-- Synapse Database Schema
-- Target: MySQL 8.4 (InnoDB)
-- Character Set: utf8mb4

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------
-- Table `users`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(20) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `invite_code` VARCHAR(10) NOT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  -- plan/118: DOMPET KEDUA — "Saldo Absen" (checkin). Kredit HANYA dari
  -- Bonus Absensi Harian; debit HANYA dari split payment checkout sewa.
  -- TIDAK dapat ditarik: `Wallet_model::create_withdrawal()` hanya melihat
  -- dompet `main` (invarian CK3). Mirror non-otoritatif — sumber kebenaran =
  -- SUM(credit - debit) `wallet_ledger` dengan `wallet_type = 'checkin'` (CK2).
  -- Tanpa backfill: nilai awal seluruh baris lama = 0.00 (D3/CK8).
  `checkin_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `avatar_url` VARCHAR(255) DEFAULT NULL,
  `level_id` INT NOT NULL DEFAULT 0,
  `is_banned` TINYINT(1) NOT NULL DEFAULT 0,
  -- plan/91: flag promotor (admin-only). Bypass gating referral Condition A.
  `is_promoter` TINYINT(1) NOT NULL DEFAULT 0,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  -- DEPRECATED (plan/120): flag klaim "Misi Level 1" (bonus one-time Rp 80.000 —
  -- 3 downline B aktif + omset ≥ Rp 330.000). FITUR DIHENTIKAN di lapis
  -- aplikasi: view kartu `/team`, `Team::claim_level1()`, `User_model::
  -- claim_level1()`/`get_claim_data()`, dan `User_model::LEVEL1_BONUS` sudah
  -- DIHAPUS. Kolom DIPERTAHANKAN sebagai retensi historis — nol pembaca/penulis
  -- baru (invariant L1R1). Baris `wallet_ledger` historis ber-prefix `L1-` dan
  -- baris `user_notifications` `title_key='notif_bonus_l1'` juga dibiarkan utuh
  -- (invariant L1R4, tanpa backfill).
  `is_level_1_claimed` TINYINT(1) NOT NULL DEFAULT 0,
  -- DEPRECATED (plan/119): stamp klaim `Gaji Mingguan` (siklus 7 hari) —
  -- mesin wage DIHENTIKAN dan digantikan Bonus Pencapaian Tim. Kolom
  -- DIPERTAHANKAN sebagai retensi historis (nol penulis baru; tidak ada kode
  -- yang membacanya untuk keputusan bisnis). Baris `wallet_ledger` historis
  -- ber-prefix `WAGE-` juga dibiarkan utuh (invariant Z1, tanpa backfill).
  `last_wage_claimed_at` DATETIME NULL DEFAULT NULL,
  -- plan/119: STATE KLAIM Bonus Pencapaian Tim (TEAM MILESTONE BONUS).
  -- CSV target integer kanonik MENAIK tanpa spasi (contoh '9,30'; NULL/'' =
  -- belum ada klaim). Diturunkan dari `system_settings.team_milestone_tiers`.
  -- Penulis TUNGGAL: `User_model::claim_milestone()` di dalam satu TX terkunci
  -- (`users ... FOR UPDATE` → UPDATE kondisional `(team_milestone_claimed <=> ?)`
  -- + guard `affected_rows() === 1`, pola M4). Klaim bersifat PERMANEN: turunnya
  -- jumlah downline aktif TIDAK membatalkan/mengulang bayaran (MK6).
  -- Baris pembayaran tetap di `wallet_ledger`:
  -- `transaction_id = 'MSB-{user_id}-T{target}'`, `wallet_type = 'main'` —
  -- UNIQUE `uk_wallet_ledger_user_tx_type` = guard tingkat DB (MK2).
  -- Anggaran panjang: max 20 tier × target ≤ 6 digit ⇒ ≤ 139 karakter (D6).
  `team_milestone_claimed` VARCHAR(255) NULL DEFAULT NULL,
  -- plan/112: absensi harian (daily check-in). `checkin_last_date` = tanggal WIB
  -- klaim terakhir (DATE, otoritas harian); `checkin_streak` = hari beruntun
  -- terakhir yang DIBAYAR (0 = belum pernah). Otoritas tanggal = PHP WIB, bukan
  -- MySQL NOW()/CURDATE() (invarian M2/M3). Histori klaim per hari ada di
  -- `wallet_ledger` dengan `transaction_id` = 'CHK-{user_id}-{Ymd}'.
  `checkin_streak` INT UNSIGNED NOT NULL DEFAULT 0,
  `checkin_last_date` DATE NULL DEFAULT NULL,
  -- plan/121: TIKET LUCKY SPIN — COUNTER tiket (BUKAN uang: tidak pernah masuk
  -- `wallet_ledger`, tidak dapat ditarik/dibeli — invarian SP6).
  -- Otoritatif (dapat diturunkan ulang):
  --   COUNT(`spin_ticket_grants`.`upline_id` = id)
  --   − COUNT(`spin_results`.`user_id` = id)
  -- Penulis TUNGGAL `Spin_model`:
  --   • +1 di dalam TX `Rental_model::checkout_rental()` (langkah 7b) saat
  --     downline L1 membeli produk NON-trial PERTAMA kali;
  --   • −1 di dalam TX `Spin_model::spin()` (UPDATE kondisional
  --     `spin_tickets > 0` + guard `affected_rows() === 1`, pola M4).
  -- Tanpa backfill (keputusan D4): seluruh baris lama mulai dari 0.
  `spin_tickets` INT UNSIGNED NOT NULL DEFAULT 0,
  -- plan/117: KUNCI PENARIKAN PER-USER (Withdrawal Lock) — mitigasi fraud.
  -- `is_wd_locked = 1` memblokir PENGAJUAN penarikan BARU pada 4 lapis:
  -- tombol /wallet, gatekeeper GET /wallet/withdraw, POST
  -- /wallet/process_withdraw, dan otoritas finansial di dalam TX terkunci
  -- `Wallet_model::create_withdrawal()`. BUKAN ban: user tetap bisa login,
  -- menyewa, klaim ROI & absensi (beda dari `is_banned`).
  -- TIDAK membatalkan penarikan `pending` yang sudah ada (keputusan D1) dan
  -- TIDAK menulis `wallet_ledger`/`withdrawals` (D2).
  -- `wd_lock_reason` = alasan INTERNAL admin (VARCHAR 255, NULL = terbuka);
  -- kolom ini TIDAK PERNAH dikirim ke permukaan member (invarian W3).
  -- Hanya diubah lewat toggle admin ber-audit `admin_toggle_wd_lock` (W1).
  `is_wd_locked` TINYINT(1) NOT NULL DEFAULT 0,
  `wd_lock_reason` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_phone` (`phone`),
  UNIQUE KEY `uk_invite_code` (`invite_code`),
  INDEX `idx_parent_id` (`parent_id`),
  INDEX `idx_invite_code` (`invite_code`),
  CONSTRAINT `fk_users_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `gpu_products`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `gpu_products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  -- plan/104: nama berkas gambar produk (BASENAME saja, tanpa path) di
  -- `uploads/products/`. NULL atau berkas hilang di disk → marketplace
  -- merender fallback banner gelap (resolusi via product_image_helper:
  -- product_image_url() === null). Berkas fisik bersifat runtime &
  -- di-gitignore (lihat .gitignore blok plan/104); kolom ini hanya nama.
  `image` VARCHAR(255) NULL DEFAULT NULL,
  `type` ENUM('short_term', 'long_term') NOT NULL,
  `price` DECIMAL(15,2) NOT NULL,
  `daily_rate` DECIMAL(15,2) NOT NULL,
  `duration_days` INT UNSIGNED NOT NULL,
  `is_refundable` TINYINT(1) NOT NULL DEFAULT 0,
  -- plan/83: gating & per-user purchase limits engine.
  -- 0 = unlimited; N >= 1 = lifetime rental cap per user.
  `max_per_user` INT UNSIGNED NOT NULL DEFAULT 0,
  -- plan/114: penanda PRODUK TRIAL ("GPU Magang"). 1 = produk trial
  -- (activation hook) — harga WAJIB 0, `max_per_user` WAJIB 1, dan checkout
  -- TIDAK memotong saldo (tanpa baris `wallet_ledger`), TIDAK memicu rebate
  -- 3-tier, dan TIDAK menambah omzet upline (omzet = derivasi
  -- SUM(purchase_price) → kontrak bernilai 0 menyumbang 0).
  -- Invarian: TEPAT SATU baris dengan is_trial = 1 (dijaga admin product CRUD
  -- + `scripts/migrate_114_trial_product_wd_gate.php --verify`).
  -- Gerbang penarikan anti free-rider memakai kolom ini:
  -- `Rental_model::has_paid_rental()` = riwayat >= 1 kontrak dari produk
  -- NON-trial. Baris lama otomatis 0 (DEFAULT) — tanpa backfill.
  -- plan/116 (F17): TANPA klausa `AFTER` — `AFTER` hanya valid di
  -- `ALTER TABLE … ADD COLUMN` (lihat catatan migrasi di bawah), BUKAN di
  -- `CREATE TABLE`. Klausa itu membuat `database.sql` gagal di-import
  -- (ERROR 1064 di tabel ini → seluruh tabel sesudahnya tak pernah dibuat).
  -- Posisi fisik tetap identik dengan DB aktif (langsung setelah
  -- `max_per_user`) karena baris ini memang diletakkan di sini.
  `is_trial` TINYINT(1) NOT NULL DEFAULT 0,
  -- DEPRECATED (plan/87): prerequisite-chain gating decommissioned.
  -- Product availability is 100% admin-controlled via `is_active`.
  -- Column/index/FK retained non-destructively (all rows NULL); no
  -- application code reads or writes this column anymore.
  `unlock_prerequisite_id` INT UNSIGNED NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_unlock_prerequisite` (`unlock_prerequisite_id`),
  CONSTRAINT `fk_gpu_products_unlock_prereq` FOREIGN KEY (`unlock_prerequisite_id`) REFERENCES `gpu_products` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `rentals`
-- -----------------------------------------------------
-- DEPRECATED (M10, plan/78): legacy table — no code path reads or writes it;
-- the live table is `user_rentals`. Retention-only; do not use in new code.
CREATE TABLE IF NOT EXISTS `rentals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `gpu_product_id` INT UNSIGNED NOT NULL,
  `status` ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
  `total_days` INT UNSIGNED NOT NULL,
  `days_processed` INT UNSIGNED NOT NULL DEFAULT 0,
  `daily_rate_snapshot` DECIMAL(15,2) NOT NULL,
  `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ends_at` TIMESTAMP NULL DEFAULT NULL,
  `last_claimed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_status` (`user_id`, `status`),
  CONSTRAINT `fk_rentals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rentals_product` FOREIGN KEY (`gpu_product_id`) REFERENCES `gpu_products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `ewallet_providers` — Plan 106 (katalog provider e-wallet dinamis)
-- Satu-satunya sumber kebenaran pilihan provider untuk member (/wallet/bind_bank)
-- dan otoritas status aktif/nonaktif. `code` = identitas stabil (dipakai audit,
-- backfill migrasi & verify CLI); `name` = label tampilan (disimpan apa adanya
-- di `bank_accounts.bank_name` — keputusan D4). Tidak ada hard delete (D7).
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ewallet_providers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ewallet_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed idempotent (pola `system_settings`): INSERT IGNORE tidak pernah
-- menimpa rename/status yang sudah diubah admin.
INSERT IGNORE INTO `ewallet_providers` (`code`, `name`, `is_active`) VALUES
('DANA',      'DANA',      1),
('SHOPEEPAY', 'ShopeePay', 1),
('OVO',       'OVO',       1),
('GOPAY',     'GoPay',     1);

-- -----------------------------------------------------
-- Table `bank_accounts` — RETENSI STRUKTURAL (Plan 106)
-- Struktur SENGAJA tidak diubah (zero-breakage: `fk_withdrawals_bank`).
-- Semantik kolom setelah Plan 106:
--   `bank_name`      = NAMA provider e-wallet (harus ada di `ewallet_providers.name`);
--   `account_number` = nomor HP e-wallet kanonik `^08[0-9]{8,11}$`;
--   `account_holder` = nama pemilik akun e-wallet;
--   `is_primary`     = FLAG BINDING AKTIF (1 = terikat, 0 = arsip/unbound — D2).
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `bank_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `bank_name` VARCHAR(100) NOT NULL,
  `account_number` VARCHAR(50) NOT NULL,
  `account_holder` VARCHAR(100) NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_bank_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `withdrawals`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `withdrawals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `bank_account_id` BIGINT UNSIGNED NOT NULL,
  `wd_number` VARCHAR(50) NULL DEFAULT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `gross_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `fee_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('pending', 'processing', 'success', 'failed') NOT NULL DEFAULT 'pending',
  `remark` VARCHAR(255) DEFAULT NULL,
  `decline_reason` VARCHAR(255) DEFAULT NULL,
  `processed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wd_number` (`wd_number`),
  -- Plan 94 (F2): leading-status untuk COUNT antrean pending + listing
  -- dashboard ORDER BY created_at. Live DB: ALTER one-time (lihat bawah).
  INDEX `idx_status_created` (`status`, `created_at`),
  CONSTRAINT `fk_withdrawals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_withdrawals_bank` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `otp_logs`
-- -----------------------------------------------------
-- DEPRECATED (M10, plan/78): legacy table — no code path reads or writes it;
-- no OTP flow exists (auth uses native session CAPTCHA). Retention-only.
CREATE TABLE IF NOT EXISTS `otp_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(20) NOT NULL,
  `otp_code` VARCHAR(6) NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- PHASE B (Langkah 0): production tables + Phase 10 baseline
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Table `wallet_ledger` (immutable append-only ledger)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `wallet_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `transaction_id` VARCHAR(50) NOT NULL,
  `type` ENUM('credit', 'debit') NOT NULL,
  -- plan/118: jenis dompet yang dikredit/didebit (audit multi-dompet).
  -- DEFAULT 'main' = backfill implisit seluruh baris pra-plan/118 (D3/CK8).
  -- `uk_wallet_ledger_user_tx_type (user_id, transaction_id, type)` TIDAK
  -- berubah -> idempotensi harian absensi ('CHK-{user_id}-{Ymd}') tetap utuh.
  `wallet_type` ENUM('main', 'checkin') NOT NULL DEFAULT 'main',
  `amount` DECIMAL(15,2) NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wallet_ledger_user_tx_type` (`user_id`, `transaction_id`, `type`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_type` (`type`),
  INDEX `idx_created_at` (`created_at`),
  CONSTRAINT `fk_wallet_ledger_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `deposits` (transaction invoices; plan/102 = manual QRIS gateway)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `deposits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `invoice_number` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  -- plan/102: kode unik 3 digit (100–999) gateway QRIS manual. Disimpan
  -- permanen sebagai jejak audit (tidak dibuang setelah deposit selesai).
  `unique_code` SMALLINT UNSIGNED NULL DEFAULT NULL,
  -- plan/102: nominal bayar yang DIBEKUKAN saat create = pokok + [fee] + kode.
  -- Otoritatif untuk verifikasi admin DAN nilai kredit (Option A). TIDAK pernah
  -- dihitung ulang saat render (menutup celah G1 plan/102).
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  -- plan/102: `"{pokok}-{kode}"` selama reservasi HIDUP; NULL saat baris keluar
  -- dari pending/waiting_approval. UNIQUE + semantik "banyak NULL" InnoDB =
  -- jaminan tingkat DB: maksimal satu pemilik hidup per (pokok, kode), dan kode
  -- bebas dipakai ulang setelah expired/rejected. Sekaligus index scan tabrakan
  -- via prefix `LIKE '150000-%'` (tanpa index tambahan).
  `reserved_code_key` VARCHAR(24) NULL DEFAULT NULL,
  `expires_at` TIMESTAMP NULL DEFAULT NULL,
  `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
  `processed_at` TIMESTAMP NULL DEFAULT NULL,
  `decline_reason` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending', 'waiting_approval', 'success', 'failed', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invoice_number` (`invoice_number`),
  -- plan/102: eksklusivitas reservasi kode unik per (pokok, kode).
  UNIQUE KEY `uk_reserved_code_key` (`reserved_code_key`),
  INDEX `idx_user_status` (`user_id`, `status`),
  -- Plan 94 (F2): leading-status untuk COUNT antrean pending + listing
  -- dashboard ORDER BY created_at. Live DB: ALTER one-time (lihat bawah).
  INDEX `idx_status_created` (`status`, `created_at`),
  -- plan/102: sweep expiry lazy (leading status) — pola sama idx_status_created.
  INDEX `idx_status_expires` (`status`, `expires_at`),
  CONSTRAINT `fk_deposits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `user_rentals` (active/expired GPU leases)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_rentals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  -- plan/91 (K4): origin kontrak — 'purchase' (checkout/inject, default)
  -- vs 'promoter_reward' (reward zero-cost). Pembeda kuota per kanal:
  -- baris reward TIDAK memakan kuota pembelian berbayar (GATE 2 source-aware).
  `source` ENUM('purchase','promoter_reward') NOT NULL DEFAULT 'purchase',
  `purchase_price` DECIMAL(15,2) NOT NULL,
  `daily_roi` DECIMAL(15,2) NOT NULL,
  `total_days` INT UNSIGNED NOT NULL DEFAULT 0,
  `days_processed` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
  `expired_at` TIMESTAMP NULL DEFAULT NULL,
  `last_claimed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- M3 (plan/60): composite (user_id, status, expired_at) untuk lazy sweep
  -- per-user + kualifikasi downline; (status, expired_at) untuk sweep
  -- global/CLI/admin aggregate. Leftmost prefix menggantikan idx_user_status.
  INDEX `idx_user_status_expired` (`user_id`, `status`, `expired_at`),
  INDEX `idx_status_expired` (`status`, `expired_at`),
  INDEX `idx_product_id` (`product_id`),
  CONSTRAINT `fk_user_rentals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_user_rentals_product` FOREIGN KEY (`product_id`) REFERENCES `gpu_products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `admins` (isolated admin auth — no FK to users)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `user_notifications` (in-app notifications)
-- -----------------------------------------------------
-- plan/103 (W8): `title_key` + `params` menyimpan KUNCI kamus dan
-- PARAMETER-nya, sehingga notifikasi dirender dalam idiom PEMBACA
-- (i18n_notification_text()) — bukan prosa beku satu bahasa.
-- Kolom `title`/`message` dipertahankan sebagai retensi + fallback baris
-- legacy (title_key NULL) dan TIDAK pernah dihapus.
CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(100) NOT NULL,
  `message` TEXT NOT NULL,
  `title_key` VARCHAR(64) NULL COMMENT 'plan/103: key kamus dasar (tanpa _title/_body)',
  `params` JSON NULL COMMENT 'plan/103: argumen vsprintf untuk <key>_body',
  `type` ENUM('info', 'warning', 'success', 'commission') NOT NULL DEFAULT 'info',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_read` (`user_id`, `is_read`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- MIGRASI LIVE ONE-TIME (plan/103) — untuk DB yang sudah berjalan:
--   php scripts/migrate_103_notification_i18n.php --dry-run
--   php scripts/migrate_103_notification_i18n.php --apply    (idempotent)
--
-- DDL ekuivalen bila dijalankan manual:
--   ALTER TABLE `user_notifications`
--     ADD COLUMN `title_key` VARCHAR(64) NULL AFTER `message`,
--     ADD COLUMN `params` JSON NULL AFTER `title_key`;
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Table `spin_ticket_grants` (plan/121 — LUCKY SPIN WHEEL: grant tiket)
-- -----------------------------------------------------
-- Satu baris = SATU tiket yang diperoleh seorang upline karena downline L1-nya
-- melakukan pembelian NON-trial PERTAMA kali. `uk_spin_grant_downline`
-- (UNIQUE `downline_id`) adalah implementasi STRUKTURAL aturan "maksimal 1
-- tiket per downline seumur hidup" (invarian SP2): pembelian kedua dan
-- seterusnya mustahil mencetak tiket baru, bahkan bila lapis aplikasi dilewati.
-- Penulis TUNGGAL: `Spin_model::award_ticket_for_first_paid_rental()` —
-- participant TX milik `Rental_model::checkout_rental()` (langkah 7b); tidak
-- ada jalur lain yang boleh INSERT ke tabel ini.
-- `rental_id` = kontrak pemicu (audit); NULL hanya bila kontraknya hilang.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `spin_ticket_grants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `upline_id` BIGINT UNSIGNED NOT NULL COMMENT 'Penerima tiket (parent saat grant)',
  `downline_id` BIGINT UNSIGNED NOT NULL COMMENT 'Pemicu: pembeli pertama kali',
  `rental_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Kontrak pemicu (audit)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spin_grant_downline` (`downline_id`),
  INDEX `idx_spin_grant_upline` (`upline_id`, `created_at`),
  CONSTRAINT `fk_spin_grant_upline` FOREIGN KEY (`upline_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_spin_grant_downline` FOREIGN KEY (`downline_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_spin_grant_rental` FOREIGN KEY (`rental_id`) REFERENCES `user_rentals` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `spin_results` (plan/121 — LUCKY SPIN WHEEL: jejak audit spin)
-- -----------------------------------------------------
-- Satu baris = SATU spin yang sudah dibayar (tiket terpakai + hadiah
-- dikreditkan). Menyimpan SNAPSHOT konfigurasi saat spin (bobot segmen dan
-- total bobot) sehingga hasil dapat diaudit walau admin mengubah roda
-- kemudian. `slot_index` = posisi segmen (0-based, urutan konfigurasi) yang
-- dikirim balik ke klien HANYA untuk animasi (zero-trust: hadiah sudah
-- ditentukan & dibayar di server, invarian SP4/SP5).
-- `transaction_id` = 'SPIN-{id}' (di-set setelah insert) → jejak ke
-- `wallet_ledger`; UNIQUE `uk_spin_tx` = backstop anti duplikasi.
-- FK `ON DELETE RESTRICT`: baris audit tidak pernah ikut terhapus.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `spin_results` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `slot_index` SMALLINT UNSIGNED NOT NULL COMMENT 'Posisi segmen (0-based, urutan konfigurasi)',
  `prize_amount` DECIMAL(15,2) NOT NULL COMMENT 'Snapshot nominal hadiah (integer IDR)',
  `weight_snapshot` INT UNSIGNED NOT NULL COMMENT 'Bobot segmen saat spin (audit probabilitas)',
  `total_weight` INT UNSIGNED NOT NULL COMMENT 'Sigma bobot saat spin',
  `transaction_id` VARCHAR(50) NULL DEFAULT NULL COMMENT 'SPIN-{id} — jejak wallet_ledger',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spin_tx` (`transaction_id`),
  INDEX `idx_spin_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_spin_result_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- MIGRASI LIVE ONE-TIME (plan/121) — untuk DB yang sudah berjalan:
--   php scripts/migrate_121_lucky_spin.php --dry-run
--   php scripts/migrate_121_lucky_spin.php --apply    (idempotent)
--
-- DDL ekuivalen bila dijalankan manual:
--   ALTER TABLE `users`
--     ADD COLUMN `spin_tickets` INT UNSIGNED NOT NULL DEFAULT 0
--       AFTER `checkin_last_date`;
--   CREATE TABLE IF NOT EXISTS `spin_ticket_grants` ( … );   -- seperti di atas
--   CREATE TABLE IF NOT EXISTS `spin_results` ( … );         -- seperti di atas
--   INSERT IGNORE INTO `system_settings` (`key_name`,`key_value`) VALUES
--     ('spin_wheel_enabled','1'),
--     ('spin_wheel_segments','[[5000,30],[10000,25],[25000,18],[50000,10],
--                              [100000,6],[150000,3],[250000,2],[500000,1]]');
--
-- ⚠️ TANPA BACKFILL (keputusan D4): tiket mulai dihitung sejak rilis.
--    Downline yang sudah membeli produk non-trial SEBELUM plan/121 TIDAK
--    menghasilkan tiket retroaktif — nol pergerakan data, nol baris ledger.
-- -----------------------------------------------------


-- -----------------------------------------------------
-- Table `promoter_claims` (plan/91 — klaim reward promotor, omzet burn)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `promoter_claims` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Promotor pemohon',
  `product_id` INT UNSIGNED NOT NULL COMMENT 'Produk reward (harus di peta tier)',
  `omzet_cost` INT UNSIGNED NOT NULL COMMENT 'Omzet L1 yang dibakar (integer IDR, M8)',
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_id` INT UNSIGNED DEFAULT NULL COMMENT 'Admin yang approve/reject',
  `admin_notes` VARCHAR(255) DEFAULT NULL COMMENT 'Catatan admin (wajib via UI saat reject)',
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

-- -----------------------------------------------------
-- Table `system_settings` (key-value; circuit breaker Phase 9A + maintenance mode plan/95)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key_name` VARCHAR(50) NOT NULL,
  `key_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key_name` (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed is idempotent: never fails on duplicate key, never overwrites a live value.
INSERT IGNORE INTO `system_settings` (`key_name`, `key_value`) VALUES
('is_registration_open', '1'),
-- plan/95: Maintenance Mode member site (0=normal, 1=locked down; admin/CLI exempt).
('is_maintenance_mode', '0'),
-- M1 (plan/56): dynamic withdrawal/deposit financial config (PRD §121-125 defaults).
('wd_operational_days', '1,2,3,4,5,6'),
('wd_open_time', '07:00'),
('wd_close_time', '19:00'),
('wd_fixed_fee', '6500'),
('wd_fee_tiers', '[[100000,500000,1000],[500000,1000000,750],[1000000,2000000,650],[2000000,5000000,500],[5000000,10000000,400],[10000000,50000001,300]]'),
('wd_min_amount', '100000'),
('wd_max_amount', '50000000'),
('deposit_fee_enabled', '0'),
('deposit_fee_type', 'flat'),
('deposit_fee_value', '0'),
-- M7 (plan/70): contact/support keys migrated from decommissioned `site_settings`.
('wa_number', '628000000000'),
('support_email', 'support@synapse.id'),
-- plan/105: tautan grup/komunitas WhatsApp resmi. '' = tidak dikonfigurasi →
-- kartu Komunitas disembunyikan di halaman Bantuan member (fail-safe).
('wa_group_link', ''),
-- Plan 89: 3-tier affiliate purchase rebate config (defaults 5/3/1%; engine skips when disabled).
('rebate_enabled', '1'),
('rebate_l1_percent', '5'),
('rebate_l2_percent', '3'),
('rebate_l3_percent', '1'),
-- plan/102: gateway deposit QRIS manual — identitas pembayaran (konten tampilan
-- member) + kebijakan deposit (dipakai jalur uang; fallback di
-- application/config/withdrawal_fees.php bila baris hilang/rusak).
('qris_image', ''),
('qris_merchant_name', 'Synapse'),
('qris_payment_instructions', 'Scan QRIS di atas menggunakan aplikasi bank/e-wallet Anda, lalu transfer sejumlah TEPAT nominal yang tertera (termasuk 3 digit kode unik). Deposit diverifikasi manual oleh admin pada jam kerja.'),
('deposit_expiry_minutes', '60'),
('deposit_min_amount', '10000'),
('deposit_max_amount', '50000000'),
-- plan/112: Absensi Harian (daily check-in). `checkin_enabled` = gerbang
-- fail-closed (0 = widget disembunyikan DAN endpoint klaim menolak).
-- `checkin_streak_policy`: 'reset' = bolong sehari → mulai lagi dari hari 1;
-- 'continue' = lanjut ke N+1 (gap tidak mereset). Bonus = min(base × hari, max);
-- fallback per-key di application/config/checkin_rewards.php.
-- plan/118: `checkin_require_active_rental` = gerbang syarat SEWA AKTIF
-- (0 = OFF/default). Bila '1', klaim absensi menuntut minimal 1 kontrak
-- `user_rentals` dengan status='active' DAN expired_at > now WIB (trial
-- maupun berbayar). Bonus dikredit ke dompet `checkin` (users.checkin_balance),
-- bukan saldo utama.
('checkin_enabled', '1'),
('checkin_base_reward', '50'),
('checkin_max_reward', '10000'),
('checkin_streak_policy', 'reset'),
('checkin_require_active_rental', '0'),
-- plan/119: BONUS PENCAPAIAN TIM (Team Milestone Bonus) — PENGGANTI Gaji
-- Mingguan. `team_milestone_enabled` = gerbang fail-closed ('0' → kartu member
-- TIDAK dirender DAN endpoint `POST /team/claim_milestone` menolak HTTP 403
-- dengan `code: 'disabled'`).
-- `team_milestone_tiers` = JSON array tuple `[[target_downline_aktif, nominal_idr], …]`
-- kanonik MENAIK (preseden `wd_fee_tiers`). Amandemen: satu tier dibayar
-- SEKALI seumur hidup per user (tanpa cooldown/siklus), klaim berurutan dari
-- tier terendah, kredit ke SALDO UTAMA (`wallet_type = 'main'`).
-- Seed default = mirror tier gaji lama (9/30/70/130/190) agar ambang & nominal
-- yang sudah dipublikasikan tidak berubah — hanya periodenya (per minggu →
-- sekali). Fallback per-key: application/config/team_milestones.php.
-- Ambang administratif (max 20 tier, target 1..100000, nominal 1..100000000)
-- hidup di aplikasi/helpers/team_milestone_helper.php — BUKAN di sini.
('team_milestone_enabled', '1'),
('team_milestone_tiers', '[[9,200000],[30,1000000],[70,2500000],[130,5000000],[190,9000000]]'),
-- plan/121: LUCKY SPIN WHEEL — tiket referral + roda hadiah.
-- `spin_wheel_enabled` = gerbang fail-closed ('0' → UI disembunyikan DAN
-- endpoint `POST /lucky-spin/spin` menolak HTTP 403 `code:'disabled'`).
-- `spin_wheel_segments` = JSON array tuple `[[nominal_idr, bobot], …]`;
-- URUTAN array = urutan segmen pada roda. Invarian: 2..12 segmen,
-- setiap nominal ≥ 1 (TIDAK ada segmen Rp 0 — keputusan D3) dan bobot ≥ 1.
-- Ambang administratif hidup di application/helpers/spin_wheel_helper.php
-- (satu sumber: validator admin + CLI `--verify`) — BUKAN di sini.
-- Fallback per-key: application/config/spin_wheel.php.
('spin_wheel_enabled', '1'),
('spin_wheel_segments', '[[5000,30],[10000,25],[25000,18],[50000,10],[100000,6],[150000,3],[250000,2],[500000,1]]');

-- -----------------------------------------------------
-- Table `system_audit_logs` — Phase 10 baseline (ERD §6)
-- No code writes to this until Phase 10A; created now per audit-report prerequisite.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED DEFAULT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_admin_id` (`admin_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_action` (`action`),
  INDEX `idx_created_at` (`created_at`),
  CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `rate_limits` — Phase 10B (rate limiting & brute force)
-- Satu baris per composite key (endpoint + identitas). Baris pendek
-- umurnya (GC ≤ 30 menit); tidak perlu FK (bukan data bisnis).
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rate_key` VARCHAR(191) NOT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_attempt_at` DATETIME NOT NULL,
  `locked_until` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rate_key` (`rate_key`),
  INDEX `idx_last_attempt_at` (`last_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Seed `gpu_products` (plan/82 + plan/83): DB canonical — Product_model tidak lagi
-- memakai fallback mock. 8 paket komersial final (id 1-8 eksplisit agar
-- referensi `user_rentals.product_id` lama tetap valid; nilai adalah lineup
-- resmi Rp 150.000 s.d. Rp 10.000.000, semuanya integer IDR) + 1 paket trial
-- plan/114 (id 9, `is_trial` = 1).
-- plan/87: gating & limits engine disederhanakan — `max_per_user` (0 = tanpa
-- batas) tetap aktif sebagai satu-satunya batas pembelian per-user;
-- `unlock_prerequisite_id` DICOMMISSIONED (rantai progresif 4→5→6→7→8
-- dihapus): ketersediaan produk 100% via toggle admin `is_active`.
-- Idempotent-uppsert: ON DUPLICATE KEY UPDATE menyegarkan baris id 1-4 bila
-- sudah ada (migrasi lineup), menyisipkan id 5-9 pada instalasi bersih, dan
-- mengunci nilai gating/limits kanonik pada setiap re-run.
-- ⚠️ BLOK INI UNTUK INSTALASI BERSIH / DB DUMMY SAJA. JANGAN dijalankan pada
--    DB yang sudah berisi lineup berbeda (id live ≠ id seed — mis. pada DB
--    aktif id 9 = 'RTX 4090 Pro'): penulisan `id` eksplisit akan menimpa baris
--    tersebut. Untuk DB yang SUDAH ADA gunakan migrasi plan/114 yang DIKUNCI
--    OLEH `name` (lihat blok "Plan 114 — MIGRASI LIVE" di bawah).
-- -----------------------------------------------------
INSERT INTO `gpu_products` (`id`, `name`, `type`, `price`, `daily_rate`, `duration_days`, `is_refundable`, `max_per_user`, `unlock_prerequisite_id`, `is_active`, `is_trial`) VALUES
(1, 'RTX 3060 Starter', 'short_term', 150000.00, 7500.00, 25, 0, 1, NULL, 1, 0),
(2, 'RTX 4060 Lite', 'short_term', 300000.00, 13500.00, 30, 0, 2, NULL, 1, 0),
(3, 'RTX 4070 Basic', 'short_term', 600000.00, 28000.00, 30, 0, 3, NULL, 1, 0),
(4, 'RTX 4080 Prime', 'short_term', 1200000.00, 57600.00, 35, 0, 5, NULL, 1, 0),
(5, 'RTX 4090 Pro', 'long_term', 2500000.00, 125000.00, 40, 0, 5, NULL, 1, 0),
(6, 'A100 Cloud Cluster', 'long_term', 4500000.00, 234000.00, 45, 0, 5, NULL, 1, 0),
(7, 'H100 Tensor Node', 'long_term', 7000000.00, 378000.00, 50, 0, 0, NULL, 1, 0),
(8, 'H200 Sovereign', 'long_term', 10000000.00, 560000.00, 60, 0, 0, NULL, 1, 0),
-- plan/114: PRODUK TRIAL ("GPU Magang") — Rp 0 / Rp 10.000 per hari / 3 hari
-- (total potensi Rp 30.000 lewat mesin klaim ROI existing), batas 1x per user.
-- id 9 pada INSTALASI BERSIH. Produk trial TIDAK BOLEH memakai id yang ada di
-- peta reward promotor (`application/config/promoter_rewards.php`) — pada
-- instalasi ini peta = id 5..8.
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

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------
-- Plan 94 (F2) — MIGRASI LIVE (one-time; jalankan manual di DB aktif):
-- `idx_status_created` sudah masuk CREATE TABLE di atas (instalasi baru
-- otomatis). Untuk DB yang SUDAH ADA, jalankan SEKALI:
--
--   MySQL 8 (tanpa IF NOT EXISTS):
--   ALTER TABLE `deposits`    ADD INDEX `idx_status_created` (`status`, `created_at`);
--   ALTER TABLE `withdrawals` ADD INDEX `idx_status_created` (`status`, `created_at`);
--
--   MariaDB (mendukung IF NOT EXISTS — idempotent-safe):
--   CREATE INDEX IF NOT EXISTS `idx_status_created` ON `deposits`    (`status`, `created_at`);
--   CREATE INDEX IF NOT EXISTS `idx_status_created` ON `withdrawals` (`status`, `created_at`);
--
-- Tujuan: COUNT antrean pending (Admin_model::get_alert_counts) memakai
-- leading-status → index-scan sub-ms; listing Command Center yang memakai
-- ORDER BY created_at ASC juga terlayani index yang sama.
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Plan 102 — MIGRASI LIVE (one-time; jalankan manual di DB aktif).
-- Kolom/enum/index di atas sudah masuk CREATE TABLE (instalasi baru otomatis).
-- Untuk DB yang SUDAH ADA, gunakan tool yang sudah menyertakan BACKFILL dan
-- verifikasi (JANGAN jalankan ALTER mentah tanpa backfill — baris lama akan
-- kehilangan total_amount & expires_at):
--
--   php scripts/migrate_102_qris_deposits.php --dry-run   # inspeksi, tanpa tulis
--   php scripts/migrate_102_qris_deposits.php --apply     # DDL + backfill + verify
--
-- Referensi SQL yang dijalankan tool tersebut (urutan wajib):
--
--   1) ALTER TABLE `deposits`
--        MODIFY `status` ENUM('pending','waiting_approval','success','failed','rejected','expired')
--               NOT NULL DEFAULT 'pending',
--        ADD COLUMN `unique_code`       SMALLINT UNSIGNED NULL DEFAULT NULL AFTER `amount`,
--        ADD COLUMN `total_amount`      DECIMAL(15,2)     NOT NULL DEFAULT 0.00 AFTER `unique_code`,
--        ADD COLUMN `reserved_code_key` VARCHAR(24)       NULL DEFAULT NULL AFTER `total_amount`,
--        ADD COLUMN `expires_at`        TIMESTAMP         NULL DEFAULT NULL AFTER `reserved_code_key`,
--        ADD COLUMN `confirmed_at`      TIMESTAMP         NULL DEFAULT NULL AFTER `expires_at`,
--        ADD COLUMN `processed_at`      TIMESTAMP         NULL DEFAULT NULL AFTER `confirmed_at`,
--        ADD COLUMN `decline_reason`    VARCHAR(255)      NULL DEFAULT NULL AFTER `processed_at`,
--        ADD UNIQUE KEY `uk_reserved_code_key` (`reserved_code_key`),
--        ADD INDEX      `idx_status_expires`   (`status`, `expires_at`);
--
--   2) UPDATE `deposits` SET `total_amount` = `amount` WHERE `total_amount` = 0;
--      -- parity kredit: sebelum plan/102 jalur approve mengkredit kolom `amount`.
--
--   3) UPDATE `deposits`
--         SET `expires_at` = DATE_ADD('<WIB now>', INTERVAL 60 MINUTE)
--       WHERE `status` = 'pending' AND `expires_at` IS NULL;
--      -- jendela bayar baru untuk invoice produksi yang masih menggantung
--      -- (tanpa ini baris lama langsung tersapu 'expired' oleh sweep).
--
--   4) Verifikasi invarian (WAJIB 0 baris / 0 inconsistent):
--      SELECT COUNT(*) FROM `deposits`
--       WHERE (status IN ('pending','waiting_approval')) <> (reserved_code_key IS NOT NULL);
--
-- Catatan: `ADD COLUMN` tidak idempoten di MySQL 8 (tanpa IF NOT EXISTS);
-- MariaDB mendukung `ADD COLUMN IF NOT EXISTS`. Tool CLI sudah memeriksa
-- information_schema lebih dulu sehingga aman dijalankan ulang.
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Plan 104 — MIGRASI LIVE (one-time; jalankan manual di DB aktif).
-- Kolom `image` sudah masuk CREATE TABLE `gpu_products` di atas (instalasi
-- baru otomatis). Untuk DB yang SUDAH ADA, gunakan tool yang sudah
-- menyertakan BACKFILL + verifikasi:
--
--   php scripts/migrate_104_gpu_product_images.php --dry-run   # inspeksi
--   php scripts/migrate_104_gpu_product_images.php --apply     # DDL + backfill + verify
--   php scripts/migrate_104_gpu_product_images.php --verify    # read-only, 8/8
--
-- Referensi SQL yang dijalankan tool tersebut (urutan wajib):
--
--   1) ALTER TABLE `gpu_products`
--        ADD COLUMN `image` VARCHAR(255) NULL DEFAULT NULL AFTER `name`;
--      -- MariaDB (idempoten): ADD COLUMN IF NOT EXISTS `image` …
--
--   2) Backfill 8 paket kanonik — KEYED BY `name`, BUKAN `id` (id live
--      bisa 5-12 sementara seed kanonik memakai id 1-8; prefix angka pada
--      nama berkas = urutan lineup, bukan id):
--        UPDATE `gpu_products` SET `image` = 'product1-rtx-3060-starter.jpeg'
--         WHERE `name` = 'RTX 3060 Starter' AND `image` IS NULL;   -- … dst 8 baris
--      -- Predikat `image IS NULL` menjaga gambar hasil upload admin TIDAK
--      -- pernah ditimpa oleh re-run migrasi.
--
--   3) Verifikasi (harapan: 8 baris terisi, 0 baris rusak):
--        SELECT COUNT(*) FROM `gpu_products` WHERE `image` IS NOT NULL;
--        SELECT id, name, image FROM `gpu_products` WHERE `is_active` = 1 ORDER BY id;
--
-- Catatan: kolom hanya menyimpan BASENAME; prefix `uploads/products/`
-- di-resolve application/helpers/product_image_helper.php. Berkas fisik
-- bersifat runtime (di-gitignore) → instalasi bersih yang belum memiliki
-- aset akan menampilkan fallback banner, bukan broken image.
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Plan 106 — MIGRASI LIVE (one-time; jalankan manual di DB aktif).
-- Tabel `ewallet_providers` + seed 4 provider sudah masuk blok CREATE TABLE
-- di atas (instalasi baru otomatis). Untuk DB yang SUDAH ADA — yang masih
-- menyimpan binding rekening bank legacy — gunakan tool yang menyertakan
-- BACKFILL + ARSIP + verifikasi:
--
--   php scripts/migrate_106_ewallet_withdrawal.php --dry-run   # inspeksi, tanpa tulis
--   php scripts/migrate_106_ewallet_withdrawal.php --apply     # DDL + seed + backfill + arsip + verify
--   php scripts/migrate_106_ewallet_withdrawal.php --verify    # read-only; exit 2 bila drift
--
-- Referensi SQL yang dijalankan tool tersebut (urutan wajib):
--
--   1) CREATE TABLE IF NOT EXISTS `ewallet_providers` ( … );   -- §DDL di atas
--
--   2) INSERT IGNORE INTO `ewallet_providers` (`code`,`name`,`is_active`) VALUES
--        ('DANA','DANA',1),('SHOPEEPAY','ShopeePay',1),
--        ('OVO','OVO',1),('GOPAY','GoPay',1);
--      -- INSERT IGNORE: re-run TIDAK pernah menimpa rename/status admin.
--
--   3) Backfill nama bank legacy → provider (kolom utf8mb4_unicode_ci = CI):
--        UPDATE `bank_accounts` SET `bank_name` = 'DANA'
--         WHERE LOWER(`bank_name`) IN ('bca','bank bca','bank central asia (bca)',
--                                      'bni','bank negara indonesia (bni)');
--        UPDATE `bank_accounts` SET `bank_name` = 'OVO'
--         WHERE LOWER(`bank_name`) IN ('mandiri','bank mandiri');
--        UPDATE `bank_accounts` SET `bank_name` = 'GoPay'
--         WHERE LOWER(`bank_name`) IN ('bri','bank rakyat indonesia (bri)');
--        UPDATE `bank_accounts` SET `bank_name` = 'ShopeePay'
--         WHERE LOWER(`bank_name`) IN ('cimb','bank cimb niaga');
--        -- sisa nama tak dikenal → provider default (DANA)
--        UPDATE `bank_accounts` SET `bank_name` = 'DANA'
--         WHERE `bank_name` NOT IN (SELECT `name` FROM `ewallet_providers`);
--
--   4) Arsipkan binding legacy (nomor bank BUKAN nomor HP e-wallet) → member
--      wajib re-bind. TIDAK PERNAH menghapus baris (FK RESTRICT + riwayat):
--        UPDATE `bank_accounts` SET `is_primary` = 0
--         WHERE `is_primary` = 1 AND `account_number` NOT REGEXP '^08[0-9]{8,11}$';
--
--   5) Verifikasi invarian (WAJIB: 4 / 0 / 0 / 0):
--        SELECT COUNT(*) FROM `ewallet_providers`
--         WHERE `is_active`=1 AND `code` IN ('DANA','SHOPEEPAY','OVO','GOPAY');
--        SELECT COUNT(*) FROM `bank_accounts` b
--          LEFT JOIN `ewallet_providers` p ON p.`name` = b.`bank_name`
--         WHERE b.`is_primary`=1 AND p.`id` IS NULL;                 -- drift = 0
--        SELECT COUNT(*) FROM `bank_accounts`
--         WHERE `is_primary`=1 AND `account_number` NOT REGEXP '^08[0-9]{8,11}$';
--        SELECT COUNT(*) FROM `withdrawals` w
--          LEFT JOIN `bank_accounts` b ON b.`id` = w.`bank_account_id`
--         WHERE w.`bank_account_id` IS NOT NULL AND b.`id` IS NULL;  -- orphan = 0
--
-- Catatan: arsip reversibel — `UPDATE bank_accounts SET is_primary=1 WHERE id IN (…)`
-- memakai daftar ID yang dicetak tool. Menghapus baris TIDAK PERNAH menjadi
-- opsi: `fk_withdrawals_bank` ON DELETE RESTRICT dan seluruh kartu riwayat
-- penarikan membaca nama provider/nomor dari baris tersebut.
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Plan 112 — MIGRASI LIVE (one-time; jalankan manual di DB aktif).
-- Dua kolom `users` + empat kunci `system_settings` sudah masuk blok
-- CREATE TABLE / seed di atas (instalasi baru otomatis). Untuk DB yang SUDAH
-- ADA, gunakan tool yang menyertakan verifikasi (idempotent, tanpa backfill —
-- baris lama cukup memakai DEFAULT 0 / NULL):
--
--   php scripts/migrate_112_daily_checkin.php --dry-run   # inspeksi, tanpa tulis
--   php scripts/migrate_112_daily_checkin.php --apply     # DDL + seed + verify
--   php scripts/migrate_112_daily_checkin.php --verify    # read-only; exit 2 bila drift
--
-- Referensi SQL yang dijalankan tool tersebut (urutan wajib):
--
--   1) ALTER TABLE `users`
--        ADD COLUMN `checkin_streak`    INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_wage_claimed_at`,
--        ADD COLUMN `checkin_last_date` DATE         NULL DEFAULT NULL AFTER `checkin_streak`;
--      -- MariaDB (idempoten): ADD COLUMN IF NOT EXISTS …; MySQL 8 tidak punya
--      -- IF NOT EXISTS → tool memeriksa information_schema lebih dulu.
--
--   2) INSERT IGNORE INTO `system_settings` (`key_name`,`key_value`) VALUES
--        ('checkin_enabled','1'),('checkin_base_reward','50'),
--        ('checkin_max_reward','10000'),('checkin_streak_policy','reset');
--      -- INSERT IGNORE: re-run TIDAK pernah menimpa nilai yang diubah admin.
--
--   3) Verifikasi invarian (harapan: 2 kolom / 4 baris / 0 nilai rusak):
--        SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--          FROM information_schema.COLUMNS
--         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
--           AND COLUMN_NAME IN ('checkin_streak','checkin_last_date');
--        SELECT `key_name`, `key_value` FROM `system_settings`
--         WHERE `key_name` LIKE 'checkin\_%';
--        -- tamper (exit 2): enabled ∉ {'0','1'} / policy ∉ {'reset','continue'}
--        --   / base atau max bukan integer positif / base > max
--
-- Catatan: bonus DIBAYAR lewat `Wallet_model::credit()` dengan transaction_id
-- deterministik `'CHK-{user_id}-{Ymd}'` — UNIQUE (user_id, transaction_id, type)
-- menjadi jaminan tingkat DB "satu kredit per user per hari". Histori absensi
-- karena itu TIDAK memerlukan tabel baru: cukup query `wallet_ledger`
-- dengan prefix `CHK-%` (terlayani `idx_user_id`).
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Plan 114 — MIGRASI LIVE (one-time; jalankan manual di DB aktif).
-- Kolom `is_trial` + seed produk trial sudah masuk blok CREATE TABLE / seed di
-- atas (instalasi baru otomatis). Untuk DB yang SUDAH ADA, gunakan tool:
--
--   php scripts/migrate_114_trial_product_wd_gate.php --dry-run   # inspeksi, tanpa tulis
--   php scripts/migrate_114_trial_product_wd_gate.php --apply     # DDL + seed + verify
--   php scripts/migrate_114_trial_product_wd_gate.php --verify     # read-only; exit 2 bila drift
--
-- Referensi SQL yang dijalankan tool tersebut (urutan wajib):
--
--   1) ALTER TABLE `gpu_products`
--        ADD COLUMN `is_trial` TINYINT(1) NOT NULL DEFAULT 0 AFTER `max_per_user`;
--      -- TANPA backfill: DEFAULT 0 mengisi seluruh baris lama (semua non-trial).
--      -- MariaDB: ADD COLUMN IF NOT EXISTS …; MySQL 8 tidak punya IF NOT EXISTS
--      -- → tool memeriksa information_schema lebih dulu (idempoten).
--
--   2) INSERT INTO `gpu_products` … ON DUPLICATE KEY UPDATE — DIKUNCI OLEH `name`
--        ('GPU Magang (Trial)'), BUKAN oleh `id`: id live ≠ id seed (preseden
--        plan/104). Nilai: type='short_term', price=0, daily_rate=10000,
--        duration_days=3, is_refundable=0, max_per_user=1, is_active=1,
--        is_trial=1.
--      -- Nama produk TIDAK PERNAH menjadi identitas teknis aplikasi
--      -- (identitas = `is_trial`); `name` hanya kunci pencocokan migrasi.
--
--   3) Verifikasi invarian (harapan: 1 baris trial / 0 inconsistent):
--        SELECT COUNT(*) FROM `gpu_products` WHERE `is_trial` = 1;            -- = 1
--        SELECT COUNT(*) FROM `gpu_products`
--         WHERE `is_trial` = 1 AND (`price` <> 0 OR `max_per_user` <> 1
--                                    OR `daily_rate` <> 10000 OR `duration_days` <> 3
--                                    OR `is_active` <> 1);                   -- = 0
--        -- tamper (exit 2): > 1 baris is_trial=1, atau id baris trial masuk
--        -- peta reward promotor (`application/config/promoter_rewards.php`)
--
-- ⚠️ URUTAN RILIS: migrasi ini WAJIB dijalankan SEBELUM kode baru di-deploy —
--    `Rental_model::has_paid_rental()` (gerbang penarikan) dan GATE 0
--    `checkout_rental()` membaca `gpu_products.is_trial`. Kolom yang belum ada
--    = error query pada /wallet, /wallet/withdraw, dan checkout.
--
-- Catatan ekonomi (mengapa TIDAK ada `_add_omzet` untuk di-bypass): omzet
-- upline dihitung DERIVATIF — `Promoter_model::get_omzet_summary()` =
-- `COALESCE(SUM(ur.purchase_price), 0)`. Kontrak trial ber-`purchase_price` 0
-- menyumbang 0 secara otomatis; rebate 3-tier dilewati di `checkout_rental()`
-- dan debit saldo TIDAK dijalankan (`Wallet_model::_post()` menolak amount ≤ 0,
-- sehingga Rp 0 tidak pernah menghasilkan baris `wallet_ledger`).
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Plan 117 — MIGRASI LIVE (one-time; jalankan manual di DB aktif).
-- Dua kolom `users` sudah masuk blok CREATE TABLE di atas (instalasi baru
-- otomatis). Untuk DB yang SUDAH ADA, gunakan tool:
--
--   php scripts/migrate_117_user_wd_lock.php --dry-run   # inspeksi, tanpa tulis
--   php scripts/migrate_117_user_wd_lock.php --apply     # DDL + normalize + verify
--   php scripts/migrate_117_user_wd_lock.php --verify    # read-only; exit 2 bila drift
--
-- Referensi SQL yang dijalankan tool tersebut (urutan wajib):
--
--   1) ALTER TABLE `users`
--        ADD COLUMN `is_wd_locked`   TINYINT(1)   NOT NULL DEFAULT 0    AFTER `checkin_last_date`,
--        ADD COLUMN `wd_lock_reason` VARCHAR(255) NULL     DEFAULT NULL AFTER `is_wd_locked`;
--      -- TANPA backfill: DEFAULT 0 mengisi seluruh baris lama (semua terbuka).
--      -- `AFTER` hanya valid di ALTER TABLE … ADD COLUMN — JANGAN dipakai di
--      -- dalam `CREATE TABLE` (F17, plan/116).
--      -- MariaDB: ADD COLUMN IF NOT EXISTS …; MySQL 8 tidak punya IF NOT EXISTS
--      -- → tool memeriksa information_schema lebih dulu (idempoten).
--
--   2) Normalisasi invarian W4 (wajib, idempoten):
--        UPDATE `users` SET `wd_lock_reason` = NULL
--         WHERE `is_wd_locked` = 0 AND `wd_lock_reason` IS NOT NULL;
--
--   3) Verifikasi invarian (harapan: 2 kolom / 0 orphan):
--        SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--          FROM information_schema.COLUMNS
--         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
--           AND COLUMN_NAME IN ('is_wd_locked','wd_lock_reason');
--        SELECT COUNT(*) FROM `users`
--         WHERE `is_wd_locked` = 0 AND `wd_lock_reason` IS NOT NULL;   -- = 0
--        -- tamper (exit 2): tipe/nullability/default kolom tidak sesuai, atau
--        --   orphan reason > 0. `is_wd_locked = 1` tanpa alasan hanya [WARN]
--        --   (legit: admin bisa mengunci lalu lupa mengisi — data tetap sah).
--
-- ⚠️ URUTAN RILIS: migrasi ini WAJIB dijalankan SEBELUM deploy kode plan/117 —
--    `Admin_model::get_users()` (kolom `is_wd_locked`/`wd_lock_reason`),
--    `Wallet_model::is_withdrawal_locked()` (gate TX penarikan), dan
--    `Admin::toggle_wd_lock()` membaca kolom tersebut. Kolom yang belum ada =
--    error query pada /admin/users, /admin/user_detail, dan /wallet.
--
-- Rollback manual (tidak dilakukan tool; hanya bila benar-benar perlu):
--    ALTER TABLE `users` DROP COLUMN `wd_lock_reason`, DROP COLUMN `is_wd_locked`;
--
-- Catatan non-keuangan (mengapa lock TIDAK menyentuh ledger): kunci hanya
-- memblokir PENGAJUAN baru (keputusan D1) — penarikan `pending` dibiarkan dan
-- tetap diproses admin, sehingga tidak ada baris `wallet_ledger` maupun
-- `withdrawals` yang ditulis/dibatalkan oleh lock/unlock (D2, imutabilitas Z1).
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Plan 118 — MIGRASI LIVE (one-time; jalankan manual di DB aktif).
-- Satu kolom `users`, satu kolom `wallet_ledger`, dan satu key
-- `system_settings` sudah masuk blok CREATE TABLE / seed di atas (instalasi
-- baru otomatis). Untuk DB yang SUDAH ADA, gunakan tool:
--
--   php scripts/migrate_118_checkin_refactor.php --dry-run   # inspeksi, tanpa tulis
--   php scripts/migrate_118_checkin_refactor.php --apply     # DDL + seed + normalize + verify
--   php scripts/migrate_118_checkin_refactor.php --verify    # read-only; exit 2 bila drift
--
-- Referensi SQL yang dijalankan tool tersebut (urutan wajib):
--
--   1) ALTER TABLE `users`
--        ADD COLUMN `checkin_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00
--        AFTER `balance`;
--   2) ALTER TABLE `wallet_ledger`
--        ADD COLUMN `wallet_type` ENUM('main','checkin') NOT NULL DEFAULT 'main'
--        AFTER `type`;
--      -- TANPA backfill: DEFAULT mengisi seluruh baris lama
--      --   (`checkin_balance` = 0.00 → semua user mulai dari 0; keputusan D3;
--      --    `wallet_type` = 'main' → bonus absensi historis tetap di saldo utama).
--      -- `AFTER` hanya valid di ALTER TABLE … ADD COLUMN — JANGAN dipakai di
--      -- dalam `CREATE TABLE` (F17, plan/116).
--      -- MariaDB: ADD COLUMN IF NOT EXISTS …; MySQL 8 tidak punya IF NOT EXISTS
--      -- → tool memeriksa information_schema lebih dulu (idempoten).
--      -- INDEX TIDAK ditambahkan: seluruh agregat dompet memakai predikat
--      -- `user_id = ?` (terlayani `idx_user_id`); `wallet_type` hanya
--      -- mempersempit hasil dalam rentang baris satu user.
--      -- UNIQUE `uk_wallet_ledger_user_tx_type (user_id, transaction_id, type)`
--      -- TIDAK berubah → idempotensi harian absensi tetap utuh.
--
--   3) Seed idempoten (nilai yang sudah ada TIDAK ditimpa):
--        INSERT INTO `system_settings` (`key_name`,`key_value`)
--             VALUES ('checkin_require_active_rental','0')
--        ON DUPLICATE KEY UPDATE `key_name` = `key_name`;
--
--   4) Normalisasi defensif (harapan 0 baris):
--        UPDATE `wallet_ledger` SET `wallet_type` = 'main'
--         WHERE `wallet_type` IS NULL;
--
--   5) Verifikasi invarian (harapan: 2 kolom + 1 key, tanpa tamper):
--        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--          FROM information_schema.COLUMNS
--         WHERE TABLE_SCHEMA = DATABASE()
--           AND (TABLE_NAME = 'users'         AND COLUMN_NAME = 'checkin_balance'
--             OR TABLE_NAME = 'wallet_ledger' AND COLUMN_NAME = 'wallet_type');
--        SELECT `key_value` FROM `system_settings`
--         WHERE `key_name` = 'checkin_require_active_rental';            -- = 0
--        -- tamper (exit 2): tipe/nullability/default kolom tidak sesuai, ENUM
--        --   bukan tepat ('main','checkin'), atau key absen.
--
-- ⚠️ URUTAN RILIS: migrasi ini WAJIB dijalankan SEBELUM deploy kode plan/118 —
--    `Wallet_model::_post()` menulis kolom cache (`balance`/`checkin_balance`),
--    `Wallet_model::lock_and_get_balances()` membaca `wallet_ledger.wallet_type`,
--    `Checkin_model::claim()` mengkredit dompet `checkin`,
--    `Rental_model::checkout_rental()` melakukan split debit, dan seluruh
--    agregat `Admin_model` memfilter per dompet. Kolom yang belum ada = error
--    query pada /home, /wallet, /marketplace, /admin/settings, /admin/users.
--
-- Rollback manual (tidak dilakukan tool; hanya bila benar-benar perlu):
--    ALTER TABLE `wallet_ledger` DROP COLUMN `wallet_type`;
--    ALTER TABLE `users` DROP COLUMN `checkin_balance`;
--    DELETE FROM `system_settings` WHERE `key_name` = 'checkin_require_active_rental';
--
-- Catatan non-keuangan: tidak ada baris `wallet_ledger` lama yang diubah —
-- ledger tetap append-only (Z1). Bonus absensi historis TIDAK dipindahkan ke
-- dompet checkin (keputusan D3): hanya kredit absensi BARU yang masuk
-- `checkin_balance`.
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Plan 119 — MIGRASI LIVE (one-time; jalankan manual di DB aktif).
-- Satu kolom `users` + dua key `system_settings` sudah masuk blok
-- CREATE TABLE / seed di atas (instalasi baru otomatis). Untuk DB yang SUDAH
-- ADA, gunakan tool:
--
--   php scripts/migrate_119_team_milestone.php --dry-run        # inspeksi, tanpa tulis
--   php scripts/migrate_119_team_milestone.php --apply          # DDL + seed + backfill + verify
--   php scripts/migrate_119_team_milestone.php --verify         # read-only; exit 2 bila drift
--   php scripts/migrate_119_team_milestone.php --skip-backfill  # lewati fase 3 (O3)
--
-- Referensi SQL yang dijalankan tool tersebut (urutan wajib):
--
--   1) ALTER TABLE `users`
--        ADD COLUMN `team_milestone_claimed` VARCHAR(255) NULL DEFAULT NULL
--        AFTER `last_wage_claimed_at`;
--      -- TANPA backfill otomatis: NULL = belum ada klaim. Fase 3 (O3) mengisi
--      -- kolom ini HANYA untuk user yang historisnya sudah menerima
--      -- Gaji Mingguan — menandai tier yang setara sebagai "sudah diklaim"
--      -- (tanpa uang baru, tanpa baris `wallet_ledger`).
--      -- `AFTER` hanya valid di ALTER TABLE … ADD COLUMN — JANGAN dipakai di
--      -- dalam `CREATE TABLE` (F17, plan/116).
--      -- MariaDB: ADD COLUMN IF NOT EXISTS …; MySQL 8 tidak punya IF NOT EXISTS
--      -- → tool memeriksa information_schema lebih dulu (idempoten).
--      -- INDEX TIDAK ditambahkan: seluruh baca state memakai `user_id = ?`
--      -- (terlayani `idx_user_id`) atau PK `id`.
--
--   2) Seed idempoten (nilai yang sudah ada TIDAK ditimpa):
--        INSERT INTO `system_settings` (`key_name`,`key_value`) VALUES
--          ('team_milestone_enabled','1'),
--          ('team_milestone_tiers','[[9,200000],[30,1000000],[70,2500000],[130,5000000],[190,9000000]]')
--        ON DUPLICATE KEY UPDATE `key_name` = `key_name`;
--
--   3) Grandfathering (keputusan owner O3 — opsional, `--skip-backfill` untuk melewati):
--        SELECT `user_id`, `description`, `amount`
--          FROM `wallet_ledger`
--         WHERE `type` = 'credit' AND `transaction_id` LIKE 'WAGE-%';
--      -- Tool menghitung pencapaian tertinggi per user dari prosa deskripsi
--      -- "Gaji Mingguan Level N — M downline aktif" (regex `(\d+) downline aktif`),
--      -- dengan PROXY nominal (`amount` terbesar → tier dengan nominal ≤ itu)
--      -- bila prosa tidak parseable, lalu:
--        UPDATE `users` SET `team_milestone_claimed` = '<csv target terklaim>'
--         WHERE `id` = ? AND `team_milestone_claimed` IS NULL;
--      -- Guard `IS NULL` ⇒ re-run = 0 baris (tidak pernah menimpa klaim baru
--      -- pasca-deploy).
--      -- ⚠️ Jalankan `--apply` SEGERA setelah deploy: fase ini memakai
--      --    konfigurasi tier SAAT ITU (seed default = mirror tier lama).
--
--   4) Verifikasi invarian (harapan: 1 kolom + 2 key, tanpa tamper):
--        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--          FROM information_schema.COLUMNS
--         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
--           AND COLUMN_NAME = 'team_milestone_claimed';
--        SELECT `key_name`, `key_value` FROM `system_settings`
--         WHERE `key_name` LIKE 'team_milestone\_%';
--        -- tamper (exit 2): tipe/nullability kolom tidak sesuai; enabled ∉ {'0','1'};
--        --   `tiers` bukan JSON `[[int,int],…]` valid; target duplikat/tidak menaik;
--        --   di luar ambang (max 20 tier, target 1..100000, nominal 1..100000000);
--        --   atau nilai `team_milestone_claimed` tidak parseable.
--
-- ⚠️ URUTAN RILIS: migrasi ini WAJIB dijalankan SEBELUM deploy kode plan/119 —
--    `User_model::get_milestone_config()` membaca `system_settings`
--    (`team_milestone_enabled`/`team_milestone_tiers`) dan
--    `User_model::claim_milestone()`/`get_milestone_status()` membaca kolom
--    `users.team_milestone_claimed`. Kolom/key yang belum ada = error query
--    pada /team dan /admin/settings. Endpoint `POST /team/claim_milestone`
--    baru ada setelah deploy, jadi ambang risiko terbatas pada dua halaman itu.
--
-- Rollback manual (tidak dilakukan tool; hanya bila benar-benar perlu):
--    ALTER TABLE `users` DROP COLUMN `team_milestone_claimed`;
--    DELETE FROM `system_settings`
--     WHERE `key_name` IN ('team_milestone_enabled','team_milestone_tiers');
--
-- Catatan non-keuangan: fase backfill HANYA menulis state klaim (nol baris
-- `wallet_ledger`, nol mutasi saldo, nol notifikasi). Seluruh baris ledger
-- historis `WAGE-*` dipertahankan apa adanya (Z1, keputusan D10).
-- -----------------------------------------------------

-- -----------------------------------------------------
-- MIGRASI LIVE (plan/121) — LUCKY SPIN WHEEL (Referral Reward System)
-- -----------------------------------------------------
-- Ringkas: 1 kolom `users` + 2 tabel BARU (`spin_ticket_grants`,
-- `spin_results`) + 2 kunci `system_settings`. SELURUH DDL di atas sudah
-- masuk blok CREATE TABLE (instalasi baru tidak butuh langkah tambahan).
--
-- Untuk DB yang sudah berjalan, jalankan (idempotent, `--dry-run` default):
--   php scripts/migrate_121_lucky_spin.php --dry-run
--   php scripts/migrate_121_lucky_spin.php --apply
--   php scripts/migrate_121_lucky_spin.php --verify      (read-only, exit 2 bila tamper)
--
-- DDL ekuivalen bila dijalankan manual:
--   1) ALTER TABLE `users`
--        ADD COLUMN `spin_tickets` INT UNSIGNED NOT NULL DEFAULT 0
--          AFTER `checkin_last_date`;
--   2) CREATE TABLE IF NOT EXISTS `spin_ticket_grants` ( … );
--   3) CREATE TABLE IF NOT EXISTS `spin_results` ( … );
--   4) INSERT IGNORE INTO `system_settings` (`key_name`,`key_value`) VALUES
--        ('spin_wheel_enabled','1'),
--        ('spin_wheel_segments','[[5000,30],[10000,25],[25000,18],[50000,10],
--                                 [100000,6],[150000,3],[250000,2],[500000,1]]');
--
-- ⚠️ URUTAN RILIS: jalankan migrasi SEBELUM deploy kode plan/121.
--    `Spin_model::get_config()` membaca kedua key `system_settings`, dan
--    `Spin_model::get_summary()`/`award_ticket_for_first_paid_rental()`
--    membaca kolom `users.spin_tickets` + dua tabel baru. Kolom/tabel/key yang
--    belum ada = error query pada /lucky-spin, /home (kartu tiket), dan
--    /admin/lucky-spin. `Rental_model::checkout_rental()` hanya menyentuh
--    jalur tiket pada pembelian NON-trial, jadi ambang risiko terbatas.
--
-- ⚠️ TANPA BACKFILL (keputusan owner D4): tiket mulai dihitung sejak rilis.
--    Downline yang sudah membeli produk non-trial SEBELUM plan/121 TIDAK
--    menghasilkan tiket retroaktif. Nol mutasi `wallet_ledger`, nol mutasi
--    `users.balance`, nol notifikasi — migration ini murni DDL + seed setelan.
--
-- Catatan drift (dicek `--verify`, exit 2 bila tidak konsisten):
--    `users.spin_tickets` WAJIB sama dengan
--      COUNT(`spin_ticket_grants` WHERE upline_id = id)
--      − COUNT(`spin_results` WHERE user_id = id)
--    Kolom adalah cache; otoritas = dua tabel event di atas (invarian SP6).
--
-- Rollback manual (tidak dilakukan tool; hanya bila benar-benar perlu):
--    DROP TABLE `spin_results`;
--    DROP TABLE `spin_ticket_grants`;
--    ALTER TABLE `users` DROP COLUMN `spin_tickets`;
--    DELETE FROM `system_settings`
--     WHERE `key_name` IN ('spin_wheel_enabled','spin_wheel_segments');
-- -----------------------------------------------------
