<!-- Admin Settings View — Phase 8B -->
<!-- Theme: Bloomberg Terminal / Clean Admin (white bg, slate borders, indigo accents) -->

<?php if ($this->session->flashdata('success')): ?>
    <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm flex items-center gap-2">
        <i class="fas fa-check-circle"></i>
        <?= $this->session->flashdata('success') ?>
    </div>
<?php endif; ?>

<?php if ($this->session->flashdata('error')): ?>
    <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm flex items-center gap-2">
        <i class="fas fa-exclamation-circle"></i>
        <?= $this->session->flashdata('error') ?>
    </div>
<?php endif; ?>

<div class="max-w-lg">
    <!-- Header -->
    <div class="mb-6">
        <h3 class="text-lg font-semibold text-slate-900">Pengaturan Umum</h3>
        <p class="text-sm text-slate-500 mt-1">Kelola informasi kontak support yang ditampilkan di halaman Bantuan.</p>
    </div>

    <!-- Settings Form -->
    <?= form_open('admin/settings', 'class="space-y-6"') ?>

        <!-- WhatsApp Number -->
        <div>
            <label for="wa_number" class="block text-sm font-medium text-slate-700 mb-1.5">
                <i class="fab fa-whatsapp text-emerald-500 mr-1"></i>
                Nomor WhatsApp CS
            </label>
            <input type="text"
                   id="wa_number"
                   name="wa_number"
                   value="<?= set_value('wa_number', $wa_number) ?>"
                   pattern="[0-9]*"
                   inputmode="numeric"
                   required
                   placeholder="628xxxxxxxxxx"
                   class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm text-slate-900
                          focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500
                          placeholder:text-slate-400 font-mono">
            <p class="text-xs text-slate-400 mt-1">Format: kode negara + nomor. Contoh: 628123456789</p>
        </div>

        <!-- Support Email -->
        <div>
            <label for="support_email" class="block text-sm font-medium text-slate-700 mb-1.5">
                <i class="fas fa-envelope text-blue-500 mr-1"></i>
                Email Support
            </label>
            <input type="email"
                   id="support_email"
                   name="support_email"
                   value="<?= set_value('support_email', $support_email) ?>"
                   required
                   placeholder="support@synapse.id"
                   class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm text-slate-900
                          focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500
                          placeholder:text-slate-400">
        </div>

        <!-- Submit -->
        <div class="pt-2">
            <button type="submit"
                    class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white text-sm font-medium
                           hover:bg-indigo-700 active:bg-indigo-800 transition-colors
                           flex items-center gap-2">
                <i class="fas fa-save text-xs"></i>
                Simpan Pengaturan
            </button>
        </div>

    <?= form_close() ?>
</div>
