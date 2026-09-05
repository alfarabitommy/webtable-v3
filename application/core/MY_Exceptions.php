<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MY_Exceptions — security headers pada path error yang exit lebih awal
 * (Phase 10D WS-3), + JSON error envelope untuk request AJAX (M9/P7,
 * plan/76 Batch E).
 *
 * CI3 memanggil Exceptions::show_404() lalu exit(4), dan
 * _exception_handler()/_error_handler() (Common.php) juga exit(1) —
 * semuanya TIDAK melewati Output::_display(). Tanpa override ini, halaman
 * 404 / exception / fatal error tidak membawa security headers.
 *
 * Batch E (plan/76 §8): saat request mengindikasikan JSON
 * (X-Requested-With: XMLHttpRequest — dipakai csrfFetch() dan fetch()
 * AJAX aplikasi ini — atau Accept: application/json), error
 * 404/show_error/exception/php-error diubah menjadi envelope JSON
 * terstandar via api_error() (choke-point helpers/api_helper.php), bukan
 * halaman HTML, sehingga r.json() di sisi frontend tidak pernah gagal
 * parse (sebelumnya: HTML 500 -> r.json() reject -> toast "kesalahan
 * jaringan"). Detail teknis (pesan exception/DB/PHP) hanya masuk server
 * log, TIDAK bocor ke klien. Jalur non-AJAX tetap mendelegasikan ke
 * parent (HTML stock CI3 — branding error terpisah, out of scope).
 */
class MY_Exceptions extends CI_Exceptions {

    public function show_404($page = '', $log_error = TRUE)
    {
        if ($this->_wants_json())
        {
            if ($log_error)
            {
                log_message('error', '404 Page Not Found: ' . $page);
            }
            $this->_emit_json_error('Halaman tidak ditemukan.', 404, 'not_found');
        }

        $this->_emit_headers();
        return parent::show_404($page, $log_error);
    }

    public function show_error($heading, $message, $template = 'error_general', $status_code = 500)
    {
        if ($this->_wants_json())
        {
            $detail = is_array($message) ? implode(' | ', $message) : (string) $message;
            log_message('error', 'show_error: ' . $detail);
            $this->_emit_json_error('Terjadi kesalahan sistem. Silakan coba lagi.', (int) $status_code, 'internal_error');
        }

        $this->_emit_headers();
        return parent::show_error($heading, $message, $template, $status_code);
    }

    public function show_exception($exception)
    {
        if ($this->_wants_json())
        {
            $this->_clean_buffers();
            log_message('error', 'Exception: ' . $exception->getMessage()
                . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
            $this->_emit_json_error('Terjadi kesalahan sistem. Silakan coba lagi.', 500, 'internal_error');
        }

        $this->_emit_headers();
        return parent::show_exception($exception);
    }

    public function show_php_error($severity, $message, $filepath, $line)
    {
        if ($this->_wants_json())
        {
            $this->_clean_buffers();
            log_message('error', 'Severity: ' . $severity . ' --> ' . $message . ' ' . $filepath . ' ' . $line);
            $this->_emit_json_error('Terjadi kesalahan sistem. Silakan coba lagi.', 500, 'internal_error');
        }

        $this->_emit_headers();
        return parent::show_php_error($severity, $message, $filepath, $line);
    }

    private function _emit_headers()
    {
        if (class_exists('MY_Output')) {
            MY_Output::emit_security_headers();
        }
    }

    /**
     * Deteksi permintaan JSON (AJAX/fetch): header X-Requested-With khas
     * csrfFetch()/fetch() aplikasi, atau Accept: application/json.
     *
     * @return bool
     */
    private function _wants_json()
    {
        if (is_cli())
        {
            return FALSE;
        }

        $xrw = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            ? strtolower(trim((string) $_SERVER['HTTP_X_REQUESTED_WITH']))
            : '';
        if ($xrw === 'xmlhttprequest')
        {
            return TRUE;
        }

        $accept = isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
        return (stripos($accept, 'application/json') !== FALSE);
    }

    /**
     * Buang buffer output parsial apa pun (parity manajemen ob
     * CI_Exceptions) agar envelope JSON tidak terkontaminasi HTML/teks
     * yang sempat ter-render sebelum error terjadi.
     */
    private function _clean_buffers()
    {
        while (ob_get_level() > $this->ob_level)
        {
            ob_end_clean();
        }
    }

    /**
     * Emit security headers + envelope JSON error via api_error()
     * (choke-point plan/76). Detail error teknis TIDAK dikirim ke klien —
     * hanya pesan generik + status + kode mesin.
     *
     * @param string $message     Pesan human-readable untuk klien
     * @param int    $status_code HTTP status (404/500/dst)
     * @param string $code        Kode mesin envelope (not_found/internal_error)
     * @return void
     */
    private function _emit_json_error($message, $status_code, $code)
    {
        $this->_emit_headers();

        if ( ! function_exists('api_error') && file_exists(APPPATH . 'helpers/api_helper.php'))
        {
            require_once APPPATH . 'helpers/api_helper.php';
        }

        if ( ! function_exists('api_error'))
        {
            // Fallback defensif bila helper tak tersedia: tetap JSON +
            // status, tanpa leak detail apa pun.
            $status_code = (int) $status_code;
            if ($status_code < 100 || $status_code > 599)
            {
                $status_code = 500;
            }

            if ( ! headers_sent())
            {
                set_status_header($status_code);
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'message' => $message,
                'errors'  => [],
                'data'    => null,
                'code'    => $code,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        api_error($message, $status_code, [], $code);
    }
}
