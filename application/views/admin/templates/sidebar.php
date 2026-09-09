    <!-- Sidebar -->
    <aside id="admin-sidebar"
           class="hidden lg:flex flex-col fixed inset-y-0 left-0 w-64 t-sidebar border-r z-50 transition-transform duration-200">

        <!-- Logo -->
        <div class="h-16 flex items-center px-6 border-b border-[var(--t-border)]">
            <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center mr-3">
                <i class="fas fa-bolt text-white text-sm"></i>
            </div>
            <div>
                <h1 class="text-sm font-bold text-[var(--t-text)] tracking-tight">Synapse</h1>
                <p class="text-[10px] text-[var(--t-muted)] font-medium uppercase tracking-wider">Admin Panel</p>
            </div>
        </div>

        <?php
        // Plan 94 (F2): SSR alert counts (dari Admin::__construct) —
        // badge merah live per queue. Polling JS menyegarkan id di bawah.
        $adm_alerts = isset($global_admin_alerts) ? $global_admin_alerts : array();
        $n_dep  = (int) ($adm_alerts['pending_deposits'] ?? 0);
        $n_wd   = (int) ($adm_alerts['pending_withdrawals'] ?? 0);
        $n_prom = (int) ($adm_alerts['pending_promoter_claims'] ?? 0);
        $badge = function ($id, $n) {
            return '<span id="' . $id . '" class="ml-auto min-w-[18px] h-[18px] px-1 bg-rose-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center ring-2 ring-[var(--t-surface)] ' . ($n > 0 ? '' : 'hidden') . '">' . ($n > 99 ? '99+' : $n) . '</span>';
        };
        ?>

        <!-- Navigation -->
        <nav class="flex-1 px-3 py-4 space-y-1">
            <a href="<?= site_url('admin') ?>"
               class="t-nav-link <?= $this->uri->segment(1) === 'admin' && !$this->uri->segment(2) ? 't-nav-active' : '' ?>">
                <i class="fas fa-chart-pie w-5 text-center text-xs"></i>
                <span>Dashboard</span>
            </a>
            <!-- Plan 94 (F2): shortcut queue → Command Center (anchor) + badge
                 pending deposits. Deep-link = /admin#pending-deposits. -->
            <a href="<?= site_url('admin') ?>#pending-deposits"
               class="t-nav-link">
                <i class="fas fa-arrow-down w-5 text-center text-xs"></i>
                <span>Deposit</span>
                <?= $badge('admin-badge-deposit', $n_dep) ?>
            </a>
            <!-- Plan 94 (F2): shortcut queue → Command Center (anchor) + badge
                 pending withdrawals. Deep-link = /admin#pending-withdrawals. -->
            <a href="<?= site_url('admin') ?>#pending-withdrawals"
               class="t-nav-link">
                <i class="fas fa-arrow-up w-5 text-center text-xs"></i>
                <span>Penarikan</span>
                <?= $badge('admin-badge-withdrawal', $n_wd) ?>
            </a>
            <a href="<?= site_url('admin/history/deposit') ?>"
               class="t-nav-link <?= $this->uri->segment(2) === 'history' ? 't-nav-active' : '' ?>">
                <i class="fas fa-history w-5 text-center text-xs"></i>
                <span>Riwayat Transaksi</span>
            </a>
            <a href="<?= site_url('admin/audit') ?>"
               class="t-nav-link <?= $this->uri->segment(2) === 'audit' ? 't-nav-active' : '' ?>">
                <i class="fas fa-clipboard-list w-5 text-center text-xs"></i>
                <span>Audit Logs</span>
            </a>
            <a href="<?= site_url('admin/users') ?>"
               class="t-nav-link <?= in_array($this->uri->segment(2), ['users', 'user_detail']) ? 't-nav-active' : '' ?>">
                <i class="fas fa-users w-5 text-center text-xs"></i>
                <span>User Management</span>
            </a>
            <a href="<?= site_url('admin/products') ?>"
               class="t-nav-link <?= $this->uri->segment(2) === 'products' ? 't-nav-active' : '' ?>">
                <i class="fas fa-microchip w-5 text-center text-xs"></i>
                <span>Produk GPU</span>
            </a>
            <!-- Plan 91: queue klaim reward promotor. Plan 94 (F2): + badge -->
            <a href="<?= site_url('admin/promoter-claims') ?>"
               class="t-nav-link <?= in_array($this->uri->segment(2), ['promoter-claims', 'promoter_claims']) ? 't-nav-active' : '' ?>">
                <i class="fas fa-star w-5 text-center text-xs"></i>
                <span>Klaim Promoter</span>
                <?= $badge('admin-badge-promoter', $n_prom) ?>
            </a>
            <a href="<?= site_url('admin/analytics') ?>"
               class="t-nav-link <?= $this->uri->segment(2) === 'analytics' ? 't-nav-active' : '' ?>">
                <i class="fas fa-chart-line w-5 text-center text-xs"></i>
                <span>Analytics</span>
            </a>
            <a href="<?= site_url('admin/settings') ?>"
               class="t-nav-link <?= in_array($this->uri->segment(2), ['settings', 'financial-settings']) ? 't-nav-active' : '' ?>">
                <i class="fas fa-cog w-5 text-center text-xs"></i>
                <span>Pengaturan</span>
            </a>
        </nav>

        <!-- Logout -->
        <div class="px-3 py-4 border-t border-[var(--t-border)]">
            <a href="<?= site_url('admin/logout') ?>"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-[var(--t-text-2)] hover:bg-red-500/10 hover:text-red-600 dark:hover:text-red-400 transition-colors">
                <i class="fas fa-sign-out-alt w-5 text-center text-xs"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- Mobile sidebar overlay -->
    <div id="admin-sidebar-overlay"
         class="hidden fixed inset-0 bg-black/40 z-40 lg:hidden"
         onclick="toggleSidebar()"></div>
