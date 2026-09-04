# 50 — C6 WEEKLY WAGE CLAIM RACE / DOUBLE PAYOUT FIX PLAN

**Vulnerability:** C6 — "Weekly wage claim TOCTOU — double wage" (`plan/37_FULL_SYSTEM_AUDIT_REPORT.md` §2-C6, CRITICAL).
**Mode:** 🔵 PLAN ONLY. No application code or database schema was modified to produce this document.
**Date:** 2026-09-02. **Author:** Principal Financial Backend Architect / DB Concurrency Specialist / CI3 Auditor.

---

## 0. EXECUTIVE SUMMARY

`User_model::claim_wage()` re-verifies the 7-day cooldown *inside* a transaction, but then stamps `last_wage_claimed_at` with an **unconditional** `UPDATE users SET last_wage_claimed_at = ? WHERE id = ?` and gates **nothing** on `affected_rows()`. Under InnoDB REPEATABLE READ the in-TX eligibility reads are *consistent (snapshot) reads* that take no locks, so two concurrent AJAX POSTs to `/team/claim_wage` can both observe `claimable=true`, both overwrite the timestamp (last-writer-wins), and **both insert a `wallet_ledger` credit → double weekly wage payout**. The ledger `transaction_id` is timestamp-based (`W{level}-{user}-YmdHis`) — non-deterministic, so it cannot even be used to detect the duplicate. `claim_level1()` is already race-safe (atomic `WHERE is_level_1_claimed = 0` + `affected_rows() === 1` gate); `claim_wage()` is the one money path that skipped that pattern.

**The fix (4 defense layers, all inside one explicit transaction):**

1. **Pessimistic anchor-row lock** — `SELECT id FROM users WHERE id = ? FOR UPDATE` as the *first* statement of an explicit `trans_begin()`, exactly like the canonical `Rental_model::claim_roi()` pattern (plan/44). All wage-claim writers for a user serialize on this single PK row.
2. **In-TX interval verification on the locked row** — re-read `last_wage_claimed_at` from the locked row (current read) and verify the 7-day interval has actually elapsed using **PHP-generated `Asia/Jakarta` datetimes** (house rule: "PHP Asia/Jakarta — bukan MySQL NOW()", cf. `claim_roi` and M1). Outcome codes: `already_claimed` / `cycle_not_ready` → clean rollback, **zero writes**.
3. **Atomic conditional timestamp mutation** — `UPDATE users SET last_wage_claimed_at = ? WHERE id = ? AND (last_wage_claimed_at IS NULL OR last_wage_claimed_at < ?)`; the `wallet_ledger` credit is gated on `affected_rows() === 1`, mirroring the audit's suggested fix but *layered on top of* the lock so the invariant survives any future refactor that drops one layer.
4. **Deterministic, monotonic ledger identity** — `WAGE-{user_id}-Y{year}W{iso_week}` (or a per-user cycle counter) so each payout maps 1:1 to one weekly cycle and duplicates are detectable (and blockable by a future `UNIQUE` index — see §3.6 for the prerequisite sibling-prefix audit).

All SQL and transaction handling stays **encapsulated in `User_model.php`**. `Team::claim_wage()` keeps only HTTP-method enforcement, CSRF (already global), optional rate limiting, and JSON/flash mapping — zero `$this->db` in the controller.

---

## 1. VULNERABILITY CONTEXT (from audit §2-C6)

> Files: `application/models/User_model.php` (`claim_wage`).
> Issue: cooldown is re-verified inside the TX, but `last_wage_claimed_at` is updated **unconditionally** (`WHERE id = ?` only). Two concurrent AJAX calls both see `claimable=true`, both update, both insert the wage credit → double payout. `claim_level1` is safe (atomic `WHERE is_level_1_claimed = 0` + `affected_rows`); `claim_wage` lacks the same pattern.
> Fix (audit): conditional `UPDATE users SET last_wage_claimed_at = NOW() WHERE id = ? AND (last_wage_claimed_at IS NULL OR last_wage_claimed_at < NOW() - INTERVAL 7 DAY)` and gate the credit on `affected_rows() === 1`.

### 1.1 Why the current code is racy (mechanism)

1. `claim_wage()` opens `trans_start()` then runs `count_all_active_downlines()` (recursive CTE) and `check_wage_cooldown()` — the latter does `get_user_by_id()` = a **plain `SELECT * FROM users WHERE id = ?`**, which under InnoDB REPEATABLE READ is a *consistent read*: it reads the TX snapshot and **takes no lock**.
2. Both racing TXs therefore see `last_wage_claimed_at` in its pre-claim state (`NULL` or older than 7 days) → both evaluate `claimable=true`.
3. Each TX then executes the **unconditional** timestamp update (`WHERE id = ?` only). Both succeed — the second simply overwrites the first (no error, `affected_rows()` is 1 for both because the predicate is only on the PK).
4. Each TX inserts its own `wallet_ledger` credit row and adds the wage to `users.balance`. Both commit → **two credits for one weekly cycle**.

**Race timeline (user U, `last_wage_claimed_at` = NULL, wage level L):**

```
T1                                          T2
trans_start()                               trans_start()
count_all_active_downlines → n   [snapshot] count_all_active_downlines → n   [snapshot]
check_wage_cooldown: NULL → claimable ✓     check_wage_cooldown: NULL → claimable ✓
UPDATE users SET last_wage_claimed_at=now   (blocks? no — no row lock taken)
    WHERE id=U  → affected_rows 1           UPDATE users SET last_wage_claimed_at=now
INSERT wallet_ledger W-L-U-... credit       WHERE id=U  → affected_rows 1   ← overwrites T1's stamp
INSERT wallet_ledger W-L-U-... credit       ← SECOND credit (different tx_id only because seconds differ)
COMMIT                                      COMMIT
→ users: 1 stamp, ledger: 2 credits, balance +2×wage        ← DOUBLE PAYOUT
```

### 1.2 Why the audit's single-line fix is necessary but not sufficient

The audit's conditional `UPDATE ... WHERE last_wage_claimed_at IS NULL OR last_wage_claimed_at < cutoff` **alone** already closes the race under MySQL (the second TX's `UPDATE` is a *current read* on the row: it blocks on T1's uncommitted row, then re-evaluates the `WHERE` against T1's committed stamp → 0 rows → credit gated off). This plan keeps that conditional as **layer 3**, but adds the `FOR UPDATE` anchor (layer 1) + locked-row verification (layer 2) because:

- a one-line conditional gives **no structured status codes** — the UI cannot distinguish "sudah diklaim" from "belum waktunya" or "kualifikasi gagal" (needed for Test Cases 2 & 3 and the view's 3-state button);
- it makes the **eligibility snapshot stable** (downline counts / wage level read post-lock, at a single instant);
- it keeps the invariant correct if a future refactor (or a second claim path, e.g. the PRD's Monday-01:00 cron from M2) forgets the conditional — the row lock serializes every wage writer per user;
- deterministic `transaction_id` (layer 4) turns "silent double credit" into an auditable, indexed-deduplicable fact.

---

## 2. INSPECTION FINDINGS (current state of target files)

### 2.1 Model — `application/models/User_model.php`

| Member | Current behavior | C6 relevance |
|---|---|---|
| `check_wage_cooldown($user_id)` (L169) | `get_user_by_id()` plain read; PHP `DateTime::diff()->days >= 7` → claimable; else `days_remaining = 7 - diff_days` | consistent read, **no lock**; floor-based day math (a 6d23h59m wait reports `7` days remaining — cosmetic, reused by view) |
| `count_all_active_downlines()` (L135) | recursive CTE over whole tree + `user_rentals` (consistent reads, no locks) | eligibility counter; safe to run post-lock |
| `determine_wage_level($n)` (L155) | maps `WAGE_TIERS` {9→200k … 190→9M} → `['level','amount','label']` | pure function |
| `claim_wage($user_id)` (L291) | `trans_start()` → re-check level → re-check cooldown → **unconditional** `UPDATE users SET last_wage_claimed_at = date(...) WHERE id = ?` → insert `wallet_ledger` `W{level}-{user}-{YmdHis}` credit → `SET balance = balance + amount` → `trans_complete()`; returns `{success,message,amount,level}` | **the vulnerable method** — no lock, no conditional stamp, no `affected_rows()` gate, non-deterministic `tx_id` |
| `claim_level1()` (L240) | `WHERE id = ? AND is_level_1_claimed = 0` + `affected_rows() === 0` rollback | the in-repo **safe pattern** to mirror |

### 2.2 Controller — `application/controllers/Team.php`

- `claim_wage()` (L94): sets JSON content type; guards only `is_ajax_request()` (checks the `X-Requested-With` header) and the `user_id` session; calls `$this->User_model->claim_wage($user_id)`; on success re-reads `get_user_by_id()` for `new_balance`; echoes `{success,message,amount,level,new_balance}` with **HTTP 200 in all cases**.
- **No HTTP-method check** — a `GET` carrying the AJAX header would pass (`M9`/GET-mutation class noted elsewhere in the audit). CSRF is global (`config.php`: `csrf_protection = TRUE`, `csrf_regenerate = FALSE`, token injected by the `csrfFetch()` wrapper from `templates/csrf_meta.php`) — fine for concurrency testing since the token is stable across requests in a session.
- **No rate limit** — `Wallet::process_withdraw` shows the house pattern (`Rate_limit_model->check/hit` + `rate_limit_json_response()` helper) but `Team::claim_wage` does not use it.

### 2.3 View — `application/views/team/index.php`

- `claimWage()` JS (L311) uses `csrfFetch()` → POST `/team/claim_wage`; disables `#btn-claim-wage` client-side while in flight and re-enables on failure — a UX-only double-click guard (never trusted server-side).
- **Gap:** the wage claim card / `#btn-claim-wage` element is **not rendered anywhere in the current view** — only the Level-1 mission card has a button; the L2–6 wage tiers survive as help-modal text (L204–215) and the JS handler is dead code. A claim UI restore is required for the manual-claim flow to be reachable at all (see §3.7).

### 2.4 Schema (canonical `database.sql` + runtime drift)

- `users`: canonical DDL (L11–30) has **no** `last_wage_claimed_at` / `is_level_1_claimed`; production columns exist only via `database_seed.sql` ALTERs (`is_level_1_claimed TINYINT(1) NOT NULL DEFAULT 0`; `last_wage_claimed_at TIMESTAMP NULL DEFAULT NULL`). Known housekeeping drift (plan/0_HOUSEKEEPING_PLAN.md) — must be aligned so tests on a fresh canonical DB pass.
- `wallet_ledger` (L163–176): `id, user_id, transaction_id VARCHAR(50) NOT NULL, type ENUM('credit','debit'), amount DECIMAL(15,2), description, created_at`; indexes only `idx_user_id/idx_type/idx_created_at`; **no `UNIQUE` on `transaction_id`** (same gap noted for C2/C5).
- `users.balance DECIMAL(15,2)` is **stale** for most money moves (C4) — the fix keeps writing it for consistency with the rest of the codebase, but the *invariant* enforced here is "≤ 1 credit per 7-day cycle", serialized on the `users` row, not on `balance`.

### 2.5 In-repo reference patterns (the "correct" style to copy)

| Pattern | Where | What it proves |
|---|---|---|
| `trans_begin()` + try/catch + explicit `trans_commit()`/`trans_rollback()`; `SELECT ... FOR UPDATE` as **first statement**; gate payout on `affected_rows() === 1`; PHP-datetime bound params | `Rental_model::claim_roi()` (C2 fix, plan/44) | Pessimistic lock + explicit TX boundary + atomic gate = established house pattern for money races |
| `SELECT balance FROM users WHERE id = ? FOR UPDATE` inside `trans_begin()` | `Ledger_model::insert_transaction()` (dead code, C4/M6) | `users` PK row is the canonical InnoDB serialization anchor |
| `WHERE id = ? AND status_guard = 0` + `affected_rows()` gate | `User_model::claim_level1()`; `Wallet_model::approve_deposit_simulator()` (C1) | conditional transitions as concurrency guards |
| Deterministic per-object ledger id | `Rental_model` ROI credit `ROI-{rental_id}-D{days_processed}` (L266) | precedent for reproducible `transaction_id` |

---

## 3. ARCHITECTURAL DESIGN

### 3.1 Locking model — pessimistic anchor-row lock on `users`

**Lock target:** the claimant's `users` row, `SELECT id FROM users WHERE id = ? FOR UPDATE`, executed as the **very first statement** of the explicit transaction.

Why:
1. Every per-user money path in this codebase that must serialize does so through the `users` PK row (`Ledger_model`, `claim_roi` style); the wage claim becomes another serialized writer on that row.
2. Single-row PK lock: cheap, no table/range locks, and the only other wage writers for that user are other `claim_wage` calls — they contend only on this row, in the same order → **no deadlock surface** (downline-count reads take no locks; the recursive CTE cannot deadlock against the anchor lock).
3. The lock converts the eligibility decision into a **current read**: T2 blocks at the `SELECT ... FOR UPDATE` until T1 commits, then reads T1's committed `last_wage_claimed_at` → the in-TX check correctly reports `already_claimed` instead of racing past it.

Transaction discipline (copy `claim_roi` verbatim in structure):
- `$this->db->trans_begin();` … `try { … }` … on any rejection `$this->db->trans_rollback(); return result;` … on success `$this->db->trans_commit();` — **no** `trans_start()/trans_complete()` (its nested/destructive-mode semantics with `trans_status()` are the weak form; explicit begin/commit/rollback is the hardened style).
- All timestamps are **PHP-generated `Asia/Jakarta` strings bound as parameters** (`date('Y-m-d H:i:s')`), never MySQL `NOW()`/`CURRENT_TIMESTAMP` in SQL — the established timezone convention in this codebase (M1, Phase 10B, `claim_roi` comment "PHP Asia/Jakarta — bukan MySQL NOW()"). A preflight acceptance check must confirm the DB session `time_zone` does not skew TIMESTAMP round-trips (see §5.4).

### 3.2 In-transaction interval & eligibility verification (on the locked row)

Order of operations inside `claim_wage($user_id)` after the lock is acquired:

1. **Locked-row read** (current read): `SELECT id, last_wage_claimed_at, is_banned FROM users WHERE id = ? FOR UPDATE`. Missing row / `is_banned = 1` → rollback, `code: user_unavailable`.
2. **Interval verification against the locked value** — the authoritative TOCTOU close:
   - `$cutoff = date('Y-m-d H:i:s', strtotime('-7 days'))` (PHP, Asia/Jakarta).
   - `last_wage_claimed_at IS NOT NULL AND last_wage_claimed_at >= $cutoff` → rollback; if the stamp is today/this visible cycle → `code: already_claimed`, else → `code: cycle_not_ready` (both with a human `message` + `next_claim_date`). This is *informative*, but the hard guarantee comes from the conditional UPDATE in 3.3 (see why-below).
3. **Eligibility snapshot (post-lock, stable)**: `count_all_active_downlines()` → `determine_wage_level()`. `null` → rollback, `code: not_qualified`. (These stay consistent reads — correctness of the *cycle invariant* does not depend on them being current reads, and they are now taken at one instant after serialization.)
4. Proceed to 3.3.

Rationale for keeping both 3.2-step-2 *and* the 3.3 conditional: the locked-row check produces the UI codes and avoids writing the stamp when nothing should change; the conditional UPDATE is the last-line atomic guard that stays correct even against a hypothetical writer that bypasses the lock (e.g. the future PRD cron).

### 3.3 Atomic status/timestamp mutation (the gate)

```sql
UPDATE users
   SET last_wage_claimed_at = ?            -- $now  (PHP Asia/Jakarta)
 WHERE id = ?
   AND (last_wage_claimed_at IS NULL
        OR last_wage_claimed_at < ?)       -- $cutoff = $now − 7 days
```

- Bound params only; executed **after** the lock, inside the same TX.
- **Gate:** `if ($this->db->affected_rows() !== 1) { trans_rollback(); return already_claimed; }` — log `log_message('error', 'C6 guard: …')` when this fires despite the locked-row pre-check (means an unexpected writer raced; observability requirement).
- Only *after* this gate passes are the ledger credit and balance update executed.

### 3.4 Deterministic, monotonic ledger transaction ID

Replace `'W' . $level . '-' . $user_id . '-' . date('YmdHis')` with a **cycle-keyed** id:

```
WAGE-{user_id}-Y{year}W{iso_week}     // e.g. WAGE-42-Y2026W36
```

- `$year/$iso_week` = PHP `date('o')` / `date('W')` of `$now` (ISO-8601 week numbering, Asia/Jakarta instant of the *successful* claim).
- **Uniqueness argument under the live 7-day-rolling rule:** two successful claims by the same user are ≥ 7 days apart, which always lands in a different ISO week (a 7-day span always crosses a Monday week boundary; year rollover yields a different `Y…W…` string) → **at most one `WAGE-{user}-Y{year}W{iso_week}` row per user ever**. Fits `VARCHAR(50)` comfortably.
- **Dedupe semantics:** the id is the *detection/audit* key and the future uniqueness enforcement point. It also survives the migration to the PRD's fixed weekly cycle (M2: Monday 01:00 cron) unchanged — the ISO week *is* the cycle then.
- `description` keeps the human detail (`Gaji Mingguan Level X — N downline aktif`) and gains the cycle label; `type='credit'`, `amount` unchanged.

> Note: the deterministic id is **defense-in-depth**, not the primary guard — the row lock + conditional stamp are the invariant. The id must never be *required* to differ to detect a duplicate (that was the old timestamp-based failure mode); under the fix, a second credit for the same cycle is impossible before the insert is even attempted.

### 3.5 Credit & balance mutation (same TX, after the gate)

1. `INSERT wallet_ledger (user_id, transaction_id='WAGE-…', type='credit', amount=…, description=…)` — mirror existing column set; no `balance_after` column exists on `wallet_ledger` (that's the dead `transactions` table, M6 — out of scope).
2. `UPDATE users SET balance = balance + ? WHERE id = ?` (relative increment, existing style). C4 (stale `balance` vs ledger) is out of scope; the ledger row is the true record and is inserted in the same TX.
3. `trans_commit()`; on exception → `trans_rollback()` + `log_message('error')` + `code: error`.
4. Return envelope: `{success:true, code:'claimed', message, amount, level, cycle:'Y2026W36', transaction_id}` (transaction_id echoed for the audit trail/toast).

### 3.6 Schema changes (deferred to implementation phase)

| Change | Table | Purpose | Prerequisite |
|---|---|---|---|
| **A (required for tests)** — align canonical DDL | `users` | add `last_wage_claimed_at TIMESTAMP NULL DEFAULT NULL`, `is_level_1_claimed TINYINT(1) NOT NULL DEFAULT 0` (mirror `database_seed.sql` ALTERs) | none — closes plan/0 drift |
| **B (recommended, gated)** — `UNIQUE KEY uk_wallet_ledger_tx (transaction_id)` | `wallet_ledger` | hard DB-level dedupe of any ledger id, incl. wage | **sibling-prefix audit first**: current writers — `ADM-…` (md5 suffix, unique), `INV/WD-…` (unique invoice/WD numbers), `ROI-{rental}-D{days}` (deterministic, unique), `L1-{user}-{date}` (unique), `RENT-{product}-{YmdHis}` (**not** unique across users: two users buying the same product in the same second collide) → RENT- format must gain a user/random component first, and any legacy duplicates produced by the C6 race must be purged/deduped before the index is applied |

Schema change B is optional for correctness (layers 1–3 fully serialize); it is the audit-proofing layer. If the sibling audit is deferred, keep the deterministic id anyway — it makes the *absence* of duplicates verifiable by a trivial `GROUP BY transaction_id HAVING COUNT(*) > 1` query.

### 3.7 Controller & view changes (thin shell)

**`Team::claim_wage()`** (HTTP/UX layer only — **zero `$this->db`**):
1. JSON content type (existing).
2. **POST-only**: `$this->input->method() !== 'post'` → `show_404()` (closes the GET-mutation hole).
3. `is_ajax_request()` + `user_id` session guard (existing).
4. **Rate limit (optional but recommended)**: `$this->Rate_limit_model->check('wage_claim:'.$user_id, 5, 60)` + `rate_limit_json_response($throttle)` on hit, `hit()` on pass — mirror `Wallet::process_withdraw` L141–149. (Prevents hammering the endpoint with replay bursts; CSRF already global.)
5. Map the model's `code` → JSON: HTTP 200 with `{success:false, code, message}` for `already_claimed`/`cycle_not_ready`/`not_qualified` (UI toast), `{success:true, …}` for `claimed`; `error` → HTTP 500 JSON. Add `new_balance` from `Wallet_model::get_balance($user_id)` (ledger-based, not the stale `users.balance` — consistent with C2 fix and the view's `#balance-display`).
6. Success path may notify (`Notification_model->insert`, as `claim_level1` does).

**`application/views/team/index.php`** — restore the L2–6 wage card (the manual claim flow needs a reachable button):
- Render a "Gaji Mingguan — Level 2–6" card driven by `claim_data` (`weekly_eligible`, `current_level/current_wage_label`, `cooldown_active`, `days_remaining`, `next_claim_date`) with a 3-state button: **claimed/cooldown** (disabled, countdown text) / **eligible** (`id="btn-claim-wage"`, `onclick="claimWage()"`, the existing JS) / **not qualified** (disabled). CSRF is already injected via `csrf_meta` partial + `csrfFetch()`; no form wrapper needed.
- Client-side double-click guard (disable + spinner) stays — explicitly documented as UX-only.

### 3.8 Encapsulation contract (AGENTS.md)

- `User_model::claim_wage()` = the **only** place with SQL/transactions for this flow: lock, verification, conditional stamp, ledger credit, balance update, commit/rollback, status codes.
- `Team::claim_wage()` = HTTP method + AJAX + session + rate limit + CSRF (global) + code→message/JSON mapping. Verify by `grep` that no `$this->db` appears in `Team.php`.

---

## 4. RESULT CODES (model ↔ controller ↔ view contract)

| `code` | HTTP | Meaning | Action |
|---|---|---|---|
| `claimed` | 200 | credit committed | toast success; disable button; refresh balance |
| `already_claimed` | 200 | stamp present in current window (locked-row/conditional gate) | toast "Gaji mingguan sudah diklaim"; disable button |
| `cycle_not_ready` | 200 | stamp exists but ≥ visible-window (boundary case) | toast countdown with `next_claim_date` |
| `not_qualified` | 200 | < 9 active downlines at claim instant | toast requirement message; button stays disabled |
| `user_unavailable` | 403/200 | user row missing / banned | session-level handling |
| `error` | 500 | unexpected DB failure | generic message; `log_message('error')` |

---

## 5. VERIFICATION & TESTING PROTOCOL

### 5.0 Static checks
- `php -l application/models/User_model.php` (and any touched file) — roadmap lint rule.
- `grep -n '\$this->db' application/controllers/Team.php` → **zero matches** (encapsulation acceptance).
- `grep -rn "claim_wage\|WAGE-" application/models/User_model.php` — single implementation site.

### 5.1 Test 1 — Eligible single claim
Precondition: user with ≥ 9 active downlines, `last_wage_claimed_at` = NULL or > 7 days.
1. Log in (session cookie + CSRF token from page meta).
2. `curl -X POST /team/claim_wage` → assert `success:true`, `code:claimed`, `amount` == tier amount for the active count, `cycle` == current `Y{year}W{iso_week}`.
3. DB asserts: exactly **1** `wallet_ledger` credit with `transaction_id = 'WAGE-{user}-Y…W…'`; `users.last_wage_claimed_at` == request instant (±1 s); balance delta == amount.

### 5.2 Test 2 — Burst concurrency race (the C6 regression test)
Precondition: fresh eligible user (stamp NULL). Fire **5–10 concurrent POSTs**:

```bash
export SID=…; export TOKEN=…;  # from a logged-in session (csrf_regenerate=FALSE → stable token)
seq 1 10 | xargs -P 10 -I{} curl -s -o /tmp/c6_{}.json -w "%{http_code}\n" \
  -X POST "$BASE/team/claim_wage" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "Cookie: $SID" \
  -d "synapse_csrf_token=$TOKEN"
```

Assert:
- exactly **1** response `code:claimed`; the other 9–∞ → `code:already_claimed` (HTTP 200), **no** `error`/500.
- DB: `SELECT COUNT(*) FROM wallet_ledger WHERE user_id=? AND type='credit' AND transaction_id LIKE 'WAGE-%'` == **1**; `users.last_wage_claimed_at` set once; balance delta == exactly one wage.
- Repeat with the user's stamp aged < 7 days → **0** new credits (belt: conditional gate re-tested under contention).

### 5.3 Test 3 — Immediate replay
1. After a successful claim, immediately re-POST.
2. Assert `code:already_claimed`, `success:false`.
3. **Zero-write proof:** snapshot `COUNT(wallet_ledger)` + `users.updated_at`/`last_wage_claimed_at` before and after → identical (the rollback path writes nothing; verify also that no `transactions`/other side rows appeared).

### 5.4 Test-environment prerequisites / caveats
- Run against **real MySQL/InnoDB** (InnoDB row locks are the mechanism under test; sqlite/etc. prove nothing).
- **Timezone preflight:** confirm the DB session `time_zone` round-trips TIMESTAMP values without skew vs PHP Asia/Jakarta (Phase 10B/M1 class). If the session is UTC while PHP is +7, either set the CI3 DB config timezone (`'+07:00'`, supported since CI 3.1.10) or bind/compare timestamps purely in PHP as specified — the plan's bound-PHP-datetime comparisons are robust either way, but the *stored* value should round-trip losslessly for the UI countdown.
- The affected-rows gate must be asserted **inside** the same DB connection the TX used (CI3 `$this->db` is fine; don't read from a second connection that could see a different isolation snapshot).

---

## 6. ROLLOUT / IMPLEMENTATION ORDER (when approved)

1. Schema **A** (canonical `users` DDL alignment) — independent, required for fresh-DB tests.
2. `User_model.php`: rewrite `claim_wage()` per §3.1–3.5 (lock → verify → conditional stamp → gate → credit → commit); add a private `_wage_cycle_id($user_id, $now)` helper (ISO week) and a `_wage_now()`/cutoff helper; keep `check_wage_cooldown()` as the *read-only UI mirror* (document that it is never the authorization point).
3. `Team.php`: POST-method + rate-limit + code→JSON mapping per §3.7; add notification on success (parity with `claim_level1`).
4. View: restore the wage card + 3-state button wiring (`claimWage()` JS already present).
5. Schema **B** (optional): sibling `transaction_id` prefix audit → fix `RENT-` format → purge legacy C6 dupes → add `UNIQUE(transaction_id)`.
6. Run §5 protocol; update `plan/37` status row for C6 and close out with a summary document (house convention: `5x_C6_…_SUMMARY.md`).

---

## 7. ACCEPTANCE CRITERIA (traceable to the task brief)

- [ ] `php -l` passes on every touched PHP file; `Team.php` contains **zero** `$this->db` (encapsulation, AGENTS.md).
- [ ] `claim_wage()` begins with `trans_begin()` + `SELECT … FROM users WHERE id = ? FOR UPDATE` (anchor lock first statement).
- [ ] Interval re-verified **on the locked row** with PHP `Asia/Jakarta` cutoffs; rejection paths roll back cleanly with `already_claimed` / `cycle_not_ready` / `not_qualified`.
- [ ] Timestamp mutation is conditional (`last_wage_claimed_at IS NULL OR < cutoff`) and the ledger credit is gated on `affected_rows() === 1`.
- [ ] `transaction_id` deterministic & monotonic per cycle (`WAGE-{user}-Y{year}W{iso_week}`); ≤ 1 such row per user.
- [ ] Test 1 (eligible claim) passes; Test 2 (5–10 concurrent via `xargs -P`) → exactly one `claimed`, no duplicate ledger rows; Test 3 (immediate replay) → `already_claimed`, zero writes.
- [ ] No `NOW()`/`CURRENT_TIMESTAMP` in the new SQL; all timestamps PHP-bound params (timezone convention).
