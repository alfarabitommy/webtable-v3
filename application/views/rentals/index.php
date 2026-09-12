<!-- ═══ Infrastruktur Aktif — Dark Server Blade Aesthetic ═══ -->

<!-- ═══ HELP BUTTON ═══ -->
<div class="px-4 pt-4">
    <button onclick="openRentalHelpModal()" class="w-full flex items-center justify-center gap-2 bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 text-[11px] font-bold py-2 px-4 rounded-xl border border-indigo-500/20 transition-all active:scale-95">
        <i class="fas fa-info-circle"></i> <?= lang('rental_how_it_works') ?>
    </button>
</div>

<style>
    .blade-bg {
        /* Phase 32: tokenized — flips with theme (dark: Neural Surface; light: soft slate) */
        background: linear-gradient(180deg, var(--u-surface) 0%, var(--u-surface-2) 40%, var(--u-bg) 100%);
        color: var(--u-text);
        min-height: calc(100vh - 140px);
    }
    .glow-border {
        position: relative;
    }
    .glow-border::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, #3b82f6, #8b5cf6, #3b82f6, transparent);
        border-radius: 1rem 1rem 0 0;
    }
    .pulse-dot {
        animation: pulse-glow 2s ease-in-out infinite;
    }
    @keyframes pulse-glow {
        0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6); }
        50% { box-shadow: 0 0 8px 3px rgba(16, 185, 129, 0.2); }
    }
    .scanline {
        background: repeating-linear-gradient(
            0deg,
            transparent,
            transparent 2px,
            rgba(56, 189, 248, 0.03) 2px,
            rgba(56, 189, 248, 0.03) 4px
        );
    }
    .card-hover {
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .card-hover:active {
        transform: scale(0.985);
    }
    .data-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1px;
        background: rgba(100, 116, 139, 0.15);
        border-radius: 0.75rem;
        overflow: hidden;
    }
    .data-cell {
        background: rgba(15, 23, 42, 0.8);
        padding: 0.75rem 1rem;
    }
    .data-cell span {
        font-size: 0.625rem;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #64748b;
        font-weight: 700;
    }
    .progress-bar {
        height: 6px;
        background: rgba(30, 41, 59, 0.8);
        border-radius: 9999px;
        overflow: hidden;
    }
    .progress-fill {
        height: 100%;
        border-radius: 9999px;
        background: linear-gradient(90deg, #3b82f6, #6366f1);
        transition: width 0.6s ease;
    }
</style>

<div class="blade-bg scanline p-4 pb-28">

    <!-- ═══ Page Header ═══ -->
    <div class="mb-6">
        <div class="flex items-center gap-2 mb-1">
            <div class="w-1.5 h-1.5 rounded-full bg-blue-600 pulse-dot"></div>
            <span class="text-[10px] font-bold text-blue-600 uppercase tracking-[0.2em]"><?= lang('rental_system_monitor') ?></span>
        </div>
        <h2 class="flex items-center gap-2 text-xl font-extrabold tracking-tight text-transparent bg-clip-text bg-gradient-to-r from-indigo-600 to-cyan-600 dark:from-cyan-300 dark:to-indigo-300">
            <i class="fas fa-microchip text-blue-600"></i> <?= lang('rental_active_infra_title') ?>
        </h2>
        <p class="text-xs u-text-2 mt-1"><?= lang('rental_sub') ?></p>
    </div>

    <!-- ═══ Flash Messages ═══ -->
    <?php if ($this->session->flashdata('error')): ?>
    <div class="u-flash-error px-4 py-3 rounded-xl mb-4 text-sm font-semibold flex items-center gap-2 shadow-sm">
        <i class="fas fa-exclamation-circle"></i> <?= $this->session->flashdata('error') ?>
    </div>
    <?php endif; ?>
    <?php if ($this->session->flashdata('success')): ?>
    <div class="u-flash-success px-4 py-3 rounded-xl mb-4 text-sm font-semibold flex items-center gap-2 shadow-sm">
        <i class="fas fa-check-circle"></i> <?= $this->session->flashdata('success') ?>
    </div>
    <?php endif; ?>

    <!-- ═══ Empty State ═══ -->
    <?php if (empty($rentals)): ?>
    <div class="flex flex-col items-center justify-center py-20 text-center">
        <div class="w-20 h-20 rounded-full u-card-inset flex items-center justify-center mb-5">
            <i class="fas fa-server text-3xl u-muted"></i>
        </div>
        <h3 class="text-lg font-bold u-text-2 mb-2"><?= lang('rental_empty_title') ?></h3>
        <p class="text-xs u-muted max-w-[240px] leading-relaxed"><?= lang('rental_empty_body') ?></p>
        <a href="<?= site_url('marketplace') ?>" class="mt-6 px-6 py-3 bg-blue-600 hover:bg-blue-500 text-white text-sm font-bold rounded-xl transition-all active:scale-95 flex items-center gap-2">
            <i class="fas fa-store"></i> <?= lang('rental_open_market') ?>
        </a>
    </div>

    <?php else: ?>

    <!-- ═══ Active Rental Cards ═══ -->
    <?php foreach ($rentals as $rental): ?>
        <?php
        // $actual_claimable is pre-calculated by controller
        $actual_claimable = (int) ($rental->actual_claimable ?? 0);

        $expired = strtotime($rental->expired_at);
        $days_left = max(0, ceil(($expired - time()) / 86400));

        // Progress bar percentage
        $total_days = max(1, (int) $rental->total_days);
        $days_processed = (int) $rental->days_processed;
        $progress_pct = min(100, ($days_processed / $total_days) * 100);

        // C2/P8 + plan/46: status klaim-hari-ini, kelengkapan kontrak &
        // kedaluwarsa dihitung controller dari Rental_model::claimable_info
        // (single source of truth — identik dengan mesin klaim). Disabled
        // state di sini hanya kosmetik; server (row lock) tetap otoritas
        // anti double-payout.
        $is_claimed_today = (bool) ($rental->is_claimed_today ?? false);
        $is_completed     = (bool) ($rental->is_completed ?? false);
        $is_expired       = (bool) ($rental->is_expired ?? false);
        ?>

    <div class="glow-border u-card-fin rounded-2xl p-5 mb-4 shadow-xl relative overflow-hidden card-hover">
        <!-- Subtle background pattern -->
        <div class="absolute inset-0 opacity-[0.03]" style="background-image: url('data:image/svg+xml,<svg width=&quot;20&quot; height=&quot;20&quot; xmlns=&quot;http://www.w3.org/2000/svg&quot;><circle cx=&quot;1&quot; cy=&quot;1&quot; r=&quot;0.5&quot; fill=&quot;white&quot;/></svg>'); background-size: 20px 20px;"></div>

        <!-- Header Row -->
        <div class="relative flex items-start justify-between mb-4">
            <div class="flex-1 min-w-0">
                <h3 class="text-base font-extrabold text-white truncate"><?= htmlspecialchars($rental->product_name ?? 'Node #' . $rental->product_id) ?></h3>
                <p class="text-[10px] text-slate-500 mt-0.5 font-mono">ID: #<?= $rental->id ?> · <?= i18n_date($rental->created_at) ?></p>
            </div>
            <div class="flex items-center gap-1.5 bg-emerald-500/10 border border-emerald-500/20 rounded-full px-2.5 py-1 ml-3 flex-shrink-0">
                <div class="w-2 h-2 rounded-full bg-emerald-500 pulse-dot"></div>
                <span class="text-[10px] text-emerald-400 font-bold uppercase tracking-wider"><?= lang('common_online') ?></span>
            </div>
        </div>

        <!-- Data Grid -->
        <div class="relative data-grid mb-4">
            <div class="data-cell">
                <span><?= lang('common_rent_price_label') ?></span>
                <p class="text-sm font-extrabold text-white mt-0.5">Rp <?= number_format($rental->purchase_price, 0, ',', '.') ?></p>
            </div>
            <div class="data-cell">
                <span><?= lang('common_daily_roi_label') ?></span>
                <p class="text-sm font-extrabold text-emerald-400 mt-0.5">Rp <?= number_format($rental->daily_roi, 0, ',', '.') ?></p>
            </div>
        </div>

        <!-- Expiry Info -->
        <div class="relative flex items-center justify-between text-[11px] text-slate-500 mb-2">
            <span><i class="fas fa-clock mr-1"></i> <?= sprintf(lang('rental_expires_on'), i18n_date($expired)) ?></span>
            <span class="font-mono <?= $days_left <= 3 ? 'text-rose-400' : 'text-slate-500' ?>"><?= sprintf(lang('rental_days_left'), $days_left) ?></span>
        </div>

        <!-- Progress Bar -->
        <div class="relative progress-bar mb-1">
            <div class="progress-fill" style="width: <?= $progress_pct ?>%"></div>
        </div>
        <p class="text-[10px] text-slate-500 mb-4 font-mono">
            <?= sprintf(lang('rental_claim_days'), $days_processed, $total_days) ?> · <span class="text-slate-400"><?= lang('rental_roi_cap') ?></span>
        </p>

        <!-- Claim Button — plan/46: mesin state 4-cabang deterministik.
             Hanya actual_claimable >= 1 yang merender <form>; state H+1
             (actual_claimable < 1) TANPA form → mustahil submit dari UI. -->
        <?php if ($is_completed): ?>
        <button disabled aria-disabled="true" class="w-full h-12 bg-slate-800 text-slate-500 font-bold text-sm rounded-xl cursor-not-allowed border border-slate-700/50 flex justify-center items-center gap-2">
            <i class="fas fa-lock text-slate-600"></i> <?= lang('rental_contract_done') ?>
        </button>
        <?php elseif ($is_expired): ?>
        <button disabled aria-disabled="true" class="w-full h-12 bg-slate-800 text-slate-500 font-bold text-sm rounded-xl cursor-not-allowed border border-slate-700/50 flex justify-center items-center gap-2">
            <i class="fas fa-lock text-slate-600"></i> <?= lang('rental_contract_expired') ?>
        </button>
        <?php elseif ($is_claimed_today): ?>
        <button disabled aria-disabled="true" class="w-full h-12 bg-slate-800 text-slate-500 font-bold text-sm rounded-xl cursor-not-allowed border border-slate-700/50 flex justify-center items-center gap-2">
            <i class="fas fa-check-circle text-slate-600"></i> <?= lang('rental_claimed_today') ?>
        </button>
        <?php elseif ($actual_claimable < 1): ?>
        <button disabled aria-disabled="true" class="w-full h-12 bg-slate-800 text-slate-500 font-bold text-sm rounded-xl cursor-not-allowed border border-slate-700/50 opacity-60 flex justify-center items-center gap-2">
            <i class="fas fa-clock text-slate-600"></i> <?= lang('rental_not_yet') ?>
        </button>
        <?php else: ?>
        <?php echo form_open('rentals/claim/' . $rental->id, ['class' => 'w-full m-0 p-0 claim-form']); ?>
            <button type="submit" class="w-full h-12 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white font-bold rounded-xl shadow-[0_0_15px_rgba(37,99,235,0.4)] transform transition active:scale-95 flex justify-center items-center gap-2">
                <i class="fas fa-bolt text-yellow-400"></i> <?= sprintf(lang('rental_claim_roi'), 'Rp ' . number_format($rental->daily_roi * $actual_claimable, 0, ',', '.')) ?><?= $actual_claimable >= 2 ? ' <span class="text-[10px] font-normal opacity-80">' . lang('rental_two_days') . '</span>' : '' ?>
            </button>
        <?php echo form_close(); ?>
        <?php endif; ?>

    </div>
    <?php endforeach; ?>

    <!-- ═══ Summary Bar ═══ -->
    <?php
        $total_roi = 0;
        $total_pending = 0;
        foreach ($rentals as $rental) {
            $total_roi += $rental->daily_roi;
            $actual = (int) ($rental->actual_claimable ?? 0);
            $total_pending += $actual * $rental->daily_roi;
        }
    ?>
    <div class="relative bg-gradient-to-br from-slate-800 via-slate-900 to-indigo-950 border border-slate-700/50 rounded-xl p-4 mt-2 shadow-xl overflow-hidden shadow-[inset_0_1px_0_rgba(255,255,255,0.05)]">
        <div class="absolute top-0 left-0 w-full h-[1px] bg-gradient-to-r from-blue-500 via-indigo-500 to-purple-500"></div>
        <div class="flex items-center justify-between relative z-10">
            <div class="flex-1">
                <span class="text-[10px] text-slate-500 uppercase tracking-widest font-bold flex items-center gap-1.5">
                    <i class="fas fa-chart-line text-blue-400"></i> <?= lang('rental_est_claimable') ?>
                </span>
                <p class="text-2xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-indigo-400">Rp <?= number_format($total_pending, 0, ',', '.') ?></p>
                <p class="text-[10px] text-slate-500 mt-0.5"><?= sprintf(lang('rental_potential_day'), 'Rp ' . number_format($total_roi, 0, ',', '.')) ?></p>
            </div>
            <div class="border-l border-slate-700/50 pl-4 text-right min-w-[100px]">
                <span class="text-[10px] text-slate-500 uppercase tracking-widest font-bold flex items-center justify-end gap-1.5">
                    <i class="fas fa-server text-blue-400"></i> <?= lang('rental_total_nodes') ?>
                </span>
                <p class="text-2xl font-extrabold text-white"><?= count($rentals) ?></p>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<!-- ═══ HELP MODAL — Rental Rules Bottom Sheet ═══ -->
<div id="rentalHelpModal" class="fixed inset-0 z-[60] hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeRentalHelpModal()"></div>
    <div class="absolute bottom-0 left-0 right-0 u-modal rounded-t-3xl max-h-[80vh] overflow-y-auto transform translate-y-full transition-transform duration-300" id="rentalHelpSheet">
        <div class="sticky top-0 u-modal px-5 pt-5 pb-3 border-b border-slate-100 dark:border-slate-800 rounded-t-3xl">
            <div class="w-10 h-1 bg-slate-200 dark:bg-slate-600 rounded-full mx-auto mb-3"></div>
            <h3 class="text-sm font-bold u-text flex items-center gap-2"><i class="fas fa-info-circle text-indigo-500"></i> <?= lang('rental_help_title') ?></h3>
        </div>
        <div class="px-5 py-4 space-y-4">
            <!-- 2-Day Accumulation -->
            <div class="bg-indigo-50 dark:bg-indigo-500/10 rounded-xl p-4 border border-indigo-100 dark:border-indigo-500/20">
                <h4 class="text-xs font-bold text-indigo-700 dark:text-indigo-300 mb-2"><i class="fas fa-clock mr-1"></i> <?= lang('rental_help_acc_title') ?></h4>
                <p class="text-[11px] u-text-2 leading-relaxed"><?= lang('rental_help_acc_body') ?></p>
                <p class="text-[10px] text-indigo-600 dark:text-indigo-400 mt-2 font-semibold"><?= lang('rental_help_acc_example') ?></p>
            </div>
            <!-- Use It or Lose It -->
            <div class="bg-amber-50 dark:bg-amber-500/10 rounded-xl p-4 border border-amber-100 dark:border-amber-500/20">
                <h4 class="text-xs font-bold text-amber-700 dark:text-amber-300 mb-2"><i class="fas fa-exclamation-triangle mr-1"></i> <?= lang('rental_help_lose_title') ?></h4>
                <p class="text-[11px] u-text-2 leading-relaxed"><?= lang('rental_help_lose_body') ?></p>
            </div>
            <!-- Over-payment Protection -->
            <div class="bg-emerald-50 dark:bg-emerald-500/10 rounded-xl p-4 border border-emerald-100 dark:border-emerald-500/20">
                <h4 class="text-xs font-bold text-emerald-700 dark:text-emerald-300 mb-2"><i class="fas fa-shield-alt mr-1"></i> <?= lang('rental_help_overpay_title') ?></h4>
                <p class="text-[11px] u-text-2 leading-relaxed"><?= lang('rental_help_overpay_body') ?></p>
            </div>
            <!-- Close Button -->
            <button onclick="closeRentalHelpModal()" class="w-full py-3 bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold rounded-xl transition-all active:scale-95"><?= lang('common_got_it') ?></button>
        </div>
    </div>
</div>

<!-- ═══ HELP MODAL JS ═══ -->
<script>
function openRentalHelpModal() {
    var m = document.getElementById('rentalHelpModal');
    var s = document.getElementById('rentalHelpSheet');
    m.classList.remove('hidden');
    setTimeout(function() { s.classList.remove('translate-y-full'); s.classList.add('translate-y-0'); }, 10);
}
function closeRentalHelpModal() {
    var m = document.getElementById('rentalHelpModal');
    var s = document.getElementById('rentalHelpSheet');
    s.classList.remove('translate-y-0');
    s.classList.add('translate-y-full');
    setTimeout(function() { m.classList.add('hidden'); }, 300);
}
/* C2 (plan/46): guard anti double-submit PER-FORM — tidak menyentuh default
   action submit pertama (POST native + redirect + flashdata tetap utuh);
   hanya klik kedua+ yang diblokir via data-submitting="1". Tombol langsung
   diberi umpan balik visual "Memproses..." agar pengguna tahu klik diterima.
   Otoritas anti double-payout tetap server (row lock SELECT ... FOR UPDATE
   di Rental_model::claim_roi). */
document.querySelectorAll('form.claim-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        if (form.getAttribute('data-submitting') === '1') {
            e.preventDefault();
            return;
        }
        form.setAttribute('data-submitting', '1');
        var btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.classList.add('opacity-60', 'cursor-not-allowed');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> ' + ((window.SYNAPSE_I18N || {})['js_processing'] || 'Memproses...');
        }
    });
});
</script>
