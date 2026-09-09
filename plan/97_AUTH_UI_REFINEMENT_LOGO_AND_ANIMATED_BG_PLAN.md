# Plan 97 — Refinement UI Halaman Auth: Wordmark "SYNAPSE", Animated Ambient Background & Theme Toggle

> **Status:** BLUEPRINT (dokumen arsitektur & styling SAJA — Plan Mode). Tidak ada
> kode aplikasi, controller, model, view, atau aset yang dibuat/diubah/dihapus oleh
> dokumen ini. Deliverable round ini = file ini saja
> (`plan/97_AUTH_UI_REFINEMENT_LOGO_AND_ANIMATED_BG_PLAN.md`); eksekusi
> implementasi MENUNGGU instruksi lanjutan terpisah dari pemilik repositori.
>
> **Ruang lingkup (persis 3 view):**
> - `application/views/auth/login.php`
> - `application/views/auth/register.php`
> - `application/views/auth/change_password.php`
>
> **Non-goals:** header member (`templates/header.php`), halaman `profile/change_password`,
> admin login / `control-panel`, halaman maintenance 503, dan seluruh halaman lain.

---

## 1. Ringkasan & Tujuan

Menyamakan presentasi visual tiga halaman auth member agar setara dengan
identitas "Futuristic AI GPU / Deep Obsidian + cyan-indigo glow" platform (Phase 32),
namun kini **theme-aware penuh** (dark **dan** light), **tanpa aset eksternal**
(placehold.co dihapus), dengan **animated ambient background ala macOS/iOS Dynamic
Wallpaper** murni CSS (GPU-composited), **glassmorphism** pada kartu form, dan
**Theme Toggle Sun/Moon** di cluster header kanan-atas berdampingan dengan
lang-switcher.

| Aspek | Nilai |
|---|---|
| Halaman | `auth/login.php`, `auth/register.php`, `auth/change_password.php` (standalone, tanpa `templates/header.php`) |
| Logo | Wordmark vektor **SYNAPSE** (gradient `background-clip: text` + mark SVG inline kecil) — Retina-crisp, **nol gambar eksternal** |
| Background | Ambient orbs blur(80–120px), animasi transform-only (`translate3d`/`rotate`), 60 FPS, `prefers-reduced-motion` dihormati |
| Bug yang diperbaiki | Latar tetap gelap saat light mode (sumber: `bg-slate-900` hardcoded di `body`/shell + scrim gradient slate) |
| Kartu form | Glassmorphism: `backdrop-blur` + border tipis tema + fallback solid bila `backdrop-filter` tak didukung |
| Theme toggle | Tombol Sun/Moon inline-SVG di kanan lang-switcher; persist `localStorage 'user_theme'`; parity penuh dgn engine member (`header.php`) |
| Basis data / i18n | **NOL** perubahan skema; **NOL** key bahasa baru (key existing `common_toggle_theme` + tagline per-halaman sudah tersedia) |
| Mutasi uang/saldo | NOL |
| Perubahan controller/model | NOL (murni view + CSS + JS vanilla inline) |

---

## 2. Fakta Codebase yang Menjadi Dasar Desain

> Nomor baris mengacu HEAD saat dokumen ditulis; nomor dapat bergeser — verifikasi
> ulang sebelum patch. Ketiga view berbagi struktur kepala & kanopi yang **identik
> baris-demi-baris** kecuali judul `<title>` dan key tagline.

### 2.1 Struktur bersama ketiga view (HEAD)

| Region | `login.php` | `register.php` | `change_password.php` | Isi |
|---|---|---|---|---|
| Anti-FOUC theme | 8–19 | 8–19 | 8–19 | `localStorage 'user_theme'`; default **dark** |
| Tailwind Play CDN + `darkMode:'class'` | 21–24 | 21–24 | 21–24 | CDN + konfigurasi |
| `<style>` token subset Phase 32 | 25–66 | 25–66 | 25–66 | `:root`/`html.dark`: `--u-surface`, `--u-border`, `--u-text(-2)`, `--u-muted`, `--u-input-bg` + kelas `.u-surface-bg`, `.u-input`, `.u-btn-dark`, `.u-flash-*` |
| `body` | 68 | 68 | 68 | **`bg-slate-900` hardcoded** ❌ |
| Shell `max-w-[480px]` | 70 | 70 | 70 | **`bg-slate-900` hardcoded** ❌ |
| Kanopi branding | 72–81 | 72–81 | 72–81 | lihat §2.2 |
| Buka kartu form | 84 | 84 | 84 | `u-surface-bg ... rounded-t-[2.5rem] ... -mt-4` (solid, bukan glass) |
| Skrip CAPTCHA refresh | 170–194 | 170–195 | — | hanya login & register |

### 2.2 Kanopi branding saat ini (region 72–81, ketiga view)

```html
<!-- ═══ TOP: Branding & Background ═══ -->
<div class="h-[35vh] w-full relative flex flex-col items-center justify-center shrink-0">
    <!-- Plan 94 (F1): language switcher EN|ID (pra-login) -->
    <div class="absolute top-4 right-4 z-20"><?php $this->load->view('templates/lang_switcher'); ?></div>
    <img src="https://placehold.co/480x400/1e293b/334155?text=Tech+Background" class="absolute inset-0 w-full h-full object-cover opacity-40 mix-blend-overlay" alt="Background">
    <div class="absolute inset-0 bg-gradient-to-b from-slate-900/60 via-slate-900/40 to-slate-900/90"></div>

    <img src="https://placehold.co/160x50/ffffff/0f172a?text=SYNAPSE+LOGO" class="relative z-10 mb-4 rounded-lg shadow-lg" alt="Synapse Logo">
    <p class="relative z-10 text-white/90 text-sm font-medium text-center px-6 leading-relaxed"><?= lang('auth_tagline_login') ?></p>
</div>
```

Pemetaan istilah dari spesifikasi pemilik ke realitas codebase:

| Istilah spesifikasi | Realitas di codebase | Penanganan |
|---|---|---|
| "Temporary white card placeholder button (SYNAPSE LOGO)" | `<img placehold.co/160x50/ffffff/0f172a?text=SYNAPSE+LOGO>` (baris 79) — PNG kartu putih | Dihapus total, diganti wordmark inline (§4) |
| "Faint overlapping text behind it" | `<img placehold.co/480x400/...?text=Tech+Background>` (baris 76) dengan `opacity-40 mix-blend-overlay` — teks "Tech Background" samar menembus di belakang logo | Dihapus total; diganti ambient layer (§3) |
| Scrim gradient | Baris 77 `bg-gradient-to-b from-slate-900/...` — **penyebab kanopi selalu gelap** di light mode | Dihapus; warna tema pindah ke token body/ambient |

### 2.3 Divergensi spesifikasi vs realitas (WAJIB diikuti realitas)

1. **Key localStorage adalah `'user_theme'`, BUKAN `'theme'`.**
   - Anti-FOUC ketiga view auth: `localStorage.getItem('user_theme') !== 'light'` → tambah `.dark` (login.php:12, register.php:12, change_password.php:12).
   - Engine member (`templates/header.php:446`): `localStorage.setItem('user_theme', dark ? 'dark' : 'light')` di dalam `toggleUserTheme()` (header.php:443–449), plus event `user-theme-change` (header.php:448) dan sinkronisasi ikon `syncThemeUI()` (header.php:451–459).
   - **Keputusan:** toggle auth memakai **`user_theme`** (bukan `theme`) agar satu sumber preferensi dengan seluruh aplikasi member. Memakai key `'theme'` akan menciptakan orphan key dan memutus interoperabilitas yang justru diminta spesifikasi. Anti-FOUC existing tidak berubah.

2. **Key tagline per-halaman, bukan `lang('auth_tagline')` generik.**
   - `auth_tagline_login` (EN `'Gateway to the AI Ecosystem'` / ID `'Gerbang Akses Ekosistem AI'`) — dipakai login.php:80.
   - `auth_tagline_register` (`'Join the Global Network'` / `'Bergabung dengan Jaringan Global'`) — register.php:80.
   - `auth_tagline_change_pw` (`'AI Ecosystem Security'` / `'Keamanan Ekosistem AI'`) — change_password.php:80.
   - `lang('auth_tagline')` **tidak ada** di language files → lanjutkan memakai key per-halaman yang sudah ada. Tidak ada key baru.

3. **Font Awesome TIDAK dimuat di halaman auth** (hanya Tailwind Play CDN). Engine member memakai `<i class="fas fa-sun/fa-moon">` (header.php:279, 453). Toggle auth harus **inline SVG** (konsisten dgn lang_switcher.php yang sudah memakai inline SVG bendera). Zero-dep dijaga.

4. **`.u-capsule` TIDAK terdefinisi di halaman auth** (hanya di `templates/header.php:191–197`). Lang-switcher di halaman auth saat ini tampil tanpa chip latar. Plan menambahkan definisi capsule glass ke token subset auth agar cluster header (lang + toggle) punya latar konsisten di kedua tema.

5. **`change_password` butuh sesi login** — `Auth::change_password()` (Auth.php:290–330): redirect `login` bila `user_id` kosong (Auth.php:293–295); alur normal = forced-password 7E3 (Auth.php:272–273) atau user login membuka `/auth/change-password`. Matriks verifikasi menyesuaikan (§7).

### 2.4 Konvensi yang dipakai sebagai acuan visual

- Phase 32 token (header.php:45–87): Dark = Deep Obsidian `#040711`–`#0b1120` + glow cyan/indigo; Light = crisp `#f1f5f9`/white + indigo refined. Sudah ada `--u-glass` (light `rgba(255,255,255,.78)` / dark `rgba(11,17,32,.72)`) dan `--u-glass-border` — token auth meniru semantiknya.
- `docs/4_UI_UX_GUIDELINES.md`: master wrapper light `bg-slate-100`; kontainer `max-w-[480px]`; z-index disiplin (content `z-10/20`, header `z-40`, dst).
- Pola partial: `templates/lang_switcher.php` (markup + inline SVG, tanpa skrip). Partial baru mengikuti pola ini.
- Komit & pesan dokumen Bahasa Indonesia (gaya existing).

---

## 3. Arsitektur Visual

### 3.1 Komposisi lapisan (z-index di dalam shell)

```
┌ body .u-auth-body (gradient tema; token)                     z-0
│ └ div.auth-shell max-w-[480px] relative min-h-screen overflow-hidden flex flex-col
│    ├ div.auth-ambient absolute inset-0 overflow-hidden pointer-events-none  z-0
│    │   ├ .orb.orb-1 … .orb-6   (blob blur 80–120px, animasi transform-only)
│    │   └ div.auth-vignette      (radial halus di bawah, menyatukan kartu)   z-0
│    ├ section.auth-canopy relative h-[34vh] min-h-[260px] … (transparan)     z-10
│    │   ├ div.auth-halo          (radial glow netral di belakang wordmark)   z-0 (dalam section)
│    │   ├ div.auth-topbar absolute top-4 right-4 flex gap-2                 z-30
│    │   │   ├ lang_switcher (partial existing)
│    │   │   └ auth_theme_toggle (partial baru)
│    │   ├ mark SVG neural + h1.wordmark "SYNAPSE"                           z-10
│    │   └ p.auth-tagline                                                  z-10
│    └ main.auth-card relative z-20 glass … rounded-t-[2.5rem] -mt-4 flex-1
│        (isi form existing TIDAK berubah)
```

Aturan:
- Kanopi & `body` **tidak pernah** memakai warna slate hardcoded lagi — warna hanya lewat token (light pearl / dark obsidian) → bug light-mode tertutup di akar.
- `auth-ambient` membentang **seluruh shell** (bukan hanya kanopi) agar kartu glass mendapat *depth*; `pointer-events-none` + `z-0` → non-intrusif terhadap input (klik & fokus form aman).
- Kontrol kanan-atas naik ke `z-30` (di atas kartu `z-20` agar tak tertutup rounded overlap `-mt-4`).
- Seluruh animasi hanya menyentuh `transform`/`opacity` (compositor-only) → tanpa layout thrashing.

### 3.2 Palette tokens (ditambahkan ke blok `<style>` tiap view)

```css
/* ── Plan 97: token ambient & wordmark (auth standalone) ── */
:root {
    --u-auth-body-1: #f8fafc;              /* pearl */
    --u-auth-body-2: #eef2ff;              /* slate-50 → indigo-50 */
    --u-auth-orb-1: rgba(125, 211, 252, .55);   /* sky-300 */
    --u-auth-orb-2: rgba(199, 210, 254, .60);   /* indigo-200 */
    --u-auth-orb-3: rgba(221, 214, 254, .55);   /* violet-200 */
    --u-auth-orb-4: rgba(165, 243, 252, .50);   /* cyan-200 */
    --u-auth-orb-5: rgba(224, 231, 255, .60);   /* indigo-100 */
    --u-auth-orb-6: rgba(147, 197, 253, .45);   /* blue-300 */
    --u-auth-card-bg: rgba(255, 255, 255, .74);
    --u-auth-card-border: rgba(203, 213, 225, .9);
    --u-auth-card-shadow: 0 -12px 40px rgba(51, 65, 85, .14);
    --u-auth-halo: rgba(99, 102, 241, .10);
    --u-auth-vignette: rgba(226, 232, 240, .55);
    --u-auth-wm-a: #0f172a;                 /* slate-900 */
    --u-auth-wm-b: #312e81;                 /* indigo-950 */
    --u-auth-wm-c: #4338ca;                 /* indigo-700 */
    --u-auth-glow: none;
    --u-auth-tag: #475569;                  /* slate-600 */
    --u-capsule-bg: rgba(255, 255, 255, .62);
    --u-capsule-border: rgba(148, 163, 184, .35);
}
html.dark {
    --u-auth-body-1: #07090e;               /* obsidian canvas */
    --u-auth-body-2: #0b1120;
    --u-auth-orb-1: rgba(34, 211, 238, .42);   /* cyan-400  */
    --u-auth-orb-2: rgba(99, 102, 241, .40);   /* indigo-500 */
    --u-auth-orb-3: rgba(139, 92, 246, .38);   /* violet-500 (electric) */
    --u-auth-orb-4: rgba(56, 189, 248, .36);   /* sky-400 */
    --u-auth-orb-5: rgba(129, 140, 248, .34);  /* indigo-400 */
    --u-auth-orb-6: rgba(167, 139, 250, .30);  /* violet-400 (electric 2) */
    --u-auth-card-bg: rgba(11, 17, 32, .66);
    --u-auth-card-border: rgba(56, 189, 248, .16);
    --u-auth-card-shadow: 0 -12px 44px rgba(0, 0, 0, .45);
    --u-auth-halo: rgba(34, 211, 238, .10);
    --u-auth-vignette: rgba(4, 7, 17, .6);
    --u-auth-wm-a: #22d3ee;                 /* cyan-400 */
    --u-auth-wm-b: #6366f1;                 /* indigo-500 */
    --u-auth-wm-c: #a78bfa;                 /* violet-400 */
    --u-auth-glow: drop-shadow(0 0 18px rgba(34, 211, 238, .35));
    --u-auth-tag: #cbd5e1;                  /* slate-300 */
    --u-capsule-bg: rgba(15, 23, 42, .5);
    --u-capsule-border: rgba(148, 163, 184, .25);
}
```

Catatan kontras:
- Wordmark light = slate-900→indigo (kontras tinggi di atas pearl). Wordmark dark = cyan→violet + `drop-shadow` glow tipis.
- Tagline: `var(--u-auth-tag)` — `#475569` (light, di atas orbs pastel) dan `#cbd5e1` (dark) → memenuhi WCAG AA terhadap kanvas masing-masing; tanpa kelas `text-white/90` hardcoded lagi.
- Opacity orbs sengaja **rendah** (≤ .60 light, ≤ .42 dark) agar tidak mengganggu keterbacaan form/kartu (non-intrusif).

### 3.3 Ambient layer — spesifikasi orb & keyframes

6 orb. Tiap orb: `position:absolute; border-radius:9999px; filter: blur(Xpx); will-change: transform;` — **animasi hanya `transform`** (blur/opacity statis per-orb). Rotasi nyata hanya terlihat pada 2 orb "energy" conic-gradient; sisanya radial-gradient.

| Orb | Ukuran (px) | Posisi awal | Bentuk | Blur | Durasi (s) | Delay (s) | Keluarga animasi |
|---|---|---|---|---|---|---|---|
| 1 | 380 | `left:-8%; top:-14%` | radial cyan/sky | 110 | 26 | -6 | `auth-drift-1` |
| 2 | 320 | `right:-10%; top:-6%` | radial indigo | 100 | 31 | -14 | `auth-drift-2` |
| 3 | 300 | `left:6%; top:26%` | **conic electric violet** | 90 | 34 | -20 | `auth-spin` + `auth-drift-3` |
| 4 | 420 | `right:-14%; top:34%` | radial blue/sky | 120 | 38 | -3 | `auth-drift-2` |
| 5 | 260 | `left:-6%; bottom:-10%` | radial lavender | 85 | 29 | -11 | `auth-drift-1` |
| 6 | 360 | `right:2%; bottom:-14%` | **conic electric indigo** | 105 | 44 | -27 | `auth-spin` + `auth-drift-3` |

Gradient warna didefinisikan dengan var token tema (contoh):

```css
.orb-1 { background: radial-gradient(circle at 32% 30%, var(--u-auth-orb-1), transparent 68%); }
.orb-3 { background: conic-gradient(from 0deg, transparent 8%, var(--u-auth-orb-3) 28%, transparent 52%, var(--u-auth-orb-3) 76%, transparent 92%); }
```

Keyframes (satu definisi per keluarga, dipakai banyak orb dgn skala berbeda):

```css
@keyframes auth-drift-1 {
    0%   { transform: translate3d(0, 0, 0) scale(1); }
    50%  { transform: translate3d(9vw, -7vh, 0) scale(1.18); }
    100% { transform: translate3d(-6vw, 4vh, 0) scale(.94); }
}
@keyframes auth-drift-2 {
    0%   { transform: translate3d(0, 0, 0) scale(1); }
    50%  { transform: translate3d(-8vw, 6vh, 0) scale(1.12); }
    100% { transform: translate3d(5vw, -5vh, 0) scale(.92); }
}
@keyframes auth-drift-3 {
    0%   { transform: translate3d(0, 0, 0) scale(1); }
    33%  { transform: translate3d(4vw, 9vh, 0) scale(1.15); }
    66%  { transform: translate3d(-7vw, 3vh, 0) scale(.95); }
    100% { transform: translate3d(0, -6vh, 0) scale(1.05); }
}
@keyframes auth-spin { to { transform: rotate(360deg); } }
```

Pola komposisi per orb (2 animasi sekaligus hanya untuk orb conic):

```css
.orb { position: absolute; border-radius: 9999px; pointer-events: none; will-change: transform; opacity: 1; }
.orb-1 { width: 380px; height: 380px; left: -8%; top: -14%; filter: blur(110px); animation: auth-drift-1 26s ease-in-out -6s infinite alternate; }
.orb-3 { width: 300px; height: 300px; left: 6%; top: 26%; filter: blur(90px);
         animation: auth-spin 34s linear -20s infinite, auth-drift-3 34s ease-in-out -20s infinite alternate; }
```

Jaminan 60 FPS / tanpa thrashing:
1. Hanya properti kompositor (`transform`, `opacity`) yang dianimasikan; `filter: blur(...)` statis per elemen (mem-filter ulang per frame = mahal & dilarang).
2. Amplitudo memakai `vw`/`vh` (viewport-relative) — tidak pernah `top`/`left`/`margin` di keyframes → nol layout/paint, murni compositing.
3. Ukuran > 2× blur agar tepi blur tak tampil; `overflow:hidden` di `auth-ambient` mencegah scrollbar horizontal dari elemen yang keluar kanvas.
4. `will-change: transform` pada 6 elemen saja (bukan blanket).

Hormati `prefers-reduced-motion` & fallback kapabilitas:

```css
@media (prefers-reduced-motion: reduce) {
    .orb { animation: none !important; }
}
@supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
    .auth-card { background-color: var(--u-surface); }   /* solid fallback */
    .auth-topbar .u-capsule { background-color: var(--u-surface-2); }
}
```

### 3.4 Kanopi, halo & kartu glass

- **Kanopi:** `section.auth-canopy` menggantikan div kanopi lama — transparan (warna dari ambient), tinggi `h-[34vh] min-h-[260px]`, tetap `flex flex-col items-center justify-center shrink-0 relative`.
- **Halo di belakang brand:** `div.auth-halo` (absolute, `left-1/2 top-[46%] -translate-x-1/2 -translate-y-1/2`, `w-[240px] h-[240px] rounded-full`, `background: radial-gradient(closest-side, var(--u-auth-halo), transparent 70%)`, `filter: blur(40px)`) — blur sekali, statis, murah; memberi *seating* wordmark di kedua tema.
- **Kartu:** kelas baru `.auth-card` menggantikan kombinasi `u-surface-bg` + `shadow-[...]` pada baris pembuka kartu (84):

```css
.auth-card {
    background-color: var(--u-auth-card-bg);
    -webkit-backdrop-filter: blur(16px) saturate(1.5);
    backdrop-filter: blur(16px) saturate(1.5);
    border: 1px solid var(--u-auth-card-border);
    box-shadow: var(--u-auth-card-shadow);
}
```

Semua kelas utilitas Tailwind pada kartu (radius `rounded-t-[2.5rem]`, `px-6 py-8`, `-mt-4`, `flex-1`, `flex flex-col`, `relative z-20`, `w-full`) **dipertahankan**; hanya `u-surface-bg` dan shadow arbitrer lama yang diganti kelas `.auth-card` (aturan Phase 32: kelas semantik hanya memegang warna/bg/border/shadow; spacing & radius tetap utilitas).

---

## 4. Wordmark "SYNAPSE"

### 4.1 Konsep

Komposisi dua bagian, keduanya inline & zero-dep:
1. **Mark neural SVG** (dekoratif, `aria-hidden`): kisi 7 node + 8 link, stroke gradient cyan→indigo→violet (id unik `a97-mark-grad`; satu instans per dokumen → aman).
2. **Teks wordmark**: `<h1 class="u-auth-wordmark">Synapse</h1>` — gradient `background-clip: text`, uppercase, `font-black`, `tracking` lebar refiner.

### 4.2 CSS wordmark

```css
.u-auth-wordmark {
    font-size: 2.05rem;                 /* ≈ text-3xl+; retina-crisp karena teks asli */
    font-weight: 900;
    letter-spacing: .3em;
    margin-right: -.3em;                /* kompensasi tracking agar center optis */
    text-transform: uppercase;
    background-image: linear-gradient(100deg,
        var(--u-auth-wm-a) 0%, var(--u-auth-wm-b) 58%, var(--u-auth-wm-c) 100%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    filter: var(--u-auth-glow);         /* none di light; drop-shadow cyan di dark */
    line-height: 1.15;
}
```

- Tidak ada font eksternal (stack `font-sans` Tailwind/system) → nol permintaan jaringan, rendering tajam di Retina (gradient & anti-alias teks asli, bukan bitmap PNG).
- `filter` var `--u-auth-glow` memakai `drop-shadow` (bukan `text-shadow`) agar glow mengikuti bentuk glyph hasil gradient.
- Perubahan tema = ganti var token; kelas tidak berubah.

### 4.3 Mark SVG (ringkas, ditempel sebelum `<h1>`)

```html
<svg viewBox="0 0 40 40" class="w-9 h-9 -mr-1 mb-1" aria-hidden="true">
    <defs>
        <linearGradient id="a97-mark-grad" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="var(--u-auth-wm-a)"/>
            <stop offset=".6" stop-color="var(--u-auth-wm-b)"/>
            <stop offset="1" stop-color="var(--u-auth-wm-c)"/>
        </linearGradient>
    </defs>
    <!-- node inti + 6 node tepi + link; stroke=url(#a97-mark-grad) -->
</svg>
```

> Mark tidak wajib; bila pemilik menghendaki wordmark teks murni, hapus blok ini
> tanpa efek lain. Satu-satunya syarat: id gradient unik per dokumen (awalan `a97-`).

### 4.4 Blok brand + tagline (pengganti logo & tagline lama)

```html
<div class="relative z-10 flex items-center justify-center px-8 text-center">
    <svg ...mark...></svg>
    <h1 class="u-auth-wordmark">Synapse</h1>
</div>
<p class="u-auth-tagline relative z-10 mt-3 text-sm font-medium text-center px-6 leading-relaxed">
    <?= lang('auth_tagline_login') ?>   <!-- per halaman: login/register/change_pw -->
</p>
```

- Teks konten wordmark `Synapse` (bukan `SYNAPSE`) + `text-transform: uppercase` → semantik & a11y benar, tampilan uppercase, tanpa hardcode huruf besar di konten.
- `.u-auth-tagline { color: var(--u-auth-tag); }` menggantikan `text-white/90` (sumber kontras salah di light).

---

## 5. Theme Toggle (Partial Baru + Integrasi Cluster)

### 5.1 Partial baru `application/views/templates/auth_theme_toggle.php`

Pola mengikuti `lang_switcher.php` (markup + inline SVG, BASEPATH guard, tanpa aset eksternal). Isi lengkap di Lampiran C.

Markup inti:

```html
<button type="button" id="auth-theme-toggle"
        class="auth-toggle-btn" aria-label="<?= lang('common_toggle_theme') ?>"
        title="<?= lang('common_toggle_theme') ?>">
    <!-- Moon = state light (klik → gelap); Sun = state dark (klik → terang).
         Visibilitas murni CSS agar konsisten sejak parse pertama (anti-FOUC). -->
    <svg class="auth-ico-moon" viewBox="0 0 24 24" ...>…path bulan…</svg>
    <svg class="auth-ico-sun"  viewBox="0 0 24 24" ...>…path matahari…</svg>
</button>
<script>
(function () {
    if (window.__authThemeInit) { return; }
    window.__authThemeInit = true;
    var btn = document.getElementById('auth-theme-toggle');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
        var html = document.documentElement;
        var dark = html.classList.toggle('dark');
        try { localStorage.setItem('user_theme', dark ? 'dark' : 'light'); } catch (e) {}
        window.dispatchEvent(new CustomEvent('user-theme-change', { detail: { dark: dark } }));
    });
})();
</script>
```

CSS ikon (di blok `<style>` tiap view — Lampiran A):

```css
.auth-toggle-btn {
    width: 2.25rem; height: 2.25rem;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 9999px;
    background-color: var(--u-capsule-bg);
    border: 1px solid var(--u-capsule-border);
    color: var(--u-text-2);
    transition: background-color .15s ease, color .15s ease, transform .1s ease;
}
.auth-toggle-btn:hover { color: var(--u-text); }
.auth-toggle-btn:active { transform: scale(.95); }
.auth-ico-moon { display: block; }         /* light → tampil bulan (menuju dark) */
.auth-ico-sun  { display: none; }
html.dark .auth-ico-moon { display: none; } /* dark → tampil matahari (menuju light) */
html.dark .auth-ico-sun  { display: block; }
```

Ikon SVG 18px `fill="none" stroke="currentColor" stroke-width="1.8"` (gaya Feather/Lucide, konsisten dgn ikon CAPTCHA existing yang juga stroke `currentColor`).

### 5.2 Integrasi cluster kanan-atas (ketiga view)

Baris 75 lama:

```html
<div class="absolute top-4 right-4 z-20"><?php $this->load->view('templates/lang_switcher'); ?></div>
```

menjadi (z naik ke 30, cluster glass):

```html
<div class="absolute top-4 right-4 z-30 flex items-center gap-2">
    <?php $this->load->view('templates/lang_switcher'); ?>
    <?php $this->load->view('templates/auth_theme_toggle'); ?>
</div>
```

`lang_switcher.php` **tidak disentuh**; chip visualnya kini muncul karena token subset auth menambahkan definisi `.u-capsule` yang selama ini hilang di halaman auth (§2.3 poin 4):

```css
.u-capsule { background-color: var(--u-capsule-bg); border: 1px solid var(--u-capsule-border); color: var(--u-text-2); transition: background-color .15s ease, border-color .15s ease; }
```

### 5.3 Parity dengan engine member

| Perilaku | Member (`header.php`) | Auth (partial baru) |
|---|---|---|
| Key persist | `localStorage 'user_theme'` (:446) | sama — `'user_theme'` |
| Mekanisme | `html.classList.toggle('dark')` (:445) | sama |
| Event broadcast | `CustomEvent('user-theme-change',{detail:{dark}})` (:448) | sama (parity; tak ada listener di halaman auth) |
| Arah ikon | dark → `fa-sun`; light → `fa-moon` (:453) | CSS: `html.dark` → sun; default → moon (setara) |
| Aria label | `lang('common_toggle_theme')` (:276) | sama — key sudah ada EN `'Toggle theme'` / ID `'Ganti tema'` |
| Anti-FOUC | skrip kepala sebelum CSS | tidak berubah; ikon dikontrol CSS murni jadi konsisten sejak paint pertama |
| Ikon | Font Awesome | inline SVG (FA tidak dimuat di auth) |

Efek interoperabilitas end-to-end: user mengganti tema di halaman login → membuka dashboard → `header.php` membaca `user_theme` yang sama → konsisten. Sebaliknya juga. Key `'theme'` dari spesifikasi **sengaja tidak dipakai** (lihat §2.3 poin 1).

---

## 6. Langkah Implementasi per View

> Semua langkah bersifat *identical* untuk ketiga view (kecuali key tagline), sehingga
> diff ketiga file harus identik strukturnya — memudahkan review. Nomor baris lama
> ditandai "(HEAD)" dan bisa bergeser setelah edit pertama.

### Langkah 1 — `auth/login.php` (file pertama, jadi "canonical")

1. **Blok `<style>` (HEAD 25–66):** tambahkan di akhir (sebelum `</style>`): token ambient & wordmark (§3.2), keyframes (§3.3), `.orb*`, `.auth-halo`, `.auth-card`, `.u-auth-wordmark`, `.u-auth-tagline`, `.u-capsule`, `.auth-toggle-btn` + ikon, media `prefers-reduced-motion`, `@supports` fallback (Lampiran A).
2. **`<body>` (HEAD 68):** `class="bg-slate-900 flex justify-center …"` → `class="u-auth-body flex justify-center min-h-screen font-sans antialiased"`; tambah `.u-auth-body { background: linear-gradient(165deg, var(--u-auth-body-1), var(--u-auth-body-2)); }` di CSS (Lampiran A).
3. **Shell (HEAD 70):** hapus `bg-slate-900` → `class="w-full max-w-[480px] min-h-screen mx-auto relative overflow-hidden flex flex-col"`; sisipkan layer ambient tepat setelah pembuka shell:
   ```html
   <div class="auth-ambient absolute inset-0 overflow-hidden pointer-events-none" aria-hidden="true">
       <div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div>
       <div class="orb orb-4"></div><div class="orb orb-5"></div><div class="orb orb-6"></div>
       <div class="auth-vignette absolute inset-0"></div>
   </div>
   ```
4. **Kanopi (HEAD 72–81):** ganti seluruh blok (Lampiran B): `section.auth-canopy` transparan + cluster lang/toggle + halo + wordmark + tagline. Hapus **kedua** `<img placehold.co>` (baris 76 & 79) dan scrim gradient (baris 77).
5. **Buka kartu (HEAD 84):** `u-surface-bg` → `auth-card` (kelas utilitas radius/shadow lama yang konflik dibuang: hapus `shadow-[0_-15px_40px_rgba(0,0,0,0.2)]`; `-mt-4`, `rounded-t-[2.5rem]`, `px-6 py-8`, `flex-1`, `relative z-20`, `flex flex-col`, `w-full` dipertahankan).
6. **CAPTCHA box (HEAD 140–141):** biarkan (sudah punya pasangan `dark:`); tidak wajib diubah. *(Opsional polish — tidak termasuk scope inti.)*

### Langkah 2 — `auth/register.php`

Salin **persis** perubahan Langkah 1 (blok CSS identik; canopy identik) dengan satu
perbedaan: key tagline `auth_tagline_login` → `auth_tagline_register`.

### Langkah 3 — `auth/change_password.php`

Salin persis (key tagline `auth_tagline_change_pw`). Tidak ada blok CAPTCHA → tidak
ada penyesuaian skrip; partial toggle menyertakan skrip-nya sendiri (Lampiran C)
sehingga tidak perlu `<script>` tambahan di akhir body.

### Langkah 4 — file partial baru

Buat `application/views/templates/auth_theme_toggle.php` (isi penuh Lampiran C),
dengan `defined('BASEPATH') OR exit('No direct script access allowed');` (konvensi
repositori) dan guard `window.__authThemeInit` agar aman bila di-load dua kali.

### Langkah 5 — verifikasi (bagian dari §7) + `php -l` ketiga view + partial.

---

## 7. Matriks Verifikasi

> Saat eksekusi nanti. Lingkungan: `synapse.test` + browser (Chrome devtools mobile
> emulation) atau `php -S localhost:8080` (catatan: konfigurasi default menanti
> `synapse.test`; gunakan host alias bila perlu).

| ID | Langkah verifikasi | Harapan |
|---|---|---|
| V1 | `php -l application/views/auth/login.php` (+ register, change_password, templates/auth_theme_toggle.php) | `No syntax errors detected` ×4 |
| V2 | GET `/login` & `/register` (browser/curl) | HTTP 200; **tidak ada** permintaan jaringan ke `placehold.co` (panel Network kosong utk gambar); nol gambar eksternal |
| V3 | `change_password.php` — login dulu (sesi), lalu buka `/auth/change-password` (tanpa sesi redirect `login` — Auth.php:293) | HTTP 200 dengan sesi; layout baru tampil |
| V4 | Mode default (dark): kanopi obsidian `#07090e–#0b1120`, orb cyan/indigo/violet bergerak halus; wordmark gradient cyan→violet + glow; tagline terbaca | Visual sesuai §3/§4 |
| V5 | Klik toggle → light: **regresi lama tertutup** — `body` & shell pearl `#f8fafc→#eef2ff`, orb sky/lavender pastel, kartu putih glass, wordmark slate-900→indigo, tagline `#475569` | Tidak ada lagi area gelap hardcoded di light |
| V6 | Klik toggle lagi → kembali dark; refresh halaman di masing-masing mode | State bertahan (tanpa FOUC: skrip kepala anti-FOUC + ikon CSS murni konsisten sejak paint pertama) |
| V7 | Buka dashboard member setelah toggle di halaman auth | Tema mengikuti `user_theme` yang sama (interop lintas halaman, §5.3) |
| V8 | Periksa localStorage | Key `user_theme` = `dark`/`light` (bukan key asing `theme`) |
| V9 | Responsif: viewport 360×640, 390×844, desktop ≥ 1024 | Layout `max-w-[480px]` utuh; orb tak memicu scrollbar horizontal; input/kaptcha fokus normal (cluster `pointer-events` benar) |
| V10 | DevTools Performance 30 s di mode dark & light | Tidak ada kerja layout/paint per frame dari animasi; aktivitas compositor saja (60 FPS pada GPU) |
| V11 | DevTools → Rendering → emulate `prefers-reduced-motion: reduce` | Orb statis (tidak ada animasi); halaman tetap lengkap |
| V12 | Nonaktifkan `backdrop-filter` (devtools force) | Kartu jatuh ke solid `var(--u-surface)` (fallback `@supports`) — teks tetap terbaca |
| V13 | Kontras: wordmark & tagline vs kanvas kedua tema | Wordmark ≥ 4.5:1 pada area teks; tagline ≥ 4.5:1 (light) / ≥ 7:1 di atas orb redup (dark) — target AA |
| V14 | Aksesibilitas: satu elemen `h1` (wordmark) per halaman; tombol toggle punya `aria-label`; ikon dekoratif `aria-hidden`; lang-switcher tak berubah perilaku | Audit ringan DOM & keyboard (Tab → Enter toggle) |
| V15 | Perbandingan diff ketiga view | Blok baru identik kecuali key tagline & judul — mudah direview |
| V16 | Smoke: register & login end-to-end tetap jalan (CAPTCHA refresh, flashdata sukses/error tampil di kartu glass) | Alur tak berubah; hanya kulit visual |

Catatan V3: alur normal menuju `change_password` = forced-password 7E3 (Auth.php:272–273)
atau login manual lalu buka URL; verifikasi memakai salah satu.

---

## 8. Risiko & Catatan Implementasi

1. **`backdrop-filter` di Android lama** — mitigasi `@supports` fallback solid (§3.3); kartu tetap terbaca karena token teks terpisah.
2. **Biaya kompositing blur besar** — 6 orb, blur statis, transform-only; kalau profil menunjukkan jank di device kelas bawah, reduksi bertingkat: turunkan opacity dark ke ~.3, matikan 2 orb conic (yang paling mahal), atau naikkan durasi.
3. **Rotasi conic + blur** bisa tampak "berdenyut" bila GPU lemah → orb 3 & 6 adalah kandidat pertama yang dinonaktifkan pada `prefers-reduced-motion` (sudah) atau media query kapasitas.
4. **Jangan menyentuh** `lang_switcher.php`, blok anti-FOUC, konfigurasi Tailwind CDN, CAPTCHA, maupun logika form — scope murni presentasi.
5. **Key `user_theme` vs `'theme'`** — keputusan arsitektur sudah dipatok di §2.3; jangan "menuruti" spesifikasi mentah-mentah karena akan memutus interop lintas halaman.
6. Commit pesan Bahasa Indonesia, satu commit per view atau satu commit berisi ketiganya + partial (ikuti gaya existing; file partial baru wajib `php -l`).

---

## Lampiran A — CSS tambahan (disisipkan di akhir blok `<style>` tiap view)

```css
/* ═══ Plan 97: Auth ambient, wordmark, glass & theme-toggle (auth standalone) ═══ */
:root { /* token light — lihat §3.2 */ }
html.dark { /* token dark — lihat §3.2 */ }

.u-auth-body { background: linear-gradient(165deg, var(--u-auth-body-1), var(--u-auth-body-2)); }

/* Ambient layer */
.auth-ambient { z-index: 0; }
.orb { position: absolute; border-radius: 9999px; pointer-events: none; will-change: transform; }
.orb-1 { width:380px; height:380px; left:-8%;  top:-14%; filter:blur(110px);
         background: radial-gradient(circle at 32% 30%, var(--u-auth-orb-1), transparent 68%);
         animation: auth-drift-1 26s ease-in-out -6s infinite alternate; }
.orb-2 { width:320px; height:320px; right:-10%; top:-6%; filter:blur(100px);
         background: radial-gradient(circle at 70% 40%, var(--u-auth-orb-2), transparent 66%);
         animation: auth-drift-2 31s ease-in-out -14s infinite alternate; }
.orb-3 { width:300px; height:300px; left:6%;  top:26%; filter:blur(90px);
         background: conic-gradient(from 0deg, transparent 8%, var(--u-auth-orb-3) 28%, transparent 52%, var(--u-auth-orb-3) 76%, transparent 92%);
         animation: auth-spin 34s linear -20s infinite, auth-drift-3 34s ease-in-out -20s infinite alternate; }
.orb-4 { width:420px; height:420px; right:-14%; top:34%; filter:blur(120px);
         background: radial-gradient(circle at 60% 45%, var(--u-auth-orb-4), transparent 70%);
         animation: auth-drift-2 38s ease-in-out -3s infinite alternate; }
.orb-5 { width:260px; height:260px; left:-6%; bottom:-10%; filter:blur(85px);
         background: radial-gradient(circle at 40% 60%, var(--u-auth-orb-5), transparent 70%);
         animation: auth-drift-1 29s ease-in-out -11s infinite alternate; }
.orb-6 { width:360px; height:360px; right:2%; bottom:-14%; filter:blur(105px);
         background: conic-gradient(from 90deg, transparent 12%, var(--u-auth-orb-6) 32%, transparent 55%, var(--u-auth-orb-6) 78%, transparent 95%);
         animation: auth-spin 44s linear -27s infinite, auth-drift-3 44s ease-in-out -27s infinite alternate; }
.auth-vignette { background: radial-gradient(120% 90% at 50% 108%, var(--u-auth-vignette), transparent 70%); }

/* Keyframes §3.3 */
@keyframes auth-drift-1 { /* …§3.3… */ }
@keyframes auth-drift-2 { /* …§3.3… */ }
@keyframes auth-drift-3 { /* …§3.3… */ }
@keyframes auth-spin { to { transform: rotate(360deg); } }

.auth-halo { position:absolute; left:50%; top:46%; width:240px; height:240px; transform:translate(-50%,-50%);
             border-radius:9999px; background: radial-gradient(closest-side, var(--u-auth-halo), transparent 70%); }

.auth-card { background-color: var(--u-auth-card-bg);
             -webkit-backdrop-filter: blur(16px) saturate(1.5); backdrop-filter: blur(16px) saturate(1.5);
             border: 1px solid var(--u-auth-card-border); box-shadow: var(--u-auth-card-shadow); }

.u-auth-wordmark { font-size:2.05rem; font-weight:900; letter-spacing:.3em; margin-right:-.3em;
                   text-transform:uppercase; line-height:1.15;
                   background-image: linear-gradient(100deg, var(--u-auth-wm-a) 0%, var(--u-auth-wm-b) 58%, var(--u-auth-wm-c) 100%);
                   -webkit-background-clip:text; background-clip:text; color:transparent; filter:var(--u-auth-glow); }
.u-auth-tagline { color: var(--u-auth-tag); }

/* Cluster atas (lang + toggle) */
.u-capsule { background-color: var(--u-capsule-bg); border:1px solid var(--u-capsule-border);
             color: var(--u-text-2); transition: background-color .15s ease, border-color .15s ease; }
.auth-toggle-btn { width:2.25rem; height:2.25rem; display:inline-flex; align-items:center; justify-content:center;
                   border-radius:9999px; background-color: var(--u-capsule-bg); border:1px solid var(--u-capsule-border);
                   color: var(--u-text-2); transition: background-color .15s ease, color .15s ease, transform .1s ease; }
.auth-toggle-btn:hover { color: var(--u-text); }
.auth-toggle-btn:active { transform: scale(.95); }
.auth-toggle-btn svg { width:18px; height:18px; }
.auth-ico-moon { display:block; } .auth-ico-sun { display:none; }
html.dark .auth-ico-moon { display:none; } html.dark .auth-ico-sun { display:block; }

@media (prefers-reduced-motion: reduce) { .orb { animation: none !important; } }
@supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
    .auth-card { background-color: var(--u-surface); }
}
```

## Lampiran B — Kanopi pengganti (skeleton; tagline menyesuaikan per halaman)

```html
<!-- ═══ TOP: Branding & ambient (Plan 97) ═══ -->
<section class="h-[34vh] min-h-[260px] w-full relative flex flex-col items-center justify-center shrink-0">
    <!-- Plan 94 (F1) + Plan 97: lang switcher & theme toggle (cluster kanan-atas) -->
    <div class="absolute top-4 right-4 z-30 flex items-center gap-2">
        <?php $this->load->view('templates/lang_switcher'); ?>
        <?php $this->load->view('templates/auth_theme_toggle'); ?>
    </div>
    <div class="auth-halo" aria-hidden="true"></div>
    <div class="relative z-10 flex items-center justify-center px-8">
        <!-- [mark SVG neural 36px, stroke url(#a97-mark-grad), aria-hidden] -->
        <h1 class="u-auth-wordmark">Synapse</h1>
    </div>
    <p class="u-auth-tagline relative z-10 mt-3 text-sm font-medium text-center px-6 leading-relaxed">
        <?= lang('auth_tagline_login') ?>
    </p>
</section>
```

## Lampiran C — `application/views/templates/auth_theme_toggle.php` (isi penuh saat eksekusi)

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');
// Plan 97: Theme toggle Sun/Moon — auth standalone (tanpa Font Awesome).
// Persist key 'user_theme' (parity dgn templates/header.php toggleUserTheme).
// Ikon dikontrol CSS murni (html.dark → sun) agar konsisten sejak paint pertama.
?>
<button type="button" id="auth-theme-toggle" class="auth-toggle-btn"
        aria-label="<?= lang('common_toggle_theme') ?>" title="<?= lang('common_toggle_theme') ?>">
    <!-- Moon (state light) -->
    <svg class="auth-ico-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
    </svg>
    <!-- Sun (state dark) -->
    <svg class="auth-ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4"></circle>
        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"></path>
    </svg>
</button>
<script>
(function () {
    if (window.__authThemeInit) { return; }
    window.__authThemeInit = true;
    var btn = document.getElementById('auth-theme-toggle');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
        var html = document.documentElement;
        var dark = html.classList.toggle('dark');
        try { localStorage.setItem('user_theme', dark ? 'dark' : 'light'); } catch (e) {}
        window.dispatchEvent(new CustomEvent('user-theme-change', { detail: { dark: dark } }));
    });
})();
</script>
```

---

*End of Plan 97 (BLUEPRINT — menunggu instruksi eksekusi terpisah).*
