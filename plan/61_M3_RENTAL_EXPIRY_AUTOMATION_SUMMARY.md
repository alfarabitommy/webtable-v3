# 61 — M3 RENTAL EXPIRY AUTOMATION & LAZY EVALUATION — SUMMARY

**Finding:** plan/37_FULL_SYSTEM_AUDIT_REPORT.md §3-M3 ("No rental expiration / ROI cron (state machine incomplete)").
**Blueprint:** plan/60_M3_RENTAL_EXPIRY_AUTOMATION_PLAN.md (approved).
**Mode:** Implementation — all phases executed. **Owner constraint honored:** NO cron — expiry is Lazy / Event-Driven + defensive query filtering; cron-class maintenance is operator/manual tooling only.
**Date:** 2026-09-03. DB: `db_webtable` (MariaDB 12.3.2, live).

---

## 1. WHAT CHANGED

| Component | Change |
|---|---|
| `application/models/Rental_model.php` | + `expire_user_rentals($user_id, $now = null)` — lazy per-user sweep: `UPDATE user_rentals SET status='completed' WHERE user_id=? AND status='active' AND expired_at <= ?` (bound PHP WIB, no TX). + `expire_all_expired($now = null)` — global sweep (returns flipped count), shared by CLI + admin. `has_active_rental()` hardened: `WHERE status='active' AND expired_at > <WIB now>` (withdrawal gatekeeper). |
| `application/core/MY_Controller.php` | Lazy per-request hook inside authenticated block, after `SET time_zone='+07:00'` + session/ban/password guards, **before** balance/notification reads: loads `Rental_model` and calls `expire_user_rentals(user_id)`. |
| `application/models/User_model.php` | Defensive `expired_at > ?` (bound WIB now) added to `count_active_b_downlines()`, `sum_sales_b_downlines()`, `count_all_active_downlines()` (recursive CTE), and both `is_active` correlated subqueries in `get_team_with_active_status()`. |
| `application/models/Admin_model.php` | Current-obligation aggregates now exclude expired: treasury `pending_roi` (phantom-obligation fix), `get_active_users_count()`, `get_global_analytics()` active_rentals, `get_leaderboard()` (total_sales + active_rental_count CASE), `get_user_xray()` per-user active, `get_active_rentals()` (CSV export). Lifetime stats (`get_rental_volume`, `get_revenue_chart_data`) intentionally unchanged. |
| `database.sql` | `user_rentals` indexes: `idx_user_status_expired(user_id, status, expired_at)` + `idx_status_expired(status, expired_at)` replace `idx_user_status`. |
| `database_seed.sql` | No DDL in seed (INSERT-only) — schema parity owned by `database.sql`; no seed change needed. |
| `scripts/expire_rentals.php` | NEW standalone CLI (mysqli, conventions of `backfill_withdrawal_fees.php`): `--dry-run` (default, count + sample), `--apply` (one transaction), `--help`; WIB pin `SET time_zone='+07:00'`; PHP-generated bound param; index-health warning; exit codes 0/1/2; idempotent. |
| `application/controllers/Admin.php` | NEW POST-only `expire_expired_rentals()` — global sweep via `Rental_model::expire_all_expired()` + `Audit_model` log (`admin_expire_rentals`, `flipped_count`) inside `trans_start/complete`; flashdata success/info; redirect back to dashboard. |
| `application/views/admin/dashboard.php` | Maintenance strip in Treasury Health card: POST form button "Tutup Sewa Kedaluwarsa" (CSRF via `form_open`) + `info` flash block. |
| `application/views/admin/templates/header.php` | Added `.t-flash-info` style (blue variant) for the new info flash. |
| `AGENTS.md` | Quick-note documenting the M3 lazy-expiry architecture + tooling. |

**Claim gate (Phase 4):** verified **already authoritative and unchanged** — `Rental_model::claim_roi()` (C2/plan 44) still locks with `SELECT ... FOR UPDATE`, rejects non-`active`, evaluates `claimable_info()` (expiry boundary `expired_at <= now`) inside the TX and flips `active→completed` idempotently with 0 payout. The lazy sweep uses the **same boundary comparator**, so it can never close a still-claimable contract earlier than the gate, and both writers are conditional (`WHERE status='active'`) → race-safe (no double/lost payout). After a sweep, `Rentals::index` (feeds on `get_active_rentals()`, status='active') hides completed contracts consistently.

---

## 2. DATABASE INDEXING (live `db_webtable`)

Applied and confirmed:
```
ALTER TABLE user_rentals
  ADD INDEX idx_user_status_expired (user_id, status, expired_at),
  ADD INDEX idx_status_expired     (status, expired_at);
ALTER TABLE user_rentals DROP INDEX idx_user_status;   -- superseded (leftmost prefix)
```
Final live indexes: `PRIMARY(id)`, `idx_user_status_expired(user_id,status,expired_at)`, `idx_status_expired(status,expired_at)`, `idx_product_id(product_id)` — **in sync with `database.sql`**.

**EXPLAIN verification (all use the new composites, covering):**
```
lazy sweep (per-user)         key=idx_user_status_expired  type=range rows=1 Using where; Using index
has_active_rental gate        key=idx_user_status_expired  type=range rows=1 Using where; Using index
global sweep / aggregates     key=idx_status_expired       type=range rows=1 Using where; Using index
downline count join           ur: key=idx_user_status_expired (u: idx_parent_id)  ref/range, Using index
```

---

## 3. VERIFICATION

**Lint (`php -l`) — all clean:** `Rental_model.php`, `MY_Controller.php`, `User_model.php`, `Admin_model.php`, `Admin.php`, `dashboard.php`, `admin/templates/header.php`, `scripts/expire_rentals.php`.

**DB functional assertions (fixture sponsor S + downlines D1 expired / D2 current):** all PASS — legacy count=2 vs M3-filtered=1; `is_active` subqueries 0/1; full UNION team query flags 0/1; `sum_sales_b_downlines` = current contract only; tree count = 1; `has_active_rental` false/true; treasury `pending_roi` = only non-expired obligation; lazy per-user sweep flips expired row; global sweep flips remaining expired and leaves current untouched; 0 expired-but-active remain. Fixtures deleted after run.

**CLI end-to-end:** fixture expired contract → `--dry-run` lists it (no write) → `--apply` flips 1 (post-check 0 remain) → second `--apply` is a no-op (idempotent). Fixture cleaned up.

**Not verifiable in this environment:** full HTTP login flow (register/login are reCAPTCHA fail-closed without `RECAPTCHA_SECRET` env), so the `MY_Controller` hook + dashboard button were verified by code review, lint, and SQL-level equivalence rather than a browser session. Recommended UAT smoke test: set `expired_at` in the past for an active rental → user login → row flips before `/rentals` renders; admin dashboard → "Tutup Sewa Kedaluwarsa" → audit row `admin_expire_rentals` appears.

---

## 4. RISKS / NOTES
- A `user_rentals` row with `expired_at IS NULL` (possible only via manual DB edit — every code path sets it) is **not** flipped by the sweep and **excluded** from defensive "active" counts. Consistent with `claimable_info()` treating NULL as never-expired on the claim side; document rather than silently flip.
- `get_rental_volume()`/`get_revenue_chart_data()` (lifetime stats) intentionally left unfiltered — only "current active/obligation" semantics changed.
- No cron or auto-ROI distribution added (owner constraint). ROI remains user-initiated claim, C2-safe.

*End of summary — plan/61. Companion to plan/60 blueprint; M3 (audit plan/37 §3) resolved.*
