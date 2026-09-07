<!-- ===== MISSION CARD: LEVEL 1 BONUS ===== -->
<?php
    $m_agent  = (int) ($claim_data['active_b_count'] ?? 0);
    $m_turnover = (int) ($claim_data['sales_b'] ?? 0);
    $m_claimed  = (bool) ($claim_data['level1_claimed'] ?? false);
    $m_agent_pct    = $m_agent >= 3 ? 100 : round(($m_agent / 3) * 100);
    $m_turnover_pct = $m_turnover >= 330000 ? 100 : round(($m_turnover / 330000) * 100);

    // ── Gaji Mingguan (Level 2-6) — display mirror dari get_claim_data ──
    $w_level    = (int) ($claim_data['current_level'] ?? 0);
    $w_label    = $claim_data['current_wage_label'] ?? null;
    $w_total    = (int) ($claim_data['total_active'] ?? 0);
    $w_eligible = (bool) ($claim_data['weekly_eligible'] ?? false);
    $w_cool     = (bool) ($claim_data['cooldown_active'] ?? false);
    $w_days     = $claim_data['days_remaining'] ?? null;
    $w_next_d   = $claim_data['next_claim_date'] ?? null;

    // Tangga level (threshold => level) — display only, mirror WAGE_TIERS
    $w_ladder = [9 => 2, 30 => 3, 70 => 4, 130 => 5, 190 => 6];
    $w_next_thr = null;
    $w_next_lvl = null;
    foreach ($w_ladder as $thr => $lvl) {
        if ($w_total < $thr) {
            $w_next_thr = $thr;
            $w_next_lvl = $lvl;
            break;
        }
    }
    $w_pct = $w_next_thr ? (int) min(100, round($w_total / $w_next_thr * 100)) : 100;
?>
<section class="mx-4 mt-4 u-card-fin rounded-2xl p-4 shadow-lg">
    <!-- Header -->
    <div class="flex items-center justify-between mb-1">
        <div class="flex items-center gap-2">
            <span class="text-base">🎯</span>
            <h3 class="text-xs font-extrabold text-white uppercase tracking-wider">Misi Level 1</h3>
        </div>
        <span class="text-[10px] font-bold text-indigo-400 bg-indigo-500/10 px-2 py-0.5 rounded-full border border-indigo-500/20">Rp <?= $l1_bonus_fmt ?></span>
    </div>
    <p class="text-[10px] text-slate-400 mb-3">Klaim sekali seumur hidup</p>

    <!-- Agent Progress -->
    <div class="mb-3">
        <div class="flex items-center justify-between mb-1">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wide">Agen Aktif</span>
            <span class="text-xs font-extrabold text-white"><?= $m_agent ?> <span class="text-slate-500 font-normal">/ 3</span></span>
        </div>
        <div class="w-full h-2 bg-slate-700 rounded-full overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500 <?= $m_agent >= 3 ? 'bg-emerald-500' : 'bg-indigo-500' ?>" style="width: <?= $m_agent_pct ?>%"></div>
        </div>
    </div>

    <!-- Turnover Progress -->
    <div class="mb-3">
        <div class="flex items-center justify-between mb-1">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wide">Omset Tim</span>
            <span class="text-xs font-extrabold text-white">Rp <?= number_format($m_turnover, 0, ',', '.') ?> <span class="text-slate-500 font-normal">/ 330.000</span></span>
        </div>
        <div class="w-full h-2 bg-slate-700 rounded-full overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500 <?= $m_turnover_pct >= 100 ? 'bg-emerald-500' : 'bg-indigo-500' ?>" style="width: <?= $m_turnover_pct ?>%"></div>
        </div>
    </div>

    <!-- Button: 3-state conditional -->
    <div class="mt-4">
        <?php if ($m_claimed): ?>
            <div class="w-full text-center py-2.5 bg-emerald-500/10 border border-emerald-500/20 rounded-xl">
                <span class="text-xs font-bold text-emerald-400"><i class="fas fa-check-circle mr-1"></i>Selesai — Sudah Diklaim</span>
            </div>
        <?php elseif ($m_agent >= 3 && $m_turnover >= 330000): ?>
            <button id="btn-claim-l1" onclick="claimLevel1()" class="w-full bg-indigo-500 hover:bg-indigo-600 text-white text-xs font-bold py-3 px-4 rounded-xl transition-all active:scale-[0.97] shadow-lg shadow-indigo-500/20">
                <i class="fas fa-gift mr-1"></i>Klaim Bonus Rp <?= $l1_bonus_fmt ?>
            </button>
        <?php else: ?>
            <button disabled class="w-full bg-slate-700 text-slate-400 text-xs font-bold py-3 px-4 rounded-xl cursor-not-allowed border border-slate-600">
                <i class="fas fa-lock mr-1"></i>Syarat Belum Terpenuhi
            </button>
        <?php endif; ?>
    </div>
</section>

<!-- ===== MISSION CARD: GAJI MINGGUAN (LEVEL 2-6) ===== -->
<section class="mx-4 mt-4 u-card-fin rounded-2xl p-4 shadow-lg">
    <!-- Header -->
    <div class="flex items-center justify-between mb-1">
        <div class="flex items-center gap-2">
            <span class="text-base">💰</span>
            <h3 class="text-xs font-extrabold text-white uppercase tracking-wider">Gaji Mingguan</h3>
        </div>
        <span class="text-[10px] font-bold text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded-full border border-emerald-500/20">
            <?= $w_label !== null ? $w_label : 'Level 2–6' ?>
        </span>
    </div>
    <p class="text-[10px] text-slate-400 mb-3">Klaim 1x per 7 hari — level ditentukan dinamis dari downline aktif</p>

    <!-- Progress -->
    <div class="mb-3">
        <div class="flex items-center justify-between mb-1">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wide">Downline Aktif</span>
            <span class="text-xs font-extrabold text-white">
                <?= number_format($w_total, 0, ',', '.') ?>
                <?php if ($w_next_thr !== null): ?>
                    <span class="text-slate-500 font-normal">/ <?= number_format($w_next_thr, 0, ',', '.') ?> menuju Level <?= $w_next_lvl ?></span>
                <?php else: ?>
                    <span class="text-emerald-400 font-normal">— Level 6 tercapai</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="w-full h-2 bg-slate-700 rounded-full overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500 <?= $w_total >= 9 ? 'bg-emerald-500' : 'bg-indigo-500' ?>" style="width: <?= $w_pct ?>%"></div>
        </div>
    </div>

    <!-- Current tier summary -->
    <div class="flex items-center gap-2 mb-3">
        <?php if ($w_level > 0): ?>
            <span class="text-[10px] font-bold text-emerald-400 bg-emerald-500/10 px-2 py-1 rounded-lg border border-emerald-500/20">
                Level <?= $w_level ?> — <?= $w_label ?>
            </span>
        <?php else: ?>
            <span class="text-[10px] font-bold text-slate-400 bg-slate-500/10 px-2 py-1 rounded-lg border border-slate-600">
                Belum mencapai Level 2
            </span>
        <?php endif; ?>
    </div>

    <!-- Button: 3-state conditional (eligible / cooldown / not qualified) -->
    <div class="mt-4">
        <?php if ($w_eligible && $w_level > 0): ?>
            <button id="btn-claim-wage" onclick="claimWage()" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold py-3 px-4 rounded-xl transition-all active:scale-[0.97] shadow-lg shadow-emerald-500/20">
                <i class="fas fa-money-bill-wave mr-1"></i>Klaim Gaji Mingguan (Level <?= $w_level ?>) — <?= $w_label ?>
            </button>
        <?php elseif ($w_cool): ?>
            <button disabled class="w-full bg-slate-700 text-slate-400 text-xs font-bold py-3 px-4 rounded-xl cursor-not-allowed border border-slate-600">
                <i class="fas fa-hourglass-half mr-1"></i>Cooldown — Gaji berikutnya: <?= (int) $w_days ?> hari lagi<?= $w_next_d ? ' (' . $w_next_d . ')' : '' ?>
            </button>
        <?php else: ?>
            <button disabled class="w-full bg-slate-700 text-slate-400 text-xs font-bold py-3 px-4 rounded-xl cursor-not-allowed border border-slate-600">
                <i class="fas fa-lock mr-1"></i>Syarat Belum Terpenuhi — Minimal 9 Downline Aktif
            </button>
        <?php endif; ?>
    </div>
</section>

<!-- ===== HELP BUTTON ===== -->
<div class="mx-4 mt-3">
    <button onclick="openHelpModal()" class="w-full flex items-center justify-center gap-2 bg-indigo-50 dark:bg-indigo-500/10 hover:bg-indigo-100 dark:hover:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 text-xs font-bold py-2.5 px-4 rounded-xl border border-indigo-200 dark:border-indigo-500/20 transition-all active:scale-95">
        <i class="fas fa-info-circle"></i> Cara Kerja Bonus
    </button>
</div>

<!-- ===== LAYER 1: SHARE CENTER (Plan 89 — gating referral) ===== -->
<?php if (!empty($referral_locked)): ?>
<!-- Condition A (lifetime_rentals == 0): kode/link/QR DISEMBUNYIKAN — locked state -->
<section class="mx-4 mt-3 u-card rounded-2xl p-5 shadow-sm">
    <div class="flex items-center gap-2 mb-1">
        <i class="fas fa-lock text-indigo-500"></i>
        <h2 class="text-sm font-bold u-text uppercase tracking-wide">Pusat Berbagi</h2>
    </div>
    <div class="text-center py-5">
        <div class="w-14 h-14 mx-auto mb-3 rounded-2xl u-card-inset flex items-center justify-center">
            <i class="fas fa-gift u-muted text-xl"></i>
        </div>
        <p class="text-sm u-text font-bold mb-1">Kode Undangan Terkunci</p>
        <p class="text-xs u-muted leading-relaxed max-w-[280px] mx-auto mb-4">
            Sewa minimal 1 paket GPU untuk membuka kode undangan dan mulai menghasilkan komisi tim.
        </p>
        <a href="<?= base_url('marketplace') ?>"
           class="inline-flex items-center gap-2 bg-indigo-500 hover:bg-indigo-600 active:scale-95 text-white text-xs font-bold px-5 py-3 rounded-xl transition-all">
            <i class="fas fa-microchip mr-1"></i>Lihat Paket GPU
        </a>
    </div>
</section>
<?php else: ?>
<!-- Condition B/C (lifetime_rentals >= 1): kode & link PERMANEN terlihat -->
<section class="mx-4 mt-3 u-card rounded-2xl p-5 shadow-sm">
    <div class="flex items-center gap-2 mb-1">
        <i class="fas fa-share-alt text-indigo-500"></i>
        <h2 class="text-sm font-bold u-text uppercase tracking-wide">Pusat Berbagi</h2>
    </div>
    <p class="text-xs u-muted mb-4">Bagikan kode ini ke temanmu untuk membangun tim</p>

    <!-- Referral URL -->
    <div class="u-card-inset rounded-xl p-3 mb-4">
        <p class="text-[10px] u-muted uppercase font-semibold mb-1">Link Undangan</p>
        <div class="flex items-center gap-2">
            <code id="ref-url" class="text-xs u-text-2 font-mono truncate flex-1"><?= $ref_url ?></code>
            <button id="btn-copy" onclick="copyRef()" class="shrink-0 bg-indigo-500 hover:bg-indigo-600 active:scale-95 text-white text-[10px] font-bold px-3 py-1.5 rounded-lg transition-all">
                <i class="fas fa-copy mr-1"></i>Salin
            </button>
        </div>
    </div>

    <!-- QR Code (container tetap putih di dua tema — keputusan desain §2.8) -->
    <div class="flex flex-col items-center">
        <div id="qrcode" class="bg-white p-3 rounded-xl border border-slate-100 shadow-sm"></div>
        <p class="text-[10px] u-muted mt-2">Scan QR untuk mendaftar</p>
    </div>
</section>
<?php endif; ?>

<!-- ===== LAYER 1B: MEMBER REBATE GUIDE (Plan 89 — 3-Tier Purchase Rebate) ===== -->
<section class="mx-4 mt-3 u-card-gpu rounded-2xl p-5 shadow-sm">
    <div class="flex items-center gap-2 mb-1">
        <i class="fas fa-network-wired text-cyan-500"></i>
        <h2 class="text-sm font-bold u-text uppercase tracking-wide">Komisi Rebate 3-Tier</h2>
        <?php if (empty($rebate_enabled)): ?>
            <span class="ml-auto text-[9px] font-bold text-slate-400 bg-slate-500/10 border border-slate-600 px-2 py-0.5 rounded-full">Nonaktif</span>
        <?php endif; ?>
    </div>
    <p class="text-xs u-muted mb-4">
        Komisi instan dari setiap pembelian paket GPU oleh jaringan Anda — otomatis masuk ke saldo dompet.
    </p>

    <div class="space-y-2">
        <div class="u-card-inset rounded-xl p-3 flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 min-w-0">
                <span class="shrink-0 w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs font-extrabold">L1</span>
                <div class="min-w-0">
                    <p class="text-xs u-text font-bold">Downline Langsung</p>
                    <p class="text-[10px] u-muted truncate">Anak — referral langsung Anda</p>
                </div>
            </div>
            <span class="shrink-0 text-sm font-extrabold text-indigo-600 dark:text-indigo-400"><?= (int) $rebate_l1_percent ?>%</span>
        </div>
        <div class="u-card-inset rounded-xl p-3 flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 min-w-0">
                <span class="shrink-0 w-8 h-8 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 flex items-center justify-center text-xs font-extrabold">L2</span>
                <div class="min-w-0">
                    <p class="text-xs u-text font-bold">Level 2</p>
                    <p class="text-[10px] u-muted truncate">Cucu — dari downline langsung Anda</p>
                </div>
            </div>
            <span class="shrink-0 text-sm font-extrabold text-cyan-600 dark:text-cyan-400"><?= (int) $rebate_l2_percent ?>%</span>
        </div>
        <div class="u-card-inset rounded-xl p-3 flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 min-w-0">
                <span class="shrink-0 w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs font-extrabold">L3</span>
                <div class="min-w-0">
                    <p class="text-xs u-text font-bold">Level 3</p>
                    <p class="text-[10px] u-muted truncate">Cicit — 3 level di bawah Anda</p>
                </div>
            </div>
            <span class="shrink-0 text-sm font-extrabold text-emerald-600 dark:text-emerald-400"><?= (int) $rebate_l3_percent ?>%</span>
        </div>
    </div>

    <div class="mt-3 rounded-xl p-3 border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10">
        <p class="text-[11px] u-text-2 leading-relaxed">
            <i class="fas fa-exclamation-triangle text-amber-500 mr-1"></i>
            <b>Syarat kelayakan:</b> upline harus memiliki <b>minimal 1 kontrak sewa aktif</b> pada saat
            downline membeli paket. Bila tidak aktif, komisi tier tersebut <b>hangus</b> — tidak dilimpahkan
            ke level di atasnya.
        </p>
    </div>
</section>

<?php if (!empty($is_promoter) && $promoter_summary !== null): ?>
<?php
    // Plan 91 — Hub Program Promotor (Omzet Burn / Redeemable Quota).
    $p_sum  = $promoter_summary;
    $p_avail = (int) ($p_sum['available'] ?? 0);
    $promo_reason_label = [
        'ok'             => 'Klaim Reward',
        'omzet_kurang'   => 'Omzet Belum Cukup',
        'kuota_penuh'    => 'Kuota Reward Penuh',
        'produk_nonaktif'=> 'Produk Nonaktif',
        'rasio_invalid'  => 'Sedang Tidak Tersedia',
        'produk_hilang'  => 'Tidak Tersedia',
    ];
?>
<!-- ===== PROGRAM PROMOTOR (Plan 91) ===== -->
<section id="promoter-hub" class="mx-4 mt-3 u-card rounded-2xl p-5 shadow-sm border border-indigo-500/20">
    <div class="flex items-center gap-2 mb-1">
        <i class="fas fa-star text-amber-400"></i>
        <h2 class="text-sm font-bold u-text uppercase tracking-wide">Program Promotor</h2>
        <span class="ml-auto text-[9px] font-bold text-amber-500 bg-amber-500/10 border border-amber-500/30 px-2 py-0.5 rounded-full">Reward GPU</span>
    </div>
    <p class="text-[10px] u-muted mb-4">Tukarkan omzet downline langsung (L1) dengan kontrak GPU zero-cost — ROI harian penuh, disetujui admin.</p>

    <!-- Statistik omzet L1 -->
    <div class="grid grid-cols-2 gap-2 mb-4">
        <div class="u-card-inset rounded-xl p-3">
            <p class="text-[10px] u-muted font-semibold uppercase tracking-wide">Omzet L1 Total</p>
            <p class="text-base font-extrabold u-text mt-1">Rp <?= number_format((int) ($p_sum['total_l1'] ?? 0), 0, ',', '.') ?></p>
        </div>
        <div class="u-card-inset rounded-xl p-3">
            <p class="text-[10px] u-muted font-semibold uppercase tracking-wide">Terpakai (Burn)</p>
            <p class="text-base font-extrabold u-text mt-1">Rp <?= number_format((int) ($p_sum['burned'] ?? 0), 0, ',', '.') ?></p>
        </div>
        <div class="u-card-inset rounded-xl p-3">
            <p class="text-[10px] u-muted font-semibold uppercase tracking-wide">Terkunci (Pending)</p>
            <p class="text-base font-extrabold u-text mt-1">Rp <?= number_format((int) ($p_sum['locked'] ?? 0), 0, ',', '.') ?></p>
        </div>
        <div class="bg-emerald-50 dark:bg-emerald-500/10 rounded-xl p-3 border border-emerald-100 dark:border-emerald-500/20">
            <p class="text-[10px] text-emerald-500 dark:text-emerald-400 font-semibold uppercase tracking-wide">Tersedia</p>
            <p class="text-base font-extrabold text-emerald-600 dark:text-emerald-400 mt-1">Rp <?= number_format($p_avail, 0, ',', '.') ?></p>
        </div>
    </div>

    <!-- Kartu tier reward -->
    <?php if (!empty($promoter_tiers)): ?>
    <div class="space-y-3 mb-4">
        <?php foreach ($promoter_tiers as $t):
            $cost   = (int) ($t['omzet_cost'] ?? 0);
            $pct    = $cost > 0 ? min(100, round(($p_avail / $cost) * 100)) : 0;
            $price  = (int) ($t['price'] ?? 0);
            $pname  = htmlspecialchars((string) ($t['product_name'] ?? 'Produk Reward'), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="u-card-inset rounded-xl p-4">
            <div class="flex items-start justify-between gap-2 mb-2">
                <div class="min-w-0">
                    <p class="text-xs u-text font-extrabold truncate"><?= $pname ?></p>
                    <p class="text-[10px] u-muted mt-0.5">Nilai reward <span class="font-bold text-emerald-600 dark:text-emerald-400">Rp <?= number_format($price, 0, ',', '.') ?></span></p>
                </div>
                <span class="shrink-0 text-[10px] font-extrabold text-amber-600 dark:text-amber-400 bg-amber-500/10 border border-amber-500/20 px-2 py-1 rounded-lg">Rp <?= number_format($cost, 0, ',', '.') ?></span>
            </div>
            <div class="flex items-center justify-between mb-1">
                <span class="text-[9px] u-muted font-semibold uppercase tracking-wide">Progress omzet tersedia</span>
                <span class="text-[10px] u-text-2 font-mono"><?= number_format($p_avail, 0, ',', '.') ?> / <?= number_format($cost, 0, ',', '.') ?></span>
            </div>
            <div class="w-full h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden mb-3">
                <div class="h-full rounded-full <?= $pct >= 100 ? 'bg-emerald-500' : 'bg-indigo-500' ?>" style="width: <?= $pct ?>%"></div>
            </div>

            <?php if (!empty($t['can_claim'])): ?>
                <button type="button" data-pid="<?= (int) $t['product_id'] ?>" data-name="<?= $pname ?>"
                        onclick="openPromoterClaim(this)"
                        class="w-full bg-indigo-500 hover:bg-indigo-600 text-white text-xs font-bold py-2.5 px-4 rounded-xl transition-all active:scale-[0.97]">
                    <i class="fas fa-gift mr-1"></i>Klaim Reward — Rp <?= number_format($price, 0, ',', '.') ?>
                </button>
            <?php else: ?>
                <button type="button" disabled
                        class="w-full bg-slate-700 text-slate-400 text-xs font-bold py-2.5 px-4 rounded-xl cursor-not-allowed border border-slate-600">
                    <i class="fas fa-lock mr-1"></i><?= htmlspecialchars((string) ($promo_reason_label[$t['reason'] ?? 'produk_hilang'] ?? 'Tidak Tersedia'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Riwayat klaim -->
    <div>
        <p class="text-[10px] u-muted uppercase font-semibold tracking-wide mb-2">Riwayat Klaim</p>
        <?php if (empty($promoter_history)): ?>
            <p class="text-[11px] u-muted py-3 text-center rounded-xl u-card-inset">Belum ada klaim reward.</p>
        <?php else: ?>
            <div class="max-h-56 overflow-y-auto space-y-2">
                <?php foreach ($promoter_history as $h): ?>
                    <div class="u-card-inset rounded-xl px-3 py-2 flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-xs u-text font-semibold truncate"><?= htmlspecialchars((string) ($h->product_name ?? 'Reward'), ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="text-[10px] u-muted">Rp <?= number_format((int) $h->omzet_cost, 0, ',', '.') ?> · <?= date('d M Y H:i', strtotime($h->created_at)) ?></p>
                            <?php if ($h->status === 'rejected' && !empty($h->admin_notes)): ?>
                                <p class="text-[9px] text-amber-600 dark:text-amber-400 mt-0.5 truncate">Alasan: <?= htmlspecialchars($h->admin_notes, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="shrink-0 text-[9px] font-bold px-2 py-1 rounded-full <?= $h->status === 'approved' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : ($h->status === 'rejected' ? 'bg-red-500/10 text-red-600 dark:text-red-400' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400') ?>">
                            <?= strtoupper($h->status) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ═══ MODAL KLAIM REWARD PROMOTOR — Bottom Sheet ═══ -->
<div id="promoterClaimModal" class="fixed inset-0 z-[60] hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closePromoterClaim()"></div>
    <div class="absolute bottom-0 left-0 right-0 u-modal rounded-t-3xl max-h-[80vh] overflow-y-auto transform translate-y-full transition-transform duration-300" id="promoterClaimSheet">
        <div class="sticky top-0 u-modal px-5 pt-5 pb-3 border-b border-slate-100 dark:border-slate-800 rounded-t-3xl">
            <div class="w-10 h-1 bg-slate-200 dark:bg-slate-600 rounded-full mx-auto mb-3"></div>
            <h3 class="text-sm font-bold u-text flex items-center gap-2"><i class="fas fa-gift text-indigo-500"></i> Klaim Reward Promotor</h3>
        </div>
        <div class="px-5 py-4 space-y-4">
            <div class="bg-slate-50 dark:bg-slate-800/60 rounded-xl p-4 border border-slate-100 dark:border-slate-700">
                <p class="text-xs u-text font-bold mb-1" id="promoClaimProduct">—</p>
                <p class="text-[11px] u-text-2 leading-relaxed">
                    Klaim akan membakar omzet L1 sesuai tier dan masuk antrean <b>persetujuan admin</b>.
                    Saat disetujui, kontrak GPU zero-cost aktif otomatis — klaim ROI harian dimulai H+1.
                </p>
            </div>
            <button id="btn-claim-promoter" onclick="claimPromoter()"
                    class="w-full bg-indigo-500 hover:bg-indigo-600 text-white text-xs font-bold py-3 px-4 rounded-xl transition-all active:scale-[0.97] shadow-lg shadow-indigo-500/20">
                <i class="fas fa-paper-plane mr-1"></i>Ajukan Klaim
            </button>
            <button onclick="closePromoterClaim()"
                    class="w-full py-2.5 u-btn-ghost rounded-xl text-xs font-bold u-text-2 transition-colors">
                Batal
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===== LAYER 2: GAMIFICATION ===== -->
<section class="mx-4 mt-3 u-card rounded-2xl p-5 shadow-sm">
    <div class="flex items-center gap-2 mb-4">
        <i class="fas fa-chart-bar text-indigo-500"></i>
        <h2 class="text-sm font-bold u-text uppercase tracking-wide">Statistik Tim</h2>
    </div>

    <!-- Metric Cards -->
    <div class="grid grid-cols-2 gap-3 mb-4">
        <div class="u-card-inset rounded-xl p-3 text-center">
            <p class="text-2xl font-extrabold u-text"><?= $total_bc ?></p>
            <p class="text-[10px] u-muted font-semibold mt-0.5">Total Anggota</p>
        </div>
        <div class="bg-emerald-50 dark:bg-emerald-500/10 rounded-xl p-3 text-center border border-emerald-100 dark:border-emerald-500/20">
            <p class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400"><?= $active_bc ?></p>
            <p class="text-[10px] text-emerald-500 dark:text-emerald-400 font-semibold mt-0.5">Anggota Aktif</p>
        </div>
    </div>

    <!-- Level Breakdown -->
    <div class="grid grid-cols-2 gap-3">
        <div class="bg-indigo-50 dark:bg-indigo-500/10 rounded-xl p-3 text-center border border-indigo-100 dark:border-indigo-500/20">
            <p class="text-2xl font-extrabold text-indigo-600 dark:text-indigo-400"><?= $l1_active ?></p>
            <p class="text-[10px] text-indigo-500 dark:text-indigo-400 font-semibold mt-0.5">Agen L1 Aktif</p>
        </div>
        <div class="u-card-inset rounded-xl p-3 text-center">
            <p class="text-2xl font-extrabold u-text"><?= $l2_active ?></p>
            <p class="text-[10px] u-muted font-semibold mt-0.5">Agen L2 Aktif</p>
        </div>
    </div>
</section>

<!-- ===== LAYER 3: MEMBER LIST ===== -->
<section class="mx-4 mt-3 mb-24 u-card rounded-2xl p-5 shadow-sm">
    <div class="flex items-center gap-2 mb-4">
        <i class="fas fa-users text-indigo-500"></i>
        <h2 class="text-sm font-bold u-text uppercase tracking-wide">Daftar Anggota</h2>
        <span class="ml-auto text-[10px] bg-slate-100 dark:bg-slate-700 dark:text-slate-300 text-slate-500 font-bold px-2 py-0.5 rounded-full"><?= $total_bc ?></span>
    </div>

    <?php if (empty($members)): ?>
        <!-- Empty State -->
        <div class="text-center py-8">
            <i class="fas fa-user-plus text-4xl u-muted mb-3"></i>
            <p class="text-sm u-text-2 font-semibold">Belum ada anggota tim</p>
            <p class="text-[10px] u-muted mt-1">Bagikan kode referral untuk mulai</p>
        </div>
    <?php else: ?>
        <div class="max-h-96 overflow-y-auto space-y-2">
            <?php foreach ($members as $m): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl border <?= $m->is_active ? 'border-emerald-100 bg-emerald-50/50 dark:border-emerald-500/20 dark:bg-emerald-500/10' : 'border-slate-100 bg-slate-50/50 dark:border-slate-700 dark:bg-slate-800/40' ?>">
                    <!-- Avatar Initial -->
                    <div class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 <?= $m->is_active ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400' : 'bg-slate-100 text-slate-400 dark:bg-slate-700 dark:text-slate-300' ?>">
                        <span class="text-xs font-bold"><?= strtoupper(substr($m->username ?? $m->phone_full, 0, 1)) ?></span>
                    </div>

                    <!-- Info -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1.5">
                            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200 truncate"><?= htmlspecialchars($m->username ?? 'User') ?></p>
                            <span class="shrink-0 text-[9px] font-bold px-1.5 py-0.5 rounded-full <?= $m->level == 1 ? 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-300' : 'bg-slate-200 text-slate-500 dark:bg-slate-700 dark:text-slate-300' ?>">
                                L<?= $m->level ?>
                            </span>
                        </div>
                        <a href="https://wa.me/<?= $m->phone_wa ?>?text=<?= urlencode('Halo, saya upline Anda di platform. Ada yang bisa saya bantu terkait penyewaan produk?') ?>" target="_blank" rel="noopener noreferrer" class="text-[10px] text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300 font-semibold font-mono flex items-center gap-1.5 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            <?= $m->phone_full ?>
                        </a>
                    </div>

                    <!-- Status Badge -->
                    <?php if ($m->is_active): ?>
                        <span class="shrink-0 text-[10px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-100 dark:bg-emerald-500/10 px-2 py-1 rounded-full">
                            <i class="fas fa-circle text-[5px] mr-0.5 align-middle"></i>Aktif
                        </span>
                    <?php else: ?>
                        <span class="shrink-0 text-[10px] font-bold text-slate-400 dark:text-slate-300 bg-slate-100 dark:bg-slate-700 px-2 py-1 rounded-full">
                            Inactive
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- ═══ HELP MODAL — Bottom Sheet ═══ -->
<div id="helpModal" class="fixed inset-0 z-[60] hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeHelpModal()"></div>
    <div class="absolute bottom-0 left-0 right-0 u-modal rounded-t-3xl max-h-[80vh] overflow-y-auto transform translate-y-full transition-transform duration-300" id="helpSheet">
        <div class="sticky top-0 u-modal px-5 pt-5 pb-3 border-b border-slate-100 dark:border-slate-800 rounded-t-3xl">
            <div class="w-10 h-1 bg-slate-200 dark:bg-slate-600 rounded-full mx-auto mb-3"></div>
            <h3 class="text-sm font-bold u-text flex items-center gap-2"><i class="fas fa-info-circle text-indigo-500"></i> Cara Kerja Bonus Afiliasi</h3>
        </div>
        <div class="px-5 py-4 space-y-4">
            <!-- Active Downline Rule -->
            <div class="bg-slate-50 dark:bg-slate-800/60 rounded-xl p-4 border border-slate-100 dark:border-slate-700">
                <h4 class="text-xs font-bold text-slate-700 dark:text-slate-200 mb-2"><i class="fas fa-users text-indigo-500 mr-1"></i> Downline Aktif</h4>
                <p class="text-[11px] u-text-2 leading-relaxed">Downline dianggap <b>aktif</b> hanya jika memiliki <b>sewa aktif</b> di sistem (status <code>active</code> di database). User yang sudah expired/tidak menyewa <b>tidak dihitung</b>.</p>
            </div>
            <!-- Level 1 -->
            <div class="bg-indigo-50 dark:bg-indigo-500/10 rounded-xl p-4 border border-indigo-100 dark:border-indigo-500/20">
                <h4 class="text-xs font-bold text-indigo-700 dark:text-indigo-300 mb-2"><i class="fas fa-gift mr-1"></i> Bonus Level 1 — Satu Kali</h4>
                <p class="text-[11px] u-text-2 leading-relaxed mb-2">Dapatkan <b>Rp <?= $l1_bonus_fmt ?></b> (sekali seumur hidup) jika:</p>
                <ul class="text-[11px] u-text-2 space-y-1 ml-3 list-disc">
                    <li>Minimal <b>3 downline langsung (B)</b> berstatus aktif</li>
                    <li>Total omset downline B aktif ≥ <b>Rp 330.000</b></li>
                </ul>
                <p class="text-[10px] text-indigo-400 mt-2 font-semibold">⚠️ Bonus ini hanya bisa diklaim 1x. Tidak bisa diulang.</p>
            </div>
            <!-- Level 2-6 -->
            <div class="bg-emerald-50 dark:bg-emerald-500/10 rounded-xl p-4 border border-emerald-100 dark:border-emerald-500/20">
                <h4 class="text-xs font-bold text-emerald-700 dark:text-emerald-300 mb-2"><i class="fas fa-money-bill-wave mr-1"></i> Gaji Mingguan — Level 2-6</h4>
                <p class="text-[11px] u-text-2 leading-relaxed mb-2">Klaim gaji mingguan berdasarkan jumlah downline aktif (semua level):</p>
                <div class="text-[11px] space-y-1">
                    <div class="flex justify-between"><span class="u-text-2">Level 2 (≥ 9 aktif)</span><span class="font-bold text-emerald-600 dark:text-emerald-400">Rp 200.000</span></div>
                    <div class="flex justify-between"><span class="u-text-2">Level 3 (≥ 30 aktif)</span><span class="font-bold text-emerald-600 dark:text-emerald-400">Rp 1.000.000</span></div>
                    <div class="flex justify-between"><span class="u-text-2">Level 4 (≥ 70 aktif)</span><span class="font-bold text-emerald-600 dark:text-emerald-400">Rp 2.500.000</span></div>
                    <div class="flex justify-between"><span class="u-text-2">Level 5 (≥ 130 aktif)</span><span class="font-bold text-emerald-600 dark:text-emerald-400">Rp 5.000.000</span></div>
                    <div class="flex justify-between"><span class="u-text-2">Level 6 (≥ 190 aktif)</span><span class="font-bold text-emerald-600 dark:text-emerald-400">Rp 9.000.000</span></div>
                </div>
                <p class="text-[10px] text-emerald-500 dark:text-emerald-400 mt-2 font-semibold">⏱️ Cooldown 7 hari antar klaim. Level ditentukan dinamis saat klaim.</p>
            </div>
            <!-- Close Button -->
            <button onclick="closeHelpModal()" class="w-full py-3 bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold rounded-xl transition-all active:scale-95">Mengerti</button>
        </div>
    </div>
</div>

<!-- QRCode.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
// P5 (plan/80): label tombol klaim L1 dinamis dari User_model::LEVEL1_BONUS.
const L1_CLAIM_LABEL = <?= json_encode('Klaim Bonus Level 1 (Rp ' . $l1_bonus_fmt . ')') ?>;
// Generate QR — hanya saat Share Center terbuka (Condition B/C). Pada
// Condition A (locked) elemen #qrcode TIDAK dirender → guard anti-null.
var qrTarget = document.getElementById('qrcode');
if (qrTarget) {
    new QRCode(qrTarget, {
        text: "<?= $ref_url ?>",
        width: 160,
        height: 160,
        colorDark: "#1e293b",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.M
    });
}

// Copy to clipboard
function copyRef() {
    var url = "<?= $ref_url ?>";
    var btn = document.getElementById('btn-copy');
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            btn.innerHTML = '<i class="fas fa-check mr-1"></i>Tersalin!';
            btn.classList.add('bg-emerald-500');
            btn.classList.remove('bg-indigo-500');
            setTimeout(function() {
                btn.innerHTML = '<i class="fas fa-copy mr-1"></i>Salin';
                btn.classList.remove('bg-emerald-500');
                btn.classList.add('bg-indigo-500');
            }, 2000);
        });
    } else {
        // Fallback
        var t = document.createElement('textarea');
        t.value = url;
        document.body.appendChild(t);
        t.select();
        document.execCommand('copy');
        document.body.removeChild(t);
        btn.innerHTML = '<i class="fas fa-check mr-1"></i>Tersalin!';
        setTimeout(function() { btn.innerHTML = '<i class="fas fa-copy mr-1"></i>Salin'; }, 2000);
    }
}

// ═══ Help Modal ═══
function openHelpModal() {
    var m = document.getElementById('helpModal');
    var s = document.getElementById('helpSheet');
    m.classList.remove('hidden');
    setTimeout(function() { s.classList.remove('translate-y-full'); s.classList.add('translate-y-0'); }, 10);
}
function closeHelpModal() {
    var m = document.getElementById('helpModal');
    var s = document.getElementById('helpSheet');
    s.classList.remove('translate-y-0');
    s.classList.add('translate-y-full');
    setTimeout(function() { m.classList.add('hidden'); }, 300);
}

// ═══ Claim AJAX ═══
// Token CSRF disuntik otomatis oleh csrfFetch() (partial templates/csrf_meta.php)

function claimLevel1() {
    var btn = document.getElementById('btn-claim-l1');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Memproses...';
    var fd = new FormData();
    csrfFetch('<?= site_url("team/claim_level1") ?>', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i>Bonus Diklaim!';
            btn.className = 'w-full bg-emerald-500 text-white text-xs font-bold py-3 px-4 rounded-xl cursor-not-allowed';
            // C4 (plan/54): endpoint mengembalikan new_balance (saldo ledger segar).
            if (d.new_balance !== undefined) {
                var bal = document.getElementById('balance-display');
                if (bal) bal.textContent = 'Rp ' + Number(d.new_balance).toLocaleString('id-ID');
            }
            showToast(d.message || 'Bonus berhasil diklaim!', 'success');
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-gift mr-1"></i>' + L1_CLAIM_LABEL;
            showToast(d.message || 'Gagal klaim', 'error');
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-gift mr-1"></i>' + L1_CLAIM_LABEL;
        showToast('Terjadi kesalahan jaringan', 'error');
    });
}

function claimWage() {
    var btn = document.getElementById('btn-claim-wage');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Memproses...';
    var fd = new FormData();
    csrfFetch('<?= site_url("team/claim_wage") ?>', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        var code = d.code || (d.success ? 'claimed' : 'error');
        if (d.success && code === 'claimed') {
            btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i>Gaji Diklaim!';
            btn.className = 'w-full bg-emerald-500 text-white text-xs font-bold py-3 px-4 rounded-xl cursor-not-allowed';
            if (d.new_balance !== undefined) {
                var bal = document.getElementById('balance-display');
                if (bal) bal.textContent = 'Rp ' + Number(d.new_balance).toLocaleString('id-ID');
            }
            showToast(d.message || 'Gaji berhasil diklaim!', 'success');
        } else if (code === 'already_claimed') {
            btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i>Sudah Diklaim Minggu Ini';
            btn.className = 'w-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-bold py-3 px-4 rounded-xl cursor-not-allowed';
            showToast(d.message || 'Gaji mingguan sudah diklaim.', 'error');
        } else if (code === 'cycle_not_ready') {
            btn.innerHTML = '<i class="fas fa-hourglass-half mr-1"></i>Cooldown Aktif';
            btn.className = 'w-full bg-slate-700 text-slate-400 text-xs font-bold py-3 px-4 rounded-xl cursor-not-allowed border border-slate-600';
            showToast(d.message || 'Cooldown aktif.', 'error');
        } else {
            // not_qualified / unauthenticated / error / too_many_attempts → re-enable
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-money-bill-wave mr-1"></i>Klaim Gaji Mingguan';
            showToast(d.message || 'Gagal klaim', 'error');
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-money-bill-wave mr-1"></i>Klaim Gaji Mingguan';
        showToast('Terjadi kesalahan jaringan', 'error');
    });
}

function showToast(msg, type) {
    var c = document.createElement('div');
    c.className = 'fixed top-4 left-1/2 -translate-x-1/2 z-[100] px-4 py-3 rounded-xl text-xs font-bold shadow-lg transition-all ' +
        (type === 'success' ? 'bg-emerald-500 text-white' : 'bg-red-500 text-white');
    c.textContent = msg;
    document.body.appendChild(c);
    setTimeout(function() { c.style.opacity = '0'; c.style.transition = 'opacity 0.3s'; }, 2500);
    setTimeout(function() { document.body.removeChild(c); }, 3000);
}

// ═══ Plan 91 — Klaim Reward Promotor ═══
var promoPid = null;
function openPromoterClaim(btn) {
    promoPid = btn.getAttribute('data-pid');
    document.getElementById('promoClaimProduct').textContent = btn.getAttribute('data-name');
    var m = document.getElementById('promoterClaimModal');
    var s = document.getElementById('promoterClaimSheet');
    if (!m || !s) return;
    m.classList.remove('hidden');
    setTimeout(function() { s.classList.remove('translate-y-full'); s.classList.add('translate-y-0'); }, 10);
}
function closePromoterClaim() {
    var m = document.getElementById('promoterClaimModal');
    var s = document.getElementById('promoterClaimSheet');
    if (!m || !s) return;
    s.classList.remove('translate-y-0');
    s.classList.add('translate-y-full');
    setTimeout(function() { m.classList.add('hidden'); }, 300);
}
function claimPromoter() {
    var btn = document.getElementById('btn-claim-promoter');
    if (!btn || btn.disabled || !promoPid) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Memproses...';
    var fd = new FormData();
    fd.append('product_id', promoPid);
    csrfFetch('<?= site_url("promoter/claim") ?>', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            closePromoterClaim();
            showToast(d.message || 'Klaim diajukan!', 'success');
            setTimeout(function() { window.location.reload(); }, 900);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i>Ajukan Klaim';
            showToast(d.message || 'Gagal mengajukan klaim', 'error');
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i>Ajukan Klaim';
        showToast('Terjadi kesalahan jaringan', 'error');
    });
}
</script>
