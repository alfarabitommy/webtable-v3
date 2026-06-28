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
