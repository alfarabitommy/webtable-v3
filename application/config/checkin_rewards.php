<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// =====================================================================
// Plan 112 — ABSENSI HARIAN (DAILY CHECK-IN): FALLBACK CONFIG
// =====================================================================
// Sumber dinamis = `system_settings` (dioperasikan admin dari
// /admin/settings, di-seed di database.sql + database_seed.sql). File ini
// hanya FALLBACK per-key (pola M1/plan/56 & plan/89 `rebate_commission.php`):
// dipakai bila baris dinamis hilang atau tidak valid — setelan rusak TIDAK
// PERNAH membuat request fatal.
//
// Bonus harian = min(base × hari streak, max) — aritmetika INTEGER IDR utuh
// (M8, tanpa pecahan). Invarian wajib: 1 ≤ base ≤ max.
//
// Semua angka di file ini adalah DEFAULT, bukan ambang validasi: ambang
// administratif hidup di Checkin_model::BASE_MAX / MAX_MAX (satu sumber,
// dipakai validator form admin & scripts/migrate_112_daily_checkin.php).
return [
    'checkin_enabled'       => 1,        // 1 = tampil & dapat diklaim, 0 = mati total (fail-closed)
    'checkin_base_reward'   => 50,       // bonus hari ke-1 (IDR)
    'checkin_max_reward'    => 10000,    // cap harian keras (IDR)
    'checkin_streak_policy' => 'reset',  // 'reset' = bolong → hari 1 | 'continue' = lanjut N+1
];
