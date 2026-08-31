# Ban UI & Session Kick — Root-Cause Resolution Plan (14)

**Status:** Blueprint — awaiting analysis. No application code modified.

**Stack:** CodeIgniter 3 + PHP 8.x, MySQL (`db_webtable`), mobile-first Tailwind (CDN), Indonesian UI, IDR only.
**Spec anchors:** `docs/1_PRD.md` (ban lockout), `docs/3_ROADMAP.md` 7E2; prior patch `plan/12_AUTH_GUARDS_PATCH_PLAN.md` + `plan/13_AUTH_GUARDS_PATCH_SUMMARY.md`.

---

## 1. Inspection Findings (evidence)

### 1.1 Session-kick code path (static review of working tree)

| # | Claim / suspicion | Actual code | Verdict |
|---|-------------------|-------------|---------|
| 1 | `User_model::get_user_by_id()` returns array vs object mismatch | Line 27: `return $this->db->get_where('users', ['id' => $user_id])->row();` → **object** (`stdClass`) | `->row()` = object; `$user->is_banned` property access in `MY_Controller.php:21` **matches**. No mismatch today. |
| 2 | Reported controllers skip the guard | `Home`, `Wallet`, `Team`, `Rentals`, `Marketplace`, `Help`, `Notification`, `User` all `extends MY_Controller` and call `parent::__construct()` (verified per-file) | Guard **runs** on all 4 reported routes. |
| 3 | Kick logic present in `MY_Controller::__construct` | Lines 16–29: per-request `get_user_by_id()` → `if ($user && (int) $user->is_banned === 1)` → `unset_userdata('user_id')` + `set_flashdata('error', …)` + `redirect('login')` | Present and correct **if** `users.is_banned` exists and holds `1`. |
| 4 | Login rejection + flash render exist | `Auth::login()` ban check after `password_verify` (lines ~157/200); `views/auth/login.php` renders `flashdata('error')` (plan 12/13) | OK — kick message will surface on `/login`. |
| 5 | **Schema drift (top suspect)** | `database.sql` (authoritative schema, per AGENTS.md) `users` DDL (lines 11–28) has **NO** `is_banned`, `must_change_password`, `username`, `role` columns. The columns exist only as `ALTER TABLE` in the **untracked** `database_seed.sql` (lines 18–20) | If the live DB was created from `database.sql` without the seed ALTERs, `$user->is_banned` is an **undefined property → NULL** → `(int) NULL === 0` → **guard silently never fires**. This exactly reproduces "kick ineffective". |
| 6 | **Unguarded user controller** | `Profile.php:4` → `class Profile extends CI_Controller` — no login guard in `index()`, no ban guard | Banned users can still browse `/profile`; logged-out users too. Explicitly in scope of the inspection list. |
| 7 | PHP 8.3 deprecation output | Documented in plan 13 §5: CI3 emits "Creation of dynamic property" deprecations on PHP 8.3; in `development` env they are displayed and **break `header()` (redirects)** | Pre-existing env hazard; can make the kick redirect fail. Mitigation: run dev with `CI_ENV=testing`. Not a code defect of this patch. |
| 8 | Deployed copy stale | `git status` shows `MY_Controller.php` **uncommitted** (plan 12/13 changes not committed) | If UAT hit a deployed build from `HEAD`, the guard is absent entirely → kick never runs. Deployment-sync issue, not code. |

**Root-cause ranking:** ① schema drift (5) — silently disables the guard; ② stale deployed code (8); ③ `/profile` unguarded (6); ④ PHP 8.3 dev-env redirect break (7). The object/array mismatch (1) is **not** the cause, but we harden against it anyway (defense-in-depth, per objective).

### 1.2 Admin ban UI (current state)

| View | Badge | Toggle action |
|------|-------|---------------|
| `views/admin/users.php` | Exists but labels **"Banned" / "Active"** (English, `bg-red-100`/`bg-emerald-100` on light table) | **None** — table has only a "Detail" link. |
| `views/admin/user_detail.php` | Exists, labels **"DIBANNED" / "ACTIVE"** on the `bg-slate-800` header block | **Exists and functional**: POST form → `admin/toggle_ban/{id}` with `confirm()` (lines 105–118). |

Controller/model support is complete and correct:
- `Admin::toggle_ban($id)` (Admin.php:504) — POST-only guard, ACID `toggle_ban` + audit `admin_toggle_ban`, flashdata success/error, redirect back to detail.
- `Admin_model::toggle_ban($id)` (line 243) — flips `users.is_banned` atomically; `get_users()` already SELECTs `u.is_banned` (line 106); `get_user_detail()` returns `u.*`.
- Route: `admin/toggle_ban/{id}` maps via default CI3 routing (no route entry needed).

**Gaps to close:** (a) `users.php` has no Ban/Unban control; (b) badge labels not the spec'd `AKTIF` / `BANNED`; (c) labels/colors must align with the dark-terminal design language (dashboard `bg-slate-900` panels + `emerald-400`/`red-400` accents; user_detail `bg-slate-800` header).

---

## 2. Implementation Blueprint

### PHASE 1 — Session-kick hardening (root cause)

**1a. `application/core/MY_Controller.php` — type-safe user-row normalization (array **and** object)**

Replace the direct property access with a normalization that tolerates both `->row()` (object) and `->row_array()` (array) returns, and missing columns:

```php
$this->load->model('User_model');
$row = (array) $this->User_model->get_user_by_id($this->session->userdata('user_id'));

$is_banned          = (int) ($row['is_banned'] ?? 0);          // object→(array) cast + null-coalescing = type-safe
$must_change_passwd = (int) ($row['must_change_password'] ?? 0);

if ($row && $is_banned === 1) {
    $this->session->unset_userdata('user_id');   // keep flashdata alive — sess_destroy would kill it
    $this->session->set_flashdata('error', 'Akun Anda telah dinonaktifkan. Silakan hubungi admin.');
    redirect('login');
}

if ($row && $must_change_passwd === 1) {
    redirect('auth/change-password');
}
```

Why this is the precise fix:
- `(array) $obj` on a `stdClass` row yields `['is_banned' => '1', …]`; `(array) null` → `[]` (covers deleted-user edge, preserves the old `$user &&` guard semantics).
- `?? 0` makes a **missing column** resolve to `0` (no PHP warning, no crash) — combined with 1c, the schema is guaranteed to have the column, so a missing column can no longer silently disable the guard.
- If a future refactor flips `get_user_by_id()` to `->row_array()`, the guard keeps working unchanged.

**1b. `application/controllers/Profile.php` — close the unguarded route**
- Change `class Profile extends CI_Controller` → `class Profile extends MY_Controller`. Constructor already calls `parent::__construct()`, which then enforces login + ban kick + forced-password-change uniformly. No other change needed (no Auth-page exception applies to Profile).

**1c. `database.sql` — sync authoritative schema (kills the silent no-op)**
- Add to the `users` table DDL (matching `database_seed.sql` lines 18–20):
```sql
`is_banned`            TINYINT(1) NOT NULL DEFAULT 0,
`must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
```
- Note: MySQL 8 has no `ADD COLUMN IF NOT EXISTS`; for **already-provisioned live DBs** the migration path is the existing `database_seed.sql` ALTERs (documented in the verification runbook, step 3). This step makes the source of truth match the live schema and prevents regressions in fresh installs.

**1d. No changes to:** `Auth.php` (login ban check present), `views/auth/login.php` (error-flash block present), `routes.php` (no new route needed), `Admin.php` / `Admin_model.php` (toggle_ban complete).

### PHASE 2 — Admin ban UI (Bloomberg-terminal alignment)

Design language (existing, to match): admin shell = light `bg-slate-50` content + dark terminal panels (`bg-slate-900`/`bg-slate-800`, `border-slate-700`) with `text-emerald-400` / `text-red-400` accents (dashboard, user_detail header). No full admin re-skin — out of scope.

**2a. `views/admin/users.php` — status badge + toggle action column**
- Badge (replace "Banned"/"Active"), spec colors:
  - BANNED: `inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-500 text-white` + `<i class="fas fa-ban"></i> BANNED`
  - AKTIF:  `... bg-emerald-500 text-white` + `<i class="fas fa-check-circle"></i> AKTIF`
- Action control, in the existing "Aksi" cell next to "Detail" (dynamic text + `confirm()`):
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
- CSRF note: `csrf_protection = FALSE` project-wide (config.php:460) — bare POST form is consistent with all existing admin forms; flagged as known limitation.

**2b. `views/admin/user_detail.php` — normalize labels + keep functional toggle**
- Header badge (dark `bg-slate-800` block): relabel `DIBANNED` → **BANNED** (`bg-rose-500/20 text-rose-400`), `ACTIVE` → **AKTIF** (`bg-emerald-500/20 text-emerald-400`). Colors already match the terminal palette.
- Toggle button (exists, lines 105–118): relabel `Ban` → **Blokir Akun** (rose), `Unban` → **Buka Blokir** (emerald); keep POST action + `confirm()` messages; ensure button colors are the solid `bg-rose-600` / `bg-emerald-600` used elsewhere.

### PHASE 3 — Verification protocol

**3a. Syntax lint (roadmap rule — every touched PHP file):**
```bash
php -l application/core/MY_Controller.php
php -l application/controllers/Profile.php
php -l application/views/admin/users.php
php -l application/views/admin/user_detail.php
```
(`database.sql` is not PHP — no lint; verify with `git diff --check`.)

**3b. Schema precondition (one-time, live DB):**
```bash
mysql -uroot -proot -e "SHOW COLUMNS FROM db_webtable.users LIKE 'is_banned'; SHOW COLUMNS FROM db_webtable.users LIKE 'must_change_password';"
# if empty rows → run the ALTERs from database_seed.sql lines 18–20 first
```

**3c. End-to-end browser scenario (admin + victim sessions, two browsers or incognito):**
1. Admin logs in at `/control-panel`; victim logs in at `/login` → lands on `/home`.
2. Admin → User Management (`/admin/users`) → row shows **AKTIF** badge + **"Blokir Akun"** button with confirm popup.
3. Admin clicks **Blokir Akun** → confirm OK → POST `admin/toggle_ban/{id}` → redirect to `/admin/user_detail/{id}` → flash "User berhasil DIBANNED." → badge now **BANNED**, button now **"Buka Blokir"**.
4. **Kick:** with the victim's session cookie, GET `/home` → **302 to `/login`** (guard in MY_Controller) → login page renders "Akun Anda telah dinonaktifkan. Silakan hubungi admin." (flashdata). Repeat for `/wallet`, `/team`, `/rentals`, `/profile` — all 302 to `/login`.
5. Victim attempts login → login page re-renders with the same ban message (Auth::login guard), no session created.
6. Admin clicks **Buka Blokir** → flash "User berhasil di-UNBAN." → badge **AKTIF**.
7. Victim logs in again → 200/302 to `/home`, full access restored.
8. Regression: non-banned user login → `/home` (no redirect loop); admin `/admin/users` table + detail render (200) with new badges/buttons; wallet/notification globals still inject.

---

## 3. Files Touched (implementation phase)

| File | Change |
|------|--------|
| `application/core/MY_Controller.php` | type-safe `(array)` normalization + `?? 0` for ban/forced-change guards (kick + flashdata unchanged) |
| `application/controllers/Profile.php` | `extends MY_Controller` (closes unguarded `/profile`) |
| `application/views/admin/users.php` | AKTIF/BANNED badges + Blokir Akun/Buka Blokir POST control with `confirm()` |
| `application/views/admin/user_detail.php` | relabel badges/buttons to AKTIF/BANNED + Blokir Akun/Buka Blokir (toggle already functional) |
| `database.sql` | `users` DDL += `is_banned`, `must_change_password` (schema sync; live-DB path = seed ALTERs) |

## 4. Decisions & Trade-offs (explicit)

- **Type-safe normalization over object-only access** — defense-in-depth; the current `->row()` object is not the bug, but the guard must not silently no-op on schema drift or a future `row_array()` refactor.
- **`unset_userdata` + flashdata + `redirect('login')` preserved** — flash survives the redirect; the user sees the exact PRD message on the login page. `sess_destroy()` is deliberately avoided.
- **Schema sync limited to the two ban columns** — broader drift (`username`, `role`, `level_id` semantics) is pre-existing and out of scope; noted for a future hygiene pass.
- **Theme = dark-terminal accents on the existing admin shell** (slate-900/800 panels + emerald/rose-400). A full admin dark re-skin is out of scope; say the word if desired.
- **No CSRF token** — consistent with the project (off globally); known limitation, existing behavior.
- **Self-ban guard not added** — `toggle_ban` can target the admin's own `users` row; not in the reported scope, offered as optional hardening.
- **PHP 8.3 dev-env redirect break** is a pre-existing environment hazard (plan 13 §5), not fixed here — run E2E with `CI_ENV=testing` or PHP ≤ 8.2.
