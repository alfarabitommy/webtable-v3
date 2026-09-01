# Phase 10C — CSRF Protection & Session Hardening

**Project:** Synapse (webtable) · **Baseline:** `main` (HEAD `974b0b2`) · **Branch kerja:** `fase-10c-csrf-session`
**Mode:** PLANNING — blueprint menunggu persetujuan user. **Belum ada kode/konfigurasi yang diubah.**
**Referensi:** `docs/3_ROADMAP.md` (Phase 10C), `plan/18_PHASE_10B_PLAN.md` (format & gaya), `docs/1_PRD.md` (state machine bisnis), AGENTS.md (SQL hanya di model, `php -l` tiap file, branch per fase), `system/core/Security.php` (engine CSRF CI 3.1.13 — fakta divalidasi langsung dari source).

---

## Ringkasan Perubahan

| # | Perubahan | File |
|---|-----------|------|
| 1 | **Aktifkan CSRF + token/cookie rename + `csrf_regenerate = FALSE`** — analisis lengkap di §3 | `application/config/config.php` (edit) |
| 2 | **Cookie hardening** — `cookie_httponly = TRUE`, `cookie_secure` environment-aware, `sess_regenerate_destroy = TRUE` | `application/config/config.php` (edit) |
| 3 | **Partial view CSRF** — meta tag + JS handler terpusat (dipakai user & admin layout) | `application/views/templates/csrf_meta.php` (**new**) |
| 4 | **Inject partial ke user layout** | `application/views/templates/header.php` (edit) |
| 5 | **Inject partial ke admin layout** | `application/views/admin/templates/header.php` (edit) |
| 6 | **Konversi 8 `<form method="POST">` raw → `form_open()`** (token hidden otomatis) | `application/views/admin/user_detail.php`, `application/views/admin/users.php`, `application/views/admin/dashboard.php` (edit) |
| 7 | **Hardening 3 AJAX POST tanpa token** — `read_notifications` (header), `mark_all_read` (notification), `toggle_registration` (admin dashboard, **JSON → FormData** agar `$_POST` terisi) | `application/views/templates/header.php`, `application/views/notification/index.php`, `application/views/admin/dashboard.php` (edit) |
| 8 | **Refactor AJAX team ke handler terpusat** (sudah punya token; diseragamkan) | `application/views/team/index.php` (edit) |
| 9 | Blueprint ini | `plan/26_PHASE_10C_PLAN.md` (**new**) |

**Tanpa perubahan:** `routes.php` (tidak ada endpoint baru; tidak ada webhook eksternal yang butuh exclusion), controller (tidak ada logika bisnis yang disentuh — CSRF token di-`unset` dari `$_POST` oleh engine sebelum controller membaca input, lihat §3.6).

---

## 1. Hasil Audit

### 1.1 Fakta engine CSRF CI 3.1.13 (dibaca langsung dari `system/core/Security.php`)

| Fakta | Implikasi desain |
|---|---|
| `csrf_verify()` hanya memvalidasi **`$_POST[csrf_token_name]`** vs `$_COOKIE[csrf_cookie_name]` via `hash_equals()` — **tidak ada fallback header** (`X-CSRF-TOKEN` dsb.) | Semua request AJAX **wajib membawa token di body** (FormData / hidden input). Mengirim token via header saja = 403. |
| Token yang valid di-`unset()` dari `$_POST` sebelum controller berjalan | Tidak ada perubahan controller; `$this->input->post()` tidak pernah melihat token. |
| Body JSON (`Content-Type: application/json`) **tidak mengisi `$_POST`** | Endpoint AJAX ber-body JSON (contoh: `admin/toggle_registration`) akan selalu 403 → harus dikonversi ke FormData. |
| `csrf_set_cookie()` memakai `config_item('cookie_httponly')` untuk cookie CSRF | `cookie_httponly = TRUE` membuat cookie CSRF tidak bisa dibaca JS — aman karena hash disuplai lewat meta tag, bukan cookie. |
| Kegagalan → `csrf_show_error()` → `show_error('The action you have requested is not allowed.', 403)` | Halaman error CI standar, HTTP 403. |
| `csrf_regenerate = TRUE` → hash baru + cookie baru di **setiap submission** | Token di halaman yang sudah dirender (termasuk hash di meta/JS) menjadi basi → submissi berikutnya dari halaman yang sama gagal 403 (lihat §3.4). |
| `csrf_exclude_uris` dicocokkan dengan **regex** `preg_match('#^'.$excluded.'$#i', uri_string)` | Pattern regex, bukan prefix. Kosong = semua POST divalidasi. |
| `csrf_verify()` men-set cookie CSRF di **setiap request non-POST** (termasuk GET) | Meta tag selalu tersedia saat render karena `get_csrf_hash()` membaca cookie yang baru di-set. |

### 1.2 Audit konfigurasi saat ini (`application/config/config.php`)

| Item | Nilai sekarang | Status |
|---|---|---|
| `csrf_protection` | `FALSE` | 🔴 OFF — celah utama |
| `csrf_token_name` / `csrf_cookie_name` | `csrf_test_name` / `csrf_cookie_name` (default CI) | 🟡 rename hardening |
| `csrf_expire` | `7200` | 🟢 |
| `csrf_regenerate` | `TRUE` | 🟡 bermasalah utk multi-tab/multi-aksi (lihat §3.4) |
| `csrf_exclude_uris` | `array()` | 🟢 (tidak ada webhook eksternal — §3.5) |
| `cookie_httponly` | `FALSE` | 🔴 harus `TRUE` |
| `cookie_samesite` | `'Lax'` | 🟢 |
| `cookie_secure` | `FALSE` | 🟡 environment-aware (§4.2) |
| `sess_time_to_update` | `300` (5 menit rotasi session ID) | 🟢 |
| `sess_regenerate_destroy` | `FALSE` | 🟡 sebaiknya `TRUE` (§4.3) |
| `sess_samesite` / `sess_expiration` | `Lax` / `7200` | 🟢 |

### 1.3 Audit form (`application/views/`)

**17 form sudah memakai `form_open()` / `form_open_multipart()`** (token hidden otomatis setelah CSRF aktif):
`marketplace/index.php` (checkout), `admin/settings.php`, `admin/user_detail.php` (update_user, inject_balance, inject_rental, reset_password), `admin/users.php` (create_user), `admin/login.php`, `auth/login.php`, `auth/register.php`, `auth/change_password.php`, `wallet/bank_bind.php`, `wallet/withdraw.php`, `wallet/index.php` (topup), `profile/change_password.php`, `profile/index.php` (update, multipart), `rentals/index.php` (claim).

**8 form raw `<form method="POST">` — WAJIB dikonversi ke `form_open()`** (tanpa ini: semua POST dari form ini 403):

| File:Ln | Endpoint | Aksi |
|---|---|---|
| `admin/user_detail.php:111` | `admin/toggle_ban/{id}` (unban) | → `form_open('admin/toggle_ban/'.$user->id)` + `onsubmit` dipertahankan |
| `admin/user_detail.php:117` | `admin/toggle_ban/{id}` (ban) | → `form_open(...)` |
| `admin/user_detail.php:263` | `admin/cancel_rental/{id}` | → `form_open(...)` |
| `admin/user_detail.php:284` | `admin/adjust_time/{id}` | → `form_open(..., 'class="flex flex-wrap items-end gap-3"')` |
| `admin/users.php:99` | `admin/toggle_ban/{id}` | → `form_open(...)` |
| `admin/dashboard.php:156` | `admin/approve_deposit/{id}` | → `form_open(...)` |
| `admin/dashboard.php:203` | `admin/approve_withdrawal/{id}` | → `form_open(...)` |
| `admin/dashboard.php:209` | `admin/decline_withdrawal/{id}` | → `form_open(...)` |

**Form GET** (tidak butuh CSRF, tetap dipertahankan): `admin/users.php:27` (search), `admin/audit.php:35` (filter).

### 1.4 Audit AJAX (fetch/XHR)

| Call site | Endpoint | Method | Token saat ini | Aksi |
|---|---|---|---|---|
| `templates/header.php:137` (toggleNotifDropdown) | `user/read_notifications` | POST | ❌ tidak ada | pakai `csrfFetch()` (§5) |
| `templates/header.php:169` (markAllRead) | `user/read_notifications` | POST | ❌ tidak ada | pakai `csrfFetch()` |
| `notification/index.php:131` | `notification/mark_all_read` | POST | ❌ tidak ada | pakai `csrfFetch()` |
| `admin/dashboard.php:235` | `admin/toggle_registration` | POST + **JSON body** | ❌ tidak ada | **ganti ke FormData** + token (§5.3) |
| `team/index.php:289` | `team/claim_level1` | POST (FormData) | ✅ `csrfName`/`csrfHash` manual | refactor ke `csrfFetch()` |
| `team/index.php:320` | `team/claim_wage` | POST (FormData) | ✅ manual | refactor ke `csrfFetch()` |
| `admin/templates/footer.php:77` | `admin/chart_data` | GET | n/a | tidak berubah |
| `admin/analytics.php:293` | `admin/user_xray/{id}` | GET | n/a | tidak berubah |

### 1.5 Endpoint state-changing via GET (di luar cakupan CSRF POST, dicatat)

- `auth/logout` (link di `profile/index.php:135`) dan `admin/logout` (link di `admin/templates/sidebar.php:58`) — CI3 CSRF hanya memvalidasi POST; logout GET tetap berfungsi. Konversi logout ke POST + CSRF dicatat sebagai item 10D (perubahan UX link→form, di luar scope 10C).
- `wallet/simulate_payment/{invoice}` (route GET, simulator dev Phase 5) — bukan POST, tidak kena CSRF; akan diganti gateway asli di Phase 11A.

---

## 2. Arsitektur CSRF — `application/config/config.php`

### 2.1 Blok konfigurasi final

```php
$config['csrf_protection']  = TRUE;
$config['csrf_token_name']  = 'synapse_csrf_token';
$config['csrf_cookie_name'] = 'synapse_csrf_cookie';
$config['csrf_expire']      = 7200;
$config['csrf_regenerate']  = FALSE;
$config['csrf_exclude_uris'] = array();
```

### 2.2 Keputusan desain

| Keputusan | Alasan |
|---|---|
| `csrf_protection = TRUE` | Inti fase. Semua POST tervalidasi; GET tidak terpengaruh (engine hanya periksa POST). |
| Rename `csrf_token_name`/`csrf_cookie_name` | Hardening kosmetik: nama default CI mudah ditebak attacker untuk membangun payload CSRF yang "mirip". Aman karena **semua pembacaan dinamis**: `form_open()` (helper form), `$this->config->item('csrf_token_name')` (team/index.php), meta tag baru (partial §5.1). Tidak ada string token hardcoded di view/controller. Nama cookie CSRF juga berbeda dari `ci_session` (tidak ada tabrakan). |
| `csrf_expire = 7200` | Sejajar dengan `sess_expiration` (7200). Dengan `regenerate = FALSE`, expire berfungsi sebagai rotasi berkala token per-session. |
| `csrf_regenerate = FALSE` | **Rekomendasi.** Analisis lengkap di §3.4. |
| `csrf_exclude_uris = array()` | Tidak ada webhook/payment callback eksternal saat ini (hanya simulator dev via GET). §3.5 mendokumentasikan aturan masa depan. |

### 2.3 Catatan `ENVIRONMENT` di `config.php`

`ENVIRONMENT` didefinisikan di `index.php:56` sebelum config dimuat → **aman** memakai `ENVIRONMENT === 'production'` di dalam `config.php` untuk `cookie_secure` (§4.2).

---

## 3. Analisis `csrf_regenerate` (evaluasi diminta user)

### 3.1 Perilaku `TRUE` (default CI) di aplikasi ini

1. Setiap POST yang valid → hash & cookie baru di-set (Security.php:239–247).
2. Halaman yang sedang terbuka memegang token lama:
   - **Multi-tab:** submit di tab A me-rotasi token → form di tab B (dirender sebelum rotasi) membawa token basi → **403**.
   - **Multi-aksi per halaman:** halaman `team` punya dua tombol klaim (`claim_level1`, `claim_wage`) yang membaca satu `csrfHash` saat page-load. Klaim pertama sukses & me-rotasi → klaim kedua dari halaman yang sama **403**.
3. JS tidak bisa memperbaiki diri: cookie CSRF akan ber-`HttpOnly` (tidak bisa dibaca JS), CI 3.1.13 tidak punya fallback header, dan token hanya bisa disuplai server di tiap response JSON — kontrak baru di ~5 endpoint.

### 3.2 Perilaku `FALSE`

- Token stabil per session; valid selama `csrf_expire` (7200 s). Rotasi terjadi saat: session baru, atau expire cookie (engine generate hash baru saat cookie kedaluwarsa — `_csrf_set_hash()`).
- Form lama (> 2 jam) yang disubmit → 403 (perilaku wajar; token memang basi).
- `csrf_regenerate = FALSE` **tidak** melonggarkan validasi: setiap POST tetap dicek `hash_equals()` terhadap cookie.

### 3.3 Trade-off keamanan (jujur)

| | `TRUE` | `FALSE` |
|---|---|---|
| Token yang tertangkap attacker | Mati setelah 1× pemakaian | Berlaku sampai expire (≤ 2 jam) |
| Multi-tab / back-button | 403 (frustrasi user) | ✅ normal |
| Multi-aksi per halaman (team) | 403 pada aksi ke-2 | ✅ normal |
| Kompleksitas JS | Wajib sinkron hash dari server | nol |

Mitigasi risiko `FALSE`: `SameSite=Lax` (cookie CSRF + session), `HttpOnly`, HTTPS produksi (`cookie_secure`), dan `csrf_expire` 2 jam membatasi jendela reuse.

### 3.4 Kesimpulan

**`csrf_regenerate = FALSE`** — sesuai pertimbangan user (multi-tab & form submission) dan perilaku nyata engine CI 3.1.13 di aplikasi ini (halaman `team` multi-aksi, JS tidak bisa baca cookie HttpOnly, tidak ada header fallback). Dicatat sebagai **keputusan desain yang bisa dibalik**: jika di masa depan diinginkan rotasi per-submission, syaratnya server wajib mengembalikan hash baru di tiap response JSON AJAX (kontrak di `csrfFetch`).

### 3.5 `csrf_exclude_uris` — keputusan

**Tetap `array()`.** Audit §1.5: tidak ada webhook eksternal / payment callback. Aturan ke depan (didokumentasikan untuk Phase 11B):
- Webhook server-to-server **wajib masuk exclusion** dengan regex `$route`-style, contoh: `array('payment/webhook/(:any)')` → `payment/webhook/.+`.
- Exclusion hanya untuk endpoint yang **tidak punya session user** dan memakai mekanisme otentikasi sendiri (signature verification). Endpoint yang bisa diakses browser tidak boleh di-exclude.

### 3.6 Dampak ke controller — nihil

Engine meng-`unset` token dari `$_POST` sebelum controller membaca input (Security.php:236). Tidak ada perubahan pada `Auth`, `Admin`, `Wallet`, `Rentals`, `Profile`, `Team`, `Notification`, `User`.

---

## 4. Session & Cookie Hardening — `application/config/config.php`

### 4.1 Blok cookie final

```php
$config['cookie_secure']   = (ENVIRONMENT === 'production') ? TRUE : FALSE;
$config['cookie_httponly'] = TRUE;
$config['cookie_samesite'] = 'Lax';
```

### 4.2 Keputusan `cookie_secure` (environment-aware)

- `ENVIRONMENT` tersedia di `config.php` (didefinisikan di `index.php:56` sebelum config dimuat).
- **production → TRUE** (HTTPS wajib; cookie tidak akan terkirim via HTTP — perilaku yang diinginkan).
- **development → FALSE** (dev `php -S localhost:8080` dan `http://synapse.test/` tanpa TLS: cookie Secure tidak akan disimpan browser → session & CSRF mati total di dev).
- Catatan deploy: pastikan produksi memakai HTTPS; verifikasi di §7 (Skenario D).

### 4.3 Session hardening

```php
$config['sess_time_to_update']    = 300;   // dipertahankan — rotasi session ID tiap 5 menit (anti session fixation)
$config['sess_regenerate_destroy'] = TRUE; // baru: hapus data session lama saat rotasi ID
```

- `sess_regenerate_destroy = TRUE`: saat session ID dirotasi (tiap 300 s), data session lama dihapus — memperkecil jendela session fixation. Biaya: `ci_session` file lama dihapus segera (vs ditunggu GC). Aman untuk driver `files`.
- `sess_expiration = 7200` dipertahankan.
- **Item roadmap "session timeout 30 menit idle"** → §8 (deferred: butuh stamping `last_activity` per request di `MY_Controller`; ditawarkan sebagai tambahan opsional fase ini).

### 4.4 Interaksi HttpOnly dengan CSRF

- `cookie_httponly = TRUE` berlaku juga untuk **cookie CSRF** (Security.php:286). JS tidak membaca cookie — hash disuplai via **meta tag** (partial §5.1). Tidak ada konflik.
- `SameSite = Lax` (session & CSRF cookie): mencegah cookie terkirim pada cross-site POST — lapisan pertahanan tambahan di atas token.

---

## 5. AJAX & Dynamic Request Hardening

### 5.1 Partial view baru — `application/views/templates/csrf_meta.php`

Satu sumber token untuk kedua layout (user & admin), di-include dari `<head>` masing-masing header:

```php
<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!-- Phase 10C: CSRF meta tags — wajib ada di tiap halaman sebelum JS AJAX dipakai -->
<meta name="csrf-token-name" content="<?= $this->security->get_csrf_token_name(); ?>">
<meta name="csrf-token-hash" content="<?= $this->security->get_csrf_hash(); ?>">
<script>
(function () {
    var name = document.querySelector('meta[name="csrf-token-name"]');
    var hash = document.querySelector('meta[name="csrf-token-hash"]');
    if (!name || !hash) { console.error('CSRF meta tags missing'); return; }
    var TOKEN_NAME = name.content, TOKEN_HASH = hash.content;

    window.getCsrfTokenName = function () { return TOKEN_NAME; };
    window.getCsrfTokenHash = function () { return TOKEN_HASH; };

    /** Wrapper fetch standar: selalu menyuntik token CSRF ke body POST.
     *  CI 3.1.13 hanya memvalidasi $_POST → token wajib di body (bukan header).
     *  - body FormData → token di-append.
     *  - body string (urlencoded/JSON) → token disuntik sebagai field.
     *  - tanpa body → FormData berisi token. */
    window.csrfFetch = function (url, options) {
        options = options || {};
        options.headers = options.headers || {};
        options.headers['X-Requested-With'] = 'XMLHttpRequest';
        var body = options.body;

        if (typeof FormData !== 'undefined' && body instanceof FormData) {
            if (!body.has(TOKEN_NAME)) body.append(TOKEN_NAME, TOKEN_HASH);
        } else if (typeof body === 'string') {
            var sep = body.indexOf('=') === -1 ? '' : (body.indexOf('?') !== -1 ? '&' : (body.length ? '&' : ''));
            // token disuntik ke payload string (urlencoded maupun JSON blok)
            if (body.charAt(0) === '{') {
                try {
                    var obj = JSON.parse(body);
                    obj[TOKEN_NAME] = TOKEN_HASH;
                    options.body = JSON.stringify(obj);
                } catch (e) { /* fallthrough: tambah field urlencoded */ }
            }
            if (options.body !== body) {
                // sudah di-handle JSON
            } else {
                options.body = body + (body.length ? '&' : '') + encodeURIComponent(TOKEN_NAME) + '=' + encodeURIComponent(TOKEN_HASH);
            }
        } else if (body === undefined || body === null) {
            var fd = new FormData();
            fd.append(TOKEN_NAME, TOKEN_HASH);
            options.body = fd;
        }
        return fetch(url, options);
    };
})();
</script>
```

*(Detail: string kosong/`=`-edge cases disederhanakan saat implementasi — inti kontrak: **setiap POST AJAX membawa token di body**.)*

### 5.2 Injeksi ke layout

- `application/views/templates/header.php` — dalam `<head>`, setelah tag `<title>`: `<?php $this->load->view('templates/csrf_meta'); ?>`
- `application/views/admin/templates/header.php` — dalam `<head>`, setelah `<title>`: `<?php $this->load->view('templates/csrf_meta'); ?>`

### 5.3 Migrasi call site AJAX

| Call site | Perubahan |
|---|---|
| `templates/header.php` (2× `read_notifications`) | ganti `fetch(url, {...})` → `csrfFetch(url, { method: 'POST' })` |
| `notification/index.php` (`mark_all_read`) | `fetch` → `csrfFetch(url, { method: 'POST' })` |
| `admin/dashboard.php` (`toggle_registration`) | **hapus `Content-Type: application/json`**; ganti body JSON → `FormData` (token di-append otomatis oleh `csrfFetch`); controller `Admin::toggle_registration` tidak berubah (tidak membaca body JSON, hanya `is_ajax_request`) |
| `team/index.php` (`claim_level1`, `claim_wage`) | hapus deklarasi manual `csrfName`/`csrfHash` + `fd.append(csrfName, csrfHash)` → `csrfFetch(url, { method: 'POST', body: fd })` (token di-append otomatis; `fd.has()` guard mencegah duplikat) |
| GET AJAX (`chart_data`, `user_xray`) | tanpa perubahan (GET tidak divalidasi CSRF) |

---

## 6. Konversi Form Raw (detail implementasi)

Pola konversi seragam (contoh `admin/dashboard.php:156`):

```php
<!-- SEBELUM -->
<form method="POST" action="<?= site_url('admin/approve_deposit/' . $dep->id) ?>" ...>
<!-- SESUDAH -->
<?= form_open('admin/approve_deposit/' . $dep->id, 'class="..."') ?>
```

- Atribut `onsubmit="return confirm(...)"` dan class dipertahankan (dipindah ke argumen kedua `form_open`).
- `form_open()` otomatis menyisipkan `<input type="hidden" name="synapse_csrf_token" value="...">` saat `csrf_protection = TRUE` — tidak perlu input manual.
- Form raw dengan `method="POST"` yang TIDAK dikonversi akan 403 (dicek di Skenario B, §7).

---

## 7. Verification & Testing Protocol

### 7.1 Lint (Roadmap Rule)

```bash
php -l application/config/config.php
php -l application/views/templates/csrf_meta.php
php -l application/views/templates/header.php
php -l application/views/admin/templates/header.php
php -l application/views/team/index.php
php -l application/views/notification/index.php
php -l application/views/admin/dashboard.php
php -l application/views/admin/user_detail.php
php -l application/views/admin/users.php
```
Wajib: `No syntax errors detected in ...` untuk seluruh file. *(File view yang berisi PHP+HTML tetap memenuhi syarat `php -l`.)*

### 7.2 Setup test

- Server: `php -S localhost:8080` dari project root (pakai `http://localhost:8080/index.php/...` bila pretty-URL nonaktif).
- Cookie jar per skenario: `curl -c /tmp/cj.txt -b /tmp/cj.txt`.
- Ekstraksi token dari halaman: `TOKEN=$(curl -s -c /tmp/cj.txt http://localhost:8080/index.php/login | grep -oP 'name="synapse_csrf_token" value="\K[^"]+')`.

### 7.3 Skenario A — Form POST valid dengan token sukses (user & admin)

1. User login: GET `/index.php/login` (jar + token) → POST `phone/password/synapse_csrf_token=$TOKEN` → harap **302** (redirect home).
2. Admin login `control-panel` → POST → **302**.
3. Form raw yang dikonversi (contoh `admin/approve_deposit/{id}`): POST dengan token dari halaman dashboard → **302/200** sesuai alur.
4. Form GET (search/filter) → tetap **200**.

### 7.4 Skenario B — Token hilang / tampered → 403

```bash
# POST tanpa token
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/index.php/login -d "phone=081234567890&password=x"
# → 403

# POST dengan token salah (tampered)
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/index.php/login \
     -d "phone=081234567890&password=x&synapse_csrf_token=deadbeef"
# → 403

# body berisi teks error CI standar
curl -s -X POST http://localhost:8080/index.php/login -d "phone=081234567890&password=x" | grep -c "requested is not allowed"
# → 1
```
Ulangi untuk 1 endpoint admin (`admin/toggle_ban/{id}`) → 403 tanpa token.

### 7.5 Skenario C — AJAX

1. **Dengan token (csrfFetch):** muat halaman (jar+token), lalu:
   - `user/read_notifications` (POST FormData token) → **200** `{"success":true}`.
   - `notification/mark_all_read` → **200**.
   - `admin/toggle_registration` (FormData, bukan JSON) → **200** `{"success":true,...}`.
   - `team/claim_level1` & `claim_wage` (halaman team; guard bisnis mungkin menolak — yang diuji adalah **tidak 403**: response JSON `{"success":false,...}` dengan pesan bisnis, bukan halaman error CSRF).
2. **Tanpa token (simulasi regresi):** `curl -X POST /index.php/user/read_notifications -H "X-Requested-With: XMLHttpRequest"` → **403**.

### 7.6 Skenario D — Flag cookie

```bash
curl -s -o /dev/null -D - -c /tmp/cj.txt http://localhost:8080/index.php/login
# Harap muncul (dev; production memakai https):
# Set-Cookie: ci_session=...; Path=/; HttpOnly; SameSite=Lax
# Set-Cookie: synapse_csrf_cookie=...; Path=/; HttpOnly; SameSite=Lax
# (cookie_secure menambahkan "; Secure" hanya saat ENVIRONMENT=production)
```
Verifikasi isi `/tmp/cj.txt`: flag `#HttpOnly_` dan `#SameSite=` ada. Untuk produksi: set `CI_ENV=production` + https → cek `; Secure` muncul (verifikasi manual/deploy, bukan dev server).

### 7.7 Skenario E — `csrf_regenerate = FALSE` (stabilitas multi-aksi)

Pada halaman `team`: dua POST berurutan dengan **token yang sama** dari satu page-load:
1. Klaim 1 → response JSON bisnis (bukan 403).
2. Klaim 2 (token sama) → response JSON bisnis (bukan 403).
→ membuktikan token stabil antar submission (regenerate OFF). *(Dengan ON, langkah 2 akan 403.)*

### 7.8 Hygiene review

```bash
grep -rn 'method="POST"' application/views/        # → 0 hasil (semua sudah form_open)
grep -rn 'csrfFetch\|getCsrfTokenHash' application/views/   # call site sesuai §5.3
grep -rn 'csrf_test_name\|csrf_cookie_name' application/  # → 0 (rename bersih)
git diff --stat
git status --short
```

---

## 8. Files Touched (Phase 10C)

| File | Action |
|------|--------|
| `plan/26_PHASE_10C_PLAN.md` | **new** — blueprint ini |
| `application/config/config.php` | edit — blok CSRF (§2.1) + cookie/session (§4.1, §4.3) |
| `application/views/templates/csrf_meta.php` | **new** — meta tag + `csrfFetch` (§5.1) |
| `application/views/templates/header.php` | edit — inject partial (§5.2) + migrasi 2× `read_notifications` (§5.3) |
| `application/views/admin/templates/header.php` | edit — inject partial (§5.2) |
| `application/views/notification/index.php` | edit — `mark_all_read` → `csrfFetch` (§5.3) |
| `application/views/admin/dashboard.php` | edit — `toggle_registration` JSON→FormData + `csrfFetch` (§5.3) |
| `application/views/team/index.php` | edit — refactor claim ke `csrfFetch`, hapus token manual (§5.3) |
| `application/views/admin/user_detail.php` | edit — 4 form raw → `form_open` (§6) |
| `application/views/admin/users.php` | edit — 1 form raw → `form_open` (§6) |
| `application/views/admin/dashboard.php` | edit — 3 form raw → `form_open` (§6) |

---

## 9. Out of Scope / Ditunda

- **Session timeout 30 menit idle (roadmap 10C)** — memerlukan stamping `last_activity` per request (`MY_Controller`) + cek pada tiap request; diusulkan sebagai tambahan kecil fase ini **hanya jika disetujui**, jika tidak → ikut 10D. `sess_expiration = 7200` saat ini adalah timeout absolut, bukan idle.
- **Konversi logout GET → POST + CSRF** (`auth/logout`, `admin/logout`) — perubahan UX (link → form), diusulkan ke 10D. CSRF CI3 tidak memvalidasi GET, jadi tidak menghalangi fase ini.
- **Custom error page 403 untuk AJAX** — `show_error()` merender HTML; AJAX yang gagal CSRF dapat diubah jadi JSON 403 via `MY_Exceptions` (opsional, 10D).
- **Webhook exclusion** — belum ada gateway asli; saat Phase 11B menambahkan webhook, endpoint tersebut wajib masuk `csrf_exclude_uris` (aturan §3.5).
- **Session binding IP/User-Agent, `proxy_ips`** — 10D (sama dengan catatan 10A/10B).
- **Rotasi token per-submission (`csrf_regenerate = TRUE`)** — ditolak untuk fase ini dengan alasan §3.4; keputusan terdokumentasi dan dapat dibalik dengan kontrak server-return-hash.

---

*Blueprint menunggu persetujuan user (Tommy). Setelah approval: buat branch `fase-10c-csrf-session`, eksekusi §2–§6, verifikasi §7, commit berbahasa Indonesia.*
