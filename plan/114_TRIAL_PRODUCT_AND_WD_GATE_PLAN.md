# PLAN 114 — PRODUK TRIAL "GPU MAGANG" + GERBANG PENARIKAN (ANTI FREE-RIDER)

**Status:** DISETUJUI — siap eksekusi langkah E1..E19 (belum ada kode yang ditulis pada fase perencanaan ini)
**Tanggal:** 2026-09-21
**Ruang lingkup:** katalog produk (`gpu_products`) + jalur checkout (`Rental_model`) + gerbang penarikan (`Wallet_model`/`Wallet`) + surface member (marketplace, wallet, rentals, help) + admin product CRUD
**Keputusan owner:** `dec-31e078841e9e27e1`
**Non-goal tunggal yang mengikat:** tidak ada perubahan di bawah `system/`.

---

## 0. Ringkasan eksekutif

Member mendapat **satu** produk trial "GPU Magang": harga **Rp 0**, ROI harian **Rp 10.000**, durasi **3 hari** (total potensi Rp 30.000 lewat mesin klaim ROI yang **sudah ada**), dibatasi **1× per user**. Karena harganya 0, checkout trial **tidak memotong saldo** (tanpa baris `wallet_ledger`), **tidak memicu rebate 3-tier**, dan **tidak menambah omzet** upline. Untuk menutup celah *free-rider* (trial + absensi harian lalu langsung tarik dana), penarikan kini mensyaratkan **riwayat minimal satu kontrak dari produk NON-trial**.

Fitur menyentuh: **1 kolom** `gpu_products` (+ seed 1 baris), **1 fungsi model baru** (`Rental_model::has_paid_rental`), **2 guard admin baru** di `Admin_model`, **1 jalur bebas di `checkout_rental()`**, **3 titik gate di `Wallet_model`/`Wallet`**, **1 skrip migrasi**, **6 kunci kamus**, dan **5 view member + 1 view admin**. Nol route baru, nol tabel baru, nol nilai ENUM baru, nol kunci `system_settings` baru, nol perubahan di `system/`.

### 0.1 Keputusan yang dikonfirmasi owner

| Kode | Keputusan | Nilai yang dipilih |
|---|---|---|
| D-A | Definisi "sewa berbayar" untuk gerbang WD | **Literal spesifikasi: cukup `gpu_products.is_trial = 0`.** Konsekuensi yang **diterima sadar**: kontrak reward promotor (`source='promoter_reward'`, `purchase_price = 0`) atas produk non-trial **ikut membuka** penarikan. |
| D-B | Trial membuka gating referral Condition A? | **Ya** — `get_user_rental_stats()` **tidak diubah**; setelah klaim trial, kode/link/QR undangan langsung terbuka (plan/89). |
| D-C | UX halaman penarikan untuk yang tidak berhak | **Pola existing: redirect `302` + flashdata** ke `/wallet`, plus state tombol *disabled* baru di `/wallet` agar user tidak pernah menabrak dinding. |

---

## 1. Fakta repositori yang diverifikasi (dasar desain)

| # | Fakta | Bukti |
|---|---|---|
| F1 | `gpu_products` = `id, name, image, type, price, daily_rate, duration_days, is_refundable, max_per_user, unlock_prerequisite_id (dormant), is_active, created_at, updated_at` — belum ada penanda trial | `database.sql:46-74` |
| F2 | Lineup kanonik = **id 1–8 eksplisit** (Rp 150.000–10.000.000) dengan upsert `ON DUPLICATE KEY UPDATE` | `database.sql:461-479` |
| F3 | Lineup seed dummy (`database_seed.sql`) = **id 1–4** dengan nama berbeda → **id live ≠ id seed**; preseden migrasi **keyed by `name`** | `database_seed.sql:53-60`; `scripts/migrate_104_gpu_product_images.php` |
| F4 | Peta reward promotor **keyed by `gpu_products.id`** = `5..8` → produk di luar peta tidak bisa ditebus | `application/config/promoter_rewards.php:24-29` |
| F5 | `checkout_rental()`: `trans_begin` → `lock_and_get_balance()` (anchor `users`) → GATE 0 snapshot produk → GATE 2 kuota → tolak overspend → **`debit()`** → insert kontrak → **`_distribute_rebate()`** → commit | `Rental_model.php:90-205` |
| F6 | **`Wallet_model::_post()` MENOLAK `amount <= 0`** (log warning + `return false`) → jalur mana pun yang memanggil `debit(..., 0, ...)` **gagal** dan memaksa rollback penuh | `Wallet_model.php:702-719` |
| F7 | `_distribute_rebate()` sudah aman untuk harga 0: `intdiv(0 * pct, 100) = 0 < 1` → tiap tier `continue` (tanpa baris ledger kosong); engine nonaktif → no-op | `Rental_model.php:572-576, 649-654` |
| F8 | **Tidak ada fungsi `_add_omzet`.** Omzet upline = **derivasi** `SUM(ur.purchase_price)` atas downline L1 → kontrak `purchase_price = 0` menyumbang **0 secara otomatis** | `Promoter_model.php:115-125` |
| F9 | GATE 2 kuota = `COUNT(*) … status IN ('active','completed') AND source <> 'promoter_reward'` → `'cancelled'` membebaskan kuota | `Rental_model.php:123-147`; `Product_model.php:64-74` |
| F10 | `claim_roi()` **tidak bergantung pada harga**: guard = `status='active'` + `claimable_info` (T+1, akumulasi 2 hari) + `UPDATE` kondisional + `credit()` | `Rental_model.php:275-350` |
| F11 | Gerbang WD existing: **controller-only** `has_active_rental()` di `withdraw()` **dan** `process_withdraw()`; otoritas finansial ada di `create_withdrawal()` TX | `Rental_model.php:811-819`; `Wallet.php:359, 447`; `Wallet_model.php:1200-1308` |
| F12 | `create_withdrawal()` TX berisi: lock anchor `users` → validasi binding e-wallet **`FOR UPDATE`** (`code='no_ewallet'`) → kebijakan operasional → min/max → saldo → pending → daily limit → insert + `debit()` | `Wallet_model.php:1220-1304` |
| F13 | Pemetaan kode → kamus terpusat: `WD_ERR_KEYS` + `_wd_message()`; kode di luar peta jatuh ke `wd_err_process_failed` | `Wallet.php:26-47, 88-106, 536` |
| F14 | `/wallet` sudah punya 3 state tombol WD (pending → perlu sewa aktif → batas harian → link), sumber `has_active_rental` | `Wallet.php:131-133`; `views/wallet/index.php:35-51` |
| F15 | `views/wallet/withdraw.php`: notice server-rendered + `#wd_submit` di-*disable* JS saat di luar jam operasional (pola notice yang bisa ditiru) | `views/wallet/withdraw.php:15-20, 48-52, 240-266` |
| F16 | Kamus **621 baris `$lang[...]`** per idiom (hitung `grep`), paritas 1:1; gate `P3` hanya menandai **`Rp` + digit**, `P5` menolak nilai EN≡ID di luar allowlist, `P6` newline/atribut | `scripts/audit_i18n_parity.php:73-85, 97-140, 158` |
| F17 | Admin product CRUD: `_validate_product_payload()` menolak `price` dengan `^[1-9][0-9]*$` (**0 ditolak**), modal `f_price` `min="1" required`, payload modal JSON dibangun di view, audit before/after dari `_product_payload_from_row()` | `Admin.php:2437-2490, 2505-2517`; `views/admin/products/index.php:132-146, 226` |
| F18 | Kontrak skrip migrasi: `--dry-run` (default)/`--apply`/`--verify`, pre-flight `information_schema`, idempoten, tamper → exit 2, exit code 0/1/2; BASEPATH+ENVIRONMENT didefinisikan, DB config dibaca dari `application/config/database.php` | `scripts/migrate_112_daily_checkin.php:1-95` |
| F19 | `wallet_ledger` = append-only immutable, `amount` selalu > 0; `RBT-{rental}-L{tier}` = id komisi, `ROI-{rental}-D{n}` = id ROI | `database.sql:197-204`; `Wallet_model.php:708-719` |
| F20 | Admin panel 100% Indonesia dan **dikecualikan** dari gate i18n → literal Indonesia di `views/admin/**` sah | `scripts/audit_i18n_hardcoded.php:41-46` |
| F21 | `Rental_model::create_rental()` (baris 26) **tanpa pemanggil** — dead code, bukan jalur yang dipakai UI | `grep -rn "create_rental(" application/` → hanya definisi |

---

## 2. Keputusan desain (D1–D10)

| # | Keputusan | Alasan / trade-off |
|---|---|---|
| **D1** | Identifikasi trial = **kolom baru `gpu_products.is_trial TINYINT(1) NOT NULL DEFAULT 0`**, bukan "harga == 0" | Flag eksplisit = auditable, bisa ditoggle admin, dan memberi invarian yang bisa diverifikasi migrasi. "Harga 0" adalah kecelakaan ekonomi (promo berbayar bisa berharga 0), bukan identitas. |
| **D2** | Penanda trial **TIDAK** masuk sebagai nilai ENUM `user_rentals.source` | `source` adalah ENUM di tabel kontrak & dibaca oleh predikat kuota per-kanal (K4, F9). Menambah nilai ENUM = `ALTER` pada tabel yang menyentuh uang + menyentuh setiap pembaca. Kontrak trial tetap `source='purchase'` → kuota/ROI/semantik lain utuh. |
| **D3** | Aktivasi trial = **jalur bebas di dalam TX `checkout_rental()`**: `debit()` **tidak dipanggil** (F6) dan `_distribute_rebate()` **tidak dipanggil** | Wajib, bukan preferensi: `_post()` menolak `amount <= 0`, jadi `debit(…, 0, …)` akan mengembalikan `false` dan membatalkan seluruh checkout. Ledger tetap murni (tanpa baris 0 IDR) → Z1/M8 utuh. |
| **D4** | Bypass rebate = **lompati pemanggilan**, bukan mengandalkan guard internal | Engine memang sudah no-op untuk harga 0 (F7), tapi melompati menghindari traversal upline + query kelayakan per tier yang pasti menghasilkan 0 IDR, dan membuat niat terbaca eksplisit di kode. |
| **D5** | Omzet **tidak perlu di-bypass** | Requirement menyebut `_add_omzet` — **fungsi itu tidak ada** (F8). Omzet L1 adalah `SUM(purchase_price)`; kontrak trial bernilai 0 menyumbang 0 otomatis. Deviasi redaksi requirement ini **dinyatakan terbuka**, bukan substitusi senyap. |
| **D6** | Predikat gerbang WD = `JOIN gpu_products … WHERE p.is_trial = 0` (keputusan owner D-A) | Literal sesuai permintaan. **Tanpa filter `status`** — sesuai semantik "riwayat pernah membayar" (berbeda dari predikat kuota D1/plan-83 yang berbicara tentang *kepemilikan saat ini*). Kontrak `'cancelled'` karena itu **tetap** membuka gerbang; alasan dicatat di §9. |
| **D7** | Otoritas gerbang = **di dalam TX `create_withdrawal()`**, cermin UX di controller | Konvensi existing yang sudah tertulis di kode (F12, komentar `:1189-1191`). Read view dibuat **setelah** lock wait → admin yang meng-`cancel_rental` bersamaan tidak bisa menembus gate. |
| **D8** | UX = **redirect + flashdata** (keputusan owner D-C) + state tombol disabled baru di `/wallet` | Konsisten dengan 4 gatekeeper existing; user melihat alasan sebagai flash di `/wallet`, dan tombolnya sudah menyatakan terkunci sebelum diklik. |
| **D9** | Admin product CRUD **wajib** mengenal `is_trial` (bukan opsional) | Tanpa itu admin **tidak bisa meng-edit** produk trial sama sekali: `_validate_product_payload()` menolak `price = 0` dan modal memaksa `min="1"` (F17) → form edit selalu gagal. Ditambah dua guard anti-penyalahgunaan (satu trial global; produk dengan pembeli berbayar tidak boleh di-flip jadi trial). |
| **D10** | **Tanpa index baru** | Predikat gate terlayani leftmost prefix `idx_user_status_expired (user_id, …)` + PK join `gpu_products.id`. `is_trial` tidak perlu index (katalog puluhan baris, difilter setelah join). |

---

## 3. Perubahan skema

### 3.1 `database.sql` — `CREATE TABLE gpu_products` (blok `:46-74`)

```sql
  -- plan/83: gating & per-user purchase limits engine.
  -- 0 = unlimited; N >= 1 = lifetime rental cap per user.
  `max_per_user` INT UNSIGNED NOT NULL DEFAULT 0,
  -- plan/114: penanda PRODUK TRIAL ("GPU Magang"). 1 = produk trial
  -- (activation hook) — harga WAJIB 0, `max_per_user` WAJIB 1, dan checkout
  -- TIDAK memotong saldo (tanpa baris `wallet_ledger`), TIDAK memicu rebate
  -- 3-tier, dan TIDAK menambah omzet upline (derivasi SUM(purchase_price) = 0).
  -- Invarian: TEPAT SATU baris dengan is_trial = 1 (dijaga admin CRUD +
  -- `scripts/migrate_114_*.php --verify`). Gerbang penarikan
  -- (`Rental_model::has_paid_rental`) memakai kolom ini via JOIN.
  `is_trial` TINYINT(1) NOT NULL DEFAULT 0 AFTER `max_per_user`,
```

### 3.2 `database.sql` — seed `gpu_products` (blok `:461-479`)

Tambah kolom `is_trial` pada daftar kolom + nilai `0` untuk id 1–8, dan **satu baris baru id 9**; tambahkan pula `is_trial = VALUES(is_trial)` ke `ON DUPLICATE KEY UPDATE`:

```sql
INSERT INTO `gpu_products` (`id`, `name`, `type`, `price`, `daily_rate`, `duration_days`, `is_refundable`, `max_per_user`, `unlock_prerequisite_id`, `is_active`, `is_trial`) VALUES
(1, 'RTX 3060 Starter', 'short_term',  150000.00,   7500.00, 25, 0, 1, NULL, 1, 0),
-- … id 2–8 tidak berubah, hanya ditambah nilai `is_trial = 0` di akhir …
(8, 'H200 Sovereign',   'long_term', 10000000.00, 560000.00, 60, 0, 0, NULL, 1, 0),
-- plan/114: PRODUK TRIAL ("GPU Magang") — Rp 0 / Rp 10.000 per hari / 3 hari
-- (total potensi Rp 30.000), 1× per user. id 9 SENGAJA di luar peta reward
-- promotor (id 5–8, `application/config/promoter_rewards.php`).
(9, 'GPU Magang (Trial)', 'short_term', 0.00, 10000.00, 3, 0, 1, NULL, 1, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `type` = VALUES(`type`),
  `price` = VALUES(`price`),
  `daily_rate` = VALUES(`daily_rate`),
  `duration_days` = VALUES(`duration_days`),
  `is_refundable` = VALUES(`is_refundable`),
  `max_per_user` = VALUES(`max_per_user`),
  `unlock_prerequisite_id` = NULL,
  `is_active` = VALUES(`is_active`),
  `is_trial` = VALUES(`is_trial`);
```

> `image` sengaja **tidak** disertakan (konsisten dengan blok ini hari ini) → `NULL` → marketplace merender fallback banner plan/104, bukan gambar rusak.

### 3.3 `database_seed.sql` — SECTION `gpu_products` (`:46-60`)

Baris trial dengan `id = 9` dan `image = NULL` (mengikuti bentuk blok yang sudah ada), plus `is_trial = VALUES(is_trial)` pada `ON DUPLICATE KEY UPDATE`. Re-run seed tetap menormalkan baris trial tanpa menyentuh `is_active`/`max_per_user` produk lain (perilaku existing dipertahankan).

### 3.4 `database.sql` — blok "MIGRASI LIVE" baru (pola `:546-…`)

```sql
-- Plan 114 — MIGRASI LIVE (one-time; jalankan manual di DB aktif).
-- Kolom `is_trial` + seed produk trial sudah masuk blok CREATE TABLE/seed di
-- atas (instalasi baru otomatis). Untuk DB yang SUDAH ADA gunakan tool:
--   php scripts/migrate_114_trial_product_wd_gate.php --dry-run  # inspeksi
--   php scripts/migrate_114_trial_product_wd_gate.php --apply    # DDL + seed + verify
--   php scripts/migrate_114_trial_product_wd_gate.php --verify    # read-only; exit 2 bila drift
--   1) ALTER TABLE `gpu_products`
--        ADD COLUMN `is_trial` TINYINT(1) NOT NULL DEFAULT 0 AFTER `max_per_user`;
--      -- TANPA backfill: DEFAULT 0 mengisi seluruh baris lama (semua non-trial).
--   2) INSERT … ON DUPLICATE KEY UPDATE `gpu_products` DIKUNCI OLEH `name`
--        ('GPU Magang (Trial)') — id live ≠ id seed (preseden plan/104).
--   3) Verifikasi invarian (WAJIB 1 baris / 0 inconsistent):
--        SELECT COUNT(*) FROM `gpu_products` WHERE `is_trial` = 1;          -- = 1
--        SELECT COUNT(*) FROM `gpu_products`
--         WHERE `is_trial` = 1 AND (`price` <> 0 OR `max_per_user` <> 1);   -- = 0
-- ⚠️ URUTAN RILIS: migrasi ini WAJIB jalan SEBELUM kode baru di-deploy
--    (query gerbang penarikan membaca `p.gpu_products.is_trial`).
```

### 3.5 `scripts/migrate_114_trial_product_wd_gate.php` (baru — kontrak F18)

```
--dry-run (default) | --apply | --verify | --help        exit 0 / 1 / 2
1 PRE-FLIGHT : tabel `gpu_products` & `user_rentals` ada; kolom anchor
               `max_per_user` ada (dipakai AFTER); `promoter_rewards.php`
               dapat di-require (untuk guard tier).
2 INSPECT    : kolom `is_trial` ada/belum; baris trial (by `name`) ada/belum;
               jumlah baris `is_trial = 1` saat ini.
3 DDL        : ALTER TABLE `gpu_products` ADD COLUMN `is_trial`
               TINYINT(1) NOT NULL DEFAULT 0 AFTER `max_per_user`
               (dijaga information_schema → idempoten; TANPA backfill).
4 SEED       : upsert DIKUNCI `name` = 'GPU Magang (Trial)' dengan
               type='short_term', price=0, daily_rate=10000, duration_days=3,
               is_refundable=0, max_per_user=1, is_active=1, is_trial=1.
               Baris lain TIDAK disentuh (id auto bila tabrakan `name`).
5 GUARD      : (a) tepat SATU baris `is_trial = 1`;
               (b) id baris trial ∉ kunci peta `promoter_rewards.php`;
               (c) invarian baris trial: is_trial=1, price=0, daily_rate=10000,
                   duration_days=3, max_per_user=1, is_active=1
               → pelanggaran = tamper → exit 2.
6 VERIFY     : tipe/nullability/default kolom = tinyint(1) / NOT NULL / 0
               + ulangi 5(a)–(c). Re-run `--apply` = no-op ("sudah ada (dibiarkan)").
```

Berkas ini **hanya membaca** config (`promoter_rewards.php`, `database.php`) dan **tidak pernah** menyentuh `user_rentals`/`wallet_ledger`.

---

## 4. Logika checkout — bypass rebate & omzet

### 4.1 Choke-point tunggal: `Rental_model::checkout_rental()` (`:90-205`)

| Langkah | Sekarang | Setelah plan/114 |
|---|---|---|
| 1 | `lock_and_get_balance()` — anchor `users` | **tetap** (anchor serialisasi anti-race, tetap wajib walau gratis) |
| 2 | GATE 0 snapshot produk | **`+ p.is_trial`** pada `SELECT`; snapshot tetap otoritatif (anti POST-tamper) |
| 2b | — | **BARU — guard fail-closed** (lihat 4.3) |
| 3 | GATE 2 kuota `max_per_user` | **tetap, tanpa perubahan** → trial = 1× per user lewat mekanisme yang sudah teruji |
| 4 | Tolak overspend `fresh_balance < price` | **dilewati bila jalur bebas** (tetap dijalankan untuk jalur berbayar) |
| 5 | `Wallet_model::debit(...)` | **DILEWATI pada jalur bebas** (F6: `amount <= 0` → `false` → rollback) |
| 6 | `INSERT user_rentals` | **tetap**, `purchase_price = 0`, `source` default `'purchase'`, `expired_at = now + 3 hari` |
| 7 | `_distribute_rebate(...)` | **DILEWATI pada jalur bebas** (D4) |
| 8 | `trans_commit()` | **tetap** |

Bentuk kode (kerangka, bukan tambalan setengah jadi):

```php
// GATE 0 (plan/83, retained) — snapshot SEGAR di dalam TX terkunci.
$product = $this->db->query(
    "SELECT p.id, p.name, p.price, p.daily_rate, p.duration_days,
            p.is_active, p.max_per_user, p.is_trial
       FROM gpu_products p
      WHERE p.id = ?",
    [(int) $product['id']]
)->row_array();
// … guard is_active seperti sekarang …

// plan/114 — klasifikasi jalur (fail-closed, lihat §4.3).
$price      = (int) $product['price'];
$is_trial   = ((int) $product['is_trial'] === 1);
$free_trial = ($is_trial && $price === 0);

if (!$free_trial && $price < 1) { /* rollback → 'product_unavailable' */ }

// … GATE 2 kuota (tidak berubah) …

// 4. Penolakan overspend — hanya jalur berbayar.
if (!$free_trial && $fresh_balance < $price) { /* rollback → 'insufficient' */ }

// 5. Debit — DILEWATI untuk trial gratis (M8: _post() menolak <= 0).
if (!$free_trial) {
    if (!$this->Wallet_model->debit($user_id, $price, 'RENT-' . …, 'Sewa ' . $product['name'])) { … }
}

// 6. Kontrak sewa — SELALU dibuat (trial pun kontrak normal).
// 7. Rebate — DILEWATI untuk trial gratis (harga 0 → 0 IDR untuk semua tier;
//    omzet upline = SUM(purchase_price) = 0 otomatis, tanpa fungsi _add_omzet).
if (!$free_trial) {
    if (!$this->_distribute_rebate($user_id, $price, $rental_id)) { … }
}
```

Ringkas: **dua `if (!$free_trial)`** yang membungkus `debit()` (langkah 5) dan `_distribute_rebate()` (langkah 7). Tidak ada perubahan pada `claim_roi()`, GATE 2, atau `lock_and_get_balance()`.

### 4.2 Kenapa omzet memang sudah 0

`Promoter_model::get_omzet_summary()` (F8) menghitung `COALESCE(SUM(ur.purchase_price), 0)`. Kontrak trial `purchase_price = 0` ⇒ **omzet upline tidak bergerak**, termasuk untuk `available`/`redeemable` (burn/lock tidak terpengaruh). Nol kode omzet yang perlu diubah — ini **pernyataan**, bukan asumsi: akan dibuktikan di verifikasi **V8**.

### 4.3 Guard fail-closed (anti penyalahgunaan & anti DDL-belum-sinkron)

| Kondisi baris produk | Perlakuan | Alasan |
|---|---|---|
| `is_trial = 1` **dan** `price = 0` | jalur bebas (tanpa debit, tanpa rebate) | definisi trial |
| `is_trial = 1` **dan** `price > 0` | **jalur berbayar normal** + `log_message('warning', …)` | tidak pernah memberi kontrak gratis; data aneh tetap tidak eksploitatif |
| `is_trial = 0` **dan** `price = 0` | **TOLAK** (`code = 'product_unavailable'`) | produk berharga 0 non-trial tidak boleh melahirkan kontrak gratis (tamper/DDL drift) |
| selain itu | perilaku existing (tidak berubah) | zero-regression |

---

## 5. Gerbang penarikan (anti free-rider)

### 5.1 `Rental_model::has_paid_rental($user_id): bool` (baru)

Diletakkan tepat setelah `has_active_rental()` (`:811-819`) — domain sewa, SQL di model (rule 2):

```php
/**
 * plan/114 — Gerbang penarikan: user wajib punya RIWAYAT kontrak dari produk
 * NON-trial (keputusan owner D-A: predikat literal `is_trial = 0`).
 *
 * Sengaja TANPA filter `status` — beda dari predikat kuota GATE 2 (plan/83 D1)
 * yang berbicara tentang KEPEMILIKAN SAAT INI. Di sini yang diuji adalah
 * RIWAYAT pernah bertransaksi produk berbayar, sehingga `'cancelled'` dan
 * `'completed'` tetap memenuhi (soft-cancel admin tanpa refund ≠ batalnya
 * status pelanggan). Konsekuensi diterima owner: kontrak reward promotor
 * (`source='promoter_reward'`, purchase_price 0) atas produk non-trial
 * ikut membuka gerbang.
 */
public function has_paid_rental($user_id) {
    $row = $this->db->query(
        "SELECT 1
           FROM user_rentals ur
           JOIN gpu_products p ON p.id = ur.product_id
          WHERE ur.user_id = ?
            AND p.is_trial = 0
          LIMIT 1",
        [(int) $user_id]
    )->row();
    return $row !== null;
}
```

### 5.2 `Wallet_model::create_withdrawal()` — OTORITAS di dalam TX (`:1200-1308`)

Sisipan baru sebagai **langkah 1b**, langsung setelah validasi binding e-wallet `FOR UPDATE` (langkah 1a) dan sebelum kebijakan operasional (1c) — jadi read view-nya dibuat **setelah** lock wait:

```php
// 1b. plan/114 — GERBANG ANTI FREE-RIDER (otoritas, di dalam TX terkunci).
//     Trial + absensi harian tidak boleh langsung dicairkan: penarikan
//     mensyaratkan riwayat >= 1 kontrak dari produk non-trial. Read view
//     dibuat SETELAH lock wait → admin yang meng-cancel sewa bersamaan tidak
//     bisa menembus gate. Cermin UX (controller) hanya untuk pesan cepat.
if (!$this->Rental_model->has_paid_rental($user_id)) {
    $this->db->trans_rollback();
    return ['success' => false, 'code' => 'no_paid_rental',
            'message' => 'Anda harus menyewa minimal 1 produk berbayar untuk dapat melakukan penarikan.',
            'wd_number' => null];
}
```

`Wallet_model` **belum** memuat `Rental_model` → tambahkan `$this->load->model('Rental_model');` di `Wallet_model::__construct()` (pola cross-model loader yang sudah dipakai di repo, mis. `Notification_model` di `Rental_model`). Hasil: **nol baris** `withdrawals` dan **nol baris** `wallet_ledger` pada penolakan (imutabilitas utuh, Z1).

### 5.3 `Wallet` controller — cermin UX + pemetaan kamus

| Lokasi | Perubahan |
|---|---|
| `Wallet::WD_ERR_KEYS` (`:26-41`) | `+ 'no_paid_rental' => 'wd_err_no_paid_rental',` (jaring pengaman bila model mengembalikan kode ini lewat jalur hasil) |
| `Wallet::withdraw()` GET | **Gatekeeper 2b** — setelah gate `has_active_rental()` (`:359-363`), sebelum daily limit: `if (!$this->Rental_model->has_paid_rental($user_id)) { flashdata('error', lang('wd_err_no_paid_rental')); redirect('wallet'); return; }` |
| `Wallet::process_withdraw()` POST | **Gatekeeper yang sama**, setelah `:447-451`: menutup jalur POST langsung/`curl` yang melewati UI |
| `Wallet::index()` (`:132`) | `+ 'has_paid_rental' => $this->Rental_model->has_paid_rental($user_id),` → dipakai state tombol baru di view |

Urutan gatekeeper final: **pending WD → sewa aktif → sewa berbayar (BARU) → batas harian → binding e-wallet → batas jam/hari → min/max → saldo → `create_withdrawal()`**.

---

## 6. UI/UX & penjelasan aturan ke user

### 6.1 `views/wallet/index.php` (`:35-51`) — state tombol baru

Cabang baru **setelah** `empty($has_active_rental)` (sewa aktif lolos tapi berbayar belum → tombol terkunci):

```php
<?php elseif (empty($has_paid_rental)): ?>
<button disabled class="flex-1 bg-slate-400 text-white text-sm font-bold py-2.5 rounded-xl cursor-not-allowed opacity-60">
    <i class="fas fa-lock mr-1"></i> <?= lang('wallet_wd_need_paid') ?>
</button>
```

User **tidak pernah** menabrak dinding: tombolnya sudah menyatakan terkunci, dan bila tetap memaksa `/wallet/withdraw` ia menerima flash `wd_err_no_paid_rental` di `/wallet` (pola D-C). Batas jelas: trial aktif namun belum pernah beli → terkunci.

### 6.2 `views/wallet/withdraw.php` — tidak dirender untuk yang tidak berhak

Sesuai keputusan owner, halaman ini **tidak** mendapat banner baru: gatekeeper GET/POST mencegahnya dirender bagi user tak berhak (redirect + flash). Tombol `#wd_submit` dan notice jam operasional tidak disentuh. Keuntungan: surface yang sudah teruji (F15) tidak dibongkar.

### 6.3 `views/marketplace/index.php` — kartu trial jujur

- Chip **`lang('market_trial_badge')`** di samping chip kuota (`:100-107`) bila `(int) $product['is_trial'] === 1`.
- Harga tetap dirender lewat jalur uang existing (`Rp <?= number_format(...) ?>` → `Rp 0`), ditambah label `lang('market_trial_free')` agar "Rp 0" tidak terbaca sebagai bug.
- Kuota: `market_rent_limit_max` yang ada sudah menampilkan "Maks. 1 (sisa 1)" → **tidak perlu kunci baru**.
- `Product_model::get_catalog_for_user()` memakai `SELECT p.*` (F9) → `is_trial` **sudah** ikut terkirim: **nol perubahan model untuk marketplace**.
- Modal konfirmasi: untuk `price = 0` cek `userBalance >= 0` selalu lolos → **tanpa perubahan JS** (jalur "saldo kurang" mustahil tercapai). Dicatat sebagai keputusan, bukan kelalaian.

### 6.4 `views/rentals/index.php` — chip trial di daftar sewa

Chip `lang('rental_trial_badge')` di kartu kontrak bila `purchase_price == 0` (`:168` tetap menampilkan `Rp 0`). Rendah risiko, mencegah "kok harga sewaku Rp 0".

### 6.5 `views/help/index.php` + kamus — FAQ "Mengapa saya tidak bisa menarik dana?"

Sisipkan `help_q_wd_fail_req6` di akhir daftar (setelah `req5`). Catatan penting: daftar ini **bahkan belum menyebut** syarat sewa aktif, jadi bullet ini sekaligus memperbaiki akurasi dokumentasi member — dan menyebut *harga* tanpa literal `Rp <digit>` agar gate P3 tetap LULUS (nominal lewat argumen, L6).

### 6.6 Admin (`views/admin/products/index.php` + `Admin`) — 100% Indonesia (L1)

| Titik | Perubahan |
|---|---|
| Modal (`:226`) | Field baru: checkbox **"Produk Trial (harga Rp 0)"** (`name="is_trial" id="f_trial"`); JS: saat dicentang → `f_price.value = 0`, `f_price.readOnly = true`, `f_quota.value = 1`; saat dilepas → buka kembali, `min="1"` |
| Payload modal (`:132-146`) | `+ 'is_trial' => (int) ($p->is_trial ?? 0)` (dengan `??` untuk kompatibilitas DDL-belum-jalan) |
| Daftar produk (`:104-110`) | Chip **`TRIAL`** di samping chip `short/long term` (tanpa kolom/colspan baru) |
| `Admin::_validate_product_payload()` | `is_trial` dari POST; `price` = `^(0|[1-9][0-9]*)$` **bila** `is_trial=1`, selain itu tetap `^[1-9][0-9]*$`; guard Indonesia: "Produk trial wajib berharga Rp 0." / "Produk trial wajib dibatasi 1 per user." / "Hanya satu produk trial yang diizinkan." / "Produk sudah memiliki kontrak berbayar — tidak dapat dijadikan trial." |
| `Admin::_product_payload_from_row()` (`:2505-2517`) | `+ 'is_trial' => (int) $row->is_trial` → audit `admin_create_product`/`admin_update_product` otomatis memuat `is_trial` (before/after) |
| `Admin_model` (2 read baru) | `count_other_trial_products($except_id)` + `product_has_paid_rentals($product_id)` (`SELECT 1 FROM user_rentals WHERE product_id = ? AND purchase_price > 0 LIMIT 1`) — **SQL tetap di model** (rule 2) |

Guard "produk dengan pembeli berbayar tidak boleh di-flip jadi trial" adalah mitigasi **edge case D6**: tanpa itu, mem-flag produk lama sebagai trial akan **mencabut hak penarikan** para pembelinya secara retroaktif (predikat gate membaca `is_trial` saat ini, bukan snapshot kontrak).

---

## 7. i18n — 6 kunci baru (paritas 1:1)

| Kunci | ID | EN |
|---|---|---|
| `wd_err_no_paid_rental` | `Anda harus menyewa minimal 1 produk berbayar untuk dapat melakukan penarikan.` | `You must rent at least 1 paid product before you can make a withdrawal.` |
| `wallet_wd_need_paid` | `Perlu Sewa Berbayar` | `Paid Rental Required` |
| `market_trial_badge` | `Uji Coba` | `Trial` |
| `market_trial_free` | `Gratis` | `Free` |
| `rental_trial_badge` | `Kontrak Uji Coba` | `Trial Contract` |
| `help_q_wd_fail_req6` | `<strong>Sewa berbayar</strong> - Anda harus pernah menyewa minimal satu produk berbayar. Produk trial gratis tidak membuka akses penarikan.` | `<strong>Paid rental</strong> - you must have rented at least one paid product. The free trial product does not unlock withdrawals.` |

Kepatuhan gate: **P5** — semua nilai EN ≠ ID (`market_trial_badge` sengaja `Uji Coba`/`Trial` agar tidak identik; allowlist **tidak** ditambah, mengikuti preseden plan/106 yang justru menghapus entri). **P3** — tidak ada `Rp` + digit di nilai mana pun (pesan wajib owner bebas dari `Rp`; `help_q_wd_fail_req6` memakai kata, bukan nominal). **P6** — tanpa newline literal; `help_q_wd_fail_req6` memakai `<strong>` seperti `req1–req5`. Pesan spesifik owner dipertahankan **verbatim** di ID. Angka final kamus (621 → 627 per idiom) **wajib dibuktikan** oleh `audit_i18n_parity.php`, bukan diklaim.

Surface admin **tidak** menambah kunci (L1). `views/admin/**` dikecualikan dari `audit_i18n_hardcoded.php` (F20).

---

## 8. Non-goals (eksplisit di luar scope)

1. Tidak ada perubahan di bawah `system/`; nol route baru; nol tabel baru; nol nilai ENUM baru; nol kunci `system_settings` baru.
2. `claim_roi()`, mesin rebate (`_distribute_rebate`), `Promoter_model` (omzet = derivasi), dan `Checkin_model` **tidak diubah**.
3. `get_user_rental_stats()` **tidak diubah** → trial **tetap** membuka gating referral (keputusan owner D-B).
4. Tidak ada gate baru pada **deposit/top-up** (user gratis tetap boleh mengisi saldo).
5. Gate WD lain (mis. minimum saldo, usia akun) tidak ditambah.
6. `Rental_model::create_rental()` (dead code, F21) tidak disentuh — bukan jalur UI.
7. Tidak ada batas khusus "user baru" berbasis umur akun: produk trial dirender untuk **semua** member aktif dengan batas **1× lifetime** (mekanisme `max_per_user`, sesuai requirement) — lihat §13 O3.
8. Komisi rebate/ROI promotor atas produk trial tidak dibuat (tidak ada dasar ekonomi).

---

## 9. Matriks edge case

| # | Skenario | Penanganan |
|---|---|---|
| E1 | Dua tab checkout trial bersamaan | Anchor `FOR UPDATE` pada `users` menserialkan; pemanggil kedua melihat `own_count = 1` → `quota_exceeded` |
| E2 | `price = 0` **non-trial** (tamper / DDL drift) | **TOLAK** `product_unavailable` (§4.3) — jalur gratis hanya untuk `is_trial = 1` |
| E3 | `is_trial = 1` tapi `price > 0` | Jalur berbayar normal + log warning — tidak pernah memberi kontrak gratis |
| E4 | Kuota trial dibebaskan admin (`cancel_rental`) → user klaim trial lagi | Perilaku **disengaja** (predikat GATE 2 plan/83 mengabaikan `'cancelled'`); konsisten dengan seluruh katalog. Alternatif (memasukkan `'cancelled'`) mengubah semantik kuota global → di luar scope |
| E5 | Trial kadaluarsa | Sweep lazy `expire_user_rentals()` → `status='completed'`; trial **tidak pernah** membuka gerbang WD (fail-closed by design) |
| E6 | ROI trial diklaim hari ke-1..3 | `ROI-{rental_id}-D{n}` lewat `claim_roi` **tanpa perubahan** (F10); maksimum 3 × Rp 10.000 |
| E7 | User trial mencoba WD di hari ke-2 (punya saldo check-in + ROI) | **DIBLOKIR**: gerbang `has_paid_rental` di TX + gatekeeper GET/POST → redirect `/wallet` + flash |
| E8 | Admin mengubah `max_per_user` trial menjadi 0/2 | **DITOLAK** validasi admin ("Produk trial wajib dibatasi 1 per user.") → mencegah trial tak terbatas |
| E9 | Admin membuat trial kedua | **DITOLAK** ("Hanya satu produk trial yang diizinkan.") |
| E10 | Admin mem-flag produk yang sudah punya kontrak berbayar sebagai trial | **DITOLAK** (guard `product_has_paid_rentals`) — mencegah pencabutan hak WD retroaktif |
| E11 | Data diubah manual via SQL: 2 baris `is_trial = 1` | `migrate_114 --verify` → **exit 2** (tamper terdeteksi) |
| E12 | DB live belum dimigrasi, kode sudah deploy | Query gate membaca kolom yang belum ada → error. **Mitigasi: urutan rilis DDL-dulu** (§3.4 + R1) |
| E13 | User tanpa satu pun `user_rentals` | `has_paid_rental()` → `false` tanpa error (JOIN kosong); tombol WD terkunci |
| E14 | Upline inaktif saat downline klaim trial | Tidak relevan: `_distribute_rebate` **tidak dipanggil** → tanpa breakage/notifikasi |
| E15 | Skrip `scripts/seed_withdraw_test_account.php` | Tetap sah: kontraknya dari produk non-trial → `has_paid_rental()` = `true`; **tidak diubah** |
| E16 | User banned | `MY_Controller` mengalihkan sebelum body; gerbang TX tetap berlaku (defense in depth) |

---

## 10. Directives eksekusi (berurutan)

| # | Langkah | Berkas |
|---|---|---|
| **E1** | DDL kanonik + seed produk trial (id 9) + blok MIGRASI LIVE plan/114 | `database.sql` |
| **E2** | Seed data dummy: baris trial id 9 + `is_trial = VALUES(is_trial)` | `database_seed.sql` |
| **E3** | Skrip migrasi 6 fase (kontrak F18, keyed by `name`, guard tier promotor) | `scripts/migrate_114_trial_product_wd_gate.php` **(baru)** |
| **E4** | Jalur bebas + guard fail-closed di `checkout_rental()`; GATE 0 menyertakan `p.is_trial` | `application/models/Rental_model.php` |
| **E4b** | *(disetujui — perbaikan laten)* `quota_exceeded` → kembalikan `'max' => $max` dari model **dan** petakan `'quota_exceeded' => 'rental_err_max_per_user'` di controller. Tanpa ini, penolakan kuota (termasuk batas trial 1×) hanya menampilkan pesan generik "checkout gagal" — pesan yang salah untuk requirement headline "Strictly 1 per user" | `Rental_model.php`, `application/controllers/Rentals.php` |
| **E5** | `has_paid_rental($user_id)` baru (JOIN, predikat literal owner) | `application/models/Rental_model.php` |
| **E6** | Cek 1b `no_paid_rental` di dalam TX + `load->model('Rental_model')` di konstruktor | `application/models/Wallet_model.php` |
| **E7** | `WD_ERR_KEYS` + gatekeeper 2b di `withdraw()` & `process_withdraw()` + view var `has_paid_rental` | `application/controllers/Wallet.php` |
| **E8** | 6 kunci kamus (ID & EN, paritas 1:1) | `application/language/{indonesian,english}/app_lang.php` |
| **E9** | State tombol disabled baru | `application/views/wallet/index.php` |
| **E10** | Chip trial + label gratis di kartu produk | `application/views/marketplace/index.php` |
| **E11** | Chip trial di kartu kontrak | `application/views/rentals/index.php` |
| **E12** | Bullet requirement ke-6 di FAQ penarikan | `application/views/help/index.php` |
| **E13** | `is_trial` di validasi + payload audit; 2 read guard di model; checkbox + chip + JS di modal | `application/controllers/Admin.php`, `application/models/Admin_model.php`, `application/views/admin/products/index.php` |
| **E14** | `php -l` **setiap** berkas PHP baru/berubah → wajib bersih | — |
| **E15** | `php scripts/audit_i18n_parity.php` → "LULUS"; `php scripts/audit_i18n_hardcoded.php` → "0 temuan" (keduanya exit 0) | — |
| **E16** | Migrasi: `--dry-run` → `--apply` → `--apply` ulang (**no-op**) → `--verify` (exit 0) | — |
| **E17** | Verifikasi runtime V1–V10 (§10.1) + regresi flow berbayar | — |
| **E18** | `plan/114_TRIAL_PRODUCT_AND_WD_GATE_SUMMARY.md` (bukti nyata: perintah + hasil) | `plan/` |
| **E19** | *(opsional)* Sinkronisasi `docs/1_PRD.md`, `docs/2_ERD.md`, `docs/3_ROADMAP.md` — repo terbiasa memisahkannya (plan/111 & 113) → disarankan **plan terpisah** | `docs/` |

### 10.1 Matriks verifikasi runtime (bukti yang akan dilampirkan)

| # | Skenario | Ekspektasi terukur |
|---|---|---|
| V1 | `GET /marketplace` sebagai user baru | Kartu "GPU Magang (Trial)" tampil: chip Uji Coba, `Rp 0`, kuota Maks. 1 |
| V2 | `POST /rentals/checkout` trial (user baru) | `302 → /rentals`; **1** baris `user_rentals` (`purchase_price = 0`, `source='purchase'`, `expired_at = +3 hari`); **0** baris `wallet_ledger` baru; `users.balance` **tidak berubah** |
| V3 | Checkout trial ke-2 (user sama) | Ditolak; kuota 1× tegas + pesan `rental_err_max_per_user` (E4b) |
| V4 | `GET /wallet` (trial aktif, belum pernah beli) | Tombol WD **disabled** + label `Perlu Sewa Berbayar` |
| V5 | `POST /wallet/process_withdraw` (bypass UI, saldo cukup dari check-in/ROI) | `302 → /wallet`; flash `wd_err_no_paid_rental`; **0** baris `withdrawals` & `wallet_ledger` |
| V6 | Harness CLI `/tmp` memanggil `Wallet_model::create_withdrawal()` langsung | `{success:false, code:'no_paid_rental'}`; 0 baris tertulis |
| V7 | Beli produk berbayar → WD normal | `302` sukses; `withdrawals` + `wallet_ledger` debit seperti biasa (**regresi nol**) |
| V8 | Checkout trial oleh downline dengan upline L1–L3 aktif | **0** baris `wallet_ledger` berprefix `RBT-`; **0** notifikasi `notif_rebate`; `Promoter_model::get_omzet_summary(upline)['total']` **tidak berubah** |
| V9 | ROI hari ke-1 trial | `ROI-{rental_id}-D1` = Rp 10.000 (1 baris kredit) |
| V10 | Migrasi idempoten & tamper | `--apply` re-run = no-op; tamper 2× `is_trial=1` → `--verify` exit **2** |

---

## 11. Risiko & mitigasi

| # | Risiko | Mitigasi |
|---|---|---|
| R1 | **Kode deploy sebelum DDL** → query gerbang membaca `is_trial` yang belum ada → 500 di `/wallet` & `/wallet/withdraw` | Urutan rilis diwajibkan: migrasi 114 **dulu**, kode kemudian (§3.4); payload admin memakai `??` agar tahan DDL-belum-jalan |
| R2 | Reward promotor produk non-trial membuka gerbang WD (D-A) | Konsekuensi **diterima owner**; jalur penutupnya dicatat sebagai **O1** (`AND ur.purchase_price > 0`) |
| R3 | Penyalahgunaan multi-akun (1 trial per akun) | Batas 1× per user + gerbang WD (kontrol utama) + `uk_phone` pada `users` (1 akun per nomor HP) |
| R4 | Admin membuat trial tak terbatas / trial ganda / flip produk berbayar | Tiga guard validasi admin (§6.6, E8–E10) — pesan Indonesia (L1) |
| R5 | Ledger tercemar baris 0 IDR bila jalur bebas salah | `_post()` menolak `<= 0` (F6) → mustahil; V2 memverifikasi ledger tetap kosong untuk checkout trial |
| R6 | Omzet/rebate ikut terpicu | Derivasi `SUM(purchase_price)` (F8) + `_distribute_rebate` dilewati → dibuktikan V8 |
| R7 | Pesan trial-quota generik (bug laten) | E4b (disetujui) — bukan bug baru |
| R8 | Pelanggaran gate i18n | 6 kunci dirancang lolos P3/P5/P6; E15 wajib "LULUS"/"0 temuan" sebelum dinyatakan selesai |
| R9 | Klaim tanpa bukti (runtime tidak bisa dijalankan di environment tertentu) | Summary E18 **wajib** mencatat perintah yang benar-benar dijalankan + hasil apa adanya, dan menyatakan eksplisit langkah yang tidak dapat dijalankan |

---

## 12. Daftar berkas yang akan tersentuh

**Baru (2):** `scripts/migrate_114_trial_product_wd_gate.php`, `plan/114_TRIAL_PRODUCT_AND_WD_GATE_SUMMARY.md`
**Skema (2):** `database.sql`, `database_seed.sql`
**Model (3):** `application/models/Rental_model.php`, `application/models/Wallet_model.php`, `application/models/Admin_model.php`
**Controller (3):** `application/controllers/Wallet.php`, `application/controllers/Rentals.php` (E4b), `application/controllers/Admin.php`
**Kamus (2):** `application/language/indonesian/app_lang.php`, `application/language/english/app_lang.php`
**View (5 member + 1 admin):** `views/wallet/index.php`, `views/marketplace/index.php`, `views/rentals/index.php`, `views/help/index.php`, `views/admin/products/index.php`
**Tidak disentuh:** `system/**`, `application/config/**`, `application/config/routes.php`, `views/wallet/withdraw.php`, `Claim/Checkin/Promoter` model, `Rental_model::create_rental()` (dead code)

---

## 13. Keputusan lanjutan (status setelah persetujuan plan)

| # | Item | Status / default yang dipakai |
|---|---|---|
| **O1** | Menutup celah reward promotor pada gerbang WD (predikat ketat `AND ur.purchase_price > 0`) — kontradiksi dengan D-A | **DITUTUP: ikut D-A apa adanya** (literal `is_trial = 0`). Perubahan hanya bila owner meminta eksplisit di lain waktu |
| **O2** | E4b (perbaikan pesan kuota `quota_exceeded`) — masuk plan ini atau plan terpisah | **DITERIMA: masuk sebagai E4b** |
| **O3** | Apakah trial hanya untuk "user baru" (berbasis umur akun / rentang registrasi)? | **Semua member aktif, 1× lifetime** (tanpa kolom/logika umur akun) |
| **O4** | E11 (`rental_trial_badge` di `/rentals`) & E12 (FAQ `req6`) — kosmetik/dokumentasi | **DIKERJAKAN** (rendah risiko, menambah kejujuran UI) |

---

## 14. Aturan pelaporan (definition of done)

1. Setiap berkas PHP baru/ubah lolos `php -l`.
2. `audit_i18n_parity.php` → **LULUS**; `audit_i18n_hardcoded.php` → **0 temuan** (exit 0), dijalankan setelah perubahan kamus/view.
3. Migrasi dibuktikan idempoten: `--apply` → `--apply` (no-op) → `--verify` (exit 0).
4. Flow diuji runtime (V1–V10) atau, bila environment menghalangi, dinyatakan eksplisit beserta perintah yang siap dijalankan.
5. `plan/114_TRIAL_PRODUCT_AND_WD_GATE_SUMMARY.md` memuat matriks bukti (perintah + hasil nyata); `docs/` disinkronkan lewat plan terpisah bila diperlukan.
6. Diff akhir hanya berisi perubahan yang diniatkan — tanpa skrip scratch/artefak sementara di repo.
