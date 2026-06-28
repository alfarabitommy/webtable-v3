<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Rental_model extends CI_Model {

    /**
     * Insert into user_rentals. Calculate expired_at.
     */
    public function create_rental($user_id, $product_id, $price, $roi, $duration_days) {
        $data = [
            'user_id'         => $user_id,
            'product_id'      => $product_id,
            'purchase_price'  => $price,
            'daily_roi'       => $roi,
            'status'          => 'active',
            'expired_at'      => date('Y-m-d H:i:s', strtotime("+{$duration_days} days")),
        ];

        if ($this->db->insert('user_rentals', $data)) {
            return $this->db->insert_id();
        }
        return false;
    }

    /**
     * Query user_rentals joined with gpu_products for product name.
     * Strictly returns array of objects.
     */
    public function get_active_rentals($user_id) {
        $this->db->select('user_rentals.*, gpu_products.name as product_name');
        $this->db->from('user_rentals');
        $this->db->join('gpu_products', 'gpu_products.id = user_rentals.product_id', 'left');
        $this->db->where('user_rentals.user_id', $user_id);
        $this->db->where('user_rentals.status', 'active');
        $this->db->order_by('user_rentals.created_at', 'DESC');
        return $this->db->get()->result(); // strictly array of Objects
    }

    /**
     * DB Transaction: update last_claimed_at + credit wallet_ledger.
     */
    public function claim_roi($rental_id, $user_id, $roi_amount) {
        $this->db->trans_start();

        // 1. Update last_claimed_at
        $this->db->where('id', $rental_id);
        $this->db->where('user_id', $user_id);
        $this->db->update('user_rentals', [
            'last_claimed_at' => date('Y-m-d H:i:s'),
        ]);

        // 2. Credit wallet_ledger
        $this->db->insert('wallet_ledger', [
            'user_id'        => $user_id,
            'transaction_id' => 'ROI-' . $rental_id . '-' . date('YmdHis'),
            'type'           => 'credit',
            'amount'         => $roi_amount,
            'description'    => 'ROI Harian #' . $rental_id,
        ]);

        $this->db->trans_complete();
        return $this->db->trans_status();
    }

    /**
     * Get a single rental by id + user_id (ownership check).
     */
    public function get_rental($rental_id, $user_id) {
        return $this->db->get_where('user_rentals', [
            'id'      => $rental_id,
            'user_id' => $user_id,
        ])->row();
    }

    public function has_active_rental($user_id) {
        return $this->db->where('user_id', $user_id)
                        ->where('status', 'active')
                        ->limit(1)
                        ->get('user_rentals')
                        ->num_rows() > 0;
    }
}
