# Plan 72 — Native SVG CAPTCHA & Google reCAPTCHA Purge

Status: **PLAN — approved (doc-only step; code execution pending further instruction)**
Related: Plan 34/35 (reCAPTCHA transport fix), Plan 40/41 (production login session fix), Phase 10D (env contract, `plan/28` §3), Phase 10B (rate limits), Phase 32 (theme manager), M5 (phone normalization, `plan/67`).

---

## 1. Rationale

Google reCAPTCHA v2 is being **completely removed** from the Login and Register flows:

- **External dependency**: loads `https://www.google.com/recaptcha/api.js` (renderer + network) and POSTs every submit to `https://www.google.com/recaptcha/api/siteverify`.
- **Domain whitelisting friction**: every deployment host (incl. shared hosting) must register the site keypair for its exact domain before the widget works.
- **Shared-hosting/transport pain**: the entire Plan 34/40 saga (errno 77 CA-bundle failures, `RECAPTCHA_SECRET` missing → fail-closed lockout of ALL login/register) exists only because of this outbound cURL dependency. Removing it removes the failure class.
- **Latency & UX**: renderer is slow on mobile-first pages and adds a third-party round-trip to every auth attempt.

Replacement: a **zero-dependency, native inline-SVG CAPTCHA engine** (PHP generates an SVG string; no GD/Imagick, no disk I/O, no outbound calls) with a strict single-use + 3-minute TTL session lifecycle, light/dark theme-neutral styling, and an instant AJAX refresh endpoint.

---

## 2. Purge Target Inventory (verified by grep)

| # | Location | Artifact | Action |
|---|----------|----------|--------|
| P1 | `application/views/auth/login.php:22` | `<script src="https://www.google.com/recaptcha/api.js" async defer>` | Delete line |
| P2 | `application/views/auth/login.php:130-133` | `<!-- Google reCAPTCHA v2 -->` + `.g-recaptcha` container | Replace with native component (Phase 4) |
| P3 | `application/views/auth/register.php:22` | same `api.js` script tag | Delete line |
| P4 | `application/views/auth/register.php:131-133` | same `.g-recaptcha` container | Replace with native component (Phase 4) |
| P5 | `application/controllers/Auth.php:13` | `$this->config->load('recaptcha', TRUE);` (constructor) | Delete |
| P6 | `application/controllers/Auth.php:31-160` | `_verify_recaptcha()` + `_resolve_ca_bundles()` (entire cURL/CA logic block) | Delete both methods |
| P7 | `application/controllers/Auth.php:168-170, 200-207` (register) | `$data['recaptcha_site_key']` fetch; POST `g-recaptcha-response` check | Remove; replace gate with `_verify_captcha()` (Phase 3) |
| P8 | `application/controllers/Auth.php:275-277, 294-301` (login) | same site-key fetch + `g-recaptcha-response` check | Remove; replace gate (Phase 3) |
| P9 | `application/config/recaptcha.php` | whole env config file (`RECAPTCHA_SECRET`, `RECAPTCHA_SITE_KEY`) | Delete file (git rm) |
| P10 | `AGENTS.md:19, 31` | "reCAPTCHA v2", "reCAPTCHA secret (.htaccess)" living-guidance references | Reword → native SVG captcha |
| P11 | `docs/1_PRD.md:58-61, 222` | PRD "Bot Protection — Google reCAPTCHA v2" spec (authoritative) | Replace with native SVG captcha spec |
| P12 | `docs/3_ROADMAP.md:24` | historical milestone text "Bot protection via Google reCAPTCHA v2" | Leave as history (append new M-milestone entry for this change) |
| P13 | `docs/5_AUDIT_REPORT.md` | historical audit rows | Leave as history (no action) |
| P14 | `plan/*.md` (34/35/40/41/5/etc.), `application/logs/*.php` | historical write-ups / runtime logs | No action (history) |
| P15 | root `captcha/` dir | empty legacy dir (world-writable, leftover from the old CI3 GD captcha era) | Out of scope; optional later cleanup note |

Live-code grep proof (current): reCAPTCHA tokens exist **only** in `application/controllers/Auth.php`, `application/config/recaptcha.php`, `application/views/auth/{login,register}.php`, `AGENTS.md`, `docs/1_PRD.md`, `docs/3_ROADMAP.md`, `docs/5_AUDIT_REPORT.md`, `plan/*.md` history, and `application/logs/*`. **`.htaccess`, `index.php`, `nginx-rewrite.txt`, `reasonix.toml` are already clean** (Plan 35 removed the `SetEnv RECAPTCHA_SECRET` lines).

**Env-var retirement note**: `RECAPTCHA_SECRET` / `RECAPTCHA_SITE_KEY` become dead (documented as retired in AGENTS.md env note). No code reads them after P5-P9. Leaving stale values in a server env is harmless; removing them from the deployment is recommended but optional.

---

## 3. Native SVG CAPTCHA Engine — Design

### 3.1 Component placement (zero new deps)

- **`application/helpers/captcha_helper.php`** (new; loaded via `$this->load->helper('captcha')` in `Auth` only — mirrors `ratelimit_helper` pattern). Pure rendering functions, no session/DB:
  - `captcha_alphabet()` → unambiguous alphabet string.
  - `build_captcha_svg(string $code): string` → inline SVG string (pure function: same code in → structurally identical output; per-request randomness is the norm).
- **`Auth.php`** owns the lifecycle (session + validation):
  - private `_issue_captcha(): array` → generates code, builds SVG, writes session, returns `['svg' => …, 'code' => …]` for the view.
  - private `_verify_captcha(string $input): bool` → strict single-use evaluation (flush regardless of outcome).
  - public `refresh_captcha()` → JSON endpoint (Phase 3.4).

Rendering lives in the helper (pure), lifecycle in the controller (session-bound) — matches the repo convention while keeping `Auth` free of SVG math.

### 3.2 Character set & generation math

- **Length 5** alphanumeric characters.
- **Alphabet** (excludes visually ambiguous glyphs `0, O, o, 1, I, l`):
  - Uppercase: `A-Z` minus `{I, O}` → 24
  - Lowercase: `a-z` minus `{l, o}` → 24
  - Digits: `2-9` → 8
  - Total pool **56 chars**.
- Code drawn with `random_int()` (CSPRNG), e.g. `$alphabet[random_int(0, 55)]`.
- **Matching**: `strtolower(trim($input)) === strtolower($stored)` → case-insensitive; input is trimmed and uppercased visually in the field but compared lowercased.

### 3.3 SVG geometry & styling (light/dark harmonized)

Canvas: `viewBox="0 0 150 50"`, `width="100%"` inside a fixed-height (≈ `h-14`) rounded container. **No background rect** — the SVG is transparent and inherits the card surface (`--u-surface`: `#fff` light / `#0b1120` dark, Phase 32 tokens), so one rendering serves both themes.

Per-character layout — **font-metric independent** (each glyph is its own `<text text-anchor="middle">`, so no reliance on a monospace font width):

- Baseline slot centers `x_i = 16 + i * 27` for `i = 0..4` (≈ span 16→124 inside 150), jittered `±3`.
- `y` ≈ 33 `±3`.
- Per-char rotation θ ∈ `[−22°, +22°]` about the glyph's own `(x_i, y_i)` via `transform="translate(x_i y_i) rotate(θ)"` with the `<text>` at local `(0,0)`.
- Font: `font-family="ui-monospace, Menlo, Consolas, monospace"`, `font-size="26"`, `font-weight="700"`.

Theme-neutral palette (high contrast on both `#fff` and `#0b1120`):

| Color | Hex | Usage |
|-------|-----|-------|
| Indigo | `#6366f1` | char fill/stroke |
| Cyan | `#06b6d4` | char fill/stroke |
| Violet | `#8b5cf6` | char fill/stroke |

Per char, pick one of the three, with `fill` + a same-hue `stroke` (`stroke-width="0.8"`) for legibility and optional `opacity` 0.85–1.0.

**Disturbance layer (drawn first, under the chars):**
- `fill="none"` on the group.
- 4–5 noise `<line>`s, random endpoints inside the viewBox, `stroke` = random indigo/cyan/violet/slate, `stroke-width` 0.6–1.4, `opacity` 0.15–0.3.
- 18–24 `<circle>` dots, `r` 0.6–1.6, random positions, `fill`/`stroke` random muted hue, `opacity` 0.2–0.5.

Element order in the returned string: `<svg>` → noise group (`fill="none"`) → per-char `<text>` group → `</svg>`. All attribute values are PHP-generated ints/floats from a fixed palette — no user input reaches the SVG, so no XSS surface.

### 3.4 Session lifecycle (security)

Session key namespace: `auth_captcha` → `['code' => …, 'expires' => time() + 180]` (CI3 file sessions, autoloaded; session exists on the unauthenticated auth pages).

- **TTL = 180 s (3 minutes)**. Enforced at verification: `time() > expires` ⇒ reject with the expired message.
- **Strict single-use**: `_verify_captcha()` first reads `auth_captcha`, then **always** unsets it (`$this->session->unset_userdata('auth_captcha')`) — on success AND on every failure path (empty input, wrong code, expired). A captured challenge can never be replayed, and the next submit requires a fresh visual refresh.
- **Multi-tab behavior**: a refresh or a failed submit invalidates the previous code; the currently displayed SVG is always the only valid one (GET page / POST-fail re-render / refresh endpoint all re-issue).
- No DB involvement (session-only) → no schema change.
- The SVG glyphs *are* the secret (as with any visual captcha); stored server-side in the session file, never echoed anywhere but the intended `<svg>` container.

---

## 4. Controller & Validation Integration (`Auth.php`)

### 4.1 Login (`Auth::login`) — current flow at lines 279-351

1. Rate-limit fail-fast stays first (`login:{phone}:{ip}`, 5/15 min, key built from **normalized** phone — unchanged, Phase 10B).
2. Replace the reCAPTCHA gate (P8) with the native one, in the **same position** (fail-fast before credential/DB work):
   ```php
   if (!$this->_verify_captcha((string) $this->input->post('captcha', TRUE))) {
       $data['errors'][] = 'Kode keamanan salah atau sudah kedaluwarsa.';
       $data['values']   = $this->input->post();
       $this->load->view('auth/login', $data);   // view GET re-issues a fresh captcha
       return;
   }
   ```
3. Credential check, ban lockout, session set, forced-password-change redirect — all unchanged.

### 4.2 Register (`Auth::register`) — current flow at lines 163-265

1. Registration-open circuit breaker unchanged.
2. Rate limit unchanged (`register:{ip}`, 5/15 min, `hit()` before captcha — preserves the "every submission counts" anti-bot property).
3. **M5 ordering preserved**: phone normalization happens after the captcha gate, exactly as today (captcha → normalize `$_POST['phone']` → `is_unique` validation → invite-code parent lookup → insert). Captcha sits where reCAPTCHA sat, so no M5/rate-limit semantics shift.
4. Replace gate (P7) with `_verify_captcha()` + same friendly message. `password`/`invite_code` validation and the `uk_phone` 1062 defensive path unchanged.

### 4.3 Constructor

Remove `$this->config->load('recaptcha', TRUE);` (P5). Add `$this->load->helper('captcha');` (session + models already loaded).

### 4.4 Instant refresh endpoint — `Auth::refresh_captcha()`

```php
public function refresh_captcha() {
    if (!$this->input->is_ajax_request()) { show_404(); }   // or redirect back
    $c = $this->_issue_captcha();                            // new code + session + svg
    $this->output->set_content_type('application/json');
    $this->output->set_output(json_encode([
        'svg'        => $c['svg'],
        'expires_in' => 180,
    ]));
}
```

- **GET** (read-only w.r.t. CSRF: it only rotates a challenge in the caller's own session; CI3 CSRF validates POST bodies — see §5). Direct controller URL `auth/refresh_captcha` resolves **without a new `$route` entry** (CI3 maps segment-2 to the method; `change-password` needed a route only because of the dash). Register the route anyway *only* if a cleaner alias is wanted — default: no `routes.php` change.
- Returns fresh SVG + remaining TTL so the UI could show an optional countdown; the button handler swaps the SVG and clears the input.

---

## 5. CSRF Notes (Phase 10C pattern)

- Global CSRF is ON (`csrf_regenerate = FALSE`, token `synapse_csrf_token`).
- Login/register forms use `form_open()` → hidden CSRF field auto-injected → native POST submits already carry the token. **No change.**
- The refresh call is **GET** → no CSRF token required (CI3 validates POST only). Auth pages are standalone and currently do **not** include `templates/csrf_meta.php`; if the refresh were later switched to POST, the csrf meta + `csrfFetch` bootstrap would need inlining into both auth views (noted, not planned).

---

## 6. View Changes (`views/auth/login.php`, `views/auth/register.php`)

### 6.1 Server-side render

`Auth::login()` / `Auth::register()` call `_issue_captcha()` on every GET **and** on every failed-POST re-render, exposing `$data['captcha_svg']` (and no longer `recaptcha_site_key`). Re-render after a captcha failure automatically presents a fresh challenge (old one was flushed).

### 6.2 Form field + SVG component (replaces the `.g-recaptcha` div)

```html
<!-- Native SVG CAPTCHA -->
<div>
    <label for="captcha" class="text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5 block">
        Kode Keamanan <span class="text-rose-500">*</span>
    </label>
    <div class="flex items-center gap-3">
        <input type="text" id="captcha" name="captcha" maxlength="5" autocomplete="off"
               class="u-input h-14 px-5 rounded-2xl text-sm text-center uppercase tracking-[0.35em]
                      focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all w-36"
               placeholder="Kode Keamanan"
               oninput="this.value = this.value.replace(/[^A-Za-z0-9]/g,'').toUpperCase()">
        <div id="captcha-box"
             class="flex-1 h-14 rounded-xl border border-slate-300 dark:border-slate-700
                    bg-white dark:bg-slate-800 flex items-center justify-center overflow-hidden"
             aria-live="polite">
            <?= $captcha_svg ?>   <!-- pre-escaped SVG string -->
        </div>
        <button type="button" id="captcha-refresh" title="Muat ulang kode"
                class="h-14 w-14 shrink-0 rounded-xl border border-slate-300 dark:border-slate-700
                       text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400
                       flex items-center justify-center transition-colors">
            <svg viewBox="0 0 24 24" class="w-6 h-6" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><polyline points="21 3 21 9 15 9"/></svg>
        </button>
    </div>
    <?= form_error('captcha', '<p class="text-xs text-rose-500 mt-1.5">', '</p>') ?>
    <p class="text-xs u-muted mt-1.5">5 karakter alfanumerik. Kode berlaku 3 menit & hanya sekali pakai.</p>
</div>
```

- `maxlength="5"`; field shows uppercase via CSS + the `oninput` sanitizer (value uppercased; comparison is case-insensitive anyway).
- Container: fixed height `h-14`, rounded, `border-slate-300 dark:border-slate-700`; inner SVG transparent so glyphs (indigo/cyan/violet) stay sharp on **both** `bg-white`/`dark:bg-slate-800` (and the Phase-32 token surfaces).
- Refresh button: `type="button"`, circular refresh icon (inline SVG, no FA dependency; FA CDN is already present on these pages but inline SVG keeps the engine dependency-light).

### 6.3 Refresh JS (identical block in both auth views — vanilla)

```js
document.getElementById('captcha-refresh').addEventListener('click', function () {
    var box = document.getElementById('captcha-box');
    var btn = this, orig = btn.innerHTML;
    btn.disabled = true;
    fetch(siteUrl('auth/refresh_captcha'), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) throw 0; return r.json(); })
        .then(function (d) {
            box.innerHTML = d.svg;
            var inp = document.getElementById('captcha');
            if (inp) inp.value = '';
        })
        .catch(function () { window.location.reload(); })   // fallback: full GET re-issues
        .finally(function () { btn.disabled = false; btn.innerHTML = orig; });
});
```

`siteUrl()` resolves via an inline `<script>var siteUrl = function(u){ return '<?= site_url() ?>' + u; };</script>` (base_url is env-driven). No library, no build step — matches the vanilla-JS convention.

---

## 7. Docs & Housekeeping Updates

- **`AGENTS.md`**: reword the two reCAPTCHA references (P10) — describe Auth as "native SVG CAPTCHA (single-use, 3-min TTL, session-bound)"; update the env note to list `RECAPTCHA_SECRET`/`RECAPTCHA_SITE_KEY` as retired.
- **`docs/1_PRD.md`** (authoritative): rewrite the Bot-Protection bullets (§58-61 and §222) to spec the native engine: 5-char unambiguous alphabet, inline transparent SVG, case-insensitive match, strict single-use flush, 3-min TTL, refresh endpoint, message "Kode keamanan salah atau sudah kedaluwarsa."
- **`docs/3_ROADMAP.md`**: append this work as a new completed/planned milestone entry; do not rewrite the historical "1C" checkbox.
- Historical plan docs (`plan/34/35/40/41/5/…`), `docs/5_AUDIT_REPORT.md`, and logs: left untouched (history).
- Optional (not in this scope): remove the empty world-writable root `captcha/` dir and stale server env vars.

---

## 8. Verification & Testing Protocol

### 8.1 Static checks (per modified/new PHP file)

1. `php -l` on `Auth.php`, `captcha_helper.php` (and any view PHP fragments as rendered).
2. Purge proof: `grep -rni "recaptcha\|g-recaptcha\|siteverify\|RECAPTCHA" application/ .htaccess index.php` → **zero matches outside `application/logs/*`**.
3. Confirm `application/config/recaptcha.php` deleted and no `$this->config->load('recaptcha'...)` remains.

### 8.2 Flow tests (browser/curl, `php -S localhost:8080`)

| # | Case | Steps | Expected |
|---|------|-------|----------|
| T1 | Page render | GET `/login`, `/register` | HTTP 200; SVG captcha box + "Kode Keamanan" input + refresh button present; no `api.js`, no `.g-recaptcha`, no `g-recaptcha-response` in HTML |
| T2 | Valid submission | Read code from session, submit login+register with correct code | Login → 302 `home` (or forced change-password); register → flashdata success → redirect login |
| T3 | Wrong code | Submit with an incorrect 5-char code (rate limits aside) | Reject with **"Kode keamanan salah atau sudah kedaluwarsa."**; a NEW svg renders; resubmit of the SAME old code (now flushed) is rejected again (single-use proof) |
| T4 | Expired code | Set `auth_captcha.expires` to `time()-1` (or temporarily TTL=1) then submit correct code | Same friendly message; new svg issued |
| T5 | Empty code | Submit with empty captcha field | Same friendly rejection |
| T6 | Refresh cycle | Click refresh; compare svg + code | New SVG differs from old; input cleared; session code rotated (old code now invalid) |
| T7 | Theme contrast | Toggle light/dark (`localStorage user_theme`), reload both pages | Glyphs (indigo/cyan/violet) legible on `bg-white` and `dark:bg-slate-800` card; noise subtle in both |
| T8 | Case-insensitivity | Enter code in lowercase | Accepted (compare is lowercased both sides) |
| T9 | Rate-limit intact | 6 rapid register POSTs | 6th blocked by Phase 10B message (captcha no longer the limiter) |
| T10 | CSRF intact | POST login without CSRF token | CI3 CSRF rejection (unchanged behavior) |

### 8.3 Regression guards

- Login credential-wrong path still increments the rate-limit counter and shows "Nomor telepon atau kata sandi salah."
- Register still normalizes phone before `is_unique`, and duplicate concurrent phone still hits the friendly `uk_phone` 1062 path.
- Captcha failure must NOT consume a rate-limit "hit" for login (mirrors current reCAPTCHA ordering: login checks throttle, then captcha; register hits the counter before captcha — keep exactly as-is).

---

## 9. Implementation Phases (execution deferred — awaiting go-ahead)

1. **Phase 1 — Engine**: new `application/helpers/captcha_helper.php` (`captcha_alphabet()`, `build_captcha_svg()`); `Auth` private `_issue_captcha()` / `_verify_captcha()` (session TTL+single-use); constructor swaps `config->load('recaptcha')` for `helper('captcha')`.
2. **Phase 2 — Purge**: delete `application/config/recaptcha.php`; strip `_verify_recaptcha()`/`_resolve_ca_bundles()` + site-key fetches + `g-recaptcha-response` gates from `Auth.php`; remove `api.js` + `.g-recaptcha` from both views (component re-added in Phase 4).
3. **Phase 3 — Integration & refresh**: wire `_verify_captcha()` gates into `login()`/`register()` with the friendly error; expose `$data['captcha_svg']` on every render; add `Auth::refresh_captcha()` JSON endpoint.
4. **Phase 4 — Views & JS**: captcha field + SVG container + refresh button component in both auth views; shared vanilla refresh JS + `siteUrl` helper.
5. **Phase 5 — Docs & verification**: AGENTS.md + `docs/1_PRD.md` + `docs/3_ROADMAP.md` updates; run §8 static + flow tests; write `plan/73_…_SUMMARY.md` after implementation.

Files touched: `application/helpers/captcha_helper.php` (new), `application/controllers/Auth.php`, `application/views/auth/login.php`, `application/views/auth/register.php`, `application/config/recaptcha.php` (deleted), `AGENTS.md`, `docs/1_PRD.md`, `docs/3_ROADMAP.md`, `plan/72_…` (this doc). No schema/DB change. Commit message (Indonesian, repo style): e.g. `M8: purge Google reCAPTCHA & implement native SVG captcha (login/register)`.
