<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends CI_Controller {

    private $recaptcha_secret = '6Le3PSgtAAAAAL65R6znylzjtBpAp9i8yBi-HW2w';

    public function __construct() {
        parent::__construct();
        $this->load->model('User_model');
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

        $data = array('secret' => $this->recaptcha_secret, 'response' => $recaptcha_response);

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
                    $this->session->set_userdata([
                        'user_id'     => $user->id,
                        'phone'       => $user->phone,
                        'level_id'    => $user->level_id,
                        'invite_code' => $user->invite_code,
                        'role'        => $user->role,
                    ]);
                    redirect('home');
                } else {
                    $data['errors'][] = 'Nomor telepon atau kata sandi salah.';
                }
            }
        }

        $data['values'] = $this->input->post();
        $this->load->view('auth/login', $data);
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
