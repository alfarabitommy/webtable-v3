# Plan 47 — C2 Fix Summary: Rental Claim Button State & Claim Dispatch Resilience

Status: **SELESAI (executed & lint-clean)**
Based on: `plan/46_C2_RENTAL_UI_AND_CLAIM_DISPATCH_FIX_PLAN.md` (APPROVED)
Related: `plan/44` / `plan/45` (C2 ROI claim race), `docs/1_PRD.md` (aturan H+1/T+1 & akumulasi 2 hari)

---

## 1. Ringkasan Eksekusi

Dua isu C2 yang ditemukan saat verifikasi manual `/rentals` telah diperbaiki:

1. **Zero-claimable active state** — unit baru (H+1, `actual_claimable === 0`) tidak lagi merender tombol biru aktif "Klaim Rp 0"; kini tombol disabled abu-abu **"Belum Waktunya (H+1)"** tanpa `<form>`.
2. **Silent form dispatch** — jalur hening dihilangkan (server fail-closed + guard klien per-form yang tidak menyentuh POST native dan memberi umpan balik visual).

Tidak ada perubahan aturan bisnis (mesin klaim `claimable_info`/`claim_roi`, ledger ACID, rate limiter tetap utuh).

---

## 2. Perubahan per File

### 2.1 `application/views/rentals/index.php`

- **Mesin state tombol 4-cabang deterministik** (urutan prioritas sesuai blueprint):
  1. `$is_completed` → disabled `aria-disabled="true"` **"Kontrak Habis"** (ikon `fa-lock`)
  2. `$is_expired` → disabled **"Kontrak Berakhir"** (ikon `fa-lock`) — flag `$is_expired` baru ditambahkan dari `$rental->is_expired` (`claimable_info`)
  3. `$is_claimed_today` → disabled **"Sudah Diklaim"** (ikon `fa-check-circle`)
  4. `$actual_claimable < 1` → disabled abu-abu **"Belum Waktunya (H+1)"** dengan `bg-slate-800 text-slate-500 cursor-not-allowed opacity-60` (ikon `fa-clock`) — **tanpa merender `<form>`**
  5. `else` (`actual_claimable >= 1`) → `<form>` aktif ber-klass `claim-form` + tombol **"Klaim Rp {daily_roi × actual_claimable}"** (+ label `(2 Hari)` bila ≥ 2)
- **Anti double-click diganti** menjadi guard per-form:
  - `document.querySelectorAll('form.claim-form')` + listener `submit` per form.
  - Submit pertama: set `data-submitting="1"`, `btn.disabled = true`, tambah `opacity-60 cursor-not-allowed`, ganti konten tombol menjadi `<i class="fas fa-spinner fa-spin mr-1"></i> Memproses...` — **tanpa `preventDefault()`** → POST native + redirect + flashdata tetap utuh.
  - Submit kedua+ (`data-submitting === '1'`): `e.preventDefault()` — blokir double-submit.
  - Listener global lama (`document.addEventListener('submit', ... setTimeout(...)`) dihapus.
- **Typo `truncate\`** pada atribut class `<h3>` nama produk dibersihkan menjadi `truncate`.

### 2.2 `application/controllers/Rentals.php` — `claim($rental_id)`

- Guard metode eksplisit fail-closed:
  ```php
  if ($this->input->method() !== 'post') {
      show_404();
      return;
  }
  ```
  Menggantikan `if (!$this->input->post()) { redirect('rentals'); }` (redirect hening tanpa flashdata).
- Audit jalur lain: `!$rental_id` → flashdata error + redirect; rate-limit blocked → flashdata error + redirect (atau JSON untuk AJAX); mapping hasil `claim_roi` (`claimed` → success, selain itu → error) — **setiap early return & mapping selalu men-set flashdata sebelum redirect**.

### 2.3 `application/models/Rental_model.php` — pesan I4

- Pesan rejection H+1 (unit dibuat hari ini) diselaraskan dengan label tombol:
  - Lama: `"Klaim pertama baru dapat dilakukan keesokan harinya (H+1) setelah pembelian."`
  - Baru: `"Belum Waktunya (H+1): klaim pertama baru dapat dilakukan keesokan harinya setelah pembelian."`
- Logika bisnis I4, transaksi, dan pesan "sudah mengklaim hari ini" tidak diubah.

---

## 3. Verifikasi

| Check | Hasil |
|-------|-------|
| `php -l application/controllers/Rentals.php` | ✅ No syntax errors detected |
| `php -l application/models/Rental_model.php` | ✅ No syntax errors detected |
| `php -l application/views/rentals/index.php` | ✅ No syntax errors detected |
| Inspeksi ulang hasil edit (view: cabang tombol & JS guard) | ✅ Sesuai blueprint, struktur form/if-else utuh |

### Pemetaan Kriteria Penerimaan (plan/46 §4)

- **AC1** — Unit H+1 (`actual_claimable=0`): tombol disabled "Belum Waktunya (H+1)", tanpa `<form>` → terpenuhi di kode (cabang 4 merender `<button disabled>` saja).
- **AC2** — Klik unit eligible: submit pertama tidak di-`preventDefault`, POST native + redirect + flashdata → terpenuhi (guard JS & mapping controller).
- **AC3** — Double-click: submit kedua diblokir via `data-submitting` → terpenuhi (ditambah otoritas server row-lock).
- **AC4** — Tidak ada jalur hening: guard POST lama (satu-satunya jalur tanpa flashdata) diganti `show_404()` → terpenuhi.
- **AC5** — `php -l` bersih → terpenuhi (lihat tabel di atas).

---

## 4. Catatan / Sisa Verifikasi Runtime (di luar lingkup kode)

Verifikasi browser end-to-end (klik → 1 POST 302 → flash success → saldo naik) dan pengecekan access log Apache vhost **belum dijalankan di sesi ini** (keterbatasan akses read-only lingkungan dev: CLI MySQL & access log per-vhost tidak terjangkau). Disarankan langkah konfirmasi runtime lanjutan:

1. `curl` dengan cookie session + token CSRF: `POST /rentals/claim/{id}` → harap `302` + flashdata pada GET lanjutan.
2. Browser devtools Network: satu klik = satu POST; console bersih; visual "Memproses..." muncul.
3. Cek DOM: unit H+1 tidak memuat `<form action="...rentals/claim/...">`.

Perubahan lain di working tree (`git status`: Phase 10D env/secrets, Phase 32/33 theme, plan 44/45) tidak tersentuh oleh eksekusi ini.
