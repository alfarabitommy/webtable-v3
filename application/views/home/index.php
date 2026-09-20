<div class="p-4 space-y-6">

    <style>
    /* ═══ Plan 99: Home Hero (AI Neural Cluster) + World Node Map — scoped hm99-* ═══
       Aturan: hanya color/bg/border/shadow/animation; radius & spacing tetap utility Tailwind. */

    /* ---------- Hero canvas (LAYER 1) ---------- */
    .hm99-hero {
        position: relative; overflow: hidden; isolation: isolate;
        min-height: 224px; display: flex; flex-direction: column; justify-content: space-between;
        background: linear-gradient(165deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e2e8f0; box-shadow: var(--u-glow);
    }
    html.dark .hm99-hero {
        background: linear-gradient(160deg, #0b1120 0%, #0d1526 55%, #0b1120 100%);
        border: 1px solid rgba(56, 189, 248, .16);
        box-shadow: 0 0 24px rgba(56, 189, 248, .10), 0 0 60px rgba(99, 102, 241, .08),
                    inset 0 0 40px rgba(56, 189, 248, .04);
    }
    /* Neural dot lattice (LAYER 2b) — mask fade ke kanan-atas */
    .hm99-hero::before {
        content: ""; position: absolute; inset: 0; z-index: 0; pointer-events: none;
        background-image: radial-gradient(circle, rgba(14, 165, 233, .35) 1px, transparent 1.5px);
        background-size: 20px 20px; opacity: .5;
        -webkit-mask-image: radial-gradient(ellipse 75% 95% at 85% 15%, #000 0%, transparent 65%);
                mask-image: radial-gradient(ellipse 75% 95% at 85% 15%, #000 0%, transparent 65%);
    }
    html.dark .hm99-hero::before {
        background-image: radial-gradient(circle, rgba(103, 232, 249, .5) 1px, transparent 1.6px);
        opacity: .7;
    }
    /* Sheen atas (LAYER 2c) */
    .hm99-hero::after {
        content: ""; position: absolute; inset: 0; z-index: 0; pointer-events: none;
        background: linear-gradient(180deg, rgba(255,255,255,.5), transparent 40%);
    }
    html.dark .hm99-hero::after { background: linear-gradient(180deg, rgba(103,232,249,.06), transparent 45%); }

    /* Glow orbs (LAYER 2) */
    .hm99-orb { position: absolute; z-index: 0; border-radius: 9999px; pointer-events: none; filter: blur(24px); }
    .hm99-orb-a { width: 170px; height: 170px; right: -40px; top: -55px;
                  background: radial-gradient(circle, rgba(6,182,212,.16), transparent 65%);
                  animation: hm99-drift 9s ease-in-out infinite alternate; }
    .hm99-orb-b { width: 190px; height: 190px; left: -70px; bottom: -80px;
                  background: radial-gradient(circle, rgba(99,102,241,.13), transparent 65%);
                  animation: hm99-drift 11s ease-in-out 1s infinite alternate-reverse; }
    html.dark .hm99-orb-a { background: radial-gradient(circle, rgba(56,189,248,.20), transparent 65%); }
    html.dark .hm99-orb-b { background: radial-gradient(circle, rgba(99,102,241,.17), transparent 65%); }
    @keyframes hm99-drift {
        0%   { transform: translate(0, 0) scale(1); }
        100% { transform: translate(-14px, 10px) scale(1.06); }
    }

    /* Konten hero (LAYER 3) */
    .hm99-hero-content { position: relative; z-index: 10; display: flex; flex-direction: column;
                         gap: .75rem; height: 100%; }
    /* Gradient title (cyan→indigo dark / deep indigo light) */
    .hm99-grad-text {
        background-image: linear-gradient(92deg, #312e81 0%, #4f46e5 55%, #0e7490 100%);
        -webkit-background-clip: text; background-clip: text; color: transparent;
    }
    html.dark .hm99-grad-text {
        background-image: linear-gradient(92deg, #22d3ee 0%, #60a5fa 45%, #818cf8 100%);
    }
    @supports not ((-webkit-background-clip: text) or (background-clip: text)) {
        .hm99-grad-text { background-image: none; color: var(--u-text); }
    }
    /* Status dot pulsing */
    .hm99-statusdot { width: 7px; height: 7px; border-radius: 9999px; background: #34d399;
                      box-shadow: 0 0 0 0 rgba(52, 211, 153, .65); animation: hm99-ping 2.0s ease-out infinite; }
    .hm99-live-dot { width: 5px; height: 5px; border-radius: 9999px; background: currentColor;
                     box-shadow: 0 0 0 0 currentColor; animation: hm99-ping 2.0s ease-out infinite; }
    @keyframes hm99-ping {
        0%   { box-shadow: 0 0 0 0 rgba(52, 211, 153, .65); }
        70%  { box-shadow: 0 0 0 6px rgba(52, 211, 153, 0); }
        100% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0); }
    }
    /* HUD pills */
    .hm99-pill { display: inline-flex; align-items: center; gap: .4rem; padding: .28rem .55rem;
                 border-radius: .7rem; background: #ffffff; border: 1px solid #e2e8f0;
                 box-shadow: 0 1px 2px rgba(15,23,42,.05); }
    html.dark .hm99-pill { background: rgba(148,163,184,.08); border: 1px solid rgba(148,163,184,.18); box-shadow: none; }
    .hm99-pill-ic { width: 16px; height: 16px; border-radius: 9999px; display: inline-flex; align-items: center;
                    justify-content: center; font-size: 8px; flex: none; }
    .hm99-pill-cy { background: rgba(8,145,178,.12); color: #0e7490; }
    .hm99-pill-em { background: rgba(5,150,105,.12); color: #047857; }
    .hm99-pill-in { background: rgba(79,70,229,.12); color: #4f46e5; }
    html.dark .hm99-pill-cy { background: rgba(34,211,238,.14); color: #22d3ee; }
    html.dark .hm99-pill-em { background: rgba(52,211,153,.14); color: #34d399; }
    html.dark .hm99-pill-in { background: rgba(129,140,248,.14); color: #818cf8; }
    .hm99-pill-lbl { font-family: 'JetBrains Mono', monospace; font-size: 9px; font-weight: 600;
                     color: #334155; letter-spacing: .02em; line-height: 1.2; }
    html.dark .hm99-pill-lbl { color: #cbd5e1; }

    /* ---------- World Node Map card ---------- */
    .hm99-map { position: relative; background: var(--u-surface); border: 1px solid var(--u-border); }
    .hm99-map-wrap { position: relative; width: 100%; }
    .hm99-map-svg { display: block; width: 100%; height: auto; }
    .hm99-chip { display: inline-flex; align-items: center; gap: .35rem; padding: .25rem .55rem;
                 border-radius: 9999px; font-size: 9px; font-weight: 700; letter-spacing: .02em;
                 background: #f8fafc; border: 1px solid #e2e8f0; color: #475569; }
    html.dark .hm99-chip { background: rgba(148,163,184,.07); border: 1px solid rgba(148,163,184,.14); color: #94a3b8; }
    .hm99-chip i { font-size: 8px; }
    .hm99-chip-live { color: #047857; }
    html.dark .hm99-chip-live { color: #34d399; }

    /* SVG: warna via kelas (parity satu sumber — dark/light) */
    .hm99-grat { stroke: rgba(100,116,139,.14); stroke-width: 1; fill: none; }
    html.dark .hm99-grat { stroke: rgba(148,163,184,.08); }
    .hm99-grat-eq { stroke: rgba(100,116,139,.28); }
    html.dark .hm99-grat-eq { stroke: rgba(148,163,184,.16); }
    .hm99-land, .hm99-isle { stroke-linejoin: round; stroke-linecap: round; }
    .hm99-land { fill: rgba(79,70,229,.055); stroke: rgba(79,70,229,.30); stroke-width: 2; }
    html.dark .hm99-land { fill: rgba(56,189,248,.085); stroke: rgba(103,232,249,.42);
                           filter: drop-shadow(0 0 5px rgba(34,211,238,.28)); }
    .hm99-isle { fill: rgba(79,70,229,.055); stroke: rgba(79,70,229,.26); stroke-width: 1.6; }
    html.dark .hm99-isle { fill: rgba(56,189,248,.085); stroke: rgba(103,232,249,.36); }

    /* Primary routes: base path + travelling normalized pulse (Plan 101, pathLength="1") */
    .hm99-route { fill: none; stroke: rgba(79,70,229,.20); stroke-width: 1.3; }
    html.dark .hm99-route { stroke: rgba(99,102,241,.38); }
    .hm99-flow { fill: none; stroke: #0891b2; stroke-width: 2.4; stroke-linecap: round;
                 stroke-dasharray: .04 .96; animation: hm99-flowp 3s linear infinite; }
    html.dark .hm99-flow { stroke: #22d3ee; filter: drop-shadow(0 0 4px rgba(34,211,238,.75)); }
    @keyframes hm99-flowp { to { stroke-dashoffset: -1; } }

    /* Secondary backbone: static dashed transit links (Plan 101) */
    .hm99-bb { fill: none; stroke: rgba(100,116,139,.38); stroke-width: 1;
               stroke-dasharray: 1.6 5.4; stroke-linecap: round; }
    html.dark .hm99-bb { stroke: rgba(148,163,184,.32); }

    /* Edge micro-nodes (unlabeled; opacity breathing, tanpa scale) */
    .hm99-edge { fill: #0ea5e9; filter: drop-shadow(0 0 3px rgba(14,165,233,.6));
                 animation: hm99-edgep 4s ease-in-out infinite alternate; }
    html.dark .hm99-edge { fill: #22d3ee; filter: drop-shadow(0 0 5px rgba(34,211,238,.8)); }
    .hm99-edge-halo { fill: none; stroke: rgba(14,165,233,.40); stroke-width: 1; }
    html.dark .hm99-edge-halo { stroke: rgba(34,211,238,.35); }
    @keyframes hm99-edgep { from { opacity: .45; } to { opacity: 1; } }
    .hm99-ncore { fill: #0891b2; }
    html.dark .hm99-ncore { fill: #22d3ee; }
    .hm99-nring { fill: none; stroke: rgba(8,145,178,.55); stroke-width: 1.4;
                  animation: hm99-node 2.4s cubic-bezier(0,0,.2,1) infinite; }
    html.dark .hm99-nring { stroke: rgba(34,211,238,.6); }
    @keyframes hm99-node {
        0%   { transform: scale(.7); opacity: .8; }
        70%  { transform: scale(1.9); opacity: 0; }
        100% { transform: scale(1.9); opacity: 0; }
    }
    .hm99-nring, .hm99-ncore { transform-box: fill-box; transform-origin: center; }

    /* Hub label overlay (HTML chip di atas SVG — posisi % = x/10, y/20 art-box) */
    .hm99-hub-label { position: absolute; transform: translate(-50%, -140%); pointer-events: none;
                      white-space: nowrap; padding: 2px 6px; border-radius: 6px; font-size: 9px;
                      font-weight: 700; letter-spacing: .03em; background: #ffffff;
                      border: 1px solid #e2e8f0; color: #334155; box-shadow: 0 1px 2px rgba(15,23,42,.06); }
    html.dark .hm99-hub-label { background: rgba(148,163,184,.10); border: 1px solid rgba(148,163,184,.22);
                                color: #cbd5e1; box-shadow: none; }
    .hm99-hub-jkt { transform: translate(-50%, 46%); }   /* di bawah node: hindari tabrakan dgn SIN */
    .hm99-hub-tyo { transform: translate(-108%, -140%); } /* kiri node: aman dari tepi kanan art-box */

    /* Reduced motion — semua animasi dimatikan; konten statis tetap terbaca */
    @media (prefers-reduced-motion: reduce) {
        .hm99-hero *, .hm99-map * { animation: none !important; transition: none !important; }
        .hm112-card * { animation: none !important; transition: none !important; }
        .hm99-statusdot, .hm99-live-dot { box-shadow: 0 0 0 2px rgba(52,211,153,.25); }
    }

    /* ═══ Plan 112: Widget Absensi Harian — scoped hm112-* ═══
       Aturan sama plan/99: hanya color/bg/border/shadow/animation; radius &
       spacing tetap utility Tailwind. Warna memakai token tema (--u-*) agar
       idiom terang/gelap konsisten tanpa duplikasi aturan. */
    .hm112-card {
        position: relative; overflow: hidden; isolation: isolate;
        background:
            linear-gradient(135deg, rgba(99, 102, 241, .10) 0%, rgba(6, 182, 212, .07) 100%),
            var(--u-surface);
        border: 1px solid var(--u-border-glow-2);
        box-shadow: var(--u-glow);
    }
    html.dark .hm112-card {
        background:
            linear-gradient(135deg, rgba(99, 102, 241, .18) 0%, rgba(6, 182, 212, .12) 100%),
            var(--u-surface);
        border: 1px solid rgba(99, 102, 241, .30);
    }
    .hm112-orb {
        position: absolute; width: 150px; height: 150px; border-radius: 50%;
        filter: blur(38px); opacity: .38; pointer-events: none; z-index: -1;
        animation: hm112-drift 9s ease-in-out infinite;
    }
    .hm112-orb-a { top: -56px; right: -40px;
                   background: radial-gradient(circle, rgba(99,102,241,.60), transparent 70%); }
    .hm112-orb-b { bottom: -64px; left: -46px; animation-delay: -4s;
                   background: radial-gradient(circle, rgba(6,182,212,.50), transparent 70%); }
    html.dark .hm112-orb { opacity: .55; }
    @keyframes hm112-drift {
        0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
        50%      { transform: translate3d(0, -10px, 0) scale(1.06); }
    }
    .hm112-ic { background: rgba(99,102,241,.12); border: 1px solid rgba(99,102,241,.28); }
    html.dark .hm112-ic { background: rgba(99,102,241,.18); border-color: rgba(129,140,248,.34); }
    .hm112-chip { background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.32);
                  color: #b45309; }
    html.dark .hm112-chip { color: #fbbf24; border-color: rgba(251,191,36,.32); }
    .hm112-day { background: var(--u-surface-2); border: 1px solid var(--u-border); }
    .hm112-day-next { background: rgba(99,102,241,.12); border-color: rgba(99,102,241,.42);
                      box-shadow: 0 0 0 2px rgba(99,102,241,.10); }
    html.dark .hm112-day-next { background: rgba(99,102,241,.20); border-color: rgba(129,140,248,.46); }
    .hm112-btn {
        background: linear-gradient(135deg, #4f46e5 0%, #0891b2 100%);
        border: 1px solid rgba(79,70,229,.35);
        box-shadow: 0 6px 18px rgba(79,70,229,.22);
        transition: opacity .15s ease, transform .15s ease;
    }
    .hm112-btn:hover:not(:disabled) { opacity: .93; }
    .hm112-btn:active:not(:disabled) { transform: scale(.985); }
    .hm112-btn:disabled {
        background: var(--u-surface-3); color: var(--u-text-2);
        border: 1px solid var(--u-border); box-shadow: none; cursor: not-allowed;
    }
    .hm112-pulse { animation: hm112-pulse 2.4s ease-out infinite; }
    @keyframes hm112-pulse {
        0%   { box-shadow: 0 0 0 0 rgba(99,102,241,.35); }
        75%  { box-shadow: 0 0 0 12px rgba(99,102,241,0); }
        100% { box-shadow: 0 0 0 0 rgba(99,102,241,0); }
    }
    </style>

    <!-- ═══ AI Neural Cluster Hero Card (Plan 99) ═══ -->
    <div class="hm99-hero rounded-3xl shadow-2xl">
        <div class="hm99-orb hm99-orb-a" aria-hidden="true"></div>
        <div class="hm99-orb hm99-orb-b" aria-hidden="true"></div>

        <div class="hm99-hero-content p-5">
            <!-- Status header: [ • ONLINE ] Synapse Engine v2.0 Active -->
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full border text-[10px] font-bold uppercase tracking-widest bg-emerald-500/15 border-emerald-500/30 text-emerald-600 dark:text-emerald-400">
                    <span class="hm99-statusdot"></span>
                    <?= lang('home_online') ?>
                </span>
                <span class="font-mono text-[8.5px] u-text-2 font-semibold tracking-[0.16em] uppercase"><?= lang('home_engine_line') ?></span>
            </div>

            <!-- Headline -->
            <div class="mt-auto">
                <h2 class="hm99-grad-text text-2xl font-extrabold leading-tight mb-1"><?= lang('home_hero_title') ?></h2>
            </div>

            <!-- Micro-HUD telemetry pills -->
            <div class="flex flex-wrap gap-1.5">
                <span class="hm99-pill"><span class="hm99-pill-ic hm99-pill-cy"><i class="fas fa-bolt"></i></span><span class="hm99-pill-lbl"><?= lang('home_hud_pflops') ?></span></span>
                <span class="hm99-pill"><span class="hm99-pill-ic hm99-pill-em"><i class="fas fa-lock"></i></span><span class="hm99-pill-lbl"><?= lang('home_hud_isolated') ?></span></span>
                <span class="hm99-pill"><span class="hm99-pill-ic hm99-pill-in"><i class="fas fa-globe"></i></span><span class="hm99-pill-lbl"><?= lang('home_hud_link') ?></span></span>
            </div>
        </div>
    </div>

    <?php if (!empty($checkin['enabled'])): ?>
    <?php
    // ═══ Plan 112: Widget Absensi Harian (Daily Check-in) ═══
    // Seluruh angka DIHITUNG & DIFORMAT di server (L6: uang tidak pernah masuk
    // kamus; view tidak pernah melakukan aritmetika uang). Persentase progres
    // memakai intdiv (M8 — tanpa pembagian float).
    $hm112_max  = max(1, (int) $checkin['max_reward']);
    // Persentase progres = ceil() integer murni (intdiv) → hari ke-1 (0,5%)
    // tetap terlihat sebagai 1%, cap = 100%. Tanpa pembagian float (M8).
    $hm112_pct  = min(100, intdiv(max(0, (int) $checkin['today_reward']) * 100 + $hm112_max - 1, $hm112_max));
    $hm112_done = !empty($checkin['claimed_today']);
    ?>
    <?php /* Peta string dinamis JS — pola SYNAPSE_I18N (plan/94 L5), digabung
             (Object.assign) agar header tidak perlu diubah. */ ?>
    <script>
    window.SYNAPSE_I18N = Object.assign(window.SYNAPSE_I18N || {}, <?= json_encode([
        'hm112_claim'       => lang('home_checkin_claim_btn'),
        'hm112_claimed'     => lang('home_checkin_claimed_btn'),
        'hm112_next_in'     => lang('home_checkin_next_in'),
        'hm112_window_left' => lang('home_checkin_window_left'),
        'hm112_generic'     => lang('home_checkin_err_generic'),
        'hm112_day_fmt'     => lang('home_checkin_day'),
    ], JSON_UNESCAPED_UNICODE) ?>);
    </script>

    <div id="hm112-card" class="hm112-card rounded-2xl p-5 shadow-sm"
         data-claimed="<?= $hm112_done ? '1' : '0' ?>"
         data-window-ts="<?= (int) $checkin['next_window_ts'] ?>"
         data-max-reward="<?= $hm112_max ?>">
        <div class="hm112-orb hm112-orb-a" aria-hidden="true"></div>
        <div class="hm112-orb hm112-orb-b" aria-hidden="true"></div>

        <!-- Identitas + chip streak -->
        <div class="flex items-start justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <div class="hm112-ic w-11 h-11 shrink-0 rounded-2xl flex items-center justify-center">
                    <i class="fas fa-calendar-check text-indigo-600 dark:text-indigo-400"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] u-muted font-semibold uppercase tracking-wider"><?= lang('home_checkin_title') ?></p>
                    <p class="text-[11px] u-text-2 leading-snug mt-0.5"><?= lang('home_checkin_subtitle') ?></p>
                </div>
            </div>
            <span class="hm112-chip shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold">
                <i class="fas fa-fire"></i>
                <span id="hm112-streak-days"><?= (int) $checkin['streak'] ?></span>
                <span><?= lang('home_checkin_streak_label') ?></span>
            </span>
        </div>

        <!-- Bonus hari ini + bar ke cap harian -->
        <div class="mt-4">
            <p class="text-[10px] u-muted font-semibold uppercase tracking-wider"><?= lang('home_checkin_today_reward') ?></p>
            <div class="flex items-end justify-between gap-3 mt-0.5">
                <p class="text-2xl font-extrabold u-text leading-none">Rp <span id="hm112-amount"><?= number_format((int) $checkin['today_reward'], 0, ',', '.') ?></span></p>
                <span id="hm112-day-label" class="text-[10px] u-muted font-mono"><?= sprintf(lang('home_checkin_day'), (int) $checkin['today_streak']) ?></span>
            </div>
            <div class="mt-2 h-1.5 w-full rounded-full u-progress-track overflow-hidden">
                <div id="hm112-bar" class="h-full rounded-full" style="width: <?= $hm112_pct ?>%; background: linear-gradient(90deg, #6366f1, #06b6d4);"></div>
            </div>
            <p class="text-[10px] u-muted mt-1.5"><?= lang('home_checkin_cap_label') ?> · Rp <?= number_format($hm112_max, 0, ',', '.') ?></p>
        </div>

        <!-- Pratinjau 7 hari ke depan (server-rendered) -->
        <div class="mt-4">
            <p class="text-[10px] u-muted font-semibold uppercase tracking-wider mb-1.5"><?= lang('home_checkin_preview') ?></p>
            <div class="grid grid-cols-7 gap-1">
                <?php foreach ($checkin['preview'] as $hm112_p): ?>
                <div class="hm112-day<?= !empty($hm112_p['is_next']) ? ' hm112-day-next' : '' ?> rounded-lg py-1.5 text-center"
                     data-day="<?= (int) $hm112_p['day'] ?>">
                    <span class="hm112-day-num block text-[9px] font-mono u-muted"><?= (int) $hm112_p['day'] ?></span>
                    <span class="hm112-day-amt block text-[10px] font-bold u-text-2"><?= number_format((int) $hm112_p['reward'], 0, ',', '.') ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Timer WIB + tombol klaim -->
        <div class="mt-4 flex items-center justify-between gap-2">
            <p id="hm112-timer" class="text-[10px] u-muted font-mono">&#8212;</p>
        </div>

        <button id="hm112-claim" type="button"
                class="hm112-btn w-full mt-2 h-12 rounded-xl flex items-center justify-center gap-2 text-sm font-bold text-white<?= $hm112_done ? '' : ' hm112-pulse' ?>"
                aria-label="<?= $hm112_done ? lang('home_checkin_claimed_btn') : lang('home_checkin_claim_btn') ?>"
                <?= $hm112_done ? 'disabled' : '' ?>>
            <i class="fas <?= $hm112_done ? 'fa-circle-check' : 'fa-hand-holding-usd' ?>"></i>
            <span id="hm112-btn-label"><?= $hm112_done ? lang('home_checkin_claimed_btn') : lang('home_checkin_claim_btn') ?></span>
        </button>

        <p class="text-[10px] u-muted mt-2 text-center"><?= lang($checkin['policy'] === 'continue' ? 'home_checkin_hint_continue' : 'home_checkin_hint_reset') ?></p>
        <p id="hm112-status" class="text-[10px] text-center text-emerald-600 dark:text-emerald-400 mt-1.5 hidden" aria-live="polite"></p>
    </div>

    <div id="hm112-toast" class="fixed left-1/2 -translate-x-1/2 bottom-24 px-4 py-2 u-toast text-xs font-medium rounded-xl opacity-0 transition-opacity duration-300 z-[60] shadow-lg pointer-events-none"></div>

    <script>
    (function () {
        var card = document.getElementById('hm112-card');
        var btn  = document.getElementById('hm112-claim');
        if (!card || !btn) return;

        var I18N = window.SYNAPSE_I18N || {};
        var NUM  = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });

        var amountEl = document.getElementById('hm112-amount');
        var barEl    = document.getElementById('hm112-bar');
        var streakEl = document.getElementById('hm112-streak-days');
        var dayEl    = document.getElementById('hm112-day-label');
        var labelEl  = document.getElementById('hm112-btn-label');
        var timerEl  = document.getElementById('hm112-timer');
        var statusEl = document.getElementById('hm112-status');
        var toastEl  = document.getElementById('hm112-toast');
        var pills    = card.querySelectorAll('.hm112-day');
        var iconEl   = btn.querySelector('i');

        var claimed   = card.getAttribute('data-claimed') === '1';
        var windowTs  = parseInt(card.getAttribute('data-window-ts'), 10) || 0;
        var maxReward = parseInt(card.getAttribute('data-max-reward'), 10) || 0;
        var toastTimer = null;

        function showToast(text, ok) {
            if (!toastEl || !text) return;
            toastEl.textContent = text;
            toastEl.classList.toggle('text-emerald-600', !!ok);
            toastEl.classList.remove('opacity-0');
            if (toastTimer) clearTimeout(toastTimer);
            toastTimer = setTimeout(function () { toastEl.classList.add('opacity-0'); }, 2600);
        }

        // Hitung mundur ke tengah malam WIB berikutnya (epoch dari SERVER — klien
        // tidak menghitung ulang tanggal, hanya sisa waktu).
        function tick() {
            if (!timerEl || !windowTs) return;
            var left = windowTs - Math.floor(Date.now() / 1000);
            if (left < 0) left = 0;
            function p(n) { return (n < 10 ? '0' : '') + n; }
            timerEl.textContent = (claimed ? (I18N.hm112_next_in || '') : (I18N.hm112_window_left || ''))
                + ' ' + p(Math.floor(left / 3600)) + ':' + p(Math.floor((left % 3600) / 60)) + ':' + p(left % 60);
        }
        tick();
        setInterval(tick, 1000);

        // Sinkronkan seluruh tampilan dengan status segar dari server.
        function paint(st) {
            if (!st) return;
            claimed = !!st.claimed_today;
            card.setAttribute('data-claimed', claimed ? '1' : '0');

            if (streakEl) streakEl.textContent = st.streak;
            if (amountEl) amountEl.textContent = NUM.format(st.today_reward);
            if (dayEl && I18N.hm112_day_fmt) dayEl.textContent = I18N.hm112_day_fmt.replace('%d', st.today_streak);
            if (barEl && maxReward > 0) {
                barEl.style.width = Math.min(100, Math.round((st.today_reward * 100) / maxReward)) + '%';
            }

            if (pills.length && st.preview && st.preview.length === pills.length) {
                for (var i = 0; i < pills.length; i++) {
                    pills[i].setAttribute('data-day', st.preview[i].day);
                    var num = pills[i].querySelector('.hm112-day-num');
                    var amt = pills[i].querySelector('.hm112-day-amt');
                    if (num) num.textContent = st.preview[i].day;
                    if (amt) amt.textContent = NUM.format(st.preview[i].reward);
                    pills[i].classList.toggle('hm112-day-next', !!st.preview[i].is_next);
                }
            }

            btn.disabled = claimed;
            btn.classList.toggle('hm112-pulse', !claimed);
            if (labelEl) labelEl.textContent = claimed ? I18N.hm112_claimed : I18N.hm112_claim;
            if (iconEl) iconEl.className = claimed ? 'fas fa-circle-check' : 'fas fa-hand-holding-usd';
            tick();
        }

        var CLAIM_URL = <?= json_encode(site_url('checkin/claim')) ?>;

        btn.addEventListener('click', function () {
            if (btn.disabled) return;

            var prevLabel = labelEl ? labelEl.textContent : '';
            btn.disabled = true;
            btn.classList.remove('hm112-pulse');
            if (labelEl) labelEl.textContent = I18N.js_processing || prevLabel;

            csrfFetch(CLAIM_URL, { method: 'POST' })
                .then(function (res) { return res.json(); })
                .then(function (d) {
                    if (d && d.success) {
                        showToast(d.message, true);
                        if (statusEl) {
                            statusEl.textContent = d.message || '';
                            statusEl.classList.remove('hidden');
                        }
                        paint(d.data && d.data.status);
                        return;
                    }

                    // Fail-closed: admin baru saja mematikan fitur → widget harus
                    // hilang; server TIDAK merender apa pun pada reload.
                    if (d && d.code === 'disabled') { window.location.reload(); return; }

                    if (d && d.status) paint(d.status);
                    else if (labelEl) labelEl.textContent = prevLabel;

                    var msg = (d && d.message) ? d.message : I18N.hm112_generic;
                    showToast(msg, false);
                    if (statusEl) {
                        statusEl.textContent = msg || '';
                        statusEl.classList.remove('hidden');
                    }
                })
                .catch(function () {
                    if (labelEl) labelEl.textContent = prevLabel;
                    btn.disabled = claimed;
                    if (!claimed) btn.classList.add('hm112-pulse');
                    showToast(I18N.hm112_generic, false);
                });
        });
    })();
    </script>
    <?php endif; ?>

    <!-- ═══ User Identity & Referral Card ═══ -->
    <div class="u-card rounded-2xl p-5 shadow-sm flex items-center justify-between">
        <div class="space-y-2">
            <div>
                <span class="text-[10px] u-muted font-semibold uppercase tracking-wider"><?= lang('home_node_id') ?></span>
                <p class="text-sm u-text font-semibold mt-0.5">
                    <?= substr($user->phone, 0, 3) . '••••' . substr($user->phone, -3) ?>
                </p>
            </div>
            <div>
                <span class="text-[10px] u-muted font-semibold uppercase tracking-wider"><?= lang('home_invite_code') ?></span>
                <?php if (!empty($referral_locked)): ?>
                <!-- Condition A (Plan 89): lifetime == 0 → kode disembunyikan -->
                <div class="flex items-center gap-2 mt-1">
                    <a href="<?= base_url('marketplace') ?>"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 u-card-inset rounded-lg text-[11px] font-semibold text-amber-600 dark:text-amber-400 hover:opacity-80 transition-opacity">
                        <i class="fas fa-lock text-[10px]"></i> <?= lang('home_locked_rent') ?>
                    </a>
                </div>
                <?php else: ?>
                <div class="flex items-center gap-2 mt-1">
                    <span id="inviteCodeText" class="inline-block px-3 py-1 u-card-inset rounded-lg text-sm font-bold u-text tracking-widest\"><?= $user->invite_code ?></span>
                    <button id="btnCopyInvite" class="flex items-center gap-1 px-2.5 py-1.5 u-btn-ghost rounded-lg transition-colors" title="<?= lang('common_copier_label') ?>" aria-label="<?= lang('home_copy_btn') ?>">
                        <i class="fas fa-copy u-text-2 text-xs"></i>
                        <span class="text-[11px] font-semibold u-text-2"><?= lang('home_copy_btn') ?></span>
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
                    <span class="text-[10px] u-muted font-semibold uppercase tracking-wider"><?= lang('home_promoter_program') ?></span>
                    <p class="text-lg font-extrabold u-text leading-tight">Rp <?= number_format((int) ($promoter_available ?? 0), 0, ',', '.') ?></p>
                    <p class="text-[10px] u-muted"><?= lang('home_omzet_available') ?></p>
                </div>
            </div>
            <a href="<?= base_url('team#promoter-hub') ?>"
               class="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-[11px] font-bold transition-colors active:scale-95">
                <?= lang('home_manage') ?> <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Copy Toast (z-[60]: above bottom nav z-50) -->
    <div id="copy-toast" class="fixed left-1/2 -translate-x-1/2 bottom-24 px-4 py-2 u-toast text-xs font-medium rounded-xl opacity-0 transition-opacity duration-300 z-[60] shadow-lg pointer-events-none">
        <?= lang('home_copied_toast') ?>
    </div>

    <script>
    (function () {
        var btn = document.getElementById('btnCopyInvite');
        var codeEl = document.getElementById('inviteCodeText');
        var toast = document.getElementById('copy-toast');
        if (!btn || !codeEl || !toast) return; // Plan 89: locked state → tombol tak dirender
        var iconEl = btn.querySelector('i');
        var labelEl = btn.querySelector('span');
        var labelOrig = labelEl ? labelEl.textContent : '';

        btn.addEventListener('click', function () {
            var code = codeEl.textContent.trim();

            function onSuccess() {
                labelEl.textContent = window.SYNAPSE_I18N['js_copied'];
                iconEl.className = 'fas fa-check text-white text-xs';
                btn.classList.remove('u-btn-ghost');
                btn.classList.add('bg-emerald-500');

                toast.classList.remove('opacity-0');

                setTimeout(function () {
                    labelEl.textContent = labelOrig;
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
                    alert(window.SYNAPSE_I18N['js_copy_code_failed']);
                }
                document.body.removeChild(ta);
            }
        });
    })();
    </script>

    <!-- ═══ Visual Stats Section ═══ -->
    <div>
        <!-- ═══ Global Network Topology — World Node Map (Plan 99) ═══ -->
        <div class="hm99-map rounded-2xl overflow-hidden mb-4">
            <!-- Header: judul + SLA chip -->
            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 pt-3.5 pb-1">
                <div class="flex items-center gap-2">
                    <span class="w-7 h-7 rounded-lg bg-indigo-100 dark:bg-indigo-500/10 flex items-center justify-center">
                        <i class="fas fa-earth-asia text-[11px] text-indigo-600 dark:text-indigo-400"></i>
                    </span>
                    <span class="text-[11px] font-bold u-text tracking-wide"><?= lang('home_topology_title') ?></span>
                </div>
                <span class="hm99-chip hm99-chip-live"><span class="hm99-live-dot"></span><?= lang('home_topology_sla') ?></span>
            </div>

            <!-- SVG kanvas (geometri only; label hub = HTML overlay agar terbaca 360-480px) -->
            <div class="hm99-map-wrap">
                <svg class="hm99-map-svg" viewBox="0 0 1000 500" preserveAspectRatio="xMidYMid meet"
                     role="img" aria-label="<?= lang('home_topology_title') ?>">
                    <!-- Graticule (grid dunia) -->
                    <g fill="none">
                        <path class="hm99-grat" d="M0 0V500M200 0V500M400 0V500M600 0V500M800 0V500M1000 0V500"/>
                        <path class="hm99-grat" d="M0 100H1000M0 400H1000"/>
                        <path class="hm99-grat hm99-grat-eq" d="M0 250H1000"/>
                    </g>

                    <!-- Continent landmasses: stylized vector rings (Plan 101, Q-smoothed, tanpa blur) -->
                    <g class="hm99-land-g">
                        <path class="hm99-land" d="M95 55Q125 58 180.5 65Q236 72 268 77.5Q300 83 319.5 90Q339 97 347.5 108Q356 119 342 123.5Q328 128 317 130.5Q306 133 291 157Q276 181 266 186.5Q256 192 267 210Q278 228 250 215.5Q222 203 208 194.5Q194 186 179 166.5Q164 147 160 133Q156 119 142 105.5Q128 92 92 93Q56 94 47.5 88.5Q39 83 52 67.5Q65 52 95 55Z"/>
                        <path class="hm99-land" d="M287.5 223.5Q292 219 308.5 220.5Q325 222 341.5 230.5Q358 239 380.5 252Q403 265 392 289.5Q381 314 365.5 330.5Q350 347 334.5 364Q319 381 318 391Q317 401 311.5 399Q306 397 301.5 387.5Q297 378 300 360Q303 342 298.5 318Q294 294 286 273.5Q278 253 280.5 240.5Q283 228 287.5 223.5Z"/>
                        <path class="hm99-land" d="M483.5 123.5Q493 118 494.5 115Q496 112 504 108Q512 104 517.5 97.5Q523 91 526 88.5Q529 86 522 81.5Q515 77 526.5 68.5Q538 60 555.5 56Q573 52 585 59.5Q597 67 632 62.5Q667 58 667 84Q667 110 651.5 114.5Q636 119 621 120Q606 121 600 123Q594 125 586 127Q578 129 577.5 131.5Q577 134 571 136Q565 138 563 143Q561 148 556 143.5Q551 139 547.5 141.5Q544 144 532.5 136.5Q521 129 517 129.5Q513 130 509.5 132.5Q506 135 502.5 137.5Q499 140 492 145Q485 150 479.5 148.5Q474 147 474 138Q474 129 483.5 123.5Z"/>
                        <path class="hm99-land" d="M479.5 154Q474 157 469 162.5Q464 168 457.5 187.5Q451 207 454 210.5Q457 214 464 223.5Q471 233 486 234Q501 235 509.5 236.5Q518 238 522 239Q526 240 525.5 245.5Q525 251 529 259Q533 267 533 275Q533 283 536.5 300Q540 317 546.5 331.5Q553 346 565.5 342.5Q578 339 588.5 320Q599 301 604.5 289.5Q610 278 610.5 268Q611 258 622 243Q633 228 638 223Q643 218 632.5 217Q622 216 614.5 206Q607 196 598.5 182Q590 168 586.5 165.5Q583 163 568 162Q553 161 540.5 154Q528 147 506.5 149Q485 151 479.5 154Z"/>
                        <path class="hm99-land" d="M667 84Q667 110 651.5 114.5Q636 119 635.5 129Q635 139 625 136Q615 133 610.5 133Q606 133 594 134.5Q582 136 578.5 139.5Q575 143 580.5 146Q586 149 591 153Q596 157 602 174.5Q608 192 616 203.5Q624 215 644.5 201.5Q665 188 670.5 184Q676 180 680.5 180Q685 180 689 184.5Q693 189 698.5 197.5Q704 206 709.5 217Q715 228 720.5 216Q726 204 737.5 196.5Q749 189 756 196.5Q763 204 768.5 212.5Q774 221 781 233.5Q788 246 785.5 235.5Q783 225 789.5 223.5Q796 222 797.5 213Q799 204 803 197Q807 190 812 189Q817 188 823 185Q829 182 833.5 177Q838 172 839 161Q840 150 845.5 147Q851 144 857 141Q863 138 868.5 129.5Q874 121 883 110.5Q892 100 904.5 93Q917 86 928 83.5Q939 81 955.5 74Q972 67 951.5 61.5Q931 56 896 53Q861 50 826.5 47Q792 44 764 47Q736 50 715 53Q694 56 680.5 57Q667 58 667 84Z"/>
                        <path class="hm99-land" d="M840 297Q864 283 880 281.5Q896 280 900 288.5Q904 297 915 311.5Q926 326 922.5 335Q919 344 911 350Q903 356 894 351.5Q885 347 875 343Q865 339 842 339Q819 339 817 330Q815 321 815.5 316Q816 311 840 297Z"/>
                    </g>

                    <!-- Island & archipelago paths (Greenland, UK/IE, Jepang, Filipina, Indonesia) -->
                    <g class="hm99-isle-g">
                        <path class="hm99-isle" d="M398.5 27.5Q450 22 414 52.5Q378 83 368 77.5Q358 72 352.5 64Q347 56 347 44.5Q347 33 398.5 27.5Z"/>
                        <path class="hm99-isle" d="M492.5 110Q501 109 502.5 106.5Q504 104 501.5 102.5Q499 101 496.5 95.5Q494 90 489.5 89.5Q485 89 486.5 95Q488 101 486 106Q484 111 492.5 110Z"/>
                        <path class="hm99-isle" d="M477.5 106Q483 105 480.5 102Q478 99 475 99Q472 99 472 103Q472 107 477.5 106Z"/>
                        <path class="hm99-isle" d="M443 66.5Q450 65 456.5 67.5Q463 70 455 72Q447 74 441.5 71Q436 68 443 66.5Z"/>
                        <path class="hm99-isle" d="M638.5 291Q639 299 632.5 310Q626 321 624 316Q622 311 630 297Q638 283 638.5 291Z"/>
                        <path class="hm99-isle" d="M871 154Q878 153 883.5 152Q889 151 890.5 150Q892 149 893 146Q894 143 893 139Q892 135 891 136.5Q890 138 887.5 142.5Q885 147 882 149Q879 151 871.5 153Q864 155 871 154Z"/>
                        <path class="hm99-isle" d="M896.5 130.5Q903 127 900.5 128.5Q898 130 895 131Q892 132 891 133Q890 134 896.5 130.5Z"/>
                        <path class="hm99-isle" d="M836.5 200Q839 201 838.5 206Q838 211 836.5 209Q835 207 834.5 203Q834 199 836.5 200Z"/>
                        <path class="hm99-isle" d="M845 231Q850 231 850.5 227.5Q851 224 847.5 224Q844 224 842 227.5Q840 231 845 231Z"/>
                        <path class="hm99-isle" d="M773.5 238.5Q782 242 788 254Q794 266 786.5 263.5Q779 261 775 252.5Q771 244 768 239.5Q765 235 773.5 238.5Z"/>
                        <path class="hm99-isle" d="M796.5 268Q800 268 805.5 269Q811 270 814.5 271.5Q818 273 816.5 273Q815 273 811 272Q807 271 801.5 271Q796 271 794.5 269.5Q793 268 796.5 268Z"/>
                        <path class="hm99-isle" d="M807.5 244Q812 239 817 236.5Q822 234 825.5 237Q829 240 827.5 245Q826 250 821.5 252.5Q817 255 812 253.5Q807 252 805 250.5Q803 249 807.5 244Z"/>
                        <path class="hm99-isle" d="M836.5 250Q840 252 839 256.5Q838 261 835 262Q832 263 832 258.5Q832 254 834 251.5Q836 249 834.5 248.5Q833 248 836.5 250Z"/>
                        <path class="hm99-isle" d="M873 254.5Q879 256 885.5 257Q892 258 900 263.5Q908 269 904.5 271.5Q901 274 893.5 273Q886 272 879 266.5Q872 261 869.5 257Q867 253 873 254.5Z"/>
                    </g>

                    <!-- Secondary backbone: static dashed transit links (mesh antar-benua) -->
                    <g class="hm99-bb-g" fill="none">
                        <path class="hm99-bb" d="M285 142 Q393 82 500 107"/>
                        <path class="hm99-bb" d="M500 107 L524 111"/>
                        <path class="hm99-bb" d="M524 111 Q589 118 654 180"/>
                        <path class="hm99-bb" d="M654 180 Q678 161 702 197"/>
                        <path class="hm99-bb" d="M702 197 Q745 194 788 246"/>
                        <path class="hm99-bb" d="M788 246 Q854 267 920 344"/>
                        <path class="hm99-bb" d="M888 151 Q904 220 920 344"/>
                        <path class="hm99-bb" d="M285 142 Q328 201 370 315"/>
                    </g>

                    <!-- Primary active routes: base (statis) -->
                    <g class="hm99-route-g" fill="none">
                        <path class="hm99-route" d="M285 142 Q405 55 524 111"/>
                        <path class="hm99-route" d="M524 111 Q656 100 788 246"/>
                        <path class="hm99-route" d="M788 246 Q793 221 797 267"/>
                        <path class="hm99-route" d="M797 267 Q843 173 888 151"/>
                        <path class="hm99-route" d="M888 151 Q587 17 285 142"/>
                    </g>

                    <!-- Primary pulse (travelling dash, pathLength=1: satu komet per path per loop) -->
                    <g class="hm99-flow-g" fill="none">
                        <path class="hm99-flow" pathLength="1" style="animation-duration:2.8s;animation-delay:0s" d="M285 142 Q405 55 524 111"/>
                        <path class="hm99-flow" pathLength="1" style="animation-duration:3.4s;animation-delay:.55s" d="M524 111 Q656 100 788 246"/>
                        <path class="hm99-flow" pathLength="1" style="animation-duration:2.2s;animation-delay:1.1s;stroke-dasharray:.12 .88" d="M788 246 Q793 221 797 267"/>
                        <path class="hm99-flow" pathLength="1" style="animation-duration:2.4s;animation-delay:1.65s;animation-direction:reverse" d="M797 267 Q843 173 888 151"/>
                        <path class="hm99-flow" pathLength="1" style="animation-duration:4.2s;animation-delay:2.2s;animation-direction:reverse" d="M888 151 Q587 17 285 142"/>
                    </g>

                    <!-- Edge micro-nodes (unlabeled: London, São Paulo, Dubai, Mumbai, Sydney) -->
                    <g class="hm99-edge-g">
                        <circle class="hm99-edge-halo" cx="500" cy="107" r="5"/>
                        <circle class="hm99-edge" style="animation-delay:0s" cx="500" cy="107" r="2.5"/>
                        <circle class="hm99-edge-halo" cx="370" cy="315" r="5"/>
                        <circle class="hm99-edge" style="animation-delay:.6s" cx="370" cy="315" r="2.5"/>
                        <circle class="hm99-edge-halo" cx="654" cy="180" r="5"/>
                        <circle class="hm99-edge" style="animation-delay:1.2s" cx="654" cy="180" r="2.5"/>
                        <circle class="hm99-edge-halo" cx="702" cy="197" r="5"/>
                        <circle class="hm99-edge" style="animation-delay:1.8s" cx="702" cy="197" r="2.5"/>
                        <circle class="hm99-edge-halo" cx="920" cy="344" r="5"/>
                        <circle class="hm99-edge" style="animation-delay:2.4s" cx="920" cy="344" r="2.5"/>
                    </g>

                    <!-- Hubs: ping ring + core (stagger 0/.4/.8/1.2/1.6s) -->
                    <g>
                        <circle class="hm99-nring" style="animation-delay:0s" cx="524" cy="111" r="10"/>
                        <circle class="hm99-ncore" cx="524" cy="111" r="6"/>
                        <circle class="hm99-nring" style="animation-delay:.4s" cx="285" cy="142" r="10"/>
                        <circle class="hm99-ncore" cx="285" cy="142" r="6"/>
                        <circle class="hm99-nring" style="animation-delay:.8s" cx="888" cy="151" r="10"/>
                        <circle class="hm99-ncore" cx="888" cy="151" r="6"/>
                        <circle class="hm99-nring" style="animation-delay:1.2s" cx="788" cy="246" r="10"/>
                        <circle class="hm99-ncore" cx="788" cy="246" r="6"/>
                        <circle class="hm99-nring" style="animation-delay:1.6s" cx="797" cy="267" r="10"/>
                        <circle class="hm99-ncore" cx="797" cy="267" r="6"/>
                    </g>
                </svg>

                <!-- Hub label overlay (posisi % = x/10, y/20 dari dataset Plan 99 §4.4) -->
                <span class="hm99-hub-label" style="left:78.8%;top:49.2%;"><?= lang('home_hub_sin') ?></span>
                <span class="hm99-hub-label hm99-hub-jkt" style="left:79.7%;top:53.4%;"><?= lang('home_hub_jkt') ?></span>
                <span class="hm99-hub-label hm99-hub-tyo" style="left:88.8%;top:30.2%;"><?= lang('home_hub_tyo') ?></span>
                <span class="hm99-hub-label" style="left:28.5%;top:28.4%;"><?= lang('home_hub_useast') ?></span>
                <span class="hm99-hub-label" style="left:52.4%;top:22.2%;"><?= lang('home_hub_fra') ?></span>
            </div>

            <!-- Micro-status footer -->
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 pt-1.5 pb-3">
                <span class="hm99-chip"><i class="fas fa-shield-halved"></i><?= lang('home_topology_failover') ?></span>
                <span class="hm99-chip"><i class="fas fa-gauge-high"></i><?= lang('home_topology_latency') ?></span>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <!-- Uptime Node -->
            <div class="u-card-inset p-4 rounded-2xl">
                <div class="w-9 h-9 bg-emerald-100 dark:bg-emerald-500/10 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-bolt text-emerald-600 dark:text-emerald-400 text-sm"></i>
                </div>
                <p class="text-2xl font-extrabold text-emerald-500">99.99%</p>
                <p class="text-[11px] u-text-2 font-semibold mt-1"><?= lang('home_stat_uptime') ?></p>
            </div>

            <!-- Kapasitas Tersewa -->
            <div class="u-card-inset p-4 rounded-2xl">
                <div class="w-9 h-9 bg-amber-100 dark:bg-amber-500/10 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-chart-line text-amber-600 dark:text-amber-400 text-sm"></i>
                </div>
                <p class="text-2xl font-extrabold u-text">98.5%</p>
                <p class="text-[11px] u-text-2 font-semibold mt-1"><?= lang('home_stat_capacity') ?></p>
            </div>

            <!-- Global TFLOPs -->
            <div class="u-card-inset p-4 rounded-2xl">
                <div class="w-9 h-9 bg-blue-100 dark:bg-blue-500/10 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-microchip text-blue-600 dark:text-blue-400 text-sm"></i>
                </div>
                <p class="text-2xl font-extrabold u-text">1,250+</p>
                <p class="text-[11px] u-text-2 font-semibold mt-1"><?= lang('home_stat_tflops') ?></p>
            </div>

            <!-- Total Value -->
            <div class="u-card-inset p-4 rounded-2xl">
                <div class="w-9 h-9 bg-indigo-100 dark:bg-indigo-500/10 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-coins text-indigo-600 dark:text-indigo-400 text-sm"></i>
                </div>
                <p class="text-2xl font-extrabold u-text">1,500,000</p>
                <p class="text-[11px] u-text-2 font-semibold mt-1"><?= lang('home_stat_value') ?></p>
            </div>
        </div>
    </div>

    <!-- ═══ Action Button (Phase 32: cyber gradient) ═══ -->
    <a href="<?= base_url('marketplace') ?>" class="w-full h-14 u-btn-cyber rounded-2xl flex items-center justify-center gap-2">
        <?= lang('home_explore_cta') ?> <i class="fas fa-arrow-right"></i>
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
            <h3 class="text-sm font-extrabold u-text leading-snug pt-1.5"><?= lang('home_warn_title') ?></h3>
        </div>
        <p class="text-xs u-text-2 leading-relaxed mb-5">
            <?= lang('home_warn_body') ?>
        </p>
        <a href="<?= base_url('marketplace') ?>" class="w-full h-12 u-btn-cyber rounded-xl flex items-center justify-center gap-2 text-sm">
            <?= lang('home_activate_now') ?> <i class="fas fa-arrow-right"></i>
        </a>
        <button onclick="closeInactiveWarn()"
                class="w-full mt-2 py-3 u-btn-ghost rounded-xl text-xs font-bold u-text-2 transition-colors active:scale-[0.98]">
            <?= lang('home_later') ?>
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
