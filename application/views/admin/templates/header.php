<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? $page_title . ' · ' : '' ?>Synapse Admin</title>
    <script>
        // ── Phase 30: Admin Theme Manager — Anti-FOUC ──
        // Apply dark class on <html> BEFORE first paint (default: dark).
        // Only a stored 'light' preference opts out.
        (function () {
            try {
                if (localStorage.getItem('admin_theme') !== 'light') {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {
                document.documentElement.classList.add('dark');
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        * { -webkit-tap-highlight-color: transparent; }

        /* ═══ Phase 30: Admin Theme Manager — Design Tokens ═══ */
        :root {
            color-scheme: light;
            --t-bg: #f1f5f9;
            --t-surface: #ffffff;
            --t-surface-2: #f8fafc;
            --t-surface-3: #f1f5f9;
            --t-border: #e2e8f0;
            --t-border-strong: #cbd5e1;
            --t-text: #0f172a;
            --t-text-2: #475569;
            --t-muted: #94a3b8;
            --t-input-bg: #ffffff;
            --t-hover: #f1f5f9;
            --t-active: #eef2ff;
            --t-chart-grid: rgba(100, 116, 139, 0.12);
            --t-chart-tick: #64748b;
            --t-tooltip-bg: #ffffff;
            --t-tooltip-title: #1e293b;
        }
        html.dark {
            color-scheme: dark;
            --t-bg: #020617;
            --t-surface: #0f172a;
            --t-surface-2: #1e293b;
            --t-surface-3: #334155;
            --t-border: #1e293b;
            --t-border-strong: #334155;
            --t-text: #f1f5f9;
            --t-text-2: #94a3b8;
            --t-muted: #64748b;
            --t-input-bg: #1e293b;
            --t-hover: #1e293b;
            --t-active: rgba(99, 102, 241, 0.15);
            --t-chart-grid: rgba(148, 163, 184, 0.08);
            --t-chart-tick: #94a3b8;
            --t-tooltip-bg: #1e293b;
            --t-tooltip-title: #e2e8f0;
        }

        /* ═══ Phase 30: Semantic component classes ═══ */
        .t-shell { background-color: var(--t-bg); color: var(--t-text); }

        .t-text-2 { color: var(--t-text-2); }
        .t-muted { color: var(--t-muted); }

        .t-card { background-color: var(--t-surface); border: 1px solid var(--t-border); border-radius: 0.75rem; }
        .t-card-hd { border-bottom: 1px solid var(--t-border); }
        .t-card-bd { padding: 1.25rem; }

        .t-th {
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--t-text-2);
            white-space: nowrap;
        }
        .t-td { color: var(--t-text-2); }
        .t-row-hover:hover { background-color: var(--t-hover); }
        .t-divide > * + * { border-top: 1px solid var(--t-border); }

        .t-input, .t-select {
            border: 1px solid var(--t-border-strong);
            background-color: var(--t-input-bg);
            color: var(--t-text);
            outline: none;
        }
        .t-input::placeholder, .t-select::placeholder { color: var(--t-muted); }
        .t-select { cursor: pointer; }

        .t-label { display: block; font-weight: 500; color: var(--t-text-2); margin-bottom: 0.375rem; }

        .t-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.125rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .t-badge-muted { background-color: var(--t-surface-3); color: var(--t-text-2); }
        .t-badge-success { background-color: rgba(16, 185, 129, 0.12); color: #059669; }
        html.dark .t-badge-success { color: #34d399; }
        .t-badge-danger { background-color: rgba(244, 63, 94, 0.12); color: #e11d48; }
        html.dark .t-badge-danger { color: #fb7185; }
        .t-badge-warning { background-color: rgba(245, 158, 11, 0.12); color: #d97706; }
        html.dark .t-badge-warning { color: #fbbf24; }
        .t-badge-info { background-color: rgba(59, 130, 246, 0.12); color: #2563eb; }
        html.dark .t-badge-info { color: #60a5fa; }
        .t-badge-indigo { background-color: rgba(99, 102, 241, 0.12); color: #4f46e5; }
        html.dark .t-badge-indigo { color: #818cf8; }

        .t-btn-ghost {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            background-color: var(--t-surface-3);
            color: var(--t-text-2);
            border-radius: 0.5rem;
            font-weight: 500;
            transition: background-color 0.15s ease, color 0.15s ease;
        }
        .t-btn-ghost:hover { background-color: var(--t-hover); color: var(--t-text); }

        .t-modal { background-color: var(--t-surface); border: 1px solid var(--t-border); border-radius: 1rem; }
        .t-modal-backdrop { background-color: rgba(0, 0, 0, 0.6); }

        .t-flash-success { background-color: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.25); color: #059669; }
        html.dark .t-flash-success { color: #34d399; }
        .t-flash-error { background-color: rgba(244, 63, 94, 0.1); border: 1px solid rgba(244, 63, 94, 0.25); color: #e11d48; }
        html.dark .t-flash-error { color: #fb7185; }
        .t-flash-info { background-color: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.25); color: #2563eb; }
        html.dark .t-flash-info { color: #60a5fa; }

        .t-nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.75rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--t-text-2);
            transition: background-color 0.15s ease, color 0.15s ease;
        }
        .t-nav-link:hover { background-color: var(--t-hover); color: var(--t-text); }
        .t-nav-active { background-color: var(--t-active); color: #4f46e5; }
        html.dark .t-nav-active { color: #818cf8; }

        .t-hero { background-color: var(--t-surface-2); border: 1px solid var(--t-border); border-radius: 0.75rem; }

        .t-sidebar { background-color: var(--t-surface); border-color: var(--t-border); }
        .t-topbar { background-color: var(--t-surface); border-color: var(--t-border); }

        /* Server-rendered pagination links (light/dark baked classes) — theme override */
        .t-pagination a { color: var(--t-text-2); border-color: var(--t-border); }
        .t-pagination a:hover { background-color: var(--t-hover); color: var(--t-text); }
    </style>
</head>
<body class="flex h-screen t-shell font-sans overflow-hidden">
