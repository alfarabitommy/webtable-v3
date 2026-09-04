    <!-- Fixed Bottom Navigation — Golden 5 (Phase 32: glassmorphic + glow active dot) -->
    <nav class="h-16 u-nav-bottom fixed bottom-0 w-full max-w-[480px] z-50 flex justify-around items-center text-[10px] font-medium">

        <!-- 1. Beranda -->
        <a href="<?= site_url('home') ?>" class="relative flex flex-col items-center gap-0.5 p-2 <?= ($this->uri->segment(1) === 'home' || $this->uri->segment(1) === '') ? 'u-nav-active font-bold' : 'u-nav-item' ?>">
            <span class="absolute top-1 left-1/2 -translate-x-1/2 u-nav-dot <?= ($this->uri->segment(1) === 'home' || $this->uri->segment(1) === '') ? '' : 'opacity-0' ?>"></span>
            <i class="fas fa-home text-lg"></i>
            <span>Beranda</span>
        </a>

        <!-- 2. Market (Beli Paket) -->
        <a href="<?= site_url('marketplace') ?>" class="relative flex flex-col items-center gap-0.5 p-2 <?= $this->uri->segment(1) === 'marketplace' ? 'u-nav-active font-bold' : 'u-nav-item' ?>">
            <span class="absolute top-1 left-1/2 -translate-x-1/2 u-nav-dot <?= $this->uri->segment(1) === 'marketplace' ? '' : 'opacity-0' ?>"></span>
            <i class="fas fa-shopping-cart text-lg"></i>
            <span>Market</span>
            <span class="text-[8px] u-muted -mt-0.5">Beli Paket</span>
        </a>

        <!-- 3. Sewa Saya (Aset) -->
        <a href="<?= site_url('rentals') ?>" class="relative flex flex-col items-center gap-0.5 p-2 <?= $this->uri->segment(1) === 'rentals' ? 'u-nav-active font-bold' : 'u-nav-item' ?>">
            <span class="absolute top-1 left-1/2 -translate-x-1/2 u-nav-dot <?= $this->uri->segment(1) === 'rentals' ? '' : 'opacity-0' ?>"></span>
            <i class="fas fa-server text-lg"></i>
            <span>Sewa Saya</span>
            <span class="text-[8px] u-muted -mt-0.5">Aset</span>
        </a>

        <!-- 4. Afiliasi (Jaringan) -->
        <a href="<?= site_url('team') ?>" class="relative flex flex-col items-center gap-0.5 p-2 <?= $this->uri->segment(1) === 'team' ? 'u-nav-active font-bold' : 'u-nav-item' ?>">
            <span class="absolute top-1 left-1/2 -translate-x-1/2 u-nav-dot <?= $this->uri->segment(1) === 'team' ? '' : 'opacity-0' ?>"></span>
            <i class="fas fa-users text-lg"></i>
            <span>Afiliasi</span>
            <span class="text-[8px] u-muted -mt-0.5">Jaringan</span>
        </a>

        <!-- 5. Profil (Akun) -->
        <a href="<?= site_url('profile') ?>" class="relative flex flex-col items-center gap-0.5 p-2 <?= $this->uri->segment(1) === 'profile' ? 'u-nav-active font-bold' : 'u-nav-item' ?>">
            <span class="absolute top-1 left-1/2 -translate-x-1/2 u-nav-dot <?= $this->uri->segment(1) === 'profile' ? '' : 'opacity-0' ?>"></span>
            <i class="fas fa-user-circle text-lg"></i>
            <span>Profil</span>
            <span class="text-[8px] u-muted -mt-0.5">Akun</span>
        </a>

    </nav>

</div>
</body>
</html>
