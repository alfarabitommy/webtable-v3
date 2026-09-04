# Phase 10D — SSL Verification, Network Hardening & Production Lockdown

**Project:** Synapse (webtable) · **Baseline:** `main` (HEAD saat audit) · **Branch kerja:** `fase-10d-ssl-network-lockdown` (proposed)
**Mode:** PLANNING — blueprint menunggu persetujuan user. **Belum ada kode/konfigurasi yang diubah.**
**Referensi:** `docs/3_ROADMAP.md` (Phase 10, item 10D), `plan/26_PHASE_10C_PLAN.md` (format & gaya), AGENTS.md (SQL hanya di model, `php -l` tiap file, branch per fase, commit bahasa Indonesia), `system/core/Input.php` (engine IP + proxy CI 3.1.13), `system/core/CodeIgniter.php` (choke point `$OUT->_display()`).

> **Catatan scope:** item 10D di `docs/3_ROADMAP.md` berjudul *"Input Sanitization Audit"*. Sesuai arahan user, Phase 10D ini mengimplementasikan scope **SSL Verification, Network Hardening & Production Lockdown** (5 workstream di bawah). Input Sanitization Audit tetap menjadi follow-up terpisah (diusulkan dicatat di roadmap).

**Keputusan user (diambil via ask tool):**
- ✅ **ENV default fail-closed** — `index.php` default `ENVIRONMENT = 'production'` bila `CI_ENV` tidak terdefinisi. Dev lokal wajib eksplisit `CI_ENV=development php -S localhost:8080` (atau `SetEnv CI_ENV development` di vhost lokal `synapse.test`). Tidak ada env dev yang dikirim ke produksi.
- ✅ **Hapus backdoor dev** — `Auth::seeder_admin()` dihapus dan `application/controllers/Test_core.php` dihapus permanen. Seeding dev sudah dicakup `database_seed.sql` / `scripts/seed_database.php`.

---

## Ringkasan Perubahan

| # | Perubahan | File |
|---|-----------|------|
| 1 | **Strict SSL pada `_verify_recaptcha()`** — `VERIFYPEER=true`, `VERIFYHOST=2`, CA bundle env-aware, timeout, `curl_error` fail-closed, dev-bypass eksplisit yang **diabaikan di production** | `application/controllers/Auth.php` (edit) |
| 2 | **Hapus `seeder_admin()`** (backdoor admin unauthenticated) | `application/controllers/Auth.php` (edit) |
| 3 | **Hapus `Test_core.php`** (tester DB-destructive unauthenticated) | `application/controllers/Test_core.php` (**delete**) |
| 4 | **`proxy_ips` env-driven** (`TRUSTED_PROXIES`) + dokumentasi arsitektur trusted proxy | `application/config/config.php` (edit) |
| 5 | **Security headers global** via `MY_Output::_display()` (choke point tunggal, mencakup halaman error & AJAX) | `application/core/MY_Output.php` (**new**) |
| 6 | **Error lockdown** — `SHOW_DEBUG_BACKTRACE` production=false, template error di-escape & disembunyikan path/backtrace di production, `log_threshold` env-aware, `encryption_key` & `base_url` env-driven | `application/config/constants.php`, `application/views/errors/html/*.php` (5 file), `application/config/config.php` (edit) |
| 7 | **Kredensial DB env-driven** (fallback dev `root/root`) | `application/config/database.php` (edit) |
| 8 | **Hapus reCAPTCHA secret hardcoded** dari `.htaccess` + contoh komentar env | `.htaccess` (edit) |
| 9 | Blueprint ini | `plan/28_PHASE_10D_PLAN.md` (**new**) |

**Tanpa perubahan:** `routes.php` (tidak ada endpoint baru; akses `seeder_admin`/`test_core` hilang otomatis karena method/file dihapus), `system/core/*` (tidak ada hacking core — `MY_Output` cukup via subclass), `application/config/hooks.php` (header via `MY_Output`, bukan hooks).

---

## 1. Hasil Audit (fakta tervalidasi dari source)

### 1.1 cURL / SSL — satu-satunya outbound HTTP di codebase, SSL **disabled**
`grep -rn "curl_init|CURLOPT|file_get_contents"` di `application/` + `system/core/` hanya menemukan satu situs:

- `application/controllers/Auth.php:42-51` — `Auth::_verify_recaptcha()` POST ke `https://www.google.com/recaptcha/api/siteverify`:
  ```php
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // CRITICAL FIX — bypass SSL for local dev
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // CRITICAL FIX — bypass SSL for local dev
  ```
- Tidak ada `CURLOPT_TIMEOUT` / `CURLOPT_CONNECTTIMEOUT` / `CURLOPT_CAINFO` / pengecekan `curl_errno()`.
- Tidak ada `file_get_contents('http…')` outbound lain. Satu-satunya `file_get_contents` lain: `system/core/Input.php:928` (`php://input`) dan `system/core/Loader.php:965` (load view) — keduanya internal.

**Konsekuensi:** seluruh trafik reCAPTCHA rentan MITM — token reCAPTCHA (dan secret) dapat dibaca/disuntik attacker on-path. Google memakai CA publik yang terpercaya, jadi verifikasi ketat tetap berfungsi normal dari dev lokal; bypass hanya dibutuhkan untuk sandbox offline/self-signed.

### 1.2 Secret hardcoded & backdoor dev
- **`.htaccess:2` — reCAPTCHA secret KEBOCORAN di repo:**
  `SetEnv RECAPTCHA_SECRET "6Le3PSgtAAAAAL65R6znylzjtBpAp9i8yBi-HW2w"` ← ini flag yang AGENTS.md catat. Secret aktif ter-commit. **WAJIB dicabut + rotasi secret di Google reCAPTCHA console.**
- `application/controllers/Auth.php:277-297` — `seeder_admin()`: tanpa auth check, route default CI (`/auth/seeder_admin`), membuat admin `081234567890`/`admin123` dan **echo kredensial ke browser**.
- `application/controllers/Test_core.php` (145 baris) — `/test_core` tanpa auth: **menghapus** baris `transactions`/`rentals`/`users` (baris 20-23), membuat user, `print_r` seluruh baris user/ledger + stack trace.
- Positif: `application/config/recaptcha.php:18-19` membaca `getenv('RECAPTCHA_SECRET')` (tidak hardcoded) dan `Auth::_verify_recaptcha()` fail-closed saat secret kosong (`Auth.php:34-38`).

### 1.3 IP client & trusted proxy
- `application/config/config.php:532` — `$config['proxy_ips'] = '';` (kosong).
- Semantik `system/core/Input.php:462-587` (`ip_address()`): bila `proxy_ips` **kosong** → hanya `REMOTE_ADDR`; `X-Forwarded-For`/`HTTP_CLIENT_IP`/`HTTP_X_CLIENT_IP`/`HTTP_X_CLUSTER_CLIENT_IP` **diabaikan total** → saat ini **tidak bisa di-spoof**, tapi bila dideploy di belakang reverse proxy tanpa `proxy_ips`, SEMUA client collapse ke IP proxy → bucket rate limit menjadi global (DoS satu IP memblokir semua user) dan audit log salah.
- Bila `proxy_ips` terisi → nilai header dihormati **hanya jika** `REMOTE_ADDR` cocok daftar trusted (IP eksak atau subnet, mendukung IPv6), ambil entri **pertama** dari chain (paling kiri = client), divalidasi `valid_ip()`; invalid → fallback `REMOTE_ADDR`; akhir → `'0.0.0.0'`.
- Pemakai `ip_address()` yang terdampak: rate limit `register:{ip}` (`Auth.php:78`), `login:{phone}:{ip}` (`Auth.php:151`), `admin_login:{username}:{ip}` (`Admin_auth.php:23`), audit `log_admin_action`/`_audit_ctx` (`Admin.php` 11 titik) → disimpan `Audit_model` (`ip_address VARCHAR(45)` — sudah muat IPv6).

### 1.4 Security headers — **tidak ada satupun**
- `grep -rn "X-Frame|X-Content-Type|Strict-Transport|Referrer-Policy|Permissions-Policy"` di `application/` = 0 hasil. Header yang ada hanya Content-Type/Status/CSV (`Notification.php:31`, `Admin.php:1007-1010`, `ratelimit_helper.php:33`).
- `application/core/MY_Controller.php` hanya guard auth + inject vars, tidak set header.
- Choke point yang tepat: `system/core/CodeIgniter.php:552` memanggil `$OUT->_display()` di **setiap** request (termasuk 404/exception, AJAX JSON, CSV) → extend `MY_Output::_display()` = satu titik injeksi yang menjamin cakupan total, tanpa hack core.

### 1.5 Error lockdown & kebocoran informasi
- `index.php:56` — `define('ENVIRONMENT', isset($_SERVER['CI_ENV']) ? $_SERVER['CI_ENV'] : 'development');` → **server produksi tanpa `CI_ENV` menampilkan error** (`display_errors=1`). Ini akar masalah.
- `application/config/constants.php:14` — `SHOW_DEBUG_BACKTRACE` = `TRUE` **tanpa syarat** → `error_exception.php` & `error_php.php` merender **path file lengkap + backtrace** di production.
- Template `application/views/errors/html/error_general.php:60-61`, `error_db.php:60-61`, `error_404.php:60-61` — `echo $heading/$message` **tanpa escape** (XSS via isi error, dan DB error mentah sampai ke HTML bila `db_debug` on).
- `error_exception.php:9-12` — kelas exception, pesan, `$exception->getFile()` (path penuh), baris; backtrace baris 14-30 digate `SHOW_DEBUG_BACKTRACE`.
- `error_php.php:9-12` — severity/pesan/path (path dipotong 2 segmen oleh `system/core/Exceptions.php:247-255`), backtrace.
- `application/config/config.php:228` — `log_threshold = 0` → logging **mati total** (termasuk log error reCAPTCHA di `Auth.php:36`). `log_path=''` (default `application/logs/`), `log_file_permissions=0644`.
- `config.php:329` — `encryption_key = ''`; `config.php:26` — `base_url = 'http://synapse.test/'` hardcoded (HTTP).
- `database.php:78-81` — kredensial DB `localhost`/`root`/`root`/`db_webtable` hardcoded (dev default; roadmap rule: env vars). `db_debug` sudah env-conditional (`database.php:85`).
- Positif: `cookie_secure` sudah production-only (`config.php:414`), CSRF aktif (`config.php:460-465`), `cookie_httponly=TRUE`, `samesite=Lax`.

---

## 2. Workstream & Desain Detail

### WS-1 — SSL Verification Hardening (`Auth.php::_verify_recaptcha`)

Tulis ulang blok cURL (baris 42-51) menjadi:

```php
$ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// STRICT SSL — zero-trust di semua environment. JANGAN pernah nonaktifkan
// di production. CA publik Google terverifikasi oleh bundle sistem.
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

// CA bundle khusus (optional, env-driven) — untuk enterprise CA / self-signed sandbox.
$ca_bundle = (string) getenv('SSL_CA_BUNDLE');
if ($ca_bundle !== '') {
    curl_setopt($ch, CURLOPT_CAINFO, $ca_bundle);
}

// Environment-aware dev sandbox bypass: HANYA dihormati di luar production.
// Production selalu strict; flag ini tidak pernah bisa mengaktifkan bypass di prod.
if (ENVIRONMENT !== 'production' && getenv('CURL_SSL_VERIFY_DEV_BYPASS') === '1') {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    log_message('debug', 'reCAPTCHA: SSL verify bypass aktif (dev sandbox only).');
}

curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_USERAGENT, 'Synapse-CI3/1.0 (reCAPTCHA verifier)');

$response = curl_exec($ch);
if ($response === false) {
    log_message('error', 'reCAPTCHA curl gagal: ' . curl_error($ch) . ' (errno ' . curl_errno($ch) . ')');
    curl_close($ch);
    return FALSE; // fail-closed — jangan pernah loloskan verifikasi saat transport gagal
}
curl_close($ch);
```

Prinsip:
1. **Production zero-trust**: `VERIFYPEER=true` + `VERIFYHOST=2` **hardcoded sebagai default**. Tidak ada jalur kode yang bisa menonaktifkannya di `ENVIRONMENT === 'production'`.
2. **Dev/testing**: tetap strict (Google CA publik valid dari dev), kecuali sandbox offline benar-benar butuh bypass → `CURL_SSL_VERIFY_DEV_BYPASS=1` eksplisit, dan guard `ENVIRONMENT !== 'production'` menjamin flag itu inert di produksi.
3. **Fail-closed**: transport error / timeout / empty response → `FALSE` + `log_message('error')` (efektif setelah `log_threshold` diperbaiki di WS-4).
4. **Tidak ada secret baru** — secret tetap dari `getenv('RECAPTCHA_SECRET')` via `recaptcha.php`.

Verifikasi SSL terkait WS-1 ada di §5.

### WS-2 — Reverse Proxy & Client IP Spoofing Protection (`config.php::proxy_ips`)

**1) Konfigurasi env-driven** (pengganti `config.php:532`):

```php
$config['proxy_ips'] = '';
$_trusted_proxies = getenv('TRUSTED_PROXIES');
if ($_trusted_proxies !== false && trim((string) $_trusted_proxies) !== '') {
    $config['proxy_ips'] = array_map('trim', explode(',', (string) $_trusted_proxies));
}
```

Contoh nilai: `TRUSTED_PROXIES=10.0.0.1,10.0.0.0/8` (IP eksak + subnet didukung engine CI3, IPv4 & IPv6).

**2) Arsitektur kepercayaan (bagaimana tidak bisa di-spoof):**
- `proxy_ips` kosong (dev/standalone): `ip_address()` = `REMOTE_ADDR` murni; `X-Forwarded-For` **diabaikan** → spoofing mustahil. Status aman saat ini dipertahankan.
- `proxy_ips` terisi: header `X-Forwarded-For` dihormati **hanya** bila `REMOTE_ADDR` cocok daftar trusted. Attacker tidak bisa mengubah `REMOTE_ADDR` dari sisi client → hanya proxy terpercaya yang bisa menyuntik IP client.
- **Syarat non-negosiasi di lapisan proxy (infra):** proxy wajib **menimpa** (overwrite), bukan menambahkan, `X-Forwarded-For` dari client tak dikenal. Engine CI3 mengambil entri **paling kiri** chain — jika proxy menambahkan XFF client liar (mis. nginx `$proxy_add_x_forwarded_for`), attacker bisa mengisi nilai kiri. Karena itu dokumen per-proxy:
  - **Nginx (recommended):** pakai modul `real_ip` — `set_real_ip_from <trusted>; real_ip_header X-Forwarded-For;` lalu aplikasi cukup pakai `REMOTE_ADDR` (`proxy_ips` boleh kosong), ATAU `proxy_set_header X-Forwarded-For $remote_addr;` (overwrite) + `TRUSTED_PROXIES` berisi IP nginx.
  - **Cloudflare:** `X-Forwarded-For` di-overwrite Cloudflare dengan IP client asli; `TRUSTED_PROXIES` diisi range edge Cloudflare (lihat `https://www.cloudflare.com/ips-v4` dan `ips-v6` — range berubah, jangan hardcode permanen tanpa prosedur update).
  - **AWS ALB:** ALB mengisi `X-Forwarded-For` dengan IP client (menggabungkan bila client mengirim XFF) → pastikan lapisan di belakang ALB (nginx `real_ip` dengan `set_real_ip_from` VPC CIDR) menimpa sebelum app, atau batasi `TRUSTED_PROXIES` ke ALB dan patuhi aturan overwrite.
- **Dampak pada 10B & 10A:** dengan `proxy_ips` benar, bucket rate limit (`register:{ip}`, `login:{phone}:{ip}`, `admin_login:{username}:{ip}`) kembali per-client dan `Audit_model.ip_address` tercatat IP client sebenarnya (VARCHAR(45) sudah muat IPv6). Tanpa `proxy_ips` di belakang proxy: semua user = satu IP proxy → rate limit global & audit keliru. Ini **deployment-gate**: produksi di belakang proxy WAJIB set `TRUSTED_PROXIES`.

**3) Tidak menyentuh** `system/core/Input.php` — engine CI3 3.1.13 sudah benar (validasi + whitelist). Menghindari fork core.

### WS-3 — Security Headers Global (`application/core/MY_Output.php`, **new**)

Kelas baru (tanpa hack core; CI otomatis memakai `MY_` prefix via `subclass_prefix`):

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class MY_Output extends CI_Output {

    public function _display($output = '')
    {
        $this->set_header('X-Frame-Options: SAMEORIGIN');               // clickjacking
        $this->set_header('X-Content-Type-Options: nosniff');           // anti MIME-sniffing
        $this->set_header('X-XSS-Protection: 1; mode=block');           // legacy XSS filter
        $this->set_header('Referrer-Policy: strict-origin-when-cross-origin');
        $this->set_header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        // HSTS hanya di produksi & hanya saat request sudah HTTPS —
        // mencegah HSTS poisoning lewat koneksi HTTP/develop.
        if (ENVIRONMENT === 'production' && self::_request_is_https()) {
            $this->set_header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        parent::_display($output);
    }

    private static function _request_is_https()
    {
        return (! empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    }
}
```

Rasional desain:
- `CodeIgniter.php:552` memanggil `$OUT->_display()` untuk **setiap** request yang masuk framework → header berlaku untuk halaman user, admin, 404, exception page, AJAX JSON, dan CSV export, tanpa menyentuh tiap controller.
- Tidak bertabrakan: header yang sudah ada (`Content-Type`, `Content-Disposition`, `429`) beda nama; `set_header()` CI3 menambahkan header baru (tidak ada duplikat karena tidak ada pemanggil lain untuk nama-nama ini).
- `X-Forwarded-Proto` diperhitungkan agar HSTS tetap terpasang saat TLS di-terminate di proxy (nginx/ALB) — engine `is_https()` CI3 tidak membaca header itu.
- Catatan: `X-XSS-Protection` sudah usang di browser modern (kadang memicu warning) tetapi diminta eksplisit → tetap dipasang.

### WS-4 — Production Error Lockdown & Anti Info-Leak

**4a. `index.php:56` — default fail-closed (keputusan user):**

```php
define('ENVIRONMENT', isset($_SERVER['CI_ENV']) ? $_SERVER['CI_ENV'] : 'production');
```

+ perbarui komentar: dev lokal → `CI_ENV=development php -S localhost:8080`; vhost Apache lokal `synapse.test` → `SetEnv CI_ENV "development"` (di vhost lokal, **bukan** di file yang ter-commit — lihat WS-5). Efek: switch baris 66-90 otomatis `display_errors=0` + `error_reporting` production di server tanpa `CI_ENV`.

**4b. `application/config/constants.php:14` — backtrace production off:**

```php
defined('SHOW_DEBUG_BACKTRACE') OR define('SHOW_DEBUG_BACKTRACE', (ENVIRONMENT !== 'production'));
```

**4c. Template error HTML di-escape & direduksi di production** (`application/views/errors/html/`):
- `error_general.php`, `error_404.php`, `error_db.php`: ganti `echo $heading;` → `echo htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');` dan escape `$message` (CI3 sudah membungkus `<p>`, escape nilai di dalamnya). Menutup XSS via isi pesan error.
- `error_exception.php`: blok Type/Message/Filename/Line + Backtrace dibungkus `if (ENVIRONMENT !== 'production')`, nilai di-`htmlspecialchars`; di production render hanya pesan generik tanpa path/stack:
  ```php
  <?php if (ENVIRONMENT !== 'production'): ?>
      <p>Type: ...</p><p>Message: <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
      <p>Filename: <?php echo htmlspecialchars($exception->getFile(), ...); ?></p>
      ... backtrace ...
  <?php else: ?>
      <p>Terjadi kesalahan sistem. Silakan coba lagi nanti.</p>
  <?php endif; ?>
  ```
- `error_php.php`: pola sama — severity/message/filepath/line + backtrace hanya non-production, selalu di-escape.
- Template CLI (`errors/cli/`) tidak diubah: hanya jalan di CLI, tidak pernah ter-expose HTTP. (Catatan di plan: biarkan stock.)

**4d. `config.php` — logging & env:**
- `log_threshold` (baris 228): `$config['log_threshold'] = (ENVIRONMENT === 'production') ? 1 : 4;` → error production tercatat (termasuk log reCAPTCHA `Auth.php:36`), dev dapat debug.
- `encryption_key` (baris 329): `$config['encryption_key'] = (string) getenv('ENCRYPTION_KEY');` (kosong di dev = status quo; produksi diwajibkan via validation gate, lihat §5).
- `base_url` (baris 26): `$config['base_url'] = (string) (getenv('APP_BASE_URL') ?: 'http://synapse.test/');` → produksi wajib `https://…`.

**4e. `database.php:78-81` — kredensial env-driven (fallback dev):**

```php
'hostname' => (string) (getenv('DB_HOSTNAME') ?: 'localhost'),
'username' => (string) (getenv('DB_USERNAME') ?: 'root'),
'password' => (string) (getenv('DB_PASSWORD') ?: 'root'),
'database' => (string) (getenv('DB_DATABASE') ?: 'db_webtable'),
```

`db_debug` (baris 85) tetap `(ENVIRONMENT !== 'production')` — sudah benar: di production error DB tidak dirender, hanya ke log (setelah 4d).

### WS-5 — Pembersihan Secret & Backdoor

- **`.htaccess`:** hapus baris 2 (`SetEnv RECAPTCHA_SECRET "…"`). Ganti dengan contoh yang **di-comment** saja:
  ```apache
  # Local dev (jangan aktif di production):
  # SetEnv CI_ENV "development"
  # SetEnv RECAPTCHA_SECRET "isi-dari-env-atau-vault"
  ```
  Keputusan desain: `SetEnv CI_ENV development` TIDAK diaktifkan di file yang ter-commit karena `.htaccess` ikut terdeploy ke production (membatalkan fail-closed default). Dev lokal memasangnya di vhost Apache sendiri (dokumentasi di §6).
- **Rotasi secret:** secret yang bocor di `.htaccess` WAJIB dirotasi di Google reCAPTCHA console (diluar kode; dicatat sebagai aksi manual opsional user).
- **`Auth.php`:** hapus seluruh method `seeder_admin()` (baris 276-297). Access `/auth/seeder_admin` otomatis 404 (route tidak terdaftar).
- **`Test_core.php`:** hapus file. Access `/test_core` otomatis 404. Dev tetap punya `scripts/seed_database.php` + `database_seed.sql`.
- **AGENTS.md:** perbarui catatan ⚠️ (secret hardcoded sudah dicabut dari repo; Auth.php tidak lagi memegang secret apa pun).

---

## 3. Kontrak Environment Variable (baru & existing)

| Var | Dipakai di | Dev default | Production |
|-----|-----------|-------------|------------|
| `CI_ENV` | `index.php:56` | `development` (wajib eksplisit) | `production` (default fail-closed) |
| `RECAPTCHA_SECRET` | `recaptcha.php` (existing) | kosong → fail-closed | wajib |
| `RECAPTCHA_SITE_KEY` | `recaptcha.php` (existing) | opsional | opsional |
| `TRUSTED_PROXIES` | `config.php::proxy_ips` (baru) | kosong | wajib bila di belakang proxy |
| `CURL_SSL_VERIFY_DEV_BYPASS` | `Auth.php` (baru, dev-only) | `1` hanya di sandbox offline | **diabaikan** (guard `ENVIRONMENT !== 'production'`) |
| `SSL_CA_BUNDLE` | `Auth.php` (baru, optional) | kosong | kosong (bundle sistem) |
| `APP_BASE_URL` | `config.php::base_url` (baru) | `http://synapse.test/` fallback | `https://…` wajib |
| `ENCRYPTION_KEY` | `config.php::encryption_key` (baru) | kosong (status quo) | wajib (`openssl rand -base64 32`) |
| `DB_HOSTNAME/USERNAME/PASSWORD/DATABASE` | `database.php` (baru) | `localhost/root/root/db_webtable` fallback | wajib |

Tidak ada kredensial/kunci baru yang di-hardcode di repo.

---

## 4. Manifest Perubahan per File

| File | Aksi | Inti perubahan |
|------|------|----------------|
| `application/controllers/Auth.php` | edit | §2 WS-1 (curl strict) + hapus `seeder_admin()` |
| `application/controllers/Test_core.php` | **delete** | backdoor DB-destructive |
| `application/config/config.php` | edit | `base_url`, `log_threshold`, `encryption_key`, `proxy_ips` env-driven |
| `application/config/constants.php` | edit | `SHOW_DEBUG_BACKTRACE` = non-production |
| `application/config/database.php` | edit | kredensial env-driven |
| `application/core/MY_Output.php` | **new** | security headers global |
| `application/views/errors/html/error_general.php` | edit | escape + produksi generik |
| `application/views/errors/html/error_404.php` | edit | escape + produksi generik |
| `application/views/errors/html/error_db.php` | edit | escape + produksi generik |
| `application/views/errors/html/error_exception.php` | edit | escape + gate non-production |
| `application/views/errors/html/error_php.php` | edit | escape + gate non-production |
| `index.php` | edit | default ENVIRONMENT `production` + komentar |
| `.htaccess` | edit | hapus secret, contoh env ter-comment |
| `AGENTS.md` | edit | update catatan ⚠️ secret |
| `plan/28_PHASE_10D_PLAN.md` | **new** | blueprint ini |

---

## 5. Protokol Verifikasi & Testing

**5a. Syntax lint (wajib tiap file berubah):**
```bash
php -l index.php
php -l application/controllers/Auth.php
php -l application/config/config.php
php -l application/config/constants.php
php -l application/config/database.php
php -l application/core/MY_Output.php
# + 5 template error
```

**5b. Security headers (curl -I):**
```bash
# Dev (tanpa HSTS):
curl -sI http://localhost:8080/login | grep -iE "x-frame-options|x-content-type-options|x-xss-protection|referrer-policy|permissions-policy"
# → ke-5 header wajib muncul; strict-transport-security HARUS TIDAK ada di HTTP dev.

# Production (HTTPS):
curl -sI https://synapse.test/login | grep -i "strict-transport-security"
# → "Strict-Transport-Security: max-age=31536000; includeSubDomains"

# Admin + AJAX + error page ikut diverifikasi:
curl -sI https://synapse.test/control-panel | grep -iE "x-frame-options|x-content-type-options"
curl -sI https://synapse.test/halaman-tidak-ada-404xyz | grep -iE "x-frame-options|strict-transport-security"
```

**5c. SSL verification behavior:**
```bash
# Statik: tidak boleh ada false/0 lagi untuk opsi SSL di Auth.php
grep -n "CURLOPT_SSL_VERIFY" application/controllers/Auth.php
# → VERIFYPEER true, VERIFYHOST 2; dev bypass hanya di luar production.

# Runtime: CA publik tervalidasi dengan opsi strict (dev & prod sama)
php -r '$ch=curl_init("https://www.google.com/recaptcha/api/siteverify");curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,true);curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,2);curl_exec($ch);var_dump(curl_errno($ch)===0,curl_errno($ch));'

# Fail-closed: tanpa RECAPTCHA_SECRET, register DITOLAK
env -u RECAPTCHA_SECRET CI_ENV=development php -S localhost:8080 &
curl -s -X POST http://localhost:8080/register -d "phone=081234567890&password=rahasia123&invite_code=XXXXXX" | grep -c "Verifikasi reCAPTCHA gagal"  # ≥1
# dan application/logs/log-*.php memuat "reCAPTCHA secret belum dikonfigurasi" (log_threshold aktif)
```

**5d. IP spoofing resistance (rate limit 10B + audit 10A):**
```bash
# Kasus A — proxy_ips kosong (dev): spoof HARUS diabaikan.
curl -s -H "X-Forwarded-For: 203.0.113.9" -X POST http://localhost:8080/login \
     -d "phone=081234567890&password=salah" >/dev/null
# → cek tabel rate_limit: key "login:081234567890:127.0.0.1" (bukan 203.0.113.9) terhitung;
#   tidak ada baris dengan 203.0.113.9. Audit log mencatat 127.0.0.1.

# Kasus B — simulasi trusted proxy (dev only):
TRUSTED_PROXIES=127.0.0.1 CI_ENV=development php -S localhost:8081 &
curl -s -H "X-Forwarded-For: 198.51.100.7" -X POST http://localhost:8081/login \
     -d "phone=081234567890&password=salah" >/dev/null
# → key rate_limit memakai 198.51.100.7 (XFF dihormati karena REMOTE_ADDR=127.0.0.1 trusted).
# Setelah pengujian, matikan server; pastikan tidak ada TRUSTED_PROXIES ter-commit aktif.

# Kasus C — proxy_ips kosong + header X-Real-IP / HTTP_CLIENT_IP serupa tetap diabaikan (engine CI3).
```

**5e. Regression / smoke:**
- Flow user: register (secret valid) → login → home; login salah 5× → 429 (rate limit tetap bekerja).
- Admin: `/control-panel` login → dashboard; aksi audit tetap mencatat IP.
- AJAX: `read_notifications` dan `team/claim-level1` tetap 200/JSON.
- `test_core` & `auth/seeder_admin` → **404** (file/method hilang).
- Tidak ada file error template yang break: paksa 404 (`/halaman-tidak-ada`) tampil normal + header lengkap.

---

## 6. Runbook Deployment (dokumentasi untuk user)

1. **Dev lokal:** `CI_ENV=development php -S localhost:8080`; untuk vhost Apache `synapse.test`, tambahkan `SetEnv CI_ENV "development"` di blok VirtualHost lokal (bukan di `.htaccess` ter-commit). Tidak wajib `RECAPTCHA_SECRET` (register akan menolak dengan pesan reCAPTCHA — perilaku fail-closed).
2. **Production:**
   - `CI_ENV=production` di env server/vhost; jangan pernah unset.
   - `APP_BASE_URL=https://…`, `RECAPTCHA_SECRET=…` (secret **baru** hasil rotasi), `ENCRYPTION_KEY=$(openssl rand -base64 32)`, `DB_*` sesuai environment.
   - Bila di belakang proxy: `TRUSTED_PROXIES=<IP proxy/range>` + pastikan proxy menimpa `X-Forwarded-For` (lihat WS-2).
   - Validasi pra-deploy: jalankan §5b-§5d terhadap staging. Deployment dianggap GAGAL bila `curl -I` produksi tidak memuat HSTS/header, atau `CI_ENV` tidak terdefinisi (kini fail-closed 503/error hidden).
3. **Rotasi reCAPTCHA secret** (aksi manual user, opsional tapi direkomendasikan) karena secret lama pernah ter-commit.

---

## 7. Risiko & Catatan

- **HSTS `includeSubDomains`** hanya aktif di production+HTTPS; sekali browser menerima header, subdomain wajib HTTPS — pastikan tidak ada subdomain HTTP-only sebelum enable (staging: uji dengan `max-age=0` dulu bila perlu).
- **XFF-overwrite adalah syarat infra** (WS-2): bila proxy menambahkan XFF client, whitelist `proxy_ips` tidak cukup — harus ada overwrite/`real_ip` di lapisan proxy.
- **`X-XSS-Protection` usang** (diminta eksplisit) — dapat menimbulkan warning console di Chrome modern; tidak berdampak fungsional.
- **Hapus `Test_core.php`** menghilangkan integration tester dev — sudah tergantikan `scripts/seed_database.php` + `database_seed.sql`; reversible via git.
- **`log_threshold` production=1** menulis `application/logs/` — pastikan direktori writable & log file `0644` aman dari web root exposure (sudah default CI, `.php` extension).
- **Di luar scope:** Content-Security-Policy (tidak diminta), input sanitization audit (item roadmap 10D asli — follow-up), konfigurasi nginx/ALB aktual (dokumen panduan saja), migrasi session ke database, `sess_match_ip` (10C).
