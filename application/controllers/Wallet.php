<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Wallet extends MY_Controller {

    // ===================================================================
    //  Plan 103 — peta `code` → key kamus (D2).
    //
    //  Model mengembalikan hasil terstruktur {success, code, message};
    //  `code` adalah OTORITAS presentasi — controller menerjemahkannya di
    //  sini, dan `message` diturunkan menjadi diagnostik log saja (D1/D3).
    //  Konstanta eksplisit (bukan konkatenasi 'prefix_' . $code) agar key
    //  tetap grep-able dan dapat diverifikasi scanner audit plan/103.
    // ===================================================================
    private const DEPOSIT_ERR_KEYS = [
        'invalid_amount' => 'deposit_err_invalid_amount',
        'below_min'      => 'deposit_err_below_min',
        'above_max'      => 'deposit_err_above_max',
        'pending_exists' => 'deposit_err_pending_exists',
        'code_exhausted' => 'deposit_err_code_exhausted',
        'code_conflict'  => 'deposit_err_code_conflict',
        'not_found'      => 'deposit_err_not_found',
        'expired'        => 'deposit_err_expired',
        'not_pending'    => 'deposit_err_not_pending',
        'error'          => 'deposit_err_create_failed',
    ];

    private const WD_ERR_KEYS = [
        'below_min'      => 'wd_err_below_min',
        'above_max'      => 'wd_err_above_max',
        'insufficient'   => 'wd_err_insufficient',
        'pending_exists' => 'wd_err_pending_exists',
        'daily_limit'    => 'wd_err_daily_limit',
        'closed_day'     => 'wd_err_closed_day',
        'closed_time'    => 'wd_err_closed_time',
        // plan/106: hardening di dalam TX — tujuan penarikan tidak lagi valid
        // (binding direset admin / nomor di luar aturan) → pesan yang sama
        // dengan gatekeeper GET/POST "belum mengikat akun e-wallet".
        'no_ewallet'     => 'wd_err_no_ewallet',
        'error'          => 'wd_err_process_failed',
    ];

    // Kode yang pesannya menyisipkan nominal (L6: uang via argumen sprintf).
    private const AMOUNT_KEYS = [
        'deposit_err_below_min', 'deposit_err_above_max',
        'wd_err_below_min', 'wd_err_above_max',
    ];

    public function __construct() {
        parent::__construct();
        $this->load->model('Wallet_model');
        $this->load->model('Rental_model');
        $this->load->model('Rate_limit_model');
        // plan/106: katalog provider e-wallet (sumber tunggal pilihan binding).
        $this->load->model('Ewallet_model');
        $this->load->helper('ratelimit');
    }

    /**
     * Plan 103: terjemahkan hasil model deposit → pesan idiom aktif.
     *
     * @param  array $result Hasil Wallet_model (success/code/message)
     * @return string
     */
    private function _deposit_message(array $result) {
        $key = self::DEPOSIT_ERR_KEYS[$result['code'] ?? 'error'] ?? 'deposit_err_create_failed';

        // Diagnostik: prosa model tidak pernah lagi mencapai UI (D3).
        if (empty($result['success']) && !empty($result['message'])) {
            log_message('error', 'plan/103 wallet deposit ' . ($result['code'] ?? '?') . ': ' . $result['message']);
        }

        if (in_array($key, self::AMOUNT_KEYS, true)) {
            $policy = $this->Wallet_model->get_deposit_policy();
            $amount = ($key === 'deposit_err_below_min') ? $policy['min_amount'] : $policy['max_amount'];
            return sprintf(lang($key), number_format((int) $amount, 0, ',', '.'));
        }

        return lang($key);
    }

    /**
     * Plan 103: terjemahkan hasil model penarikan → pesan idiom aktif.
     *
     * @param  array $result Hasil Wallet_model (success/code/message)
     * @return string
     */
    private function _wd_message(array $result) {
        $key = self::WD_ERR_KEYS[$result['code'] ?? 'error'] ?? 'wd_err_process_failed';

        if (empty($result['success']) && !empty($result['message'])) {
            log_message('error', 'plan/103 wallet withdrawal ' . ($result['code'] ?? '?') . ': ' . $result['message']);
        }

        if ($key === 'wd_err_closed_time') {
            $cfg = $this->Wallet_model->get_financial_config();
            return sprintf(lang($key), $cfg['open_time'], $cfg['close_time']);
        }

        if (in_array($key, self::AMOUNT_KEYS, true)) {
            $cfg    = $this->Wallet_model->get_financial_config();
            $amount = ($key === 'wd_err_below_min') ? $cfg['min_amount'] : $cfg['max_amount'];
            return sprintf(lang($key), number_format((int) $amount, 0, ',', '.'));
        }

        return lang($key);
    }

    /** Rupiah integer → string ribuan (L6: angka tidak pernah masuk kamus). */
    private function _idr($amount) {
        return number_format((int) $amount, 0, ',', '.');
    }


    public function index() {
        $user_id = $this->session->userdata('user_id');

        // M1 (plan/56 §4.3): dynamic deposit fee config utk breakdown UI.
        $fin_cfg = $this->Wallet_model->get_financial_config();
        // plan/102: kebijakan deposit manual QRIS (expiry/min/max).
        $policy  = $this->Wallet_model->get_deposit_policy();

        $this->load->model('Admin_model');

        $data = [
            'page_title'            => lang('wallet_page_title'),
            'balance'               => $this->Wallet_model->get_balance($user_id),
            // plan/102: deposit HIDUP (pending + waiting_approval).
            'pending'               => $this->Wallet_model->get_active_deposits($user_id),
            'pending_withdrawals'   => $this->Wallet_model->get_pending_withdrawals($user_id),
            'has_pending_wd'        => $this->Wallet_model->has_pending_withdrawal($user_id),
            'has_active_rental'     => $this->Rental_model->has_active_rental($user_id),
            'daily_limit_reached'   => $this->Wallet_model->has_reached_daily_wd_limit($user_id),
            'ledger'                => $this->Wallet_model->get_ledger_history($user_id),
            // Deposit fee (dynamic, M1).
            'deposit_fee_enabled'   => (int) $fin_cfg['deposit_fee_enabled'],
            'deposit_fee_type'      => $fin_cfg['deposit_fee_type'],
            'deposit_fee_value'     => $fin_cfg['deposit_fee_value'],
            // plan/102: kebijakan deposit + status konfigurasi QRIS.
            'deposit_policy'        => $policy,
            'qris_configured'       => (string) $this->Admin_model->get_setting('qris_image') !== '',
            'now_ts'                => time(),
        ];

        // plan/102: nominal yang ditampilkan & disalin adalah `total_amount`
        // yang DIBEKUKAN saat invoice dibuat (pokok + [fee] + kode unik).
        // TIDAK dihitung ulang di sini — perubahan setting fee di tengah
        // siklus invoice tidak boleh mengubah nominal yang diverifikasi admin.

        $this->load->view('templates/header', $data);
        $this->load->view('wallet/index', $data);
        $this->load->view('templates/bottom_nav');
    }

    public function topup() {
        $user_id = $this->session->userdata('user_id');

        // plan/102: rate limit pengajuan deposit (parity pola WD 10B) —
        // key per-user; guard deposit-aktif-tunggal tetap otoritas utama.
        $rl_key   = 'deposit:' . $user_id;
        $throttle = $this->Rate_limit_model->check($rl_key, 5, 900);
        if (!$throttle['allowed']) {
            if ($this->input->is_ajax_request()) {
                rate_limit_json_response($throttle);
            }
            $this->session->set_flashdata('error', rate_limit_message($throttle['remaining_seconds']));
            redirect('wallet');
            return;
        }
        $this->Rate_limit_model->hit($rl_key, 900, 5);

        // M8 (plan/74 §2.4): validasi INTEGER ketat — hanya digit positif.
        // Tolak "10000.50", "1e5", negatif, "100,000" & kosong SECARA EKSPLISIT.
        // (preg_replace lama diam-diam menulis ulang "10000.50" → "1000050".)
        $amount_raw = $this->input->post('amount');
        if (!is_string($amount_raw) || !preg_match('/^[1-9][0-9]*$/', $amount_raw)) {
            $this->session->set_flashdata('error', lang('wallet_err_amount_invalid'));
            redirect('wallet');
            return;
        }
        $amount = (int) $amount_raw;

        // plan/102 §3.3/§7.1: fail-closed — tanpa gambar QRIS terkonfigurasi,
        // member tidak boleh diarahkan transfer ke tujuan yang tidak dikenal.
        $this->load->model('Admin_model');
        if ((string) $this->Admin_model->get_setting('qris_image') === '') {
            $this->session->set_flashdata('error', lang('deposit_err_qris_unconfigured'));
            redirect('wallet');
            return;
        }

        $result = $this->Wallet_model->create_deposit($user_id, $amount);

        if ($result['success']) {
            // plan/103: kalimat + nominal lewat satu key (uang sebagai argumen,
            // L6) — menggantikan 3 konkatenasi literal.
            $this->session->set_flashdata('success', sprintf(
                lang('deposit_ok_created'),
                $result['invoice_number'],
                $this->_idr($result['total_amount']),
                (int) $result['unique_code']
            ));
            // Langsung ke halaman pembayaran — member tidak perlu mencari invoice.
            redirect('wallet/pay/' . $result['invoice_number']);
            return;
        }

        $this->session->set_flashdata('error', $this->_deposit_message($result));
        redirect('wallet');
    }

    /**
     * plan/102: halaman pembayaran manual QRIS (GET) — QR, nominal TEPAT
     * (kode unik ditonjolkan), countdown, dan tombol "Saya Sudah Transfer".
     */
    public function pay($invoice_number = '') {
        $user_id  = $this->session->userdata('user_id');
        $deposit  = $this->_owned_deposit($invoice_number);

        if ($deposit === null) {
            return; // _owned_deposit sudah menampilkan 404/403 + log
        }

        $this->load->model('Admin_model');

        $data = [
            'page_title'    => lang('wallet_pay_title'),
            'deposit'       => $deposit,
            'now_ts'        => time(),
            'expires_ts'    => ($deposit->expires_at !== null) ? strtotime($deposit->expires_at) : null,
            'qris_image'    => (string) $this->Admin_model->get_setting('qris_image'),
            'qris_merchant' => (string) $this->Admin_model->get_setting('qris_merchant_name'),
            'qris_notes'    => (string) $this->Admin_model->get_setting('qris_payment_instructions'),
            'wa_number'     => $this->Admin_model->get_setting('wa_number') ?: '628000000000',
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('wallet/pay', $data);
        $this->load->view('templates/bottom_nav');
    }

    /**
     * plan/102: konfirmasi "Saya Sudah Transfer" (POST) — pending →
     * waiting_approval. Tidak ada unggahan bukti; admin memverifikasi mutasi
     * bank/QRIS secara manual terhadap `total_amount`.
     */
    public function confirm_payment($invoice_number = '') {
        // M4 (plan/62 S1): POST-only — mutasi status tidak boleh via GET.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $user_id = $this->session->userdata('user_id');

        $rl_key   = 'deposit_confirm:' . $user_id;
        $throttle = $this->Rate_limit_model->check($rl_key, 10, 900);
        if (!$throttle['allowed']) {
            $this->session->set_flashdata('error', rate_limit_message($throttle['remaining_seconds']));
            redirect('wallet/pay/' . $invoice_number);
            return;
        }
        $this->Rate_limit_model->hit($rl_key, 900, 10);

        if ($this->_owned_deposit($invoice_number) === null) {
            return;
        }

        $result = $this->Wallet_model->confirm_deposit($invoice_number, $user_id);

        if ($result['success']) {
            $this->session->set_flashdata('success', lang('deposit_ok_confirmed'));
        } else {
            $this->session->set_flashdata('error', $this->_deposit_message($result));
        }

        redirect('wallet/pay/' . $invoice_number);
    }

    /**
     * plan/102: ambil deposit milik session user; tampilkan 404/403 + log bila
     * tidak valid (pola kepemilikan C1 plan/38 / C7 plan/42).
     *
     * @return object|null Baris deposit, atau null bila request sudah ditolak.
     */
    private function _owned_deposit($invoice_number) {
        $user_id = $this->session->userdata('user_id');
        $deposit = $this->Wallet_model->get_deposit_by_invoice($invoice_number);

        if (!$deposit) {
            show_404();
            return null;
        }

        if ((int) $deposit->user_id !== (int) $user_id) {
            log_message('error', 'plan/102 ownership violation: user ' . $user_id
                . ' attempted to access deposit ' . $invoice_number . ' owned by user ' . $deposit->user_id);
            // plan/103: halaman error mengikuti idiom aktif (dulu literal ID).
            show_error(lang('common_err_forbidden_owner'), 403);
            return null;
        }

        return $deposit;
    }

    public function simulate_payment($invoice_number) {
        // C1 (plan 38): production hard-gate — fail-closed. Endpoint TIDAK ADA
        // di production; tidak pernah memproses apapun.
        if (ENVIRONMENT === 'production') {
            show_404();
            return;
        }

        // C1 (plan 38): POST-only — GET mutation dihapus (policy 10B).
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $user_id = $this->session->userdata('user_id');

        // C1 (plan 38): validasi kepemilikan — invoice harus milik session user.
        $deposit = $this->Wallet_model->get_deposit_by_invoice($invoice_number);
        if (!$deposit) {
            $this->session->set_flashdata('error', lang('deposit_err_not_found'));
            redirect('wallet');
            return;
        }
        if ((int)$deposit->user_id !== (int)$user_id) {
            log_message('error', 'C1 ownership violation: user ' . $user_id . ' attempted simulate on invoice ' . $invoice_number . ' owned by user ' . $deposit->user_id);
            show_error(lang('common_err_forbidden_owner'), 403);
            return;
        }

        $result = $this->Wallet_model->approve_deposit_simulator($invoice_number, $user_id);

        if ($result) {
            $this->session->set_flashdata('success', lang('deposit_ok_simulated'));
        } else {
            $this->session->set_flashdata('error', lang('deposit_err_simulate_failed'));
        }

        redirect('wallet');
    }

    // ===== WITHDRAWAL (GET: show form) =====

    public function withdraw() {
        $user_id = $this->session->userdata('user_id');

        // Gatekeeper 1: pending withdrawal
        if ($this->Wallet_model->has_pending_withdrawal($user_id)) {
            $this->session->set_flashdata('error', lang('wd_err_pending_exists'));
            redirect('wallet');
            return;
        }

        // Gatekeeper 2: active rental required
        if (!$this->Rental_model->has_active_rental($user_id)) {
            $this->session->set_flashdata('error', lang('wd_err_no_active_rental'));
            redirect('wallet');
            return;
        }

        // Gatekeeper 3: daily limit
        if ($this->Wallet_model->has_reached_daily_wd_limit($user_id)) {
            $this->session->set_flashdata('error', lang('wd_err_daily_limit_done'));
            redirect('wallet');
            return;
        }

        // Gatekeeper 4 (plan/106): akun e-wallet harus terikat DAN providernya
        // masih aktif. Provider nonaktif = penarikan diblokir (keputusan D5):
        // immutability binding tetap utuh, hanya reset admin (atau reaktivasi
        // provider) yang membuka jalur ini.
        $ewallet = $this->Wallet_model->get_user_ewallet($user_id);
        if (empty($ewallet)) {
            $this->session->set_flashdata('error', lang('wd_err_no_ewallet_cta'));
            redirect('wallet/bind_bank');
            return;
        }

        $provider = $this->_resolve_active_provider($ewallet);
        if ($provider === null) {
            $this->session->set_flashdata('error', lang('wd_err_ewallet_inactive'));
            redirect('wallet/bind_bank');
            return;
        }

        // M1 (plan/56 §3-4.2): status operasional + config dinamis untuk
        // preview fee real-time & disabled state. TIDAK redirect saat tutup:
        // halaman tetap dirender dengan notice informatif (server tetap
        // otoritas — POST/process_withdraw + TX model menolak tegas).
        $wd_cfg  = $this->Wallet_model->get_financial_config();
        $wd_op   = $this->Wallet_model->withdrawal_operational_status();

        $data = [
            'page_title'   => lang('wd_page_title'),
            'balance'      => $this->Wallet_model->get_balance($user_id),
            // plan/106: binding e-wallet aktif (dulu `bank`).
            'ewallet'      => $ewallet,
            // Subset config untuk JS preview (json_encode di view).
            'wd_config'    => [
                'operational_days' => $wd_cfg['operational_days'],
                'open_time'        => $wd_cfg['open_time'],
                'close_time'       => $wd_cfg['close_time'],
                'fixed_fee'        => (int) $wd_cfg['fixed_fee'],
                'tiers'            => $wd_cfg['tiers'],
                'min_amount'       => (int) $wd_cfg['min_amount'],
                'max_amount'       => (int) $wd_cfg['max_amount'],
            ],
            'wd_open'      => $wd_op['open'],
            'wd_code'      => $wd_op['code'], // 'open' | 'closed_day' | 'closed_time'
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('wallet/withdraw', $data);
        $this->load->view('templates/bottom_nav');
    }

    // ===== WITHDRAWAL (POST: process) =====

    public function process_withdraw() {
        $user_id = $this->session->userdata('user_id');

        // ─── RATE LIMIT (10B): rate limit pengajuan WD — key withdraw:{user_id}.
        // Setiap submission dihitung; Gatekeeper existing (single-pending-WD,
        // daily limit) tetap menjadi otoritas utama.
        $rl_key   = 'withdraw:' . $user_id;
        $throttle = $this->Rate_limit_model->check($rl_key, 5, 900);
        if (!$throttle['allowed']) {
            if ($this->input->is_ajax_request()) {
                rate_limit_json_response($throttle);
            }
            $this->session->set_flashdata('error', rate_limit_message($throttle['remaining_seconds']));
            redirect('wallet/withdraw');
        }
        $this->Rate_limit_model->hit($rl_key, 900, 5);

        // Same gatekeepers as withdraw() GET
        if ($this->Wallet_model->has_pending_withdrawal($user_id)) {
            $this->session->set_flashdata('error', lang('wd_err_pending_exists'));
            redirect('wallet');
            return;
        }

        if (!$this->Rental_model->has_active_rental($user_id)) {
            $this->session->set_flashdata('error', lang('wd_err_no_active_rental'));
            redirect('wallet');
            return;
        }

        if ($this->Wallet_model->has_reached_daily_wd_limit($user_id)) {
            $this->session->set_flashdata('error', lang('wd_err_daily_limit'));
            redirect('wallet');
            return;
        }

        // Fetch binding e-wallet server-side — zero client input (plan/106).
        // `$ewallet->id` adalah bank_account_id: FK `fk_withdrawals_bank`.
        $ewallet = $this->Wallet_model->get_user_ewallet($user_id);
        if (empty($ewallet)) {
            $this->session->set_flashdata('error', lang('wd_err_no_ewallet'));
            redirect('wallet/bind_bank');
            return;
        }

        if ($this->_resolve_active_provider($ewallet) === null) {
            $this->session->set_flashdata('error', lang('wd_err_ewallet_inactive'));
            redirect('wallet/bind_bank');
            return;
        }

        // M8 (plan/74 §2.4): validasi INTEGER ketat — hanya digit positif.
        // Tolak "10000.50" / "1e5" / negatif / "100,000" / kosong SECARA
        // EKSPLISIT (preg_replace lama diam-diam mengubah "10000.50" →
        // "1000050" dan "1e5" → "15"). Tidak ada penulisan ulang input.
        $amount_raw = $this->input->post('amount');
        if (!is_string($amount_raw) || !preg_match('/^[1-9][0-9]*$/', $amount_raw)) {
            $this->session->set_flashdata('error', lang('wallet_err_amount_invalid_wd'));
            redirect('wallet/withdraw');
            return;
        }
        $amount = (int) $amount_raw;

        // M1 (plan/56 §3): config dinamis + gerbang operasional (UX mirror —
        // otoritas finansial tetap di Wallet_model::create_withdrawal TX).
        $wd_cfg = $this->Wallet_model->get_financial_config();
        $wd_op  = $this->Wallet_model->withdrawal_operational_status();

        if (!$wd_op['open']) {
            // plan/103: satu key per kode, nominal/parameter waktu via sprintf.
            $message = ($wd_op['code'] === 'closed_day')
                ? lang('wd_err_closed_day')
                : sprintf(lang('wd_err_closed_time'), $wd_cfg['open_time'], $wd_cfg['close_time']);
            $this->session->set_flashdata('error', $message);
            redirect('wallet/withdraw');
            return;
        }

        $min_wd = (int) $wd_cfg['min_amount'];
        $max_wd = (int) $wd_cfg['max_amount'];

        // Pre-check saldo: HANYA fast UX feedback. Otoritas finansial
        // anti-race ada di Wallet_model::create_withdrawal (kunci anchor
        // users + saldo segar di dalam TX) — audit C5, plan/48.
        $user_balance = $this->Wallet_model->get_balance($user_id);

        if ($amount < $min_wd) {
            $this->session->set_flashdata('error', sprintf(lang('wd_err_below_min'), $this->_idr($min_wd)));
            redirect('wallet/withdraw');
            return;
        }

        if ($amount > $max_wd) {
            $this->session->set_flashdata('error', sprintf(lang('wd_err_above_max'), $this->_idr($max_wd)));
            redirect('wallet/withdraw');
            return;
        }

        if ($user_balance < $amount) {
            $this->session->set_flashdata('error', lang('wd_err_insufficient'));
            redirect('wallet/withdraw');
            return;
        }

        $result = $this->Wallet_model->create_withdrawal($user_id, $amount, $ewallet->id);

        // C5 (plan/48 §3.5): map hasil terstruktur model → flashdata + redirect.
        if ($result['success']) {
            $this->session->set_flashdata('success', lang('wd_ok_submitted'));
            redirect('wallet');
            return;
        }

        $this->session->set_flashdata('error', $this->_wd_message($result));
        // Kembali ke form untuk kode yang konteksnya halaman penarikan.
        $form_codes = ['insufficient', 'below_min', 'above_max', 'closed_day', 'closed_time'];
        redirect(in_array($result['code'], $form_codes, true) ? 'wallet/withdraw' : 'wallet');
    }

    public function simulate_wd_approve($wd_number) {
        // C7 (plan 42) 4A: production hard-gate — fail-closed. Di production
        // endpoint ini TIDAK ADA (404) — tidak pernah memproses apapun.
        if (ENVIRONMENT === 'production') {
            show_404();
            return;
        }

        // C7 4D: POST-only — GET mutation dihapus (policy 10B).
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $user_id = $this->session->userdata('user_id');

        // C7 4C: validasi kepemilikan — WD harus milik session user.
        $wd = $this->Wallet_model->get_withdrawal_by_wd_number($wd_number);
        if (!$wd) {
            $this->session->set_flashdata('error', lang('wd_err_not_found'));
            redirect('wallet');
            return;
        }
        if ((int)$wd->user_id !== (int)$user_id) {
            log_message('error', 'C7 ownership violation: user ' . $user_id . ' attempted simulate_wd_approve on ' . $wd_number . ' owned by user ' . $wd->user_id);
            show_error(lang('common_err_forbidden_owner'), 403);
            return;
        }

        $result = $this->Wallet_model->approve_withdrawal_simulator($wd_number, $user_id);

        if ($result) {
            $this->session->set_flashdata('success', lang('wd_ok_simulated'));
        } else {
            $this->session->set_flashdata('error', lang('wd_err_simulate_failed'));
        }

        redirect('wallet');
    }

    // ===================================================================
    //  Plan 106 — E-WALLET BINDING (IMMUTABLE)
    //
    //  URL tetap `/wallet/bind_bank` (route eksisting) dan tabel tetap
    //  `bank_accounts` (kompatibilitas FK), tetapi isinya kini binding
    //  e-wallet:
    //    bank_name      ← nama provider dari `ewallet_providers` (aktif);
    //    account_number ← nomor HP e-wallet kanonik (helper ewallet_helper);
    //    account_holder ← nama pemilik akun.
    //  Provider TIDAK boleh datang dari klien sebagai nama bebas: yang
    //  dikirim klien adalah `provider_id`, lalu di-resolve ke katalog aktif.
    // ===================================================================

    /**
     * Resolusi provider AKTIF untuk sebuah binding.
     *
     * @param  object $ewallet Baris binding aktif (`bank_accounts`)
     * @return object|null     null bila provider hilang dari katalog ATAU nonaktif
     */
    private function _resolve_active_provider($ewallet) {
        if (empty($ewallet) || empty($ewallet->bank_name)) {
            return null;
        }

        $provider = $this->Ewallet_model->get_provider_by_name($ewallet->bank_name);

        if (!$provider || (int) $provider->is_active !== 1) {
            return null;
        }

        return $provider;
    }

    public function bind_bank() {
        $user_id = $this->session->userdata('user_id');
        $existing_ewallet = $this->Wallet_model->get_user_ewallet($user_id);

        // POST: Backend Bypass Protection
        if ($this->input->post()) {
            // 1. Immutability: satu binding aktif per user (guard cepat;
            //    otoritas anti-race ada di Wallet_model::bind_user_ewallet).
            if ($existing_ewallet) {
                $this->session->set_flashdata('error', lang('bb_err_already_bound'));
                redirect('wallet/bind_bank');
                return;
            }

            // 2. Provider: id integer positif + WAJIB ada dan AKTIF di katalog.
            //    Nama provider tidak pernah dipercaya dari klien.
            $provider_id = $this->input->post('provider_id');
            if (!is_string($provider_id) || !preg_match('/^[1-9][0-9]*$/', $provider_id)) {
                $this->session->set_flashdata('error', lang('bb_err_provider_invalid'));
                redirect('wallet/bind_bank');
                return;
            }

            $provider = $this->Ewallet_model->get_active_provider((int) $provider_id);
            if (!$provider) {
                $this->session->set_flashdata('error', lang('bb_err_provider_invalid'));
                redirect('wallet/bind_bank');
                return;
            }

            // 3. Kelengkapan field (parity pesan lama).
            $phone_raw      = $this->input->post('ewallet_phone');
            $account_holder = trim((string) $this->input->post('account_holder'));

            if (empty($phone_raw) || $account_holder === '') {
                $this->session->set_flashdata('error', lang('bb_err_required_fields'));
                redirect('wallet/bind_bank');
                return;
            }

            // 4. Nomor HP e-wallet: numerik, awalan 08, 10–13 digit
            //    (normalisasi + validasi satu sumber: ewallet_helper).
            $phone = ewallet_phone_validate($phone_raw);
            if ($phone === null) {
                $this->session->set_flashdata('error', lang('bb_err_phone'));
                redirect('wallet/bind_bank');
                return;
            }

            // 5. Panjang nama pemilik (kolom VARCHAR(100)).
            if (mb_strlen($account_holder) > 100) {
                $this->session->set_flashdata('error', lang('bb_err_holder_too_long'));
                redirect('wallet/bind_bank');
                return;
            }

            // 6. Simpan di dalam TX + row-level lock (anti double-submit).
            $result = $this->Wallet_model->bind_user_ewallet(
                $user_id,
                (string) $provider->name,
                $phone,
                $account_holder
            );

            if (!empty($result['ok'])) {
                $this->session->set_flashdata('success', lang('bb_ok_bound'));
            } elseif (($result['code'] ?? '') === 'already_bound') {
                $this->session->set_flashdata('error', lang('bb_err_already_bound'));
            } else {
                $this->session->set_flashdata('error', lang('bb_err_save_failed'));
            }

            redirect('wallet/bind_bank');
            return;
        }

        // GET: Render view — provider selector HANYA dari katalog aktif;
        // daftar kosong = state fail-closed (kartu info, form tidak dirender).
        $data = [
            'page_title'       => lang('bind_page_title'),
            'existing_ewallet' => $existing_ewallet,
            'providers'        => $this->Ewallet_model->get_active_providers(),
            // Status provider binding aktif (untuk notice nonaktif di State B).
            'ewallet_provider' => $existing_ewallet
                ? $this->Ewallet_model->get_provider_by_name($existing_ewallet->bank_name)
                : null,
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('wallet/bank_bind', $data);
        $this->load->view('templates/bottom_nav');
    }
}
