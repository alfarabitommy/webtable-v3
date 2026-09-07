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
    <div class="u-card rounded-2xl p-5 shadow-sm flex items-center justify-between">
        <div class="space-y-2">
            <div>
                <span class="text-[10px] u-muted font-semibold uppercase tracking-wider">Identitas Node</span>
                <p class="text-sm u-text font-semibold mt-0.5">
                    <?= substr($user->phone, 0, 3) . '••••' . substr($user->phone, -3) ?>
                </p>
            </div>
            <div>
                <span class="text-[10px] u-muted font-semibold uppercase tracking-wider">Kode Undangan</span>
                <?php if (!empty($referral_locked)): ?>
                <!-- Condition A (Plan 89): lifetime == 0 → kode disembunyikan -->
                <div class="flex items-center gap-2 mt-1">
                    <a href="<?= base_url('marketplace') ?>"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 u-card-inset rounded-lg text-[11px] font-semibold text-amber-600 dark:text-amber-400 hover:opacity-80 transition-opacity">
                        <i class="fas fa-lock text-[10px]"></i> Terkunci — Sewa 1 Paket
                    </a>
                </div>
                <?php else: ?>
                <div class="flex items-center gap-2 mt-1">
                    <span id="inviteCodeText" class="inline-block px-3 py-1 u-card-inset rounded-lg text-sm font-bold u-text tracking-widest\"><?= $user->invite_code ?></span>
                    <button id="btnCopyInvite" class="flex items-center gap-1 px-2.5 py-1.5 u-btn-ghost rounded-lg transition-colors" title="Salin">
                        <i class="fas fa-copy u-text-2 text-xs"></i>
                        <span class="text-[11px] font-semibold u-text-2">Salin</span>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="w-16 h-16 rounded-2xl u-card-inset flex items-center justify-center">
            <i class="fas fa-user-astronaut u-muted text-2xl"></i>
        </div>
    </div>

    <?php if (!empty($is_promoter)): ?>
    <!-- ═══ Kartu Program Promotor (Plan 91) ═══ -->
    <div class="u-card rounded-2xl p-5 shadow-sm" style="background: linear-gradient(135deg, rgba(79,70,229,.12), rgba(6,182,212,.10)); border: 1px solid rgba(79,70,229,.25);">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-11 h-11 shrink-0 rounded-2xl bg-amber-500/15 border border-amber-500/30 flex items-center justify-center">
                    <i class="fas fa-star text-amber-500"></i>
                </div>
                <div class="min-w-0">
                    <span class="text-[10px] u-muted font-semibold uppercase tracking-wider">Program Promotor</span>
                    <p class="text-lg font-extrabold u-text leading-tight">Rp <?= number_format((int) ($promoter_available ?? 0), 0, ',', '.') ?></p>
                    <p class="text-[10px] u-muted">Omzet tersedia untuk reward GPU</p>
                </div>
            </div>
            <a href="<?= base_url('team#promoter-hub') ?>"
               class="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-[11px] font-bold transition-colors active:scale-95">
                Kelola <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Copy Toast (z-[60]: above bottom nav z-50) -->
    <div id="copy-toast" class="fixed left-1/2 -translate-x-1/2 bottom-24 px-4 py-2 u-toast text-xs font-medium rounded-xl opacity-0 transition-opacity duration-300 z-[60] shadow-lg pointer-events-none">
        Berhasil disalin!
    </div>

    <script>
    (function () {
        var btn = document.getElementById('btnCopyInvite');
        var codeEl = document.getElementById('inviteCodeText');
        var toast = document.getElementById('copy-toast');
        if (!btn || !codeEl || !toast) return; // Plan 89: locked state → tombol tak dirender
        var iconEl = btn.querySelector('i');
        var labelEl = btn.querySelector('span');

        btn.addEventListener('click', function () {
            var code = codeEl.textContent.trim();

            function onSuccess() {
                labelEl.textContent = 'Tersalin!';
                iconEl.className = 'fas fa-check text-white text-xs';
                btn.classList.remove('u-btn-ghost');
                btn.classList.add('bg-emerald-500');

                toast.classList.remove('opacity-0');

                setTimeout(function () {
                    labelEl.textContent = 'Salin';
                    iconEl.className = 'fas fa-copy u-text-2 text-xs';
                    btn.classList.remove('bg-emerald-500');
                    btn.classList.add('u-btn-ghost');
                    toast.classList.add('opacity-0');
                }, 2000);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(onSuccess).catch(function () {
                    fallbackCopy(code);
                });
            } else {
                fallbackCopy(code);
            }

            function fallbackCopy(text) {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px';
                document.body.appendChild(ta);
                ta.select();
                try {
                    document.execCommand('copy');
                    onSuccess();
                } catch (e) {
                    alert('Gagal menyalin kode.');
                }
                document.body.removeChild(ta);
            }
        });
    })();
    </script>

    <!-- ═══ Visual Stats Section ═══ -->
    <div>
        <div class="w-full h-32 rounded-2xl overflow-hidden mb-4">
            <img src="https://placehold.co/480x150/f8fafc/94a3b8?text=Global+Network+Topology" class="w-full h-full object-cover" alt="Topology">
        </div>

        <div class="grid grid-cols-2 gap-3">
            <!-- Uptime Node -->
            <div class="u-card-inset p-4 rounded-2xl">
                <div class="w-9 h-9 bg-emerald-100 dark:bg-emerald-500/10 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-bolt text-emerald-600 dark:text-emerald-400 text-sm"></i>
                </div>
                <p class="text-2xl font-extrabold text-emerald-500">99.99%</p>
                <p class="text-[11px] u-text-2 font-semibold mt-1">Uptime Node</p>
            </div>

            <!-- Kapasitas Tersewa -->
            <div class="u-card-inset p-4 rounded-2xl">
                <div class="w-9 h-9 bg-amber-100 dark:bg-amber-500/10 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-chart-line text-amber-600 dark:text-amber-400 text-sm"></i>
                </div>
                <p class="text-2xl font-extrabold u-text">98.5%</p>
                <p class="text-[11px] u-text-2 font-semibold mt-1">Kapasitas Tersewa</p>
            </div>

            <!-- Global TFLOPs -->
            <div class="u-card-inset p-4 rounded-2xl">
                <div class="w-9 h-9 bg-blue-100 dark:bg-blue-500/10 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-microchip text-blue-600 dark:text-blue-400 text-sm"></i>
                </div>
                <p class="text-2xl font-extrabold u-text">1,250+</p>
                <p class="text-[11px] u-text-2 font-semibold mt-1">Global TFLOPs</p>
            </div>

            <!-- Total Value -->
            <div class="u-card-inset p-4 rounded-2xl">
                <div class="w-9 h-9 bg-indigo-100 dark:bg-indigo-500/10 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-coins text-indigo-600 dark:text-indigo-400 text-sm"></i>
                </div>
                <p class="text-2xl font-extrabold u-text">1,500,000</p>
                <p class="text-[11px] u-text-2 font-semibold mt-1">Total Value · USC</p>
            </div>
        </div>
    </div>

    <!-- ═══ Action Button (Phase 32: cyber gradient) ═══ -->
    <a href="<?= base_url('marketplace') ?>" class="w-full h-14 u-btn-cyber rounded-2xl flex items-center justify-center gap-2">
        Eksplorasi Marketplace <i class="fas fa-arrow-right"></i>
    </a>

</div>

<?php if (!empty($inactive_warning)): ?>
<!-- ═══ WARNING MODAL: KONTAK SEWA TIDAK AKTIF (Plan 89 — Condition B) ═══
     Target: lifetime_rentals > 0 && active_rentals == 0. Muncul otomatis saat
     landing dashboard; dismiss hanya menutup render saat itu (deterministik). -->
<div id="inactiveWarnModal" class="fixed inset-0 z-[70]">
    <div class="absolute inset-0 bg-black/60" onclick="closeInactiveWarn()"></div>
    <div class="absolute bottom-0 left-0 right-0 u-modal rounded-t-3xl px-5 pt-4 pb-6 max-h-[85vh] overflow-y-auto">
        <div class="w-10 h-1 bg-slate-300 dark:bg-slate-600 rounded-full mx-auto mb-4"></div>
        <div class="flex items-start gap-3 mb-3">
            <div class="w-11 h-11 shrink-0 rounded-2xl bg-amber-500/15 border border-amber-500/30 flex items-center justify-center">
                <i class="fas fa-exclamation-triangle text-amber-500 text-lg"></i>
            </div>
            <h3 class="text-sm font-extrabold u-text leading-snug pt-1.5">⚠️ Perhatian: Kontrak Sewa Anda Tidak Aktif!</h3>
        </div>
        <p class="text-xs u-text-2 leading-relaxed mb-5">
            Anda saat ini tidak memiliki sewa GPU yang aktif. Aktifkan kembali minimal satu paket
            sekarang agar komisi referral dari jaringan tim Anda tidak hangus.
        </p>
        <a href="<?= base_url('marketplace') ?>" class="w-full h-12 u-btn-cyber rounded-xl flex items-center justify-center gap-2 text-sm">
            Aktifkan Sewa Sekarang <i class="fas fa-arrow-right"></i>
        </a>
        <button onclick="closeInactiveWarn()"
                class="w-full mt-2 py-3 u-btn-ghost rounded-xl text-xs font-bold u-text-2 transition-colors active:scale-[0.98]">
            Nanti Saja
        </button>
    </div>
</div>
<script>
(function () {
    var m = document.getElementById('inactiveWarnModal');
    if (m) m.classList.remove('hidden'); // modal default tampil (tanpa class hidden)
})();
function closeInactiveWarn() {
    var m = document.getElementById('inactiveWarnModal');
    if (m) m.classList.add('hidden');
}
</script>
<?php endif; ?>
