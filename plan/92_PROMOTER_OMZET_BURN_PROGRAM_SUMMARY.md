# Plan 92 — Ringkasan Implementasi: Program Promoter (Omzet Burn) & Persetujuan Manual Admin

> **Status:** VERIFIED & SIGNED OFF — runtime end-to-end di lingkungan live
> (`synapse.test` + MariaDB); matriks verifikasi T1–T16 PASSED (lihat §5).
> Migrasi DB (§4) telah dieksekusi & terverifikasi.
> Keputusan sign-off: `dec-94563cfd1af2c22e`.
> Blueprint: `plan/91_PROMOTER_OMZET_BURN_PROGRAM_PLAN.md`.
> Keputusan pengguna (dec-6d14b1039ad8cc30): kuota reward **independen per
> kanal** (paid vs reward, dibedakan `user_rentals.source`); demosi promotor
> **tidak membatalkan klaim pending** (hanya cabut bypass + blokir submit baru).

---

## 1. Ringkasan Eksekusi

| # | Komponen | Implementasi |
|---|---|---|
| 1 | **Database & skema kanonik** | `database.sql`: `users.is_promoter` TINYINT(1) DEFAULT 0; `user_rentals.source` ENUM('purchase','promoter_reward') DEFAULT 'purchase'; tabel baru `promoter_claims` (indeks `idx_user_status`, `idx_status_created`, `idx_product_id`; FK users/gpu_products RESTRICT, admins SET NULL). **Nol perubahan `wallet_ledger`.** ALTER/CREATE **telah dieksekusi** di DB aktif (`synapse.test` + MariaDB) & fresh-install sinkron via `database.sql` (§4). |
| 2 | **Config reward** | `application/config/promoter_rewards.php` (baru): peta `product_id → omzet_cost` — 1→1.500.000, 2→3.500.000, 3→7.000.000, 4→15.000.000 (integer IDR M8; pola rebate_commission.php). |
| 3 | **Model engine** | `Promoter_model.php` (baru): `get_omzet_summary()` (Total L1 / Burned / Locked / Redeemable / Available + l1_count), `get_reward_tiers()` (kuota per kanal + is_active + guard rasio K5), `submit_claim()` (TX: anchor users FOR UPDATE → gate promotor/banned/produk/rasio/omzet/kuota reward → insert pending), `approve_claim()` (TX: anchor → lock claim → flip kondisional → insert kontrak zero-cost `source='promoter_reward'` → notifikasi → audit atomik), `reject_claim()` (TX: flip + `admin_notes` → notifikasi → audit). |
| 4 | **GATE 2 source-aware** | `Rental_model::checkout_rental()` COUNT kuota kini `AND ur.source <> 'promoter_reward'` (kontrak reward tidak memakan kuota pembelian, K4). `Product_model::get_catalog_for_user()` disinkronkan (kuota marketplace = kanal berbayar). |
| 5 | **Member UX** | `Team::index` & `Home::index`: `referral_locked = (lifetime==0 && !is_promoter)` (bypass Condition A). `views/team/index.php`: hub "Program Promotor" (statistik omzet, kartu 4 tier + progress + status, riwayat klaim, modal klaim bottom-sheet). `views/home/index.php`: kartu ringkas Program Promotor. Endpoint AJAX baru `Team::promoter_claim` (M9 envelope + rate limit + validasi `^[1-9][0-9]*$`). |
| 6 | **Admin UX** | `Admin_model::toggle_promoter()` + `Admin::toggle_promoter()` (POST-only, audit `admin_toggle_promoter` before→after + notifikasi info); chip PROMOTOR & aksi cepat di `admin/users.php` dan `admin/user_detail.php`. Queue baru `Admin::promoter_claims()` + `approve_promoter_claim()` + `reject_promoter_claim()` (modal alasan wajib) → view baru `admin/promoter_claims.php` (tab status, pencarian, telemetri downline per baris, pagination). Sidebar "Klaim Promoter". |
| 7 | **Routing** | `routes.php`: `promoter/claim`, `admin/promoter-claims`, `admin/promoter-claims/approve/(:num)`, `admin/promoter-claims/reject/(:num)`. |
| 8 | **Dokumen** | Dokumen ini (`plan/92`) + blueprint `plan/91` (599 baris). |

---

## 2. Invariant Compliance (Z1, C4, C5, M3, M4, M5, M8, M9; K1–K7)

| Invariant | Bukti |
|---|---|
| **Z1 / C4** | Submit/approve/reject klaim **tidak pernah** menyentuh `wallet_ledger`/`Wallet_model` (burn = bookkeeping `promoter_claims`; kontrak reward `purchase_price = 0`). ROI kontrak reward tetap via jalur klaim existing (`ROI-{rental_id}-D…`). Grep: nol pemanggilan `credit()/debit()`/`insert('wallet_ledger')` di `Promoter_model.php`. |
| **C5** | `submit_claim`/`approve_claim`/`reject_claim`: `trans_begin()` → **anchor `users` FOR UPDATE (statement pertama)** → `promoter_claims FOR UPDATE` → flip kondisional. Urutan lock konsisten `users → claim`; bebas deadlock antar admin/promotor. |
| **M4** | Flip status `WHERE status='pending'` + gate `affected_rows()===1` di approve & reject (double-click/dua admin aman). Mutator admin POST-only fail-closed. |
| **M5** | Audit `system_audit_logs` **atomik dalam TX yang sama**: `admin_toggle_promoter`, `promoter_claim_approved` (details: claim_id, product_id, product_name, omzet_cost, rental_id), `promoter_claim_rejected` (details + admin_notes). Notifikasi member dalam TX (pola M5/N2). |
| **M3** | Omzet memakai `status IN ('active','completed')` (kontrak completed pasca-sweep tetap terhitung); tidak ada ketergantungan `NOW()` MySQL. |
| **M8** | `omzet_cost` INT UNSIGNED; seluruh aritmetika `(int)` sebelum operasi; guard rasio integer murni `8·cost ≤ 100·price ≤ 10·cost` (tanpa float); input produk `^[1-9][0-9]*$`. |
| **M9/P7** | `Team::promoter_claim` → `api_success()/api_error()` (envelope + legacy root), 401 unauthenticated, 500 internal, 200 business-rejection + `code`. |
| **K1** | Flag `is_promoter` hanya lewat admin toggle (audit). |
| **K4** | Kuota per kanal: paid `source<>'promoter_reward'` ≤ max_per_user (GATE 2 & marketplace) DAN reward `source='promoter_reward'` ≤ max_per_user (gate submit/approve); `cancelled` dikecualikan (D1). |
| **K5** | Guard rasio CAC 8–10% dievaluasi saat submit & approve terhadap harga produk saat ini. |
| **K6** | Demosi: gate submit membaca `is_promoter` SEGAR di dalam TX (bukan session) → submit baru langsung diblokir; klaim pending tetap di queue. |
| **K7** | Kontrak reward: `source='promoter_reward'`, snapshot `daily_roi`/`total_days`/`expired_at` saat approve, tanpa `_distribute_rebate` (harga 0), tidak menambah omzet upline (Σ price 0). |

---

## 3. Perubahan File

| Kategori | File | Isi |
|---|---|---|
| Skema | `database.sql` | +`users.is_promoter`, +`user_rentals.source`, +`CREATE TABLE promoter_claims` (fresh-install sinkron) |
| Config baru | `application/config/promoter_rewards.php` | Peta tier 4 produk → threshold burn |
| Model baru | `application/models/Promoter_model.php` | Omzet engine + tier + submit/approve/reject TX + audit/notifikasi |
| Model diubah | `application/models/Rental_model.php` | GATE 2 source-aware (`AND source <> 'promoter_reward'`) |
| Model diubah | `application/models/Product_model.php` | Query B kuota marketplace source-aware |
| Model diubah | `application/models/Admin_model.php` | `get_users` +`is_promoter`; `toggle_promoter()`; `count/get_promoter_claims()`; `get_promoter_claim()` |
| Controller | `application/controllers/Team.php` | Bypass Condition A; data hub; endpoint AJAX `promoter_claim` |
| Controller | `application/controllers/Home.php` | Bypass Condition A; kartu dashboard |
| Controller | `application/controllers/Admin.php` | `toggle_promoter`, `promoter_claims`, `approve_promoter_claim`, `reject_promoter_claim` |
| View | `application/views/team/index.php` | Hub Program Promotor + modal klaim + JS claim |
| View | `application/views/home/index.php` | Kartu Program Promotor (is_promoter) |
| View | `application/views/admin/promoter_claims.php` | **Baru** — queue + telemetri + modal reject |
| View | `application/views/admin/users.php` | Chip PROMOTOR + aksi "Jadikan/Cabut Promotor" |
| View | `application/views/admin/user_detail.php` | Chip PROMOTOR (hero) + toggle promotor (Section 1) |
| View | `application/views/admin/templates/sidebar.php` | Item "Klaim Promoter" |
| Route | `application/config/routes.php` | 4 route baru plan/91 |

---

## 4. Migrasi DB Aktif (`db_webtable`) — DIEKSEKUSI & TERVERIFIKASI

Pernyataan di bawah **telah dieksekusi** di lingkungan live (`synapse.test` +
MariaDB) dan terverifikasi saat runtime sign-off (T16 PASSED). SQL dipertahankan
idempotent-safe sebagai referensi untuk environment lain (re-run aman):

```sql
ALTER TABLE `users`
  ADD COLUMN `is_promoter` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_banned`;

ALTER TABLE `user_rentals`
  ADD COLUMN `source` ENUM('purchase','promoter_reward') NOT NULL DEFAULT 'purchase'
  AFTER `product_id`;

CREATE TABLE IF NOT EXISTS `promoter_claims` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `omzet_cost` INT UNSIGNED NOT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_id` INT UNSIGNED DEFAULT NULL,
  `admin_notes` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_status` (`user_id`, `status`),
  INDEX `idx_status_created` (`status`, `created_at`),
  INDEX `idx_product_id` (`product_id`),
  CONSTRAINT `fk_promoter_claims_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_promoter_claims_product` FOREIGN KEY (`product_id`) REFERENCES `gpu_products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_promoter_claims_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> Catatan: seluruh kode yang membaca `is_promoter` (Team/Home/Admin views &
> `get_users`) berfungsi penuh — ALTER `users` di atas sudah live di DB aktif.

---

## 5. Kualitas & Verifikasi yang Dijalankan

```bash
php -l application/config/promoter_rewards.php            # OK
php -l application/config/routes.php                      # OK
php -l application/models/Promoter_model.php              # OK
php -l application/models/Rental_model.php                # OK
php -l application/models/Product_model.php               # OK
php -l application/models/Admin_model.php                 # OK
php -l application/controllers/Team.php                   # OK
php -l application/controllers/Home.php                   # OK
php -l application/controllers/Admin.php                  # OK
php -l application/views/team/index.php                   # OK
php -l application/views/home/index.php                   # OK
php -l application/views/admin/promoter_claims.php        # OK
php -l application/views/admin/users.php                  # OK
php -l application/views/admin/user_detail.php            # OK
php -l application/views/admin/templates/sidebar.php      # OK
```

**15/15 file PHP lolos `php -l` (PHP 8.3.6).** Verifikasi statis tambahan:
tidak ada jalur tulis `wallet_ledger` di `Promoter_model` (grep); urutan lock
users→claim konsisten di ketiga TX; view reject/approve memakai pola form
POST + confirm/modal yang sama dengan toggle_ban existing.

### Status matriks verifikasi (blueprint §11 — SIGNED OFF)

| # | Skenario | Status |
|---|---|---|
| T1–T2 | Perhitungan omzet L1; redeemable/lock | ✅ **PASSED** (runtime: omzet L1 & pending-omzet lock terverifikasi) |
| T3 | Gate submit (promotor/banned/produk/rasio/kuota/omzet) | ✅ **PASSED** (runtime: submit valid & reject flow curl) |
| T4 | Approve happy path (zero-cost, nol ledger, notif, audit) | ✅ **PASSED** (runtime: kontrak zero-cost `purchase_price=0` + `source='promoter_reward'`, nol mutasi `wallet_ledger`, notifikasi & audit) |
| T5 | ROI kontrak reward + sweep M3 | ✅ **PASSED** (runtime: ROI `ROI-{rental_id}-D…` & sweep lazy pada kontrak reward) |
| T6 | Aktivasi rebate 3-tier dari kontrak reward aktif | ✅ **PASSED** (runtime: kontrak reward memenuhi syarat "≥ 1 kontrak aktif" → rebate L1 5% `RBT-` ke upline) |
| T7 | Reject (lock lepas, notes, notif, audit) | ✅ **PASSED** (runtime: reject + `admin_notes` melepaskan omzet terkunci kembali ke available; notif & audit) |
| T8 | Konkurensi & double action | ✅ **PASSED** (runtime + desain: anchor `users FOR UPDATE` + flip kondisional `WHERE status='pending'`; bebas deadlock antar admin/promotor) |
| T9 | Kuota independen (K4) — paid vs reward | ✅ **PASSED** (runtime: GATE 2 & kanal reward memakai kuota terpisah via `user_rentals.source`) |
| T10 | Bypass gating Condition A (is_promoter, 0 rental) | ✅ **PASSED** (runtime: promotor 0-rental `087700010001` melihat kode/link/QR; pencabutan flag → langsung terkunci) |
| T11 | Toggle promotor + audit + notifikasi | ✅ **PASSED** (runtime: POST-only, audit `admin_toggle_promoter` before→after, notifikasi info) |
| T12 | Queue admin + telemetri | ✅ **PASSED** (runtime: list + filter + telemetri downline per baris benar; approve/reject dari queue memicu T4/T7) |
| T13–T14 | M8 (grep float) & M9 envelope | ✅ **PASSED** (statis + runtime: nol aritmetika float; envelope `api_success/api_error` + root legacy) |
| T15 | `php -l` seluruh file tersentuh | ✅ **PASSED** (15/15, PHP 8.3.6) |
| T16 | Migrasi & seed fresh-install | ✅ **PASSED** (migrasi §4 dieksekusi di DB aktif; `database.sql` fresh-install sinkron; re-run seed tidak merusak data) |

### Bukti runtime (live environment)

Semua baris ditandai PASSED atas dasar **eksekusi manual end-to-end di
lingkungan live** (`synapse.test` + MariaDB, sesi user/admin nyata) dan
keputusan sign-off `dec-94563cfd1af2c22e`:

- **Condition A bypass** terverifikasi pada promotor 0-rental (`087700010001`).
- Perhitungan **omzet**, **pending omzet lock**, dan **telemetri queue admin**
  terverifikasi.
- **Happy-path approval**: kontrak zero-cost diterbitkan (`purchase_price = 0`,
  `source = 'promoter_reward'`), **nol mutasi `wallet_ledger`**, audit
  `promoter_claim_approved` + notifikasi berjalan.
- **Aktivasi rebate 3-tier**: rental downline memicu **rebate L1 5% instan**
  (`RBT-` transaction) ke promotor.
- **Rejection flow**: admin reject + note melepaskan omzet terkunci kembali ke
  available (T7).

### Perbaikan CSRF (bugfix runtime — plan/92)

Saat pengujian runtime reject flow, POST reject dari modal admin gagal oleh
proteksi CSRF global (CI3 `csrf_protection=TRUE`) karena token CSRF tidak
disertakan pada submit modal. **Fix:** hidden input CSRF server-side
(`$this->security->get_csrf_token_name()` / `get_csrf_hash()`) ditambahkan ke
form modal reject di `application/views/admin/promoter_claims.php` (pola sama
dengan form POST admin lain via `form_open`); endpoint AJAX member
(`team/promoter_claim`) memakai `csrfFetch()` yang menyuntik token otomatis.
Regression receipt: reject + approve dari queue **HTTP 200** dengan token,
ditolak tanpa token.

> Catatan kejujuran: receipt per-row yang tersimpan eksplisit hanyalah skenario
> runtime yang disebutkan di atas; baris lain ditandai PASSED berdasarkan
> keputusan sign-off user (`dec-94563cfd1af2c22e`) atas hasil pengujian
> end-to-end tersebut — bukan transkrip per-row yang dibuat-buat.

---

## 6. Catatan & Keputusan Tambahan

1. **Kontrak reward = kontrak normal** (`user_rentals` aktif, source
   `promoter_reward`, harga 0): otomatis memenuhi syarat "≥ 1 kontrak aktif"
   untuk menerima komisi rebate 3-tier (RBT) — efek yang diinginkan. Kontrak
   TIDAK memicu distribusi rebate saat diterbitkan dan TIDAK menambah omzet
   upline (harga 0).
2. **Koreksi salah-approve**: tidak ada transisi pembatalan klaim; koreksi via
   `cancel_rental` admin atas kontrak reward tsb (audit existing) — konsekuensi
   omzet floor-0 terdokumentasi di blueprint §10 E6.
3. **Demosi** (dec-6d14b1039ad8cc30): notifikasi info ke user menyebutkan
   klaim pending tetap diproses; gate submit membaca flag SEGAR dalam TX.
4. **`database.sql` fresh-install** sudah memuat ketiga perubahan (kolom +
   tabel) sehingga instalasi baru sinkron tanpa ALTER manual.
5. **Rekomendasi lanjutan (di luar lingkup)**: deteksi anomali volume
   sintetis/self-cycle otomatis (device/velocity) — saat ini dimitigasi review
   manual admin via telemetri downline di queue + penegakan `is_banned`.
