<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin_auth extends CI_Controller {

    public function __construct() {
        parent::__construct();
    }

    public function login() {
        // Redirect if already logged in as admin
        if ($this->session->userdata('admin_id')) {
            redirect('admin');
        }

        if ($this->input->post()) {
            $username = $this->input->post('username', TRUE);
            $password = $this->input->post('password', TRUE);

            $admin = $this->db->get_where('admins', ['username' => $username])->row();

            if ($admin && password_verify($password, $admin->password)) {
                $this->session->set_userdata([
                    'admin_id'       => $admin->id,
                    'admin_username' => $admin->username,
                ]);
                redirect('admin');
            } else {
                $this->session->set_flashdata('error', 'SYSTEM HALTED: Invalid credentials.');
                redirect('control-panel');
            }
        }

        $this->load->view('admin/login');
    }
}
