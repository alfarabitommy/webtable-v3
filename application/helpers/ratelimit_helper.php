<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// ===================================================================
//  RATE LIMIT HELPERS (Phase 10B)
//
//  Copy pesan lockout terpusat — satu bahasa per idiom, satu format, untuk
//  seluruh endpoint yang di-instrumentasi (Auth, Admin_auth, Rentals,
//  Team, Wallet). rate_limit_json_response() mengakhiri request (exit)
//  dengan HTTP 429 + payload JSON, dipakai saat is_ajax_request().
//
//  Plan 103: pesan TIDAK lagi literal Indonesia. Member mengikuti idiom
//  request (kamus `ratelimit_too_many`); admin (Admin_auth) meneruskan
//  idiom `'id'` eksplisit karena panel admin 100% Indonesian (L1) dan
//  tidak pernah memanggil i18n_apply().
//
//  M9/P7 (plan/76 Batch 0): payload 429 didelegasikan ke api_error()
//  (helpers/api_helper.php) — satu choke-point JSON. Body mempertahankan
//  SEMUA key legacy root {success, error, message, retry_after} + status
//  429 + Content-Type: application/json; envelope menambah key additive
//  {errors, data, code} (paritas semantik; urutan key JSON tidak relevan
//  bagi konsumen — semua parse via JSON.parse/r.json()).
// ===================================================================

/**
 * Pesan lockout standar, mengikuti IDIOM AKTIF request member.
 *
 * Plan 103: helper ini dipakai DUA kelas konsumen yang berbeda bahasa:
 *   - member (Auth, Rentals, Team, Wallet): idiom = session/cookie
 *     `site_lang` yang sudah dimuat `i18n_apply()` → panggil tanpa argumen.
 *   - admin (Admin_auth): panel admin 100% Indonesian (invariant L1) dan
 *     TIDAK PERNAH memanggil `i18n_apply()`, sehingga `lang()` di sana
 *     akan membaca idiom default config (english). Karena itu call-site
 *     admin WAJIB meneruskan `'id'` eksplisit.
 *
 * @param int         $remaining_seconds Sisa waktu lockout dalam detik
 * @param string|null $idiom             'en'|'id'|null (null = idiom request)
 * @return string
 */
function rate_limit_message($remaining_seconds, $idiom = NULL) {
    $minutes = max(1, (int) ceil($remaining_seconds / 60));

    if ($idiom !== NULL) {
        return sprintf(_rate_limit_line($idiom), $minutes);
    }

    return sprintf(lang('ratelimit_too_many'), $minutes);
}

/**
 * Ambil baris kamus `ratelimit_too_many` pada idiom tertentu TANPA
 * mengubah idiom request aktif (tidak menyentuh session/cookie dan tidak
 * memuat idiom kedua secara permanen — L4).
 *
 * @param  string $idiom 'en'|'id'
 * @return string Baris kamus (fallback: teks Indonesia kanonik)
 */
function _rate_limit_line($idiom) {
    $fallback = 'Terlalu banyak percobaan gagal. Silakan coba lagi dalam %d menit.';
    $map      = function_exists('i18n_idioms') ? i18n_idioms() : array('en' => 'english', 'id' => 'indonesian');

    if ( ! isset($map[$idiom])) {
        return $fallback;
    }

    $file = APPPATH . 'language/' . $map[$idiom] . '/app_lang.php';
    if ( ! is_file($file)) {
        return $fallback;
    }

    $lang = array();
    include $file;

    return isset($lang['ratelimit_too_many']) ? (string) $lang['ratelimit_too_many'] : $fallback;
}

/**
 * Response AJAX/JSON untuk request yang diblokir: HTTP 429 + payload
 * terstruktur { success, error, message, retry_after }. Mengakhiri
 * request (echo + exit), konsisten dengan pola controller AJAX existing.
 *
 * Plan 103: `$idiom` diteruskan ke rate_limit_message() sehingga admin
 * (Admin_auth) tetap menerima pesan Indonesia (L1) sementara member
 * mengikuti bahasa sesi. Body JSON TIDAK berubah bentuk.
 *
 * @param array       $throttle Hasil Rate_limit_model::check()
 * @param string|null $idiom    'id' untuk panel admin; null = idiom request
 */
function rate_limit_json_response($throttle, $idiom = NULL) {
    if ( ! function_exists('api_error') && file_exists(APPPATH . 'helpers/api_helper.php')) {
        require_once APPPATH . 'helpers/api_helper.php';
    }

    $message = rate_limit_message($throttle['remaining_seconds'], $idiom);

    api_error(
        $message,
        429,
        [],
        'too_many_attempts',
        [
            'error'       => 'too_many_attempts',
            'message'     => $message,
            'retry_after' => (int) $throttle['remaining_seconds'],
        ]
    );
}
