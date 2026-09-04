# 42 — C7 WITHDRAWAL SIMULATOR SELF-APPROVAL FIX PLAN (Architectural Blueprint)

**Scope:** Eliminate vulnerability C7 (User self-approval of withdrawals) per `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` §2-C7.
**Mode:** PLAN MODE — blueprint only. **No application code or configuration has been modified by this plan's authoring.**
**Status:** ⏸ Pending explicit approval before implementation.
**Related audit:** plan/37 (C1–C7 criticals), plan/38 (C1 deposit-simulator fix — same 4-layer pattern), Phase 10B (POST-only hardening), Phase 10D (fail-closed ENVIRONMENT).

---

## 1. EXECUTIVE SUMMARY (Diagnostic)

| Layer | Current state (verified) | Risk |
|---|---|---|
| `Wallet::simulate_wd_approve()` | Any logged-in user can call it (GET **or** POST — no method guard); no ENVIRONMENT gate; **no ownership check at all** (the WD row is never fetched); calls the model unconditionally | Authenticated user self-approves own pending payout, bypassing admin review (PRD violation); can also flip **other users'** WDs via guessable `WD-{YmdHis}-{userId}` numbers (unauthorized state mutation) |
| `Wallet_model::approve_withdrawal_simulator()` | `UPDATE withdrawals SET status='success' WHERE wd_number=?` — **no `status='pending'` guard, no `user_id` constraint**; no `affected_rows()` gate; returns `trans_status()` (TRUE even when 0 rows matched) | Replay on an already-processed WD returns "success" semantics; cross-user update by number enumeration |
| Route `wallet/simulate_wd_approve/(:any)` | **NOT registered** in `routes.php` (audit §2-C7 claim inaccurate — verified current file). Still reachable via CI3 default `controller/method/param` routing | Endpoint is live today regardless of route table; explicit route entry is documentation, not a control |
| `views/wallet/index.php`, `views/wallet/withdraw.php` | **No WD-simulator trigger exists in any view** (verified via grep — only the deposit simulator button exists, already wrapped in `ENVIRONMENT !== 'production'`) | Exploit is URL-only today; view guard is defense-in-depth for any future dev UI |
| Ledger impact | The WD debit is inserted **at request time** (`create_withdrawal`) — funds are already locked. Flipping to `success` does not add/remove ledger rows; it only approves the payout | No double-spend from this bug (unlike C1), but the authorization bypass + cross-user mutation are the critical harms |

**Exploit (as audited):** user registers → submits a withdrawal (`WD-20260902-101500-{uid}`) → instead of waiting for admin, calls `GET /wallet/simulate_wd_approve/WD-{own}` → their payout is marked `success` with zero admin review. Because the number embeds the owner id and timestamp, user A can also attempt to flip user B's WD by guessing the number — the model happily matches on `wd_number` alone.

**Fix strategy (fail-closed, defense-in-depth, four layers — mirrors approved C1 fix in plan 38):**
1. **Environment lockdown** — `show_404()` at the very top of `simulate_wd_approve()` when `ENVIRONMENT === 'production'`; any dev-only UI trigger wrapped in `<?php if (ENVIRONMENT !== 'production'): ?>`.
2. **Atomic state guard & idempotency** — single conditional `UPDATE withdrawals SET status='success' WHERE wd_number=? AND status='pending' AND user_id=?`; success returned **only** when `affected_rows() === 1`, inside one TX.
3. **Ownership & session verification** — controller pre-check compares `withdrawals.user_id` vs `$this->session->userdata('user_id')` (403 on mismatch) **and** the model's UPDATE embeds `user_id` (authoritative atomic enforcement).
4. **HTTP method & CSRF** — POST-only (`show_404()` on any non-POST); CSRF enforced by the existing global `csrf_protection=TRUE`; dev form uses `form_open()` so the token is emitted automatically.

---

## 2. VERIFIED CURRENT-STATE FACTS (inspection evidence)

| Fact | Evidence |
|---|---|
| `ENVIRONMENT` resolves fail-closed to `'production'` when `CI_ENV` unset | `index.php:67-73` (`$ci_env !== '' ? $ci_env : 'production'`) |
| `simulate_wd_approve()` has no ENVIRONMENT / method / ownership guard — calls model then redirects | `application/controllers/Wallet.php:210-220` |
| Model updates by `wd_number` only — no `status='pending'`, no `user_id`; no `affected_rows()` gate; returns bare `trans_status()` | `application/models/Wallet_model.php:140-150` |
| Route `wallet/simulate_wd_approve/(:any)` is **absent** from the route table (audit report inaccuracy); default CI3 routing still exposes the method | `application/config/routes.php` (whole file scanned, lines 1-40) |
| No WD-simulator trigger in any view | `grep -rn "simulate_wd_approve\|simulate_wd" application/views/` → empty; `wallet/index.php` contains only the deposit simulator button (already `ENVIRONMENT !== 'production'`-guarded, lines 105-113); `wallet/withdraw.php` has none |
| CSRF global enabled; token `synapse_csrf_token`, `csrf_regenerate=FALSE` | `application/config/config.php` (Phase 10C; stable token across replay → replay test valid) |
| `withdrawals.wd_number` has `UNIQUE KEY uk_wd_number`; `status ENUM('pending','processing','success','failed')` | `database_seed.sql:26-30` (ALTER + unique key), `database.sql:110-125` |
| The WD debit (`wallet_ledger`, type `debit`, transaction_id = wd_number) is inserted at request time in `create_withdrawal` | `application/models/Wallet_model.php:96-103` |
| Legitimate approval path exists: `Admin::approve_withdrawal()` flips `pending→success` with audit log inside a TX | `application/controllers/Admin.php:143-183` |
| Existing codebase patterns to mirror | C1 fix (plan 38): production gate + POST-only + ownership pre-check + conditional UPDATE + `affected_rows() === 1` in `Wallet.php`/`Wallet_model.php`; `$this->input->method() !== 'post'` → `show_404()` |

**Schema caveat (out of scope, tracked as C3):** canonical `database.sql` declares `withdrawals.gross_amount/fee_amount/net_amount NOT NULL` while the seed adds `wd_number`/`amount`; `create_withdrawal()` inserts only `wd_number`/`amount`/`bank_account_id`. C7's fix does not touch the schema; the simulator only reads/writes `wd_number`, `user_id`, `status` — all present in the live schema.

---

## 3. ROOT-CAUSE ANALYSIS

`approve_withdrawal_simulator()` violates the same three invariants plan 38 identified for C1, plus one C7-specific one:

1. **State transition must be conditional.** A withdrawal may only move `pending → success` once, and only by an authorized actor (admin — or the dev simulator). Current code: `WHERE wd_number = ?` only → replayable and status-blind (`success → success` re-reports success).
2. **The transition must be scoped to the authenticated owner.** Current code never references `user_id`. `WD-{YmdHis}-{userId}` is time+id guessable → cross-user state mutation.
3. **The caller must be authorized.** The endpoint is a dev/UAT convenience that bypasses `Admin::approve_withdrawal()`; it must be provably inert outside non-production and impossible to invoke via GET/CSRF in any environment.
4. **The return value must reflect the mutation, not the TX.** `trans_status()` is TRUE for a committed TX that matched 0 rows; the controller flashes "Simulasi: Penarikan berhasil disetujui" even when nothing changed — masking idempotency and poisoning diagnostics.

Note the money-flow difference vs C1: no credit/debit is inserted by this simulator (the debit was locked at request time), so the primary harms are **authorization bypass (self-approval)** and **cross-user mutation**, not double-credit. The same 4-layer defense nevertheless applies.

---

## 4. ARCHITECTURAL FIX DESIGN

### 4A. Environment Lockdown (Production Hard-Gate) — fail-closed

**Controller — `application/controllers/Wallet.php`, `simulate_wd_approve()`:** first statement, before any DB/ledger/session work:
```php
public function simulate_wd_approve($wd_number) {
    // C7 (plan 42) 4A: production hard-gate — fail-closed. Di production
    // endpoint ini TIDAK ADA (404) — tidak pernah memproses apapun.
    if (ENVIRONMENT === 'production') {
        show_404();
        return;
    }

    // C7 4D: POST-only — GET mutation dihapus (policy 10B).
    if ($this->input->method() !== 'post') {
        show_404();
        return;
    }

    $user_id = $this->session->userdata('user_id');

    // C7 4C: ownership pre-check (fail-fast 403) — lihat §4C.
    // ...

    $result = $this->Wallet_model->approve_withdrawal_simulator($wd_number, $user_id);
    // flash success / "sudah diproses" + redirect('wallet') — lihat §4C.
}
```
Rationale: identical fail-closed shape to the approved C1 gate. `show_404()` before anything else — the URL effectively does not exist in production.

**View — `application/views/wallet/index.php` (defense-in-depth):** any dev-only WD-simulator control must be wrapped:
```php
<?php if (ENVIRONMENT !== 'production'): ?>
    <?= form_open('wallet/simulate_wd_approve/' . $wd->wd_number, 'class="mt-2"'); ?>
        <button type="submit" class="w-full ...">Simulasi Persetujuan (Dev Only)</button>
    <?= form_close(); ?>
<?php endif; ?>
```
> **Note (verified):** no WD-simulator button exists in any view today. Implementer applies **Option B unless told otherwise** (see §7 Decision Record):
> - **Option A:** leave both views untouched — the endpoint is already inert in production via §4A (view guard is then purely a documented convention).
> - **Option B (recommended, parity with plan 38):** restore a dev-only "Simulasi Persetujuan (Dev Only)" POST button inside the `ENVIRONMENT !== 'production'` guard on the pending-withdrawal card (`wallet/index.php`, lines 125-143), so the UAT loop (withdraw → simulate approve → status check) remains usable in `development` and provably cannot render in production.

### 4B. Atomic State Guard & Idempotency — model

**Model — `application/models/Wallet_model.php`, rewrite `approve_withdrawal_simulator($wd_number, $user_id)`:**
```php
public function approve_withdrawal_simulator($wd_number, $user_id) {
    $this->db->trans_start();

    // C7 4B: transisi atomik bersyarat — hanya menang jika WD masih
    // 'pending' DAN milik user session. affected_rows() === 1 adalah
    // satu-satunya gerbang sukses; replay/duplicate → 0 baris → false.
    $this->db->where('wd_number', $wd_number);
    $this->db->where('status', 'pending');
    $this->db->where('user_id', $user_id);
    $this->db->update('withdrawals', ['status' => 'success']);

    $affected = $this->db->affected_rows();

    $this->db->trans_complete();
    return $this->db->trans_status() && $affected === 1;
}
```
Concurrency proof: identical to C1 — the InnoDB row lock taken by the conditional UPDATE serializes concurrent callers; exactly one observes `affected_rows() === 1` (the row is `pending` only once); losers observe 0 and get `false`. Replay on an already-`success` WD matches 0 rows → `false` → "sudah diproses" flash. The status flip is the entire money-relevant mutation (debit already booked at request time), so no ledger insert is needed here — do **not** add one.

Optional (decision record §7): also set `processed_at = NOW()` in the same UPDATE for parity with the schema's `processed_at` column — cosmetic, harmless, not required by the fix.

### 4C. Ownership & Session Verification

Two enforcement points (belt + braces — the model's WHERE is the authoritative atomic one; the controller check supplies the required clean 403 semantics):

**Controller pre-check** (before calling the model):
```php
$wd = $this->Wallet_model->get_withdrawal_by_wd_number($wd_number); // SELECT id, user_id, status FROM withdrawals WHERE wd_number = ?
if (!$wd) {
    $this->session->set_flashdata('error', 'Penarikan tidak ditemukan.');
    redirect('wallet');
    return;
}
if ((int)$wd->user_id !== (int)$user_id) {
    log_message('error', 'C7 ownership violation: user ' . $user_id
        . ' attempted simulate_wd_approve on ' . $wd_number
        . ' owned by user ' . $wd->user_id);
    show_error('Akses ditolak: penarikan milik pengguna lain.', 403);
    return;
}
$result = $this->Wallet_model->approve_withdrawal_simulator($wd_number, $user_id);
```
**Model** additionally embeds `user_id` in the conditional UPDATE (§4B), so even a TOCTOU between the pre-check and the UPDATE cannot flip a foreign WD (0 rows affected → false). The pre-read is a UX/status-code optimization, not a security dependency. New model helper `get_withdrawal_by_wd_number($wd_number)` keeps all DB access in the model (AGENTS.md rule — no SQL in controllers).

### 4D. HTTP Method & CSRF Hardening

- **POST-only:** controller `show_404()` for any non-POST (mirrors the C1 fix and the `Admin` pattern). Any pre-existing GET bookmark/CSRF-GET vector is eliminated.
- **CSRF:** global `csrf_protection = TRUE` already requires a valid `synapse_csrf_token` on every POST; the dev form uses `form_open()` which emits the hidden token automatically. No config change needed. (If a JS/`csrfFetch` path is ever added, it must carry the same token — Team-view pattern.)
- The WD number travels as the URL segment (`wallet/simulate_wd_approve/{wd_number}`); acceptable for a dev-only, CSRF-protected POST. `MY_Controller` already guarantees an authenticated session (redirects to `login` otherwise) — ownership beyond that is enforced in §4C.

### 4E. Route table — documentation only (no control)

`routes.php` has no `wallet/simulate_wd_approve` entry today; CI3 default routing still resolves the method. The production gate in §4A is the authoritative control. **Optional (recommended):** add an explicit route with a comment for parity with `simulate_payment` and to make the endpoint's existence/intent greppable:
```php
// C7 (plan 42): dev/UAT-only WD simulator — production-inert (gate in controller).
$route['wallet/simulate_wd_approve/(:any)'] = 'wallet/simulate_wd_approve/$1';
```
Adding it changes no behavior (default routing already matched); omitting it changes no security (the gate does the work). Decision recorded in §7.

---

## 5. FILE-BY-FILE CHANGE PLAN (implementation scope after approval)

| # | File | Change |
|---|---|---|
| 1 | `application/controllers/Wallet.php` | `simulate_wd_approve()`: production gate (`show_404`), POST-only guard (`show_404`), ownership pre-check via `get_withdrawal_by_wd_number()` (403 on cross-user, flash+redirect on missing), pass `$user_id` to the model. Keep flash messaging (success / "sudah diproses atau tidak valid"). |
| 2 | `application/models/Wallet_model.php` | Add `get_withdrawal_by_wd_number($wd_number)` helper (SELECT `id, user_id, status`); rewrite `approve_withdrawal_simulator($wd_number, $user_id)` per §4B (conditional `UPDATE ... WHERE wd_number=? AND status='pending' AND user_id=?` + `affected_rows() === 1` gate, inside the TX). |
| 3 | `application/views/wallet/index.php` | Option B: dev-only "Simulasi Persetujuan (Dev Only)" POST button on the pending-withdrawal card, wrapped in `<?php if (ENVIRONMENT !== 'production'): ?>`, via `form_open('wallet/simulate_wd_approve/' . $wd->wd_number)`. Option A: no change (guard documented only). |
| 4 | `application/config/routes.php` | Optional: explicit route entry + `// C7 (plan 42)` comment (§4E). No functional requirement. |
| 5 | `database.sql` / `database_seed.sql` | **No change** (C7 needs no schema work; C3 schema drift tracked separately). |

Out of scope for this patch (tracked in audit P0): **C2** (ROI race), **C3** (withdrawal schema/CSV), **C5** (balance-check race), **C6** (wage TOCTOU) remain separate PRs; **C4/M6** double-entry ledger is P1.

---

## 6. VERIFICATION & TESTING PROTOCOL

### 6.1 Static checks
```bash
php -l application/controllers/Wallet.php
php -l application/models/Wallet_model.php
php -l application/views/wallet/index.php      # if touched (Option B)
php -l application/config/routes.php           # if touched (optional §4E)
```

### 6.2 Runtime harness
- MySQL `db_webtable` seeded; local servers:
  - Dev: `CI_ENV=development php -S localhost:8080` (tests 1–3)
  - Prod-sim: `CI_ENV=production php -S localhost:8081` (test 4)
- Session cookie jar per test user; CSRF token extracted from any page (`name="synapse_csrf_token" value="..."`); `csrf_regenerate=FALSE` keeps the token stable across replay POSTs.
- DB assertions via `mysql -e` on `db_webtable`.
- Test data: create the pending WD by seeding directly (simplest, focused on the simulator):
```sql
-- user 1 owns WD-...; user 2 is a different account
INSERT INTO withdrawals (user_id, bank_account_id, wd_number, amount, gross_amount, fee_amount, net_amount, status)
VALUES (1, 1, 'WD-20260902-101500-1', 100000, 100000, 0, 100000, 'pending');
```
  (adjust to the live column set; `gross/fee/net` only if the live schema requires them — C3 caveat §2.)

### 6.3 Test cases (acceptance criteria)

**Test 1 — Valid single withdrawal approval simulation succeeds (dev)**
```bash
curl -s -o /dev/null -w '%{http_code}' -b /tmp/cj -d "synapse_csrf_token=$TOKEN" http://localhost:8080/wallet/simulate_wd_approve/WD-20260902-101500-1
```
Assert: HTTP `302` (redirect to wallet, success flash); `SELECT status FROM withdrawals WHERE wd_number='WD-...'` → `success`; exactly **one** status change (ledger untouched — still one `debit` row from `create_withdrawal`).

**Test 2 — Duplicate / replay POST fails (affected rows = 0)**
```bash
curl -s -o /dev/null -w '%{http_code}' -b /tmp/cj -d "synapse_csrf_token=$TOKEN" http://localhost:8080/wallet/simulate_wd_approve/WD-20260902-101500-1
```
Assert: HTTP `302` with the "sudah diproses / tidak valid" error flash; `status` remains `success`; no ledger delta. Also run a 5× concurrent burst (`xargs -P 5`): exactly one transition, all losers report failure.

**Test 3 — Cross-user WD tampering → 403**
```bash
# user 2 session (cookie jar /tmp/cj2), logged in as user 2
curl -s -o /dev/null -w '%{http_code}' -b /tmp/cj2 -d "synapse_csrf_token=$TOKEN2" http://localhost:8080/wallet/simulate_wd_approve/WD-20260902-101500-1
```
Assert: HTTP `403`; `status` unchanged; `error` log line `C7 ownership violation` written. Also assert GET (no POST body) → HTTP `404`.

**Test 4 — Production endpoint → 404 (fail-closed)**
```bash
curl -s -o /dev/null -w '%{http_code}' http://localhost:8081/wallet/simulate_wd_approve/WD-20260902-101500-1            # GET
curl -s -o /dev/null -w '%{http_code}' -d "synapse_csrf_token=$TOKEN" http://localhost:8081/wallet/simulate_wd_approve/WD-20260902-101500-1   # POST
```
Assert: HTTP `404` in both cases; `status` remains `pending`; no flash, no redirect to wallet.

**Regression sweep:** re-run `php -l` on all touched files; wallet page loads HTTP 200 in dev (guarded button visible under Option B) and in prod-sim the button is absent + endpoint 404; re-run the C7 row of the audit checklist. Confirm the real admin approval path (`Admin::approve_withdrawal`) still works untouched.

---

## 7. DECISION RECORD

1. **Keep or remove the endpoint?** Keep as a dev-only simulator, fully gated (§4A–4D) — the UAT withdrawal flow needs a way to approve without an admin login, and the 4-layer defense makes it provably inert in production. (Removing it entirely would also be safe; the C1 precedent chose keep-and-gate, and this plan follows it.)
2. **View button (Option A vs B):** No WD-simulator button exists today. **Recommendation: Option B** — restore a dev-only POST button inside the `ENVIRONMENT !== 'production'` guard on the pending-withdrawal card (UAT parity with the deposit simulator; provably inert in production). Option A (no button) is equally safe — endpoint behavior identical.
3. **Route registration:** **Recommendation: add** the explicit `$route['wallet/simulate_wd_approve/(:any)']` entry with a `// C7 (plan 42)` comment for greppability/parity with `simulate_payment`. No behavioral or security impact either way (default routing already matched; the gate is authoritative).
4. **`processed_at`:** set `processed_at = NOW()` in the same UPDATE (optional, cosmetic). Default: include — it matches the schema column and costs nothing. Skippable if the implementer wants the smallest diff.
5. **Response codes:** cross-user → `show_error(..., 403)` per the acceptance criterion (reveals WD-number existence to an authenticated user; acceptable for a dev-only endpoint, mitigated by the production 404 gate). Non-POST / production → `show_404()` (endpoint "does not exist").

## 8. ROLLOUT NOTES
- Branch per `docs/3_ROADMAP.md` phase-branch convention; commit message in Indonesian, e.g. `fix(c7): kunci simulasi approve penarikan — gate produksi, POST+CSRF, kepemilikan, guard atomik`.
- This plan is **PLAN MODE only** — no application code or configuration has been modified. Implementation starts only after explicit approval.
