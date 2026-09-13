<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// ===================================================================
//  E-WALLET HELPERS — Plan 106 (Exclusive E-Wallet Withdrawal Gateway)
//
//  Satu-satunya tempat aturan nomor HP e-wallet dan masking tampilan
//  hidup (choke-point tunggal). Dipakai empat konsumen:
//
//    1. Member : Wallet::bind_bank() POST — normalisasi + validasi nomor.
//    2. View   : wallet/bank_bind.php, wallet/withdraw.php,
//                wallet/index.php — masking nomor terikat.
//    3. CLI    : scripts/migrate_106_ewallet_withdrawal.php — verifikator
//                otoritatif per baris (arsip binding legacy).
//    4. Admin  : views/admin/user_detail.php — masking nomor di kartu E-Wallet.
//
//  Aturan kanonik nomor HP e-wallet:
//    - numerik murni (non-digit dibuang);
//    - awalan `62` / `0062` dinormalkan menjadi `0` (paritas Auth::_normalize_phone);
//    - digit tanpa awalan `0` diberi awalan `0`;
//    - hasil akhir WAJIB cocok ^08[0-9]{8,11}$ → diawali `08`,
//      panjang TOTAL 10–13 digit.
//
//  Kontrak nilai:
//    ewallet_phone_validate() → string kanonik `08…` (valid) | null (invalid)
//    ewallet_phone_mask()     → string tampilan ter-mask (tidak pernah null)
//
//  Fungsi murni (tanpa dependensi CI) sehingga aman di-include dari CLI;
//  semua dibungkus function_exists() agar aman di-include ganda
//  (pola api_helper plan/76, i18n_helper plan/94, product_image plan/104,
//  wa_group plan/105).
//
//  Catatan i18n (L1/plan-103): helper ini TIDAK pernah mengembalikan prosa
//  user-facing — pesan galat berbahasa idiom ada di controller member
//  (`lang('bb_err_phone')`) dan prosa Indonesia di controller admin.
// ===================================================================

if ( ! function_exists('ewallet_phone_pattern'))
{
    /**
     * Pola regex otoritatif nomor HP e-wallet (tanpa delimiter).
     *
     * Dipakai sebagai satu-satunya sumber aturan; CLI migrasi memakai pola
     * yang sama untuk filter massal SQL (REGEXP) dan memverifikasi ulang
     * tiap baris dengan ewallet_phone_is_valid().
     *
     * @return string
     */
    function ewallet_phone_pattern()
    {
        return '^08[0-9]{8,11}$';
    }
}

if ( ! function_exists('ewallet_phone_normalize'))
{
    /**
     * Normalisasi input nomor HP e-wallet (backend tetap sumber kebenaran).
     *
     * @param  mixed $raw
     * @return string Digit ternormalisasi (bisa belum valid — cek terpisah).
     */
    function ewallet_phone_normalize($raw)
    {
        $raw    = is_string($raw) ? $raw : (string) $raw;
        $digits = preg_replace('/\D/', '', trim($raw));
        $digits = is_string($digits) ? $digits : '';

        // 62… / 0062… → 0… (hanya bila memang awalan negara, bukan bagian nomor).
        if (strpos($digits, '0062') === 0 && strlen($digits) > 4)
        {
            $digits = substr($digits, 4);
        }
        elseif (strpos($digits, '62') === 0 && strlen($digits) > 2)
        {
            $digits = substr($digits, 2);
        }

        if ($digits !== '' && $digits[0] !== '0')
        {
            $digits = '0' . $digits;
        }

        return $digits;
    }
}

if ( ! function_exists('ewallet_phone_is_valid'))
{
    /**
     * Validasi digit (sudah ternormalisasi) terhadap aturan e-wallet.
     *
     * @param  mixed $digits
     * @return bool TRUE bila numerik, diawali `08`, dan panjang 10–13 digit.
     */
    function ewallet_phone_is_valid($digits)
    {
        if ( ! is_string($digits) && ! is_int($digits)) { return FALSE; }
        $digits = (string) $digits;

        return preg_match('/' . ewallet_phone_pattern() . '/', $digits) === 1;
    }
}

if ( ! function_exists('ewallet_phone_validate'))
{
    /**
     * Normalisasi + validasi satu langkah (kontrak tunggal untuk controller).
     *
     * @param  mixed $raw Nilai mentah dari POST / DB.
     * @return string|null String kanonik `08…` bila valid; null bila tidak.
     */
    function ewallet_phone_validate($raw)
    {
        $digits = ewallet_phone_normalize($raw);

        return ewallet_phone_is_valid($digits) ? $digits : NULL;
    }
}

if ( ! function_exists('ewallet_phone_mask'))
{
    /**
     * Masking nomor untuk tampilan (kartu terikat, kartu penarikan, riwayat,
     * kartu admin) — algoritma lama dipertahankan apa adanya agar tampilan
     * tidak berubah: 4 digit awal + '*' × (len−7) + 3 digit akhir; nomor
     * pendek (<= 7) ditampilkan apa adanya.
     *
     * Nilai non-numerik (mis. baris legacy yang belum dimigrasikan) tetap
     * diproses dengan aturan panjang yang sama — pemanggil WAJIB tetap
     * meng-escape hasilnya saat render (htmlspecialchars).
     *
     * @param  mixed $value
     * @return string
     */
    function ewallet_phone_mask($value)
    {
        $value = is_string($value) ? $value : (string) $value;
        $len   = strlen($value);

        if ($len <= 7) { return $value; }

        return substr($value, 0, 4) . str_repeat('*', $len - 7) . substr($value, -3);
    }
}

if ( ! function_exists('ewallet_default_code'))
{
    /**
     * Code provider default untuk backfill nama bank yang tidak dikenal.
     *
     * @return string
     */
    function ewallet_default_code()
    {
        return 'DANA';
    }
}
