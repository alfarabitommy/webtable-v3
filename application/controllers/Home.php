<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Home extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('User_model');
        $this->load->model('Rental_model');
        // plan/112: konfigurasi + status absensi harian (widget dashboard).
        $this->load->model('Checkin_model');
    }

    public function index() {
        $user_id = $this->session->userdata('user_id');
        
        // Fetch full user data for the home dashboard
        $user = $this->User_model->get_user_by_id($user_id);

        // Plan 89: statistik sewa derived (user_rentals) untuk (a) peringatan
        // upline inaktif — Condition B (lifetime > 0 && active == 0), dan
        // (b) gating kode undangan — Condition A (lifetime == 0).
        // Plan 91 (K1): promotor (users.is_promoter=1) BYPASS Condition A —
        // kode undangan dashboard terbuka permanen walau lifetime == 0.
        $rental_stats = $this->Rental_model->get_user_rental_stats($user_id);
        $lifetime = (int) $rental_stats['lifetime_rentals'];
        $active   = (int) $rental_stats['active_rentals'];
        $is_promoter = ((int) ($user->is_promoter ?? 0)) === 1;

        // Plan 91: kartu ringkas Program Promotor di dashboard (promotor saja).
        $promoter_available = null;
        if ($is_promoter) {
            $this->load->model('Promoter_model');
            $promoter_available = $this->Promoter_model->get_omzet_summary($user_id)['available'];
        }

        // plan/115: promo produk trial untuk member yang BELUM PERNAH menyewa
        // (lifetime_rentals === 0) — gerbang SERVER. HTML modal hanya dirender
        // bila produk trial aktif berharga 0 benar-benar ada di DB (fail-closed:
        // produk dimatikan / harganya diubah admin → promo hilang sendiri).
        // Seluruh nominal DIHITUNG di server (L6/M8: integer murni, tanpa float);
        // kamus i18n tidak pernah memuat angka. Dipanggil HANYA di cabang ini →
        // user yang sudah pernah menyewa tidak menambah query apa pun.
        $trial_promo = null;
        if ($lifetime === 0) {
            $this->load->model('Product_model');
            $trial = $this->Product_model->get_active_trial_product();

            if ($trial) {
                $days  = max(1, (int) $trial['duration_days']);
                $daily = (int) $trial['daily_rate'];

                $trial_promo = [
                    // O1 (plan/115, disetujui owner): scope gerbang klien
                    // per-user → localStorage `trial_popup_dismissed_<user_id>`,
                    // sehingga perangkat bersama tidak menekan promo user lain.
                    'user_id' => (int) $user_id,
                    'days'    => $days,
                    'daily'   => $daily,
                    'total'   => $daily * $days,
                ];
            }
        }

        $data = [
            'page_title'           => lang('home_page_title'),
            'user'                 => $user,
            // Warning modal dashboard: pernah menyewa tapi 0 kontrak aktif.
            'inactive_warning'     => ($lifetime > 0 && $active === 0),
            // Gating kode undangan di kartu identitas (konsisten /team).
            'referral_locked'      => ($lifetime === 0 && !$is_promoter),
            'is_promoter'          => $is_promoter,
            'promoter_available'   => $promoter_available,
            // plan/115: NULL → view TIDAK merender modal sama sekali.
            'trial_promo'          => $trial_promo,
            // plan/112: status absensi harian (read-only). `enabled=false` →
            // view TIDAK merender widget sama sekali (guard di view).
            'checkin'              => $this->Checkin_model->get_status($user_id),
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('home/index', $data);
        $this->load->view('templates/bottom_nav');
    }
}
