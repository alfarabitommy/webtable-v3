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
</body>
</html>
