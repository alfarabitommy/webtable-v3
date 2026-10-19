<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_model extends CI_Model {

    public function __construct() {
        parent::__construct();
        // C4 (plan/54): claim_milestone/get_milestone_status memakai ledger
        // ingestion helper + saldo segar — pastikan Wallet_model ter-load walau
        // dipanggil di luar MY_Controller (jalur CLI/cron).
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
     * plan/116 (D2): "aktif" juga mensyaratkan kontrak dari produk NON-trial
     * (`gpu_products.is_trial = 0`) — pemegang trial gratis (plan/114) TIDAK
     * dihitung aktif. Predikat kanonik = Rental_model::has_paid_rental().
     */
    public function get_team_with_active_status($user_id) {
        $now = date('Y-m-d H:i:s');
        $sql = "SELECT u.id, u.username, u.phone, u.invite_code, u.level_id, u.created_at, 1 AS `level`,
                (SELECT COUNT(*) FROM user_rentals ur
                   JOIN gpu_products gp ON gp.id = ur.product_id
                  WHERE ur.user_id = u.id AND ur.status = 'active' AND ur.expired_at > ?
                    AND gp.is_trial = 0) AS is_active
                FROM users u WHERE u.parent_id = ?
                UNION ALL
                SELECT u.id, u.username, u.phone, u.invite_code, u.level_id, u.created_at, 2 AS `level`,
                (SELECT COUNT(*) FROM user_rentals ur
                   JOIN gpu_products gp ON gp.id = ur.product_id
                  WHERE ur.user_id = u.id AND ur.status = 'active' AND ur.expired_at > ?
                    AND gp.is_trial = 0) AS is_active
                FROM users u INNER JOIN users p ON u.parent_id = p.id
                WHERE p.parent_id = ? AND u.id != ?";
        return $this->db->query($sql, [$now, $user_id, $now, $user_id, $user_id])->result();
    }

    // ============================================================
    // PHASE 9B — AFFILIATE REWARD METHODS (Bonus Pencapaian Tim)
    //
    // plan/120: blok "Misi Level 1" (konstanta `LEVEL1_BONUS`, `get_claim_data()`,
    // `claim_level1()`) DIHAPUS — fitur dihentikan di lapis aplikasi. Kolom
    // `users.is_level_1_claimed` DIPERTAHANKAN sebagai retensi historis (nol
    // pembaca/penulis di sini). Primitif `count_active_b_downlines()` dan
    // `sum_sales_b_downlines()` SENGAJA dipertahankan sebagai mesin reusable
    // (rujukan invariant plan/116) walau tanpa pemanggil aktif.
    // ============================================================

    /**
     * Count active downlines (B-tier only = direct referrals with active rental)
     * M3 (plan/60): filter defensif expired_at > now — kontrak kedaluwarsa
     * tidak pernah dihitung walau status row belum di-flip (user tak login).
     * plan/116 (D2): wajib `gpu_products.is_trial = 0` — pemegang trial gratis
     * TIDAK dihitung downline aktif (predikat kanonik = has_paid_rental()).
     * @param int $user_id
     * @return int
     */
    public function count_active_b_downlines($user_id) {
        $now = date('Y-m-d H:i:s');
        $sql = "SELECT COUNT(DISTINCT u.id) AS cnt
                FROM users u
                JOIN user_rentals ur ON ur.user_id = u.id
                JOIN gpu_products gp ON gp.id = ur.product_id
                WHERE u.parent_id = ?
                  AND ur.status = 'active'
                  AND ur.expired_at > ?
                  AND gp.is_trial = 0";
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
     * plan/116 (D2) — OTORITAS LEVEL GAJI MINGGUAN: kontrak dari produk TRIAL
     * (`gpu_products.is_trial = 1`, harga 0 — plan/114) TIDAK PERNAH dihitung
     * sebagai downline aktif. Tanpa filter ini, 9 pemegang trial gratis cukup
     * untuk membuka Gaji Mingguan Level 2 tanpa modal (anti free-rider).
     * Predikat identik dengan Rental_model::has_paid_rental() (plan/114 D-A).
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
                JOIN gpu_products gp ON gp.id = ur.product_id
                WHERE ur.status = 'active'
                  AND ur.expired_at > ?
                  AND gp.is_trial = 0";
        $row = $this->db->query($sql, [$user_id, $now])->row();
        return (int) ($row->cnt ?? 0);
    }

    // =====================================================================
    // plan/119 — BONUS PENCAPAIAN TIM (TEAM MILESTONE BONUS)
    // Pengganti Gaji Mingguan: tier DINAMIS dari `system_settings`
    // (`team_milestone_enabled` + `team_milestone_tiers`), dibayar SEKALI
    // seumur hidup per tier, klaim berurutan dari tier terendah, kredit ke
    // SALDO UTAMA (`wallet_type = 'main'`).
    //
    // Invarian (plan/119 §0.3):
    //   MK1 predikat "downline aktif" = count_all_active_downlines() (K8, plan/116)
    //   MK2 satu klaim per (user, target) seumur hidup — 3 lapis (state CSV +
    //       UNIQUE ledger + recount di dalam TX)
    //   MK3 kredit HANYA lewat Wallet_model::credit() dompet `main`
    //   MK4 nominal SELALU dari konfigurasi server; klien hanya kirim `target`
    //   MK5 urutan TX mengikat (lihat docblock claim_milestone())
    //   MK6 klaim permanen (tanpa clawback)
    //   MK7 klaim sekuensial (tier lebih rendah wajib sudah diklaim)
    //   MK8 tanpa tabel/index baru
    // =====================================================================

    /** @var array|null Cache konfigurasi milestone per-request. */
    private static $_ms_cfg = NULL;

    /**
     * Konfigurasi Bonus Pencapaian Tim (dinamis + fallback per-key).
     *
     * Pola `Checkin_model::get_config()`: baris `system_settings` di atas
     * fallback `application/config/team_milestones.php`, divalidasi per-key.
     * Nilai rusak/tamper → fallback key tersebut + log; TIDAK pernah fatal,
     * dan TIDAK pernah menghasilkan tier nominal 0 (parse ketat helper).
     *
     * @return array{enabled:bool,tiers:array<int,array{target:int,amount:int}>}
     */
    public function get_milestone_config() {
        if (self::$_ms_cfg !== NULL) {
            return self::$_ms_cfg;
        }

        $fallback = require APPPATH . 'config/team_milestones.php';

        $map = [];
        $rows = $this->db->query(
            "SELECT key_name, key_value FROM system_settings
              WHERE key_name IN ('team_milestone_enabled', 'team_milestone_tiers')"
        )->result();

        foreach ($rows as $row) {
            $map[$row->key_name] = $row->key_value;
        }

        self::$_ms_cfg = $this->_resolve_milestone_config($fallback, $map);
        return self::$_ms_cfg;
    }

    /**
     * Gabungkan baris dinamis di atas fallback dengan validasi per-key.
     *
     * @param  array $fallback Isi `application/config/team_milestones.php`
     * @param  array $map      key_name => key_value dari `system_settings`
     * @return array{enabled:bool,tiers:array<int,array{target:int,amount:int}>}
     */
    private function _resolve_milestone_config(array $fallback, array $map) {
        $cfg = [
            'enabled' => ((int) ($fallback['team_milestone_enabled'] ?? 0)) === 1,
            'tiers'   => [],
        ];

        // Fallback tier (wajib lolos validasi penuh — kalau tidak, tiers kosong
        // dan kartu member merender state "belum dikonfigurasi").
        $fb_tiers = team_milestone_tiers_parse($fallback['team_milestone_tiers'] ?? []);
        if ($fb_tiers !== NULL) {
            $fb_valid = team_milestone_tiers_valid($fb_tiers);
            if ($fb_valid['ok']) {
                $cfg['tiers'] = $fb_tiers;
            } else {
                log_message('error', 'User_model: fallback team_milestones.php tidak valid ('
                    . ($fb_valid['reason'] ?? '?') . ') — tiers kosong');
            }
        } else {
            log_message('error', 'User_model: fallback team_milestones.php tidak dapat diparse — tiers kosong');
        }

        // Toggle dinamis: hanya '0'/'1' diterima (nilai lain = fallback + log).
        if (array_key_exists('team_milestone_enabled', $map)) {
            $raw = (string) $map['team_milestone_enabled'];
            if ($raw === '0' || $raw === '1') {
                $cfg['enabled'] = ($raw === '1');
            } else {
                log_message('error', 'User_model: team_milestone_enabled tidak valid (' . $raw . ') — fallback dipakai');
            }
        }

        // Tier dinamis: parse ketat + validasi penuh; rusak → fallback + log.
        if (array_key_exists('team_milestone_tiers', $map)) {
            $tiers = team_milestone_tiers_parse($map['team_milestone_tiers']);
            $valid = ($tiers !== NULL)
                ? team_milestone_tiers_valid($tiers)
                : ['ok' => FALSE, 'reason' => 'parse_failed'];

            if ($valid['ok']) {
                $cfg['tiers'] = $tiers;
            } else {
                log_message('error', 'User_model: team_milestone_tiers tidak valid ('
                    . ($valid['reason'] ?? '?') . ') — fallback dipakai');
            }
        }

        return $cfg;
    }

    /**
     * Validator setelan tier untuk panel admin (kontrak sama dengan
     * `Checkin_model::validate_checkin_settings()` / plan/110):
     * `{ok, errors[], field_errors{}, values{}}` — all-or-nothing; pesan
     * berbahasa Indonesia (invarian L1) dan aman dari gate i18n karena hidup
     * di MODEL (gate `audit_i18n_hardcoded.php` mengecualikan model dari R2).
     *
     * Transport input: array baris `[['target'=>…, 'amount'=>…], …]`
     * (plan/110 — baris belum valid tetap terkirim & dapat direpopulasi),
     * atau JSON kanonik legacy `[[9,200000],…]` bila transport array tidak ada.
     *
     * @param  array $raw
     * @return array{ok:bool,errors:string[],field_errors:array<string,string[]>,values:array<string,string>}
     */
    public function validate_milestone_settings(array $raw) {
        $errors       = [];
        $field_errors = [];
        $values       = [];
        $lim          = team_milestone_limits();

        // Toggle (semantik checkbox: tidak dikirim = OFF).
        $values['team_milestone_enabled'] =
            (isset($raw['team_milestone_enabled']) && ! empty($raw['team_milestone_enabled'])) ? '1' : '0';

        $input = $raw['team_milestone_tiers'] ?? NULL;
        $tiers = [];
        $row_errors = FALSE;

        if (is_array($input)) {
            // Array baris (transport utama) ATAU tuple kanonik [[t,a],…].
            $i = 0;
            foreach ($input as $row) {
                $i++;

                $target_raw = NULL;
                $amount_raw = NULL;
                if (is_array($row)) {
                    if (array_key_exists('target', $row) || array_key_exists('amount', $row)) {
                        $target_raw = $row['target'] ?? NULL;
                        $amount_raw = $row['amount'] ?? NULL;
                    } else {
                        $pos        = array_values($row);
                        $target_raw = $pos[0] ?? NULL;
                        $amount_raw = $pos[1] ?? NULL;
                    }
                } elseif (is_object($row)) {
                    $target_raw = $row->target ?? NULL;
                    $amount_raw = $row->amount ?? NULL;
                }

                $t_str = is_scalar($target_raw) ? trim((string) $target_raw) : '';
                $a_str = is_scalar($amount_raw) ? trim((string) $amount_raw) : '';

                // Baris kosong sepenuhnya = diabaikan (memudahkan admin
                // menghapus baris; bukan error).
                if ($t_str === '' && $a_str === '') {
                    continue;
                }

                // Baris separuh terisi = error per baris.
                if ($t_str === '' || $a_str === '') {
                    $msg = 'Baris ' . $i . ': target dan nominal wajib diisi keduanya.';
                    $errors[] = $msg;
                    $field_errors['tm_row_' . $i][] = $msg;
                    $row_errors = TRUE;
                    continue;
                }

                $target = team_milestone_int_token($t_str);
                $amount = team_milestone_int_token($a_str);

                if ($target === NULL || $target < 1 || $target > $lim['max_target']) {
                    $msg = 'Baris ' . $i . ': target harus bilangan bulat 1–'
                         . number_format($lim['max_target'], 0, ',', '.')
                         . ' (jumlah downline aktif).';
                    $errors[] = $msg;
                    $field_errors['tm_row_' . $i][] = $msg;
                    $row_errors = TRUE;
                    continue;
                }
                if ($amount === NULL || $amount < 1 || $amount > $lim['max_amount']) {
                    $msg = 'Baris ' . $i . ': nominal bonus harus bilangan bulat 1–'
                         . number_format($lim['max_amount'], 0, ',', '.') . ' (IDR).';
                    $errors[] = $msg;
                    $field_errors['tm_row_' . $i][] = $msg;
                    $row_errors = TRUE;
                    continue;
                }

                $tiers[] = ['target' => $target, 'amount' => $amount];
            }
        } else {
            // Jalur kompatibilitas: JSON kanonik (halaman ter-cache / API).
            $parsed = team_milestone_tiers_parse($input);
            if ($parsed === NULL) {
                $msg = 'Format tier tidak valid. Isi minimal satu baris target + nominal bonus.';
                $errors[] = $msg;
                $field_errors['tm_row_1'][] = $msg;
                $row_errors = TRUE;
            } else {
                $tiers = $parsed;
            }
        }

        // Urutan & keunikan (pesan menyebut nomor baris + nilai konkret).
        if ( ! $row_errors && count($tiers) > 0) {
            $prev = NULL;
            foreach ($tiers as $idx => $tier) {
                if ($prev !== NULL && $tier['target'] <= $prev) {
                    $msg = 'Baris ' . ($idx + 1) . ': target antar baris harus unik dan menaik ('
                         . number_format($prev, 0, ',', '.') . ' → '
                         . number_format($tier['target'], 0, ',', '.') . ').';
                    $errors[] = $msg;
                    $field_errors['tm_row_' . ($idx + 1)][] = $msg;
                }
                $prev = $tier['target'];
            }
        }

        // Gate final: bentuk/ambang/anggaran kolom (satu sumber helper).
        if (count($errors) === 0) {
            $valid = team_milestone_tiers_valid($tiers);
            if ( ! $valid['ok']) {
                switch ($valid['reason']) {
                    case 'empty':
                        $msg = 'Minimal satu tier wajib diisi (target downline aktif + nominal bonus).';
                        $field_errors['tm_row_1'][] = $msg;
                        break;
                    case 'too_many':
                        $msg = 'Maksimal ' . (int) $lim['max_tiers'] . ' tier.';
                        break;
                    case 'csv_too_long':
                        $msg = 'Konfigurasi terlalu panjang — kurangi jumlah atau digit target.';
                        break;
                    default:
                        $msg = 'Konfigurasi tier tidak valid, periksa kembali target dan nominalnya.';
                        break;
                }
                $errors[] = $msg;
            }
        }

        if (count($errors) > 0) {
            return [
                'ok'           => FALSE,
                'errors'       => $errors,
                'field_errors' => $field_errors,
                'values'       => [],
            ];
        }

        $values['team_milestone_tiers'] = team_milestone_tiers_encode($tiers);

        return [
            'ok'           => TRUE,
            'errors'       => [],
            'field_errors' => [],
            'values'       => $values,
        ];
    }

    /**
     * Status Bonus Pencapaian Tim (read-only) — dipakai kartu `/team` DAN
     * payload respons klaim (state segar tanpa reload).
     *
     * State per tier (MK7):
     *   claimed         — target ada di state klaim user
     *   claimable       — tier pertama yang belum diklaim + seluruh tier lebih
     *                     rendah sudah diklaim + `total_active >= target`
     *                     (MAKSIMAL satu baris berstatus ini)
     *   locked_previous — belum diklaim & ada tier lebih rendah yang belum
     *                     diklaim (selesaikan tier sebelumnya dulu)
     *   locked_count    — seluruh tier lebih rendah sudah diklaim tetapi jumlah
     *                     downline aktif belum mencapai target (`missing`)
     *
     * @param  int $user_id
     * @return array
     */
    public function get_milestone_status($user_id) {
        $user_id = (int) $user_id;
        $cfg     = $this->get_milestone_config();
        $user    = $this->get_user_by_id($user_id);

        // MK1 — otoritas penghitungan (predikat K8 plan/116).
        $total = $this->count_all_active_downlines($user_id);

        $claimed = team_milestone_claimed_parse($user->team_milestone_claimed ?? NULL);

        $rows           = [];
        $claimable      = NULL;
        $next_target    = NULL;
        $claimed_total  = 0;
        $all_claimed    = (count($cfg['tiers']) > 0);
        $pending_lower  = FALSE;

        foreach ($cfg['tiers'] as $tier) {
            $target = (int) $tier['target'];
            $amount = (int) $tier['amount'];
            $is_claimed = in_array($target, $claimed, TRUE);

            $missing = $target - $total;
            if ($missing < 0) {
                $missing = 0;
            }

            if ($is_claimed) {
                $state         = 'claimed';
                $claimed_total += $amount;
            } else {
                $all_claimed = FALSE;
                if ($next_target === NULL) {
                    $next_target = $target;
                }

                if ($pending_lower) {
                    // Ada tier lebih rendah yang belum diklaim (MK7/O2).
                    $state = 'locked_previous';
                } elseif ($total >= $target) {
                    $state     = 'claimable';
                    $claimable = $target;
                } else {
                    $state = 'locked_count';
                }
                $pending_lower = TRUE;
            }

            $rows[] = [
                'target'  => $target,
                'amount'  => $amount,
                'state'   => $state,
                'missing' => $missing,
            ];
        }

        return [
            'enabled'          => (bool) $cfg['enabled'],
            'total_active'     => $total,
            'tiers'            => $rows,
            // Target untuk progress bar = tier belum-diklaim terendah.
            'next_target'      => $next_target,
            // Target yang boleh diklaim SEKARANG (sekuensial ⇒ maks 1 | null).
            'claimable_target' => $claimable,
            'claimed_targets'  => $claimed,
            'all_claimed'      => $all_claimed,
            'claimed_total'    => $claimed_total,
            // Saldo utama SEGAR dari ledger (bukan users.balance yang basi — C4).
            'balance'          => (int) $this->Wallet_model->get_balance($user_id),
        ];
    }

    /**
     * Claim Bonus Pencapaian Tim untuk SATU tier (plan/119).
     *
     * Menggantikan `claim_wage()` (Gaji Mingguan, siklus 7 hari): satu tier
     * dibayar SEKALI seumur hidup, tanpa cooldown, klaim berurutan dari tier
     * terendah, kredit ke SALDO UTAMA.
     *
     * Urutan TX yang MENGIKAT (MK5 — jangan diubah tanpa menyesuaikan invarian):
     *   1. `SELECT ... FROM users WHERE id = ? FOR UPDATE` — statement DB
     *      PERTAMA: serialisasi klaim per user, tutup TOCTOU (pola C6 plan/50);
     *   2. gerbang akun (baris tidak ada / banned → `user_unavailable`);
     *   3. resolve konfigurasi pasca-lock → `enabled === false` → `disabled`;
     *   4. gerbang target (MK4): target WAJIB ada di konfigurasi; nominal
     *      SELALU dari konfigurasi server, bukan dari input klien;
     *   5. idempotensi (MK2): target sudah ada di state klaim → `already_claimed`;
     *   6. gerbang sekuensial (MK7/O2): seluruh tier lebih rendah wajib sudah
     *      diklaim → selain itu `locked_previous`. Dievaluasi SEBELUM recount
     *      supaya hasil deterministik (`locked_previous` ≠ `not_qualified`);
     *   7. recount kualifikasi (MK1 — `count_all_active_downlines()`, predikat
     *      K8 plan/116) → kurang → `not_qualified` (+ `missing`);
     *   8. stamp KONDISIONAL state klaim (NULL-safe `<=>`) + guard
     *      `affected_rows() === 1` (M4);
     *   9. SATU kredit `Wallet_model::credit()` dompet `main` (MK3) dengan
     *      transaction_id deterministik `MSB-{user_id}-T{target}`;
     *  10. commit.
     *
     * Semua nilai digenerate PHP dan dikirim sebagai bound params — MySQL
     * NOW() TIDAK dipakai (M2/M3). Seluruh SQL hidup di model (AGENTS.md).
     *
     * @param  int $user_id
     * @param  int $target  Target tier (jumlah downline aktif); divalidasi ulang di sini
     * @return array {success, code, message, amount, target, missing, transaction_id}
     */
    public function claim_milestone($user_id, $target) {
        $user_id = (int) $user_id;
        $target  = (int) $target;

        // CI3 (non-production) merender halaman error mentah saat query gagal DI
        // DALAM transaksi — termasuk duplicate key 1062. Jalur AJAX TIDAK BOLEH
        // mengembalikan HTML (M9/P7), jadi db_debug dimatikan lokal lalu
        // dipulihkan (pola Checkin_model::claim() / Auth.php).
        $prev_debug         = $this->db->db_debug;
        $this->db->db_debug = FALSE;

        try {
            $this->db->trans_begin();

            // ── 1. Anchor row lock (current read) — serialisasi klaim milestone. ──
            $user = $this->db->query(
                "SELECT id, is_banned, team_milestone_claimed
                   FROM users
                  WHERE id = ?
                  FOR UPDATE",
                [$user_id]
            )->row();

            // ── 2. Gerbang akun. ──
            if ( ! $user) {
                $this->db->trans_rollback();
                return $this->_milestone_result('user_unavailable', 'Sistem: Akun tidak ditemukan.');
            }
            if ((int) $user->is_banned === 1) {
                $this->db->trans_rollback();
                return $this->_milestone_result('user_unavailable', 'Sistem: Akun Anda telah dinonaktifkan.');
            }

            // ── 3. Konfigurasi (snapshot stabil pasca-lock). ──
            $cfg = $this->get_milestone_config();
            if ( ! $cfg['enabled']) {
                $this->db->trans_rollback();
                return $this->_milestone_result('disabled', 'Bonus Pencapaian Tim sedang dinonaktifkan.');
            }

            // ── 4. Gerbang target (MK4). ──
            $tier = NULL;
            foreach ($cfg['tiers'] as $candidate) {
                if ((int) $candidate['target'] === $target) {
                    $tier = $candidate;
                    break;
                }
            }
            if ($tier === NULL) {
                $this->db->trans_rollback();
                return $this->_milestone_result('invalid_target', 'Tier bonus tidak valid.');
            }

            $claimed = team_milestone_claimed_parse($user->team_milestone_claimed);

            // ── 5. Idempotensi (MK2). ──
            if (in_array($target, $claimed, TRUE)) {
                $this->db->trans_rollback();
                return $this->_milestone_result('already_claimed', 'Tier bonus ini sudah pernah diklaim.');
            }

            // ── 6. Gerbang sekuensial (MK7/O2). ──
            foreach ($cfg['tiers'] as $candidate) {
                $lower = (int) $candidate['target'];
                if ($lower >= $target) {
                    break;
                }
                if ( ! in_array($lower, $claimed, TRUE)) {
                    $this->db->trans_rollback();
                    return $this->_milestone_result('locked_previous', 'Klaim tier sebelumnya lebih dulu.');
                }
            }

            // ── 7. Recount kualifikasi (MK1 — di dalam TX terkunci). ──
            $total = $this->count_all_active_downlines($user_id);
            if ($total < $target) {
                $this->db->trans_rollback();
                return $this->_milestone_result(
                    'not_qualified',
                    'Jumlah downline aktif belum mencapai tier ini.',
                    ['missing' => (int) ($target - $total)]
                );
            }

            // ── 8. Stamp KONDISIONAL state klaim — gate anti double-claim (M4:
            //       perbandingan NULL-safe `<=>` + guard affected_rows).
            //       Nilai lama dipakai apa adanya (NULL tetap NULL) supaya
            //       baris kosong juga cocok. ──
            $claimed[] = $target;
            $new_csv   = team_milestone_claimed_encode($claimed);

            $this->db->query(
                "UPDATE users
                    SET team_milestone_claimed = ?
                  WHERE id = ?
                    AND (team_milestone_claimed <=> ?)",
                [$new_csv, $user_id, $user->team_milestone_claimed]
            );

            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                log_message('error', 'MK2 guard: klaim milestone user ' . $user_id
                    . ' target ' . $target . ' — affected_rows=' . $this->db->affected_rows()
                    . ' (race tak terduga walau baris sudah dikunci)');
                return $this->_milestone_result('already_claimed', 'Tier bonus ini sudah pernah diklaim.');
            }

            // ── 9. SATU kredit ledger ke SALDO UTAMA (MK3). transaction_id
            //       deterministik + UNIQUE (user_id, transaction_id, type) =
            //       guard tingkat DB lapis kedua (MK2). ──
            $tx_id       = team_milestone_claim_tx_id($user_id, $target);
            $description = 'Bonus Pencapaian Tim ' . $target . ' Downline Aktif';

            if ( ! $this->Wallet_model->credit(
                $user_id, (int) $tier['amount'], $tx_id, $description, Wallet_model::WALLET_MAIN
            )) {
                // PENTING: baca error SEBELUM rollback — statement ROLLBACK yang
                // SUKSES mereset errno koneksi, sehingga pembacaan setelahnya
                // kehilangan kode 1062 (pola Checkin_model::claim()/plan/112).
                $err = $this->db->error();
                $this->db->trans_rollback();

                if (in_array((int) $err['code'], [1062, 23000], TRUE)) {
                    return $this->_milestone_result('already_claimed', 'Tier bonus ini sudah pernah diklaim.');
                }

                log_message('error', 'MK3 guard: kredit milestone gagal user ' . $user_id
                    . ' target ' . $target . ' (code=' . $err['code'] . ', msg=' . $err['message'] . ')');
                return $this->_milestone_result('error', 'Sistem: Gagal memproses klaim. Coba lagi.');
            }

            if ($this->db->trans_status() === FALSE) {
                $this->db->trans_rollback();
                log_message('error', 'MK5 guard: trans_status FALSE (user=' . $user_id
                    . ', target=' . $target . ')');
                return $this->_milestone_result('error', 'Sistem: Gagal memproses klaim. Coba lagi.');
            }

            $this->db->trans_commit();

            return $this->_milestone_result(
                'claimed',
                'Bonus Pencapaian Tim untuk ' . $target . ' downline aktif berhasil diklaim!',
                [
                    'success'        => TRUE,
                    'amount'         => (int) $tier['amount'],
                    'target'         => $target,
                    'transaction_id' => $tx_id,
                ]
            );

        } catch (Throwable $e) {
            if ($this->db->trans_status() !== FALSE) {
                $this->db->trans_rollback();
            }
            log_message('error', 'User_model::claim_milestone user ' . $user_id . ' — ' . $e->getMessage());
            return $this->_milestone_result('error', 'Sistem: Gagal memproses klaim. Coba lagi.');
        } finally {
            $this->db->db_debug = $prev_debug;
        }
    }

    /**
     * Normalisasi hasil claim_milestone menjadi payload kontrak JSON.
     * Hanya code 'claimed' yang bermakna success=true.
     *
     * @param string $code    claimed|already_claimed|locked_previous|not_qualified|
     *                        invalid_target|disabled|user_unavailable|error
     * @param string $message Prosa diagnostik (presentasi lewat kamus — plan/103)
     * @param array  $extra   Override key payload (success, amount, target, ...)
     * @return array
     */
    private function _milestone_result($code, $message, array $extra = []) {
        return array_merge([
            'success'        => ($code === 'claimed'),
            'code'           => $code,
            'message'        => $message,
            'amount'         => NULL,
            'target'         => NULL,
            'missing'        => NULL,
            'transaction_id' => NULL,
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
