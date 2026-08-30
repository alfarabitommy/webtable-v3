<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin extends CI_Controller {

    public function __construct() {
        parent::__construct();

        $this->load->database();
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
        $deposit = $this->db->get_where('deposits', ['id' => $deposit_id])->row();
        if (!$deposit || $deposit->status !== 'pending') {
            $this->session->set_flashdata('error', 'Deposit tidak valid atau sudah diproses.');
            redirect('admin');
            return;
        }

        $this->db->trans_start();

        // 1. Update deposit status
        $this->db->where('id', $deposit_id)->update('deposits', ['status' => 'success']);

        // 2. Credit wallet ledger
        $this->db->insert('wallet_ledger', [
            'user_id'        => $deposit->user_id,
            'transaction_id' => $deposit->invoice_number,
            'amount'         => $deposit->amount,
            'type'           => 'credit',
            'description'    => 'Top Up via ' . $deposit->invoice_number,
        ]);

        $this->db->trans_complete();

        if ($this->db->trans_status()) {
            // 3. Notify user — fire-and-forget after committed TX
            $this->load->model('Notification_model');
            $this->Notification_model->insert(
                $deposit->user_id,
                'Deposit Berhasil',
                'Top Up sebesar Rp ' . number_format($deposit->amount, 0, ',', '.') . ' telah masuk ke saldo Anda.',
                'success'
            );
            $this->session->set_flashdata('success', 'Deposit #' . $deposit->invoice_number . ' berhasil disetujui.');
        } else {
            $this->session->set_flashdata('error', 'Gagal memproses deposit.');
        }

        redirect('admin');
    }

    public function approve_withdrawal($wd_id) {
        $wd = $this->db->get_where('withdrawals', ['id' => $wd_id])->row();
        if (!$wd || $wd->status !== 'pending') {
            $this->session->set_flashdata('error', 'Penarikan tidak valid atau sudah diproses.');
            redirect('admin');
            return;
        }

        $this->db->trans_start();

        // Balance was already deducted on request — just flip status
        $this->db->where('id', $wd_id)->update('withdrawals', ['status' => 'success']);

        $this->db->trans_complete();

        if ($this->db->trans_status()) {
            // 2. Notify user
            $this->load->model('Notification_model');
            $this->Notification_model->insert(
                $wd->user_id,
                'Penarikan Berhasil',
                'Penarikan sebesar Rp ' . number_format($wd->amount, 0, ',', '.') . ' telah diproses.',
                'success'
            );
            $this->session->set_flashdata('success', 'Penarikan #' . $wd->wd_number . ' berhasil disetujui.');
        } else {
            $this->session->set_flashdata('error', 'Gagal memproses penarikan.');
        }

        redirect('admin');
    }

    public function decline_withdrawal($wd_id) {
        $wd = $this->db->get_where('withdrawals', ['id' => $wd_id])->row();
        if (!$wd || $wd->status !== 'pending') {
            $this->session->set_flashdata('error', 'Penarikan tidak valid atau sudah diproses.');
            redirect('admin');
            return;
        }

        $this->db->trans_start();

        // 1. Update withdrawal status to failed
        $this->db->where('id', $wd_id)->update('withdrawals', ['status' => 'failed']);

        // 2. Refund: insert credit transaction into wallet_ledger
        // Funds were already debited on request — must credit back
        $this->db->insert('wallet_ledger', [
            'user_id'        => $wd->user_id,
            'transaction_id' => $wd->wd_number,
            'amount'         => $wd->amount,
            'type'           => 'credit',
            'description'    => 'Pengembalian Dana: Penarikan Ditolak (' . $wd->wd_number . ')',
        ]);

        $this->db->trans_complete();

        if ($this->db->trans_status()) {
            // 3. Notify user
            $this->load->model('Notification_model');
            $this->Notification_model->insert(
                $wd->user_id,
                'Penarikan Ditolak',
                'Penarikan sebesar Rp ' . number_format($wd->amount, 0, ',', '.') . ' ditolak. Dana telah dikembalikan ke saldo.',
                'error'
            );
            $this->session->set_flashdata('success', 'Penarikan #' . $wd->wd_number . ' ditolak & dana dikembalikan.');
        } else {
            $this->session->set_flashdata('error', 'Gagal menolak penarikan.');
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

        if ($this->input->method() === 'post') {
            $this->form_validation->set_rules('wa_number', 'Nomor WhatsApp', 'required|numeric');
            $this->form_validation->set_rules('support_email', 'Email Support', 'required|valid_email');

            if ($this->form_validation->run()) {
                $data = [
                    'wa_number'     => $this->input->post('wa_number', TRUE),
                    'support_email' => $this->input->post('support_email', TRUE),
                ];

                if ($this->Admin_model->update_settings($data)) {
                    $this->session->set_flashdata('success', 'Pengaturan berhasil disimpan.');
                } else {
                    $this->session->set_flashdata('error', 'Gagal menyimpan pengaturan.');
                }
            } else {
                $this->session->set_flashdata('error', validation_errors());
            }
            redirect('admin/settings');
        }

        $settings = $this->Admin_model->get_all_settings();

        $data = [
            'page_title'     => 'Pengaturan',
            'wa_number'       => $settings['wa_number'] ?? '',
            'support_email'   => $settings['support_email'] ?? '',
        ];

        $this->load->view('admin/templates/header', $data);
        $this->load->view('admin/templates/sidebar', $data);
        $this->load->view('admin/templates/topbar', $data);
        $this->load->view('admin/settings', $data);
        $this->load->view('admin/templates/footer');
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
        if ($this->input->method() !== 'post') {
            redirect('admin/user_detail/' . $id);
            return;
        }

        $this->load->model('Admin_model');
        $id = (int) $id;

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

        // Upline resolution
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
            $this->Admin_model->update_parent_id($id, $upline->id);
        }

        $this->Admin_model->update_user_profile($id, [
            'username'    => $username,
            'phone'       => $phone,
            'invite_code' => $invite_code,
        ]);

        $this->session->set_flashdata('success', 'Profil user berhasil diperbarui.');
        redirect('admin/user_detail/' . $id);
    }

    public function toggle_ban($id)
    {
        if ($this->input->method() !== 'post') {
            redirect('admin/user_detail/' . $id);
            return;
        }
        $this->load->model('Admin_model');
        $new_state = $this->Admin_model->toggle_ban($id);

        if ($new_state === FALSE) {
            $this->session->set_flashdata('error', 'User tidak ditemukan.');
        } elseif ($new_state) {
            $this->session->set_flashdata('success', 'User berhasil DIBANNED.');
        } else {
            $this->session->set_flashdata('success', 'User berhasil di-UNBAN.');
        }
        redirect('admin/user_detail/' . $id);
    }

    public function inject_balance($id)
    {
        if ($this->input->method() !== 'post') {
            redirect('admin/user_detail/' . $id);
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

        if ($this->Admin_model->inject_balance($id, $type, $amount, $desc)) {
            $label = strtoupper($type);
            $this->session->set_flashdata('success', "Balance {$label}: Rp " . number_format($amount, 0, ',', '.') . " berhasil.");
        } else {
            $this->session->set_flashdata('error', 'Gagal inject balance.');
        }
        redirect('admin/user_detail/' . $id);
    }

    public function inject_rental($id)
    {
        if ($this->input->method() !== 'post') {
            redirect('admin/user_detail/' . $id);
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

        if ($this->Admin_model->inject_rental($id, $product_id)) {
            $this->session->set_flashdata('success', 'Rental berhasil di-inject (BYPASS balance).');
        } else {
            $this->session->set_flashdata('error', 'Gagal inject rental.');
        }
        redirect('admin/user_detail/' . $id);
    }

    public function cancel_rental($rental_id)
    {
        if ($this->input->method() !== 'post') {
            redirect('admin/users');
            return;
        }

        $this->load->model('Admin_model');
        $rental = $this->db->where('id', $rental_id)->get('user_rentals')->row();

        if (!$rental) {
            $this->session->set_flashdata('error', 'Rental tidak ditemukan.');
            redirect('admin/users');
            return;
        }

        $this->Admin_model->cancel_rental($rental_id);
        $this->session->set_flashdata('success', 'Rental #' . $rental_id . ' berhasil dicancel.');
        redirect('admin/user_detail/' . $rental->user_id);
    }

    public function adjust_time($rental_id)
    {
        if ($this->input->method() !== 'post') {
            redirect('admin/users');
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

        $this->Admin_model->adjust_rental_time($rental_id, $last_claimed_at, $days_processed);
        $this->session->set_flashdata('success', 'Rental #' . $rental_id . ' — Time Travel berhasil!');
        redirect('admin/user_detail/' . $rental->user_id);
    }

    // ===================================================================
    //  CREATE NEW USER (Admin Bypass Referral)
    // ===================================================================

    public function create_user()
    {
        if ($this->input->method() !== 'post') {
            redirect('admin/users');
            return;
        }

        $this->load->model('Admin_model');

        $this->form_validation->set_rules('phone', 'Phone', 'required|trim|is_unique[users.phone]');
        $this->form_validation->set_rules('password', 'Password', 'required|min_length[8]');

        if ($this->form_validation->run() === FALSE) {
            $this->session->set_flashdata('error', validation_errors());
            redirect('admin/users');
            return;
        }

        $phone       = $this->_normalize_phone($this->input->post('phone', TRUE));
        $_POST['phone'] = $phone;
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

        $user_id = $this->Admin_model->create_user([
            'phone'       => $phone,
            'password'    => password_hash($password, PASSWORD_DEFAULT),
            'invite_code' => $invite_code,
            'parent_id'   => $parent_id,
            'role'        => 'user',
            'is_banned'   => 0,
            'balance'     => 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        if ($user_id) {
            $this->session->set_flashdata('success', "Pengguna berhasil dibuat! Invite Code: {$invite_code}");
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
        if ($this->input->method() !== 'post') {
            redirect('admin/users');
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

        if ($this->Admin_model->force_reset_password($user_id, $hashed)) {
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

        $this->Admin_model->set_setting('is_registration_open', $new_value);

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'success' => true,
                'is_open' => ($new_value === '1'),
                'message' => ($new_value === '1') ? 'Pendaftaran dibuka' : 'Pendaftaran ditutup'
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
    //  PHASE 9C: NATIVE CSV EXPORT
    // ===================================================================

    public function export_csv($type = '')
    {
        if (!$this->session->userdata('admin_logged_in')) {
            redirect('admin_auth');
        }

        $allowed = ['ledger', 'rentals', 'withdrawals'];
        if (!in_array($type, $allowed)) {
            show_404();
        }

        $this->load->model('Admin_model');
        $date = date('Y-m-d');

        switch ($type) {
            case 'ledger':
                $data     = $this->Admin_model->get_all_ledger();
                $filename = 'wallet_ledger';
                $headers  = ['ID', 'User ID', 'Amount', 'Type', 'Description', 'Created At'];
                break;

            case 'rentals':
                $data     = $this->Admin_model->get_active_rentals();
                $filename = 'active_rentals';
                $headers  = ['ID', 'User ID', 'Product Name', 'Purchase Price', 'Daily ROI',
                             'Days Processed', 'Total Days', 'Status', 'Created At'];
                break;

            case 'withdrawals':
                $data     = $this->Admin_model->get_all_withdrawals();
                $filename = 'withdrawals';
                $headers  = ['ID', 'User ID', 'Amount', 'Bank Name', 'Account Number',
                             'Status', 'Created At'];
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

        foreach ($data as $row) {
            if (isset($row['created_at'])) {
                $row['created_at'] = date('Y-m-d H:i', strtotime($row['created_at']));
            }
            fputcsv($fp, $row);
        }

        fclose($fp);
        exit;
    }

}
