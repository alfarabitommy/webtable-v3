<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin_model extends CI_Model {

    public function __construct() {
        parent::__construct();
        $this->load->database();
    }

    // ===== HISTORY: COUNTS =====

    public function count_history_deposits() {
        return (int) $this->db
            ->where_in('status', ['success', 'failed'])
            ->count_all_results('deposits');
    }

    public function count_history_withdrawals() {
        return (int) $this->db
            ->where_in('status', ['success', 'failed'])
            ->count_all_results('withdrawals');
    }

    // ===== HISTORY: FETCHERS =====

    public function get_history_deposits($limit, $offset) {
        $this->db->select('d.*, u.phone');
        $this->db->from('deposits d');
        $this->db->join('users u', 'u.id = d.user_id', 'left');
        $this->db->where_in('d.status', ['success', 'failed']);
        $this->db->order_by('d.created_at', 'DESC');
        $this->db->limit($limit, $offset);
        return $this->db->get()->result();
    }

    public function get_history_withdrawals($limit, $offset) {
        $this->db->select('w.*, u.phone, ba.bank_name, ba.account_number, ba.account_holder AS account_name');
        $this->db->from('withdrawals w');
        $this->db->join('users u', 'u.id = w.user_id', 'left');
        $this->db->join('bank_accounts ba', 'ba.id = w.bank_account_id', 'left');
        $this->db->where_in('w.status', ['success', 'failed']);
        $this->db->order_by('w.created_at', 'DESC');
        $this->db->limit($limit, $offset);
        return $this->db->get()->result();
    }

    // ===== SETTINGS =====

    public function get_all_settings() {
        $rows = $this->db->get('site_settings')->result();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row->key_name] = $row->setting_value;
        }
        return $settings;
    }

    public function update_settings($data) {
        $this->db->trans_start();
        foreach ($data as $key => $value) {
            $this->db->where('key_name', $key)->update('site_settings', ['setting_value' => $value]);
        }
        $this->db->trans_complete();
        return $this->db->trans_status();
    }

    // ===================================================================
    //  USER MANAGEMENT
    // ===================================================================

    // --- Listing & Search ---

    public function count_users($search = '') {
        if ($search) {
            $this->db->group_start();
            $this->db->like('u.phone', $search);
            $this->db->or_like('u.username', $search);
            $this->db->or_like('u.invite_code', $search);
            $this->db->group_end();
        }
        $this->db->from('users u');
        return (int) $this->db->count_all_results();
    }

    public function get_users($search = '', $limit = 50, $offset = 0) {
        $this->db->select('u.id, u.phone, u.username, u.invite_code, u.role, u.is_banned, u.balance, u.created_at, p.invite_code AS parent_invite_code');
        $this->db->from('users u');
        $this->db->join('users p', 'p.id = u.parent_id', 'left');

        if ($search) {
            $this->db->group_start();
            $this->db->like('u.phone', $search);
            $this->db->or_like('u.username', $search);
            $this->db->or_like('u.invite_code', $search);
            $this->db->group_end();
        }

        $this->db->order_by('u.created_at', 'DESC');
        $this->db->limit($limit, $offset);
        return $this->db->get()->result();
    }

    // --- Single User Detail ---

    public function get_user_detail($id) {
        return $this->db
            ->select('u.*, p.invite_code AS parent_invite_code, p.username AS parent_username')
            ->from('users u')
            ->join('users p', 'p.id = u.parent_id', 'left')
            ->where('u.id', $id)
            ->get()
            ->row();
    }

    // --- Calculated Balance (from wallet_ledger) ---

    public function get_user_balance($id) {
        $credit = (float) $this->db
            ->select_sum('amount')
            ->where('user_id', $id)
            ->where('type', 'credit')
            ->get('wallet_ledger')
            ->row()->amount ?? 0;

        $debit = (float) $this->db
            ->select_sum('amount')
            ->where('user_id', $id)
            ->where('type', 'debit')
            ->get('wallet_ledger')
            ->row()->amount ?? 0;

        return $credit - $debit;
    }

    // --- Wallet History ---

    public function get_wallet_history($user_id, $limit = 20) {
        return $this->db
            ->where('user_id', $user_id)
            ->order_by('created_at', 'DESC')
            ->limit($limit)
            ->get('wallet_ledger')
            ->result();
    }

    // --- Rentals for User ---

    public function get_user_rentals($user_id) {
        return $this->db
            ->select('ur.*, gp.name AS product_name, gp.price AS product_price, gp.daily_rate, gp.duration_days')
            ->from('user_rentals ur')
            ->join('gpu_products gp', 'gp.id = ur.product_id', 'left')
            ->where('ur.user_id', $user_id)
            ->order_by('ur.created_at', 'DESC')
            ->get()
            ->result();
    }

    // --- Direct Downline ---

    public function get_downline($user_id) {
        return $this->db
            ->select('id, phone, username, invite_code, created_at, is_banned')
            ->where('parent_id', $user_id)
            ->order_by('created_at', 'DESC')
            ->get('users')
            ->result();
    }

    // --- Profile Update ---

    public function update_user_profile($id, $data) {
        return $this->db->where('id', $id)->update('users', $data);
    }

    // --- Check invite_code uniqueness (excluding self) ---

    public function is_invite_code_taken($code, $exclude_id = 0) {
        $this->db->where('invite_code', $code);
        if ($exclude_id) {
            $this->db->where('id !=', $exclude_id);
        }
        return $this->db->count_all_results('users') > 0;
    }

    // --- Resolve upline from invite_code ---

    public function resolve_upline($invite_code) {
        return $this->db
            ->select('id, invite_code, username, phone')
            ->where('invite_code', $invite_code)
            ->get('users')
            ->row();
    }

    // --- Circular ancestor check ---

    public function has_ancestor($user_id, $candidate_ancestor_id) {
        $current = $candidate_ancestor_id;
        $limit = 50; // safety cap

        while ($current && $limit-- > 0) {
            if ($current == $user_id) {
                return true; // circular!
            }
            $row = $this->db->select('parent_id')->where('id', $current)->get('users')->row();
            $current = $row ? $row->parent_id : null;
        }
        return false;
    }

    // --- Update parent_id ---

    public function update_parent_id($user_id, $parent_id) {
        if ($parent_id) {
            return $this->db->where('id', $user_id)->update('users', ['parent_id' => $parent_id]);
        }
        return $this->db->where('id', $user_id)->update('users', ['parent_id' => null]);
    }

    // --- Toggle Ban ---

    public function toggle_ban($id) {
        $user = $this->db->select('is_banned')->where('id', $id)->get('users')->row();
        if (!$user) return false;

        $new_val = $user->is_banned ? 0 : 1;
        $this->db->where('id', $id)->update('users', ['is_banned' => $new_val]);
        return $new_val; // returns new state
    }

    // --- Balance Injection (ACID) ---

    public function inject_balance($user_id, $type, $amount, $description) {
        $transaction_id = 'ADM-' . date('YmdHis') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

        $this->db->trans_start();
        $this->db->insert('wallet_ledger', [
            'user_id'        => $user_id,
            'transaction_id' => $transaction_id,
            'type'           => $type,
            'amount'         => $amount,
            'description'    => $description,
        ]);
        $this->db->trans_complete();

        return $this->db->trans_status();
    }

    // --- Active Products for Dropdown ---

    public function get_active_products() {
        return $this->db
            ->where('is_active', 1)
            ->order_by('price', 'ASC')
            ->get('gpu_products')
            ->result();
    }

    // --- Inject Rental (Bypass) ---

    public function inject_rental($user_id, $product_id) {
        $product = $this->db->where('id', $product_id)->get('gpu_products')->row();
        if (!$product) return false;

        $this->db->trans_start();
        $this->db->insert('user_rentals', [
            'user_id'         => $user_id,
            'product_id'      => $product_id,
            'purchase_price'  => $product->price,
            'daily_roi'       => $product->daily_rate,
            'days_processed'  => 0,
            'total_days'      => $product->duration_days,
            'status'          => 'active',
            'last_claimed_at' => date('Y-m-d H:i:s'),
            'expired_at'      => date('Y-m-d H:i:s', strtotime('+' . $product->duration_days . ' days')),
        ]);
        $this->db->trans_complete();

        return $this->db->trans_status();
    }

    // --- Cancel Rental (Soft) ---

    public function cancel_rental($rental_id) {
        return $this->db
            ->where('id', $rental_id)
            ->update('user_rentals', ['status' => 'cancelled']);
    }

    // --- Time Travel ---

    public function adjust_rental_time($rental_id, $last_claimed_at, $days_processed) {
        return $this->db
            ->where('id', $rental_id)
            ->update('user_rentals', [
                'last_claimed_at' => $last_claimed_at,
                'days_processed'  => (int) $days_processed,
            ]);
    }

    // ===================================================================
    //  CREATE USER (Admin Bypass)
    // ===================================================================

    public function generate_invite_code() {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while ($this->is_invite_code_taken($code));
        return $code;
    }

    public function create_user($data) {
        $this->db->insert('users', $data);
        $insert_id = $this->db->insert_id();
        return $insert_id > 0 ? $insert_id : false;
    }

    // ===================================================================
    //  FORCE RESET PASSWORD
    // ===================================================================

    public function force_reset_password($user_id, $hashed_password) {
        return $this->db->where('id', $user_id)->update('users', ['password' => $hashed_password]);
    }

    public function user_exists($id) {
        return $this->db->where('id', $id)->count_all_results('users') > 0;
    }
}
