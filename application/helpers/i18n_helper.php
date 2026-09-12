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

// ===================================================================
//  Plan 103 — DATE/TIME LOCALIZATION
//
//  CI3 `date('d M Y')` selalu memakai nama bulan/hari INGGRIS: di mode
//  `id` ini adalah kebocoran bahasa yang tidak bisa diperbaiki di layer
//  kamus. `date_lang.php` CI3 juga TIDAK dimuat — i18n_apply() hanya
//  memuat app_lang.php (plan/94 L4: tepat satu idiom per request).
//
//  Solusi: leksikon tanggal hidup di app_lang.php (prefix `dt_*`) dan
//  helper ini menjadi SATU-SATUNYA titik format tanggal member-facing.
//
//  Invariant:
//   - Angka TIDAK pernah diterjemahkan (L6) — `d`, `Y`, `H`, `i`, `s`
//     murni lewat PHP `date()`; hanya token `D` (hari) & `M` (bulan)
//     yang disubstitusi dari kamus.
//   - Jam tetap WIB (pin M2 di MY_Controller/Auth) → timezone TIDAK
//     disentuh di sini; `WIB` adalah label zona teknis (tidak diterjemah).
// ===================================================================

if ( ! function_exists('i18n_date_lexicon'))
{
    /**
     * Peta token nama hari/bulan → key kamus. SATU-SUMBER.
     *
     * @return array{tokens:array<string,string>,keys:array<string,string>}
     */
    function i18n_date_lexicon()
    {
        return array(
            // token PHP date() => key kamus (nilai EN adalah teks kanonik PHP)
            'tokens' => array(
                'D' => array(
                    'Mon' => 'dt_mon', 'Tue' => 'dt_tue', 'Wed' => 'dt_wed',
                    'Thu' => 'dt_thu', 'Fri' => 'dt_fri', 'Sat' => 'dt_sat',
                    'Sun' => 'dt_sun',
                ),
                'M' => array(
                    'Jan' => 'dt_jan', 'Feb' => 'dt_feb', 'Mar' => 'dt_mar',
                    'Apr' => 'dt_apr', 'May' => 'dt_may', 'Jun' => 'dt_jun',
                    'Jul' => 'dt_jul', 'Aug' => 'dt_aug', 'Sep' => 'dt_sep',
                    'Oct' => 'dt_oct', 'Nov' => 'dt_nov', 'Dec' => 'dt_dec',
                ),
            ),
            // token yang perlu substitusi (D = hari, M = bulan)
            'keys' => array('D', 'M'),
        );
    }
}

if ( ! function_exists('_i18n_date_subst'))
{
    /**
     * Substitusi token nama hari/bulan pada hasil `date()` sesuai idiom
     * aktif. Aman bila kamus belum dimuat (lang() → NULL): nilai asli
     * PHP dipertahankan.
     *
     * @param  string $formatted Hasil date($fmt, $ts)
     * @return string
     */
    function _i18n_date_subst($formatted)
    {
        $lex = i18n_date_lexicon();

        foreach ($lex['keys'] as $token) {
            foreach ($lex['tokens'][$token] as $english => $key) {
                if (strpos($formatted, $english) === FALSE) {
                    continue;
                }
                $translated = lang($key);
                if (is_string($translated) && $translated !== '') {
                    $formatted = str_replace($english, $translated, $formatted);
                }
            }
        }

        return $formatted;
    }
}

if ( ! function_exists('i18n_date'))
{
    /**
     * Format tanggal ber-lokalisasi (tanpa komponen jam).
     *
     * @param  int|string|null $ts  Epoch detik, string tanggal, atau NULL (= now)
     * @param  string          $fmt Format PHP date() (default 'd M Y')
     * @return string
     */
    function i18n_date($ts = NULL, $fmt = 'd M Y')
    {
        return _i18n_date_subst(date($fmt, i18n_ts($ts)));
    }
}

if ( ! function_exists('i18n_datetime'))
{
    /**
     * Format tanggal + jam ber-lokalisasi (tanpa detik).
     *
     * @param  int|string|null $ts Epoch detik, string tanggal, atau NULL (= now)
     * @return string
     */
    function i18n_datetime($ts = NULL)
    {
        return _i18n_date_subst(date('d M Y H:i', i18n_ts($ts)));
    }
}

if ( ! function_exists('i18n_ts'))
{
    /**
     * Normalisasi input waktu → epoch detik (int). String non-numerik
     * di-parse lewat strtotime(); nilai tak valid → time().
     *
     * @param  int|string|null $ts
     * @return int
     */
    function i18n_ts($ts)
    {
        if ($ts === NULL || $ts === '') {
            return time();
        }
        if (is_int($ts) || (is_string($ts) && ctype_digit($ts))) {
            return (int) $ts;
        }
        $parsed = strtotime((string) $ts);

        return ($parsed === FALSE) ? time() : (int) $parsed;
    }
}

// ===================================================================
//  Plan 103 — PERSISTENT NOTIFICATION RENDERER
//
//  Notifikasi disimpan di `user_notifications` dengan `title_key` +
//  `params` (JSON) sejak plan/103; teks kolom `title`/`message` tetap
//  diisi sebagai retensi & fallback. Renderer ini menerjemahkan baris
//  saat DIBACA sehingga member dapat mengganti bahasa kapan saja dan
//  notifikasi lama ikut berubah idiom.
//
//  Baris legacy (title_key NULL — hasil backfill yang tidak dikenali)
//  dirender apa adanya dari kolom DB (tanpa kehilangan data).
// ===================================================================

if ( ! function_exists('i18n_notification_text'))
{
    /**
     * Terjemahkan satu baris notifikasi ke idiom request aktif.
     *
     * @param  array $row Baris user_notifications (title, message, title_key?, params?)
     * @return array{title:string,message:string}
     */
    function i18n_notification_text($row)
    {
        $row = (array) $row;

        $key = isset($row['title_key']) ? (string) $row['title_key'] : '';

        if ($key === '')
        {
            return array(
                'title'   => isset($row['title']) ? (string) $row['title'] : '',
                'message' => isset($row['message']) ? (string) $row['message'] : '',
            );
        }

        $title_key = $key . '_title';
        $body_key  = $key . '_body';

        $title = lang($title_key);
        $body  = lang($body_key);

        // Kunci tidak dikenal (mis. key dihapus dari kamus) → fallback DB.
        if ( ! is_string($title) || $title === '')
        {
            $title = isset($row['title']) ? (string) $row['title'] : '';
        }
        if ( ! is_string($body) || $body === '')
        {
            $body = isset($row['message']) ? (string) $row['message'] : '';
        }

        $params = array();
        if ( ! empty($row['params']))
        {
            $decoded = json_decode((string) $row['params'], TRUE);
            if (is_array($decoded))
            {
                $params = $decoded;
            }
        }

        if ($params)
        {
            $body = vsprintf($body, $params);
        }

        return array('title' => $title, 'message' => $body);
    }
}

// ===================================================================
//  Plan 103 — LEDGER DESCRIPTION RENDERER
//
//  `wallet_ledger.description` adalah jejak audit immutable yang ditulis
//  sejak awal aplikasi dalam Bahasa Indonesia (mis. "Klaim ROI Harian
//  Kontrak #12 (H+3)"). Migrasi skema ledger DI LUAR scope plan/103, jadi
//  deskripsi diterjemahkan SAAT DIBACA untuk format kanonik yang
//  deterministik; entri lain (termasuk teks bebas admin) ditampilkan apa
//  adanya — nol kehilangan informasi, nol mutasi data historis.
// ===================================================================

if ( ! function_exists('i18n_ledger_description'))
{
    /**
     * Terjemahkan deskripsi ledger kanonik ke idiom aktif.
     *
     * Pola yang dipetakan (semua ditulis oleh model deterministik):
     *   - Klaim ROI Harian Kontrak #N (H+D)
     *   - Komisi sewa GPU Level T …                 (rebate L1–L3)
     *   - Komisi Rebate Level T …
     *   - Bonus Level 1 — 3 Agen Aktif + Omset ≥330rb
     *   - Gaji Mingguan Level N — D downline aktif …
     *   - Withdrawal #WD-…
     *   - Deposit QRIS / Top Up …
     *
     * @param  string $description Nilai mentah kolom description
     * @return string
     */
    function i18n_ledger_description($description)
    {
        $raw = (string) $description;
        if ($raw === '')
        {
            return $raw;
        }

        $patterns = array(
            // ROI harian: "Klaim ROI Harian Kontrak #12 (H+3)"
            '/^Klaim ROI Harian Kontrak #(\d+)(?: \(H\+(\d+)\))?$/u'
                => 'ledger_roi_daily',
            // Rebate L1–L3 (dua varian copy historis)
            '/^(?:Komisi sewa GPU|Komisi Rebate) Level (\d+)/u'
                => 'ledger_rebate',
            // Bonus Level 1
            '/^Bonus Level 1\b/u'
                => 'ledger_bonus_l1',
            // Gaji mingguan
            '/^Gaji Mingguan Level (\d+)/u'
                => 'ledger_wage',
            // Penarikan
            '/^(?:Withdrawal|Penarikan)\b/u'
                => 'ledger_withdrawal',
            // Deposit / top up
            '/^(?:Deposit|Top Up)\b/u'
                => 'ledger_deposit',
        );

        foreach ($patterns as $re => $key)
        {
            if ( ! preg_match($re, $raw, $mm))
            {
                continue;
            }

            $line = lang($key);
            if ( ! is_string($line) || $line === '')
            {
                return $raw;   // idiom belum dimuat → jangan kehilangan data
            }

            if (isset($mm[1]) && $mm[1] !== '')
            {
                return sprintf($line, $mm[1], isset($mm[2]) ? $mm[2] : '');
            }

            return $line;
        }

        return $raw;
    }
}
