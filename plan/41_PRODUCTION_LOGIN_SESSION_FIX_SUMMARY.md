# Plan 41 — Ringkasan Eksekusi: Fix Login Production & Session Drop (Plan 40)

**Status:** SELESAI (execution approved)
**Tanggal:** 2026-09-02
**Dasar:** `plan/40_PRODUCTION_LOGIN_SESSION_FIX_PLAN.md` (disetujui)

---

## 1. Perubahan yang Dieksekusi

### 1.1 `application/config/config.php` — Transport-Aware Cookie Secure

- **Dihapus:** `$config['cookie_secure'] = (ENVIRONMENT === 'production') ? TRUE : FALSE;`
  (baris lama 414) — penyebab utama session drop di host HTTP polos.
- **Ditambahkan** (di bawah blok `proxy_ips`, akhir file) blok
  **"Cookie Secure — Transport-Aware"** yang mengevaluasi `cookie_secure` secara
  dinamis per request:
  1. **Override eksplisit** `COOKIE_SECURE=true|false` (env) — menang untuk
     deployment khusus/staging.
  2. **HTTPS asli** — `$_SERVER['HTTPS']` = `on` (Apache mod_ssl) → `TRUE`.
  3. **TLS di-terminate proxy** — `X-Forwarded-Proto: https` HANYA dipercaya bila
     `REMOTE_ADDR` cocok dengan whitelist `TRUSTED_PROXIES` (env → `proxy_ips`).
     Pencocokan mendukung **IP persis maupun CIDR subnet IPv4/IPv6**
     (meniru `system/core/Input.php`), mencegah spoofing header dari klien publik.
  4. Selain itu → `FALSE` (HTTP polos / dev).
- Blok sengaja diletakkan **setelah** inisialisasi `proxy_ips` agar whitelist sudah
  terisi; semua variabel sementara di-`unset` setelahnya.
- Catatan: `is_https()` (Common.php) tidak melakukan gating `proxy_ips`, sehingga
  deteksi ditulis manual — aman dipakai karena Common.php di-load sebelum Config
  (CodeIgniter.php:81 vs 220).

### 1.2 `index.php` — Resolusi Environment Dinamis Dipulihkan

- **Dihapus:** override uji coba `define('ENVIRONMENT', 'production')`
  ("Paksa ke mode production untuk pengujian").
- **Dipulihkan** resolusi dinamis (Plan 34): baca `$_SERVER['CI_ENV']`, lalu
  `getenv('CI_ENV')`, dengan **fallback fail-closed `'production'`** bila keduanya
  kosong — dev lokal kembali berjalan via `CI_ENV=development` (Apache `SetEnv` di
  vhost lokal / env FPM / `php -S`), produksi tetap aman secara default.

### 1.3 `application/controllers/Auth.php` — Hardening CA Bundle Auto-Discovery

- **`_resolve_ca_bundle()` → `_resolve_ca_bundles()`**: mengembalikan **daftar**
  semua kandidat CA yang VALID, bukan path pertama yang ada. Setiap kandidat wajib
  lolos **`is_file() && is_readable() && filesize() > 0`** — file CA kosong/rusak
  (kasus `errno 77` CURLE_SSL_CACERT_BADFILE, mis. `/usr/local/etc/openssl@1.1/
  cert.pem` di build PHP FlyEnv) dilewati. Prioritas:
  1. `SSL_CA_BUNDLE` (env eksplisit)
  2. **Bundle sistem**: `/etc/ssl/certs/ca-certificates.crt`, RHEL/Fedora, SUSE
  3. `ini_get('openssl.cafile')` (hanya bila valid)
  4. Fallback Homebrew (macOS)
- **`_verify_recaptcha()`**: mengiterasi daftar kandidat; jika curl gagal dengan
  **errno 77 atau 60**, coba kandidat berikutnya sebelum fail-closed. Kegagalan
  non-CA (timeout/DNS) langsung fail-closed tanpa retry. Perilaku lain dipertahankan:
  STRICT SSL (VERIFYPEER/VERIFYHOST), dev-bypass hanya di luar production via
  `CURL_SSL_VERIFY_DEV_BYPASS`, fail-closed bila `RECAPTCHA_SECRET` kosong, dan
  fallback ke default CA path PHP bila tidak ada bundle valid.

---

## 2. Verifikasi

### 2.1 Lint (semua file lolos)

```
$ php -l index.php
No syntax errors detected in index.php
$ php -l application/config/config.php
No syntax errors detected in application/config/config.php
$ php -l application/controllers/Auth.php
No syntax errors detected in application/controllers/Auth.php
```

### 2.2 Harness logika `cookie_secure` (config.php asli, 8 skenario)

| Skenario | Expected | Got |
|---|---|---|
| HTTP polos (tanpa HTTPS/proxy) | false | **PASS** |
| `HTTPS=on` (Apache mod_ssl) | true | **PASS** |
| `X-Forwarded-Proto: https` dari proxy trusted (IP persis) | true | **PASS** |
| `X-Forwarded-Proto: https` dari IP **tidak** trusted (spoof) | false | **PASS** |
| `X-Forwarded-Proto: https` dari proxy CIDR `10.0.0.0/8` | true | **PASS** |
| `X-Forwarded-Proto: http` walau dari proxy trusted | false | **PASS** |
| `COOKIE_SECURE=true` override di HTTP | true | **PASS** |
| `COOKIE_SECURE=false` override walau HTTPS | false | **PASS** |

### 2.3 Uji fungsional live (`http://synapse.test`)

1. **`GET /login` (fresh)** → `Set-Cookie: synapse_csrf_cookie=...; HttpOnly;
   SameSite=Strict` dan `Set-Cookie: ci_session=...; HttpOnly; SameSite=Lax` —
   **tanpa atribut `Secure`** (sebelumnya browser menolak cookie ini di HTTP).
2. **`GET /login` ulang dengan cookie jar** → nilai `synapse_csrf_cookie`
   identik, `ci_session` tidak di-reset — **session persist** antar request
   (tidak ada lagi session file orphan baru per request).
3. **`POST /login` (CSRF token valid, kredensial salah)** → **HTTP 200** re-render
   form dengan pesan *"Verifikasi reCAPTCHA gagal..."* (gate reCAPTCHA berjalan
   sebelum cek kredensial — wajar karena `g-recaptcha-response` dikosongkan).
   **Tidak ada lagi pola `POST /login 303 → GET /home 307 → GET /login`.**

### 2.4 Statistik diff

```
 application/config/config.php    | 117 ++++++++++++++++++++++++++++--
 application/controllers/Auth.php | 149 ++++++++++++++++++++++++++++++---------
 index.php                        |  21 +++++-
 3 files changed, 250 insertions(+), 37 deletions(-)
```

---

## 3. Catatan & Tindak Lanjut

- **Deployment produksi asli:** wajib HTTPS (Apache mod_ssl otomatis mengisi
  `$_SERVER['HTTPS']`; nginx/LB TLS-termination set `fastcgi_param HTTPS on;`
  atau `X-Forwarded-Proto: https` + `TRUSTED_PROXIES` berisi IP proxy).
  Di HTTPS sungguhan `cookie_secure` otomatis `TRUE` — keamanan session/CSRF
  tidak berkurang.
- **Env yang wajib ada di web server:** `RECAPTCHA_SECRET` (+ `RECAPTCHA_SITE_KEY`)
  — tanpa secret, login ditolak fail-closed (perilaku disengaja, Plan 34).
- **Opsional (tidak dieksekusi di plan ini):** pindahkan `SetEnv CI_ENV
  "development"` dari `.htaccess` ke vhost lokal saja; pindahkan
  `sess_save_path` dari `sys_get_temp_dir()` ke direktori khusus.
- Sisa session file orphan lama di `/tmp/ci_session*` (dari sebelum fix) bisa
  dibersihkan; tidak memengaruhi fungsionalitas.
