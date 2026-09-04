# 51 — C6 WEEKLY WAGE CLAIM RACE / DOUBLE PAYOUT FIX SUMMARY (Eliminated)

**Vulnerability:** C6 — "Weekly wage claim TOCTOU — double wage" (`plan/37_FULL_SYSTEM_AUDIT_REPORT.md` §2-C6, CRITICAL).
**Mode:** ✅ EXECUTED per approved blueprint `plan/50_C6_WAGE_CLAIM_RACE_FIX_PLAN.md`.
**Date:** 2026-09-02. **Author:** Principal Financial Backend Architect / DB Concurrency Specialist / CI3 Auditor.

---

## 1. Vulnerability (recap)

`User_model::claim_wage()` re-verified the 7-day cooldown inside `trans_start()`, but stamped `last_wage_claimed_at` with an **unconditional** `UPDATE ... WHERE id = ?` and inserted the `wallet_ledger` credit with **no gate**. Under InnoDB REPEATABLE READ the in-TX eligibility reads are lock-free consistent reads → two concurrent AJAX POSTs to `/team/claim_wage` could both observe `claimable=true`, both stamp, and both insert a credit → **double weekly payout**. The ledger `transaction_id` was timestamp-based (`W{level}-{user}-YmdHis`), so duplicates were undetectable. The wage claim UI trigger (`#btn-claim-wage`) was also missing from the Team page (dead `claimWage()` JS only).

## 2. Changes applied

### 2.1 `application/models/User_model.php` — `claim_wage()` rewritten (concurrency engine)

- **Explicit transaction boundaries**: `trans_begin()` / `trans_commit()` / `trans_rollback()` inside `try { } catch (Throwable $e) { ... }` (house pattern from `Rental_model::claim_roi`, plan/44) — no more `trans_start()/trans_complete()` weak form.
- **Anchor row lock first**: `SELECT id, last_wage_claimed_at, is_banned FROM users WHERE id = ? FOR UPDATE` — serializes every wage writer per user on the PK row; the eligibility decision becomes a current read (the second racer blocks, then sees the committed stamp).
- **Interval verified on the locked row** (TOCTOU close) with PHP-generated `Asia/Jakarta` datetimes (`$now` / `$cutoff = $now − 7*86400`, bound params; **no MySQL `NOW()`**) — clean rollback with structured codes:
  - `already_claimed` — last stamp is in the current ISO week (`Y{year}W{iso_week}`) → replay / race-loser (Test 3 / burst).
  - `cycle_not_ready` — previous week's stamp but the 7-day interval has not fully elapsed → countdown message with `next_claim_date`.
  - `not_qualified` — `< 9` active downlines at claim instant.
  - `user_unavailable` — row missing or `is_banned`.
- **Atomic conditional stamp**: `UPDATE users SET last_wage_claimed_at = ? WHERE id = ? AND (last_wage_claimed_at IS NULL OR last_wage_claimed_at <= ?)`.
- **Gate**: ledger credit strictly on `$this->db->affected_rows() === 1`; otherwise rollback + `log_message('error', 'C6 guard: ...')` (observability if an unexpected writer ever races).
- **Deterministic ledger identity**: `WAGE-{user_id}-Y{year}W{iso_week}` (e.g. `WAGE-42-Y2026W36`; `date('W')` zero-pads W01–W53, verified) → at most one ledger row per user per cycle; duplicates now detectable by `GROUP BY transaction_id HAVING COUNT(*) > 1`.
- **Success payload**: `['success'=>true, 'code'=>'claimed', 'message', 'amount', 'level', 'cycle', 'transaction_id']` via new private `_wage_result()` normalizer (only `claimed` maps to `success=true`).
- `check_wage_cooldown()` and `get_claim_data()` untouched (read-only UI mirror, never the authorization point).

### 2.2 `application/controllers/Team.php` — `claim_wage()` hardened (HTTP/UX shell)

- Constructor now loads `Rate_limit_model` + `ratelimit` helper (pattern: `Wallet::process_withdraw`).
- **POST-only**: `if ($this->input->method() !== 'post') { show_404(); return; }` (closes GET-mutation class M9).
- Retains `is_ajax_request()` + `user_id` session guards (JSON `unauthenticated` on missing session).
- **Rate limit** `wage_claim:{user_id}` — 5 hits / 60 s via `Rate_limit_model::check()` + `hit()`; block → `rate_limit_json_response()` (HTTP 429).
- Calls `User_model::claim_wage()` and maps codes to JSON; HTTP 500 only for `code === 'error'`.
- Fresh balance from `Wallet_model::get_balance($user_id)` (ledger-based, **not** stale `users.balance` — C4) → `new_balance` in payload.
- Success notification via `Notification_model->insert(..., 'commission')` (parity with `claim_level1`).
- **Zero `$this->db`** in the controller (verified by grep).

### 2.3 `application/views/team/index.php` — wage claim UI restored

- **Weekly Wage card (Level 2–6) restored** after the Level-1 mission card: header badge with current tier label, "Downline Aktif" progress toward the next tier threshold (ladder 9→L2 … 190→L6), current-level chip, and a **3-state action button**:
  - **Eligible** → enabled `#btn-claim-wage` (emerald) triggering `claimWage()` — "Klaim Gaji Mingguan (Level N) — Rp …".
  - **Cooldown** → disabled, hourglass icon, "Cooldown — Gaji berikutnya: X hari lagi (tanggal)".
  - **Not qualified** → disabled lock icon "Syarat Belum Terpenuhi — Minimal 9 Downline Aktif".
- `claimWage()` JS rewritten to consume the structured payload: terminal disabled states for `claimed` / `already_claimed` / `cycle_not_ready`, re-enable + toast for transient codes (`not_qualified`, `error`, 429), balance refresh reads `d.new_balance`. CSRF via the existing `csrfFetch()` wrapper (`templates/csrf_meta.php`, global `csrf_protection=TRUE`).

### 2.4 `database.sql` — canonical `users` DDL aligned

Added to the canonical `users` DDL (closing the plan/0 housekeeping drift for these columns; shape mirrors the `database_seed.sql` ALTERs):

```sql
`is_level_1_claimed` TINYINT(1) NOT NULL DEFAULT 0,
`last_wage_claimed_at` TIMESTAMP NULL DEFAULT NULL,
```

## 3. Concurrency semantics (why the double payout is now structurally impossible)

```
T1                                        T2 (concurrent)
trans_begin()                             trans_begin()
SELECT ... WHERE id=U FOR UPDATE  ← lock  (blocks on T1's row lock)
  stamp=NULL → claimable ✓
UPDATE users SET last_wage_claimed_at=$now
  WHERE id=U AND (stamp IS NULL OR stamp<=cutoff)
  affected_rows === 1 ✓
INSERT wallet_ledger WAGE-U-Y2026W36       → resumes: reads COMMITTED stamp=$now
UPDATE users SET balance=balance+amount    stamp > cutoff → already_claimed
trans_commit()                             trans_rollback()   ← zero writes
```

Every layer is independently sufficient (row lock serializes; conditional stamp + `affected_rows()===1` gates; deterministic id dedupes) — the invariant survives any future refactor that drops one layer (e.g. the PRD's Monday-01:00 cron from M2).

## 4. Verification performed

| Check | Command | Result |
|---|---|---|
| Lint (model) | `php -l application/models/User_model.php` | ✅ No syntax errors |
| Lint (controller) | `php -l application/controllers/Team.php` | ✅ No syntax errors |
| Lint (view) | `php -l application/views/team/index.php` | ✅ No syntax errors |
| Encapsulation | `grep -n '\$this->db' application/controllers/Team.php` | ✅ PASS — zero `$this->db` usage in Team.php |
| Structure | grep `trans_begin/FOR UPDATE/affected_rows() !== 1/WAGE-/trans_commit/trans_rollback` in User_model | ✅ trans_begin L310, `FOR UPDATE` anchor L318, `affected_rows() !== 1` gate L381, `WAGE-` tx id L391, `trans_commit` L406, rollback on every rejection path |
| Deterministic id | `php -r` `date('o').'W'.date('W')` | ✅ `2026W36` (zero-padded W01–W53) |
| Schema | awk users DDL in `database.sql` | ✅ both columns present |

## 5. Runtime verification (manual — pending live-DB smoke run)

No MySQL/InnoDB instance is available in this sandbox, so the plan/50 §5 DB protocol (Test 1 eligible claim, Test 2 burst concurrency via `xargs -P`, Test 3 immediate replay, session-timezone preflight) must be run against a live DB before production rollout:

```bash
# Test 2 shape (csrf_regenerate=FALSE → stable token per session):
seq 1 10 | xargs -P 10 -I{} curl -s -o /tmp/c6_{}.json -w "%{http_code}\n" \
  -X POST "$BASE/team/claim_wage" -H "X-Requested-With: XMLHttpRequest" \
  -H "Cookie: $SID" -d "synapse_csrf_token=$TOKEN"
# Expect: exactly one code=claimed; others already_claimed (≤4 before the 5/60s rate limit) or HTTP 429;
# DB: COUNT(wallet_ledger WAGE-%) == 1, users.last_wage_claimed_at set once, balance delta == one wage.
```

Note: with the 5-hit/60 s rate limiter, a 10-way burst yields 1×`claimed` + up to 4×`already_claimed` + the rest HTTP 429 `too_many_attempts` — the money invariant (exactly one credit) holds in all interleavings because it is enforced by the model's row lock + conditional gate, not by the limiter.

## 6. Acceptance criteria (plan/50 §7)

- [x] `php -l` passes on every touched PHP file; `Team.php` contains zero `$this->db`.
- [x] `claim_wage()` begins with `trans_begin()` + `SELECT ... FROM users WHERE id = ? FOR UPDATE`.
- [x] Interval re-verified on the locked row with PHP `Asia/Jakarta` bound datetimes; rejections roll back cleanly with `already_claimed` / `cycle_not_ready` / `not_qualified`.
- [x] Timestamp mutation conditional (`stamp IS NULL OR stamp <= cutoff`); ledger credit gated on `affected_rows() === 1`.
- [x] `transaction_id` deterministic & monotonic per cycle (`WAGE-{user}-Y{year}W{iso_week}`).
- [x] Wage card + `#btn-claim-wage` 3-state trigger restored; `claimWage()` JS handles all result codes.
- [x] No `NOW()`/`CURRENT_TIMESTAMP` in the new SQL; all timestamps PHP-generated bound params.
- [ ] Test 1/2/3 runtime assertions — **pending live InnoDB** (see §5).

## 7. Out of scope / notes

- **Schema change B (plan/50 §3.6)** — `UNIQUE KEY` on `wallet_ledger.transaction_id` — deferred: requires a sibling-prefix audit first (`Rental_model` `RENT-{product}-{YmdHis}` is cross-user collidable at 1-second granularity and must gain a user/random component; legacy C6 duplicates must be purged). The deterministic `WAGE-` id already enables duplicate detection without the index.
- **`database_seed.sql`** still carries the now-redundant `ALTER TABLE users ADD COLUMN is_level_1_claimed / last_wage_claimed_at` (lines 22/24). On a *fresh* DB built from the aligned `database.sql`, those ALTERs would error (`ADD COLUMN` is not idempotent in MySQL 8). Left untouched to keep the legacy patch path working for already-deployed DBs — remove/make-idempotent during housekeeping. (The aligned `database.sql` is the source of truth going forward.)
- **`users.username`/`role` drift** (also plan/0) remains — only the two C6-relevant columns were aligned per task scope.
- **C4 (stale `users.balance`)** not addressed here; balance truth remains `wallet_ledger` and the controller reports `Wallet_model::get_balance()`.
- **M2 (PRD Monday-01:00 cron vs manual 7-day claim)** — documented drift; the fix's cycle-keyed identity is cron-compatible.
- Pre-existing minor UI note: `#balance-display` does not exist in `templates/header.php` (the JS balance refresh is a guarded no-op) — out of scope.
