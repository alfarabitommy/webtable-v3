<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// =====================================================================
// Plan 121 — LUCKY SPIN WHEEL: FALLBACK CONFIG
// =====================================================================
// Sumber dinamis = `system_settings` (dioperasikan admin dari
// /admin/lucky-spin, di-seed di database.sql + database_seed.sql):
//   spin_wheel_enabled   '1' | '0'   (fail-closed: '0' → UI disembunyikan DAN
//                                     POST /lucky-spin/spin → 403 disabled)
//   spin_wheel_segments  JSON [[nominal_idr, bobot], …] — URUTAN = urutan
//                        segmen roda (indeks 0 = segmen pertama)
//
// File ini hanya FALLBACK per-key (pola M1/plan/56, plan/89
// `rebate_commission.php`, plan/112 `checkin_rewards.php`, plan/119
// `team_milestones.php`): dipakai bila baris dinamis HILANG atau TIDAK VALID —
// konfigurasi rusak tidak pernah membuat request fatal. Angka di sini adalah
// DEFAULT, bukan ambang validasi: ambang administratif hidup di
// `application/helpers/spin_wheel_helper.php` (`spin_wheel_limits()`) — satu
// sumber, dipakai validator admin (`Spin_model::validate_settings()`) dan CLI
// `scripts/migrate_121_lucky_spin.php --verify`.
//
// SEMANTIK (plan/121):
//   - 1 tiket per downline L1 SEUMUR HIDUP, hanya dari pembelian PERTAMA
//     produk NON-trial (trial gratis tidak menghasilkan tiket);
//   - tiket BUKAN uang (tanpa `wallet_ledger`, tidak dapat ditarik);
//   - hadiah ADALAH uang: kredit `wallet_type = 'main'` lewat
//     `Wallet_model::credit()`, satu baris `SPIN-{id}` per spin;
//   - pemilihan segmen SELALU di server (CSPRNG + bobot) — klien hanya
//     menerima indeks segmen untuk animasi;
//   - TIDAK ADA segmen Rp 0: setiap nominal ≥ 1 (keputusan owner D3).
//
// Default segmen = contoh awal (total bobot 95, expected value ≈ Rp 35,8 rb
// per tiket: Σ(nominal × bobot) / Σbobot). Admin dapat mengubahnya kapan saja
// dari /admin/lucky-spin — halaman itu menampilkan peluang per segmen +
// expected value per tiket.
return [
    'spin_wheel_enabled'  => 1,   // 1 = aktif; 0 = UI hilang + endpoint 403
    'spin_wheel_segments' => [
        [5000,   30],   // 30/95
        [10000,  25],   // 25/95
        [25000,  18],   // 18/95
        [50000,  10],   // 10/95
        [100000,  6],   //  6/95
        [150000,  3],   //  3/95
        [250000,  2],   //  2/95
        [500000,  1],   //  1/95
    ],
];
