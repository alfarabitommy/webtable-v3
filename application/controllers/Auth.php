<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends CI_Controller {

    public function __construct() {
        parent::__construct();

        // M2 (plan/58 §3 Phase 2): pin WIB sesi MySQL sebagai statement DB
        // pertama (Auth bukan MY_Controller — entry point login/register).
        $this->db->query("SET time_zone = '+07:00'");

        $this->config->load('recaptcha', TRUE);
        $this->load->model('User_model');
        $this->load->model('Rate_limit_model');
        $this->load->helper('ratelimit');
    }

    // ─── PHONE NORMALIZER ──────────────────────────────
    private function _normalize_phone($raw) {
        $digits = preg_replace('/\D/', '', trim($raw));
        if (strpos($digits, '62') === 0 && strlen($digits) > 2) {
            $digits = '0' . substr($digits, 2);
        }
        if ($digits !== '' && $digits[0] !== '0') {
            $digits = '0' . $digits;
        }
        return $digits;
    }

    // ─── RECAPTCHA VERIFIER ─────────────────────────────
    private function _verify_recaptcha($recaptcha_response) {
        if (empty($recaptcha_response)) {
            return FALSE;
        }

        // Fail-closed: tanpa secret yang terkonfigurasi, tolak verifikasi
        // dan catat error — JANGAN pernah lanjut ke curl ke Google.
        $secret = (string) $this->config->item('recaptcha_secret', 'recaptcha');
        if ($secret === '') {
            log_message('error', 'reCAPTCHA secret belum dikonfigurasi (env RECAPTCHA_SECRET) — verifikasi ditolak (fail-closed).');
            return FALSE;
        }

        $data = array('secret' => $secret, 'response' => $recaptcha_response);

        // ─── STRICT SSL (Phase 10D WS-1) ─────────────────────────────
        // Production zero-trust: VERIFYPEER=true + VERIFYHOST=2 adalah
        // default yang TIDAK BISA dinonaktifkan di production. CA publik
        // Google tervalidasi oleh bundle sistem, jadi verifikasi ketat
        // tetap berfungsi dari dev lokal. Bypass sandbox dev hanya
        // dihormati di luar production dan hanya via flag env eksplisit.
        //
        // ─── Plan 40: iterasi kandidat CA bundle ─────────────────────
        // _resolve_ca_bundles() mengembalikan daftar CA bundle yang sudah
        // divalidasi (is_file + is_readable + filesize > 0), dengan bundle
        // sistem lebih diutamakan. Bila kandidat terpilih gagal di curl
        // dengan errno 77 (CURLE_SSL_CACERT_BADFILE — file CA rusak) atau
        // 60 (CURLE_SSL_CACERT — sertifikat tidak tertrust), coba kandidat
        // berikutnya sebelum fail-closed. Kegagalan lain (timeout/DNS) tidak
        // di-retry karena mengganti CA bundle tidak akan menyembuhkannya.
        $ca_bundles = $this->_resolve_ca_bundles();
        if (empty($ca_bundles)) {
            // Tidak ada bundle valid — serahkan ke default CA path PHP
            // (tanpa CURLOPT_CAINFO). Bila default pun tidak tersedia,
            // strict verify akan fail-closed di production (perilaku aman).
            $ca_bundles = array('');
        }

        $response   = false;
        $last_error = '';
        foreach ($ca_bundles as $ca_bundle) {
            $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            if ($ca_bundle !== '') {
                curl_setopt($ch, CURLOPT_CAINFO, $ca_bundle);
            }

            // Environment-aware dev sandbox bypass: HANYA dihormati di luar
            // production; guard di bawah membuat flag ini inert di produksi.
            if (ENVIRONMENT !== 'production' && getenv('CURL_SSL_VERIFY_DEV_BYPASS') === '1') {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                log_message('debug', 'reCAPTCHA: SSL verify bypass aktif (dev sandbox only).');
            }

            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Synapse-CI3/1.0 (reCAPTCHA verifier)');

            $attempt = curl_exec($ch);
            $errno   = curl_errno($ch);
            $errmsg  = curl_error($ch);
            curl_close($ch);

            if ($attempt !== false) {
                $response = $attempt;
                break;
            }

            $last_error = $errmsg . ' (errno ' . $errno . ')';
            if ($errno !== 77 && $errno !== 60) {
                // Bukan kegagalan CA bundle — retry tidak akan membantu.
                break;
            }
        }

        if ($response === false) {
            // Fail-closed: transport error / timeout — jangan pernah loloskan
            // verifikasi saat koneksi ke Google gagal.
            log_message('error', 'reCAPTCHA curl gagal: ' . $last_error);
            return FALSE;
        }

        $result = json_decode($response);
        return isset($result->success) && $result->success === true;
    }

    // ─── CA BUNDLE RESOLVER (Plan 34 + Plan 40) ─────────────────
    // Mengembalikan daftar SEMUA kandidat CA bundle yang VALID, dengan
    // urutan prioritas:
    //   1. SSL_CA_BUNDLE (env eksplisit — enterprise CA / sandbox self-signed)
    //   2. bundle sistem yang dikenal (Debian/Ubuntu, RHEL/Fedora, SUSE)
    //   3. ini_get('openssl.cafile') bila file-nya valid
    //   4. fallback Homebrew (macOS)
    // Setiap kandidat WAJIB lolos is_file() && is_readable() && filesize() > 0.
    // Kandidat kosong/rusak dilewati — mencegah curl errno 77
    // (CURLE_SSL_CACERT_BADFILE) akibat CA file yang ada tapi tidak valid
    // (kasus Plan 40: /usr/local/etc/openssl@1.1/cert.pem di build PHP
    // FlyEnv). Pemanggil (_verify_recaptcha) mengiterasi daftar ini dan
    // mencoba kandidat berikutnya bila curl gagal dengan errno 77/60.
    private function _resolve_ca_bundles() {
        $candidates = array(
            (string) getenv('SSL_CA_BUNDLE'),                      // 1. explicit override (env)
            '/etc/ssl/certs/ca-certificates.crt',                  // 2. Debian / Ubuntu
            '/etc/pki/tls/certs/ca-bundle.crt',                    //    RHEL / Fedora / CentOS
            '/etc/ssl/ca-bundle.pem',                              //    SUSE / OpenSUSE
            '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',   //    RHEL (ca-trust)
            (string) ini_get('openssl.cafile'),                    // 3. PHP ini setting
            '/usr/local/etc/openssl/cert.pem',                     // 4. Homebrew (macOS)
        );

        $valid = array();
        foreach ($candidates as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            if (is_file($path) && is_readable($path) && filesize($path) > 0) {
                $valid[] = $path;
            }
        }

        return $valid;
    }

    // ─── REGISTER ──────────────────────────────────────
    public function register() {
        if (!empty($this->session->userdata('user_id'))) {
            redirect('home');
        }

        // Plan 34 (C4): site key publik dari config (env RECAPTCHA_SITE_KEY),
        // bukan hardcoded di view.
        $data['recaptcha_site_key'] = (string) $this->config->item('recaptcha_site_key', 'recaptcha');

        // Phase 9A: Circuit Breaker — block registration if closed
        $this->load->model('Admin_model');
        if ($this->Admin_model->get_setting('is_registration_open') !== '1') {
            $data['errors'] = ['Pendaftaran member baru sedang ditutup sementara untuk menjaga stabilitas ekosistem. Silakan coba lagi nanti.'];
            $data['values'] = [];
            $this->load->view('auth/register', $data);
            return;
        }

        $data['errors'] = [];

        if ($this->input->post()) {
            // ─── RATE LIMIT (10B): burst limiter registrasi — key register:{ip}.
            // Setiap submission dihitung (sukses maupun gagal) agar bot yang
            // berhasil membuat akun pun tetap terkena pagu 5/15 menit per IP.
            $rl_key   = 'register:' . $this->input->ip_address();
            $throttle = $this->Rate_limit_model->check($rl_key, 5, 900);
            if (!$throttle['allowed']) {
                if ($this->input->is_ajax_request()) {
                    rate_limit_json_response($throttle);
                }
                $data['errors'][] = rate_limit_message($throttle['remaining_seconds']);
                $data['values']   = $this->input->post();
                $this->load->view('auth/register', $data);
                return;
            }
            $this->Rate_limit_model->hit($rl_key, 900, 5);

            // reCAPTCHA check first (fail-fast before DB)
            $recaptcha = $this->input->post('g-recaptcha-response', TRUE);
            if (!$this->_verify_recaptcha($recaptcha)) {
                $data['errors'][] = 'Verifikasi reCAPTCHA gagal. Silakan centang kotak \'Saya bukan robot\'.';
                $data['values']   = $this->input->post();
                $this->load->view('auth/register', $data);
                return;
            }

            // ─── M5 (plan/66 §5 → plan/67): normalize FIRST — validation
            //     (incl. is_unique[users.phone]) must see the canonical 08xx
            //     form, so 628xx/08xx variants of the same number are rejected
            //     here with a friendly message, not by a raw uk_phone DB error.
            $phone          = $this->_normalize_phone($this->input->post('phone', TRUE));
            $_POST['phone'] = $phone;

            $this->form_validation->set_rules('phone', 'Nomor Telepon', 'required|is_unique[users.phone]', array(
                'is_unique' => 'Nomor telepon sudah terdaftar. Silakan gunakan nomor lain atau login.',
            ));
            $this->form_validation->set_rules('password', 'Kata Sandi', 'required|min_length[8]');
            $this->form_validation->set_rules('invite_code', 'Kode Undangan', 'required');

            if ($this->form_validation->run()) {
                $password    = $this->input->post('password', TRUE);
                $invite_code = strtoupper(trim($this->input->post('invite_code', TRUE)));

                $parent = $this->db->get_where('users', ['invite_code' => $invite_code])->row();

                if (!$parent) {
                    $data['errors'][] = 'Kode Undangan tidak valid. Silakan periksa kembali.';
                    $data['values']   = $this->input->post();
                    $this->load->view('auth/register', $data);
                    return;
                }

                $user_data = [
                    'phone'     => $phone,
                    'password'  => password_hash($password, PASSWORD_BCRYPT),
                    'parent_id' => $parent->id,
                ];

                // Defensive (M5): is_unique closes the sequential path, but two
                // concurrent submits can still slip past it. Disable CI3 db_debug
                // for this one statement (db_debug=TRUE renders a raw error page
                // outside production) and translate a uk_phone duplicate (errno
                // 1062) into the same friendly message as the validation rule.
                $prev_debug         = $this->db->db_debug;
                $this->db->db_debug = FALSE;
                $user_id            = $this->User_model->create_user($user_data);
                $db_error           = $this->db->error();
                $this->db->db_debug = $prev_debug;

                if ($user_id) {
                    $this->session->set_flashdata('success', 'Pendaftaran berhasil! Silakan login.');
                    redirect('login');
                } elseif ((int) $db_error['code'] === 1062 && strpos((string) $db_error['message'], 'uk_phone') !== FALSE) {
                    $data['errors'][] = 'Nomor telepon sudah terdaftar. Silakan gunakan nomor lain atau login.';
                } else {
                    $data['errors'][] = 'Terjadi kesalahan sistem saat pendaftaran. Silakan coba lagi.';
                }
            }
        }

        $data['values'] = $this->input->post();
        $this->load->view('auth/register', $data);
    }

    // ─── LOGIN ────────────────────────────────────────
    public function login() {
        if (!empty($this->session->userdata('user_id'))) {
            redirect('home');
        }

        $data['errors'] = [];

        // Plan 34 (C4): site key publik dari config (env RECAPTCHA_SITE_KEY),
        // bukan hardcoded di view.
        $data['recaptcha_site_key'] = (string) $this->config->item('recaptcha_site_key', 'recaptcha');

        if ($this->input->post()) {
            // ─── RATE LIMIT (10B): fail-fast sebelum reCAPTCHA — key login:{phone}:{ip}
            $rl_phone = $this->_normalize_phone($this->input->post('phone', TRUE));
            $rl_key   = 'login:' . $rl_phone . ':' . $this->input->ip_address();
            $throttle = $this->Rate_limit_model->check($rl_key, 5, 900);
            if (!$throttle['allowed']) {
                if ($this->input->is_ajax_request()) {
                    rate_limit_json_response($throttle);
                }
                $data['errors'][] = rate_limit_message($throttle['remaining_seconds']);
                $data['values']   = $this->input->post();
                $this->load->view('auth/login', $data);
                return;
            }

            // reCAPTCHA check first (fail-fast before DB)
            $recaptcha = $this->input->post('g-recaptcha-response', TRUE);
            if (!$this->_verify_recaptcha($recaptcha)) {
                $data['errors'][] = 'Verifikasi reCAPTCHA gagal. Silakan centang kotak \'Saya bukan robot\'.';
                $data['values']   = $this->input->post();
                $this->load->view('auth/login', $data);
                return;
            }

            $this->form_validation->set_rules('phone', 'Nomor Telepon', 'required');
            $this->form_validation->set_rules('password', 'Kata Sandi', 'required');

            if ($this->form_validation->run()) {
                $phone    = $this->_normalize_phone($this->input->post('phone', TRUE));
                $password = $this->input->post('password', TRUE);

                // Strictly enforce 'user' role on user portal
                $user = $this->db->get_where('users', [
                    'phone' => $phone,
                    'role'  => 'user',
                ])->row();

                if ($user && password_verify($password, $user->password)) {
                    // Rate limit (10B): kredensial benar → bersihkan counter
                    $this->Rate_limit_model->clear($rl_key);

                    // Ban lockout — reject banned accounts. Checked AFTER credential
                    // verification so ban status is not leaked to callers without
                    // valid credentials.
                    if ((int) $user->is_banned === 1) {
                        $data['errors'][] = 'Akun Anda telah dinonaktifkan. Silakan hubungi admin.';
                        $data['values']   = $this->input->post();
                        $this->load->view('auth/login', $data);
                        return;
                    }

                    $this->session->set_userdata([
                        'user_id'     => $user->id,
                        'phone'       => $user->phone,
                        'level_id'    => $user->level_id,
                        'invite_code' => $user->invite_code,
                        'role'        => $user->role,
                    ]);

                    // Forced password change (7E3): go straight to change-password
                    // instead of the dashboard; MY_Controller guards all other pages.
                    if ((int) $user->must_change_password === 1) {
                        redirect('auth/change-password');
                    }

                    redirect('home');
                } else {
                    // Rate limit (10B): kredensial salah → catat percobaan gagal
                    $this->Rate_limit_model->hit($rl_key, 900, 5);
                    $data['errors'][] = 'Nomor telepon atau kata sandi salah.';
                }
            }
        }

        $data['values'] = $this->input->post();
        $this->load->view('auth/login', $data);
    }

    // ─── CHANGE PASSWORD (forced reset flow — 7E3) ────────
    public function change_password() {
        // Self-guard: Auth extends CI_Controller, not MY_Controller
        $user_id = $this->session->userdata('user_id');
        if (empty($user_id)) {
            redirect('login');
        }

        $user = $this->User_model->get_user_by_id($user_id);

        // Banned users cannot use the change-password flow either
        if ($user && (int) $user->is_banned === 1) {
            $this->session->unset_userdata('user_id');
            $this->session->set_flashdata('error', 'Akun Anda telah dinonaktifkan. Silakan hubungi admin.');
            redirect('login');
        }

        $data['errors'] = [];

        if ($this->input->post()) {
            $this->form_validation->set_rules('new_password', 'Kata Sandi Baru', 'required|min_length[8]');
            $this->form_validation->set_rules('confirm_password', 'Konfirmasi Kata Sandi', 'required|matches[new_password]');

            if ($this->form_validation->run()) {
                $new_password = $this->input->post('new_password', TRUE);

                $updated = $this->User_model->update_user($user_id, [
                    'password'             => password_hash($new_password, PASSWORD_BCRYPT),
                    'must_change_password' => 0,
                ]);

                if ($updated) {
                    $this->session->set_flashdata('success', 'Kata sandi berhasil diperbarui.');
                    redirect('home');
                } else {
                    $data['errors'][] = 'Gagal memperbarui kata sandi. Silakan coba lagi.';
                }
            }
        }

        $data['values'] = $this->input->post();
        $this->load->view('auth/change_password', $data);
    }

    // ─── LOGOUT ───────────────────────────────────────
    public function logout() {
        $this->session->sess_destroy();
        redirect('login');
    }
}
