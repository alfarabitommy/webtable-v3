<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Notification extends MY_Controller {

    public function __construct() {
        parent::__construct();
        // M9/P7 (plan/76): choke-point JSON helper — envelope terstandar.
        $this->load->helper('api');
    }

    /**
     * GET /notification — Full history page
     */
    public function index() {
        $user_id = $this->session->userdata('user_id');
        $notifs  = $this->Notification_model->get_by_user($user_id, 100);

        $data = [
            'page_title'   => 'Notifikasi',
            'notifications' => $notifs,
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('notification/index', $data);
        $this->load->view('templates/bottom_nav');
    }

    /**
     * AJAX POST /notification/mark_all_read
     *
     * M9/P7 (plan/76 Batch A): envelope {success, message, data:{unread_count}}
     * + key legacy root `unread_count`; unauthenticated -> HTTP 401 JSON
     * (dulu 200 {success:false,error}). Konsumen (notification/index.php,
     * templates/header.php) hanya membaca `success` — tanpa perubahan view.
     */
    public function mark_all_read() {
        $user_id = $this->session->userdata('user_id');
        if (!$user_id) {
            api_error('Sesi habis. Silakan login ulang.', 401, [], 'unauthenticated', ['error' => 'Unauthorized']);
        }

        $this->Notification_model->mark_read($user_id);
        api_success(['unread_count' => 0], '', 200, ['unread_count' => 0]);
    }
}
