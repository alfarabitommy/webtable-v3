# Langkah 0 — Housekeeping, Security Fix & Schema Synchronization

**Project:** Synapse (webtable) · **Baseline:** `main` @ `87ac933` (plus untracked `docs/5_AUDIT_REPORT.md`)
**Mode:** PLAN — nothing below is applied yet. This file is the approved-then-executed blueprint for the pre-Phase-10 cleanup.
**Constraint:** No application logic is changed beyond the two security patches (reCAPTCHA secret extraction, `export_csv` guard). No new features.

---

## Scope Summary

| # | Work item | Severity | Files |
|---|-----------|----------|-------|
| A | Documentation alignment: Phase 9 → ✅ COMPLETED (renamed 9A/9B/9C), 7E audit-log claims corrected | Low | `docs/3_ROADMAP.md` |
| B | Schema synchronization: add 6 production tables missing from `database.sql`; baseline DDL for `system_audit_logs` | Medium | `database.sql` |
| C | Security patch: remove hardcoded reCAPTCHA secret from `Auth.php`, load from environment via new config file | **High** | `application/controllers/Auth.php`, `application/config/recaptcha.php` (new) |
| D | Session guard consistency: `Admin::export_csv()` must check `admin_id` and redirect to `control-panel` | Low | `application/controllers/Admin.php` |

Verification: `php -l` on all modified PHP files, schema diff vs live DB, curl smoke tests, `git diff` review, Indonesian commit.

---

## Phase A — Documentation Alignment (`docs/3_ROADMAP.md`)

### A1. Mark Phase 9 as ✅ COMPLETED (move + rename)

Current state (lines 109–116): Phase 9 sits under `## Upcoming Phases` as `### Phase 9: Advanced Analytics & Reporting (PLANNED)` with stale 9A/9B/9C names.

**Action:**
1. Relocate the whole Phase 9 block from `## Upcoming Phases` to the end of the `## Completed Phases` section (after 8C3, before the `---` separator).
2. Retitle to: `### Phase 9: Advanced Analytics & Reporting ✅ COMPLETED`
3. Rewrite the three items to match the shipped code (verified: `Admin.php`, `Admin_model.php`, `views/admin/dashboard.php`, `views/admin/analytics.php`):

```markdown
### Phase 9: Advanced Analytics & Reporting ✅ COMPLETED
- [x] **9A: Treasury & Chart.js** — Admin Command Center "Treasury Health" panel: total cash-in (SUM `user_rentals.purchase_price`), total user balances (SUM credit − SUM debit on `wallet_ledger`), pending ROI obligation dari rental aktif, circuit breaker `is_registration_open`; Chart.js 4.4.1 revenue chart dengan AJAX refresh via `admin/chart_data`.
- [x] **9B: Analytics & VIP Leaderboard** — Halaman `admin/analytics`: global metrics (`get_global_analytics()`), per-user financial X-ray (`get_user_xray()`), "TOP AFFILIATES — VIP LEADERBOARD" via recursive CTE (`get_leaderboard()`).
- [x] **9C: CSV Export Streaming** — `Admin::export_csv()` native streaming ke `php://output`, UTF-8 BOM untuk Excel, 3 tipe: `ledger` / `rentals` / `withdrawals`.
```

### A2. Correct premature 7E audit-logging claims

Current 7E1/7E2 claim audit-log writes that do not exist anywhere in the code (audit confirmed: zero audit code; audit logging is Phase 10A).

**Action:** remove the `+ audit log (...)` clauses and add a deferral note:

- 7E1 (line 81): `...password_hash(PASSWORD_BCRYPT), insert to users + audit log (action: 'admin_create_user'). Flash success with phone + invite code.` →
  `...password_hash(PASSWORD_BCRYPT), insert ke users. Flash success dengan phone + invite code. *(Audit logging menyusul di Phase 10A.)*`
- 7E2 (line 82): `...update users.password, audit log (action: 'admin_reset_password'). One-time plaintext display...` →
  `...update users.password. One-time plaintext display ke admin di flash message. *(Audit logging menyusul di Phase 10A.)*`

### A3. Verify
- `grep -n "Phase 9" docs/3_ROADMAP.md` → heading moved & `✅ COMPLETED`.
- `grep -n "audit log" docs/3_ROADMAP.md` → only Phase 10A references remain.
- No PHP file touched in this phase.

---

## Phase B — Database Schema Synchronization (`database.sql`)

### B1. Tables to ADD (used by production code, absent from `database.sql`)

| # | Table | Verified usage (code) | FK |
|---|-------|----------------------|----|
| 1 | `wallet_ledger` | `Wallet_model`, `Ledger_model`, `Admin_model`, `Rental_model`, `User_model` | → `users.id` RESTRICT |
| 2 | `deposits` | `Wallet_model::create_deposit/approve_deposit_simulator`, `Admin::approve_deposit`, history queries | → `users.id` RESTRICT |
| 3 | `user_rentals` | `Rental_model` (all), `Admin_model`, `User_model` (CTE queries) | → `users.id` RESTRICT, `gpu_products.id` RESTRICT |
| 4 | `admins` | `Admin_auth::login` (`username`, `password`, `id`) | none (hard-separated) |
| 5 | `user_notifications` | `Notification_model` (all) | → `users.id` CASCADE |
| 6 | `system_settings` *(extra table found during inspection)* | `Admin_model::get_setting/set_setting` — Phase 9A circuit breaker `is_registration_open` | none |

> `system_settings` is NOT in the task's explicit 5-table list, but it **is** used in production (registration circuit breaker), so it is included for the same reason. Note the shape difference: `system_settings` uses `key_value`, while the existing seed's `site_settings` uses `setting_value` — both kept as-is; consolidation is a flagged follow-up (B5).

### B2. DDL to append (code-first, aligned with ERD v5.0)

```sql
-- -----------------------------------------------------
-- Table `wallet_ledger` (immutable append-only ledger)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `wallet_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `transaction_id` VARCHAR(50) NOT NULL,
  `type` ENUM('credit', 'debit') NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_type` (`type`),
  INDEX `idx_created_at` (`created_at`),
  CONSTRAINT `fk_wallet_ledger_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `deposits`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `deposits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `invoice_number` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `status` ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invoice_number` (`invoice_number`),
  INDEX `idx_user_status` (`user_id`, `status`),
  CONSTRAINT `fk_deposits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `user_rentals` (replaces dead `rentals`; ERD §7)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_rentals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `purchase_price` DECIMAL(15,2) NOT NULL,
  `daily_roi` DECIMAL(15,2) NOT NULL,
  `total_days` INT UNSIGNED NOT NULL DEFAULT 0,
  `days_processed` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
  `expired_at` TIMESTAMP NULL DEFAULT NULL,
  `last_claimed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_status` (`user_id`, `status`),
  INDEX `idx_product_id` (`product_id`),
  CONSTRAINT `fk_user_rentals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_user_rentals_product` FOREIGN KEY (`product_id`) REFERENCES `gpu_products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `admins` (hard-separated auth, no FK to users)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `user_notifications` (ERD §5 / roadmap 7D1)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(100) NOT NULL,
  `message` TEXT NOT NULL,
  `type` ENUM('info', 'warning', 'success', 'commission') NOT NULL DEFAULT 'info',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_read` (`user_id`, `is_read`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `system_settings` (key-value; circuit breaker Phase 9A)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key_name` VARCHAR(50) NOT NULL,
  `key_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key_name` (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` (`key_name`, `key_value`) VALUES
('is_registration_open', '1');
```

### B3. Baseline DDL for `system_audit_logs` (Phase 10A — ERD v5.0 §6)

Appended with a `-- Phase 10 baseline` comment. Safe to create now (per audit-report prerequisite #1); no code writes to it until 10A.

```sql
-- -----------------------------------------------------
-- Table `system_audit_logs` — Phase 10 baseline (ERD §6)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED DEFAULT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_admin_id` (`admin_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_action` (`action`),
  INDEX `idx_created_at` (`created_at`),
  CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### B4. Apply to live DB (implementation-time)

`database.sql` is the seed; the live DB gets the same DDL. Because all statements use `CREATE TABLE IF NOT EXISTS`, applying the file to an existing DB is idempotent and non-destructive:

```bash
mysql -u"$DB_USER" -p db_webtable < database.sql
```

Then verify with `SHOW TABLES;` and per-table `SHOW CREATE TABLE`.

### B5. Flagged drift found during inspection (NOT in this step — recommend follow-up)

These are documented so the next phase can decide; none block Langkah 0:

| Finding | Evidence | Recommendation |
|---|---|---|
| `users` table in `database.sql` lacks columns used by prod: `role`, `username`, `is_banned`, `must_change_password`, `is_level_1_claimed`, `last_wage_claimed_at` | `Auth::login`, `Admin_model`, `User_model::claim_level1/claim_wage` | Align `users` DDL in a follow-up commit |
| `withdrawals` table lacks `wd_number` (code uses it) and code ignores `gross/fee/net_amount` | `Wallet_model::create_withdrawal` inserts `wd_number` | Align in follow-up |
| `rentals` table in seed is dead — zero production references | grep: no `get('rentals')`/`from('rentals')` anywhere; ERD §7 | Keep for now (safe); drop in follow-up |
| Duplicate settings tables: `site_settings` (`setting_value`) vs `system_settings` (`key_value`) | `Admin_model` lines 51/62 vs 379/386 | Consolidate in follow-up |
| `Admin_model::get_active_rentals()` selects `product_name` from `user_rentals` (no such column; no join) | `Admin_model.php:568` | Verify against live DB; fix join in 9C/10A follow-up |
| `curl_setopt(CURLOPT_SSL_VERIFYPEER/HOST, false)` in `Auth::_verify_recaptcha()` | `Auth.php:38-39` | Re-enable SSL verification in Phase 10D |

### B6. Verify
- `grep -c "CREATE TABLE IF NOT EXISTS" database.sql` → 15 (8 existing + 7 new: 6 + audit).
- Live-DB diff: `mysql ... -e "SHOW TABLES"` matches the seed's table set (plus no drift on the 7 new ones via `SHOW CREATE TABLE`).

---

## Phase C — Security Patch: reCAPTCHA Secret Extraction

### C1. New config file `application/config/recaptcha.php` (env-driven, no literal secret)

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// reCAPTCHA v2 keys — dimuat dari environment (Roadmap rule #6: no hardcoded credentials).
// Secret WAJIB diisi via env var RECAPTCHA_SECRET; jika kosong, Auth::_verify_recaptcha()
// gagal secara fail-closed (registrasi/login ditolak) dan menulis error log.
$config['recaptcha_secret']   = (string) getenv('RECAPTCHA_SECRET');
$config['recaptcha_site_key'] = (string) getenv('RECAPTCHA_SITE_KEY'); // public key — opsional, views belum menggunakannya
```

### C2. Patch `application/controllers/Auth.php`

1. **Delete** line 6: `private $recaptcha_secret = '<REDACTED_SECRET>';` (literal secret leaves the repo; never copied into any file; nilai asli tidak pernah ditulis ulang di repo).
2. **Constructor**: add config load:
   ```php
   public function __construct() {
       parent::__construct();
       $this->config->load('recaptcha', TRUE);
       $this->load->model('User_model');
   }
   ```
3. **`_verify_recaptcha()`**: read from config + fail-closed:
   ```php
   private function _verify_recaptcha($recaptcha_response) {
       if (empty($recaptcha_response)) {
           return FALSE;
       }

       $secret = (string) $this->config->item('recaptcha_secret', 'recaptcha');
       if ($secret === '') {
           log_message('error', 'reCAPTCHA secret belum dikonfigurasi (env RECAPTCHA_SECRET) — verifikasi ditolak (fail-closed).');
           return FALSE;
       }

       $data = array('secret' => $secret, 'response' => $recaptcha_response);
       // ... curl siteverify tetap seperti sekarang ...
   }
   ```
   Register (`register()`, line 68) and login (`login()`, line 126) call sites stay untouched — behavior identical once `RECAPTCHA_SECRET` is set.

### C3. Deployment requirement (do not break existing verification)

Set the env var **in the same release window** as this patch:

- **Apache:** `SetEnv RECAPTCHA_SECRET "..."` in the vhost (or `.htaccess` if `AllowOverride Options`).
- **nginx (PHP-FPM):** `fastcgi_param RECAPTCHA_SECRET "...";`
- **CLI / cron:** `export RECAPTCHA_SECRET="..."` before cron jobs, or via systemd `Environment=`.
- **Local dev (php -S):** `RECAPTCHA_SECRET="..." php -S localhost:8080`.

If the operator cannot set env vars (e.g., shared hosting), the fallback is a minimal dotenv loader in `index.php` — a follow-up option, not part of this patch (documented, not implemented).

### C4. Optional (default: deferred)
- Move the **site key** out of `views/auth/login.php` + `views/auth/register.php` (currently hardcoded, e.g. `data-sitekey="6Le3PSgtAAAAAFHpzlaZX-h70_zV1fIyKXR00THy"`) into `recaptcha.php` and render via `$data`. Site key is public, so this is cosmetic single-source-of-truth only — deferred to avoid touching views in Langkah 0.

### C5. Verify
- `php -l application/controllers/Auth.php application/config/recaptcha.php`
- `grep -rn "6Le3PSgt" application/` → only the two view site-key occurrences remain (public key), none in controllers.
- With `RECAPTCHA_SECRET` set: `curl -s -o /dev/null -w "%{http_code}" http://synapse.test/register` → 200; a real reCAPTCHA token still passes.
- Without it: `_verify_recaptcha()` returns FALSE → error log entry, register/login blocked (fail-closed).

---

## Phase D — Session Guard Consistency (`Admin::export_csv()`)

### D1. Patch `application/controllers/Admin.php` (lines 752–754)

```php
// BEFORE
if (!$this->session->userdata('admin_logged_in')) {
    redirect('admin_auth');
}

// AFTER — aligned with core dual-auth guard (constructor, Admin_auth session key)
if (!$this->session->userdata('admin_id')) {
    redirect('control-panel');
}
```

Notes:
- `admin_logged_in` is never set anywhere in the codebase (real key: `admin_id`) and `admin_auth` is not a defined route (falls through to `Admin_auth::index()` → 404). Harmless today only because the constructor guard (lines 14–16) runs first — this makes the method self-consistent and defense-in-depth.
- No other changes to `export_csv()` (CSV logic untouched).

### D2. Verify
- `php -l application/controllers/Admin.php`
- Unauthenticated `curl -s -o /dev/null -w "%{http_code}" http://synapse.test/admin/export_csv/ledger` → 302 with `Location: .../control-panel`.
- Authenticated admin session → 200, `Content-Type: text/csv`, UTF-8 BOM present.

---

## Phase E — Final Verification & Commit

1. **Lint all touched PHP:** `php -l application/controllers/Auth.php application/controllers/Admin.php application/config/recaptcha.php`
2. **Schema diff check:** `git diff --stat database.sql` + live-DB `SHOW TABLES` / `SHOW CREATE TABLE` comparison for the 7 new tables (idempotent `CREATE TABLE IF NOT EXISTS` apply).
3. **Smoke tests (roadmap rule #3):** `/` 200, `/login` 200, `/register` 200, `/control-panel` 200, `/admin/export_csv/ledger` 302→`control-panel` when logged out.
4. **Secret hygiene (roadmap rule #6):** `git diff` must show the literal reCAPTCHA secret removed and never re-added; `grep -rn "<REDACTED_SECRET>"` (ganti placeholder dengan literal asli saat menjalankan) → no matches.
5. **Branch + commit (roadmap rule #4, AGENTS.md):** work on `fase-0-housekeeping`, commit in Indonesian, e.g. `Langkah 0: sinkronisasi skema DB, ekstraksi secret reCAPTCHA ke env, perbaiki guard export_csv`.
6. Merge to `main` only after user confirmation and all checks green.

---

## Files Touched (full list)

| File | Action |
|------|--------|
| `plan/0_HOUSEKEEPING_PLAN.md` | **new** — this blueprint |
| `docs/3_ROADMAP.md` | edit — Phase 9 ✅ COMPLETED + renamed items; 7E1/7E2 audit claims corrected |
| `database.sql` | edit — append 7 tables (6 missing + `system_audit_logs` baseline) |
| `application/config/recaptcha.php` | **new** — env-driven reCAPTCHA keys (no literals) |
| `application/controllers/Auth.php` | edit — remove hardcoded secret; load config; fail-closed verify |
| `application/controllers/Admin.php` | edit — `export_csv()` guard: `admin_id` → `control-panel` |

## Out of Scope (documented, follow-up)

B5 drift items, PRD §9 spec addition, `admin/audit` 404 (Phase 10A/10B), CSRF/session hardening (10B–10D), SSL-verify re-enable, site-key view move (C4).

**STRICT RULE:** Nothing in Phases A–E is executed until the user approves this plan.
