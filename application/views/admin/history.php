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

<!-- Page Header -->
<div class="mb-6">
    <h1 class="text-xl font-bold text-slate-900">Riwayat Transaksi</h1>
    <p class="text-sm text-slate-500 mt-1">Semua deposit & penarikan yang sudah diproses</p>
</div>

<!-- Tabs -->
<div class="flex border-b border-slate-200 mb-6">
    <a href="<?= site_url('admin/history/deposit') ?>"
       class="px-5 py-3 text-sm font-medium border-b-2 transition-colors
              <?= $type === 'deposit' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-400 hover:text-slate-600' ?>">
        <i class="fas fa-arrow-down mr-1.5"></i>Deposit
    </a>
    <a href="<?= site_url('admin/history/withdrawal') ?>"
       class="px-5 py-3 text-sm font-medium border-b-2 transition-colors
              <?= $type === 'withdrawal' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-400 hover:text-slate-600' ?>">
        <i class="fas fa-arrow-up mr-1.5"></i>Penarikan
    </a>
</div>

<!-- Summary Badge -->
<div class="mb-4">
    <span class="text-xs font-medium text-slate-500 bg-slate-100 px-2.5 py-1 rounded-full">
        <?= number_format($total, 0, ',', '.') ?> transaksi
    </span>
</div>

<!-- Table Card -->
<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <?php if (empty($transactions)): ?>
        <div class="px-5 py-16 text-center text-slate-400 text-sm">
            <i class="fas fa-inbox text-3xl mb-3 block opacity-40"></i>
            Belum ada riwayat transaksi
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100">
                        <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Tanggal</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">User</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">No. Transaksi</th>
                        <?php if ($type === 'withdrawal'): ?>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Bank</th>
                        <?php endif; ?>
                        <th class="text-right px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Nominal</th>
                        <th class="text-center px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($transactions as $row): ?>
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-5 py-3.5 text-slate-600 whitespace-nowrap">
                            <?= date('d M Y H:i', strtotime($row->created_at)) ?>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="text-slate-800 font-medium"><?= $row->phone ?></span>
                        </td>
                        <td class="px-5 py-3.5 font-mono text-xs text-slate-700">
                            <?= $type === 'deposit' ? $row->invoice_number : $row->wd_number ?>
                        </td>
                        <?php if ($type === 'withdrawal'): ?>
                        <td class="px-5 py-3.5 text-slate-600 text-xs">
                            <?= $row->bank_name ?> · <?= $row->account_number ?>
                        </td>
                        <?php endif; ?>
                        <td class="px-5 py-3.5 text-right font-mono font-semibold text-slate-900 whitespace-nowrap">
                            Rp <?= number_format($row->amount, 0, ',', '.') ?>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <?php if ($row->status === 'success'): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700">
                                    <span class="w-1.5 h-1.5 bg-green-500 rounded-full mr-1.5"></span>Success
                                </span>
                            <?php elseif ($row->status === 'failed'): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">
                                    <span class="w-1.5 h-1.5 bg-red-500 rounded-full mr-1.5"></span>Failed
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600">
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
            <div class="px-5 py-4 border-t border-slate-100">
                <?= $pagination ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
