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
                            backgroundColor: '#1e293b',
                            titleColor: '#e2e8f0',
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
                            grid: { color: 'rgba(148,163,184,0.08)', drawBorder: false },
                            ticks: { color: '#94a3b8', font: { family: 'JetBrains Mono', size: 10 } }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(148,163,184,0.08)', drawBorder: false },
                            ticks: {
                                color: '#94a3b8',
                                font: { family: 'JetBrains Mono', size: 10 },
                                callback: function(val) { return 'Rp ' + (val/1000) + 'k'; }
                            }
                        }
                    }
                }
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
    </script>
</body>
</html>
