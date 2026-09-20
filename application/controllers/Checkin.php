<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Checkin — plan/112 (Absensi Harian / Daily Check-in, member-facing).
 *
 * Lapisan HTTP/UX saja (pola Team::claim_wage): POST-only + AJAX-only + sesi +
 * rate limit + pemetaan hasil model ke JSON M9/P7. SELURUH gate, aturan streak,
 * dan transaksi uang hidup di Checkin_model (AGENTS.md: tidak ada SQL di
 * controller).
 *
 * Kontrak respons (canonical envelope + key legacy di root):
 *   - sukses            → 200 {success:true,  data:{reward,streak,transaction_id,
 *                            new_balance,status}, code:'ok', reward, streak, ...}
 *   - penolakan bisnis  → 200 {success:false, code:'already_claimed'|'user_unavailable'}
 *   - fitur OFF         → 403 {success:false, code:'disabled'}   (fail-closed)
 *   - gagal internal    → 500 {success:false, code:'error'}
 *   - sesi habis        → 401 {success:false, code:'unauthenticated'}
 *
 * `status` pada respons sukses = Checkin_model::get_status() segar, sehingga
 * widget dapat memutakhirkan streak/pratinjau tanpa reload halaman.
 */
class Checkin extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Checkin_model');
        $this->load->model('Rate_limit_model');
        $this->load->model('Wallet_model');
        // api + ratelimit TIDAK autoloaded (autoload.php) — loader lokal,
        // pola sama dengan Team (plan/91) & Wallet.
        $this->load->helper('ratelimit');
        $this->load->helper('api');
    }

    /**
     * POST (AJAX): klaim bonus absensi harian.
     */
    public function claim() {
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

        // Rate limit (pola Team::claim_wage / Wallet::process_withdraw):
        // key checkin_claim:{user_id}, 5 hit / 60 detik.
        $rl_key   = 'checkin_claim:' . $user_id;
        $throttle = $this->Rate_limit_model->check($rl_key, 5, 60);
        if ( ! $throttle['allowed']) {
            rate_limit_json_response($throttle);
        }
        $this->Rate_limit_model->hit($rl_key, 60, 5);

        $result = $this->Checkin_model->claim($user_id);

        // Semua key model (kecuali `success`) tetap di root sebagai legacy.
        $legacy = $result;
        unset($legacy['success']);

        $localized = $this->_message($result);
        $legacy['message'] = $localized;

        if ($result['code'] === 'error') {
            api_error($localized, 500, [], 'error', $legacy);
        }

        if ($result['success']) {
            // Saldo segar dari wallet_ledger — bukan users.balance yang basi (C4).
            $new_balance = $this->Wallet_model->get_balance($user_id);
            // Status pasca-klaim: widget memutakhirkan streak + pratinjau tanpa reload.
            $status = $this->Checkin_model->get_status($user_id);

            $legacy['new_balance'] = $new_balance;
            $legacy['status']      = $status;

            api_success(
                [
                    'reward'         => (int) $result['reward'],
                    'streak'         => (int) $result['streak'],
                    'transaction_id' => $result['transaction_id'],
                    'new_balance'    => $new_balance,
                    'status'         => $status,
                ],
                lang('home_checkin_ok_claimed'),
                200,
                $legacy
            );
        }

        // Penolakan bisnis: fitur OFF = 403 (fail-closed eksplisit);
        // sisanya (already_claimed / user_unavailable) = 200 {success:false}
        // dengan key `code` untuk branching JS.
        // plan/112: sertakan `status` segar pada penolakan yang bisa dipicu
        // klik ganda agar widget memutakhirkan dirinya (streak/hari/tombol)
        // tanpa reload — bukan sekadar toast.
        if ($result['code'] === 'already_claimed' || $result['code'] === 'user_unavailable') {
            $legacy['status'] = $this->Checkin_model->get_status($user_id);
        }

        $http = ($result['code'] === 'disabled') ? 403 : 200;
        api_error($localized, $http, [], $result['code'], $legacy);
    }

    /**
     * Kode hasil model → pesan idiom aktif (pola Team::_wage_message).
     * Bila kamus belum termuat (lang() → NULL), jatuh ke prosa model.
     *
     * @param  array $result Hasil Checkin_model::claim()
     * @return string
     */
    private function _message(array $result) {
        $map = [
            // `code` 'ok' WAJIB ada di peta: legacy root `message` menimpa
            // envelope (api_helper menyalin key legacy di atas $body), sehingga
            // tanpa entri ini respons SUKSES akan membawa pesan error generik.
            'ok'               => 'home_checkin_ok_claimed',
            'already_claimed'  => 'home_checkin_err_already',
            'disabled'         => 'home_checkin_err_disabled',
            'user_unavailable' => 'home_checkin_err_unavailable',
            'error'            => 'home_checkin_err_generic',
        ];
        $key  = $map[$result['code'] ?? 'error'] ?? 'home_checkin_err_generic';
        $line = lang($key);

        if (is_string($line) && $line !== '') {
            return $line;
        }

        return (string) ($result['message'] ?? '');
    }
}
