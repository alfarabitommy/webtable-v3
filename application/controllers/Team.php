<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Team extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('User_model');
    }

    public function index() {
        $user_id = $this->session->userdata('user_id');
        $user = $this->User_model->get_user_by_id($user_id);
        $members = $this->User_model->get_team_with_active_status($user_id);

        // Format phone for WhatsApp + cast is_active to bool
        foreach ($members as &$m) {
            $m->phone_full = $m->phone;
            $m->phone_wa = $this->_format_wa_phone($m->phone);
            $m->is_active = (int) $m->is_active > 0;
        }
        unset($m);

        // Counts
        $total_bc  = count($members);
        $active_bc = 0;
        $l1_active = 0;
        $l2_active = 0;
        foreach ($members as $m) {
            if ($m->is_active) {
                $active_bc++;
                ($m->level == 1) ? $l1_active++ : $l2_active++;
            }
        }

        // Phase 9B: claim data
        $claim_data = $this->User_model->get_claim_data($user_id);

        $data = [
            'page_title' => 'Tim & Afiliasi',
            'user'       => $user,
            'members'    => $members,
            'total_bc'   => $total_bc,
            'active_bc'  => $active_bc,
            'l1_active'  => $l1_active,
            'l2_active'  => $l2_active,
            'ref_url'    => base_url('register?ref=' . $user->invite_code),
            'claim_data' => $claim_data,
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('team/index', $data);
        $this->load->view('templates/bottom_nav');
    }

    /**
     * POST: Claim Level 1 bonus
     */
    public function claim_level1() {
        $this->output->set_content_type('application/json');

        if ( ! $this->input->is_ajax_request()) {
            show_404();
        }

        $user_id = $this->session->userdata('user_id');
        if ( ! $user_id) {
            echo json_encode(['success' => false, 'message' => 'Sesi habis. Silakan login ulang.']);
            exit;
        }

        $result = $this->User_model->claim_level1($user_id);

        if ($result['success']) {
            $user = $this->User_model->get_user_by_id($user_id);
            $result['new_balance'] = $user->balance;
        }

        echo json_encode($result);
        exit;
    }

    /**
     * POST: Claim weekly wage (L2-6)
     */
    public function claim_wage() {
        $this->output->set_content_type('application/json');

        if ( ! $this->input->is_ajax_request()) {
            show_404();
        }

        $user_id = $this->session->userdata('user_id');
        if ( ! $user_id) {
            echo json_encode(['success' => false, 'message' => 'Sesi habis. Silakan login ulang.']);
            exit;
        }

        $result = $this->User_model->claim_wage($user_id);

        if ($result['success']) {
            $user = $this->User_model->get_user_by_id($user_id);
            $result['new_balance'] = $user->balance;
        }

        echo json_encode($result);
        exit;
    }

    /**
     * Format phone for WhatsApp wa.me API
     * 08123456789 → 628123456789
     */
    private function _format_wa_phone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strpos($phone, '0') === 0) {
            $phone = '62' . substr($phone, 1);
        } elseif (strpos($phone, '62') !== 0) {
            $phone = '62' . $phone;
        }
        return $phone;
    }
}
