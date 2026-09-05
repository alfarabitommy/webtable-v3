<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends CI_Controller {

    public function __construct() {
        parent::__construct();

        // M2 (plan/58 §3 Phase 2): pin WIB sesi MySQL sebagai statement DB
        // pertama (Auth bukan MY_Controller — entry point login/register).
        $this->db->query("SET time_zone = '+07:00'");

        $this->load->helper('captcha');
        $this->load->model('User_model');
        $this->load->model('Rate_limit_model');
        $this->load->helper('ratelimit');
        // M9/P7 (plan/76 Batch C): choke-point JSON helper.
        $this->load->helper('api');
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

    // ─── NATIVE SVG CAPTCHA LIFECYCLE (plan/72) ─────────────
    // Session-bound challenge with strict single-use + TTL 180s. No external
    // service, no disk I/O, no GD/Imagick — the challenge exists only in the
    // `auth_captcha` session data; rendering is pure (captcha_helper).
    private const CAPTCHA_TTL_SECONDS = 180;

    // Issue a fresh 5-char challenge: persist session (auth_captcha) and
    // return the inline SVG (for views/refresh) plus the raw code (tests).
    private function _issue_captcha() {
        $alphabet = captcha_alphabet();
        $code     = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $this->session->set_userdata('auth_captcha', array(
            'code'    => $code,
            'expires' => time() + self::CAPTCHA_TTL_SECONDS,
        ));

        return array(
            'svg'  => build_captcha_svg($code),
            'code' => $code,
        );
    }

    // Evaluate a submitted code. STRICT single-use: the stored challenge is
    // flushed on EVERY evaluation — success, empty input, wrong code, or
    // expired — so a captured response can never be replayed. TTL (180s)
    // enforced here; failures surface as one friendly message upstream.
    private function _verify_captcha($input) {
        $stored = $this->session->userdata('auth_captcha');
        $this->session->unset_userdata('auth_captcha');

        if (!is_array($stored) || empty($stored['code'])) {
            return FALSE;
        }
        if (time() > (int) $stored['expires']) {
            return FALSE;
        }

        $input   = strtolower(trim((string) $input));
        $captcha = strtolower(trim((string) $stored['code']));
        return $input !== '' && $input === $captcha;
    }

    // Render an auth view with a FRESH captcha challenge on every render
    // (initial GET and each failed-POST re-render). The previous challenge
    // was already consumed by _verify_captcha(), so re-issuing here keeps
    // the displayed SVG the only valid one.
    private function _render_auth_view($view, array $data) {
        $data['captcha_svg'] = $this->_issue_captcha()['svg'];
        $this->load->view($view, $data);
    }

    // ─── REGISTER ──────────────────────────────────────
    public function register() {
        if (!empty($this->session->userdata('user_id'))) {
            redirect('home');
        }

        // Phase 9A: Circuit Breaker — block registration if closed
        $this->load->model('Admin_model');
        if ($this->Admin_model->get_setting('is_registration_open') !== '1') {
            $data['errors'] = ['Pendaftaran member baru sedang ditutup sementara untuk menjaga stabilitas ekosistem. Silakan coba lagi nanti.'];
            $data['values'] = [];
            $this->_render_auth_view('auth/register', $data);
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
                $this->_render_auth_view('auth/register', $data);
                return;
            }
            $this->Rate_limit_model->hit($rl_key, 900, 5);

            // Native SVG captcha gate (fail-fast before DB) — plan/72.
            // Single-use: whatever the outcome, the stored challenge is
            // flushed, so a fresh code is always required on the next POST.
            if (!$this->_verify_captcha($this->input->post('captcha', TRUE))) {
                $data['errors'][] = 'Kode keamanan salah atau sudah kedaluwarsa.';
                $data['values']   = $this->input->post();
                $this->_render_auth_view('auth/register', $data);
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
                    $this->_render_auth_view('auth/register', $data);
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
        $this->_render_auth_view('auth/register', $data);
    }

    // ─── LOGIN ────────────────────────────────────────
    public function login() {
        if (!empty($this->session->userdata('user_id'))) {
            redirect('home');
        }

        $data['errors'] = [];

        if ($this->input->post()) {
            // ─── RATE LIMIT (10B): fail-fast sebelum captcha — key login:{phone}:{ip}
            $rl_phone = $this->_normalize_phone($this->input->post('phone', TRUE));
            $rl_key   = 'login:' . $rl_phone . ':' . $this->input->ip_address();
            $throttle = $this->Rate_limit_model->check($rl_key, 5, 900);
            if (!$throttle['allowed']) {
                if ($this->input->is_ajax_request()) {
                    rate_limit_json_response($throttle);
                }
                $data['errors'][] = rate_limit_message($throttle['remaining_seconds']);
                $data['values']   = $this->input->post();
                $this->_render_auth_view('auth/login', $data);
                return;
            }

            // Native SVG captcha gate (fail-fast before DB) — plan/72.
            // Single-use: whatever the outcome, the stored challenge is
            // flushed, so a fresh code is always required on the next POST.
            if (!$this->_verify_captcha($this->input->post('captcha', TRUE))) {
                $data['errors'][] = 'Kode keamanan salah atau sudah kedaluwarsa.';
                $data['values']   = $this->input->post();
                $this->_render_auth_view('auth/login', $data);
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
                        $this->_render_auth_view('auth/login', $data);
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
        $this->_render_auth_view('auth/login', $data);
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

    // ─── CAPTCHA REFRESH (plan/72) ──────────────────────
    // AJAX JSON endpoint: rotates the session challenge and returns a fresh
    // SVG + TTL so the reload button never needs a full page refresh. GET +
    // read-only (only the caller's own session data rotates) → no CSRF token
    // required; CI3 validates POST bodies only.
    public function refresh_captcha() {
        if (!$this->input->is_ajax_request()) {
            redirect('login');
            return;
        }

        $challenge = $this->_issue_captcha();

        // M9/P7 (plan/76 Batch C): envelope {success, message,
        // data:{svg, expires_in}} + key legacy root `svg`/`expires_in`
        // (dibaca auth/login.php & auth/register.php). HTTP TETAP 200 —
        // konsumen mengecek res.ok (fallback reload). Enkoder choke-point
        // memakai flag JSON_HEX_* (parity encoding lama di endpoint ini).
        api_success(
            [
                'svg'        => $challenge['svg'],
                'expires_in' => self::CAPTCHA_TTL_SECONDS,
            ],
            '',
            200,
            [
                'svg'        => $challenge['svg'],
                'expires_in' => self::CAPTCHA_TTL_SECONDS,
            ]
        );
    }

    // ─── LOGOUT ───────────────────────────────────────
    public function logout() {
        $this->session->sess_destroy();
        redirect('login');
    }
}
