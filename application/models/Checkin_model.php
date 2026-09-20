<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Checkin_model — plan/112 (Absensi Harian / Daily Check-in).
 *
 * Tanggung jawab:
 *   1. Konfigurasi fitur (get_config)  : `system_settings` di atas fallback
 *      application/config/checkin_rewards.php — validasi per-key + invarian
 *      1 ≤ base ≤ max (fallback ATOMIK pasangan base/max bila dilanggar).
 *   2. Validator setelan admin (validate_checkin_settings) — kontrak sama
 *      dengan Wallet_model::validate_financial_settings (F12 plan/112).
 *   3. Status widget (get_status)      : read-only, 1 PK lookup.
 *   4. Jalur uang (claim)             : satu TX terkunci + SATU kredit lewat
 *      `Wallet_model::credit()` — tidak ada mutasi saldo di jalur lain (C4/M8).
 *
 * Invarian penting:
 *   - Otoritas "hari" = PHP WIB (`date('Y-m-d')`), BUKAN MySQL NOW()/CURDATE()
 *     (M2/M3). Timezone PHP sudah di-pin Asia/Jakarta di index.php + MY_Controller.
 *   - `transaction_id` = 'CHK-{user_id}-{Ymd}' → UNIQUE (user_id, transaction_id,
 *     type) di wallet_ledger = jaminan tingkat DB "satu kredit per user per hari".
 *   - Histori absensi TIDAK punya tabel sendiri: query `wallet_ledger` prefix
 *     'CHK-%' (terlayani idx_user_id). Tidak ada sumber kebenaran ganda.
 *   - Semua angka = INTEGER IDR utuh (M8); `_post()` menolak amount ≤ 0.
 */
class Checkin_model extends CI_Model {

    /** Ambang administratif (satu sumber: validator admin + CLI verify). */
    const BASE_MAX = 1000000;    // bonus hari ke-1 maksimum (IDR)
    const MAX_MAX  = 10000000;   // cap harian maksimum (IDR)

    /** Jumlah hari pratinjau pada widget dashboard. */
    const PREVIEW_DAYS = 7;

    /** @var array|null Cache konfigurasi per-request. */
    private static $_cfg = null;

    public function __construct() {
        parent::__construct();
    }

    // =====================================================================
    //  1. KONFIGURASI (setelan dinamis + fallback per-key)
    // =====================================================================

    /**
     * Konfigurasi absensi harian yang sudah tervalidasi & koheren.
     *
     * @return array{enabled:bool, base:int, max:int, policy:string}
     */
    public function get_config() {
        if (self::$_cfg !== null) {
            return self::$_cfg;
        }

        $fallback = require APPPATH . 'config/checkin_rewards.php';

        $map = [];
        $rows = $this->db->query(
            "SELECT key_name, key_value FROM system_settings
              WHERE key_name IN ('checkin_enabled', 'checkin_base_reward',
                                 'checkin_max_reward', 'checkin_streak_policy')"
        )->result();

        foreach ($rows as $row) {
            $map[$row->key_name] = $row->key_value;
        }

        self::$_cfg = $this->_resolve_config($fallback, $map);
        return self::$_cfg;
    }

    /**
     * Gabungkan baris dinamis di atas fallback dengan validasi per-key.
     * Nilai rusak → fallback key tersebut + log (tidak pernah fatal).
     */
    private function _resolve_config(array $fallback, array $map) {
        $cfg = [
            'enabled' => ((int) $fallback['checkin_enabled']) === 1,
            'base'    => max(1, (int) $fallback['checkin_base_reward']),
            'max'     => max(1, (int) $fallback['checkin_max_reward']),
            'policy'  => ($fallback['checkin_streak_policy'] === 'continue') ? 'continue' : 'reset',
        ];

        // Toggle: '0' | '1' persis.
        if (array_key_exists('checkin_enabled', $map)) {
            $raw = (string) $map['checkin_enabled'];
            if ($raw === '0' || $raw === '1') {
                $cfg['enabled'] = ($raw === '1');
            } else {
                log_message('error', 'Checkin_model: checkin_enabled tidak valid (' . $raw . ') — fallback dipakai');
            }
        }

        // Integer IDR positif (M8) — tanpa pecahan.
        $base = $this->_norm_positive_int(
            array_key_exists('checkin_base_reward', $map) ? $map['checkin_base_reward'] : null
        );
        $max = $this->_norm_positive_int(
            array_key_exists('checkin_max_reward', $map) ? $map['checkin_max_reward'] : null
        );

        // Invarian koherensi: 1 ≤ base ≤ max. Pelanggaran → BOUNDS dikembalikan
        // BERPASANGAN ke fallback (atomik), sehingga tidak pernah ada konfigurasi
        // yang membuat reward 0 / melebihi cap.
        if ($base !== null && $max !== null && $base <= $max) {
            $cfg['base'] = $base;
            $cfg['max']  = $max;
        } else {
            if ($base !== null || $max !== null) {
                log_message('error', 'Checkin_model: checkin_base_reward / checkin_max_reward '
                    . 'tidak koheren atau rusak — fallback dipakai (plan/112)');
            }
        }

        // Kebijakan streak: whitelist ketat; nilai asing → 'reset' (paling ketat).
        if (array_key_exists('checkin_streak_policy', $map)) {
            $policy = (string) $map['checkin_streak_policy'];
            if ($policy === 'reset' || $policy === 'continue') {
                $cfg['policy'] = $policy;
            } else {
                log_message('error', 'Checkin_model: checkin_streak_policy tidak valid (' . $policy . ') — fallback dipakai');
            }
        }

        return $cfg;
    }

    /** '^[1-9][0-9]*$' → int; selain itu NULL. */
    private function _norm_positive_int($raw) {
        if (!is_scalar($raw)) {
            return null;
        }
        $raw = trim((string) $raw);
        if (!preg_match('/^[1-9][0-9]*$/', $raw)) {
            return null;
        }
        $value = (int) $raw;

        return ($value > 0) ? $value : null;
    }

    // =====================================================================
    //  2. VALIDATOR SETELAN ADMIN (kontrak F12 plan/112)
    // =====================================================================

    /**
     * Normalisasi + validasi input form admin. Pesan 100% Indonesia (L1).
     *
     * @param array $raw Map key system_settings → nilai mentah $_POST.
     * @return array{ok:bool, errors:string[], notices:string[],
     *               field_errors:array<string,string[]>, values:array<string,string>}
     */
    public function validate_checkin_settings(array $raw) {
        $errors       = [];
        $field_errors = [];
        $values       = [];

        // Toggle (semantik checkbox: tidak dikirim = OFF).
        $values['checkin_enabled'] = (isset($raw['checkin_enabled']) && !empty($raw['checkin_enabled'])) ? '1' : '0';

        // Bonus hari ke-1.
        $base = isset($raw['checkin_base_reward'])
            ? $this->_norm_positive_int($raw['checkin_base_reward'])
            : null;
        $base_valid = ($base !== null && $base <= self::BASE_MAX);
        if (!$base_valid) {
            $message = 'Bonus hari pertama harus angka bulat 1–' . self::BASE_MAX . ' (IDR).';
            $errors[] = $message;
            $field_errors['checkin_base_reward'][] = $message;
        } else {
            $values['checkin_base_reward'] = (string) $base;
        }

        // Cap harian.
        $max = isset($raw['checkin_max_reward'])
            ? $this->_norm_positive_int($raw['checkin_max_reward'])
            : null;
        $max_valid = ($max !== null && $max <= self::MAX_MAX);
        if (!$max_valid) {
            $message = 'Batas harian harus angka bulat 1–' . self::MAX_MAX . ' (IDR).';
            $errors[] = $message;
            $field_errors['checkin_max_reward'][] = $message;
        } else {
            $values['checkin_max_reward'] = (string) $max;
        }

        // Invarian 1 ≤ base ≤ max (dinilai hanya bila keduanya sudah valid).
        if ($base_valid && $max_valid && $base > $max) {
            $message = 'Bonus hari pertama tidak boleh melebihi batas harian.';
            $errors[] = $message;
            $field_errors['checkin_base_reward'][] = $message;
            $field_errors['checkin_max_reward'][]  = $message;
        }

        // Kebijakan streak.
        $policy = isset($raw['checkin_streak_policy']) ? (string) $raw['checkin_streak_policy'] : '';
        if ($policy !== 'reset' && $policy !== 'continue') {
            $message = 'Kebijakan streak harus dipilih (Reset ke Hari 1 atau Lanjutkan).';
            $errors[] = $message;
            $field_errors['checkin_streak_policy'][] = $message;
        } else {
            $values['checkin_streak_policy'] = $policy;
        }

        return [
            'ok'           => count($errors) === 0,
            'errors'       => $errors,
            'notices'      => [],
            'field_errors' => $field_errors,
            'values'       => $values,
        ];
    }

    // =====================================================================
    //  3. STATUS WIDGET (read-only)
    // =====================================================================

    /**
     * Status absensi satu user untuk widget dashboard.
     *
     * @param int $user_id
     * @return array{
     *   enabled:bool, claimed_today:bool, streak:int, last_date:string|null,
     *   today_streak:int, today_reward:int, next_streak:int, next_reward:int,
     *   base_reward:int, max_reward:int, policy:string, server_date:string,
     *   next_window_ts:int, max_today_reached:bool,
     *   preview:array<int,array{day:int,reward:int,is_next:bool}>
     * }
     */
    public function get_status($user_id) {
        $cfg = $this->get_config();

        $out = [
            'enabled'            => $cfg['enabled'],
            'claimed_today'      => false,
            'streak'             => 0,
            'last_date'          => null,
            'today_streak'       => 1,
            'today_reward'       => $cfg['base'],
            'next_streak'        => 1,
            'next_reward'        => $cfg['base'],
            'base_reward'        => $cfg['base'],
            'max_reward'         => $cfg['max'],
            'policy'             => $cfg['policy'],
            'server_date'        => date('Y-m-d'),
            'next_window_ts'     => mktime(0, 0, 0, (int) date('n'), (int) date('j') + 1, (int) date('Y')),
            'max_today_reached'  => false,
            'preview'            => [],
        ];

        // Fail-closed: fitur OFF → tidak ada state yang perlu dibaca.
        if (!$cfg['enabled']) {
            $out['streak']       = 0;
            $out['today_reward'] = 0;
            $out['next_reward']  = 0;
            $out['today_streak'] = 0;
            $out['next_streak']  = 0;
            return $out;
        }

        $row = $this->db->query(
            "SELECT checkin_streak, checkin_last_date FROM users WHERE id = ?",
            [(int) $user_id]
        )->row();

        $streak = $row ? (int) $row->checkin_streak : 0;
        $last   = ($row && $row->checkin_last_date !== null) ? (string) $row->checkin_last_date : null;
        $today  = $out['server_date'];

        // Klaim berikutnya (null = tidak boleh klaim hari ini: sudah klaim / data
        // masa depan → fail-closed, sama seperti keputusan server di claim()).
        $next_claimable = $this->_streak_next($streak, $last, $today, $cfg['policy']);
        $claimed_today  = ($next_claimable === null);

        $out['claimed_today'] = $claimed_today;
        $out['streak']        = $streak;
        $out['last_date']     = $last;

        if ($claimed_today) {
            // Hari ini SUDAH dibayar: hari ke-`streak`, berikutnya = streak+1.
            $out['today_streak']  = $streak;
            $out['today_reward']  = $this->_reward_for($streak, $cfg['base'], $cfg['max']);
            $out['next_streak']   = $streak + 1;
            $out['next_reward']   = $this->_reward_for($streak + 1, $cfg['base'], $cfg['max']);
        } else {
            // Hari ini BELUM dibayar: `next_claimable` = hari yang akan dibayar.
            $out['today_streak']  = $next_claimable;
            $out['today_reward']  = $this->_reward_for($next_claimable, $cfg['base'], $cfg['max']);
            $out['next_streak']   = $next_claimable;
            $out['next_reward']   = $out['today_reward'];
        }

        $out['max_today_reached'] = ($out['today_reward'] >= $cfg['max']);

        // Pratinjau 7 klaim ke depan (mulai dari klaim berikutnya) — angka dihitung
        // di server (view tidak pernah melakukan aritmetika uang).
        $preview = [];
        for ($i = 0; $i < self::PREVIEW_DAYS; $i++) {
            $day = $out['next_streak'] + $i;
            $preview[] = [
                'day'     => $day,
                'reward'  => $this->_reward_for($day, $cfg['base'], $cfg['max']),
                'is_next' => ($i === 0),
            ];
        }
        $out['preview'] = $preview;

        return $out;
    }

    // =====================================================================
    //  4. JALUR UANG: KLAIM (satu TX + satu kredit)
    // =====================================================================

    /**
     * Klaim bonus absensi harian (idempotent per hari WIB).
     *
     * Urutan wajib di dalam TX (pola C5 + claim_wage):
     *   1. anchor `SELECT ... FROM users ... FOR UPDATE` (statement pertama),
     *   2. hitung hari yang berhak (gap → kebijakan 'reset' | 'continue'),
     *   3. UPDATE users KONDISIONAL + `affected_rows() === 1` (M4),
     *   4. SATU kredit lewat Wallet_model::credit() dengan transaction_id
     *      deterministik 'CHK-{user_id}-{Ymd}' (F2/F3 plan/112).
     *
     * @param int $user_id
     * @return array{success:bool, code:string, message:string, reward:int,
     *               streak:int, transaction_id:string}
     *   code: 'ok' | 'disabled' | 'already_claimed' | 'user_unavailable' | 'error'
     */
    public function claim($user_id) {
        $fail = function ($code, $message) {
            return [
                'success'        => false,
                'code'           => $code,
                'message'        => $message,
                'reward'         => 0,
                'streak'         => 0,
                'transaction_id' => '',
            ];
        };

        $cfg = $this->get_config();

        // Fail-closed: fitur OFF → tidak ada TX, tidak ada uang.
        if (!$cfg['enabled']) {
            return $fail('disabled', 'Absensi harian sedang dinonaktifkan.');
        }

        $this->load->model('Wallet_model');

        $user_id = (int) $user_id;

        // CI3 (non-production) merender halaman error mentah saat query gagal DI
        // DALAM transaksi — termasuk duplicate key 1062. Jalur AJAX TIDAK BOLEH
        // mengembalikan HTML (M9/P7), jadi db_debug dimatikan lokal lalu
        // dipulihkan (pola Auth.php:229-238 / Admin.php:1558-1573).
        $prev_debug         = $this->db->db_debug;
        $this->db->db_debug = FALSE;

        try {
            $this->db->trans_begin();

            // 1. ANCHOR + state terkunci.
            $row = $this->db->query(
                "SELECT id, checkin_streak, checkin_last_date, is_banned
                   FROM users WHERE id = ? FOR UPDATE",
                [$user_id]
            )->row();

            if (!$row || (int) $row->is_banned === 1) {
                $this->db->trans_rollback();
                return $fail('user_unavailable', 'Akun tidak memenuhi syarat untuk bonus saat ini.');
            }

            $streak = (int) $row->checkin_streak;
            $last   = ($row->checkin_last_date !== null) ? (string) $row->checkin_last_date : null;
            $today  = date('Y-m-d');

            // 2. Hari yang berhak (null = sudah klaim / tanggal masa depan).
            $new_streak = $this->_streak_next($streak, $last, $today, $cfg['policy']);
            if ($new_streak === null) {
                $this->db->trans_rollback();
                return $fail('already_claimed', 'Anda sudah mengklaim bonus hari ini.');
            }

            // 3. Nominal (integer IDR utuh; _post() menolak ≤ 0 sebagai jaring terakhir).
            $reward = $this->_reward_for($new_streak, $cfg['base'], $cfg['max']);
            if ($reward < 1) {
                $this->db->trans_rollback();
                log_message('error', 'Checkin_model::claim — reward tidak valid (user=' . $user_id
                    . ', day=' . $new_streak . ', base=' . $cfg['base'] . ', max=' . $cfg['max'] . ')');
                return $fail('error', 'Gagal memproses bonus. Silakan coba lagi.');
            }

            // 4. Stempel KONDISIONAL: hanya menang bila state belum berubah
            //    (termasuk di dalam lock — sabuk tambahan terhadap double-submit).
            $this->db->query(
                "UPDATE users SET checkin_streak = ?, checkin_last_date = ?
                  WHERE id = ? AND (checkin_last_date IS NULL OR checkin_last_date < ?)",
                [$new_streak, $today, $user_id, $today]
            );

            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                return $fail('already_claimed', 'Anda sudah mengklaim bonus hari ini.');
            }

            // 5. SATU kredit ledger — ID deterministik per hari (jaminan UNIQUE DB).
            $tx_id       = 'CHK-' . $user_id . '-' . date('Ymd');
            $description = 'Bonus Absensi Harian Hari ke-' . $new_streak;

            if (!$this->Wallet_model->credit($user_id, $reward, $tx_id, $description)) {
                // PENTING: baca error SEBELUM rollback — statement ROLLBACK yang
                // SUKSES mereset errno koneksi, sehingga pembacaan setelahnya
                // kehilangan kode 1062 (urutan sama dengan
                // Wallet_model::create_deposit, plan/102).
                $err = $this->db->error();
                $this->db->trans_rollback();

                // Duplicate key = TX lain sudah membayar hari ini (race yang lolos
                // dari lock) → hasilnya SAMA dengan sudah klaim, bukan error.
                if (in_array((int) $err['code'], [1062, 23000], true)) {
                    return $fail('already_claimed', 'Anda sudah mengklaim bonus hari ini.');
                }

                log_message('error', 'Checkin_model::claim — kredit gagal (user=' . $user_id
                    . ', code=' . $err['code'] . ', msg=' . $err['message'] . ')');
                return $fail('error', 'Gagal memproses bonus. Silakan coba lagi.');
            }

            if ($this->db->trans_status() === false) {
                $this->db->trans_rollback();
                log_message('error', 'Checkin_model::claim — trans_status FALSE (user=' . $user_id . ')');
                return $fail('error', 'Gagal memproses bonus. Silakan coba lagi.');
            }

            $this->db->trans_commit();

            return [
                'success'        => true,
                'code'           => 'ok',
                'message'        => '',
                'reward'         => $reward,
                'streak'         => $new_streak,
                'transaction_id' => $tx_id,
            ];
        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Checkin_model::claim — ' . $e->getMessage());
            return $fail('error', 'Gagal memproses bonus. Silakan coba lagi.');
        } finally {
            $this->db->db_debug = $prev_debug;
        }
    }

    // =====================================================================
    //  FUNGSI MURNI (tanpa I/O — mudah ditelaah & diuji)
    // =====================================================================

    /**
     * Hari streak yang berhak dibayar berikutnya.
     *
     * @param int         $streak    Hari beruntun terakhir yang dibayar (0 = belum pernah).
     * @param string|null $last_date Tanggal klaim terakhir ('Y-m-d' WIB) atau NULL.
     * @param string      $today     Tanggal WIB hari ini.
     * @param string      $policy    'reset' | 'continue'.
     * @return int|null Hari yang akan dibayar, atau NULL bila hari ini TIDAK berhak
     *   (sudah diklaim, atau `last_date` di masa depan → fail-closed).
     */
    public function _streak_next($streak, $last_date, $today, $policy) {
        $streak = max(0, (int) $streak);
        $last   = trim((string) $last_date);

        if ($last === '') {
            return 1;                       // pemain baru
        }

        // Fail-closed: data masa depan (clock skew / baris diubah manual / TZ)
        // diperlakukan sebagai "sudah diklaim" — streak tidak dimajukan.
        if ($last > $today) {
            return null;
        }

        if ($last === $today) {
            return null;                    // E1: sudah klaim hari ini
        }

        $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));

        if ($last === $yesterday) {
            return $streak + 1;             // beruntun
        }

        // Gap (bolong ≥ 1 hari): kebijakan menentukan.
        //   continue → lanjut N+1 (gap tidak mereset, hari terlewat tidak dihitung)
        //   reset    → mulai lagi dari hari 1
        return ($policy === 'continue') ? ($streak + 1) : 1;
    }

    /**
     * Bonus untuk hari ke-`day` = min(base × day, cap). Integer IDR (M8).
     */
    public function _reward_for($day, $base, $max) {
        $day  = max(1, (int) $day);
        $base = max(1, (int) $base);
        $max  = max(1, (int) $max);

        return min($base * $day, $max);
    }

    /**
     * Jumlah klaim seumur hidup (turunan ledger — tidak ada kolom total).
     *
     * @param int $user_id
     * @return int
     */
    public function get_lifetime_claims($user_id) {
        $row = $this->db->query(
            "SELECT COUNT(*) AS c FROM wallet_ledger
              WHERE user_id = ? AND transaction_id LIKE 'CHK-%'",
            [(int) $user_id]
        )->row();

        return $row ? (int) $row->c : 0;
    }
}
