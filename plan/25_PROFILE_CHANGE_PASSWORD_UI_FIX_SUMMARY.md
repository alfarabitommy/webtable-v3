# Profile Change Password — UI Spacing & Localization Fix Summary (25)

**Status:** ✅ IMPLEMENTED & LINT-CLEAN — Plan `plan/24_PROFILE_CHANGE_PASSWORD_UI_FIX_PLAN.md` executed in full.
**Scope:** 2 files — `application/controllers/Profile.php` (localized validation messages) and `application/views/profile/change_password.php` (form spacing + bottom padding + refined error containers). No routes/models/DB changes.

---

## 1. Files Changed

| File | Change |
|------|--------|
| `application/controllers/Profile.php` | +9: `set_message([...])` block for `required`, `min_length`, `matches` inside `change_password()` (inserted after `set_rules()`, before `run()`) |
| `application/views/profile/change_password.php` | 5 edits: `pb-24` on outer wrapper; `['class' => 'space-y-4']` on `form_open()`; `mt-1` → `mt-1.5` on all 3 inline `form_error()` wrappers |

`git diff --stat` (tracked): `application/controllers/Profile.php | 56 ++++++++++++++++++++++++++++` (includes the uncommitted phase-22 method — see §5); the view file is untracked (`??`) from the previous phase, 4,425 bytes.

---

## 2. Implementation Highlights

### Controller (`Profile.php::change_password()`)

Inserted after the three `set_rules()` calls, before `$this->form_validation->run()`:

```php
$this->form_validation->set_message([
    'required'   => '{field} wajib diisi.',
    'min_length' => '{field} minimal {param} karakter.',
    'matches'    => '{field} tidak cocok dengan {param}.',
]);
```

- CI3 substitutes `{field}` / `{param}` at render time; for `matches`, `{param}` resolves to the **label** of `new_password` ("Kata Sandi Baru").
- Rendered examples: `Kata Sandi Baru minimal 8 karakter.` · `Konfirmasi Kata Sandi tidak cocok dengan Kata Sandi Baru.` · `Kata Sandi Saat Ini wajib diisi.`
- `_verify_current_password` message ("Kata sandi saat ini salah.") was already Indonesian — untouched.

### View (`profile/change_password.php`)

1. **Outer wrapper** — `<div class="p-4 space-y-6">` → `<div class="p-4 space-y-6 pb-24">` (matches sibling `profile/index.php`; clears the fixed bottom nav on short viewports).
2. **Form element** — `<?= form_open('profile/change-password') ?>` → `<?= form_open('profile/change-password', ['class' => 'space-y-4']) ?>` (root cause of the collapse: the card's `space-y-5` only spaced the card's direct children, never the form's inner field divs; `space-y-4` now gives each field block a fixed 1rem rhythm whether or not an error renders).
3. **Inline error containers** — all three `form_error()` wrappers: `mt-1` → `mt-1.5`:
   ```php
   <?= form_error('current_password', '<p class="mt-1.5 text-xs text-rose-500 font-semibold">', '</p>') ?>
   <?= form_error('new_password',     '<p class="mt-1.5 text-xs text-rose-500 font-semibold">', '</p>') ?>
   <?= form_error('confirm_password', '<p class="mt-1.5 text-xs text-rose-500 font-semibold">', '</p>') ?>
   ```
   Labels (`mb-1.5`) and all other markup/classes kept verbatim — no reserved error space, so the normal state has no awkward gaps.

---

## 3. Verification — Syntax Lint (roadmap rule)

```
$ php -l application/controllers/Profile.php
No syntax errors detected in application/controllers/Profile.php
$ php -l application/views/profile/change_password.php
No syntax errors detected in application/views/profile/change_password.php
```

Final file inspection confirmed all 5 targeted edits present and no unintended changes (labels, button, flash blocks, hierarchy untouched).

---

## 4. Manual UAT / Visual Verification Checks

> Setup: local dev `php -S localhost:8080` from project root; log in as a normal user; open `/profile/change-password` (via Profile → "Keamanan & Sandi").

| # | Scenario | Checks |
|---|----------|--------|
| 1 | Normal state, 375×667 (mobile) | 3 fields with visible **~16px vertical gaps**; labels ~6px above inputs; no horizontal overflow; submit button fully visible above bottom nav (`pb-24`); card centered in the `max-w-[480px]` shell |
| 2 | Single error state, 375×667 | Inline Indonesian error under the offending field; gap to the next field's label intact — **error no longer touches the label**; page grows gracefully, no bottom-nav overlap |
| 3 | All-errors state, 375×667 | Three inline errors, one per field — `Kata Sandi Saat Ini wajib diisi.`, `Kata Sandi Baru wajib diisi.`, `Konfirmasi Kata Sandi wajib diisi.` (no English "field is required"); uniform 1rem field rhythm preserved |
| 4 | min_length, 375×667 | `new_password` < 8 chars → `Kata Sandi Baru minimal 8 karakter.` |
| 5 | matches, 375×667 | mismatched confirm → `Konfirmasi Kata Sandi tidak cocok dengan Kata Sandi Baru.` |
| 6 | callback, 375×667 | wrong `current_password` → `Kata sandi saat ini salah.`; DB hash unchanged |
| 7 | Max shell, 480×800 | Same spacing/error checks at shell max width; page centered on desktop; no scroll-jump |
| 8 | Happy path | valid 3 fields → 302 to `/profile` with green flash "Kata sandi berhasil diperbarui."; re-login with the new password succeeds |

**Pass criteria:** in every error state the error text never touches the adjacent label/input, and field-to-field spacing stays uniform — guaranteed by `space-y-4` + `mt-1.5`.

---

## 5. Notes / Residuals

- **Working tree**: branch `fase-10b-rate-limiting` carries unrelated uncommitted work; `Profile.php`'s diff (+56) includes the previously-uncommitted phase-22 `change_password()` method. This fix's own delta is scoped to the two target files and the documented lines only.
- **Localization scope**: `set_message()` is per-run — no global language file was added, so `Auth` and other forms are unaffected. A shared `application/language/indonesian/form_validation_lang.php` remains a possible follow-up.
- **No JS / build step**: pure Tailwind class + PHP string changes; Tailwind CDN JIT-compiles `space-y-4`/`pb-24`/`mt-1.5` at runtime.
- Password fields are never repopulated with `value=` on validation failure (no leakage risk).
- Suggested commit (Indonesian, existing style): `fix(profile): perbaiki jarak form & lokalisasi pesan validasi ubah sandi`
