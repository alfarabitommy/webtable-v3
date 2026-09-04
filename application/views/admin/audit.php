<!-- Phase 10A: Audit Trail — Bloomberg Terminal Aesthetic -->
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
            <i class="fas fa-clipboard-list text-emerald-600 dark:text-emerald-400"></i> AUDIT TRAIL — COMMAND CENTER
        </h2>
        <p class="text-xs text-[var(--t-muted)] mt-1 font-mono">System Log &middot; Every privileged admin action &middot; Immutable trail</p>
    </div>
    <div class="text-[10px] text-[var(--t-muted)] font-mono text-right">
        <div>LAST SYNC: <?= date('d M Y H:i:s') ?></div>
        <div class="mt-1 text-emerald-600 dark:text-emerald-400"><?= number_format($total) ?> ENTRIES</div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- FILTER BAR                                                         -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="t-card p-4 mb-6">
    <form method="get" action="<?= site_url('admin/audit') ?>" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--t-muted)] font-semibold mb-1.5">Action</label>
            <select name="action"
                    class="t-select text-xs font-mono rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                <option value="">-- SEMUA ACTION --</option>
                <?php foreach ($actions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt->action, ENT_QUOTES) ?>" <?= $f_action === $opt->action ? 'selected' : '' ?>>
                        <?= htmlspecialchars($opt->action) ?> (<?= (int) $opt->cnt ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--t-muted)] font-semibold mb-1.5">Dari</label>
            <input type="date" name="from" value="<?= htmlspecialchars($f_from) ?>"
                   class="t-input text-xs font-mono rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--t-muted)] font-semibold mb-1.5">Sampai</label>
            <input type="date" name="to" value="<?= htmlspecialchars($f_to) ?>"
                   class="t-input text-xs font-mono rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
        </div>
        <button type="submit"
                class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold uppercase tracking-wider transition-colors flex items-center gap-1.5">
            <i class="fas fa-filter text-[10px]"></i> Filter
        </button>
        <a href="<?= site_url('admin/audit') ?>"
           class="px-4 py-2 t-btn-ghost text-xs font-bold uppercase tracking-wider transition-colors flex items-center gap-1.5">
            <i class="fas fa-rotate-left text-[10px]"></i> Reset
        </a>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- AUDIT LOG TABLE                                                    -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="t-card overflow-hidden">
    <div class="px-5 py-4 border-b border-[var(--t-border)] flex items-center justify-between">
        <div>
            <h3 class="text-sm font-bold text-[var(--t-text)] flex items-center gap-2">
                <i class="fas fa-terminal text-emerald-600 dark:text-emerald-400 text-xs"></i> SYSTEM_LOG // AUDIT
            </h3>
            <p class="text-[10px] text-[var(--t-muted)] mt-0.5 font-mono">Ordered by created_at DESC &middot; all money values in IDR</p>
        </div>
    </div>

    <?php if (empty($logs)): ?>
        <div class="px-5 py-12 text-center">
            <i class="fas fa-inbox text-[var(--t-muted)] text-2xl mb-3"></i>
            <p class="text-sm text-[var(--t-muted)]">∅ Tidak ada catatan audit.</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-[var(--t-border)] bg-[var(--t-surface-2)]">
                        <th class="text-left px-5 py-3 t-th w-16">#ID</th>
                        <th class="text-left px-5 py-3 t-th">Waktu</th>
                        <th class="text-left px-5 py-3 t-th">Admin</th>
                        <th class="text-left px-5 py-3 t-th">Action</th>
                        <th class="text-left px-5 py-3 t-th">Target</th>
                        <th class="text-left px-5 py-3 t-th">IP</th>
                        <th class="text-left px-5 py-3 t-th">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--t-border)]">
                    <?php foreach ($logs as $log): ?>
                        <?php
                        // Action badge color by family
                        $badge = 'bg-slate-500/10 text-slate-500 dark:text-slate-400';
                        if (strpos($log->action, 'approve') === 0) {
                            $badge = 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400';
                        } elseif (strpos($log->action, 'decline') === 0) {
                            $badge = 'bg-red-500/10 text-red-600 dark:text-red-400';
                        } elseif (in_array($log->action, ['admin_create_user', 'admin_reset_password'], true)) {
                            $badge = 'bg-amber-500/10 text-amber-600 dark:text-amber-400';
                        } elseif (strpos($log->action, 'toggle') !== false) {
                            $badge = 'bg-violet-500/10 text-violet-600 dark:text-violet-400';
                        } elseif (strpos($log->action, 'inject') === 0) {
                            $badge = 'bg-sky-500/10 text-sky-600 dark:text-sky-400';
                        }
                        $details_raw = (string) $log->details;
                        ?>
                        <tr class="t-row-hover transition-colors even:bg-[var(--t-surface-2)]">
                            <td class="px-5 py-3 font-mono text-xs text-[var(--t-muted)]"><?= (int) $log->id ?></td>
                            <td class="px-5 py-3 font-mono text-xs t-text-2 whitespace-nowrap"><?= date('d M Y H:i:s', strtotime($log->created_at)) ?></td>
                            <td class="px-5 py-3 font-mono text-xs text-[var(--t-text)]">
                                <?= $log->admin_username !== null ? htmlspecialchars($log->admin_username) : '<span class="text-[var(--t-muted)]">—</span>' ?>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-block px-2 py-0.5 rounded font-mono text-[10px] font-semibold <?= $badge ?>">
                                    <?= htmlspecialchars($log->action) ?>
                                </span>
                            </td>
                            <td class="px-5 py-3 font-mono text-xs">
                                <?php if ($log->user_id !== null): ?>
                                    <a href="<?= site_url('admin/user_detail/' . (int) $log->user_id) ?>"
                                       class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300 hover:underline">
                                        <?= $log->user_phone !== null ? htmlspecialchars($log->user_phone) : '#' . (int) $log->user_id ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-[var(--t-muted)]">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 font-mono text-xs text-[var(--t-muted)] whitespace-nowrap"><?= htmlspecialchars($log->ip_address) ?></td>
                            <td class="px-5 py-3 font-mono text-[11px] t-text-2 max-w-xs truncate" title="<?= htmlspecialchars($details_raw) ?>">
                                <?= $details_raw !== '' ? htmlspecialchars($details_raw) : '<span class="text-[var(--t-muted)]">—</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pagination !== ''): ?>
            <div class="px-5 py-4 border-t border-[var(--t-border)]">
                <div class="t-pagination"><?= $pagination ?></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
