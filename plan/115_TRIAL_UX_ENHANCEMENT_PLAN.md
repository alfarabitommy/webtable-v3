# PLAN 115 — TRIAL PRODUCT UX ENHANCEMENTS (SORTING PRIORITY + WELCOME POPUP)

**Status:** DISETUJUI — siap eksekusi langkah E0..E11 (belum ada kode yang ditulis pada fase perencanaan ini)
**Tanggal:** 2026-09-23
**Ruang lingkup:** katalog marketplace (`Product_model`) + dashboard member (`Home` + `views/home/index.php`) + kamus dwibahasa
**Prasyarat:** plan/114 (produk trial `GPU Magang` + gerbang penarikan) sudah rilis & terverifikasi
**Keputusan owner:** `dec-0d9ee9b255b03969` — (a) urutan non-trial **tetap `p.id ASC` (existing apa adanya)**; (b) nominal bonus **diturunkan dari baris produk trial di DB**
**Non-goal yang mengikat:** nol perubahan di bawah `system/`, nol DDL, nol migrasi, nol route baru, nol perubahan panel admin.

---

## 0. Ringkasan eksekutif

Dua optimasi konversi di atas fondasi plan/114, **tanpa menyentuh uang, skema, atau otoritas gate**:

1. **Kartu trial selalu paling atas di `/marketplace`** — satu klausa `ORDER BY` (`p.is_trial DESC, p.id ASC`) di `Product_model::get_catalog_for_user()`. Urutan produk non-trial **tidak berubah sama sekali** (tetap `p.id ASC`, keputusan owner).
2. **Welcome popup promo trial di `/home`** — bottom-sheet modal yang:
   - **digate server** (HTML hanya dirender bila `lifetime_rentals == 0` **dan** produk trial aktif berharga 0 benar-benar ada di DB → fail-closed),
   - **digate klien** (`localStorage['trial_popup_dismissed']`) agar tidak spam,
   - menampilkan nominal **hasil hitung server** dari baris produk trial (tanpa nominal apa pun di kamus — L6/P3),
   - menyatakan **terbuka** bahwa penarikan nanti tetap mensyaratkan 1 produk berbayar (gerbang plan/114) + CTA `Klaim Sekarang` → `/marketplace`.

Dampak kode: **1 method model baru** (`get_active_trial_product()`), **1 klausa ORDER BY**, **1 view var baru**, **1 blok modal + 1 IIFE vanilla JS**, **6 kunci kamus** (627 → **633/633**). Nol berkas pada `database.sql`/`database_seed.sql`/`scripts/`/`routes.php`/admin.

---

## 1. Fakta repositori yang diverifikasi (dasar desain)

| # | Fakta | Bukti |
|---|---|---|
| F1 | Katalog marketplace memakai **`WHERE p.is_active = 1 ORDER BY p.id ASC`** — urutannya **id**, bukan harga | `Product_model.php:55-60` |
| F2 | Lineup kanonik id 1–8 punya harga **menaik** (150.000 → 10.000.000) → pada instalasi bersih `id ASC` ≡ `price ASC`; pada DB live (produk 5–12) **tidak** ekuivalen | `database.sql:479-486`; plan/114 §2.3 |
| F3 | **Hanya satu pemanggil** katalog: `Marketplace::index()` | `grep -rn get_catalog_for_user application/` → `Marketplace.php:17` + definisi |
| F4 | `lifetime_rentals` = **derivasi** (tanpa kolom baru) `COUNT(*) status IN ('active','completed')`; sudah dipanggil di dashboard | `Rental_model.php:596-611`; `Home.php:25-26` |
| F5 | Preseden modal di view yang sama: `#inactiveWarnModal` — **default tampil tanpa class `hidden`**, ditutup via `classList.add('hidden')` + fungsi global `closeInactiveWarn()` | `views/home/index.php:772-808` |
| F6 | Gate modal plan/89 = `lifetime > 0 && active === 0`; gate promo plan/115 = `lifetime === 0` → **kedua modal mustahil tampil bersamaan** | `Home.php:41` |
| F7 | Guideline z-index: modal `z-[60]`, backdrop `z-[59]`, bottom nav `z-50`, header `z-40`. **Deviasi pra-ada:** `#inactiveWarnModal` memakai `z-[70]` (di luar tabel) — **tidak diubah** plan ini | `docs/4_UI_UX_GUIDELINES.md:34-44`; `views/home/index.php:776` |
| F8 | Token tema siap pakai: `u-modal`, `u-modal-backdrop`, `u-btn-cyber`, `u-btn-ghost`, `u-text`, `u-text-2`, `u-muted` | `templates/header.php:41-252` |
| F9 | Pola sah: placeholder di kamus + `sprintf` di view | `app_lang.php:86` (`home_checkin_day = 'Hari ke-%d'`) → `home/index.php:313` |
| F10 | Gate **P3** menolak nilai kamus berpola `Rp` + digit → nominal **wajib** lewat placeholder/PHP | `scripts/audit_i18n_parity.php:78-79` |
| F11 | **P6** melaporkan `<`/`>` di nilai kamus sebagai **info** (bukan gagal) sekaligus memperingatkan pemakaian di atribut → nilai plan/115 ditulis **tanpa markup** | `scripts/audit_i18n_parity.php:157-172` |
| F12 | `audit_i18n_hardcoded.php` **melewati seluruh baris yang memuat `lang(...)`** dan token identifier non-kamus → identifier JS (`trialPromoModal`, `trial_popup_dismissed`) bukan temuan; sebaliknya lexicon ID memuat `sewa/saldo/penarikan/hari/anda` → **semua prosa wajib lewat kamus** | `scripts/audit_i18n_hardcoded.php:323, 330, 340`; `:71-83` |
| F13 | Kamus **627 key per idiom**, paritas 1:1, kedua gate hijau (baseline plan/114 §6.1) | `grep -c "^\$lang\["` → 627/627 |
| F14 | `views/home/index.php` = 808 baris; wrapper halaman `<div class="p-4 space-y-6">` ditutup di `:770`; blok modal plan/89 berada **di luar** wrapper → lokasi kanonik modal baru = tepat sebelum `:772` | `views/home/index.php:1, 770, 772-808` |
| F15 | `base_url('marketplace')` = pola CTA yang sudah dipakai di dashboard | `views/home/index.php:766, 789` |
| F16 | **Tidak ada test suite**; verifikasi = `php -l` + 2 gate CLI i18n + HTTP/curl + harness `/tmp` | AGENTS.md §Commands |
| F17 | Invarian trial (harga 0, kuota 1, maksimal satu baris) **sudah dijaga validasi admin** plan/114 → pembaca baru boleh mengandalkannya, tetap dengan fallback defensif | `plan/114_..._SUMMARY.md` §5 |

---

## 2. Keputusan desain (D1–D8)

| # | Keputusan | Alasan / trade-off |
|---|---|---|
| **D1** | `ORDER BY p.is_trial DESC, p.id ASC` — **hanya menambahkan** kunci pertama | Keputusan owner: non-trial **tetap `p.id ASC`**. Diff satu baris, nol perubahan urutan produk berbayar. Karena invarian "tepat satu trial" (plan/114), `is_trial DESC` bersifat deterministik & stabil. |
| **D2** | Nominal **di-server**, sumber = baris produk trial di DB (keputusan owner) via method read-only baru `Product_model::get_active_trial_product()`; predikat **fail-closed**: `is_trial = 1 AND is_active = 1 AND price = 0` | Copy tetap jujur walau admin mengubah `daily_rate`/`duration_days`; bila admin mematikan/menghargai produk trial → promo **hilang sendiri**, bukan berbohong. Melanggar L6/P3 bila nominal ditulis di kamus (F10). |
| **D3** | Modal dirender dengan class **`hidden` sejak awal**; JS membukanya hanya bila flag absen | Tanpa JS modal **tidak pernah tampil** → tidak ada CTA mati/terjebak (fail-open untuk fitur, bukan dead-end). Berbeda dari plan/89 (default tampil) — disengaja, alasannya dicatat di sini. |
| **D4** | **Semua** jalur tutup (✕, "Nanti Saja", klik backdrop, `Esc`) menulis flag; klik CTA **juga** menulis flag (tanpa `preventDefault`) | Interaksi = sudah dilihat → anti-spam. Aman karena gerbang server pasti menutup popup begitu trial diklaim (`lifetime_rentals` menjadi 1); risiko residu hanya bila user mengklik CTA lalu batal (dianggap wajar). |
| **D5** | Nama storage **literal** `trial_popup_dismissed`, nilai `'1'` | Sesuai redaksi requirement; **konsekuensi sadar**: flag bersifat per-perangkat, bukan per-user → pada perangkat bersama (umum di target pasar) user berikutnya tidak melihat promo. Mitigasi 1 baris dicatat sebagai **O1** dan menunggu keputusan owner. |
| **D6** | Tanpa CSS/animasi baru; hanya token tema existing (F8) dan `z-[60]` sesuai guideline (F7) | Nol kewajiban `prefers-reduced-motion` (preseden plan/105), nol risiko regresi tema terang/gelap, nol `<style>` baru. |
| **D7** | Nol perubahan admin / DDL / route / migrasi | `is_trial` sudah ada sejak plan/114; tidak ada URL baru (modal = markup pada `/home`), jadi tidak perlu entri `$routes`. |
| **D8** | JS dibungkus **IIFE** + `addEventListener`, bukan `onclick` inline + fungsi global | Modal baru tidak boleh mempolusi `window` (plan/89 memakai fungsi global `closeInactiveWarn()` — pola lama, tidak diubah). |

---

## 3. Model Changes (persis, dua titik)

### 3.1 `Product_model::get_catalog_for_user()` — klausa `ORDER BY`

`application/models/Product_model.php:55-60`

```php
        $rows = $this->db->query(
            "SELECT p.*
               FROM gpu_products p
              WHERE p.is_active = 1
              ORDER BY p.is_trial DESC, p.id ASC"   // plan/115: kartu trial SELALU paling atas
        )->result_array();
```

- **Hanya baris `ORDER BY`** yang berubah (perintah lain utuh). Docblock `:41-48` disesuaikan: `... (result_array, order is_trial DESC lalu id ASC)`.
- **Konsekuensi yang dinyatakan terbuka:** kartu trial tetap di posisi pertama **juga saat kuotanya sudah habis** (state B *quota reached*) — itu memang perilaku yang diminta ("ALWAYS appear at the very top"), dan konsisten dengan kuota 1× plan/114.
- **Prasyarat kolom:** `gpu_products.is_trial` wajib sudah ada (E0). Deploy kode sebelum DDL → 500 di `/marketplace` (risiko R1, sudah tertutup karena migrasi 114 terpasang).

### 3.2 Method baru — `Product_model::get_active_trial_product()`

Diletakkan tepat setelah `get_catalog_for_user()` (sebelum penutup class):

```php
    /**
     * plan/115 — Produk trial AKTIF untuk promo dashboard (READ-ONLY, display only).
     * Fail-closed: mengembalikan NULL bila tidak ada baris `is_trial = 1` yang
     * aktif DAN berharga 0 — sehingga modal promo tidak pernah menjanjikan
     * "Gratis" untuk produk yang tidak gratis. Otoritas ekonomi tetap
     * Rental_model::checkout_rental (TX terkunci).
     *
     * @return array|null
     */
    public function get_active_trial_product() {
        $row = $this->db->query(
            "SELECT id, name, price, daily_rate, duration_days, max_per_user
               FROM gpu_products
              WHERE is_trial = 1
                AND is_active = 1
                AND price = 0
              ORDER BY id ASC
              LIMIT 1"
        )->row_array();

        return $row ?: null;
    }
```

`LIMIT 1` + `ORDER BY id ASC` → deterministik walau invarian "tepat satu trial" dilanggar manual (degradasi aman, tanpa error).

---

## 4. Controller Changes — `application/controllers/Home.php`

Sisipkan **setelah** `Home.php:27` (blok `$lifetime`/`$active`) dan tambahkan satu view var pada `$data` (`:37-49`):

```php
        // plan/115: promo produk trial untuk member yang BELUM PERNAH menyewa
        // (lifetime_rentals === 0). Gerbang server — HTML modal hanya ada bila
        // produk trial aktif berharga 0 benar-benar ada di DB (fail-closed).
        // Seluruh nominal DIHITUNG di server (L6/M8: integer murni, no float).
        $trial_promo = null;
        if ($lifetime === 0) {
            $this->load->model('Product_model');
            $trial = $this->Product_model->get_active_trial_product();
            if ($trial) {
                $days  = max(1, (int) $trial['duration_days']);
                $daily = (int) $trial['daily_rate'];
                $trial_promo = [
                    'id'    => (int) $trial['id'],
                    'days'  => $days,
                    'daily' => $daily,
                    'total' => $daily * $days,   // M8: aritmetika integer
                ];
            }
        }
```

lalu pada `$data`:

```php
            // plan/115: null → view TIDAK merender modal sama sekali.
            'trial_promo'          => $trial_promo,
```

- Loader `Product_model` berada **di dalam cabang** (hanya saat `lifetime === 0`) — nol query tambahan untuk user yang sudah pernah menyewa.
- `$lifetime === 0` sudah berupa `(int)` dari F4 → perbandingan ketat aman.
- **Tidak** menyentuh `referral_locked`, `inactive_warning`, `is_promoter`, atau `checkin`.

---

## 5. View Changes — `application/views/home/index.php`

Disisipkan **tepat sebelum baris `:772`** (`<?php if (!empty($inactive_warning)): ?>`), yaitu **di luar** wrapper `p-4 space-y-6` — lokasi kanonik modal (F14). Nol perubahan pada `<style>` (F/D6) dan nol sentuhan pada blok plan/89/plan/112.

### 5.1 Struktur markup (Tailwind + token tema)

```php
<?php if (!empty($trial_promo)): ?>
<!-- ═══ TRIAL PROMO MODAL (plan/115) ═══
     Gerbang server: dirender HANYA bila lifetime_rentals == 0 DAN produk trial
     aktif berharga 0 ada di DB. Gerbang klien: localStorage 'trial_popup_dismissed'.
     Default `hidden` → tanpa JS modal tidak pernah tampil (tanpa CTA mati).
     z-[60] sesuai docs/4_UI_UX_GUIDELINES.md §2 (bottom nav z-50, header z-40).
     Nominal uang DIHITUNG & DIFORMAT di server (L6) — kamus tidak memuat angka. -->
<div id="trialPromoModal" class="fixed inset-0 z-[60] hidden"
     role="dialog" aria-modal="true" aria-labelledby="trialPromoTitle">
    <div id="trialPromoBackdrop" class="absolute inset-0 u-modal-backdrop backdrop-blur-sm"></div>

    <div class="absolute bottom-0 left-0 right-0 u-modal rounded-t-3xl px-5 pt-4 pb-6 max-h-[85vh] overflow-y-auto">
        <div class="w-10 h-1 bg-slate-300 dark:bg-slate-600 rounded-full mx-auto mb-4"></div>

        <!-- Tombol tutup: target sentuh >= 44px + aria-label i18n -->
        <button type="button" id="trialPromoClose" aria-label="<?= lang('home_trial_modal_close') ?>"
                class="absolute top-3 right-3 w-11 h-11 rounded-full flex items-center justify-center u-btn-ghost">
            <i class="fas fa-xmark text-base" aria-hidden="true"></i>
        </button>

        <div class="flex items-start gap-3 mb-3 pr-12">
            <div class="w-11 h-11 shrink-0 rounded-2xl bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center">
                <i class="fas fa-flask text-cyan-500 text-lg" aria-hidden="true"></i>
            </div>
            <div class="min-w-0">
                <!-- Chip identitas: REUSE kunci plan/114 -->
                <span class="inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-0.5 rounded-full bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 mb-1.5">
                    <i class="fas fa-flask text-[9px]" aria-hidden="true"></i> <?= lang('market_trial_badge') ?>
                </span>
                <h3 id="trialPromoTitle" class="text-sm font-extrabold u-text leading-snug"><?= lang('home_trial_modal_title') ?></h3>
            </div>
        </div>

        <p class="text-xs u-text-2 leading-relaxed"><?= lang('home_trial_modal_subtitle') ?></p>

        <!-- Callout bonus: nominal dari server, 3 placeholder (L6/P3) -->
        <div class="mt-3 rounded-xl p-3 bg-emerald-500/10 border border-emerald-500/30">
            <p class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 leading-relaxed">
                <?= sprintf(
                        lang('home_trial_modal_potential'),
                        number_format((int) $trial_promo['total'], 0, ',', '.'),
                        number_format((int) $trial_promo['daily'], 0, ',', '.'),
                        (int) $trial_promo['days']
                    ) ?>
            </p>
            <span class="mt-2 inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-700 dark:text-emerald-400">
                <?= lang('market_trial_free') ?>
            </span>
        </div>

        <!-- Keterbukaan gerbang penarikan (plan/114) + nada anti-scam (plan/107) -->
        <p class="mt-3 text-[11px] u-muted leading-relaxed flex items-start gap-2">
            <i class="fas fa-circle-info mt-0.5" aria-hidden="true"></i>
            <span><?= lang('home_trial_modal_rule') ?></span>
        </p>

        <a id="trialPromoCta" href="<?= base_url('marketplace') ?>"
           class="w-full mt-4 h-12 u-btn-cyber rounded-xl flex items-center justify-center gap-2 text-sm font-bold">
            <?= lang('home_trial_modal_cta') ?> <i class="fas fa-arrow-right" aria-hidden="true"></i>
        </a>
        <button type="button" id="trialPromoLater"
                class="w-full mt-2 py-3 u-btn-ghost rounded-xl text-xs font-bold u-text-2 transition-colors active:scale-[0.98]">
            <?= lang('home_later') ?>
        </button>
    </div>
</div>
```

**Rasional tata letak & aksesibilitas**

| Aspek | Pilihan | Alasan |
|---|---|---|
| z-index | kontainer `z-[60]`, backdrop di dalam kontainer | Guideline §2 (F7); di atas bottom nav `z-50`. **Tidak** memakai `z-[70]` sisa deviasi plan/89 — dan keduanya mustahil bersamaan (F6). |
| Default state | class **`hidden`** | D3: no-JS → tak tampil; tak ada kontrol mati. |
| Semantik | `role="dialog"` + `aria-modal="true"` + `aria-labelledby` → `<h3 id="trialPromoTitle">` | Modal dikenal screen reader; judul terhubung. |
| Tombol tutup | ✕ `type="button"` `aria-label` i18n, `w-11 h-11` | Target sentuh ≥ 44px; label dibacakan; teks lewat kamus. |
| CTA | `<a>` `h-12` ke `base_url('marketplace')` | Target ≥ 44px; URL kanonik (F15); fokus awal saat dibuka. |
| Ikon | semua `aria-hidden="true"` | Dekoratif, tidak membisingkan screen reader. |
| Animasi/CSS baru | **nol** | D6; `prefers-reduced-motion` tidak relevan. |
| Privasi scroll | tidak mengunci scroll `body` | Paritas dengan modal existing (plan/89) — kandidat **O2**. |
| Toast plan/112 (`z-[60]`, `pointer-events-none`) | tidak berkonflik | Toast hanya muncul saat klaim absensi, dan modal sudah tertutup pada saat itu. |

### 5.2 JS Logic (vanilla, IIFE — ditempatkan tepat setelah markup modal, di dalam gate `if`)

```html
<script>
(function () {
    var modal = document.getElementById('trialPromoModal');
    if (!modal) return;                       // gerbang server gagal → tidak ada modal

    var FLAG = 'trial_popup_dismissed';
    var suppressed = false;
    try { suppressed = window.localStorage.getItem(FLAG) === '1'; } catch (e) { suppressed = false; }
    if (suppressed) { return; }               // gerbang klien: sudah pernah ditutup di perangkat ini

    var cta      = document.getElementById('trialPromoCta');
    var closeBtn = document.getElementById('trialPromoClose');
    var laterBtn = document.getElementById('trialPromoLater');
    var backdrop = document.getElementById('trialPromoBackdrop');

    function markDismissed() {
        // Mode privat / storage diblokir: tulis gagal → modal tetap tertutup untuk sesi ini.
        try { window.localStorage.setItem(FLAG, '1'); } catch (e) {}
    }
    function close() {
        modal.classList.add('hidden');
        markDismissed();
        document.removeEventListener('keydown', onKey);
    }
    function onKey(e) {
        if (e.key === 'Escape') { close(); return; }
        if (e.key !== 'Tab') { return; }
        var f = [closeBtn, cta, laterBtn].filter(Boolean);
        if (!f.length) { return; }
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    if (closeBtn) { closeBtn.addEventListener('click', close); }
    if (laterBtn) { laterBtn.addEventListener('click', close); }
    if (backdrop) { backdrop.addEventListener('click', close); }
    if (cta) { cta.addEventListener('click', markDismissed); }   // interaksi = jangan tampil lagi

    modal.classList.remove('hidden');
    if (cta) { cta.focus(); }
    document.addEventListener('keydown', onKey);
})();
</script>
<?php endif; ?>
```

**Catatan perilaku & higienitas**

1. **Urutan gerbang wajib**: baca `localStorage` → `return` bila ada **sebelum** `classList.remove('hidden')`. Invarian ini diperiksa di V5a.
2. `try/catch` pada **kedua** akses storage → mode privat tak pernah mematahkan tombol tutup.
3. **Nol string dinamis** → **nol penambahan** `window.SYNAPSE_I18N`; semua teks sudah server-rendered lewat `lang()`.
4. `Esc`, klik backdrop, ✕, dan "Nanti Saja" semuanya menutup; klik CTA menandai lalu membiarkan navigasi berjalan normal (D4).
5. Focus trap ringan 3 elemen (`Tab`/`Shift+Tab` berputar) — tanpa `onclick` inline, tanpa fungsi global (D8).
6. Semua identifier baru lolos gate hardcoded-string (F12): baris ber-`lang(...)` dilewati, dan `trialPromoModal`/`trial_popup_dismissed` bukan kata lexicon.

---

## 6. i18n Keys (6 kunci baru; paritas 627 → **633/633**)

Diletakkan sebagai blok berkomentar `// plan/115: modal promo produk trial (dashboard member baru).` **tepat setelah** blok plan/114 `market_trial_*` (baris `:153-156`) di **kedua** berkas — posisi baris kedua idiom identik, sehingga paritas struktural terjaga.

| # | Key | ID | EN |
|---|---|---|---|
| 1 | `home_trial_modal_title` | `Gratis Sewa GPU Magang` | `Free GPU Internship Rental` |
| 2 | `home_trial_modal_subtitle` | `Coba mesin ROI Synapse tanpa modal awal, khusus untuk member yang belum pernah menyewa.` | `Try the Synapse ROI engine with zero upfront cost, exclusive to first-time renters.` |
| 3 | `home_trial_modal_potential` | `Potensi profit Rp %s (Rp %s/hari selama %d hari).` | `Potential profit of Rp %s (Rp %s/day for %d days).` |
| 4 | `home_trial_modal_rule` | `Catatan jujur: untuk menarik saldo, Anda nanti tetap harus menyewa minimal 1 produk berbayar (gerbang penarikan).` | `Full disclosure: to withdraw your balance you will still need to rent at least 1 paid product (withdrawal gate).` |
| 5 | `home_trial_modal_cta` | `Klaim Sekarang` | `Claim Now` |
| 6 | `home_trial_modal_close` | `Tutup promo` | `Close promo` |

**Kunci yang DI-REUSE (nol duplikasi):** `market_trial_badge` (`Uji Coba`/`Trial`) untuk chip identitas, `market_trial_free` (`Gratis`/`Free`) untuk chip harga, `home_later` (`Nanti Saja`/`Later`) untuk tombol sekunder.

**Kepatuhan gate (dinyatakan, bukan diasumsikan):**

| Gate | Pemeriksaan | Status rancangan |
|---|---|---|
| P3 | `Rp` + digit | **Lolos** — semua `Rp` diikuti `%` (F10); nominal hidup di PHP (`number_format`) |
| P5 | EN ≢ ID di luar allowlist | **Lolos** — keenam pasang berbeda; allowlist **tidak** ditambah |
| P3b | prosa ID bocor ke idiom EN | **Lolos** — EN bebas `silakan/anda/tidak/belum/sudah/gagal/berhasil/nominal/rekening/penarikan/kedaluwarsa/menunggu/wajib/jumlah` |
| P6 | newline/atribut | **Lolos tanpa warning** — nilai **tanpa** `<`/`>` (F11), dan tidak ada yang dipakai di atribut (kecuali `home_trial_modal_close` sebagai `aria-label` → tanpa markup) |
| P1 | paritas himpunan key | **633/633** di kedua idiom |
| R1–R7 | string hardcoded | **0 temuan** rancangan — semua prosa lewat `lang()`; baris ber-`lang(` dilewati (F12) |

**Kepatuhan nada pemasaran (plan/107):** hanya *potensi/klaim harian*, tidak ada *dijamin untung/profit tetap/auto cuan*; gerbang penarikan **dinyatakan terbuka** di baris rule; tidak ada janji pencairan instan.

---

## 7. Eksekusi (E0 → E11)

| # | Langkah | Berkas | Kriteria selesai |
|---|---|---|---|
| **E0** | **Pre-flight prasyarat DDL** — `php scripts/migrate_114_trial_product_wd_gate.php --verify` | — | exit 0 (kolom `is_trial` ada, tepat 1 baris trial). **Gate keras**: E1–E2 tidak boleh dirilis sebelum ini hijau (R1). |
| **E1** | Klausa `ORDER BY p.is_trial DESC, p.id ASC` + sinkronisasi docblock | `application/models/Product_model.php` | diff = 1 baris kode + komentar |
| **E2** | Method baru `get_active_trial_product()` (read-only, fail-closed) | `application/models/Product_model.php` | mengembalikan `null` tanpa baris trial layak |
| **E3** | Perhitungan `$trial_promo` (integer, kelas M8) + view var | `application/controllers/Home.php` | nol query tambahan saat `lifetime > 0` |
| **E4** | Blok modal server-gated + IIFE JS (default `hidden`) | `application/views/home/index.php` | markup hanya bila `$trial_promo` tidak kosong |
| **E5** | 6 kunci kamus di **kedua** idiom, satu blok berkomentar | `application/language/{indonesian,english}/app_lang.php` | 633 key per idiom, paritas 1:1 |
| **E6** | `php -l` **setiap** berkas PHP baru/berubah | 4 berkas PHP | semua "No syntax errors", `lint_fail=0` |
| **E7** | `php scripts/audit_i18n_parity.php` → `LULUS`; `php scripts/audit_i18n_hardcoded.php` → `0 temuan` | — | **keduanya exit 0** (wajib sebelum dinyatakan selesai) |
| **E8** | Verifikasi runtime V1–V8 (§8) | — | hasil terukur dicatat apa adanya |
| **E9** | Regresi fokus: checkout trial & berbayar, modal plan/89, dashboard `lifetime > 0`, tema terang/gelap, `id` ⇄ `en` | — | **nol regresi** (V7) |
| **E10** | Tulis `plan/115_TRIAL_UX_ENHANCEMENT_PLAN.md` + `plan/115_TRIAL_UX_ENHANCEMENT_SUMMARY.md` (bukti nyata) | `plan/` | perintah + hasil apa adanya; langkah yang tak bisa dijalankan dinyatakan eksplisit |
| **E11** | *(opsional)* sinkronisasi `docs/4_UI_UX_GUIDELINES.md` (modal onboarding) & `docs/3_ROADMAP.md` — **disarankan plan terpisah** | `docs/` | mengikuti kebiasaan repo (plan/111, 113, 114-E19) |

---

## 8. Matriks verifikasi runtime (bukti yang akan dilampirkan)

Harness: `php -S 127.0.0.1:8099` + env `DB_*` lokal (**tanpa** mengubah `application/config/**`) + login HTTP lewat CAPTCHA native, mengikuti pola plan/114 §6.3.

| # | Skenario | Cara | Ekspektasi terukur |
|---|---|---|---|
| V1 | **Trial di puncak katalog** | `GET /marketplace` (user `lifetime = 0`) | urutan kartu: baris pertama = produk trial; sisa kartu **ascending `id`** persis seperti sebelum plan/115 |
| V2 | Trial tetap puncak saat kuota habis | klaim trial lalu `GET /marketplace` | kartu trial **masih** indeks 0, state B (tombol non-aktif) |
| V3 | **Gerbang server modal = tampil** | `GET /home` (user `lifetime = 0`) | `#trialPromoModal` ada, class `hidden` ada, dan blok memuat `Rp 30.000`, `Rp 10.000`, `3 hari` (hasil `sprintf` + `number_format`) |
| V4 | **Gerbang server modal = absen** | `GET /home` dengan (a) user `lifetime > 0`, (b) produk trial `is_active = 0`, (c) trial tamper `price > 0` | (a)(b)(c) `grep -c 'trialPromoModal'` = **0** — fail-closed terbukti |
| V5a | **Gerbang klien** — invarian kode | ekstrak blok `<script>` dari HTML ter-render | pembacaan `localStorage.getItem('trial_popup_dismissed')` + `return` berada **sebelum** `classList.remove('hidden')` |
| V5b | Gerbang klien — perilaku nyata | harness `/tmp` (jsdom bila `npm install` tersedia) atau uji browser manual: muat → tutup → set flag → muat ulang | muat ke-1 modal tampil; setelah ditutup, flag `'1'` ada dan muat ulang **tidak** menampilkan modal. **Bila tooling menghalangi, dinyatakan eksplisit** (preseden plan/114 §6.3), tanpa klaim berlebih |
| V6 | Dwibahasa | `GET /home` dengan `site_lang = id` vs `en` | keenam string berganti sesuai kamus; **nol** prosa EN bocor di mode `id` |
| V7 | **Regresi nol** | (a) `POST /rentals/checkout` trial (`302`) & produk berbayar; (b) `GET /home` user `lifetime > 0` (modal plan/89 utuh); (c) `/marketplace` checkout modal | perilaku identik dengan sebelum plan/115 |
| V8 | Gate CLI | `php -l` (4 berkas) · `audit_i18n_parity.php` · `audit_i18n_hardcoded.php` | lint bersih · `LULUS` exit 0 · `0 temuan` exit 0 |

**Baseline yang harus dicatat sebelum perubahan:** hasil kedua gate i18n (627/627) — mengikuti V0 plan/114.

---

## 9. Risiko & mitigasi

| # | Risiko | Mitigasi |
|---|---|---|
| R1 | Kode `ORDER BY p.is_trial` dirilis sebelum DDL → 500 di `/marketplace` | **Urutan rilis wajib**: E0 `--verify` exit 0 lebih dulu (kolom sudah ada di live sejak plan/114) |
| R2 | Popup muncul berulang karena `localStorage` diblokir (mode privat) | `try/catch` pada `getItem`+`setItem`; degradasi hanya "muncul lagi", tombol tutup selalu berfungsi |
| R3 | Perangkat bersama: user B tidak melihat promo karena flag user A (D5) | Konsekuensi diterima sadar pada v1; mitigasi 1 baris (`trial_popup_dismissed_<user_id>`) dicatat sebagai **O1** menunggu keputusan owner |
| R4 | JS dinonaktifkan → promo tidak pernah tampil | Disengaja (D3): lebih baik tak tampil daripada CTA mati tanpa jalan tutup |
| R5 | Admin mematikan/menghargai produk trial → promo hilang | Disengaja (D2): fail-closed lebih benar daripada menampilkan "Gratis" yang bohong |
| R6 | Pelanggaran gate i18n (nominal di kamus / prosa ID di EN) | Rancangan key sudah lulus P3/P5/P3b/P6 secara analitis; **E7 wajib hijau** sebelum selesai |
| R7 | Tabrakan lapisan z-index dengan modal plan/89 | Mustahil bersamaan (F6, terverifikasi); modal baru memakai `z-[60]` sesuai guideline, bukan `z-[70]` |
| R8 | Klaim tanpa bukti (runtime tak bisa dijalankan) | E10 **wajib** mencatat perintah yang benar-benar dijalankan + hasil apa adanya, dan menyatakan eksplisit langkah yang tidak dapat dijalankan (V5b) |
| R9 | Query tambahan di dashboard | Method baru hanya dipanggil saat `lifetime === 0`; satu `SELECT … LIMIT 1`; nol N+1 |

---

## 10. Berkas yang akan tersentuh

**Baru (2):** `plan/115_TRIAL_UX_ENHANCEMENT_PLAN.md`, `plan/115_TRIAL_UX_ENHANCEMENT_SUMMARY.md`
**Model (1):** `application/models/Product_model.php` (1 klausa `ORDER BY` + 1 method baru)
**Controller (1):** `application/controllers/Home.php` (+1 blok, +1 view var)
**View (1):** `application/views/home/index.php` (+~95 baris sebelum `:772`)
**Kamus (2):** `application/language/indonesian/app_lang.php`, `application/language/english/app_lang.php` (+6 key masing-masing)

**Tidak disentuh (dinyatakan eksplisit):** `database.sql`, `database_seed.sql`, `scripts/**` (nol migrasi baru), `application/config/**`, `application/config/routes.php`, `views/marketplace/index.php`, `views/home/index.php` bagian plan/89 & plan/112, `controllers/Marketplace.php`, `Rental_model`, `Wallet_model`, `Admin*`, seluruh `system/**`.

---

## 11. Non-goals

1. Nol perubahan skema/seed/migrasi — `is_trial` sudah ada (plan/114).
2. Nol URL baru → nol entri `$routes`.
3. Nol perubahan panel admin (termasuk tidak ada toggle "tampilkan popup").
4. Tidak ada gerbang "umur akun": gerbangnya tetap **`lifetime_rentals === 0`**, konsisten dengan keputusan O3 plan/114.
5. Tidak mengubah perilaku kartu trial di marketplace selain posisinya (chip/label/harga plan/114 utuh).
6. Tidak menyentuh modal plan/89 (`z-[70]`) maupun widget absensi plan/112.

---

## 12. Definition of Done

| Kriteria | Target |
|---|---|
| Semua berkas PHP berubah lolos `php -l` | 4/4 bersih |
| Kedua gate i18n exit 0 | `LULUS` + `0 temuan` |
| Paritas kamus | **633/633**, EN ≢ ID di luar allowlist, P3/P3b/P6 bersih |
| Prasyarat plan/114 | `migrate_114_… --verify` exit 0 **sebelum** E1 dirilis |
| Flow diuji | V1–V8 dijalankan; V5b dinyatakan eksplisit bila tooling menghalangi |
| Diff akhir | hanya 6 berkas diniatkan + 2 berkas `plan/`; nol skrip scratch (semua di `/tmp`) |
| Ringkasan | `plan/115_…_SUMMARY.md` memuat matriks bukti perintah + hasil nyata |

---

## 13. Item opsional (di luar plan ini sampai owner memutuskan)

| # | Item | Dampak |
|---|---|---|
| **O1** | Flag per-user: `trial_popup_dismissed_<user_id>` (1 baris) | Menutup celah perangkat bersama (R3) |
| **O2** | Kunci scroll `body` (`overflow-hidden`) selama modal terbuka | UX lebih rapi; menyimpang dari modal existing |
| **O3** | Baris info "batas 1× per akun" di dalam modal (butuh 1 kunci kamus tambahan) | Transparansi kuota trial |

---

## 14. Catatan penutup

Semua klaim pada plan ini berasal dari berkas yang dibaca langsung (`application/models/Product_model.php`, `application/controllers/Home.php`, `application/views/home/index.php`, `application/views/marketplace/index.php`, kedua kamus `application/language/**`, kedua gate `scripts/audit_i18n_*.php`, `docs/4_UI_UX_GUIDELINES.md`, `database.sql`, `plan/114_..._SUMMARY.md`) — nomor baris dapat direproduksi. Dua keputusan yang secara material membentuk rancangan ini (urutan produk non-trial, sumber angka bonus) ditetapkan owner lewat `dec-0d9ee9b255b03969` dan tercermin di D1/D2.
