<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Product_model extends CI_Model {

    /**
     * Shared mock data — single source of truth for UAT.
     * Matches DB schema: id, name, type, price, daily_rate, duration_days, description.
     */
    private $mock_products = [
        [
            'id'            => 1,
            'name'          => 'NVIDIA RTX 4090 Node',
            'type'          => 'short_term',
            'description'   => 'Dedicated GPU node untuk AI training & inference.',
            'price'         => 1500000,
            'daily_rate'    => 45000,
            'duration_days' => 30,
            'is_active'     => 1,
        ],
        [
            'id'            => 2,
            'name'          => 'AMD MI300X Cluster',
            'type'          => 'long_term',
            'description'   => 'Enterprise-grade HPC cluster untuk workload masif.',
            'price'         => 3500000,
            'daily_rate'    => 120000,
            'duration_days' => 90,
            'is_active'     => 1,
        ],
        [
            'id'            => 3,
            'name'          => 'Cloud VPS Enterprise',
            'type'          => 'short_term',
            'description'   => 'Virtual server high-performance untuk aplikasi production.',
            'price'         => 750000,
            'daily_rate'    => 22000,
            'duration_days' => 30,
            'is_active'     => 1,
        ],
        [
            'id'            => 4,
            'name'          => 'AI Inference Pod',
            'type'          => 'long_term',
            'description'   => 'Pod inference optimal untuk deployment model LLM.',
            'price'         => 2000000,
            'daily_rate'    => 65000,
            'duration_days' => 60,
            'is_active'     => 1,
        ],
    ];

    /**
     * Get all active products — DB first, mock fallback.
     * Returns array with consistent keys including 'daily_rate' and 'duration_days'.
     */
    public function get_all_active_products() {
        $db_products = $this->db->get_where('gpu_products', ['is_active' => 1])->result_array();
        if (!empty($db_products)) {
            return $db_products;
        }
        return $this->mock_products;
    }

    /**
     * Get single product by ID — DB first, mock fallback.
     * Used by Rentals/checkout to resolve product_id from form.
     */
    public function get_product($id) {
        // Try real DB first
        $row = $this->db->get_where('gpu_products', ['id' => $id])->row_array();
        if ($row) {
            return $row;
        }
        // Fallback to mock
        foreach ($this->mock_products as $product) {
            if ((int)$product['id'] === (int)$id) {
                return $product;
            }
        }
        return null;
    }
}
