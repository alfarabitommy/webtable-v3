<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class MY_Controller extends CI_Controller {

    public function __construct() {
        parent::__construct();

        // M2 (plan/58 §3 Phase 2): unconditional WIB session pin as the FIRST
        // DB statement of every authenticated user request. CI3 connects
        // lazily (conn_id = FALSE until the first query), so a guarded
        // SET in a model constructor never fires on a fresh connection —
        // this query() forces the connection and applies
        // SET time_zone = '+07:00' before any model load or query,
        // keeping TIMESTAMP read-backs (created_at, last_wage_claimed_at,
        // rate-limit windows) WIB-consistent with the PHP clock.
        $this->db->query("SET time_zone = '+07:00'");

        $controller = $this->router->fetch_class();

        if ($controller !== 'auth' && empty($this->session->userdata('user_id'))) {
            redirect('login');
        }

        // Inject global balance for header
        if ($this->session->userdata('user_id')) {
            // Re-read user row per request: enforce ban lockout + forced password change.
            // Type-safe: the (array) cast tolerates both ->row() (object) and ->row_array()
            // (array) model returns; the null-coalescing ?? 0 keeps a missing column from
            // silently disabling the guard (no PHP warning, no crash).
            $this->load->model('User_model');
            $row = (array) $this->User_model->get_user_by_id($this->session->userdata('user_id'));

            $is_banned          = (int) ($row['is_banned'] ?? 0);
            $must_change_passwd = (int) ($row['must_change_password'] ?? 0);

            if ($row && $is_banned === 1) {
                $this->session->unset_userdata('user_id'); // keep flashdata alive (sess_destroy would kill it)
                $this->session->set_flashdata('error', 'Akun Anda telah dinonaktifkan. Silakan hubungi admin.');
                redirect('login');
            }

            if ($row && $must_change_passwd === 1) {
                redirect('auth/change-password');
            }

            // M3 (plan/60): lazy expiry per-request — tutup kontrak sewa
            // expired milik user (active → completed) SETELAH session valid
            // + timezone init, SEBELUM baca saldo/notifikasi/bisnis apa pun
            // (gate penarikan, daftar sewa, klaim). Satu UPDATE ber-index
            // (idx_user_status_expired), autocommit tanpa TX → overhead
            // sub-milidetik; idempotent & race-safe vs claim_roi (C2).
            $this->load->model('Rental_model');
            $this->Rental_model->expire_user_rentals($this->session->userdata('user_id'));

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
