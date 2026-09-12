<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * plan/102 — Halaman pembayaran manual QRIS (member, dwibahasa EN/ID).
 *
 * Data dari Wallet::pay():
 *   $deposit (object), $now_ts (int), $expires_ts (int|null),
 *   $qris_image, $qris_merchant, $qris_notes, $wa_number
 *
 * Aturan tampilan:
 *   - Nominal PRIMER = `total_amount` yang dibekukan saat invoice dibuat
 *     (pokok + [fee] + kode unik) — TIDAK pernah dihitung ulang di sini.
 *   - Kode unik ditonjolkan. Split "3 digit terakhir" hanya dipakai bila pokok
 *     kelipatan 1000 (kondisi yang menjamin 3 digit terakhir total == kode);
 *     di luar itu penekanan dilakukan pada baris breakdown agar tidak menyesatkan.
 *   - Countdown memakai selisih (expires_ts − now_ts) dari SERVER sehingga jam
 *     client yang meleset tidak mengubah sisa waktu. Otoritas tetap server.
 */

$status     = (string) $deposit->status;
$is_legacy  = ($deposit->unique_code === null);
$base       = (int) $deposit->amount;
$code       = $is_legacy ? null : (int) $deposit->unique_code;
$total      = ((int) $deposit->total_amount > 0) ? (int) $deposit->total_amount : $base;
$fee_part   = $total - $base - ($code === null ? 0 : $code);

$is_expired  = ($deposit->expires_at !== null && strtotime($deposit->expires_at) <= $now_ts);
$can_pay     = ($status === 'pending' && !$is_expired);
$can_split   = ($code !== null && $base % 1000 === 0 && $total % 1000 === $code);

$total_str   = number_format($total, 0, ',', '.');
$total_head  = $can_split ? substr($total_str, 0, -3) : $total_str;
$total_code  = $can_split ? substr($total_str, -3)  : '';

// plan/103: tanggal WIB ber-lokalisasi (dulu date('d M Y') = nama bulan
// Inggris di kedua idiom). 'WIB' tetap literal — label zona teknis (D5).
$expires_wib = ($deposit->expires_at !== null)
    ? i18n_datetime($deposit->expires_at) . ' WIB'
    : '—';
?>

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

    <!-- ===== HEADER: invoice + status ===== -->
    <div class="flex items-center justify-between gap-3">
        <a href="<?= site_url('wallet') ?>" class="flex items-center gap-1.5 text-xs font-bold u-text-2 hover:u-text transition">
            <i class="fas fa-arrow-left text-[10px]"></i> <?= lang('wallet_pay_back') ?>
        </a>
        <?php
            $pill = [
                'pending'          => ['bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',   'fa-hourglass-half', lang('wallet_pay_status_pending')],
                'waiting_approval' => ['bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300', 'fa-user-shield',   lang('wallet_pay_status_waiting')],
                'success'          => ['bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300', 'fa-check-circle', lang('wallet_pay_status_success')],
                'rejected'         => ['bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',      'fa-times-circle',  lang('wallet_pay_status_rejected')],
                'expired'          => ['bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',     'fa-clock',         lang('wallet_pay_status_expired')],
            ];
            $p = $pill[$status] ?? ['bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300', 'fa-circle', strtoupper($status)];
        ?>
        <span class="shrink-0 inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full <?= $p[0] ?>">
            <i class="fas <?= $p[1] ?> text-[10px]"></i> <?= $p[2] ?>
        </span>
    </div>

    <div class="u-card rounded-2xl px-4 py-3 shadow-sm">
        <div class="text-[10px] uppercase tracking-widest u-muted font-bold"><?= lang('wallet_pay_invoice_label') ?></div>
        <div class="font-mono text-xs u-text break-all"><?= html_escape($deposit->invoice_number) ?></div>
    </div>

    <!-- ===== BANNER STATUS ===== -->
    <?php if ($status === 'waiting_approval'): ?>
        <div class="bg-indigo-50 dark:bg-indigo-500/10 border border-indigo-200 dark:border-indigo-500/20 rounded-2xl p-4">
            <div class="text-sm font-bold text-indigo-700 dark:text-indigo-300 flex items-center gap-2">
                <i class="fas fa-user-shield"></i> <?= lang('wallet_pay_waiting_title') ?>
            </div>
            <p class="text-xs text-indigo-700/80 dark:text-indigo-300/80 mt-1.5 leading-relaxed"><?= lang('wallet_pay_waiting_body') ?></p>
            <?php if ($deposit->confirmed_at !== null): ?>
                <div class="text-[10px] font-mono text-indigo-700/70 dark:text-indigo-300/70 mt-2">
                    <?= lang('wallet_pay_confirmed_at') ?> <?= i18n_datetime($deposit->confirmed_at) ?> WIB
                </div>
            <?php endif; ?>
        </div>
    <?php elseif ($status === 'success'): ?>
        <div class="bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 rounded-2xl p-4">
            <div class="text-sm font-bold text-emerald-700 dark:text-emerald-300 flex items-center gap-2">
                <i class="fas fa-check-circle"></i> <?= lang('wallet_pay_success_title') ?>
            </div>
            <p class="text-xs text-emerald-700/80 dark:text-emerald-300/80 mt-1.5 leading-relaxed">
                <?= sprintf(lang('wallet_pay_success_body'), 'Rp ' . number_format($total, 0, ',', '.')) ?>
            </p>
            <a href="<?= site_url('wallet') ?>" class="inline-flex items-center gap-1.5 mt-3 text-[11px] font-bold text-emerald-700 dark:text-emerald-300 underline">
                <i class="fas fa-receipt text-[10px]"></i> <?= lang('wallet_ledger_title') ?>
            </a>
        </div>
    <?php elseif ($status === 'rejected'): ?>
        <div class="bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/20 rounded-2xl p-4">
            <div class="text-sm font-bold text-rose-700 dark:text-rose-300 flex items-center gap-2">
                <i class="fas fa-times-circle"></i> <?= lang('wallet_pay_rejected_title') ?>
            </div>
            <p class="text-xs text-rose-700/80 dark:text-rose-300/80 mt-1.5 leading-relaxed"><?= lang('wallet_pay_rejected_body') ?></p>
            <?php if (!empty($deposit->decline_reason)): ?>
                <div class="mt-2 text-[11px] font-semibold text-rose-700 dark:text-rose-300 bg-white/60 dark:bg-black/20 border border-rose-200/70 dark:border-rose-500/20 rounded-lg px-3 py-2">
                    <?= lang('wallet_pay_reason_label') ?> <?= html_escape($deposit->decline_reason) ?>
                </div>
            <?php endif; ?>
            <a href="https://wa.me/<?= urlencode($wa_number) ?>?text=<?= urlencode(lang('wallet_pay_help_wa_text') . ' ' . $deposit->invoice_number) ?>"
               target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 mt-3 text-[11px] font-bold text-rose-700 dark:text-rose-300 underline">
                <i class="fab fa-whatsapp text-[11px]"></i> <?= lang('wallet_pay_help_note') ?>
            </a>
        </div>
    <?php elseif ($status === 'expired' || $is_expired): ?>
        <div class="bg-slate-100 dark:bg-slate-700/30 border border-slate-200 dark:border-slate-600 rounded-2xl p-4">
            <div class="text-sm font-bold u-text flex items-center gap-2">
                <i class="fas fa-clock u-muted"></i> <?= lang('wallet_pay_expired_title') ?>
            </div>
            <p class="text-xs u-text-2 mt-1.5 leading-relaxed"><?= lang('wallet_pay_expired_body') ?></p>
        </div>
    <?php endif; ?>

    <?php if ($status === 'expired'): ?>
        <a href="<?= site_url('wallet') ?>"
           class="block w-full text-center u-btn-cyber text-white text-sm font-bold py-3 rounded-xl transition">
            <i class="fas fa-plus mr-1"></i> <?= lang('wallet_pay_expired_cta') ?>
        </a>
    <?php endif; ?>

    <!-- ===== NOMINAL (primer) ===== -->
    <div class="u-card rounded-2xl p-5 shadow-sm">
        <div class="text-[10px] uppercase tracking-widest u-muted font-bold"><?= lang('wallet_pay_total_label') ?></div>
        <div class="text-3xl font-extrabold u-text font-mono tracking-tight leading-tight mt-1 break-all">
            Rp <?php if ($can_split): ?><?= $total_head ?><span class="text-amber-500 dark:text-amber-400"><?= $total_code ?></span><?php else: ?><?= $total_str ?><?php endif; ?>
        </div>

        <div class="mt-3 rounded-xl u-card-inset px-3 py-2.5 space-y-1.5">
            <div class="flex items-center justify-between text-[11px]">
                <span class="u-muted"><?= lang('wallet_pay_principal_label') ?></span>
                <span class="font-mono font-bold u-text">Rp <?= number_format($base, 0, ',', '.') ?></span>
            </div>
            <?php if ($code !== null): ?>
            <div class="flex items-center justify-between text-[11px]">
                <span class="u-muted"><?= lang('wallet_pay_code_label') ?></span>
                <span class="font-mono font-extrabold text-amber-600 dark:text-amber-400 tracking-widest">+ <?= $code ?></span>
            </div>
            <?php endif; ?>
            <?php if ($fee_part > 0): ?>
            <div class="flex items-center justify-between text-[11px]">
                <span class="u-muted"><?= lang('wallet_service_fee') ?></span>
                <span class="font-mono font-bold u-text">Rp <?= number_format($fee_part, 0, ',', '.') ?></span>
            </div>
            <?php endif; ?>
            <div class="flex items-center justify-between text-[11px] pt-1.5 border-t border-[var(--u-border)]">
                <span class="font-bold u-text"><?= lang('wallet_pay_total_label') ?></span>
                <span class="font-mono font-extrabold u-text">Rp <?= $total_str ?></span>
            </div>
        </div>

        <?php if ($is_legacy): ?>
            <div class="mt-3 text-[11px] u-muted leading-relaxed">
                <i class="fas fa-info-circle mr-1"></i> <?= lang('wallet_pay_legacy_note') ?>
            </div>
        <?php else: ?>
            <div class="mt-3 text-[11px] font-semibold text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl px-3 py-2 leading-relaxed">
                <i class="fas fa-exclamation-triangle mr-1"></i> <?= lang('wallet_pay_code_hint') ?>
            </div>
        <?php endif; ?>

        <button type="button"
                id="btn-copy-total"
                data-copy="<?= $total ?>"
                class="mt-3 w-full flex items-center justify-center gap-2 text-xs font-bold u-btn-ghost py-2.5 rounded-xl transition active:scale-95"
                title="<?= lang('common_copy_title') ?>"
                aria-label="<?= lang('wallet_copy_aria') ?>">
            <i class="fas fa-copy text-[10px]"></i> <?= lang('wallet_copy_amount') ?>
        </button>
    </div>

    <!-- ===== COUNTDOWN / JENDELA BAYAR ===== -->
    <?php if ($status === 'pending' && !$is_expired): ?>
        <div class="u-card rounded-2xl px-5 py-4 shadow-sm flex items-center justify-between gap-3">
            <div>
                <div class="text-[10px] uppercase tracking-widest u-muted font-bold"><?= lang('wallet_pay_expires_in') ?></div>
                <?php /* plan/103: satuan jam/menit/detik diambil dari kamus dan
                         diumumkan lewat aria-label (bukan menyisipkan kata ke
                         dalam digit — angka tidak pernah diterjemahkan, L6). */ ?>
                <div id="countdown" class="text-2xl font-extrabold font-mono u-text tracking-tight"
                     data-expires-ts="<?= (int) $expires_ts ?>"
                     data-now-ts="<?= (int) $now_ts ?>"
                     data-unit-h="<?= html_escape(lang('time_unit_hours')) ?>"
                     data-unit-m="<?= html_escape(lang('time_unit_minutes')) ?>"
                     data-unit-s="<?= html_escape(lang('time_unit_seconds')) ?>"
                     data-expired-label="<?= html_escape(lang('wallet_pay_expired_title')) ?>"
                     aria-live="polite"
                     aria-label="--">--:--:--</div>
                <div class="text-[10px] u-muted font-mono mt-0.5"><?= lang('wallet_pay_expires_at') ?> <?= $expires_wib ?></div>
            </div>
            <i class="fas fa-hourglass-half text-2xl text-amber-500"></i>
        </div>
    <?php endif; ?>

    <!-- ===== QRIS ===== -->
    <div class="u-card rounded-2xl p-5 shadow-sm">
        <div class="text-[10px] uppercase tracking-widest u-muted font-bold mb-3 text-center"><?= lang('wallet_pay_scan_title') ?></div>

        <?php if ($qris_image !== ''): ?>
            <img src="<?= base_url('uploads/qris/' . $qris_image) ?>"
                 alt="<?= lang('wallet_pay_qris_alt') ?>"
                 class="w-full max-w-[280px] mx-auto aspect-square object-contain rounded-xl bg-white p-2 border border-[var(--u-border)]">
            <div class="text-center mt-3">
                <div class="text-[10px] uppercase tracking-widest u-muted font-bold"><?= lang('wallet_pay_merchant_label') ?></div>
                <div class="text-sm font-extrabold u-text tracking-wide"><?= html_escape($qris_merchant) ?></div>
            </div>
        <?php else: ?>
            <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl p-4 text-center">
                <i class="fas fa-qrcode text-2xl text-amber-500 mb-2"></i>
                <p class="text-xs font-semibold text-amber-700 dark:text-amber-300 leading-relaxed"><?= lang('wallet_pay_not_configured') ?></p>
                <a href="https://wa.me/<?= urlencode($wa_number) ?>"
                   target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1.5 mt-2 text-[11px] font-bold text-amber-700 dark:text-amber-300 underline">
                    <i class="fab fa-whatsapp"></i> <?= lang('wallet_pay_help_note') ?>
                </a>
            </div>
        <?php endif; ?>

        <?php
        /* =====================================================================
           plan/103 (W5) — LANGKAH KANONIK DI DALAM KARTU QRIS.

           Tiga langkah operasional (scan → transfer tepat → konfirmasi)
           SELALU dirender dari kamus, tepat di dalam kartu QRIS. Dengan
           begitu section ini tidak pernah bisa menjadi unilingual meskipun
           admin mengisi `qris_payment_instructions` (yang notabene ditulis
           dalam bahasa panel admin = Indonesia, invariant L1).

           Catatan admin tetap ditampilkan, tetapi sebagai blok ADITIF
           BERLABEL (wallet_pay_qris_notes_label) — pembaca tahu blok itu
           teks bebas merchant, bukan instruksi sistem.
           ===================================================================== */ ?>
        <div class="mt-4 pt-4 border-t border-[var(--u-border)]">
            <div class="text-[10px] uppercase tracking-widest u-muted font-bold mb-2.5"><?= lang('wallet_pay_steps_title') ?></div>
            <ol class="space-y-2.5">
                <li class="flex items-start gap-2.5">
                    <span class="shrink-0 w-5 h-5 rounded-full bg-blue-600 text-white text-[10px] font-bold flex items-center justify-center">1</span>
                    <span class="text-[11px] u-text-2 leading-relaxed"><?= lang('wallet_pay_step_scan') ?></span>
                </li>
                <li class="flex items-start gap-2.5">
                    <span class="shrink-0 w-5 h-5 rounded-full bg-blue-600 text-white text-[10px] font-bold flex items-center justify-center">2</span>
                    <span class="text-[11px] u-text-2 leading-relaxed"><?= lang('wallet_pay_step_transfer') ?></span>
                </li>
                <li class="flex items-start gap-2.5">
                    <span class="shrink-0 w-5 h-5 rounded-full bg-blue-600 text-white text-[10px] font-bold flex items-center justify-center">3</span>
                    <span class="text-[11px] u-text-2 leading-relaxed"><?= lang('wallet_pay_step_confirm') ?></span>
                </li>
            </ol>
        </div>

        <?php if (trim($qris_notes) !== ''): ?>
            <div class="mt-4 pt-4 border-t border-[var(--u-border)]">
                <div class="text-[10px] uppercase tracking-widest u-muted font-bold mb-1.5"><?= lang('wallet_pay_qris_notes_label') ?></div>
                <div class="text-[11px] u-text-2 leading-relaxed"><?= nl2br(html_escape($qris_notes)) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ===== CTA KONFIRMASI ===== -->
    <?php if ($can_pay): ?>
        <?php /* plan/103: dialog konfirmasi lewat data-confirm + handler JS
                 terpusat — menghapus kelas bug escaping atribut onsubmit
                 (str_replace hanya meng-escape apostrof) dan membuat string
                 dialog tersedia bersih dari kamus. */ ?>
        <?= form_open('wallet/confirm_payment/' . $deposit->invoice_number,
            ['id' => 'confirmPayForm', 'data-guard-submit' => '1',
             'data-confirm' => lang('wallet_pay_confirm_dialog')]) ?>
            <button type="submit" id="btn-confirm-pay"
                    class="w-full u-btn-cyber text-white text-sm font-bold py-3.5 rounded-xl transition">
                <i class="fas fa-check mr-1.5"></i> <?= lang('wallet_pay_confirm_btn') ?>
            </button>
        <?= form_close() ?>
        <p class="text-[10px] u-muted text-center leading-relaxed px-2"><?= lang('wallet_pay_confirm_note') ?></p>
    <?php endif; ?>

</div>

<script>
/* plan/102: string JS halaman ini — diterjemahkan server-side (plan/94 F1). */
var PAY_L = <?= json_encode([
    'expired_title' => lang('wallet_pay_expired_title'),
    'expired_body'  => lang('wallet_pay_expired_body'),
    'copied'        => lang('js_copied'),
    'copy_failed'   => lang('js_copy_failed'),
    'unit_h'        => lang('time_unit_hours'),
    'unit_m'        => lang('time_unit_minutes'),
    'unit_s'        => lang('time_unit_seconds'),
    'urgent'        => lang('wallet_pay_expires_soon'),
], JSON_UNESCAPED_UNICODE) ?>;

/* --- Dialog konfirmasi terpusat (plan/103: data-confirm, bukan onsubmit) --- */
(function () {
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        });
    });
})();

/* --- Countdown: selisih dari SERVER (bebas clock-skew client) --- */
(function () {
    var el = document.getElementById('countdown');
    if (!el) { return; }

    var expiresTs = parseInt(el.getAttribute('data-expires-ts'), 10);
    var nowTs     = parseInt(el.getAttribute('data-now-ts'), 10);
    if (!expiresTs || !nowTs) { return; }

    var remaining = expiresTs - nowTs;
    var reloaded  = false;

    // plan/103: satuan dari atribut data-* (server-rendered dari kamus).
    var U_H = el.getAttribute('data-unit-h') || '';
    var U_M = el.getAttribute('data-unit-m') || '';
    var U_S = el.getAttribute('data-unit-s') || '';
    var EXPIRED_LABEL = el.getAttribute('data-expired-label') || '';

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    // Angka tetap TIDAK diterjemahkan (L6); satuan hidup di aria-label agar
    // pembaca layar mendengar "12 minutes 3 seconds", bukan "00:12:03".
    function announce(h, m, s) {
        el.setAttribute('aria-label', h + ' ' + U_H + ' ' + m + ' ' + U_M + ' ' + s + ' ' + U_S);
    }

    function render() {
        if (remaining <= 0) {
            el.textContent = '00:00:00';
            el.setAttribute('aria-label', EXPIRED_LABEL);
            // Jendela bayar habis: matikan CTA, tampilkan banner, lalu reload
            // SEKALI agar state server (sweep expiry) menjadi otoritatif.
            var btn = document.getElementById('btn-confirm-pay');
            if (btn) { btn.disabled = true; btn.classList.add('opacity-40', 'cursor-not-allowed'); }
            if (!reloaded) {
                reloaded = true;
                setTimeout(function () { location.reload(); }, 1200);
            }
            return;
        }
        var h = Math.floor(remaining / 3600);
        var m = Math.floor((remaining % 3600) / 60);
        var s = remaining % 60;
        el.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
        // Catatan plan/103: ambang "segera berakhir" sengaja TIDAK diwujudkan
        // sebagai kelas CSS baru di sini (view ini tidak punya blok <style>,
        // dan kelas tanpa aturan = dead code). Yang otoritatif tetap banner
        // server setelah jendela habis.
        announce(h, m, s);
        remaining--;
    }

    render();
    setInterval(render, 1000);
})();

/* --- Tombol salin nominal (integer mentah, tanpa format) --- */
(function () {
    var btn = document.getElementById('btn-copy-total');
    if (!btn) { return; }

    function flash(ok) {
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-' + (ok ? 'check' : 'times') + ' text-[10px]"></i> '
            + (ok ? PAY_L.copied : PAY_L.copy_failed);
        setTimeout(function () { btn.innerHTML = orig; }, 1500);
    }

    btn.addEventListener('click', function () {
        var val = btn.getAttribute('data-copy');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(val).then(function () { flash(true); }, function () { flash(false); });
            return;
        }
        var ta = document.createElement('textarea');
        ta.value = val;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); flash(true); } catch (e) { flash(false); }
        document.body.removeChild(ta);
    });
})();
</script>
