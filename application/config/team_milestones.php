<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// =====================================================================
// Plan 119 — BONUS PENCAPAIAN TIM (TEAM MILESTONE BONUS): FALLBACK CONFIG
// =====================================================================
// Sumber dinamis = `system_settings` (dioperasikan admin dari /admin/settings,
// di-seed di database.sql + database_seed.sql):
//   team_milestone_enabled  '1' | '0'
//   team_milestone_tiers    JSON [[target_downline_aktif, nominal_idr], …]
//
// File ini hanya FALLBACK per-key (pola M1/plan/56, plan/89
// `rebate_commission.php`, plan/112 `checkin_rewards.php`): dipakai bila baris
// dinamis HILANG atau TIDAK VALID — konfigurasi rusak tidak pernah membuat
// request fatal. Nilai angka di sini adalah DEFAULT, bukan ambang validasi:
// ambang administratif hidup di `application/helpers/team_milestone_helper.php`
// (`team_milestone_limits()`) — satu sumber, dipakai validator admin
// (`User_model::validate_milestone_settings()`) dan CLI `--verify`.
//
// SEMANTIK (amandemen plan/119 atas Gaji Mingguan):
//   - satu tier dibayar SEKALI seumur hidup per user (tanpa cooldown, tanpa
//     siklus mingguan, tanpa cron);
//   - klaim berurutan dari tier TERENDAH yang belum diklaim;
//   - kredit masuk SALDO UTAMA (`wallet_ledger.wallet_type = 'main'`);
//   - "downline aktif" = predikat kanonik plan/116 K8 (kontrak
//     `status='active'` + `expired_at > now WIB` + produk NON-trial).
//
// Seed default = mirror tier gaji lama (9/30/70/130/190) supaya ambang &
// nominal yang sudah dipublikasikan tidak berubah — hanya periodenya
// (per minggu menjadi sekali seumur hidup).
return [
    'team_milestone_enabled' => 1,   // 1 = aktif; 0 = kartu member tidak dirender + endpoint 403
    'team_milestone_tiers'   => [
        [9,   200000],     // 9 downline aktif   -> Rp 200.000
        [30,  1000000],    // 30 downline aktif  -> Rp 1.000.000
        [70,  2500000],    // 70 downline aktif  -> Rp 2.500.000
        [130, 5000000],    // 130 downline aktif -> Rp 5.000.000
        [190, 9000000],    // 190 downline aktif -> Rp 9.000.000
    ],
];
