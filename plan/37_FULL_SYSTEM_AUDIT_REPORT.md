# 37 — FULL SYSTEM AUDIT REPORT (360°)

**Scope:** Entire Synapse platform — user layer, admin panel, DB schema, transaction atomicity, state machines, security, UI/UX, docs alignment.
**Mode:** READ-ONLY / AUDIT. No application code modified.
**Method:** Static audit of `application/controllers`, `application/models`, `application/core`, `application/views`, `database.sql`, `database_seed.sql`, `docs/1_PRD.md`, `docs/3_ROADMAP.md`, `AGENTS.md`, `application/config/*`.
**Date:** 2026-09-02. **Auditor:** Principal Architect / Security / QA.

> **Headline:** The platform has strong hygiene (CSRF, rate limits, reCAPTCHA fail-closed, audit logging, security headers, bound-param SQL, dual-auth separation), but **the money layer has 4 critical race/double-spend-class vulnerabilities, 1 broken withdrawal schema path, and a dual-ledger drift**, plus meaningful PRD drift in the affiliate/wage engine and zero cron automation.

---

## 1. EXECUTIVE SUMMARY

| Severity | Count | Theme |
|---|---|---|
| 🔴 Critical (Security / Financial / Race) | 7 | C1–C7 |
| 🟠 Medium / Functional | 10 | M1–M10 |
| 🟡 Minor / Polish | 8 | P1–P8 |

**Critical summary:**
- **C1 — Deposit simulator double-credit** (money printer): `Wallet::simulate_payment()` is a logged-in **GET** route; `approve_deposit_simulator()` sets `status='success'` and inserts a `wallet_ledger` credit **unconditionally** — calling it twice on the same invoice credits twice; no ownership check (invoice format `INV-YmdHis-userId` is guessable).
- **C2 — ROI claim lost-update race**: `Rentals::claim()` reads `days_processed`/`last_claimed_at` without `FOR UPDATE` and writes back stale values; two concurrent POSTs both pass the T+1/claimable checks → double payout. No `expired_at`/`status='active'` guard either.
- **C3 — Withdrawal insert violates schema**: `create_withdrawal()` inserts `amount`, but `withdrawals` requires `gross_amount`/`fee_amount`/`net_amount` NOT NULL (seed only adds `wd_number` + `amount`) → new withdrawals fail unless the live DB was custom-migrated. Two CSV exports select columns that don't exist (`user_rentals.product_name`, `withdrawals.bank_name/account_number`) → fatal.
- **C4 — Dual-balance drift**: `users.balance` updated only by `claim_level1`/`claim_wage`; every other money move writes only `wallet_ledger`. Stale `balance` shown in Admin user list and Team claim responses. `Ledger_model::insert_transaction` + `transactions` table are **dead code** (no callers) — the PRD double-entry ledger is not implemented.
- **C5 — Withdrawal & checkout balance race**: balance check happens outside the TX, debit inserted without row lock → concurrent requests can overspend (negative balance).
- **C6 — Weekly wage TOCTOU**: `claim_wage()` re-checks cooldown inside TX but sets `last_wage_claimed_at` unconditionally (no atomic conditional) → concurrent requests double-claim. (`claim_level1` is race-safe via `WHERE is_level_1_claimed = 0`.)
- **C7 — User self-approval of withdrawals**: `Wallet::simulate_wd_approve()` (GET) flips any WD to `success` — a user can approve their own pending withdrawal without admin.

**Other key themes:** no cron automation anywhere (rental expiry, daily ROI, weekly wage auto-pay per PRD); withdrawal fee tiers / Mon–Sat 07:00–19:00 window / Rp 50jt max not implemented; wage tier thresholds & L1 criteria differ from PRD; two key-value settings stores; audit coverage is complete; CSRF + AJAX tokens handled; empty states are well covered.

---

## 2. CRITICAL FINDINGS (Security / Financial / Race conditions)

### C1. Deposit simulator double-credit — money printer (CRITICAL)
- **Files:** `application/controllers/Wallet.php` (`simulate_payment`), `application/models/Wallet_model.php` (`approve_deposit_simulator`), route `wallet/simulate_payment/(:any)`.
- **Issue:** `approve_deposit_simulator()` runs inside `trans_start()`, but: (1) updates `deposits.status='success'` **without any `WHERE status='pending'` guard**; (2) **unconditionally** inserts a `wallet_ledger` credit afterwards. A second call (or two concurrent calls) on the same invoice inserts a second credit. (3) No ownership check — any logged-in user may simulate payment on any invoice whose number they guess. (4) State mutation via GET, contradicting the project's own POST-only hardening (10B).
- **Exploit sketch:** register → `topup` 100.000 → hit `/wallet/simulate_payment/INV-20260902-101500-{uid}` twice → balance credited Rp 200.000.
- **Fix:** conditional `UPDATE ... SET status='success' WHERE invoice_number=? AND status='pending'`; credit only when `affected_rows()===1`; verify `deposits.user_id === session user_id`; POST-only; move behind a UAT-only flag.

### C2. ROI claim lost-update race — double payout (CRITICAL)
- **Files:** `application/controllers/Rentals.php` (`claim`), `application/models/Rental_model.php` (`claim_roi` — unused by controller).
- **Issue:** rental row is read with plain `get_where` (no `FOR UPDATE`); `claimable_days`/`remaining_days` computed from that snapshot; then `UPDATE user_rentals SET days_processed = {stale}+n, last_claimed_at = now`. Two concurrent POSTs both read `days_processed=0`, both compute claimable=1, both insert ROI credits → double payout. `transaction_id = 'ROI-'.time().'-'.$rental_id` is non-unique (same second) so the ledger cannot dedupe.
- **Secondary:** no `status='active'` check and no `expired_at > NOW()` check — an expired rental (status never flipped — see M3) is still claimable until `days_processed` reaches `total_days`.
- **Fix:** `trans_begin` + `SELECT ... FOR UPDATE` on the rental row, or conditional `UPDATE ... SET days_processed = days_processed + ? WHERE id=? AND days_processed = ?` with `affected_rows()===1` as the concurrency guard; add unique key on `wallet_ledger.transaction_id`.

### C3. Withdrawal insert violates schema; CSV exports reference missing columns (CRITICAL)
- **Files:** `application/models/Wallet_model.php` (`create_withdrawal`), `application/models/Admin_model.php` (`get_all_withdrawals`, `get_active_rentals`).
- **Issue A:** `database.sql` defines `withdrawals(gross_amount, fee_amount, net_amount ... NOT NULL)` with **no defaults**. `database_seed.sql` only adds `wd_number` and `amount`. `create_withdrawal()` inserts `user_id, wd_number, amount, bank_account_id, status` — `gross_amount/fee_amount/net_amount` remain unset → MySQL strict-mode **NOT NULL violation → every new withdrawal fails** (`trans_status() === false`). This is the **withdrawal feature being broken end-to-end** on the canonical schema. *(Verify against live DB — if a live migration added defaults, this is downgraded to "schema drift between repo files and code".)*
- **Issue B:** `get_all_withdrawals()` selects `bank_name, account_number` from `withdrawals` (they live in `bank_accounts`); `get_active_rentals()` selects `product_name` from `user_rentals` (FK `product_id` only). Both `export_csv` cases throw SQL errors → HTTP 500, no partial download.
- **Fix:** add `gross_amount/fee_amount/net_amount` (or defaults) + `bank_name`/`account_number` joins in the queries; align `database.sql` and `database_seed.sql`; implement PRD fee tiers (M1) so `fee_amount/net_amount` are real.

### C4. Dual-balance ledger drift + dead double-entry code (CRITICAL)
- **Files:** `application/models/Ledger_model.php`, `application/models/Wallet_model.php` (`get_balance`), `application/models/User_model.php` (`claim_level1`/`claim_wage`), `application/controllers/Admin.php` (users list), `application/controllers/Team.php` (`claim_level1`).
- **Issue:** AGENTS.md states wallet balance = `SUM(credit)−SUM(debit)` on `wallet_ledger`. Only `claim_level1`/`claim_wage` also mutate `users.balance`. Deposits, rental purchases, ROI claims, withdrawals, refunds, admin injections update **only** `wallet_ledger`. Consequences:
  - `Team::claim_level1` returns `$user->balance` (stale, often 0) as `new_balance` — users see a wrong post-claim balance.
  - `Admin::users` list renders `u.balance` — stale vs. `get_user_balance()` (wallet_ledger-based) used in `user_detail`.
  - `Ledger_model::insert_transaction()` (SELECT FOR UPDATE + `transactions` row + `users.balance` update) has **zero callers** — dead code. The `transactions` table is never written. PRD §Level-1 Zero-Trust step 6 requires a `transactions` row (`type: commission_bonus`); not implemented.
- **Fix:** pick ONE source of truth. Either (a) stop using `users.balance` entirely (drop/ignore column, fix Team/Admin display), or (b) make every money move write both atomically via a single ledger service (revive `Ledger_model` and route all credit/debit through it). Then remove dead code.

### C5. Balance check outside TX — withdrawal & checkout overspend race (CRITICAL)
- **Files:** `application/controllers/Wallet.php` (`process_withdraw`), `application/controllers/Rentals.php` (`checkout`).
- **Issue:** `get_balance($user_id) >= $amount` runs before `trans_start()`; the debit is inserted with no `FOR UPDATE` on the user row and no conditional guard. Two concurrent withdrawals (or two checkouts) can both pass the check → double debit → negative balance. `Ledger_model` already demonstrates the correct pattern (`SELECT ... FOR UPDATE`) — it's just not used here.
- **Fix:** move the balance check inside the TX with `SELECT balance FROM users ... FOR UPDATE` (or `SUM(wallet_ledger) ... FOR UPDATE` / a conditional debit) and roll back if insufficient.

### C6. Weekly wage claim TOCTOU — double wage (CRITICAL)
- **Files:** `application/models/User_model.php` (`claim_wage`).
- **Issue:** cooldown is re-verified inside the TX, but `last_wage_claimed_at` is updated **unconditionally** (`WHERE id = ?` only). Two concurrent AJAX calls both see `claimable=true`, both update, both insert the wage credit → double payout. `claim_level1` is safe (atomic `WHERE is_level_1_claimed = 0` + `affected_rows`); `claim_wage` lacks the same pattern.
- **Fix:** conditional `UPDATE users SET last_wage_claimed_at = NOW() WHERE id = ? AND (last_wage_claimed_at IS NULL OR last_wage_claimed_at < NOW() - INTERVAL 7 DAY)` and gate the credit on `affected_rows() === 1`.

### C7. User self-approval of withdrawals (CRITICAL)
- **Files:** `application/controllers/Wallet.php` (`simulate_wd_approve`), `application/models/Wallet_model.php` (`approve_withdrawal_simulator`).
- **Issue:** any logged-in user can call `GET /wallet/simulate_wd_approve/WD-...` to flip a **pending → success** withdrawal — self-approving their own payout and bypassing admin review. WD numbers (`WD-YmdHis-userId`) are guessable for other users (unauthorized state mutation, no ownership check). GET-mutation again.
- **Fix:** remove from user-facing routes or gate behind `ENVIRONMENT !== 'production'` + admin session; enforce ownership; POST-only.

---

## 3. MEDIUM / FUNCTIONAL GAPS

### M1. PRD withdrawal rules missing (fee, window, max)
`docs/1_PRD.md` §121–125: Mon–Sat 07:00–19:00 WIB window, min Rp 100.000 ✓ (implemented), **max Rp 50.000.000 ✗**, **fee tiers ✗ (no `fee_amount`/`net_amount` ever computed)**. `has_reached_daily_wd_limit()` uses MySQL `CURDATE()` — same timezone-mismatch class fixed for rate limits in Phase 10B (MySQL server often UTC vs PHP Asia/Jakarta); boundary at 00:00–07:00 WIB is wrong. Use PHP-generated dates.

### M2. Affiliate wage engine drifts from PRD (tiers, criteria, automation)
- **Tiers:** code `WAGE_TIERS` = {9→200k ✓, 30→1M, 70→2.5M, 130→5M, 190→9M}; PRD = {9→200k, 18→500k, 40→1.5M, 90→4M, 190→9M}. Only the L2 and L6 endpoints match; mid tiers are richer in code.
- **L1 criteria:** code requires 3 **direct (B)** active downlines + sales ≥ 330.000; PRD says **"3 Agen B+C Aktif"** and zero-trust step 2: sum sales of **active L1 + L2** downlines. Code ignores C (L2) → under-qualifying vs spec.
- **Wage population:** code counts the **entire tree (B+C+D+E+F)** via recursive CTE; PRD says "B+C Aktif". Code is over-generous.
- **Automation:** PRD §167 — cron **Monday 01:00 WIB automatic distribution**; code is manual claim + 7-day cooldown (`last_wage_claimed_at`). Documented as intentional in plans, but spec drift vs v5.0 PRD remains.

### M3. No rental expiration / ROI cron (state machine incomplete)
No code flips `user_rentals.status` active→completed at `expired_at`; no daily-ROI cron (PRD §32 "Sistem/Cron"). Consequences: expired rentals keep counting for `count_active_b_downlines`, `has_active_rental` (withdrawal gatekeeper), team `is_active`, admin treasury `pending_roi` → phantom obligations & inflated eligibility. `Rentals::claim` doesn't check `expired_at`.

### M4. Admin approve/decline double-submit race
`Admin::approve_deposit` / `approve_withdrawal` / `decline_withdrawal` read `status === 'pending'` then update — safe for sequential duplicates, but two concurrent clicks both pass the read → double credit (deposit) or double refund (decline). Use conditional `UPDATE ... WHERE status='pending'` (or FOR UPDATE) inside the TX.

### M5. Phone normalization vs `is_unique` ordering (register & admin create_user)
`is_unique[users.phone]` validates the **raw** input; `$_POST['phone'] = $normalized` happens after `form_validation->run()`. `628xx` vs `08xx` variants both pass validation; DB `uk_phone` then rejects → generic confusing error instead of a friendly duplicate message. Normalize before validation (or validate with a callback on normalized value).

### M6. `transactions` double-entry ledger not implemented
PRD mandates a double-entry `transactions` ledger (`balance_after`, types incl. `commission_bonus`, `daily_revenue`). The table exists; `Ledger_model` exists but is unused; `wallet_ledger` is the de-facto single-entry store. Decide & document: either implement double-entry via `Ledger_model` (all money moves) or amend the PRD/ERD to single-ledger and drop the dead table/model.

### M7. Two settings stores with divergent shapes
`site_settings` (`setting_value`) holds `wa_number`/`support_email` (read by `get_all_settings`, Admin settings page, Help page); `system_settings` (`key_value`) holds `is_registration_open` (read by `get_setting`, dashboard, Auth register, toggle_registration). Drift-prone; no single admin surface. Consolidate into one table + one model API.

### M8. Money type discipline
`wallet_ledger.amount`/`users.balance` are DECIMAL(15,2); `Wallet_model::get_balance` returns `(int)` — truncates decimals; `Admin::inject_balance` accepts `floatval` (e.g. 0.001). With IDR-only and whole-rupiah products this is latent, but a float path exists. Standardize on integer IDR (or string decimal) end-to-end.

### M9. Inconsistent API error envelope
Rate-limit helper: `{success,error,message,retry_after}` + HTTP 429. Team claim endpoints: `{success,message}` always HTTP 200. Notification endpoints: `{success,error}`. No shared JSON error contract; some error paths leak HTML (`validation_errors()` set into flashdata). Standardize a JSON envelope helper + status codes.

### M10. Unused/orphaned schema & code
- `rentals` table (legacy: `gpu_product_id`, `daily_rate_snapshot`) — nothing reads/writes it; `user_rentals` is the live table.
- `otp_logs` — created, no OTP flow uses it.
- `application/controllers/User.php` duplicates `Notification::mark_all_read` (`User::read_notifications`) — pick one.
- `Rental_model::claim_roi()` — unused (controller inlines its own TX).

---

## 4. MINOR / POLISH GAPS

- **P1.** `simulate_payment`/`simulate_wd_approve` are GET mutations — contradict the 10B POST-only policy (see C1/C7). Convert or gate.
- **P2.** `Wallet::topup` doesn't check `create_deposit` result; `INV-YmdHis-userId` can collide on same-second double-submit → DB unique violation while the UI still flashes "success".
- **P3.** Notification history capped at 100 rows, no pagination (`Notification::index`).
- **P4.** No empty-state for `marketplace` product list (masked by mock fallback) and `admin/users` empty search results (verify — `users.php` has no `empty($users)` branch seen in scan).
- **P5.** `Team::claim_level1` success message hardcodes "Rp 80.000" while amount lives in `claim_level1()` — keep in sync.
- **P6.** `has_reached_daily_wd_limit` includes `processing` status — enum supports it; fine, but ensure the PRD daily limit counts only success/pending (spec ambiguity).
- **P7.** 404/403/500 use stock CI3 error views (headers injected correctly via `MY_Exceptions`/`MY_Output` ✓); no branded/customized error pages, no JSON 500 envelope for API routes.
- **P8.** `Rentals::index` re-implements the claimable-days math already in `claim()` — two copies of the T+1/2-day-cap rule (drift risk; seen identical in both).

**Positive notes:** empty states are present across rentals/wallet/team/notification/admin (dashboard, history, audit, analytics, user_detail); dual-theme (dark/light) via CSS vars is wired; CSRF enabled with `csrfFetch()` token injection for AJAX; all SQL uses bound params; admin audit coverage is complete for all 13 state-changing actions.

---

## 5. ROADMAP & SPEC STATUS MATRIX

| Feature (source) | Status | Evidence / Drift |
|---|---|---|
| Dual-auth separation, cloaked `/control-panel` | ✅ Done | `MY_Controller` vs `Admin` guard; routes |
| reCAPTCHA v2 fail-closed, strict SSL, env keys (10D) | ✅ Done | `Auth::_verify_recaptcha`, `recaptcha.php` config |
| Rate limiting 10B (login/register/admin/WD/claim) | ✅ Done | `Rate_limit_model` atomic upsert; PHP-tz timestamps |
| Audit logging 10A — all admin state changes | ✅ Done | `Audit_model` inside TX at every Admin mutator |
| Ban UI + session kick, forced password change | ✅ Done | `MY_Controller` guards; `Auth::change_password` |
| CSRF + AJAX token | ✅ Done | `csrf_protection=TRUE`, `csrfFetch()` in team view |
| Env-driven secrets, security headers (10D) | ✅ Done | `.htaccess`, `MY_Output::emit_security_headers`, `MY_Exceptions` |
| Admin theme manager / user theme manager | ✅ Done | plan 30–33; CSS-var themes |
| Pagination (users, history, audit), CSV export | ⚠️ Partial | CSV rentals/withdrawals **broken** (missing columns, C3) |
| Treasury health + circuit breaker (registration toggle) | ✅ Done | `get_treasury_stats`, `toggle_registration` |
| Leaderboard / financial x-ray / analytics | ✅ Done | recursive CTE; `get_user_xray` (withdrawals.amount OK post-seed) |
| **Rental lifecycle: creation → ROI → expiry** | ⚠️ Partial | Creation + manual claim OK; **no auto-expiry, no daily-ROI cron** (M3); claim race (C2) |
| **Withdrawal: window, min/max, fees** | ⚠️ Partial | Min 100k ✓; window/max/fees **missing** (M1); insert broken on canonical schema (C3) |
| **Affiliate L1 bonus (3 B+C, 330k, one-time 80k)** | ⚠️ Partial | Amount ✓; criteria count B only, not B+C (M2); manual claim ✓ |
| **Wage L2–L6 tiers + Monday 01:00 cron** | ⚠️ Partial | Tiers drift (M2); manual claim + cooldown instead of cron; TOCTOU (C6) |
| **Double-entry ledger (`transactions` + balance_after)** | ❌ Missing | `Ledger_model` dead; `transactions` never written (C4/M6) |
| **OTP flow** | ❌ Missing | `otp_logs` table only |
| IDR-only everywhere | ✅ Done | money display + PRD enforced |

---

## 6. PRIORITIZED ACTION PLAN

**P0 — Stop-loss (fix first, one PR per item):**
1. **C1** — Guard `approve_deposit_simulator` with `WHERE status='pending'` + `affected_rows()===1` + ownership + POST-only.
2. **C2** — `SELECT ... FOR UPDATE` (or conditional update) in `Rentals::claim`; add `expired_at`/`status` guard; unique `transaction_id`.
3. **C3** — Align schema: defaults/columns for `withdrawals` (gross/fee/net) + fix CSV queries (`bank_accounts` join; `gpu_products` join for `product_name`); add regression check (create + export).
4. **C5** — Move balance checks inside TX with row lock in `process_withdraw` and `checkout`.
5. **C6** — Atomic conditional update for `last_wage_claimed_at` in `claim_wage`.
6. **C7** — Remove/flag-gate user-accessible WD simulator.

**P1 — Integrity & spec (next sprint):**
7. **C4/M6** — Single source of truth: route all money moves through one ledger service (revive `Ledger_model` with `wallet_ledger` semantics), sync `users.balance` or deprecate; fix Team/Admin stale-balance displays; remove dead code.
8. **M1** — Implement fee tiers, max 50jt, Mon–Sat 07:00–19:00 window, PHP-tz daily-limit.
9. **M2** — Reconcile wage tiers + L1 "B+C" criteria + wage population ("B+C") with PRD; decide cron vs manual claim and document.
10. **M3** — Add a cron/CI-job (or lazy check on claim) to expire rentals (`status='completed'`) and, if PRD-aligned, auto-distribute ROI.
11. **M4** — Conditional `UPDATE ... WHERE status='pending'` for admin approve/decline.

**P2 — Hardening & polish:**
12. **M5/M9/P2** — Normalize phone before validation; standardize JSON error envelope; check `create_deposit` result.
13. **M7/M10/P3/P5/P8** — Consolidate settings tables; remove orphaned `rentals`/`User` controller/`claim_roi`; notification pagination; dedupe claimable-days math; centralize bonus amount constant.
14. **P1/P4/P6/P7** — POST-only for simulators; empty states (marketplace, admin users search); branded error views + JSON 500 envelope.

**Verification after fixes:** `php -l` on every touched file; manual flow test via `php -S localhost:8080` + `curl` (register → topup → deposit approve → checkout → claim → withdraw → approve; two concurrent claim/WD POSTs must not double-credit); run `export_csv` for all three types; re-run this audit checklist.

---

*End of report — plan/37_FULL_SYSTEM_AUDIT_REPORT.md. Read-only audit; no application code changed.*
