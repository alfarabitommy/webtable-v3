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

<!-- Tabs (title lives in the topbar — no duplicate heading) -->
<div class="flex border-b border-[var(--t-border)] mb-6">
    <a href="<?= site_url('admin/history/deposit') ?>"
       class="px-5 py-3 text-sm font-medium border-b-2 transition-colors
              <?= $type === 'deposit' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-[var(--t-muted)] hover:text-[var(--t-text-2)]' ?>">
        <i class="fas fa-arrow-down mr-1.5"></i>Deposit
    </a>
    <a href="<?= site_url('admin/history/withdrawal') ?>"
       class="px-5 py-3 text-sm font-medium border-b-2 transition-colors
              <?= $type === 'withdrawal' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-[var(--t-muted)] hover:text-[var(--t-text-2)]' ?>">
        <i class="fas fa-arrow-up mr-1.5"></i>Penarikan
    </a>
</div>

<!-- Summary Badge + CSV Export -->
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <span class="t-badge t-badge-muted">
        <?= number_format($total, 0, ',', '.') ?> transaksi
    </span>

    <?php if ($type === 'withdrawal'): ?>
        <!-- C3 (plan/52): tombol export CSV riwayat penarikan -->
        <a href="<?= site_url('admin/export_csv/withdrawals') ?>"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg t-btn-ghost border border-[var(--t-border)]
                  text-xs font-semibold text-[var(--t-text-2)] hover:text-[var(--t-text)] transition-colors">
            <i class="fas fa-file-csv text-green-600 dark:text-green-400 mr-1"></i>Ekspor CSV
        </a>
    <?php endif; ?>
</div>

<!-- Table Card -->
<div class="t-card shadow-sm overflow-hidden">
    <?php if (empty($transactions)): ?>
        <div class="px-5 py-16 text-center text-[var(--t-muted)] text-sm">
            <i class="fas fa-inbox text-3xl mb-3 block opacity-40"></i>
            Belum ada riwayat transaksi
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-[var(--t-border)]">
                        <th class="text-left px-5 py-3 t-th">Tanggal</th>
                        <th class="text-left px-5 py-3 t-th">User</th>
                        <th class="text-left px-5 py-3 t-th">No. Transaksi</th>
                        <?php if ($type === 'withdrawal'): ?>
                            <th class="text-left px-5 py-3 t-th">Bank</th>
                        <?php endif; ?>
                        <th class="text-right px-5 py-3 t-th">Nominal</th>
                        <th class="text-center px-5 py-3 t-th">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--t-border)]">
                    <?php foreach ($transactions as $row): ?>
                    <tr class="t-row-hover transition-colors">
                        <td class="px-5 py-3.5 t-text-2 whitespace-nowrap">
                            <?= date('d M Y H:i', strtotime($row->created_at)) ?>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="text-[var(--t-text)] font-medium"><?= $row->phone ?></span>
                        </td>
                        <td class="px-5 py-3.5 font-mono text-xs text-[var(--t-text-2)]">
                            <?= $type === 'deposit' ? $row->invoice_number : $row->wd_number ?>
                        </td>
                        <?php if ($type === 'withdrawal'): ?>
                        <td class="px-5 py-3.5 t-text-2 text-xs">
                            <?= $row->bank_name ?> · <?= $row->account_number ?>
                        </td>
                        <?php endif; ?>
                        <td class="px-5 py-3.5 text-right font-mono font-semibold text-[var(--t-text)] whitespace-nowrap">
                            Rp <?= number_format($row->amount, 0, ',', '.') ?>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <?php if ($row->status === 'success'): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full mr-1.5"></span>Success
                                </span>
                            <?php elseif ($row->status === 'failed'): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-500/10 text-red-600 dark:text-red-400">
                                    <span class="w-1.5 h-1.5 bg-red-500 rounded-full mr-1.5"></span>Failed
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-[var(--t-surface-3)] text-[var(--t-text-2)]">
                                    <?= $row->status ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total > 50): ?>
            <div class="px-5 py-4 border-t border-[var(--t-border)]">
                <div class="t-pagination"><?= $pagination ?></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
