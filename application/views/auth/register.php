<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Daftar · Synapse</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
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

    <!-- ═══ BOTTOM: Form Card ═══ -->
    <div class="bg-white flex-1 rounded-t-[2.5rem] w-full relative z-20 shadow-[0_-15px_40px_rgba(0,0,0,0.2)] px-6 py-8 flex flex-col -mt-4">

        <!-- Validation Errors -->
        <?php if (validation_errors()): ?>
            <div class="px-4 py-3 bg-rose-50 border border-rose-200 rounded-xl text-sm text-rose-600 mb-4">
                <?= validation_errors() ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-4 space-y-1">
                <?php foreach ($errors as $e): ?>
                    <div class="px-4 py-3 bg-rose-50 border border-rose-200 rounded-xl text-sm text-rose-600"><?= $e ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Register Form -->
        <h2 class="text-xl font-bold text-slate-900 mb-1">Daftar Akun</h2>
        <p class="text-sm text-slate-500 mb-6">Bergabunglah bersama ribuan investor Synapse.</p>

        <?= form_open('register', ['class' => 'space-y-4']) ?>

            <div>
                <label for="invite_code" class="text-xs font-semibold text-slate-700 mb-1.5 block">Kode Undangan <span class="text-rose-500">*</span></label>
                <input type="text" id="invite_code" name="invite_code" value="<?= set_value('invite_code') ?>"
                       class="h-14 px-5 rounded-2xl border border-slate-200 bg-slate-50 text-slate-800 text-sm focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all w-full uppercase"
                       placeholder="Contoh: ABC123" maxlength="6">
                <?= form_error('invite_code', '<p class="text-xs text-rose-500 mt-1.5">', '</p>') ?>
                <p class="text-xs text-slate-400 mt-1.5">Kode undangan dari agen yang mengajak Anda.</p>
            </div>

            <div>
                <label for="phone" class="text-xs font-semibold text-slate-700 mb-1.5 block">Nomor Telepon <span class="text-rose-500">*</span></label>
                <input type="text" id="phone" name="phone" value="<?= set_value('phone') ?>"
                       class="h-14 px-5 rounded-2xl border border-slate-200 bg-slate-50 text-slate-800 text-sm focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all w-full"
                       placeholder="081234567890" maxlength="12">
                <?= form_error('phone', '<p class="text-xs text-rose-500 mt-1.5">', '</p>') ?>
            </div>

            <div>
                <label for="password" class="text-xs font-semibold text-slate-700 mb-1.5 block">Kata Sandi <span class="text-rose-500">*</span></label>
                <input type="password" id="password" name="password"
                       class="h-14 px-5 rounded-2xl border border-slate-200 bg-slate-50 text-slate-800 text-sm focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all w-full"
                       placeholder="Minimal 8 karakter">
                <?= form_error('password', '<p class="text-xs text-rose-500 mt-1.5">', '</p>') ?>
            </div>

            <!-- Google reCAPTCHA v2 -->
            <div class="flex justify-center my-2">
                <div class="g-recaptcha" data-sitekey="6Le3PSgtAAAAAFHpzlaZX-h70_zV1fIyKXR00THy"></div>
            </div>

            <button type="submit" class="h-14 w-full bg-slate-900 hover:bg-blue-600 text-white font-semibold rounded-2xl shadow-lg transition-colors flex items-center justify-center mt-2">
                <span>Daftar</span>
            </button>

        <?= form_close() ?>

        <p class="text-slate-500 text-sm text-center mt-6">
            Sudah punya akun?
            <a href="<?= site_url('login') ?>" class="text-blue-600 font-semibold hover:underline">Masuk</a>
        </p>
    </div>

</div>

</body>
</html>
