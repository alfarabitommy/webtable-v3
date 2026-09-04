# 74 — M8 BALANCE INTEGER-CASTING & ARITHMETIC DISCIPLINE PLAN

**Finding:** plan/37 §M8 (`plan/37_FULL_SYSTEM_AUDIT_REPORT.md:104-105`) — "Money type discipline"; still OPEN per `plan/66_AUDIT_GAP_ANALYSIS_SUMMARY.md` §2-M8 (`Wallet_model::get_balance` line 419 etc.).
**Mode:** ANALYSIS + PLAN (read-only vs application code). No application code modified; no schema altered.
**Decision (user-approved, `dec-5776ed3a88018f17`):** **Code-boundary enforcement, keep `DECIMAL(15,2)` columns.** No `ALTER`/migration on the live DB. Zero-fraction invariant is guaranteed at the PHP layer via single-choke-point `(int)` casting + integer-only arithmetic + strict integer validation. Full schema migration to `BIGINT` is deferred (tracked, not part of this round).

---

## 1. CURRENT-STATE AUDIT (evidence, static inspection)

All money columns are `DECIMAL(15,2)` (`database.sql`): `users.balance`:17, `wallet_ledger.amount`:138, `deposits.amount`:156, `withdrawals.amount|gross_amount|fee_amount|net_amount`:97-100, `user_rentals.purchase_price|daily_roi`:173-174, `gpu_products.price|daily_rate`:41-42. MySQL returns these as **numeric strings** (e.g. `"100000.00"`). PHP then type-juggles.

### A. Wallet_model.php — core choke points

| Site | Line(s) | Problem class |
|---|---|---|
| `get_balance()` — two `SUM` queries, `return (int)($credit - $debit);` | 408-420 | MySQL `SUM` returns DECIMAL **strings**; subtraction is loose numeric coercion (float when fractional) then truncated **after** arithmetic; two separate queries (non-atomic read). `COALESCE` already guarantees empty→0 (string `"0"`). Safe only because IDR is whole; truncation-after-subtraction would *mask* a fractional row instead of surfacing it. |
| `lock_and_get_balance()` — `return (float) $row->balance;` | 441-460 | The **authoritative in-TX balance** (used by every sufficiency gate) is a **float**; docblock `@return float\|false`. Primary drift vector feeding `$fresh_balance < (float) $amount` checks. |
| `_post()` — `$amount = (float) $amount;` … `if ($amount == 0)` | 512-557 | **Single write path for all money** casts to **float**, has **no positivity guard** (negative/zero credits are not rejected; zero is skipped via loose `== 0`), and would persist fractional amounts into `wallet_ledger` + the relative `users.balance` cache. |
| `calculate_withdrawal_fee()` — `(int) floor($gross * $bps / 10000) + (int) $cfg['fixed_fee']` | 340-352 | `$gross * $bps` is int×int (fits 64-bit: gross ≤ 5e7 cfg-max, bps ≤ 10000 → ≤ 5e11), but `/ 10000` promotes to **float** then `floor`. Exact below 2^53 today; latent float path. Returns `fee|net|bps` ints (OK). |
| `calculate_deposit_fee()` — `$amount = (float) $amount; (int) floor($amount * (float)$cfg['deposit_fee_value'] / 100)` | 362-372 | Percent fee stored as float (`_norm_pct` → `round($v,2)`, e.g. `0.70`); **float** amount × float pct / 100 then truncate. Docblock says floor; drift-free only by luck of small magnitudes. |
| `create_deposit()` — inserts `$amount` as received | 572-601 | Controller sends digit-only **string**; MySQL coerces. No `(int)` boundary cast. |
| `create_withdrawal()` — `(int) $amount` at 715 ✓; `$fresh_balance < (float) $amount` at 738; `debit(..., (float) $amount, ...)` at 773-778 | 698-794 | Amount cast is correct; leftover float compares/float cast into debit. `fee/net` ints from calculator (OK). |
| `approve_deposit_simulator()` — `credit(..., (float) $deposit->amount, ...)` | 654-656 | Float cast of DB DECIMAL-string into ledger write. |

### B. Dependent models — callers of credit()/debit()

| Site | Line(s) | Problem class |
|---|---|---|
| `Admin_model::inject_balance()` — `credit((int)$uid, (float)$amount, …)` / `debit(…)`; audit `(float) $amount` | 473-521 | Float boundary (controller even passes `floatval`, see D). Audit detail persisted as float string. |
| `User_model::claim_wage()` — `credit(..., (float) $wage_level['amount'], ...)` | 439-445 | Wage amounts are whole-IDR tier constants; float cast unnecessary. `claim_level1` (306-308) already uses plain int `80000` (model). |
| `Rental_model::checkout_rental()` — `$fresh_balance < (float) $product['price']`; `debit(..., (float) $product['price'], ...)` | 83-95 | Product price read as DECIMAL-string; float compare + float cast into debit. |
| `Rental_model::claim_roi()` — `$payout = $info['actual_claimable'] * $rental->daily_roi;` then `credit(..., (float) $payout, ...)` | 267-275 | int × DECIMAL-string → **float** (any `"…00"` string coerces via float because it carries `.00`); payout amount returned as `int\|float`. Daily ROI is whole-IDR product data, so no fraction today, but the arithmetic must be forced integer. |

### C. Balance reads feeding logic/views/JSON (return-type parity)

`MY_Controller.php:57`, `Wallet.php:22,151,240`, `Marketplace.php:18`, `Rentals.php:71`, `Team.php:79,136`, `User_model.php:253` — all consume `get_balance()`; `Admin_model.php:283,406,486` consume `lock_and_get_balance() === false`. Once both helpers return strict `int`, every consumer is integer-typed automatically.

### D. Controller/input boundaries

| Site | Line(s) | Problem class |
|---|---|---|
| `Wallet::topup()` & `submit_withdrawal()` — `$amount = preg_replace('/[^0-9]/', '', post('amount'));` then loose `$amount > 0` | Wallet.php 49, 218-246 | **Silent input corruption**: `"10000.50"` → `"1000050"`, `"1e5"` → `"15"` (dot/e stripped, digits merged), `"100,000"` → `"100000"` accepted. No CI3 `integer`/`is_natural_no_zero` rule; string-vs-int loose comparisons. |
| `Admin::inject_balance()` — `$amount = floatval($this->input->post('amount', TRUE));` + `$amount <= 0` | Admin.php 642-645 | **Audit-M8 exact quote**: accepts fractional floats (e.g. `0.001`); `floatval` of `"1e5"` = `100000.0` passes. |
| `Wallet::index` enrichment — `$inv->total_payable = (float) $inv->amount + $inv->deposit_fee;` | Wallet.php 38-39 | Float addition for display enrichment. |
| `Admin` CSV/xray — `(float)` casts of `gross_eff/fee_amount/net_amount`; `(string)(int) round((float)$value)` canonicalizer | Admin.php 1240-1242, 1247, 1289 | Float detours in export/display helpers; safe today, but violates the integer-only contract; recompute path already feeds `calculate_withdrawal_fee((int)$gross)`. |

### E. Display & API parity

- Views use `number_format($val, 0, ',', '.')` — safe once values are ints (float `100000.9` would *round* on display while ledger held `100000`; the drift is at write time, which A-D fix).
- JSON APIs: `Team.php:79,136` emit `new_balance` (int via `get_balance`) ✓; `Rentals.php:142` / `Team.php:143` format `(float) $result['amount']` for notification text; `claim_roi` returns amount as float today → JSON `10000.0` possible. Fix at source (return int).

---

## 2. PROPOSED POLICY (integer-IDR contract end-to-end)

1. **Balance reads** — `get_balance()`: single SQL `COALESCE(SUM(CASE …credit…) − SUM(CASE …debit…), 0)` and `return (int) $row->balance;`. `lock_and_get_balance()`: same, `return (int) $row->balance;`. Both always return strict `int` (empty → `0`), never string/float; keep `false` sentinel only in the lock variant.
2. **Mutation choke point** — `_post()`: `$amount = (int) $amount;` + explicit guard: `$amount <= 0 → log + return false` (reject non-positive at the single write gate; the current `== 0` skip is folded into this guard's semantics — a zero/negative credit is a programming error, not a no-op). All `credit()/debit()` callers pass `(int)` (drop every `(float)` cast on money).
3. **Fee / percentage math (rounding policy)** —
   - Withdrawal tier fee: **floor semantics preserved with pure integer division**: `fee = intdiv($gross * $bps, 10000) + (int) $cfg['fixed_fee'];` (`intdiv` == floor for non-negative operands; gross ≥ 1 and bps ≥ 0 so no sign trap). Same result as today, no float.
   - Deposit percent fee: convert the admin pct (≤ 2 decimals, ≤ 5%) to **integer basis points once** at config normalization: `bps = (int) round($pct * 100)` (0.70 → 70 bp). Then `fee = intdiv($amount * $bps, 10000)`. Flat type stays `(int) value`. `round()` is used **only** for the config unit conversion — never on money; money is truncated/floor only, matching the withdrawal-fee docblock convention.
   - Wages/commissions/ROI: whole-IDR constants and whole-IDR product rows; force integer multiplication by casting row values `(int)` **before** arithmetic (`$payout = $days * (int) $rental->daily_roi;`), so no DECIMAL-string float coercion can occur.
4. **Input validation** — replace `preg_replace`-strip + `floatval` with rules that reject non-integer syntax outright:
   - Wallet topup/withdraw: accept only canonical digits → CI3 `required|integer|greater_than[0]` (or explicit pre-check `preg_match('/^[1-9][0-9]*$/')` on the raw post) — `"10000.50"`, `"1e5"`, `"100,000"`, `"0"`, negatives are all rejected, never silently rewritten. Verify the frontend sends plain digits (views/JS) before choosing rule; keep behavior aligned with the UI's id-ID currency formatter if it posts formatted text (then parse-with-reject, never strip-and-merge).
   - Admin inject: CI3 `integer|greater_than[0]` + `(int)` cast; `0.001`/`1e5` → "Data inject tidak valid."
5. **Display/API parity** — monetary amounts reach views/JSON strictly as int; `number_format($x, 0, …)` everywhere (already convention); notification/CSV helpers drop `(float)` wrappers on money.

---

## 3. IMPLEMENTATION CHECKLIST (post-approval)

1. **Write this blueprint file** (this doc) — done at plan-approval step.
2. `application/models/Wallet_model.php`:
   - `get_balance()` → single query, `(int)` return.
   - `lock_and_get_balance()` → `(int) $row->balance`; docblock `@return int|false`.
   - `_post()` → `(int)` cast + `<= 0` guard (log + false).
   - `calculate_withdrawal_fee()` → `intdiv` formulation.
   - `calculate_deposit_fee()` → basis-point integer path.
   - `_norm_pct`/config: percent stored to 2dp stays float *in config* but is converted to integer bp inside the fee calculator (or normalized to bp at merge time — pick one, document in docblock).
   - `create_deposit()` → `(int) $amount` boundary cast; `approve_deposit_simulator()`/`create_withdrawal()` → `(int)` debit/credit amounts.
3. Dependent models: `Rental_model::checkout_rental` + `claim_roi` (int compare/payout/multiply), `User_model::claim_wage` (int), `Admin_model::inject_balance` (int + int audit detail).
4. Controllers: `Wallet::topup`, `Wallet::submit_withdrawal`, `Admin::inject_balance` integer validation + casts; `Wallet::index` `total_payable` int; Admin CSV/xray float detours → int.
5. Display/API parity: ROI/claim result amounts int (JSON ints), notification/CSV `number_format` on ints.

---

## 4. VERIFICATION PROTOCOL

1. `php -l` every modified PHP file.
2. **Static grep guards** (must return no matches after fix):
   - `grep -rn "(float).*amount\|(float).*balance\|(float).*price\|(float).*roi\|(float).*payout" application/` (money paths float-free).
   - No `insert('wallet_ledger'` / `UPDATE users SET balance` outside `Wallet_model.php` (pre-existing guard, re-check).
3. **CLI type/smoke probes** (`php -r` against model code or a tiny throwaway harness in /tmp — not committed):
   - `get_balance()` on empty ledger → `0`, `gettype() === 'integer'`.
   - `lock_and_get_balance()` → `integer`; `false` sentinel preserved for missing user.
   - `_post`-guard: credit/debit with `0`, `-5`, `10.5` → rejected (`false`); `5000` → OK; ledger row `amount` has no fraction.
   - `calculate_withdrawal_fee`: boundary tiers (100000→1000bps tier, 500000→750, 999999/1000000 split) and values where `gross*bps/10000` is non-integer (e.g. 123456 × 1000/10000 = 12345.6 → fee 12345 + fixed) — assert equality with the old floor formula for a fixed corpus.
   - `calculate_deposit_fee`: pct `0.70`, amount 1_000_000 → 7000; amount 3 → 0 (floor); flat type.
   - `claim_roi` payout path: `actual_claimable × (int) daily_roi` is int for whole-IDR products.
4. **curl smoke** (dev server, `synapse.test` or `localhost:8080`):
   - `POST wallet/topup` with `10000.50` / `1e5` / `abc` → flash "Nominal tidak valid." (NOT silent `1000050`).
   - `POST wallet/withdraw_process` with `10000.50` → rejected; with valid digits → 302.
   - Admin `inject_balance` with `0.001` → "Data inject tidak valid."; with `100000` → success + ledger row whole.
   - ROI claim success JSON: `amount` and `new_balance` are JSON integers (no `10000.0`).
5. **Read-only DB recon** (no writes): `SELECT COUNT(*) FROM wallet_ledger WHERE amount <> FLOOR(amount)` and same for `withdrawals`/`deposits`/`users.balance` → 0 (confirms invariant; any hit logged and reported, not auto-fixed in this round).

---

## 5. OUT OF SCOPE / DEFERRED

- Schema migration of `DECIMAL(15,2)` → `BIGINT` (decision `dec-5776ed3a88018f17`; tracked for a future round).
- M6 double-entry `transactions` (decision-gated), M7 settings consolidation, M9 JSON envelope, M10 orphans — separate rounds per plan/66.

*End of plan — analysis only; no application code changed, no schema altered, no code executed against the live DB.*
