<div class="p-4 space-y-6">
    <!-- Header -->
    <div class="flex items-center gap-3 mb-6">
        <a href="<?= base_url('wallet'); ?>" class="w-8 h-8 bg-white border border-slate-200 rounded-full flex items-center justify-center text-slate-500 shadow-sm active:scale-90 transition-all">
            <i class="fas fa-arrow-left text-xs"></i>
        </a>
        <h2 class="text-xl font-extrabold text-slate-900 tracking-tight"><?= $page_title ?></h2>
    </div>

    <!-- Flash Messages -->
    <?php if ($this->session->flashdata('success')): ?>
        <div class="bg-emerald-100 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl text-xs font-bold flex items-center gap-3 shadow-sm">
            <i class="fas fa-check-circle"></i>
            <?= $this->session->flashdata('success'); ?>
        </div>
    <?php endif; ?>

    <?php if ($this->session->flashdata('error')): ?>
        <div class="bg-rose-100 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-xs font-bold flex items-center gap-3 shadow-sm">
            <i class="fas fa-exclamation-circle"></i>
            <?= $this->session->flashdata('error'); ?>
        </div>
    <?php endif; ?>

    <?php if ($existing_bank): ?>
    <!-- ===== STATE B: BANK BOUND (READ-ONLY CARD) ===== -->
    <div class="bg-slate-900 text-white p-6 rounded-2xl shadow-xl relative overflow-hidden">
        <div class="absolute inset-0 opacity-5" style="background-image: repeating-linear-gradient(45deg, #fff 0, #fff 1px, transparent 1px, transparent 20px);"></div>
        <div class="relative z-10 space-y-6">
            <div class="flex items-center justify-between">
                <span class="text-slate-400 text-xs uppercase tracking-wider font-bold">Rekening Terikat</span>
                <span class="bg-emerald-500/20 text-emerald-400 text-[10px] font-bold px-2 py-0.5 rounded-full border border-emerald-500/30">TERVERIFIKASI</span>
            </div>

            <div>
                <div class="text-2xl font-extrabold tracking-tight"><?= htmlspecialchars($existing_bank->bank_name); ?></div>
            </div>

            <div>
                <span class="text-slate-400 text-[10px] uppercase tracking-widest font-bold block mb-1">Nomor Rekening</span>
                <div class="text-xl font-mono font-bold tracking-widest">
                    <?php
                        $acc = $existing_bank->account_number;
                        $len = strlen($acc);
                        if ($len > 7) {
                            $first = substr($acc, 0, 4);
                            $last  = substr($acc, -3);
                            echo $first . str_repeat('*', $len - 7) . $last;
                        } else {
                            echo $acc;
                        }
                    ?>
                </div>
            </div>

            <div class="flex justify-between items-end border-t border-slate-800 pt-4">
                <div>
                    <span class="text-slate-400 text-[10px] uppercase tracking-widest font-bold block mb-0.5">Atas Nama</span>
                    <div class="text-sm font-bold"><?= htmlspecialchars($existing_bank->account_holder); ?></div>
                </div>
                <div class="text-right">
                    <span class="text-slate-500 text-[10px] font-mono">Bound</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Security Warning -->
    <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 flex gap-3 items-start">
        <div class="bg-rose-100 w-8 h-8 rounded-full flex items-center justify-center shrink-0 mt-0.5">
            <i class="fas fa-lock text-rose-500 text-xs"></i>
        </div>
        <div>
            <h4 class="text-rose-700 text-xs font-extrabold mb-1">Data Rekening Dikunci</h4>
            <p class="text-rose-600 text-[11px] leading-relaxed">Data rekening telah dikunci demi keamanan. Hubungi Customer Service untuk perubahan.</p>
        </div>
    </div>

    <?php else: ?>
    <!-- ===== STATE A: NO BANK (BIND FORM) ===== -->
    <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 bg-indigo-50 rounded-xl flex items-center justify-center">
                <i class="fas fa-university text-indigo-500 text-sm"></i>
            </div>
            <div>
                <h3 class="text-sm font-extrabold text-slate-900">Ikat Rekening Bank</h3>
                <p class="text-[10px] text-slate-500">Data hanya bisa dikirim satu kali</p>
            </div>
        </div>

        <?= form_open('wallet/bind_bank', ['class' => 'space-y-4']); ?>

            <!-- Bank Name -->
            <div>
                <label class="text-[10px] uppercase tracking-widest text-slate-400 font-bold block mb-1.5">Nama Bank</label>
                <select name="bank_name" class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-slate-50 text-sm text-slate-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all" required>
                    <option value="">Pilih Bank</option>
                    <option value="Bank Central Asia (BCA)" <?= set_select('bank_name', 'Bank Central Asia (BCA)'); ?>>Bank Central Asia (BCA)</option>
                    <option value="Bank Mandiri" <?= set_select('bank_name', 'Bank Mandiri'); ?>>Bank Mandiri</option>
                    <option value="Bank Rakyat Indonesia (BRI)" <?= set_select('bank_name', 'Bank Rakyat Indonesia (BRI)'); ?>>Bank Rakyat Indonesia (BRI)</option>
                    <option value="Bank Negara Indonesia (BNI)" <?= set_select('bank_name', 'Bank Negara Indonesia (BNI)'); ?>>Bank Negara Indonesia (BNI)</option>
                    <option value="Bank Syariah Indonesia (BSI)" <?= set_select('bank_name', 'Bank Syariah Indonesia (BSI)'); ?>>Bank Syariah Indonesia (BSI)</option>
                    <option value="Bank Danamon" <?= set_select('bank_name', 'Bank Danamon'); ?>>Bank Danamon</option>
                    <option value="Bank CIMB Niaga" <?= set_select('bank_name', 'Bank CIMB Niaga'); ?>>Bank CIMB Niaga</option>
                    <option value="Bank Permata" <?= set_select('bank_name', 'Bank Permata'); ?>>Bank Permata</option>
                    <option value="Bank Mega" <?= set_select('bank_name', 'Bank Mega'); ?>>Bank Mega</option>
                    <option value="Bank Neo Commerce (BNC)" <?= set_select('bank_name', 'Bank Neo Commerce (BNC)'); ?>>Bank Neo Commerce (BNC)</option>
                    <option value="Jago" <?= set_select('bank_name', 'Jago'); ?>>Jago</option>
                    <option value="Sea Bank" <?= set_select('bank_name', 'Sea Bank'); ?>>Sea Bank</option>
                    <option value="Bank Jago" <?= set_select('bank_name', 'Bank Jago'); ?>>Bank Jago</option>
                </select>
                <?= form_error('bank_name', '<p class="text-xs text-rose-500 mt-1">', '</p>'); ?>
            </div>

            <!-- Account Number -->
            <div>
                <label class="text-[10px] uppercase tracking-widest text-slate-400 font-bold block mb-1.5">Nomor Rekening</label>
                <input type="text" name="account_number" value="<?= set_value('account_number'); ?>" placeholder="Masukkan nomor rekening" class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-slate-50 text-sm text-slate-900 font-mono tracking-wider focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all" required inputmode="numeric">
                <?= form_error('account_number', '<p class="text-xs text-rose-500 mt-1">', '</p>'); ?>
            </div>

            <!-- Account Holder -->
            <div>
                <label class="text-[10px] uppercase tracking-widest text-slate-400 font-bold block mb-1.5">Nama Pemilik Rekening</label>
                <input type="text" name="account_holder" value="<?= set_value('account_holder'); ?>" placeholder="Sesuai nama di rekening bank" class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-slate-50 text-sm text-slate-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all capitalize" required>
                <?= form_error('account_holder', '<p class="text-xs text-rose-500 mt-1">', '</p>'); ?>
            </div>

            <!-- Security Notice -->
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 flex gap-3 items-start mt-4">
                <i class="fas fa-exclamation-triangle text-amber-500 text-xs mt-0.5"></i>
                <p class="text-amber-700 text-[11px] leading-relaxed">Perhatian: Data rekening hanya bisa dikirim <strong>sekali</strong> dan tidak dapat diubah setelah dikirim demi keamanan akun Anda.</p>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="w-full h-14 bg-slate-900 hover:bg-indigo-600 text-white rounded-xl text-sm font-extrabold shadow-lg transition-all active:scale-95 mt-6">
                <i class="fas fa-link mr-2"></i> Ikat Rekening
            </button>

        <?= form_close(); ?>
    </div>
    <?php endif; ?>
</div>
