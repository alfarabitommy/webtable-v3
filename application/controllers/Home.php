<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Home extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('User_model');
    }

    public function index() {
        $user_id = $this->session->userdata('user_id');
        
        // Fetch full user data for the home dashboard
        $user = $this->User_model->get_user_by_id($user_id);
        
        $data = [
            'page_title' => 'Dashboard',
            'user' => $user
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('home/index', $data);
        $this->load->view('templates/bottom_nav');
    }
}
