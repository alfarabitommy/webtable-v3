<div class="p-4 pb-24 space-y-4">

    <!-- ═══ Page Header ═══ -->
    <div>
        <h2 class="text-xl font-extrabold u-text tracking-tight">Katalog Infrastruktur</h2>
        <p class="text-sm u-text-2 mt-1">Pilih node sesuai kebutuhan Anda.</p>
    </div>

    <!-- ═══ Flashdata Alerts ═══ -->
    <?php if ($this->session->flashdata('error')): ?>
    <div class="u-flash-error px-4 py-3 rounded-xl mb-4 text-sm font-semibold flex items-center gap-2">
        <i class="fas fa-exclamation-circle"></i> <?= $this->session->flashdata('error'); ?>
    </div>
    <?php endif; ?>
    <?php if ($this->session->flashdata('success')): ?>
    <div class="u-flash-success px-4 py-3 rounded-xl mb-4 text-sm font-semibold flex items-center gap-2">
        <i class="fas fa-check-circle"></i> <?= $this->session->flashdata('success'); ?>
    </div>
    <?php endif; ?>

    <!-- ═══ Product Cards (Phase 32: .u-card-gpu — neural surface + cyan glow border) ═══ -->
    <?php foreach ($products as $product): ?>
    <div class="u-card-gpu rounded-2xl p-4 shadow-sm flex flex-col">
        <img src="https://placehold.co/400x150/f8fafc/94a3b8?text=<?= urlencode($product['name']) ?>" class="rounded-xl object-cover h-28 w-full mb-3" alt="<?= htmlspecialchars($product['name']) ?>">

        <h3 class="text-base font-bold u-text"><?= htmlspecialchars($product['name']) ?></h3>
        <p class="text-xs u-text-2 mt-1 leading-relaxed"><?= htmlspecialchars($product['description'] ?? '') ?></p>

        <div class="flex items-center gap-4 mt-3">
            <div>
                <span class="text-[10px] u-muted font-semibold uppercase tracking-wider">Harga Sewa</span>
                <p class="text-lg font-extrabold u-text">Rp <?= number_format($product['price'], 0, ',', '.') ?></p>
            </div>
            <div class="ml-auto text-right">
                <span class="text-[10px] u-muted font-semibold uppercase tracking-wider">ROI Harian</span>
                <p class="text-sm font-bold text-emerald-500">Rp <?= number_format($product['daily_rate'], 0, ',', '.') ?></p>
            </div>
        </div>

        <button class="btn-sewa w-full h-12 u-btn-cyber rounded-xl font-bold mt-3 transition-all active:scale-[0.98]"
                data-id="<?= $product['id'] ?>"
                data-name="<?= htmlspecialchars($product['name']) ?>"
                data-price="<?= $product['price'] ?>">
            Sewa Sekarang
        </button>
    </div>
    <?php endforeach; ?>

</div>

<!-- ═══ Bottom Sheet Modal (Phase 32: .u-modal) ═══ -->
<div id="transactionModal" class="fixed inset-0 z-[60] hidden">
    <!-- Overlay -->
    <div id="modalOverlay" class="absolute inset-0 u-modal-backdrop backdrop-blur-sm transition-opacity"></div>

    <!-- Sheet -->
    <div id="modalSheet" class="absolute bottom-0 w-full max-w-[480px] mx-auto u-modal rounded-t-3xl p-6 pb-12 translate-y-full transition-transform duration-300 ease-out shadow-2xl">
        <!-- Handle -->
        <div class="w-10 h-1 bg-slate-300 dark:bg-slate-600 rounded-full mx-auto mb-5"></div>

        <h3 class="text-lg font-bold u-text mb-4" id="modalTitle">Detail Transaksi</h3>

        <div class="space-y-3 mb-6">
            <div class="flex justify-between items-center">
                <span class="text-sm u-text-2">Produk</span>
                <span class="text-sm font-semibold u-text" id="modalProductName">-</span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm u-text-2">Harga Sewa</span>
                <span class="text-sm font-bold u-text" id="modalProductPrice">-</span>
            </div>
            <div class="h-px" style="background-color: var(--u-divide);"></div>
            <div class="flex justify-between items-center">
                <span class="text-sm u-text-2">Saldo Anda</span>
                <span class="text-sm font-semibold" id="modalBalance">-</span>
            </div>
        </div>

        <?php echo form_open('rentals/checkout', ['id' => 'form-checkout', 'class' => 'w-full', 'data-guard-submit' => '1']); ?>
            <input type="hidden" name="product_id" id="modal_product_id" value="">
            <div id="modalActionBtn"></div>
        <?php echo form_close(); ?>
    </div>
</div>

<script>
(function() {
    var modal          = document.getElementById('transactionModal');
    var overlay        = document.getElementById('modalOverlay');
    var sheet          = document.getElementById('modalSheet');
    var productNameEl  = document.getElementById('modalProductName');
    var productPriceEl = document.getElementById('modalProductPrice');
    var balanceEl      = document.getElementById('modalBalance');
    var actionBtn      = document.getElementById('modalActionBtn');
    var productIdInput = document.getElementById('modal_product_id');

    var userBalance = <?= (int) $user_balance ?>;
    var baseUrl     = '<?= base_url() ?>';

    var IDR = new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0
    });

    function openModal(data) {
        productNameEl.textContent  = data.name;
        productPriceEl.textContent = IDR.format(data.price);
        balanceEl.textContent      = IDR.format(userBalance);

        // Set the hidden product_id value
        productIdInput.value = data.id;

        if (userBalance >= data.price) {
            balanceEl.className = 'text-sm font-semibold text-emerald-600 dark:text-emerald-400';
            actionBtn.innerHTML =
                '<button type="submit" class="w-full h-14 bg-blue-600 hover:bg-blue-700 text-white rounded-2xl font-bold shadow-lg transition-all flex items-center justify-center gap-2">' +
                    '<i class="fas fa-lock"></i> Konfirmasi & Bayar' +
                '</button>';
        } else {
            balanceEl.className = 'text-sm font-semibold text-rose-600 dark:text-rose-400';
            actionBtn.innerHTML =
                '<a href="' + baseUrl + 'wallet" class="block w-full h-14 bg-rose-500 hover:bg-rose-600 text-white rounded-2xl font-bold shadow-lg transition-all flex items-center justify-center gap-2">' +
                    '<i class="fas fa-wallet"></i> Saldo Tidak Mencukupi — Isi Saldo' +
                '</a>';
        }

        modal.classList.remove('hidden');
        requestAnimationFrame(function() {
            sheet.style.transform = 'translateY(0)';
        });
    }

    function closeModal() {
        sheet.style.transform = 'translateY(100%)';
        setTimeout(function() {
            modal.classList.add('hidden');
            actionBtn.innerHTML = '';
            productIdInput.value = '';
        }, 300);
    }

    document.querySelectorAll('.btn-sewa').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            var clickedBtn = e.currentTarget;
            openModal({
                id:    clickedBtn.getAttribute('data-id'),
                name:  clickedBtn.getAttribute('data-name'),
                price: parseFloat(clickedBtn.getAttribute('data-price'))
            });
        });
    });

    overlay.addEventListener('click', closeModal);
})();
</script>
