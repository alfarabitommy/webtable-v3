<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class MY_Controller extends CI_Controller {

    public function __construct() {
        parent::__construct();

        // plan/95 (G-1/G-4): maintenance gate PALING AWAL — sebelum pin WIB M2,
        // i18n_apply, guard redirect login, sweep rental M3, dan baca
        // saldo/notifikasi apa pun. Exempt: CLI + session admin_id (G-3).
        // Saat aktif & non-admin: exit HTTP 503 (HTML) / JSON MAINTENANCE_MODE.
        maintenance_gate();

        // M2 (plan/58 §3 Phase 2): unconditional WIB session pin as the FIRST
        // DB statement of every authenticated user request (setelah gate plan/95).
        // CI3 connects
        // lazily (conn_id = FALSE until the first query), so a guarded
        // SET in a model constructor never fires on a fresh connection —
        // this query() forces the connection and applies
        // SET time_zone = '+07:00' before any model load or query,
        // keeping TIMESTAMP read-backs (created_at, last_wage_claimed_at,
        // rate-limit windows) WIB-consistent with the PHP clock.
        $this->db->query("SET time_zone = '+07:00'");

        // Plan 94 (F1): bahasa member (member-facing ONLY). Resolusi
        // session('site_lang') → cookie('site_lang') → 'en'; muat app_lang
        // pada idiom terpilih (TEPAT SATU — L4) + inject var site_lang_code
        // untuk <html lang> & switcher. Admin tidak pernah lewat sini (L1).
        i18n_apply();

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
                $this->session->set_flashdata('error', lang('auth_err_account_inactive'));
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

            // plan/102: lazy expiry deposit manual QRIS — pending yang lewat
            // jendela bayar ditutup (status expired + reservasi kode unik
            // dilepas) SEBELUM halaman wallet/menu lain membaca deposit aktif.
            // Satu UPDATE ber-index (idx_status_expires), autocommit tanpa TX,
            // idempotent. `waiting_approval` SENGAJA tidak disentuh (D1: uang
            // sudah diklaim ditransfer → menunggu verifikasi admin, bukan
            // kedaluwarsa). Urutan statement mengikuti pola M3 di atasnya.
            $this->Wallet_model->expire_user_deposits($this->session->userdata('user_id'));

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
