# Plan 91 — Program Promoter: Reward GPU via Omzet Burn Bertingkat & Persetujuan Manual Admin

> **Status:** BLUEPRINT (dokumen arsitektur SAJA). Belum ada perubahan kode,
> model, controller, view, atau skema DB yang dilakukan oleh dokumen ini —
> eksekusi menunggu instruksi lanjutan terpisah.
> **Keputusan pengguna (dec-6d14b1039ad8cc30):**
> 1. **Kuota reward INDEPENDEN dari kuota pembelian berbayar** — kedua kanal
>    dibatasi oleh `gpu_products.max_per_user` yang sama, tetapi dihitung
>    terpisah dan dibedakan lewat kolom baru `user_rentals.source`. Promoter
>    yang pernah menyewa paket dengan dananya sendiri tetap bisa menebus
>    reward tier tsb; spam redeem tier rendah tetap terbatas oleh kuotanya.
> 2. **Demosi promotor TIDAK membatalkan klaim pending** — hanya mencabut
>    bypass gating referral (Condition A) dan memblokir pengajuan BARU.
>    Klaim pending yang sudah masuk tetap berada di antrean admin untuk
>    keputusan manusia eksplisit (approve/reject).

---

## 1. Ringkasan & Tujuan

Program **Promoter/Influencer** memberi hadiah berupa **kontrak sewa GPU
bernilai penuh (Rp 0 di muka)** kepada member yang menghasilkan volume sewa
(*omzet*) dari **downline langsung (L1)** mereka. Hadiah ditebus dengan
membakar omzet yang sudah diraih — model **Omzet Burn (Redeemable Quota)** —
sehingga biaya akuisisi (cost-of-acquisition) platform terkunci ketat di
kisaran **8%–10%** dari omzet L1.

| Aspek | Nilai |
|---|---|
| Kanal akuisisi | Downline L1 (`users.parent_id = promotor`) |
| Sumber omzet | `user_rentals.purchase_price`, `status IN ('active','completed')` |
| Mata uang | IDR integer murni (M8) |
| Reward | Kontrak `user_rentals` zero-cost (`purchase_price = 0`, `source = 'promoter_reward'`), ROI harian penuh, durasi standar produk |
| Mutasi `wallet_ledger` saat penerbitan | **NOL** (tidak ada deposit/debit) |
| Persetujuan | Manual oleh admin (queue `/admin/promoter-claims`) |
| Target CAC | 8%–10% dari omzet L1 per klaim (guard keras, lihat §5.4) |

Efek sengaja dari kontrak reward: karena kontrak tsb adalah `user_rentals`
aktif biasa, kontrak **secara inheren mengaktifkan kelayakan rebate 3-tier**
promotor (syarat menerima komisi `RBT-` adalah ≥ 1 kontrak aktif, plan/89)
dan ikut dalam seluruh gate "aktif" lain yang konsisten (M3).

---

## 2. Keputusan Arsitektur & Invariant yang Dipegang

| Kode | Keputusan / Invariant | Sumber |
|---|---|---|
| K1 | `is_promoter` adalah flag admin-only di `users`; **tanpa self-service** | Spek |
| K2 | Omzet dihitung dari **pembelian kontrak** downline L1, **bukan** deposit wallet | Spek §L1 Omzet |
| K3 | Redeemable = Total L1 − Burned(approved); pending **mengunci** omzet tsb | Spek |
| K4 | Kuota **independen per kanal**: `paid_count ≤ max_per_user` DAN `reward_count ≤ max_per_user` (predikat D1: `status IN ('active','completed')`, `cancelled` dikecualikan) — dibedakan via `source` | dec-6d14b1039ad8cc30 |
| K5 | **Guard rasio CAC 8–10%** dihitung dari harga produk *saat ini* vs `omzet_cost`, aritmetika integer murni (tanpa float); di luar rentang → tolak | Spek "strictly maintain" |
| K6 | Demosi: cabut bypass + blokir submit baru; **pending tetap processable** | dec-6d14b1039ad8cc30 |
| K7 | Penerbitan reward: `purchase_price = 0`, `source='promoter_reward'`, snapshot `daily_roi`/`total_days`/`expired_at` saat approve; **tanpa** panggil `_distribute_rebate` (harga 0 → komisi 0; kontrak reward bukan "pembelian" untuk upline) | Arsitektur |
| K8 | Seluruh transaksi money-adjacent memakai pola ACID C5/M8/M9 yang ada | plan/48, 74, 76 |
| Z1 | `wallet_ledger` immutable & satu-satunya sumber kebenaran finansial — program ini **tidak menulis ledger** saat burn/redeem | AGENTS.md |
| C4 | Mutasi uang (ROI kontrak reward nanti, via klaim biasa) hanya lewat `Wallet_model::credit()/debit()` | plan/54 |
| C5 | Setiap TX submit/approve/reject membuka dengan anchor `users` `FOR UPDATE` (statement pertama), urutan lock konsisten | plan/48 |
| M3 | Kelayakan "aktif" selalu memfilter `expired_at > ?` bound param PHP WIB | plan/60 |
| M5/A1 | Audit `system_audit_logs` atomik **dalam TX yang sama** untuk toggle flag & approve/reject klaim | plan/64 |
| M8 | Uang IDR integer: `(int)` choke-point, `intdiv()`, validasi `^[1-9][0-9]*$` untuk input | plan/74 |
| M9/P7 | Endpoint AJAX member lewat `api_success()/api_error()` | plan/76 |
| M4 | Mutator admin POST-only fail-closed; flip status kondisional `affected_rows()===1` | plan/62 |

---

## 3. Desain Database & Migrasi

### 3.1 `users.is_promoter` (flag)

```sql
-- Migrasi DB aktif (idempotent-safe; jalankan sekali):
ALTER TABLE `users`
  ADD COLUMN `is_promoter` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_banned`;
```

- `0` = member biasa (default, backfill otomatis semua baris lama), `1` = promotor.
- Tanpa indeks tersendiri (selalu dibaca bersama `id`); `level_id` tidak dipakai ulang.
- **Bypass gating Condition A** (lihat §9.1.1): saat `is_promoter = 1`, kode
  undangan / link / QR terbuka permanen di `/team` dan dashboard **walau
  `lifetime_rentals = 0`**.
- Di `database.sql`: tambahkan kolom pada `CREATE TABLE users` agar instalasi
  bersih sinkron (posisi & default identik).

### 3.2 `user_rentals.source` (origin kontrak)

```sql
ALTER TABLE `user_rentals`
  ADD COLUMN `source` ENUM('purchase','promoter_reward') NOT NULL DEFAULT 'purchase'
  AFTER `product_id`;
```

- `'purchase'` = kontrak dari `checkout_rental()` / inject admin (default,
  backfill otomatis seluruh baris lama via `DEFAULT` pada ALTER).
- `'promoter_reward'` = kontrak reward zero-cost dari program ini (satu-satunya
  penulis eksplisit nilai ini).
- Kolom inilah pembeda **kuota per kanal (K4)** dan memastikan baris reward
  **tidak** mengonsumsi kuota pembelian berbayar (lihat perubahan GATE 2 §5.3).
- Sinkronkan `CREATE TABLE user_rentals` di `database.sql`.

### 3.3 `promoter_claims` (tabel baru — lifecycle klaim)

```sql
CREATE TABLE `promoter_claims` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL COMMENT 'Promotor pemohon',
  `product_id` INT UNSIGNED NOT NULL COMMENT 'Produk reward (harus di peta tier)',
  `omzet_cost` INT UNSIGNED NOT NULL COMMENT 'Omzet L1 yang dibakar (integer IDR, M8)',
  `status`     ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_id`   INT UNSIGNED DEFAULT NULL COMMENT 'Admin yang approve/reject',
  `admin_notes` VARCHAR(255) DEFAULT NULL COMMENT 'Catatan (wajib diisi saat reject via UI)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_status`  (`user_id`, `status`),
  INDEX `idx_status_created` (`status`, `created_at`),
  INDEX `idx_product_id`   (`product_id`),
  CONSTRAINT `fk_promoter_claims_user`    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_promoter_claims_product` FOREIGN KEY (`product_id`)
    REFERENCES `gpu_products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_promoter_claims_admin`   FOREIGN KEY (`admin_id`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Catatan desain:
- `omzet_cost` **INT UNSIGNED** (integer IDR murni, konsisten M8) — bukan
  DECIMAL; kolom baru tidak terikat kompatibilitas lama.
- Tidak ada kolom "value/nilai reward": nilai nominal = harga produk saat
  approve (snapshot); rasio diverifikasi via K5.
- Tidak ada kolom `rental_id` hasil: dicatat di `details` audit
  (`promoter_claim_approved`), cukup untuk rekonsiliasi.
- `status` **terminal**: `approved`/`rejected` tidak punya transisi lanjut.
- Tidak ada partial unique index — anti-double-spend dijamin **logika TX
  (lock + flip kondisional)**, bukan constraint (lihat §7).
- Sinkronkan `CREATE TABLE` ke `database.sql` (kanonik, di antara
  `user_notifications` dan `system_settings` sesuai urutan ketergantungan FK).

### 3.4 Nol perubahan `wallet_ledger` & tabel lain

- **Tidak ada** kolom/tabel baru di `wallet_ledger`, `withdrawals`, `deposits`,
  `system_settings` (peta tier statis, §5.2 — bukan setting admin dinamis M7).
- Kredit ROI dari kontrak reward memakai jalur klaim ROI existing
  (`ROI-{rental_id}-D{...}`), bukan jalur baru.

---

## 4. Mesin Omzet L1 (Redeemable Quota / Burn Model)

### 4.1 Definisi & formula

```
Omzet L1 (total)     = Σ purchase_price  user_rentals milik L1 LANGSUNG promotor
                       (users.parent_id = promotor.id)
                       status IN ('active','completed')          -- 'cancelled' TIDAK dihitung
Burned               = Σ omzet_cost      promoter_claims         -- status = 'approved'
Locked (pending)     = Σ omzet_cost      promoter_claims         -- status = 'pending'

Redeemable           = max(0, Total − Burned)                    -- saldo yang bisa ditukar
Available            = max(0, Redeemable − Locked)               -- untuk gate submit/approve
```

- **Pending mengunci omzet**: setiap submit menambah `Locked`, sehingga dua
  klaim konkuren tidak bisa membakar omzet yang sama (§7). Reject melepas lock
  secara alami (baris tidak lagi `pending`).
- **Floor 0**: `Total` dapat turun setelah burn (pembatalan/reparent downline,
  §10 E6/E7) — saldo tidak pernah negatif; burn yang sudah terjadi tidak ditarik.
- **Baris reward downline ikut terjumlah 0** (`purchase_price = 0`), sehingga
  reward tidak menciptakan omzet baru bagi upline (anti-kompon K7).

### 4.2 Query inti (semua bound param; waktu WIB dari PHP)

```php
// Di dalam TX (setelah anchor users FOR UPDATE):
$total = (int) $this->db->query(
    "SELECT COALESCE(SUM(ur.purchase_price), 0) AS total
       FROM user_rentals ur
       JOIN users u ON u.id = ur.user_id
      WHERE u.parent_id = ? AND ur.status IN ('active','completed')",
    [(int) $promoter_id]
)->row()->total;

$burned = (int) $this->db->query(
    "SELECT COALESCE(SUM(omzet_cost), 0) AS total
       FROM promoter_claims WHERE user_id = ? AND status = 'approved'",
    [(int) $promoter_id]
)->row()->total;

$locked = (int) $this->db->query(
    "SELECT COALESCE(SUM(omzet_cost), 0) AS total
       FROM promoter_claims WHERE user_id = ? AND status = 'pending'",
    [(int) $promoter_id]
)->row()->total;
```

- `purchase_price` DECIMAL di-`(int)` kan **sebelum** aritmetika (M8) — nilai
  tersimpan selalu integer IDR.
- Predikat omzet = **persis** predikat GATE 2 (D1) minus filter `source`
  (omzet tidak membedakan sumber; harga 0 sudah menetralkan reward).

### 4.3 Anti race

Semua perhitungan omzet hanya dipercaya **di dalam TX** setelah anchor
`users` `FOR UPDATE` (serialisasi per promotor). Nilai yang ditampilkan di
halaman member bersifat indikatif; otoritatif adalah rekomputasi saat
submit/approve (§7).

---

## 5. Reward Packages, Threshold & Kuota

### 5.1 Peta tier kanonik (nilai = `gpu_products.price` saat ini)

| Tier | Produk (id) | Nilai reward (harga) | Threshold omzet (`omzet_cost`) | Rasio CAC |
|---|---|---|---|---|
| 1 | RTX 3060 Starter (1) | Rp 150.000 | Rp 1.500.000 | 10,0% |
| 2 | RTX 4060 Lite (2) | Rp 300.000 | Rp 3.500.000 | 8,57% |
| 3 | RTX 4070 Basic (3) | Rp 600.000 | Rp 7.000.000 | 8,57% |
| 4 | RTX 4080 Prime (4) | Rp 1.200.000 | Rp 15.000.000 | 8,0% |

- Pemetaan merujuk **id produk** (bukan nama) agar tahan rename.
- Durasi & ROI harian kontrak reward = snapshot `duration_days` / `daily_rate`
  produk **saat approve** (pariti GATE 0 checkout).

### 5.2 Representasi kanonik (bukan setting admin)

File baru `application/config/promoter_rewards.php` (pola
`rebate_commission.php` plan/89 — fallback statis bertipe, tanpa UI admin M7):

```php
// product_id => omzet_cost (integer IDR) — peta tier reward promoter.
$config['promoter_rewards'] = [
    1 => 1500000,   // RTX 3060 Starter  — Tier 1 (10,0%)
    2 => 3500000,   // RTX 4060 Lite     — Tier 2 (8,57%)
    3 => 7000000,   // RTX 4070 Basic    — Tier 3 (8,57%)
    4 => 15000000,  // RTX 4080 Prime    — Tier 4 (8,0%)
];
```

Model membaca + memvalidasi peta ini (integer positif); tier tanpa produk aktif
otomatis "tidak tersedia" di UI member.

### 5.3 Kuota per kanal (K4) — termasuk perubahan GATE 2 checkout

Dua penghitung **independen**, keduanya ≤ `max_per_user` (0 = tanpa batas),
predikat D1 (`status IN ('active','completed')`, `cancelled` tidak memakan
kuota), dibedakan `source`:

```sql
-- Kanal berbayar (dipakai GATE 2 checkout & tampilan marketplace):
SELECT COUNT(*) FROM user_rentals
 WHERE user_id = ? AND product_id = ?
   AND source <> 'promoter_reward'
   AND status IN ('active','completed');

-- Kanal reward (dipakai gate submit/approve klaim):
SELECT COUNT(*) FROM user_rentals
 WHERE user_id = ? AND product_id = ?
   AND source = 'promoter_reward'
   AND status IN ('active','completed');
```

Konsekuensi implementasi penting (efek keputusan dec-6d14b1039ad8cc30):

1. **GATE 2 `Rental_model::checkout_rental()` (baris ~123–144) harus menjadi
   source-aware**: tambah `AND source <> 'promoter_reward'` pada COUNT kuota —
   jika tidak, baris reward akan "memakan" jatah pembelian berbayar dan
   melanggar K4. Predikat D1 lain tidak berubah.
2. **Penghitung kuota yang ditampilkan** marketplace/`Product_model`
   (dan `Admin_model::get_user_rentals`) disesuaikan bila menampilkan
   sisa kuota — sumber-aware agar angka konsisten dengan gate.
3. Batas `max_per_user` contoh (seed): RTX 3060=1, 4060=2, 4070=3, 4080=5 →
   promotor tetap bisa menebus Tier-1 walau sudah membeli 1 unit sendiri
   (kanal terpisah), tetapi redeem reward Tier-1 maksimal 1× (kuota reward 1).

### 5.4 Guard rasio CAC 8–10% (K5)

Saat **submit** dan **approve**, validasi rasio dengan integer murni (tanpa
float, M8):

```
rasio_ok  ⇔  8 · omzet_cost ≤ 100 · price_current ≤ 10 · omzet_cost
```

- `price_current` = `gpu_products.price` saat itu (snapshot read terkunci).
- Gagal → tolak dengan pesan sistem (submit) / blokir tombol approve dengan
  pesan ke admin (mis. harga produk diedit lewat admin product CRUD plan/85
  hingga rasio keluar dari 8–10%) — admin harus menyesuaikan harga/map.
- Ini menjaga "strictly maintain 8%–10%" sebagai invariant platform, bukan
  sekadar dokumentasi.

---

## 6. Claim Lifecycle & State Machine

```
                submit (member, is_promoter=1, gate lengkap)
                              │
                              ▼
   ┌─────────────────────────────────────────────┐
   │  pending  (omzet_cost DIKUNCI; menunggu     │── reject (admin + alasan) ──► rejected
   │            admin)                            │      (lock LEPAS otomatis)
   └─────────────────────────────────────────────┘
                              │ approve (admin: verifikasi ulang, burn efektif,
                              │         terbitkan kontrak zero-cost, audit)
                              ▼
                          approved   (terminal; tidak bisa diubah lagi)
```

Aturan transisi:

| Transisi | Pelaku | Prasyarat | Efek |
|---|---|---|---|
| → `pending` | Promotor (member) | `is_promoter=1`, tidak banned, produk di peta & `is_active=1`, rasio K5, `reward_count < max`, `Available ≥ omzet_cost` | Baris claims + lock omzet |
| `pending` → `approved` | Admin | Klaim masih `pending` (flip kondisional), produk masih ada & aktif, rasio K5, `reward_count < max`, `Total − Burned − Locked_lain ≥ omzet_cost` | Burn efektif; kontrak reward aktif; audit + notifikasi; lock menjadi burn |
| `pending` → `rejected` | Admin | Klaim masih `pending` (flip kondisional) | `admin_notes` tersimpan; audit + notifikasi; lock lepas |

**Terminal:** `approved` & `rejected` tidak memiliki aksi lanjut (tidak ada
cancel/batalkan klaim). Koreksi atas kekeliruan approve dilakukan lewat
jalur admin existing (`cancel_rental` kontrak reward, dengan konsekuensi
§10 E6), bukan lewat status klaim.

### 6.1 Submit (member) — alur

1. `trans_begin()`.
2. **Anchor**: `Wallet_model::lock_and_get_balance($user_id)` (statement
   pertama, serialisasi per promotor; nilai balance diabaikan).
3. Gate identitas segar (bukan session): `is_promoter = 1`, `is_banned = 0`
   (K6: demosi yang terjadi sebelum TX ini otomatis memblokir submit).
4. Gate produk: ada di peta config, `is_active = 1`, rasio K5.
5. Rekomputasi omzet (§4.2) → `Available ≥ omzet_cost`.
6. Kuota reward kanal: `reward_count < max_per_user` (bila `max > 0`).
7. `INSERT promoter_claims (user_id, product_id, omzet_cost, status='pending')`.
8. `trans_commit()` → respons JSON M9 (`api_success`), refresh UI.

Kegagalan → `trans_rollback()` + `api_error` dengan pesan Indonesia yang
spesifik (saldo omzet kurang / kuota penuh / produk nonaktif / bukan promotor).

### 6.2 Approve (admin) — alur ACID

1. `trans_begin()`.
2. Baca klaim (consistent read) → ambil `user_id`, `product_id`, `omzet_cost`;
   bila tidak ditemukan → error.
3. **Anchor**: `lock_and_get_balance((int)$user_id)` (users `FOR UPDATE`,
   statement pertama setelah baca identitas klaim — urutan lock konsisten:
   **users sebelum baris klaim**, lihat §7).
4. `SELECT ... FROM promoter_claims WHERE id=? AND user_id=? FOR UPDATE` →
   verifikasi `status = 'pending'` (bila sudah approved/rejected → rollback,
   pesan "sudah diproses").
5. Gate produk (current read terkunci): ada, `is_active=1`, rasio K5.
6. Rekomputasi omzet (§4.2, current read setelah lock) → pastikan
   `Total − Burned − Locked_lain ≥ omzet_cost` klaim ini (mengantisipasi
   penurunan `Total` sejak submit, E6/E7).
7. Kuota reward kanal: `reward_count < max_per_user`.
8. **Flip kondisional**: `UPDATE promoter_claims SET status='approved',
   admin_id=?, admin_notes=? WHERE id=? AND status='pending'` →
   `affected_rows() === 1` wajib (anti double-click dua admin).
9. Snapshot produk terkunci → `INSERT user_rentals`:
   ```php
   [
     'user_id'        => (int) $user_id,
     'product_id'     => (int) $product_id,
     'source'         => 'promoter_reward',
     'purchase_price' => 0,                              // ZERO-COST (K7)
     'daily_roi'      => (int) $product->daily_rate,     // ROI penuh
     'total_days'     => (int) $product->duration_days,
     'status'         => 'active',
     'expired_at'     => date('Y-m-d H:i:s', strtotime('+' . (int) $product->duration_days . ' days')),
   ]
   ```
   **Tidak ada** `Wallet_model->credit()/debit()`, tidak ada baris
   `wallet_ledger` (Z1 — tidak ada uang masuk/keluar).
   **Tidak memanggil** `_distribute_rebate()` (bukan pembelian; harga 0 →
   komisi upline = 0 dengan sendirinya, K7).
10. **Audit atomik** dalam TX sama: `_write_audit` / `Audit_model` dengan
    aksi `promoter_claim_approved` (§8).
11. **Notifikasi member** dalam TX sama (pola M5/N2, §8).
12. `trans_commit()` → flash sukses admin + redirect queue.

### 6.3 Reject (admin) — alur

1–4 sama dengan approve (TX + lock urutan sama).
5. Ambil `admin_notes` (trim, `mb_substr` ≤ 255; dari UI wajib diisi untuk
   reject — kolom tetap nullable di DB sesuai spek).
6. Flip kondisional → `status='rejected', admin_id, admin_notes` dengan
   `WHERE id=? AND status='pending'`, `affected_rows()===1`.
7. Audit atomik `promoter_claim_rejected` + notifikasi member (type
   `warning`, sertakan alasan).
8. `trans_commit()`.

Tidak ada penulisan tambahan: **lock omzet lepas dengan sendirinya** karena
baris tidak lagi `pending` (formula §4.1).

---

## 7. Konkurensi & Integritas

| Skenario | Mekanisme |
|---|---|
| Dua submit klaim bersamaan (promotor sama, omzet nyaris habis) | Anchor `users` `FOR UPDATE` statement pertama → serialisasi per promotor. Submit kedua menunggu commit submit pertama; rekomputasi `Available` (current read) melihat pending pertama → ditolak bila omzet tak cukup. Bebas deadlock (satu lock, urutan tunggal). |
| Double-click approve / dua admin approve klaim sama | Baca identitas → anchor users → `FOR UPDATE` baris klaim → flip **kondisional** `WHERE status='pending'` + gate `affected_rows()===1`. Admin kedua mendapat "sudah diproses", tanpa kontrak ganda. |
| Approve klaim berbeda, promotor sama, bersamaan | Serialisasi di anchor users → urutan deterministik; lock kedua (baris klaim) hanya dipegang setelah anchor → tidak ada deadlock. |
| Submit vs approve promotor sama bersamaan | Submit pegang anchor users lalu insert baris baru; approve pegang anchor users lalu lock baris klaim existing. Lock baris klaim tidak pernah diminta sebelum anchor → lintasan lock konsisten (users → claim). |
| Sweep expiry (M3) & klaim ROI berjalan atas kontrak reward | Kontrak reward adalah `user_rentals` biasa; `expire_user_rentals()` & `claim_roi()` sudah race-safe (UPDATE kondisional + gate sendiri). Tidak ada interaksi baru. |
| Kehilangan koneksi / exception | `try/catch(Throwable)` + `trans_rollback()` menyeluruh (pola `checkout_rental`/`approve_withdrawal`). |
| Idempotensi audit & notifikasi | Ditulis dalam TX yang sama dengan flip status; rollback membatalkan semuanya; flip kondisional menjamin eksekusi tunggal. |

Urutan lock **wajib** (dokumentasikan di kode): `users (anchor) → promoter_claims
(FOR UPDATE) → user_rentals (insert)`. Anchor memakai
`Wallet_model::lock_and_get_balance()` yang sudah ada agar seragam dengan C5 —
nilai kembalian diabaikan pada alur yang tidak menyentuh saldo.

---

## 8. Audit & Notifikasi

### 8.1 Skema aksi audit (`system_audit_logs`, ditulis ATOMik dalam TX)

| Aksi | Actor | Subject (`user_id`) | `details` (JSON) |
|---|---|---|---|
| `admin_toggle_promoter` | Admin | Promotor/member | `{new_state: 'promoter'|'member'}` (before→after) |
| `promoter_claim_approved` | Admin | Promotor | `{claim_id, product_id, product_name, omzet_cost, rental_id}` |
| `promoter_claim_rejected` | Admin | Promotor | `{claim_id, product_id, omzet_cost, admin_notes}` |

- Toggle flag memakai pola `toggle_ban` (controller `trans_start` +
  `Audit_model::log_admin_action` + flash; M4 POST-only).
- Approve/reject memakai pola `approve_withdrawal`/`decline_withdrawal`
  (TX dikelola model + `_write_audit($audit)` di dalam TX, audit ctx dari
  `Admin::_audit_ctx()`).
- Baris otomatis muncul di halaman `/admin/audit` existing (filter aksi).

### 8.2 Notifikasi member (`user_notifications`, dalam TX — M5/N2)

| Momen | Type | Contoh pesan |
|---|---|---|
| Approve | `success` | "Reward Promotor Cair — Kontrak RTX 4080 Prime (reward) telah diaktifkan di akun Anda. Klaim ROI harian dimulai H+1." |
| Reject | `warning` | "Klaim Reward Ditolak — RTX 4070 Basic. Alasan: {admin_notes}. Omzet Anda telah dikembalikan ke saldo redeemable." |
| Toggle → promotor | `info` | "Status Promotor Aktif — kode undangan Anda terbuka. Ajak downline dan kumpulkan omzet L1 untuk reward GPU." |
| Toggle → member (demosi) | `info` | "Status Promotor Dicabut — pengajuan klaim baru ditutup; klaim pending tetap diproses admin." |

- Submit klaim tidak membuat notifikasi (cukup respons AJAX + riwayat klaim).

---

## 9. UI Placement

### 9.1 Member

#### 9.1.1 Bypass Condition A (gating referral) — file tersentuh

`referral_locked` saat ini = `lifetime_rentals === 0`, dihitung di
`Team::index()` (baris ±24) dan `Home::index()` (dashboard, kartu identitas,
plan/89). Rumus baru (K1):

```php
$referral_locked = ((int) $rental_stats['lifetime_rentals']) === 0
                && ((int) $user->is_promoter) !== 1;   // promotor: bypass permanen
```

- `users.is_promoter` otomatis terbawa oleh `get_user_by_id()`/query profil
  yang ada (SELECT kolom penuh) — verifikasi saat implementasi.
- Kode/link/QR kembali terkunci otomatis saat flag dicabut dan lifetime = 0
  (K6: bypass dicabut seketika; tanpa perlu tindakan lain).

#### 9.1.2 Hub Promoter di `/team`

Section baru **"Program Promotor"** (render hanya bila `is_promoter = 1`):
- Statistik live (dari `Promoter_model::get_omzet_summary()`):
  Omzet L1 total, Terpakai (burned), Terkunci (pending), **Tersedia** (available);
  jumlah downline L1 & L1 aktif.
- Kartu 4 tier (dari config): nama produk, nilai reward, threshold omzet,
  progress `available / omzet_cost`, status chip (Tersedia / Kuota penuh /
  Produk nonaktif / Omzet kurang) + tombol **"Klaim Reward"**.
- Modal konfirmasi → POST AJAX → respons JSON M9 → refresh statistik +
  notifikasi hasil; riwayat klaim member (10–20 terakhir) dengan chip status.
- Teks panduan singkat + disclaimer "reward dikenakan persetujuan admin".

#### 9.1.3 Dashboard (`Home::index`)

Kartu ringkas **"Program Promotor"** (is_promoter = 1): omzet tersedia + CTA
ke `/team` (#promoter). Bukan duplikasi penuh — hindari beban query.

### 9.2 Admin

#### 9.2.1 Toggle promotor (user management)

- Tombol/switch di `admin/user_detail` (utama) + aksi cepat di list
  `admin/users`: **"Jadikan Promotor" / "Cabut Promotor"** (POST-only,
  pola `toggle_ban`); konfirmasi modal menampilkan peringatan demosi (K6).
- Tampilkan chip "PROMOTOR" di list & detail; audit otomatis (§8).

#### 9.2.2 Queue `/admin/promoter-claims`

- Halaman baru `Admin::promoter_claims()` (GET): tab filter
  (Semua/Pending/Approved/Rejected), pencarian via phone promotor, pagination
  (pola `Admin::users`), item sidebar baru **"Klaim Promoter"** (badge jumlah
  pending opsional).
- Setiap baris: promotor (phone + chip promotor/banned), produk reward,
  `omzet_cost` (format IDR), tanggal submit, status, admin + catatan;
  **telemetri downline** (summary omzet & jumlah L1 — panggil
  `Promoter_model::get_omzet_summary()` + hitung L1) agar review manual
  (anti volume sintetis E9) cepat.
- Aksi per baris pending:
  - **Approve**: POST `Admin::approve_promoter_claim($claim_id)` — POST-only
    M4, redirect + flash (pola approve/decline withdrawal); di belakang layar
    alur §6.2.
  - **Reject**: POST `Admin::reject_promoter_claim($claim_id)` — modal alasan
    (wajib) → `admin_notes`.
- Produk nonaktif / rasio keluar rentang / omzet turun → approve ditolak
  sistem dengan pesan; admin memutuskan reject atau perbaiki produk dulu.

### 9.3 Routes (`application/config/routes.php`)

```php
// Member (AJAX claim — alias rute, atau method URL langsung OK):
$route['promoter/claim'] = 'team/promoter_claim';

// Admin queue (pretty URL wajib — pola plan/85):
$route['admin/promoter-claims'] = 'admin/promoter_claims';
$route['admin/promoter-claims/approve/(:num)'] = 'admin/approve_promoter_claim/$1';
$route['admin/promoter-claims/reject/(:num)']  = 'admin/reject_promoter_claim/$1';
```

---

## 10. Edge Cases & Mitigasi

| # | Kasus | Mitigasi |
|---|---|---|
| E1 | Dua submit klaim bersamaan (double-spend omzet) | Anchor users `FOR UPDATE` + rekomputasi `Available` di TX; pending kedua ditolak bila omzet tak cukup (§7). |
| E2 | Double-click / dua admin approve atau reject klaim sama | Flip kondisional `WHERE status='pending'` + `affected_rows()===1`; pelaku kedua mendapat pesan "sudah diproses" (pariti withdrawal). |
| E3 | **Promotor didemosi dengan klaim pending** | K6: flag dicabut → bypass gating hilang & submit baru ditolak (gate baca `is_promoter` SEGAR di TX, bukan session); klaim pending tetap di queue untuk keputusan admin. Demosi kedua kalinya tidak mengubah riwayat approved. |
| E4 | Promotor di-ban | `is_banned=1` → submit ditolak; klaim pending tetap bisa diproses admin (discretion; telemetri menampilkan chip banned). Upline banned sudah dilewati mesin rebate (plan/89) — tidak ada interaksi ganda. |
| E5 | Produk dinonaktifkan / harga diedit antara submit dan approve | Approve membaca produk current read: nonaktif → approve ditolak (admin reject dulu); rasio keluar 8–10% → ditolak K5 (admin sesuaikan harga/map). `omzet_cost` klaim tetap snapshot submit. |
| E6 | Downline L1 di-`cancel_rental` admin setelah burn | `Total` turun → `Redeemable`/`Available` di-floor 0 (bukan negatif); reward yang sudah diterbitkan TIDAK ditarik (non-refundable). Approve klaim baru yang bergantung omzet tsb gagal (verifikasi ulang §6.2-6). |
| E7 | Reparent downline (`update_parent_id` admin) setelah approval | Atribusi omzet berubah secara retroaktif; matematika aman via floor 0 + verifikasi ulang saat approve. Batasan operasional didokumentasikan: hindari reparent akun ber-volume pasca-burn; jejak ada di audit `update_user`. |
| E8 | Downline "inaktif" (kontrak expired → completed via sweep M3) | Omzet tetap terhitung: predikat `status IN ('active','completed')` — kelayakan downline saat ini tidak relevan; hanya `cancelled` yang keluar. |
| E9 | Volume sintetis / self-cycle (promotor danai akun L1 sendiri) | Ekonomi tetap: reward ≤ 10% omzet, tapi omzet berasal dari dana promotor sendiri. Mitigasi: persetujuan **manual admin** + telemetri downline di queue (jumlah L1 unik, rentang waktu, rasio) + penegakan `is_banned`; deteksi anomali otomatis (device/velocity) di luar lingkup — rekomendasi lanjutan. |
| E10 | Submit saat produk nonaktif / tak ada di peta / kuota reward penuh / omzet kurang | Gate submit lengkap dengan pesan spesifik (produk nonaktif, kuota penuh, omzet belum cukup). |
| E11 | Promotor tanpa L1 sama sekali | `Total = 0` → semua tier "Omzet belum cukup"; tidak ada klaim kosong. |
| E12 | Kontrak reward tidak sengaja salah approve | Tidak ada transisi pembatalan klaim; koreksi via `cancel_rental` admin atas kontrak reward tsb (konsekuensi E6) + audit existing `admin_cancel_rental` (verifikasi aksi saat implementasi). |
| E13 | Klaim pending terlantar (demosi/menganggur) | Tetap tampil di queue (filter status); tidak ada auto-expiry — keputusan manusia. |
| E14 | Duplikasi insert kontrak reward | Flip kondisional tunggal + `rental_id` hanya dibuat sekali per transisi; insert terjadi setelah flip sukses dalam TX sama (rollback membatalkan keduanya). |

---

## 11. Matriks Verifikasi (untuk fase implementasi; runtime butuh env ber-DB)

| # | Skenario | Kriteria sukses |
|---|---|---|
| T1 | Perhitungan omzet L1 | `SUM(purchase_price)` L1 langsung = angka benar; `completed` (post-expiry) terhitung; `cancelled` tidak; baris reward (0) tidak menambah omzet upline. |
| T2 | Redeemable & lock | `Available = max(0, Total − Burned − Pending)`; reject melepas lock; submit menambah lock. |
| T3 | Submit gate | Ditolak saat: bukan promotor, banned, produk nonaktif/tak di peta, rasio di luar 8–10%, kuota reward penuh, omzet kurang; sukses saat semua gate lolos (status `pending`). |
| T4 | Approve happy path | Status → `approved`; kontrak `user_rentals` baru `purchase_price=0`, `source='promoter_reward'`, `daily_roi`/`total_days`/`expired_at` snapshot benar; **nol baris `wallet_ledger`**; 1 notifikasi `success`; 1 baris audit `promoter_claim_approved` (TX sama). |
| T5 | ROI kontrak reward | `claim_roi()` membayar `daily_roi × hari` penuh (T+1); sweep M3 menutup kontrak ke `completed` tepat waktu. |
| T6 | Aktivasi rebate 3-tier | Promotor ber-kontrak reward aktif menerima `RBT-{rental}-L{tier}` saat downline L1 membeli (5/3/1%); setelah kontrak reward expired → breakage (tidak menerima). |
| T7 | Reject | Status → `rejected` + `admin_notes`; lock lepas (Available naik kembali); tanpa kontrak; 1 notifikasi `warning`; 1 audit `promoter_claim_rejected`. |
| T8 | Konkurensi & double action | Dua submit bersamaan → maksimal 1 lolos bila omzet cukup untuk 1; approve dua admin → 1 sukses, 1 "sudah diproses"; tidak ada kontrak ganda. |
| T9 | Kuota independen (K4) | Promotor dgn 1 pembelian berbayar RTX 3060 tetap bisa redeem 1× reward Tier-1 (kanal terpisah); redeem ke-2 reward Tier-1 ditolak (kuota reward = 1); GATE 2 checkout pembayaran TIDAK terpengaruh baris reward (source-aware). |
| T10 | Bypass gating Condition A | `is_promoter=1` dgn 0 lifetime rental: kode/link/QR tampil di `/team` & dashboard; flag dicabut → langsung terkunci lagi (0 rental). |
| T11 | Toggle promotor admin | POST-only; audit `admin_toggle_promoter` before→after; notifikasi info; konsisten di list & detail. |
| T12 | Queue admin | List + filter + telemetri benar; approve/reject dari queue memicu T4/T7. |
| T13 | M8 | Grep: tidak ada aritmetika float pada uang di kode baru; `(int)` sebelum aritmetika; validasi input `^[1-9][0-9]*$`. |
| T14 | M9 | Endpoint submit member: envelope `api_success/api_error`; AJAX 404/exception → JSON bersih. |
| T15 | Statis | `php -l` seluruh file PHP tersentuh (fase implementasi). |
| T16 | Migrasi & seed | `database.sql` fresh-install sinkron; ALTER idempotent di DB lama; re-run seed tidak merusak data. |

⏳ = verifikasi runtime memerlukan lingkungan MySQL aktif + sesi user/admin
(pariti plan/90: tidak tersedia di sandbox ini; verifikasi statis + desain
menjadi bukti sementara).

---

## 12. Lingkup Eksekusi (NANTI — di luar dokumen ini)

Dokumen ini **tidak mengubah apa pun**. Saat implementasi diinstruksikan,
file yang akan tersentuh (per blueprint di atas):

| Kategori | File |
|---|---|
| Skema | `database.sql` (+ ALTER terpisah untuk DB aktif: §3.1–3.3) |
| Config baru | `application/config/promoter_rewards.php` |
| Model baru | `application/models/Promoter_model.php` (summary omzet, submit/approve/reject TX, validasi config) |
| Model diubah | `Rental_model.php` (GATE 2 source-aware §5.3); `Product_model.php`/`Admin_model.php` (tampilan kuota source-aware bila ada); `Admin_model.php` (`toggle_promoter`) |
| Controller | `Team.php` (+`promoter_claim` AJAX & bypass), `Home.php` (bypass), `Admin.php` (`toggle_promoter`, `promoter_claims`, `approve_promoter_claim`, `reject_promoter_claim`) |
| View | `team/index.php` (hub promotor), `home/index.php` (kartu), `admin/users.php`, `admin/user_detail.php` (toggle), `admin/promoter_claims.php` (baru), `admin/templates/sidebar.php` |
| Routing | `application/config/routes.php` (§9.3) |
| Docs (opsional sinkron) | `docs/2_ERD.md`, `docs/3_ROADMAP.md`, catatan AGENTS.md |

### Catatan ekonomi (transparansi)

Kontrak reward membayar ROI harian **penuh** selama durasi standar (mis.
RTX 4080: `57.600 × 35 hari ≈ Rp 2.016.000` dari kontrak bernilai nominal
Rp 1.200.000). Biaya kas aktual platform = aliran ROI tersebut; angka
"8–10%" pada spek adalah rasio terhadap **nilai nominal paket** dan dijaga
oleh guard K5 terhadap `gpu_products.price`. Konsisten dengan spek; keputusan
akuntansi biaya penuh dapat direview terpisah bila diinginkan.
