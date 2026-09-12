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
  `avatar_url` VARCHAR(255) DEFAULT NULL,
  `level_id` INT NOT NULL DEFAULT 0,
  `is_banned` TINYINT(1) NOT NULL DEFAULT 0,
  -- plan/91: flag promotor (admin-only). Bypass gating referral Condition A.
  `is_promoter` TINYINT(1) NOT NULL DEFAULT 0,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `is_level_1_claimed` TINYINT(1) NOT NULL DEFAULT 0,
  `last_wage_claimed_at` DATETIME NULL DEFAULT NULL,
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
-- Table `bank_accounts`
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
('deposit_max_amount', '50000000');

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
-- resmi Rp 150.000 s.d. Rp 10.000.000, semuanya integer IDR).
-- plan/87: gating & limits engine disederhanakan — `max_per_user` (0 = tanpa
-- batas) tetap aktif sebagai satu-satunya batas pembelian per-user;
-- `unlock_prerequisite_id` DICOMMISSIONED (rantai progresif 4→5→6→7→8
-- dihapus): ketersediaan produk 100% via toggle admin `is_active`.
-- Idempotent-uppsert: ON DUPLICATE KEY UPDATE menyegarkan baris id 1-4 bila
-- sudah ada (migrasi lineup), menyisipkan id 5-8 pada instalasi bersih, dan
-- mengunci nilai gating/limits kanonik pada setiap re-run.
-- -----------------------------------------------------
INSERT INTO `gpu_products` (`id`, `name`, `type`, `price`, `daily_rate`, `duration_days`, `is_refundable`, `max_per_user`, `unlock_prerequisite_id`, `is_active`) VALUES
(1, 'RTX 3060 Starter', 'short_term', 150000.00, 7500.00, 25, 0, 1, NULL, 1),
(2, 'RTX 4060 Lite', 'short_term', 300000.00, 13500.00, 30, 0, 2, NULL, 1),
(3, 'RTX 4070 Basic', 'short_term', 600000.00, 28000.00, 30, 0, 3, NULL, 1),
(4, 'RTX 4080 Prime', 'short_term', 1200000.00, 57600.00, 35, 0, 5, NULL, 1),
(5, 'RTX 4090 Pro', 'long_term', 2500000.00, 125000.00, 40, 0, 5, NULL, 1),
(6, 'A100 Cloud Cluster', 'long_term', 4500000.00, 234000.00, 45, 0, 5, NULL, 1),
(7, 'H100 Tensor Node', 'long_term', 7000000.00, 378000.00, 50, 0, 0, NULL, 1),
(8, 'H200 Sovereign', 'long_term', 10000000.00, 560000.00, 60, 0, 0, NULL, 1)
ON DUPLICATE KEY UPDATE 
  `name` = VALUES(`name`),
  `type` = VALUES(`type`),
  `price` = VALUES(`price`),
  `daily_rate` = VALUES(`daily_rate`),
  `duration_days` = VALUES(`duration_days`),
  `is_refundable` = VALUES(`is_refundable`),
  `max_per_user` = VALUES(`max_per_user`),
  `unlock_prerequisite_id` = NULL,
  `is_active` = VALUES(`is_active`);

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
