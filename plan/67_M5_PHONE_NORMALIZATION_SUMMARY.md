# 67 — M5 PHONE NORMALIZATION (VALIDATION-ORDERING) — SUMMARY

**Phase:** Implementation of `plan/66_AUDIT_GAP_ANALYSIS_SUMMARY.md` §5 — resolves genuine plan/37 finding **M5** ("Phone normalization vs `is_unique` ordering").
**Date:** 2026-09-03. **Mode:** Code changes applied to `application/controllers/Auth.php` and `application/controllers/Admin.php`. No schema change. No live-DB persistent rows created (throwaway row cleaned up).
**Source finding:** `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` §3-M5: `is_unique[users.phone]` validated the **raw** input; `$_POST['phone'] = $normalized` happened **after** `form_validation->run()`. `628xx` vs `08xx` variants both passed validation, then the DB `uk_phone` constraint rejected with a generic/raw error instead of a friendly duplicate message.

> **Label reconciliation (recap):** remediation-round "M5" (plan/64–65) = notifications & audit trail, a *different* finding. plan/37 finding M5 (phone normalization) was still open — this plan closes it.

---

## 1. CODE CHANGES

### 1a. `application/controllers/Auth.php` — `register()`

1. **Normalize before validation.** Immediately before `set_rules()`, the raw input is normalized and written back:
   ```php
   $phone          = $this->_normalize_phone($this->input->post('phone', TRUE));
   $_POST['phone'] = $phone;
   ```
   `form_validation->run()` (which reads `$_POST`) now evaluates `required|is_unique[users.phone]` against the **canonical 08xx form**.
2. **Friendly duplicate message on the rule** (per-field 4th argument):
   ```php
   $this->form_validation->set_rules('phone', 'Nomor Telepon', 'required|is_unique[users.phone]', array(
       'is_unique' => 'Nomor telepon sudah terdaftar. Silakan gunakan nomor lain atau login.',
   ));
   ```
   Rendered by `validation_errors()` + `form_error('phone')` in `auth/register.php` (existing view, no view change).
3. **Defensive duplicate-key catch at insert** (race window past `is_unique`): CI3 `db_debug` is TRUE outside production and would render a raw DB error page on a concurrent double-submit. The insert now runs with `db_debug` temporarily FALSE; after it, `$this->db->error()` is inspected:
   ```php
   $prev_debug         = $this->db->db_debug;
   $this->db->db_debug = FALSE;
   $user_id            = $this->User_model->create_user($user_data);
   $db_error           = $this->db->error();
   $this->db->db_debug = $prev_debug;

   if ($user_id) { ... redirect('login'); }
   elseif ((int) $db_error['code'] === 1062 && strpos((string) $db_error['message'], 'uk_phone') !== FALSE) {
       $data['errors'][] = 'Nomor telepon sudah terdaftar. Silakan gunakan nomor lain atau login.';
   } else {
       $data['errors'][] = 'Terjadi kesalahan sistem saat pendaftaran. Silakan coba lagi.';
   }
   ```
   ⇒ a `uk_phone` duplicate (errno 1062) becomes the same friendly message; any other failure keeps the generic message. No 500 / raw DB page.

### 1b. `application/controllers/Admin.php` — `create_user()`

Same normalize-before-validation pattern, applied before the existing rule set:
```php
$phone         = $this->_normalize_phone($this->input->post('phone', TRUE));
$_POST['phone'] = $phone;

$this->form_validation->set_rules('phone', 'Phone', 'required|trim|is_unique[users.phone]', array(
    'is_unique' => 'Nomor telepon sudah terdaftar.',
));
```
Validation failure path unchanged (`flashdata(validation_errors())` → redirect `admin/users`). The **M4 POST-only guard** (`$this->input->method() !== 'post'` → `show_404()`) is untouched (still the first statement of the method).

Inside the existing `trans_start()`/`trans_complete()` block, the insert is protected the same way as 1a.3:
- `db_debug` toggled FALSE for the single insert; `uk_phone` duplicate detected via `errno === 1062` + message contains `uk_phone`.
- **Audit logging preserved**: on duplicate, no audit row is written (nothing was created — a no-op); in every other branch the `admin_create_user` audit row is logged inside the TX exactly as before (rollback-safe).
- Redirect feedback: success / `'Nomor telepon sudah terdaftar.'` (dupe) / `'Gagal membuat pengguna.'` (other).

---

## 2. CONSISTENCY CHECK ACROSS CONTROLLERS

| Path | Status | Evidence |
|---|---|---|
| `Auth::register` | ✅ Normalize-before-validation | `Auth.php:213–214` normalize → `:216` `set_rules` |
| `Auth::login` | ✅ Already canonical | `Auth.php:307` `$phone = _normalize_phone(...)` before `get_where('users', ['phone' => $phone, ...])` — matching a registered user always uses the canonical form. Rate-limit key also normalizes (`Auth.php:281`) |
| `Admin::create_user` | ✅ Normalize-before-validation | `Admin.php:888–889` normalize → `:891` `set_rules`; POST-only guard `:877` retained |
| Raw `is_unique[users.phone]` remaining | ✅ None | `grep -rn "is_unique\[users.phone\]" application/controllers/` → exactly 2 `set_rules` sites (Auth:216, Admin:891), **both preceded** by `_normalize_phone()` + `$_POST` rewrite. Other hits are comments only |

No other controller inserts users or validates phone against `users.phone` (`Profile.php` has no phone rules).

---

## 3. VERIFICATION

### Lint (both modified files)
```
php -l application/controllers/Auth.php   → No syntax errors detected
php -l application/controllers/Admin.php  → No syntax errors detected
```

### Behavior (temporary CLI controller `M5_verify`, live dev DB `db_webtable`, throwaway row, **removed after run**)
Run: `CI_ENV=development DB_HOSTNAME=127.0.0.1 php index.php M5_verify`

```
[PASS] seed canonical user 081202370550            -> uid=63
[PASS] exactly one canonical row in DB             -> count=1
[PASS] _normalize_phone(6281202370550) === 081202370550  -> got='081202370550'
[PASS] NEW: validation rejects variant w/ friendly msg  -> run=false | error=<p>Nomor telepon sudah terdaftar. Silakan gunakan nomor lain atau login.</p>
[PASS] OLD: raw variant would PASS is_unique (bug demo) -> run=true
[PASS] race dupe insert → false + errno 1062 uk_phone   -> code=1062 | msg=Duplicate entry '081202370550' for key 'uk_phone'
[PASS] no second row created by dupe insert        -> count=1
[PASS] cleanup: 0 leftover rows                    -> count=0
ALL CHECKS PASSED
```

Proof mapping to the acceptance behavior:
- **registering `6281234567890` when `081234567890` exists** → check 3: the real `_normalize_phone()` (`628…` → `0812…`) + the real CI `is_unique[users.phone]` rule (same two statements now in `Auth::register`) **fail validation** with the friendly Indonesian message.
- **not an unhandled database exception** → check 6: the DB-layer race duplicate returns `false` + `errno 1062` + `uk_phone` key, which the new controller branch translates into the same friendly message; check 7 proves no second row is inserted. Without the new `db_debug` toggle, `db_debug=TRUE` (non-production) would have rendered a raw DB error page — the branch under test is exactly what prevents that.

**Notes / limitations:**
- End-to-end HTTP `POST /auth/register` curl was **not** run because reCAPTCHA v2 is fail-closed and no `RECAPTCHA_SECRET` is set in this sandbox (by design, plan/28 env contract). Checks 2–3 execute the *identical* statements used by the patched controller against the real DB, so the validation path is proven at logic level.
- Console shows pre-existing PHP 8.2 deprecation notices from CI3 core (`CI_URI::$config`, `CI_Router::$uri` dynamic properties) under `development` — unrelated to this change (present on any request).
- The dev DB already contained a leftover user with phone `081234567890` (with FK children, e.g. bank accounts) from earlier manual testing. The harness therefore used a **unique random** throwaway phone per run and cleaned up only the row it created — no pre-existing dev data touched.

---

## 4. FILES CHANGED

- `application/controllers/Auth.php` (register: normalize-before-validation, custom `is_unique` message, defensive `uk_phone` 1062 catch)
- `application/controllers/Admin.php` (create_user: same pattern; duplicate skips audit no-op; success/dupe/generic feedback)
- `plan/66_AUDIT_GAP_ANALYSIS_SUMMARY.md` (prior analysis — unchanged)
- `plan/67_M5_PHONE_NORMALIZATION_SUMMARY.md` (this document)
- Temp harness `application/controllers/M5_verify.php` — **created and deleted** during verification; no trace left.

---

*End of summary — plan/67_M5_PHONE_NORMALIZATION_SUMMARY.md.*
