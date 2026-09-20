<?php
defined('BASEPATH') OR exit('No direct script access allowed');
// M7 (plan/70): Pengaturan terpadu — kontak/support, jam operasional &
// hari aktif, biaya penarikan + tier dinamis, dan biaya deposit dalam satu
// halaman/form (single authoritative endpoint /admin/settings).
// Theme: Bloomberg Terminal / Clean Admin (theme-aware via Phase 30 tokens).

$day_labels = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

// ── plan/110: SUMBER RENDER ────────────────────────────────────────────────
// Prioritas nilai form = POST (via set_value) → state flashdata → DB.
// `settings_form_state` / `qris_form_state` di-set controller HANYA saat
// validasi gagal, sehingga admin tidak kehilangan input (dulu: redirect
// merender ulang dari DB dan seluruh ketikan finansial hilang).
// Semua nilai berasal dari input admin → WAJIB di-escape saat render.
$fs = (isset($form_state) && is_array($form_state)) ? $form_state : [];
$qs = (isset($qris_state) && is_array($qris_state)) ? $qris_state : [];

/** Nilai skalar dari state form (fallback ke nilai DB bila tak ada). */
$state_val = function ($map, $key, $fallback) {
    if (!is_array($map) || !array_key_exists($key, $map)) {
        return (string) $fallback;
    }
    $raw = $map[$key];
    return (is_scalar($raw) ? (string) $raw : (string) $fallback);
};

// Baris tier: state form (apa yang baru diketik admin) → config (DB).
$tier_rows = (!empty($fs['tier_rows']) && is_array($fs['tier_rows'])) ? $fs['tier_rows'] : $tier_rows;

// Hari aktif: state form → DB.
$active_days = (!empty($fs['wd_operational_days']) && is_array($fs['wd_operational_days']))
    ? array_map('strval', $fs['wd_operational_days'])
    : array_map('strval', $days);

// Error inline (map key → pesan[]) — diratakan untuk ditampilkan per kartu.
$error_flat = function ($map) {
    $out = [];
    if (is_array($map)) {
        foreach ($map as $messages) {
            foreach ((array) $messages as $message) {
                if (is_scalar($message) && trim((string) $message) !== '') {
                    $out[] = (string) $message;
                }
            }
        }
    }
    return array_values(array_unique($out));
};
$financial_errors = $error_flat(isset($field_errors) ? $field_errors : []);
$qris_errors      = $error_flat(isset($qris_field_errors) ? $qris_field_errors : []);
$auto_notices     = (isset($notices) && is_array($notices)) ? $notices : [];

$min_display = $state_val($fs, 'wd_min_amount', (int) $min_amount);
$max_display = $state_val($fs, 'wd_max_amount', (int) $max_amount);

// Kartu biaya deposit & rebate (satu form dengan finansial → ikut direpopulasi).
$dep_enabled = array_key_exists('deposit_fee_enabled', $fs)
    ? !empty($fs['deposit_fee_enabled'])
    : (bool) $deposit_fee_enabled;
$dep_type    = $state_val($fs, 'deposit_fee_type', $deposit_fee_type);
$dep_value   = $state_val($fs, 'deposit_fee_value', $deposit_fee_value);

$rebate_enabled_state = array_key_exists('rebate_enabled', $fs)
    ? !empty($fs['rebate_enabled'])
    : (bool) $rebate_enabled;

// Kartu QRIS/deposit (form TERPISAH → state sendiri, plan/110 P8).
$qris_merchant_display = $state_val($qs, 'qris_merchant_name', $qris_merchant_name);
$qris_instructions     = $state_val($qs, 'qris_payment_instructions', $qris_instructions);
$dep_expiry_display    = $state_val($qs, 'deposit_expiry_minutes', (int) $deposit_expiry_minutes);
$dep_min_display       = $state_val($qs, 'deposit_min_amount', (int) $deposit_min_amount);
$dep_max_display       = $state_val($qs, 'deposit_max_amount', (int) $deposit_max_amount);
?>

<?php if ($this->session->flashdata('success')): ?>
    <div class="mb-4 px-4 py-3 rounded-lg t-flash-success text-sm flex items-center gap-2">
        <i class="fas fa-check-circle"></i>
        <?= $this->session->flashdata('success') ?>
    </div>
<?php endif; ?>

<?php if ($this->session->flashdata('error')): ?>
    <div class="mb-4 px-4 py-3 rounded-lg t-flash-error text-sm flex items-center gap-2">
        <i class="fas fa-exclamation-circle"></i>
        <?= $this->session->flashdata('error') ?>
    </div>
<?php endif; ?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-6">
        <h3 class="text-lg font-semibold text-[var(--t-text)]">Pengaturan</h3>
        <p class="text-sm text-[var(--t-muted)] mt-1">
            Kelola informasi kontak support, jam operasional penarikan (WIB), biaya &amp; tier penarikan,
            serta biaya deposit. Perubahan langsung berlaku; fallback spec PRD di
            <code class="text-xs">application/config/withdrawal_fees.php</code>.
        </p>
    </div>

    <?= form_open('admin/settings', ['id' => 'settingsForm', 'data-guard-submit' => '1']) ?>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">

        <!-- ================= COLUMN KIRI ================= -->
        <div class="space-y-6">

            <!-- Card 1: Kontak & Bantuan (L1 — label Indonesia; plan/105) -->
            <div class="t-card p-6">
                <h4 class="text-sm font-semibold text-[var(--t-text)] mb-4 flex items-center gap-2">
                    <i class="fas fa-headset text-indigo-500"></i> Kontak &amp; Bantuan
                </h4>

                <div class="space-y-4">
                    <div>
                        <label for="wa_number" class="t-label text-sm mb-1.5">
                            <i class="fab fa-whatsapp text-emerald-500 mr-1"></i>
                            Nomor WhatsApp CS
                        </label>
                        <input type="text"
                               id="wa_number"
                               name="wa_number"
                               value="<?= set_value('wa_number', $state_val($fs, 'wa_number', $wa_number)) ?>"
                               pattern="[0-9]*"
                               inputmode="numeric"
                               required
                               placeholder="628xxxxxxxxxx"
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono
                                      focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <p class="text-xs text-[var(--t-muted)] mt-1">Format: kode negara + nomor. Contoh: 628123456789</p>
                    </div>

                    <div>
                        <label for="support_email" class="t-label text-sm mb-1.5">
                            <i class="fas fa-envelope text-blue-500 mr-1"></i>
                            Email Support
                        </label>
                        <input type="email"
                               id="support_email"
                               name="support_email"
                               value="<?= set_value('support_email', $state_val($fs, 'support_email', $support_email)) ?>"
                               required
                               placeholder="support@synapse.id"
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm
                                      focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>

                    <!-- plan/105: tautan grup/komunitas WhatsApp (opsional; '' = kartu disembunyikan) -->
                    <div>
                        <label for="wa_group_link" class="t-label text-sm mb-1.5">
                            <i class="fas fa-users text-emerald-500 mr-1"></i>
                            Link Grup WhatsApp (Komunitas)
                        </label>
                        <input type="text"
                               id="wa_group_link"
                               name="wa_group_link"
                               value="<?= set_value('wa_group_link', $state_val($fs, 'wa_group_link', $wa_group_link)) ?>"
                               inputmode="url"
                               autocomplete="off"
                               spellcheck="false"
                               maxlength="512"
                               placeholder="https://chat.whatsapp.com/XXXXXXXXXXXXXXXXXXXXXX"
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono
                                      focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <p class="text-xs text-[var(--t-muted)] mt-1">
                            Tautan undangan grup/komunitas WhatsApp resmi. Kosongkan bila belum ada —
                            kartu Komunitas di halaman Bantuan member otomatis disembunyikan.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Card 2: Operational Hours & Active Days -->
            <div class="t-card p-6">
                <h4 class="text-sm font-semibold text-[var(--t-text)] mb-4 flex items-center gap-2">
                    <i class="fas fa-clock text-indigo-500"></i> Jam Operasional Penarikan
                </h4>

                <div class="mb-4">
                    <label class="t-label text-sm mb-2 block">Hari Aktif (WIB)</label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <?php foreach ($day_labels as $dval => $dlabel): ?>
                        <label class="flex items-center gap-2 text-sm text-[var(--t-text-2)] cursor-pointer select-none">
                            <input type="checkbox"
                                   name="wd_operational_days[]"
                                   value="<?= $dval ?>"
                                   class="rounded border-slate-300"
                                   <?= in_array((string) $dval, $active_days, true) ? 'checked' : '' ?>>
                            <?= $dlabel ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="wd_open_time" class="t-label text-sm mb-1.5 block">Jam Buka</label>
                        <input type="time" id="wd_open_time" name="wd_open_time"
                               value="<?= htmlspecialchars($state_val($fs, 'wd_open_time', $open_time)) ?>" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm">
                    </div>
                    <div>
                        <label for="wd_close_time" class="t-label text-sm mb-1.5 block">Jam Tutup</label>
                        <input type="time" id="wd_close_time" name="wd_close_time"
                               value="<?= htmlspecialchars($state_val($fs, 'wd_close_time', $close_time)) ?>" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm">
                    </div>
                </div>
                <p class="text-xs text-[var(--t-muted)] mt-2">Gerbang berlaku saat <strong>pengajuan</strong> penarikan (hari &amp; jam WIB).</p>
            </div>
        </div>

        <!-- ================= COLUMN KANAN ================= -->
        <div class="space-y-6">

            <!-- Card 3: Withdrawal Fee & Dynamic Tier -->
            <div class="t-card p-6">
                <h4 class="text-sm font-semibold text-[var(--t-text)] mb-4 flex items-center gap-2">
                    <i class="fas fa-percentage text-orange-500"></i> Biaya Penarikan
                </h4>

                <?php if (!empty($financial_errors) || !empty($auto_notices)): ?>
                <div class="mb-4 space-y-2">
                    <?php if (!empty($auto_notices)): ?>
                    <div class="px-3 py-2 rounded-lg text-xs bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                        <i class="fas fa-wand-magic-sparkles mr-1"></i>
                        Penyesuaian otomatis: <?= htmlspecialchars(implode(' ', $auto_notices)) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($financial_errors)): ?>
                    <div class="px-3 py-2 rounded-lg text-xs bg-red-500/10 text-red-600 dark:text-red-400">
                        <p class="font-medium mb-1">Periksa kembali:</p>
                        <ul class="list-disc list-inside space-y-0.5">
                            <?php foreach ($financial_errors as $message): ?>
                            <li><?= htmlspecialchars($message) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-3 gap-4 mb-4">
                    <div>
                        <label for="wd_fixed_fee" class="t-label text-sm mb-1.5 block">Biaya Tetap (IDR)</label>
                        <input type="number" id="wd_fixed_fee" name="wd_fixed_fee"
                               value="<?= htmlspecialchars($state_val($fs, 'wd_fixed_fee', (int) $fixed_fee)) ?>"
                               min="0" max="100000" step="1" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono">
                    </div>
                    <div>
                        <label for="wd_min_amount" class="t-label text-sm mb-1.5 block">Minimal (IDR)</label>
                        <input type="number" id="wd_min_amount" name="wd_min_amount"
                               value="<?= htmlspecialchars($min_display) ?>"
                               min="1" step="1" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono">
                    </div>
                    <div>
                        <label for="wd_max_amount" class="t-label text-sm mb-1.5 block">Maksimal (IDR)</label>
                        <input type="number" id="wd_max_amount" name="wd_max_amount"
                               value="<?= htmlspecialchars($max_display) ?>"
                               min="1" step="1" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono">
                    </div>
                </div>

                <!-- plan/110: editor tier.
                     Transport = array (wd_tier_min[]/max[]/pct[]) — bukan lagi
                     satu hidden JSON, agar baris yang belum valid tetap terkirim
                     dan bisa direpopulasi. Endpoint turunan (Min baris 1 & Maks
                     baris terakhir) tidak diketik manual: Min baris 1 readonly
                     mengikuti "Minimal (IDR)", Maks baris terakhir diperpanjang
                     otomatis oleh JS (dan server menormalkan sebagai otoritas). -->
                <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
                    <label class="t-label text-sm block">Tier Biaya (% dari nominal + biaya tetap)</label>
                    <div class="flex items-center gap-3">
                        <button type="button" id="tierFix"
                                class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                            <i class="fas fa-wand-magic-sparkles mr-1"></i>Rapikan Tier
                        </button>
                        <button type="button" id="tierAutoMax"
                                class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                            <i class="fas fa-arrow-up-right-dots mr-1"></i>Sesuaikan Batas Atas
                        </button>
                    </div>
                </div>
                <p id="tierDerivedHint" class="text-[11px] text-[var(--t-muted)] mb-2"></p>

                <div class="space-y-2 mb-2" id="tierRows">
                    <?php foreach ($tier_rows as $i => $tier): ?>
                    <?php
                        // Bentuk baris seragam: ['min','max','pct'] — dari state
                        // form (mentah, apa yang diketik admin) atau dari config.
                        $row_min = is_array($tier) ? (isset($tier['min']) ? $tier['min'] : '') : (isset($tier[0]) ? $tier[0] : '');
                        $row_max = is_array($tier) ? (isset($tier['max']) ? $tier['max'] : '') : (isset($tier[1]) ? $tier[1] : '');
                        $row_pct = is_array($tier)
                            ? (isset($tier['pct']) ? $tier['pct'] : (isset($tier[2]) ? withdrawal_fee_tier_bps_to_pct($tier[2]) : ''))
                            : '';
                    ?>
                    <!-- Tier row: grid responsif (label di atas input) — M7 (plan/70) fix overflow -->
                    <div class="tier-row rounded-lg border border-slate-200 dark:border-slate-700 p-3
                                grid grid-cols-2 sm:grid-cols-12 gap-2 sm:gap-3 items-end"
                         data-row="<?= (int) $i + 1 ?>">
                        <div class="col-span-1 sm:col-span-4 min-w-0">
                            <label class="tier-min-label block text-[11px] text-[var(--t-muted)] mb-1">
                                Min (IDR)<?= $i === 0 ? ' — = Minimal' : '' ?>
                            </label>
                            <input type="number" name="wd_tier_min[]"
                                   class="tier-min t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono
                                          <?= $i === 0 ? 'bg-slate-100 dark:bg-slate-800 cursor-not-allowed' : '' ?>"
                                   min="0" step="1" value="<?= htmlspecialchars((string) $row_min) ?>"
                                   <?= $i === 0 ? 'readonly aria-readonly="true" title="Mengikuti nilai Minimal (IDR)"' : '' ?>>
                        </div>
                        <div class="col-span-1 sm:col-span-4 min-w-0">
                            <label class="block text-[11px] text-[var(--t-muted)] mb-1">Maks (IDR)</label>
                            <input type="number" name="wd_tier_max[]"
                                   class="tier-max t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono"
                                   min="0" step="1" value="<?= htmlspecialchars((string) $row_max) ?>">
                        </div>
                        <div class="col-span-1 sm:col-span-3 min-w-0">
                            <label class="block text-[11px] text-[var(--t-muted)] mb-1">Persen (%)</label>
                            <input type="number" name="wd_tier_pct[]"
                                   class="tier-pct t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono"
                                   min="0" max="100" step="0.01"
                                   value="<?= htmlspecialchars((string) $row_pct) ?>">
                        </div>
                        <div class="col-span-2 sm:col-span-1 flex justify-end items-end">
                            <button type="button" class="tier-del w-8 h-8 rounded-lg text-xs text-red-500 hover:bg-red-500/10 shrink-0"
                                    title="Hapus baris">&times;</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="tierAdd"
                        class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                    <i class="fas fa-plus mr-1"></i>Tambah baris tier
                </button>
                <p id="tierStatus" class="text-xs text-[var(--t-muted)] mt-2"></p>
                <p class="text-xs text-[var(--t-muted)] mt-1">
                    Rentang half-open [min, max): nominal batas masuk ke tier lebih tinggi.
                    Simpan untuk validasi server (server menormalkan endpoint turunan dan melaporkannya).
                </p>
            </div>

            <!-- Card 4: Deposit Fee -->
            <div class="t-card p-6">
                <h4 class="text-sm font-semibold text-[var(--t-text)] mb-4 flex items-center gap-2">
                    <i class="fas fa-wallet text-emerald-500"></i> Biaya Deposit (Top Up)
                </h4>

                <div class="flex items-center justify-between mb-4">
                    <label for="deposit_fee_enabled" class="text-sm text-[var(--t-text-2)] cursor-pointer select-none">
                        Aktifkan biaya deposit
                    </label>
                    <input type="checkbox" id="deposit_fee_enabled" name="deposit_fee_enabled" value="1"
                           class="rounded border-slate-300" <?= $dep_enabled ? 'checked' : '' ?>>
                </div>
                <p class="text-xs text-[var(--t-muted)] -mt-2 mb-4">User membayar <strong>pokok + biaya</strong>; saldo wallet dikredit <strong>pokok saja</strong> (zero dilution).</p>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="deposit_fee_type" class="t-label text-sm mb-1.5 block">Tipe Biaya</label>
                        <select id="deposit_fee_type" name="deposit_fee_type"
                                class="t-input w-full px-3 py-2.5 rounded-lg text-sm">
                            <option value="flat" <?= $dep_type === 'flat' ? 'selected' : '' ?>>Flat (IDR tetap)</option>
                            <option value="percent" <?= $dep_type === 'percent' ? 'selected' : '' ?>>Persen (%)</option>
                        </select>
                    </div>
                    <div>
                        <label for="deposit_fee_value" class="t-label text-sm mb-1.5 block">
                            Nilai <span id="depValSuffix">(IDR)</span>
                        </label>
                        <input type="number" id="deposit_fee_value" name="deposit_fee_value"
                               value="<?= htmlspecialchars($dep_value) ?>"
                               min="0" step="any" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Card 5: Komisi Rebate 3-Tier (Plan 89) — full width di bawah grid -->
    <div class="t-card p-6 mt-6">
        <h4 class="text-sm font-semibold text-[var(--t-text)] mb-4 flex items-center gap-2">
            <i class="fas fa-network-wired text-cyan-500"></i> Komisi Rebate 3-Tier (Affiliate Purchase Rebate)
        </h4>

        <div class="flex items-center justify-between mb-4">
            <label for="rebate_enabled" class="text-sm text-[var(--t-text-2)] cursor-pointer select-none">
                Aktifkan komisi rebate 3-tier
            </label>
            <input type="checkbox" id="rebate_enabled" name="rebate_enabled" value="1"
                   class="rounded border-slate-300" <?= $rebate_enabled_state ? 'checked' : '' ?>>
        </div>
        <p class="text-xs text-[var(--t-muted)] -mt-2 mb-4">
            Komisi dibayarkan <strong>otomatis</strong> ke upline L1–L3 yang memiliki sewa aktif saat
            downline membeli paket GPU. Upline inaktif = jatah hangus (breakage platform, tanpa pass-up).
        </p>

        <?php $rebate_fields = [
            ['level' => 1, 'key' => 'rebate_l1_percent', 'value' => (int) $rebate_l1_percent],
            ['level' => 2, 'key' => 'rebate_l2_percent', 'value' => (int) $rebate_l2_percent],
            ['level' => 3, 'key' => 'rebate_l3_percent', 'value' => (int) $rebate_l3_percent],
        ]; ?>
        <div class="grid grid-cols-3 gap-4">
            <?php foreach ($rebate_fields as $rf): ?>
            <div>
                <label for="<?= $rf['key'] ?>" class="t-label text-sm mb-1.5 block">Level <?= (int) $rf['level'] ?> (%)</label>
                <input type="number" id="<?= $rf['key'] ?>" name="<?= $rf['key'] ?>"
                       value="<?= htmlspecialchars($state_val($fs, $rf['key'], $rf['value'])) ?>" min="0" max="100" step="1" required
                       class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono">
            </div>
            <?php endforeach; ?>
        </div>
        <p class="text-xs text-[var(--t-muted)] mt-2">
            Nilai harus angka bulat 0–100. Contoh: downline membeli paket Rp 2.000.000 dengan L1 5% →
            komisi upline Rp 100.000; L2 3% → Rp 60.000; L3 1% → Rp 20.000.
        </p>
    </div>

    <!-- Submit (satu form → satu aksi simpan; M4 guard aktif via data-guard-submit) -->
    <div class="pt-6 mt-2 border-t border-[var(--t-border)] flex justify-end">
        <button type="submit"
                class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white text-sm font-medium
                       hover:bg-indigo-700 active:bg-indigo-800 transition-colors
                       flex items-center gap-2">
            <i class="fas fa-save text-xs"></i>
            Simpan Pengaturan
        </button>
    </div>

    <?= form_close() ?>

    <!-- =================================================================
         plan/102: PEMBAYARAN QRIS MANUAL
         Form TERPISAH dari form finansial/kontak di atas agar jalur POST
         yang sudah ada tidak tersentuh (risiko regresi minimum). Wajib
         enctype multipart untuk unggah gambar QRIS.
         ================================================================= -->
    <div class="mt-6 t-card p-6">
        <h4 class="text-sm font-semibold text-[var(--t-text)] mb-1 flex items-center gap-2">
            <i class="fas fa-qrcode text-indigo-500"></i> Pembayaran QRIS Manual
        </h4>
        <p class="text-xs text-[var(--t-muted)] mb-5">
            Konfigurasi deposit manual: member mentransfer nominal <span class="font-semibold">TEPAT</span>
            (pokok + 3 digit kode unik) ke QRIS di bawah, lalu menekan "Saya Sudah Transfer".
            Admin memverifikasi mutasi dan menyetujuinya di Command Center.
            <span class="font-semibold">Selama gambar QRIS kosong, pembuatan deposit ditolak (fail-closed).</span>
        </p>

        <?php if (!empty($qris_errors)): ?>
        <div class="mb-4 px-3 py-2 rounded-lg text-xs bg-red-500/10 text-red-600 dark:text-red-400">
            <p class="font-medium mb-1">Periksa kembali:</p>
            <ul class="list-disc list-inside space-y-0.5">
                <?php foreach ($qris_errors as $message): ?>
                <li><?= htmlspecialchars($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?= form_open_multipart('admin/settings/qris', ['id' => 'qrisForm', 'data-guard-submit' => '1']) ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

            <!-- Gambar QRIS -->
            <div>
                <label for="qris_image" class="t-label text-sm mb-1.5 block">Gambar QRIS</label>
                <div class="rounded-xl border border-[var(--t-border)] bg-[var(--t-surface-2)] p-3 flex items-center justify-center min-h-[180px]">
                    <?php if ($qris_image !== ''): ?>
                        <img src="<?= base_url('uploads/qris/' . $qris_image) ?>"
                             alt="QRIS merchant" class="max-h-[200px] w-auto object-contain rounded-lg bg-white p-1">
                    <?php else: ?>
                        <div class="text-center text-[var(--t-muted)] text-xs">
                            <i class="fas fa-qrcode text-3xl block mb-2 opacity-40"></i>
                            Belum ada gambar QRIS
                        </div>
                    <?php endif; ?>
                </div>
                <input type="file" id="qris_image" name="qris_image"
                       accept="image/png,image/jpeg"
                       class="t-input w-full mt-3 px-3 py-2 rounded-lg text-xs file:mr-2 file:px-2 file:py-1 file:rounded file:border-0 file:text-xs file:bg-indigo-600 file:text-white">
                <p class="text-xs text-[var(--t-muted)] mt-1">
                    Format PNG/JPG, maksimal 2 MB. Nama merchant &amp; instruksi juga dapat diubah kapan saja.
                </p>
            </div>

            <!-- Identitas + instruksi -->
            <div class="lg:col-span-2 space-y-4">
                <div>
                    <label for="qris_merchant_name" class="t-label text-sm mb-1.5 block">Nama Merchant QRIS</label>
                    <input type="text" id="qris_merchant_name" name="qris_merchant_name"
                           value="<?= htmlspecialchars($qris_merchant_display) ?>"
                           maxlength="100" required placeholder="Synapse"
                           class="t-input w-full px-3 py-2.5 rounded-lg text-sm">
                </div>

                <div>
                    <label for="qris_payment_instructions" class="t-label text-sm mb-1.5 block">Instruksi Pembayaran (ditampilkan ke member)</label>
                    <textarea id="qris_payment_instructions" name="qris_payment_instructions"
                              rows="4" maxlength="2000"
                              class="t-input w-full px-3 py-2.5 rounded-lg text-sm"><?= htmlspecialchars($qris_instructions) ?></textarea>                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label for="deposit_expiry_minutes" class="t-label text-sm mb-1.5 block">Masa Berlaku (menit)</label>
                        <input type="number" id="deposit_expiry_minutes" name="deposit_expiry_minutes"
                               value="<?= htmlspecialchars($dep_expiry_display) ?>" min="5" max="1440" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono">
                    </div>
                    <div>
                        <label for="deposit_min_amount" class="t-label text-sm mb-1.5 block">Min Deposit (Rp)</label>
                        <input type="number" id="deposit_min_amount" name="deposit_min_amount"
                               value="<?= htmlspecialchars($dep_min_display) ?>" min="1" step="1" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono">
                    </div>
                    <div>
                        <label for="deposit_max_amount" class="t-label text-sm mb-1.5 block">Max Deposit (Rp)</label>
                        <input type="number" id="deposit_max_amount" name="deposit_max_amount"
                               value="<?= htmlspecialchars($dep_max_display) ?>" min="1" step="1" required
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono">
                    </div>
                </div>

                <p class="text-xs text-[var(--t-muted)]">
                    Kode unik 3 digit (100–999) dialokasikan otomatis per nominal pokok dan dilepas kembali saat
                    deposit kedaluwarsa/ditolak. Setelah member menekan "Saya Sudah Transfer", deposit tidak
                    kedaluwarsa otomatis dan menunggu keputusan admin.
                </p>
            </div>
        </div>

        <div class="pt-6 mt-6 border-t border-[var(--t-border)] flex justify-end">
            <button type="submit"
                    class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white text-sm font-medium
                           hover:bg-indigo-700 active:bg-indigo-800 transition-colors
                           flex items-center gap-2">
                <i class="fas fa-qrcode text-xs"></i>
                Simpan Konfigurasi QRIS
            </button>
        </div>

        <?= form_close() ?>
    </div>
</div>

<script>
(function () {
    // =====================================================================
    //  plan/110 — EDITOR TIER PENARIKAN (transport ARRAY + endpoint turunan)
    //
    //  Perubahan inti dari versi lama (plan/70/71):
    //   1. Tidak ada lagi konstanta snapshot MIN_AMOUNT/MAX_AMOUNT: nilai
    //      dibaca LIVE dari #wd_min_amount / #wd_max_amount. Inilah akar
    //      deadlock lama (JS menuntut tier[0].min == nilai LAMA sementara
    //      server menuntut == nilai BARU).
    //   2. Transport = input array (wd_tier_min[]/max[]/pct[]) yang memang
    //      dikirim browser apa adanya → baris yang belum valid TIDAK PERNAH
    //      hilang, sehingga bisa direpopulasi server setelah gagal simpan.
    //   3. Endpoint TURUNAN tidak pernah memblokir: Min baris 1 mengikuti
    //      Minimal (readonly) dan Maks baris terakhir diperpanjang otomatis.
    //      Hanya kontradiksi nyata (baris kosong, max <= min, persen di luar
    //      0-100, celah/tumpang tindih) yang menghentikan submit — dan server
    //      tetap otoritas akhir (aturan identik ada di withdrawal_fee_helper).
    // =====================================================================
    var rowsEl       = document.getElementById('tierRows');
    var statusEl     = document.getElementById('tierStatus');
    var hintEl       = document.getElementById('tierDerivedHint');
    var fixEl        = document.getElementById('tierFix');
    var autoMaxEl    = document.getElementById('tierAutoMax');
    var addEl        = document.getElementById('tierAdd');
    var minEl        = document.getElementById('wd_min_amount');
    var maxEl        = document.getElementById('wd_max_amount');
    var formEl       = document.getElementById('settingsForm');

    // Admin pernah mengedit "Maks" baris terakhir secara manual → jangan
    // ditimpa; tampilkan hint + tombol "Sesuaikan Batas Atas".
    var lastMaxTouched = false;

    function rowList() {
        return Array.prototype.slice.call(rowsEl.querySelectorAll('.tier-row'));
    }
    function rf(row) {
        return {
            min:   row.querySelector('.tier-min'),
            max:   row.querySelector('.tier-max'),
            pct:   row.querySelector('.tier-pct'),
            label: row.querySelector('.tier-min-label')
        };
    }
    function valInt(el) {
        if (!el) { return NaN; }
        var s = String(el.value).replace(/[^0-9]/g, '');
        return (s === '') ? NaN : parseInt(s, 10);
    }
    function valPct(el) {
        if (!el) { return NaN; }
        var s = String(el.value).replace(',', '.');
        if (s === '' || !/^[0-9]+(\.[0-9]+)?$/.test(s)) { return NaN; }
        var v = parseFloat(s);
        return (v < 0 || v > 100) ? NaN : v;
    }
    function idr(n) {
        return 'Rp ' + Number(n).toLocaleString('id-ID');
    }
    function liveMin() { return valInt(minEl); }
    function liveMax() { return valInt(maxEl); }

    function setStatus(html, kind) {
        statusEl.innerHTML = html;
        statusEl.className = (kind === 'ok')
            ? 'text-xs text-emerald-600 dark:text-emerald-400 mt-2'
            : (kind === 'warn'
                ? 'text-xs text-amber-600 dark:text-amber-400 mt-2'
                : 'text-xs text-red-500 mt-2');
    }
    function markRow(root, bad) {
        if (!root) { return; }
        root.classList.toggle('ring-2', bad);
        root.classList.toggle('ring-red-500', bad);
    }

    // ── Endpoint TURUNAN (derive, bukan assert) ──────────────────────────
    function syncDerived() {
        var rs = rowList();
        if (rs.length === 0) { return; }

        rs.forEach(function (row, i) {
            var f = rf(row);
            var isFirst = (i === 0);
            f.min.readOnly = isFirst;
            if (isFirst) {
                f.min.setAttribute('aria-readonly', 'true');
                f.min.setAttribute('title', 'Mengikuti nilai Minimal (IDR)');
            } else {
                f.min.removeAttribute('aria-readonly');
                f.min.removeAttribute('title');
            }
            f.min.classList.toggle('bg-slate-100', isFirst);
            f.min.classList.toggle('dark:bg-slate-800', isFirst);
            f.min.classList.toggle('cursor-not-allowed', isFirst);
            if (f.label) {
                f.label.textContent = isFirst ? 'Min (IDR) — = Minimal' : 'Min (IDR)';
            }
        });

        // Min baris 1 = Minimal Penarikan (satu sumber, tidak diketik dua kali).
        var mn = liveMin();
        rf(rs[0]).min.value = isNaN(mn) ? '' : String(mn);

        // Maks baris terakhir > Maksimal Penarikan — hanya bila belum disentuh.
        var lastF = rf(rs[rs.length - 1]);
        var mx = liveMax();
        if (!isNaN(mx) && !lastMaxTouched) {
            var cur = valInt(lastF.max);
            if (isNaN(cur) || cur <= mx) {
                var rmin = valInt(lastF.min);
                lastF.max.value = String(Math.max(mx + 1, isNaN(rmin) ? 0 : rmin + 1));
            }
        }
    }

    // ── Validasi (paritas aturan helper withdrawal_fee_helper.php) ───────
    function computeTiers() {
        var rs = rowList();
        if (rs.length === 0) {
            return { ok: false, tiers: [], warnings: [],
                     errors: [{ row: 0, msg: 'Minimal satu baris tier biaya.' }] };
        }

        var parsed = [];
        var errors = [];
        rs.forEach(function (row, i) {
            var f = rf(row);
            markRow(row, false);
            var mn = valInt(f.min);
            var mx = valInt(f.max);
            var pc = valPct(f.pct);

            if (isNaN(mn) || isNaN(mx) || isNaN(pc)) {
                errors.push({ row: i + 1, msg: 'Baris ' + (i + 1) + ': min, maks, dan persen wajib angka (persen 0–100).' });
                markRow(row, true);
                return;
            }
            if (mx <= mn) {
                errors.push({ row: i + 1, msg: 'Baris ' + (i + 1) + ': maksimal harus lebih besar dari minimal.' });
                markRow(row, true);
                return;
            }
            parsed.push({ min: mn, max: mx, bps: Math.round(pc * 100), root: row });
        });

        if (errors.length) {
            return { ok: false, tiers: [], warnings: [], errors: errors };
        }

        for (var i = 1; i < parsed.length; i++) {
            if (parsed[i].min !== parsed[i - 1].max) {
                errors.push({ row: i + 1, msg: 'Baris ' + (i + 1) + ': minimal (' + idr(parsed[i].min)
                    + ') harus sama dengan maksimal baris sebelumnya (' + idr(parsed[i - 1].max)
                    + ') — tidak boleh ada celah/tumpang tindih. Klik "Rapikan Tier".' });
                markRow(parsed[i].root, true);
            }
        }
        if (errors.length) {
            return { ok: false, tiers: [], warnings: [], errors: errors };
        }

        var tiers = parsed.map(function (t) { return [t.min, t.max, t.bps]; });

        // Catatan (tidak memblokir): server menormalkan endpoint turunan.
        var warnings = [];
        var mx = liveMax();
        if (!isNaN(mx) && tiers[tiers.length - 1][1] <= mx) {
            warnings.push('Batas atas tier terakhir (' + idr(tiers[tiers.length - 1][1])
                + ') masih ≤ Maksimal (' + idr(mx) + ') — akan dinaikkan otomatis saat disimpan.');
        }

        return { ok: true, tiers: tiers, warnings: warnings, errors: [] };
    }

    function derivedHint() {
        var parts = [];
        var mn = liveMin();
        var mx = liveMax();
        parts.push('Baris pertama otomatis mengikuti Minimal Penarikan' + (isNaN(mn) ? '' : ' (' + idr(mn) + ')') + '.');
        if (!isNaN(mx)) {
            parts.push('Batas atas baris terakhir otomatis dibuat > ' + idr(mx)
                + (lastMaxTouched ? ' — klik "Sesuaikan Batas Atas" untuk merapikannya.' : '.'));
        }
        return parts.join(' ');
    }

    function refresh(prefix) {
        syncDerived();
        var res = computeTiers();
        if (hintEl) { hintEl.textContent = derivedHint(); }

        var head = prefix ? prefix + ' ' : '';
        if (!res.ok) {
            setStatus(head + res.errors.map(function (e) { return e.msg; }).join(' '), 'err');
            return res;
        }

        var tiers = res.tiers;
        var msg = head + tiers.length + ' tier · tercakup ' + idr(tiers[0][0]) + ' – '
                + idr(tiers[tiers.length - 1][1]) + ' ✓';
        if (res.warnings.length) {
            msg += ' ' + res.warnings.join(' ');
            setStatus(msg, 'warn');
        } else {
            setStatus(msg, 'ok');
        }
        return res;
    }

    // ── Baris baru: HARUS identik dengan markup render PHP di atas
    //    (grid responsif label-di-atas-input; M7 plan/71 fix overflow).
    function tierRowHTML(values) {
        var v = values || {};
        var mn = (v.min === undefined || v.min === null) ? '' : v.min;
        var mx = (v.max === undefined || v.max === null) ? '' : v.max;
        var pc = (v.pct === undefined || v.pct === null || isNaN(v.pct)) ? '' : v.pct;
        return '' +
            '<div class="col-span-1 sm:col-span-4 min-w-0">' +
                '<label class="tier-min-label block text-[11px] text-[var(--t-muted)] mb-1">Min (IDR)</label>' +
                '<input type="number" name="wd_tier_min[]" class="tier-min t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono" min="0" step="1" value="' + mn + '">' +
            '</div>' +
            '<div class="col-span-1 sm:col-span-4 min-w-0">' +
                '<label class="block text-[11px] text-[var(--t-muted)] mb-1">Maks (IDR)</label>' +
                '<input type="number" name="wd_tier_max[]" class="tier-max t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono" min="0" step="1" value="' + mx + '">' +
            '</div>' +
            '<div class="col-span-1 sm:col-span-3 min-w-0">' +
                '<label class="block text-[11px] text-[var(--t-muted)] mb-1">Persen (%)</label>' +
                '<input type="number" name="wd_tier_pct[]" class="tier-pct t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono" min="0" max="100" step="0.01" value="' + pc + '">' +
            '</div>' +
            '<div class="col-span-2 sm:col-span-1 flex justify-end items-end">' +
                '<button type="button" class="tier-del w-8 h-8 rounded-lg text-xs text-red-500 hover:bg-red-500/10 shrink-0" title="Hapus baris">&times;</button>' +
            '</div>';
    }

    function addRow(values) {
        var div = document.createElement('div');
        div.className = 'tier-row rounded-lg border border-slate-200 dark:border-slate-700 p-3 grid grid-cols-2 sm:grid-cols-12 gap-2 sm:gap-3 items-end';
        div.innerHTML = tierRowHTML(values);
        rowsEl.appendChild(div);
        wireRow(div);
        return div;
    }

    function wireRow(row) {
        row.querySelectorAll('input').forEach(function (el) {
            el.addEventListener('input', function () {
                var rs = rowList();
                if (row === rs[rs.length - 1] && el.classList.contains('tier-max')) {
                    lastMaxTouched = true;
                }
                refresh();
            });
        });
        var del = row.querySelector('.tier-del');
        if (del) {
            del.addEventListener('click', function () {
                if (rowList().length <= 1) { return; }
                row.remove();
                refresh();
            });
        }
    }

    // ── "Rapikan Tier": urutkan berdasar Min lalu sambung batas (stitch),
    //    sehingga celah/tumpang tindih hilang tanpa mengetik ulang.
    fixEl.addEventListener('click', function () {
        var rs = rowList();
        if (rs.length === 0) { return; }

        var items = rs.map(function (row) {
            return { root: row, f: rf(row), min: valInt(rf(row).min) };
        });
        items.sort(function (a, b) {
            var am = isNaN(a.min) ? Infinity : a.min;
            var bm = isNaN(b.min) ? Infinity : b.min;
            return am - bm;
        });

        var fixed = 0;
        var prev = null;
        items.forEach(function (it) {
            rowsEl.appendChild(it.root);
            if (prev !== null && !isNaN(prev) && !isNaN(it.min) && it.min !== prev) {
                it.f.min.value = String(prev);
                fixed++;
            }
            prev = valInt(it.f.max);
        });

        lastMaxTouched = false;
        refresh(fixed > 0 ? (fixed + ' baris dirapikan.') : 'Tidak ada celah/tumpang tindih.');
    });

    autoMaxEl.addEventListener('click', function () {
        lastMaxTouched = false;
        refresh('Batas atas disesuaikan.');
    });

    addEl.addEventListener('click', function () {
        // Baris baru langsung kontigu: Min = Maks baris sebelumnya,
        // persen disalin dari baris sebelumnya (Maks diisi otomatis).
        var rs = rowList();
        var last = rs.length ? rf(rs[rs.length - 1]) : null;
        var lastMax = last ? valInt(last.max) : NaN;
        var lastPct = last ? valPct(last.pct) : NaN;
        addRow({
            min: isNaN(lastMax) ? '' : lastMax,
            pct: isNaN(lastPct) ? '' : lastPct
        });
        lastMaxTouched = false;
        refresh();
    });

    // Perubahan Minimal/Maksimal langsung menyegarkan endpoint turunan.
    [minEl, maxEl].forEach(function (el) {
        if (!el) { return; }
        el.addEventListener('input', function () { refresh(); });
        el.addEventListener('change', function () { refresh(); });
    });

    // SUBMIT: blokir HANYA kontradiksi nyata (paritas helper server).
    // preventDefault + stopPropagation agar M4 guard (csrf_meta,
    // data-guard-submit) TIDAK menandai form "submitting" saat dibatalkan.
    formEl.addEventListener('submit', function (e) {
        var res = refresh();
        if (!res.ok) {
            e.preventDefault();
            e.stopPropagation();
        }
    });

    rowList().forEach(wireRow);
    refresh();
})();
</script>
