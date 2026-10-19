<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Spin_model — plan/121 (LUCKY SPIN WHEEL / Referral Reward System).
 *
 * Tanggung jawab:
 *   1. Konfigurasi roda (get_config)      : `system_settings` di atas fallback
 *      application/config/spin_wheel.php — validasi per-key lewat helper
 *      `spin_wheel` (parse/valid/encode), fail-closed bila segmen rusak.
 *   2. Validator setelan admin (validate_settings) — kontrak sama dengan
 *      Checkin_model::validate_checkin_settings / User_model::
 *      validate_milestone_settings (pesan 100% Indonesia, L1).
 *   3. Status halaman (get_summary)       : read-only (counter tiket, jumlah
 *      grant, jumlah spin, histori 10 spin terakhir, segmen siap render).
 *   4. Perolehan tiket (award_ticket_for_first_paid_rental) : CALLER-TX
 *      PARTICIPANT — dipanggil `Rental_model::checkout_rental()` (langkah 7b)
 *      DI DALAM TX yang sama; tidak pernah membuka TX sendiri.
 *   5. Jalur uang (spin)                  : satu TX terkunci + SATU kredit ke
 *      dompet `main` lewat `Wallet_model::credit()` (SP1) — tidak ada mutasi
 *      saldo di jalur lain.
 *
 * Invarian penting (plan/121 §2):
 *   - SP2: maksimal 1 tiket per downline SEUMUR HIDUP. Dijaga UNIQUE
 *     `spin_ticket_grants.uk_spin_grant_downline` + pre-check terserialisasi
 *     oleh anchor `users … FOR UPDATE` milik checkout.
 *   - SP3: hanya kontrak NON-trial (`gpu_products.is_trial = 0`) yang memicu
 *     grant; jalur trial gratis tidak pernah sampai ke sini.
 *   - SP4/SP5: pemilihan segmen SELALU di server — `random_int()` (CSPRNG) +
 *     `spin_wheel_pick()` (fungsi murni). Klien tidak mengirim apa pun.
 *   - SP6: tiket BUKAN uang (tanpa `wallet_ledger`); hadiah ADALAH uang
 *     (`wallet_type = 'main'`, satu baris `SPIN-{spin_results.id}` per spin).
 *   - SP7: `spin_wheel_enabled = '0'` → `disabled`; segmen rusak →
 *     `not_configured`. Tidak pernah ada hadiah "diam-diam".
 *   - SP12: kegagalan grant di dalam checkout → return false → pemanggil
 *     ROLLBACK seluruh TX (tidak ada grant parsial).
 *
 * Otoritas angka:
 *   `users.spin_tickets` = CACHE. Otoritatif = COUNT(spin_ticket_grants) −
 *   COUNT(spin_results); pemeriksaan drift ada di
 *   `scripts/migrate_121_lucky_spin.php --verify` (SP6).
 */
class Spin_model extends CI_Model {

    /** Prefix transaction_id ledger hadiah (jejak: SPIN-{spin_results.id}). */
    const TX_PREFIX = 'SPIN-';

    /** Jumlah baris histori yang dirender di halaman member. */
    const HISTORY_LIMIT = 10;

    /** @var array|null Cache konfigurasi per-request. */
    private static $_cfg = null;

    public function __construct() {
        parent::__construct();
    }

    // =====================================================================
    //  1. KONFIGURASI (setelan dinamis + fallback per-key)
    // =====================================================================

    /**
     * Konfigurasi roda yang sudah tervalidasi & koheren.
     *
     * `configured = false` berarti segmen tidak dapat dipakai (baris dinamis
     * rusak DAN fallback rusak) → seluruh jalur uang fail-closed
     * (`not_configured`), UI tidak merender roda.
     *
     * @return array{enabled:bool,segments:array<int,array{amount:int,weight:int}>,
     *               total_weight:int,odds_bp:array<int,int>,expected_value:int,
     *               configured:bool}
     */
    public function get_config() {
        if (self::$_cfg !== null) {
            return self::$_cfg;
        }

        $fallback = require APPPATH . 'config/spin_wheel.php';

        $map = [];
        $rows = $this->db->query(
            "SELECT key_name, key_value FROM system_settings
              WHERE key_name IN ('spin_wheel_enabled', 'spin_wheel_segments')"
        )->result();

        foreach ($rows as $row) {
            $map[$row->key_name] = $row->key_value;
        }

        self::$_cfg = $this->_resolve_config(is_array($fallback) ? $fallback : [], $map);
        return self::$_cfg;
    }

    /**
     * Gabungkan baris dinamis di atas fallback dengan validasi per-key.
     * Nilai rusak → fallback key tersebut + log (tidak pernah fatal).
     */
    private function _resolve_config(array $fallback, array $map) {
        // ── Toggle fitur (fail-closed bila baris hilang). ────────────────
        $enabled = ((int) ($fallback['spin_wheel_enabled'] ?? 0)) === 1;

        if (array_key_exists('spin_wheel_enabled', $map)) {
            $raw = (string) $map['spin_wheel_enabled'];
            if ($raw === '0' || $raw === '1') {
                $enabled = ($raw === '1');
            } else {
                log_message('error', 'Spin_model: spin_wheel_enabled tidak valid (' . $raw
                    . ') — fallback dipakai');
            }
        }

        // ── Segmen roda: dinamis → fallback → kosong (fail-closed). ─────
        $segments   = null;
        $configured = false;

        if (array_key_exists('spin_wheel_segments', $map)) {
            $parsed = spin_wheel_segments_parse($map['spin_wheel_segments']);
            if ($parsed !== null && spin_wheel_segments_valid($parsed)) {
                $segments   = $parsed;
                $configured = true;
            } else {
                log_message('error', 'Spin_model: spin_wheel_segments tidak valid ('
                    . substr((string) $map['spin_wheel_segments'], 0, 120) . ') — fallback dipakai');
            }
        }

        if (!$configured) {
            $fb = spin_wheel_segments_parse($fallback['spin_wheel_segments'] ?? null);
            if ($fb !== null && spin_wheel_segments_valid($fb)) {
                $segments   = $fb;
                $configured = true;
            } else {
                log_message('error', 'Spin_model: fallback spin_wheel.php tidak valid — '
                    . 'roda tidak dapat dipakai (fail-closed, plan/121 SP7)');
                $segments = [];
            }
        }

        return [
            'enabled'        => $enabled,
            'segments'       => $segments,
            'total_weight'   => $configured ? spin_wheel_total_weight($segments) : 0,
            'odds_bp'        => $configured ? spin_wheel_odds_bp($segments) : [],
            'expected_value' => $configured ? spin_wheel_expected_value($segments) : 0,
            'configured'     => $configured,
        ];
    }

    // =====================================================================
    //  2. VALIDATOR SETELAN ADMIN (pesan 100% Indonesia — L1)
    // =====================================================================

    /**
     * Normalisasi + validasi input form admin (/admin/lucky-spin).
     *
     * Transport yang diterima: array baris `spin_amount[]` / `spin_weight[]`
     * (pola plan/110/119 — baris yang belum valid tetap terkirim sehingga
     * form dapat direpopulasi), ATAU string JSON kanonik `spin_wheel_segments`
     * (jalur kompatibilitas/KO).
     *
     * @param array $raw Map key system_settings → nilai mentah $_POST.
     * @return array{ok:bool,errors:string[],notices:string[],
     *               field_errors:array<string,string[]>,values:array<string,string>}
     */
    public function validate_settings(array $raw) {
        $errors       = [];
        $field_errors = [];
        $values       = [];
        $lim          = spin_wheel_limits();

        // Toggle (semantik checkbox: tidak dikirim = OFF).
        $values['spin_wheel_enabled'] =
            (isset($raw['spin_wheel_enabled']) && !empty($raw['spin_wheel_enabled'])) ? '1' : '0';

        $amounts = isset($raw['spin_amount']) && is_array($raw['spin_amount']) ? $raw['spin_amount'] : null;
        $weights = isset($raw['spin_weight']) && is_array($raw['spin_weight']) ? $raw['spin_weight'] : null;

        $segments = [];
        $row_errors = [];

        if ($amounts !== null && $weights !== null) {
            // ── Transport array baris (UI utama). ────────────────────────
            $count = max(count($amounts), count($weights));
            for ($i = 0; $i < $count; $i++) {
                $amount_raw = array_key_exists($i, $amounts) ? $amounts[$i] : '';
                $weight_raw = array_key_exists($i, $weights) ? $weights[$i] : '';

                $a_str = is_scalar($amount_raw) ? trim((string) $amount_raw) : '';
                $w_str = is_scalar($weight_raw) ? trim((string) $weight_raw) : '';

                // Baris kosong = diabaikan (admin boleh meninggalkan slot kosong).
                if ($a_str === '' && $w_str === '') {
                    continue;
                }

                $amount = spin_wheel_int_token($a_str);
                $weight = spin_wheel_int_token($w_str);
                $row_no = $i + 1;
                $row_ok = true;

                if ($amount === null || $amount < 1 || $amount > $lim['max_amount']) {
                    $message = 'Baris ' . $row_no . ': nominal hadiah harus angka bulat 1–'
                        . number_format($lim['max_amount'], 0, ',', '.') . ' (IDR).';
                    $errors[] = $message;
                    $row_errors['spin_row_' . $row_no][] = $message;
                    $row_ok = false;
                }

                if ($weight === null || $weight < 1 || $weight > $lim['max_weight']) {
                    $message = 'Baris ' . $row_no . ': bobot harus angka bulat 1–'
                        . number_format($lim['max_weight'], 0, ',', '.') . '.';
                    $errors[] = $message;
                    $row_errors['spin_row_' . $row_no][] = $message;
                    $row_ok = false;
                }

                if ($row_ok) {
                    $segments[] = ['amount' => $amount, 'weight' => $weight];
                }
            }
        } else {
            // ── Jalur kompatibilitas: string JSON kanonik. ───────────────
            $parsed = spin_wheel_segments_parse($raw['spin_wheel_segments'] ?? null);
            if ($parsed === null) {
                $message = 'Konfigurasi segmen tidak dapat dibaca (format JSON tidak valid).';
                $errors[] = $message;
                $field_errors['spin_wheel_segments'][] = $message;
            } else {
                $segments = $parsed;
            }
        }

        // Jumlah segmen + total bobot (invarian roda).
        $count_seg = count($segments);
        if ($count_seg < $lim['min_segments']) {
            $message = 'Roda harus memiliki minimal ' . $lim['min_segments'] . ' segmen hadiah.';
            $errors[] = $message;
            $field_errors['spin_wheel_segments'][] = $message;
        } elseif ($count_seg > $lim['max_segments']) {
            $message = 'Roda maksimal ' . $lim['max_segments'] . ' segmen.';
            $errors[] = $message;
            $field_errors['spin_wheel_segments'][] = $message;
        }

        $total_weight = spin_wheel_total_weight($segments);
        if ($total_weight > $lim['max_total_weight']) {
            $message = 'Total bobot maksimal ' . number_format($lim['max_total_weight'], 0, ',', '.') . '.';
            $errors[] = $message;
            $field_errors['spin_wheel_segments'][] = $message;
        }

        if (count($errors) === 0 && spin_wheel_segments_valid($segments)) {
            $values['spin_wheel_segments'] = spin_wheel_segments_encode($segments);
        }

        return [
            'ok'           => count($errors) === 0,
            'errors'       => $errors,
            'notices'      => [],
            'field_errors' => array_merge($field_errors, $row_errors),
            'values'       => $values,
        ];
    }

    // =====================================================================
    //  3. STATUS HALAMAN (read-only)
    // =====================================================================

    /**
     * Ringkasan roda untuk satu user (read-only, maks 3 query + 1 histori).
     *
     * `can_spin` = gabungan seluruh gate (fitur aktif, konfigurasi valid,
     * tiket ≥ 1). View memakai flag ini untuk merender tombol disabled,
     * bukan menebak dari angka.
     *
     * @param int $user_id
     * @return array{enabled:bool,configured:bool,tickets:int,earned:int,used:int,
     *               can_spin:bool,total_weight:int,expected_value:int,
     *               segments:array<int,array{index:int,amount:int,odds_bp:int}>,
     *               history:array<int,array{slot_index:int,amount:int,created_at:string}>,
     *               server_time:string}
     */
    public function get_summary($user_id) {
        $cfg = $this->get_config();

        $out = [
            'enabled'        => $cfg['enabled'],
            'configured'     => $cfg['configured'],
            'tickets'        => 0,
            'earned'         => 0,
            'used'           => 0,
            'can_spin'       => false,
            'total_weight'   => $cfg['total_weight'],
            'expected_value' => $cfg['expected_value'],
            'segments'       => [],
            'history'        => [],
            'server_time'    => date('Y-m-d H:i:s'),
        ];

        // Fail-closed: fitur OFF / konfigurasi rusak → tidak ada state dibaca.
        if (!$cfg['enabled'] || !$cfg['configured']) {
            return $out;
        }

        // Segmen siap render (indeks = posisi roda; peluang dari server, L6).
        foreach ($cfg['segments'] as $i => $seg) {
            $out['segments'][] = [
                'index'   => $i,
                'amount'  => (int) $seg['amount'],
                'odds_bp' => (int) ($cfg['odds_bp'][$i] ?? 0),
            ];
        }

        $row = $this->db->query(
            "SELECT
               (SELECT COALESCE(spin_tickets, 0) FROM users WHERE id = ?) AS tickets,
               (SELECT COUNT(*) FROM spin_ticket_grants WHERE upline_id = ?) AS earned,
               (SELECT COUNT(*) FROM spin_results WHERE user_id = ?) AS used",
            [(int) $user_id, (int) $user_id, (int) $user_id]
        )->row();

        if ($row) {
            $out['tickets'] = (int) $row->tickets;
            $out['earned']  = (int) $row->earned;
            $out['used']    = (int) $row->used;
        }

        $out['can_spin'] = ($out['tickets'] > 0);

        $history = $this->db->query(
            "SELECT slot_index, prize_amount, created_at
               FROM spin_results
              WHERE user_id = ?
              ORDER BY id DESC
              LIMIT " . (int) self::HISTORY_LIMIT,
            [(int) $user_id]
        )->result();

        foreach ($history as $h) {
            $out['history'][] = [
                'slot_index' => (int) $h->slot_index,
                'amount'     => (int) $h->prize_amount,
                'created_at' => (string) $h->created_at,
            ];
        }

        return $out;
    }

    // =====================================================================
    //  4. PEROLEHAN TIKET (caller-TX participant — SP2/SP3/SP12)
    // =====================================================================

    /**
     * Beri 1 tiket kepada upline L1 pembeli bila ini pembelian NON-trial
     * PERTAMA downline tersebut.
     *
     * WAJIB dipanggil DI DALAM TX `Rental_model::checkout_rental()` (langkah
     * 7b) SETELAH kontrak dibuat, SEBELUM commit — tidak pernah membuka TX
     * sendiri dan tidak pernah commit/rollback sendiri.
     *
     * Urutan (tidak mengubah kelas/urutan lock TX pemanggil — baris `users`
     * pembeli sudah terkunci `FOR UPDATE` oleh `lock_and_get_balances()`):
     *   1. baca `parent_id` pembeli → NULL/self ⇒ no-op sukses;
     *   2. baca upline → hilang/`is_banned = 1` ⇒ log + no-op sukses
     *      (pembelian TIDAK pernah digagalkan oleh upline yang rusak);
     *   3. pre-check `spin_ticket_grants.downline_id` → ada ⇒ no-op sukses
     *      (pembelian kedua dan seterusnya — jalur normal);
     *   4. INSERT grant (UNIQUE `downline_id` = backstop tingkat DB);
     *   5. `UPDATE users SET spin_tickets = spin_tickets + 1` + guard
     *      `affected_rows() === 1` (M4) — gagal ⇒ false;
     *   6. notifikasi ber-key di TX yang sama (pola M5).
     *
     * @param int $buyer_id  Downline yang baru checkout.
     * @param int $rental_id Kontrak pemicu (audit).
     * @return bool true = sukses/no-op; false = gagal → pemanggil rollback.
     */
    public function award_ticket_for_first_paid_rental($buyer_id, $rental_id) {
        $buyer_id  = (int) $buyer_id;
        $rental_id = (int) $rental_id;

        // 1. Parent (upline L1) pembeli.
        $buyer = $this->db->query(
            "SELECT id, parent_id FROM users WHERE id = ?",
            [$buyer_id]
        )->row();

        if (!$buyer) {
            // Defensif — anchor users sudah diverifikasi oleh checkout.
            log_message('error', 'Spin_model::award_ticket — pembeli (id=' . $buyer_id
                . ') tidak ditemukan saat rental ' . $rental_id);
            return true;
        }

        if ($buyer->parent_id === null) {
            return true;   // tanpa upline → tidak ada penerima tiket
        }

        $upline_id = (int) $buyer->parent_id;
        if ($upline_id === $buyer_id) {
            log_message('error', 'Spin_model::award_ticket — referensi diri (user ' . $buyer_id
                . ') saat rental ' . $rental_id . ' — grant dilewati (fail-closed)');
            return true;
        }

        // 2. Upline harus ada & tidak diblokir (A1: aktivitas TIDAK disyaratkan).
        $upline = $this->db->query(
            "SELECT id, is_banned FROM users WHERE id = ?",
            [$upline_id]
        )->row();

        if (!$upline) {
            log_message('error', 'Spin_model::award_ticket — upline (id=' . $upline_id
                . ') tidak ditemukan saat rental ' . $rental_id . ' — grant dilewati');
            return true;
        }

        if ((int) $upline->is_banned === 1) {
            log_message('debug', 'Spin_model::award_ticket — upline (id=' . $upline_id
                . ') diblokir; tiket tidak diberikan (rental ' . $rental_id . ')');
            return true;
        }

        // 3. Hanya pembelian NON-trial PERTAMA seumur hidup downline ini.
        //    Kontrak pemicu SUDAH di-insert pemanggil (langkah 6 checkout), jadi
        //    hitungan 1 = inilah yang pertama. Hitungan > 1 berarti downline ini
        //    sudah pernah membeli sebelumnya (termasuk pembelian PRA-rilis) →
        //    no-op: tanpa backfill retroaktif (keputusan D4) dan tanpa tiket
        //    kedua (SP2). Kontrak reward promotor (`source='promoter_reward'`,
        //    zero-cost — plan/91 K7) BUKAN pembelian, jadi tidak dihitung.
        $paid = $this->db->query(
            "SELECT COUNT(*) AS cnt
               FROM user_rentals ur
               JOIN gpu_products gp ON gp.id = ur.product_id
              WHERE ur.user_id = ?
                AND gp.is_trial = 0
                AND ur.source <> 'promoter_reward'",
            [$buyer_id]
        )->row();

        if (!$paid || (int) $paid->cnt > 1) {
            return true;   // bukan pembelian berbayar pertama → tanpa tiket
        }

        // 4. Idempotensi lapis aplikasi (serialisasi: baris pembeli terkunci).
        $existing = $this->db->query(
            "SELECT id FROM spin_ticket_grants WHERE downline_id = ?",
            [$buyer_id]
        )->row();

        if ($existing) {
            return true;   // downline ini sudah pernah menghasilkan tiket
        }

        // 5. Grant (UNIQUE downline_id = guard struktural SP2).
        $inserted = $this->db->insert('spin_ticket_grants', [
            'upline_id'   => $upline_id,
            'downline_id' => $buyer_id,
            'rental_id'   => ($rental_id > 0) ? $rental_id : null,
        ]);

        if (!$inserted) {
            log_message('error', 'Spin_model::award_ticket — insert spin_ticket_grants gagal '
                . '(upline=' . $upline_id . ', downline=' . $buyer_id . ', rental=' . $rental_id . ')');
            return false;
        }

        // 6. Counter tiket upline (relative + guard affected_rows, M4).
        $this->db->query(
            "UPDATE users SET spin_tickets = spin_tickets + 1 WHERE id = ?",
            [$upline_id]
        );

        if ($this->db->affected_rows() !== 1) {
            log_message('error', 'Spin_model::award_ticket — update counter tiket gagal '
                . '(upline=' . $upline_id . ', affected_rows=' . $this->db->affected_rows() . ')');
            return false;
        }

        // 7. Notifikasi ber-key (dwi-bahasa, dirender per idiom pembaca).
        $this->load->model('Notification_model');
        $this->Notification_model->insert_keyed($upline_id, 'notif_spin_ticket', [], 'info');

        return true;
    }

    // =====================================================================
    //  5. JALUR UANG: SPIN (satu TX + satu kredit ke dompet main)
    // =====================================================================

    /**
     * Putar roda: potong 1 tiket, tentukan hadiah di SERVER, kredit dompet
     * `main`, catat jejak audit — seluruhnya dalam satu TX.
     *
     * Urutan wajib di dalam TX (pola C5 + Checkin_model::claim):
     *   1. anchor `SELECT … FROM users … FOR UPDATE` (statement pertama),
     *   2. konfigurasi SEGAR (enabled/segmen) → gate `disabled`/`not_configured`,
     *   3. gate `no_tickets` (baca tiket dari baris TERKUNCI),
     *   4. UPDATE kondisional `spin_tickets > 0` + `affected_rows() === 1` (M4),
     *   5. CSPRNG `random_int()` + `spin_wheel_pick()` (murni) → indeks segmen,
     *   6. INSERT `spin_results` (snapshot bobot) → `insert_id()` → id ledger,
     *   7. SATU kredit `Wallet_model::credit()` dompet `main`, tx id
     *      `SPIN-{spin_id}` (deterministik, bebas tabrakan),
     *   8. backfill `transaction_id` pada baris audit + notifikasi ber-key.
     *
     * @param int $user_id
     * @return array{success:bool,code:string,message:string,slot_index:int,
     *               prize:int,transaction_id:string,wallet_type:string,
     *               tickets_left:int,new_balance:int}
     *   code: 'ok' | 'disabled' | 'not_configured' | 'no_tickets'
     *         | 'user_unavailable' | 'error'
     */
    public function spin($user_id) {
        $user_id = (int) $user_id;

        // Model dompet dimuat eksplisit (pola Checkin_model) — `spin()` wajib
        // memakai `Wallet_model::credit()` sebagai SATU-SATUNYA jalur kredit.
        $this->load->model('Wallet_model');

        $fail = function ($code, $message) {
            return [
                'success'        => false,
                'code'           => $code,
                'message'        => $message,
                'slot_index'     => -1,
                'prize'          => 0,
                'transaction_id' => '',
                'wallet_type'    => Wallet_model::WALLET_MAIN,
                'tickets_left'   => 0,
                'new_balance'    => 0,
            ];
        };

        $this->db->trans_begin();

        try {
            // 1. Anchor lock — serialisasi double-submit/paralel (M4/C5).
            $user = $this->db->query(
                "SELECT id, spin_tickets FROM users WHERE id = ? FOR UPDATE",
                [$user_id]
            )->row();

            if (!$user) {
                $this->db->trans_rollback();
                return $fail('user_unavailable', 'Sistem: Akun tidak ditemukan.');
            }

            // 2. Konfigurasi SEGAR (dibaca setelah lock; memoized per request).
            $cfg = $this->get_config();

            if (!$cfg['enabled']) {
                $this->db->trans_rollback();
                return $fail('disabled', 'Sistem: Fitur Lucky Spin sedang tidak aktif.');
            }

            if (!$cfg['configured'] || $cfg['total_weight'] < 1) {
                $this->db->trans_rollback();
                log_message('error', 'Spin_model::spin — konfigurasi segmen tidak valid '
                    . '(user=' . $user_id . ')');
                return $fail('not_configured', 'Sistem: Konfigurasi roda hadiah belum siap.');
            }

            // 3. Gate tiket (dibaca dari baris TERKUNCI).
            $tickets = (int) $user->spin_tickets;
            if ($tickets < 1) {
                $this->db->trans_rollback();
                return $fail('no_tickets', 'Anda belum memiliki tiket Lucky Spin.');
            }

            // 4. Pemotongan tiket KONDISIONAL (anti negatif / lost update).
            $this->db->query(
                "UPDATE users SET spin_tickets = spin_tickets - 1
                  WHERE id = ? AND spin_tickets > 0",
                [$user_id]
            );

            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                log_message('error', 'Spin_model::spin — pemotongan tiket gagal (user=' . $user_id
                    . ', affected_rows=' . $this->db->affected_rows() . ')');
                return $fail('no_tickets', 'Anda belum memiliki tiket Lucky Spin.');
            }

            // 5. Randomisasi SERVER-SIDE (CSPRNG + fungsi murni).
            $roll = random_int(1, (int) $cfg['total_weight']);
            $slot = spin_wheel_pick($cfg['segments'], $roll);

            if ($slot === null) {
                $this->db->trans_rollback();
                log_message('error', 'Spin_model::spin — spin_wheel_pick gagal (user=' . $user_id
                    . ', roll=' . $roll . ', total=' . $cfg['total_weight'] . ')');
                return $fail('error', 'Sistem: Gagal memproses spin. Coba lagi.');
            }

            $segment = $cfg['segments'][$slot];
            $prize   = (int) $segment['amount'];

            // D3: setiap segmen ≥ Rp 1 — dijaga validator + `_post()` (amount ≤ 0).
            if ($prize < 1) {
                $this->db->trans_rollback();
                log_message('error', 'Spin_model::spin — segmen bernominal < 1 (user=' . $user_id
                    . ', slot=' . $slot . ') — konfigurasi ditolak');
                return $fail('not_configured', 'Sistem: Konfigurasi roda hadiah belum siap.');
            }

            // 6. Jejak audit (snapshot bobot saat spin — dapat diaudit).
            $inserted = $this->db->insert('spin_results', [
                'user_id'         => $user_id,
                'slot_index'      => (int) $slot,
                'prize_amount'    => $prize,
                'weight_snapshot' => (int) $segment['weight'],
                'total_weight'    => (int) $cfg['total_weight'],
            ]);

            if (!$inserted) {
                $this->db->trans_rollback();
                log_message('error', 'Spin_model::spin — insert spin_results gagal (user=' . $user_id . ')');
                return $fail('error', 'Sistem: Gagal memproses spin. Coba lagi.');
            }

            $spin_id = (int) $this->db->insert_id();
            $tx_id   = self::TX_PREFIX . $spin_id;

            // 7. SATU kredit ke dompet utama (SP1/SP6/SP8).
            $credited = $this->Wallet_model->credit(
                $user_id,
                $prize,
                $tx_id,
                'Hadiah Lucky Spin',
                Wallet_model::WALLET_MAIN
            );

            if (!$credited) {
                $this->db->trans_rollback();
                log_message('error', 'Spin_model::spin — kredit hadiah gagal (user=' . $user_id
                    . ', prize=' . $prize . ', tx=' . $tx_id . ')');
                return $fail('error', 'Sistem: Gagal memproses spin. Coba lagi.');
            }

            // 8. Backfill jejak ledger pada baris audit + guard affected_rows.
            $this->db->query(
                "UPDATE spin_results SET transaction_id = ? WHERE id = ?",
                [$tx_id, $spin_id]
            );

            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                log_message('error', 'Spin_model::spin — backfill transaction_id gagal (spin=' . $spin_id . ')');
                return $fail('error', 'Sistem: Gagal memproses spin. Coba lagi.');
            }

            $this->load->model('Notification_model');
            $this->Notification_model->insert_keyed(
                $user_id,
                'notif_spin_win',
                [number_format($prize, 0, ',', '.')],
                'commission'
            );

            $this->db->trans_commit();

            // Saldo segar dari ledger (bukan users.balance yang mungkin basi).
            $new_balance = $this->Wallet_model->get_balance($user_id);

            return [
                'success'        => true,
                'code'           => 'ok',
                'message'        => '',
                'slot_index'     => (int) $slot,
                'prize'          => $prize,
                'transaction_id' => $tx_id,
                'wallet_type'    => Wallet_model::WALLET_MAIN,
                'tickets_left'   => max(0, $tickets - 1),
                'new_balance'    => (int) $new_balance,
            ];

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Spin_model::spin — ' . $e->getMessage());
            return $fail('error', 'Sistem: Gagal memproses spin. Coba lagi.');
        }
    }
}
