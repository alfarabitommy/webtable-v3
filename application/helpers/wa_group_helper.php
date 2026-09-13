<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// ===================================================================
//  WA GROUP LINK HELPERS — Plan 105 (Dynamic WhatsApp Group Link)
//
//  Satu-satunya tempat aturan tautan undangan grup/komunitas WhatsApp
//  hidup (choke-point tunggal). Dipakai tiga konsumen:
//
//    1. Admin  : Admin::settings() POST — validasi + simpan bentuk kanonik.
//    2. Member : Help::index() — re-validasi saat render (baris DB bisa
//                disunting manual via phpMyAdmin → anti tamper).
//    3. CLI    : scripts/migrate_105_wa_group_link.php --verify.
//
//  Kontrak nilai:
//    wa_group_link_normalize() →  ''   = kosong / belum dikonfigurasi (SAH)
//                                 kanonik = tautan valid, siap disimpan
//                                 null = TIDAK valid (harus ditolak)
//    wa_group_link_url()       →  kanonik | ''  (TIDAK pernah null)
//                                 '' = kartu komunitas tidak dirender.
//
//  Aturan kanonik:
//    - host TEPAT `chat.whatsapp.com` (prefix `www.` dinormalkan;
//      case-insensitive), skema WAJIB https;
//    - tanpa userinfo (`@`), tanpa port, tanpa host lain (anti phishing);
//    - token path `[A-Za-z0-9_-]{6,64}`; bentuk legacy `/invite/<token>`
//      dinormalkan ke `/<token>`;
//    - input tanpa skema / `http://` dinaikkan ke `https://`;
//      query, fragment, dan trailing slash dibuang;
//    - panjang mentah <= 512 karakter; karakter format tak terlihat
//      (ZWSP/ZWNJ/ZWJ/BOM) dibuang sebelum validasi (higienitas paste).
//
//  Fungsi murni (tanpa dependensi CI) sehingga aman di-include dari CLI;
//  semua dibungkus function_exists() agar aman di-include ganda
//  (pola api_helper plan/76, i18n_helper plan/94, product_image plan/104).
//
//  Catatan i18n (L1/plan-103): helper ini TIDAK pernah mengembalikan
//  prosa user-facing. Pesan galat berbahasa Indonesia tinggal di
//  controller admin (Admin.php — di luar cakupan audit string member).
// ===================================================================

if ( ! function_exists('wa_group_link_normalize'))
{
    /**
     * Kanonikalisasi + validasi tautan undangan grup WhatsApp.
     *
     * @param  mixed $raw Nilai mentah (POST / DB / argumen CLI).
     * @return string|null '' = kosong (tidak dikonfigurasi, SAH);
     *                     string kanonik = valid;
     *                     null = TIDAK valid.
     */
    function wa_group_link_normalize($raw)
    {
        $raw = is_string($raw) ? $raw : '';

        // Buang karakter format tak terlihat (sering ikut saat copy-paste).
        $raw = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $raw);
        $raw = trim($raw);

        if ($raw === '')        { return ''; }   // kosong = belum dikonfigurasi
        if (strlen($raw) > 512) { return NULL; }

        // Input tanpa skema atau http:// → naikkan ke https (kanonik tunggal).
        if (stripos($raw, 'chat.whatsapp.com/') === 0)        { $raw = 'https://' . $raw; }
        if (stripos($raw, 'http://chat.whatsapp.com/') === 0) { $raw = 'https://' . substr($raw, 7); }

        $p = parse_url($raw);
        if ( ! is_array($p) || empty($p['scheme']) || empty($p['host'])) { return NULL; }
        if (strtolower($p['scheme']) !== 'https') { return NULL; }
        if (isset($p['user']) || isset($p['pass']) || isset($p['port'])) { return NULL; }

        $host = strtolower($p['host']);
        if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }
        if ($host !== 'chat.whatsapp.com') { return NULL; }

        $path = isset($p['path']) ? trim($p['path'], '/') : '';
        $path = preg_replace('#^invite/#i', '', $path);
        if ( ! preg_match('/^[A-Za-z0-9_-]{6,64}$/', $path)) { return NULL; }

        return 'https://chat.whatsapp.com/' . $path;
    }
}

if ( ! function_exists('wa_group_link_url'))
{
    /**
     * Choke-point tampil (member): kanonik ATAU string kosong.
     *
     * Nilai tidak valid / kosong / hasil tamper manual di DB SELALU
     * menjadi '' → pemanggil (Help controller + view) menyembunyikan
     * kartu komunitas sepenuhnya, tanpa tautan rusak.
     *
     * @param  mixed $raw
     * @return string
     */
    function wa_group_link_url($raw)
    {
        $canonical = wa_group_link_normalize($raw);

        return is_string($canonical) ? $canonical : '';
    }
}
