# Phase 32 — User Theme Manager (Dark Default) & Futuristic AI GPU UI Refactor

**Project:** Synapse (webtable) · **Baseline:** `main` · **Branch kerja:** `fase-32-user-theme-manager` (proposed)
**Mode:** PLANNING — blueprint menunggu persetujuan user. **Belum ada kode yang diubah.**
**Referensi:** `docs/4_UI_UX_GUIDELINES.md` (palet, z-index, density), AGENTS.md (SQL hanya di model, `php -l` tiap file, branch per fase, commit bahasa Indonesia), `plan/30_ADMIN_THEME_MANAGER_PLAN.md` + `plan/31_ADMIN_THEME_MANAGER_SUMMARY.md` (arsitektur tema yang sudah terbukti di sisi admin — pola sama, palet & token berbeda), `plan/28_PHASE_10D_PLAN.md` (format).

> **Posisi vs Phase 30:** Admin Command Center (Phase 30, SELESAI) memakai arsitektur `darkMode:'class'` + CSS variable tokens + semantic classes (prefix `t-`) dengan palet Bloomberg slate. Phase 32 menerapkan **arsitektur yang sama** di aplikasi user-facing dengan palet **Futuristic AI GPU** (Deep Obsidian + Neural Surface + glow cyan/indigo), semantic classes ber-prefix **`u-`** (user) agar tidak rancu dengan `t-` (admin). Prasyarat yang sama berlaku: pastikan working tree bersih (Phase 30/10D sudah di-commit) sebelum branching.

---

## Ringkasan Perubahan

| # | Perubahan | File |
|---|-----------|------|
| 1 | **Infrastruktur tema user** — anti-FOUC inline script (dark default, key `user_theme`), `tailwind.config darkMode:'class'`, design tokens `--u-*` (dark Obsidian default / light Tech SaaS), semantic component classes `.u-*`, `color-scheme`, font Inter + JetBrains Mono | `application/views/templates/header.php` (edit) |
| 2 | **Toggle Sun/Moon** di sticky top header, di samping capsule wallet/notif | `application/views/templates/header.php` (edit) |
| 3 | **Bottom nav glassmorphic** — `u-nav-bottom` (glass blur + glow border), active item dengan glow indicator, tokenize | `application/views/templates/bottom_nav.php` (edit) |
| 4 | **Home** — hero tetap dark (invariant), kartu identitas/stats → semantic, tombol aksi → `u-btn-cyber`, toast copy → token-aware | `application/views/home/index.php` (edit) |
| 5 | **Marketplace** — kartu GPU → `u-card-gpu`, flash → `u-flash-*`, bottom sheet modal → `u-modal`, class JS-set → token-aware | `application/views/marketplace/index.php` (edit) |
| 6 | **Rentals** — estetika "server blade" yang sudah dark di-token-kan agar **flip ke light** (`.blade-bg`, `.data-cell`, `.progress-bar` → vars), glow-border tetap sebagai aksen, help modal → `u-modal` | `application/views/rentals/index.php` (edit) |
| 7 | **Team** — mission card dark + section light (campuran) → semua `u-card`; QR tetap putih (keputusan desain), modals → `u-modal`, class JS-set → token-aware | `application/views/team/index.php` (edit) |
| 8 | **Wallet** — balance card → `u-card-fin`, topup form → `u-card`, string class `INACTIVE/ACTIVE` (JS) → token-aware, pending/ledger → token | `application/views/wallet/index.php` (edit) |
| 9 | **Withdraw** — bank card → `u-card-fin`, form/balance box → `u-card`, input → `u-input` | `application/views/wallet/withdraw.php` (edit) |
| 10 | **Bank Bind** — bank card → `u-card-fin`, form → `u-card`, warning → token-aware, input/select → `u-input`/`u-select` | `application/views/wallet/bank_bind.php` (edit) |
| 11 | **Profile** — kartu identitas/referral/hub → semantic; **item "Tema Tampilan" baru di menu hub** (toggle + label mode aktif); edit-profile sheet → `u-modal` | `application/views/profile/index.php` (edit) |
| 12 | **Profile Change Password** — form card → `u-card`, input → `u-input`, flash → `u-flash-*` | `application/views/profile/change_password.php` (edit) |
| 13 | **Notification** — kartu unread/read → class token (`u-notif-unread`/`u-notif-read`), pill/date separator → token, JS `markAllRead` → token-aware | `application/views/notification/index.php` (edit) |
| 14 | **Help** (bonus — ditemukan saat audit, satu shell sama) — card FAQ/support → semantic | `application/views/help/index.php` (edit) |
| 15 | **Auth standalone** — infra tema sendiri di tiap file (anti-FOUC + token subset + semantic subset), layout split hero dark + form card flip; **tanpa toggle UI** (pre-auth), mengikuti preferensi tersimpan | `application/views/auth/login.php`, `auth/register.php`, `auth/change_password.php` (edit) |
| 16 | Blueprint ini | `plan/32_USER_THEME_MANAGER_PLAN.md` (**new**) |

**Tanpa perubahan:** controller, routes, model, config — toggle murni client-side (localStorage + class `dark` di `<html>`), tidak ada endpoint baru.

---

## 1. Hasil Audit (fakta tervalidasi dari source)

### 1.1 Keadaan tema saat ini — aplikasi user 100% light, kecuali 2 halaman campuran & 1 halaman dark total

| File | Keadaan | Bukti (baris) |
|------|---------|---------------|
| `templates/header.php` | **Shell light** — body `bg-slate-200`, app `bg-slate-50`; header `bg-white/80 backdrop-blur-md`; tanpa config dark, tanpa anti-FOUC, tanpa `color-scheme` | 15–16, 19 |
| `templates/bottom_nav.php` | **Light** — `bg-white border-t border-slate-200`, active `text-indigo-600` | 2, 5 |
| `home/index.php` | **Campuran** — hero `from-slate-900` (dark, ok); kartu identitas & 4 stats `bg-white`/`bg-slate-50`; tombol aksi `bg-slate-900 hover:bg-blue-600`; toast copy `bg-slate-900` + JS swap `bg-slate-100` | 23, 42, 48, 66–75, 114–147, 152 |
| `marketplace/index.php` | **Light** — kartu produk `bg-white border-slate-100`, button `bg-slate-900 hover:bg-slate-800`; flash `bg-rose-50`/`bg-emerald-50`; modal sheet `bg-white` (**z-[60] ✓ sudah benar**); JS `text-emerald-600`/`text-rose-600` | 23, 40, 57, 115, 121 |
| `rentals/index.php` | **Dark total (server blade)** — `.blade-bg` gradient `#0b1120→#111827`, card `bg-slate-900`, `.data-cell` `rgba(15,23,42,.8)`, `.progress-bar` `rgba(30,41,59,.8)`; help modal **`bg-white` (sheet light di tengah halaman dark — janggal)**; header `text-transparent bg-clip-text from-slate-900 to-slate-700` (**gelap di atas bg dark — tidak terbaca!**) | 12–14, 57, 69–70, 89, 146, 243 |
| `team/index.php` | **Campuran** — mission card `bg-slate-900` (dark); 3 section + 2 modal `bg-white` (light); QR `colorDark:#1e293b colorLight:#ffffff` hardcoded; JS swap `bg-indigo-500`↔`bg-emerald-500` (invariant, ok) | 9, 68, 94, 126, 182–183, 230–232 |
| `wallet/index.php` | **Campuran** — balance card `bg-slate-900` (dark); topup form `bg-white`; amt-btn **class di-set via JS string** `INACTIVE`/`ACTIVE` (`bg-slate-50 border-slate-200 text-slate-700` — tidak theme-aware); pending/ledger `bg-white` | 18, 57, 180–182, 95, 137 |
| `wallet/withdraw.php` | **Campuran** — bank card `bg-slate-900`; balance box & form `bg-white`; fee box `bg-amber-50`; input `bg-slate-50` | 39, 59, 83, 75 |
| `wallet/bank_bind.php` | **Campuran** — bank card `bg-slate-900`; form `bg-white`; warning `bg-rose-50`; input/select `bg-slate-50` | 27, 81, 69, 97 |
| `profile/index.php` | **Light total** — identitas/referral/hub `bg-white`; hub list `hover:bg-slate-50` + icon chips tinted; edit sheet `bg-white` (**z-[60] ✓**); **belum ada item pengaturan tema** | 18, 53, 71, 76, 155 |
| `profile/change_password.php` | **Light total** — form card `bg-white`, input `bg-white`/`bg-slate-50` | 32, 46, 217 |
| `notification/index.php` | **Light total** — kartu unread `bg-slate-50` / read `bg-white`; pill tinted; separator `bg-slate-200`; **JS `markAllRead` swap class light-hardcoded** (`bg-slate-50`→`bg-white`, `text-slate-900`→`text-slate-600`) | 76, 139–159 |
| `help/index.php` | **Light total** — card `bg-white border-slate-200` | 16 |
| `auth/login.php`, `register.php`, `change_password.php` | **Standalone split hero** — top `bg-slate-900` (dark) + form card `bg-white rounded-t-[2.5rem]` (light); tanpa config dark, tanpa anti-FOUC | login 10–24 |
| `templates/csrf_meta.php` | Netral (JS murni) — **tidak diubah** | — |

**Dampak:** dark-as-default tanpa refactor akan membuat halaman light (home/marketplace/profile/wallet/notification/help) menyilaukan, dan halaman dark (rentals) tidak bisa dialihkan ke light. Toggle tanpa per-halaman tokenisasi tidak berfungsi — **seluruh literal warna netral harus di-token-kan**.

### 1.2 Ukuran refactor (grep literal warna sensitif tema — `bg-white|bg-slate-|text-slate-|border-slate-`)

```
team/index.php: 52    profile/index.php: 40    wallet/index.php: 35    help/index.php: 31
rentals/index.php: 28 home/index.php: 27       wallet/bank_bind.php: 18 notification/index.php: 18
marketplace/index.php: 18 header.php: 16       wallet/withdraw.php: 13 profile/change_password.php: 10
bottom_nav.php: 5      auth/*: 35 (3 file)
```

≈ **335 titik** di 16 file user (termasuk 3 auth standalone). Yang **tetap boleh mentah**: aksen brand/aksi (cyan-400/indigo-600/emerald-600/rose-600/amber-600 solid) karena identik di kedua tema, plus warna di dalam hero/gambar.

### 1.3 Temuan desain tambahan (bug/inkonsistensi yang di-cover fase ini)

1. **`rentals/index.php` baris 89** — judul "Infrastruktur Aktif" memakai `text-transparent bg-clip-text bg-gradient-to-r from-slate-900 to-slate-700` (gradient gelap) di atas background `.blade-bg` yang **gelap** → teks nyaris tak terbaca. Wajib diperbaiki (token `--u-text` atau gradient cyan→indigo terang di dark).
2. **`header.php` baris 49** — dropdown notifikasi `z-50`, melanggar `docs/4_UI_UX_GUIDELINES.md` §2 (modal/dropdown wajib `z-[60]`) → akan tertutup bottom nav `z-50` di posisi scroll tertentu. Fix: `z-[60]`.
3. **`home/index.php` baris 48** — toast copy `z-50` di atas bottom nav; sama-sama perlu `z-[60]` (di bawah modal tapi di atas nav). Fix: `z-[60]`.
4. **Class yang di-set via JS** (harus ikut token-aware): `home` swap `bg-slate-100` (baris 66–75), `marketplace` `text-emerald-600`/`text-rose-600` (115/121), `wallet` string `INACTIVE`/`ACTIVE` (180–182), `team` swap `bg-indigo-500` (invariant — aman), `notification` `markAllRead` (139–165).
5. **Auth pages memuat reCAPTCHA** — widget selalu putih; di dark mode tetap terbaca (keputusan: biarkan, bungkus `rounded-xl overflow-hidden` agar estetis).

---

## 2. Desain Theme Manager User

### 2.1 Model tema — Tailwind `darkMode: 'class'` + CSS variable tokens + semantic classes (pola Phase 30)

Strategi identik Phase 30 (terbukti di admin): **satu sumber kebenaran** = CSS variable tokens `--u-*` yang flip di `html.dark`. Halaman memakai **semantic component classes** (`.u-*`) — bukan `dark:` variant per elemen — agar ~335 titik jadi ~15 kelas reusable. `darkMode:'class'` tetap diaktifkan untuk one-off kecil.

- Key localStorage: **`user_theme`** (`'dark' | 'light'`) — terpisah dari `admin_theme` (konvensi dual-auth hard separation).
- Default: **dark** — hanya user yang menyimpan `'light'` yang tidak mendapat class `dark`.
- `html.dark { color-scheme: dark; }` → input date/select/scrollbar native ikut tema.

### 2.2 `header.php` — urutan `<head>` (kritis, identik pola Phase 30 §2.2)

1. **Anti-FOUC inline script — script PERTAMA di `<head>`, sebelum Tailwind/CDN:**
   ```html
   <script>
       // User Theme Manager — anti-FOUC: apply dark class before first paint
       (function () {
           try {
               if (localStorage.getItem('user_theme') !== 'light') {
                   document.documentElement.classList.add('dark');
               }
           } catch (e) {
               document.documentElement.classList.add('dark'); // storage blocked → default dark
           }
       })();
   </script>
   ```
2. `tailwind.config` (setelah script Tailwind Play CDN, pola existing admin): `darkMode: 'class'` + font `sans: Inter`, `mono: JetBrains Mono` + Google Fonts `<link>` (perluasan: angka finansial `font-mono` per guideline §4).
3. Blok `<style>` → tambah **design tokens + semantic classes** (§2.4).
4. `<body>` → `u-shell`; app container `bg-slate-50` → `u-app`; header `bg-white/80` → `u-topbar`.
5. Notif dropdown: `z-50` → `z-[60]`, literals → token.

### 2.3 Toggle Sun/Moon — 2 titik kontrol

**A. Sticky top header** (`header.php`, di samping capsule wallet, sebelum bell):
```html
<button id="user-theme-toggle" type="button" aria-label="Ganti tema"
        class="w-9 h-9 rounded-full u-btn-ghost flex items-center justify-center transition-colors active:scale-95"
        onclick="toggleUserTheme()">
    <i id="theme-toggle-icon" class="fas fa-moon text-sm"></i>
</button>
```

**B. Menu hub Profile** — item baru di list "Menu Akun" (di atas "Keamanan & Sandi"):
```html
<button type="button" id="btn-theme-hub"
        class="w-full flex items-center justify-between px-5 py-4 u-row-hover transition border-b u-divide-b active:scale-[0.98] text-left">
    <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl bg-cyan-50 text-cyan-600 dark:bg-cyan-500/10 dark:text-cyan-400 flex items-center justify-center">
            <i id="theme-hub-icon" class="fas fa-moon text-sm"></i>
        </div>
        <span class="text-sm font-medium u-text">Tema Tampilan</span>
    </div>
    <span class="flex items-center gap-2">
        <span id="theme-mode-label" class="text-[10px] font-bold u-muted uppercase tracking-wider">Gelap</span>
        <i class="fas fa-chevron-right text-[10px] u-muted"></i>
    </span>
</button>
```

**Satu fungsi global `toggleUserTheme()`** (diletakkan di `header.php` agar tersedia di semua halaman, termasuk profile):
```js
function toggleUserTheme() {
    var html = document.documentElement;
    var dark = html.classList.toggle('dark');
    try { localStorage.setItem('user_theme', dark ? 'dark' : 'light'); } catch (e) {}
    syncThemeUI(dark);
    window.dispatchEvent(new CustomEvent('user-theme-change', { detail: { dark: dark } }));
}
function syncThemeUI(dark) {
    document.querySelectorAll('#theme-toggle-icon').forEach(function (i) {
        i.className = 'fas ' + (dark ? 'fa-sun' : 'fa-moon') + ' text-sm';
    });
    var hubIcon = document.getElementById('theme-hub-icon');
    if (hubIcon) hubIcon.className = 'fas ' + (dark ? 'fa-sun' : 'fa-moon') + ' text-sm';
    var lbl = document.getElementById('theme-mode-label');
    if (lbl) lbl.textContent = dark ? 'Gelap' : 'Terang';
}
// init: sinkron ikon saat DOM ready (baca class <html>)
document.addEventListener('DOMContentLoaded', function () {
    syncThemeUI(document.documentElement.classList.contains('dark'));
});
```
Arah ikon (keputusan, konsisten Phase 30): di dark mode tampil `fa-sun` ("klik → terang"), di light tampil `fa-moon` ("klik → gelap").

### 2.4 Design tokens & palette

#### Dark (DEFAULT) — Futuristic AI GPU: Deep Obsidian + Neural Surface + cyan/indigo glow

| Token | Dark (`html.dark`) | Dipakai untuk |
|-------|--------------------|---------------|
| `--u-bg` | `#040711` (Deep Obsidian) | Shell body, app container |
| `--u-surface` | `#0b1120` (Neural Surface) | Card, header, bottom nav, modal sheet |
| `--u-surface-2` | `#0f172a` (slate-900) | Inset: hero inner, data-cell, input |
| `--u-surface-3` | `#1e293b` (slate-800) | Badge netral, avatar, tombol disabled |
| `--u-border` | `rgba(148,163,184,0.18)` | Border card/shell netral |
| `--u-border-glow` | `rgba(56,189,248,0.25)` (cyan-500/25) | Border aksen card GPU, nav atas |
| `--u-border-glow-2` | `rgba(99,102,241,0.30)` (indigo-500/30) | Border aksen sekunder, mission card |
| `--u-text` | `#e6edf7` | Judul, teks utama |
| `--u-text-2` | `#94a3b8` (slate-400) | Body sekunder |
| `--u-muted` | `#64748b` (slate-500) | Label micro, placeholder |
| `--u-input-bg` | `#0d1526` | Input/select |
| `--u-hover` | `rgba(148,163,184,0.08)` | Row hover, nav hover |
| `--u-active` | `rgba(56,189,248,0.12)` | Nav active bg, chip aktif |
| `--u-glow` | `0 0 20px rgba(56,189,248,0.12), 0 0 40px rgba(99,102,241,0.08)` | Shadow glow card GPU / balance |
| `--u-glass` | `rgba(11,17,32,0.72)` | Bottom nav glassmorphic |
| `--u-glass-border` | `rgba(56,189,248,0.15)` | Bottom nav border |
| `--u-divide` | `rgba(148,163,184,0.12)` | divide-y antar item |

#### Light — Sleek Tech SaaS (crisp white/slate + refined indigo)

| Token | Light (`:root`) |
|-------|-----------------|
| `--u-bg` | `#f1f5f9` (slate-100) |
| `--u-surface` | `#ffffff` |
| `--u-surface-2` | `#f8fafc` (slate-50) |
| `--u-surface-3` | `#f1f5f9` (slate-100) |
| `--u-border` | `#e2e8f0` (slate-200) |
| `--u-border-glow` | `rgba(99,102,241,0.25)` |
| `--u-border-glow-2` | `rgba(79,70,229,0.20)` |
| `--u-text` | `#0f172a` (slate-900) |
| `--u-text-2` | `#475569` (slate-600) |
| `--u-muted` | `#94a3b8` (slate-400) |
| `--u-input-bg` | `#ffffff` |
| `--u-hover` | `#f1f5f9` (slate-100) |
| `--u-active` | `#eef2ff` (indigo-50) |
| `--u-glow` | `0 10px 30px rgba(15,23,42,0.08)` |
| `--u-glass` | `rgba(255,255,255,0.78)` |
| `--u-glass-border` | `rgba(226,232,240,0.9)` |
| `--u-divide` | `#f1f5f9` (slate-100) |

**Aksen yang identik di dua tema (tidak di-token):** `text-cyan-400`, `bg-indigo-600`, `bg-blue-600`, `bg-emerald-500`, `bg-rose-500`, `bg-orange-500`, `bg-amber-500` + hover-nya; gradient `from-blue-600 to-indigo-600` (tombol klaim rentals); gradient glow `.glow-border` (`#3b82f6→#8b5cf6`).

### 2.5 Semantic component classes (prefix `u-`, di `<style>` `header.php`)

- **Shell/App:** `.u-shell` (bg `--u-bg` + text `--u-text` + `font-sans`), `.u-app` (container `max-w-[480px]`, bg `--u-bg` — menggantikan `bg-slate-50`), `.u-text` / `.u-text-2` / `.u-muted` (warna teks).
- **Topbar:** `.u-topbar` (bg `--u-glass` + `backdrop-blur-md` + border-b `--u-border` + `sticky top-0 z-40`); capsule wallet → `.u-capsule` (bg `--u-surface-2` + border `--u-border`).
- **Card:** `.u-card` (bg `--u-surface` + border `--u-border` + `rounded-2xl`), `.u-card-gpu` (= `.u-card` + border `--u-border-glow` + shadow `--u-glow` + hover lift), `.u-card-fin` (kartu finansial dark Bloomberg — bg gradient `--u-surface-2→--u-surface` + border glow, teks putih; di light flip ke indigo-50 tint), `.u-card-inset` (bg `--u-surface-2`).
- **Input/Form:** `.u-input`, `.u-select` (bg `--u-input-bg` + border `--u-border-strong`→ pakai `--u-border` + fokus ring indigo/cyan), `.u-label` (uppercase micro, `--u-muted`).
- **Button:** `.u-btn-cyber` (primary: `bg-gradient-to-r from-indigo-600 to-cyan-500` (dark) / `from-indigo-600 to-blue-600` (light) + `text-white` + glow shadow), `.u-btn-dark` (pengganti pola `bg-slate-900 hover:bg-blue-600` — flip: dark `bg-indigo-600/90`, light `bg-slate-900`), `.u-btn-ghost` (netral `--u-surface-3`).
- **Badge:** `.u-badge-ai` (chip futuristik: `bg-cyan-500/10 border border-cyan-500/30 text-cyan-400` dark / `bg-cyan-50 border-cyan-200 text-cyan-700` light), `.u-badge-success`, `.u-badge-danger`, `.u-badge-warning`, `.u-badge-info`, `.u-badge-indigo` (tinted, pasangan `dark:`).
- **Nav:** `.u-nav-bottom` (glass: bg `--u-glass` + `backdrop-blur-xl` + border-t `--u-glass-border` + `fixed bottom-0 z-50 max-w-[480px] h-16`), `.u-nav-item` (warna `--u-text-2`), `.u-nav-active` (warna aksen cyan-400/indigo-600 + **glow dot indicator** di atas ikon + `drop-shadow`).
- **Modal:** `.u-modal` (bg `--u-surface` + rounded-t-3xl), `.u-modal-backdrop` (`bg-black/60 backdrop-blur-sm`), **semua modal/dropdown wajib `z-[60]`** (perbaikan temuan 1.3).
- **Flash:** `.u-flash-success`, `.u-flash-error` (tinted emerald/rose, pasangan `dark:`).
- **Misc:** `.u-divide` (divide-y `--u-divide`), `.u-row-hover` (hover `--u-hover`), `.u-hero` (hero home — tetap dark invariant dengan overlay gradient), `.u-toast`, `.u-chip` (icon chip tinted — flip via `dark:` pasangan), `.u-progress-track` / `.u-progress-fill` (token untuk rentals), `.u-data-cell` (token untuk rentals `.data-cell`).

### 2.6 Tokenisasi estetika "Server Blade" (rentals) — kunci flip dark→light

`rentals/index.php` sudah memuat benih futuristik yang benar (dark blade, glow border, scanline). Agar flip ke light tetap elegan:
- `.blade-bg` gradient `#0b1120→#111827→#0f172a` → ganti dengan `background: linear-gradient(180deg, var(--u-surface) 0%, var(--u-surface-2) 40%, var(--u-bg) 100%)` + `color: var(--u-text)`.
- `.data-cell` `rgba(15,23,42,0.8)` → `var(--u-surface)`; label `.data-cell span` `#64748b` → `var(--u-muted)`.
- `.progress-bar` track `rgba(30,41,59,0.8)` → `var(--u-surface-3)`; `.progress-fill` gradient `#3b82f6→#6366f1` **tetap** (aksen invariant).
- `.glow-border::before` gradient `#3b82f6,#8b5cf6` **tetap** (aksen).
- Judul halaman (bug 1.3-1): gradient teks → di dark `from-cyan-300 to-indigo-300`, di light `from-slate-900 to-indigo-600` (via `dark:` variant, one-off).
- Teks `text-white` di dalam card blade → `var(--u-text)`; `text-slate-500` → `var(--u-muted)`/`--u-text-2`.

### 2.7 Auth standalone (login/register/change_password)

Sama seperti `admin/login.php` (Phase 30 §2.6): masing-masing file mendapat **infra tema duplikat minimal** di `<head>` sendiri:
1. Anti-FOUC inline script (key `user_theme`, dark default) — script pertama.
2. `tailwind.config { darkMode:'class' }` setelah CDN.
3. Token subset `--u-*` (bg/surface/border/text/muted/input) + semantic subset (`.u-shell`, `.u-card`, `.u-input`, `.u-label`, `.u-btn-cyber`, `.u-flash-*`) + `color-scheme`.
4. Layout split hero dipertahankan: top hero `bg-slate-900` **tetap dark di kedua tema** (identitas brand — keputusan desain), form card `bg-white` → `.u-card` flip (dark: `--u-surface`).
5. **Tanpa toggle UI** (halaman pre-auth, belum ada user) — mengikuti preferensi tersimpan via anti-FOUC sehingga pengguna yang kembali tidak kaget. (Catatan: bisa ditambahkan nanti bila diinginkan — di luar scope.)

### 2.8 Keputusan desain khusus

- **QR code (team):** tetap `colorLight:#ffffff` + `colorDark:#1e293b` di **dua tema** — QR adalah artefak scan; kontras putih/gelap menjamin keterbacaan semua scanner. Container QR dibiarkan `bg-white` (light invariant) dan dibungkus border token. **Bukan bug.**
- **Hero images (placehold.co):** tetap apa adanya (konten gambar); overlay gradient hero memakai warna token transparan agar blending mulus di dua tema.
- **Kartu finansial (`u-card-fin`) tetap gelap di dua tema** (estetika terminal untuk angka uang, konsisten Phase 30 treasury inner cells) — di light mode pakai gradient `from-slate-900 to-indigo-950` yang sama seperti sekarang agar tidak menyilaukan di shell putih (kontras tinggi memang diinginkan untuk saldo).

---

## 3. Refactor per Halaman (matriks detail)

| File | Konversi utama |
|------|----------------|
| `templates/header.php` | Anti-FOUC; `tailwind.config darkMode:'class'` + font Inter/JetBrains Mono; token `--u-*` + semantic `.u-*`; body → `u-shell`; app container → `u-app`; header → `u-topbar`; capsule wallet → `u-capsule`; **toggle Sun/Moon + `toggleUserTheme()`/`syncThemeUI()`**; notif dropdown `z-50`→`z-[60]` + tokenize (header, item, footer, empty state); notif item tinted icon → `u-chip` + `dark:` |
| `templates/bottom_nav.php` | Nav → `.u-nav-bottom` (glass); item → `.u-nav-item`; active → `.u-nav-active` + glow dot (`<span class="u-nav-dot">`); label micro → `u-muted` |
| `home/index.php` | Hero tetap (invariant, tokenize overlay via `dark:` optional); kartu identitas → `.u-card`; invite code chip → `u-card-inset`; stats 2×2 → `.u-card` + icon chip `u-chip`; nilai angka `text-slate-900` → `u-text`; tombol aksi → `.u-btn-cyber`; toast copy → `.u-toast` + `z-[60]`; **JS swap `bg-slate-100`/`hover:bg-slate-200` → `u-btn-ghost`-ish** (ganti via `classList` ke class token atau set CSS var) |
| `marketplace/index.php` | Flash → `.u-flash-*`; header halaman → token; kartu produk → `.u-card-gpu` (border glow cyan + shadow `--u-glow`); harga `text-slate-900` → `u-text`; tombol "Sewa Sekarang" `bg-slate-900 hover:bg-slate-800` → `.u-btn-cyber`; modal sheet `bg-white` → `.u-modal`; handle/drag `bg-slate-300` → token; info rows → token; separator `bg-slate-100` → `--u-divide`; **JS `balanceEl.className` `text-emerald-600`/`text-rose-600` → tambah `dark:text-emerald-400`/`dark:text-rose-400`** |
| `rentals/index.php` | **Tokenisasi blade** (§2.6); help button tetap tinted indigo (`dark:` pasangan); flash sudah tinted (`/10` opacity — ok, verifikasi kontras); header judul fix (bug 1.3-1); empty state → token; card rental `.glow-border bg-slate-900 border-slate-700` → `.u-card` + `glow-border` + border `--u-border-glow-2`; data grid → `.u-data-cell` token; status "Online" chip → `.u-badge-ai`; tombol disabled `bg-slate-800` → `u-surface-3`; summary bar gradient → token + border glow; **help modal `bg-white` → `.u-modal`** + inner info boxes → tinted `dark:` |
| `team/index.php` | Mission card `bg-slate-900 border-slate-700` → `.u-card` + `--u-border-glow-2`; progress track `bg-slate-700` → `u-surface-3`; teks putih → token; badge `Rp 80.000` → `.u-badge-indigo`; button klaim gradient tetap; disabled `bg-slate-700 text-slate-400` → token; help button → tinted `dark:`; 3 section `bg-white` → `.u-card`; referral box `bg-slate-50` → `u-card-inset`; QR container tetap putih (keputusan §2.8); metric cards `bg-slate-50`/`bg-emerald-50`/`bg-indigo-50` → `.u-card`/tinted `dark:`; member list border/badge → tinted `dark:`; **2 modal `bg-white` → `.u-modal`**; JS swap copy `bg-indigo-500`→`bg-emerald-500` (invariant, ok — tanpa perubahan) |
| `wallet/index.php` | Balance card `bg-slate-900` → `.u-card-fin` (tetap gelap dua tema, §2.8) + badge AKTIF → `.u-badge-ai`; tombol Top Up `bg-blue-600` tetap; tombol disabled → token; topup form `bg-white` → `.u-card`; amt-btn **string JS `INACTIVE`** → class token `.u-amt-inactive` (definisikan di CSS: `bg var(--u-surface-2) border var(--u-border) text var(--u-text-2)` + hover tint cyan) — `ACTIVE` tetap `bg-blue-600 text-white shadow-md` (invariant); input amount → `.u-input`; pending → `.u-card` + tinted `dark:`; ledger card → `.u-card`; row hover → `.u-row-hover`; divide → `.u-divide`; icon circle credit/debit → tinted `dark:`; **JS `resetAllBtns` memakai class token, bukan string literal** |
| `wallet/withdraw.php` | Back button → `u-btn-ghost`; bank card → `.u-card-fin`; balance box `bg-white` → `.u-card-inset`; input → `.u-input`; fee box `bg-amber-50` → tinted `dark:`; submit `bg-orange-500` tetap |
| `wallet/bank_bind.php` | Back button → `u-btn-ghost`; bank card → `.u-card-fin`; warning `bg-rose-50` → tinted `dark:`; form `bg-white` → `.u-card`; icon chip → `u-chip`; input/select → `.u-input`/`.u-select`; notice `bg-amber-50` → tinted `dark:`; submit `bg-slate-900 hover:bg-indigo-600` → `.u-btn-dark` |
| `profile/index.php` | Flash → `.u-flash-*`; identity card `bg-white` → `.u-card`; avatar ring `border-indigo-100` → `dark:` pasangan; level badge → `.u-badge-indigo`; referral card → `.u-card`; box kode → `u-card-inset`; **hub "Menu Akun" → `.u-card` + list item `.u-row-hover` + divide `.u-divide`**; icon chips → `u-chip` + `dark:`; **item baru "Tema Tampilan" (§2.3-B)**; logout tinted rose `dark:`; edit-profile sheet `bg-white` → `.u-modal`; handle `bg-slate-300` → token; input → `.u-input` |
| `profile/change_password.php` | Flash → `.u-flash-*`; back → `u-btn-ghost`; form card → `.u-card`; icon lock `bg-amber-50` → tinted `dark:`; input → `.u-input`; label → `.u-label`; submit `bg-indigo-600` tetap |
| `notification/index.php` | Header/mark-all → token; separator `bg-slate-200` → `--u-divide`; kartu unread/read → **class token `.u-notif-unread` / `.u-notif-read`** (definisikan di CSS: unread bg `--u-surface-2` + border-l accent, read bg `--u-surface`); pill → tinted `dark:`; icon chip → `u-chip`; **JS `markAllRead` ganti literal `bg-slate-50`/`bg-white`/`text-slate-900`/`text-slate-600` → `u-notif-read`/token classes** |
| `help/index.php` | Header → token; contact card `bg-white` → `.u-card`; FAQ items → `.u-card`/token; button WA/email tetap (emerald/blue solid invariant) |
| `auth/login.php`, `auth/register.php`, `auth/change_password.php` | Infra standalone §2.7; body top `bg-slate-900` tetap; form card `bg-white` → `.u-card`; input → `.u-input`; label → `.u-label`; submit `bg-slate-900 hover:bg-blue-600` → `.u-btn-dark`/`.u-btn-cyber`; flash → `.u-flash-*`; reCAPTCHA wrapper `rounded-xl overflow-hidden`; link akun → token |

---

## 4. Manifest Perubahan per File

| File | Aksi |
|------|------|
| `application/views/templates/header.php` | edit — infra tema + toggle + tokenize shell/notif |
| `application/views/templates/bottom_nav.php` | edit — glass nav + glow active |
| `application/views/home/index.php` | edit |
| `application/views/marketplace/index.php` | edit |
| `application/views/rentals/index.php` | edit |
| `application/views/team/index.php` | edit |
| `application/views/wallet/index.php` | edit |
| `application/views/wallet/withdraw.php` | edit |
| `application/views/wallet/bank_bind.php` | edit |
| `application/views/profile/index.php` | edit — + item tema |
| `application/views/profile/change_password.php` | edit |
| `application/views/notification/index.php` | edit |
| `application/views/help/index.php` | edit |
| `application/views/auth/login.php` | edit — infra standalone |
| `application/views/auth/register.php` | edit — infra standalone |
| `application/views/auth/change_password.php` | edit — infra standalone |
| `plan/32_USER_THEME_MANAGER_PLAN.md` | **new** — blueprint ini |

**Tidak disentuh:** controller, model, routes, config, CSS eksternal, `templates/csrf_meta.php` (netral). Tidak ada endpoint/route baru (toggle murni client-side). **Admin app (Phase 30) tidak terpengaruh** — prefix `u-` vs `t-`, key `user_theme` vs `admin_theme`.

---

## 5. Protokol Verifikasi & Testing

1. **Lint:** `php -l` untuk **setiap** file PHP yang diubah (16 file).
2. **Boot lokal:** `CI_ENV=development php -S localhost:8080` (atau vhost `synapse.test`); login sebagai user biasa.
3. **Matriks visual — 2 mode, semua halaman user:**
   - **Dark (default):** `/home`, `/marketplace`, `/rentals`, `/team`, `/wallet`, `/wallet/withdraw`, `/wallet/bind_bank`, `/profile`, `/profile/change-password`, `/notification`, `/help` → HTTP 200; shell obsidian, card Neural Surface, border glow terbaca; **tidak ada blok putih menyilaukan**; kontras `u-text-2`/`u-muted` di surface gelap memadai.
   - **Light:** klik toggle → ulangi semua halaman; **rentals blade harus flip elegan** (header judul terbaca, data-cell/netral), tidak ada teks gelap di surface gelap atau teks pudar; QR tetap ter-scan.
4. **Anti-FOUC:** reload/hard-refresh (Ctrl+F5) → tidak ada flash terang→gelap; `localStorage.user_theme` persist; ikon toggle sinkron di header & profile hub; halaman auth (login/register) mengikuti preferensi tersimpan.
5. **Interaksi:** toggle dari header (semua halaman) & dari hub profile; dropdown notifikasi terbuka **di atas bottom nav** (fix `z-[60]`); modal marketplace/rentals/team/profile (buka/tutup, backdrop, scroll lock); klaim ROI/level1 (button gradient); topup amt-btn (inactive/active di dua tema); mark-all-read (styling unread→read di dua tema); copy invite/referral (toast di dua tema); reCAPTCHA tampil benar di auth dark.
6. **Audit kebersihan tema:** `grep -rn "bg-white\|bg-slate-\(50\|100\|200\|300\|400\|500\|600\|700\|800\|900\|950\)\|text-slate-\|border-slate-" application/views` (user, non-admin) → hanya tersisa aksen solid invariant, `u-card-fin` (keputusan §2.8), QR container (keputusan), dan hero images (konten) — cek manual tiap match.
7. **Regresi non-UI:** pastikan tidak ada perubahan di controller/model/routes; AJAX notifikasi/klaim tetap 200.

---

## 6. Risiko & Catatan

- **`rentals/index.php` adalah halaman paling kompleks** — blade aesthetic saat ini dark-hardcoded (gradient, data-cell, progress). Tokenisasi harus hati-hati agar di light mode tetap terlihat "premium", bukan abu-abu datar; proporsal: surface-2 untuk data-cell + glow border mempertahankan identitas.
- **JS-set classes** (wallet INACTIVE/ACTIVE, notification markAllRead, home copy swap, marketplace balance color) — semua harus diarahkan ke class token/CSS var; class string di JS yang memakai literal slate akan "lolos" dari grep audit, jadi verifikasi §5-6 harus mencakup file JS inline di view.
- **Tailwind Play CDN + `dark:` di JS string:** aman (pola Phase 30 sudah terbukti — CDN meng-compile class baru via MutationObserver); tetap pastikan string `dark:`-nya juga muncul statis di file bila perlu.
- **`user_theme` vs `admin_theme`:** key terpisah — user yang memilih dark di app mobile tidak mengubah tema admin, dan sebaliknya (dual-auth hard separation, konsisten AGENTS.md).
- **Auth pages tanpa toggle** — keputusan §2.7; bila user meminta, bisa ditambah toggle kecil di footer auth (di luar scope sekarang).
- **reCAPTCHA** selalu putih — di dark tetap terbaca; wrapper rounded. Tidak ada cara theme-ize widget pihak ketiga tanpa risiko, jadi dibiarkan.
- **Font** Inter + JetBrains Mono ditambahkan (perluasan guideline §4: angka finansial `font-mono`); CDN Google Fonts — bila offline, fallback system-ui/monospace aman.
- **Bukan scope:** logic bisnis, data, controller, halaman admin (Phase 30), `docs/` (tidak ada perubahan selain yang sudah ada — guideline §3-6 sudah selaras dengan arah desain baru; bila perlu, update guideline di fase terpisah).
- **Prasyarat branch:** pastikan pekerjaan Phase 30/10D sudah di-commit sebelum branching `fase-32-user-theme-manager` (working tree bersih) agar diff bersih dan revert-able.
