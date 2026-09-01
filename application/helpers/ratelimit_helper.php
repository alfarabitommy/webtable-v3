<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// ===================================================================
//  RATE LIMIT HELPERS (Phase 10B)
//
//  Copy pesan Indonesia terpusat — satu bahasa, satu format, untuk
//  seluruh endpoint yang di-instrumentasi (Auth, Admin_auth, Rentals,
//  Wallet). rate_limit_json_response() mengakhiri request (exit) dengan
//  HTTP 429 + payload JSON, dipakai saat $this->input->is_ajax_request().
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
    set_status_header(429);
    header('Content-Type: application/json');
    echo json_encode([
        'success'     => false,
        'error'       => 'too_many_attempts',
        'message'     => rate_limit_message($throttle['remaining_seconds']),
        'retry_after' => (int) $throttle['remaining_seconds'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
