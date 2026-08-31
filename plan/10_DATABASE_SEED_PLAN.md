# Database Seed Plan — db_webtable (Dummy Dataset for Full-Feature Testing)

**Status:** Blueprint — awaiting approval before running any seeder. **No SQL has been executed and no code written to the database.**

Target DB: `db_webtable` (MySQL 8.4 / InnoDB / utf8mb4), stack CodeIgniter 3 + PHP 8.x.
Goal: a rich, realistic, large-scale dummy dataset covering **MLM agency trees, wallet ledgers, ROI claims, withdrawal queues, and the Phase 10A Audit Viewer** so every feature can be exercised end-to-end without touching real money.

---

## 1. Scope & Objectives

| # | Feature under test | Seeded data that exercises it |
|---|--------------------|-------------------------------|
| 1 | Auth / login (user + admin) | 13 user accounts + 1 admin, all with known credentials |
| 2 | MLM agency tree (Team page) | 3-tier tree: Tier 0 → Tier 1 → Tier 2 → Tier 3, B+C downline counting, Level 1 bonus qualification |
| 3 | Wallet double-entry ledger | `wallet_ledger` credits/debits; `users.balance` == Σcredit − Σdebit for every user |
| 4 | Marketplace & rental purchase | 4 GPU tiers; multiple leases per user |
| 5 | Daily ROI & claim (T+1 rule) | H-3/H-2/H-1 rentals with accrued ROI claimable; **H-0 rental created today → T+1 claim rejection test** |
| 6 | Completed / expired / cancelled states | Lifecycle coverage incl. admin cancel & time-travel |
| 7 | Deposits (invoice queue) | pending / success / failed with `INV-...` numbers |
| 8 | Withdrawals (queue + fees) | pending / processing / success / failed, gross/fee/net per PRD fee tiers, single-pending-WD rule |
| 9 | Notifications | commission / success / info / warning, read + unread |
| 10 | Phase 10A Audit Viewer | `system_audit_logs` populated with every action string the code writes |
| 11 | Admin user management | banned user, forced-password-change user, upline reassignment data |

---

## 2. Live-Schema Drift & Reconciliation (read this first)

`database.sql` is **out of sync with the code** (documented drift, see `plan/0_HOUSEKEEPING_PLAN.md` §B5, Phase 7E3). The seeder must target the **live production schema**, so it ships with an idempotent reconciliation step.

Columns the code uses that are **missing from `database.sql`**:

| Table | Missing in `database.sql` | Used by |
|-------|---------------------------|---------|
| `users` | `username VARCHAR(50)`, `role VARCHAR(20)`, `is_banned TINYINT(1)`, `must_change_password TINYINT(1)`, `is_level_1_claimed TINYINT(1)`, `last_wage_claimed_at TIMESTAMP NULL` | `Admin_model`, `Auth`, `User_model::claim_level1/claim_wage`, `MY_Controller` |
| `withdrawals` | `wd_number VARCHAR(50) UNIQUE`, `amount DECIMAL(15,2)` | `Wallet_model::create_withdrawal`, `Admin::approve/decline_withdrawal`, views |

Notes:
- `withdrawals` also carries `gross_amount / fee_amount / net_amount` from `database.sql`; the code path writes `amount` (gross) only. The seeder **writes `amount` (code-compatible) and, if the columns exist, fills `gross_amount = amount`, `fee_amount`/`net_amount` from the PRD fee table** (see §8.3).
- `role` is `'user'` or `'admin'` (checked by `Auth::login`). Admin console auth lives in `admins` (hard-separated `admin_id` session); **never set `users.role = 'admin'` in the seed** — "VIP Leader" is a business label expressed via `level_id`/username, not a role.
- The runner (see §10) executes reconciliation as guarded `ALTER TABLE ... ADD COLUMN` statements checked against `information_schema.COLUMNS`, so the same script runs clean on both a pristine `database.sql` instance and the migrated live DB.

---

## 3. Admin Master Account (`admins`)

| Column | Value |
|--------|-------|
| `id` | 1 |
| `username` | `admin` |
| `password` | `$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi` (plaintext: `password`) |
| `created_at` | 45 days ago |

Login route: `/control-panel` (`Admin_auth::login`). Guarded by `admin_id` session.

---

## 4. User Hierarchy Map (`users`) — 13 accounts, 3-tier MLM

All passwords: bcrypt `$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi` (plaintext `password`).
Phones are backend-normalized (`0[0-9]{9,13}`, no dashes). Invite codes: 6-char alphanumeric, unique (`Admin_model::generate_invite_code` style).

| ID | Username | Phone | Role | `parent_id` (upline) | Tree tier | `level_id` | Flags | Notes |
|----|----------|-------|------|----------------------|-----------|------------|-------|-------|
| 1 | `vipleader` | `081234567890` | user | NULL | Tier 0 (Root) | 0 | `is_level_1_claimed=1`, `last_wage_claimed_at` set | VIP Leader — anchor of the tree |
| 2 | `cofounder` | `081298765432` | user | NULL | Tier 0 (Root) | 0 | — | Second root; tests multi-root tree |
| 3 | `agent_budi` | `085712345678` | user | 1 | Tier 1 (Direct) | 1 | — | Active downline, 2 sub-downlines |
| 4 | `agent_sari` | `085798765432` | user | 1 | Tier 1 (Direct) | 1 | — | 2 sub-downlines |
| 5 | `agent_dewi` | `087811223344` | user | 1 | Tier 1 (Direct) | 1 | — | 1 sub-downline |
| 6 | `agent_eka` | `087855667788` | user | 1 | Tier 1 (Direct) | 1 | `must_change_password=1` | Tests forced password-change redirect |
| 7 | `sub_fajar` | `085723456789` | user | 3 | Tier 2 | 2 | — | Edge to Tier 3 |
| 8 | `sub_gina` | `085798112233` | user | 3 | Tier 2 | 2 | — | — |
| 9 | `sub_hadi` | `087833445566` | user | 4 | Tier 2 | 2 | — | Edge to Tier 3 |
| 10 | `sub_indah` | `087855667799` | user | 4 | Tier 2 | 2 | — | Completed-rental history |
| 11 | `sub_joko` | `081277889900` | user | 5 | Tier 2 | 2 | — | — |
| 12 | `leaf_karin` | `081288990011` | user | 7 | Tier 3 (Leaf) | 3 | — | Newest member, H-1 rental |
| 13 | `leaf_lutfi` | `081299001122` | user | 9 | Tier 3 (Leaf) | 3 | `is_banned=1` | Banned user — tests ban lockout on login |

Invite-code allocation (fixed, unique): `U1=ABC123`, `U2=DEF456`, `U3=GHI789`, `U4=JKL012`, `U5=MNO345`, `U6=PQR678`, `U7=STU901`, `U8=VWX234`, `U9=YZA567`, `U10=BCD890`, `U11=EFG123`, `U12=HIJ456`, `U13=KLM789`.

MLM qualification sanity check (for the Team page of `vipleader`):
- Active downlines B+C (users with ≥1 `user_rentals` row): 3,4,5,7,8,9,10,11,12,13 → 10 active ≥ 3 ✓
- B+C total sales = Σ `purchase_price` of active downlines' rentals → designed ≥ Rp 330.000 ✓ (Level 1 bonus already claimed by U1; a **not-yet-claimed** setup exists for `cofounder` (U2) so `POST /team/claim-level1` can be tested against a qualifying tree).

---

## 5. GPU Products Catalog (`gpu_products`) — 4 tiers

| ID | Name | `type` | Price (Rp) | `daily_rate` (Rp) | `duration_days` | `is_refundable` | `is_active` |
|----|------|--------|-----------|-------------------|-----------------|-----------------|-------------|
| 1 | RTX 4090 Node (Entry) | short_term | 1.000.000 | 40.000 | 30 | 0 | 1 |
| 2 | RTX 4090 Dual Cluster (Mid) | long_term | 5.000.000 | 185.000 | 90 | 1 | 1 |
| 3 | A100 Tensor Cloud (High) | long_term | 15.000.000 | 520.000 | 180 | 1 | 1 |
| 4 | H100 Sovereign Node (Enterprise) | long_term | 50.000.000 | 1.650.000 | 365 | 1 | 1 |

Values are placeholders to be **verified against `Product_model` mock fallback** during implementation and adjusted if the app renders a fallback catalog with different figures. All rates are plain IDR integers (PRD: integer-level precision; no sen).

---

## 6. Rich Rental Leases (`user_rentals`)

Date strategy: **relative to run time** (`DATE_SUB(NOW(), INTERVAL n DAY)`) so H-0 is always "today" and the T+1 test stays valid on every re-run.

| Rental ID | User | Product | `purchase_price` | `daily_roi` | `total_days` | `days_processed` | Status | Created (H = days ago) | Test purpose |
|-----------|------|---------|------------------|-------------|--------------|------------------|--------|------------------------|--------------|
| 1 | 1 | 4 (H100) | 50.000.000 | 1.650.000 | 365 | 12 | active | H-12 | Long-term accrual, claimable |
| 2 | 1 | 1 (4090 Node) | 1.000.000 | 40.000 | 30 | 30 | completed | H-45 (expired) | Completed lease history |
| 3 | 2 | 3 (A100) | 15.000.000 | 520.000 | 180 | 3 | active | H-10 | — |
| 4 | 3 | 2 (Dual Cluster) | 5.000.000 | 185.000 | 90 | 1 | active | **H-2** | Past rental, ROI claimable |
| 5 | 3 | 1 (4090 Node) | 1.000.000 | 40.000 | 30 | 30 | completed | H-40 (expired) | Upline with full history |
| 6 | 4 | 1 (4090 Node) | 1.000.000 | 40.000 | 30 | 0 | active | **H-0 (today)** | **T+1 rejection test** |
| 7 | 5 | 1 (4090 Node) | 1.000.000 | 40.000 | 30 | 30 | completed | H-60 (expired) | Expired → not claimable |
| 8 | 6 | 2 (Dual Cluster) | 5.000.000 | 185.000 | 90 | 0 | cancelled | H-5 | Cancelled state (admin cancel) |
| 9 | 7 | 1 (4090 Node) | 1.000.000 | 40.000 | 30 | 2 | active | **H-3** | Past rental, ROI claimable |
| 10 | 8 | 3 (A100) | 15.000.000 | 520.000 | 180 | 1 | active | **H-1** | Claimable today |
| 11 | 9 | 2 (Dual Cluster) | 5.000.000 | 185.000 | 90 | 1 | active | **H-2** | Past rental |
| 12 | 10 | 1 (4090 Node) | 1.000.000 | 40.000 | 30 | 30 | completed | H-35 (expired) | History only |
| 13 | 11 | 2 (Dual Cluster) | 5.000.000 | 185.000 | 90 | 2 | active | H-3 | Past rental |
| 14 | 12 | 1 (4090 Node) | 1.000.000 | 40.000 | 30 | 1 | active | **H-1** | Leaf member |
| 15 | 13 | 1 (4090 Node) | 1.000.000 | 40.000 | 30 | 0 | active | H-0 (today) | Banned user with lease (ban blocks login only) |

Invariant: `days_processed` = number of ROI credits already in `wallet_ledger` for that rental (credits use `transaction_id` prefix `ROI-{rental_id}-`), and `last_claimed_at` = `created_at + days_processed days` (except H-0/H-1 rentals where claim has not happened yet). Completed rentals: `expired_at = created_at + total_days`, `last_claimed_at` set, all `total_days` ROI credits present.

---

## 7. Wallet Ledger & Double-Entry Accounting (`wallet_ledger`)

### 7.1 Golden rule (enforced by the seeder, verified by the runner)

> `users.balance = SUM(credit) − SUM(debit)` on `wallet_ledger` per `user_id` — and both must be ≥ 0 for every non-banned user.

The ledger rows are **derived by construction** from the deposits / rentals / withdrawals / commissions defined above, so the invariant holds by design, not by luck. The runner's `--verify` mode independently re-computes `SUM(credit)-SUM(debit)` and reports any mismatch against `users.balance`.

### 7.2 Ledger entry catalogue (per user, deterministic)

| # | `type` | `transaction_id` pattern | `description` pattern | Amount |
|---|--------|--------------------------|-----------------------|--------|
| A | credit | `INV-{ts}-{user_id}` | `Top Up via {invoice}` | deposit amount |
| B | debit | `RENT-{rental_id}-{ts}` | `Pembelian {product_name}` | `purchase_price` |
| C | credit | `ROI-{rental_id}-{ts}` | `ROI Harian #{rental_id}` | `daily_roi` × 1 per processed day |
| D | credit | `CM-{ts}-{user_id}` | `Komisi Referral {upline_phone}` | per PRD (bonus/wage) |
| E | debit | `WD-{ts}-{user_id}` | `Penarikan Dana via {wd_number}` | gross `amount` |
| F | credit | `WD-{ts}-{user_id}` (refund pair) | `Pengembalian Dana: Penarikan Ditolak ({wd_number})` | gross `amount` (only for failed WD) |
| G | credit | `L1-{ts}-{user_id}` | `Bonus Level 1 Agency` | 80.000 (only U1, already claimed) |
| H | credit | `WAGE-{ts}-{user_id}` | `Gaji Mingguan Level {n}` | per PRD (optional for U1) |

Rows B and C use the **same `user_id` and rental** as §6 so ROI math matches `days_processed` exactly.

### 7.3 Per-user balance spec (targets the runner verifies)

| User | Σ credits | Σ debits | Expected balance |
|------|-----------|----------|------------------|
| 1 | 50.000.000 dep + 19.800.000 ROI (12×1.65M + 30×40k) + 80.000 L1 + 100.000 wage | 51.000.000 (2 rentals) + 5.000.000 WD (success) | ≈ 13.980.000 |
| 2 | 15.000.000 dep + 1.560.000 ROI | 15.000.000 (rental) | ≈ 1.560.000 |
| 3 | 6.000.000 dep + 185.000 + 40.000×30 ROI | 6.000.000 (2 rentals) + 3.000.000 WD (pending) | ≈ 4.385.000 |
| 4 | 2.000.000 dep + 0 ROI (H-0) | 1.000.000 (rental) | ≈ 1.000.000 |
| 5 | 1.000.000 dep + 1.200.000 ROI (30×40k) | 1.000.000 (rental) + 1.000.000 WD (failed→refunded +1.000.000) | ≈ 1.200.000 |
| 6 | 5.000.000 dep (failed — **not** credited) | 5.000.000 (cancelled rental debit, see note) | 0 |
| 7 | 1.000.000 dep + 80.000 ROI (2×40k) | 1.000.000 (rental) + 500.000 WD (processing) | ≈ 580.000 |
| 8 | 15.000.000 dep + 520.000 ROI | 15.000.000 (rental) | 520.000 |
| 9 | 5.000.000 dep + 185.000 ROI | 5.000.000 (rental) | 185.000 |
| 10 | 1.000.000 dep + 1.200.000 ROI | 1.000.000 (rental) | 1.200.000 |
| 11 | 5.000.000 dep + 370.000 ROI (2×185k) | 5.000.000 (rental) | 370.000 |
| 12 | 1.000.000 dep + 40.000 ROI | 1.000.000 (rental) | 40.000 |
| 13 | 1.000.000 dep (pending — not credited) | 1.000.000 (rental, from prior deposit credit) | 0 |

Cancelled rental (U6): debit for `purchase_price` stays in the ledger (funds locked at purchase); admin cancel does not auto-refund in current code — the seed mirrors code behavior. U6 and U13 end at balance 0, which is fine (withdrawal gate requires an active rental anyway).

---

## 8. Financial & Secondary Tables

### 8.1 `bank_accounts`
One bound account per user, `is_primary = 1`, realistic digits per bank (BCA 10, Mandiri 13, BRI 15, BNI 10, CIMB 10). Distribution: U1 BCA, U2 Mandiri, U3 BRI, U4 BNI, U5 CIMB, U6 BCA, U7 Mandiri, U8 BRI, U9 BNI, U10 CIMB, U11 BCA, U12 Mandiri, U13 BRI. Holder names = full-name variant of username. Verified accounts only (no pending verification flow exists).

### 8.2 `deposits` (invoice queue)
Invoice format matches `Wallet_model::create_deposit`: `INV-{YmdHis}-{user_id}` (unique). Mixed statuses:

| Invoice | User | Amount | Status | Age |
|---------|------|--------|--------|-----|
| INV-…-1 | 1 | 50.000.000 | success | H-45 |
| INV-…-2 | 2 | 15.000.000 | success | H-30 |
| INV-…-3 | 3 | 5.000.000 | success | H-10 |
| INV-…-3 | 3 | 1.000.000 | success | H-5 |
| INV-…-4 | 4 | 1.000.000 | success | H-3 |
| INV-…-4 | 4 | 2.000.000 | **pending** | H-1 |
| INV-…-5 | 5 | 1.000.000 | success | H-60 |
| INV-…-6 | 6 | 5.000.000 | **failed** | H-8 |
| INV-…-7 | 7 | 1.000.000 | success | H-4 |
| INV-…-8 | 8 | 15.000.000 | success | H-6 |
| INV-…-9 | 9 | 5.000.000 | success | H-4 |
| INV-…-10 | 10 | 1.000.000 | success | H-35 |
| INV-…-11 | 11 | 5.000.000 | success | H-5 |
| INV-…-12 | 12 | 1.000.000 | success | H-2 |
| INV-…-13 | 13 | 1.000.000 | **pending** | H-1 |

Only `success` deposits produce ledger credits (matches `approve_deposit_simulator` behavior). The 2 pending invoices give the admin dashboard an immediate approval queue.

### 8.3 `withdrawals` (queue + fees)
`wd_number` format matches `Wallet_model::create_withdrawal`: `WD-{YmdHis}-{user_id}` (unique). Fee tiers per PRD §E (`fee = pct × gross + 6.500`; `net = gross − fee`):

| WD | User | `amount` (gross) | Fee calc (tier) | Fee | Net | Status | Age | Ledger |
|----|------|------------------|-----------------|-----|-----|--------|-----|--------|
| 1 | 1 | 5.000.000 | 5% + 6.500 (2–5jt) | 256.500 | 4.743.500 | success | H-15 | debit −5.000.000 |
| 2 | 3 | 3.000.000 | 5% + 6.500 (2–5jt) | 156.500 | 2.843.500 | **pending** | H-1 | debit −3.000.000 (single-pending-WD test) |
| 3 | 5 | 1.000.000 | 6.5% + 6.500 (1–2jt) | 71.500 | 928.500 | **failed** | H-30 | debit −1.000.000 **+ refund credit +1.000.000** |
| 4 | 7 | 500.000 | 7.5% + 6.500 (0.5–1jt) | 44.000 | 456.000 | **processing** | H-2 | debit −500.000 |

Statuses deliberately span the full queue; U3's pending WD blocks further WD requests for that user (single-pending rule). All WDs belong to users holding an active rental (withdrawal gate requirement).

### 8.4 `user_notifications`
Types restricted to the schema enum (`info`, `warning`, `success`, `commission`). ~14 rows, mix of read/unread:

- `commission` — "Bonus Level 1 Cair" (U1, read) + "Komisi Referral" (U1, unread)
- `success` — "Deposit Berhasil" (U2, read; U3, unread), "Penarikan Berhasil" (U1, read)
- `warning` — "Penarikan Ditolak" (U5, unread)
- `info` — "Selamat Datang di Synapse" (every new leaf: U12, U13, unread), "Pembaruan Sistem" (U1, read)
- `error` is NOT used (not in enum — pre-existing code quirk in `decline_withdrawal`; seeds stay enum-safe).

### 8.5 `system_audit_logs` (Phase 10A Audit Viewer)
`admin_id = 1`, `user_id` varied, `details` JSON-encoded with `JSON_UNESCAPED_UNICODE` (matches `Audit_model::log_admin_action`), `ip_address` realistic (e.g. `127.0.0.1`, `10.0.0.11`), spread over the last 14 days so date-filter pagination is testable. **Use only action strings the code actually writes** (drives the filter dropdown in `/admin/audit`):

| Action | Details example | # rows |
|--------|-----------------|--------|
| `approve_deposit` | `{"invoice_number":"INV-…","amount":15000000}` | 4 |
| `approve_withdrawal` | `{"wd_number":"WD-…","amount":5000000}` | 1 |
| `decline_withdrawal` | `{"wd_number":"WD-…","amount":1000000,"refunded":true}` | 1 |
| `admin_update_settings` | `{"fields":["is_registration_open"]}` | 1 |
| `admin_update_user` | `{"phone":"0812…","invite_code":"…","upline_id":null}` | 1 |
| `admin_toggle_ban` | `{"new_state":"banned"}` | 1 |
| `admin_inject_balance` | `{"type":"credit","amount":100000}` | 1 |
| `admin_inject_rental` | `{"product_id":1}` | 1 |
| `admin_cancel_rental` | `{"rental_id":8}` | 1 |
| `admin_adjust_time` | `{"rental_id":9,"days_processed":2}` | 1 |
| `admin_create_user` | `{"phone":"0812…","created_by":1}` | 2 |
| `admin_reset_password` | details `NULL` (plaintext never logged) | 1 |

~16 rows total → viewer, filters, and pagination are populated immediately.

---

## 9. `database_seed.sql` Structure (safe, idempotent)

Single file at repo root: `database_seed.sql`, split into numbered sections with clear banners.

### 9.1 Idempotency design
- **Explicit PKs everywhere** (`INSERT INTO t (id, …) VALUES (1, …)` …) → every statement uses `ON DUPLICATE KEY UPDATE` (PK-based), so re-running updates in place instead of failing or duplicating.
- Natural-key guards on top of PK upsert for safety: pre-flight conflict check (see §10) aborts if an existing row's natural key (`phone`, `invite_code`, `invoice_number`, `wd_number`, `admins.username`) collides with a seed value owned by a *different* row.
- **Relative timestamps** (`DATE_SUB(NOW(), INTERVAL n DAY)`) → every re-run re-creates the H-0/T+1 scenario against "today".
- `SET FOREIGN_KEY_CHECKS=0` is **not** used for data inserts; sections are ordered by FK dependency so checks stay ON and referential integrity is proven (see 9.2). `SET NAMES utf8mb4;` at top.

### 9.2 Section order (FK dependency)
1. `SET NAMES utf8mb4;` + safe guards (no DROP, no TRUNCATE).
2. Schema reconciliation ALTERs (guarded by `information_schema`; no-op on migrated DBs).
3. `admins` (no FK) — id 1.
4. `gpu_products` (no FK) — ids 1–4.
5. `users` (self-FK `parent_id`) — ids 1–13; parents inserted before children (order 1,2,3,4,5,6,7,8,9,10,11,12,13 already topological).
6. `bank_accounts` (FK users) — 13 rows.
7. `user_rentals` (FK users, gpu_products) — 15 rows (§6).
8. `deposits` (FK users) — 15 rows (§8.2).
9. `withdrawals` (FK users, bank_accounts) — 4 rows (§8.3).
10. `wallet_ledger` (FK users) — rows derived from §7.2 catalogue (≈90 rows; generated per user).
11. `user_notifications` (FK users) — 14 rows.
12. `system_audit_logs` (FK admins, users) — 16 rows.
13. `system_settings` / `site_settings` — leave existing `INSERT IGNORE` seeds untouched (no overwrite of live values).
14. Final verification queries as SQL comments + a `-- SEED COMPLETE` banner (runner performs the actual checks).

### 9.3 Example statement shapes
```sql
-- users (parent before child; bcrypt = the 'password' hash)
INSERT INTO `users` (`id`,`username`,`phone`,`password`,`invite_code`,`parent_id`,`balance`,`level_id`,`role`,`is_banned`,`must_change_password`,`is_level_1_claimed`,`last_wage_claimed_at`,`created_at`)
VALUES (1,'vipleader','081234567890','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','ABC123',NULL,13980000,0,'user',0,0,1,DATE_SUB(NOW(),INTERVAL 7 DAY),DATE_SUB(NOW(),INTERVAL 45 DAY))
ON DUPLICATE KEY UPDATE `phone`=VALUES(`phone`), `password`=VALUES(`password`), `parent_id`=VALUES(`parent_id`), `balance`=VALUES(`balance`), …;

-- wallet_ledger (one row per ROI day keeps the audit trail realistic)
INSERT INTO `wallet_ledger` (`user_id`,`transaction_id`,`type`,`amount`,`description`,`created_at`)
VALUES (1,'ROI-1-{ts}','credit',1650000.00,'ROI Harian #1',DATE_SUB(NOW(),INTERVAL 12 DAY))
ON DUPLICATE KEY UPDATE `amount`=VALUES(`amount`);
```
(The actual file expands these; the plan pins down values, formats, and invariants so implementation is mechanical.)

---

## 10. PHP Runner — `scripts/seed_database.php`

Standalone CLI script (outside CI MVC so no web exposure; reads DB creds from `application/config/database.php` via `$db['default']`; uses PDO/mysqli).

### 10.1 Modes (CLI args)
- `php scripts/seed_database.php --verify` (default): dry-run — schema reconciliation plan, natural-key conflict scan, FK-order validation; **writes nothing**.
- `php scripts/seed_database.php --apply`: executes `database_seed.sql` section-by-section, each section in its own transaction, in §9.2 order, FK checks **ON**.
- `php scripts/seed_database.php --apply --force`: skips the "existing non-seed rows detected" abort.
- Exit codes: 0 = clean, 1 = pre-flight failure, 2 = apply failure (rolled back to last good section).

### 10.2 Pre-flight (prevents FK conflicts & data clobbering)
1. Connect; `SELECT VERSION()`, check `db_webtable` exists.
2. `DESCRIBE users / withdrawals` → build the reconciled column set; print which prod columns will be added.
3. Natural-key conflict scan: seed `phone`/`invite_code`/`invoice_number`/`wd_number`/`username` must not belong to a *different* existing row. Any hit → abort unless `--force`.
4. Confirm target IDs 1–13 in `users` are free or already equal to seed rows.
5. Confirm `user_rentals` FK targets (products 1–4) exist or will be inserted first.

### 10.3 Apply
Executes sections in order; after each section, quick row-count assertion (expected count per §9.2); on failure, roll back that section's transaction and report the offending statement with section banner.

### 10.4 Post-apply verification (always runs)
- **Ledger invariant:** for every user, `users.balance == SUM(credit)−SUM(debit)`; print a table; any mismatch → exit 2.
- **Tree integrity:** no self-parent (`parent_id <> id`), no cycle, max depth 3, `parent_id` rows exist.
- **Counts:** match §4/§5/§6/§8 specs.
- **T+1 scenario:** assert rental #6 and #15 have `days_processed = 0` and `last_claimed_at IS NULL` (claimable-to-test state).
- **Uniqueness:** `phone`, `invite_code`, `invoice_number`, `wd_number` distinct.

---

## 11. Rollout & Rollback Strategy

1. **Backup first:** `mysqldump -u {user} -p db_webtable > backups/pre_seed_{ts}.sql` (any existing dev data is preserved).
2. Run `--verify` on staging; review the reconciliation + conflict report.
3. Run `--apply`; inspect the post-apply report (all invariants green).
4. Smoke-test (checklist §12) against `php -S localhost:8080` or `synapse.test`.
5. **Rollback** = `mysql db_webtable < backups/pre_seed_{ts}.sql` (explicit-ID upsert means the seed is also self-rollback-safe on re-run, but a backup restore is the deterministic path).
6. Never run against a production DB with real users; the seeder is a dev/staging tool.

---

## 12. Post-Seed Smoke-Test Checklist

- [ ] Login `/control-panel` with `admin` / `password` → dashboard shows pending deposit queue + withdrawal queue.
- [ ] `/admin/audit` → 12 actions in filter dropdown, ~16 rows, date-filter + pagination work.
- [ ] Login `081234567890` / `password` → Team page shows B+C tree (Tier 1 + Tier 2 members), Level 1 bonus already claimed.
- [ ] Login `081298765432` (cofounder) → claim Level 1 bonus via `/team/claim-level1` (tree qualifies; bonus Rp 80.000 credited).
- [ ] Login `085798765432` (agent_sari, rental #6 H-0) → attempt ROI claim → **rejected (T+1 rule)**.
- [ ] Login `085712345678` (agent_budi, rental #4 H-2) → ROI claim succeeds; balance +185.000.
- [ ] Wallet page shows full ledger history (deposits, purchases, ROI, WD debit, refund credit).
- [ ] Login `085799001122`… (banned `leaf_lutfi`) → login rejected / `is_banned` gate.
- [ ] Login `087855667788` (agent_eka) → redirected to `/auth/change-password` (must_change_password).
- [ ] Wallet → withdraw: `agent_budi` blocked by single-pending WD; `vipleader` allowed (fee preview 5% + 6.500).
- [ ] Admin → cancel rental / time-travel / toggle ban / reset password → each writes a new `system_audit_logs` row visible in `/admin/audit`.

---

## 13. Credential Cheat-Sheet (quick reference)

**Admin:** `/control-panel` — username `admin` / password `password`

| ID | Username | Phone | Upline | Role | Flags | Password |
|----|----------|-------|--------|------|-------|----------|
| 1 | vipleader | 081234567890 | — (root) | user | L1 claimed | password |
| 2 | cofounder | 081298765432 | — (root) | user | — | password |
| 3 | agent_budi | 085712345678 | 1 | user | — | password |
| 4 | agent_sari | 085798765432 | 1 | user | — | password |
| 5 | agent_dewi | 087811223344 | 1 | user | — | password |
| 6 | agent_eka | 087855667788 | 1 | user | must_change_password=1 | password |
| 7 | sub_fajar | 085723456789 | 3 | user | — | password |
| 8 | sub_gina | 085798112233 | 3 | user | — | password |
| 9 | sub_hadi | 087833445566 | 4 | user | — | password |
| 10 | sub_indah | 087855667799 | 4 | user | — | password |
| 11 | sub_joko | 081277889900 | 5 | user | — | password |
| 12 | leaf_karin | 081288990011 | 7 | user | — | password |
| 13 | leaf_lutfi | 081299001122 | 9 | user | is_banned=1 | password |

All bcrypt hashes: `$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi`.
