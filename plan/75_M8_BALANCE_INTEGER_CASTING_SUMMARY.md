# 75 — M8 BALANCE INTEGER-CASTING & ARITHMETIC DISCIPLINE — IMPLEMENTATION SUMMARY

**Task:** Implement audit finding M8 (money type discipline) per approved blueprint `plan/74_M8_BALANCE_INTEGER_CASTING_DISCIPLINE_PLAN.md`, strictly Option 1 — **code-boundary enforcement, keep `DECIMAL(15,2)`; no DB schema ALTER** (decision `dec-5776ed3a88018f17`).
**Mode:** EXECUTION. 9 files modified (4 models, 4 controllers, 1 view). No schema change; no data migration; no code executed against any database.

---

## 1. CODE MODIFICATIONS

### application/models/Wallet_model.php (core choke points)
| Method | Change |
|---|---|
| `get_balance()` | Two `SUM` string queries → **single query** `COALESCE(SUM(CASE…credit) − SUM(CASE…debit), 0)`; returns strict `(int) $row->balance` (empty → 0). Never string/float/null. |
| `lock_and_get_balance()` | `return (float)` → `return (int)`; docblock `@return int\|false`. Authoritative in-TX balance now integer (all sufficiency gates integer-compared). |
| `_post()` (credit/debit sole write path) | `$amount = (float)` → `(int)` + **positivity guard** `if ($amount <= 0) { log warning; return false; }`; removed loose `== 0` no-op skip. Fractional/zero/negative amounts can no longer reach `wallet_ledger` or the `users.balance` relative cache. |
| `calculate_withdrawal_fee()` | `(int) floor($gross*$bps/10000)` → **`intdiv($gross * $bps, 10000)`** (floor semantics, pure integer; gross*bps ≤ 5e11 < PHP_INT_MAX). |
| `calculate_deposit_fee()` | Float `floor(amount*pct/100)` → **basis-point integer**: `bps = (int) round(pct*100)` (0.70 → 70), `fee = intdiv($amount * $bps, 10000)`. `round()` used only for the config unit conversion, never on money. |
| `create_deposit()` | `(int)` boundary cast of amount (docblock `@param int`). |
| `approve_deposit_simulator()` / `create_withdrawal()` | `credit(..., (float) …)` / `(float)` compare / `debit(..., (float) …)` → all `(int)`. |
| `credit()`/`debit()` | Docblocks `@param int $amount`. |

### Dependent models
- **Rental_model.php** — `checkout_rental()`: overspend compare & `debit()` price as `(int)`; `user_rentals` snapshot (`purchase_price`, `daily_roi`) stored `(int)`. `claim_roi()`: `$payout = days * (int) $rental->daily_roi` (no DECIMAL-string float coercion) and `credit((int) $payout)`; docblock `amount:int`. `create_rental()` snapshots `(int)`.
- **User_model.php** — `claim_wage()`: `(float)` removed, `credit((int) $wage_level['amount'])`.
- **Admin_model.php** — `inject_balance()`: `(int)` boundary + docblock; audit `details.amount` integer. All **17 monetary `(float)` casts → `(int)`** (ledger write paths for deposit-approve/withdrawal-refund plus treasury/xray/analytics monetary aggregates). `inject_rental()` `user_rentals` snapshot `(int)`.

### Controllers & views (input validation + display parity)
- **Wallet.php** — `topup()` & `process_withdraw()`: `preg_replace('/[^0-9]/', …)` **silent strip removed**; strict validation `preg_match('/^[1-9][0-9]*$/')` on the raw POST (rejects `10000.50`, `1e5`, `-5`, `100,000`, empty) with flash "Nominal tidak valid." / "Nominal penarikan tidak valid." + `(int)` cast; dead `$amount <= 0` branch removed. `index()` enrichment `total_payable`/`deposit_fee` integer (`calculate_deposit_fee((int) …)` + `(int)` sum).
- **Admin.php** — `inject_balance()`: `floatval()` → strict `^[1-9][0-9]*$` parse (0 fallback caught by existing `<= 0` guard → "Data inject tidak valid."). Rental audit context `(float)` → `(int)`. Withdrawal CSV export: `gross/fee/net` read as `(int)`; `_csv_money()` no longer `round((float))` — plain `(string) (int) $value` (no round-on-money).
- **Rentals.php / Team.php** — claim notification `number_format((float) $result['amount'])` → `(int)` (JSON `amount`/`new_balance` now integer end-to-end).
- **views/wallet/index.php** — money display casts `(float)` → `(int)`; copy-nominal value derived as int.

---

## 2. VALIDATION TIGHTENING (summary)
- Wallet topup/withdraw: **only canonical positive-integer digits accepted** at the controller; nothing is rewritten server-side.
- Admin manual inject: **fractional/float/scientific input rejected** (`0.001`, `1e5` → invalid).
- Model backstop: `Wallet_model::_post()` rejects non-positive amounts (defense-in-depth for any future caller).
- Fee math: floor semantics preserved with **pure integer division**; percent fees converted once to integer basis points.

---

## 3. VERIFICATION STATUS
1. **Lint** — `php -l` clean on all 8 modified PHP files + `application/views/wallet/index.php`.
2. **grep guards** — zero `(float)` casts on money variables across models/controllers/views except two **sanctioned percent-config conversions** (`Wallet_model.php:381` pct→bp unit conversion; `wallet/index.php:250` percent value injected to JS preview). `floatval` fully removed. Ledger writes remain confined to `Wallet_model.php`.
3. **Fee-formula equivalence corpus** (no DB) — 45,000 withdrawal pairs: `intdiv(g*b,10000)` ≡ legacy `(int)floor(g*b/10000)`, **0 mismatches**; 10,220 deposit pairs across pcts {0.05, 0.50, 0.70, 1.25, 2.00, 3.33, 5.00}: integer-bp math ≡ legacy float floor, **0 diffs**; `bp(0.70)=70`.
4. **Read-only DB integrity check** — `SELECT COUNT(*) … WHERE amount <> FLOOR(amount)` on `wallet_ledger`, `withdrawals`, `deposits`, `users.balance` was **attempted but could not run** (no local MySQL server in this environment; mysqli socket unreachable). **Pending operator action** — run on a DB-backed dev/live environment; expected result: 0 fractional rows in all four.

---

## 4. RESIDUALS / NOTES (not in this round's scope)
- **curl flow smoke** (topup `10000.50` → "Nominal tidak valid.", admin inject `0.001` rejected, claim JSON ints) requires the app + DB and is **pending a DB-backed environment**.
- Admin **financial-settings config parser** (`Wallet_model::_norm_int`, `_resolve_financial_config`) still digit-strips admin-entered config values (e.g. `"10000.50"` → `"1000050"` for `wd_fixed_fee`). Trusted-admin-only input with range validation; flagged as a follow-up hardening candidate (out of M8's approved scope).
- `application/config/database.php` lines ~103-107 contain a **commented production credential block** (stale remnant) — recommend removal in a later secrets-hygiene pass.
- Rounding convention now enforced: `round()` only for config unit conversion; money is truncated/floored via `intdiv`; balances/mutations are strict int IDR.

---

*End of summary — implementation complete per plan/74; no schema altered; DB-dependent checks flagged for operator execution.*
