# Plan 68 — M6 Transactions Table Audit Report

**Status:** READ-ONLY AUDIT — no code or schema modified
**Mode:** Exhaustive dependency proof for the M6 pragmatic path (drop legacy `transactions`, formalize `wallet_ledger` as sole ledger)
**Date:** 2026-09-03
**Auditor scope:** `application/` (controllers, models, views, helpers, config, core, libraries, hooks), `database.sql`, `database_seed.sql`, `scripts/`, `backups/`, live `db_webtable` (read-only queries only)

---

## 1. Executive Summary

The application **never reads from, writes to, or depends on the `transactions` table**. Every occurrence of the string "transactions" in `application/` is either a **PHP variable name** holding rows from the `deposits`/`withdrawals` tables, an **HTML/UI label or comment**, a **CI3 transaction control-flow method** (`trans_*`), or a **comment** about database transactions. The only writer that ever existed (`Ledger_model`) was already deleted (C4, see `git status: D application/models/Ledger_model.php`; no runtime references remain).

Live-DB proof (`db_webtable`, MariaDB 12.3.2): `transactions` exists with **0 rows**; its only FK is `fk_transactions_user` → `users(id)`; **no** table, trigger, view, routine, or event references it in any direction.

**Verdict:** Dropping `transactions` causes **zero runtime regression**. Four non-runtime files reference the table name and must be updated in the same change (decommission steps in §5–§6).

---

## 2. Summary Table of Findings

Matrix of **every** occurrence of `transactions` in the audited surface. Classifications:

- **CI3 TX** = CodeIgniter transaction control-flow method (`trans_start`/`trans_begin`/`trans_complete`/`trans_status`/`trans_rollback`) — no table access.
- **Ledger Column** = `transaction_id`/`tx_*` identifiers on `wallet_ledger` or DOM ids — unrelated to the table.
- **Cosmetic/View** = PHP variable name, HTML label, comment, or UI string bound to other tables.
- **Actual Table Query** = real SQL/query-builder access to table `transactions` — **none found**.

### 2a. `application/` — the literal word "transactions" (9 hits — all non-table)

| Component / File | Line / Context | Classification | Drop Impact |
|---|---|---|---|
| `application/controllers/Admin.php` | 223 — `$transactions = $this->Admin_model->get_history_deposits(...)` | Cosmetic/View (var name) — `get_history_deposits()` reads `deposits` (JOIN `users`), see `Admin_model.php:52-61` | None |
| `application/controllers/Admin.php` | 226 — `$transactions = ...->get_history_withdrawals(...)` | Cosmetic/View (var name) — reads `withdrawals` (JOIN `users`, `bank_accounts`), see `Admin_model.php:62-71` | None |
| `application/controllers/Admin.php` | 258 — `'transactions' => $transactions` (view data for `admin/history`) | Cosmetic/View (array key) | None |
| `application/views/admin/history.php` | 47 — `if (empty($transactions))` | Cosmetic/View — rows come from `deposits`/`withdrawals` (columns used: `created_at`, `phone`, `invoice_number`/`wd_number`, `bank_name`, `account_number`, `amount`, `status` — all non-`transactions` columns) | None |
| `application/views/admin/history.php` | 68 — `foreach ($transactions as $row)` | Cosmetic/View (var iteration) | None |
| `application/views/admin/user_detail.php` | 172 — `<!-- Recent Transactions -->` | Cosmetic/View (HTML comment) | None |
| `application/views/admin/user_detail.php` | 173 — `<h5 ...>Recent Transactions</h5>` | Cosmetic/View (heading) — list fed by `$wallet_history` = `Admin_model::get_wallet_history()` → **`wallet_ledger`** (`Admin_model.php:166-171`) | None |
| `application/views/wallet/index.php` | 98 — `<!-- ===== PENDING TRANSACTIONS ===== -->` | Cosmetic/View (HTML comment) — section loops `$pending` (pending **`deposits`** invoices; fields `invoice_number`, `deposit_fee`, `total_payable`, `amount`) | None |
| `application/models/Audit_model.php` | 19 — comment "…the helper never manages transactions itself" | Comment (about CI3 `trans_*` nesting depth for `system_audit_logs` inserts) | None |

### 2b. CI3 transaction methods (`trans_*`) — control flow, NOT table access

| Component / File | Lines | Classification |
|---|---|---|
| `application/controllers/Admin.php` | 363, 375, 377 / 557, 591, 593 / 613, 625 / 737, 754, 756 / 786, 795, 797 / 838, 858, 860 / 921, 960, 962 / 1005, 1015, 1017 / 1041, 1051, 1053 | CI3 TX |
| `application/models/Admin_model.php` | 85, 90, 91 / 300-344 / 365-392 / 423-475 / 503-538 / 560, 573, 575 | CI3 TX |
| `application/models/Wallet_model.php` | 621-668 / 701-790 / 836, 851, 852 | CI3 TX |
| `application/models/Rental_model.php` | 71-119 / 194-290 / 357-388 | CI3 TX |
| `application/models/User_model.php` | 266-324 / 356-469 | CI3 TX |

All wrap ledger/deposit/withdrawal/rental mutations in ACID envelopes. **None touch table `transactions`.**

### 2c. "transaction"-token identifiers — ledger column / DOM ids, unrelated

| Component / File | Line / Context | Classification |
|---|---|---|
| `application/models/Wallet_model.php` | 488-552 — `credit()/debit()/_post()` params + `'transaction_id' => (string)$transaction_id` | Ledger Column — inserts into **`wallet_ledger`** (`transaction_id` column, unique `uk_wallet_ledger_user_tx_type`) |
| `application/models/Admin_model.php` | 499-515 — `$transaction_id = 'ADM-…'` passed to `Wallet_model::credit/debit` | Ledger Column — `ADM-` ids stored on `wallet_ledger`, not the table |
| `application/models/User_model.php` | 338-463 — comments + `'transaction_id' => $tx_id` (ROI/wage `tx_id` deterministic per cycle) | Ledger Column |
| `application/views/admin/user_detail.php` | 185 — `$tx->description ?? $tx->transaction_id` | Ledger Column — `$tx` is a `wallet_ledger` row |
| `application/views/marketplace/index.php` | 52, 88 — `id="transactionModal"`, `getElementById('transactionModal')` | DOM id (JS modal) — no DB |
| `application/models/Admin_model.php` | 522, 529 — `'balance_after'` in audit `details` JSON (comment + key for `system_audit_logs`) | Comment/audit payload — NOT a `transactions.balance_after` query |
| `application/models/Wallet_model.php` | 478 — comment "…Ledger_model lama…" (historical reference to deleted model) | Comment |

### 2d. Actual SQL / query-builder access to table `transactions`

**None.** Greps executed (all case-insensitive, across `application/`, `scripts/`, `*.sql`, plus whole-repo backtick scan):

- `SELECT|INSERT|UPDATE|DELETE … transactions`, `FROM/JOIN/INTO … transactions`
- `$this->db->get('transactions')` / `get_where` / `from('transactions')` / `table('transactions')`
- backticked `` `transactions` `` and quoted `"transactions"` / `'transactions'` string literals
- `balance_after`, `reference_type`, `reference_id` (columns that exist **only** on `transactions`): `balance_after` appears solely in an audit-JSON key + comment (`Admin_model.php:522,529`); the other two: **0 hits**.

Result: **0 actual table queries in application code.**

---

## 3. Database Dependency Check (explicit FK verification)

### 3a. Schema file `database.sql`

- **CREATE TABLE location:** lines 74-90 (`-- Table \`transactions\`` header at 74-75, `CREATE TABLE IF NOT EXISTS \`transactions\`` at line 76), between the `rentals` block (ends ~L72) and `bank_accounts` (starts L96).
- **Columns:** `id`, `user_id`, `type ENUM('deposit','withdrawal','rental_payment','daily_revenue','commission_bonus','refund')`, `amount`, `balance_after`, `description`, `reference_type`, `reference_id`, `created_at`; PK `id`; indexes `idx_user_id`, `idx_type`, `idx_created_at`.
- **FKs defined on `transactions`:** exactly one — `CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT` (L90).
- **Reverse FKs pointing to `transactions.id`:** none. Full FK inventory of `database.sql` (all 16 FKs) points at `users`, `gpu_products`, `bank_accounts`, or `admins` only.

### 3b. Live database `db_webtable` (read-only queries, MariaDB 12.3.2)

| Check (information_schema / direct) | Result |
|---|---|
| Table exists | Yes |
| Row count `SELECT COUNT(*) FROM transactions` | **0** |
| FKs defined ON `transactions` | 1: `fk_transactions_user` (`user_id` → `users.id`) — matches `database.sql` |
| Reverse FKs where `REFERENCED_TABLE_NAME = 'transactions'` | **NONE** |
| Triggers (`EVENT_OBJECT_TABLE='transactions'` or statement LIKE `%transactions%`) | **NONE** |
| Views with `VIEW_DEFINITION LIKE '%transactions%'` | **NONE** |
| Routines (stored proc/func) `ROUTINE_DEFINITION LIKE '%transactions%'` | **NONE** |
| Events `EVENT_DEFINITION LIKE '%transactions%'` | **NONE** |
| `wallet_ledger` row count (the live, de-facto ledger) | **212** |

**Conclusion:** `transactions` is a childless leaf table with a single outgoing FK to `users`, empty on the live server, and invisible to every trigger/view/routine/event in the schema.

---

## 4. Schema & Seed Inventory

| Artifact | Reference to `transactions` | Detail |
|---|---|---|
| `database.sql` | **Yes** — the only DDL | `CREATE TABLE IF NOT EXISTS \`transactions\`` at line 76 (block L74-90) |
| `database_seed.sql` | **No** | No `CREATE TABLE`, no `INSERT INTO transactions`. Inserts only into `admins`, `gpu_products`, `users`, `bank_accounts`, `user_rentals`, `deposits`, `withdrawals`, `wallet_ledger` (multiple), `user_notifications`, `system_audit_logs` |
| `scripts/seed_database.php` | **Yes** — L71 | `$tables_expected` pre-flight array includes `'transactions'` → seed run **fails** ("Missing tables: transactions") unless updated when table is dropped |
| `scripts/seed_withdraw_test_account.php` | **Yes** — L82 | `dependent_tables()` list (child-tables-to-truncate) includes `'transactions'` → only row deletion, no functional need |
| `backups/pre_seed_20260831_*.sql` (3 files) | **Yes** — schema snapshots | `DROP TABLE IF EXISTS` + `CREATE TABLE` mirror (L134-149 / L183-198 / L185-200); **0** `INSERT INTO \`transactions\`` rows in any backup (verified `grep -c`) |
| `docs/1_PRD.md`, `docs/2_ERD.md` (§Tabel `transactions` L97), `docs/3_ROADMAP.md`, `docs/5_AUDIT_REPORT.md`, `AGENTS.md:15` | **Yes** — spec/history text only | Business spec (PRD §daily_revenue / §commission_bonus) still mandates a `transactions` insert → **spec amendment is the M6 product decision** (single-ledger wording), separate from this audit |
| `plan/*` (37, 50, 54, 55, 66, 2, 28) | **Yes** — prior analysis | All prior audits already concluded: "dead code, zero callers, never written" (`plan/37`, `plan/54`, `plan/66`) |

---

## 5. Risk Assessment

**Confirmed: dropping `transactions` causes ZERO regression** under the definition "the application reads from, writes to, or depends on the table":

1. **No runtime SQL** in any controller/model/view/helper/config/library/hook targets the table (grep coverage above, incl. dynamic-string forms and backticked literals).
2. **No structural dependency** — no reverse FK, trigger, view, stored routine, or event (live-DB information_schema proof).
3. **No data at risk** — 0 rows on live DB; no seed file or backup dump ever inserted a row.
4. **All 9 in-code occurrences of the string** are cosmetic variables/labels/comments bound to `deposits`, `withdrawals`, or `wallet_ledger`; `trans_*` calls are CI3 transaction control flow; `transaction_id`/`tx_*` are `wallet_ledger` columns and DOM ids.
5. **Deleted writer stays deleted** — `Ledger_model` removed (C4); only a historical comment in `Wallet_model.php:478` mentions it; no call sites remain.

**Non-runtime regression surface (must ship in the same change, else tooling breaks):**

| File | Reason |
|---|---|
| `scripts/seed_database.php:71` | Pre-flight `SHOW TABLES` check fails with "Missing tables: transactions" |
| `scripts/seed_withdraw_test_account.php:82` | Truncation list contains a now-missing table (loop skips missing gracefully only after a per-table existence check — update list anyway) |
| `AGENTS.md:15`, `docs/2_ERD.md`, `docs/1_PRD.md`, `docs/3_ROADMAP.md` | Documentation still names `transactions` as part of the ledger design (PRD/ERD amendment is the gated M6 product decision) |
| `backups/pre_seed_*.sql` | Historical snapshots — leave untouched (point-in-time artifacts) |

---

## 6. Decommissioning Steps (DRAFT — NOT EXECUTED; requires sign-off)

### 6a. Live database (`db_webtable`)

No other table references `transactions`, so no `ALTER … DROP FOREIGN KEY` chain on dependents is needed. The table's own FK travels with the table.

```sql
-- 0) OPTIONAL safety snapshot before any change (table currently has 0 rows):
--    mysqldump --no-data db_webtable transactions > backups/pre_drop_transactions_YYYYMMDD_HHMMSS.sql
--    mysqldump db_webtable transactions >> backups/pre_drop_transactions_YYYYMMDD_HHMMSS.sql   -- captures any rows

-- 1) Live schema: drop the legacy table
SET FOREIGN_KEY_CHECKS = 0;          -- belt-and-braces; no dependent FK exists
DROP TABLE IF EXISTS `transactions`;
SET FOREIGN_KEY_CHECKS = 1;

-- Staged alternative (equivalent, more verbose):
--   ALTER TABLE `transactions` DROP FOREIGN KEY `fk_transactions_user`;
--   DROP TABLE `transactions`;
```

### 6b. `database.sql` (schema-of-record)

Delete block lines 74-90 (comment header `-- Table \`transactions\`` + full `CREATE TABLE IF NOT EXISTS \`transactions\`` … `fk_transactions_user` … closing `ENGINE=InnoDB …;`), keeping `rentals` (L54-72) and `bank_accounts` (L96+) intact. Optional: add a one-line schema-history note.

### 6c. Code / tooling (non-SQL)

- `scripts/seed_database.php:71` — remove `'transactions'` from `$tables_expected`.
- `scripts/seed_withdraw_test_account.php:82` — remove `'transactions'` from `dependent_tables()` `$want` list.
- Docs amendment (the gated M6 product decision, handled in the execution plan, not this audit): `docs/1_PRD.md` (drop `transactions` insert steps), `docs/2_ERD.md` (§Tabel `transactions`, §ACID wording), `docs/3_ROADMAP.md` historical ticks left as-is, `AGENTS.md:15` table list.

### 6d. Post-change verification (execution phase)

1. `SHOW TABLES LIKE 'transactions'` → empty.
2. Smoke: admin history page (`admin/history/deposit`, `.../withdrawal`) HTTP 200 (reads `deposits`/`withdrawals`); wallet page HTTP 200 (reads `wallet_ledger`); `wallet_ledger` balance math unchanged (`SUM(credit)-SUM(debit)`, 212 rows baseline).
3. `php scripts/seed_database.php --dry-run` passes pre-flight without `transactions`.
4. Grep regression: `grep -rn "transactions" application/` → only the cosmetic/CI3-TX/comment hits listed in §2a-§2c (unchanged, expected).

---

## 7. Sign-off Gate

**READ-ONLY AUDIT COMPLETE. Awaiting user review/sign-off before any schema modification (§6a-§6d) is executed.**

- ✅ Proven: application never reads/writes/depends on `transactions`.
- ✅ Proven: no FK/trigger/view/routine/event dependency (live DB).
- ✅ Proven: 0 rows at risk; seed/backup files never populated it.
- ⏸ Holds: the M6 product decision (amend PRD/ERD to single-ledger wording) and sign-off to execute §6.
