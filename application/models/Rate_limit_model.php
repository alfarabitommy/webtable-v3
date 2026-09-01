<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Rate_limit_model extends CI_Model {

    public function __construct() {
        parent::__construct();
        // Pastikan timezone PHP konsisten Asia/Jakarta walau model dipanggil
        // dari web (index.php sudah set) maupun CLI/cron — semua timestamp
        // rate limit di-generate dari PHP (fix Phase 10B).
        if (date_default_timezone_get() !== 'Asia/Jakarta') {
            date_default_timezone_set('Asia/Jakarta');
        }
        $this->load->database();
    }

    // ===================================================================
    //  RATE LIMITER (Phase 10B)
    //
    //  Satu baris per composite key (endpoint + identitas), lihat
    //  plan/18_PHASE_10B_PLAN.md §2.2 untuk komposisi key.
    //
    //  Semua tulis memakai atomic InnoDB upsert (INSERT ... ON DUPLICATE
    //  KEY UPDATE) — tanpa read-modify-write, aman terhadap konkurensi.
    //  Semua nilai user masuk sebagai bound params (konvensi AGENTS.md).
    //
    //  TIMEZONE (fix Phase 10B): semua timestamp (last_attempt_at,
    //  locked_until, cutoff GC) di-generate di PHP via date('Y-m-d H:i:s')
    //  dengan timezone Asia/Jakarta (di-set di index.php) dan dikirim
    //  sebagai bound params. MySQL NOW() TIDAK dipakai — timezone server
    //  MySQL (sering UTC) tidak lagi bisa membuat selisih 7 jam pada
    //  remaining_seconds lockout (bug "435 menit").
    // ===================================================================

    /**
     * Cek status throttle untuk sebuah key. READ-ONLY (tidak mengubah
     * attempts); satu-satunya tulis adalah auto-reset saat lock
     * kedaluwarsa agar pengguna mendapat 5 percobaan segar.
     *
     * @param string $key             Composite key, e.g. 'login:081234567890:127.0.0.1'
     * @param int    $max_attempts    Ambang percobaan sebelum lock (default 5)
     * @param int    $lockout_seconds Durasi lock (default 900 = 15 menit)
     * @return array{allowed: bool, remaining_seconds: int, attempts: int}
     */
    public function check($key, $max_attempts = 5, $lockout_seconds = 900) {
        $this->_maybe_gc();

        $row = $this->db->get_where('rate_limits', ['rate_key' => $key])->row();

        if (!$row) {
            return ['allowed' => true, 'remaining_seconds' => 0, 'attempts' => 0];
        }

        $now = time();

        // Lock aktif → blokir dengan sisa waktu menunggu
        if ($row->locked_until && strtotime($row->locked_until) > $now) {
            return [
                'allowed'           => false,
                'remaining_seconds' => strtotime($row->locked_until) - $now,
                'attempts'          => (int) $row->attempts,
            ];
        }

        // Lock kedaluwarsa → reset counter (fresh start), tidak blokir
        if ($row->locked_until) {
            $this->clear($key);
            return ['allowed' => true, 'remaining_seconds' => 0, 'attempts' => 0];
        }

        return ['allowed' => true, 'remaining_seconds' => 0, 'attempts' => (int) $row->attempts];
    }

    /**
     * Catat satu percobaan: increment counter (dengan window decay —
     * jika percobaan terakhir lebih tua dari window, counter di-reset ke 1)
     * dan set locked_until saat ambang tercapai (idempotent, tidak
     * memperpanjang lock pada percobaan berikutnya).
     *
     * @param string   $key             Composite key
     * @param int      $lockout_seconds Durasi lock (default 900)
     * @param int|null $max_attempts    Ambang lock (default 5)
     * @return array  Hasil check() setelah hit
     */
    public function hit($key, $lockout_seconds = 900, $max_attempts = null) {
        $max_attempts    = (int) ($max_attempts ?: 5);
        $lockout_seconds = (int) $lockout_seconds;
        $this->_maybe_gc();

        // Timestamp di-generate PHP (Asia/Jakarta, di-set di index.php) dan
        // dikirim sebagai bound params — TIDAK memakai MySQL NOW() agar
        // tidak bergantung pada timezone server MySQL (fix Phase 10B).
        $now          = date('Y-m-d H:i:s');
        $window_cut   = date('Y-m-d H:i:s', time() - $lockout_seconds);
        $locked_until = date('Y-m-d H:i:s', time() + $lockout_seconds);

        // Atomic increment + window decay dalam satu statement
        $this->db->query(
            "INSERT INTO rate_limits (rate_key, attempts, last_attempt_at)
             VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
               attempts = IF(last_attempt_at < ?, 1, attempts + 1),
               last_attempt_at = ?",
            [$key, $now, $window_cut, $now]
        );

        // Set lock tepat saat ambang tercapai — hanya jika belum terkunci
        $this->db->query(
            "UPDATE rate_limits
             SET locked_until = ?
             WHERE rate_key = ? AND attempts >= ? AND locked_until IS NULL",
            [$locked_until, $key, $max_attempts]
        );

        return $this->check($key, $max_attempts, $lockout_seconds);
    }

    /**
     * Reset key setelah autentikasi/aksi sukses. Implementasi DELETE
     * (setara "reset ke 0") — tabel tetap lean, tak ada baris basi.
     *
     * @param string $key Composite key
     */
    public function clear($key) {
        $this->db->where('rate_key', $key)->delete('rate_limits');
    }

    /**
     * Garbage collection: hapus baris yang idle ≥ 30 menit (2× window
     * default 15 menit). Baris terkunci tidak mungkin idle ≥ 30 menit
     * karena last_attempt_at selalu diperbarui oleh hit().
     */
    public function gc() {
        // Cutoff 30 menit (2× window default) — di-generate PHP (Asia/Jakarta),
        // konsisten dengan last_attempt_at yang ditulis oleh hit().
        $cutoff = date('Y-m-d H:i:s', time() - 1800);
        $this->db->query("DELETE FROM rate_limits WHERE last_attempt_at < ?", [$cutoff]);
    }

    /**
     * GC probabilistik (~1% per pemanggilan check/hit) — biaya mendekati
     * nol, tidak perlu request berdedikasi.
     */
    private function _maybe_gc() {
        if (mt_rand(1, 100) === 1) {
            $this->gc();
        }
    }
}
