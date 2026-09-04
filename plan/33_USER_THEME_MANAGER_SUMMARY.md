# Phase 32 — Execution Summary: User Theme Manager (Dark Default) & Futuristic AI GPU UI Refactor

**Project:** Synapse (webtable) · **Fase:** 32 (per blueprint `plan/32_USER_THEME_MANAGER_PLAN.md`, APPROVED)
**Status:** ✅ **SELESAI** — seluruh 16 file target dieksekusi, lint bersih (16/16 `php -l`), audit kebersihan tema lulus (semua literal yang tersisa = keputusan desain yang disengaja).
**Catatan lingkungan:** MySQL tidak tersedia di environment eksekusi ini, sehingga verifikasi visual runtime (login + semua halaman di Dark & Light) **belum dapat dijalankan** — lihat §5 untuk runbook manual di mesin dev dengan DB `db_webtable`.

---

## 1. Daftar Perubahan (manifest)

| # | File | Aksi | Isi |
|---|------|------|-----|
| 1 | `application/views/templates/header.php` | rewrite | **Infrastruktur tema user**: anti-FOUC inline script (script pertama di `<head>`, dark default, key **`user_theme`**, fallback dark saat storage diblokir); `tailwind.config { darkMode:'class' }` + font Inter/JetBrains Mono (Google Fonts); **design tokens `--u-*`** (light `:root` / dark `html.dark`) — Deep Obsidian `#040711`, Neural Surface `#0b1120`, border glow cyan `rgba(56,189,248,.25)` / indigo `rgba(99,102,241,.30)`, glass, divide, glow shadow; `color-scheme` per tema; **semantic classes `.u-*`**: shell/app/text, card/card-gpu/card-fin/card-inset, input/select/label, btn-cyber/btn-dark/btn-ghost, badge-ai + badge tinted (pasangan `dark:`), topbar/capsule, nav-bottom/nav-item/nav-active/nav-dot, modal/modal-backdrop, flash-success/error, divide/row-hover, notif-read/unread, toast, amt-inactive, progress-track; **body → `u-shell` + `font-sans antialiased`**, app container → `u-app`; header → `u-topbar`; capsule wallet → `u-capsule`; **toggle Sun/Moon `#user-theme-toggle` + `toggleUserTheme()`/`syncThemeUI()`** (dispatch `CustomEvent('user-theme-change')`); **notif dropdown `z-50` → `z-[60]`** (perbaikan guideline §2) + tokenize (u-card, u-divide, u-row-hover, icon chip `dark:`). |
| 2 | `application/views/templates/bottom_nav.php` | rewrite | **Glassmorphic bottom nav**: wrapper `bg-white border-t border-slate-200` → `.u-nav-bottom` (glass `--u-glass` + `backdrop-filter: blur(16px)` + border `--u-glass-border`); tiap item → `.u-nav-item` / `.u-nav-active` (cyan glow di dark / indigo di light) + **glow dot indicator** `.u-nav-dot` (absolute, opacity-0 saat non-aktif); micro-label → `u-muted`. |
| 3 | `application/views/home/index.php` | rewrite | Hero tetap (invariant); kartu identitas `bg-white` → `.u-card`; chip kode undangan & avatar → `.u-card-inset`; tombol salin → `.u-btn-ghost`; **JS swap copy `bg-slate-100/hover:bg-slate-200` → `u-btn-ghost`**; stats 2×2 → `.u-card-inset` + icon chip tinted `dark:`; angka → `u-text`/`u-text-2`/`u-muted`; **tombol aksi → `.u-btn-cyber`** (gradient indigo→cyan + glow); **toast copy `z-50` → `z-[60]`** + `.u-toast`. |
| 4 | `application/views/marketplace/index.php` | rewrite | Flash → `.u-flash-*`; **kartu produk → `.u-card-gpu`** (Neural Surface + border glow cyan + `--u-glow` shadow); harga → token; **tombol "Sewa Sekarang" → `.u-btn-cyber`**; modal sheet `bg-white` → `.u-modal` (tetap `z-[60]`); handle/separator/rows → token; **JS `balanceEl` → `text-emerald-600 dark:text-emerald-400` / `text-rose-600 dark:text-rose-400`**. |
| 5 | `application/views/rentals/index.php` | edit (15) | **Tokenisasi estetika server blade**: `.blade-bg` gradient hardcoded → `var(--u-surface)→var(--u-surface-2)→var(--u-bg)` + `color: var(--u-text)` (flip ke light); **fix bug judul** `from-slate-900 to-slate-700` (gelap di atas gelap, tak terbaca) → `from-indigo-600 to-cyan-600 dark:from-cyan-300 dark:to-indigo-300`; flash → `.u-flash-*`; tombol help → `dark:` pasangan; empty state → token; **kartu rig `bg-slate-900 border-slate-700/60` → `.u-card-fin`** (tetap gelap dua tema, interior fixed); **help modal `bg-white` → `.u-modal`** + info boxes tinted `dark:`; `.data-cell`/`.progress-bar` sengaja tetap dark (di dalam kartu fin). |
| 6 | `application/views/team/index.php` | edit (7) | Mission card `bg-slate-900` → `.u-card-fin` (interior tetap, progress track `bg-slate-700` fixed); tombol help → `dark:` pasangan; 3 section `bg-white` → `.u-card`; referral box → `.u-card-inset`; metric cards → `.u-card-inset`/tinted `dark:`; member list row/avatar/badge/status → `dark:` pasangan; **2 modal `bg-white` → `.u-modal`** + info boxes tinted `dark:`; **QR container tetap putih (keputusan §2.8)** — scan reliability; JS copy `bg-indigo-500`↔`bg-emerald-500` (invariant, tanpa perubahan). |
| 7 | `application/views/wallet/index.php` | edit (16) | Balance card `bg-slate-900` → `.u-card-fin` (tetap gelap, badge AKTIF `u-badge-ai`-style); topup form `bg-white` → `.u-card`; **amt-btn: markup + string JS `INACTIVE` → class token `.u-amt-inactive`** (CSS var-based, hover tint cyan), `ACTIVE` tetap solid blue; tombol "Lain" → `bg-transparent` + `dark:` pasangan; input amount → `.u-input`; pending/pending-WD/ledger → `.u-card` + tinted `dark:`; ledger divide `divide-slate-50` → `.u-divide`; row → `.u-row-hover`; icon circle credit/debit → `dark:` pasangan. |
| 8 | `application/views/wallet/withdraw.php` | edit (10) | Back button → `.u-btn-ghost`; flash → `.u-flash-*`; bank card → `.u-card-fin`; balance box → `.u-card-inset`; input → `.u-input`; fee box → `dark:` pasangan; tombol submit orange tetap. |
| 9 | `application/views/wallet/bank_bind.php` | edit (12) | Back button → `.u-btn-ghost`; flash → `.u-flash-*`; bank card (state B) → `.u-card-fin`; warning rose → `dark:` pasangan; form (state A) → `.u-card`; icon chip → `dark:`; label → `u-muted`; **select/input → `.u-select`/`.u-input`**; notice amber → `dark:` pasangan; **submit → `.u-btn-dark`**. |
| 10 | `application/views/profile/index.php` | edit (14) | Identity card → `.u-card`; avatar ring/placeholder → `dark:` pasangan; level badge → `dark:` pasangan; referral card → `.u-card` + box → `.u-card-inset`; **hub menu → `.u-card` + item `.u-row-hover` + divide `dark:border-slate-800` + icon chips `dark:`**; **item baru "Tema Tampilan" (`#btn-theme-hub` → `toggleUserTheme()`, `#theme-hub-icon` + `#theme-mode-label` sinkron via `syncThemeUI`)** di antara Edit Profil & Keamanan; logout → `dark:hover:bg-rose-500/10`; **edit-profile sheet `bg-white` → `.u-modal`**; input → `.u-input`; readonly phone → `dark:` pasangan. |
| 11 | `application/views/profile/change_password.php` | edit (9) | Back → `.u-btn-ghost`; flash + general errors → `.u-flash-*`; form card → `.u-card`; icon lock → `dark:` pasangan; label → `u-text-2`; **3 input → `.u-input`**; submit solid indigo tetap. |
| 12 | `application/views/notification/index.php` | edit (12) | Tombol mark-all → `.u-btn-ghost`; judul → `u-text`; separator tanggal → `dark:bg-slate-700`; empty state → token; **`$type_styles` + `dark:` pasangan** (bg/icon/pill); kartu unread/read → **`.u-notif-unread` / `.u-notif-read`**; **JS `markAllRead` → token-aware** (swap `u-notif-unread`→`u-notif-read`, title `dark:` classes, tombol sukses `dark:bg-emerald-500/10 dark:text-emerald-400`). |
| 13 | `application/views/help/index.php` | rewrite | Header → token; contact card → `.u-card`; **6 FAQ item `bg-white border-slate-200` → `.u-card`**, pertanyaan → `u-text`, chevron → `u-muted`, jawaban → `u-text-2`; tombol WA/Email solid tetap; footer → `u-muted`. |
| 14 | `application/views/auth/login.php` | rewrite | **Infra standalone**: anti-FOUC (`user_theme`, dark default) + `darkMode:'class'` + token subset (`--u-surface/border/text/text-2/muted/input-bg`) + semantic subset (`.u-surface-bg`, `.u-input`, `.u-btn-dark`, `.u-flash-*`); layout split hero **tetap** (`bg-slate-900` fixed, brand hero); **form card `bg-white` → `.u-surface-bg`** (flip); label → `dark:text-slate-300`; input → `.u-input`; submit → `.u-btn-dark`; flash → `.u-flash-*`; **reCAPTCHA dibungkus `rounded-xl overflow-hidden`**; link akun → `dark:text-blue-400`. |
| 15 | `application/views/auth/register.php` | rewrite | Sama dengan login (infra standalone + 3 input + reCAPTCHA wrapper + `.u-btn-dark`). |
| 16 | `application/views/auth/change_password.php` | rewrite | Sama dengan login (tanpa reCAPTCHA/link bawah). |
| 17 | `plan/32_USER_THEME_MANAGER_PLAN.md` | new | Blueprint (fase sebelumnya, APPROVED). |
| 18 | `plan/33_USER_THEME_MANAGER_SUMMARY.md` | new | File ini. |

**Tanpa perubahan:** controller, model, routes, config, `templates/csrf_meta.php` (netral) — toggle murni client-side (localStorage + class `dark`), tidak ada endpoint baru. **Admin app (Phase 30) tidak tersentuh** (prefix `u-` vs `t-`, key `user_theme` vs `admin_theme`).

---

## 2. Hasil Lint (`php -l`)

Semua **16 file PHP** yang dimodifikasi lulus (16/16, 0 error):

```
No syntax errors detected in application/views/templates/header.php
No syntax errors detected in application/views/templates/bottom_nav.php
No syntax errors detected in application/views/home/index.php
No syntax errors detected in application/views/marketplace/index.php
No syntax errors detected in application/views/rentals/index.php
No syntax errors detected in application/views/team/index.php
No syntax errors detected in application/views/wallet/index.php
No syntax errors detected in application/views/wallet/withdraw.php
No syntax errors detected in application/views/wallet/bank_bind.php
No syntax errors detected in application/views/profile/index.php
No syntax errors detected in application/views/profile/change_password.php
No syntax errors detected in application/views/notification/index.php
No syntax errors detected in application/views/help/index.php
No syntax errors detected in application/views/auth/login.php
No syntax errors detected in application/views/auth/register.php
No syntax errors detected in application/views/auth/change_password.php
```

---

## 3. Audit Kebersihan Tema (grep literal warna)

`grep -rnE "bg-white|bg-slate-|text-slate-|border-slate-"` di `application/views` (non-admin, eksklusi literal yang punya pasangan `dark:`) → 175 match tersisa, **semuanya keputusan desain yang disengaja** (sesuai blueprint §5.6):

- **Interior kartu finansial `u-card-fin` (tetap gelap di dua tema — §2.8):** wallet balance card (label `text-slate-400`, tombol disabled `bg-slate-600`/`bg-slate-400`), withdraw & bank_bind bank cards (`text-slate-400`, `border-slate-800`, "Bound"), rentals rig cards + summary bar (`text-slate-500`, `bg-slate-800` disabled, gradient `from-slate-800 via-slate-900 to-indigo-950`, `border-slate-700/50`), team mission card (`text-slate-400`, progress `bg-slate-700`, disabled `bg-slate-700 border-slate-600`).
- **QR container team** (`bg-white ... border-slate-100`) — §2.8, keterbacaan scanner.
- **Auth split-hero** (`bg-slate-900` body + container, 3 file) — §2.7, identitas brand.
- **Hero home** (`text-slate-300` subtitle di atas gambar hero) — invariant.
- **header.php** `hover:text-slate-700 dark:hover:text-slate-200` — bell, punya pasangan dark.
- **Prefix "Rp"** `text-slate-400` di input topup/withdraw — terbaca di kedua tema (`--u-input-bg`).
- Aksen solid invariant (indigo/emerald/rose/orange/amber + gradient blue→indigo) — identik dua tema, tidak di-token.

Tidak ada literal tema netral yang lolos tanpa mekanisme flip.

---

## 4. Fitur yang Dieksekusi (checklist blueprint)

- ✅ **Default Dark**: `dark` class di `<html>` via anti-FOUC (header.php + 3 auth standalone).
- ✅ **Persistence**: `localStorage.getItem('user_theme')` / `setItem('user_theme', 'dark'|'light')` — key sesuai spesifikasi, terpisah dari `admin_theme`.
- ✅ **Anti-FOUC**: inline script pertama di `<head>`, sebelum Tailwind/CDN.
- ✅ **Toggle UI**: Sun/Moon di sticky header (`header.php` — di codebase ini header.php memang berisi topbar aktual) **dan** item "Tema Tampilan" di hub Profile (`profile/index.php`); ikon/label sinkron via `syncThemeUI()`.
- ✅ **Palet Futuristic AI GPU**: Deep Obsidian `#040711` + Neural Surface `#0b1120` + glow cyan/indigo (dark) ↔ Tech SaaS putih/slate + indigo refined (light); `color-scheme` per tema.
- ✅ **Component tokenization**: `.u-shell/app/card/card-gpu/card-fin/card-inset/input/select/label/btn-cyber/btn-dark/btn-ghost/badge-ai/badge-*/nav-bottom/nav-item/nav-active/nav-dot/modal/modal-backdrop/flash-*/divide/row-hover/notif-read/unread/toast/amt-inactive/topbar/capsule`.
- ✅ **Z-index fixes**: notif dropdown `z-50`→`z-[60]` (header), toast copy `z-50`→`z-[60]` (home); semua modal sudah `z-[60]`.
- ✅ **Fix bug terbaca**: judul rentals `from-slate-900 to-slate-700` (gelap-di-gelap) → gradient cyan/indigo + `dark:`.
- ✅ **JS-set classes theme-aware**: wallet `INACTIVE`→`.u-amt-inactive`, notification `markAllRead`→token classes, home copy swap→`.u-btn-ghost`, marketplace balance→`dark:` variants.
- ✅ **QR tetap putih** (keputusan); **auth pages** infra standalone + split hero dipertahankan + reCAPTCHA rounded.
- ✅ **`u-card-fin` tetap gelap di dua tema** (estetika terminal finansial, konsisten Phase 30).

---

## 5. Verifikasi Runtime — RUNBOOK (perlu DB `db_webtable` + user ter-seed)

Environment eksekusi ini tidak memiliki MySQL, sehingga langkah berikut **belum dijalankan** dan wajib diverifikasi di mesin dev:

1. `CI_ENV=development php -S localhost:8080` dari project root (atau vhost `synapse.test`); login sebagai user biasa.
2. **Mode Dark (default)** — HTTP 200 + kontras OK di semua halaman user:
   `/home`, `/marketplace`, `/rentals`, `/team`, `/wallet`, `/wallet/withdraw`, `/wallet/bind_bank`, `/profile`, `/profile/change-password`, `/notification`, `/help`.
   → shell obsidian, card Neural Surface, border glow; **tidak ada blok putih menyilaukan**; blade rentals flip gelap→elegan; judul rentals terbaca.
3. **Mode Light** — klik toggle (header & hub profile); ulangi semua halaman; kontras `u-text-2`/`u-muted` memadai; QR tetap ter-scan; `u-card-fin` tetap gelap (by design).
4. **Anti-FOUC**: reload/hard-refresh — tidak ada flash terang→gelap; `user_theme` persist; ikon toggle sinkron (header + profile); halaman auth (login/register/change-password) mengikuti preferensi tersimpan.
5. **Interaksi**: dropdown notifikasi terbuka **di atas bottom nav** (fix `z-[60]`); modal marketplace/rentals/team/profile (buka/tutup, backdrop, scroll lock); klaim ROI/level-1 (gradient tetap); topup amt-btn (inactive/active di dua tema); mark-all-read (styling unread→read); copy invite/referral (toast); reCAPTCHA tampil benar di auth dark; FAQ accordion; bottom nav glow dot pada halaman aktif.
6. **Regresi non-UI**: AJAX notifikasi/klaim tetap 200; tidak ada perubahan controller/model/routes.

---

## 6. Catatan & Risiko

- **Deviasi kecil dari blueprint:** token subset auth memakai `.u-surface-bg` (bukan `.u-card`) untuk form card — menghindari border di tepi card melengkung split-hero; keputusan implementasi, bukan perubahan desain.
- **`u-card-fin` interior memakai warna slate fixed** (bukan token) — disengaja (kartu selalu gelap); jangan di-"perbaiki" menjadi token saat refactor berikutnya.
- **JS-set classes**: semua sudah diarahkan ke class token; string `dark:` di JS aman dengan Tailwind Play CDN (MutationObserver), dan semua string juga muncul statis di file (pola Phase 30).
- **Pagination** tidak relevan (user app tanpa pagination server-rendered).
- **`user_theme` vs `admin_theme`** tetap terpisah — preferensi tema user tidak memengaruhi admin dan sebaliknya (dual-auth hard separation).
- **reCAPTCHA** selalu putih — wrapper rounded; widget pihak ketiga tidak bisa di-theme-ize tanpa risiko.
- **Placehold.co images** tetap (konten); overlay hero memakai token transparan.
- **Belum diverifikasi runtime** (keterbatasan environment): lihat runbook §5.
- **Prasyarat branch:** pekerjaan Phase 10D + Phase 30 masih belum di-commit di working tree saat eksekusi (state `git status` kotor) — sebelum merge/branching, pastikan commit terpisah per fase agar diff Phase 32 bersih (16 file view user + 2 file plan).
