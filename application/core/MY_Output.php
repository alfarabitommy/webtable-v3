<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MY_Output — Global Security Headers (Phase 10D WS-3)
 *
 * Choke point output normal CI3: system/core/CodeIgniter.php:552 memanggil
 * $OUT->_display() di setiap request yang mencapai akhir lifecycle
 * (termasuk AJAX JSON dan CSV export).
 *
 * PENTING: path error yang exit lebih awal (404 via Exceptions::show_404,
 * uncaught exception, fatal error) TIDAK melewati _display(). Karena itu
 * logika header dipusatkan di emit_security_headers() (static, header()
 * langsung) dan dipanggil juga oleh MY_Exceptions (lihat WS-3 blueprint).
 *
 * HSTS hanya dipasang di production DAN saat request sudah HTTPS (termasuk
 * TLS yang di-terminate di proxy via X-Forwarded-Proto) — mencegah HSTS
 * poisoning lewat koneksi HTTP/development.
 */
class MY_Output extends CI_Output {

    public function _display($output = '')
    {
        self::emit_security_headers();
        parent::_display($output);
    }

    /**
     * Satu sumber kebenaran header keamanan. Dipakai oleh _display() dan
     * oleh MY_Exceptions untuk path error yang exit sebelum _display().
     * header() dengan $replace default (true) membuat pemanggilan ganda
     * pada path yang dilalui dua kali (mis. error non-fatal) aman: header
     * lama ditimpa nilai identik, tidak ada duplikat.
     */
    public static function emit_security_headers()
    {
        header('X-Frame-Options: SAMEORIGIN');               // clickjacking
        header('X-Content-Type-Options: nosniff');           // anti MIME-sniffing
        header('X-XSS-Protection: 1; mode=block');           // legacy XSS filter
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        if (ENVIRONMENT === 'production' && self::_request_is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Deteksi HTTPS request (termasuk TLS termination di proxy).
     */
    private static function _request_is_https()
    {
        return (! empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    }
}
