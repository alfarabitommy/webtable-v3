# Plan 101 — World Node Map Refinement: Sharp Vector Continents & Expanded Mesh Topology

> **Status:** BLUEPRINT — implementation NOT started. This task only produces this document (`plan/101_WORLD_MAP_VECTOR_LANDMASS_AND_MESH_TOPOLOGY_PLAN.md`); no view, dictionary, controller, model, or asset file is changed by it.
> **Bahasa dokumen:** English (sesuai brief). Format & disiplin mengikuti plan/97–99 (fakta → arsitektur → dataset → token → timing → langkah → matriks verifikasi → lampiran).
> **Lingkup eksekusi nanti:** ONLY `application/views/home/index.php` — satu file. No dictionary change (no new user-facing copy), no controller/model/JS change, no external asset (100% pure CSS + inline SVG).

---

## 1. Ringkasan & Tujuan

The World Node Map card on the member dashboard (`application/views/home/index.php`, built in Plan 99) is visually weak: the landmasses are 7 blurry ellipses (`<ellipse class="hm99-cont">` under `feGaussianBlur stdDeviation="16"`), and the topology is only 4 sparse arcs clustered on the FRA/SIN side. This plan refactors the map into a rich, authentic global network dashboard.

| # | Workstream | Kondisi sekarang (Plan 99) | Target (Plan 101) |
|---|-----------|----------------------------|-------------------|
| W1 | **Landmasses** | 7 blurry ellipses + `feGaussianBlur stdDeviation="16"` | Crisp, recognizable **vector silhouettes** of North America, South America, Europe, Africa, Asia, Australia + articulated **Southeast Asia / Indonesian archipelago** (and island groups: Greenland, UK/IE, Japan, Philippines, Madagascar…). `feGaussianBlur` removed completely. |
| W2 | **Mesh topology** | 4 sparse arcs clustered on one side | **5 primary active pulse arcs** forming a global clockwise corridor (US-EAST→FRA→SIN→JKT→TYO→US-EAST) + **8 secondary backbone links** (subtle static dashed lines) spanning all oceans. |
| W3 | **Node hierarchy** | 5 labeled hubs (SIN-1, JKT-2, TYO-1, US-EAST, FRA-1) | Keep 5 labeled hubs w/ ping rings **unchanged** + add **5 unlabeled glowing edge micro-nodes** (Sydney, London, Mumbai, São Paulo, Dubai) for southern/western hemisphere balance — zero text clutter, zero new dictionary keys. |
| W4 | **Theme parity & perf** | Blur filter forces filter-region re-raster on every arc-animation frame (expensive) | Pixel-identical parity via single-source classes; **no SVG filter at all** (static landmasses = composited once); animations restricted to `transform`/`stroke-dashoffset`/`opacity` (60 FPS rule). |

**Invariants (wajib, same as Plan 99):**
- 100% pure CSS + inline SVG; zero external map images / JS mapping libraries; zero new JS.
- `prefers-reduced-motion: reduce` honored (existing blanket rule already covers `.hm99-map *`).
- Responsive 360–480 px shell; label-alignment mechanism (HTML `%` overlay) **untouched**, so hub labels keep tracking nodes at every width.
- CSS custom code only sets color/bg/border/shadow/animation (house rule — radius/spacing stays Tailwind).
- All geometry stays **data-free decorative** (precedent: hardcoded stat cards; Plan 99 §9.1).
- Money/domain logic: untouched (pure view refactor).

---

## 2. Fakta Codebase (terverifikasi pra-edit)

File: `application/views/home/index.php` (465 lines).

| Region | L (pra-edit) | Isi | Aksi |
|--------|--------------|-----|------|
| Scoped `<style>` | 3–149 | Plan 99 CSS `hm99-*` | Partial replace (§8.1) |
| Map CSS block | 95–98 | `.hm99-map`, `.hm99-map-wrap`, `.hm99-map-svg` | Keep |
| Chip CSS | 99–105 | `.hm99-chip`, `.hm99-chip-live` | Keep (used by header + footer) |
| Graticule CSS | 107–111 | `.hm99-grat`, `.hm99-grat-eq` | Keep |
| **Blob CSS** | **112–113** | `.hm99-cont` fill token | **Remove** → `.hm99-land` / `.hm99-isle` |
| **Arc CSS + keyframes** | **114–121** | `.hm99-arc`, `.hm99-arc-dash`, `hm99-dashflow`, `hm99-glow` | **Replace** → `.hm99-route`, `.hm99-flow`, `.hm99-bb`, keyframes `hm99-flowp`, `hm99-edgep` |
| Node CSS | 122–132 | `.hm99-ncore`, `.hm99-nring`, `hm99-node` | Keep |
| Hub-label CSS | 134–142 | `.hm99-hub-label`, `-jkt`, `-tyo` | Keep |
| Reduced motion | 144–148 | blanket kill | Keep (comment updated) |
| Map card markup | 298–381 | header (301–311) + `.hm99-map-wrap` (314) + `<svg>` (315–366) + label overlay (368–373) + footer (376–380) | SVG interior replaced; header/labels/footer untouched |
| `<defs>` + blur filter | 317–321 | `filter#hm99-blur` `feGaussianBlur 16` | **Remove entire `<defs>`** |
| Graticule group | 323–328 | 3 paths `hm99-grat` | Keep byte-identical |
| Blob group | 330–339 | `<g filter="url(#hm99-blur)">` + 7 `<ellipse class="hm99-cont">` | **Remove** → landmass `<g>` groups (§4/§5) |
| Arcs group | 341–351 | 4× `.hm99-arc` + 4× `.hm99-arc-dash` (inline delay/direction) | **Replace** → 5 route + 5 flow + 8 backbone |
| Hubs group | 353–365 | 5× `.hm99-nring` + `.hm99-ncore` (inline delays) | Keep (stagger intact) |
| Edge nodes | — | — | **Insert** new `<g>` before hubs (paint order §6.1) |
| Label overlay spans | 368–373 | 5 `%`-positioned HTML chips | Keep byte-identical |
| Footer chips | 377–380 | failover + latency | Keep |

Dictionary (`home_topology_*`, `home_hub_*` in EN/ID): **no change** — edge nodes are unlabeled, no new copy anywhere.

---

## 3. Keputusan Desain & Divergensi (documented)

1. **Landmass = coast-anchored stylized rings rendered as smooth closed paths** (no fetched map data — zero external dependency rule). Vertices in §4 are hand-picked coastal landmarks expressed in the same equirectangular grid Plan 99 already verified (SIN→(788,246), FRA→(524,111), US-EAST→(285,142) all match the formula). Recognizability bar: silhouette identifiable at ≤448 px wide (card inner width); low-poly but unmistakable — **not** cartographic-grade coastline (documented stylization, same spirit as Plan 99 §2.5.3).
2. **Smoothing rule:** each ring table (§4/§5) is canonical; at execution each ring becomes a smooth closed path via the standard *midpoint-quadratic* pass: `M mid(P0,P1) Q P1 mid(P1,P2) Q P2 … Q Pn−1 mid(Pn−1,P0) Q P0 mid(P0,P1) Z` (deterministic arithmetic, machine-checkable). Rationale: straight `L` rings read angular at a 2:1 art-box scale; Q-smoothing yields the elegant, crisp, immediately-identifiable contour the brief asks for, while ring tables stay the single source of truth (no duplicated 400-char `d` strings in appendices — deliberate divergence from Plan 99's paste-ready appendix; automated assembly checks in §10).
3. **Islands get their own class** `.hm99-isle` (same fill family, thinner stroke) so archipelago micro-shapes stay crisp without dominating the canvas.
4. **Blur removal is a real perf fix:** animated content above/inside a `filter` region forces filter re-raster per frame; deleting `feGaussianBlur` + the `filter=` group makes landmasses static, composited-once layers.
5. **Interior seas not carved** (Caspian, Persian Gulf, Red Sea tip, Great Lakes, Baltic): at render scale (≤0.45 CSS factor) these are ≤2–4 px. Visual QC at execution may add notches only where a silhouette needs them (e.g., Black Sea / Adriatic), never the Caspian/Gulf.
6. Hub coordinates, overlay `%` positions, hub label text stay **byte-identical** (anti-regression: any shift desyncs HTML labels from SVG nodes).
7. Edge nodes are unlabeled micro-dots connected only through backbone topology; the `<svg>` keeps `role="img"` + `aria-label`, so no a11y/dictionary impact.

---

## 4. Landmass Coordinate Design (canonical rings)

### 4.1 Projection (unchanged, verified)

- `viewBox="0 0 1000 500"`, `preserveAspectRatio="xMidYMid meet"`, CSS keeps `width:100%; height:auto` (intrinsic 2:1 — no letterbox, so `%` overlays stay aligned with nodes).
- `x = 500 + lon·25/9` ; `y = 250 − lat·25/9` — equivalent to `x = (lon+180)/360·1000`, `y = (90−lat)/180·500`. Rounding: half-up.
- Reference anchors (Plan 99 §4.4, re-verified): SIN (103.85,1.35)→(788,246); JKT (106.85,−6.2)→(797,267); TYO (139.7,35.7)→(888,151); US-EAST (−77.5,39)→(285,142); FRA (8.68,50.1)→(524,111). Equator y=250, prime meridian x=500, date line x=1000.

### 4.2 Render template (canonical ring → smooth path)

Ring `P0…Pn−1` → smoothed closed path:

```
M (P0+P1)/2 Q P1 (P1+P2)/2 Q P2 (P2+P3)/2 … Q Pn−1 (Pn−1+P0)/2 Q P0 (P0+P1)/2 Z
```

→ `<path class="hm99-land" d="…"/>` (automated at execution; asserted in §10 V7).

### 4.3 North America — 18 vertices (clockwise: Alaska N → Hudson → Labrador → Florida → Yucatán → Panama → W coast → Alaska)

| # | x,y | # | x,y | # | x,y |
|---|---|---|---|---|---|
| 1 | 65,52 | 7 | 328,128 | 13 | 194,186 |
| 2 | 125,58 | 8 | 306,133 | 14 | 164,147 |
| 3 | 236,72 | 9 | 276,181 | 15 | 156,119 |
| 4 | 300,83 | 10 | 256,192 | 16 | 128,92 |
| 5 | 339,97 | 11 | 278,228 | 17 | 56,94 |
| 6 | 356,119 | 12 | 222,203 | 18 | 39,83 |

Contains US-EAST (285,142) ✓. Alaska + Hudson Bay + Florida/Yucatán/Panama taper + Baja/California coastline readable.

### 4.4 South America — 14 vertices (clockwise from Panama)

| # | x,y | # | x,y | # | x,y |
|---|---|---|---|---|---|
| 1 | 283,228 | 6 | 381,314 | 11 | 297,378 |
| 2 | 292,219 | 7 | 350,347 | 12 | 303,342 |
| 3 | 325,222 | 8 | 319,381 | 13 | 294,294 |
| 4 | 358,239 | 9 | 317,401 | 14 | 278,253 |
| 5 | 403,265 | 10 | 306,397 | — | — |

Contains São Paulo edge (370,315) on-land (interior, ~8 units from coast edge) ✓. NE bulge (403,265) + Patagonia taper + Chile coast.

### 4.5 Europe — 27 vertices (clockwise; incl. Scandinavia + European Russia to Ural cut at lon 60E; Italy as stylized boot)

| # | x,y | # | x,y | # | x,y |
|---|---|---|---|---|---|
| 1 | 474,129 | 10 | 597,67 | 19 | 561,148 |
| 2 | 493,118 | 11 | 667,58 | 20 | 551,139 |
| 3 | 496,112 | 12 | 667,110 | 21 | 544,144 |
| 4 | 512,104 | 13 | 636,119 | 22 | 521,129 |
| 5 | 523,91 | 14 | 606,121 | 23 | 513,130 |
| 6 | 529,86 | 15 | 594,125 | 24 | 506,135 |
| 7 | 515,77 | 16 | 578,129 | 25 | 499,140 |
| 8 | 538,60 | 17 | 577,134 | 26 | 485,150 |
| 9 | 573,52 | 18 | 565,138 | 27 | 474,147 |

Contains FRA (524,111) ✓. Scandinavia (5–10), Black-Sea N coast (13–17), Greece (18–19), boot (20–22), Iberia (23–27) recognizable.

### 4.6 Africa — 26 vertices (clockwise from Tangier)

| # | x,y | # | x,y | # | x,y |
|---|---|---|---|---|---|
| 1 | 485,151 | 10 | 525,251 | 19 | 643,218 |
| 2 | 474,157 | 11 | 533,267 | 20 | 622,216 |
| 3 | 464,168 | 12 | 533,283 | 21 | 607,196 |
| 4 | 451,207 | 13 | 540,317 | 22 | 590,168 |
| 5 | 457,214 | 14 | 553,346 | 23 | 583,163 |
| 6 | 471,233 | 15 | 578,339 | 24 | 553,161 |
| 7 | 501,235 | 16 | 599,301 | 25 | 528,147 |
| 8 | 518,238 | 17 | 610,278 | 26 | — |
| 9 | 526,240 | 18 | 611,258 | — | — |

W bulge (4), Gulf of Guinea (6–9), Cape (14), Mozambique/E (15–17), Horn (18–19), Red Sea / Suez / Med N (20–25), closes to (485,151).

### 4.7 Asia — 44 vertices (clockwise; shares the Ural cut with Europe: (667,58) → (667,110) → (636,119))

| # | x,y | # | x,y | # | x,y |
|---|---|---|---|---|---|
| 1 | 667,58 | 16 | 693,189 | 31 | 840,150 |
| 2 | 667,110 | 17 | 704,206 | 32 | 851,144 |
| 3 | 636,119 | 18 | 715,228 | 33 | 863,138 |
| 4 | 635,139 | 19 | 726,204 | 34 | 874,121 |
| 5 | 615,133 | 20 | 749,189 | 35 | 892,100 |
| 6 | 606,133 | 21 | 763,204 | 36 | 917,86 |
| 7 | 582,136 | 22 | 774,221 | 37 | 939,81 |
| 8 | 575,143 | 23 | 788,246 | 38 | 972,67 |
| 9 | 586,149 | 24 | 783,225 | 39 | 931,56 |
| 10 | 596,157 | 25 | 796,222 | 40 | 861,50 |
| 11 | 608,192 | 26 | 799,204 | 41 | 792,44 |
| 12 | 624,215 | 27 | 807,190 | 42 | 736,50 |
| 13 | 665,188 | 28 | 817,188 | 43 | 694,56 |
| 14 | 676,180 | 29 | 829,182 | 44 | — |
| 15 | 685,180 | 30 | 838,172 | — | — |

Anatolia/Aegean (5–10), Levant→Arabia→Hormuz (11–14), India subcontinent (16–19: Gujarat, W coast, Kanyakumari tip (715,228), Bay of Bengal), Indochina + **Malay peninsula ending exactly at the SIN anchor (788,246)** (20–24), China coast + Korea (25–34), Siberia to the Chukotka cut at lon 170E (35–38 — no date-line wrap, see §11), Arctic coast back to Ural (39–43). Interior contains Dubai (654,180) and Mumbai (702,197) ✓ (~0–4 units inland).

### 4.8 Australia — 11 vertices (clockwise)

| # | x,y | # | x,y | # | x,y |
|---|---|---|---|---|---|
| 1 | 816,311 | 5 | 926,326 | 9 | 865,339 |
| 2 | 864,283 | 6 | 919,344 | 10 | 819,339 |
| 3 | 896,280 | 7 | 903,356 | 11 | 815,321 |
| 4 | 904,297 | 8 | 885,347 | — | — |

Contains Sydney (920,344) on the SE-coast vertex ✓. Cape York (3), Bight (8–9), SW (10–11).

> **QC note:** rings are coast-anchored stylizations (±0–2° per landmark, half-up rounding). Execution includes a visual QC pass (§10 V11); minor single-vertex nudges (≤5 units) are allowed to improve recognizability **without** changing hub/edge/route coordinates.

---

## 5. Island / Archipelago Rings (`.hm99-isle`, 14 shapes)

Indonesia & regional islands must read clearly (W3 of the brief). All rings closed.

| Shape | Vertices (x,y) |
|-------|----------------|
| Greenland | (347,33) (450,22) (378,83) (358,72) (347,56) |
| Great Britain (UK) | (484,111) (501,109) (504,104) (499,101) (494,90) (485,89) (488,101) |
| Ireland | (472,107) (483,105) (478,99) (472,99) |
| Iceland | (436,68) (450,65) (463,70) (447,74) |
| Madagascar | (638,283) (639,299) (626,321) (622,311) |
| Honshu (Japan main) | (864,155) (878,153) (889,151) (892,149) (894,143) (892,135) (890,138) (885,147) (879,151) |
| Hokkaido | (890,134) (903,127) (898,130) (892,132) |
| Luzon (Philippines) | (834,199) (839,201) (838,211) (835,207) |
| Mindanao (Philippines) | (840,231) (850,231) (851,224) (844,224) |
| **Sumatra** | (765,235) (782,242) (794,266) (779,261) (771,244) |
| **Java** | (793,268) (800,268) (811,270) (818,273) (815,273) (807,271) (796,271) |
| **Borneo** | (803,249) (812,239) (822,234) (829,240) (826,250) (817,255) (807,252) |
| **Sulawesi** | (833,248) (840,252) (838,261) (832,263) (832,254) (836,249) |
| **New Guinea** | (867,253) (879,256) (892,258) (908,269) (901,274) (886,272) (872,261) |

Placement checks: JKT (797,267) sits on Java's north coast (Jakarta is coastal) ✓; TYO (888,151) inside the Honshu ring near its SE coast ✓; SIN (788,246) at the Malay-peninsula tip of the Asia ring, ~10 units NW of Sumatra's NE point — correct strait geometry ✓; Torres gap between New Guinea (y≥253) and Cape York (896,280) ≈ 27 units ✓.

---

## 6. Mesh Topology (nodes, routes, backbone)

### 6.1 Node dataset

**Primary hubs — 5, UNCHANGED from Plan 99 (coords, ping stagger, HTML label overlay byte-identical):**

| Hub | x,y | Label overlay | Ping delay |
|-----|-----|---------------|-----------|
| FRA-1 | 524,111 | 52.4%, 22.2% | 0 s |
| US-EAST | 285,142 | 28.5%, 28.4% | .4 s |
| TYO-1 | 888,151 | 88.8%, 30.2% | .8 s |
| SIN-1 | 788,246 | 78.8%, 49.2% | 1.2 s |
| JKT-2 | 797,267 | 79.7%, 53.4% | 1.6 s |

**Secondary edge nodes — 5 new, unlabeled, glowing micro-dots (`.hm99-edge`):**

| Edge | City | lon, lat | x,y | Justification |
|------|------|----------|-----|---------------|
| E1 | London | −0.13, 51.5 | 500,107 | Atlantic terminus (B1/B2) |
| E2 | São Paulo | −46.63, −23.55 | 370,315 | S. hemisphere / S. Atlantic balance |
| E3 | Dubai | 55.27, 25.2 | 654,180 | Middle-East relay (B3/B4) |
| E4 | Mumbai | 72.88, 19.08 | 702,197 | South-Asia relay (B4/B5) |
| E5 | Sydney | 151.21, −33.87 | 920,344 | Oceania / S. Pacific balance (B6/B7) |

### 6.2 Primary active pulse arcs — 5 (`route` base + `flow` traveling pulse each)

Corridor story: **global clockwise loop** US-EAST → FRA → SIN → JKT → TYO → US-EAST (Atlantic → Eurasia → SEA → Pacific), echoing Plan 99's cross-border workload balancing but as a full ring.

Control-point formula (Plan 99 §4.5, kept): `C = ((x1+x2)/2, (y1+y2)/2 − lift)`, `lift = clamp(0.30·|x2−x1|, 36, 130)` (SIN→JKT short hop is the exception: lift 30).

| Arc | From → To | Path `d` | ≈len | Flow dur | Delay | Dir |
|-----|-----------|----------|------|----------|-------|-----|
| P1 | US-EAST(285,142) → FRA(524,111) | `M285 142 Q405 55 524 111` | 250 | 2.8 s | 0 s | fwd |
| P2 | FRA(524,111) → SIN(788,246) | `M524 111 Q656 100 788 246` | 300 | 3.4 s | .55 s | fwd |
| P3 | SIN(788,246) → JKT(797,267) | `M788 246 Q793 221 797 267` | 55 | 2.2 s | 1.1 s | fwd |
| P4 | JKT(797,267) → TYO(888,151) | `M797 267 Q843 173 888 151` | 160 | 2.4 s | 1.65 s | rev |
| P5 | TYO(888,151) → US-EAST(285,142) | `M888 151 Q587 17 285 142` | 610 | 4.2 s | 2.2 s | rev |

(P1's control (405,55) and P5's control (587,17) coincide with Plan 99's A2/A3 geometry reversed — visually continuous with the shipped look.)

**Flow rendering (per arc, 2 paths):**
1. `path.hm99-route` — static translucent full route (1.3-unit stroke) with a soft glow token.
2. `path.hm99-flow` — bright traveling pulse. **`pathLength="1"` normalization** on every flow path + `stroke-dasharray: .04 .96` → exactly ONE comet per path per loop, seamless (dashoffset travels ±1.0 = one full period); inline `style="animation-delay:…;animation-direction:…"` (Plan 99 precedent). P3 (very short) overrides inline `stroke-dasharray: .12 .88` so its pulse stays visible (~5 px).

### 6.3 Secondary backbone transit links — 8 (`.hm99-bb`, static dashed)

"Enterprise fiber backbone" texture across all oceans; lift = `clamp(0.20·|dx|, 28, 110)` upward; near hops drawn straight.

| # | From → To | Path `d` | Role |
|---|-----------|----------|------|
| B1 | US-EAST → London | `M285 142 Q393 82 500 107` | N. Atlantic |
| B2 | London → FRA | `M500 107 L524 111` | EU metro hop |
| B3 | FRA → Dubai | `M524 111 Q589 118 654 180` | Europe → ME |
| B4 | Dubai → Mumbai | `M654 180 Q678 161 702 197` | ME → S. Asia |
| B5 | Mumbai → SIN | `M702 197 Q745 194 788 246` | S. Asia → SEA |
| B6 | SIN → Sydney | `M788 246 Q854 267 920 344` | SEA → Oceania |
| B7 | TYO → Sydney | `M888 151 Q904 220 920 344` | W. Pacific |
| B8 | US-EAST → São Paulo | `M285 142 Q328 201 370 315` | S. Atlantic |

Checks: B6 passes just N of the Java ring (near x821,y260 vs. Java top ~y268 — no collision) ✓; the label fan-out at US-EAST (P1/P5 + B1/B8) spreads NE/E/W/SW — chips render above with `pointer-events:none`, no overlap.

### 6.4 Edge-node visual (micro-dot, no label)

Per edge: `<circle class="hm99-edge-halo" cx="…" cy="…" r="5"/>` + `<circle class="hm99-edge" cx="…" cy="…" r="2.5"/>`. Soft opacity "breathing" (`.hm99-edgep`, 4 s alternate, stagger .6 s) — no scale animation (keeps micro-dots pixel-stable); glow via drop-shadow token. Reduced-motion → static glowing dot.

---

## 7. Theme Tokens & Timing (updated CSS)

### 7.1 Class inventory — new vs removed (single-source dark/light pairs)

| Class | Used by | Light | Dark |
|-------|---------|-------|------|
| `.hm99-land` | 6 continent paths | `fill: rgba(79,70,229,.055)`; `stroke: rgba(79,70,229,.30)` | `fill: rgba(56,189,248,.085)`; `stroke: rgba(103,232,249,.42)`; `filter: drop-shadow(0 0 5px rgba(34,211,238,.28))` |
| `.hm99-isle` | 14 island paths | same fill family; `stroke: rgba(79,70,229,.26)` | same dark fill; `stroke: rgba(103,232,249,.36)` |
| shared land/isles | — | `stroke-width: 2` (land) / `1.6` (isle); `stroke-linejoin: round`; `stroke-linecap: round` | same |
| `.hm99-route` | P1–P5 base | `stroke: rgba(79,70,229,.20)`; width 1.3 | `stroke: rgba(99,102,241,.38)`; width 1.3 |
| `.hm99-flow` | P1–P5 pulse | `stroke: #0891b2`; width 2.4; round cap; `dasharray: .04 .96` | `stroke: #22d3ee`; width 2.4; `drop-shadow(0 0 4px rgba(34,211,238,.75))` |
| `.hm99-bb` | B1–B8 | `stroke: rgba(100,116,139,.38)`; width 1; `dasharray: 1.6 5.4`; round cap | `stroke: rgba(148,163,184,.32)`; width 1 |
| `.hm99-edge` | 5 micro-dot cores | `fill: #0ea5e9`; `drop-shadow(0 0 3px rgba(14,165,233,.6))` | `fill: #22d3ee`; stronger glow |
| `.hm99-edge-halo` | 5 halos | `stroke: rgba(14,165,233,.40)`; width 1; fill none | `stroke: rgba(34,211,238,.35)` |

**Removed:** `.hm99-cont`, `.hm99-arc`, `.hm99-arc-dash`, keyframes `hm99-dashflow`/`hm99-glow`, `<defs>` + `filter#hm99-blur` + `feGaussianBlur`.
**Kept untouched:** `.hm99-map*`, `.hm99-chip*`, `.hm99-grat*`, `.hm99-ncore`, `.hm99-nring` + `hm99-node`, `.hm99-hub-label*`, reduced-motion blanket, all hero classes.

### 7.2 Keyframes & timing matrix

| Anim | Property (only) | Dur | Iter | Stagger |
|------|-----------------|-----|------|---------|
| `hm99-flowp` `{ to { stroke-dashoffset: -1; } }` | stroke-dashoffset | 2.2–4.2 s inline per arc | infinite | P1–P5: 0 / .55 / 1.1 / 1.65 / 2.2 s; dir fwd/fwd/fwd/rev/rev |
| `hm99-node` (hub ping, kept) | transform + opacity | 2.4 s | infinite | FRA 0 → JKT 1.6 s (unchanged) |
| `hm99-edgep` `{ from { opacity:.45 } to { opacity:1 } }` alternate | opacity | 4 s | infinite alternate | .6 s steps E1–E5 |
| `hm99-ping` (SLA/live dot, kept) | box-shadow | 2.0 s | infinite | — |

60 FPS rule honored: only `stroke-dashoffset`, `transform`, `opacity`, `box-shadow` animate; landmasses/blur-free static layers never repaint. `prefers-reduced-motion: reduce` blanket (`animation:none !important` on `.hm99-map *`) leaves: land/isles static, routes static, each flow path showing one static comet dot (dasharray still visible), backbones dashed, edges glowing — fully readable.

### 7.3 Paint order (bottom → top)

Graticule → `.hm99-land` (6) → `.hm99-isle` (14) → `.hm99-bb` (8) → `.hm99-route` (5) → `.hm99-flow` (5) → `.hm99-edge-halo`/`.hm99-edge` (5) → hub `.hm99-nring`/`.hm99-ncore` (5) → **HTML hub-label overlay** (outside SVG).

---

## 8. Exact View Diff Outline (`application/views/home/index.php` only)

### 8.1 Style block (inside existing scoped `<style>`, region L95–148)

1. **Keep** L95–111 (map/wrap/svg + chips + graticule tokens).
2. **Replace** L112–121 (`.hm99-cont` + `.hm99-arc`/`-dash` + keyframes `hm99-dashflow`/`hm99-glow`) with the new block: `.hm99-land`, `.hm99-isle`, `.hm99-route`, `.hm99-flow`, `.hm99-bb`, `.hm99-edge`, `.hm99-edge-halo` + `@keyframes hm99-flowp` + `@keyframes hm99-edgep`, plus dark-pair variants (token table §7.1).
3. **Keep** L122–142 (node core/ring + hub labels) and L144–148 (reduced motion — update the comment only; the blanket already covers the new classes).

### 8.2 Map SVG markup (region L315–366 inside `.hm99-map-wrap`)

1. **Delete** `<defs>…<filter id="hm99-blur">…</filter></defs>` (L317–321).
2. **Keep** graticule `<g>` (L323–328) byte-identical.
3. **Replace** blob `<g filter="url(#hm99-blur)">` + 7 `<ellipse>` (L330–339) with:
   - `<g class="hm99-land-g">` — 6 `<path class="hm99-land">` (rings §4.3–4.8, Q-smoothed per §4.2);
   - `<g class="hm99-isle-g">` — 14 `<path class="hm99-isle">` (rings §5).
4. **Replace** arcs `<g>` (L341–351) with, in paint order:
   - `<g class="hm99-bb-g" fill="none">` — 8 `<path class="hm99-bb">` (§6.3);
   - `<g class="hm99-route-g" fill="none">` — 5 `<path class="hm99-route">` (§6.2);
   - `<g class="hm99-flow-g" fill="none">` — 5 `<path class="hm99-flow" pathLength="1">` with inline `animation-delay`/`animation-direction` (P3 also inline dasharray) (§6.2).
5. **Insert** the edge-node `<g>` (5× halo + core circles, §6.4) immediately **before** the hub `<g>` (L353) so hub ping rings stay on top; keep hubs L353–365 byte-identical.
6. **Keep** HTML label overlay (L368–373), header (L301–311), footer (L376–380), and `role="img" aria-label` on the `<svg>` untouched.

### 8.3 Nothing else

No dictionary, controller, model, JS, routes, or other views touched. Net art-element delta ≈ −22 removed (7 ellipse + 8 arcs + defs/filter) + ~39 added (20 land/isles paths + 13 route/flow + 8 backbone + 10 edge circles) ≈ +17 SVG elements — negligible DOM cost.

---

## 9. Execution Steps (later phase, per roadmap)

1. Apply the CSS replacement §8.1; `php -l application/views/home/index.php`.
2. Generate smoothed path `d` from ring tables §4/§5 (deterministic midpoint-Q pass) and paste land/isles groups §8.2.3.
3. Add backbone/route/flow/edge markup §8.2.4–5; keep hubs/labels/header/footer untouched.
4. Run the verification matrix §10 (lint, greps, python assertions).
5. Visual QA dark/light × 360/390/414/480 px + reduced-motion emulation; nudge stylized ring vertices ≤5 units only where a silhouette reads poorly (no hub/edge/route coordinate changes).
6. Write `plan/101_WORLD_MAP_VECTOR_LANDMASS_AND_MESH_TOPOLOGY_SUMMARY.md` (receipts + diff breakdown) per repo convention.

---

## 10. Verification Matrix (execution-time)

| # | Check | Command / method | Pass |
|---|-------|------------------|------|
| V1 | PHP syntax | `php -l application/views/home/index.php` | `No syntax errors detected` |
| V2 | No blur / ellipse / old classes left | `grep -c "feGaussianBlur\|filter=url\|<ellipse\|hm99-cont\|hm99-arc"` (view) | `0` |
| V3 | New class presence / counts | `hm99-land` = 6 paths + `hm99-isle` = 14 paths; `hm99-route` = 5; `hm99-flow` = 5; `hm99-bb` = 8; `hm99-edge` = 5; `hm99-edge-halo` = 5 | counts exact |
| V4 | Flow normalization | `grep -c 'pathLength="1"'` | `5` |
| V5 | Hub geometry frozen | grep the 5 hub `cx` values (524 / 285 / 888 / 788 / 797) | at Plan 99 coords; overlay spans L368–373 byte-identical |
| V6 | Tag balance | div/span/svg/g/path/circle open = close; `<defs>` = 0 | pass |
| V7 | Ring data sanity (python3) | every (x,y) ∈ [0,1000] × [0,500]; rings closed; Q midpoints = averages of the ring table | all pass |
| V8 | No external requests | `grep -c "http\|placehold\|<img"` in the map region | `0` |
| V9 | Both themes, 360–480 px | Browser QA: landmasses crisp (no blur), hub labels aligned (SIN↔JKT, TYO edge), no overlap/truncation, no horizontal scroll | pass all widths |
| V10 | Reduced motion | DevTools emulation: animations off; static comet dots/edges visible; content readable | pass |
| V11 | Recognizability QC (manual) | NA/SA/EU/AF/AS/AU + Indonesia archipelago + Japan read correctly; edges visible in the S hemisphere; dark/light parity | pass |
| V12 | Perf | DevTools: no filter paint regions; compositor-only animation (transform/dashoffset/opacity); smooth on low-end device | pass |

---

## 11. Risiko & Catatan Implementasi

1. **Stylized ≠ cartographic.** Ring tables are deterministic, coast-anchored stylizations (±0–2°) authored in the projection Plan 99 verified; gross geographic relations (hub-in-landmass, straits, archipelago placement) are pre-checked in §4–6, but silhouettes must pass the human recognizability QA (V11). A future accuracy upgrade (Natural-Earth-derived paths) is a separate task, not this one.
2. **Interior seas uncarved** (Caspian / Persian Gulf / Red Sea tip): invisible at scale (≤2–4 px); acceptable per §3.5. Do not add during execution without re-running V7.
3. **Asia cut at lon 170E** avoids the date-line wrap (Chukotka's <2° sliver east of 170E is omitted) — keeps one clean polygon; do not "fix" by extending the ring to x=0.
4. **`pathLength="1"` flow pulses:** dasharray fractions are path-length-relative; the P3 short hop needs its inline `.12 .88` override (documented in the markup). Do not remove the inline styles (delay/direction/dasharray) — they are the timing system.
5. **Smoothing arithmetic** must derive from the ring tables (§4/§5) at execution — never hand-typed `d` from memory (single-source rule; V7 catches drift).
6. **No new dictionary keys / no copy.** If an implementer is tempted to label edge nodes, that is scope creep and would break the EN/ID symmetry invariant.
7. **Label alignment depends on `height:auto` SVG** (Plan 99 §4.5 deviation): keep `.hm99-map-svg { width:100%; height:auto }` — do not switch to a fixed height (letterbox would desync the `%` overlays).
8. The reduced-motion blanket already scopes `.hm99-map *` — the new classes are covered with zero extra CSS; only the block comment needs a touch-up.
