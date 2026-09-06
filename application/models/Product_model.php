<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Product_model extends CI_Model {

    /**
     * Get all active products — DB is the single source of truth (P4, plan/80).
     * Mock fallback dihapus: tabel `gpu_products` di-seed dari database.sql
     * (INSERT IGNORE id 1-4). Nol/baris non-aktif → array kosong sehingga
     * empty state marketplace benar-benar tercapai & teruji.
     */
    public function get_all_active_products() {
        return $this->db
            ->get_where('gpu_products', ['is_active' => 1])
            ->result_array();
    }

    /**
     * Get single product by ID — DB only (P4, plan/80).
     * NULL/empty → caller wajib null-check (Rentals::checkout sudah
     * menangani: flashdata "Produk tidak ditemukan di database.").
     */
    public function get_product($id) {
        return $this->db
            ->get_where('gpu_products', ['id' => $id])
            ->row_array();
    }

    /**
     * plan/83 — Katalog ter-personalisasi untuk satu user (DISPLAY ONLY).
     * Menggabungkan produk aktif dengan nama paket prasyarat + telemetri
     * pembelian lifetime user. Otoritas GATE (prasyarat & kuota) tetap di
     * Rental_model::checkout_rental di dalam TX terkunci — method ini hanya
     * bahan render UI marketplace (State A/B/C), TIDAK pernah dipercaya.
     *
     * Predikat "pernah disewa" (D1, plan/83): user_rentals.status IN
     * ('active','completed') — baris 'cancelled' (soft-cancel admin tanpa
     * refund) tidak memenuhi prasyarat dan tidak memakan kuota.
     *
     * @param int $user_id
     * @return array  Produk aktif (result_array, order id ASC) dengan ekstra:
     *   prerequisite_id|prerequisite_name (int|null|string|null),
     *   user_rentals_count (int), quota_max (int), is_unlimited (bool),
     *   quota_remaining (int|null), is_locked (bool), is_quota_exhausted (bool),
     *   can_rent (bool). Produk non-aktif / katalog kosong → array kosong
     *   (empty-state marketplace P4 tetap tercapai).
     */
    public function get_catalog_for_user($user_id) {
        $user_id = (int) $user_id;

        // Query A — produk aktif + nama prasyarat (self LEFT JOIN; prasyarat
        // yang sedang is_active=0 tetap menampilkan nama & tetap terpenuhi
        // oleh riwayat sewa lama — D1/D3 plan/83).
        $rows = $this->db->query(
            "SELECT p.*,
                    pr.id   AS prerequisite_id,
                    pr.name AS prerequisite_name
               FROM gpu_products p
               LEFT JOIN gpu_products pr ON pr.id = p.unlock_prerequisite_id
              WHERE p.is_active = 1
              ORDER BY p.id ASC"
        )->result_array();

        // Query B — SATU agregat untuk seluruh riwayat kualifikasi user.
        $counts = [];
        foreach ($this->db->query(
            "SELECT product_id, COUNT(*) AS cnt
               FROM user_rentals
              WHERE user_id = ? AND status IN ('active', 'completed')
              GROUP BY product_id",
            [$user_id]
        )->result() as $row) {
            $counts[(int) $row->product_id] = (int) $row->cnt;
        }

        foreach ($rows as &$p) {
            $p['user_rentals_count'] = $counts[(int) $p['id']] ?? 0;

            // M8 (plan/74): seluruh aritmetika integer IDR — max_per_user
            // dari DB (string) di-(int) kan sebelum perbandingan/selisih.
            $max                   = (int) $p['max_per_user'];
            $p['quota_max']        = $max;
            $p['is_unlimited']     = ($max === 0);
            $p['quota_remaining']  = $p['is_unlimited']
                ? null
                : max(0, $max - $p['user_rentals_count']);
            $p['is_quota_exhausted'] = (!$p['is_unlimited']
                && $p['user_rentals_count'] >= $max);

            $prereq          = ($p['prerequisite_id'] === null)
                ? null
                : (int) $p['prerequisite_id'];
            $p['is_locked']  = ($prereq !== null && !isset($counts[$prereq]));

            $p['can_rent']   = !$p['is_locked'] && !$p['is_quota_exhausted'];
        }
        unset($p);

        return $rows;
    }
}
