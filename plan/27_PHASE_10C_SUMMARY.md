# Phase 10C — CSRF Protection & Session Hardening (SUMMARY)

**Project:** Synapse (webtable) · **Branch:** `fase-10c-csrf-session` (dibuat dari `main` @ `d40bced`)
**Status:** ✅ SELESAI — semua target file dieksekusi sesuai blueprint `plan/26_PHASE_10C_PLAN.md` (disetujui).
**Tanggal:** 2026-09-01 (dev sandbox)

---

## 1. Ringkasan Perubahan (git diff highlights)

| # | Perubahan | File | Diff |
|---|-----------|------|------|
| 1 | CSRF aktif + token/cookie rename + `csrf_regenerate = FALSE` | `application/config/config.php` | 6 baris CSRF block |
| 2 | Cookie & session hardening | `application/config/config.php` | `cookie_secure` env-aware, `cookie_httponly = TRUE`, `sess_regenerate_destroy = TRUE` |
| 3 | Partial meta tag + `window.csrfFetch()` | `application/views/templates/csrf_meta.php` (**new**) | — |
| 4 | Inject partial ke `<head>` user layout | `application/views/templates/header.php` | +1 baris |
| 5 | Inject partial ke `<head>` admin layout | `application/views/admin/templates/header.php` | +1 baris |
| 6 | 8 form raw → `form_open()`/`form_close()` | `admin/user_detail.php` (4), `admin/users.php` (1), `admin/dashboard.php` (3) | −11/+11 netto |
| 7 | AJAX → `csrfFetch()`; `toggle_registration` JSON→FormData | `templates/header.php` (2×), `notification/index.php`, `admin/dashboard.php`, `team/index.php` | −12/+6 netto |

**Statistik:** 8 file dimodifikasi + 1 file baru; `git diff --stat` → **36 insertions, 49 deletions**.

### 1.1 `config.php` — CSRF

```php
$config['csrf_protection']  = TRUE;
$config['csrf_token_name']  = 'synapse_csrf_token';
$config['csrf_cookie_name'] = 'synapse_csrf_cookie';
$config['csrf_expire']      = 7200;
$config['csrf_regenerate']  = FALSE;      // multi-tab & multi-aksi per halaman (analisis §3 blueprint)
$config['csrf_exclude_uris'] = array();   // tidak ada webhook eksternal (atur saat Phase 11B)
```

### 1.2 `config.php` — Cookie & session

```php
$config['cookie_secure']    = (ENVIRONMENT === 'production') ? TRUE : FALSE;
$config['cookie_httponly']  = TRUE;
$config['cookie_samesite']  = 'Lax';
$config['sess_time_to_update']     = 300;
$config['sess_regenerate_destroy'] = TRUE;
```

### 1.3 `csrf_meta.php` (new) — kontrak AJAX

- `<meta name="csrf-token-name">` / `<meta name="csrf-token-hash">` dari `$this->security->get_csrf_token_name()/get_csrf_hash()`.
- `window.csrfFetch(url, options)` — wrapper fetch yang **wajib menyuntik token ke body** (CI 3.1.13 hanya validasi `$_POST`, tidak ada fallback header):
  - body `FormData` → `append(token)` (guard `has()`);
  - body string urlencoded → token ditambahkan sebagai field;
  - body JSON → token disuntik ke payload JSON;
  - tanpa body → FormData berisi token.
- Di-inject via `$this->load->view('templates/csrf_meta')` di `<head>` kedua layout.

### 1.4 Form & AJAX

- **8 form raw dikonversi:** `admin/user_detail.php` (toggle_ban unban/ban, cancel_rental, adjust_time), `admin/users.php` (toggle_ban), `admin/dashboard.php` (approve_deposit, approve_withdrawal, decline_withdrawal). Handler `onsubmit="return confirm(...)"` & class dipertahankan. `grep -rn 'method="POST"' application/views/` → **0 hasil**.
- **AJAX dimigrasi:** `user/read_notifications` (header, 2 call site), `notification/mark_all_read`, `admin/toggle_registration` (**body JSON dihapus** — dikirim sebagai FormData berisi token saja agar `$_POST` terisi dan controller `is_ajax_request()` tetap bekerja), `team/claim_level1` + `claim_wage` (variabel manual `csrfName`/`csrfHash` dan `fd.append` dihapus).
- **GET AJAX tidak disentuh** (`admin/chart_data`, `admin/user_xray`) — CSRF hanya memvalidasi POST.

---

## 2. Verifikasi

### 2.1 Lint (`php -l`) — 9/9 lulus

```
No syntax errors detected in application/config/config.php
No syntax errors detected in application/views/templates/csrf_meta.php
No syntax errors detected in application/views/templates/header.php
No syntax errors detected in application/views/admin/templates/header.php
No syntax errors detected in application/views/admin/user_detail.php
No syntax errors detected in application/views/admin/users.php
No syntax errors detected in application/views/admin/dashboard.php
No syntax errors detected in application/views/notification/index.php
No syntax errors detected in application/views/team/index.php
```

### 2.2 UAT — hasil yang terverifikasi di sandbox dev (`php -S localhost:8080`)

| # | Skenario (curl) | Hasil |
|---|---|---|
| A | POST `/index.php/login` dengan **token valid** (cookie CSRF `synapse_csrf_cookie` = hash, `regenerate=FALSE`) | **200** (bukan 403 — gate CSRF lolos; halaman lanjut ke proses controller) ✅ |
| B | POST tanpa token | **403** ✅ |
| C | POST token tampered (`deadbeef`) | **403** ✅ |
| D | Cookie CSRF di dev | `synapse_csrf_cookie=…; Max-Age=7200; path=/; HttpOnly; SameSite=Strict` (tanpa `Secure` — env-aware benar) ✅ |

### 2.3 UAT — diserahkan ke lingkungan ber-DB (belum bisa dijalankan di sandbox)

Sandbox ini **tidak memiliki MySQL** (`mysql.service not found`; halaman yang butuh DB gagal render "Unable to connect to your database server"). CSRF gate berjalan **sebelum** koneksi DB (terbukti: 403 tetap terjadi), sehingga perilaku inti terverifikasi. Langkah UAT yang menunggu lingkungan `synapse.test` + MySQL (blueprint §7.2–7.7):

1. **Login user & admin dengan token valid** → 302/200 (render penuh halaman + session).
2. **Form admin yang dikonversi** (`approve_deposit`, `toggle_ban`, dst.) → 302/200.
3. **AJAX `csrfFetch`** (`read_notifications`, `mark_all_read`, `toggle_registration`, klaim team) → 200 JSON; tanpa token → 403.
4. **Cookie session `ci_session`** → `HttpOnly; SameSite=Lax` (config `cookie_samesite='Lax'`; di produksi + `Secure`).
5. **Produksi** (`CI_ENV=production` + HTTPS): `Secure` flag aktif pada semua cookie.

---

## 3. Catatan Implementasi (temuan selama eksekusi)

1. **CI 3.1.13 meng-hardcode `SameSite=Strict` untuk cookie CSRF** (`system/core/Security.php` `csrf_set_cookie()`) — bukan `Lax`. Ini lebih ketat dari rencana; **tidak diubah** (tidak hack core). Cookie *session* (`ci_session`) tetap mengikuti `cookie_samesite = 'Lax'`.
2. **Halaman auth bersifat standalone** (`auth/login.php`, `auth/register.php`, `auth/change_password.php`, `admin/login.php` tidak memakai `templates/header.php`), sehingga meta tag/`csrfFetch` tidak muncul di sana — **tidak masalah**: halaman tersebut tidak melakukan AJAX dan semua formnya sudah `form_open()` (token hidden otomatis). Meta tag hanya dibutuhkan halaman ber-AJAX (semua halaman user via `templates/header.php` + semua halaman admin via `admin/templates/header.php`).
3. **`form_open()` dengan atribut array** dipakai untuk `onsubmit` yang memuat interpolasi PHP (contoh: `['onsubmit' => "return confirm('Approve deposit {$dep->invoice_number}?')"]`) — hasil render setara atribut string.
4. **`toggle_registration`** kini mengirim FormData token-only (tanpa body JSON) — kontrak controller tidak berubah (`is_ajax_request()` + `set_flashdata` tidak membaca body).
5. Tidak ada perubahan controller, routes, atau logika bisnis — token CSRF di-`unset` oleh engine sebelum controller membaca input.

---

## 4. Files Touched (final)

| File | Aksi |
|------|------|
| `plan/26_PHASE_10C_PLAN.md` | new — blueprint (approved) |
| `plan/27_PHASE_10C_SUMMARY.md` | new — dokumen ini |
| `application/config/config.php` | edit — CSRF + cookie/session hardening |
| `application/views/templates/csrf_meta.php` | **new** — meta tag + `csrfFetch()` |
| `application/views/templates/header.php` | edit — inject partial + 2× `read_notifications` → `csrfFetch` |
| `application/views/admin/templates/header.php` | edit — inject partial |
| `application/views/admin/user_detail.php` | edit — 4 form → `form_open` |
| `application/views/admin/users.php` | edit — 1 form → `form_open` |
| `application/views/admin/dashboard.php` | edit — 3 form → `form_open` + `toggle_registration` → `csrfFetch` |
| `application/views/notification/index.php` | edit — `mark_all_read` → `csrfFetch` |
| `application/views/team/index.php` | edit — 2 klaim → `csrfFetch`, token manual dihapus |

---

## 5. Tindak Lanjut (deferred, sesuai blueprint §9)

- **UAT penuh di lingkungan ber-DB** (`synapse.test` + MySQL) — langkah §2.3.
- **Session timeout 30 menit idle** (roadmap 10C) — stamping `last_activity` di `MY_Controller` (opsional, atau 10D).
- **Logout GET → POST + CSRF** (`auth/logout`, `admin/logout`) — 10D.
- **Custom 403 JSON untuk AJAX** — 10D.
- **Webhook exclusion `csrf_exclude_uris`** — wajib diisi saat Phase 11B menambahkan gateway asli.

*Commit berikutnya: pesan berbahasa Indonesia mengikuti gaya repo (contoh: `feat(security): perlindungan CSRF dan hardening sesi 10C`).*
