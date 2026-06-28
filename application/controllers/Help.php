<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Help extends MY_Controller {

    public function index() {
        $this->load->model('Admin_model');
        $settings = $this->Admin_model->get_all_settings();

        $data = [
            'page_title'   => 'Bantuan',
            'wa_number'    => $settings['wa_number'] ?? '628000000000',
            'support_email'=> $settings['support_email'] ?? 'support@synapse.id',
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('help/index', $data);
        $this->load->view('templates/bottom_nav');
    }
}
