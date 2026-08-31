# Phase B — Execution Summary (Database Schema Synchronization)

**Project:** Synapse (webtable) · **Baseline:** `main` @ `87ac933`
**Plan:** `plan/2_PHASE_B_PLAN.md` (approved) · **Status:** ✅ COMPLETED & re-verified

---

## What was done

### 1. `database.sql` updated (+127 lines, additions only)

Appended the approved 7-table DDL block after the final `SET FOREIGN_KEY_CHECKS = 1;`:

| Table | Purpose | FK (delete rule) |
|---|---|---|
| `wallet_ledger` | Immutable append-only ledger (`Wallet_model`, `Rental_model`, `Admin_model`, `User_model`) | → `users.id` **RESTRICT** |
| `deposits` | Transaction invoices (`Wallet_model`, `Admin::approve_deposit`) | → `users.id` **RESTRICT** |
| `user_rentals` | Active/expired GPU leases (`Rental_model`, `Admin_model`, `User_model`) | → `users.id` RESTRICT, `gpu_products.id` RESTRICT |
| `admins` | Isolated admin auth (`Admin_auth::login`) | none (hard-separated) |
| `user_notifications` | In-app notifications (`Notification_model`) | → `users.id` **CASCADE** |
| `system_settings` | Key-value circuit breaker (`Admin_model::get_setting/set_setting`, Phase 9A) | none |
| `system_audit_logs` | Phase 10 baseline (ERD §6); no code writes until 10A | `admins.id` SET NULL, `users.id` SET NULL |

All statements: `CREATE TABLE IF NOT EXISTS`, `ENGINE=InnoDB`, `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
Seed uses `INSERT IGNORE` (idempotent — never fails on duplicate key, never overwrites a live value).

### 2. Live database `db_webtable` applied

- `mysql` CLI not installed in this environment → used the plan's documented **PHP/CI3 fallback runner** (mysqli over TCP `127.0.0.1:3306`, creds from `application/config/database.php`).
- Only the **Phase B block** was executed (not the whole `database.sql`), because the legacy seed's plain `INSERT INTO site_settings` is not idempotent against existing rows. The Phase B block is fully idempotent.
- Result: **9/9 statements OK, 0 errors** (7 `CREATE TABLE` + 1 `INSERT IGNORE` + `SET FOREIGN_KEY_CHECKS = 1`).
- **Idempotency proven:** the block was re-applied → again 9/9 OK, no duplicate-key or table-exists errors.
- Throwaway runners lived in `/tmp` and were removed; nothing scratch was committed.

---

## Verification results

### Table count in `database.sql`

```
$ grep -c "CREATE TABLE IF NOT EXISTS" database.sql
15        # 8 pre-existing + 7 new
```

### Live DB parity (`db_webtable`)

```
SHOW TABLES → TABLE_COUNT=15
NEW_TABLES_PRESENT=ALL_7
```

Engine/collation (all 7 new tables): `InnoDB` / `utf8mb4_unicode_ci` ✅

FK delete rules (`information_schema.REFERENTIAL_CONSTRAINTS`):

| Table | Constraint | Referenced | DELETE rule |
|---|---|---|---|
| `wallet_ledger` | `fk_wallet_ledger_user` | `users` | RESTRICT |
| `deposits` | `fk_deposits_user` | `users` | RESTRICT |
| `user_rentals` | `fk_user_rentals_user` / `fk_user_rentals_product` | `users` / `gpu_products` | RESTRICT |
| `user_notifications` | `fk_notifications_user` | `users` | **CASCADE** |
| `system_audit_logs` | `fk_audit_user` / `fk_audit_admin` | `users` / `admins` | SET NULL |

Seed check: `system_settings.is_registration_open = 1` (exactly 1 row) ✅

### Existing data untouched

Row counts of all 8 pre-existing tables unchanged; legacy `site_settings` still holds exactly the 2 seed rows (`wa_number`, `support_email`). Phase B executed only additive DDL (`CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE`) — **no `ALTER`/`DROP`/`UPDATE`** on any existing table or record.

---

## Working tree state (ready for Phase C)

```
 M database.sql            ← Phase B (this step, +127 lines)
 M docs/3_ROADMAP.md       ← Phase A (prior, uncommitted)
 M reasonix.toml           ← pre-existing infra config (not touched by me)
?? docs/5_AUDIT_REPORT.md  ← untracked (expected)
?? plan/                   ← untracked (expected)
```

Phase C (Security Patch: reCAPTCHA secret extraction) touches only `application/controllers/Auth.php` + new `application/config/recaptcha.php` — **zero overlap** with Phase B. No PHP files changed in Phase B, so no `php -l` needed for this phase.

---

## Assumptions & deviations (reported during execution)

1. **Live-DB apply used the PHP/CI3 fallback** (mysql CLI absent) against `127.0.0.1:3306` with `root/root` from `application/config/database.php` — operator should re-confirm credentials in the production-equivalent environment.
2. **Only the Phase B block applied to the live DB** (not the whole `database.sql`) to avoid the non-idempotent legacy `site_settings` seed INSERT.
3. **`admins` table created empty** — admin account seeding is out of scope (no credentials in repo).
4. **No commit created yet** — `database.sql` + Phase A docs remain uncommitted, awaiting the Indonesian commit message per housekeeping convention.

## Deferred follow-ups (plan B5, out of scope, unchanged)

- `users` table missing prod columns (`role`, `username`, `is_banned`, `must_change_password`, `is_level_1_claimed`, `last_wage_claimed_at`)
- `withdrawals` missing `wd_number` (code uses it)
- Dead `rentals` table in seed (zero production references)
- `site_settings` vs `system_settings` duplication (consolidation)
- `Admin_model::get_active_rentals()` selects non-existent `user_rentals.product_name` (no join)
- reCAPTCHA SSL verification disabled in `Auth::_verify_recaptcha()` (Phase 10D)

---

## Next step

**Phase C — Security Patch: reCAPTCHA secret extraction** (`Auth.php` + new `config/recaptcha.php`), per `plan/0_HOUSEKEEPING_PLAN.md`.
