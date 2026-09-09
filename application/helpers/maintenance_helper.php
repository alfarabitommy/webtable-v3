<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// ===================================================================
//  MAINTENANCE MODE GATE — plan/95
//
//  Dipanggil sebagai statement PERTAMA setelah parent::__construct()
//  di MY_Controller, Auth, dan Lang (G-1). Admin/Admin_auth TIDAK
//  pernah memanggil helper ini (exempt by construction, G-3a).
//  CLI di-skip (G-3c). Deteksi JSON parity MY_Exceptions::_wants_json
//  (G-6). Default saat key hilang = '0' (site live, S-2).
// ===================================================================

if ( ! function_exists('maintenance_is_active'))
{
    /**
     * Apakah maintenance mode aktif? Satu SELECT ber-index, di-cache
     * per-request. Key hilang/null -> FALSE (S-2).
     *
     * @return bool
     */
    function maintenance_is_active()
    {
        static $active = null;
        if ($active !== null)
        {
            return $active;
        }
        $ci =& get_instance();
        $ci->load->model('Admin_model');
        $active = ($ci->Admin_model->get_setting('is_maintenance_mode') === '1');
        return $active;
    }
}

if ( ! function_exists('_maintenance_wants_json'))
{
    /**
     * Deteksi JSON request — parity MY_Exceptions::_wants_json():
     * X-Requested-With: XMLHttpRequest (csrfFetch/fetch aplikasi) atau
     * Accept: application/json.
     *
     * @return bool
     */
    function _maintenance_wants_json()
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
}

if ( ! function_exists('maintenance_gate'))
{
    /**
     * Gate utama. Panggil PALING AWAL di constructor member/guest.
     * Bypass: CLI, session admin_id, mode non-aktif. Aktif & non-admin:
     * JSON -> api_error(503, MAINTENANCE_MODE); HTML -> 503 + view.
     *
     * @return void
     */
    function maintenance_gate()
    {
        // G-3c: CLI (scripts/cron/seeder) tidak pernah dikunci.
        if (is_cli())
        {
            return;
        }

        $ci =& get_instance();

        // G-3b: admin session fully exempt (admin tidak pernah lock out;
        // termasuk saat membuka URL member dari browser yang sama).
        if ( ! empty($ci->session->userdata('admin_id')))
        {
            return;
        }

        // S-2: key hilang/null -> normal.
        if ( ! maintenance_is_active())
        {
            return;
        }

        // Parity MY_Exceptions: security headers sebelum early-exit.
        if (class_exists('MY_Output'))
        {
            MY_Output::emit_security_headers();
        }

        if (_maintenance_wants_json())
        {
            // G-6: envelope standar (kalimat persis spek).
            if ( ! function_exists('api_error')
                && file_exists(APPPATH . 'helpers/api_helper.php'))
            {
                require_once APPPATH . 'helpers/api_helper.php';
            }
            if (function_exists('api_error'))
            {
                api_error('System is currently under maintenance. Please check back later.', 503, [], 'MAINTENANCE_MODE');
            }

            // Fallback defensif: tetap JSON + status, tanpa leak apa pun.
            set_status_header(503);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'System is currently under maintenance. Please check back later.',
                'errors'  => [],
                'data'    => null,
                'code'    => 'MAINTENANCE_MODE',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // HTML: 503 + no-store + view standalone (G-5/E-1). Tanpa 302.
        set_status_header(503);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        // CI3 Loader mem-buffer output view ke Output::$final_output
        // (Loader::_ci_load -> append_output) yang baru terkirim lewat
        // Output::_display() di akhir lifecycle. exit() langsung melewati
        // _display() -> body 0-byte (Chromium: "HTTP ERROR 503"). Dengan
        // $return=TRUE string view dikembalikan lalu di-echo SEBELUM exit
        // (pola sama _api_send di api_helper) sehingga body 503 terkirim.
        echo $ci->load->view('errors/html/maintenance', [], TRUE);
        exit;
    }
}
