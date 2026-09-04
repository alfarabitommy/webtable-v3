# Plan 34 — Fix: Login Always Fails at reCAPTCHA Verification

Status: **DIAGNOSIS COMPLETE — awaiting approval before code changes**
Related: Phase 10D (env-driven secrets), Housekeeping C4 (site key from config).

---

## 1. Symptom

Every login POST at `/login` is rejected with
`Verifikasi reCAPTCHA gagal. Silakan centang kotak 'Saya bukan robot'.`
The widget renders client-side, but the server-side `siteverify` call never succeeds.

## 2. Root Cause (verified, transport-level)

`Auth::_verify_recaptcha()` (`application/controllers/Auth.php:27-85`) performs a
strict-SSL cURL POST to `https://www.google.com/recaptcha/api/siteverify`. Under the
FlyEnv dev stack (**nginx → php-fpm, static-php 8.1.34**), that call aborts locally
before reaching Google:

```
application/logs/log-2026-09-02.php:435 (and 465)
ERROR - 2026-09-02 01:16:24 --> reCAPTCHA curl gagal: error adding trust anchors
        from file: /usr/local/etc/openssl@1.1/cert.pem (errno 77)
```

- `errno 77` = `CURLE_SSL_CACERT_BADFILE`. The FlyEnv static-php build's libcurl has
  a **macOS Homebrew CA path baked in** (`/usr/local/etc/openssl@1.1/cert.pem`) that
  **does not exist on this Linux host**. `CURLOPT_SSL_VERIFYPEER=true` therefore fails
  immediately with "error adding trust anchors from file".
- The handler is **fail-closed by design** (`Auth.php:74-80`): any curl transport error
  logs and returns `FALSE` → login rejected. Correct behavior, wrong trigger.
- **System CLI curl works** (errno 0, real Google response) because it resolves the
  system bundle `/etc/ssl/certs/ca-certificates.crt` (exists) — confirming the failure
  is specific to the FPM build's default CA path, not the network or Google.

Why the existing dev bypass is inert:

- The site is served by **nginx** (FlyEnv vhost `1788067981989.conf`, root
  `/home/tommy/dev/webtable`). **nginx ignores `.htaccess`**, so
  `SetEnv CI_ENV "development"` (`.htaccess:6`) never applies → `index.php:64`
  falls back to `ENVIRONMENT = 'production'`.
- The bypass additionally requires `CURL_SSL_VERIFY_DEV_BYPASS=1`, which is unset.

Keypair is **NOT** the cause:

- Google accepts the secret: `siteverify` with the committed secret and a junk token
  returns `{"success": false, "error-codes": ["invalid-input-response"]}` — i.e.
  **not** `invalid-input-secret`/`invalid-keypair`. Site key
  (`6Le3PSgtAAAAAFHpzlaZX-h70_zV1fIyKXR00THy`) and secret
  (`6Le3PSgtAAAAAL65R6znylzjtBpAp9i8yBi-HW2w`) share the same account prefix and pair.

### Inspection checklist results

| Check | Result |
|---|---|
| `api.js` in view `<head>` | ✅ `login.php:22`, `register.php:22` (`async defer`) |
| `.g-recaptcha` div in form, valid site key | ✅ `login.php:132`, `register.php:133` — but key is **hardcoded** (see §4C) |
| `_verify_recaptcha()` secret source | ✅ `$this->config->item('recaptcha_secret', 'recaptcha')` ← `getenv('RECAPTCHA_SECRET')` (`config/recaptcha.php`); fail-closed when empty |
| Logs | 🎯 `log-2026-09-02.php` lines 435/465: `errno 77` CA-bundle failure on both login POSTs (CSRF valid, correct code path) |
| cURL SSL | 🎯 `VERIFYPEER=true` + `VERIFYHOST=2`; CAINFO only from `SSL_CA_BUNDLE` env (unset) → FPM's broken default CA path used |

## 3. Secondary findings (same area, fix alongside)

- **Site key hardcoded in views** (`login.php:132`, `register.php:133`); config
  `recaptcha_site_key` (env `RECAPTCHA_SITE_KEY`) is defined but unused — the C4
  housekeeping gap. Rotation of the key would silently break the widget.
- **Secret committed in `.htaccess`** (lines 3 and 7: `SetEnv RECAPTCHA_SECRET "…"`),
  including a header comment claiming it is not committed. Direct violation of the
  Phase 10D rule ("Never re-commit secrets") and AGENTS.md. Under nginx it is also a
  no-op — the secret actually reaches the app via the FPM process environment, not
  `.htaccess`. Must be removed from the repo and kept only in server env.

## 4. Fix Plan (pending approval)

### A. Primary fix — robust CA bundle resolution in `Auth::_verify_recaptcha()` (`Auth.php:40-71`)

1. Keep the production posture: `CURLOPT_SSL_VERIFYPEER=true`,
   `CURLOPT_SSL_VERIFYHOST=2` remain the baseline everywhere.
2. Resolve a CA bundle **before** the curl call, first hit wins:
   - `SSL_CA_BUNDLE` env (existing explicit override — unchanged);
   - `ini_get('openssl.cafile')` if non-empty and the file exists (covers standard builds);
   - well-known system paths if they exist:
     `/etc/ssl/certs/ca-certificates.crt`,
     `/etc/pki/tls/certs/ca-bundle.crt`,
     `/etc/ssl/ca-bundle.pem`,
     `/usr/local/etc/openssl/cert.pem`,
     `/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem`.
   - Hit → `CURLOPT_CAINFO` (on this host: `/etc/ssl/certs/ca-certificates.crt`).
3. No bundle found:
   - **production** → fail-closed (unchanged) with a precise log message;
   - **non-production + `CURL_SSL_VERIFY_DEV_BYPASS=1`** → existing dev bypass;
   - **non-production, no flag** → fail-closed, but log the detected system CA
     candidates and the remedy (`SSL_CA_BUNDLE` or the dev bypass flag).

### B. Dev environment plumbing (so `ENVIRONMENT`/flags are real)

- Set `CI_ENV=development` and `RECAPTCHA_SITE_KEY` in the FlyEnv server env
  (nginx `fastcgi_param` or FPM pool `env[]` — **outside the repo**), since
  `.htaccess` is ignored by nginx. Optionally harden `index.php:64` to also fall back
  to `getenv('CI_ENV')` so `php -S`/CLI devs get the same behavior.

### C. De-hardcode the site key (housekeeping C4)

- Controllers (`Auth::login`, `Auth::register`) pass
  `recaptcha_site_key` (`$this->config->item('recaptcha_site_key', 'recaptcha')`) into
  the views; views render `data-sitekey="<?= htmlspecialchars($recaptcha_site_key) ?>"`.
- Requires `RECAPTCHA_SITE_KEY` in server env (part of B).

### D. Security cleanup

- Remove `SetEnv RECAPTCHA_SECRET "…"` from `.htaccess` (both lines); keep the secret
  only in server env / a git-ignored local file. Nothing in this plan changes the
  secret value or the keypair.

### E. Testing / verification steps

1. **Pre-fix reproduction (done):** system CLI `siteverify` POST → errno 0; FPM app log
   → errno 77 (lines 435/465 of `log-2026-09-02.php`).
2. **Transport smoke test:** with CAINFO pointed at `/etc/ssl/certs/ca-certificates.crt`,
   `siteverify` returns `invalid-input-response` (errno 0) — proves TLS works.
3. **End-to-end:** browser login with a real widget token → expect `302` to `home` and
   **no** `errno 77` in `application/logs/log-<date>.php`; repeat for `register`.
4. **Dev bypass test:** `CURL_SSL_VERIFY_DEV_BYPASS=1` + `CI_ENV=development` in the
   FPM env → login succeeds even if the CA bundle were missing.
5. **Production-guard test:** simulate missing CA with `ENVIRONMENT=production` →
   fail-closed, error logged (never bypassed).
6. **Regression:** `php -l` on every modified PHP file; confirm no committed secret in
   the diff (grep `RECAPTCHA_SECRET`).

## 5. Acceptance criteria

- [ ] Login succeeds end-to-end on the FlyEnv stack (no errno 77 in logs).
- [ ] Production keeps strict peer+host verification; dev bypass remains explicit.
- [ ] Site key rendered from config, not hardcoded.
- [ ] No secret in the repository diff; `.htaccess` env lines removed.
- [ ] All modified PHP files pass `php -l`.
