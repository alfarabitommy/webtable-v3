<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Notification extends MY_Controller {

    public function __construct() {
        parent::__construct();
        // M9/P7 (plan/76): choke-point JSON helper — envelope terstandar.
        $this->load->helper('api');
    }

    /**
     * GET /notification — Full history page (paginated, P3 plan/80)
     * 15 item per halaman, query string `per_page` (parity Admin::users),
     * link CI pagination diberi kelas Tailwind adaptif light/dark.
     */
    public function index() {
        $user_id = $this->session->userdata('user_id');

        $per_page = 15;
        $offset   = max(0, (int) $this->input->get('per_page', TRUE));

        $total  = $this->Notification_model->count_by_user($user_id);
        $notifs = $this->Notification_model->get_by_user($user_id, $per_page, $offset);

        $this->load->library('pagination');
        // Kelas diterapkan lewat 'attributes' → menempel LANGSUNG pada <a> yang
        // di-generate library. BUKAN lewat num/prev/next_tag_open: CI3 sudah
        // membungkus <a href> sendiri, sehingga <a> pada tag_open menghasilkan
        // nested <a><a> (browser memecah → pill kosong + nomor tanpa gaya).
        $link_class = 'px-3 py-1.5 text-sm rounded-lg border border-slate-200 dark:border-slate-700 '
                    . 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors';
        $config = [
            'base_url'             => site_url('notification'),
            'total_rows'           => $total,
            'per_page'             => $per_page,
            'page_query_string'    => TRUE,
            'query_string_segment' => 'per_page',
            // Kelas Tailwind untuk SEMUA <a> link (num/prev/next/first/last).
            'attributes'           => ['class' => $link_class],
            // Halaman aktif eksplisit (offset). Catatan: CI3 create_links()
            // menimpa nilai ini dari GET segment di mode page_query_string;
            // saat param absen library membaca NULL — ditangani cast (string)
            // di Pagination.php:526 (PHP 8.1+/8.3 deprecation fix).
            'cur_page'             => $offset,
            'full_tag_open'        => '<nav class="flex items-center justify-center gap-1 mt-6" aria-label="Navigasi halaman">',
            'full_tag_close'       => '</nav>',
            // Tag num/prev/next dibiarkan kosong (default '') — tanpa wrapper
            // ekstra; anchor dari library yang jadi "pill"-nya.
            'num_tag_open'         => '',
            'num_tag_close'        => '',
            'cur_tag_open'         => '<span class="px-3 py-1.5 text-sm rounded-lg bg-indigo-600 text-white font-medium">',
            'cur_tag_close'        => '</span>',
            'next_link'            => '&raquo;',
            'next_tag_open'        => '',
            'next_tag_close'       => '',
            'prev_link'            => '&laquo;',
            'prev_tag_open'        => '',
            'prev_tag_close'       => '',
        ];
        $this->pagination->initialize($config);

        $data = [
            'page_title'    => 'Notifikasi',
            'notifications' => $notifs,
            'total'         => $total,
            'per_page'      => $per_page,
            'pagination'    => $this->pagination->create_links(),
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('notification/index', $data);
        $this->load->view('templates/bottom_nav');
    }

    /**
     * AJAX POST /notification/mark_all_read
     *
     * M9/P7 (plan/76 Batch A): envelope {success, message, data:{unread_count}}
     * + key legacy root `unread_count`; unauthenticated -> HTTP 401 JSON
     * (dulu 200 {success:false,error}). Konsumen (notification/index.php,
     * templates/header.php) hanya membaca `success` — tanpa perubahan view.
     */
    public function mark_all_read() {
        $user_id = $this->session->userdata('user_id');
        if (!$user_id) {
            api_error('Sesi habis. Silakan login ulang.', 401, [], 'unauthenticated', ['error' => 'Unauthorized']);
        }

        $this->Notification_model->mark_read($user_id);
        api_success(['unread_count' => 0], '', 200, ['unread_count' => 0]);
    }
}
