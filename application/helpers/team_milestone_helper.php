<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// =====================================================================
// Plan 119 — BONUS PENCAPAIAN TIM (TEAM MILESTONE BONUS): CHOKE-POINT
// =====================================================================
// SATU sumber kebenaran untuk BENTUK data tier & state klaim. Semua fungsi
// di sini MURNI (tanpa DB, tanpa I/O) dan `function_exists()`-guarded supaya
// aman di-include dari CLI (`scripts/migrate_119_team_milestone.php`).
//
// Aturan lengkap (AGENTS.md "Helper-first rule"): kontroler admin, model
// (`User_model`), view member, dan CLI WAJIB memakai helper ini — dilarang
// menyalin ulang parsing/encoding di tempat lain.
//
// ⚠️ INVARIAN GATE i18n: file ini TIDAK BOLEH memuat prosa user-facing
//    (pesan berbahasa Indonesia hidup di MODEL — `User_model` dikecualikan
//    gate `audit_i18n_hardcoded.php` R2, helper TIDAK). Semua "alasan" di
//    sini adalah token teknis `snake_case`.
//
// Bentuk kanonik:
//   tier   : ['target' => int >= 1, 'amount' => int >= 1]  (daftar MENAIK)
//   state  : '9,30,70'  (CSV target integer, menaik, tanpa spasi; '' = kosong)
//   ledger : transaction_id 'MSB-{user_id}-T{target}'

if ( ! function_exists('team_milestone_limits'))
{
    /**
     * Ambang administratif (SATU sumber: validator admin + CLI --verify).
     *
     * `max_tiers` diturunkan dari lebar kolom `users.team_milestone_claimed`
     * (VARCHAR 255): 20 tier × (target 6 digit + 1 koma) = 139 karakter,
     * jauh di bawah `max_claimed_csv`. Menaikkan batas ini berarti
     * MEMPERLEBAR kolom (lihat catatan migrasi plan/119) — bukan sekadar
     * mengubah angka di sini.
     *
     * @return array{max_tiers:int,max_target:int,max_amount:int,max_claimed_csv:int}
     */
    function team_milestone_limits()
    {
        return array(
            'max_tiers'       => 20,
            'max_target'      => 100000,
            'max_amount'      => 100000000,
            'max_claimed_csv' => 200,
        );
    }
}

if ( ! function_exists('team_milestone_int_token'))
{
    /**
     * Token integer ketat (M8): angka bulat non-negatif tanpa tanda/titik/
     * eksponen. Mengembalikan int, atau NULL bila tidak memenuhi.
     *
     * @param  mixed $raw
     * @return int|null
     */
    function team_milestone_int_token($raw)
    {
        if (is_int($raw)) {
            return $raw;
        }
        if ( ! is_string($raw)) {
            return NULL;
        }
        $s = trim($raw);
        if ($s === '' || ! preg_match('/^[0-9]+$/', $s)) {
            return NULL;
        }
        // Hindari overflow (PHP int 64-bit; batas administratif jauh di bawah).
        if (strlen($s) > 12) {
            return NULL;
        }
        return (int) $s;
    }
}

if ( ! function_exists('team_milestone_tiers_parse'))
{
    /**
     * Parse STRUKTURAL konfigurasi tier. Menerima string JSON
     * (`system_settings.team_milestone_tiers`, bentuk `[[9,200000],…]`) ATAU
     * array tuple. TIDAK mengurutkan input dan TIDAK memeriksa ambang —
     * urutan/ambang diperiksa `team_milestone_tiers_valid()` supaya validator
     * admin dapat melaporkan baris yang salah apa adanya.
     *
     * Ketat (bukan best-effort): bentuk rusak → NULL, sehingga nilai rusak
     * tidak pernah diam-diam menjadi tier nominal 0.
     *
     * @param  mixed $raw
     * @return array<int,array{target:int,amount:int}>|null
     */
    function team_milestone_tiers_parse($raw)
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return NULL;
            }
            $decoded = json_decode($raw, TRUE);
            if ( ! is_array($decoded)) {
                return NULL;
            }
            $raw = $decoded;
        }

        if ( ! is_array($raw)) {
            return NULL;
        }

        $tiers = array();
        foreach ($raw as $tuple) {
            if (is_object($tuple)) {
                $tuple = (array) $tuple;
            }
            if ( ! is_array($tuple)) {
                return NULL;
            }
            // Hanya tuple berpasangan (0,1) — bentuk JSON kanonik [[t,a],…].
            if (count($tuple) !== 2) {
                return NULL;
            }
            $tuple = array_values($tuple);

            $target = team_milestone_int_token($tuple[0]);
            $amount = team_milestone_int_token($tuple[1]);
            if ($target === NULL || $amount === NULL) {
                return NULL;
            }

            $tiers[] = array('target' => $target, 'amount' => $amount);
        }

        return $tiers;
    }
}

if ( ! function_exists('team_milestone_tiers_valid'))
{
    /**
     * Validasi lengkap daftar tier (urutan, keunikan, ambang, anggaran CSV).
     *
     * Mengembalikan token alasan teknis (BUKAN prosa) + indeks baris 1-based
     * supaya pemanggil (model admin) dapat menyusun pesan Indonesia sendiri:
     *   'empty' | 'too_many' | 'target_out_of_range' | 'amount_out_of_range'
     *   | 'target_not_ascending' | 'csv_too_long'
     *
     * @param  array $tiers Hasil `team_milestone_tiers_parse()`
     * @return array{ok:bool,reason:string|null,index:int|null}
     */
    function team_milestone_tiers_valid(array $tiers)
    {
        $lim = team_milestone_limits();

        $n = count($tiers);
        if ($n < 1) {
            return array('ok' => FALSE, 'reason' => 'empty', 'index' => NULL);
        }
        if ($n > $lim['max_tiers']) {
            return array('ok' => FALSE, 'reason' => 'too_many', 'index' => NULL);
        }

        $prev = NULL;
        $i    = 0;
        foreach ($tiers as $tier) {
            $i++;

            $target = is_array($tier) ? ($tier['target'] ?? NULL) : NULL;
            $amount = is_array($tier) ? ($tier['amount'] ?? NULL) : NULL;

            $target = team_milestone_int_token($target);
            $amount = team_milestone_int_token($amount);

            if ($target === NULL || $target < 1 || $target > $lim['max_target']) {
                return array('ok' => FALSE, 'reason' => 'target_out_of_range', 'index' => $i);
            }
            if ($amount === NULL || $amount < 1 || $amount > $lim['max_amount']) {
                return array('ok' => FALSE, 'reason' => 'amount_out_of_range', 'index' => $i);
            }
            // Unik + menaik ketat (urutan tampilan & urutan klaim sekuensial).
            if ($prev !== NULL && $target <= $prev) {
                return array('ok' => FALSE, 'reason' => 'target_not_ascending', 'index' => $i);
            }
            $prev = $target;
        }

        // Anggaran kolom state: proyeksi CSV seluruh target wajib muat.
        $targets = team_milestone_tiers_targets($tiers);
        if (strlen(team_milestone_claimed_encode($targets)) > $lim['max_claimed_csv']) {
            return array('ok' => FALSE, 'reason' => 'csv_too_long', 'index' => NULL);
        }

        return array('ok' => TRUE, 'reason' => NULL, 'index' => NULL);
    }
}

if ( ! function_exists('team_milestone_tiers_encode'))
{
    /**
     * Daftar tier → JSON kanonik `[[target,amount],…]` (persist + audit
     * `after`). Urutan dipertahankan apa adanya (setelah validasi = menaik).
     *
     * @param  array $tiers
     * @return string
     */
    function team_milestone_tiers_encode(array $tiers)
    {
        $out = array();
        foreach ($tiers as $tier) {
            if ( ! is_array($tier)) {
                continue;
            }
            $target = team_milestone_int_token($tier['target'] ?? NULL);
            $amount = team_milestone_int_token($tier['amount'] ?? NULL);
            if ($target === NULL || $amount === NULL) {
                continue;
            }
            $out[] = array($target, $amount);
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }
}

if ( ! function_exists('team_milestone_tiers_targets'))
{
    /**
     * Daftar target saja (untuk perbandingan state klaim & batas CSV).
     *
     * @param  array $tiers
     * @return array<int,int>
     */
    function team_milestone_tiers_targets(array $tiers)
    {
        $out = array();
        foreach ($tiers as $tier) {
            if ( ! is_array($tier)) {
                continue;
            }
            $target = team_milestone_int_token($tier['target'] ?? NULL);
            if ($target !== NULL) {
                $out[] = $target;
            }
        }
        return $out;
    }
}

if ( ! function_exists('team_milestone_claimed_parse'))
{
    /**
     * CSV state klaim -> daftar target (menaik, unik).
     *
     * Toleran-aman: token non-integer, duplikat, atau di luar jangkauan
     * (`0` / > `max_target`) DIIABAIKAN — nilai asing hasil tamper tidak
     * pernah fatal DAN tidak dapat membuka klaim (target juga harus ada di
     * konfigurasi + tercapai).
     *
     * @param  mixed $csv
     * @return array<int,int>
     */
    function team_milestone_claimed_parse($csv)
    {
        if ($csv === NULL || $csv === FALSE) {
            return array();
        }
        if (is_int($csv)) {
            $csv = (string) $csv;
        }
        if ( ! is_string($csv)) {
            return array();
        }
        $csv = str_replace(array(' ', "\t", "\n", "\r"), '', $csv);
        if ($csv === '') {
            return array();
        }

        $lim      = team_milestone_limits();
        $targets = array();
        foreach (explode(',', $csv) as $token) {
            $val = team_milestone_int_token($token);
            if ($val === NULL || $val < 1 || $val > $lim['max_target']) {
                continue;
            }
            $targets[$val] = $val;
        }

        ksort($targets);
        return array_values($targets);
    }
}

if ( ! function_exists('team_milestone_claimed_encode'))
{
    /**
     * Daftar target → CSV kanonik (`'9,30'`; string kosong bila tidak ada).
     * Output selalu aman-disimpan ke `users.team_milestone_claimed`.
     *
     * @param  array $targets
     * @return string
     */
    function team_milestone_claimed_encode(array $targets)
    {
        $lim = team_milestone_limits();

        $clean = array();
        foreach ($targets as $raw) {
            $val = team_milestone_int_token($raw);
            if ($val === NULL || $val < 1 || $val > $lim['max_target']) {
                continue;
            }
            $clean[$val] = $val;
        }
        if (count($clean) === 0) {
            return '';
        }
        ksort($clean);
        return implode(',', array_values($clean));
    }
}

if ( ! function_exists('team_milestone_claimed_is_canonical'))
{
    /**
     * Apakah nilai kolom `users.team_milestone_claimed` sudah berbentuk
     * kanonik (dipakai CLI `--verify` sebagai deteksi tamper).
     *
     * @param  mixed $csv
     * @return bool
     */
    function team_milestone_claimed_is_canonical($csv)
    {
        if ($csv === NULL || $csv === '') {
            return TRUE;   // kedua bentuk "kosong" sah
        }
        if ( ! is_string($csv)) {
            return FALSE;
        }
        return (team_milestone_claimed_encode(team_milestone_claimed_parse($csv)) === $csv);
    }
}

if ( ! function_exists('team_milestone_claim_tx_id'))
{
    /**
     * transaction_id deterministik klaim milestone (SATU sumber untuk penulis
     * `User_model::claim_milestone()` dan pembaca riwayat).
     * UNIQUE `uk_wallet_ledger_user_tx_type (user_id, transaction_id, type)`
     * pada `wallet_ledger` = guard tingkat DB "satu kredit per user per tier".
     *
     * @param  int $user_id
     * @param  int $target
     * @return string
     */
    function team_milestone_claim_tx_id($user_id, $target)
    {
        return team_milestone_claim_tx_prefix() . (int) $user_id . '-T' . (int) $target;
    }
}

if ( ! function_exists('team_milestone_claim_tx_prefix'))
{
    /**
     * Prefix transaction_id klaim milestone (untuk query `LIKE`).
     *
     * @return string
     */
    function team_milestone_claim_tx_prefix()
    {
        return 'MSB-';
    }
}
