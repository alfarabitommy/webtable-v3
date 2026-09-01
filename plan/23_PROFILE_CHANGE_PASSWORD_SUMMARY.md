# Voluntary Profile Password Change — Execution Summary (23)

**Status:** ✅ IMPLEMENTED & LINT-CLEAN — Plan `plan/22_PROFILE_CHANGE_PASSWORD_PLAN.md` executed in full.
**Scope:** Route registration, controller methods, new view, profile page link + dead-code removal. No model/DB changes (reused `User_model::update_user()`).

---

## 1. Files Changed

| File | Change |
|------|--------|
| `application/config/routes.php` | +1: `$route['profile/change-password'] = 'profile/change_password';` (Profile block) |
| `application/controllers/Profile.php` | +50: `change_password()` (GET+POST) + `_verify_current_password()` callback |
| `application/views/profile/change_password.php` | **NEW** (4.4 KB): mobile-first form under user shell |
| `application/views/profile/index.php` | −54/+3: button→anchor link; removed `#changePasswordModal` markup + orphan JS IIFE |

`git diff --stat` (3 tracked files): `3 files changed, 55 insertions(+), 51 deletions(-)`; new view untracked (`??`).

---

## 2. Implementation Highlights

### Route (`routes.php`)
```php
$route['profile/change-password'] = 'profile/change_password';
```

### Controller (`Profile.php`)
- `change_password()`:
  - Rules: `current_password` = `required|callback__verify_current_password`; `new_password` = `required|min_length[8]`; `confirm_password` = `required|matches[new_password]`.
  - Success: `User_model::update_user($user_id, ['password' => password_hash($new, PASSWORD_BCRYPT)])` → flash `"Kata sandi berhasil diperbarui."` → `redirect('profile')`.
  - Failure: inline `form_error()` per field (200 re-render); DB-level failure surfaced via `$data['errors']`.
  - **Deliberately does NOT touch `must_change_password`** — forced-reset flow (`Auth::change_password`) remains the only place that clears the flag.
- `_verify_current_password($current_password)` (public — CI3 callback requirement): `password_verify()` vs `users.password`; error message `"Kata sandi saat ini salah."` bound via `set_message()`.
- Guards inherited from `MY_Controller` (login required, ban kick, forced-password-change redirect) — no extra guard code needed.

### View (`profile/change_password.php`)
- Rendered between `templates/header` and `templates/bottom_nav`; back button → `base_url('profile')`; `$page_title` = "Ubah Kata Sandi".
- 3 password inputs with `autocomplete="current-password"`/`"new-password"`, inline `form_error()` renderers, flashdata success/error blocks, general-error list, indigo submit button — styling reused verbatim from the profile edit sheet / `wallet/withdraw.php`.

### Profile page (`profile/index.php`)
- `#btn-change-password` button → `<a href="<?= site_url('profile/change-password') ?>">` (identical amber-lock/chevron styling).
- Removed dead `#changePasswordModal` block and the change-password JS IIFE; kept shared `openSheet()`/`closeSheet()` helpers and Edit Profil wiring.

---

## 3. Verification — Syntax Lint

```
$ php -l application/config/routes.php
No syntax errors detected in application/config/routes.php
$ php -l application/controllers/Profile.php
No syntax errors detected in application/controllers/Profile.php
$ php -l application/views/profile/index.php
No syntax errors detected in application/views/profile/index.php
$ php -l application/views/profile/change_password.php
No syntax errors detected in application/views/profile/change_password.php
```

Orphan-reference scan on `profile/index.php` (`btn-change-password|changePasswordModal|cp-backdrop|btn-close-cp|cp-sheet`): **NONE (clean)**.

---

## 4. Manual UAT Steps (browser, dev server `php -S localhost:8080`)

> Setup: create a normal user via `/register` (or use an existing one); keep its current password handy.

| # | Scenario | Steps | Expected |
|---|----------|-------|----------|
| 1 | Page access (guest) | Log out → visit `/profile/change-password` | Redirect to `/login` |
| 2 | Page renders | Log in → `/profile` → tap "Keamanan & Sandi" | Lands on `/profile/change-password`; back arrow returns to `/profile`; 3 fields + submit visible |
| 3 | Wrong current password | Submit wrong `current_password` + valid new/confirm | Inline red error under first field: "Kata sandi saat ini salah."; old password still works |
| 4 | Mismatched confirm | Submit valid current + new, different confirm | Inline error under confirm field (matches rule) |
| 5 | Short new password | Submit `new_password` < 8 chars | Inline error under new-password field (min_length) |
| 6 | Happy path | Submit valid 3 fields | Redirect to `/profile` with green flash "Kata sandi berhasil diperbarui." |
| 7 | Re-login with new password | Log out → log in with the **new** password | Login succeeds; old password now rejected |

Optional DB spot-check (tests 3–6):
```sql
SELECT password FROM users WHERE id=<user_id>;
-- then verify: password_verify('<old>', $hash) === FALSE, password_verify('<new>', $hash) === TRUE
```

---

## 5. Notes / Residuals

- CSRF is globally disabled (`config.php`), so `form_open()` needs no token; if CSRF is enabled later, `form_open()` auto-injects it — no code change required.
- Authenticated change-password surface is not rate-limited (only public login/register are); the login limiter already bounds credential guessing at the door. Possible follow-up, out of scope here.
- No schema/migration changes; no model changes.
