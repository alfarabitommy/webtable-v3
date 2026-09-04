<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MY_Exceptions — security headers pada path error yang exit lebih awal
 * (Phase 10D WS-3).
 *
 * CI3 memanggil Exceptions::show_404() lalu exit(4), dan
 * _exception_handler()/_error_handler() (Common.php) juga exit(1) —
 * semuanya TIDAK melewati Output::_display(). Tanpa override ini, halaman
 * 404 / exception / fatal error tidak membawa security headers.
 *
 * Implementasi: emit header via MY_Output::emit_security_headers() (satu
 * sumber kebenaran yang sama dengan _display), lalu delegasi ke parent.
 * Pemanggilan ganda aman karena header() menimpa nilai identik.
 */
class MY_Exceptions extends CI_Exceptions {

    public function show_404($page = '', $log_error = TRUE)
    {
        $this->_emit_headers();
        return parent::show_404($page, $log_error);
    }

    public function show_error($heading, $message, $template = 'error_general', $status_code = 500)
    {
        $this->_emit_headers();
        return parent::show_error($heading, $message, $template, $status_code);
    }

    public function show_exception($exception)
    {
        $this->_emit_headers();
        return parent::show_exception($exception);
    }

    public function show_php_error($severity, $message, $filepath, $line)
    {
        $this->_emit_headers();
        return parent::show_php_error($severity, $message, $filepath, $line);
    }

    private function _emit_headers()
    {
        if (class_exists('MY_Output')) {
            MY_Output::emit_security_headers();
        }
    }
}
