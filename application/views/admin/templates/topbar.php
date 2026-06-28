    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col lg:pl-64 min-h-screen">

        <!-- Top Bar -->
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 lg:px-6 flex-shrink-0">
            <div class="flex items-center gap-3">
                <!-- Mobile hamburger -->
                <button onclick="toggleSidebar()" class="lg:hidden text-slate-500 hover:text-slate-700 p-1">
                    <i class="fas fa-bars text-lg"></i>
                </button>
                <h2 class="text-sm font-semibold text-slate-700"><?= isset($page_title) ? $page_title : 'Dashboard' ?></h2>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 bg-slate-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-user text-slate-400 text-xs"></i>
                    </div>
                    <span class="text-sm font-medium text-slate-700 hidden sm:inline">
                        <?= $this->session->userdata('admin_username') ?? 'Admin' ?>
                    </span>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <main class="flex-1 overflow-y-auto p-4 lg:p-6">
