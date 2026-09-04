# 65 — M5 NOTIFICATIONS & AUDIT TRAIL INTEGRITY (SUMMARY)

**Phase:** Implementation of approved blueprint `plan/64_M5_NOTIFICATIONS_AND_AUDIT_TRAIL_PLAN.md` (N1–N4, A1–A2).
**Date:** 2026-09-03. **Mode:** Code + schema changes applied to the live dev DB (`db_webtable`) and repo files.

---

## 1. DATABASE CHANGES (N4)

- **`database.sql`** — `withdrawals` gained nullable column:
  ```sql
  `decline_reason` VARCHAR(255) DEFAULT NULL  -- placed AFTER `remark`
  ```
- **Live DB (`db_webtable`)** — migration applied and verified:
  `ALTER TABLE withdrawals ADD COLUMN decline_reason VARCHAR(255) NULL DEFAULT NULL AFTER remark` → `SHOW COLUMNS` confirms `varchar(255) / NULL / no default`.

## 2. NOTIFICATION DISPATCH — CALL SITES ADDED (N1–N3)

| # | Event | File:line context | Type | Trigger / dedupe |
|---|---|---|---|---|
| N1 | **ROI claim success** | `controllers/Rentals.php` (`claim()`) | `commission` | Only when `claim_roi()` returns `code === 'claimed'` (the sole payout-success code, C2) → replay/double-claim can never double-notify |
| N2 | **Rental expired → completed (lazy per-user)** | `models/Rental_model.php` (`expire_user_rentals()`) | `info` | Self-contained TX; per-row conditional `UPDATE ... WHERE status='active'` + `affected_rows()===1` gate; flip-once ⇒ notify-once (verified: run 1 → 2 flips/2 notifs, run 2 → 0/0) |
| N2 | **Rental expired → completed (global sweep)** | `models/Rental_model.php` (`expire_all_expired()`) | `info` | Caller-TX participant (pattern `Wallet_model::credit`, plan/54) so `Admin::expire_expired_rentals` commits sweep + notifications + audit atomically; same per-row gate (verified: 1 flip/1 notif, re-run 0) |
| N3 | **Admin inject balance** | `controllers/Admin.php` (`inject_balance()`) | `info` | Post-commit; message distinguishes credit ("ditambahkan ke") vs debit ("dipotong dari"), appends custom description |
| N3 | **Admin inject rental** | `controllers/Admin.php` (`inject_rental()`) | `info` | Post-commit; references product id |
| N3 | **Admin unban** | `controllers/Admin.php` (`toggle_ban()`) | `info` | Post-commit; only when user exists and new state = unbanned (`$new_state !== FALSE && (int)$new_state === 0`) |

Existing bells retained: deposit approved, WD approved, WD declined (now with reason + type fix), L1 bonus, weekly wage.

## 3. WITHDRAWAL-DECLINE REASON END-TO-END (N4)

- `Admin_model::decline_withdrawal($wd_id, $audit = null, $reason = null)` — persists `decline_reason` in the same conditional `pending→failed` transition; audit `details` now carries `wd_number`, `amount`, `refunded`, `reason`.
- `Admin::decline_withdrawal` reads optional `reason` POST field and passes it through; notification message appends ` Alasan: …` when present.
- Decline bell type corrected `'error' → 'warning'` — **latent bug found**: `user_notifications.type` is `ENUM('info','warning','success','commission')`, so the old `'error'` was silently coerced to `''` by MySQL (4 legacy rows observed live). Code now emits a valid enum member; legacy rows left untouched (render via template fallback).
- `views/admin/dashboard.php` decline form now includes an optional reason input (`name="reason"`, maxlength 255); row wrapper uses `items-start` so the taller decline column does not stretch the Approve button.

## 4. AUDIT-TRAIL PAYLOAD ENRICHMENT (A1) & CONSISTENCY (A2)

| Action key | Enrichment added |
|---|---|
| `admin_update_financial_settings` | `before` (per key, read pre-persist via `get_setting`) + `after` (`$v['values']`) — fee/ops-hours/limits toggles now auditable old→new |
| `admin_update_settings` | `before` (wa_number/support_email snapshot) + `after` (`$data`) |
| `admin_adjust_time` | `before`/`after` for `last_claimed_at` + `days_processed` |
| `admin_cancel_rental` | snapshot `product_id`, `purchase_price`, `daily_roi`, `refunded => false` (soft-cancel, no refund) |
| `admin_create_user` | + `invite_code`, `parent_id` |
| `admin_inject_balance` | model now writes `type`, `amount`, `description`, `balance_after` (fresh ledger sum inside the TX) |
| `admin_update_user` | `before` (username/phone/invite_code/parent_id read pre-write) vs `after` (incl. resolved `parent_id`) |
| `decline_withdrawal` | + `reason` (see §3) |

**A2 regression:** all audit insertions remain inside their caller TX (rollback-safe); every Admin mutator retains its POST-only guard (`$this->input->method() !== 'post'` → 404). No audit call moved outside a transaction.

## 5. VERIFICATION

- **Lint (all touched PHP files pass):**
  ```
  php -l application/controllers/Admin.php          → No syntax errors
  php -l application/controllers/Rentals.php        → No syntax errors
  php -l application/models/Admin_model.php         → No syntax errors
  php -l application/models/Rental_model.php        → No syntax errors
  php -l application/views/admin/dashboard.php      → No syntax errors
  ```
- **Functional (temporary CLI controller, live dev DB, throwaway rows, full cleanup — removed after run):**
  ```json
  {"ok":true,"checks":[
    ["expire_user_rentals","flip1=2 flip2=0 notif=2",true],
    ["expire_all_expired","flip1=1 flip2=0 notifB=1 tx=ok",true],
    ["decline_withdrawal_reason","persist=y audit=y refunded=y",true],
    ["inject_balance_audit","ok=y desc=y balance_after=y",true]]}
  ```
  ⇒ No duplicate notifications on repeated sweeps (flip-once/notify-once proven); reason persisted + audited + refund intact; enriched audit payloads confirmed. Leftover-row audit: 0 test users/notifications/audit/WD rows remain.

## 6. FILES CHANGED

- `database.sql` (schema)
- `application/controllers/Admin.php` (N3, N4, A1)
- `application/controllers/Rentals.php` (N1)
- `application/models/Admin_model.php` (N4 signature/persist/audit; A1 inject_balance)
- `application/models/Rental_model.php` (N2 both sweeps)
- `application/views/admin/dashboard.php` (N4 decline reason input)
- `plan/64_M5_NOTIFICATIONS_AND_AUDIT_TRAIL_PLAN.md`, `plan/65_M5_NOTIFICATIONS_AND_AUDIT_TRAIL_SUMMARY.md` (docs)

*End of summary — plan/65_M5_NOTIFICATIONS_AND_AUDIT_TRAIL_SUMMARY.md.*

---

## ADDENDUM — M5 BUGFIX: decline reason missing from user notification

**Symptom:** Manual test of Scenario 1 — WD decline processed correctly, but member notification showed only the fallback (`…Dana telah dikembalikan ke saldo.`) without `Alasan: …`, although `withdrawals.decline_reason` and `system_audit_logs.details` were populated.

**Root cause:** `Admin_model::decline_withdrawal()` returns the `$wd` object read at method start — a **pre-UPDATE snapshot** where `decline_reason` is still `NULL`. The controller gated the message on the returned object (`if ($wd->decline_reason)`), which is always falsy → reason never reached the notification text. DB + audit were fine (they use the `$reason` argument directly).

**Fix (`application/controllers/Admin.php::decline_withdrawal`):** build the message from the local sanitized POST value instead of the stale object:
```php
$reason = trim((string) $this->input->post('reason', TRUE));
// ...pass ($reason === '') ? null : $reason into Admin_model::decline_withdrawal()...
$message = 'Penarikan sebesar Rp ' . number_format($wd->amount, 0, ',', '.') . ' ditolak. Dana telah dikembalikan ke saldo.';
if ($reason !== '') {
    $message .= ' Alasan: ' . $reason;
}
```

**Verification (temporary CLI harness, live dev DB, throwaway rows, removed after run):**
```json
{"ok":true,"checks":[
  ["returned_wd_is_stale_snapshot","decline_reason_on_returned=NULL",true],
  ["db","Rekening tidak valid",true],
  ["audit","contains reason",true],
  ["notification_message","Penarikan sebesar Rp 100.000 ditolak. Dana telah dikembalikan ke saldo. Alasan: Rekening tidak valid",true]]}
```
`php -l` clean on `Admin.php`, `Admin_model.php`, `views/admin/dashboard.php`. Dashboard form confirmed: `<input type="text" name="reason" maxlength="255">` sits strictly inside the decline `<form>` (form_open → input → submit → form_close).
