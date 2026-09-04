<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Rental_model extends CI_Model {

    public function __construct() {
        parent::__construct();
        // C2: pastikan timezone Asia/Jakarta untuk seluruh perhitungan tanggal
        // klaim (web di-set di index.php; guard ini mengamankan jalur CLI/cron)
        // — konsisten dengan Rate_limit_model (fix Phase 10B).
        if (date_default_timezone_get() !== 'Asia/Jakarta') {
            date_default_timezone_set('Asia/Jakarta');
        }
        // C5 (plan/48): helper lock_and_get_balance() untuk kunci anchor users
        // + saldo segar otoritatif pada jalur debit checkout. No-op bila sudah
        // di-load MY_Controller; memastikan model mandiri (jalur CLI/cron).
        $this->load->model('Wallet_model');
    }

    /**
     * Insert into user_rentals. Calculate expired_at.
     */
    public function create_rental($user_id, $product_id, $price, $roi, $duration_days) {
        $data = [
            'user_id'         => $user_id,
            'product_id'      => $product_id,
            // M8: snapshot integer IDR bulat.
            'purchase_price'  => (int) $price,
            'daily_roi'       => (int) $roi,
            'status'          => 'active',
            'expired_at'      => date('Y-m-d H:i:s', strtotime("+{$duration_days} days")),
        ];

        if ($this->db->insert('user_rentals', $data)) {
            return $this->db->insert_id();
        }
        return false;
    }

    /**
     * Query user_rentals joined with gpu_products for product name.
     * Strictly returns array of objects.
     */
    public function get_active_rentals($user_id) {
        $this->db->select('user_rentals.*, gpu_products.name as product_name');
        $this->db->from('user_rentals');
        $this->db->join('gpu_products', 'gpu_products.id = user_rentals.product_id', 'left');
        $this->db->where('user_rentals.user_id', $user_id);
        $this->db->where('user_rentals.status', 'active');
        $this->db->order_by('user_rentals.created_at', 'DESC');
        return $this->db->get()->result(); // strictly array of Objects
    }

    /**
     * C5 (plan/48): ACID checkout anti-overspend — debit wallet_ledger +
     * buat user_rentals dalam SATU transaksi terkunci.
     *   1. trans_begin() eksplisit + try/catch (gaya claim_roi, plan/44).
     *   2. lock_and_get_balance() (Wallet_model) — kunci anchor users + saldo
     *      segar otoritatif sebagai statement pertama (serialisasi semua
     *      debit per-user: checkout vs penarikan vs checkout lain).
     *   3. Penolakan overspend STRICT: fresh_balance < price → rollback +
     *      code 'insufficient' (audit C5; pre-check controller hanya UX).
     *   4. Insert debit wallet_ledger + user_rentals (active) → commit.
     *
     * @param int   $user_id
     * @param array $product Produk dari Product_model::get_product()
     *                       (id, name, price, daily_rate, duration_days)
     * @return array{success:bool, code:string, message:string, rental_id:int|null}
     *   code: 'ok' | 'insufficient' | 'error'
     */
    public function checkout_rental($user_id, $product) {
        $this->db->trans_begin();

        try {
            // 1. Kunci anchor users + saldo segar otoritatif (anti-race C5).
            $fresh_balance = $this->Wallet_model->lock_and_get_balance($user_id);

            if ($fresh_balance === false) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'error', 'message' => 'Sistem: Gagal memotong saldo atau membuat kontrak sewa.', 'rental_id' => null];
            }

            // 2. Penolakan overspend STRICT di dalam TX terkunci.
            //    (M8: fresh_balance int & harga produk di-(int) kan.)
            if ($fresh_balance < (int) $product['price']) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'insufficient', 'message' => 'Sistem: Saldo USC/IDR Anda tidak mencukupi.', 'rental_id' => null];
            }

            // 3. Debit via ledger ingestion helper (ledger + cache atomik C4/W3);
            //    kegagalan → rollback seluruh TX (tidak ada kontrak tanpa debit).
            $debited = $this->Wallet_model->debit(
                $user_id,
                (int) $product['price'],
                'RENT-' . $product['id'] . '-' . date('YmdHis'),
                'Sewa ' . $product['name']
            );

            if (!$debited) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'error', 'message' => 'Sistem: Gagal memotong saldo atau membuat kontrak sewa.', 'rental_id' => null];
            }

            // 4. Buat kontrak sewa (dengan expired_at = now + duration_days)
            //    M8: snapshot harga & ROI harian disimpan sebagai integer IDR.
            $this->db->insert('user_rentals', [
                'user_id'        => $user_id,
                'product_id'     => $product['id'],
                'purchase_price' => (int) $product['price'],
                'daily_roi'      => (int) $product['daily_rate'],
                'total_days'     => $product['duration_days'],
                'status'         => 'active',
                'expired_at'     => date('Y-m-d H:i:s', strtotime('+' . $product['duration_days'] . ' days')),
            ]);
            $rental_id = $this->db->insert_id();

            $this->db->trans_commit();

            return ['success' => true, 'code' => 'ok', 'message' => '', 'rental_id' => $rental_id];

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Rental_model::checkout_rental — ' . $e->getMessage());
            return ['success' => false, 'code' => 'error', 'message' => 'Sistem: Gagal memotong saldo atau membuat kontrak sewa.', 'rental_id' => null];
        }
    }

    /**
     * SINGLE SOURCE OF TRUTH untuk matematika klaim ROI (aturan T+1/H+1,
     * akumulasi maks 2 hari, sisa hari kontrak, dan flag status). Dipakai
     * bersama oleh daftar sewa (Rentals::index — display) dan mesin klaim
     * (claim_roi — otoritatif), sehingga logika tidak pernah dobel (P8).
     * Fungsi murni — tanpa akses DB.
     *
     * @param object $rental Baris user_rentals (stdClass) berisi: created_at,
     *                       last_claimed_at, days_processed, total_days,
     *                       expired_at.
     * @return array{
     *   reference_date:string, day_diff:int, claimable_days:int,
     *   remaining_days:int, actual_claimable:int, is_claimed_today:bool,
     *   is_expired:bool, is_completed:bool
     * }
     */
    public function claimable_info($rental) {
        $now = time();

        // Batas kontrak (expired_at) sudah terlewati?
        $is_expired = !empty($rental->expired_at)
            && strtotime($rental->expired_at) <= $now;

        // Seluruh hari kontrak sudah diklaim?
        $is_completed = (int) $rental->days_processed >= (int) $rental->total_days;

        // Referensi perhitungan: klaim terakhir, atau awal kontrak (basis T+1).
        $reference_date = !empty($rental->last_claimed_at)
            ? date('Y-m-d', strtotime($rental->last_claimed_at))
            : date('Y-m-d', strtotime($rental->created_at));

        // Hari kalender penuh sejak referensi (midnight-to-midnight, PHP tz).
        $day_diff = (int) ((strtotime('today') - strtotime($reference_date)) / 86400);

        // Kap akumulasi: maksimal 2 hari (use-it-or-lose-it).
        $claimable_days = max(0, min($day_diff, 2));
        $remaining_days = max(0, (int) $rental->total_days - (int) $rental->days_processed);
        $actual_claimable = min($claimable_days, $remaining_days);

        return [
            'reference_date'   => $reference_date,
            'day_diff'         => $day_diff,
            'claimable_days'   => $claimable_days,
            'remaining_days'   => $remaining_days,
            'actual_claimable' => $actual_claimable,
            'is_claimed_today' => !empty($rental->last_claimed_at)
                && date('Y-m-d', strtotime($rental->last_claimed_at)) === date('Y-m-d'),
            'is_expired'       => $is_expired,
            'is_completed'     => $is_completed,
        ];
    }

    /**
     * C2 (plan/44): mesin klaim ROI — SATU transaksi atomik yang aman
     * terhadap lost-update / double-payout:
     *   1. SELECT ... FOR UPDATE pada baris sewa (scope id + user_id)
     *      → klaim paralel diblokir & membaca state committed (current read).
     *   2. Guard lifecycle: hanya status='active'; expired / hari penuh →
     *      flip atomik 'completed' (lazy close, idempotent) → 0 payout.
     *   3. Matematika klaim via claimable_info() — single source of truth.
     *   4. UPDATE relatif terjaga (days_processed = days_processed + ?);
     *      kredit ledger di-gate STRICT pada affected_rows() === 1.
     *   5. Kredit wallet_ledger dengan ID deterministik ROI-{id}-D{seq}.
     *
     * @param int $rental_id
     * @param int $user_id   Pemilik sewa (scope autentikasi).
     * @return array{code:string, message:string, amount:int, days:int}
     */
    public function claim_roi($rental_id, $user_id) {
        $this->db->trans_begin();

        try {
            // 1. Kunci baris sewa milik user (current read) — serialisasi.
            $rental = $this->db->query(
                "SELECT * FROM user_rentals
                  WHERE id = ? AND user_id = ?
                  FOR UPDATE",
                [$rental_id, $user_id]
            )->row();

            if (!$rental) {
                $this->db->trans_rollback();
                return $this->_claim_result('not_found', 'Sistem: Data sewa tidak ditemukan.');
            }

            // 2. Guard lifecycle I1 — kontrak non-active ditolak mentah-mentah.
            if ($rental->status !== 'active') {
                $this->db->trans_rollback();
                return $this->_claim_result('not_active', 'Sistem: Sewa ini sudah tidak aktif.');
            }

            $info = $this->claimable_info($rental);
            $now  = date('Y-m-d H:i:s'); // PHP Asia/Jakarta — bukan MySQL NOW()

            // 3. Expired (I2) / seluruh hari terklaim (I3) → tutup kontrak
            //    active→completed secara atomik & tolak klaim (0 payout).
            if ($info['is_expired'] || $info['is_completed']) {
                $this->db->query(
                    "UPDATE user_rentals
                        SET status = 'completed'
                      WHERE id = ? AND user_id = ? AND status = 'active'",
                    [$rental_id, $user_id]
                );
                $this->db->trans_commit();

                if ($info['is_expired']) {
                    return $this->_claim_result('expired', 'Sistem: Masa sewa Anda telah berakhir (kontrak ditutup).');
                }
                return $this->_claim_result('completed', 'Sistem: Masa kontrak Anda telah habis.');
            }

            // 4. Tidak ada hari claimable hari ini (I4 — replay / T+1).
            if ($info['actual_claimable'] < 1) {
                $this->db->trans_rollback();
                if (date('Y-m-d', strtotime($rental->created_at)) === date('Y-m-d')) {
                    // Pesan selaras label tombol disabled "Belum Waktunya (H+1)"
                    // (plan/46) — UI & server memakai istilah yang sama.
                    return $this->_claim_result('no_claimable', 'Belum Waktunya (H+1): klaim pertama baru dapat dilakukan keesokan harinya setelah pembelian.');
                }
                return $this->_claim_result('no_claimable', 'Sistem: Anda sudah mengklaim penghasilan hari ini.');
            }

            // 5. UPDATE relatif terjaga — nilai absolut basi mustahil ditulis;
            //    gate kredit: affected_rows() === 1.
            $this->db->query(
                "UPDATE user_rentals
                    SET days_processed = days_processed + ?,
                        last_claimed_at = ?
                  WHERE id = ? AND user_id = ? AND status = 'active'",
                [$info['actual_claimable'], $now, $rental_id, $user_id]
            );

            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                log_message('error', 'C2 guard: klaim rental ' . $rental_id
                    . ' user ' . $user_id . ' — affected_rows=' . $this->db->affected_rows());
                return $this->_claim_result('error', 'Sistem: Gagal memproses klaim. Coba lagi.');
            }

            // 6. Kredit via ledger ingestion helper (ledger + cache atomik C4/W4).
            //    ID deterministik per urutan klaim: ROI-{rental_id}-D{days_processed_setelah_klaim}
            //    (monotonic). Kegagalan → rollback (0 payout, kontrak tidak maju).
            $new_days_processed = (int) $rental->days_processed + $info['actual_claimable'];
            // M8 (plan/74 §2.3): perkalian integer murni — daily_roi (string
            // DECIMAL dari DB) di-(int) kan SEBELUM aritmetika; hasil payout
            // SELALU int (tidak ada koersi float int × "5000.00").
            $payout             = $info['actual_claimable'] * (int) $rental->daily_roi;

            $credited = $this->Wallet_model->credit(
                (int) $rental->user_id,
                (int) $payout,
                'ROI-' . $rental_id . '-D' . $new_days_processed,
                'Klaim ROI ' . $info['actual_claimable'] . ' Hari'
            );

            if (!$credited) {
                $this->db->trans_rollback();
                log_message('error', 'C2 ledger: credit gagal untuk rental ' . $rental_id);
                return $this->_claim_result('error', 'Sistem: Gagal memproses klaim ke database.');
            }

            $this->db->trans_commit();

            $message = 'Berhasil! Rp ' . number_format($payout, 0, ',', '.')
                . ' telah ditambahkan ke dompet (' . $info['actual_claimable'] . ' hari klaim).';
            return $this->_claim_result('claimed', $message, $payout, $info['actual_claimable']);

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Rental_model::claim_roi — ' . $e->getMessage());
            return $this->_claim_result('error', 'Sistem: Gagal memproses klaim. Coba lagi.');
        }
    }

    /**
     * Bangun array hasil klaim terstruktur (kontrak model → controller).
     */
    private function _claim_result($code, $message, $amount = 0, $days = 0) {
        return [
            'code'    => $code,
            'message' => $message,
            'amount'  => $amount,
            'days'    => $days,
        ];
    }

    /**
     * Get a single rental by id + user_id (ownership check).
     */
    public function get_rental($rental_id, $user_id) {
        return $this->db->get_where('user_rentals', [
            'id'      => $rental_id,
            'user_id' => $user_id,
        ])->row();
    }

    // ============================================================
    // M3 (plan/60) — RENTAL EXPIRY ENGINE (Lazy / Event-Driven)
    //
    // Owner constraint: NO mandatory cron. Expiry happens via:
    //   1. Per-request lazy sweep (MY_Controller hook → expire_user_rentals)
    //   2. Defensive query filtering (eligibility SQL adds expired_at > now)
    //   3. Authoritative claim gate (claim_roi, C2/plan 44 — already landed)
    //   4. Optional manual tooling (scripts/expire_rentals.php + Admin action
    //      → expire_all_expired)
    //
    // Semantik batas: expired_at <= now (PHP Asia/Jakarta) — identik dengan
    // claimable_info::is_expired (single source of truth), jadi sweep tidak
    // pernah menutup kontrak lebih awal dari gate klaim. Waktu dikirim sebagai
    // bound param PHP (konvensi Phase 10B / plan/50) — MySQL NOW() TIDAK
    // dipakai. UPDATE kondisional (WHERE status='active') → idempotent,
    // race-safe terhadap claim_roi (keduanya menulis kondisional).
    // ============================================================

    /**
     * Lazy per-user sweep — dipanggil MY_Controller pada tiap request
     * user terautentikasi (setelah session + timezone init, sebelum baca
     * bisnis). Menutup kontrak expired milik user tsb: active → completed.
     *
     * M5/N2: sweep kini self-contained dalam SATU transaksi dengan notifikasi
     * per kontrak. Semantik FLIP-ONCE / NOTIFY-ONCE: setiap baris di-update
     * KONDISIONAL (WHERE status='active'); notifikasi hanya dibuat saat
     * affected_rows()===1, jadi sweep ulang (request berikutnya / konkuren)
     * tidak pernah membuat notifikasi ganda. Kegagalan → rollback penuh
     * (status tetap active & tanpa notifikasi).
     *
     * @param int         $user_id
     * @param string|null $now Timestamp WIB 'Y-m-d H:i:s' (default: sekarang)
     * @return int|false Jumlah kontrak yang di-flip 'active'→'completed',
     *                   atau false bila TX gagal.
     */
    public function expire_user_rentals($user_id, $now = null) {
        $now = $now ?: date('Y-m-d H:i:s');
        $this->load->model('Notification_model');

        $this->db->trans_begin();

        try {
            $rows = $this->db->query(
                "SELECT id FROM user_rentals
                  WHERE user_id = ? AND status = 'active' AND expired_at <= ?",
                [$user_id, $now]
            )->result();

            $flipped = 0;
            foreach ($rows as $row) {
                $this->db->query(
                    "UPDATE user_rentals SET status = 'completed'
                      WHERE id = ? AND user_id = ? AND status = 'active'",
                    [$row->id, $user_id]
                );
                if ($this->db->affected_rows() === 1) {
                    $flipped++;
                    $this->Notification_model->insert(
                        (int) $user_id,
                        'Kontrak Sewa Selesai',
                        'Masa sewa kontrak #' . (int) $row->id . ' telah berakhir dan kontrak ditutup otomatis.',
                        'info'
                    );
                }
            }

            $this->db->trans_commit();
            return $flipped;

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Rental_model::expire_user_rentals — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Global sweep — dipakai CLI scripts/expire_rentals.php dan aksi admin
     * (opsional/manual, BUKAN cron). Menutup SEMUA kontrak expired.
     *
     * M5/N2: CALLER-TX PARTICIPANT (pola Wallet_model::credit, plan/54) —
     * TIDAK membuka/commit transaksi sendiri. Pemanggil saat ini
     * (Admin::expire_expired_rentals) sudah membungkus sweep + audit + seluruh
     * notifikasi dalam SATU trans_start()/trans_complete(), jadi rollback
     * membatalkan flip DAN notifikasi secara atomik. Flip-once/notify-once
     * via UPDATE kondisional + gate affected_rows()===1 per baris (sweep
     * ganda/konkuren → baris kedua kena 0 → tanpa notifikasi ganda).
     *
     * @param string|null $now Timestamp WIB 'Y-m-d H:i:s' (default: sekarang)
     * @return int Jumlah baris yang di-flip ke 'completed'
     */
    public function expire_all_expired($now = null) {
        $now = $now ?: date('Y-m-d H:i:s');
        $this->load->model('Notification_model');

        $rows = $this->db->query(
            "SELECT id, user_id FROM user_rentals
              WHERE status = 'active' AND expired_at <= ?",
            [$now]
        )->result();

        $flipped = 0;
        foreach ($rows as $row) {
            $this->db->query(
                "UPDATE user_rentals SET status = 'completed'
                  WHERE id = ? AND status = 'active'",
                [$row->id]
            );
            if ($this->db->affected_rows() === 1) {
                $flipped++;
                $this->Notification_model->insert(
                    (int) $row->user_id,
                    'Kontrak Sewa Selesai',
                    'Masa sewa kontrak #' . (int) $row->id . ' telah berakhir dan kontrak ditutup otomatis.',
                    'info'
                );
            }
        }

        return $flipped;
    }

    /**
     * M3 (plan/60): gatekeeper penarikan — hanya kontrak BENAR-BENAR aktif
     * (belum lewat expired_at) yang memenuhi syarat. Defensif: meski sweep
     * per-request sudah menutup kontrak user yang login, filter ini menutup
     * celah sub-detik antara sweep dan gate serta jalur non-sweep.
     *
     * @param int $user_id
     * @return bool
     */
    public function has_active_rental($user_id) {
        $row = $this->db->query(
            "SELECT id FROM user_rentals
              WHERE user_id = ? AND status = 'active' AND expired_at > ?
              LIMIT 1",
            [$user_id, date('Y-m-d H:i:s')]
        )->row();
        return $row !== null;
    }
}
