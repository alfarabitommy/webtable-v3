<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_model extends CI_Model {

    /**
     * Create a new user with auto-generated invite code
     * @param array $data Keys: phone, password, parent_id (optional)
     * @return int|bool User ID on success, false on failure
     */
    public function create_user($data) {
        $data['invite_code'] = $this->_generate_invite_code();

        if ($this->db->insert('users', $data)) {
            return $this->db->insert_id();
        }

        return false;
    }

    /**
     * Retrieve user by phone number
     * @param string $phone
     * @return object|null
     */
    /**
     * Retrieve user by ID
     * @param int $user_id
     * @return object|null
     */
    public function get_user_by_id($user_id) {
        return $this->db->get_where('users', ['id' => $user_id])->row();
    }

    public function get_user_by_phone($phone) {
        return $this->db->get_where('users', ['phone' => $phone])->row();
    }

    /**
     * Get downlines by level
     * @param int $user_id The parent user ID
     * @param int $level 1 for Direct (B), 2 for Indirect (C)
     * @return array
     */
    public function get_downlines($user_id, $level) {
        if ($level == 1) {
            // Direct downlines (B)
            return $this->db->get_where('users', ['parent_id' => $user_id])->result_array();
        } elseif ($level == 2) {
            // Indirect downlines (C) — children of direct downlines
            $this->db->select('u.*');
            $this->db->from('users u');
            $this->db->join('users p', 'u.parent_id = p.id');
            $this->db->where('p.parent_id', $user_id);
            return $this->db->get()->result_array();
        }
        return [];
    }

    /**
     * Generate a unique 6-character alphanumeric invite code
     * @return string
     */
    private function _generate_invite_code() {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        do {
            $code  = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $exists = $this->db->get_where('users', ['invite_code' => $code])->num_rows();
        } while ($exists > 0);

        return $code;
    }
}
