<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Team extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('User_model');
        $this->load->model('Rental_model');
        $this->load->model('Rate_limit_model');
        $this->load->helper('ratelimit');
        // M9/P7 (plan/76 Batch B): choke-point JSON helper.
        $this->load->helper('api');
    }

    public function index() {
        $user_id = $this->session->userdata('user_id');
        $user = $this->User_model->get_user_by_id($user_id);
        $members = $this->User_model->get_team_with_active_status($user_id);

        // Plan 89: gating referral — kode/link/QR terbuka hanya bila member
        // PERNAH menyewa (lifetime >= 1); derived dari user_rentals.
        // Plan 91 (K1): promotor (users.is_promoter=1) BYPASS Condition A —
        // kode/link/QR terbuka permanen walau lifetime == 0.
        $rental_stats = $this->Rental_model->get_user_rental_stats($user_id);
        $is_promoter  = ((int) ($user->is_promoter ?? 0)) === 1;
        $referral_locked = ((int) $rental_stats['lifetime_rentals']) === 0 && !$is_promoter;

        // Plan 91: data hub Program Promotor (hanya untuk promotor).
        $promoter_summary = null;
        $promoter_tiers   = [];
        $promoter_history = [];
        if ($is_promoter) {
            $this->load->model('Promoter_model');
            $promoter_summary = $this->Promoter_model->get_omzet_summary($user_id);
            $promoter_tiers   = $this->Promoter_model->get_reward_tiers($user_id);
            $promoter_history = $this->Promoter_model->get_claim_history($user_id, 15);
        }

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
            'page_title'      => lang('team_page_title'),
            'user'            => $user,
            'members'         => $members,
            'total_bc'        => $total_bc,
            'active_bc'       => $active_bc,
            'l1_active'       => $l1_active,
            'l2_active'       => $l2_active,
            // Plan 89: saat terkunci (lifetime == 0), ref_url KOSONG —
            // kode undangan tidak pernah bocor ke markup (Condition A).
            'ref_url'         => $referral_locked ? '' : base_url('register?ref=' . $user->invite_code),
            'referral_locked' => $referral_locked,
            'rental_stats'    => $rental_stats,
            // Plan 91: flag & data Program Promotor (null/kosong utk non-promotor).
            'is_promoter'     => $is_promoter,
            'promoter_summary' => $promoter_summary,
            'promoter_tiers'  => $promoter_tiers,
            'promoter_history'=> $promoter_history,
            'claim_data'      => $claim_data,
            // P5 (plan/80): single source of truth untuk tampilan bonus L1.
            'l1_bonus'     => User_model::LEVEL1_BONUS,
            'l1_bonus_fmt' => number_format(User_model::LEVEL1_BONUS, 0, ',', '.'),
        ];

        // Plan 89: persen komisi untuk kartu panduan — dari merged dynamic
        // config (fallback-safe), bukan hardcode.
        $rebate_cfg = $this->Rental_model->get_rebate_config();
        $data['rebate_enabled']    = (int) $rebate_cfg['rebate_enabled'];
        $data['rebate_l1_percent'] = (int) $rebate_cfg['rebate_l1_percent'];
        $data['rebate_l2_percent'] = (int) $rebate_cfg['rebate_l2_percent'];
        $data['rebate_l3_percent'] = (int) $rebate_cfg['rebate_l3_percent'];

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
            $message = lang('common_session_expired');
            api_error($message, 401, [], 'unauthenticated', ['message' => $message]);
        }

        $result = $this->User_model->claim_level1($user_id);

        if ( ! $result['success']) {
            // plan/103 W8/D1: `code` → key kamus; prosa model hanya diagnostik.
            $message = $this->_claim_message($result, 'rental_err_claim_failed');
            $legacy  = array_merge($result, ['message' => $message]);
            unset($legacy['success']);
            api_error($message, 200, [], null, $legacy);
        }

        // C4 (plan/54): saldo segar dari wallet_ledger — bukan
        // users.balance yang basi (parity dengan claim_wage).
        $result['new_balance'] = $this->Wallet_model->get_balance($user_id);

        // Notify user — jumlah dinamis dari $result['amount'] (P5, plan/80):
        // parity gaya claim_wage, tier berubah → notifikasi ikut berubah.
        $this->Notification_model->insert_keyed(
            $user_id,
            'notif_bonus_l1',
            [number_format((int) $result['amount'], 0, ',', '.')],
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
            $message = lang('common_session_expired');
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
        // plan/103: pesan diterjemahkan dari `code` dalam idiom aktif.
        $localized = $this->_wage_message($result);
        $legacy['message'] = $localized;

        if ($result['code'] === 'error') {
            api_error($localized, 500, [], 'error', $legacy);
        }

        if ($result['success']) {
            // Saldo segar dari wallet_ledger — bukan users.balance yang basi (C4).
            $result['new_balance'] = $this->Wallet_model->get_balance($user_id);
            $legacy['new_balance'] = $result['new_balance'];
            $localized = lang('team_js_wage_success');

            // Notifikasi (parity claim_level1).
            $this->Notification_model->insert_keyed(
                $user_id,
                'notif_wage',
                [number_format((int) $result['amount'], 0, ',', '.')],
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
                $localized,
                200,
                $legacy
            );
        }

        // Business rejection (already_claimed / cycle_not_ready /
        // not_qualified / user_unavailable) — HTTP 200 {success:false}
        // + key `code` untuk branching JS claimWage().
        api_error($localized, 200, [], $result['code'], $legacy);
    }

    /**
     * POST (AJAX): Ajukan klaim reward promotor — plan/91.
     * Lapisan HTTP/UX saja (pola claim_level1/claim_wage): POST + AJAX +
     * sesi + rate limit + pemetaan hasil model ke JSON M9. SELURUH gate,
     * omzet engine & TX ada di Promoter_model::submit_claim().
     */
    public function promoter_claim() {
        // POST-only + AJAX-only — tutup celah GET-mutation (audit M9).
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
            $message = lang('common_session_expired');
            api_error($message, 401, [], 'unauthenticated', ['message' => $message]);
        }

        // M8 (plan/74): input produk — integer ketat ^[1-9][0-9]*$.
        $product_raw = $this->input->post('product_id', TRUE);
        $product_id  = (is_string($product_raw) && preg_match('/^[1-9][0-9]*$/', $product_raw))
            ? (int) $product_raw : 0;
        if ($product_id <= 0) {
            // plan/103: pesan validasi input dari kamus (idiom aktif).
            $bad_input = lang('promo_err_invalid_request');
            api_error($bad_input, 200, [], 'invalid_request', ['message' => $bad_input]);
        }

        // Rate limit (pola claim_wage, plan/50 §3.7): promoter_claim:{uid}, 5/60 dtk.
        $rl_key   = 'promoter_claim:' . $user_id;
        $throttle = $this->Rate_limit_model->check($rl_key, 5, 60);
        if ( ! $throttle['allowed']) {
            rate_limit_json_response($throttle);
        }
        $this->Rate_limit_model->hit($rl_key, 60, 5);

        $this->load->model('Promoter_model');
        $result = $this->Promoter_model->submit_claim($user_id, $product_id);

        // Semua key model (kecuali success) tetap di root sebagai legacy.
        $legacy = $result;
        unset($legacy['success']);

        // plan/103: `code` → key kamus (idiom aktif); prosa model diagnostik.
        $pmsg = $this->Promoter_model->localize_result($result);
        $legacy['message'] = $pmsg;

        // Internal error -> HTTP 500 (parity claim_wage).
        if ($result['code'] === 'error') {
            api_error($pmsg, 500, [], 'error', $legacy);
        }

        if ($result['success']) {
            api_success(
                [
                    'claim_id' => $result['claim_id'],
                    'summary'  => $this->Promoter_model->get_omzet_summary($user_id),
                ],
                $pmsg,
                200,
                $legacy
            );
        }

        // Business rejection (not_promoter / banned / insufficient_omzet /
        // quota_exceeded / product_unavailable / ratio_invalid ...) —
        // HTTP 200 {success:false} + key `code`.
        api_error($pmsg, 200, [], $result['code'], $legacy);
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

    /**
     * Plan 103: hasil User_model::claim_level1() → pesan idiom aktif.
     *
     * @param  array  $result
     * @param  string $fallback_key
     * @return string
     */
    private function _claim_message(array $result, $fallback_key) {
        log_message('error', 'plan/103 claim ' . ($result['code'] ?? '?') . ': ' . ($result['message'] ?? ''));

        $map = [
            'already_claimed' => 'team_js_weekly_already',
            'not_qualified'   => 'team_err_not_qualified',
            'user_unavailable'=> 'team_err_user_unavailable',
        ];
        $key = $map[$result['code'] ?? 'error'] ?? $fallback_key;

        return lang($key);
    }

    /**
     * Plan 103: hasil User_model::claim_wage() → pesan idiom aktif.
     * Kode `cycle_not_ready` menyisipkan jumlah hari + tanggal WIB
     * ber-lokalisasi (i18n_date) — bukan prosa `hari lagi` di model.
     *
     * @param  array $result
     * @return string
     */
    private function _wage_message(array $result) {
        $map = [
            'already_claimed' => 'team_js_weekly_already',
            'not_qualified'   => 'team_err_wage_not_qualified',
            'user_unavailable'=> 'team_err_user_unavailable',
            'cycle_not_ready' => 'team_err_wage_cooldown',
            'error'           => 'rental_err_claim_failed',
        ];
        $key = $map[$result['code'] ?? 'error'] ?? 'rental_err_claim_failed';

        if ($result['code'] === 'cycle_not_ready') {
            $days = (int) ($result['days_remaining'] ?? 0);
            $when = i18n_date($result['next_claim_ts'] ?? null);
            return sprintf(lang($key), $days, $when);
        }

        return lang($key);
    }
}
