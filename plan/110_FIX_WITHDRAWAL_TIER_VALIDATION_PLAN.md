# Plan 110 — Perbaikan Validasi Tier & Minimal Penarikan (Admin Financial Settings)

> **Status:** BLUEPRINT (dokumen arsitektur SAJA). Belum ada satu pun perubahan
> kode aplikasi, skema DB, controller, model, route, view, helper, kamus bahasa,
> atau berkas `system/` yang dilakukan oleh dokumen ini. **Deliverable round ini
> = file ini saja** (`plan/110_FIX_WITHDRAWAL_TIER_VALIDATION_PLAN.md`).
> Eksekusi implementasi **MENUNGGU instruksi lanjutan terpisah** dari pemilik
> repositori.
>
> **Keputusan pemilik repositori (sudah dikonfirmasi, mengikat desain) —
> `dec-880272f38fa19298`:**
>
> | # | Keputusan |
> |---|---|
> | **D1 — Editor** | **Derive the endpoints.** `Min` pada baris tier pertama **diturunkan** dari field "Minimal (IDR)" (tidak dapat diketik bebas), dan `Maks` baris terakhir **diperpanjang otomatis** agar tetap di atas "Maksimal". Admin hanya mengisi batas *interior* + persen. Hanya kontradiksi nyata (baris tidak masuk akal / tidak kontigu) yang tetap menjadi error keras. |
> | **D2 — Server** | **Auto-adjust + report.** `Wallet_model::validate_financial_settings()` **menulis ulang** dua endpoint turunan (tier pertama `min` ← `wd_min_amount`; tier terakhir `max` ← `max(max, wd_max_amount + 1)`) dan mengembalikan `notices[]` yang ditampilkan di flash message **dan** dicatat di baris audit sebagai `auto_adjusted`. Semua pelanggaran lain (baris `max ≤ min`, gap/overlap, persen di luar 0–100, daftar tier kosong, `min ≥ max`) **tetap hard reject**. |
> | **D3 — Bahasa dokumen** | **Indonesia** (konsisten dengan `plan/1..109`). |
>
> **Ruang lingkup:** mengubah editor tier + jalur validasi/persistensi tier
> penarikan pada `/admin/settings` (Card 3) sehingga admin dapat menurunkan
> nominal minimal penarikan (mis. Rp 50.000) **tanpa** terjebak loop validasi.
>
> **Zero-impact:** nol DDL, nol migrasi, nol route baru, nol key kamus baru, nol
> perubahan copy member (L6/L1 terjaga), nol perubahan `system/`, nol perubahan
> pada jalur uang (`Wallet_model::_post()`, `wallet_ledger`,
> `calculate_withdrawal_fee()`).

---

## 1. Ringkasan & Success Criteria

### 1.1 Masalah yang diselesaikan

Admin membuka `/admin/settings` → Card **"Biaya Penarikan"**, lalu **menurunkan
"Minimal (IDR)" dari `100000` menjadi `50000`** (satu-satunya perubahan yang
diinginkan). Form tidak pernah bisa disimpan. Dua pesan bergantian muncul:

1. `Validasi gagal: Tier pertama harus dimulai dari nominal minimal penarikan (Rp 50.000).`
   → pesan **backend** (`Admin::settings()` → flashdata prefix "Validasi gagal: ",
   `Admin.php:450`).
2. `Tier belum valid: periksa min < max dan persen 0-100 pada setiap baris.`
   → pesan **frontend** (status text `#tierStatus`, `settings.php:431`) yang
   **memblokir submit** (`settings.php:535-540`), bukan sekadar informasi.

Karena tidak ada state yang dapat disubmit, admin kehilangan setiap usaha
editnya: setiap kegagalan → `redirect('admin/settings')` → halaman dirender ulang
dari **nilai DB** (bukan dari nilai yang baru diketik), sehingga seluruh
perubahan finansial di kartu itu hilang dan loop mulai dari nol.

### 1.2 Akar masalah (ringkas)

- **RC1 (deadlock).** View meng-*snapshot* `MIN_AMOUNT`/`MAX_AMOUNT` saat render
  (`settings.php:413-414`), lalu JS menuntut `tier[0].min === MIN_AMOUNT`
  (`:451-456`); sementara server menuntut `tier[0].min === (int) POST wd_min_amount`
  (`Wallet_model.php:302-304`). Setelah admin mengetik `50000`, dua assertion itu
  **saling bertentangan** → tidak ada payload yang diterima keduanya.
- **RC2 (tidak ada repopulasi).** Field `wd_min_amount`/`wd_max_amount`/tier
  dirender langsung dari `$cfg` (DB) tanpa `set_value()`, dan POST berakhir
  dengan `redirect()` → seluruh input finansial + kontak + rebate dibuang.
- **RC3 (state transisi diperlakukan fatal).** `serializeTiers()` dijalankan pada
  **setiap ketikan** (`:500-503`) dan men-`preventDefault()` submit
  (`:535-540`) saat ada state intermediate tidak koheren. Mengedit satu batas
  partisi kontigu **wajib** melewati state tidak koheren (mis. field dikosongkan
  dulu / satu batas diubah sebelum pasangannya) → mustahil, dan tanpa affordance
  "rapikan".
- **RC4 (data turunan dipaksa jadi input bebas).** `tiers[0].min` dan
  `tiers[n-1].max` **secara struktural turunan** dari `wd_min_amount`/`wd_max_amount`,
  tetapi dirender sebagai dua field independen yang harus "kebetulan" sama.

### 1.3 Solusi

Tiga lapis, mengikuti D1/D2:

| Lapis | Perubahan |
|---|---|
| **Transport** | Baris tier dikirim sebagai **array** (`wd_tier_min[]`, `wd_tier_max[]`, `wd_tier_pct[]`) menggantikan satu hidden JSON `wd_fee_tiers` (jalur JSON legacy tetap **diterima** agar halaman ter-cache/browser lama tidak rusak). |
| **Aturan** | Satu choke-point baru `application/helpers/withdrawal_fee_helper.php` (fungsi murni, pola plan/105): baris mentah → tier kontigu; **endpoint turunan dinormalkan** (`notices[]`), sisanya hard error yang menyebut **nomor baris + kedua nilai**. |
| **UI/UX** | Row 1 `Min` = **readonly derivasi**; `Maks` baris terakhir auto-extend (dengan hint + tombol "Sesuaikan" bila admin pernah mengeditnya manual); tombol **"Rapikan Tier"**; ringkasan cakupan live ("Rentang tercakup Rp X – Rp Y ✓"); error **inline per baris**; **repopulasi** seluruh form setelah gagal simpan. |

### 1.4 Success Criteria

| # | Kriteria | Bukti |
|---|---|---|
| S1 | Menurunkan `Minimal` ke `50000` + simpan → **`302` + flash sukses**, tanpa menyentuh baris tier manual | V5 §8 |
| S2 | `wd_fee_tiers` tersimpan = `[[50000,500000,1000],[500000,…],…,[10000000,50000001,300]]` (hanya endpoint pertama bergeser) | V5b §8 |
| S3 | Penyesuaian endpoint otomatis **tampil** ke admin (flash) **dan** tercatat di audit (`auto_adjusted`) | V5a/V6 §8 |
| S4 | Setiap nominal di `[wd_min_amount, wd_max_amount]` tercakup **tepat satu** tier (tidak ada gap → `calculate_withdrawal_fee()` tak pernah jatuh ke fallback tier terakhir) | V3-C9 §8 |
| S5 | Pelanggaran nyata (gap / `max ≤ min` / persen > 100 / tier kosong) tetap **ditolak**, dengan pesan yang menyebut nomor baris + nilai aktual | V3-C4/C5/C6/C7, V7 §8 |
| S6 | Setelah simpan gagal, **tidak ada input yang hilang** (min/max/hari/jam/tier/kontak/rebate) | V7b §8 |
| S7 | Fee tidak berubah untuk nilai di atas tier pertama: `500000 → 44.000`, `1.000.000 → 71.500`, `5.000.000 → 206.500` | V9 §8 |
| S8 | Nol DDL, nol route, nol key kamus, `system/` bersih; `php -l` bersih; **kedua gate i18n tetap exit 0** | V1/V2/V12 §8 |

---

## 2. Fakta Codebase Terverifikasi (pra-edit)

Semua baris berikut **dibaca langsung** dari working tree (HEAD `8cc8783`, plus
pekerjaan plan/109 yang belum di-commit):

| Lokasi | Fakta |
|---|---|
| `application/views/admin/settings.php:151-215` | Card 3 "Biaya Penarikan": grid 3 kolom `wd_fixed_fee` / `wd_min_amount` / `wd_max_amount` (`:157-176`) + editor tier `#tierRows` (`:179-206`) + `#tierAdd` (`:207-210`) + **hidden** `wd_fee_tiers` (`:211`) + `#tierStatus` (`:212-214`). |
| `:180-205` | Baris tier dirender dari `$tiers` (list `[min,max,bps]`) → 3 input `.tier-min/.tier-max/.tier-pct` (`min="0"`, `step="1"` / `step="0.01"`) + tombol `.tier-del`. Persen dirender `rtrim(rtrim(number_format($tier[2]/100, 2, '.', ''), '0'), '.')` (`:198`). |
| `:413-414` | `var MIN_AMOUNT = <?= (int) $min_amount ?>; var MAX_AMOUNT = <?= (int) $max_amount ?>;` → **konstanta snapshot saat render**, tidak pernah disinkronkan dengan input. |
| `:420-467` | `serializeTiers()`: (a) `:429-434` error bila `NaN` / `max ≤ min` / persen ∉ [0,100] → **pesan #2**; (b) `:435-440` error bila `min` baris ≠ `max` baris sebelumnya (kontiguitas); (c) `:445-450` tier kosong; (d) `:451-456` `tier[0].min !== MIN_AMOUNT` → **pesan #1 versi frontend**; (e) `:457-462` `tier[n-1].max ≤ MAX_AMOUNT`. Setiap kegagalan `hidden.value = ''`. |
| `:500-503` | Listener `input` **hanya** dipasang ke input di dalam `.tier-row` → `#wd_min_amount`/`#wd_max_amount` **tidak** memicu validasi/status apa pun. |
| `:535-540` | Handler submit: `if (!serializeTiers()) { e.preventDefault(); e.stopPropagation(); }` → state tier tidak valid **memblokir** submit (kontrak plan/70 §215 dipertahankan). |
| `application/controllers/Admin.php:375-537` | `settings()`: satu endpoint GET+POST untuk kontak + finansial + rebate (+ QRIS sebagai form terpisah). |
| `Admin.php:418-429` | `$raw` finansial diambil **mentah** dari POST; `wd_fee_tiers` diteruskan sebagai string. |
| `Admin.php:430-433` | `$v = $this->Wallet_model->validate_financial_settings($raw);` — `errors` digabung, `values` dipakai. |
| `Admin.php:448-453` | **All-or-nothing**: satu error → `set_flashdata('error', 'Validasi gagal: ' . implode(' ', $errors))` + `redirect('admin/settings')` → **POST dibuang, tidak ada repopulasi**. |
| `Admin.php:459-475` | Snapshot `before` per key + `_audit_ctx(null,'admin_update_settings',['keys','before','after'])`; detail audit bebas JSON (dibaca `_write_audit`, `Admin_model.php:22-34`). |
| `Admin.php:493-536` | GET merender **dari DB** (`$cfg = get_financial_config()`, `$contact = get_settings_map([...])`); hanya field kontak memakai `set_value(...)`, dan setelah redirect `$_POST` kosong → nilainya = DB lagi. |
| `application/models/Wallet_model.php:246-340` | `validate_financial_settings()` — aturan per field. Bounds `:284-292` (`min`/`max` int ≥ 1, `min < max`); tier `:294-309` (`_norm_tiers()` **strict**, lalu `:302-304` `first[0] !== $min` → **pesan #1 versi backend**, `:305-307` `last[1] <= $max`). |
| `Wallet_model.php:206-233` | `_norm_tiers()` — parser JSON **strict & total**: salah satu pelanggaran → `null`, tanpa detail baris. Dipakai juga di jalur baca (`:101`). |
| `Wallet_model.php:77-150`, khususnya `:106-123` | `_resolve_financial_config()` — **safety net baca**: bila bundle `tiers` + `min`/`max` tidak koheren, **kedua bound dikembalikan ke fallback berkas** (dan dicatat `log_message('error')`). Inilah alasan aturan koherensi tidak boleh sekadar dihapus. |
| `Wallet_model.php:456-476`, khususnya `:464` | `calculate_withdrawal_fee()` — `$bps = end($cfg['tiers'])[2]` sebagai **fallback bila nominal tidak masuk tier mana pun** = tarif termurah (3%). Gap tier ⇒ **biaya kurang potong secara senyap**. Aturan koherensi punya alasan finansial nyata. |
| `Wallet_model.php:179-200` | `_norm_int()` / `_norm_pct()` — DB-free, dipakai banyak field lain. |
| `Admin_model.php:1187-1194 / 1210-1220 / 1227-1238` | `get_setting()` / `get_settings_map()` / `update_system_settings($data,$audit)` (satu TX: N× `INSERT … ON DUPLICATE KEY UPDATE` + 1 baris audit). |
| `application/config/withdrawal_fees.php:32-45` | Fallback berkas: 6 tier half-open `[min,max)` (seed pertama `100000..500000` = 10% / `1000` bps), `min_amount 100000`, `max_amount 50000000`. Komentar `:29-31` menyebut "effective floor = min WD 100.000". |
| `database.sql:368-370`, `database_seed.sql:302-304` | Seed `system_settings`: `wd_fee_tiers` = JSON 6 tier, `wd_min_amount 100000`, `wd_max_amount 50000000`. **Tidak ada batas `min ≥ 100000`** di jalur simpan (`_norm_int(..., 1)`) → `50000` **diterima** model (catatan plan/56 §"min ≥ 100000" **tidak** diimplementasikan; kode menang, AGENTS.md). |
| `application/config/autoload.php:112` | Baris autoload helper (working tree sudah memuat `withdrawal_amount` dari plan/109) — titik sisip helper baru. |
| `scripts/migrate_105_wa_group_link.php:33-38` | Preseden pola: CLI `define('BASEPATH', …)` lalu `include` helper aplikasi → **satu sumber aturan** untuk aplikasi & verifier (dipakai untuk V3 §8). |
| `docs/1_PRD.md:124` | "Minimum Rp 100.000, Maksimum Rp 50.000.000 per penarikan" → **konflik dokumen** bila owner ingin floor operasional Rp 50.000 (lihat §12). |
| `plan/56_M1_OPERATIONAL_RULES_AND_DYNAMIC_FEES_PLAN.md:68-69` | Aturan lama: kontigu, `first.min == wd_min_amount`, `last.max > wd_max_amount`, **"any violation → reject the whole update"**. → **diamandemen** oleh plan/110 D2. |
| `plan/70_M7_SETTINGS_CONSOLIDATION_AND_UI_PLAN.md:215`, `plan/71_…:51` | Kontrak JS lama (`.tier-row/.tier-min/.tier-max/.tier-pct/.tier-del`, `#tierRows/#wd_fee_tiers/#tierStatus/#tierAdd`, `serializeTiers()`, grid responsif M7) → **sebagian diamandemen** (§6.4). |

---

## 3. Reproduksi & Bukti Deadlock

### 3.1 Matriks langkah (state awal = seed)

State DB: `wd_min_amount=100000`, `wd_max_amount=50000000`,
`wd_fee_tiers=[[100000,500000,1000],[500000,1000000,750],[1000000,2000000,650],[2000000,5000000,500],[5000000,10000000,400],[10000000,50000001,300]]`.
Saat render: `MIN_AMOUNT=100000`, `MAX_AMOUNT=50000000`.

| # | Aksi admin | Yang terjadi | Hasil |
|---|---|---|---|
| **K1** | Ubah `Minimal` → `50000` | Tidak ada listener di `#wd_min_amount` (`:500-503`); `#tierStatus` tetap hijau; `wd_fee_tiers` tetap berisi tier 1 = `100000` | status "hijau" (menyesatkan) |
| **K2** | Klik **Simpan** | JS lolos (`out[0][0] === MIN_AMOUNT` masih `100000`) → POST `wd_min_amount=50000`, tier lama | — |
| **K3** | Server | `min=50000` OK; `_norm_tiers()` OK; `first[0]=100000 !== 50000` → `Wallet_model.php:303` | **`Validasi gagal: Tier pertama harus dimulai dari nominal minimal penarikan (Rp 50.000).`** ← pesan #1 user |
| **K4** | Redirect + render ulang | `Minimal` kembali menampilkan `100000` (dibaca dari DB). Usaha K1 **hilang** (RC2) | loop |
| **K5** | Ubah `Min` **baris 1** → `50000` (agar cocok dengan niat) | `serializeTiers()`: `out[0][0]=50000 !== MIN_AMOUNT=100000` → `:451-456` → `hidden.value=''`, status merah | **`Tier pertama harus dimulai dari nominal minimal penarikan (Rp 100.000).`** (menyebut nilai **lama** → membingungkan) |
| **K6** | Klik **Simpan** | `serializeTiers()` → `false` → `e.preventDefault()` (`:535-540`) | **submit dibatalkan, tidak ada POST** → benar-benar buntu |

Saat admin mengetik/mengosongkan field untuk mengubah nilai (mis. menghapus
`100000` sebelum mengetik `50000`, atau mengetik `max` baris 1 = `500000` pada
`Min` baris 1 sehingga `max ≤ min`), `:429-434` menyala lebih dulu:

> `Tier belum valid: periksa min < max dan persen 0-100 pada setiap baris.`

← **pesan #2 user** (state transisi/baris belum lengkap dianggap fatal).
Menambah baris baru via **"Tambah baris tier"** (`addRow(null)` → 3 input kosong)
juga langsung memicu pesan ini begitu salah satu input disentuh.

### 3.2 Pembuktian kontradiksi (inti deadlock)

```
JS  (settings.php:451)        : tier[0].min === MIN_AMOUNT            // MIN_AMOUNT = 100000 (snapshot render)
PHP (Wallet_model.php:302)    : tier[0].min === (int) POST wd_min_amount   // = 50000 (niat admin)
```

Setelah K1 (`wd_min_amount` = 50000), kedua predikat **tidak dapat dipenuhi
bersama**: nilai `MIN_AMOUNT` adalah hasil pembacaan **sebelum** perubahan,
sedangkan nilai server adalah **sesudah** perubahan. Tidak ada urutan pengeditan
yang menyelamatkan, karena `MIN_AMOUNT` hanya berubah bila halaman di-render
ulang — dan render ulang selalu mengembalikan nilai DB (RC2). Predikat pasangan
`MAX_AMOUNT` (`:457-462`) punya cacat identik untuk `wd_max_amount`.

### 3.3 Mengapa ini benar-benar UX-fatality (bukan sekadar pesan menjengkelkan)

1. **Tidak ada payload yang valid** untuk skenario paling wajar (RC1).
2. **Tidak ada repopulasi** ⇒ setiap kegagalan mengulang dari nol (RC2).
3. **State transisi = fatal** ⇒ mengedit batas partisi kontigu mustahil
   dilakukan bertahap, dan tidak ada tombol perbaikan (RC3).
4. **Data turunan disamarkan sebagai input bebas** ⇒ admin dipaksa "mengetik
   ulang" nilai yang sebenarnya sudah dimilikinya (RC4).
5. **Pesan tidak menunjuk baris** ⇒ admin tidak tahu baris mana yang salah,
   sedangkan pesan JS bahkan **tidak** tampil sebagai error form (ia hanya
   `#tierStatus`) walau dialah yang sebenarnya memblokir.

---

## 4. Akar Masalah (konsolidasi)

| Kode | Akar masalah | Bukti | Perbaikan |
|---|---|---|---|
| **RC1** | JS memakai `MIN_AMOUNT`/`MAX_AMOUNT` snapshot, bukan nilai live input | `settings.php:413-414`, `:451-462` | P1 (hapus konstanta), P2 (`liveMin()/liveMax()`) |
| **RC2** | Tidak ada repopulasi setelah gagal simpan (`redirect`) | `Admin.php:448-453`, `:493-536` | P7 (`flashdata('settings_form_state')`) |
| **RC3** | Validasi per-ketikan memblokir submit; tidak ada "rapikan" | `settings.php:500-503`, `:535-540` | P3 (`computeTiers()` dengan hard-error + repair), P4 (tombol Rapikan) |
| **RC4** | Endpoint tier turunan dipaksa jadi input bebas & harus sama | `settings.php:187/192`, `Wallet_model.php:302-307` | P1 (row 1 `Min` readonly), P6 (normalizer menurunkan endpoint) |
| **RC5** | Pesan seragam & tanpa nomor baris; `_norm_tiers()` mengembalikan `null` tanpa detail | `Wallet_model.php:206-233`, `:297-298` | P6 (error per baris + `field_errors`) |
| **RC6** | Semua kartu all-or-nothing → pengaturan kontak/rebate ikut terblokir oleh tier | `Admin.php:448-453` | Tidak diubah (atomik by design); dampak hilang setelah P6 → tercatat §9 |
| **RC7** | `#wd_min_amount`/`#wd_max_amount` tidak terhubung ke validasi tier | `settings.php:500-503` | P2 (listener pada kedua input) |
| **RC8** | JSON transport: satu hidden field; bila invalid → **seluruh baris hilang** (tak bisa direpopulasi) | `settings.php:211`, `:430`, `:436` | P5 (array `wd_tier_min[]/max[]/pct[]`) |

---

## 5. Desain Solusi

### 5.1 Prinsip

> **Endpoint partisi tier = DATA TURUNAN, bukan data bebas.**
> Sistem berasal dari `wd_min_amount`/`wd_max_amount`; partisi tier berasal dari
> batas *interior* + persen yang diketik admin. Karena itu endpoint
> **diturunkan** (derive), bukan **diperiksa kesamaannya** (assert).

Konsekuensi (dan mengapa aturan koherensi tetap dipertahankan):
`calculate_withdrawal_fee()` (`Wallet_model.php:464`) memakai tarif tier
**terakhir** sebagai fallback bila nominal tidak masuk tier mana pun. Jaminan
"`[wd_min_amount, wd_max_amount]` tercakup penuh" karena itu **wajib** — tetapi
menjadi jaminan yang **tidak mungkin dilanggar admin** bila endpoint diturunkan,
alih-alih menjadi teka-teki tulis-ulang.

### 5.2 Aturan tier kanonik (final, pasca-plan/110)

1. Minimal **1 baris** tier.
2. Setiap baris: `0 < min < max`; `0 ≤ persen ≤ 100` (toleran 2 desimal) →
   `bps = round(persen × 100)` ∈ `[0, 10000]`.
3. Baris **kontigu penuh** & menaik: `rows[i].min === rows[i-1].max` untuk `i ≥ 1`
   (tidak ada gap/overlap). Pelanggaran = **hard error** yang menyebut
   **nomor baris + kedua nilai aktual**.
4. **Endpoint turunan (auto-normalisasi, bukan error):**
   `rows[0].min ← wd_min_amount` dan
   `rows[n-1].max ← max(rows[n-1].max, wd_max_amount + 1)` → masuk `notices[]`.
5. Turunan jaminan #3 + #4: `[wd_min_amount, wd_max_amount]` tercakup tepat satu
   tier ⇒ tidak ada jalur yang jatuh ke tarif fallback.
6. Kasus sisa yang **tetap** hard error (dan pesannya harus jelas):
   setelah `rows[0].min ← wd_min_amount`, ternyata `rows[0].max ≤ rows[0].min`
   (admin menaikkan minimal melewati batas atas baris pertama) →
   `"Tier baris 1: maksimal (Rp 500.000) harus lebih besar dari minimal (Rp 600.000). Naikkan maksimalnya atau hapus baris ini."`

### 5.3 Transport baru (array) + jalur legacy

| | Sebelum | Sesudah |
|---|---|---|
| Kirim | 1 hidden `name="wd_fee_tiers"` (JSON) | 3 input paralel per baris: `wd_tier_min[]`, `wd_tier_max[]`, `wd_tier_pct[]` |
| Terima | `_norm_tiers($json)` strict | Helper `withdrawal_fee_tier_normalize()` (rows) — **JSON legacy tetap diparse** bila array tidak dikirim (`withdrawal_fee_tier_rows_from_json()`), agar halaman yang masih ter-cache dengan JS lama tetap bisa disimpan |
| Repopulasi | tidak mungkin (JSON invalid = hilang) | baris array mentah disimpan di flashdata → dirender kembali apa adanya |

Manfaat: kegagalan validasi **tidak lagi menghancurkan input** (syarat S6), dan
serialisasi JSON menjadi urusan **server** (representasi penyimpanan), bukan
urusan transport.

### 5.4 Kontrak helper baru (`application/helpers/withdrawal_fee_helper.php`)

Fungsi **murni**, tanpa DB, `function_exists()`-guarded (pola `wa_group_helper`,
`product_image_helper`, `withdrawal_amount_helper`):

```php
withdrawal_fee_tier_normalize($rows, $min_amount, $max_amount)
  // -> ['ok'=>bool, 'errors'=>string[], 'notices'=>string[], 'tiers'=>[[min,max,bps],…]|null, 'rows'=>normalized_rows]

withdrawal_fee_tier_rows_from_json($json)
  // JSON legacy '[[min,max,bps],…]' -> [['min','max','pct'],…] (best effort) | null

withdrawal_fee_tier_json($tiers)
  // [[min,max,bps],…] -> JSON kanonik untuk system_settings.wd_fee_tiers

withdrawal_fee_tier_bps_to_pct($bps)
  // bps 750 -> '7.5' (dipakai view PHP DAN JS agar tidak ada rumus ganda)
```

Aturan internal (draf):

```php
$out = []; $errors = []; $notices = [];
// 1) parse tiap baris (digit-only int / persen 2 desimal)
//    gagal -> errors[] = "Tier baris {n}: min, maks, dan persen wajib angka (persen 0–100)."
// 2) $max <= $min -> errors[] = "Tier baris {n}: maksimal harus lebih besar dari minimal."
// 3) usort($out, fn($a,$b) => $a['min'] <=> $b['min'])
// 4) kontiguitas: $out[i]['min'] !== $out[i-1]['max'] -> errors[] (sebut nomor baris + dua nilai)
// 5) endpoint turunan (hanya bila $min_amount/$max_amount valid):
//    $out[0]['min'] !== $min_amount  -> notices[] + set
//    last max <= $max_amount         -> notices[] + set ke $max_amount + 1
// 6) guard akhir: last.max <= last.min -> errors[]
return ['ok' => !$errors, 'errors'=>$errors, 'notices'=>$notices,
        'tiers'=> $errors ? null : array_map(fn($t)=>[(int)$t['min'],(int)$t['max'],(int)$t['bps']], $out),
        'rows' => $out];
```

**Catatan penting (D2):** auto-normalisasi **hanya** untuk dua endpoint turunan.
Gap/overlap, persen di luar rentang, baris tidak lengkap, dan tier kosong tetap
**hard reject** — tidak ada tipe biaya yang diubah senyap.

### 5.5 Kontrak model (`Wallet_model`)

- `validate_financial_settings(array $raw)` — **signature tetap**, bentuk
  kembalian **aditif**:
  ```php
  ['ok'=>bool, 'errors'=>string[], 'notices'=>string[],
   'field_errors'=>['wd_fee_tiers'=>[…],'wd_min_amount'=>[…]],
   'values'=>array<string,string>]
  ```
  (kunci `errors`/`values` tidak berubah → aman untuk pemanggil yang ada).
- Precedence saat bounds invalid: bila `min`/`max` gagal (`:284-292`), tier tetap
  divalidasi **struktural saja** (tanpa penurunan endpoint, tanpa error
  kontradiksi) agar admin tidak menerima dua pesan yang saling menutupi.
- `$raw['wd_fee_tiers']` menerima **dua** bentuk: `array` baris (transport baru)
  atau `string` JSON (legacy). Deteksi: `is_array()`.
- `_norm_tiers()` (`:206-233`) **tidak diubah** — ia tetap parser **strict** jalur
  baca (`_resolve_financial_config()`, `:101`) dan safety net bila baris DB
  disunting manual. Duplikasi aturan half-open dengan helper **disengaja &
  didokumentasikan** (satu untuk input, satu untuk baca) demi nol risiko pada
  jalur uang.

### 5.6 UI Card 3 (baru)

```
┌ Biaya Penarikan ────────────────────────────────────────────────┐
│ Biaya Tetap (IDR) | Minimal (IDR) | Maksimal (IDR)              │
│   [6500]          |  [50000]  *   |  [50000000]                 │
│                   * = tier pertama mengikuti nilai ini          │
│                                                                  │
│ Tier Biaya      [ Rapikan Tier ]   Rentang tercakup: ✓ …        │
│ ┌ Baris 1  Min 50.000 (otomatis)  Maks [500000]  % [10]  [×] ┐  │
│ ┌ Baris 2  Min 500.000 (otomatis) Maks [1000000] % [7.5] [×] ┐  │
│ … Baris 6  Min 10.000.000        Maks [50000001]% [3]   [×]     │
│ + Tambah baris tier                                             │
└──────────────────────────────────────────────────────────────────┘
```

Detail perilaku:
1. **Row 1 `Min`** → `readonly` + `aria-readonly`, nilainya **selalu** =
   nilai live `#wd_min_amount`; ada label bantuan "= Minimal penarikan".
2. **`Maks` baris terakhir** → bila ≤ live `Maksimal`, JS **tidak** menulis ulang
   bila admin sudah pernah mengeditnya manual (`dirty` set) melainkan menampilkan
   hint amber + tombol **"Sesuaikan"**; server tetap menormalkannya (D2).
   Bila belum disentuh → auto-isi `liveMax + 1` + notice hijau.
3. **Interior** → bebas diketik; boleh berada dalam state sementara yang tidak
   kontigu (JS menandai merah, **tidak** mengosongkan payload).
4. **Tombol "Rapikan Tier"** → sort baris menaik berdasar `min` (stabil), lalu
   `rows[i].min = rows[i-1].max`, lalu turunkan endpoint; menampilkan
   "N baris dirapikan". Aksi eksplisit admin → tidak perlu konfirmasi.
5. **Ringkasan cakupan live** (`#tierStatus`): hijau
   `"6 tier · tercakup Rp 50.000 – Rp 50.000.001 ✓ (tanpa celah)"`; merah
   `"Tier baris 2: minimal Rp 600.000 ≠ maksimal baris 1 (Rp 500.000)"` +
   `ring-2 ring-red-500` pada baris tersebut. **Ini status, bukan halaman error.**
6. **Submit gate dipertahankan** (kontrak M4/plan/70): block **hanya** bila
   `hardErrors.length > 0` (baris tidak lengkap / `max ≤ min` / persen di luar
   rentang / gap). Endpoint turunan **tidak pernah** memblokir.
7. Nol key kamus baru (admin 100% Indonesia, L1); nol animasi; nol `<style>` baru
   (pakai token tema `t-*` yang sudah ada).

### 5.7 Repopulasi & pelaporan error

- POST gagal → `set_flashdata('settings_form_state', $state)` dengan `$state` =
  seluruh nilai mentah finansial + `wd_operational_days`, `tier_rows` (BARIS
  MENTAH dari array transport), `wa_number`/`support_email`/`wa_group_link`,
  `rebate_*`, `errors`, `notices`, `field_errors`.
- GET → `$state = $this->session->flashdata('settings_form_state')`; bila ada,
  jadikan **sumber render** (fallback ke `$cfg`/DB bila tidak). Field kontak
  memakai `set_value('…', $state['…'] ?? $db_value)` sehingga precedence tetap
  `POST → state → DB`.
- Semua nilai `$state` berasal dari input admin → **wajib** di-escape saat render
  (`htmlspecialchars` / `set_value`). Tidak ada echo mentah (anti-XSS).
- Banner flash error tetap ada (`'Validasi gagal: ' . implode(' ', $errors)`)
  **plus** blok ringkas error per baris di dalam Card 3.
- Sukses + ada `notices` →
  `"Pengaturan berhasil disimpan. Penyesuaian otomatis: {notices}"`.

### 5.8 Semantik audit (M5/A1)

`_audit_ctx(null, 'admin_update_settings', ['keys','before','after'])` bertambah
satu kunci: `'auto_adjusted' => $notices` (array; `[]` bila tidak ada penyesuaian)
sehingga setiap penyesuaian otomatis **terlacak** — sejalan dengan prinsip
"never silently change money semantics". `update_system_settings()`
(`Admin_model.php:1227-1238`) tidak perlu diubah (detail audit bebas JSON).

---

## 6. Inventaris Berkas

### 6.1 Berkas BARU (1)

| Berkas | Isi |
|---|---|
| `application/helpers/withdrawal_fee_helper.php` | Choke-point tunggal aturan tier (5 fungsi murni §5.4) + `function_exists()` guard + komentar asal-usul (plan/110, amandemen plan/56 §2.3). |

### 6.2 Berkas DIUBAH (5)

| Berkas | Perubahan |
|---|---|
| `application/config/autoload.php:112` | Tambah `'withdrawal_fee'` di `$autoload['helper']` + komentar Indonesia (pola plan/104/105/109). **Wajib di atas** baris yang sudah memuat `withdrawal_amount` (plan/109). |
| `application/models/Wallet_model.php` | `validate_financial_settings()`: konsumsi array/JSON via helper, kembalikan `notices` + `field_errors`, aturan endpoint turunan (D2). `_norm_tiers()`/`_resolve_financial_config()`/`calculate_withdrawal_fee()` **tidak disentuh**. |
| `application/controllers/Admin.php:375-537` | `settings()`: rakit baris tier dari array POST (+ fallback JSON legacy), teruskan `notices`, repopulasi via `settings_form_state`, `auto_adjusted` di audit ctx, GET menyiapkan `tier_rows`/`form_state`/`field_errors` untuk view. |
| `application/views/admin/settings.php` | Card 3 (markup `:151-215`), blok error/notice inline, `row 1 Min` readonly, tombol "Rapikan Tier", ringkasan cakupan, dan **rewrite** script `:410-544` (`computeTiers()` + `syncDerived()` + `liveMin/liveMax` + transport array). |
| `application/config/withdrawal_fees.php:29-45` | **Komentar saja** (dokumentasi aturan endpoint turunan + catatan bahwa nilai fallback tetap `100000`/`50000000`). Nilai fallback **tidak** diubah (lihat §12 Q1). |

### 6.3 Berkas DIVERIFIKASI SAJA (target: **nol perubahan**)

`application/models/Admin_model.php`, `application/config/routes.php`,
`database.sql`, `database_seed.sql`, `application/language/{english,indonesian}/app_lang.php`,
`application/views/wallet/withdraw.php`, `application/controllers/Wallet.php`,
`application/helpers/withdrawal_amount_helper.php`, seluruh `system/**`,
`scripts/**` (kecuali probe sementara di `/tmp`, §8).

### 6.4 Amandemen kontrak plan sebelumnya (dicatat, bukan dilanggar senyap)

| Plan | Kontrak lama | Status di plan/110 |
|---|---|---|
| plan/56 §2.3 (:68-69) | `first.min == wd_min_amount` & `last.max > wd_max_amount`; **any violation → reject** | **Diamandemen (D2)**: dua predikat itu dinormalkan otomatis + `notices`; sisanya tetap reject. |
| plan/70 :215 / plan/71 :51 | `.tier-min/.tier-max/.tier-pct/.tier-del`, `#tierRows/#tierStatus/#tierAdd`, `serializeTiers()`, hidden `#wd_fee_tiers` | **Diamandemen sebagian**: class & id baris, `#tierRows`, `#tierStatus`, `#tierAdd`, dan grid responsif M7 **dipertahankan**; hidden `#wd_fee_tiers` → array `wd_tier_min[]/max[]/pct[]`; `serializeTiers()` → `computeTiers()`. Jalur JSON legacy tetap diterima (kompat. mundur). |
| plan/52 §1.4 / plan/109 | Tarif tier, `calculate_withdrawal_fee()`, tampilan net | **Tidak disentuh** — hanya dijadikan bukti mengapa cakupan tier wajib penuh (§5.1). |

---

## 7. Langkah Eksekusi (P1–P9)

### P1 — Helper choke-point (`withdrawal_fee_helper.php`) + autoload
Implementasi 5 fungsi §5.4 (murni, `function_exists()`-guarded, komentar asal
aturan + mengapa endpoint turunan dinormalkan). Daftarkan `'withdrawal_fee'` di
`application/config/autoload.php:112`. `php -l` keduanya.

### P2 — `Wallet_model::validate_financial_settings()`
- Terima `wd_fee_tiers` sebagai array baris **atau** string JSON legacy.
- Delegasikan ke `withdrawal_fee_tier_normalize($rows, $min, $max)`.
- Tambah `notices`, `field_errors`; jaga `errors`/`values` apa adanya.
- Precedence "bounds invalid → validasi tier struktural saja" (§5.5).
- Pertahankan `_norm_tiers()`, `_resolve_financial_config()`,
  `calculate_withdrawal_fee()` **byte-identik**.

### P3 — View: transport + validasi baru (`application/views/admin/settings.php`)
- Hapus hidden `#wd_fee_tiers` (`:211`); render 3 input array per baris
  (`name="wd_tier_min[]"` dst.), `data-row="n"`.
- Baris 1: `Min` `readonly` + label "= Minimal penarikan" (`:184-188`).
- Ganti `MIN_AMOUNT`/`MAX_AMOUNT` (`:413-414`) → `liveMin()/liveMax()`;
  listener `input`/`change` pada `#wd_min_amount`, `#wd_max_amount` (`:500-503`).
- `computeTiers()` menggantikan `serializeTiers()`: kembalikan
  `{ok, hardErrors:[{row,msg}], notices:[], tiers:[[…]]}`; **selalu** isi input
  array (tidak pernah mengosongkan payload); tandai baris bermasalah.
- Submit gate (`:535-540`) tetap ada, tapi hanya bereaksi pada `hardErrors`.

### P4 — View: "Rapikan Tier" + ringkasan cakupan
Tombol `#tierFix` (§5.6.4), tombol "Sesuaikan" untuk `Maks` baris terakhir,
`#tierStatus` menjadi ringkasan cakupan (§5.6.5).

### P5 — View: error & notice inline
Blok error per baris (dari `field_errors['wd_fee_tiers']`) + daftar `notices`
hijau/amber di dalam Card 3 (di atas tombol Simpan).

### P6 — Controller: `Admin::settings()` POST
- Rakit `$tier_rows` dari `wd_tier_min[]/max[]/pct[]`; bila kosong →
  pakai `wd_fee_tiers` legacy (string) untuk kompatibilitas.
- Teruskan `notices` → flash sukses "Penyesuaian otomatis: …".
- `$audit_ctx` + `'auto_adjusted' => $notices`.
- Gagal → `set_flashdata('settings_form_state', …)` **sebelum** `redirect`.

### P7 — Controller: `Admin::settings()` GET
`$state = flashdata('settings_form_state')` → sumber render; sediakan
`tier_rows` (dari `$state` atau dari `$cfg['tiers']` via
`withdrawal_fee_tier_bps_to_pct()`) + `field_errors`/`notices` untuk view.

### P8 — (opsional, risiko rendah) Parity repopulasi kartu QRIS/deposit
`deposit_expiry_minutes`/`deposit_min_amount`/`deposit_max_amount` (form terpisah,
`:313-407`) juga direpopulasi dari `settings_form_state` supaya satu kegagalan
tidak menghapus dua kartu sekaligus. **Tanpa** perubahan semantik validasi.

### P9 — Dokumentasi + verifikasi
- Perbarui komentar `application/config/withdrawal_fees.php:29-45`.
- Catat amandemen plan/56 & plan/70 di `plan/110_…_SUMMARY.md` saat eksekusi.
- Jalankan seluruh matriks §8 dan **laporkan hasil nyata** (jangan klaim yang
  tidak dijalankan).

---

## 8. Matriks Verifikasi

| # | Verifikasi | Perintah / aksi | Ekspektasi |
|---|---|---|---|
| **V1** | Lint seluruh berkas tersentuh | `php -l application/helpers/withdrawal_fee_helper.php && php -l application/models/Wallet_model.php && php -l application/controllers/Admin.php && php -l application/views/admin/settings.php && php -l application/config/autoload.php` | `No syntax errors detected` ×5 |
| **V2** | Gate i18n (wajib, copy member tidak berubah) | `php scripts/audit_i18n_parity.php` → `LULUS`; `php scripts/audit_i18n_hardcoded.php` → `0 temuan` | exit 0 keduanya |
| **V3** | Probe CLI normalizer (`/tmp/probe_110_tier_normalize.php`, `define('BASEPATH',…)` + `include` helper — pola `migrate_105`) | kasus C1…C10 di bawah | sesuai tabel |
| V3-C1 | **Skenario user**: baris seed, `min=50000` | `ok=true`, `notices` menyebut tier 1 → Rp 50.000, `tiers[0]=[50000,500000,1000]` | ✔ |
| V3-C2 | Baris seed apa adanya (`min=100000`) | `ok=true`, `notices=[]` (tidak ada perubahan senyap) | ✔ |
| V3-C3 | `last.max == max_amount` | dinormalkan ke `max_amount+1` + notice | ✔ |
| V3-C4 | Gap (`row2.min=600000`, `row1.max=500000`) | `ok=false`, error menyebut **baris 2** + kedua nilai | ✔ |
| V3-C5 | `row.max ≤ row.min` | `ok=false`, error menyebut nomor baris | ✔ |
| V3-C6 | persen `120` | `ok=false` | ✔ |
| V3-C7 | `rows = []` | `ok=false`, "Minimal satu tier." | ✔ |
| V3-C8 | JSON legacy 6 tier (kompatibilitas mundur) | terparse → `ok=true` | ✔ |
| V3-C9 | **Invariant cakupan**: untuk `min`, `min+1`, tiap `boundary−1/boundary/boundary+1`, `max` → tepat 1 tier | 0 pelanggaran | ✔ |
| V3-C10 | Round-trip persen: bps 750 → `'7.5'` → bps 750 | bolak-balik identik | ✔ |
| **V4** | HTTP GET halaman admin | login admin → `GET /admin/settings` | `200`; 6 `.tier-row`; `#wd_min_amount` ada; input tier `name="wd_tier_min[]"`; row 1 `Min` `readonly`; **tidak ada** `id="wd_fee_tiers"` |
| **V5** | HTTP POST skenario user (min=50000, 302 → follow) | `POST /admin/settings` (CSRF) | `302` → flash **sukses** + "Penyesuaian otomatis: Tier pertama …"; `#wd_min_amount` = `50000`; tier row 1 `Min` = `50000` |
| V5b | DB | `SELECT key_name,key_value FROM system_settings WHERE key_name IN ('wd_min_amount','wd_fee_tiers','wd_max_amount')` | `50000` / `[[50000,500000,1000],[500000,1000000,750],…,[10000000,50000001,300]]` / `50000000` |
| V5a | Flash notice tampil di HTML respons | cari string "Penyesuaian otomatis" | ditemukan |
| **V6** | Audit | `SELECT details FROM system_audit_logs WHERE action='admin_update_settings' ORDER BY id DESC LIMIT 1` | `keys` memuat `wd_min_amount`,`wd_fee_tiers`; `before`/`after` benar; `auto_adjusted` = array notice |
| **V7** | HTTP POST gap (skenario error nyata) | `POST` dengan gap + min baru | `302` → flash error menyebut **"Tier baris N"**; DB **tidak** berubah |
| V7b | Repopulasi | baca respons setelah redirect | min/max/tier/hari/jam/**kontak/rebate** = nilai yang baru diketik (bukan DB) |
| **V8** | Member tidak rusak | `GET /wallet/withdraw` (session member) | `200`; `WD_CONFIG.tiers[0][0] === 50000`; helper preview tidak error |
| **V9** | Regresi tarif (plan/52) | preview/`calculate_withdrawal_fee` pada `500000 → 44.000`, `1.000.000 → 71.500`, `5.000.000 → 206.500` | identik dengan sebelum perubahan |
| **V10** | No-op save | simpan tanpa perubahan apa pun | flash sukses; audit `keys: []` |
| **V11** | Kompatibilitas mundur | `POST` langsung dengan `wd_fee_tiers` JSON + **tanpa** `wd_tier_min[]` | hasil = V5 |
| **V12** | Guard grep | `grep -n "MIN_AMOUNT\|MAX_AMOUNT" application/views/admin/settings.php` → kosong; `git status --short system/ database.sql database_seed.sql` → kosong | ✔ |

> **Catatan lingkungan (pola plan/109):** jika kredensial login admin/member
> belum tersedia saat eksekusi, V4–V11 dinyatakan **"Pending Manual QA by User"**
> dengan langkah reproduksi persis di atas; V1–V3 dan V12 tetap **wajib**
> dijalankan dan dilaporkan apa adanya.

---

## 9. Non-Goal (eksplisit)

1. **Tidak** mengubah `calculate_withdrawal_fee()`, `_post()`, `wallet_ledger`,
   atau tarif/persentase tier (`plan/52 §1.4` tetap otoritatif).
2. **Tidak** mengubah `_resolve_financial_config()` (safety net baca) dan
   `_norm_tiers()` (parser strict jalur baca).
3. **Tidak** mengubah skema/seed (`database.sql`, `database_seed.sql`) — nol DDL,
   nol migrasi.
4. **Tidak** menyentuh route, kamus i18n, copy member, atau `system/**`.
5. **Tidak** mengubah sifat all-or-nothing pencatatan pengaturan (satu TX).
   RC6 hanya terdokumentasi; dampaknya hilang sendiri karena tier nyaris tidak
   pernah lagi hard-error (§5.2).
6. **Tidak** menambah editor/animate/CSS baru di luar token tema `t-*`.
7. **Tidak** mengubah nominal fallback di `withdrawal_fees.php` (§12 Q1).

---

## 10. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Auto-normalisasi menyembunyikan niat admin | Admin mengira persen yang diketik tersimpan, padahal batas bergeser | `notices[]` **selalu** ditampilkan + dicatat `auto_adjusted` di audit; hanya 2 endpoint turunan yang dapat berubah (persen & batas interior mustahil berubah senyap) |
| Gap tier tersimpan → tarif termurah dipakai (`:464`) | Potensi kurang potong biaya | Gap tetap **hard error**; invariant cakupan diuji V3-C9 |
| Jalur legacy JSON lupa dites | Halaman ter-cache rusak saat Simpan | V11 wajib; legacy diparse lewat helper yang sama (bukan jalur kedua) |
| Repopulasi flashdata = jalur XSS baru | Stored/reflected XSS di panel admin | Semua nilai `$state` berasal dari input admin & di-escape (`set_value`/`htmlspecialchars`); input tier tetap `type="number"` |
| `autoload.php` konflik dengan pekerjaan plan/109 yang belum di-commit | Helper tidak termuat | Sisipkan `'withdrawal_fee'` **setelah** `'withdrawal_amount'` pada baris yang sama; verifikasi dengan `php -l` + V4 |
| Bounds salah (mis. `min=600000` > `max` baris 1) | Admin hanya melihat error `max ≤ min` | Pesan §5.2 butir 6 eksplisit menyebut tindakan ("naikkan maksimalnya atau hapus baris ini") + highlight baris 1 |
| Nilai live di `system_settings` disunting manual via phpMyAdmin | Bundle tidak koheren → bound kembali ke fallback 100.000/50.000.000 + `log_message('error')` | Perilaku **existing** dipertahankan (bukan regresi); dokumentasi §12 Q1 |

---

## 11. Rollback

Tidak ada DDL/migrasi ⇒ rollback = `git checkout` pada 5 berkas diubah + hapus
1 berkas baru:

```
git checkout -- application/config/autoload.php application/models/Wallet_model.php \
                application/controllers/Admin.php application/views/admin/settings.php \
                application/config/withdrawal_fees.php
rm application/helpers/withdrawal_fee_helper.php
```

Nilai `system_settings` yang sudah tersimpan tetap valid untuk kode lama **hanya
bila** `tier[0].min == wd_min_amount` (mis. sesudah berhasil menurunkan ke
50.000 tanpa menyesuaikan tier → kode lama akan menolak simpan berikutnya, tetapi
membaca/menghitung fee tetap benar). Bila perlu kembali persis ke seed:
`update system_settings set key_value = '100000' where key_name = 'wd_min_amount'`
dan pulihkan JSON tier seed (`database.sql:368`).

---

## 12. Pertanyaan Terbuka untuk Pemilik (tidak memblokir P1–P9)

**Q1 — Nominal fallback `withdrawal_fees.php` & `docs/1_PRD.md:124`.**
Berkas fallback (dan PRD §122) masih menyebut **minimum Rp 100.000**. Selama
`system_settings` normal, nilai operasional = 50.000 seperti yang diinginkan.
Namun bila baris `wd_min_amount`/`wd_fee_tiers` hilang atau tidak koheren, sistem
**kembali** ke 100.000 (perilaku existing). Pilihan:
(a) **biarkan** 100.000 sebagai fail-safe + dokumentasikan divergensi (rekomendasi
plan ini), atau (b) samakan fallback ke 50.000 **dan** perbarui `docs/1_PRD.md`
§121-125 agar dokumen tidak bertentangan dengan operasi.

**Q2 — Nominal minimum: batas bawah administrasi.** Saat ini tidak ada lantai
(`_norm_int(..., 1)`), jadi admin dapat menyetel serendah Rp 1 (fee tetap 6.500 →
net bisa 0/negatif untuk nominal kecil). Perlu lantai bisnis eksplisit (mis.
`min ≥ 10.000` + validasi `gross > fixed_fee + fee`)? Jika ya, sebaiknya menjadi
plan terpisah agar tidak memperluas lingkup perbaikan UI ini.

**Q3 — Kartu QRIS/deposit (P8).** Apakah repopulasi kartu "Pembayaran QRIS
Manual" ikut dikerjakan pada plan ini (disarankan ya, risiko rendah), atau
ditunda?
