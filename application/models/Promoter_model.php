<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Promoter_model — Program Promoter (plan/91): omzet burn (redeemable quota)
 * + klaim reward GPU zero-cost dengan persetujuan manual admin.
 *
 * Invariant yang dipegang:
 *  Z1/C4  — TIDAK ada mutasi wallet_ledger pada submit/approve/reject klaim
 *           (burn omzet adalah bookkeeping promoter_claims; kontrak reward
 *           purchase_price = 0). Kredit ROI kontrak reward berjalan lewat
 *           jalur klaim ROI existing (Rental_model::claim_roi).
 *  C5/M8  — Setiap TX membuka dgn anchor users FOR UPDATE (statement
 *           pertama); flip status kondisional WHERE status='pending' +
 *           affected_rows()===1 (anti double-action M4); seluruh aritmetika
 *           omzet integer IDR ((int) sebelum operasi, tanpa float).
 *  M5     — Audit (approve/reject) ditulis ATOMik di dalam TX yang sama
 *           (pola Admin_model::approve_withdrawal + _write_audit).
 *  M3     — Predikat "aktif/omzet" memakai status IN ('active','completed')
 *           + bound param; expired_at tidak dipakai untuk omzet (kontrak
 *           completed pasca sweep M3 tetap terhitung).
 *  K4     — Kuota per kanal INDEPENDEN: paid (source<>'promoter_reward') &
 *           reward (source='promoter_reward'), keduanya ≤ max_per_user,
 *           predikat D1 (cancelled dikecualikan).
 *  K5     — Guard rasio CAC 8–10% (integer murni): 8·cost ≤ 100·price ≤ 10·cost.
 *  K6     — Demosi: pending tetap processable; submit baru diblokir via gate
 *           is_promoter SEGAR di dalam TX (bukan session).
 */
class Promoter_model extends CI_Model {

    public function __construct() {
        parent::__construct();
        $this->config->load('promoter_rewards');
    }

    // ===================================================================
    // CONFIG / TIER MAP
    // ===================================================================

    /**
     * Peta tier reward: gpu_products.id => omzet_cost (integer IDR).
     * Sumber: application/config/promoter_rewards.php (fallback statis).
     * Nilai non-integer/non-positif dibuang (fail-closed, M8).
     *
     * @return array<int,int>
     */
    public function get_tier_map() {
        $cfg = $this->config->item('promoter_rewards');
        $map = [];
        if (is_array($cfg)) {
            foreach ($cfg as $pid => $cost) {
                $pid  = (int) $pid;
                $cost = (int) $cost;
                if ($pid > 0 && $cost > 0) {
                    $map[$pid] = $cost;
                }
            }
        }
        return $map;
    }

    /**
     * Snapshot produk (current read) — dipakai gate submit/approve.
     *
     * @return object|null
     */
    private function _product_row($product_id) {
        return $this->db->query(
            "SELECT id, name, price, daily_rate, duration_days, max_per_user, is_active
               FROM gpu_products
              WHERE id = ?",
            [(int) $product_id]
        )->row();
    }

    /**
     * Guard rasio CAC 8–10% (K5) — integer murni tanpa float (M8):
     *   8 · omzet_cost ≤ 100 · price ≤ 10 · omzet_cost
     *
     * @param int $price      Harga produk saat ini (integer IDR)
     * @param int $omzet_cost Threshold omzet yang dibakar (integer IDR)
     * @return bool
     */
    private function _ratio_ok($price, $omzet_cost) {
        $p = (int) $price;
        $c = (int) $omzet_cost;
        if ($p <= 0 || $c <= 0) {
            return false;
        }
        return (8 * $c <= 100 * $p) && (100 * $p <= 10 * $c);
    }

    // ===================================================================
    // OMZET ENGINE (Redeemable Quota / Burn)
    // ===================================================================

    /**
     * Hitung omzet L1 + burn/lock promotor.
     *
     * Omzet L1 = Σ purchase_price user_rentals milik downline LANGSUNG
     * (users.parent_id = promotor), status IN ('active','completed');
     * 'cancelled' TIDAK dihitung. Baris reward downline (price 0) otomatis
     * netral. Waktu tidak relevan (kontrak completed tetap terhitung).
     *
     * Redeemable = max(0, Total − Burned); Available = max(0, Redeemable −
     * Locked). Dapat dipanggil di luar TX (tampilan indikatif) ATAU di dalam
     * TX terkunci (gate otoritatif submit/approve — current reads).
     *
     * @param int $promoter_id
     * @return array{
     *   total_l1:int, burned:int, locked:int, redeemable:int, available:int,
     *   l1_count:int
     * }
     */
    public function get_omzet_summary($promoter_id) {
        $pid = (int) $promoter_id;

        $total = (int) $this->db->query(
            "SELECT COALESCE(SUM(ur.purchase_price), 0) AS total
               FROM user_rentals ur
               JOIN users u ON u.id = ur.user_id
              WHERE u.parent_id = ? AND ur.status IN ('active','completed')",
            [$pid]
        )->row()->total;

        $burned = (int) $this->db->query(
            "SELECT COALESCE(SUM(omzet_cost), 0) AS total
               FROM promoter_claims
              WHERE user_id = ? AND status = 'approved'",
            [$pid]
        )->row()->total;

        $locked = (int) $this->db->query(
            "SELECT COALESCE(SUM(omzet_cost), 0) AS total
               FROM promoter_claims
              WHERE user_id = ? AND status = 'pending'",
            [$pid]
        )->row()->total;

        $l1_count = (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM users WHERE parent_id = ?",
            [$pid]
        )->row()->cnt;

        $redeemable = max(0, $total - $burned);

        return [
            'total_l1'    => $total,
            'burned'      => $burned,
            'locked'      => $locked,
            'redeemable'  => $redeemable,
            'available'   => max(0, $redeemable - $locked),
            'l1_count'    => $l1_count,
        ];
    }

    /**
     * Hitung kepemilikan kontrak user utk satu produk, per kanal (K4).
     * Predikat D1: status IN ('active','completed'); 'cancelled' netral.
     *
     * @return array{paid:int, reward:int}
     */
    private function _channel_counts($user_id, $product_id) {
        $uid = (int) $user_id;
        $pid = (int) $product_id;

        $paid = (int) $this->db->query(
            "SELECT COUNT(*) AS cnt
               FROM user_rentals
              WHERE user_id = ? AND product_id = ?
                AND source <> 'promoter_reward'
                AND status IN ('active','completed')",
            [$uid, $pid]
        )->row()->cnt;

        $reward = (int) $this->db->query(
            "SELECT COUNT(*) AS cnt
               FROM user_rentals
              WHERE user_id = ? AND product_id = ?
                AND source = 'promoter_reward'
                AND status IN ('active','completed')",
            [$uid, $pid]
        )->row()->cnt;

        return ['paid' => $paid, 'reward' => $reward];
    }

    /**
     * Daftar tier reward + status kelayakan utk UI member (DISPLAY).
     * Otoritas gate tetap submit_claim()/approve_claim() di dalam TX.
     *
     * @param int $promoter_id
     * @return array<int,array> Tier terurut product_id, tiap elemen:
     *   product_id, omzet_cost, product_name|null, price:int|null,
     *   product_active:bool, ratio_ok:bool, reward_count:int,
     *   quota_max:int, reward_quota_ok:bool, available:int,
     *   can_claim:bool, reason:string ('ok'|'omzet_kurang'|'kuota_penuh'|
     *   'produk_nonaktif'|'rasio_invalid'|'produk_hilang')
     */
    public function get_reward_tiers($promoter_id) {
        $available = $this->get_omzet_summary((int) $promoter_id)['available'];
        $tiers     = [];

        foreach ($this->get_tier_map() as $pid => $omzet_cost) {
            $tier = [
                'product_id'     => $pid,
                'omzet_cost'     => $omzet_cost,
                'product_name'   => null,
                'price'          => null,
                'product_active' => false,
                'ratio_ok'       => false,
                'reward_count'   => 0,
                'quota_max'      => 0,
                'reward_quota_ok' => true,
                'available'      => $available,
                'can_claim'      => false,
                'reason'         => 'produk_hilang',
            ];

            $product = $this->_product_row($pid);
            if ($product) {
                $tier['product_name']   = (string) $product->name;
                $tier['price']          = (int) $product->price;
                $tier['product_active'] = ((int) $product->is_active === 1);
                $tier['ratio_ok']       = $this->_ratio_ok((int) $product->price, $omzet_cost);
                $tier['quota_max']      = (int) $product->max_per_user;

                $counts                    = $this->_channel_counts((int) $promoter_id, $pid);
                $tier['reward_count']      = $counts['reward'];
                $max                       = $tier['quota_max'];
                $tier['reward_quota_ok']   = ($max === 0) || ($counts['reward'] < $max);

                if (!$tier['product_active']) {
                    $tier['reason'] = 'produk_nonaktif';
                } elseif (!$tier['ratio_ok']) {
                    $tier['reason'] = 'rasio_invalid';
                } elseif (!$tier['reward_quota_ok']) {
                    $tier['reason'] = 'kuota_penuh';
                } elseif ($available < $omzet_cost) {
                    $tier['reason'] = 'omzet_kurang';
                } else {
                    $tier['can_claim'] = true;
                    $tier['reason']    = 'ok';
                }
            }

            $tiers[] = $tier;
        }

        return $tiers;
    }

    /**
     * Riwayat klaim member (display) — 15 terbaru default.
     *
     * @return array<object>
     */
    public function get_claim_history($user_id, $limit = 15) {
        return $this->db->query(
            "SELECT pc.*, p.name AS product_name
               FROM promoter_claims pc
               LEFT JOIN gpu_products p ON p.id = pc.product_id
              WHERE pc.user_id = ?
              ORDER BY pc.id DESC
              LIMIT ?",
            [(int) $user_id, max(1, (int) $limit)]
        )->result();
    }

    // ===================================================================
    // CLAIM LIFECYCLE — TX OWNER (C5: users anchor FOR UPDATE pertama)
    // ===================================================================

    /**
     * Submit klaim (member) — pending + kunci omzet.
     *
     * Gate lengkap di dalam SATU TX setelah anchor users FOR UPDATE:
     *   1. User ada, is_promoter = 1 (SEGAR — demosi memblokir), is_banned = 0
     *   2. Produk di peta tier & is_active = 1; rasio CAC K5 ok
     *   3. Available = max(0, Total − Burned − Locked) ≥ omzet_cost
     *   4. Kuota kanal REWARD: reward_count < max_per_user (bila max > 0)
     *
     * @param int $user_id
     * @param int $product_id
     * @return array{success:bool, code:string, message:string, claim_id:int|null}
     */
    public function submit_claim($user_id, $product_id) {
        $uid = (int) $user_id;
        $pid = (int) $product_id;
        $map = $this->get_tier_map();

        if (!isset($map[$pid])) {
            return ['success' => false, 'code' => 'invalid_product',
                'message' => 'Sistem: Produk tidak tersedia sebagai reward promotor.', 'claim_id' => null];
        }
        $omzet_cost = $map[$pid];

        $this->db->trans_begin();

        try {
            // 1. Anchor users FOR UPDATE (statement pertama — C5).
            $u = $this->db->query(
                "SELECT id, is_promoter, is_banned FROM users WHERE id = ? FOR UPDATE",
                [$uid]
            )->row();

            if (!$u) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'user_unavailable',
                    'message' => 'Sistem: Akun tidak ditemukan.', 'claim_id' => null];
            }
            if ((int) $u->is_banned === 1) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'banned',
                    'message' => 'Sistem: Akun Anda dinonaktifkan.', 'claim_id' => null];
            }
            if ((int) $u->is_promoter !== 1) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'not_promoter',
                    'message' => 'Sistem: Status promotor Anda tidak aktif.', 'claim_id' => null];
            }

            // 2. Produk snapshot + rasio (current read SETELAH lock).
            $product = $this->_product_row($pid);
            if (!$product || (int) $product->is_active !== 1) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'product_unavailable',
                    'message' => 'Sistem: Produk reward tidak aktif.', 'claim_id' => null];
            }
            if (!$this->_ratio_ok((int) $product->price, $omzet_cost)) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'ratio_invalid',
                    'message' => 'Sistem: Konfigurasi reward sedang tidak konsisten. Hubungi admin.', 'claim_id' => null];
            }

            // 3. Rekomputasi omzet otoritatif (current reads) — anti double-spend.
            $summary = $this->get_omzet_summary($uid);
            if ($summary['available'] < $omzet_cost) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'insufficient_omzet',
                    'message' => 'Sistem: Omzet L1 tersedia belum cukup untuk tier ini.', 'claim_id' => null];
            }

            // 4. Kuota kanal reward (K4).
            $counts = $this->_channel_counts($uid, $pid);
            $max    = (int) $product->max_per_user;
            if ($max > 0 && $counts['reward'] >= $max) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'quota_exceeded',
                    'message' => 'Sistem: Kuota reward paket ini telah tercapai (Maks. ' . $max . ').', 'claim_id' => null];
            }

            // 5. Insert klaim pending (mengunci omzet_cost tsb).
            $this->db->insert('promoter_claims', [
                'user_id'    => $uid,
                'product_id' => $pid,
                'omzet_cost' => $omzet_cost,
                'status'     => 'pending',
            ]);
            $claim_id = (int) $this->db->insert_id();

            $this->db->trans_commit();

            return ['success' => true, 'code' => 'ok',
                'message' => 'Klaim reward diajukan. Menunggu persetujuan admin.', 'claim_id' => $claim_id];

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Promoter_model::submit_claim — ' . $e->getMessage());
            return ['success' => false, 'code' => 'error',
                'message' => 'Sistem: Gagal mengajukan klaim. Coba lagi.', 'claim_id' => null];
        }
    }

    /**
     * Approve klaim (admin) — pending→approved, burn efektif, terbitkan
     * kontrak reward zero-cost (Z1: TANPA mutasi wallet_ledger), notifikasi
     * & audit M5 ATOMIK dalam TX yang sama.
     *
     * @param int   $claim_id
     * @param array $audit Konteks audit (admin_id, action, ip_address) —
     *                     user_id & details diisi model (parity approve_withdrawal)
     * @return array{success:bool, code:string, message:string, rental_id:int|null}
     */
    public function approve_claim($claim_id, $audit = null) {
        $cid = (int) $claim_id;

        $claim = $this->db->query(
            "SELECT id, user_id, product_id, omzet_cost, status
               FROM promoter_claims WHERE id = ?",
            [$cid]
        )->row();

        if (!$claim || $claim->status !== 'pending') {
            return ['success' => false, 'code' => 'already_processed',
                'message' => 'Klaim tidak valid atau sudah diproses.', 'rental_id' => null];
        }

        $uid = (int) $claim->user_id;

        $this->db->trans_begin();

        try {
            // 1. Anchor users FOR UPDATE (statement pertama — C5; serialisasi
            //    per promotor, urutan lock users → claim).
            $anchor = $this->db->query(
                "SELECT id FROM users WHERE id = ? FOR UPDATE",
                [$uid]
            )->row();
            if (!$anchor) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'user_unavailable',
                    'message' => 'Promotor tidak ditemukan.', 'rental_id' => null];
            }

            // 2. Kunci baris klaim + verifikasi ulang pending (anti double-action).
            $locked = $this->db->query(
                "SELECT id, user_id, product_id, omzet_cost, status
                   FROM promoter_claims
                  WHERE id = ? AND status = 'pending'
                  FOR UPDATE",
                [$cid]
            )->row();
            if (!$locked) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'already_processed',
                    'message' => 'Klaim sudah diproses oleh admin lain.', 'rental_id' => null];
            }

            $omzet_cost = (int) $locked->omzet_cost;
            $pid        = (int) $locked->product_id;

            // 3. Produk snapshot + is_active + rasio (K5) — current read.
            $product = $this->_product_row($pid);
            if (!$product || (int) $product->is_active !== 1) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'product_unavailable',
                    'message' => 'Produk reward tidak aktif. Nonaktifkan/reject klaim ini dahulu.', 'rental_id' => null];
            }
            if (!$this->_ratio_ok((int) $product->price, $omzet_cost)) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'ratio_invalid',
                    'message' => 'Rasio reward di luar 8–10% (harga produk berubah). Sesuaikan dahulu.', 'rental_id' => null];
            }

            // 4. Verifikasi omzet otoritatif pasca-lock: total L1 harus tetap
            //    menutupi burned + klaim ini (floor; E6/E7 cancel/reparent).
            $summary = $this->get_omzet_summary($uid);
            if ($summary['available'] < 0 || $summary['redeemable'] < $omzet_cost) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'insufficient_omzet',
                    'message' => 'Omzet L1 promotor turun sejak pengajuan. Reject klaim ini.', 'rental_id' => null];
            }

            // 5. Kuota kanal reward (K4) — verifikasi ulang saat approve.
            $counts = $this->_channel_counts($uid, $pid);
            $max    = (int) $product->max_per_user;
            if ($max > 0 && $counts['reward'] >= $max) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'quota_exceeded',
                    'message' => 'Kuota reward paket ini telah tercapai.', 'rental_id' => null];
            }

            // 6. Flip kondisional pending→approved (M4: affected_rows === 1).
            $admin_id = is_array($audit) && isset($audit['admin_id']) ? (int) $audit['admin_id'] : null;
            $this->db->query(
                "UPDATE promoter_claims
                    SET status = 'approved', admin_id = ?, updated_at = NOW()
                  WHERE id = ? AND status = 'pending'",
                [$admin_id, $cid]
            );
            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'already_processed',
                    'message' => 'Klaim sudah diproses.', 'rental_id' => null];
            }

            // 7. Terbitkan kontrak reward zero-cost (Z1/K7): purchase_price 0,
            //    source 'promoter_reward', snapshot daily_roi/total_days/
            //    expired_at dari produk; TANPA debit & TANPA _distribute_rebate.
            $this->db->insert('user_rentals', [
                'user_id'        => $uid,
                'product_id'     => $pid,
                'source'         => 'promoter_reward',
                'purchase_price' => 0,
                'daily_roi'      => (int) $product->daily_rate,
                'total_days'     => (int) $product->duration_days,
                'status'         => 'active',
                'expired_at'     => date('Y-m-d H:i:s', strtotime('+' . (int) $product->duration_days . ' days')),
            ]);
            $rental_id = (int) $this->db->insert_id();

            // 8. Notifikasi member (M5/N2 — dalam TX).
            $this->load->model('Notification_model');
            $this->Notification_model->insert(
                $uid,
                'Reward Promotor Cair',
                'Kontrak ' . (string) $product->name . ' (reward, tanpa biaya) telah diaktifkan. '
                    . 'Klaim ROI harian dimulai H+1.',
                'success'
            );

            // 9. Audit atomik (M5 — dalam TX).
            if (is_array($audit)) {
                $audit['user_id'] = $uid;
                $audit['details'] = [
                    'claim_id'      => $cid,
                    'product_id'    => $pid,
                    'product_name'  => (string) $product->name,
                    'omzet_cost'    => $omzet_cost,
                    'rental_id'     => $rental_id,
                ];
                $this->_write_audit($audit);
            }

            $this->db->trans_commit();

            return ['success' => true, 'code' => 'ok',
                'message' => 'Klaim #' . $cid . ' disetujui. Kontrak reward #' . $rental_id . ' aktif.', 'rental_id' => $rental_id];

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Promoter_model::approve_claim — ' . $e->getMessage());
            return ['success' => false, 'code' => 'error',
                'message' => 'Sistem: Gagal menyetujui klaim.', 'rental_id' => null];
        }
    }

    /**
     * Reject klaim (admin) — pending→rejected + admin_notes. Lock omzet lepas
     * dengan sendirinya (baris tidak lagi pending). Notifikasi & audit atomik.
     *
     * @param int         $claim_id
     * @param array|null  $audit  Konteks audit (admin_id, action, ip_address)
     * @param string|null $notes  Alasan penolakan (dipotong 255)
     * @return array{success:bool, code:string, message:string}
     */
    public function reject_claim($claim_id, $audit = null, $notes = null) {
        $cid   = (int) $claim_id;
        $notes = ($notes !== null && trim($notes) !== '')
            ? mb_substr(trim($notes), 0, 255)
            : null;

        $claim = $this->db->query(
            "SELECT id, user_id, product_id, omzet_cost, status
               FROM promoter_claims WHERE id = ?",
            [$cid]
        )->row();

        if (!$claim || $claim->status !== 'pending') {
            return ['success' => false, 'code' => 'already_processed',
                'message' => 'Klaim tidak valid atau sudah diproses.', 'rental_id' => null];
        }

        $uid = (int) $claim->user_id;

        $this->db->trans_begin();

        try {
            // 1. Anchor users FOR UPDATE (C5).
            $anchor = $this->db->query(
                "SELECT id FROM users WHERE id = ? FOR UPDATE",
                [$uid]
            )->row();
            if (!$anchor) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'user_unavailable',
                    'message' => 'Promotor tidak ditemukan.', 'rental_id' => null];
            }

            // 2. Lock baris klaim + verifikasi pending.
            $locked = $this->db->query(
                "SELECT id FROM promoter_claims
                  WHERE id = ? AND status = 'pending'
                  FOR UPDATE",
                [$cid]
            )->row();
            if (!$locked) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'already_processed',
                    'message' => 'Klaim sudah diproses oleh admin lain.', 'rental_id' => null];
            }

            // 3. Flip kondisional pending→rejected (M4).
            $admin_id = is_array($audit) && isset($audit['admin_id']) ? (int) $audit['admin_id'] : null;
            $this->db->query(
                "UPDATE promoter_claims
                    SET status = 'rejected', admin_id = ?, admin_notes = ?, updated_at = NOW()
                  WHERE id = ? AND status = 'pending'",
                [$admin_id, $notes, $cid]
            );
            if ($this->db->affected_rows() !== 1) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'already_processed',
                    'message' => 'Klaim sudah diproses.', 'rental_id' => null];
            }

            // 4. Notifikasi member (M5/N2 — dalam TX).
            $this->load->model('Notification_model');
            $product_row = $this->db->query(
                "SELECT name FROM gpu_products WHERE id = ?",
                [(int) $claim->product_id]
            )->row();
            $product_name = ($product_row && $product_row->name !== null && $product_row->name !== '')
                ? (string) $product_row->name
                : 'Reward';
            $this->Notification_model->insert(
                $uid,
                'Klaim Reward Ditolak',
                'Klaim ' . $product_name . ' ditolak' . ($notes ? ': ' . $notes : '')
                    . '. Omzet Anda telah dikembalikan ke saldo redeemable.',
                'warning'
            );

            // 5. Audit atomik (M5).
            if (is_array($audit)) {
                $audit['user_id'] = $uid;
                $audit['details'] = [
                    'claim_id'   => $cid,
                    'product_id' => (int) $claim->product_id,
                    'omzet_cost' => (int) $claim->omzet_cost,
                    'admin_notes'=> $notes,
                ];
                $this->_write_audit($audit);
            }

            $this->db->trans_commit();

            return ['success' => true, 'code' => 'ok',
                'message' => 'Klaim #' . $cid . ' ditolak. Lock omzet dilepas.', 'rental_id' => null];

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Promoter_model::reject_claim — ' . $e->getMessage());
            return ['success' => false, 'code' => 'error',
                'message' => 'Sistem: Gagal menolak klaim.', 'rental_id' => null];
        }
    }

    /**
     * Tulis audit di dalam TX pemanggil (pola Admin_model::_write_audit).
     *
     * @param array $audit {admin_id, user_id, action, details, ip_address}
     */
    private function _write_audit($audit = null) {
        if (!is_array($audit) || empty($audit)) {
            return;
        }
        $this->load->model('Audit_model');
        $this->Audit_model->log_admin_action(
            isset($audit['admin_id'])   ? $audit['admin_id']   : null,
            isset($audit['user_id'])    ? $audit['user_id']    : null,
            isset($audit['action'])     ? $audit['action']     : '',
            isset($audit['details'])    ? $audit['details']    : null,
            isset($audit['ip_address']) ? $audit['ip_address'] : ''
        );
    }
}
