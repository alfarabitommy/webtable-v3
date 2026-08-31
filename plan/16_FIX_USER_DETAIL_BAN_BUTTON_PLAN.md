# Plan 16 — Fix Ban/Unban Toggle Button on Admin User Detail

**Status:** APPROVED — plan file only; application code NOT changed yet (awaiting go signal)
**Scope:** 1 file — `application/views/admin/user_detail.php` (view-only fix; no controller/model/route changes)

---

## 1. Root Cause (Inspection Findings)

### 1.1 The broken markup — `application/views/admin/user_detail.php`, lines 100–119

- Line 61 opens the profile-update form: `form_open('admin/update_user/' . $user->id, 'class="space-y-4"')`, closed at line 121 by `<?= form_close() ?>`.
- Lines 106–118 place the Ban/Unban toggle inside a
  `<form method="POST" action="<?= site_url('admin/toggle_ban/' . $user->id) ?>">`
  that is **nested inside** that outer profile form.
- **HTML forbids nested forms.** Browsers parse the inner `<form>` start tag as a parse error and silently discard the element; the inner submit button then becomes a submit button of the **outer** `update_user` form, and the inner `onsubmit="return confirm(...)"` handler is dropped along with it.
- **Result:** clicking "Blokir Akun" / "Buka Blokir" POSTs to `admin/update_user/{id}` instead of `admin/toggle_ban/{id}`; the ban state never changes and no confirm dialog appears — exactly the reported symptom.

### 1.2 Variable naming — ✅ OK

- `Admin::user_detail($id)` (controller lines 383–410) passes `'user' => $user` (line 397). The view consistently uses `$user->id`, `$user->is_banned`, `$user->username`, etc. No variable rename needed.
- (The list view `application/views/admin/users.php` uses `$u` — a different view, not affected.)

### 1.3 onsubmit / confirm escaping — ✅ OK

- `onsubmit="return confirm('Blokir user ini? User tidak bisa login & sesi aktif akan diakhiri.')"`
- Single-quoted JS string inside a double-quoted HTML attribute; the messages contain no apostrophes. The `&` is followed by a space, so it is a literal ampersand — valid HTML, not an ambiguous-ampersand parse error. (Optionally `&amp;` for hygiene; cosmetic only, not part of this fix.)

### 1.4 Controller `Admin::toggle_ban($id)` — ✅ OK, no changes needed

- Lines 504–535.
- Enforces POST: `if ($this->input->method() !== 'post') { redirect('admin/user_detail/' . $id); }` — so the fix must send a real POST (a plain GET nav to the URL is safely redirected).
- ACID TX: `Admin_model->toggle_ban($id)` (model lines 243–250) flips `is_banned` 1↔0 and returns the new state; `FALSE` → flashdata "User tidak ditemukan."
- Audit log `admin_toggle_ban` (with `new_state`) on success; flashdata success `User berhasil DIBANNED.` / `User berhasil di-UNBAN.`; redirect back to `admin/user_detail/{id}`.
- Routing: no pretty-URL entry needed — CI3 default routing maps `admin/toggle_ban/{id}` → `Admin::toggle_ban({id})` (`routes.php` has no conflicting rule; `translate_uri_dashes` = FALSE).
- Auth guard: controller constructor requires `admin_id` session → redirect `control-panel`.

### 1.5 Working reference (confirms the diagnosis)

- `application/views/admin/users.php` lines 99–108 contain the *same* toggle markup as a **standalone** (non-nested) inline form, and ban/unban works from the list view. The nesting in `user_detail.php` is the only defect.

---

## 2. Patch Blueprint

### 2.1 Fix — un-nest the toggle form (move it outside the profile-update form)

Edit **`application/views/admin/user_detail.php`** only:

1. Close the profile-update form after the "Simpan Profil" button row (move `<?= form_close() ?>` up so it lands right after the save button's flex div — equivalently, restructure so the toggle forms become siblings of the closed form).
2. Keep the two toggle `<form>` blocks byte-identical in markup (POST to `admin/toggle_ban/{id}`, `onsubmit` confirm), but now as **top-level standalone forms**.
3. Wrap them in a spacing container (e.g. `<div class="flex items-center gap-3 pt-2">`) so they render as an action row below "Simpan Profil" within the same card.

**Diff sketch (target structure):**

```diff
             <div class="flex items-center gap-3 pt-2">
                 <button type="submit" class="px-5 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 active:bg-indigo-800 transition-colors flex items-center gap-2">
                     <i class="fas fa-save text-xs"></i> Simpan Profil
                 </button>
+            </div>
 
-                <!-- Ban Toggle -->
-                <?php if ($user->is_banned): ?>
-                    <form method="POST" action="<?= site_url('admin/toggle_ban/' . $user->id) ?>" onsubmit="return confirm('Buka blokir user ini?')">
-                        <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors flex items-center gap-2">
-                            <i class="fas fa-unlock text-xs"></i> Buka Blokir
-                        </button>
-                    </form>
-                <?php else: ?>
-                    <form method="POST" action="<?= site_url('admin/toggle_ban/' . $user->id) ?>" onsubmit="return confirm('Blokir user ini? User tidak bisa login & sesi aktif akan diakhiri.')">
-                        <button type="submit" class="px-4 py-2 rounded-lg bg-rose-600 text-white text-sm font-medium hover:bg-rose-700 transition-colors flex items-center gap-2">
-                            <i class="fas fa-ban text-xs"></i> Blokir Akun
-                        </button>
-                    </form>
-                <?php endif; ?>
-            </div>
-
-        <?= form_close() ?>
+        <?= form_close() ?>
+
+        <!-- Ban Toggle — standalone form; MUST NOT be nested inside another <form> -->
+        <div class="flex items-center gap-3 pt-2">
+            <?php if ($user->is_banned): ?>
+                <form method="POST" action="<?= site_url('admin/toggle_ban/' . $user->id) ?>" onsubmit="return confirm('Buka blokir user ini?')">
+                    <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors flex items-center gap-2">
+                        <i class="fas fa-unlock text-xs"></i> Buka Blokir
+                    </button>
+                </form>
+            <?php else: ?>
+                <form method="POST" action="<?= site_url('admin/toggle_ban/' . $user->id) ?>" onsubmit="return confirm('Blokir user ini? User tidak bisa login & sesi aktif akan diakhiri.')">
+                    <button type="submit" class="px-4 py-2 rounded-lg bg-rose-600 text-white text-sm font-medium hover:bg-rose-700 transition-colors flex items-center gap-2">
+                        <i class="fas fa-ban text-xs"></i> Blokir Akun
+                    </button>
+                </form>
+            <?php endif; ?>
+        </div>
```

**Alternative (only if the exact side-by-side layout must be preserved):** give the profile form an id (`id="form-update-user"` in the `form_open` attributes), close it before the action row, and add `form="form-update-user"` to the "Simpan Profil" submit button so both buttons stay in one flex row. Uses the HTML5 `form` attribute. Not recommended as default — the plain un-nest above is simpler and matches the `users.php` pattern.

### 2.2 Files touched

| File | Change |
|---|---|
| `application/views/admin/user_detail.php` | Move ban-toggle forms out of the profile-update form; no other changes |

No changes to `Admin.php`, `Admin_model.php`, `routes.php`, or `users.php`.

---

## 3. Verification Protocol

### 3.1 Static checks

1. `php -l application/views/admin/user_detail.php` → must report **No syntax errors** (roadmap lint rule).
2. Re-read the section: confirm no `<form>` element remains nested inside the `update_user` form (exactly two toggle forms, both top-level siblings after `form_close()`).

### 3.2 Browser verification (manual, as the tester reported)

1. Log in at `/control-panel` (cloaked admin).
2. `/admin/users` → pick a non-banned test user → `/admin/user_detail/{id}`.
3. Click **"Blokir Akun"**:
   - Confirm dialog appears ("Blokir user ini? …").
   - On OK → DevTools Network tab shows `POST /admin/toggle_ban/{id}` → **302** redirect.
   - After redirect: header badge flips to **BANNED**, green flashdata "User berhasil DIBANNED.", button now reads "Buka Blokir".
4. DB spot-check: `SELECT is_banned FROM users WHERE id = {id}` → `1` (or check the AKTIF/BANNED badge on `/admin/users`).
5. Click **"Buka Blokir"** → symmetric: badge AKTIF, "User berhasil di-UNBAN.", `is_banned` → `0`.
6. Regression on the same page: "Simpan Profil" still POSTs to `update_user` and shows the success flash; `cancel_rental`, `adjust_time` (time travel), `inject_balance`, `inject_rental`, `reset_password` forms still behave as before (they are already standalone).
7. Optional curl (no-JS POST guard check): with the admin session cookie,
   `curl -i -X POST .../admin/toggle_ban/{id}` → 302 back to detail + state flip;
   a plain `GET .../admin/toggle_ban/{id}` → 302 with **no** state change (POST guard works).

### 3.3 "Immediate" feedback

The controller already redirects back to the detail page with flashdata on success, so the badge flips right after the confirm dialog — satisfies "updates the user's ban status immediately."

---

## 4. Rollback

Single-file view-only diff → `git checkout -- application/views/admin/user_detail.php` (or revert the one commit). No data migration; no DB impact.

---

## 5. Notes / out of scope

- The hardcoded reCAPTCHA secret in `Auth.php` is unrelated (see AGENTS.md cleanup flag) — not touched.
- The `&` in the confirm string is valid as-is; no escaping change required.
- Commit message (Indonesian, existing style), e.g.: `fix: pindahkan tombol blokir/unblokir keluar dari form profil di user_detail` — to be applied only after the go signal.
