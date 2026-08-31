# Phase B — Database Schema Synchronization (`database.sql` + live `db_webtable`)

**Project:** Synapse (webtable) · **Baseline:** `main` @ `87ac933`
**Mode:** PLAN — nothing below is applied yet. Wait for approval before editing `database.sql` or executing anything against the live DB.
**Source:** `plan/0_HOUSEKEEPING_PLAN.md` Phase B (STEP 0).

---

## Context (verified during inspection)

- **Current `database.sql`:** 8 `CREATE TABLE IF NOT EXISTS` statements (`users`, `gpu_products`, `rentals`, `transactions`, `bank_accounts`, `withdrawals`, `otp_logs`, `site_settings`). Target after Phase B: **15**.
- **Code usage of the 7 target tables verified line-by-line** in `Wallet_model`, `Ledger_model`, `Rental_model`, `Notification_model`, `Admin_model`, `Admin_auth`, `Admin`, `User_model` — every column in the DDL below is actually read/written by production code, and matches ERD v5.0 §3/§5/§6/§7.
- **Environment note:** `mysql` CLI is **not installed** in this workspace (only `php`). Live-DB steps (Phase 3) are executed by the operator on the DB host, or via a CI3/PHP runner — see Phase 3.

### Tables to add

| # | Table | Verified usage (code) | FK |
|---|-------|----------------------|----|
| 1 | `wallet_ledger` | `Wallet_model`, `Ledger_model`, `Admin_model`, `Rental_model`, `User_model` | → `users.id` RESTRICT |
| 2 | `deposits` | `Wallet_model`, `Admin::approve_deposit`, history queries | → `users.id` RESTRICT |
| 3 | `user_rentals` | `Rental_model` (all), `Admin_model`, `User_model` (CTE queries) | → `users.id` RESTRICT, `gpu_products.id` RESTRICT |
| 4 | `admins` | `Admin_auth::login` (`username`, `password`, `id`) | none (hard-separated) |
| 5 | `user_notifications` | `Notification_model` (all) | → `users.id` CASCADE |
| 6 | `system_settings` | `Admin_model::get_setting/set_setting` — Phase 9A circuit breaker `is_registration_open` | none |
| 7 | `system_audit_logs` | Phase 10 baseline (ERD §6); zero code writes until 10A | `admins.id` SET NULL, `users.id` SET NULL |

---

## Phase 1 — Freeze & preview the 7-table DDL (append block)

This is the exact block to append to `database.sql` (after the final `SET FOREIGN_KEY_CHECKS = 1;`).

**One deviation from the plan file:** the `system_settings` seed becomes `INSERT IGNORE` so re-running the file on a live DB never fails on the `uk_key_name` unique key and never clobbers a live `is_registration_open` value.

All statements: `CREATE TABLE IF NOT EXISTS`, InnoDB, `utf8mb4_unicode_ci`, FK rules per spec. Dependency order preserved (referenced tables `users`/`gpu_products` exist earlier in the file; `admins` is created before `system_audit_logs`).

```sql
-- -----------------------------------------------------
-- PHASE B (Langkah 0): production tables + Phase 10 baseline
-- -----------------------------------------------------

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
-- Table `deposits` (transaction invoices)
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
-- Table `user_rentals` (active/expired GPU leases)
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
-- Table `admins` (isolated admin auth — no FK to users)
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
-- Table `user_notifications` (in-app notifications)
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

-- Seed is idempotent: never fails on duplicate key, never overwrites a live value.
INSERT IGNORE INTO `system_settings` (`key_name`, `key_value`) VALUES
('is_registration_open', '1');

-- -----------------------------------------------------
-- Table `system_audit_logs` — Phase 10 baseline (ERD §6)
-- No code writes to this until Phase 10A; created now per audit-report prerequisite.
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

SET FOREIGN_KEY_CHECKS = 1;
```

### DDL verification summary (code → DDL)

| Table | Code-confirmed columns | FK rule |
|---|---|---|
| `wallet_ledger` | `user_id, transaction_id, type(credit/debit), amount, description, created_at` (inserts in `Wallet_model`, `Rental_model`, `Admin_model`) | → `users.id` RESTRICT |
| `deposits` | `user_id, invoice_number, amount, status(pending/success/failed), created_at, updated_at` (`Admin::approve_deposit` by `id`+`pending`) | → `users.id` RESTRICT |
| `user_rentals` | `user_id, product_id, purchase_price, daily_roi, total_days, days_processed, status, expired_at, last_claimed_at, created_at` (all `Rental_model`/`Admin_model` ops) | → `users.id` RESTRICT, `gpu_products.id` RESTRICT |
| `admins` | `id, username, password, created_at, updated_at` (`Admin_auth::login`) | none (hard-separated) |
| `user_notifications` | `user_id, title, message, type, is_read, created_at` (`Notification_model`) | → `users.id` CASCADE |
| `system_settings` | `key_name, key_value, updated_at` (`Admin_model::get_setting/set_setting`) | none |
| `system_audit_logs` | ERD §6 Phase 10 baseline; zero code writes yet | `admins.id` SET NULL, `users.id` SET NULL |

---

## Phase 2 — Apply to `database.sql` (implementation, after approval)

- Append the block above after the final `SET FOREIGN_KEY_CHECKS = 1;`, under a `-- PHASE B` header comment. No existing statement is modified.
- Verify: `grep -c "CREATE TABLE IF NOT EXISTS" database.sql` → **15**; confirm file still ends with `SET FOREIGN_KEY_CHECKS = 1;`.
- No PHP files touched in this phase → no `php -l` needed for B itself.

---

## Phase 3 — Live DB migration (`db_webtable`) — safe execution

Because every statement is `CREATE TABLE IF NOT EXISTS` and additive, the whole file is idempotent: re-running after any partial failure simply completes the remainder. Recommended operator command (on the DB host):

```bash
# optional pre-flight backup (recommended)
mysqldump -u"$DB_USER" -p db_webtable > "db_webtable_backup_$(date +%Y%m%d_%H%M%S).sql"

# idempotent apply — safe to re-run
mysql -u"$DB_USER" -p db_webtable < database.sql
```

Fallback for this workspace (no `mysql` CLI): run the DDL through a throwaway PHP/CI3 runner using `application/config/database.php` credentials (`$this->db->query(...)` per statement) — written and removed inside `sys_get_temp_dir()`, never committed. `system_audit_logs` is created last, after `admins`, so FK creation never references a missing table.

---

## Phase 4 — Schema parity verification (post-apply)

```sql
SHOW TABLES;                                    -- expect 15 rows incl. the 7 new
SHOW CREATE TABLE wallet_ledger;                -- + deposits, user_rentals, admins,
SHOW CREATE TABLE user_notifications;           --   user_notifications, system_settings,
SHOW CREATE TABLE system_audit_logs;            --   system_audit_logs
-- FK delete/update rules parity:
SELECT rc.TABLE_NAME, rc.CONSTRAINT_NAME, rc.REFERENCED_TABLE_NAME,
       rc.DELETE_RULE, rc.UPDATE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS rc
WHERE rc.CONSTRAINT_SCHEMA = 'db_webtable'
  AND rc.TABLE_NAME IN ('wallet_ledger','deposits','user_rentals','user_notifications','system_audit_logs')
ORDER BY rc.TABLE_NAME;
-- Engine/collation parity:
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'db_webtable' AND TABLE_NAME IN ('wallet_ledger','deposits','user_rentals','admins','user_notifications','system_settings','system_audit_logs');
```

Expected: 15 tables; all new tables `InnoDB` / `utf8mb4_unicode_ci`; RESTRICT on ledger/deposits/user_rentals, CASCADE on `user_notifications`, SET NULL on audit logs.

---

## Phase 5 — Risk & drift analysis

- **Zero impact on existing data.** The block contains only `CREATE TABLE IF NOT EXISTS` + one `INSERT IGNORE` into a brand-new table. No `ALTER`/`DROP`/`UPDATE` touches the existing 8 tables; runtime records in `users`, `gpu_products`, `transactions`, etc. are untouched. `INSERT IGNORE` (vs. the plan file's plain `INSERT`) additionally guarantees no duplicate-key failure and no overwrite of a live `is_registration_open`.
- **Re-run safe:** any interrupted apply leaves only fully-created tables; a re-run completes the rest.
- **Documented follow-up drift (out of scope, from plan B5, unchanged):** `users` missing prod columns (`role`, `username`, `is_banned`, `must_change_password`, `is_level_1_claimed`, `last_wage_claimed_at`); `withdrawals` missing `wd_number`; dead `rentals` table; `site_settings` vs `system_settings` duplication; `Admin_model::get_active_rentals()` selects non-existent `user_rentals.product_name`; reCAPTCHA SSL verification. These are **not** touched in Phase B.
- **Explicit non-goal:** no new features, no application-logic changes beyond what's approved.

---

## Plan steps (for approval)

1. **Freeze & preview the 7-table DDL** (verified vs code + ERD) — *done above*
   - Finalize append block incl. `INSERT IGNORE` seed amendment
2. **Append DDL to `database.sql`** in dependency order
   - Verify `grep -c` → 15 and clean `git diff` (SQL file only)
3. **Apply to live `db_webtable`** (operator `mysql < database.sql`, optional backup, re-run-safe)
   - Pre-flight check of mysql client vs PHP fallback
4. **Verify schema parity** (`SHOW TABLES`, `SHOW CREATE TABLE`, `REFERENTIAL_CONSTRAINTS`, collation)
   - Compare against DDL; report any drift
5. **Risk & drift sign-off**
   - Confirm no impact on existing tables/records; log B5 follow-ups

Awaiting approval before touching `database.sql` or executing anything against the live database.
