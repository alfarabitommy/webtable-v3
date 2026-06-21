<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Marketplace extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Product_model');
        $this->load->model('Wallet_model');
    }

    public function index() {
        // Single source of truth: Product_model handles DB + mock fallback
        $products = $this->Product_model->get_all_active_products();

        // User wallet balance (real from DB)
        $user_id = $this->session->userdata('user_id');
        $user_balance = $user_id ? $this->Wallet_model->get_balance($user_id) : 0;

        $data = [
            'page_title'  => 'Marketplace',
            'products'    => $products,
            'user_balance'=> $user_balance,
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('marketplace/index', $data);
        $this->load->view('templates/bottom_nav');
    }
}
