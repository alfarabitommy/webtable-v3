# PLAN 114 — SUMMARY: PRODUK TRIAL "GPU MAGANG" + GERBANG PENARIKAN (ANTI FREE-RIDER)

**Status:** ✅ COMPLETED & RUNTIME-VERIFIED (V1–V10 dieksekusi; 2 catatan lingkungan di §6.11–6.12)
**Tanggal:** 2026-09-21
**Plan:** `plan/114_TRIAL_PRODUCT_AND_WD_GATE_PLAN.md`
**Keputusan owner:** `dec-31e078841e9e27e1` — definisi berbayar **literal `is_trial = 0`** · trial **membuka** gating referral · UI WD **pola existing (redirect + flash)**
**Deviasi sadar dari redaksi requirement:** requirement menyebut bypass fungsi `_add_omzet` — **fungsi itu tidak ada di kodebase**. Omzet upline dihitung **derivatif** (`Promoter_model::get_omzet_summary()` = `SUM(ur.purchase_price)`), sehingga kontrak trial bernilai 0 menyumbang 0 otomatis (dibuktikan V8/V8b). Bukan substitusi senyap: tidak ada yang di-bypass karena tidak ada yang perlu di-bypass.

---

## 1. Berkas yang berubah

| # | Berkas | Jenis | Δ | Isi |
|---|---|---|---|---|
| 1 | `plan/114_TRIAL_PRODUCT_AND_WD_GATE_PLAN.md` | baru | 501 baris | Blueprint (dokumen ini = ringkasannya) |
| 2 | `plan/114_TRIAL_PRODUCT_AND_WD_GATE_SUMMARY.md` | baru | — | Ringkasan + matriks bukti ini |
| 3 | `scripts/migrate_114_trial_product_wd_gate.php` | baru | 565 baris | Migrasi CLI 6 fase (`--dry-run`/`--apply`/`--verify`, idempoten, tamper-detect) |
| 4 | `database.sql` | ubah | +84 / −13 | Kolom `is_trial` + seed produk trial (id 9) + catatan MIGRASI LIVE plan/114 |
| 5 | `database_seed.sql` | ubah | +21 / −6 | Baris trial id 9 (+ normalisasi `max_per_user`/`is_trial`) |
| 6 | `application/models/Rental_model.php` | ubah | +120 / −13 | Jalur bebas trial + guard fail-closed + E4b (`max`) + `has_paid_rental()` |
| 7 | `application/models/Wallet_model.php` | ubah | +28 / −2 | Cek 1b `no_paid_rental` di dalam TX + loader `Rental_model` |
| 8 | `application/models/Admin_model.php` | ubah | +35 / −1 | Whitelist kolom + `count_other_trial_products()` + `product_has_paid_rentals()` |
| 9 | `application/controllers/Wallet.php` | ubah | +27 / −0 | `WD_ERR_KEYS['no_paid_rental']` + gatekeeper 2b (GET & POST) + view var |
| 10 | `application/controllers/Rentals.php` | ubah | +9 / −2 | E4b: `quota_exceeded` → `rental_err_max_per_user` |
| 11 | `application/controllers/Admin.php` | ubah | +31 / −2 | Validasi `is_trial` + invarian + 2 guard + payload audit |
| 12 | `application/views/admin/products/index.php` | ubah | +53 / −0 | Checkbox Trial + kunci harga/kuota (JS) + chip TRIAL |
| 13 | `application/views/wallet/index.php` | ubah | +5 / −0 | State tombol WD terkunci |
| 14 | `application/views/marketplace/index.php` | ubah | +14 / −1 | Chip Trial + label harga Gratis |
| 15 | `application/views/rentals/index.php` | ubah | +7 / −0 | Chip kontrak trial |
| 16 | `application/views/help/index.php` | ubah | +1 / −0 | Bullet syarat ke-6 di FAQ penarikan |
| 17 | `application/language/english/app_lang.php` | ubah | +11 / −0 | 6 key baru + 2 komentar |
| 18 | `application/language/indonesian/app_lang.php` | ubah | +11 / −0 | 6 key baru + 2 komentar |

**Tidak disentuh:** `routes.php`, `application/config/**`, `views/wallet/withdraw.php`, `Promoter_model`, `Checkin_model`, `claim_roi()`, `_distribute_rebate()` (logika internal), `Rental_model::create_rental()` (dead code), dan **apa pun di bawah `system/`** (nol perubahan framework).

---

## 2. Skema, seed & migrasi

### 2.1 `gpu_products.is_trial` (1 kolom)

```sql
`is_trial` TINYINT(1) NOT NULL DEFAULT 0 AFTER `max_per_user`,
```

Tanpa backfill (baris lama otomatis 0), tanpa index baru (predikat gerbang terlayani `idx_user_status_expired (user_id, …)` + PK join), tanpa nilai ENUM baru (`user_rentals.source` tidak disentuh).

### 2.2 Produk trial (1 baris)

| Kolom | Nilai | Catatan |
|---|---|---|
| `name` | `GPU Magang (Trial)` | **kunci pencocokan migrasi** (id live ≠ id seed) |
| `type` | `short_term` | — |
| `price` | `0.00` | dijaga: jalur bebas hanya bila `is_trial = 1 AND price = 0` |
| `daily_rate` | `10000.00` | ROI harian (3 hari → potensi Rp 30.000) |
| `duration_days` | `3` | — |
| `max_per_user` | `1` | Strictly 1× per user (GATE 2 existing) |
| `is_active` | `1` | toggle admin tetap sah |
| `is_trial` | `1` | **satu-satunya** baris dengan nilai ini |

### 2.3 Bukti migrasi CLI (DB lokal, layout id menyerupai live)

```
$ php scripts/migrate_114_trial_product_wd_gate.php --dry-run
  kolom gpu_products.is_trial  BELUM ADA | baris trial BELUM ADA | anchor max_per_user ADA
  1. ALTER TABLE `gpu_products` ADD COLUMN `is_trial` TINYINT(1) NOT NULL DEFAULT 0 AFTER `max_per_user`
  2. INSERT … VALUES ('GPU Magang (Trial)', …)   -- id AUTO_INCREMENT
  exit 0

$ … --apply          (pertama)
  gpu_products.is_trial   ditambahkan (TANPA backfill — baris lama = 0)
  baris trial DIBUAT (id 17, price 0, daily_rate 10000, duration_days 3, max_per_user 1)
  [OK] Plan 114 siap: … TEPAT 1 produk trial (id 17, is_active 1).            exit 0

$ … --apply          (re-run → IDEMPOTEN)
  gpu_products.is_trial   sudah ada (dibiarkan)
  baris trial id 17 sudah sesuai (no-op)                                      exit 0

$ … --verify
  kolom gpu_products.is_trial  TINYINT(1) NOT NULL DEFAULT 0 OK
  baris produk trial           TEPAT 1 (is_trial=1, price=0, max_per_user=1, daily_rate=10000, duration_days=3) OK
  guard peta reward promotor   OK (id trial di luar: 5, 6, 7, 8)              exit 0
```

**Guard peta reward terbukti relevan:** pada DB ini `promoter_rewards.php` = id **5,6,7,8**, dan migrasi memberi trial **id 17** (auto-increment) — tepat di luar peta, sehingga produk trial **tidak bisa ditebus** sebagai kontrak reward zero-cost.

**Tamper detection (V10, diuji nyata):**

```
$ UPDATE gpu_products SET is_trial=1 WHERE id=16;   # baris kedua
$ … --verify  → [FATAL] invarian 'TEPAT SATU produk trial' dilanggar: 2 baris (id: 16, 17) — tamper?   exit 2
$ … --apply   → [FATAL] Ditemukan 2 baris `is_trial = 1` (id: 16, 17) … Perbaiki manual … sebelum --apply.  exit 2
                (tool MENOLAK menulis apa pun)
$ UPDATE gpu_products SET is_trial=0 WHERE id=16;   # pulih
$ … --verify  → [OK] Verifikasi plan/114 lulus.                                exit 0
```

### 2.4 Validasi blok seed kanonik (di dalam TX + ROLLBACK, nol persistensi)

| Sumber | Hasil di dalam TX | Setelah ROLLBACK |
|---|---|---|
| `database.sql` (id 1–9) | statements OK, `affected_rows=18`, baris `is_trial=1` → id 9 **dan** 17 | kembali 1 baris (id 17) |
| `database_seed.sql` (id 1–4 + 9) | statements OK, `affected_rows=10`, id 9 `max_per_user=1` ✅ | kembali 1 baris (id 17) |

> ⚠️ **Temuan operasional (bukti di atas, bukan teori).** Kedua blok seed memakai **`id` eksplisit**, jadi menjalankannya pada DB yang sudah berisi lineup berbeda akan **menimpa baris id tersebut**. Pada DB ini id 9 = `RTX 4090 Pro` (produk komersial), sehingga re-seed mentah akan mengubahnya menjadi produk trial **berharga 0** dan menghasilkan **dua** baris `is_trial=1`. Mitigasi yang sudah terpasang: (a) komentar peringatan eksplisit di **kedua** berkas seed ("BLOK INI UNTUK INSTALASI BERSIH / DB DUMMY SAJA"), dan (b) **jalur live wajib memakai migrasi** yang DIKUNCI OLEH `name` (terbukti bekerja: id live 17, bukan 9). Ini kelas risiko yang sudah ada sebelumnya untuk id 1–8; plan/114 tidak memperkenalkan mekanisme baru.

---

## 3. Backend

### 3.1 Jalur bebas checkout — `Rental_model::checkout_rental()`

Snapshot GATE 0 kini menyertakan `p.is_trial`; klasifikasi jalur fail-closed:

| Kondisi baris produk | Perlakuan |
|---|---|
| `is_trial = 1` & `price = 0` | **jalur bebas**: tanpa cek saldo, tanpa `debit()`, tanpa `_distribute_rebate()`; kontrak dibuat `purchase_price = 0` |
| `is_trial = 1` & `price > 0` | jalur **berbayar** normal + `log_message('warning', …)` |
| `is_trial = 0` & `price <= 0` | **TOLAK** `product_unavailable` (fail-closed) |
| lainnya | perilaku existing (zero-regression) |

Alasan teknis wajibnya jalur terpisah: `Wallet_model::_post()` menolak `amount <= 0`, sehingga `debit(…, 0, …)` akan mengembalikan `false` dan membatalkan seluruh checkout — sekaligus menjamin tidak pernah ada baris `wallet_ledger` bernilai 0 (Z1/M8).

**E4b (bug laten yang diperbaiki):** `quota_exceeded` kini menyertakan `max` dan dipetakan ke `rental_err_max_per_user`. Sebelumnya penolakan kuota (termasuk batas trial 1×) hanya menampilkan pesan generik — terbukti dari runtime (V3).

### 3.2 Gerbang penarikan — 3 lapis

| Lapis | Lokasi | Bukti |
|---|---|---|
| 1 | `Wallet::withdraw()` (GET) gatekeeper 2b | V5: HTTP 307 → `/wallet` + flash |
| 2 | `Wallet::process_withdraw()` (POST) gatekeeper 2b | V5: HTTP 303 → `/wallet` + flash |
| 3 | **`Wallet_model::create_withdrawal()` langkah 1b — di dalam TX terkunci**, sebelum kebijakan operasional | V6: `{code:"no_paid_rental"}`, 0 baris tertulis |

Predikat (keputusan owner, literal): `SELECT 1 FROM user_rentals ur JOIN gpu_products p ON p.id = ur.product_id WHERE ur.user_id = ? AND p.is_trial = 0 LIMIT 1` — tanpa filter `status` (semantik "riwayat pernah bertransaksi produk berbayar"). Urutan gate final: pending WD → sewa aktif → **sewa berbayar** → batas harian → binding e-wallet → jam/hari → min/max → saldo → TX model.

---

## 4. UI

| Surface | Perubahan | Bukti runtime |
|---|---|---|
| `/marketplace` | Chip **Trial** + label **Gratis/Free** + harga `Rp 0` (nominal tetap via jalur uang existing — L6) | V1 |
| `/wallet` | State tombol ke-4: **disabled** + ikon gembok + "Perlu Sewa Berbayar" | V4 |
| `/wallet/withdraw` | **Tidak diubah** (keputusan owner D-C: gatekeeper GET/POST mencegah render) | V5 (redirect) |
| `/rentals` | Chip "Kontrak Uji Coba" pada kontrak `purchase_price = 0` | markup ter-render |
| `/help` | Bullet syarat ke-6 (sewa berbayar) di FAQ "Mengapa saya tidak bisa menarik dana?" | markup ter-render |
| `/admin/products` | Checkbox **Produk Trial (harga Rp 0)** + kunci harga/kuota di JS + chip **TRIAL** di tabel | validasi server-side (§5) |

Copy admin 100% Indonesia (invariant L1); nol kunci kamus baru untuk admin.

---

## 5. i18n

6 kunci baru, **paritas 627/627** (dari 621), EN ≠ ID seluruhnya:

| Key | ID | EN |
|---|---|---|
| `wd_err_no_paid_rental` | `Anda harus menyewa minimal 1 produk berbayar untuk dapat melakukan penarikan.` | `You must rent at least 1 paid product before you can make a withdrawal.` |
| `wallet_wd_need_paid` | `Perlu Sewa Berbayar` | `Paid Rental Required` |
| `market_trial_badge` | `Uji Coba` | `Trial` |
| `market_trial_free` | `Gratis` | `Free` |
| `rental_trial_badge` | `Kontrak Uji Coba` | `Trial Contract` |
| `help_q_wd_fail_req6` | `<strong>Sewa berbayar</strong> - …` | `<strong>Paid rental</strong> - …` |

Pesan wajib owner dipertahankan **verbatim** di idiom ID (bebas `Rp`, jadi lolos gate P3). Allowlist P5 **tidak** ditambah.

**Validasi admin (server-side, all-or-nothing):** `is_trial=1` → wajib `price = 0` ("Produk trial wajib berharga Rp 0."), wajib `max_per_user = 1` ("… dibatasi 1 sewa per user."), maksimal satu trial global ("Hanya satu produk trial yang diizinkan."), dan produk yang sudah punya kontrak berbayar tidak boleh dijadikan trial ("… tidak dapat dijadikan trial." — mencegah pencabutan hak penarikan pembeli lama secara retroaktif).

---

## 6. Matriks verifikasi (hasil yang BENAR-BENAR dijalankan)

Lingkungan: PHP 8.3.6 CLI + `php -S 127.0.0.1:8099` (env `DB_*` → DB lokal `db_webtable`, `APP_BASE_URL` lokal; **tanpa** mengubah `application/config/**`) + MariaDB 12.3.2 lokal yang layout id-nya menyerupai live (produk komersial 5–12, reward map 5–8).

| # | Skenario | Perintah / cara | Hasil terukur | Status |
|---|---|---|---|---|
| V0 | Baseline gate i18n **sebelum** perubahan | `php scripts/audit_i18n_parity.php` · `…_hardcoded.php` | 621/621, exit 0; 0 temuan | ✅ |
| V1 | Kartu trial di marketplace | `GET /marketplace` (sesi user trial) | teks kartu: `GPU Magang (Trial) · Rental Limit: Max. 1 (Remaining: 1) · Trial · Rental Price Rp 0 Free · Daily ROI Rp 10.000 · Rent Now` | ✅ |
| V2 | Checkout trial dengan saldo **Rp 0** | `POST /rentals/checkout` (`product_id=17`) | HTTP **303 → /rentals**; `user_rentals` #4: `purchase_price=0.00`, `source=purchase`, `daily_roi=10000`, 3 hari, `active`; `wallet_ledger` **7 → 7** (nol baris); `users.balance` **0.00 → 0.00** | ✅ |
| V3 | Trial kedua (batas 1×) | `POST /rentals/checkout` ulang | HTTP 303 → `/marketplace`; kuota tetap 1 kontrak; flash = `System: the maximum rental limit for this package has been reached (Max. 1).` → **E4b terbukti** (bukan pesan generik) | ✅ |
| V4 | Tombol WD terkunci | `GET /wallet` | tombol `disabled` + `Paid Rental Required`; tautan `wallet/withdraw` = **0** | ✅ |
| V5 | **Free-rider diblokir** (punya saldo Rp 10.000 dari ROI trial, sewa aktif, belum beli) | `GET /wallet/withdraw` → `POST /wallet/process_withdraw` | 307 → `/wallet` dan 303 → `/wallet`; flash `You must rent at least 1 paid product before you can make a withdrawal.`; `withdrawals`(user3) = **0**; `wallet_ledger`(user3) = **1** (hanya ROI); saldo tetap **10000.00** | ✅ |
| V6 | **Otoritas model di dalam TX** | harness CLI (`/tmp`) memanggil `Wallet_model::create_withdrawal(3, 10000, binding)` langsung | `{"success":false,"code":"no_paid_rental", …}`; `withdrawals` 2 → 2, `wallet_ledger` 8 → 8, `balance` 10000 → 10000 | ✅ |
| V7 | Regresi: pembeli berbayar TIDAK diblokir | (a) harness: `create_withdrawal(1, 50000, binding)`; (b) HTTP: `GET /wallet/withdraw` user 1 | (a) `{"success":true,"code":"ok","wd_number":"WD-20260921230610-1"}` + 1 baris pending di dalam TX → **ROLLBACK** → 0 baris; (b) HTTP **200**, `#withdrawForm` ter-render, pesan "paid product" **0**; `/wallet` menampilkan **tautan** WD (bukan disabled) | ✅ |
| V8 | **Rebate & omzet trial = 0** | harness: `checkout_rental(2, {id:17})` dengan rebate aktif (L1 5%/L2 3%/L3 1%), upline = user 1 | `code: ok`, kontrak `purchase_price 0.00`; baris `RBT-*` **0 → 0**; saldo pembeli **50000 → 50000**; omzet upline (`total_l1`) **150000 → 150000**; notifikasi upline **1 → 1** | ✅ |
| V8b | **Kontrol negatif**: pembelian berbayar TETAP memicu rebate/omzet | harness (TX luar + ROLLBACK): `checkout_rental(2, {id:6 /*Rp 300.000*/})` | `RBT-7-L1` = **Rp 15.000** ke upline (5% × 300.000); omzet upline **150000 → 450000**; setelah ROLLBACK baris `RBT-*` = 0 & saldo utuh → bypass plan/114 **spesifik trial** | ✅ |
| V9 | ROI trial lewat mesin klaim existing | backdate `created_at` −1 hari + `POST /rentals/claim/4` | HTTP 303 → `/rentals`; ledger `ROI-4-D1` credit **Rp 10.000**; `users.balance` 0 → 10000; `days_processed` 0 → 1 | ✅ |
| V10 | Migrasi idempoten + tamper | `--dry-run` → `--apply` → `--apply` (no-op) → `--verify` → tamper → `--verify`/`--apply` | exit 0/0/0/0, lalu **exit 2** pada kedua perintah tamper (menolak menulis), lalu exit 0 setelah pulih | ✅ |

### 6.1 Gate wajib (Definition of Done)

| Gate | Perintah | Hasil |
|---|---|---|
| Lint 14 berkas PHP berubah+baru | `php -l <file>` | **semua** "No syntax errors" (`lint_fail=0`) |
| Paritas kamus | `php scripts/audit_i18n_parity.php` | **627/627**, identik 0, P3 0, P6 0, P3b 0 → `[OK] … LULUS`, exit 0 |
| Higienitas string | `php scripts/audit_i18n_hardcoded.php` | 88 berkas dipindai, **0 temuan**, exit 0 |
| Migrasi | `…/migrate_114_trial_product_wd_gate.php --verify` | `[OK] Verifikasi plan/114 lulus.`, exit 0 |

### 6.2 Integritas data pasca-uji

Semua artefak uji dihapus (kontrak trial uji user 2 & 3, baris ledger ROI uji, binding fixture user 3, baris rate-limit) dan cache saldo dipulihkan. Kueri invarian yang sama dengan `scripts/reconcile_balances.php` dijalankan manual:

```
  user #1 cache=200000.00 ledger=200000.00 OK
  user #2 cache=50000.00  ledger=50000.00  OK
  user #3 cache=0.00      ledger=0.00      OK
  user #4 cache=50000.00  ledger=50000.00  OK
  user #5 cache=0.00      ledger=0.00      OK
  TOTAL DRIFT = 0
  rental trial tersisa: 0 | ledger uji tersisa: 0 | binding user3 tersisa: 0 | withdrawals pending: 0
  PRODUK TRIAL (deliverable): id=17 jumlah=1 | kolom is_trial=1
```

### 6.3 Catatan lingkungan & anomali yang ditemukan (jujur, tanpa klaim berlebih)

1. **`scripts/reconcile_balances.php --verify` TIDAK bisa dijalankan** pada konfigurasi ini: `load_db_config()`-nya hanya menerima grup `$db['default']`, sedangkan `database.php` memakai grup `local`/`dev`/`live` → `[FATAL] Cannot read DB credentials from application/config/database.php` (exit 1). **Temuan pra-ada** (tidak disebabkan plan/114). Sebagai gantinya kueri invarian yang identik dijalankan manual (§6.2). Skrip `migrate_*` (termasuk migrasi 114 baru) memakai resolver `$active_group`-aware sehingga **bisa** diarahkan ke DB lokal via env `DB_*` — dan itulah jalur yang dipakai pada semua uji di atas.
2. **Percobaan V7 pertama gagal karena kesalahan harness, bukan bug aplikasi:** amount uji 10.000 < minimum 50.000 → model mengembalikan `below_min`. Justru membuktikan gerbang berbayar **lolos** (kode bukan `no_paid_rental`). Diulang dengan 50.000 → `ok`.
3. **`--verify` memperlakukan `is_active = 0` pada produk trial sebagai `[WARN]`, bukan tamper** — deviasi kecil dari plan §3.5(c) (yang menyebut `is_active=1` sebagai invarian keras). Alasan: mematikan produk adalah aksi admin yang sah (`admin/products/toggle_status`) dan repo ini konsisten tidak pernah membalikkan keputusan admin; exit 2 palsu akan menyesatkan operator. Invarian **struktur** (is_trial, price, max_per_user, daily_rate, duration_days, guard reward map) tetap keras.
4. **`database_seed.sql` diperketat 1 klausa** dibanding plan §3.3: `max_per_user = VALUES(max_per_user)` ditambahkan ke `ON DUPLICATE KEY UPDATE`. Tanpa itu, re-seed pada baris id 9 yang sudah ada meninggalkan `max_per_user = 5` (terbukti di probe TX: `{"id":"9",…,"max_per_user":"5"}`) → invarian trial rusak. Untuk baris 1–4 nilainya 0 (sama seperti sebelumnya) → nol perubahan perilaku.
5. **Verifikasi UI dijalankan sebagai HTML nyata** melalui server PHP built-in + login HTTP; CAPTCHA native dijawab dari penyimpanan sesi (harness `/tmp`, **tanpa** mengubah kode aplikasi). Uji model (V6/V7a/V8/V8b) memakai bootstrap CI3 via route `auth/login` (route yang merender & kembali tanpa redirect) sehingga `Wallet_model`/`Rental_model` dapat dipanggil langsung — pola baru khusus harness, tidak menambah berkas di repo.

---

## 7. Non-goals yang tetap tidak dikerjakan

1. Nol perubahan di bawah `system/`; nol route baru; nol tabel baru; nol nilai ENUM baru; nol kunci `system_settings` baru.
2. `claim_roi()`, `Promoter_model`, `Checkin_model`, `withdraw.php`, dan `routes.php` tidak disentuh.
3. `get_user_rental_stats()` tidak diubah → trial **tetap** membuka gating referral (keputusan owner D-B).
4. Tidak ada gate baru pada deposit/top-up, tidak ada batas "umur akun" untuk klaim trial (semua member aktif, 1× lifetime).
5. E19 (sinkronisasi `docs/1_PRD.md`, `docs/2_ERD.md`, `docs/3_ROADMAP.md`) **ditunda** ke plan terpisah — mengikuti kebiasaan repo (plan/111 & plan/113 memisahkan langkah docs).

---

## 8. Kebersihan & pemulihan lingkungan

- Semua skrip scratch (patch, harness) berada di `/tmp` — **nol** berkas scratch di repo.
- Direktori task sesi (`.reasonix/tasks/desktop-…-bash-1/`) yang terbentuk dari job server background **dihapus**; `git status` bersih dari artefak tak diinginkan.
- Server uji `php -S 127.0.0.1:8099` **dihentikan**; `application/config/**` tidak pernah diubah (tidak ada switch `$active_group`, tidak ada perubahan `base_url`).
- DB lokal dikembalikan ke keadaan pasca-migrasi saja (produk trial id 17 tetap ada sebagai deliverable; seluruh artefak uji dihapus).

---

## 9. Definition of Done

| Kriteria | Status |
|---|---|
| Setiap berkas PHP termodifikasi lolos `php -l` | ✅ 14/14 |
| Kedua gate i18n exit 0 (`LULUS` / `0 temuan`) | ✅ |
| Flow diuji (HTTP 200/302/303/307 sesuai harapan) + harness CLI | ✅ V1–V10 |
| DDL ada di `database.sql` + migrasi idempoten (re-run = no-op) + `--verify` exit 0 | ✅ |
| Diff akhir hanya berisi perubahan yang diniatkan | ✅ (15 ubahan + 2 berkas baru; nol artefak) |
| Ringkasan mencatat perubahan + bukti + catatan lingkungan apa adanya | ✅ (dokumen ini) |

---

## 10. Tindak lanjut yang disarankan (opsional, di luar plan/114)

1. **O1 (celah diterima owner):** kontrak reward promotor (`purchase_price = 0`) atas produk non-trial ikut membuka gerbang penarikan. Bila kelak ingin ditutup: tambah `AND ur.purchase_price > 0` pada `has_paid_rental()` (satu baris + 1 kueri).
2. **O5 (pra-ada):** perbaiki `load_db_config()` di `scripts/reconcile_balances.php` agar selaras dengan resolver `$active_group`-aware (pola `migrate_112`/`migrate_114`) supaya verifier drift bisa dijalankan di DB non-`default`.
3. **O6:** pertimbangkan mengganti id eksplisit blok seed `gpu_products` (mis. memakai `INSERT … ON DUPLICATE KEY UPDATE` DIKUNCI `name`) agar menjalankan `database.sql`/`database_seed.sql` pada DB terisi tidak berisiko menimpa produk komersial.
4. **O7:** E19 — sinkronkan `docs/1_PRD.md` (produk trial + gerbang WD), `docs/2_ERD.md` (kolom `is_trial`), `docs/3_ROADMAP.md` (milestone plan/114).
