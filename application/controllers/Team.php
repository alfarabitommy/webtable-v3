<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Team extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('User_model');
        $this->load->model('Rate_limit_model');
        $this->load->helper('ratelimit');
        // M9/P7 (plan/76 Batch B): choke-point JSON helper.
        $this->load->helper('api');
    }

    public function index() {
        $user_id = $this->session->userdata('user_id');
        $user = $this->User_model->get_user_by_id($user_id);
        $members = $this->User_model->get_team_with_active_status($user_id);

        // Format phone for WhatsApp + cast is_active to bool
        foreach ($members as &$m) {
            $m->phone_full = $m->phone;
            $m->phone_wa = $this->_format_wa_phone($m->phone);
            $m->is_active = (int) $m->is_active > 0;
        }
        unset($m);

        // Counts
        $total_bc  = count($members);
        $active_bc = 0;
        $l1_active = 0;
        $l2_active = 0;
        foreach ($members as $m) {
            if ($m->is_active) {
                $active_bc++;
                ($m->level == 1) ? $l1_active++ : $l2_active++;
            }
        }

        // Phase 9B: claim data
        $claim_data = $this->User_model->get_claim_data($user_id);

        $data = [
            'page_title' => 'Tim & Afiliasi',
            'user'       => $user,
            'members'    => $members,
            'total_bc'   => $total_bc,
            'active_bc'  => $active_bc,
            'l1_active'  => $l1_active,
            'l2_active'  => $l2_active,
            'ref_url'    => base_url('register?ref=' . $user->invite_code),
            'claim_data' => $claim_data,
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('team/index', $data);
        $this->load->view('templates/bottom_nav');
    }

    /**
     * POST: Claim Level 1 bonus
     *
     * M9/P7 (plan/76 Batch B): respons lewat choke-point api_* — envelope
     * {success, message, data} + SEMUA key legacy root yang dibaca frontend
     * (message, new_balance). Unauthenticated -> HTTP 401 JSON. Business
     * rejection tetap HTTP 200 {success:false} (parity lama — JS team
     * claimLevel1() hanya branch pada key, bukan status).
     */
    public function claim_level1() {
        if ( ! $this->input->is_ajax_request()) {
            show_404();
        }

        $user_id = $this->session->userdata('user_id');
        if ( ! $user_id) {
            $message = 'Sesi habis. Silakan login ulang.';
            api_error($message, 401, [], 'unauthenticated', ['message' => $message]);
        }

        $result = $this->User_model->claim_level1($user_id);

        if ( ! $result['success']) {
            // Legacy body lama {success:false, message} + envelope additive.
            api_error($result['message'], 200, [], null, ['message' => $result['message']]);
        }

        // C4 (plan/54): saldo segar dari wallet_ledger — bukan
        // users.balance yang basi (parity dengan claim_wage).
        $result['new_balance'] = $this->Wallet_model->get_balance($user_id);

        // Notify user
        $this->Notification_model->insert(
            $user_id,
            'Bonus Level 1 Cair',
            'Selamat! Bonus Level 1 sebesar Rp 80.000 telah masuk ke saldo.',
            'commission'
        );

        // Envelope + legacy root {success di-canonical, message, new_balance}.
        $legacy = $result;
        unset($legacy['success']);
        api_success(
            ['new_balance' => $result['new_balance']],
            $result['message'],
            200,
            $legacy
        );
    }

    /**
     * POST: Claim weekly wage (L2-6) — C6 (plan/50).
     * Hanya lapisan HTTP/UX: method POST, AJAX, sesi, rate limit, dan
     * pemetaan kode hasil model ke JSON — semua SQL di model (AGENTS.md).
     *
     * M9/P7 (plan/76 Batch B): respons lewat choke-point api_* — envelope
     * {success, message, data} + SEMUA key legacy root yang dibaca frontend
     * (code, message, amount, level, cycle, transaction_id, next_claim_date,
     * new_balance). Status: unauthenticated -> 401; code 'error' -> 500
     * (parity lama); business rejection lain -> 200 {success:false} —
     * claimWage() JS hanya branch pada key `code`.
     */
    public function claim_wage() {
        // POST-only — tutup celah GET-mutation (audit M9).
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        if ( ! $this->input->is_ajax_request()) {
            show_404();
            return;
        }

        $user_id = $this->session->userdata('user_id');
        if ( ! $user_id) {
            $message = 'Sesi habis. Silakan login ulang.';
            api_error($message, 401, [], 'unauthenticated', ['code' => 'unauthenticated', 'message' => $message]);
        }

        // Rate limit (pola Wallet::process_withdraw, plan/50 §3.7):
        // key wage_claim:{user_id}, 5 hit / 60 detik.
        $rl_key   = 'wage_claim:' . $user_id;
        $throttle = $this->Rate_limit_model->check($rl_key, 5, 60);
        if ( ! $throttle['allowed']) {
            rate_limit_json_response($throttle);
        }
        $this->Rate_limit_model->hit($rl_key, 60, 5);

        $result = $this->User_model->claim_wage($user_id);

        // Semua key model (kecuali success) tetap di root sebagai legacy —
        // reproduksi body lama {success, code, message, amount, level, ...}.
        $legacy = $result;
        unset($legacy['success']);

        // Internal error -> HTTP 500 (parity lama: set_status_header(500)).
        if ($result['code'] === 'error') {
            api_error($result['message'], 500, [], 'error', $legacy);
        }

        if ($result['success']) {
            // Saldo segar dari wallet_ledger — bukan users.balance yang basi (C4).
            $result['new_balance'] = $this->Wallet_model->get_balance($user_id);
            $legacy['new_balance'] = $result['new_balance'];

            // Notifikasi (parity claim_level1).
            $this->Notification_model->insert(
                $user_id,
                'Gaji Mingguan Cair',
                'Selamat! Gaji mingguan Level ' . $result['level']
                    . ' sebesar Rp ' . number_format((int) $result['amount'], 0, ',', '.')
                    . ' telah masuk ke saldo.',
                'commission'
            );

            api_success(
                [
                    'level'          => $result['level'],
                    'amount'         => $result['amount'],
                    'new_balance'    => $result['new_balance'],
                    'cycle'          => $result['cycle'],
                    'transaction_id' => $result['transaction_id'],
                ],
                $result['message'],
                200,
                $legacy
            );
        }

        // Business rejection (already_claimed / cycle_not_ready /
        // not_qualified / user_unavailable) — HTTP 200 {success:false}
        // + key `code` untuk branching JS claimWage().
        api_error($result['message'], 200, [], $result['code'], $legacy);
    }

    /**
     * Format phone for WhatsApp wa.me API
     * 08123456789 → 628123456789
     */
    private function _format_wa_phone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strpos($phone, '0') === 0) {
            $phone = '62' . substr($phone, 1);
        } elseif (strpos($phone, '62') !== 0) {
            $phone = '62' . $phone;
        }
        return $phone;
    }
}
