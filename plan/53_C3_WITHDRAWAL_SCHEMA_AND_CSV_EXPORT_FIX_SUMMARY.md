# SUMMARY 53 — C3: Withdrawal Schema Drift & Admin CSV Export Fix

- **Plan:** `plan/52_C3_WITHDRAWAL_SCHEMA_AND_CSV_EXPORT_FIX_PLAN.md` (approved).
- **Status:** IMPLEMENTED + VERIFIED on live DB (`db_webtable`, MariaDB 12.3.2).
- **Decisions applied:** dec-30928987d7ae1c74 — half-open fee tiers `[min, max)` (boundary → higher tier; Rp 5.000.000 → 4% → fee Rp 206.500); idempotent backfill + read-side fallback.

---

## 1. Files Changed

| File | Change |
|---|---|
| `application/config/withdrawal_fees.php` | **NEW** — PRD fee tier table (single source): `fixed_fee 6500`, tiers `[100k,500k)=10%`, `[500k,1M)=7.5%`, `[1M,2M)=6.5%`, `[2M,5M)=5%`, `[5M,10M)=4%`, `[10M,50.000.001)=3%` (bps 1000/750/650/500/400/300) |
| `application/models/Wallet_model.php` | `__construct` loads fee config; **`calculate_withdrawal_fee(int $gross)`** (integer IDR math: `floor(gross*bps/10000) + 6500`, returns fee/net/bps); **`create_withdrawal()`** now inserts `amount`, `gross_amount`, `fee_amount`, `net_amount` atomically inside the C5 anchor-locked TX |
| `application/models/Admin_model.php` | `get_all_withdrawals()` → LEFT JOIN `users` (phone) + `bank_accounts` (bank_name/account_number/account_holder) + `gross_eff = COALESCE(NULLIF(gross_amount,0), amount)`; `get_active_rentals()` → LEFT JOIN `users` (phone) + `gpu_products` (`name AS product_name`) |
| `application/controllers/Admin.php` | `export_csv()` refactored to **explicit column maps** per type (order-independent), raw-IDR figures via `_csv_money()`, timestamp format via `_csv_ts()`, and **read-side fee recompute fallback** for legacy rows with fee/net 0/NULL |
| `database.sql` | Canonical `withdrawals` DDL: `+wd_number` (nullable, `uk_wd_number`), `+amount NOT NULL DEFAULT 0.00`, `gross/fee/net_amount ... DEFAULT 0.00` |
| `database_seed.sql` | `[RECONCILE]` ALTERs: `ALTER COLUMN gross/fee/net_amount SET DEFAULT 0.00`; seed row 1 comment + values re-aligned **5jt → 4%** (fee 206500.00, net 4793500.00) |
| `scripts/seed_database.php` | `reconcile_plan()` + `parse_alter()` + new `reconcile_done()`/`column_default_is()` helpers → guarded, idempotent handling of `ALTER COLUMN ... SET DEFAULT` |
| `scripts/backfill_withdrawal_fees.php` | **NEW** — standalone idempotent runner (`--verify/--apply/--check/--no-backup`), mirrors seed runner conventions (mysqli, creds from `application/config/database.php`, backups to `backups/`) |

## 2. Lint (all pass)

```
php -l application/config/withdrawal_fees.php   -> No syntax errors
php -l application/models/Wallet_model.php      -> No syntax errors
php -l application/models/Admin_model.php       -> No syntax errors
php -l application/controllers/Admin.php        -> No syntax errors
php -l scripts/backfill_withdrawal_fees.php     -> No syntax errors
php -l scripts/seed_database.php                -> No syntax errors
```

## 3. Verification — Live DB (`db_webtable`)

### 3.1 Schema reconcile (idempotent)
- Applied guarded `ALTER COLUMN gross/fee/net_amount SET DEFAULT 0.00` on live DB.
- Re-run `php scripts/seed_database.php --verify` → **all 12 reconcile statements report `[skip]`** (including the 3 new defaults), proving guarded idempotence.

### 3.2 Backfill (real legacy data repaired)
- Found 3 legacy rows (ids 5, 6, 7) with `gross/fee/net = 0.00` (created pre-fix — the exact drift from audit C3).
- `php scripts/backfill_withdrawal_fees.php --apply`:
  - id 5: amount 100.000 → gross 100.000, fee 16.500, net 83.500
  - id 6: amount 100.000 → gross 100.000, fee 16.500, net 83.500
  - id 7: amount 1.000.000 → gross 1.000.000, fee 71.500, net 928.500
  - Backup written: `backups/pre_backfill_20260902_140850.sql`
- `--check` → `ALL VALIDATIONS PASSED`; **0 rows with 0/NULL gross/fee/net remain**.
- Seed row id 1 (5jt, old 5% tier 256.500) re-aligned on live DB to 4% (206.500 / 4.793.500) per plan/52 §5.4 → subsequent `--check` shows 0 mismatches.

### 3.3 Test Case 1 — new withdrawal persists all four figures (strict mode) ✅
- Real HTTP flow (`/wallet/process_withdraw`, user id 1, amount Rp 5.000.000 — exact tier boundary).
- Stored row: `amount = 5000000.00`, `gross_amount = 5000000.00`, `fee_amount = 206500.00`, `net_amount = 4793500.00`, `status = pending`, `wd_number = WD-20260902211516-1`.
- Exactly one matching `wallet_ledger` debit (5000000.00). No strict-mode error. **PASS**.
- (Artifact row removed after verification; DB returned to prior state — 7 withdrawals.)

### 3.4 Fee engine unit cases (real model via CI_Model stub) ✅
11/11 PASS, incl. boundaries: 500.000→7.5% (44.000), 1.000.000→6.5% (71.500), 2.000.000→5% (106.500), **5.000.000→4% (206.500/4.793.500)**, 10.000.000→3% (306.500), 50.000.000→3% (1.506.500), plus floor-semantics odd values (499.999→56.499, 4.999.999→256.499).

### 3.5 Test Case 2 — admin CSV exports ✅
Authenticated admin via `/control-panel`, then:
- `GET /admin/export_csv/withdrawals` → **HTTP 200**, `Content-Type: text/csv; charset=utf-8`, UTF-8 BOM, 13-col header (`ID, WD Number, User ID, Phone, Gross (IDR), Fee (IDR), Net (IDR), Bank Name, Account Number, Account Holder, Status, Processed At, Created At`); legacy row exports gross from `amount` fallback; no PHP notices/warnings (checked CI log).
- `GET /admin/export_csv/rentals` → **HTTP 200**, Product Name from `gpu_products`, Phone present.
- `GET /admin/export_csv/ledger` → **HTTP 200**, unchanged shape (raw IDR integers).
- Column count per row == header count (explicit map).

## 4. Notes / Caveats
- Export IDR figures are **raw integers** (no `Rp`, no thousand separators) — machine-readable by design; headers carry `(IDR)` to make that explicit (blueprint's `#` comment-row idea was dropped to keep CSV consumers happy — documented here instead).
- The wallet fee *preview* JS (`application/views/wallet/withdraw.php`) still shows a flat 5% estimate — display-only cosmetic, deferred (noted in plan/52 §5.5 as optional). Authority for fees is server-side only.
- `scripts/seed_database.php` was touched beyond plan/52's file list because its `reconcile_plan()` is the actual guarded ALTER executor for the seed runner; adding defaults there keeps the canonical migration path idempotent (verify shows `[skip]`).
- Full browser E2E of Test Case 1 required forging a dev session (user login is reCAPTCHA fail-closed without env secret); exercised the genuine controller→model→DB path.
- No app test suite exists (repo convention); verification used lint + live-DB HTTP/CLI checks per plan/52 §7.
