    <!-- Flash Messages -->
    <?php if ($this->session->flashdata('success')): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm font-medium px-4 py-3 rounded-xl mb-6 flex items-center gap-2">
            <i class="fas fa-check-circle"></i>
            <?= $this->session->flashdata('success') ?>
        </div>
    <?php endif; ?>
    <?php if ($this->session->flashdata('error')): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm font-medium px-4 py-3 rounded-xl mb-6 flex items-center gap-2">
            <i class="fas fa-exclamation-triangle"></i>
            <?= $this->session->flashdata('error') ?>
        </div>
    <?php endif; ?>

    <!-- Phase 9A: Treasury Health Dashboard -->
    <?php $is_critical = $treasury['is_critical'] ?? false; ?>
    <div class="mb-6 p-5 rounded-xl border-2 <?= $is_critical ? 'bg-red-950/40 border-red-500 animate-pulse' : 'bg-slate-900 border-slate-700' ?> shadow-lg">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <i class="fas fa-shield-halved <?= $is_critical ? 'text-red-400' : 'text-emerald-400' ?>"></i>
                <h2 class="text-sm font-bold <?= $is_critical ? 'text-red-300' : 'text-emerald-300' ?> uppercase tracking-wider">Treasury Health</h2>
            </div>
            <button id="circuit-breaker-btn" onclick="toggleRegistration()"
                    class="px-4 py-1.5 rounded-lg text-xs font-bold transition-all duration-200 <?= $is_registration_open ? 'bg-red-600 hover:bg-red-700 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white' ?>">
                <i class="fas fa-power-off mr-1"></i>
                <span id="cb-label"><?= $is_registration_open ? 'TUTUP PENDAFTARAN' : 'BUKA PENDAFTARAN' ?></span>
            </button>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <!-- Cash In -->
            <div class="bg-slate-800/80 rounded-lg p-3">
                <div class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold mb-1">Cash In</div>
                <div class="text-lg font-bold text-emerald-400 font-mono">Rp <?= number_format($treasury['total_cash_in'], 0, ',', '.') ?></div>
            </div>
            <!-- User Balances -->
            <div class="bg-slate-800/80 rounded-lg p-3">
                <div class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold mb-1">User Balances</div>
                <div class="text-lg font-bold text-blue-400 font-mono">Rp <?= number_format($treasury['total_balances'], 0, ',', '.') ?></div>
            </div>
            <!-- Pending ROI -->
            <div class="bg-slate-800/80 rounded-lg p-3">
                <div class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold mb-1">Pending ROI</div>
                <div class="text-lg font-bold text-amber-400 font-mono">Rp <?= number_format($treasury['pending_roi'], 0, ',', '.') ?></div>
            </div>
            <!-- Status -->
            <div class="bg-slate-800/80 rounded-lg p-3">
                <div class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold mb-1">Status</div>
                <div class="flex items-center gap-2 mt-1">
                    <span class="w-2.5 h-2.5 rounded-full <?= $is_critical ? 'bg-red-500 animate-pulse' : 'bg-emerald-500' ?>"></span>
                    <span class="text-lg font-bold <?= $is_critical ? 'text-red-400' : 'text-emerald-400' ?>"><?= $is_critical ? 'CRITICAL' : 'SAFE' ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Phase 9A: Analytics Stat Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <!-- Active Users -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-700 shadow-lg">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-9 h-9 rounded-lg bg-indigo-500/15 flex items-center justify-center">
                    <i class="fas fa-users text-indigo-400 text-sm"></i>
                </div>
                <div>
                    <div class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold">Active Users</div>
                    <div id="stat-active-users" class="text-xl font-bold text-indigo-400 font-mono"><?= number_format($analytics_stats['active_users']) ?></div>
                </div>
            </div>
            <div class="text-[10px] text-slate-500">Users with active rentals</div>
        </div>
        <!-- Rental Volume -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-700 shadow-lg">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-9 h-9 rounded-lg bg-emerald-500/15 flex items-center justify-center">
                    <i class="fas fa-coins text-emerald-400 text-sm"></i>
                </div>
                <div>
                    <div class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold">Rental Volume</div>
                    <div id="stat-rental-volume" class="text-xl font-bold text-emerald-400 font-mono">Rp <?= number_format($analytics_stats['rental_volume'], 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="text-[10px] text-slate-500">Total purchase price across all rentals</div>
        </div>
        <!-- Withdrawal Volume -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-700 shadow-lg">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-9 h-9 rounded-lg bg-amber-500/15 flex items-center justify-center">
                    <i class="fas fa-arrow-up-from-bracket text-amber-400 text-sm"></i>
                </div>
                <div>
                    <div class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold">Withdrawal Volume</div>
                    <div id="stat-wd-volume" class="text-xl font-bold text-amber-400 font-mono">Rp <?= number_format($analytics_stats['withdrawal_volume'], 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="text-[10px] text-slate-500">Total successful withdrawal amount</div>
        </div>
    </div>

    <!-- Phase 9A: Revenue Chart -->
    <div class="bg-slate-900 rounded-xl p-5 border border-slate-700 shadow-lg mb-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <i class="fas fa-chart-line text-emerald-400"></i>
                <h2 class="text-sm font-bold text-emerald-300 uppercase tracking-wider">Revenue Over Time</h2>
            </div>
            <select id="chartPeriod" class="bg-slate-800 border border-slate-600 text-slate-300 text-xs font-medium rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none cursor-pointer">
                <option value="7">7 Hari Terakhir</option>
                <option value="30">30 Hari Terakhir</option>
            </select>
        </div>
        <div style="position:relative; height:280px;">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>

    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-xl font-bold text-slate-900">Dashboard</h1>
        <p class="text-sm text-slate-500 mt-1">Pending approval queue</p>
    </div>

    <!-- Grid Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- LEFT: Pending Deposits -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                    Pending Deposits
                </h2>
                <span class="text-xs font-bold text-slate-500 bg-slate-100 px-2.5 py-0.5 rounded-full">
                    <?= count($pending_deposits) ?>
                </span>
            </div>

            <?php if (empty($pending_deposits)): ?>
                <div class="px-5 py-12 text-center text-slate-400 text-sm">
                    <i class="fas fa-inbox text-2xl mb-2 block opacity-40"></i>
                    No pending deposits
                </div>
            <?php else: ?>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($pending_deposits as $dep): ?>
                    <div class="px-5 py-4 hover:bg-slate-50 transition-colors">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <div class="text-sm font-semibold text-slate-800"><?= $dep->invoice_number ?></div>
                                <div class="text-xs text-slate-400 mt-0.5"><?= $dep->phone ?></div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-bold text-slate-900 font-mono">Rp <?= number_format($dep->amount, 0, ',', '.') ?></div>
                                <div class="text-[11px] text-slate-400 mt-0.5"><?= date('d M Y H:i', strtotime($dep->created_at)) ?></div>
                            </div>
                        </div>
                        <form method="POST" action="<?= site_url('admin/approve_deposit/' . $dep->id) ?>"
                              onsubmit="return confirm('Approve deposit <?= $dep->invoice_number ?>?')">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                                <i class="fas fa-check mr-1"></i> Approve
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Pending Withdrawals -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                    <span class="w-2 h-2 bg-amber-500 rounded-full"></span>
                    Pending Withdrawals
                </h2>
                <span class="text-xs font-bold text-slate-500 bg-slate-100 px-2.5 py-0.5 rounded-full">
                    <?= count($pending_withdrawals) ?>
                </span>
            </div>

            <?php if (empty($pending_withdrawals)): ?>
                <div class="px-5 py-12 text-center text-slate-400 text-sm">
                    <i class="fas fa-inbox text-2xl mb-2 block opacity-40"></i>
                    No pending withdrawals
                </div>
            <?php else: ?>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($pending_withdrawals as $wd): ?>
                    <div class="px-5 py-4 hover:bg-slate-50 transition-colors">
                        <div class="flex items-start justify-between mb-2">
                            <div>
                                <div class="text-sm font-semibold text-slate-800"><?= $wd->wd_number ?></div>
                                <div class="text-xs text-slate-400 mt-0.5"><?= $wd->phone ?></div>
                                <div class="text-xs text-slate-400 mt-0.5">
                                    <?= $wd->bank_name ?> · <?= $wd->account_number ?> · <?= $wd->account_name ?>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-bold text-slate-900 font-mono">Rp <?= number_format($wd->amount, 0, ',', '.') ?></div>
                                <div class="text-[11px] text-slate-400 mt-0.5"><?= date('d M Y H:i', strtotime($wd->created_at)) ?></div>
                            </div>
                        </div>
                        <div class="flex gap-2 mt-3">
                            <form method="POST" action="<?= site_url('admin/approve_withdrawal/' . $wd->id) ?>" class="flex-1"
                                  onsubmit="return confirm('Approve withdrawal <?= $wd->wd_number ?>?')">
                                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
                                    <i class="fas fa-check mr-1"></i> Approve
                                </button>
                            </form>
                            <form method="POST" action="<?= site_url('admin/decline_withdrawal/' . $wd->id) ?>" class="flex-1"
                                  onsubmit="return confirm('Decline withdrawal <?= $wd->wd_number ?>? Dana akan dikembalikan.')">
                                <button type="submit" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium px-3 py-2 rounded-lg transition-colors">
                                    <i class="fas fa-times mr-1"></i> Decline
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        function toggleRegistration() {
            const btn = document.getElementById('circuit-breaker-btn');
            const label = document.getElementById('cb-label');
            const originalText = label.textContent;
            const originalClass = btn.className;

            btn.disabled = true;
            label.textContent = 'Memproses...';
            btn.classList.add('opacity-75', 'cursor-not-allowed');

            fetch('<?= base_url('admin/toggle_registration') ?>', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const isOpen = data.is_open;
                    label.textContent = isOpen ? 'TUTUP PENDAFTARAN' : 'BUKA PENDAFTARAN';
                    if (isOpen) {
                        btn.classList.remove('bg-emerald-600', 'hover:bg-emerald-700');
                        btn.classList.add('bg-red-600', 'hover:bg-red-700');
                    } else {
                        btn.classList.remove('bg-red-600', 'hover:bg-red-700');
                        btn.classList.add('bg-emerald-600', 'hover:bg-emerald-700');
                    }
                } else {
                    label.textContent = originalText;
                    alert('Gagal: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(() => {
                label.textContent = originalText;
                alert('Terjadi kesalahan jaringan.');
            })
            .finally(() => {
                btn.disabled = false;
            });
        }
    </script>
