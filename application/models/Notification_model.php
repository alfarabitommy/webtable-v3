<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Notification_model extends CI_Model {

    private $table = 'user_notifications';

    public function get_unread_count($user_id) {
        return (int) $this->db
            ->where('user_id', $user_id)
            ->where('is_read', 0)
            ->count_all_results($this->table);
    }

    public function get_latest($user_id, $limit = 5) {
        return $this->db
            ->where('user_id', $user_id)
            ->order_by('created_at', 'DESC')
            ->get($this->table, $limit)
            ->result_array();
    }

    public function mark_read($user_id) {
        return $this->db
            ->where('user_id', $user_id)
            ->where('is_read', 0)
            ->update($this->table, ['is_read' => 1]);
    }

    /**
     * Total notifications for a user (pagination count — P3, plan/80)
     */
    public function count_by_user($user_id) {
        return (int) $this->db
            ->where('user_id', $user_id)
            ->count_all_results($this->table);
    }

    /**
     * Get notifications for full history page (paginated — P3, plan/80)
     * @param int $user_id
     * @param int $limit  items per page (default 15)
     * @param int $offset zero-based row offset (page * limit)
     */
    public function get_by_user($user_id, $limit = 15, $offset = 0) {
        return $this->db
            ->where('user_id', $user_id)
            ->order_by('created_at', 'DESC')
            ->limit($limit, $offset)
            ->get($this->table)
            ->result_array();
    }

    /**
     * Mark single notification as read (by id + user_id for security)
     */
    public function mark_single_read($id, $user_id) {
        return $this->db
            ->where('id', $id)
            ->where('user_id', $user_id)
            ->update($this->table, ['is_read' => 1]);
    }

    public function insert($user_id, $title, $message, $type = 'info') {
        return $this->db->insert($this->table, [
            'user_id' => $user_id,
            'title'   => $title,
            'message' => $message,
            'type'    => $type,
        ]);
    }

    // =================================================================
    //  plan/103 (W8) — NOTIFIKASI DWIBAHASA
    //
    //  Jalur tulis kanonik: simpan KEY + PARAMETER, bukan prosa beku.
    //  Kolom `title`/`message` TETAP diisi (retensi, fallback baris legacy,
    //  dan konsumen non-member) — dirender dalam idiom saat insert,
    //  sedangkan pembacaan member memakai i18n_notification_text() sehingga
    //  bahasa notifikasi mengikuti pilihan pembaca saat itu juga.
    //
    //  Param TIDAK pernah berisi kalimat ber-terjemahan; hanya nominal yang
    //  sudah diformat (Rp …, L6) dan nilai domain (angka, nama produk,
    //  catatan admin) yang sengaja dibiarkan apa adanya.
    // =================================================================

    /**
     * Insert notifikasi ber-key (plan/103).
     *
     * @param  int    $user_id
     * @param  string $key    Key dasar kamus TANPA suffix (_title/_body)
     * @param  array  $params Argumen vsprintf untuk key `<key>_body`
     * @param  string $type   info|success|warning|commission
     * @return bool
     */
    public function insert_keyed($user_id, $key, array $params = [], $type = 'info') {
        $title = lang($key . '_title');
        $body  = lang($key . '_body');

        // Idiom belum dimuat (konteks tanpa i18n_apply) → aman string kosong;
        // renderer saat dibaca yang menentukan teks final.
        $title = is_string($title) ? $title : '';
        $body  = is_string($body) ? $body : '';

        if ($body !== '' && $params) {
            $rendered = @vsprintf($body, array_values($params));
            $body = ($rendered === FALSE) ? $body : $rendered;
        }

        return $this->db->insert($this->table, [
            'user_id'   => $user_id,
            'title_key' => $key,
            'params'    => $params ? json_encode(array_values($params), JSON_UNESCAPED_UNICODE) : NULL,
            'title'     => $title,
            'message'   => $body,
            'type'      => $type,
        ]);
    }
}
