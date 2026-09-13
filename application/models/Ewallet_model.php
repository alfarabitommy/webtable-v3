<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Ewallet_model — Plan 106 (katalog provider e-wallet dinamis).
 *
 * Satu-satunya sumber kebenaran tabel `ewallet_providers`, dipakai bersama oleh:
 *
 *   - Member (`Wallet::bind_bank`, gate penarikan `Wallet::withdraw` /
 *     `process_withdraw`) → HANYA provider aktif yang boleh dipilih/dipakai;
 *   - Admin (`Admin::ewallet_providers` + CRUD provider) → melihat semua baris
 *     berikut hitungan binding aktif/total sebagai konteks keputusan;
 *   - CLI `scripts/migrate_106_ewallet_withdrawal.php` (verifikasi katalog).
 *
 * Aturan penting:
 *   - Hanya model ini yang boleh menulis `ewallet_providers` (single writer);
 *   - `bank_accounts` TIDAK ditulis dari sini — pemiliknya `Wallet_model`
 *     (`bind_user_ewallet` / `unbind_user_ewallet` / `reassign_provider_name`),
 *     dan cascade rename dipanggil admin dari `Wallet_model`;
 *   - TIDAK ADA hard delete (plan 106 keputusan D7): `bank_name` pada baris
 *     historis harus selalu bisa di-resolve ke katalog.
 */
class Ewallet_model extends CI_Model {

    /** @var string */
    private $table = 'ewallet_providers';

    // ===================================================================
    //  READ — MEMBER (hanya provider aktif)
    // ===================================================================

    /**
     * Provider aktif untuk selector member (`/wallet/bind_bank`).
     *
     * @return array Urut nama ASC.
     */
    public function get_active_providers() {
        return $this->db
            ->where('is_active', 1)
            ->order_by('name', 'ASC')
            ->get($this->table)
            ->result();
    }

    /**
     * Choke-point validasi POST member: baris provider yang AKTIF.
     *
     * @param  int $id
     * @return object|null null bila tidak ada ATAU nonaktif (fail-closed).
     */
    public function get_active_provider($id) {
        return $this->db
            ->where('id', (int) $id)
            ->where('is_active', 1)
            ->get($this->table)
            ->row();
    }

    /**
     * Resolusi nama tersimpan (`bank_accounts.bank_name`) → baris katalog.
     *
     * Dipakai gate penarikan & deteksi drift: baris boleh nonaktif (pemanggil
     * yang memutuskan blokir), tetapi HARUS ada di katalog.
     *
     * @param  string $name
     * @return object|null
     */
    public function get_provider_by_name($name) {
        $name = trim((string) $name);
        if ($name === '') { return null; }

        return $this->db
            ->where('name', $name)
            ->order_by('id', 'ASC')
            ->limit(1)
            ->get($this->table)
            ->row();
    }

    /**
     * Jumlah provider aktif (guard D6: minimal satu harus tetap aktif).
     *
     * @return int
     */
    public function count_active_providers() {
        return (int) $this->db->where('is_active', 1)->count_all_results($this->table);
    }

    // ===================================================================
    //  READ — ADMIN (semua baris + konteks pemakaian)
    // ===================================================================

    /**
     * Daftar provider untuk panel admin, lengkap dengan jumlah akun terikat.
     *
     * `active_bindings` = baris `bank_accounts` dengan `is_primary = 1`
     * (binding hidup, akan terblokir bila provider dinonaktifkan);
     * `total_bindings`  = seluruh baris historis (termasuk arsip).
     *
     * @return array
     */
    public function get_providers_admin() {
        $rows = $this->db->query(
            "SELECT p.*,
                    COALESCE(b.active_cnt, 0) AS active_bindings,
                    COALESCE(b.total_cnt, 0)  AS total_bindings
               FROM `ewallet_providers` p
               LEFT JOIN (
                    SELECT bank_name,
                           SUM(is_primary = 1) AS active_cnt,
                           COUNT(*)            AS total_cnt
                      FROM `bank_accounts`
                     GROUP BY bank_name
               ) b ON b.bank_name = p.name
              ORDER BY p.is_active DESC, p.name ASC"
        )->result();

        foreach ($rows as $r) {
            $r->id              = (int) $r->id;
            $r->is_active       = (int) $r->is_active;
            $r->active_bindings = (int) $r->active_bindings;
            $r->total_bindings  = (int) $r->total_bindings;
        }

        return $rows;
    }

    /**
     * Baris provider by id (state apa pun) — untuk toggle/rename/audit.
     *
     * @param  int $id
     * @return object|null
     */
    public function get_provider($id) {
        return $this->db->where('id', (int) $id)->get($this->table)->row();
    }

    /**
     * Daftar code kanonik yang sudah ada (dipakai CLI/verify & pesan galat).
     *
     * @return array<string>
     */
    public function get_codes() {
        $out = [];
        foreach ($this->db->select('code')->get($this->table)->result() as $r) {
            $out[] = (string) $r->code;
        }
        return $out;
    }

    // ===================================================================
    //  VALIDASI DUPLIKAT (collation utf8mb4_unicode_ci = case-insensitive)
    // ===================================================================

    /**
     * @param  string   $code
     * @param  int|null $exclude_id
     * @return bool
     */
    public function code_exists($code, $exclude_id = null) {
        $this->db->where('code', strtoupper(trim((string) $code)));
        if ($exclude_id !== null) { $this->db->where('id !=', (int) $exclude_id); }
        return $this->db->count_all_results($this->table) > 0;
    }

    /**
     * @param  string   $name
     * @param  int|null $exclude_id
     * @return bool
     */
    public function name_exists($name, $exclude_id = null) {
        $this->db->where('name', trim((string) $name));
        if ($exclude_id !== null) { $this->db->where('id !=', (int) $exclude_id); }
        return $this->db->count_all_results($this->table) > 0;
    }

    // ===================================================================
    //  WRITE — ADMIN CRUD (tanpa hard delete)
    // ===================================================================

    /**
     * Tambah provider baru.
     *
     * @param  array $fields code, name, is_active
     * @return int|false insert_id, atau false saat gagal
     */
    public function create_provider(array $fields) {
        $data = [
            'code'      => strtoupper(trim((string) ($fields['code'] ?? ''))),
            'name'      => trim((string) ($fields['name'] ?? '')),
            'is_active' => ((int) ($fields['is_active'] ?? 1) === 1) ? 1 : 0,
        ];

        if (!$this->db->insert($this->table, $data)) {
            log_message('error', 'Ewallet_model::create_provider — insert gagal: '
                . $this->db->error()['message']);
            return false;
        }

        return (int) $this->db->insert_id();
    }

    /**
     * Rename provider (CODE immutable — identitas stabil untuk audit/CLI).
     *
     * Cascade ke `bank_accounts.bank_name` BUKAN tanggung jawab model ini:
     * admin memanggil `Wallet_model::reassign_provider_name()` di dalam TX yang
     * sama (satu penulis per tabel).
     *
     * @param  int    $id
     * @param  string $name
     * @return bool
     */
    public function rename_provider($id, $name) {
        $this->db->where('id', (int) $id);
        $ok = $this->db->update($this->table, ['name' => trim((string) $name)]);

        if (!$ok) {
            log_message('error', 'Ewallet_model::rename_provider — update gagal: '
                . $this->db->error()['message']);
        }

        return (bool) $ok;
    }

    /**
     * Set status aktif/nonaktif.
     *
     * @param  int $id
     * @param  int $state 1 = aktif, 0 = nonaktif
     * @return bool
     */
    public function set_provider_active($id, $state) {
        $this->db->where('id', (int) $id);
        $ok = $this->db->update($this->table, ['is_active' => ((int) $state === 1) ? 1 : 0]);

        if (!$ok) {
            log_message('error', 'Ewallet_model::set_provider_active — update gagal: '
                . $this->db->error()['message']);
        }

        return (bool) $ok;
    }
}
