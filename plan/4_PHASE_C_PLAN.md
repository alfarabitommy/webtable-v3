# Langkah 0 — Phase C: Security Patch — Ekstraksi Secret reCAPTCHA ke Environment

**Project:** Synapse (webtable) · **Baseline:** `main` @ `87ac933` (branch kerja: `fase-0-housekeeping`)
**Mode:** PLAN — blueprint di bawah belum dieksekusi. Tunggu persetujuan user sebelum menyentuh file kode.
**Referensi:** `plan/0_HOUSEKEEPING_PLAN.md` §Phase C (C1–C5); Roadmap Rule #6 (No Hardcoded Credentials — `docs/3_ROADMAP.md` baris 14).
**Constraint:** Tidak ada perubahan logika aplikasi selain patch keamanan ini. Tidak ada fitur baru.

---

## Ringkasan Perubahan

| # | Perubahan | Severity | File |
|---|-----------|----------|------|
| C1 | File konfigurasi baru `recaptcha.php` — membaca `RECAPTCHA_SECRET` & `RECAPTCHA_SITE_KEY` via `getenv()` | — | `application/config/recaptcha.php` (baru) |
| C2 | `Auth.php`: hapus property berisi literal secret; load config di constructor; `_verify_recaptcha()` fail-closed | **High** | `application/controllers/Auth.php` |
| C3 | Strategi suplai env var di dev & production | — | dokumen ini |
| C4 | Verifikasi & hygiene (lint, grep, test case present/missing) | — | dokumen ini |

---

## 1. Blueprint File Konfigurasi Baru — `application/config/recaptcha.php`

Buat file baru dengan struktur persis berikut. **Tidak boleh ada satu pun literal key** di dalamnya (Roadmap Rule #6).

```php
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
```

**Catatan desain (wajib dipertahankan saat implementasi):**

1. **`(string) getenv('...')`** — PHP 7.1+ mengembalikan `string|false`; cast ke `string` menjamin nilai selalu bertipe string, sehingga *unset* menjadi `''` (kosong) — kondisi fail-closed terdeteksi secara deterministik.
2. **Akses dengan index** — config dimuat dengan `$this->config->load('recaptcha', TRUE)`, sehingga item diakses via `$this->config->item('recaptcha_secret', 'recaptcha')` (argumen kedua = nama file config). Ini menghindari tabrakan nama key dengan config lain.
3. **Guard `BASEPATH`** — mengikuti konvensi AGENTS.md (semua file PHP).
4. **Tanpa komentar nilai asli** — literal secret lama tidak boleh muncul bahkan di komentar (hygiene, §4).
5. `.env`/`putenv` **tidak** digunakan — hanya `getenv()` (env proses). Fallback dotenv untuk shared hosting didokumentasikan sebagai follow-up (§3.5), bukan bagian patch ini.

---

## 2. Strategi Patch Controller — `application/controllers/Auth.php`

### 2.1 Hapus property secret (baris 6)

Hapus baris berikut **secara utuh** — literal secret meninggalkan repo dan tidak boleh disalin ke file mana pun (nilai asli di-redact di sini; jangan pernah menuliskannya ulang di repo):

```php
private $recaptcha_secret = '<REDACTED_SECRET>';
```

### 2.2 Update constructor — load config `recaptcha`

```php
public function __construct() {
    parent::__construct();
    $this->config->load('recaptcha', TRUE);
    $this->load->model('User_model');
}
```

- Load config **sebelum** model (tidak ada dependensi, tapi urutan ini menjaga `_verify_recaptcha()` siap dipakai di `register()`/`login()`).
- `TRUE` = mode index/return: item disimpan di bawah kunci `'recaptcha'` → konsisten dengan akses `config->item(..., 'recaptcha')`.

### 2.3 Refactor `_verify_recaptcha()` — fail-closed

`register()` (baris 68) dan `login()` (baris 126) **tidak diubah** — call site tetap sama; perilaku identik selama `RECAPTCHA_SECRET` ter-set.

```php
private function _verify_recaptcha($recaptcha_response) {
    if (empty($recaptcha_response)) {
        return FALSE;
    }

    // Fail-closed: tanpa secret yang terkonfigurasi, tolak verifikasi
    // dan catat error — JANGAN pernah lanjut ke curl ke Google.
    $secret = (string) $this->config->item('recaptcha_secret', 'recaptcha');
    if ($secret === '') {
        log_message('error', 'reCAPTCHA secret belum dikonfigurasi (env RECAPTCHA_SECRET) — verifikasi ditolak (fail-closed).');
        return FALSE;
    }

    $data = array('secret' => $secret, 'response' => $recaptcha_response);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://www.google.com/recaptcha/api/siteverify");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // CRITICAL FIX — bypass SSL untuk local dev
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // CRITICAL FIX — bypass SSL untuk local dev

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response);
    return isset($result->success) && $result->success === true;
}
```

**Perubahan vs status quo:**
- `$this->recaptcha_secret` (property) → `$this->config->item('recaptcha_secret', 'recaptcha')` (env-driven).
- Blok guard baru (fail-closed): jika secret kosong/unset → `log_message('error', ...)` + `return FALSE` **tanpa** panggilan curl. Artinya register/login tertolak dengan pesan "Verifikasi reCAPTCHA gagal…" (pesan lama, tidak berubah) — dan kegagalan *selalu* terlihat di log, bukan gagal senyap.
- Sisanya (curl siteverify, parsing JSON) tidak disentuh — perbaikan SSL-verify tetap tertunda di Phase 10D (di luar scope, lihat §6).

---

## 3. Strategi Environment Setup & Deployment

Env var **harus diset pada window rilis yang sama** dengan patch ini — kalau tidak, fail-closed memblokir register/login (by design).

### 3.1 Local dev — `php -S`

```bash
RECAPTCHA_SECRET="<secret-asli-anda>" RECAPTCHA_SITE_KEY="<site-key>" php -S localhost:8080
```

- Prefix env di depan perintah → tersedia untuk `getenv()` di proses PHP.
- Tanpa env var (untuk menguji fail-closed): `php -S localhost:8080` polos.
- Catatan: `base_url` default `http://synapse.test/`; untuk smoke test cukup `http://localhost:8080/...` (CI3 menerima keduanya; redirect `login`/`home` mengikuti `base_url`).

### 3.2 Apache (mod_php / mod_ruid2)

- Vhost (disarankan): di dalam `<VirtualHost>`:
  ```apache
  SetEnv RECAPTCHA_SECRET "<secret-asli-anda>"
  SetEnv RECAPTCHA_SITE_KEY "<site-key>"
  ```
- `.htaccess` (hanya jika `AllowOverride Options` diizinkan oleh konfigurasi server):
  ```apache
  SetEnv RECAPTCHA_SECRET "<secret-asli-anda>"
  SetEnv RECAPTCHA_SITE_KEY "<site-key>"
  ```
  (`SetEnv` adalah direktif mod_env; konteks `.htaccess` butuh `AllowOverride Options`.)
- **Jangan commit** `.htaccess` yang berisi secret asli ke repo — jika `SetEnv` ditempatkan di `.htaccess` untuk dev lokal, file itu wajib di-gitignore (lihat §6) atau secret disuntik lewat mekanisme server.

### 3.3 nginx + PHP-FPM

Di dalam blok `location ~ \.php$` (atau konteks `server`):

```nginx
fastcgi_param RECAPTCHA_SECRET "<secret-asli-anda>";
fastcgi_param RECAPTCHA_SITE_KEY "<site-key>";
```

Lalu `nginx -t && systemctl reload nginx` (atau `service nginx reload`).

### 3.4 CLI / cron / systemd

- Shell: `export RECAPTCHA_SECRET="<secret-asli-anda>"` sebelum menjalankan job.
- systemd unit: `Environment=RECAPTCHA_SECRET=<secret-asli-anda>` di bagian `[Service]`.

### 3.5 Fallback (dokumentasi saja — TIDAK diimplementasikan di patch ini)

Shared hosting yang tidak bisa menyetel env var → opsi minimal dotenv loader di `index.php` (baca `.env` di luar webroot). Dicatat sebagai follow-up, konsisten dengan §C3 `0_HOUSEKEEPING_PLAN.md`.

### 3.6 Alur tanpa merusak login/register saat testing

| Skenario | Env var | Hasil |
|---|---|---|
| Dev normal | `RECAPTCHA_SECRET` ter-set | Verifikasi berjalan normal (curl ke Google) |
| Dev tanpa internet | `RECAPTCHA_SECRET` ter-set | curl gagal → `json_decode(null)` → `success` tidak ter-set → `FALSE` → login/register tertolak (tidak crash, halaman render dengan pesan error) |
| Fail-closed test | `RECAPTCHA_SECRET` tidak diset | Langsung `FALSE` + error log, tanpa curl — behavior deterministik |

---

## 4. Protokol Verifikasi & Hygiene

### 4.1 Syntax lint (Roadmap Rule — semua file PHP baru/diubah)

```bash
php -l application/config/recaptcha.php
php -l application/controllers/Auth.php
```

Kedua perintah wajib menghasilkan `No syntax errors detected`.

### 4.2 Hygiene grep — tidak ada literal secret tersisa di controller layer

```bash
# 1) Literal SECRET lama (PRIVATE) — wajib 0 match di seluruh application/
#    (ganti <REDACTED_SECRET> dengan literal secret asli saat menjalankan — literal TIDAK pernah disimpan di repo)
grep -rn "<REDACTED_SECRET>" application/ || echo "CLEAN: 0 matches"

# 2) Tidak boleh ada referensi property $recaptcha_secret di Auth.php
grep -n "recaptcha_secret" application/controllers/Auth.php
#   → hanya baris: $this->config->item('recaptcha_secret', 'recaptcha') (di _verify_recaptcha)
#   dan TIDAK ada `private $recaptcha_secret` / `$this->recaptcha_secret`

# 3) Config membaca env — getenv() wajib ada di recaptcha.php
grep -n "getenv" application/config/recaptcha.php

# 4) SITE KEY (PUBLIC) masih di views — DIPERBOLEHKAN (bukan secret), tercatat sebagai C4 follow-up
grep -rn "6Le3PSgtAAAAAFHpzlaZX-h70_zV1fIyKXR00THy" application/views/auth/
```

> Catatan hygiene penting: site key `6Le3PSgtAAAAAFHpzlaZX-h70_zV1fIyKXR00THy` (di `views/auth/login.php:66` & `register.php:74`) adalah kunci **publik** — berbeda dari secret. Tidak dihapus di Phase C (C4 di `0_HOUSEKEEPING_PLAN.md` mendefer pemindahannya ke config). Verifikasi grep §4.2.#1 menyasar literal **secret**, bukan site key.

Tambahan: `git diff application/controllers/Auth.php` harus menunjukkan baris literal secret **terhapus** dan tidak ada baris baru yang memuatnya.

### 4.3 Test cases

**Test A — `RECAPTCHA_SECRET` PRESENT (happy path, dev normal)**

1. Start: `RECAPTCHA_SECRET="dummy-value-untuk-test" RECAPTCHA_SITE_KEY="dummy" php -S localhost:8080`
2. `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/register` → `200`
3. `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/login` → `200`
4. `curl -s -X POST http://localhost:8080/auth/register` (tanpa `g-recaptcha-response`) → halaman render dengan pesan "Verifikasi reCAPTCHA gagal…", **tidak ada** insert user (fail-fast sama seperti hari ini).
5. POST `g-recaptcha-response=abc` (token palsu) → curl ke Google terpanggil; tanpa jaringan: `FALSE` + halaman error (no crash); dengan jaringan: `success=false` → `FALSE` (tertahan). Token asli + browser widget = E2E penuh, di luar sandbox — dicatat sebagai keterbatasan pengujian.

**Test B — `RECAPTCHA_SECRET` MISSING (fail-closed)**

1. Start: `php -S localhost:8080` (tanpa env var).
2. `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/register` → `200` (halaman tetap render — bukan error 500).
3. `curl -s -X POST http://localhost:8080/auth/login -d "phone=081234567890&password=xxx&g-recaptcha-response=abc"` → `_verify_recaptcha()` `FALSE` **tanpa panggilan curl**; pesan error reCAPTCHA tampil; login tidak diproses.
4. Cek log: `grep -l "fail-closed" application/logs/log-*.php` → file log berisi baris `ERROR - ... reCAPTCHA secret belum dikonfigurasi (env RECAPTCHA_SECRET) — verifikasi ditolak (fail-closed).`

**Test C — Hygiene**

- Semua grep di §4.2: #1 → 0 match; #2 → hanya baris config-read; #3 → `getenv` ada.

**Test D — Lint**

- `php -l` kedua file → `No syntax errors detected`.

---

## 5. Branch & Commit

- Branch: `fase-0-housekeeping` (sudah aktif per `0_HOUSEKEEPING_PLAN.md` Phase E).
- Commit terpisah untuk Phase C, pesan dalam Bahasa Indonesia sesuai gaya repo:
  `Langkah 0: ekstraksi secret reCAPTCHA ke env (recaptcha.php) dan fail-closed di Auth`
- Merge ke `main` hanya setelah user konfirmasi dan semua check §4 hijau.

---

## 6. Out of Scope (didokumentasikan, follow-up)

- Pemindahan **site key** dari `views/auth/login.php` & `register.php` ke config (C4 `0_HOUSEKEEPING_PLAN.md`) — menyentuh views, di-defer.
- Re-enable `CURLOPT_SSL_VERIFYPEER/HOST` (Phase 10D).
- Dotenv loader fallback untuk shared hosting (§3.5).
- Penambahan `.gitignore` entry untuk `.htaccess` lokal yang memuat secret (jika dipakai) — diimplementasi bersama Phase E housekeeping, bukan patch kode ini.

---

## Files Touched (Phase C saja)

| File | Action |
|------|--------|
| `plan/4_PHASE_C_PLAN.md` | **new** — blueprint ini |
| `application/config/recaptcha.php` | **new** — env-driven keys, tanpa literal |
| `application/controllers/Auth.php` | edit — hapus property secret; load config; `_verify_recaptcha()` fail-closed |

**STRICT RULE:** Tidak ada file kode yang diubah/dibuat sampai user menyetujui eksekusi Phase C.
