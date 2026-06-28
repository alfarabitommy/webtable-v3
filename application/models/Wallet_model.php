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

    // ===== WITHDRAWAL =====

    public function create_withdrawal($user_id, $amount, $bank_account_id) {
        $wd_number = 'WD-' . date('YmdHis') . '-' . $user_id;

        $this->db->trans_start();

        // 1. Insert withdrawal record (bank_account_id FK only — no raw bank strings)
        $this->db->insert('withdrawals', [
            'user_id'          => $user_id,
            'wd_number'        => $wd_number,
            'amount'           => $amount,
            'bank_account_id'  => $bank_account_id,
            'status'           => 'pending',
        ]);

        // 2. Insert debit into wallet_ledger (lock funds immediately)
        $this->db->insert('wallet_ledger', [
            'user_id'        => $user_id,
            'transaction_id' => $wd_number,
            'amount'         => $amount,
            'type'           => 'debit',
            'description'    => 'Penarikan Dana via ' . $wd_number,
        ]);

        $this->db->trans_complete();

        if ($this->db->trans_status()) {
            return $wd_number;
        }
        return false;
    }

    public function get_pending_withdrawals($user_id) {
        $this->db->select('w.*, b.bank_name, b.account_number, b.account_holder AS account_name');
        $this->db->from('withdrawals w');
        $this->db->join('bank_accounts b', 'b.id = w.bank_account_id', 'left');
        $this->db->where('w.user_id', $user_id);
        $this->db->where('w.status', 'pending');
        $this->db->order_by('w.created_at', 'DESC');
        return $this->db->get()->result();
    }

    public function has_pending_withdrawal($user_id) {
        $this->db->where('user_id', $user_id);
        $this->db->where('status', 'pending');
        $this->db->limit(1);
        return $this->db->get('withdrawals')->num_rows() > 0;
    }

    public function has_reached_daily_wd_limit($user_id) {
        return $this->db
            ->where('user_id', $user_id)
            ->where("DATE(created_at) = CURDATE()")
            ->where_in('status', ['pending', 'success', 'processing'])
            ->limit(1)
            ->get('withdrawals')
            ->num_rows() > 0;
    }

    public function approve_withdrawal_simulator($wd_number) {
        $this->db->trans_start();

        $this->db->where('wd_number', $wd_number);
        $this->db->update('withdrawals', [
            'status' => 'success',
        ]);

        $this->db->trans_complete();
        return $this->db->trans_status();
    }

    // ===== BANK BINDING (IMMUTABLE) =====

    public function get_user_bank($user_id) {
        return $this->db->get_where('bank_accounts', ['user_id' => $user_id])->row();
    }

    public function insert_bank($data) {
        return $this->db->insert('bank_accounts', $data);
    }
}