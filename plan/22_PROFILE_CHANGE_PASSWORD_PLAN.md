# Voluntary Profile Password Change — Architectural Blueprint (22)

**Status:** Blueprint — PLAN MODE. No application code modified.
**Stack:** CodeIgniter 3 + PHP 8.x, MySQL (`db_webtable`), mobile-first Tailwind (CDN), Indonesian UI, IDR only.
**Spec anchors:** `docs/1_PRD.md` (password rules, min 8 chars), `docs/3_ROADMAP.md` 7E2/7E3 (forced reset flow exists at `auth/change-password`); this plan adds the **voluntary** counterpart reachable from `/profile`, per system task.

---

## 1. Inspection Findings (evidence)

| # | File | Current state | Implication |
|---|------|---------------|-------------|
| 1 | `application/views/profile/index.php` | "Keamanan & Sandi" menu item is a `<button id="btn-change-password">` (lines 110–120) that opens a **placeholder bottom-sheet modal** (`#changePasswordModal`, lines 230–262) with text *"Fitur ubah sandi akan segera tersedia."* | Placeholder must become a direct link to the new page; modal + its JS wiring become dead code and should be removed. |
| 2 | `application/controllers/Profile.php` | `class Profile extends MY_Controller` (Plan 14 fix); methods `index()`, `update()`, `avatar_delete()`. No password method. Loads `User_model`. | Extends `MY_Controller` → login guard, ban kick, and forced-password-change redirect already enforced for free. New method slots in here. |
| 3 | `application/config/routes.php` | `$route['auth/change-password'] = 'auth/change_password';` exists (line 10). Profile routes exist for `profile`, `profile/update`, `profile/avatar_delete` (lines 20–22). | Add `$route['profile/change-password'] = 'profile/change_password';` — same dash→underscore pattern, `translate_uri_dashes = FALSE`. |
| 4 | `application/models/User_model.php` | `update_user($user_id, $data)` (line 64) and `get_user_by_id()` exist; `users.password` stores `password_hash(..., PASSWORD_BCRYPT)` (see `Auth::register` line 121). | No model changes needed — reuse `update_user`; verify with `password_verify()`. |
| 5 | `application/controllers/Auth.php::change_password()` (lines 227–268) | Forced flow: rules `new_password` (required\|min_length[8]) + `confirm_password` (required\|matches[new_password]); on success **clears `must_change_password`** and redirects to `home`. Does **not** verify the current password. | Voluntary flow differs in 2 ways: (a) adds `current_password` verified via `password_verify()`; (b) must **NOT** touch `must_change_password` (only the forced flow clears it — users under forced reset are intercepted by `MY_Controller` and never reach `/profile`). |
| 6 | `application/views/auth/change_password.php` | Reference form (standalone dark page, `form_open('auth/change-password')`, `form_error()` inline under each field, `autocomplete="new-password"`). | Styling inspiration; new view must instead use the **user shell** (`templates/header.php` + `templates/bottom_nav.php`) like `wallet/withdraw.php`. |
| 7 | `application/views/wallet/withdraw.php` | Canonical user-shell subpage: back-button header (`fa-arrow-left` → `base_url('wallet')`), flashdata success/error blocks, `form_open(...)`, `$page_title` from controller. | Direct layout template for the new view. |
| 8 | `application/config/autoload.php` | `form_validation` library and `url`, `file`, `form`, `security` helpers autoloaded. | No loading code needed in the controller. |
| 9 | `application/config/config.php` | `csrf_protection = FALSE` (line 460). | `form_open()` works as-is; if CSRF is ever enabled, `form_open()` auto-injects the token — no extra work. |

---

## 2. Implementation Blueprint

### 2.1 Route — `application/config/routes.php`

Add to the "Profile" block (after line 22):

```php
$route['profile/change-password'] = 'profile/change_password';
```

Clean URL: `/profile/change-password` → `Profile::change_password()`. Mirrors the existing `auth/change-password` pattern.

### 2.2 Controller — `application/controllers/Profile.php`

Add one public method (GET + POST combined, CI3 style):

```php
// ─── VOLUNTARY CHANGE PASSWORD ────────────────────────
public function change_password() {
    $user_id = $this->session->userdata('user_id');
    $user    = $this->User_model->get_user_by_id($user_id);   // non-null: MY_Controller guard passed

    $data['errors'] = [];

    if ($this->input->post()) {
        $this->form_validation->set_rules(
            'current_password',
            'Kata Sandi Saat Ini',
            'required|callback__verify_current_password'
        );
        $this->form_validation->set_rules('new_password', 'Kata Sandi Baru', 'required|min_length[8]');
        $this->form_validation->set_rules(
            'confirm_password',
            'Konfirmasi Kata Sandi',
            'required|matches[new_password]'
        );

        if ($this->form_validation->run()) {
            $updated = $this->User_model->update_user($user_id, [
                'password' => password_hash($this->input->post('new_password', TRUE), PASSWORD_BCRYPT),
            ]);

            if ($updated) {
                $this->session->set_flashdata('success', 'Kata sandi berhasil diperbarui.');
                redirect('profile');
            }

            $data['errors'][] = 'Gagal memperbarui kata sandi. Silakan coba lagi.';
        }
    }

    $data['values'] = $this->input->post();
    $this->load->view('templates/header', ['page_title' => 'Ubah Kata Sandi']);
    $this->load->view('profile/change_password', $data);
    $this->load->view('templates/bottom_nav');
}

// Form-validation callback: verifies current password against stored bcrypt hash
public function _verify_current_password($current_password) {
    $user = $this->User_model->get_user_by_id($this->session->userdata('user_id'));
    if ($user && password_verify($current_password, $user->password)) {
        return TRUE;
    }
    $this->form_validation->set_message('_verify_current_password', 'Kata sandi saat ini salah.');
    return FALSE;
}
```

Design decisions & rationale:
- **`callback__verify_current_password`** — CI3 callback rule runs inside `form_validation->run()`, so a wrong current password renders as an inline `form_error('current_password')` under the field (specific error, per requirement) and prevents the update. Uses the same `password_verify()` + stored-bcrypt check as `Auth::login()`.
- **No `must_change_password` write** — voluntary flow must never clear the forced-reset flag; users with the flag set are intercepted by `MY_Controller` (`redirect('auth/change-password')`) before this page loads.
- **Redirect `profile` on success** with flash "Kata sandi berhasil diperbarui." — exact copy from the forced flow (`Auth::change_password`), per requirement.
- **Security hygiene**: `PASSWORD_BCRYPT` hashing identical to registration; input sanitized via `$this->input->post(..., TRUE)` (XSS filter); no plaintext ever logged/stored. Banned users and unauthenticated access are already blocked by `MY_Controller::__construct`.
- **No reCAPTCHA / rate limit** — this is a session-authenticated, logged-in action (unlike public login/register), so the existing login brute-force limiter already bounds guessing; rate-limiting the authenticated surface is out of scope (note in §5).

### 2.3 View — `application/views/profile/change_password.php` (new)

User-shell partial (loaded between `templates/header.php` and `templates/bottom_nav.php`), modeled on `wallet/withdraw.php`:

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');
?>

<div class="p-4 space-y-6">
    <!-- Back header -->
    <div class="flex items-center gap-3 mb-6">
        <a href="<?= base_url('profile'); ?>" class="w-8 h-8 bg-white border border-slate-200 rounded-full flex items-center justify-center text-slate-500 shadow-sm active:scale-90 transition-all">
            <i class="fas fa-arrow-left text-xs"></i>
        </a>
        <h2 class="text-xl font-extrabold text-slate-900 tracking-tight"><?= $page_title ?></h2>
    </div>

    <!-- Flash: success / error -->
    <?php if ($this->session->flashdata('success')): ?>
        <div class="bg-emerald-100 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl text-xs font-bold flex items-center gap-3 shadow-sm">
            <i class="fas fa-check-circle"></i><?= $this->session->flashdata('success'); ?>
        </div>
    <?php endif; ?>
    <?php if ($this->session->flashdata('error')): ?>
        <div class="bg-rose-100 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-xs font-bold flex items-center gap-3 shadow-sm">
            <i class="fas fa-exclamation-circle"></i><?= $this->session->flashdata('error'); ?>
        </div>
    <?php endif; ?>

    <!-- General errors (DB-level failures) -->
    <?php if (!empty($errors)): foreach ($errors as $e): ?>
        <div class="bg-rose-100 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-xs font-bold"><?= $e ?></div>
    <?php endforeach; endif; ?>

    <!-- Form -->
    <div class="bg-white rounded-2xl p-5 shadow-sm space-y-5">
        <div class="text-center">
            <div class="w-14 h-14 rounded-full bg-amber-50 flex items-center justify-center mx-auto mb-3">
                <i class="fas fa-lock text-xl text-amber-400"></i>
            </div>
            <p class="text-xs text-slate-400">Gunakan minimal 8 karakter untuk kata sandi baru Anda.</p>
        </div>

        <?= form_open('profile/change-password') ?>

            <div>
                <label for="current_password" class="block text-xs font-bold text-slate-500 mb-1.5 uppercase tracking-wide">Kata Sandi Saat Ini</label>
                <input type="password" id="current_password" name="current_password" required
                       autocomplete="current-password" placeholder="Masukkan kata sandi lama"
                       class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm font-medium text-slate-900 outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <?= form_error('current_password', '<p class="mt-1 text-xs text-rose-500 font-semibold">', '</p>') ?>
            </div>

            <div>
                <label for="new_password" class="block text-xs font-bold text-slate-500 mb-1.5 uppercase tracking-wide">Kata Sandi Baru</label>
                <input type="password" id="new_password" name="new_password" required minlength="8"
                       autocomplete="new-password" placeholder="Minimal 8 karakter"
                       class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm font-medium text-slate-900 outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <?= form_error('new_password', '<p class="mt-1 text-xs text-rose-500 font-semibold">', '</p>') ?>
            </div>

            <div>
                <label for="confirm_password" class="block text-xs font-bold text-slate-500 mb-1.5 uppercase tracking-wide">Konfirmasi Kata Sandi Baru</label>
                <input type="password" id="confirm_password" name="confirm_password" required
                       autocomplete="new-password" placeholder="Ulangi kata sandi baru"
                       class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm font-medium text-slate-900 outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <?= form_error('confirm_password', '<p class="mt-1 text-xs text-rose-500 font-semibold">', '</p>') ?>
            </div>

            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold py-3.5 rounded-xl transition flex items-center justify-center gap-2">
                <i class="fas fa-check"></i> Simpan Kata Sandi
            </button>

        <?= form_close() ?>
    </div>
</div>
```

### 2.4 Update — `application/views/profile/index.php`

1. **Replace the "Keamanan & Sandi" `<button id="btn-change-password">`** (lines 110–120) with an equivalent `<a>` keeping identical visual markup (amber lock icon, chevron):

```html
<a href="<?= site_url('profile/change-password') ?>"
   class="flex items-center justify-between px-5 py-4 hover:bg-slate-50 transition border-b border-slate-50 active:scale-[0.98]">
    <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
            <i class="fas fa-lock text-sm"></i>
        </div>
        <span class="text-sm font-medium text-slate-700">Keamanan & Sandi</span>
    </div>
    <i class="fas fa-chevron-right text-[10px] text-slate-300"></i>
</a>
```

2. **Remove dead placeholder code** (directly orphaned by the change):
   - The `#changePasswordModal` block (lines 230–262).
   - The change-password IIFE in the JS (lines 373–385).
   - **Keep** `openSheet()`/`closeSheet()` helpers and the edit-profile wiring — they are shared with the Edit Profil modal. (`#btn-close-cp` / `#cp-backdrop` references disappear with the modal.)

### 2.5 Model / DB / config

- **No changes**: `User_model::update_user()` covers the write; `users.password` already exists; no schema change; `form_validation` autoloaded; CSRF disabled (and `form_open()` handles the token if ever enabled).

---

## 3. Flow Summary

```
/profile (Menu "Keamanan & Sandi" → <a>)
   └─ GET /profile/change-password  → Profile::change_password()  [MY_Controller: login+ban+forced-flag guards]
        └─ POST (form_open, 3 fields)
             ├─ CI3 form_validation:
             │    current_password : required + callback__verify_current_password (password_verify vs users.password)
             │    new_password     : required | min_length[8]
             │    confirm_password : required | matches[new_password]
             ├─ FAIL → re-render view, inline form_error() per field (HTTP 200)
             └─ PASS → update_user(id, ['password' => password_hash(new, PASSWORD_BCRYPT)])
                        flashdata success "Kata sandi berhasil diperbarui."
                        redirect('profile')   [302]
```

---

## 4. Verification Protocol

| # | Test | Steps | Expected |
|---|------|-------|----------|
| 0 | Syntax lint (roadmap rule) | `php -l application/controllers/Profile.php`; `php -l application/views/profile/index.php`; `php -l application/views/profile/change_password.php`; `php -l application/config/routes.php` | `No syntax errors detected` |
| 1 | Auth guard | `curl` GET `/profile/change-password` logged out | 302 → `/login` (MY_Controller) |
| 2 | GET renders | Logged-in: GET `/profile/change-password` | HTTP 200, 3 password fields, back link to `/profile` |
| 3 | Wrong current password | POST `current_password=wrong`, valid new/confirm | 200, inline `form_error('current_password')` = "Kata sandi saat ini salah."; DB hash unchanged |
| 4 | Mismatched confirm | POST valid current + new, different confirm | 200, inline error under `confirm_password` (matches rule); DB hash unchanged |
| 5 | Short new password | POST new_password=7 chars | 200, inline error under `new_password` (min_length) |
| 6 | Happy path | POST valid 3 fields | 302 → `/profile`, flash "Kata sandi berhasil diperbarui."; `users.password` now verifies against new value |
| 7 | Next login | `logout` → `login` with new password | Login succeeds (`Auth::login` `password_verify` vs new hash); old password fails |
| 8 | Forced-flag isolation | User with `must_change_password=1` | `MY_Controller` still redirects to `/auth/change-password`; `/profile/change-password` unreachable until flag cleared |

DB assertion for tests 3–6: `SELECT password FROM users WHERE id=<id>` → `password_verify($old, $hash)` / `password_verify($new, $hash)` as appropriate.

---

## 5. Risks / Notes

- **Auth rate-limit boundary**: the authenticated change-password action is not rate-limited (only public login/register are). The existing per-IP login limiter bounds credential guessing at the login door; adding a limiter to this surface is a possible follow-up, not part of this scope.
- **`must_change_password` isolation** is deliberate: only `Auth::change_password()` (forced flow) clears the flag. Do not reuse `Auth::change_password` for the voluntary flow.
- **Callback visibility**: CI3 callback rules require the method to be `public` on the controller. `_verify_current_password` is intentionally public for that reason.
- **Stale placeholder UI**: if the modal/JS cleanup (§2.4 step 2) is skipped, the page still works but carries dead code (`#changePasswordModal`, orphan IIFE) — include it to keep the diff clean.
- **CSS class reuse**: input/button classes copied verbatim from `profile/index.php` edit sheet and `wallet/withdraw.php` to preserve the mobile design language.
- All flash strings in Indonesian; all money/UI conventions unchanged.
