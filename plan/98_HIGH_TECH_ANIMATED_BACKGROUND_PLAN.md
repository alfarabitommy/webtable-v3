# Plan 98 — Refinement: High-Tech Animated Background "Circuit Grid + Flowing Data Pulses" (Halaman Auth)

> **Status:** BLUEPRINT (dokumen arsitektur & styling SAJA — Plan Mode). Tidak ada
> kode aplikasi, controller, model, view, atau aset yang dibuat/diubah/dihapus oleh
> dokumen ini. Deliverable round ini = file ini saja
> (`plan/98_HIGH_TECH_ANIMATED_BACKGROUND_PLAN.md`); eksekusi implementasi
> MENUNGGU instruksi lanjutan terpisah dari pemilik repositori.
>
> **Ruang lingkup (persis 3 view):**
> - `application/views/auth/login.php`
> - `application/views/auth/register.php`
> - `application/views/auth/change_password.php`
>
> **Non-goals:** header member (`templates/header.php`), halaman `profile/change_password`,
> admin login / `control-panel`, halaman maintenance 503, mesin CAPTCHA (plan/72),
> theme toggle (plan 97), lang-switcher (plan 94), dan seluruh halaman lain.

---

## 1. Ringkasan & Tujuan

Plan 97 memperkenalkan ambient background 6 orb. Di implementasi saat ini orb-orb
tersebut **terlihat statis** karena dua penyebab desain: (1) `filter: blur(85px–120px)`
menghapus seluruh bentuk & kontras cahaya sehingga yang terlihat hanya "asap" kabur, dan
(2) siklus animasi 26s–46s (`auth-drift-1/2`, `auth-energy`) terlalu lambat untuk
dipersepsi dalam 2–3 detik pertama *landing*. Plan 98 menaikkan estetika menjadi
**high-tech yang aktif & terbaca**: circuit/grid SVG tajam + **data pulses yang benar-benar
mengalir** di atas trace (metafora GPU neural network), dengan plasma ambient yang
"dijinakkan" (blur 35px–50px) sehingga bloom punya bentuk dan kontras, serta ritme
animasi 6s–12s.

Diagnosis kondisi saat ini (nomor baris = HEAD; identik di ketiga view):

| Gejala | Akar masalah di kode (HEAD) |
|---|---|
| Orb tampak statis / "frozen smoke" | `filter: blur(110px/100px/90px/120px/85px/105px)` + `radial-gradient(... transparent 66–70%)` → gradien sudah memudar sebelum blur bekerja; tidak ada tepi/inti yang terbaca |
| Gerakan tidak terasa saat landing | Durasi `26s/31s/36s/38s/29s/46s` (baris 117–134); satu siklus > setengah menit |
| Tidak ada narasi "AI GPU / data" | Latar hanya warna; tidak ada elemen konduktif (trace, node, paket data) |
| `prefers-reduced-motion` hanya menutup orb | Baris 193: `@media (...) { .orb { animation:none } }` — bila Plan 98 menambah layer animasi, aturan ini wajib diperluas |

Arah perbaikan (per spesifikasi pemilik):

1. **Cyber Circuit / Grid Mesh** — grid vektor halus + trace 45°/90° dengan junction
   node menyala, dirender sebagai **satu inline SVG** (zero-dep, tema-adaptif via CSS var).
2. **Flowing Data Pulses (fitur inti)** — paket cahaya berjalan di sepanjang bus/trace
   utama via `stroke-dasharray` + animasi `stroke-dashoffset` murni CSS.
3. **Tamed Ambient Plasma** — orb lama diremajakan menjadi 5 "plasma core" kecil
   (blur 35px–50px) dengan inti terang dan tepi terbaca, dirender sebagai bloom
   non-destruktif di atas vektor.
4. **Dynamic Timing** — seluruh siklus ambien 7s–12s; laju paket data ~160px/s
   (durasi 6s–8.5s per bus) sehingga langsung terlihat.
5. **Theme parity** — Dark = obsidian `#050811` + cyan listrik + violet/indigo;
   Light = cleanroom `#f8fafc` + indigo/slate halus + cyan/teal listrik.
6. **Invariants** — 100% CSS + inline SVG; animasi hanya `transform`, `opacity`,
   `stroke-dashoffset`; `prefers-reduced-motion: reduce` → freeze; kartu `auth-card`
   glassmorphism & kontras form **tidak berubah**.

| Aspek | Nilai |
|---|---|
| Halaman | `auth/login.php`, `auth/register.php`, `auth/change_password.php` (standalone) |
| Teknik | 1 SVG inline penuh-bleed (grid pattern + die/chip + trace statis + pulse) + 5 div plasma + 1 vignette; semua animasi CSS |
| Aset eksternal | **NOL** (Tailwind Play CDN yang sudah ada tidak dihitung; tidak ada gambar/font/library baru) |
| Properti yang dianimasikan | Hanya `transform`, `opacity`, `stroke-dashoffset` (spesifikasi 60 FPS) |
| Filter | `blur` & `drop-shadow` bersifat **statis** (tidak pernah dianimasikan) |
| Durasi animasi | Plasma 7s–12s; paket data 6s–8.5s per bus; node blink 7s; ring spin 12s |
| Basis data / i18n | **NOL** perubahan skema; **NOL** key bahasa baru |
| Mutasi uang/saldo | NOL |
| Perubahan controller/model | NOL (murni view + CSS + SVG inline) |

---

## 2. Fakta Codebase yang Menjadi Dasar Desain

> Nomor baris mengacu HEAD saat dokumen ditulis; verifikasi ulang sebelum patch.

### 2.1 Region bersama yang disentuh (identik di ketiga view)

| Region | `login.php` | `register.php` | `change_password.php` | Isi |
|---|---|---|---|---|
| Token Phase 32 (`:root` / `html.dark`) | 27–44 | 27–44 | 27–44 | `--u-surface`, `--u-border`, `--u-text(-2)`, `--u-muted`, `--u-input-bg` — **jangan diubah** |
| Token Plan 97 (`:root` / `html.dark`) | 68–89 / 90–111 | sama | sama | `--u-auth-*` — sebagian nilai **di-retune** (body-1, orb-1..6, vignette) + **disisipkan** token circuit/pulse baru |
| CSS ambient (orb + keyframes) | 114–156 | sama | sama | **DIGANTI** blok Plan 98 (Lampiran A §3) |
| CSS kartu glass | 158–165 | sama | sama | Tidak berubah |
| Media `prefers-reduced-motion` | 193 | sama | sama | **DIPERLUAS** (tidak hanya `.orb`) |
| `@supports` fallback kartu | 194–196 | sama | sama | Tidak berubah |
| Markup ambient | 203–208 | sama | sama | **DIGANTI** blok markup Plan 98 (Lampiran B) |
| Kanopi branding / form card / CAPTCHA | 210–319 | 210–320 | 210–291 | Tidak berubah (kecuali tagline per-halaman yang memang beda) |

Markup ambient saat ini (login.php:203–208, identik di view lain):

```html
<!-- Plan 97: animated ambient background (6 orbs, transform-only, GPU-composited) -->
<div class="auth-ambient absolute inset-0 overflow-hidden pointer-events-none" aria-hidden="true">
    <div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div>
    <div class="orb orb-4"></div><div class="orb orb-5"></div><div class="orb orb-6"></div>
    <div class="auth-vignette absolute inset-0"></div>
</div>
```

### 2.2 Divergensi spesifikasi vs realitas (WAJIB diikuti realitas)

1. **Tidak ada JS runtime untuk animasi.** Semua halaman auth memuat Tailwind Play CDN
   + CSS `<style>`; CAPTCHA refresh satu-satunya skrip. Animasi paket data **harus**
   murni CSS/SVG — tanpa `<animate>`, tanpa `requestAnimationFrame`, tanpa library.
2. **Kartu `auth-card` melapisi 2/3 bawah layar** dengan `backdrop-filter: blur(16px)`.
   Trace/pulse di belakang kartu akan ter-frost — gunakan untuk *depth*: bus bawah boleh
   ada, tetapi jaga opacity rendah (terbaca samar lewat kaca, tidak berkompetisi dengan
   teks form).
3. **Zona aman teks.** Kanopi (baris 211–234) memuat wordmark "SYNAPSE" (gradient text,
   ±x 115–365, y 112–172 saat HEAD) dan cluster lang/toggle kanan-atas (±x 310–464,
   y 16–52, `z-30`). Dataset circuit (Lampiran C) menghindari zona tengah tersebut;
   hanya *signal ring* dashed samar + die di kedua sisi yang boleh berada di area kanopi.
4. **`change_password` butuh sesi login** (`Auth::change_password`, redirect ke `login`
   bila `user_id` kosong). Matriks verifikasi menyesuaikan (302 saat anonim).
5. **Konvensi tema**: anti-FOUC menambah kelas `dark` ke `<html>` bila
   `localStorage['user_theme'] !== 'light'` (default dark). Toggle menukar kelas
   `dark`. Seluruh varian tema Plan 98 dinyatakan sebagai `:root` (light) vs
   `html.dark` (dark) — konsisten dengan blok token existing.
6. **Filter statis boleh, animasi filter tidak.** Blur plasma & glow drop-shadow
   di-set sekali di CSS; animasi hanya `transform`/`opacity`/`stroke-dashoffset`.

---

## 3. Arsitektur Visual

### 3.1 Komposisi lapisan & paint order

Urutan layer (dari bawah ke atas; semuanya di dalam shell
`max-w-[480px] min-h-screen overflow-hidden`, kartu & kanopi tetap `z-10/z-20` di atas):

```
┌ body .u-auth-body — canvas gradient tema (Light: #f8fafc→#eef2ff / Dark: #050811→#0b1120)
│ └ .auth-ambient  (absolute inset-0 overflow-hidden pointer-events-none, z-0, aria-hidden)
│    1. div.plasma-1..5     ← Tamed Plasma Core (blur 35–50px, inti terang, tepi terbaca)
│         BLOOM non-destruktif: html.dark → mix-blend-mode:screen; light → normal + alpha rendah
│    2. svg.auth-circuit    ← Circuit/Grid Mesh (grid pattern + die/chip + trace statis)
│    3. svg.auth-circuit, <g class="layer-pulse"> — Flowing Data Pulses
│         (path pulse = duplikat path bus; dasharray + stroke-dashoffset)
│    4. div.auth-vignette   ← veil tepi (retune alpha per tema)
│ └ section kanopi (z-10: halo, wordmark, tagline) — TIDAK BERUBAH
│ └ div.auth-card (z-20, glass) — TIDAK BERUBAH
```

**Keputusan desain — posisi plasma vs vektor.** Spesifikasi menulis dua hal:
"energy blooms **behind** the circuits" dan urutan konseptual
`Canvas → Grid → Pulses → Plasma Core → Canopy`. Keduanya direkonsiliasi dengan
semantik *bloom aditif* (fisika cahaya): sumber energi plasma berada **di belakang**
papan sirkuit, tetapi cahayanya dikompositkan **di atas** vektor sebagai lapisan
penambah luminansi (bukan penutup). Karena itu paint order mengikuti urutan
konseptual spesifikasi (plasma di atas SVG), dengan tiga jaminan agar vektor tetap
tajam: (a) alpha plasma dibudget rendah–sedang, (b) `html.dark` memakai
`mix-blend-mode: screen` sehingga hanya menambah cahaya ke kanvas obsidian,
(c) tema light memakai `mix-blend-mode: normal` + alpha ≤ 0.35. Kontras garis
diverifikasi di matriks (§6, baris "contrast/crispness").

### 3.2 Token warna & opacity (per tema)

Nilai lama orb diganti (nama token `--u-auth-orb-*` dipertahankan agar kelas plasma
bisa merujuk langsung; blok CSS plasma identik di kedua tema, perbedaan hanya lewat
token). Token baru ber-prefix `--u-auth-` agar sejalan dengan blok Plan 97.

**Kanvas & body gradient** (nilai yang berubah dari Plan 97):

| Token | Light | Dark |
|---|---|---|
| `--u-auth-body-1` | `#f8fafc` (tetap) | `#07090e` → **`#050811`** (deep obsidian) |
| `--u-auth-body-2` | `#eef2ff` (tetap) | `#0b1120` (tetap) |

**Disisipkan ke `:root` (light) dan `html.dark`** — circuit/pulse:

| Token | Light | Dark |
|---|---|---|
| `--u-grid-line` | `rgba(79, 70, 229, 0.09)` | `rgba(103, 232, 249, 0.07)` |
| `--u-trace` | `rgba(79, 70, 229, 0.16)` | `rgba(34, 211, 238, 0.30)` |
| `--u-trace-dim` | `rgba(79, 70, 229, 0.09)` | `rgba(34, 211, 238, 0.15)` |
| `--u-trace-bus` | `rgba(14, 116, 144, 0.35)` | `rgba(56, 189, 248, 0.50)` |
| `--u-node` | `rgba(79, 70, 229, 0.55)` | `rgba(34, 211, 238, 0.80)` |
| `--u-node-core` | `#4f46e5` | `#67e8f9` |
| `--u-ring` | `rgba(99, 102, 241, 0.30)` | `rgba(34, 211, 238, 0.35)` |
| `--u-pulse-a` (cyan, bus kiri) | `#06b6d4` | `#22d3ee` |
| `--u-pulse-b` (violet, bus kanan) | `#4f46e5` | `#a78bfa` |
| `--u-pulse-c` (biru/teal, bus bawah) | `#0d9488` | `#60a5fa` |
| `--u-pulse-glow` (drop-shadow, varian per bus: `-a/-b/-c`) | `drop-shadow(0 0 5px rgba(6, 182, 212, 0.45))` dst. | `drop-shadow(0 0 6px rgba(34, 211, 238, 0.85))` dst. |

**Nilai baru `--u-auth-orb-1..6`** (plasma; inti lebih pekat, tepi memudar cepat):

| Token | Light | Dark |
|---|---|---|
| `--u-auth-orb-1` (cyan, kiri-atas) | `rgba(186, 230, 253, 0.85)` | `rgba(34, 211, 238, 0.60)` |
| `--u-auth-orb-2` (indigo, kanan-atas) | `rgba(199, 210, 254, 0.80)` | `rgba(99, 102, 241, 0.62)` |
| `--u-auth-orb-3` (violet, kanan-tengah) | `rgba(221, 214, 254, 0.80)` | `rgba(139, 92, 246, 0.55)` |
| `--u-auth-orb-4` (biru, kiri-tengah) | `rgba(191, 219, 254, 0.75)` | `rgba(56, 189, 248, 0.52)` |
| `--u-auth-orb-5` (ungu, kiri-bawah) | `rgba(224, 231, 255, 0.80)` | `rgba(129, 140, 248, 0.48)` |
| `--u-auth-orb-6` (lavender, kanan-bawah) | `rgba(233, 213, 255, 0.70)` | `rgba(167, 139, 250, 0.45)` |

`--u-auth-vignette`: light `rgba(226, 232, 240, 0.45)` (dari 0.55) / dark
`rgba(3, 6, 15, 0.55)` (dari 0.6) — vignette sedikit diringankan agar vektor tetap
terbaca di tepi.

### 3.3 Circuit/Grid Mesh (SVG statis)

Satu elemen `<svg class="auth-circuit">` penuh-bleed
(`position:absolute; inset:0; width:100%; height:100%`), `viewBox="0 0 480 900"`
dengan `preserveAspectRatio="xMidYMid slice"`, `aria-hidden="true"`,
`pointer-events:none`. Isi:

1. **Grid pattern** — `<pattern id="p-grid" width="28" height="28" patternUnits="userSpaceOnUse">`
   berisi path `M28 0 H0 V28` 1px ber-stroke `var(--u-grid-line)`, diterapkan lewat
   `<rect class="c-grid" width="480" height="900" fill="url(#p-grid)">`.
2. **Signal ring** — `<circle class="auth-ring" cx="240" cy="148" r="118" fill="none">`
   dashed (`stroke-dasharray: 2 9`), berputar pelan (12s). Ring ini "cincin paket die"
   yang membingkai wordmark — teks tetap di atasnya (z-10).
3. **GPU dies** — dua "chip": *die A* (gateway, kiri-atas) `rect x46 y52 w64 h64 rx10`
   + inti `rect x57 y63 w42 h42 rx6`; *die B* (compute, kanan-atas)
   `rect x404 y58 w52 h52 rx8` + inti `rect x414 y68 w32 h32 rx4`. Masing-masing
   dengan pin bulat kecil (kelas `.pin`, r≈2) di keempat sisinya.
4. **Trace statis** — path `H/V` + diagonal 45° (`L` dengan |dx|=|dy|), stroke tipis
   1px `var(--u-trace)` / `var(--u-trace-dim)`, menghubungkan die ke node/margin.
   Dataset deterministik penuh di Lampiran C.
5. **Junction node** — `<circle class="node">` di titik cabang/ujung trace & endpoint
   bus; endpoint bus memakai `<circle class="node node--core">` (lebih besar, ber-glow
   statis via `drop-shadow`, "blink" 7s). Hanya ±10 node agar DOM hemat.

### 3.4 Flowing Data Pulses (fitur inti)

Mekanisme: tiga **bus** (jalur arteri) dipilih dari trace. Untuk tiap bus dirender
**4 path ber-`d` identik** dengan peran berbeda (semua `fill:none`):

| Path | Peran | CSS |
|---|---|---|
| `.t-bus` | dasar jalur (terlihat saat antar-paket) | `stroke: var(--u-trace-bus)`; w 1.5; `stroke-dasharray: 1 8` (titik-titik samar) — **statis** |
| `.pulse.p-dots` | aliran dot kecil kontinu ("bit stream") | `stroke: var(--u-pulse-*)`; w 2; `stroke-dasharray: 2 18` |
| `.pulse.p-burst` | kepala paket terang ("comet head") | warna sama lebih pekat; w 2.8; `stroke-dasharray: 10 150` |
| `.pulse.p-tail` | ekor paket redup di belakang kepala | warna sama; w 5; opacity .28; `stroke-dasharray: 40 120`, base `stroke-dashoffset: 40px` agar selalu 40px di belakang kepala |

Aturan angka (wajib agar loop mulus & sinkron):

- Periode pola: dots = 20; burst/tail = 160. Total pergeseran per siklus `Δ` harus
  **kelipatan periode** masing-masing → pilih `Δ = 160 × T`, T = durasi siklus:
  - bus-1: `T = 6s`  → `Δ = 960`
  - bus-2: `T = 7s`  → `Δ = 1120`
  - bus-3: `T = 8.5s` → `Δ = 1360`
- Laju konstan ≈ 160px/s di semua bus (960/6 = 1120/7 = 1360/8.5). Kecepatan seragam
  membuat "lalu lintas" terlihat koheren; desinkronisasi antar bus via
  `animation-delay` negatif (bus-1 0s, bus-2 −1.4s, bus-3 −3s).
- Arah aliran = arah path `d` (offset negatif → paket bergerak maju sepanjang path,
  dari die/awal menuju node tujuan, lalu "tiba" dan lenyap di ujung — efek paket
  mencapai tujuan).
- Tail: keyframes terpisah `dash-flow-tail-*` dari `40px` → `40px − Δ` (durasi sama)
  sehingga ekor selalu tertinggal 40px di belakang kepala, tidak pernah mendahului.

Keyframes (Lampiran A): `dash-flow-b1/b2/b3` (`to { stroke-dashoffset: -Δ }`) untuk
dots & burst; `dash-flow-tail-b1/b2/b3` untuk tail. Arah "kepala di depan ekor"
diverifikasi visual (QA §6); bila terbalik cukup balik tanda base offset tail.

### 3.5 Tamed Plasma Core

Menggantikan 6 orb lama → **5 plasma core** (kelas `.plasma`, `.plasma-1..5`):

| Core | Posisi | Ukuran | blur | Animasi | Makna visual |
|---|---|---|---|---|---|
| plasma-1 | left −10%, top −16% | 300px | 45px | drift 7s | energi die A |
| plasma-2 | right −12%, top −12% | 280px | 42px | drift 8s | energi die B |
| plasma-3 | right −18%, top 28% | 340px | 50px | breath 9s | bloom kanan-tengah |
| plasma-4 | left −10%, bottom −10% | 320px | 46px | drift 10s | energi kiri-bawah |
| plasma-5 | right 6%, bottom −18% | 260px | 38px | breath 11s | depth belakang kartu |

Aturan rendering: `background: radial-gradient(circle at 30% 28%, var(--u-auth-orb-N) 0%, transparent 62%)`
dengan token baru (inti pekat α .5–.62 → tepi hilang di 62%, bukan 66–70% lama);
`border-radius: 9999px; pointer-events:none; will-change: transform`. Satu-satunya
filter adalah `blur` **statis** (35–50px sesuai spesifikasi). Animasi transform-only:
`plasma-drift` (`translate3d` ±6vw/±5vh + `scale` 1.06–1.16) dan `plasma-breath`
(`scale` .92–1.08), durasi 7s–11s, `ease-in-out`, `infinite alternate`, delay negatif
bervariasi agar tidak serempak. Tidak ada lagi konic-gradient/rotate 360° lama (46s)
yang membuat "asap berputar lambat".

### 3.6 Canopy & Form Card (tidak berubah / retune minor)

- **Kanopi**: struktur branding (halo, mark neural, wordmark, tagline) tidak berubah.
  `.auth-halo` tetap; efek "die package" baru datang dari `auth-ring` di SVG (3.3).
- **Vignette**: tetap elemen terakhir dalam `.auth-ambient`, hanya nilai token
  diringankan (lihat 3.2) agar vektor di tepi tidak hilang.
- **Form card** `.auth-card` (glass, `rounded-t-[2.5rem]`, backdrop-blur 16px):
  **tidak diubah**. Kontras label/input/error tetap dari token Phase 32; fokus input
  (`focus:ring-2 focus:ring-blue-600/20`) tetap. Karena ambient berada di `z-0` di
  belakang kartu `z-20`, kaca otomatis mem-frost circuit di area bawah — *depth* yang
  diinginkan, bukan noise (opacity trace-bus di area bawah sudah dibudget rendah).
- **`prefers-reduced-motion`**: media query diperluas —
  `.plasma, .pulse, .node, .auth-ring { animation: none !important; }` dan
  `.pulse { stroke-dashoffset: 0; }` (paket "parkir" sebagai titik-titik statis di
  jalurnya). Layout/vektor tetap tampil penuh — hanya gerak yang dibekukan.

---

## 4. Matriks Timing (ringkasan)

| Elemen | Keyframe | Durasi | Delay | Properti animasi |
|---|---|---|---|---|
| plasma-1/-2/-4 | `plasma-drift` | 7s/8s/10s | −1s/−4s/−7s | transform |
| plasma-3/-5 | `plasma-breath` | 9s/11s | −2s/−5s | transform |
| node / node--core | `node-blink` | 7s | bervariasi per kelas | opacity, transform |
| auth-ring | `ring-spin` | 12s | 0 | transform (rotate) |
| bus-1 dots/burst/tail | `dash-flow-b1` / `dash-flow-tail-b1` | 6s | 0 | stroke-dashoffset |
| bus-2 dots/burst/tail | `dash-flow-b2` / `dash-flow-tail-b2` | 7s | −1.4s | stroke-dashoffset |
| bus-3 dots/burst/tail | `dash-flow-b3` / `dash-flow-tail-b3` | 8.5s | −3s | stroke-dashoffset |

Semua `ease-in-out`…**kecuali** aliran paket memakai `linear` (kecepatan konstan =
lalu lintas realistis). Seluruh durasi ambien ≤ 12s dan aliran paket 6s–8.5s —
gerakan langsung terlihat saat *landing* tanpa terasa kacau.

---

## 5. Langkah Implementasi per View

Ketiga view memakai blok identik → **login.php adalah canonical**; setelah diuji di
login, blok yang sama disalin utuh ke dua view lain. Tidak ada partial baru (bedakan
dari Plan 97 yang membuat `auth_theme_toggle.php`; SVG ini dekoratif per-halaman dan
cukup pendek untuk di-inline — menghindari partial baru yang wajib `defined('BASEPATH')`).

### Langkah 1 — `auth/login.php` (canonical)
1. **Token `:root` light** (baris 68–89): sisipkan token circuit/pulse light (3.2)
   setelah baris `--u-auth-vignette: rgba(226, 232, 240, 0.55);`; ganti nilai
   `--u-auth-orb-1..6` light.
2. **Token `html.dark`** (baris 90–111): ubah `--u-auth-body-1` → `#050811`; ganti
   nilai orb dark; sisipkan token circuit/pulse dark setelah baris vignette dark;
   ringankan vignette dark.
3. **CSS ambient** (baris 114–156): ganti seluruh region (komentar
   `/* Ambient layer — transform-only animation ... */` sampai penutup keyframe
   `auth-energy`) dengan blok CSS Plan 98 (Lampiran A).
4. **Media reduce** (baris 193): ganti rule `.orb` dengan rule diperluas (3.6).
5. **Markup ambient** (baris 203–208): ganti dengan markup Plan 98 (Lampiran B).
6. `php -l application/views/auth/login.php` + QA visual (login & register).

### Langkah 2 — `auth/register.php`
Terapkan **diff yang sama persis** (region-nya identik baris-demi-baris dengan login;
tidak ada bagian khusus register di region ini). Verifikasi parity:
`diff <(sed -n '67,196p;203,208p' login.php) <(sed -n '67,196p;203,208p' register.php)`
→ kosong.

### Langkah 3 — `auth/change_password.php`
Terapkan diff yang sama; verifikasi parity dengan perintah diff yang sama
(region 67–196 & 203–208 memang identik di file ini). Tidak ada skrip CAPTCHA di
view ini — tidak tersentuh.

### Langkah 4 — Verifikasi penuh (§6) + lint `php -l` ketiga file.

> Aturan repo: semua file PHP yang disentuh di-lint (`php -l`); commit message Bahasa
> Indonesia; kerja di branch per-phase. Eksekusi tetap menunggu instruksi terpisah.

---

## 6. Matriks Verifikasi

| # | Kriteria | Metode | Ekspektasi |
|---|---|---|---|
| V1 | Lint PHP | `php -l application/views/auth/login.php` (+ register, change_password) | `No syntax errors detected` ×3 |
| V2 | Parity ketiga view | `diff` region 67–196 & 203–208 antar file | Tidak ada perbedaan |
| V3 | Halaman termuat (anonim) | `curl -s http://synapse.test/login` & `/register` | HTTP 200; berisi `auth-circuit`, `plasma-1`, `class="pulse` |
| V4 | Gate sesi change-password | `curl -sI http://synapse.test/auth/change-password` (tanpa sesi) | HTTP 302 → `login`; setelah login 200 & markup Plan 98 ada |
| V5 | Zero aset eksternal baru | DevTools Network (semua request) | Hanya Tailwind CDN + request lokal; **0 gambar/font baru** |
| V6 | Paket data benar-benar mengalir | Inspeksi visual 2–3 detik (dark & light) | Dots & comet bergerak sepanjang bus dari die → node tujuan; kepala paket di depan ekor (arah aliran) |
| V7 | Plasma "tamed" | Inspeksi visual | Bloom punya inti & tepi terbaca (blur 35–50px); bukan asap 85–120px |
| V8 | Timing 6–12s | DevTools Animations / hitung manual | Siklus plasma ≤ 12s; aliran paket 6–8.5s; gerakan terlihat segera |
| V9 | Contrast circuit vs plasma | Inspeksi dark & light | Trace 1px tetap tajam di atas/menembus bloom; zona wordmark bersih (kecuali ring dashed samar) |
| V10 | Theme parity | Toggle Sun/Moon + reload | Dark: kanvas `#050811`, cyan/violet vivid; Light: kanvas `#f8fafc`, indigo/slate halus; preferensi persist (`localStorage 'user_theme'`) |
| V11 | Glass card & kontras form | Inspeksi kedua tema | `auth-card` masih `backdrop-filter: blur(16px)`; label/teks kontras tinggi; fokus input jelas (ring biru) |
| V12 | Reduce motion | DevTools → Emulate `prefers-reduced-motion: reduce` | Semua animasi berhenti (plasma/pulse/node/ring); layout & vektor tetap tampil; tidak ada jank |
| V13 | 60 FPS & properti | DevTools Performance + audit CSS | Properti animasi ⊆ {transform, opacity, stroke-dashoffset}; tidak ada layout thrash; tidak ada animasi `filter`/`background` |
| V14 | A11y & layout | Keyboard tab, zoom, scroll | Ambient `aria-hidden` + pointer-events none; tanpa horizontal scrollbar; elemen interaktif (lang, toggle, input, captcha) tetap fokusable di atas animasi |
| V15 | Non-regresi CAPTCHA/flow | Klik refresh CAPTCHA, isi form, submit | Refresh SVG berfungsi (plan/72); validasi & flashdata tampil normal |
| V16 | Regresi visual lain | Screenshot login/register/change-password (dark+light, ≥2 ukuran layar: 390×844 & 480×900) | Tidak ada elemen bertabrakan; `overflow` tidak memotong kartu |

---

## 7. Risiko & Catatan Implementasi

1. **Repaint `stroke-dashoffset`** bukan kompositor-murni (path ikut di-repaint per
   frame). Mitigasi: hanya 3 bus × 3 path animasi (9 path), area kecil; trace statis,
   grid, dies tidak pernah dianimasikan; `will-change` hanya untuk elemen transform.
   Target tetap 60 FPS di flagship & smooth di menengah — QA V13.
2. **Jumlah `drop-shadow`** dibatasi (hanya pulse burst, node--core, ring) — glow
   berlebihan mahal di perangkat rendah.
3. **Blur besar + backdrop-filter** bisa mahal bila bertumpuk penuh; karena plasma
   hanya 5 core berukuran ≤ 340px dan blur statis, risikonya rendah; pantau di
   perangkat entry-level saat V13.
4. **Kesalahan arah tail** (ekor di depan kepala) mungkin muncul — perbaiki dengan
   membalik tanda base offset `.p-tail` (40px ↔ −40px); tidak mengubah struktur.
5. **Dataset SVG Lampiran C** adalah referensi kanonik; saat implementasi, nudge
   koordinat ±10px diperbolehkan bila QA visual menemukan tabrakan dengan wordmark /
   cluster kanan-atas / tepi `slice` — tanpa mengubah aturan geometri (H/V/45°).
6. **`prefers-reduced-motion`** jangan hanya menonaktifkan animasi plasma — rule
   diperluas ke pulse/node/ring (V12); jika tidak, paket data tetap bergerak untuk
   pengguna yang meminta reduksi gerak.
7. **Jangan menimpa blok Plan 97 yang masih dipakai**: `.u-auth-wordmark`,
   `.auth-card`, `.u-capsule`, `.auth-toggle-btn`, `.auth-halo`, `.auth-vignette`
   (kelas vignette dipertahankan, hanya token berubah) — patch bersifat *replace
   region 114–156 + sisip token + ganti markup*, bukan tulis-ulang `<style>` penuh.

---

## Lampiran A — Blok CSS Plan 98 (pengganti region 114–156 + media reduce 193)

```css
/* ═══ Plan 98: high-tech circuit grid + flowing data pulses (auth standalone) ═══ */
.auth-ambient { z-index: 0; }

/* — Tamed Plasma Core (blur 35–50px; inti pekat; bloom non-destruktif) — */
.plasma { position: absolute; border-radius: 9999px; pointer-events: none;
          will-change: transform; mix-blend-mode: normal; }
html.dark .plasma { mix-blend-mode: screen; }
.plasma-1 { width: 300px; height: 300px; left: -10%; top: -16%; filter: blur(45px);
            background: radial-gradient(circle at 30% 28%, var(--u-auth-orb-1) 0%, transparent 62%);
            animation: plasma-drift 7s ease-in-out -1s infinite alternate; }
.plasma-2 { width: 280px; height: 280px; right: -12%; top: -12%; filter: blur(42px);
            background: radial-gradient(circle at 68% 34%, var(--u-auth-orb-2) 0%, transparent 62%);
            animation: plasma-drift 8s ease-in-out -4s infinite alternate; }
.plasma-3 { width: 340px; height: 340px; right: -18%; top: 28%; filter: blur(50px);
            background: radial-gradient(circle at 60% 42%, var(--u-auth-orb-3) 0%, transparent 64%);
            animation: plasma-breath 9s ease-in-out -2s infinite alternate; }
.plasma-4 { width: 320px; height: 320px; left: -10%; bottom: -10%; filter: blur(46px);
            background: radial-gradient(circle at 40% 58%, var(--u-auth-orb-4) 0%, transparent 63%);
            animation: plasma-drift 10s ease-in-out -7s infinite alternate; }
.plasma-5 { width: 260px; height: 260px; right: 6%; bottom: -18%; filter: blur(38px);
            background: radial-gradient(circle at 55% 50%, var(--u-auth-orb-5) 0%, transparent 62%);
            animation: plasma-breath 11s ease-in-out -5s infinite alternate; }

/* — Circuit/Grid SVG (statis; hanya .auth-ring & .node yang bernapas) — */
.auth-circuit { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
.auth-circuit .c-grid { stroke: var(--u-grid-line); }
.auth-circuit .die--frame { fill: none; stroke: var(--u-trace); stroke-width: 1.2; }
.auth-circuit .die--core { fill: none; stroke: var(--u-trace-dim); stroke-width: 1; }
.auth-circuit .pin { fill: var(--u-node); opacity: 0.8; }
.auth-circuit .t-line { fill: none; stroke: var(--u-trace); stroke-width: 1; }
.auth-circuit .t-line--dim { fill: none; stroke: var(--u-trace-dim); stroke-width: 1; }
.auth-circuit .t-bus { fill: none; stroke: var(--u-trace-bus); stroke-width: 1.5;
                       stroke-dasharray: 1 8; }
.auth-circuit .node { fill: var(--u-node); transform-box: fill-box; transform-origin: center;
                      animation: node-blink 7s ease-in-out infinite; }
.auth-circuit .node--core { fill: var(--u-node-core); filter: drop-shadow(0 0 6px var(--u-node));
                            animation-duration: 4.2s; }
.auth-circuit .node--d2 { animation-delay: -1.8s; }
.auth-circuit .node--d3 { animation-delay: -3.4s; }
.auth-circuit .auth-ring { fill: none; stroke: var(--u-ring); stroke-width: 1.25;
                           stroke-dasharray: 2 9; transform-box: fill-box; transform-origin: center;
                           animation: ring-spin 12s linear infinite; }

/* — Flowing Data Pulses (hanya stroke-dashoffset yang dianimasikan) — */
.auth-circuit .pulse { fill: none; stroke-linecap: round; stroke: currentColor; }
.bus-b1 { color: var(--u-pulse-a); }
.bus-b2 { color: var(--u-pulse-b); }
.bus-b3 { color: var(--u-pulse-c); }
.pulse.p-dots { stroke-width: 2; stroke-dasharray: 2 18;
                animation: dash-flow-b1 6s linear infinite; }
.pulse.p-burst { stroke-width: 2.8; stroke-dasharray: 10 150; filter: var(--u-pulse-glow);
                 animation: dash-flow-b1 6s linear infinite; }
.pulse.p-tail { stroke-width: 5; opacity: 0.28; stroke-dasharray: 40 120;
                animation: dash-flow-tail-b1 6s linear infinite; }
.bus-b2.p-dots, .bus-b2.p-burst { animation: dash-flow-b2 7s linear infinite; }
.bus-b2.p-tail { animation: dash-flow-tail-b2 7s linear infinite; }
.bus-b3.p-dots, .bus-b3.p-burst { animation: dash-flow-b3 8.5s linear infinite; }
.bus-b3.p-tail { animation: dash-flow-tail-b3 8.5s linear infinite; }

/* Keyframes plasma — transform-only (GPU) */
@keyframes plasma-drift {
    0%   { transform: translate3d(0, 0, 0) scale(1); }
    50%  { transform: translate3d(5vw, -4vh, 0) scale(1.14); }
    100% { transform: translate3d(-4vw, 3vh, 0) scale(1.02); }
}
@keyframes plasma-breath {
    0%   { transform: scale(0.94); }
    100% { transform: scale(1.06); }
}
@keyframes node-blink {
    0%, 100% { opacity: 0.35; transform: scale(0.85); }
    50%      { opacity: 1; transform: scale(1.25); }
}
@keyframes ring-spin { to { transform: rotate(360deg); } }

/* Keyframes aliran paket: Δ = 160 × T (kelipatan periode 20 & 160) */
@keyframes dash-flow-b1 { to { stroke-dashoffset: -960; } }
@keyframes dash-flow-tail-b1 { from { stroke-dashoffset: 40px; } to { stroke-dashoffset: -920px; } }
@keyframes dash-flow-b2 { to { stroke-dashoffset: -1120; } }
@keyframes dash-flow-tail-b2 { from { stroke-dashoffset: 40px; } to { stroke-dashoffset: -1080px; } }
@keyframes dash-flow-b3 { to { stroke-dashoffset: -1360; } }
@keyframes dash-flow-tail-b3 { from { stroke-dashoffset: 40px; } to { stroke-dashoffset: -1320px; } }
```

Pengganti media reduce (baris 193):

```css
@media (prefers-reduced-motion: reduce) {
    .plasma, .pulse, .node, .auth-ring { animation: none !important; }
    .pulse { stroke-dashoffset: 0; }
}
```

> Catatan eksekusi: varian warna bus (`--u-pulse-b`/`-c`) diterapkan via kelas kecil
> per bus (`<path class="pulse p-dots bus-b2" ...>` + `.bus-b2 { color: var(--u-pulse-b); }`
> dan `.pulse { stroke: currentColor; }`) agar satu blok CSS melayani tiga bus dengan
> satu pola glow. Bila tim memilih pendekatan langsung (override `stroke` per bus),
> hasil visual akhirnya sama — yang penting glow & dasharray mengikuti aturan §3.4.

## Lampiran B — Markup ambient Plan 98 (pengganti baris 203–208)

```html
<!-- Plan 98: high-tech circuit grid + flowing data pulses (pure CSS/SVG, zero-dep) -->
<div class="auth-ambient absolute inset-0 overflow-hidden pointer-events-none" aria-hidden="true">
    <!-- Tamed plasma cores (bloom non-destruktif) -->
    <div class="plasma plasma-1"></div><div class="plasma plasma-2"></div>
    <div class="plasma plasma-3"></div><div class="plasma plasma-4"></div>
    <div class="plasma plasma-5"></div>

    <!-- Circuit/Grid + Flowing Data Pulses (satu SVG; path lengkap di Lampiran C) -->
    <svg class="auth-circuit" viewBox="0 0 480 900" preserveAspectRatio="xMidYMid slice"
         xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
        <defs>
            <pattern id="p-grid" width="28" height="28" patternUnits="userSpaceOnUse">
                <path d="M28 0 H0 V28" class="c-grid" fill="none"/>
            </pattern>
        </defs>
        <rect width="480" height="900" fill="url(#p-grid)"/>

        <!-- Signal ring (membingkai wordmark) -->
        <circle class="auth-ring" cx="240" cy="148" r="118"/>

        <!-- Die A (gateway, kiri-atas) + Die B (compute, kanan-atas) -->
        <rect class="die--frame" x="46" y="52" width="64" height="64" rx="10"/>
        <rect class="die--core"  x="57" y="63" width="42" height="42" rx="6"/>
        <rect class="die--frame" x="404" y="58" width="52" height="52" rx="8"/>
        <rect class="die--core"  x="414" y="68" width="32" height="32" rx="4"/>

        <!-- Pin die A / die B (Lampiran C) -->
        <!-- Trace statis (Lampiran C: S1..S7) -->
        <!-- Node (Lampiran C) -->

        <!-- Layer pulse: 3 bus × {t-bus, p-dots, p-burst, p-tail} = 12 path (Lampiran C) -->
    </svg>

    <div class="auth-vignette absolute inset-0"></div>
</div>
```

## Lampiran C — Dataset kanonik path (koordinat deterministik)

Aturan geometri: segmen hanya `H` / `V` / diagonal 45° (`L` dengan |dx|=|dy|); semua
koordinat dalam viewBox 480×900; margin aman ≥ 24px dari tepi kiri/kanan dan ≥ 20px
dari tepi atas (area cluster kanan-atas x≥310 y≤52 dihindari; zona wordmark
x 115–365, y 100–180 hanya dilewati *signal ring*).

**Pin die A** (r 2.1, `.pin`): top `(62,52)(78,52)(94,52)`; bottom `(62,116)(78,116)(94,116)`;
left `(46,68)(46,84)(46,100)`; right `(110,68)(110,84)(110,100)`.

**Pin die B** (r 2.1, `.pin`): top `(420,58)(430,58)(440,58)`; bottom `(420,110)(430,110)(440,110)`;
left `(404,72)(404,84)(404,96)`; right `(456,72)(456,84)(456,96)`.

**Trace statis** (kelas `t-line` / `t-line--dim`):

| id | d | node ujung |
|---|---|---|
| S1 (dim) | `M28 84 H 46` | `(28,84)` |
| S2 (dim) | `M46 68 H 30 V 26 H 24` | `(24,26)` |
| S3 (dim) | `M110 68 L 126 84 V 140` | `(126,140)` |
| S4 (dim) | `M110 100 L 128 118 V 240` | `(128,240)` |
| S5 (dim) | `M404 96 L 388 112 V 190` | `(388,190)` |
| S6 (dim) | `M456 72 V 46` | `(456,46)` |
| S7 (dim) | `M62 760 V 824 L 92 854 H 440` | `(440,854)` |

**Bus arteri** (kelas `t-bus` untuk garis dasar; untuk tiap bus tambahkan 3 path
duplikat ber-`d` sama: `.pulse.p-dots`, `.pulse.p-burst`, `.pulse.p-tail` + kelas
bus `bus-b1/b2/b3`):

| id | d (arah aliran = arah path) | warna | node tujuan (core) |
|---|---|---|---|
| BUS-1 | `M62 116 V 300 L 92 330 V 470 L 62 500 V 760` | `--u-pulse-a` cyan | `(62,760)` |
| BUS-2 | `M430 110 V 170 L 456 196 V 300 L 430 326 V 520 L 456 546 V 720` | `--u-pulse-b` violet | `(456,720)` |
| BUS-3 | `M60 640 H 150 L 186 676 H 300 L 336 640 H 420` | `--u-pulse-c` biru/teal | `(420,640)` |

**Node** (kelas `node`; endpoint bus memakai `node node--core`): endpoint bus
`(62,760) (456,720) (420,640)` = core; trace ujung `(24,26) (28,84) (126,140)
(128,240) (388,190) (456,46) (440,854)` = node biasa (distribusi delay
`node--d2/--d3` pada sebagian agar tidak serempak). Total elemen DOM SVG ±50 —
ringan untuk satu layer dekoratif.
