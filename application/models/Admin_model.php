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

    // ===================================================================
    //  TREASURY HEALTH (Phase 9A)
    // ===================================================================

    public function get_treasury_stats() {
        // Total Cash In — sum of all rental purchase prices
        $cash_in = (float) $this->db
            ->select_sum('purchase_price')
            ->get('user_rentals')
            ->row()->purchase_price ?? 0;

        // Total Balances — dynamic from wallet_ledger (credit − debit)
        $row_bal = $this->db->query(
            "SELECT COALESCE(
                SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END)
              - SUM(CASE WHEN type = 'debit'  THEN amount ELSE 0 END)
            , 0) AS total_balances
            FROM wallet_ledger"
        )->row();
        $balances = (float) ($row_bal ? $row_bal->total_balances : 0);

        // Pending ROI — future obligation from active rentals
        // Use raw query to avoid CI3 aliasing issues with computed columns
        $row = $this->db->query(
            "SELECT COALESCE(SUM((total_days - days_processed) * daily_roi), 0) AS pending_roi
             FROM user_rentals WHERE status = 'active'"
        )->row();
        $pending_roi = (float) ($row ? $row->pending_roi : 0);

        return [
            'total_cash_in'  => $cash_in,
            'total_balances' => $balances,
            'pending_roi'    => $pending_roi,
            'is_critical'    => ($balances + $pending_roi) > $cash_in,
        ];
    }

    // ===== SYSTEM SETTINGS (key-value) =====

    public function get_setting($key) {
        $row = $this->db
            ->select('key_value')
            ->where('key_name', $key)
            ->get('system_settings')
            ->row();
        return $row ? $row->key_value : null;
    }

    public function set_setting($key, $value) {
        return $this->db->query(
            'INSERT INTO system_settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), updated_at = CURRENT_TIMESTAMP',
            [$key, $value]
        );
    }

    // ===================================================================
    //  PHASE 9A: ANALYTICS
    // ===================================================================

    public function get_active_users_count() {
        return (int) $this->db->query(
            "SELECT COUNT(DISTINCT user_id) AS cnt FROM user_rentals WHERE status = 'active'"
        )->row()->cnt ?? 0;
    }

    public function get_rental_volume() {
        return (float) $this->db
            ->select_sum('purchase_price')
            ->get('user_rentals')
            ->row()->purchase_price ?? 0;
    }

    public function get_withdrawal_volume() {
        $query = $this->db->query("SELECT COALESCE(SUM(amount), 0) AS total_withdrawal FROM withdrawals WHERE status = 'success'");
        return (float) $query->row()->total_withdrawal;
    }

    public function get_revenue_chart_data($days = 7) {
        $days = max(1, min(90, (int) $days));
        $from = date('Y-m-d', strtotime("-{$days} days"));

        $rows = $this->db->query(
            "SELECT DATE(created_at) AS dt, COALESCE(SUM(purchase_price), 0) AS revenue
             FROM user_rentals
             WHERE created_at >= ?
             GROUP BY DATE(created_at)
             ORDER BY dt ASC",
            [$from]
        )->result();

        $labels = [];
        $data   = [];
        foreach ($rows as $row) {
            $labels[] = date('d M', strtotime($row->dt));
            $data[]   = (float) $row->revenue;
        }
        return ['labels' => $labels, 'data' => $data];
    }

    // ===================================================================
    //  PHASE 9B: ANALYTICS — GLOBAL METRICS
    // ===================================================================

    public function get_global_analytics() {
        $agents = $this->db->query(
            "SELECT COUNT(*) AS total_agents FROM users WHERE is_banned = 0"
        )->row();

        $commissions = $this->db->query(
            "SELECT COALESCE(SUM(amount), 0) AS total_commissions
             FROM wallet_ledger WHERE type = 'debit'"
        )->row();

        $active_rentals = $this->db->query(
            "SELECT COUNT(*) AS active_rentals FROM user_rentals WHERE status = 'active'"
        )->row();

        $total_users = $this->db->count_all('users');

        return [
            'total_agents'     => (int) ($agents->total_agents ?? 0),
            'total_commissions'=> (float) ($commissions->total_commissions ?? 0),
            'active_rentals'   => (int) ($active_rentals->active_rentals ?? 0),
            'total_users'      => (int) $total_users,
        ];
    }

    // ===================================================================
    //  PHASE 9B: LEADERBOARD — TOP AFFILIATES (RECURSIVE CTE)
    // ===================================================================

    public function get_leaderboard($limit = 25) {
        $sql = "
            WITH RECURSIVE downline_tree AS (
                SELECT u.parent_id AS affiliate_id, u.id AS downline_id, 1 AS lvl
                FROM users u
                WHERE u.parent_id IS NOT NULL
                UNION ALL
                SELECT dt.affiliate_id, u2.id, dt.lvl + 1
                FROM downline_tree dt
                JOIN users u2 ON u2.parent_id = dt.downline_id
                WHERE dt.lvl < 2
            )
            SELECT
                aff.id,
                aff.phone,
                aff.username,
                aff.invite_code,
                COUNT(DISTINCT dt.downline_id) AS downline_count,
                COALESCE(SUM(CASE WHEN ur.status = 'active' THEN ur.purchase_price ELSE 0 END), 0) AS total_sales,
                COUNT(DISTINCT CASE WHEN ur.status = 'active' THEN ur.id END) AS active_rental_count
            FROM users aff
            INNER JOIN downline_tree dt ON dt.affiliate_id = aff.id
            LEFT JOIN user_rentals ur ON ur.user_id = dt.downline_id
            GROUP BY aff.id, aff.phone, aff.username, aff.invite_code
            ORDER BY downline_count DESC, total_sales DESC
            LIMIT ?";

        return $this->db->query($sql, [(int) $limit])->result();
    }

    // ===================================================================
    //  PHASE 9B: FINANCIAL X-RAY (SINGLE USER)
    // ===================================================================

    public function get_user_xray($user_id) {
        $user = $this->db->select('id, phone, username, invite_code, parent_id')
            ->where('id', $user_id)
            ->get('users')
            ->row();

        if (!$user) return null;

        $credit = (float) $this->db
            ->select_sum('amount')
            ->where('user_id', $user_id)
            ->where('type', 'credit')
            ->get('wallet_ledger')
            ->row()->amount ?? 0;

        $debit = (float) $this->db
            ->select_sum('amount')
            ->where('user_id', $user_id)
            ->where('type', 'debit')
            ->get('wallet_ledger')
            ->row()->amount ?? 0;

        $rentals = $this->db->query(
            "SELECT COUNT(*) AS active_count,
                    COALESCE(SUM(purchase_price), 0) AS total_invested
             FROM user_rentals WHERE user_id = ? AND status = 'active'",
            [$user_id]
        )->row();

        $downline_count = $this->db
            ->where('parent_id', $user_id)
            ->count_all_results('users');

        $total_wd = (float) $this->db
            ->select_sum('amount')
            ->where('user_id', $user_id)
            ->where('status', 'success')
            ->get('withdrawals')
            ->row()->amount ?? 0;

        $balance = $credit - $debit;

        return [
            'user'              => $user,
            'total_credit'      => $credit,
            'total_debit'       => $debit,
            'balance'           => $balance,
            'total_withdrawals' => $total_wd,
            'active_rentals'    => (int) ($rentals->active_count ?? 0),
            'total_invested'    => (float) ($rentals->total_invested ?? 0),
            'downline_count'    => (int) $downline_count,
        ];
    }

    // ===================================================================
    //  PHASE 9C: CSV EXPORT — DATA QUERY METHODS
    // ===================================================================

    public function get_all_ledger() {
        return $this->db->select('id, user_id, amount, type, description, created_at')
            ->from('wallet_ledger')
            ->order_by('created_at', 'DESC')
            ->get()
            ->result_array();
    }

    public function get_active_rentals() {
        return $this->db->select('id, user_id, product_name, purchase_price, daily_roi, days_processed, total_days, status, created_at')
            ->from('user_rentals')
            ->where('status', 'active')
            ->order_by('created_at', 'DESC')
            ->get()
            ->result_array();
    }

    public function get_all_withdrawals() {
        return $this->db->select('id, user_id, amount, bank_name, account_number, status, created_at')
            ->from('withdrawals')
            ->order_by('created_at', 'DESC')
            ->get()
            ->result_array();
    }

}