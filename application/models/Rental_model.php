<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Rental_model extends CI_Model {

    /**
     * Create a new rental record
     * @param array $data
     * @return int|bool Rental ID on success, false on failure
     */
    public function create_rental($data) {
        if ($this->db->insert('rentals', $data)) {
            return $this->db->insert_id();
        }
        return false;
    }

    /**
     * Get all rentals for a specific user
     * @param int $user_id
     * @return array
     */
    public function get_user_rentals($user_id) {
        return $this->db->get_where('rentals', ['user_id' => $user_id])->result_array();
    }
}
