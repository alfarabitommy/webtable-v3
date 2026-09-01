<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Rentals extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->helper(array('form', 'url'));
        $this->load->model('Rental_model');
        $this->load->model('Wallet_model');
        $this->load->model('Product_model');
        $this->load->model('Rate_limit_model');
        $this->load->helper('ratelimit');
    }

    /**
     * GET /rentals — active rentals list
     */
    public function index() {
        $user_id = $this->session->userdata('user_id');
        $rentals = $this->Rental_model->get_active_rentals($user_id);

        // Augment each rental with claimable_days logic
        foreach ($rentals as &$r) {
            $ref = !empty($r->last_claimed_at)
                ? date('Y-m-d', strtotime($r->last_claimed_at))
                : date('Y-m-d', strtotime($r->created_at));
            $diff = (int) ((strtotime('today') - strtotime($ref)) / 86400);

            $r->claimable_days = min($diff, 2);
            $r->remaining_days = max(0, $r->total_days - $r->days_processed);
            $r->actual_claimable = min($r->claimable_days, $r->remaining_days);
        }
        unset($r);

        $data = [
            'page_title' => 'Sewa Saya',
            'rentals'    => $rentals,
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('rentals/index', $data);
        $this->load->view('templates/bottom_nav');
    }

    /**
     * POST /rentals/checkout — deduct balance, create rental
     */
    public function checkout() {
        $user_id    = $this->session->userdata('user_id');
        $product_id = $this->input->post('product_id');

        // 1. Validate product_id from form
        if (empty($product_id) || !is_numeric($product_id)) {
            $this->session->set_flashdata('error', 'Sistem: ID Produk tidak terbaca dari form.');
            redirect('marketplace');
        }

        // 2. Fetch product via model (DB first, mock fallback)
        $product = $this->Product_model->get_product($product_id);
        if (!$product) {
            $this->session->set_flashdata('error', 'Sistem: Produk tidak ditemukan di database.');
            redirect('marketplace');
        }

        // 3. Check user balance
        $user_balance = $this->Wallet_model->get_balance($user_id);
        if ($user_balance < $product['price']) {
            $this->session->set_flashdata('error', 'Sistem: Saldo USC/IDR Anda tidak mencukupi.');
            redirect('marketplace');
        }

        // 4. ACID Transaction: debit wallet + create rental
        $this->db->trans_start();

        // 4a. Debit wallet_ledger
        $this->db->insert('wallet_ledger', [
            'user_id'        => $user_id,
            'transaction_id' => 'RENT-' . $product_id . '-' . date('YmdHis'),
            'type'           => 'debit',
            'amount'         => $product['price'],
            'description'    => 'Sewa ' . $product['name'],
        ]);

        // 4b. Create rental record with total_days
        $this->db->insert('user_rentals', [
            'user_id'        => $user_id,
            'product_id'     => $product['id'],
            'purchase_price' => $product['price'],
            'daily_roi'      => $product['daily_rate'],
            'total_days'     => $product['duration_days'],
            'status'         => 'active',
            'expired_at'     => date('Y-m-d H:i:s', strtotime('+' . $product['duration_days'] . ' days')),
        ]);

        $this->db->trans_complete();

        // 5. Evaluate transaction result
        if ($this->db->trans_status() === FALSE) {
            $this->session->set_flashdata('error', 'Sistem: Gagal memotong saldo atau membuat kontrak sewa.');
            redirect('marketplace');
        }

        // 6. Success
        $this->session->set_flashdata('success', 'Sewa berhasil diaktifkan! Infrastruktur sedang online.');
        redirect('rentals');
    }

    /**
     * POST /rentals/claim/{id} — ROI claim with 2-day accumulation
     */
    public function claim($rental_id = null) {
        // POST-only guard (10B hardening): claim memutasi DB (wallet + rental),
        // tidak boleh dieksekusi via GET. Form view memakai form_open (POST).
        if (!$this->input->post()) {
            redirect('rentals');
        }

        if (!$rental_id) {
            $this->session->set_flashdata('error', 'Sistem: ID Sewa tidak valid.');
            redirect('rentals');
        }

        $user_id = $this->session->userdata('user_id');

        // ─── RATE LIMIT (10B): anti-spam klaim — key claim_roi:{user_id}.
        // Setiap percobaan klaim dihitung (burst limiter); business guard
        // T+1 tetap menjadi otoritas utama jumlah ROI harian.
        $rl_key   = 'claim_roi:' . $user_id;
        $throttle = $this->Rate_limit_model->check($rl_key, 5, 900);
        if (!$throttle['allowed']) {
            if ($this->input->is_ajax_request()) {
                rate_limit_json_response($throttle);
            }
            $this->session->set_flashdata('error', rate_limit_message($throttle['remaining_seconds']));
            redirect('rentals');
        }
        $this->Rate_limit_model->hit($rl_key, 900, 5);

        // 1. Fetch Rental Safely
        $rental = $this->db->get_where('user_rentals', ['id' => $rental_id, 'user_id' => $user_id])->row();

        if (!$rental) {
            $this->session->set_flashdata('error', 'Sistem: Data sewa tidak ditemukan.');
            redirect('rentals');
        }

        // 2. T+1 Rule: prevent same-day claims
        if (date('Y-m-d', strtotime($rental->created_at)) === date('Y-m-d')) {
            $this->session->set_flashdata('error', 'Klaim pertama baru dapat dilakukan keesokan harinya (H+1) setelah pembelian.');
            redirect('rentals');
        }

        // 3. Calculate claimable days (2-day accumulation logic)
        $reference_date = !empty($rental->last_claimed_at)
            ? date('Y-m-d', strtotime($rental->last_claimed_at))
            : date('Y-m-d', strtotime($rental->created_at));

        $day_diff = (int) ((strtotime('today') - strtotime($reference_date)) / 86400);

        // Accumulation cap: max 2 days
        $claimable_days = min($day_diff, 2);

        // Over-payment protection
        $remaining_days = $rental->total_days - $rental->days_processed;
        $actual_claim_days = min($claimable_days, max(0, $remaining_days));

        // Guard: nothing to claim
        if ($actual_claim_days < 1) {
            if ($remaining_days <= 0) {
                $this->session->set_flashdata('error', 'Sistem: Masa kontrak Anda telah habis.');
            } else {
                $this->session->set_flashdata('error', 'Sistem: Anda sudah mengklaim penghasilan hari ini.');
            }
            redirect('rentals');
        }

        // 3. ACID Transaction
        $this->db->trans_start();

        // Update rental: increment days_processed, update last_claimed_at
        $this->db->where('id', $rental_id);
        $this->db->where('user_id', $user_id);
        $this->db->update('user_rentals', [
            'days_processed'  => $rental->days_processed + $actual_claim_days,
            'last_claimed_at' => date('Y-m-d H:i:s'),
        ]);

        // Credit wallet: actual_claim_days × daily_roi
        $total_payout = $actual_claim_days * $rental->daily_roi;
        $this->db->insert('wallet_ledger', [
            'user_id'        => $rental->user_id,
            'transaction_id' => 'ROI-' . time() . '-' . $rental_id,
            'type'           => 'credit',
            'amount'         => $total_payout,
            'description'    => 'Klaim ROI ' . $actual_claim_days . ' Hari',
        ]);

        $this->db->trans_complete();

        // 4. Handle Response
        if ($this->db->trans_status() === FALSE) {
            $this->session->set_flashdata('error', 'Sistem: Gagal memproses klaim ke database.');
        } else {
            $this->session->set_flashdata('success', 'Berhasil! Rp ' . number_format($total_payout, 0, ',', '.') . ' telah ditambahkan ke dompet (' . $actual_claim_days . ' hari klaim).');
        }

        redirect('rentals');
    }
}
