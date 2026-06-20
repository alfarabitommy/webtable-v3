<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ledger_model extends CI_Model {

    /**
     * Insert transaction and update user balance atomically.
     * Uses SELECT ... FOR UPDATE to prevent double-spend race conditions.
     *
     * @param int         $user_id
     * @param string      $type         Transaction type enum value
     * @param float       $amount       Positive (income) or negative (expense)
     * @param string      $description  Human-readable note
     * @param string|null $ref_type     Reference table name (e.g. 'rentals', 'withdrawals')
     * @param int|null    $ref_id       Foreign key ID in reference table
     * @return bool
     */
    public function insert_transaction($user_id, $type, $amount, $description, $ref_type = null, $ref_id = null) {
        $this->db->trans_begin();

        try {
            // 1. Lock user row and read current balance
            $row = $this->db->query(
                "SELECT balance FROM users WHERE id = ? FOR UPDATE",
                [$user_id]
            )->row();

            if (!$row) {
                throw new Exception('User not found');
            }

            $current_balance = (float) $row->balance;
            $new_balance     = $current_balance + (float) $amount;

            // 2. Insert ledger record
            $transaction = [
                'user_id'        => $user_id,
                'type'           => $type,
                'amount'         => $amount,
                'balance_after'  => $new_balance,
                'description'    => $description,
                'reference_type' => $ref_type,
                'reference_id'   => $ref_id,
            ];

            if ($this->db->insert('transactions', $transaction) === false) {
                throw new Exception('Failed to insert transaction');
            }

            // 3. Update users.balance
            $this->db->where('id', $user_id);
            $this->db->update('users', ['balance' => $new_balance]);

            if ($this->db->affected_rows() === 0 && $new_balance !== $current_balance) {
                throw new Exception('Failed to update user balance');
            }

            // 4. Commit or rollback based on CI3 transaction status
            if ($this->db->trans_status() === false) {
                $this->db->trans_rollback();
                return false;
            }

            $this->db->trans_commit();
            return true;

        } catch (Exception $e) {
            $this->db->trans_rollback();
            log_message('error', 'Ledger_model::insert_transaction — ' . $e->getMessage());
            return false;
        }
    }
}
