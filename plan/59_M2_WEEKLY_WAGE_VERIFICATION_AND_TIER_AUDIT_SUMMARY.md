# 59 — M2: Weekly Wage Timezone & Schema Hardening — EXECUTION SUMMARY

> **Plan:** `plan/58_M2_WEEKLY_WAGE_VERIFICATION_AND_TIER_AUDIT_PLAN.md` (approved).
> **Scope executed:** Phase 1 (schema hardening) + Phase 2 (unconditional `+07:00` pin).
> **Spec confirmations:** tiers 9/30/70/130/190 → 200k/1jt/2.5jt/5jt/9jt and whole-tree
> population retained (PRD amendment deferred to final doc batch); L1 criteria retained (B-only).
> **Out-of-scope (NOT touched):** `User_model::WAGE_TIERS`, `determine_wage_level()`,
> `claim_wage()`, `check_wage_cooldown()`, `count_all_active_downlines()` — cooldown math and all
> wage payout logic unchanged; PRD/doc batch deferred.

---

## 1. Owner decisions applied

| # | Topic | Decision | Source |
|---|-------|----------|--------|
| D1 | Tier ladder | Keep live tiers (9/30/70/130/190) | `dec-a6cc4929e0fcc60f` |
| D2 | Wage population | Keep whole-tree recursive-CTE count | `dec-a6cc4929e0fcc60f` |
| D3 | Timezone hardening | DATETIME column **and** unconditional pin | `dec-a6cc4929e0fcc60f` |
| D4 | Ledger UNIQUE scope | `UNIQUE (user_id, transaction_id, type)` — composite, **not** plain `(user_id, transaction_id)`, because the W6 decline-refund flow deliberately inserts a credit with `transaction_id = wd_number` (same user+id as the original debit row, `Admin_model::decline_withdrawal` L437–442, comment L401–402). A plain `(user_id, transaction_id)` key would roll back every withdrawal decline. | `dec-33a67c663c695599` |

---

## 2. Schema hardening (Phase 1) — applied to live `db_webtable` (127.0.0.1:3306)

1. **`users.last_wage_claimed_at` TIMESTAMP → DATETIME**
   `ALTER TABLE users MODIFY last_wage_claimed_at DATETIME NULL DEFAULT NULL;`
   - Executed under the default session tz (SYSTEM) so existing values keep exactly what the app
     has been reading — no behavior change for legacy rows.
   - Verified: `information_schema.COLUMNS.DATA_TYPE = 'datetime'`; `users.id=1` stamp preserved as
     `'2026-08-24 19:00:08'` (2 stamp rows total, 0 lost).
2. **`wallet_ledger` composite unique key**
   - Pre-check: `SELECT user_id, transaction_id, type ... GROUP BY ... HAVING COUNT(*)>1` → **0 duplicates**.
   - `ALTER TABLE wallet_ledger ADD UNIQUE KEY uk_wallet_ledger_user_tx_type (user_id, transaction_id, type);`
   - Verified: index present, `NON_UNIQUE = 0`.

### Constraint behavior tests (after pin applied, session `+07:00`)

| Test | Result |
|------|--------|
| Duplicate `(1,'M2TEST-1','credit')` second insert | **BLOCKED** — errno 1062 (duplicate wage/bonus credit impossible) |
| WD pair: `(1,'M2TEST-2','debit')` then `(1,'M2TEST-2','credit')` | **ALLOWED** — decline-refund flow (W6) preserved |
| DATETIME literal round-trip `'2026-01-01 00:00:00'` | **IDENTICAL** — literal storage, tz-immune |
| Test rows cleaned up | wallet_ledger back to 197 rows |

### Seed files kept canonical & idempotent
- `database.sql`: users DDL L23 → `DATETIME`; wallet_ledger DDL L177 → `UNIQUE KEY uk_wallet_ledger_user_tx_type (user_id, transaction_id, type)`.
- `database_seed.sql`: reconcile ALTER L24 → `DATETIME`; new `-- [RECONCILE]` ALTER (L32) for the wallet_ledger key (documentation parity — runner executes `reconcile_plan()`).
- `scripts/seed_database.php`: `reconcile_plan()` L282 → `DATETIME` + new wallet_ledger key entry (L289); `parse_alter()` index regex extended to match composite keys (multi-column), keeping the runner's `index_exists()` guard working so re-runs skip cleanly.

---

## 3. Unconditional `+07:00` session pin (Phase 2) — CI3 lazy-connection trap fixed

**Root cause:** CI3 connects lazily (`DB_driver::$conn_id` defaults `FALSE`; connection opens on the
first query). The old pins (`Wallet_model`/`Admin_model` constructors) were guarded by
`if ($this->db && $this->db->conn_id)` and therefore **never executed** on a fresh connection
(session driver = files; models load before the first query). On hosts whose MySQL default tz ≠
`+07:00` (typically UTC), the TIMESTAMP wage stamp was written/read under the server tz → rolling
cooldown boundary off by up to the tz offset (UTC ⇒ up to ~7 h early eligibility, ≈4% over-payout).

**Fix — pin is now the FIRST DB statement of every entry point and every model constructor:**

| File | Change |
|------|--------|
| `application/core/MY_Controller.php` | `SET time_zone = '+07:00'` right after `parent::__construct()`, before any model load/query (all authenticated user pages: Home, Team, Wallet, Rentals, Marketplace, Profile, Notification, Help, User) |
| `application/controllers/Auth.php` | same pin first (login/register/change-password — `Auth` extends `CI_Controller`, not `MY_Controller`) |
| `application/controllers/Admin.php` | same pin right after `$this->load->database()` (admin console) |
| `application/controllers/Admin_auth.php` | same pin first (`/control-panel` entry) |
| `application/models/Wallet_model.php` | `conn_id` guard **removed** → unconditional `$this->db->query("SET time_zone = '+07:00'")` (backstop for CLI/cron/future paths; forces first connect + pin) |
| `application/models/Admin_model.php` | `conn_id` guard **removed** → unconditional same statement |

The statement is idempotent per request on the single shared CI connection. Because it is the first
query, it also *forces* the lazy connection under the pinned tz, so every subsequent
TIMESTAMP/DATETIME read-back (created_at, rate-limit windows, admin CSV exports, wage stamps) is
WIB-consistent regardless of the MySQL server default.

**Dev-instance note:** baseline probe showed this sandbox MySQL `@@session.time_zone = SYSTEM` with
`NOW() − UTC_TIMESTAMP() = 7h` (system tz = +07:00), so no legacy stamp skew existed here; the pin
now makes the behavior explicit and host-independent. On a UTC-default production host the pin is the
fix for the M2/Phase-10B class of bug.

---

## 4. Cooldown & ledger integrity (no logic change — verified)

`User_model::claim_wage()` / `check_wage_cooldown()` were **not modified**. They write/read PHP
`'Y-m-d H:i:s'` Asia/Jakarta strings and compare lexically; against a `DATETIME` column the stored
literal is byte-identical to the PHP string (no tz conversion), so the exact-168-hour gate is now
fully deterministic.

### Verification results
1. **Lint:** `php -l` clean on all 8 checked files (MY_Controller, Auth, Admin, Admin_auth,
   Wallet_model, Admin_model, User_model sanity, scripts/seed_database.php).
2. **168-hour boundary sim** (mirror of the claim_wage gate, PHP Asia/Jakarta): 6d23h59m59s → not
   claimable; exactly 7d0h0m0s (== cutoff) → claimable; 7d+1s → claimable; NULL → claimable —
   **all PASS**.
3. **Transaction-id collision sim** (`WAGE-{uid}-Y{o}W{W}`): +7-day claims always produce distinct
   ids for every weekday sample and across the year-end matrix (`2025-12-27 Y2025W52 → +7d Y2026W01`,
   `2025-12-29 Y2026W01 → +7d Y2026W02`, … `2026-01-05 Y2026W02 → +7d Y2026W03`); ISO week span
   (604,799 s) < 604,800 s proves a same-week double claim is impossible under the ≥7-day gate —
   **24/24 PASS**. DB-level backstop now also enforced by the composite unique key.
4. **App smoke test** (`php -S` + live DB, env `DB_HOSTNAME=127.0.0.1`):
   `/index.php/auth/login` → 200, `/index.php/control-panel` → 200, `/index.php/team` → 307
   (login redirect, pin runs before guard), `/index.php/admin` → 307 (control-panel redirect);
   **zero** DB/connection errors and zero PHP warnings in the server log.

---

## 5. Deviations & notes
- **UNIQUE key is composite `(user_id, transaction_id, type)`**, not the literal
  `(user_id, transaction_id)` from the approved spec — required to keep the W6 decline-refund
  debit/refund pair legal; owner-approved (`dec-33a67c663c695599`).
- `scripts/seed_database.php::parse_alter()` regex extended for composite-key recognition (index
  guard by name still applies) — small runner fix required for idempotent re-runs.
- `backups/` untouched; no new backup created this phase (migration was non-destructive, verified
  pre/post row counts and stamp preservation).

## 6. Pending / manual items (carried forward)
- Full HTTP regression of the claim button flow (`/team` POST claim_wage, C6 burst test,
  UI countdown parity) on a real host — sandbox smoke covered boot + pins only (plan/58 §3 Phase 4).
- Production-host probe `SELECT @@session.time_zone` after deploy to confirm the pin overrides the
  server default.
- End-of-project doc batch: PRD §150–167 + ROADMAP 7B2 amendments (tiers, whole-tree population,
  manual rolling claim), and the L1 criteria confirmation if any.

*End of plan/59 — M2 execution summary. Application code changed: timezone pins only (Phase 2) +
schema seeds (Phase 1); no wage/business logic modified.*
