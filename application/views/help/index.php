<!-- ═══ Help / Bantuan — Phase 8B ═══ -->
<div class="p-4 space-y-6">

    <!-- ═══ Header ═══ -->
    <div>
        <h2 class="text-lg font-extrabold text-slate-900">Bantuan & FAQ</h2>
        <p class="text-xs text-slate-500 mt-1">Temukan jawaban atas pertanyaan umum seputar Synapse.</p>
    </div>

    <!-- ═══ Contact CTA ═══ -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
        <h3 class="text-sm font-bold text-slate-900 mb-3 flex items-center gap-2">
            <i class="fas fa-headset text-indigo-500"></i>
            Hubungi Support
        </h3>
        <p class="text-xs text-slate-500 mb-4">Belum menemukan jawaban? Tim kami siap membantu Anda.</p>
        <div class="grid grid-cols-2 gap-3">
            <!-- WhatsApp Button -->
            <a href="https://wa.me/<?= urlencode($wa_number) ?>?text=Halo%20Synapse%2C%20saya%20butuh%20bantuan..."
               target="_blank"
               rel="noopener"
               class="flex items-center justify-center gap-2 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white text-sm font-bold py-3 px-4 rounded-xl transition-all duration-200 active:scale-95 shadow-sm">
                <i class="fab fa-whatsapp text-lg"></i>
                <span>WhatsApp</span>
            </a>
            <!-- Email Button -->
            <a href="mailto:<?= urlencode($support_email) ?>?subject=Bantuan%20Synapse"
               class="flex items-center justify-center gap-2 bg-blue-500 hover:bg-blue-600 active:bg-blue-700 text-white text-sm font-bold py-3 px-4 rounded-xl transition-all duration-200 active:scale-95 shadow-sm">
                <i class="fas fa-envelope text-lg"></i>
                <span>Email</span>
            </a>
        </div>
    </div>

    <!-- ═══ FAQ Accordion ═══ -->
    <div class="space-y-3" id="faq-container">
        <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
            <i class="fas fa-question-circle text-indigo-500"></i>
            Pertanyaan Umum
        </h3>

        <!-- FAQ Item 1 -->
        <div class="faq-item bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <button onclick="toggleFaq(this)"
                    class="w-full flex items-center justify-between p-4 text-left gap-3">
                <span class="text-sm font-semibold text-slate-800">Apa itu Synapse?</span>
                <i class="fas fa-chevron-down text-slate-400 text-xs transition-transform duration-300 shrink-0 faq-icon"></i>
            </button>
            <div class="faq-answer max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                <div class="px-4 pb-4 text-xs text-slate-600 leading-relaxed">
                    Synapse adalah platform digital penyewaan GPU (Graphics Processing Unit) untuk komputasi AI dan mining. Pengguna bisa menyewa node GPU secara online, menerima pendapatan harian (ROI), dan menarik dana kapan saja selama jam operasional.
                </div>
            </div>
        </div>

        <!-- FAQ Item 2 -->
        <div class="faq-item bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <button onclick="toggleFaq(this)"
                    class="w-full flex items-center justify-between p-4 text-left gap-3">
                <span class="text-sm font-semibold text-slate-800">Bagaimana cara menyewa?</span>
                <i class="fas fa-chevron-down text-slate-400 text-xs transition-transform duration-300 shrink-0 faq-icon"></i>
            </button>
            <div class="faq-answer max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                <div class="px-4 pb-4 text-xs text-slate-600 leading-relaxed">
                    <ol class="list-decimal list-inside space-y-1">
                        <li>Buka menu <strong>Marketplace</strong> dari navigasi bawah.</li>
                        <li>Pilih paket GPU yang ingin disewa.</li>
                        <li>Klik <strong>"Sewa Sekarang"</strong> dan konfirmasi pembayaran dari saldo wallet Anda.</li>
                        <li>Node akan aktif otomatis dan mulai menghasilkan ROI harian.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- FAQ Item 3 -->
        <div class="faq-item bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <button onclick="toggleFaq(this)"
                    class="w-full flex items-center justify-between p-4 text-left gap-3">
                <span class="text-sm font-semibold text-slate-800">Bagaimana cara withdraw (penarikan)?</span>
                <i class="fas fa-chevron-down text-slate-400 text-xs transition-transform duration-300 shrink-0 faq-icon"></i>
            </button>
            <div class="faq-answer max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                <div class="px-4 pb-4 text-xs text-slate-600 leading-relaxed">
                    <ul class="list-disc list-inside space-y-1">
                        <li>Masuk ke menu <strong>Wallet</strong> → <strong>Penarikan</strong>.</li>
                        <li>Pilih rekening bank yang sudah terikat.</li>
                        <li>Masukkan jumlah penarikan (min Rp 100.000).</li>
                        <li>Penarikan diproses pada jam operasional: <strong>07:00 – 19:00 WIB</strong>.</li>
                        <li>Biaya admin (fee) akan dipotong otomatis sesuai tier nominal.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- FAQ Item 4 -->
        <div class="faq-item bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <button onclick="toggleFaq(this)"
                    class="w-full flex items-center justify-between p-4 text-left gap-3">
                <span class="text-sm font-semibold text-slate-800">Apa itu referral?</span>
                <i class="fas fa-chevron-down text-slate-400 text-xs transition-transform duration-300 shrink-0 faq-icon"></i>
            </button>
            <div class="faq-answer max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                <div class="px-4 pb-4 text-xs text-slate-600 leading-relaxed">
                    Setiap pengguna memiliki <strong>Kode Undangan</strong> unik. Bagikan kode tersebut ke teman atau keluarga. Ketika mereka mendaftar menggunakan kode Anda, mereka menjadi downline (Level 1 / Agen B). Anda akan menerima bonus komisi dari aktivitas transaksi downline. Lihat detail lengkapnya di menu <strong>Tim &amp; Afiliasi</strong>.
                </div>
            </div>
        </div>

        <!-- FAQ Item 5 — NEW: Security Rule -->
        <div class="faq-item bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <button onclick="toggleFaq(this)"
                    class="w-full flex items-center justify-between p-4 text-left gap-3">
                <span class="text-sm font-semibold text-slate-800">Mengapa saya tidak bisa melakukan penarikan dana?</span>
                <i class="fas fa-chevron-down text-slate-400 text-xs transition-transform duration-300 shrink-0 faq-icon"></i>
            </button>
            <div class="faq-answer max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                <div class="px-4 pb-4 text-xs text-slate-600 leading-relaxed">
                    Ada beberapa syarat yang harus dipenuhi sebelum bisa melakukan penarikan:
                    <ul class="list-disc list-inside space-y-1 mt-2">
                        <li><strong>Rekening Bank wajib diikat terlebih dahulu</strong> — Buka menu Profil → Rekening Bank untuk mengikat nomor rekening Anda.</li>
                        <li><strong>Jam operasional</strong> — Penarikan hanya diproses pada jam <strong>07:00 – 19:00 WIB</strong>.</li>
                        <li><strong>Minimal penarikan</strong> — Jumlah minimum adalah <strong>Rp 100.000</strong>.</li>
                        <li><strong>Saldo mencukupi</strong> — Pastikan saldo wallet Anda cukup setelah dipotong biaya admin.</li>
                        <li><strong>Tidak ada penarikan pending</strong> — Jika Anda memiliki penarikan yang belum diproses, tunggu hingga selesai terlebih dahulu.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- FAQ Item 6 -->
        <div class="faq-item bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <button onclick="toggleFaq(this)"
                    class="w-full flex items-center justify-between p-4 text-left gap-3">
                <span class="text-sm font-semibold text-slate-800">Bagaimana cara isi saldo (top up)?</span>
                <i class="fas fa-chevron-down text-slate-400 text-xs transition-transform duration-300 shrink-0 faq-icon"></i>
            </button>
            <div class="faq-answer max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                <div class="px-4 pb-4 text-xs text-slate-600 leading-relaxed">
                    <ol class="list-decimal list-inside space-y-1">
                        <li>Buka menu <strong>Wallet</strong>.</li>
                        <li>Klik tombol <strong>"Top Up"</strong>.</li>
                        <li>Pilih nominal cepat atau masukkan nominal custom.</li>
                        <li>Klik <strong>"Kirim Top Up"</strong> dan ikuti instruksi pembayaran.</li>
                        <li>Saldo akan masuk setelah pembayaran terkonfirmasi oleh sistem.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Footer Note ═══ -->
    <div class="text-center pb-4">
        <p class="text-[10px] text-slate-400">Synapse Support · v1.0</p>
    </div>

</div>

<!-- ═══ Vanilla JS Accordion ═══ -->
<script>
(function() {
    function toggleFaq(button) {
        const faqItem = button.closest('.faq-item');
        const answer = faqItem.querySelector('.faq-answer');
        const icon = faqItem.querySelector('.faq-icon');
        const isOpen = answer.classList.contains('max-h-[500px]');

        // Close all other open FAQs
        document.querySelectorAll('.faq-item').forEach(function(item) {
            const ans = item.querySelector('.faq-answer');
            const ico = item.querySelector('.faq-icon');
            if (item !== faqItem) {
                ans.classList.remove('max-h-[500px]');
                ans.classList.add('max-h-0');
                ico.classList.remove('rotate-180');
            }
        });

        // Toggle current FAQ
        if (isOpen) {
            answer.classList.remove('max-h-[500px]');
            answer.classList.add('max-h-0');
            icon.classList.remove('rotate-180');
        } else {
            answer.classList.remove('max-h-0');
            answer.classList.add('max-h-[500px]');
            icon.classList.add('rotate-180');
        }
    }

    // Expose to global scope for inline onclick handlers
    window.toggleFaq = toggleFaq;
})();
</script>
