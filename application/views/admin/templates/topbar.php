    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col lg:pl-64 min-h-screen">

        <!-- Top Bar -->
        <header class="h-16 t-topbar border-b flex items-center justify-between px-4 lg:px-6 flex-shrink-0">
            <div class="flex items-center gap-3">
                <!-- Mobile hamburger -->
                <button onclick="toggleSidebar()" class="lg:hidden text-[var(--t-text-2)] hover:text-[var(--t-text)] p-1">
                    <i class="fas fa-bars text-lg"></i>
                </button>
                <h2 class="text-sm font-semibold text-[var(--t-text-2)]"><?= isset($page_title) ? $page_title : 'Dashboard' ?></h2>
            </div>
            <div class="flex items-center gap-3">
                <!-- Phase 30: Admin Theme Manager — Sun/Moon toggle -->
                <button id="admin-theme-toggle" type="button" aria-label="Ganti tema"
                        onclick="toggleAdminTheme()"
                        class="w-8 h-8 t-btn-ghost rounded-lg flex items-center justify-center cursor-pointer">
                    <i id="theme-toggle-icon" class="fas fa-sun text-sm"></i>
                </button>
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 bg-[var(--t-surface-3)] rounded-full flex items-center justify-center">
                        <i class="fas fa-user text-[var(--t-muted)] text-xs"></i>
                    </div>
                    <span class="text-sm font-medium text-[var(--t-text-2)] hidden sm:inline">
                        <?= $this->session->userdata('admin_username') ?? 'Admin' ?>
                    </span>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <main class="flex-1 overflow-y-auto p-6">
