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
    <div class="bg-slate-900 text-white p-6 rounded-2xl shadow-xl relative overflow-hidden">
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
    <div id="topup-form-container" class="hidden transition-all duration-300 ease-in-out origin-top bg-white rounded-2xl p-5 shadow-sm">
        <h3 class="text-sm font-bold text-slate-900 mb-3 flex items-center gap-2">
            <i class="fas fa-wallet text-blue-500"></i> Isi Saldo
        </h3>

        <?= form_open('wallet/topup', 'id="topupForm"'); ?>

            <!-- Quick Amount Grid -->
            <div class="grid grid-cols-4 gap-2 mb-4">
                <button type="button" data-amount="100000" class="amt-btn bg-slate-50 hover:bg-blue-50 border border-slate-200 text-slate-700 text-xs font-bold py-2.5 rounded-xl transition">100K</button>
                <button type="button" data-amount="250000" class="amt-btn bg-slate-50 hover:bg-blue-50 border border-slate-200 text-slate-700 text-xs font-bold py-2.5 rounded-xl transition">250K</button>
                <button type="button" data-amount="500000" class="amt-btn bg-slate-50 hover:bg-blue-50 border border-slate-200 text-slate-700 text-xs font-bold py-2.5 rounded-xl transition">500K</button>
                <button type="button" data-amount="1000000" class="amt-btn bg-slate-50 hover:bg-blue-50 border border-slate-200 text-slate-700 text-xs font-bold py-2.5 rounded-xl transition">1M</button>
                <button type="button" data-amount="1500000" class="amt-btn bg-slate-50 hover:bg-blue-50 border border-slate-200 text-slate-700 text-xs font-bold py-2.5 rounded-xl transition">1.5M</button>
                <button type="button" data-amount="2000000" class="amt-btn bg-slate-50 hover:bg-blue-50 border border-slate-200 text-slate-700 text-xs font-bold py-2.5 rounded-xl transition">2M</button>
                <button type="button" data-amount="2500000" class="amt-btn bg-slate-50 hover:bg-blue-50 border border-slate-200 text-slate-700 text-xs font-bold py-2.5 rounded-xl transition">2.5M</button>
                <button type="button" onclick="setCustomAmount()" class="bg-white border-2 border-dashed border-slate-300 text-slate-400 text-xs font-bold py-2.5 rounded-xl hover:border-blue-400 hover:text-blue-500 transition">
                    <i class="fas fa-pen text-[10px]"></i> Lain
                </button>
            </div>

            <!-- Amount Input -->
            <div class="relative mb-4">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-bold">Rp</span>
                <input type="text" id="amountDisplay" readonly class="w-full bg-slate-50 border border-slate-200 rounded-xl py-3 pl-12 pr-4 text-right text-lg font-bold text-slate-900 outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="0">
                <input type="hidden" name="amount" id="amountHidden" value="">
            </div>

            <!-- Submit -->
            <button type="submit" id="submitBtn" disabled class="w-full bg-blue-600 text-white text-sm font-bold py-3 rounded-xl transition disabled:opacity-40 disabled:cursor-not-allowed hover:bg-blue-700">
                <i class="fas fa-lock mr-1"></i> Lanjutkan Pembayaran
            </button>

        <?= form_close(); ?>
    </div>

    <!-- ===== PENDING TRANSACTIONS ===== -->
    <?php if (!empty($pending)): ?>
    <div class="bg-white rounded-2xl p-5 shadow-sm">
        <h3 class="text-sm font-bold text-slate-900 mb-3 flex items-center gap-2">
            <i class="fas fa-clock text-amber-500"></i> Menunggu Pembayaran
        </h3>
        <div class="space-y-2">
            <?php foreach ($pending as $row): ?>
            <div class="bg-amber-50 border border-amber-100 rounded-xl p-3">
                <div class="text-xs font-mono text-slate-500 truncate"><?= $row->invoice_number ?></div>
                <div class="text-sm font-bold text-slate-900">Rp <?= number_format($row->amount, 0, ',', '.') ?></div>
                <span class="inline-block mt-1 text-[10px] font-bold text-amber-600 bg-amber-100 px-2 py-0.5 rounded-full uppercase">Menunggu Pembayaran</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== PENDING WITHDRAWALS ===== -->
    <?php if (!empty($pending_withdrawals)): ?>
        <div class="mb-6">
            <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-3">Penarikan Tertunda (Pending)</h3>
            <div class="space-y-3">
                <?php foreach ($pending_withdrawals as $wd): ?>
                    <div class="bg-orange-50 border border-orange-200 rounded-2xl p-4 flex flex-col gap-3 shadow-sm">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-xs font-bold text-orange-600 mb-1"><?= $wd->wd_number ?></p>
                                <p class="text-sm font-bold text-slate-800"><?= $wd->bank_name ?> - <?= $wd->account_number ?></p>
                                <p class="text-xs text-slate-500 uppercase"><?= $wd->account_name ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-slate-800">Rp <?= number_format($wd->amount, 0, ',', '.') ?></p>
                                <p class="text-xs font-semibold text-orange-500">Pending</p>
                            </div>
                        </div>
                        <span class="inline-block text-[10px] font-bold text-orange-600 bg-orange-100 px-2 py-0.5 rounded-full uppercase">Menunggu Persetujuan</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ===== LEDGER HISTORY ===== -->
    <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
        <div class="px-5 pt-5 pb-2 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                <i class="fas fa-receipt text-slate-400"></i> Riwayat Transaksi
            </h3>
            <?php if (!empty($ledger)): ?>
            <span class="text-[10px] font-bold text-slate-400 bg-slate-100 px-2 py-1 rounded-full"><?= count($ledger) ?> transaksi</span>
            <?php endif; ?>
        </div>

        <?php if (empty($ledger)): ?>
            <div class="px-5 pb-5 text-center py-8">
                <div class="text-4xl mb-2 opacity-30">📄</div>
                <p class="text-xs text-slate-400">Belum ada transaksi</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-50">
                <?php foreach ($ledger as $row): ?>
                <div class="px-5 py-3 flex items-center justify-between hover:bg-slate-50 transition">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center <?= $row->type === 'credit' ? 'bg-emerald-100 text-emerald-600' : 'bg-rose-100 text-rose-600' ?>">
                            <i class="fas fa-<?= $row->type === 'credit' ? 'arrow-down' : 'arrow-up' ?> text-xs"></i>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-slate-900 truncate max-w-[220px]"><?= $row->description ?></div>
                            <div class="text-[10px] text-slate-400 font-mono"><?= date('d M Y, H:i', strtotime($row->created_at)) ?></div>
                        </div>
                    </div>
                    <div class="text-sm font-bold font-mono <?= $row->type === 'credit' ? 'text-emerald-600' : 'text-slate-800' ?>">
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
var INACTIVE = BASE + ' bg-slate-50 hover:bg-blue-50 border border-slate-200 text-slate-700';
var ACTIVE   = BASE + ' bg-blue-600 hover:bg-blue-500 border border-blue-600 text-white shadow-md';

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
    if (custom) {
        var val = parseInt(custom.replace(/[^0-9]/g, ''), 10);
        if (val > 0) {
            resetAllBtns();
            setAmount(val);
        }
    }
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
</script>