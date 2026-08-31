<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Ubah Kata Sandi · Synapse</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 flex justify-center min-h-screen font-sans antialiased">

<div class="w-full max-w-[480px] min-h-screen mx-auto relative bg-slate-900 overflow-hidden flex flex-col">

    <!-- ═══ TOP: Branding & Background ═══ -->
    <div class="h-[35vh] w-full relative flex flex-col items-center justify-center shrink-0">
        <img src="https://placehold.co/480x400/1e293b/334155?text=Tech+Background" class="absolute inset-0 w-full h-full object-cover opacity-40 mix-blend-overlay" alt="Background">
        <div class="absolute inset-0 bg-gradient-to-b from-slate-900/60 via-slate-900/40 to-slate-900/90"></div>

        <img src="https://placehold.co/160x50/ffffff/0f172a?text=SYNAPSE+LOGO" class="relative z-10 mb-4 rounded-lg shadow-lg" alt="Synapse Logo">
        <p class="relative z-10 text-white/90 text-sm font-medium text-center px-6 leading-relaxed">Keamanan Ekosistem AI</p>
    </div>

    <!-- ═══ BOTTOM: Form Card ═══ -->
    <div class="bg-white flex-1 rounded-t-[2.5rem] w-full relative z-20 shadow-[0_-15px_40px_rgba(0,0,0,0.2)] px-6 py-8 flex flex-col -mt-4">

        <!-- Flashdata Success -->
        <?php if ($this->session->flashdata('success')): ?>
            <div class="px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm text-emerald-700 mb-4">
                <?= $this->session->flashdata('success') ?>
            </div>
        <?php endif; ?>

        <!-- Flashdata Error -->
        <?php if ($this->session->flashdata('error')): ?>
            <div class="px-4 py-3 bg-rose-50 border border-rose-200 rounded-xl text-sm text-rose-600 mb-4">
                <?= $this->session->flashdata('error') ?>
            </div>
        <?php endif; ?>

        <!-- Validation / Auth Errors -->
        <?php if (!empty($errors)): ?>
            <div class="mb-4 space-y-1">
                <?php foreach ($errors as $e): ?>
                    <div class="px-4 py-3 bg-rose-50 border border-rose-200 rounded-xl text-sm text-rose-600"><?= $e ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Change Password Form -->
        <h2 class="text-xl font-bold text-slate-900 mb-1">Ubah Kata Sandi</h2>
        <p class="text-sm text-slate-500 mb-6">Anda wajib mengganti kata sandi sebelum melanjutkan. Gunakan minimal 8 karakter.</p>

        <?= form_open('auth/change-password', ['class' => 'space-y-4']) ?>

            <div>
                <label for="new_password" class="text-xs font-semibold text-slate-700 mb-1.5 block">Kata Sandi Baru</label>
                <input type="password" id="new_password" name="new_password"
                       class="h-14 px-5 rounded-2xl border border-slate-200 bg-slate-50 text-slate-800 text-sm focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all w-full"
                       placeholder="Minimal 8 karakter" autocomplete="new-password">
                <?= form_error('new_password', '<p class="text-xs text-rose-500 mt-1.5">', '</p>') ?>
            </div>

            <div>
                <label for="confirm_password" class="text-xs font-semibold text-slate-700 mb-1.5 block">Konfirmasi Kata Sandi</label>
                <input type="password" id="confirm_password" name="confirm_password"
                       class="h-14 px-5 rounded-2xl border border-slate-200 bg-slate-50 text-slate-800 text-sm focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all w-full"
                       placeholder="Ulangi kata sandi baru" autocomplete="new-password">
                <?= form_error('confirm_password', '<p class="text-xs text-rose-500 mt-1.5">', '</p>') ?>
            </div>

            <button type="submit" class="h-14 w-full bg-slate-900 hover:bg-blue-600 text-white font-semibold rounded-2xl shadow-lg transition-colors flex items-center justify-center mt-2">
                <span>Simpan Kata Sandi</span>
            </button>

        <?= form_close() ?>

    </div>

</div>

</body>
</html>
