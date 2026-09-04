# SUMMARY 57 — M1: Withdrawal Operational Rules & Dynamic Financial Configuration

- **Status:** EXECUTED per `plan/56_M1_OPERATIONAL_RULES_AND_DYNAMIC_FEES_PLAN.md` (approved blueprint). Implementation complete; DB-backed HTTP test cases documented for the operator (no local MySQL in the execution sandbox).
- **Scope delivered:** dynamic financial config (fallback-first), WIB timezone harmonization, operational + min/max gating inside the withdrawal TX, admin financial-rules UI, user-facing fee preview overhaul.
- **Out of scope (unchanged):** M7 settings-store consolidation, M6 double-entry ledger, persisted deposit-fee accounting, gating admin *approval* of withdrawals by the window (submission-only).

---

## 1. Code & Config Changes

| File | Change |
|---|---|
| `application/config/withdrawal_fees.php` | Full fallback object: `operational_days '1,2,3,4,5,6'`, `open_time '07:00'`, `close_time '19:00'`, `fixed_fee 6500`, half-open `tiers`, `min_amount 100000`, `max_amount 50000000`, `deposit_fee_enabled 0`, `deposit_fee_type flat`, `deposit_fee_value 0` (PRD §121–125 defaults; never mutated by app). |
| `application/models/Wallet_model.php` | `__construct` runs `SET time_zone = '+07:00'`; removed static `$_wd_fees`. Added `get_financial_config()` (per-request static cache, `system_settings` dynamic → file fallback per-key), normalizers `_norm_days_csv/_norm_time/_norm_int/_norm_pct/_norm_tiers`, tiers↔bounds **coherence bundle** (violation → atomic fallback of both), `validate_financial_settings()` (admin POST validator + save-time rules), `calculate_deposit_fee()` (flat / percent-points, floor), `withdrawal_operational_status()` (WIB day `N` + `HH:MM` window, inclusive close). `calculate_withdrawal_fee()` now reads the merged dynamic config. `create_withdrawal()` gates inside the locked TX: `closed_day`/`closed_time`/`below_min`/`above_max` before the overspend check. `has_reached_daily_wd_limit()` rewritten: PHP-bound `date('Y-m-d')` (WIB 00:00–23:59), no MySQL `CURDATE()`; counts `pending/processing/success` (failed/declined does not consume quota). |
| `application/models/Admin_model.php` | `__construct` runs `SET time_zone = '+07:00'` (WIB session for reads/exports). |
| `application/controllers/Wallet.php` | `index()` injects dynamic deposit-fee config and enriches pending invoices (`deposit_fee`, `total_payable`). `withdraw()` (GET) injects `wd_config`/`wd_open`/`wd_code` — renders the page with disabled state + notice when closed (no redirect; server stays authority). `process_withdraw()` mirrors operational + dynamic min/max pre-checks and maps `insufficient|below_min|above_max|closed_day|closed_time` back to the form. |
| `application/views/wallet/withdraw.php` | Flat-5% JS **removed**. Server `WD_CONFIG` (days/window/fixed/tiers/min/max); real-time half-open tier preview (`fee = floor(amount*bps/10000) + fixed`), tier % label, net payout; WIB-aware disable + informative notice computed via `Intl.DateTimeFormat('Asia/Jakarta')`, 30 s refresh. |
| `application/views/wallet/index.php` | Top-up panel shows dynamic deposit fee breakdown (`Pokok + Biaya = Total Dibayar`) before invoice creation; pending invoice cards show `Total dibayar`. `Wallet::topup()`/ledger unchanged → credit stays pure principal (zero dilution). |
| `application/controllers/Admin.php` | `financial_settings()` (GET render / POST save): loads `Wallet_model`, validates via `validate_financial_settings()`, persists atomically via `Admin_model::set_setting()` per key + `Audit_model::log_admin_action('admin_update_financial_settings')`, redirect-after-POST with flashdata. |
| `application/views/admin/financial_settings.php` | **New.** 3 sections: Jam Operasional (7 day checkboxes + time inputs), Biaya Penarikan (fixed fee, min/max, dynamic tier editor serializing `[[min,max,bps]]` JSON with client checks), Biaya Deposit (toggle/type/value). |
| `application/config/routes.php` | `$route['admin/financial-settings'] = 'admin/financial_settings';` |
| `application/views/admin/templates/sidebar.php` | "Aturan Finansial" nav link (active state on segment 2). |
| `database.sql` + `database_seed.sql` | `system_settings` seed extended with the 10 M1 keys (`INSERT IGNORE`, idempotent, never overwrites live values): `wd_operational_days`, `wd_open_time`, `wd_close_time`, `wd_fixed_fee`, `wd_fee_tiers`, `wd_min_amount`, `wd_max_amount`, `deposit_fee_enabled`, `deposit_fee_type`, `deposit_fee_value`. |

No schema migration beyond settings rows; no ledger/billing change (deposit fee is display/metadata; wallet credit = principal only).

## 2. Lint Results

`php -l` on all touched/new PHP files — **all pass (SWEEP_OK):**
`application/config/withdrawal_fees.php`, `application/config/routes.php`, `application/models/Wallet_model.php`, `application/models/Admin_model.php`, `application/controllers/Wallet.php`, `application/controllers/Admin.php`, `application/views/wallet/withdraw.php`, `application/views/wallet/index.php`, `application/views/admin/financial_settings.php`, `application/views/admin/templates/sidebar.php`.

## 3. Verification Performed

Logic-level checks (pure PHP, WIB clock) — **passed**:
- Fee math from the real fallback config reproduces the approved plan/52 samples: Rp 5.000.000 → fee **206.500** (4%), Rp 500.000 → **44.000** (7,5%), Rp 1.000.000 → **71.500** (6,5%), Rp 10.000.000 → **306.500** (3%).
- Seed `wd_fee_tiers` JSON decodes OK (6 half-open tiers).
- Window/day gating boundaries: Mon 06:59 → `closed_time`, 07:00 → `open`, 19:00 → `open` (inclusive close), 19:01 → `closed_time`, Sunday 12:00 → `closed_day`.
- Daily-limit boundary: PHP `date('Y-m-d')` under `Asia/Jakarta` classifies a 00:30 WIB withdrawal on the correct WIB calendar day (independent of a UTC DB session, which the `SET time_zone = '+07:00'` harmonization also covers).

DB-backed HTTP tests could **not** be executed here — no local MySQL server in the sandbox (`mysqli` connect: "No such file or directory"). Operator checklist (from plan/56 §5):
- **Test Case 1 (window/day):** `admin/financial-settings` set days `1,2,3,4,5`; POST `wallet/process_withdraw` on Saturday → `closed_day` rejection + flashdata; inside window Monday passes to existing gates; settings mutation applies immediately (per-request cache).
- **Test Case 2 (daily limit 00:00 WIB):** with DB session UTC, insert WD at 00:30 WIB (17:30 UTC prev day); `has_reached_daily_wd_limit` at 00:31 WIB → `true`; 2026-06-09 23:59:59 → `true`; 2026-06-10 00:00:01 → `false`.
- **Test Case 3 (admin mutation):** change tier JSON + window via `admin/financial-settings`; verify `withdraw.php` preview/POST math and rejection window shift immediately (model + browser HTTP 200/302 + flashdata).

## 4. Caveats / Notes

- Work executed on an already-dirty working tree (pre-existing uncommitted Phase 10D/other changes present before this task); this summary covers only the M1 deltas above.
- Deposit-fee payable on pending invoices is recomputed from current config at render (deterministic; approved in plan/56 §4.4 as display/metadata only — no `deposits.fee_amount` snapshot).
- Operational gating applies to **withdrawal submission** only; admin approval flow untouched.
- Percent deposit fee value is in percentage points (`0.70` = 0.70%); `wd_fee_tiers` bps scale (`1000` = 10%).
