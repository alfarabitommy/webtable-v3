<?php
defined('BASEPATH') OR exit('No direct script access allowed');
// plan/106 — Admin Provider E-Wallet: katalog provider yang bisa dipilih member
// saat mengikat akun penarikan. Semua mutasi POST via form_open (CSRF otomatis);
// TIDAK ADA jalur hapus (keputusan D7 — `bank_name` pada baris historis harus
// selalu bisa di-resolve ke katalog).
//
// Invariant L1: halaman admin TIDAK pernah memuat kamus/i18n_apply → seluruh
// copy di file ini literal Indonesia.
$total        = count($providers);
$active_cnt   = 0;
$inactive_cnt = 0;
$bound_live   = 0;
foreach ($providers as $p) {
    if ((int) $p->is_active === 1) { $active_cnt++; } else { $inactive_cnt++; }
    $bound_live += (int) $p->active_bindings;
}
?>
<?php if ($this->session->flashdata('success')): ?>
    <div class="mb-4 px-4 py-3 rounded-lg t-flash-success text-sm flex items-center gap-2">
        <i class="fas fa-check-circle"></i> <?= $this->session->flashdata('success') ?>
    </div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
    <div class="mb-4 px-4 py-3 rounded-lg t-flash-error text-sm flex items-center gap-2">
        <i class="fas fa-exclamation-circle"></i> <?= $this->session->flashdata('error') ?>
    </div>
<?php endif; ?>

<!-- Header + ringkasan + trigger -->
<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
    <div class="flex items-center gap-2 flex-wrap">
        <span class="px-3 py-1.5 rounded-lg bg-[var(--t-surface-2)] border border-[var(--t-border)] text-xs t-text-2 font-medium">
            <?= $total ?> provider
        </span>
        <span class="px-3 py-1.5 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 text-xs font-medium">
            <?= $active_cnt ?> aktif
        </span>
        <span class="px-3 py-1.5 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20 text-xs font-medium">
            <?= $inactive_cnt ?> nonaktif
        </span>
        <span class="px-3 py-1.5 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20 text-xs font-medium">
            <?= $bound_live ?> akun member terikat
        </span>
    </div>
    <button type="button" onclick="openProviderCreate()"
            class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors flex items-center gap-2 w-fit">
        <i class="fas fa-plus-circle text-xs"></i> Tambah Provider
    </button>
</div>
<p class="text-[11px] text-[var(--t-muted)] -mt-3 mb-4">
    Provider nonaktif tidak muncul di pilihan member saat mengikat akun, tetapi ikatan lama tetap tersimpan.
    Provider tidak pernah dihapus permanen — nonaktifkan saja (label pada riwayat penarikan tetap utuh).
    Minimal satu provider harus tetap aktif.
</p>

<!-- Tabel provider -->
<div class="t-card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-[var(--t-border)] bg-[var(--t-surface-2)]">
                    <th class="text-left px-4 py-3 t-th">ID</th>
                    <th class="text-left px-4 py-3 t-th">Kode</th>
                    <th class="text-left px-4 py-3 t-th">Nama Provider</th>
                    <th class="text-left px-4 py-3 t-th">Akun Terikat</th>
                    <th class="text-left px-4 py-3 t-th">Status</th>
                    <th class="text-left px-4 py-3 t-th">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--t-border)]">
                <?php if (empty($providers)): ?>
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center t-muted text-sm">
                            Belum ada provider e-wallet. Tambahkan minimal satu agar member bisa mengikat akun.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($providers as $p): ?>
                        <?php $is_on = ((int) $p->is_active === 1); ?>
                        <tr class="t-row-hover transition-colors">
                            <td class="px-4 py-3.5 t-muted font-mono text-xs">#<?= (int) $p->id ?></td>
                            <td class="px-4 py-3.5">
                                <span class="font-mono text-xs px-2 py-1 rounded bg-[var(--t-surface-2)] border border-[var(--t-border)] t-text-2">
                                    <?= htmlspecialchars((string) $p->code, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="px-4 py-3.5">
                                <span class="font-medium text-[var(--t-text)]"><?= htmlspecialchars((string) $p->name, ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td class="px-4 py-3.5 t-text-2 text-xs">
                                <?= (int) $p->active_bindings ?> aktif
                                <span class="t-muted">/ <?= (int) $p->total_bindings ?> total</span>
                            </td>
                            <td class="px-4 py-3.5">
                                <?php if ($is_on): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                                        <i class="fas fa-circle text-[6px]"></i> Aktif
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-medium bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20">
                                        <i class="fas fa-circle text-[6px]"></i> Nonaktif
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <button type="button"
                                            data-provider='<?= htmlspecialchars(json_encode([
                                                'id'   => (int) $p->id,
                                                'code' => (string) $p->code,
                                                'name' => (string) $p->name,
                                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'
                                            onclick="openProviderRename(this)"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 text-xs font-medium hover:bg-indigo-500/20 transition-colors">
                                        <i class="fas fa-pen text-[10px]"></i> Ubah Nama
                                    </button>
                                    <?= form_open('admin/ewallet-providers/toggle_status/' . (int) $p->id, ['onsubmit' => "return confirm('" . ($is_on
                                            ? 'Nonaktifkan provider "' . htmlspecialchars((string) $p->name, ENT_QUOTES) . '"? Provider langsung hilang dari pilihan member; ikatan lama diblokir dari penarikan sampai diaktifkan kembali.'
                                            : 'Aktifkan provider "' . htmlspecialchars((string) $p->name, ENT_QUOTES) . '"?') . "')", 'class' => 'inline']) ?>
                                        <button type="submit"
                                                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors
                                                       <?= $is_on ? 'bg-amber-600 text-white hover:bg-amber-700' : 'bg-emerald-600 text-white hover:bg-emerald-700' ?>">
                                            <i class="fas <?= $is_on ? 'fa-eye-slash' : 'fa-eye' ?> text-[10px]"></i>
                                            <?= $is_on ? 'Nonaktifkan' : 'Aktifkan' ?>
                                        </button>
                                    <?= form_close() ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: Tambah / Ubah Nama Provider E-Wallet (vanilla JS)      -->
<!-- ============================================================ -->
<div id="providerModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center">
    <div class="absolute inset-0 t-modal-backdrop" onclick="closeProviderModal()"></div>
    <div class="relative t-modal shadow-2xl w-full max-w-lg mx-4 p-6 max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h4 class="text-base font-bold text-[var(--t-text)] flex items-center gap-2">
                <i class="fas fa-wallet text-emerald-500"></i>
                <span id="providerModalTitle">Tambah Provider E-Wallet</span>
            </h4>
            <button type="button" onclick="closeProviderModal()"
                    class="text-[var(--t-muted)] hover:text-[var(--t-text-2)] transition-colors">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>

        <?php
        // form_open() menyisipkan token CSRF otomatis; JS hanya mengganti
        // action/title/value — token TIDAK pernah dihapus atau ditulis ulang.
        ?>
        <?= form_open('admin/ewallet-providers/create', ['id' => 'providerModalForm', 'class' => 'space-y-4']) ?>

            <div>
                <label class="t-label text-xs mb-1">Kode Provider</label>
                <input type="text" name="code" id="p_code" required maxlength="50"
                       pattern="[A-Za-z0-9_]{2,50}" autocomplete="off"
                       placeholder="Contoh: DANA"
                       class="t-input w-full px-3 py-2 rounded-lg text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                <p class="text-[11px] text-[var(--t-muted)] mt-1">
                    Identitas stabil untuk audit &amp; migrasi (huruf kapital/angka, maks 50). Tidak bisa diubah setelah dibuat.
                </p>
                <p id="p_code_hint" class="hidden text-[11px] text-amber-600 dark:text-amber-400 mt-1">
                    Kode tidak dapat diubah — hanya nama tampilan yang bisa diperbarui.
                </p>
            </div>

            <div>
                <label class="t-label text-xs mb-1">Nama Provider (label ke member)</label>
                <input type="text" name="name" id="p_name" required maxlength="100"
                       placeholder="Contoh: DANA"
                       class="t-input w-full px-3 py-2 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                <p id="p_name_hint" class="hidden text-[11px] text-[var(--t-muted)] mt-1">
                    Mengubah nama akan memperbarui label pada akun member yang sudah terikat.
                </p>
            </div>

            <div id="p_active_wrap">
                <label class="t-label text-xs mb-1">Status</label>
                <select name="is_active" id="p_active"
                        class="t-select t-input w-full px-3 py-2 rounded-lg text-sm">
                    <option value="1">Aktif — muncul di pilihan member</option>
                    <option value="0">Nonaktif — disembunyikan dari member</option>
                </select>
            </div>

            <div class="flex items-center gap-3 pt-2 border-t border-[var(--t-border)]">
                <button type="submit" id="providerSubmitBtn"
                        class="px-5 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 active:bg-emerald-800 transition-colors flex items-center gap-2">
                    <i class="fas fa-plus-circle text-xs"></i> <span id="providerSubmitLabel">Simpan Provider</span>
                </button>
                <button type="button" onclick="closeProviderModal()"
                        class="px-4 py-2 rounded-lg t-btn-ghost text-sm border border-[var(--t-border)]">
                    Batal
                </button>
            </div>
        <?= form_close() ?>
    </div>
</div>

<script>
(function () {
    var modal  = document.getElementById('providerModal');
    var form   = document.getElementById('providerModalForm');
    var csrfInput = form ? form.querySelector('input[type="hidden"][name="synapse_csrf_token"]') : null;
    var createUrl  = '<?= site_url('admin/ewallet-providers/create') ?>';
    var updateBase = '<?= site_url('admin/ewallet-providers/update') ?>/';

    function resetForm() {
        var csrfValue = (csrfInput && csrfInput.value !== '') ? csrfInput.value : null;
        form.reset();
        if (csrfValue !== null && csrfInput && csrfInput.value === '') {
            csrfInput.value = csrfValue;
        }
        form.action = createUrl;
        document.getElementById('providerModalTitle').textContent  = 'Tambah Provider E-Wallet';
        document.getElementById('providerSubmitLabel').textContent = 'Simpan Provider';
        document.getElementById('p_code').readOnly = false;
        document.getElementById('p_name').placeholder = 'Contoh: DANA';
        document.getElementById('p_code_hint').classList.add('hidden');
        document.getElementById('p_name_hint').classList.add('hidden');
        document.getElementById('p_active_wrap').classList.remove('hidden');
    }

    window.openProviderCreate = function () {
        resetForm();
        modal.classList.remove('hidden');
        document.getElementById('p_code').focus();
    };

    window.openProviderRename = function (btn) {
        var data = {};
        try { data = JSON.parse(btn.getAttribute('data-provider') || '{}'); } catch (e) { data = {}; }

        resetForm();
        form.action = updateBase + data.id;
        document.getElementById('p_code').value = data.code || '';
        document.getElementById('p_code').readOnly = true;
        document.getElementById('p_name').value = data.name || '';
        document.getElementById('p_name').placeholder = data.name || '';
        document.getElementById('providerModalTitle').textContent  = 'Ubah Nama Provider';
        document.getElementById('providerSubmitLabel').textContent = 'Simpan Nama';
        document.getElementById('p_code_hint').classList.remove('hidden');
        document.getElementById('p_name_hint').classList.remove('hidden');
        // Rename hanya mengubah `name` — status diatur lewat tombol toggle baris.
        document.getElementById('p_active_wrap').classList.add('hidden');
        modal.classList.remove('hidden');
        document.getElementById('p_name').focus();
    };

    window.closeProviderModal = function () {
        modal.classList.add('hidden');
    };

    // Kode otomatis uppercase saat diketik (parity normalisasi server-side).
    var codeInput = document.getElementById('p_code');
    if (codeInput) {
        codeInput.addEventListener('input', function () {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9_]/g, '');
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) { closeProviderModal(); }
    });
})();
</script>
