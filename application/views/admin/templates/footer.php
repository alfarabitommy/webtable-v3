        </main>
    </div>

    <script>
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
