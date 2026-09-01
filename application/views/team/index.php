<!-- ===== MISSION CARD: LEVEL 1 BONUS ===== -->
<?php
    $m_agent  = (int) ($claim_data['active_b_count'] ?? 0);
    $m_turnover = (int) ($claim_data['sales_b'] ?? 0);
    $m_claimed  = (bool) ($claim_data['level1_claimed'] ?? false);
    $m_agent_pct    = $m_agent >= 3 ? 100 : round(($m_agent / 3) * 100);
    $m_turnover_pct = $m_turnover >= 330000 ? 100 : round(($m_turnover / 330000) * 100);
?>
<section class="mx-4 mt-4 bg-slate-900 rounded-2xl p-4 border border-slate-700 shadow-lg">
    <!-- Header -->
    <div class="flex items-center justify-between mb-1">
        <div class="flex items-center gap-2">
            <span class="text-base">🎯</span>
            <h3 class="text-xs font-extrabold text-white uppercase tracking-wider">Misi Level 1</h3>
        </div>
        <span class="text-[10px] font-bold text-indigo-400 bg-indigo-500/10 px-2 py-0.5 rounded-full border border-indigo-500/20">Rp 80.000</span>
    </div>
    <p class="text-[10px] text-slate-400 mb-3">Klaim sekali seumur hidup</p>

    <!-- Agent Progress -->
    <div class="mb-3">
        <div class="flex items-center justify-between mb-1">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wide">Agen Aktif</span>
            <span class="text-xs font-extrabold text-white"><?= $m_agent ?> <span class="text-slate-500 font-normal">/ 3</span></span>
        </div>
        <div class="w-full h-2 bg-slate-700 rounded-full overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500 <?= $m_agent >= 3 ? 'bg-emerald-500' : 'bg-indigo-500' ?>" style="width: <?= $m_agent_pct ?>%"></div>
        </div>
    </div>

    <!-- Turnover Progress -->
    <div class="mb-3">
        <div class="flex items-center justify-between mb-1">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wide">Omset Tim</span>
            <span class="text-xs font-extrabold text-white">Rp <?= number_format($m_turnover, 0, ',', '.') ?> <span class="text-slate-500 font-normal">/ 330.000</span></span>
        </div>
        <div class="w-full h-2 bg-slate-700 rounded-full overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500 <?= $m_turnover_pct >= 100 ? 'bg-emerald-500' : 'bg-indigo-500' ?>" style="width: <?= $m_turnover_pct ?>%"></div>
        </div>
    </div>

    <!-- Button: 3-state conditional -->
    <div class="mt-4">
        <?php if ($m_claimed): ?>
            <div class="w-full text-center py-2.5 bg-emerald-500/10 border border-emerald-500/20 rounded-xl">
                <span class="text-xs font-bold text-emerald-400"><i class="fas fa-check-circle mr-1"></i>Selesai — Sudah Diklaim</span>
            </div>
        <?php elseif ($m_agent >= 3 && $m_turnover >= 330000): ?>
            <button id="btn-claim-l1" onclick="claimLevel1()" class="w-full bg-indigo-500 hover:bg-indigo-600 text-white text-xs font-bold py-3 px-4 rounded-xl transition-all active:scale-[0.97] shadow-lg shadow-indigo-500/20">
                <i class="fas fa-gift mr-1"></i>Klaim Bonus Rp 80.000
            </button>
        <?php else: ?>
            <button disabled class="w-full bg-slate-700 text-slate-400 text-xs font-bold py-3 px-4 rounded-xl cursor-not-allowed border border-slate-600">
                <i class="fas fa-lock mr-1"></i>Syarat Belum Terpenuhi
            </button>
        <?php endif; ?>
    </div>
</section>

<!-- ===== HELP BUTTON ===== -->
<div class="mx-4 mt-3">
    <button onclick="openHelpModal()" class="w-full flex items-center justify-center gap-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 text-xs font-bold py-2.5 px-4 rounded-xl border border-indigo-200 transition-all active:scale-95">
        <i class="fas fa-info-circle"></i> Cara Kerja Bonus
    </button>
</div>

<!-- ===== LAYER 1: SHARE CENTER ===== -->
<section class="mx-4 mt-3 bg-white rounded-2xl p-5 shadow-sm border border-slate-100">
    <div class="flex items-center gap-2 mb-1">
        <i class="fas fa-share-alt text-indigo-500"></i>
        <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Pusat Berbagi</h2>
    </div>
    <p class="text-xs text-slate-400 mb-4">Bagikan kode ini ke temanmu untuk membangun tim</p>

    <!-- Referral URL -->
    <div class="bg-slate-50 rounded-xl border border-slate-200 p-3 mb-4">
        <p class="text-[10px] text-slate-400 uppercase font-semibold mb-1">Link Undangan</p>
        <div class="flex items-center gap-2">
            <code id="ref-url" class="text-xs text-slate-700 font-mono truncate flex-1"><?= $ref_url ?></code>
            <button id="btn-copy" onclick="copyRef()" class="shrink-0 bg-indigo-500 hover:bg-indigo-600 active:scale-95 text-white text-[10px] font-bold px-3 py-1.5 rounded-lg transition-all">
                <i class="fas fa-copy mr-1"></i>Salin
            </button>
        </div>
    </div>

    <!-- QR Code -->
    <div class="flex flex-col items-center">
        <div id="qrcode" class="bg-white p-3 rounded-xl border border-slate-100 shadow-sm"></div>
        <p class="text-[10px] text-slate-400 mt-2">Scan QR untuk mendaftar</p>
    </div>
</section>

<!-- ===== LAYER 2: GAMIFICATION ===== -->
<section class="mx-4 mt-3 bg-white rounded-2xl p-5 shadow-sm border border-slate-100">
    <div class="flex items-center gap-2 mb-4">
        <i class="fas fa-chart-bar text-indigo-500"></i>
        <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Statistik Tim</h2>
    </div>

    <!-- Metric Cards -->
    <div class="grid grid-cols-2 gap-3 mb-4">
        <div class="bg-slate-50 rounded-xl p-3 text-center border border-slate-100">
            <p class="text-2xl font-extrabold text-slate-800"><?= $total_bc ?></p>
            <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Total Anggota</p>
        </div>
        <div class="bg-emerald-50 rounded-xl p-3 text-center border border-emerald-100">
            <p class="text-2xl font-extrabold text-emerald-600"><?= $active_bc ?></p>
            <p class="text-[10px] text-emerald-500 font-semibold mt-0.5">Anggota Aktif</p>
        </div>
    </div>

    <!-- Level Breakdown -->
    <div class="grid grid-cols-2 gap-3">
        <div class="bg-indigo-50 rounded-xl p-3 text-center border border-indigo-100">
            <p class="text-2xl font-extrabold text-indigo-600"><?= $l1_active ?></p>
            <p class="text-[10px] text-indigo-500 font-semibold mt-0.5">Agen L1 Aktif</p>
        </div>
        <div class="bg-slate-50 rounded-xl p-3 text-center border border-slate-100">
            <p class="text-2xl font-extrabold text-slate-800"><?= $l2_active ?></p>
            <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Agen L2 Aktif</p>
        </div>
    </div>
</section>

<!-- ===== LAYER 3: MEMBER LIST ===== -->
<section class="mx-4 mt-3 mb-24 bg-white rounded-2xl p-5 shadow-sm border border-slate-100">
    <div class="flex items-center gap-2 mb-4">
        <i class="fas fa-users text-indigo-500"></i>
        <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Daftar Anggota</h2>
        <span class="ml-auto text-[10px] bg-slate-100 text-slate-500 font-bold px-2 py-0.5 rounded-full"><?= $total_bc ?></span>
    </div>

    <?php if (empty($members)): ?>
        <!-- Empty State -->
        <div class="text-center py-8">
            <i class="fas fa-user-plus text-4xl text-slate-200 mb-3"></i>
            <p class="text-sm text-slate-400 font-semibold">Belum ada anggota tim</p>
            <p class="text-[10px] text-slate-300 mt-1">Bagikan kode referral untuk mulai</p>
        </div>
    <?php else: ?>
        <div class="max-h-96 overflow-y-auto space-y-2">
            <?php foreach ($members as $m): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl border <?= $m->is_active ? 'border-emerald-100 bg-emerald-50/50' : 'border-slate-100 bg-slate-50/50' ?>">
                    <!-- Avatar Initial -->
                    <div class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 <?= $m->is_active ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-400' ?>">
                        <span class="text-xs font-bold"><?= strtoupper(substr($m->username ?? $m->phone_full, 0, 1)) ?></span>
                    </div>

                    <!-- Info -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1.5">
                            <p class="text-sm font-semibold text-slate-700 truncate"><?= htmlspecialchars($m->username ?? 'User') ?></p>
                            <span class="shrink-0 text-[9px] font-bold px-1.5 py-0.5 rounded-full <?= $m->level == 1 ? 'bg-indigo-100 text-indigo-600' : 'bg-slate-200 text-slate-500' ?>">
                                L<?= $m->level ?>
                            </span>
                        </div>
                        <a href="https://wa.me/<?= $m->phone_wa ?>?text=<?= urlencode('Halo, saya upline Anda di platform. Ada yang bisa saya bantu terkait penyewaan produk?') ?>" target="_blank" rel="noopener noreferrer" class="text-[10px] text-emerald-600 hover:text-emerald-700 font-semibold font-mono flex items-center gap-1.5 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            <?= $m->phone_full ?>
                        </a>
                    </div>

                    <!-- Status Badge -->
                    <?php if ($m->is_active): ?>
                        <span class="shrink-0 text-[10px] font-bold text-emerald-600 bg-emerald-100 px-2 py-1 rounded-full">
                            <i class="fas fa-circle text-[5px] mr-0.5 align-middle"></i>Aktif
                        </span>
                    <?php else: ?>
                        <span class="shrink-0 text-[10px] font-bold text-slate-400 bg-slate-100 px-2 py-1 rounded-full">
                            Inactive
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- ═══ HELP MODAL — Bottom Sheet ═══ -->
<div id="helpModal" class="fixed inset-0 z-[60] hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeHelpModal()"></div>
    <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-3xl max-h-[80vh] overflow-y-auto transform translate-y-full transition-transform duration-300" id="helpSheet">
        <div class="sticky top-0 bg-white px-5 pt-5 pb-3 border-b border-slate-100 rounded-t-3xl">
            <div class="w-10 h-1 bg-slate-200 rounded-full mx-auto mb-3"></div>
            <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2"><i class="fas fa-info-circle text-indigo-500"></i> Cara Kerja Bonus Afiliasi</h3>
        </div>
        <div class="px-5 py-4 space-y-4">
            <!-- Active Downline Rule -->
            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                <h4 class="text-xs font-bold text-slate-700 mb-2"><i class="fas fa-users text-indigo-500 mr-1"></i> Downline Aktif</h4>
                <p class="text-[11px] text-slate-500 leading-relaxed">Downline dianggap <b>aktif</b> hanya jika memiliki <b>sewa aktif</b> di sistem (status <code>active</code> di database). User yang sudah expired/tidak menyewa <b>tidak dihitung</b>.</p>
            </div>
            <!-- Level 1 -->
            <div class="bg-indigo-50 rounded-xl p-4 border border-indigo-100">
                <h4 class="text-xs font-bold text-indigo-700 mb-2"><i class="fas fa-gift mr-1"></i> Bonus Level 1 — Satu Kali</h4>
                <p class="text-[11px] text-slate-600 leading-relaxed mb-2">Dapatkan <b>Rp 80.000</b> (sekali seumur hidup) jika:</p>
                <ul class="text-[11px] text-slate-500 space-y-1 ml-3 list-disc">
                    <li>Minimal <b>3 downline langsung (B)</b> berstatus aktif</li>
                    <li>Total omset downline B aktif ≥ <b>Rp 330.000</b></li>
                </ul>
                <p class="text-[10px] text-indigo-400 mt-2 font-semibold">⚠️ Bonus ini hanya bisa diklaim 1x. Tidak bisa diulang.</p>
            </div>
            <!-- Level 2-6 -->
            <div class="bg-emerald-50 rounded-xl p-4 border border-emerald-100">
                <h4 class="text-xs font-bold text-emerald-700 mb-2"><i class="fas fa-money-bill-wave mr-1"></i> Gaji Mingguan — Level 2-6</h4>
                <p class="text-[11px] text-slate-600 leading-relaxed mb-2">Klaim gaji mingguan berdasarkan jumlah downline aktif (semua level):</p>
                <div class="text-[11px] space-y-1">
                    <div class="flex justify-between"><span class="text-slate-500">Level 2 (≥ 9 aktif)</span><span class="font-bold text-emerald-600">Rp 200.000</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Level 3 (≥ 30 aktif)</span><span class="font-bold text-emerald-600">Rp 1.000.000</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Level 4 (≥ 70 aktif)</span><span class="font-bold text-emerald-600">Rp 2.500.000</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Level 5 (≥ 130 aktif)</span><span class="font-bold text-emerald-600">Rp 5.000.000</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Level 6 (≥ 190 aktif)</span><span class="font-bold text-emerald-600">Rp 9.000.000</span></div>
                </div>
                <p class="text-[10px] text-emerald-500 mt-2 font-semibold">⏱️ Cooldown 7 hari antar klaim. Level ditentukan dinamis saat klaim.</p>
            </div>
            <!-- Close Button -->
            <button onclick="closeHelpModal()" class="w-full py-3 bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold rounded-xl transition-all active:scale-95">Mengerti</button>
        </div>
    </div>
</div>

<!-- QRCode.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
// Generate QR
new QRCode(document.getElementById("qrcode"), {
    text: "<?= $ref_url ?>",
    width: 160,
    height: 160,
    colorDark: "#1e293b",
    colorLight: "#ffffff",
    correctLevel: QRCode.CorrectLevel.M
});

// Copy to clipboard
function copyRef() {
    var url = "<?= $ref_url ?>";
    var btn = document.getElementById('btn-copy');
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            btn.innerHTML = '<i class="fas fa-check mr-1"></i>Tersalin!';
            btn.classList.add('bg-emerald-500');
            btn.classList.remove('bg-indigo-500');
            setTimeout(function() {
                btn.innerHTML = '<i class="fas fa-copy mr-1"></i>Salin';
                btn.classList.remove('bg-emerald-500');
                btn.classList.add('bg-indigo-500');
            }, 2000);
        });
    } else {
        // Fallback
        var t = document.createElement('textarea');
        t.value = url;
        document.body.appendChild(t);
        t.select();
        document.execCommand('copy');
        document.body.removeChild(t);
        btn.innerHTML = '<i class="fas fa-check mr-1"></i>Tersalin!';
        setTimeout(function() { btn.innerHTML = '<i class="fas fa-copy mr-1"></i>Salin'; }, 2000);
    }
}

// ═══ Help Modal ═══
function openHelpModal() {
    var m = document.getElementById('helpModal');
    var s = document.getElementById('helpSheet');
    m.classList.remove('hidden');
    setTimeout(function() { s.classList.remove('translate-y-full'); s.classList.add('translate-y-0'); }, 10);
}
function closeHelpModal() {
    var m = document.getElementById('helpModal');
    var s = document.getElementById('helpSheet');
    s.classList.remove('translate-y-0');
    s.classList.add('translate-y-full');
    setTimeout(function() { m.classList.add('hidden'); }, 300);
}

// ═══ Claim AJAX ═══
// Token CSRF disuntik otomatis oleh csrfFetch() (partial templates/csrf_meta.php)

function claimLevel1() {
    var btn = document.getElementById('btn-claim-l1');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Memproses...';
    var fd = new FormData();
    csrfFetch('<?= site_url("team/claim_level1") ?>', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i>Bonus Diklaim!';
            btn.className = 'w-full bg-emerald-500 text-white text-xs font-bold py-3 px-4 rounded-xl cursor-not-allowed';
            if (d.balance !== undefined) {
                var bal = document.getElementById('balance-display');
                if (bal) bal.textContent = 'Rp ' + Number(d.balance).toLocaleString('id-ID');
            }
            showToast(d.message || 'Bonus berhasil diklaim!', 'success');
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-gift mr-1"></i>Klaim Bonus Level 1 (Rp 80.000)';
            showToast(d.message || 'Gagal klaim', 'error');
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-gift mr-1"></i>Klaim Bonus Level 1 (Rp 80.000)';
        showToast('Terjadi kesalahan jaringan', 'error');
    });
}

function claimWage() {
    var btn = document.getElementById('btn-claim-wage');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Memproses...';
    var fd = new FormData();
    csrfFetch('<?= site_url("team/claim_wage") ?>', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i>Gaji Diklaim!';
            btn.className = 'w-full bg-emerald-500 text-white text-xs font-bold py-3 px-4 rounded-xl cursor-not-allowed';
            if (d.balance !== undefined) {
                var bal = document.getElementById('balance-display');
                if (bal) bal.textContent = 'Rp ' + Number(d.balance).toLocaleString('id-ID');
            }
            showToast(d.message || 'Gaji berhasil diklaim!', 'success');
        } else {
            btn.disabled = false;
            var lvl = d.level || 'X';
            btn.innerHTML = '<i class="fas fa-money-bill-wave mr-1"></i>Klaim Gaji Mingguan (Level ' + lvl + ')';
            showToast(d.message || 'Gagal klaim', 'error');
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-money-bill-wave mr-1"></i>Klaim Gaji Mingguan';
        showToast('Terjadi kesalahan jaringan', 'error');
    });
}

function showToast(msg, type) {
    var c = document.createElement('div');
    c.className = 'fixed top-4 left-1/2 -translate-x-1/2 z-[100] px-4 py-3 rounded-xl text-xs font-bold shadow-lg transition-all ' +
        (type === 'success' ? 'bg-emerald-500 text-white' : 'bg-red-500 text-white');
    c.textContent = msg;
    document.body.appendChild(c);
    setTimeout(function() { c.style.opacity = '0'; c.style.transition = 'opacity 0.3s'; }, 2500);
    setTimeout(function() { document.body.removeChild(c); }, 3000);
}
</script>
