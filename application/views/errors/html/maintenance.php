<?php
defined('BASEPATH') OR exit('No direct script access allowed');
// plan/95: Standalone HTTP 503 maintenance page (member site locked down).
// NOTE: rendered by maintenance_gate() BEFORE any member-shell view/global
// vars exist — must NOT reference $global_*, templates, or DB data.
// Self-contained: inline CSS only (no CDN/Tailwind dependency).
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0b1220">
<title>Maintenance — Synapse</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { height: 100%; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                     "Helvetica Neue", Arial, sans-serif;
        background: linear-gradient(160deg, #0b1220 0%, #101b33 55%, #0d2440 100%);
        color: #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        min-height: 100vh;
        -webkit-font-smoothing: antialiased;
    }
    .card {
        max-width: 520px;
        width: 100%;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 20px;
        padding: 44px 32px;
        text-align: center;
        box-shadow: 0 20px 50px rgba(2, 6, 23, 0.55);
    }
    .icon {
        width: 72px;
        height: 72px;
        margin: 0 auto 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(99, 102, 241, 0.14);
        border: 1px solid rgba(129, 140, 248, 0.35);
        animation: spin 9s linear infinite;
    }
    .icon svg { width: 36px; height: 36px; stroke: #a5b4fc; }
    @keyframes spin { to { transform: rotate(360deg); } }
    h1 { font-size: 1.45rem; font-weight: 800; letter-spacing: -0.01em; color: #f1f5f9; }
    .en-sub {
        margin-top: 6px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.14em;
        color: #818cf8;
    }
    p.msg { margin-top: 18px; font-size: 0.95rem; line-height: 1.65; color: #cbd5e1; }
    p.msg .en { display: block; margin-top: 10px; color: #94a3b8; font-size: 0.88rem; }
    .status {
        margin-top: 26px;
        padding-top: 16px;
        border-top: 1px dashed rgba(148, 163, 184, 0.22);
        font-size: 0.72rem;
        letter-spacing: 0.08em;
        color: #64748b;
    }
    @media (prefers-reduced-motion: reduce) {
        .icon { animation: none; }
    }
</style>
</head>
<body>
    <main class="card">
        <div class="icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                <path d="M9.8 15.2 2.2 22.6"/><path d="M18 13a5 5 0 0 0 5 5"/>
            </svg>
        </div>
        <h1>Sistem Sedang Dalam Pemeliharaan</h1>
        <div class="en-sub">We&rsquo;re Currently Under Maintenance</div>
        <p class="msg">
            Situs member sedang dikunci sementara untuk perawatan. Silakan kembali
            lagi nanti — sesi Anda tetap aman.
            <span class="en">The member site is temporarily locked for maintenance.
            Please check back later — your session remains safe.</span>
        </p>
        <div class="status">HTTP 503 Service Unavailable &middot; Synapse</div>
    </main>
</body>
</html>
