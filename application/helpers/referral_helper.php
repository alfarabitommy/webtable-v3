<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Plan 108 — Referral / Invite Code choke-point (auto-fill `/register?ref=CODE`).
 *
 * SATU sumber kebenaran untuk normalisasi, validasi, dan resolusi prioritas
 * kode undangan. Dipakai oleh `Auth::_referral_prefill()` (capture + prefill),
 * view `auth/register.php` (nilai fallback), dan bisa di-`include` langsung
 * dari script CLI verifikasi/migrasi.
 *
 * Kontrak panjang kode (keputusan pemilik repositori `dec-37ae9226e2838c01`,
 * refinement ke-1 plan/108):
 *   - Kode undangan Synapse SELALU tepat 6 karakter `[0-9A-Z]`
 *     (`User_model::_generate_invite_code()`, `Admin_model::generate_invite_code()`).
 *   - Sanitizer & input frontend (`maxlength="6"`) sama-sama memakai 6.
 *   - Kolom `users.invite_code` TETAP `VARCHAR(10)` (headroom storage, tanpa DDL).
 *
 * Murni: nol akses DB, nol session, nol output. Semua fungsi dibungkus
 * `function_exists()` supaya aman di-`include` ulang dari CLI
 * (pola `wa_group_helper.php` plan/105 / `ewallet_helper.php` plan/106).
 */

// Satu sumber panjang kode undangan (guarded: aman pada include berulang).
defined('REFERRAL_CODE_LENGTH') OR define('REFERRAL_CODE_LENGTH', 6);

if ( ! function_exists('referral_code_normalize'))
{
    /**
     * Sanitasi input mentah (mis. parameter `?ref=`) menjadi kode kanonik.
     *
     * Aturan (persis requirement plan/108 §5): buang SEMUA karakter
     * non-alfanumerik, `strtoupper`, lalu clamp ke REFERRAL_CODE_LENGTH.
     * Input non-scalar (`?ref[]=x` → array) dianggap kosong — mencegah
     * fatal/notice pada rute publik.
     *
     * Jaminan bentuk: hasil SELALU cocok `/^[0-9A-Z]{0,6}$/` — nol karakter
     * HTML yang mungkin lolos, sehingga aman disisipkan ke `value=""`.
     * Clamp adalah BATAS ATAS, bukan syarat panjang: input pendek
     * (mis. `?ref=ab` → `AB`) diteruskan apa adanya dan akan ditolak oleh
     * validasi form / lookup `users.invite_code` saat submit
     * (`auth_err_invite_invalid`). Sanitasi TIDAK menolak, hanya membersihkan.
     *
     * Untuk cek bentuk yang ketat pakai `referral_code_is_valid()`.
     *
     * @param  mixed $raw
     * @return string Kode kanonik (0..REFERRAL_CODE_LENGTH karakter), atau `''`.
     */
    function referral_code_normalize($raw)
    {
        if ( ! is_scalar($raw))
        {
            return '';
        }

        $clean = preg_replace('/[^0-9A-Za-z]/', '', (string) $raw);

        return substr(strtoupper((string) $clean), 0, REFERRAL_CODE_LENGTH);
    }
}

if ( ! function_exists('referral_code_is_valid'))
{
    /**
     * Validasi bentuk kode KANONIK PENUH: tepat REFERRAL_CODE_LENGTH karakter
     * `[0-9A-Z]`. Ini cek ketat (bukan sekadar hasil sanitasi) — dipakai
     * verifikator CLI dan pemanggil yang butuh kepastian bentuk.
     *
     * @param  mixed $code
     * @return bool
     */
    function referral_code_is_valid($code)
    {
        if ( ! is_string($code))
        {
            return FALSE;
        }

        return preg_match('/^[0-9A-Z]{'.REFERRAL_CODE_LENGTH.'}$/', $code) === 1;
    }
}

if ( ! function_exists('referral_code_resolve'))
{
    /**
     * Prioritas nilai prefill: `ref` pada URL saat ini MENANG atas nilai
     * tersimpan (session/cookie). Keduanya diasumsikan SUDAH ternormalisasi —
     * fungsi ini tidak menormalisasi ulang.
     *
     * @param  string $url_ref Kode dari `?ref=` request ini (boleh `''`).
     * @param  string $stored  Kode tersimpan dari session/cookie (boleh `''`).
     * @return string Anggota pertama yang tidak kosong, atau `''`.
     */
    function referral_code_resolve($url_ref, $stored)
    {
        $url_ref = is_string($url_ref) ? $url_ref : '';
        $stored  = is_string($stored) ? $stored : '';

        return $url_ref !== '' ? $url_ref : $stored;
    }
}

if ( ! function_exists('referral_capture_key'))
{
    /**
     * Nama key session + cookie untuk kode referral tertangkap.
     *
     * Satu sumber supaya controller (set/read) dan blok cleanup
     * (unset/delete) tidak pernah memakai string berbeda.
     *
     * Nilainya adalah KODE SHARE PUBLIK (sudah tampil di URL & dibagikan
     * member) → tanpa kebutuhan kerahasiaan. Jangan pernah menyimpan hal lain.
     *
     * @return string
     */
    function referral_capture_key()
    {
        return 'referral_code';
    }
}

if ( ! function_exists('referral_capture_ttl'))
{
    /**
     * Umur simpan kode referral (detik) = 30 hari.
     *
     * Refinement ke-1 plan/108: prospek sering mengklik link afiliasi dari
     * WhatsApp/Telegram lalu mendaftar beberapa hari kemudian. 30 hari
     * menyamai TTL cookie `site_lang` (plan/94 F1) dan tetap dibersihkan
     * segera setelah registrasi sukses.
     *
     * @return int
     */
    function referral_capture_ttl()
    {
        return 2592000; // 30 hari
    }
}
