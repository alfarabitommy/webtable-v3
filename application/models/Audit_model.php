<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Audit_model extends CI_Model {

    public function __construct() {
        parent::__construct();
        $this->load->database();
    }

    // ===================================================================
    //  AUDIT LOGGER (Phase 10A)
    //
    //  Transaction-agnostic by design: performs a plain INSERT and relies
    //  on the CALLER's ACID envelope (trans_start/trans_complete or
    //  trans_begin/trans_commit/trans_rollback) for atomicity. Opening a
    //  nested transaction here would corrupt CI3's transaction depth
    //  counter and risk partial commits — so the helper never manages
    //  transactions itself. Every call site must be inside the same
    //  transaction as its action so a failed operation rolls back both.
    // ===================================================================

    /**
     * Insert one admin audit log row.
     *
     * @param int|null    $admin_id  Session admin_id (FK admins.id, SET NULL on delete)
     * @param int|null    $user_id   Target user id (FK users.id, SET NULL on delete)
     * @param string      $action    Action vocabulary, e.g. 'approve_deposit'
     * @param array|null  $details   Associative array, JSON-encoded before insert
     * @param string      $ip_address Client IP (VARCHAR 45)
     * @return bool
     */
    public function log_admin_action($admin_id, $user_id, $action, $details = null, $ip_address = '') {
        return $this->db->insert('system_audit_logs', [
            'admin_id'   => $admin_id ? (int) $admin_id : null,
            'user_id'    => $user_id ? (int) $user_id : null,
            'action'     => $action,
            'details'    => is_array($details)
                ? json_encode($details, JSON_UNESCAPED_UNICODE)
                : $details,
            'ip_address' => $ip_address,
        ]);
    }

    // ===================================================================
    //  AUDIT VIEWER QUERIES
    // ===================================================================

    /**
     * Fetch audit log rows with optional filters, newest first.
     *
     * @param string $action Exact action filter (whitelisted upstream)
     * @param string $from   Date YYYY-MM-DD (inclusive, start of day)
     * @param string $to     Date YYYY-MM-DD (inclusive, end of day)
     * @param int    $limit
     * @param int    $offset
     * @return array
     */
    public function get_audit_logs($action = '', $from = '', $to = '', $limit = 50, $offset = 0) {
        $this->db->select('a.id, a.admin_id, a.user_id, a.action, a.details, a.ip_address, a.created_at, adm.username AS admin_username, u.phone AS user_phone');
        $this->db->from('system_audit_logs a');
        $this->db->join('admins adm', 'adm.id = a.admin_id', 'left');
        $this->db->join('users u', 'u.id = a.user_id', 'left');

        $this->_apply_filters($action, $from, $to);

        $this->db->order_by('a.created_at', 'DESC');
        $this->db->order_by('a.id', 'DESC');
        $this->db->limit((int) $limit, max(0, (int) $offset));

        return $this->db->get()->result();
    }

    /**
     * Count audit rows matching the same filters (for pagination).
     *
     * @param string $action
     * @param string $from
     * @param string $to
     * @return int
     */
    public function count_audit_logs($action = '', $from = '', $to = '') {
        $this->db->from('system_audit_logs a');
        $this->_apply_filters($action, $from, $to);
        return (int) $this->db->count_all_results();
    }

    /**
     * Distinct actions + row count, for the filter dropdown.
     *
     * @return array  Each row: action, cnt
     */
    public function get_action_options() {
        return $this->db->query(
            "SELECT action, COUNT(*) AS cnt
             FROM system_audit_logs
             GROUP BY action
             ORDER BY cnt DESC, action ASC"
        )->result();
    }

    // --- shared WHERE builder (bound params only) ---

    private function _apply_filters($action, $from, $to) {
        if ($action !== '') {
            $this->db->where('a.action', $action);
        }
        if ($from !== '') {
            $this->db->where('DATE(a.created_at) >=', $from);
        }
        if ($to !== '') {
            $this->db->where('DATE(a.created_at) <=', $to);
        }
    }
}
