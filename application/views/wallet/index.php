<div class="p-4 space-y-5">

    <!-- Flash Messages -->
    <?php if ($this->session->flashdata('success')): ?>
        <div class="bg-emerald-500 text-white text-sm font-semibold px-4 py-3 rounded-xl flex items-center gap-2 shadow-lg animate-bounce-in">
            <i class="fas fa-check-circle"></i>
            <?= $this->session->flashdata('success') ?>
        </div>
    <?php endif; ?>
    <?php if ($this->session->flashdata('error')): ?>
        <div class="bg-rose-500 text-white text-sm font-semibold px-4 py-3 rounded-xl flex items-center gap-2 shadow-lg">
            <i class="fas fa-exclamation-circle"></i>
            <?= $this->session->flashdata('error') ?>
        </div>
    <?php endif; ?>

    <!-- ===== TOP CARD: BALANCE (Terminal Aesthetic) ===== -->
    <div class="u-card-fin text-white p-6 rounded-2xl shadow-xl relative overflow-hidden">
        <!-- Subtle grid overlay -->
        <div class="absolute inset-0 opacity-5" style="background-image: linear-gradient(rgba(255,255,255,0.1) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.1) 1px, transparent 1px); background-size: 20px 20px;"></div>

        <div class="relative z-10">
            <div class="flex items-center justify-between mb-1">
                <span class="text-slate-400 text-xs font-medium tracking-wider uppercase">Total Saldo</span>
                <span class="text-emerald-400 text-[10px] font-mono bg-emerald-400/10 px-2 py-0.5 rounded-full">AKTIF</span>
            </div>
            <div class="text-3xl font-bold font-mono tracking-tight mb-4">
                Rp <?= number_format($balance, 0, ',', '.') ?>
            </div>

            <div class="flex gap-2">
                <button type="button" id="btn-toggle-topup" class="flex-1 bg-blue-600 hover:bg-blue-500 text-white text-sm font-bold py-2.5 rounded-xl transition">
                    <i class="fas fa-plus mr-1"></i> Top Up
                </button>
                <?php if (!empty($has_pending_wd)): ?>
                <button disabled class="flex-1 bg-slate-600 text-slate-300 text-sm font-bold py-2.5 rounded-xl cursor-not-allowed opacity-60">
                    <i class="fas fa-hourglass-half mr-1"></i> Menunggu Persetujuan
                </button>
                <?php elseif (empty($has_active_rental)): ?>
                <button disabled class="flex-1 bg-slate-400 text-white text-sm font-bold py-2.5 rounded-xl cursor-not-allowed opacity-60">
                    <i class="fas fa-lock mr-1"></i> Pinjaman Aktif
                </button>
                <?php elseif (!empty($daily_limit_reached)): ?>
                <button disabled class="flex-1 bg-slate-600 text-slate-300 text-sm font-bold py-2.5 rounded-xl cursor-not-allowed opacity-60">
                    <i class="fas fa-calendar-check mr-1"></i> Batas Harian
                </button>
                <?php else: ?>
                <a href="<?= base_url('wallet/withdraw') ?>" class="flex-1 bg-orange-500 hover:bg-orange-400 text-white text-sm font-bold py-2.5 rounded-xl transition text-center no-underline">
                    <i class="fas fa-arrow-down mr-1"></i> Tarik Dana
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== TOP-UP FORM ===== -->
    <div id="topup-form-container" class="hidden transition-all duration-300 ease-in-out origin-top u-card rounded-2xl p-5 shadow-sm">
        <h3 class="text-sm font-bold u-text mb-3 flex items-center gap-2">
            <i class="fas fa-wallet text-blue-500"></i> Isi Saldo
        </h3>

        <?= form_open('wallet/topup', ['id' => 'topupForm', 'data-guard-submit' => '1']); ?>

            <!-- Quick Amount Grid -->
            <div class="grid grid-cols-4 gap-2 mb-4">
                <button type="button" data-amount="100000" class="amt-btn u-amt-inactive text-xs font-bold py-2.5 rounded-xl transition">100K</button>
                <button type="button" data-amount="250000" class="amt-btn u-amt-inactive text-xs font-bold py-2.5 rounded-xl transition">250K</button>
                <button type="button" data-amount="500000" class="amt-btn u-amt-inactive text-xs font-bold py-2.5 rounded-xl transition">500K</button>
                <button type="button" data-amount="1000000" class="amt-btn u-amt-inactive text-xs font-bold py-2.5 rounded-xl transition">1M</button>
                <button type="button" data-amount="1500000" class="amt-btn u-amt-inactive text-xs font-bold py-2.5 rounded-xl transition">1.5M</button>
                <button type="button" data-amount="2000000" class="amt-btn u-amt-inactive text-xs font-bold py-2.5 rounded-xl transition">2M</button>
                <button type="button" data-amount="2500000" class="amt-btn u-amt-inactive text-xs font-bold py-2.5 rounded-xl transition">2.5M</button>
                <button type="button" onclick="setCustomAmount()" class="bg-transparent border-2 border-dashed border-slate-300 dark:border-slate-600 text-slate-400 dark:text-slate-500 text-xs font-bold py-2.5 rounded-xl hover:border-blue-400 dark:hover:border-blue-500 hover:text-blue-500 dark:hover:text-blue-400 transition">
                    <i class="fas fa-pen text-[10px]"></i> Lain
                </button>
            </div>

            <!-- Amount Input -->
            <div class="relative mb-4">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-bold">Rp</span>
                <input type="text" id="amountDisplay" readonly class="u-input w-full rounded-xl py-3 pl-12 pr-4 text-right text-lg font-bold focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="0">
                <input type="hidden" name="amount" id="amountHidden" value="">
            </div>

            <!-- M1 (plan/56 §4.3): breakdown biaya deposit dinamis sebelum invoice -->
            <div id="depositFeeBreakdown" class="hidden mb-4 bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 rounded-xl p-3 text-[11px] font-bold text-emerald-700 dark:text-emerald-300 leading-relaxed">
                <span id="depositFeeBreakdownText"></span>
            </div>

            <!-- Submit -->
            <button type="submit" id="submitBtn" disabled class="w-full bg-blue-600 text-white text-sm font-bold py-3 rounded-xl transition disabled:opacity-40 disabled:cursor-not-allowed hover:bg-blue-700">
                <i class="fas fa-lock mr-1"></i> Lanjutkan Pembayaran
            </button>

        <?= form_close(); ?>
    </div>

    <!-- ===== PENDING TRANSACTIONS ===== -->
    <?php if (!empty($pending)): ?>
    <div class="u-card rounded-2xl p-5 shadow-sm">
        <h3 class="text-sm font-bold u-text mb-3 flex items-center gap-2">
            <i class="fas fa-clock text-amber-500"></i> Menunggu Pembayaran
        </h3>
        <div class="space-y-2">
            <?php foreach ($pending as $row): ?>
            <?php
                // M1 (plan/56 §4.3) + invoice hierarchy fix: angka PRIMER yang
                // ditampilkan besar adalah total yang HARUS ditransfer
                // (total_payable), bukan pokok — mencegah underpayment.
                $has_fee  = !empty($deposit_fee_enabled) && !empty($row->deposit_fee);
                // M8: nilai finansial sudah integer di sumbernya (controller/
                // model) — tampilkan & salin sebagai int, tanpa cast float.
                $primary  = (int) ($has_fee ? $row->total_payable : $row->amount);
                $copy_val = $primary;
            ?>
            <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-100 dark:border-amber-500/20 rounded-xl p-4">
                <!-- Header: invoice + copy nominal -->
                <div class="flex items-start justify-between gap-2">
                    <div class="text-xs font-mono u-text-2 truncate min-w-0"><?= $row->invoice_number ?></div>
                    <button type="button"
                            class="btn-copy-nominal shrink-0 flex items-center gap-1.5 text-[10px] font-bold text-amber-700 dark:text-amber-300 bg-white/70 dark:bg-black/20 border border-amber-200 dark:border-amber-500/20 px-2 py-1 rounded-lg hover:bg-white dark:hover:bg-black/30 transition active:scale-95"
                            data-copy="<?= $copy_val ?>"
                            title="Salin nominal transfer">
                        <i class="fas fa-copy text-[9px]"></i> Salin Nominal
                    </button>
                </div>

                <!-- Primary figure: TOTAL TAGIHAN / NOMINAL TRANSFER -->
                <div class="mt-2">
                    <div class="text-[10px] uppercase tracking-widest u-muted font-bold">Total Tagihan / Nominal Transfer</div>
                    <div class="text-2xl font-extrabold u-text font-mono tracking-tight leading-tight">Rp <?= number_format($primary, 0, ',', '.') ?></div>
                </div>

                <!-- Breakdown (hanya saat fee > 0; fee 0/non-aktif → tanpa baris redundan) -->
                <?php if ($has_fee): ?>
                <div class="mt-2 rounded-lg bg-white/70 dark:bg-black/20 border border-amber-200/80 dark:border-amber-500/15 px-3 py-2 space-y-1">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="u-muted">Saldo Masuk (Pokok)</span>
                        <span class="font-mono font-bold u-text">Rp <?= number_format((int) $row->amount, 0, ',', '.') ?></span>
                    </div>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="u-muted">Biaya Layanan</span>
                        <span class="font-mono font-bold u-text">Rp <?= number_format((int) $row->deposit_fee, 0, ',', '.') ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <span class="inline-block mt-2 text-[10px] font-bold text-amber-600 dark:text-amber-400 bg-amber-100 dark:bg-amber-500/10 px-2 py-0.5 rounded-full uppercase">Menunggu Pembayaran</span>
                <?php if (ENVIRONMENT !== 'production'): ?>
                <!-- C1 (plan 38): simulasi pembayaran HANYA untuk development/UAT —
                     tidak pernah dirender di production. POST + CSRF (form_open). -->
                <?= form_open('wallet/simulate_payment/' . $row->invoice_number, ['class' => 'mt-2', 'data-guard-submit' => '1']); ?>
                    <button type="submit" class="w-full bg-amber-500 hover:bg-amber-400 text-white text-xs font-bold py-2 rounded-lg transition">
                        <i class="fas fa-flask mr-1"></i> Simulasi Pembayaran (Dev Only)
                    </button>
                <?= form_close(); ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== PENDING WITHDRAWALS ===== -->
    <?php if (!empty($pending_withdrawals)): ?>
        <div class="mb-6">
            <h3 class="text-sm font-bold u-text-2 uppercase tracking-wider mb-3">Penarikan Tertunda (Pending)</h3>
            <div class="space-y-3">
                <?php foreach ($pending_withdrawals as $wd): ?>
                    <div class="bg-orange-50 dark:bg-orange-500/10 border border-orange-200 dark:border-orange-500/20 rounded-2xl p-4 flex flex-col gap-3 shadow-sm">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-xs font-bold text-orange-600 dark:text-orange-400 mb-1"><?= $wd->wd_number ?></p>
                                <p class="text-sm font-bold u-text"><?= $wd->bank_name ?> - <?= $wd->account_number ?></p>
                                <p class="text-xs u-text-2 uppercase"><?= $wd->account_name ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold u-text">Rp <?= number_format($wd->amount, 0, ',', '.') ?></p>
                                <p class="text-xs font-semibold text-orange-500 dark:text-orange-400">Pending</p>
                            </div>
                        </div>
                        <span class="inline-block text-[10px] font-bold text-orange-600 dark:text-orange-400 bg-orange-100 dark:bg-orange-500/10 px-2 py-0.5 rounded-full uppercase">Menunggu Persetujuan</span>
                        <?php if (ENVIRONMENT !== 'production'): ?>
                        <!-- C7 (plan 42): simulasi persetujuan WD HANYA untuk development/UAT —
                             tidak pernah dirender di production. POST + CSRF (form_open). -->
                        <?= form_open('wallet/simulate_wd_approve/' . $wd->wd_number, ['class' => 'mt-2', 'data-guard-submit' => '1']); ?>
                            <button type="submit" class="w-full bg-orange-500 hover:bg-orange-400 text-white text-xs font-bold py-2 rounded-lg transition">
                                <i class="fas fa-flask mr-1"></i> Simulasi Persetujuan (Dev Only)
                            </button>
                        <?= form_close(); ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ===== LEDGER HISTORY ===== -->
    <div class="u-card rounded-2xl shadow-sm overflow-hidden">
        <div class="px-5 pt-5 pb-2 flex items-center justify-between">
            <h3 class="text-sm font-bold u-text flex items-center gap-2">
                <i class="fas fa-receipt u-muted"></i> Riwayat Transaksi
            </h3>
            <?php if (!empty($ledger)): ?>
            <span class="text-[10px] font-bold text-slate-400 dark:text-slate-300 bg-slate-100 dark:bg-slate-700 px-2 py-1 rounded-full"><?= count($ledger) ?> transaksi</span>
            <?php endif; ?>
        </div>

        <?php if (empty($ledger)): ?>
            <div class="px-5 pb-5 text-center py-8">
                <div class="text-4xl mb-2 opacity-30">📄</div>
                <p class="text-xs u-muted">Belum ada transaksi</p>
            </div>
        <?php else: ?>
            <div class="u-divide">
                <?php foreach ($ledger as $row): ?>
                <div class="px-5 py-3 flex items-center justify-between u-row-hover transition">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center <?= $row->type === 'credit' ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-rose-100 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400' ?>">
                            <i class="fas fa-<?= $row->type === 'credit' ? 'arrow-down' : 'arrow-up' ?> text-xs"></i>
                        </div>
                        <div>
                            <div class="text-xs font-medium u-text truncate max-w-[220px]"><?= $row->description ?></div>
                            <div class="text-[10px] u-muted font-mono"><?= date('d M Y, H:i', strtotime($row->created_at)) ?></div>
                        </div>
                    </div>
                    <div class="text-sm font-bold font-mono <?= $row->type === 'credit' ? 'text-emerald-600 dark:text-emerald-400' : 'u-text' ?>">
                        <?= $row->type === 'credit' ? '+' : '-' ?> Rp <?= number_format($row->amount, 0, ',', '.') ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>


<!-- ===== JAVASCRIPT ===== -->
<script>
/* --- Top-up Quick Amount Buttons --- */
var BASE   = 'amt-btn text-xs font-bold py-2.5 rounded-xl transition';
var INACTIVE = BASE + ' u-amt-inactive';
var ACTIVE   = BASE + ' bg-blue-600 hover:bg-blue-500 border border-blue-600 text-white shadow-md';

/* --- M1 (plan/56 §4.3): dynamic deposit fee config dari server --- */
var DEP_FEE = {
    enabled: <?= (int) $deposit_fee_enabled ?>,
    type: <?= json_encode($deposit_fee_type) ?>,
    value: <?= json_encode((float) $deposit_fee_value) ?>
};
var depFeeBox = document.getElementById('depositFeeBreakdown');
var depFeeText = document.getElementById('depositFeeBreakdownText');

function depositFeeOf(amount) {
    if (!DEP_FEE.enabled || amount <= 0) { return 0; }
    if (DEP_FEE.type === 'flat') { return DEP_FEE.value; }
    return Math.floor(amount * DEP_FEE.value / 100); // percent points, integer IDR
}

function updateDepositFee(amount) {
    if (!DEP_FEE.enabled || amount <= 0) {
        depFeeBox.classList.add('hidden');
        depFeeText.textContent = '';
        return;
    }
    var fee = depositFeeOf(amount);
    var total = amount + fee;
    depFeeText.textContent = 'Pokok Rp ' + amount.toLocaleString('id-ID')
        + ' + Biaya Rp ' + fee.toLocaleString('id-ID')
        + ' = Total Dibayar Rp ' + total.toLocaleString('id-ID');
    depFeeBox.classList.remove('hidden');
}

function formatIDR(num) {
    return 'Rp ' + num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function resetAllBtns() {
    document.querySelectorAll('.amt-btn').forEach(function(btn) {
        btn.className = INACTIVE;
    });
}

function setAmount(val) {
    document.getElementById('amountDisplay').value = formatIDR(val);
    document.getElementById('amountHidden').value = val;
    document.getElementById('submitBtn').disabled = false;
    updateDepositFee(val);
}

document.querySelectorAll('.amt-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        var clicked = e.currentTarget;
        var amount  = parseInt(clicked.getAttribute('data-amount'), 10);
        resetAllBtns();
        clicked.className = ACTIVE;
        setAmount(amount);
    });
});

function setCustomAmount() {
    var custom = prompt('Masukkan nominal (angka saja):\nContoh: 750000');
    if (!custom) { return; } // batal / kosong

    // M8 parity (plan/74 §2.4): tolak karakter non-digit (titik, koma,
    // minus, 'e', spasi, dll) SECARA EKSPLISIT. Strip regex lama diam-diam
    // mengubah "50000.50" → 5000050, sehingga validasi integer backend
    // tidak pernah terpanggil. Hanya string bulat ^[1-9][0-9]*$ diterima.
    if (!/^[1-9][0-9]*$/.test(custom)) {
        alert('Nominal harus berupa bilangan bulat (angka saja tanpa titik atau desimal).');
        return; // abort — jangan mengisi form dengan nilai yang dimanipulasi
    }

    var val = parseInt(custom, 10);
    resetAllBtns();
    setAmount(val);
}

/* --- Top-up Toggle --- */
var btnToggleTopup = document.getElementById('btn-toggle-topup');
var topupFormContainer = document.getElementById('topup-form-container');

if(btnToggleTopup && topupFormContainer) {
    btnToggleTopup.addEventListener('click', function() {
        topupFormContainer.classList.toggle('hidden');
        if(!topupFormContainer.classList.contains('hidden')) {
            topupFormContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    });
}

/* --- Pending invoice: Salin Nominal (copy exact transfer integer, e.g. 104000) --- */
function flashCopyLabel(btn) {
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check text-[9px]"></i> Tersalin';
    setTimeout(function() { btn.innerHTML = orig; }, 1500);
}

document.querySelectorAll('.btn-copy-nominal').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var val = btn.getAttribute('data-copy'); // integer tanpa format (mis. 104000)
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(val).then(function() { flashCopyLabel(btn); });
        } else {
            var ta = document.createElement('textarea');
            ta.value = val;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); flashCopyLabel(btn); } catch (e) {}
            document.body.removeChild(ta);
        }
    });
});
</script>