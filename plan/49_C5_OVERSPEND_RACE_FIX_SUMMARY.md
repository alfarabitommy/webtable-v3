# 49 — C5 OVERSPEND / BALANCE RACE FIX SUMMARY (Withdrawal & Checkout — Eliminated)

**Status:** ✅ COMPLETE — code applied, `php -l` clean on all 4 modified files, controller grep verification passed (zero `$this->db`). Runtime curl/DB concurrency tests per plan/48 §5 pending live-DB smoke run (see §5).
**Blueprint:** plan/48_C5_OVERSPEND_RACE_FIX_PLAN.md (approved). **Audit:** plan/37 §2-C5.
**Scope:** `application/models/Wallet_model.php`, `application/models/Rental_model.php`, `application/controllers/Wallet.php`, `application/controllers/Rentals.php`. **No database schema modified** (pessimistic row lock needs no DDL).

---

## 1. Vulnerability (recap)

In both money-debit paths the authoritative balance check ran **outside** the transaction on an **unlocked snapshot read**:

- `Wallet::process_withdraw()` / `Rentals::checkout()` called `Wallet_model::get_balance()` — plain `SUM` on `wallet_ledger` (InnoDB consistent read under REPEATABLE READ) — **before** `trans_start()`.
- `Wallet_model::create_withdrawal()` and `Rental_model::checkout_rental()` inserted the debit with **no `FOR UPDATE` on the user row and no conditional guard**.

Two concurrent requests (checkout vs withdrawal, or two of either) for the same user could both observe the same pre-debit balance, both pass `balance >= amount`, and both commit their debits → **Σcredit − Σdebit on `wallet_ledger` goes negative** (overspend). Because the balance is *derived* from an append-only ledger, the check-then-insert gap was the entire vulnerability window.

---

## 2. Changes applied

### 2.1 `application/models/Wallet_model.php`

**`lock_and_get_balance($user_id)`** (new) — shared serialization + authoritative-balance primitive:
1. `SELECT id FROM users WHERE id = ? FOR UPDATE` — pessimistic lock on the **`users` anchor row** (single row, PK); returns `false` when the user row does not exist.
2. Fresh balance in **one conditional-aggregation query**:
   ```sql
   SELECT COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0)
        - COALESCE(SUM(CASE WHEN type = 'debit'  THEN amount ELSE 0 END), 0) AS balance
   FROM wallet_ledger WHERE user_id = ?
   ```
3. Returns `(float) $row->balance`.

Docblock enforces the invariant: **must be the first operation inside `trans_begin()`** of every debit path — the `FOR UPDATE` (locking/current read) blocks concurrent debit TXs for the same user until the holder commits, and the SUM is the *first consistent read*, so its read view is created after the lock wait → it necessarily sees every debit committed by earlier lock holders. `users.balance` is deliberately **not** used (stale, audit C4) — the row is only the serialization anchor.

**`create_withdrawal($user_id, $amount, $bank_account_id)`** (rewritten) — ACID anti-overspend engine, explicit `trans_begin()` + `try/catch` (house style of `claim_roi`):
1. `lock_and_get_balance()` first → `false` (user missing) → rollback → `code 'error'`.
2. `fresh_balance < (float) $amount` → **rollback** → `code 'insufficient'`, message `'Saldo tidak mencukupi untuk penarikan'`.
3. Authoritative re-checks **inside the lock**: pending withdrawal exists → `code 'pending_exists'`; daily limit reached → `code 'daily_limit'` (both rollback; read view post-lock-wait makes them race-free — plan/48 §3.4).
4. Insert `withdrawals` (status `pending`) + `wallet_ledger` debit (`transaction_id = wd_number`), `trans_commit()` → `code 'ok'` with `wd_number`.
5. `catch (Throwable)` → rollback + `log_message('error', ...)` → `code 'error'`.

Return contract changed from `string|false` to structured:
```php
['success' => bool, 'code' => 'ok'|'insufficient'|'pending_exists'|'daily_limit'|'error',
 'message' => string, 'wd_number' => string|null]
```

### 2.2 `application/models/Rental_model.php`

**`__construct()`**: now also `$this->load->model('Wallet_model');` (no-op when already loaded by `MY_Controller`; keeps the model self-sufficient for CLI/cron paths).

**`checkout_rental($user_id, $product)`** (rewritten) — ACID anti-overspend engine, explicit `trans_begin()` + `try/catch`:
1. `$this->Wallet_model->lock_and_get_balance($user_id)` first → `false` (user missing) → rollback → `code 'error'`.
2. `fresh_balance < (float) $product['price']` → **rollback** → `code 'insufficient'`, message `'Sistem: Saldo USC/IDR Anda tidak mencukupi.'`.
3. Insert `wallet_ledger` debit (`transaction_id = 'RENT-{product_id}-{YmdHis}'`, unchanged) + `user_rentals` (status `active`, `expired_at`), `trans_commit()` → `code 'ok'` with `rental_id`.
4. `catch (Throwable)` → rollback + `log_message('error', ...)` → `code 'error'`.

Return contract extended with `code` (structure already array-based):
```php
['success' => bool, 'code' => 'ok'|'insufficient'|'error', 'message' => string, 'rental_id' => int|null]
```

### 2.3 `application/controllers/Wallet.php` — `process_withdraw()`

- All pre-checks (rate limit, pending WD, active rental, daily limit, bank binding, min amount, `amount <= 0`, balance) **kept strictly as fast UX feedback** — commented as non-authoritative; the financial authority is `create_withdrawal()`.
- Result mapping (plan/48 §3.5):
  - `success === true` → success flashdata `'Permintaan penarikan berhasil diajukan'`, redirect `wallet`.
  - `code === 'insufficient'` → error flashdata from model message, redirect **`wallet/withdraw`**.
  - other failure codes (`pending_exists`, `daily_limit`, `error`) → error flashdata from model message, redirect `wallet`.
- No `$this->db` access; controllers stay thin.

### 2.4 `application/controllers/Rentals.php` — `checkout()`

- Balance pre-check kept as a fast UX fail with updated comments (authority lives in `Rental_model::checkout_rental`).
- Consumes the structured result: `success === false` → error flashdata from `$result['message']`, redirect `marketplace`; success → existing success flashdata, redirect `rentals`.
- No `$this->db` access.

---

## 3. Concurrency semantics (why overspend is now structurally impossible)

- All debit paths (withdrawal submission, rental checkout) first acquire the **same `users.id` row lock** inside their own TX → per-user serialization; the second request blocks, then its fresh `SUM` sees the first request's committed debit.
- Balance is always computed **after** lock acquisition as the first consistent read of the TX → never a stale snapshot.
- `fresh_balance < required` → immediate rollback + clean `insufficient` — nothing partial persists.
- Racing **credits** never cause overspend: worst case a debit loses a race against a just-committing credit and is conservatively rejected (user retries). Only debits reduce balance; only debits are serialized.

---

## 4. Verification performed

| Check | Command | Result |
|---|---|---|
| Syntax lint | `php -l application/models/Wallet_model.php` | ✅ No syntax errors |
| Syntax lint | `php -l application/models/Rental_model.php` | ✅ No syntax errors |
| Syntax lint | `php -l application/controllers/Wallet.php` | ✅ No syntax errors |
| Syntax lint | `php -l application/controllers/Rentals.php` | ✅ No syntax errors |
| No direct DB in controllers | `grep -n '\$this->db' application/controllers/Wallet.php application/controllers/Rentals.php` | ✅ zero matches (exit 1) |
| Caller audit | `grep -rn "create_withdrawal\|checkout_rental" application/` | ✅ only the two controllers consume these methods — no other caller depends on the old `string|false` return |

---

## 5. Runtime verification (manual — pending live-DB smoke run)

Per AGENTS.md there is no automated test suite and money flows are verified via browser/`curl` against the dev DB. **No MySQL server is reachable in this environment** (`mysql`/`mysqladmin` absent), so the plan/48 §5 protocol is queued for the local dev environment (`php -S localhost:8080` + seeded `db_webtable`):

1. **Test 1 — Normal debit:** seed a user with a known credit balance; submit one withdrawal and one checkout; assert exactly one `withdrawals` pending row + one debit each; ledger `SUM(credit)−SUM(debit)` matches expected; wallet display agrees.
2. **Test 2 — Concurrent overspend burst:** user balance 150.000; fire **simultaneous** POST `/wallet/process_withdraw` (100.000) and POST `/rentals/checkout` (product 100.000); assert exactly one debit commits, the loser returns `insufficient`, and the ledger never drops below zero. Fresh user per run (rate limiter `withdraw:{uid}` 5/900s). CSRF cookie+token must be captured per session.
3. **Test 3 — Zero/low-balance rejection:** user balance ≤ required; direct model call to `checkout_rental()`/`create_withdrawal()` (bypassing the UX pre-check) must return `success=false, code='insufficient'` with no persisted debit/withdrawal/rental rows.

---

## 6. Acceptance criteria (plan/48 §7)

- ✅ **A1 — In-TX authoritative check:** both debit engines decide inside `trans_begin()`; pre-checks in controllers are UX-only.
- ✅ **A2 — Pessimistic lock:** `SELECT id FROM users WHERE id = ? FOR UPDATE` is the first statement of each debit TX; fresh balance from `wallet_ledger` computed after lock acquisition.
- ✅ **A3 — Strict rejection:** `fresh_balance < required` → rollback + clean `code='insufficient'`; nothing partial persists.
- ✅ **A4 — Encapsulation:** all TX/ledger SQL in `Wallet_model`/`Rental_model`; controllers thin with zero `$this->db`; shared helper avoids duplicated lock SQL (AGENTS.md).
- ✅ **A5 — Tests:** lint + grep + caller audit executed here; runtime burst/zero-balance protocol documented (§5, pending DB).
- ✅ **A6 — No schema change:** fix is purely transactional (row lock needs no DDL).

---

## 7. Out of scope / notes

- **C3 dependency:** the withdrawal *success* leg still requires the `withdrawals` schema alignment (`gross_amount/fee_amount/net_amount`) on the live DB — canonical `database.sql` has them NOT NULL without defaults while `create_withdrawal()` inserts neither (audit C3, separate plan). The C5 lock/check logic is independent and fully testable via checkout + the `insufficient` path.
- `Wallet_model::approve_deposit_simulator()` / `approve_withdrawal_simulator()` retain their existing `trans_start()`/`trans_complete()` style (C1/C7 scope, untouched).
- `Wallet_model::get_balance()` unchanged — still used for display and UX pre-checks; it is deliberately not a financial guard.
- No commit created in this workspace state; working tree also carries unrelated pre-existing phase changes (47 files) that predate this task and were not touched.

---

*End of report — plan/49_C5_OVERSPEND_RACE_FIX_SUMMARY.md.*
