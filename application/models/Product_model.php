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
     * plan/83 + plan/87 — Katalog ter-personalisasi untuk satu user (DISPLAY ONLY).
     * Menyajikan produk AKTIF + telemetri kuota pembelian lifetime user.
     * Otoritas GATE (kuota & is_active) tetap di Rental_model::checkout_rental
     * di dalam TX terkunci — method ini hanya bahan render UI marketplace
     * (State A available / B quota reached), TIDAK pernah dipercaya.
     *
     * plan/87: gating prasyarat DICOMMISSIONED — ketersediaan murni via
     * toggle admin is_active; produk non-aktif tidak pernah dirender (bukan
     * kartu terkunci/dim). Predikat kuota (D1, plan/83): user_rentals.status
     * IN ('active','completed') — baris 'cancelled' (soft-cancel admin tanpa
     * refund) tidak memakan kuota.
     *
     * @param int $user_id
     * @return array  Produk aktif (result_array, order id ASC) dengan ekstra:
     *   user_rentals_count (int), quota_max (int), is_unlimited (bool),
     *   quota_remaining (int|null), is_quota_exhausted (bool),
     *   can_rent (bool). Produk non-aktif / katalog kosong → array kosong
     *   (empty-state marketplace P4 tetap tercapai).
     */
    public function get_catalog_for_user($user_id) {
        $user_id = (int) $user_id;

        // Query A — produk aktif SAJA (is_active = 1). Tanpa self-join
        // prasyarat (plan/87): gating 100% via toggle admin; produk
        // non-aktif tidak pernah dirender dalam bentuk apa pun.
        $rows = $this->db->query(
            "SELECT p.*
               FROM gpu_products p
              WHERE p.is_active = 1
              ORDER BY p.id ASC"
        )->result_array();

        // Query B — SATU agregat untuk seluruh riwayat kualifikasi user.
        $counts = [];
        foreach ($this->db->query(
            "SELECT product_id, COUNT(*) AS cnt
               FROM user_rentals
              WHERE user_id = ? AND status IN ('active', 'completed')
                -- plan/91 (K4): kuota marketplace = kanal BERBAYAR saja;
                -- kontrak reward (source='promoter_reward') tidak memakannya.
                AND source <> 'promoter_reward'
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

            // plan/87: tanpa prasyarat — ketersediaan murni fungsi kuota
            // (is_active sudah difilter di Query A).
            $p['can_rent'] = !$p['is_quota_exhausted'];
        }
        unset($p);

        return $rows;
    }
}
