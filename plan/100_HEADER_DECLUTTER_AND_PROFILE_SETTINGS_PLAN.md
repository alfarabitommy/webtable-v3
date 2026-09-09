# Plan 100 — Header Declutter & Migration of Language/Theme Settings to Profile

**Status:** DRAFT (blueprint only — no view/application code changed)
**Scope:** Member-side only. Member header (`application/views/templates/header.php`) is
decluttered; Language + Theme controls are relocated into the Profile view
(`application/views/profile/index.php`) as a dedicated **App Preferences /
Pengaturan Tampilan & Bahasa** card.
**Constraint honored:** this document is the deliverable. No `.php` view, controller,
language file, or asset is modified by this plan's execution — Phase 1–4 below are the
blueprint sections, and the Implementation Order (§6) is a *future* checklist that must
only run after a separate approval.

---

## 0. Ringkasan (Executive Summary)

The member sticky header currently packs **5 interactive groups** into a `h-14` bar on a
`max-w-[480px]` shell: Logo/wordmark, wallet balance pill, EN/ID language capsule,
sun/moon theme button, and notification bell. At 360–390 px this cluster leaves almost no
air and the balance pill competes with the lang capsule for width.

**After:** the header keeps only the high-frequency trio — **Logo · Balance Pill ·
Notification Bell** — with balanced spacing. Language & Theme become rows inside a new
**"App Preferences"** card on the Profile page, using the exact same engines already in
production:

- Language row → plain `GET /lang/switch/(en|id)` anchors (existing `Lang::switch()`,
  session + 30-day cookie + same-host referer redirect). No new endpoint, no CSRF, no JS.
- Theme row → segmented **Dark 🌙 / Light ☀️** control that writes the same
  `localStorage('user_theme')`, toggles the same `document.documentElement.dark` class,
  and dispatches the same `CustomEvent('user-theme-change', {detail:{dark}})` contract.

Pre-login auth pages (`login.php`, `register.php`, `change_password.php`) keep their own
top-right lang/theme cluster and the admin console keeps its separate engine — both are
**untouched**.

### Assumptions (safe defaults; flagged for review)

| # | Assumption | Basis |
|---|------------|-------|
| A1 | **"USC/IDR" in the brief is a typo.** The wallet pill remains IDR `Rp …` exactly as today. No "USC" currency exists anywhere in this repo (AGENTS.md: *all money is IDR only*). | `header.php:264–270`, repo money invariant |
| A2 | **Flags stay inline-SVG, not emoji.** `lang_switcher.php` carries an explicit repo decision: *"Flag = SVG inline sederhana (tanpa aset jaringan, tanpa emoji — render platform tidak konsisten)"*. The Profile row reuses those SVGs (flag + `EN`/`ID` text). | `templates/lang_switcher.php:4–5` |
| A3 | **Hub row #4 "Tema Tampilan" is superseded** (not duplicated) by the new card. The theme control gets exactly one home on the member side. | single-home principle; `profile/index.php:110–123` |
| A4 | Header **keeps its height `h-14`, sticky behavior, z-index stack, and the anti-FOUC head script** — decluttering must not reflow the shell or flash unstyled themes. | `docs/4_UI_UX_GUIDELINES.md` z-index rules |

---

## 1. Current-State Inventory (verified against source)

### 1.1 Member header — `application/views/templates/header.php`

Right cluster (inside `<header class="h-14 u-topbar … sticky top-0 z-40">`,
`div.flex.items-center.gap-2.5`, lines 263–366):

| Node | Lines | Element | Behavior |
|------|-------|---------|----------|
| 1 | 264–270 | Wallet pill | `<a href="wallet">` `u-capsule` rounded-full; `fa-wallet` + `Rp {balance}` (`text-xs font-mono`); **not a dropdown** in current code — plain link |
| 2 | 272–273 | Lang switcher | `$this->load->view('templates/lang_switcher')` — EN/ID flag capsule |
| 3 | 275–280 | Theme button | `<button id="user-theme-toggle">` `u-btn-ghost` w-9 h-9, `onclick="toggleUserTheme()"`, `<i id="theme-toggle-icon">` |
| 4 | 282–365 | Notification bell | `#notif-wrapper` → `#notif-badge`, `#notif-dropdown` (z-[60]) |

Inline `<script>` block (369–463) defines:

- `window.SYNAPSE_I18N` map (372–378): `js_theme_dark`, `js_theme_light`,
  `js_processing`, `js_copied`, `js_copy_failed` (client-side dictionary, invariant L5).
- Notification logic: `notifUnreadCount`, `toggleNotifDropdown()`, `closeNotifOutside()`,
  `markAllRead()` (380–440).
- **Theme engine (442–463):** `toggleUserTheme()`, `syncThemeUI(dark)`,
  `DOMContentLoaded → syncThemeUI(...)`. Engine contract:
  `documentElement.classList.toggle('dark')` → `localStorage.setItem('user_theme', …)`
  → `dispatchEvent(new CustomEvent('user-theme-change', {detail:{dark}}))`.
- Anti-FOUC head script (11–21): default **dark**; only stored `'light'` opts out.

### 1.2 Lang switcher partial — `templates/lang_switcher.php` (KEEP; reused by auth)

- Capsule container: `inline-flex items-center gap-0.5 u-capsule rounded-full p-0.5`,
  `role="group"`, `aria-label=lang('lang_switch_label')`.
- Two `w-8 h-8 rounded-full` anchors to `site_url('lang/switch/en')` / `…/id`.
- Active = `ring-2 ring-indigo-500/80 shadow`; inactive = `opacity-55 hover:opacity-100`.
- Inline SVG flags (UK cross / ID bicolor); `title`+`aria-label` from
  `lang_english` / `lang_indonesian`.
- Used by: member header (removed here) **and** auth pages (kept).

### 1.3 Theme toggle partial — `templates/auth_theme_toggle.php` (KEEP; auth only)

- Standalone (no Font Awesome — CSS-driven moon/sun glyphs), `id="auth-theme-toggle"`,
  guarded by `window.__authThemeInit`. Same 3-line engine contract duplicated inline
  (repo already tolerates this duplication pattern).

### 1.4 Auth pages (UNTOUCHED)

`auth/login.php`, `auth/register.php`, `auth/change_password.php` each render the cluster at
lines ~364–367:
`<div class="absolute top-4 right-4 z-30 flex items-center gap-2">` +
`lang_switcher` + `auth_theme_toggle`.

### 1.5 Profile view — `application/views/profile/index.php`

Layout: `<div class="p-4 space-y-5 pb-24">` → flash msgs → **IDENTITY CARD** →
**REFERRAL CENTER** → **THE HUB** menu (`u-card rounded-2xl shadow-sm overflow-hidden`,
rows are `<a>/<button>` `flex items-center justify-between px-5 py-4 u-row-hover` with a
`w-9 h-9 rounded-xl` icon tile + label + chevron).

Hub row #4 (110–123) is the existing theme entry:
`<button id="btn-theme-hub" onclick="toggleUserTheme()">` with
`<i id="theme-hub-icon">` and `<span id="theme-mode-label">` (label text hydrated by
header's `syncThemeUI` from `SYNAPSE_I18N.js_theme_*`). **This row is the seed of the new
card and is removed once the card lands.**

Bottom script (246–367): referral copy (uses `SYNAPSE_I18N.js_copied/js_copy_failed`),
`openSheet/closeSheet`, edit-profile modal, avatar preview.

### 1.6 Language engine — `Lang.php` + routing + i18n

- Route (routes.php:68): `$route['lang/switch/(:any)'] = 'lang/switch/$1';`
- `Lang::switch($code)` validates `∈ {en,id}` (else 404), sets
  `session('site_lang')`, writes 30-day cookie `site_lang`, redirects back to same-host
  `HTTP_REFERER` (fallback `base_url()`). GET-only, idempotent, **no CSRF, no DB**.
- Member pages get `site_lang_code` view var via `i18n_apply()` (MY_Controller), which
  the header uses for `<html lang>` and which the Profile card reuses to mark the active
  language.

### 1.7 Dictionaries — `application/language/{english,indonesian}/app_lang.php`

Identical key sets required (V6 parity). Keys relevant to this plan:

| Key | EN | ID | Used by |
|-----|----|----|---------|
| `common_toggle_theme` | Toggle theme | Ganti tema | auth toggle (kept) |
| `js_theme_dark` / `js_theme_light` | Dark / Light | Gelap / Terang | header map + profile label (see §3.3) |
| `lang_english` / `lang_indonesian` | English / Indonesian | English / Indonesian | switcher aria/title (kept) |
| `lang_switch_label` | Change language | Ganti bahasa | switcher group aria (kept) |
| `profile_theme` | Appearance Theme | Tema Tampilan | **only** hub row #4 (removed → key removed) |

Note: files are 348 lines but **not** 348 keys (`wallet_amount_prompt` value spans two
lines); exact key counts are measured with `grep -c "\$lang\["` at verification time.

---

## 2. Target Architecture

```
┌─ MEMBER HEADER (after) ──────────────────────────────┐
│ Logo ◈ Synapse ··· [⇄ Rp 12.345.678]   [🔔]          │
└──────────────────────────────────────────────────────┘
  language + theme control  →  Profile → App Preferences card
```

- One source of truth for each preference, reachable from anywhere on the member side
  (Profile is 1 tap away in the bottom nav).
- Language stays a **full-page GET navigation** (existing engine — zero JS, zero CSRF,
  works pre- and post-login, idempotent).
- Theme stays a **local-only, JS-driven** preference (no DB, no network), persisting in
  `localStorage['user_theme']` and applied class-first (anti-FOUC) on every page.
- Member JS map (`SYNAPSE_I18N`) continues to serve the remaining `js_*` client strings.

---

## 3. Blueprint §1 — Member Header Declutter

### 3.1 Exact DOM changes in `header.php`

**Remove** (right cluster, lines 272–280, i.e. the two comment lines + include + button):

```php
<!-- Plan 94 (F1): Language Switcher (EN default / ID secondary) -->
<?php $this->load->view('templates/lang_switcher'); ?>

<!-- Theme Toggle (Phase 32) — Sun/Moon -->
<button id="user-theme-toggle" type="button" aria-label="<?= lang('common_toggle_theme') ?>"
        class="w-9 h-9 rounded-full u-btn-ghost flex items-center justify-center transition-colors active:scale-95"
        onclick="toggleUserTheme()">
    <i id="theme-toggle-icon" class="fas fa-moon text-sm"></i>
</button>
```

**Retain byte-identical:** `<html lang>`, anti-FOUC head script, wallet pill anchor
(`href=wallet`, `u-capsule`, `Rp` formatting), the whole `#notif-wrapper` subtree
(badge/dropdown/list/mark-read), `SYNAPSE_I18N` (minus the two theme keys — §3.3),
notification JS, header height `h-14`, sticky `z-40`, `px-4`.

### 3.2 Balance-pill width guard (small, additive)

9+ digit balances can push the pill into the bell even with the lang capsule gone. Add
**classes only** (no markup/logic change) so the pill truncates gracefully:

```html
<a href="<?= base_url('wallet'); ?>" title="Rp <?= number_format($global_balance,0,',','.') ?>"
   class="u-capsule group flex items-center px-3 py-1 rounded-full transition-all duration-200 active:scale-95 hover:shadow min-w-0 max-w-[46vw]">
    <i class="fas fa-wallet text-indigo-500 mr-2 text-xs group-hover:scale-110 transition-transform flex-shrink-0"></i>
    <span class="text-xs font-mono font-bold tracking-tighter truncate">Rp …</span>
</a>
```

`min-w-0` + `truncate` on the inner `<span>` + `max-w-[46vw]` on the anchor: at 360 px the
pill may occupy at most ~166 px; full amount still available via `title`.

### 3.3 Theme-engine removal from header (after Profile card owns it)

Remove from the header `<script>` (442–463): `toggleUserTheme()`, `syncThemeUI()`, and the
`DOMContentLoaded → syncThemeUI(...)` call. Remove the two theme entries from the
`SYNAPSE_I18N` map (keys `js_theme_dark`, `js_theme_light`).

> **Fallback (minimal-churn alternative, if a later review objects to moving the engine):**
> keep the functions in `header.php` untouched — with the button gone, `syncThemeUI()`
> is already a safe no-op (`querySelectorAll('#theme-toggle-icon')` + guarded `if (hubIcon)`
> / `if (lbl)`). The Profile card script then binds to the *same* engine instead of
> shipping its own. **Recommended path is removal** (dead code, single home); the fallback
> is documented here only to de-risk review.

### 3.4 Before / after wireframes (mobile)

```
BEFORE — 360 px shell (header h-14, px-4)             DOM groups = 5
┌──────────────────────────────────────────────────────┐
│ ◈ Synapse        [⇄ Rp 12.345] [EN|ID] [◐] [🔔]     │
│                  └─ gap-2.5 ─┘ ←  ~198 px cluster,  │
│                   balance & capsule crowd at 9-digit │
└──────────────────────────────────────────────────────┘

AFTER — 360 px shell                                  DOM groups = 3
┌──────────────────────────────────────────────────────┐
│ ◈ Synapse             [⇄ Rp 12.345.678]      [🔔]    │
│        left group          pill ≤ 46vw         bell  │
└──────────────────────────────────────────────────────┘
```

Rough width math (360 px): left logo(28)+mr(12)+wordmark(~86) ≈ 126 px → right cluster
has ≈ 234 px minus `justify-between` slack ≈ **~200 px of air**. Pill (≤166 px) + gap
(10) + bell (~24) ≈ 200 px worst case — no overflow, comfortable tap targets (≥ 32 px
effective), pill and bell never collide.

### 3.5 Header "unchanged behavior" checklist (regression proof for reviewers)

- `templates/lang_switcher.php` and `templates/auth_theme_toggle.php` files are **not
  edited**; auth page cluster intact (verify `git diff` touches no `views/auth/*`).
- Notification bell: markup, IDs, `notifUnreadCount`, mark-all-read-on-open, outside-click
  close, dropdown `z-[60]` — untouched.
- Wallet pill: same href/format — only additive `min-w-0/max-w/truncate/title` classes.
- Admin engine (`admin/templates/topbar.php` `#theme-toggle-icon` + footer JS) untouched —
  greps must be scoped per-file (see §6).
- Member `profile/change_password.php` (voluntary, member shell) also loses header
  lang/theme access by design — user reaches them via Profile → App Preferences.

---

## 4. Blueprint §2 — Profile "App Preferences" Card

### 4.1 Placement & structure

Insert a new card **between REFERRAL CENTER and THE HUB** (top-to-bottom: Identity →
Referral → **App Preferences** → Account Menu). Remove hub row #4 (theme) so the two
settings have exactly one home. Hub stays pure navigation (rows 1–3, 5–7).

### 4.2 Card anatomy & markup skeleton

Card skeleton (row pattern mirrors hub rows for visual rhythm, but rows carry *controls*
on the right instead of a chevron):

```php
<!-- ===== APP PREFERENCES (Plan 100) — Pengaturan Tampilan & Bahasa ===== -->
<div class="u-card rounded-2xl shadow-sm overflow-hidden">
    <h3 class="text-[10px] font-bold u-muted uppercase tracking-widest px-5 pt-4 pb-2">
        <i class="fas fa-palette mr-1.5"></i><?= lang('profile_pref_title') ?>
    </h3>

    <!-- Row 1 — Language / Bahasa -->
    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-50 dark:border-slate-800">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-9 h-9 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-globe text-sm"></i>
            </div>
            <span class="text-sm font-medium u-text"><?= lang('profile_lang_label') ?></span>
        </div>
        <!-- segmented EN | ID — GET /lang/switch/(:any) -->
        <?php $lang_cur = isset($site_lang_code) ? $site_lang_code : 'en'; ?>
        <div class="inline-flex items-center gap-0.5 u-capsule rounded-full p-0.5 flex-shrink-0"
             role="group" aria-label="<?= lang('lang_switch_label') ?>">
            <!-- EN (default) — flag SVG copied from templates/lang_switcher.php -->
            <a href="<?= site_url('lang/switch/en') ?>"
               class="flex items-center gap-1.5 h-8 px-2.5 rounded-full transition-all duration-200 active:scale-95 <?= $lang_cur === 'en' ? 'bg-indigo-600 text-white shadow-sm' : 'opacity-60 hover:opacity-100 u-text-2' ?>"
               title="<?= lang('lang_english') ?>" aria-label="<?= lang('lang_english') ?>" aria-pressed="<?= $lang_cur === 'en' ? 'true' : 'false' ?>">
                [UK FLAG SVG 18×12] <span class="text-[11px] font-extrabold tracking-wide">EN</span>
            </a>
            <!-- ID — same pattern, href lang/switch/id, ID flag SVG, label ID -->
        </div>
    </div>

    <!-- Row 2 — Theme / Tema -->
    <div class="flex items-center justify-between px-5 py-4">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-9 h-9 rounded-xl bg-cyan-50 dark:bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-palette text-sm"></i>  <!-- or fa-moon, see 4.4 -->
            </div>
            <div class="min-w-0">
                <p class="text-sm font-medium u-text"><?= lang('profile_theme_label') ?></p>
                <p class="text-[10px] u-muted"><?= lang('profile_theme_hint') ?></p> <!-- optional -->
            </div>
        </div>
        <!-- segmented Dark | Light — JS-driven, see §5 -->
        <div id="pref-theme-seg" class="inline-flex items-center gap-0.5 u-capsule rounded-full p-0.5 flex-shrink-0"
             role="group" aria-label="<?= lang('profile_theme_label') ?>">
            <button type="button" data-theme="dark"  aria-pressed="false"
                    class="pref-theme-opt flex items-center gap-1.5 h-8 px-2.5 rounded-full text-[11px] font-extrabold transition-all duration-200 active:scale-95">
                <i class="fas fa-moon text-xs"></i><span><?= lang('profile_theme_dark') ?></span>
            </button>
            <button type="button" data-theme="light" aria-pressed="false"
                    class="pref-theme-opt flex items-center gap-1.5 h-8 px-2.5 rounded-full text-[11px] font-extrabold transition-all duration-200 active:scale-95">
                <i class="fas fa-sun text-xs"></i><span><?= lang('profile_theme_light') ?></span>
            </button>
        </div>
    </div>
</div>
```

### 4.3 Styling tokens (dark/light strict)

Every color comes from the existing system — **semantic `u-*` classes own color/bg/border,
Tailwind utilities own spacing/radius** (repo rule in `header.php` `<style>` comment):

| Element | Token / utility | Theming |
|---|---|---|
| Card | `u-card rounded-2xl shadow-sm overflow-hidden` | `var(--u-surface)` + `var(--u-border)` auto-both-themes |
| Row divider | `border-b border-slate-50 dark:border-slate-800` | matches existing hub rows |
| Icon tile | `w-9 h-9 rounded-xl bg-{indigo|cyan}-50 dark:bg-{indigo|cyan}-500/10 text-…` | matches hub rows |
| Control shell | `inline-flex gap-0.5 u-capsule rounded-full p-0.5` | `var(--u-surface-2)` bg |
| **Option active (light)** | `bg-indigo-600 text-white shadow-sm` | solid indigo fill |
| **Option active (dark)** | `bg-cyan-500/20 text-cyan-200 ring-1 ring-inset ring-cyan-400/30` | glow-lean cyan, consistent with dark accent `#22d3ee` (`u-nav-active`, `u-badge-ai`) |
| Option inactive | `opacity-60 hover:opacity-100 u-text-2 hover:bg-slate-100/60 dark:hover:bg-slate-700/40` | token text, subtle hover wash |
| Section label | `text-[10px] font-bold u-muted uppercase tracking-widest px-5 pt-4 pb-2` | header convention |

Active-segment fill mirrors the brand gradient direction used by `u-btn-cyber`
(indigo→cyan) while staying flat for legibility. Optional strict-gradient variant for
review: `bg-gradient-to-r from-indigo-500 to-cyan-500 text-white shadow-sm` (both themes) —
final pick happens at implementation with a 360 px screenshot pass.

### 4.4 Language row behavior

- Reuses the **exact inline SVGs** from `templates/lang_switcher.php` (UK/ID), enlarged
  row includes the visible `EN`/`ID` code; full native name in `title`/`aria-label`.
- Clicking = plain GET navigation through the existing route → server re-renders the
  whole page in the chosen language; referer redirect lands back on `/profile`.
  No AJAX needed (matches how the engine works today and keeps it idempotent + CSRF-free).
- Active state is **server-rendered** from `$site_lang_code` (already injected by
  `i18n_apply()` for member controllers).

---

## 5. Blueprint §3 — JS Engine Wiring & Dictionary Schema

### 5.1 Theme segmented control — event bindings (profile view, inline script)

New guarded IIFE appended to the existing bottom `<script>` in `profile/index.php`
(guard mirrors `auth_theme_toggle.php`'s `window.__authThemeInit` pattern):

```js
/* --- Plan 100: App Preferences — theme segmented control --- */
(function () {
    if (window.__profilePrefInit) { return; }
    window.__profilePrefInit = true;

    var seg   = document.getElementById('pref-theme-seg');
    if (!seg) { return; }
    var opts  = Array.prototype.slice.call(seg.querySelectorAll('.pref-theme-opt'));
    var html  = document.documentElement;

    function isDark() { return html.classList.contains('dark'); }

    function render() {
        var dark = isDark();
        opts.forEach(function (b) {
            var active = (b.getAttribute('data-theme') === 'dark') === dark;
            b.setAttribute('aria-pressed', active ? 'true' : 'false');
            // active vs inactive class swap — single reflow batch, no layout thrash:
            b.classList.toggle('bg-indigo-600', active);
            b.classList.toggle('text-white', active);
            b.classList.toggle('shadow-sm', active);
            b.classList.toggle('bg-cyan-500/20', active);
            b.classList.toggle('text-cyan-200', active);
            b.classList.toggle('ring-1', active);
            b.classList.toggle('ring-inset', active);
            b.classList.toggle('ring-cyan-400/30', active);
            b.classList.toggle('opacity-60', !active);
        });
    }

    // Core engine — EXACT parity with the contract the header engine used
    // (localStorage 'user_theme' + .dark class + CustomEvent('user-theme-change')):
    function applyTheme(dark) {
        html.classList.toggle('dark', dark);
        try { localStorage.setItem('user_theme', dark ? 'dark' : 'light'); } catch (e) {}
        window.dispatchEvent(new CustomEvent('user-theme-change', { detail: { dark: dark } }));
        render();
    }

    opts.forEach(function (b) {
        b.addEventListener('click', function () {
            applyTheme(b.getAttribute('data-theme') === 'dark');
        });
    });

    // Keep segments in sync when theme changes from ANY source on this page
    // (e.g. future shortcuts); storage is not cross-tab synced (unchanged behavior).
    window.addEventListener('user-theme-change', function (e) {
        if (e && e.detail && typeof e.detail.dark === 'boolean') { render(); }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', render);
    } else { render(); }
})();
```

Contract invariants preserved:
- class applied **before** storage write and before event dispatch (same order as old
  engine); anti-FOUC head script still wins on first paint for every page.
- `applyTheme(true)` when already dark = harmless re-assert (idempotent); clicking the
  active option is a no-op visually.
- No `getComputedStyle`/offset reads → zero layout thrashing; transitions are
  color/bg/box-shadow only.

### 5.2 Engine disposition summary

| Component | Decision | Reason |
|---|---|---|
| `toggleUserTheme()`/`syncThemeUI()` in `header.php` | **Remove** (recommended) / keep (fallback) | Dead after declutter; Profile owns the only control. Fallback keeps them as safe no-ops |
| `#theme-toggle-icon`, `#theme-hub-icon`, `#theme-mode-label` | Remove with their DOM (header button; profile hub row #4) | No other consumers (grep-verified member-side) |
| `js_theme_dark` / `js_theme_light` in `SYNAPSE_I18N` | Remove from map + dictionaries | Superseded by static `profile_theme_dark/light` labels (§5.3); `js_copied/js_copy_failed/js_processing` stay |
| Anti-FOUC head script | Keep, byte-identical | Prevents theme flash; default-dark logic untouched |

### 5.3 Dictionary schema (both files, exact symmetrical parity — V6)

**Add** (identical key sets in `english` and `indonesian`):

| Key | EN | ID | Used for |
|-----|----|----|----------|
| `profile_pref_title` | App Preferences | Pengaturan Tampilan & Bahasa | Card section heading |
| `profile_lang_label` | Language | Bahasa | Language row label |
| `profile_theme_label` | Theme | Tema | Theme row label + segmented group aria |
| `profile_theme_dark` | Dark | Gelap | Dark segment text |
| `profile_theme_light` | Light | Terang | Light segment text |
| `profile_theme_hint` *(optional)* | Saved on this device | Tersimpan di perangkat ini | Sub-caption under Theme label |

**Remove** (both files, sole consumers removed by this plan):

| Key | Old EN / ID | Why safe |
|-----|-------------|----------|
| `profile_theme` | Appearance Theme / Tema Tampilan | Only consumer = hub row #4 (grep-verified); row is superseded |
| `js_theme_dark` | Dark / Gelap | Only consumers = header `SYNAPSE_I18N` map + `theme-mode-label` hydration (both removed) |
| `js_theme_light` | Light / Terang | same as above |

**Reuse without change:** `lang_english`, `lang_indonesian`, `lang_switch_label`
(switcher), `common_toggle_theme` (auth/admin toggles remain).

Net dictionary delta: **+5 (or +6 with hint), −3 keys, both files identical sets**.
Values honor invariant L6: no `Rp`/numbers embedded; `EN`/`ID` visible labels are ISO
codes, intentionally **not** dictionary entries (not translatable words).

---

## 6. Blueprint §4 — Verification Matrix & Implementation Order

### 6.1 Implementation order (FUTURE — requires separate approval; do not run now)

1. Edit `application/views/templates/header.php` — remove lang include + theme button +
   theme engine + 2 `SYNAPSE_I18N` keys; add pill truncation classes (§3.1–3.3).
2. Edit `application/language/english/app_lang.php` **and**
   `application/language/indonesian/app_lang.php` — apply §5.3 schema (both in one step).
3. Edit `application/views/profile/index.php` — insert App Preferences card, remove hub
   row #4, append theme segmented script (§4–5).
4. Manual browser pass at 360 / 390 / 480 px (member pages + profile card).
5. Commit (Indonesian message, e.g. `plan/100: declutter header, pindah pengaturan
   bahasa & tema ke profil`) — only 4 files changed.

### 6.2 Verification matrix

| # | Check | Method / expected result |
|---|-------|--------------------------|
| V1 | PHP lint | `php -l` on all 4 touched `.php` files → `No syntax errors detected` |
| V2 | Dict parity | Extract key sets EN vs ID (`grep -oP "(?<=\\$lang\[')[^']+(?='\])"`), `sort`, `diff` → **empty**; counts equal in both files |
| V3 | Orphans gone | grep `user-theme-toggle\|theme-toggle-icon\|btn-theme-hub\|theme-hub-icon\|theme-mode-label\|js_theme_dark\|js_theme_light` → 0 hits in `templates/header.php` + `profile/index.php` (**admin** `topbar.php`/`footer.php` and `views/auth/*` must still show their own hits — scope the grep per file) |
| V4 | Scope guard | `git diff --stat` lists **only** the 4 files; `git diff --stat -- application/views/auth application/views/admin application/views/templates/lang_switcher.php application/views/templates/auth_theme_toggle.php` empty |
| V5 | Header smoke | member session → GET `/` and `/profile` HTTP 200; response contains `#notif-wrapper` and wallet `u-capsule`, does **not** contain `user-theme-toggle` or `lang/switch/en` in header region |
| V6 | Lang engine | on `/profile` click EN→ID anchor → 302 to referer, page re-renders ID (`<html lang="id">`, card labels `Pengaturan …`/`Bahasa`/`Tema`); ID→EN symmetrical; invalid `lang/switch/xx` → 404 |
| V7 | Theme engine | fresh (no stored theme) → `html.dark` present, **Dark** segment pressed; click Light → `.dark` removed, `localStorage['user_theme']==='light'`, `user-theme-change` fired (console), reload keeps light; click Dark → symmetrical; `aria-pressed` toggles correctly |
| V8 | No-thrash | DevTools Performance on click: no forced reflow (no layout reads between writes); transitions color/bg/shadow only |
| V9 | Viewport | 360/390/480 px: header single line, pill truncates (not wraps) with `title` full amount, bell untouched; profile card rows fit without text wrap |
| V10 | Notif regression | open bell → dropdown + mark-all-read-on-open + outside-click close still work (unread badge decrements) |
| V11 | Auth untouched | screenshot/HTTP 200 for `auth/login`, `auth/register`, `auth/change-password`; top-right lang+theme cluster renders (both themes) |
| V12 | Key consumers | `common_toggle_theme` still resolves (auth/admin); `profile_theme`/`js_theme_*` absent from both dicts and no `lang()` call references them (grep `lang('profile_theme')`, `lang('js_theme_`)` → 0) |

### 6.3 Rollback

All changes are view/dictionary-local with zero schema or DB impact. Rollback = `git
checkout` the 4 files; auth/admin surfaces unaffected; `localStorage['user_theme']`
values remain valid under both old and new UIs.

---

## 7. Risks & Notes

- **Discovery tradeoff (accepted):** theme/lang are now 1 tap deeper (Profile) on the
  member side; a header quick-toggle is intentionally out of scope per the brief.
- **Admin & auth engines are separate implementations** (repo already duplicates the
  3-line contract) — this plan does not unify them; do not "helpfully" refactor them here.
- Cross-tab theme sync (via `storage` event) is **not** part of current behavior and is
  not added.
- `profile/change_password.php` (member shell) loses header lang/theme by design; auth
  forced-flow `auth/change_password.php` keeps its cluster (untouched).
- If the optional `profile_theme_hint` key is unwanted, drop it from both files together —
  parity is all-or-nothing per key.

---

*References: `docs/4_UI_UX_GUIDELINES.md`, plan/94 (dual language F1), plan/97 (auth UI +
theme toggle), Phase 32 (theme manager, `header.php` tokens), AGENTS.md conventions
(V6 parity, L5 `js_` map, L6 money/numbers, semantic-class CSS rule).*
