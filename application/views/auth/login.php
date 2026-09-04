<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login · Synapse</title>

    <!-- ── Phase 32: User Theme Manager — Anti-FOUC (standalone auth) ── -->
    <script>
    (function () {
        try {
            if (localStorage.getItem('user_theme') !== 'light') {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {
            document.documentElement.classList.add('dark');
        }
    })();
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <style>
        /* ═══ Phase 32: User Theme Manager — token subset (standalone auth) ═══ */
        :root {
            color-scheme: light;
            --u-surface: #ffffff;
            --u-border: #e2e8f0;
            --u-text: #0f172a;
            --u-text-2: #475569;
            --u-muted: #94a3b8;
            --u-input-bg: #ffffff;
        }
        html.dark {
            color-scheme: dark;
            --u-surface: #0b1120;
            --u-border: rgba(148, 163, 184, 0.18);
            --u-text: #e6edf7;
            --u-text-2: #94a3b8;
            --u-muted: #64748b;
            --u-input-bg: #0d1526;
        }
        .u-surface-bg { background-color: var(--u-surface); }
        .u-input {
            border: 1px solid var(--u-border);
            background-color: var(--u-input-bg);
            color: var(--u-text);
            outline: none;
        }
        .u-input::placeholder { color: var(--u-muted); }
        .u-btn-dark {
            background-color: #0f172a;
            color: #fff;
            font-weight: 700;
            transition: background-color 0.15s ease;
        }
        html.dark .u-btn-dark { background-color: rgba(99, 102, 241, 0.35); }
        .u-btn-dark:hover { background-color: #2563eb; }
        html.dark .u-btn-dark:hover { background-color: rgba(99, 102, 241, 0.55); }
        .u-flash-success { background-color: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.25); color: #059669; }
        html.dark .u-flash-success { color: #34d399; }
        .u-flash-error { background-color: rgba(244, 63, 94, 0.1); border: 1px solid rgba(244, 63, 94, 0.25); color: #e11d48; }
        html.dark .u-flash-error { color: #fb7185; }
    </style>
</head>
<body class="bg-slate-900 flex justify-center min-h-screen font-sans antialiased">

<div class="w-full max-w-[480px] min-h-screen mx-auto relative bg-slate-900 overflow-hidden flex flex-col">

    <!-- ═══ TOP: Branding & Background ═══ -->
    <div class="h-[35vh] w-full relative flex flex-col items-center justify-center shrink-0">
        <img src="https://placehold.co/480x400/1e293b/334155?text=Tech+Background" class="absolute inset-0 w-full h-full object-cover opacity-40 mix-blend-overlay" alt="Background">
        <div class="absolute inset-0 bg-gradient-to-b from-slate-900/60 via-slate-900/40 to-slate-900/90"></div>

        <img src="https://placehold.co/160x50/ffffff/0f172a?text=SYNAPSE+LOGO" class="relative z-10 mb-4 rounded-lg shadow-lg" alt="Synapse Logo">
        <p class="relative z-10 text-white/90 text-sm font-medium text-center px-6 leading-relaxed">Gerbang Akses Ekosistem AI</p>
    </div>

    <!-- ═══ BOTTOM: Form Card (Phase 32: theme-aware surface) ═══ -->
    <div class="u-surface-bg flex-1 rounded-t-[2.5rem] w-full relative z-20 shadow-[0_-15px_40px_rgba(0,0,0,0.2)] px-6 py-8 flex flex-col -mt-4">

        <!-- Flashdata Success -->
        <?php if ($this->session->flashdata('success')): ?>
            <div class="u-flash-success px-4 py-3 rounded-xl text-sm mb-4">
                <?= $this->session->flashdata('success') ?>
            </div>
        <?php endif; ?>

        <!-- Flashdata Error -->
        <?php if ($this->session->flashdata('error')): ?>
            <div class="u-flash-error px-4 py-3 rounded-xl text-sm mb-4">
                <?= $this->session->flashdata('error') ?>
            </div>
        <?php endif; ?>

        <!-- Validation / Auth Errors -->
        <?php if (!empty($errors)): ?>
            <div class="mb-4 space-y-1">
                <?php foreach ($errors as $e): ?>
                    <div class="u-flash-error px-4 py-3 rounded-xl text-sm"><?= $e ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <h2 class="text-xl font-bold u-text mb-1">Masuk</h2>
        <p class="text-sm u-text-2 mb-6">Masukkan nomor telepon dan kata sandi Anda.</p>

        <?= form_open('login', ['class' => 'space-y-4']) ?>

            <div>
                <label for="phone" class="text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5 block">Nomor Telepon</label>
                <input type="tel" id="phone" name="phone" value="<?= set_value('phone', $values['phone'] ?? '') ?>"
                       class="u-input h-14 px-5 rounded-2xl text-sm focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all w-full"
                       placeholder="081234567890" inputmode="numeric">
                <?= form_error('phone', '<p class="text-xs text-rose-500 mt-1.5">', '</p>') ?>
            </div>

            <div>
                <label for="password" class="text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5 block">Kata Sandi</label>
                <input type="password" id="password" name="password"
                       class="u-input h-14 px-5 rounded-2xl text-sm focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all w-full"
                       placeholder="Minimal 8 karakter">
                <?= form_error('password', '<p class="text-xs text-rose-500 mt-1.5">', '</p>') ?>
            </div>

            <!-- Native SVG CAPTCHA (plan/72): inline SVG, single-use, TTL 3 menit -->
            <div>
                <label for="captcha" class="text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5 block">Kode Keamanan</label>
                <div class="flex items-center gap-3">
                    <input type="text" id="captcha" name="captcha" maxlength="5" autocomplete="off"
                           class="u-input h-14 px-3 rounded-2xl text-sm text-center uppercase tracking-[0.25em] w-36 focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all"
                           placeholder="Kode Keamanan" aria-label="Kode Keamanan"
                           oninput="this.value = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase()">
                    <div id="captcha-box"
                         class="flex-1 h-14 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 flex items-center justify-center overflow-hidden p-1"
                         aria-live="polite">
                        <?= $captcha_svg ?>
                    </div>
                    <button type="button" id="captcha-refresh" title="Muat ulang kode keamanan"
                            aria-label="Muat ulang kode keamanan"
                            class="h-14 w-12 shrink-0 rounded-xl border border-slate-300 dark:border-slate-700 text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 flex items-center justify-center transition-colors">
                        <svg viewBox="0 0 24 24" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 12a9 9 0 1 1-2.64-6.36"></path>
                            <polyline points="21 3 21 9 15 9"></polyline>
                        </svg>
                    </button>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1.5">5 karakter alfanumerik, berlaku 3 menit &amp; hanya sekali pakai.</p>
            </div>

            <button type="submit" class="u-btn-dark h-14 w-full rounded-2xl shadow-lg flex items-center justify-center mt-2">
                <span>Masuk</span>
            </button>

        <?= form_close() ?>

        <p class="u-text-2 text-sm text-center mt-6">
            Belum punya akun?
            <a href="<?= site_url('register') ?>" class="text-blue-600 dark:text-blue-400 font-semibold hover:underline">Daftar sekarang</a>
        </p>
    </div>

</div>

<script>
    /* Native SVG CAPTCHA refresh (plan/72) — vanilla fetch, no library. */
    var siteUrl = function (path) { return '<?= site_url() ?>' + path; };
    (function () {
        var btn = document.getElementById('captcha-refresh');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            if (btn.disabled) { return; }
            btn.disabled = true;
            fetch(siteUrl('auth/refresh_captcha'), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (res) {
                    if (!res.ok) { throw new Error('HTTP ' + res.status); }
                    return res.json();
                })
                .then(function (data) {
                    var box = document.getElementById('captcha-box');
                    if (box) { box.innerHTML = data.svg; }
                    var input = document.getElementById('captcha');
                    if (input) { input.value = ''; input.focus(); }
                })
                .catch(function () { window.location.reload(); }) /* fallback: full GET re-issues */
                .finally(function () { btn.disabled = false; });
        });
    })();
</script>

</body>
</html>
