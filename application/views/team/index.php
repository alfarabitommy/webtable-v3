<?php
    // ── Bonus Pencapaian Tim (plan/119) — SATU sumber:
    //    User_model::get_milestone_status(). View HANYA mem-loop; tidak ada
    //    tangga tier hardcoded lagi (dulu `$w_ladder` = salinan WAGE_TIERS),
    //    dan nominal selalu dirender dinamis (L6: angka tidak masuk kamus).
    $ms_enabled   = !empty($milestones['enabled']);
    $ms_total     = (int) ($milestones['total_active'] ?? 0);
    $ms_tiers     = isset($milestones['tiers']) && is_array($milestones['tiers']) ? $milestones['tiers'] : [];
    $ms_next      = isset($milestones['next_target']) ? (int) $milestones['next_target'] : null;
    $ms_all       = !empty($milestones['all_claimed']);
    // Progres = jumlah aktif / target tier belum-diklaim terendah (integer murni).
    $ms_pct = ($ms_next !== null && $ms_next > 0)
        ? (int) min(100, intdiv($ms_total * 100, $ms_next))
        : ($ms_all ? 100 : 0);
?>
<!-- ===== CARD: BONUS PENCAPAIAN TIM (plan/119 — pengganti Gaji Mingguan) ===== -->
<?php if (!empty($ms_enabled)): ?>
<section id="ms-card" class="mx-4 mt-4 u-card-fin rounded-2xl p-4 shadow-lg">
    <!-- Header -->
    <div class="flex items-center justify-between mb-1">
        <div class="flex items-center gap-2">
            <span class="text-base">🏆</span>
            <h3 class="text-xs font-extrabold text-white uppercase tracking-wider"><?= lang('team_ms_title') ?></h3>
        </div>
        <span class="text-[10px] font-bold text-amber-400 bg-amber-500/10 px-2 py-0.5 rounded-full border border-amber-500/20 whitespace-nowrap"><?= lang('team_ms_once') ?></span>
    </div>
    <p class="text-[10px] text-slate-400 mb-3"><?= lang('team_ms_sub') ?></p>

    <!-- Progres menuju tier berikutnya (integer murni, tanpa float) -->
    <div class="mb-3">
        <div class="flex items-center justify-between mb-1 gap-2">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wide"><?= lang('team_ms_progress_label') ?></span>
            <span class="text-xs font-extrabold text-white text-right">
                <span id="ms-total"><?= number_format($ms_total, 0, ',', '.') ?></span>
                <?php if ($ms_next !== null): ?>
                    <span class="text-slate-500 font-normal"><?= sprintf(lang('team_ms_progress_to'), number_format((int) $ms_next, 0, ',', '.')) ?></span>
                <?php else: ?>
                    <span class="text-emerald-400 font-normal"><?= lang('team_ms_all_claimed') ?></span>
                <?php endif; ?>
            </span>
        </div>
        <div class="w-full h-2 bg-slate-700 rounded-full overflow-hidden">
            <div id="ms-bar" class="h-full rounded-full transition-all duration-500 <?= $ms_pct >= 100 ? 'bg-emerald-500' : 'bg-amber-500' ?>" style="width: <?= $ms_pct ?>%"></div>
        </div>
    </div>

    <?php if (count($ms_tiers) === 0): ?>
        <p class="text-[11px] text-slate-400"><?= lang('team_ms_empty') ?></p>
    <?php else: ?>
    <div id="ms-tiers" class="space-y-2">
        <?php foreach ($ms_tiers as $ms_tier):
            $t_target  = (int) $ms_tier['target'];
            $t_amount  = (int) $ms_tier['amount'];
            $t_state   = (string) $ms_tier['state'];
            $t_missing = (int) ($ms_tier['missing'] ?? 0);
        ?>
        <div class="u-card-inset rounded-xl p-3 flex items-center justify-between gap-2" data-target="<?= $t_target ?>" data-state="<?= htmlspecialchars($t_state, ENT_QUOTES, 'UTF-8') ?>">
            <div class="min-w-0">
                <p class="text-xs u-text font-bold"><?= sprintf(lang('team_ms_target_row'), $t_target) ?></p>
                <p class="text-[10px] u-muted">Rp <?= number_format($t_amount, 0, ',', '.') ?></p>
            </div>
            <div class="shrink-0 text-right" data-ms-slot>
                <?php if ($t_state === 'claimed'): ?>
                    <span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-400" title="<?= lang('team_ms_claimed_hint') ?>">
                        <i class="fas fa-check-circle"></i><?= lang('team_ms_claimed') ?>
                    </span>
                <?php elseif ($t_state === 'claimable'): ?>
                    <button type="button" id="btn-claim-ms" data-target="<?= $t_target ?>" onclick="claimMilestone(this)"
                            class="bg-amber-500 hover:bg-amber-600 text-white text-[10px] font-bold px-3 py-2 rounded-lg transition-all active:scale-95 whitespace-nowrap">
                        <i class="fas fa-trophy mr-1"></i><?= sprintf(lang('team_ms_claim_btn'), 'Rp ' . number_format($t_amount, 0, ',', '.')) ?>
                    </button>
                <?php elseif ($t_state === 'locked_count'): ?>
                    <span class="inline-flex items-center gap-1 text-[10px] font-bold text-slate-400">
                        <i class="fas fa-lock"></i><?= sprintf(lang('team_ms_need_more'), $t_missing) ?>
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1 text-[10px] font-bold text-slate-400">
                        <i class="fas fa-flag"></i><?= lang('team_ms_locked_prev') ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- ===== HELP BUTTON ===== -->
<div class="mx-4 mt-4">
    <button onclick="openHelpModal()" class="w-full flex items-center justify-center gap-2 bg-indigo-50 dark:bg-indigo-500/10 hover:bg-indigo-100 dark:hover:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 text-xs font-bold py-2.5 px-4 rounded-xl border border-indigo-200 dark:border-indigo-500/20 transition-all active:scale-95">
        <i class="fas fa-info-circle"></i> <?= lang('team_how_bonus') ?>
    </button>
</div>

<!-- ===== LAYER 1: SHARE CENTER (Plan 89 — gating referral) ===== -->
<?php if (!empty($referral_locked)): ?>
<!-- Condition A (lifetime_rentals == 0): kode/link/QR DISEMBUNYIKAN — locked state -->
<section class="mx-4 mt-3 u-card rounded-2xl p-5 shadow-sm">
    <div class="flex items-center gap-2 mb-1">
        <i class="fas fa-lock text-indigo-500"></i>
        <h2 class="text-sm font-bold u-text uppercase tracking-wide"><?= lang('team_share_center') ?></h2>
    </div>
    <div class="text-center py-5">
        <div class="w-14 h-14 mx-auto mb-3 rounded-2xl u-card-inset flex items-center justify-center">
            <i class="fas fa-gift u-muted text-xl"></i>
        </div>
        <p class="text-sm u-text font-bold mb-1"><?= lang('team_code_locked') ?></p>
        <p class="text-xs u-muted leading-relaxed max-w-[280px] mx-auto mb-4">
            <?= lang('team_locked_body') ?>
        </p>
        <a href="<?= base_url('marketplace') ?>"
           class="inline-flex items-center gap-2 bg-indigo-500 hover:bg-indigo-600 active:scale-95 text-white text-xs font-bold px-5 py-3 rounded-xl transition-all">
            <i class="fas fa-microchip mr-1"></i> <?= lang('team_view_gpu') ?>
        </a>
    </div>
</section>
<?php else: ?>
<!-- Condition B/C (lifetime_rentals >= 1): kode & link PERMANEN terlihat -->
<section class="mx-4 mt-3 u-card rounded-2xl p-5 shadow-sm">
    <div class="flex items-center gap-2 mb-1">
        <i class="fas fa-share-alt text-indigo-500"></i>
        <h2 class="text-sm font-bold u-text uppercase tracking-wide"><?= lang('team_share_center') ?></h2>
    </div>
    <p class="text-xs u-muted mb-4"><?= lang('team_share_hint') ?></p>

    <!-- Referral URL -->
    <div class="u-card-inset rounded-xl p-3 mb-4">
        <p class="text-[10px] u-muted uppercase font-semibold mb-1"><?= lang('team_invite_link') ?></p>
        <div class="flex items-center gap-2">
            <code id="ref-url" class="text-xs u-text-2 font-mono truncate flex-1"><?= $ref_url ?></code>
            <button id="btn-copy" onclick="copyRef()" class="shrink-0 bg-indigo-500 hover:bg-indigo-600 active:scale-95 text-white text-[10px] font-bold px-3 py-1.5 rounded-lg transition-all">
                <i class="fas fa-copy mr-1"></i><?= lang('team_copy_btn') ?>
            </button>
        </div>
    </div>

    <!-- QR Code (container tetap putih di dua tema — keputusan desain §2.8) -->
    <div class="flex flex-col items-center">
        <div id="qrcode" class="bg-white p-3 rounded-xl border border-slate-100 shadow-sm"></div>
        <p class="text-[10px] u-muted mt-2"><?= lang('team_scan_qr') ?></p>
    </div>
</section>
<?php endif; ?>

<!-- ===== LAYER 1B: MEMBER REBATE GUIDE (Plan 89 — 3-Tier Purchase Rebate) ===== -->
<section class="mx-4 mt-3 u-card-gpu rounded-2xl p-5 shadow-sm">
    <div class="flex items-center gap-2 mb-1">
        <i class="fas fa-network-wired text-cyan-500"></i>
        <h2 class="text-sm font-bold u-text uppercase tracking-wide"><?= lang('team_rebate_title') ?></h2>
        <?php if (empty($rebate_enabled)): ?>
            <span class="ml-auto text-[9px] font-bold text-slate-400 bg-slate-500/10 border border-slate-600 px-2 py-0.5 rounded-full"><?= lang('team_disabled') ?></span>
        <?php endif; ?>
    </div>
    <p class="text-xs u-muted mb-4">
        <?= lang('team_rebate_body') ?>
    </p>

    <div class="space-y-2">
        <div class="u-card-inset rounded-xl p-3 flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 min-w-0">
                <span class="shrink-0 w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs font-extrabold">L1</span>
                <div class="min-w-0">
                    <p class="text-xs u-text font-bold"><?= lang('team_direct_downline') ?></p>
                    <p class="text-[10px] u-muted truncate"><?= lang('team_direct_sub') ?></p>
                </div>
            </div>
            <span class="shrink-0 text-sm font-extrabold text-indigo-600 dark:text-indigo-400"><?= (int) $rebate_l1_percent ?>%</span>
        </div>
        <div class="u-card-inset rounded-xl p-3 flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 min-w-0">
                <span class="shrink-0 w-8 h-8 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 flex items-center justify-center text-xs font-extrabold">L2</span>
                <div class="min-w-0">
                    <p class="text-xs u-text font-bold"><?= lang('team_l2_name') ?></p>
                    <p class="text-[10px] u-muted truncate"><?= lang('team_l2_sub') ?></p>
                </div>
            </div>
            <span class="shrink-0 text-sm font-extrabold text-cyan-600 dark:text-cyan-400"><?= (int) $rebate_l2_percent ?>%</span>
        </div>
        <div class="u-card-inset rounded-xl p-3 flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 min-w-0">
                <span class="shrink-0 w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs font-extrabold">L3</span>
                <div class="min-w-0">
                    <p class="text-xs u-text font-bold"><?= lang('team_l3_name') ?></p>
                    <p class="text-[10px] u-muted truncate"><?= lang('team_l3_sub') ?></p>
                </div>
            </div>
            <span class="shrink-0 text-sm font-extrabold text-emerald-600 dark:text-emerald-400"><?= (int) $rebate_l3_percent ?>%</span>
        </div>
    </div>

    <div class="mt-3 rounded-xl p-3 border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10">
        <p class="text-[11px] u-text-2 leading-relaxed">
            <i class="fas fa-exclamation-triangle text-amber-500 mr-1"></i>
            <?= lang('team_rebate_req_label') ?> <b><?= lang('team_rebate_req_body1') ?></b>
            <?= lang('team_rebate_req_body2') ?> <b><?= lang('team_rebate_req_body3') ?></b>
        </p>
    </div>
</section>

<?php if (!empty($is_promoter) && $promoter_summary !== null): ?>
<?php
    // Plan 91 — Hub Program Promotor (Omzet Burn / Redeemable Quota).
    $p_sum  = $promoter_summary;
    $p_avail = (int) ($p_sum['available'] ?? 0);
    // Plan 94 (F1): label alasan disabled diterjemahkan via app_lang.
    $promo_reason_key = [
        'ok'             => 'team_promo_reason_ok',
        'omzet_kurang'   => 'team_promo_reason_omzet_kurang',
        'kuota_penuh'    => 'team_promo_reason_kuota_penuh',
        'produk_nonaktif'=> 'team_promo_reason_produk_nonaktif',
        'rasio_invalid'  => 'team_promo_reason_rasio_invalid',
        'produk_hilang'  => 'team_promo_reason_produk_hilang',
    ];
?>
<!-- ===== PROGRAM PROMOTOR (Plan 91) ===== -->
<section id="promoter-hub" class="mx-4 mt-3 u-card rounded-2xl p-5 shadow-sm border border-indigo-500/20">
    <div class="flex items-center gap-2 mb-1">
        <i class="fas fa-star text-amber-400"></i>
        <h2 class="text-sm font-bold u-text uppercase tracking-wide"><?= lang('team_promo_program') ?></h2>
        <span class="ml-auto text-[9px] font-bold text-amber-500 bg-amber-500/10 border border-amber-500/30 px-2 py-0.5 rounded-full"><?= lang('team_promo_badge') ?></span>
    </div>
    <p class="text-[10px] u-muted mb-4"><?= lang('team_promo_sub') ?></p>

    <!-- Statistik omzet L1 -->
    <div class="grid grid-cols-2 gap-2 mb-4">
        <div class="u-card-inset rounded-xl p-3">
            <p class="text-[10px] u-muted font-semibold uppercase tracking-wide"><?= lang('team_promo_omzet_total') ?></p>
            <p class="text-base font-extrabold u-text mt-1">Rp <?= number_format((int) ($p_sum['total_l1'] ?? 0), 0, ',', '.') ?></p>
        </div>
        <div class="u-card-inset rounded-xl p-3">
            <p class="text-[10px] u-muted font-semibold uppercase tracking-wide"><?= lang('team_promo_burned') ?></p>
            <p class="text-base font-extrabold u-text mt-1">Rp <?= number_format((int) ($p_sum['burned'] ?? 0), 0, ',', '.') ?></p>
        </div>
        <div class="u-card-inset rounded-xl p-3">
            <p class="text-[10px] u-muted font-semibold uppercase tracking-wide"><?= lang('team_promo_locked') ?></p>
            <p class="text-base font-extrabold u-text mt-1">Rp <?= number_format((int) ($p_sum['locked'] ?? 0), 0, ',', '.') ?></p>
        </div>
        <div class="bg-emerald-50 dark:bg-emerald-500/10 rounded-xl p-3 border border-emerald-100 dark:border-emerald-500/20">
            <p class="text-[10px] text-emerald-500 dark:text-emerald-400 font-semibold uppercase tracking-wide"><?= lang('team_promo_available') ?></p>
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
            $pname  = htmlspecialchars((string) ($t['product_name'] ?? lang('team_promo_product_default')), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="u-card-inset rounded-xl p-4">
            <div class="flex items-start justify-between gap-2 mb-2">
                <div class="min-w-0">
                    <p class="text-xs u-text font-extrabold truncate"><?= $pname ?></p>
                    <p class="text-[10px] u-muted mt-0.5"><?= lang('team_promo_reward_value') ?> <span class="font-bold text-emerald-600 dark:text-emerald-400">Rp <?= number_format($price, 0, ',', '.') ?></span></p>
                </div>
                <span class="shrink-0 text-[10px] font-extrabold text-amber-600 dark:text-amber-400 bg-amber-500/10 border border-amber-500/20 px-2 py-1 rounded-lg">Rp <?= number_format($cost, 0, ',', '.') ?></span>
            </div>
            <div class="flex items-center justify-between mb-1">
                <span class="text-[9px] u-muted font-semibold uppercase tracking-wide"><?= lang('team_promo_progress') ?></span>
                <span class="text-[10px] u-text-2 font-mono"><?= number_format($p_avail, 0, ',', '.') ?> / <?= number_format($cost, 0, ',', '.') ?></span>
            </div>
            <div class="w-full h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden mb-3">
                <div class="h-full rounded-full <?= $pct >= 100 ? 'bg-emerald-500' : 'bg-indigo-500' ?>" style="width: <?= $pct ?>%"></div>
            </div>

            <?php if (!empty($t['can_claim'])): ?>
                <button type="button" data-pid="<?= (int) $t['product_id'] ?>" data-name="<?= $pname ?>"
                        onclick="openPromoterClaim(this)"
                        class="w-full bg-indigo-500 hover:bg-indigo-600 text-white text-xs font-bold py-2.5 px-4 rounded-xl transition-all active:scale-[0.97]">
                    <i class="fas fa-gift mr-1"></i><?= sprintf(lang('team_promo_claim_btn'), number_format($price, 0, ',', '.')) ?>
                </button>
            <?php else: ?>
                <button type="button" disabled
                        class="w-full bg-slate-700 text-slate-400 text-xs font-bold py-2.5 px-4 rounded-xl cursor-not-allowed border border-slate-600">
                    <i class="fas fa-lock mr-1"></i><?= htmlspecialchars((string) lang($promo_reason_key[$t['reason'] ?? 'produk_hilang'] ?? 'team_promo_reason_produk_hilang'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Riwayat klaim -->
    <div>
        <p class="text-[10px] u-muted uppercase font-semibold tracking-wide mb-2"><?= lang('team_promo_history') ?></p>
        <?php if (empty($promoter_history)): ?>
            <p class="text-[11px] u-muted py-3 text-center rounded-xl u-card-inset"><?= lang('team_promo_history_empty') ?></p>
        <?php else: ?>
            <div class="max-h-56 overflow-y-auto space-y-2">
                <?php foreach ($promoter_history as $h): ?>
                    <div class="u-card-inset rounded-xl px-3 py-2 flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-xs u-text font-semibold truncate"><?= htmlspecialchars((string) ($h->product_name ?? 'Reward'), ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="text-[10px] u-muted">Rp <?= number_format((int) $h->omzet_cost, 0, ',', '.') ?> · <?= i18n_datetime($h->created_at) ?></p>
                            <?php if ($h->status === 'rejected' && !empty($h->admin_notes)): ?>
                                <p class="text-[9px] text-amber-600 dark:text-amber-400 mt-0.5 truncate"><?= sprintf(lang('team_promo_reason_text'), htmlspecialchars($h->admin_notes, ENT_QUOTES, 'UTF-8')) ?></p>
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
            <h3 class="text-sm font-bold u-text flex items-center gap-2"><i class="fas fa-gift text-indigo-500"></i> <?= lang('team_promo_modal_title') ?></h3>
        </div>
        <div class="px-5 py-4 space-y-4">
            <div class="bg-slate-50 dark:bg-slate-800/60 rounded-xl p-4 border border-slate-100 dark:border-slate-700">
                <p class="text-xs u-text font-bold mb-1" id="promoClaimProduct">—</p>
                <p class="text-[11px] u-text-2 leading-relaxed">
                    <?= lang('team_promo_modal_body') ?>
                </p>
            </div>
            <button id="btn-claim-promoter" onclick="claimPromoter()"
                    class="w-full bg-indigo-500 hover:bg-indigo-600 text-white text-xs font-bold py-3 px-4 rounded-xl transition-all active:scale-[0.97] shadow-lg shadow-indigo-500/20">
                <i class="fas fa-paper-plane mr-1"></i><?= lang('team_promo_submit') ?>
            </button>
            <button onclick="closePromoterClaim()"
                    class="w-full py-2.5 u-btn-ghost rounded-xl text-xs font-bold u-text-2 transition-colors">
                <?= lang('common_cancel') ?>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===== LAYER 2: GAMIFICATION ===== -->
<section class="mx-4 mt-3 u-card rounded-2xl p-5 shadow-sm">
    <div class="flex items-center gap-2 mb-4">
        <i class="fas fa-chart-bar text-indigo-500"></i>
        <h2 class="text-sm font-bold u-text uppercase tracking-wide"><?= lang('team_stats_title') ?></h2>
    </div>

    <!-- Metric Cards -->
    <div class="grid grid-cols-2 gap-3 mb-4">
        <div class="u-card-inset rounded-xl p-3 text-center">
            <p class="text-2xl font-extrabold u-text"><?= $total_bc ?></p>
            <p class="text-[10px] u-muted font-semibold mt-0.5"><?= lang('team_total_members') ?></p>
        </div>
        <div class="bg-emerald-50 dark:bg-emerald-500/10 rounded-xl p-3 text-center border border-emerald-100 dark:border-emerald-500/20">
            <p class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400"><?= $active_bc ?></p>
            <p class="text-[10px] text-emerald-500 dark:text-emerald-400 font-semibold mt-0.5"><?= lang('team_active_members') ?></p>
        </div>
    </div>

    <!-- Level Breakdown -->
    <div class="grid grid-cols-2 gap-3">
        <div class="bg-indigo-50 dark:bg-indigo-500/10 rounded-xl p-3 text-center border border-indigo-100 dark:border-indigo-500/20">
            <p class="text-2xl font-extrabold text-indigo-600 dark:text-indigo-400"><?= $l1_active ?></p>
            <p class="text-[10px] text-indigo-500 dark:text-indigo-400 font-semibold mt-0.5"><?= lang('team_l1_active') ?></p>
        </div>
        <div class="u-card-inset rounded-xl p-3 text-center">
            <p class="text-2xl font-extrabold u-text"><?= $l2_active ?></p>
            <p class="text-[10px] u-muted font-semibold mt-0.5"><?= lang('team_l2_active') ?></p>
        </div>
    </div>
</section>

<!-- ===== LAYER 3: MEMBER LIST ===== -->
<section class="mx-4 mt-3 mb-24 u-card rounded-2xl p-5 shadow-sm">
    <div class="flex items-center gap-2 mb-4">
        <i class="fas fa-users text-indigo-500"></i>
        <h2 class="text-sm font-bold u-text uppercase tracking-wide"><?= lang('team_members_title') ?></h2>
        <span class="ml-auto text-[10px] bg-slate-100 dark:bg-slate-700 dark:text-slate-300 text-slate-500 font-bold px-2 py-0.5 rounded-full"><?= $total_bc ?></span>
    </div>

    <?php if (empty($members)): ?>
        <!-- Empty State -->
        <div class="text-center py-8">
            <i class="fas fa-user-plus text-4xl u-muted mb-3"></i>
            <p class="text-sm u-text-2 font-semibold"><?= lang('team_members_empty') ?></p>
            <p class="text-[10px] u-muted mt-1"><?= lang('team_members_empty_sub') ?></p>
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
                        <a href="https://wa.me/<?= $m->phone_wa ?>?text=<?= urlencode(lang('team_wa_message')) ?>" target="_blank" rel="noopener noreferrer" class="text-[10px] text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300 font-semibold font-mono flex items-center gap-1.5 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            <?= $m->phone_full ?>
                        </a>
                    </div>

                    <!-- Status Badge -->
                    <?php if ($m->is_active): ?>
                        <span class="shrink-0 text-[10px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-100 dark:bg-emerald-500/10 px-2 py-1 rounded-full">
                            <i class="fas fa-circle text-[5px] mr-0.5 align-middle"></i><?= lang('team_active_badge') ?>
                        </span>
                    <?php else: ?>
                        <span class="shrink-0 text-[10px] font-bold text-slate-400 dark:text-slate-300 bg-slate-100 dark:bg-slate-700 px-2 py-1 rounded-full">
                            <?= lang('team_inactive_badge') ?>
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
            <h3 class="text-sm font-bold u-text flex items-center gap-2"><i class="fas fa-info-circle text-indigo-500"></i> <?= lang('team_help_title') ?></h3>
        </div>
        <div class="px-5 py-4 space-y-4">
            <!-- Active Downline Rule -->
            <div class="bg-slate-50 dark:bg-slate-800/60 rounded-xl p-4 border border-slate-100 dark:border-slate-700">
                <h4 class="text-xs font-bold text-slate-700 dark:text-slate-200 mb-2"><i class="fas fa-users text-indigo-500 mr-1"></i> <?= lang('team_help_active_downline') ?></h4>
                <p class="text-[11px] u-text-2 leading-relaxed"><?= lang('team_help_active_body') ?></p>
            </div>
            <!-- Bonus Pencapaian Tim (plan/119) — baris tier di-loop dari konfigurasi -->
            <?php if (!empty($ms_enabled)): ?>
            <div class="bg-amber-50 dark:bg-amber-500/10 rounded-xl p-4 border border-amber-100 dark:border-amber-500/20">
                <h4 class="text-xs font-bold text-amber-700 dark:text-amber-300 mb-2"><i class="fas fa-trophy mr-1"></i> <?= lang('team_ms_help_title') ?></h4>
                <p class="text-[11px] u-text-2 leading-relaxed mb-2"><?= lang('team_ms_help_body') ?></p>
                <?php if (count($ms_tiers) > 0): ?>
                <div class="text-[11px] space-y-1">
                    <?php foreach ($ms_tiers as $ms_tier): ?>
                    <div class="flex justify-between gap-2">
                        <span class="u-text-2"><?= sprintf(lang('team_ms_help_row'), (int) $ms_tier['target'], 'Rp ' . number_format((int) $ms_tier['amount'], 0, ',', '.')) ?></span>
                        <?php if ($ms_tier['state'] === 'claimed'): ?>
                            <span class="shrink-0 font-bold text-emerald-600 dark:text-emerald-400"><i class="fas fa-check-circle"></i></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <p class="text-[10px] text-amber-600 dark:text-amber-400 mt-2 font-semibold"><?= lang('team_ms_help_note') ?></p>
            </div>
            <?php endif; ?>
            <!-- Close Button -->
            <button onclick="closeHelpModal()" class="w-full py-3 bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold rounded-xl transition-all active:scale-95"><?= lang('common_got_it') ?></button>
        </div>
    </div>
</div>

<!-- QRCode.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
// Plan 94 (F1): default pesan JS halaman — diterjemahkan server-side
const I18N = <?= json_encode([
    'claim_failed'  => lang('team_js_claim_failed'),
    'network_error' => lang('team_js_network_error'),
    // plan/119: Bonus Pencapaian Tim (template %s/%d dirender server-side)
    'ms_success'        => lang('team_ms_js_success'),
    'ms_already'        => lang('team_ms_js_already'),
    'ms_locked'         => lang('team_ms_js_locked'),
    'ms_not_qualified'  => lang('team_ms_js_not_qualified'),
    'ms_invalid'        => lang('team_ms_js_invalid'),
    'ms_disabled'       => lang('team_ms_js_disabled'),
    'ms_claimed'        => lang('team_ms_claimed'),
    'ms_need_more'      => lang('team_ms_need_more'),
    'ms_locked_prev'    => lang('team_ms_locked_prev'),
    'ms_claim_btn'      => lang('team_ms_claim_btn'),
    'ms_target_row'     => lang('team_ms_target_row'),
    'ms_progress_to'    => lang('team_ms_progress_to'),
    'ms_all_claimed'    => lang('team_ms_all_claimed'),
    'promo_submit'      => lang('team_promo_submit'),
    'promo_claimed'     => lang('team_js_promo_claimed'),
    'promo_failed'      => lang('team_js_promo_failed'),
], JSON_UNESCAPED_UNICODE) ?>;
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
    var copyHtml = btn ? btn.innerHTML : '';
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            btn.innerHTML = '<i class="fas fa-check mr-1"></i>' + window.SYNAPSE_I18N['js_copied'];
            btn.classList.add('bg-emerald-500');
            btn.classList.remove('bg-indigo-500');
            setTimeout(function() {
                btn.innerHTML = copyHtml;
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
        btn.innerHTML = '<i class="fas fa-check mr-1"></i>' + window.SYNAPSE_I18N['js_copied'];
        setTimeout(function() { btn.innerHTML = copyHtml; }, 2000);
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

// ═══ plan/119 — Bonus Pencapaian Tim: klaim + repaint ═══
// Template kamus memakai placeholder printf (`%1$d`/`%2$s`/'%d'/'%s') —
// dirender di klien dengan urutan argumen (server yang memiliki copy-nya, L1 i18n).
function msTpl(tpl, args) {
    var i = 0;
    return String(tpl == null ? '' : tpl).replace(/%(\d+\$)?[ds]/g, function () {
        return (i < args.length) ? String(args[i++]) : '';
    });
}
function msEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function msIdr(n) {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(n) || 0);
}

// Repaint seluruh daftar tier dari payload `milestones` (state SEGAR dari
// server — tidak ada perhitungan kredensial/kualifikasi di klien).
function paintMilestones(ms) {
    var box = document.getElementById('ms-tiers');
    if (!box || !ms || !ms.tiers) return;

    var total = Number(ms.total_active) || 0;
    var next  = (ms.next_target === null || ms.next_target === undefined) ? null : Number(ms.next_target);
    var all   = !!ms.all_claimed;

    var totalEl = document.getElementById('ms-total');
    if (totalEl) totalEl.textContent = msIdr(total);

    var bar = document.getElementById('ms-bar');
    if (bar) {
        var pct = (next && next > 0) ? Math.min(100, Math.floor(total * 100 / next)) : (all ? 100 : 0);
        bar.style.width = pct + '%';
        bar.className = 'h-full rounded-full transition-all duration-500 ' + (pct >= 100 ? 'bg-emerald-500' : 'bg-amber-500');
    }

    var html = '';
    ms.tiers.forEach(function (t) {
        var target = Number(t.target) || 0;
        var amount = Number(t.amount) || 0;
        var state  = String(t.state || '');
        var slot;

        if (state === 'claimed') {
            slot = '<span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-400">'
                 + '<i class="fas fa-check-circle"></i>' + msEsc(I18N.ms_claimed) + '</span>';
        } else if (state === 'claimable') {
            slot = '<button type="button" id="btn-claim-ms" data-target="' + target + '" onclick="claimMilestone(this)"'
                 + ' class="bg-amber-500 hover:bg-amber-600 text-white text-[10px] font-bold px-3 py-2 rounded-lg transition-all active:scale-95 whitespace-nowrap">'
                 + '<i class="fas fa-trophy mr-1"></i>' + msEsc(msTpl(I18N.ms_claim_btn, ['Rp ' + msIdr(amount)])) + '</button>';
        } else if (state === 'locked_count') {
            slot = '<span class="inline-flex items-center gap-1 text-[10px] font-bold text-slate-400">'
                 + '<i class="fas fa-lock"></i>' + msEsc(msTpl(I18N.ms_need_more, [Number(t.missing) || 0])) + '</span>';
        } else {
            slot = '<span class="inline-flex items-center gap-1 text-[10px] font-bold text-slate-400">'
                 + '<i class="fas fa-flag"></i>' + msEsc(I18N.ms_locked_prev) + '</span>';
        }

        html += '<div class="u-card-inset rounded-xl p-3 flex items-center justify-between gap-2" data-target="' + target + '" data-state="' + msEsc(state) + '">'
              + '<div class="min-w-0">'
              + '<p class="text-xs u-text font-bold">' + msEsc(msTpl(I18N.ms_target_row, [target])) + '</p>'
              + '<p class="text-[10px] u-muted">Rp ' + msIdr(amount) + '</p>'
              + '</div>'
              + '<div class="shrink-0 text-right" data-ms-slot>' + slot + '</div>'
              + '</div>';
    });

    box.innerHTML = html;
}

function claimMilestone(btn) {
    if (!btn || btn.disabled) return;
    var target = btn.getAttribute('data-target');
    var html   = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>' + ((window.SYNAPSE_I18N || {})['js_processing'] || '...');

    var fd = new FormData();
    fd.append('target', target);

    csrfFetch('<?= site_url("team/claim_milestone") ?>', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        var code = d.code || (d.success ? 'claimed' : 'error');

        if (d.success && code === 'claimed') {
            if (d.new_balance !== undefined) {
                var bal = document.getElementById('balance-display');
                if (bal) bal.textContent = 'Rp ' + Number(d.new_balance).toLocaleString('id-ID');
            }
            // State segar dari server → repaint kartu tanpa reload.
            if (d.milestones) paintMilestones(d.milestones);
            showToast(d.message || I18N.ms_success, 'success');
            return;
        }

        if (d.milestones) paintMilestones(d.milestones);

        if (code === 'already_claimed' || code === 'locked_previous'
            || code === 'invalid_target' || code === 'user_unavailable') {
            // State server sudah final → tampilkan apa adanya (tanpa re-enable).
            showToast(d.message || I18N.ms_already, 'error');
            return;
        }

        // not_qualified / disabled / unauthenticated / error / too_many_attempts
        // → pulihkan tombol supaya dapat dicoba lagi.
        btn.disabled = false;
        btn.innerHTML = html;
        if (code === 'not_qualified')    showToast(d.message || I18N.ms_not_qualified, 'error');
        else if (code === 'disabled')    showToast(d.message || I18N.ms_disabled, 'error');
        else if (code === 'invalid_target') showToast(d.message || I18N.ms_invalid, 'error');
        else                             showToast(d.message || I18N.claim_failed, 'error');
    })
    .catch(function () {
        btn.disabled = false;
        btn.innerHTML = html;
        showToast(I18N.network_error, 'error');
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
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>' + ((window.SYNAPSE_I18N || {})['js_processing'] || 'Memproses...');
    var fd = new FormData();
    fd.append('product_id', promoPid);
    csrfFetch('<?= site_url("promoter/claim") ?>', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            closePromoterClaim();
            showToast(d.message || I18N.promo_claimed, 'success');
            setTimeout(function() { window.location.reload(); }, 900);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i>' + I18N.promo_submit;
            showToast(d.message || I18N.promo_failed, 'error');
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i>' + I18N.promo_submit;
        showToast(I18N.network_error, 'error');
    });
}
</script>
