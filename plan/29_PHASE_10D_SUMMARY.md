# Phase 10D — Execution Summary: SSL Verification, Network Hardening & Production Lockdown

**Project:** Synapse (webtable) · **Fase:** 10D (per blueprint `plan/28_PHASE_10D_PLAN.md`, APPROVED)
**Status:** ✅ **SELESAI** — seluruh perubahan dieksekusi, lint bersih, verifikasi runtime lulus.
**Keputusan user yang dieksekusi:** (1) ENVIRONMENT default `production` (fail-closed); (2) `seeder_admin()` + `Test_core.php` dihapus permanen.

---

## 1. Daftar Perubahan (manifest)

| # | File | Aksi | Isi |
|---|------|------|-----|
| 1 | `application/controllers/Auth.php` | edit | `_verify_recaptcha()`: strict SSL (`CURLOPT_SSL_VERIFYPEER=true`, `VERIFYHOST=2`), CA bundle env-driven (`SSL_CA_BUNDLE`), dev-bypass guard (`ENVIRONMENT !== 'production' && CURL_SSL_VERIFY_DEV_BYPASS === '1'` — inert di production), `TIMEOUT=10`/`CONNECTTIMEOUT=5`, `USERAGENT`, **fail-closed** pada `curl_exec() === false` (log + `FALSE`). Method `seeder_admin()` dihapus total. |
| 2 | `application/controllers/Test_core.php` | **delete** | Backdoor tester DB-destructive dihapus. `/test_core` → 404 (terverifikasi). |
| 3 | `application/core/MY_Output.php` | **new** | Injeksi global security headers di `_display()` via `emit_security_headers()` (static, `header()` langsung, satu sumber kebenaran): `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `X-XSS-Protection: 1; mode=block`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: geolocation=(), microphone=(), camera=()`, dan `Strict-Transport-Security: max-age=31536000; includeSubDomains` **hanya** saat `ENVIRONMENT === 'production'` + request HTTPS (termasuk `X-Forwarded-Proto` dari proxy). |
| 4 | `application/core/MY_Exceptions.php` | **new** | Menutup celah path error yang `exit()` sebelum `_display()` (`show_404` → `exit(4)`, uncaught exception/fatal → `exit(1)`): override `show_404`/`show_error`/`show_exception`/`show_php_error` memanggil `MY_Output::emit_security_headers()` sebelum delegasi parent. Header ganda aman (PHP `header()` menimpa nilai identik). |
| 5 | `index.php` | edit | Default `ENVIRONMENT = 'production'` bila `CI_ENV` unset (fail-closed) + komentar dev (`CI_ENV=development php -S localhost:8080`, `SetEnv CI_ENV "development"` di vhost lokal). |
| 6 | `application/config/constants.php` | edit | `SHOW_DEBUG_BACKTRACE = (ENVIRONMENT !== 'production')` — backtrace hanya di dev. |
| 7 | `application/config/config.php` | edit | `base_url` ← `APP_BASE_URL` (fallback `http://synapse.test/`); `log_threshold` ← `production ? 1 : 4` (sebelumnya 0 = log mati); `encryption_key` ← `ENCRYPTION_KEY`; `proxy_ips` ← `TRUSTED_PROXIES` (comma list → array, kosong default). |
| 8 | `application/config/database.php` | edit | Kredensial DB env-driven: `DB_HOSTNAME`/`DB_USERNAME`/`DB_PASSWORD`/`DB_DATABASE` (fallback dev `localhost`/`root`/`root`/`db_webtable`). |
| 9 | `.htaccess` | edit | `SetEnv RECAPTCHA_SECRET "<secret>"` hardcoded **DIHAPUS**; contoh env hanya sebagai komentar (tidak diaktifkan di file ter-commit agar dev env tidak ikut ter-deploy). |
| 10 | `application/views/errors/html/error_general.php` | edit | `htmlspecialchars((string)…, ENT_QUOTES, 'UTF-8')` pada `$heading`/`$message`. |
| 11 | `application/views/errors/html/error_404.php` | edit | Sama (escape). |
| 12 | `application/views/errors/html/error_db.php` | edit | Sama (escape). |
| 13 | `application/views/errors/html/error_exception.php` | edit | Escape semua output + blok Type/Message/Filename/Line/Backtrace **hanya** jika `ENVIRONMENT !== 'production'`; production → pesan generik tanpa path/stack. |
| 14 | `application/views/errors/html/error_php.php` | edit | Sama (escape + masking produksi). |
| 15 | `AGENTS.md` | edit | Catatan ⚠️ secret hardcoded diganti status ✅ (semua secret/backdoor dicabut, konfigurasi env-driven, referensi kontrak env plan 10D). |
| 16 | `plan/28_PHASE_10D_PLAN.md` | new | Blueprint (fase sebelumnya). |
| 17 | `plan/29_PHASE_10D_SUMMARY.md` | new | File ini. |

**Tanpa perubahan:** `routes.php`, `system/core/*`, `hooks.php` — sesuai blueprint.

---

## 2. Hasil Lint (`php -l`)

Semua file PHP termodifikasi/baru **lulus** (11/11, 0 error):

```
No syntax errors detected in index.php
No syntax errors detected in application/controllers/Auth.php
No syntax errors detected in application/config/config.php
No syntax errors detected in application/config/constants.php
No syntax errors detected in application/config/database.php
No syntax errors detected in application/core/MY_Output.php
No syntax errors detected in application/core/MY_Exceptions.php
No syntax errors detected in application/views/errors/html/error_general.php
No syntax errors detected in application/views/errors/html/error_404.php
No syntax errors detected in application/views/errors/html/error_db.php
No syntax errors detected in application/views/errors/html/error_exception.php
No syntax errors detected in application/views/errors/html/error_php.php
```

---

## 3. Verifikasi Runtime (dev server `CI_ENV=development php -S 127.0.0.1:8080`)

| # | Pengujian | Hasil |
|---|-----------|-------|
| 1 | `curl -sI /login` — 5 header keamanan, **tanpa** HSTS (dev/HTTP) | ✅ `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`, `Referrer-Policy`, `Permissions-Policy` hadir; `Strict-Transport-Security` tidak muncul |
| 2 | `curl -sI /halaman-tidak-ada` (404) — header tetap hadir (via `MY_Exceptions`) | ✅ 5 header hadir pada HTTP 404 |
| 3 | `/auth/seeder_admin` | ✅ **404** (method dihapus) |
| 4 | `/test_core` | ✅ **404** (file dihapus) |
| 5 | Statik SSL: `grep CURLOPT_SSL_VERIFY Auth.php` | ✅ default `true`/`2`; `false`/`0` hanya dalam blok guard dev (baris 64-65) |
| 6 | Runtime SSL probe (opsi strict vs CA publik Google, file `/tmp/probe_ssl.php`) | ✅ `strict_verify_ok=true errno=0` |
| 7 | Secret scrub: `6Le3PSgt`/`admin123`/`password123` di `application/` + `.htaccess` | ✅ Secret tidak ada lagi. **Kecuali**: `data-sitekey` (site key **publik**) di `register.php:74` & `login.php:73` — bukan rahasia, aman di HTML client (sesuai desain reCAPTCHA v2) |

**Tidak teruji (keterbatasan lingkungan):** flow register/login/rate-limit penuh & fail-closed reCAPTCHA butuh MySQL (`db_webtable`) — tidak tersedia di sandbox ini (`mysql` client & server tidak ada). Logika fail-closed (`secret === '' → FALSE`) tidak berubah dan tetap ter-lint. IP-spoofing test (Kasus A/B/C blueprint §5d) juga butuh DB untuk mengamati tabel `rate_limit` — direkomendasikan dieksekusi di environment dev penuh dengan MySQL.

---

## 4. Review

- **Built-in review subagent (`skill:review` / `tool:security_review`):** dicoba, tetapi tidak menghasilkan verdict (subagent gagal 3× / paused di max_steps) — dicatat sebagai **unavailable**.
- **Review manual (Lead Architect):** seluruh diff diperiksa per file:
  - Tidak ada jalur fail-open: SSL strict default; dev-bypass tidak mungkin aktif di production (guard `ENVIRONMENT !== 'production'`); reCAPTCHA fail-closed pada secret kosong & transport error.
  - Header: satu sumber kebenaran (`MY_Output::emit_security_headers()`), `header()` replace default → tidak ada duplikat pada path ganda (mis. error non-fatal yang dilanjutkan ke `_display()`).
  - PHP 8: semua template menggunakan `htmlspecialchars((string)…, ENT_QUOTES, 'UTF-8')` (hindari TypeError bila nilai bukan string); signature override `MY_Exceptions` kompatibel dengan parent CI3 3.1.13.
  - CI3 compatibility: `MY_` subclass loading standar (`load_class`), tidak ada hack core; `class_exists('MY_Output')` dijamin true (Output di-load di bootstrap sebelum routing/error path).
  - Komentar `ENVIRONMENT`/config aman: `ENVIRONMENT` didefinisikan di `index.php` sebelum `constants.php`/`config.php` di-load.

---

## 5. Catatan Tambahan / Tindak Lanjut

1. **Rotasi reCAPTCHA secret** (manual, di luar kode): secret lama pernah ter-commit di `.htaccess` — ganti `RECAPTCHA_SECRET` di Google reCAPTCHA console dan set env baru di server.
2. **Site key publik** di `views/auth/register.php` & `login.php` sengaja dibiarkan hardcoded (public key; opsional dipindah ke env `RECAPTCHA_SITE_KEY` di fase lanjutan bila diinginkan).
3. **Production deployment** wajib: `CI_ENV=production`, `APP_BASE_URL=https://…`, `ENCRYPTION_KEY` (via `openssl rand -base64 32`), `RECAPTCHA_SECRET` (hasil rotasi), `DB_*`; bila di belakang proxy: `TRUSTED_PROXIES` + pastikan proxy menimpa `X-Forwarded-For` (lihat blueprint §WS-2).
4. **Dev lokal:** `CI_ENV=development php -S localhost:8080` (atau `SetEnv CI_ENV "development"` di vhost Apache lokal — jangan di `.htaccess` ter-commit).
5. **Item roadmap 10D "Input Sanitization Audit"** tetap open sebagai follow-up terpisah (di luar scope fase ini).
6. **Belum di-commit:** perubahan masih di working tree `main`. Langkah berikut sesuai konvensi fase: branch `fase-10d-ssl-network-lockdown` + commit bahasa Indonesia (menunggu instruksi user).
