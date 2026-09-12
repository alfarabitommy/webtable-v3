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
            'page_title' => lang('profile_page_title'),
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
            $this->session->set_flashdata('error', lang('profile_err_name_length'));
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
                // plan/103: display_errors() mengembalikan prosa INGGRIS dari
                // system/language/english/upload_lang.php — tidak pernah
                // ditampilkan ke member (leak di mode id). Pesan generik
                // ber-kamus + detail asli masuk log.
                log_message('error', 'Profile::update upload gagal: ' . $this->upload->display_errors('', ''));
                $this->session->set_flashdata('error', lang('profile_err_upload_failed'));
                redirect('profile');
            }
        }

        if ($this->User_model->update_user($user_id, $update_data)) {
            $this->session->set_flashdata('success', lang('profile_ok_updated'));
        } else {
            $this->session->set_flashdata('error', lang('profile_err_update_failed'));
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
        $this->session->set_flashdata('success', lang('profile_ok_photo_deleted'));
        redirect('profile');
    }

    // ─── VOLUNTARY CHANGE PASSWORD ─────────────────────
    public function change_password() {
        $user_id = $this->session->userdata('user_id');
        $user    = $this->User_model->get_user_by_id($user_id);   // non-null: MY_Controller guard passed

        $data['errors'] = [];

        if ($this->input->post()) {
            // plan/103: label & pesan form_validation dari kamus (dulu literal
            // Indonesia + pesan bawaan CI3 berbahasa Inggris).
            $this->form_validation->set_rules(
                'current_password',
                lang('fv_label_current_password'),
                'required|callback__verify_current_password'
            );
            $this->form_validation->set_rules('new_password', lang('fv_label_new_password'), 'required|min_length[8]');
            $this->form_validation->set_rules(
                'confirm_password',
                lang('fv_label_confirm_password'),
                'required|matches[new_password]'
            );

            $this->form_validation->set_message([
                'required'   => lang('fv_msg_required'),
                'min_length' => lang('fv_msg_min_length'),
                'matches'    => lang('fv_msg_matches'),
            ]);

            if ($this->form_validation->run()) {
                $updated = $this->User_model->update_user($user_id, [
                    'password' => password_hash($this->input->post('new_password', TRUE), PASSWORD_BCRYPT),
                ]);

                if ($updated) {
                    $this->session->set_flashdata('success', lang('auth_ok_password_updated'));
                    redirect('profile');
                }

                $data['errors'][] = lang('profile_err_update_failed');
            }
        }

        $data['values'] = $this->input->post();
        $this->load->view('templates/header', ['page_title' => lang('profile_change_password_title')]);
        $this->load->view('profile/change_password', $data);
        $this->load->view('templates/bottom_nav');
    }

    // Form-validation callback: verifies current password against stored bcrypt hash
    public function _verify_current_password($current_password) {
        $user = $this->User_model->get_user_by_id($this->session->userdata('user_id'));
        if ($user && password_verify($current_password, $user->password)) {
            return TRUE;
        }
        $this->form_validation->set_message('_verify_current_password', lang('profile_err_current_password'));
        return FALSE;
    }
}