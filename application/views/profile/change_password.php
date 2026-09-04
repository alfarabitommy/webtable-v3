<?php
defined('BASEPATH') OR exit('No direct script access allowed');
?>

<div class="p-4 space-y-6 pb-24">
    <!-- Back header -->
    <div class="flex items-center gap-3 mb-6">
        <a href="<?= base_url('profile'); ?>" class="w-8 h-8 u-btn-ghost rounded-full flex items-center justify-center shadow-sm active:scale-90 transition-all">
            <i class="fas fa-arrow-left text-xs"></i>
        </a>
        <h2 class="text-xl font-extrabold u-text tracking-tight"><?= $page_title ?></h2>
    </div>

    <!-- Flash: success / error -->
    <?php if ($this->session->flashdata('success')): ?>
        <div class="u-flash-success px-4 py-3 rounded-xl text-xs font-bold flex items-center gap-3 shadow-sm">
            <i class="fas fa-check-circle"></i><?= $this->session->flashdata('success'); ?>
        </div>
    <?php endif; ?>
    <?php if ($this->session->flashdata('error')): ?>
        <div class="u-flash-error px-4 py-3 rounded-xl text-xs font-bold flex items-center gap-3 shadow-sm">
            <i class="fas fa-exclamation-circle"></i><?= $this->session->flashdata('error'); ?>
        </div>
    <?php endif; ?>

    <!-- General errors (DB-level failures) -->
    <?php if (!empty($errors)): foreach ($errors as $e): ?>
        <div class="u-flash-error px-4 py-3 rounded-xl text-xs font-bold"><?= $e ?></div>
    <?php endforeach; endif; ?>

    <!-- Form -->
    <div class="u-card rounded-2xl p-5 shadow-sm space-y-5">
        <div class="text-center">
            <div class="w-14 h-14 rounded-full bg-amber-50 dark:bg-amber-500/10 flex items-center justify-center mx-auto mb-3">
                <i class="fas fa-lock text-xl text-amber-400 dark:text-amber-300"></i>
            </div>
            <p class="text-xs u-muted">Gunakan minimal 8 karakter untuk kata sandi baru Anda.</p>
        </div>

        <?= form_open('profile/change-password', ['class' => 'space-y-4']) ?>

            <div>
                <label for="current_password" class="block text-xs font-bold u-text-2 mb-1.5 uppercase tracking-wide">Kata Sandi Saat Ini</label>
                <input type="password" id="current_password" name="current_password" required
                       autocomplete="current-password" placeholder="Masukkan kata sandi lama"
                       class="u-input w-full rounded-xl py-3 px-4 text-sm font-medium focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <?= form_error('current_password', '<p class="mt-1.5 text-xs text-rose-500 font-semibold">', '</p>') ?>
            </div>

            <div>
                <label for="new_password" class="block text-xs font-bold u-text-2 mb-1.5 uppercase tracking-wide">Kata Sandi Baru</label>
                <input type="password" id="new_password" name="new_password" required minlength="8"
                       autocomplete="new-password" placeholder="Minimal 8 karakter"
                       class="u-input w-full rounded-xl py-3 px-4 text-sm font-medium focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <?= form_error('new_password', '<p class="mt-1.5 text-xs text-rose-500 font-semibold">', '</p>') ?>
            </div>

            <div>
                <label for="confirm_password" class="block text-xs font-bold u-text-2 mb-1.5 uppercase tracking-wide">Konfirmasi Kata Sandi Baru</label>
                <input type="password" id="confirm_password" name="confirm_password" required
                       autocomplete="new-password" placeholder="Ulangi kata sandi baru"
                       class="u-input w-full rounded-xl py-3 px-4 text-sm font-medium focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <?= form_error('confirm_password', '<p class="mt-1.5 text-xs text-rose-500 font-semibold">', '</p>') ?>
            </div>

            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold py-3.5 rounded-xl transition flex items-center justify-center gap-2">
                <i class="fas fa-check"></i> Simpan Kata Sandi
            </button>

        <?= form_close() ?>
    </div>
</div>
