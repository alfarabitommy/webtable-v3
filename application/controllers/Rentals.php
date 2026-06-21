<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Rentals extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->helper(array('form', 'url'));
        $this->load->model('Rental_model');
        $this->load->model('Wallet_model');
        $this->load->model('Product_model');
    }

    /**
     * GET /rentals — active rentals list
     */
    public function index() {
        $user_id = $this->session->userdata('user_id');
        $rentals = $this->Rental_model->get_active_rentals($user_id);

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

        // 4b. Create rental record
        $this->db->insert('user_rentals', [
            'user_id'        => $user_id,
            'product_id'     => $product['id'],
            'purchase_price' => $product['price'],
            'daily_roi'      => $product['daily_rate'],
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
     * POST /rentals/claim/{id} — daily ROI claim
     */
    public function claim($rental_id = null) {
        if (!$rental_id) {
            $this->session->set_flashdata('error', 'Sistem: ID Sewa tidak valid.');
            redirect('rentals');
        }

        // 1. Fetch Rental Safely
        $rental = $this->db->get_where('user_rentals', ['id' => $rental_id])->row();
        
        if (!$rental) {
            $this->session->set_flashdata('error', 'Sistem: Data sewa tidak ditemukan.');
            redirect('rentals');
        }

        // 2. Strict NULL Check & Date Validation (The Fix)
        if (!empty($rental->last_claimed_at)) {
            $last_claim_date = date('Y-m-d', strtotime($rental->last_claimed_at));
            if (date('Y-m-d') === $last_claim_date) {
                $this->session->set_flashdata('error', 'Sistem: Anda sudah mengklaim penghasilan hari ini.');
                redirect('rentals');
            }
        }

        // 3. Bulletproof ACID Transaction
        $this->db->trans_start();

        // Update Claim Date
        $this->db->where('id', $rental_id);
        $this->db->update('user_rentals', ['last_claimed_at' => date('Y-m-d H:i:s')]);

        // Insert ROI to Wallet Ledger
        $this->db->insert('wallet_ledger', [
            'user_id' => $rental->user_id,
            'transaction_id' => 'ROI-' . time() . '-' . $rental_id,
            'type' => 'credit',
            'amount' => $rental->daily_roi,
            'description' => 'Klaim ROI Harian'
        ]);

        $this->db->trans_complete();

        // 4. Handle Response
        if ($this->db->trans_status() === FALSE) {
            $this->session->set_flashdata('error', 'Sistem: Gagal memproses klaim ke database.');
        } else {
            $this->session->set_flashdata('success', 'Berhasil! Penghasilan harian Anda telah ditambahkan ke dompet.');
        }
        
        redirect('rentals');
    }
}
