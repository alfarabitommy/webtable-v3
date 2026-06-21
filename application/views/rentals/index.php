<!-- ═══ Infrastruktur Aktif — Dark Server Blade Aesthetic ═══ -->
<style>
    .blade-bg {
        background: linear-gradient(180deg, #0b1120 0%, #111827 40%, #0f172a 100%);
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
    .btn-claim {
        background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
        box-shadow: 0 4px 20px rgba(37, 99, 235, 0.35), 0 0 40px rgba(79, 70, 229, 0.1);
        transition: all 0.2s;
    }
    .btn-claim:active {
        transform: scale(0.96);
        box-shadow: 0 2px 10px rgba(37, 99, 235, 0.25);
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
</style>

<div class="blade-bg scanline p-4 pb-28 text-white">

    <!-- ═══ Page Header ═══ -->
    <div class="mb-6">
        <div class="flex items-center gap-2 mb-1">
            <div class="w-1.5 h-1.5 rounded-full bg-blue-600 pulse-dot"></div>
            <span class="text-[10px] font-bold text-blue-600 uppercase tracking-[0.2em]">System Monitor</span>
        </div>
        <h2 class="flex items-center gap-2 text-xl font-extrabold tracking-tight text-transparent bg-clip-text bg-gradient-to-r from-slate-900 to-slate-700">
            <i class="fas fa-microchip text-blue-600"></i> Infrastruktur Aktif
        </h2>
        <p class="text-xs text-slate-500 mt-1">Status node dan penghasilan harian Anda.</p>
    </div>

    <!-- ═══ Flash Messages ═══ -->
    <?php if ($this->session->flashdata('error')): ?>
    <div class="bg-rose-500/10 border border-rose-500/30 text-rose-400 px-4 py-3 rounded-xl mb-4 text-sm font-semibold flex items-center gap-2 shadow-sm">
        <i class="fas fa-exclamation-circle"></i> <?= $this->session->flashdata('error') ?>
    </div>
    <?php endif; ?>
    <?php if ($this->session->flashdata('success')): ?>
    <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 px-4 py-3 rounded-xl mb-4 text-sm font-semibold flex items-center gap-2 shadow-sm">
        <i class="fas fa-check-circle"></i> <?= $this->session->flashdata('success') ?>
    </div>
    <?php endif; ?>

    <!-- ═══ Empty State ═══ -->
    <?php if (empty($rentals)): ?>
    <div class="flex flex-col items-center justify-center py-20 text-center">
        <div class="w-20 h-20 rounded-full bg-slate-800/80 border border-slate-700 flex items-center justify-center mb-5">
            <i class="fas fa-server text-3xl text-slate-600"></i>
        </div>
        <h3 class="text-lg font-bold text-slate-400 mb-2">Belum Ada Node Aktif</h3>
        <p class="text-xs text-slate-600 max-w-[240px] leading-relaxed">Beli infrastruktur GPU di marketplace untuk mulai menghasilkan.</p>
        <a href="<?= site_url('marketplace') ?>" class="mt-6 px-6 py-3 bg-blue-600 hover:bg-blue-500 text-white text-sm font-bold rounded-xl transition-all active:scale-95 flex items-center gap-2">
            <i class="fas fa-store"></i> Buka Marketplace
        </a>
    </div>

    <?php else: ?>

    <!-- ═══ Active Rental Cards ═══ -->
    <?php foreach ($rentals as $rental): ?>
        <?php
        $is_claimed = false;
        if (!empty($rental->last_claimed_at)) {
            $last_claim_date = date('Y-m-d', strtotime($rental->last_claimed_at));
            if (date('Y-m-d') === $last_claim_date) {
                $is_claimed = true;
            }
        }

        $expired = strtotime($rental->expired_at);
        $days_left = max(0, ceil(($expired - time()) / 86400));
        ?>

    <div class="glow-border bg-slate-900 border border-slate-700/60 rounded-2xl p-5 mb-4 shadow-xl relative overflow-hidden card-hover">
        <!-- Subtle background pattern -->
        <div class="absolute inset-0 opacity-[0.03]" style="background-image: url('data:image/svg+xml,<svg width=&quot;20&quot; height=&quot;20&quot; xmlns=&quot;http://www.w3.org/2000/svg&quot;><circle cx=&quot;1&quot; cy=&quot;1&quot; r=&quot;0.5&quot; fill=&quot;white&quot;/></svg>'); background-size: 20px 20px;"></div>

        <!-- Header Row -->
        <div class="relative flex items-start justify-between mb-4">
            <div class="flex-1 min-w-0">
                <h3 class="text-base font-extrabold text-white truncate"><?= htmlspecialchars($rental->product_name ?? 'Node #' . $rental->product_id) ?></h3>
                <p class="text-[10px] text-slate-500 mt-0.5 font-mono">ID: #<?= $rental->id ?> · <?= date('d M Y', strtotime($rental->created_at)) ?></p>
            </div>
            <div class="flex items-center gap-1.5 bg-emerald-500/10 border border-emerald-500/20 rounded-full px-2.5 py-1 ml-3 flex-shrink-0">
                <div class="w-2 h-2 rounded-full bg-emerald-500 pulse-dot"></div>
                <span class="text-[10px] text-emerald-400 font-bold uppercase tracking-wider">Online</span>
            </div>
        </div>

        <!-- Data Grid -->
        <div class="relative data-grid mb-4">
            <div class="data-cell">
                <span>Harga Sewa</span>
                <p class="text-sm font-extrabold text-white mt-0.5">Rp <?= number_format($rental->purchase_price, 0, ',', '.') ?></p>
            </div>
            <div class="data-cell">
                <span>ROI Harian</span>
                <p class="text-sm font-extrabold text-emerald-400 mt-0.5">Rp <?= number_format($rental->daily_roi, 0, ',', '.') ?></p>
            </div>
        </div>

        <!-- Expiry Info -->
        <div class="relative flex items-center justify-between text-[11px] text-slate-500 mb-1">
            <span><i class="fas fa-clock mr-1"></i> Berakhir: <?= date('d M Y', $expired) ?></span>
            <span class="font-mono <?= $days_left <= 3 ? 'text-rose-400' : 'text-slate-500' ?>"><?= $days_left ?> hari lagi</span>
        </div>

        <!-- Claim Button -->
        <?php if ($is_claimed): ?>
        <button disabled class="w-full h-12 mt-4 bg-slate-800 text-slate-500 font-bold text-sm rounded-xl cursor-not-allowed border border-slate-700/50 flex justify-center items-center gap-2">
            <i class="fas fa-check-circle text-slate-600"></i> Penghasilan Hari Ini Telah Diklaim
        </button>
        <?php else: ?>
        <?php echo form_open('rentals/claim/' . $rental->id, ['class' => 'w-full m-0 p-0']); ?>
            <button type="submit" class="w-full h-12 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white font-bold rounded-xl shadow-[0_0_15px_rgba(37,99,235,0.4)] transform transition active:scale-95 flex justify-center items-center gap-2">
                <i class="fas fa-bolt text-yellow-400"></i> Klaim Rp <?= number_format($rental->daily_roi, 0, ',', '.') ?>
            </button>
        <?php echo form_close(); ?>
        <?php endif; ?>

    </div>
    <?php endforeach; ?>

    <!-- ═══ Summary Bar ═══ -->
    <?php
        $total_roi = 0;
        foreach ($rentals as $rental) { $total_roi += $rental->daily_roi; }
    ?>
    <div class="relative bg-gradient-to-br from-slate-800 via-slate-900 to-indigo-950 border border-slate-700/50 rounded-xl p-4 mt-2 shadow-xl overflow-hidden shadow-[inset_0_1px_0_rgba(255,255,255,0.05)]">
        <div class="absolute top-0 left-0 w-full h-[1px] bg-gradient-to-r from-blue-500 via-indigo-500 to-purple-500"></div>
        <div class="flex items-center justify-between relative z-10">
            <div class="flex-1">
                <span class="text-[10px] text-slate-500 uppercase tracking-widest font-bold flex items-center gap-1.5">
                    <i class="fas fa-chart-line text-blue-400"></i> Estimasi Penghasilan/Hari
                </span>
                <p class="text-2xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-indigo-400">Rp <?= number_format($total_roi, 0, ',', '.') ?></p>
            </div>
            <div class="border-l border-slate-700/50 pl-4 text-right min-w-[100px]">
                <span class="text-[10px] text-slate-500 uppercase tracking-widest font-bold flex items-center justify-end gap-1.5">
                    <i class="fas fa-server text-blue-400"></i> Total Node
                </span>
                <p class="text-2xl font-extrabold text-white"><?= count($rentals) ?></p>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>
