<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends CI_Controller {

    public function __construct() {
        parent::__construct();
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

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://www.google.com/recaptcha/api/siteverify");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // CRITICAL FIX — bypass SSL for local dev
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // CRITICAL FIX — bypass SSL for local dev

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response);
        return isset($result->success) && $result->success === true;
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

            $this->form_validation->set_rules('phone', 'Nomor Telepon', 'required|is_unique[users.phone]');
            $this->form_validation->set_rules('password', 'Kata Sandi', 'required|min_length[8]');
            $this->form_validation->set_rules('invite_code', 'Kode Undangan', 'required');

            if ($this->form_validation->run()) {
                $phone       = $this->_normalize_phone($this->input->post('phone', TRUE));
                $_POST['phone'] = $phone;
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

                $user_id = $this->User_model->create_user($user_data);

                if ($user_id) {
                    $this->session->set_flashdata('success', 'Pendaftaran berhasil! Silakan login.');
                    redirect('login');
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

    // ─── ADMIN SEEDER (temporary) ─────────────────────
    public function seeder_admin() {
        $phone = '081234567890';
        $exists = $this->db->get_where('users', ['phone' => $phone])->row();

        if (!$exists) {
            $data = [
                'phone'       => $phone,
                'password'    => password_hash('admin123', PASSWORD_BCRYPT),
                'role'        => 'admin',
                'invite_code' => 'ADMIN0',
                'created_at'  => date('Y-m-d H:i:s'),
            ];
            $this->db->insert('users', $data);
            echo "<h3>Akun Admin berhasil dibuat!</h3>
                  <p>Phone: {$phone}<br>Password: admin123</p>
                  <a href='" . base_url('login') . "'>Kembali ke Login</a>";
        } else {
            echo "<h3>Akun Admin sudah ada! (Phone: {$phone})</h3>
                  <a href='" . base_url('login') . "'>Kembali ke Login</a>";
        }
    }
}
