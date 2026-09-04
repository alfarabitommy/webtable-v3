<!-- Notification Full History -->
<div class="px-4 pt-4 pb-6">

    <!-- Header Bar -->
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <i class="fas fa-bell text-indigo-500 text-lg"></i>
            <h2 class="text-base font-bold u-text">Riwayat Notifikasi</h2>
        </div>
        <?php if (!empty($notifications)): ?>
            <button onclick="markAllRead()" id="mark-all-btn"
                    class="u-btn-ghost text-[11px] font-medium px-3 py-1.5 rounded-lg transition-colors active:scale-95">
                Tandai Semua Sudah Dibaca
            </button>
        <?php endif; ?>
    </div>

    <!-- Notification List -->
    <div id="notif-container" class="space-y-2">
        <?php if (empty($notifications)): ?>
            <!-- Empty State -->
            <div class="flex flex-col items-center justify-center py-16">
                <div class="w-16 h-16 bg-slate-100 dark:bg-slate-800 rounded-full flex items-center justify-center mb-4">
                    <i class="fas fa-bell-slash text-slate-300 dark:text-slate-600 text-2xl"></i>
                </div>
                <p class="text-sm font-medium u-text-2">Belum ada notifikasi</p>
                <p class="text-xs u-muted mt-1">Notifikasi akan muncul di sini</p>
            </div>
        <?php else: ?>
            <?php
            $type_styles = [
                'info'        => ['border' => 'border-l-blue-500',    'dot' => 'bg-blue-500',    'icon' => 'fa-info-circle',   'icon_color' => 'text-blue-500 dark:text-blue-400',    'bg' => 'bg-blue-50 dark:bg-blue-500/10',    'pill' => 'bg-blue-100 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400'],
                'success'     => ['border' => 'border-l-emerald-500', 'dot' => 'bg-emerald-500', 'icon' => 'fa-check-circle',  'icon_color' => 'text-emerald-500 dark:text-emerald-400', 'bg' => 'bg-emerald-50 dark:bg-emerald-500/10', 'pill' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400'],
                'warning'     => ['border' => 'border-l-amber-500',   'dot' => 'bg-amber-500',   'icon' => 'fa-exclamation-triangle', 'icon_color' => 'text-amber-500 dark:text-amber-400', 'bg' => 'bg-amber-50 dark:bg-amber-500/10', 'pill' => 'bg-amber-100 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400'],
                'error'       => ['border' => 'border-l-rose-500',    'dot' => 'bg-rose-500',    'icon' => 'fa-times-circle',  'icon_color' => 'text-rose-500 dark:text-rose-400',    'bg' => 'bg-rose-50 dark:bg-rose-500/10',    'pill' => 'bg-rose-100 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400'],
                'commission'  => ['border' => 'border-l-indigo-500',  'dot' => 'bg-indigo-500',  'icon' => 'fa-coins',         'icon_color' => 'text-indigo-500 dark:text-indigo-400',  'bg' => 'bg-indigo-50 dark:bg-indigo-500/10',  'pill' => 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400'],
            ];
            $type_labels = [
                'info'       => 'Info',
                'success'    => 'Berhasil',
                'warning'    => 'Peringatan',
                'error'      => 'Gagal',
                'commission' => 'Bonus',
            ];
            $current_date = '';
            foreach ($notifications as $n):
                $style = $type_styles[$n['type']] ?? $type_styles['info'];
                $label = $type_labels[$n['type']] ?? 'Info';
                $is_unread = !$n['is_read'];

                // Date grouping
                $dt = new DateTime($n['created_at']);
                $today = new DateTime();
                if ($dt->format('Y-m-d') === $today->format('Y-m-d')) {
                    $date_label = 'Hari Ini';
                } elseif ($dt->format('Y-m-d') === $today->modify('-1 day')->format('Y-m-d')) {
                    $date_label = 'Kemarin';
                } else {
                    $date_label = $dt->format('d M Y');
                }
                $today = new DateTime(); // reset

                if ($date_label !== $current_date):
                    $current_date = $date_label;
                ?>
                    <!-- Date Separator -->
                    <div class="flex items-center gap-3 py-2 mt-2 first:mt-0">
                        <div class="flex-1 h-px bg-slate-200 dark:bg-slate-700"></div>
                        <span class="text-[10px] font-semibold u-muted uppercase tracking-wider"><?= $date_label ?></span>
                        <div class="flex-1 h-px bg-slate-200 dark:bg-slate-700"></div>
                    </div>
                <?php endif; ?>

                <!-- Notification Card -->
                <div class="notif-card <?= $style['border'] ?> border-l-[3px] rounded-lg transition-all duration-200
                    <?= $is_unread ? 'u-notif-unread shadow-sm' : 'u-notif-read' ?>"
                     data-id="<?= $n['id'] ?>" data-unread="<?= $is_unread ? '1' : '0' ?>">

                    <div class="flex items-start gap-3 p-3">
                        <!-- Icon -->
                        <div class="<?= $style['bg'] ?> w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i class="fas <?= $style['icon'] ?> <?= $style['icon_color'] ?> text-xs"></i>
                        </div>

                        <!-- Content -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-0.5">
                                <?php if ($is_unread): ?>
                                    <span class="w-1.5 h-1.5 rounded-full <?= $style['dot'] ?> flex-shrink-0"></span>
                                <?php endif; ?>
                                <h4 class="text-sm font-semibold <?= $is_unread ? 'text-slate-900 dark:text-slate-100' : 'text-slate-600 dark:text-slate-300' ?> leading-tight truncate">
                                    <?= htmlspecialchars($n['title']) ?>
                                </h4>
                                <span class="<?= $style['pill'] ?> text-[9px] font-bold px-1.5 py-0.5 rounded-full flex-shrink-0 uppercase">
                                    <?= $label ?>
                                </span>
                            </div>
                            <p class="text-xs <?= $is_unread ? 'text-slate-600 dark:text-slate-300' : 'text-slate-400 dark:text-slate-500' ?> mt-0.5 leading-relaxed">
                                <?= htmlspecialchars($n['message']) ?>
                            </p>
                            <p class="text-[10px] u-muted mt-1.5 font-medium">
                                <?php
                                $diff = (new DateTime())->diff($dt);
                                if ($diff->days === 0 && $diff->h === 0) {
                                    echo $diff->i . ' menit lalu';
                                } elseif ($diff->days === 0) {
                                    echo $diff->h . ' jam lalu';
                                } elseif ($diff->days < 7) {
                                    echo $diff->days . ' hari lalu';
                                } else {
                                    echo $dt->format('d M Y, H:i');
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function markAllRead() {
    const btn = document.getElementById('mark-all-btn');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Memproses...';
    }

    csrfFetch('<?= base_url('notification/mark_all_read') ?>', {
        method: 'POST'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Remove all unread styling
            document.querySelectorAll('.notif-card[data-unread="1"]').forEach(card => {
                card.classList.remove('u-notif-unread', 'shadow-sm');
                card.classList.add('u-notif-read');
                card.setAttribute('data-unread', '0');
                // Remove blue dots
                const dot = card.querySelector('.rounded-full:not([class*="pill"]):not([class*="icon"])');
                if (dot && dot.classList.contains('bg-blue-500') ||
                    dot && dot.classList.contains('bg-emerald-500') ||
                    dot && dot.classList.contains('bg-amber-500') ||
                    dot && dot.classList.contains('bg-rose-500') ||
                    dot && dot.classList.contains('bg-indigo-500')) {
                    // Only remove small dot indicators, not type icons
                    if (dot.offsetWidth < 10) {
                        dot.remove();
                    }
                }
                // Dim title text
                const title = card.querySelector('h4');
                if (title) {
                    title.classList.remove('text-slate-900', 'dark:text-slate-100');
                    title.classList.add('text-slate-600', 'dark:text-slate-300');
                }
            });

            if (btn) {
                btn.textContent = 'Semua Sudah Dibaca ✓';
                btn.classList.remove('u-btn-ghost');
                btn.classList.add('bg-emerald-50', 'dark:bg-emerald-500/10', 'text-emerald-600', 'dark:text-emerald-400');
            }

            // Update header badge
            const badge = document.getElementById('notif-badge');
            if (badge) badge.classList.add('hidden');
            notifUnreadCount = 0;
        }
    })
    .catch(() => {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Tandai Semua Sudah Dibaca';
        }
    });
}
</script>
