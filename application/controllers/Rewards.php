<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Rewards — plan/121 (LUCKY SPIN WHEEL, member-facing).
 *
 * Lapisan HTTP/UX saja (pola Checkin plan/112 + Team::claim_milestone):
 * POST-only + AJAX-only + sesi + rate limit + pemetaan hasil model ke JSON
 * M9/P7. SELURUH gate, randomisasi berbobot, dan transaksi uang hidup di
 * `Spin_model` (AGENTS.md: tidak ada SQL di controller).
 *
 * Kontrak respons `spin()` (canonical envelope + key legacy di root):
 *   - sukses            → 200 {success:true, data:{slot_index,prize,
 *                            transaction_id,wallet_type,tickets_left,
 *                            new_balance,summary}, code:'ok', …}
 *   - penolakan bisnis  → 200 {success:false, code:'no_tickets'|'user_unavailable'}
 *   - fitur OFF/rusak   → 403 {success:false, code:'disabled'|'not_configured'}
 *   - gagal internal    → 500 {success:false, code:'error'}
 *   - sesi habis        → 401 {success:false, code:'unauthenticated'}
 *
 * `slot_index` HANYA dipakai klien untuk animasi roda — hadiah sudah
 * ditentukan & dikreditkan di server sebelum respons dikirim (zero-trust).
 */
class Rewards extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Spin_model');
        $this->load->model('Rate_limit_model');
        $this->load->model('Wallet_model');
        // api + ratelimit TIDAK autoloaded (autoload.php) — loader lokal,
        // pola sama dengan Checkin (plan/112) & Team (plan/91).
        $this->load->helper('ratelimit');
        $this->load->helper('api');
    }

    /**
     * GET /lucky-spin — halaman roda hadiah.
     */
    public function index() {
        $user_id = $this->session->userdata('user_id');

        $data = [
            'page_title' => lang('spin_page_title'),
            // Ringkasan read-only: tiket, grant/spin count, segmen + peluang,
            // histori 10 spin terakhir. `enabled=false`/`configured=false`
            // → view merender state fail-closed (tanpa roda, tanpa tombol).
            'spin'       => $this->Spin_model->get_summary($user_id),
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('rewards/index', $data);
        $this->load->view('templates/bottom_nav');
    }

    /**
     * POST (AJAX): putar roda — potong 1 tiket + kredit hadiah ke dompet utama.
     */
    public function spin() {
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

        // Rate limit (pola Checkin::claim / Team::claim_milestone):
        // key spin_play:{user_id}, 5 hit / 60 detik.
        $rl_key   = 'spin_play:' . $user_id;
        $throttle = $this->Rate_limit_model->check($rl_key, 5, 60);
        if ( ! $throttle['allowed']) {
            rate_limit_json_response($throttle);
        }
        $this->Rate_limit_model->hit($rl_key, 60, 5);

        $result = $this->Spin_model->spin($user_id);

        // Semua key model (kecuali `success`) tetap di root sebagai legacy.
        $legacy = $result;
        unset($legacy['success']);

        $localized = $this->_message($result);
        $legacy['message'] = $localized;

        if ($result['code'] === 'error') {
            api_error($localized, 500, [], 'error', $legacy);
        }

        if ($result['success']) {
            // Ringkasan segar: tombol/tiket/histori diperbarui tanpa reload.
            $summary = $this->Spin_model->get_summary($user_id);

            $legacy['summary'] = $summary;

            api_success(
                [
                    'slot_index'     => (int) $result['slot_index'],
                    'prize'          => (int) $result['prize'],
                    'transaction_id' => $result['transaction_id'],
                    'wallet_type'    => $result['wallet_type'] ?? Wallet_model::WALLET_MAIN,
                    'tickets_left'   => (int) $result['tickets_left'],
                    'new_balance'    => (int) $result['new_balance'],
                    'summary'        => $summary,
                ],
                lang('spin_ok_message'),
                200,
                $legacy
            );
        }

        // Penolakan bisnis: fitur OFF / konfigurasi rusak = 403 (fail-closed
        // eksplisit); sisanya (no_tickets / user_unavailable) = 200
        // {success:false} dengan `code` untuk branching JS + `summary` segar
        // supaya tombol/tiket tidak perlu reload.
        if (in_array($result['code'], ['no_tickets', 'user_unavailable'], true)) {
            $legacy['summary'] = $this->Spin_model->get_summary($user_id);
        }

        $http = in_array($result['code'], ['disabled', 'not_configured'], true) ? 403 : 200;
        api_error($localized, $http, [], $result['code'], $legacy);
    }

    /**
     * Kode hasil model → pesan idiom aktif (pola Checkin::_message).
     * Bila kamus belum termuat (lang() → NULL), jatuh ke prosa model.
     *
     * @param  array $result Hasil Spin_model::spin()
     * @return string
     */
    private function _message(array $result) {
        $map = [
            // `code` 'ok' WAJIB ada di peta: legacy root `message` menimpa
            // envelope (api_helper menyalin key legacy di atas $body), sehingga
            // tanpa entri ini respons SUKSES akan membawa pesan error generik
            // (pelajaran F18 — plan/119).
            'ok'               => 'spin_ok_message',
            'no_tickets'       => 'spin_err_no_ticket',
            'disabled'         => 'spin_err_disabled',
            'not_configured'   => 'spin_err_not_configured',
            'user_unavailable' => 'spin_err_unavailable',
            'error'            => 'spin_err_generic',
        ];
        $key  = $map[$result['code'] ?? 'error'] ?? 'spin_err_generic';
        $line = lang($key);

        if (is_string($line) && $line !== '') {
            return $line;
        }

        return (string) ($result['message'] ?? '');
    }
}
