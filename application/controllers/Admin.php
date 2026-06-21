<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin extends CI_Controller {

    public function __construct() {
        parent::__construct();

        $this->load->database();
        $this->load->library('session');
        $this->load->helper('url');

        if (!$this->session->userdata('admin_id')) {
            redirect('control-panel');
        }
    }

    public function index() {
        $pending_deposits = $this->db->select('d.*, u.phone')
            ->from('deposits d')
            ->join('users u', 'u.id = d.user_id', 'left')
            ->where('d.status', 'pending')
            ->order_by('d.created_at', 'ASC')
            ->get()->result();

        $pending_withdrawals = $this->db->select('w.*, u.phone')
            ->from('withdrawals w')
            ->join('users u', 'u.id = w.user_id', 'left')
            ->where('w.status', 'pending')
            ->order_by('w.created_at', 'ASC')
            ->get()->result();

        $data = [
            'page_title'         => 'Command Center',
            'pending_deposits'   => $pending_deposits,
            'pending_withdrawals'=> $pending_withdrawals,
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('admin/dashboard', $data);
        $this->load->view('templates/bottom_nav');
    }

    public function approve_deposit($deposit_id) {
        $deposit = $this->db->get_where('deposits', ['id' => $deposit_id])->row();
        if (!$deposit || $deposit->status !== 'pending') {
            $this->session->set_flashdata('error', 'Deposit tidak valid atau sudah diproses.');
            redirect('admin');
            return;
        }

        $this->db->trans_start();

        // 1. Update deposit status
        $this->db->where('id', $deposit_id)->update('deposits', ['status' => 'success']);

        // 2. Credit wallet ledger
        $this->db->insert('wallet_ledger', [
            'user_id'        => $deposit->user_id,
            'transaction_id' => $deposit->invoice_number,
            'amount'         => $deposit->amount,
            'type'           => 'credit',
            'description'    => 'Top Up via ' . $deposit->invoice_number,
        ]);

        $this->db->trans_complete();

        if ($this->db->trans_status()) {
            $this->session->set_flashdata('success', 'Deposit #' . $deposit->invoice_number . ' berhasil disetujui.');
        } else {
            $this->session->set_flashdata('error', 'Gagal memproses deposit.');
        }

        redirect('admin');
    }

    public function approve_withdrawal($wd_id) {
        $wd = $this->db->get_where('withdrawals', ['id' => $wd_id])->row();
        if (!$wd || $wd->status !== 'pending') {
            $this->session->set_flashdata('error', 'Penarikan tidak valid atau sudah diproses.');
            redirect('admin');
            return;
        }

        $this->db->trans_start();

        // Balance was already deducted on request — just flip status
        $this->db->where('id', $wd_id)->update('withdrawals', ['status' => 'success']);

        $this->db->trans_complete();

        if ($this->db->trans_status()) {
            $this->session->set_flashdata('success', 'Penarikan #' . $wd->wd_number . ' berhasil disetujui.');
        } else {
            $this->session->set_flashdata('error', 'Gagal memproses penarikan.');
        }

        redirect('admin');
    }

    public function decline_withdrawal($wd_id) {
        $wd = $this->db->get_where('withdrawals', ['id' => $wd_id])->row();
        if (!$wd || $wd->status !== 'pending') {
            $this->session->set_flashdata('error', 'Penarikan tidak valid atau sudah diproses.');
            redirect('admin');
            return;
        }

        $this->db->trans_start();

        // 1. Update withdrawal status to failed
        $this->db->where('id', $wd_id)->update('withdrawals', ['status' => 'failed']);

        // 2. Refund: insert credit transaction into wallet_ledger
        // Funds were already debited on request — must credit back
        $this->db->insert('wallet_ledger', [
            'user_id'        => $wd->user_id,
            'transaction_id' => $wd->wd_number,
            'amount'         => $wd->amount,
            'type'           => 'credit',
            'description'    => 'Pengembalian Dana: Penarikan Ditolak (' . $wd->wd_number . ')',
        ]);

        $this->db->trans_complete();

        if ($this->db->trans_status()) {
            $this->session->set_flashdata('success', 'Penarikan #' . $wd->wd_number . ' ditolak & dana dikembalikan.');
        } else {
            $this->session->set_flashdata('error', 'Gagal menolak penarikan.');
        }

        redirect('admin');
    }
}
