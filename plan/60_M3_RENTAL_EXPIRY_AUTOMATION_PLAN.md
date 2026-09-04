# 60 — M3 RENTAL EXPIRY AUTOMATION & LAZY EVALUATION — ARCHITECTURAL BLUEPRINT

**Finding:** plan/37_FULL_SYSTEM_AUDIT_REPORT.md §3-M3 — "No rental expiration / ROI cron (state machine incomplete)".
**Mode:** PLAN ONLY — no application code or database schema modified. Blueprint awaiting approval.
**Owner constraint (explicit):** the system owner **rejects mandatory server-level cron** as the primary mechanism. Expiry must be **Lazy / Event-Driven** + **defensive query filtering**; cron-class maintenance is optional/manual tooling only.
**Related prior fixes already landed:** C2 claim gate (plan/44–47), C5 checkout lock (plan/48), C6 wage TOCTOU (plan/50), M1 (plan/56), M2 (plan/58). `claim_roi()` already contains the authoritative expiry gate (see §4.3) — this blueprint **completes** M3, it does not rebuild C2.

---

## 1. VERIFIED CURRENT STATE (evidence)

**Live table is `user_rentals`, NOT `rentals`.** The audit's legacy `rentals` table (database.sql:52–70, `gpu_product_id`, `daily_rate_snapshot`) is orphaned (M10) — nothing reads/writes it. **Column is `expired_at`, NOT `expires_at`.** All SQL below targets `user_rentals.expired_at`. (The task statement's `UPDATE rentals ... expires_at <= ?` must be read as `user_rentals ... expired_at <= ?`.)

Schema (database.sql:204–221):
- Columns: `id, user_id, product_id, purchase_price, daily_roi, total_days, days_processed, status ENUM('active','completed','cancelled'), expired_at TIMESTAMP NULL, last_claimed_at TIMESTAMP NULL, created_at`.
- Indexes today: `PRIMARY(id)`, `idx_user_status(user_id, status)`, `idx_product_id(product_id)`.
- **No index supports `status='active' AND expired_at <= ?` scans (global sweep / admin aggregates), and no composite serves `(user_id, status, expired_at)`.**
- No `updated_at` on `user_rentals` — a status flip needs no extra column.

Expiry semantics already codified (single source of truth `Rental_model::claimable_info()`, Rental_model.php:141–175):
- `is_expired = strtotime(expired_at) <= now` (expired **at-or-past** `expired_at`; day-of expiry is still claimable until the boundary passes).
- `is_completed = days_processed >= total_days` (can only coincide with/after expiry given the max-2-days accumulation cap, but kept as a defensive branch).
- Time authority: PHP `Asia/Jakarta` (index.php:131) + session-pinned `SET time_zone = '+07:00'` as the **first DB statement** of every authenticated request (MY_Controller.php:17). Codebase convention: **never rely on MySQL server `NOW()`** (plan/50, User_model comments) — bind PHP-generated WIB datetimes. Under the pinned session tz, MySQL `NOW()` would equal WIB, but bound params stay convention-safe (incl. CLI paths).

**Current behaviour gaps (M3 consequences, all confirmed in code):**
1. **No proactive expiry.** Nothing flips `status='active' → 'completed'` at `expired_at` except `claim_roi()` for the *specific* rental a user attempts to claim. A user who never clicks claim keeps phantom-active rows.
2. **Phantom-active eligibility** — these query `user_rentals ... status='active'` with **no `expired_at` guard**, so expired contracts keep qualifying their (possibly never-logging-in) owner:
   - `User_model::count_active_b_downlines()` (:111–119)
   - `User_model::sum_sales_b_downlines()` (:126–135)
   - `User_model::count_all_active_downlines()` (:143–156, recursive CTE)
   - `User_model::get_team_with_active_status()` `is_active` subqueries (:79–89)
   - `Rental_model::has_active_rental()` (:318–324) — **withdrawal gatekeeper** (Wallet.php:26,114,191)
   - Admin aggregates (`Admin_model.php`): :635 (treasury ROI obligation), :671 (active-investor count), :724 (active-rentals count), :759–760 (downline sales rollup), :800 (per-user active), :851 (rentals list join), and admin `is_active` counters in Admin.php user list.
3. **Claim gate is already authoritative** — `claim_roi()` (Rental_model.php:193–294): `trans_begin` → `SELECT ... FOR UPDATE` (id+user) → `status !== 'active'` reject → `claimable_info()` → if `is_expired || is_completed`: atomic idempotent flip `UPDATE ... SET status='completed' WHERE id=? AND user_id=? AND status='active'` → commit → **0 payout**, code `expired`/`completed`. So the "authoritative ROI claim gate" requirement is **verified as already satisfied**; M3 work here is *verification + consistency*, not new code.

---

## 2. ARCHITECTURE — LAZY / EVENT-DRIVEN, NO CRON

Three complementary layers (defense in depth), in priority order:

1. **Per-request user lazy expiry (primary sweep)** — one indexed, conditional `UPDATE` executed in `MY_Controller` during every authenticated user request, right after session validation and timezone initialization. Self-heals the acting user's own state before any page/business logic reads it (withdrawal gate, rental list, claim UI all see consistent state).
2. **Defensive query filtering (eligibility truth)** — every qualification query about **other users** (downlines) or global aggregates adds `AND expired_at > <WIB-now>` so that a downline who never logs in (never triggers sweep #1) can never keep expired contracts counting toward B-downline counts, L1/L2–L6 wage tiers, `is_active`, or withdrawal-gate semantics.
3. **Authoritative claim gate (exists, C2)** — the last line of defense at the money boundary; any race or missed sweep is still rejected inside the locked transaction with an idempotent completed-flip and 0 payout.

Optional/manual operator tooling (NOT cron): standalone CLI sweep `scripts/expire_rentals.php` and an admin on-demand POST action. Both call the same shared model method (`Rental_model::expire_all_expired()`) so all paths converge on one query.

**Why sweep + defensive filters are both needed:** sweep #1 only runs for the *acting* user. Eligibility checks (sponsor's team page, wage qualification, admin treasury) evaluate **other** users — their expiry cannot be assumed to have been lazily applied. The filters make the queries self-correcting regardless of login history. Admin global aggregates are covered by the same predicate (or by running the global sweep from the CLI/admin action — both supported).

**Race-safety analysis (no payout risk):**
- Sweep UPDATE is conditional (`WHERE status='active' AND expired_at <= ?`) and idempotent — concurrent re-runs are no-ops.
- Sweep vs `claim_roi`: both use conditional `status='active'` writes. If claim wins, sweep's predicate no longer matches (row already `completed`). If sweep wins, the claim's `SELECT ... FOR UPDATE` reads `completed` → rejected `not_active`, 0 payout. No lost payout: an expired contract yields 0 by the existing gate semantics anyway (accrued-but-unclaimed days are use-it-or-lose-it at expiry per `claimable_info`).
- Sweep is NOT wrapped in a money transaction (pure state normalization, no ledger writes) → no lock contention with money paths; the single-row impact is sub-millisecond with the composite index (§5).

---

## 3. DESIGN DECISIONS & DEFAULTS (assumptions — change if owner disagrees)

| # | Decision | Default | Rationale |
|---|---|---|---|
| D1 | Target table/column | `user_rentals.expired_at` | Schema truth; task's `rentals`/`expires_at` names are legacy/audit shorthand. |
| D2 | Expiry boundary | `expired_at <= <WIB now>` (flip at-or-past boundary) | Must match `claimable_info::is_expired` (single source of truth) to avoid a claimable-day being closed early or a sweep racing the gate. |
| D3 | Sweep scope | Time-based only (`expired_at <= now`); **not** `days_processed >= total_days` | Spec's predicate is time-only; `is_completed` coincides with expiry by construction and is already handled by the claim gate's defensive branch. |
| D4 | Time source | PHP `date('Y-m-d H:i:s')` bound parameter everywhere (model methods + CLI after `SET time_zone='+07:00'`) | Codebase convention (plan/50); equivalent to WIB `NOW()` under the pinned session tz but safe on fresh/CLI connections. |
| D5 | Sweep placement in `MY_Controller` | Inside the authenticated block, **after** `SET time_zone` + the `user_id` guard, **after** ban/forced-password guards, **before** balance read/notifications | Spec: "after session validation and timezone initialization". Keeps the sweep out of unauthenticated/guest paths and runs it before any business read. |
| D6 | Model home for the sweep | New methods on `Rental_model`: `expire_user_rentals($user_id, $now = null)`, `expire_all_expired($now = null)` | Cohesion (rental lifecycle); AGENTS.md rule "no SQL in controllers". `MY_Controller` loads `Rental_model` only inside the authenticated branch; constructor cost is negligible (tz guard no-ops after index.php). Alternative (place on already-loaded `User_model`) rejected for cohesion but noted. |
| D7 | Defensive filter shape | `AND ur.status = 'active' AND ur.expired_at > ?` on the **join/qualification** predicate (per-user bound `$now`) | Matches audit wording (`r.status='active' AND r.expires_at > NOW()`) with convention-safe binding. |
| D8 | Admin aggregates | Per-line audit (§4.4): add `expired_at > now` only where semantics = "currently active / current obligation"; leave lifetime/historical stats unchanged | `total_sales`-style rollups may be lifetime; flag each at implementation against its UI label. |
| D9 | Admin button | Optional; default surface = existing admin dashboard treasury card (shows active-rental stats), POST route `admin/expire_expired_rentals`, audit-logged; no new admin page (no `/admin/rentals` page exists today — rentals are managed under `user_detail`) | "e.g. /admin/rentals" was illustrative; adding a full rentals page is out of M3 scope. |
| D10 | Indexes | Add `(user_id, status, expired_at)` + `(status, expired_at)`; keep/drop `idx_user_status` after EXPLAIN verification | Equality-first then range; leftmost prefix of new composite supersedes `idx_user_status`. |

---

## 4. COMPONENT-BY-COMPONENT CHANGES

### 4.1 `Rental_model` — shared expiry engine (new, SQL lives here)
```php
/** Lazy per-user sweep — MY_Controller hook. Idempotent, no TX needed. */
public function expire_user_rentals($user_id, $now = null) {
    $now = $now ?: date('Y-m-d H:i:s');
    return $this->db->query(
        "UPDATE user_rentals
            SET status = 'completed'
          WHERE user_id = ? AND status = 'active' AND expired_at <= ?",
        [$user_id, $now]
    );
}

/** Global sweep — CLI script + admin button. Returns flipped-row count. */
public function expire_all_expired($now = null) {
    $now = $now ?: date('Y-m-d H:i:s');
    $this->db->query(
        "UPDATE user_rentals
            SET status = 'completed'
          WHERE status = 'active' AND expired_at <= ?",
        [$now]
    );
    return $this->db->affected_rows();
}
```
(Constructor already pins `Asia/Jakarta` + loads `Wallet_model`; CLI path stays tz-correct.)

### 4.2 `MY_Controller` — per-request lazy hook
Insert inside the authenticated branch (after existing ban / forced-password guards, before `Wallet_model` balance read):
```php
$this->load->model('Rental_model');
$this->Rental_model->expire_user_rentals($this->session->userdata('user_id'));
```
Effect per request: one indexed UPDATE; `affected_rows` usually 0; sub-millisecond with index §5. It runs before `Rentals::index` (list hides expired), before `Wallet::index/process_withdraw` (withdrawal gate sees truth), and before any claim attempt. Cost model: +1 model load +1 prepared UPDATE per authenticated request; no locks held (autocommit), no ledger writes.

### 4.3 Authoritative claim gate — VERIFY ONLY (already landed, C2/plan 44)
`Rental_model::claim_roi()` already: locks the row (`FOR UPDATE`), rejects non-`active`, evaluates expiry via `claimable_info()` inside the TX, flips `active→completed` idempotently and safely rejects (0 payout). **No code change required.** This blueprint's Phase 4 is a verification/regression pass + confirming `Rentals::claim` (POST-only, rate-limited, delegates to `claim_roi`) and `Rentals::index` render consistently after a lazy sweep (expired rows now `completed` → excluded by `get_active_rentals`).

### 4.4 `User_model` + other models — defensive downline/qualification filters
Add `AND ur.expired_at > ?` (bound `$now`, `date('Y-m-d H:i:s')`) to:
- `count_active_b_downlines()` (:116) — B-tier count
- `sum_sales_b_downlines()` (:132) — B-tier sales (qualification uses current, non-expired contracts)
- `count_all_active_downlines()` (:153) — recursive-CTE tree count
- `get_team_with_active_status()` `is_active` subqueries (:81, :85)
- `Rental_model::has_active_rental()` (:318) — withdrawal gatekeeper: add `->where('expired_at >', $now)` (defense-in-depth even though the sweep covers self-queries; closes sub-second expiry-between-sweep-and-gate and any non-swept call path)

Admin aggregates (`Admin_model.php`) — audit each against its view label; where the label means "currently active / outstanding obligation", add the same predicate:
- :635 treasury ROI obligation, :671 active-investor count, :724 active-rentals count, :800 per-user active, :851 rentals-list join, and the team/downline rollups :759–760 (only if their UI means *current* qualification sales — verify; do not change if lifetime).
- Admin `users` list `is_active` counter (Admin controller) — after sweeps+CLI these self-heal; add the filter only where cheap (list query) — optional.

### 4.5 Database indexing (`database.sql` + live `db_webtable`)
Replace `idx_user_status` with two composites (equality-first, range last):
```sql
-- database.sql definition change
ALTER TABLE `user_rentals`
  ADD INDEX `idx_user_status_expired` (`user_id`, `status`, `expired_at`),
  ADD INDEX `idx_status_expired` (`status`, `expired_at`);
-- live DB (idempotent, additive; MySQL 8 INSTANT for secondary indexes):
-- optionally DROP INDEX idx_user_status after EXPLAIN confirms no regression
```
Rationale:
- Sweep: `WHERE user_id=? AND status='active' AND expired_at<=?` → `idx_user_status_expired` (prefix + range, near-covering).
- Global sweep / CLI / admin aggregates: `WHERE status='active' AND expired_at<=?` → `idx_status_expired`.
- Downline JOINs (qualification): driven by `user_id` of downline + status + expiry → `idx_user_status_expired`.

### 4.6 `scripts/expire_rentals.php` — optional/manual CLI
Follow `scripts/backfill_withdrawal_fees.php` conventions (standalone mysqli, `BASEPATH`/`ENVIRONMENT` defines, credentials parsed from `application/config/database.php`, `SET time_zone='+07:00'` after connect, exit codes, no CI bootstrap). Flags:
- `--dry-run` (default): `SELECT COUNT(*)` of rows matching `status='active' AND expired_at <= <WIB now>` + sample IDs; writes nothing.
- `--apply`: `expire_all_expired()` inside one transaction; prints flipped count.
- `--help`, `-h`. Exit codes 0/1/2. Idempotent. **No cron wiring** — operator-invoked only (owner constraint).

### 4.7 Admin on-demand trigger (optional)
- `Admin::expire_expired_rentals()` — POST-only; admin session (already enforced by Admin controller); calls `Rental_model::expire_all_expired()`; audit log `admin_expire_rentals` via `Audit_model` inside `trans_start/complete` (mirror `cancel_rental` pattern, Admin.php:614–651); flashdata with flipped count; redirect back.
- Route registration in `application/config/routes.php` for a pretty URL (project convention), e.g. `admin/rentals/expire` — or keep the raw `admin/expire_expired_rentals` if no pretty URL is warranted; placement on the dashboard treasury card (label in Indonesian, e.g. "Tutup Sewa Kedaluwarsa").
- Per-user variant (button in `user_detail` rentals table calling `expire_user_rentals($id)`) is a trivial add-on; default is the global action only.

---

## 5. PERFORMANCE & INDEX VERIFICATION (acceptance criteria)
- `EXPLAIN` the sweep: `type=ref/range`, `key=idx_user_status_expired`, rows ≈ 0–few for a normal user.
- `EXPLAIN` global sweep: `key=idx_status_expired`.
- Regression: `EXPLAIN` existing hot queries (`has_active_rental`, downline counts, `get_active_rentals`) — no full scan introduced; dropping `idx_user_status` only after confirming the composite covers its leftmost prefix.
- Overhead: per-request sweep must not add a measurable latency class (single indexed UPDATE, usually 0 rows; verified by curl timing before/after on a warm page).

---

## 6. IMPLEMENTATION PHASES (work order)

### Phase 1 — Expiry engine + schema definition
- Add `Rental_model::expire_user_rentals($user_id, $now)` and `expire_all_expired($now)` (bound PHP-WIB param; idempotent conditional UPDATEs).
- Update `database.sql` `user_rentals` indexes: add `idx_user_status_expired` + `idx_status_expired` (superseding `idx_user_status`).
- Apply the additive ALTER to the live `db_webtable` (keep `idx_user_status` until Phase 6 EXPLAIN sign-off).
- `php -l` on `Rental_model.php`.

### Phase 2 — Lazy per-request hook
- Insert the sweep call in `MY_Controller` authenticated branch (after guards, before balance read).
- Confirm no double model-load overhead concern; document the hook with a comment (Indonesian, referencing this plan).
- `php -l` on `MY_Controller.php`.

### Phase 3 — Defensive eligibility filters
- `User_model`: add `expired_at > ?` to `count_active_b_downlines`, `sum_sales_b_downlines`, `count_all_active_downlines`, and both `is_active` subqueries in `get_team_with_active_status`.
- `Rental_model::has_active_rental()`: add `expired_at >` bound filter (withdrawal gatekeeper).
- Admin aggregates audit per §4.4 (label-driven; add predicate to current/obligation semantics only).
- `php -l` on touched models.

### Phase 4 — Claim-gate & UI consistency verification
- Verify `claim_roi()` expired branch (flip + reject, 0 payout) — no code change expected.
- Verify `Rentals::index` no longer shows expired contracts after a sweep (they are `completed` and filtered), and claim button math matches `claimable_info`.
- Manual scenario: rental with `expired_at` in the past + `status='active'` → user logs in → row flips before listing; claim attempt on an expired row returns `expired` with 0 payout; two concurrent claims → one payout (C2 regression).

### Phase 5 — Manual tooling (optional per owner)
- `scripts/expire_rentals.php` (`--dry-run`/`--apply`, exit codes, tz-pinned).
- Admin POST action + audit log + dashboard button (default global; per-user optional).
- `php -l` on script + `Admin.php`.

### Phase 6 — Verification, docs, sign-off
- `php -l` on every touched file (roadmap rule).
- EXPLAIN matrix (§5) incl. regression on `idx_user_status` drop.
- curl end-to-end: register → topup → checkout → (set `expired_at` past via SQL) → login → list/claim behaviour; expired-downline (never logs in) does not count in sponsor's `count_active_b_downlines`/team/wage views.
- Update docs/AGENTS notes (M3 status) and mark §5 matrix row in plan/37 as resolved (follow plan/56–59 summary convention; a `61_M3_..._SUMMARY.md` may be produced post-implementation).

---

## 7. OUT OF SCOPE / DEFERRED
- Daily-ROI auto-distribution cron (PRD §32) — owner rejected cron dependency; ROI remains user-initiated claim (C2-safe). If auto-pay is ever wanted it belongs to a separate PRD-alignment decision.
- Decommissioning the orphaned legacy `rentals` table (M10).
- Notification on rental completion (no existing hook; can be added later).
- Full admin rentals page (`/admin/rentals`) — not needed for the trigger (D9).

*End of blueprint — plan/60. Read-only planning artifact; no application code or schema changed.*
