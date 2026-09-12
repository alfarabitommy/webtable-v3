<?php
defined('BASEPATH') OR exit('No direct script access allowed');
// plan/85 — Admin GPU Product Management: daftar paket (aktif & nonaktif) +
// create/edit modal + toggle status inline. Semua mutasi POST via form_open
// (CSRF otomatis); tidak ada path hapus (Zero Hard-Delete — FK RESTRICT).
$total = count($products);
$active_cnt = 0; $inactive_cnt = 0; $live_contracts = 0;
foreach ($products as $p) {
    if ((int) $p->is_active === 1) { $active_cnt++; } else { $inactive_cnt++; }
    $live_contracts += (int) $p->active_cnt;
}
$IDR = static function ($v) { return 'Rp ' . number_format((int) $v, 0, ',', '.'); };
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

<!-- Header + summary + trigger -->
<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
    <div class="flex items-center gap-2 flex-wrap">
        <span class="px-3 py-1.5 rounded-lg bg-[var(--t-surface-2)] border border-[var(--t-border)] text-xs t-text-2 font-medium">
            <?= $total ?> paket
        </span>
        <span class="px-3 py-1.5 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 text-xs font-medium">
            <?= $active_cnt ?> aktif
        </span>
        <span class="px-3 py-1.5 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20 text-xs font-medium">
            <?= $inactive_cnt ?> nonaktif
        </span>
        <span class="px-3 py-1.5 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20 text-xs font-medium">
            <?= $live_contracts ?> kontrak berjalan
        </span>
    </div>
    <button type="button" onclick="openProductCreate()"
            class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors flex items-center gap-2 w-fit">
        <i class="fas fa-plus-circle text-xs"></i> Tambah Paket GPU
    </button>
</div>
<p class="text-[11px] text-[var(--t-muted)] -mt-3 mb-4">
    Paket tidak pernah dihapus permanen (riwayat sewa terkunci via FK).
    Nonaktifkan saja via toggle — paket langsung hilang dari marketplace user
    (plan/87: tanpa rantai prasyarat; ketersediaan 100% via toggle status).
</p>

<!-- Product table -->
<div class="t-card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-[var(--t-border)] bg-[var(--t-surface-2)]">
                    <th class="text-left px-4 py-3 t-th">ID</th>
                    <th class="text-left px-4 py-3 t-th">Gambar</th>
                    <th class="text-left px-4 py-3 t-th">Nama &amp; Type</th>
                    <th class="text-left px-4 py-3 t-th">Harga Sewa</th>
                    <th class="text-left px-4 py-3 t-th">ROI Harian</th>
                    <th class="text-left px-4 py-3 t-th">Durasi</th>
                    <th class="text-left px-4 py-3 t-th">Kuota Sewa</th>
                    <th class="text-left px-4 py-3 t-th">Status</th>
                    <th class="text-left px-4 py-3 t-th">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--t-border)]">
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="9" class="px-4 py-12 text-center text-[var(--t-muted)]">
                            <i class="fas fa-microchip text-3xl mb-3 block opacity-60"></i>
                            Belum ada paket GPU. Tambahkan paket pertama.
                            <div class="mt-4">
                                <button type="button" onclick="openProductCreate()"
                                        class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-xs font-medium hover:bg-emerald-700 transition-colors">
                                    <i class="fas fa-plus-circle"></i> Tambah Paket GPU
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                    <?php
                        $is_on = ((int) $p->is_active === 1);
                        $is_limited = ((int) $p->max_per_user > 0);
                        // plan/104: resolusi tunggal via helper — null = belum ada
                        // gambar ATAU berkasnya hilang di disk (fallback mini).
                        $thumb = product_image_url($p->image ?? null);
                    ?>
                    <tr class="t-row-hover transition-colors <?= $is_on ? '' : 'opacity-60' ?>">
                        <td class="px-4 py-3 font-mono text-xs t-text-2"><?= (int) $p->id ?></td>
                        <td class="px-4 py-3">
                            <div class="w-20 aspect-video rounded-lg overflow-hidden bg-slate-900 border border-[var(--t-border)] flex items-center justify-center">
                                <?php if ($thumb !== null): ?>
                                    <img src="<?= $thumb ?>" alt="<?= htmlspecialchars($p->name) ?>"
                                         loading="lazy" decoding="async" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <i class="fas fa-microchip text-[var(--t-muted)]" title="Belum ada gambar"></i>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-semibold text-[var(--t-text)]"><?= htmlspecialchars($p->name) ?></span>
                            <span class="ml-2 inline-block px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide
                                        <?= $p->type === 'short_term'
                                            ? 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400'
                                            : 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400' ?>">
                                <?= $p->type === 'short_term' ? 'short' : 'long' ?> term
                            </span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-emerald-600 dark:text-emerald-400 font-semibold"><?= $IDR($p->price) ?></td>
                        <td class="px-4 py-3 font-mono text-xs t-text-2"><?= $IDR($p->daily_rate) ?></td>
                        <td class="px-4 py-3 text-xs t-text-2"><?= (int) $p->duration_days ?> hari</td>
                        <td class="px-4 py-3">
                            <span class="text-xs font-semibold text-[var(--t-text)]">
                                <?= $is_limited ? 'Maks. ' . (int) $p->max_per_user : 'Tanpa Batas' ?>
                            </span>
                            <span class="block text-[10px] t-muted"><?= (int) $p->total_cnt ?> dipakai (lifetime)</span>
                            <span class="block text-[10px] t-muted"><?= (int) $p->active_cnt ?> kontrak aktif</span>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($is_on): ?>
                                <span class="t-badge t-badge-success"><i class="fas fa-check-circle text-[10px]"></i> AKTIF</span>
                            <?php else: ?>
                                <span class="t-badge t-badge-danger"><i class="fas fa-ban text-[10px]"></i> NONAKTIF</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <button type="button" class="btn-product-edit" data-product='<?= htmlspecialchars(json_encode([
                                        'id'                   => (int) $p->id,
                                        'name'                 => $p->name,
                                        'type'                 => $p->type,
                                        'price'                => (int) $p->price,
                                        'daily_rate'           => (int) $p->daily_rate,
                                        'duration_days'        => (int) $p->duration_days,
                                        'is_refundable'        => (int) $p->is_refundable,
                                        'max_per_user'         => (int) $p->max_per_user,
                                        'is_active'            => (int) $p->is_active,
                                        // plan/104: nama berkas + URL siap-render untuk
                                        // preview modal (URL '' bila fallback).
                                        'image'                => (string) ($p->image ?? ''),
                                        'image_url'            => (string) (product_image_url($p->image ?? null) ?? ''),
                                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'
                                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 text-xs font-medium hover:bg-indigo-500/20 transition-colors">
                                    <i class="fas fa-pen text-[10px]"></i> Edit
                                </button>
                                <?= form_open('admin/products/toggle_status/' . (int) $p->id, ['onsubmit' => "return confirm('" . ($is_on
                                        ? 'Nonaktifkan paket "' . htmlspecialchars($p->name, ENT_QUOTES) . '"? Paket langsung disembunyikan dari marketplace user.'
                                        : 'Aktifkan paket "' . htmlspecialchars($p->name, ENT_QUOTES) . '"?') . "')", 'class' => 'inline']) ?>
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
<!-- MODAL: Create / Edit Paket GPU (unified, vanilla JS)          -->
<!-- ============================================================ -->
<div id="productModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center">
    <div class="absolute inset-0 t-modal-backdrop" onclick="closeProductModal()"></div>
    <div class="relative t-modal shadow-2xl w-full max-w-2xl mx-4 p-6 max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h4 class="text-base font-bold text-[var(--t-text)] flex items-center gap-2">
                <i class="fas fa-microchip text-emerald-500"></i>
                <span id="productModalTitle">Tambah Paket GPU</span>
            </h4>
            <button type="button" onclick="closeProductModal()"
                    class="text-[var(--t-muted)] hover:text-[var(--t-text-2)] transition-colors">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>

        <?php
        // plan/85 fix: WAJIB form_open() (bukan <form> mentah) — helper ini yang
        // menyisipkan <input type="hidden" name="synapse_csrf_token" ...> otomatis
        // (csrf_protection=TRUE). Tanpa input itu, POST /admin/products/create
        // ditolak CI3 dengan HTTP 403 "The action you have requested is not allowed."
        // JS modal hanya mengganti action/title/value — token TIDAK pernah
        // dihapus/di-overwrite (form.reset() hanya memulihkan nilai awal token,
        // yang tetap valid: csrf_regenerate = FALSE per session).
        //
        // plan/104: form_open_MULTIPART — wajib untuk unggah gambar produk.
        // `enctype` tetap utuh walau JS mengganti form.action ke endpoint update.
        // Form ini SENGAJA tidak memakai data-guard-submit: resetForm() memanggil
        // form.reset() yang tidak membersihkan flag data-submitting, sehingga
        // tombol bisa terkunci permanen setelah submit yang gagal.
        ?>
        <?= form_open_multipart('admin/products/create', ['id' => 'productModalForm', 'class' => 'space-y-4']) ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="t-label text-xs mb-1">Nama Paket <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="f_name" required maxlength="100"
                           class="t-input w-full px-3 py-2 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                           placeholder="cth: RTX 5070 Ti Prime">
                </div>

                <div>
                    <label class="t-label text-xs mb-1">Tipe <span class="text-red-500">*</span></label>
                    <select name="type" id="f_type" class="t-select t-input w-full px-3 py-2 rounded-lg text-sm">
                        <option value="short_term">Short Term</option>
                        <option value="long_term">Long Term</option>
                    </select>
                </div>
                <div>
                    <label class="t-label text-xs mb-1">Durasi Kontrak (hari) <span class="text-red-500">*</span></label>
                    <input type="number" name="duration_days" id="f_duration" required min="1" step="1" inputmode="numeric"
                           class="t-input w-full px-3 py-2 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label class="t-label text-xs mb-1">Harga Sewa (IDR) <span class="text-red-500">*</span></label>
                    <input type="number" name="price" id="f_price" required min="1" step="1" inputmode="numeric"
                           class="t-input w-full px-3 py-2 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                           placeholder="cth: 150000">
                </div>
                <div>
                    <label class="t-label text-xs mb-1">ROI Harian (IDR) <span class="text-red-500">*</span></label>
                    <input type="number" name="daily_rate" id="f_roi" required min="1" step="1" inputmode="numeric"
                           class="t-input w-full px-3 py-2 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                           placeholder="cth: 7500">
                </div>

                <div>
                    <label class="t-label text-xs mb-1">Batas Sewa / User <span class="text-[var(--t-muted)] font-normal">(0 = tanpa batas)</span></label>
                    <input type="number" name="max_per_user" id="f_quota" required min="0" step="1" inputmode="numeric"
                           class="t-input w-full px-3 py-2 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                           placeholder="0">
                </div>
                <label class="inline-flex items-center gap-2 text-xs t-text-2 pt-4 cursor-pointer">
                    <input type="checkbox" name="is_refundable" id="f_refundable" value="1" class="accent-emerald-600">
                    Dapat dikembalikan (is_refundable)
                </label>
                <div id="f_active_wrap">
                    <label class="t-label text-xs mb-1">Status <span class="text-red-500">*</span></label>
                    <select name="is_active" id="f_active" class="t-select t-input w-full px-3 py-2 rounded-lg text-sm">
                        <option value="1">Aktif</option>
                        <option value="0">Nonaktif</option>
                    </select>
                </div>

                <!-- plan/104: GAMBAR PRODUK (16:9) — preview server-rendered +
                     pratinjau langsung via URL.createObjectURL saat berkas dipilih. -->
                <div class="sm:col-span-2">
                    <label for="f_image" class="t-label text-xs mb-1">
                        Gambar Produk <span class="text-[var(--t-muted)] font-normal">(opsional)</span>
                    </label>

                    <div class="rounded-xl border border-[var(--t-border)] bg-[var(--t-surface-2)] p-3 flex items-center justify-center min-h-[140px]">
                        <img id="f_image_preview" src="" alt=""
                             class="hidden w-full max-w-[280px] aspect-video object-cover rounded-lg">
                        <div id="f_image_empty" class="text-center text-[var(--t-muted)] text-xs">
                            <i class="fas fa-microchip text-3xl block mb-2 opacity-40"></i>
                            Belum ada gambar
                        </div>
                    </div>

                    <input type="file" id="f_image" name="image"
                           accept="image/jpeg,image/png,image/webp"
                           class="t-input w-full mt-3 px-3 py-2 rounded-lg text-xs file:mr-2 file:px-2 file:py-1 file:rounded file:border-0 file:text-xs file:bg-emerald-600 file:text-white">

                    <p class="text-xs text-[var(--t-muted)] mt-1">
                        Format JPG/PNG/WebP, maksimal 2 MB. Rasio ideal 16:9 (contoh 1920×1080).
                        Mengunggah berkas baru akan menggantikan gambar lama.
                    </p>

                    <label id="f_image_remove_wrap"
                           class="hidden items-center gap-2 text-xs t-text-2 mt-2 cursor-pointer">
                        <input type="checkbox" name="remove_image" id="f_image_remove" value="1" class="accent-rose-600">
                        Hapus gambar saat ini
                    </label>
                </div>
            </div>

            <div id="edit_status_hint" class="hidden text-[11px] text-amber-600 dark:text-amber-400 -mt-2">
                <i class="fas fa-info-circle"></i> Status tidak diubah lewat form ini — gunakan tombol Aktifkan/Nonaktifkan di tabel.
            </div>

            <div class="flex items-center gap-3 pt-2 border-t border-[var(--t-border)]">
                <button type="submit" id="productSubmitBtn"
                        class="px-5 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 active:bg-emerald-800 transition-colors flex items-center gap-2">
                    <i class="fas fa-plus-circle text-xs"></i> <span id="productSubmitLabel">Buat Paket</span>
                </button>
                <button type="button" onclick="closeProductModal()"
                        class="px-4 py-2 rounded-lg t-btn-ghost text-sm border border-[var(--t-border)]">
                    Batal
                </button>
            </div>
        <?= form_close() ?>
    </div>
</div>

<script>
(function () {
    var modal  = document.getElementById('productModal');
    var form   = document.getElementById('productModalForm');
    // Hidden CSRF input disisipkan otomatis oleh form_open() di atas —
    // JS ini TIDAK menghapusnya; resetForm() hanya memulihkan nilai awal
    // token (tetap valid: csrf_regenerate = FALSE, token stabil per session).
    var csrfInput = form ? form.querySelector('input[type="hidden"][name="synapse_csrf_token"]') : null;
    var createUrl = '<?= site_url('admin/products/create') ?>';
    var updateBase = '<?= site_url('admin/products/update') ?>/';

    // plan/104: elemen gambar produk + penanda object URL yang sedang dipakai
    // (wajib di-revoke agar blob tidak menumpuk di memori).
    var imgInput     = document.getElementById('f_image');
    var imgPreview   = document.getElementById('f_image_preview');
    var imgEmpty     = document.getElementById('f_image_empty');
    var imgRemoveBox = document.getElementById('f_image_remove');
    var imgRemoveWrap= document.getElementById('f_image_remove_wrap');
    var objectUrl    = null;

    function clearObjectUrl() {
        if (objectUrl !== null) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }
    }

    /** Tampilkan pratinjau gambar (src apa pun) atau blok kosong. */
    function showImagePreview(src) {
        if (src) {
            imgPreview.src = src;
            imgPreview.classList.remove('hidden');
            imgEmpty.classList.add('hidden');
        } else {
            imgPreview.removeAttribute('src');
            imgPreview.classList.add('hidden');
            imgEmpty.classList.remove('hidden');
        }
    }

    function resetForm() {
        var csrfValue = (csrfInput && csrfInput.value !== '') ? csrfInput.value : null;
        clearObjectUrl();
        form.reset();
        // Pastikan token tetap utuh setelah reset (fallback bila browser
        // mengosongkannya): pulihkan nilai yang tadi terbaca.
        if (csrfValue !== null && csrfInput && csrfInput.value === '') {
            csrfInput.value = csrfValue;
        }
        form.action = createUrl;
        document.getElementById('f_active').value = '1';
        document.getElementById('productModalTitle').textContent = 'Tambah Paket GPU';
        document.getElementById('productSubmitLabel').textContent = 'Buat Paket';
        document.getElementById('edit_status_hint').classList.add('hidden');
        document.getElementById('f_active_wrap').classList.remove('hidden');

        // plan/104: mode create → belum ada gambar; kontrol hapus disembunyikan.
        showImagePreview('');
        imgRemoveBox.checked = false;
        imgRemoveWrap.classList.add('hidden');
        imgRemoveWrap.classList.remove('inline-flex');
    }

    window.openProductCreate = function () {
        resetForm();
        modal.classList.remove('hidden');
        document.getElementById('f_name').focus();
    };

    window.openProductEdit = function (btn) {
        var d;
        try { d = JSON.parse(btn.getAttribute('data-product')); } catch (e) { return; }

        resetForm();
        form.action = updateBase + d.id;

        document.getElementById('f_name').value     = d.name;
        document.getElementById('f_type').value     = d.type;
        document.getElementById('f_price').value    = d.price;
        document.getElementById('f_roi').value      = d.daily_rate;
        document.getElementById('f_duration').value = d.duration_days;
        document.getElementById('f_quota').value    = d.max_per_user;
        document.getElementById('f_refundable').checked = (d.is_refundable === 1);

        // plan/104: tampilkan gambar saat ini (server-rendered URL) + kontrol
        // hapus hanya bila produk memang sudah punya gambar.
        showImagePreview(d.image_url || '');
        if (d.image_url) {
            imgRemoveWrap.classList.remove('hidden');
            imgRemoveWrap.classList.add('inline-flex');
        }

        // edit: status dikelola toggle → sembunyikan field is_active + hint
        document.getElementById('f_active_wrap').classList.add('hidden');
        document.getElementById('edit_status_hint').classList.remove('hidden');
        document.getElementById('productModalTitle').textContent = 'Edit Paket — ' + d.name;
        document.getElementById('productSubmitLabel').textContent = 'Simpan Perubahan';

        modal.classList.remove('hidden');
        document.getElementById('f_name').focus();
    };

    window.closeProductModal = function () {
        modal.classList.add('hidden');
        resetForm();
    };

    // plan/104: pratinjau langsung berkas yang dipilih + uncheck "Hapus gambar"
    // (berkas baru selalu menang atas permintaan hapus).
    if (imgInput) {
        imgInput.addEventListener('change', function () {
            var file = (imgInput.files && imgInput.files[0]) ? imgInput.files[0] : null;
            if (!file) { return; }

            clearObjectUrl();
            objectUrl = URL.createObjectURL(file);
            showImagePreview(objectUrl);
            imgRemoveBox.checked = false;
        });
    }

    // tombol Edit tiap baris — binding deklaratif (tanpa inline onclick)
    document.querySelectorAll('.btn-product-edit').forEach(function (btn) {
        btn.addEventListener('click', function () { openProductEdit(btn); });
    });
})();
</script>
