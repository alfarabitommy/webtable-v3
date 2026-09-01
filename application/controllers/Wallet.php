<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Wallet extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Wallet_model');
        $this->load->model('Rental_model');
        $this->load->model('Rate_limit_model');
        $this->load->helper('ratelimit');
    }

    public function index() {
        $user_id = $this->session->userdata('user_id');

        $data = [
            'page_title'            => 'Wallet',
            'balance'               => $this->Wallet_model->get_balance($user_id),
            'pending'               => $this->Wallet_model->get_pending_deposits($user_id),
            'pending_withdrawals'   => $this->Wallet_model->get_pending_withdrawals($user_id),
            'has_pending_wd'        => $this->Wallet_model->has_pending_withdrawal($user_id),
            'has_active_rental'     => $this->Rental_model->has_active_rental($user_id),
            'daily_limit_reached'   => $this->Wallet_model->has_reached_daily_wd_limit($user_id),
            'ledger'                => $this->Wallet_model->get_ledger_history($user_id),
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('wallet/index', $data);
        $this->load->view('templates/bottom_nav');
    }

    public function topup() {
        $user_id = $this->session->userdata('user_id');
        $amount  = preg_replace('/[^0-9]/', '', $this->input->post('amount'));

        if ($amount > 0) {
            $this->Wallet_model->create_deposit($user_id, $amount);
            $this->session->set_flashdata('success', 'Invoice berhasil dibuat. Silakan selesaikan pembayaran.');
        } else {
            $this->session->set_flashdata('error', 'Nominal tidak valid.');
        }

        redirect('wallet');
    }

    public function simulate_payment($invoice_number) {
        $result = $this->Wallet_model->approve_deposit_simulator($invoice_number);

        if ($result) {
            $this->session->set_flashdata('success', 'Pembayaran berhasil disimulasikan! Dana sudah masuk.');
        } else {
            $this->session->set_flashdata('error', 'Gagal memproses simulasi.');
        }

        redirect('wallet');
    }

    // ===== WITHDRAWAL (GET: show form) =====

    public function withdraw() {
        $user_id = $this->session->userdata('user_id');

        // Gatekeeper 1: pending withdrawal
        if ($this->Wallet_model->has_pending_withdrawal($user_id)) {
            $this->session->set_flashdata('error', 'Anda masih memiliki penarikan yang sedang diproses.');
            redirect('wallet');
            return;
        }

        // Gatekeeper 2: active rental required
        if (!$this->Rental_model->has_active_rental($user_id)) {
            $this->session->set_flashdata('error', 'Anda harus memiliki minimal 1 produk sewa aktif untuk melakukan penarikan.');
            redirect('wallet');
            return;
        }

        // Gatekeeper 3: daily limit
        if ($this->Wallet_model->has_reached_daily_wd_limit($user_id)) {
            $this->session->set_flashdata('error', 'Batas penarikan harian tercapai. Anda sudah melakukan penarikan hari ini.');
            redirect('wallet');
            return;
        }

        // Gatekeeper 4: bank must be bound
        $bank = $this->Wallet_model->get_user_bank($user_id);
        if (empty($bank)) {
            $this->session->set_flashdata('error', 'Anda belum mengikat rekening bank. Silakan ikat rekening terlebih dahulu.');
            redirect('wallet/bind_bank');
            return;
        }

        $data = [
            'page_title' => 'Penarikan Dana',
            'balance'    => $this->Wallet_model->get_balance($user_id),
            'bank'       => $bank,
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('wallet/withdraw', $data);
        $this->load->view('templates/bottom_nav');
    }

    // ===== WITHDRAWAL (POST: process) =====

    public function process_withdraw() {
        $user_id = $this->session->userdata('user_id');

        // ─── RATE LIMIT (10B): rate limit pengajuan WD — key withdraw:{user_id}.
        // Setiap submission dihitung; Gatekeeper existing (single-pending-WD,
        // daily limit) tetap menjadi otoritas utama.
        $rl_key   = 'withdraw:' . $user_id;
        $throttle = $this->Rate_limit_model->check($rl_key, 5, 900);
        if (!$throttle['allowed']) {
            if ($this->input->is_ajax_request()) {
                rate_limit_json_response($throttle);
            }
            $this->session->set_flashdata('error', rate_limit_message($throttle['remaining_seconds']));
            redirect('wallet/withdraw');
        }
        $this->Rate_limit_model->hit($rl_key, 900, 5);

        // Same gatekeepers as withdraw() GET
        if ($this->Wallet_model->has_pending_withdrawal($user_id)) {
            $this->session->set_flashdata('error', 'Anda masih memiliki penarikan yang sedang diproses.');
            redirect('wallet');
            return;
        }

        if (!$this->Rental_model->has_active_rental($user_id)) {
            $this->session->set_flashdata('error', 'Anda harus memiliki minimal 1 produk sewa aktif untuk melakukan penarikan.');
            redirect('wallet');
            return;
        }

        if ($this->Wallet_model->has_reached_daily_wd_limit($user_id)) {
            $this->session->set_flashdata('error', 'Batas penarikan harian tercapai.');
            redirect('wallet');
            return;
        }

        // Fetch bank_account_id server-side — zero client bank input
        $bank = $this->Wallet_model->get_user_bank($user_id);
        if (empty($bank)) {
            $this->session->set_flashdata('error', 'Anda belum mengikat rekening bank.');
            redirect('wallet/bind_bank');
            return;
        }

        $amount = preg_replace('/[^0-9]/', '', $this->input->post('amount'));
        $user_balance = $this->Wallet_model->get_balance($user_id);

        if ($amount <= 0) {
            $this->session->set_flashdata('error', 'Nominal penarikan tidak valid.');
            redirect('wallet/withdraw');
            return;
        }

        if ($amount < 100000) {
            $this->session->set_flashdata('error', 'Minimal penarikan adalah Rp 100.000');
            redirect('wallet/withdraw');
            return;
        }

        if ($user_balance < $amount) {
            $this->session->set_flashdata('error', 'Saldo tidak mencukupi untuk penarikan');
            redirect('wallet/withdraw');
            return;
        }

        $result = $this->Wallet_model->create_withdrawal($user_id, $amount, $bank->id);

        if ($result) {
            $this->session->set_flashdata('success', 'Permintaan penarikan berhasil diajukan');
        } else {
            $this->session->set_flashdata('error', 'Gagal memproses penarikan.');
        }

        redirect('wallet');
    }

    public function simulate_wd_approve($wd_number) {
        $result = $this->Wallet_model->approve_withdrawal_simulator($wd_number);

        if ($result) {
            $this->session->set_flashdata('success', 'Simulasi: Penarikan berhasil disetujui.');
        } else {
            $this->session->set_flashdata('error', 'Gagal memproses simulasi penarikan.');
        }

        redirect('wallet');
    }

    // ===== BANK BINDING (IMMUTABLE) =====

    public function bind_bank() {
        $user_id = $this->session->userdata('user_id');
        $existing_bank = $this->Wallet_model->get_user_bank($user_id);

        // POST: Backend Bypass Protection
        if ($this->input->post()) {
            if ($existing_bank) {
                $this->session->set_flashdata('error', 'Rekening sudah terikat dan tidak dapat diubah.');
                redirect('wallet/bind_bank');
                return;
            }

            $bank_name      = $this->input->post('bank_name');
            $account_number = $this->input->post('account_number');
            $account_holder = $this->input->post('account_holder');

            // Validation
            if (empty($bank_name) || empty($account_number) || empty($account_holder)) {
                $this->session->set_flashdata('error', 'Semua field wajib diisi.');
                redirect('wallet/bind_bank');
                return;
            }

            if (!preg_match('/^[0-9]+$/', $account_number) || strlen($account_number) < 8) {
                $this->session->set_flashdata('error', 'Nomor rekening harus numeric minimal 8 digit.');
                redirect('wallet/bind_bank');
                return;
            }

            $data = [
                'user_id'        => $user_id,
                'bank_name'      => $bank_name,
                'account_number' => $account_number,
                'account_holder' => $account_holder,
                'is_primary'     => 1,
            ];

            if ($this->Wallet_model->insert_bank($data)) {
                $this->session->set_flashdata('success', 'Rekening berhasil diikat.');
            } else {
                $this->session->set_flashdata('error', 'Gagal menyimpan data rekening.');
            }

            redirect('wallet/bind_bank');
            return;
        }

        // GET: Render view
        $data = [
            'page_title'    => 'Bind Rekening',
            'existing_bank' => $existing_bank,
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('wallet/bank_bind', $data);
        $this->load->view('templates/bottom_nav');
    }
}
