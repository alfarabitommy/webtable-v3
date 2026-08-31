# Plan 17 — Execution Summary: Fix Ban/Unban Toggle Button on Admin User Detail

**Status:** ✅ EXECUTED & VERIFIED (lint + structure). UAT (manual browser) pending by tester.
**File changed:** `application/views/admin/user_detail.php` (view-only, 1 file)
**Plan reference:** `plan/16_FIX_USER_DETAIL_BAN_BUTTON_PLAN.md` (approved)

---

## 1. Root Cause (recap)

The Ban/Unban toggle in `application/views/admin/user_detail.php` was a
`<form method="POST" action=".../admin/toggle_ban/{id}">` **nested inside** the
profile-update form (`form_open('admin/update_user/' . $user->id)` at line 61,
`form_close()` at old line 121). HTML forbids nested forms — browsers silently
drop the inner `<form>` element and its `onsubmit` handler, so the button
submitted the **outer** `update_user` form instead of `admin/toggle_ban/{id}`.
The ban state never changed and no confirm dialog appeared.

## 2. Change Applied

In `application/views/admin/user_detail.php`:

1. Closed the profile-update form **immediately after** the "Simpan Profil"
   button (the save button's flex container is now closed with `</div>`, then
   `<?= form_close() ?>` at line 106).
2. Moved the entire Ban/Unban toggle block **outside** the profile form as a
   standalone sibling container (`<div class="flex items-center gap-3 pt-2">`)
   directly below it.
3. Preserved all styling and behavior byte-for-byte:
   - POST action `site_url('admin/toggle_ban/' . $user->id)` (both branches)
   - Confirm dialogs: `Buka blokir user ini?` / `Blokir user ini? User tidak bisa login & sesi aktif akan diakhiri.`
   - Button classes `bg-emerald-600` (unban) / `bg-rose-600` (ban) and icons `fa-unlock` / `fa-ban`

### 2.1 Git diff highlights (against HEAD)

```diff
@@ -101,24 +101,26 @@
                 <button type="submit" ...>
                     <i class="fas fa-save text-xs"></i> Simpan Profil
                 </button>
-
-                <!-- Ban Toggle -->
-                <?php if ($user->is_banned): ?>
-                    <form method="POST" action="<?= site_url('admin/toggle_ban/' . $user->id) ?>" onsubmit="return confirm('Unban user ini?')">
-                        <button type="submit" class="... bg-emerald-600 ...">... Unban</button>
-                    </form>
-                <?php else: ?>
-                    <form method="POST" action="<?= site_url('admin/toggle_ban/' . $user->id) ?>" onsubmit="return confirm('BANNED user ini? ...')">
-                        <button type="submit" class="... bg-red-600 ...">... Ban</button>
-                    </form>
-                <?php endif; ?>
             </div>
 
         <?= form_close() ?>
+
+        <!-- Ban Toggle — standalone form; MUST NOT be nested inside another <form> -->
+        <div class="flex items-center gap-3 pt-2">
+            <?php if ($user->is_banned): ?>
+                <form method="POST" action="<?= site_url('admin/toggle_ban/' . $user->id) ?>" onsubmit="return confirm('Buka blokir user ini?')">
+                    <button type="submit" class="... bg-emerald-600 ...">... Buka Blokir</button>
+                </form>
+            <?php else: ?>
+                <form method="POST" action="<?= site_url('admin/toggle_ban/' . $user->id) ?>" onsubmit="return confirm('Blokir user ini? User tidak bisa login & sesi aktif akan diakhiri.')">
+                    <button type="submit" class="... bg-rose-600 ...">... Blokir Akun</button>
+                </form>
+            <?php endif; ?>
+        </div>
     </div>
```

> **Note:** the full `git diff` also shows pre-existing working-tree changes
> (header badge text `BANNED`/`AKTIF`, rose badge colors, and the Indonesian
> confirm strings/labels) that were already on disk **before** this task's edit
> (verified during plan-mode inspection). Those were NOT modified by this fix —
> this fix is exclusively the structural un-nesting of the toggle forms.

## 3. Verification Performed

### 3.1 Syntax lint (roadmap rule)

```
$ php -l application/views/admin/user_detail.php
No syntax errors detected in application/views/admin/user_detail.php
```

### 3.2 No nested `<form>` remains — structural check

Form tags present in the file, in order:

| Line | Tag | Form |
|---|---|---|
| 61  | `<?= form_open('admin/update_user/...') ?>` | profile update |
| 106 | `<?= form_close() ?>` | profile update — **no `<form>` between 61 and 106** |
| 111 | `<form ... admin/toggle_ban/...>` (is_banned) | toggle — standalone sibling |
| 115 | `</form>` | |
| 117 | `<form ... admin/toggle_ban/...>` (else) | toggle — standalone sibling |
| 121 | `</form>` | |
| 143/170 | `inject_balance` | standalone (unchanged) |
| 263/267 | `cancel_rental` | standalone (unchanged) |
| 284/300 | `adjust_time` | standalone (unchanged) |
| 324/346 | `inject_rental` | standalone (unchanged) |
| 401/416 | `reset_password` | standalone (unchanged) |

**Conclusion:** the profile form now contains zero nested `<form>` elements; the
two toggle forms are top-level siblings after `form_close()`.

## 4. Manual UAT Steps (for tester)

1. Log in at `/control-panel` (cloaked admin).
2. `/admin/users` → pick a **non-banned** test user → `/admin/user_detail/{id}`.
3. Click **"Blokir Akun"**:
   - Confirm dialog appears: `Blokir user ini? User tidak bisa login & sesi aktif akan diakhiri.`
   - On OK → DevTools Network tab shows `POST /admin/toggle_ban/{id}` → **302**.
   - After redirect: header badge flips to **BANNED**, green flashdata `User berhasil DIBANNED.`, button now reads **"Buka Blokir"** (emerald, `fa-unlock`).
4. DB spot-check: `SELECT is_banned FROM users WHERE id = {id};` → `1`.
5. Click **"Buka Blokir"** → symmetric: badge **AKTIF**, flashdata `User berhasil di-UNBAN.`, `is_banned` → `0`.
6. Regression on the same page: "Simpan Profil" still POSTs to `update_user` with success flash; `inject_balance`, `cancel_rental`, `adjust_time` (time travel), `inject_rental`, `reset_password` still work.
7. Optional no-JS check: `GET /admin/toggle_ban/{id}` → 302 with **no** state change (POST guard); `curl -X POST` with admin session cookie → 302 + state flip.

## 5. Notes / Out of Scope

- No changes to `Admin.php`, `Admin_model.php`, `routes.php`, or `users.php` — controller/model logic was already correct.
- No commit created (not requested); when committing, use Indonesian message per repo style, e.g. `fix: pindahkan tombol blokir/unblokir keluar dari form profil di user_detail`.
- The pre-existing working-tree badge/label changes (see §2.1 note) are unrelated to this fix.
