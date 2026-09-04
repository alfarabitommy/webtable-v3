# Phase 30 — Execution Summary: Admin Theme Manager (Dark Default) & Spacing Refactor

**Project:** Synapse (webtable) · **Fase:** 30 (per blueprint `plan/30_ADMIN_THEME_MANAGER_PLAN.md`, APPROVED)
**Status:** ✅ **SELESAI** — seluruh 12 file dieksekusi, lint bersih (12/12 `php -l`), audit kebersihan tema lulus.
**Catatan lingkungan:** MySQL tidak tersedia di environment eksekusi ini, sehingga verifikasi visual runtime (login + semua subpage di Dark & Light) **belum dapat dijalankan** — lihat §5 untuk runbook manual yang harus dijalankan di mesin dev dengan DB `db_webtable`.

---

## 1. Daftar Perubahan (manifest)

| # | File | Aksi | Isi |
|---|------|------|-----|
| 1 | `application/views/admin/templates/header.php` | edit | **Infrastruktur tema**: anti-FOUC inline script (script pertama di `<head>`, apply `dark` class pada `<html>` kecuali `localStorage.getItem('admin_theme') === 'light'`, fallback dark saat storage diblokir); `tailwind.config` + `darkMode: 'class'`; **design tokens** (`:root` light / `html.dark` dark): `--t-bg`, `--t-surface*`, `--t-border*`, `--t-text*`, `--t-muted`, `--t-input-bg`, `--t-hover`, `--t-active`, `--t-chart-grid/tick`, `--t-tooltip-*`, plus `color-scheme` per tema; **semantic classes**: `.t-shell`, `.t-card`/`.t-card-hd`/`.t-card-bd`, `.t-th`/`.t-td`/`.t-row-hover`/`.t-divide`, `.t-input`/`.t-select`/`.t-label`, `.t-badge*` (muted/success/danger/warning/info/indigo), `.t-btn-ghost`, `.t-modal`/`.t-modal-backdrop`, `.t-flash-*`, `.t-nav-link`/`.t-nav-active`, `.t-hero`, `.t-sidebar`, `.t-topbar`, `.t-pagination` (override CSS untuk link pagination server-rendered — tanpa sentuh controller); `<body>` → `t-shell` (token). |
| 2 | `application/views/admin/templates/topbar.php` | edit | **Toggle Sun/Moon** (`#admin-theme-toggle` + `#theme-toggle-icon`, `onclick="toggleAdminTheme()"`, `aria-label`, ikon `fa-sun`/`fa-moon` di samping badge admin); topbar → `t-topbar` token; badge avatar/username → token; **container utama `p-4 lg:p-6` → `p-6`** (normalisasi padding). |
| 3 | `application/views/admin/templates/sidebar.php` | edit | `bg-white border-slate-200` → `t-sidebar` + `border-r`; logo text → token; nav links → `.t-nav-link` + kondisional `.t-nav-active` (semua 6 link); logout → token + `hover:bg-red-500/10 hover:text-red-600 dark:hover:text-red-400`; border-b/border-t → `var(--t-border)`. |
| 4 | `application/views/admin/templates/footer.php` | edit | **Chart.js theme-aware**: fungsi `themeColors()` membaca CSS vars (`--t-chart-grid`, `--t-chart-tick`, `--t-tooltip-bg`, `--t-tooltip-title`) via `getComputedStyle`; chart diekspos ke `window.revenueChart`; listener `admin-theme-change` → update grid/ticks/tooltip + `chart.update()`; **`toggleAdminTheme()`** (toggle `dark` class, persist `localStorage 'admin_theme'`, swap ikon, dispatch `CustomEvent('admin-theme-change')`); sinkronisasi ikon toggle saat load. |
| 5 | `application/views/admin/dashboard.php` | edit | Flash → `.t-flash-*`; **treasury card flip** (`bg-white dark:bg-slate-900` + critical `bg-red-50 dark:bg-red-950/40`, aksen teks `dark:` variants; inner stat cells `bg-slate-800/80` sengaja tetap dark-accent di dua tema); stat cards → `.t-card` + nilai `text-emerald/amber/indigo-600 dark:-400`; chart card → `.t-card`; `select#chartPeriod` → `.t-select`; **h1 "Dashboard" duplikat dihapus** (baris 116-120); pending deposits/withdrawals → `.t-card`, badge count → `.t-badge-muted`, row → `.t-row-hover`, divide/border → token, tombol Decline → `.t-btn-ghost`; treasury grid `gap-3` → **`gap-4`**. |
| 6 | `application/views/admin/users.php` | edit | Flash → `.t-flash-*`; **h3 "User Management" duplikat dihapus** (header toolbar: count + search + button); search input → `.t-input`; tabel → `.t-card` + `.t-th` + `.t-row-hover` + divide token; status badge → `.t-badge-success`/`.t-badge-danger`; tombol Detail → indigo tint `dark:`; **modal → `.t-modal` + `z-50` → `z-[60]`** + backdrop `.t-modal-backdrop`; input modal → `.t-input`; label → `.t-label`; tombol Batal → `.t-btn-ghost`; pagination dibungkus `.t-pagination`. |
| 7 | `application/views/admin/user_detail.php` | edit | Flash → `.t-flash-*`; back link → token; **header user `bg-slate-800` → `.t-hero`** (avatar `t-surface-3`, teks token, chip status tinted `dark:`); **6 section card `bg-white` → `.t-card`**; semua input/select → `.t-input`/`.t-select`; label → `.t-label`; balance inset → `t-surface-2` + token border; recent transactions + downline list → token divide/border + `.t-row-hover`; tabel rentals → `.t-th` + token; status chip rental → tinted `dark:`; progress track → `t-surface-3`; tombol icon Cancel/Time Travel → tinted `dark:`; warning box → `bg-amber-500/10 border-amber-500/25` + `text-amber-700 dark:text-amber-400`. |
| 8 | `application/views/admin/analytics.php` | edit | Flash → `.t-flash-*`; header + LAST SYNC → token; **4 metric card `bg-slate-950` → `.t-card`**; leaderboard card + export card → `.t-card`; thead → `bg-[var(--t-surface-2)]` + `.t-th`; row → `.t-row-hover` + `even:` token; rank chip → tinted `dark:`; invite/X-Ray button → indigo tint `dark:`; export button → `.t-btn-ghost`; **X-Ray modal → `.t-modal`** (tetap `z-[60]`, header sticky `bg-[var(--t-surface)]`); stat cells → `t-surface-2`; quick action → `.t-btn-ghost`; **JS-set class di `openXray`** (badge profit/loss/break-even, balance) → `text-emerald/red/amber-600 dark:-400` + `text-[var(--t-muted)]`. |
| 9 | `application/views/admin/audit.php` | edit | Flash → `.t-flash-*`; header + LAST SYNC → token; **filter bar `bg-slate-950` → `.t-card`**; select/date input → `.t-select`/`.t-input`; tombol Reset → `.t-btn-ghost`; tabel → `.t-card` + `.t-th` + `.t-row-hover` + `even:` token; **action badge PHP `$badge` → semua `dark:` variants** (slate/emerald/red/amber/violet/sky); link user → `dark:`; pagination dibungkus `.t-pagination`. |
| 10 | `application/views/admin/history.php` | edit | Flash → `.t-flash-*`; **h1 "Riwayat Transaksi" duplikat dihapus** (tab + summary badge jadi elemen pertama); tabs border → token + active `dark:`; summary badge → `.t-badge-muted`; tabel → `.t-card` + `.t-th` + `.t-row-hover`; status chip → tinted `dark:` (success/failed/netral); pagination dibungkus `.t-pagination`. |
| 11 | `application/views/admin/settings.php` | edit | Flash → `.t-flash-*`; **form dibungkus `.t-card p-6`**; input → `.t-input`; label → `.t-label`; helper text → token; header token; tombol simpan solid indigo tetap. |
| 12 | `application/views/admin/login.php` | edit | **Infra standalone**: anti-FOUC dark-default + `tailwind.config { darkMode: 'class' }` + token subset (`--t-bg/surface/border*/text*/muted/input-bg` + `color-scheme`) + `.t-shell`/`.t-modal`/`.t-label`/`.t-input`/`.t-flash-*`; body → `t-shell`; card → `.t-modal`; input → `.t-input`; logo shadow → `shadow-indigo-500/20` (aman dua tema); footer → token. |
| 13 | `plan/30_ADMIN_THEME_MANAGER_PLAN.md` | new | Blueprint (fase sebelumnya). |
| 14 | `plan/31_ADMIN_THEME_MANAGER_SUMMARY.md` | new | File ini. |

**Tanpa perubahan:** controller (`Admin.php`), routes, model, config — toggle murni client-side. Pagination server-rendered ditangani via override CSS `.t-pagination` (tidak mengubah kelas di controller).

---

## 2. Hasil Lint (`php -l`)

Semua 12 file PHP yang dimodifikasi **lulus** (12/12, 0 error):

```
No syntax errors detected in application/views/admin/templates/header.php
No syntax errors detected in application/views/admin/templates/topbar.php
No syntax errors detected in application/views/admin/templates/sidebar.php
No syntax errors detected in application/views/admin/templates/footer.php
No syntax errors detected in application/views/admin/dashboard.php
No syntax errors detected in application/views/admin/users.php
No syntax errors detected in application/views/admin/user_detail.php
No syntax errors detected in application/views/admin/analytics.php
No syntax errors detected in application/views/admin/audit.php
No syntax errors detected in application/views/admin/history.php
No syntax errors detected in application/views/admin/settings.php
No syntax errors detected in application/views/admin/login.php
```

---

## 3. Audit Kebersihan Tema (grep literal warna)

`grep -rn "bg-white|bg-slate-|text-slate-|border-slate-"` di `application/views/admin/` → hanya tersisa **literal yang disengaja**:

- **Treasury card dashboard**: `bg-slate-800/80` + `text-slate-400` pada 4 inner stat cell — keputusan desain: sel angka finansial tetap dark-accent di **dua** tema (outer card flip via `bg-white dark:bg-slate-900`).
- **Aksen solid** (identik dua tema): `bg-amber-500 text-white` (badge role Admin), dot status `bg-emerald-500`/`bg-red-500`/`bg-green-500`/`bg-amber-500`, progress bar `bg-indigo-500`/`bg-slate-400`/`bg-red-400`.
- **Tinted badges** — semua memiliki pasangan `dark:text-*` (contoh: `bg-emerald-500/10 text-emerald-600 dark:text-emerald-400`), termasuk yang di-set via JS (`openXray`) dan PHP (`$badge` di audit).
- Logout `hover:bg-red-500/10 hover:text-red-600 dark:hover:text-red-400`.

Tidak ada literal tema netral (white/slate) yang lolos tanpa mekanisme flip.

---

## 4. Fitur yang Dieksekusi (checklist blueprint)

- ✅ **Default Dark**: `dark` class di `<html>` via anti-FOUC script (header.php + login.php).
- ✅ **Persistence**: `localStorage.getItem('admin_theme')` / `setItem('admin_theme', 'dark'|'light')` — key sesuai spesifikasi.
- ✅ **Anti-FOUC**: script inline pertama di `<head>`, sebelum Tailwind/CDN CSS.
- ✅ **Toggle UI**: tombol Sun/Moon di top navbar samping badge admin (`topbar.php` — deviasi nama file yang sudah dicatat di blueprint §2.3).
- ✅ **Palet terharmonisasi**: token + semantic classes untuk Shell, Cards, Tables, Badges, Inputs, Modals, Flash, Nav.
- ✅ **Spacing**: container `p-6` seragam; judul duplikat dihapus (dashboard/users/history); ritme `mb-6`; grid metric `gap-4`; modal seragam `z-[60]`.
- ✅ **Chart.js**: baca CSS vars + listener `admin-theme-change` → re-render dinamis.
- ✅ **Login standalone**: infra tema sendiri, dark default.

---

## 5. Verifikasi Runtime — RUNBOOK (perlu DB `db_webtable` + admin ter-seed)

Environment eksekusi ini tidak memiliki MySQL, sehingga langkah berikut **belum dijalankan** dan wajib diverifikasi di mesin dev:

1. `CI_ENV=development php -S localhost:8080` dari project root (atau vhost `synapse.test`).
2. Login `/control-panel` dengan kredensial admin lokal.
3. **Mode Dark (default)** — tiap halaman HTTP 200 + kontras OK:
   `/admin`, `/admin/users`, `/admin/user_detail/{id}`, `/admin/analytics`, `/admin/audit`, `/admin/history/deposit`, `/admin/history/withdrawal`, `/admin/settings`.
4. **Mode Light** — klik toggle; ulangi langkah 3; pastikan tidak ada blok gelap menyilaukan / teks pudar.
5. **Anti-FOUC**: reload/hard-refresh — tidak ada flash terang→gelap; `admin_theme` persist; ikon toggle sinkron.
6. **Chart.js**: dashboard di kedua tema — grid/tick/tooltip berubah saat toggle, tanpa error console; `admin/chart_data` tetap 200.
7. **Interaksi**: circuit breaker, approve/decline, create-user modal (`z-[60]` di atas sidebar `z-50`), X-Ray modal (buka/Escape), pagination (tampilan tema di 3 halaman), hamburger mobile, `chartPeriod`.

---

## 6. Catatan & Risiko

- **Deviasi file toggle** (sudah disetujui di blueprint): toggle di `topbar.php` (top navbar + badge admin sebenarnya), bukan `header.php` (`<head>` HTML).
- **Pagination**: link server-rendered di `Admin.php` tetap light/dark-hardcoded; ditangani override CSS `.t-pagination` (specificity lebih tinggi). Opsi lanjutan bila ingin bersih total: pindahkan kelas pagination ke token di controller (di luar scope Phase 30).
- **Treasury inner cells** sengaja dark di dua tema (estetika Bloomberg untuk angka finansial); bukan bug.
- **`dark:` variants di JS/PHP string**: class seperti `dark:text-emerald-400` yang di-set runtime aman — Tailwind Play CDN meng-compile class saat muncul di DOM (MutationObserver), dan semua string tersebut juga muncul statis di file.
- **Prasyarat branch**: blueprint menyarankan commit pekerjaan Phase 10D (working tree kotor) sebelum branching `fase-30-admin-theme-manager` — tetap berlaku.
- **Belum diverifikasi runtime** (keterbatasan environment): lihat runbook §5.
