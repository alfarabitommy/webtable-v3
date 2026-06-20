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
            'page_title' => 'Wallet',
            'balance'    => $this->Wallet_model->get_balance($user_id),
            'pending'    => $this->Wallet_model->get_pending_deposits($user_id),
            'ledger'     => $this->Wallet_model->get_ledger_history($user_id),
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
}