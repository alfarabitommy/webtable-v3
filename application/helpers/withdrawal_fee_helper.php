<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// ===================================================================
//  WITHDRAWAL FEE TIER HELPERS — Plan 110 (amandemen plan/56 §2.3)
//
//  Satu-satunya tempat aturan tier biaya penarikan dari INPUT ADMIN hidup
//  (choke-point tunggal, helper-first rule AGENTS.md). Dipakai:
//
//    1. Admin  : Admin::settings() POST → Wallet_model::validate_financial_settings().
//    2. CLI    : scripts/probe/verifier plan/110 (include langsung setelah
//                BASEPATH didefinisikan — pola scripts/migrate_105_*).
//    3. View   : withdrawal_fee_tier_bps_to_pct() untuk render nilai persen
//                (agar tidak ada rumus ganda di PHP view vs JS).
//
//  ATURAN KANONIK (plan/110 §5.2):
//    1) Minimal 1 baris tier.
//    2) Setiap baris: 0 < min < max; persen 0..100 (toleran 2 desimal);
//       bps = round(persen * 100) ∈ [0,10000] (skala tier: 10% = 1000 bps).
//    3) Baris KONTIGU PENUH & menaik: rows[i].min === rows[i-1].max
//       (half-open [min, max): nominal batas masuk tier lebih tinggi).
//       Celah / tumpang tindih = ERROR KERAS (menyebut nomor baris + nilai).
//    4) ENDPOINT TURUNAN (bukan data bebas) — DINORMALKAN OTOMATIS, bukan
//       ditolak: rows[0].min ← wd_min_amount dan
//       rows[n-1].max ← max(rows[n-1].max, wd_max_amount + 1).
//       Penyesuaian dilaporkan lewat `notices[]` (tampil ke admin + audit
//       `auto_adjusted`). AMANDEMEN plan/56 §2.3 (dulu: "any violation →
//       reject the whole update") — preseden: kode menang, plan/110 D2.
//
//  KENAPA ATURAN INI PENTING (bukan birokrasi):
//    Wallet_model::calculate_withdrawal_fee() memakai tarif tier TERAKHIR
//    sebagai fallback bila nominal tidak masuk tier mana pun — artinya celah
//    tier = potensi kurang potong biaya secara senyap. Jaminan "tercakup
//    penuh" WAJIB ada; plan/110 membuatnya mustahil dilanggar admin dengan
//    MENURUNKAN endpoint, bukan dengan memaksa admin mengetik ulang nilai
//    yang sebenarnya sudah dimiliki sistem.
//
//  Kontrak fungsi: MURNI (tanpa DB, tanpa efek samping, tanpa state), aman
//  di-include berulang (semua dibungkus function_exists()), uang selalu
//  INTEGER IDR (M8). Pemformatan `Rp` di sini hanya untuk pesan error admin
//  (panel admin 100% Indonesia, invarian L1 — bukan copy member, bukan i18n).
// ===================================================================

// Batas panjang digit nominal tier (anti overflow/saturasi int).
defined('WITHDRAWAL_FEE_TIER_MAX_DIGITS') OR define('WITHDRAWAL_FEE_TIER_MAX_DIGITS', 15);

if ( ! function_exists('withdrawal_fee_tier_int'))
{
    /**
     * Nominal IDR dari input mentah → int >= $min, atau null bila tidak valid.
     * Toleran pemisah ribuan (digit-only, mirror Wallet_model::_norm_int)
     * TAPI dengan batas panjang digit agar nilai tak masuk akal ditolak.
     *
     * @param  mixed $raw
     * @param  int   $min Nilai minimum yang diterima (default 0).
     * @return int|null
     */
    function withdrawal_fee_tier_int($raw, $min = 0)
    {
        if ($raw === null || is_array($raw) || is_object($raw)) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', (string) $raw);
        if ($digits === '' || strlen($digits) > WITHDRAWAL_FEE_TIER_MAX_DIGITS) {
            return null;
        }

        $value = (int) $digits;
        return ($value < $min) ? null : $value;
    }
}

if ( ! function_exists('withdrawal_fee_tier_pct'))
{
    /**
     * Persen dari input mentah → float 0..100 (2 desimal), atau null.
     * Koma diterima sebagai pemisah desimal (input manual admin).
     *
     * @param  mixed $raw
     * @return float|null
     */
    function withdrawal_fee_tier_pct($raw)
    {
        if ($raw === null || is_array($raw) || is_object($raw)) {
            return null;
        }

        $s = str_replace(',', '.', trim((string) $raw));
        if ($s === '' || ! preg_match('/^[0-9]+(\.[0-9]+)?$/', $s)) {
            return null;
        }

        $value = (float) $s;
        if ($value < 0 || $value > 100) {
            return null;
        }

        return round($value, 2);
    }
}

if ( ! function_exists('withdrawal_fee_tier_bps_to_pct'))
{
    /**
     * bps → label persen kanonik tanpa nol ekor (750 → '7.5', 1000 → '10').
     * Dipakai PHP view DAN menjadi acuan pembulatan JS (plan/110 §5.4).
     *
     * @param  int|string $bps
     * @return string
     */
    function withdrawal_fee_tier_bps_to_pct($bps)
    {
        $label = number_format(((int) $bps) / 100, 2, '.', '');
        $label = rtrim(rtrim($label, '0'), '.');

        return ($label === '') ? '0' : $label;
    }
}

if ( ! function_exists('withdrawal_fee_tier_rows_from_json'))
{
    /**
     * JSON legacy `[[min,max,bps],…]` (system_settings.wd_fee_tiers) → baris
     * editor `[['min','max','pct'],…]`, ATAU null bila bentuknya tidak sah.
     * Ketat (bukan best-effort): dipakai sebagai jalur kompatibilitas mundur
     * bagi halaman yang masih mengirim satu hidden JSON, sehingga nilai rusak
     * tidak pernah "diam-diam" menjadi tier 0%.
     *
     * @param  mixed $json
     * @return array<int,array{min:mixed,max:mixed,pct:string}>|null
     */
    function withdrawal_fee_tier_rows_from_json($json)
    {
        if (! is_string($json) || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded) || count($decoded) === 0) {
            return null;
        }

        $rows = [];
        foreach ($decoded as $tuple) {
            if (! is_array($tuple) || count($tuple) !== 3) {
                return null;
            }
            foreach ($tuple as $component) {
                if (! is_numeric($component)) {
                    return null;
                }
            }
            $rows[] = [
                'min' => $tuple[0],
                'max' => $tuple[1],
                'pct' => withdrawal_fee_tier_bps_to_pct($tuple[2]),
            ];
        }

        return $rows;
    }
}

if ( ! function_exists('withdrawal_fee_tier_normalize'))
{
    /**
     * Normalisasi baris tier dari input admin → tier kanonik siap simpan.
     *
     * @param  mixed $rows       Baris mentah POST: [['min'=>…, 'max'=>…, 'pct'=>…], …]
     * @param  mixed $min_amount Nominal minimal penarikan (wd_min_amount).
     * @param  mixed $max_amount Nominal maksimal penarikan (wd_max_amount).
     * @return array{ok:bool,errors:string[],notices:string[],tiers:array<int,array{0:int,1:int,2:int}>|null,rows:array}
     *   - ok      : true → `tiers` siap di-json_encode ke wd_fee_tiers.
     *   - errors  : pelanggaran KERAS (per baris bila relevan) — Indonesia.
     *   - notices : penyesuaian OTOMATIS endpoint turunan (D2 plan/110).
     *   - rows    : baris hasil parse (untuk repopulasi/inspeksi).
     */
    function withdrawal_fee_tier_normalize($rows, $min_amount, $max_amount)
    {
        $errors   = [];
        $notices  = [];
        $fail     = function ($errs, $parsed = []) use (&$notices) {
            return ['ok' => false, 'errors' => $errs, 'notices' => $notices, 'tiers' => null, 'rows' => $parsed];
        };

        if (! is_array($rows) || count($rows) === 0) {
            return $fail(['Minimal satu baris tier biaya.']);
        }

        // Endpoint turunan hanya boleh dinormalkan bila bound-nya SENDIRI valid
        // (kalau tidak, tier divalidasi struktural saja — plan/110 §5.5).
        $bound_min = withdrawal_fee_tier_int($min_amount, 1);
        $bound_max = withdrawal_fee_tier_int($max_amount, 1);
        $derive    = ($bound_min !== null && $bound_max !== null && $bound_max > $bound_min);

        // ── 1) Parse per baris (angka + relasi min/max).
        $parsed = [];
        foreach (array_values($rows) as $index => $row) {
            $no  = $index + 1;
            $row = is_array($row) ? $row : [];

            $mn = withdrawal_fee_tier_int(array_key_exists('min', $row) ? $row['min'] : null, 0);
            $mx = withdrawal_fee_tier_int(array_key_exists('max', $row) ? $row['max'] : null, 0);
            $pc = withdrawal_fee_tier_pct(array_key_exists('pct', $row) ? $row['pct'] : null);

            if ($mn === null || $mx === null || $pc === null) {
                $errors[] = 'Tier baris ' . $no . ': min, maks, dan persen wajib angka '
                          . '(persen 0–100, maksimal 2 desimal).';
                continue;
            }
            if ($mx <= $mn) {
                $errors[] = 'Tier baris ' . $no . ': maksimal (Rp ' . number_format($mx, 0, ',', '.')
                          . ') harus lebih besar dari minimal (Rp ' . number_format($mn, 0, ',', '.') . ').';
                continue;
            }

            $parsed[] = [
                'min' => $mn,
                'max' => $mx,
                'bps' => (int) round($pc * 100),
                'pct' => $pc,
            ];
        }

        if (! empty($errors)) {
            return $fail($errors, $parsed);
        }

        // ── 2) Urutkan menaik (stabil) lalu wajib kontigu penuh.
        usort($parsed, function ($a, $b) {
            return $a['min'] <=> $b['min'];
        });

        $count = count($parsed);
        for ($i = 1; $i < $count; $i++) {
            if ($parsed[$i]['min'] !== $parsed[$i - 1]['max']) {
                $errors[] = 'Tier baris ' . ($i + 1) . ': minimal (Rp '
                          . number_format($parsed[$i]['min'], 0, ',', '.')
                          . ') harus sama dengan maksimal baris sebelumnya (Rp '
                          . number_format($parsed[$i - 1]['max'], 0, ',', '.')
                          . ') — tidak boleh ada celah atau tumpang tindih. '
                          . 'Klik "Rapikan Tier" untuk merapikan otomatis.';
            }
        }

        if (! empty($errors)) {
            return $fail($errors, $parsed);
        }

        // ── 3) Endpoint TURUNAN (D2 plan/110) — normalkan, jangan tolak.
        if ($derive) {
            if ($parsed[0]['min'] !== $bound_min) {
                $notices[] = 'Batas bawah tier pertama disesuaikan mengikuti Minimal Penarikan (Rp '
                           . number_format($bound_min, 0, ',', '.') . ').';
                $parsed[0]['min'] = $bound_min;
            }

            $last = $count - 1;
            if ($parsed[$last]['max'] <= $bound_max) {
                $new_max = $bound_max + 1;
                $notices[] = 'Batas atas tier terakhir dinaikkan ke Rp '
                           . number_format($new_max, 0, ',', '.')
                           . ' agar melewati Maksimal Penarikan (Rp '
                           . number_format($bound_max, 0, ',', '.') . ').';
                $parsed[$last]['max'] = $new_max;
            }
        }

        // ── 4) Guard akhir: normalisasi tidak boleh menghasilkan baris invalid
        //      (mis. Minimal dinaikkan melewati batas atas baris pertama).
        foreach ($parsed as $index => $tier) {
            if ($tier['max'] <= $tier['min']) {
                $errors[] = 'Tier baris ' . ($index + 1) . ': maksimal (Rp '
                          . number_format($tier['max'], 0, ',', '.')
                          . ') harus lebih besar dari minimal (Rp '
                          . number_format($tier['min'], 0, ',', '.')
                          . ') — naikkan maksimalnya atau hapus baris ini.';
            }
        }

        if (! empty($errors)) {
            return $fail($errors, $parsed);
        }

        $tiers = [];
        foreach ($parsed as $tier) {
            $tiers[] = [(int) $tier['min'], (int) $tier['max'], (int) $tier['bps']];
        }

        return ['ok' => true, 'errors' => [], 'notices' => $notices, 'tiers' => $tiers, 'rows' => $parsed];
    }
}

if ( ! function_exists('withdrawal_fee_tier_json'))
{
    /**
     * Tier kanonik → JSON untuk system_settings.wd_fee_tiers.
     *
     * @param  mixed $tiers [[min,max,bps],…]
     * @return string
     */
    function withdrawal_fee_tier_json($tiers)
    {
        $out = [];
        foreach ((array) $tiers as $tier) {
            if (! is_array($tier) || count($tier) < 3) {
                continue;
            }
            $out[] = [(int) $tier[0], (int) $tier[1], (int) $tier[2]];
        }

        $json = json_encode($out);

        return ($json === false) ? '[]' : $json;
    }
}
