# Plan 55 — C4: Balance Desync & Ledger Reconciliation — EXECUTION SUMMARY

**Implements:** `plan/54_C4_BALANCE_DESYNC_AND_LEDGER_RECONCILIATION_PLAN.md` (approved).
**Mode:** execution — application code + reconciliation runner changes, DB data repair.
**Scope:** eliminate `users.balance` ↔ `wallet_ledger` desynchronization (audit C4, plan/37 §2-C4).
**Date:** 2026-09-02. **Executor:** Principal Financial Backend Architect.

---

## 1. VERIFICATION HEADLINE

| Check | Result |
|---|---|
| `php -l` on all 8 touched PHP files | ✅ ALL LINT OK |
| Grep-guard (raw ledger/balance writes outside `Wallet_model`) | ✅ 0 hits |
| Baseline drift (`--verify`, pre-fix) | ⚠️ **4 drifted accounts**, Σ\|Δ\| = **Rp 6.325.000** (cache understated) |
| `--apply` | ✅ 4/4 repaired in one TX, backup written |
| `--check` (post-apply) | ✅ **0 drifted accounts** (exit 0) |
| Idempotency (second `--apply`) | ✅ no-op, exit 0 |
| `--json` contract | ✅ clean machine-readable output |

---

## 2. ARCHITECTURE AS-BUILT

- **`wallet_ledger` = single source of truth** (Σcredit − Σdebit).
- **`users.balance` = atomic read-cache**, written **only** by the new ingestion
  helper in `Wallet_model`, in the **same TX** as its ledger row, with **relative**
  increments (`balance = balance ± ?`), gated on `affected_rows() === 1`.
- All 9 money mutation paths (W1–W9) now route through `Wallet_model::credit()` /
  `debit()`; **no direct `wallet_ledger` INSERT or `users SET balance` exists outside
  `Wallet_model`** (verified by grep guard — full encapsulation, including the
  Admin controller money flows which were moved into `Admin_model`).
- One-time legacy drift repaired by `scripts/reconcile_balances.php --apply`.

---

## 3. FILE CHANGES

### New helper — `application/models/Wallet_model.php`
- Added C4 *ledger ingestion service*: `credit($user_id, $amount, $transaction_id,
  $description)`, `debit(...)` → private `_post($type, ...)`.
  - Inserts 1 `wallet_ledger` row, then `UPDATE users SET balance = balance ± ? WHERE id = ?`;
    returns `false` if the ledger insert fails **or** `affected_rows() !== 1` so the caller
    rolls back its whole TX. Caller-TX participant: never opens/commits its own TX,
    never writes absolute balances. Amount 0 → ledger row only (cache unchanged).
- **W2** `approve_deposit_simulator()`: converted to explicit TX; anchor lock
  (`lock_and_get_balance`) → conditional `pending→success` (affected_rows gate) →
  `credit()`.
- **W5** `create_withdrawal()`: inline debit insert replaced by `debit()` (rollback on false).

### `application/models/Admin_model.php`
- **W1** `approve_deposit($id, $audit)` (new ACID): anchor lock → conditional
  `status='pending'→success` → `credit()` → audit — one TX. Returns `{success, deposit,
  message}` so the controller needs no DB query.
- **W10** `approve_withdrawal($id, $audit)` (new ACID): conditional status flip only
  (no money move, M4 double-submit gate added).
- **W6** `decline_withdrawal($id, $audit)` (new ACID): anchor lock → conditional
  `pending→failed` → refund `credit()` (transaction_id stays `wd_number`) → audit.
- **W7** `inject_balance()`: rewired — anchor lock + `credit()/debit()` + audit, explicit TX.
- `get_users()`: `balance` now from the `wallet_ledger` aggregate
  (`LEFT JOIN` derived table, `COALESCE(l.balance,0)`), so the admin user list always
  matches `user_detail` (`get_user_balance`) — no more stale `u.balance` display.

### `application/controllers/Admin.php`
- `approve_deposit` / `approve_withdrawal` / `decline_withdrawal` are now thin HTTP
  delegates: call the `Admin_model` ACID methods (audit context passed), then fire the
  notification + flashdata on success. **No direct `$this->db` money queries remain in
  the controller** (the three flows previously opened their own TX and inserted
  `wallet_ledger` inline).

### `application/models/Rental_model.php`
- **W3** `checkout_rental()`: debit via `Wallet_model::debit()` (rollback on false).
- **W4** `claim_roi()`: credit via `Wallet_model::credit()` (rollback on false) —
  deterministic `ROI-{id}-D{seq}` transaction_id preserved.

### `application/models/User_model.php`
- Constructor loads `Wallet_model` (CLI/cron-safe).
- **W8** `claim_level1()`: rewritten as explicit TX — `SELECT ... FOR UPDATE` anchor →
  eligibility re-check on the locked row → atomic `is_level_1_claimed` conditional flag →
  `credit()`. **Manual `balance + 80000` update deleted.**
- **W9** `claim_wage()`: ledger insert + manual `balance + ?` update replaced by a single
  `credit()` call gated on rollback.
- `get_claim_data()`: `balance` now `Wallet_model::get_balance()` (fresh ledger) instead
  of stale `$user->balance`.

### `application/controllers/Team.php` + `application/views/team/index.php`
- `claim_level1()` returns fresh `new_balance` from `Wallet_model::get_balance()`
  (was stale `$user->balance`; parity with `claim_wage`).
- Team view `claimLevel1()` JS now reads `d.new_balance` (was `d.balance` — the key
  mismatch meant the header balance never refreshed after an L1 claim).

### Dead code — `application/models/Ledger_model.php`
- **Deleted** (git working-tree deletion). Its only method `insert_transaction()` was the
  stale absolute-balance read-modify-write with **zero callers**; removing it eliminates
  the anti-pattern exemplar. `Wallet_model` docblock now names it as the forbidden pattern.
  (`transactions` table untouched — the M6 double-entry decision remains a separate plan.)

### New CLI — `scripts/reconcile_balances.php`
- Mirrors `scripts/backfill_withdrawal_fees.php` conventions: standalone mysqli runner,
  credentials from `application/config/database.php` (`load_db_config`), PHP backup dump
  `backups/pre_reconcile_*.sql`, documented exit codes.
- Flags: `--verify` (default), `--apply`, `--check`, `--json`, `--no-backup`, `--help`.
- Drift query is **exact-DECIMAL**: `u.balance <> COALESCE(SUM(credit) − SUM(debit), 0)`
  per user (no float dust).
- `--apply` repair is race-safe: session `READ COMMITTED` (every consistent read sees the
  latest commit) + per-user `SELECT ... FOR UPDATE` + ledger re-aggregate **after** the
  lock wait + `UPDATE users SET balance = ?` — one transaction, idempotent.
- Exit codes: `0` clean · `1` pre-flight failure · `2` apply failure · `3` drift present
  (verify/check fail loud for cron/CI).

---

## 4. RECONCILIATION OUTPUT (db_webtable)

### Before (`--verify`, baseline)
```
Scanned users: 29
Drifted accounts (users.balance <> ledger): 4
Sum |delta|: Rp 6.325.000   Sum delta: Rp -6.325.000
  user_id          cache         ledger          delta
  3          1385000.00     4570000.00    -3185000.00
  8           520000.00     2560000.00    -2040000.00
  12           40000.00      140000.00     -100000.0
  13               0.00     1000000.00    -1000000.00
```
→ Matches the audit's predicted drift class exactly: users whose money moved through
ledger-only paths (deposit/ROI/WD) had frozen, understated `users.balance`.

### After (`--apply` → `--check`)
```
Backup written: backups/pre_reconcile_20260902_153533.sql
Repairing 4 account(s) in one transaction (per-user lock + re-aggregate)... commit OK
[PASS] 0 drifted accounts remain after --apply.        → EXIT 0
--check → CHECK PASSED — 0 drifted accounts remain.     → EXIT 0
second --apply → Nothing to reconcile.                  → EXIT 0 (idempotent)
```

---

## 5. LINT & ENCAPSULATION PROOF

- `php -l` clean on: `application/models/Wallet_model.php`, `Admin_model.php`,
  `Rental_model.php`, `User_model.php`, `application/controllers/Admin.php`, `Team.php`,
  `application/views/team/index.php`, `scripts/reconcile_balances.php`.
- Grep guard across `application/` (excluding `Wallet_model.php`) for
  `insert('wallet_ledger'`, `update('wallet_ledger'`, `SET balance`, `set('balance'`,
  manual `balance ± ` : **0 hits** — every money mutation is inside
  `Wallet_model::_post()`.
- `$this->db` money usage in `Wallet.php` / `Team.php` controllers: 0.

---

## 6. DEFERRED / RECOMMENDED QA (not executed in this environment)

- **HTTP end-to-end flow test** (plan/54 §5 Test 2): register → topup → deposit approve →
  checkout → ROI claim → L1/wage claim → withdrawal request → admin approve & decline,
  with `reconcile_balances.php --verify` after each step and the UI balance displays
  checked. Requires a running web server (`php -S` + `synapse.test` rewrite) + reCAPTCHA
  dev bypass; not available in this sandbox. Run in the dev environment before production.
- C2/C5/C6 race regressions (concurrent claim/withdraw/checkout probes) — helpers add no
  locks of their own; anchor locks remain caller-side, so prior race fixes are preserved
  by construction, but the probes from plans 44/48/50 should be re-run in QA.
- Nightly cron: `php scripts/reconcile_balances.php --check --json` → alert on exit 3.

---

*End of report — plan/55_C4_BALANCE_DESYNC_AND_LEDGER_RECONCILIATION_SUMMARY.md.*
