<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// plan/106 — Binding akun e-wallet (eksklusif e-wallet).
//
//   State A (belum terikat) : card selector 2x2 dari `ewallet_providers` AKTIF
//                             (murni CSS `peer-checked:` — tanpa JS) + input
//                             nomor HP e-wallet + nama pemilik akun.
//   State B (terikat)       : kartu read-only (provider, nomor ter-mask, nama)
//                             + penjelasan bahwa reset hanya via Customer Service.
//
// Aturan nomor HP: numerik, diawali 08, 10–13 digit (helper ewallet_helper.php
// adalah sumber tunggal; `pattern` di bawah hanya parity sisi klien).
// Semua teks user-facing lewat kamus (plan/103) — nol literal di file ini.
$provider_inactive = ($existing_ewallet && (!$ewallet_provider || (int) $ewallet_provider->is_active !== 1));
?>
<div class="p-4 space-y-6">
    <!-- Header -->
    <div class="flex items-center gap-3 mb-6">
        <a href="<?= base_url('wallet'); ?>" class="w-8 h-8 u-btn-ghost rounded-full flex items-center justify-center shadow-sm active:scale-90 transition-all">
            <i class="fas fa-arrow-left text-xs"></i>
        </a>
        <h2 class="text-xl font-extrabold u-text tracking-tight"><?= $page_title ?></h2>
    </div>

    <!-- Flash Messages -->
    <?php if ($this->session->flashdata('success')): ?>
        <div class="u-flash-success px-4 py-3 rounded-xl text-xs font-bold flex items-center gap-3 shadow-sm">
            <i class="fas fa-check-circle"></i>
            <?= $this->session->flashdata('success'); ?>
        </div>
    <?php endif; ?>

    <?php if ($this->session->flashdata('error')): ?>
        <div class="u-flash-error px-4 py-3 rounded-xl text-xs font-bold flex items-center gap-3 shadow-sm">
            <i class="fas fa-exclamation-circle"></i>
            <?= $this->session->flashdata('error'); ?>
        </div>
    <?php endif; ?>

    <?php if ($existing_ewallet): ?>
    <!-- ===== STATE B: E-WALLET TERIKAT (READ-ONLY CARD) ===== -->
    <div class="u-card-fin text-white p-6 rounded-2xl shadow-xl relative overflow-hidden">
        <div class="absolute inset-0 opacity-5" style="background-image: repeating-linear-gradient(45deg, #fff 0, #fff 1px, transparent 1px, transparent 20px);"></div>
        <div class="relative z-10 space-y-6">
            <div class="flex items-center justify-between">
                <span class="text-slate-400 text-xs uppercase tracking-wider font-bold"><?= lang('bb_linked_label') ?></span>
                <span class="bg-emerald-500/20 text-emerald-400 text-[10px] font-bold px-2 py-0.5 rounded-full border border-emerald-500/30"><?= lang('wd_verified') ?></span>
            </div>

            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center shrink-0">
                    <i class="fas fa-wallet text-sm"></i>
                </div>
                <div class="text-2xl font-extrabold tracking-tight"><?= htmlspecialchars($existing_ewallet->bank_name); ?></div>
            </div>

            <div>
                <span class="text-slate-400 text-[10px] uppercase tracking-widest font-bold block mb-1"><?= lang('bb_phone_label') ?></span>
                <div class="text-xl font-mono font-bold tracking-widest">
                    <?= htmlspecialchars(ewallet_phone_mask($existing_ewallet->account_number)); ?>
                </div>
            </div>

            <div class="flex justify-between items-end border-t border-slate-800 pt-4">
                <div>
                    <span class="text-slate-400 text-[10px] uppercase tracking-widest font-bold block mb-0.5"><?= lang('bb_account_holder_label') ?></span>
                    <div class="text-sm font-bold"><?= htmlspecialchars($existing_ewallet->account_holder); ?></div>
                </div>
                <div class="text-right">
                    <span class="text-slate-500 text-[10px] font-mono"><?= lang('bb_bound_status') ?></span>
                </div>
            </div>
        </div>
    </div>

    <?php if ($provider_inactive): ?>
    <!-- Provider nonaktif: penarikan diblokir sampai provider aktif kembali
         atau admin mereset ikatan (keputusan D5). -->
    <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl p-4 flex gap-3 items-start">
        <div class="bg-amber-100 dark:bg-amber-500/10 w-8 h-8 rounded-full flex items-center justify-center shrink-0 mt-0.5">
            <i class="fas fa-triangle-exclamation text-amber-600 dark:text-amber-400 text-xs"></i>
        </div>
        <div>
            <p class="text-amber-700 dark:text-amber-300 text-[11px] leading-relaxed"><?= lang('bb_provider_inactive_notice') ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Security Warning -->
    <div class="bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/20 rounded-xl p-4 flex gap-3 items-start">
        <div class="bg-rose-100 dark:bg-rose-500/10 w-8 h-8 rounded-full flex items-center justify-center shrink-0 mt-0.5">
            <i class="fas fa-lock text-rose-500 dark:text-rose-400 text-xs"></i>
        </div>
        <div>
            <h4 class="text-rose-700 dark:text-rose-300 text-xs font-extrabold mb-1"><?= lang('bb_security_title') ?></h4>
            <p class="text-rose-600 dark:text-rose-400 text-[11px] leading-relaxed"><?= lang('bb_security_body') ?></p>
        </div>
    </div>

    <?php elseif (empty($providers)): ?>
    <!-- ===== STATE A0: TIDAK ADA PROVIDER AKTIF (FAIL-CLOSED) =====
         Tanpa provider aktif tidak ada tujuan penarikan yang sah — form dan
         tombol submit sengaja TIDAK dirender. -->
    <div class="u-card rounded-2xl p-6 shadow-sm text-center space-y-3">
        <div class="w-12 h-12 bg-amber-50 dark:bg-amber-500/10 rounded-2xl flex items-center justify-center mx-auto">
            <i class="fas fa-wallet text-amber-500 text-base"></i>
        </div>
        <p class="text-xs u-text-2 leading-relaxed"><?= lang('bb_no_provider_available') ?></p>
    </div>

    <?php else: ?>
    <!-- ===== STATE A: BELUM TERIKAT (BIND FORM) ===== -->
    <div class="u-card rounded-2xl p-6 shadow-sm">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 bg-indigo-50 dark:bg-indigo-500/10 rounded-xl flex items-center justify-center">
                <i class="fas fa-wallet text-indigo-500 text-sm"></i>
            </div>
            <div>
                <h3 class="text-sm font-extrabold u-text"><?= lang('bb_bind_title') ?></h3>
                <p class="text-[10px] u-text-2"><?= lang('bb_bind_sub') ?></p>
            </div>
        </div>

        <?= form_open('wallet/bind_bank', ['class' => 'space-y-4']); ?>

            <!-- Provider e-wallet: card selector 2x2 (katalog aktif dari DB) -->
            <fieldset>
                <legend class="text-[10px] uppercase tracking-widest u-muted font-bold block mb-1.5"><?= lang('bb_provider_label') ?></legend>
                <p class="text-[10px] u-text-2 mb-2.5"><?= lang('bb_choose_provider') ?></p>

                <div class="grid grid-cols-2 gap-3">
                    <?php foreach ($providers as $p): ?>
                        <?php $pid = (string) (int) $p->id; ?>
                        <label class="relative block cursor-pointer">
                            <input type="radio" name="provider_id" value="<?= $pid ?>"
                                   class="peer sr-only" required
                                   <?= set_radio('provider_id', $pid); ?>>
                            <div class="u-card rounded-xl px-3 py-4 h-full flex flex-col items-center justify-center gap-2 text-center border border-slate-200 dark:border-slate-700
                                        transition-all peer-checked:border-indigo-500 peer-checked:bg-indigo-50 dark:peer-checked:bg-indigo-500/10 peer-checked:ring-2 peer-checked:ring-indigo-500/20
                                        peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-500/40">
                                <i class="fas fa-wallet text-indigo-500 text-base"></i>
                                <span class="text-xs font-extrabold u-text leading-tight break-words"><?= htmlspecialchars($p->name); ?></span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <!-- Nomor HP e-wallet -->
            <div>
                <label class="text-[10px] uppercase tracking-widest u-muted font-bold block mb-1.5"><?= lang('bb_phone_label') ?></label>
                <input type="text" name="ewallet_phone" value="<?= set_value('ewallet_phone'); ?>"
                       placeholder="<?= lang('bb_phone_placeholder') ?>"
                       class="u-input w-full h-12 px-4 rounded-xl text-sm font-mono tracking-wider focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all"
                       required inputmode="numeric" maxlength="13" pattern="08[0-9]{8,11}" autocomplete="off">
                <p class="text-[10px] u-text-2 mt-1.5"><?= lang('bb_phone_hint') ?></p>
                <?= form_error('ewallet_phone', '<p class="text-xs text-rose-500 mt-1">', '</p>'); ?>
            </div>

            <!-- Nama pemilik akun -->
            <div>
                <label class="text-[10px] uppercase tracking-widest u-muted font-bold block mb-1.5"><?= lang('bb_holder_label') ?></label>
                <input type="text" name="account_holder" value="<?= set_value('account_holder'); ?>"
                       placeholder="<?= lang('bb_holder_placeholder') ?>" maxlength="100"
                       class="u-input w-full h-12 px-4 rounded-xl text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all capitalize" required>
                <?= form_error('account_holder', '<p class="text-xs text-rose-500 mt-1">', '</p>'); ?>
            </div>

            <!-- Security Notice -->
            <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl p-3 flex gap-3 items-start mt-4">
                <i class="fas fa-exclamation-triangle text-amber-500 dark:text-amber-400 text-xs mt-0.5"></i>
                <p class="text-amber-700 dark:text-amber-300 text-[11px] leading-relaxed"><?= lang('bb_notice') ?></p>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="u-btn-dark w-full h-14 rounded-xl text-sm font-extrabold shadow-lg transition-all active:scale-95 mt-6">
                <i class="fas fa-link mr-2"></i> <?= lang('bb_bind_btn') ?>
            </button>

        <?= form_close(); ?>
    </div>
    <?php endif; ?>
</div>
