# Plan 101 — Summary Eksekusi: World Node Map Refinement (Sharp Vector Continents & Expanded Mesh Topology)

> **Status:** EKSEKUSI SELESAI — blueprint `plan/101_WORLD_MAP_VECTOR_LANDMASS_AND_MESH_TOPOLOGY_PLAN.md` diterapkan pada 1 file view:
> 1. `application/views/home/index.php` (465 → 525 baris)
>
> Tidak ada file lain yang diubah oleh tugas ini (controller/model/JS/routes/dictionary tidak disentuh — zero new dictionary keys, zero external assets).

---

## 1. Execution Receipts (perintah yang dijalankan)

| # | Perintah / metode | Hasil |
|---|-------------------|-------|
| R1 | `php -l application/views/home/index.php` | `No syntax errors detected` |
| R2 | `grep -c "feGaussianBlur\|filter=url\|<ellipse\|hm99-cont\|hm99-arc\b\|hm99-dashflow\|hm99-glow"` | `0` (semua artefak blur/ellipse/arc lama hilang) |
| R3 | `grep -rln "hm99-arc\|hm99-cont\|hm99-blur\|hm99-dashflow" application/` | `none` (repo-wide) |
| R4 | `grep -o` count class baru | `hm99-land`=6, `hm99-isle`=14, `hm99-route`=5, `hm99-flow`=5, `hm99-bb`=8, `hm99-edge`=5, `hm99-edge-halo`=5 |
| R5 | `grep -c 'pathLength="1"'` (atribut pada `<path class="hm99-flow">`) | `5` |
| R6 | Hub circles per koordinat (cx,cy) | 524,111 ×2 · 285,142 ×2 · 888,151 ×2 · 788,246 ×2 · 797,267 ×2 (ring+core) — 5 hub utuh |
| R7 | Hub label overlay | `hm99-hub-label` ×5 (posisi % & varian `-jkt`/`-tyo` byte-identical) |
| R8 | Tag balance (python3) | `g` 8/8 · `path` 41 self-closed · `circle` 20 self-closed · `svg` 1/1 · `span` 28/28 · `div` 42/42; `<defs>` = 0 |
| R9 | Path smoothing generator | Ring kanonik Plan 101 → `d` Q-midpoint (satu komet per path, lihat §4) |
| R10 | `wc -l application/views/home/index.php` | 525 (sebelumnya 465) |

---

## 2. Line-Diff Breakdown (`application/views/home/index.php`)

### 2.1 CSS (region style scoped `hm99-*`)

| Region pra-edit | Aksi |
|-----------------|------|
| L107–111 `.hm99-grat` / `.hm99-grat-eq` | **Utuh** (graticule tetap) |
| L112–113 `.hm99-cont` (+dark) | **Dihapus** → `.hm99-land` / `.hm99-isle` |
| L114–121 `.hm99-arc`, `.hm99-arc-dash`, `@keyframes hm99-dashflow`, `@keyframes hm99-glow` | **Dihapus** → `.hm99-route`, `.hm99-flow` (+`html.dark`), `@keyframes hm99-flowp`, `.hm99-bb`, `.hm99-edge`, `.hm99-edge-halo`, `@keyframes hm99-edgep` |
| L122–132 `.hm99-ncore` / `.hm99-nring` / `hm99-node` | **Utuh** |
| L134–142 `.hm99-hub-label*` | **Utuh** |
| L144–148 `prefers-reduced-motion` | **Utuh** (blanket `.hm99-map *` otomatis mencakup kelas baru) |

Token parity (single-source, kelas berpasangan `html.dark`):
- **Land/isles:** light = fill `rgba(79,70,229,.055)` + stroke indigo `rgba(79,70,229,.30/.26)`, stroke-width 2 / 1.6, `linejoin/linecap round`; dark = fill cyan `rgba(56,189,248,.085)` + stroke `rgba(103,232,249,.42/.36)` + `drop-shadow` halus pada land.
- **Routes:** light `rgba(79,70,229,.20)` → dark `rgba(99,102,241,.38)`, width 1.3.
- **Flow (pulse):** light `#0891b2` → dark `#22d3ee` + glow `drop-shadow(0 0 4px …)`; `dasharray .04 .96`, `pathLength="1"`, animasi `stroke-dashoffset → −1` (`hm99-flowp`) — tepat satu komet per path per loop, seamless.
- **Backbone:** light `rgba(100,116,139,.38)` → dark `rgba(148,163,184,.32)`; `dasharray 1.6 5.4`, statis.
- **Edge:** core light `#0ea5e9` / dark `#22d3ee` + glow; halo stroke 1-unit; breathing opacity `hm99-edgep` 4 s alternate (tanpa scale — micro-dot stabil).

### 2.2 Markup SVG (region `.hm99-map-wrap`)

| Region pra-edit | Aksi |
|-----------------|------|
| L317–321 `<defs>` + `filter#hm99-blur` + `feGaussianBlur stdDeviation=16` | **Dihapus total** (nol filter SVG tersisa) |
| L323–328 graticule `<g>` | **Utuh** byte-identical |
| L330–339 blob `<g filter=url(#hm99-blur)>` + 7 `<ellipse>` | **Diganti** → `<g class="hm99-land-g">` (6 `<path class="hm99-land">`) + `<g class="hm99-isle-g">` (14 `<path class="hm99-isle">`) |
| L341–351 arcs `<g>` (4 base + 4 dash) | **Diganti** → `<g class="hm99-bb-g">` (8 backbone) + `<g class="hm99-route-g">` (5 route) + `<g class="hm99-flow-g">` (5 pulse `pathLength="1"`, timing inline) |
| (baru) sebelum hub group | **Disisipkan** `<g class="hm99-edge-g">` — 5 edge micro-node (halo r=5 + core r=2.5, breathing stagger 0/.6/1.2/1.8/2.4 s) |
| L353–365 hub `<g>` (ping ring + core) | **Utuh** byte-identical (stagger 0/.4/.8/1.2/1.6 s) |
| L368–373 hub-label HTML overlay | **Utuh** byte-identical |
| L376–380 footer chips | **Utuh** |
| header card + `role="img" aria-label` | **Utuh** |

---

## 3. Node & Route Verification

### 3.1 Primary hubs (5, dipertahankan) — koordinat & label overlay cocok dgn Plan 99
FRA-1 (524,111 | 52.4%,22.2%) · US-EAST (285,142 | 28.5%,28.4%) · TYO-1 (888,151 | 88.8%,30.2%) · SIN-1 (788,246 | 78.8%,49.2%) · JKT-2 (797,267 | 79.7%,53.4%).

### 3.2 Secondary edge nodes (5, baru, unlabeled) — dihitung dari lon/lat riil
| Edge | City | x,y | Delay breathe |
|------|------|-----|---------------|
| E1 | London | 500,107 | 0 s |
| E2 | São Paulo | 370,315 | .6 s |
| E3 | Dubai | 654,180 | 1.2 s |
| E4 | Mumbai | 702,197 | 1.8 s |
| E5 | Sydney | 920,344 | 2.4 s |

### 3.3 Primary active pulse arcs (5) — koridor loop global searah jarum jam
US-EAST→FRA (`Q405 55`, 2.8s) · FRA→SIN (`Q656 100`, 3.4s) · SIN→JKT (`Q793 221`, 2.2s, dasharray inline `.12 .88`) · JKT→TYO (`Q843 173`, 2.4s, reverse) · TYO→US-EAST (`Q587 17`, 4.2s, reverse). Delay stagger 0/.55/1.1/1.65/2.2 s. Kontrol P1 (405,55) & P5 (587,17) = geometri Plan 99 A2/A3 (reversed) — kontinuitas visual terjaga.

### 3.4 Secondary backbone transit links (8, static dashed)
US-EAST→London · London→FRA (lurus) · FRA→Dubai · Dubai→Mumbai · Mumbai→SIN · SIN→Sydney · TYO→Sydney · US-EAST→São Paulo. Cek geometri: B6 lewat utara ring Java (≈x821,y260 vs Java top ≈y268) — tanpa tabrakan visual.

---

## 4. Visual Inspection Notes (analisis statis/struktural; render browser = manual QA pass sebelum rilis)

### 4.1 Recognizability landmass
- 6 benua = ring kanonik ber-anchor pantai (proyeksi equirectangular Plan 99, ±0–2° stylization) → **Q-midpoint smoothing** (path melewati midpoint tiap segmen, verteks asli sebagai kontrol). Siluet: NA (Alaska/Hudson/Florida/Panama), SA (bulge NE + Patagonia), Europe (Scandinavia + boot Italia + Iberia), Africa (bulge W + Horn + Cape), Asia (Arabia/India/Indochina + **Semenanjung Melayu berujung tepat di SIN (788,246)**), Australia (Cape York + Bight).
- **Indonesia/ASEAN diartikulasi** sebagai 14 isle-path terpisah: Sumatra, Java, Borneo, Sulawesi, New Guinea + Jepang (Honshu, Hokkaido), Filipina (Luzon, Mindanao), Greenland, UK/IE, Islandia, Madagaskar — stroke lebih tipis (1.6) agar tetap crisp di skala kecil.
- Cek penempatan node: JKT di pantai utara Java (coastal city) ✓; TYO di dalam ring Honshu ✓; Sydney di verteks pantai SE Australia ✓; Dubai & Mumbai di daratan Asia ✓; São Paulo di daratan SA ✓.
- **QC note:** karena lingkungan eksekusi tanpa browser, *recognizability akhir wajib lolos visual QA manual* (dua tema × 360–480 px). Nudge verteks ≤5 unit diperbolehkan bila perlu — tanpa mengubah koordinat hub/edge/route.

### 4.2 Dark/Light parity
- Semua warna SVG via kelas CSS berpasangan (`html.dark .hm99-*`) — tidak ada atribut warna inline yang bisa divergen.
- Light: pearl `#ffffff` card, landmass indigo lembut + shoreline blueprint `rgba(79,70,229,.30)`, pulse sky `#0891b2`, node teal `#0891b2`/`#0ea5e9` — kontras teks & garis ≥ slate-600 pada putih (AA).
- Dark: obsidian `#0b1120`, landmass cyan `rgba(56,189,248,.085)` + kontur `rgba(103,232,249,.42)` + subtle drop-shadow, pulse cyan `#22d3ee` (rasio tinggi di obsidian).
- Filter `feGaussianBlur` dihapus → landmass statis ter-composite sekali (perf win nyata: tidak ada filter-region re-raster per frame animasi).

### 4.3 Animasi & reduced motion
- 60 FPS rule: hanya `stroke-dashoffset` (flow), `transform`+`opacity` (hub ping), `opacity` (edge breathe), `box-shadow` (SLA dot) — tidak ada animasi layout/fill.
- `prefers-reduced-motion: reduce` (blanket `.hm99-map *`) mematikan semua animasi; konten tetap terbaca: land/isles statis, route statis, tiap flow menyisakan satu dot komet statis (dasharray tetap terlihat), backbone dashed, edge glowing.

### 4.4 Responsivitas & alignment
- `.hm99-map-svg { width:100%; height:auto }` dipertahankan (rasio intrinsik 2:1, tanpa letterbox) → overlay label `%` tetap segaris dgn node di 360–480 px.
- Grup baru = geometri murni; tidak ada elemen baru di atas label layer; `pointer-events:none` pada label tidak berubah.

### 4.5 Deviasi kecil dari blueprint (tidak mengubah arsitektur)
1. Ring Asia berisi **43 verteks** (header blueprint menulis 44 — tabel kanonik §4.7 memang 43; implementasi mengikuti tabel).
2. `@keyframes hm99-flowp` memakai default 3 s di kelas + override `animation-duration` inline per path (2.2–4.2 s) — mekanisme timing inline dipertahankan (termasuk `stroke-dasharray:.12 .88` khusus P3).
3. Grup edge diberi nama `<g class="hm99-edge-g">` (kosmetik).
4. Komentar blok reduced-motion tidak diubah teksnya (blanket sudah mencakup kelas baru; tidak perlu CSS tambahan).

---

## 5. Ringkasan State

- File berubah oleh tugas ini: `application/views/home/index.php` (**M**, 465 → 525 baris) + dokumen `plan/101_WORLD_MAP_VECTOR_LANDMASS_AND_MESH_TOPOLOGY_PLAN.md` (blueprint, dibuat sebelumnya — tidak berubah) dan summary ini.
- Repo memiliki banyak perubahan kerja lain yang belum di-commit (hasil Plan 89–100) — **di luar** cakupan tugas ini dan tidak disentuh.
- Belum ada commit yang dibuat (menunggu instruksi).
- **Manual QA pass yang disarankan sebelum rilis** (matriks V9–V12 plan/101): render dark/light pada 360/390/414/480 px, emulasi `prefers-reduced-motion: reduce`, cek network tab (nol external image), dan inspeksi performa (tidak ada filter paint region).
