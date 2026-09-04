<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Help extends MY_Controller {

    public function index() {
        $this->load->model('Admin_model');
        // M7 (plan/70): kontak support dibaca dari system_settings
        // (store tunggal); fallback nilai lama dipertahankan.
        $data = [
            'page_title'   => 'Bantuan',
            'wa_number'    => $this->Admin_model->get_setting('wa_number') ?: '628000000000',
            'support_email'=> $this->Admin_model->get_setting('support_email') ?: 'support@synapse.id',
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('help/index', $data);
        $this->load->view('templates/bottom_nav');
    }
}
