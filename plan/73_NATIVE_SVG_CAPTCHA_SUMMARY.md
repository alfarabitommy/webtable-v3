# Plan 73 — Execution Summary: Native SVG CAPTCHA & Google CAPTCHA Purge

Plan: `plan/72_NATIVE_SVG_CAPTCHA_AND_RECAPTCHA_PURGE_PLAN.md` (approved). Scope: M8 — remove Google-hosted CAPTCHA from Login/Register, ship a zero-dependency native inline-SVG CAPTCHA engine.

---

## 1. Files Created / Modified / Deleted

| File | Action | Purpose |
|------|--------|---------|
| `application/helpers/captcha_helper.php` | **created** | Pure SVG renderer: `captcha_alphabet()` (56 unambiguous glyphs — `0,O,o,1,I,l` excluded) + `build_captcha_svg($code)` (transparent 150×50 SVG, per-char ±22° rotation + position jitter, noise lines 4–5 + dots 18–24, theme-neutral indigo `#6366f1` / cyan `#06b6d4` / violet `#8b5cf6`, CSPRNG `random_int`) |
| `application/controllers/Auth.php` | **modified** | Constructor: `config->load('recaptcha')` → `helper('captcha')`. Deleted `_verify_recaptcha()` + `_resolve_ca_bundles()` (~130 lines of cURL/CA logic). Added lifecycle: `const CAPTCHA_TTL_SECONDS = 180`, `_issue_captcha()`, `_verify_captcha()` (session `auth_captcha`, **strict single-use flush on every evaluation**, TTL check), `_render_auth_view()` (fresh challenge per render), public `refresh_captcha()` JSON endpoint |
| `application/config/recaptcha.php` | **deleted** (`git rm`) | Env config `RECAPTCHA_SECRET` / `RECAPTCHA_SITE_KEY` retired — no CAPTCHA keys exist anymore |
| `application/views/auth/login.php` | **modified** | Removed `api.js` script + `.g-recaptcha` container; added native "Kode Keamanan" component (input `maxlength=5` uppercase, `#captcha-box` w/ `$captcha_svg`, `#captcha-refresh` button w/ inline SVG icon) + vanilla fetch refresh JS |
| `application/views/auth/register.php` | **modified** | Same three changes as login (label carries required `*`) |
| `AGENTS.md` | **modified** | Controller description, secrets/env-rule line, and new M8 quick-add note — external CAPTCHA fully gone |
| `docs/1_PRD.md` | **modified** | §Bot Protection bullets (§58–62) + §6 Security line rewritten to the native SVG CAPTCHA spec |
| `docs/3_ROADMAP.md` | **modified** | Added completed "M8: Native SVG CAPTCHA & External CAPTCHA Purge" milestone entry (M8A/M8B/M8C); historical 1C text untouched |
| `plan/72_…PURGE_PLAN.md` | (previous step) | Blueprint |

Pre-existing dirty files left untouched: `.gitignore`, `index.php`, untracked `application/config/config.php` + `database.php` (local env), `plan/72`.

## 2. Integration Behavior (preserved semantics)

- **Login** (`Auth::login`): throttle fail-fast (`login:{phone}:{ip}`, 10B) → `_verify_captcha()` gate → credential check / ban lockout / session / forced-password redirect — unchanged ordering.
- **Register** (`Auth::register`): circuit-breaker → rate-limit `check`+`hit` (`register:{ip}`) → `_verify_captcha()` gate → **M5 phone normalization** → `is_unique` → invite lookup → `create_user` (uk_phone 1062 fallback) — unchanged ordering.
- Gate failure (wrong/empty/expired/flushed) → error `Kode keamanan salah atau sudah kedaluwarsa.` + re-render issuing a **fresh** challenge (`_render_auth_view()`).
- Matching: `strtolower(trim($input)) === strtolower($stored)`; TTL 180 s; single-use flush happens even on failed attempts (anti-replay).
- Refresh: `GET auth/refresh_captcha` (AJAX-only via `X-Requested-With`), JSON `{svg, expires_in:180}` (JSON_HEX_* hardened). No `routes.php` entry needed (direct CI3 method URL). CSRF: forms unchanged (`form_open`); GET refresh rotates only the caller's own session data.
- Views render `$captcha_svg` on every GET and failed-POST path (no more `recaptcha_site_key`).

## 3. Verification Results

| Check | Command | Result |
|-------|---------|--------|
| Lint helper | `php -l application/helpers/captcha_helper.php` | ✅ No syntax errors |
| Lint controller | `php -l application/controllers/Auth.php` | ✅ No syntax errors |
| Lint login view | `php -l application/views/auth/login.php` | ✅ No syntax errors |
| Lint register view | `php -l application/views/auth/register.php` | ✅ No syntax errors |
| Purge grep (active code + living docs, logs excluded) | `grep -rniE "recaptcha\|g-recaptcha\|siteverify" application --exclude-dir=logs .htaccess index.php AGENTS.md docs/1_PRD.md` | ✅ **zero matches** (exit 1) |
| `recaptcha.php` removed | `git status` | ✅ `D application/config/recaptcha.php` |
| Helper smoke (2 runs) | php snippet in /tmp: alphabet length 56, no ambiguous glyphs, per run 5 `<text>`, 4–5 `<line>`, 18–24 `<circle>`, output varies | ✅ deterministic structure, randomized visuals |

Remaining manual/browser protocol (plan/72 §8.2, T1–T10 — full login/register round-trips, expired-session simulation, theme toggle, refresh-cycle click): not run here (no app test suite / DB-backed runtime in this environment).

## 4. Notes & Follow-ups

- Remaining references to the old service live **only** in history: `application/logs/*`, `docs/5_AUDIT_REPORT.md`, and `plan/34/35/40/41/5/…` write-ups — intentionally untouched.
- Server env vars `RECAPTCHA_SECRET`/`RECAPTCHA_SITE_KEY` (if still set on the deployment) are now dead weight; removal is recommended but optional.
- Root `captcha/` (empty, world-writable, legacy GD-captcha era) remains out of scope — optional later cleanup.
- Commit (pending user go-ahead, Indonesian style): `M8: purge Google CAPTCHA & implement native SVG captcha (login/register)`.
