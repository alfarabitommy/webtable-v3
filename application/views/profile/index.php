<div class="p-4 space-y-5">

    <!-- Flash Messages -->
    <?php if ($this->session->flashdata('success')): ?>
        <div class="bg-emerald-500 text-white text-sm font-semibold px-4 py-3 rounded-xl flex items-center gap-2 shadow-lg">
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

    <!-- ===== IDENTITY CARD ===== -->
    <div class="bg-white rounded-2xl p-6 shadow-sm text-center space-y-4">
        <!-- Avatar -->
        <div class="relative inline-block">
            <?php if (!empty($user->avatar_url)): ?>
                <img src="<?= base_url('uploads/avatars/' . $user->avatar_url) ?>"
                     class="w-24 h-24 rounded-full object-cover border-4 border-indigo-100 mx-auto shadow-md"
                     alt="Avatar">
            <?php else: ?>
                <div class="w-24 h-24 rounded-full bg-slate-100 border-4 border-indigo-100 mx-auto shadow-md flex items-center justify-center">
                    <i class="fas fa-user text-3xl text-slate-300"></i>
                </div>
            <?php endif; ?>
            <!-- Edit Avatar FAB -->
            <button type="button" id="btn-edit-profile"
                    class="absolute bottom-0 right-0 bg-indigo-600 text-white w-8 h-8 rounded-full shadow-lg flex items-center justify-center hover:bg-indigo-700 transition active:scale-90">
                <i class="fas fa-pen text-[10px]"></i>
            </button>
        </div>

        <!-- Name + Phone -->
        <div>
            <h2 class="text-xl font-extrabold text-slate-900"><?= htmlspecialchars($user->username ?? 'User') ?></h2>
            <p class="text-sm text-slate-500 font-mono mt-1"><?= htmlspecialchars($user->phone) ?></p>
        </div>

        <!-- Level Badge -->
        <div class="flex justify-center">
            <span class="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider bg-indigo-50 text-indigo-600 px-3 py-1 rounded-full border border-indigo-100">
                <i class="fas fa-crown text-indigo-400"></i>
                Level <?= $user->level_id ?? 0 ?>
            </span>
        </div>
    </div>

    <!-- ===== REFERRAL CENTER ===== -->
    <div class="bg-white rounded-2xl p-5 shadow-sm space-y-3">
        <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Kode Referral</h3>
        <div class="flex items-center gap-3">
            <div class="flex-1 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">
                <span id="referral-code" class="font-mono text-sm font-bold text-indigo-600 tracking-wider">
                    <?= htmlspecialchars($user->invite_code ?? 'N/A') ?>
                </span>
            </div>
            <button type="button" id="btn-copy-ref"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-3 rounded-xl transition active:scale-95 flex items-center gap-2">
                <i class="fas fa-copy"></i>
            </button>
        </div>
        <!-- Copy toast -->
        <p id="copy-toast" class="text-[10px] font-bold text-emerald-600 text-center opacity-0 transition-opacity">Tersalin!</p>
    </div>

    <!-- ===== SETTINGS LIST ===== -->
    <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
        <!-- Rekening Bank -->
        <a href="<?= site_url('wallet/bind_bank') ?>"
           class="flex items-center justify-between px-5 py-4 hover:bg-slate-50 transition border-b border-slate-50 active:scale-[0.98]">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                    <i class="fas fa-university text-sm"></i>
                </div>
                <span class="text-sm font-medium text-slate-700">Rekening Bank</span>
            </div>
            <i class="fas fa-chevron-right text-[10px] text-slate-300"></i>
        </a>
        <!-- Ubah Kata Sandi -->
        <a href="#" class="flex items-center justify-between px-5 py-4 hover:bg-slate-50 transition active:scale-[0.98]">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                    <i class="fas fa-lock text-sm"></i>
                </div>
                <span class="text-sm font-medium text-slate-700">Ubah Kata Sandi</span>
            </div>
            <i class="fas fa-chevron-right text-[10px] text-slate-300"></i>
        </a>
    </div>

    <!-- ===== DANGER ZONE ===== -->
    <a href="<?= site_url('auth/logout') ?>"
       class="block w-full bg-rose-50 text-rose-600 border border-rose-200 text-center py-3.5 rounded-2xl text-sm font-bold hover:bg-rose-100 transition active:scale-[0.98]">
        <i class="fas fa-sign-out-alt mr-2"></i> Keluar
    </a>

</div>

<!-- ===== EDIT PROFILE BOTTOM SHEET MODAL ===== -->
<div id="editProfileModal" class="fixed inset-0 z-[60] hidden">
    <!-- Backdrop -->
    <div id="ep-backdrop" class="absolute inset-0 bg-black/50 backdrop-blur-sm transition-opacity"></div>

    <!-- Sheet -->
    <div id="ep-sheet" class="absolute bottom-0 left-0 right-0 bg-white rounded-t-3xl shadow-2xl transform translate-y-full transition-transform duration-300 ease-out max-h-[85vh] overflow-y-auto">

        <!-- Handle -->
        <div class="flex justify-center pt-3 pb-1">
            <div class="w-10 h-1 bg-slate-300 rounded-full"></div>
        </div>

        <!-- Header -->
        <div class="px-5 pb-4 flex items-center justify-between border-b border-slate-100">
            <h3 class="text-base font-bold text-slate-900 flex items-center gap-2">
                <i class="fas fa-user-edit text-indigo-500"></i> Edit Profil
            </h3>
            <button type="button" id="btn-close-ep" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 flex items-center justify-center transition">
                <i class="fas fa-times text-slate-500 text-xs"></i>
            </button>
        </div>

        <!-- Form -->
        <?= form_open_multipart('profile/update', 'id="editProfileForm"'); ?>
        <div class="px-5 pt-4 pb-6 space-y-5">

            <!-- Avatar Upload -->
            <div class="flex flex-col items-center gap-3">
                <div class="relative">
                    <img id="avatarPreview"
                         src="<?= !empty($user->avatar_url) ? base_url('uploads/avatars/' . $user->avatar_url) : '' ?>"
                         class="w-20 h-20 rounded-full object-cover border-2 border-slate-200 <?= empty($user->avatar_url) ? 'hidden' : '' ?>"
                         alt="Preview">
                    <div id="avatarPlaceholder"
                         class="w-20 h-20 rounded-full bg-slate-100 border-2 border-dashed border-slate-300 flex items-center justify-center <?= !empty($user->avatar_url) ? 'hidden' : '' ?>">
                        <i class="fas fa-camera text-slate-400 text-xl"></i>
                    </div>
                </div>
                <label for="avatarInput"
                       class="text-xs font-bold text-indigo-600 cursor-pointer hover:text-indigo-700 transition">
                    <i class="fas fa-camera mr-1"></i> Pilih Foto
                </label>
                <input type="file" name="avatar" id="avatarInput" accept="image/png,image/jpeg,image/gif" class="hidden">
                <!-- Delete avatar link -->
                <?php if (!empty($user->avatar_url)): ?>
                    <a href="<?= site_url('profile/avatar_delete') ?>"
                       class="text-[10px] font-bold text-rose-500 hover:text-rose-600 transition">
                        <i class="fas fa-trash-alt mr-1"></i> Hapus Foto
                    </a>
                <?php endif; ?>
            </div>

            <!-- Username -->
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1.5 uppercase tracking-wide">Nama</label>
                <input type="text" name="username" required maxlength="50"
                       value="<?= htmlspecialchars($user->username ?? '') ?>"
                       placeholder="Masukkan nama"
                       class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm font-medium text-slate-900 outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <p class="mt-1 text-[10px] text-slate-400">Maks. 50 karakter</p>
            </div>

            <!-- Phone (Read-only) -->
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1.5 uppercase tracking-wide">Nomor Telepon</label>
                <input type="text" readonly
                       value="<?= htmlspecialchars($user->phone) ?>"
                       class="w-full bg-slate-50 border border-slate-200 rounded-xl py-3 px-4 text-sm font-medium text-slate-400 cursor-not-allowed">
                <p class="mt-1 text-[10px] text-slate-400">Tidak dapat diubah</p>
            </div>

            <!-- Submit -->
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold py-3.5 rounded-xl transition flex items-center justify-center gap-2">
                <i class="fas fa-check"></i> Simpan Perubahan
            </button>
        </div>
        <?= form_close(); ?>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
/* --- Copy Referral (robust) --- */
(function() {
    var btn       = document.getElementById('btn-copy-ref');
    var codeEl    = document.getElementById('referral-code');
    var toast     = document.getElementById('copy-toast');
    var original  = btn ? btn.innerHTML : '';
    if (!btn || !codeEl || !toast) return;

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        // Fallback for non-secure contexts (HTTP)
        return new Promise(function(resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try {
                var ok = document.execCommand('copy');
                document.body.removeChild(ta);
                ok ? resolve() : reject(new Error('execCommand copy failed'));
            } catch (e) {
                document.body.removeChild(ta);
                reject(e);
            }
        });
    }

    function showSuccess() {
        toast.textContent = 'Tersalin!';
        toast.className = 'text-[10px] font-bold text-emerald-600 text-center opacity-100 transition-opacity';
        btn.innerHTML = '<i class="fas fa-check"></i>';
        btn.classList.remove('bg-indigo-600', 'hover:bg-indigo-700');
        btn.classList.add('bg-emerald-600');
    }

    function showError() {
        toast.textContent = 'Gagal menyalin';
        toast.className = 'text-[10px] font-bold text-rose-600 text-center opacity-100 transition-opacity';
    }

    function reset() {
        toast.className = 'text-[10px] font-bold text-emerald-600 text-center opacity-0 transition-opacity';
        btn.innerHTML = original;
        btn.classList.remove('bg-emerald-600');
        btn.classList.add('bg-indigo-600', 'hover:bg-indigo-700');
    }

    btn.addEventListener('click', function() {
        var code = codeEl.textContent.trim();
        if (!code || code === 'N/A') return;

        copyText(code).then(showSuccess).catch(function(e) {
            console.warn('Copy failed:', e);
            showError();
        }).finally(function() {
            setTimeout(reset, 2000);
        });
    });
})();

/* --- Edit Profile Bottom Sheet --- */
(function() {
    var modal    = document.getElementById('editProfileModal');
    var sheet    = document.getElementById('ep-sheet');
    var backdrop = document.getElementById('ep-backdrop');
    var btnOpen  = document.getElementById('btn-edit-profile');
    var btnClose = document.getElementById('btn-close-ep');

    function openModal() {
        modal.classList.remove('hidden');
        void modal.offsetWidth; // force reflow
        backdrop.style.opacity = '0';
        sheet.style.transform = 'translateY(100%)';
        requestAnimationFrame(function() {
            backdrop.style.opacity = '1';
            sheet.style.transform = 'translateY(0)';
        });
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        backdrop.style.opacity = '0';
        sheet.style.transform = 'translateY(100%)';
        setTimeout(function() {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }, 300);
    }

    if (btnOpen)  btnOpen.addEventListener('click', openModal);
    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (backdrop) backdrop.addEventListener('click', closeModal);
})();

/* --- Avatar Preview --- */
document.getElementById('avatarInput').addEventListener('change', function(e) {
    var file = e.target.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(ev) {
        var preview = document.getElementById('avatarPreview');
        var placeholder = document.getElementById('avatarPlaceholder');
        preview.src = ev.target.result;
        preview.classList.remove('hidden');
        placeholder.classList.add('hidden');
    };
    reader.readAsDataURL(file);
});
</script>