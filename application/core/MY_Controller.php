<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class MY_Controller extends CI_Controller {

    public function __construct() {
        parent::__construct();

        $controller = $this->router->fetch_class();

        if ($controller !== 'auth' && empty($this->session->userdata('user_id'))) {
            redirect('login');
        }

        // Inject global balance for header
        if ($this->session->userdata('user_id')) {
            $this->load->model('Wallet_model');
            $balance = $this->Wallet_model->get_balance($this->session->userdata('user_id'));
            $this->load->vars(['global_balance' => $balance]);
        } else {
            $this->load->vars(['global_balance' => 0]);
        }
    }
}
