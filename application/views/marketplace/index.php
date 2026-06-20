<div class="p-4 pb-24 space-y-4">

    <!-- ═══ Page Header ═══ -->
    <div>
        <h2 class="text-xl font-extrabold text-slate-900 tracking-tight">Katalog Infrastruktur</h2>
        <p class="text-sm text-slate-500 mt-1">Pilih node sesuai kebutuhan Anda.</p>
    </div>

    <!-- ═══ Product Cards ═══ -->
    <?php foreach ($products as $product): ?>
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-100 flex flex-col">
        <img src="https://placehold.co/400x150/f8fafc/94a3b8?text=<?= urlencode($product['name']) ?>" class="rounded-xl object-cover h-28 w-full mb-3" alt="<?= htmlspecialchars($product['name']) ?>">

        <h3 class="text-base font-bold text-slate-900"><?= htmlspecialchars($product['name']) ?></h3>
        <p class="text-xs text-slate-500 mt-1 leading-relaxed"><?= htmlspecialchars($product['description'] ?? '') ?></p>

        <div class="flex items-center gap-4 mt-3">
            <div>
                <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">Harga Sewa</span>
                <p class="text-lg font-extrabold text-slate-900">Rp <?= number_format($product['price'], 0, ',', '.') ?></p>
            </div>
            <div class="ml-auto text-right">
                <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">ROI Harian</span>
                <p class="text-sm font-bold text-emerald-500">Rp <?= number_format($product['daily_roi'], 0, ',', '.') ?></p>
            </div>
        </div>

        <button class="btn-sewa w-full h-12 bg-slate-900 hover:bg-slate-800 text-white rounded-xl font-bold mt-3 transition-all active:scale-[0.98]"
                data-id="<?= $product['id'] ?>"
                data-name="<?= htmlspecialchars($product['name']) ?>"
                data-price="<?= $product['price'] ?>">
            Sewa Sekarang
        </button>
    </div>
    <?php endforeach; ?>

</div>

<!-- ═══ Bottom Sheet Modal ═══ -->
<div id="transactionModal" class="fixed inset-0 z-[60] hidden">
    <!-- Overlay -->
    <div id="modalOverlay" class="absolute inset-0 bg-black/60 backdrop-blur-sm transition-opacity"></div>

    <!-- Sheet -->
    <div id="modalSheet" class="absolute bottom-0 w-full max-w-[480px] mx-auto bg-white rounded-t-3xl p-6 pb-12 translate-y-full transition-transform duration-300 ease-out shadow-2xl">
        <!-- Handle -->
        <div class="w-10 h-1 bg-slate-300 rounded-full mx-auto mb-5"></div>

        <h3 class="text-lg font-bold text-slate-900 mb-4" id="modalTitle">Detail Transaksi</h3>

        <div class="space-y-3 mb-6">
            <div class="flex justify-between items-center">
                <span class="text-sm text-slate-500">Produk</span>
                <span class="text-sm font-semibold text-slate-900" id="modalProductName">-</span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-slate-500">Harga Sewa</span>
                <span class="text-sm font-bold text-slate-900" id="modalProductPrice">-</span>
            </div>
            <div class="h-px bg-slate-100"></div>
            <div class="flex justify-between items-center">
                <span class="text-sm text-slate-500">Saldo Anda</span>
                <span class="text-sm font-semibold" id="modalBalance">-</span>
            </div>
        </div>

        <div id="modalActionBtn"></div>
    </div>
</div>

<script>
(function() {
    const modal          = document.getElementById('transactionModal');
    const overlay        = document.getElementById('modalOverlay');
    const sheet          = document.getElementById('modalSheet');
    const productNameEl  = document.getElementById('modalProductName');
    const productPriceEl = document.getElementById('modalProductPrice');
    const balanceEl      = document.getElementById('modalBalance');
    const actionBtn      = document.getElementById('modalActionBtn');

    const userBalance = <?= (int) $user_balance ?>;
    const baseUrl     = '<?= base_url() ?>';

    const IDR = new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0
    });

    function openModal(data) {
        productNameEl.textContent  = data.name;
        productPriceEl.textContent = IDR.format(data.price);
        balanceEl.textContent      = IDR.format(userBalance);

        if (userBalance >= data.price) {
            balanceEl.className = 'text-sm font-semibold text-emerald-600';
            actionBtn.innerHTML =
                '<form method="POST" action="' + baseUrl + 'rentals/create" class="space-y-3">' +
                    '<input type="hidden" name="product_id" value="' + data.id + '">' +
                    '<button type="submit" class="w-full h-14 bg-blue-600 hover:bg-blue-700 text-white rounded-2xl font-bold shadow-lg transition-all flex items-center justify-center gap-2">' +
                        '<i class="fas fa-lock"></i> Konfirmasi & Bayar' +
                    '</button>' +
                '</form>';
        } else {
            balanceEl.className = 'text-sm font-semibold text-rose-600';
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
        }, 300);
    }

    // Attach to all product buttons
    document.querySelectorAll('.btn-sewa').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openModal({
                id:    this.dataset.id,
                name:  this.dataset.name,
                price: parseInt(this.dataset.price, 10)
            });
        });
    });

    // Close on overlay tap
    overlay.addEventListener('click', closeModal);
})();
</script>
