# Plan 46 — C2 Fix: Rental Claim Button State & Claim Dispatch Resilience

Status: **BLUEPRINT — menunggu approval. Belum ada perubahan kode aplikasi.**
Branch target: `main` (belum dibuat branch fase; ikuti kebiasaan repo bila perlu)
Related: `plan/44_C2_ROI_CLAIM_RACE_FIX_PLAN.md`, `plan/45_C2_ROI_CLAIM_RACE_FIX_SUMMARY.md`, `docs/1_PRD.md` (aturan T+1/H+1 & akumulasi 2 hari), `docs/3_ROADMAP.md`.

---

## 1. Ringkasan Masalah (hasil verifikasi manual C2)

| # | Gejala | Halaman/Endpoint |
|---|--------|------------------|
| 1 | Unit sewa **baru dibeli hari ini** (`actual_claimable === 0`, aturan H+1/T+1) tetap merender tombol biru **aktif & bisa diklik** bertuliskan `⚡ Klaim Rp 0`, bukan state disabled "Belum Waktunya". | `GET /rentals` — `application/views/rentals/index.php` |
| 2 | Klik tombol klaim pada unit **eligible** (mis. Unit #10: `Rp 1.040.000` untuk 2 hari) **tidak menghasilkan aksi/kredit yang terlihat** — tidak ada flash success/error, tidak ada perubahan saldo. | `POST /rentals/claim/{id}` — `application/controllers/Rentals.php` + `application/models/Rental_model.php` |

---

## 2. Bukti & Hasil Inspeksi (PLAN MODE — read-only)

### 2.1 View — `application/views/rentals/index.php`

- **Flash container ADA dan benar** (baris 98–107): merender `$this->session->flashdata('error')` dan `('success')` — bukan penyebab "tidak terlihat".
- **Rantai kondisi tombol (baris 188–203) hanya 3 cabang:**
  1. `$is_claimed_today` → disabled "Sudah Diklaim"
  2. `$is_completed` → disabled "Kontrak Habis"
  3. **else → form aktif + tombol biru** (baris 198–202)
- **TIDAK ADA cabang `actual_claimable < 1` / `claimable_days < 1`.** Unit dibeli hari ini: `day_diff = 0 → claimable_days = 0 → actual_claimable = 0`, `is_claimed_today=false`, `is_completed=false` → jatuh ke cabang *else* → tombol biru aktif dengan label `Klaim Rp 0` (`daily_roi * 0 = 0`, baris 200). **→ Akar masalah #1 terkonfirmasi di kode.**
- `git diff` terhadap kondisi pre-plan/44 membuktikan celah ini **sudah ada sebelum refactor C2** (cabang disabled lama juga hanya `is_claimed_today` / `days_processed >= total_days`); refactor `claimable_info` (plan/44) membuatnya konsisten tapi **tidak menutup** state H+1.

### 2.2 Anti double-click JS (baris 286–300, hasil Phase C2/plan/44)

```js
document.addEventListener('submit', function (e) {
    ...
    setTimeout(function () { btn.disabled = true; ... }, 0);
});
```

- Pemeriksaan perilaku browser modern (Chrome/FF/Safari/Edge): men-disable tombol **setelah event `submit` selesai (via `setTimeout 0`)** TIDAK membatalkan default action form — navigasi POST sudah diantrekan sebelum callback timer jalan. Pola ini aman dan **bukan** penyebab langsung dispatch gagal.
- Namun ini **satu-satunya kode klien yang menyentuh submit** dan tidak memberi umpan balik visual (tombol tetap biru penuh sampai reload), sehingga tidak bisa membuktikan ke pengguna bahwa klik "diterima". Jika ada kegagalan navigasi apa pun, klik berikutnya hening total (tombol sudah disabled oleh timer). → Wajib diganti pola yang eksplisit & memberi umpan balik (bagian 4.B).

### 2.3 Controller — `application/controllers/Rentals.php::claim()`

- Guard POST = `if (!$this->input->post()) { redirect('rentals'); }` (baris 96–98) — **redirect HENING tanpa flashdata** (satu-satunya jalur tanpa umpan balik). Form klaim hanya membawa field CSRF, jadi `post()` praktis selalu non-kosong; tetap: jalur ini harus diberi flashdata (fail-closed).
- Semua cabang lain (`claim_roi` mapping, baris 127–131) **selalu** `set_flashdata` + `redirect('rentals')` — aman.
- CSRF: `csrf_protection = TRUE`, `csrf_regenerate = FALSE` → token form `form_open()` valid lintas request; log menunjukkan "CSRF token verified" di setiap POST.

### 2.4 Model — `Rental_model::claimable_info()` & `claim_roi()`

- `claimable_info()` = single source of truth (T+1, kap 2 hari, sisa hari). Sudah benar.
- `claim_roi()` guard I4 `actual_claimable < 1` → pesan H+1 / "sudah mengklaim hari ini". **Server sudah aman** (row lock `FOR UPDATE`, `affected_rows()===1`, ledger ID deterministik). Tidak ada perubahan bisnis yang diperlukan — masalah #2 bukan di mesin klaim.

### 2.5 Log (env dev FlyEnv Apache, host `synapse.test`)

- `application/logs/log-2026-09-02.php`: rentang **17:53:20–17:55:01** berisi ~13 siklus `POST (CSRF verified)` → `GET` yang me-render `rentals/index.php`; **tidak ada** entri `ERROR`/`CRITICAL`. Artinya request klaim/checkout sampai ke controller dan dijawab redirect — layer HTTP/CSRF **bukan** titik gagal.
- Catatan keterbatasan lingkungan: akses log vhost Apache (`~/.config/FlyEnv/server/apache/common/logs/access_log`) tidak memuat entri `rentals/claim` (file lama/umum, bukan per-vhost), dan klien `mysql` CLI tidak tersedia + socket MySQL FlyEnv tidak ditemukan → **state DB Unit #10 & status kode tiap POST belum terkonfirmasi**; diverifikasi pada tahap implementasi (protokol §6).

### 2.6 Kesimpulan diagnosis

- **#1 (button state): ROOT CAUSE pasti di view** — cabang state `actual_claimable < 1` (H+1) hilang dari rantai kondisi; bukan masalah server.
- **#2 (dispatch hening): multi-layer, dua hipotesis tersisa** yang harus dipisahkan saat implementasi:
  - **H-A (server, flash tersembunyi/tidak terlihat):** klaim dieksekusi tapi hasilnya "gagal diam-diam" dari perspektif pengguna — mis. guard `!$this->input->post()` (redirect tanpa flash), atau pengamat tidak melihat flash karena pesan hanya muncul sekali (flashdata) dan ter-render di atas halaman yang sama.
  - **H-B (klien, klik tidak pernah jadi POST):** interaksi setelah kegagalan pertama membuat tombol disabled (timer interceptor) atau event submit tidak terpicu → tidak ada entri log baru sama sekali untuk klik klaim (log berakhir 17:55:01 setelah halaman rentals render).
  - Bukti log konsisten dengan H-B (tidak ada jejak POST klaim pasca-render terakhir), tapi belum menutup H-A. Protokol §6 memisahkan keduanya (curl + Network tab + log vhost).

---

## 3. Desain Perbaikan (Blueprint)

Prinsip: **(a)** state tombol ditentukan 100% server-side dari `claimable_info` (tanpa ketergantungan JS), **(b)** submit = POST native + redirect + flashdata dengan **jaminan umpan balik di setiap jalur** (server & klien), **(c)** anti double-click tidak boleh lagi bisa mengganggu default action.

### 3.A View `application/views/rentals/index.php` — mesin state tombol 4 cabang deterministik

Ganti rantai kondisi baris 188–203 menjadi (urutan prioritas):

```
if ($is_completed)                        → disabled "Kontrak Habis"          (ikon lock)
elseif ($is_expired)                      → disabled "Kontrak Berakhir"       (ikon lock)   [jika ada gap is_expired & !is_completed]
elseif ($is_claimed_today)                → disabled "Sudah Diklaim"           (ikon check)
elseif ($actual_claimable < 1)            → disabled "Belum Waktunya (H+1)"    (ikon clock, abu-abu)
else                                      → form aktif "Klaim Rp {amount}"     (ikon bolt)   [hanya actual_claimable >= 1]
```

- Cabang `actual_claimable < 1` **tidak merender `<form>` sama sekali** — mustahil submit. Styling disabled: `bg-slate-800 text-slate-500 cursor-not-allowed opacity-60` + `aria-disabled="true"` (kosmetik; otoritas tetap server).
- Cabang aktif: tambahkan `data-rental-id` dan label amount sudah memakai `daily_roi * actual_claimable` (tetap).
- Salinan pesan server I4 (H+1) di `Rental_model` diselaraskan dengan label tombol ("Belum Waktunya (H+1)") agar konsisten.
- Opsional bersih-bersih kecil (bukan bagian inti, aman): typo `truncate\` di atribut class `<h3>` baris 153.

### 3.B Anti double-click — ganti interceptor global dengan guard per-form berbasis flag (native POST dipertahankan)

- Hapus listener `submit` global (baris 286–300).
- Pasang listener per form klaim (atau delegasi dengan pengecekan `action`) yang **tidak pernah** menyentuh default action pada submit pertama:

```js
// Pseudocode desain:
claimForm.addEventListener('submit', function (e) {
    if (this.dataset.submitting === '1') {   // klik ke-2+ → blokir
        e.preventDefault();
        return;
    }
    this.dataset.submitting = '1';            // submit pertama: LANJUT native
    var btn = this.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.classList.add('opacity-60','cursor-not-allowed');
               btn.dataset.original = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...'; }
});
```

- Men-disable tombol **di dalam handler submit pertama** aman (aktivasi sudah terjadi; default action tetap berjalan) — berbeda dengan asumsi checklist lama; dokumentasikan di komentar kode.
- Umpan balik visual "Memproses..." = jaminan klik terlihat diproses; state baru datang dari reload setelah redirect.
- Tidak ada AJAX/fetch — CSRF, session, dan flashdata mengalir apa adanya lewat POST native (keputusan arsitektur: perubahan minimal, konsisten dengan seluruh app CI3).

### 3.C Controller `Rentals::claim()` — eliminasi semua jalur hening

- Ganti guard (baris 96–98) menjadi pemeriksaan metode + flashdata fail-closed:

```php
if ($this->input->method() !== 'post') {
    show_404(); // atau redirect('rentals') — bukan jalur dari form kita
}
```

- (Guard `$rental_id` kosong sudah ber-flashdata — biarkan.)
- Pastikan **setiap** early-return & setiap `code` hasil `claim_roi` memetakan flashdata (sudah; tambahkan `default:` generic error bila code tak dikenal — fail-closed).
- Tanpa perubahan bisnis di `Rental_model::claim_roi` / `claimable_info` (sudah benar & teruji plan/44/45).

### 3.D Tanpa perubahan arsitektur lain

- Ledger/row-lock/rate-limit dibiarkan (sudah menjadi otoritas anti double-payout). Rate limiter klaim (5/900 dtk) tetap — pesan lockout sudah ber-flashdata.

---

## 4. Kriteria Penerimaan

- **AC1 (#1):** Unit dibuat hari ini (`actual_claimable=0`, H+1) merender tombol disabled abu-abu "Belum Waktunya (H+1)" tanpa `<form>` — `curl`/DOM tidak menemukan form action `rentals/claim/{id}` untuk unit tsb.
- **AC2 (#2):** Klik klaim unit eligible → tepat 1 POST `302` → flash success tampil; saldo topbar naik sesuai `amount`; tombol berubah "Sudah Diklaim".
- **AC3:** Double-click cepat → hanya **satu** POST (dibuktikan di access log aplikasi + Network tab).
- **AC4:** Tidak ada jalur hening: setiap outcome klaim (sukses/H+1/sudah diklaim/rate-limit/error) selalu menghasilkan flashdata terlihat; state disabled selalu konsisten dengan `claimable_info`.
- **AC5:** `php -l` bersih untuk semua file PHP yang diubah; tidak ada perubahan logika bisnis/IDR.

---

## 5. Lingkup Perubahan (saat implementasi)

| File | Perubahan |
|------|-----------|
| `application/views/rentals/index.php` | Cabang state ke-4 (`actual_claimable < 1`), ganti interceptor JS, label konsisten, (opsional) typo `truncate\` |
| `application/controllers/Rentals.php` | Guard POST berbasis metode + fail-closed default mapping code |
| `application/models/Rental_model.php` | (Opsional) selaraskan copy pesan H+1 dengan label tombol — tanpa ubah logika |
| `plan/47_C2_..._SUMMARY.md` | Ringkasan setelah implementasi (mengikuti kebiasaan repo) |

Tidak diubah: ledger, transaksi ACID, rate limiter, routes, config.

---

## 6. Protokol Verifikasi (eksekusi saat implementasi)

1. **Siapkan state**: login user tester; pastikan ada (a) unit baru hari ini (`created_at` hari ini → H+1), (b) unit eligible (`actual_claimable >= 1`, mis. #10). Cek via query DB (PHP+mysqli, socket FlyEnv) — baca `user_rentals`, `wallet_ledger`, `rate_limits`.
2. **Server-side (memisahkan H-A vs H-B)**: `curl` dengan cookie session + token CSRF (ambil dari halaman): `POST /rentals/claim/{id}` → harap `302`; ikuti redirect → cek HTML berisi flash `success`/`error`; bandingkan `SELECT SUM` ledger & `days_processed` sebelum/sesudah.
3. **Client-side**: browser devtools Network — satu klik = satu `POST` 302; console bersih; visual "Memproses..." muncul; setelah redirect state tombol benar.
4. **Log**: grep `rentals/claim` di `application/logs/*` + akses log Apache vhost → konfirmasi tidak ada 403/500; pastikan tidak ada POST ganda per klik.
5. `php -l` semua file PHP termodifikasi.

---

## 7. Catatan / Risiko

- Diagnosis #2 belum 100% tunggal (H-A vs H-B) karena keterbatasan read-only lingkungan (DB socket & access log vhost tak terjangkau dari sesi ini). Desain §3 menutup **kedua** hipotesis: jalur hening server dihilangkan (3.C) dan interaksi klien dibuat eksplisit + berumpan balik (3.B), sehingga gejala "klik tanpa efek" tidak mungkin berulang apa pun lapisan penyebab aslinya.
- Semua copy UI Bahasa Indonesia; uang IDR; tanpa perubahan aturan bisnis PRD v5.0.
- Perubahan kecil kosmetik lain di file view (mis. baris 153) hanya bila diformalkan di PR yang sama.
