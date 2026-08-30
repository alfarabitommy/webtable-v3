    <!-- Fixed Bottom Navigation — Golden 5 -->
    <nav class="h-16 bg-white border-t border-slate-200 fixed bottom-0 w-full max-w-[480px] z-50 flex justify-around items-center text-[10px] font-medium text-slate-500">

        <!-- 1. Beranda -->
        <a href="<?= site_url('home') ?>" class="flex flex-col items-center gap-0.5 p-2 <?= ($this->uri->segment(1) === 'home' || $this->uri->segment(1) === '') ? 'text-indigo-600 font-bold' : '' ?>">
            <i class="fas fa-home text-lg"></i>
            <span>Beranda</span>
        </a>

        <!-- 2. Market (Beli Paket) -->
        <a href="<?= site_url('marketplace') ?>" class="flex flex-col items-center gap-0.5 p-2 <?= $this->uri->segment(1) === 'marketplace' ? 'text-indigo-600 font-bold' : '' ?>">
            <i class="fas fa-shopping-cart text-lg"></i>
            <span>Market</span>
            <span class="text-[8px] text-slate-400 -mt-0.5">Beli Paket</span>
        </a>

        <!-- 3. Sewa Saya (Aset) -->
        <a href="<?= site_url('rentals') ?>" class="flex flex-col items-center gap-0.5 p-2 <?= $this->uri->segment(1) === 'rentals' ? 'text-indigo-600 font-bold' : '' ?>">
            <i class="fas fa-server text-lg"></i>
            <span>Sewa Saya</span>
            <span class="text-[8px] text-slate-400 -mt-0.5">Aset</span>
        </a>

        <!-- 4. Afiliasi (Jaringan) -->
        <a href="<?= site_url('team') ?>" class="flex flex-col items-center gap-0.5 p-2 <?= $this->uri->segment(1) === 'team' ? 'text-indigo-600 font-bold' : '' ?>">
            <i class="fas fa-users text-lg"></i>
            <span>Afiliasi</span>
            <span class="text-[8px] text-slate-400 -mt-0.5">Jaringan</span>
        </a>

        <!-- 5. Profil (Akun) -->
        <a href="<?= site_url('profile') ?>" class="flex flex-col items-center gap-0.5 p-2 <?= $this->uri->segment(1) === 'profile' ? 'text-indigo-600 font-bold' : '' ?>">
            <i class="fas fa-user-circle text-lg"></i>
            <span>Profil</span>
            <span class="text-[8px] text-slate-400 -mt-0.5">Akun</span>
        </a>

    </nav>

</div>
</body>
</html>
