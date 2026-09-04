# 64 — M5 NOTIFICATION COVERAGE & AUDIT TRAIL INTEGRITY (PLAN)

**Scope:** Finding M5 (Notification coverage, admin audit trail consistency, and system observability) — the continuation of the M-round remediation series after M1 (plan/56–57), M2 (plan/58–59), M3 (plan/60–61), M4 (plan/62–63).
**Mode:** PLAN / BLUEPRINT. No application code or DB schema was modified to produce this document.
**Method:** Read-only audit of `application/controllers`, `application/models`, `application/core`, `database.sql`, and prior remediation plans (C1–C7, M1–M4).

> **Label reconciliation:** In `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` §3 the literal finding "M5" is *phone normalization vs `is_unique` ordering*. The M-round series this plan continues is numbered by *remediation round*, not by the audit-report finding ID — hence "M5" here = notification coverage, admin audit-trail consistency, and observability, as defined by the task brief. Report line 129 already claims "admin audit coverage is complete for all 13 state-changing actions"; this blueprint verifies that claim and closes the payload/notification gaps around it.

---

## 1. CROSS-REFERENCE: WHAT C1–C7 / M1–M4 ALREADY HARDENED

| Round (plan) | Audit/notification work already introduced | State |
|---|---|---|
| Phase 10A (plan/9) | `Audit_model` (`system_audit_logs`), in-TX audit at every Admin mutator, `/admin/audit` viewer with filters + pagination | ✅ Done |
| C4 (plan/54) | Deposit approve / withdrawal approve+decline moved into `Admin_model` ACID methods carrying `$audit` ctx (`_audit_ctx`: admin_id, target user_id, action, details, IP) — audit row inside the same TX | ✅ Done |
| C1/C5/C7 (plans 38–55) | Money moves centralized in models; conditional state transitions — audit rows only survive on committed success paths (rollback removes both) | ✅ Done |
| M1 (plan/56–57) | `financial_settings` persistence + audit `admin_update_financial_settings` | ✅ Done (payload thin — see §3) |
| M2 (plan/58–59) | Wage/tier verification; user notifications on `claim_level1` / `claim_wage` exist | ✅ Done |
| M3 (plan/60–61) | Rental expiry: lazy per-request (`MY_Controller` → `Rental_model::expire_user_rentals`), manual sweep (`Admin::expire_expired_rentals` → `expire_all_expired`), audit `admin_expire_rentals` | ⚠️ Audit done; **no per-user notification** on completion |
| M4 (plan/62–63) | POST-only fail-closed guards + conditional transitions on every Admin mutator; audit calls kept inside the TX | ✅ Done |

**Net:** audit rows exist for **every** admin state-changing action and user notifications exist for exactly **5** events. The remaining blind spots are notification coverage on money-in/lifecycle events, WD-decline reason conveyance, and audit **payload** consistency (before/after + actionable metadata).

---

## 2. MEMBER NOTIFICATION COVERAGE AUDIT (`Notification_model.php` + callers)

`Notification_model` (`user_notifications` table) exposes `insert($user_id, $title, $message, $type)` and read/mark-read helpers. **Only 5 insert call sites exist**:

- `application/controllers/Admin.php:117` — Deposit approved (type `success`)
- `application/controllers/Admin.php:151` — Withdrawal approved (type `success`)
- `application/controllers/Admin.php:185` — Withdrawal declined (type `error`)
- `application/controllers/Team.php:82` — Level-1 bonus claimed (type `commission`)
- `application/controllers/Team.php:139` — Weekly wage claimed (type `commission`, includes level + amount)

All other lifecycle feedback is **transient session flashdata only**.

### Coverage matrix — user notifications

| Lifecycle event | Channel today | Status |
|---|---|---|
| Deposit approved | Bell (`Admin.php:117`, amount in message) | ✅ Covered |
| Deposit rejected | — | ⚠️ **No decline path exists** in code/state machine (`Admin_model` has only `approve_deposit`; deposits go `pending → success`). Product gap — flag, do not build here |
| Withdrawal approved | Bell (`Admin.php:151`) | ✅ Covered |
| Withdrawal declined | Bell (`Admin.php:185`) | ⚠️ Covered, but **rejection reason is never captured or conveyed**: `Admin_model::decline_withdrawal($wd_id, $audit)` has no reason parameter; message text is fixed |
| Rental contract completed / expired (M3 lazy sweep, `expire_all_expired`, admin sweep) | Silent | ❌ **Missing** — user never informed contract ended |
| ROI claimed (`Rentals::claim` → `Rental_model::claim_roi`, result `code === 'claimed'`) | Flashdata only | ❌ **Missing** — money-in with no bell (inconsistent vs wage/L1 which both notify) |
| Wage claimed (L2–L6) | Bell (`Team.php:139`) | ✅ Covered |
| Level-1 bonus / level achieved | Bell (`Team.php:82`) | ✅ Covered; L2–L6 **tier-up itself** (not the claim) unnotified — minor |
| Admin `inject_balance` / `inject_rental` | Silent | ❌ **Missing** — money/contract changed on the user's behalf, user uninformed |
| Ban / unban | Silent | 🟡 Ban is pointless (user locked out); **unban notification** is a cheap win |
| Rental purchase (`checkout`), top-up created, WD request created | Flashdata only | 🟡 User-initiated with on-screen response — optional, low value |

---

## 3. ADMIN AUDIT TRAIL COMPLETENESS (`Audit_model.php` + `Admin.php` + `Admin_model.php`)

`Audit_model::log_admin_action($admin_id, $user_id, $action, $details, $ip_address)` is transaction-agnostic by design; every call site runs **inside the caller's TX** so a failed operation rolls back both the action and its audit row. Viewer at `/admin/audit` joins `admins`/`users` and filters by action/date with pagination.

### Coverage matrix — audit payload consistency

| Admin action | Audit action key | Payload today | Verdict |
|---|---|---|---|
| Approve deposit | `approve_deposit` (inside `Admin_model`) | `$audit` ctx via `_audit_ctx` | ✅ (verify amount/invoice present in details inline) |
| Approve withdrawal | `approve_withdrawal` | ctx | ✅ (verify inline) |
| Decline withdrawal | `decline_withdrawal` | ctx, **no reason** | ⚠️ Enrich with reason (§4 N4) |
| Financial rules (M1: ops days/hours, WD fixed fee + tiers, min/max, deposit fee) | `admin_update_financial_settings` (`Admin.php:337`) | **`keys` only** — no before→after | ⚠️ Compliance-critical toggles; log old→new per key |
| Settings (wa_number / support_email) | `admin_update_settings` | **`fields` only** | ⚠️ Add before→after |
| Registration circuit breaker | `admin_toggle_registration` | `was_open` / `is_open` | ✅ |
| Update user profile | `admin_update_user` (`Admin.php:531`) | phone/invite/upline **after-state** only | ⚠️ No before-state snapshot |
| Toggle ban | `admin_toggle_ban` (`Admin.php:564`) | `new_state` | ✅ |
| Inject balance | `admin_inject_balance` (ctx via model) | type + amount; **description & balance_after omitted** | ⚠️ Enrich |
| Inject rental | `admin_inject_rental` (ctx) | product_id only | ⚠️ Add price/daily-ROI snapshot |
| Cancel rental | `admin_cancel_rental` (`Admin.php:660`) | rental_id only | ⚠️ Add amount snapshot |
| Expiry sweep (M3) | `admin_expire_rentals` (`Admin.php:701`) | `flipped_count`, global (user_id null) | ✅ |
| Adjust rental time | `admin_adjust_time` (`Admin.php:754`) | rental_id + new `days_processed`, **no old value / last_claimed_at** | ⚠️ Enrich old→new |
| Create user | `admin_create_user` (`Admin.php:830`) | phone + created_by | ⚠️ Add invite_code + parent_id |
| Reset password | `admin_reset_password` (`Admin.php:883`) | user_id | ✅ (plaintext never logged — correct) |

**Payload consistency rules already satisfied everywhere:** actor `admin_id` from session, target `user_id` (or `null` for global actions), `ip_address` via `input->ip_address()`, JSON-encoded details, inside-TX rollback safety, POST-only trigger (M4). The gap is **metadata richness**, not presence.

### Observability gaps

- Bell history capped at 100 rows, no pagination (audit report P3); no entity reference (invoice/rental/WD number) as structured data on notifications.
- No admin-side view correlating "what admin did" (`system_audit_logs`) with "what the user was told" (`user_notifications`).
- No admin-facing alert surface for recurring operations (expiry sweep runs silently unless the dashboard button is used).

---

## 4. PRAGMATIC REMEDIATION (lightweight, non-breaking)

Priorities are high-value blind spots only; no bloat, no new tables/views except one nullable column.

### N1 — Notify on successful ROI claim
`Rentals::claim` → when `Rental_model::claim_roi()` returns `code === 'claimed'`, insert a `commission` notification (title "ROI Harian Cair", message with amount + rental id) before redirect. Idempotent by construction (bell only on the single committed success code); mirrors the Team claim pattern.

### N2 — Notify on rental expiry / completion (both sweep paths)
Inside the TX of `Rental_model::expire_user_rentals()` (lazy, per requesting user) and `expire_all_expired()` (admin sweep), emit one `info` notification per flipped rental ("Kontrak Sewa Selesai", rental id + product name if available). Flip-once ⇒ notify-once; insert inside the TX so rollback discards both. Lazy path notifies exactly the affected requesting user; sweep path notifies each affected `user_id`.

### N3 — Notify user on admin unilateral money/contract changes
- `inject_balance` → notification "Penyesuaian Saldo oleh Admin" (credit/debit + amount).
- `inject_rental` → notification "Sewa Diaktifkan oleh Admin" (product).
- Unban (`toggle_ban` when result is unbanned) → notification "Akun Diaktifkan Kembali".
Insert post-commit (fire-and-forget), same pattern as the existing Admin approve/decline bells.

### N4 — Withdrawal-decline reason end-to-end
- Add nullable `decline_reason VARCHAR(255)` to `withdrawals` (`database.sql` + a note for the live-DB migration).
- Add optional reason input to the admin decline form/UI; thread through `Admin_model::decline_withdrawal($wd_id, $audit, $reason = null)`; persist it.
- Include the reason in the decline notification message and in the audit `details` JSON (`'reason' => ...`).

### A1 — Enrich audit `details` payloads (before/after + actionable metadata)
- `admin_update_financial_settings` / `admin_update_settings`: read current values pre-write and log `before` → `after` per key inside the same TX.
- `admin_adjust_time`: log old and new `days_processed` (+ old `last_claimed_at`).
- `admin_cancel_rental`: log rental amount/ROI snapshot (and whether a refund occurred).
- `admin_create_user`: log `invite_code` and `parent_id`.
- `admin_inject_balance`: log `description` and post-action `balance_after`.
- `admin_update_user`: log before-state (username/phone/invite_code/parent) alongside after-state.
- `decline_withdrawal`: log `wd_number`, `amount`, and `reason` (ties to N4).

### A2 — Consistency guard (regression)
Re-verify each Admin mutator still (a) writes its audit row inside the same TX as the action, (b) is POST-only, (c) survives only on committed success. Produce the action inventory as a static checklist in the summary for future audits.

### Out of scope (deliberate)
Deposit-decline feature (no such state machine — product decision); notification pagination (P3); admin notification viewer; real-time push; wage cron automation (M2 decision); double-entry ledger (C4/M6).

---

## 5. LAYERED IMPLEMENTATION PHASES (post-approval)

1. **Close notification blind spots (money-in & lifecycle)**
   - Bell after successful ROI claim in `Rentals::claim` (only on `code === 'claimed'`)
   - Per-user "kontrak selesai" notification inside the TX of `Rental_model::expire_user_rentals()` and `expire_all_expired()`
   - Notify user on admin `inject_balance`, `inject_rental`, and unban (post-commit)

2. **Withdrawal-decline reason end-to-end**
   - Add nullable `withdrawals.decline_reason` (`database.sql` + live-DB migration note)
   - Thread optional reason through `Admin_model::decline_withdrawal()` and the admin decline UI
   - Include reason in decline notification message and audit `details`

3. **Audit-trail payload enrichment**
   - Before→after for `financial_settings` and `settings` updates (read current values pre-write, same TX)
   - Enrich `adjust_time`, `cancel_rental`, `create_user`, `inject_balance`, `update_user`, `decline_withdrawal`
   - A2 regression checklist: every mutator logs in-TX, POST-only, rollback-safe

4. **Verification**
   - `php -l` every touched file
   - Manual `curl` smoke flows: deposit approve → bell row; ROI claim → notification row; sweep POST → expiry notification; decline WD with reason → message contains reason + audit details; repeat claim/expiry attempts must NOT duplicate notifications
   - Confirm `/admin/audit` viewer renders enriched details

---

*End of blueprint — plan/64_M5_NOTIFICATIONS_AND_AUDIT_TRAIL_PLAN.md. Read-only inspection; no application code or DB schema changed.*
