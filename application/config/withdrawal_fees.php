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
 *
 *   CATATAN plan/110 (Q1): nilai min/max di berkas ini adalah **FAIL-SAFE**,
 *   bukan otoritas operasional. Nilai operasional hidup di system_settings
 *   (dapat diubah admin di /admin/settings) dan boleh BERBEDA — mis. minimal
 *   penarikan diturunkan ke Rp 50.000 tanpa menyentuh berkas ini, sementara
 *   docs/1_PRD.md §122 masih menyebut Rp 100.000. Bila baris
 *   wd_min_amount/wd_max_amount/wd_fee_tiers hilang atau tidak koheren,
 *   Wallet_model::_resolve_financial_config() mengembalikan bundle ini
 *   (bound → 100.000/50.000.000) dan mencatat log error — perilaku yang
 *   SENGAJA dipertahankan sebagai pengaman (fail-safe), bukan bug.
 *
 *   ATURAN TIER (plan/110 §5.2 — amandemen plan/56 §2.3): tier half-open
 *   [min, max) wajib KONTIGU PENUH menutupi [wd_min_amount, wd_max_amount],
 *   dengan dua endpoint TURUNAN yang dinormalkan otomatis saat admin menyimpan
 *   (bukan lagi ditolak): tier pertama `min` ← wd_min_amount dan tier terakhir
 *   `max` ← max(…, wd_max_amount + 1). Jaminan cakupan penuh ini penting
 *   karena calculate_withdrawal_fee() memakai tarif tier TERAKHIR sebagai
 *   fallback bila nominal tidak masuk tier mana pun.
 *   Implementasi: application/helpers/withdrawal_fee_helper.php (choke-point
 *   input admin); parser strict jalur BACA tetap Wallet_model::_norm_tiers().
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
    // Endpoint turunan (plan/110): tier pertama `min` mengikuti
    // system_settings.wd_min_amount dan tier terakhir `max` > wd_max_amount —
    // keduanya dinormalkan otomatis saat admin menyimpan; nilai di bawah ini
    // adalah bentuk FAIL-SAFE (lihat catatan ATURAN TIER di atas).
    'fixed_fee'        => 6500,
    'tiers'            => [
        // [min, max_exclusive, bps]
        [100000,      500000,   1000],   // 10%   (mengikuti min_amount fail-safe 100.000)
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

    // plan/102 — Kebijakan deposit QRIS manual (fallback bila baris
    // system_settings hilang/rusak; dibaca Wallet_model::get_deposit_policy()):
    //   - jendela bayar invoice (menit) sebelum status → expired & kode dilepas,
    //   - batas nominal POKOK deposit (belum termasuk kode unik & fee).
    'deposit_expiry_minutes' => 60,
    'deposit_min_amount'     => 10000,
    'deposit_max_amount'     => 50000000,
];
