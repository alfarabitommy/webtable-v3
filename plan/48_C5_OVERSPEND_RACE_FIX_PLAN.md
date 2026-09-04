# 48 — C5 OVERSPEND / BALANCE RACE FIX PLAN (Withdrawal & Checkout)

**Vulnerability:** C5 — "Balance check outside TX → withdrawal & checkout overspend race" (plan/37_FULL_SYSTEM_AUDIT_REPORT.md §2-C5, CRITICAL).
**Mode:** 🔵 PLAN ONLY. No application code or schema was modified to produce this document.
**Date:** 2026-09-02. **Author:** Principal Financial Backend Architect / DB Concurrency Specialist / CI3 Auditor.

---

## 0. EXECUTIVE SUMMARY

The authoritative balance check for both money-debit paths runs **outside** the database transaction and the debit insert is guarded by **no row lock**. Under MySQL REPEATABLE READ (InnoDB default), the pre-TX `get_balance()` SUMs are *consistent (snapshot) reads*: two concurrent requests for the same user can both observe the same pre-debit balance, both pass `balance >= amount`, and both insert debits → **balance (Σcredit − Σdebit on `wallet_ledger`) goes negative**.

**The fix:** move the authoritative verification *inside* the explicit transaction, serialize per-user debit paths with a **pessimistic row lock on the `users` anchor row** (`SELECT id FROM users WHERE id = ? FOR UPDATE`, first statement of the TX), recompute the balance fresh from `wallet_ledger` **after** the lock is acquired, and **rollback + clean `insufficient` rejection** when `fresh_balance < required_amount`.

This mirrors the pattern already proven in this codebase: `Rental_model::claim_roi()` (`SELECT ... FOR UPDATE` + explicit `trans_begin/commit/rollback` + `affected_rows()` gates) and the (currently dead) `Ledger_model::insert_transaction()` (`SELECT balance FROM users WHERE id = ? FOR UPDATE`).

---

## 1. VULNERABILITY CONTEXT (from audit §2-C5)

> Files: `application/controllers/Wallet.php` (`process_withdraw`), `application/controllers/Rentals.php` (`checkout`).
> Issue: `get_balance($user_id) >= $amount` runs before `trans_start()`; the debit is inserted with no `FOR UPDATE` on the user row and no conditional guard. Two concurrent withdrawals (or two checkouts) can both pass the check → double debit → negative balance. `Ledger_model` already demonstrates the correct pattern (`SELECT ... FOR UPDATE`) — it's just not used here.
> Fix: move the balance check inside the TX with `SELECT balance FROM users ... FOR UPDATE` (or `SUM(wallet_ledger) ... FOR UPDATE` / a conditional debit) and roll back if insufficient.

### 1.1 Why the current code is racy (mechanism)

1. `Wallet_model::get_balance()` runs two plain `SELECT SUM(amount) ... FROM wallet_ledger` queries. Under InnoDB REPEATABLE READ these are **consistent reads** — they read a transaction snapshot and take **no locks**.
2. In `Wallet::process_withdraw()` and `Rentals::checkout()` the check `$user_balance >= $amount` executes in the controller, **before** any `trans_begin()`; each statement autocommits separately.
3. The debit is then inserted inside `trans_start()`/`trans_complete()` with **no lock on any row that serializes debit writers** and **no conditional WHERE** — nothing stops two TXs whose pre-checks both passed from both committing their debits.
4. Because the source of truth is a *derived* balance (`SUM(credit) − SUM(debit)` over an append-only ledger), there is no `users.balance` row update to serve as an atomic decrement — the check-then-insert gap is the entire vulnerability window.

**Race timeline (both requests for user U, balance = 100, both debit 100):**

```
T1 (WD)                                T2 (Checkout)
balance=SUM() → 100      [snapshot]    balance=SUM() → 100      [snapshot]
100 >= 100 ✓                           100 >= 100 ✓
INSERT debit 100                        INSERT debit 100
COMMIT                                  COMMIT
→ ledger −100                            → ledger −100
```

---

## 2. INSPECTION FINDINGS (current state of target files)

### 2.1 Withdrawal flow

**`application/controllers/Wallet.php::process_withdraw()`** (POST `/wallet/process_withdraw`):
- Rate limit `withdraw:{user_id}` (5/900s), then UX gatekeepers **outside any TX**: `has_pending_withdrawal()`, `has_active_rental()`, `has_reached_daily_wd_limit()`, bank binding.
- `$amount = preg_replace('/[^0-9]/','', post('amount'))` → integer IDR.
- **Line ~179:** `$user_balance = $this->Wallet_model->get_balance($user_id);`
- **Line ~193:** `if ($user_balance < $amount)` → flashdata error + redirect. ← *the vulnerable external pre-check*
- **Line ~199:** `$result = $this->Wallet_model->create_withdrawal($user_id, $amount, $bank->id);` → flashdata success/error + redirect to `wallet`.

**`application/models/Wallet_model.php::create_withdrawal($user_id, $amount, $bank_account_id)`**:
```php
$wd_number = 'WD-' . date('YmdHis') . '-' . $user_id;
$this->db->trans_start();
$this->db->insert('withdrawals', [... 'amount'=>$amount, 'status'=>'pending']);   // no gross/fee/net (C3, see §2.5)
$this->db->insert('wallet_ledger', [... 'type'=>'debit', 'amount'=>$amount]);     // NO lock, NO guard
$this->db->trans_complete();
return $this->db->trans_status() ? $wd_number : false;
```
Returns `string|false` — the controller cannot distinguish *insufficient balance* from *other failure*.

### 2.2 Checkout flow

**`application/controllers/Rentals.php::checkout()`** (POST `/rentals/checkout`):
- Validates `product_id`, fetches product via `Product_model::get_product()`.
- **Line ~69:** `$user_balance = $this->Wallet_model->get_balance($user_id);`
- **Line ~70:** `if ($user_balance < $product['price'])` → flashdata error. ← *the vulnerable external pre-check*
- **Line ~78:** `$result = $this->Rental_model->checkout_rental($user_id, $product);`

**`application/models/Rental_model.php::checkout_rental($user_id, $product)`**:
```php
$this->db->trans_start();
$this->db->insert('wallet_ledger', [... 'type'=>'debit', 'amount'=>$product['price']]);  // NO lock, NO guard
$this->db->insert('user_rentals',  [... 'status'=>'active', 'expired_at'=>...]);
$rental_id = $this->db->insert_id();
$this->db->trans_complete();
if ($this->db->trans_status() === FALSE) return ['success'=>false, 'message'=>'...'];
return ['success'=>true, 'message'=>'', 'rental_id'=>$rental_id];
```
Returns structured `{success, message, rental_id}` — good contract shape to extend with a `code` field.

### 2.3 Balance source of truth

`wallet_ledger` is append-only: `id, user_id, transaction_id, type ENUM('credit','debit'), amount DECIMAL(15,2), description, created_at`, `INDEX idx_user_id (user_id)`, FK `user_id → users.id ON DELETE RESTRICT`. **No unique key on `transaction_id`** (C2 noted the same — dedupe hardening is out of scope here, see §3.6).
- `users.balance DECIMAL(15,2) DEFAULT 0` is **stale** for most money moves (C4) — it must **not** be used as the balance source; `users.id` is used *only as the serialization anchor*.

### 2.4 In-repo reference patterns (the "correct" style to copy)

| Pattern | Where | What it proves |
|---|---|---|
| `SELECT * FROM user_rentals WHERE id=? AND user_id=? FOR UPDATE` as **first statement** of `trans_begin()`; try/catch; explicit `trans_commit()`/`trans_rollback()`; gate payout on `affected_rows()===1` | `Rental_model::claim_roi()` (C2 fix, plan/44) | Pessimistic lock + explicit TX boundary is the established house pattern for money races |
| `SELECT balance FROM users WHERE id = ? FOR UPDATE` inside `trans_begin()` | `Ledger_model::insert_transaction()` (dead code, C4/M6) | Anchor-row lock on `users` is the canonical InnoDB serialization target |
| `trans_start()`/`trans_complete()` + conditional `WHERE status='pending'` + `affected_rows()===1` | `Wallet_model::approve_deposit_simulator()` (C1 fix, plan/38) | Conditional transitions as concurrency guards |

### 2.5 Related dependency — C3 (schema drift) & other gatekeepers

- **C3 (unfixed):** canonical `database.sql` `withdrawals` requires `gross_amount/fee_amount/net_amount` NOT NULL (no defaults); `create_withdrawal()` inserts neither → on the canonical schema the withdrawals insert fails and every WD rolls back. `database_seed.sql` supplies these columns only for seeded rows. **Verification of the WD *success* path depends on C3 schema alignment on the live DB** (see §5.4). The C5 logic itself is independent and fully testable via checkout + the insufficient path.
- **Single-pending-WD & daily-limit gates** (`has_pending_withdrawal`, `has_reached_daily_wd_limit`) read `withdrawals` rows **outside** the TX — two concurrent WD submissions for the same user can both pass them (business-rule race, sibling of C5). Because the C5 users-row lock serializes all WD submissions per user anyway, these gatekeepers get cheap **authoritative re-checks inside the locked TX** (§3.4) so the "max 1 pending WD" and "1 WD/day" invariants stay strict.
- **UX pre-checks that may stay outside** (fast feedback, never trusted): `has_active_rental()` (reads `user_rentals`), bank binding, rate limit, min-amount, `amount <= 0`.

### 2.6 Numeric handling notes

- `get_balance()` returns `(int)` — truncates DECIMAL(15,2) fractions (M8, latent; IDR products are whole rupiah today). Withdrawal amounts are integer IDR by construction (`preg_replace('/[^0-9]/','')`); product prices are DECIMAL from `gpu_products`. Comparisons in the guarded path use **float** arithmetic on DECIMAL-derived values (safe range: well under 2^53); full integer-IDR standardization is M8, out of scope.

---

## 3. ARCHITECTURAL DESIGN

### 3.1 Locking model — pessimistic anchor-row lock

**Lock target:** the user's `users` row — `SELECT id FROM users WHERE id = ? FOR UPDATE`, executed as the **first statement inside the explicit transaction**.

Why this target:
1. Every money-debit path for a user is made to pass through this one row, so concurrent debits for the same user **serialize** (second TX blocks on the lock until the first commits, then reads the first TX's committed debit).
2. Single-row lock on the PK — cheap, no table/range locks, no deadlock surface between the two debit paths (both lock `users.id` in the same order, then append rows to different tables).
3. It is the exact anchor the audit named and `Ledger_model` already demonstrates.

**Why a fresh balance computed *after* the lock is authoritative:**
- A `FOR UPDATE` read is a **locking (current) read**: it blocks until any other TX holding the row commits, then reads the **latest committed** version.
- InnoDB creates the transaction's consistent-read snapshot at the **first consistent read**, not at `BEGIN`. If the `FOR UPDATE` is the first statement, the subsequent `SUM` on `wallet_ledger` (a consistent read) creates its read view **after** lock acquisition → it necessarily sees every debit committed by every earlier holder of the users-row lock.
- **Ordering rule (non-negotiable):** inside the TX, the `FOR UPDATE` lock must precede any other read that feeds the decision. A `SUM` performed before the lock would use a pre-wait snapshot and reintroduce the race.

**Why racing credits are safe (no overspend, worst case a conservative reject):**
- A concurrent *credit* (deposit approval, ROI claim, admin inject, WD-decline refund) does not take the users-row lock and may commit while we hold it. If it commits before our first consistent read it is visible (more balance); if after, our SUM simply doesn't see it and we may **reject a debit that would have been covered** — the user retries. Overspend is impossible because every path that *debits* is serialized, and debits are the only rows that reduce the balance.
- Combined effect: **the balance never drops below zero; the only cost of racing with a credit is a false "insufficient" (safe direction).**

**Expected concurrency matrix (balance = 100):**

| TX-A (debit 100) | TX-B (debit 100) | Result |
|---|---|---|
| Locks user, balance=100 ✓, debits, commits | — | A succeeds; ledger = 0 |
| — | Starts after A commits; locks user, balance=0, **100 ≥ 100 false** → rollback | B fails `insufficient`; ledger stays 0 |
| Locks user | Blocks on users-row lock until A commits, then fresh SUM | Serialized — never both pass |

### 3.2 Transaction boundary & house style

Adopt `Rental_model::claim_roi()`'s explicit pattern for both debit models (uniform guard rails, clean error returns instead of `trans_complete()` boolean plumbing):

```
trans_begin()
  try:
    1. lock users row FOR UPDATE            (or fail: user missing → rollback)
    2. fresh balance = SUM over wallet_ledger  (single conditional-aggregation query)
    3. if fresh_balance < required → rollback → return {code:'insufficient'}
    4. [WD only] authoritative re-checks (pending-WD / daily limit) → rollback on violation
    5. insert debit (wallet_ledger) [+ withdrawals / user_rentals row]
    6. trans_commit() → return {code:'ok'}
  catch (Throwable):
    trans_rollback(); log_message(); return {code:'error'}
```

Rationale: `trans_begin()/trans_commit()/trans_rollback()` (CI3 3.1.13) give explicit control for the early-return rejection paths; try/catch guarantees rollback on exceptions; consistent with C2's already-merged fix.

### 3.3 Shared helper — one locked-balance routine (encapsulation per AGENTS.md)

All DB access lives in models. To avoid duplicating the lock+SUM SQL in two models, add **one** primitive to `Wallet_model` and let `Rental_model` call it (models may load sibling models):

```php
/**
 * Serialisasi debit per-user + saldo segar otoritatif.
 * WAJIB menjadi statement pertama di dalam transaksi debit (lihat §3.1).
 * @return float|false  saldo segar (Σcredit−Σdebit dari wallet_ledger),
 *                      atau false bila baris users tidak ditemukan.
 */
public function lock_and_get_balance($user_id) {
    $user = $this->db->query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$user_id])->row();
    if (!$user) {
        return false;
    }
    $row = $this->db->query(
        "SELECT COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN type = 'debit'  THEN amount ELSE 0 END), 0) AS balance
           FROM wallet_ledger
          WHERE user_id = ?",
        [$user_id]
    )->row();
    return (float) $row->balance;
}
```

- Single-query conditional aggregation (one consistent read — clean read-view semantics after the lock).
- `Wallet_model::get_balance()` **stays untouched** for display/UX pre-checks; it is deliberately *not* the financial guard.
- Controllers remain thin and SQL-free; `Rental_model` gains no raw duplicated lock SQL beyond a `$this->wallet_model` load — or, if preferred, the two-line `FOR UPDATE` statement is kept local and only the fresh-balance SUM is shared. **Decision recorded in §4; default = shared helper.**

### 3.4 Per-path guarded methods (blueprint behavior)

**`Wallet_model::create_withdrawal($user_id, $amount, $bank_account_id)`** — new contract:
```php
return [
  'success' => bool,
  'code'    => 'ok' | 'insufficient' | 'pending_exists' | 'daily_limit' | 'user_not_found' | 'error',
  'message' => string,        // Indonesian, UI-ready
  'wd_number' => string|null,
];
```
Behavior inside the TX (after users-row lock):
1. `lock_and_get_balance()`; `false` → `user_not_found`.
2. `fresh_balance < $amount` → **`insufficient`** (rollback; message `'Saldo tidak mencukupi untuk penarikan'` — same copy the controller uses today).
3. Authoritative re-checks (fresh reads after the lock; serialized by the users-row lock so they are race-free): pending-WD exists → `pending_exists`; daily limit reached → `daily_limit`.
4. Insert `withdrawals` (pending) + `wallet_ledger` debit (`transaction_id = wd_number`).
5. Commit → `ok` with `wd_number`.

**`Rental_model::checkout_rental($user_id, $product)`** — extend contract with `code`:
```php
return [
  'success' => bool,
  'code'    => 'ok' | 'insufficient' | 'user_not_found' | 'error',
  'message' => string,
  'rental_id' => int|null,
];
```
Behavior inside the TX (after users-row lock):
1. `lock_and_get_balance()` (via shared helper); `false` → `user_not_found`.
2. `fresh_balance < (float)$product['price']` → **`insufficient`** (rollback; message `'Sistem: Saldo USC/IDR Anda tidak mencukupi.'`).
3. Insert `wallet_ledger` debit (`transaction_id = 'RENT-' . product_id . '-' . date('YmdHis')` — unchanged) + `user_rentals` row.
4. Commit → `ok` with `rental_id`.

### 3.5 Controllers — external pre-checks remain UX-only, errors mapped cleanly

- `Wallet::process_withdraw()`: keep the existing fast pre-checks (min amount, bank, active rental, daily limit, pending WD, balance) — they short-circuit obvious invalid requests before hitting the DB. Change only the tail: map the model result by `code` to the flashdata message:
  - `ok` → success flash, redirect `wallet`
  - `insufficient` → `'Saldo tidak mencukupi untuk penarikan'`, redirect `wallet/withdraw`
  - `pending_exists` / `daily_limit` / `error` → existing copy, redirect `wallet`
- `Rentals::checkout()`: keep the balance pre-check as a UX fast-fail; map `code === 'insufficient'` from `checkout_rental()` to the existing insufficient message (it already forwards `$result['message']` — only ensure the insufficient copy is distinct and accurate), otherwise unchanged.
- No controller gains `$this->db` access; no new SQL in controllers (AGENTS.md).

### 3.6 Explicitly out of scope (listed, not implemented)

- C3 withdrawal schema alignment (needed only for end-to-end WD *success* verification — §5.4).
- Unique index on `wallet_ledger.transaction_id` (C2 dedupe hardening) — separate change.
- C4/M6 single-ledger service + `users.balance` deprecation; M8 integer-IDR; M1 fee/window/max rework.
- `Admin` approve/decline conditional updates (M4).

---

## 4. CHANGE LIST (files & methods — for the implementation phase; nothing changed yet)

| # | File | Method | Change |
|---|---|---|---|
| 1 | `application/models/Wallet_model.php` | `lock_and_get_balance()` | **Add** shared helper (§3.3) |
| 2 | `application/models/Wallet_model.php` | `create_withdrawal()` | Rewrite TX body: users-row lock → fresh balance → `insufficient`/`pending_exists`/`daily_limit` rejects → inserts → commit; explicit `trans_begin/commit/rollback` + try/catch; return structured array §3.4 |
| 3 | `application/models/Rental_model.php` | `checkout_rental()` | Load `Wallet_model`; users-row lock → fresh balance → `insufficient` reject → inserts → commit; explicit TX style; add `code` to return §3.4 |
| 4 | `application/controllers/Wallet.php` | `process_withdraw()` | Map structured result `code` → flashdata (§3.5); pre-checks untouched |
| 5 | `application/controllers/Rentals.php` | `checkout()` | Map `code` → flashdata; comment that authoritative guard is in model (§3.5) |
| — | `database.sql` / schema | — | **No schema change** (users-row lock needs no DDL) |
| — | views | — | No change |

---

## 5. VERIFICATION & TESTING PROTOCOL (implementation phase)

Environment: local `php -S localhost:8080` + MySQL with a seeded DB; run per phase-branch convention; `php -l` each touched file.

### 5.0 Static gates
- `php -l application/models/Wallet_model.php`
- `php -l application/models/Rental_model.php`
- `php -l application/controllers/Wallet.php`
- `php -l application/controllers/Rentals.php`

### 5.1 Test Case 1 — Normal debit
1. Seed user U with a known credit balance B (e.g., admin inject 1.000.000 → ledger credit).
2. Login as U via curl (capture CSRF cookie + token from the form page, per CI3 `csrf_protection=TRUE`).
3. **Withdrawal:** POST `/wallet/process_withdraw` amount 200.000 → expect success flash; assert exactly 1 new `withdrawals` row (pending) and 1 `wallet_ledger` debit 200.000; assert `SUM(credit)−SUM(debit)` = B − 200.000.
4. **Checkout:** POST `/rentals/checkout` with a product whose price ≤ remaining balance → expect success; assert 1 new `wallet_ledger` debit = price and 1 `user_rentals` row active; assert balance = B − 200.000 − price.
5. Assert displayed wallet balance (GET `/wallet`) matches ledger arithmetic.

### 5.2 Test Case 2 — Concurrent overspend burst (checkout vs withdrawal)
Goal: prove exactly one debit wins when both cannot be covered, and the ledger never goes negative.
1. Fresh user U2; balance exactly **150.000** (credit only). Product P price 100.000.
2. Prepare two sessions via curl; fetch CSRF for each (`/wallet/withdraw` and `/marketplace`).
3. Fire **simultaneously** (two shells / `xargs -P2`): POST `/wallet/process_withdraw` (100.000) **and** POST `/rentals/checkout` (product P, 100.000). Total demanded 200.000 > 150.000 → only one may succeed.
4. **Assertions (DB):**
   - Exactly one of {new `withdrawals` pending row, new `user_rentals` row} exists — i.e., exactly one debit committed.
   - The loser's flashdata/redirect shows the insufficient-balance message (or the WD pending-exists message if the WD lost to the pending gate — in that case rerun once more to demonstrate the pure `insufficient` loser; the invariant below is the hard assertion).
   - `SELECT COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE 0 END),0) FROM wallet_ledger WHERE user_id = U2` → **= 50.000 (≥ 0, and never negative at any observed instant)**.
5. Repeat 5× with fresh users (rate limiter `withdraw:{uid}` 5/900s and the single-pending-WD gate make repeated runs on one user unreliable — use a new user per run).
6. Optional negative-control: before the fix (or on a reverted branch), the same burst yields **two** committed debits and a negative ledger — demonstrating the C5 repro.

### 5.3 Test Case 3 — Zero / low-balance rejection inside the locked TX
1. User U3 with balance **50.000**; product P price 100.000; WD request 100.000.
2. Direct model-level call (bypass the controller pre-check, which would mask the model path): invoke `Rental_model::checkout_rental(U3, P)` from a throwaway CLI/bootstrap script (or temporary ENV-gated route) → assert return `success=false, code='insufficient'`; assert **no** `wallet_ledger` debit and **no** `user_rentals` row.
3. Same for `Wallet_model::create_withdrawal(U3, 100000, bank_id)` → assert `code='insufficient'`, no debit, no `withdrawals` row.
4. User U3 with balance **0**: same two calls → `code='insufficient'`, ledger stays 0.00.
5. Assert DB `trans_status()`-equivalent: nothing partial persisted after the rollbacks (no orphan debit without its withdrawal/rental row).

### 5.4 C3 schema dependency check (for WD *success* leg only)
- `DESCRIBE withdrawals;` — if `gross_amount/fee_amount/net_amount` are NOT NULL without defaults, apply the C3 alignment first (or note the WD success path is blocked by C3 and verify C5 via checkout + insufficient paths only). The C5 lock/check logic itself is orthogonal to C3.

### 5.5 Regression sweep
- Existing flows: topup → approve deposit → checkout → claim ROI (T+1) → withdraw → admin approve/decline; two *concurrent claim* POSTs must still not double-credit (C2 unchanged); two concurrent checkout POSTs for different products within balance → both succeed sequentially, ledger correct; CSRF/AJAX tokens intact.

---

## 6. ROLLBACK & RISKS

- **Rollback:** revert the 5-file change set (single PR/phase branch per convention); no DDL, no data migration, no seed change → trivially reversible.
- **Risk — lock contention:** per-user serialization of debit submissions is intentional and bounded (a user's own rapid double-submits queue briefly). No cross-user contention; no lock-ordering deadlock (all debit TXs lock the same single row first, then write disjoint row sets). Credits never take the users-row lock, so deposit/ROI/admin flows are unaffected.
- **Risk — conservative rejection vs concurrent credits:** a debit racing a credit may return `insufficient` even though the credit would have covered it (credit commits mid-TX after our read view). Safe direction (no overspend); user retries. Acceptable and documented.
- **Risk — false confidence from pre-checks:** controllers keep pre-checks *only* for UX; the model TX is the sole authority. Code comments must state this to prevent future "optimizations" that trust the pre-check.

---

## 7. ACCEPTANCE CRITERIA (maps to audit §2-C5 fix statement)

- [x] **A1 — In-TX authoritative check:** no money-debit path decides on a balance read outside its explicit transaction; `create_withdrawal` and `checkout_rental` both verify inside `trans_begin()`.
- [x] **A2 — Pessimistic lock:** `SELECT id FROM users WHERE id = ? FOR UPDATE` is the first statement of each debit TX; fresh balance is `SUM(credit)−SUM(debit)` on `wallet_ledger` computed after lock acquisition.
- [x] **A3 — Strict rejection:** `fresh_balance < required` → rollback + clean `code='insufficient'` message; nothing partial persists.
- [x] **A4 — Encapsulation:** all TX/ledger SQL in `Wallet_model`/`Rental_model`; controllers thin, no `$this->db`; shared helper avoids duplicated lock SQL (AGENTS.md).
- [x] **A5 — Tests:** §5 protocol — php -l; normal debit; concurrent burst (exactly one winner, ledger never negative); zero-balance rejection.
- [x] **A6 — No schema change:** fix is purely transactional (row lock needs no DDL).

---

## 8. STATUS

🔵 **PLAN COMPLETE — awaiting user approval before any application-code modification.**

*End of plan — plan/48_C5_OVERSPEND_RACE_FIX_PLAN.md. Read-only; no application code or schema modified.*
