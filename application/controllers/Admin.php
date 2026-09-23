<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin extends CI_Controller {

    public function __construct() {
        parent::__construct();

        $this->load->database();
        // M2 (plan/58 §3 Phase 2): pin WIB sesi MySQL sebagai statement DB
        // pertama pada entry point admin (Admin extends CI_Controller).
        $this->db->query("SET time_zone = '+07:00'");
        $this->load->library('session');
        $this->load->helper('url');
        $this->load->library('pagination');
        // M9/P7 (plan/76 Batch D): choke-point JSON helper (Admin extends
        // CI_Controller — helper, bukan method MY_Controller).
        $this->load->helper('api');

        if (!$this->session->userdata('admin_id')) {
            redirect('control-panel');
        }

        // Plan 94 (F2): SSR awal alert center — COUNT index-saja per queue
        // di-inject sebagai var global (dipakai sidebar/topbar/footer agar
        // badge & bell terisi SEBELUM poll pertama; A4 — pola sama
        // global_balance di MY_Controller). Tanpa ini Admin_model tetap
        // bisa di-load per-method di bawah (load->model idempotent).
        $this->load->model('Admin_model');

        // plan/102: sweep expiry GLOBAL sebelum menghitung alert — deposit
        // pending yang sudah lewat jendela bayar ditutup (status expired +
        // reservasi kode dilepas) supaya badge antrean tidak menghitung baris
        // basi. Satu UPDATE ber-index (idx_status_expires), autocommit,
        // idempotent; pola lazy M3 tanpa cron.
        $this->load->model('Wallet_model');
        $this->Wallet_model->expire_stale_deposits();

        $this->load->vars(array('global_admin_alerts' => $this->Admin_model->get_alert_counts()));
    }

    /**
     * GET /admin/alerts/poll — Plan 94 (F2).
     *
     * Polling ringan alert center: COUNT pending per queue (read-only,
     * GET, admin-only — guard constructor). Tanpa audit/rate-limit (bukan
     * mutasi; A1). Respons via api_success() (M9/P7): data.* kanonik +
     * key legacy di root ({pending_deposits, pending_withdrawals,
     * pending_promoter_claims, total_urgent}) untuk kontrak spek/legacy.
     */
    public function alerts_poll()
    {
        $this->load->model('Admin_model');
        $counts = $this->Admin_model->get_alert_counts();

        // Poll GET tidak boleh di-cache browser (angka selalu segar).
        $this->output->set_header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        api_success($counts, 'ok', 200, $counts);
    }

    // ─── PHONE NORMALIZER ──────────────────────────────
    private function _normalize_phone($raw) {
        $digits = preg_replace('/\D/', '', trim($raw));
        if (strpos($digits, '62') === 0 && strlen($digits) > 2) {
            $digits = '0' . substr($digits, 2);
        }
        if ($digits !== '' && $digits[0] !== '0') {
            $digits = '0' . $digits;
        }
        return $digits;
    }

    // ─── AUDIT CONTEXT (Phase 10A) ─────────────────────
    // Builds the $audit array passed to Admin_model TX methods.
    // (Direct calls to Audit_model::log_admin_action() are used where the
    // transaction lives in this controller — see approve_deposit et al.)

    private function _audit_ctx($user_id, $action, $details = null) {
        return [
            'admin_id'   => (int) $this->session->userdata('admin_id'),
            'user_id'    => $user_id,
            'action'     => $action,
            'details'    => $details,
            'ip_address' => $this->input->ip_address(),
        ];
    }

    public function index() {
        $this->load->model('Admin_model');

        // plan/102: antrean deposit (pending + waiting_approval) kini disediakan
        // model — invariant "semua akses DB di model" (AGENTS.md) tetap terjaga.
        // Urutan: waiting_approval (member sudah menyatakan transfer) lebih dulu.
        $pending_deposits = $this->Admin_model->get_deposit_queue();

        // plan/109: antrean penarikan juga disediakan model (parity get_deposit_queue)
        // — SQL inline di controller dihapus, dan baris di-dekorasi nominal
        // gross/fee/net (net = yang WAJIB ditransfer admin).
        $pending_withdrawals = $this->Admin_model->get_withdrawal_queue();

        // Phase 9A: Treasury Health + Circuit Breaker
        $treasury = $this->Admin_model->get_treasury_stats();

        // Phase 9A: Analytics Stats
        $analytics_stats = [
            'active_users'       => $this->Admin_model->get_active_users_count(),
            'rental_volume'      => $this->Admin_model->get_rental_volume(),
            'withdrawal_volume'  => $this->Admin_model->get_withdrawal_volume(),
        ];
        $chart_data = $this->Admin_model->get_revenue_chart_data(7);

        $data = [
            'page_title'         => 'Command Center',
            'pending_deposits'   => $pending_deposits,
            'pending_withdrawals'=> $pending_withdrawals,
            'treasury'           => $treasury,
            'analytics_stats'    => $analytics_stats,
            'chart_data'         => $chart_data,
            'is_registration_open' => ($this->Admin_model->get_setting('is_registration_open') === '1'),
            // plan/95: state maintenance mode untuk tombol toggle dashboard.
            'is_maintenance_mode'  => ($this->Admin_model->get_setting('is_maintenance_mode') === '1'),
        ];

        $this->load->view('admin/templates/header', $data);
        $this->load->view('admin/templates/sidebar', $data);
        $this->load->view('admin/templates/topbar', $data);
        $this->load->view('admin/dashboard', $data);
        $this->load->view('admin/templates/footer');
    }

    public function approve_deposit($deposit_id) {
        // M4 (plan/62 S1): POST-only — mutasi status finansial tidak boleh
        // dipicu via GET (CSRF CI3 hanya melindungi POST-family; fail-closed).
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        // C4 (plan/54): seluruh mutasi uang pindah ke Admin_model (ACID: anchor
        // lock + transisi kondisional + Wallet_model::credit + audit). Controller
        // hanya menangani HTTP/flashdata/notifikasi — tanpa $this->db langsung.
        // plan/102: model menerima status pending MAUPUN waiting_approval,
        // menolak baris kedaluwarsa, mengkredit `total_amount`, dan melepas
        // reservasi kode unik.
        $this->load->model('Admin_model');

        $result = $this->Admin_model->approve_deposit(
            (int) $deposit_id,
            $this->_audit_ctx(null, 'approve_deposit')
        );

        if ($result['success'] && $result['deposit']) {
            // Notify user — fire-and-forget after committed TX.
            // plan/102: nominal notifikasi = nilai kredit otoritatif
            // (pokok + kode unik; fee deposit ditahan platform — D3).
            $deposit  = $result['deposit'];
            $this->load->model('Wallet_model');
            $credited = $this->Wallet_model->deposit_credit_amount($deposit);

            $this->load->model('Notification_model');
            // plan/103 W8: key + params (bukan prosa beku) — dirender dalam
            // idiom pembaca oleh i18n_notification_text().
            $this->Notification_model->insert_keyed(
                $deposit->user_id,
                'notif_deposit_approved',
                [number_format($credited, 0, ',', '.')],
                'success'
            );
            $this->session->set_flashdata('success', 'Deposit #' . $deposit->invoice_number . ' berhasil disetujui.');
        } else {
            $this->session->set_flashdata('error', $result['message']);
        }

        redirect('admin#pending-deposits');
    }

    /**
     * plan/102: tolak deposit (pending|waiting_approval → rejected) dengan
     * alasan opsional. TIDAK ada mutasi uang — reservasi kode dilepas di
     * transisi yang sama sehingga kode bebas dipakai ulang.
     */
    public function decline_deposit($deposit_id) {
        // M4 (plan/62 S1): POST-only — fail-closed.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');

        $reason = trim((string) $this->input->post('reason', TRUE));

        $result = $this->Admin_model->decline_deposit(
            (int) $deposit_id,
            $this->_audit_ctx(null, 'decline_deposit'),
            ($reason === '') ? null : $reason
        );

        if ($result['success'] && $result['deposit']) {
            $deposit = $result['deposit'];

            // BUGFIX M5 (parity decline_withdrawal): alasan dibaca dari variabel
            // POST lokal — objek $deposit adalah snapshot SEBELUM update,
            // sehingga decline_reason di dalamnya masih NULL.
            // plan/103 W8: alasan menjadi PARAMETER, bukan bagian prosa beku.
            $this->load->model('Notification_model');
            $this->Notification_model->insert_keyed(
                $deposit->user_id,
                'notif_deposit_declined',
                [$reason],
                'warning' // 'error' bukan anggota ENUM user_notifications.type (bug senyap M5)
            );
            $this->session->set_flashdata('success', 'Deposit #' . $deposit->invoice_number . ' ditolak.');
        } else {
            $this->session->set_flashdata('error', $result['message']);
        }

        redirect('admin#pending-deposits');
    }

    public function approve_withdrawal($wd_id) {
        // M4 (plan/62 S1): POST-only — mutasi status finansial tidak boleh
        // dipicu via GET (CSRF CI3 hanya melindungi POST-family; fail-closed).
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        // C4 (plan/54): status flip ACID di Admin_model (transisi kondisional,
        // anti double-submit M4); tidak ada mutasi uang di sini.
        $this->load->model('Admin_model');

        $result = $this->Admin_model->approve_withdrawal(
            (int) $wd_id,
            $this->_audit_ctx(null, 'approve_withdrawal')
        );

        if ($result['success'] && $result['withdrawal']) {
            $wd = $result['withdrawal'];
            $this->load->model('Notification_model');

            // plan/109: notifikasi WAJIB menyebut NET (dana yang benar-benar
            // dikirim ke e-wallet member) — bukan gross. Gross & fee ikut sebagai
            // rincian agar member tidak mengira ada dana hilang. Baris legacy
            // (fee/net 0) di-resolusi choke-point withdrawal_amount_parts().
            $this->load->model('Wallet_model');
            $parts = withdrawal_amount_parts($wd, [$this->Wallet_model, 'calculate_withdrawal_fee']);

            $this->Notification_model->insert_keyed(
                $wd->user_id,
                'notif_wd_approved',
                [
                    number_format($parts['gross'], 0, ',', '.'),
                    number_format($parts['fee'], 0, ',', '.'),
                    number_format($parts['net'], 0, ',', '.'),
                ],
                'success'
            );
            $this->session->set_flashdata(
                'success',
                'Penarikan #' . $wd->wd_number . ' disetujui. Transfer NET Rp '
                . number_format($parts['net'], 0, ',', '.') . ' ke akun e-wallet penarikan.'
            );
        } else {
            $this->session->set_flashdata('error', $result['message']);
        }

        redirect('admin');
    }

    public function decline_withdrawal($wd_id) {
        // M4 (plan/62 S1): POST-only — mutasi status finansial tidak boleh
        // dipicu via GET (CSRF CI3 hanya melindungi POST-family; fail-closed).
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        // C4 (plan/54): refund ACID di Admin_model (anchor lock + transisi
        // kondisional + Wallet_model::credit + audit) — tanpa $this->db langsung.
        $this->load->model('Admin_model');

        // M5/N4: alasan penolakan opsional dari form dashboard — ikut
        // dipersist (withdrawals.decline_reason), masuk audit & pesan notifikasi.
        $reason = trim((string) $this->input->post('reason', TRUE));

        $result = $this->Admin_model->decline_withdrawal(
            (int) $wd_id,
            $this->_audit_ctx(null, 'decline_withdrawal'),
            ($reason === '') ? null : $reason
        );

        if ($result['success'] && $result['withdrawal']) {
            $wd = $result['withdrawal'];
            $this->load->model('Notification_model');
            // BUGFIX M5: JANGAN baca alasan dari $wd->decline_reason — objek
            // $wd adalah snapshot baris SEBELUM update (decline_reason masih
            // NULL saat di-read), sehingga gate itu selalu false → alasan
            // hilang dari notifikasi. Alasan diambil dari variabel lokal
            // $reason (nilai POST ter-santasi, sama dengan yang dipersist).
            // plan/103 W8: nominal + alasan menjadi PARAMETER kamus.
            $this->Notification_model->insert_keyed(
                $wd->user_id,
                'notif_wd_declined',
                [number_format($wd->amount, 0, ',', '.'), $reason],
                'warning' // M5: 'error' bukan anggota ENUM user_notifications.type → di-koersi '' oleh MySQL (bug senyap)
            );
            $this->session->set_flashdata('success', 'Penarikan #' . $wd->wd_number . ' ditolak & dana dikembalikan.');
        } else {
            $this->session->set_flashdata('error', $result['message']);
        }

        redirect('admin');
    }

    public function history($type = 'deposit', $offset = 0) {
        // Whitelist tabs
        $type = ($type === 'withdrawal') ? 'withdrawal' : 'deposit';
        $offset = max(0, intval($offset));

        $this->load->model('Admin_model');
        $per_page = 50;

        if ($type === 'deposit') {
            $total = $this->Admin_model->count_history_deposits();
            $transactions = $this->Admin_model->get_history_deposits($per_page, $offset);
        } else {
            $total = $this->Admin_model->count_history_withdrawals();
            $transactions = $this->Admin_model->get_history_withdrawals($per_page, $offset);
        }

        // CI3 Pagination
        $config['base_url']      = site_url("admin/history/{$type}");
        $config['total_rows']    = $total;
        $config['per_page']      = $per_page;
        $config['uri_segment']   = 4;

        $config['full_tag_open']    = '<nav class="flex items-center justify-center gap-1 mt-6">';
        $config['full_tag_close']   = '</nav>';
        $config['num_tag_open']     = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">';
        $config['num_tag_close']    = '</a>';
        $config['cur_tag_open']     = '<span class="px-3 py-1.5 text-sm rounded-lg bg-indigo-600 text-white font-medium">';
        $config['cur_tag_close']    = '</span>';
        $config['next_link']        = '&raquo;';
        $config['next_tag_open']    = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">';
        $config['next_tag_close']   = '</a>';
        $config['prev_link']        = '&laquo;';
        $config['prev_tag_open']    = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">';
        $config['prev_tag_close']   = '</a>';
        $config['first_link']       = '&laquo;&laquo;';
        $config['first_tag_open']   = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">';
        $config['first_tag_close']  = '</a>';
        $config['last_link']        = '&raquo;&raquo;';
        $config['last_tag_open']    = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">';
        $config['last_tag_close']   = '</a>';

        $this->pagination->initialize($config);

        $data = [
            'page_title'   => 'Riwayat Transaksi',
            'transactions' => $transactions,
            'type'         => $type,
            'pagination'   => $this->pagination->create_links(),
            'total'        => $total,
        ];

        $this->load->view('admin/templates/header', $data);
        $this->load->view('admin/templates/sidebar', $data);
        $this->load->view('admin/templates/topbar', $data);
        $this->load->view('admin/history', $data);
        $this->load->view('admin/templates/footer');
    }

    public function settings() {
        $this->load->model('Admin_model');
        $this->load->model('Wallet_model');
        // Plan 89: rebate 3-tier config — resolver GET + validator POST.
        $this->load->model('Rental_model');
        // plan/112: absensi harian — resolver GET + validator POST.
        $this->load->model('Checkin_model');

        // M7 (plan/70): satu endpoint pengaturan — GET merender form terpadu
        // (kontak + finansial), POST memproses keduanya dalam satu submit.
        $method = $this->input->method();

        if ($method === 'post') {
            $errors = [];

            // ── Kontak/support (XSS filter ON, validasi form CI3) ──
            $this->form_validation->set_rules('wa_number', 'Nomor WhatsApp', 'required|numeric');
            $this->form_validation->set_rules('support_email', 'Email Support', 'required|valid_email');

            if (!$this->form_validation->run()) {
                $errors = array_merge($errors, array_values($this->form_validation->error_array()));
            }

            // plan/105: tautan grup WhatsApp — OPSIONAL ('' = kartu komunitas
            // tidak ditampilkan di halaman Bantuan member). Sengaja di luar
            // form_validation karena nilai kosong adalah nilai SAH; aturan
            // kanonik ada di helper wa_group_helper (satu sumber bersama
            // render member & CLI migrasi).
            $wa_group_raw  = (string) $this->input->post('wa_group_link', TRUE);
            $wa_group_link = wa_group_link_normalize($wa_group_raw);
            if ($wa_group_link === null) {
                $errors[] = 'Link grup WhatsApp tidak valid. Gunakan tautan undangan resmi '
                          . '(contoh: https://chat.whatsapp.com/XXXXXXXXXXXXXXXXXXXXXX) '
                          . 'atau kosongkan bila belum ada.';
                $wa_group_link = '';
            }

            $contact = [
                'wa_number'     => $this->input->post('wa_number', TRUE),
                'support_email' => $this->input->post('support_email', TRUE),
                'wa_group_link' => $wa_group_link,
            ];

            // ── Finansial (raw POST → normalizer ketat Wallet_model;
            //    validasi digit-only/regex/JSON decode sebelum disimpan) ──
            // plan/110: tier dikirim sebagai ARRAY baris (wd_tier_min[]/max[]/pct[])
            // supaya baris yang belum valid tetap terkirim & bisa direpopulasi;
            // bila array tidak ada (halaman ter-cache) jatuh ke JSON legacy.
            $tier_rows = $this->_collect_tier_rows();

            // Jalur kompatibilitas mundur (halaman ter-cache): bila array tidak
            // dikirim, JSON legacy diparse ULANG hanya untuk REPOPULASI editor —
            // validasi tetap memakai string JSON (sumber kebenaran request ini).
            if (count($tier_rows) === 0) {
                $legacy_rows = withdrawal_fee_tier_rows_from_json($this->input->post('wd_fee_tiers'));
                if (is_array($legacy_rows)) {
                    $tier_rows = $legacy_rows;
                }
            }

            $raw = [
                'wd_operational_days' => $this->input->post('wd_operational_days'),
                'wd_open_time'        => $this->input->post('wd_open_time'),
                'wd_close_time'       => $this->input->post('wd_close_time'),
                'wd_fixed_fee'        => $this->input->post('wd_fixed_fee'),
                'wd_min_amount'       => $this->input->post('wd_min_amount'),
                'wd_max_amount'       => $this->input->post('wd_max_amount'),
                'wd_fee_tiers'        => (count($tier_rows) > 0) ? $tier_rows : $this->input->post('wd_fee_tiers'),
                'deposit_fee_enabled' => $this->input->post('deposit_fee_enabled'),
                'deposit_fee_type'    => $this->input->post('deposit_fee_type'),
                'deposit_fee_value'   => $this->input->post('deposit_fee_value'),
            ];
            $v = $this->Wallet_model->validate_financial_settings($raw);
            $auto_notices = isset($v['notices']) && is_array($v['notices']) ? $v['notices'] : [];
            if (!$v['ok']) {
                $errors = array_merge($errors, $v['errors']);
            }

            // ── Rebate 3-tier (Plan 89): raw POST → normalizer ketat
            //    Rental_model (persen integer 0–100; toggle '1'/'0').
            $rebate_raw = [
                'rebate_enabled'    => $this->input->post('rebate_enabled'),
                'rebate_l1_percent' => $this->input->post('rebate_l1_percent'),
                'rebate_l2_percent' => $this->input->post('rebate_l2_percent'),
                'rebate_l3_percent' => $this->input->post('rebate_l3_percent'),
            ];
            $rv = $this->Rental_model->validate_rebate_settings($rebate_raw);
            if (!$rv['ok']) {
                $errors = array_merge($errors, $rv['errors']);
            }

            // ── Absensi Harian (plan/112): raw POST → normalizer ketat
            //    Checkin_model (toggle '1'/'0', integer IDR + invarian
            //    1 ≤ base ≤ max, whitelist kebijakan streak).
            $checkin_raw = [
                'checkin_enabled'       => $this->input->post('checkin_enabled'),
                'checkin_base_reward'   => $this->input->post('checkin_base_reward'),
                'checkin_max_reward'    => $this->input->post('checkin_max_reward'),
                'checkin_streak_policy' => $this->input->post('checkin_streak_policy'),
            ];
            $cv = $this->Checkin_model->validate_checkin_settings($checkin_raw);
            if (!$cv['ok']) {
                $errors = array_merge($errors, $cv['errors']);
            }

            // All-or-nothing: satu error → tidak ada satupun yang disimpan.
            // plan/110: state form disimpan sebagai flashdata sehingga admin
            // TIDAK kehilangan satu pun input (dulu seluruh ketikan finansial
            // hilang karena render ulang dari DB).
            if (!empty($errors)) {
                $this->session->set_flashdata('error', 'Validasi gagal: ' . implode(' ', $errors));
                $this->session->set_flashdata(
                    'settings_form_state',
                    $this->_settings_form_state($tier_rows, $errors, array_merge(
                        isset($v['field_errors']) ? $v['field_errors'] : [],
                        isset($cv['field_errors']) ? $cv['field_errors'] : []
                    ))
                );
                redirect('admin/settings');
                return;
            }

            $final = array_merge($contact, $v['values'], $rv['values'], $cv['values']);

            // M5/A1: snapshot nilai lama per key SEBELUM persist (audit before→after).
            $before = [];
            foreach (array_keys($final) as $key) {
                $before[$key] = $this->Admin_model->get_setting($key);
            }

            // Catat hanya key yang benar-benar berubah (before !== after).
            $changed = [];
            foreach ($final as $key => $value) {
                if ((string) ($before[$key] ?? null) !== (string) $value) {
                    $changed[$key] = $value;
                }
            }

            // plan/110 D2: penyesuaian otomatis endpoint tier (bila ada) WAJIB
            // terlacak — bukan perubahan senyap.
            $audit_ctx = $this->_audit_ctx(null, 'admin_update_settings', [
                'keys'          => array_keys($changed),
                'before'        => array_intersect_key($before, $changed),
                'after'         => $changed,
                'auto_adjusted' => $auto_notices,
            ]);

            // Persist atomik semua key (kontak + finansial) + audit dalam SATU TX.
            if ($this->Admin_model->update_system_settings($final, $audit_ctx)) {
                $success = 'Pengaturan berhasil disimpan dan langsung berlaku.';
                if (!empty($auto_notices)) {
                    $success .= ' Penyesuaian otomatis: ' . implode(' ', $auto_notices);
                }
                $this->session->set_flashdata('success', $success);
            } else {
                $this->session->set_flashdata('error', 'Gagal menyimpan pengaturan.');
            }
            redirect('admin/settings');
            return;
        }

        // POST-only gate: selain GET/POST ditolak 404.
        if ($method !== 'get') {
            show_404();
            return;
        }

        $contact = $this->Admin_model->get_settings_map(['wa_number', 'support_email', 'wa_group_link']);
        $cfg     = $this->Wallet_model->get_financial_config();
        // plan/102: konfigurasi pembayaran QRIS manual + kebijakan deposit
        // (form TERPISAH dari form finansial di halaman yang sama).
        $qris      = $this->Admin_model->get_settings_map(['qris_image', 'qris_merchant_name', 'qris_payment_instructions']);
        $depPolicy = $this->Wallet_model->get_deposit_policy();

        // ── plan/110: REPOPULASI setelah validasi gagal ──────────────────
        // Flashdata bertahan SATU request (pola CI3 flashdata): dibaca di sini
        // lalu dihapus otomatis, sehingga tidak ada nilai basi yang tersangkut.
        // Setiap nilai berasal dari input admin → view WAJIB meng-escape.
        $form_state = $this->session->flashdata('settings_form_state');
        $form_state = is_array($form_state) ? $form_state : [];
        $qris_state = $this->session->flashdata('qris_form_state');
        $qris_state = is_array($qris_state) ? $qris_state : [];

        // Baris tier untuk editor: state form (apa yang diketik admin) →
        // config DB (bentuk baris seragam ['min','max','pct']).
        $tier_rows = (isset($form_state['tier_rows']) && is_array($form_state['tier_rows']) && count($form_state['tier_rows']) > 0)
            ? $form_state['tier_rows']
            : $this->_tier_rows_from_config($cfg['tiers']);

        $data = [
            'page_title'          => 'Pengaturan',
            'wa_number'           => $contact['wa_number'] ?? '',
            'support_email'       => $contact['support_email'] ?? '',
            // plan/105: tautan grup/komunitas WhatsApp ('' = kartu disembunyikan).
            'wa_group_link'       => (string) ($contact['wa_group_link'] ?? ''),
            'days'                => array_map('intval', array_filter(explode(',', $cfg['operational_days']), 'strlen')),
            'open_time'           => $cfg['open_time'],
            'close_time'          => $cfg['close_time'],
            'fixed_fee'           => (int) $cfg['fixed_fee'],
            // plan/110: `tiers` dipertahankan (konsumen lama/inspeksi) dan
            // `tier_rows` adalah sumber render editor (bentuk baris seragam).
            'tiers'               => $cfg['tiers'],
            'tier_rows'           => $tier_rows,
            'min_amount'          => (int) $cfg['min_amount'],
            'max_amount'          => (int) $cfg['max_amount'],
            'deposit_fee_enabled' => (int) $cfg['deposit_fee_enabled'],
            'deposit_fee_type'    => $cfg['deposit_fee_type'],
            'deposit_fee_value'   => $cfg['deposit_fee_value'],
            // plan/110: state form (finansial + QRIS) & pesan inline.
            'form_state'          => $form_state,
            'qris_state'          => $qris_state,
            'field_errors'        => isset($form_state['field_errors']) ? (array) $form_state['field_errors'] : [],
            'qris_field_errors'   => isset($qris_state['field_errors']) ? (array) $qris_state['field_errors'] : [],
            'notices'             => isset($form_state['notices']) ? (array) $form_state['notices'] : [],
            // plan/102
            'qris_image'          => (string) ($qris['qris_image'] ?? ''),
            'qris_merchant_name'  => (string) ($qris['qris_merchant_name'] ?? ''),
            'qris_instructions'   => (string) ($qris['qris_payment_instructions'] ?? ''),
            'deposit_expiry_minutes' => (int) $depPolicy['expiry_minutes'],
            'deposit_min_amount'     => (int) $depPolicy['min_amount'],
            'deposit_max_amount'     => (int) $depPolicy['max_amount'],
        ];

        // Plan 89: nilai rebate dari merged dynamic config (fallback-safe).
        $rebate_cfg = $this->Rental_model->get_rebate_config();
        $data['rebate_enabled']    = (int) $rebate_cfg['rebate_enabled'];
        $data['rebate_l1_percent'] = (int) $rebate_cfg['rebate_l1_percent'];
        $data['rebate_l2_percent'] = (int) $rebate_cfg['rebate_l2_percent'];
        $data['rebate_l3_percent'] = (int) $rebate_cfg['rebate_l3_percent'];

        // plan/112: nilai Absensi Harian dari merged dynamic config (fallback-safe).
        $checkin_cfg = $this->Checkin_model->get_config();
        $data['checkin_enabled']       = $checkin_cfg['enabled'] ? 1 : 0;
        $data['checkin_base_reward']   = (int) $checkin_cfg['base'];
        $data['checkin_max_reward']    = (int) $checkin_cfg['max'];
        $data['checkin_streak_policy'] = $checkin_cfg['policy'];

        $this->load->view('admin/templates/header', $data);
        $this->load->view('admin/templates/sidebar', $data);
        $this->load->view('admin/templates/topbar', $data);
        $this->load->view('admin/settings', $data);
        $this->load->view('admin/templates/footer');
    }

    // ===================================================================
    //  plan/110: HELPER FORM PENGATURAN (transport tier + repopulasi)
    //  Panel admin tetap 100% Indonesia (invarian L1) — tanpa key i18n.
    // ===================================================================

    /**
     * Rakitan baris tier dari input ARRAY (plan/110):
     * `wd_tier_min[]` / `wd_tier_max[]` / `wd_tier_pct[]` dipasangkan per
     * indeks. Mengembalikan [] bila transport array tidak dipakai (halaman
     * ter-cache / JS lama) — pemanggil lalu jatuh ke JSON legacy
     * `wd_fee_tiers`. Tidak ada normalisasi di sini: aturan tier hidup di
     * `application/helpers/withdrawal_fee_helper.php` (choke-point tunggal).
     *
     * @return array<int,array{min:mixed,max:mixed,pct:mixed}>
     */
    private function _collect_tier_rows() {
        $mins = $this->input->post('wd_tier_min');
        $maxs = $this->input->post('wd_tier_max');
        $pcts = $this->input->post('wd_tier_pct');

        if (!is_array($mins)) {
            return [];
        }

        $rows = [];
        foreach (array_values($mins) as $i => $min) {
            $rows[] = [
                'min' => $min,
                'max' => (is_array($maxs) && isset($maxs[$i])) ? $maxs[$i] : null,
                'pct' => (is_array($pcts) && isset($pcts[$i])) ? $pcts[$i] : null,
            ];
        }

        return $rows;
    }

    /**
     * Bentuk baris editor yang SERAGAM dari config tersimpan
     * (`[[min,max,bps],…]` → `[['min','max','pct'],…]`) agar view dan state
     * form memakai satu bentuk. Konversi bps → persen memakai helper yang
     * sama dengan render (tidak ada rumus ganda).
     */
    private function _tier_rows_from_config(array $tiers) {
        $rows = [];
        foreach ($tiers as $tier) {
            if (!is_array($tier) || count($tier) < 3) {
                continue;
            }
            $rows[] = [
                'min' => (int) $tier[0],
                'max' => (int) $tier[1],
                'pct' => withdrawal_fee_tier_bps_to_pct($tier[2]),
            ];
        }

        return $rows;
    }

    /**
     * State form untuk repopulasi setelah validasi gagal (plan/110 P7/P8).
     * Nilai disalin APA ADANYA dari POST (string/array) — view yang
     * meng-escape saat render. Flashdata bertahan satu request sehingga tidak
     * pernah ada nilai basi yang tersangkut.
     */
    private function _settings_form_state(array $tier_rows, array $errors, array $field_errors) {
        $days = $this->input->post('wd_operational_days');

        return [
            'wa_number'           => (string) $this->input->post('wa_number', TRUE),
            'support_email'       => (string) $this->input->post('support_email', TRUE),
            'wa_group_link'       => (string) $this->input->post('wa_group_link', TRUE),
            'wd_operational_days' => is_array($days) ? array_map('strval', $days) : [],
            'wd_open_time'        => (string) $this->input->post('wd_open_time', TRUE),
            'wd_close_time'       => (string) $this->input->post('wd_close_time', TRUE),
            'wd_fixed_fee'        => (string) $this->input->post('wd_fixed_fee', TRUE),
            'wd_min_amount'       => (string) $this->input->post('wd_min_amount', TRUE),
            'wd_max_amount'       => (string) $this->input->post('wd_max_amount', TRUE),
            'tier_rows'           => $tier_rows,
            'deposit_fee_enabled' => (string) $this->input->post('deposit_fee_enabled'),
            'deposit_fee_type'    => (string) $this->input->post('deposit_fee_type', TRUE),
            'deposit_fee_value'   => (string) $this->input->post('deposit_fee_value', TRUE),
            'rebate_enabled'      => (string) $this->input->post('rebate_enabled'),
            'rebate_l1_percent'   => (string) $this->input->post('rebate_l1_percent', TRUE),
            'rebate_l2_percent'   => (string) $this->input->post('rebate_l2_percent', TRUE),
            'rebate_l3_percent'   => (string) $this->input->post('rebate_l3_percent', TRUE),
            // plan/112: Absensi Harian (satu form dengan finansial → ikut repopulasi).
            'checkin_enabled'       => (string) $this->input->post('checkin_enabled'),
            'checkin_base_reward'   => (string) $this->input->post('checkin_base_reward', TRUE),
            'checkin_max_reward'    => (string) $this->input->post('checkin_max_reward', TRUE),
            'checkin_streak_policy' => (string) $this->input->post('checkin_streak_policy', TRUE),
            'errors'              => array_values($errors),
            'field_errors'        => $field_errors,
            'notices'             => [],
        ];
    }

    // ===================================================================
    //  plan/102: PEMBAYARAN QRIS MANUAL (gambar + identitas + kebijakan deposit)
    //  Form terpisah dari /admin/settings agar jalur POST finansial/kontak/
    //  rebate yang sudah ada tidak tersentuh (risiko regresi minimum).
    //  Panel admin tetap 100% Indonesia (invarian L1) — tanpa key i18n.
    // ===================================================================

    /**
     * POST /admin/settings/qris — simpan konfigurasi QRIS manual + kebijakan
     * deposit. Urutan WAJIB: validasi → upload → persist (all-or-nothing),
     * supaya berkas tidak pernah tersimpan saat ada field tidak valid.
     */
    public function qris_settings() {
        // M4 (plan/62 S1): POST-only — selain POST ditolak 404.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $this->load->model('Wallet_model');

        // ── 1. Validasi seluruh field teks/angka LEBIH DULU (all-or-nothing).
        $v = $this->Wallet_model->validate_deposit_settings([
            'qris_merchant_name'        => $this->input->post('qris_merchant_name', TRUE),
            'qris_payment_instructions' => $this->input->post('qris_payment_instructions'),
            'deposit_expiry_minutes'    => $this->input->post('deposit_expiry_minutes'),
            'deposit_min_amount'        => $this->input->post('deposit_min_amount'),
            'deposit_max_amount'        => $this->input->post('deposit_max_amount'),
        ]);

        if (!$v['ok']) {
            $this->session->set_flashdata('error', 'Validasi QRIS gagal: ' . implode(' ', $v['errors']));
            // plan/110 P8: repopulasi kartu QRIS/deposit — input admin TIDAK
            // hilang saat validasi gagal (sebelumnya render ulang dari DB).
            $this->session->set_flashdata('qris_form_state', [
                'qris_merchant_name'        => (string) $this->input->post('qris_merchant_name', TRUE),
                'qris_payment_instructions' => (string) $this->input->post('qris_payment_instructions'),
                'deposit_expiry_minutes'    => (string) $this->input->post('deposit_expiry_minutes', TRUE),
                'deposit_min_amount'        => (string) $this->input->post('deposit_min_amount', TRUE),
                'deposit_max_amount'        => (string) $this->input->post('deposit_max_amount', TRUE),
                'field_errors'              => ['qris' => $v['errors']],
            ]);
            redirect('admin/settings');
            return;
        }

        $final = $v['values'];

        // ── 2. Upload gambar QRIS (opsional). Security Engineer checklist:
        //    allowlist ekstensi + true-MIME (detect_mime), nama acak
        //    (encrypt_name → tanpa path traversal), batas 2 MB, dan SVG
        //    SENGAJA ditolak (vektor stored XSS).
        $old_image = (string) $this->Admin_model->get_setting('qris_image');
        $new_image = null;

        if (!empty($_FILES['qris_image']['name'])) {
            $config = [
                'upload_path'   => './uploads/qris/',
                'allowed_types' => 'png|jpg|jpeg',
                'max_size'      => 2048,
                'encrypt_name'  => TRUE,
                'remove_spaces' => TRUE,
                'detect_mime'   => TRUE,
            ];

            $this->load->library('upload', $config);

            if (!$this->upload->do_upload('qris_image')) {
                // plan/110 P8: field teks tetap direpopulasi walau unggahan gagal
                // (berkas TIDAK tersimpan; hanya state form yang dibawa).
                $this->session->set_flashdata('qris_form_state', [
                    'qris_merchant_name'        => (string) $this->input->post('qris_merchant_name', TRUE),
                    'qris_payment_instructions' => (string) $this->input->post('qris_payment_instructions'),
                    'deposit_expiry_minutes'    => (string) $this->input->post('deposit_expiry_minutes', TRUE),
                    'deposit_min_amount'        => (string) $this->input->post('deposit_min_amount', TRUE),
                    'deposit_max_amount'        => (string) $this->input->post('deposit_max_amount', TRUE),
                    'field_errors'              => ['qris' => ['Upload gambar QRIS gagal: ' . $this->upload->display_errors('', '')]],
                ]);
                $this->session->set_flashdata('error', 'Upload gambar QRIS gagal: ' . $this->upload->display_errors('', ''));
                redirect('admin/settings');
                return;
            }

            $upload_data = $this->upload->data();
            $new_image   = $upload_data['file_name'];
            $final['qris_image'] = $new_image;
        }

        // ── 3. Snapshot before→after per key (audit M5/A1).
        $keys   = array_keys($final);
        $before = [];
        foreach ($keys as $key) {
            $before[$key] = $this->Admin_model->get_setting($key);
        }

        $changed = [];
        foreach ($final as $key => $value) {
            if ((string) ($before[$key] ?? null) !== (string) $value) {
                $changed[$key] = $value;
            }
        }

        $audit_ctx = $this->_audit_ctx(null, 'admin_update_qris_settings', [
            'keys'           => array_keys($changed),
            'before'         => array_intersect_key($before, $changed),
            'after'          => $changed,
            'image_replaced' => ($new_image !== null),
        ]);

        if (!$this->Admin_model->update_system_settings($final, $audit_ctx)) {
            // Persist gagal → buang berkas baru agar tidak menjadi orphan dan
            // gambar lama tetap menjadi acuan (tidak ada jendela "gambar hilang").
            if ($new_image !== null && file_exists('./uploads/qris/' . $new_image)) {
                @unlink('./uploads/qris/' . $new_image);
            }
            // plan/110 P8: state form tetap dibawa (input tidak hilang).
            $this->session->set_flashdata('qris_form_state', [
                'qris_merchant_name'        => (string) $this->input->post('qris_merchant_name', TRUE),
                'qris_payment_instructions' => (string) $this->input->post('qris_payment_instructions'),
                'deposit_expiry_minutes'    => (string) $this->input->post('deposit_expiry_minutes', TRUE),
                'deposit_min_amount'        => (string) $this->input->post('deposit_min_amount', TRUE),
                'deposit_max_amount'        => (string) $this->input->post('deposit_max_amount', TRUE),
                'field_errors'              => [],
            ]);
            $this->session->set_flashdata('error', 'Gagal menyimpan konfigurasi QRIS.');
            redirect('admin/settings');
            return;
        }

        // ── 4. Hapus berkas LAMA hanya setelah persist sukses.
        if ($new_image !== null && $old_image !== '' && $old_image !== $new_image
            && file_exists('./uploads/qris/' . $old_image)) {
            @unlink('./uploads/qris/' . $old_image);
        }

        $this->session->set_flashdata('success', 'Konfigurasi pembayaran QRIS berhasil disimpan.');
        redirect('admin/settings');
    }

    // ===================================================================
    //  M7 (plan/70): aturan finansial disatukan ke /admin/settings.
    //  Endpoint lama dipertahankan sebagai redirect shim (backward compat):
    //  bookmark/URL lama (GET maupun POST) mendarat di halaman terpadu.
    // ===================================================================

    public function financial_settings() {
        redirect('admin/settings');
    }

    // ===================================================================
    //  USER MANAGEMENT (Admin UAT Tools)
    // ===================================================================

    public function users()
    {
        $this->load->model('Admin_model');
        $search   = $this->input->get('q', TRUE);
        $per_page = 50;
        $offset   = max(0, intval($this->input->get('per_page', TRUE) ?? 0));

        $total = $this->Admin_model->count_users($search);
        $users = $this->Admin_model->get_users($search, $per_page, $offset);

        $config['base_url']             = site_url('admin/users') . ($search ? '?q=' . urlencode($search) : '');
        $config['total_rows']           = $total;
        $config['per_page']             = $per_page;
        $config['page_query_string']    = TRUE;
        $config['query_string_segment'] = 'per_page';
        $config['full_tag_open']        = '<nav class="flex items-center justify-center gap-1 mt-6">';
        $config['full_tag_close']       = '</nav>';
        $config['num_tag_open']         = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">';
        $config['num_tag_close']        = '</a>';
        $config['cur_tag_open']         = '<span class="px-3 py-1.5 text-sm rounded-lg bg-indigo-600 text-white font-medium">';
        $config['cur_tag_close']        = '</span>';
        $config['next_link']            = '&raquo;';
        $config['next_tag_open']        = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">';
        $config['next_tag_close']       = '</a>';
        $config['prev_link']            = '&laquo;';
        $config['prev_tag_open']        = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">';
        $config['prev_tag_close']       = '</a>';

        $this->pagination->initialize($config);

        $data = [
            'page_title' => 'User Management',
            'users'      => $users,
            'search'     => $search,
            'total'      => $total,
            'pagination' => $this->pagination->create_links(),
        ];

        $this->load->view('admin/templates/header', $data);
        $this->load->view('admin/templates/sidebar', $data);
        $this->load->view('admin/templates/topbar', $data);
        $this->load->view('admin/users', $data);
        $this->load->view('admin/templates/footer');
    }

    public function user_detail($id)
    {
        $this->load->model('Admin_model');
        $this->load->model('Wallet_model');
        $this->load->model('Ewallet_model');
        $id = (int) $id;

        $user = $this->Admin_model->get_user_detail($id);
        if (!$user) {
            $this->session->set_flashdata('error', 'User tidak ditemukan.');
            redirect('admin/users');
            return;
        }

        // plan/106: kartu E-Wallet di detail user — binding aktif + baris
        // katalog (untuk status aktif/nonaktif provider) + konteks penarikan
        // pending (dipakai peringatan dialog konfirmasi reset).
        $ewallet          = $this->Wallet_model->get_user_ewallet($id);
        $ewallet_provider = $ewallet ? $this->Ewallet_model->get_provider_by_name($ewallet->bank_name) : null;

        $data = [
            'page_title'     => 'User Detail',
            'user'           => $user,
            'balance'        => $this->Admin_model->get_user_balance($id),
            'rentals'        => $this->Admin_model->get_user_rentals($id),
            'wallet_history' => $this->Admin_model->get_wallet_history($id, 20),
            'downline'       => $this->Admin_model->get_downline($id),
            'products'       => $this->Admin_model->get_active_products(),
            'ewallet'          => $ewallet,
            'ewallet_provider' => $ewallet_provider,
            'has_pending_withdrawal' => $this->Wallet_model->has_pending_withdrawal($id),
        ];

        $this->load->view('admin/templates/header', $data);
        $this->load->view('admin/templates/sidebar', $data);
        $this->load->view('admin/templates/topbar', $data);
        $this->load->view('admin/user_detail', $data);
        $this->load->view('admin/templates/footer');
    }

    public function update_user($id)
    {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $id = (int) $id;

        // M5/A1: snapshot profil lama untuk payload audit before→after.
        $before_user = $this->db->select('username, phone, invite_code, parent_id')
            ->where('id', $id)
            ->get('users')
            ->row();

        $this->form_validation->set_rules('username', 'Username', 'trim|max_length[50]');
        $this->form_validation->set_rules('phone', 'Phone', 'required|trim');
        $this->form_validation->set_rules('invite_code', 'Invite Code', 'required|trim|max_length[10]');

        if ($this->form_validation->run() === FALSE) {
            $this->session->set_flashdata('error', validation_errors());
            redirect('admin/user_detail/' . $id);
            return;
        }

        $phone       = $this->_normalize_phone($this->input->post('phone', TRUE));
        $_POST['phone'] = $phone;
        $username    = $this->input->post('username', TRUE);
        $invite_code = $this->input->post('invite_code', TRUE);
        $upline_code = trim($this->input->post('upline_invite_code', TRUE));

        // Unique checks
        if ($this->Admin_model->is_invite_code_taken($invite_code, $id)) {
            $this->session->set_flashdata('error', 'Invite code sudah digunakan user lain.');
            redirect('admin/user_detail/' . $id);
            return;
        }
        $phone_exists = $this->db->where('phone', $phone)->where('id !=', $id)->count_all_results('users');
        if ($phone_exists > 0) {
            $this->session->set_flashdata('error', 'Nomor telepon sudah digunakan user lain.');
            redirect('admin/user_detail/' . $id);
            return;
        }

        // Upline resolution — validation only (no writes yet; TX starts after all guards)
        $resolved_upline_id = null;
        if ($upline_code !== '') {
            $upline = $this->Admin_model->resolve_upline($upline_code);
            if (!$upline) {
                $this->session->set_flashdata('error', 'Upline invite code tidak ditemukan.');
                redirect('admin/user_detail/' . $id);
                return;
            }
            if ($upline->id == $id) {
                $this->session->set_flashdata('error', 'User tidak bisa menjadi upline diri sendiri.');
                redirect('admin/user_detail/' . $id);
                return;
            }
            if ($this->Admin_model->has_ancestor($id, $upline->id)) {
                $this->session->set_flashdata('error', 'Upline tidak valid — akan membuat siklus.');
                redirect('admin/user_detail/' . $id);
                return;
            }
            $resolved_upline_id = $upline->id;
        }

        // Atomic: profile/upline writes + audit log commit or rollback together
        $this->db->trans_start();
        if ($resolved_upline_id !== null) {
            $this->Admin_model->update_parent_id($id, $resolved_upline_id);
        }
        $this->Admin_model->update_user_profile($id, [
            'username'    => $username,
            'phone'       => $phone,
            'invite_code' => $invite_code,
        ]);

        $this->load->model('Audit_model');
        $after_parent = ($resolved_upline_id !== null)
            ? $resolved_upline_id
            : ($before_user ? $before_user->parent_id : null);
        $this->Audit_model->log_admin_action(
            (int) $this->session->userdata('admin_id'),
            $id,
            'admin_update_user',
            [
                'before' => $before_user ? [
                    'username'    => $before_user->username,
                    'phone'       => $before_user->phone,
                    'invite_code' => $before_user->invite_code,
                    'parent_id'   => $before_user->parent_id,
                ] : null,
                'after'  => [
                    'username'    => $username,
                    'phone'       => $phone,
                    'invite_code' => $invite_code,
                    'parent_id'   => $after_parent,
                ],
            ],
            $this->input->ip_address()
        );
        $this->db->trans_complete();

        if (!$this->db->trans_status()) {
            $this->session->set_flashdata('error', 'Gagal memperbarui profil user.');
            redirect('admin/user_detail/' . $id);
            return;
        }

        $this->session->set_flashdata('success', 'Profil user berhasil diperbarui.');
        redirect('admin/user_detail/' . $id);
    }

    public function toggle_ban($id)
    {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }
        $this->load->model('Admin_model');

        // Atomic: ban state change + audit log
        $this->db->trans_start();
        $new_state = $this->Admin_model->toggle_ban($id);
        if ($new_state !== FALSE) {
            $this->load->model('Audit_model');
            $this->Audit_model->log_admin_action(
                (int) $this->session->userdata('admin_id'),
                $id,
                'admin_toggle_ban',
                ['new_state' => $new_state ? 'banned' : 'unbanned'],
                $this->input->ip_address()
            );
        }
        $this->db->trans_complete();

        if ($new_state === FALSE) {
            $this->session->set_flashdata('error', 'User tidak ditemukan.');
        } elseif ($new_state) {
            $this->session->set_flashdata('success', 'User berhasil DIBANNED.');
        } else {
            $this->session->set_flashdata('success', 'User berhasil di-UNBAN.');
            // M5/N3: beri tahu user bahwa akunnya aktif kembali (post-commit —
            // sesi lama sudah diakhiri saat ban, notifikasi terbaca saat login).
            $this->load->model('Notification_model');
            $this->Notification_model->insert_keyed(
                (int) $id,
                'notif_unbanned',
                [],
                'info'
            );
        }
        redirect('admin/user_detail/' . $id);
    }

    public function toggle_promoter($id)
    {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }
        $this->load->model('Admin_model');

        // Atomic: promoter state change + audit log (M5)
        $this->db->trans_start();
        $new_state = $this->Admin_model->toggle_promoter($id);
        if ($new_state !== FALSE) {
            $this->load->model('Audit_model');
            $this->Audit_model->log_admin_action(
                (int) $this->session->userdata('admin_id'),
                $id,
                'admin_toggle_promoter',
                ['new_state' => $new_state ? 'promoter' : 'member'],
                $this->input->ip_address()
            );
        }
        $this->db->trans_complete();

        if ($new_state === FALSE) {
            $this->session->set_flashdata('error', 'User tidak ditemukan.');
        } elseif ($new_state) {
            $this->session->set_flashdata('success', 'User dijadikan PROMOTOR — kode undangan terbuka permanen.');
        } else {
            // plan/91 (K6): demosi hanya mencabut bypass & memblokir submit baru;
            // klaim pending tetap diproses admin (keputusan dec-6d14b1039ad8cc30).
            $this->session->set_flashdata('success', 'Status promotor dicabut. Klaim pending tetap diproses; pengajuan baru diblokir.');
        }

        // M5/N3: user wajib tahu perubahan status (post-commit).
        if ($new_state !== FALSE) {
            $this->load->model('Notification_model');
            $this->Notification_model->insert_keyed(
                (int) $id,
                $new_state ? 'notif_promoter_on' : 'notif_promoter_off',
                [],
                'info'
            );
        }
        redirect('admin/user_detail/' . $id);
    }

    public function inject_balance($id)
    {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $id     = (int) $id;
        $type   = $this->input->post('type', TRUE);
        // M8 (plan/74 §2.4): validasi INTEGER ketat — floatval() lama menerima
        // "0.001" & "1e5". Kini hanya digit positif yang diteruskan ke model
        // (guard <= 0 di bawah menangkap 0/input tak valid).
        $amount_raw = $this->input->post('amount', TRUE);
        $amount     = (is_string($amount_raw) && preg_match('/^[1-9][0-9]*$/', $amount_raw))
            ? (int) $amount_raw : 0;
        $desc   = $this->input->post('description', TRUE) ?: 'Admin Manual Adjustment';

        if (!in_array($type, ['credit', 'debit']) || $amount <= 0) {
            $this->session->set_flashdata('error', 'Data inject tidak valid.');
            redirect('admin/user_detail/' . $id);
            return;
        }

        if ($this->Admin_model->inject_balance($id, $type, $amount, $desc, $this->_audit_ctx($id, 'admin_inject_balance', ['type' => $type, 'amount' => $amount, 'description' => $desc]))) {
            $label = strtoupper($type);
            $this->session->set_flashdata('success', "Balance {$label}: Rp " . number_format($amount, 0, ',', '.') . " berhasil.");
            // M5/N3: user wajib tahu perubahan saldo sepihak oleh admin (post-commit).
            $this->load->model('Notification_model');
            // plan/103 W8: suffix keterangan menjadi PARAMETER (kata sambung
            // sudah tidak dirangkai manual), sehingga kalimat dapat dirender
            // ulang dalam idiom pembaca.
            $this->Notification_model->insert_keyed(
                (int) $id,
                ($type === 'credit') ? 'notif_balance_credit' : 'notif_balance_debit',
                [
                    number_format($amount, 0, ',', '.'),
                    ($desc !== '' && $desc !== 'Admin Manual Adjustment')
                        ? sprintf(lang('notif_balance_note_suffix'), $desc)
                        : '',
                ],
                'info'
            );
        } else {
            $this->session->set_flashdata('error', 'Gagal inject balance.');
        }
        redirect('admin/user_detail/' . $id);
    }

    public function inject_rental($id)
    {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $id         = (int) $id;
        $product_id = (int) $this->input->post('product_id', TRUE);

        if ($product_id <= 0) {
            $this->session->set_flashdata('error', 'Pilih produk terlebih dahulu.');
            redirect('admin/user_detail/' . $id);
            return;
        }

        if ($this->Admin_model->inject_rental($id, $product_id, $this->_audit_ctx($id, 'admin_inject_rental', ['product_id' => $product_id]))) {
            $this->session->set_flashdata('success', 'Rental berhasil di-inject (BYPASS balance).');
            // M5/N3: user wajib tahu kontrak sewa diaktifkan sepihak oleh admin (post-commit).
            $this->load->model('Notification_model');
            $this->Notification_model->insert_keyed(
                (int) $id,
                'notif_rental_injected',
                [(int) $product_id],
                'info'
            );
        } else {
            $this->session->set_flashdata('error', 'Gagal inject rental.');
        }
        redirect('admin/user_detail/' . $id);
    }

    public function cancel_rental($rental_id)
    {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $rental = $this->db->where('id', $rental_id)->get('user_rentals')->row();

        if (!$rental) {
            $this->session->set_flashdata('error', 'Rental tidak ditemukan.');
            redirect('admin/users');
            return;
        }

        // Atomic: cancel + audit log
        $this->db->trans_start();
        $this->Admin_model->cancel_rental($rental_id);
        $this->load->model('Audit_model');
        $this->Audit_model->log_admin_action(
            (int) $this->session->userdata('admin_id'),
            $rental->user_id,
            'admin_cancel_rental',
            [
                'rental_id'      => (int) $rental_id,
                'product_id'     => isset($rental->product_id) ? (int) $rental->product_id : null,
                'purchase_price' => isset($rental->purchase_price) ? (int) $rental->purchase_price : null,
                'daily_roi'      => isset($rental->daily_roi) ? (int) $rental->daily_roi : null,
                // Soft-cancel (status → 'cancelled') TANPA refund — snapshot transparan.
                'refunded'       => false,
            ],
            $this->input->ip_address()
        );
        $this->db->trans_complete();

        if (!$this->db->trans_status()) {
            $this->session->set_flashdata('error', 'Gagal cancel rental.');
            redirect('admin/user_detail/' . $rental->user_id);
            return;
        }

        $this->session->set_flashdata('success', 'Rental #' . $rental_id . ' berhasil dicancel.');
        redirect('admin/user_detail/' . $rental->user_id);
    }

    /**
     * M3 (plan/60): sweep manual sewa kedaluwarsa (opsional, BUKAN cron).
     * Menutup SEMUA kontrak user_rentals expired (active → completed) via
     * Rental_model::expire_all_expired() + audit log, POST-only.
     * Jalur manual pelengkap lazy-evaluation (MY_Controller) & filter
     * defensif — dipicu tombol di dashboard (Treasury Health).
     */
    public function expire_expired_rentals()
    {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $this->load->model('Rental_model');
        $this->load->model('Audit_model');

        // Atomic: global sweep + audit log
        $this->db->trans_start();
        $flipped = $this->Rental_model->expire_all_expired();
        $this->Audit_model->log_admin_action(
            (int) $this->session->userdata('admin_id'),
            null, // aksi global — tanpa user target
            'admin_expire_rentals',
            ['flipped_count' => $flipped],
            $this->input->ip_address()
        );
        $this->db->trans_complete();

        if (!$this->db->trans_status()) {
            $this->session->set_flashdata('error', 'Gagal menjalankan sweep sewa kedaluwarsa.');
            redirect('admin');
            return;
        }

        if ($flipped > 0) {
            $this->session->set_flashdata('success', $flipped . ' kontrak sewa kedaluwarsa telah ditutup (active → completed).');
        } else {
            $this->session->set_flashdata('info', 'Sweep selesai: tidak ada kontrak kedaluwarsa yang perlu ditutup.');
        }
        redirect('admin');
    }

    public function adjust_time($rental_id)
    {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $rental = $this->db->where('id', $rental_id)->get('user_rentals')->row();

        if (!$rental) {
            $this->session->set_flashdata('error', 'Rental tidak ditemukan.');
            redirect('admin/users');
            return;
        }

        $last_claimed_at = $this->input->post('last_claimed_at', TRUE);
        $days_processed  = (int) $this->input->post('days_processed', TRUE);

        if (!$last_claimed_at || $days_processed < 0) {
            $this->session->set_flashdata('error', 'Data time travel tidak valid.');
            redirect('admin/user_detail/' . $rental->user_id);
            return;
        }

        // Atomic: time travel + audit log
        $this->db->trans_start();
        $this->Admin_model->adjust_rental_time($rental_id, $last_claimed_at, $days_processed);
        $this->load->model('Audit_model');
        $this->Audit_model->log_admin_action(
            (int) $this->session->userdata('admin_id'),
            $rental->user_id,
            'admin_adjust_time',
            [
                'rental_id' => (int) $rental_id,
                'before'    => [
                    'last_claimed_at' => $rental->last_claimed_at,
                    'days_processed'  => (int) $rental->days_processed,
                ],
                'after'     => [
                    'last_claimed_at' => $last_claimed_at,
                    'days_processed'  => (int) $days_processed,
                ],
            ],
            $this->input->ip_address()
        );
        $this->db->trans_complete();

        if (!$this->db->trans_status()) {
            $this->session->set_flashdata('error', 'Gagal menyesuaikan waktu rental.');
            redirect('admin/user_detail/' . $rental->user_id);
            return;
        }

        $this->session->set_flashdata('success', 'Rental #' . $rental_id . ' — Time Travel berhasil!');
        redirect('admin/user_detail/' . $rental->user_id);
    }

    // ===================================================================
    //  PLAN/91 — PROMOTER CLAIMS (approval queue)
    // ===================================================================

    /**
     * GET: Queue klaim promotor (tab Semua/Pending/Approved/Rejected + cari
     * phone/username). Setiap baris dilengkapi telemetri omzet L1 downline
     * (display; otoritas gate tetap di Promoter_model TX).
     */
    public function promoter_claims()
    {
        $this->load->model('Admin_model');
        $this->load->model('Promoter_model');

        $status = (string) $this->input->get('status', TRUE);
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $status = '';
        }
        $search   = trim((string) $this->input->get('q', TRUE));
        $per_page = 50;
        $offset   = max(0, intval($this->input->get('per_page', TRUE) ?? 0));

        $total  = $this->Admin_model->count_promoter_claims($status, $search);
        $claims = $this->Admin_model->get_promoter_claims($status, $search, $per_page, $offset);

        // Telemetri omzet per klaim — utk review manual anti volume sintetis (E9).
        foreach ($claims as $c) {
            $c->telemetry = $this->Promoter_model->get_omzet_summary((int) $c->user_id);
        }

        $params = array_filter(['q' => $search, 'status' => $status]);
        $config['base_url']             = site_url('admin/promoter-claims') . ($params ? '?' . http_build_query($params) : '');
        $config['total_rows']           = $total;
        $config['per_page']             = $per_page;
        $config['page_query_string']    = TRUE;
        $config['query_string_segment'] = 'per_page';
        $config['full_tag_open']        = '<nav class="flex items-center justify-center gap-1 mt-6">';
        $config['full_tag_close']       = '</nav>';
        $config['num_tag_open']         = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">';
        $config['num_tag_close']        = '</a>';
        $config['cur_tag_open']         = '<span class="px-3 py-1.5 text-sm rounded-lg bg-indigo-600 text-white font-medium">';
        $config['cur_tag_close']        = '</span>';
        $config['next_link']            = '&raquo;';
        $config['next_tag_open']        = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">';
        $config['next_tag_close']       = '</a>';
        $config['prev_link']            = '&laquo;';
        $config['prev_tag_open']        = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">';
        $config['prev_tag_close']       = '</a>';

        $this->pagination->initialize($config);

        $data = [
            'page_title'    => 'Klaim Promoter',
            'claims'        => $claims,
            'search'        => $search,
            'status'        => $status,
            'total'         => $total,
            'pagination'    => $this->pagination->create_links(),
            'pending_count' => $this->Admin_model->count_promoter_claims('pending'),
        ];

        $this->load->view('admin/templates/header', $data);
        $this->load->view('admin/templates/sidebar', $data);
        $this->load->view('admin/templates/topbar', $data);
        $this->load->view('admin/promoter_claims', $data);
        $this->load->view('admin/templates/footer');
    }

    /**
     * POST: Approve klaim promotor (pending→approved + kontrak reward
     * zero-cost). Seluruh TX & gate ada di Promoter_model::approve_claim();
     * audit & notifikasi ditulis atomik di dalam TX model (M5).
     */
    public function approve_promoter_claim($claim_id)
    {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $claim = $this->Admin_model->get_promoter_claim($claim_id);
        if (!$claim) {
            $this->session->set_flashdata('error', 'Klaim tidak ditemukan.');
            redirect('admin/promoter-claims');
            return;
        }

        $this->load->model('Promoter_model');
        $audit  = $this->_audit_ctx(null, 'promoter_claim_approved');
        $result = $this->Promoter_model->approve_claim((int) $claim->id, $audit);

        if ($result['success']) {
            $this->session->set_flashdata('success', $result['message']);
        } else {
            $this->session->set_flashdata('error', $result['message']);
        }
        redirect('admin/promoter-claims');
    }

    /**
     * POST: Reject klaim promotor (pending→rejected + admin_notes wajib).
     * Lock omzet lepas otomatis; audit & notifikasi atomik di dalam TX model.
     */
    public function reject_promoter_claim($claim_id)
    {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $claim = $this->Admin_model->get_promoter_claim($claim_id);
        if (!$claim) {
            $this->session->set_flashdata('error', 'Klaim tidak ditemukan.');
            redirect('admin/promoter-claims');
            return;
        }

        $notes = trim((string) $this->input->post('admin_notes', TRUE));
        if ($notes === '') {
            $this->session->set_flashdata('error', 'Alasan penolakan wajib diisi.');
            redirect('admin/promoter-claims');
            return;
        }

        $this->load->model('Promoter_model');
        $audit  = $this->_audit_ctx(null, 'promoter_claim_rejected');
        $result = $this->Promoter_model->reject_claim((int) $claim->id, $audit, $notes);

        if ($result['success']) {
            $this->session->set_flashdata('success', $result['message']);
        } else {
            $this->session->set_flashdata('error', $result['message']);
        }
        redirect('admin/promoter-claims');
    }

    // ===================================================================
    //  CREATE NEW USER (Admin Bypass Referral)
    // ===================================================================

    public function create_user()
    {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');

        // M5 (plan/66 §5 → plan/67): normalize FIRST — is_unique[users.phone]
        // must validate the canonical 08xx form, so 628xx/08xx variants of the
        // same number are rejected here with a friendly message, not by a raw
        // uk_phone DB error.
        $phone         = $this->_normalize_phone($this->input->post('phone', TRUE));
        $_POST['phone'] = $phone;

        $this->form_validation->set_rules('phone', 'Phone', 'required|trim|is_unique[users.phone]', array(
            'is_unique' => 'Nomor telepon sudah terdaftar.',
        ));
        $this->form_validation->set_rules('password', 'Password', 'required|min_length[8]');

        if ($this->form_validation->run() === FALSE) {
            $this->session->set_flashdata('error', validation_errors());
            redirect('admin/users');
            return;
        }

        $password    = $this->input->post('password', TRUE);
        $upline_code = trim($this->input->post('upline_invite_code', TRUE));

        // Auto-generate 6-char alphanumeric invite code
        $invite_code = $this->Admin_model->generate_invite_code();

        // Resolve upline — empty = root node (parent_id NULL)
        $parent_id = null;
        if ($upline_code !== '') {
            $upline = $this->Admin_model->resolve_upline($upline_code);
            if (!$upline) {
                $this->session->set_flashdata('error', 'Upline invite code tidak ditemukan.');
                redirect('admin/users');
                return;
            }
            $parent_id = $upline->id;
        }

        // Atomic: user insert + audit log (PRD §7.D.1)
        $this->db->trans_start();

        // Defensive (M5): is_unique above closes the sequential path; a
        // concurrent double-submit can still slip past it. Disable CI3
        // db_debug for this one insert (db_debug=TRUE renders a raw error page
        // outside production) and detect a uk_phone duplicate (errno 1062).
        $prev_debug         = $this->db->db_debug;
        $this->db->db_debug = FALSE;
        $user_id            = $this->Admin_model->create_user([
            'phone'       => $phone,
            'password'    => password_hash($password, PASSWORD_DEFAULT),
            'invite_code' => $invite_code,
            'parent_id'   => $parent_id,
            'role'        => 'user',
            'is_banned'   => 0,
            'balance'     => 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        $db_error           = $this->db->error();
        $this->db->db_debug = $prev_debug;
        $is_phone_dupe      = ($user_id === false && (int) $db_error['code'] === 1062
            && strpos((string) $db_error['message'], 'uk_phone') !== FALSE);

        // No audit row for a no-op duplicate (nothing was created).
        if (!$is_phone_dupe) {
            $this->load->model('Audit_model');
            $this->Audit_model->log_admin_action(
                (int) $this->session->userdata('admin_id'),
                $user_id ?: null,
                'admin_create_user',
                [
                    'phone'       => $phone,
                    'invite_code' => $invite_code,
                    'parent_id'   => $parent_id,
                    'created_by'  => (int) $this->session->userdata('admin_id'),
                ],
                $this->input->ip_address()
            );
        }
        $this->db->trans_complete();

        if ($user_id && $this->db->trans_status()) {
            $this->session->set_flashdata('success', "Pengguna berhasil dibuat! Invite Code: {$invite_code}");
        } elseif ($is_phone_dupe) {
            $this->session->set_flashdata('error', 'Nomor telepon sudah terdaftar.');
        } else {
            $this->session->set_flashdata('error', 'Gagal membuat pengguna.');
        }
        redirect('admin/users');
    }

    // ===================================================================
    //  FORCE RESET PASSWORD
    // ===================================================================

    public function reset_password($user_id)
    {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $user_id = (int) $user_id;

        if (!$this->Admin_model->user_exists($user_id)) {
            $this->session->set_flashdata('error', 'User tidak ditemukan.');
            redirect('admin/users');
            return;
        }

        $this->form_validation->set_rules('new_password', 'Password Baru', 'required|min_length[8]');

        if ($this->form_validation->run() === FALSE) {
            $this->session->set_flashdata('error', validation_errors());
            redirect('admin/user_detail/' . $user_id);
            return;
        }

        $new_password = $this->input->post('new_password', TRUE);
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);

        // Atomic: password update + audit log (PRD §7.D.2 — plaintext never logged)
        $this->db->trans_start();
        $ok = $this->Admin_model->force_reset_password($user_id, $hashed);
        $this->load->model('Audit_model');
        $this->Audit_model->log_admin_action(
            (int) $this->session->userdata('admin_id'),
            $user_id,
            'admin_reset_password',
            ['user_id' => $user_id],
            $this->input->ip_address()
        );
        $this->db->trans_complete();

        if ($ok && $this->db->trans_status()) {
            $this->session->set_flashdata('success', 'Kata sandi berhasil di-reset.');
        } else {
            $this->session->set_flashdata('error', 'Gagal mereset kata sandi.');
        }
        redirect('admin/user_detail/' . $user_id);
    }

    // ===================================================================
    //  plan/106 — RESET / UNBIND AKUN E-WALLET (admin)
    //
    //  "Reset" = ARSIP, bukan hapus (keputusan D2): `is_primary = 0` membuat
    //  member bisa mengikat ulang, sementara baris tetap ada sehingga FK
    //  `fk_withdrawals_bank` (ON DELETE RESTRICT) tidak pernah terpicu dan
    //  seluruh kartu riwayat penarikan tetap menampilkan provider + nomor asli.
    //
    //  Penarikan in-flight SENGAJA tidak diblokir: baris `withdrawals` memegang
    //  `bank_account_id` sendiri, jadi payout lama tetap dapat diproses admin.
    //  Konfirmasi di UI memberi peringatan bila masih ada penarikan pending.
    // ===================================================================

    public function reset_ewallet($user_id)
    {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $this->load->model('Wallet_model');
        $this->load->model('Audit_model');
        $user_id = (int) $user_id;

        if (!$this->Admin_model->user_exists($user_id)) {
            $this->session->set_flashdata('error', 'User tidak ditemukan.');
            redirect('admin/users');
            return;
        }

        $before = $this->Wallet_model->get_user_ewallet($user_id);
        if (!$before) {
            $this->session->set_flashdata('error', 'User tidak memiliki akun e-wallet terikat.');
            redirect('admin/user_detail/' . $user_id);
            return;
        }

        // Atomic: arsip binding + audit + notifikasi member (M5).
        $this->db->trans_start();
        $affected = $this->Wallet_model->unbind_user_ewallet($user_id);

        if ($affected >= 1) {
            // PII minimisation: nomor HP disimpan TER-MASK di audit log.
            $this->Audit_model->log_admin_action(
                (int) $this->session->userdata('admin_id'),
                $user_id,
                'admin_reset_ewallet',
                [
                    'before' => [
                        'bank_name'             => (string) $before->bank_name,
                        'account_number_masked' => ewallet_phone_mask((string) $before->account_number),
                        'account_holder'        => (string) $before->account_holder,
                    ],
                    'after'    => ['is_primary' => 0],
                    'affected' => $affected,
                ],
                $this->input->ip_address()
            );

            $this->load->model('Notification_model');
            $this->Notification_model->insert_keyed($user_id, 'notif_ewallet_reset', [], 'info');
        }
        $this->db->trans_complete();

        if ($affected < 1 || !$this->db->trans_status()) {
            $this->session->set_flashdata('error', 'Gagal mereset akun e-wallet user.');
        } else {
            $this->session->set_flashdata('success', 'Akun e-wallet user berhasil direset. User dapat mengikat ulang.');
        }
        redirect('admin/user_detail/' . $user_id);
    }

    // ===================================================================
    //  PHASE 9A: CIRCUIT BREAKER TOGGLE
    // ===================================================================

    public function toggle_registration() {
        if ($this->input->method() !== 'post') {
            // M9/P7 (plan/76 Batch D): 405 kini membawa Content-Type JSON
            // (dulu body JSON tanpa header) + legacy `error` alias.
            api_error('Method not allowed', 405, [], 'method_not_allowed', ['error' => 'Method not allowed']);
        }

        $this->load->model('Admin_model');

        $current = $this->Admin_model->get_setting('is_registration_open');
        $new_value = ($current === '1') ? '0' : '1';

        // Atomic: setting write + audit log
        $this->db->trans_start();
        $this->Admin_model->set_setting('is_registration_open', $new_value);
        $this->load->model('Audit_model');
        $this->Audit_model->log_admin_action(
            (int) $this->session->userdata('admin_id'),
            null,
            'admin_toggle_registration',
            ['was_open' => ($current === '1'), 'is_open' => ($new_value === '1')],
            $this->input->ip_address()
        );
        $this->db->trans_complete();

        $success = $this->db->trans_status();
        $is_open = ($new_value === '1');

        if ($success) {
            $message = $is_open ? 'Pendaftaran dibuka' : 'Pendaftaran ditutup';
            // Envelope + legacy root {is_open, message} (dibaca dashboard.php).
            api_success(['is_open' => $is_open], $message, 200, ['is_open' => $is_open, 'message' => $message]);
        }

        // Gagal transaksi: HTTP 500 + legacy {is_open, message} + alias
        // `error` — dashboard.php membaca data.error (bug "Unknown error"
        // plan/76 §4.4 kini tertutup).
        $message = 'Gagal mengubah pengaturan pendaftaran.';
        api_error($message, 500, [], 'toggle_failed', ['is_open' => $is_open, 'message' => $message, 'error' => $message]);
    }

    // ===================================================================
    //  PLAN 95: MAINTENANCE MODE TOGGLE
    // ===================================================================

    public function toggle_maintenance() {
        // M4 (plan/62 S1): POST-only fail-closed — mutasi tidak boleh via GET.
        if ($this->input->method() !== 'post') {
            // M9/P7: 405 JSON + content-type + legacy `error` alias.
            api_error('Method not allowed', 405, [], 'method_not_allowed', ['error' => 'Method not allowed']);
        }

        $this->load->model('Admin_model');

        $current = $this->Admin_model->get_setting('is_maintenance_mode');
        $new_value = ($current === '1') ? '0' : '1';

        // M5: write setting + audit atomik dalam SATU TX (rollback menghapus
        // keduanya). CSRF otomatis (csrfFetch kirim token); guard admin_id
        // sudah di constructor Admin.
        $this->db->trans_start();
        $this->Admin_model->set_setting('is_maintenance_mode', $new_value);
        $this->load->model('Audit_model');
        $this->Audit_model->log_admin_action(
            (int) $this->session->userdata('admin_id'),
            null,
            'admin_toggle_maintenance',
            ['was_maintenance' => ($current === '1'), 'is_maintenance' => ($new_value === '1')],
            $this->input->ip_address()
        );
        $this->db->trans_complete();

        $success = $this->db->trans_status();
        $is_maintenance = ($new_value === '1');

        if ($success) {
            $message = $is_maintenance
                ? 'Mode maintenance AKTIF — situs member terkunci (HTTP 503).'
                : 'Mode maintenance NONAKTIF — situs member normal.';
            // Envelope + legacy root {is_maintenance_mode, message} (dibaca dashboard).
            api_success(['is_maintenance_mode' => $is_maintenance], $message, 200,
                ['is_maintenance_mode' => $is_maintenance, 'message' => $message]);
        }

        // Gagal transaksi: HTTP 500 + legacy + alias `error` (bug "Unknown error" tertutup).
        $message = 'Gagal mengubah mode maintenance.';
        api_error($message, 500, [], 'toggle_failed',
            ['is_maintenance_mode' => $is_maintenance, 'message' => $message, 'error' => $message]);
    }

    // ===================================================================
    //  plan/106 — ADMIN PROVIDER E-WALLET (katalog dinamis, CRUD tanpa hard
    //  delete — keputusan D7). Pretty URLs di routes.php. Semua mutator:
    //  POST-only fail-closed, CSRF via form_open, audit atomik M5 dalam
    //  trans_start/trans_complete. Copy & pesan 100% Indonesia (invariant L1:
    //  admin TIDAK pernah memuat kamus/i18n_apply).
    // ===================================================================

    public function ewallet_providers() {
        $this->load->model('Ewallet_model');

        $data = [
            'page_title' => 'Provider E-Wallet',
            'providers'  => $this->Ewallet_model->get_providers_admin(),
        ];

        $this->load->view('admin/templates/header', $data);
        $this->load->view('admin/templates/sidebar', $data);
        $this->load->view('admin/templates/topbar', $data);
        $this->load->view('admin/ewallet_providers', $data);
        $this->load->view('admin/templates/footer');
    }

    /**
     * plan/106: tambah provider e-wallet baru (POST-only).
     *
     * `code` = identitas stabil (uppercase, unik, immutable setelah dibuat);
     * `name` = label tampilan yang disimpan apa adanya di bank_accounts.
     */
    public function create_ewallet_provider() {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Ewallet_model');
        $this->load->model('Audit_model');

        $code = strtoupper(trim((string) $this->input->post('code', TRUE)));
        $name = trim((string) $this->input->post('name', TRUE));
        $is_active = ((string) $this->input->post('is_active', TRUE) === '0') ? 0 : 1;

        if ($code === '' || !preg_match('/^[A-Z0-9_]{2,50}$/', $code)) {
            $this->session->set_flashdata('error', 'Kode provider wajib 2-50 karakter (huruf kapital, angka, atau garis bawah).');
            redirect('admin/ewallet-providers');
            return;
        }
        if ($name === '' || mb_strlen($name) > 100) {
            $this->session->set_flashdata('error', 'Nama provider wajib diisi (maksimal 100 karakter).');
            redirect('admin/ewallet-providers');
            return;
        }
        if ($this->Ewallet_model->code_exists($code)) {
            $this->session->set_flashdata('error', 'Kode provider "' . $code . '" sudah dipakai.');
            redirect('admin/ewallet-providers');
            return;
        }
        if ($this->Ewallet_model->name_exists($name)) {
            $this->session->set_flashdata('error', 'Nama provider "' . $name . '" sudah ada. Gunakan nama lain agar pilihan member tidak ambigu.');
            redirect('admin/ewallet-providers');
            return;
        }

        $this->db->trans_start();
        $new_id = $this->Ewallet_model->create_provider([
            'code'      => $code,
            'name'      => $name,
            'is_active' => $is_active,
        ]);
        if ($new_id !== false) {
            $this->Audit_model->log_admin_action(
                (int) $this->session->userdata('admin_id'),
                null, // aksi katalog provider — tanpa user (kolom nullable)
                'admin_create_ewallet_provider',
                [
                    'provider_id' => $new_id,
                    'before'      => null,
                    'after'       => ['id' => $new_id, 'code' => $code, 'name' => $name, 'is_active' => $is_active],
                ],
                $this->input->ip_address()
            );
        }
        $this->db->trans_complete();

        if (!$this->db->trans_status() || $new_id === false) {
            $this->session->set_flashdata('error', 'Gagal menyimpan provider e-wallet.');
            redirect('admin/ewallet-providers');
            return;
        }

        $this->session->set_flashdata('success', 'Provider "' . $name . '" berhasil ditambahkan.');
        redirect('admin/ewallet-providers');
    }

    /**
     * plan/106: rename provider (POST-only). CODE immutable — hanya `name`
     * yang berubah, dan perubahan itu DI-CASCADE ke label binding tersimpan
     * (`bank_accounts.bank_name`) di dalam TX yang sama (keputusan D4).
     */
    public function update_ewallet_provider($id) {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Ewallet_model');
        $this->load->model('Wallet_model');
        $this->load->model('Audit_model');

        $id     = (int) $id;
        $before = $this->Ewallet_model->get_provider($id);
        if (!$before) {
            $this->session->set_flashdata('error', 'Provider tidak ditemukan.');
            redirect('admin/ewallet-providers');
            return;
        }

        $name = trim((string) $this->input->post('name', TRUE));
        if ($name === '' || mb_strlen($name) > 100) {
            $this->session->set_flashdata('error', 'Nama provider wajib diisi (maksimal 100 karakter).');
            redirect('admin/ewallet-providers');
            return;
        }
        if ($this->Ewallet_model->name_exists($name, $id)) {
            $this->session->set_flashdata('error', 'Nama provider "' . $name . '" sudah dipakai provider lain.');
            redirect('admin/ewallet-providers');
            return;
        }

        if ($name === (string) $before->name) {
            $this->session->set_flashdata('success', 'Nama provider tidak berubah.');
            redirect('admin/ewallet-providers');
            return;
        }

        $this->db->trans_start();
        $ok        = $this->Ewallet_model->rename_provider($id, $name);
        $rebounded = $ok ? $this->Wallet_model->reassign_provider_name((string) $before->name, $name) : 0;

        if ($ok) {
            $this->Audit_model->log_admin_action(
                (int) $this->session->userdata('admin_id'),
                null,
                'admin_rename_ewallet_provider',
                [
                    'provider_id'       => $id,
                    'code'              => (string) $before->code,
                    'before'            => ['name' => (string) $before->name],
                    'after'             => ['name' => $name],
                    'rebound_bindings'  => $rebounded,
                ],
                $this->input->ip_address()
            );
        }
        $this->db->trans_complete();

        if (!$this->db->trans_status() || !$ok) {
            $this->session->set_flashdata('error', 'Gagal mengubah nama provider.');
            redirect('admin/ewallet-providers');
            return;
        }

        $this->session->set_flashdata('success', 'Provider "' . $before->name . '" berhasil diubah menjadi "' . $name . '".'
            . ($rebounded > 0 ? ' ' . $rebounded . ' akun member terikat ikut diperbarui.' : ''));
        redirect('admin/ewallet-providers');
    }

    /**
     * plan/106: aktifkan/nonaktifkan provider (POST-only).
     *
     * Guard D6: provider aktif TERAKHIR tidak boleh dinonaktifkan — tanpa
     * provider aktif, tidak ada member yang bisa mengikat akun e-wallet.
     */
    public function toggle_ewallet_provider($id) {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Ewallet_model');
        $this->load->model('Audit_model');

        $id     = (int) $id;
        $before = $this->Ewallet_model->get_provider($id);
        if (!$before) {
            $this->session->set_flashdata('error', 'Provider tidak ditemukan.');
            redirect('admin/ewallet-providers');
            return;
        }

        $new_state = (((int) $before->is_active === 1) ? 0 : 1);

        // D6: pertahankan minimal satu provider aktif.
        if ($new_state === 0 && $this->Ewallet_model->count_active_providers() <= 1) {
            $this->session->set_flashdata('error', 'Minimal satu provider e-wallet harus aktif — provider terakhir tidak bisa dinonaktifkan.');
            redirect('admin/ewallet-providers');
            return;
        }

        // Konteks keputusan: berapa binding hidup yang akan terblokir.
        $bound = 0;
        if ($new_state === 0) {
            $this->load->model('Wallet_model');
            $row = $this->db->query(
                "SELECT COUNT(*) c FROM bank_accounts WHERE is_primary = 1 AND bank_name = ?",
                [(string) $before->name]
            )->row();
            $bound = $row ? (int) $row->c : 0;
        }

        $this->db->trans_start();
        $ok = $this->Ewallet_model->set_provider_active($id, $new_state);
        if ($ok) {
            $this->Audit_model->log_admin_action(
                (int) $this->session->userdata('admin_id'),
                null,
                'admin_toggle_ewallet_provider',
                [
                    'provider_id'     => $id,
                    'code'            => (string) $before->code,
                    'name'            => (string) $before->name,
                    'before'          => ['is_active' => (int) $before->is_active],
                    'after'           => ['is_active' => $new_state],
                    'active_bindings' => $bound,
                ],
                $this->input->ip_address()
            );
        }
        $this->db->trans_complete();

        if (!$this->db->trans_status() || !$ok) {
            $this->session->set_flashdata('error', 'Gagal mengubah status provider.');
            redirect('admin/ewallet-providers');
            return;
        }

        if ($new_state === 1) {
            $this->session->set_flashdata('success', 'Provider "' . $before->name . '" berhasil diaktifkan.');
        } else {
            $this->session->set_flashdata('success', 'Provider "' . $before->name . '" berhasil dinonaktifkan.'
                . ($bound > 0 ? ' PERHATIAN: ' . $bound . ' akun member terikat akan diblokir dari penarikan sampai provider diaktifkan kembali.' : ''));
        }
        redirect('admin/ewallet-providers');
    }

    // ===================================================================
    //  PHASE 9A: CHART DATA (AJAX)
    // ===================================================================

    // ===================================================================
    //  plan/85 — ADMIN GPU PRODUCT MANAGEMENT (CRUD, no hard delete)
    //  Pretty URLs di routes.php. Semua mutator: POST-only fail-closed,
    //  CSRF via form_open (csrf_protection=TRUE), audit atomik M5 dalam
    //  trans_start/trans_complete (state + system_audit_logs commit bersama).
    // ===================================================================

    public function products() {
        $this->load->model('Admin_model');

        $data = [
            'page_title' => 'Manajemen Produk GPU',
            'products'   => $this->Admin_model->get_products_admin(),
        ];

        $this->load->view('admin/templates/header', $data);
        $this->load->view('admin/templates/sidebar', $data);
        $this->load->view('admin/templates/topbar', $data);
        $this->load->view('admin/products/index', $data);
        $this->load->view('admin/templates/footer');
    }

    public function create_product() {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $this->load->model('Audit_model');

        $v = $this->_validate_product_payload(false);
        if (!$v['ok']) {
            $this->session->set_flashdata('error', implode('<br>', $v['errors']));
            redirect('admin/products');
            return;
        }

        // plan/104: validasi teks DULU, baru upload (all-or-nothing) — tidak
        // pernah ada berkas tersimpan untuk payload yang tidak valid.
        $upload = $this->_handle_product_image_upload(false);
        if (!$upload['ok']) {
            $this->session->set_flashdata('error', $upload['error']);
            redirect('admin/products');
            return;
        }
        if ($upload['set']) {
            $v['fields']['image'] = $upload['image'];
        }

        $this->db->trans_start();
        $new_id = $this->Admin_model->create_product($v['fields']);
        if ($new_id !== false) {
            $after       = $v['fields'];
            $after['id'] = $new_id;
            $this->Audit_model->log_admin_action(
                (int) $this->session->userdata('admin_id'),
                null, // aksi produk — tanpa user (kolom nullable)
                'admin_create_product',
                ['product_id' => $new_id, 'before' => null, 'after' => $after],
                $this->input->ip_address()
            );
        }
        $this->db->trans_complete();

        if (!$this->db->trans_status() || $new_id === false) {
            // plan/104: persist gagal → berkas baru dibuang (anti-orphan).
            $this->_discard_uploaded_product_image($upload);
            $this->session->set_flashdata('error', 'Gagal menyimpan paket baru.');
        } else {
            $this->session->set_flashdata('success', 'Paket "' . $v['fields']['name'] . '" berhasil dibuat.');
        }
        redirect('admin/products');
    }

    public function update_product($id) {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $this->load->model('Audit_model');
        $id = (int) $id;

        // M5/A1: snapshot BEFORE untuk payload audit (baca murni, pra-TX).
        $before = $this->Admin_model->get_product_row($id);
        if (!$before) {
            $this->session->set_flashdata('error', 'Paket tidak ditemukan.');
            redirect('admin/products');
            return;
        }

        $v = $this->_validate_product_payload(true, $id);
        if (!$v['ok']) {
            $this->session->set_flashdata('error', implode('<br>', $v['errors']));
            redirect('admin/products');
            return;
        }

        // plan/104: gambar — urutan wajib validasi teks → upload → persist.
        // `remove_image` hanya berlaku bila tidak ada berkas baru (upload menang).
        $remove_requested = ($this->input->post('remove_image') !== null);
        $upload           = $this->_handle_product_image_upload($remove_requested);
        if (!$upload['ok']) {
            $this->session->set_flashdata('error', $upload['error']);
            redirect('admin/products');
            return;
        }
        if ($upload['set']) {
            $v['fields']['image'] = $upload['image'];
        }

        // Payload audit simetris: BEFORE (semua kolom) vs AFTER (BEFORE +
        // field yang diedit; is_active tidak diedit via form → tetap).
        $before_payload = $this->_product_payload_from_row($before);
        $after_payload  = $before_payload;
        foreach ($v['fields'] as $k => $val) {
            $after_payload[$k] = $val;
        }

        $this->db->trans_start();
        $updated = $this->Admin_model->update_product($id, $v['fields']);
        if ($updated) {
            $this->Audit_model->log_admin_action(
                (int) $this->session->userdata('admin_id'),
                null,
                'admin_update_product',
                ['product_id' => $id, 'before' => $before_payload, 'after' => $after_payload],
                $this->input->ip_address()
            );
        }
        $this->db->trans_complete();

        if (!$this->db->trans_status() || !$updated) {
            // plan/104: persist gagal → berkas BARU dibuang; gambar lama tetap
            // menjadi acuan (tidak ada jendela "gambar hilang").
            $this->_discard_uploaded_product_image($upload);
            $this->session->set_flashdata('error', 'Gagal memperbarui paket.');
        } else {
            // plan/104: hapus berkas LAMA hanya setelah persist sukses.
            $this->_delete_replaced_product_image($before, $upload, $v['fields'], $id);
            $this->session->set_flashdata('success', 'Paket "' . $before->name . '" berhasil diperbarui.');
        }
        redirect('admin/products');
    }

    public function toggle_product_status($id) {
        // M4 (plan/62 H1): fail-closed POST-only untuk mutator admin.
        if ($this->input->method() !== 'post') {
            show_404();
            return;
        }

        $this->load->model('Admin_model');
        $this->load->model('Audit_model');
        $id = (int) $id;

        $before    = $this->Admin_model->get_product_row($id);
        if (!$before) {
            $this->session->set_flashdata('error', 'Paket tidak ditemukan.');
            redirect('admin/products');
            return;
        }
        $new_state = (((int) $before->is_active === 1) ? 0 : 1);

        $this->db->trans_start();
        $ok = $this->Admin_model->set_product_active($id, $new_state);
        if ($ok) {
            $this->Audit_model->log_admin_action(
                (int) $this->session->userdata('admin_id'),
                null,
                'admin_toggle_product_status',
                [
                    'product_id' => $id,
                    'name'       => $before->name,
                    'before'     => ['is_active' => (int) $before->is_active],
                    'after'      => ['is_active' => $new_state],
                ],
                $this->input->ip_address()
            );
        }
        $this->db->trans_complete();

        if (!$this->db->trans_status() || !$ok) {
            $this->session->set_flashdata('error', 'Gagal mengubah status paket.');
        } elseif ($new_state === 1) {
            $this->session->set_flashdata('success', 'Paket "' . $before->name . '" berhasil diaktifkan.');
        } else {
            $this->session->set_flashdata('success', 'Paket "' . $before->name . '" berhasil dinonaktifkan.');
        }
        redirect('admin/products');
    }

    /**
     * plan/104 — Upload gambar produk (Security Engineer checklist).
     *
     * allowlist ekstensi + true-MIME (detect_mime, WAJIB ada entry `webp`
     * di config/mimes.php) + nama acak (encrypt_name → tanpa path traversal)
     * + batas 2048 KB (selaras upload_max_filesize=2M host). SVG SENGAJA
     * ditolak (vektor stored XSS). Pesan galat SELALU prosa Indonesia —
     * detail asli dari Upload library (bahasa Inggris) hanya masuk log
     * (invariant L1; pola plan/103 Profile::update).
     *
     * @param bool $remove_requested  checkbox "Hapus gambar saat ini"
     * @return array{ok:bool, error:string, set:bool, image:?string}
     *   set=false → gambar lama DIPERTAHANKAN (tidak ada berkas baru,
     *   tidak ada permintaan hapus).
     */
    private function _handle_product_image_upload($remove_requested) {
        $no_change = ['ok' => TRUE, 'error' => '', 'set' => FALSE, 'image' => NULL];

        // Tidak ada berkas baru diunggah.
        if (empty($_FILES['image']['name'])) {
            if ($remove_requested) {
                return ['ok' => TRUE, 'error' => '', 'set' => TRUE, 'image' => NULL];
            }
            return $no_change;
        }

        $config = [
            'upload_path'      => './uploads/products/',
            'allowed_types'    => 'jpg|jpeg|png|webp',
            'max_size'         => 2048,
            'encrypt_name'     => TRUE,
            'remove_spaces'    => TRUE,
            'file_ext_tolower' => TRUE,
            'detect_mime'      => TRUE,
        ];

        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('image')) {
            log_message('error', 'Admin::_handle_product_image_upload — ' . $this->upload->display_errors('', ''));

            return [
                'ok'    => FALSE,
                'error' => 'Upload gambar gagal: berkas harus berformat JPG, PNG, atau WebP dengan ukuran maksimal 2 MB.',
                'set'   => FALSE,
                'image' => NULL,
            ];
        }

        $upload_data = $this->upload->data();

        return [
            'ok'    => TRUE,
            'error' => '',
            'set'   => TRUE,
            'image' => (string) $upload_data['file_name'],
        ];
    }

    /**
     * plan/104 — Buang berkas BARU saat persist DB gagal (anti-orphan).
     * Hanya menyentuh berkas yang baru saja diunggah; gambar lama tidak
     * pernah tersentuh di sini.
     *
     * @param array $upload  hasil _handle_product_image_upload()
     */
    private function _discard_uploaded_product_image(array $upload) {
        if (empty($upload['set']) || empty($upload['image'])) {
            return;
        }
        $path = './uploads/products/' . $upload['image'];
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * plan/104 — Hapus berkas LAMA setelah persist sukses (deletion
     * lifecycle). Guard berlapis:
     *   1. hanya bila ada perubahan gambar (upload baru ATAU remove_image);
     *   2. nama lama tidak kosong & berbeda dari nama baru;
     *   3. tidak direferensikan baris produk lain (Admin_model);
     *   4. berkas benar-benar ada di disk.
     *
     * @param object $before   baris BEFORE (memuat ->image)
     * @param array  $upload   hasil _handle_product_image_upload()
     * @param array  $fields   field yang dipersist ($v['fields'])
     * @param int    $id       id produk
     */
    private function _delete_replaced_product_image($before, array $upload, array $fields, $id) {
        if (empty($upload['set'])) {
            return; // tidak ada perubahan gambar
        }

        $old = isset($before->image) ? (string) $before->image : '';
        if ($old === '') {
            return;
        }

        $new = array_key_exists('image', $fields) && $fields['image'] !== null
            ? (string) $fields['image']
            : '';

        if ($old === $new) {
            return; // nama sama (update no-op) → jangan hapus
        }

        $this->load->model('Admin_model');
        if ($this->Admin_model->is_product_image_referenced($old, $id)) {
            // Masih dipakai baris lain — berkas dipertahankan.
            log_message('info', 'Admin::_delete_replaced_product_image — berkas ' . $old . ' masih direferensikan produk lain; tidak dihapus.');
            return;
        }

        $path = './uploads/products/' . $old;
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Validasi payload produk (create/edit) — MURNI BACA, tanpa write.
     *   * M8 (plan/74): price/daily_rate/duration_days regex ^[1-9][0-9]*$
     *     (integer positif; float/exp/0 ditolak), max_per_user ^(0|[1-9][0-9]*)$.
     *   * name wajib & unik (ci, excl. diri sendiri saat edit).
     *   * is_active hanya untuk create (edit memakai endpoint toggle).
     *   * plan/87: validasi prasyarat DIHAPUS — gating murni via is_active.
     *
     * @param bool     $is_edit
     * @param int|null $product_id  id paket saat edit (excl. nama sendiri).
     * @return array{ok:bool, errors:string[], fields:array}
     */
    private function _validate_product_payload($is_edit, $product_id = null) {
        $errors = [];
        $p      = $this->input->post();

        // ── name ──
        $name = trim((string) ($p['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Nama paket wajib diisi (maks. 100 karakter).';
        } elseif ($this->Admin_model->is_product_name_taken($name, $product_id)) {
            $errors[] = 'Nama paket sudah digunakan paket lain.';
        }

        // ── type ──
        $type = (string) ($p['type'] ?? '');
        if (!in_array($type, ['short_term', 'long_term'], true)) {
            $errors[] = 'Tipe paket tidak valid.';
        }

        // ── plan/114: penanda produk TRIAL (activation hook) ──
        // Harga Rp 0 HANYA sah untuk produk trial; invarian produk trial
        // (harga 0 + kuota 1/user) dipakai jalur bebas checkout & migrasi 114.
        $is_trial = (isset($p['is_trial']) && (int) $p['is_trial'] === 1) ? 1 : 0;

        // ── M8 integer IDR & durasi/kuota ──
        $price_raw = (string) ($p['price'] ?? '');
        $price_pattern = ($is_trial === 1) ? '/^(0|[1-9][0-9]*)$/' : '/^[1-9][0-9]*$/';
        if (!preg_match($price_pattern, $price_raw)) {
            $errors[] = $is_trial === 1
                ? 'Harga produk trial wajib 0 (Rp 0).'
                : 'Harga sewa harus bilangan bulat positif (IDR).';
        }
        $roi_raw = (string) ($p['daily_rate'] ?? '');
        if (!preg_match('/^[1-9][0-9]*$/', $roi_raw)) {
            $errors[] = 'ROI harian harus bilangan bulat positif (IDR).';
        }
        $dur_raw = (string) ($p['duration_days'] ?? '');
        if (!preg_match('/^[1-9][0-9]*$/', $dur_raw)) {
            $errors[] = 'Durasi kontrak minimal 1 hari.';
        }
        $quota_raw = (string) ($p['max_per_user'] ?? '');
        if (!preg_match('/^(0|[1-9][0-9]*)$/', $quota_raw)) {
            $errors[] = 'Batas sewa per user harus ≥ 0 (0 = tanpa batas).';
        }

        // plan/87: validasi prasyarat DIHAPUS — gating produk murni via
        // is_active (toggle admin). Kolom unlock_prerequisite_id dormant.

        // plan/114 — INVARIAN & GUARD PRODUK TRIAL (all-or-nothing).
        if ($is_trial === 1) {
            if ((int) $price_raw !== 0) {
                $errors[] = 'Produk trial wajib berharga Rp 0.';
            }
            if ((int) $quota_raw !== 1) {
                $errors[] = 'Produk trial wajib dibatasi 1 sewa per user.';
            }
            if ($this->Admin_model->count_other_trial_products($is_edit ? (int) $product_id : 0) > 0) {
                $errors[] = 'Hanya satu produk trial yang diizinkan.';
            }
            if ($is_edit && $this->Admin_model->product_has_paid_rentals((int) $product_id)) {
                $errors[] = 'Produk sudah memiliki kontrak berbayar — tidak dapat dijadikan trial.';
            }
        }

        if (count($errors) > 0) {
            return ['ok' => false, 'errors' => $errors, 'fields' => []];
        }

        $fields = [
            'name'                     => $name,
            'type'                     => $type,
            'price'                    => (int) $price_raw,
            'daily_rate'               => (int) $roi_raw,
            'duration_days'            => (int) $dur_raw,
            'is_refundable'            => isset($p['is_refundable']) ? 1 : 0,
            'max_per_user'             => (int) $quota_raw,
            // plan/114: koersi 0/1 (kolom TINYINT; hanya 1 yang berarti trial).
            'is_trial'                 => $is_trial,
        ];
        if (!$is_edit) {
            $fields['is_active'] = (isset($p['is_active']) && (int) $p['is_active'] === 1) ? 1 : 0;
        }
        return ['ok' => true, 'errors' => [], 'fields' => $fields];
    }

    /**
     * Payload audit simetris dari baris gpu_products (BEFORE snapshot).
     * M8: nominal & boolean di-(int) kan. plan/87: unlock_prerequisite_id
     * tidak disertakan (kolom dormant). plan/104: `image` (nama berkas
     * gambar produk) disertakan — `??` menjaga kompatibilitas bila kolom
     * belum ada (deploy kode mendahului DDL).
     *
     * @param object $row  hasil Admin_model::get_product_row()
     * @return array
     */
    private function _product_payload_from_row($row) {
        return [
            'name'                   => $row->name,
            'image'                  => $row->image ?? null,
            'type'                   => $row->type,
            'price'                  => (int) $row->price,
            'daily_rate'             => (int) $row->daily_rate,
            'duration_days'          => (int) $row->duration_days,
            'is_refundable'          => (int) $row->is_refundable,
            'max_per_user'           => (int) $row->max_per_user,
            'is_active'              => (int) $row->is_active,
            // plan/114: `??` menjaga kompatibilitas bila DDL `is_trial`
            // belum dijalankan pada DB target (deploy kode mendahului DDL).
            'is_trial'               => (int) ($row->is_trial ?? 0),
        ];
    }

    public function chart_data() {
        $this->load->model('Admin_model');
        $days = max(1, min(90, intval($this->input->get('days', TRUE) ?: 7)));
        $chart = $this->Admin_model->get_revenue_chart_data($days);

        // M9/P7 (plan/76 Batch D): endpoint data-native — payload key `data`
        // (deret revenue) BERBENTURAN dengan key envelope `data`, sehingga
        // nesting penuh {data:{labels,data}} mustahil tanpa merusak konsumen
        // (footer.php chart: json.data = deret revenue, json.labels).
        // Solusi zero-regression: root TETAP {labels, data} (legacy) + additif
        // {success:true, message:''} — konsumen tidak berubah (deviasi
        // terdokumentasi di plan/77 §3).
        api_success(null, '', 200, ['labels' => $chart['labels'], 'data' => $chart['data']]);
    }

    // ===================================================================
    //  PHASE 9B: ANALYTICS PAGE
    // ===================================================================

    public function analytics() {
        $this->load->model('Admin_model');

        $global   = $this->Admin_model->get_global_analytics();
        $leaders  = $this->Admin_model->get_leaderboard(25);

        $data = [
            'page_title' => 'Analytics',
            'global'     => $global,
            'leaders'    => $leaders,
        ];

        $this->load->view('admin/templates/header', $data);
        $this->load->view('admin/templates/sidebar', $data);
        $this->load->view('admin/templates/topbar', $data);
        $this->load->view('admin/analytics', $data);
        $this->load->view('admin/templates/footer');
    }

    // ===================================================================
    //  PHASE 9B: FINANCIAL X-RAY (AJAX JSON)
    // ===================================================================

    public function user_xray($user_id) {
        $this->load->model('Admin_model');
        $user_id = (int) $user_id;

        $xray = $this->Admin_model->get_user_xray($user_id);

        if (!$xray) {
            // M9/P7 (plan/76 Batch D): 404 envelope + legacy `error` alias
            // (analytics.php openXray() membaca json.error).
            api_error('User not found', 404, [], 'not_found', ['error' => 'User not found']);
        }

        // Envelope {success:true, data: xray} — bentuk `data` tidak berubah
        // (analytics.php membaca json.data.user / total_credit / dst).
        api_success($xray, '', 200);
    }


    // ===================================================================
    //  PHASE 10A: AUDIT VIEWER (/admin/audit)
    // ===================================================================

    public function audit()
    {
        $this->load->model('Admin_model');
        $this->load->model('Audit_model');

        // Filters — action is passed through; date format validated here
        $action = trim((string) $this->input->get('action', TRUE));
        $from   = trim((string) $this->input->get('from', TRUE));
        $to     = trim((string) $this->input->get('to', TRUE));

        if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
        if ($to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = '';

        $per_page = 50;
        $offset   = max(0, intval($this->input->get('per_page', TRUE) ?? 0));

        $total = $this->Audit_model->count_audit_logs($action, $from, $to);
        $logs  = $this->Audit_model->get_audit_logs($action, $from, $to, $per_page, $offset);

        // Pagination — reuse_query_string keeps action/from/to across page links
        $config['base_url']             = site_url('admin/audit');
        $config['total_rows']           = $total;
        $config['per_page']             = $per_page;
        $config['page_query_string']    = TRUE;
        $config['query_string_segment'] = 'per_page';
        $config['reuse_query_string']   = TRUE;
        $config['full_tag_open']        = '<nav class="flex items-center justify-center gap-1 mt-6">';
        $config['full_tag_close']       = '</nav>';
        $config['num_tag_open']         = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-800 text-slate-400 hover:bg-slate-800 hover:text-slate-200 transition-colors font-mono">';
        $config['num_tag_close']        = '</a>';
        $config['cur_tag_open']         = '<span class="px-3 py-1.5 text-sm rounded-lg bg-emerald-600 text-white font-medium font-mono">';
        $config['cur_tag_close']        = '</span>';
        $config['next_link']            = '&raquo;';
        $config['next_tag_open']        = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-800 text-slate-400 hover:bg-slate-800 hover:text-slate-200 transition-colors font-mono">';
        $config['next_tag_close']       = '</a>';
        $config['prev_link']            = '&laquo;';
        $config['prev_tag_open']        = '<a href="{link}" class="px-3 py-1.5 text-sm rounded-lg border border-slate-800 text-slate-400 hover:bg-slate-800 hover:text-slate-200 transition-colors font-mono">';
        $config['prev_tag_close']       = '</a>';

        $this->pagination->initialize($config);

        $data = [
            'page_title' => 'Audit Logs',
            'logs'       => $logs,
            'actions'    => $this->Audit_model->get_action_options(),
            'f_action'   => $action,
            'f_from'     => $from,
            'f_to'       => $to,
            'total'      => $total,
            'pagination' => $this->pagination->create_links(),
        ];

        $this->load->view('admin/templates/header', $data);
        $this->load->view('admin/templates/sidebar', $data);
        $this->load->view('admin/templates/topbar', $data);
        $this->load->view('admin/audit', $data);
        $this->load->view('admin/templates/footer');
    }

    // ===================================================================
    //  PHASE 9C: NATIVE CSV EXPORT
    // ===================================================================

    public function export_csv($type = '')
    {
        if (!$this->session->userdata('admin_id')) {
            redirect('control-panel');
        }

        $allowed = ['ledger', 'rentals', 'withdrawals'];
        if (!in_array($type, $allowed)) {
            show_404();
        }

        $this->load->model('Admin_model');
        $date = date('Y-m-d');

        // C3 (plan/52): kolom diekspor lewat EXPLICIT column-map per tipe
        // (order-independent; header dan isi tidak bisa melenceng), bukan
        // dump mentah result_array(). IDR diekspor sebagai integer polos.
        switch ($type) {
            case 'ledger':
                $data     = $this->Admin_model->get_all_ledger();
                $filename = 'wallet_ledger';
                $headers  = ['ID', 'User ID', 'Amount (IDR)', 'Type', 'Description', 'Created At'];
                $rows     = [];
                foreach ($data as $r) {
                    $rows[] = [
                        $r['id'], $r['user_id'],
                        $this->_csv_money($r['amount']),
                        $r['type'], $r['description'],
                        $this->_csv_ts($r['created_at']),
                    ];
                }
                break;

            case 'rentals':
                $data     = $this->Admin_model->get_active_rentals();
                $filename = 'active_rentals';
                $headers  = ['ID', 'User ID', 'Phone', 'Product Name', 'Purchase Price (IDR)',
                             'Daily ROI (IDR)', 'Days Processed', 'Total Days', 'Status', 'Created At'];
                $rows     = [];
                foreach ($data as $r) {
                    $rows[] = [
                        $r['id'], $r['user_id'], $r['phone'],
                        $r['product_name'],
                        $this->_csv_money($r['purchase_price']),
                        $this->_csv_money($r['daily_roi']),
                        $r['days_processed'], $r['total_days'], $r['status'],
                        $this->_csv_ts($r['created_at']),
                    ];
                }
                break;

            case 'withdrawals':
                $this->load->model('Wallet_model');
                $data     = $this->Admin_model->get_all_withdrawals();
                $filename = 'withdrawals';
                $headers  = ['ID', 'WD Number', 'User ID', 'Phone',
                             'Gross (IDR)', 'Fee (IDR)', 'Net (IDR)',
                             'Provider E-Wallet', 'Nomor HP E-Wallet', 'Nama Pemilik Akun',
                             'Status', 'Processed At', 'Created At'];
                $rows     = [];
                foreach ($data as $r) {
                    // Legacy fallback: gross_amount 0/NULL -> amount (gross_eff alias).
                    // M8: baca sebagai integer IDR bulat (kolom DECIMAL → string).
                    $gross = (int) $r['gross_eff'];
                    $fee   = (int) $r['fee_amount'];
                    $net   = (int) $r['net_amount'];

                    // Read-side recompute: legacy rows tanpa fee/net tersimpan
                    // (0/NULL) -> hitung ulang dari gross sesuai tier PRD.
                    if ($gross > 0 && ($fee <= 0 || $net <= 0)) {
                        $calc = $this->Wallet_model->calculate_withdrawal_fee($gross);
                        $fee  = $calc['fee'];
                        $net  = $calc['net'];
                    }

                    $rows[] = [
                        $r['id'], $r['wd_number'], $r['user_id'], $r['phone'],
                        $this->_csv_money($gross), $this->_csv_money($fee), $this->_csv_money($net),
                        $r['bank_name'], $r['account_number'], $r['account_holder'],
                        $r['status'],
                        $this->_csv_ts($r['processed_at']),
                        $this->_csv_ts($r['created_at']),
                    ];
                }
                break;
        }

        // Force download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . $date . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Stream to browser via php://output
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel compat
        fputcsv($fp, $headers);

        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }

        fclose($fp);
        exit;
    }

    /**
     * IDR ke integer polos untuk CSV (machine-readable; tanpa "Rp", tanpa
     * pemisah ribuan). Nilai UI number_format hanya untuk tampilan.
     * M8: tidak ada round() pada uang — nilai sudah integer IDR di sumbernya,
     * helper hanya menormalkan tipe (int) → string.
     */
    private function _csv_money($value)
    {
        return (string) (int) $value;
    }

    /** Format timestamp DB ke Y-m-d H:i; kosong bila null/00:00. */
    private function _csv_ts($value)
    {
        if (!$value || $value === '0000-00-00 00:00:00' || $value === '0000-00-00') {
            return '';
        }
        return date('Y-m-d H:i', strtotime($value));
    }

}
