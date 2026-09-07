<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// =====================================================================
// Plan 91 — PROGRAM PROMOTER: REWARD TIER MAP (OMZET BURN THRESHOLDS)
// =====================================================================
// Static canonical source of truth (pola rebate_commission.php plan/89 —
// fallback config bertipe, BUKAN setting admin dinamis M7; tanpa UI admin).
// Map: gpu_products.id => omzet_cost (integer IDR) yang HARUS dibakar
// (dikunci saat pending, dikurangi permanen saat approved) untuk menebus
// kontrak reward zero-cost produk tsb. Menjaga cost-of-acquisition 8–10%
// terhadap harga produk SAAT INI (guard rasio di Promoter_model).
//
//   Tier 1: RTX 3060 Starter (Rp 150.000)  → Rp 1.500.000  (10,0%)
//   Tier 2: RTX 4060 Lite    (Rp 300.000)  → Rp 3.500.000  (8,57%)
//   Tier 3: RTX 4070 Basic   (Rp 600.000)  → Rp 7.000.000  (8,57%)
//   Tier 4: RTX 4080 Prime   (Rp 1.200.000)→ Rp 15.000.000 (8,0%)
//
// Produk di luar peta TIDAK bisa ditebus sebagai reward. Admin product
// CRUD (plan/85) wajib menjaga harga agar rasio tetap 8–10%; guard K5
// menolak klaim bila rasio keluar rentang. Nilai WAJIB integer IDR (M8).
$config['promoter_rewards'] = [
    5 => 1500000,   // RTX 3060 Starter (Rp 150.000) — Tier 1
    6 => 3500000,   // RTX 4060 Lite    (Rp 300.000) — Tier 2
    7 => 7000000,   // RTX 4070 Basic   (Rp 600.000) — Tier 3
    8 => 15000000,  // RTX 4080 Prime   (Rp 1.200.000) — Tier 4
];
