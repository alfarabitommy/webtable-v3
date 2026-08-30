# AGENTS.md — Synapse (webtable)

**Synapse** — Indonesian, mobile-first AI GPU rental / GPUaaS investment platform: rent GPU nodes for daily ROI, double-entry wallet ledger, multi-level agency/affiliate system, admin command center.

## Project
- Stack: CodeIgniter 3 (PHP 8.x), MySQL (`db_webtable`), Vanilla JS, Tailwind CSS + Font Awesome via CDN. No Composer runtime deps; no build step.
- Entry point: `index.php` (CI3 front controller). Clean URLs (`index_page=''`) via `application/config/routes.php`; Apache rewrite in `.htaccess`, nginx in `nginx-rewrite.txt`. `base_url` = `http://synapse.test/`.
- UI language is Indonesian; **all money is IDR only** (no foreign currencies at any layer).
- Business spec is authoritative in `docs/`: `1_PRD.md` (v5.0 — exact business logic), `2_ERD.md`, `3_ROADMAP.md` (v6.0 — strict agent rules), `4_UI_UX_GUIDELINES.md`.

## Commands
- Lint every new/modified PHP file (roadmap rule): `php -l <file>`
- No app test suite. Verify flows via browser/`curl` (HTTP 200 / 302 expected).
- Local dev: `php -S localhost:8080` from project root (pretty URLs need the rewrite config; default config expects `synapse.test`).
- DB credentials in `application/config/database.php`; schema seed in `database.sql` (`users`, `gpu_products`, `rentals`, `transactions`, `bank_accounts`, `withdrawals`, `otp_logs`, `site_settings` — code also uses `wallet_ledger`, `deposits`, `admins`, `user_rentals`, notifications).

## Architecture
- `application/core/MY_Controller.php` — base for user controllers: guards `user_id` session (redirect `login`), injects `global_balance`, `global_unread_count`, `global_notifications` into all views.
- Controllers: `Auth` (register/login/logout, reCAPTCHA v2, phone normalizer), `Home`, `Rentals`, `Wallet` (deposit/withdraw/bind bank), `Marketplace`, `Team`, `Profile`, `Help`, `Notification` (AJAX), `User`; **admin** = `Admin` + `Admin_auth` with cloaked login at `/control-panel` (route `'control-panel' => 'Admin_auth/login'`), guarded by `admin_id` session.
- Models: `User_model`, `Product_model`, `Rental_model`, `Wallet_model`, `Ledger_model`, `Admin_model`, `Notification_model`. **All DB access lives in models** — no SQL in controllers/views.
- Ledger: `Ledger_model` wraps money moves in ACID transactions (`trans_begin/commit/rollback`, `SELECT ... FOR UPDATE` on `users`); wallet balance = `SUM(credit) − SUM(debit)` on `wallet_ledger` (`Wallet_model::get_balance`).
- Views: user pages = `templates/header.php` + `templates/bottom_nav.php`; admin = `admin/templates/*`. Mobile-first `max-w-[480px]` shell, Tailwind utilities.

## Conventions
- Every PHP file: `defined('BASEPATH') OR exit('No direct script access allowed');`
- SQL only in models, with bound params (`$this->db->query("... ?", [$id])`); query builder OK (see `Admin`).
- Dual-auth hard separation: `user_id` vs `admin_id` sessions — never shared; admin routes redirect to `control-panel` when `admin_id` missing.
- Phone normalization (backend is source of truth): strip non-digits, `62`/`0062` → `0`, final `/^0[0-9]{9,13}$/` (implemented as `_normalize_phone()` in `Auth` and `Admin`).
- Money display: PHP `number_format($val, 0, ',', '.')` with `Rp ` prefix; JS `Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })`.
- Business state machines (deposit `pending→success`, single pending WD, WD window Mon–Sat 07:00–19:00, fee tiers) are in `docs/1_PRD.md` — read it before touching business logic.
- No hardcoded credentials/keys in repo (roadmap rule; use env vars). ⚠️ `application/controllers/Auth.php` currently holds a hardcoded reCAPTCHA secret — flag for cleanup, never copy it.
- Work on per-phase branches per `docs/3_ROADMAP.md`; commit messages in Indonesian (existing style).
- Register pretty URLs in `application/config/routes.php` (add a `$route[...]`), not as controller method URLs.

## Notes
<!-- quick-add notes -->
