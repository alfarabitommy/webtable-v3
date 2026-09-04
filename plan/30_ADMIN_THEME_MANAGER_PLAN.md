# Phase 30 — Admin Theme Manager (Dark Default) & Spacing Refactor

**Project:** Synapse (webtable) · **Baseline:** `main` (HEAD saat audit, berisi pekerjaan Phase 10D yang **belum di-commit**) · **Branch kerja:** `fase-30-admin-theme-manager` (proposed)
**Mode:** PLANNING — blueprint menunggu persetujuan user. **Belum ada kode yang diubah.**
**Referensi:** `docs/4_UI_UX_GUIDELINES.md` (palet dark/light, z-index, density), AGENTS.md (SQL hanya di model, `php -l` tiap file, branch per fase, commit bahasa Indonesia), `plan/28_PHASE_10D_PLAN.md` (format & gaya).

> **Prasyarat (penting):** working tree saat ini kotor — pekerjaan Phase 10D belum di-commit di `main` (`git status` menunjukkan 10+ file modified, `Test_core.php` deleted). Sebelum branching `fase-30`, pekerjaan 10D harus di-commit/di-landing terlebih dahulu agar diff Phase 30 bersih dan revert-able. Tidak ada kode Phase 30 yang menimpa perubahan 10D (tidak menyentuh controller/config).

---

## Ringkasan Perubahan

| # | Perubahan | File |
|---|-----------|------|
| 1 | **Infrastruktur tema** — anti-FOUC inline script di `<head>` (apply `dark` class sebelum render), `tailwind.config` `darkMode: 'class'`, CSS variable tokens (light `:root` / dark `html.dark`), semantic component classes, `color-scheme` | `application/views/admin/templates/header.php` (edit) |
| 2 | **Toggle Sun/Moon** di top navbar, di samping badge admin | `application/views/admin/templates/topbar.php` (edit) |
| 3 | **Shell dark-aware** — sidebar, logo, nav active/hover, overlay, logout | `application/views/admin/templates/sidebar.php` (edit) |
| 4 | **Chart.js theme-aware** — baca CSS variables saat init + redraw saat toggle | `application/views/admin/templates/footer.php` (edit) |
| 5 | **Dashboard** — dark stat/treasury/chart cards → semantic, pending queues light → semantic, hapus h1 "Dashboard" duplikat, rapikan urutan & ritme | `application/views/admin/dashboard.php` (edit) |
| 6 | **Users** — tabel/input/modal → semantic, modal `z-50` → `z-[60]`, hapus judul duplikat | `application/views/admin/users.php` (edit) |
| 7 | **User Detail** — header card + 6 section card → semantic, input/table/badge → semantic | `application/views/admin/user_detail.php` (edit) |
| 8 | **Analytics** — `bg-slate-950` → semantic (agar light mode tetap terbaca), modal X-Ray → `t-modal` (tetap `z-[60]`) | `application/views/admin/analytics.php` (edit) |
| 9 | **Audit** — filter bar + tabel → semantic, badge tetap tinted | `application/views/admin/audit.php` (edit) |
| 10 | **History** — tabel/card → semantic, hapus h1 duplikat, tabs border → token | `application/views/admin/history.php` (edit) |
| 11 | **Settings** — form/card/input → semantic | `application/views/admin/settings.php` (edit) |
| 12 | **Login admin** — infra tema standalone (anti-FOUC + tokens), dark default | `application/views/admin/login.php` (edit) |
| 13 | Blueprint ini | `plan/30_ADMIN_THEME_MANAGER_PLAN.md` (**new**) |

**Tanpa perubahan:** controller (`Admin.php`), routes, model — toggle murni client-side (localStorage + class), tidak ada endpoint baru.

---

## 1. Hasil Audit (fakta tervalidasi dari source)

### 1.1 Keadaan tema saat ini — tidak konsisten, 3 gaya bercampur

| File | Keadaan | Bukti |
|------|---------|-------|
| `templates/header.php` | Shell **light** (`bg-slate-50 text-slate-800`), tanpa config dark, tanpa anti-FOUC, tanpa `color-scheme` | baris 29 |
| `templates/sidebar.php` | **Light** — `bg-white border-slate-200`, active `bg-indigo-50 text-indigo-700` | baris 3, 20 |
| `templates/topbar.php` | **Light** — `h-16 bg-white border-b border-slate-200`, badge admin `bg-slate-100` | baris 5, 15 |
| `templates/footer.php` | Netral; **Chart.js tooltip/grid dikunci warna dark** (`#1e293b`, `#94a3b8`) | baris 39-70 |
| `dashboard.php` | **CAMPURAN** — treasury + stat cards + chart `bg-slate-900` (dark), queue deposit/WD `bg-white` (light); h1 "Dashboard" muncul **setelah** chart (baris 117) | baris 17-114, 126, 168 |
| `users.php` | **Light total** — card `bg-white`, modal `z-50`, input `border-slate-300`; h3 "User Management" duplikat topbar | baris 19, 46, 123 |
| `user_detail.php` | **CAMPURAN** — header user `bg-slate-800` (dark), 6 section `bg-white` (light) | baris 22, 56, 129, 201, 314, 352, 391 |
| `analytics.php` | **Dark total** (Bloomberg) — `bg-slate-950` cards, `text-white`; modal X-Ray `z-[60]`; `LAST SYNC text-slate-600` | baris 20, 35, 59, 153, 193 |
| `audit.php` | **Dark total** — filter bar + tabel `bg-slate-950` | baris 34, 72 |
| `history.php` | **Light total** — card `bg-white`, badge `bg-green-100`; h1 "Riwayat Transaksi" duplikat topbar | baris 17, 43, 86 |
| `settings.php` | **Light total** — cardless form, input `border-slate-300` | baris 21, 42 |
| `login.php` | **Standalone light** — tidak pakai admin templates, `bg-slate-100` | baris 12, 25 |

**Dampak:** dengan dark-as-default, halaman light (users/history/settings) dan bagian light di halaman campuran akan menyilaukan; halaman dark (analytics/audit) tidak bisa dialihkan ke light. Toggle tanpa refactor per-halaman tidak akan berfungsi — **seluruh literal warna harus di-token-kan**.

### 1.2 Ukuran refactor (grep literal warna sensitif tema)

```
analytics.php: 45    audit.php: 27    dashboard.php: 37    history.php: 14
login.php: 9         settings.php: 9  user_detail.php: 61  users.php: 30
sidebar.php: 12      topbar.php: 5    header.php: 1
```
≈ **250 titik** `bg-white / bg-slate-* / text-slate-* / border-slate-*` yang harus menjadi theme-aware. Yang **tetap boleh mentah**: aksen brand/aksi (indigo-600/emerald-600/rose-600/amber-600 solid) karena identik di kedua tema.

### 1.3 Duplikasi judul (topbar vs halaman) → gap "breadcrumb–header" janggal

- Topbar selalu render `page_title` (`h2`). Halaman **dashboard** (`h1 "Dashboard"`), **users** (`h3 "User Management"`), **history** (`h1 "Riwayat Transaksi"`), **settings** (`h3 "Pengaturan Umum"`) mengulang judul yang sama di bawahnya → dua baris judul bertumpuk + gap tidak konsisten (`mb-4`/`mb-6` campur).
- **Dashboard** lebih parah: header halaman terletak di baris 117 — **setelah** kartu treasury, stat, dan chart (di bawah fold).

### 1.4 Inkonsistensi spacing & grid

- Padding container utama: `p-4 lg:p-6` (topbar.php baris 26) — tidak seragam di semua view.
- Padding card: `p-4` (users search), `p-5` (dashboard chart), `p-6` (user_detail sections) — 3 nilai berbeda.
- Grid metric card: `gap-3` (dashboard treasury), `gap-4` (dashboard stat, analytics) — tidak seragam.
- Modal: `z-50` (users create) vs `z-[60]` (analytics X-Ray) — melanggar `docs/4_UI_UX_GUIDELINES.md` §2 (modal wajib `z-[60]`).

---

## 2. Desain Theme Manager

### 2.1 Model tema — Tailwind `darkMode: 'class'` + CSS variable tokens + semantic classes

Strategi: **satu sumber kebenaran** = CSS variable tokens yang flip di `html.dark`. Halaman memakai **semantic component classes** (bukan `dark:` variant per elemen) agar refactor 250 titik jadi ~12 penggantian kelas reusable, konsisten, dan mudah diaudit. `darkMode: 'class'` tetap diaktifkan untuk one-off kecil.

### 2.2 `header.php` — urutan script & anti-FOUC

Urutan di `<head>` (kritis):
1. **Anti-FOUC inline script (script pertama, sebelum Tailwind & CSS apa pun):**
   ```html
   <script>
       // Admin Theme Manager — anti-FOUC: apply dark class before first paint
       (function () {
           try {
               if (localStorage.getItem('admin_theme') !== 'light') {
                   document.documentElement.classList.add('dark');
               }
           } catch (e) {
               document.documentElement.classList.add('dark'); // storage blocked → default dark
           }
       })();
   </script>
   ```
   → Default **dark**; hanya user yang menyimpan `'light'` yang tidak mendapat class. Persistensi key: **`admin_theme`** (`'dark' | 'light'`).
2. `tailwind.config` → tambah `darkMode: 'class'` (baris 11-21 yang ada).
3. Blok `<style>` yang ada → tambah **token & semantic classes** (detail §2.4).
4. `<body>`: `bg-slate-50 text-slate-800` → `bg-[var(--t-bg)] text-[var(--t-text)]` (shell theme-aware).

### 2.3 `topbar.php` — toggle Sun/Moon

> **Catatan deviasi file:** requirement menyebut "toggle di top navbar … di `header.php`", tetapi di codebase ini top navbar (dengan badge admin) berada di **`admin/templates/topbar.php`** — `header.php` adalah `<head>` HTML. Toggle dipasang di **`topbar.php`**, di samping badge admin (mengikuti deskripsi fungsional, bukan nama file).

```html
<button id="admin-theme-toggle" type="button" aria-label="Ganti tema"
        class="w-8 h-8 rounded-lg t-btn-ghost flex items-center justify-center transition-colors"
        onclick="toggleAdminTheme()">
    <i id="theme-toggle-icon" class="fas fa-moon text-sm"></i>
</button>
```
Script toggle (satu fungsi global, diletakkan di `topbar.php` atau `footer.php`):
```js
function toggleAdminTheme() {
    var html = document.documentElement;
    var dark = html.classList.toggle('dark');
    try { localStorage.setItem('admin_theme', dark ? 'dark' : 'light'); } catch (e) {}
    var icon = document.getElementById('theme-toggle-icon');
    if (icon) { icon.className = 'fas ' + (dark ? 'fa-moon' : 'fa-sun') + ' text-sm'; }
    window.dispatchEvent(new CustomEvent('admin-theme-change', { detail: { dark: dark } }));
}
```
Ikon inisial disinkronkan dengan state saat ini (baca `localStorage`/class di `footer.php` saat DOM ready). Dark → ikon `fa-sun` (artinya "klik untuk light"… sebaliknya: menampilkan sun berarti arah ke light; diputuskan saat implementasi agar intuitif — rekomendasi: **tampilkan `fa-sun` di dark mode**).

### 2.4 Token palet & semantic classes (`<style>` di `header.php`)

Palet (mengikuti `docs/4_UI_UX_GUIDELINES.md` §3 — slate scale + Bloomberg dark):

| Token | Light (`:root`) | Dark (`html.dark`) | Dipakai untuk |
|-------|-----------------|--------------------|---------------|
| `--t-bg` | `#f1f5f9` (slate-100) | `#020617` (slate-950) | Shell/body |
| `--t-surface` | `#ffffff` | `#0f172a` (slate-900) | Card, sidebar, topbar |
| `--t-surface-2` | `#f8fafc` (slate-50) | `#1e293b` (slate-800) | Nested: thead, hover, inset |
| `--t-surface-3` | `#f1f5f9` (slate-100) | `#334155` (slate-700) | Badge netral, avatar |
| `--t-border` | `#e2e8f0` (slate-200) | `#1e293b` (slate-800) | Border card/shell |
| `--t-border-strong` | `#cbd5e1` (slate-300) | `#334155` (slate-700) | Input border, tab |
| `--t-text` | `#0f172a` (slate-900) | `#f1f5f9` (slate-100) | Judul, teks utama |
| `--t-text-2` | `#475569` (slate-600) | `#94a3b8` (slate-400) | Body sekunder |
| `--t-muted` | `#94a3b8` (slate-400) | `#64748b` (slate-500) | Label micro, placeholder |
| `--t-input-bg` | `#ffffff` | `#1e293b` (slate-800) | Input/select |
| `--t-hover` | `#f1f5f9` (slate-100) | `#1e293b` (slate-800) | Row hover, nav hover |
| `--t-active` | `#eef2ff` (indigo-50) | `#312e81/30` (indigo-900/30) | Nav active bg |
| `--t-chart-grid` | `rgba(100,116,139,.12)` | `rgba(148,163,184,.08)` | Chart.js grid |
| `--t-chart-tick` | `#64748b` | `#94a3b8` | Chart.js ticks |
| `--t-tooltip-bg` | `#ffffff` | `#1e293b` | Chart.js tooltip |

`html.dark { color-scheme: dark; }` → date input, scrollbar, select native mengikuti tema.

**Semantic classes** (semua berbasis token di atas):
- **Shell:** `.t-shell` (bg + text + font) — atau utility langsung `bg-[var(--t-bg)] text-[var(--t-text)]`.
- **Card:** `.t-card` (bg surface + border + rounded-xl), `.t-card-hd` (border-b bawah, px-5 py-4), `.t-card-bd` (p-5).
- **Table:** `.t-th` (uppercase tracking-wider + warna muted, ukuran 10-11px), `.t-td`, `.t-row-hover`, `.t-divide` (divide-y token border).
- **Input/Form:** `.t-input` (bg+border+text+fokus ring indigo), `.t-select`, `.t-label`, `.t-checkbox`.
- **Badge:** `.t-badge` (netral), `.t-badge-success` (emerald tint: `bg-emerald-500/10 text-emerald-600 dark:text-emerald-400`), `.t-badge-danger`, `.t-badge-warning`, `.t-badge-info`, `.t-badge-indigo`.
- **Button:** `.t-btn-ghost` (netral — pengganti `bg-slate-100 text-slate-700` / `bg-slate-800 text-slate-300`), `.t-btn-primary` tetap `bg-indigo-600` (identik dua tema).
- **Modal:** `.t-modal` (bg surface + rounded + border), `.t-modal-backdrop` (`bg-black/60`), wajib `z-[60]`.
- **Flash:** `.t-flash-success` (`bg-emerald-50 border-emerald-200 text-emerald-700` ↔ `bg-emerald-500/10 border-emerald-500/20 text-emerald-400`), `.t-flash-error` serupa.
- **Nav:** `.t-nav-link` (inactive), `.t-nav-active` (`bg-indigo-50 text-indigo-700` ↔ indigo tint dark), `.t-sidebar`, `.t-topbar`, `.t-hero` (header user dark card di user_detail → jadi accent card yang flip).

**Aturan refactor:** aksen solid (indigo-600/emerald-600/rose-600/amber-600/orange-600 + hover-nya) **tidak diubah**. Semua `bg-white/bg-slate-*/text-slate-*/border-slate-*` netral **wajib** diganti semantic class. Sisa literal warna di akhir refactor dicek via grep (§6).

### 2.5 Chart.js theme-aware (`footer.php`)

- Saat init: baca `getComputedStyle(document.documentElement).getPropertyValue('--t-chart-grid')` dst untuk grid/ticks/tooltip — bukan hex hardcoded.
- Warna emerald (border/gradient/point) tetap — terlihat baik di kedua tema.
- Listener: `window.addEventListener('admin-theme-change', function(){ /* update chart.options.scales/plugins + chart.update() */ })`.
- Chart instance disimpan global (`window.revenueChart`) agar footer/analytics bisa redraw.

### 2.6 `login.php` — infra standalone

`login.php` tidak memakai admin templates → duplikasi minimal infra di `<head>`-nya sendiri: anti-FOUC script + token/semantic classes (subset: `.t-card`, `.t-input`, `.t-label`, `.t-btn-primary`) + `darkMode` config. Konsisten dark-default di halaman masuk.

---

## 3. Spacing & Layout Rhythm

1. **Container utama:** `topbar.php` baris 26 → `p-4 lg:p-6` diubah jadi **`p-6` seragam** (keputusan: p-6, sesuai requirement "p-6 or p-8"; p-8 terlalu boros untuk admin desktop-first dengan sidebar).
2. **Judul duplikat dihapus** (sumber judul = topbar):
   - `dashboard.php` — hapus blok "Page Header" (baris 116-120); urutan konten jadi: flash → header halaman (bukan judul, melainkan meta/aksi jika ada) → treasury → stat → chart → queues.
   - `users.php` — hapus `h3 "User Management"`; pertahankan baris toolbar (count + search + tombol) sebagai header halaman.
   - `history.php` — hapus blok h1; tab + summary badge jadi elemen pertama.
   - `settings.php` — pertahankan header (berisi deskripsi), standarkan `mb-6`.
   - `analytics.php` / `audit.php` — pertahankan hero "COMMAND CENTER" (estetika), standarkan `mb-6`.
3. **Ritme vertikal:** blok pertama tiap halaman `mb-6`; antar-section `mb-6`; `main` memberi padding luar `p-6` sehingga tidak ada margin-top ganda.
4. **Grid metric card:** seragam `gap-4`, card `p-5 rounded-xl` (treasury dashboard `gap-3` → `gap-4`; grid 2-kolom section tetap `gap-6`).
5. **Table cell padding:** seragam `px-5 py-3` (thead) / `px-5 py-3.5` (tbody) untuk tabel data (users/history/audit/analytics), `px-3 py-2`/`px-3 py-3` untuk tabel padat (user_detail rentals).
6. **Tabs (history):** `border-b border-slate-200` → token `var(--t-border)`; active `border-indigo-600 text-indigo-700` tetap.
7. **Modal:** semua modal → `z-[60]` + `.t-modal` (users create modal diubah dari `z-50`).

---

## 4. Refactor per Halaman (detail matriks)

| File | Konversi utama |
|------|----------------|
| `header.php` | Anti-FOUC, `darkMode:'class'`, token + semantic classes, body → token, `color-scheme` |
| `sidebar.php` | `bg-white` → `t-sidebar`/var; nav link → `.t-nav-link`/`.t-nav-active`; logout hover `hover:bg-red-50` → `t-btn-ghost`-ish; logo text `text-slate-900` → `var(--t-text)` |
| `topbar.php` | `bg-white border-slate-200` → token; toggle Sun/Moon + fungsi `toggleAdminTheme()`; badge avatar `bg-slate-100` → `t-surface-3` |
| `footer.php` | Chart colors → CSS vars; `admin-theme-change` listener; sync ikon toggle saat DOM ready |
| `dashboard.php` | Treasury/stat/chart card `bg-slate-900 border-slate-700` → `.t-card` + aksen teks (emerald/amber/blue -400 → token-aware); queue `bg-white` → `.t-card`; flash → `.t-flash-*`; hapus h1 duplikat; treasury `gap-3`→`gap-4`; select `chartPeriod` → `.t-select`; badge count `bg-slate-100` → `.t-badge` |
| `users.php` | Card tabel → `.t-card`; `bg-slate-50` thead → `.t-th`/`t-surface-2`; row hover → `.t-row-hover`; search input + modal inputs → `.t-input`; status badge → `.t-badge-success`/`.t-badge-danger`; modal → `.t-modal` + `z-[60]`; flash → `.t-flash-*` |
| `user_detail.php` | Header user `bg-slate-800` → `.t-hero` (flip); 6 section `bg-white border-slate-200 p-6` → `.t-card`; inputs/select → `.t-input`/`.t-select`; balance inset `bg-slate-50` → `t-surface-2`; tabel rentals → `.t-th`/`.t-td`; status chip rentals → `.t-badge-*`; tombol icon `bg-red-50`/`bg-amber-50` → `.t-btn-ghost` tinted; downline list → `.t-card`-nested; flash → `.t-flash-*` |
| `analytics.php` | `bg-slate-950 border-slate-800` → `.t-card`; `text-white` judul → `var(--t-text)`; `LAST SYNC text-slate-600` → `var(--t-muted)`; tabel → `.t-th`/`.t-td`/`.t-row-hover`; X-Ray modal → `.t-modal` (tetap `z-[60]`); stat X-Ray `bg-slate-900` → `t-surface-2`; tombol export `bg-slate-900` → `.t-btn-ghost`; badge → `.t-badge-*`; JS-set className di `openXray` (badge emerald/red/amber) → gunakan token-aware class |
| `audit.php` | Filter bar + tabel card → `.t-card`; select/date input → `.t-input`/`.t-select`; thead/tbody → `.t-th`/`.t-td`/`.t-row-hover`; action badge → `.t-badge-*`; tombol Reset `bg-slate-800` → `.t-btn-ghost`; `LAST SYNC` → var |
| `history.php` | Card tabel → `.t-card`; tabs border → var; summary badge → `.t-badge`; status chip → `.t-badge-success`/`.t-badge-danger`/`.t-badge`; flash → `.t-flash-*`; hapus h1 duplikat |
| `settings.php` | Card wrap → `.t-card` (max-w-lg); input → `.t-input`; label → `.t-label`; flash → `.t-flash-*`; header `mb-6` |
| `login.php` | Infra standalone (anti-FOUC + tokens + subset classes); body `bg-slate-100` → var; card `bg-white` → `.t-card`; input → `.t-input`; shadow indigo tetap |

---

## 5. Manifest Perubahan per File

| File | Aksi |
|------|------|
| `application/views/admin/templates/header.php` | edit — infra tema |
| `application/views/admin/templates/topbar.php` | edit — toggle + shell token |
| `application/views/admin/templates/sidebar.php` | edit — shell token |
| `application/views/admin/templates/footer.php` | edit — chart theme-aware + toggle sync |
| `application/views/admin/dashboard.php` | edit |
| `application/views/admin/users.php` | edit |
| `application/views/admin/user_detail.php` | edit |
| `application/views/admin/analytics.php` | edit |
| `application/views/admin/audit.php` | edit |
| `application/views/admin/history.php` | edit |
| `application/views/admin/settings.php` | edit |
| `application/views/admin/login.php` | edit |
| `plan/30_ADMIN_THEME_MANAGER_PLAN.md` | **new** — blueprint ini |

**Tidak disentuh:** controller, model, routes, config, CSS eksternal. Tidak ada endpoint/route baru (toggle murni client-side).

---

## 6. Protokol Verifikasi & Testing

1. **Lint:** `php -l` untuk **setiap** file PHP yang diubah (12 file).
2. **Boot lokal:** `CI_ENV=development php -S localhost:8080` (pretty URL default config mengharapkan `synapse.test`; verifikasi via host mapping atau langsung ke `index.php` path). Login via `/control-panel` dengan kredensial admin lokal.
3. **Regresi visual semua subpage — 2 mode:**
   - Default (dark): `/admin`, `/admin/users`, `/admin/user_detail/{id}`, `/admin/analytics`, `/admin/audit`, `/admin/history/deposit`, `/admin/history/withdrawal`, `/admin/settings`, `/control-panel` → pastikan HTTP 200, shell + card + tabel + input + modal terbaca, tidak ada blok terang menyilaukan di tengah dark.
   - Light: klik toggle → semua halaman di atas terlihat benar; kontras `text-2`/`muted` memadai di surface putih.
4. **Anti-FOUC:** reload halaman → tidak ada flash terang→gelap; `localStorage.admin_theme` ter-set; toggle persist setelah reload; hard-refresh (Ctrl+F5) tetap konsisten.
5. **Chart.js:** dashboard dark & light — grid/ticks/tooltip berubah warna saat toggle, tidak ada error console; `curl` AJAX `admin/chart_data` tetap 200.
6. **Interaksi:** toggle registrasi (circuit breaker), approve/decline, create user modal (buka/tutup, `z-[60]` di atas sidebar `z-50`), X-Ray modal (buka/Escape), pagination, hamburger mobile, dropdown `chartPeriod`.
7. **Audit kebersihan tema:** `grep -rn "bg-white\|bg-slate-\(50\|100\|200\|300\|400\|500\|600\|700\|800\|900\|950\)\|text-slate-\|border-slate-" application/views/admin` → hanya tersisa aksen brand solid + token vars yang disengaja (cek manual tiap match).
8. **Regresi non-admin:** pastikan perubahan hanya menyentuh `views/admin/*` (user-facing app tidak terpengaruh).

---

## 7. Risiko & Catatan

- **Deviasi file toggle:** requirement menyebut `header.php`, implementasi di `topbar.php` (top navbar + badge admin sebenarnya ada di sana; `header.php` = `<head>`). Sudah dikonfirmasi via source — lihat §2.3.
- **Prasyarat commit 10D:** working tree kotor (pekerjaan Phase 10D belum di-commit). Branch `fase-30` harus berangkat dari state yang bersih.
- **CDN Tailwind + `darkMode`:** `tailwind.config` dengan `darkMode:'class'` didukung Play CDN; config harus di-set setelah script Tailwind load (pola existing sudah benar). Token/semantic classes tidak bergantung pada `dark:` variant — aman.
- **JS-set className (X-Ray badge, circuit breaker):** beberapa class di-set via JS (`analytics.php` `openXray`, `dashboard.php` `toggleRegistration`) — perlu disesuaikan agar theme-aware saat set class (badge tinted emerald/red/amber sudah token-friendly karena pakai `/10` opacity yang bekerja di dua tema; verifikasi kontras).
- **`<option>` dark di select:** dengan `color-scheme: dark`, dropdown native ikut gelap; teks option menggunakan warna sistem — aman.
- **Login standalone:** duplikasi kecil infra tema (anti-FOUC + tokens) tidak bisa dihindari tanpa mengubah struktur view; diterima demi konsistensi (12 file menyentuh view admin saja, 0 controller).
- **Bukan scope:** tema user-facing app (mobile) — tetap light sesuai `docs/4_UI_UX_GUIDELINES.md`; tidak ada perubahan `docs/` selain yang sudah ada.
