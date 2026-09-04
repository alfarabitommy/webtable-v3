# Plan 54 — C4: Users.balance ↔ wallet_ledger Desync Elimination (Blueprint)

**Mode:** PLAN ONLY — architectural blueprint. No application code or schema changed.
**Source finding:** `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` §2-C4 (+ M6, M8, P5 cross-references).
**Date:** (blueprint) — implements step 7 of the audit's P1 action plan.
**Status:** Awaiting approval before implementation.

---

## 1. EXECUTIVE SUMMARY

`wallet_ledger` is already the de-facto authoritative financial store: every balance read
in user-facing code (`Wallet_model::get_balance`, `Wallet_model::lock_and_get_balance`,
`Admin_model::get_user_balance`, `MY_Controller` header balance) computes
Σcredit − Σdebit from it. **The defect is that `users.balance` is a second, mostly-orphaned
copy**: only 2 of 9 money-mutation paths write it, so it drifts from the ledger and is
displayed stale in the Admin user list and Team claim responses.

**Policy decision (recommended):** keep `wallet_ledger` as the **sole source of truth** and
demote `users.balance` to a **high-performance atomic read-cache** that is written **only**
inside the same ACID transaction as its ledger row, via one unified ingestion helper in
`Wallet_model`. Complete deprecation (dropping the column) is rejected: the column already
exists, is `NOT NULL DEFAULT 0.00`, is cheap to keep correct, and removing it would force
every list/header read into a `SUM(GROUP BY)` query or an `IN`-scoped aggregate for no
functional gain. Keeping it as a converged cache also preserves the PRD/ERD contract that
`users.balance` exists.

The blueprint therefore has three deliverables:

1. **D1 — Inventory & desync-point map** (this file §2): every balance read and every money
   mutation, classified ledger-only / dual-write / stale-read.
2. **D2 — Synchronization architecture** (§3): one ingestion helper pair in `Wallet_model`
   (`credit()` / `debit()`), relative cache updates, caller-TX contract, read-side
   canonicalization, stale-display fixes, dead-code removal.
3. **D3 — Idempotent reconciliation runner** (§4): `scripts/reconcile_balances.php`
   (--verify / --apply / --check), mirroring `scripts/backfill_withdrawal_fees.php`
   conventions, plus the verification & test protocol (§5).

---

## 2. DIAGNOSTIC INVENTORY — ALL BALANCE READS & MONEY MUTATIONS

### 2.1 Balance reads

| # | Site | Mechanism | Source of truth | Verdict |
|---|---|---|---|---|
| R1 | `Wallet_model::get_balance()` (L36–48) | `SUM(credit) − SUM(debit)` on `wallet_ledger` | ledger | ✅ authoritative; **casts to `(int)`** (M8 truncation) |
| R2 | `Wallet_model::lock_and_get_balance()` (L69–88) | `SELECT id FROM users ... FOR UPDATE` anchor + ledger SUM in TX | ledger | ✅ authoritative (C5 serialization). Comment L63–64 explicitly documents `users.balance` as unused/stale |
| R3 | `Admin_model::get_user_balance()` (L137–153) | ledger SUM | ledger | ✅ authoritative (used by `Admin::user_detail`) |
| R4 | `MY_Controller` (L38) → `global_balance` | `get_balance()` per request | ledger | ✅ authoritative (header + injected into all views) |
| R5 | `Wallet::index`/`withdraw`, `Rentals::checkout` pre-check, `Marketplace::index` | `get_balance()` | ledger | ✅ authoritative (checkout/withdraw pre-checks are UX-only; the financial gate re-locks inside the TX) |
| R6 | `User_model::get_claim_data()` (L230) `'balance' => $user->balance` | direct column | **stale cache** | ❌ returns drifted `users.balance`; currently unused by `team/index.php` (0 references) but is a trap |
| R7 | `Team::claim_level1()` (L77–78) `$result['new_balance'] = $user->balance` | direct column | **stale cache** | ❌ wrong post-claim balance to the user; contrast `Team::claim_wage()` (L134–135) which already uses `get_balance()` ✅ |
| R8 | `Admin_model::get_users()` (L106) selects `u.balance` → `admin/users.php` (L85–86) | direct column | **stale cache** | ❌ admin user list shows drifted balance; `user_detail` on the same page shows the ledger-correct value — self-contradictory UI |
| R9 | `Ledger_model::insert_transaction()` (L24) `SELECT balance FROM users ... FOR UPDATE` | direct column | dead code | ❌ reads then writes absolute balance from a stale read — the exact anti-pattern to avoid |
| R10 | Views `wallet/index.php`, `wallet/withdraw.php`, `marketplace`, `header.php` | `$balance` / `global_balance` from controllers | ledger | ✅ (R4/R5 sources) |

**No session-cached balance exists** — `global_balance` is per-request `load->vars()`, not a
session write. Good: nothing to invalidate.

### 2.2 Money mutation paths (writes)

| # | Path (file:line) | Ledger write | `users.balance` write | Classification |
|---|---|---|---|---|
| W1 | Deposit approve — `Admin::approve_deposit` (L106–112) | credit `invoice_number` | — | ⚠️ split-brain (ledger-only) |
| W2 | Deposit simulator — `Wallet_model::approve_deposit_simulator` (L136–142) | credit `invoice_number` | — | ⚠️ split-brain (ledger-only) |
| W3 | Rental checkout — `Rental_model::checkout_rental` (L89–95) | debit `RENT-…` | — | ⚠️ split-brain (ledger-only) |
| W4 | ROI claim — `Rental_model::claim_roi` (L264–270) | credit `ROI-…` | — | ⚠️ split-brain (ledger-only) |
| W5 | Withdrawal request — `Wallet_model::create_withdrawal` (L222–228) | debit `WD-…` | — | ⚠️ split-brain (ledger-only) |
| W6 | WD decline/refund — `Admin::decline_withdrawal` (L200–206) | credit `wd_number` | — | ⚠️ split-brain (ledger-only) |
| W7 | Admin injection — `Admin_model::inject_balance` (L258–264) | credit/debit `ADM-…` | — | ⚠️ split-brain (ledger-only) |
| W8 | L1 bonus — `User_model::claim_level1` (L263–274) | credit `L1-…` | `balance = balance + 80000` (relative) | ✅ dual-write (one of only two synced paths) |
| W9 | Weekly wage — `User_model::claim_wage` (L392–404) | credit `WAGE-…` | `balance = balance + ?` (relative) | ✅ dual-write |
| W10 | WD approve — `Admin::approve_withdrawal` (L154), simulator (Wallet_model L281–284) | none (funds debited at request) | — | ✅ no money move; status flip only — must **never** gain a ledger/balance write |
| — | `Admin_model::inject_rental` (L283–303) | **none by design** (view: "TIDAK akan mendebit balance") | — | ✅ balance-neutral testing bypass; no ledger row, no cache write → cannot desync the *cache* (affects treasury semantics, out of C4 scope) |

**Root cause confirmed:** W1–W7 write only `wallet_ledger`; W8–W9 are the only dual writers.
The audit's "updated only by claim_level1/claim_wage" matches the code exactly. `users.balance`
for any user whose last activity is deposit/checkout/ROI/WD/refund/injection is frozen at
registration (`DEFAULT 0.00`) or at the last W8/W9 event.

### 2.3 Schema & dead-code facts

- `users.balance` — `DECIMAL(15,2) NOT NULL DEFAULT 0.00` (`database.sql` L17). Registration
  inserts no balance → 0.00, consistent with an empty ledger. Seed sets balances as constants
  on the `users` INSERT with the comment "users.balance == SUM(credit) − SUM(debit) holds by
  construction" — **no runtime or seed-end re-sync exists**.
- `wallet_ledger` — no `UNIQUE` on `transaction_id` (C2's suggested unique key would conflict:
  a declined WD legitimately carries **two** rows with the same `transaction_id` — debit `WD-x`
  at request + credit `WD-x` at refund). Any dedupe hardening must be `UNIQUE (transaction_id, type)`
  or scoped, and is a separate follow-up (M4/C1 are already gated by conditional transitions).
- `Ledger_model` + `transactions` table (`database.sql` L76) — **zero callers**, never written.
  Dead code; its absolute-balance read-modify-write pattern (R9) is the race anti-pattern C5
  eliminated elsewhere and must not be revived.

---

## 3. SYNCHRONIZATION ARCHITECTURE (implementation phase, post-approval)

### 3.1 Policy

1. **`wallet_ledger` = sole authoritative source** for every balance (read & reconcile).
2. **`users.balance` = atomic read-cache**, written **only** by the ingestion helper below,
   in the **same transaction** as its ledger row, with **relative** increments
   (`balance = balance ± amount`). Relative updates converge under concurrency by
   construction — absolute writes from stale reads (Ledger_model style) are forbidden.
3. **All money moves are ledger-row + cache-row pairs** — one helper call, no exceptions,
   enforced by code review + a grep guard (`assert_no_raw_ledger_writes` dev script).
4. One-time legacy drift is repaired by the reconciliation runner (§4), then kept at zero by
   the invariant + nightly `--check`.

### 3.2 Ledger ingestion helper — `Wallet_model` (new)

Because business writes (deposit status, withdrawal row, rental row, wage stamp) must commit
**atomically with** their money move, the helper is a **caller-TX participant**, not a
self-committing service: the caller already opened the TX (`trans_begin()` + anchor lock,
the C5/C6 pattern) and the helper performs ledger insert + relative cache update inside it.

```php
// Inside Wallet_model. NO trans_begin/commit here — caller owns the TX.
// Must be called only after the caller has serialized the user row
// (lock_and_get_balance / SELECT ... FOR UPDATE), and only once per row per TX.
public function credit($user_id, $amount, $transaction_id, $description) { return $this->_post('credit', ...); }
public function debit ($user_id, $amount, $transaction_id, $description) { return $this->_post('debit',  ...); }

private function _post($type, $user_id, $amount, $transaction_id, $description) {
    $amount = (float) $amount;                       // IDR, integer-valued (M8 discipline)
    $ok = $this->db->insert('wallet_ledger', [...]); // 1 ledger row
    if ($ok) {
        $sign = ($type === 'credit') ? '+' : '-';
        $this->db->query("UPDATE users SET balance = balance $sign ? WHERE id = ?", [$amount, $user_id]);
        $ok = $this->db->affected_rows() === 1;      // cache write failure ⇒ caller rolls back everything
    }
    return $ok;
}
```

Contract details to encode in the method docblock:
- **Caller must already hold the `users` row lock** (all current debit paths do via
  `lock_and_get_balance`; credit paths claim_level1/claim_wage lock via their own
  `SELECT ... FOR UPDATE`; deposit-approve/refund/injection currently don't — see §3.3).
- Helper **never** opens/commits a TX, **never** writes absolute balance, **never** re-reads
  balance. Returns `false` on ledger-insert or cache-update failure so the caller rolls back
  its whole TX (business row + audit + notification stay consistent).
- `transaction_id` generation stays at each call site (existing deterministic schemes
  `INV-* / RENT-* / ROI-*-D* / WD-* / L1-* / WAGE-* / ADM-*` are preserved and unchanged).

### 3.3 Rewiring matrix (one edit per writer)

| Writer | Change |
|---|---|
| W1 `Admin::approve_deposit` | open explicit TX (or keep `trans_start`), **lock anchor user first** (`lock_and_get_balance`), conditional `UPDATE deposits SET status='success' WHERE id=? AND status='pending'`, then `credit()` — gate credit on `affected_rows()===1` (closes M4 double-click too) |
| W2 `approve_deposit_simulator` | same conditional-transition + lock + `credit()` |
| W3 `checkout_rental` | replace inline ledger insert (L89–95) with `debit()` (already inside locked TX) |
| W4 `claim_roi` | replace inline credit insert (L264–270) with `credit()` (already inside locked TX) |
| W5 `create_withdrawal` | replace inline debit insert (L222–228) with `debit()` (already inside locked TX) |
| W6 `decline_withdrawal` | add anchor lock + `credit()` (refund) — refund `transaction_id` stays `wd_number` |
| W7 `inject_balance` | add anchor lock + `credit()/debit()` |
| W8 `claim_level1` | **delete** inline `balance + 80000` update (L271–274); replace ledger insert with `credit()` — cache now synced by helper |
| W9 `claim_wage` | **delete** inline `balance + ?` update (L401–404); replace ledger insert with `credit()` |
| W10 `approve_withdrawal` (+ simulator) | **no change** (no money move) — add regression note only |

Result: every writer produces exactly one ledger row + one relative cache write in one TX.
Anchoring (W1/W2/W6/W7 previously unlocked) is required before the relative cache update so
two concurrent writers on the same user serialize — the C5 discipline extended to credits.

### 3.4 Read-side canonicalization & stale-display fixes

Single accessor rule: **user-facing code must never read `users.balance` directly.**

- R8 `Admin_model::get_users()` — replace `u.balance` with a ledger aggregate in the same
  query (page = 50 rows): `LEFT JOIN (SELECT user_id, SUM(CASE WHEN type='credit' THEN amount ELSE -amount END) AS balance FROM wallet_ledger GROUP BY user_id) l ON l.user_id = u.id`
  and select `COALESCE(l.balance, 0) AS balance`. Independent of cache convergence → admin
  list and `user_detail` (R3, ledger-based) can never contradict each other. *(Optimization
  later, once the invariant + nightly --check prove convergence: switch this join back to
  `u.balance`.)*
- R6/R7 Team — `get_claim_data()` returns ledger balance (`Wallet_model::get_balance($user_id)`)
  or drops the key; `Team::claim_level1()` sets `new_balance` from `get_balance()` exactly like
  `claim_wage()` already does (L134–135). Fix the view key mismatch: `team/index.php` L1 JS
  (L378) reads `d.balance` but the endpoint returns `new_balance` → unify both claim handlers
  and both JS branches on one key (`new_balance`), keeping `d.balance` for backward compat
  one release or removing it.
- R1 `get_balance()` `(int)` cast — keep return as-is for whole-IDR flows but do **not** widen
  scope to M8 here; the reconciliation compare is decimal-exact in SQL (below), immune to PHP
  truncation. Note M8 as prerequisite hardening for any future fractional-amount feature.
- R9 dead code — delete `Ledger_model::insert_transaction()` and the never-written
  `transactions` writer path, or convert `Ledger_model` into a thin facade over
  `Wallet_model::credit/debit` if the PRD double-entry decision (M6) later requires a mirror.
  Minimal C4-scope action: **remove the dead method** so the stale absolute-balance pattern has
  no in-repo exemplar; leave the M6 double-entry decision to its own plan.
- Enforce: grep guard — no `insert('wallet_ledger'` and no `UPDATE users SET balance`
  outside `Wallet_model`. A tiny dev check `scripts/assert_ledger_policy.php` (or a grep in
  CI notes) fails if any writer bypasses the helper.

---

## 4. RECONCILIATION RUNNER — `scripts/reconcile_balances.php`

Mirrors `scripts/backfill_withdrawal_fees.php` conventions: standalone CLI, `BASEPATH`
sentinel, DB credentials parsed from `application/config/database.php`, automatic backup
dump (`backups/pre_reconcile_*.sql`, skipped with `--no-backup`), idempotent, documented exit
codes.

### 4.1 CLI contract

```
Usage: php scripts/reconcile_balances.php [--verify|--apply|--check] [--no-backup] [--json]
  --verify    (default) dry-run: detect & quantify drift; writes NOTHING
  --apply     repair users.balance = ledger aggregate, atomically, with backup
  --check     post-apply validation only (expect zero drift)
  --no-backup skip automatic backup dump
  --json      machine-readable summary on stdout (for cron/nagios)
Exit codes: 0 = no drift / clean, 1 = pre-flight failure (no DB, no config),
            2 = apply failure (TX rolled back), 3 = drift still present after --check.
```

### 4.2 Detection query (decimal-exact, no float dust)

```sql
-- drifted accounts: cache vs ledger aggregate, compared in DECIMAL
SELECT u.id, u.balance AS cache_balance,
       COALESCE(l.ledger_balance, 0) AS ledger_balance
FROM users u
LEFT JOIN (
    SELECT user_id,
           COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) AS ledger_balance
    FROM wallet_ledger
    GROUP BY user_id
) l ON l.user_id = u.id
WHERE u.balance <> COALESCE(l.ledger_balance, 0);   -- exact DECIMAL compare; NULL cache default 0
```

### 4.3 Report (--verify)

- Totals: scanned users, drifted count, sum of |drift|, max |drift|, drift by sign
  (cache higher vs lower than ledger).
- Per-user table: `user_id`, `cache_balance`, `ledger_balance`, `delta`
  (optionally `--json` for the full list).
- Exit 0 when zero drift; exit 3 when drift found in verify mode too (so cron/CI fails loud),
  with the report still printed. (Rationale: keep a single "drift exists" code across modes.)

### 4.4 Repair (--apply) — atomic & race-safe

1. Pre-flight: DB reachable; backup dump unless `--no-backup`.
2. `trans_begin()`; recompute drifted list (same query).
3. Per drifted user, in ascending id order:
   - `SELECT id FROM users WHERE id = ? FOR UPDATE` (serialize concurrent money moves),
   - re-aggregate that user's ledger SUM (consistent read **after** lock wait — the
     `lock_and_get_balance` pattern),
   - if still drifted: `UPDATE users SET balance = ? WHERE id = ?` (absolute write is safe
     here: row is locked, value is the authoritative aggregate, and post-invariant writers
     only add relative deltas on top of it).
4. Commit; exit 0. Any failure → rollback, exit 2. Idempotent: a re-run finds nothing.

### 4.5 Operations

- Run once after deployment (legacy drift repair), then schedule `--check` nightly
  (e.g. cron `05 00 * * * php scripts/reconcile_balances.php --check --json` → alert on exit 3)
  as the drift canary that proves the §3 invariant holds in production.

---

## 5. VERIFICATION & TESTING PROTOCOL (implementation phase)

Static & flow tests per repo convention (AGENTS.md):

1. **Static:** `php -l` on every touched file (models, controllers, script). Grep-guard:
   no `insert('wallet_ledger'` / `UPDATE users SET balance` outside `Wallet_model`.
2. **Test 1 — baseline drift:** against the dev/live DB run
   `php scripts/reconcile_balances.php --verify` and capture the drifted-account report as the
   pre-fix baseline (expected: every user with deposit/ROI/WD/checkout activity but no
   W8/W9 event shows drift ≈ that activity's net). Record the numbers in the phase summary.
3. **Test 2 — end-to-end sync:** after rewire, `--apply` once (drift → 0), then walk one full
   money lifecycle via `php -S localhost:8080` + curl (HTTP 200/302): register → topup →
   deposit approve (simulator) → marketplace checkout → ROI claim (T+1, 2-day cap) → L1 claim
   (3 active B downlines + ≥330rb) → wage claim → withdrawal request → admin approve **and**
   a second decline/refund. After **each** step run `--verify` → must report 0 drift, and the
   displayed balances (wallet page, admin list vs user_detail, claim responses) must match.
4. **Regression — race guards untouched:** repeat the C2/C6 concurrency probes (parallel
   claim_roi / claim_wage POSTs) and C5 overspend probes from plans 44/48/50 — fixes must
   still hold with the helper in place (helper adds no lock of its own; anchor lock remains
   caller-side).
5. **Idempotency:** run `--apply` twice — second run must be a no-op (exit 0, 0 rows).

**Environment note:** Tests 1–2 require a reachable MySQL `db_webtable` (sandbox had no
running DB during planning); they execute in the implementation phase on the dev DB.

---

## 6. SCOPE BOUNDARIES (explicitly NOT in this plan)

- M6 double-entry `transactions` ledger revival (separate PRD/ERD decision) — only dead-code
  removal of the stale pattern is included.
- M8 money-type (int-IDR) standardization — referenced as prerequisite, not implemented here.
- M1 fee/window/max, M2 wage-tier PRD drift, M3 rental-expiry cron — separate plans.
- Treasury/`inject_rental` bypass semantics — balance-neutral; flagged only.
- C1/C2/C5/C6/C7 fixes already shipped (plans 38/44/48/50/42) — regression-tested, not redone.

---

## 7. DELIVERABLE CHECKLIST (implementation phase)

- [ ] `Wallet_model::credit()/debit()/_post()` added (caller-TX contract documented).
- [ ] W1–W9 rewired to the helper; inline `wallet_ledger` inserts and W8/W9 `balance + X`
      updates removed; anchor locks added where missing (W1/W2/W6/W7).
- [ ] `Admin_model::get_users()` ledger-aggregate join; `admin/users.php` unchanged (receives
      corrected `balance`).
- [ ] Team claims return ledger `new_balance`; `team/index.php` JS key unified;
      `get_claim_data()` no longer exposes stale `$user->balance`.
- [ ] Dead `Ledger_model::insert_transaction` removed (or facade-ized per M6 decision).
- [ ] `scripts/reconcile_balances.php` (--verify/--apply/--check/--json/--no-backup) shipped.
- [ ] `php -l` all touched files; Test 1 baseline + Test 2 lifecycle sync + race regressions;
      double-`--apply` idempotency; phase summary records drift counts.
