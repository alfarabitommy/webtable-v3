<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin_model extends CI_Model {

    public function __construct() {
        parent::__construct();
        $this->load->database();

        // M1 (plan/56 §3) + M2 (plan/58 §3 Phase 2): pin MySQL session ke
        // Asia/Jakarta (WIB) — TANPA guard `conn_id` (CI3 lazy-connect membuat
        // guard lama tidak pernah jalan pada koneksi baru; lihat Wallet_model).
        // query() memaksa koneksi pertama + pin '+07:00' sebelum statement
        // lain. Idempotent per request pada koneksi CI tunggal.
        $this->db->query("SET time_zone = '+07:00'");
    }

    // ===== AUDIT WRITE (Phase 10A) =====
    // Plain INSERT via Audit_model — called INSIDE an open transaction
    // (trans_start/trans_complete) so rollback removes the audit row too.

    private function _write_audit($audit = null) {
        if (!is_array($audit) || empty($audit)) {
            return;
        }
        $this->load->model('Audit_model');
        $this->Audit_model->log_admin_action(
            isset($audit['admin_id'])  ? $audit['admin_id']  : null,
            isset($audit['user_id'])   ? $audit['user_id']   : null,
            isset($audit['action'])    ? $audit['action']    : '',
            isset($audit['details'])   ? $audit['details']   : null,
            isset($audit['ip_address'])? $audit['ip_address']: ''
        );
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
        // C4 (plan/54): balance kolom = agregat wallet_ledger (Σcredit − Σdebit),
        // BUKAN users.balance (cache basi) — user list selalu konsisten dengan
        // user_detail (get_user_balance). Raw SQL: subquery join tidak aman
        // dipakai query builder (escaping identifier gabungan).
        $sql = "SELECT u.id, u.phone, u.username, u.invite_code, u.role, u.is_banned,
                       COALESCE(l.balance, 0) AS balance, u.created_at,
                       p.invite_code AS parent_invite_code
                FROM users u
                LEFT JOIN users p ON p.id = u.parent_id
                LEFT JOIN (
                    SELECT user_id,
                           COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) AS balance
                    FROM wallet_ledger
                    GROUP BY user_id
                ) l ON l.user_id = u.id";

        $params = [];

        if ($search !== '') {
            $sql    .= " WHERE u.phone LIKE ? OR u.username LIKE ? OR u.invite_code LIKE ?";
            $like    = '%' . $search . '%';
            $params  = [$like, $like, $like];
        }

        $sql     .= " ORDER BY u.created_at DESC LIMIT ? OFFSET ?";
        $params[] = (int) $limit;
        $params[] = max(0, (int) $offset);

        return $this->db->query($sql, $params)->result();
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
        $credit = (int) $this->db
            ->select_sum('amount')
            ->where('user_id', $id)
            ->where('type', 'credit')
            ->get('wallet_ledger')
            ->row()->amount ?? 0;

        $debit = (int) $this->db
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

    // ===================================================================
    //  ADMIN MONEY ACTIONS — ACID (C4 plan/54 + M4 gate)
    // ===================================================================
    // Controller memanggil metode ini (bukan $this->db langsung) sehingga
    // SELURUH mutasi uang terenkapsulasi di model: anchor lock users →
    // transisi status kondisional (WHERE status='pending', anti double-submit
    // M4) → Wallet_model::credit()/debit() (ledger + cache atomik) → audit
    // di dalam TX yang sama. Mengembalikan baris bisnis agar controller bisa
    // menampilkan flashdata/notifikasi TANPA query tambahan.

    /**
     * W1 — Approve deposit: pending→success + kredit ledger & cache.
     *
     * @param int   $deposit_id
     * @param array|null $audit  Konteks audit dari _audit_ctx(); user_id & details
     *                           diisi model dari baris deposit (parity lama).
     * @return array{success:bool, deposit:object|null, message:string}
     */
    public function approve_deposit($deposit_id, $audit = null) {
        $deposit = $this->db->get_where('deposits', ['id' => (int) $deposit_id])->row();

        if (!$deposit || $deposit->status !== 'pending') {
            return ['success' => false, 'deposit' => null, 'message' => 'Deposit tidak valid atau sudah diproses.'];
        }

        $this->load->model('Wallet_model');

        $this->db->trans_begin();

        try {
            // 1. Anchor lock users (W1 credit path — serialisasi sebelum mutasi).
            if ($this->Wallet_model->lock_and_get_balance((int) $deposit->user_id) === false) {
                $this->db->trans_rollback();
                return ['success' => false, 'deposit' => $deposit, 'message' => 'Gagal memproses deposit.'];
            }

            // 2. Transisi atomik kondisional (replay/double-click → 0 baris).
            $this->db->where('id', (int) $deposit_id);
            $this->db->where('status', 'pending');
            $this->db->update('deposits', ['status' => 'success']);

            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                return ['success' => false, 'deposit' => $deposit, 'message' => 'Deposit tidak valid atau sudah diproses.'];
            }

            // 3. Kredit ledger + cache atomik (helper C4).
            $credited = $this->Wallet_model->credit(
                (int) $deposit->user_id,
                (int) $deposit->amount,
                $deposit->invoice_number,
                'Top Up via ' . $deposit->invoice_number
            );

            if (!$credited) {
                $this->db->trans_rollback();
                return ['success' => false, 'deposit' => $deposit, 'message' => 'Gagal memproses deposit.'];
            }

            // 4. Audit di dalam TX yang sama.
            if (is_array($audit)) {
                $audit['user_id'] = (int) $deposit->user_id;
                $audit['details'] = ['invoice_number' => $deposit->invoice_number, 'amount' => $deposit->amount];
                $this->_write_audit($audit);
            }

            $this->db->trans_commit();

            return ['success' => true, 'deposit' => $deposit, 'message' => ''];

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Admin_model::approve_deposit — ' . $e->getMessage());
            return ['success' => false, 'deposit' => $deposit, 'message' => 'Gagal memproses deposit.'];
        }
    }

    /**
     * Approve withdrawal: pending→success. TANPA mutasi uang (dana sudah
     * didebit saat pengajuan, W5) — hanya flip status kondisional (M4).
     *
     * @param int   $wd_id
     * @param array|null $audit
     * @return array{success:bool, withdrawal:object|null, message:string}
     */
    public function approve_withdrawal($wd_id, $audit = null) {
        $wd = $this->db->get_where('withdrawals', ['id' => (int) $wd_id])->row();

        if (!$wd || $wd->status !== 'pending') {
            return ['success' => false, 'withdrawal' => null, 'message' => 'Penarikan tidak valid atau sudah diproses.'];
        }

        $this->db->trans_begin();

        try {
            // Flip status kondisional — double-click kedua → 0 baris → false.
            $this->db->where('id', (int) $wd_id);
            $this->db->where('status', 'pending');
            $this->db->update('withdrawals', [
                'status'       => 'success',
                'processed_at' => date('Y-m-d H:i:s'),
            ]);

            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                return ['success' => false, 'withdrawal' => $wd, 'message' => 'Penarikan tidak valid atau sudah diproses.'];
            }

            if (is_array($audit)) {
                $audit['user_id'] = (int) $wd->user_id;
                $audit['details'] = ['wd_number' => $wd->wd_number, 'amount' => $wd->amount];
                $this->_write_audit($audit);
            }

            $this->db->trans_commit();

            return ['success' => true, 'withdrawal' => $wd, 'message' => ''];

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Admin_model::approve_withdrawal — ' . $e->getMessage());
            return ['success' => false, 'withdrawal' => $wd, 'message' => 'Gagal memproses penarikan.'];
        }
    }

    /**
     * W6 — Decline withdrawal: pending→failed + refund kredit ledger & cache
     * (dana yang didebit saat pengajuan dikembalikan; transaction_id = wd_number,
     * parity lama — pasangan debit WD-x + kredit WD-x diizinkan karena tidak ada
     * unique key pada transaction_id).
     *
     * @param int   $wd_id
     * @param array|null $audit
     * @param string|null $reason  Alasan penolakan (opsional, M5/N4) — disimpan
     *                             ke withdrawals.decline_reason & audit details.
     * @return array{success:bool, withdrawal:object|null, message:string}
     */
    public function decline_withdrawal($wd_id, $audit = null, $reason = null) {
        $wd = $this->db->get_where('withdrawals', ['id' => (int) $wd_id])->row();

        if (!$wd || $wd->status !== 'pending') {
            return ['success' => false, 'withdrawal' => null, 'message' => 'Penarikan tidak valid atau sudah diproses.'];
        }

        $reason = ($reason !== null && trim($reason) !== '')
            ? mb_substr(trim($reason), 0, 255)
            : null;

        $this->load->model('Wallet_model');

        $this->db->trans_begin();

        try {
            // 1. Anchor lock users (W6 credit path — serialisasi refund).
            if ($this->Wallet_model->lock_and_get_balance((int) $wd->user_id) === false) {
                $this->db->trans_rollback();
                return ['success' => false, 'withdrawal' => $wd, 'message' => 'Gagal menolak penarikan.'];
            }

            // 2. Transisi pending→failed kondisional (anti double-refund M4).
            //    decline_reason (M5/N4) ikut dipersist di transisi yang sama.
            $this->db->where('id', (int) $wd_id);
            $this->db->where('status', 'pending');
            $this->db->update('withdrawals', [
                'status'         => 'failed',
                'decline_reason' => $reason,
            ]);

            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                return ['success' => false, 'withdrawal' => $wd, 'message' => 'Penarikan tidak valid atau sudah diproses.'];
            }

            // 3. Refund: kredit ledger + cache atomik.
            $refunded = $this->Wallet_model->credit(
                (int) $wd->user_id,
                (int) $wd->amount,
                $wd->wd_number,
                'Pengembalian Dana: Penarikan Ditolak (' . $wd->wd_number . ')'
            );

            if (!$refunded) {
                $this->db->trans_rollback();
                return ['success' => false, 'withdrawal' => $wd, 'message' => 'Gagal menolak penarikan.'];
            }

            if (is_array($audit)) {
                $audit['user_id'] = (int) $wd->user_id;
                $audit['details'] = [
                    'wd_number' => $wd->wd_number,
                    'amount'    => $wd->amount,
                    'refunded'  => true,
                    'reason'    => $reason,
                ];
                $this->_write_audit($audit);
            }

            $this->db->trans_commit();

            return ['success' => true, 'withdrawal' => $wd, 'message' => ''];

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Admin_model::decline_withdrawal — ' . $e->getMessage());
            return ['success' => false, 'withdrawal' => $wd, 'message' => 'Gagal menolak penarikan.'];
        }
    }

    // --- Balance Injection (ACID) ---

    /**
     * W7 — Inject balance manual (credit/debit) via ledger ingestion helper:
     * anchor lock → Wallet_model::credit()/debit() → audit, satu TX.
     *
     * M8 (plan/74 §2.2): amount di-(int) kan di sini (boundary model) — nilai
     * 0/negatif/pecahan ditolak oleh guard Wallet_model::_post.
     *
     * @param int    $user_id
     * @param string $type   'credit' | 'debit'
     * @param int    $amount Nominal IDR bulat positif.
     * @param string $description
     * @param array|null $audit
     * @return bool
     */
    public function inject_balance($user_id, $type, $amount, $description, $audit = null) {
        if (!in_array($type, ['credit', 'debit'], true)) {
            return false;
        }

        $amount = (int) $amount;

        $transaction_id = 'ADM-' . date('YmdHis') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

        $this->load->model('Wallet_model');

        $this->db->trans_begin();

        try {
            // 1. Anchor lock users (W7 — serialisasi inject per-user).
            if ($this->Wallet_model->lock_and_get_balance((int) $user_id) === false) {
                $this->db->trans_rollback();
                return false;
            }

            // 2. Kredit/debit via helper (ledger + cache atomik).
            $ok = ($type === 'credit')
                ? $this->Wallet_model->credit((int) $user_id, (int) $amount, $transaction_id, $description)
                : $this->Wallet_model->debit((int) $user_id, (int) $amount, $transaction_id, $description);

            if (!$ok) {
                $this->db->trans_rollback();
                return false;
            }

            // 3. Audit di dalam TX yang sama (M5/A1: + description & balance_after).
            if (is_array($audit)) {
                $audit['user_id'] = (int) $user_id;
                $audit['details'] = is_array($audit['details'] ?? null) ? $audit['details'] : [];
                $audit['details']['type']         = $type;
                $audit['details']['amount']       = (int) $amount;
                $audit['details']['description']  = $description;
                $audit['details']['balance_after'] = $this->Wallet_model->get_balance((int) $user_id);
                $this->_write_audit($audit);
            }

            $this->db->trans_commit();

            return true;

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Admin_model::inject_balance — ' . $e->getMessage());
            return false;
        }
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

    public function inject_rental($user_id, $product_id, $audit = null) {
        $product = $this->db->where('id', $product_id)->get('gpu_products')->row();
        if (!$product) return false;

        $this->db->trans_start();
        $this->db->insert('user_rentals', [
            'user_id'         => $user_id,
            'product_id'      => $product_id,
            // M8: simpan snapshot harga/ROI sebagai integer IDR bulat.
            'purchase_price'  => (int) $product->price,
            'daily_roi'       => (int) $product->daily_rate,
            'days_processed'  => 0,
            'total_days'      => $product->duration_days,
            'status'          => 'active',
            'last_claimed_at' => date('Y-m-d H:i:s'),
            'expired_at'      => date('Y-m-d H:i:s', strtotime('+' . $product->duration_days . ' days')),
        ]);
        $this->_write_audit($audit);
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
        $cash_in = (int) $this->db
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
        $balances = (int) ($row_bal ? $row_bal->total_balances : 0);

        // Pending ROI — future obligation from active rentals
        // Use raw query to avoid CI3 aliasing issues with computed columns
        // M3 (plan/60): hanya kontrak yang BELUM expired (expired_at > now)
        // yang menjadi kewajiban masa depan — cegah phantom obligation.
        $now = date('Y-m-d H:i:s');
        $row = $this->db->query(
            "SELECT COALESCE(SUM((total_days - days_processed) * daily_roi), 0) AS pending_roi
             FROM user_rentals WHERE status = 'active' AND expired_at > ?",
            [$now]
        )->row();
        $pending_roi = (int) ($row ? $row->pending_roi : 0);

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

    // M7 (plan/70): single-store API — system_settings adalah satu-satunya
    // store key-value (site_settings legacy sudah didekomisioning).

    /**
     * Baca peta key => value dari system_settings.
     * @param array $keys Kosong = semua baris (SELECT key_name, key_value).
     */
    public function get_settings_map($keys = []) {
        $this->db->select('key_name, key_value');
        if (!empty($keys)) {
            $this->db->where_in('key_name', $keys);
        }
        $settings = [];
        foreach ($this->db->get('system_settings')->result() as $row) {
            $settings[$row->key_name] = $row->key_value;
        }
        return $settings;
    }

    /**
     * Persist batch dalam SATU transaksi + SATU audit row (pola M5/A1).
     * Controller menyiapkan $audit dengan before/after per key SEBELUM
     * memanggil; rollback ikut menghapus baris audit.
     */
    public function update_system_settings($data, $audit = null) {
        $this->db->trans_start();
        foreach ($data as $key => $value) {
            $this->db->query(
                'INSERT INTO system_settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), updated_at = CURRENT_TIMESTAMP',
                [$key, $value]
            );
        }
        $this->_write_audit($audit);
        $this->db->trans_complete();
        return $this->db->trans_status();
    }

    // ===================================================================
    //  PHASE 9A: ANALYTICS
    // ===================================================================

    public function get_active_users_count() {
        // M3 (plan/60): investor aktif = memiliki kontrak yang belum expired.
        $now = date('Y-m-d H:i:s');
        return (int) ($this->db->query(
            "SELECT COUNT(DISTINCT user_id) AS cnt FROM user_rentals WHERE status = 'active' AND expired_at > ?",
            [$now]
        )->row()->cnt ?? 0);
    }

    public function get_rental_volume() {
        return (int) $this->db
            ->select_sum('purchase_price')
            ->get('user_rentals')
            ->row()->purchase_price ?? 0;
    }

    public function get_withdrawal_volume() {
        $query = $this->db->query("SELECT COALESCE(SUM(amount), 0) AS total_withdrawal FROM withdrawals WHERE status = 'success'");
        return (int) $query->row()->total_withdrawal;
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
            $data[]   = (int) $row->revenue;
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
            "SELECT COUNT(*) AS active_rentals FROM user_rentals WHERE status = 'active' AND expired_at > ?",
            [date('Y-m-d H:i:s')] // M3 (plan/60): hanya kontrak belum expired
        )->row();

        $total_users = $this->db->count_all('users');

        return [
            'total_agents'     => (int) ($agents->total_agents ?? 0),
            'total_commissions'=> (int) ($commissions->total_commissions ?? 0),
            'active_rentals'   => (int) ($active_rentals->active_rentals ?? 0),
            'total_users'      => (int) $total_users,
        ];
    }

    // ===================================================================
    //  PHASE 9B: LEADERBOARD — TOP AFFILIATES (RECURSIVE CTE)
    // ===================================================================

    public function get_leaderboard($limit = 25) {
        // M3 (plan/60): metrik 'active' hanya menghitung kontrak yang belum
        // expired (expired_at > now) — cegah inflasi phantom-active.
        $now = date('Y-m-d H:i:s');
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
                COALESCE(SUM(CASE WHEN ur.status = 'active' AND ur.expired_at > ? THEN ur.purchase_price ELSE 0 END), 0) AS total_sales,
                COUNT(DISTINCT CASE WHEN ur.status = 'active' AND ur.expired_at > ? THEN ur.id END) AS active_rental_count
            FROM users aff
            INNER JOIN downline_tree dt ON dt.affiliate_id = aff.id
            LEFT JOIN user_rentals ur ON ur.user_id = dt.downline_id
            GROUP BY aff.id, aff.phone, aff.username, aff.invite_code
            ORDER BY downline_count DESC, total_sales DESC
            LIMIT ?";

        return $this->db->query($sql, [$now, $now, (int) $limit])->result();
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

        $credit = (int) $this->db
            ->select_sum('amount')
            ->where('user_id', $user_id)
            ->where('type', 'credit')
            ->get('wallet_ledger')
            ->row()->amount ?? 0;

        $debit = (int) $this->db
            ->select_sum('amount')
            ->where('user_id', $user_id)
            ->where('type', 'debit')
            ->get('wallet_ledger')
            ->row()->amount ?? 0;

        $rentals = $this->db->query(
            "SELECT COUNT(*) AS active_count,
                    COALESCE(SUM(purchase_price), 0) AS total_invested
             FROM user_rentals WHERE user_id = ? AND status = 'active' AND expired_at > ?",
            [$user_id, date('Y-m-d H:i:s')] // M3 (plan/60): kontrak aktif = belum expired
        )->row();

        $downline_count = $this->db
            ->where('parent_id', $user_id)
            ->count_all_results('users');

        $total_wd = (int) $this->db
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
            'total_invested'    => (int) ($rentals->total_invested ?? 0),
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
        // C3 (plan/52): product_name lives in gpu_products (FK product_id);
        // tambah phone user. Tanpa join query ini error (SQL 500).
        // M3 (plan/60): export 'active rentals' = kontrak belum expired.
        return $this->db->select(
                'ur.id, ur.user_id, u.phone, g.name AS product_name,
                 ur.purchase_price, ur.daily_roi, ur.days_processed,
                 ur.total_days, ur.status, ur.created_at')
            ->from('user_rentals ur')
            ->join('users u',        'u.id = ur.user_id',    'left')
            ->join('gpu_products g', 'g.id = ur.product_id', 'left')
            ->where('ur.status', 'active')
            ->where('ur.expired_at >', date('Y-m-d H:i:s'))
            ->order_by('ur.created_at', 'DESC')
            ->get()
            ->result_array();
    }

    public function get_all_withdrawals() {
        // C3 (plan/52): bank_name/account_number/account_holder hidup di
        // bank_accounts (bukan withdrawals); phone di users. Tambah gross/fee/net
        // dan gross_eff = fallback legacy (gross 0/NULL -> amount) untuk export.
        return $this->db->select(
                'w.id, w.wd_number, w.user_id, u.phone,
                 w.amount, w.gross_amount, w.fee_amount, w.net_amount,
                 COALESCE(NULLIF(w.gross_amount, 0), w.amount) AS gross_eff,
                 ba.bank_name, ba.account_number, ba.account_holder,
                 w.status, w.remark, w.processed_at, w.created_at')
            ->from('withdrawals w')
            ->join('users u',         'u.id  = w.user_id',         'left')
            ->join('bank_accounts ba', 'ba.id = w.bank_account_id', 'left')
            ->order_by('w.created_at', 'DESC')
            ->get()
            ->result_array();
    }

}