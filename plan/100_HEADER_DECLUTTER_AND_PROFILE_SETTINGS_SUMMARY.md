# Plan 100 — Header Declutter & Profile Settings: EXECUTION SUMMARY

**Status:** ✅ IMPLEMENTED (4 files) — blueprint `plan/100_HEADER_DECLUTTER_AND_PROFILE_SETTINGS_PLAN.md`
**Scope executed:** Section 6 implementation order (steps 1–3) + static verification (V1–V4).
Live-browser QA (V5–V11) is **deferred** — no MySQL server exists in this sandbox
(see §4), so the CI3 app cannot render here; this is an environment constraint, not a
code regression.

---

## 1. Execution Receipts (per file)

| # | File | Net Δ | What changed |
|---|------|-------|--------------|
| 1 | `application/views/templates/header.php` | 464 → 430 lines (−34) | Header declutter + JS cleanup |
| 2 | `application/views/profile/index.php` | 368 → 480 lines (+112) | App Preferences card + theme script |
| 3 | `application/language/english/app_lang.php` | 348 → 351 lines (+3 net) | +6 keys / −3 keys |
| 4 | `application/language/indonesian/app_lang.php` | 348 → 351 lines (+3 net) | +6 keys / −3 keys |

Key count per dictionary: **329 → 332** (identical sets in both files).

### 1.1 `templates/header.php`

Removed (right cluster, formerly lines 271–281):
- `$this->load->view('templates/lang_switcher');` include + its `Plan 94 (F1)` comment.
- `#user-theme-toggle` sun/moon `<button>` block + `Phase 32` comment.

Wallet balance pill — additive truncation guard (no behavior change):
- Anchor: `+ title="Rp {full amount}"`, `+ min-w-0 max-w-[46vw]`.
- Icon: `+ flex-shrink-0`.
- Amount span: `+ truncate` → at 360 px the pill can occupy ≤ ~166 px, never collides
  with the bell; full amount on hover via `title`.

Removed from the trailing `<script>`:
- `window.SYNAPSE_I18N` entries `js_theme_dark`, `js_theme_light` (map now
  `js_processing`, `js_copied`, `js_copy_failed` only).
- Theme engine: `toggleUserTheme()`, `syncThemeUI()`, and the
  `DOMContentLoaded → syncThemeUI()` listener.

Retained byte-identical: anti-FOUC head script, wallet pill markup/logic, entire
`#notif-wrapper` subtree (badge/dropdown/mark-all-read), all notification JS, header
geometry (`h-14`, sticky `z-40`, `px-4`), `<html lang>`/`site_lang_code` handling.

### 1.2 `profile/index.php`

Inserted a new **"App Preferences / Pengaturan Tampilan & Bahasa"** card
(`u-card rounded-2xl shadow-sm overflow-hidden`) **between REFERRAL CENTER and THE HUB**:

- **Row 1 — Language/Bahasa** (`fa-globe` indigo tile, label `profile_lang_label`):
  segmented capsule reusing the lang-switcher inline SVGs (UK/ID) + `EN`/`ID` text,
  anchors to `site_url('lang/switch/en'|'lang/switch/id')`, active segment
  server-rendered from `$site_lang_code` (`bg-indigo-600 text-white shadow-sm` vs
  `opacity-60 hover:opacity-100`), `role="group"` + `aria-label=lang_switch_label`,
  `title`/`aria-label` from `lang_english`/`lang_indonesian`.
- **Row 2 — Theme/Tema** (`fa-palette` cyan tile, label `profile_theme_label` + hint
  `profile_theme_hint`): segmented `#pref-theme-seg` with two `button.pref-theme-opt`
  (`data-theme="dark"` 🌙 / `data-theme="light"` ☀️, labels
  `profile_theme_dark`/`profile_theme_light`), `aria-pressed` toggled by JS.
- Hub menu comment renumber after row removal: rows now 1–6
  (Dompet, Tarik Dana, Edit Profil, Keamanan, Bantuan, Keluar).
- Hub row #4 (`#btn-theme-hub` + `#theme-hub-icon` + `#theme-mode-label`, old
  `lang('profile_theme')` + `toggleUserTheme()` onclick) **deleted**.

Appended guarded IIFE at the bottom `<script>` (`window.__profilePrefInit` guard):
`render()` (theme-aware active classes, mutually exclusive so no stylesheet-order
conflict), `applyTheme(dark)` (`.dark` class ↔ `localStorage['user_theme']` ↔
`CustomEvent('user-theme-change', {detail:{dark}})`, engine-contract parity with the
removed header engine), click bindings on both segments, `user-theme-change` listener to
re-render on external theme changes, immediate initial `render()` (script sits after the
card markup).

### 1.3 Dictionary schema (both files — final values)

Removed: `profile_theme` ('Appearance Theme'/'Tema Tampilan'), `js_theme_dark`,
`js_theme_light` — sole consumers were the deleted header/hub-row code.

| Key | EN | ID |
|-----|----|----|
| `profile_pref_title` | App Preferences | Pengaturan Tampilan & Bahasa |
| `profile_lang_label` | Language | Bahasa |
| `profile_theme_label` | Theme | Tema |
| `profile_theme_dark` | Dark | Gelap |
| `profile_theme_light` | Light | Terang |
| `profile_theme_hint` | Saved on this device | Tersimpan di perangkat ini |

Unchanged & still consumed: `lang_english`, `lang_indonesian`, `lang_switch_label`
(profile + auth), `common_toggle_theme` (auth/admin toggles).

---

## 2. Verification Results

| Check | Command / method | Result |
|-------|------------------|--------|
| V1 PHP lint ×4 | `php -l` on all 4 touched files | ✅ `No syntax errors detected` ×4 (run twice, incl. after final comment edit) |
| V2 Dict parity | sorted key-set extraction EN vs ID (`grep -oP` + `sort` + `diff`) | ✅ **332 = 332 keys, diff empty — identical key sets**; 6 new keys present, 3 obsolete absent |
| V3 Orphan greps | `grep -nE "user-theme-toggle\|theme-toggle-icon\|btn-theme-hub\|theme-hub-icon\|theme-mode-label\|toggleUserTheme\|syncThemeUI\|js_theme_dark\|js_theme_light\|lang_switcher"` over the 4 files | ✅ **0 hits** |
| V4 Scope guard (auth/admin intact) | grep over `views/auth/*.php`, `views/admin/templates/*.php` | ✅ `lang_switcher`/`auth_theme_toggle` still in all 3 auth views; `theme-toggle-icon` still in admin topbar+footer — untouched |
| V5 HTTP smoke | `php -S` + curl `/login`, `/`, `/lang/switch/en` | ⛔ Not possible — **no MySQL in sandbox** (`mysqli: No such file or directory`; config fallback `localhost/root`); all app routes 500 incl. `/login`, which never loads the changed files → environment-level, pre-existing |

### 2.1 Static width sanity (replaces visual check until QA)

- Header @360 px: left group ≈126 px; right cluster = pill (≤ `46vw` ≈ 166 px) + gap
  (10) + bell (≈24) ≈ **200 px max** → no wrap/collision; truncation + `title` preserves
  full balance. Groups now 3 (logo · pill · bell) vs 5 before.
- Profile card @360 px (inner ≈328 px): Language row label (≈90 px incl. icon tile) +
  EN/ID capsule (≈140 px) fits; Theme row label+hint column + capsule fits with
  `min-w-0` shrink available. No horizontal scroll risk on either row.

---

## 3. Out-of-scope / Unchanged

- Auth views (`login.php`, `register.php`, `change_password.php`) — lang/theme cluster
  intact (verified §2 V4).
- Admin theme toggle (`admin/templates/topbar.php` + `footer.php`) — separate engine,
  intact.
- `templates/lang_switcher.php`, `templates/auth_theme_toggle.php`, `bottom_nav.php`,
  `MY_Controller.php`, `Lang.php` — **not touched**.
- No DB/schema/route changes; theme & language engines unchanged at the contract level.

---

## 4. Deferred Live QA Checklist (needs local MySQL + browser)

1. Boot app against a live DB → login as member → `/profile` renders the new card,
   header shows only logo · balance pill · bell at 360/390/480 px (no overlap, pill
   truncates with `title`).
2. Language: on `/profile`, EN→ID anchor → 302 referer redirect → page re-renders in ID
   (`<html lang="id">`, card title `Pengaturan Tampilan & Bahasa`, `Bahasa`/`Tema`/`Gelap`
   labels); ID→EN symmetrical; invalid `lang/switch/xx` → 404.
3. Theme: fresh profile (no stored theme) → Dark segment pressed & `html.dark` set;
   click Light → `.dark` removed, `localStorage['user_theme']==='light'`, event fired,
   reload persists; `aria-pressed` tracks state; bell dropdown + mark-all-read unaffected.
4. Auth pages still show their top-right EN/ID + sun/moon cluster; forced
   `change_password` flow unaffected.

## 5. Rollback

All changes are view/dictionary-local, zero DB impact. Rollback = restore the 4 files
(`git checkout` for the tracked pair; the two `app_lang.php` files are untracked in this
workspace — restore from backup/editor history). `localStorage['user_theme']` values stay
valid under both UIs.

*Note: `git status` shows the two language files as untracked (`??`) — pre-existing
workspace state (whole `application/language/` tree was uncommitted before this change),
not introduced here.*
