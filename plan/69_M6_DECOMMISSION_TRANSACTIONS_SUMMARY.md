# Plan 69 — M6 Decommission Transactions Table (Execution Summary)

**Status:** ✅ EXECUTED & VERIFIED
**Predecessor:** `plan/68_M6_TRANSACTIONS_TABLE_AUDIT_REPORT.md` (approved — zero-dependency proof)
**Date:** 2026-09-03
**Branch:** `main` (working tree; changes left uncommitted, consistent with existing phase workflow)
**Live DB target:** `db_webtable` @ 127.0.0.1:3306 (MariaDB 12.3.2), user `root` (local dev env-driven fallback)

---

## 1. Live Database Cleanup — ✅ Done

Executed against live `db_webtable` (approved DDL, `DROP TABLE IF EXISTS`):

```sql
DROP TABLE IF EXISTS `transactions`;
```

Verification results (post-drop, same session):

| Check | Result |
|---|---|
| `DROP TABLE IF EXISTS \`transactions\`` | `DROP OK` |
| `SHOW TABLES LIKE 'transactions'` | **rows = 0 (empty set)** |
| `SHOW TABLES` | **15 tables remain** (was 16) — all runtime tables intact (`users`, `gpu_products`, `rentals`, `bank_accounts`, `withdrawals`, `otp_logs`, `site_settings`, `wallet_ledger`, `deposits`, `user_rentals`, `admins`, `user_notifications`, `system_settings`, `system_audit_logs`, `rate_limits`) |

No dependent FK / trigger / view / routine existed (audit §3), so no `ALTER ... DROP FOREIGN KEY` chain was required; the table carried only its own `fk_transactions_user` constraint, which drops with the table.

## 2. Schema & Tooling Parity — ✅ Done

| File | Change | Verification |
|---|---|---|
| `database.sql` | Removed the complete `CREATE TABLE IF NOT EXISTS \`transactions\`` block (was L74-90 incl. `fk_transactions_user` FK, ENUM `type`, `balance_after`, `reference_type/id` columns, 3 indexes) | `grep -c transactions database.sql` → **0** |
| `scripts/seed_database.php` | Removed `'transactions'` from `$tables_expected` pre-flight array (was L71) | `php -l` clean; `grep` → 0 |
| `scripts/seed_withdraw_test_account.php` | Removed `'transactions'` from `dependent_tables()` truncation `$want` array (was L82) | `php -l` clean; `grep` → 0 |

## 3. Documentation & Specification Alignment — ✅ Done

Formalized **`wallet_ledger` as the sole authoritative transaction ledger** and removed legacy live-table mentions:

| File | Change |
|---|---|
| `docs/2_ERD.md` | (1) ACID-compliance rule (L13): dropped "Insert ke `transactions`" from the mutation list. (2) `wallet_ledger` section intro: added explicit statement that `wallet_ledger` is the ONE authoritative transaction ledger and `transactions` was decommissioned (M6 → `plan/68`/`plan/69`), do not recreate. (3) Replaced the full "### Tabel: `transactions`" spec section (was L97-108) with a `~~DECOMMISSIONED (M6)~~` marker note. |
| `AGENTS.md` | (1) Tagline: "double-entry wallet ledger" → "single authoritative wallet ledger (`wallet_ledger`)". (2) Schema-seed table list (L15): removed `transactions`. (3) Models list: removed deleted `Ledger_model`, aligned to actual files (`…`, `Audit_model`, `Rate_limit_model`). (4) Ledger section: rewrote to state `wallet_ledger` is the sole authoritative ledger; all money moves run inside ACID `trans_*` transactions with `SELECT ... FOR UPDATE` anchor; balance = `SUM(credit) − SUM(debit)`. |

Remaining occurrences of the word "transactions" in the edited docs are intentional **decommission traceability notes only** (`AGENTS.md` Ledger line; `docs/2_ERD.md` L81 + L97), never statements that the table is live.

**Intentionally NOT edited** (per audit sign-off scope): `docs/1_PRD.md` and `docs/3_ROADMAP.md` still describe the legacy double-entry insert steps — flagged as the remaining **spec-amendment follow-up** (product wording decision). Historical `backups/pre_seed_2026*.sql` snapshots left untouched as point-in-time artifacts.

## 4. Verification — ✅ All Passed

1. **Lint:** `php -l scripts/seed_database.php` → no syntax errors; `php -l scripts/seed_withdraw_test_account.php` → no syntax errors.
2. **DB query replication (post-drop, live schema)** — exact SQL used by the affected pages, executed against `db_webtable`:
   - `Admin_model::get_history_deposits` SQL (`deposits` ⋈ `users`, status success/failed) → OK (5 rows)
   - `Admin_model::get_history_withdrawals` SQL (`withdrawals` ⋈ `users` ⋈ `bank_accounts`) → OK (5 rows)
   - Wallet "Menunggu Pembayaran" pending-deposits query → OK (0 rows)
   - `wallet_ledger` history read → OK (5 rows)
3. **HTTP smoke** (`php -S 127.0.0.1:8097`, then `curl`):
   - `GET /login` → **HTTP 200** (app + DB boot clean)
   - `GET /admin/history` → **HTTP 307 → `http://synapse.test/control-panel`** (admin auth gate; no 500, no DB error)
   - `GET /wallet` → **HTTP 307 → `http://synapse.test/login`** (user auth gate; no 500, no DB error)
   - Server log scan: **no** `transactions` / `Fatal` / `Uncaught` / syntax entries.
   - Note: full authed page render was not exercised (no admin/user credentials available in read-only audit session); the DB queries those pages execute were replicated directly and succeed against the 15-table schema.
4. **Residual-scan:** `grep -c transactions` → `database.sql: 0`, `scripts/seed_database.php: 0`, `scripts/seed_withdraw_test_account.php: 0` (AGENTS.md/ERD: only the intentional decommission notes).

## 5. Outcome & Follow-ups

- `transactions` table removed from live `db_webtable`; `wallet_ledger` is now the sole transaction ledger in code, schema (`database.sql`), seed tooling, and docs.
- M6 pragmatic path **resolved** (see `plan/66` M6 row).
- **Follow-up (gated, not part of this execution):** amend `docs/1_PRD.md` (and optionally `docs/3_ROADMAP.md` historical ticks) to single-ledger wording — product/spec decision.
- **Follow-up (optional):** commit changes per per-phase-branch convention (commit message in Indonesian).

---

**Execution complete. Files touched this turn:** `database.sql`, `scripts/seed_database.php`, `scripts/seed_withdraw_test_account.php`, `docs/2_ERD.md`, `AGENTS.md`, and this report `plan/69_M6_DECOMMISSION_TRANSACTIONS_SUMMARY.md`.
