<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Home extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('User_model');
        $this->load->model('Rental_model');
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

        $data = [
            'page_title'           => 'Dashboard',
            'user'                 => $user,
            // Warning modal dashboard: pernah menyewa tapi 0 kontrak aktif.
            'inactive_warning'     => ($lifetime > 0 && $active === 0),
            // Gating kode undangan di kartu identitas (konsisten /team).
            'referral_locked'      => ($lifetime === 0 && !$is_promoter),
            'is_promoter'          => $is_promoter,
            'promoter_available'   => $promoter_available,
        ];

        $this->load->view('templates/header', $data);
        $this->load->view('home/index', $data);
        $this->load->view('templates/bottom_nav');
    }
}
