<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * M1 (plan/56) — Withdrawal & Deposit financial configuration.
 *
 * FALLBACK object (single source of PRD spec defaults). The app reads the
 * same keys from the `system_settings` table (dynamic, admin-operable) via
 * Wallet_model::get_financial_config(); this file is used ONLY when a
 * dynamic row is missing or invalid. Never mutated by the app.
 *
 * PRD docs/1_PRD.md §121-125 — Withdrawal rules:
 *   - Window: Mon–Sat (1..6), 07:00–19:00 WIB (submission gate only).
 *   - Min Rp 100.000, Max Rp 50.000.000 per withdrawal.
 *   - Fee = floor(gross * bps / 10000) + fixed_fee (integer IDR);
 *     half-open tiers [min, max): boundary amount belongs to the higher
 *     (more discounted) tier. Approved dec-30928987d7ae1c74 (plan/52 §1.4):
 *       - Rp 5.000.000  -> 4%  -> fee Rp 206.500 (bukan 5%)
 *       - Rp 500.000    -> 7,5% -> fee Rp  44.000
 *       - Rp 1.000.000  -> 6,5% -> fee Rp  71.500
 * bps = basis points of the percentage component (10% = 1000, ... 3% = 300).
 *
 * Deposit fee (M1 §2.1): user pays amount + fee; wallet ledger credit stays
 * pure principal (zero dilution). percent value is in percentage points
 * (0.70 = 0.70%); fee = floor(amount * pct / 100); flat fee = value (IDR).
 *
 * IDR only.
 */
return [
    // Operational window (days: CSV 1=Monday .. 7=Sunday; times HH:MM WIB).
    'operational_days' => '1,2,3,4,5,6',
    'open_time'        => '07:00',
    'close_time'       => '19:00',

    // Withdrawal fee structure.
    'fixed_fee'        => 6500,
    'tiers'            => [
        // [min, max_exclusive, bps]
        [100000,      500000,   1000],   // 10%   (effective floor = min WD 100.000)
        [500000,     1000000,    750],   // 7,5%
        [1000000,    2000000,    650],   // 6,5%
        [2000000,    5000000,    500],   // 5%
        [5000000,   10000000,    400],   // 4%
        [10000000,   50000001,   300],   // 3%   (last max exclusive > max_amount 50.000.000)
    ],

    // Per-withdrawal bounds (PRD §122).
    'min_amount'       => 100000,
    'max_amount'       => 50000000,

    // Deposit fee (toggle 0/1; type flat|percent; value in IDR or percent pts).
    'deposit_fee_enabled' => 0,
    'deposit_fee_type'    => 'flat',
    'deposit_fee_value'   => 0,
];
