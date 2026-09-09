<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Marketplace extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Product_model');
        $this->load->model('Wallet_model');
    }

    public function index() {
        // plan/83: katalog ter-personalisasi — produk aktif + telemetri gating
        // & kuota per user (prasyarat/limit). Otoritas gate tetap di
        // Rental_model::checkout_rental (TX terkunci); data ini hanya display.
        $user_id = $this->session->userdata('user_id');
        $products = $this->Product_model->get_catalog_for_user($user_id);

        // User wallet balance (real from DB)
        $user_balance = $user_id ? $this->Wallet_model->get_balance($user_id) : 0;

        $data = [
            'page_title'  => lang('market_page_title'),
            'products'    => $products,
            'user_balance'=> $user_balance,
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('marketplace/index', $data);
        $this->load->view('templates/bottom_nav');
    }
}
