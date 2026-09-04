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
  `type` ENUM('short_term', 'long_term') NOT NULL,
  `price` DECIMAL(15,2) NOT NULL,
  `daily_rate` DECIMAL(15,2) NOT NULL,
  `duration_days` INT UNSIGNED NOT NULL,
  `is_refundable` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `rentals`
-- -----------------------------------------------------
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
  CONSTRAINT `fk_withdrawals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_withdrawals_bank` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `otp_logs`
-- -----------------------------------------------------
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
-- Table `deposits` (transaction invoices)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `deposits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `invoice_number` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `status` ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invoice_number` (`invoice_number`),
  INDEX `idx_user_status` (`user_id`, `status`),
  CONSTRAINT `fk_deposits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `user_rentals` (active/expired GPU leases)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_rentals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
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
CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(100) NOT NULL,
  `message` TEXT NOT NULL,
  `type` ENUM('info', 'warning', 'success', 'commission') NOT NULL DEFAULT 'info',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_read` (`user_id`, `is_read`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `system_settings` (key-value; circuit breaker Phase 9A)
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
('support_email', 'support@synapse.id');

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

SET FOREIGN_KEY_CHECKS = 1;
