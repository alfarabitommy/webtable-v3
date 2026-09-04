<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_model extends CI_Model {

    public function __construct() {
        parent::__construct();
        // C4 (plan/54): claim_level1/claim_wage/get_claim_data memakai
        // ledger ingestion helper + saldo segar — pastikan Wallet_model
        // ter-load walau dipanggil di luar MY_Controller (jalur CLI/cron).
        $this->load->model('Wallet_model');
    }

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
     * M3 (plan/60): is_active = kontrak BENAR-BENAR aktif (expired_at > now
     * WIB via bound param) — downline yang tak pernah login tidak bisa
     * mempertahankan status aktif lewat kontrak kedaluwarsa.
     */
    public function get_team_with_active_status($user_id) {
        $now = date('Y-m-d H:i:s');
        $sql = "SELECT u.id, u.username, u.phone, u.invite_code, u.level_id, u.created_at, 1 AS `level`,
                (SELECT COUNT(*) FROM user_rentals ur WHERE ur.user_id = u.id AND ur.status = 'active' AND ur.expired_at > ?) AS is_active
                FROM users u WHERE u.parent_id = ?
                UNION ALL
                SELECT u.id, u.username, u.phone, u.invite_code, u.level_id, u.created_at, 2 AS `level`,
                (SELECT COUNT(*) FROM user_rentals ur WHERE ur.user_id = u.id AND ur.status = 'active' AND ur.expired_at > ?) AS is_active
                FROM users u INNER JOIN users p ON u.parent_id = p.id
                WHERE p.parent_id = ? AND u.id != ?";
        return $this->db->query($sql, [$now, $user_id, $now, $user_id, $user_id])->result();
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
     * M3 (plan/60): filter defensif expired_at > now — kontrak kedaluwarsa
     * tidak pernah dihitung walau status row belum di-flip (user tak login).
     * @param int $user_id
     * @return int
     */
    public function count_active_b_downlines($user_id) {
        $now = date('Y-m-d H:i:s');
        $sql = "SELECT COUNT(DISTINCT u.id) AS cnt
                FROM users u
                JOIN user_rentals ur ON ur.user_id = u.id
                WHERE u.parent_id = ?
                  AND ur.status = 'active'
                  AND ur.expired_at > ?";
        $row = $this->db->query($sql, [$user_id, $now])->row();
        return (int) ($row->cnt ?? 0);
    }

    /**
     * Sum total_price from user_rentals for direct (B-tier) active downlines
     * M3 (plan/60): filter defensif expired_at > now (sama dengan count).
     * @param int $user_id
     * @return int
     */
    public function sum_sales_b_downlines($user_id) {
        $now = date('Y-m-d H:i:s');
        $sql = "SELECT COALESCE(SUM(gp.price), 0) AS total
                FROM user_rentals ur
                JOIN users u ON u.id = ur.user_id
                JOIN gpu_products gp ON gp.id = ur.product_id
                WHERE u.parent_id = ?
                  AND ur.status = 'active'
                  AND ur.expired_at > ?";
        $row = $this->db->query($sql, [$user_id, $now])->row();
        return (int) ($row->total ?? 0);
    }

    /**
     * Count ALL active downlines in entire referral tree (B+C+D+E+F)
     * Uses recursive CTE via MySQL 8.4
     * M3 (plan/60): filter defensif expired_at > now pada join user_rentals.
     * @param int $user_id
     * @return int
     */
    public function count_all_active_downlines($user_id) {
        $now = date('Y-m-d H:i:s');
        $sql = "WITH RECURSIVE tree AS (
                    SELECT id FROM users WHERE parent_id = ?
                    UNION ALL
                    SELECT u.id FROM users u
                    INNER JOIN tree t ON u.parent_id = t.id
                )
                SELECT COUNT(DISTINCT t.id) AS cnt
                FROM tree t
                JOIN user_rentals ur ON ur.user_id = t.id
                WHERE ur.status = 'active'
                  AND ur.expired_at > ?";
        $row = $this->db->query($sql, [$user_id, $now])->row();
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
            // balance — SALDO SEGAR dari wallet_ledger, bukan users.balance
            // yang basi (C4 plan/54; get_balance = sumber otoritatif).
            'balance'            => $this->Wallet_model->get_balance($user_id),
        ];
    }

    /**
     * Claim Level 1 bonus (one-time Rp 80.000)
     * ACID transaction with double-claim guard
     * @param int $user_id
     * @return array ['success' => bool, 'message' => string]
     */
    public function claim_level1($user_id) {
        // C4 (plan/54) W8: kredit via Wallet_model::credit() (ledger + cache
        // atomik). TX eksplisit + lock anchor users — pola C5/C6 (plan/48/50).
        $this->db->trans_begin();

        try {
            // 1. Kunci anchor users (serialisasi klaim per-user).
            $user = $this->db->query(
                "SELECT id, is_level_1_claimed FROM users WHERE id = ? FOR UPDATE",
                [$user_id]
            )->row();

            if (!$user) {
                $this->db->trans_rollback();
                return ['success' => false, 'message' => 'Kualifikasi tidak terpenuhi.'];
            }

            // 2. Re-verify di dalam TX terkunci (TOCTOU close).
            $eligible = $this->count_active_b_downlines($user_id) >= 3
                      && $this->sum_sales_b_downlines($user_id) >= 330000;

            if (!$eligible) {
                $this->db->trans_rollback();
                return ['success' => false, 'message' => 'Kualifikasi tidak terpenuhi.'];
            }

            if ((int) $user->is_level_1_claimed === 1) {
                $this->db->trans_rollback();
                return ['success' => false, 'message' => 'Bonus sudah diklaim sebelumnya.'];
            }

            // 3. Flag atomik kondisional — gate anti double-claim.
            $this->db->where(['id' => $user_id, 'is_level_1_claimed' => 0]);
            $this->db->update('users', ['is_level_1_claimed' => 1]);

            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                return ['success' => false, 'message' => 'Bonus sudah diklaim sebelumnya.'];
            }

            // 4. Kredit ledger + cache atomik (helper C4; TANPA update manual
            //    users.balance — sudah ditangani helper).
            $tx_id = 'L1-' . $user_id . '-' . date('YmdHis');
            $credited = $this->Wallet_model->credit(
                (int) $user_id,
                80000,
                $tx_id,
                'Bonus Level 1 — 3 Agen Aktif + Omset ≥330rb'
            );

            if (!$credited) {
                $this->db->trans_rollback();
                return ['success' => false, 'message' => 'Gagal memproses klaim. Coba lagi.'];
            }

            $this->db->trans_commit();

            return ['success' => true, 'message' => 'Bonus Level 1 Rp 80.000 berhasil diklaim!'];

        } catch (Throwable $e) {
            if ($this->db->trans_status() !== false) {
                $this->db->trans_rollback();
            }
            log_message('error', 'User_model::claim_level1 user ' . $user_id . ' — ' . $e->getMessage());
            return ['success' => false, 'message' => 'Gagal memproses klaim. Coba lagi.'];
        }
    }

    /**
     * Claim weekly wage (L2-6) — C6 race-hardened (plan/50).
     *
     * Serialisasi per-user via kunci baris anchor (SELECT ... FOR UPDATE)
     * sebagai statement PERTAMA dari transaksi eksplisit, verifikasi
     * interval 7 hari pada baris TERKUNCI (tutup TOCTOU), stamp timestamp
     * via UPDATE kondisional yang di-gate affected_rows() === 1, dan
     * kredit wallet_ledger dengan transaction_id deterministik:
     * WAGE-{user_id}-Y{year}W{iso_week} (≤ 1 kredit per user per siklus).
     *
     * Semua timestamp di-generate PHP (Asia/Jakarta) dan dikirim sebagai
     * bound params — MySQL NOW() TIDAK dipakai (konvensi Phase 10B / plan/50
     * §3.1). NOL SQL di luar model (AGENTS.md).
     *
     * @param int $user_id
     * @return array {success, code, message, amount, level, cycle,
     *                transaction_id, next_claim_date}
     */
    public function claim_wage($user_id) {
        // Defensif: konsisten Asia/Jakarta walau dipanggil dari CLI/cron
        // (gaya Rate_limit_model, fix Phase 10B).
        if (date_default_timezone_get() !== 'Asia/Jakarta') {
            date_default_timezone_set('Asia/Jakarta');
        }

        $this->db->trans_begin();

        try {
            // ── 1. Anchor row lock (current read) — serialisasi writer gaji. ──
            $user = $this->db->query(
                "SELECT id, last_wage_claimed_at, is_banned
                   FROM users
                  WHERE id = ?
                  FOR UPDATE",
                [$user_id]
            )->row();

            if ( ! $user) {
                $this->db->trans_rollback();
                return $this->_wage_result('user_unavailable', 'Sistem: Akun tidak ditemukan.');
            }

            if ((int) $user->is_banned === 1) {
                $this->db->trans_rollback();
                return $this->_wage_result('user_unavailable', 'Sistem: Akun Anda telah dinonaktifkan.');
            }

            // ── 2. Interval 7 hari pada baris TERKUNCI (TOCTOU close). ──
            $now_ts = time();
            $now    = date('Y-m-d H:i:s', $now_ts);
            $cutoff = date('Y-m-d H:i:s', $now_ts - 7 * 86400); // klaimable saat stamp <= cutoff
            $week   = date('o') . 'W' . date('W');               // ISO year + week (Y2026W36)

            // Klaimable ⇔ last_wage_claimed_at IS NULL ATAU stamp <= cutoff
            // (interval 7 hari penuh sudah lewat). String 'Y-m-d H:i:s'
            // membandingkan leksikografis == kronologis.
            if ( ! empty($user->last_wage_claimed_at) && $user->last_wage_claimed_at > $cutoff) {
                $last_ts = strtotime($user->last_wage_claimed_at);

                // Klaim terakhir masih pada siklus (ISO week) yang sama →
                // replay / race-loser (Test 3 / burst concurrency).
                if ($last_ts !== false && date('o', $last_ts) . 'W' . date('W', $last_ts) === $week) {
                    $this->db->trans_rollback();
                    return $this->_wage_result('already_claimed', 'Gaji mingguan sudah diklaim untuk minggu ini.');
                }

                // Klaim pekan lalu, interval 7 hari belum lewat → countdown.
                $next_ts        = $last_ts + 7 * 86400;
                $days_remaining = (int) max(1, ceil(($next_ts - $now_ts) / 86400));
                $next_date      = date('d M Y', $next_ts);
                $this->db->trans_rollback();
                return $this->_wage_result(
                    'cycle_not_ready',
                    "Cooldown aktif. Gaji berikutnya: {$days_remaining} hari lagi ({$next_date}).",
                    ['next_claim_date' => $next_date]
                );
            }

            // ── 3. Kualifikasi (snapshot stabil, dibaca pasca-lock). ──
            $total_active = $this->count_all_active_downlines($user_id);
            $wage_level   = $this->determine_wage_level($total_active);

            if ($wage_level === null) {
                $this->db->trans_rollback();
                return $this->_wage_result('not_qualified', 'Minimal 9 downline aktif untuk klaim gaji mingguan.');
            }

            // ── 4. Stamp atomik kondisional — gate terakhir anti-race. ──
            $this->db->query(
                "UPDATE users
                    SET last_wage_claimed_at = ?
                  WHERE id = ?
                    AND (last_wage_claimed_at IS NULL OR last_wage_claimed_at <= ?)",
                [$now, $user_id, $cutoff]
            );

            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                log_message('error', 'C6 guard: klaim gaji user ' . $user_id
                    . ' — affected_rows=' . $this->db->affected_rows()
                    . ' (race tak terduga walau baris sudah dikunci)');
                return $this->_wage_result('already_claimed', 'Gaji mingguan sudah diklaim untuk minggu ini.');
            }

            // ── 5. Kredit via ledger ingestion helper (ledger + cache atomik
            //       C4/W9; transaction_id deterministik per siklus). TANPA
            //       update manual users.balance — helper yang menanganinya. ──
            $tx_id = 'WAGE-' . (int) $user_id . '-Y' . $week;
            $credited = $this->Wallet_model->credit(
                (int) $user_id,
                (int) $wage_level['amount'],
                $tx_id,
                'Gaji Mingguan Level ' . $wage_level['level']
                    . ' — ' . $total_active . ' downline aktif (siklus Y' . $week . ')'
            );

            if (!$credited) {
                $this->db->trans_rollback();
                log_message('error', 'C4 guard: kredit gaji gagal user ' . $user_id . ' (siklus Y' . $week . ')');
                return $this->_wage_result('error', 'Sistem: Gagal memproses klaim. Coba lagi.');
            }

            $this->db->trans_commit();

            return $this->_wage_result(
                'claimed',
                'Gaji Mingguan Level ' . $wage_level['level'] . ' (' . $wage_level['label'] . ') berhasil diklaim!',
                [
                    'success'        => true,
                    'amount'         => (int) $wage_level['amount'],
                    'level'          => (int) $wage_level['level'],
                    'cycle'          => 'Y' . $week,
                    'transaction_id' => $tx_id,
                ]
            );

        } catch (Throwable $e) {
            if ($this->db->trans_status() !== false) {
                $this->db->trans_rollback();
            }
            log_message('error', 'C6 claim_wage user ' . $user_id . ' — ' . $e->getMessage());
            return $this->_wage_result('error', 'Sistem: Gagal memproses klaim. Coba lagi.');
        }
    }

    /**
     * Normalisasi hasil claim_wage menjadi payload kontrak JSON.
     * Hanya code 'claimed' yang bermakna success=true.
     *
     * @param string $code   claimed|already_claimed|cycle_not_ready|not_qualified|user_unavailable|error
     * @param string $message
     * @param array  $extra  Override key payload (success, amount, level, ...)
     * @return array
     */
    private function _wage_result($code, $message, array $extra = []) {
        return array_merge([
            'success'         => ($code === 'claimed'),
            'code'            => $code,
            'message'         => $message,
            'amount'          => null,
            'level'           => null,
            'cycle'           => null,
            'transaction_id'  => null,
            'next_claim_date' => null,
        ], $extra);
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
