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

        <!-- Navigation -->
        <nav class="flex-1 px-3 py-4 space-y-1">
            <a href="<?= site_url('admin') ?>"
               class="t-nav-link <?= $this->uri->segment(1) === 'admin' && !$this->uri->segment(2) ? 't-nav-active' : '' ?>">
                <i class="fas fa-chart-pie w-5 text-center text-xs"></i>
                <span>Dashboard</span>
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
