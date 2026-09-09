# Plan 99 — Summary Eksekusi: Home Hero AI Card, World Node Map & Fix Label Mata Uang (USC → USD)

> **Status:** EKSEKUSI SELESAI — blueprint `plan/99_HOME_HERO_AND_TOPOLOGY_MAP_PLAN.md` diterapkan penuh pada 3 file:
> 1. `application/language/english/app_lang.php`
> 2. `application/language/indonesian/app_lang.php`
> 3. `application/views/home/index.php`
>
> Tidak ada file lain yang diubah oleh tugas ini (controller/model/JS/routes tidak disentuh).

---

## 1. Execution Receipts (perintah yang dijalankan)

| # | Perintah | Hasil |
|---|----------|-------|
| R1 | `php -l application/language/english/app_lang.php` | `No syntax errors detected` |
| R2 | `php -l application/language/indonesian/app_lang.php` | `No syntax errors detected` |
| R3 | `php -l application/views/home/index.php` | `No syntax errors detected` |
| R4 | `grep -c "USC"` (kedua kamus) | `0` / `0` |
| R5 | `grep -n "Total Value · USD"` (kedua kamus) | L104 EN + L104 ID |
| R6 | `grep -c "placehold.co" application/views/home/index.php` | `0` |
| R7 | Assertion suite (python3) | 7/7 **PASS** (lihat §3) |

---

## 2. Line-Diff Breakdown per File

### 2.1 `application/language/english/app_lang.php` (untracked baseline → +12 baris, total 348)

| Region | Sebelum | Sesudah |
|--------|---------|---------|
| Slot hub/hud (antara `home_hero_title` L83 & `home_invite_code`) | — | L84–91: `home_hub_fra`, `home_hub_jkt`, `home_hub_sin`, `home_hub_tyo`, `home_hub_useast`, `home_hud_isolated`, `home_hud_link`, `home_hud_pflops` |
| `home_invite_code` | L84 | L92 (geser +8) |
| `home_stat_value` | L96 `'Total Value · USC'` | **L104 `'Total Value · USD'`** |
| Slot topology (antara `home_stat_value` & `home_warn_body`) | — | L105–108: `home_topology_failover`, `home_topology_latency`, `home_topology_sla`, `home_topology_title` |
| `home_warn_body` | L97 | L109 (geser +12) |

Nilai EN persis mengikuti copy spec Plan 99 (mis. `'Singapore · SIN-1'`, `'12.4 PFLOPS Active'`, `'Global SLA: 99.99%'`, `'Global Network Topology'`).

### 2.2 `application/language/indonesian/app_lang.php` (untracked baseline → +12 baris, total 348)

Layout baris **identik** dengan EN (L84–91 hub/hud; L104 `'Total Value · USD'`; L105–108 topology; L109 warn). Nilai ID: `'Singapura · SIN-1'`, `'Virginia Utara · US-EAST'`, `'12.4 PFLOPS Aktif'`, `'Failover Multi-Region Aktif'`, `'Latensi <24ms'`, `'SLA Global: 99.99%'`, `'Topologi Jaringan Global'`, dst. Kode hub (`SIN-1`…) & unit literal di kedua bahasa (konvensi Plan 94).

### 2.3 `application/views/home/index.php` (tracked, M) — 228 → 465 baris

| Region lama | Baris (pra-edit) | Pengganti |
|-------------|------------------|-----------|
| Hero placeholder (`placehold.co` img + overlay gradien) | L3–20 | **AI Neural Cluster Hero Card** — `<style>` scoped `hm99-*` (~L4–200) disisipkan sebelum hero + markup hero baru |
| Topology placeholder (`placehold.co` img di box `h-32`) | L142–144 | **World Node Map Card** — header (judul + SLA chip) → SVG kanvas + 5 hub-label overlay → footer chips (failover, latency) |

Rincian markup baru:
- **Hero:** `.hm99-hero` (obsidian `#0b1120` dark / pearl `#ffffff–#f8fafc` light, `min-height:224px`) → orb glow ×2 (LAYER 2) → neural dot-lattice `::before` + sheen `::after` → konten: status row (`hm99-statusdot` pulsing + `lang('home_online')` pill kiri, `lang('home_engine_line')` mono kanan-atas) → headline `.hm99-grad-text` (gradient text, cyan→indigo dark / deep-indigo light) → 3 HUD pill (`fa-bolt` PFLOPS, `fa-lock` Isolated, `fa-globe` Direct Link).
- **Map:** `.hm99-map` (surface `var(--u-surface)`, border `var(--u-border)`) → header judul `home_topology_title` + chip SLA → `.hm99-map-wrap` berisi `<svg viewBox="0 0 1000 500">` (graticule 5V+3H → 7 continent ellipse + `feGaussianBlur` → 4 arc base + 4 arc-dash animasi → 5 node core+ping ring) + 5 `<span class="hm99-hub-label">` overlay (posisi `%` dari dataset Plan 99 §4.4) → footer 2 chip status.
- **Penghapusan eksternal image:** kedua `placehold.co` (hero + topology) hilang → dashboard member hanya menyisakan logo header template (di luar scope Plan 99).

---

## 3. Hasil Verifikasi

### 3.1 Sintaks (R1–R3)
`php -l` → **zero syntax errors** pada ketiga file.

### 3.2 Simetri & kebocoran key (R7 — 7/7 PASS)

| Check | Hasil |
|-------|-------|
| Tidak ada `USC` di kedua kamus | PASS |
| Label `'Total Value · USD'` ada di EN & ID | PASS |
| Set key EN ≡ ID (329 key masing-masing, terurut alfabetis) | PASS |
| Nol `placehold.co` di home view | PASS |
| Tidak ada raw dict-key bocor ke view (di luar `lang()`/`SYNAPSE_I18N`) | PASS |
| 12 key baru Plan 99 dipakai view | PASS |
| Keseimbangan tag (`div`/`span`/`g`/`svg` open=close) | PASS |

Catatan kebocoran: dua token yang terdeteksi awalnya (`js_copied`, `js_copy_failed`) adalah lookup **peta JS `SYNAPSE_I18N` yang sudah ada** pada script copy-invite (bukan string render mentah) — keduanya punya key di kamus.

### 3.3 Struktur akhir view (angka terverifikasi)

- `div` 42/42, `span` 28/28, `style` 1/1, `svg` 1/1, `g` 4/4 (graticule, blobs, arcs, hubs), `defs` 1/1, `filter` 1/1.
- `hm99-arc` base ×4, `hm99-arc-dash` ×4, `hm99-nring`/`hm99-ncore` ×5 hub, hub-label ×5.

---

## 4. Visual Inspection Notes

> Catatan: inspeksi visual **statis/struktural** (lingkungan eksekusi tanpa browser/headless-render). Verifikasi visual runtime (matriks V6–V10 plan/99 §8 — dua tema × 360–480 px, emulasi `prefers-reduced-motion`, network tab) tetap menjadi **manual QA pass** sebelum rilis. Di bawah ini analisis berbasis token & geometri terverifikasi.

### 4.1 Hero — kontras (light & dark)

| Elemen | Light | Dark |
|--------|-------|------|
| Kanvas | `#ffffff → #f8fafc` | `#0b1120 → #0d1526` |
| Title (24px extrabold → "large text" WCAG ≥3:1) | `#312e81→#4f46e5→#0e7490` (rasio ≈5.9–8:1 — **AA pass**) | `#22d3ee→#60a5fa→#818cf8` di `#0b1120` (rasio ≈7–10:1 — **AA pass**) |
| Subtitle/pill text | `#334155`/`#475569` di pearl (≈8–10:1) | `#cbd5e1`/`#94a3b8` di obsidian |
| Status pill | emerald-600 di tint emerald (≈4.9:1) | emerald-400 (≈10:1) |

`@supports` fallback: bila `background-clip:text` tidak didukung, title jatuh ke `color: var(--u-text)` — tidak pernah invisible.

### 4.2 World Node Map — layering & parity

Paint order SVG (bottom → top): graticule (`hm99-grat`) → continent blobs (`hm99-cont` + blur) → arcs base (`hm99-arc`, glow pulse 6s) → arc dash (`hm99-arc-dash`, dashflow 2.6s, stagger 0/.65/1.3/1.95s, arah reverse pada A2/A4) → node ping ring (`hm99-nring`, 2.4s, stagger 0/.4/.8/1.2/1.6s) → node core (`hm99-ncore`) → **HTML hub-label overlay** (di luar SVG, z-above).

Theme parity dijamin satu sumber: seluruh `stroke`/`fill` SVG lewat kelas CSS dengan pasangan `html.dark .hm99-*` — tidak ada atribut warna inline yang bisa divergen (light: slate/indigo arcs, teal `#0891b2` nodes; dark: cyan `#22d3ee` arcs & nodes, indigo base).

### 4.3 Responsivitas (360–480 px) — pendekatan

- `.hm99-map-svg { display:block; width:100%; height:auto }` → tinggi = lebar/2 (rasio viewBox 2:1) **tanpa letterbox**, sehingga overlay label persentase (`x/10%`, `y/20%`) selalu segaris dengan node di semua lebar (art-box tidak pernah dicrop).
- Ketinggian aktual: ≈164 px (360 viewport) s.d. ≈224 px (480 viewport) — sejalan hero.
- Hero: header `flex-wrap`, pills `flex-wrap` (2 baris rapi bila sempit), headline `mt-auto`.
- Label hub: `white-space:nowrap` + variant `.hm99-hub-jkt` (di bawah node) & `.hm99-hub-tyo` (anchor kiri) untuk menghindari tabrakan SIN↔JKT dan tepi kanan art-box.

### 4.4 Reduced motion
`@media (prefers-reduced-motion: reduce)` mematikan seluruh animasi/transisi (`animation: none !important`) di hero & map; dot status jatuh ke ring statis; konten tetap terbaca.

### 4.5 Deviasi kecil dari blueprint (terdokumentasi, tidak mengubah arsitektur)

1. **Sizing SVG map:** Lampiran A menulis `.hm99-map-svg { height:165px }` → dieksekusi sebagai `height:auto` + `width:100%` (rasio intrinsik 2:1). Alasan: tinggi tetap + `meet` menimbulkan letterbox horizontal yang **menggeser overlay label %** dari posisi node; `height:auto` menghilangkan letterbox dan menjaga alignment label di 360–480 px (keputusan sudah diantisipasi Plan 99 §4.2).
2. **Hero engine line:** Lampiran B menampilkan `home_engine_line` dua kali (kanan-atas + subtitle) dengan breakpoint `xs:`/`md:` — `xs` bukan breakpoint Tailwind default dan `md` tidak pernah aktif di shell `max-w-[480px]`. Dieksekusi sekali saja: mono uppercase di kanan status row; subtitle lama dihapus (tidak ada duplikasi, semua copy tetap tampil).
3. `u-card` tidak dipakai ganda di map card (`.hm99-map` sudah membawa surface/border — satu sumber, mencegah konflik border).
4. Glow arc digabung ke `.hm99-arc` (kelas terpisah `hm99-arcglow` dari Lampiran A tidak dipakai).

---

## 5. Ringkasan State

- File berubah oleh tugas ini: `application/views/home/index.php` (**M**), `application/language/english/app_lang.php` (**untracked baseline**, +12 baris), `application/language/indonesian/app_lang.php` (**untracked baseline**, +12 baris).
- Dokumen: `plan/99_HOME_HERO_AND_TOPOLOGY_MAP_PLAN.md` (blueprint, dibuat sebelumnya) — tidak berubah; summary ini = `plan/99_HOME_HERO_AND_TOPOLOGY_MAP_SUMMARY.md`.
- Repo memiliki banyak perubahan kerja lain yang belum di-commit (hasil Plan 89–98) — **di luar** cakupan tugas ini dan tidak disentuh.
- Belum ada commit yang dibuat (menunggu instruksi).
- **Manual QA pass yang disarankan sebelum rilis** (matriks V6–V10 plan/99): render dark/light pada 360/390/414/480 px, emulasi `prefers-reduced-motion: reduce`, dan cek network tab (nol request `placehold.co` dari dashboard; hanya logo header template yang tersisa).
