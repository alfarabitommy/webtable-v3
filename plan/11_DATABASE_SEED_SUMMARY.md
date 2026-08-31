# Database Seed Execution Summary — db_webtable (Phase 10 / Step 0)

**Date:** 2026-08-31 · **Target DB:** `db_webtable` (MariaDB 12.3.2, InnoDB, utf8mb4) · **Status:** ✅ APPLIED & FULLY VERIFIED

---

## 1. Execution Context

| Item | Value |
|------|-------|
| DB server | MariaDB 12.3.2 (TCP `127.0.0.1:3306`) — PHP mysqli client (no mysql client binary on host) |
| Pre-seed state | Schema present (15 tables from `database.sql`), **0 users, 0 admins** — clean slate |
| Missing prod columns before seed | `users.username, role, is_banned, must_change_password, is_level_1_claimed, last_wage_claimed_at`; `withdrawals.wd_number, amount` (+ `uk_wd_number`) |
| Reconciliation | All 9 guarded `ALTER`s applied in `--apply` (idempotent; skipped on re-runs) |
| Pre-seed backups | `backups/pre_seed_20260831_115526.sql` (empty DB), `backups/pre_seed_20260831_115602.sql` (post-first-apply) — auto-written by the runner, dir git-ignored |

## 2. Files Created

| File | Purpose |
|------|---------|
| `database_seed.sql` | Idempotent seed: reconciliation ALTERs + 11 data sections in FK-topological order, relative timestamps (`DATE_SUB(NOW(), INTERVAL n DAY)`), explicit PKs + `ON DUPLICATE KEY UPDATE` |
| `scripts/seed_database.php` | CLI runner: `--verify` (dry-run), `--apply` (transactional per section, FK checks ON), `--check` (standalone validation), `--force` (seed-domain wipe + re-apply), `--no-backup`; exit codes 0/1/2/3 |
| `plan/10_DATABASE_SEED_PLAN.md` | Approved blueprint (unchanged) |
| `plan/11_DATABASE_SEED_SUMMARY.md` | This document |
| `.gitignore` | Added `/backups/` |

## 3. Table Row Counts (post-apply)

| Table | Expected | Actual | Status |
|-------|----------|--------|--------|
| `admins` | 1 | 1 | ✅ |
| `gpu_products` | 4 | 4 | ✅ |
| `users` | 13 | 13 | ✅ |
| `bank_accounts` | 13 | 13 | ✅ |
| `user_rentals` | 15 | 15 | ✅ |
| `deposits` | 15 | 15 | ✅ |
| `withdrawals` | 4 | 4 | ✅ |
| `wallet_ledger` | ≥170 | **179** | ✅ |
| `user_notifications` | 14 | 14 | ✅ |
| `system_audit_logs` | 16 | 16 | ✅ |

Status mixes — deposits: `success 13 / pending 1 / failed 1` · withdrawals: `pending 1 / processing 1 / success 1 / failed 1` (gross/fee/net per PRD tiers: 5jt→256.500 fee, 3jt→156.500, 1jt→71.500, 500k→44.000).

## 4. Ledger Invariant Verification

Strict assertion: `users.balance == SUM(credit) − SUM(debit)` on `wallet_ledger`, balance ≥ 0.

| ID | Username | Phone | Credits (Rp) | Debits (Rp) | Balance (Rp) | Result |
|----|----------|-------|--------------|-------------|--------------|--------|
| 1 | vipleader | 081234567890 | 71.280.000 | 56.000.000 | 15.280.000 | ✅ PASS |
| 2 | cofounder | 081298765432 | 16.560.000 | 15.000.000 | 1.560.000 | ✅ PASS |
| 3 | agent_budi | 085712345678 | 10.385.000 | 9.000.000 | 1.385.000 | ✅ PASS |
| 4 | agent_sari | 085798765432 | 2.000.000 | 1.000.000 | 1.000.000 | ✅ PASS |
| 5 | agent_dewi | 087811223344 | 3.200.000 | 2.000.000 | 1.200.000 | ✅ PASS |
| 6 | agent_eka | 087855667788 | 5.000.000 | 5.000.000 | 0 | ✅ PASS |
| 7 | sub_fajar | 085723456789 | 1.580.000 | 1.500.000 | 80.000 | ✅ PASS |
| 8 | sub_gina | 085798112233 | 15.520.000 | 15.000.000 | 520.000 | ✅ PASS |
| 9 | sub_hadi | 087833445566 | 5.185.000 | 5.000.000 | 185.000 | ✅ PASS |
| 10 | sub_indah | 087855667799 | 2.200.000 | 1.000.000 | 1.200.000 | ✅ PASS |
| 11 | sub_joko | 081277889900 | 5.370.000 | 5.000.000 | 370.000 | ✅ PASS |
| 12 | leaf_karin | 081288990011 | 1.040.000 | 1.000.000 | 40.000 | ✅ PASS |
| 13 | leaf_lutfi | 081299001122 | 1.000.000 | 1.000.000 | 0 | ✅ PASS |

**13/13 PASS** · Total wallet balance = **Rp 22.820.000**. Ledger rows are *derived* from `deposits`/`user_rentals`/`withdrawals` via `INSERT ... SELECT` (single source of truth): 13 deposit credits, 15 purchase debits, 143 daily-ROI credits (one per `days_processed`), 4 WD debits, 1 refund credit, 3 bonus/wage/commission credits (U1) = 179 rows.

## 5. Integrity & Scenario Checks (all PASS)

| Check | Result |
|-------|--------|
| MLM tree: all 13 users reachable from roots, max depth 3, no self-parent, no orphan `parent_id` | ✅ |
| FK integrity: 10 relationships checked (rentals→users/products, deposits→users, withdrawals→users/bank, bank→users, ledger→users, notifications→users, audit→admins/users) — 0 orphans | ✅ |
| **H-0 / T+1 fixtures**: rentals #6 (agent_sari) & #15 (leaf_lutfi) → `days_processed = 0` AND `last_claimed_at IS NULL` | ✅ |
| Natural-key uniqueness: `phone`, `invite_code`, `invoice_number`, `wd_number`, `admins.username` | ✅ |
| Account presence: admin id=1 `admin`; 13 users (12 active, 1 banned by design) | ✅ |
| `password_verify('password')` TRUE for admin + all 13 users | ✅ |
| Audit viewer data: 16 rows, 11 distinct action strings (5× `approve_deposit`, 2× `admin_create_user`, 1× each of 9 more) | ✅ |

## 6. Runner Exit-Code Behaviour (verified)

| Command | Exit | Meaning |
|---------|------|---------|
| `php scripts/seed_database.php --verify` | 0 | Dry-run plan, no writes |
| `php scripts/seed_database.php --apply` | 0 | First apply, full validation green |
| `php scripts/seed_database.php --check` | 0 | Standalone re-validation |
| `php scripts/seed_database.php --apply` (DB already seeded, no `--force`) | 1 | Pre-flight protection abort |
| `php scripts/seed_database.php --apply --force` | 0 | Seed-domain wipe + re-apply, validation green |

## 7. Known Deviations from `plan/10_DATABASE_SEED_PLAN.md` (documented)

1. **Balance arithmetic corrected** (plan §7.3 used `≈` estimates; final exact values are the table above): U1 15.280.000 (plan slip: 19.8M+1.2M = 21.0M ROI, not 19.8M), U3 1.385.000 (deposit set to 9.000.000 so `balance ≥ 0`), U4 1.000.000 (success deposit 2.000.000, pending one reduced), U6 0 (success deposit 5.000.000 added so the cancelled-rental debit is funded), U7 80.000 (deposit 1.500.000), U13 0 (success deposit 1.000.000 added alongside the pending one). The invariant `balance = Σcredit − Σdebit ≥ 0` is enforced by construction and by validation.
2. **Deposits = 15** (13 success / 1 pending / 1 failed) vs plan's 17-row sketch — merged U3's two deposits; U13 keeps a pending invoice for the admin queue.
3. **Audit = 16 rows, 11 distinct actions** — `admin_inject_balance` intentionally dropped (it writes a ledger credit; keeping the invariant required matching ledger rows, so the action was replaced with extra `approve_deposit`/`admin_create_user` entries).
4. **`wallet_ledger` ROI rows generated via SQL** (`INSERT ... SELECT` from `user_rentals` joined to a numbers table) instead of ~143 hand-written rows — guaranteed consistency with `days_processed`/`daily_roi`.
5. **MariaDB, not MySQL 8.4** — same SQL dialect for everything used here; reconciliation is guarded in PHP (not `IF NOT EXISTS`), so it is portable to MySQL 8.4.
6. **InnoDB `autoinc_lock_mode=1` batch allocation** burns some `AUTO_INCREMENT` ids around `INSERT ... SELECT` (observed id gaps). Harmless, but it invalidated a naive id-range cleanup for `system_audit_logs` — the runner now cleans audit rows by date-window + user set instead of id range (fixed & re-verified).

## 8. Rollback

Restore any pre-seed snapshot, e.g. `mysql -u root -p db_webtable < backups/pre_seed_20260831_115526.sql`, or re-seed deterministically with `php scripts/seed_database.php --apply --force` (idempotent upserts + seed-domain cleanup). The seeder is a dev/staging tool — never run against a production DB with real users.

## 9. Credential Cheat-Sheet (Phone, Role, Flags, Password, Upline)

**Admin:** `/control-panel` → username `admin` / password `password`

| ID | Username | Phone | Upline ID | Role | Flags | Password |
|----|----------|-------|-----------|------|-------|----------|
| 1 | vipleader | 081234567890 | — (root) | user | `is_level_1_claimed=1`, wage claimed | password |
| 2 | cofounder | 081298765432 | — (root) | user | — (Level-1 bonus claim test) | password |
| 3 | agent_budi | 085712345678 | 1 | user | — | password |
| 4 | agent_sari | 085798765432 | 1 | user | H-0 rental → T+1 rejection test | password |
| 5 | agent_dewi | 087811223344 | 1 | user | failed WD + refund history | password |
| 6 | agent_eka | 087855667788 | 1 | user | `must_change_password=1` | password |
| 7 | sub_fajar | 085723456789 | 3 | user | — | password |
| 8 | sub_gina | 085798112233 | 3 | user | — | password |
| 9 | sub_hadi | 087833445566 | 4 | user | — | password |
| 10 | sub_indah | 087855667799 | 4 | user | completed-rental history | password |
| 11 | sub_joko | 081277889900 | 5 | user | — | password |
| 12 | leaf_karin | 081288990011 | 7 | user | newest member | password |
| 13 | leaf_lutfi | 081299001122 | 9 | user | `is_banned=1` (ban-lockout test) | password |

All hashes: bcrypt of `password` (`$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi`). Invite codes: `ABC123`…`KLM789` (id order).
