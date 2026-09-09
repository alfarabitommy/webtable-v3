<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// ===================================================================
//  I18N HELPERS — Plan 94 (F1 Dual-Language Engine, member-facing ONLY)
//
//  Resolusi bahasa member: session('site_lang') → cookie('site_lang')
//  → 'en' (default visitor baru). Pemetaan kode pendek → idiom CI:
//  'en' → english, 'id' → indonesian (I18N_IDIOMS satu-sumber).
//
//  Panggil i18n_apply() di constructor request member (MY_Controller &
//  Auth) SETELAH parent::__construct() — session/helper sudah autoload.
//  Admin (Admin/Admin_auth) TIDAK PERNAH memanggil ini: admin 100%
//  Indonesian (invariant L1). Hanya SATU idiom dimuat per request (L4) —
//  Lang::load() meng-merge array, idiom kedua menimpa baris pertama.
//
//  Semua fungsi dibungkus function_exists() agar aman di-include ganda
//  (pola api_helper plan/76).
// ===================================================================

if ( ! function_exists('i18n_idioms'))
{
    /**
     * Peta kode pendek → idiom CI. SATU-SATUNYA sumber pemetaan.
     *
     * @return array
     */
    function i18n_idioms()
    {
        return array('en' => 'english', 'id' => 'indonesian');
    }
}

if ( ! function_exists('i18n_default_code'))
{
    /**
     * Bahasa default visitor baru / first-time user (spek: English).
     *
     * @return string
     */
    function i18n_default_code()
    {
        return 'en';
    }
}

if ( ! function_exists('i18n_resolve'))
{
    /**
     * Resolve kode bahasa aktif: session('site_lang') → cookie('site_lang')
     * → default 'en'. Selalu mengembalikan 'en' | 'id'.
     *
     * @return string
     */
    function i18n_resolve()
    {
        $ci   =& get_instance();
        $map  = i18n_idioms();
        $code = (string) $ci->session->userdata('site_lang');

        if ( ! isset($map[$code]))
        {
            $code = (string) $ci->input->cookie('site_lang', TRUE);
        }

        if ( ! isset($map[$code]))
        {
            $code = i18n_default_code();
        }

        return $code;
    }
}

if ( ! function_exists('i18n_idiom'))
{
    /**
     * Kode pendek → idiom CI ('english'|'indonesian'); kode tak dikenal
     * jatuh aman ke 'english'.
     *
     * @param string $code
     * @return string
     */
    function i18n_idiom($code)
    {
        $map = i18n_idioms();
        return isset($map[$code]) ? $map[$code] : 'english';
    }
}

if ( ! function_exists('i18n_apply'))
{
    /**
     * Bootstrap bahasa untuk SATU request member: muat app_lang.php pada
     * idiom terpilih (tepat satu — L4) + inject var view site_lang_code.
     *
     * @return string Kode bahasa aktif ('en'|'id')
     */
    function i18n_apply()
    {
        $ci   =& get_instance();
        $code = i18n_resolve();

        $ci->lang->load('app_lang', i18n_idiom($code));
        $ci->load->vars(array('site_lang_code' => $code));

        return $code;
    }
}

if ( ! function_exists('i18n_is_referer_same_host'))
{
    /**
     * Validasi referer untuk redirect pasca-switch (L8): host harus sama
     * dengan HTTP_HOST — anti open-redirect. Kosong/eksternal → FALSE
     * (caller jatuh ke base_url()).
     *
     * @param string $url
     * @return bool
     */
    function i18n_is_referer_same_host($url)
    {
        $host = (string) parse_url((string) $url, PHP_URL_HOST);
        $self = (string) (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');

        return ($host !== '' && $self !== '' && strcasecmp($host, $self) === 0);
    }
}
