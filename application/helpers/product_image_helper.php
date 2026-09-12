<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// ===================================================================
//  PRODUCT IMAGE HELPERS — Plan 104 (GPU Product Real Image Support)
//
//  Satu-satunya tempat resolusi berkas gambar produk:
//  nama (BASENAME di `gpu_products.image`) → path disk → URL publik.
//
//  Kontrak fallback TUNGGAL (dipakai 3 konsumen: marketplace,
//  tabel admin, modal admin):
//    product_image_url() === null  →  render fallback banner.
//  `null` dikembalikan untuk: nilai NULL/kosong, nama tidak aman
//  (path traversal / ekstensi di luar allowlist), ATAU berkas tidak
//  ada di disk (DB bisa menunjuk berkas yang sudah dihapus).
//
//  Prefix path `uploads/products/` HANYA didefinisikan di sini
//  (harga satu sumber); view tidak pernah menyusun path/URL sendiri.
//
//  Catatan keamanan: `product_image_filename()` adalah choke-point
//  anti mass-assignment — nilai dari input apa pun harus lewat sini
//  sebelum menyentuh DB (basename-only + allowlist ekstensi).
//
//  Semua fungsi dibungkus function_exists() agar aman di-include
//  ganda (pola api_helper plan/76 & i18n_helper plan/94).
// ===================================================================

if ( ! function_exists('product_image_dir'))
{
    /**
     * Path direktori penyimpanan gambar produk (server-side, absolut
     * bila FCPATH tersedia). Satu-satunya definisi prefix path.
     *
     * @return string
     */
    function product_image_dir()
    {
        return defined('FCPATH')
            ? rtrim(FCPATH, '/\\') . '/uploads/products/'
            : './uploads/products/';
    }
}

if ( ! function_exists('product_image_extensions'))
{
    /**
     * Allowlist ekstensi gambar produk (satu sumber; selaras konfigurasi
     * Upload library di Admin::_handle_product_image_upload()).
     *
     * @return array
     */
    function product_image_extensions()
    {
        return array('jpg', 'jpeg', 'png', 'webp');
    }
}

if ( ! function_exists('product_image_filename'))
{
    /**
     * Sanitasi nilai `gpu_products.image` → BASENAME aman, atau NULL.
     *
     * Ditolak (→ null):
     *   - NULL / non-string / string kosong / hanya spasi
     *   - mengandung pemisah path ('/', '\') atau traversal ('..')
     *   - mengandung byte NUL atau skema URL ('://')
     *   - ekstensi di luar allowlist (jpg|jpeg|png|webp) → menolak
     *     svg/html/php/polyglot payload (stored XSS / RCE)
     *   - panjang > 255 (batas kolom VARCHAR(255))
     *
     * @param mixed $raw
     * @return string|null  basename bersih atau null
     */
    function product_image_filename($raw)
    {
        if ( ! is_string($raw) && ! is_numeric($raw))
        {
            return NULL;
        }

        $name = trim((string) $raw);

        if ($name === '' OR strlen($name) > 255)
        {
            return NULL;
        }

        // Anti path traversal / NUL-byte / URL absolut.
        if (strpos($name, '/') !== FALSE
            OR strpos($name, '\\') !== FALSE
            OR strpos($name, '..') !== FALSE
            OR strpos($name, "\0") !== FALSE
            OR strpos($name, '://') !== FALSE)
        {
            return NULL;
        }

        if (basename($name) !== $name)
        {
            return NULL;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ( ! in_array($ext, product_image_extensions(), TRUE))
        {
            return NULL;
        }

        return $name;
    }
}

if ( ! function_exists('product_image_path'))
{
    /**
     * Path absolut berkas gambar produk (tanpa cek keberadaan).
     *
     * @param mixed $raw
     * @return string|null  null bila nama tidak valid
     */
    function product_image_path($raw)
    {
        $name = product_image_filename($raw);

        return ($name === NULL) ? NULL : product_image_dir() . $name;
    }
}

if ( ! function_exists('product_image_exists'))
{
    /**
     * TRUE bila nama valid DAN berkasnya benar-benar ada di disk.
     *
     * Memoized per nama dalam satu request (pola enqueue kartu katalog
     * 8 produk → maksimal 8 stat(), sisanya cache OS/array).
     *
     * @param mixed $raw
     * @return bool
     */
    function product_image_exists($raw)
    {
        static $cache = array();

        $name = product_image_filename($raw);

        if ($name === NULL)
        {
            return FALSE;
        }

        if ( ! array_key_exists($name, $cache))
        {
            $path          = product_image_dir() . $name;
            $cache[$name]  = is_file($path);
        }

        return $cache[$name];
    }
}

if ( ! function_exists('product_image_url'))
{
    /**
     * URL publik gambar produk, atau NULL bila harus fallback.
     *
     * `null` untuk: nilai NULL/kosong, nama tidak aman, atau berkas
     * tidak ada di disk (degradasi graceful — view merender banner
     * fallback, bukan broken image).
     *
     * Nama di-`rawurlencode` (basename → tanpa '/'), sehingga aset
     * legacy ber-spasi (hasil backfill SQL manual) tetap menghasilkan
     * URL yang valid.
     *
     * @param mixed $raw
     * @return string|null
     */
    function product_image_url($raw)
    {
        if ( ! product_image_exists($raw))
        {
            return NULL;
        }

        return base_url('uploads/products/' . rawurlencode(product_image_filename($raw)));
    }
}
