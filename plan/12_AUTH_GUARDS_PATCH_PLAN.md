# Auth Guards Patch Plan — Ban Lockout & Forced Password Change (7E3 completion)

**Status:** Blueprint — awaiting approval before applying any code changes. No application code has been written yet.

**Stack:** CodeIgniter 3 + PHP 8.x, MySQL (`db_webtable`), mobile-first Tailwind (CDN), Indonesian UI, IDR only.
**Spec anchors:** `docs/1_PRD.md` §7E3 / §D.2 (line 286: *"When flag is `1`, redirect all requests to `/auth/change-password` until password is updated"*); `docs/3_ROADMAP.md` 7E2/7E3.

---

## 1. Findings from Code Inspection (root cause)

| # | Claimed behavior (plans/roadmap) | Actual code | Gap |
|---|----------------------------------|-------------|-----|
| 1 | `must_change_password = 1` → redirect to `/auth/change-password` | **No code in `application/` references `must_change_password` or `change-password` at all** (verified by grep). `MY_Controller.php` only guards `user_id` session. | Redirect never implemented → any visit to `/auth/change-password` 404s |
| 2 | `Auth::change_password()` + view exist | `Auth.php` has only `register/login/logout/seeder_admin`. No `change_password` method; no `application/views/auth/change_password.php`. | 404 on forced-change flow |
| 3 | `is_banned = 1` users blocked from login | `Auth::login()` (lines 123–170) checks only phone + `role='user'` + `password_verify`, then sets session and redirects `home`. No `is_banned` check. | `leaf_lutfi` (081299001122) can log in while banned |
| 4 | Banned-but-logged-in users kicked on next request | `MY_Controller::__construct` loads balance + notifications per request but never re-reads the user row. | No session-level kick; ban only takes effect after logout |

Supporting facts:
- `Admin::toggle_ban($id)` (POST only) already toggles `users.is_banned` and writes audit log — ban can happen while the victim is logged in, which is why the session kick is required.
- `Auth` extends `CI_Controller` (not `MY_Controller`), so `change_password()` must **self-guard** (own `user_id` check + ban check).
- `application/config/routes.php` has `translate_uri_dashes = FALSE` → the literal URI `auth/change-password` will **not** map to method `change_password()` automatically; an explicit route is required (project convention: "Register pretty URLs in routes.php").
- `application/views/auth/login.php` renders `flashdata('success')` and `$errors` but **not** `flashdata('error')` — the kick message must be surfaced by adding an error-flash block (pattern already used in `views/admin/*`, `views/marketplace/index.php`).
- Auth pages are standalone dark-mode pages (`bg-slate-900` shell + white rounded card), **not** `templates/header.php` — `change_password.php` must follow the standalone layout of `login.php`.
- `User_model` already provides `get_user_by_id($id)` and `update_user($id, $data)` — no new model methods needed; all DB access stays in models.

---

## 2. Implementation Blueprint

### PHASE 1 — Ban Enforcement (login rejection + session kick)

**1a. `Auth::login()` — reject banned users at login**
- After the existing `password_verify` succeeds (check **after** credential verification — do not leak ban status to callers who do not hold valid credentials), add:
  ```php
  if ((int) $user->is_banned === 1) {
      $data['errors'][] = 'Akun Anda telah dinonaktifkan. Silakan hubungi admin.';
      $data['values']   = $this->input->post();
      $this->load->view('auth/login', $data);
      return;
  }
  ```
- No session data is written; the user is shown the exact Indonesian message from the requirement.

**1b. `MY_Controller::__construct` — session-level kick for banned users**
- When `user_id` is present (non-auth controllers), load `User_model` and fetch the row once: `$user = $this->User_model->get_user_by_id($user_id);`
- If `(int) $user->is_banned === 1`:
  ```php
  $this->session->unset_userdata('user_id');          // keep flashdata alive (sess_destroy would kill it)
  $this->session->set_flashdata('error', 'Akun Anda telah dinonaktifkan. Silakan hubungi admin.');
  redirect('login');
  ```
- `unset_userdata` (not `sess_destroy`) so the flash message survives the redirect. The same fetch also supplies `must_change_password` for Phase 2 (one query per request, already the norm: MY_Controller loads `Wallet_model` + `Notification_model` per request).

**1c. `application/views/auth/login.php` — surface the kick message**
- Add a `flashdata('error')` render block in the same rose/error style as the existing `$errors` block (copy of the pattern in `views/admin/users.php`).

### PHASE 2 — Forced Password Change (7E3 completion)

**2a. Route** — `application/config/routes.php`, after the Phase 2 auth routes:
```php
$route['auth/change-password'] = 'auth/change_password';
```

**2b. `Auth::change_password()` — new method (GET + POST)**
- Self-guard (Auth is not under `MY_Controller`):
  - `user_id` empty → `redirect('login');`
  - fetch user via `User_model::get_user_by_id()`; if `is_banned === 1` → same kick as 1b (unset `user_id`, flash error, redirect `login`).
- **GET:** render `auth/change_password` view with `$errors = []` and `$values = []`.
- **POST** (`form_validation`):
  ```php
  $this->form_validation->set_rules('new_password', 'Kata Sandi Baru', 'required|min_length[8]');
  $this->form_validation->set_rules('confirm_password', 'Konfirmasi Kata Sandi', 'required|matches[new_password]');
  ```
  - On success: `User_model::update_user($user_id, ['password' => password_hash($new, PASSWORD_BCRYPT), 'must_change_password' => 0])` — single update clears the flag (PRD D.2: cleared after successful password update).
  - `set_flashdata('success', 'Kata sandi berhasil diperbarui.')` → `redirect('home');` (Home = dashboard, default controller).
  - On failure: re-render view with `form_error()` inline messages + `$values` repopulated.

**2c. `MY_Controller::__construct` — force redirect while flag is set**
- In the same logged-in branch as 1b: if `(int) $user->must_change_password === 1` → `redirect('auth/change-password');` (covers every non-auth page; Auth controller is exempt from MY_Controller, so `change_password()` itself stays reachable — no redirect loop).

**2d. `Auth::login()` — immediate redirect when flag is set**
- After ban check passes and session data is set, if `(int) $user->must_change_password === 1` → `redirect('auth/change-password');` else `redirect('home');` (better UX than landing on dashboard first; 2c remains the safety net for every other request).

**2e. `application/views/auth/change_password.php` — new view**
- Standalone dark-mode page cloned from `login.php` layout: `bg-slate-900` shell, branding header (`SYNAPSE LOGO` placeholder), white rounded `2.5rem` top card.
- Title "Ubah Kata Sandi" + helper text (e.g. *"Anda wajib mengganti kata sandi sebelum melanjutkan."*).
- Fields: `Kata Sandi Baru` (type password, `required|min_length[8]` → placeholder "Minimal 8 karakter") and `Konfirmasi Kata Sandi` (matches), with `form_error()` + `$errors` + `flashdata('error')` render blocks.
- Submit button "Simpan Kata Sandi", dark `bg-slate-900 hover:bg-blue-600` style.
- No reCAPTCHA (user already authenticated).
- Guard against CSRF? CI3 CSRF is off by default in this project (login/register have no `csrf` tokens) — stay consistent, note as known limitation.

### PHASE 3 — Verification Protocol

**3a. Syntax lint (roadmap rule — every new/modified PHP file):**
```bash
php -l application/controllers/Auth.php
php -l application/core/MY_Controller.php
php -l application/views/auth/change_password.php
php -l application/config/routes.php
```

**3b. Workflow B — forced password change (`must_change_password = 1`, user id 6 `agent_eka` 087855667788):**
1. Login `087855667788` + temp password (seeded) → expect **302 to `/auth/change-password`** (2d), not `home`.
2. With the session cookie, GET `/home` → **302 to `/auth/change-password`** (2c, MY_Controller guard).
3. GET `/auth/change-password` → 200 renders the form (2b GET).
4. POST new password `ABCabc123` + mismatched confirm → form re-rendered with `form_error` (validation fails).
5. POST `ABCabc123` + matching confirm → **302 to `home`** + success flash; verify `SELECT must_change_password, password FROM users WHERE id = 6` → `0` and bcrypt hash updated.
6. Logout; login with old temp password → rejected; login with `ABCabc123` → success.

**3c. Workflow A — ban lockout (`is_banned = 1`, user id 13 `leaf_lutfi` 081299001122):**
1. Login `081299001122` + seeded password → **200 login page with** "Akun Anda telah dinonaktifkan. Silakan hubungi admin." and no `user_id` session (1a).
2. Session-kick: with a valid logged-in session for a second user (e.g. id 1 `vipleader`), toggle ban via `Admin::toggle_ban` (or `UPDATE users SET is_banned = 1 WHERE id = 1`), then GET `/home` with that cookie → **302 to `login`** + error flash rendered on the login page (1b + 1c).
3. Edge: unauthenticated GET `/auth/change-password` → 302 to `login`.

**3d. Regression:** register→login, normal login redirect to `home`, admin login at `/control-panel`, wallet/notification globals still inject (MY_Controller changes must not break the balance/notification injection).

---

## 3. Files Touched

| File | Change |
|------|--------|
| `application/controllers/Auth.php` | ban check + `must_change_password` redirect in `login()`; new `change_password()` method |
| `application/core/MY_Controller.php` | per-request user fetch → ban kick + `must_change_password` redirect |
| `application/views/auth/change_password.php` | **new** standalone dark-mode form view |
| `application/views/auth/login.php` | add `flashdata('error')` render block |
| `application/config/routes.php` | `$route['auth/change-password'] = 'auth/change_password';` |

No model changes needed (`User_model::get_user_by_id/update_user` suffice). No DB schema changes (`is_banned`, `must_change_password` already live per seed).

---

## 4. Decisions & Trade-offs (explicit)

- **Ban check after `password_verify`** — prevents revealing ban status to anyone without valid credentials (enumeration hardening). The requirement's exact message is still shown to the legitimate owner.
- **Kick via `unset_userdata('user_id')` instead of `sess_destroy()`** — flashdata survives; no new session cookie dance.
- **Current-password verification on the change form**: the requirement specifies only new + confirm (admin-reset flow already authenticated the user with the temp password), so the plan follows that. Recommended optional hardening (3-field form) is **not** included — say the word and it becomes an extra 3-line validation rule + one `password_verify`.
- **One extra DB read per request in `MY_Controller`** — the price of immediate ban enforcement; consistent with existing per-request model loads.
- **CSRF tokens not added** — CI3 CSRF is off across this project (login/register have none); adding it here alone would be inconsistent. Flagged as known limitation for a future hardening pass.
- **`CURLOPT_SSL_VERIFYPEER false` in `_verify_recaptcha`** — pre-existing, out of scope (already documented as dev-only in the code).

---

## 5. Out of Scope

- Audit-log writes for `admin_reset_password` (Phase 10A handles; already tracked in `plan/1_PHASE_A_SUMMARY.md`).
- Hardcoded reCAPTCHA secret in `Auth.php` (AGENTS.md cleanup flag — untouched here).
- CSRF hardening, brute-force throttling, current-password verification (see §4).
