# Phase C — Execution Summary (Security Patch: reCAPTCHA Secret Extraction)

**Project:** Synapse (webtable) · **Baseline:** `main` @ `87ac933`
**Plan:** `plan/4_PHASE_C_PLAN.md` (approved) · **Status:** ✅ COMPLETED & verified (9 security reviews PASS)

---

## What was done

### 1. `application/config/recaptcha.php` (new, 19 lines)

Env-driven reCAPTCHA v2 keys — **zero literal keys** (Roadmap Rule #6):

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// reCAPTCHA v2 — konfigurasi berbasis environment (Roadmap Rule #6).
$config['recaptcha_secret']   = (string) getenv('RECAPTCHA_SECRET');
$config['recaptcha_site_key'] = (string) getenv('RECAPTCHA_SITE_KEY');
```

- `(string) getenv(...)` menjamin nilai `''` saat env unset → kondisi fail-closed terdeteksi deterministik.
- Di-load dengan index: `$this->config->load('recaptcha', TRUE)` → diakses via `$this->config->item('recaptcha_secret', 'recaptcha')`.

### 2. `application/controllers/Auth.php` (edit, 2 hunks / +13 −1 net)

| Perubahan | Detail |
|---|---|
| Hapus literal secret | `private $recaptcha_secret = '<REDACTED_SECRET>';` dihapus dari repo (tidak disalin ke file mana pun) |
| Constructor | `$this->config->load('recaptcha', TRUE);` ditambahkan **sebelum** `$this->load->model('User_model');` |
| `_verify_recaptcha()` | Baca `(string) $this->config->item('recaptcha_secret', 'recaptcha')`; jika kosong → `log_message('error', '...fail-closed.')` + `return FALSE` **sebelum** `curl_init()` |

Fail-closed berlaku di semua jalur: response kosong → `FALSE`; secret kosong/unset → `FALSE` + error log, tanpa curl; config file gagal load (`item()` → `NULL` → `''`) → tetap `FALSE`; curl gagal → `json_decode` null → `FALSE`. Call site `register()`/`login()` tidak diubah — perilaku identik saat `RECAPTCHA_SECRET` ter-set.

---

## Verification results

### `php -l` (kedua file)

```
$ php -l application/config/recaptcha.php
No syntax errors detected in application/config/recaptcha.php
$ php -l application/controllers/Auth.php
No syntax errors detected in application/controllers/Auth.php
```

### Hygiene grep — literal secret (PRIVATE) **0 match**

```
$ grep -rn "<REDACTED_SECRET>" application/   # dijalankan operator dgn literal asli
CLEAN: 0 matches

$ grep -n "recaptcha_secret" application/controllers/Auth.php
32:        $secret = (string) $this->config->item('recaptcha_secret', 'recaptcha');
```

- Literal secret (dan prefix uniknya) **0 match tree-wide** (termasuk `plan/`, `docs/`).
- Site key (PUBLIC) tetap ada di `views/auth/login.php:66` & `register.php:74` — diperbolehkan, deferral C4.
- Plan docs memakai placeholder `<REDACTED_SECRET>` (0_HOUSEKEEPING_PLAN.md:250,332; 4_PHASE_C_PLAN.md:64,197) — literal tidak pernah ditulis ulang di repo.

### `git diff --check` (verifier terakui host) — OK, tanpa whitespace error / conflict marker

### Behavioral probe (config + guard)

```
CASE 1 (env unset): secret=''          → FAIL-CLOSED (log_message + return FALSE, tanpa curl) — GOOD
CASE 2 (env set):   secret='sk_test_…' → ALLOW → curl https://www.google.com/recaptcha/api/siteverify — GOOD
RESULT: PASS
```

### Security review — **PASS, no blocking issues** (9 review, semua PASS)

- Fail-closed ordering sesuai spesifikasi; semantik CI3 `load(..., TRUE)` + `item(..., 'recaptcha')` benar.
- Tanpa regresi: diff hanya 2 hunk (hapus property + load config + guard); call site login/register tak tersentuh.
- Temuan non-blocking (semua pre-existing/eksternal): `CURLOPT_SSL_VERIFYPEER/HOST=false` (Auth.php:45-46, Phase 10D), `seeder_admin()` (Auth.php:179), rotasi key (secret ada di git history commit `73f04b8`).

---

## Working tree state (ready for Phase D)

```
 M application/controllers/Auth.php   ← Phase C (this step)
?? application/config/recaptcha.php   ← Phase C (this step, new)
 M database.sql                       ← Phase B (prior, uncommitted)
 M docs/3_ROADMAP.md                  ← Phase A (prior, uncommitted)
 M reasonix.toml                      ← infra/host tooling (bukan perubahan aplikasi)
?? docs/5_AUDIT_REPORT.md             ← untracked (expected)
?? plan/                              ← untracked (expected)
```

---

## Assumptions & limitations (dilaporkan selama eksekusi)

1. **E2E browser tidak bisa dijalankan** di sandbox ini — tidak ada MySQL dan tidak ada jaringan; CI3 mati di koneksi DB (Database Error page). Verifikasi fail-closed dilakukan deterministik via probe terpisah (PASS).
2. **`php -l` / grep bukan closed-loop verifier yang diakui host**; `git diff --check` (terakui) lulus; `php -l`/grep dicatat sebagai manual.
3. **`log_threshold = 0`** (pre-existing, `config.php:228`) → `log_message('error', ...)` tidak tertulis ke file log; kode benar, visibility butuh `log_threshold >= 1` (keputusan deployment).
4. **Belum ada commit** — menunggu konfirmasi user (Phase E housekeeping), pesan Bahasa Indonesia mis. `Langkah 0: ekstraksi secret reCAPTCHA ke env (recaptcha.php) dan fail-closed di Auth`.
5. **Rotasi key WAJIB dilakukan operator** di Google reCAPTCHA console — secret lama ada di git history (`73f04b8`), ekstraksi working-tree tidak meng-invalidate-nya.

## Deferred follow-ups (out of scope, unchanged)

- Site key di views → pindah ke config (C4 `0_HOUSEKEEPING_PLAN.md`)
- Re-enable `CURLOPT_SSL_VERIFYPEER/HOST` (Phase 10D)
- Dotenv loader fallback untuk shared hosting (§3.5 `4_PHASE_C_PLAN.md`)
- `.gitignore` entry untuk `.htaccess` lokal yang memuat secret

---

## Next step

**Phase D — Session Guard Consistency** (`Admin::export_csv()`: guard `admin_id` → redirect `control-panel`), per `plan/0_HOUSEKEEPING_PLAN.md`.
