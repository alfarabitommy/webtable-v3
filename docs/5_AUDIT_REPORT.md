# Synapse (webtable) — Architectural Audit Report

**Date:** audit performed on current `main` (HEAD: `87ac933`)
**Scope:** Read-only verification of documented phases vs. actual code, gap analysis, and next-milestone definition (Phase 10).
**Method:** File inspection of `docs/3_ROADMAP.md`, `docs/1_PRD.md`, `docs/2_ERD.md`, `AGENTS.md`, `application/controllers/`, `application/models/`, `application/views/admin/`, `application/views/templates/`, `application/config/config.php`, `database.sql`, and git history.

---

## 1. Executive Summary

- **All phases 1–8 and Phase 9 are implemented in code** and committed (`87ac933` — analytics, CSV export, 5-item nav, T+1 claim rule). Working tree is clean.
- **The documentation is out of sync with the code:**
  - `docs/3_ROADMAP.md` still marks Phase 9 as PLANNED (stale checkbox names: "Revenue Dashboard / User Analytics / Export Functionality" instead of the actual Treasury/Chart.js, Analytics & VIP Leaderboard, CSV Export Streaming).
  - Roadmap 7E1/7E2 claim audit logs exist — they do not (no audit code anywhere).
  - `docs/1_PRD.md` has no section for Phase 10 hardening; only PRD §6 (Security & OpSec) is relevant.
  - `database.sql` is stale (only 8 of the tables used by production code).
- **Next milestone per roadmap rule #7 (sequential lock):** Phase 10 — System Hardening & Audit Trail. No unfinished prior phase blocks it.
- **No blockers for starting Phase 10**, but two prerequisites must be handled first: the `system_audit_logs` table is not in `database.sql`, and enabling CSRF will break all existing POST forms/AJAX unless tokens are propagated in the same milestone.

---

## 2. Current Status Confirmation (verified from code)

| Phase | Status | Evidence |
|---|---|---|
| 1. Database & Basic Auth | ✅ | `Auth.php`: reCAPTCHA v2, phone normalizer `_normalize_phone()` (regex `/^0[0-9]{9,13}$/`), register/login/logout |
| 2. Auth Context & Navigation | ✅ | `application/core/MY_Controller.php`: user session guard, `global_balance` + notifications injected via `$this->load->vars()` |
| 3. Marketplace | ✅ | `Marketplace.php`, `Product_model`, one-screen checkout bottom sheet |
| 4. Rental System | ✅ | `Rentals.php` checkout + claim, `user_rentals` insert, ACID via `Ledger_model` |
| 5. Ledger & Wallet | ✅ | `Wallet_model` (SUM credit − SUM debit), deposits `INV-{YmdHis}-{user_id}`, dev simulator, tiered WD fees, auto-rollback on decline |
| 6. Admin Command Center | ✅ | `Admin.php` + `Admin_auth.php`: dual auth (`admin_id` vs `user_id`), cloaked `/control-panel`, ACID queue ops |
| 7A–7D. Affiliate, Agency, Team, Notifications | ✅ | `Admin_model::resolve_upline/has_ancestor/get_downline`, `Notification_model` + `Notification.php` + AJAX endpoints |
| 7E. Advanced User Mgmt | ⚠️ partial | `Admin::create_user` / `reset_password` / `must_change_password` present — but **no audit-log writes** despite roadmap 7E1/7E2 claiming them |
| 8A–8C. Daily ROI, WD queue, Level-1 mission card | ✅ | Cron logic, `team/claim-level1` (idempotency guard + `SELECT ... FOR UPDATE`) |
| 9A. Treasury & Chart.js | ✅ | `views/admin/dashboard.php` "Treasury Health" (cash-in / balances / pending ROI / critical circuit-breaker) + Chart.js 4.4.1, AJAX refresh via `admin/chart_data` |
| 9B. Analytics & VIP Leaderboard | ✅ | `views/admin/analytics.php` (global metrics + "TOP AFFILIATES — VIP LEADERBOARD"), `user_xray` JSON endpoint |
| 9C. CSV Export Streaming | ✅ | `Admin::export_csv()` — `php://output` streaming, UTF-8 BOM, 3 types: ledger / rentals / withdrawals |
| Recent: T+1 ROI Claim restriction | ✅ | `Rentals::claim()` — same-day claim rejected ("T+1 Rule"), max 2-day accumulation |
| Recent: 5-Item Bottom Nav + Profile Hub | ✅ | `views/templates/bottom_nav.php` (Beranda / Market / Sewa Saya / Afiliasi / Profil); `views/profile/index.php` hub modal, avatar upload, invite code display |

---

## 3. Documentation Synchronization Issues (to fix)

1. **`docs/3_ROADMAP.md` — Phase 9 must be marked ✅ COMPLETED** and the 9A/9B/9C checkbox names corrected to match the implemented features (Treasury & Chart.js / Analytics & VIP Leaderboard / CSV Export Streaming).
2. **Roadmap 7E1/7E2 overstate reality** — they claim audit logs for `admin_create_user` and `admin_reset_password`, but grep of controllers/models finds **zero audit code**. Either implement it (Phase 10A does exactly this) or fix the checkbox text.
3. **`docs/1_PRD.md` lacks a Phase 10 spec section** — the hardening details exist only in the roadmap. PRD §6 (Security & OpSec) is the closest anchor and should be expanded into a dedicated module section.
4. **`database.sql` is stale** — only 8 tables (`users`, `gpu_products`, `rentals`, `transactions`, `bank_accounts`, `withdrawals`, `otp_logs`, `site_settings`). Missing tables used by production code: `wallet_ledger`, `deposits`, `user_rentals`, `admins`, `user_notifications`, and (for Phase 10) `system_audit_logs`. ERD v5.0 is the authoritative schema.

---

## 4. Code Discrepancies Found

| # | Finding | Severity | Detail |
|---|---|---|---|
| 1 | **Dead sidebar link: "Audit Logs" → `admin/audit` → 404** | Medium | `views/admin/templates/sidebar.php` links to `admin/audit`, but `Admin.php` has no `audit()` method. Premature Phase 10 UI — will be satisfied by 10B. |
| 2 | **Inconsistent session check in `Admin::export_csv()`** | Low | Guards on `admin_logged_in` (never set; real key is `admin_id`) and redirects to `admin_auth` instead of the cloaked `control-panel` route. Harmless today only because the constructor guard runs first. |
| 3 | **Config violates PRD §6** | Medium | `csrf_protection = FALSE`, `sess_expiration = 7200` (roadmap targets 30-min idle), `sess_regenerate_destroy = FALSE`. Enabling CSRF will break every existing POST/AJAX flow unless tokens are propagated. |
| 4 | **Hardcoded reCAPTCHA secret in `Auth.php`** | High (security) | Roadmap rule #6 forbids hardcoded credentials; secret is committed to the repo. Flagged in AGENTS.md — should move to env/`.env` before production. |

---

## 5. The Next Milestone — Phase 10: System Hardening & Audit Trail

Defined in `docs/3_ROADMAP.md` (PLANNED), grounded in PRD §6:

- **10A — Audit Logging:** `system_audit_logs` table (spec already in ERD v5.0 §6: `id`, `admin_id` FK, `user_id` FK, `action`, `details`, `ip_address`, `created_at`). Every admin action logged: deposit approval, withdrawal approval/decline, user creation, password reset (+ all other privileged ops: inject_balance, inject_rental, cancel_rental, adjust_time, toggle_ban, toggle_registration, settings).
- **10B — Rate Limiting:** IP-based throttle on auth endpoints (login, register, OTP) to prevent brute-force; double-spend mutex/disable-state on financial buttons (PRD §6).
- **10C — Session Security:** 30-min idle timeout, concurrent-session limiting, CSRF token rotation.
- **10D — Input Sanitization Audit:** full review of user inputs against XSS, SQL injection, CSRF vectors; bank account data masking (`1234*****789`); honor `X-Forwarded-For` for real IP logging behind proxy (PRD §6).

**Acceptance criteria (suggested):**
- Every privileged admin action writes a row to `system_audit_logs` inside its ACID transaction; rollback of the action rolls back the log.
- Audit viewer at `/admin/audit` returns 200 and lists/filters entries; sidebar link works.
- 5 failed logins from one IP within a window → temporary lockout with clear message.
- Session expires after 30 min idle; logging in on a second device invalidates the first session.
- All POST/AJAX flows pass with `csrf_protection = TRUE` (regression: register, checkout, claim, team, notifications, admin queues → HTTP 200/302).
- `php -l` clean on all new/modified files; curl smoke tests per roadmap rule #3.

---

## 6. Pre-requisites / Blockers Before Starting Phase 10

1. **Create `system_audit_logs` table** — not in `database.sql`; CI3 has no migration tooling, so it must be added to `database.sql` and applied to the live DB manually (same pattern used for `must_change_password` in 7E3).
2. **CSRF enablement is a breaking change** — must be shipped together with token propagation to all forms + `fetch()` calls, and a full POST-flow regression pass.
3. **Verify live DB schema vs ERD** first — since `database.sql` is stale, confirm which tables actually exist in the running DB before writing audit/rate-limit queries.
4. **No existing audit code** — 10A is greenfield; the roadmap 7E audit claims were never implemented.

---

## 7. Suggestions (prioritized)

1. **Sync docs first** (cheap, unblocks accurate tracking): mark Phase 9 ✅ in `docs/3_ROADMAP.md` with corrected 9A/9B/9C names; correct 7E audit claims; add PRD §9 "System Hardening" spec; refresh `database.sql` to full schema.
2. **Implement Phase 10 in order 10A → 10B → 10C → 10D** on a dedicated branch per roadmap rule #4; commit in Indonesian per repo style.
3. **Fold the `admin/audit` 404 fix and the `export_csv` guard inconsistency into 10A/10B** — they are small and belong to the same hardening milestone.
4. **Move the reCAPTCHA secret out of `Auth.php`** into environment config before anything else ships; it is the single most urgent security item found.
5. **Verify each milestone step** with `php -l` + curl (HTTP 200/302) before merging, per roadmap rules #3 and #5.

---

## 8. Notes

- This report is an audit artifact; it does not modify any code or documentation.
- All conclusions above were verified against actual files — no assumptions about checkbox states.
