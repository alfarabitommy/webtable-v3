<div class="bg-slate-950 text-slate-300 font-mono text-sm p-6 min-h-screen">

    <!-- Flash Messages -->
    <?php if ($this->session->flashdata('success')): ?>
        <div class="bg-green-900/50 border border-green-500 text-green-400 text-xs font-bold px-4 py-3 rounded-lg mb-6 flex items-center gap-2">
            <i class="fas fa-check-circle"></i>
            <?= $this->session->flashdata('success') ?>
        </div>
    <?php endif; ?>
    <?php if ($this->session->flashdata('error')): ?>
        <div class="bg-red-900/50 border border-red-500 text-red-400 text-xs font-bold px-4 py-3 rounded-lg mb-6 flex items-center gap-2">
            <i class="fas fa-exclamation-triangle"></i>
            <?= $this->session->flashdata('error') ?>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-green-400 text-lg font-bold tracking-widest uppercase">SYNAPSE COMMAND CENTER // ROOT ACCESS</h1>
        <p class="text-slate-500 text-xs mt-1">System Administration Panel &mdash; Real-time Approval Queue</p>
    </div>

    <!-- Grid Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

        <!-- LEFT COLUMN: Pending Deposits -->
        <div>
            <div class="border border-slate-800 rounded-lg overflow-hidden">
                <div class="bg-slate-900 px-4 py-3 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="text-green-400 text-xs font-bold tracking-wider uppercase">
                        <i class="fas fa-arrow-down mr-1"></i> Pending Deposits
                    </h2>
                    <span class="text-[10px] font-bold text-slate-500 bg-slate-800 px-2 py-0.5 rounded">
                        <?= count($pending_deposits) ?> QUEUE
                    </span>
                </div>

                <?php if (empty($pending_deposits)): ?>
                    <div class="px-4 py-8 text-center text-slate-600 text-xs">
                        <div class="text-2xl mb-1 opacity-30">&#8709;</div>
                        No pending deposits
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-slate-800/50">
                        <?php foreach ($pending_deposits as $dep): ?>
                        <div class="px-4 py-3 hover:bg-slate-900/50 transition">
                            <div class="flex items-start justify-between mb-2">
                                <div>
                                    <div class="text-green-500 text-xs font-bold"><?= $dep->invoice_number ?></div>
                                    <div class="text-slate-400 text-[10px] mt-0.5"><?= $dep->phone ?></div>
                                </div>
                                <div class="text-right">
                                    <div class="text-white text-sm font-bold font-mono">Rp <?= number_format($dep->amount, 0, ',', '.') ?></div>
                                    <div class="text-slate-500 text-[10px]"><?= date('d M Y H:i', strtotime($dep->created_at)) ?></div>
                                </div>
                            </div>
                            <form method="POST" action="<?= base_url('admin/approve_deposit/' . $dep->id) ?>" class="mt-2"
                                  onsubmit="return confirm('Approve deposit <?= $dep->invoice_number ?>?')">
                                <button type="submit" class="w-full bg-green-700 hover:bg-green-600 text-white text-xs font-bold px-3 py-1.5 rounded transition">
                                    <i class="fas fa-check mr-1"></i> APPROVE
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN: Pending Withdrawals -->
        <div>
            <div class="border border-slate-800 rounded-lg overflow-hidden">
                <div class="bg-slate-900 px-4 py-3 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="text-orange-400 text-xs font-bold tracking-wider uppercase">
                        <i class="fas fa-arrow-up mr-1"></i> Pending Withdrawals
                    </h2>
                    <span class="text-[10px] font-bold text-slate-500 bg-slate-800 px-2 py-0.5 rounded">
                        <?= count($pending_withdrawals) ?> QUEUE
                    </span>
                </div>

                <?php if (empty($pending_withdrawals)): ?>
                    <div class="px-4 py-8 text-center text-slate-600 text-xs">
                        <div class="text-2xl mb-1 opacity-30">&#8709;</div>
                        No pending withdrawals
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-slate-800/50">
                        <?php foreach ($pending_withdrawals as $wd): ?>
                        <div class="px-4 py-3 hover:bg-slate-900/50 transition">
                            <div class="flex items-start justify-between mb-2">
                                <div>
                                    <div class="text-orange-500 text-xs font-bold"><?= $wd->wd_number ?></div>
                                    <div class="text-slate-400 text-[10px] mt-0.5"><?= $wd->phone ?></div>
                                    <div class="text-slate-500 text-[10px] mt-0.5">
                                        <?= $wd->bank_name ?> &middot; <?= $wd->account_number ?> &middot; <?= $wd->account_name ?>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-white text-sm font-bold font-mono">Rp <?= number_format($wd->amount, 0, ',', '.') ?></div>
                                    <div class="text-slate-500 text-[10px]"><?= date('d M Y H:i', strtotime($wd->created_at)) ?></div>
                                </div>
                            </div>
                            <div class="flex gap-2 mt-2">
                                <form method="POST" action="<?= base_url('admin/approve_withdrawal/' . $wd->id) ?>" class="flex-1"
                                      onsubmit="return confirm('Approve withdrawal <?= $wd->wd_number ?>?')">
                                    <button type="submit" class="w-full bg-orange-700 hover:bg-orange-600 text-white text-xs font-bold px-3 py-1.5 rounded transition">
                                        <i class="fas fa-check mr-1"></i> APPROVE
                                    </button>
                                </form>
                                <form method="POST" action="<?= base_url('admin/decline_withdrawal/' . $wd->id) ?>" class="flex-1"
                                      onsubmit="return confirm('Decline withdrawal <?= $wd->wd_number ?>? Dana akan dikembalikan.')">
                                    <button type="submit" class="w-full bg-slate-700 hover:bg-slate-600 text-white text-xs font-bold px-3 py-1.5 rounded transition">
                                        <i class="fas fa-times mr-1"></i> DECLINE
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- System Info Footer -->
    <div class="mt-8 pt-4 border-t border-slate-800 flex items-center justify-between text-[10px] text-slate-600">
        <span>SYNAPSE ADMIN v1.0 &mdash; ROOT ACCESS</span>
        <span class="font-mono"><?= date('Y-m-d H:i:s') ?></span>
    </div>

</div>
