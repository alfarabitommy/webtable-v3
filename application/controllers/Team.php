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

        // plan/119: Bonus Pencapaian Tim (pengganti Gaji Mingguan). Seluruh
        // perhitungan tier/state berasal dari model (satu sumber) — view hanya
        // mem-loop; nominal dirender dinamis (tanpa angka di kamus, L6).
        $milestones = $this->User_model->get_milestone_status($user_id);

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
            // plan/119: data kartu Bonus Pencapaian Tim.
            'milestones'      => $milestones,
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
     * POST: Klaim Bonus Pencapaian Tim (plan/119 — pengganti Gaji Mingguan).
     * Hanya lapisan HTTP/UX: method POST, AJAX, sesi, rate limit, validasi
     * input `target`, dan pemetaan kode hasil model ke JSON — SELURUH SQL dan
     * gate bisnis ada di `User_model::claim_milestone()` (AGENTS.md).
     *
     * M9/P7: respons lewat choke-point api_* — envelope {success, message, data}
     * + SEMUA key legacy root yang dibaca frontend (code, message, amount,
     * target, missing, transaction_id, new_balance, milestones).
     * Status: unauthenticated -> 401; `error` -> 500; `disabled` -> 403
     * (fail-closed); penolakan bisnis lain -> 200 {success:false}.
     *
     * F18 (plan/119): pesan FINAL di-assign ke $legacy SEBELUM semua cabang
     * keluar, dan peta `_milestone_message()` memuat `claimed` — sehingga tidak
     * mungkin lagi copy sukses menampilkan teks gagal.
     */
    public function claim_milestone() {
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

        // M8: input integer ketat ^[1-9][0-9]*$ (target = jumlah downline aktif).
        $target_raw = $this->input->post('target', TRUE);
        if ( ! is_string($target_raw) || ! preg_match('/^[1-9][0-9]*$/', $target_raw)) {
            $bad_input = lang('team_ms_js_invalid');
            api_error($bad_input, 200, [], 'invalid_target', [
                'code'    => 'invalid_target',
                'message' => $bad_input,
            ]);
        }
        $target = (int) $target_raw;

        // Rate limit (pola process_withdraw, plan/50 §3.7):
        // key milestone_claim:{user_id}, 5 hit / 60 detik.
        $rl_key   = 'milestone_claim:' . $user_id;
        $throttle = $this->Rate_limit_model->check($rl_key, 5, 60);
        if ( ! $throttle['allowed']) {
            rate_limit_json_response($throttle);
        }
        $this->Rate_limit_model->hit($rl_key, 60, 5);

        $result = $this->User_model->claim_milestone($user_id, $target);

        // Semua key model (kecuali success) tetap di root sebagai legacy —
        // reproduksi gaya body lama {success, code, message, amount, ...}.
        $legacy = $result;
        unset($legacy['success']);

        // plan/103: `code` -> key kamus (idiom aktif); prosa model = diagnostik.
        // Peta LENGKAP termasuk 'claimed' — akar bug F18 ditutup di sini.
        $localized = $this->_milestone_message($result);
        $legacy['message'] = $localized;

        if ($result['success']) {
            // Saldo utama SEGAR dari wallet_ledger (bukan users.balance basi — C4).
            $result['new_balance'] = $this->Wallet_model->get_balance($user_id);
            $legacy['new_balance'] = $result['new_balance'];

            // State milestone segar => kartu /team dapat repaint tanpa reload
            // (parity pola `status` segar Checkin::claim, plan/112/118).
            $milestones = $this->User_model->get_milestone_status($user_id);
            $legacy['milestones'] = $milestones;

            // Notifikasi (parity plan/119) — params: nominal, target.
            $this->Notification_model->insert_keyed(
                $user_id,
                'notif_milestone',
                [number_format((int) $result['amount'], 0, ',', '.'), (string) (int) $result['target']],
                'commission'
            );

            api_success(
                [
                    'target'         => (int) $result['target'],
                    'amount'         => (int) $result['amount'],
                    'new_balance'    => $result['new_balance'],
                    'transaction_id' => $result['transaction_id'],
                    'milestones'     => $milestones,
                ],
                $localized,
                200,
                $legacy
            );
        }

        // `error` -> 500 · `disabled` -> 403 (fail-closed) · sisanya 200.
        // Satu jalur keluar: `$localized` sudah menjadi `$legacy['message']`.
        $status = ($result['code'] === 'error') ? 500 : (($result['code'] === 'disabled') ? 403 : 200);

        if ($status === 200) {
            api_error($localized, 200, [], $result['code'], $legacy);
        }

        api_error($localized, $status, [], $result['code'], $legacy);
    }

    /**
     * POST (AJAX): Ajukan klaim reward promotor — plan/91.
     * Lapisan HTTP/UX saja (pola claim_milestone): POST + AJAX +
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

        // Rate limit (pola claim_milestone, plan/50 §3.7): promoter_claim:{uid}, 5/60 dtk.
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

        // Internal error -> HTTP 500 (parity claim_milestone).
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
     * plan/119 (F18): hasil User_model::claim_milestone() → pesan idiom aktif.
     *
     * Peta LENGKAP — termasuk `claimed` (akar bug F18: peta wage lama tidak
     * memuat `claimed` sehingga pesan sukses jatuh ke prosa "gagal"). Tidak ada
     * fallback diam-diam untuk jalur sukses: setiap kode punya key sendiri.
     *
     * @param  array $result
     * @return string
     */
    private function _milestone_message(array $result) {
        log_message('debug', 'plan/119 milestone claim ' . ($result['code'] ?? '?')
            . ': ' . ($result['message'] ?? ''));

        $map = [
            'claimed'         => 'team_ms_js_success',
            'already_claimed' => 'team_ms_js_already',
            'locked_previous' => 'team_ms_js_locked',
            'not_qualified'   => 'team_ms_js_not_qualified',
            'invalid_target'  => 'team_ms_js_invalid',
            'disabled'        => 'team_ms_js_disabled',
            'user_unavailable'=> 'team_err_user_unavailable',
            'error'           => 'rental_err_claim_failed',
        ];
        $key = $map[$result['code'] ?? 'error'] ?? 'rental_err_claim_failed';

        return lang($key);
    }
}
