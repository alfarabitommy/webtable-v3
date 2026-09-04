# 58 — M2: Weekly Wage Manual Claim, Tier Audit & Cooldown Integrity (Blueprint)

> **Status:** PLAN MODE — inspection blueprint only. **No application code or DB schema was modified.**
> **Source finding:** `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` §3-M2 (and §5 matrix row "Wage L2–L6 tiers + Monday 01:00 cron").
> **Prior art:** C6 race hardening `plan/50`/`plan/51`; ledger ingestion `plan/54`/`plan/55`; timezone sync `plan/20`, `plan/28` §3, `plan/56`/`plan/57` (M1, `SET time_zone` pattern).
> **Decision record:** owner confirmation `decision_id dec-a6cc4929e0fcc60f` (below).

---

## 0. Architectural decision — operational model (confirmed)

The system owner **explicitly chose the Manual Rolling Claim model** (7-day rolling cooldown via
`users.last_wage_claimed_at`) over the PRD v5.0 §167 cron model ("Monday 01:00 WIB automatic
distribution"). Rationale recorded by owner: optimize user engagement and protect treasury cash flow.

Formal documentation of this model change (PRD/ROADMAP amendment) is **batched at the end of the
project** during the documentation update pass — it is NOT part of this phase's code changes.

### Owner decisions (ask, `dec-a6cc4929e0fcc60f`)

| # | Topic | Decision |
|---|-------|----------|
| D1 | Wage tier ladder (L2–L6) | **Keep current code/UI tiers** — `{9→200k, 30→1jt, 70→2.5jt, 130→5jt, 190→9jt}`. PRD §150–155 amended at end-of-project doc batch. |
| D2 | Wage population | **Keep whole-tree active-downline count** (recursive CTE, B+C+D+E+F). PRD amended to whole-tree definition in the doc batch. |
| D3 | Timezone hardening | **Both:** convert `users.last_wage_claimed_at` to `DATETIME` **and** issue an unconditional `SET time_zone = '+07:00'` at DB connection init. |

---

## 1. Scope & method

Inspection targets (read-only during this plan):
1. `application/models/User_model.php` — `WAGE_TIERS`, `determine_wage_level()`, `claim_wage()`, `check_wage_cooldown()`, `get_claim_data()`, `count_all_active_downlines()`, `count_active_b_downlines()`, `sum_sales_b_downlines()`.
2. Callers: `application/controllers/Team.php` (`claim_wage`, `claim_level1`), `application/views/team/index.php` (tier ladder UI).
3. Spec: `docs/1_PRD.md` §150–167, `docs/3_ROADMAP.md` 7B, `docs/2_ERD.md`.
4. Schema: `database.sql` (`users`, `wallet_ledger`); `application/config/database.php`, `autoload.php`, `config.php` (session driver); `index.php` (timezone).
5. Ledger ingestion: `application/models/Wallet_model.php` (`credit()`/`_post()`), timezone pin in `Wallet_model`/`Admin_model` constructors.
6. Framework connect semantics: `system/database/DB.php`, `system/database/DB_driver.php` (`conn_id` default, `initialize()`, `simple_query()` lazy connect).

---

## 2. Findings

### 2.1 Wage tier ladder — current (live, now owner-intended) vs PRD v5.0

`User_model::WAGE_TIERS` (const, L98–104), enforced by `determine_wage_level()` (L163) with
`>= threshold` matching from L6 downward. UI ladder in `views/team/index.php` L293–297 mirrors it
exactly (display-only; comment L18).

| Level | Threshold — live code/UI (D1: owner-intended) | Wage — live (D1: owner-intended) | PRD v5.0 §150–155 (to be amended) | Match? |
|-------|-----------------------------------------------|-----------------------------------|-----------------------------------|--------|
| L2 | ≥ 9 active | Rp 200.000 | 9 → Rp 200.000 | ✅ (endpoint) |
| L3 | ≥ 30 active | Rp 1.000.000 | 18 → Rp 500.000 | ❌ drift |
| L4 | ≥ 70 active | Rp 2.500.000 | 40 → Rp 1.500.000 | ❌ drift |
| L5 | ≥ 130 active | Rp 5.000.000 | 90 → Rp 4.000.000 | ❌ drift |
| L6 | ≥ 190 active | Rp 9.000.000 | 190 → Rp 9.000.000 | ✅ (endpoint) |

Notes:
- **"Level 2 = 9 or 10 downlines?"** → **9.** No "10" appears in any spec, code, or UI; the "9 or 10"
  ambiguity in the finding is resolved to **9** everywhere (code L103, UI L293, PRD L151).
- L3–L5 live thresholds/payouts are **richer than PRD** (30/1jt vs 18/500k, etc.). Per D1 this is the
  intended business design, retained deliberately; the audit report's "mid tiers drift" is resolved by
  amending the PRD, not the code.
- No typographical or boundary inconsistency found *within* the live ladder: thresholds ascend strictly
  (9 < 30 < 70 < 130 < 190), amounts ascend strictly (200k < 1jt < 2.5jt < 5jt < 9jt), `>=` matching is
  deterministic and total-ordered.

### 2.2 Wage population (D2: whole tree retained)

`claim_wage()` L396 calls `count_all_active_downlines()` (recursive CTE over the **entire** referral
tree) → `determine_wage_level()`. PRD says "Agen B+C Aktif" (L1+L2 only). Per D2, whole-tree counting
is retained as intended; the PRD gets amended. No code change. (Performance of the CTE on deep trees is
out of scope here but worth a later check.)

Adjacent L1 drift (NOT covered by D1/D2, left open — see §5): L1 bonus criteria are `3 direct (B)`
active + direct sales ≥ 330.000 (`get_claim_data` L213, `claim_level1` L267–268) vs PRD "3 Agen B+C
Aktif & Total Sales B+C > Rp 330.000". Owner posture so far ("keep current") suggests retaining B-only;
confirm during implementation.

### 2.3 Rolling 7-day cooldown — math & timezone

**Authoritative gate — `claim_wage()` L364–419 (inside TX, after `SELECT ... FOR UPDATE` anchor L346):**
- `$now`/`$cutoff` generated in PHP (`time()`, Asia/Jakarta enforced L338–340); `cutoff = now − 604800 s`.
- Claimable ⇔ `last_wage_claimed_at IS NULL` **or** stored string `<= cutoff` (lexical compare of
  `'Y-m-d H:i:s'` == chronological). Exactly **168 hours (7 × 86,400 s)** — no calendar/floor drift,
  no DST (Asia/Jakarta is fixed UTC+7).
- Replay/countdown branches (L373–393): same-ISO-week replay → `already_claimed`; later week but
  `> cutoff` → `cycle_not_ready` with countdown; conditional stamp `UPDATE ... WHERE id = ? AND
  (last_wage_claimed_at IS NULL OR last_wage_claimed_at <= ?)` gated by `affected_rows() === 1` (L405–419)
  — the C6 race guard. Timestamp written is the PHP-generated WIB string (no `NOW()`).

**UI mirror — `check_wage_cooldown()` L177–199 / `get_claim_data()`:** `DateTime::diff()->days >= 7`
(floor) for claimability and `7 − diff` whole days for the countdown label. Read-only; never the
authorization point (documented in plan/50). Boundary parity with the gate holds: elapsed ≥ 7 d ⇔
`days >= 7`; elapsed < 7 d ⇔ `days <= 6`. Cosmetic only: sub-day remainder is rounded up to whole
"hari" in both implementations (a 2 h wait shows "1 hari").

**Timezone integrity — RISK FOUND (rationale for D3):**
- `users.last_wage_claimed_at` is **`TIMESTAMP`** (database.sql L23) — MySQL converts on write/read
  through the **session** `time_zone`.
- The `+07:00` pin exists but is **guarded**: `Wallet_model::__construct` (L19–21) and
  `Admin_model::__construct` (L13–15) run `SET time_zone = '+07:00'` **only if `$this->db->conn_id` is
  already truthy**.
- CI3 connects **lazily**: `DB_driver::$conn_id` defaults to `FALSE` (L160); `initialize()` →
  `db_connect()` runs on first query (`simple_query()` L784–792). Session driver is `files`
  (config.php L386) → no DB read at bootstrap. `MY_Controller::__construct` loads `User_model`
  (L21, which loads `Wallet_model`) **before** the first query (`Wallet_model->get_balance()` L38).
- ⇒ On any host whose MySQL default session tz ≠ `+07:00` (commonly UTC on cloud/containers), the pin
  **never fires** and the wage stamp is written/read under the server default tz. Effect on the rolling
  gate: each claim's stored instant is shifted by `|session_tz − +07:00|` (UTC ⇒ stored string reads 7 h
  earlier than true WIB ⇒ next claim becomes eligible up to ~7 h **early** ≈ 1 extra claim per ~24 cycles
  ≈ 4% wage over-payout; larger offsets possible on other servers). This is the same bug class Phase 10B /
  M1 (plan/56) fixed for rate limits — reintroduced here by the guarded-connect pattern.
- **No verification was possible in this sandbox** (no live MySQL). First implementation step is a live
  probe (see §3 Phase 4).

### 2.4 Ledger transaction-id uniqueness (`WAGE-{user_id}-Y{year}W{iso_week}`)

Built at L424: `'WAGE-' . (int)$user_id . '-Y' . $week` with `$week = date('o') . 'W' . date('W')`
(ISO year + ISO week, PHP Asia/Jakarta), e.g. `WAGE-42-Y2026W36`. Description embeds the same cycle.

- **Length:** ≤ `VARCHAR(50)` comfortably (max ~35 chars incl. 20-digit unsigned user id).
- **Per-user uniqueness argument (rolling model):** two successful claims by one user are **≥ 168 h
  apart**, so the second stamp always falls in a **later ISO week** (an ISO week spans exactly
  Mon 00:00–Sun 23:59:59 < 168 h; two timestamps sharing an ISO week differ by < 7 days). ⇒ **at most
  one `WAGE-{user}-Y…W…` row per user ever.** (Argument first made in plan/50 L154.)
- **Year-end edge cases verified safe:**
  - Claim Mon 2025-12-29 (ISO **2026-W01**, `date('o')=2026`) → id `...-Y2026W01`; next claim
    ≥ Mon 2026-01-05 → ISO 2026-W02 → distinct.
  - Claim Sun 2025-12-28 (ISO 2025-W52) → `...-Y2025W52`; next claim ≥ Sun 2026-01-04 (ISO **2026-W01**)
    → distinct (the `'o'` ISO-year component absorbs the calendar-year rollover).
  - 53-week ISO years (e.g. 2020, 2026 is not) produce W53 ids — no collision, next is W01 of next ISO
    year.
  - Claims on the SAME ISO week are impossible by the ≥ 7-day rule; the L378 replay branch additionally
    maps a same-week retry to `already_claimed`.
- **Gap — no DB-level backstop:** `wallet_ledger` (database.sql L168–181) has **no UNIQUE constraint on
  `transaction_id`** (PK + non-unique indexes only). Uniqueness currently rests entirely on app logic +
  the C6 row lock. Defense-in-depth fix: `UNIQUE KEY uk_wallet_ledger_user_tx (user_id, transaction_id)`
  (per-user scope — matches the actual risk class of double-claim; avoids auditing every other id
  generator for global uniqueness, cf. P2 `INV-…` same-second note in plan/37).
- Determinism bonus (plan/50): the id survives a future migration to the PRD cron cycle unchanged — the
  ISO week *is* the cycle.

---

## 3. Remediation blueprint (to execute AFTER plan approval — not executed in this phase)

### Phase 1 — Schema hardening (migration, `database.sql` + live-DB ALTER)
- `ALTER TABLE users MODIFY last_wage_claimed_at DATETIME NULL DEFAULT NULL;` — store the literal WIB
  string; immune to session-tz conversion (D3). All writes/comparisons are already PHP-generated strings.
- `ALTER TABLE wallet_ledger ADD UNIQUE KEY uk_wallet_ledger_user_tx (user_id, transaction_id);` (2.4).
  Pre-check for existing duplicate `(user_id, transaction_id)` rows before applying.
- Backfill note: if the live probe (§Phase 4) shows stored stamps were written under a skewed session tz,
  existing `users.last_wage_claimed_at` values are shifted; decide per-owner between (a) one-time
  re-derivation to true WIB instants, or (b) reset of cooldown stamps (treasury-safe direction — loses
  ≤ 7 days of anti-abuse, never pays early). Confirm with owner before running.
- Mirror the DDL change in `database.sql` (schema seed stays canonical).

### Phase 2 — Unconditional `+07:00` session pin at first connect
- Replace the guarded pattern with a pin that runs on the **first** connection, before any other query:
  - Preferred: execute `$this->db->query("SET time_zone = '+07:00'")` as the first DB statement in the
    user base controller (`MY_Controller::__construct`, before model loads/`get_balance`) and the admin
    base controller; keep/strengthen the `Wallet_model`/`Admin_model` constructor SETs as idempotent
    belt-and-braces (drop the `conn_id` guard or keep it — the bootstrap pin makes it redundant).
  - CLI/cron entry points (future M3 cron): apply the same first-statement pin (helper documented in
    plan/56 §3 / plan/28 §3 env contract style).
  - Alternative (rejected for now, note only): `MY_DB_mysqli_driver::db_connect()` subclass hook —
    heavier, unnecessary while the bootstrap first-statement covers all web flows.
- Rationale: protects every other TIMESTAMP read-back (`created_at`/`updated_at`, admin CSV exports,
  M1 daily-window logic) — the systemic fix, not just the wage gate.

### Phase 3 — Code & consistency (no tier/population logic change — D1/D2)
- Keep `WAGE_TIERS`, `determine_wage_level()`, whole-tree `count_all_active_downlines()` **as-is**
  (owner-intended). Remove stale comments that imply PRD v5.0 tier parity, if any.
- UI ladder (`views/team/index.php` L293–297): unchanged (already matches code). Optional polish:
  derive from `WAGE_TIERS` in PHP to kill the mirror-drift risk (P8-style dedupe) — low priority.
- `claim_wage()`/`check_wage_cooldown()`: no logic change required for the gate (2.3 analysis: math is
  exact); only the column-type + pin changes apply. Keep the documented "UI mirror is not the
  authorization point" note.
- L1 criteria drift (§2.2): confirm owner pick (retain B-only vs align to "B+C") in this phase; code
  change only if align chosen.

### Phase 4 — Verification (regression checklist, run after implementation)
- `php -l` on every touched PHP file (roadmap rule).
- **Live timezone probe (first, gating):** from a real request, `SELECT @@session.time_zone,
  @@global.time_zone, NOW(), UTC_TIMESTAMP()` — assert session = `+07:00` before the wage path runs;
  then insert + read back a `last_wage_claimed_at` stamp and assert the string round-trips byte-identical
  (TIMESTAMP→DATETIME conversion correctness).
- **Boundary tests:** inject stamps at exactly 6 d 23 h / 7 d 0 h / 7 d 1 h before now →
  `cycle_not_ready` vs `claimed` at the 168 h mark; assert no claim before 168 h, claim at/after it.
- **Year-end simulation:** stamps on 2025-12-28/29/31 and 2026-01-01/04 → assert generated
  `transaction_id` cycles (`Y2025W52`, `Y2026W01`, `Y2026W02`…) are distinct and the replay branch
  behaves (same-week retry → `already_claimed`).
- **Tx-id collision:** two claims ≥ 7 d apart in the same ISO week (attempt) → impossible by design;
  verify the conditional-stamp gate + `affected_rows()===1` still blocks; attempt a duplicate
  `(user_id, transaction_id)` ledger insert → expect UNIQUE violation after Phase 1.
- **Concurrency:** re-run C6 burst test (plan/51 §5) — two parallel POSTs must yield one credit.
- **UI parity:** Team page countdown label vs gate (`check_wage_cooldown` mirror) on a 6 d 23 h stamp.

### Phase 5 — Documentation batch (end of project, NOT this phase)
Amend in the final doc pass (owner-committed):
- `docs/1_PRD.md` §150–155: tiers → `9/30/70/130/190` with payouts `200k/1jt/2.5jt/5jt/9jt`.
- §151–155 wage population: "Agen B+C Aktif" → **whole-tree active downlines** (D2).
- §167: cron "Monday 01:00" → **Manual Rolling Claim, 7-day cooldown, Asia/Jakarta** (D0).
- §156–166 L1: apply owner pick from §3 Phase 3.
- `docs/3_ROADMAP.md` 7B2 marker ("Cron Job — Weekly Wage") → manual-claim status.

---

## 4. Open items / risks carried forward
1. **L1 bonus criteria** (B-only vs B+C, PRD §150/160) — separate owner pick needed; not decided in
   `dec-a6cc4929e0fcc60f`.
2. **Live session-tz probe unverified** (no MySQL in sandbox) — Phase 4 step 1 is the gate for Phase 1
   backfill semantics.
3. **Existing `users.last_wage_claimed_at` row semantics** under a skewed session tz — backfill decision
   (a)/(b) requires the probe + owner confirmation.
4. **`wallet_ledger.created_at`/other TIMESTAMP columns** remain TIMESTAMP; the Phase 2 pin makes their
   read-back consistent — a full DATETIME conversion sweep is out of scope.
5. Deep-tree performance of the whole-tree recursive CTE (retained population, D2) — future check.

*End of plan/58 — PLAN MODE blueprint; no application code or schema changed.*
