<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------
| reCAPTCHA v2 — konfigurasi berbasis environment (Roadmap Rule #6)
| -------------------------------------------------------------------
| Secret TIDAK PERNAH di-hardcode di repository. Nilai dibaca dari
| environment variable saat runtime:
|
|   RECAPTCHA_SECRET   -> secret key (PRIVATE — wajib diisi)
|   RECAPTCHA_SITE_KEY -> site key (PUBLIC — opsional, views belum
|                         menggunakannya; lihat C4 di 0_HOUSEKEEPING_PLAN)
|
| Jika RECAPTCHA_SECRET kosong/unset, Auth::_verify_recaptcha() menolak
| verifikasi secara fail-closed dan menulis error log (lihat §2.3).
*/
$config['recaptcha_secret']   = (string) getenv('RECAPTCHA_SECRET');
$config['recaptcha_site_key'] = (string) getenv('RECAPTCHA_SITE_KEY');
