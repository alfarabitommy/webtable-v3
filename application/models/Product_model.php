<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Product_model extends CI_Model {

    /**
     * Get all products that are currently active in the marketplace
     * @return array
     */
    public function get_all_active_products() {
        return $this->db->get_where('gpu_products', ['is_active' => 1])->result_array();
    }
}
