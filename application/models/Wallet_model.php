<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Wallet_model extends CI_Model {

    public function get_balance($user_id) {
        $credit = $this->db->query(
            "SELECT COALESCE(SUM(amount), 0) AS total FROM wallet_ledger WHERE user_id = ? AND type = 'credit'",
            [$user_id]
        )->row()->total;

        $debit = $this->db->query(
            "SELECT COALESCE(SUM(amount), 0) AS total FROM wallet_ledger WHERE user_id = ? AND type = 'debit'",
            [$user_id]
        )->row()->total;

        return (int)($credit - $debit);
    }

    public function create_deposit($user_id, $amount) {
        $invoice = 'INV-' . date('YmdHis') . '-' . $user_id;
        $data = [
            'user_id'        => $user_id,
            'invoice_number' => $invoice,
            'amount'         => $amount,
            'status'         => 'pending',
        ];
        $this->db->insert('deposits', $data);
        return $invoice;
    }

    public function get_pending_deposits($user_id) {
        return $this->db->get_where('deposits', [
            'user_id' => $user_id,
            'status'  => 'pending',
        ])->result();
    }

    public function get_ledger_history($user_id) {
        $this->db->where('user_id', $user_id);
        $this->db->order_by('created_at', 'DESC');
        return $this->db->get('wallet_ledger')->result();
    }

    public function approve_deposit_simulator($invoice_number) {
        $this->db->trans_start();

        // 1. Update deposit status
        $this->db->where('invoice_number', $invoice_number)->update('deposits', ['status' => 'success']);

        // 2. Get deposit info
        $deposit = $this->db->get_where('deposits', ['invoice_number' => $invoice_number])->row();

        if ($deposit) {
            // 3. Insert credit record
            $this->db->insert('wallet_ledger', [
                'user_id'       => $deposit->user_id,
                'transaction_id'=> $invoice_number,
                'amount'        => $deposit->amount,
                'type'          => 'credit',
                'description'   => 'Top Up via ' . $invoice_number,
            ]);
        }

        $this->db->trans_complete();
        return $this->db->trans_status();
    }
}