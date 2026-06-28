<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Mask account number: first 4 + asterisks + last 3
$acc = $bank->account_number;
$len = strlen($acc);
if ($len > 7) {
    $masked = substr($acc, 0, 4) . str_repeat('*', $len - 7) . substr($acc, -3);
} else {
    $masked = $acc;
}
?>

<div class="p-4 space-y-6">
    <!-- Header -->
    <div class="flex items-center gap-3 mb-6">
        <a href="<?= base_url('wallet'); ?>" class="w-8 h-8 bg-white border border-slate-200 rounded-full flex items-center justify-center text-slate-500 shadow-sm active:scale-90 transition-all">
            <i class="fas fa-arrow-left text-xs"></i>
        </a>
        <h2 class="text-xl font-extrabold text-slate-900 tracking-tight"><?= $page_title ?></h2>
    </div>

    <!-- Flash Messages -->
    <?php if ($this->session->flashdata('success')): ?>
        <div class="bg-emerald-100 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl text-xs font-bold flex items-center gap-3 shadow-sm">
            <i class="fas fa-check-circle"></i>
            <?= $this->session->flashdata('success'); ?>
        </div>
    <?php endif; ?>

    <?php if ($this->session->flashdata('error')): ?>
        <div class="bg-rose-100 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-xs font-bold flex items-center gap-3 shadow-sm">
            <i class="fas fa-exclamation-circle"></i>
            <?= $this->session->flashdata('error'); ?>
        </div>
    <?php endif; ?>

    <!-- ===== READ-ONLY BANK CARD ===== -->
    <div class="bg-slate-900 text-white p-4 rounded-xl mb-6 relative overflow-hidden">
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
    <?= form_open('wallet/process_withdraw', 'id="withdrawForm"'); ?>

        <!-- Balance Info -->
        <div class="bg-white border border-slate-200 rounded-xl p-4 mb-4 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs text-slate-500 font-bold">Saldo Tersedia</span>
                <span class="text-sm font-extrabold text-slate-900 font-mono">Rp <?= number_format($balance, 0, ',', '.'); ?></span>
            </div>
        </div>

        <!-- Amount Input -->
        <div class="mb-4">
            <label class="block text-[10px] uppercase tracking-widest text-slate-400 font-bold mb-1.5">Nominal Penarikan</label>
            <div class="relative">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-bold">Rp</span>
                <input type="text"
                       name="amount"
                       id="wd_amount"
                       placeholder="0"
                       class="w-full h-14 pl-12 pr-4 rounded-xl border border-slate-200 bg-slate-50 text-lg font-mono font-bold text-slate-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all text-right"
                       inputmode="numeric"
                       required>
            </div>
            <p class="mt-1.5 text-xs text-slate-500">Minimal penarikan: Rp 100.000</p>
        </div>

        <!-- Fee Display -->
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 mb-4">
            <div class="flex items-center justify-between mb-1">
                <span class="text-[11px] text-amber-700 font-bold">Biaya Admin (5%)</span>
                <span class="text-[11px] font-mono font-bold text-amber-700" id="wd_fee">Rp 0</span>
            </div>
            <div class="flex items-center justify-between border-t border-amber-200 pt-2 mt-2">
                <span class="text-[11px] text-amber-800 font-extrabold">Diterima</span>
                <span class="text-sm font-mono font-extrabold text-amber-800" id="wd_net">Rp 0</span>
            </div>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="w-full h-14 bg-orange-500 hover:bg-orange-400 text-white rounded-xl text-sm font-extrabold shadow-lg transition-all active:scale-95">
            <i class="fas fa-paper-plane mr-2"></i> Ajukan Penarikan
        </button>

    <?= form_close(); ?>
</div>

<script>
(function() {
    var amountInput = document.getElementById('wd_amount');
    var feeEl = document.getElementById('wd_fee');
    var netEl = document.getElementById('wd_net');
    var balance = <?= (int) $balance ?>;

    function parseAmount(str) {
        return parseInt(str.replace(/[^0-9]/g, ''), 10) || 0;
    }

    function formatRupiah(num) {
        return 'Rp ' + num.toLocaleString('id-ID');
    }

    function calcFee() {
        var amount = parseAmount(amountInput.value);
        var fee = Math.floor(amount * 0.05);
        var net = amount - fee;
        if (amount > 0) {
            feeEl.textContent = formatRupiah(fee);
            netEl.textContent = formatRupiah(net);
        } else {
            feeEl.textContent = 'Rp 0';
            netEl.textContent = 'Rp 0';
        }
    }

    if (amountInput) {
        amountInput.addEventListener('input', calcFee);
    }
})();
</script>