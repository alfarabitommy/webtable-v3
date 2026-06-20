<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Marketplace extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Product_model');
        $this->load->model('Wallet_model');
    }

    public function index() {
        // Fetch active products (fallback to dummy data if DB empty)
        $products = $this->Product_model->get_all_active_products();

        if (empty($products)) {
            $products = [
                [
                    'id'         => 1,
                    'name'       => 'NVIDIA RTX 4090 Node',
                    'description'=> 'Dedicated GPU node untuk AI training & inference.',
                    'price'      => 1500000,
                    'daily_roi'  => 45000,
                ],
                [
                    'id'         => 2,
                    'name'       => 'AMD MI300X Cluster',
                    'description'=> 'Enterprise-grade HPC cluster untuk workload masif.',
                    'price'      => 3500000,
                    'daily_roi'  => 120000,
                ],
                [
                    'id'         => 3,
                    'name'       => 'Cloud VPS Enterprise',
                    'description'=> 'Virtual server high-performance untuk aplikasi production.',
                    'price'      => 750000,
                    'daily_roi'  => 22000,
                ],
                [
                    'id'         => 4,
                    'name'       => 'AI Inference Pod',
                    'description'=> 'Pod inference optimal untuk deployment model LLM.',
                    'price'      => 2000000,
                    'daily_roi'  => 65000,
                ],
            ];
        }

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
