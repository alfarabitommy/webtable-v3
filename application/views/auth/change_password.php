<!DOCTYPE html>
<html lang="<?= htmlspecialchars(isset($site_lang_code) ? $site_lang_code : 'en', ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= lang('auth_cpw_title') ?> · Synapse</title>

    <!-- ── Phase 32: User Theme Manager — Anti-FOUC (standalone auth) ── -->
    <script>
    (function () {
        try {
            if (localStorage.getItem('user_theme') !== 'light') {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {
            document.documentElement.classList.add('dark');
        }
    })();
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <style>
        /* ═══ Phase 32: User Theme Manager — token subset (standalone auth) ═══ */
        :root {
            color-scheme: light;
            --u-surface: #ffffff;
            --u-border: #e2e8f0;
            --u-text: #0f172a;
            --u-text-2: #475569;
            --u-muted: #94a3b8;
            --u-input-bg: #ffffff;
        }
        html.dark {
            color-scheme: dark;
            --u-surface: #0b1120;
            --u-border: rgba(148, 163, 184, 0.18);
            --u-text: #e6edf7;
            --u-text-2: #94a3b8;
            --u-muted: #64748b;
            --u-input-bg: #0d1526;
        }
        .u-surface-bg { background-color: var(--u-surface); }
        .u-input {
            border: 1px solid var(--u-border);
            background-color: var(--u-input-bg);
            color: var(--u-text);
            outline: none;
        }
        .u-input::placeholder { color: var(--u-muted); }
        .u-btn-dark {
            background-color: #0f172a;
            color: #fff;
            font-weight: 700;
            transition: background-color 0.15s ease;
        }
        html.dark .u-btn-dark { background-color: rgba(99, 102, 241, 0.35); }
        .u-btn-dark:hover { background-color: #2563eb; }
        html.dark .u-btn-dark:hover { background-color: rgba(99, 102, 241, 0.55); }
        .u-flash-success { background-color: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.25); color: #059669; }
        html.dark .u-flash-success { color: #34d399; }
        .u-flash-error { background-color: rgba(244, 63, 94, 0.1); border: 1px solid rgba(244, 63, 94, 0.25); color: #e11d48; }
        html.dark .u-flash-error { color: #fb7185; }

        /* ═══ Plan 97: Auth ambient, wordmark, glass & theme toggle (auth standalone) ═══ */
        :root {
            --u-auth-body-1: #f8fafc;
            --u-auth-body-2: #eef2ff;
            --u-auth-orb-1: rgba(186, 230, 253, 0.85);
            --u-auth-orb-2: rgba(199, 210, 254, 0.80);
            --u-auth-orb-3: rgba(221, 214, 254, 0.80);
            --u-auth-orb-4: rgba(191, 219, 254, 0.75);
            --u-auth-orb-5: rgba(224, 231, 255, 0.80);
            --u-auth-orb-6: rgba(233, 213, 255, 0.70);
            --u-auth-card-bg: rgba(255, 255, 255, 0.74);
            --u-auth-card-border: rgba(203, 213, 225, 0.9);
            --u-auth-card-shadow: 0 -12px 40px rgba(51, 65, 85, 0.14);
            --u-auth-halo: rgba(99, 102, 241, 0.10);
            --u-auth-vignette: rgba(226, 232, 240, 0.45);
            /* Plan 98: circuit grid & flowing data pulses (light) */
            --u-grid-line: rgba(79, 70, 229, 0.09);
            --u-trace: rgba(79, 70, 229, 0.16);
            --u-trace-dim: rgba(79, 70, 229, 0.09);
            --u-trace-bus: rgba(14, 116, 144, 0.35);
            --u-node: rgba(79, 70, 229, 0.55);
            --u-node-core: #4f46e5;
            --u-ring: rgba(99, 102, 241, 0.30);
            --u-pulse-a: #06b6d4;
            --u-pulse-b: #4f46e5;
            --u-pulse-c: #0d9488;
            --u-pulse-glow-a: drop-shadow(0 0 5px rgba(6, 182, 212, 0.45));
            --u-pulse-glow-b: drop-shadow(0 0 5px rgba(79, 70, 229, 0.40));
            --u-pulse-glow-c: drop-shadow(0 0 5px rgba(13, 148, 136, 0.40));
            --u-auth-wm-a: #0f172a;
            --u-auth-wm-b: #312e81;
            --u-auth-wm-c: #4338ca;
            --u-auth-glow: none;
            --u-auth-tag: #475569;
            --u-capsule-bg: rgba(255, 255, 255, 0.62);
            --u-capsule-border: rgba(148, 163, 184, 0.35);
        }
        html.dark {
            --u-auth-body-1: #050811;
            --u-auth-body-2: #0b1120;
            --u-auth-orb-1: rgba(34, 211, 238, 0.60);
            --u-auth-orb-2: rgba(99, 102, 241, 0.62);
            --u-auth-orb-3: rgba(139, 92, 246, 0.55);
            --u-auth-orb-4: rgba(56, 189, 248, 0.52);
            --u-auth-orb-5: rgba(129, 140, 248, 0.48);
            --u-auth-orb-6: rgba(167, 139, 250, 0.45);
            --u-auth-card-bg: rgba(11, 17, 32, 0.66);
            --u-auth-card-border: rgba(56, 189, 248, 0.16);
            --u-auth-card-shadow: 0 -12px 44px rgba(0, 0, 0, 0.45);
            --u-auth-halo: rgba(34, 211, 238, 0.10);
            --u-auth-vignette: rgba(3, 6, 15, 0.55);
            /* Plan 98: circuit grid & flowing data pulses (dark) */
            --u-grid-line: rgba(103, 232, 249, 0.07);
            --u-trace: rgba(34, 211, 238, 0.30);
            --u-trace-dim: rgba(34, 211, 238, 0.15);
            --u-trace-bus: rgba(56, 189, 248, 0.50);
            --u-node: rgba(34, 211, 238, 0.80);
            --u-node-core: #67e8f9;
            --u-ring: rgba(34, 211, 238, 0.35);
            --u-pulse-a: #22d3ee;
            --u-pulse-b: #a78bfa;
            --u-pulse-c: #60a5fa;
            --u-pulse-glow-a: drop-shadow(0 0 6px rgba(34, 211, 238, 0.85));
            --u-pulse-glow-b: drop-shadow(0 0 6px rgba(167, 139, 250, 0.80));
            --u-pulse-glow-c: drop-shadow(0 0 6px rgba(96, 165, 250, 0.80));
            --u-auth-wm-a: #22d3ee;
            --u-auth-wm-b: #6366f1;
            --u-auth-wm-c: #a78bfa;
            --u-auth-glow: drop-shadow(0 0 18px rgba(34, 211, 238, 0.35));
            --u-auth-tag: #cbd5e1;
            --u-capsule-bg: rgba(15, 23, 42, 0.5);
            --u-capsule-border: rgba(148, 163, 184, 0.25);
        }
        .u-auth-body { background: linear-gradient(165deg, var(--u-auth-body-1), var(--u-auth-body-2)); }

        /* ═══ Plan 98: high-tech circuit grid + flowing data pulses (auth standalone) ═══ */
        .auth-ambient { z-index: 0; }

        /* — Tamed Plasma Core (blur 35–50px; inti pekat; bloom non-destruktif) — */
        .plasma { position: absolute; border-radius: 9999px; pointer-events: none;
                  will-change: transform; mix-blend-mode: normal; }
        html.dark .plasma { mix-blend-mode: screen; }
        .plasma-1 { width: 300px; height: 300px; left: -10%; top: -16%; filter: blur(45px);
                    background: radial-gradient(circle at 30% 28%, var(--u-auth-orb-1) 0%, transparent 62%);
                    animation: plasma-drift 7s ease-in-out -1s infinite alternate; }
        .plasma-2 { width: 280px; height: 280px; right: -12%; top: -12%; filter: blur(42px);
                    background: radial-gradient(circle at 68% 34%, var(--u-auth-orb-2) 0%, transparent 62%);
                    animation: plasma-drift 8s ease-in-out -4s infinite alternate; }
        .plasma-3 { width: 340px; height: 340px; right: -18%; top: 28%; filter: blur(50px);
                    background: radial-gradient(circle at 60% 42%, var(--u-auth-orb-3) 0%, transparent 64%);
                    animation: plasma-breath 9s ease-in-out -2s infinite alternate; }
        .plasma-4 { width: 320px; height: 320px; left: -10%; bottom: -10%; filter: blur(46px);
                    background: radial-gradient(circle at 40% 58%, var(--u-auth-orb-4) 0%, transparent 63%);
                    animation: plasma-drift 10s ease-in-out -7s infinite alternate; }
        .plasma-5 { width: 260px; height: 260px; right: 6%; bottom: -18%; filter: blur(38px);
                    background: radial-gradient(circle at 55% 50%, var(--u-auth-orb-5) 0%, transparent 62%);
                    animation: plasma-breath 11s ease-in-out -5s infinite alternate; }

        /* — Circuit/Grid SVG (statis; hanya .auth-ring & .node yang bernapas) — */
        .auth-circuit { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
        .auth-circuit .c-grid { stroke: var(--u-grid-line); }
        .auth-circuit .die--frame { fill: none; stroke: var(--u-trace); stroke-width: 1.2; }
        .auth-circuit .die--core { fill: none; stroke: var(--u-trace-dim); stroke-width: 1; }
        .auth-circuit .pin { fill: var(--u-node); opacity: 0.8; }
        .auth-circuit .t-line { fill: none; stroke: var(--u-trace); stroke-width: 1; }
        .auth-circuit .t-line--dim { fill: none; stroke: var(--u-trace-dim); stroke-width: 1; }
        .auth-circuit .t-bus { fill: none; stroke: var(--u-trace-bus); stroke-width: 1.5;
                               stroke-dasharray: 1 8; }
        .auth-circuit .node { fill: var(--u-node); transform-box: fill-box; transform-origin: center;
                              animation: node-blink 7s ease-in-out infinite; }
        .auth-circuit .node--core { fill: var(--u-node-core); filter: drop-shadow(0 0 6px var(--u-node));
                                    animation-duration: 4.2s; }
        .auth-circuit .node--d2 { animation-delay: -1.8s; }
        .auth-circuit .node--d3 { animation-delay: -3.4s; }
        .auth-circuit .auth-ring { fill: none; stroke: var(--u-ring); stroke-width: 1.25;
                                   stroke-dasharray: 2 9; transform-box: fill-box; transform-origin: center;
                                   animation: ring-spin 12s linear infinite; }

        /* — Flowing Data Pulses (hanya stroke-dashoffset yang dianimasikan) — */
        .auth-circuit .pulse { fill: none; stroke-linecap: round; stroke: currentColor; }
        .bus-b1 { color: var(--u-pulse-a); }
        .bus-b2 { color: var(--u-pulse-b); }
        .bus-b3 { color: var(--u-pulse-c); }
        .pulse.p-dots { stroke-width: 2; stroke-dasharray: 2 18;
                        animation: dash-flow-b1 6s linear infinite; }
        .pulse.p-burst { stroke-width: 2.8; stroke-dasharray: 10 150;
                         animation: dash-flow-b1 6s linear infinite; }
        .pulse.p-tail { stroke-width: 5; opacity: 0.28; stroke-dasharray: 40 120;
                        animation: dash-flow-tail-b1 6s linear infinite; }
        .bus-b1.p-burst { filter: var(--u-pulse-glow-a); }
        .bus-b2.p-burst { filter: var(--u-pulse-glow-b); }
        .bus-b3.p-burst { filter: var(--u-pulse-glow-c); }
        .bus-b2.p-dots, .bus-b2.p-burst { animation: dash-flow-b2 7s linear infinite; }
        .bus-b2.p-tail { animation: dash-flow-tail-b2 7s linear infinite; }
        .bus-b3.p-dots, .bus-b3.p-burst { animation: dash-flow-b3 8s linear infinite; }
        .bus-b3.p-tail { animation: dash-flow-tail-b3 8s linear infinite; }

        /* Keyframes plasma — transform-only (GPU) */
        @keyframes plasma-drift {
            0%   { transform: translate3d(0, 0, 0) scale(1); }
            50%  { transform: translate3d(5vw, -4vh, 0) scale(1.14); }
            100% { transform: translate3d(-4vw, 3vh, 0) scale(1.02); }
        }
        @keyframes plasma-breath {
            0%   { transform: scale(0.94); }
            100% { transform: scale(1.06); }
        }
        @keyframes node-blink {
            0%, 100% { opacity: 0.35; transform: scale(0.85); }
            50%      { opacity: 1; transform: scale(1.25); }
        }
        @keyframes ring-spin { to { transform: rotate(360deg); } }

        /* Keyframes aliran paket: Δ = 160 × T (kelipatan periode 20 & 160) */
        @keyframes dash-flow-b1 { to { stroke-dashoffset: -960px; } }
        @keyframes dash-flow-tail-b1 { from { stroke-dashoffset: 40px; } to { stroke-dashoffset: -920px; } }
        @keyframes dash-flow-b2 { to { stroke-dashoffset: -1120px; } }
        @keyframes dash-flow-tail-b2 { from { stroke-dashoffset: 40px; } to { stroke-dashoffset: -1080px; } }
        @keyframes dash-flow-b3 { to { stroke-dashoffset: -1280px; } }
        @keyframes dash-flow-tail-b3 { from { stroke-dashoffset: 40px; } to { stroke-dashoffset: -1240px; } }

        /* Veil tepi & halo kanopi (aturan statis; warna lewat token tema) */
        .auth-vignette { background: radial-gradient(120% 90% at 50% 108%, var(--u-auth-vignette), transparent 70%); }
        .auth-halo { position: absolute; left: 50%; top: 46%; width: 240px; height: 240px;
                     transform: translate(-50%, -50%); border-radius: 9999px;
                     background: radial-gradient(closest-side, var(--u-auth-halo), transparent 70%); }

        /* Kartu form glassmorphism */
        .auth-card {
            background-color: var(--u-auth-card-bg);
            -webkit-backdrop-filter: blur(16px) saturate(1.5);
            backdrop-filter: blur(16px) saturate(1.5);
            border: 1px solid var(--u-auth-card-border);
            box-shadow: var(--u-auth-card-shadow);
        }

        /* Wordmark gradient + tagline tema-adaptif */
        .u-auth-wordmark {
            font-size: 2.05rem; font-weight: 900; letter-spacing: 0.3em; margin-right: -0.3em;
            text-transform: uppercase; line-height: 1.15;
            background-image: linear-gradient(100deg, var(--u-auth-wm-a) 0%, var(--u-auth-wm-b) 58%, var(--u-auth-wm-c) 100%);
            -webkit-background-clip: text; background-clip: text; color: transparent;
            filter: var(--u-auth-glow);
        }
        .u-auth-tagline { color: var(--u-auth-tag); }

        /* Cluster atas: chip lang-switcher (sebelumnya tak terdefinisi di auth) + toggle */
        .u-capsule { background-color: var(--u-capsule-bg); border: 1px solid var(--u-capsule-border);
                     color: var(--u-text-2); transition: background-color 0.15s ease, border-color 0.15s ease; }
        .auth-toggle-btn {
            width: 2.25rem; height: 2.25rem; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 9999px; background-color: var(--u-capsule-bg); border: 1px solid var(--u-capsule-border);
            color: var(--u-text-2); transition: background-color 0.15s ease, color 0.15s ease, transform 0.1s ease;
        }
        .auth-toggle-btn:hover { color: var(--u-text); }
        .auth-toggle-btn:active { transform: scale(0.95); }
        .auth-toggle-btn svg { width: 18px; height: 18px; }
        .auth-ico-moon { display: block; }
        .auth-ico-sun { display: none; }
        html.dark .auth-ico-moon { display: none; }
        html.dark .auth-ico-sun { display: block; }

        @media (prefers-reduced-motion: reduce) {
            .plasma, .pulse, .node, .auth-ring { animation: none !important; }
            .pulse { stroke-dashoffset: 0; }
        }
        @supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
            .auth-card { background-color: var(--u-surface); }
        }
    </style>
</head>
<body class="u-auth-body flex justify-center min-h-screen font-sans antialiased">

<div class="w-full max-w-[480px] min-h-screen mx-auto relative overflow-hidden flex flex-col">

    <!-- Plan 98: high-tech circuit grid + flowing data pulses (pure CSS/SVG, zero-dep) -->
    <div class="auth-ambient absolute inset-0 overflow-hidden pointer-events-none" aria-hidden="true">
        <!-- Tamed plasma cores (bloom; dirender di bawah vektor agar tetap tajam) -->
        <div class="plasma plasma-1"></div><div class="plasma plasma-2"></div>
        <div class="plasma plasma-3"></div><div class="plasma plasma-4"></div>
        <div class="plasma plasma-5"></div>

        <!-- Circuit/Grid + Flowing Data Pulses (satu SVG; viewBox 480x900, slice) -->
        <svg class="auth-circuit" viewBox="0 0 480 900" preserveAspectRatio="xMidYMid slice"
             xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
            <defs>
                <pattern id="p-grid" width="28" height="28" patternUnits="userSpaceOnUse">
                    <path d="M28 0 H0 V28" class="c-grid" fill="none"/>
                </pattern>
            </defs>
            <rect width="480" height="900" fill="url(#p-grid)"/>

            <!-- Signal ring pembingkai wordmark -->
            <circle class="auth-ring" cx="240" cy="148" r="118"/>

            <!-- Die A (gateway) & Die B (compute) -->
            <rect class="die--frame" x="46" y="52" width="64" height="64" rx="10"/>
            <rect class="die--core" x="57" y="63" width="42" height="42" rx="6"/>
            <rect class="die--frame" x="404" y="58" width="52" height="52" rx="8"/>
            <rect class="die--core" x="414" y="68" width="32" height="32" rx="4"/>

            <!-- Pin die A -->
            <circle class="pin" cx="62" cy="52" r="2.1"/><circle class="pin" cx="78" cy="52" r="2.1"/><circle class="pin" cx="94" cy="52" r="2.1"/>
            <circle class="pin" cx="62" cy="116" r="2.1"/><circle class="pin" cx="78" cy="116" r="2.1"/><circle class="pin" cx="94" cy="116" r="2.1"/>
            <circle class="pin" cx="46" cy="68" r="2.1"/><circle class="pin" cx="46" cy="84" r="2.1"/><circle class="pin" cx="46" cy="100" r="2.1"/>
            <circle class="pin" cx="110" cy="68" r="2.1"/><circle class="pin" cx="110" cy="84" r="2.1"/><circle class="pin" cx="110" cy="100" r="2.1"/>
            <!-- Pin die B -->
            <circle class="pin" cx="420" cy="58" r="2.1"/><circle class="pin" cx="430" cy="58" r="2.1"/><circle class="pin" cx="440" cy="58" r="2.1"/>
            <circle class="pin" cx="420" cy="110" r="2.1"/><circle class="pin" cx="430" cy="110" r="2.1"/><circle class="pin" cx="440" cy="110" r="2.1"/>
            <circle class="pin" cx="404" cy="72" r="2.1"/><circle class="pin" cx="404" cy="84" r="2.1"/><circle class="pin" cx="404" cy="96" r="2.1"/>
            <circle class="pin" cx="456" cy="72" r="2.1"/><circle class="pin" cx="456" cy="84" r="2.1"/><circle class="pin" cx="456" cy="96" r="2.1"/>

            <!-- Trace statis (segmen H/V + diagonal 45 derajat) -->
            <path class="t-line--dim" d="M28 84 H 46"/>
            <path class="t-line--dim" d="M46 68 H 30 V 26 H 24"/>
            <path class="t-line--dim" d="M110 68 L 126 84 V 140"/>
            <path class="t-line--dim" d="M110 100 L 128 118 V 240"/>
            <path class="t-line--dim" d="M404 96 L 388 112 V 190"/>
            <path class="t-line--dim" d="M456 72 V 46"/>
            <path class="t-line--dim" d="M62 760 V 824 L 92 854 H 440"/>

            <!-- Node ujung trace (delay tersebar agar tidak serempak) -->
            <circle class="node" cx="24" cy="26" r="2.6"/><circle class="node node--d2" cx="28" cy="84" r="2.6"/>
            <circle class="node node--d3" cx="126" cy="140" r="2.6"/><circle class="node node--d2" cx="128" cy="240" r="2.6"/>
            <circle class="node node--d3" cx="388" cy="190" r="2.6"/><circle class="node" cx="456" cy="46" r="2.6"/>
            <circle class="node node--d2" cx="440" cy="854" r="2.6"/><circle class="node node--d3" cx="60" cy="640" r="2.6"/>

            <!-- BUS-1 (cyan): dasar jalur + dots + burst + tail -->
            <path class="t-bus" d="M62 116 V 300 L 92 330 V 470 L 62 500 V 760"/>
            <path class="pulse p-dots bus-b1" d="M62 116 V 300 L 92 330 V 470 L 62 500 V 760"/>
            <path class="pulse p-burst bus-b1" d="M62 116 V 300 L 92 330 V 470 L 62 500 V 760"/>
            <path class="pulse p-tail bus-b1" d="M62 116 V 300 L 92 330 V 470 L 62 500 V 760"/>

            <!-- BUS-2 (violet) -->
            <path class="t-bus" d="M430 110 V 170 L 456 196 V 300 L 430 326 V 520 L 456 546 V 720"/>
            <path class="pulse p-dots bus-b2" d="M430 110 V 170 L 456 196 V 300 L 430 326 V 520 L 456 546 V 720"/>
            <path class="pulse p-burst bus-b2" d="M430 110 V 170 L 456 196 V 300 L 430 326 V 520 L 456 546 V 720"/>
            <path class="pulse p-tail bus-b2" d="M430 110 V 170 L 456 196 V 300 L 430 326 V 520 L 456 546 V 720"/>

            <!-- BUS-3 (biru/teal) -->
            <path class="t-bus" d="M60 640 H 150 L 186 676 H 300 L 336 640 H 420"/>
            <path class="pulse p-dots bus-b3" d="M60 640 H 150 L 186 676 H 300 L 336 640 H 420"/>
            <path class="pulse p-burst bus-b3" d="M60 640 H 150 L 186 676 H 300 L 336 640 H 420"/>
            <path class="pulse p-tail bus-b3" d="M60 640 H 150 L 186 676 H 300 L 336 640 H 420"/>

            <!-- Node tujuan bus (core: glow + blink lebih cepat) -->
            <circle class="node node--core" cx="62" cy="760" r="4"/>
            <circle class="node node--core node--d3" cx="456" cy="720" r="4"/>
            <circle class="node node--core node--d2" cx="420" cy="640" r="4"/>
        </svg>

        <div class="auth-vignette absolute inset-0"></div>
    </div>

    <!-- ═══ TOP: Branding & ambient (Plan 97) ═══ -->
    <section class="h-[34vh] min-h-[260px] w-full relative flex flex-col items-center justify-center shrink-0">
        <!-- Plan 94 (F1) + Plan 97: lang switcher & theme toggle (cluster kanan-atas) -->
        <div class="absolute top-4 right-4 z-30 flex items-center gap-2">
            <?php $this->load->view('templates/lang_switcher'); ?>
            <?php $this->load->view('templates/auth_theme_toggle'); ?>
        </div>
        <div class="auth-halo" aria-hidden="true"></div>
        <div class="relative z-10 flex items-center justify-center px-8">
            <!-- Plan 97: mark neural dekoratif — inline SVG, zero-dep, tema-adaptif -->
            <svg viewBox="0 0 40 40" class="w-8 h-8 mr-1.5 shrink-0" aria-hidden="true">
                <polygon points="31.3,26.5 20,33 8.7,26.5 8.7,13.5 20,7 31.3,13.5" fill="none" stroke="var(--u-auth-wm-b)" stroke-width="1.2" stroke-linejoin="round" opacity=".55"/>
                <path d="M20 20 31.3 26.5M20 20 20 33M20 20 8.7 26.5M20 20 8.7 13.5M20 20 20 7M20 20 31.3 13.5" stroke="var(--u-auth-wm-b)" stroke-width="1.1" stroke-linecap="round" opacity=".35"/>
                <circle cx="20" cy="20" r="2.7" fill="var(--u-auth-wm-c)"/>
                <circle cx="31.3" cy="26.5" r="1.5" fill="var(--u-auth-wm-b)"/>
                <circle cx="20" cy="33" r="1.5" fill="var(--u-auth-wm-b)"/>
                <circle cx="8.7" cy="26.5" r="1.5" fill="var(--u-auth-wm-b)"/>
                <circle cx="8.7" cy="13.5" r="1.5" fill="var(--u-auth-wm-b)"/>
                <circle cx="20" cy="7" r="1.5" fill="var(--u-auth-wm-b)"/>
                <circle cx="31.3" cy="13.5" r="1.5" fill="var(--u-auth-wm-b)"/>
            </svg>
            <h1 class="u-auth-wordmark">Synapse</h1>
        </div>
        <p class="u-auth-tagline relative z-10 mt-3 text-sm font-medium text-center px-6 leading-relaxed"><?= lang('auth_tagline_change_pw') ?></p>
    </section>

    <!-- ═══ BOTTOM: Form Card (Phase 32: theme-aware surface) ═══ -->
    <div class="auth-card flex-1 rounded-t-[2.5rem] w-full relative z-20 px-6 py-8 flex flex-col -mt-4">

        <!-- Flashdata Success -->
        <?php if ($this->session->flashdata('success')): ?>
            <div class="u-flash-success px-4 py-3 rounded-xl text-sm mb-4">
                <?= $this->session->flashdata('success') ?>
            </div>
        <?php endif; ?>

        <!-- Flashdata Error -->
        <?php if ($this->session->flashdata('error')): ?>
            <div class="u-flash-error px-4 py-3 rounded-xl text-sm mb-4">
                <?= $this->session->flashdata('error') ?>
            </div>
        <?php endif; ?>

        <!-- Validation / Auth Errors -->
        <?php if (!empty($errors)): ?>
            <div class="mb-4 space-y-1">
                <?php foreach ($errors as $e): ?>
                    <div class="u-flash-error px-4 py-3 rounded-xl text-sm"><?= $e ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Change Password Form -->
        <h2 class="text-xl font-bold u-text mb-1"><?= lang('auth_cpw_title') ?></h2>
        <p class="text-sm u-text-2 mb-6"><?= lang('auth_cpw_subtitle') ?></p>

        <?= form_open('auth/change-password', ['class' => 'space-y-4']) ?>

            <div>
                <label for="new_password" class="text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5 block"><?= lang('auth_new_password_label') ?></label>
                <input type="password" id="new_password" name="new_password"
                       class="u-input h-14 px-5 rounded-2xl text-sm focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all w-full"
                       placeholder="<?= lang('common_min8_placeholder') ?>" autocomplete="new-password">
                <?= form_error('new_password', '<p class="text-xs text-rose-500 mt-1.5">', '</p>') ?>
            </div>

            <div>
                <label for="confirm_password" class="text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5 block"><?= lang('auth_confirm_password_label') ?></label>
                <input type="password" id="confirm_password" name="confirm_password"
                       class="u-input h-14 px-5 rounded-2xl text-sm focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition-all w-full"
                       placeholder="<?= lang('auth_confirm_placeholder') ?>" autocomplete="new-password">
                <?= form_error('confirm_password', '<p class="text-xs text-rose-500 mt-1.5">', '</p>') ?>
            </div>

            <button type="submit" class="u-btn-dark h-14 w-full rounded-2xl shadow-lg flex items-center justify-center mt-2">
                <span><?= lang('auth_cpw_btn') ?></span>
            </button>

        <?= form_close() ?>

    </div>

</div>

</body>
</html>
