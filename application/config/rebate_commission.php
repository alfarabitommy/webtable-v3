<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// =====================================================================
// Plan 89 — REBATE 3-TIER (AFFILIATE PURCHASE REBATE): FALLBACK CONFIG
// =====================================================================
// Dynamic source of truth = `system_settings` (admin-operable, seeded in
// database.sql). File ini hanya FALLBACK per-key (pola M1/plan/56:
// `withdrawal_fees.php`) — dipakai bila baris dinamis hilang/tidak valid.
// Persen komisi dihitung dari harga paket GPU yang dibeli downline:
//   L1 = 5% (anak langsung), L2 = 3% (cucu), L3 = 1% (cicit).
// Kelayakan: upline wajib memiliki >= 1 kontrak sewa AKTIF saat downline
// membeli; bila inaktif, jatah tier hangus (breakage platform, no pass-up).
// Nilai WAJIB integer IDR-discipline (M8): 0–100, tanpa pecahan.
return [
    'rebate_enabled'    => 1, // 1 = aktif, 0 = nonaktif (engine skip total)
    'rebate_l1_percent' => 5,
    'rebate_l2_percent' => 3,
    'rebate_l3_percent' => 1,
];
