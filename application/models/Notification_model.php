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
     * Get all notifications for full history page
     */
    public function get_by_user($user_id, $limit = 100) {
        return $this->db
            ->where('user_id', $user_id)
            ->order_by('created_at', 'DESC')
            ->get($this->table, $limit)
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
