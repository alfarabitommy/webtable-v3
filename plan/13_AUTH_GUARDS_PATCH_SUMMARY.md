# Auth Guards Patch — Execution Summary (Ban Lockout & Forced Password Change)

**Status:** Implemented per approved plan `plan/12_AUTH_GUARDS_PATCH_PLAN.md`. All 5 files patched; verification ran; one environment limitation documented (no MySQL server in sandbox → DB-backed workflows listed as runbook, not executed).

**Spec anchors:** `docs/1_PRD.md` §7E3 / §D.2; `docs/3_ROADMAP.md` 7E2/7E3.

---

## 1. What Was Changed (git diff highlights)

### `application/config/routes.php` (+1)
```php
$route['auth/change-password'] = 'auth/change_password';
```
Registered after the existing Phase 2 auth routes. Required because `translate_uri_dashes = FALSE` would otherwise treat `change-password` as a literal (invalid) method name.

### `application/controllers/Auth.php` (+61)
1. **`login()` — ban lockout guard** (placed **after** `password_verify` so ban status is not leaked to callers without valid credentials):
   ```php
   if ((int) $user->is_banned === 1) {
       $data['errors'][] = 'Akun Anda telah dinonaktifkan. Silakan hubungi admin.';
       $data['values']   = $this->input->post();
       $this->load->view('auth/login', $data);
       return;
   }
   ```
   No session is written; the login page re-renders with the exact Indonesian error message.
2. **`login()` — forced-change redirect** after session data is set:
   ```php
   if ((int) $user->must_change_password === 1) {
       redirect('auth/change-password');
   }
   redirect('home');
   ```
3. **New `change_password()` (GET + POST):**
   - Self-guard (Auth extends `CI_Controller`, not `MY_Controller`): empty `user_id` → `redirect('login')`; banned user → same kick as MY_Controller (unset `user_id` + flash + redirect).
   - **POST validation:** `new_password` = `required|min_length[8]`, `confirm_password` = `required|matches[new_password]`.
   - On success: `User_model::update_user($user_id, ['password' => password_hash($new, PASSWORD_BCRYPT), 'must_change_password' => 0])` — bcrypt hash + flag cleared in one update (PRD D.2), flash `success` "Kata sandi berhasil diperbarui.", `redirect('home')`.
   - On failure: re-renders view with `form_error()` inline messages.

### `application/core/MY_Controller.php` (+14)
Per-request user-row fetch in the logged-in branch (one extra query, same pattern as the existing per-request `Wallet_model`/`Notification_model` loads):
```php
$this->load->model('User_model');
$user = $this->User_model->get_user_by_id($this->session->userdata('user_id'));

if ($user && (int) $user->is_banned === 1) {
    $this->session->unset_userdata('user_id'); // keep flashdata alive (sess_destroy would kill it)
    $this->session->set_flashdata('error', 'Akun Anda telah dinonaktifkan. Silakan hubungi admin.');
    redirect('login');
}

if ($user && (int) $user->must_change_password === 1) {
    redirect('auth/change-password');
}
```
- Banned-but-logged-in users are kicked on their next request (admin `toggle_ban` takes effect immediately).
- `must_change_password = 1` forces every non-auth page to `/auth/change-password` (PRD: "redirect all requests … until password is updated"). No loop: Auth is exempt from MY_Controller, so the change form itself stays reachable.
- `$user &&` guards preserve pre-existing behavior for the deleted-user edge case.

### `application/views/auth/change_password.php` (new, 4.4 KB)
Standalone mobile-first dark-mode page cloned from `login.php`: `bg-slate-900` shell + branding header + white rounded card. Fields `Kata Sandi Baru` / `Konfirmasi Kata Sandi` (`autocomplete="new-password"`), `form_error` inline + `$errors` + `flashdata('success'|'error')` render blocks, submit "Simpan Kata Sandi". No reCAPTCHA (already authenticated). No value repopulation on password inputs.

### `application/views/auth/login.php` (+7)
Added `flashdata('error')` render block (rose style, same pattern as `views/admin/*`) so the session-kick message is visible after redirect.

---

## 2. Linting Results

AGENTS.md rule (`php -l` on every new/modified PHP file) — **all clean**:

| File | Result |
|------|--------|
| `application/controllers/Auth.php` | No syntax errors detected |
| `application/core/MY_Controller.php` | No syntax errors detected |
| `application/views/auth/change_password.php` | No syntax errors detected |
| `application/views/auth/login.php` | No syntax errors detected |
| `application/config/routes.php` | No syntax errors detected |

Also: `git diff --check` — no whitespace errors.

---

## 3. Verified Workflows

### Executed in this environment
| Check | Method | Result |
|-------|--------|--------|
| Route resolves | CI CLI dispatch: `php -d error_reporting=24575 index.php auth/change-password` | Dispatched into `Auth` controller (reached `Auth::__construct`); control `auth/nonexistent_method` printed `ERROR: Not Found` (exit 4). Route + method proven reachable. |
| Diff integrity | `git diff --check` + full diff review vs plan/12 §2 | All 4 modified files + new view match the approved blueprint exactly. |

> Environment note: the sandbox has **no running MySQL** (no `mysqld`/`mariadbd` binary; `mysqli` connect fails with "No such file or directory") and the `database` library is autoloaded, so every page dies at DB connect. The DB-backed workflows below are therefore a **runbook** to execute on a MySQL-enabled dev box (credentials in `application/config/database.php`, seeded users from `plan/10_DATABASE_SEED_PLAN.md`).

### Runbook — Workflow B: forced password change (user 6 `agent_eka` 087855667788, `must_change_password=1`)
```bash
# 1. login (reCAPTCHA required; RECAPTCHA_SECRET env + valid token) → expect 302 to /auth/change-password
curl -c /tmp/jar -b /tmp/jar -L -s -o /dev/null -w "%{http_code} %{redirect_url}\n" \
  -d "phone=087855667788&password=<temp>&g-recaptcha-response=<token>" http://synapse.test/login

# 2. any other page while flag set → 302 to /auth/change-password
curl -c /tmp/jar -b /tmp/jar -s -o /dev/null -w "%{http_code} %{redirect_url}\n" http://synapse.test/home

# 3. GET form renders (200, contains "Ubah Kata Sandi")
curl -c /tmp/jar -b /tmp/jar -s http://synapse.test/auth/change-password | grep -c "Ubah Kata Sandi"

# 4. mismatched confirm → validation error (form re-rendered, form_error)
# 5. matching POST → 302 home + success flash
curl -c /tmp/jar -b /tmp/jar -s -o /dev/null -w "%{http_code} %{redirect_url}\n" \
  -d "new_password=ABCabc123&confirm_password=ABCabc123" http://synapse.test/auth/change-password

# 6. DB assert flag cleared + bcrypt updated; old temp password must fail, new one must work
mysql -uroot -proot -e "SELECT must_change_password, password FROM db_webtable.users WHERE id=6;"
```

### Runbook — Workflow A: ban lockout (user 13 `leaf_lutfi` 081299001122, `is_banned=1`)
```bash
# 1. login → 200 login page with "Akun Anda telah dinonaktifkan. Silakan hubungi admin.", no user_id session
# 2. session kick: logged-in user gets banned (Admin::toggle_ban), next GET /home → 302 to login + flash error shown
# 3. edge: unauthenticated GET /auth/change-password → 302 to login (self-guard)
```

---

## 4. Files Touched (this patch only)

| File | Change |
|------|--------|
| `application/controllers/Auth.php` | ban check + `must_change_password` redirect in `login()`; new `change_password()` |
| `application/core/MY_Controller.php` | per-request user fetch → ban kick + forced-change redirect |
| `application/views/auth/change_password.php` | **new** standalone dark-mode form view |
| `application/views/auth/login.php` | `flashdata('error')` render block |
| `application/config/routes.php` | `$route['auth/change-password']` |

No model or DB schema changes. Other `M`/`??` entries in `git status` (Admin.php, Admin_model.php, Audit_model, database_seed.sql, plans 8–11, etc.) are pre-existing Phase 10A work, untouched here.

---

## 5. Known Limitations & Notes

- **PHP 8.3 deprecation noise:** this CI3 version emits `Creation of dynamic property ...` deprecations on PHP 8.3 that get displayed in `development` env and break `header()` (redirects). Pre-existing, unrelated to this patch — run dev servers with `CI_ENV=testing` (as done here) or a PHP ≤ 8.2 runtime.
- **DB-backed workflows not executed here** (no MySQL in sandbox) — runbook in §3.
- **Not included (per plan/12 §4):** current-password verification on the change form (spec says new+confirm only), CSRF tokens (off project-wide), brute-force throttling.
- **reCAPTCHA fail-closed:** `Auth::login()` POST cannot be exercised without `RECAPTCHA_SECRET` + a valid Google token — pre-existing design, documented.
