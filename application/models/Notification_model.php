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
}
