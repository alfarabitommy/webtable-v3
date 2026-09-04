# Plan 39 — Execution Summary: C1 Deposit Simulator Fix

Status: **COMPLETE — code applied, php -l clean. Runtime curl tests per plan/38 §6 pending live-DB smoke run (see §5).**
Plan: `plan/38_C1_DEPOSIT_SIMULATOR_FIX_PLAN.md` (approved). Related: `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` C1, Phase 10B (POST-only), Phase 10D (fail-closed ENVIRONMENT).
Scope option: **Option B** — dev-only simulator UI button restored inside the `ENVIRONMENT !== 'production'` guard.

---

## 1. Vulnerability (recap)

`Wallet::simulate_payment()` was a logged-in **GET** route with no ENVIRONMENT gate and no ownership check; `approve_deposit_simulator()` flipped `deposits.status='success'` **without** a `WHERE status='pending'` guard and **unconditionally** inserted a `wallet_ledger` credit. Calling the same invoice twice (or concurrently) credited the balance twice — a money printer; any logged-in user could also simulate another user's guessable invoice (`INV-YmdHis-userId`).

## 2. Changes applied

### 2.1 `application/controllers/Wallet.php` — `simulate_payment($invoice_number)`
Fail-closed hardening, in order of execution:
1. **Production hard-gate:** `if (ENVIRONMENT === 'production') { show_404(); return; }` — the URL effectively does not exist in production; no DB/ledger logic is ever reached.
2. **POST-only guard:** `if ($this->input->method() !== 'post') { show_404(); return; }` — GET mutation eliminated (10B policy; mirrors the `Admin` controller pattern).
3. **Existence check:** `get_deposit_by_invoice($invoice_number)` → missing invoice → flash error + `redirect('wallet')`.
4. **Ownership check:** `(int)$deposit->user_id !== (int)$user_id` → `log_message('error', 'C1 ownership violation: ...')` + `show_error('Akses ditolak: invoice milik pengguna lain.', 403)`.
5. **Model call:** `approve_deposit_simulator($invoice_number, $user_id)` with success flash ("Pembayaran berhasil disimulasikan! Dana sudah masuk.") or failure flash ("Gagal memproses simulasi: invoice sudah diproses atau tidak valid.").

### 2.2 `application/models/Wallet_model.php`
- **New helper** `get_deposit_by_invoice($invoice_number)` → `get_where('deposits', ['invoice_number' => $invoice_number])->row()`.
- **Rewritten** `approve_deposit_simulator($invoice_number, $user_id)` — atomic conditional state transition:
  ```php
  $this->db->trans_start();

  $this->db->where('invoice_number', $invoice_number);
  $this->db->where('status', 'pending');
  $this->db->where('user_id', $user_id);
  $this->db->update('deposits', ['status' => 'success']);

  $affected = $this->db->affected_rows();

  if ($affected === 1) {
      $deposit = $this->db->get_where('deposits', ['invoice_number' => $invoice_number])->row();
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
  ```
  - Idempotency: the credit is inserted **only** when `affected_rows() === 1` (i.e. the row was actually `pending` and owned by the session user). Replay/duplicate/concurrent callers match 0 rows → 0 credit → `false`. InnoDB row lock serializes concurrent transitions — exactly one winner.
  - Ownership is embedded in the UPDATE's WHERE (authoritative, TOCTOU-proof) in addition to the controller pre-check.
  - Credit insert and status flip share one transaction: a failed insert rolls back the status flip and vice versa.

### 2.3 `application/views/wallet/index.php` — dev-only simulator UI (Option B)
Inside the pending-deposit card, under each invoice row:
```php
<?php if (ENVIRONMENT !== 'production'): ?>
<!-- C1 (plan 38): simulasi pembayaran HANYA untuk development/UAT —
     tidak pernah dirender di production. POST + CSRF (form_open). -->
<?= form_open('wallet/simulate_payment/' . $row->invoice_number, 'class="mt-2"'); ?>
    <button type="submit" class="w-full bg-amber-500 hover:bg-amber-400 text-white text-xs font-bold py-2 rounded-lg transition">
        <i class="fas fa-flask mr-1"></i> Simulasi Pembayaran (Dev Only)
    </button>
<?= form_close(); ?>
<?php endif; ?>
```
- `form_open()` emits the hidden CSRF token (`synapse_csrf_token`, global `csrf_protection = TRUE`) → POST is token-enforced by CI3.
- The button cannot render in production (guard evaluated server-side).

### 2.4 `application/config/routes.php`
**No change.** Route `wallet/simulate_payment/(:any)` remains (used by the dev POST form); the controller's production hard-gate makes it inert in production.

## 3. Syntax verification (php -l)

```
$ php -l application/controllers/Wallet.php
No syntax errors detected in application/controllers/Wallet.php
$ php -l application/models/Wallet_model.php
No syntax errors detected in application/models/Wallet_model.php
$ php -l application/views/wallet/index.php
No syntax errors detected in application/views/wallet/index.php
```
All three modified files lint clean. (AGENTS.md roadmap rule satisfied.)

## 4. Files touched (C1 scope only)

| File | Change |
|---|---|
| `application/controllers/Wallet.php` | `simulate_payment()`: production gate + POST-only + ownership 403 + new model signature |
| `application/models/Wallet_model.php` | `get_deposit_by_invoice()` added; `approve_deposit_simulator()` rewritten (atomic conditional update + `affected_rows() === 1` gate) |
| `application/views/wallet/index.php` | Dev-only simulator POST form inside `ENVIRONMENT !== 'production'` guard |
| `plan/38_C1_DEPOSIT_SIMULATOR_FIX_PLAN.md` | Blueprint (previous step) |

> Note: the working tree also contains many **pre-existing uncommitted changes** from earlier phases (10A/10D, themes, reCAPTCHA, etc.) — they are unrelated to C1 and were not touched by this fix.

## 5. Test protocol status

Plan/38 §6.2–6.3 defines 4 runtime test cases (dev valid simulate, replay/concurrent burst, cross-user 403, production 404). These require a live MySQL `db_webtable` + `CI_ENV`-controlled dev servers and are the **recommended next step** before merging:

1. **T1 — valid simulate:** POST → 302, `deposits.status='success'`, exactly 1 `wallet_ledger` credit, balance +100.000.
2. **T2 — replay/concurrent:** second POST (and parallel burst) → error flash, credit count still 1, balance delta 0.
3. **T3 — cross-user:** second user session on the invoice → HTTP 403, no new credit; GET (no POST) → HTTP 404.
4. **T4 — production (`CI_ENV=production`):** GET and POST → HTTP 404, `deposits.status` remains `pending`, zero ledger rows.

Static guarantees already in place: production hard-gate, POST-only, atomic conditional UPDATE, ownership in both controller (403) and model WHERE, CSRF via global config + `form_open()`.

## 6. Rollout notes
- Commit on the C1 phase branch per `docs/3_ROADMAP.md`; suggested message (Indonesian): `fix(c1): kunci simulasi deposit — gate produksi, POST+CSRF, kepemilikan, guard atomik`.
- Follow-ups tracked separately (audit P0): **C7** `simulate_wd_approve` (same GET-mutation class — remove/flag-gate in its own PR); optional `wallet_ledger` unique index `uk_user_tx_type(user_id, transaction_id, type)` as a separate migration after dedupe.
