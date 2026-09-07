<!-- Admin — Queue Klaim Promoter (Plan 91: omzet burn + persetujuan manual) -->
<?php
    $base_list = site_url('admin/promoter-claims');
    $tab_url = function ($st) use ($base_list, $search) {
        $p = [];
        if ($search !== '') $p['q'] = $search;
        if ($st !== '')     $p['status'] = $st;
        return $base_list . ($p ? '?' . http_build_query($p) : '');
    };
    $status_chip = [
        'pending'  => 'background:rgba(245,158,11,.12);color:#d97706;border:1px solid rgba(245,158,11,.35);',
        'approved' => 'background:rgba(16,185,129,.12);color:#059669;border:1px solid rgba(16,185,129,.35);',
        'rejected' => 'background:rgba(244,63,94,.12);color:#e11d48;border:1px solid rgba(244,63,94,.35);',
    ];
?>

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

<!-- Header + Search -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <p class="text-sm t-text-2"><?= $total ?> total klaim reward promotor</p>
    <form method="GET" action="<?= $base_list ?>" class="flex gap-2">
        <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
        <input type="text" name="q" value="<?= htmlspecialchars($search ?? '', ENT_QUOTES, 'UTF-8') ?>"
               placeholder="Cari phone / username promotor..."
               class="t-input w-full sm:w-72 px-3 py-2 rounded-lg text-sm
                      focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
        <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 transition-colors">
            <i class="fas fa-search"></i>
        </button>
        <?php if ($search): ?>
            <a href="<?= $base_list ?>" class="px-3 py-2 rounded-lg t-btn-ghost text-sm border border-[var(--t-border)]">
                <i class="fas fa-times"></i>
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Tabs status -->
<div class="flex items-center gap-2 mb-4 flex-wrap">
    <a href="<?= $tab_url('') ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors <?= $status === '' ? 'bg-indigo-600 text-white' : 't-btn-ghost border border-[var(--t-border)] text-[var(--t-text-2)] hover:bg-indigo-500/10' ?>">
        Semua
    </a>
    <a href="<?= $tab_url('pending') ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors <?= $status === 'pending' ? 'bg-amber-600 text-white' : 't-btn-ghost border border-[var(--t-border)] text-[var(--t-text-2)] hover:bg-amber-500/10' ?>">
        Pending
        <?php if ((int) $pending_count > 0): ?>
            <span class="ml-1 px-1.5 py-0.5 rounded-full text-[9px] font-bold <?= $status === 'pending' ? 'bg-white/20' : 'bg-amber-500/15 text-amber-600 dark:text-amber-400' ?>"><?= (int) $pending_count ?></span>
        <?php endif; ?>
    </a>
    <a href="<?= $tab_url('approved') ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors <?= $status === 'approved' ? 'bg-emerald-600 text-white' : 't-btn-ghost border border-[var(--t-border)] text-[var(--t-text-2)] hover:bg-emerald-500/10' ?>">
        Approved
    </a>
    <a href="<?= $tab_url('rejected') ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors <?= $status === 'rejected' ? 'bg-rose-600 text-white' : 't-btn-ghost border border-[var(--t-border)] text-[var(--t-text-2)] hover:bg-rose-500/10' ?>">
        Rejected
    </a>
</div>

<!-- Table -->
<div class="t-card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-[var(--t-border)] bg-[var(--t-surface-2)]">
                    <th class="text-left px-4 py-3 t-th">ID</th>
                    <th class="text-left px-4 py-3 t-th">Promotor</th>
                    <th class="text-left px-4 py-3 t-th">Produk Reward</th>
                    <th class="text-left px-4 py-3 t-th">Burn (Omzet)</th>
                    <th class="text-left px-4 py-3 t-th">Telemetri Downline</th>
                    <th class="text-left px-4 py-3 t-th">Status</th>
                    <th class="text-left px-4 py-3 t-th">Diajukan</th>
                    <th class="text-left px-4 py-3 t-th">Diproses</th>
                    <th class="text-left px-4 py-3 t-th">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--t-border)]">
                <?php if (empty($claims)): ?>
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-[var(--t-muted)]">
                            <i class="fas fa-star text-2xl mb-2 block"></i>
                            Tidak ada klaim promotor ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($claims as $c): ?>
                        <tr class="t-row-hover transition-colors">
                            <td class="px-4 py-3 font-mono text-xs t-text-2">#<?= $c->id ?></td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col items-start gap-1">
                                    <span class="font-mono text-[var(--t-text)]"><?= htmlspecialchars($c->user_phone ?? '—') ?></span>
                                    <span class="flex items-center gap-1 flex-wrap">
                                        <?php if (!empty($c->is_banned)): ?>
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-red-500/10 text-red-600 dark:text-red-400">Banned</span>
                                        <?php endif; ?>
                                        <?php if (!empty($c->is_promoter)): ?>
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">Promotor</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs font-medium text-[var(--t-text)]"><?= htmlspecialchars($c->product_name ?? '—') ?></span>
                                <?php if ($c->product_price !== null): ?>
                                    <span class="block text-[10px] t-muted font-mono">Nilai Rp <?= number_format((int) $c->product_price, 0, ',', '.') ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-amber-600 dark:text-amber-400 font-semibold">
                                Rp <?= number_format((int) $c->omzet_cost, 0, ',', '.') ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php $tm = $c->telemetry ?? null; if ($tm): ?>
                                    <div class="text-[10px] t-text-2 leading-relaxed">
                                        <span class="font-mono">L1: Rp <?= number_format((int) $tm['total_l1'], 0, ',', '.') ?></span><br>
                                        <span class="font-mono">Tersedia: Rp <?= number_format((int) $tm['available'], 0, ',', '.') ?></span>
                                        <span class="t-muted"> · <?= (int) $tm['l1_count'] ?> downline</span>
                                    </div>
                                <?php else: ?>
                                    <span class="text-[10px] t-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="t-badge" style="<?= $status_chip[$c->status] ?? '' ?>">
                                    <i class="fas <?= $c->status === 'approved' ? 'fa-check-circle' : ($c->status === 'rejected' ? 'fa-times-circle' : 'fa-clock') ?> text-[10px]"></i>
                                    <?= strtoupper($c->status) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs t-muted"><?= date('d M Y H:i', strtotime($c->created_at)) ?></td>
                            <td class="px-4 py-3">
                                <?php if ($c->status !== 'pending'): ?>
                                    <span class="text-[10px] t-text-2"><?= htmlspecialchars($c->admin_username ?? 'Admin #' . (int) $c->admin_id, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($c->admin_notes)): ?>
                                        <span class="block text-[10px] t-muted max-w-[220px] truncate" title="<?= htmlspecialchars($c->admin_notes, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c->admin_notes, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-[10px] t-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($c->status === 'pending'): ?>
                                    <div class="flex items-center gap-2">
                                        <?= form_open('admin/promoter-claims/approve/' . $c->id, ['onsubmit' => "return confirm('Setujui klaim #{$c->id}? Kontrak reward zero-cost akan langsung aktif.')", 'class' => 'inline']) ?>
                                            <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-medium hover:bg-emerald-700 transition-colors">
                                                <i class="fas fa-check text-[10px]"></i> Approve
                                            </button>
                                        <?= form_close() ?>
                                        <button type="button"
                                                onclick="openRejectModal('<?= site_url('admin/promoter-claims/reject/' . $c->id) ?>', '#<?= $c->id ?>')"
                                                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-rose-600 text-white text-xs font-medium hover:bg-rose-700 transition-colors">
                                            <i class="fas fa-times text-[10px]"></i> Reject
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span class="text-[10px] t-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<div class="t-pagination"><?= $pagination ?></div>

<!-- ============================================================ -->
<!-- MODAL: Alasan Penolakan (Reject) -->
<!-- ============================================================ -->
<div id="rejectClaimModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center">
    <div class="absolute inset-0 t-modal-backdrop" onclick="closeRejectModal()"></div>
    <div class="relative t-modal shadow-2xl w-full max-w-md mx-4 p-6">
        <div class="flex items-center justify-between mb-5">
            <h4 class="text-base font-bold text-[var(--t-text)] flex items-center gap-2">
                <i class="fas fa-times-circle text-rose-500"></i> Tolak Klaim <span id="rejectClaimLabel" class="font-mono text-sm"></span>
            </h4>
            <button type="button" onclick="closeRejectModal()" class="text-[var(--t-muted)] hover:text-[var(--t-text-2)] transition-colors">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>

        <form id="rejectClaimForm" method="post" action="" class="space-y-4">
            <!-- CSRF token (CI3): form raw (bukan form_open) → token di-inject
                 manual agar POST reject tidak ditolak 403 oleh csrf_protection. -->
            <input type="hidden" name="<?= $this->security->get_csrf_token_name(); ?>" value="<?= $this->security->get_csrf_hash(); ?>">
            <div>
                <label class="t-label text-xs mb-1">Alasan Penolakan <span class="text-red-500">*</span></label>
                <textarea name="admin_notes" rows="3" required maxlength="255"
                          placeholder="Contoh: omzet downline tidak valid / perlu verifikasi tambahan..."
                          class="t-input w-full px-3 py-2 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-rose-500 focus:border-rose-500"></textarea>
                <p class="text-[11px] text-[var(--t-muted)] mt-1">Alasan dikirim ke promotor; omzet yang terkunci otomatis dilepas.</p>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="px-5 py-2 rounded-lg bg-rose-600 text-white text-sm font-medium hover:bg-rose-700 active:bg-rose-800 transition-colors flex items-center gap-2">
                    <i class="fas fa-times text-xs"></i> Tolak Klaim
                </button>
                <button type="button" onclick="closeRejectModal()" class="px-4 py-2 rounded-lg t-btn-ghost text-sm border border-[var(--t-border)]">
                    Batal
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectModal(url, label) {
    var m = document.getElementById('rejectClaimModal');
    var f = document.getElementById('rejectClaimForm');
    var l = document.getElementById('rejectClaimLabel');
    if (!m || !f) return;
    f.action = url;
    if (l) l.textContent = label || '';
    m.classList.remove('hidden');
}
function closeRejectModal() {
    var m = document.getElementById('rejectClaimModal');
    if (m) m.classList.add('hidden');
}
</script>
