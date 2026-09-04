<?php
defined('BASEPATH') OR exit('No direct script access allowed');
// M7 (plan/70): Pengaturan terpadu — kontak/support, jam operasional &
// hari aktif, biaya penarikan + tier dinamis, dan biaya deposit dalam satu
// halaman/form (single authoritative endpoint /admin/settings).
// Theme: Bloomberg Terminal / Clean Admin (theme-aware via Phase 30 tokens).

$day_labels = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
?>

<?php if ($this->session->flashdata('success')): ?>
    <div class="mb-4 px-4 py-3 rounded-lg t-flash-success text-sm flex items-center gap-2">
        <i class="fas fa-check-circle"></i>
        <?= $this->session->flashdata('success') ?>
    </div>
<?php endif; ?>

<?php if ($this->session->flashdata('error')): ?>
    <div class="mb-4 px-4 py-3 rounded-lg t-flash-error text-sm flex items-center gap-2">
        <i class="fas fa-exclamation-circle"></i>
        <?= $this->session->flashdata('error') ?>
    </div>
<?php endif; ?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-6">
        <h3 class="text-lg font-semibold text-[var(--t-text)]">Pengaturan</h3>
        <p class="text-sm text-[var(--t-muted)] mt-1">
            Kelola informasi kontak support, jam operasional penarikan (WIB), biaya &amp; tier penarikan,
            serta biaya deposit. Perubahan langsung berlaku; fallback spec PRD di
            <code class="text-xs">application/config/withdrawal_fees.php</code>.
        </p>
    </div>

    <?= form_open('admin/settings', ['id' => 'settingsForm', 'data-guard-submit' => '1']) ?>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">

        <!-- ================= COLUMN KIRI ================= -->
        <div class="space-y-6">

            <!-- Card 1: General & Support -->
            <div class="t-card p-6">
                <h4 class="text-sm font-semibold text-[var(--t-text)] mb-4 flex items-center gap-2">
                    <i class="fas fa-headset text-indigo-500"></i> General &amp; Support
                </h4>

                <div class="space-y-4">
                    <div>
                        <label for="wa_number" class="t-label text-sm mb-1.5">
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
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono
                                      focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <p class="text-xs text-[var(--t-muted)] mt-1">Format: kode negara + nomor. Contoh: 628123456789</p>
                    </div>

                    <div>
                        <label for="support_email" class="t-label text-sm mb-1.5">
                            <i class="fas fa-envelope text-blue-500 mr-1"></i>
                            Email Support
                        </label>
                        <input type="email"
                               id="support_email"
                               name="support_email"
                               value="<?= set_value('support_email', $support_email) ?>"
                               required
                               placeholder="support@synapse.id"
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm
                                      focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>
            </div>

            <!-- Card 2: Operational Hours & Active Days -->
            <div class="t-card p-6">
                <h4 class="text-sm font-semibold text-[var(--t-text)] mb-4 flex items-center gap-2">
                    <i class="fas fa-clock text-indigo-500"></i> Jam Operasional Penarikan
                </h4>

                <div class="mb-4">
                    <label class="t-label text-sm mb-2 block">Hari Aktif (WIB)</label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <?php foreach ($day_labels as $dval => $dlabel): ?>
                        <label class="flex items-center gap-2 text-sm text-[var(--t-text-2)] cursor-pointer select-none">
                            <input type="checkbox"
                                   name="wd_operational_days[]"
                                   value="<?= $dval ?>"
                                   class="rounded border-slate-300"
                                   <?= in_array($dval, $days, true) ? 'checked' : '' ?>>
                            <?= $dlabel ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="wd_open_time" class="t-label text-sm mb-1.5 block">Jam Buka</label>
                        <input type="time" id="wd_open_time" name="wd_open_time"
                               value="<?= htmlspecialchars($open_time) ?>" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm">
                    </div>
                    <div>
                        <label for="wd_close_time" class="t-label text-sm mb-1.5 block">Jam Tutup</label>
                        <input type="time" id="wd_close_time" name="wd_close_time"
                               value="<?= htmlspecialchars($close_time) ?>" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm">
                    </div>
                </div>
                <p class="text-xs text-[var(--t-muted)] mt-2">Gerbang berlaku saat <strong>pengajuan</strong> penarikan (hari &amp; jam WIB).</p>
            </div>
        </div>

        <!-- ================= COLUMN KANAN ================= -->
        <div class="space-y-6">

            <!-- Card 3: Withdrawal Fee & Dynamic Tier -->
            <div class="t-card p-6">
                <h4 class="text-sm font-semibold text-[var(--t-text)] mb-4 flex items-center gap-2">
                    <i class="fas fa-percentage text-orange-500"></i> Biaya Penarikan
                </h4>

                <div class="grid grid-cols-3 gap-4 mb-4">
                    <div>
                        <label for="wd_fixed_fee" class="t-label text-sm mb-1.5 block">Biaya Tetap (IDR)</label>
                        <input type="number" id="wd_fixed_fee" name="wd_fixed_fee"
                               value="<?= (int) $fixed_fee ?>" min="0" max="100000" step="1" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono">
                    </div>
                    <div>
                        <label for="wd_min_amount" class="t-label text-sm mb-1.5 block">Minimal (IDR)</label>
                        <input type="number" id="wd_min_amount" name="wd_min_amount"
                               value="<?= (int) $min_amount ?>" min="1" step="1" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono">
                    </div>
                    <div>
                        <label for="wd_max_amount" class="t-label text-sm mb-1.5 block">Maksimal (IDR)</label>
                        <input type="number" id="wd_max_amount" name="wd_max_amount"
                               value="<?= (int) $max_amount ?>" min="1" step="1" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono">
                    </div>
                </div>

                <label class="t-label text-sm mb-2 block">Tier Biaya (% dari nominal + biaya tetap)</label>
                <div class="space-y-2 mb-2" id="tierRows">
                    <?php foreach ($tiers as $i => $tier): ?>
                    <!-- Tier row: grid responsif (label di atas input) — M7 (plan/70) fix overflow -->
                    <div class="tier-row rounded-lg border border-slate-200 dark:border-slate-700 p-3
                                grid grid-cols-2 sm:grid-cols-12 gap-2 sm:gap-3 items-end">
                        <div class="col-span-1 sm:col-span-4 min-w-0">
                            <label class="block text-[11px] text-[var(--t-muted)] mb-1">Min (IDR)</label>
                            <input type="number" class="tier-min t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono"
                                   min="0" step="1" value="<?= (int) $tier[0] ?>">
                        </div>
                        <div class="col-span-1 sm:col-span-4 min-w-0">
                            <label class="block text-[11px] text-[var(--t-muted)] mb-1">Maks (IDR)</label>
                            <input type="number" class="tier-max t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono"
                                   min="0" step="1" value="<?= (int) $tier[1] ?>">
                        </div>
                        <div class="col-span-1 sm:col-span-3 min-w-0">
                            <label class="block text-[11px] text-[var(--t-muted)] mb-1">Persen (%)</label>
                            <input type="number" class="tier-pct t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono"
                                   min="0" max="100" step="0.01"
                                   value="<?= htmlspecialchars(rtrim(rtrim(number_format($tier[2] / 100, 2, '.', ''), '0'), '.')) ?>">
                        </div>
                        <div class="col-span-2 sm:col-span-1 flex justify-end items-end">
                            <button type="button" class="tier-del w-8 h-8 rounded-lg text-xs text-red-500 hover:bg-red-500/10 shrink-0"
                                    title="Hapus baris">&times;</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="tierAdd"
                        class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                    <i class="fas fa-plus mr-1"></i>Tambah baris tier
                </button>
                <input type="hidden" name="wd_fee_tiers" id="wd_fee_tiers" value="">
                <p id="tierStatus" class="text-xs text-[var(--t-muted)] mt-2">
                    Rentang half-open [min, max): nominal batas masuk ke tier lebih tinggi. Simpan untuk validasi server.
                </p>
            </div>

            <!-- Card 4: Deposit Fee -->
            <div class="t-card p-6">
                <h4 class="text-sm font-semibold text-[var(--t-text)] mb-4 flex items-center gap-2">
                    <i class="fas fa-wallet text-emerald-500"></i> Biaya Deposit (Top Up)
                </h4>

                <div class="flex items-center justify-between mb-4">
                    <label for="deposit_fee_enabled" class="text-sm text-[var(--t-text-2)] cursor-pointer select-none">
                        Aktifkan biaya deposit
                    </label>
                    <input type="checkbox" id="deposit_fee_enabled" name="deposit_fee_enabled" value="1"
                           class="rounded border-slate-300" <?= $deposit_fee_enabled ? 'checked' : '' ?>>
                </div>
                <p class="text-xs text-[var(--t-muted)] -mt-2 mb-4">User membayar <strong>pokok + biaya</strong>; saldo wallet dikredit <strong>pokok saja</strong> (zero dilution).</p>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="deposit_fee_type" class="t-label text-sm mb-1.5 block">Tipe Biaya</label>
                        <select id="deposit_fee_type" name="deposit_fee_type"
                                class="t-input w-full px-3 py-2.5 rounded-lg text-sm">
                            <option value="flat" <?= $deposit_fee_type === 'flat' ? 'selected' : '' ?>>Flat (IDR tetap)</option>
                            <option value="percent" <?= $deposit_fee_type === 'percent' ? 'selected' : '' ?>>Persen (%)</option>
                        </select>
                    </div>
                    <div>
                        <label for="deposit_fee_value" class="t-label text-sm mb-1.5 block">
                            Nilai <span id="depValSuffix">(IDR)</span>
                        </label>
                        <input type="number" id="deposit_fee_value" name="deposit_fee_value"
                               value="<?= htmlspecialchars((string) $deposit_fee_value) ?>"
                               min="0" step="any" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Submit (satu form → satu aksi simpan; M4 guard aktif via data-guard-submit) -->
    <div class="pt-6 mt-2 border-t border-[var(--t-border)] flex justify-end">
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

<script>
(function () {
    var DAY_NAMES = {1:'Senin',2:'Selasa',3:'Rabu',4:'Kamis',5:'Jumat',6:'Sabtu',7:'Minggu'};
    var MIN_AMOUNT = <?= (int) $min_amount ?>;
    var MAX_AMOUNT = <?= (int) $max_amount ?>;

    var rowsEl = document.getElementById('tierRows');
    var hidden = document.getElementById('wd_fee_tiers');
    var statusEl = document.getElementById('tierStatus');

    function serializeTiers() {
        var out = [];
        var rows = rowsEl.querySelectorAll('.tier-row');
        var min = null;
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var mn = parseInt(r.querySelector('.tier-min').value.replace(/[^0-9]/g, ''), 10);
            var mx = parseInt(r.querySelector('.tier-max').value.replace(/[^0-9]/g, ''), 10);
            var pc = parseFloat(r.querySelector('.tier-pct').value.replace(',', '.'));
            if (isNaN(mn) || isNaN(mx) || isNaN(pc) || mx <= mn || pc < 0 || pc > 100) {
                hidden.value = '';
                statusEl.textContent = 'Tier belum valid: periksa min < max dan persen 0–100 pada setiap baris.';
                statusEl.className = 'text-xs text-red-500 mt-2';
                return false;
            }
            if (min !== null && mn !== min) {
                hidden.value = '';
                statusEl.textContent = 'Tier harus kontigu: nilai minimal baris ini harus = nilai maksimal baris sebelumnya (' + min.toLocaleString('id-ID') + ').';
                statusEl.className = 'text-xs text-red-500 mt-2';
                return false;
            }
            var bps = Math.round(pc * 100); // 10% -> 1000
            out.push([mn, mx, bps]);
            min = mx;
        }
        if (out.length === 0) {
            hidden.value = '';
            statusEl.textContent = 'Minimal satu baris tier.';
            statusEl.className = 'text-xs text-red-500 mt-2';
            return false;
        }
        if (out[0][0] !== MIN_AMOUNT) {
            hidden.value = '';
            statusEl.textContent = 'Tier pertama harus dimulai dari nominal minimal penarikan (Rp ' + MIN_AMOUNT.toLocaleString('id-ID') + ').';
            statusEl.className = 'text-xs text-red-500 mt-2';
            return false;
        }
        if (out[out.length - 1][1] <= MAX_AMOUNT) {
            hidden.value = '';
            statusEl.textContent = 'Batas atas tier terakhir harus di atas nominal maksimal (Rp ' + MAX_AMOUNT.toLocaleString('id-ID') + ').';
            statusEl.className = 'text-xs text-red-500 mt-2';
            return false;
        }
        hidden.value = JSON.stringify(out);
        statusEl.textContent = 'JSON tier siap disimpan: ' + out.length + ' baris.';
        statusEl.className = 'text-xs text-emerald-600 dark:text-emerald-400 mt-2';
        return true;
    }

    // Markup baris baru — HARUS identik dengan baris render PHP di atas
    // (grid responsif label-di-atas-input; M7 fix overflow).
    function tierRowHTML(tier) {
        var pct = tier ? (tier[2] / 100) : 0;
        var val = tier ? String(pct) : '';
        return '' +
            '<div class="col-span-1 sm:col-span-4 min-w-0">' +
                '<label class="block text-[11px] text-[var(--t-muted)] mb-1">Min (IDR)</label>' +
                '<input type="number" class="tier-min t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono" min="0" step="1" value="' + (tier ? tier[0] : '') + '">' +
            '</div>' +
            '<div class="col-span-1 sm:col-span-4 min-w-0">' +
                '<label class="block text-[11px] text-[var(--t-muted)] mb-1">Maks (IDR)</label>' +
                '<input type="number" class="tier-max t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono" min="0" step="1" value="' + (tier ? tier[1] : '') + '">' +
            '</div>' +
            '<div class="col-span-1 sm:col-span-3 min-w-0">' +
                '<label class="block text-[11px] text-[var(--t-muted)] mb-1">Persen (%)</label>' +
                '<input type="number" class="tier-pct t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono" min="0" max="100" step="0.01" value="' + val + '">' +
            '</div>' +
            '<div class="col-span-2 sm:col-span-1 flex justify-end items-end">' +
                '<button type="button" class="tier-del w-8 h-8 rounded-lg text-xs text-red-500 hover:bg-red-500/10 shrink-0" title="Hapus baris">&times;</button>' +
            '</div>';
    }

    function addRow(tier) {
        var div = document.createElement('div');
        div.className = 'tier-row rounded-lg border border-slate-200 dark:border-slate-700 p-3 grid grid-cols-2 sm:grid-cols-12 gap-2 sm:gap-3 items-end';
        div.innerHTML = tierRowHTML(tier);
        rowsEl.appendChild(div);
        wireRow(div);
    }

    function wireRow(row) {
        row.querySelectorAll('input').forEach(function (el) {
            el.addEventListener('input', serializeTiers);
        });
        var del = row.querySelector('.tier-del');
        if (del) {
            del.addEventListener('click', function () {
                if (rowsEl.querySelectorAll('.tier-row').length <= 1) {
                    return;
                }
                row.remove();
                serializeTiers();
            });
        }
    }

    rowsEl.querySelectorAll('.tier-row').forEach(wireRow);
    document.getElementById('tierAdd').addEventListener('click', function () { addRow(null); });

    // Deposit type: ubah label/suffix & step.
    var typeEl = document.getElementById('deposit_fee_type');
    var valEl = document.getElementById('deposit_fee_value');
    var suffixEl = document.getElementById('depValSuffix');
    function syncDepositType() {
        var pct = typeEl.value === 'percent';
        suffixEl.textContent = pct ? '(%, contoh 0.70)' : '(IDR)';
        valEl.step = pct ? 'any' : '1';
        valEl.max = pct ? '5' : '100000';
    }
    typeEl.addEventListener('change', syncDepositType);
    syncDepositType();

    // Submit: tier harus tervalidasi lebih dulu. preventDefault + stopPropagation
    // agar M4 guard (csrf_meta, data-guard-submit) TIDAK menandai form
    // sebagai "submitting" saat submit dibatalkan oleh tier invalid.
    document.getElementById('settingsForm').addEventListener('submit', function (e) {
        if (!serializeTiers()) {
            e.preventDefault();
            e.stopPropagation();
        }
    });

    serializeTiers();
})();
</script>
