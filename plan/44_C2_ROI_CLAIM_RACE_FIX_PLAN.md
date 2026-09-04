# 44 — C2 ROI CLAIM LOST-UPDATE RACE FIX PLAN (Double-Payout — Architectural Blueprint)

**Scope:** Eliminate vulnerability C2 (ROI claim lost-update race / double payout) per `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` §2-C2: serialize concurrent `Rentals::claim()` POSTs, add rental lifecycle guards (`status='active'`, `expired_at`), make ledger transaction IDs deterministic & dedupable, and encapsulate the whole atomic workflow inside `Rental_model` (AGENTS.md: no SQL in controllers).
**Mode:** PLAN MODE — blueprint only. **No application code or database schema modified by this plan's authoring.**
**Status:** ⏸ Pending explicit approval before implementation.
**Related audit:** plan/37 (C1–C7), plan/38 (C1 fix — applied, working tree), plan/42 (C7 fix — applied, working tree). Follows the same one-PR-per-item discipline.

---

## 1. EXECUTIVE SUMMARY (Diagnostic)

| Layer | Current state (verified) | Risk |
|---|---|---|
| `Rentals::claim()` | Reads the rental with a **plain** `get_where` (no `FOR UPDATE`), computes `claimable_days`/`actual_claim_days` from that snapshot, then writes back an **absolute** stale value (`days_processed = snapshot + n`) | Two concurrent POSTs both read `days_processed=0` → both pass the day-diff guard → **two ROI credits** (double payout) |
| Rental lookup | Scoped by `id` + `user_id` ✓ (ownership), but **no `status='active'` guard and no `expired_at > NOW()` guard** | Expired rental (`status` never flipped — audit M3, no cron) is still claimable until `days_processed` reaches `total_days`; a `completed`/`cancelled` row is not even checked |
| Ledger `transaction_id` | `'ROI-'.time().'-'.$rental_id` — **same-second non-unique**; `wallet_ledger` has **no unique index** on `transaction_id` (or composite) | Ledger cannot dedupe a double-insert even structurally; replay in the same second collides by design |
| Architecture (AGENTS.md) | Controller inlines its own SQL TX (`Rentals.php:179-199`); `Rental_model::claim_roi()` exists but is **unused** (dead code) | Violates "all DB access lives in models"; duplicate claimable-day math lives in `index()` + `claim()` (audit P8, drift risk) |
| View | Claim is a `form_open()` **POST** with auto CSRF token (✓); disabled states are cosmetic only | Client-side disable is not a concurrency control — server must be authoritative |

**Exploit (as audited):** two rapid/concurrent POSTs to `/rentals/claim/{id}` (double-click, retry after timeout, or parallel `curl`) both snapshot `days_processed=0`, `last_claimed_at=NULL`, compute `actual_claim_days≥1`, and both execute the unconditional `UPDATE ... SET days_processed = 0+n` + ledger insert → the rental advances only once but the wallet is credited **twice** (2× payout), and two ledger rows with near-identical IDs exist.

**Fix strategy (defense-in-depth, four layers):**
1. **Serialization** — the rental row is read with `SELECT ... FOR UPDATE` inside an explicit model-level TX; the second concurrent request **blocks** on the lock, then re-reads the committed state (`days_processed`/`last_claimed_at` already advanced) → computes 0 claimable → no credit. The write is additionally a guarded **relative** update (`days_processed = days_processed + ?`) gated on `affected_rows() === 1`, so an absolute stale write is structurally impossible.
2. **Lifecycle invariants** — claim requires `status='active'`; an expired rental (`expired_at <= now`) or a fully-processed rental (`days_processed >= total_days`) is atomically flipped `active → completed` (idempotent conditional UPDATE) and rejected with **no credit**.
3. **Deterministic ledger identity** — `transaction_id = 'ROI-{rental_id}-D{days_processed_after}'` (monotonic per rental, one ID per claim sequence) + a composite unique key migration on `wallet_ledger` as structural dedupe (separate commit, pre-dedupe required — same caveats as plan/38 §4E).
4. **Model encapsulation & single math source** — the entire atomic workflow moves into `Rental_model::claim_roi()`; the controller keeps only HTTP validation, auth, rate limit, and result→flash/redirect mapping; one shared claimable-day helper feeds both `index()` (display) and `claim()` (authoritative), eliminating the P8 twin.

---

## 2. VERIFIED CURRENT-STATE FACTS (inspection evidence)

| Fact | Evidence |
|---|---|
| `claim()` reads without lock, then writes **absolute** stale value | `application/controllers/Rentals.php:141` (`get_where`), `:184-187` (`'days_processed' => $rental->days_processed + $actual_claim_days`) |
| No `status`/`expired_at` guard in `claim()` | `Rentals.php:141-176` (fetch, T+1 check, day-diff math only) |
| Non-unique, same-second `transaction_id` | `Rentals.php:193` (`'ROI-'.time().'-'.$rental_id`) |
| Ledger insert and rental update share a TX but nothing serializes the read | `Rentals.php:179-199` (`trans_start` … `trans_complete`) |
| `Rental_model::claim_roi()` unused; controller inlines SQL | `Rental_model.php:42-63`; grep: only self-reference + rate-limit key string; `Rentals.php:179-199` |
| Claimable-day math duplicated (display vs claim) | `Rentals.php:24-33` (`index()`) vs `:154-166` (`claim()`) — identical today (audit P8) |
| View submits POST form w/ CSRF; disabled states cosmetic | `application/views/rentals/index.php:191-205` (`form_open('rentals/claim/'.$rental->id)` → token auto-included); `:138-145` `$is_claimed_today` |
| `wallet_ledger` schema — **no unique key**; `transaction_id VARCHAR(50) NOT NULL`; indexes only `user_id`/`type`/`created_at` | `database.sql:163-176` |
| `user_rentals` schema — `status ENUM('active','completed','cancelled') DEFAULT 'active'`, `expired_at TIMESTAMP NULL`, `days_processed INT UNSIGNED DEFAULT 0`, index `idx_user_status(user_id, status)` | `database.sql:198-215` |
| `expired_at` written as PHP Jakarta string (`now + N days`), never flipped by any cron (M3) | `Rentals.php:93`, `Rental_model.php:16`; grep: no other `expired_at` writer except Admin create |
| Rate limiter on claim: 5 hits / 900 s per user (`claim_roi:{user_id}`), `check()` then `hit()` — anti-spam only, not a money guard | `Rentals.php:127-138`; `Rate_limit_model.php` |
| House concurrency pattern already applied (C1): conditional state UPDATE + `affected_rows() === 1` gates the credit, inside one TX | `Wallet_model.php::approve_deposit_simulator` (working tree) |
| TX discipline precedent (`trans_begin`/`commit`/`rollback`, `FOR UPDATE`, try/catch) | `Ledger_model.php:18-72` |
| PHP 8.3 CLI available; **no MySQL server/client in this environment** | `php -v` → 8.3.6; `which mysql mariadb` → none (same constraint as plan/43 §5) |

---

## 3. ROOT-CAUSE ANALYSIS

`Rentals::claim()` violates three invariants every money-mutating path in this platform must hold:

1. **The decision and the write must be serialized.** The business check (day-diff/T+1/remaining) reads a snapshot; the write assumes that snapshot is still current. Without a row lock (`FOR UPDATE`) or a conditional-write token, two readers can both pass the check and both write — the classic **lost update** (both write `snapshot + n`; the second overwrites the first's increment instead of building on it).
2. **The state machine must be enforced at claim time.** `status` and `expired_at` exist in the schema but `claim()` never consults them. A rental whose contract window has passed but whose `status` is still `'active'` (no cron flips it — audit M3) keeps paying ROI. Because the over-payment cap only checks `total_days - days_processed`, the phantom keeps paying until its *day budget* runs out, and a `completed`/`cancelled` row is never rejected.
3. **A money credit must be idempotent and referable.** The credit's `transaction_id` is a wall-clock timestamp — two identical claims in the same second produce identical IDs, and the table has no uniqueness constraint, so nothing (application or schema) can tell the duplicates apart after the fact.

Additional structural debt: the TX lives in the controller (AGENTS.md violation), and the claimable-day rule is duplicated in `index()` (P8) — any fix must not let the two copies drift further.

---

## 4. ARCHITECTURAL FIX DESIGN

### 4A. Concurrency Mechanism — pessimistic `FOR UPDATE` (primary) + guarded relative write (secondary)

**Mechanism comparison (evaluated per the C2 brief):**

| Criterion | `SELECT ... FOR UPDATE` (pessimistic) | Atomic conditional `UPDATE ... WHERE days_processed = ?` (optimistic) |
|---|---|---|
| Serialization point | Row X-lock acquired at first read inside the TX; contender **blocks**, then reads committed state (`current read`, unaffected by REPEATABLE-READ snapshot) | Row X-lock acquired at the UPDATE; contender's UPDATE matches **0 rows** |
| Variable claim amount `min(day_diff, 2, remaining)` | Computed from the **locked, fresh** row → always correct | Workable only because the amount is a pure function of the snapshot — but the WHERE must re-encode the entire decision (`days_processed = ? AND last_claimed_at < today00 AND status='active' AND expired_at > NOW()`) to be safe; predicate grows brittle (DATE() on indexed column, `AND`-chain must mirror PHP math exactly) |
| Expiry/completed transition (`active→completed` lazy flip) | Natural: flip inside the same TX after locking, re-check | Awkward: needs a second conditional UPDATE; the "flip and reject" path has no clean single-statement form |
| Midnight boundary (claim at 23:59:59 vs 00:00:01) | Locking read at T uses committed `last_claimed_at`; second request recomputes against the fresh row → D+1 claim legitimately allowed, same-day replay impossible | Same end result (write serialized on the row), but the PHP-computed `last_claimed_at < today-00:00` token must be generated at the *write* moment, not the read moment |
| Bookkeeping risk | Requires explicit `trans_begin/commit/rollback` discipline (bug → open TX; PHP request end rolls back) | Single statement, no TX bookkeeping — but the business logic still lives in PHP outside the statement |
| Verdict | **Primary.** One lock covers read→compute→flip→write→credit atomically; the whole state machine runs against a fresh row | Secondary/reinforcement only, not standalone |

**Chosen design — hybrid, in the model:**

```php
// Rental_model.php — target state (post-approval scope)
public function claim_roi($rental_id, $user_id) {
    $this->db->trans_begin();
    try {
        // 1. Serialize: lock the owner's rental row (current read).
        $rental = $this->db->query(
            "SELECT * FROM user_rentals
              WHERE id = ? AND user_id = ?
              FOR UPDATE",
            [$rental_id, $user_id]
        )->row();
        if (!$rental) {
            $this->db->trans_rollback();
            return $this->_result('not_found', 'Data sewa tidak ditemukan.');
        }

        // 2. Lifecycle guards (state machine, §4B) — on the LOCKED row.
        if ($rental->status !== 'active') { /* rollback; reject completed/cancelled */ }
        if ($this->_is_expired($rental)) {  /* lazy flip active→completed (idempotent); rollback; reject */ }
        if ((int)$rental->days_processed >= (int)$rental->total_days) { /* same flip; reject */ }

        // 3. Claimable math — single shared source (§4D), on the LOCKED row.
        $info = $this->claimable_info($rental);          // pure helper
        if ($info['actual_claimable'] < 1) { $this->db->trans_rollback(); return ... 'no_claimable'; }

        // 4. Write: guarded RELATIVE increment; gate credit on affected_rows === 1.
        $new_dp = (int)$rental->days_processed + $info['actual_claimable'];
        $this->db->query(
            "UPDATE user_rentals
                SET days_processed = days_processed + ?,
                    last_claimed_at = ?
              WHERE id = ? AND user_id = ? AND status = 'active'",
            [$info['actual_claimable'], $now, $rental_id, $user_id]
        );
        if ($this->db->affected_rows() !== 1) { rollback; return ...; }

        // 5. Credit ledger — deterministic ID (§4C), same TX.
        $payout = $info['actual_claimable'] * $rental->daily_roi;
        $this->db->insert('wallet_ledger', [
            'user_id'        => $rental->user_id,
            'transaction_id' => 'ROI-' . $rental_id . '-D' . $new_dp,
            'type'           => 'credit',
            'amount'         => $payout,
            'description'    => 'Klaim ROI ' . $info['actual_claimable'] . ' Hari',
        ]);

        $this->db->trans_commit();
        return $this->_result('claimed', $msg, $payout, $info['actual_claimable']);
    } catch (Throwable $e) {
        $this->db->trans_rollback();
        log_message('error', 'Rental_model::claim_roi — ' . $e->getMessage());
        return $this->_result('error', 'Sistem: Gagal memproses klaim.');
    }
}
```

**Concurrency proof (why exactly one credit):** T1 and T2 both reach step 1. T1 takes the row X-lock; T2's `SELECT ... FOR UPDATE` **blocks**. T1 computes from a fresh row, advances `days_processed`, inserts the credit, commits → lock released. T2's locking read now returns the **committed** row (`days_processed` advanced, `last_claimed_at = today`) — InnoDB locking reads bypass the REPEATABLE-READ snapshot ("current read") — so T2's day-diff math yields 0 claimable and it returns `no_claimable` with **no write and no credit**. Even in the pathological case where T2 somehow reached step 4, the WHERE clause + `affected_rows()` gate and the deterministic ID (steps 4–5) still allow only one credit.

### 4B. Rental Lifecycle & Expiry Guards (state machine)

Formal invariants enforced inside the locked TX (step 2 of §4A):

- **I1 — Active-only:** claim proceeds only when `status === 'active'`. `completed`/`cancelled` → reject (`code='not_active'`), no credit.
- **I2 — Expiry boundary:** if `expired_at` is set and `expired_at <= {PHP now}` → run the **idempotent** transition once:
  ```sql
  UPDATE user_rentals SET status = 'completed'
   WHERE id = ? AND status = 'active' AND expired_at <= ?
  ```
  (`affected_rows` 0 or 1 — safe against a concurrent expiry cron, audit M3 follow-up) → rollback the claim TX *after* committing the flip is not possible in one TX, so: perform the flip, then **rollback** (discarding any partial claim work) and return `code='expired'` — the flip itself commits the completion. *(Implementation note: do the flip, then `trans_commit()` if a flip occurred else `trans_rollback()`, returning `expired` in both paths — the flip must persist while the claim must not.)*
- **I3 — Over-payment cap:** `days_processed >= total_days` → same `active→completed` flip → reject (`code='completed'`). The claimable cap `min(day_diff, 2, total_days - days_processed)` is applied against the **locked** row, so the sum of credited days can never exceed `total_days`.
- **I4 — One claim per rental per calendar day:** `last_claimed_at` is advanced only by the winning TX (step 4), and the day-diff math (reference = `last_claimed_at` ?: `created_at`) runs on the locked row → same-day replay yields `actual_claimable = 0`.

Timezone rule (project convention, Phase 20): **all** boundary comparisons use PHP-generated Asia/Jakarta strings as bound params — never MySQL `NOW()`/`CURDATE()` (server tz skew). `$now = date('Y-m-d H:i:s')`; day boundary via `date('Y-m-d')`. This keeps the write/read round-trip self-consistent for `TIMESTAMP` columns and matches how `expired_at` is written at checkout.

### 4C. Deterministic & Unique Ledger Transactions

- **New format:** `ROI-{rental_id}-D{days_processed_after}` — e.g. first claim advancing `0→2` yields `ROI-42-D2`. `days_processed` strictly increases per rental (each successful claim adds ≥ 1), so the ID is **monotonic, deterministic, and unique per claim sequence**; it never collides with legacy IDs (`ROI-{10-digit-ts}-{id}` — the `D` segment cannot parse as a timestamp) so **no historical dedupe is needed to adopt it**.
- **Schema (defense-in-depth, separate commit — same caveats as plan/38 §4E):** add the composite unique key, not a global one (global would break existing flows: `RENT-{product_id}-{YmdHis}` in `checkout()` collides across users, `INV-{YmdHis}-{user_id}` collides on same-user same-second topup — audit P2):
  ```sql
  ALTER TABLE `wallet_ledger`
    ADD UNIQUE KEY `uk_user_tx_type` (`user_id`, `transaction_id`, `type`);
  ```
  Pre-migration: run `SELECT user_id, transaction_id, type, COUNT(*) c FROM wallet_ledger GROUP BY user_id, transaction_id, type HAVING c > 1;` and reconcile/hold duplicates **before** enabling the key (C2's historical double-payouts across *different seconds* have distinct IDs and need business reconciliation against `days_processed` — documented as out of scope here).
- **Layering honesty:** the unique key is not what stops same-day replay (the row lock + state machine do — a replay writes a *different* `-D{seq}` ID). The key exists to make a **regressed duplicate insert of the same claim** fail loudly (TX rollback) instead of double-crediting silently.

### 4D. Model Encapsulation & Single Math Source (AGENTS.md)

- **`Rental_model::claim_roi($rental_id, $user_id)`** — rewritten per §4A–4C: owns the whole TX, returns a structured result `['code' => ..., 'message' => ..., 'amount' => int|null, 'days' => int|null]` with codes `claimed | no_claimable | not_found | not_active | expired | completed | error`. (Current signature `claim_roi($rental_id, $user_id, $roi_amount)` has zero callers — safe to repurpose.)
- **`Rental_model::claimable_info($rental_row)`** — pure helper, the **single** implementation of the T+1 / 2-day-cap / remaining-days math (today's exact logic from `Rentals.php:154-166`), returning `['claimable_days', 'remaining_days', 'actual_claimable', 'is_claimed_today', 'is_expired', 'is_completed']`. Used by `index()` (display augmentation, replaces `Rentals.php:24-33`) and by `claim_roi()` (authoritative) → kills the P8 twin.
- **`Rentals::claim()` (controller)** — keeps only: POST guard, `$rental_id` validation, rate limit, `$this->Rental_model->claim_roi(...)` call, and code→flash/redirect mapping (`claimed` → success flash w/ `Rp` formatted amount; others → the model's Indonesian message). **Zero SQL / zero `$this->db` in the controller.**
- **`Rentals::index()` (controller)** — delegates augmentation to `claimable_info()`; no math inline.

---

## 5. FILE-BY-FILE CHANGE PLAN (implementation scope after approval)

| # | File | Change |
|---|---|---|
| 1 | `application/models/Rental_model.php` | Rewrite `claim_roi($rental_id, $user_id)` → full atomic workflow (§4A–4C): `trans_begin`; `SELECT ... FOR UPDATE` scoped `id`+`user_id`; lifecycle guards + lazy `active→completed` flips (I1–I4); guarded relative `UPDATE` + `affected_rows()===1` gate; deterministic ledger insert; `trans_commit`/`rollback` + try/catch (Ledger_model style). Add pure helper `claimable_info($rental)` (§4D). Return structured result array. |
| 2 | `application/controllers/Rentals.php` | `claim()`: strip inline SQL/TX; call `claim_roi()`; map result code → flash + redirect (keep POST guard, rate limit, messages in Indonesian). `index()`: replace inline math (`:24-33`) with `claimable_info()`. |
| 3 | `application/views/rentals/index.php` | *Optional, non-blocking:* disable the submit button on first click (`this.disabled = true` in `onsubmit`/inline JS) to cut accidental double-submits — **cosmetic only**; the server guard is authoritative. No change to `form_open` (POST + CSRF already correct). |
| 4 | `database.sql` + live DB *(separate migration commit)* | Add `UNIQUE KEY uk_user_tx_type (user_id, transaction_id, type)` on `wallet_ledger` **after** pre-dedupe query passes (§4C). Update `database.sql:163-176` for fresh installs. |
| 5 | *(no change)* | Routes (`rentals/claim/(:num)` already registered); `Ledger_model`/`Wallet_model` untouched (C5/C4 remain separate PRs). |

Out of scope for this patch (tracked in audit): **M3** (expiry/ROI cron — this plan only makes claim-time lazy completion safe); **C5** (checkout/withdraw balance race); **C4/M6** (ledger source-of-truth); C3, C6 remain separate PRs.

---

## 6. VERIFICATION & TESTING PROTOCOL

### 6.1 Static checks
```bash
php -l application/models/Rental_model.php
php -l application/controllers/Rentals.php
php -l application/views/rentals/index.php     # if touched (optional item)
```
Regression grep (no SQL left in controller): `grep -n '\$this->db' application/controllers/Rentals.php` → only expected non-SQL references or none.

### 6.2 Runtime harness
- Live MySQL `db_webtable` (seed `database_seed.sql`); local server `CI_ENV=development php -S localhost:8080` (this execution environment has **no MySQL server/client** — php -l runs locally; runtime tests are prescribed for the live-DB smoke run, same as plans 39/43 §5).
- Session cookie jar per test user; CSRF token extracted from any rendered page (`name="synapse_csrf_token" value="..."`); `csrf_regenerate=FALSE` keeps the token stable across replay POSTs.
- DB assertions via `mysql -e` on `db_webtable`.
- Rate-limit note: claim is throttled 5/900 s per user (`check()` then `hit()`, non-atomic — a burst can push several requests past `check()` before locks land). The money assertion must hold **regardless** of how many requests reach the TX; optionally `DELETE FROM rate_limits WHERE rate_key='claim_roi:{uid}';` between rounds to isolate the concurrency layer.

### 6.3 Test cases (acceptance criteria)

**Test 1 — Single claim credits exactly once**
```bash
# seeded rental R with total_days≥3, created 3+ days ago, days_processed=0, never claimed
curl -s -o /dev/null -w '%{http_code}' -b /tmp/cj -c /tmp/cj -d "synapse_csrf_token=$TOKEN" http://localhost:8080/rentals/claim/$R
```
Assert: HTTP 302 + success flash; `SELECT days_processed, last_claimed_at FROM user_rentals WHERE id=$R` → `2` (cap) and `last_claimed_at = today`; `SELECT COUNT(*), SUM(amount) FROM wallet_ledger WHERE transaction_id='ROI-$R-D2' AND type='credit'` → `1`, amount = `2 × daily_roi`; wallet balance delta = exactly that payout.

**Test 2 — Burst concurrency / lost-update (5–10 parallel POSTs)**
```bash
# fresh rental R2 (created 3+ days ago, dp=0) — one round, N parallel
seq 1 8 | xargs -P 8 -I{} curl -s -o /dev/null -b /tmp/cj -d "synapse_csrf_token=$TOKEN" http://localhost:8080/rentals/claim/$R2
```
Assert: **exactly one** winner — `days_processed` advanced by exactly the claimable amount once (e.g. `2`), `last_claimed_at` set once; `COUNT(*)` of `ROI-$R2-D2` credit rows = `1` (no duplicate ledger rows, no `-D` gap beyond the winning sequence); balance delta = one payout only. Repeat with fresh rental for 10-way burst. (Losers return `no_claimable` flash or are rate-limited — both must produce **zero** writes.)

**Test 3 — Zero claimable / replay**
```bash
# immediately after Test 1 on rental R
curl -s -o /dev/null -w '%{http_code}' -b /tmp/cj -d "synapse_csrf_token=$TOKEN" http://localhost:8080/rentals/claim/$R
```
Assert: HTTP 302, error flash ("sudah mengklaim hari ini"); `days_processed` **unchanged**; `COUNT(*)` of ledger rows for rental R still `1`; balance delta `0`.

**Test 4 — Expired / completed rejection**
```bash
# R4: status='active', expired_at = yesterday, dp < total_days (simulates missing cron — audit M3)
# R5: status='completed' (seeded), dp < total_days
curl ... http://localhost:8080/rentals/claim/$R4 ; curl ... http://localhost:8080/rentals/claim/$R5
```
Assert: both HTTP 302 with rejection flash; **no ledger row** for either; `R4.status` now `'completed'` (lazy flip persisted, I2/I3) and a **second** POST to R4 → rejected as `not_active`; `R5.status` unchanged; balance delta `0` overall.

**Regression sweep:** re-run `php -l` on all touched files; `/rentals` page renders HTTP 200 with correct per-card button state (claimed-today disabled / claimable amount matches `claimable_info()`); `Rentals::index` math identical to claim math for a sample of rental states (P8); re-run the audit checklist items for C2 only.

---

## 7. DECISION RECORD

1. **Pessimistic vs optimistic:** `SELECT ... FOR UPDATE` chosen as the serialization mechanism (§4A table). The optimistic single-statement form is elegant but must re-encode the whole claim decision (2-day cap + T+1 + expiry + remaining) as WHERE predicates and still cannot express the lazy `active→completed` flip cleanly. The guarded **relative** UPDATE + `affected_rows()` gate is retained as a second layer because it costs one WHERE clause and makes an absolute stale write structurally impossible.
2. **Deterministic ID:** `ROI-{rental_id}-D{days_processed_after}` (monotonic, collision-free vs legacy, no historical migration needed) over microsecond-entropy IDs (non-deterministic, defeats audit/replay reasoning).
3. **Unique key scope & timing:** composite `(user_id, transaction_id, type)`, separate migration commit after pre-dedupe — global `transaction_id` would break existing `RENT-`/`INV-` flows (collision classes documented in §4C). Not required for the fix's correctness; it is structural insurance against regression.
4. **Lazy completion in claim TX:** flipping `active→completed` at claim time (I2/I3) is safe under the row lock and makes the fix independent of the missing M3 cron; the cron (auto-expiry/auto-ROI per PRD) stays a separate roadmap item.
5. **Result contract:** structured `code` array instead of bare booleans so the controller can render precise Indonesian flash messages without re-deriving business state.

## 8. ROLLOUT NOTES
- Branch per `docs/3_ROADMAP.md` phase-branch convention; commit message in Indonesian, e.g. `fix(c2): klaim ROI aman konkurensi — FOR UPDATE + guard status/expired + ID ledger deterministik + pindah SQL ke model`.
- This plan is **PLAN MODE only** — no application code or schema has been modified. Implementation starts only after explicit approval.
- Runtime tests (Test 1–4) require a live MySQL `db_webtable`; the local execution environment has PHP 8.3 CLI (php -l) but no MySQL server/client — the live-DB smoke run is the recommended next step after implementation, per the established plan/39 & 43 workflow.
