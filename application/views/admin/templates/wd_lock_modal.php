<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!-- ============================================================ -->
<!-- plan/117: MODAL KUNCI PENARIKAN PER-USER (Withdrawal Lock)   -->
<!--                                                              -->
<!-- Partial generik — satu modal melayani SEMUA baris di         -->
<!-- views/admin/users.php maupun kartu di user_detail.php.       -->
<!-- Dipanggil via openWdLockModal(userId, label) /               -->
<!-- unlockWdLock(userId). Copy 100% Indonesia (invarian L1) dan  -->
<!-- TIDAK melewati i18n. Mutasi dikirim sebagai AJAX POST        -->
<!-- (`csrfFetch` menyuntik token CSRF + X-Requested-With) dan    -->
<!-- jawabannya envelope api_helper; sukses → reload halaman agar -->
<!-- badge/alasan dirender ulang dari server (satu sumber         -->
<!-- kebenaran, tanpa bedah DOM).                                 -->
<!-- ============================================================ -->
<div id="wdLockModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center">
    <!-- Backdrop -->
    <div class="absolute inset-0 t-modal-backdrop" onclick="window.closeWdLockModal()"></div>

    <!-- Modal Card -->
    <div class="relative t-modal shadow-2xl w-full max-w-md mx-4 p-6">
        <div class="flex items-center justify-between mb-5">
            <h4 class="text-base font-bold text-[var(--t-text)] flex items-center gap-2">
                <i class="fas fa-lock text-rose-500"></i> Kunci Penarikan (Withdrawal Lock)
            </h4>
            <button type="button" onclick="window.closeWdLockModal()"
                    class="text-[var(--t-muted)] hover:text-[var(--t-text-2)] transition-colors">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>

        <p class="text-xs t-text-2 mb-4">
            User: <span id="wdLockPhone" class="font-mono font-semibold text-[var(--t-text)]">—</span>
        </p>

        <form id="wdLockForm" onsubmit="return window.submitWdLock(event)" class="space-y-4">
            <input type="hidden" id="wdLockUserId" value="">

            <div>
                <label class="t-label text-xs mb-1" for="wdLockReason">
                    Alasan Penguncian <span class="text-red-500">*</span>
                </label>
                <input type="text" id="wdLockReason" maxlength="255" required autocomplete="off"
                       placeholder="mis. indikasi akun ganda / penyalahgunaan bonus"
                       class="t-input w-full px-3 py-2 rounded-lg text-sm
                              focus:outline-none focus:ring-2 focus:ring-rose-500 focus:border-rose-500">
                <p class="text-[11px] text-[var(--t-muted)] mt-1">
                    Alasan bersifat <strong>internal</strong> — tidak pernah ditampilkan ke member.
                    Hanya tercatat di audit log.
                </p>
            </div>

            <p class="text-[11px] text-amber-600 dark:text-amber-400">
                <i class="fas fa-info-circle"></i>
                Kunci ini hanya memblokir <strong>pengajuan penarikan baru</strong>. Penarikan yang
                sudah pending tetap diproses admin; user tetap bisa login dan menyewa.
            </p>

            <p id="wdLockError" class="hidden t-flash-error px-3 py-2 rounded-lg text-xs font-semibold"></p>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" id="wdLockSubmit"
                        class="px-5 py-2 rounded-lg bg-rose-600 text-white text-sm font-medium hover:bg-rose-700 active:bg-rose-800 transition-colors flex items-center gap-2">
                    <i class="fas fa-lock text-xs"></i> Kunci Penarikan
                </button>
                <button type="button" onclick="window.closeWdLockModal()"
                        class="px-4 py-2 rounded-lg t-btn-ghost text-sm border border-[var(--t-border)]">
                    Batal
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var ENDPOINT = <?= json_encode(site_url('admin/toggle_wd_lock')) ?>;

    var modal   = document.getElementById('wdLockModal');
    var form    = document.getElementById('wdLockForm');
    var uidIn   = document.getElementById('wdLockUserId');
    var phoneEl = document.getElementById('wdLockPhone');
    var reason  = document.getElementById('wdLockReason');
    var errBox  = document.getElementById('wdLockError');
    var submit  = document.getElementById('wdLockSubmit');

    if (!modal || !form) { return; }

    function showError(msg) {
        if (!errBox) { return; }
        errBox.textContent = msg;
        errBox.classList.remove('hidden');
    }

    function clearError() {
        if (!errBox) { return; }
        errBox.textContent = '';
        errBox.classList.add('hidden');
    }

    /** POST JSON ke endpoint mutator; selalu reload saat sukses. */
    function sendLock(userId, lockValue, reasonText) {
        var fd = new FormData();
        fd.append('lock', lockValue);
        if (lockValue === '1') { fd.append('reason', reasonText); }

        return window.csrfFetch(ENDPOINT + '/' + parseInt(userId, 10), {
            method: 'POST',
            body: fd,
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (json && json.success) {
                window.location.reload();
                return true;
            }
            showError((json && (json.message || json.error)) || 'Gagal mengubah status kunci.');
            return false;
        })
        .catch(function () {
            showError('Terjadi kesalahan jaringan. Coba lagi.');
            return false;
        });
    }

    window.openWdLockModal = function (userId, label) {
        uidIn.value    = parseInt(userId, 10) || '';
        phoneEl.textContent = label || '—';
        reason.value   = '';
        clearError();
        modal.classList.remove('hidden');
        if (reason) { reason.focus(); }
    };

    window.closeWdLockModal = function () {
        modal.classList.add('hidden');
        clearError();
    };

    window.submitWdLock = function (e) {
        if (e) { e.preventDefault(); }

        var userId = parseInt(uidIn.value, 10) || 0;
        var text   = reason ? String(reason.value || '').trim() : '';

        if (!userId) { showError('User tidak valid.'); return false; }
        if (text === '') { showError('Alasan penguncian wajib diisi.'); return false; }
        if (text.length > 255) { showError('Alasan maksimal 255 karakter.'); return false; }

        clearError();
        if (submit) { submit.disabled = true; submit.classList.add('opacity-60', 'cursor-not-allowed'); }

        sendLock(userId, '1', text).then(function (ok) {
            if (!ok && submit) {
                submit.disabled = false;
                submit.classList.remove('opacity-60', 'cursor-not-allowed');
            }
        });

        return false;
    };

    window.unlockWdLock = function (userId) {
        if (!confirm('Buka kunci penarikan user ini? Alasan penguncian akan dihapus.')) { return; }
        sendLock(userId, '0', '');
    };
})();
</script>
