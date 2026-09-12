<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Wallet_model extends CI_Model {

    /** @var array|null Per-request cache of merged financial config (M1, plan/56). */
    private static $_fin_cfg = null;

    /** @var array|null Per-request cache of deposit policy (plan/102). */
    private static $_dep_policy = null;

    /**
     * plan/102: rentang kode unik 3 digit (keputusan pemilik repositori D2).
     * Tanpa leading zero → tidak ada ambiguitas "045" vs "+45" saat member
     * mengetik nominal transfer. 900 kode per nominal pokok.
     */
    const UNIQUE_CODE_MIN = 100;
    const UNIQUE_CODE_MAX = 999;

    public function __construct() {
        parent::__construct();

        // M1 (plan/56 §3) + M2 (plan/58 §3 Phase 2): harmonize the MySQL
        // session to Asia/Jakarta so TIMESTAMP read-back, NOW()/CURDATE() and
        // conversions agree with the PHP clock (index.php sets
        // date_default_timezone_set('Asia/Jakarta')). Numeric offset '+07:00'
        // needs no server timezone tables.
        //
        // M2: pin TANPA guard `conn_id` — CI3 lazy-connect berarti conn_id
        // masih FALSE ketika konstruktor model pertama dijalankan, sehingga
        // guard lama TIDAK PERNAH mengeksekusi SET pada koneksi baru (skew
        // UTC-vs-WIB pada users.last_wage_claimed_at TIMESTAMP, lihat plan/58
        // §2.3). query() ini sekaligus memaksa koneksi pertama DAN menerapkan
        // pin sebelum statement lain; idempotent per request pada koneksi CI
        // tunggal. Entry point web juga mem-pin lebih awal (MY_Controller,
        // Auth, Admin, Admin_auth) — konstruktor ini backstop untuk CLI/cron.
        $this->db->query("SET time_zone = '+07:00'");
    }

    // =====================================================================
    // M1 (plan/56 §2): DYNAMIC FINANCIAL CONFIGURATION
    // =====================================================================
    // `system_settings` (key_value TEXT) is the dynamic, admin-operable
    // source of truth; application/config/withdrawal_fees.php is the FALLBACK
    // used per-key when a dynamic row is missing or invalid. Validation keeps
    // the merged object internally coherent (tiers ↔ min/max bundle), and a
    // corrupt dynamic value never crashes the request — it logs + falls back.

    /**
     * Merged financial config (per-request static cache).
     *
     * @return array{
     *   operational_days:string, open_time:string, close_time:string,
     *   fixed_fee:int, tiers:array, min_amount:int, max_amount:int,
     *   deposit_fee_enabled:int, deposit_fee_type:string, deposit_fee_value:int|float
     * }
     */
    public function get_financial_config() {
        if (self::$_fin_cfg !== null) {
            return self::$_fin_cfg;
        }

        $fallback = require APPPATH . 'config/withdrawal_fees.php';

        $map = [];
        foreach ($this->db->select('key_name, key_value')->get('system_settings')->result() as $row) {
            $map[$row->key_name] = $row->key_value;
        }

        self::$_fin_cfg = $this->_resolve_financial_config($fallback, $map);
        return self::$_fin_cfg;
    }

    /**
     * Merge dynamic rows over the fallback with per-key validation.
     */
    private function _resolve_financial_config(array $fallback, array $map) {
        $cfg = $fallback;

        // 1) Hari operasional: CSV 1..7 (1 = Senin).
        $days = $this->_norm_days_csv(isset($map['wd_operational_days']) ? $map['wd_operational_days'] : null);
        if ($days !== null) {
            $cfg['operational_days'] = $days;
        }

        // 2) Jam operasional: pasangan HH:MM valid dengan open < close.
        $open  = $this->_norm_time(isset($map['wd_open_time'])  ? $map['wd_open_time']  : null);
        $close = $this->_norm_time(isset($map['wd_close_time']) ? $map['wd_close_time'] : null);
        if ($open !== null && $close !== null && $open < $close) {
            $cfg['open_time']  = $open;
            $cfg['close_time'] = $close;
        }

        // 3) Biaya tetap penarikan (int >= 0).
        $fixed = $this->_norm_int(isset($map['wd_fixed_fee']) ? $map['wd_fixed_fee'] : null, 0);
        if ($fixed !== null) {
            $cfg['fixed_fee'] = $fixed;
        }

        // 4) Tier JSON half-open [min,max,bps], sorted & contiguous.
        $tiers = $this->_norm_tiers(isset($map['wd_fee_tiers']) ? $map['wd_fee_tiers'] : null);
        if ($tiers !== null) {
            $cfg['tiers'] = $tiers;
        }

        // 5) Batas min/max — coherence bundle: bounds wajib berada dalam
        //    rentang tier [first.min, last.max). Pelanggaran → kembalikan
        //    BOUNDS + TIERS sekaligus ke fallback (atomik), sehingga tidak
        //    pernah ada rentang nominal tanpa tier.
        $min = $this->_norm_int(isset($map['wd_min_amount']) ? $map['wd_min_amount'] : null, 1);
        $max = $this->_norm_int(isset($map['wd_max_amount']) ? $map['wd_max_amount'] : null, 1);
        $firstMin = $cfg['tiers'][0][0];
        $lastMax  = end($cfg['tiers'])[1];
        if ($min !== null && $max !== null && $min < $max && $min >= $firstMin && $max < $lastMax) {
            $cfg['min_amount'] = $min;
            $cfg['max_amount'] = $max;
        } else {
            if ($min !== null || $max !== null || $tiers !== null) {
                log_message('error', 'Wallet_model: wd_fee_tiers / wd_min_amount / wd_max_amount tidak koheren — fallback dipakai (plan/56 §2.3)');
            }
            $cfg['min_amount'] = $fallback['min_amount'];
            $cfg['max_amount'] = $fallback['max_amount'];
        }

        // 6) Biaya deposit (toggle / tipe / nilai).
        $depEnabled = isset($map['deposit_fee_enabled']) ? $map['deposit_fee_enabled'] : null;
        if ($depEnabled === '0' || $depEnabled === '1') {
            $cfg['deposit_fee_enabled'] = (int) $depEnabled;
        }

        $depType = isset($map['deposit_fee_type']) ? $map['deposit_fee_type'] : null;
        if ($depType === 'flat' || $depType === 'percent') {
            $cfg['deposit_fee_type'] = $depType;
        }

        $depVal = isset($map['deposit_fee_value']) ? $map['deposit_fee_value'] : null;
        if ($cfg['deposit_fee_type'] === 'flat') {
            $v = $this->_norm_int($depVal, 0);
            if ($v !== null) {
                $cfg['deposit_fee_value'] = $v;
            }
        } else {
            $v = $this->_norm_pct($depVal);
            if ($v !== null) {
                $cfg['deposit_fee_value'] = $v;
            }
        }

        return $cfg;
    }

    /** CSV '1..7' → sorted unique CSV, or null bila invalid. */
    private function _norm_days_csv($raw) {
        if (!is_string($raw)) {
            return null;
        }
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (!preg_match('/^[1-7]$/', $part)) {
                return null;
            }
            $out[] = (int) $part;
        }
        $out = array_values(array_unique($out));
        sort($out);
        return count($out) > 0 ? implode(',', $out) : null;
    }

    /** 'HH:MM' valid, atau null. */
    private function _norm_time($raw) {
        if (!is_string($raw) || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $raw)) {
            return null;
        }
        return $raw;
    }

    /** Integer >= $min (digit-only), atau null. */
    private function _norm_int($raw, $min) {
        if ($raw === null) {
            return null;
        }
        $s = preg_replace('/[^0-9]/', '', (string) $raw);
        if ($s === '' || (int) $s < $min) {
            return null;
        }
        return (int) $s;
    }

    /** Persen 0..100 (2 desimal), atau null. */
    private function _norm_pct($raw) {
        if (!is_string($raw) || trim($raw) === '' || !is_numeric(trim($raw))) {
            return null;
        }
        $v = (float) trim($raw);
        if ($v < 0 || $v > 100) {
            return null;
        }
        return round($v, 2);
    }

    /**
     * JSON [[min,max,bps],...] half-open, sorted & contiguous — atau null.
     * bps scale: 10% = 1000.
     */
    private function _norm_tiers($rawJson) {
        if (!is_string($rawJson) || trim($rawJson) === '') {
            return null;
        }
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded) || count($decoded) === 0) {
            return null;
        }
        $tiers   = [];
        $prevMax = null;
        foreach ($decoded as $t) {
            if (!is_array($t) || count($t) !== 3) {
                return null;
            }
            $min = (int) $t[0];
            $max = (int) $t[1];
            $bps = (int) $t[2];
            if ($min < 0 || $max <= $min || $bps < 0 || $bps > 10000) {
                return null;
            }
            if ($prevMax !== null && $min !== $prevMax) {
                return null; // harus kontigu [min, max)
            }
            $tiers[] = [$min, $max, $bps];
            $prevMax = $max;
        }
        return $tiers;
    }

    /**
     * Validasi input admin (plan/56 §4.1) untuk halaman
     * admin/financial-settings. Aturan identik dengan normalizer model
     * (fallback chain), tapi dengan pesan error eksplisit per field dan
     * aturan save-time: tier pertama wajib mulai dari nominal minimal &
     * batas atas tier terakhir harus DI ATAS nominal maksimal.
     *
     * @param array $raw Map key system_settings → nilai mentah dari $_POST.
     * @return array{ok:bool, errors:string[], values:array<string,string>}
     *   values berisi key yang valid & ternormalisasi (siap set_setting).
     */
    public function validate_financial_settings(array $raw) {
        $errors = [];
        $values = [];

        // ── Hari operasional: checkbox array atau CSV.
        $daysRaw = isset($raw['wd_operational_days']) ? $raw['wd_operational_days'] : null;
        $days = null;
        if (is_array($daysRaw)) {
            $days = $this->_norm_days_csv(implode(',', $daysRaw));
        } elseif (is_string($daysRaw) && trim($daysRaw) !== '') {
            $days = $this->_norm_days_csv($daysRaw);
        }
        if ($days === null) {
            $errors[] = 'Pilih minimal 1 hari operasional (Senin–Minggu).';
        } else {
            $values['wd_operational_days'] = $days;
        }

        // ── Jam operasional: HH:MM, open < close.
        $open  = isset($raw['wd_open_time'])  ? $this->_norm_time(trim((string) $raw['wd_open_time']))  : null;
        $close = isset($raw['wd_close_time']) ? $this->_norm_time(trim((string) $raw['wd_close_time'])) : null;
        if ($open === null || $close === null) {
            $errors[] = 'Jam operasional harus berformat HH:MM (contoh: 07:00).';
        } elseif ($open >= $close) {
            $errors[] = 'Jam buka harus lebih awal dari jam tutup.';
        } else {
            $values['wd_open_time']  = $open;
            $values['wd_close_time'] = $close;
        }

        // ── Biaya tetap penarikan.
        $fixed = isset($raw['wd_fixed_fee']) ? $this->_norm_int((string) $raw['wd_fixed_fee'], 0) : null;
        if ($fixed === null || $fixed > 100000) {
            $errors[] = 'Biaya tetap penarikan harus angka bulat 0–100000.';
        } else {
            $values['wd_fixed_fee'] = (string) $fixed;
        }

        // ── Batas min/max.
        $min = isset($raw['wd_min_amount']) ? $this->_norm_int((string) $raw['wd_min_amount'], 1) : null;
        $max = isset($raw['wd_max_amount']) ? $this->_norm_int((string) $raw['wd_max_amount'], 1) : null;
        if ($min === null || $max === null || $min >= $max) {
            $errors[] = 'Minimal & maksimal penarikan tidak valid (min harus lebih kecil dari max).';
        } else {
            $values['wd_min_amount'] = (string) $min;
            $values['wd_max_amount'] = (string) $max;
        }

        // ── Tier JSON.
        $tiersRaw = isset($raw['wd_fee_tiers']) ? $raw['wd_fee_tiers'] : null;
        $tiers    = $this->_norm_tiers(is_string($tiersRaw) ? $tiersRaw : null);
        if ($tiers === null) {
            $errors[] = 'Tier biaya tidak valid: butuh JSON [[min,max,bps],...] terurut & kontigu (max baris ini = min baris berikutnya).';
        } else {
            $first = $tiers[0];
            $last  = end($tiers);
            if ($min !== null && $first[0] !== $min) {
                $errors[] = 'Tier pertama harus dimulai dari nominal minimal penarikan (Rp ' . number_format($min, 0, ',', '.') . ').';
            }
            if ($max !== null && $last[1] <= $max) {
                $errors[] = 'Batas atas tier terakhir harus di atas nominal maksimal penarikan (Rp ' . number_format($max, 0, ',', '.') . ').';
            }
            $values['wd_fee_tiers'] = json_encode($tiers);
        }

        // ── Biaya deposit.
        $depEnabled = (isset($raw['deposit_fee_enabled']) && !empty($raw['deposit_fee_enabled'])) ? '1' : '0';
        $values['deposit_fee_enabled'] = $depEnabled;

        $depType = isset($raw['deposit_fee_type']) ? (string) $raw['deposit_fee_type'] : 'flat';
        if ($depType !== 'flat' && $depType !== 'percent') {
            $errors[] = 'Tipe biaya deposit harus flat atau percent.';
            $depType = 'flat';
        }
        $values['deposit_fee_type'] = $depType;

        $depValRaw = isset($raw['deposit_fee_value']) ? (string) $raw['deposit_fee_value'] : '';
        if ($depType === 'flat') {
            $depVal = $this->_norm_int($depValRaw, 0);
            if ($depVal === null || $depVal > 100000) {
                $errors[] = 'Biaya deposit flat harus angka bulat 0–100000 (IDR).';
            } else {
                $values['deposit_fee_value'] = (string) $depVal;
            }
        } else {
            $depVal = $this->_norm_pct($depValRaw);
            if ($depVal === null || $depVal > 5) {
                $errors[] = 'Biaya deposit persen maksimal 5% (contoh: 0.70 = 0.70%).';
            } else {
                $values['deposit_fee_value'] = (string) $depVal;
            }
        }

        return ['ok' => count($errors) === 0, 'errors' => $errors, 'values' => $values];
    }

    // =====================================================================
    // plan/102: KEBIJAKAN DEPOSIT QRIS MANUAL
    // =====================================================================
    // Sumber: `system_settings` (dinamis, dapat dioperasikan admin) di atas
    // fallback application/config/withdrawal_fees.php — semantik identik M1:
    // nilai dinamis hilang/rusak TIDAK pernah membuat request gagal, hanya
    // di-log lalu fallback dipakai.

    /**
     * Kebijakan deposit (per-request static cache).
     *
     * @return array{expiry_minutes:int, min_amount:int, max_amount:int}
     */
    public function get_deposit_policy() {
        if (self::$_dep_policy !== null) {
            return self::$_dep_policy;
        }

        $fallback = require APPPATH . 'config/withdrawal_fees.php';

        $map = [];
        foreach ($this->db->select('key_name, key_value')->get('system_settings')->result() as $row) {
            $map[$row->key_name] = $row->key_value;
        }

        $policy = [
            'expiry_minutes' => (int) (isset($fallback['deposit_expiry_minutes']) ? $fallback['deposit_expiry_minutes'] : 60),
            'min_amount'     => (int) (isset($fallback['deposit_min_amount'])     ? $fallback['deposit_min_amount']     : 10000),
            'max_amount'     => (int) (isset($fallback['deposit_max_amount'])     ? $fallback['deposit_max_amount']     : 50000000),
        ];

        // Jendela bayar: 5–1440 menit.
        if (isset($map['deposit_expiry_minutes'])) {
            $exp = $this->_norm_int($map['deposit_expiry_minutes'], 5);
            if ($exp !== null && $exp <= 1440) {
                $policy['expiry_minutes'] = $exp;
            } else {
                log_message('error', 'Wallet_model: deposit_expiry_minutes tidak valid — fallback dipakai (plan/102)');
            }
        }

        // Batas nominal: bundle koheren (min < max, max ≤ 1e9) — pelanggaran
        // mengembalikan KEDUANYA ke fallback sekaligus (atomik).
        $min = isset($map['deposit_min_amount']) ? $this->_norm_int($map['deposit_min_amount'], 1) : null;
        $max = isset($map['deposit_max_amount']) ? $this->_norm_int($map['deposit_max_amount'], 1) : null;
        if ($min !== null && $max !== null && $min < $max && $max <= 1000000000) {
            $policy['min_amount'] = $min;
            $policy['max_amount'] = $max;
        } elseif ($min !== null || $max !== null) {
            log_message('error', 'Wallet_model: deposit_min_amount/deposit_max_amount tidak koheren — fallback dipakai (plan/102)');
        }

        self::$_dep_policy = $policy;
        return $policy;
    }

    /**
     * Validasi input admin untuk kartu "Pembayaran QRIS Manual"
     * (plan/102 §7.2) — all-or-nothing, pesan error eksplisit per field.
     *
     * Cakupan: konten QRIS (nama merchant, instruksi) + kebijakan deposit
     * (expiry, min, max). Nama file gambar TIDAK divalidasi di sini (ditangani
     * controller setelah upload sukses).
     *
     * @param array $raw Map key system_settings → nilai mentah dari $_POST.
     * @return array{ok:bool, errors:string[], values:array<string,string>}
     */
    public function validate_deposit_settings(array $raw) {
        $errors = [];
        $values = [];

        // ── Nama merchant: wajib, 1–100 karakter.
        $merchant = isset($raw['qris_merchant_name']) ? trim((string) $raw['qris_merchant_name']) : '';
        if ($merchant === '' || mb_strlen($merchant) > 100) {
            $errors[] = 'Nama merchant QRIS wajib diisi (1–100 karakter).';
        } else {
            $values['qris_merchant_name'] = $merchant;
        }

        // ── Instruksi pembayaran: opsional, ≤ 2000 karakter.
        $instructions = isset($raw['qris_payment_instructions']) ? trim((string) $raw['qris_payment_instructions']) : '';
        if (mb_strlen($instructions) > 2000) {
            $errors[] = 'Instruksi pembayaran maksimal 2000 karakter.';
        } else {
            $values['qris_payment_instructions'] = $instructions;
        }

        // ── Jendela bayar: 5–1440 menit.
        $expiry = isset($raw['deposit_expiry_minutes']) ? $this->_norm_int((string) $raw['deposit_expiry_minutes'], 5) : null;
        if ($expiry === null || $expiry > 1440) {
            $errors[] = 'Masa berlaku deposit harus angka bulat 5–1440 menit.';
        } else {
            $values['deposit_expiry_minutes'] = (string) $expiry;
        }

        // ── Batas nominal deposit.
        $min = isset($raw['deposit_min_amount']) ? $this->_norm_int((string) $raw['deposit_min_amount'], 1) : null;
        $max = isset($raw['deposit_max_amount']) ? $this->_norm_int((string) $raw['deposit_max_amount'], 1) : null;
        if ($min === null || $max === null || $min >= $max || $max > 1000000000) {
            $errors[] = 'Minimal & maksimal deposit tidak valid (min harus lebih kecil dari max, maksimal Rp 1.000.000.000).';
        } else {
            $values['deposit_min_amount'] = (string) $min;
            $values['deposit_max_amount'] = (string) $max;
        }

        return ['ok' => count($errors) === 0, 'errors' => $errors, 'values' => $values];
    }

    /**
     * PRD fee tier calculation (plan/52 §1.4, dec-30928987d7ae1c74) —
     * reads the DYNAMIC merged config (plan/56 M1).
     * Half-open tiers [min, max): boundary amount belongs to the higher tier.
     * Integer IDR math (M8 plan/74 §2.3): fee = floor(gross*bps/10000) +
     * fixed_fee via integer division — `intdiv` == floor untuk operand
     * non-negatif (gross ≥ 1, bps ≥ 0), TANPA jalur float.
     *
     * @param int $gross Nominal penarikan (gross_amount == amount).
     * @return array{fee:int, net:int, bps:int} fee_amount & net_amount (gross − fee).
     */
    public function calculate_withdrawal_fee($gross) {
        $gross = (int) $gross;
        $cfg   = $this->get_financial_config();
        $bps   = end($cfg['tiers'])[2]; // fallback: tier tertinggi
        foreach ($cfg['tiers'] as $tier) {
            if ($gross >= $tier[0] && $gross < $tier[1]) {
                $bps = $tier[2];
                break;
            }
        }
        // gross ≤ wd_max (5e7 seed) & bps ≤ 10000 → gross*bps ≤ 5e11 < PHP_INT_MAX.
        $fee = intdiv($gross * $bps, 10000) + (int) $cfg['fixed_fee'];
        return ['fee' => $fee, 'net' => $gross - $fee, 'bps' => $bps];
    }

    /**
     * Deposit fee (M1 plan/56 §2.1): user pays amount + fee, wallet credit
     * stays pure principal. flat → fee = value IDR; percent → value in
     * percentage points (0.70 = 0.70%).
     *
     * M8 (plan/74 §2.3): basis-point INTEGER math — persen (≤2 desimal)
     * dikonversi SEKALI ke basis point integer (0.70% = 70 bp) via
     * round(pct*100) — round() hanya untuk konversi unit konfigurasi, TIDAK
     * pernah pada uang. fee = floor(amount * bp / 10000) via intdiv.
     *
     * @param int $amount Principal nominal (IDR bulat).
     * @return int Fee IDR (0 saat deposit fee non-aktif).
     */
    public function calculate_deposit_fee($amount) {
        $cfg = $this->get_financial_config();
        if (empty($cfg['deposit_fee_enabled'])) {
            return 0;
        }
        $amount = (int) $amount;
        if ($cfg['deposit_fee_type'] === 'flat') {
            return (int) $cfg['deposit_fee_value'];
        }
        // 0.70 (%) → 70 bp. round() menghindari truncation gotcha
        // `(int)(0.7*100) === 69` akibat representasi biner float.
        $bps = (int) round(((float) $cfg['deposit_fee_value']) * 100);
        return intdiv($amount * $bps, 10000);
    }

    /**
     * Operational window status (M1 plan/56 §3) — WIB via PHP clock
     * (date_default_timezone_set('Asia/Jakarta') di index.php).
     *
     * @param int|string|null $now Timestamp unix / string parseable / null = now.
     * @return array{open:bool, code:string, day:int, days:int[], time:string,
     *               open_time:string, close_time:string}
     *   code: 'open' | 'closed_day' | 'closed_time'
     */
    public function withdrawal_operational_status($now = null) {
        $cfg = $this->get_financial_config();
        $ts  = ($now === null) ? time() : (is_int($now) ? $now : strtotime($now));
        $day = (int) date('N', $ts);          // 1 = Senin .. 7 = Minggu
        $hm  = date('H:i', $ts);              // WIB wall-clock

        $days = array_map('intval', array_filter(explode(',', $cfg['operational_days']), 'strlen'));

        $base = [
            'day'        => $day,
            'days'       => $days,
            'time'       => $hm,
            'open_time'  => $cfg['open_time'],
            'close_time' => $cfg['close_time'],
        ];

        if (!in_array($day, $days, true)) {
            return $base + ['open' => false, 'code' => 'closed_day'];
        }
        if ($hm < $cfg['open_time'] || $hm > $cfg['close_time']) {
            return $base + ['open' => false, 'code' => 'closed_time'];
        }
        return $base + ['open' => true, 'code' => 'open'];
    }

    /**
     * M8 (plan/74 §2.1): saldo otoritatif = Σcredit − Σdebit dalam SATU query.
     * Return SELALU strict int — MySQL mengembalikan SUM(DECIMAL) sebagai
     * numeric-string, jadi kalkulasi dilakukan di sisi SQL dan hasilnya
     * di-(int) kan di sini (baris kosong → COALESCE 0 → 0). Tidak pernah
     * float/string, tidak pernah null.
     *
     * @param int $user_id
     * @return int Saldo IDR bulat (0 saat tidak ada baris ledger).
     */
    public function get_balance($user_id) {
        $row = $this->db->query(
            "SELECT COALESCE(
                      SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END)
                    - SUM(CASE WHEN type = 'debit'  THEN amount ELSE 0 END),
                    0
            ) AS balance
             FROM wallet_ledger
            WHERE user_id = ?",
            [(int) $user_id]
        )->row();

        return (int) $row->balance;
    }

    /**
     * C5 (plan/48): kunci baris anchor users + hitung saldo segar otoritatif
     * dari wallet_ledger (Σcredit − Σdebit) dalam SATU transaksi debit.
     *
     * WAJIB dipanggil sebagai OPERASI PERTAMA di dalam trans_begin() pada
     * semua jalur debit. Alasan (plan/48 §3.1): `FOR UPDATE` adalah locking
     * (current) read yang memblokir TX debit paralel untuk user yang sama
     * hingga pemegang lock commit; SUM setelahnya adalah consistent read
     * PERTAMA di TX tersebut, sehingga read view-nya dibuat SETELAH lock
     * wait selesai → pasti melihat semua debit yang sudah di-commit pemegang
     * lock sebelumnya. Memanggilnya setelah read lain di TX yang sama akan
     * memakai snapshot lama (stale) dan menghidupkan kembali race C5.
     *
     * users.balance TIDAK dipakai (stale, audit C4) — baris users hanya
     * menjadi anchor serialisasi; sumber saldo tetap wallet_ledger.
     *
     * @param int $user_id
     * @return int|false Saldo segar (strict int), atau false bila baris users tidak ada.
     */
    public function lock_and_get_balance($user_id) {
        $user = $this->db->query(
            "SELECT id FROM users WHERE id = ? FOR UPDATE",
            [$user_id]
        )->row();

        if (!$user) {
            return false;
        }

        $row = $this->db->query(
            "SELECT COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN type = 'debit'  THEN amount ELSE 0 END), 0) AS balance
             FROM wallet_ledger
            WHERE user_id = ?",
            [$user_id]
        )->row();

        // M8 (plan/74 §2.1): strict int — saldo otoritatif di dalam TX terkunci
        // tidak boleh float (menghilangkan vektor drift untuk gate sufficiency).
        return (int) $row->balance;
    }

    // =====================================================================
    // C4 (plan/54): LEDGER INGESTION SERVICE — SINGLE WRITE PATH
    // =====================================================================
    // wallet_ledger = SATU-SATUNYA sumber kebenaran finansial; users.balance
    // hanya read-cache atomik yang HARUS sinkron di transaksi yang sama.
    //
    // credit()/debit() adalah peserta TX pemanggil (caller-TX participant):
    //   * TIDAK pernah membuka/commit transaksi sendiri — pemanggil sudah
    //     menjalankan trans_begin() dan mengunci baris anchor users
    //     (lock_and_get_balance / SELECT ... FOR UPDATE) sebagai statement
    //     pertama (pola C5, plan/48).
    //   * Menulis SATU baris wallet_ledger + update cache RELATIF
    //     (balance = balance ± ?) dalam unit atomik yang sama.
    //   * Mengembalikan false saat insert ledger ATAU update cache gagal
    //     (affected_rows() === 1) → pemanggil wajib rollback seluruh TX.
    //   * DILARANG: menulis balance absolut dari hasil baca basi (pola
    //     Ledger_model lama), membuka TX sendiri, atau dipanggil di luar TX.
    // Seluruh mutasi uang (W1–W9, audit plan/54 §3.3) wajib lewat helper ini —
    // grep-guard: tidak boleh ada insert('wallet_ledger' / UPDATE users
    // SET balance di luar file ini.

    /**
     * Kredit: +1 baris wallet_ledger (type=credit) + users.balance += amount.
     *
     * M8 (plan/74 §2.2): amount WAJIB integer IDR positif — di-(int) kan dan
     * di-guard di _post() (nilai 0/negatif/pecahan ditolak, log + false).
     *
     * @param int    $user_id
     * @param int    $amount  Nominal IDR bulat positif.
     * @param string $transaction_id  ID deterministik pemanggil (INV-/RENT-/ROI-/WD-/L1-/WAGE-/ADM-…).
     * @param string $description
     * @return bool false → pemanggil harus rollback.
     */
    public function credit($user_id, $amount, $transaction_id, $description) {
        return $this->_post('credit', $user_id, $amount, $transaction_id, $description);
    }

    /**
     * Debit: +1 baris wallet_ledger (type=debit) + users.balance -= amount.
     *
     * @param int    $user_id
     * @param int    $amount  Nominal IDR bulat positif.
     * @param string $transaction_id
     * @param string $description
     * @return bool false → pemanggil harus rollback.
     */
    public function debit($user_id, $amount, $transaction_id, $description) {
        return $this->_post('debit', $user_id, $amount, $transaction_id, $description);
    }

    /**
     * Implementasi tunggal credit/debit — lihat docblock section di atas.
     */
    private function _post($type, $user_id, $amount, $transaction_id, $description) {
        if (!in_array($type, ['credit', 'debit'], true)) {
            log_message('error', 'Wallet_model::_post — tipe ledger tidak valid: ' . var_export($type, true));
            return false;
        }

        // M8 (plan/74 §2.2): INTEGER-IDR choke point — satu-satunya gerbang
        // tulis wallet_ledger. Amount wajib integer POSITIF: nilai 0/negatif
        // adalah programming error (bukan no-op seperti `== 0` lama), dan
        // nilai pecahan dilarang (IDR zero-fraction). Di-(int) kan dulu agar
        // perbandingan & persistensi tidak pernah kena type-coercion.
        $amount = (int) $amount;
        if ($amount <= 0) {
            log_message('warning', 'Wallet_model::_post — amount tidak valid (type=' . $type
                . ', user=' . (int) $user_id . ', amount=' . var_export($amount, true)
                . ', tx=' . (string) $transaction_id . ')');
            return false;
        }

        // 1. Baris ledger (immutable append-only).
        $inserted = $this->db->insert('wallet_ledger', [
            'user_id'        => (int) $user_id,
            'transaction_id' => (string) $transaction_id,
            'type'           => $type,
            'amount'         => $amount,
            'description'    => (string) $description,
        ]);

        if (!$inserted) {
            log_message('error', 'Wallet_model::_post — insert wallet_ledger gagal (type=' . $type
                . ', user=' . (int) $user_id . ', tx=' . (string) $transaction_id . ')');
            return false;
        }

        // 2. Update cache relatif (amount dijamin > 0 oleh guard di atas).
        $sign  = ($type === 'credit') ? '+' : '-';
        $query = "UPDATE users SET balance = balance {$sign} ? WHERE id = ?";

        if (!$this->db->query($query, [$amount, (int) $user_id])) {
            log_message('error', 'Wallet_model::_post — update cache gagal (user=' . (int) $user_id
                . ', tx=' . (string) $transaction_id . ')');
            return false;
        }

        if ($this->db->affected_rows() !== 1) {
            log_message('error', 'Wallet_model::_post — cache update affected_rows=' . $this->db->affected_rows()
                . ' (user=' . (int) $user_id . ', tx=' . (string) $transaction_id . ')');
            return false;
        }

        return true;
    }

    /**
     * Buat invoice deposit pending + RESERVASI KODE UNIK 3 digit (W2 / plan102).
     *
     * M4 (plan/62 S2/H2): idempoten & aman-bentrok. Invoice lama
     * 'INV-YmdHis-userId' deterministik per detik → dua submit bersamaan bisa
     * menabrak uk_invoice_number (duplicate key, P2 audit lama). Sekarang
     * memuat sufiks acak 6-hex; hasil insert diverifikasi, dan collision tak
     * terduga di-retry maksimal 3x (sufiks baru tiap percobaan).
     *
     * plan/102: satu transaksi per attempt dengan URUTAN yang mengikat:
     *   1. kunci anchor `users` (serialisasi per-user; pola C5/plan48),
     *   2. baca kode yang sedang direservasi untuk pokok ini
     *      (prefix scan `reserved_code_key LIKE '{amount}-%'`),
     *   3. GUARD deposit aktif tunggal (pending|waiting_approval) milik user,
     *   4. pilih kode dari himpunan bebas (CSPRNG),
     *   5. INSERT invoice + unique_code + total_amount + reserved_code_key
     *      + expires_at.
     *
     * Jaminan anti-tabrakan lintas-user ada di INDEX UNIQUE
     * `uk_reserved_code_key` (bukan di SELECT): insert yang kalah balapan
     * mendapat duplicate key 1062 → rollback → attempt berikutnya membaca
     * ulang himpunan kode. Karena langkah 2/3 adalah consistent read PERTAMA
     * setelah lock wait selesai, TX yang menunggu lock PASTI melihat insert
     * yang sudah di-commit pesaingnya (alasan identik lock_and_get_balance,
     * plan/48 §3.1) — sehingga `pending_exists` bebas race double-submit.
     *
     * @param int $user_id
     * @param int $amount Nominal pokok IDR bulat positif (M8: di-(int) kan di boundary model).
     * @return array{success:bool, code:string, message:string, invoice_number:string|null,
     *               unique_code:int|null, total_amount:int, expires_at:string|null}
     *   code: 'ok' | 'invalid_amount' | 'below_min' | 'above_max'
     *         | 'pending_exists' | 'code_exhausted' | 'code_conflict' | 'error'
     */
    public function create_deposit($user_id, $amount) {
        $amount = (int) $amount;
        $policy = $this->get_deposit_policy();

        $fail = function ($code, $message) {
            return [
                'success' => false, 'code' => $code, 'message' => $message,
                'invoice_number' => null, 'unique_code' => null,
                'total_amount' => 0, 'expires_at' => null,
            ];
        };

        if ($amount <= 0) {
            return $fail('invalid_amount', 'Nominal deposit tidak valid.');
        }
        if ($amount < $policy['min_amount']) {
            return $fail('below_min', 'Minimal deposit adalah Rp ' . number_format($policy['min_amount'], 0, ',', '.'));
        }
        if ($amount > $policy['max_amount']) {
            return $fail('above_max', 'Maksimal deposit adalah Rp ' . number_format($policy['max_amount'], 0, ',', '.'));
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->db->trans_begin();

            try {
                // 1. Anchor lock users — juga memvalidasi baris user ada.
                if ($this->lock_and_get_balance($user_id) === false) {
                    $this->db->trans_rollback();
                    return $fail('error', 'Gagal membuat invoice. Silakan coba lagi.');
                }

                // 2. Kode yang sedang direservasi untuk pokok ini (reservasi
                //    hidup saja yang punya reserved_code_key non-NULL).
                $used = [];
                $rows = $this->db->query(
                    "SELECT unique_code FROM deposits
                      WHERE reserved_code_key LIKE ?
                        AND unique_code IS NOT NULL",
                    [$amount . '-%']
                )->result();
                foreach ($rows as $r) {
                    $used[(int) $r->unique_code] = true;
                }

                // 3. Guard deposit aktif tunggal (otoritatif, di dalam lock).
                $active = (int) $this->db->query(
                    "SELECT COUNT(*) AS c FROM deposits
                      WHERE user_id = ? AND status IN ('pending','waiting_approval')",
                    [(int) $user_id]
                )->row()->c;
                if ($active > 0) {
                    $this->db->trans_rollback();
                    return $fail('pending_exists', 'Anda masih memiliki deposit aktif. Selesaikan atau tunggu kedaluwarsa terlebih dahulu.');
                }

                // 4. Alokasi kode unik dari himpunan bebas.
                $code = $this->_pick_unique_code($used);
                if ($code === null) {
                    $this->db->trans_rollback();
                    log_message('error', 'Wallet_model::create_deposit — kode unik habis untuk pokok ' . $amount);
                    return $fail('code_exhausted', 'Kuota kode unik untuk nominal ini sedang penuh. Coba nominal lain atau hubungi admin.');
                }

                // 5. Persist: total_amount DIBEKUKAN di sini (pokok + fee + kode).
                $fee         = $this->calculate_deposit_fee($amount);
                $total       = $amount + $fee + $code;
                $invoice     = 'INV-' . date('YmdHis') . '-' . (int) $user_id . '-'
                             . strtoupper(bin2hex(random_bytes(3)));
                $expires_at  = date('Y-m-d H:i:s', time() + ((int) $policy['expiry_minutes'] * 60));

                $inserted = $this->db->insert('deposits', [
                    'user_id'          => (int) $user_id,
                    'invoice_number'   => $invoice,
                    'amount'           => $amount,
                    'unique_code'      => $code,
                    'total_amount'     => $total,
                    'reserved_code_key' => $amount . '-' . $code,
                    'expires_at'       => $expires_at,
                    'status'           => 'pending',
                ]);

                if (!$inserted) {
                    $err = $this->db->error();
                    $this->db->trans_rollback();

                    // Duplicate key = invoice ATAU kode unik sudah direbut TX
                    // lain → ulangi attempt (baca ulang himpunan kode).
                    if (in_array((int) $err['code'], [1062, 23000], true)) {
                        continue;
                    }
                    log_message('error', 'Wallet_model::create_deposit — insert gagal (user=' . (int) $user_id
                        . ', code=' . $err['code'] . ', msg=' . $err['message'] . ')');
                    return $fail('error', 'Gagal membuat invoice. Silakan coba lagi.');
                }

                $this->db->trans_commit();

                return [
                    'success'        => true,
                    'code'           => 'ok',
                    'message'        => '',
                    'invoice_number' => $invoice,
                    'unique_code'    => $code,
                    'total_amount'   => $total,
                    'expires_at'     => $expires_at,
                ];

            } catch (Throwable $e) {
                $this->db->trans_rollback();
                log_message('error', 'Wallet_model::create_deposit — ' . $e->getMessage());
                return $fail('error', 'Gagal membuat invoice. Silakan coba lagi.');
            }
        }

        log_message('error', 'Wallet_model::create_deposit — gagal setelah retry (user=' . (int) $user_id . ')');
        return $fail('code_conflict', 'Sistem sedang sibuk mengalokasikan kode unik. Silakan coba lagi.');
    }

    /**
     * Pilih kode unik bebas dari rentang 100–999 (CSPRNG, tanpa modulo bias).
     *
     * Murni/tanpa I/O agar mudah ditelaah: input himpunan kode terpakai,
     * output kode bebas acak atau null bila rentang habis.
     *
     * @param array<int,bool> $used Kode terpakai (key = nilai kode).
     * @return int|null
     */
    private function _pick_unique_code(array $used) {
        $free = [];
        for ($c = self::UNIQUE_CODE_MIN; $c <= self::UNIQUE_CODE_MAX; $c++) {
            if (!isset($used[$c])) {
                $free[] = $c;
            }
        }
        if (count($free) === 0) {
            return null;
        }
        return $free[random_int(0, count($free) - 1)];
    }

    /**
     * plan/102: apakah user punya deposit hidup (pending | waiting_approval)?
     * Dipakai untuk mirror UX guard aktif-tunggal — otoritasnya tetap
     * create_deposit() di dalam TX terkunci.
     */
    public function has_active_deposit($user_id) {
        return (int) $this->db
            ->where('user_id', (int) $user_id)
            ->where_in('status', ['pending', 'waiting_approval'])
            ->count_all_results('deposits') > 0;
    }

    /**
     * plan/102: deposit HIDUP milik user (pending | waiting_approval).
     * Rename + perluas dari get_pending_deposits() lama.
     */
    public function get_active_deposits($user_id) {
        return $this->db
            ->where('user_id', (int) $user_id)
            ->where_in('status', ['pending', 'waiting_approval'])
            ->order_by('created_at', 'DESC')
            ->get('deposits')
            ->result();
    }

    /**
     * plan/102: konfirmasi transfer member — pending → waiting_approval.
     *
     * Member TIDAK mengunggah bukti; ia menyatakan sudah transfer. Reservasi
     * kode TETAP ditahan (D1: waiting_approval tidak pernah auto-expire),
     * sehingga tidak ada risiko kode direbut orang lain setelah member bayar.
     *
     * @return array{success:bool, code:string, message:string}
     *   code: 'ok' | 'not_found' | 'not_pending' | 'expired' | 'error'
     */
    public function confirm_deposit($invoice_number, $user_id) {
        $deposit = $this->get_deposit_by_invoice($invoice_number);

        if (!$deposit) {
            return ['success' => false, 'code' => 'not_found', 'message' => 'Invoice tidak ditemukan.'];
        }

        // Otoritas jendela bayar = PHP WIB (bukan NOW() MySQL — invarian M2/M3).
        $now = date('Y-m-d H:i:s');
        if ($deposit->expires_at !== null && $deposit->expires_at <= $now) {
            return ['success' => false, 'code' => 'expired', 'message' => 'Invoice sudah kedaluwarsa. Silakan buat deposit baru.'];
        }
        if ($deposit->status !== 'pending') {
            return ['success' => false, 'code' => 'not_pending', 'message' => 'Deposit ini sudah dikonfirmasi atau tidak lagi menunggu pembayaran.'];
        }

        // Transisi kondisional: hanya menang bila masih pending DAN milik user
        // DAN jendela bayar masih terbuka. affected_rows() === 1 = gerbang.
        $this->db->where('invoice_number', $invoice_number);
        $this->db->where('user_id', (int) $user_id);
        $this->db->where('status', 'pending');
        $this->db->where('expires_at >', $now);
        $this->db->update('deposits', [
            'status'       => 'waiting_approval',
            'confirmed_at' => $now,
        ]);

        if ($this->db->affected_rows() !== 1) {
            return ['success' => false, 'code' => 'not_pending', 'message' => 'Deposit ini sudah dikonfirmasi atau tidak lagi menunggu pembayaran.'];
        }

        return ['success' => true, 'code' => 'ok', 'message' => ''];
    }

    /**
     * plan/102: sweep expiry lazy per-user (M3 plan/60) — pending → expired,
     * melepas reservasi kode (reserved_code_key = NULL) agar kode bebas
     * dipakai ulang. Satu UPDATE ber-index (idx_status_expires), autocommit,
     * idempotent. `waiting_approval` SENGAJA tidak disentuh (keputusan D1).
     *
     * @return int Jumlah baris yang ditutup.
     */
    public function expire_user_deposits($user_id) {
        $now = date('Y-m-d H:i:s');

        $this->db->where('user_id', (int) $user_id);
        $this->db->where('status', 'pending');
        $this->db->where('expires_at <=', $now);
        $this->db->update('deposits', [
            'status'            => 'expired',
            'processed_at'      => $now,
            'reserved_code_key' => null,
        ]);

        return (int) $this->db->affected_rows();
    }

    /**
     * plan/102: sweep expiry GLOBAL (entry admin + CLI). Batch dibatasi agar
     * tidak pernah mengunci tabel lama. `waiting_approval` tidak disentuh (D1).
     *
     * @param int $limit Maksimum baris per panggilan.
     * @return int Jumlah baris yang ditutup.
     */
    public function expire_stale_deposits($limit = 500) {
        $now   = date('Y-m-d H:i:s');
        $limit = max(1, (int) $limit);

        $ids = $this->db->query(
            "SELECT id FROM deposits
              WHERE status = 'pending' AND expires_at <= ?
              ORDER BY expires_at ASC
              LIMIT " . $limit,
            [$now]
        )->result();

        if (count($ids) === 0) {
            return 0;
        }

        $id_list = array_map(function ($r) { return (int) $r->id; }, $ids);

        $this->db->where_in('id', $id_list);
        $this->db->where('status', 'pending');
        $this->db->where('expires_at <=', $now);
        $this->db->update('deposits', [
            'status'            => 'expired',
            'processed_at'      => $now,
            'reserved_code_key' => null,
        ]);

        return (int) $this->db->affected_rows();
    }

    public function get_ledger_history($user_id) {
        $this->db->where('user_id', $user_id);
        $this->db->order_by('created_at', 'DESC');
        return $this->db->get('wallet_ledger')->result();
    }

    public function get_deposit_by_invoice($invoice_number) {
        return $this->db->get_where('deposits', ['invoice_number' => $invoice_number])->row();
    }

    /**
     * Simulator pelunasan deposit (DEV/UAT ONLY — di-gate ENVIRONMENT oleh
     * controller). plan/102: menerima `pending` MAUPUN `waiting_approval`,
     * mengkredit pokok + kode unik (D3 — fee deposit ditahan platform),
     * dan melepas reservasi kode.
     */
    public function approve_deposit_simulator($invoice_number, $user_id) {
        $this->db->trans_begin();

        try {
            // C1 (plan 38) + C4 (plan/54): transisi atomik kondisional — hanya
            // menang jika invoice masih hidup (pending|waiting_approval) DAN
            // milik user session; affected_rows() === 1 adalah satu-satunya
            // gerbang kredit. replay/duplicate → 0 baris → 0 kredit.

            // 1. Kunci anchor users (W2 credit path — serialisasi kredit
            //    per-user sebelum mutasi cache; pola C5/plan/48).
            if ($this->lock_and_get_balance($user_id) === false) {
                $this->db->trans_rollback();
                return false;
            }

            // 2. Transisi → success bersyarat + lepas reservasi kode unik.
            $this->db->where('invoice_number', $invoice_number);
            $this->db->where_in('status', ['pending', 'waiting_approval']);
            $this->db->where('user_id', $user_id);
            $this->db->update('deposits', [
                'status'            => 'success',
                'processed_at'      => date('Y-m-d H:i:s'),
                'reserved_code_key' => null,
            ]);

            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                return false;
            }

            // 3. Kredit via ledger ingestion helper (ledger + cache atomik).
            $deposit = $this->db->get_where('deposits', ['invoice_number' => $invoice_number])->row();
            if (!$deposit) {
                $this->db->trans_rollback();
                return false;
            }

            // plan/102 Option A: kredit = total_amount (pokok + kode, +fee bila
            // fee deposit aktif). Fallback defensif ke `amount` untuk baris
            // yang belum ter-backfill.
            $credit_amount = $this->deposit_credit_amount($deposit);

            if (!$this->credit(
                (int) $deposit->user_id,
                $credit_amount,
                $deposit->invoice_number,
                'Top Up via ' . $deposit->invoice_number
            )) {
                $this->db->trans_rollback();
                return false;
            }

            $this->db->trans_commit();
            return true;

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Wallet_model::approve_deposit_simulator — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * plan/102: nilai kredit otoritatif sebuah deposit (keputusan D3).
     *
     * Komposisi `total_amount` yang dibekukan saat create:
     *   total_amount = pokok + [fee] + kode unik
     *
     * Kredit ke dompet = **pokok + kode unik**:
     *   - deposit fee (bila aktif) DITAHAN platform — semantik M1 plan/56
     *     "wallet credit stays pure principal" (zero dilution);
     *   - saat `deposit_fee_enabled = '0'` → total_amount == pokok + kode,
     *     sehingga kredit = total_amount = **Option A persis** (seluruh nominal
     *     transfer dikreditkan).
     *
     * Baris legacy pra-migrasi (total_amount = 0) dikredit sebesar `amount` —
     * parity perilaku lama.
     *
     * @param object $deposit Baris deposits.
     * @return int Nominal kredit IDR bulat (>= 0).
     */
    public function deposit_credit_amount($deposit) {
        $total  = (int) (isset($deposit->total_amount) ? $deposit->total_amount : 0);
        $amount = (int) (isset($deposit->amount) ? $deposit->amount : 0);
        $code   = (isset($deposit->unique_code) && $deposit->unique_code !== null)
            ? (int) $deposit->unique_code : 0;

        if ($total <= 0) {
            return $amount; // baris legacy (belum ter-backfill): parity kredit lama
        }

        $credit = $amount + $code;

        // Guard defensif: kredit tidak pernah melebihi nominal yang dibekukan.
        if ($credit <= 0 || $credit > $total) {
            return $total;
        }

        return $credit;
    }

    // ===== WITHDRAWAL =====

    /**
     * C5 (plan/48): mesin penarikan ACID anti-overspend.
     *   1. trans_begin() eksplisit + try/catch (gaya claim_roi, plan/44).
     *   2. lock_and_get_balance() — kunci anchor users + saldo segar sebagai
     *      statement pertama (serialisasi semua debit per-user).
     *   3. Penolakan overspend STRICT di dalam TX terkunci:
     *      fresh_balance < amount → rollback + code 'insufficient'.
     *   4. Re-check gatekeeper per-user (pending-WD & daily limit) DI DALAM
     *      lock — read view dibuat setelah lock wait, jadi bebas race
     *      double-submit (plan/48 §3.4).
     *   5. Insert withdrawals (pending) + wallet_ledger (debit) → commit.
     *
     * Pre-check di controller HANYA untuk UX cepat; otoritas finansial ada
     * di sini (AGENTS.md — semua akses DB di model).
     *
     * @param int $user_id
     * @param int $amount          Nominal IDR (integer, hasil sanitasi controller)
     * @param int $bank_account_id
     * @return array{success:bool, code:string, message:string, wd_number:string|null}
     *   code: 'ok' | 'insufficient' | 'pending_exists' | 'daily_limit'
     *         | 'closed_day' | 'closed_time' | 'below_min' | 'above_max' | 'error'
     */
    public function create_withdrawal($user_id, $amount, $bank_account_id) {
        $wd_number = 'WD-' . date('YmdHis') . '-' . $user_id;

        $this->db->trans_begin();

        try {
            // 1. Kunci anchor users + saldo segar otoritatif (anti-race C5).
            $fresh_balance = $this->lock_and_get_balance($user_id);

            if ($fresh_balance === false) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'error', 'message' => 'Gagal memproses penarikan.', 'wd_number' => null];
            }

            // 1b. Kebijakan operasional & batas nominal (M1, plan/56) —
            //     otoritatif di dalam TX terkunci (re-check setelah lock wait),
            //     jadi perubahan jam/hari/tier langsung berlaku tanpa race.
            $amount = (int) $amount;
            $cfg    = $this->get_financial_config();
            $op     = $this->withdrawal_operational_status();

            if (!$op['open']) {
                $this->db->trans_rollback();
                $message = ($op['code'] === 'closed_day')
                    ? 'Hari ini bukan hari operasional penarikan.'
                    : 'Penarikan hanya dapat diajukan pada pukul ' . $cfg['open_time'] . '–' . $cfg['close_time'] . ' WIB.';
                return ['success' => false, 'code' => $op['code'], 'message' => $message, 'wd_number' => null];
            }

            if ($amount < (int) $cfg['min_amount']) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'below_min', 'message' => 'Minimal penarikan adalah Rp ' . number_format((int) $cfg['min_amount'], 0, ',', '.') . '.', 'wd_number' => null];
            }

            if ($amount > (int) $cfg['max_amount']) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'above_max', 'message' => 'Maksimal penarikan adalah Rp ' . number_format((int) $cfg['max_amount'], 0, ',', '.') . '.', 'wd_number' => null];
            }

            // 2. Penolakan overspend STRICT di dalam TX terkunci.
            //    (M8: fresh_balance & amount keduanya strict int.)
            if ($fresh_balance < $amount) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'insufficient', 'message' => 'Saldo tidak mencukupi untuk penarikan', 'wd_number' => null];
            }

            // 3. Re-check otoritatif gatekeeper per-user (serialized oleh lock).
            if ($this->has_pending_withdrawal($user_id)) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'pending_exists', 'message' => 'Anda masih memiliki penarikan yang sedang diproses.', 'wd_number' => null];
            }

            if ($this->has_reached_daily_wd_limit($user_id)) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'daily_limit', 'message' => 'Batas penarikan harian tercapai.', 'wd_number' => null];
            }

            // 4. Insert withdrawal record (bank_account_id FK only — no raw bank strings).
            //    C3 (plan/52): persist SEMUA kolom finansial — gross/fee/net dihitung
            //    dari fee tiers PRD (half-open) di dalam TX terkunci yang sama;
            //    amount == gross_amount (backward-compat mirror, seed legacy pattern).
            $fee = $this->calculate_withdrawal_fee((int) $amount);

            $this->db->insert('withdrawals', [
                'user_id'          => $user_id,
                'wd_number'        => $wd_number,
                'amount'           => $amount,             // mirror == gross (legacy contract)
                'gross_amount'     => $amount,
                'fee_amount'       => $fee['fee'],
                'net_amount'       => $fee['net'],
                'bank_account_id'  => $bank_account_id,
                'status'           => 'pending',
            ]);

            // 5. Debit via ledger ingestion helper (ledger + cache atomik) —
            //    lock funds immediately; kegagalan → rollback seluruh TX.
            $debited = $this->debit(
                $user_id,
                $amount,
                $wd_number,
                'Penarikan Dana via ' . $wd_number
            );

            if (!$debited) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'error', 'message' => 'Gagal memproses penarikan.', 'wd_number' => null];
            }

            $this->db->trans_commit();

            return ['success' => true, 'code' => 'ok', 'message' => '', 'wd_number' => $wd_number];

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Wallet_model::create_withdrawal — ' . $e->getMessage());
            return ['success' => false, 'code' => 'error', 'message' => 'Gagal memproses penarikan.', 'wd_number' => null];
        }
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

    /**
     * Daily limit: maksimal 1 penarikan per hari kalender WIB
     * (00:00:00–23:59:59 Asia/Jakarta). Boundary dihitung di PHP
     * (date_default_timezone_set di index.php) dan di-bind sebagai parameter —
     * TIDAK memakai CURDATE() server MySQL (audit M1/plan/56 §3: server sering
     * UTC vs PHP WIB). Status yang dihitung: pending/processing/success
     * (penarikan 'failed'/declined TIDAK menghabiskan kuota harian).
     */
    public function has_reached_daily_wd_limit($user_id) {
        $today = date('Y-m-d'); // WIB wall-clock today
        $row = $this->db->query(
            "SELECT COUNT(*) AS c FROM withdrawals
              WHERE user_id = ? AND DATE(created_at) = ? AND status IN ('pending', 'processing', 'success')",
            [(int) $user_id, $today]
        )->row();
        return ((int) $row->c) > 0;
    }

    public function get_withdrawal_by_wd_number($wd_number) {
        return $this->db->get_where('withdrawals', ['wd_number' => $wd_number])->row();
    }

    public function approve_withdrawal_simulator($wd_number, $user_id) {
        $this->db->trans_start();

        // C7 (plan 42) 4B: transisi atomik bersyarat — hanya menang jika WD
        // masih 'pending' DAN milik user session. affected_rows() === 1 adalah
        // satu-satunya gerbang sukses; replay/duplicate → 0 baris → false.
        $this->db->where('wd_number', $wd_number);
        $this->db->where('status', 'pending');
        $this->db->where('user_id', $user_id);
        $this->db->update('withdrawals', [
            'status'       => 'success',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);

        $affected = $this->db->affected_rows();

        $this->db->trans_complete();
        return $this->db->trans_status() && $affected === 1;
    }

    // ===== BANK BINDING (IMMUTABLE) =====

    public function get_user_bank($user_id) {
        return $this->db->get_where('bank_accounts', ['user_id' => $user_id])->row();
    }

    public function insert_bank($data) {
        return $this->db->insert('bank_accounts', $data);
    }
}