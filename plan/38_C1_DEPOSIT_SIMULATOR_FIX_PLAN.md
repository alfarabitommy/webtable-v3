# 38 — C1 DEPOSIT SIMULATOR FIX PLAN (Money Printer — Architectural Blueprint)

**Scope:** Eliminate vulnerability C1 (Deposit simulator double-credit / money printer) per `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` §2-C1.
**Mode:** PLAN MODE — blueprint only. **No application code or configuration modified by this plan's authoring.**
**Status:** ⏸ Pending explicit approval before implementation.
**Related audit:** plan/37 (C1–C7 criticals), Phase 10B (POST-only hardening), Phase 10D (fail-closed ENVIRONMENT).

---

## 1. EXECUTIVE SUMMARY (Diagnostic)

| Layer | Current state (verified) | Risk |
|---|---|---|
| `Wallet::simulate_payment()` | Logged-in **GET** route; no ENVIRONMENT gate; no ownership check; calls model unconditionally | URL directly reachable by any authenticated user |
| `Wallet_model::approve_deposit_simulator()` | `UPDATE deposits SET status='success'` **without** `WHERE status='pending'`; **unconditionally** inserts `wallet_ledger` credit; no `user_id` constraint | Double call / concurrent call → double credit; cross-user credit |
| Route `wallet/simulate_payment/(:any)` | Registered; serves any invoice string | Guessable invoice (`INV-YmdHis-userId`) amplifies exposure |
| `views/wallet/index.php` | **No simulator button currently exists** in the view (verified via grep of all views) | The exploit is URL-only today; view guard is defense-in-depth for any future dev UI |

**Exploit (as audited):** register → `topup` 100.000 → hit `GET /wallet/simulate_payment/INV-20260902-101500-{uid}` twice → the model flips `status='success'` twice (no pending guard) and inserts **two** `wallet_ledger` credits → balance credited Rp 200.000 (money printer).

**Fix strategy (fail-closed, defense-in-depth, four layers):**
1. **Environment lockdown** — `show_404()` the method in production; never render a simulator control outside non-production.
2. **Atomic state guard & idempotency** — single conditional `UPDATE ... WHERE invoice_number=? AND status='pending'`; credit inserted **only** when `affected_rows() === 1`, inside the same TX.
3. **Ownership & session verification** — `deposits.user_id` constrained to the session user, enforced both in the controller (fail-fast 403) and atomically inside the model's UPDATE.
4. **HTTP method & CSRF** — POST-only (`show_404()` on GET); CSRF token required (global `csrf_protection=TRUE` already enforced by CI3 on any POST).

---

## 2. VERIFIED CURRENT-STATE FACTS (inspection evidence)

| Fact | Evidence |
|---|---|
| `ENVIRONMENT` resolves fail-closed to `'production'` when `CI_ENV` unset | `index.php:67-73` (`$ci_env !== '' ? $ci_env : 'production'`) |
| `simulate_payment()` has no POST/ENVIRONMENT/ownership guard | `application/controllers/Wallet.php:47-57` |
| Model updates without `status='pending'` guard, credits unconditionally, no `user_id` | `application/models/Wallet_model.php:45-67` |
| Route `wallet/simulate_payment/(:any)` → `wallet/simulate_payment/$1` | `application/config/routes.php:13` |
| No simulator button in any view today | `grep -rn "simulate\|Simulasi\|simulasi" application/views/` → empty |
| CSRF global enabled, token `synapse_csrf_token`, `csrf_regenerate=FALSE` | `application/config/config.php:460-464` (token stable across replay → replay test valid) |
| `deposits.invoice_number` has `UNIQUE KEY uk_invoice_number`; index `(user_id, status)` | `database.sql:181-193` |
| `wallet_ledger.transaction_id` has **no unique index** | `database.sql:163-176` → idempotency must come from the conditional UPDATE, not the schema |
| Existing codebase patterns to mirror | `Rentals::claim` POST-only guard (`Rentals.php:115`); `Admin` `$this->input->method() !== 'post'` → `show_404()`; `User_model.php:256` `affected_rows() === 0` check; `Ledger_model` TX discipline |

---

## 3. ROOT-CAUSE ANALYSIS

`approve_deposit_simulator()` violates the three invariants that every money-mutating path in this platform must hold:

1. **State transition must be conditional.** A deposit may only move `pending → success` exactly once. Current code: `WHERE invoice_number = ?` only → repeatable.
2. **The ledger credit must be gated by the transition actually happening.** Current code inserts the credit after the UPDATE regardless of how many rows it touched (and even re-reads the row afterward — a second caller re-reads `status='success'` and inserts again).
3. **The transition must be scoped to the authenticated owner.** Current code never references `user_id`, so invoice enumeration (`INV-{YmdHis}-{userId}` is time+id guessable) lets user A credit user B's invoice.

Additionally the controller surface is wrong: a state-mutating action reachable via **GET** without a **CSRF token** contradicts the project's own Phase 10B POST-only policy and enables CSRF-driven double-credit from an external page (the victim's session is used; same-origin CSRF cookie token does not protect cross-site GET).

---

## 4. ARCHITECTURAL FIX DESIGN

### 4A. Environment Lockdown (Production Hard-Gate) — fail-closed

**Controller — `application/controllers/Wallet.php`, `simulate_payment()`:**
```php
public function simulate_payment($invoice_number) {
    // C1 (plan 38) 4A: production hard-gate — fail-closed. Di production
    // endpoint ini TIDAK ADA (404) — tidak pernah memproses apapun.
    if (ENVIRONMENT === 'production') {
        show_404();
        return;
    }

    // C1 4D: POST-only — GET mutation dihapus (policy 10B).
    if (!$this->input->post()) {
        show_404();
        return;
    }
    // ... (ownership pre-check §4C, then model call §4B)
}
```
Rationale: `show_404()` before any DB/ledger logic — the URL effectively does not exist in production (fail-closed, no fall-through). The route registration may remain; the gate makes it inert.

**View — `application/views/wallet/index.php` (defense-in-depth for any dev UI):**
```php
<?php if (ENVIRONMENT !== 'production'): ?>
    <!-- Dev-only simulator (UAT). Never rendered in production. -->
    <form method="post" action="<?= base_url('wallet/simulate_payment/' . $row->invoice_number) ?>">
        <?= form_open('wallet/simulate_payment/' . $row->invoice_number) /* or manual csrf hidden */ ?>
        <button type="submit" class="...">Simulasi Pembayaran (Dev Only)</button>
        <?= form_close() ?>
    </form>
<?php endif; ?>
```
> **Note (verified):** no simulator button exists in the current view. Two options — the implementer will apply **Option B unless told otherwise** (see §7 Decision Record):
> - **Option A:** leave the view untouched (endpoint already inert in production via 4A); guard block is a documented convention for future dev UIs.
> - **Option B (recommended):** restore a small dev-only simulator button **inside** the `ENVIRONMENT !== 'production'` guard on the pending-deposit card so the UAT loop (topup → simulate → ledger check) remains usable in `development`, and it provably cannot render in production.
> Either way the endpoint behavior is identical (POST + CSRF + ownership + atomic).

### 4B. Atomic State Guard & Idempotency — model

**Model — `application/models/Wallet_model.php`, `approve_deposit_simulator($invoice_number, $user_id)`:**
```php
public function approve_deposit_simulator($invoice_number, $user_id) {
    $this->db->trans_start();

    // C1 4B: atomic conditional transition — hanya menang jika invoice
    // masih 'pending' DAN milik user session. Satu baris = satu kali sukses.
    $this->db->where('invoice_number', $invoice_number);
    $this->db->where('status', 'pending');
    $this->db->where('user_id', $user_id);
    $this->db->update('deposits', ['status' => 'success']);

    $affected = $this->db->affected_rows();
    if ($affected === 1) {
        // Transisi terjadi barusan (owner, pending->success): insert credit.
        $deposit = $this->db->get_where('deposits',
            ['invoice_number' => $invoice_number])->row();
        if ($deposit) {
            $this->db->insert('wallet_ledger', [
                'user_id'        => $deposit->user_id,
                'transaction_id' => $invoice_number,
                'amount'         => $deposit->amount,
                'type'           => 'credit',
                'description'    => 'Top Up via ' . $invoice_number,
            ]);
        }
    }

    $this->db->trans_complete();
    return $this->db->trans_status() && $affected === 1;
}
```
Concurrency proof: InnoDB row lock on the matched `deposits` row makes the conditional UPDATE serializable. Exactly one of N concurrent callers observes `affected_rows() === 1` (the row is `pending` only once); losers observe 0 and insert **no** credit. Replay on an already-`success` invoice matches 0 rows → 0 credit → returns `false`. The credit and the state flip share one TX (`trans_start/trans_complete`), so a failed credit insert rolls back the status flip (and vice versa).

### 4C. Ownership & Session Verification

Two enforcement points (belt + braces; the model's WHERE is the authoritative atomic one, the controller check gives the required clean 403 semantics):

**Controller pre-check** (before calling the model):
```php
$user_id = $this->session->userdata('user_id');
$deposit = $this->Wallet_model->get_deposit_by_invoice($invoice_number); // SELECT id, user_id FROM deposits WHERE invoice_number = ?
if (!$deposit) {
    $this->session->set_flashdata('error', 'Invoice tidak ditemukan.');
    redirect('wallet');
    return;
}
if ((int)$deposit->user_id !== (int)$user_id) {
    log_message('error', 'C1 ownership violation: user ' . $user_id
        . ' attempted simulate on invoice ' . $invoice_number
        . ' owned by user ' . $deposit->user_id);
    show_error('Akses ditolak: invoice milik pengguna lain.', 403);
    return;
}
$result = $this->Wallet_model->approve_deposit_simulator($invoice_number, $user_id);
```
**Model** additionally embeds `user_id` in the conditional UPDATE (§4B) — so even a TOCTOU between the controller pre-check and the UPDATE cannot credit a foreign invoice (0 rows affected → no credit). The pre-read is a UX/status-code optimization, not a security dependency.

### 4D. HTTP Method & CSRF Hardening

- **POST-only:** controller `show_404()` for non-POST (mirrors `Admin` pattern; stronger than redirect). Any pre-existing GET bookmark/CSRF vector is eliminated.
- **CSRF:** global `csrf_protection = TRUE` already forces a valid `synapse_csrf_token` on every POST; the dev form uses `form_open()` which emits the hidden token automatically. No config change needed. (If a JS/`csrfFetch` path is ever added, it must use the same token — same pattern as the Team view.)
- The invoice travels as the URL segment (`wallet/simulate_payment/{invoice}`); acceptable for a dev-only, CSRF-protected POST. Alternative (post body field) noted but not required.

### 4E. Optional defense-in-depth (recommended follow-up, not part of core patch)

Add a unique index to make double-credit structurally impossible even if a future code path regresses:
```sql
ALTER TABLE `wallet_ledger`
  ADD UNIQUE KEY `uk_user_tx_type` (`user_id`, `transaction_id`, `type`);
```
**Caveat:** dedupe any pre-existing duplicate rows first; `transaction_id` is also used by WD debits (WD-number) — `(user_id, transaction_id, type)` is distinct per flow. Keep this as a separate migration/commit (one-PR-per-item discipline, §6).

---

## 5. FILE-BY-FILE CHANGE PLAN (implementation scope after approval)

| # | File | Change |
|---|---|---|
| 1 | `application/controllers/Wallet.php` | `simulate_payment()`: add production gate (`show_404`), POST-only guard (`show_404`), ownership pre-check (403 on cross-user, flash+redirect on missing), pass `$user_id` to model. Keep flash messaging (success / "Invoice sudah diproses" error). |
| 2 | `application/models/Wallet_model.php` | Add `get_deposit_by_invoice($invoice_number)` helper; rewrite `approve_deposit_simulator($invoice_number, $user_id)` per §4B (conditional UPDATE + `affected_rows() === 1` gate on the credit, inside the TX). |
| 3 | `application/views/wallet/index.php` | Wrap the pending-deposit simulator control in `<?php if (ENVIRONMENT !== 'production'): ?>`; render it as a POST form via `form_open('wallet/simulate_payment/' . $row->invoice_number)` (CSRF token auto-included). Option B: restore dev-only button; Option A: no button, guard documented. |
| 4 | `application/config/routes.php` | **No change required** (route stays for the dev POST form; production gate makes it inert). Optionally add a comment `// C1: production-inert (see plan 38)`. |
| 5 | *(optional, separate commit)* | Unique index `uk_user_tx_type` on `wallet_ledger` after dedupe (§4E). |

Out of scope for this patch (tracked in audit P0): **C7** (`simulate_wd_approve` — same GET-mutation class, remove/flag-gate separately); C2, C3, C5, C6 remain separate PRs.

---

## 6. VERIFICATION & TESTING PROTOCOL

### 6.1 Static checks
```bash
php -l application/controllers/Wallet.php
php -l application/models/Wallet_model.php
php -l application/views/wallet/index.php      # if touched
php -l application/config/routes.php           # if touched
```

### 6.2 Runtime harness
- MySQL `db_webtable` seeded; local servers:
  - Dev: `CI_ENV=development php -S localhost:8080` (tests 1–3)
  - Prod-sim: `CI_ENV=production php -S localhost:8081` (test 4)
- Session cookie jar per test user; CSRF token extracted from any page (`name="synapse_csrf_token" value="..."`); `csrf_regenerate=FALSE` keeps the token stable across the replay POSTs.
- DB assertions via `mysql -e` on `db_webtable`.

### 6.3 Test cases (acceptance criteria)

**Test 1 — Valid single payment simulation succeeds**
```bash
# login (cookie jar), extract CSRF, topup 100.000 → invoice INV-...
curl -s -b /tmp/cj -c /tmp/cj -d "synapse_csrf_token=$TOKEN&amount=100000" http://localhost:8080/wallet/topup
# POST simulate once (302 → wallet)
curl -s -o /dev/null -w '%{http_code}' -b /tmp/cj -d "synapse_csrf_token=$TOKEN" http://localhost:8080/wallet/simulate_payment/$INV
```
Assert:
- `SELECT status FROM deposits WHERE invoice_number='$INV'` → `success`
- `SELECT COUNT(*) FROM wallet_ledger WHERE transaction_id='$INV' AND type='credit'` → `1`
- `get_balance` increased by exactly `100000` (ledger delta = 1 credit).

**Test 2 — Duplicate / replay POST credits nothing**
```bash
curl -s -o /dev/null -w '%{http_code}' -b /tmp/cj -d "synapse_csrf_token=$TOKEN" http://localhost:8080/wallet/simulate_payment/$INV
```
Assert: HTTP 302 with error flash; `COUNT(*)` still `1`; balance delta `0` (no new credit). Same assertion for a concurrent burst (e.g. 5 parallel POSTs via `xargs -P`): exactly one credit total.

**Test 3 — Cross-user attempt → 403**
```bash
# second user session (cookie jar /tmp/cj2) — logged-in as user B
curl -s -o /dev/null -w '%{http_code}' -b /tmp/cj2 -d "synapse_csrf_token=$TOKEN2" http://localhost:8080/wallet/simulate_payment/$INV_A
```
Assert: HTTP `403`; no new `wallet_ledger` row; `deposits.status` unchanged. Also assert GET (no POST body) → HTTP `404`.

**Test 4 — Production simulation → 404 (fail-closed)**
```bash
curl -s -o /dev/null -w '%{http_code}' http://localhost:8081/wallet/simulate_payment/$INV          # GET
curl -s -o /dev/null -w '%{http_code}' -d "synapse_csrf_token=$TOKEN" http://localhost:8081/wallet/simulate_payment/$INV   # POST
```
Assert: HTTP `404` in both cases; `deposits.status` remains `pending`; zero ledger rows for the invoice; no flash, no redirect to wallet.

**Regression sweep:** re-run `php -l`, wallet page loads (HTTP 200) in dev with the guarded button visible, and in prod-sim the button absent (view guard) + endpoint 404. Re-run the audit checklist items for C1 only.

---

## 7. DECISION RECORD

1. **View button (Option A vs B):** No simulator button exists today. **Recommendation: Option B** — restore a dev-only simulator button inside the `ENVIRONMENT !== 'production'` guard (keeps UAT usable; provably inert in production). If the team prefers zero UI, Option A (no button) is equally safe — endpoint behavior identical.
2. **Cross-user response:** `show_error(..., 403)` chosen per the stated acceptance criterion (403 Forbidden). Note: this reveals to an authenticated user that an invoice exists (invoice numbers are already guessable per audit C1); acceptable for a dev-only endpoint, mitigated by the production 404 gate.
3. **Optional unique index (§4E):** deferred to a separate commit to keep this PR minimal and to allow pre-dedupe of `wallet_ledger`.

## 8. ROLLOUT NOTES
- Branch per `docs/3_ROADMAP.md` phase-branch convention; commit message in Indonesian, e.g. `fix(c1): kunci simulasi deposit — gate produksi, POST+CSRF, kepemilikan, guard atomik`.
- This plan is **PLAN MODE only** — no application code or configuration has been modified. Implementation starts only after explicit approval.
