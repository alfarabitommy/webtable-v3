<!-- Admin User Management — User Detail Mega Dashboard -->
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

<!-- Back Link -->
<a href="<?= site_url('admin/users') ?>" class="inline-flex items-center gap-2 text-sm t-text-2 hover:text-[var(--t-text)] transition-colors mb-4">
    <i class="fas fa-arrow-left text-xs"></i> Kembali ke User List
</a>

<!-- User Header (Hero) -->
<div class="t-hero p-6 mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div class="flex items-center gap-4">
        <div class="w-14 h-14 rounded-full bg-[var(--t-surface-3)] flex items-center justify-center flex-shrink-0">
            <i class="fas fa-user text-[var(--t-muted)] text-xl"></i>
        </div>
        <div>
            <h3 class="text-lg font-bold text-[var(--t-text)]">
                <?= htmlspecialchars($user->username ?? $user->phone) ?>
                <?php if ($user->role === 'admin'): ?>
                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500 text-white uppercase">Admin</span>
                <?php endif; ?>
            </h3>
            <p class="text-sm text-[var(--t-muted)] font-mono"><?= htmlspecialchars($user->phone) ?></p>
            <p class="text-xs text-[var(--t-muted)] mt-1">ID: <?= $user->id ?> &middot; Joined <?= date('d M Y H:i', strtotime($user->created_at)) ?></p>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <?php if ($user->is_banned): ?>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 text-xs font-semibold">
                <i class="fas fa-ban"></i> BANNED
            </span>
        <?php else: ?>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 text-xs font-semibold">
                <i class="fas fa-check-circle"></i> AKTIF
            </span>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- ================================================================= -->
    <!-- SECTION 1: Profile & Upline -->
    <!-- ================================================================= -->
    <div class="t-card p-6">
        <h4 class="text-sm font-bold text-[var(--t-text)] mb-4 flex items-center gap-2">
            <i class="fas fa-user-edit text-indigo-500"></i> Profil & Upline
        </h4>

        <?= form_open('admin/update_user/' . $user->id, 'class="space-y-4"') ?>

            <!-- Username -->
            <div>
                <label class="t-label text-xs mb-1">Username</label>
                <input type="text" name="username" value="<?= htmlspecialchars($user->username ?? '') ?>"
                       class="t-input w-full px-3 py-2 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <!-- Phone -->
            <div>
                <label class="t-label text-xs mb-1">Phone</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($user->phone) ?>"
                       required class="t-input w-full px-3 py-2 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <!-- Invite Code -->
            <div>
                <label class="t-label text-xs mb-1">Invite Code</label>
                <input type="text" name="invite_code" value="<?= htmlspecialchars($user->invite_code) ?>"
                       required maxlength="10"
                       class="t-input w-full px-3 py-2 rounded-lg text-sm font-mono font-semibold text-indigo-600 dark:text-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <!-- Upline -->
            <div>
                <label class="t-label text-xs mb-1">
                    Upline Invite Code
                    <?php if ($user->parent_invite_code): ?>
                        <span class="text-[var(--t-muted)] font-normal">(saat ini: <?= htmlspecialchars($user->parent_invite_code) ?> — <?= htmlspecialchars($user->parent_username ?? '—') ?>)</span>
                    <?php else: ?>
                        <span class="text-[var(--t-muted)] font-normal">(tidak ada upline)</span>
                    <?php endif; ?>
                </label>
                <input type="text" name="upline_invite_code" value="" placeholder="Masukkan kode invite baru..."
                       class="t-input w-full px-3 py-2 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                <p class="text-[11px] text-[var(--t-muted)] mt-1">Kosongkan jika tidak ingin mengubah upline.</p>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="px-5 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 active:bg-indigo-800 transition-colors flex items-center gap-2">
                    <i class="fas fa-save text-xs"></i> Simpan Profil
                </button>
            </div>

        <?= form_close() ?>

        <!-- Ban Toggle — standalone form; MUST NOT be nested inside another <form> -->
        <div class="flex items-center gap-3 pt-2">
            <?php if ($user->is_banned): ?>
                <?= form_open('admin/toggle_ban/' . $user->id, "onsubmit=\"return confirm('Buka blokir user ini?')\"") ?>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors flex items-center gap-2">
                        <i class="fas fa-unlock text-xs"></i> Buka Blokir
                    </button>
                <?= form_close() ?>
            <?php else: ?>
                <?= form_open('admin/toggle_ban/' . $user->id, "onsubmit=\"return confirm('Blokir user ini? User tidak bisa login & sesi aktif akan diakhiri.')\"") ?>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-rose-600 text-white text-sm font-medium hover:bg-rose-700 transition-colors flex items-center gap-2">
                        <i class="fas fa-ban text-xs"></i> Blokir Akun
                    </button>
                <?= form_close() ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- SECTION 2: Wallet Controls -->
    <!-- ================================================================= -->
    <div class="t-card p-6">
        <h4 class="text-sm font-bold text-[var(--t-text)] mb-4 flex items-center gap-2">
            <i class="fas fa-wallet text-emerald-500"></i> Wallet Controls
        </h4>

        <!-- Balance Display -->
        <div class="bg-[var(--t-surface-2)] rounded-lg p-4 mb-4 border border-[var(--t-border)]">
            <p class="text-xs text-[var(--t-muted)] mb-1">Calculated Balance (from ledger)</p>
            <p class="text-2xl font-bold font-mono <?= $balance > 0 ? 'text-emerald-600 dark:text-emerald-400' : ($balance < 0 ? 'text-red-600 dark:text-red-400' : 'text-[var(--t-muted)]') ?>">
                Rp <?= number_format($balance, 0, ',', '.') ?>
            </p>
        </div>

        <!-- Inject Form -->
        <?= form_open('admin/inject_balance/' . $user->id, 'class="space-y-3 mb-4"') ?>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="t-label text-xs mb-1">Tipe</label>
                    <select name="type" class="t-select w-full px-3 py-2 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="credit">💰 Credit (Tambah)</option>
                        <option value="debit">💸 Debit (Kurang)</option>
                    </select>
                </div>
                <div>
                    <label class="t-label text-xs mb-1">Amount (Rp)</label>
                    <input type="number" name="amount" min="1" step="100" required placeholder="100000"
                           class="t-input w-full px-3 py-2 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>
            </div>

            <div>
                <label class="t-label text-xs mb-1">Description</label>
                <input type="text" name="description" placeholder="Admin Manual Adjustment"
                       class="t-input w-full px-3 py-2 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors flex items-center gap-2">
                <i class="fas fa-plus-circle text-xs"></i> Inject Balance
            </button>

        <?= form_close() ?>

        <!-- Recent Transactions -->
        <h5 class="text-xs font-semibold t-muted uppercase tracking-wider mb-2">Recent Transactions</h5>
        <?php if (empty($wallet_history)): ?>
            <p class="text-xs text-[var(--t-muted)] py-3 text-center">Belum ada transaksi.</p>
        <?php else: ?>
            <div class="max-h-48 overflow-y-auto divide-y divide-[var(--t-border)] border border-[var(--t-border)] rounded-lg">
                <?php foreach ($wallet_history as $tx): ?>
                    <div class="flex items-center justify-between px-3 py-2 t-row-hover">
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-full flex items-center justify-center <?= $tx->type === 'credit' ? 'bg-emerald-500/10' : 'bg-red-500/10' ?>">
                                <i class="fas <?= $tx->type === 'credit' ? 'fa-arrow-down text-emerald-600 dark:text-emerald-400' : 'fa-arrow-up text-red-600 dark:text-red-400' ?> text-[10px]"></i>
                            </div>
                            <div>
                                <p class="text-xs text-[var(--t-text-2)] line-clamp-1"><?= htmlspecialchars($tx->description ?? $tx->transaction_id) ?></p>
                                <p class="text-[10px] text-[var(--t-muted)] font-mono"><?= date('d M H:i', strtotime($tx->created_at)) ?></p>
                            </div>
                        </div>
                        <span class="text-xs font-mono font-semibold <?= $tx->type === 'credit' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' ?>">
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
    <div class="t-card p-6 lg:col-span-2">
        <h4 class="text-sm font-bold text-[var(--t-text)] mb-4 flex items-center gap-2">
            <i class="fas fa-server text-purple-500"></i> Rentals Manipulation
            <span class="text-xs font-normal text-[var(--t-muted)] ml-1">(<?= count($rentals) ?> total)</span>
        </h4>

        <?php if (empty($rentals)): ?>
            <p class="text-sm text-[var(--t-muted)] py-6 text-center">Belum ada rental.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[var(--t-border)] bg-[var(--t-surface-2)]">
                            <th class="text-left px-3 py-2 t-th">ID</th>
                            <th class="text-left px-3 py-2 t-th">Produk</th>
                            <th class="text-left px-3 py-2 t-th">Harga</th>
                            <th class="text-left px-3 py-2 t-th">ROI/Hari</th>
                            <th class="text-left px-3 py-2 t-th">Progress</th>
                            <th class="text-left px-3 py-2 t-th">Status</th>
                            <th class="text-left px-3 py-2 t-th">Last Claim</th>
                            <th class="text-left px-3 py-2 t-th">Expires</th>
                            <th class="text-left px-3 py-2 t-th">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--t-border)]">
                        <?php foreach ($rentals as $r): ?>
                            <tr class="t-row-hover transition-colors">
                                <td class="px-3 py-3 font-mono text-xs t-text-2">#<?= $r->id ?></td>
                                <td class="px-3 py-3 text-xs text-[var(--t-text)] font-medium"><?= htmlspecialchars($r->product_name ?? '—') ?></td>
                                <td class="px-3 py-3 font-mono text-xs t-text-2">Rp <?= number_format($r->purchase_price, 0, ',', '.') ?></td>
                                <td class="px-3 py-3 font-mono text-xs text-emerald-600 dark:text-emerald-400">Rp <?= number_format($r->daily_roi, 0, ',', '.') ?>/hari</td>
                                <td class="px-3 py-3">
                                    <?php
                                    $progress = $r->total_days > 0 ? round(($r->days_processed / $r->total_days) * 100) : 0;
                                    $bar_color = $r->status === 'active' ? 'bg-indigo-500' : ($r->status === 'expired' ? 'bg-slate-400' : 'bg-red-400');
                                    ?>
                                    <div class="flex items-center gap-2">
                                        <div class="w-16 h-1.5 bg-[var(--t-surface-3)] rounded-full overflow-hidden">
                                            <div class="<?= $bar_color ?> h-full rounded-full" style="width: <?= min($progress, 100) ?>%"></div>
                                        </div>
                                        <span class="text-[10px] text-[var(--t-muted)] font-mono"><?= $r->days_processed ?>/<?= $r->total_days ?></span>
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    <?php if ($r->status === 'active'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">ACTIVE</span>
                                    <?php elseif ($r->status === 'expired'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-[var(--t-surface-3)] text-[var(--t-text-2)]">EXPIRED</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-red-500/10 text-red-600 dark:text-red-400">CANCELLED</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3 font-mono text-[10px] t-muted">
                                    <?= $r->last_claimed_at ? date('d M H:i', strtotime($r->last_claimed_at)) : '—' ?>
                                </td>
                                <td class="px-3 py-3 font-mono text-[10px] t-muted">
                                    <?= date('d M Y', strtotime($r->expired_at)) ?>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-1">
                                        <!-- Cancel Button -->
                                        <?php if ($r->status === 'active'): ?>
                                            <?= form_open('admin/cancel_rental/' . $r->id, ['onsubmit' => "return confirm('Cancel rental #{$r->id}?')"]) ?>
                                                <button type="submit" class="px-2 py-1 rounded text-[10px] font-medium bg-red-500/10 text-red-600 dark:text-red-400 hover:bg-red-500/20 transition-colors">
                                                    <i class="fas fa-times-circle"></i>
                                                </button>
                                            <?= form_close() ?>
                                        <?php endif; ?>
                                        <!-- Time Travel Toggle -->
                                        <?php if ($r->status === 'active'): ?>
                                            <button onclick="document.getElementById('tt-<?= $r->id ?>').classList.toggle('hidden')"
                                                    class="px-2 py-1 rounded text-[10px] font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 transition-colors">
                                                <i class="fas fa-clock"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <!-- Time Travel Row (Hidden by Default) -->
                            <?php if ($r->status === 'active'): ?>
                                <tr id="tt-<?= $r->id ?>" class="hidden bg-amber-500/5">
                                    <td colspan="9" class="px-4 py-3">
                                        <?= form_open('admin/adjust_time/' . $r->id, 'class="flex flex-wrap items-end gap-3"') ?>
                                            <div>
                                                <label class="t-label text-[10px] mb-1">Last Claimed At</label>
                                                <input type="datetime-local" name="last_claimed_at"
                                                       value="<?= $r->last_claimed_at ? date('Y-m-d\TH:i', strtotime($r->last_claimed_at)) : '' ?>"
                                                       class="t-input px-3 py-1.5 rounded-lg border-amber-300 dark:border-amber-500/40 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-amber-500">
                                            </div>
                                            <div>
                                                <label class="t-label text-[10px] mb-1">Days Processed</label>
                                                <input type="number" name="days_processed" min="0" max="<?= $r->total_days ?>"
                                                       value="<?= $r->days_processed ?>"
                                                       class="t-input w-24 px-3 py-1.5 rounded-lg border-amber-300 dark:border-amber-500/40 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-amber-500">
                                            </div>
                                            <button type="submit" class="px-4 py-1.5 rounded-lg bg-amber-600 text-white text-xs font-medium hover:bg-amber-700 transition-colors flex items-center gap-1.5">
                                                <i class="fas fa-forward text-[10px]"></i> Time Travel
                                            </button>
                                        <?= form_close() ?>
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
    <div class="t-card p-6">
        <h4 class="text-sm font-bold text-[var(--t-text)] mb-4 flex items-center gap-2">
            <i class="fas fa-rocket text-orange-500"></i> Inject Rental (BYPASS)
        </h4>

        <div class="bg-amber-500/10 border border-amber-500/25 rounded-lg px-3 py-2 mb-4 flex items-start gap-2">
            <i class="fas fa-exclamation-triangle text-amber-500 text-xs mt-0.5"></i>
            <p class="text-[11px] text-amber-700 dark:text-amber-400">Rental ini TIDAK akan mendebit balance user. Murni inject untuk testing.</p>
        </div>

        <?= form_open('admin/inject_rental/' . $user->id, 'class="space-y-3"') ?>

            <div>
                <label class="t-label text-xs mb-1">Pilih Produk</label>
                <select name="product_id" required
                        class="t-select w-full px-3 py-2 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
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
    <div class="t-card p-6">
        <h4 class="text-sm font-bold text-[var(--t-text)] mb-4 flex items-center gap-2">
            <i class="fas fa-sitemap text-blue-500"></i> Direct Downline
            <span class="text-xs font-normal text-[var(--t-muted)] ml-1">(<?= count($downline) ?>)</span>
        </h4>

        <?php if (empty($downline)): ?>
            <p class="text-sm text-[var(--t-muted)] py-6 text-center">Tidak ada downline langsung.</p>
        <?php else: ?>
            <div class="max-h-64 overflow-y-auto divide-y divide-[var(--t-border)] border border-[var(--t-border)] rounded-lg">
                <?php foreach ($downline as $d): ?>
                    <div class="flex items-center justify-between px-3 py-2 t-row-hover">
                        <div class="flex items-center gap-3">
                            <div class="w-7 h-7 rounded-full bg-[var(--t-surface-3)] flex items-center justify-center">
                                <i class="fas fa-user text-[var(--t-muted)] text-[10px]"></i>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-[var(--t-text-2)]"><?= htmlspecialchars($d->username ?? $d->phone) ?></p>
                                <p class="text-[10px] text-[var(--t-muted)] font-mono"><?= htmlspecialchars($d->invite_code) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if ($d->is_banned): ?>
                                <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-red-500/10 text-red-600 dark:text-red-400">Banned</span>
                            <?php endif; ?>
                            <a href="<?= site_url('admin/user_detail/' . $d->id) ?>"
                               class="px-2 py-1 rounded text-[10px] font-medium bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 transition-colors">
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
    <div class="t-card p-6">
        <h4 class="text-sm font-bold text-[var(--t-text)] mb-4 flex items-center gap-2">
            <i class="fas fa-key text-red-500"></i> Reset Kata Sandi
        </h4>

        <div class="bg-amber-500/10 border border-amber-500/25 rounded-lg px-3 py-2 mb-4 flex items-start gap-2">
            <i class="fas fa-exclamation-triangle text-amber-500 text-xs mt-0.5"></i>
            <p class="text-[11px] text-amber-700 dark:text-amber-400">Password baru akan di-hash sebelum disimpan. User akan bisa login dengan password baru ini.</p>
        </div>

        <?= form_open('admin/reset_password/' . $user->id, 'class="flex flex-wrap items-end gap-3"') ?>

            <div>
                <label class="t-label text-xs mb-1">Password Baru</label>
                <input type="text" name="new_password" required minlength="8" placeholder="Min. 8 karakter"
                       class="t-input w-64 px-3 py-2 rounded-lg text-sm
                              focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500">
            </div>

            <button type="submit"
                    class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700 transition-colors flex items-center gap-2">
                <i class="fas fa-key text-xs"></i> Reset Sekarang
            </button>

        <?= form_close() ?>
    </div>

</div>
