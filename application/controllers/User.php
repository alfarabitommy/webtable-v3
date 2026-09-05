<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User extends MY_Controller {

    public function __construct() {
        parent::__construct();
        // M9/P7 (plan/76 Batch A): choke-point JSON helper.
        $this->load->helper('api');
    }

    /**
     * Legacy alias — mark all notifications as read
     * POST /user/read_notifications
     *
     * M10 (plan/78): canonical endpoint is Notification::mark_all_read
     * (POST notification/mark_all_read). This thin alias forwards to the same
     * Notification_model->mark_read() + api_success() envelope and is retained
     * for backward compatibility (route application/config/routes.php:45).
     * Konsumen (templates/header.php) hanya membaca `success`.
     */
    public function read_notifications() {
        if (!$this->session->userdata('user_id')) {
            api_error('Sesi habis. Silakan login ulang.', 401, [], 'unauthenticated', ['error' => 'Unauthorized']);
        }

        $user_id = $this->session->userdata('user_id');
        $this->load->model('Notification_model');
        $this->Notification_model->mark_read($user_id);

        api_success(['unread_count' => 0], '', 200, ['unread_count' => 0]);
    }
}
