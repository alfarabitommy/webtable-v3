# Plan 104 — GPU Product Real Image Support & Admin Upload Integration

**Status:** BLUEPRINT / PLAN ONLY — belum ada baris kode, DDL, atau view yang diubah.
**Deliverable plan ini:** dokumen arsitektur (`plan/104_GPU_PRODUCT_IMAGES_AND_ADMIN_UPLOAD_PLAN.md`).
**Scope eksekusi plan ini:** dokumen ini **adalah** deliverable. Phase B–H di §11 adalah roadmap implementasi yang dieksekusi setelah blueprint disetujui (terpisah dari plan ini).

---

## 0. Konteks & Masalah

`application/views/marketplace/index.php:67` merender gambar produk dari placeholder eksternal:

```php
<img src="https://placehold.co/400x150/f8fafc/94a3b8?text=<?= urlencode($product['name']) ?>"
     class="rounded-xl object-cover h-28 w-full mb-3" alt="<?= htmlspecialchars($product['name']) ?>">
```

Tabel `gpu_products` **tidak punya kolom gambar**, sehingga tidak ada jalur untuk menampilkan aset nyata. User telah menyediakan 8 aset gambar produk berkualitas (16:9, JPEG) di `uploads/products/`, tetapi belum terhubung ke baris produk mana pun.

Plan ini merancang: (a) kolom `image` + migrasi idempoten, (b) pipeline upload aman di admin, (c) refactor kartu marketplace menjadi kontainer 16:9 dengan fallback bergaya, (d) backfill langsung 8 produk aktif.

---

## 1. Ringkasan & Success Criteria

| # | Kriteria | Bukti verifikasi |
|---|---|---|
| S1 | `gpu_products.image VARCHAR(255) NULL` ada di DB live **dan** di `database.sql` | `SHOW COLUMNS FROM gpu_products LIKE 'image'` + grep DDL kanonik |
| S2 | 8 produk aktif (live id 5–12) punya `image` terisi dan berkasnya ada di disk | `SELECT COUNT(*) FROM gpu_products WHERE image IS NOT NULL` = 8, tiap berkas `is_file()` |
| S3 | Marketplace tidak lagi memuat `placehold.co`; kartu memakai kontainer 16:9 nyata | `grep -rn placehold.co application/views/marketplace/` = 0 |
| S4 | `image` NULL **atau** berkas hilang → fallback banner gelap ber-ikonografi GPU, tanpa PHP warning & tanpa broken-image | uji negatif §12.4 |
| S5 | Admin dapat **upload / ganti / hapus** gambar produk; berkas lama terhapus setelah persist sukses | uji §12.5 |
| S6 | Upload hanya `jpg\|jpeg\|png\|webp`, ≤ 2048 KB, true-MIME, nama acak; SVG/PHP/executable ditolak | uji §12.6 |
| S7 | UI admin 100% Indonesia (L1); marketplace tetap dwibahasa murni (plan/103) tanpa key kamus baru — paritas 332 key tetap | `php scripts/audit_i18n_parity.php` = 0 diff |
| S8 | Migrasi CLI idempoten (`--dry-run`/`--apply`) + SQL raw siap-tempel phpMyAdmin | re-run = no-op, exit code 0 |

**Non-goal:** resize/thumbnail server-side, galeri multi-gambar per produk, CDN/object-storage, hard delete produk, perubahan skema di luar satu kolom `image`.

---

## 2. Fakta Codebase Terverifikasi (pra-edit)

Semua baris di bawah sudah diperiksa langsung di repo/DB pada saat plan ini disusun.

| # | Temuan | Bukti |
|---|---|---|
| F1 | `gpu_products` live **belum** punya kolom `image`; kolom terakhir = `unlock_prerequisite_id` | `DESCRIBE gpu_products` |
| F2 | Live DB: 8 produk aktif **id 5–12** (`RTX 3060 Starter` … `H200 Sovereign`); id 1–4 = lineup legacy **nonaktif**; id 16 = `RTX 5090 Test Node` **nonaktif** | `SELECT id,name,is_active` |
| F3 | `database.sql:404-422` men-seed lineup kanonik yang **sama** dengan **id 1–8** (bukan 5–12) | `database.sql:405-412` |
| F4 | `database_seed.sql:47-54` masih lineup lama 4 produk (nama berbeda) | `database_seed.sql:46-54` |
| F5 | ⇒ **mapping backfill wajib keyed by `name`**, bukan `id` | konsekuensi F2+F3 |
| F6 | 8 aset ada di `uploads/products/` dengan **spasi pada nama berkas**: `product{N}-<Nama>.jpeg`; tiap berkas **2752×1536**, JPEG baseline, ukuran **2,1–2,9 MB** (semua **>** batas 2 MB); total direktori 20 MB | `getimagesize()`, `du -sh` |
| F7 | `uploads/products/` **belum** di-gitignore dan **belum** punya `index.html`; `uploads/qris/` punya preseden keduanya | `.gitignore` blok plan/102, `uploads/qris/index.html` |
| F8 | `application/config/mimes.php` **tidak punya entry `webp`** (punya `jpeg`/`jpg`/`png` di :77-88) | `grep webp application/config/mimes.php` = kosong |
| F9 | `Upload::is_allowed_filetype()` melakukan lookup `$this->_mimes[$ext]`; ekstensi tak terdaftar → **FALSE** | `system/libraries/Upload.php:903-910` |
| F10 | Urutan validasi upload: ekstensi+MIME (`:465`) → MIME kedua untuk gambar (`:487`) → ukuran (`:501`) → dimensi (`:509`) | `system/libraries/Upload.php` |
| F11 | `is_allowed_filesize()` = `max_size > file_size` (**strict**); `file_size` = KB `round(x,2)` | `Upload.php:934`, `:497` |
| F12 | `encrypt_name` → `md5(uniqid(mt_rand())).ext` (32 hex) | `Upload.php:651` |
| F13 | Host PHP: `upload_max_filesize=2M`, `post_max_size=8M`, **GD = none**, **Imagick = none**, fileinfo = yes | `php -i` |
| F14 | Preseden upload aman + lifecycle unlink berkas: `Admin::qris_settings()` | `Admin.php:548-614` |
| F15 | Preseden pesan galat upload berbahasa Indonesia (anti-leak prosa Inggris, plan/103): `Profile::update()` | `Profile.php:57-63` |
| F16 | Preseden view upload: `form_open_multipart()` + kotak preview + hint | `admin/settings.php:301-325` |
| F17 | Admin produk: CRUD + validasi + audit (M5) + whitelist kolom anti mass-assignment | `Admin.php:1548-1794`, `Admin_model.php:824-985` |
| F18 | Form modal produk **tanpa `enctype`** (`form_open()` di `:178`); tabel `:55-148`; `colspan="8"` di `:71`; `data-product` JSON `:118-128`; JS `:250-317` | `application/views/admin/products/index.php` |
| F19 | `csrf_regenerate = FALSE` (token stabil per sesi); guard `data-guard-submit` bersifat opt-in | `config.php:463-467`, `templates/csrf_meta.php:64-93` |
| F20 | Tailwind Play CDN v3 → utilitas `aspect-video` tersedia | `templates/header.php:23`, `admin/templates/header.php:21` |
| F21 | Helper autoload: `url, file, form, security, language, i18n, maintenance` | `application/config/autoload.php:95` |
| F22 | Preseden helper kustom (function_exists guard + dimuat lintas admin/member): `captcha_helper.php`, `i18n_helper.php` | `application/helpers/` |
| F23 | Preseden script migrasi CLI idempoten (`load_db_config()`, `col_exists()`, exit 0/1/2) | `scripts/migrate_103_notification_i18n.php:32-87` |
| F24 | Kamus i18n: 332 key di kedua idiom, paritas dijaga sebagai invariant | `app_lang.php`, plan/100 |
| F25 | `placehold.co` hanya di 2 tempat: `marketplace/index.php:67` dan `templates/header.php:260` (logo header — **di luar scope**) | `grep -rn placehold.co application/` |

---

## 3. Design Decisions (mengikat untuk eksekusi)

| # | Keputusan | Alasan |
|---|---|---|
| **D1** | Kolom menyimpan **basename saja** (tanpa path, tanpa URL) | konsisten dengan `users.avatar_url` & `system_settings.qris_image` |
| **D2** | Prefix `uploads/products/` di-resolve **satu tempat** (helper), bukan di 3 view | 3 konsumen: marketplace, tabel admin, modal admin |
| **D3** | Backfill **keyed by `name`**, bukan `id` | dua id-space (F2 vs F3) |
| **D4** | Aset di-**rename ke slug** saat backfill (default), `--keep-filenames` sebagai escape hatch | nama aset ber-spasi → URL rapuh; slug = URL bersih tanpa encoding |
| **D5** | Status fallback ditentukan **`is_file()`**, bukan hanya NULL | DB bisa menunjuk berkas yang sudah dihapus → tetap graceful |
| **D6** | Fallback marketplace = `<div>` 16:9 **tanpa teks** + SVG chip GPU **tanpa `<defs>`/`id`** | dwibahasa murni (plan/103) + hindari 8 ID duplikat dalam satu dokumen |
| **D7** | Fallback mini admin memakai `<i class="fas fa-microchip">`, bukan SVG | konsisten dengan empty-state admin yang sudah ada (`admin/products/index.php:72`) |
| **D8** | **Wajib** menambah `'webp' => array('image/webp')` ke `mimes.php` | tanpa itu `detect_mime=TRUE` **selalu** menolak WebP (F8+F9) |
| **D9** | Batas upload tetap **2048 KB** (sesuai brief), meski 8 aset bawaan 2,1–2,9 MB | aset bawaan hanya masuk lewat backfill (filesystem), bukan jalur upload; script memberi peringatan |
| **D10** | Pesan galat upload ke admin = prosa **Indonesia generik**; detail asli (Inggris) hanya ke `log_message()` | Invariant L1 + preseden plan/103 (F15) |
| **D11** | Kontrol **`remove_image`** (hapus gambar) termasuk scope | tanpa itu admin hanya bisa mengganti, tidak bisa mengosongkan |
| **D12** | **Tidak** menambahkan `data-guard-submit` ke form modal produk | `resetForm()` memanggil `form.reset()` yang **tidak** membersihkan flag `data-submitting` → risiko form terkunci permanen |
| **D13** | Nol key kamus baru | paritas 332 key terjaga; `alt`/`aria-label` memakai `name` (data, bukan copy UI) |
| **D14** | **Tidak** ada pemrosesan gambar server-side (tanpa resize/thumbnail/recompress) | GD & Imagick tidak terpasang (F13) |
| **D15** | Seed kanonik `database.sql` **tidak** meng-hardcode nama berkas gambar | berkas runtime di-gitignore → instalasi bersih tak punya berkasnya; fallback banner menangani |

---

## 4. Database — DDL, Migrasi & Backfill

### 4.1 DDL kanonik

```sql
ALTER TABLE `gpu_products`
  ADD COLUMN `image` VARCHAR(255) NULL DEFAULT NULL AFTER `name`;
```

`VARCHAR(255)`: kompatibel dengan `encrypt_name` (32 hex + ekstensi) dan slug backfill; selaras dengan kolom nama-berkas lain di repo.

### 4.2 Patch `database.sql`

**(a) `CREATE TABLE gpu_products`** (setelah baris `name` di `:41`):

```sql
  `name` VARCHAR(100) NOT NULL,
  -- plan/104: nama berkas gambar produk (BASENAME saja, tanpa path) di
  -- `uploads/products/`. NULL atau berkas hilang di disk → marketplace
  -- merender fallback banner gelap (resolusi via product_image_helper).
  -- Berkas fisik bersifat runtime & di-gitignore; kolom ini hanya nama.
  `image` VARCHAR(255) NULL DEFAULT NULL,
  `type` ENUM('short_term', 'long_term') NOT NULL,
```

**(b) Blok "MIGRASI LIVE" baru** di ekor file (mengikuti pola blok plan/94 `:426-442` dan plan/102 `:443-485`), berisi:

- `ALTER TABLE` di atas + varian MariaDB `ADD COLUMN IF NOT EXISTS` (idempoten-safe);
- catatan MySQL 8 tidak mendukung `IF NOT EXISTS` pada `ADD COLUMN` → jalankan sekali, atau pakai tool yang memeriksa `information_schema`;
- pointer ke `scripts/migrate_104_gpu_product_images.php --dry-run|--apply|--verify`;
- pernyataan bahwa backfill bersifat **name-keyed** dan tidak menimpa gambar hasil upload admin.

### 4.3 Patch `database_seed.sql`

Pada `SECTION: gpu_products` (`:47-54`): tambahkan kolom `image` ke daftar kolom `INSERT` dengan nilai **`NULL`** untuk 4 baris legacy (tidak ada aset yang cocok), sehingga file tetap konsisten bila dijalankan ke DB bersih. Tambahkan komentar bahwa backfill 8 paket kanonik dilakukan oleh migrasi plan/104 (name-keyed), **bukan** oleh seed ini (D15).

### 4.4 `scripts/migrate_104_gpu_product_images.php` — arsitektur

Mengikuti **pola persis** `scripts/migrate_103_notification_i18n.php`: CLI self-contained (tanpa bootstrap CI3), `load_db_config()` membaca `application/config/database.php`, `mysqli` + `SET time_zone='+07:00'` (WIB, M2), `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)`.

```
Usage: php scripts/migrate_104_gpu_product_images.php [--dry-run|--apply|--verify]
                                                     [--keep-filenames] [--help]

  --dry-run         (default) inspeksi + cetak rencana, TIDAK menulis apa pun
  --apply           DDL + rename aset + backfill + verify
  --verify          hanya verifikasi (read-only); exit 2 bila tidak 8/8
  --keep-filenames  jangan rename berkas; simpan nama aset apa adanya (ber-spasi)
  --help | -h       usage

Exit codes: 0 = bersih/no-op, 1 = pre-flight gagal, 2 = apply/verify gagal
```

**Tahapan `--apply`:**

1. **Pre-flight** — koneksi DB; `gpu_products` ada; `uploads/products/` ada & writable; hitung baris aktif; cetak snapshot `id | name | image | file? | size KB`.
2. **DDL (idempoten)** — `col_exists('gpu_products', 'image')` via `information_schema` (pola `migrate_103:80-87`); `ALTER TABLE … ADD COLUMN` **hanya** bila belum ada; verifikasi ulang **setelah** eksekusi. Catatan: DDL MySQL auto-commit → tidak bisa di-rollback; karena itu tiap tahap diverifikasi pasca-eksekusi dan gagal → exit 2.
3. **Backfill + rename** (loop peta §4.6):
   - resolusi baris: `SELECT id, image FROM gpu_products WHERE name = ?` (bila >1 baris → peringatan, semua baris diproses; bila 0 → `SKIP (nama tidak dikenal)`);
   - penentuan target nama: pakai slug bila berkas slug **sudah** ada → jika belum, `rename(src → slug)`; bila rename gagal atau `--keep-filenames` → pakai nama asli;
   - `UPDATE gpu_products SET image = ? WHERE id = ? AND (image IS NULL OR image = ? /* nama pra-rename */)` → **tidak pernah menimpa** gambar yang sudah di-upload admin (tidak ada flag `--force`);
   - urutan **rename dulu, baru UPDATE**: crash di antaranya menyisakan `image` menunjuk berkas yang sudah tiada (→ fallback banner, degradasi graceful) dan **re-run menyembuhkan sendiri** karena syarat `image IS NULL OR image = <nama pra-rename>`.
4. **Verify** — `COUNT(image IS NOT NULL)` = jumlah peta yang cocok; setiap nama dicek `is_file()`; **peringatan eksplisit** untuk berkas `> 2048 KB` ("tidak dapat di-upload ulang lewat panel admin — pertimbangkan rekompresi"); peringatan untuk baris yang cocok tapi berkasnya hilang.
5. **Ringkasan** — tabel hasil + exit code.

**Sanitasi internal script** (script berjalan di luar bootstrap CI3 sehingga **tidak** memakai helper kustom; duplikasi ±6 baris didokumentasikan di header script):

```php
// nama: basename-only + tolak '..', '/', '\', NUL + allowlist ekstensi
$ext_ok = in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp'], true);
$safe   = ($name === basename($name)) && $ext_ok && strpos($name, "\0") === false;

// slug: lowercase + normalisasi non-allowlist menjadi '-'
$slug = preg_replace('/-+/', '-', preg_replace('/[^a-z0-9._-]/', '-', strtolower($src)));
```

### 4.5 Raw SQL siap-tempel (phpMyAdmin)

```sql
-- ══ STEP 0: prasyarat — hasil HARUS 0 sebelum ALTER ══════════════════
SELECT COUNT(*) AS kolom_sudah_ada
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'gpu_products'
   AND COLUMN_NAME  = 'image';

-- ══ STEP 1: DDL ══════════════════════════════════════════════════════
ALTER TABLE `gpu_products`
  ADD COLUMN `image` VARCHAR(255) NULL DEFAULT NULL AFTER `name`;

-- Varian MariaDB (mendukung IF NOT EXISTS — idempoten-safe):
-- ALTER TABLE `gpu_products`
--   ADD COLUMN IF NOT EXISTS `image` VARCHAR(255) NULL DEFAULT NULL AFTER `name`;

-- ══ STEP 2: verifikasi kolom ═════════════════════════════════════════
SHOW COLUMNS FROM `gpu_products` LIKE 'image';
-- Harapan: Field=image, Type=varchar(255), Null=YES, Default=NULL

-- ══ STEP 3: backfill 8 produk (aman diulang; hanya mengisi yang NULL) ═
UPDATE `gpu_products` SET `image` = 'product1-RTX 3060 Starter.jpeg'   WHERE `name` = 'RTX 3060 Starter'   AND `image` IS NULL;
UPDATE `gpu_products` SET `image` = 'product2-RTX 4060 Lite.jpeg'      WHERE `name` = 'RTX 4060 Lite'      AND `image` IS NULL;
UPDATE `gpu_products` SET `image` = 'product3-RTX 4070 Basic.jpeg'     WHERE `name` = 'RTX 4070 Basic'     AND `image` IS NULL;
UPDATE `gpu_products` SET `image` = 'product4-RTX 4080 Prime.jpeg'     WHERE `name` = 'RTX 4080 Prime'     AND `image` IS NULL;
UPDATE `gpu_products` SET `image` = 'product5-RTX 4090 Pro.jpeg'       WHERE `name` = 'RTX 4090 Pro'       AND `image` IS NULL;
UPDATE `gpu_products` SET `image` = 'product6-A100 Cloud Cluster.jpeg' WHERE `name` = 'A100 Cloud Cluster' AND `image` IS NULL;
UPDATE `gpu_products` SET `image` = 'product7-H100 Tensor Node.jpeg'   WHERE `name` = 'H100 Tensor Node'   AND `image` IS NULL;
UPDATE `gpu_products` SET `image` = 'product8-H200 Sovereign.jpeg'     WHERE `name` = 'H200 Sovereign'     AND `image` IS NULL;

-- ══ STEP 4: laporan ══════════════════════════════════════════════════
SELECT id, name, image FROM `gpu_products` WHERE `is_active` = 1 ORDER BY id;
SELECT COUNT(*) AS terisi FROM `gpu_products` WHERE `image` IS NOT NULL;   -- harapan: 8
```

> **Jangan campur dua jalur.** Bila memakai SQL manual di atas, berkas tetap bernama ber-spasi → jalankan script dengan `--keep-filenames` (atau tidak usah menjalankan script sama sekali); helper akan meng-`rawurlencode` spasi sehingga URL tetap valid. Bila memakai jalur script (default), berkas akan di-*rename* ke slug dan kolom berisi slug.

### 4.6 Mapping backfill 8 produk (langsung, siap pakai)

| # | `name` (kunci resolusi) | live id | kanonik id (`database.sql`) | berkas sumber (sekarang) | target slug (default script) |
|---|---|---|---|---|---|
| 1 | RTX 3060 Starter | 5 | 1 | `product1-RTX 3060 Starter.jpeg` | `product1-rtx-3060-starter.jpeg` |
| 2 | RTX 4060 Lite | 6 | 2 | `product2-RTX 4060 Lite.jpeg` | `product2-rtx-4060-lite.jpeg` |
| 3 | RTX 4070 Basic | 7 | 3 | `product3-RTX 4070 Basic.jpeg` | `product3-rtx-4070-basic.jpeg` |
| 4 | RTX 4080 Prime | 8 | 4 | `product4-RTX 4080 Prime.jpeg` | `product4-rtx-4080-prime.jpeg` |
| 5 | RTX 4090 Pro | 9 | 5 | `product5-RTX 4090 Pro.jpeg` | `product5-rtx-4090-pro.jpeg` |
| 6 | A100 Cloud Cluster | 10 | 6 | `product6-A100 Cloud Cluster.jpeg` | `product6-a100-cloud-cluster.jpeg` |
| 7 | H100 Tensor Node | 11 | 7 | `product7-H100 Tensor Node.jpeg` | `product7-h100-tensor-node.jpeg` |
| 8 | H200 Sovereign | 12 | 8 | `product8-H200 Sovereign.jpeg` | `product8-h200-sovereign.jpeg` |

- Prefix angka pada nama berkas = **urutan lineup kanonik**, **bukan** live id (F2 vs F3) → resolusi wajib via `name`.
- Semua aset: **2752×1536** (≈1,79:1 → `object-cover` memotong ≤ 1,2%), JPEG baseline.
- Baris yang **sengaja** dibiarkan `NULL` (→ fallback di admin, tidak pernah tampil di marketplace karena `is_active = 0`): live id 1–4 (lineup legacy) dan id 16 (`RTX 5090 Test Node`).

---

## 5. Storage & Security Spec — `uploads/products/`

| Kontrol | Nilai | Catatan |
|---|---|---|
| Path unggah | `./uploads/products/` | mengikuti preseden `./uploads/qris/` (F14) |
| Allowlist tipe | `jpg\|jpeg\|png\|webp` | brief menang atas preseden QRIS (`png\|jpg\|jpeg`) |
| Ukuran maks | `max_size = 2048` (KB) | selaras `upload_max_filesize=2M` host; CI memakai perbandingan **strict** (F11) → praktis ≤ 2047,99 KB |
| Nama berkas | `encrypt_name = TRUE` | `md5(uniqid(mt_rand())).ext` — *obscurity*, **bukan** CSPRNG (F12); dapat diterima karena gambar katalog bersifat publik (tanpa kebutuhan kerahasiaan) |
| Normalisasi nama | `remove_spaces = TRUE`, `file_ext_tolower = TRUE` | cegah nama ber-spasi/huruf besar |
| Deteksi tipe | `detect_mime = TRUE` | finfo true-MIME + `getimagesize()` (wajib untuk ekstensi gambar, F10) |
| **Prasyarat konfigurasi** | `application/config/mimes.php`: tambah `'webp' => array('image/webp'),` (setelah `'png'` di `:88`) | **tanpa ini WebP pasti ditolak** (F8+F9) |
| SVG | ditolak | tidak ada di allowlist; `$mimes['svg']` memuat `text/xml` → vektor stored-XSS (L: pohon keamanan repo) |
| PHP/JS/HTML/Polyglot | ditolak | allowlist ekstensi + true-MIME (`detect_mime`) |
| Path traversal | mustahil | `encrypt_name` (nama dibangkitkan server) + sanitasi basename-only di helper & model |
| Directory listing / akses langsung skrip | dicegah | `index.html` placeholder di-track (pola plan/102) |

**`.gitignore`** (blok baru di bawah blok plan/102):

```
# plan/104: konten unggahan runtime (gambar produk GPU admin) — direktori tetap
# ada di clone bersih lewat placeholder yang di-track.
uploads/products/*
!uploads/products/index.html
```

**`uploads/products/index.html`** = salinan byte-identik `uploads/qris/index.html`:

```html
<!DOCTYPE html>
<html><head><title>403</title></head><body><h1>No direct script access allowed</h1></body></html>
```

**Lifecycle hapus berkas (urutan wajib — mengikuti `Admin::qris_settings()`):**

1. Upload berkas baru (Upload library menulis ke `uploads/products/`).
2. Persist DB di dalam TX + audit (state + audit commit bersama, M5).
3. **Persist gagal** → `@unlink` berkas **baru** (anti-orphan); gambar lama tidak tersentuh.
4. **Persist sukses** → `@unlink` berkas **lama**, dengan guard lengkap:
   `old !== ''` && `old !== new` && `!is_product_image_referenced(old, id)` && `file_exists(...)`.

---

## 6. Helper Kanonik — `application/helpers/product_image_helper.php` (baru)

Mengikuti pola `captcha_helper.php`/`i18n_helper.php`: semua fungsi dibungkus `function_exists()`, dan didaftarkan di `application/config/autoload.php:95` dengan menambah `'product_image'` ke `$autoload['helper']`.

```php
product_image_filename($raw): ?string
// basename-only; allowlist jpg|jpeg|png|webp; ≤255 char; tolak '/', '\', '..', NUL, '://'
// → null bila kosong / non-string / tidak aman

product_image_path($raw): ?string
// FCPATH . 'uploads/products/' . <nama valid>  → null bila nama tidak valid

product_image_exists($raw): bool
// nama valid && is_file(product_image_path())
// memoized (static $cache) → 8 kartu = ≤ 8 stat() (OS-cached)

product_image_url($raw): ?string
// base_url('uploads/products/' . rawurlencode(<nama>)) → null bila nama kosong /
// tidak aman / berkas tidak ada di disk
```

**Kontrak tunggal:** `product_image_url()` mengembalikan `null` untuk NULL / string kosong / nama tidak aman / berkas tidak ada di disk → **satu titik keputusan fallback** untuk ketiga konsumen view.

`rawurlencode` hanya diterapkan pada nama (basename → tidak ada `/`), sehingga aset ber-spasi dari jalur SQL manual (§4.5) tetap menghasilkan URL valid.

---

## 7. Integrasi Admin

### 7.1 `application/models/Admin_model.php`

1. **`_sanitize_product_fields()`** (`:958-985`) — tambah `'image'` ke `$allowed`:

```php
case 'image':
    // plan/104: hanya nama berkas hasil server (encrypt_name) atau slug
    // backfill yang boleh masuk; input liar → NULL (anti mass-assignment).
    $clean[$key] = product_image_filename($data[$key]);
    break;
```

Kunci yang **absen** dari `$data` ⇒ kolom tidak tersentuh ⇒ `update_product()` tanpa `image` tidak menghapus gambar yang ada. Nilai `null` ⇒ SQL `NULL`.

2. **Method baru** (SQL hanya di model, bound param):

```php
/**
 * plan/104: true bila nama berkas gambar masih direferensikan baris produk lain.
 * Guard sebelum @unlink berkas lama (cegah penghapusan aset bersama — mis. hasil
 * backfill/seed manual yang dipakai >1 baris).
 *
 * @param string $filename
 * @param int    $except_id  baris yang sedang di-update (dikecualikan)
 * @return bool
 */
public function is_product_image_referenced($filename, $except_id = 0) {
    return $this->db->where('image', (string) $filename)
                    ->where('id !=', (int) $except_id)
                    ->count_all_results('gpu_products') > 0;
}
```

3. `get_products_admin()` (`:824-848`) dan `get_product_row()` (`:857-864`) memakai `SELECT p.*` → kolom `image` ikut otomatis (**tanpa perubahan query**).

### 7.2 `application/controllers/Admin.php`

**Private helper baru** `_handle_product_image_upload($remove_requested)`:

```php
$config = [
    'upload_path'      => './uploads/products/',
    'allowed_types'    => 'jpg|jpeg|png|webp',
    'max_size'         => 2048,        // KB — selaras upload_max_filesize=2M host
    'encrypt_name'     => TRUE,        // nama acak (32 hex) → anti traversal/collision
    'remove_spaces'    => TRUE,
    'file_ext_tolower' => TRUE,
    'detect_mime'      => TRUE,        // true-MIME finfo (butuh entry mimes.php, D8)
];
```

Kontrak return:

| Kondisi | Return |
|---|---|
| `$_FILES['image']['name']` kosong & `!$remove_requested` | `['ok'=>true, 'set'=>false]` → **pertahankan** gambar lama |
| `do_upload()` gagal | `['ok'=>false, 'error'=>'Upload gagal: berkas harus berformat JPG, PNG, atau WebP dengan ukuran maksimal 2 MB.']` + `log_message('error', $this->upload->display_errors('', ''))` (D10) |
| Sukses | `['ok'=>true, 'set'=>true, 'image'=>$upload_data['file_name']]` |
| `remove_image` dicentang tanpa upload | `['ok'=>true, 'set'=>true, 'image'=>null]` |

**Integrasi (urutan wajib: validasi teks → upload → persist):**

- `create_product()` (`:1563-1601`):
  `_validate_product_payload(false)` → `_handle_product_image_upload()` → `$v['fields']['image']` (bila `set`) → `trans_start` → `Admin_model::create_product()` + audit `admin_create_product` → `trans_complete`; gagal ⇒ `@unlink` berkas baru.
- `update_product($id)` (`:1603-1656`):
  `$before = get_product_row($id)` (**sudah** memuat `->image`) → validasi → `$remove_requested = ($this->input->post('remove_image') !== null)` → upload → `$v['fields']['image']` (bila `set`) → `trans_start` → `update_product()` + audit `admin_update_product` (payload before/after otomatis memuat `image`) → `trans_complete`; gagal ⇒ unlink berkas baru; sukses ⇒ unlink berkas lama dengan guard §5.
- `toggle_product_status()` (`:1658-1703`), routes, guard POST-only, CSRF: **tidak berubah**.
- `_product_payload_from_row()` (`:1783-1794`) — tambah satu baris:

```php
'image' => $row->image ?? null,   // ?? → aman bila kolom belum ada (deploy kode mendahului DDL)
```

- Audit: **tidak ada key baru**. `after.image` / `before.image` sudah menyampaikan perubahan lengkap (payload tetap simetris).

### 7.3 `application/views/admin/products/index.php`

1. **Kolom tabel baru** `<th …>Gambar</th>` setelah kolom ID; `colspan="8"` di `:71` → **`colspan="9"`**.
2. **Sel thumbnail**:

```php
<td class="px-4 py-3">
    <?php $thumb = product_image_url($p->image ?? null); ?>
    <div class="w-20 aspect-video rounded-lg overflow-hidden bg-slate-900 border border-[var(--t-border)] flex items-center justify-center">
        <?php if ($thumb !== null): ?>
            <img src="<?= $thumb ?>" alt="<?= htmlspecialchars($p->name) ?>"
                 loading="lazy" decoding="async" class="w-full h-full object-cover">
        <?php else: ?>
            <i class="fas fa-microchip text-[var(--t-muted)]"></i>
        <?php endif; ?>
    </div>
</td>
```

3. **Payload `data-product`** (`:118-128`) — tambah 2 key:

```php
'image'     => (string) ($p->image ?? ''),
'image_url' => (string) (product_image_url($p->image ?? null) ?? ''),
```

4. **Form modal** (`:178`) → ganti ke multipart (wajib, form sekarang tanpa `enctype`; F18):

```php
<?= form_open_multipart('admin/products/create', ['id' => 'productModalForm', 'class' => 'space-y-4']) ?>
```

`enctype` tetap utuh meski JS mengganti `form.action` ke `admin/products/update/{id}`. Token CSRF tetap disisipkan otomatis oleh helper (CSRF `csrf_regenerate = FALSE`, F19).

5. **Blok form baru "Gambar Produk"** (di dalam grid, `sm:col-span-2`), 100% Indonesia (L1):

- label `Gambar Produk` + keterangan `(opsional)`;
- kotak preview `min-h-[140px]` (pola `admin/settings.php:301-325`) berisi `<img id="f_image_preview" class="hidden w-full max-w-[280px] aspect-video object-cover rounded-lg">` atau blok kosong `<div id="f_image_empty">` dengan `<i class="fas fa-microchip …">` + teks `Belum ada gambar`;
- `<input type="file" id="f_image" name="image" accept="image/jpeg,image/png,image/webp">` ber-style `t-input` + `file:` utilities;
- hint: `Format JPG/PNG/WebP, maksimal 2 MB. Rasio ideal 16:9 (contoh 1920×1080). Mengunggah berkas baru akan menggantikan gambar lama.`;
- checkbox `Hapus gambar saat ini` (`name="remove_image" value="1"`, `id="f_image_remove_wrap"` tersembunyi di mode create).

6. **JS (vanilla, tanpa library baru)**:

- `resetForm()` → sembunyikan preview & checkbox hapus, tampilkan blok kosong; `form.reset()` juga mengosongkan `input[type=file]`;
- `openProductEdit()` → bila `d.image_url` tidak kosong: tampilkan preview dengan `src = d.image_url` dan tampilkan wrap `remove_image`;
- listener `change` pada `f_image` → preview via `URL.createObjectURL(file)` (objek URL sebelumnya di-`revokeObjectURL`), dan **uncheck** `remove_image` (upload menang atas hapus — didokumentasikan di hint UI);
- Tidak ada `data-guard-submit` pada form ini (D12).

---

## 8. Marketplace View Overhaul (`application/views/marketplace/index.php`)

Ganti baris `:67` dengan kontainer 16:9 + fallback:

```php
<?php $img_url = product_image_url($product['image'] ?? null); ?>
<?php if ($img_url !== null): ?>
    <img src="<?= $img_url ?>" alt="<?= htmlspecialchars($product['name']) ?>"
         loading="lazy" decoding="async"
         class="w-full aspect-video object-cover rounded-xl mb-3 bg-slate-900">
<?php else: ?>
    <div class="w-full aspect-video rounded-xl mb-3 overflow-hidden
                bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900
                flex items-center justify-center"
         role="img" aria-label="<?= htmlspecialchars($product['name']) ?>">
        <svg viewBox="0 0 96 96" fill="none" class="w-16 h-16" aria-hidden="true">
            <circle cx="48" cy="48" r="24" fill="#6366f1" opacity="0.12"/>
            <g stroke="#475569" stroke-width="2.5" stroke-linecap="round">
                <path d="M26 40h-8M26 48h-8M26 56h-8M70 40h8M70 48h8M70 56h8"/>
                <path d="M40 26v-8M48 26v-8M56 26v-8M40 70v8M48 70v8M56 70v8"/>
            </g>
            <rect x="26" y="26" width="44" height="44" rx="7" stroke="#818cf8" stroke-width="2.5" opacity="0.85"/>
            <rect x="40" y="40" width="16" height="16" rx="3" fill="#22d3ee" opacity="0.5"/>
        </svg>
    </div>
<?php endif; ?>
```

**Rasional desain:**

| Aspek | Keputusan |
|---|---|
| Kelas wajib brief | `aspect-video`, `w-full`, `object-cover`, `rounded-xl` — semuanya ada pada `<img>` |
| Fallback | gradien gelap **di kedua tema** (pola `.u-card-fin`), ikonografi chip GPU (die + 12 pin + core), **nol teks** → dwibahasa murni (plan/103) |
| SVG | **tanpa `<defs>`/`id`** → nol risiko ID duplikat saat 8 kartu dirender; hanya atribut warna/opacity statis |
| Aksesibilitas | `role="img"` + `aria-label` = nama produk → paritas dengan `alt` pada cabang gambar |
| Nol layout shift | kedua cabang memakai `aspect-video` (ruang ter-*reserve* sebelum gambar dimuat) |
| Performa | `loading="lazy"` + `decoding="async"` — penting: total aset bawaan 20 MB |
| Ketahanan deploy | `$product['image'] ?? null` → aman bila kode ter-deploy mendahului DDL (tanpa `Undefined array key`) |
| CSS baru | **nol** — hanya utilitas Tailwind; `templates/header.php` (plan/100) tidak disentuh |
| Di luar perubahan | badge kuota, harga, tombol `.btn-sewa`, modal bottom-sheet, JS checkout — **tidak tersentuh** |

---

## 9. Invariants & Kepatuhan i18n

| Invariant | Pemenuhan |
|---|---|
| **L1** — admin 100% Indonesia | seluruh label/hint/pesan baru hardcoded Indonesia; `admin/products/index.php` **tidak** memanggil `lang()`; galat upload tidak pernah menampilkan prosa Inggris (D10, preseden plan/103) |
| **plan/103** — kemurnian dwibahasa member | nol key kamus baru; `alt`/`aria-label` memakai `name` (data produk, bukan copy UI); paritas **332 key** tidak berubah |
| **L6** — uang tidak dilokalkan | tidak ada perubahan format IDR (`Rp` + `number_format`) |
| **M5** — state + audit commit bersama | create/update produk tetap dalam `trans_start`/`trans_complete`; audit `admin_create_product`/`admin_update_product` memuat `image` di before/after |
| **M8** — disiplin integer IDR | tidak menyentuh aritmetika uang |
| Zero Hard-Delete | tidak ada jalur hapus produk; hapus **berkas gambar** ≠ hapus baris |
| SQL hanya di model | upload = filesystem (preseden QRIS); query nama-berkas di `Admin_model::is_product_image_referenced()` |
| Mobile shell 360–480 px | gambar `w-full aspect-video` di dalam kartu `p-4` → ~328–448 px, tanpa overflow horizontal |
| Framework compatibility | nol perubahan di `system/` |
| Dual-auth separation | route/guard admin tidak berubah (`admin_id` session) |

---

## 10. Edge Cases & Failure Modes

| # | Kasus | Perilaku yang diinginkan |
|---|---|---|
| 1 | Kolom `image` belum ada saat kode ter-deploy | `?? null` di view & model → fallback banner, nol PHP warning |
| 2 | Berkas hilang dari disk tapi DB terisi | `product_image_url()` → `null` → fallback banner |
| 3 | Nama ber-spasi (jalur SQL manual §4.5) | helper `rawurlencode` → URL valid; tetap disarankan jalur slug |
| 4 | Upload ditolak (tipe/ukuran) | nol write DB, nol berkas tersisa, gambar lama utuh, flash Indonesia |
| 5 | Persist gagal setelah upload sukses | berkas baru di-`unlink` (anti-orphan), gambar lama tetap dipakai |
| 6 | Dua baris menunjuk berkas yang sama | guard `is_product_image_referenced()` mencegah penghapusan |
| 7 | Upload + `remove_image` dalam satu submit | upload menang (di-dokumentasikan di hint UI) |
| 8 | Berkas > 2048 KB (8 aset bawaan) | hanya bisa masuk via backfill; script memperingatkan; re-upload via admin akan ditolak |
| 9 | WebP tanpa entry `mimes.php` | dicegah oleh D8 (tanpa itu: selalu gagal) |
| 10 | Double-submit form modal | POST native; tanpa guard (D12) — perilaku lama dipertahankan |
| 11 | Admin meng-upload ke produk yang sudah punya gambar | `UPDATE … WHERE image IS NULL OR image = <pra-rename>` → gambar hasil upload admin tidak pernah ditimpa script |
| 12 | Direktori `uploads/products/` tak ada | pre-flight script exit 1; Upload library gagal → pesan Indonesia (bukan fatal) |
| 13 | XSS / path traversal | `encrypt_name` + basename allowlist + `htmlspecialchars` (alt/aria) + `rawurlencode` (URL) |
| 14 | Tanpa GD/Imagick | tidak ada resize/thumbnail; berkas asli disajikan (didokumentasikan + rekomendasi rekompresi offline) |
| 15 | Baris produk nonaktif tanpa gambar (id 1–4, 16) | tidak relevan bagi member (tidak dirender); fallback hanya terlihat di admin |

---

## 11. Urutan Implementasi (Step-by-Step)

| Phase | Step | Aksi | File |
|---|---|---|---|
| **A** | A1 | Materialisasi blueprint ini (dokumen) | `plan/104_GPU_PRODUCT_IMAGES_AND_ADMIN_UPLOAD_PLAN.md` |
| **B** | B1 | Helper kanonik + registrasi autoload (`'product_image'`) | `application/helpers/product_image_helper.php` (baru), `application/config/autoload.php` |
| | B2 | Entry `webp` (prasyarat mutlak) | `application/config/mimes.php` |
| | B3 | DDL kanonik + blok "MIGRASI LIVE" | `database.sql` |
| | B4 | Kolom `image` pada seed section | `database_seed.sql` |
| **C** | C1 | Script migrasi CLI | `scripts/migrate_104_gpu_product_images.php` (baru) |
| | C2 | Aturan ignore + placeholder direktori | `.gitignore`, `uploads/products/index.html` (baru) |
| | C3 | Eksekusi migrasi | DB live: `--dry-run` → `--apply` → `--verify` |
| **D** | D1 | Whitelist `image` + method `is_product_image_referenced()` | `application/models/Admin_model.php` |
| | D2 | Upload handling create/update + lifecycle unlink + audit | `application/controllers/Admin.php` |
| **E** | E1 | Kolom tabel + `colspan` 9 + `form_open_multipart` + preview + JS | `application/views/admin/products/index.php` |
| **F** | F1 | Kontainer 16:9 + fallback banner | `application/views/marketplace/index.php` |
| **G** | G1 | Sinkronisasi dokumen: bullet kolom `image` | `docs/2_ERD.md` |
| | G2 | Bullet Notes plan/104 (konvensi AGENTS.md) | `AGENTS.md` |
| **H** | H1 | Matriks verifikasi §12 + `php -l` semua file tersentuh | — |
| **I** | I1 | *(pasca-verifikasi)* ringkasan + commit pesan Indonesia di branch fase | `plan/104_..._SUMMARY.md` |

**Urutan aman:** B → C boleh berjalan **sebelum** D–F (kode toleran terhadap kolom absen **dan** berkas hilang). D–F **wajib** setelah B1 (helper) & B2 (`mimes.php`).

---

## 12. Matriks Verifikasi

### 12.1 Statis
- `php -l` untuk: helper baru, `Admin.php`, `Admin_model.php`, `admin/products/index.php`, `marketplace/index.php`, `mimes.php`, `autoload.php`, script migrasi → semua `No syntax errors`.
- `grep -rn placehold.co application/views/marketplace/` → **0** (`templates/header.php:260` sengaja di luar scope).
- `grep -rn "lang(" application/views/admin/products/index.php` → **0** (bukti L1).
- `grep -c "'webp'" application/config/mimes.php` → **1**.
- `php scripts/audit_i18n_parity.php` → **332/332, 0 diff**; `php scripts/audit_i18n_hardcoded.php` → marketplace tetap bersih.

### 12.2 CLI & Database
- `php scripts/migrate_104_gpu_product_images.php --dry-run` → mencetak rencana & snapshot, **tanpa** menulis (bandingkan `SHOW COLUMNS` sebelum/sesudah).
- `--apply` → `SHOW COLUMNS FROM gpu_products LIKE 'image'` = 1 baris `varchar(255)`, `Null = YES`, `Default = NULL`.
- `SELECT COUNT(*) FROM gpu_products WHERE image IS NOT NULL` = **8**.
- `SELECT id, name, image FROM gpu_products WHERE is_active = 1 ORDER BY id` → 8 baris terisi.
- Per baris: `test -f "uploads/products/<nilai image>"` → semua ada (exit 0).
- `--verify` → 8/8 OK, exit 0. Re-run `--apply` → **no-op** (idempoten), exit 0.
- `SELECT COUNT(*) FROM gpu_products WHERE image IS NOT NULL AND id NOT IN (<8 id kanonik>)` = 0 (tidak menyentuh baris lain, termasuk id 16).

### 12.3 Runtime — aset & halaman
- `curl -sI "http://synapse.test/uploads/products/product1-rtx-3060-starter.jpeg"` → `200`, `Content-Type: image/jpeg`.
- `curl -s -o /dev/null -w '%{http_code}' http://synapse.test/marketplace` → 200 (dengan sesi member) / 302 (tanpa sesi, guard login).
- Marketplace (sesi login) → 8 gambar asli, setiap kontainer **16:9**; tidak ada request ke `placehold.co` (cek tab Network).
- `/admin/products` → 8 thumbnail tampil; modal **Edit** menampilkan preview gambar saat ini.

### 12.4 Negatif — fallback
- `UPDATE gpu_products SET image = NULL WHERE id = <salah satu id aktif>` → kartu menampilkan fallback banner gelap (chip GPU), **tanpa** broken-image dan **tanpa** PHP notice di `application/logs/`.
- `UPDATE gpu_products SET image = 'berkas-tidak-ada.jpeg' WHERE id = <id>` → fallback (jalur `is_file()`).
- Kembalikan nilai asli setelah pengujian (verifikasi ulang §12.2).

### 12.5 Admin CRUD gambar (browser)
- Upload PNG 500 KB ke satu paket → flash sukses Indonesia; `SELECT image` berubah; berkas **lama hilang** dari `ls -la uploads/products/`; baris audit terbaru `admin_update_product` memuat `before.image` & `after.image` (`SELECT action, details FROM system_audit_logs ORDER BY id DESC LIMIT 1`).
- Upload gambar ke paket yang belum punya gambar → thumbnail muncul di tabel + preview modal.
- Centang `Hapus gambar saat ini` **tanpa** upload → `image = NULL`, berkas terhapus, fallback muncul di marketplace & admin.
- **Create** paket baru + gambar → baris baru punya `image`; audit `admin_create_product` memuat `after.image`.
- Upload gambar untuk produk dengan `image` yang dipakai bersama baris lain → berkas lama **tidak** dihapus (guard §5).

### 12.6 Negatif — keamanan upload
- `.svg` (walau di-rename `.jpg`, atau MIME dipalsukan) → ditolak, flash Indonesia, nol berkas tersisa.
- `payload.php` / `.phtml` / `.html` → ditolak.
- JPEG 3 MB → ditolak ("maksimal 2 MB"), gambar lama utuh.
- **WebP valid ≤ 2 MB → DITERIMA** (bukti D8 bekerja).
- `ls -la uploads/products/` setelah seluruh pengujian → nol berkas sampah/orphan; nama berkas hasil upload = 32 hex + `.ext`.

### 12.7 Responsif & regresi
- Viewport 360 / 375 / 414 / 480 px: kartu 16:9 utuh, nol scroll horizontal, badge kuota / harga / tombol `.btn-sewa` utuh, bottom-sheet modal tetap berfungsi.
- Tema gelap & terang: gambar tampil normal; **fallback gelap di kedua tema**.
- Smoke test non-regresi: checkout rental (modal + `POST /rentals/checkout`), toggle status produk, `GET /admin/alerts/poll`, `POST /admin/toggle-maintenance`, `GET /lang/switch/en|id` → semua tidak berubah.

---

## 13. Risiko, Asumsi & Follow-up

### 13.1 Risiko & mitigasi

| Risiko | Level | Mitigasi |
|---|---|---|
| `mimes.php` tanpa `webp` → fitur "dukung WebP" gagal total | **tinggi** | D8 wajib + uji positif WebP (§12.6) |
| Aset bawaan 2,1–2,9 MB > batas 2 MB → admin tidak bisa re-upload aset tersebut | **tinggi** | D9 + peringatan di script; dokumentasi; backfill melewati validasi upload |
| Beban 20 MB pada jaringan mobile 360–480 px | **tinggi (UX)** | `loading="lazy"` + `decoding="async"`; rekomendasi rekompresi offline (follow-up #1) |
| Aset di-gitignore → hilang pada clone bersih | sedang | didokumentasikan (D15 + asumsi A2); DB tetap fallback secara graceful |
| Rename berkas saat backfill | rendah | `--dry-run`, idempoten, `--keep-filenames`, `--verify`, exit code 2 |
| Kolom dormant `unlock_prerequisite_id` ikut tersentuh | rendah | whitelist `_sanitize_product_fields` **hanya** ditambah `image` |
| Regresi header (plan/100) / CSS | rendah | nol perubahan `templates/header.php`; hanya utilitas Tailwind |
| Deploy kode sebelum DDL | rendah | guard `?? null` di view & model |

### 13.2 Asumsi

- **A1.** 8 berkas di `uploads/products/` adalah aset final yang dimaksud; angka prefix (`product1..product8`) = urutan lineup kanonik, **bukan** live id.
- **A2.** Aset bersifat runtime dan tidak masuk Git (sesuai brief + aturan `.gitignore`); sumber kanonik disimpan operator di luar repo.
- **A3.** 2752×1536 (≈1,79:1) dianggap 16:9; crop `object-cover` ≤ 1,2% dapat diterima, tanpa re-encode.
- **A4.** Tidak ada GD/Imagick pada host → nol pemrosesan gambar server-side (F13).
- **A5.** Verifikasi admin memakai sesi admin lokal; tidak ada kredensial yang di-commit.
- **A6.** `uploads/` dapat diakses publik tanpa autentikasi (sama seperti `avatars`/`qris`) — gambar katalog produk tidak sensitif.

### 13.3 Follow-up (di luar scope)

1. **Rekompresi 8 aset** ke ±250–400 KB secara offline (`mozjpeg`/`cwebp`) → bobot katalog turun dari 20 MB ke ±2,5 MB (menang terbesar untuk UX mobile).
2. Hardening opsional `uploads/products/.htaccess` (matikan eksekusi skrip) — memerlukan paritas konfigurasi Apache/nginx.
3. Perbaikan mikro opsional: prosa Inggris `display_errors()` pada `Admin::qris_settings()` (pola sama dengan D10).
4. Opsional: indikator "berkas hilang" pada tabel admin (diagnostik, bukan blocker).
5. Opsional: pipeline thumbnail server-side bila GD/Imagick kelak tersedia pada host.
