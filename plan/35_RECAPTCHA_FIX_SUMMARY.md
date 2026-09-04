# Plan 35 — Execution Summary: reCAPTCHA Transport Fix & Housekeeping

Status: **COMPLETE — verified end-to-end on the live dev stack**
Plan: `plan/34_RECAPTCHA_FIX_PLAN.md` (approved). Related: Phase 10D, Housekeeping C4.

---

## 1. Root cause (recap)

Login always failed at reCAPTCHA because the server-side `siteverify` cURL call died
locally with a TLS CA-bundle error before reaching Google:

```
application/logs/log-2026-09-02.php:435,465
ERROR → reCAPTCHA curl gagal: error adding trust anchors from file:
        /usr/local/etc/openssl@1.1/cert.pem (errno 77)     # CURLE_SSL_CACERT_BADFILE
```

The FlyEnv **static-php 8.1.34** FPM build has a macOS Homebrew CA path baked in as the
libcurl default; that file does not exist on this Linux host. `_verify_recaptcha()` is
fail-closed on transport errors → every login rejected. System CLI curl was unaffected
(it resolves `/etc/ssl/certs/ca-certificates.crt`). The keypair itself was valid
(Google returned `invalid-input-response`, not `invalid-input-secret`).

## 2. Code changes applied

### 2.1 `application/controllers/Auth.php` — CA bundle auto-resolution
- `_verify_recaptcha()` now resolves a CA bundle via the new private
  `_resolve_ca_bundle()` and binds it with `CURLOPT_CAINFO`.
- Resolution priority: `SSL_CA_BUNDLE` env → `ini_get('openssl.cafile')` (if file
  exists) → well-known system paths (`/etc/ssl/certs/ca-certificates.crt`,
  `/etc/pki/tls/certs/ca-bundle.crt`, `/etc/ssl/ca-bundle.pem`,
  `/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem`, `/usr/local/etc/openssl/cert.pem`).
- Strict SSL retained: `CURLOPT_SSL_VERIFYPEER=true`, `CURLOPT_SSL_VERIFYHOST=2`.
- Dev-only bypass unchanged and still gated: `ENVIRONMENT !== 'production'` **and**
  `CURL_SSL_VERIFY_DEV_BYPASS=1`.
- Fail-closed transport handling preserved (curl error → log + `FALSE`).
- On this host the resolver selects `/etc/ssl/certs/ca-certificates.crt`.

### 2.2 `application/controllers/Auth.php` — dynamic site key
- `Auth::login()` and `Auth::register()` load
  `$this->config->item('recaptcha_site_key', 'recaptcha')` and pass it as
  `$data['recaptcha_site_key']` to the views. In `register()` it is set before the
  circuit-breaker early return so every render path has it.

### 2.3 Views — `application/views/auth/login.php` & `register.php`
- `data-sitekey` is now rendered from config:
  `data-sitekey="<?= htmlspecialchars($recaptcha_site_key ?? '') ?>"` (hardcoded key removed).

### 2.4 `index.php` — hardened environment detection
- `ENVIRONMENT` now checks `$_SERVER['CI_ENV']` **and** `getenv('CI_ENV')` before the
  fail-closed fallback to `'production'`. Unset `CI_ENV` still yields `production`.

### 2.5 `.htaccess` — secret scrubbed
- Both `SetEnv RECAPTCHA_SECRET "…"` lines removed (the secret was committed here,
  violating Phase 10D). `SetEnv CI_ENV "development"` retained (Apache dev only).

## 3. Environment wiring (outside the repo — per approval dec-073203177dd29e11)

- Added to FlyEnv FPM pool `/home/tommy/.config/FlyEnv/server/php/81/conf/php-fpm.conf`
  (`[www]`, engine-agnostic — works under Apache and nginx):
  - `env[RECAPTCHA_SECRET]`, `env[RECAPTCHA_SITE_KEY]`, `env[CI_ENV]="development"`
- Reloaded php-fpm master (pid 11013) with `SIGUSR2`:
  `NOTICE: reloading: execvp(...php-fpm...)` — config/env re-read, no downtime event
  observed beyond the reload notice.

## 4. Verification (all passed)

| # | Check | Result |
|---|---|---|
| 1 | `php -l application/controllers/Auth.php` | `No syntax errors detected` |
| 2 | `php -l index.php` | `No syntax errors detected` |
| 3 | `php -l application/views/auth/login.php` | `No syntax errors detected` |
| 4 | `php -l application/views/auth/register.php` | `No syntax errors detected` |
| 5 | Secret scrubbed from repo: `grep -rn "SetEnv RECAPTCHA" .` | only historical `plan/*.md` docs (no code/htaccess) |
| 6 | GET `/login` renders `data-sitekey="6Le3PSgtAAAAAFHpzlaZX-h70_zV1fIyKXR00THy"` (from env) | ✅ |
| 7 | POST `/login` (valid CSRF + junk token) → HTTP 200, view shows `Verifikasi reCAPTCHA gagal` | ✅ expected — Google rejects junk token |
| 8 | Log for the POST (01:31:27): **no** new `reCAPTCHA curl gagal` (errno 77), **no** `secret belum dikonfigurasi` | ✅ transport now reaches Google |
| 9 | `curl-gagal` total unchanged at 2 (both historical 01:16), `secret-missing` unchanged at 1 (01:27 pre-wiring) | ✅ no regression |

End-to-end proof: the request ran the full `_verify_recaptcha()` path through the real
FPM build's cURL (the one that previously died with errno 77); with a valid secret it
reached Google and got the expected server-side rejection of the junk token.

## 5. Acceptance criteria — status

- [x] Login reaches Google's siteverify without local TLS failure (no errno 77).
- [x] Production keeps strict peer+host verification; dev bypass remains explicit & gated.
- [x] Site key rendered from config, not hardcoded.
- [x] No secret in the repository diff; `.htaccess` env lines removed.
- [x] All modified PHP files pass `php -l`.

## 6. Notes / recommendations

- The secret `6Le3PSgtAAAAAL65R6znylzjtBpAp9i8yBi-HW2w` is still visible in **git
  history** (`plan/28_PHASE_10D_PLAN.md`, etc.). Phase 10D already flagged rotation —
  **recommend rotating the keypair in the Google reCAPTCHA console** and updating the
  two `env[]` entries in the FlyEnv pool conf when done. No rotation was performed in
  this task (out of scope per approved plan).
- The FlyEnv pool conf now holds the secret in plaintext **outside the repo** — same
  posture as the old `.htaccess`, minus the repo-leak problem.
- `RECAPTCHA_SITE_KEY` is public data; it now lives in server env per the config
  contract (housekeeping C4).
