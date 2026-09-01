<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Profile extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('User_model');
    }

    public function index() {
        $user_id = $this->session->userdata('user_id');
        $user = $this->User_model->get_user_by_id($user_id);

        $data = [
            'page_title' => 'Profil Saya',
            'user'       => $user,
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('profile/index', $data);
        $this->load->view('templates/bottom_nav');
    }

    public function update() {
        $user_id = $this->session->userdata('user_id');

        $username = trim($this->input->post('username', TRUE));
        if ($username === '' || mb_strlen($username) > 50) {
            $this->session->set_flashdata('error', 'Nama 1-50 karakter');
            redirect('profile');
        }

        $update_data = ['username' => $username];

        // Avatar upload
        if (!empty($_FILES['avatar']['name'])) {
            $config['upload_path']          = './uploads/avatars/';
            $config['allowed_types']        = 'gif|jpg|jpeg|png';
            $config['max_size']             = 2048;
            $config['encrypt_name']         = TRUE;
            $config['remove_spaces']        = TRUE;
            $config['detect_mime']          = TRUE;

            $this->load->library('upload', $config);

            if ($this->upload->do_upload('avatar')) {
                $upload_data = $this->upload->data();
                $update_data['avatar_url'] = $upload_data['file_name'];

                // Delete old avatar
                $user = $this->User_model->get_user_by_id($user_id);
                if (!empty($user->avatar_url) && file_exists('./uploads/avatars/' . $user->avatar_url)) {
                    @unlink('./uploads/avatars/' . $user->avatar_url);
                }
            } else {
                $this->session->set_flashdata('error', $this->upload->display_errors('', ''));
                redirect('profile');
            }
        }

        if ($this->User_model->update_user($user_id, $update_data)) {
            $this->session->set_flashdata('success', 'Profil diperbarui');
        } else {
            $this->session->set_flashdata('error', 'Gagal memperbarui');
        }

        redirect('profile');
    }

    public function avatar_delete() {
        $user_id = $this->session->userdata('user_id');
        $user = $this->User_model->get_user_by_id($user_id);

        if (!empty($user->avatar_url) && file_exists('./uploads/avatars/' . $user->avatar_url)) {
            @unlink('./uploads/avatars/' . $user->avatar_url);
        }

        $this->User_model->update_user($user_id, ['avatar_url' => NULL]);
        $this->session->set_flashdata('success', 'Foto dihapus');
        redirect('profile');
    }

    // ─── VOLUNTARY CHANGE PASSWORD ─────────────────────
    public function change_password() {
        $user_id = $this->session->userdata('user_id');
        $user    = $this->User_model->get_user_by_id($user_id);   // non-null: MY_Controller guard passed

        $data['errors'] = [];

        if ($this->input->post()) {
            $this->form_validation->set_rules(
                'current_password',
                'Kata Sandi Saat Ini',
                'required|callback__verify_current_password'
            );
            $this->form_validation->set_rules('new_password', 'Kata Sandi Baru', 'required|min_length[8]');
            $this->form_validation->set_rules(
                'confirm_password',
                'Konfirmasi Kata Sandi',
                'required|matches[new_password]'
            );

            $this->form_validation->set_message([
                'required'   => '{field} wajib diisi.',
                'min_length' => '{field} minimal {param} karakter.',
                'matches'    => '{field} tidak cocok dengan {param}.',
            ]);

            if ($this->form_validation->run()) {
                $updated = $this->User_model->update_user($user_id, [
                    'password' => password_hash($this->input->post('new_password', TRUE), PASSWORD_BCRYPT),
                ]);

                if ($updated) {
                    $this->session->set_flashdata('success', 'Kata sandi berhasil diperbarui.');
                    redirect('profile');
                }

                $data['errors'][] = 'Gagal memperbarui kata sandi. Silakan coba lagi.';
            }
        }

        $data['values'] = $this->input->post();
        $this->load->view('templates/header', ['page_title' => 'Ubah Kata Sandi']);
        $this->load->view('profile/change_password', $data);
        $this->load->view('templates/bottom_nav');
    }

    // Form-validation callback: verifies current password against stored bcrypt hash
    public function _verify_current_password($current_password) {
        $user = $this->User_model->get_user_by_id($this->session->userdata('user_id'));
        if ($user && password_verify($current_password, $user->password)) {
            return TRUE;
        }
        $this->form_validation->set_message('_verify_current_password', 'Kata sandi saat ini salah.');
        return FALSE;
    }
}