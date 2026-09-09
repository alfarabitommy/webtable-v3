# Plan 99 — Refinement Home Dashboard: "AI Neural Cluster" Hero Card, "World Node Map" Topology & Currency Label Fix (USC → USD)

> **Status:** BLUEPRINT — implementation NOT started. This task only produces this document (`plan/99_...md`); no view, dictionary, controller, model, or asset file is changed by it.
> **Bahasa dokumen:** English (sesuai brief). Format & disiplin mengikuti plan/97 & plan/98 (fakta → arsitektur → token → timing → langkah → matriks verifikasi → lampiran).
> **Lingkup eksekusi nanti:** `application/views/home/index.php` + `application/language/{english,indonesian}/app_lang.php` — dua file view + dua file kamus; TANPA menyentuh controller/model/JS lain.

---

## 1. Ringkasan & Tujuan

Tiga workstream pada member home dashboard (`application/views/home/index.php`), dieksekusi sebagai satu patch UI + i18n:

| # | Workstream | Kondisi sekarang (anchor L) | Target |
|---|-----------|-----------------------------|--------|
| W1 | **Hero "The Future of AI Computing"** | Kotak gelap statis + `<img placehold.co>` bg (L4–20), tidak reaktif terhadap tema terang | **AI Neural Cluster Hero Card** theme-adaptive: obsidian `#0b1120` (dark) / frosted pearl `#ffffff–#f8fafc` (light), gradient text, status header `[ • ONLINE ]`, 3 micro-HUD telemetry pills |
| W2 | **Global Network Topology** | Kotak putih kosong `h-32` + `<img placehold.co>` placeholder (L142–144) | **World Node Map** card: pure inline SVG, 5 glowing GPU hub (SIN-1, JKT-2, TYO-1, US-EAST, FRA-1), animated data arcs, micro-status footer; theme parity |
| W3 | **Currency label** | `home_stat_value` = `'Total Value · USC'` di L96 **kedua** kamus | `'Total Value · USD'` (EN & ID) — label dirender via `lang()`, jadi cukup ubah kamus |

**Standards (invariant, wajib):**
- 100% pure CSS + inline SVG — **nol** external image request (kedua `placehold.co` di halaman ini dihapus).
- Nol JS baru untuk animasi (semua lewat CSS keyframes); satu-satunya JS di halaman (copy-invite toast, L84–138) **tidak disentuh**.
- `prefers-reduced-motion: reduce` dihormati (pola Plan 97/98).
- Responsif 360–480 px (shell `max-w-[480px]`, konten `p-4`).
- Semua copy baru lewat kamus (invariant dual-language Plan 94/L5) — tidak ada string mentah baru di view.
- Copy & angka dekoratif = **data-free** (precedent: stat card `99.99%`, `1,250+`, `1,500,000` sudah hardcoded di view sejak awal). Tidak ada wiring data/controller baru.

---

## 2. Fakta Codebase yang Menjadi Dasar Desain

### 2.1 Region yang disentuh di `application/views/home/index.php` (228 L)

| Region | L | Isi | Aksi |
|--------|---|-----|------|
| Top of page | 1–2 | `<div class="p-4 space-y-6">` + blank | Sisipkan blok `<style>` (Lampiran A) tepat sebelum komentar hero L3 (child pertama container; `<style>` di body valid & menang atas Play CDN karena load belakangan) |
| Hero | 3–20 | komentar + `<div class="relative w-full h-56 …">` + `<img placehold.co>` + overlay gradien + badge ONLINE + title/subtitle | **Ganti total** dengan skeleton Lampiran B |
| Visual Stats header | 140 | komentar `═══ Visual Stats Section ═══` | utuh |
| Wrapper stat | 141 / 183 | `<div>` pembuka/penutup | utuh |
| **Topology placeholder** | 142–144 | `<div class="w-full h-32 …">` + `<img placehold.co>` | **Ganti total** dengan map card Lampiran C (pertahankan `mb-4` agar jarak ke stat grid sama) |
| Stat grid | 146–182 | 4 kartu `u-card-inset` | utuh — label W3 dirender di L180 `lang('home_stat_value')` (cukup ubah kamus, **view tidak berubah**) |
| Copy toast + script | 80–82, 84–138 | `btnCopyInvite` + fallback | utuh (regresi dicek di §8) |
| Warning modal (Plan 89) | 192–227 | `inactiveWarnModal` | utuh |

### 2.2 Kamus & layout (terverifikasi)

- `home_stat_value = 'Total Value · USC'` — **baris 96** di `application/language/english/app_lang.php` **dan** `application/language/indonesian/app_lang.php` (nilai identik, label sengaja berbahasa Inggris di kedua mode).
- File kamus **terurut alfabetis** per key. Slot sisip:
  - `home_hub_*` + `home_hud_*` → antara `home_hero_title` (L83) dan `home_invite_code` (L84).
  - `home_topology_*` → antara `home_stat_value` (L96) dan `home_warn_body` (L97).
- Key hero yang sudah ada & dipakai ulang (EN/ID terverifikasi): `home_online` ('Online'/'Online'), `home_engine_line` ('Synapse Engine v2.0 Active'/'Synapse Engine v2.0 Aktif'), `home_hero_title` ('The Future of AI Computing'/'Masa Depan Komputasi AI').

### 2.3 Mesin tema (kutipan `application/views/templates/header.php`)

- `darkMode:'class'` (L28) + anti-FOUC: `localStorage user_theme !== 'light'` → `html.dark` (L11–21); toggle via `toggleUserTheme()` (L443–449). **Default = dark.**
- Token: `:root` (L48–67, light) vs `html.dark` (L68–87, dark):
  - `--u-bg`: `#f1f5f9` → `#040711`; `--u-surface`: `#ffffff` → `#0b1120`; `--u-border-glow`: `rgba(99,102,241,.25)` → `rgba(56,189,248,.25)`; `--u-glow` ikut tema.
- Artinya hero dark spec `#0b1120` = token `--u-surface` dark → komponen baru memakai nilai literal sesuai spec + CSS vars untuk warna sekunder.

### 2.4 Precedent konvensi

- `<style>` inline di view member: `application/views/rentals/index.php`. Blok `prefers-reduced-motion`: view auth Plan 97/98 (mis. `auth/login.php`).
- Copy user-facing via kamus `lang()` (Plan 94); teks dekoratif Inggris di halaman ini sudah ada precedent (placeholder "Global Network Topology", angka stat).
- Icon: Font Awesome 6.5.1 (sudah di-load header, `fa-*`) — spec memakai emoji (⚡🔒🌐), kita adaptasi ke FA agar konsisten & crisp (keputusan §3.6).
- CSS kustom hanya boleh men-set **color/bg/border/shadow/animation**; radius/spacing/padding tetap utility Tailwind di markup (rule header.php §Phase 32).

### 2.5 Divergensi spec vs realitas (dipatuhi realitas)

1. Spec menulis label `'Total Value - USC'` dengan dash; realitas memakai `'Total Value · USC'` (middle-dot `·`, dipakai juga di header title template). **Fix = penggantian token `USC → USD` saja, separator dipertahankan** → `'Total Value · USD'` (minimal diff, UI terlihat sama kecuali mata uang).
2. Emoji spec → icon FA (§3.6) karena FA sudah terpasang & selaras dengan seluruh UI.
3. Spec "world map outline **atau** coordinate dot grid" → dipilih **stylized graticule + continent glow blobs + hub nodes** (bukan peta kartografis; tidak ada aset peta eksternal, data koordinat deterministik di §4.3–4.5). Ini menghindari gambar outline dunia yang jelek di 360 px dan menjaga file kecil.
4. Spec memakai kata "Global Network Topology" yang selama ini hanya teks di image placeholder → menjadi judul card (dict `home_topology_title`), bukan string mentah.
5. `placehold.co` header logo (L260 header.php, template bersama semua halaman) **di luar scope** — tetap ada; dashboard net external image: **3 → 1** (hanya logo header).

---

## 3. Arsitektur Visual — Hero "AI Neural Cluster" Card

### 3.1 Komposisi lapisan (paint order, di dalam `.hm99-hero`)

```
┌─────────────────────────────────────────────┐
│ .hm99-hero  (relative, overflow:hidden,     │  LAYER 1 — canvas
│   rounded-3xl, min-h 224px, p-5/6, flex-col)│
│  ├── .hm99-orb-a / .hm99-orb-b  (absolute)  │  LAYER 2 — glow orbs (2 div aria-hidden)
│  │     radial-gradient blur blobs, z:0       │
│  ├── .hm99-hero::before  (absolute inset)   │  LAYER 2b — neural dot lattice (CSS radial
│  │     background-image: radial-gradient    │     gradient dot grid + mask fade ke kanan-
│  │     dot grid; mask radial → top-right)   │     atas), opacity rendah
│  ├── .hm99-hero::after (absolute)           │  LAYER 2c — sheen highlight tipis atas
│  └── content wrapper (.relative z-10)       │  LAYER 3 — teks & pills (di atas semua)
│        ├── status row   : pill ● ONLINE     │
│        ├── title        : h2 gradien        │
│        ├── subtitle     : engine line       │
│        └── pill row     : 3 micro-HUD pills │
└─────────────────────────────────────────────┘
```

Lattice (LAYER 2b) = "neural node accents" spec tanpa SVG: `radial-gradient(circle, rgba(...) 1px, transparent 1.5px)` ukuran 20 px, dimask radial agar hanya terlihat di sudut kanan-atas (fade out), opacity ±0.5 (light) / 0.7 (dark). Dua orb (LAYER 2) memberi "neural cluster glow": cyan-600 di kanan-atas, indigo-600 di kiri-bawah, blur 60–90 px via `filter: blur()` CSS (bukan image).

### 3.2 Status header (dipertahankan dari spec)

Pill kiri-atas: dot hijau **berpulsa** (`hm99-statusdot`, ring animation) + teks `lang('home_online')`. Teks `lang('home_engine_line')` menjadi subtitle di bawah title (mono style `font-mono`, `u-text-2`, uppercase tracking-wide, `text-[10px]`) — seluruh copy hero lama (badge, title, engine line) tetap tampil, hanya disusun ulang.

### 3.3 Title gradient text

Title = `lang('home_hero_title')`, class `.hm99-grad-text` (background-clip:text):

| Mode | Gradient (92deg) | Efek |
|------|------------------|------|
| Dark | `#22d3ee 0%` → `#60a5fa 45%` → `#818cf8 100%` (cyan→indigo) | bersinar di obsidian |
| Light | `#312e81 0%` → `#4f46e5 55%` → `#0e7490 100%` (deep indigo) | kontras tinggi di pearl (besar → AA cukup ≥3:1; nilai ≈5.9–7:1) |

Fallback safety: `color: transparent` hanya aktif bila `@supports ((-webkit-background-clip: text) or (background-clip: text))` — jika tidak didukung, warna solid `var(--u-text)`.

### 3.4 Micro-HUD telemetry pills (bawah)

Tiga chip `.hm99-pill`: icon-chip bulat (FA berwarna di atas tint) + label mono `text-[9.5px]`:

| Pill | Icon FA | Warna aksen (dark / light) | Copy key EN / ID |
|------|---------|---------------------------|------------------|
| PFLOPS | `fa-bolt` | cyan-400 / cyan-600 | `home_hud_pflops`: '12.4 PFLOPS Active' / '12.4 PFLOPS Aktif' |
| Isolasi | `fa-lock` | emerald-400 / emerald-600 | `home_hud_isolated`: 'Hardware Isolated' / 'Hardware Terisolasi' |
| Koneksi | `fa-globe` | indigo-400 / indigo-600 | `home_hud_link`: 'Direct Link' / 'Direct Link' |

Pill `flex flex-wrap gap-1.5`; pada 360 px ketiga pill tetap satu baris (≈3 × 118 px) atau wrap rapi ke dua baris — dicek di §8.

### 3.5 Token hero (dark / light)

| Token | Dark | Light |
|-------|------|-------|
| Canvas | `linear-gradient(160deg, #0b1120, #0d1526 55%, #0b1120)`; border `rgba(56,189,248,.16)`; shadow `0 0 24px rgba(56,189,248,.10), 0 0 60px rgba(99,102,241,.08)` + inset tipis | `linear-gradient(165deg, #ffffff, #f8fafc)`; border `#e2e8f0`; shadow `var(--u-glow)` |
| Lattice dot | `rgba(103,232,249,.5)` | `rgba(14,165,233,.35)` |
| Orb A / B | `rgba(56,189,248,.16)` / `rgba(99,102,241,.14)` | `rgba(6,182,212,.10)` / `rgba(99,102,241,.08)` |
| Title | grad §3.3 | grad §3.3 |
| Subtitle/pill text | `#94a3b8` / pill bg `rgba(148,163,184,.08)` border `rgba(148,163,184,.18)` | `#475569` / pill bg `#ffffff` border `#e2e8f0` shadow-sm |
| Status dot | `#34d399` ring `rgba(52,211,153,.6)` | sama (emerald-500, ring emerald) |

### 3.6 Keputusan: emoji → Font Awesome
Spec menulis pill sebagai "⚡ 12.4 PFLOPS Active, 🔒 Hardware Isolated, 🌐 Direct Link". FA (`fa-bolt`, `fa-lock`, `fa-globe`) dipilih: sudah terpasang global, crisp di semua DPR, dan konsisten dengan seluruh kartu lain (stat grid dsb.). Label teks persis mengikuti spec (via kamus).

---

## 4. Arsitektur Visual — World Node Map Card

### 4.1 Komposisi lapisan (`.hm99-map`)

```
┌────────────────────────────────────────────────┐
│ .hm99-map (u-card rounded-2xl overflow-hidden, │  LAYER 1 — surface card (var(--u-surface))
│            mb-4)                               │
│  ├── header row (px-4 pt-3.5 pb-1)             │  LAYER 2 — judul + chip SLA
│  │     icon chip fa-earth-asia + home_topology_title
│  │     … chip kanan: home_topology_sla (dot hijau)
│  ├── svg.hm99-map-svg viewBox="0 0 1000 500"   │  LAYER 3 — kanvas peta (bg transparan,
│  │   preserveAspectRatio="xMidYMid meet"       │     menyatu dengan card; height CSS 165px)
│  │   ├── defs: filter blur, radial glow        │
│  │   ├── graticule: garis tipis 5V + 3H        │  grid dunia
│  │   ├── continent blobs: ellipse + blur       │  hint benua (bukan kartografis)
│  │   ├── arcs: base path + travelling dash     │  data arcs animasi (§4.5)
│  │   ├── hub groups ×5: glow, core, ping ring, │  glowing nodes + label kota
│  │   └── labels (text via lang())              │
│  └── footer row (px-4): chips failover+latency │  LAYER 4 — micro-status
└────────────────────────────────────────────────┘
```

**Pembagian micro-status:** header kanan = chip `home_topology_sla` ('Global SLA: 99.99%'), footer = chip `home_topology_failover` + `home_topology_latency` → ketiga elemen contoh spec tampil. Header `flex flex-wrap` — pada ≤400 px chip SLA boleh turun ke baris kedua header (tidak overlap judul).

### 4.2 ViewBox & responsivitas

- `viewBox="0 0 1000 500"` (rasio 2:1), `preserveAspectRatio="xMidYMid meet"`, CSS `.hm99-map-svg{width:100%;height:165px;display:block}`.
- Rasio mekanik (padding 16 px kiri/kanan): inner width 448 px (480) → skala 0.32 → art 320×160 **terpusat**, gutter kiri/kanan ±64 px diisi warna card (seamless karena bg card = warna kanvas). Inner 328 px (360) → skala 0.328 → art 328×164. **Tidak pernah crop**, semua node & label di dalam art-box.
- Label tidak boleh keluar viewBox: posisi anchor label per hub (§4.4) dipilih menjauhi tepi kiri/kanan (US-EAST label ke kanan, TYO label ke kiri/end-anchor).
- Alternatif height: `h-[150px]` bila total card terasa tinggi; angka 165 px = kompromi readability (label 12.5px di skala 0.32 ≈ 4px layar terlalu kecil?). **Koreksi penting:** pada skala 0.32, teks 12.5 unit ≈ 4 px — tidak terbaca. Maka label & teks dibuat dengan `vector-effect`/ukuran relatif? Tidak — solusi benar: **jangan andalkan teks SVG di dalam art-box kecil**. Desain final: label kota TIDAK dirender sebagai `<text>` SVG, melainkan `foreignObject`? Tetap kecil. **Keputusan:** label hub dirender sebagai **badge HTML overlay** (absolute-positioned % di atas svg, class `hm99-badge`), posisi % = x/10 & y/20 dari dataset; hanya `text` readout numerik/kode pendek (`SIN-1` dst., font 10px) di dalam SVG via `<text>` — ukuran 14 unit ≈ 4.6px masih kecil…

**Keputusan final (anti-regresi readability):** seluruh label = **HTML chip overlay** (`.hm99-hub-label` absolute; translate(-50%,-110%) di atas node; `font-size:9px; font-weight:700`) memakai `lang('home_hub_*')`. SVG hanya memuat geometri (dots/arcs/rings). Overlay diberi posisi `left: X%` `top: Y%` dari dataset → terbaca konsisten di 360–480 px, ikut tema via CSS, dan node tetap glowing murni SVG. SVG container diberi `role="img" aria-label=lang('home_topology_title')`, hub-label HTML `aria-hidden` (duplikat semantik dihindari — judul sudah menerangkan).

Dataset §4.3–4.5 tetap memakai koordinat art-box 1000×500 (geometri); konversi % untuk overlay: `left = x/10 %`, `top = y/20 %` (mis. SIN x788 → 78.8%; y246 → 49.2%).

### 4.3 Dataset kanonik — proyeksi & kontinen (deterministik)

Proyeksi equirectangular sederhana: `x = (lon+180)/360 × 1000`, `y = (90−lat)/180 × 500`.

Continent **blobs** (ellipse stylized + `feGaussianBlur stdDeviation=16`; bukan peta akurat — dekoratif):

| Blob | cx | cy | rx | ry |
|------|----|----|----|----|
| North America | 230 | 120 | 150 | 75 |
| South America | 345 | 300 | 55 | 95 |
| Europe | 550 | 115 | 48 | 45 |
| Africa | 565 | 280 | 75 | 105 |
| Asia | 760 | 170 | 165 | 95 |
| Southeast Asia & Indonesia | 800 | 250 | 70 | 55 |
| Australia | 855 | 350 | 60 | 40 |

Graticule: vertikal `x = 0,200,400,600,800,1000`; horizontal `y = 100,250,400`; equator (`y=250`) sedikit lebih tegas.

### 4.4 Hub nodes (data terverifikasi dari geografi nyata)

| Hub | Kota (EN/ID) | lon, lat | x | y | Label % (HTML) | Arah label | Delay ping |
|-----|--------------|----------|---|---|----------------|------------|-----------|
| SIN-1 | Singapore / Singapura | 103.85, 1.35 | 788 | 246 | 78.8%, 49.2% | kanan-atas node | 1.2 s |
| JKT-2 | Jakarta / Jakarta | 106.85, −6.2 | 797 | 267 | 79.7%, 53.4% | bawah node (middle) | 1.6 s |
| TYO-1 | Tokyo / Tokyo | 139.70, 35.7 | 888 | 151 | 88.8%, 30.2% | kiri node (end-anchor) | 0.8 s |
| US-EAST | N. Virginia / Virginia Utara | −77.5, 39.0 | 285 | 142 | 28.5%, 28.4% | kanan node | 0.4 s |
| FRA-1 | Frankfurt / Frankfurt | 8.68, 50.1 | 524 | 111 | 52.4%, 22.2% | kanan-bawah node | 0.0 s |

Struktur SVG per node (kelas tema via CSS): lingkaran glow besar (blur/fill lembut) → core `r=6` → ping ring `r=10` (animasi scale+opacity). Label HTML chip: `text-[9px] font-bold`, bg tema + border, `u-text-2`, `translate(-50%,-130%)` dengan `pointer-events-none`, `white-space:nowrap`; di 360 px JKT label di bawah node agar tak bertabrakan label SIN.

### 4.5 Data arcs — path design, koordinat & timing

**Cerita visual:** cross-border workload balancing — 4 rute distribusi (SIN–FRA, FRA–US-EAST, US-EAST–TYO, TYO–SIN) membentuk loop lintas-region yang menyapu kanvas.

**Formula control point** (busur melengkung ke atas dari chord):
`C = ( (x1+x2)/2 , (y1+y2)/2 − lift )`, dengan `lift = clamp(0.30 × |x2−x1|, 36, 130)`.

Path quadratic `M x1 y1 Q Cx Cy x2 y2`:

| Arc | Dari → Ke | Path `d` | Panjang ≈ |
|-----|-----------|----------|-----------|
| A1 | SIN(788,246) → FRA(524,111) | `M788 246 Q656 99 524 111` | ≈ 300 unit |
| A2 | FRA(524,111) → US-EAST(285,142) | `M524 111 Q405 55 285 142` | ≈ 245 unit |
| A3 | US-EAST(285,142) → TYO(888,151) | `M285 142 Q587 17 888 151` | ≈ 620 unit |
| A4 | TYO(888,151) → SIN(788,246) | `M888 151 Q838 163 788 246` | ≈ 140 unit |

Setiap arc = **dua `<path>`** dengan `d` sama: (1) base path — garis lembut full-length (warna tema, opacity rendah, glow halus); (2) travelling dash — `stroke-dasharray: 4 12` (periode 16) yang di-animasi `stroke-dashoffset` 0 → **−320** (kelipatan periode → loop seamless) dengan `animation-direction` diselingi (A2 & A4 `reverse`) agar aliran data dua arah terasa.

**Matriks timing (CSS keyframes, semua di kelas `hm99-*`):**

| Elemen | Animasi | Durasi | Iterasi | Delay/stagger |
|--------|---------|--------|---------|---------------|
| Hero status dot | ping ring (scale .6→1.8, opacity .8→0) | 2.0 s | infinite | — |
| Hero orb A | drift translate/scale halus | 9 s | infinite alternate | — |
| Hero orb B | drift (arah beda) | 11 s | infinite alternate | 1 s |
| Hero lattice & sheen | statis | — | — | — |
| Map graticule/blobs | statis | — | — | — |
| Arc base | glow pulse opacity .5→1 | 6 s | infinite alternate | — |
| Arc travelling dash (A1–A4) | dashoffset 0→−320 | 2.6 s | infinite (A2,A4 reverse) | 0 / 0.65 / 1.30 / 1.95 s |
| Hub ping ring ×5 | scale .7→1.9, opacity .7→0 | 2.4 s | infinite | 0 / .4 / .8 / 1.2 / 1.6 s (FRA→US-EAST→TYO→SIN→JKT) |
| SLA chip dot | pulse | 2.0 s | infinite | — |

`@media (prefers-reduced-motion: reduce)`: semua `animation` & `transition` dinonaktifkan (`none !important`); arc tampil statis (dash pattern tetap terlihat sebagai garis putus-putus), node tetap glowing statis, dot hijau tanpa ring-pulse. Semua konten tetap terbaca.

### 4.6 Micro-status footer

Footer `flex flex-wrap gap-x-3 gap-y-1 px-4`: chip `fa-arrows-rotate`/`fa-shield-halved` + `home_topology_failover`, chip `fa-gauge-high` + `home_topology_latency` (header sudah membawa SLA). Chip = `.hm99-chip` kecil (`text-[9px]`), wrap rapi ≤2 baris di 360 px.

---

## 5. Token tema (ringkasan parity — dark vs light)

| Token | Dark | Light |
|-------|------|-------|
| Map card | `--u-surface` `#0b1120`, border `--u-border` | `--u-surface` `#ffffff`, border `--u-border` (`#e2e8f0`) |
| Graticule | `rgba(148,163,184,.08)` | `rgba(100,116,139,.14)` |
| Continent blob | `rgba(56,189,248,.10)` + blur | `rgba(79,70,229,.07)` + blur |
| Arc base | `rgba(99,102,241,.35)` glow `rgba(34,211,238,.25)` | `rgba(79,70,229,.22)` glow lembut |
| Arc dash | `#22d3ee` (cyan) | `#0ea5e9` (sky-500) |
| Node core | `#22d3ee`, ping `rgba(34,211,238,.6)` | `#0891b2`, ping `rgba(8,145,178,.5)` |
| Hub label chip | bg `rgba(148,163,184,.10)` border `rgba(148,163,184,.22)` text `#cbd5e1` | bg `#ffffff` border `#e2e8f0` text `#334155` shadow-sm |
| Footer/header chips | bg `rgba(148,163,184,.07)` text `#94a3b8` | bg `#f8fafc` text `#475569` |
| Kunci tema | `html.dark` prefix (engine eksisting) | default `:root` |

Aturan CSS: elemen SVG **tanpa** inline `stroke/fill` — semua warna lewat kelas `.hm99-*` + `html.dark .hm99-*` sehingga parity dijamin satu sumber (tidak ada duplikasi atribut yang divergen).

---

## 6. Dictionary Updates

### 6.1 Fix mata uang (W3) — 2 file × 1 baris

```diff
# application/language/english/app_lang.php  (baris 96)
- $lang['home_stat_value'] = 'Total Value · USC';
+ $lang['home_stat_value'] = 'Total Value · USD';

# application/language/indonesian/app_lang.php  (baris 96)
- $lang['home_stat_value'] = 'Total Value · USC';
+ $lang['home_stat_value'] = 'Total Value · USD';
```

(Label dirender via `lang('home_stat_value')` di home view L180 → **tidak ada perubahan view** untuk W3. Kedua kamus memakai nilai identik karena label memang berbahasa Inggris di kedua mode.)

### 6.2 Key baru (12 key × 2 file; sisip terurut alfabetis)

**Slot A — antara `home_hero_title` (L83) dan `home_invite_code` (L84):**

| Key | EN | ID |
|-----|----|----|
| `home_hub_fra` | Frankfurt · FRA-1 | Frankfurt · FRA-1 |
| `home_hub_jkt` | Jakarta · JKT-2 | Jakarta · JKT-2 |
| `home_hub_sin` | Singapore · SIN-1 | Singapura · SIN-1 |
| `home_hub_tyo` | Tokyo · TYO-1 | Tokyo · TYO-1 |
| `home_hub_useast` | N. Virginia · US-EAST | Virginia Utara · US-EAST |
| `home_hud_isolated` | Hardware Isolated | Hardware Terisolasi |
| `home_hud_link` | Direct Link | Direct Link |
| `home_hud_pflops` | 12.4 PFLOPS Active | 12.4 PFLOPS Aktif |

**Slot B — antara `home_stat_value` (L96) dan `home_warn_body` (L97):**

| Key | EN | ID |
|-----|----|----|
| `home_topology_failover` | Multi-Region Failover Active | Failover Multi-Region Aktif |
| `home_topology_latency` | Latency <24ms | Latensi <24ms |
| `home_topology_sla` | Global SLA: 99.99% | SLA Global: 99.99% |
| `home_topology_title` | Global Network Topology | Topologi Jaringan Global |

Catatan: kode hub (`SIN-1` dst.) & unit (`PFLOPS`, `ms`) literal di dalam nilai; hanya bagian bahasa yang diterjemahkan (konvensi Plan 94: jargon teknis dipertahankan). Nilai EN persis mengikuti copy spec W1/W2.

---

## 7. View Diff Outline (ringkasan eksekusi nanti)

1. **`application/views/home/index.php`**
   - Sisip blok `<style>…</style>` (Lampiran A) sebagai child pertama container L1 — sebelum komentar hero L3.
   - **Replace L3–20** (komentar hero + hero lama) dengan komentar baru + skeleton Lampiran B.
   - **Replace L142–144** (box placeholder) dengan skeleton Lampiran C (`.hm99-map` + SVG + overlay hub labels + footer). Wrapper L141/L183 & `mb-4` dipertahankan.
   - Tidak ada perubahan lain: stat grid, copy-toast script (L84–138), promoter card, warning modal Plan 89 tetap utuh.
2. **`application/language/english/app_lang.php`** — 1 ubah (L96) + 12 sisip (2 slot).
3. **`application/language/indonesian/app_lang.php`** — 1 ubah (L96) + 12 sisip (2 slot).
4. **Tidak menyentuh:** controller (`Home.php`), `templates/header.php`, model, JS, `routes.php`, aset.

---

## 8. Verification Matrix (dijalankan saat eksekusi UI, bukan tugas dokumen ini)

| # | Kriteria (spec → bukti) | Cara verifikasi | Ekspektasi |
|---|------------------------|-----------------|------------|
| V1 | W3: label 'Total Value · USD' di EN & ID | `grep -n "home_stat_value" application/language/english/app_lang.php application/language/indonesian/app_lang.php` | 2 baris bernilai `'Total Value · USD'` |
| V2 | USC hilang dari kamus | `grep -c "USC" application/language/english/app_lang.php application/language/indonesian/app_lang.php` | `0` per file |
| V3 | 12 key baru hadir 2 bahasa & terurut | `grep -c "home_hub_\|home_hud_\|home_topology_"` (per file) | `12` per file; urut alfabetis |
| V4 | PHP tetap valid | `php -l` kedua kamus + `application/views/home/index.php` | `No syntax errors` |
| V5 | Nol external image di halaman dashboard | `grep -n "placehold.co" application/views/home/index.php`; network tab | 0 baris; requests hero/topology = 0 (logo header template tetap 1 — out of scope) |
| V6 | Tema dark: hero obsidian + grad cyan→indigo terbaca; map menyatu dgn kanvas, node/arc glow | Browser (default dark) 480 px | visual spec §3.5/§5 |
| V7 | Tema light: hero pearl + border slate + grad deep indigo (kontras ≥4.5:1 utk teks normal, ≥3:1 utk title besar); map white card, node teal/blue, arcs slate/indigo | toggle tema (localStorage light), 480 px | visual spec §3.5/§5; tidak ada "kotak putih kosong" |
| V8 | Responsif 360/390/414/480 px: hero pills wrap rapi, hub labels tak terpotong/overlap, footer chips ≤2 baris, tidak ada scroll horizontal baru | DevTools responsive, kedua tema | pass semua width |
| V9 | `prefers-reduced-motion: reduce` (emulasi Rendering) | DevTools → Rendering → emulasikan; pantau | tidak ada pulse/dash berjalan; konten statis terbaca |
| V10 | Regresi JS eksisting halaman | klik copy invite (dengan & tanpa kode terkunci) | toast bekerja (Plan 89/94 utuh) |
| V11 | Tidak ada aset/JS baru | git status + diff statistik | hanya 3 file diubah (1 view + 2 kamus) + dokumen ini |
| V12 | Invariant: SQL/controller tidak tersentuh; semua copy baru via `lang()` | `git diff` review | 0 perubahan controller/model; 0 string mentah baru |

---

## 9. Risiko & Catatan Implementasi

1. **Angka dekoratif ≠ data live.** Pill "12.4 PFLOPS", SLA "99.99%", "Latency <24ms", posisi hub = presentasi marketing (precedent kartu stat hardcoded). Jika nanti ingin data nyata, itu pekerjaan terpisah (wiring controller) — di luar scope visual ini. Pertimbangkan disclaimer kecil di masa depan bila klaim dianggap terlalu kuat.
2. **Bukan peta kartografis.** Blob benua = stylized ellipse + blur; label "world map" hanya kesan visual. Cukup untuk trust-marketing; jangan dipakai untuk navigasi/geolokasi.
3. **Sisa "USC" di luar scope:** string error `'Sistem: Saldo USC/IDR Anda tidak mencukupi.'` di `application/controllers/Rentals.php` L73 & `application/models/Rental_model.php` L153 **tidak diubah** (di luar spesifikasi W3 yang hanya label metric card). Direkomendasikan follow-up terpisah jika "USC" memang stale branding (catat: bukan bagian dashboard).
4. **Overlay HTML untuk label hub** (keputusan §4.2) menambah beberapa elemen absolut di card; pastikan container `.hm99-map` `position:relative` dan label `pointer-events:none` agar tidak menghalangi klik area (card tidak punya interaksi, tapi jaga kebersihan).
5. **Spesifisitas CSS:** blok `<style>` di body load setelah Tailwind Play CDN → menang untuk properti yang sama; tetap hindari utility Tailwind pada elemen yang di-set CSS kustom (dua sumber = bug tema). Nama kelas ber-prefix `hm99-` anti-kolisi.
6. **Tuning halus saat eksekusi:** delay/arah dash arc, posisi label SIN vs JKT (jarak hanya ±21 unit), tinggi SVG 150 vs 165 px — angka di dokumen ini adalah starting point deterministik; final nudging ≤±10% tanpa mengubah arsitektur.
7. **Bahasa dokumen:** English (brief). Bila tim ingin konvensi plan/97–98 (Bahasa Indonesia), terjemahkan dokumen ini tanpa mengubah keputusan teknis.
8. **Performa:** satu `<feGaussianBlur>` kecil + animasi CSS (transform/opacity/dashoffset) — murah; tidak ada layout thrash. `will-change` tidak diperlukan pada ukuran ini.

---

## Lampiran A — Blok CSS lengkap (paste-ready; disisipkan di top view)

```html
<style>
/* ═══ Plan 99: Home Hero (AI Neural Cluster) + World Node Map — scoped hm99-* ═══
   Aturan: hanya color/bg/border/shadow/animation; radius/spacing tetap utility. */

/* ---------- Hero canvas ---------- */
.hm99-hero {
    position: relative; overflow: hidden; isolation: isolate;
    min-height: 224px; display: flex; flex-direction: column; justify-content: space-between;
    background: linear-gradient(165deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid #e2e8f0; box-shadow: var(--u-glow);
}
html.dark .hm99-hero {
    background: linear-gradient(160deg, #0b1120 0%, #0d1526 55%, #0b1120 100%);
    border: 1px solid rgba(56, 189, 248, .16);
    box-shadow: 0 0 24px rgba(56, 189, 248, .10), 0 0 60px rgba(99, 102, 241, .08),
                inset 0 0 40px rgba(56, 189, 248, .04);
}
/* Neural dot lattice (LAYER 2b) — mask fade ke kanan-atas */
.hm99-hero::before {
    content: ""; position: absolute; inset: 0; z-index: 0; pointer-events: none;
    background-image: radial-gradient(circle, rgba(14, 165, 233, .35) 1px, transparent 1.5px);
    background-size: 20px 20px; opacity: .5;
    -webkit-mask-image: radial-gradient(ellipse 75% 95% at 85% 15%, #000 0%, transparent 65%);
            mask-image: radial-gradient(ellipse 75% 95% at 85% 15%, #000 0%, transparent 65%);
}
html.dark .hm99-hero::before {
    background-image: radial-gradient(circle, rgba(103, 232, 249, .5) 1px, transparent 1.6px);
    opacity: .7;
}
/* Sheen atas (LAYER 2c) */
.hm99-hero::after {
    content: ""; position: absolute; inset: 0; z-index: 0; pointer-events: none;
    background: linear-gradient(180deg, rgba(255,255,255,.5), transparent 40%);
}
html.dark .hm99-hero::after { background: linear-gradient(180deg, rgba(103,232,249,.06), transparent 45%); }

/* Glow orbs (LAYER 2) — dua div aria-hidden di markup */
.hm99-orb { position: absolute; z-index: 0; border-radius: 9999px; pointer-events: none; filter: blur(24px); }
.hm99-orb-a { width: 170px; height: 170px; right: -40px; top: -55px;
              background: radial-gradient(circle, rgba(6,182,212,.16), transparent 65%); }
.hm99-orb-b { width: 190px; height: 190px; left: -70px; bottom: -80px;
              background: radial-gradient(circle, rgba(99,102,241,.13), transparent 65%); }
html.dark .hm99-orb-a { background: radial-gradient(circle, rgba(56,189,248,.20), transparent 65%); }
html.dark .hm99-orb-b { background: radial-gradient(circle, rgba(99,102,241,.17), transparent 65%); }

/* Konten hero */
.hm99-hero-content { position: relative; z-index: 10; display: flex; flex-direction: column;
                     gap: .9rem; height: 100%; }
/* Gradient title */
.hm99-grad-text {
    background-image: linear-gradient(92deg, #312e81 0%, #4f46e5 55%, #0e7490 100%);
    -webkit-background-clip: text; background-clip: text; color: transparent;
}
html.dark .hm99-grad-text {
    background-image: linear-gradient(92deg, #22d3ee 0%, #60a5fa 45%, #818cf8 100%);
}
@supports not ((-webkit-background-clip: text) or (background-clip: text)) {
    .hm99-grad-text { background-image: none; color: var(--u-text); }
}
/* Status dot pulsing */
.hm99-statusdot { width: 7px; height: 7px; border-radius: 9999px; background: #34d399;
                  box-shadow: 0 0 0 0 rgba(52, 211, 153, .65); animation: hm99-ping 2.0s ease-out infinite; }
@keyframes hm99-ping {
    0%   { box-shadow: 0 0 0 0 rgba(52, 211, 153, .65); }
    70%  { box-shadow: 0 0 0 6px rgba(52, 211, 153, 0); }
    100% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0); }
}
/* HUD pills */
.hm99-pill { display: inline-flex; align-items: center; gap: .4rem; padding: .3rem .6rem;
             border-radius: .7rem; background: #ffffff; border: 1px solid #e2e8f0;
             box-shadow: 0 1px 2px rgba(15,23,42,.05); }
html.dark .hm99-pill { background: rgba(148,163,184,.08); border: 1px solid rgba(148,163,184,.18);
                       box-shadow: none; }
.hm99-pill-ic { width: 16px; height: 16px; border-radius: 9999px; display: inline-flex;
                align-items: center; justify-content: center; font-size: 8px; }
.hm99-pill-cy  { background: rgba(8,145,178,.12); color: #0e7490; }
.hm99-pill-em  { background: rgba(5,150,105,.12); color: #047857; }
.hm99-pill-in  { background: rgba(79,70,229,.12); color: #4f46e5; }
html.dark .hm99-pill-cy { background: rgba(34,211,238,.14); color: #22d3ee; }
html.dark .hm99-pill-em { background: rgba(52,211,153,.14); color: #34d399; }
html.dark .hm99-pill-in { background: rgba(129,140,248,.14); color: #818cf8; }
.hm99-pill-lbl { font-family: 'JetBrains Mono', monospace; font-size: 9.5px; font-weight: 600;
                 color: #334155; letter-spacing: .02em; }
html.dark .hm99-pill-lbl { color: #cbd5e1; }

/* ---------- World Node Map card ---------- */
.hm99-map { position: relative; background: var(--u-surface); border: 1px solid var(--u-border); }
.hm99-map-svg { display: block; width: 100%; height: 165px; }
.hm99-chip { display: inline-flex; align-items: center; gap: .35rem; padding: .25rem .55rem;
             border-radius: 9999px; font-size: 9px; font-weight: 700; letter-spacing: .02em;
             background: #f8fafc; border: 1px solid #e2e8f0; color: #475569; }
html.dark .hm99-chip { background: rgba(148,163,184,.07); border: 1px solid rgba(148,163,184,.14); color: #94a3b8; }
.hm99-chip i { font-size: 8px; }
.hm99-chip-live { color: #047857; }
html.dark .hm99-chip-live { color: #34d399; }
.hm99-live-dot { width: 5px; height: 5px; border-radius: 9999px; background: currentColor;
                 box-shadow: 0 0 0 0 currentColor; animation: hm99-ping 2.0s ease-out infinite; }

/* SVG: graticule, blobs, arcs, nodes — warna via kelas (parity satu sumber) */
.hm99-grat { stroke: rgba(100,116,139,.14); stroke-width: 1; fill: none; }
html.dark .hm99-grat { stroke: rgba(148,163,184,.08); }
.hm99-grat-eq { stroke: rgba(100,116,139,.28); }
html.dark .hm99-grat-eq { stroke: rgba(148,163,184,.16); }
.hm99-cont { fill: rgba(79,70,229,.07); }
html.dark .hm99-cont { fill: rgba(56,189,248,.10); }
.hm99-arc { fill: none; stroke: rgba(79,70,229,.22); stroke-width: 1.4; }
html.dark .hm99-arc { stroke: rgba(99,102,241,.35); }
.hm99-arc-dash { fill: none; stroke: #0ea5e9; stroke-width: 2; stroke-linecap: round;
                 stroke-dasharray: 4 12; animation: hm99-dashflow 2.6s linear infinite; }
html.dark .hm99-arc-dash { stroke: #22d3ee; filter: drop-shadow(0 0 3px rgba(34,211,238,.6)); }
@keyframes hm99-dashflow { to { stroke-dashoffset: -320; } }
.hm99-arcglow { animation: hm99-glow 6s ease-in-out infinite alternate; }
@keyframes hm99-glow { from { opacity: .5; } to { opacity: 1; } }
.hm99-ncore { fill: #0891b2; }
html.dark .hm99-ncore { fill: #22d3ee; }
.hm99-nring { fill: none; stroke: rgba(8,145,178,.55); stroke-width: 1.4;
              animation: hm99-node 2.4s cubic-bezier(0,0,.2,1) infinite; }
html.dark .hm99-nring { stroke: rgba(34,211,238,.6); }
@keyframes hm99-node {
    0%   { transform: scale(.7); opacity: .8; }
    70%  { transform: scale(1.9); opacity: 0; }
    100% { transform: scale(1.9); opacity: 0; }
}
.hm99-nring, .hm99-ncore { transform-box: fill-box; transform-origin: center; }

/* Hub label overlay (HTML chip di atas SVG) */
.hm99-hub-label { position: absolute; transform: translate(-50%, -140%); pointer-events: none;
                  white-space: nowrap; padding: 2px 6px; border-radius: 6px; font-size: 9px;
                  font-weight: 700; letter-spacing: .03em; background: #ffffff;
                  border: 1px solid #e2e8f0; color: #334155; box-shadow: 0 1px 2px rgba(15,23,42,.06); }
html.dark .hm99-hub-label { background: rgba(148,163,184,.10); border: 1px solid rgba(148,163,184,.22);
                            color: #cbd5e1; box-shadow: none; }
/* JKT label di bawah node (hindari tabrakan dgn SIN) */
.hm99-hub-jkt { transform: translate(-50%, 46%); }
.hm99-hub-tyo { transform: translate(-108%, -140%); }  /* kiri node, tepi kanan aman */

/* Reduced motion — semua animasi dimatikan, konten statis tetap terbaca */
@media (prefers-reduced-motion: reduce) {
    .hm99-hero *, .hm99-map * { animation: none !important; transition: none !important; }
    .hm99-statusdot, .hm99-live-dot { box-shadow: 0 0 0 2px rgba(52,211,153,.25); }
}
</style>
```

---

## Lampiran B — Skeleton Hero Card (pengganti L3–20 `home/index.php`)

```html
<!-- ═══ AI Neural Cluster Hero Card (Plan 99) ═══ -->
<div class="hm99-hero rounded-3xl shadow-2xl">
    <div class="hm99-orb hm99-orb-a" aria-hidden="true"></div>
    <div class="hm99-orb hm99-orb-b" aria-hidden="true"></div>

    <div class="hm99-hero-content p-5">
        <!-- Status header: [ • ONLINE ] -->
        <div class="flex items-center justify-between">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full border text-[10px] font-bold uppercase tracking-widest
                         bg-emerald-500/15 border-emerald-500/30 text-emerald-600 dark:text-emerald-400">
                <span class="hm99-statusdot"></span>
                <?= lang('home_online') ?>
            </span>
            <span class="hidden xs:inline-flex font-mono text-[9px] u-text-2 font-semibold tracking-[0.18em] uppercase">
                <?= lang('home_engine_line') ?>
            </span>
        </div>

        <!-- Headline -->
        <div class="mt-auto">
            <h2 class="hm99-grad-text text-2xl font-extrabold leading-tight mb-1"><?= lang('home_hero_title') ?></h2>
            <p class="text-[11px] font-medium u-text-2 md:hidden"><?= lang('home_engine_line') ?></p>
        </div>

        <!-- Micro-HUD telemetry pills -->
        <div class="flex flex-wrap gap-1.5">
            <span class="hm99-pill"><span class="hm99-pill-ic hm99-pill-cy"><i class="fas fa-bolt"></i></span>
                <span class="hm99-pill-lbl"><?= lang('home_hud_pflops') ?></span></span>
            <span class="hm99-pill"><span class="hm99-pill-ic hm99-pill-em"><i class="fas fa-lock"></i></span>
                <span class="hm99-pill-lbl"><?= lang('home_hud_isolated') ?></span></span>
            <span class="hm99-pill"><span class="hm99-pill-ic hm99-pill-in"><i class="fas fa-globe"></i></span>
                <span class="hm99-pill-lbl"><?= lang('home_hud_link') ?></span></span>
        </div>
    </div>
</div>
```

> Catatan markup: engine line tampil dua kali secara responsif (kanan-atas mono ≥ ~400 px via `xs:` bila utility tersedia — fallback: hapus `hidden xs:inline-flex` dan biarkan hanya subtitle `md:hidden`→`sm:hidden`); eksekutor memilih satu pola saja agar tidak duplikat (lihat §9.6 — keputusan final kecil di tangan eksekusi, tanpa mengubah token).

---

## Lampiran C — Skeleton World Node Map Card (pengganti L142–144)

```html
<!-- ═══ Global Network Topology — World Node Map (Plan 99) ═══ -->
<div class="hm99-map rounded-2xl overflow-hidden mb-4">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 pt-3.5 pb-1">
        <div class="flex items-center gap-2">
            <span class="w-7 h-7 rounded-lg bg-indigo-100 dark:bg-indigo-500/10 flex items-center justify-center">
                <i class="fas fa-earth-asia text-[11px] text-indigo-600 dark:text-indigo-400"></i>
            </span>
            <span class="text-[11px] font-bold u-text tracking-wide"><?= lang('home_topology_title') ?></span>
        </div>
        <span class="hm99-chip hm99-chip-live"><span class="hm99-live-dot"></span><?= lang('home_topology_sla') ?></span>
    </div>

    <!-- SVG map canvas (geometri only; label = HTML overlay) -->
    <div class="relative px-2">
        <svg class="hm99-map-svg" viewBox="0 0 1000 500" preserveAspectRatio="xMidYMid meet"
             role="img" aria-label="<?= lang('home_topology_title') ?>">
            <defs>
                <filter id="hm99-blur" x="-40%" y="-40%" width="180%" height="180%">
                    <feGaussianBlur stdDeviation="16"/>
                </filter>
            </defs>

            <!-- Graticule (grid dunia) -->
            <g fill="none">
                <path class="hm99-grat" d="M0 0V500M200 0V500M400 0V500M600 0V500M800 0V500M1000 0V500"/>
                <path class="hm99-grat" d="M0 100H1000M0 400H1000"/>
                <path class="hm99-grat hm99-grat-eq" d="M0 250H1000"/>
            </g>

            <!-- Continent blobs (stylized, blur) -->
            <g filter="url(#hm99-blur)">
                <ellipse class="hm99-cont" cx="230" cy="120" rx="150" ry="75"/>
                <ellipse class="hm99-cont" cx="345" cy="300" rx="55" ry="95"/>
                <ellipse class="hm99-cont" cx="550" cy="115" rx="48" ry="45"/>
                <ellipse class="hm99-cont" cx="565" cy="280" rx="75" ry="105"/>
                <ellipse class="hm99-cont" cx="760" cy="170" rx="165" ry="95"/>
                <ellipse class="hm99-cont" cx="800" cy="250" rx="70" ry="55"/>
                <ellipse class="hm99-cont" cx="855" cy="350" rx="60" ry="40"/>
            </g>

            <!-- Data arcs: base + travelling dash (delay & arah per arc) -->
            <g fill="none">
                <path class="hm99-arc hm99-arcglow" d="M788 246 Q656 99 524 111"/>
                <path class="hm99-arc-dash" style="animation-delay:0s"     d="M788 246 Q656 99 524 111"/>
                <path class="hm99-arc hm99-arcglow" d="M524 111 Q405 55 285 142"/>
                <path class="hm99-arc-dash" style="animation-delay:.65s;animation-direction:reverse" d="M524 111 Q405 55 285 142"/>
                <path class="hm99-arc hm99-arcglow" d="M285 142 Q587 17 888 151"/>
                <path class="hm99-arc-dash" style="animation-delay:1.3s"   d="M285 142 Q587 17 888 151"/>
                <path class="hm99-arc hm99-arcglow" d="M888 151 Q838 163 788 246"/>
                <path class="hm99-arc-dash" style="animation-delay:1.95s;animation-direction:reverse" d="M888 151 Q838 163 788 246"/>
            </g>

            <!-- Hubs: glow core + ping ring (stagger) -->
            <g>
                <circle class="hm99-nring" style="animation-delay:0s"    cx="524" cy="111" r="10"/>
                <circle class="hm99-ncore" cx="524" cy="111" r="6"/>
                <circle class="hm99-nring" style="animation-delay:.4s"   cx="285" cy="142" r="10"/>
                <circle class="hm99-ncore" cx="285" cy="142" r="6"/>
                <circle class="hm99-nring" style="animation-delay:.8s"   cx="888" cy="151" r="10"/>
                <circle class="hm99-ncore" cx="888" cy="151" r="6"/>
                <circle class="hm99-nring" style="animation-delay:1.2s"  cx="788" cy="246" r="10"/>
                <circle class="hm99-ncore" cx="788" cy="246" r="6"/>
                <circle class="hm99-nring" style="animation-delay:1.6s"  cx="797" cy="267" r="10"/>
                <circle class="hm99-ncore" cx="797" cy="267" r="6"/>
            </g>
        </svg>

        <!-- Hub label overlays (HTML; posisi % = x/10, y/20 dari dataset §4.4) -->
        <span class="hm99-hub-label" style="left:78.8%;top:49.2%;"><?= lang('home_hub_sin') ?></span>
        <span class="hm99-hub-label hm99-hub-jkt" style="left:79.7%;top:53.4%;"><?= lang('home_hub_jkt') ?></span>
        <span class="hm99-hub-label hm99-hub-tyo" style="left:88.8%;top:30.2%;"><?= lang('home_hub_tyo') ?></span>
        <span class="hm99-hub-label" style="left:28.5%;top:28.4%;"><?= lang('home_hub_useast') ?></span>
        <span class="hm99-hub-label" style="left:52.4%;top:22.2%;"><?= lang('home_hub_fra') ?></span>
    </div>

    <!-- Micro-status footer -->
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 pt-1.5 pb-3">
        <span class="hm99-chip"><i class="fas fa-shield-halved"></i><?= lang('home_topology_failover') ?></span>
        <span class="hm99-chip"><i class="fas fa-gauge-high"></i><?= lang('home_topology_latency') ?></span>
    </div>
</div>
```

---

## Lampiran D — Dictionary patch (baris persis, eksekusi nanti)

`application/language/english/app_lang.php`:
```diff
@@ L83 @@  home_hero_title … lalu sisip SEBELUM home_invite_code:
+$lang['home_hub_fra'] = 'Frankfurt · FRA-1';
+$lang['home_hub_jkt'] = 'Jakarta · JKT-2';
+$lang['home_hub_sin'] = 'Singapore · SIN-1';
+$lang['home_hub_tyo'] = 'Tokyo · TYO-1';
+$lang['home_hub_useast'] = 'N. Virginia · US-EAST';
+$lang['home_hud_isolated'] = 'Hardware Isolated';
+$lang['home_hud_link'] = 'Direct Link';
+$lang['home_hud_pflops'] = '12.4 PFLOPS Active';
@@ L96 @@
- $lang['home_stat_value'] = 'Total Value · USC';
+ $lang['home_stat_value'] = 'Total Value · USD';
+ $lang['home_topology_failover'] = 'Multi-Region Failover Active';
+ $lang['home_topology_latency'] = 'Latency <24ms';
+ $lang['home_topology_sla'] = 'Global SLA: 99.99%';
+ $lang['home_topology_title'] = 'Global Network Topology';
```

`application/language/indonesian/app_lang.php`:
```diff
@@ L83 @@  home_hero_title … lalu sisip SEBELUM home_invite_code:
+$lang['home_hub_fra'] = 'Frankfurt · FRA-1';
+$lang['home_hub_jkt'] = 'Jakarta · JKT-2';
+$lang['home_hub_sin'] = 'Singapura · SIN-1';
+$lang['home_hub_tyo'] = 'Tokyo · TYO-1';
+$lang['home_hub_useast'] = 'Virginia Utara · US-EAST';
+$lang['home_hud_isolated'] = 'Hardware Terisolasi';
+$lang['home_hud_link'] = 'Direct Link';
+$lang['home_hud_pflops'] = '12.4 PFLOPS Aktif';
@@ L96 @@
- $lang['home_stat_value'] = 'Total Value · USC';
+ $lang['home_stat_value'] = 'Total Value · USD';
+ $lang['home_topology_failover'] = 'Failover Multi-Region Aktif';
+ $lang['home_topology_latency'] = 'Latensi <24ms';
+ $lang['home_topology_sla'] = 'SLA Global: 99.99%';
+ $lang['home_topology_title'] = 'Topologi Jaringan Global';
```

> Urutan sisip sudah terurut alfabetis (konvensi file). Kode hub & unit sengaja literal di kedua bahasa; hanya frasa bahasa yang diterjemahkan.
