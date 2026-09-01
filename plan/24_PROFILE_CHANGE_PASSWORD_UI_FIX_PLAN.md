# Profile Change Password — UI Spacing & Localization Fix Blueprint (24)

**Status:** Blueprint — PLAN MODE. No application code modified.
**Stack:** CodeIgniter 3 + PHP 8.x, MySQL (`db_webtable`), mobile-first Tailwind (CDN), Indonesian UI, IDR only.
**Scope:** `application/views/profile/change_password.php` (spacing) + `application/controllers/Profile.php` (validation message localization). Two issues reported from visual/functional testing of the voluntary change-password page:
1. Fields inside `<form>` collapse together (no vertical spacing on the `<form>` element).
2. CI3 default validation messages render in English, clashing with the Indonesian UI.

---

## 1. Inspection Findings (evidence)

### 1.1 `application/views/profile/change_password.php`

| # | Line | Current state | Implication |
|---|------|---------------|-------------|
| 1 | 5 | Outer wrapper: `<div class="p-4 space-y-6">` — **no `pb-24`**. | Sibling `profile/index.php` uses `<div class="p-4 space-y-5 pb-24">`; the bottom nav (`templates/bottom_nav.php`, `fixed bottom-0 h-16`) can overlap the submit button on short viewports. The app shell (`templates/header.php` line 15) already has `pb-24`, but the page wrapper should match the sibling profile page pattern. |
| 2 | 40 | `<?= form_open('profile/change-password') ?>` — renders `<form action="..." method="post" accept-charset="utf-8">` with **no class attribute**. | The card wrapper `div.bg-white.rounded-2xl.p-5.shadow-sm.space-y-5` (line 32) only spaces its **direct children** (the icon header div and the `<form>` element). The `<form>`'s inner field `<div>`s are **not** spaced by the card's `space-y-5`. → The 3 field divs + submit button stack with zero vertical margin: an inline `form_error()` `<p class="mt-1 ...">` visually touches the next field's label. **Root cause of issue #1.** |
| 3 | 43–47, 51–55, 59–63 | Labels use `mb-1.5` (6px) consistently across all three fields; error `<p>` uses `mt-1` (4px). | Label-to-input margin is already consistent and reasonable. Refine error margin `mt-1` → `mt-1.5` for a slightly cleaner input↔error gap; keep labels as-is. |
| 4 | 47, 55, 63 | `form_error('...', '<p class="mt-1 text-xs text-rose-500 font-semibold">', '</p>')` | Container is minimal and correct. With `space-y-4` on the `<form>`, each field block keeps a fixed 1rem rhythm regardless of whether an error renders → vertical balance preserved in error states. No reserved-space hack needed (would create awkward empty gaps). |
| 5 | 66–68 | Submit button is the last child inside `<form>`. | Gains 1rem separation from the confirm field automatically via `space-y-4`. |

**Container hierarchy (current):**

```
div.p-4.space-y-6                       ← outer wrapper (needs pb-24)
├─ div.flex (back header)
├─ flash success / flash error blocks
├─ general $errors blocks
└─ div.bg-white.rounded-2xl.p-5.shadow-sm.space-y-5      ← card (spaces only its own children)
   ├─ div.text-center (icon + hint)
   └─ form (NO class → children unspaced)  ← issue #1
      ├─ div  → label + input + form_error   [current_password]
      ├─ div  → label + input + form_error   [new_password]
      ├─ div  → label + input + form_error   [confirm_password]
      └─ button (submit)
```

### 1.2 `application/controllers/Profile.php`

| # | Line | Current state | Implication |
|---|------|---------------|-------------|
| 1 | 92–102 | `set_rules()` defines `required|callback__verify_current_password`, `required|min_length[8]`, `required|matches[new_password]` with Indonesian field labels. | No `set_message()` override → CI3 falls back to `system/language/english/form_validation_lang.php` (confirmed at lines 41/48/59): *"The {field} field is required."*, *"The {field} field must be at least {param} characters in length."*, *"The {field} field does not match the {param} field."* → English output such as *"The Konfirmasi Kata Sandi field does not match..."*. **Root cause of issue #2.** |
| 2 | 125–132 | `_verify_current_password()` already calls `set_message('_verify_current_password', 'Kata sandi saat ini salah.')` | Already localized — leave untouched. |
| 3 | 118 | `$data['values'] = $this->input->post();` | View does not repopulate password inputs (correct — passwords should never be echoed back). No change. |

### 1.3 Supporting facts (verified)

- Route already registered: `$route['profile/change-password'] = 'profile/change_password';` (`application/config/routes.php` line 23) — **no route change needed**.
- `form_open($action, $attributes, $hidden)` — CI3 form helper accepts an attributes array as the second argument → `form_open('profile/change-password', ['class' => 'space-y-4'])` emits `class="space-y-4"` on the `<form>`.
- No custom `application/language/*/form_validation_lang.php` exists (only `index.html` stubs) — so overriding via `set_message()` in the controller is the correct, scoped approach (matches the task instruction; avoids a global language-file change affecting every form).
- CI3's `set_message()` values support `{field}` and `{param}` placeholders (resolved in `_prepare_error_message()`); for `matches`, `{param}` resolves to the **label** of the target field ('Kata Sandi Baru') — verified from `system/libraries/Form_validation.php` behavior.
- Working tree note: branch `fase-10b-rate-limiting` already carries unrelated uncommitted modifications (rate-limiting work). This fix must only touch the two files above, and only the specific lines documented here.

---

## 2. Implementation Blueprint

### 2.1 View — `application/views/profile/change_password.php`

Three targeted edits; all other markup/classes stay verbatim.

**Edit A — outer wrapper (line 5): add `pb-24`**

```php
<div class="p-4 space-y-6 pb-24">
```

**Edit B — form element (line 40): add `space-y-4`**

```php
<?= form_open('profile/change-password', ['class' => 'space-y-4']) ?>
```

**Edit C — inline error containers (lines 47, 55, 63): refine margin, identical for all three fields**

```php
<?= form_error('current_password', '<p class="mt-1.5 text-xs text-rose-500 font-semibold">', '</p>') ?>
<?= form_error('new_password', '<p class="mt-1.5 text-xs text-rose-500 font-semibold">', '</p>') ?>
<?= form_error('confirm_password', '<p class="mt-1.5 text-xs text-rose-500 font-semibold">', '</p>') ?>
```

**Resulting form hierarchy (fixed):**

```
form (class="space-y-4")
├─ div  → label(mb-1.5) + input + form_error(mt-1.5)   [current_password]   ┐
├─ div  → label(mb-1.5) + input + form_error(mt-1.5)   [new_password]       │ 1rem between
├─ div  → label(mb-1.5) + input + form_error(mt-1.5)   [confirm_password]   │ blocks always
└─ button (submit)                                                          ┘
```

Design decisions:
- **`space-y-4` on the form, not on each field** — one class fixes the root cause; the 1rem rhythm between field blocks holds whether or not an error is rendered, so error appearance shifts content without making labels/errors collide (the reported glitch).
- **No reserved error space** (e.g. fixed `min-h` under inputs) — would leave awkward empty gaps in the normal state; `space-y-4` already guarantees the error can never touch the next label.
- **`mb-1.5` labels kept** — consistent with `profile/index.php` edit sheet and `wallet/withdraw.php`; only the error `mt-1 → mt-1.5` (4→6px) refinement is applied.
- **`pb-24` on the page wrapper** — mirrors `profile/index.php` line 1; guarantees the submit button clears the fixed bottom nav on short viewports even though the shell also pads.

### 2.2 Controller — `application/controllers/Profile.php`

Add one localized-message block inside `change_password()` **after** the three `set_rules()` calls (lines 92–102) and **before** `$this->form_validation->run()` (line 104):

```php
$this->form_validation->set_message([
    'required'   => '{field} wajib diisi.',
    'min_length' => '{field} minimal {param} karakter.',
    'matches'    => '{field} tidak cocok dengan {param}.',
]);
```

Rendered messages (CI3 substitutes placeholders):
- Empty submit → `Kata Sandi Saat Ini wajib diisi.` / `Kata Sandi Baru wajib diisi.` / `Konfirmasi Kata Sandi wajib diisi.`
- Short new password → `Kata Sandi Baru minimal 8 karakter.`
- Mismatched confirm → `Konfirmasi Kata Sandi tidak cocok dengan Kata Sandi Baru.` (`{param}` resolves to the `new_password` label)
- Wrong current password → unchanged `Kata sandi saat ini salah.` (existing `_verify_current_password` message)

Design decisions:
- **Scoped to this controller** — `set_message()` overrides only for this validation run; no global language file, no impact on `Auth` register/login or any other form (task explicitly requests the `set_message()` route).
- **Array form** of `set_message()` — idiomatic CI3, one call, three rules.
- **`{field}`/`{param}` placeholders kept** — avoids hardcoding labels twice; stays correct if a field label ever changes.

### 2.3 Files NOT changed

- `application/config/routes.php` — route already exists.
- `application/views/templates/*` — shell/bottom-nav untouched.
- `application/views/profile/index.php`, models, DB, config — out of scope.
- `_verify_current_password` callback and its `set_message()` — already Indonesian.

---

## 3. Flow Summary

```
POST /profile/change-password
   └─ set_rules (3 fields) + set_message (required|min_length|matches → Indonesian)
        ├─ FAIL → re-render view:
        │     form class="space-y-4"  → fields separated 1rem; errors mt-1.5 under field,
        │     never touching next label; all messages Indonesian
        └─ PASS → update_user(password hash) → flash success → redirect('profile')  [302]
```

---

## 4. Verification Protocol

### 4.1 Lint (roadmap rule)

```bash
php -l application/controllers/Profile.php
php -l application/views/profile/change_password.php
```
Expected: `No syntax errors detected` for both.

### 4.2 Functional checks (curl, authenticated session)

| # | Test | Steps | Expected |
|---|------|-------|----------|
| 1 | Empty submit (all-errors state) | POST empty fields | HTTP 200; HTML contains `Kata Sandi Saat Ini wajib diisi.`, `Kata Sandi Baru wajib diisi.`, `Konfirmasi Kata Sandi wajib diisi.` — **no English "field is required"** |
| 2 | Short new password | POST `current_password=<valid>`, `new_password=1234567`, valid confirm | 200; `Kata Sandi Baru minimal 8 karakter.` |
| 3 | Mismatched confirm | POST valid current + new, `confirm_password` different | 200; `Konfirmasi Kata Sandi tidak cocok dengan Kata Sandi Baru.` |
| 4 | Wrong current password | POST `current_password=wrong`, valid new/confirm | 200; `Kata sandi saat ini salah.`; DB hash unchanged |
| 5 | Happy path | POST 3 valid fields | 302 → `/profile`; flash `Kata sandi berhasil diperbarui.`; new hash verifies |
| 6 | Regression: forced flag | User with `must_change_password=1` | `MY_Controller` still redirects to `/auth/change-password`; behavior unchanged |

### 4.3 Visual regression (manual browser, DevTools responsive mode)

| # | State | Viewport | Checks |
|---|-------|----------|--------|
| 1 | Normal (GET) | 375×667 (mobile) | 3 fields with **visible ~16px vertical gaps**; labels ~6px above inputs; no horizontal overflow; submit button fully visible above bottom nav (`pb-24`); card centered in `max-w-[480px]` shell |
| 2 | Error (single) | 375×667 | Triggered inline error sits under its field, gap to next label intact (error no longer touches label); page grows gracefully, no overlap with bottom nav |
| 3 | Error (all 3) | 375×667 | Three inline errors, one per field, each visually attached to its own field; consistent 1rem field rhythm preserved |
| 4 | Normal + error | 480×800 (max shell) | Same checks at shell max width; centered on desktop; no scroll-jump weirdness |
| 5 | Success flash | 375×667 | After happy path, flash banner on `/profile` renders as before (unchanged) |

Pass criteria: in every error state the error text never touches the adjacent label/input, and field-to-field spacing stays uniform.

---

## 5. Risks / Notes

- **Working tree hygiene**: the branch already has unrelated uncommitted changes (rate-limiting work on `Auth`, `Admin_auth`, `Rentals`, `Wallet`, etc.). This fix touches exactly two files (`Profile.php`, `change_password.php`) — keep the diff scoped; review before commit.
- **`set_message()` is per-run scope**: safe and isolated. If future forms also need Indonesian messages, a shared `application/language/indonesian/form_validation_lang.php` becomes the cleaner global solution — noted as a follow-up, not part of this fix.
- **Password fields never repopulated** on validation failure — inputs keep `placeholder` only; no `value=` echo, so no password leakage risk.
- **No JS involved** — pure Tailwind class + PHP string changes; Tailwind CDN (`cdn.tailwindcss.com`) JIT-compiles `space-y-4`/`pb-24`/`mt-1.5` at runtime, so no build step needed.
- Commit message (Indonesian, existing style): e.g. `fix(profile): perbaiki jarak form & lokalisasi pesan validasi ubah sandi`.
