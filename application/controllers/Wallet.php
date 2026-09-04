<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Wallet extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Wallet_model');
        $this->load->model('Rental_model');
        $this->load->model('Rate_limit_model');
        $this->load->helper('ratelimit');
    }

    public function index() {
        $user_id = $this->session->userdata('user_id');

        // M1 (plan/56 §4.3): dynamic deposit fee config utk breakdown UI.
        $fin_cfg = $this->Wallet_model->get_financial_config();

        $data = [
            'page_title'            => 'Wallet',
            'balance'               => $this->Wallet_model->get_balance($user_id),
            'pending'               => $this->Wallet_model->get_pending_deposits($user_id),
            'pending_withdrawals'   => $this->Wallet_model->get_pending_withdrawals($user_id),
            'has_pending_wd'        => $this->Wallet_model->has_pending_withdrawal($user_id),
            'has_active_rental'     => $this->Rental_model->has_active_rental($user_id),
            'daily_limit_reached'   => $this->Wallet_model->has_reached_daily_wd_limit($user_id),
            'ledger'                => $this->Wallet_model->get_ledger_history($user_id),
            // Deposit fee (dynamic, M1).
            'deposit_fee_enabled'   => (int) $fin_cfg['deposit_fee_enabled'],
            'deposit_fee_type'      => $fin_cfg['deposit_fee_type'],
            'deposit_fee_value'     => $fin_cfg['deposit_fee_value'],
        ];

        // Enrich pending deposit invoices with the payable total
        // (pokok + biaya deposit) for display. M8: jumlah integer murni.
        foreach ($data['pending'] as $inv) {
            $inv->deposit_fee   = $this->Wallet_model->calculate_deposit_fee((int) $inv->amount);
            $inv->total_payable = (int) $inv->amount + $inv->deposit_fee;
        }

        $this->load->view('templates/header', $data);
        $this->load->view('wallet/index', $data);
        $this->load->view('templates/bottom_nav');
    }

    public function topup() {
        $user_id = $this->session->userdata('user_id');

        // M8 (plan/74 §2.4): validasi INTEGER ketat — hanya digit positif.
        // Tolak "10000.50", "1e5", negatif, "100,000" & kosong SECARA EKSPLISIT.
        // (preg_replace lama diam-diam menulis ulang "10000.50" → "1000050".)
        $amount_raw = $this->input->post('amount');
        if (!is_string($amount_raw) || !preg_match('/^[1-9][0-9]*$/', $amount_raw)) {
            $this->session->set_flashdata('error', 'Nominal tidak valid.');
            redirect('wallet');
            return;
        }
        $amount = (int) $amount_raw;

        // M4 (plan/62 H2): hasil terstruktur {success, invoice_number} —
        // jangan pernah flash "sukses" saat insert invoice gagal
        // (P2 audit lama: hasil create_deposit tidak pernah dicek).
        $result = $this->Wallet_model->create_deposit($user_id, $amount);
        if ($result['success']) {
            $this->session->set_flashdata('success', 'Invoice ' . $result['invoice_number'] . ' berhasil dibuat. Silakan selesaikan pembayaran.');
        } else {
            $this->session->set_flashdata('error', 'Gagal membuat invoice. Silakan coba lagi.');
        }

        redirect('wallet');
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
            $this->session->set_flashdata('error', 'Invoice tidak ditemukan.');
            redirect('wallet');
            return;
        }
        if ((int)$deposit->user_id !== (int)$user_id) {
            log_message('error', 'C1 ownership violation: user ' . $user_id . ' attempted simulate on invoice ' . $invoice_number . ' owned by user ' . $deposit->user_id);
            show_error('Akses ditolak: invoice milik pengguna lain.', 403);
            return;
        }

        $result = $this->Wallet_model->approve_deposit_simulator($invoice_number, $user_id);

        if ($result) {
            $this->session->set_flashdata('success', 'Pembayaran berhasil disimulasikan! Dana sudah masuk.');
        } else {
            $this->session->set_flashdata('error', 'Gagal memproses simulasi: invoice sudah diproses atau tidak valid.');
        }

        redirect('wallet');
    }

    // ===== WITHDRAWAL (GET: show form) =====

    public function withdraw() {
        $user_id = $this->session->userdata('user_id');

        // Gatekeeper 1: pending withdrawal
        if ($this->Wallet_model->has_pending_withdrawal($user_id)) {
            $this->session->set_flashdata('error', 'Anda masih memiliki penarikan yang sedang diproses.');
            redirect('wallet');
            return;
        }

        // Gatekeeper 2: active rental required
        if (!$this->Rental_model->has_active_rental($user_id)) {
            $this->session->set_flashdata('error', 'Anda harus memiliki minimal 1 produk sewa aktif untuk melakukan penarikan.');
            redirect('wallet');
            return;
        }

        // Gatekeeper 3: daily limit
        if ($this->Wallet_model->has_reached_daily_wd_limit($user_id)) {
            $this->session->set_flashdata('error', 'Batas penarikan harian tercapai. Anda sudah melakukan penarikan hari ini.');
            redirect('wallet');
            return;
        }

        // Gatekeeper 4: bank must be bound
        $bank = $this->Wallet_model->get_user_bank($user_id);
        if (empty($bank)) {
            $this->session->set_flashdata('error', 'Anda belum mengikat rekening bank. Silakan ikat rekening terlebih dahulu.');
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
            'page_title'   => 'Penarikan Dana',
            'balance'      => $this->Wallet_model->get_balance($user_id),
            'bank'         => $bank,
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
            $this->session->set_flashdata('error', 'Anda masih memiliki penarikan yang sedang diproses.');
            redirect('wallet');
            return;
        }

        if (!$this->Rental_model->has_active_rental($user_id)) {
            $this->session->set_flashdata('error', 'Anda harus memiliki minimal 1 produk sewa aktif untuk melakukan penarikan.');
            redirect('wallet');
            return;
        }

        if ($this->Wallet_model->has_reached_daily_wd_limit($user_id)) {
            $this->session->set_flashdata('error', 'Batas penarikan harian tercapai.');
            redirect('wallet');
            return;
        }

        // Fetch bank_account_id server-side — zero client bank input
        $bank = $this->Wallet_model->get_user_bank($user_id);
        if (empty($bank)) {
            $this->session->set_flashdata('error', 'Anda belum mengikat rekening bank.');
            redirect('wallet/bind_bank');
            return;
        }

        // M8 (plan/74 §2.4): validasi INTEGER ketat — hanya digit positif.
        // Tolak "10000.50" / "1e5" / negatif / "100,000" / kosong SECARA
        // EKSPLISIT (preg_replace lama diam-diam mengubah "10000.50" →
        // "1000050" dan "1e5" → "15"). Tidak ada penulisan ulang input.
        $amount_raw = $this->input->post('amount');
        if (!is_string($amount_raw) || !preg_match('/^[1-9][0-9]*$/', $amount_raw)) {
            $this->session->set_flashdata('error', 'Nominal penarikan tidak valid.');
            redirect('wallet/withdraw');
            return;
        }
        $amount = (int) $amount_raw;

        // M1 (plan/56 §3): config dinamis + gerbang operasional (UX mirror —
        // otoritas finansial tetap di Wallet_model::create_withdrawal TX).
        $wd_cfg = $this->Wallet_model->get_financial_config();
        $wd_op  = $this->Wallet_model->withdrawal_operational_status();

        if (!$wd_op['open']) {
            $message = ($wd_op['code'] === 'closed_day')
                ? 'Hari ini bukan hari operasional penarikan.'
                : 'Penarikan hanya dapat diajukan pada pukul ' . $wd_cfg['open_time'] . '–' . $wd_cfg['close_time'] . ' WIB.';
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
            $this->session->set_flashdata('error', 'Minimal penarikan adalah Rp ' . number_format($min_wd, 0, ',', '.'));
            redirect('wallet/withdraw');
            return;
        }

        if ($amount > $max_wd) {
            $this->session->set_flashdata('error', 'Maksimal penarikan adalah Rp ' . number_format($max_wd, 0, ',', '.'));
            redirect('wallet/withdraw');
            return;
        }

        if ($user_balance < $amount) {
            $this->session->set_flashdata('error', 'Saldo tidak mencukupi untuk penarikan');
            redirect('wallet/withdraw');
            return;
        }

        $result = $this->Wallet_model->create_withdrawal($user_id, $amount, $bank->id);

        // C5 (plan/48 §3.5): map hasil terstruktur model → flashdata + redirect.
        if ($result['success']) {
            $this->session->set_flashdata('success', 'Permintaan penarikan berhasil diajukan');
            redirect('wallet');
            return;
        }

        $this->session->set_flashdata('error', $result['message']);
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
            $this->session->set_flashdata('error', 'Penarikan tidak ditemukan.');
            redirect('wallet');
            return;
        }
        if ((int)$wd->user_id !== (int)$user_id) {
            log_message('error', 'C7 ownership violation: user ' . $user_id . ' attempted simulate_wd_approve on ' . $wd_number . ' owned by user ' . $wd->user_id);
            show_error('Akses ditolak: penarikan milik pengguna lain.', 403);
            return;
        }

        $result = $this->Wallet_model->approve_withdrawal_simulator($wd_number, $user_id);

        if ($result) {
            $this->session->set_flashdata('success', 'Simulasi: Penarikan berhasil disetujui.');
        } else {
            $this->session->set_flashdata('error', 'Gagal memproses simulasi: penarikan sudah diproses atau tidak valid.');
        }

        redirect('wallet');
    }

    // ===== BANK BINDING (IMMUTABLE) =====

    public function bind_bank() {
        $user_id = $this->session->userdata('user_id');
        $existing_bank = $this->Wallet_model->get_user_bank($user_id);

        // POST: Backend Bypass Protection
        if ($this->input->post()) {
            if ($existing_bank) {
                $this->session->set_flashdata('error', 'Rekening sudah terikat dan tidak dapat diubah.');
                redirect('wallet/bind_bank');
                return;
            }

            $bank_name      = $this->input->post('bank_name');
            $account_number = $this->input->post('account_number');
            $account_holder = $this->input->post('account_holder');

            // Validation
            if (empty($bank_name) || empty($account_number) || empty($account_holder)) {
                $this->session->set_flashdata('error', 'Semua field wajib diisi.');
                redirect('wallet/bind_bank');
                return;
            }

            if (!preg_match('/^[0-9]+$/', $account_number) || strlen($account_number) < 8) {
                $this->session->set_flashdata('error', 'Nomor rekening harus numeric minimal 8 digit.');
                redirect('wallet/bind_bank');
                return;
            }

            $data = [
                'user_id'        => $user_id,
                'bank_name'      => $bank_name,
                'account_number' => $account_number,
                'account_holder' => $account_holder,
                'is_primary'     => 1,
            ];

            if ($this->Wallet_model->insert_bank($data)) {
                $this->session->set_flashdata('success', 'Rekening berhasil diikat.');
            } else {
                $this->session->set_flashdata('error', 'Gagal menyimpan data rekening.');
            }

            redirect('wallet/bind_bank');
            return;
        }

        // GET: Render view
        $data = [
            'page_title'    => 'Bind Rekening',
            'existing_bank' => $existing_bank,
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('wallet/bank_bind', $data);
        $this->load->view('templates/bottom_nav');
    }
}
