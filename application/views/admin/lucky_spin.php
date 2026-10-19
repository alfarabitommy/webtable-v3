<?php
defined('BASEPATH') OR exit('No direct script access allowed');
// plan/121 — Admin Lucky Spin Wheel: konfigurasi segmen roda hadiah.
//
// Kontrak halaman:
//   • toggle fitur (fail-closed: nonaktif → roda member hilang DAN endpoint
//     `POST /lucky-spin/spin` menolak HTTP 403 `code:'disabled'`);
//   • editor baris `spin_amount[]` / `spin_weight[]` (nominal IDR + bobot),
//     URUTAN baris = urutan segmen pada roda member;
//   • ringkasan peluang (%) + expected value per tiket dihitung ulang live di
//     klien dari input (integer, tanpa float) — angka server dipakai saat load;
//   • seluruh copy literal Indonesia (invarian L1: panel admin TIDAK memuat
//     kamus/i18n_apply), seluruh angka uang diformat di server/view (L6).
//
// Mutasi POST via form_open (CSRF otomatis) + `data-guard-submit` (M4): satu
// form, satu aksi simpan.

$spin_rows        = isset($spin_rows) && is_array($spin_rows) ? $spin_rows : [];
$spin_field_errors = isset($spin_field_errors) ? (array) $spin_field_errors : [];
$spin_limits      = isset($spin_limits) ? (array) $spin_limits : spin_wheel_limits();
$spin_overview    = isset($spin_overview) && is_array($spin_overview) ? $spin_overview : [];
$spin_odds_bp     = isset($spin_odds_bp) && is_array($spin_odds_bp) ? $spin_odds_bp : [];

$ov_tickets = (int) ($spin_overview['tickets_outstanding'] ?? 0);
$ov_granted = (int) ($spin_overview['tickets_granted'] ?? 0);
$ov_spins   = (int) ($spin_overview['spins_total'] ?? 0);
$ov_paid    = (int) ($spin_overview['prizes_paid'] ?? 0);
$ov_recent  = isset($spin_overview['recent']) && is_array($spin_overview['recent']) ? $spin_overview['recent'] : [];

// Pesan validasi umum (bukan per-baris) untuk blok ringkasan.
$spin_general_errors = [];
foreach ($spin_field_errors as $fk => $fm) {
    if (is_array($fm) && count($fm) > 0) {
        $spin_general_errors = array_merge($spin_general_errors, array_values($fm));
    }
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

<!-- ═══ Ringkasan sirkulasi + hadiah (read-only) ═══ -->
<div class="flex items-center gap-2 flex-wrap mb-6">
    <span class="px-3 py-1.5 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 text-xs font-medium">
        <i class="fas fa-ticket mr-1"></i><?= (int) $ov_tickets ?> tiket beredar
    </span>
    <span class="px-3 py-1.5 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20 text-xs font-medium">
        <?= (int) $ov_granted ?> tiket diperoleh
    </span>
    <span class="px-3 py-1.5 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border border-cyan-500/20 text-xs font-medium">
        <?= (int) $ov_spins ?> putaran
    </span>
    <span class="px-3 py-1.5 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 text-xs font-medium">
        Hadiah dibayarkan: Rp <?= number_format((int) $ov_paid, 0, ',', '.') ?>
    </span>
</div>

<!-- ═══ Kartu konfigurasi roda ═══ -->
<div class="t-card p-6">
    <h4 class="text-sm font-semibold text-[var(--t-text)] mb-1 flex items-center gap-2">
        <i class="fas fa-dharmachakra text-amber-500"></i> Lucky Spin Wheel
    </h4>
    <p class="text-xs text-[var(--t-muted)] mb-5">
        Member menerima <span class="font-semibold">1 tiket</span> setiap kali downline L1-nya melakukan
        pembelian <span class="font-semibold">NON-trial pertama kali</span> (maksimal 1 tiket per downline
        seumur hidup). Tiket dipakai memutar roda hadiah; hadiah masuk
        <span class="font-semibold">Saldo Utama</span> dan dapat ditarik.
        Peluang menang dihitung di server (bobot relatif, bukan persentase mutlak).
    </p>

    <?php if (!empty($spin_general_errors)): ?>
    <div class="mb-4 px-3 py-2 rounded-lg text-xs bg-red-500/10 text-red-600 dark:text-red-400">
        <p class="font-medium mb-1">Periksa kembali:</p>
        <ul class="list-disc list-inside space-y-0.5">
            <?php foreach ($spin_general_errors as $spin_message): ?>
            <li><?= htmlspecialchars((string) $spin_message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (empty($spin_configured)): ?>
    <div class="mb-4 px-3 py-2 rounded-lg text-xs bg-amber-500/10 text-amber-700 dark:text-amber-400">
        <i class="fas fa-triangle-exclamation mr-1"></i>
        Konfigurasi segmen saat ini <span class="font-semibold">tidak valid</span> sehingga roda member
        ditutup (fail-closed, HTTP 403). Simpan konfigurasi yang valid di bawah untuk mengaktifkannya kembali.
    </div>
    <?php endif; ?>

    <?= form_open('admin/lucky-spin', ['id' => 'spinForm', 'data-guard-submit' => '1']) ?>

    <label class="flex items-start gap-3 cursor-pointer mb-5">
        <input type="checkbox" name="spin_wheel_enabled" value="1"
               class="mt-0.5 rounded border-slate-300" <?= !empty($spin_enabled) ? 'checked' : '' ?>>
        <span>
            <span class="text-sm text-[var(--t-text-2)] block">Aktifkan Lucky Spin Wheel</span>
            <span class="text-xs text-[var(--t-muted)] block">
                Bila <span class="font-semibold">nonaktif</span>: kartu &amp; tombol mengambang di dashboard
                member hilang, halaman <span class="font-mono">/lucky-spin</span> menampilkan pesan nonaktif,
                dan endpoint putar roda menolak (HTTP 403). Tiket yang sudah diperoleh tetap tersimpan.
            </span>
        </span>
    </label>

    <p class="t-label text-sm mb-2 block">Segmen Roda (nominal hadiah &rarr; bobot)</p>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" id="spinTable">
            <thead>
                <tr class="text-left text-xs text-[var(--t-muted)]">
                    <th class="py-2 pr-3 font-medium w-10">#</th>
                    <th class="py-2 pr-3 font-medium">Nominal Hadiah (Rp)</th>
                    <th class="py-2 pr-3 font-medium">Bobot</th>
                    <th class="py-2 pr-3 font-medium">Peluang</th>
                    <th class="py-2 w-10"></th>
                </tr>
            </thead>
            <tbody id="spinRows">
                <?php foreach ($spin_rows as $i => $row):
                    $row_amount = isset($row['amount']) ? (string) $row['amount'] : '';
                    $row_weight = isset($row['weight']) ? (string) $row['weight'] : '';
                    $row_error  = isset($spin_field_errors['spin_row_' . ($i + 1)])
                        ? implode(' ', (array) $spin_field_errors['spin_row_' . ($i + 1)]) : '';
                    $row_odds   = isset($spin_odds_bp[$i]) ? ((int) $spin_odds_bp[$i] / 100) : 0;
                ?>
                <tr class="align-top">
                    <td class="py-2 pr-3 text-xs text-[var(--t-muted)] pt-4">
                        <span class="spin-row-no"><?= $i + 1 ?></span>
                    </td>
                    <td class="py-2 pr-3">
                        <input type="number" name="spin_amount[]" value="<?= htmlspecialchars($row_amount) ?>"
                               min="1" max="<?= (int) $spin_limits['max_amount'] ?>" step="1"
                               class="t-input w-full px-3 py-2 rounded-lg text-sm spin-amount"
                               placeholder="contoh: 10000">
                    </td>
                    <td class="py-2 pr-3">
                        <input type="number" name="spin_weight[]" value="<?= htmlspecialchars($row_weight) ?>"
                               min="1" max="<?= (int) $spin_limits['max_weight'] ?>" step="1"
                               class="t-input w-full px-3 py-2 rounded-lg text-sm spin-weight"
                               placeholder="contoh: 25">
                    </td>
                    <td class="py-2 pr-3">
                        <span class="spin-odds inline-block px-2 py-1 rounded-lg bg-[var(--t-surface-2)] border border-[var(--t-border)] text-xs t-text-2 font-mono pt-2">
                            <?= number_format((float) $row_odds, 2, ',', '.') ?>%
                        </span>
                    </td>
                    <td class="py-2">
                        <button type="button" onclick="spinRemoveRow(this)" title="Hapus baris"
                                class="px-2 py-2 rounded-lg text-red-500 hover:bg-red-500/10 transition-colors">
                            <i class="fas fa-trash text-xs"></i>
                        </button>
                    </td>
                </tr>
                <?php if ($row_error !== ''): ?>
                <tr class="align-top">
                    <td colspan="5" class="pb-2 text-xs text-red-600 dark:text-red-400"><?= htmlspecialchars($row_error) ?></td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="text-xs text-[var(--t-muted)]">
                    <td class="py-2"></td>
                    <td class="py-2 pr-3">
                        Total bobot: <span id="spin-total-weight" class="font-mono font-semibold"><?= (int) $spin_total_weight ?></span>
                    </td>
                    <td class="py-2 pr-3" colspan="2">
                        Rata-rata hadiah per tiket (EV): <span id="spin-ev" class="font-mono font-semibold">Rp <?= number_format((int) $spin_expected_value, 0, ',', '.') ?></span>
                    </td>
                    <td class="py-2"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <button type="button" onclick="spinAddRow()"
            class="mt-3 px-3 py-2 rounded-lg text-xs font-medium bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 transition-colors flex items-center gap-2">
        <i class="fas fa-plus text-xs"></i> Tambah Segmen
    </button>

    <div class="mt-4 rounded-lg p-3 border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10">
        <p class="text-xs text-[var(--t-text-2)] leading-relaxed">
            <i class="fas fa-exclamation-triangle text-amber-500 mr-1"></i>
            Minimal <span class="font-semibold"><?= (int) $spin_limits['min_segments'] ?></span> segmen,
            maksimal <span class="font-semibold"><?= (int) $spin_limits['max_segments'] ?></span> segmen.
            Nominal <span class="font-semibold">1&ndash;<?= number_format((int) $spin_limits['max_amount'], 0, ',', '.') ?></span>
            (segmen Rp&nbsp;0 <span class="font-semibold">tidak diperbolehkan</span> — setiap putaran pasti berhadiah),
            bobot <span class="font-semibold">1&ndash;<?= number_format((int) $spin_limits['max_weight'], 0, ',', '.') ?></span>.
            Baris kosong otomatis diabaikan. Urutan baris = urutan segmen pada roda member.
        </p>
        <p class="text-xs text-[var(--t-text-2)] leading-relaxed mt-2">
            <i class="fas fa-info-circle text-indigo-500 mr-1"></i>
            Peluang = bobot segmen / total bobot. Perubahan berlaku seketika untuk putaran berikutnya;
            hasil putaran yang sudah tercatat <span class="font-semibold">tidak berubah</span> (snapshot bobot
            disimpan per putaran).
        </p>
    </div>

    <div class="pt-6 mt-4 border-t border-[var(--t-border)] flex justify-end">
        <button type="submit"
                class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white text-sm font-medium
                       hover:bg-indigo-700 active:bg-indigo-800 transition-colors
                       flex items-center gap-2">
            <i class="fas fa-save text-xs"></i>
            Simpan Konfigurasi
        </button>
    </div>

    <?= form_close() ?>
</div>

<!-- ═══ 20 putaran terakhir (read-only) ═══ -->
<div class="t-card p-6 mt-6">
    <h4 class="text-sm font-semibold text-[var(--t-text)] mb-1 flex items-center gap-2">
        <i class="fas fa-clock-rotate-left text-indigo-500"></i> 20 Putaran Terakhir
    </h4>
    <p class="text-xs text-[var(--t-muted)] mb-4">
        Jejak audit putaran: segmen yang keluar, nominal hadiah, dan waktu (WIB). Nominal hadiah
        dikreditkan ke Saldo Utama lewat ledger <span class="font-mono">SPIN-{id}</span>.
    </p>

    <?php if (count($ov_recent) === 0): ?>
    <p class="text-xs text-[var(--t-muted)] text-center py-4">Belum ada putaran.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-[var(--t-muted)]">
                    <th class="py-2 pr-3 font-medium">Member</th>
                    <th class="py-2 pr-3 font-medium">Segmen</th>
                    <th class="py-2 pr-3 font-medium">Hadiah</th>
                    <th class="py-2 pr-3 font-medium">Waktu</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ov_recent as $spin_item): ?>
                <tr class="border-t border-[var(--t-border)]">
                    <td class="py-2 pr-3 font-mono text-xs"><?= htmlspecialchars((string) $spin_item->phone) ?></td>
                    <td class="py-2 pr-3 text-xs">#<?= (int) $spin_item->slot_index + 1 ?></td>
                    <td class="py-2 pr-3 text-xs font-semibold">
                        Rp <?= number_format((int) $spin_item->prize_amount, 0, ',', '.') ?>
                    </td>
                    <td class="py-2 pr-3 text-xs text-[var(--t-muted)]">
                        <?= htmlspecialchars(date('d M Y H:i', strtotime((string) $spin_item->created_at))) ?> WIB
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
// plan/121 — editor baris segmen + kalkulasi peluang & EV live (vanilla,
// tanpa dependensi baru). Aritmetika INTEGER seperti server (Math.floor /
// intdiv), sehingga angka di layar tidak pernah "mengambang".
var SPIN_MIN_ROWS = <?= (int) $spin_limits['min_segments'] ?>;
var SPIN_MAX_ROWS = <?= (int) $spin_limits['max_segments'] ?>;

function spinNum(raw) {
    var s = String(raw == null ? '' : raw).trim();
    if (!/^[0-9]+$/.test(s)) { return 0; }
    var n = parseInt(s, 10);
    return isNaN(n) ? 0 : n;
}

function spinFmt(n) {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
}

function spinRowCount() {
    var body = document.getElementById('spinRows');
    return body ? body.querySelectorAll('tr.align-top').length : 0;
}

function spinRecompute() {
    var body = document.getElementById('spinRows');
    if (!body) { return; }

    var rows  = body.querySelectorAll('tr.align-top');
    var total = 0;
    var weighted = 0;

    rows.forEach(function (tr, i) {
        var a = spinNum((tr.querySelector('.spin-amount') || {}).value);
        var w = spinNum((tr.querySelector('.spin-weight') || {}).value);
        total += w;
        weighted += (a * w);

        var no = tr.querySelector('.spin-row-no');
        if (no) { no.textContent = String(i + 1); }

        var oddsEl = tr.querySelector('.spin-odds');
        if (oddsEl) {
            var bp = 0;
            if (total > 0 && w > 0) { bp = Math.floor((w * 10000) / total); }
            oddsEl.textContent = (total > 0 && w > 0)
                ? (bp / 100).toFixed(2).replace('.', ',') + '%'
                : '0,00%';
        }
    });

    // Peluang dihitung per baris dari total FINAL — ulangi sekali lagi agar
    // tidak bergantung urutan akumulasi.
    if (total > 0) {
        rows.forEach(function (tr) {
            var w = spinNum((tr.querySelector('.spin-weight') || {}).value);
            var oddsEl = tr.querySelector('.spin-odds');
            if (!oddsEl) { return; }
            var bp = (w > 0) ? Math.floor((w * 10000) / total) : 0;
            oddsEl.textContent = (bp / 100).toFixed(2).replace('.', ',') + '%';
        });
    }

    var totalEl = document.getElementById('spin-total-weight');
    if (totalEl) { totalEl.textContent = String(total); }

    var evEl = document.getElementById('spin-ev');
    if (evEl) {
        var ev = (total > 0) ? Math.floor(weighted / total) : 0;
        evEl.textContent = 'Rp ' + spinFmt(ev);
    }
}

function spinAddRow() {
    if (spinRowCount() >= SPIN_MAX_ROWS) { return; }
    var body = document.getElementById('spinRows');
    if (!body) { return; }

    var tr = document.createElement('tr');
    tr.className = 'align-top';
    tr.innerHTML = '<td class="py-2 pr-3 text-xs text-[var(--t-muted)] pt-4"><span class="spin-row-no"></span></td>'
        + '<td class="py-2 pr-3"><input type="number" name="spin_amount[]" min="1" max="<?= (int) $spin_limits['max_amount'] ?>" step="1"'
        + ' class="t-input w-full px-3 py-2 rounded-lg text-sm spin-amount" placeholder="contoh: 10000"></td>'
        + '<td class="py-2 pr-3"><input type="number" name="spin_weight[]" min="1" max="<?= (int) $spin_limits['max_weight'] ?>" step="1"'
        + ' class="t-input w-full px-3 py-2 rounded-lg text-sm spin-weight" placeholder="contoh: 25"></td>'
        + '<td class="py-2 pr-3"><span class="spin-odds inline-block px-2 py-1 rounded-lg bg-[var(--t-surface-2)]'
        + ' border border-[var(--t-border)] text-xs t-text-2 font-mono pt-2">0,00%</span></td>'
        + '<td class="py-2"><button type="button" onclick="spinRemoveRow(this)" title="Hapus baris"'
        + ' class="px-2 py-2 rounded-lg text-red-500 hover:bg-red-500/10 transition-colors">'
        + '<i class="fas fa-trash text-xs"></i></button></td>';
    body.appendChild(tr);
    spinRecompute();
}

function spinRemoveRow(btn) {
    var body = document.getElementById('spinRows');
    if (!body || !btn) { return; }
    var tr = btn.closest('tr');
    if (!tr) { return; }
    // Sisakan minimal satu baris editor agar form tidak kehilangan input.
    if (spinRowCount() <= 1) {
        tr.querySelectorAll('input').forEach(function (el) { el.value = ''; });
        spinRecompute();
        return;
    }
    tr.remove();
    spinRecompute();
}

(function () {
    var body = document.getElementById('spinRows');
    if (!body) { return; }
    body.addEventListener('input', spinRecompute);
    spinRecompute();
})();
</script>
