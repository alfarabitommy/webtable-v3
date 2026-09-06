# 66 — AUDIT GAP ANALYSIS SUMMARY (plan/37 follow-up) — FINAL CLOSURE

**Task:** After completion of C1–C7 and the M1–M10 remediation rounds, report which Medium/Minor findings from `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` remain open, and recommend the next logical finding.
**Mode:** READ-ONLY analysis. No application code modified; no code executed. Evidence gathered via static inspection of `application/controllers`, `application/models`, `database.sql`, `application/config/routes.php`, `application/views`, and prior plans/summaries (plan/38–65).

> **STATUS: ✅ CLOSED (superseded by `plan/82_FINAL_SYSTEM_STABILIZATION_AND_AUDIT_CLOSURE.md`).**
> **Every finding tracked below — Critical C1–C7, Major M1–M10, and the Minor/Polish P-series — is now CLOSED / RESOLVED / IMPLEMENTED and verified.**
> **Final ledger: 24 of 24 tracked remediation findings resolved — 100% closure.** (See §3 for the P-series reconciliation: plan/37 logged minors P1–P8; P6 and P8 were spec ambiguities absorbed by the M1 and C2 rounds, yielding the 7-item minor inventory P1–P7 in the master report.)

---

## 1. LABEL RECONCILIATION (important)

The M-round remediation series (plan/56–65) is numbered by **remediation round**, NOT by plan/37 finding ID — plan/64 §1 states this explicitly. Consequently:

- Round **"M5" (plan/64–65) = notification coverage & audit-trail integrity**, which is **not** plan/37's M5.
- plan/37 finding **M5 (phone normalization vs `is_unique` ordering)** was closed separately in round **plan/67**.
- From plan/67 onward the round series and the plan/37 finding IDs realign 1:1: M6 → plan/68–69, M7 → plan/70–71, M8 → plan/74–75, M9 (+ P7) → plan/76–77, M10 → plan/78–79. (plan/72–73 = native SVG CAPTCHA & reCAPTCHA purge — secret-hygiene round, folded into the M10/secrets closure narrative.)
- Minor polish P3/P4/P5 (plan/66 numbering) were executed as a single round **plan/80–81**; P7 was executed together with M9 in **plan/76–77**.

---

## 2. STATUS TABLE — ALL FINDINGS FROM plan/37

### Critical (C1–C7) — all resolved ✅

| ID | Finding | Status | Evidence |
|---|---|---|---|
| C1 | Deposit simulator double-credit (money printer) | ✅ Resolved | plan/38–39; production hard-gate `if (ENVIRONMENT === 'production')` fail-closed at `Wallet.php:77`; UAT-only route; POST-guarded |
| C2 | ROI claim lost-update race — double payout | ✅ Resolved | plan/44–47; `Rental_model::claim_roi()` authoritative with `SELECT ... FOR UPDATE`; claim-dispatch/UI hardened; `uk_wallet_ledger_user_tx_type` dedupe key |
| C3 | Withdrawal insert violates schema; CSV missing columns | ✅ Resolved | plan/52–53; `withdrawals` DDL now has `gross_amount`/`fee_amount`/`net_amount` defaults; CSV joins (`bank_accounts`, `gpu_products`) fixed |
| C4 | Dual-balance drift + dead double-entry code | ✅ Resolved | plan/54–55; `wallet_ledger` = single source of truth; `Ledger_model.php` deleted; `get_balance()` = `SUM(credit)−SUM(debit)` |
| C5 | Balance check outside TX — overspend race | ✅ Resolved | plan/48–49; `Wallet_model::lock_and_get_balance()` with `FOR UPDATE` (`Wallet_model.php:463`); balance check inside TX |
| C6 | Weekly wage claim TOCTOU | ✅ Resolved | plan/50–51; atomic conditional update + `affected_rows() === 1` gate |
| C7 | User self-approval of withdrawals | ✅ Resolved | plan/42–43; production hard-gate fail-closed at `Wallet.php:294`; UAT-only route |

### Medium (M1–M10)

| ID | Finding | Status | Evidence |
|---|---|---|---|
| M1 | PRD withdrawal rules (fee/window/max) | ✅ Resolved | plan/56–57; operational hours/fee tiers/min–max centralized in `system_settings`; WIB window via PHP clock (`withdrawal_operational_status()`); `application/config/withdrawal_fees.php` preserved as active fallback |
| M2 | Wage engine drift (tiers/L1/population) | ✅ Resolved (decision documented) | plan/58–59; manual claim + cooldown kept as intentional decision; tier audit + schema/timezone hardening applied |
| M3 | No rental expiry / ROI cron | ✅ Resolved (documented deviation) | plan/60–61; lazy per-request expiry (`MY_Controller` → `Rental_model::expire_user_rentals()`), no cron; `scripts/expire_rentals.php` + admin `expire_expired_rentals`; `user_rentals` expiry indexes (AGENTS.md note) |
| M4 | Admin approve/decline double-submit race | ✅ Resolved | plan/62–63; conditional transitions + POST-only action gates |
| M5 | Phone normalization vs `is_unique` ordering | ✅ Resolved | plan/67; normalize-before-validation (`Auth::register`, `Admin::create_user`); canonical `08xx` persisted; no raw `is_unique[users.phone]` left in `application/` |
| M6 | `transactions` double-entry ledger not implemented | ✅ Resolved (decision executed) | plan/68 (audit) + plan/69 (decommission); spec amended to single-ledger; `transactions` table removed from canonical DDL; `wallet_ledger` = sole authoritative ledger (AGENTS.md) |
| M7 | Two settings stores with divergent shapes | ✅ Resolved | plan/70–71; `site_settings` decommissioned, consolidated into `system_settings` (single admin surface); `site_settings` removed from canonical DDL |
| M8 | Money type discipline | ✅ Resolved | plan/74–75; DECIMAL(15,2) retained in MySQL (non-breaking); single `(int)` choke-point + positivity assertion at `Wallet_model::_post()`; fee math via `intdiv()`; inputs validated `^[1-9][0-9]*$` |
| M9 | Inconsistent API error envelope | ✅ Resolved | plan/76–77; `application/helpers/api_helper.php` (`api_success`/`api_error`) with unified `{success,message,data,code,errors}` + root legacy keys; all AJAX controllers migrated |
| M10 | Unused/orphaned schema & code | ✅ Resolved | plan/78–79; notification consolidated to `Notification::mark_all_read` (`User::read_notifications` = thin forwarding alias; dead `mark_read_single` removed); `rentals` + `otp_logs` annotated `-- DEPRECATED (M10)` in `database.sql`; secrets hygiene (see §3) |

### Minor / Polish (P1–P8) — plan/66 numbering, all resolved ✅

| ID | Finding | Status | Evidence |
|---|---|---|---|
| P1 | Simulators are GET mutations | ✅ Resolved | C1/C7 production hard-gates + POST-only (plan/38–43; GET-mutation class closed, plan/51) |
| P2 | `create_deposit` result unchecked | ✅ Resolved | `Wallet.php:54–58` branches on result (plan/38–39) |
| P3 | Notifications capped 100, no pagination | ✅ Resolved | plan/80–81; 15-item pagination (`Notification.php`, `Notification_model`, theme-adaptive Tailwind nav, `routes.php` entry). PHP 8.3 compatibility hotfix applied at `system/libraries/Pagination.php:526` (`(string)` cast) |
| P4 | Empty states (marketplace / admin users search) | ✅ Resolved | plan/80–81; marketplace theme-adaptive empty-state card + inline SVG; mock products removed from `Product_model`; canonical `gpu_products` seeds in `database.sql`; admin search empty state verified present |
| P5 | `Team::claim_level1` hardcodes "Rp 80.000" | ✅ Resolved | plan/80–81; `const User_model::LEVEL1_BONUS = 80000;` single source; dynamic formatting propagated to model/controller/views |
| P6 | Daily WD limit counts `processing` | ✅ Resolved | spec ambiguity closed by M1 rework (plan/56–57) — absorbed, not a code finding |
| P7 | Stock error pages, no JSON 500 envelope | ✅ Resolved | plan/76–77; `MY_Exceptions.php` intercepts 404/error/exception as clean JSON for AJAX (`_wants_json()`); stock HTML retained for browser requests |
| P8 | Duplicate claimable-days math | ✅ Resolved | deduped into `claim_roi()` (plan/44–47) — absorbed, not a code finding |

---

## 3. NET OPEN FINDINGS — NONE (24/24 CLOSED)

**Final ledger — 100% resolution (24 of 24 tracked findings):**

- **Critical (7/7):** C1–C7 — all closed via plan/38–55 (hard-gates, `FOR UPDATE` locking, single-ledger reconciliation, atomic transitions).
- **Major (10/10):** M1–M10 — all closed via plan/56–79 (dynamic financial config, wage audit, lazy rental expiry, idempotency, phone normalization, `transactions` decommission, settings consolidation, integer-IDR discipline, unified JSON envelope, orphan/secret cleanup).
- **Minor & hotfixes (7/7):** the P-series of the master report `plan/82` (P1 UI & P2 phone/formatting polish, P3 notification pagination + PHP 8.3 patch, P4 marketplace empty state, P5 dynamic L1 bonus, P6 security headers, P7 AJAX JSON interceptor). plan/37's historical P1–P8 maps onto this inventory with **P6 and P8 absorbed** as spec ambiguities/duplicates resolved inside the M1 and C2 rounds — hence 7, not 8, distinct minor items.

**Secrets/security hygiene (closed under M10 + related rounds):** plaintext production DB credentials removed from `application/config/database.php` (env-driven, commit `e900646`); Google-hosted CAPTCHA fully purged in favor of native session-bound SVG CAPTCHA (plan/72–73, plan/28 §3 env contract); empty `captcha/` folder and stale `.htaccess` copy removed (plan/78–79); `withdrawal_fees.php` retained as active M1 fallback (plan/78–79).

---

## 4. RECOMMENDATION — SUPERSEDED (all items executed)

The §4 recommendation ("Audit-M5 phone normalization is the clear next pick") was executed in **plan/67**. The M6 decision-gate was resolved in **plan/68–69** (decommission, single-ledger amendment). No further open findings remain; the repository moves to **final audit closure** — see the definitive master report `plan/82_FINAL_SYSTEM_STABILIZATION_AND_AUDIT_CLOSURE.md`.

---

## 5. PROPOSED NEXT ROUND — EXECUTED (tracking log)

All three items of the original §5 proposal are complete:

1. **`Auth::register` — normalize before validation** → ✅ plan/67 (normalize-before-`set_rules`; friendly duplicate-phone fallback on `uk_phone`).
2. **`Admin::create_user` same fix** → ✅ plan/67 (M4 POST-only guard intact; audit row written).
3. **Regression pass over remaining phone paths** → ✅ plan/67 (`grep` audit: no raw `is_unique[users.phone]` remains in `application/`).

**Deferred (original tracking) — all since executed:**
- M6 PRD/ERD decision-gate → ✅ plan/68–69 (decommission `transactions`; spec amended to single ledger).
- M7 settings consolidation → ✅ plan/70–71 (`site_settings` → `system_settings`).
- M9 JSON envelope (+P7) → ✅ plan/76–77 (`api_helper.php`; `MY_Exceptions` AJAX JSON).
- M10 schema/controller cleanup → ✅ plan/78–79 (deprecations, alias retention, orphan purge).
- M8 integer-IDR (latent) → ✅ plan/74–75 (choke-point casting, `intdiv`, regex).
- P3/P4/P5 polish → ✅ plan/80–81 (+ PHP 8.3 pagination patch, commit `7c6207a`).

---

*End of summary — plan/66_AUDIT_GAP_ANALYSIS_SUMMARY.md. Read-only analysis closed; superseded by plan/82 (final system stabilization & audit closure). No application code changed.*
