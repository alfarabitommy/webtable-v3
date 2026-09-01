<!-- Admin User Management — User List -->
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

<!-- Header + Search -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h3 class="text-lg font-semibold text-slate-900">User Management</h3>
        <p class="text-sm text-slate-500 mt-1"><?= $total ?> total users</p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <button type="button" onclick="document.getElementById('createUserModal').classList.remove('hidden')"
                class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors flex items-center gap-2">
            <i class="fas fa-plus-circle text-xs"></i> Tambah Pengguna Baru
        </button>
        <form method="GET" action="<?= site_url('admin/users') ?>" class="flex gap-2">
            <input type="text" name="q" value="<?= htmlspecialchars($search ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="Cari phone, username, invite code..."
                   class="px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-900
                          focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500
                          placeholder:text-slate-400 w-full sm:w-72">
            <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 transition-colors">
                <i class="fas fa-search"></i>
            </button>
            <?php if ($search): ?>
                <a href="<?= site_url('admin/users') ?>" class="px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-600 hover:bg-slate-50 transition-colors">
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- User Table -->
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50">
                    <th class="text-left px-4 py-3 font-medium text-slate-500">ID</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500">Phone</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500">Username</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500">Invite Code</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500">Upline</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500">Status</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500">Balance</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500">Joined</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-slate-400">
                            <i class="fas fa-users text-2xl mb-2 block"></i>
                            Tidak ada user ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-4 py-3 font-mono text-slate-600"><?= $u->id ?></td>
                            <td class="px-4 py-3 font-mono text-slate-900"><?= htmlspecialchars($u->phone) ?></td>
                            <td class="px-4 py-3 text-slate-700"><?= htmlspecialchars($u->username ?? '—') ?></td>
                            <td class="px-4 py-3 font-mono text-xs text-indigo-600 font-semibold"><?= htmlspecialchars($u->invite_code) ?></td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500"><?= htmlspecialchars($u->parent_invite_code ?? '—') ?></td>
                            <td class="px-4 py-3">
                                <?php if ($u->is_banned): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-500 text-white">
                                        <i class="fas fa-ban text-[10px]"></i> BANNED
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500 text-white">
                                        <i class="fas fa-check-circle text-[10px]"></i> AKTIF
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs <?= $u->balance > 0 ? 'text-emerald-600' : 'text-slate-400' ?>">
                                Rp <?= number_format($u->balance, 0, ',', '.') ?>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500"><?= date('d M Y', strtotime($u->created_at)) ?></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <a href="<?= site_url('admin/user_detail/' . $u->id) ?>"
                                       class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-medium hover:bg-indigo-100 transition-colors">
                                        <i class="fas fa-eye text-[10px]"></i> Detail
                                    </a>
                                    <?= form_open('admin/toggle_ban/' . $u->id, ['onsubmit' => "return confirm('" . ($u->is_banned ? 'Buka blokir user ini?' : 'Blokir user ini? User tidak bisa login & sesi aktif akan diakhiri.') . "')", 'class' => 'inline']) ?>
                                        <button type="submit"
                                                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors
                                                       <?= $u->is_banned ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-rose-600 text-white hover:bg-rose-700' ?>">
                                            <i class="fas <?= $u->is_banned ? 'fa-unlock' : 'fa-ban' ?> text-[10px]"></i>
                                            <?= $u->is_banned ? 'Buka Blokir' : 'Blokir Akun' ?>
                                        </button>
                                    <?= form_close() ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?= $pagination ?>

<!-- ============================================================ -->
<!-- MODAL: Tambah Pengguna Baru -->
<!-- ============================================================ -->
<div id="createUserModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/50" onclick="document.getElementById('createUserModal').classList.add('hidden')"></div>

    <!-- Modal Card -->
    <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md mx-4 p-6">
        <div class="flex items-center justify-between mb-5">
            <h4 class="text-base font-bold text-slate-900 flex items-center gap-2">
                <i class="fas fa-user-plus text-emerald-500"></i> Tambah Pengguna Baru
            </h4>
            <button type="button" onclick="document.getElementById('createUserModal').classList.add('hidden')"
                    class="text-slate-400 hover:text-slate-600 transition-colors">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>

        <?= form_open('admin/create_user', 'class="space-y-4"') ?>

            <!-- Phone -->
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Nomor Telepon <span class="text-red-500">*</span></label>
                <input type="text" name="phone" required placeholder="08xxxxxxxxxx"
                       class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-900 font-mono
                              focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500
                              placeholder:text-slate-400">
            </div>

            <!-- Password -->
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Password <span class="text-red-500">*</span></label>
                <input type="text" name="password" required minlength="8" placeholder="Min. 8 karakter"
                       class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-900
                              focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500
                              placeholder:text-slate-400">
            </div>

            <!-- Upline Invite Code (Optional) -->
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">
                    Upline Invite Code <span class="text-slate-400 font-normal">(opsional)</span>
                </label>
                <input type="text" name="upline_invite_code" placeholder="Kosongkan jika root"
                       class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-900 font-mono
                              focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500
                              placeholder:text-slate-400">
                <p class="text-[11px] text-slate-400 mt-1">Jika kosong, user akan menjadi root node (parent_id NULL).</p>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="px-5 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 active:bg-emerald-800 transition-colors flex items-center gap-2">
                    <i class="fas fa-user-plus text-xs"></i> Buat Pengguna
                </button>
                <button type="button" onclick="document.getElementById('createUserModal').classList.add('hidden')"
                        class="px-4 py-2 rounded-lg border border-slate-300 text-sm text-slate-600 hover:bg-slate-50 transition-colors">
                    Batal
                </button>
            </div>

        <?= form_close() ?>
    </div>
</div>
