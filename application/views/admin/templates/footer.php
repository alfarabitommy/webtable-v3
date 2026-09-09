        </main>
    </div>

    <script>
        /* ── Phase 9A: Revenue Chart ── */
        (function() {
            const canvas = document.getElementById('revenueChart');
            if (!canvas) return;

            const ctx = canvas.getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, 280);
            gradient.addColorStop(0, 'rgba(16,185,129,0.20)');
            gradient.addColorStop(1, 'rgba(16,185,129,0.00)');

            /* Phase 30: read theme colors from CSS variables */
            function themeColors() {
                const css = getComputedStyle(document.documentElement);
                return {
                    grid: css.getPropertyValue('--t-chart-grid').trim() || 'rgba(148,163,184,0.08)',
                    tick: css.getPropertyValue('--t-chart-tick').trim() || '#94a3b8',
                    tooltipBg: css.getPropertyValue('--t-tooltip-bg').trim() || '#1e293b',
                    tooltipTitle: css.getPropertyValue('--t-tooltip-title').trim() || '#e2e8f0'
                };
            }

            const colors = themeColors();

            const chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chart_data['labels'] ?? []) ?>,
                    datasets: [{
                        label: 'Revenue',
                        data: <?= json_encode($chart_data['data'] ?? []) ?>,
                        borderColor: '#10b981',
                        backgroundColor: gradient,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#10b981',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { intersect: false, mode: 'index' },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: colors.tooltipBg,
                            titleColor: colors.tooltipTitle,
                            bodyColor: '#10b981',
                            borderColor: '#10b981',
                            borderWidth: 1,
                            cornerRadius: 8,
                            titleFont: { family: 'JetBrains Mono', size: 11 },
                            bodyFont: { family: 'JetBrains Mono', size: 12, weight: '600' },
                            padding: 10,
                            callbacks: {
                                label: function(ctx) {
                                    return 'Rp ' + ctx.parsed.y.toLocaleString('id-ID');
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: colors.grid, drawBorder: false },
                            ticks: { color: colors.tick, font: { family: 'JetBrains Mono', size: 10 } }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: colors.grid, drawBorder: false },
                            ticks: {
                                color: colors.tick,
                                font: { family: 'JetBrains Mono', size: 10 },
                                callback: function(val) { return 'Rp ' + (val/1000) + 'k'; }
                            }
                        }
                    }
                }
            });

            /* Expose for theme re-render */
            window.revenueChart = chart;

            /* Phase 30: re-render chart when theme toggles */
            window.addEventListener('admin-theme-change', function() {
                const c = themeColors();
                const o = chart.options;
                o.scales.x.grid.color = c.grid;
                o.scales.y.grid.color = c.grid;
                o.scales.x.ticks.color = c.tick;
                o.scales.y.ticks.color = c.tick;
                o.plugins.tooltip.backgroundColor = c.tooltipBg;
                o.plugins.tooltip.titleColor = c.tooltipTitle;
                chart.update();
            });

            /* ── AJAX dropdown ── */
            document.getElementById('chartPeriod').addEventListener('change', function() {
                var days = this.value;
                fetch('<?= site_url('admin/chart_data') ?>?days=' + days)
                    .then(function(r) { return r.json(); })
                    .then(function(json) {
                        chart.data.labels = json.labels;
                        chart.data.datasets[0].data = json.data;
                        chart.update();
                    })
                    .catch(function(e) { console.error('Chart fetch error:', e); });
            });
        })();

        /* ── Sidebar Toggle ── */
        function toggleSidebar() {
            const sidebar = document.getElementById('admin-sidebar');
            const overlay = document.getElementById('admin-sidebar-overlay');
            const isOpen = !sidebar.classList.contains('hidden');
            if (isOpen) {
                sidebar.classList.add('hidden');
                overlay.classList.add('hidden');
            } else {
                sidebar.classList.remove('hidden');
                sidebar.classList.add('flex');
                overlay.classList.remove('hidden');
            }
        }

        /* ── Phase 30: Admin Theme Manager ── */
        function toggleAdminTheme() {
            const html = document.documentElement;
            const dark = html.classList.toggle('dark');
            try { localStorage.setItem('admin_theme', dark ? 'dark' : 'light'); } catch (e) {}
            const icon = document.getElementById('theme-toggle-icon');
            if (icon) icon.className = 'fas ' + (dark ? 'fa-sun' : 'fa-moon') + ' text-sm';
            window.dispatchEvent(new CustomEvent('admin-theme-change', { detail: { dark: dark } }));
        }

        /* Sync toggle icon with the current theme on load */
        (function() {
            const icon = document.getElementById('theme-toggle-icon');
            if (icon) {
                const dark = document.documentElement.classList.contains('dark');
                icon.className = 'fas ' + (dark ? 'fa-sun' : 'fa-moon') + ' text-sm';
            }
        })();
    </script>

    <!-- ═══ Plan 94 (F2): Admin Operational Alert Center — poller + bell + chime ═══ -->
    <script>
    (function () {
        // SSR baseline (dari Admin::__construct) — badge/bell sudah terisi
        // sebelum poll pertama; JS hanya menjaga agar DOM sinkron.
        var SSR = <?= json_encode([
            'pending_deposits'    => (int) ($global_admin_alerts['pending_deposits'] ?? 0),
            'pending_withdrawals' => (int) ($global_admin_alerts['pending_withdrawals'] ?? 0),
            'pending_promoter_claims' => (int) ($global_admin_alerts['pending_promoter_claims'] ?? 0),
        ]) ?>;

        var POLL_URL = <?= json_encode(site_url('admin/alerts/poll')) ?>;
        var POLL_MS  = 25000; // rentang spek 20–30 dtk

        var lastTotal = SSR.pending_deposits + SSR.pending_withdrawals + SSR.pending_promoter_claims;
        var inFlight  = false;
        var failures  = 0;
        var muted     = false;

        try { muted = localStorage.getItem('admin_alerts_muted') === '1'; } catch (e) {}

        function setBadge(el, n, cap) {
            if (!el) return;
            var max = cap || 99;
            el.textContent = n > max ? '99+' : n;
            if (n > 0) { el.classList.remove('hidden'); } else { el.classList.add('hidden'); }
        }

        function applyAlerts(c) {
            if (!c) return;
            var dep  = c.pending_deposits    | 0;
            var wd   = c.pending_withdrawals | 0;
            var prom = c.pending_promoter_claims | 0;
            var tot  = dep + wd + prom;
            // Sidebar badges
            setBadge(document.getElementById('admin-badge-deposit'), dep);
            setBadge(document.getElementById('admin-badge-withdrawal'), wd);
            setBadge(document.getElementById('admin-badge-promoter'), prom);
            // Bell dropdown row counts + total badge
            setBadge(document.getElementById('admin-bell-deposit-count'), dep);
            setBadge(document.getElementById('admin-bell-withdrawal-count'), wd);
            setBadge(document.getElementById('admin-bell-promoter-count'), prom);
            setBadge(document.getElementById('admin-bell-badge'), tot);
            return tot;
        }

        // Terapkan baseline SSR sekali (normalisasi hidden/0).
        applyAlerts(SSR);

        // ── Audio chime (A6): sintesis Web Audio 2 nada — NOL aset file.
        // AudioContext dibuat/resume pada user gesture pertama (autoplay
        // policy). Hanya berbunyi saat total_urgent NAIK vs state terakhir.
        var audioCtx = null;
        function ensureAudio() {
            try {
                if (!audioCtx) {
                    var AC = window.AudioContext || window.webkitAudioContext;
                    if (AC) audioCtx = new AC();
                }
                if (audioCtx && audioCtx.state === 'suspended') { audioCtx.resume(); }
            } catch (e) {}
        }
        function chime() {
            if (muted) return;
            ensureAudio();
            if (!audioCtx) return;
            try {
                var t = audioCtx.currentTime;
                [[880, 0], [1174.66, 0.19]].forEach(function (note) {
                    var osc = audioCtx.createOscillator();
                    var gain = audioCtx.createGain();
                    osc.type = 'sine';
                    osc.frequency.value = note[0];
                    gain.gain.setValueAtTime(0.0001, t + note[1]);
                    gain.gain.exponentialRampToValueAtTime(0.14, t + note[1] + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, t + note[1] + 0.22);
                    osc.connect(gain).connect(audioCtx.destination);
                    osc.start(t + note[1]);
                    osc.stop(t + note[1] + 0.24);
                });
            } catch (e) {}
        }

        // ── Polling (A5): satu in-flight; skip saat tab hidden; langsung
        // poll saat visible kembali; error jaringan = retry senyap, tapi
        // kegagalan beruntun (sesi mati → redirect login HTML) → reload.
        function poll() {
            if (inFlight || document.hidden) return;
            inFlight = true;
            fetch(POLL_URL, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    var ct = r.headers.get('content-type') || '';
                    if (ct.indexOf('application/json') === -1) throw new Error('not-json');
                    return r.json();
                })
                .then(function (j) {
                    failures = 0;
                    var c = (j && j.data) || j;
                    var tot = applyAlerts(c);
                    if (typeof tot === 'number' && tot > lastTotal && !muted && !document.hidden) {
                        chime(); // naik vs state sebelumnya — sekali per kenaikan
                    }
                    if (typeof tot === 'number') { lastTotal = tot; }
                })
                .catch(function () {
                    failures += 1;
                    if (failures >= 2) { window.location.reload(); } // sesi admin mati
                })
                .finally(function () { inFlight = false; });
        }

        setInterval(poll, POLL_MS);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { poll(); }
        });

        // ── Bell dropdown toggle + click-outside ──
        var bellBtn = document.getElementById('admin-alerts-bell');
        var bellDrop = document.getElementById('admin-alerts-dropdown');
        var bellWrap = document.getElementById('admin-alerts-wrapper');
        if (bellBtn && bellDrop && bellWrap) {
            bellBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                bellDrop.classList.toggle('hidden');
            });
            document.addEventListener('click', function (e) {
                if (bellDrop && !bellWrap.contains(e.target)) {
                    bellDrop.classList.add('hidden');
                }
            });
        }

        // ── Mute toggle (localStorage 'admin_alerts_muted') ──
        var muteBtn = document.getElementById('admin-alerts-mute-btn');
        var muteIcon = muteBtn ? muteBtn.querySelector('i') : null;
        var muteLabel = document.getElementById('admin-alerts-mute-label');
        function syncMuteUI() {
            if (!muteIcon || !muteLabel) return;
            muteIcon.className = 'fas text-xs ' + (muted ? 'fa-volume-xmark' : 'fa-volume-high');
            muteLabel.textContent = muted ? 'Suara Nonaktif' : 'Suara Aktif';
        }
        if (muteBtn) {
            muteBtn.addEventListener('click', function () {
                muted = !muted;
                try { localStorage.setItem('admin_alerts_muted', muted ? '1' : '0'); } catch (e) {}
                syncMuteUI();
            });
        }
        syncMuteUI();

        // AudioContext dibolehkan setelah user gesture pertama (autoplay policy).
        ['pointerdown', 'keydown', 'touchstart'].forEach(function (evt) {
            document.addEventListener(evt, ensureAudio, { once: true, passive: true });
        });
    })();
    </script>
</body>
</html>
