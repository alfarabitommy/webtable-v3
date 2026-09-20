<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Plan 109 — choke-point TUNGGAL resolusi nominal penarikan (gross/fee/net).
 *
 * Latar: `withdrawals` menyimpan tiga nominal yang artinya berbeda —
 *   gross_amount = nominal yang dipotong dari saldo member (== `amount`),
 *   fee_amount   = biaya admin (ditahan platform),
 *   net_amount   = yang WAJIB ditransfer admin ke e-wallet member.
 * Seluruh surface (antrean admin, riwayat admin, kartu member) harus
 * menampilkan NET sebagai nilai primer, dan gross/fee sebagai rincian.
 * Aturan pemilihan nilai itu hidup DI SINI saja (helper-first rule, AGENTS.md).
 *
 * Kontrak:
 *   - Fungsi MURNI, tanpa DB, tanpa efek samping → netral untuk member, admin,
 *     dan aman di-include berulang dari CLI/migrasi/probe.
 *   - Uang tetap INTEGER IDR (M8). Pemformatan (`number_format` + `Rp`) hanya
 *     di view — uang/angka tidak pernah diterjemahkan (L6).
 *   - Baris legacy (C3, plan/52 — `gross/fee/net` masih 0/NULL) di-resolusi
 *     read-side memakai kalkulator tier yang DIINJEKSI pemanggil
 *     (`Wallet_model::calculate_withdrawal_fee()`), sumber yang sama dengan
 *     jalur export CSV (`Admin::export_csv('withdrawals')`). Tidak ada tulis DB.
 *
 * Preseden global guard: `function_exists()` pada setiap fungsi (lihat
 * product_image_helper / ewallet_helper / wa_group_helper).
 */

if ( ! function_exists('withdrawal_amount_parts'))
{
    /**
     * Resolusi gross/fee/net dari satu baris `withdrawals`.
     *
     * @param  array|object  $row             Baris `withdrawals` (kolom amount,
     *                                        gross_amount, fee_amount, net_amount).
     * @param  callable|null $fee_calculator  `fn(int $gross): array{fee:int,net:int}`
     *                                        — kirim `Wallet_model::calculate_withdrawal_fee`.
     * @return array{gross:int,fee:int,net:int,legacy:bool}
     */
    function withdrawal_amount_parts($row, $fee_calculator = NULL)
    {
        $row = (array) $row;

        $gross_stored = isset($row['gross_amount']) ? (int) $row['gross_amount'] : 0;
        $amount       = isset($row['amount'])       ? (int) $row['amount']       : 0;
        $fee          = isset($row['fee_amount'])   ? (int) $row['fee_amount']   : 0;
        $net          = isset($row['net_amount'])   ? (int) $row['net_amount']   : 0;

        // Fallback legacy (plan/52 C3): gross_amount 0/NULL → `amount` (mirror gross).
        $legacy = ($gross_stored <= 0);
        $gross  = $legacy ? $amount : $gross_stored;

        if ($gross <= 0) {
            return array('gross' => 0, 'fee' => 0, 'net' => 0, 'legacy' => $legacy);
        }

        // Baris inkonsisten: fee/net belum tersimpan (0/NULL) atau tidak
        // berjumlah gross → hitung ulang dari tier yang berlaku.
        if (($fee <= 0 || $net <= 0 || ($fee + $net) !== $gross) && is_callable($fee_calculator)) {
            $calc = $fee_calculator($gross);
            if (is_array($calc) && isset($calc['fee'], $calc['net'])) {
                $fee = max(0, (int) $calc['fee']);
                $net = max(0, (int) $calc['net']);
            }
        }

        if ($net <= 0) {
            $net = max(0, $gross - $fee);
        }

        return array('gross' => $gross, 'fee' => $fee, 'net' => $net, 'legacy' => $legacy);
    }
}

if ( ! function_exists('withdrawal_amount_decorate'))
{
    /**
     * Tempelkan `gross_eff` / `fee_eff` / `net_eff` (+ `amount_legacy`) pada
     * setiap baris, sehingga view hanya memformat (tanpa aritmetika uang).
     *
     * @param  array         $rows           Baris hasil model (object atau array).
     * @param  callable|null $fee_calculator Lihat withdrawal_amount_parts().
     * @return array         Baris yang sama (sudah terdekorasi).
     */
    function withdrawal_amount_decorate(array $rows, $fee_calculator = NULL)
    {
        foreach ($rows as $i => $row) {
            $parts = withdrawal_amount_parts($row, $fee_calculator);

            if (is_object($row)) {
                $row->gross_eff     = $parts['gross'];
                $row->fee_eff       = $parts['fee'];
                $row->net_eff       = $parts['net'];
                $row->amount_legacy = $parts['legacy'];
            } else {
                // Baris array: `$row` adalah SALINAN pada foreach → tulis balik
                // lewat indeks, jika tidak dekorasi akan hilang tanpa error.
                $rows[$i]['gross_eff']     = $parts['gross'];
                $rows[$i]['fee_eff']       = $parts['fee'];
                $rows[$i]['net_eff']       = $parts['net'];
                $rows[$i]['amount_legacy'] = $parts['legacy'];
            }
        }

        return $rows;
    }
}
