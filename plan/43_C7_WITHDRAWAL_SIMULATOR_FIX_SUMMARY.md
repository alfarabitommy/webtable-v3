# Plan 43 — Execution Summary: C7 Withdrawal Simulator Self-Approval Fix

Status: **COMPLETE — code applied, php -l clean. Runtime curl tests per plan/42 §6 pending live-DB smoke run (see §5).**
Plan: `plan/42_C7_WITHDRAWAL_SIMULATOR_FIX_PLAN.md` (approved). Related: `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` C7, `plan/38`/`plan/39` (C1 same-class fix), Phase 10B (POST-only), Phase 10D (fail-closed ENVIRONMENT).
Scope option: **Option B** — dev-only simulator UI button restored inside the `ENVIRONMENT !== 'production'` guard.

---

## 1. Vulnerability (recap)

`Wallet::simulate_wd_approve()` was reachable by any logged-in user via CI3 default routing with **no ENVIRONMENT gate, no method guard, and no ownership check**; `approve_withdrawal_simulator()` flipped `withdrawals.status='success'` by `wd_number` **without** a `WHERE status='pending'` guard and **without** a `user_id` constraint, and returned bare `trans_status()` (TRUE even when 0 rows matched). A user could self-approve their own pending payout (bypassing `Admin::approve_withdrawal` review) and flip other users' WDs via guessable `WD-{YmdHis}-{userId}` numbers (unauthorized state mutation). No ledger row is inserted by this simulator (the debit is booked at request time in `create_withdrawal`) — the harms were authorization bypass + cross-user mutation, not double-credit.

## 2. Changes applied

### 2.1 `application/controllers/Wallet.php` — `simulate_wd_approve($wd_number)`

Fail-closed hardening, in order of execution (mirrors the approved C1 fix):
1. **Production hard-gate:** `if (ENVIRONMENT === 'production') { show_404(); return; }` — the URL effectively does not exist in production; no DB/session work is ever reached.
2. **POST-only guard:** `if ($this->input->method() !== 'post') { show_404(); return; }` — GET mutation eliminated (10B policy; mirrors the `Admin` controller pattern).
3. **Existence check:** `get_withdrawal_by_wd_number($wd_number)` → missing WD → flash error "Penarikan tidak ditemukan." + `redirect('wallet')`.
4. **Ownership check:** `(int)$wd->user_id !== (int)$user_id` → `log_message('error', 'C7 ownership violation: ...')` + `show_error('Akses ditolak: penarikan milik pengguna lain.', 403)`.
5. **Model call:** `approve_withdrawal_simulator($wd_number, $user_id)` with success flash ("Simulasi: Penarikan berhasil disetujui.") or failure flash ("Gagal memproses simulasi: penarikan sudah diproses atau tidak valid.").

### 2.2 `application/models/Wallet_model.php`

- **New helper** `get_withdrawal_by_wd_number($wd_number)` → `get_where('withdrawals', ['wd_number' => $wd_number])->row()` (all DB access stays in the model — AGENTS.md rule).
- **Rewritten** `approve_withdrawal_simulator($wd_number, $user_id)` — atomic conditional state transition:
  ```php
  public function approve_withdrawal_simulator($wd_number, $user_id) {
      $this->db->trans_start();

      // C7 (plan 42) 4B: transisi atomik bersyarat — hanya menang jika WD
      // masih 'pending' DAN milik user session. affected_rows() === 1 adalah
      // satu-satunya gerbang sukses; replay/duplicate → 0 baris → false.
      $this->db->where('wd_number', $wd_number);
      $this->db->where('status', 'pending');
      $this->db->where('user_id', $user_id);
      $this->db->update('withdrawals', [
          'status'       => 'success',
          'processed_at' => date('Y-m-d H:i:s'),
      ]);

      $affected = $this->db->affected_rows();

      $this->db->trans_complete();
      return $this->db->trans_status() && $affected === 1;
  }
  ```
  - Idempotency: success is returned **only** when `affected_rows() === 1` (the row was actually `pending` and owned by the session user). Replay/duplicate/concurrent callers match 0 rows → `false`. InnoDB row lock serializes concurrent transitions — exactly one winner.
  - Ownership is embedded in the UPDATE's WHERE (authoritative, TOCTOU-proof) in addition to the controller pre-check.
  - `processed_at` set in the same UPDATE (schema column exists, `database.sql`; decision record 4 of plan/42).
  - No ledger insert added — the WD debit was already booked at request time; the status flip is the entire money-relevant mutation.

### 2.3 `application/views/wallet/index.php` — dev-only simulator UI (Option B)

Inside the pending-withdrawal card, under each WD row:
```php
<?php if (ENVIRONMENT !== 'production'): ?>
<!-- C7 (plan 42): simulasi persetujuan WD HANYA untuk development/UAT —
     tidak pernah dirender di production. POST + CSRF (form_open). -->
<?= form_open('wallet/simulate_wd_approve/' . $wd->wd_number, 'class="mt-2"'); ?>
    <button type="submit" class="w-full bg-orange-500 hover:bg-orange-400 text-white text-xs font-bold py-2 rounded-lg transition">
        <i class="fas fa-flask mr-1"></i> Simulasi Persetujuan (Dev Only)
    </button>
<?= form_close(); ?>
<?php endif; ?>
```
- `form_open()` emits the hidden CSRF token (`synapse_csrf_token`, global `csrf_protection = TRUE`) → POST is token-enforced by CI3.
- The button cannot render in production (guard evaluated server-side).

### 2.4 `application/config/routes.php`

Explicit route registered with a C7-reference comment (decision record 3 of plan/42 — greppability/parity with `simulate_payment`; default routing already matched, the controller gate is the authoritative control):
```php
// C7 (plan 42): dev/UAT-only WD simulator — production-inert (gate in controller).
$route['wallet/simulate_wd_approve/(:any)'] = 'wallet/simulate_wd_approve/$1';
```

## 3. Syntax verification (php -l)

```
$ php -l application/controllers/Wallet.php
No syntax errors detected in application/controllers/Wallet.php
$ php -l application/models/Wallet_model.php
No syntax errors detected in application/models/Wallet_model.php
$ php -l application/views/wallet/index.php
No syntax errors detected in application/views/wallet/index.php
$ php -l application/config/routes.php
No syntax errors detected in application/config/routes.php
```
All four modified files lint clean. (AGENTS.md roadmap rule satisfied.)

## 4. Files touched (C7 scope only)

| File | Change |
|---|---|
| `application/controllers/Wallet.php` | `simulate_wd_approve()`: production gate + POST-only + existence check + ownership 403 + new model signature `($wd_number, $user_id)` |
| `application/models/Wallet_model.php` | `get_withdrawal_by_wd_number()` added; `approve_withdrawal_simulator()` rewritten (atomic conditional update `WHERE wd_number=? AND status='pending' AND user_id=?` + `affected_rows() === 1` gate + `processed_at`) |
| `application/views/wallet/index.php` | Dev-only "Simulasi Persetujuan (Dev Only)" POST form inside `ENVIRONMENT !== 'production'` guard on the pending-withdrawal card |
| `application/config/routes.php` | Explicit `wallet/simulate_wd_approve/(:any)` route + C7 comment |
| `plan/42_C7_WITHDRAWAL_SIMULATOR_FIX_PLAN.md` | Blueprint (previous step, approved) |

> Note: the working tree also contains **pre-existing uncommitted changes** from earlier phases (10A/10D, themes, reCAPTCHA, the C1 deposit-simulator fix, etc.) — they are unrelated to C7 and were not touched by this fix.

## 5. Test protocol status

Plan/42 §6.2–6.3 defines 4 runtime test cases. These require a live MySQL `db_webtable` + `CI_ENV`-controlled dev servers; **this execution environment has no MySQL server/client available** (`mysqladmin: command not found`), so the runtime harness was not run here and remains the **recommended next step** before merging:

1. **T1 — valid approve (dev):** POST `wallet/simulate_wd_approve/WD-...` → HTTP 302 (success flash), `SELECT status FROM withdrawals WHERE wd_number=...` → `success`, exactly one transition, no ledger delta.
2. **T2 — duplicate / replay:** second POST (and 5× parallel burst) → HTTP 302 with "sudah diproses" error flash, `affected_rows() = 0`, status stays `success`, no ledger delta.
3. **T3 — cross-user tamper:** second user session on another user's WD → HTTP 403, status unchanged, `C7 ownership violation` error log written; GET (no POST body) → HTTP 404.
4. **T4 — production (`CI_ENV=production`):** GET and POST → HTTP 404, status remains `pending`, no flash, no redirect.

Static guarantees already in place (code-verified): production hard-gate, POST-only guard, atomic conditional UPDATE, ownership enforced in both controller (403) and model WHERE, CSRF via global config + `form_open()`.

## 6. Rollout notes
- Commit on the C7 phase branch per `docs/3_ROADMAP.md`; suggested message (Indonesian): `fix(c7): kunci simulasi approve penarikan — gate produksi, POST+CSRF, kepemilikan, guard atomik`.
- Follow-ups tracked separately (audit P0): **C2** (ROI race), **C3** (withdrawal schema/CSV), **C5** (balance-check race), **C6** (wage TOCTOU) remain separate PRs; **C4/M6** double-entry ledger is P1.
