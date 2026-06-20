<div class="p-4 space-y-6">

    <!-- ═══ Hero Banner with Image ═══ -->
    <div class="relative w-full h-56 rounded-3xl overflow-hidden shadow-2xl">
        <img src="https://placehold.co/480x300/1e293b/334155?text=AI+Cloud+Nodes" class="absolute inset-0 w-full h-full object-cover" alt="Hero Background">
        <div class="absolute inset-0 bg-gradient-to-r from-slate-900 via-slate-900/80 to-transparent"></div>

        <div class="relative z-10 h-full flex flex-col justify-between p-6">
            <div>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-emerald-500/20 border border-emerald-500/30 rounded-full text-[10px] font-bold text-emerald-400 uppercase tracking-widest">
                    <span class="w-1.5 h-1.5 bg-emerald-400 rounded-full animate-pulse"></span>
                    Online
                </span>
            </div>
            <div>
                <h2 class="text-2xl font-extrabold text-white leading-tight mb-1">Masa Depan Komputasi AI</h2>
                <p class="text-sm text-slate-300 font-medium">Synapse Engine v2.0 Aktif</p>
            </div>
        </div>
    </div>

    <!-- ═══ User Identity & Referral Card ═══ -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm flex items-center justify-between">
        <div class="space-y-2">
            <div>
                <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">Identitas Node</span>
                <p class="text-sm text-slate-800 font-semibold mt-0.5">
                    <?= substr($user->phone, 0, 3) . '••••' . substr($user->phone, -3) ?>
                </p>
            </div>
            <div>
                <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">Kode Undangan</span>
                <div class="flex items-center gap-2 mt-1">
                    <span class="inline-block px-3 py-1 bg-slate-100 rounded-lg text-sm font-bold text-slate-900 tracking-widest"><?= $user->invite_code ?></span>
                    <button onclick="navigator.clipboard.writeText('<?= $user->invite_code ?>').then(function(){document.getElementById('copy-toast').classList.remove('opacity-0');setTimeout(function(){document.getElementById('copy-toast').classList.add('opacity-0')},2000);})" class="flex items-center gap-1 px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors" title="Salin">
                        <i class="fas fa-copy text-slate-500 text-xs"></i>
                        <span class="text-[11px] font-semibold text-slate-600">Salin</span>
                    </button>
                </div>
            </div>
        </div>
        <div class="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center">
            <i class="fas fa-user-astronaut text-slate-400 text-2xl"></i>
        </div>
    </div>

    <!-- Copy Toast -->
    <div id="copy-toast" class="fixed left-1/2 -translate-x-1/2 bottom-24 px-4 py-2 bg-slate-900 text-white text-xs font-medium rounded-xl opacity-0 transition-opacity duration-300 z-50 shadow-lg pointer-events-none">
        Berhasil disalin!
    </div>

    <!-- ═══ Visual Stats Section ═══ -->
    <div>
        <div class="w-full h-32 rounded-2xl overflow-hidden mb-4">
            <img src="https://placehold.co/480x150/f8fafc/94a3b8?text=Global+Network+Topology" class="w-full h-full object-cover" alt="Topology">
        </div>

        <div class="grid grid-cols-2 gap-3">
            <!-- Uptime Node -->
            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100">
                <div class="w-9 h-9 bg-emerald-100 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-bolt text-emerald-600 text-sm"></i>
                </div>
                <p class="text-2xl font-extrabold text-emerald-500">99.99%</p>
                <p class="text-[11px] text-slate-500 font-semibold mt-1">Uptime Node</p>
            </div>

            <!-- Kapasitas Tersewa -->
            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100">
                <div class="w-9 h-9 bg-amber-100 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-chart-line text-amber-600 text-sm"></i>
                </div>
                <p class="text-2xl font-extrabold text-slate-900">98.5%</p>
                <p class="text-[11px] text-slate-500 font-semibold mt-1">Kapasitas Tersewa</p>
            </div>

            <!-- Global TFLOPs -->
            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100">
                <div class="w-9 h-9 bg-blue-100 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-microchip text-blue-600 text-sm"></i>
                </div>
                <p class="text-2xl font-extrabold text-slate-900">1,250+</p>
                <p class="text-[11px] text-slate-500 font-semibold mt-1">Global TFLOPs</p>
            </div>

            <!-- Total Value -->
            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100">
                <div class="w-9 h-9 bg-indigo-100 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-coins text-indigo-600 text-sm"></i>
                </div>
                <p class="text-2xl font-extrabold text-slate-900">1,500,000</p>
                <p class="text-[11px] text-slate-500 font-semibold mt-1">Total Value · USC</p>
            </div>
        </div>
    </div>

    <!-- ═══ Action Button ═══ -->
    <a href="<?= base_url('marketplace') ?>" class="w-full h-14 bg-slate-900 hover:bg-blue-600 text-white rounded-2xl font-bold flex items-center justify-center shadow-[0_10px_20px_rgba(15,23,42,0.2)] transition-all gap-2">
        Eksplorasi Marketplace <i class="fas fa-arrow-right"></i>
    </a>

</div>
