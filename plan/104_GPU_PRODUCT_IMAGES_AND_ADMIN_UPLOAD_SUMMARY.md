# Plan 104 — SUMMARY: GPU Product Real Image Support & Admin Upload Integration

**Status:** ✅ **SELESAI & TERVERIFIKASI** (Phase A–I dari `plan/104_GPU_PRODUCT_IMAGES_AND_ADMIN_UPLOAD_PLAN.md` §11).
**Plan:** `plan/104_GPU_PRODUCT_IMAGES_AND_ADMIN_UPLOAD_PLAN.md` (616 baris).
**Tanggal eksekusi:** 2026-09-12 (WIB).
**DB live:** `db_webtable` — migrasi **sudah diterapkan** (`--apply`, 8/8 kanonik terisi).

---

## 0. Ringkasan Satu Paragraf

Marketplace berhenti memakai `placehold.co` dan kini merender **8 gambar produk nyata 16:9** (RTX 3060 Starter s.d. H200 Sovereign) dari `uploads/products/`. Kolom baru `gpu_products.image` menyimpan **basename** berkas; resolver tunggal `product_image_helper.php` memutuskan gambar vs fallback banner gelap ber-ikonografi chip GPU (tanpa teks, dwibahasa murni). Admin dapat **unggah / ganti / hapus** gambar produk lewat modal yang sudah multipart, dengan allowlist `jpg|jpeg|png|webp`, batas 2048 KB, true-MIME, nama acak, pesan galat **100% Indonesia**, dan lifecycle berkas anti-orphan + guard anti-hapus-berkas-bersama. Semua gate verifikasi §12 plan **LULUS**.

---

## 1. Diff Breakdown

### 1.1 File baru

| File | Baris | Isi |
|---|---|---|
| `application/helpers/product_image_helper.php` | **187** | `product_image_dir/extensions/filename/path/exists/url` — choke-point sanitasi + kontrak fallback tunggal `null`; semua fungsi `function_exists()`-guarded |
| `scripts/migrate_104_gpu_product_images.php` | **428** | CLI `--dry-run`/`--apply`/`--verify`/`--keep-filenames`, exit 0/1/2, idempoten, name-keyed |
| `uploads/products/index.html` | 1 | Placeholder 403 (pola `uploads/qris/index.html`), di-track |
| `plan/104_..._PLAN.md` | 616 | Blueprint (Phase A) |

### 1.2 File termodifikasi (hanya hunk plan/104)

| File | Δ | Ringkas |
|---|---|---|
| `application/controllers/Admin.php` | **+156 −1** | `_handle_product_image_upload()`, `_discard_uploaded_product_image()`, `_delete_replaced_product_image()`, integrasi create/update, `image` di payload audit |
| `application/views/admin/products/index.php` | **+116 −2** | kolom **Gambar** + `colspan` 8→9, `form_open_multipart()`, blok "Gambar Produk", pratinjau `URL.createObjectURL`, checkbox `remove_image`, `image_url` di `data-product` |
| `database.sql` | **+83 −0** | kolom `image` di `CREATE TABLE` + blok "Plan 104 — MIGRASI LIVE" (ALTER, backfill name-keyed, verifikasi) |
| `application/models/Admin_model.php` | **+38 −1** | whitelist `image` (disanitasi via helper) + `is_product_image_referenced()` |
| `application/views/marketplace/index.php` | **+29 −1** | penghapusan `placehold.co` → kontainer 16:9 + fallback SVG chip |
| `database_seed.sql` | **+11 −5** | kolom `image` (`NULL`) di `SECTION: gpu_products` + catatan migrasi |
| `.gitignore` | **+10 −0** | `uploads/products/*` + `!uploads/products/index.html` |
| `application/config/mimes.php` | **+4 −0** | **`'webp' => array('image/webp')`** (prasyarat mutlak) |
| `application/config/autoload.php` | **+4 −1** | helper `'product_image'` |
| `docs/2_ERD.md` | **+1 −0** | bullet kolom `image` |
| `AGENTS.md` | **+1 −0** | bullet Notes plan/104 |

> Catatan: `git status` menampilkan **lebih banyak** file termodifikasi (plan/94–103) karena working tree memang sudah dirty sebelum plan/104 dimulai — itu **bukan** perubahan plan ini. Total jejak plan/104 = **11 file diubah + 4 file baru**.

### 1.3 Perubahan DB & filesystem (live)

```sql
ALTER TABLE `gpu_products` ADD COLUMN `image` VARCHAR(255) NULL DEFAULT NULL AFTER `name`;   -- OK
UPDATE `gpu_products` SET `image` = '<slug>' WHERE `name` = '<8 paket kanonik>';             -- 8 baris
```
- 8 berkas di-*rename*: `product{N}-<Nama>.jpeg` → `product{n}-<slug>.jpeg` (spasi hilang).
- Baris `NULL` (disengaja): id 1–4 (lineup legacy, nonaktif) & id 16 (`RTX 5090 Test Node`, nonaktif).

---

## 2. Receipts per Phase

### Phase B — Foundations
- Helper dibuat + `php -l` bersih; `product_image_*` tersedia di member **dan** admin (autoload).
- `mimes.php`: entry `webp` ditambahkan (tanpa ini `Upload::is_allowed_filetype()` **selalu** FALSE untuk WebP → dibuktikan uji positif §4).

### Phase C — Migrasi & storage
```
$ php scripts/migrate_104_gpu_product_images.php --dry-run
Status awal : BELUM ADA
  [PLAN] id=5   RTX 3060 Starter     — SET image = 'product1-rtx-3060-starter.jpeg'
  … 8/8 …
[DRY-RUN] Tidak ada perubahan ditulis.            ← kolom & berkas terverifikasi TIDAK berubah

$ php scripts/migrate_104_gpu_product_images.php --apply
DDL         : ALTER TABLE `gpu_products` ADD COLUMN `image` … → OK
  [SET]  id=5 … id=12  (8 baris)
Baris kanonik terisi : 8/8
Referensi rusak      : 0 baris (seluruh tabel)
[WARN] Berkas > 2048 KB : 8 berkas   ← 2.153–2.891 KB (di atas batas unggah admin)
[OK] 8/8 paket kanonik memiliki gambar yang valid di disk.

$ php scripts/migrate_104_gpu_product_images.php --apply   # RE-RUN
Backfill diterapkan : 0 | Sudah benar (no-op) : 8          ← IDEMPOTEN
```
- `--verify` → exit **0**.
- **Bug ditemukan & diperbaiki saat dry-run:** `rows_by_name()` semula selalu `SELECT id, image` → **fatal** saat dry-run pada DB pra-DDL. Diperbaiki dengan flag `$with_image` (`NULL AS image` bila kolom belum ada) sehingga `--dry-run` benar-benar read-only pada DB lama (lihat §5 Deviasi D1).

### Phase D — Model & Controller
- `Admin_model::_sanitize_product_fields()` — `image` di-whitelist **dan** dipaksa lewat `product_image_filename()` (input liar → `NULL`); kunci absen ⇒ kolom tak tersentuh.
- `Admin_model::is_product_image_referenced($filename, $except_id)` — guard unlink.
- `Admin::_handle_product_image_upload($remove_requested)` + 2 helper lifecycle; `_product_payload_from_row()` memuat `image`.

### Phase E — Admin view
- Kolom **Gambar** (thumbnail `w-20 aspect-video`), `colspan="9"`, `form_open_multipart('admin/products/create')`, blok "Gambar Produk" (preview + hint 16:9 + `remove_image`), JS pratinjau langsung (objek URL di-*revoke*).
- Render live `/admin/products` (HTTP 200): **8 thumbnail** + **5 chip fallback** (produk tanpa gambar: id 1–4, 16) + `enctype="multipart/form-data"` ×1 + preview modal ×1.

### Phase F — Marketplace
- `placehold.co` **dihapus** dari kartu; `aspect-video`/`object-cover`/`rounded-xl`/`loading="lazy"`/`decoding="async"` terverifikasi di HTML tersaji (8×).
- Fallback: gradien gelap di kedua tema + SVG chip (nol `<defs>`/`<id>`, nol teks).

### Phase G — Sinkronisasi dokumen
- `docs/2_ERD.md` (bullet kolom `image`) & `AGENTS.md` (bullet Notes plan/104).

---

## 3. Matriks Verifikasi §12 — Hasil

### 12.1 Statis — ✅ SEMUA LULUS
| Uji | Hasil |
|---|---|
| `php -l` 8 file PHP tersentuh | 0 error |
| `grep placehold.co application/views/marketplace/` | **0** (sisa 1 di `templates/header.php:260` = logo brand, di luar scope F25) |
| `grep "lang(" application/views/admin/products/index.php` | **0** → L1 |
| entry `webp` di `mimes.php` | 1 |
| `php scripts/audit_i18n_parity.php` | **EN 591 = ID 591, paritas 1:1 OK**, prosa ID di idiom EN = 0 |
| `php scripts/audit_i18n_hardcoded.php` | **0 temuan / 74 file** |

### 12.2 CLI & Database — ✅ LULUS
| Uji | Hasil |
|---|---|
| `SHOW COLUMNS … LIKE 'image'` | `varchar(255)`, `Null=YES`, `Default=NULL` |
| `COUNT(image IS NOT NULL)` | **8** |
| Berkas tiap baris kanonik | **8/8 ada** (`--verify` exit 0) |
| Re-run `--apply` | **no-op** (8 no-op, 0 perubahan) |
| Baris lain (id 1–4, 16) | tetap `NULL` — tidak tersentuh |

### 12.3 Runtime — ✅ LULUS
| Uji | Hasil |
|---|---|
| `GET /marketplace` (sesi member) | **200**, **8** `<img uploads/products/…>`, 0 placeholder kartu — di idiom **en** *dan* **id** |
| `GET /admin/products` | **200**, 8 thumbnail + kolom Gambar (label Indonesia) |
| `HEAD /uploads/products/product1-rtx-3060-starter.jpeg` | **200**, `Content-Type: image/jpeg`, `Content-Length: 2717375` |
| `HEAD /uploads/products/index.html` | 200 (placeholder 403) |

### 12.4 Negatif — fallback — ✅ LULUS
| Skenario | Hasil |
|---|---|
| `image = NULL` (id 12) | 7 `<img>` + **1 fallback banner**, `role="img" aria-label="H200 Sovereign"`, 0 warning PHP |
| `image = 'berkas-tidak-ada.jpeg'` | 7 `<img>` + **1 fallback**, **URL rusak tidak pernah muncul di HTML**, 0 warning |
| Restore | `image` kembali ke slug asli (terverifikasi) |

### 12.5 Admin CRUD gambar — ✅ LULUS (produk uji id 16, agar 8 paket kanonik tak tersentuh)
| Uji | Hasil |
|---|---|
| Upload PNG valid | 303 → `image = 154c…png` |
| Upload WebP valid | 303 → `image = bce3…webp`; **berkas PNG lama terhapus** (deletion lifecycle) |
| Audit trail | `system_audit_logs` memuat `before.image`/`after.image` (simetris, id 137–142) |
| `remove_image=1` | `image = NULL` + **berkas fisik terhapus** |
| Guard berkas bersama | id 16 & 12 menunjuk `product8-h200-sovereign.jpeg` → setelah replace, berkas **TIDAK dihapus** + log `masih direferensikan produk lain` |
| Payload ditolak | **tidak** menulis baris audit (validasi gagal sebelum TX) |

### 12.6 Negatif — keamanan upload — ✅ LULUS
| Input | Hasil | Flash (Indonesia) |
|---|---|---|
| PNG valid 70 B | **DITERIMA** | `Paket "RTX 5090 Test Node" berhasil diperbarui.` |
| **WebP valid 51 B** | **DITERIMA** (bukti perbaikan `mimes.php`) | idem |
| SVG menyamar `.jpg` | **DITOLAK** | `Upload gambar gagal: berkas harus berformat JPG, PNG, atau WebP dengan ukuran maksimal 2 MB.` |
| `payload.php` | **DITOLAK** | idem |
| 3 MB (batas PHP `2M`) | **DITOLAK** | idem |
| tepat 2048,00 KB (batas CI `max_size`, perbandingan strict) | **DITOLAK** | idem |

- Detail Inggris **hanya** ke log: `Admin::_handle_product_image_upload — The filetype you are attempting to upload is not allowed.` / `… larger than the permitted size.` → **nol kebocoran prosa Inggris ke UI admin (L1)**.
- `ls uploads/products/` setelah seluruh uji: **8 aset kanonik + `index.html`**, **nol orphan**; nama hasil upload = 32 hex + `.ext`.

### 12.7 Responsif & regresi — ✅ LULUS (kecuali satu item manual)
| Uji | Hasil |
|---|---|
| `GET /admin/alerts/poll` | envelope kanonik `{success,message,data{…}}` |
| `GET /lang/switch/en` & `/id` | 307 (redirect normal) |
| Modal checkout marketplace | `id="transactionModal"` + `.btn-sewa` utuh |
| Toggle status produk | 303, `is_active` 0→1→0 (dikembalikan) |
| **Visual 360/375/414/480 px di browser** | ⚠️ **BELUM** — tidak ada browser/headless di lingkungan ini; kontrak kelas (`w-full aspect-video object-cover rounded-xl` + shell `max-w-[480px]`) terverifikasi di HTML, **verifikasi visual manual direkomendasikan** |

---

## 4. Metode Verifikasi (transparansi)

- **Sesi admin**: login nyata via `POST /control-panel` (kredensial dev), cookie jar terpisah.
- **Sesi member**: **tanpa mengubah data** — file session CI3 (`sess_driver=files`, `/tmp/ci_session<32hex>`) ditulis manual berisi `user_id|i:1;` lalu cookie `ci_session` diset. Password user live **tidak** diketahui dan **tidak** diubah (kredensial seed tidak berlaku di DB live — diverifikasi `password_verify` = no-match).
- **Fixture gambar**: PNG/WebP 1×1 valid dibuat lokal (tanpa GD/Imagick di host); WebP asli → membuktikan jalur `detect_mime` + `mimes.php`. Fixture jahat: SVG ber-ekstensi `.jpg`, `payload.php`, JPEG 3 MB, JPEG tepat 2048,00 KB.
- **Isolasi**: seluruh uji mutasi memakai produk **nonaktif id 16**; 8 paket kanonik tidak pernah diubah. Berkas 8 aset di-backup ke `/tmp/p104_backup/` sebelum uji.

---

## 5. Deviasi & Catatan Terhadap Plan

| # | Deviasi | Alasan / Dampak |
|---|---|---|
| **D1** | `rows_by_name()` diberi parameter `$with_image` (`NULL AS image` bila kolom belum ada) | **Bug nyata** yang tertangkap `--dry-run`: tanpa ini dry-run pada DB pra-DDL berhenti fatal (exit 255). Tidak mengubah perilaku `--apply`. |
| **D2** | Uji mutasi CRUD diarahkan ke produk nonaktif **id 16**, bukan produk kanonik | Melindungi 8 aset/baris produksi; cakupan uji identik (upload, replace, delete, guard). |
| **D3** | `uploads/products/index.html` + helper + script di-`chmod 644` | Konsistensi permission dengan aset web lain (placeholder harus terbaca web server). |
| **D4** | **6 baris audit** (id **137–142**, `admin_update_product`, product_id 16) tertinggal di `system_audit_logs` | Audit bersifat append-only (M5/A1) — menghapusnya = tampering. Semua teridentifikasi & hanya menyentuh produk nonaktif id 16. |
| **D5** | Entry `webp` di `mimes.php` masuk sebagai baris setelah `png` (bukan urutan alfabet ketat) | Mengikuti kedekatan semantik + komentar penjelas; nol dampak fungsional. |
| **D6** | Uji visual responsif 360–480 px belum dijalankan | Tidak ada browser di lingkungan eksekusi — dicatat sebagai langkah manual tersisa (§12.7). |

---

## 6. Follow-up (belum dikerjakan — di luar scope plan/104)

1. **Rekompresi 8 aset** (2.153–2.891 KB, total 20 MB) → ±250–400 KB/berkas. Dampak terbesar untuk mobile; aset **tidak dapat** di-upload ulang via admin selama > 2048 KB.
2. Verifikasi visual 360/375/414/480 px di browser nyata (item §12.7 yang tersisa).
3. Hardening opsional `uploads/products/.htaccess` (matikan eksekusi skrip) — perlu paritas Apache/nginx.
4. Perbaikan mikro opsional: prosa Inggris `display_errors()` pada `Admin::qris_settings()` (pola sama D10; **sudah** diterapkan pada jalur produk baru).
5. Opsional: indikator "berkas hilang" pada tabel admin + pipeline thumbnail bila GD/Imagick kelak tersedia.

---

## 7. Inventaris Perubahan Final

**Kode (8 file):** `application/helpers/product_image_helper.php` (baru), `application/config/autoload.php`, `application/config/mimes.php`, `application/models/Admin_model.php`, `application/controllers/Admin.php`, `application/views/admin/products/index.php`, `application/views/marketplace/index.php`, `scripts/migrate_104_gpu_product_images.php` (baru).

**Data/aset (3):** `uploads/products/index.html` (baru, di-track), `.gitignore`, 8 berkas aset di-*rename* ke slug (runtime, di-gitignore).

**Skema/dokumen (5):** `database.sql`, `database_seed.sql`, `docs/2_ERD.md`, `AGENTS.md`, `plan/104_GPU_PRODUCT_IMAGES_AND_ADMIN_UPLOAD_PLAN.md`.

**DB live:** kolom `gpu_products.image` + 8 baris ter-backfill (id 5–12).
