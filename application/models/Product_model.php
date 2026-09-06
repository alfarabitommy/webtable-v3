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
}
