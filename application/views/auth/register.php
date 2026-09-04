<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Daftar · Synapse</title>

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
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
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
        <p class="relative z-10 text-white/90 text-sm font-medium text-center px-6 leading-relaxed">Bergabung dengan Jaringan Global</p>
    </div>

    <!-- ═══ BOTTOM: Form Card (Phase 32: theme-aware surface) ═══ -->
    <div class="u-surface-bg flex-1 rounded-t-[2.5rem] w-full relative z-20 shadow-[0_-15px_40px_rgba(0,0,0,0.2)] px-6 py-8 flex flex-col -mt-4">

        <!-- Validation Errors -->
        <?php if (validation_errors()): ?>
            <div class="u-flash-error px-4 py-3 rounded-xl text-sm mb-4">
                <?= validation_errors() ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-4 space-y-1">
                <?php foreach ($errors as $e): ?>
                    <div class="u-flash-error px-4 py-3 rounded-xl text-sm"><?= $e ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Register Form -->
        <h2 class="text-xl font-bold u-text mb-1">Daftar Akun</h2>
        <p class="text-sm u-text-2 mb-6">Bergabunglah bersama ribuan investor Synapse.</p>

        <?= form_open('register', ['class' => 'space-y-4']) ?>

            <div>
                <label for="invite_code" class="text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5 block">Kode Undangan <span class="text-rose-500">*</span></label>
                <input type="text" id="invite_code" name="invite_code" value="<?= set_value('invite_code') ?>"
                       class="u-input h-14 px-5 rounded-2xl text-sm focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all w-full uppercase"
                       placeholder="Contoh: ABC123" maxlength="6">
                <?= form_error('invite_code', '<p class="text-xs text-rose-500 mt-1.5">', '</p>') ?>
                <p class="text-xs u-muted mt-1.5">Kode undangan dari agen yang mengajak Anda.</p>
            </div>

            <div>
                <label for="phone" class="text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5 block">Nomor Telepon <span class="text-rose-500">*</span></label>
                <input type="text" id="phone" name="phone" value="<?= set_value('phone') ?>"
                       class="u-input h-14 px-5 rounded-2xl text-sm focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all w-full"
                       placeholder="081234567890">
                <?= form_error('phone', '<p class="text-xs text-rose-500 mt-1.5">', '</p>') ?>
            </div>

            <div>
                <label for="password" class="text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5 block">Kata Sandi <span class="text-rose-500">*</span></label>
                <input type="password" id="password" name="password"
                       class="u-input h-14 px-5 rounded-2xl text-sm focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all w-full"
                       placeholder="Minimal 8 karakter">
                <?= form_error('password', '<p class="text-xs text-rose-500 mt-1.5">', '</p>') ?>
            </div>

            <!-- Google reCAPTCHA v2 -->
            <div class="flex justify-center my-2 rounded-xl overflow-hidden">
                <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($recaptcha_site_key ?? '') ?>"></div>
            </div>

            <button type="submit" class="u-btn-dark h-14 w-full rounded-2xl shadow-lg flex items-center justify-center mt-2">
                <span>Daftar</span>
            </button>

        <?= form_close() ?>

        <p class="u-text-2 text-sm text-center mt-6">
            Sudah punya akun?
            <a href="<?= site_url('login') ?>" class="text-blue-600 dark:text-blue-400 font-semibold hover:underline">Masuk</a>
        </p>
    </div>

</div>

</body>
</html>
