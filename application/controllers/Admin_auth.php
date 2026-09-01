<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin_auth extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Rate_limit_model');
        $this->load->helper('ratelimit');
    }

    public function login() {
        // Redirect if already logged in as admin
        if ($this->session->userdata('admin_id')) {
            redirect('admin');
        }

        if ($this->input->post()) {
            $username = trim($this->input->post('username', TRUE));
            $password = $this->input->post('password', TRUE);

            // ─── RATE LIMIT (10B): fail-fast — key admin_login:{username}:{ip}
            $rl_key   = 'admin_login:' . $username . ':' . $this->input->ip_address();
            $throttle = $this->Rate_limit_model->check($rl_key, 5, 900);
            if (!$throttle['allowed']) {
                if ($this->input->is_ajax_request()) {
                    rate_limit_json_response($throttle);
                }
                $this->session->set_flashdata('error', 'SYSTEM HALTED: ' . rate_limit_message($throttle['remaining_seconds']));
                redirect('control-panel');
            }

            $admin = $this->db->get_where('admins', ['username' => $username])->row();

            if ($admin && password_verify($password, $admin->password)) {
                // Rate limit (10B): kredensial benar → bersihkan counter
                $this->Rate_limit_model->clear($rl_key);

                $this->session->set_userdata([
                    'admin_id'       => $admin->id,
                    'admin_username' => $admin->username,
                ]);
                redirect('admin');
            } else {
                // Rate limit (10B): kredensial salah → catat percobaan gagal
                $this->Rate_limit_model->hit($rl_key, 900, 5);
                $this->session->set_flashdata('error', 'SYSTEM HALTED: Invalid credentials.');
                redirect('control-panel');
            }
        }

        $this->load->view('admin/login');
    }

    /**
     * GET /admin/logout — destroy admin session, back to cloaked gateway.
     * Uses unset_userdata (bukan sess_destroy) agar flashdata success
     * tetap hidup hingga halaman control-panel dirender.
     */
    public function logout() {
        $this->session->unset_userdata(['admin_id', 'admin_username']);
        $this->session->set_flashdata('success', 'Anda telah berhasil keluar.');
        redirect('control-panel');
    }
}
