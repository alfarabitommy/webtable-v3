<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// M1 (plan/56 §4.2): dynamic WD config (tiers/fixed/window) diinjeksi dari
// Wallet::withdraw(); preview fee real-time; disabled state saat di luar
// jam/hari operasional (notice WIB). Server tetap otoritas.

// Mask account number: first 4 + asterisks + last 3
$acc = $bank->account_number;
$len = strlen($acc);
if ($len > 7) {
    $masked = substr($acc, 0, 4) . str_repeat('*', $len - 7) . substr($acc, -3);
} else {
    $masked = $acc;
}

// Baseline server (render awal); JS menyegarkan via jam WIB.
$closed_notice = '';
if (!$wd_open) {
    $closed_notice = ($wd_code === 'closed_day')
        ? 'Hari ini bukan hari operasional penarikan.'
        : 'Penarikan hanya dapat diajukan pada pukul ' . $wd_config['open_time'] . '–' . $wd_config['close_time'] . ' WIB.';
}
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

    <!-- ===== NOTICE: DI LUAR JAM/HARI OPERASIONAL (M1) ===== -->
    <div id="wdClosedNotice"
         class="<?= $closed_notice === '' ? 'hidden ' : '' ?>bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/20 rounded-xl p-3 text-xs font-bold text-rose-700 dark:text-rose-300 flex items-center gap-2">
        <i class="fas fa-clock"></i>
        <span id="wdClosedNoticeText"><?= htmlspecialchars($closed_notice); ?></span>
    </div>

    <!-- ===== READ-ONLY BANK CARD ===== -->
    <div class="u-card-fin text-white p-4 rounded-xl mb-6 relative overflow-hidden">
        <div class="absolute inset-0 opacity-5" style="background-image: repeating-linear-gradient(45deg, #fff 0, #fff 1px, transparent 1px, transparent 20px);"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-3">
                <span class="text-slate-400 text-[10px] uppercase tracking-widest font-bold">Rekening Penarikan</span>
                <span class="bg-emerald-500/20 text-emerald-400 text-[10px] font-bold px-2 py-0.5 rounded-full border border-emerald-500/30">TERVERIFIKASI</span>
            </div>
            <div class="text-lg font-extrabold tracking-tight mb-1"><?= htmlspecialchars($bank->bank_name); ?></div>
            <div class="text-xl font-mono font-bold tracking-widest mb-3"><?= $masked; ?></div>
            <div class="border-t border-slate-800 pt-3">
                <span class="text-slate-400 text-[10px] uppercase tracking-widest font-bold">a.n. </span>
                <span class="text-sm font-bold"><?= htmlspecialchars($bank->account_holder); ?></span>
            </div>
        </div>
    </div>

    <!-- ===== WITHDRAWAL FORM ===== -->
    <?= form_open('wallet/process_withdraw', ['id' => 'withdrawForm', 'data-guard-submit' => '1']); ?>

        <!-- Balance Info -->
        <div class="u-card-inset rounded-xl p-4 mb-4 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs u-text-2 font-bold">Saldo Tersedia</span>
                <span class="text-sm font-extrabold u-text font-mono">Rp <?= number_format($balance, 0, ',', '.'); ?></span>
            </div>
        </div>

        <!-- Amount Input -->
        <div class="mb-4">
            <label class="block text-[10px] uppercase tracking-widest u-muted font-bold mb-1.5">Nominal Penarikan</label>
            <div class="relative">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-bold">Rp</span>
                <input type="text"
                       name="amount"
                       id="wd_amount"
                       placeholder="0"
                       class="u-input w-full h-14 pl-12 pr-4 rounded-xl text-lg font-mono font-bold focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all text-right"
                       inputmode="numeric"
                       required>
            </div>
            <p class="mt-1.5 text-xs u-text-2">
                Minimal: Rp <?= number_format((int) $wd_config['min_amount'], 0, ',', '.'); ?>
                &bull; Maksimal: Rp <?= number_format((int) $wd_config['max_amount'], 0, ',', '.'); ?>
            </p>
            <!-- M8 parity: pesan error format non-integer (client-side,
                 selaras dengan validasi backend ^[1-9][0-9]*$). -->
            <p id="wdAmountError" class="hidden mt-1.5 text-xs font-bold text-rose-500 dark:text-rose-400"></p>
        </div>

        <!-- Fee Display (dynamic tier preview — M1) -->
        <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl p-3 mb-4">
            <div class="flex items-center justify-between mb-1">
                <span class="text-[11px] text-amber-700 dark:text-amber-400 font-bold">
                    Biaya Admin <span id="wd_bps_label" class="opacity-70">(tier)</span>
                </span>
                <span class="text-[11px] font-mono font-bold text-amber-700 dark:text-amber-400" id="wd_fee">Rp 0</span>
            </div>
            <div class="flex items-center justify-between border-t border-amber-200 dark:border-amber-500/20 pt-2 mt-2">
                <span class="text-[11px] text-amber-800 dark:text-amber-300 font-extrabold">Diterima</span>
                <span class="text-sm font-mono font-extrabold text-amber-800 dark:text-amber-300" id="wd_net">Rp 0</span>
            </div>
        </div>

        <!-- Submit Button -->
        <button type="submit" id="wd_submit"
                class="w-full h-14 bg-orange-500 hover:bg-orange-400 text-white rounded-xl text-sm font-extrabold shadow-lg transition-all active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-orange-500">
            <i class="fas fa-paper-plane mr-2"></i> Ajukan Penarikan
        </button>

    <?= form_close(); ?>
</div>

<script>
(function() {
    // ===== Dynamic config dari server (M1 plan/56) — bukan 5% hardcoded.
    var WD_CONFIG = <?= json_encode($wd_config); ?>;

    var amountInput = document.getElementById('wd_amount');
    var feeEl = document.getElementById('wd_fee');
    var netEl = document.getElementById('wd_net');
    var bpsLabelEl = document.getElementById('wd_bps_label');
    var submitBtn = document.getElementById('wd_submit');
    var noticeEl = document.getElementById('wdClosedNotice');
    var noticeTextEl = document.getElementById('wdClosedNoticeText');
    var balance = <?= (int) $balance ?>;

    var DAY_CODE = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 };

    // M8 parity (plan/74 §2.4): hanya integer bulat ^[1-9][0-9]*$ yang valid —
    // konsisten dengan validasi backend process_withdraw. parseAmount TIDAK
    // lagi strip-senyap (replace(/[^0-9]/g,'') mengubah "50000.50" → 5000050);
    // input non-integer dianggap INVALID (0), bukan dimanipulasi.
    function isIntegerAmount(str) {
        return /^[1-9][0-9]*$/.test(str);
    }

    function parseAmount(str) {
        return isIntegerAmount(str) ? parseInt(str, 10) : 0;
    }

    var amountErrorEl = document.getElementById('wdAmountError');

    function showAmountError(msg) {
        if (!amountErrorEl) { return; }
        if (msg) {
            amountErrorEl.textContent = msg;
            amountErrorEl.classList.remove('hidden');
        } else {
            amountErrorEl.textContent = '';
            amountErrorEl.classList.add('hidden');
        }
    }

    function formatRupiah(num) {
        return 'Rp ' + num.toLocaleString('id-ID');
    }

    // ===== WIB wall-clock via Intl (device timezone tidak relevan) =====
    function wibNow() {
        var parts = {};
        new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Asia/Jakarta',
            weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false
        }).formatToParts(new Date()).forEach(function (p) {
            if (p.type !== 'literal') { parts[p.type] = p.value; }
        });
        var hour = parseInt(parts.hour, 10);
        if (hour === 24) { hour = 0; } // engine '24:xx' untuk tengah malam
        return {
            day: DAY_CODE[parts.weekday] || 0,
            hm: String(hour).padStart(2, '0') + ':' + parts.minute
        };
    }

    function openState() {
        var now = wibNow();
        var days = WD_CONFIG.operational_days.split(',').map(function (s) { return parseInt(s, 10); });
        if (days.indexOf(now.day) === -1) {
            return { open: false, code: 'closed_day', msg: 'Hari ini bukan hari operasional penarikan.' };
        }
        if (now.hm < WD_CONFIG.open_time || now.hm > WD_CONFIG.close_time) {
            return { open: false, code: 'closed_time', msg: 'Penarikan hanya dapat diajukan pada pukul ' + WD_CONFIG.open_time + '–' + WD_CONFIG.close_time + ' WIB.' };
        }
        return { open: true, code: 'open', msg: '' };
    }

    function calcFee(amount) {
        var tiers = WD_CONFIG.tiers;
        var bps = tiers[tiers.length - 1][2];
        for (var i = 0; i < tiers.length; i++) {
            if (amount >= tiers[i][0] && amount < tiers[i][1]) { bps = tiers[i][2]; break; }
        }
        var fee = Math.floor(amount * bps / 10000) + WD_CONFIG.fixed_fee; // integer IDR
        return { fee: fee, net: amount - fee, bps: bps };
    }

    function refresh() {
        var raw    = amountInput.value;
        var amount = parseAmount(raw);
        var op     = openState();

        // M8 parity: error inline saat input memuat non-integer
        // (mis. "50000.50") — jangan pernah menampilkan preview yang
        // berdasarkan nilai hasil strip.
        if (raw !== '' && !isIntegerAmount(raw)) {
            showAmountError('Nominal harus berupa bilangan bulat (angka saja tanpa titik atau desimal).');
        } else {
            showAmountError(null);
        }

        // Notice + disable saat di luar jam/hari operasional.
        if (!op.open) {
            noticeTextEl.textContent = op.msg;
            noticeEl.classList.remove('hidden');
            submitBtn.disabled = true;
        } else {
            noticeEl.classList.add('hidden');
        }

        var valid = amount >= WD_CONFIG.min_amount && amount <= WD_CONFIG.max_amount && amount <= balance;

        if (op.open && valid) {
            var res = calcFee(amount);
            var pct = (res.bps / 100).toLocaleString('id-ID');
            bpsLabelEl.textContent = '(' + pct + '% + Rp ' + WD_CONFIG.fixed_fee.toLocaleString('id-ID') + ')';
            feeEl.textContent = formatRupiah(res.fee);
            netEl.textContent = formatRupiah(res.net);
            submitBtn.disabled = false;
        } else {
            bpsLabelEl.textContent = '(tier)';
            feeEl.textContent = 'Rp 0';
            netEl.textContent = 'Rp 0';
            if (op.open) { submitBtn.disabled = true; } // nominal belum valid
        }
    }

    if (amountInput) {
        amountInput.addEventListener('input', refresh);
    }

    refresh();

    // M8 parity: backstop submit — cegah pengiriman (e.preventDefault()) bila
    // nominal bukan integer bulat atau di luar ambang, selaras dengan aturan
    // backend. Menutup jalur selain klik tombol (mis. Enter / autofill).
    var wdForm = document.getElementById('withdrawForm');
    if (wdForm) {
        wdForm.addEventListener('submit', function(e) {
            var raw    = amountInput.value;
            var amount = parseAmount(raw);
            var op     = openState();
            var within = amount >= WD_CONFIG.min_amount
                      && amount <= WD_CONFIG.max_amount
                      && amount <= balance;
            if (!isIntegerAmount(raw) || !op.open || !within) {
                e.preventDefault();
                if (raw !== '' && !isIntegerAmount(raw)) {
                    showAmountError('Nominal harus berupa bilangan bulat (angka saja tanpa titik atau desimal).');
                } else {
                    showAmountError('Nominal penarikan tidak valid.');
                }
                amountInput.focus();
            }
        });
    }

    // Segarkan status jam operasional setiap 30 detik (halaman bisa terbuka
    // melewati batas buka/tutup).
    setInterval(refresh, 30000);
})();
</script>
