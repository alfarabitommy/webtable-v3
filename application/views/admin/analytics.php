<!-- Phase 9B: Analytics — Bloomberg Terminal Aesthetic -->
<?php if ($this->session->flashdata('success')): ?>
    <div class="mb-4 px-4 py-3 rounded-lg t-flash-success text-sm flex items-center gap-2">
        <i class="fas fa-check-circle"></i>
        <?= $this->session->flashdata('success') ?>
    </div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
    <div class="mb-4 px-4 py-3 rounded-lg t-flash-error text-sm flex items-center gap-2">
        <i class="fas fa-exclamation-circle"></i>
        <?= $this->session->flashdata('error') ?>
    </div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- HEADER                                                            -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-[var(--t-text)] flex items-center gap-2">
            <i class="fas fa-chart-line text-indigo-600 dark:text-indigo-400"></i> ANALYTICS — COMMAND CENTER
        </h2>
        <p class="text-xs text-[var(--t-muted)] mt-1 font-mono">Global Agency Metrics &middot; VIP Leaderboard &middot; Financial X-Ray</p>
    </div>
    <div class="text-[10px] text-[var(--t-muted)] font-mono">
        LAST SYNC: <?= date('d M Y H:i:s') ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- GLOBAL METRICS (4 stat cards)                                    -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- Total Users -->
    <div class="t-card p-4">
        <p class="text-[10px] uppercase tracking-wider text-[var(--t-muted)] font-semibold mb-2">Total Users</p>
        <p class="text-2xl font-bold font-mono text-[var(--t-text)]"><?= number_format($global['total_users']) ?></p>
    </div>
    <!-- Active Agents -->
    <div class="t-card p-4">
        <p class="text-[10px] uppercase tracking-wider text-[var(--t-muted)] font-semibold mb-2">Active Agents</p>
        <p class="text-2xl font-bold font-mono text-emerald-600 dark:text-emerald-400"><?= number_format($global['total_agents']) ?></p>
    </div>
    <!-- Active Rentals -->
    <div class="t-card p-4">
        <p class="text-[10px] uppercase tracking-wider text-[var(--t-muted)] font-semibold mb-2">Active Rentals</p>
        <p class="text-2xl font-bold font-mono text-indigo-600 dark:text-indigo-400"><?= number_format($global['active_rentals']) ?></p>
    </div>
    <!-- Total Commissions -->
    <div class="t-card p-4">
        <p class="text-[10px] uppercase tracking-wider text-[var(--t-muted)] font-semibold mb-2">Total Commissions Paid</p>
        <p class="text-2xl font-bold font-mono text-amber-600 dark:text-amber-400">Rp <?= number_format($global['total_commissions'], 0, ',', '.') ?></p>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- VIP LEADERBOARD TABLE                                             -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="t-card overflow-hidden">
    <!-- Table Header -->
    <div class="px-5 py-4 border-b border-[var(--t-border)] flex items-center justify-between">
        <div>
            <h3 class="text-sm font-bold text-[var(--t-text)] flex items-center gap-2">
                <i class="fas fa-trophy text-amber-500 dark:text-amber-400 text-xs"></i> TOP AFFILIATES — VIP LEADERBOARD
            </h3>
            <p class="text-[10px] text-[var(--t-muted)] mt-0.5 font-mono">Ranked by total active downlines (L1 + L2)</p>
        </div>
        <span class="px-2.5 py-1 rounded-lg bg-[var(--t-surface-2)] text-[10px] font-mono text-[var(--t-muted)]">
            <?= count($leaders) ?> entries
        </span>
    </div>

    <?php if (empty($leaders)): ?>
        <div class="px-5 py-12 text-center">
            <i class="fas fa-inbox text-[var(--t-muted)] text-2xl mb-3"></i>
            <p class="text-sm text-[var(--t-muted)]">No affiliate data available yet.</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-[var(--t-border)] bg-[var(--t-surface-2)]">
                        <th class="text-left px-5 py-3 t-th w-12">#</th>
                        <th class="text-left px-5 py-3 t-th">Phone</th>
                        <th class="text-left px-5 py-3 t-th hidden sm:table-cell">Invite Code</th>
                        <th class="text-right px-5 py-3 t-th">Active Agents</th>
                        <th class="text-right px-5 py-3 t-th">Total Sales</th>
                        <th class="text-center px-5 py-3 t-th">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--t-border)]">
                    <?php foreach ($leaders as $i => $l): ?>
                        <tr class="t-row-hover transition-colors">
                            <!-- Rank -->
                            <td class="px-5 py-3 font-mono text-xs">
                                <?php if ($i < 3): ?>
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full
                                        <?php echo $i === 0 ? 'bg-amber-500/20 text-amber-600 dark:text-amber-400' : ($i === 1 ? 'bg-slate-400/20 text-slate-500 dark:text-slate-300' : 'bg-orange-500/20 text-orange-600 dark:text-orange-400'); ?>
                                        font-bold text-[10px]">
                                        <?= $i + 1 ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-[var(--t-muted)]"><?= $i + 1 ?></span>
                                <?php endif; ?>
                            </td>
                            <!-- Phone -->
                            <td class="px-5 py-3">
                                <div>
                                    <p class="text-xs font-medium text-[var(--t-text)] font-mono"><?= htmlspecialchars($l->phone) ?></p>
                                    <?php if ($l->username): ?>
                                        <p class="text-[10px] text-[var(--t-muted)] mt-0.5"><?= htmlspecialchars($l->username) ?></p>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <!-- Invite Code -->
                            <td class="px-5 py-3 hidden sm:table-cell">
                                <span class="text-[10px] font-mono font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-500/10 px-2 py-0.5 rounded">
                                    <?= htmlspecialchars($l->invite_code) ?>
                                </span>
                            </td>
                            <!-- Active Agents (downline_count) -->
                            <td class="px-5 py-3 text-right">
                                <span class="font-mono text-xs font-bold text-emerald-600 dark:text-emerald-400">
                                    <?= number_format($l->downline_count) ?>
                                </span>
                            </td>
                            <!-- Total Sales -->
                            <td class="px-5 py-3 text-right">
                                <span class="font-mono text-xs t-text-2">
                                    Rp <?= number_format($l->total_sales, 0, ',', '.') ?>
                                </span>
                            </td>
                            <!-- Action: X-Ray -->
                            <td class="px-5 py-3 text-center">
                                <button onclick="openXray(<?= (int) $l->id ?>)"
                                        class="px-3 py-1.5 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 text-[10px] font-semibold
                                               hover:bg-indigo-500/20 transition-colors flex items-center gap-1.5 mx-auto">
                                    <i class="fas fa-crosshairs text-[9px]"></i> X-Ray
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- DATA EXPORT CARD                                                   -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="t-card overflow-hidden mb-6">
    <div class="px-5 py-4 border-b border-[var(--t-border)] flex items-center justify-between">
        <div>
            <h3 class="text-sm font-bold text-[var(--t-text)] flex items-center gap-2">
                <i class="fas fa-download text-green-600 dark:text-green-400 text-xs"></i> DATA EXPORT
            </h3>
            <p class="text-[10px] text-[var(--t-muted)] mt-0.5 font-mono">Download raw CSV reports for external analysis</p>
        </div>
        <span class="px-2.5 py-1 rounded-lg bg-[var(--t-surface-2)] text-[10px] font-mono text-[var(--t-muted)]">
            CSV
        </span>
    </div>
    <div class="p-5 grid grid-cols-1 sm:grid-cols-3 gap-3">
        <a href="<?= site_url('admin/export_csv/ledger') ?>"
           class="flex items-center justify-center gap-2.5 t-btn-ghost border border-[var(--t-border)]
                  rounded-xl px-4 py-3.5 transition-all duration-200 text-xs font-semibold group">
            <i class="fas fa-file-csv text-green-600 dark:text-green-400 group-hover:scale-110 transition-transform"></i>
            Wallet Ledger
        </a>
        <a href="<?= site_url('admin/export_csv/rentals') ?>"
           class="flex items-center justify-center gap-2.5 t-btn-ghost border border-[var(--t-border)]
                  rounded-xl px-4 py-3.5 transition-all duration-200 text-xs font-semibold group">
            <i class="fas fa-file-csv text-green-600 dark:text-green-400 group-hover:scale-110 transition-transform"></i>
            Active Rentals
        </a>
        <a href="<?= site_url('admin/export_csv/withdrawals') ?>"
           class="flex items-center justify-center gap-2.5 t-btn-ghost border border-[var(--t-border)]
                  rounded-xl px-4 py-3.5 transition-all duration-200 text-xs font-semibold group">
            <i class="fas fa-file-csv text-green-600 dark:text-green-400 group-hover:scale-110 transition-transform"></i>
            Withdrawals
        </a>
    </div>
</div>

<!-- FINANCIAL X-RAY MODAL (z-[60])                                   -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div id="xray-overlay"
     class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-[60] flex items-end sm:items-center justify-center"
     onclick="if(event.target===this)closeXray()">

    <div id="xray-modal"
         class="t-modal rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg
                max-h-[85vh] overflow-y-auto transform translate-y-full sm:translate-y-0
                transition-transform duration-300 ease-out shadow-2xl">

        <!-- Modal Header -->
        <div class="sticky top-0 z-10 bg-[var(--t-surface)] border-b border-[var(--t-border)] px-5 py-4 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-[var(--t-text)] flex items-center gap-2">
                    <i class="fas fa-crosshairs text-indigo-600 dark:text-indigo-400"></i> FINANCIAL X-RAY
                </h3>
                <p id="xray-phone" class="text-[10px] text-[var(--t-muted)] font-mono mt-0.5"></p>
            </div>
            <button onclick="closeXray()" class="w-8 h-8 rounded-lg bg-[var(--t-surface-3)] hover:bg-[var(--t-hover)] flex items-center justify-center transition-colors">
                <i class="fas fa-times text-[var(--t-muted)] text-xs"></i>
            </button>
        </div>

        <!-- Loading State -->
        <div id="xray-loading" class="px-5 py-12 text-center">
            <div class="inline-flex items-center gap-2 text-[var(--t-muted)] text-sm">
                <i class="fas fa-circle-notch fa-spin text-indigo-600 dark:text-indigo-400"></i> Scanning financial profile...
            </div>
        </div>

        <!-- Content (hidden until loaded) -->
        <div id="xray-content" class="hidden px-5 py-5 space-y-4">

            <!-- Profitability Badge -->
            <div id="xray-badge" class="rounded-xl p-4 text-center border">
                <p id="xray-badge-label" class="text-[10px] uppercase tracking-wider font-bold mb-1"></p>
                <p id="xray-badge-value" class="text-xl font-bold font-mono"></p>
            </div>

            <!-- Financial Stats Grid -->
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-[var(--t-surface-2)] rounded-xl p-3 border border-[var(--t-border)]">
                    <p class="text-[10px] uppercase tracking-wider text-[var(--t-muted)] font-semibold mb-1">Total Deposits</p>
                    <p id="xray-deposits" class="text-sm font-bold font-mono text-emerald-600 dark:text-emerald-400"></p>
                </div>
                <div class="bg-[var(--t-surface-2)] rounded-xl p-3 border border-[var(--t-border)]">
                    <p class="text-[10px] uppercase tracking-wider text-[var(--t-muted)] font-semibold mb-1">Total Withdrawals</p>
                    <p id="xray-withdrawals" class="text-sm font-bold font-mono text-red-600 dark:text-red-400"></p>
                </div>
                <div class="bg-[var(--t-surface-2)] rounded-xl p-3 border border-[var(--t-border)]">
                    <p class="text-[10px] uppercase tracking-wider text-[var(--t-muted)] font-semibold mb-1">Active GPUs</p>
                    <p id="xray-rentals" class="text-sm font-bold font-mono text-indigo-600 dark:text-indigo-400"></p>
                </div>
                <div class="bg-[var(--t-surface-2)] rounded-xl p-3 border border-[var(--t-border)]">
                    <p class="text-[10px] uppercase tracking-wider text-[var(--t-muted)] font-semibold mb-1">Total Invested</p>
                    <p id="xray-invested" class="text-sm font-bold font-mono text-amber-600 dark:text-amber-400"></p>
                </div>
                <div class="bg-[var(--t-surface-2)] rounded-xl p-3 border border-[var(--t-border)]">
                    <p class="text-[10px] uppercase tracking-wider text-[var(--t-muted)] font-semibold mb-1">Net Balance</p>
                    <p id="xray-balance" class="text-sm font-bold font-mono"></p>
                </div>
                <div class="bg-[var(--t-surface-2)] rounded-xl p-3 border border-[var(--t-border)]">
                    <p class="text-[10px] uppercase tracking-wider text-[var(--t-muted)] font-semibold mb-1">Direct Downlines</p>
                    <p id="xray-downlines" class="text-sm font-bold font-mono text-cyan-600 dark:text-cyan-400"></p>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="flex items-center gap-2 pt-2">
                <a id="xray-profile-link" href="#" class="flex-1 px-3 py-2 t-btn-ghost text-center text-[11px] font-medium transition-colors flex items-center justify-center gap-2">
                    <i class="fas fa-user text-[10px]"></i> Full Profile
                </a>
                <button onclick="closeXray()" class="flex-1 px-3 py-2 t-btn-ghost text-[11px] font-medium transition-colors">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- AJAX FINANCIAL X-RAY LOGIC                                       -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<script>
(function() {
    var overlay  = document.getElementById('xray-overlay');
    var modal    = document.getElementById('xray-modal');
    var loading  = document.getElementById('xray-loading');
    var content  = document.getElementById('xray-content');

    function formatRp(n) {
        return 'Rp ' + Number(n).toLocaleString('id-ID');
    }

    window.openXray = function(userId) {
        // Reset state
        loading.classList.remove('hidden');
        content.classList.add('hidden');
        overlay.classList.remove('hidden');
        // Slide up animation (mobile)
        setTimeout(function() { modal.classList.remove('translate-y-full'); }, 10);

        fetch('<?= site_url("admin/user_xray/") ?>' + userId)
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (!json.success) {
                    loading.innerHTML = '<p class="text-red-400 text-sm">Error: ' + (json.error || 'Unknown') + '</p>';
                    return;
                }
                var d = json.data;

                // Phone & username
                document.getElementById('xray-phone').textContent =
                    d.user.phone + (d.user.username ? ' — ' + d.user.username : '') + ' | ID: ' + d.user.id;

                // Deposits & Withdrawals
                document.getElementById('xray-deposits').textContent    = formatRp(d.total_credit);
                document.getElementById('xray-withdrawals').textContent = formatRp(d.total_withdrawals);

                // Rentals
                document.getElementById('xray-rentals').textContent   = d.active_rentals + ' active';
                document.getElementById('xray-invested').textContent  = formatRp(d.total_invested);

                // Balance
                var balEl = document.getElementById('xray-balance');
                balEl.textContent = formatRp(d.balance);
                balEl.className = 'text-sm font-bold font-mono ' +
                    (d.balance > 0 ? 'text-emerald-600 dark:text-emerald-400' : d.balance < 0 ? 'text-red-600 dark:text-red-400' : 'text-[var(--t-muted)]');

                // Downlines
                document.getElementById('xray-downlines').textContent = d.downline_count;

                // Profitability Status
                var badge      = document.getElementById('xray-badge');
                var badgeLabel = document.getElementById('xray-badge-label');
                var badgeValue = document.getElementById('xray-badge-value');
                var net = d.total_credit - d.total_withdrawals;

                if (net > 0) {
                    badge.className = 'rounded-xl p-4 text-center border border-emerald-500/30 bg-emerald-500/10';
                    badgeLabel.className = 'text-[10px] uppercase tracking-wider font-bold mb-1 text-emerald-600 dark:text-emerald-400';
                    badgeLabel.textContent = 'Profitability Status';
                    badgeValue.className = 'text-xl font-bold font-mono text-emerald-600 dark:text-emerald-400';
                    badgeValue.textContent = 'SYSTEM PROFIT — ' + formatRp(net);
                } else if (net < 0) {
                    badge.className = 'rounded-xl p-4 text-center border border-red-500/30 bg-red-500/10';
                    badgeLabel.className = 'text-[10px] uppercase tracking-wider font-bold mb-1 text-red-600 dark:text-red-400';
                    badgeLabel.textContent = 'Profitability Status';
                    badgeValue.className = 'text-xl font-bold font-mono text-red-600 dark:text-red-400';
                    badgeValue.textContent = 'SYSTEM LOSS — ' + formatRp(Math.abs(net));
                } else {
                    badge.className = 'rounded-xl p-4 text-center border border-amber-500/30 bg-amber-500/10';
                    badgeLabel.className = 'text-[10px] uppercase tracking-wider font-bold mb-1 text-amber-600 dark:text-amber-400';
                    badgeLabel.textContent = 'Profitability Status';
                    badgeValue.className = 'text-xl font-bold font-mono text-amber-600 dark:text-amber-400';
                    badgeValue.textContent = 'BREAK EVEN';
                }

                // Profile link
                document.getElementById('xray-profile-link').href =
                    '<?= site_url("admin/user_detail/") ?>' + d.user.id;

                // Show content
                loading.classList.add('hidden');
                content.classList.remove('hidden');
            })
            .catch(function(err) {
                loading.innerHTML = '<p class="text-red-400 text-sm">Network error. Check console.</p>';
                console.error('X-Ray fetch error:', err);
            });
    };

    window.closeXray = function() {
        modal.classList.add('translate-y-full');
        setTimeout(function() {
            overlay.classList.add('hidden');
            loading.classList.remove('hidden');
            loading.innerHTML = '<div class="inline-flex items-center gap-2 text-[var(--t-muted)] text-sm"><i class="fas fa-circle-notch fa-spin text-indigo-600 dark:text-indigo-400"></i> Scanning financial profile...</div>';
            content.classList.add('hidden');
        }, 300);
    };

    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !overlay.classList.contains('hidden')) {
            closeXray();
        }
    });
})();
</script>
