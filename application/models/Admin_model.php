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
        // plan/102: status terminal baru (rejected/expired) ikut terhitung;
        // 'failed' dipertahankan untuk baris legacy. WAJIB sama persis dengan
        // filter get_history_deposits() agar total paginasi = jumlah baris.
        return (int) $this->db
            ->where_in('status', ['success', 'failed', 'rejected', 'expired'])
            ->count_all_results('deposits');
    }

    public function count_history_withdrawals() {
        return (int) $this->db
            ->where_in('status', ['success', 'failed'])
            ->count_all_results('withdrawals');
    }

    // ===================================================================
    //  ALERT CENTER (Plan 94 F2): COUNT antrean pending per queue.
    //  ===================================================================
    // Dipakai oleh polling GET /admin/alerts/poll DAN SSR awal
    // (Admin::__construct → global_admin_alerts) agar badge/bell terisi
    // sebelum poll pertama. Query memakai index leading-status
    // (deposits/withdrawals: idx_status_created — migrasi plan/94;
    // promoter_claims: idx_status_created existing) → index-scan sub-ms.
    // A1/A3: read-only, tanpa TX, tanpa audit (bukan mutasi).

    /**
     * Counts urgent per queue + total.
     *
     * @return array{pending_deposits:int,pending_withdrawals:int,pending_promoter_claims:int,total_urgent:int}
     */
    public function get_alert_counts() {
        $counts = [
            // plan/102: antrean deposit = pending (belum konfirmasi) +
            // waiting_approval (member sudah menyatakan transfer, menunggu
            // verifikasi admin). Badge HARUS sama dengan isi panel dashboard —
            // kalau hanya 'pending', menyelesaikan konfirmasi member justru
            // menurunkan badge padahal deposit masih menunggu aksi admin.
            'pending_deposits'    => (int) $this->db
                ->where_in('status', ['pending', 'waiting_approval'])
                ->count_all_results('deposits'),
            'pending_withdrawals' => (int) $this->db->where('status', 'pending')->count_all_results('withdrawals'),
            'pending_promoter_claims' => (int) $this->db->where('status', 'pending')->count_all_results('promoter_claims'),
        ];
        $counts['total_urgent'] = $counts['pending_deposits']
                                + $counts['pending_withdrawals']
                                + $counts['pending_promoter_claims'];
        return $counts;
    }

    // ===== HISTORY: FETCHERS =====

    public function get_history_deposits($limit, $offset) {
        $this->db->select('d.*, u.phone');
        $this->db->from('deposits d');
        $this->db->join('users u', 'u.id = d.user_id', 'left');
        // plan/102: parity dengan count_history_deposits().
        $this->db->where_in('d.status', ['success', 'failed', 'rejected', 'expired']);
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
                       u.is_promoter, COALESCE(l.balance, 0) AS balance, u.created_at,
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

    // ===================================================================
    // PLAN/91 — PROMOTER CLAIMS (queue admin)
    // ===================================================================

    /**
     * Hitung klaim promotor utk pagination. $status: '' | pending | approved
     * | rejected; $search: filter phone/username promotor (LIKE).
     */
    public function count_promoter_claims($status = '', $search = '') {
        $this->db->from('promoter_claims pc');
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $this->db->where('pc.status', $status);
        }
        if ($search !== '') {
            $this->db->join('users u', 'u.id = pc.user_id', 'left');
            $this->db->group_start();
            $this->db->like('u.phone', $search);
            $this->db->or_like('u.username', $search);
            $this->db->group_end();
        }
        return (int) $this->db->count_all_results();
    }

    /**
     * Daftar klaim (join promotor + produk + admin) utk queue
     * /admin/promoter-claims. Order terbaru dulu.
     */
    public function get_promoter_claims($status = '', $search = '', $limit = 50, $offset = 0) {
        $sql = "SELECT pc.*, u.phone AS user_phone, u.username, u.is_promoter, u.is_banned,
                       p.name AS product_name, p.price AS product_price,
                       a.username AS admin_username
                FROM promoter_claims pc
                LEFT JOIN users u       ON u.id = pc.user_id
                LEFT JOIN gpu_products p ON p.id = pc.product_id
                LEFT JOIN admins a      ON a.id = pc.admin_id";

        $params = [];
        $where  = [];

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where[]  = 'pc.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[]  = '(u.phone LIKE ? OR u.username LIKE ?)';
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql     .= ' ORDER BY pc.created_at DESC, pc.id DESC LIMIT ? OFFSET ?';
        $params[] = (int) $limit;
        $params[] = max(0, (int) $offset);

        return $this->db->query($sql, $params)->result();
    }

    /**
     * Satu baris klaim (join promotor + produk) — utk validasi controller
     * approve/reject sebelum memanggil TX model.
     */
    public function get_promoter_claim($claim_id) {
        return $this->db->query(
            "SELECT pc.*, u.phone AS user_phone, p.name AS product_name
               FROM promoter_claims pc
               LEFT JOIN users u ON u.id = pc.user_id
               LEFT JOIN gpu_products p ON p.id = pc.product_id
              WHERE pc.id = ?",
            [(int) $claim_id]
        )->row();
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

    /**
     * plan/91 — Toggle flag promotor (is_promoter 0↔1). Admin-only mutator;
     * audit atomik dikelola controller (pola toggle_ban, M5). Demosi TIDAK
     * membatalkan klaim pending (dec-6d14b1039ad8cc30) — hanya mencabut
     * bypass gating referral & memblokir pengajuan baru (gate baca segar
     * di Promoter_model::submit_claim).
     *
     * @param int $id
     * @return int|false Nilai BARU (0/1), false bila user tidak ditemukan.
     */
    public function toggle_promoter($id) {
        $user = $this->db->select('is_promoter')->where('id', $id)->get('users')->row();
        if (!$user) return false;

        $new_val = $user->is_promoter ? 0 : 1;
        $this->db->where('id', $id)->update('users', ['is_promoter' => $new_val]);
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
     * W1 — Approve deposit: (pending|waiting_approval) → success + kredit
     * ledger & cache. plan/102: nilai kredit = **pokok + kode unik** (D3:
     * deposit fee ditahan platform; saat fee OFF = total_amount = Option A)
     * dan reservasi kode dilepas.
     *
     * Guard status (keputusan D1/D4):
     *   - status WAJIB masih hidup (pending atau waiting_approval) — replay/
     *     double-click → 0 baris → tanpa kredit;
     *   - `waiting_approval` **SELALU dapat di-approve**, termasuk setelah
     *     jendela bayar lewat: member sudah menyatakan transfer, dan satu-
     *     satunya otoritas adalah mutasi bank yang diverifikasi admin.
     *     (Kalau baris ini ikut ditolak, D1 kehilangan maknanya — member yang
     *     transfer di menit 59 akan kehilangan uangnya.)
     *   - `pending` yang jendela bayarnya sudah lewat DITOLAK: kode uniknya
     *     sudah dilepas sweep dan bisa dimiliki orang lain.
     *
     * @param int   $deposit_id
     * @param array|null $audit  Konteks audit dari _audit_ctx(); user_id & details
     *                           diisi model dari baris deposit (parity lama).
     * @return array{success:bool, deposit:object|null, message:string}
     */
    public function approve_deposit($deposit_id, $audit = null) {
        $deposit = $this->db->get_where('deposits', ['id' => (int) $deposit_id])->row();

        if (!$deposit || !in_array($deposit->status, ['pending', 'waiting_approval'], true)) {
            return ['success' => false, 'deposit' => null, 'message' => 'Deposit tidak valid atau sudah diproses.'];
        }

        $now = date('Y-m-d H:i:s');
        $pending_expired = ($deposit->status === 'pending'
            && $deposit->expires_at !== null && $deposit->expires_at <= $now);

        if ($pending_expired) {
            return ['success' => false, 'deposit' => $deposit, 'message' => 'Deposit sudah kedaluwarsa dan tidak dapat disetujui.'];
        }

        $this->load->model('Wallet_model');

        // Nilai kredit otoritatif (pokok + kode; fallback amount utk legacy).
        $credit_amount = $this->Wallet_model->deposit_credit_amount($deposit);

        $this->db->trans_begin();

        try {
            // 1. Anchor lock users (W1 credit path — serialisasi sebelum mutasi).
            if ($this->Wallet_model->lock_and_get_balance((int) $deposit->user_id) === false) {
                $this->db->trans_rollback();
                return ['success' => false, 'deposit' => $deposit, 'message' => 'Gagal memproses deposit.'];
            }

            // 2. Transisi atomik kondisional (replay/double-click → 0 baris) +
            //    pelepasan reservasi kode unik + cap waktu proses.
            //    Guard jendela bayar HANYA berlaku untuk baris `pending` (D1).
            $this->db->where('id', (int) $deposit_id);
            $this->db->where(
                "(status = 'waiting_approval'"
                . " OR (status = 'pending' AND (expires_at IS NULL OR expires_at > " . $this->db->escape($now) . ")))",
                null,
                false
            );
            $this->db->update('deposits', [
                'status'            => 'success',
                'processed_at'      => $now,
                'reserved_code_key' => null,
            ]);

            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                return ['success' => false, 'deposit' => $deposit, 'message' => 'Deposit tidak valid atau sudah diproses.'];
            }

            // 3. Kredit ledger + cache atomik (helper C4) — nominal penuh (Option A).
            $credited = $this->Wallet_model->credit(
                (int) $deposit->user_id,
                $credit_amount,
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
                $audit['details'] = [
                    'invoice_number' => $deposit->invoice_number,
                    'amount'         => $deposit->amount,
                    'unique_code'    => $deposit->unique_code,
                    'total_amount'   => $deposit->total_amount,
                    'credited'       => $credit_amount,
                    'confirmed'      => ($deposit->status === 'waiting_approval'),
                ];
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
     * plan/102 — Decline deposit: (pending|waiting_approval) → rejected,
     * opsional alasan, TANPA mutasi uang (belum ada kredit yang terjadi).
     *
     * Reservasi kode dilepas di transisi yang sama sehingga kode bebas dipakai
     * ulang. Refund (bila member terlanjur transfer dengan nominal/pokok salah)
     * adalah keputusan operator di luar sistem → gunakan tool Inject Balance
     * yang sudah teraudit.
     *
     * @param int         $deposit_id
     * @param array|null  $audit
     * @param string|null $reason  Alasan penolakan (opsional, ≤255).
     * @return array{success:bool, deposit:object|null, message:string}
     */
    public function decline_deposit($deposit_id, $audit = null, $reason = null) {
        $deposit = $this->db->get_where('deposits', ['id' => (int) $deposit_id])->row();

        if (!$deposit || !in_array($deposit->status, ['pending', 'waiting_approval'], true)) {
            return ['success' => false, 'deposit' => null, 'message' => 'Deposit tidak valid atau sudah diproses.'];
        }

        $reason = ($reason !== null && trim($reason) !== '')
            ? mb_substr(trim($reason), 0, 255)
            : null;

        $now = date('Y-m-d H:i:s');

        $this->db->trans_begin();

        try {
            // Transisi kondisional (anti double-submit M4): 0 baris → false.
            // TIDAK ada lock users & TIDAK ada mutasi ledger — tidak ada uang
            // yang bergerak pada penolakan deposit.
            $this->db->where('id', (int) $deposit_id);
            $this->db->where_in('status', ['pending', 'waiting_approval']);
            $this->db->update('deposits', [
                'status'            => 'rejected',
                'decline_reason'    => $reason,
                'processed_at'      => $now,
                'reserved_code_key' => null,
            ]);

            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                return ['success' => false, 'deposit' => $deposit, 'message' => 'Deposit tidak valid atau sudah diproses.'];
            }

            if (is_array($audit)) {
                $audit['user_id'] = (int) $deposit->user_id;
                $audit['details'] = [
                    'invoice_number' => $deposit->invoice_number,
                    'amount'         => $deposit->amount,
                    'unique_code'    => $deposit->unique_code,
                    'total_amount'   => $deposit->total_amount,
                    'reason'         => $reason,
                ];
                $this->_write_audit($audit);
            }

            $this->db->trans_commit();

            return ['success' => true, 'deposit' => $deposit, 'message' => ''];

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Admin_model::decline_deposit — ' . $e->getMessage());
            return ['success' => false, 'deposit' => $deposit, 'message' => 'Gagal menolak deposit.'];
        }
    }

    /**
     * plan/102 — Antrean deposit untuk Command Center: baris HIDUP
     * (waiting_approval = uang sudah diklaim ditransfer, prioritas verifikasi
     * lebih tinggi daripada `pending` yang belum dikonfirmasi member).
     *
     * Dipindah dari Admin::index() agar invariant "semua akses DB di model"
     * (AGENTS.md) tetap berlaku.
     *
     * @return array<int,object>
     */
    public function get_deposit_queue() {
        return $this->db->select('d.*, u.phone')
            ->from('deposits d')
            ->join('users u', 'u.id = d.user_id', 'left')
            ->where_in('d.status', ['pending', 'waiting_approval'])
            ->order_by("FIELD(d.status, 'waiting_approval', 'pending')", 'ASC', false)
            ->order_by('d.created_at', 'ASC')
            ->get()->result();
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

    // ===================================================================
    //  plan/85 + plan/87 — ADMIN GPU PRODUCT MANAGEMENT (CRUD, no hard delete)
    //
    //  Semua SQL hidup di model (repo rule). Mutator dipanggil DI DALAM
    //  transaksi controller (trans_start/trans_complete) yang juga menulis
    //  audit via Audit_model::log_admin_action — state & audit commit/rollback
    //  bersama (M5). Parameter selalu bound; id & angka di-(int) kan (M8).
    //  Predikat kuota/usage konsisten plan/83 D1: user_rentals status
    //  IN ('active','completed'); active_cnt = sedang berjalan (status active).
    //  plan/87: gating prasyarat DICOMMISSIONED — query baca TANPA join/
    //  kolom unlock_prerequisite_id (kolom dormant, seluruh baris NULL).
    // ===================================================================

    /**
     * Semua paket (aktif & nonaktif) + pemakaian live. (plan/87: tanpa nama
     * prasyarat — gating prasyarat DICOMMISSIONED.)
     * Satu grouped aggregate pada user_rentals (bukan denormalisasi):
     *   active_cnt = kontrak 'active' (sedang berjalan)
     *   total_cnt  = 'active'+'completed' (kuota lifetime terpakai)
     *
     * @return array  objects: gpu_products.*, active_cnt:int, total_cnt:int
     */
    public function get_products_admin() {
        $rows = $this->db->query(
            "SELECT p.*,
                    COALESCE(ag.active_cnt, 0) AS active_cnt,
                    COALESCE(ag.total_cnt, 0)  AS total_cnt
               FROM gpu_products p
               LEFT JOIN (
                    SELECT product_id,
                           SUM(status = 'active')                AS active_cnt,
                           SUM(status IN ('active','completed')) AS total_cnt
                      FROM user_rentals
                     GROUP BY product_id
               ) ag ON ag.product_id = p.id
              ORDER BY p.id ASC"
        )->result();

        // M8: counter & kebijakan integer dipaksa (int) sebelum return.
        foreach ($rows as $r) {
            $r->active_cnt     = (int) $r->active_cnt;
            $r->total_cnt      = (int) $r->total_cnt;
            $r->max_per_user   = (int) $r->max_per_user;
            $r->duration_days  = (int) $r->duration_days;
        }
        return $rows;
    }

    /**
     * Satu paket by id — snapshot BEFORE untuk audit. (plan/87: tanpa join
     * prasyarat — gating prasyarat DICOMMISSIONED.)
     *
     * @param int $id
     * @return object|null  null saat baris tidak ada.
     */
    public function get_product_row($id) {
        return $this->db->query(
            "SELECT p.*
               FROM gpu_products p
              WHERE p.id = ?",
            [(int) $id]
        )->row();
    }

    /**
     * Cek duplikasi nama (case-insensitive via collation ci default).
     * Uniqueness menjaga seeding plan/83 yang mencocokkan baris BY NAME.
     *
     * @param string   $name
     * @param int|null $ignore_id  Abaikan baris ini (saat update diri sendiri).
     * @return bool
     */
    public function is_product_name_taken($name, $ignore_id = null) {
        $this->db->where('name', $name);
        if ($ignore_id !== null) {
            $this->db->where('id !=', (int) $ignore_id);
        }
        return $this->db->count_all_results('gpu_products') > 0;
    }

    /**
     * INSERT paket baru — kolom eksplisit; dipanggil dalam TX controller.
     *
     * @param array $data  {name,type,price,daily_rate,duration_days,
     *                     is_refundable,max_per_user,is_active}
     * @return int|false  insert_id atau false.
     */
    public function create_product(array $data) {
        $clean = $this->_sanitize_product_fields($data);
        if (!$this->db->insert('gpu_products', $clean)) {
            log_message('error', 'Admin_model::create_product — insert gagal: ' . $this->db->error()['message']);
            return false;
        }
        return (int) $this->db->insert_id();
    }

    /**
     * UPDATE paket by id (guarded). false hanya pada error SQL ATAU baris
     * tidak ada; update bernilai identik (affected_rows 0) = sukses no-op.
     *
     * @param int   $id
     * @param array $data  subset kolom yang diedit (lihat _sanitize_product_fields)
     * @return bool
     */
    public function update_product($id, array $data) {
        $id    = (int) $id;
        $clean = $this->_sanitize_product_fields($data);

        $this->db->where('id', $id);
        if (!$this->db->update('gpu_products', $clean)) {
            log_message('error', 'Admin_model::update_product — update gagal (id=' . $id . '): ' . $this->db->error()['message']);
            return false;
        }

        if ($this->db->affected_rows() === 0) {
            // 0 baris: nilai identik (no-op sukses) ATAU baris hilang.
            $exists = $this->db->where('id', $id)->count_all_results('gpu_products');
            return $exists > 0;
        }
        return true;
    }

    /**
     * Toggle/paksa is_active (0|1). no-op nilai sama = sukses; baris hilang
     * atau error SQL = false.
     *
     * @param int $id
     * @param int $is_active  0 atau 1
     * @return bool
     */
    public function set_product_active($id, $is_active) {
        $id        = (int) $id;
        $is_active = (int) $is_active;

        $this->db->where('id', $id);
        if (!$this->db->update('gpu_products', ['is_active' => ($is_active === 1 ? 1 : 0)])) {
            log_message('error', 'Admin_model::set_product_active — update gagal (id=' . $id . '): ' . $this->db->error()['message']);
            return false;
        }
        if ($this->db->affected_rows() === 0) {
            $exists = $this->db->where('id', $id)->count_all_results('gpu_products');
            return $exists > 0;
        }
        return true;
    }

    /**
     * plan/104 — guard sebelum menghapus berkas gambar lama.
     *
     * TRUE bila nama berkas masih direferensikan baris produk LAIN. Berkas
     * gambar normalnya unik (Upload library memakai encrypt_name), tetapi
     * aset hasil backfill/seed manual bisa dipakai bersama > 1 baris —
     * dalam kasus itu unlink akan merusak produk lain, jadi berkas
     * dipertahankan (orphan yang aman > broken image).
     *
     * @param string $filename  basename di uploads/products/
     * @param int    $except_id baris yang sedang di-update (dikecualikan)
     * @return bool
     */
    public function is_product_image_referenced($filename, $except_id = 0) {
        $filename = (string) $filename;
        if ($filename === '') {
            return false;
        }

        return $this->db
            ->where('image', $filename)
            ->where('id !=', (int) $except_id)
            ->count_all_results('gpu_products') > 0;
    }

    /**
     * Normalisasi + koersi (int) kolom produk sebelum write (M8). Kolom
     * finansial & durasi sudah divalidasi regex di controller; model tetap
     * memaksa integer & whitelist kolom (anti mass-assignment).
     * plan/87: unlock_prerequisite_id TIDAK lagi di whitelist — kolom
     * dormant, tidak boleh tersentuh jalur write mana pun.
     * plan/104: `image` masuk whitelist tetapi SELALU disanitasi lewat
     * product_image_filename() (basename-only + allowlist ekstensi).
     *
     * @param array $data  subset kolom yang diizinkan.
     * @return array       kolom bersih siap insert/update.
     */
    private function _sanitize_product_fields(array $data) {
        $allowed = ['name', 'type', 'price', 'daily_rate', 'duration_days',
                    'is_refundable', 'max_per_user', 'is_active',
                    // plan/104: gambar produk (basename di uploads/products/).
                    'image'];
        $clean = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            switch ($key) {
                case 'name':
                    $clean[$key] = trim((string) $data[$key]);
                    break;
                case 'image':
                    // plan/104: choke-point anti mass-assignment — nilai apa pun
                    // dari luar harus lewat product_image_filename() (basename-only
                    // + allowlist jpg|jpeg|png|webp). Input liar → NULL, bukan
                    // string mentah yang bisa jadi path traversal.
                    // Kunci absen ⇒ kolom TIDAK tersentuh (gambar lama aman).
                    $clean[$key] = product_image_filename($data[$key]);
                    break;
                case 'type':
                    $clean[$key] = (in_array($data[$key], ['short_term', 'long_term'], true))
                        ? $data[$key] : 'short_term';
                    break;
                case 'price':
                case 'daily_rate':
                case 'duration_days':
                case 'max_per_user':
                    $clean[$key] = (int) $data[$key];
                    break;
                default: // is_refundable, is_active — boolean 0/1
                    $clean[$key] = ((int) $data[$key] === 1) ? 1 : 0;
            }
        }
        return $clean;
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