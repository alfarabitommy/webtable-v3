<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Profile extends CI_Controller {

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
}