<!-- Admin User Management — User Detail Mega Dashboard -->
<?php if ($this->session->flashdata('success')): ?>
    <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm flex items-center gap-2">
        <i class="fas fa-check-circle"></i>
        <?= $this->session->flashdata('success') ?>
    </div>
<?php endif; ?>

<?php if ($this->session->flashdata('error')): ?>
    <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm flex items-center gap-2">
        <i class="fas fa-exclamation-circle"></i>
        <?= $this->session->flashdata('error') ?>
    </div>
<?php endif; ?>

<!-- Back Link -->
<a href="<?= site_url('admin/users') ?>" class="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-700 transition-colors mb-4">
    <i class="fas fa-arrow-left text-xs"></i> Kembali ke User List
</a>

<!-- User Header -->
<div class="bg-slate-800 rounded-xl p-6 mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div class="flex items-center gap-4">
        <div class="w-14 h-14 rounded-full bg-slate-700 flex items-center justify-center flex-shrink-0">
            <i class="fas fa-user text-slate-400 text-xl"></i>
        </div>
        <div>
            <h3 class="text-lg font-bold text-white">
                <?= htmlspecialchars($user->username ?? $user->phone) ?>
                <?php if ($user->role === 'admin'): ?>
                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500 text-white uppercase">Admin</span>
                <?php endif; ?>
            </h3>
            <p class="text-sm text-slate-400 font-mono"><?= htmlspecialchars($user->phone) ?></p>
            <p class="text-xs text-slate-500 mt-1">ID: <?= $user->id ?> &middot; Joined <?= date('d M Y H:i', strtotime($user->created_at)) ?></p>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <?php if ($user->is_banned): ?>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-red-500/20 text-red-400 text-xs font-semibold">
                <i class="fas fa-ban"></i> DIBANNED
            </span>
        <?php else: ?>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500/20 text-emerald-400 text-xs font-semibold">
                <i class="fas fa-check-circle"></i> ACTIVE
            </span>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- ================================================================= -->
    <!-- SECTION 1: Profile & Upline -->
    <!-- ================================================================= -->
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h4 class="text-sm font-bold text-slate-900 mb-4 flex items-center gap-2">
            <i class="fas fa-user-edit text-indigo-500"></i> Profil & Upline
        </h4>

        <?= form_open('admin/update_user/' . $user->id, 'class="space-y-4"') ?>

            <!-- Username -->
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Username</label>
                <input type="text" name="username" value="<?= htmlspecialchars($user->username ?? '') ?>"
                       class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 placeholder:text-slate-400">
            </div>

            <!-- Phone -->
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Phone</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($user->phone) ?>"
                       required class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <!-- Invite Code -->
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Invite Code</label>
                <input type="text" name="invite_code" value="<?= htmlspecialchars($user->invite_code) ?>"
                       required maxlength="10"
                       class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-900 font-mono font-semibold text-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <!-- Upline -->
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">
                    Upline Invite Code
                    <?php if ($user->parent_invite_code): ?>
                        <span class="text-slate-400 font-normal">(saat ini: <?= htmlspecialchars($user->parent_invite_code) ?> — <?= htmlspecialchars($user->parent_username ?? '—') ?>)</span>
                    <?php else: ?>
                        <span class="text-slate-400 font-normal">(tidak ada upline)</span>
                    <?php endif; ?>
                </label>
                <input type="text" name="upline_invite_code" value="" placeholder="Masukkan kode invite baru..."
                       class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 placeholder:text-slate-400">
                <p class="text-[11px] text-slate-400 mt-1">Kosongkan jika tidak ingin mengubah upline.</p>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="px-5 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 active:bg-indigo-800 transition-colors flex items-center gap-2">
                    <i class="fas fa-save text-xs"></i> Simpan Profil
                </button>

                <!-- Ban Toggle -->
                <?php if ($user->is_banned): ?>
                    <form method="POST" action="<?= site_url('admin/toggle_ban/' . $user->id) ?>" onsubmit="return confirm('Unban user ini?')">
                        <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors flex items-center gap-2">
                            <i class="fas fa-unlock text-xs"></i> Unban
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" action="<?= site_url('admin/toggle_ban/' . $user->id) ?>" onsubmit="return confirm('BANNED user ini? User tidak akan bisa login.')">
                        <button type="submit" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700 transition-colors flex items-center gap-2">
                            <i class="fas fa-ban text-xs"></i> Ban
                        </button>
                    </form>
                <?php endif; ?>
            </div>

        <?= form_close() ?>
    </div>

    <!-- ================================================================= -->
    <!-- SECTION 2: Wallet Controls -->
    <!-- ================================================================= -->
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h4 class="text-sm font-bold text-slate-900 mb-4 flex items-center gap-2">
            <i class="fas fa-wallet text-emerald-500"></i> Wallet Controls
        </h4>

        <!-- Balance Display -->
        <div class="bg-slate-50 rounded-lg p-4 mb-4 border border-slate-100">
            <p class="text-xs text-slate-500 mb-1">Calculated Balance (from ledger)</p>
            <p class="text-2xl font-bold font-mono <?= $balance > 0 ? 'text-emerald-600' : ($balance < 0 ? 'text-red-600' : 'text-slate-400') ?>">
                Rp <?= number_format($balance, 0, ',', '.') ?>
            </p>
        </div>

        <!-- Inject Form -->
        <?= form_open('admin/inject_balance/' . $user->id, 'class="space-y-3 mb-4"') ?>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Tipe</label>
                    <select name="type" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="credit">💰 Credit (Tambah)</option>
                        <option value="debit">💸 Debit (Kurang)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Amount (Rp)</label>
                    <input type="number" name="amount" min="1" step="100" required placeholder="100000"
                           class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 placeholder:text-slate-400">
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Description</label>
                <input type="text" name="description" placeholder="Admin Manual Adjustment"
                       class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 placeholder:text-slate-400">
            </div>

            <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors flex items-center gap-2">
                <i class="fas fa-plus-circle text-xs"></i> Inject Balance
            </button>

        <?= form_close() ?>

        <!-- Recent Transactions -->
        <h5 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Recent Transactions</h5>
        <?php if (empty($wallet_history)): ?>
            <p class="text-xs text-slate-400 py-3 text-center">Belum ada transaksi.</p>
        <?php else: ?>
            <div class="max-h-48 overflow-y-auto divide-y divide-slate-100 border border-slate-100 rounded-lg">
                <?php foreach ($wallet_history as $tx): ?>
                    <div class="flex items-center justify-between px-3 py-2">
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-full flex items-center justify-center <?= $tx->type === 'credit' ? 'bg-emerald-100' : 'bg-red-100' ?>">
                                <i class="fas <?= $tx->type === 'credit' ? 'fa-arrow-down text-emerald-600' : 'fa-arrow-up text-red-600' ?> text-[10px]"></i>
                            </div>
                            <div>
                                <p class="text-xs text-slate-700 line-clamp-1"><?= htmlspecialchars($tx->description ?? $tx->transaction_id) ?></p>
                                <p class="text-[10px] text-slate-400 font-mono"><?= date('d M H:i', strtotime($tx->created_at)) ?></p>
                            </div>
                        </div>
                        <span class="text-xs font-mono font-semibold <?= $tx->type === 'credit' ? 'text-emerald-600' : 'text-red-600' ?>">
                            <?= $tx->type === 'credit' ? '+' : '-' ?> Rp <?= number_format($tx->amount, 0, ',', '.') ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================================================================= -->
    <!-- SECTION 3: Active Rentals Manipulation -->
    <!-- ================================================================= -->
    <div class="bg-white rounded-xl border border-slate-200 p-6 lg:col-span-2">
        <h4 class="text-sm font-bold text-slate-900 mb-4 flex items-center gap-2">
            <i class="fas fa-server text-purple-500"></i> Rentals Manipulation
            <span class="text-xs font-normal text-slate-400 ml-1">(<?= count($rentals) ?> total)</span>
        </h4>

        <?php if (empty($rentals)): ?>
            <p class="text-sm text-slate-400 py-6 text-center">Belum ada rental.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50">
                            <th class="text-left px-3 py-2 font-medium text-slate-500 text-xs">ID</th>
                            <th class="text-left px-3 py-2 font-medium text-slate-500 text-xs">Produk</th>
                            <th class="text-left px-3 py-2 font-medium text-slate-500 text-xs">Harga</th>
                            <th class="text-left px-3 py-2 font-medium text-slate-500 text-xs">ROI/Hari</th>
                            <th class="text-left px-3 py-2 font-medium text-slate-500 text-xs">Progress</th>
                            <th class="text-left px-3 py-2 font-medium text-slate-500 text-xs">Status</th>
                            <th class="text-left px-3 py-2 font-medium text-slate-500 text-xs">Last Claim</th>
                            <th class="text-left px-3 py-2 font-medium text-slate-500 text-xs">Expires</th>
                            <th class="text-left px-3 py-2 font-medium text-slate-500 text-xs">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($rentals as $r): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-3 py-3 font-mono text-xs text-slate-600">#<?= $r->id ?></td>
                                <td class="px-3 py-3 text-xs text-slate-900 font-medium"><?= htmlspecialchars($r->product_name ?? '—') ?></td>
                                <td class="px-3 py-3 font-mono text-xs text-slate-600">Rp <?= number_format($r->purchase_price, 0, ',', '.') ?></td>
                                <td class="px-3 py-3 font-mono text-xs text-emerald-600">Rp <?= number_format($r->daily_roi, 0, ',', '.') ?>/hari</td>
                                <td class="px-3 py-3">
                                    <?php
                                    $progress = $r->total_days > 0 ? round(($r->days_processed / $r->total_days) * 100) : 0;
                                    $bar_color = $r->status === 'active' ? 'bg-indigo-500' : ($r->status === 'expired' ? 'bg-slate-400' : 'bg-red-400');
                                    ?>
                                    <div class="flex items-center gap-2">
                                        <div class="w-16 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                                            <div class="<?= $bar_color ?> h-full rounded-full" style="width: <?= min($progress, 100) ?>%"></div>
                                        </div>
                                        <span class="text-[10px] text-slate-500 font-mono"><?= $r->days_processed ?>/<?= $r->total_days ?></span>
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    <?php if ($r->status === 'active'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-100 text-indigo-700">ACTIVE</span>
                                    <?php elseif ($r->status === 'expired'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-600">EXPIRED</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-red-100 text-red-700">CANCELLED</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3 font-mono text-[10px] text-slate-500">
                                    <?= $r->last_claimed_at ? date('d M H:i', strtotime($r->last_claimed_at)) : '—' ?>
                                </td>
                                <td class="px-3 py-3 font-mono text-[10px] text-slate-500">
                                    <?= date('d M Y', strtotime($r->expired_at)) ?>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-1">
                                        <!-- Cancel Button -->
                                        <?php if ($r->status === 'active'): ?>
                                            <form method="POST" action="<?= site_url('admin/cancel_rental/' . $r->id) ?>" onsubmit="return confirm('Cancel rental #<?= $r->id ?>?')">
                                                <button type="submit" class="px-2 py-1 rounded text-[10px] font-medium bg-red-50 text-red-600 hover:bg-red-100 transition-colors">
                                                    <i class="fas fa-times-circle"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <!-- Time Travel Toggle -->
                                        <?php if ($r->status === 'active'): ?>
                                            <button onclick="document.getElementById('tt-<?= $r->id ?>').classList.toggle('hidden')"
                                                    class="px-2 py-1 rounded text-[10px] font-medium bg-amber-50 text-amber-600 hover:bg-amber-100 transition-colors">
                                                <i class="fas fa-clock"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <!-- Time Travel Row (Hidden by Default) -->
                            <?php if ($r->status === 'active'): ?>
                                <tr id="tt-<?= $r->id ?>" class="hidden bg-amber-50/50">
                                    <td colspan="9" class="px-4 py-3">
                                        <form method="POST" action="<?= site_url('admin/adjust_time/' . $r->id) ?>" class="flex flex-wrap items-end gap-3">
                                            <div>
                                                <label class="block text-[10px] font-medium text-slate-500 mb-1">Last Claimed At</label>
                                                <input type="datetime-local" name="last_claimed_at"
                                                       value="<?= $r->last_claimed_at ? date('Y-m-d\TH:i', strtotime($r->last_claimed_at)) : '' ?>"
                                                       class="px-3 py-1.5 rounded-lg border border-amber-300 text-xs text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-amber-500">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-medium text-slate-500 mb-1">Days Processed</label>
                                                <input type="number" name="days_processed" min="0" max="<?= $r->total_days ?>"
                                                       value="<?= $r->days_processed ?>"
                                                       class="w-24 px-3 py-1.5 rounded-lg border border-amber-300 text-xs text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-amber-500">
                                            </div>
                                            <button type="submit" class="px-4 py-1.5 rounded-lg bg-amber-600 text-white text-xs font-medium hover:bg-amber-700 transition-colors flex items-center gap-1.5">
                                                <i class="fas fa-forward text-[10px]"></i> Time Travel
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================================================================= -->
    <!-- SECTION 4: Inject Rental (Bypass) -->
    <!-- ================================================================= -->
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h4 class="text-sm font-bold text-slate-900 mb-4 flex items-center gap-2">
            <i class="fas fa-rocket text-orange-500"></i> Inject Rental (BYPASS)
        </h4>

        <div class="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-4 flex items-start gap-2">
            <i class="fas fa-exclamation-triangle text-amber-500 text-xs mt-0.5"></i>
            <p class="text-[11px] text-amber-700">Rental ini TIDAK akan mendebit balance user. Murni inject untuk testing.</p>
        </div>

        <?= form_open('admin/inject_rental/' . $user->id, 'class="space-y-3"') ?>

            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Pilih Produk</label>
                <select name="product_id" required
                        class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="">— Pilih GPU Product —</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p->id ?>">
                            <?= htmlspecialchars($p->name) ?>
                            — Rp <?= number_format($p->price, 0, ',', '.') ?>
                            — <?= $p->duration_days ?> hari
                            — <?= number_format($p->daily_rate, 0, ',', '.') ?>/hari
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="px-4 py-2 rounded-lg bg-orange-600 text-white text-sm font-medium hover:bg-orange-700 transition-colors flex items-center gap-2">
                <i class="fas fa-rocket text-xs"></i> Inject Rental
            </button>

        <?= form_close() ?>
    </div>

    <!-- ================================================================= -->
    <!-- SECTION 5: Downline -->
    <!-- ================================================================= -->
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h4 class="text-sm font-bold text-slate-900 mb-4 flex items-center gap-2">
            <i class="fas fa-sitemap text-blue-500"></i> Direct Downline
            <span class="text-xs font-normal text-slate-400 ml-1">(<?= count($downline) ?>)</span>
        </h4>

        <?php if (empty($downline)): ?>
            <p class="text-sm text-slate-400 py-6 text-center">Tidak ada downline langsung.</p>
        <?php else: ?>
            <div class="max-h-64 overflow-y-auto divide-y divide-slate-100 border border-slate-100 rounded-lg">
                <?php foreach ($downline as $d): ?>
                    <div class="flex items-center justify-between px-3 py-2 hover:bg-slate-50 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center">
                                <i class="fas fa-user text-slate-400 text-[10px]"></i>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-slate-700"><?= htmlspecialchars($d->username ?? $d->phone) ?></p>
                                <p class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($d->invite_code) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if ($d->is_banned): ?>
                                <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-red-100 text-red-600">Banned</span>
                            <?php endif; ?>
                            <a href="<?= site_url('admin/user_detail/' . $d->id) ?>"
                               class="px-2 py-1 rounded text-[10px] font-medium bg-indigo-50 text-indigo-600 hover:bg-indigo-100 transition-colors">
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================================================================= -->
    <!-- SECTION 6: Reset Kata Sandi -->
    <!-- ================================================================= -->
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h4 class="text-sm font-bold text-slate-900 mb-4 flex items-center gap-2">
            <i class="fas fa-key text-red-500"></i> Reset Kata Sandi
        </h4>

        <div class="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-4 flex items-start gap-2">
            <i class="fas fa-exclamation-triangle text-amber-500 text-xs mt-0.5"></i>
            <p class="text-[11px] text-amber-700">Password baru akan di-hash sebelum disimpan. User akan bisa login dengan password baru ini.</p>
        </div>

        <?= form_open('admin/reset_password/' . $user->id, 'class="flex flex-wrap items-end gap-3"') ?>

            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Password Baru</label>
                <input type="text" name="new_password" required minlength="8" placeholder="Min. 8 karakter"
                       class="w-64 px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-900
                              focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500
                              placeholder:text-slate-400">
            </div>

            <button type="submit"
                    class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700 transition-colors flex items-center gap-2">
                <i class="fas fa-key text-xs"></i> Reset Sekarang
            </button>

        <?= form_close() ?>
    </div>

</div>
