<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login · Synapse</title>
    <script>
        // ── Phase 30: Admin Theme Manager — Anti-FOUC (default: dark) ──
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
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }

        /* ── Phase 30: Admin Theme Manager — Design Tokens ── */
        :root {
            color-scheme: light;
            --t-bg: #f1f5f9;
            --t-surface: #ffffff;
            --t-border: #e2e8f0;
            --t-border-strong: #cbd5e1;
            --t-text: #0f172a;
            --t-text-2: #475569;
            --t-muted: #94a3b8;
            --t-input-bg: #ffffff;
        }
        html.dark {
            color-scheme: dark;
            --t-bg: #020617;
            --t-surface: #0f172a;
            --t-border: #1e293b;
            --t-border-strong: #334155;
            --t-text: #f1f5f9;
            --t-text-2: #94a3b8;
            --t-muted: #64748b;
            --t-input-bg: #1e293b;
        }

        .t-shell { background-color: var(--t-bg); color: var(--t-text); }
        .t-modal { background-color: var(--t-surface); border: 1px solid var(--t-border); border-radius: 1rem; }
        .t-label { display: block; font-weight: 500; color: var(--t-text-2); margin-bottom: 0.375rem; }
        .t-input {
            border: 1px solid var(--t-border-strong);
            background-color: var(--t-input-bg);
            color: var(--t-text);
            outline: none;
        }
        .t-input::placeholder { color: var(--t-muted); }
        .t-flash-success { background-color: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.25); color: #059669; }
        html.dark .t-flash-success { color: #34d399; }
        .t-flash-error { background-color: rgba(244, 63, 94, 0.1); border: 1px solid rgba(244, 63, 94, 0.25); color: #e11d48; }
        html.dark .t-flash-error { color: #fb7185; }
    </style>
</head>
<body class="t-shell flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-sm">
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="w-12 h-12 bg-indigo-600 rounded-xl flex items-center justify-center mx-auto mb-4 shadow-lg shadow-indigo-500/20">
                <i class="fas fa-bolt text-white text-xl"></i>
            </div>
            <h1 class="text-xl font-bold text-[var(--t-text)]">Synapse Admin</h1>
            <p class="text-sm text-[var(--t-muted)] mt-1">Sign in to your admin account</p>
        </div>

        <!-- Card -->
        <div class="t-modal shadow-sm p-6">

            <?php if ($this->session->flashdata('success')): ?>
                <div class="t-flash-success text-sm font-medium px-4 py-3 rounded-xl mb-4 flex items-center gap-2">
                    <i class="fas fa-check-circle"></i>
                    <?= $this->session->flashdata('success') ?>
                </div>
            <?php endif; ?>

            <?php if ($this->session->flashdata('error')): ?>
                <div class="t-flash-error text-sm font-medium px-4 py-3 rounded-xl mb-4 flex items-center gap-2">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= $this->session->flashdata('error') ?>
                </div>
            <?php endif; ?>

            <?= form_open('control-panel') ?>
                <div class="space-y-4">
                    <div>
                        <label class="t-label text-sm mb-1.5">Username</label>
                        <input type="text" name="username" required autocomplete="off"
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm placeholder:text-[var(--t-muted)] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                               placeholder="admin">
                    </div>
                    <div>
                        <label class="t-label text-sm mb-1.5">Password</label>
                        <input type="password" name="password" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm placeholder:text-[var(--t-muted)] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                               placeholder="••••••••">
                    </div>
                </div>
                <button type="submit"
                        class="w-full mt-5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-lg transition-colors shadow-sm">
                    Sign In
                </button>
            <?= form_close() ?>
        </div>

        <p class="text-center text-xs text-[var(--t-muted)] mt-6">Synapse Admin Panel v1.0</p>
    </div>

</body>
</html>
