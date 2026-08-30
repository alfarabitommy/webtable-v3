<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Notification extends MY_Controller {

    public function __construct() {
        parent::__construct();
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
     */
    public function mark_all_read() {
        header('Content-Type: application/json');

        $user_id = $this->session->userdata('user_id');
        if (!$user_id) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $this->Notification_model->mark_read($user_id);
        echo json_encode(['success' => true, 'unread_count' => 0]);
        exit;
    }

    /**
     * AJAX POST /notification/mark_read_single/{id}
     */
    public function mark_read_single($id) {
        header('Content-Type: application/json');

        $user_id = $this->session->userdata('user_id');
        if (!$user_id) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $this->Notification_model->mark_single_read((int) $id, $user_id);
        echo json_encode(['success' => true]);
        exit;
    }
}
