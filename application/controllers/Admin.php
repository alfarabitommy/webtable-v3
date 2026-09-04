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

        if (!$this->session->userdata('admin_id')) {
            redirect('control-panel');
        }
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

        $pending_deposits = $this->db->select('d.*, u.phone')
            ->from('deposits d')
            ->join('users u', 'u.id = d.user_id', 'left')
            ->where('d.status', 'pending')
            ->order_by('d.created_at', 'ASC')
            ->get()->result();

        $pending_withdrawals = $this->db->select('w.*, u.phone, ba.bank_name, ba.account_number, ba.account_holder AS account_name')
            ->from('withdrawals w')
            ->join('users u', 'u.id = w.user_id', 'left')
            ->join('bank_accounts ba', 'ba.id = w.bank_account_id', 'left')
            ->where('w.status', 'pending')
            ->order_by('w.created_at', 'ASC')
            ->get()->result();

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
        $this->load->model('Admin_model');

        $result = $this->Admin_model->approve_deposit(
            (int) $deposit_id,
            $this->_audit_ctx(null, 'approve_deposit')
        );

        if ($result['success'] && $result['deposit']) {
            // Notify user — fire-and-forget after committed TX
            $deposit = $result['deposit'];
            $this->load->model('Notification_model');
            $this->Notification_model->insert(
                $deposit->user_id,
                'Deposit Berhasil',
                'Top Up sebesar Rp ' . number_format($deposit->amount, 0, ',', '.') . ' telah masuk ke saldo Anda.',
                'success'
            );
            $this->session->set_flashdata('success', 'Deposit #' . $deposit->invoice_number . ' berhasil disetujui.');
        } else {
            $this->session->set_flashdata('error', $result['message']);
        }

        redirect('admin');
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
            $this->Notification_model->insert(
                $wd->user_id,
                'Penarikan Berhasil',
                'Penarikan sebesar Rp ' . number_format($wd->amount, 0, ',', '.') . ' telah diproses.',
                'success'
            );
            $this->session->set_flashdata('success', 'Penarikan #' . $wd->wd_number . ' berhasil disetujui.');
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
            // hilang dari notifikasi. Bangun pesan dari variabel lokal $reason
            // (nilai POST ter-santasi yang sama dengan yang dipersist + diaudit).
            $message = 'Penarikan sebesar Rp ' . number_format($wd->amount, 0, ',', '.') . ' ditolak. Dana telah dikembalikan ke saldo.';
            if ($reason !== '') {
                $message .= ' Alasan: ' . $reason;
            }
            $this->Notification_model->insert(
                $wd->user_id,
                'Penarikan Ditolak',
                $message,
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

            $contact = [
                'wa_number'     => $this->input->post('wa_number', TRUE),
                'support_email' => $this->input->post('support_email', TRUE),
            ];

            // ── Finansial (raw POST → normalizer ketat Wallet_model;
            //    validasi digit-only/regex/JSON decode sebelum disimpan) ──
            $raw = [
                'wd_operational_days' => $this->input->post('wd_operational_days'),
                'wd_open_time'        => $this->input->post('wd_open_time'),
                'wd_close_time'       => $this->input->post('wd_close_time'),
                'wd_fixed_fee'        => $this->input->post('wd_fixed_fee'),
                'wd_min_amount'       => $this->input->post('wd_min_amount'),
                'wd_max_amount'       => $this->input->post('wd_max_amount'),
                'wd_fee_tiers'        => $this->input->post('wd_fee_tiers'),
                'deposit_fee_enabled' => $this->input->post('deposit_fee_enabled'),
                'deposit_fee_type'    => $this->input->post('deposit_fee_type'),
                'deposit_fee_value'   => $this->input->post('deposit_fee_value'),
            ];
            $v = $this->Wallet_model->validate_financial_settings($raw);
            if (!$v['ok']) {
                $errors = array_merge($errors, $v['errors']);
            }

            // All-or-nothing: satu error → tidak ada satupun yang disimpan.
            if (!empty($errors)) {
                $this->session->set_flashdata('error', 'Validasi gagal: ' . implode(' ', $errors));
                redirect('admin/settings');
                return;
            }

            $final = array_merge($contact, $v['values']);

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

            $audit_ctx = $this->_audit_ctx(null, 'admin_update_settings', [
                'keys'   => array_keys($changed),
                'before' => array_intersect_key($before, $changed),
                'after'  => $changed,
            ]);

            // Persist atomik semua key (kontak + finansial) + audit dalam SATU TX.
            if ($this->Admin_model->update_system_settings($final, $audit_ctx)) {
                $this->session->set_flashdata('success', 'Pengaturan berhasil disimpan dan langsung berlaku.');
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

        $contact = $this->Admin_model->get_settings_map(['wa_number', 'support_email']);
        $cfg     = $this->Wallet_model->get_financial_config();

        $data = [
            'page_title'          => 'Pengaturan',
            'wa_number'           => $contact['wa_number'] ?? '',
            'support_email'       => $contact['support_email'] ?? '',
            'days'                => array_map('intval', array_filter(explode(',', $cfg['operational_days']), 'strlen')),
            'open_time'           => $cfg['open_time'],
            'close_time'          => $cfg['close_time'],
            'fixed_fee'           => (int) $cfg['fixed_fee'],
            'tiers'               => $cfg['tiers'],
            'min_amount'          => (int) $cfg['min_amount'],
            'max_amount'          => (int) $cfg['max_amount'],
            'deposit_fee_enabled' => (int) $cfg['deposit_fee_enabled'],
            'deposit_fee_type'    => $cfg['deposit_fee_type'],
            'deposit_fee_value'   => $cfg['deposit_fee_value'],
        ];

        $this->load->view('admin/templates/header', $data);
        $this->load->view('admin/templates/sidebar', $data);
        $this->load->view('admin/templates/topbar', $data);
        $this->load->view('admin/settings', $data);
        $this->load->view('admin/templates/footer');
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
        $id = (int) $id;

        $user = $this->Admin_model->get_user_detail($id);
        if (!$user) {
            $this->session->set_flashdata('error', 'User tidak ditemukan.');
            redirect('admin/users');
            return;
        }

        $data = [
            'page_title'     => 'User Detail',
            'user'           => $user,
            'balance'        => $this->Admin_model->get_user_balance($id),
            'rentals'        => $this->Admin_model->get_user_rentals($id),
            'wallet_history' => $this->Admin_model->get_wallet_history($id, 20),
            'downline'       => $this->Admin_model->get_downline($id),
            'products'       => $this->Admin_model->get_active_products(),
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
            $this->Notification_model->insert(
                (int) $id,
                'Akun Diaktifkan Kembali',
                'Akun Anda telah dibuka blokirnya oleh admin. Silakan login kembali.',
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
        $amount = floatval($this->input->post('amount', TRUE));
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
            $this->Notification_model->insert(
                (int) $id,
                ($type === 'credit') ? 'Saldo Ditambahkan Admin' : 'Saldo Dipotong Admin',
                'Saldo sebesar Rp ' . number_format($amount, 0, ',', '.') . ' telah '
                    . (($type === 'credit') ? 'ditambahkan ke' : 'dipotong dari') . ' saldo Anda oleh admin.'
                    . (($desc !== '' && $desc !== 'Admin Manual Adjustment') ? ' Keterangan: ' . $desc : ''),
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
            $this->Notification_model->insert(
                (int) $id,
                'Sewa Diaktifkan Admin',
                'Kontrak sewa (produk #' . $product_id . ') telah diaktifkan untuk akun Anda oleh admin.',
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
                'purchase_price' => isset($rental->purchase_price) ? (float) $rental->purchase_price : null,
                'daily_roi'      => isset($rental->daily_roi) ? (float) $rental->daily_roi : null,
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
    //  PHASE 9A: CIRCUIT BREAKER TOGGLE
    // ===================================================================

    public function toggle_registration() {
        if ($this->input->method() !== 'post') {
            $this->output->set_status_header(405)->set_output(json_encode(['success' => false, 'error' => 'Method not allowed']));
            return;
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

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'success' => $success,
                'is_open' => ($new_value === '1'),
                'message' => !$success
                    ? 'Gagal mengubah pengaturan pendaftaran.'
                    : (($new_value === '1') ? 'Pendaftaran dibuka' : 'Pendaftaran ditutup')
            ]));
    }

    // ===================================================================
    //  PHASE 9A: CHART DATA (AJAX)
    // ===================================================================

    public function chart_data() {
        $this->load->model('Admin_model');
        $days = max(1, min(90, intval($this->input->get('days', TRUE) ?: 7)));
        $chart = $this->Admin_model->get_revenue_chart_data($days);
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($chart));
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
            $this->output
                ->set_content_type('application/json')
                ->set_status_header(404)
                ->set_output(json_encode(['success' => false, 'error' => 'User not found']));
            return;
        }

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['success' => true, 'data' => $xray]));
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
                             'Bank Name', 'Account Number', 'Account Holder',
                             'Status', 'Processed At', 'Created At'];
                $rows     = [];
                foreach ($data as $r) {
                    // Legacy fallback: gross_amount 0/NULL -> amount (gross_eff alias).
                    $gross = (float) $r['gross_eff'];
                    $fee   = (float) $r['fee_amount'];
                    $net   = (float) $r['net_amount'];

                    // Read-side recompute: legacy rows tanpa fee/net tersimpan
                    // (0/NULL) -> hitung ulang dari gross sesuai tier PRD.
                    if ($gross > 0 && ($fee <= 0 || $net <= 0)) {
                        $calc = $this->Wallet_model->calculate_withdrawal_fee((int) $gross);
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
     */
    private function _csv_money($value)
    {
        return (string) (int) round((float) $value);
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
