# Plan 98 — Summary: Eksekusi High-Tech Circuit Grid & Flowing Data Pulses (Auth Views)

> **Status:** EKSEKUSI SELESAI — 3 view auth diubah + file summary ini. Blueprint acuan:
> `plan/98_HIGH_TECH_ANIMATED_BACKGROUND_PLAN.md` (APPROVED). Eksekusi mengikuti §5
> blueprint dengan 4 penyimpangan kecil terdokumentasi (§3) — seluruhnya koreksi
> matematis/teknis, bukan perubahan desain.
>
> **File yang diubah:**
> - `application/views/auth/login.php` (canonical)
> - `application/views/auth/register.php`
> - `application/views/auth/change_password.php`
>
> **Non-ubah:** controller/model/skema DB/i18n/CAPTCHA/toggle/lang-switcher — NOL.

---

## 1. Ringkasan

Ambient background 6 orb Plan 97 (blur 85–120px, siklus 26–46s → tampak statis) pada
ketiga halaman auth diganti menjadi **high-tech circuit grid + flowing data pulses**:

- 5 **plasma core** blur 35–50px (token `--u-auth-orb-1..6` di-retune; inti pekat,
  fade 62–64%) dengan animasi transform-only 7–11s;
- satu **inline SVG** `viewBox="0 0 480 900"` (`preserveAspectRatio="xMidYMid slice"`):
  grid pattern 28px, signal ring dashed (spin 12s), 2 GPU dies + 24 pin, 7 trace statis
  45°/90°, 8 node ujung + 3 node core;
- 3 **bus data** (kiri cyan / kanan violet / bawah biru-teal), tiap bus = 4 path
  (`t-bus` dasar bertitik statis + `p-dots` aliran bit + `p-burst` kepala paket +
  `p-tail` ekor redup 40px di belakang kepala) yang dianimasikan `stroke-dashoffset`
  linear 6s/7s/8s → **160px/s seragam**, loop mulus;
- tema: dark kanvas `#050811` + cyan/violet vivid (`html.dark`),
  light kanvas `#f8fafc` + indigo halus (`:root`);
- `prefers-reduced-motion: reduce` diperluas → freeze plasma/pulse/node/ring;
  kartu `auth-card` glass, wordmark, form, CAPTCHA **tidak tersentuh**.

Semua animasi hanya memakai `transform`, `opacity`, `stroke-dashoffset`; filter
(`blur`, `drop-shadow`) statis. Zero aset eksternal baru (tetap hanya Tailwind Play CDN).

---

## 2. Receipt Implementasi per View

### 2.1 `login.php` — canonical (7 operasi patch)

| # | Region (anchor) | Operasi | Ukuran |
|---|---|---|---|
| T1 | token `:root` (light) | nilai `--u-auth-orb-1..6` diganti + `--u-auth-vignette` 0.55→0.45 | 6 baris nilai |
| T2 | token `html.dark` | `--u-auth-body-1` `#07090e`→`#050811`; `--u-auth-orb-1..6` diganti; `--u-auth-vignette` →`rgba(3,6,15,0.55)` | 6 baris nilai + 1 |
| T3 | token `:root` (light) | sisip 14 token circuit/pulse light (`--u-grid-line`, `--u-trace`, `--u-trace-dim`, `--u-trace-bus`, `--u-node`, `--u-node-core`, `--u-ring`, `--u-pulse-a/b/c`, `--u-pulse-glow-a/b/c`) | +14 baris |
| T4 | token `html.dark` | sisip 14 token circuit/pulse dark (cyan/violet/blue vivid) | +14 baris |
| C1 | CSS ambient lama (komentar `/* Ambient layer … */` s.d. keyframes `auth-energy`) | **diganti** blok CSS Plan 98: plasma, circuit SVG, node/ring, pulse, keyframes + rule `.auth-vignette` & `.auth-halo` dipertahankan di akhir blok | 44 → 92 baris |
| M1 | media `prefers-reduced-motion` 1 baris | diperluas (`.plasma, .pulse, .node, .auth-ring` + `.pulse { stroke-dashoffset: 0 }`) | 1 → 4 baris |
| H1 | markup ambient (komentar Plan 97 s.d. `</div>` wrapper) | **diganti** markup Plan 98: 5 div plasma + 1 SVG (pattern, ring, dies, pin, trace, node, 3 bus × 4 path) + vignette | 5 → 77 baris |

Total region token 45 → 73 baris; region CSS 44 → 92 baris; markup 5 → 77 baris.
Koreksi lanjutan (post-patch): unit `px` pada semua nilai `to` keyframes dash-flow
(`-960px`, `-1120px`, `-1280px`, dst.) + bus-3 Δ 1360→1280 (lihat §3).

### 2.2 `register.php` — sinkronisasi

Region identik di-copy dari `login.php` via skrip marker-based (assert 1-occurrence):
token region (45→73), CSS region (44→92), markup (5→77), media reduce (1→4).
**Perbaikan pasca-port:** script port sempat menjatuhkan baris
`<div class="auth-vignette absolute inset-0"></div>` dan menyisakan `</div>` ganda di
kedua sibling — diperbaiki manual agar byte-identik dengan `login.php`.
Form register (invite code, CAPTCHA, CSRF), tagline & judul **tidak diubah**.

### 2.3 `change_password.php` — sinkronisasi

Identik dengan §2.2. View ini tanpa skrip CAPTCHA — bagian tsb tidak tersentuh.
Form change-password, tagline & judul **tidak diubah**.

### 2.4 Bukti parity (region-level, ketiga file byte-identik)

```
parity register.php           token  : OK        parity change_password.php    token  : OK
parity register.php           css    : OK        parity change_password.php    css    : OK
parity register.php           markup : OK        parity change_password.php    markup : OK
parity register.php           motion : OK        parity change_password.php    motion : OK
```

---

## 3. Penyimpangan dari Blueprint (semua terdokumentasi)

1. **Unit `px` pada keyframes dashoffset (koreksi teknis).** Blueprint Lampiran A
   menulis `to { stroke-dashoffset: -960; }` (unitless). Properti CSS
   `stroke-dashoffset` hanya menerima `<length>`; nilai unitless akan dibuang browser.
   Dieksekusi: `-960px` / `-1120px` / `-1280px` (dan `to` tail `-920px`/`-1080px`/`-1240px`).
2. **bus-3 Δ = 1360 salah (koreksi matematis).** Aturan blueprint §3.4 mensyaratkan Δ
   kelipatan periode (20 & 160). Δ=1360 bukan kelipatan 160 (1360 = 160×8,5) → loop
   akan melompat tiap siklus. Dieksekusi: **bus-3 T = 8s, Δ = 1280** (160×8) — tetap
   160px/s seragam dengan bus-1/bus-2 dan masih dalam jendela 6–12s. Keyframes &
   durasi CSS bus-3 menyesuaikan (8s).
3. **Glow drop-shadow per bus.** Satu token `--u-pulse-glow` di blueprint dipecah
   menjadi `--u-pulse-glow-a/b/c` (cyan/violet/biru) agar warna glow mengikuti warna
   paket tiap bus; override `filter` per bus via `.bus-bN.p-burst`.
4. **Paint order plasma + node start bus-3 (penegasan/penyempurnaan).** Markup
   menempatkan plasma **di bawah** SVG (layer 1 §3.1 blueprint & frasa "blooms behind
   the circuits"); bloom "di atas" vektor diwakili drop-shadow node/pulse. Ditambahkan
   1 node biasa `(60,640)` sebagai titik awal bus-3 agar jalur tidak "menggantung".

Tidak ada perubahan terhadap arsitektur, token warna, geometri dataset Lampiran C,
atau spesifikasi timing selain butir 2.

---

## 4. Hasil Verifikasi

### 4.1 Lint PHP (`php -l`) — PASS ×3

```
No syntax errors detected in application/views/auth/login.php
No syntax errors detected in application/views/auth/register.php
No syntax errors detected in application/views/auth/change_password.php
```

### 4.2 Matematika keyframe — 160px/s kontinu, Δ kelipatan periode (automated check: VERIFY OK)

| Keyframes | dari→ke (px) | Δ | T | speed | Δ % 20 | Δ % 160 |
|---|---|---|---|---|---|---|
| `dash-flow-b1` | 0→−960 | 960 | 6.0s | 160.00px/s | ✓ | ✓ |
| `dash-flow-b2` | 0→−1120 | 1120 | 7.0s | 160.00px/s | ✓ | ✓ |
| `dash-flow-b3` | 0→−1280 | 1280 | 8.0s | 160.00px/s | ✓ | ✓ |
| `dash-flow-tail-b1` | 40→−920 | 960 | 6.0s | 160.00px/s | ✓ | ✓ |
| `dash-flow-tail-b2` | 40→−1080 | 1120 | 7.0s | 160.00px/s | ✓ | ✓ |
| `dash-flow-tail-b3` | 40→−1240 | 1280 | 8.0s | 160.00px/s | ✓ | ✓ |

Periode dasharray: `t-bus` 1+8=9; `p-dots` 2+18=20; `p-burst` 10+150=160;
`p-tail` 40+120=160 — seluruh Δ kelipatan periodenya (loop seamless). Tail selalu
tertinggal tepat 40px di belakang kepala (base offset `from: 40px`).

### 4.3 Zero aset eksternal baru

```
assets login.php:  http refs=2 (tailwind=1, other=0)   # other=0 (xmlns w3.org bukan request)
assets register.php: http refs=2 (tailwind=1, other=0)
assets change_password.php: http refs=2 (tailwind=1, other=0)
```
Satu-satunya request eksternal tetap `https://cdn.tailwindcss.com` (sudah ada sejak
sebelum Plan 98). Tidak ada `<img>`/placehold/font/library baru.

### 4.4 Konsistensi CSS/SVG

- Semua nama `animation:` memiliki `@keyframes` (tidak ada orphan); `none` hanya dipakai
  media reduce.
- Tidak ada sisa kelas lama: `orb orb-*`, `auth-drift-*`, `auth-energy`, blur 85–120px → 0.
- Kelas markup (plasma-1..5, auth-ring, die--frame/core, pin, t-bus, p-dots/p-burst/p-tail,
  bus-b1/b2/b3, node--core, auth-circuit, auth-vignette) seluruhnya terpasang 1×.

---

## 5. Catatan Inspeksi Visual (analisis statis; QA browser manual menyusul)

Analisis berikut diturunkan dari CSS/markup final (belum ada render browser di round ini
— lihat daftar QA manual di bawah):

- **Pulse flow:** tiap bus menampilkan aliran titik (`2 18`) kontinu + "comet"
  (`10 150` kepala terang ber-glow drop-shadow + `40 120` ekor opacity .28 di belakang
  kepala) yang bergerak searah path dari die A/B menuju node core tujuan dan "tiba"
  di ujung; antar-bus ter-desinkronisasi (delay 0 / −1.4s / −3s) sehingga lalu lintas
  tidak serempak. Kecepatan seragam 160px/s menjamin gerakan langsung terlihat saat
  landing tanpa tampak kacau.
- **Plasma contrast:** blur 35–50px + radial fade pada 62–64% + alfa inti 0.45–0.62
  (dark) → bloom punya inti dan tepi terbaca, bukan "asap" 85–120px. Di dark, plasma
  memakai `mix-blend-mode: screen` (menambah luminansi ke kanvas obsidian); di light
  `normal` dengan alfa ≤ 0.35 (lavender/soft).
- **Dark/light parity:** kedua tema berbagi satu blok CSS; perbedaan hanya token —
  dark: `#050811`, `--u-trace rgba(34,211,238,.30)`, pulse `#22d3ee/#a78bfa/#60a5fa`;
  light: `#f8fafc`, `--u-trace rgba(79,70,229,.16)`, pulse `#06b6d4/#4f46e5/#0d9488`.
  Pindah tema (toggle Sun/Moon, persist `localStorage['user_theme']`) langsung
  merelayout warna tanpa FOUC (anti-FOUC head tidak diubah).
- **Kartu form:** `auth-card` tetap glass (backdrop-blur 16px) di `z-20`; trace/pulse di
  bawahnya ter-frost — depth, bukan noise. Vignette di-retune (0.45/0.55) agar vektor
  tepi tetap terbaca.

### QA manual yang masih perlu dijalankan (matriks V6–V16 blueprint §6)

1. Buka `/login`, `/register`, `/auth/change-password` (sesi login) di dark & light,
   ≥2 ukuran layar (390×844, 480×900): V6 packet flow terlihat, V7 plasma tamed,
   V9 trace tajam di zona wordmark, V10 parity, V11 kontras form & fokus input.
2. DevTools: emulate `prefers-reduced-motion: reduce` (V12) & Performance record 60 FPS
   dengan properti animasi ⊆ {transform, opacity, stroke-dashoffset} (V13).
3. Network tab: pastikan hanya Tailwind CDN (V5); keyboard tab/aria (V14);
   refresh CAPTCHA + submit form (V15).
   Catatan lingkungan: `base_url` mengarah `http://synapse.test/` — jalankan lewat host
   rewrite (`.htaccess`/nginx) atau `php -S` dengan override `base_url`.

---

## 6. Status Akhir

| Item | Status |
|---|---|
| 3 view auth dipatch | ✅ byte-identical untuk seluruh region Plan 98 |
| `php -l` ×3 | ✅ 0 error |
| Parity region 4×2 | ✅ OK |
| Matematika 160px/s & periode dasharray | ✅ VERIFY OK |
| Zero aset eksternal baru | ✅ |
| QA visual browser (V6–V16) | ⏳ manual, menunggu lingkungan host |
| Commit | belum — menunggu instruksi pemilik (style: Bahasa Indonesia) |
