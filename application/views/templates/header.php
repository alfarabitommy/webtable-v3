<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= isset($page_title) ? $page_title . ' · ' : '' ?>Synapse</title>

    <!-- ── Phase 32: User Theme Manager — Anti-FOUC ──
         Apply dark class on <html> BEFORE first paint (default: dark).
         Only a stored 'light' preference opts out. -->
    <script>
    (function () {
        try {
            if (localStorage.getItem('user_theme') !== 'light') {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {
            document.documentElement.classList.add('dark'); // storage blocked → default dark
        }
    })();
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php $this->load->view('templates/csrf_meta'); ?>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { -webkit-tap-highlight-color: transparent; }
        body { overscroll-behavior: none; }

        /* ═══ Phase 32: User Theme Manager — Design Tokens ═══
           Dark (default) = Futuristic AI GPU: Deep Obsidian + Neural Surface + cyan/indigo glow.
           Light = Sleek Tech SaaS (crisp white/slate + refined indigo). */
        :root {
            color-scheme: light;
            --u-bg: #f1f5f9;
            --u-surface: #ffffff;
            --u-surface-2: #f8fafc;
            --u-surface-3: #f1f5f9;
            --u-border: #e2e8f0;
            --u-border-glow: rgba(99, 102, 241, 0.25);
            --u-border-glow-2: rgba(79, 70, 229, 0.20);
            --u-text: #0f172a;
            --u-text-2: #475569;
            --u-muted: #94a3b8;
            --u-input-bg: #ffffff;
            --u-hover: #f1f5f9;
            --u-active: #eef2ff;
            --u-glow: 0 10px 30px rgba(15, 23, 42, 0.08);
            --u-glass: rgba(255, 255, 255, 0.78);
            --u-glass-border: rgba(226, 232, 240, 0.9);
            --u-divide: #f1f5f9;
        }
        html.dark {
            color-scheme: dark;
            --u-bg: #040711;
            --u-surface: #0b1120;
            --u-surface-2: #0f172a;
            --u-surface-3: #1e293b;
            --u-border: rgba(148, 163, 184, 0.18);
            --u-border-glow: rgba(56, 189, 248, 0.25);
            --u-border-glow-2: rgba(99, 102, 241, 0.30);
            --u-text: #e6edf7;
            --u-text-2: #94a3b8;
            --u-muted: #64748b;
            --u-input-bg: #0d1526;
            --u-hover: rgba(148, 163, 184, 0.08);
            --u-active: rgba(56, 189, 248, 0.12);
            --u-glow: 0 0 20px rgba(56, 189, 248, 0.12), 0 0 40px rgba(99, 102, 241, 0.08);
            --u-glass: rgba(11, 17, 32, 0.72);
            --u-glass-border: rgba(56, 189, 248, 0.15);
            --u-divide: rgba(148, 163, 184, 0.12);
        }

        /* ═══ Phase 32: Semantic component classes ═══
           Rule: these classes own COLOR/BG/BORDER/SHADOW only — radius, padding & spacing
           stay as Tailwind utilities at usage sites (this <style> loads after the Play CDN
           stylesheet, so a property set here wins over the same utility on one element). */
        .u-shell { background-color: var(--u-bg); color: var(--u-text); }
        .u-app { background-color: var(--u-bg); }
        .u-text { color: var(--u-text); }
        .u-text-2 { color: var(--u-text-2); }
        .u-muted { color: var(--u-muted); }

        .u-card { background-color: var(--u-surface); border: 1px solid var(--u-border); }
        .u-card-gpu {
            background-color: var(--u-surface);
            border: 1px solid var(--u-border-glow);
            box-shadow: var(--u-glow);
        }
        /* Financial/terminal cards stay DARK in both themes (Bloomberg aesthetic — decision §2.8) */
        .u-card-fin {
            background: linear-gradient(160deg, #111827 0%, #0f172a 100%);
            border: 1px solid rgba(99, 102, 241, 0.3);
            color: #fff;
            box-shadow: var(--u-glow);
        }
        .u-card-inset { background-color: var(--u-surface-2); border: 1px solid var(--u-border); }

        .u-input, .u-select {
            border: 1px solid var(--u-border);
            background-color: var(--u-input-bg);
            color: var(--u-text);
            outline: none;
        }
        .u-input::placeholder, .u-select::placeholder { color: var(--u-muted); }
        .u-select { cursor: pointer; }

        .u-label { display: block; font-weight: 600; color: var(--u-muted); margin-bottom: 0.375rem; }

        .u-btn-cyber {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: linear-gradient(90deg, #4f46e5, #06b6d4);
            color: #fff;
            font-weight: 700;
            box-shadow: 0 0 18px rgba(6, 182, 212, 0.25);
            transition: opacity 0.15s ease, transform 0.15s ease;
        }
        .u-btn-cyber:hover { opacity: 0.92; }
        .u-btn-cyber:active { transform: scale(0.98); }

        .u-btn-dark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background-color: #0f172a;
            color: #fff;
            font-weight: 700;
            transition: background-color 0.15s ease;
        }
        html.dark .u-btn-dark { background-color: rgba(99, 102, 241, 0.35); }
        .u-btn-dark:hover { background-color: #2563eb; }
        html.dark .u-btn-dark:hover { background-color: rgba(99, 102, 241, 0.55); }

        .u-btn-ghost {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            background-color: var(--u-surface-3);
            color: var(--u-text-2);
            transition: background-color 0.15s ease, color 0.15s ease;
        }
        .u-btn-ghost:hover { background-color: var(--u-hover); color: var(--u-text); }

        .u-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .u-badge-ai { background-color: rgba(6, 182, 212, 0.12); border: 1px solid rgba(6, 182, 212, 0.3); color: #0891b2; }
        html.dark .u-badge-ai { color: #22d3ee; }
        .u-badge-success { background-color: rgba(16, 185, 129, 0.12); color: #059669; }
        html.dark .u-badge-success { color: #34d399; }
        .u-badge-danger { background-color: rgba(244, 63, 94, 0.12); color: #e11d48; }
        html.dark .u-badge-danger { color: #fb7185; }
        .u-badge-warning { background-color: rgba(245, 158, 11, 0.12); color: #d97706; }
        html.dark .u-badge-warning { color: #fbbf24; }
        .u-badge-info { background-color: rgba(59, 130, 246, 0.12); color: #2563eb; }
        html.dark .u-badge-info { color: #60a5fa; }
        .u-badge-indigo { background-color: rgba(99, 102, 241, 0.12); color: #4f46e5; }
        html.dark .u-badge-indigo { color: #818cf8; }

        .u-topbar {
            background-color: var(--u-glass);
            -webkit-backdrop-filter: blur(12px);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--u-border);
        }
        .u-capsule {
            background-color: var(--u-surface-2);
            border: 1px solid var(--u-border);
            color: var(--u-text-2);
            transition: background-color 0.15s ease, border-color 0.15s ease;
        }
        .u-capsule:hover { background-color: var(--u-hover); }

        .u-nav-bottom {
            background-color: var(--u-glass);
            -webkit-backdrop-filter: blur(16px);
            backdrop-filter: blur(16px);
            border-top: 1px solid var(--u-glass-border);
        }
        .u-nav-item { color: var(--u-text-2); transition: color 0.15s ease; }
        .u-nav-item:hover { color: var(--u-text); }
        .u-nav-active { color: #4f46e5; }
        html.dark .u-nav-active { color: #22d3ee; }
        .u-nav-dot {
            width: 4px;
            height: 4px;
            border-radius: 9999px;
            background-color: #4f46e5;
            box-shadow: 0 0 8px rgba(99, 102, 241, 0.8);
        }
        html.dark .u-nav-dot { background-color: #22d3ee; box-shadow: 0 0 8px rgba(34, 211, 238, 0.9); }

        .u-modal { background-color: var(--u-surface); }
        .u-modal-backdrop { background-color: rgba(0, 0, 0, 0.6); }

        .u-flash-success {
            background-color: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.25);
            color: #059669;
        }
        html.dark .u-flash-success { color: #34d399; }
        .u-flash-error {
            background-color: rgba(244, 63, 94, 0.1);
            border: 1px solid rgba(244, 63, 94, 0.25);
            color: #e11d48;
        }
        html.dark .u-flash-error { color: #fb7185; }

        .u-divide > * + * { border-top: 1px solid var(--u-divide); }
        .u-row-hover:hover { background-color: var(--u-hover); }

        .u-notif-read { background-color: var(--u-surface); border: 1px solid var(--u-border); }
        .u-notif-unread { background-color: var(--u-surface-2); border: 1px solid var(--u-border); box-shadow: var(--u-glow); }

        .u-toast {
            background-color: var(--u-surface-2);
            border: 1px solid var(--u-border-glow);
            color: var(--u-text);
            box-shadow: var(--u-glow);
        }

        /* Wallet top-up quick amount buttons (JS-driven state) */
        .u-amt-inactive { background-color: var(--u-surface-2); border: 1px solid var(--u-border); color: var(--u-text-2); }
        .u-amt-inactive:hover { background-color: var(--u-active); border-color: var(--u-border-glow); }

        .u-progress-track { background-color: var(--u-surface-3); }
    </style>
</head>
<body class="u-shell flex justify-center font-sans antialiased">
<div class="w-full max-w-[480px] u-app min-h-screen relative shadow-2xl overflow-x-hidden pb-24">

    <!-- Sticky Top Header -->
    <header class="h-14 u-topbar flex items-center justify-between px-4 sticky top-0 z-40">
        <div class="flex items-center">
            <img src="https://placehold.co/100x100/0f172a/ffffff?text=S" class="h-7 w-7 rounded-lg shadow-sm mr-3" alt="Logo">
            <h1 class="text-lg font-extrabold u-text tracking-tight">Synapse</h1>
        </div>
        <div class="flex items-center gap-2.5">
            <a href="<?= base_url('wallet'); ?>"
               class="u-capsule group flex items-center px-3 py-1 rounded-full transition-all duration-200 active:scale-95 hover:shadow">
                <i class="fas fa-wallet text-indigo-500 mr-2 text-xs group-hover:scale-110 transition-transform"></i>
                <span class="text-xs font-mono font-bold tracking-tighter">
                    Rp <?= isset($global_balance) ? number_format($global_balance, 0, ',', '.') : '0' ?>
                </span>
            </a>

            <!-- Theme Toggle (Phase 32) — Sun/Moon -->
            <button id="user-theme-toggle" type="button" aria-label="Ganti tema"
                    class="w-9 h-9 rounded-full u-btn-ghost flex items-center justify-center transition-colors active:scale-95"
                    onclick="toggleUserTheme()">
                <i id="theme-toggle-icon" class="fas fa-moon text-sm"></i>
            </button>

            <!-- Notification Bell -->
            <div class="relative" id="notif-wrapper">
                <button onclick="toggleNotifDropdown()"
                        class="u-text-2 relative hover:text-slate-700 dark:hover:text-slate-200 transition-colors active:scale-95"
                        title="Notifikasi">
                    <i class="fas fa-bell text-lg"></i>
                    <!-- Unread Badge -->
                    <?php $uc = isset($global_unread_count) ? (int) $global_unread_count : 0; ?>
                    <span id="notif-badge"
                          class="<?= $uc > 0 ? '' : 'hidden' ?> absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] bg-rose-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center ring-2 ring-white dark:ring-slate-900 px-1">
                        <?= $uc > 99 ? '99+' : $uc ?>
                    </span>
                </button>

                <!-- Dropdown (z-[60]: above bottom nav z-50 — guideline §2) -->
                <div id="notif-dropdown"
                     class="hidden absolute right-0 top-full mt-2 w-80 u-card rounded-xl shadow-xl overflow-hidden z-[60]">

                    <!-- Header -->
                    <div class="flex items-center justify-between px-4 py-2.5 border-b" style="border-color: var(--u-divide);">
                        <span class="text-sm font-bold u-text">Notifikasi</span>
                        <button onclick="markAllRead()" id="notif-mark-read-btn"
                                class="text-[11px] text-indigo-500 hover:text-indigo-700 font-medium transition-colors <?= $uc === 0 ? 'hidden' : '' ?>">
                            Tandai semua dibaca
                        </button>
                    </div>

                    <!-- Notification List -->
                    <div class="max-h-96 overflow-y-auto u-divide" id="notif-list">
                        <?php $notifs = isset($global_notifications) ? $global_notifications : []; ?>
                        <?php if (empty($notifs)): ?>
                            <div class="py-10 text-center">
                                <i class="fas fa-inbox u-muted text-3xl mb-2"></i>
                                <p class="text-xs u-muted">Belum ada notifikasi</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifs as $n): ?>
                                <?php
                                $type_colors = [
                                    'info'    => ['bg' => 'bg-blue-50 dark:bg-blue-500/10',    'icon' => 'fa-info-circle',  'text' => 'text-blue-500 dark:text-blue-400'],
                                    'success' => ['bg' => 'bg-green-50 dark:bg-emerald-500/10', 'icon' => 'fa-check-circle',  'text' => 'text-green-500 dark:text-emerald-400'],
                                    'warning' => ['bg' => 'bg-amber-50 dark:bg-amber-500/10',   'icon' => 'fa-exclamation-triangle', 'text' => 'text-amber-500 dark:text-amber-400'],
                                    'alert'   => ['bg' => 'bg-rose-50 dark:bg-rose-500/10',    'icon' => 'fa-times-circle',  'text' => 'text-rose-500 dark:text-rose-400'],
                                ];
                                $tc = $type_colors[$n['type']] ?? $type_colors['info'];
                                ?>
                                <div class="flex items-start gap-3 px-4 py-3 u-row-hover transition-colors">
                                    <div class="<?= $tc['bg'] ?> w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                        <i class="fas <?= $tc['icon'] ?> <?= $tc['text'] ?> text-xs"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold u-text leading-tight"><?= htmlspecialchars($n['title']) ?></p>
                                        <p class="text-xs u-text-2 mt-0.5 truncate"><?= htmlspecialchars($n['message']) ?></p>
                                        <p class="text-[10px] u-muted mt-1 font-medium">
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
                    <div class="border-t px-4 py-2.5" style="border-color: var(--u-divide);">
                        <a href="<?= base_url('notification') ?>"
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
            csrfFetch('<?= base_url('user/read_notifications') ?>', {
                method: 'POST'
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
        csrfFetch('<?= base_url('user/read_notifications') ?>', {
            method: 'POST'
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

    /* ── Phase 32: User Theme Manager ── */
    function toggleUserTheme() {
        var html = document.documentElement;
        var dark = html.classList.toggle('dark');
        try { localStorage.setItem('user_theme', dark ? 'dark' : 'light'); } catch (e) {}
        syncThemeUI(dark);
        window.dispatchEvent(new CustomEvent('user-theme-change', { detail: { dark: dark } }));
    }

    function syncThemeUI(dark) {
        document.querySelectorAll('#theme-toggle-icon').forEach(function (i) {
            i.className = 'fas ' + (dark ? 'fa-sun' : 'fa-moon') + ' text-sm';
        });
        var hubIcon = document.getElementById('theme-hub-icon');
        if (hubIcon) hubIcon.className = 'fas ' + (dark ? 'fa-sun' : 'fa-moon') + ' text-sm';
        var lbl = document.getElementById('theme-mode-label');
        if (lbl) lbl.textContent = dark ? 'Gelap' : 'Terang';
    }

    document.addEventListener('DOMContentLoaded', function () {
        syncThemeUI(document.documentElement.classList.contains('dark'));
    });
    </script>
