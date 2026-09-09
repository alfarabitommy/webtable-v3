# Plan 97 — Refinement UI Halaman Auth: Ringkasan Implementasi (Wordmark, Ambient BG & Theme Toggle)

> **Status:** IMPLEMENTED — blueprint `plan/97_AUTH_UI_REFINEMENT_LOGO_AND_ANIMATED_BG_PLAN.md`
> dieksekusi sesuai ruang lingkup. `php -l` 4 file 100% hijau; pemindaian teks
> memastikan **nol** sisa `placehold.co`, `<img>`, `bg-slate-900`, `text-white/90`,
> maupun kelas kartu lama di scope auth.
> **Belum diverifikasi:** rendering visual & perilaku runtime di browser
> (tidak ada DB live / host `synapse.test` di sandbox ini) — lihat §5.

---

## 1. Ringkasan

Tiga halaman auth member (`login.php`, `register.php`, `change_password.php`) kini
memakai presentasi visual baru ala identitas platform Phase 32 namun **theme-aware
penuh**: wordmark vektor **SYNAPSE**, **animated ambient background** murni CSS
(6 orbs blur, GPU-composited), kartu form **glassmorphism**, dan **Theme Toggle
Sun/Moon** yang interoperable penuh dengan engine tema member (`user_theme`).
Seluruh aset placeholder eksternal (`placehold.co`) dihapus — **nol dependensi
gambar eksternal**.

| Aspek | Nilai |
|---|---|
| Scope | `application/views/auth/{login,register,change_password}.php` + partial baru `application/views/templates/auth_theme_toggle.php` |
| Perubahan controller/model/DB/i18n | **NOL** (murni view + CSS + JS vanilla) |
| Key tema | `localStorage 'user_theme'` — parity penuh dgn `templates/header.php` (bukan `'theme'`) |
| Key bahasa | Existing semua (`common_toggle_theme`, `auth_tagline_login/register/change_pw`) — tanpa key baru |
| Bug light-mode | Tertutup: `bg-slate-900` hardcoded di `body`/shell diganti token gradient tema |

---

## 2. File & Statistik Perubahan

| File | Status | Perubahan |
|---|---|---|
| `application/views/templates/auth_theme_toggle.php` | **BARU** | 34 baris (markup + skrip inline) |
| `application/views/auth/login.php` | diubah | +180 / −25 |
| `application/views/auth/register.php` | diubah | +183 / −28 |
| `application/views/auth/change_password.php` | diubah | +173 / −18 |

---

## 3. Rincian Implementasi per Area

### 3.1 Partial baru — `templates/auth_theme_toggle.php`

- Tombol round 36 px (`#auth-theme-toggle`, kelas `.auth-toggle-btn`) berisi **dua
  ikon inline SVG** (bulan & matahari, gaya stroke `currentColor`) — Font Awesome
  memang tidak dimuat di halaman auth.
- **State swap murni CSS** (tanpa JS ikon): `.auth-ico-moon` tampil default (light),
  `html.dark` menampilkan `.auth-ico-sun` → konsisten sejak paint pertama
  (anti-FOUC tetap utuh di blok kepala).
- Skrip klik: `html.classList.toggle('dark')` → `localStorage.setItem('user_theme', …)`
  → dispatch `CustomEvent('user-theme-change', {detail:{dark}})` — **parity perilaku**
  dgn `toggleUserTheme()` di `templates/header.php`.
- Guard `window.__authThemeInit` agar aman bila partial dimuat dua kali;
  `aria-label`/`title` = `lang('common_toggle_theme')` (sudah ada EN/ID).

### 3.2 CSS tokens & animasi (blok `<style>` tiap view, byte-identik)

- **Token dual-tema** (`:root` light / `html.dark`):
  - Light: kanvas pearl `#f8fafc → #eef2ff`; orbs sky/lavender/indigo pastel
    (opacity ≤ .60); kartu `rgba(255,255,255,.74)`; wordmark slate-900→indigo-700
    tanpa glow; tagline `#475569`.
  - Dark: kanvas obsidian `#07090e → #0b1120`; orbs cyan/indigo/electric-violet
    (opacity ≤ .42); kartu `rgba(11,17,32,.66)`; wordmark cyan→indigo→violet +
    `drop-shadow` glow; tagline `#cbd5e1`.
- **6 orb ambient**: ukuran 260–420 px, `filter: blur(85–120px)` **statis** per orb;
  animasi hanya `transform` (`translate3d`/`rotate`/`scale`) dengan amplitudo
  `vw`/`vh`, durasi 26–46 s, delay negatif bertingkat, `will-change: transform` →
  nol layout/paint per frame (60 FPS).
  - Orb 1/2/4/5 = radial-gradient (drift lambat);
  - Orb 3/6 = conic-gradient "electric" (drift **+ rotasi**).
- **Kartu glass** `.auth-card`: `backdrop-filter: blur(16px) saturate(1.5)`
  (+ `-webkit-`), border tipis token, shadow token; fallback solid
  `var(--u-surface)` bila `backdrop-filter` tak didukung (`@supports`).
- **`prefers-reduced-motion: reduce`** → animasi orb dimatikan.
- **`.u-capsule`** kini terdefinisi di auth (sebelumnya hanya di `header.php`) —
  lang-switcher mendapat chip glass konsisten di kedua tema.

> Deviasi kecil dari Lampiran A blueprint: orb conic memakai satu keyframe
> `auth-energy` (drift+rotate tergabung) alih-alih dua animasi terpisah
> (`auth-spin` + `auth-drift-3`) — dua animasi pada properti `transform` yang sama
> akan saling menimpa. Perilaku visual sesuai maksud §3.3 plan.

### 3.3 Struktur halaman per view (identik, beda key tagline)

```
<body class="u-auth-body …">                          ← gradient token tema
  div.auth-shell (max-w-480, overflow-hidden, rel)    ← bg-slate-900 dihapus
    ├ div.auth-ambient (absolute inset-0, pointer-events-none)   z-0
    │    ├ .orb.orb-1 … .orb-6
    │    └ .auth-vignette
    ├ section.auth-canopy (h-[34vh] min-h-[260px])               z-10
    │    ├ cluster kanan-atas z-30: lang_switcher + auth_theme_toggle
    │    ├ .auth-halo (glow radial statis di belakang brand)
    │    ├ mark SVG neural (7 node, stroke/fill var(--u-auth-wm-*))
    │    │   + <h1 class="u-auth-wordmark">Synapse</h1>
    │    │     (uppercase via CSS; gradient background-clip:text;
    │    │      letter-spacing .3em + margin-right kompensasi center)
    │    └ p.u-auth-tagline → lang('auth_tagline_login|register|change_pw')
    └ main.auth-card (glass, rounded-t-[2.5rem], -mt-4)          z-20
         └ isi form TIDAK berubah
```

- `<body>`: `bg-slate-900` → `.u-auth-body`.
- Shell: `bg-slate-900` dihapus; layer ambient 6 orb disisipkan setelah pembuka.
- Kanopi lama (`h-[35vh]` + 2 gambar `placehold.co` + scrim gradient slate)
  **dihapus total** → `section` transparan; "faint overlapping text" (gambar
  `?text=Tech+Background`) dan placeholder logo kartu putih (`?text=SYNAPSE+LOGO`)
  tidak ada lagi.
- Tagline lama `text-white/90` → `.u-auth-tagline` warna token tema-adaptif.
- Kartu: `u-surface-bg … shadow-[0_-15px_40px_…]` → `.auth-card`.

---

## 4. Verifikasi yang Sudah Dijalankan

| ID | Perintah / pemeriksaan | Hasil |
|---|---|---|
| V1 | `php -l` ×4 (3 view + partial) | `No syntax errors detected` — 100% hijau |
| V2 | `grep placehold.co` di scope auth | 0 temuan (hanya false-positive `placeholder` atribut) |
| V3 | `grep -n "<img"` di scope auth | 0 temuan — nol gambar eksternal |
| V4 | `grep bg-slate-900 / text-white/90 / u-surface-bg (usage)` | 0 temuan di markup |
| V5 | md5 blok CSS antar 3 view | identik (hash sama) |
| V6 | md5 kanopi antar 3 view (normalisasi key tagline) | identik |
| V7 | Keseimbangan `<section>`/`</section>` per view | 1:1 di ketiga view |
| V8 | `git diff --numstat` | login +180/−25; register +183/−28; change_password +173/−18; partial baru 34 baris |

---

## 5. Sisa Verifikasi (butuh browser/live DB — belum dijalankan)

Matriks V4–V16 pada plan §7 menunggu lingkungan runtime (`synapse.test` + MySQL
atau `php -S` dengan host alias), terutama:

- V4–V6 — tampilan dark & light, regresi light-mode tertutup, persistensi setelah
  refresh (anti-FOUC).
- V7–V8 — interop tema lintas halaman (`user_theme` dibaca dashboard member);
  key localStorage benar.
- V9–V10 — responsif 360/390/desktop; Performance 30 s (compositor-only, 60 FPS).
- V11–V12 — `prefers-reduced-motion` & fallback non-`backdrop-filter`.
- V13–V14 — kontras AA & audit a11y (satu `h1`, aria-label toggle, `aria-hidden`
  dekoratif).
- V16 — smoke login/register (CAPTCHA refresh, flashdata) tetap jalan.
- Catatan: `/auth/change-password` butuh sesi login (redirect `login` tanpa sesi —
  `Auth.php:293`); verifikasi via alur forced-password 7E3 atau login manual.

---

*Ringkasan Plan 97 — menunggu review pemilik & verifikasi runtime browser.*
