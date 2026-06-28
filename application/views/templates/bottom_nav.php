    <!-- Fixed Bottom Navigation -->
    <nav class="h-16 bg-white border-t border-slate-200 fixed bottom-0 w-full max-w-[480px] z-50 flex justify-around items-center text-[10px] font-medium text-slate-500">
        <a href="<?= site_url('home') ?>" class="flex flex-col items-center gap-1 p-2 <?= ($this->uri->segment(1) === 'home' || $this->uri->segment(1) === '') ? 'text-blue-600 font-bold' : '' ?>">
            <i class="fas fa-home text-lg"></i>
            <span>Home</span>
        </a>
        <a href="<?= site_url('rentals') ?>" class="flex flex-col items-center gap-1 p-2 <?= $this->uri->segment(1) === 'rentals' ? 'text-blue-600 font-bold' : '' ?>">
            <i class="fas fa-server text-lg"></i>
            <span>Sewa Saya</span>
        </a>
        <a href="<?= site_url('team') ?>" class="flex flex-col items-center gap-1 p-2 <?= $this->uri->segment(1) === 'team' ? 'text-blue-600 font-bold' : '' ?>">
            <i class="fas fa-users text-lg"></i>
            <span>Tim</span>
        </a>
        <a href="<?= site_url('help') ?>" class="flex flex-col items-center gap-1 p-2 <?= $this->uri->segment(1) === 'help' ? 'text-blue-600 font-bold' : '' ?>">
            <i class="fas fa-life-ring text-lg"></i>
            <span>Bantuan</span>
        </a>
        <a href="<?= site_url('marketplace') ?>" class="flex flex-col items-center gap-1 p-2 <?= $this->uri->segment(1) === 'marketplace' ? 'text-blue-600 font-bold' : '' ?>">
            <i class="fas fa-store text-lg"></i>
            <span>Marketplace</span>
        </a>
        <a href="<?= site_url('profile') ?>" class="flex flex-col items-center gap-1 p-2 <?= $this->uri->segment(1) === 'profile' ? 'text-blue-600 font-bold' : '' ?>">
            <i class="fas fa-user text-lg"></i>
            <span>Profil</span>
        </a>
    </nav>

</div>
</body>
</html>
