# Ban UI & Session Kick — Execution Summary (15)

**Status:** Implemented per approved blueprint `plan/14_BAN_UI_AND_SESSION_KICK_PLAN.md`. All 5 files patched; `php -l` clean on every touched PHP file.

**Stack:** CodeIgniter 3 + PHP 8.x, MySQL (`db_webtable`), Tailwind (CDN), Indonesian UI, IDR only.

---

## 1. Root Cause Recap (from plan 14)

- The session-kick guard in `MY_Controller.php` was functionally correct but used object-property access (`$user->is_banned`) on a `->row()` object. It **silently no-ops** when the `users.is_banned` column is missing from the live DB (undefined property → `(int) NULL === 0`), which is exactly the UAT symptom: banned users were never kicked.
- `database.sql` (authoritative schema) lacked `is_banned` / `must_change_password`; they existed only as `ALTER TABLE` statements in the untracked `database_seed.sql`.
- `/profile` was unguarded: `Profile extends CI_Controller` (no login guard, no ban kick).
- Admin UI: `users.php` had no Ban/Unban control; badge labels were English ("Active"/"Banned"); `user_detail.php` used "ACTIVE"/"DIBANNED" and "Ban"/"Unban" — not the spec labels.

---

## 2. What Was Changed (git diffs)

### `application/core/MY_Controller.php` (+20)

Type-safe user-row normalization — tolerates both `->row()` (object) and `->row_array()` (array) model returns; missing columns resolve to `0` instead of silently disabling the guard:

```php
$this->load->model('User_model');
$row = (array) $this->User_model->get_user_by_id($this->session->userdata('user_id'));

$is_banned          = (int) ($row['is_banned'] ?? 0);
$must_change_passwd = (int) ($row['must_change_password'] ?? 0);

if ($row && $is_banned === 1) {
    $this->session->unset_userdata('user_id'); // keep flashdata alive (sess_destroy would kill it)
    $this->session->set_flashdata('error', 'Akun Anda telah dinonaktifkan. Silakan hubungi admin.');
    redirect('login');
}

if ($row && $must_change_passwd === 1) {
    redirect('auth/change-password');
}
```

### `application/controllers/Profile.php` (1 line)

```diff
-class Profile extends CI_Controller {
+class Profile extends MY_Controller {
```

Closes the unguarded `/profile` route: the parent constructor now enforces the login guard, ban kick, and forced password-change redirect.

### `application/views/admin/users.php` (±28)

- Badges: `Active` (emerald-100) → **AKTIF** (`bg-emerald-500 text-white`); `Banned` (red-100) → **BANNED** (`bg-rose-500 text-white`).
- Aksi column: added an inline POST form next to the Detail link:

```html
<form method="POST" action="<?= site_url('admin/toggle_ban/' . $u->id) ?>"
      onsubmit="return confirm('<?= $u->is_banned ? 'Buka blokir user ini?' : 'Blokir user ini? User tidak bisa login & sesi aktif akan diakhiri.' ?>')"
      class="inline">
    <button type="submit"
            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors
                   <?= $u->is_banned ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-rose-600 text-white hover:bg-rose-700' ?>">
        <i class="fas <?= $u->is_banned ? 'fa-unlock' : 'fa-ban' ?> text-[10px]"></i>
        <?= $u->is_banned ? 'Buka Blokir' : 'Blokir Akun' ?>
    </button>
</form>
```

### `application/views/admin/user_detail.php` (±16)

- Header badge (dark `bg-slate-800` block): `DIBANNED` → **BANNED** (`bg-rose-500/20 text-rose-400`), `ACTIVE` → **AKTIF** (`bg-emerald-500/20 text-emerald-400`).
- Toggle buttons: `Ban` → **Blokir Akun** (`bg-rose-600`), `Unban` → **Buka Blokir** (`bg-emerald-600`); confirm messages updated to Indonesian ("Buka blokir user ini?" / "Blokir user ini? User tidak bisa login & sesi aktif akan diakhiri.").

### `database.sql` (+2)

Authoritative `users` schema now matches the live seed schema:

```sql
  `level_id` INT NOT NULL DEFAULT 0,
  `is_banned` TINYINT(1) NOT NULL DEFAULT 0,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
```

> Live DBs that predate this change must run the existing `database_seed.sql` ALTERs (lines 18–20) — MySQL 8 has no `ADD COLUMN IF NOT EXISTS`.

---

## 3. Linting Results (roadmap rule — every new/modified PHP file)

```bash
$ php -l application/core/MY_Controller.php && php -l application/controllers/Profile.php && php -l application/views/admin/users.php && php -l application/views/admin/user_detail.php
```

| File | Result |
|------|--------|
| `application/core/MY_Controller.php` | No syntax errors detected |
| `application/controllers/Profile.php` | No syntax errors detected |
| `application/views/admin/users.php` | No syntax errors detected |
| `application/views/admin/user_detail.php` | No syntax errors detected |

`git diff --check` — clean (no whitespace errors).

---

## 4. Manual UAT Runbook

**Precondition — schema (one-time, live DB):**
```bash
mysql -uroot -proot -e "SHOW COLUMNS FROM db_webtable.users LIKE 'is_banned'; SHOW COLUMNS FROM db_webtable.users LIKE 'must_change_password';"
# empty rows → run ALTERs from database_seed.sql lines 18–20 first
```

**E2E scenario (admin + victim sessions — two browsers or incognito):**
1. Admin logs in at `/control-panel`; victim logs in at `/login` → lands on `/home`.
2. Admin → User Management (`/admin/users`) → victim row shows **AKTIF** badge + **"Blokir Akun"** button (confirm popup).
3. Admin clicks **Blokir Akun** → OK → POST `admin/toggle_ban/{id}` → redirect to `/admin/user_detail/{id}` → flash "User berhasil DIBANNED." → badge **BANNED**, button now **"Buka Blokir"**.
4. **Kick:** victim's session cookie, GET `/home` → **302 to `/login`** → login page renders "Akun Anda telah dinonaktifkan. Silakan hubungi admin." (flashdata). Repeat for `/wallet`, `/team`, `/rentals`, `/profile` → all 302 to `/login`.
5. Victim attempts login → login page re-renders with the same ban message; no session created.
6. Admin clicks **Buka Blokir** → flash "User berhasil di-UNBAN." → badge **AKTIF**.
7. Victim logs in again → lands on `/home`, full access restored.
8. Regression: non-banned user login → `/home` (no redirect loop); `/admin/users` + `/admin/user_detail` render (200) with new badges/buttons; wallet/notification globals still inject.

> Environment note: run with `CI_ENV=testing` (or PHP ≤ 8.2) — the pre-existing PHP 8.3 deprecation output in `development` env breaks `header()` redirects (documented in plan 13 §5).

---

## 5. Files Touched

| File | Change |
|------|--------|
| `application/core/MY_Controller.php` | type-safe `(array)` normalization + `?? 0`; ban kick + forced-change redirect |
| `application/controllers/Profile.php` | `extends MY_Controller` |
| `application/views/admin/users.php` | AKTIF/BANNED badges + Blokir Akun/Buka Blokir POST control with `confirm()` |
| `application/views/admin/user_detail.php` | labels normalized to AKTIF/BANNED + Blokir Akun/Buka Blokir |
| `database.sql` | `users` DDL += `is_banned`, `must_change_password` |

## 6. Known Limitations (unchanged from plan 14)

- No CSRF token on the toggle forms — consistent with the project (`csrf_protection = FALSE` globally).
- No self-ban guard — `toggle_ban` can target the admin's own `users` row (offered as optional hardening).
- Theme = dark-terminal accents on the existing admin shell; no full admin re-skin.
