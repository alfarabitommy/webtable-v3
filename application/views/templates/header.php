<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= isset($page_title) ? $page_title . ' · ' : '' ?>Synapse</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { -webkit-tap-highlight-color: transparent; }
        body { overscroll-behavior: none; }
    </style>
</head>
<body class="bg-slate-200 flex justify-center">
<div class="w-full max-w-[480px] bg-slate-50 min-h-screen relative shadow-2xl overflow-x-hidden pb-24">

    <!-- Sticky Top Header -->
    <header class="h-14 bg-white/80 backdrop-blur-md border-b border-slate-100 flex items-center justify-between px-4 sticky top-0 z-40">
        <div class="flex items-center">
            <img src="https://placehold.co/100x100/0f172a/ffffff?text=S" class="h-7 w-7 rounded-lg shadow-sm mr-3" alt="Logo">
            <h1 class="text-lg font-extrabold text-slate-900 tracking-tight">Synapse</h1>
        </div>
        <div class="flex items-center gap-3">
            <a href="<?= base_url('wallet'); ?>"
               class="group flex items-center bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-1 rounded-full transition-all duration-200 active:scale-95 border border-slate-200 shadow-sm hover:shadow">
                <i class="fas fa-wallet text-indigo-500 mr-2 text-xs group-hover:scale-110 transition-transform"></i>
                <span class="text-xs font-mono font-bold tracking-tighter">
                    Rp <?= isset($global_balance) ? number_format($global_balance, 0, ',', '.') : '0' ?>
                </span>
            </a>

            <!-- Notification Bell -->
            <div class="relative" id="notif-wrapper">
                <button onclick="toggleNotifDropdown()"
                        class="text-slate-500 relative hover:text-slate-700 transition-colors active:scale-95"
                        title="Notifikasi">
                    <i class="fas fa-bell text-lg"></i>
                    <!-- Unread Badge -->
                    <?php $uc = isset($global_unread_count) ? (int) $global_unread_count : 0; ?>
                    <span id="notif-badge"
                          class="<?= $uc > 0 ? '' : 'hidden' ?> absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] bg-rose-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center ring-2 ring-white px-1">
                        <?= $uc > 99 ? '99+' : $uc ?>
                    </span>
                </button>

                <!-- Dropdown -->
                <div id="notif-dropdown"
                     class="hidden absolute right-0 top-full mt-2 w-80 bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden z-50">

                    <!-- Header -->
                    <div class="flex items-center justify-between px-4 py-2.5 border-b border-slate-100">
                        <span class="text-sm font-bold text-slate-800">Notifikasi</span>
                        <button onclick="markAllRead()" id="notif-mark-read-btn"
                                class="text-[11px] text-indigo-500 hover:text-indigo-700 font-medium transition-colors <?= $uc === 0 ? 'hidden' : '' ?>">
                            Tandai semua dibaca
                        </button>
                    </div>

                    <!-- Notification List -->
                    <div class="max-h-96 overflow-y-auto divide-y divide-slate-50" id="notif-list">
                        <?php $notifs = isset($global_notifications) ? $global_notifications : []; ?>
                        <?php if (empty($notifs)): ?>
                            <div class="py-10 text-center">
                                <i class="fas fa-inbox text-slate-300 text-3xl mb-2"></i>
                                <p class="text-xs text-slate-400">Belum ada notifikasi</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifs as $n): ?>
                                <?php
                                $type_colors = [
                                    'info'    => ['bg' => 'bg-blue-50',    'icon' => 'fa-info-circle',  'text' => 'text-blue-500'],
                                    'success' => ['bg' => 'bg-green-50',   'icon' => 'fa-check-circle',  'text' => 'text-green-500'],
                                    'warning' => ['bg' => 'bg-amber-50',   'icon' => 'fa-exclamation-triangle', 'text' => 'text-amber-500'],
                                    'alert'   => ['bg' => 'bg-rose-50',    'icon' => 'fa-times-circle',  'text' => 'text-rose-500'],
                                ];
                                $tc = $type_colors[$n['type']] ?? $type_colors['info'];
                                ?>
                                <div class="flex items-start gap-3 px-4 py-3 hover:bg-slate-50 transition-colors">
                                    <div class="<?= $tc['bg'] ?> w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                        <i class="fas <?= $tc['icon'] ?> <?= $tc['text'] ?> text-xs"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-slate-800 leading-tight"><?= htmlspecialchars($n['title']) ?></p>
                                        <p class="text-xs text-slate-500 mt-0.5 truncate"><?= htmlspecialchars($n['message']) ?></p>
                                        <p class="text-[10px] text-slate-400 mt-1 font-medium">
                                            <?php
                                            $dt = new DateTime($n['created_at']);
                                            $now = new DateTime();
                                            $diff = $now->diff($dt);
                                            if ($diff->days === 0 && $diff->h === 0) {
                                                echo $diff->i . ' menit lalu';
                                            } elseif ($diff->days === 0) {
                                                echo $diff->h . ' jam lalu';
                                            } elseif ($diff->days < 7) {
                                                echo $diff->days . ' hari lalu';
                                            } else {
                                                echo $dt->format('d M Y');
                                            }
                                            ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Footer -->
                    <div class="border-t border-slate-100 px-4 py-2.5">
                        <a href="<?= base_url('notifications') ?>"
                           class="block text-center text-[11px] text-indigo-500 hover:text-indigo-700 font-semibold transition-colors">
                            Lihat semua notifikasi <i class="fas fa-arrow-right ml-1 text-[9px]"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <script>
    let notifUnreadCount = <?= isset($global_unread_count) ? (int) $global_unread_count : 0 ?>;

    function toggleNotifDropdown() {
        const dd = document.getElementById('notif-dropdown');
        const isOpen = !dd.classList.contains('hidden');

        // Close on second click, open on first
        if (isOpen) {
            dd.classList.add('hidden');
            document.removeEventListener('click', closeNotifOutside);
            return;
        }

        dd.classList.remove('hidden');

        // Auto mark-read on open if unread
        if (notifUnreadCount > 0) {
            fetch('<?= base_url('user/read_notifications') ?>', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    notifUnreadCount = 0;
                    const badge = document.getElementById('notif-badge');
                    badge.classList.add('hidden');
                    const markBtn = document.getElementById('notif-mark-read-btn');
                    if (markBtn) markBtn.classList.add('hidden');
                }
            })
            .catch(() => {});
        }

        // Click outside to close
        setTimeout(() => {
            document.addEventListener('click', closeNotifOutside);
        }, 10);
    }

    function closeNotifOutside(e) {
        const wrapper = document.getElementById('notif-wrapper');
        if (wrapper && !wrapper.contains(e.target)) {
            document.getElementById('notif-dropdown').classList.add('hidden');
            document.removeEventListener('click', closeNotifOutside);
        }
    }

    function markAllRead() {
        fetch('<?= base_url('user/read_notifications') ?>', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                notifUnreadCount = 0;
                document.getElementById('notif-badge').classList.add('hidden');
                document.getElementById('notif-mark-read-btn').classList.add('hidden');
            }
        })
        .catch(() => {});
    }
    </script>
