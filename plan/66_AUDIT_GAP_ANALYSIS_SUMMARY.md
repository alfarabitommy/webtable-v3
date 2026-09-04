# 66 — AUDIT GAP ANALYSIS SUMMARY (plan/37 follow-up)

**Task:** After completion of C1–C7 and the M1–M5 remediation rounds, report which Medium/Minor findings from `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` remain open, and recommend the next logical finding.
**Mode:** READ-ONLY analysis. No application code modified; no code executed. Evidence gathered via static inspection of `application/controllers`, `application/models`, `database.sql`, `application/config/routes.php`, `application/views`, and prior plans/summaries (plan/38–65).

---

## 1. LABEL RECONCILIATION (important)

The M-round remediation series (plan/56–65) is numbered by **remediation round**, NOT by plan/37 finding ID — plan/64 §1 states this explicitly. Consequently:

- Round **"M5" (plan/64–65) = notification coverage & audit-trail integrity**, which is **not** plan/37's M5.
- plan/37 finding **M5 (phone normalization vs `is_unique` ordering) remains genuinely open** even though remediation "M5" was marked done.

---

## 2. STATUS TABLE — ALL FINDINGS FROM plan/37

### Critical (C1–C7) — all resolved

| ID | Finding | Status | Evidence |
|---|---|---|---|
| C1 | Deposit simulator double-credit (money printer) | ✅ Resolved | plan/38–39; production hard-gate `show_404()` verified at `Wallet.php:71` |
| C2 | ROI claim lost-update race — double payout | ✅ Resolved | plan/44–47; `Rental_model::claim_roi()` authoritative, called at `Rentals.php:132` |
| C3 | Withdrawal insert violates schema; CSV missing columns | ✅ Resolved | plan/52–53 |
| C4 | Dual-balance drift + dead double-entry code | ✅ Resolved | plan/54–55; `wallet_ledger` = single source of truth; `Ledger_model.php` deleted |
| C5 | Balance check outside TX — overspend race | ✅ Resolved | plan/48–49 |
| C6 | Weekly wage claim TOCTOU | ✅ Resolved | plan/50–51 |
| C7 | User self-approval of withdrawals | ✅ Resolved | plan/42–43; production hard-gate at `Wallet.php:284` |

### Medium (M1–M10)

| ID | Finding | Status | Evidence |
|---|---|---|---|
| M1 | PRD withdrawal rules (fee/window/max) | ✅ Resolved | plan/56–57 |
| M2 | Wage engine drift (tiers/L1/population) | ✅ Resolved (decision documented) | plan/58–59; manual claim + cooldown kept as intentional decision |
| M3 | No rental expiry / ROI cron | ✅ Resolved (documented deviation) | plan/60–61; lazy per-request expiry, no cron (AGENTS.md note) |
| M4 | Admin approve/decline double-submit race | ✅ Resolved | plan/62–63; conditional transitions + POST-only |
| **M5** | **Phone normalization vs `is_unique` ordering** | ⚠️ **OPEN** | `Auth.php:209/213–215`, `Admin.php:884/887/893–894`: `is_unique` validates raw input, `_normalize_phone()` runs AFTER `form_validation->run()`. Round-M5 (plan/64–65) did not touch this |
| M6 | `transactions` double-entry ledger not implemented | ⚠️ **OPEN (decision required)** | `Ledger_model` deleted (C4) but `transactions` table still in `database.sql:76`, never written; needs PRD/ERD decision (implement vs amend spec + drop table) |
| M7 | Two settings stores with divergent shapes | ⚠️ **OPEN** | Both `site_settings` (`database.sql:149`) and `system_settings` (:260) exist and are used (`Admin_model` lines 76/87 vs 677/684); M1 widened drift by adding financial keys to `system_settings` |
| M8 | Money type discipline | ⚠️ **OPEN (latent)** | `Wallet_model::get_balance` still `return (int)($credit - $debit);` (`Wallet_model.php:419`) |
| M9 | Inconsistent API error envelope | ⚠️ **OPEN** | `Team.php` `{success,message}` vs `User/Notification.php` `{success,error}` vs rate-limit `{success,error,message,retry_after}`; no shared JSON helper |
| M10 | Unused/orphaned schema & code | ⚠️ **PARTIAL** | `claim_roi` orphan ✅ resolved (now authoritative); legacy `rentals` (`database.sql:54`), `otp_logs` (:136), `transactions` (:76) still in schema; `User::read_notifications` (`User.php:14`, route `user/read_notifications`, called by `header.php:384/415`) still duplicates `Notification::mark_all_read` |

### Minor / Polish (P1–P8)

| ID | Finding | Status | Evidence |
|---|---|---|---|
| P1 | Simulators are GET mutations | ✅ Resolved | C1/C7 production hard-gates (GET-mutation class closed, plan/51) |
| P2 | `create_deposit` result unchecked | ✅ Resolved | `Wallet.php:54–58` branches on result |
| P3 | Notifications capped 100, no pagination | ⚠️ OPEN | `Notification.php:15` `limit 100`; explicitly out of scope in plan/64 |
| P4 | Empty states (marketplace / admin users search) | ⚠️ OPEN (low) | no empty-state branch found for marketplace |
| P5 | `Team::claim_level1` hardcodes "Rp 80.000" | ⚠️ OPEN | literal at `Team.php:85` |
| P6 | Daily WD limit counts `processing` | ✅ Resolved | spec ambiguity closed by M1 rework |
| P7 | Stock error pages, no JSON 500 envelope | ⚠️ OPEN | ties into M9 |
| P8 | Duplicate claimable-days math | ✅ Resolved | deduped into `claim_roi()` (`Rentals.php:25/129`) |

---

## 3. NET OPEN FINDINGS

**Medium:** M5 (phone normalization) — the only plan/37 M-finding skipped by the round-series; M6 (decision-gated); M7; M8 (latent); M9; M10 (partial).
**Minor:** P3, P4, P5, P7.

Note: "bank validation" is **not** a plan/37 finding. The nearest open input-hardening items are **M5** (validation ordering) and **M9** (flashdata/HTML leaking into error paths).

---

## 4. RECOMMENDATION — NEXT LOGICAL FINDING

**Audit-M5 (phone normalization)** is the clear next pick:

- Only plan/37 M-finding not covered by the M-round series → closes the actual gap.
- Small, self-contained (2 controllers: `Auth::register`, `Admin::create_user`).
- Requires **no schema change and no product decision** (unlike M6).
- Fixes a real bug: `628xx` vs `08xx` duplicates both pass `is_unique` (raw form), then the DB `uk_phone` constraint rejects with a generic error instead of a friendly duplicate message.
- Follows existing conventions (`_normalize_phone()` already exists in both controllers).

M6 should **not** be next: it requires a PRD/ERD product decision (implement double-entry via `Ledger_model` vs amend spec + drop `transactions`), so it belongs in a separate decision-gated round.

---

## 5. PROPOSED NEXT ROUND (plan only — awaiting approval)

1. **Fix `Auth::register` — normalize before validation**
   - Rewrite `$_POST['phone']` with `_normalize_phone()` output *before* `set_rules`/`run()`, so `required|is_unique[users.phone]` validates the canonical `08xx` form.
   - Friendly duplicate-phone fallback when `create_user()` fails on the DB `uk_phone` violation (instead of generic "sistem" error).
   - Verify: `php -l`; curl smoke — register `628xx` while `08xx` exists → friendly duplicate message; canonical register → success.

2. **Apply same fix to `Admin::create_user`**
   - Normalize-before-`set_rules` at `Admin.php:884–894`; keep M4's POST-only guard intact.
   - Verify: `php -l`; curl smoke — duplicate-variant phone → flashdata error (no SQL error); valid create → success + audit row.

3. **Regression pass over remaining phone paths**
   - Confirm login normalizer (`Auth.php:263`) and any phone-change path accept both variants and persist canonical form.
   - `grep -rn "is_unique\[users.phone\]" application/` → no other raw-validation occurrences remain.

**Deferred (tracked, not in this round):** M6 PRD/ERD decision-gate, M7 settings consolidation, M9 JSON envelope (+P7), M10 schema/controller cleanup, M8 integer-IDR (latent), P3/P4/P5 polish.

---

*End of summary — plan/66_AUDIT_GAP_ANALYSIS_SUMMARY.md. Read-only analysis; no application code changed, no code executed.*
