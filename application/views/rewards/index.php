<?php
/**
 * plan/121 — LUCKY SPIN WHEEL (member page).
 *
 * Zero-trust: roda HANYA menggambar konfigurasi dari server; hadiah
 * ditentukan & dikreditkan di backend (`Spin_model::spin()`), klien menerima
 * `slot_index` untuk animasi. Slot `amount`/`odds_bp` dirender server-side
 * (L6: tidak ada angka uang di kamus bahasa).
 *
 * Motion: transform-only + `prefers-reduced-motion: reduce` (SP10).
 *
 * @var array $spin Hasil Spin_model::get_summary()
 */
$spin_segments  = isset($spin['segments']) && is_array($spin['segments']) ? $spin['segments'] : [];
$spin_n         = count($spin_segments);
$spin_ready     = !empty($spin['enabled']) && !empty($spin['configured']) && $spin_n > 0;
$spin_tickets   = (int) ($spin['tickets'] ?? 0);
$spin_can       = !empty($spin['can_spin']);
$spin_balance   = isset($global_balance) ? (int) $global_balance : 0;

// Palet segmen (indeks → warna). Maksimal 12 segmen (spin_wheel_limits()).
$spin_palette = ['#6366f1', '#8b5cf6', '#06b6d4', '#0ea5e9', '#10b981', '#f59e0b',
                 '#ef4444', '#ec4899', '#14b8a6', '#a855f7', '#f97316', '#64748b'];

$spin_angle = ($spin_n > 0) ? (360 / $spin_n) : 360;
$spin_stops = [];
foreach ($spin_segments as $i => $seg) {
    $from = round($i * $spin_angle, 3);
    $to   = round(($i + 1) * $spin_angle, 3);
    $spin_stops[] = $spin_palette[$i % count($spin_palette)] . ' ' . $from . 'deg ' . $to . 'deg';
}
?>
<!-- ═══ plan/121 — Lucky Spin Wheel ═══ -->
<div class="p-4 space-y-6">

    <!-- ═══ Header + pil tiket ═══ -->
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-lg font-extrabold u-text flex items-center gap-2">
                <i class="fas fa-dharmachakra text-amber-500"></i>
                <?= lang('spin_page_title') ?>
            </h2>
            <p class="text-xs u-text-2 mt-1"><?= lang('spin_hero_subtitle') ?></p>
        </div>
        <div class="shrink-0 u-card rounded-2xl px-3 py-2 text-center min-w-[86px]">
            <p class="text-[9px] u-muted font-semibold uppercase tracking-wider"><?= lang('spin_tickets_label') ?></p>
            <p class="text-xl font-extrabold u-text leading-none mt-0.5">
                <span id="spin-tickets"><?= $spin_tickets ?></span>
            </p>
            <p class="text-[9px] u-muted mt-0.5"><?= lang('spin_ticket_unit') ?></p>
        </div>
    </div>

<?php if (!$spin_ready): ?>
    <!-- ═══ State fail-closed (SP7): fitur OFF atau konfigurasi roda rusak ═══ -->
    <div class="u-card rounded-2xl p-5 shadow-sm text-center">
        <span class="w-12 h-12 mx-auto rounded-full bg-slate-500/10 text-slate-500 flex items-center justify-center">
            <i class="fas fa-power-off text-lg"></i>
        </span>
        <p class="text-sm font-bold u-text mt-3">
            <?= lang(!empty($spin['enabled']) ? 'spin_err_not_configured' : 'spin_err_disabled') ?>
        </p>
        <p class="text-xs u-text-2 mt-1"><?= lang('spin_rules_body') ?></p>
    </div>
<?php else: ?>

    <!-- ═══ Roda hadiah ═══ -->
    <div class="u-card rounded-2xl p-4 shadow-sm">
        <div class="relative w-full max-w-[300px] mx-auto aspect-square select-none">

            <!-- Penunjuk (12 jam) — di luar roda agar tidak ikut berputar -->
            <div class="absolute left-1/2 -translate-x-1/2 -top-2 z-20 w-0 h-0"
                 style="border-left: 11px solid transparent; border-right: 11px solid transparent; border-top: 20px solid #f59e0b; filter: drop-shadow(0 2px 3px rgba(0,0,0,.25));"></div>

            <div id="spin-wheel" class="spin-wheel absolute inset-0 rounded-full border-[6px] border-white/80 dark:border-slate-800"
                 style="background: conic-gradient(<?= implode(', ', $spin_stops) ?>);">
                <?php foreach ($spin_segments as $i => $seg): ?>
                <div class="spin-label" style="--mid: <?= round($i * $spin_angle + $spin_angle / 2, 3) ?>deg">
                    <span class="absolute left-1/2 top-2.5 -translate-x-1/2 whitespace-nowrap text-[10px] font-extrabold text-white"
                          style="text-shadow: 0 1px 2px rgba(0,0,0,.45);">Rp <?= number_format((int) $seg['amount'], 0, ',', '.') ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Hub -->
            <div class="absolute inset-0 m-auto w-14 h-14 rounded-full z-10 flex items-center justify-center
                        bg-white dark:bg-slate-900 shadow-lg border-4 border-amber-400">
                <i class="fas fa-star text-amber-500 text-lg"></i>
            </div>
        </div>

        <!-- Peluang tertinggi (informasi, dihitung server) -->
        <p class="text-[10px] u-muted text-center mt-3">
            <?= lang('spin_odds_hint') ?>:
            <?php
            $spin_top = $spin_segments[0];
            foreach ($spin_segments as $seg) {
                if ((int) $seg['odds_bp'] > (int) $spin_top['odds_bp']) { $spin_top = $seg; }
            }
            ?>
            Rp <?= number_format((int) $spin_top['amount'], 0, ',', '.') ?>
            (<?= number_format((int) $spin_top['odds_bp'] / 100, 2, ',', '.') ?>%)
        </p>

        <!-- Tombol putar -->
        <button id="spin-btn" type="button"
                class="mt-4 w-full flex items-center justify-center gap-2 py-3.5 rounded-xl text-sm font-extrabold
                       text-white bg-gradient-to-r from-amber-500 to-orange-500 shadow-lg shadow-amber-500/30
                       active:scale-95 transition-transform duration-150
                       disabled:opacity-50 disabled:active:scale-100 disabled:cursor-not-allowed"
                <?= $spin_can ? '' : 'disabled' ?>
                data-has-ticket="<?= $spin_can ? '1' : '0' ?>"
                aria-label="<?= lang($spin_can ? 'spin_cta_spin' : 'spin_cta_no_ticket') ?>">
            <span id="spin-btn-label">
                <?= lang($spin_can ? 'spin_cta_spin' : 'spin_cta_no_ticket') ?>
            </span>
        </button>

        <?php if (!$spin_can): ?>
        <p class="text-[10px] u-text-2 text-center mt-2"><?= lang('spin_dashboard_no_ticket_hint') ?></p>
        <a href="<?= site_url('team') ?>"
           class="mt-3 flex items-center justify-center gap-2 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600
                  text-white text-xs font-bold transition-colors">
            <i class="fas fa-user-plus text-xs"></i>
            <?= lang('spin_empty_cta') ?>
        </a>
        <?php endif; ?>
    </div>

    <!-- ═══ Panel hasil (aria-live) ═══ -->
    <div id="spin-result" class="hidden rounded-2xl p-5 shadow-sm text-center border border-amber-400/40
                                 bg-gradient-to-br from-amber-500/15 via-amber-400/5 to-orange-500/15"
         role="status" aria-live="polite">
        <span class="w-12 h-12 mx-auto rounded-full bg-amber-500 text-white flex items-center justify-center shadow-lg shadow-amber-500/30">
            <i class="fas fa-trophy text-lg"></i>
        </span>
        <p class="text-[10px] u-muted font-semibold uppercase tracking-wider mt-3"><?= lang('spin_win_title') ?></p>
        <p class="text-2xl font-extrabold u-text leading-none mt-1">
            Rp <span id="spin-result-amount">0</span>
        </p>
        <p class="text-xs u-text-2 mt-2"><?= lang('spin_win_desc') ?></p>
        <div class="mt-3 flex items-center justify-center gap-3 text-[10px] u-muted">
            <span><?= lang('spin_tickets_label') ?>: <span id="spin-result-tickets">0</span></span>
            <span>&middot;</span>
            <span><?= lang('market_your_balance') ?>: Rp <span id="spin-result-balance"><?= number_format($spin_balance, 0, ',', '.') ?></span></span>
        </div>
        <button type="button" id="spin-result-close"
                class="mt-4 px-4 py-2 rounded-lg u-btn-ghost text-xs font-bold"><?= lang('spin_close') ?></button>
    </div>

    <!-- ═══ Cara mendapat tiket ═══ -->
    <div class="u-card rounded-2xl p-5 shadow-sm">
        <h3 class="text-sm font-bold u-text mb-3 flex items-center gap-2">
            <i class="fas fa-circle-info text-indigo-500"></i>
            <?= lang('spin_how_title') ?>
        </h3>
        <ol class="space-y-2.5">
            <?php foreach (['spin_how_step1', 'spin_how_step2', 'spin_how_step3'] as $spin_i => $spin_key): ?>
            <li class="flex items-start gap-2.5">
                <span class="shrink-0 w-5 h-5 rounded-full bg-indigo-500/10 text-indigo-500 text-[10px] font-extrabold flex items-center justify-center mt-0.5">
                    <?= $spin_i + 1 ?>
                </span>
                <span class="text-xs u-text-2 leading-relaxed"><?= lang($spin_key) ?></span>
            </li>
            <?php endforeach; ?>
        </ol>
        <div class="mt-4 rounded-lg p-3 border border-indigo-200 dark:border-indigo-500/20 bg-indigo-50 dark:bg-indigo-500/10">
            <p class="text-[11px] u-text-2 leading-relaxed"><?= lang('spin_rules_body') ?></p>
        </div>
    </div>

    <!-- ═══ Riwayat spin ═══ -->
    <div class="u-card rounded-2xl p-5 shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold u-text flex items-center gap-2">
                <i class="fas fa-clock-rotate-left text-indigo-500"></i>
                <?= lang('spin_history_title') ?>
            </h3>
            <span class="text-[10px] u-muted">
                <?= lang('spin_tickets_label') ?>: <?= (int) ($spin['earned'] ?? 0) ?> &middot; <?= lang('spin_history_title') ?>: <?= (int) ($spin['used'] ?? 0) ?>
            </span>
        </div>
        <?php if (empty($spin['history'])): ?>
        <p class="text-xs u-text-2 text-center py-3"><?= lang('spin_history_empty') ?></p>
        <?php else: ?>
        <ul class="divide-y divide-[var(--t-border)]">
            <?php foreach ($spin['history'] as $spin_row): ?>
            <li class="flex items-center justify-between gap-3 py-2.5">
                <div class="min-w-0">
                    <p class="text-xs font-bold u-text">Rp <?= number_format((int) $spin_row['amount'], 0, ',', '.') ?></p>
                    <p class="text-[10px] u-muted"><?= i18n_datetime($spin_row['created_at']) ?> WIB</p>
                </div>
                <span class="shrink-0 text-[10px] font-mono u-muted">
                    <?= lang('spin_history_prize') ?> #<?= (int) $spin_row['slot_index'] + 1 ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- ═══ Toast ═══ -->
    <div id="spin-toast"
         class="fixed left-1/2 -translate-x-1/2 bottom-24 px-4 py-2 u-toast text-xs font-medium rounded-xl
                opacity-0 transition-opacity duration-300 z-[60] shadow-lg pointer-events-none"></div>

    <style>
    /* plan/121: animasi roda = transform-only (GPU-friendly), 4.2 s ease-out. */
    .spin-wheel {
        transition: transform 4.2s cubic-bezier(.16, 1, .3, 1);
        will-change: transform;
    }
    .spin-label {
        position: absolute;
        inset: 0;
        transform: rotate(var(--mid));
        pointer-events: none;
    }
    @media (prefers-reduced-motion: reduce) {
        .spin-wheel { transition: none !important; }
    }
    </style>

    <script>
    // plan/121 — klien HANYA memutar roda & menampilkan hasil dari server.
    (function () {
        var SEGMENTS   = <?= (int) $spin_n ?>;
        var SEG_ANGLE  = SEGMENTS > 0 ? (360 / SEGMENTS) : 360;
        var SPIN_URL   = <?= json_encode(site_url('lucky-spin/spin')) ?>;
        var REDUCED    = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var I18N       = window.SYNAPSE_I18N || {};

        var wheel      = document.getElementById('spin-wheel');
        var btn        = document.getElementById('spin-btn');
        var btnLabel   = document.getElementById('spin-btn-label');
        var resultBox  = document.getElementById('spin-result');
        var resAmount  = document.getElementById('spin-result-amount');
        var resTickets = document.getElementById('spin-result-tickets');
        var resBalance = document.getElementById('spin-result-balance');
        var ticketsEl  = document.getElementById('spin-tickets');
        var toastEl    = document.getElementById('spin-toast');
        var closeBtn   = document.getElementById('spin-result-close');

        if (!wheel || !btn) { return; }

        var rotation = 0;
        var spinning = false;
        var numFmt   = new Intl.NumberFormat('id-ID');

        var labels = {
            spin:    <?= json_encode(lang('spin_cta_spin')) ?>,
            spinning:<?= json_encode(lang('spin_cta_spinning')) ?>,
            none:    <?= json_encode(lang('spin_cta_no_ticket')) ?>,
            win:     <?= json_encode(lang('spin_win_title')) ?>,
            err:     <?= json_encode(lang('spin_err_generic')) ?>,
            network: <?= json_encode(lang('js_spin_network')) ?>
        };

        function showToast(msg) {
            if (!toastEl) { return; }
            toastEl.textContent = msg;
            toastEl.classList.remove('opacity-0');
            setTimeout(function () { toastEl.classList.add('opacity-0'); }, 2600);
        }

        function setTickets(n) {
            if (ticketsEl) { ticketsEl.textContent = String(n); }
            if (resTickets) { resTickets.textContent = String(n); }
            if (btn) {
                btn.disabled = (n < 1);
                btn.setAttribute('data-has-ticket', n > 0 ? '1' : '0');
            }
            if (n < 1 && btnLabel) { btnLabel.textContent = labels.none; }
        }

        function revealResult(data) {
            if (resAmount) { resAmount.textContent = numFmt.format(data.prize || 0); }
            if (resBalance) { resBalance.textContent = numFmt.format(data.new_balance || 0); }
            if (resTickets) { resTickets.textContent = String(data.tickets_left || 0); }
            if (resultBox) { resultBox.classList.remove('hidden'); }
        }

        function finishSpin(data) {
            spinning = false;
            btn.classList.remove('opacity-60');
            btnLabel.textContent = (data.tickets_left > 0) ? labels.spin : labels.none;
            setTickets(data.tickets_left || 0);
            revealResult(data);
            showToast(labels.win);
        }

        function spinTo(slotIndex, data) {
            var center = slotIndex * SEG_ANGLE + SEG_ANGLE / 2;
            var rest   = ((rotation % 360) + 360) % 360;
            var delta  = ((360 - center) - rest + 720) % 360;

            rotation += (REDUCED ? 0 : 360 * 5) + delta;
            wheel.style.transform = 'rotate(' + rotation + 'deg)';

            if (REDUCED) {
                finishSpin(data);
                return;
            }

            var done = false;
            var onEnd = function () {
                if (done) { return; }
                done = true;
                wheel.removeEventListener('transitionend', onEnd);
                finishSpin(data);
            };
            wheel.addEventListener('transitionend', onEnd);
            setTimeout(onEnd, 4600);   // fallback bila transitionend tidak dipicu
        }

        btn.addEventListener('click', function () {
            if (spinning || btn.getAttribute('data-has-ticket') !== '1') { return; }
            spinning = true;
            btn.classList.add('opacity-60');
            btnLabel.textContent = labels.spinning;

            csrfFetch(SPIN_URL, { method: 'POST' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    var d = res && res.data ? res.data : res;
                    if (res && res.success && d && typeof d.slot_index !== 'undefined' && d.slot_index >= 0) {
                        spinTo(d.slot_index, d);
                        return;
                    }

                    // Penolakan bisnis: sinkronkan state tanpa reload.
                    spinning = false;
                    btn.classList.remove('opacity-60');
                    var left = (d && typeof d.tickets_left !== 'undefined') ? d.tickets_left : 0;
                    var summary = (d && d.summary) ? d.summary : null;
                    if (summary && typeof summary.tickets !== 'undefined') { left = summary.tickets; }
                    setTickets(left);
                    btnLabel.textContent = (left > 0) ? labels.spin : labels.none;
                    showToast((res && res.message) ? res.message : labels.err);
                })
                .catch(function () {
                    spinning = false;
                    btn.classList.remove('opacity-60');
                    btnLabel.textContent = labels.spin;
                    showToast(labels.network);
                });
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                if (resultBox) { resultBox.classList.add('hidden'); }
            });
        }
    })();
    </script>
<?php endif; ?>
</div>
