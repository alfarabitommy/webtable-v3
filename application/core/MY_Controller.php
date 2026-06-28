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

            // Notification badge + dropdown data
            $this->load->model('Notification_model');
            $unread_count = $this->Notification_model->get_unread_count($this->session->userdata('user_id'));
            $notifications = $this->Notification_model->get_latest($this->session->userdata('user_id'), 5);
            $this->load->vars([
                'global_unread_count'  => $unread_count,
                'global_notifications' => $notifications,
            ]);
        } else {
            $this->load->vars([
                'global_balance'       => 0,
                'global_unread_count'  => 0,
                'global_notifications' => [],
            ]);
        }
    }
}
