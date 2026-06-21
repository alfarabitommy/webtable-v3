<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Wallet extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Wallet_model');
    }

    public function index() {
        $user_id = $this->session->userdata('user_id');

        $data = [
            'page_title'            => 'Wallet',
            'balance'               => $this->Wallet_model->get_balance($user_id),
            'pending'               => $this->Wallet_model->get_pending_deposits($user_id),
            'pending_withdrawals'   => $this->Wallet_model->get_pending_withdrawals($user_id),
            'has_pending_wd'        => $this->Wallet_model->has_pending_withdrawal($user_id),
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

    public function withdraw() {
        $user_id = $this->session->userdata('user_id');

        // Hard-stop: reject if pending withdrawal already exists
        if ($this->Wallet_model->has_pending_withdrawal($user_id)) {
            $this->session->set_flashdata('error', 'Anda masih memiliki penarikan yang sedang diproses.');
            redirect('wallet');
            return;
        }

        $amount  = preg_replace('/[^0-9]/', '', $this->input->post('amount'));
        $bank    = $this->input->post('bank_name');
        $acc_num = $this->input->post('account_number');
        $acc_name = $this->input->post('account_holder');

        $user_balance = $this->Wallet_model->get_balance($user_id);

        if ($amount <= 0) {
            $this->session->set_flashdata('error', 'Nominal penarikan tidak valid.');
            redirect('wallet');
            return;
        }

        if ($amount < 100000) {
            $this->session->set_flashdata('error', 'Minimal penarikan adalah Rp 100.000');
            redirect('wallet');
            return;
        }

        if ($user_balance < $amount) {
            $this->session->set_flashdata('error', 'Saldo tidak mencukupi untuk penarikan');
            redirect('wallet');
            return;
        }

        $result = $this->Wallet_model->create_withdrawal($user_id, $amount, $bank, $acc_num, $acc_name);

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
}