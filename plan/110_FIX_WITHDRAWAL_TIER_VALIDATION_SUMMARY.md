# Plan 110 — SUMMARY (Eksekusi Perbaikan Validasi Tier & Minimal Penarikan)

> **Status:** **SELESAI untuk seluruh langkah kode (P1–P9).** Seluruh perintah
> CLI pada §5 dijalankan **nyata** di sesi ini dan hasilnya dikutip apa adanya.
> Verifikasi **ber-sesi admin (V4–V11: HTTP + CSRF + tulis `system_settings`)**
> dinyatakan **"Pending Manual QA by User"** — **bukan** karena bash/terminal
> diblokir (bash, PHP 8.3.6, MySQL lokal, `php -S`, dan `node` semuanya BEKERJA
> di sesi ini — lihat §5), melainkan karena:
>
> 1. **kredensial login admin tidak tersedia** di lingkungan ini (halaman
>    `/admin/settings` di balik guard `admin_id` → `307 /control-panel`), dan
> 2. V5/V5b/V7/V10 **menulis** ke `system_settings` — perubahan data operasional
>    yang menjadi otoritas pemilik repositori, bukan agen.
>
> **Rencana acuan:** `plan/110_FIX_WITHDRAWAL_TIER_VALIDATION_PLAN.md`
> **Keputusan owner:** `dec-880272f38fa19298` (D1 derive, D2 auto-adjust + report,
> D3 bahasa Indonesia) + jawaban §12: **Q1 = (a)** fallback tetap 100.000 +
> dokumentasi, **Q2 = tidak** ada lantai bisnis baru, **Q3 = YA** (P8 dikerjakan).
> **Zero-impact yang terbukti:** nol DDL, nol migrasi, nol route baru, nol key
> kamus (**tetap 602/602**), nol perubahan member copy, nol perubahan `system/`.

---

## 1. Ringkasan hasil

| # | Perubahan | Sebelum | Sesudah |
|---|---|---|---|
| 1 | **Deadlock validasi** | JS menuntut `tier[0].min === MIN_AMOUNT` (snapshot render) sementara server menuntut `=== wd_min_amount` baru ⇒ **tidak ada payload yang valid** | Nilai dibaca **LIVE** dari input; endpoint **diturunkan**, bukan di-assert (§3.4) |
| 2 | **Transport tier** | 1 hidden `wd_fee_tiers` (JSON) — baris invalid ⇒ payload dikosongkan ⇒ tak bisa direpopulasi | **Array input** `wd_tier_min[]/max[]/pct[]` (selalu terkirim); JSON legacy tetap **diterima** untuk halaman ter-cache |
| 3 | **Kontradiksi endpoint** | Error keras: "Tier pertama harus dimulai dari nominal minimal penarikan" | **Dinormalkan otomatis** + dilaporkan (`notices` → flash + audit `auto_adjusted`) |
| 4 | **State transisi** | Setiap ketikan divalidasi; satu celah ⇒ submit **diblokir**, tanpa jalan keluar | Hanya kontradiksi nyata (baris kosong / `max ≤ min` / persen / celah) yang memblokir; ada tombol **"Rapikan Tier"** + **"Sesuaikan Batas Atas"** |
| 5 | **Repopulasi** | Semua input finansial **hilang** setiap gagal simpan (render ulang dari DB) | `settings_form_state` (flashdata 1 request) mempertahankan **seluruh** input: finansial + hari/jam + kontak + rebate + **kartu QRIS/deposit (P8)** |
| 6 | **Pesan error** | Satu banner `Validasi final: …`; pesan JS tanpa nomor baris | Banner **+ blok error inline**; pesan menyebut **nomor baris + kedua nilai** + saran tindakan |
| 7 | **Aturan tier** | Tersebar: JS (view) + `_norm_tiers` + `validate_financial_settings` | **Satu choke-point** `application/helpers/withdrawal_fee_helper.php` (admin + CLI + paritas view) |

**Bukti langsung hilangnya deadlock (JS nyata, dieksekusi):** skenario user
("Minimal" → `50000`, tier tidak disentuh) kini menghasilkan
`tier[0].min = 50000` otomatis, status **hijau**, dan **submit TIDAK diblokir**
(§5 P2 J2) — persis kebalikan dari kondisi yang dilaporkan.

---

## 2. Berkas yang berubah

### 2.1 Berkas BARU (1)

| Berkas | Baris | Isi |
|---|---|---|
| `application/helpers/withdrawal_fee_helper.php` | 267 | Choke-point tunggal aturan tier (murni, DB-free, `function_exists()`-guarded): `withdrawal_fee_tier_int()`, `_pct()`, `_bps_to_pct()`, `_rows_from_json()`, `_normalize()`, `_json()` |

### 2.2 Berkas DIUBAH (6)

| Berkas | Perubahan | Catatan |
|---|---|---|
| `application/config/autoload.php` | `+ 'withdrawal_fee'` pada `$autoload['helper']` (setelah `withdrawal_amount`) + komentar Indonesia | Terbukti termuat pada request nyata (§5 P6) |
| `application/models/Wallet_model.php` | `validate_financial_settings()` → konsumsi helper, **`notices` + `field_errors`**; docblock diperbarui | **Hanya method ini yang berubah** — `_norm_tiers`, `_resolve_financial_config`, `_norm_int`, `_norm_pct`, `calculate_withdrawal_fee`, `_post` **identik byte-for-byte** (§5 P7) |
| `application/controllers/Admin.php` | `settings()`: rakit baris dari array + fallback JSON legacy, `notices` → flash sukses, `auto_adjusted` di audit, `settings_form_state` pada gagal; `qris_settings()`: `qris_form_state` pada 3 jalur gagal (validasi/upload/persist); **+3 private helper**: `_collect_tier_rows()`, `_tier_rows_from_config()`, `_settings_form_state()` | Method lain (`index`, `users`, `toggle_maintenance`, `alerts_poll`, `financial_settings`, `_audit_ctx`) tidak disentuh |
| `application/views/admin/settings.php` | Card 3 direnovasi (endpoint turunan, transport array, tombol Rapikan/Sesuaikan, hint, blok error/notice inline) + **script di-rewrite** (`computeTiers()`/`refresh()`/`syncDerived()` menggantikan `serializeTiers()`/`MIN_AMOUNT`) + repopulasi semua kartu + blok error inline QRIS | Nol key kamus baru (admin = Indonesia, L1) |
| `application/config/withdrawal_fees.php` | **Komentar saja**: aturan endpoint turunan + catatan Q1 (berkas ini = **fail-safe**, nilai operasional di `system_settings`); label tier pertama diperjelas | Nilai fallback **tidak** diubah (100.000/50.000.000) |
| `scripts/audit_i18n_hardcoded.php` | `+1` pengecualian berkas: `withdrawal_fee_helper.php` (pesan operator/admin L1 — lihat §4.2) | 8 baris termasuk komentar justifikasi |

**Diff total:** 16 berkas, +983/−188 (termasuk pekerjaan plan/109 yang belum
di-commit yang sudah ada di working tree sebelum sesi ini).

### 2.3 Berkas DIVERIFIKASI (target nol perubahan — TERCAPAI)

`application/models/Admin_model.php` (tidak diubah oleh plan/110 — perubahannya
dari plan/109), `application/config/routes.php`, `database.sql`,
`database_seed.sql`, `application/language/**` (602 baris key masing-masing,
tidak tersentuh plan/110), `application/views/wallet/withdraw.php`,
`application/controllers/Wallet.php`, seluruh `system/**`.

---

## 3. Detail perubahan per lapis

### 3.1 Helper (P1) — satu sumber aturan

- Fungsi **murni** (tanpa DB, tanpa efek samping, tanpa state) → aman dipanggil
  dari admin, CLI, dan view (paritas tampilan persen).
- Aturan: parse angka (toleran `50.000` / koma desimal), `0 < min < max`,
  persen 0–100 (2 desimal) → `bps = round(pct × 100)`, urut menaik, **kontigu
  penuh** (celah/overlap = error keras yang menyebut nomor baris + dua nilai),
  lalu **endpoint turunan** (`rows[0].min ← wd_min_amount`,
  `rows[n-1].max ← max(…, wd_max_amount + 1)`) → `notices[]`.
- Guard akhir mencegah normalisasi menghasilkan baris invalid (mis. Minimal
  dinaikkan melewati batas atas baris pertama) dengan pesan yang menyebut
  tindakan: *"naikkan maksimalnya atau hapus baris ini"*.
- Batas panjang digit (`WITHDRAWAL_FEE_TIER_MAX_DIGITS = 15`) → anti saturasi int.

### 3.2 Model (P2) — konsumsi + lapisan pesan

- `wd_fee_tiers` menerima **array baris** (transport baru) **atau** string JSON
  (legacy). Bentuk kembalian **aditif**: `ok/errors/values` tidak berubah,
  ditambah `notices` + `field_errors` (peta key → pesan untuk penandaan inline).
- **Precedence**: bila bound (`wd_min_amount`/`wd_max_amount`) tidak valid, tier
  divalidasi **struktural saja** (tanpa penurunan endpoint) agar admin tidak
  menerima dua pesan yang saling menutupi.
- Jalur baca **tidak disentuh** — `_norm_tiers()` tetap parser strict + safety
  net (`_resolve_financial_config()`) tetap mengembalikan bundle fallback bila
  baris DB tidak koheren (terbukti bekerja: §5 P4 B3/B4).

### 3.3 Controller (P6/P7/P8)

- **POST**: `_collect_tier_rows()` memasangkan `wd_tier_min[]/max[]/pct[]` per
  indeks; bila array tidak ada → parse ulang JSON legacy (validasi tetap memakai
  string sebagai sumber kebenaran request itu) → `notices` masuk flash sukses
  (`"Pengaturan berhasil disimpan. Penyesuaian otomatis: …"`) dan ke audit
  (`auto_adjusted`); gagal → `settings_form_state` **sebelum** redirect.
- **GET**: `flashdata('settings_form_state' | 'qris_form_state')` menjadi sumber
  render (fallback ke DB); `tier_rows` disiapkan seragam
  (`['min','max','pct']`) melalui `_tier_rows_from_config()`.
- **QRIS (P8)**: tiga jalur gagal (`validate_deposit_settings`, upload gagal,
  persist gagal) menyimpan `qris_form_state` + `field_errors` → kartu QRIS/deposit
  tidak lagi mengosongkan input; blok error inline dirender di kartu itu.

### 3.4 View (P3/P4/P5) — inti UX

- Hapus **total** `var MIN_AMOUNT/MAX_AMOUNT` (akar RC1) → `liveMin()/liveMax()`.
- **Row 1 `Min`** = `readonly` + derivasi dari "Minimal (IDR)"; label berubah
  dinamis (`… — = Minimal`) mengikuti baris pertama setelah hapus/urut ulang.
- **`Maks` baris terakhir** diperpanjang otomatis **hanya bila belum disentuh
  admin**; bila sudah, tampil peringatan amber + tombol **"Sesuaikan Batas Atas"**.
- **"Rapikan Tier"**: urut berdasar `Min` → sambung `rows[i].min = rows[i-1].max`
  → lapor `"N baris dirapikan."` (satu klik, tanpa mengetik ulang).
- **"Tambah baris tier"**: baris baru langsung **kontigu** (`Min` = `Maks` baris
  sebelumnya, persen disalin) sehingga valid sejak awal.
- **Status live**: jumlah tier + rentang tercakup (`Rp X – Rp Y ✓`); pelanggaran
  ditandai `ring-2 ring-red-500` pada baris bersangkutan.
- **Submit gate dipertahankan** (kontrak plan/70/M4): `preventDefault()` +
  `stopPropagation()` **hanya** saat ada pelanggaran nyata — guard `csrf_meta`
  tidak pernah menandai form "submitting" saat submit dibatalkan (dibuktikan §5 P2 J4).
- **Repopulasi**: seluruh kartu (finansial, jam/hari, deposit fee, rebate,
  kontak, QRIS) memakai `state → DB`; semua jalur keluaran di-escape
  (`set_value` = `html_escape` ENT_QUOTES sesuai `system/helpers/form_helper.php:713-723`,
  atau `htmlspecialchars`) — dibuktikan dengan payload XSS (§5 P5 R3).

---

## 4. Amandemen yang dicatat (bukan dilanggar senyap)

### 4.1 Kontrak plan sebelumnya

| Plan | Kontrak lama | Status |
|---|---|---|
| plan/56 §2.3 (:68-69) | `first.min == wd_min_amount`, `last.max > wd_max_amount`, **any violation → reject** | **Diamandemen sesuai D2**: dua endpoint turunan dinormalkan + `notices`; jenis pelanggaran lain tetap hard reject |
| plan/70 :215 / plan/71 :51 | `serializeTiers()`, hidden `#wd_fee_tiers`, kelas/id baris tier | **Sebagian diamandemen**: `#tierRows/#tierStatus/#tierAdd`, `.tier-row/.tier-min/.tier-max/.tier-pct/.tier-del`, guard submit, dan grid responsif M7 **dipertahankan**; hidden → array; `serializeTiers()` → `computeTiers()`/`refresh()`; jalur JSON legacy tetap didukung |
| plan/103 (gate i18n) | `audit_i18n_hardcoded.php` memindai `views/controllers/models/helpers` | **Cakupan diamandemen** — lihat §4.2 |

### 4.2 Amandemen cakupan gate `audit_i18n_hardcoded.php` (TEMUAN BARU saat eksekusi)

Rencana memprediksi V2 = "0 temuan". Kenyataannya gate memindai
`application/helpers/**` dan **model dikecualikan** (`R2` di-skip untuk model,
`audit_i18n_hardcoded.php:285-287`), sehingga 14 pesan operator Indonesia di
helper baru **terflag** → gate `[FAIL] 14 temuan` (exit ≠ 0).

**Resolusi (mengikuti mekanisme yang sudah ada di gate):** menambahkan
`/application/helpers/withdrawal_fee_helper.php` ke `$excludePaths` dengan
komentar justifikasi — kategori yang sama dengan `views/admin/**`,
`Admin.php`, `Admin_model.php`, dan `captcha_helper.php` yang sudah dikecualikan.

**Justifikasi teknis (bukan sekadar "diamkan gate"):** tidak satu pun string di
helper itu dirender ke surface member. Jalur member hanya **membaca** tier
(`Wallet_model::_norm_tiers()` + `calculate_withdrawal_fee()`); validator input
tier hanya dipanggil oleh `Admin::settings()` POST dan probe CLI.

**Risiko yang diakui:** berkas itu tidak lagi dipindai terhadap kebocoran copy
member di masa depan. Mitigasi: komentar di gate berbunyi *"Bila suatu saat
helper ini perlu menampilkan copy ke member, HAPUS pengecualian ini dan alihkan
ke `lang()`"*. Alternatif (memecah pesan menjadi `code` + params lalu merender
prosa di `Admin.php`/view admin) **tidak** diambil karena mengubah kontrak §5.4
yang sudah disetujui tanpa manfaat nyata.

---

## 5. Verifikasi yang BENAR-BENAR dijalankan (bukti nyata)

### P1 — Lint PHP (V1) ✅

```
php -l application/helpers/withdrawal_fee_helper.php  → No syntax errors detected
php -l application/config/autoload.php                → No syntax errors detected
php -l application/config/withdrawal_fees.php         → No syntax errors detected
php -l application/models/Wallet_model.php            → No syntax errors detected
php -l application/controllers/Admin.php              → No syntax errors detected
php -l application/views/admin/settings.php           → No syntax errors detected
php -l scripts/audit_i18n_hardcoded.php               → No syntax errors detected
```

### P2 — Probe normalizer helper (`/tmp/probe_110_tier_normalize.php`) ✅ **40 PASS / 0 FAIL**

| Kasus | Isi | Hasil |
|---|---|---|
| C1 | **Skenario user**: baris seed, `min=50000` | `ok=true`, `tiers[0]=[50000,500000,1000]`, notice menyebut Rp 50.000, 6 tier, JSON tersimpan persis |
| C2 | Bounds tidak berubah | `notices=[]` (tidak ada perubahan senyap), JSON identik |
| C3 | `last.max == max_amount` | dinormalkan ke `50000001` + notice |
| C4 | Celah (`row2.min=600000`) | `ok=false`, pesan **"Tier baris 2"** + kedua nilai |
| C5 | `max ≤ min` | `ok=false`, pesan menyebut baris 3 |
| C6 / C7 / C7b | persen 120 / list kosong / baris kosong | semuanya `ok=false` dengan pesan tepat (tanpa crash) |
| C8 | JSON legacy valid / rusak / non-numerik | terparse; rusak → `null` (tidak menjadi 0%) |
| C9 | **Invariant cakupan** `[min,max]` + sweep sampel | tak ada nominal tak tercakup, tak ada dobel, **tidak pernah jatuh ke fallback tarif termurah** |
| C10 | Round-trip bps↔persen (10 tarif) | identik |
| C11 | Bounds invalid | tier divalidasi struktural saja, `notices=[]` |
| C12 | Minimal dinaikkan melewati baris 1 | error terarah + saran tindakan |
| C13 | `"50.000"` + `"7,5"` | diterima → bps 750 |

### P3 — JS view nyata (`/tmp/probe_110_view_dom.js`, DOM stub, `node`) ✅ **34 PASS / 0 FAIL**

JS **asli** diekstrak dari view lalu dieksekusi (bukan re-implementasi):
`node --check` bersih; skenario: kondisi awal; **J2 = skenario user** (submit
lolos, row 1 min = 50000, baris lain tak tersentuh); J3 auto-extend batas atas;
J3b sentuh manual batas atas ⇒ **amber (warning) bukan blokir** + tombol
Sesuaikan; J4 baris kosong ⇒ blokir **tanpa** menghanguskan baris lain +
`stopPropagation`; J5 celah ⇒ blokir + "Rapikan Tier" menyambung; J7 paritas
persen.

### P4 — Paritas JS ⇄ PHP (`/tmp/probe_110_parity.php`) ✅ **11 PASS / 0 FAIL**

Payload **hasil dump JS nyata** (`/tmp/payload_110.json`) dijalankan lewat helper
PHP → `ok=true`, tier & JSON sama, `notices=[]` (JS sudah menurunkan endpoint ⇒
tanpa kejutan server), cakupan penuh, dan tarif PRD pada batas tier benar.

### P5 — Round-trip jalur BACA produksi + kalkulator biaya nyata (`/tmp/probe_110_readpath.php`) ✅ **23 PASS / 0 FAIL**

Meng-*include* `Wallet_model.php` **asli** dengan stub `CI_Model`/DB berisi
**baris `system_settings` sungguhan dari `db_webtable`** (read-only):
- B1: konfigurasi DB saat ini terbaca, **tanpa** log fallback, pin WIB aktif;
- B2: **tulis (helper) → baca (model asli)** untuk skenario user ⇒ `min=50000`,
  `tiers[0]=[50000,500000,1000]`, tanpa log fallback, tarif half-open benar;
- B3: bundle tidak koheren ⇒ bound kembali ke fallback **+ tercatat di log**;
- B4: JSON rusak di DB ⇒ tier fallback berkas dipakai, **tidak fatal**.

### P6 — Render view nyata + XSS (`/tmp/probe_110_view_render.php`) ✅ **31 PASS / 0 FAIL**

View di-*include* dengan stub helper CI3 (`form_open*` menghormati atribut,
`set_value` meniru `html_escape` CI3):
- R1: 6 baris tier, transport `wd_tier_min[]/max[]/pct[]`, hidden
  `#wd_fee_tiers` **hilang**, row 1 `readonly`, tombol Rapikan/Sesuaikan, hint,
  `var MIN_AMOUNT` **tidak ada lagi**, guard M4 utuh, `serializeTiers` hilang;
- R2: **repopulasi** min/maks/biaya tetap/jam/hari (hanya 2 hari tercentang)/
  kontak/rebate/tier state apa adanya/biaya deposit + checkbox/**kartu QRIS
  lengkap**/error inline finansial & QRIS;
- R3: payload XSS (`"><script>…`) tampil **ter-escape** di atribut (tidak ada
  tag `<script>`/`<img>` mentah) di semua kartu.

### P7 — Integritas jalur baca (byte-for-byte) ✅

```
_resolve_financial_config   IDENTIK byte-for-byte (3314 bytes)
_norm_days_csv / _norm_time / _norm_int / _norm_pct / _norm_tiers   IDENTIK
calculate_withdrawal_fee    IDENTIK byte-for-byte (613 bytes)
_post                       IDENTIK byte-for-byte (2414 bytes)
get_financial_config / get_deposit_policy / validate_deposit_settings  IDENTIK
validate_financial_settings BERUBAH (sesuai rencana)
```

### P8 — Gate i18n (V2) ✅ keduanya exit 0

```
php scripts/audit_i18n_parity.php     → [OK] … LULUS   ; EN keys: 602  ID keys: 602   (exit 0)
php scripts/audit_i18n_hardcoded.php  → [OK] 0 temuan — tidak ada string hardcoded   (exit 0)
```

**Nol key kamus baru** — paritas tetap **602/602** (tanpa perubahan
`application/language/**`).

### P9 — Smoke boot aplikasi nyata (`php -S` + `curl`) ✅ (tidak bisa masuk panel admin)

```
GET /login            → HTTP 200  (35.055 bytes, <title>Login · Synapse</title>,
                                   CAPTCHA + refresh endpoint tampil, 0 error PHP inline)
GET /admin/settings   → HTTP 307  → /control-panel   (guard admin_id bekerja; controller termuat)
GET / , /help , /marketplace , /lang/switch/en → HTTP 307 (guard login, sesuai desain)
```
Server log & log CI: **tidak ada** `Fatal error`/`Warning`/`Deprecated` baru.
Karena `withdrawal_fee_helper.php` ada di `autoload['helper']`, request 200 di
atas sekaligus membuktikan helper baru **termuat bersih** pada bootstrap nyata.

### P10 — Kebersihan workspace ✅

`git status system/ database.sql database_seed.sql application/config/routes.php`
→ **bersih**; `application/language/**` hanya berisi perubahan yang **sudah ada
sebelum sesi ini** (plan/108–109); seluruh berkas probe berada di `/tmp`
(**nol scratch di repo**).

---

## 6. Verifikasi PENDING — Manual QA by User (V4–V11 dari rencana §8)

**Alasan:** butuh **sesi login admin + token CSRF**, dan V5/V5b/V7/V10 **menulis**
`system_settings`. Kredensial tidak tersedia; menulis konfigurasi operasional
bukan kewenangan agen. Langkah di bawah siap dijalankan persis.

| # | Langkah | Ekspektasi |
|---|---|---|
| V4 | Login admin → `GET /admin/settings` | `200`; 6 `.tier-row`; input `name="wd_tier_min[]"`; row 1 `readonly`; **tidak ada** `id="wd_fee_tiers"` |
| V5 | Ubah **Minimal** → `50000`, klik **Simpan** | `302` → flash **sukses** + *"Penyesuaian otomatis: Batas bawah tier pertama disesuaikan mengikuti Minimal Penarikan (Rp 50.000)."*; field Min tetap `50000`; baris 1 Min = `50000` |
| V5b | `SELECT key_name,key_value FROM system_settings WHERE key_name IN ('wd_min_amount','wd_fee_tiers','wd_max_amount')` | `50000` / `[[50000,500000,1000],[…],[10000000,50000001,300]]` / `50000000` |
| V6 | `SELECT details FROM system_audit_logs WHERE action='admin_update_settings' ORDER BY id DESC LIMIT 1` | `keys` memuat `wd_min_amount`,`wd_fee_tiers`; `before`/`after` benar; **`auto_adjusted`** berisi notice |
| V7 | Buat celah antar tier → Simpan | `302` → flash error menyebut **"Tier baris N"**; DB **tidak** berubah |
| V7b | Baca ulang halaman | min/maks/tier/hari/jam/**kontak/rebate** = nilai yang tadi diketik (bukan DB) |
| V8 | (member) `GET /wallet/withdraw` | `200`; `WD_CONFIG.tiers[0][0] === 50000` |
| V9 | Preview biaya di halaman penarikan | Tarif tetap konsisten (perhatikan **§7**: `fixed_fee` lokal = 5.000, bps lokal = 0%) |
| V10 | Simpan tanpa perubahan | flash sukses; audit `keys: []` |
| V11 | `POST` langsung dengan `wd_fee_tiers` **JSON** & tanpa `wd_tier_min[]` (simulasi halaman ter-cache) | hasil sama dengan V5 |

---

## 7. Temuan runtime pada DB lokal (read-only) — PERLU PERHATIAN OWNER

`SELECT` langsung ke `db_webtable` (grup `local`, tanpa menulis apa pun):

| Key | Nilai live saat ini | Implikasi |
|---|---|---|
| `wd_min_amount` | `100000` | Target perubahan (50.000) belum tersimpan — wajar, eksekusi UI = V5 manual |
| `wd_max_amount` | `50000000` | — |
| `wd_fee_tiers` | `[[100000,500000,0],[500000,1000000,0],…,[10000000,50000001,0]]` | **Semua bps = 0%** ⇒ biaya penarikan saat ini = **biaya tetap saja** |
| `wd_fixed_fee` | `5000` | **Berbeda** dari fallback berkas (`6500`) |
| `wd_operational_days` | `1,2,3,4,5,6,7` | Termasuk **Minggu** (fallback berkas: 1–6) |
| `wd_open_time` / `wd_close_time` | `01:00` / `23:59` | **Berbeda** dari fallback berkas (07:00–19:00) |

**Konsekuensi untuk V9:** angka tarif di `plan/52 §1.4`
(44.000 / 71.500 / 206.500) mengasumsikan `fixed_fee = 6500` **dan** bps
non-nol. Dengan data live di atas, biaya = `fixed_fee` saja (mis. Rp 500.000 →
Rp 5.000). Tarif di *berkas* tetap konsisten; **keputusan nilai operasional ada
di tangan owner** (bukan bagian dari plan/110). Bila owner memang ingin struktur
tarif PRD, setel ulang persen tier + biaya tetap pada penyimpanan pertama
setelah plan/110 terpasang — dan **perhatikan**: `notices`/flash akan memberi
tahu bila server menyesuaikan endpoint tier.

> Catatan: kombinasi "bps 0% untuk semua tier" itu sendiri **valid** bagi
> validator (0 ≤ bps ≤ 10000) — perilaku lama maupun baru menerimanya.

---

## 8. Non-goal yang dihormati (keputusan §12)

1. **Q1 = (a)** — `application/config/withdrawal_fees.php` **tetap**
   `min_amount = 100000` / `max_amount = 50000000` sebagai **fail-safe**;
   divergensi terhadap `docs/1_PRD.md §122` didokumentasikan di komentar berkas
   (dan di §7 di atas). **Tidak** ada perubahan `docs/`.
2. **Q2** — **tidak** ada lantai bisnis minimum baru (`wd_min_amount` tetap
   `≥ 1`); pencatatan usulan lantai/validasi `gross > fee` tetap sebagai
   pertanyaan terbuka untuk plan terpisah.
3. **Q3 = YA** — repopulasi kartu **QRIS/deposit** dikerjakan (P8) beserta blok
   error inline-nya.
4. Tidak menyentuh `calculate_withdrawal_fee()`, `_post()`, `wallet_ledger`,
   skema/seed, route, kamus i18n, member copy, atau `system/**`.

---

## 9. Risiko tersisa & rollback

| Risiko | Status / mitigasi |
|---|---|
| Pengecualian gate i18n (§4.2) menyembunyikan kebocoran copy member di masa depan | Terbatas pada **satu berkas**; komentar eksplisit di gate berisi syarat pencabutan |
| Auto-normalisasi terasa "senyap" bagi admin | **Tidak senyap**: `notices` di flash sukses **dan** `auto_adjusted` di baris audit |
| Celah tier tersimpan ⇒ tarif termurah dipakai (`calculate_withdrawal_fee` fallback) | Celah = **hard error**; invariant cakupan diuji (C9 + sweep B2) |
| Repopulasi flashdata jadi jalur XSS | Diuji dengan payload XSS (R3) — semua jalur escape; `set_value()` CI3 = `html_escape` ENT_QUOTES |
| Perbedaan perilaku JS vs PHP (drift aturan) | Aturan ada di **satu** helper + paritas JS⇄PHP diuji pada payload nyata (P4) |
| Rollback | Tanpa DDL: `git checkout` 6 berkas + `rm application/helpers/withdrawal_fee_helper.php` (rencana §11). Nilai `system_settings` tetap valid untuk kode lama (kode lama menolak menyimpan bila `tier[0].min ≠ min` — penyimpanan berikutnya perlu menyesuaikan tier, tetapi **pembacaan & perhitungan biaya tetap benar**) |

---

## 10. Definition of Done (AGENTS.md) — status

| Butir | Status |
|---|---|
| Setiap berkas PHP termodifikasi lolos `php -l` | ✅ 7/7 (§5 P1) |
| Kedua gate i18n exit 0 saat copy berubah | ✅ 602/602 + 0 temuan (§5 P8) |
| Alur diuji lewat HTTP (200/302 sesuai harapan) atau harness CLI rencana | ✅ harness CLI nyata (139 assertion) + smoke HTTP (§5 P9); V4–V11 = **Pending Manual QA** (§6) |
| Perubahan DDL ada di `database.sql` + migrasi idempoten | ✅ **n/a** — plan/110 **tanpa DDL** |
| `plan/NN_*_SUMMARY.md` mencatat perubahan + bukti | ✅ dokumen ini |
| Diff akhir hanya berisi perubahan yang disengaja | ✅ §2 (helper/probe di `/tmp`, `system/` bersih) |
