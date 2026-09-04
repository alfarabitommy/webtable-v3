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

        // Augmentasi display: matematika klaim dari SATU sumber
        // (Rental_model::claimable_info) — sama persis dengan mesin klaim
        // claim_roi (C2/P8, single source of truth).
        foreach ($rentals as &$r) {
            $info = $this->Rental_model->claimable_info($r);
            $r->claimable_days   = $info['claimable_days'];
            $r->remaining_days   = $info['remaining_days'];
            $r->actual_claimable = $info['actual_claimable'];
            $r->is_claimed_today = $info['is_claimed_today'];
            $r->is_expired       = $info['is_expired'];
            $r->is_completed     = $info['is_completed'];
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

        // 3. Pre-check saldo: HANYA fast UX fail. Otoritas finansial
        //    anti-race (kunci anchor users + saldo segar di dalam TX, reject
        //    overspend) ada di Rental_model::checkout_rental — audit C5,
        //    plan/48.
        $user_balance = $this->Wallet_model->get_balance($user_id);
        if ($user_balance < $product['price']) {
            $this->session->set_flashdata('error', 'Sistem: Saldo USC/IDR Anda tidak mencukupi.');
            redirect('marketplace');
        }

        // 4. C2 refactor: debit wallet + pembuatan kontrak kini SATU
        //    transaksi ACID terkunci di Rental_model::checkout_rental
        //    (AGENTS.md — semua akses DB hidup di model, controller tanpa
        //    query DB). Hasil terstruktur {success, code, message} di-map
        //    ke flashdata di bawah.
        $result = $this->Rental_model->checkout_rental($user_id, $product);

        if (!$result['success']) {
            $this->session->set_flashdata('error', $result['message']);
            redirect('marketplace');
        }

        // 5. Success
        $this->session->set_flashdata('success', 'Sewa berhasil diaktifkan! Infrastruktur sedang online.');
        redirect('rentals');
    }

    /**
     * POST /rentals/claim/{id} — ROI claim with 2-day accumulation
     */
    public function claim($rental_id = null) {
        // POST-only (plan/46 fail-closed): claim memutasi DB (wallet + rental),
        // tidak boleh dieksekusi via GET. Metode selain POST → 404 eksplisit,
        // BUKAN redirect hening tanpa umpan balik. Form view memakai
        // form_open (POST) sehingga jalur ini tak tercapai dari UI normal.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
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

        // C2 (plan/44): seluruh workflow atomik — row lock SELECT ... FOR
        // UPDATE, guard lifecycle (status/expired_at/days_processed), kredit
        // ledger ID deterministik — dienkapsulasi di Rental_model::claim_roi.
        // Controller hanya memetakan kode hasil → flashdata + redirect.
        $result = $this->Rental_model->claim_roi($rental_id, $user_id);

        if ($result['code'] === 'claimed') {
            $this->session->set_flashdata('success', $result['message']);
            // M5/N1: notifikasi ROI cair (parity claim level1/wage) — hanya pada
            // code 'claimed' (satu-satunya jalur payout sukses C2), sehingga
            // replay/klaim ganda tidak pernah menghasilkan notifikasi dobel.
            $this->Notification_model->insert(
                $user_id,
                'ROI Harian Cair',
                'ROI sebesar Rp ' . number_format((float) $result['amount'], 0, ',', '.')
                    . ' telah masuk ke saldo (kontrak #' . (int) $rental_id . ').',
                'commission'
            );
        } else {
            $this->session->set_flashdata('error', $result['message']);
        }

        redirect('rentals');
    }
}
