<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User extends MY_Controller {

    public function __construct() {
        parent::__construct();
    }

    /**
     * AJAX endpoint — mark all notifications as read
     * POST /user/read_notifications
     */
    public function read_notifications() {
        header('Content-Type: application/json');

        if (!$this->session->userdata('user_id')) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $user_id = $this->session->userdata('user_id');
        $this->load->model('Notification_model');
        $this->Notification_model->mark_read($user_id);

        echo json_encode(['success' => true, 'unread_count' => 0]);
        exit;
    }
}
