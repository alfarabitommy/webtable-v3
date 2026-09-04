    <!-- Flash Messages -->
    <?php if ($this->session->flashdata('success')): ?>
        <div class="t-flash-success text-sm font-medium px-4 py-3 rounded-xl mb-6 flex items-center gap-2">
            <i class="fas fa-check-circle"></i>
            <?= $this->session->flashdata('success') ?>
        </div>
    <?php endif; ?>
    <?php if ($this->session->flashdata('error')): ?>
        <div class="t-flash-error text-sm font-medium px-4 py-3 rounded-xl mb-6 flex items-center gap-2">
            <i class="fas fa-exclamation-triangle"></i>
            <?= $this->session->flashdata('error') ?>
        </div>
    <?php endif; ?>
    <?php if ($this->session->flashdata('info')): ?>
        <div class="t-flash-info text-sm font-medium px-4 py-3 rounded-xl mb-6 flex items-center gap-2">
            <i class="fas fa-info-circle"></i>
            <?= $this->session->flashdata('info') ?>
        </div>
    <?php endif; ?>

    <!-- Phase 9A: Treasury Health Dashboard -->
    <?php $is_critical = $treasury['is_critical'] ?? false; ?>
    <div class="mb-6 p-5 rounded-xl border-2 <?= $is_critical ? 'bg-red-50 dark:bg-red-950/40 border-red-500 animate-pulse' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700' ?> shadow-lg">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <i class="fas fa-shield-halved <?= $is_critical ? 'text-red-500 dark:text-red-400' : 'text-emerald-500 dark:text-emerald-400' ?>"></i>
                <h2 class="text-sm font-bold <?= $is_critical ? 'text-red-600 dark:text-red-300' : 'text-emerald-600 dark:text-emerald-300' ?> uppercase tracking-wider">Treasury Health</h2>
            </div>
            <button id="circuit-breaker-btn" onclick="toggleRegistration()"
                    class="px-4 py-1.5 rounded-lg text-xs font-bold transition-all duration-200 <?= $is_registration_open ? 'bg-red-600 hover:bg-red-700 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white' ?>">
                <i class="fas fa-power-off mr-1"></i>
                <span id="cb-label"><?= $is_registration_open ? 'TUTUP PENDAFTARAN' : 'BUKA PENDAFTARAN' ?></span>
            </button>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
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
        <!-- M3 (plan/60): sweep manual sewa kedaluwarsa (opsional — BUKAN cron;
             pelengkap lazy-evaluation per-request MY_Controller). -->
        <div class="mt-4 pt-3 border-t border-slate-700/60 flex flex-wrap items-center justify-between gap-2">
            <div class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold">Maintenance</div>
            <?= form_open('admin/expire_expired_rentals', ['onsubmit' => "return confirm('Tutup semua kontrak sewa yang sudah kedaluwarsa?')"]) ?>
                <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-amber-600 hover:bg-amber-700 text-white transition-all duration-200">
                    <i class="fas fa-hourglass-end mr-1"></i>Tutup Sewa Kedaluwarsa
                </button>
            <?= form_close() ?>
        </div>
    </div>

    <!-- Phase 9A: Analytics Stat Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <!-- Active Users -->
        <div class="t-card p-4 shadow-lg">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-9 h-9 rounded-lg bg-indigo-500/15 flex items-center justify-center">
                    <i class="fas fa-users text-indigo-600 dark:text-indigo-400 text-sm"></i>
                </div>
                <div>
                    <div class="text-[10px] text-[var(--t-muted)] uppercase tracking-wider font-semibold">Active Users</div>
                    <div id="stat-active-users" class="text-xl font-bold text-indigo-600 dark:text-indigo-400 font-mono"><?= number_format($analytics_stats['active_users']) ?></div>
                </div>
            </div>
            <div class="text-[10px] t-text-2">Users with active rentals</div>
        </div>
        <!-- Rental Volume -->
        <div class="t-card p-4 shadow-lg">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-9 h-9 rounded-lg bg-emerald-500/15 flex items-center justify-center">
                    <i class="fas fa-coins text-emerald-600 dark:text-emerald-400 text-sm"></i>
                </div>
                <div>
                    <div class="text-[10px] text-[var(--t-muted)] uppercase tracking-wider font-semibold">Rental Volume</div>
                    <div id="stat-rental-volume" class="text-xl font-bold text-emerald-600 dark:text-emerald-400 font-mono">Rp <?= number_format($analytics_stats['rental_volume'], 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="text-[10px] t-text-2">Total purchase price across all rentals</div>
        </div>
        <!-- Withdrawal Volume -->
        <div class="t-card p-4 shadow-lg">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-9 h-9 rounded-lg bg-amber-500/15 flex items-center justify-center">
                    <i class="fas fa-arrow-up-from-bracket text-amber-600 dark:text-amber-400 text-sm"></i>
                </div>
                <div>
                    <div class="text-[10px] text-[var(--t-muted)] uppercase tracking-wider font-semibold">Withdrawal Volume</div>
                    <div id="stat-wd-volume" class="text-xl font-bold text-amber-600 dark:text-amber-400 font-mono">Rp <?= number_format($analytics_stats['withdrawal_volume'], 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="text-[10px] t-text-2">Total successful withdrawal amount</div>
        </div>
    </div>

    <!-- Phase 9A: Revenue Chart -->
    <div class="t-card p-5 shadow-lg mb-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <i class="fas fa-chart-line text-emerald-600 dark:text-emerald-400"></i>
                <h2 class="text-sm font-bold text-emerald-600 dark:text-emerald-300 uppercase tracking-wider">Revenue Over Time</h2>
            </div>
            <select id="chartPeriod" class="t-select bg-[var(--t-surface-2)] border border-[var(--t-border)] text-xs font-medium rounded-lg px-3 py-1.5 w-auto focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 cursor-pointer">
                <option value="7">7 Hari Terakhir</option>
                <option value="30">30 Hari Terakhir</option>
            </select>
        </div>
        <div style="position:relative; height:280px;">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>

    <!-- Grid Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- LEFT: Pending Deposits -->
        <div class="t-card shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-[var(--t-border)] flex items-center justify-between">
                <h2 class="text-sm font-semibold text-[var(--t-text)] flex items-center gap-2">
                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                    Pending Deposits
                </h2>
                <span class="t-badge t-badge-muted">
                    <?= count($pending_deposits) ?>
                </span>
            </div>

            <?php if (empty($pending_deposits)): ?>
                <div class="px-5 py-12 text-center text-[var(--t-muted)] text-sm">
                    <i class="fas fa-inbox text-2xl mb-2 block opacity-40"></i>
                    No pending deposits
                </div>
            <?php else: ?>
                <div class="divide-y divide-[var(--t-border)]">
                    <?php foreach ($pending_deposits as $dep): ?>
                    <div class="px-5 py-4 t-row-hover transition-colors">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <div class="text-sm font-semibold text-[var(--t-text)]"><?= $dep->invoice_number ?></div>
                                <div class="text-xs text-[var(--t-muted)] mt-0.5"><?= $dep->phone ?></div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-bold text-[var(--t-text)] font-mono">Rp <?= number_format($dep->amount, 0, ',', '.') ?></div>
                                <div class="text-[11px] text-[var(--t-muted)] mt-0.5"><?= date('d M Y H:i', strtotime($dep->created_at)) ?></div>
                            </div>
                        </div>
                        <?= form_open('admin/approve_deposit/' . $dep->id, ['data-guard-submit' => '1', 'onsubmit' => "return confirm('Approve deposit {$dep->invoice_number}?')"]) ?>
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                                <i class="fas fa-check mr-1"></i> Approve
                            </button>
                        <?= form_close() ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Pending Withdrawals -->
        <div class="t-card shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-[var(--t-border)] flex items-center justify-between">
                <h2 class="text-sm font-semibold text-[var(--t-text)] flex items-center gap-2">
                    <span class="w-2 h-2 bg-amber-500 rounded-full"></span>
                    Pending Withdrawals
                </h2>
                <span class="t-badge t-badge-muted">
                    <?= count($pending_withdrawals) ?>
                </span>
            </div>

            <?php if (empty($pending_withdrawals)): ?>
                <div class="px-5 py-12 text-center text-[var(--t-muted)] text-sm">
                    <i class="fas fa-inbox text-2xl mb-2 block opacity-40"></i>
                    No pending withdrawals
                </div>
            <?php else: ?>
                <div class="divide-y divide-[var(--t-border)]">
                    <?php foreach ($pending_withdrawals as $wd): ?>
                    <div class="px-5 py-4 t-row-hover transition-colors">
                        <div class="flex items-start justify-between mb-2">
                            <div>
                                <div class="text-sm font-semibold text-[var(--t-text)]"><?= $wd->wd_number ?></div>
                                <div class="text-xs text-[var(--t-muted)] mt-0.5"><?= $wd->phone ?></div>
                                <div class="text-xs text-[var(--t-muted)] mt-0.5">
                                    <?= $wd->bank_name ?> · <?= $wd->account_number ?> · <?= $wd->account_name ?>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-bold text-[var(--t-text)] font-mono">Rp <?= number_format($wd->amount, 0, ',', '.') ?></div>
                                <div class="text-[11px] text-[var(--t-muted)] mt-0.5"><?= date('d M Y H:i', strtotime($wd->created_at)) ?></div>
                            </div>
                        </div>
                        <div class="flex gap-2 items-start mt-3">
                            <?= form_open('admin/approve_withdrawal/' . $wd->id, ['class' => 'flex-1', 'data-guard-submit' => '1', 'onsubmit' => "return confirm('Approve withdrawal {$wd->wd_number}?')"]) ?>
                                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
                                    <i class="fas fa-check mr-1"></i> Approve
                                </button>
                            <?= form_close() ?>
                            <?= form_open('admin/decline_withdrawal/' . $wd->id, ['class' => 'flex-1 flex flex-col gap-1.5', 'data-guard-submit' => '1', 'onsubmit' => "return confirm('Decline withdrawal {$wd->wd_number}? Dana akan dikembalikan.')"]) ?>
                                <input type="text" name="reason" maxlength="255" placeholder="Alasan penolakan (opsional)"
                                       class="t-input w-full bg-[var(--t-surface-2)] border border-[var(--t-border)] rounded-lg px-2.5 py-1.5 text-xs text-[var(--t-text)] placeholder-[var(--t-muted)] focus:outline-none focus:ring-2 focus:ring-rose-500 focus:border-rose-500">
                                <button type="submit" class="w-full t-btn-ghost px-3 py-2 rounded-lg text-sm transition-colors">
                                    <i class="fas fa-times mr-1"></i> Decline
                                </button>
                            <?= form_close() ?>
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

            csrfFetch('<?= base_url('admin/toggle_registration') ?>', {
                method: 'POST'
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
