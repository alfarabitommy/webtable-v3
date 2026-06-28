<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_model extends CI_Model {

    /**
     * Create a new user with auto-generated invite code
     * @param array $data Keys: phone, password, parent_id (optional)
     * @return int|bool User ID on success, false on failure
     */
    public function create_user($data) {
        $data['invite_code'] = $this->_generate_invite_code();

        if ($this->db->insert('users', $data)) {
            return $this->db->insert_id();
        }

        return false;
    }

    /**
     * Retrieve user by ID
     * @param int $user_id
     * @return object|null
     */
    public function get_user_by_id($user_id) {
        return $this->db->get_where('users', ['id' => $user_id])->row();
    }

    /**
     * Retrieve user by phone number
     * @param string $phone
     * @return object|null
     */
    public function get_user_by_phone($phone) {
        return $this->db->get_where('users', ['phone' => $phone])->row();
    }

    /**
     * Get downlines by level
     * @param int $user_id The parent user ID
     * @param int $level 1 for Direct (B), 2 for Indirect (C)
     * @return array
     */
    public function get_downlines($user_id, $level) {
        if ($level == 1) {
            return $this->db->get_where('users', ['parent_id' => $user_id])->result_array();
        } elseif ($level == 2) {
            $this->db->select('u.*');
            $this->db->from('users u');
            $this->db->join('users p', 'u.parent_id = p.id');
            $this->db->where('p.parent_id', $user_id);
            return $this->db->get()->result_array();
        }
        return [];
    }

    /**
     * Update user profile fields
     * @param int $user_id
     * @param array $data Associative array of columns to update
     * @return bool
     */
    public function update_user($user_id, $data) {
        return $this->db->update('users', $data, ['id' => $user_id]);
    }

    /**
     * Get all L1+L2 downlines with active-rental status in one query.
     */
    public function get_team_with_active_status($user_id) {
        $sql = "SELECT u.id, u.username, u.phone, u.invite_code, u.level_id, u.created_at, 1 AS `level`,
                (SELECT COUNT(*) FROM user_rentals ur WHERE ur.user_id = u.id AND ur.status = 'active') AS is_active
                FROM users u WHERE u.parent_id = ?
                UNION ALL
                SELECT u.id, u.username, u.phone, u.invite_code, u.level_id, u.created_at, 2 AS `level`,
                (SELECT COUNT(*) FROM user_rentals ur WHERE ur.user_id = u.id AND ur.status = 'active') AS is_active
                FROM users u INNER JOIN users p ON u.parent_id = p.id
                WHERE p.parent_id = ? AND u.id != ?";
        return $this->db->query($sql, [$user_id, $user_id, $user_id])->result();
    }

    // ============================================================
    // PHASE 9B — AFFILIATE WAGE CLAIM METHODS
    // ============================================================

    /**
     * Wage tier thresholds (active downline count breakpoints)
     */
    const WAGE_TIERS = [
        6 => ['threshold' => 190, 'amount' => 9000000,  'label' => 'Rp 9.000.000'],
        5 => ['threshold' => 130, 'amount' => 5000000,  'label' => 'Rp 5.000.000'],
        4 => ['threshold' => 70,  'amount' => 2500000,  'label' => 'Rp 2.500.000'],
        3 => ['threshold' => 30,  'amount' => 1000000,  'label' => 'Rp 1.000.000'],
        2 => ['threshold' => 9,   'amount' => 200000,   'label' => 'Rp 200.000'],
    ];

    /**
     * Count active downlines (B-tier only = direct referrals with active rental)
     * @param int $user_id
     * @return int
     */
    public function count_active_b_downlines($user_id) {
        $sql = "SELECT COUNT(DISTINCT u.id) AS cnt
                FROM users u
                JOIN user_rentals ur ON ur.user_id = u.id
                WHERE u.parent_id = ?
                  AND ur.status = 'active'";
        $row = $this->db->query($sql, [$user_id])->row();
        return (int) ($row->cnt ?? 0);
    }

    /**
     * Sum total_price from user_rentals for direct (B-tier) active downlines
     * @param int $user_id
     * @return int
     */
    public function sum_sales_b_downlines($user_id) {
        $sql = "SELECT COALESCE(SUM(gp.price), 0) AS total
                FROM user_rentals ur
                JOIN users u ON u.id = ur.user_id
                JOIN gpu_products gp ON gp.id = ur.product_id
                WHERE u.parent_id = ?
                  AND ur.status = 'active'";
        $row = $this->db->query($sql, [$user_id])->row();
        return (int) ($row->total ?? 0);
    }

    /**
     * Count ALL active downlines in entire referral tree (B+C+D+E+F)
     * Uses recursive CTE via MySQL 8.4
     * @param int $user_id
     * @return int
     */
    public function count_all_active_downlines($user_id) {
        $sql = "WITH RECURSIVE tree AS (
                    SELECT id FROM users WHERE parent_id = ?
                    UNION ALL
                    SELECT u.id FROM users u
                    INNER JOIN tree t ON u.parent_id = t.id
                )
                SELECT COUNT(DISTINCT t.id) AS cnt
                FROM tree t
                JOIN user_rentals ur ON ur.user_id = t.id
                WHERE ur.status = 'active'";
        $row = $this->db->query($sql, [$user_id])->row();
        return (int) ($row->cnt ?? 0);
    }

    /**
     * Determine wage level from active downline count
     * @param int $active_count
     * @return array|null ['level' => int, 'amount' => int, 'label' => string] or null
     */
    public function determine_wage_level($active_count) {
        foreach (self::WAGE_TIERS as $level => $tier) {
            if ($active_count >= $tier['threshold']) {
                return ['level' => $level, 'amount' => $tier['amount'], 'label' => $tier['label']];
            }
        }
        return null;
    }

    /**
     * Check weekly wage cooldown
     * @param int $user_id
     * @return array ['claimable' => bool, 'days_remaining' => int|null, 'next_claim_date' => string|null]
     */
    public function check_wage_cooldown($user_id) {
        $user = $this->get_user_by_id($user_id);

        if (empty($user->last_wage_claimed_at)) {
            return ['claimable' => true, 'days_remaining' => null, 'next_claim_date' => null];
        }

        $last = new DateTime($user->last_wage_claimed_at);
        $now  = new DateTime();
        $diff = (int) $now->diff($last)->days;

        if ($diff >= 7) {
            return ['claimable' => true, 'days_remaining' => null, 'next_claim_date' => null];
        }

        $days_remaining = 7 - $diff;
        $next = (clone $last)->modify("+{$days_remaining} days");
        return [
            'claimable'        => false,
            'days_remaining'   => $days_remaining,
            'next_claim_date'  => $next->format('d M Y'),
        ];
    }

    /**
     * Get full claim data for the Team page
     * @param int $user_id
     * @return array
     */
    public function get_claim_data($user_id) {
        $user = $this->get_user_by_id($user_id);

        // L1 evaluation
        $b_active    = $this->count_active_b_downlines($user_id);
        $b_sales     = $this->sum_sales_b_downlines($user_id);
        $l1_claimed  = (int) $user->is_level_1_claimed === 1;
        $l1_eligible = ! $l1_claimed && $b_active >= 3 && $b_sales >= 330000;

        // L2-6 evaluation
        $total_active = $this->count_all_active_downlines($user_id);
        $wage_level   = $this->determine_wage_level($total_active);
        $cooldown     = $this->check_wage_cooldown($user_id);

        $weekly_eligible = ($wage_level !== null) && $cooldown['claimable'];

        return [
            // L1
            'level1_eligible'    => $l1_eligible,
            'level1_claimed'     => $l1_claimed,
            'active_b_count'     => $b_active,
            'sales_b'            => $b_sales,
            // L2-6
            'weekly_eligible'    => $weekly_eligible,
            'current_level'      => $wage_level ? $wage_level['level'] : null,
            'current_wage'       => $wage_level ? $wage_level['amount'] : null,
            'current_wage_label' => $wage_level ? $wage_level['label'] : null,
            'cooldown_active'    => ! $cooldown['claimable'],
            'days_remaining'     => $cooldown['days_remaining'],
            'next_claim_date'    => $cooldown['next_claim_date'],
            'total_active'       => $total_active,
            // balance
            'balance'            => $user->balance,
        ];
    }

    /**
     * Claim Level 1 bonus (one-time Rp 80.000)
     * ACID transaction with double-claim guard
     * @param int $user_id
     * @return array ['success' => bool, 'message' => string]
     */
    public function claim_level1($user_id) {
        $this->db->trans_start();

        // Re-verify inside TX
        $eligible = $this->count_active_b_downlines($user_id) >= 3
                  && $this->sum_sales_b_downlines($user_id) >= 330000;

        if ( ! $eligible) {
            $this->db->trans_rollback();
            return ['success' => false, 'message' => 'Kualifikasi tidak terpenuhi.'];
        }

        // Atomic flag set
        $this->db->where(['id' => $user_id, 'is_level_1_claimed' => 0]);
        $this->db->update('users', ['is_level_1_claimed' => 1]);

        if ($this->db->affected_rows() === 0) {
            $this->db->trans_rollback();
            return ['success' => false, 'message' => 'Bonus sudah diklaim sebelumnya.'];
        }

        // Credit wallet
        $tx_id = 'L1-' . $user_id . '-' . date('YmdHis');
        $this->db->insert('wallet_ledger', [
            'user_id'        => $user_id,
            'transaction_id' => $tx_id,
            'type'           => 'credit',
            'amount'         => 80000,
            'description'    => 'Bonus Level 1 — 3 Agen Aktif + Omset ≥330rb',
        ]);

        // Update balance
        $this->db->set('balance', 'balance + 80000', false);
        $this->db->where('id', $user_id);
        $this->db->update('users');

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return ['success' => false, 'message' => 'Gagal memproses klaim. Coba lagi.'];
        }

        return ['success' => true, 'message' => 'Bonus Level 1 Rp 80.000 berhasil diklaim!'];
    }

    /**
     * Claim weekly wage (L2-6)
     * ACID transaction with cooldown guard
     * @param int $user_id
     * @return array ['success' => bool, 'message' => string, 'amount' => int|null, 'level' => int|null]
     */
    public function claim_wage($user_id) {
        $this->db->trans_start();

        // Re-verify inside TX
        $total_active = $this->count_all_active_downlines($user_id);
        $wage_level   = $this->determine_wage_level($total_active);

        if ($wage_level === null) {
            $this->db->trans_rollback();
            return ['success' => false, 'message' => 'Minimal 9 downline aktif untuk klaim gaji mingguan.'];
        }

        // Re-check cooldown inside TX
        $cooldown = $this->check_wage_cooldown($user_id);
        if ( ! $cooldown['claimable']) {
            $this->db->trans_rollback();
            return ['success' => false, 'message' => "Cooldown aktif. Gaji berikutnya: {$cooldown['days_remaining']} hari lagi."];
        }

        // Set cooldown timestamp
        $this->db->where('id', $user_id);
        $this->db->update('users', ['last_wage_claimed_at' => date('Y-m-d H:i:s')]);

        // Credit wallet
        $tx_id = 'W' . $wage_level['level'] . '-' . $user_id . '-' . date('YmdHis');
        $this->db->insert('wallet_ledger', [
            'user_id'        => $user_id,
            'transaction_id' => $tx_id,
            'type'           => 'credit',
            'amount'         => $wage_level['amount'],
            'description'    => "Gaji Mingguan Level {$wage_level['level']} — {$total_active} downline aktif",
        ]);

        // Update balance
        $this->db->set('balance', 'balance + ' . $wage_level['amount'], false);
        $this->db->where('id', $user_id);
        $this->db->update('users');

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return ['success' => false, 'message' => 'Gagal memproses klaim. Coba lagi.'];
        }

        return [
            'success' => true,
            'message' => "Gaji Mingguan Level {$wage_level['level']} ({$wage_level['label']}) berhasil diklaim!",
            'amount'  => $wage_level['amount'],
            'level'   => $wage_level['level'],
        ];
    }

    // ============================================================
    // END PHASE 9B
    // ============================================================

    /**
     * Generate a unique 6-character alphanumeric invite code
     * @return string
     */
    private function _generate_invite_code() {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        do {
            $code  = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $exists = $this->db->get_where('users', ['invite_code' => $code])->num_rows();
        } while ($exists > 0);

        return $code;
    }
}
