<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// ===================================================================
//  RATE LIMIT HELPERS (Phase 10B)
//
//  Copy pesan Indonesia terpusat — satu bahasa, satu format, untuk
//  seluruh endpoint yang di-instrumentasi (Auth, Admin_auth, Rentals,
//  Wallet). rate_limit_json_response() mengakhiri request (exit) dengan
//  HTTP 429 + payload JSON, dipakai saat $this->input->is_ajax_request().
//
//  M9/P7 (plan/76 Batch 0): payload 429 didelegasikan ke api_error()
//  (helpers/api_helper.php) — satu choke-point JSON. Body mempertahankan
//  SEMUA key legacy root {success, error, message, retry_after} + status
//  429 + Content-Type: application/json; envelope menambah key additive
//  {errors, data, code} (paritas semantik; urutan key JSON tidak relevan
//  bagi konsumen — semua parse via JSON.parse/r.json()).
// ===================================================================

/**
 * Pesan lockout standar web (Indonesia).
 *
 * @param int $remaining_seconds Sisa waktu lockout dalam detik
 * @return string
 */
function rate_limit_message($remaining_seconds) {
    $minutes = max(1, (int) ceil($remaining_seconds / 60));
    return 'Terlalu banyak percobaan gagal. Silakan coba lagi dalam ' . $minutes . ' menit.';
}

/**
 * Response AJAX/JSON untuk request yang diblokir: HTTP 429 + payload
 * terstruktur { success, error, message, retry_after }. Mengakhiri
 * request (echo + exit), konsisten dengan pola controller AJAX existing.
 *
 * @param array $throttle Hasil Rate_limit_model::check()
 */
function rate_limit_json_response($throttle) {
    if ( ! function_exists('api_error') && file_exists(APPPATH . 'helpers/api_helper.php')) {
        require_once APPPATH . 'helpers/api_helper.php';
    }

    $message = rate_limit_message($throttle['remaining_seconds']);

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
