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

                <?php
                // Plan 94 (F2): SSR awal alert center — dropdown bell + badge
                // total. Id di-refresh polling JS (footer admin).
                $adm_alerts = isset($global_admin_alerts) ? $global_admin_alerts : array();
                $n_dep  = (int) ($adm_alerts['pending_deposits'] ?? 0);
                $n_wd   = (int) ($adm_alerts['pending_withdrawals'] ?? 0);
                $n_prom = (int) ($adm_alerts['pending_promoter_claims'] ?? 0);
                $n_tot  = $n_dep + $n_wd + $n_prom;
                $row_badge = function ($id, $n) {
                    return '<span id="' . $id . '" class="ml-auto min-w-[20px] h-5 px-1.5 bg-rose-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center ' . ($n > 0 ? '' : 'hidden') . '">' . ($n > 99 ? '99+' : $n) . '</span>';
                };
                ?>

                <!-- Plan 94 (F2): Alert Bell + Notification Dropdown -->
                <div class="relative" id="admin-alerts-wrapper">
                    <button type="button" id="admin-alerts-bell"
                            class="relative w-8 h-8 t-btn-ghost rounded-lg flex items-center justify-center cursor-pointer"
                            title="Antrean Menunggu" aria-label="Antrean Menunggu">
                        <i class="fas fa-bell text-sm"></i>
                        <span id="admin-bell-badge"
                              class="<?= $n_tot > 0 ? '' : 'hidden' ?> absolute -top-1 -right-1 min-w-[16px] h-4 px-0.5 bg-rose-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center ring-2 ring-[var(--t-surface)]">
                            <?= $n_tot > 99 ? '99+' : $n_tot ?>
                        </span>
                    </button>

                    <div id="admin-alerts-dropdown"
                         class="hidden absolute right-0 top-full mt-2 w-80 t-card rounded-xl shadow-xl overflow-hidden z-[70]">
                        <!-- Header -->
                        <div class="px-4 py-2.5 border-b flex items-center justify-between" style="border-color: var(--t-border);">
                            <span class="text-sm font-bold text-[var(--t-text)]">Antrean Menunggu</span>
                            <span class="text-[10px] font-bold text-[var(--t-muted)] uppercase tracking-wider">Alert Center</span>
                        </div>

                        <!-- Rows: counts + deep-links -->
                        <div class="divide-y" style="--tw-divide-opacity: 1;">
                            <a href="<?= site_url('admin') ?>#pending-deposits"
                               class="flex items-center gap-3 px-4 py-3 t-row-hover transition-colors">
                                <span class="w-8 h-8 rounded-lg bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-arrow-down text-xs"></i>
                                </span>
                                <span class="text-sm font-medium text-[var(--t-text)]">Deposit</span>
                                <?= $row_badge('admin-bell-deposit-count', $n_dep) ?>
                                <i class="fas fa-chevron-right text-[9px] text-[var(--t-muted)]"></i>
                            </a>
                            <a href="<?= site_url('admin') ?>#pending-withdrawals"
                               class="flex items-center gap-3 px-4 py-3 t-row-hover transition-colors">
                                <span class="w-8 h-8 rounded-lg bg-amber-500/15 text-amber-600 dark:text-amber-400 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-arrow-up text-xs"></i>
                                </span>
                                <span class="text-sm font-medium text-[var(--t-text)]">Penarikan</span>
                                <?= $row_badge('admin-bell-withdrawal-count', $n_wd) ?>
                                <i class="fas fa-chevron-right text-[9px] text-[var(--t-muted)]"></i>
                            </a>
                            <a href="<?= site_url('admin/promoter-claims') ?>"
                               class="flex items-center gap-3 px-4 py-3 t-row-hover transition-colors">
                                <span class="w-8 h-8 rounded-lg bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-star text-xs"></i>
                                </span>
                                <span class="text-sm font-medium text-[var(--t-text)]">Klaim Promoter</span>
                                <?= $row_badge('admin-bell-promoter-count', $n_prom) ?>
                                <i class="fas fa-chevron-right text-[9px] text-[var(--t-muted)]"></i>
                            </a>
                        </div>

                        <!-- Footer: mute/unmute chime (persist localStorage) -->
                        <div class="border-t px-3 py-2" style="border-color: var(--t-border);">
                            <button type="button" id="admin-alerts-mute-btn"
                                    class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-[11px] font-bold text-[var(--t-text-2)] hover:bg-[var(--t-hover)] transition-colors">
                                <i class="fas fa-volume-high text-xs"></i>
                                <span id="admin-alerts-mute-label">Suara Aktif</span>
                            </button>
                        </div>
                    </div>
                </div>

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
