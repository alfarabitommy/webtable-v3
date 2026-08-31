-- ============================================================================
-- Synapse (db_webtable) — Comprehensive Dummy Dataset Seed
-- Per approved plan: plan/10_DATABASE_SEED_PLAN.md
-- Safe & idempotent: explicit PKs + ON DUPLICATE KEY UPDATE; relative dates.
-- Execute via: php scripts/seed_database.php --apply
-- ============================================================================
SET NAMES utf8mb4;

-- ============ SECTION: reconcile_schema ============
-- Guarded ALTERs (the CLI runner checks information_schema before applying,
-- so this is a no-op on already-migrated databases).

-- [RECONCILE]
ALTER TABLE `users` ADD COLUMN `username` VARCHAR(50) NULL DEFAULT NULL;
-- [RECONCILE]
ALTER TABLE `users` ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'user';
-- [RECONCILE]
ALTER TABLE `users` ADD COLUMN `is_banned` TINYINT(1) NOT NULL DEFAULT 0;
-- [RECONCILE]
ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0;
-- [RECONCILE]
ALTER TABLE `users` ADD COLUMN `is_level_1_claimed` TINYINT(1) NOT NULL DEFAULT 0;
-- [RECONCILE]
ALTER TABLE `users` ADD COLUMN `last_wage_claimed_at` TIMESTAMP NULL DEFAULT NULL;
-- [RECONCILE]
ALTER TABLE `withdrawals` ADD COLUMN `wd_number` VARCHAR(50) NULL DEFAULT NULL;
-- [RECONCILE]
ALTER TABLE `withdrawals` ADD COLUMN `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00;
-- [RECONCILE]
ALTER TABLE `withdrawals` ADD UNIQUE KEY `uk_wd_number` (`wd_number`);

-- ============ SECTION: admins ============
INSERT INTO `admins` (`id`, `username`, `password`, `created_at`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', DATE_SUB(NOW(), INTERVAL 45 DAY))
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`), `password` = VALUES(`password`);

-- ============ SECTION: gpu_products ============
INSERT INTO `gpu_products` (`id`, `name`, `type`, `price`, `daily_rate`, `duration_days`, `is_refundable`, `is_active`, `created_at`) VALUES
(1, 'RTX 4090 Node (Entry)',          'short_term', 1000000.00,  40000.00,   30,  0, 1, DATE_SUB(NOW(), INTERVAL 90 DAY)),
(2, 'RTX 4090 Dual Cluster (Mid)',    'long_term',  5000000.00,  185000.00,  90,  1, 1, DATE_SUB(NOW(), INTERVAL 90 DAY)),
(3, 'A100 Tensor Cloud (High)',       'long_term',  15000000.00, 520000.00,  180, 1, 1, DATE_SUB(NOW(), INTERVAL 90 DAY)),
(4, 'H100 Sovereign Node (Enterprise)','long_term',  50000000.00, 1650000.00, 365, 1, 1, DATE_SUB(NOW(), INTERVAL 90 DAY))
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `type` = VALUES(`type`), `price` = VALUES(`price`),
                         `daily_rate` = VALUES(`daily_rate`), `duration_days` = VALUES(`duration_days`),
                         `is_refundable` = VALUES(`is_refundable`), `is_active` = VALUES(`is_active`);

-- ============ SECTION: users ============
-- 13 users, 3-tier MLM. parent_id rows are inserted before their children.
-- All passwords: bcrypt of 'password'.
INSERT INTO `users`
(`id`, `username`, `phone`, `password`, `invite_code`, `parent_id`, `balance`, `level_id`, `role`,
 `is_banned`, `must_change_password`, `is_level_1_claimed`, `last_wage_claimed_at`, `created_at`) VALUES
(1,  'vipleader',   '081234567890', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ABC123', NULL,  15280000.00, 0, 'user', 0, 0, 1, DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 45 DAY)),
(2,  'cofounder',   '081298765432', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'DEF456', NULL,  1560000.00,  0, 'user', 0, 0, 0, NULL,                             DATE_SUB(NOW(), INTERVAL 40 DAY)),
(3,  'agent_budi',  '085712345678', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'GHI789', 1,    1385000.00,  1, 'user', 0, 0, 0, NULL,                             DATE_SUB(NOW(), INTERVAL 50 DAY)),
(4,  'agent_sari',  '085798765432', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'JKL012', 1,    1000000.00,  1, 'user', 0, 0, 0, NULL,                             DATE_SUB(NOW(), INTERVAL 10 DAY)),
(5,  'agent_dewi',  '087811223344', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'MNO345', 1,    1200000.00,  1, 'user', 0, 0, 0, NULL,                             DATE_SUB(NOW(), INTERVAL 60 DAY)),
(6,  'agent_eka',   '087855667788', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'PQR678', 1,    0.00,        1, 'user', 0, 1, 0, NULL,                             DATE_SUB(NOW(), INTERVAL 15 DAY)),
(7,  'sub_fajar',   '085723456789', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'STU901', 3,    80000.00,    2, 'user', 0, 0, 0, NULL,                             DATE_SUB(NOW(), INTERVAL 12 DAY)),
(8,  'sub_gina',    '085798112233', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'VWX234', 3,    520000.00,   2, 'user', 0, 0, 0, NULL,                             DATE_SUB(NOW(), INTERVAL 8 DAY)),
(9,  'sub_hadi',    '087833445566', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'YZA567', 4,    185000.00,   2, 'user', 0, 0, 0, NULL,                             DATE_SUB(NOW(), INTERVAL 10 DAY)),
(10, 'sub_indah',   '087855667799', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'BCD890', 4,    1200000.00,  2, 'user', 0, 0, 0, NULL,                             DATE_SUB(NOW(), INTERVAL 35 DAY)),
(11, 'sub_joko',    '081277889900', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'EFG123', 5,    370000.00,   2, 'user', 0, 0, 0, NULL,                             DATE_SUB(NOW(), INTERVAL 9 DAY)),
(12, 'leaf_karin',  '081288990011', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'HIJ456', 7,    40000.00,    3, 'user', 0, 0, 0, NULL,                             DATE_SUB(NOW(), INTERVAL 2 DAY)),
(13, 'leaf_lutfi',  '081299001122', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'KLM789', 9,    0.00,        3, 'user', 1, 0, 0, NULL,                             DATE_SUB(NOW(), INTERVAL 3 DAY))
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`), `phone` = VALUES(`phone`), `password` = VALUES(`password`),
                         `invite_code` = VALUES(`invite_code`), `parent_id` = VALUES(`parent_id`), `balance` = VALUES(`balance`),
                         `level_id` = VALUES(`level_id`), `role` = VALUES(`role`), `is_banned` = VALUES(`is_banned`),
                         `must_change_password` = VALUES(`must_change_password`), `is_level_1_claimed` = VALUES(`is_level_1_claimed`);

-- ============ SECTION: bank_accounts ============
INSERT INTO `bank_accounts` (`id`, `user_id`, `bank_name`, `account_number`, `account_holder`, `is_primary`, `created_at`) VALUES
(1,  1,  'BCA',     '1234567890',      'Budi Santoso',       1, DATE_SUB(NOW(), INTERVAL 44 DAY)),
(2,  2,  'Mandiri', '1070001234567',   'Andi Wijaya',        1, DATE_SUB(NOW(), INTERVAL 39 DAY)),
(3,  3,  'BRI',     '002001234567890', 'Budi Santoso',       1, DATE_SUB(NOW(), INTERVAL 49 DAY)),
(4,  4,  'BNI',     '1234567890',      'Sari Lestari',       1, DATE_SUB(NOW(), INTERVAL 9 DAY)),
(5,  5,  'CIMB',    '8001234567',      'Dewi Anggraini',     1, DATE_SUB(NOW(), INTERVAL 59 DAY)),
(6,  6,  'BCA',     '0987654321',      'Eka Prasetya',       1, DATE_SUB(NOW(), INTERVAL 14 DAY)),
(7,  7,  'Mandiri', '1070007654321',   'Fajar Nugroho',      1, DATE_SUB(NOW(), INTERVAL 11 DAY)),
(8,  8,  'BRI',     '002009876543210', 'Gina Marlina',       1, DATE_SUB(NOW(), INTERVAL 7 DAY)),
(9,  9,  'BNI',     '0987654321',      'Hadi Setiawan',      1, DATE_SUB(NOW(), INTERVAL 9 DAY)),
(10, 10, 'CIMB',    '8007654321',      'Indah Permata',      1, DATE_SUB(NOW(), INTERVAL 34 DAY)),
(11, 11, 'BCA',     '1122334455',      'Joko Susilo',        1, DATE_SUB(NOW(), INTERVAL 8 DAY)),
(12, 12, 'Mandiri', '1070001122334',   'Karin Amelia',       1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(13, 13, 'BRI',     '002009112233445', 'Lutfi Hakim',        1, DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `bank_name` = VALUES(`bank_name`),
                         `account_number` = VALUES(`account_number`), `account_holder` = VALUES(`account_holder`);

-- ============ SECTION: user_rentals ============
-- H-0 rentals (id 6, 15) have days_processed = 0 and last_claimed_at NULL -> T+1 rejection test.
INSERT INTO `user_rentals`
(`id`, `user_id`, `product_id`, `purchase_price`, `daily_roi`, `total_days`, `days_processed`, `status`,
 `expired_at`, `last_claimed_at`, `created_at`) VALUES
(1,  1,  4, 50000000.00, 1650000.00, 365, 12, 'active',    DATE_ADD(DATE_SUB(NOW(), INTERVAL 12 DAY), INTERVAL 365 DAY), DATE_ADD(DATE_SUB(NOW(), INTERVAL 12 DAY), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 12 DAY)),
(2,  1,  1, 1000000.00,  40000.00,   30,  30, 'completed', DATE_ADD(DATE_SUB(NOW(), INTERVAL 45 DAY), INTERVAL 30 DAY),  DATE_ADD(DATE_SUB(NOW(), INTERVAL 45 DAY), INTERVAL 30 DAY),  DATE_SUB(NOW(), INTERVAL 45 DAY)),
(3,  2,  3, 15000000.00, 520000.00,  180, 3,  'active',    DATE_ADD(DATE_SUB(NOW(), INTERVAL 10 DAY), INTERVAL 180 DAY), DATE_ADD(DATE_SUB(NOW(), INTERVAL 10 DAY), INTERVAL 3 DAY),   DATE_SUB(NOW(), INTERVAL 10 DAY)),
(4,  3,  2, 5000000.00,  185000.00,  90,  1,  'active',    DATE_ADD(DATE_SUB(NOW(), INTERVAL 2 DAY), INTERVAL 90 DAY),   DATE_ADD(DATE_SUB(NOW(), INTERVAL 2 DAY), INTERVAL 1 DAY),    DATE_SUB(NOW(), INTERVAL 2 DAY)),
(5,  3,  1, 1000000.00,  40000.00,   30,  30, 'completed', DATE_ADD(DATE_SUB(NOW(), INTERVAL 40 DAY), INTERVAL 30 DAY),  DATE_ADD(DATE_SUB(NOW(), INTERVAL 40 DAY), INTERVAL 30 DAY),  DATE_SUB(NOW(), INTERVAL 40 DAY)),
(6,  4,  1, 1000000.00,  40000.00,   30,  0,  'active',    DATE_ADD(NOW(), INTERVAL 30 DAY),                              NULL,                                                          NOW()),
(7,  5,  1, 1000000.00,  40000.00,   30,  30, 'completed', DATE_ADD(DATE_SUB(NOW(), INTERVAL 60 DAY), INTERVAL 30 DAY),  DATE_ADD(DATE_SUB(NOW(), INTERVAL 60 DAY), INTERVAL 30 DAY),  DATE_SUB(NOW(), INTERVAL 60 DAY)),
(8,  6,  2, 5000000.00,  185000.00,  90,  0,  'cancelled', DATE_ADD(DATE_SUB(NOW(), INTERVAL 5 DAY), INTERVAL 90 DAY),   NULL,                                                          DATE_SUB(NOW(), INTERVAL 5 DAY)),
(9,  7,  1, 1000000.00,  40000.00,   30,  2,  'active',    DATE_ADD(DATE_SUB(NOW(), INTERVAL 3 DAY), INTERVAL 30 DAY),   DATE_ADD(DATE_SUB(NOW(), INTERVAL 3 DAY), INTERVAL 2 DAY),    DATE_SUB(NOW(), INTERVAL 3 DAY)),
(10, 8,  3, 15000000.00, 520000.00,  180, 1,  'active',    DATE_ADD(DATE_SUB(NOW(), INTERVAL 1 DAY), INTERVAL 180 DAY),  DATE_ADD(DATE_SUB(NOW(), INTERVAL 1 DAY), INTERVAL 1 DAY),    DATE_SUB(NOW(), INTERVAL 1 DAY)),
(11, 9,  2, 5000000.00,  185000.00,  90,  1,  'active',    DATE_ADD(DATE_SUB(NOW(), INTERVAL 2 DAY), INTERVAL 90 DAY),   DATE_ADD(DATE_SUB(NOW(), INTERVAL 2 DAY), INTERVAL 1 DAY),    DATE_SUB(NOW(), INTERVAL 2 DAY)),
(12, 10, 1, 1000000.00,  40000.00,   30,  30, 'completed', DATE_ADD(DATE_SUB(NOW(), INTERVAL 35 DAY), INTERVAL 30 DAY),  DATE_ADD(DATE_SUB(NOW(), INTERVAL 35 DAY), INTERVAL 30 DAY),  DATE_SUB(NOW(), INTERVAL 35 DAY)),
(13, 11, 2, 5000000.00,  185000.00,  90,  2,  'active',    DATE_ADD(DATE_SUB(NOW(), INTERVAL 3 DAY), INTERVAL 90 DAY),   DATE_ADD(DATE_SUB(NOW(), INTERVAL 3 DAY), INTERVAL 2 DAY),    DATE_SUB(NOW(), INTERVAL 3 DAY)),
(14, 12, 1, 1000000.00,  40000.00,   30,  1,  'active',    DATE_ADD(DATE_SUB(NOW(), INTERVAL 1 DAY), INTERVAL 30 DAY),   DATE_ADD(DATE_SUB(NOW(), INTERVAL 1 DAY), INTERVAL 1 DAY),    DATE_SUB(NOW(), INTERVAL 1 DAY)),
(15, 13, 1, 1000000.00,  40000.00,   30,  0,  'active',    DATE_ADD(NOW(), INTERVAL 30 DAY),                              NULL,                                                          NOW())
ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `product_id` = VALUES(`product_id`),
                         `purchase_price` = VALUES(`purchase_price`), `daily_roi` = VALUES(`daily_roi`),
                         `total_days` = VALUES(`total_days`), `days_processed` = VALUES(`days_processed`),
                         `status` = VALUES(`status`), `expired_at` = VALUES(`expired_at`), `last_claimed_at` = VALUES(`last_claimed_at`);

-- ============ SECTION: deposits ============
-- 15 invoices: 13 success, 1 failed, 1 pending. invoice_number format INV-{YmdHis}-{user_id}.
INSERT INTO `deposits` (`id`, `user_id`, `invoice_number`, `amount`, `status`, `created_at`) VALUES
(1,  1,  CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 45 DAY), '%Y%m%d%H%i%s'), '-1'),  50000000.00, 'success', DATE_SUB(NOW(), INTERVAL 45 DAY)),
(2,  2,  CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 30 DAY), '%Y%m%d%H%i%s'), '-2'),  15000000.00, 'success', DATE_SUB(NOW(), INTERVAL 30 DAY)),
(3,  3,  CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 45 DAY), '%Y%m%d%H%i%s'), '-3'),  9000000.00,  'success', DATE_SUB(NOW(), INTERVAL 45 DAY)),
(4,  4,  CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 3 DAY), '%Y%m%d%H%i%s'), '-4'),   2000000.00,  'success', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(5,  5,  CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 60 DAY), '%Y%m%d%H%i%s'), '-5'),  1000000.00,  'success', DATE_SUB(NOW(), INTERVAL 60 DAY)),
(6,  6,  CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 10 DAY), '%Y%m%d%H%i%s'), '-6'),  5000000.00,  'success', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(7,  6,  CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 8 DAY), '%Y%m%d%H%i%s'), '-6'),   5000000.00,  'failed',  DATE_SUB(NOW(), INTERVAL 8 DAY)),
(8,  7,  CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 4 DAY), '%Y%m%d%H%i%s'), '-7'),   1500000.00,  'success', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(9,  8,  CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 6 DAY), '%Y%m%d%H%i%s'), '-8'),   15000000.00, 'success', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(10, 9,  CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 4 DAY), '%Y%m%d%H%i%s'), '-9'),   5000000.00,  'success', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(11, 10, CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 35 DAY), '%Y%m%d%H%i%s'), '-10'), 1000000.00,  'success', DATE_SUB(NOW(), INTERVAL 35 DAY)),
(12, 11, CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5 DAY), '%Y%m%d%H%i%s'), '-11'),  5000000.00,  'success', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(13, 12, CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 2 DAY), '%Y%m%d%H%i%s'), '-12'),  1000000.00,  'success', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(14, 13, CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 3 DAY), '%Y%m%d%H%i%s'), '-13'),  1000000.00,  'success', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(15, 13, CONCAT('INV-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 DAY), '%Y%m%d%H%i%s'), '-13'),  1000000.00,  'pending',  DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `invoice_number` = VALUES(`invoice_number`),
                         `amount` = VALUES(`amount`), `status` = VALUES(`status`);

-- ============ SECTION: withdrawals ============
-- 4 rows covering pending / processing / success / failed. Fee tiers per PRD:
--   5jt -> 5% + 6.500 (fee 256.500, net 4.743.500)
--   3jt -> 5% + 6.500 (fee 156.500, net 2.843.500)
--   1jt -> 6.5% + 6.500 (fee 71.500, net 928.500)
--   500k -> 7.5% + 6.500 (fee 44.000, net 456.000)
INSERT INTO `withdrawals`
(`id`, `user_id`, `bank_account_id`, `wd_number`, `amount`, `gross_amount`, `fee_amount`, `net_amount`,
 `status`, `remark`, `processed_at`, `created_at`) VALUES
(1, 1,  1, CONCAT('WD-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 15 DAY), '%Y%m%d%H%i%s'), '-1'), 5000000.00, 5000000.00, 256500.00, 4743500.00, 'success',    NULL, DATE_SUB(NOW(), INTERVAL 14 DAY), DATE_SUB(NOW(), INTERVAL 15 DAY)),
(2, 3,  3, CONCAT('WD-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 DAY), '%Y%m%d%H%i%s'), '-3'),  3000000.00, 3000000.00, 156500.00, 2843500.00, 'pending',    NULL, NULL,                                DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3, 5,  5, CONCAT('WD-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 28 DAY), '%Y%m%d%H%i%s'), '-5'), 1000000.00, 1000000.00, 71500.00,  928500.00,  'failed',     'Rekening tidak valid', DATE_SUB(NOW(), INTERVAL 27 DAY), DATE_SUB(NOW(), INTERVAL 28 DAY)),
(4, 7,  7, CONCAT('WD-', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 2 DAY), '%Y%m%d%H%i%s'), '-7'),  500000.00,  500000.00,  44000.00,  456000.00,  'processing', NULL, NULL,                                DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `bank_account_id` = VALUES(`bank_account_id`),
                         `wd_number` = VALUES(`wd_number`), `amount` = VALUES(`amount`),
                         `gross_amount` = VALUES(`gross_amount`), `fee_amount` = VALUES(`fee_amount`),
                         `net_amount` = VALUES(`net_amount`), `status` = VALUES(`status`);

-- ============ SECTION: wallet_ledger ============
-- Double-entry ledger DERIVED from the tables above so that
-- users.balance == SUM(credit) - SUM(debit) holds by construction.
-- (a) Deposit credits — success deposits only (matches approve_deposit behavior)
INSERT INTO `wallet_ledger` (`user_id`, `transaction_id`, `type`, `amount`, `description`, `created_at`)
SELECT `user_id`, `invoice_number`, 'credit', `amount`, CONCAT('Top Up via ', `invoice_number`), `created_at`
FROM `deposits` WHERE `status` = 'success';

-- (b) Rental purchase debits (funds locked at purchase, incl. cancelled/completed)
INSERT INTO `wallet_ledger` (`user_id`, `transaction_id`, `type`, `amount`, `description`, `created_at`)
SELECT ur.`user_id`, CONCAT('RENT-', ur.`id`, '-', DATE_FORMAT(ur.`created_at`, '%Y%m%d%H%i%s')), 'debit',
       ur.`purchase_price`, CONCAT('Pembelian Produk #', ur.`product_id`), ur.`created_at`
FROM `user_rentals` ur;

-- (c) Daily ROI credits — exactly days_processed rows per rental, claimed on day+1..+n
INSERT INTO `wallet_ledger` (`user_id`, `transaction_id`, `type`, `amount`, `description`, `created_at`)
SELECT ur.`user_id`,
       CONCAT('ROI-', ur.`id`, '-', DATE_FORMAT(DATE_ADD(ur.`created_at`, INTERVAL n.n DAY), '%Y%m%d%H%i%s')),
       'credit', ur.`daily_roi`, CONCAT('ROI Harian #', ur.`id`),
       DATE_ADD(ur.`created_at`, INTERVAL n.n DAY)
FROM `user_rentals` ur
JOIN (SELECT a.n + b.n * 10 + c.n * 100 AS n
      FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
            UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
      CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
            UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
      CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) c) n
WHERE n.n BETWEEN 1 AND ur.`days_processed`;

-- (d) Withdrawal debits — all statuses lock funds at request time
INSERT INTO `wallet_ledger` (`user_id`, `transaction_id`, `type`, `amount`, `description`, `created_at`)
SELECT `user_id`, `wd_number`, 'debit', `amount`, CONCAT('Penarikan Dana via ', `wd_number`), `created_at`
FROM `withdrawals`;

-- (e) Refund credit for the failed withdrawal (admin decline restores funds)
INSERT INTO `wallet_ledger` (`user_id`, `transaction_id`, `type`, `amount`, `description`, `created_at`)
SELECT `user_id`, `wd_number`, 'credit', `amount`,
       CONCAT('Pengembalian Dana: Penarikan Ditolak (', `wd_number`, ')'),
       DATE_ADD(`created_at`, INTERVAL 1 DAY)
FROM `withdrawals` WHERE `status` = 'failed';

-- (f) vipleader: one-time Level 1 bonus, weekly wage, referral commission
INSERT INTO `wallet_ledger` (`user_id`, `transaction_id`, `type`, `amount`, `description`, `created_at`) VALUES
(1, 'L1-00000000000000-1',    'credit', 80000.00,  'Bonus Level 1 Agency',           DATE_SUB(NOW(), INTERVAL 20 DAY)),
(1, 'WAGE-00000000000000-1',  'credit', 100000.00, 'Gaji Mingguan Level 2',          DATE_SUB(NOW(), INTERVAL 7 DAY)),
(1, 'CM-00000000000000-1',    'credit', 100000.00, 'Komisi Referral 085712345678',   DATE_SUB(NOW(), INTERVAL 9 DAY));

-- ============ SECTION: user_notifications ============
-- 14 notifications, types within schema enum, mixed read/unread.
INSERT INTO `user_notifications` (`user_id`, `title`, `message`, `type`, `is_read`, `created_at`) VALUES
(1,  'Bonus Level 1 Cair',      'Selamat! Bonus Level 1 sebesar Rp 80.000 telah masuk ke saldo.',                'commission', 1, DATE_SUB(NOW(), INTERVAL 20 DAY)),
(1,  'Komisi Referral',         'Anda menerima komisi referral Rp 100.000 dari 085712345678.',                    'commission', 0, DATE_SUB(NOW(), INTERVAL 9 DAY)),
(2,  'Deposit Berhasil',        'Top Up sebesar Rp 15.000.000 telah masuk ke saldo Anda.',                         'success',    1, DATE_SUB(NOW(), INTERVAL 29 DAY)),
(3,  'Deposit Berhasil',        'Top Up sebesar Rp 9.000.000 telah masuk ke saldo Anda.',                          'success',    0, DATE_SUB(NOW(), INTERVAL 44 DAY)),
(1,  'Penarikan Berhasil',      'Penarikan sebesar Rp 5.000.000 telah diproses.',                                 'success',    1, DATE_SUB(NOW(), INTERVAL 14 DAY)),
(5,  'Penarikan Ditolak',       'Penarikan sebesar Rp 1.000.000 ditolak. Dana telah dikembalikan ke saldo.',      'warning',    0, DATE_SUB(NOW(), INTERVAL 27 DAY)),
(4,  'Selamat Datang di Synapse','Selamat bergabung! Mulai sewa node GPU dan dapatkan ROI harian.',               'info',       1, DATE_SUB(NOW(), INTERVAL 10 DAY)),
(10, 'Selamat Datang di Synapse','Selamat bergabung! Mulai sewa node GPU dan dapatkan ROI harian.',               'info',       1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(12, 'Selamat Datang di Synapse','Selamat bergabung! Mulai sewa node GPU dan dapatkan ROI harian.',               'info',       0, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(13, 'Selamat Datang di Synapse','Selamat bergabung! Mulai sewa node GPU dan dapatkan ROI harian.',               'info',       0, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1,  'Pembaruan Sistem',        'Sistem akan maintenance pada Minggu pukul 01:00 WIB.',                           'info',       1, DATE_SUB(NOW(), INTERVAL 7 DAY)),
(8,  'Deposit Berhasil',        'Top Up sebesar Rp 15.000.000 telah masuk ke saldo Anda.',                         'success',    0, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(11, 'Deposit Berhasil',        'Top Up sebesar Rp 5.000.000 telah masuk ke saldo Anda.',                          'success',    0, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(7,  'Penarikan Diproses',      'Penarikan Rp 500.000 sedang diproses oleh sistem.',                              'info',       0, DATE_SUB(NOW(), INTERVAL 2 DAY));

-- ============ SECTION: system_audit_logs ============
-- 16 rows, 10 distinct action strings the code writes (Phase 10A viewer).
INSERT INTO `system_audit_logs` (`admin_id`, `user_id`, `action`, `details`, `ip_address`, `created_at`)
SELECT 1, d.`user_id`, 'approve_deposit',
       CONCAT('{"invoice_number":"', d.`invoice_number`, '","amount":', CAST(d.`amount` AS CHAR), '}'),
       '127.0.0.1', DATE_ADD(d.`created_at`, INTERVAL 1 DAY)
FROM `deposits` d WHERE d.`id` IN (1, 2, 3, 4, 9);

INSERT INTO `system_audit_logs` (`admin_id`, `user_id`, `action`, `details`, `ip_address`, `created_at`)
SELECT 1, w.`user_id`, 'approve_withdrawal',
       CONCAT('{"wd_number":"', w.`wd_number`, '","amount":', CAST(w.`amount` AS CHAR), '}'),
       '127.0.0.1', DATE_ADD(w.`created_at`, INTERVAL 1 DAY)
FROM `withdrawals` w WHERE w.`id` = 1;

INSERT INTO `system_audit_logs` (`admin_id`, `user_id`, `action`, `details`, `ip_address`, `created_at`)
SELECT 1, w.`user_id`, 'decline_withdrawal',
       CONCAT('{"wd_number":"', w.`wd_number`, '","amount":', CAST(w.`amount` AS CHAR), ',"refunded":true}'),
       '10.0.0.23', DATE_ADD(w.`created_at`, INTERVAL 1 DAY)
FROM `withdrawals` w WHERE w.`id` = 3;

INSERT INTO `system_audit_logs` (`admin_id`, `user_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, NULL, 'admin_update_settings',  '{"fields":["is_registration_open"]}',                  '127.0.0.1', DATE_SUB(NOW(), INTERVAL 13 DAY)),
(1, 3,   'admin_update_user',       '{"phone":"085712345678","invite_code":"GHI789","upline_id":1}', '10.0.0.11', DATE_SUB(NOW(), INTERVAL 44 DAY)),
(1, 13,  'admin_toggle_ban',        '{"new_state":"banned"}',                                '127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 6,   'admin_cancel_rental',     '{"rental_id":8}',                                        '10.0.0.11', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(1, 7,   'admin_adjust_time',       '{"rental_id":9,"days_processed":2}',                    '127.0.0.1', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1, 12,  'admin_create_user',       '{"phone":"081288990011","created_by":1}',               '127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 13,  'admin_create_user',       '{"phone":"081299001122","created_by":1}',               '127.0.0.1', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1, 6,   'admin_reset_password',    NULL,                                                     '10.0.0.23', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(1, 12,  'admin_inject_rental',     '{"product_id":1}',                                       '10.0.0.11', DATE_SUB(NOW(), INTERVAL 1 DAY));

-- ============ SECTION: system_settings ============
-- Idempotent, never overwrites a live value.
INSERT IGNORE INTO `system_settings` (`key_name`, `key_value`) VALUES ('is_registration_open', '1');

-- ============ SEED COMPLETE ============
