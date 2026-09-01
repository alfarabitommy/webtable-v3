# Phase 10B — Rate Limiting & Brute Force Protection (SUMMARY)

**Project:** Synapse (webtable) · **Branch:** `fase-10b-rate-limiting` (dibuat dari `main` @ `974b0b2`)
**Status:** ✅ IMPLEMENTASI SELESAI di branch — **belum** di-commit, menunggu analisis & konfirmasi user (termasuk tes DB di environment nyata, lihat §5).
**Tujuan dokumen:** ringkasan eksekusi Phase 10B untuk dianalisis sebelum merge ke `main`.
**Blueprint:** `plan/18_PHASE_10B_PLAN.md` (APPROVED).

---

## 1. Ringkasan Eksekusi

| # | Deliverable | File | Status |
|---|---|---|---|
| 1 | Blueprint Phase 10B | `plan/18_PHASE_10B_PLAN.md` (new) | ✅ |
| 2 | DDL tabel `rate_limits` (UNIQUE `rate_key` + index GC) | `database.sql` (edit) | ✅ |
| 3 | Model `Rate_limit_model` — `check()` / `hit()` / `clear()` / `gc()` | `application/models/Rate_limit_model.php` (new) | ✅ |
| 4 | Helper `rate_limit_message()` + `rate_limit_json_response()` (HTTP 429) | `application/helpers/ratelimit_helper.php` (new) | ✅ |
| 5 | Instrumentasi `Auth::login()` — key `login:{phone}:{ip}` | `application/controllers/Auth.php` (edit) | ✅ |
| 6 | Instrumentasi `Auth::register()` — key `register:{ip}` (burst limiter) | `application/controllers/Auth.php` (edit) | ✅ |
| 7 | Instrumentasi `Admin_auth::login()` — key `admin_login:{username}:{ip}` | `application/controllers/Admin_auth.php` (edit) | ✅ |
| 8 | Instrumentasi `Rentals::claim()` — key `claim_roi:{user_id}` + POST-only guard | `application/controllers/Rentals.php` (edit) | ✅ |
| 9 | Instrumentasi `Wallet::process_withdraw()` — key `withdraw:{user_id}` | `application/controllers/Wallet.php` (edit) | ✅ |
| 10 | Verifikasi lint (`php -l`) seluruh file | — | ✅ (6/6) |

**Tidak diubah:** `routes.php` (semua endpoint sudah ter-route), `autoload.php` (model & helper di-`load` eksplisit per controller), view (pesan error inline/flash yang sudah ada tetap dipakai), `Admin.php`/admin panel.

---

## 2. Storage Schema — `rate_limits` (database.sql, §1.1 plan)

```sql
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rate_key` VARCHAR(191) NOT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_attempt_at` DATETIME NOT NULL,
  `locked_until` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rate_key` (`rate_key`),
  INDEX `idx_last_attempt_at` (`last_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `rate_key` plaintext komposit (`login:081234567890:127.0.0.1`) — debuggable, di bawah batas 191 char index UNIQUE InnoDB utf8mb4.
- **GC:** `Rate_limit_model::gc()` = `DELETE FROM rate_limits WHERE last_attempt_at < NOW() - INTERVAL 30 MINUTE` (2× window). Dipanggil probabilistik `_maybe_gc()` (~1% per check/hit) + siap untuk cron per jam.

**Pre-flight DB live (CI3 tanpa migration):**

```bash
mysql -u root -p db_webtable -e "SHOW TABLES LIKE 'rate_limits';"
# jika tidak ada → apply DDL di atas secara manual (pola sama dengan Phase 10A)
```

---

## 3. Engine — `Rate_limit_model` (application/models/Rate_limit_model.php)

API (signature persis spesifikasi; `hit()` + trailing `$max_attempts` opsional):

| Method | Peran |
|---|---|
| `check($key, $max_attempts = 5, $lockout_seconds = 900)` | Read-only. `['allowed' => bool, 'remaining_seconds' => int, 'attempts' => int]`. `allowed=false` hanya saat `locked_until > NOW()`. Lock kedaluwarsa → auto-`clear()` (fresh start). |
| `hit($key, $lockout_seconds = 900, $max_attempts = null)` | **Atomic upsert** `INSERT ... ON DUPLICATE KEY UPDATE` dengan **window decay** (`attempts = IF(last_attempt_at < NOW() - INTERVAL ? SECOND, 1, attempts + 1)`) + `UPDATE ... SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE attempts >= ? AND locked_until IS NULL` (set lock sekali, idempotent). Semua bound params. |
| `clear($key)` | DELETE baris (reset counter + hapus lock). |
| `gc()` / `_maybe_gc()` | GC retensi 30 menit; `_maybe_gc()` probabilistik ~1%. |

Race-safe: tanpa read-modify-write; counter tidak bisa hilang pada hit paralel (kunci UNIQUE + upsert InnoDB atomik).

---

## 4. Instrumentasi — 5 Endpoint

| # | Endpoint | Key | check | hit | clear | UX saat diblokir |
|---|---|---|---|---|---|---|
| 1 | `Auth::login()` | `login:{phone_normalized}:{ip}` | ✅ di awal POST, **sebelum reCAPTCHA** (fail-fast, hemat API Google) | ✅ cabang kredensial salah | ✅ kredensial benar (sebelum cek ban) | inline `$data['errors'][]` + render view |
| 2 | `Auth::register()` | `register:{ip}` | ✅ di awal POST | ✅ **setiap submission** (burst limiter) | — (sengaja tidak clear) | inline `$data['errors'][]` + render view |
| 3 | `Admin_auth::login()` | `admin_login:{username_trim}:{ip}` | ✅ di awal POST, sebelum query DB | ✅ cabang gagal | ✅ sukses | flash `SYSTEM HALTED: <pesan>` + redirect `control-panel` |
| 4 | `Rentals::claim()` | `claim_roi:{user_id}` | ✅ setelah `$user_id` | ✅ setiap percobaan | — | flash error + redirect `rentals` |
| 5 | `Wallet::process_withdraw()` | `withdraw:{user_id}` | ✅ setelah `$user_id`, sebelum Gatekeeper | ✅ setiap submission | — | flash error + redirect `wallet/withdraw` |

**Bonus hardening:** `Rentals::claim()` kini **POST-only** (sebelumnya bisa dieksekusi via GET meski memutasi wallet+rental) — guard `if (!$this->input->post()) redirect('rentals');`; form view sudah `form_open()` (POST), jadi tidak merusak UX.

**Pesan standar web** (helper): `"Terlalu banyak percobaan gagal. Silakan coba lagi dalam X menit."` (`rate_limit_message($remaining_seconds)`, X = `max(1, ceil(detik/60))`).

**AJAX/JSON** (`rate_limit_json_response`, saat `$this->input->is_ajax_request()`): **HTTP 429** + `{ "success": false, "error": "too_many_attempts", "message": "...", "retry_after": <detik> }` — `set_status_header(429)` (global CI3, `system/core/Common.php:485`) + `exit`. Didefinisikan untuk kontrak siap pakai; keempat endpoint saat ini non-AJAX dan tetap dapat diuji via header `X-Requested-With`.

---

## 5. Hasil Verifikasi

### 5.1 Lint (roadmap rule #5) — 6/6 lulus

```bash
$ php -l application/models/Rate_limit_model.php
No syntax errors detected in application/models/Rate_limit_model.php
$ php -l application/helpers/ratelimit_helper.php
No syntax errors detected in application/helpers/ratelimit_helper.php
$ php -l application/controllers/Auth.php
No syntax errors detected in application/controllers/Auth.php
$ php -l application/controllers/Admin_auth.php
No syntax errors detected in application/controllers/Admin_auth.php
$ php -l application/controllers/Rentals.php
No syntax errors detected in application/controllers/Rentals.php
$ php -l application/controllers/Wallet.php
No syntax errors detected in application/controllers/Wallet.php
```

`git diff --check` → clean (tanpa whitespace error). `git status` → hanya 5 file modified + 2 file baru + 1 plan doc, tanpa artefak test.

### 5.2 Batas environment sandbox

- **MySQL server tidak tersedia di environment ini** (socket `localhost` tidak terhubung; `mysql`/`mysqladmin` CLI tidak terpasang) → **tes end-to-end curl + skenario DB (plan §4.4–4.8) TIDAK dapat dijalankan di sini**. Wajib dieksekusi di environment dev Tommy dengan MySQL aktif sebelum merge.
- Verifikasi yang tuntas di sandbox: lint syntax (6/6), review diff menyeluruh, konfirmasi `set_status_header()` global tersedia, konfirmasi form klaim memakai `form_open` (POST).

---

## 6. Testing Instructions (jalankan di environment dengan MySQL aktif)

Setup: `php -S localhost:8080` dari project root (pakai `/index.php/...` bila pretty-URL tidak aktif); pastikan tabel `rate_limits` ada (§2). Seed: 1 user test (`081234567890` + password known) + 1 admin; bersihkan state antar skenario dengan `DELETE FROM rate_limits;`.

### Skenario A — User login brute force (wajib)

```bash
# 1) 5× kredensial salah (phone + IP sama)
for i in 1 2 3 4 5; do
  curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/index.php/login \
       -d "phone=081234567890&password=wrong${i}"
done
# → 200 × 5 (render ulang view error)

# 2) Verifikasi state DB
mysql -u root -p db_webtable -e \
  "SELECT rate_key, attempts, last_attempt_at, locked_until FROM rate_limits WHERE rate_key LIKE 'login:081234567890:%';"
# → attempts = 5, locked_until NOT NULL (NOW() + 15 menit)

# 3) Percobaan ke-6 dengan password BENAR → tetap diblokir
curl -s -X POST http://localhost:8080/index.php/login \
     -d "phone=081234567890&password=<password_benar>" | grep -c "Terlalu banyak percobaan"
# → 1

# 4) Simulasi expiry, lalu retry sukses
mysql -u root -p db_webtable -e \
  "UPDATE rate_limits SET locked_until = NOW() - INTERVAL 1 MINUTE WHERE rate_key LIKE 'login:081234567890:%';"
curl -s -o /dev/null -w "%{http_code}\n" -L -X POST http://localhost:8080/index.php/login \
     -d "phone=081234567890&password=<password_benar>"
# → 302 (login sukses) dan key login terhapus oleh clear()
```

### Skenario B — Admin login

Ulangi pola A di `/index.php/control-panel`: 5× gagal → ke-6 dengan password benar tetap 302 + flash `SYSTEM HALTED: ... menit`; setelah expiry → login sukses. Verifikasi key `admin_login:...` di DB.

### Skenario C — Register burst

```bash
for i in 1 2 3 4 5; do
  curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/index.php/register \
       -d "phone=08123456789${i}&password=password123&invite_code=XXXXXX"
done
# → 200 × 5 (validasi/gagal apapun tetap dihitung)
curl -s -X POST http://localhost:8080/index.php/register \
     -d "phone=081234567890&password=password123&invite_code=XXXXXX" | grep -c "Terlalu banyak percobaan"
# → 1 (ke-6 diblokir; DB: attempts = 5, key register:{ip})
```

### Skenario D — AJAX/JSON 429

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/index.php/login \
     -H "X-Requested-With: XMLHttpRequest" -d "phone=081234567890&password=x"
# → 429 + {"success":false,"error":"too_many_attempts","message":"...","retry_after":<detik>}
```

### Skenario E — GC deterministik

1. `INSERT INTO rate_limits (rate_key, attempts, last_attempt_at) VALUES ('login:stale:1.1.1.1', 3, NOW() - INTERVAL 1 HOUR);`
2. Bootstrap CI3 one-off di `/tmp` (pola `define('BASEPATH'/'APPPATH')` + `require BASEPATH.'core/CodeIgniter.php'`), panggil `get_instance()->Rate_limit_model->gc();`
3. `SELECT COUNT(*) ... WHERE rate_key = 'login:stale:1.1.1.1';` → 0.

---

## 7. Files Touched (Phase 10B)

| File | Action |
|------|--------|
| `plan/18_PHASE_10B_PLAN.md` | **new** — blueprint (APPROVED) |
| `plan/19_PHASE_10B_SUMMARY.md` | **new** — dokumen ini |
| `database.sql` | edit — DDL `rate_limits` (§2) |
| `application/models/Rate_limit_model.php` | **new** — engine rate limiter (§3) |
| `application/helpers/ratelimit_helper.php` | **new** — pesan + response 429 (§4) |
| `application/controllers/Auth.php` | edit — `login()` + `register()` (§4) |
| `application/controllers/Admin_auth.php` | edit — `login()` (§4) |
| `application/controllers/Rentals.php` | edit — `claim()` + POST-only guard (§4) |
| `application/controllers/Wallet.php` | edit — `process_withdraw()` (§4) |

---

## 8. Out of Scope / Ditunda (dari plan §6)

- `proxy_ips` / `X-Forwarded-For` → Phase 10D.
- Sliding-window presisi (per-attempt log) — semantik fixed-window berlabuh `last_attempt_at` cukup untuk spesifikasi.
- Hash `rate_key` (PII at-rest) — varian hardening 10D; plaintext dipilih untuk debuggability + preseden repo (`system_audit_logs.ip_address`).
- OTP rate limiting — belum ada endpoint OTP; model siap dipakai (`check('otp:{phone}:{ip}', ...)`) saat endpoint dibangun.
- CSRF rotation & session timeout → Phase 10C.

---

*Menunggu analisis & konfirmasi user sebelum commit + merge ke `main` (roadmap rule #4/#7). Commit message (bahasa Indonesia, gaya existing): `feat(security): implementasi rate limiting & brute force protection 10B`.*
