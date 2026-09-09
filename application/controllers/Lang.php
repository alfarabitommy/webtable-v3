<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Lang — Plan 94 (F1): switcher bahasa member (en/id).
 *
 * GET /lang/switch/(en|id) — tersedia untuk member login MAUPUN pra-login
 * (Auth/change_password memakai CI_Controller, bukan MY_Controller, jadi
 * tidak kena guard redirect login). Aksi non-finansial, idempotent, GET-only
 * → tanpa CSRF token (CI3 hanya melindungi POST-family), tanpa DB, tanpa
 * rate limit, tanpa audit.
 *
 * Alur:
 *   1. validasi kode ∈ {en, id} — selain itu 404;
 *   2. persist session('site_lang');
 *   3. persist cookie 'site_lang' 30 hari (fallback lintas restart browser);
 *   4. redirect balik ke HTTP_REFERER same-host (L8), fallback base_url().
 */
class Lang extends CI_Controller {

    public function __construct()
    {
        parent::__construct();

        // plan/95 (G-1): maintenance gate — celah controller publik terakhir
        // (selain Auth). Saat maintenance aktif, /lang/switch ikut 503.
        maintenance_gate();
    }

    /**
     * GET /lang/switch/(:any)
     *
     * @param string $code
     * @return void
     */
    public function switch($code = '')
    {
        $code = (string) $code;
        $map  = i18n_idioms();

        if ( ! isset($map[$code]))
        {
            show_404();
            return;
        }

        // Session dulu (sumber utama selama sesi hidup)…
        $this->session->set_userdata('site_lang', $code);

        // …lalu cookie 30 hari sebagai fallback lintas restart browser.
        // cookie_secure diambil otomatis dari config (transport-aware,
        // plan/40) saat atribut tidak dieksplisitkan.
        $this->input->set_cookie(array(
            'name'   => 'site_lang',
            'value'  => $code,
            'expire' => 86400 * 30, // 30 hari
        ));

        $referer = (string) $this->input->server('HTTP_REFERER', TRUE);
        redirect(i18n_is_referer_same_host($referer) ? $referer : base_url());
    }
}
