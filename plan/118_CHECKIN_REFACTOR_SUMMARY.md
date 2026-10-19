# SUMMARY PLAN 118 — DOMPET ABSEN (CHECK-IN BALANCE) & SYARAT SEWA AKTIF

- **Branch:** `feat/118-checkin-refactor`
- **Status:** ✅ COMPLETED & VERIFIED (runtime, pada DB throwaway — bukan `db_webtable`)
- **Plan:** `plan/118_CHECKIN_REFACTOR_PLAN.md`
- **Basis keputusan owner:** `dec-945aaa1f5547c192` (D9 system-only, D10 kartu terkunci + CTA, D11 header pill tetap saldo utama) + D1–D8/D12–D14 turunan di plan
- **Tipe perubahan:** skema (2 kolom + 1 key) + mesin absensi + mesin checkout (split payment) + admin (1 toggle, 5 agregat) + i18n (+12 key) + docs. **Tanpa tabel baru**, **tanpa index baru**, **tanpa route baru**, **tanpa perubahan `system/`**, **tanpa perubahan `views/templates/header.php`**.

---

## 1. Ringkasan dampak

Bonus Absensi Harian berhenti mengalir ke saldo yang dapat ditarik:

1. **Dompet kedua** `users.checkin_balance` ("Saldo Absen") — tidak dapat ditarik, tidak dapat dipindahkan, **tidak** dapat diubah admin; hanya mensubsidi checkout sewa.
2. **Audit multi-dompet** lewat `wallet_ledger.wallet_type ENUM('main','checkin') NOT NULL DEFAULT 'main'` → DEFAULT ini sekaligus menjadi backfill implisit (tanpa script backfill, tanpa pergerakan data; bonus absensi historis tetap di saldo utama).
3. **Syarat sewa aktif** `system_settings.checkin_require_active_rental` (default `'0'` = OFF) → bila `'1'`, klaim menuntut ≥ 1 kontrak aktif (`status='active' AND expired_at > now WIB`, trial ikut).
4. **Split payment** di checkout: dompet checkin dipakai lebih dulu, sisanya dari saldo utama — dua baris debit terpisah dalam satu TX terkunci. `purchase_price` tetap **harga penuh** sehingga ROI, rebate 3-tier, dan omzet upline tidak terpengaruh.

Invarian baru **CK1–CK8** terdaftar di `docs/2_ERD.md` §7.

---

## 2. Berkas

**Baru (2):** `plan/118_CHECKIN_REFACTOR_PLAN.md`, `plan/118_CHECKIN_REFACTOR_SUMMARY.md`. Direncanakan **+1** CLI (`scripts/migrate_118_checkin_refactor.php`).

**Diubah (24):** `database.sql`, `database_seed.sql`, `scripts/reconcile_balances.php`, `application/config/checkin_rewards.php`, `application/models/{Wallet,Checkin,Rental,Admin}_model.php`, `application/controllers/{Checkin,Rentals,Admin,Wallet,Marketplace}.php`, `application/views/{marketplace,wallet,home}/index.php`, `application/views/admin/{settings,dashboard,user_detail}.php`, `application/helpers/i18n_helper.php`, `application/language/{english,indonesian}/app_lang.php`, `docs/{1_PRD,2_ERD,3_ROADMAP}.md`, `AGENTS.md`.

**Tidak disentuh (diverifikasi):** `application/config/routes.php`, `application/config/autoload.php`, `application/views/templates/header.php`, `system/**`.

---

## 3. Perubahan per lapisan

### 3.1 Skema (`database.sql`, `database_seed.sql`)

| Objek | Definisi |
|---|---|
| `users.checkin_balance` | `DECIMAL(15,2) NOT NULL DEFAULT 0.00` (setelah `balance`) |
| `wallet_ledger.wallet_type` | `ENUM('main','checkin') NOT NULL DEFAULT 'main'` (setelah `type`) |
| `system_settings.checkin_require_active_rental` | `'0'` (seed di **kedua** berkas seed) |

+ blok **"Plan 118 — MIGRASI LIVE"** di `database.sql` (SQL referensi, urutan rilis, rollback manual, catatan non-keuangan). **`uk_wallet_ledger_user_tx_type` tidak berubah**, **tanpa index baru** (alasan didokumentasikan di ERD/plan).

### 3.2 `Wallet_model` (choke-point uang)

- Konstanta `WALLET_MAIN`/`WALLET_CHECKIN` + map privat `$_wallet_columns` (whitelist dompet → kolom cache; satu-satunya tempat interpolasi identifier).
- `_post()`/`credit()`/`debit()` menerima `$wallet_type` **opsional** (default `main` → seluruh pemanggil lama tidak berubah); nilai asing → `log_message('error')` + `return false`; baris ledger menulis `wallet_type`; mirror ke kolom cache yang tepat.
- `get_balance($uid, $type = 'main')`, `get_checkin_balance()`, `get_wallet_totals()` (dua dompet satu query).
- **`lock_and_get_balances()`** = implementasi tunggal (anchor `users FOR UPDATE` → **satu** agregat dua-dompet); `lock_and_get_balance()` menjadi **wrapper tipis** → seluruh jalur lama (penarikan, approve deposit, inject admin) otomatis **main-only**.
- Docblock seksi C4 diperluas (CK1–CK4).

### 3.3 `Checkin_model`

- `get_config()` + `require_active_rental`; `validate_checkin_settings()` + key toggle; `get_status()` + `require_active_rental`/`has_active_rental`/`checkin_balance`/`can_claim`.
- `claim()`: gate syarat sewa **di dalam TX**, setelah cabang `already_claimed` (precedence stabil untuk klik ganda) → `code:'no_active_rental'`; kredit memakai `Wallet_model::WALLET_CHECKIN` dengan `transaction_id` `CHK-{uid}-{Ymd}` (idempotensi harian utuh); hasil + `wallet_type` + `new_checkin_balance`.
- `Wallet_model` dimuat paling awal (konstanta dompet dipakai closure `$fail`, termasuk cabang `disabled`).

### 3.4 `Rental_model::checkout_rental($uid, $product, $use_checkin = false)`

- `lock_and_get_balances()` → `checkin_used = min(checkin, harga)`, `main_used = harga − checkin_used` (integer murni + asersi `=== harga`), tolak `insufficient` bila `main_used > fresh_balance`.
- Debit: `…-CHK` (checkin) **lalu** `…-MAIN` (main), masing-masing di-gate `> 0`; ID legacy `RENT-{product_id}-{YmdHis}` dipakai apa adanya bila tidak split. Kegagalan → rollback penuh.
- `purchase_price` tetap penuh; rebate tetap dari harga penuh; trial gratis mengabaikan checkbox (`log_message('debug')`); return + `checkin_used`/`main_used`.

### 3.5 Controller & UI member

- `Checkin`: peta `no_active_rental` → `home_checkin_err_no_rental`; `status` segar pada penolakan; payload sukses + `new_checkin_balance`/`wallet_type`.
- `Rentals`: baca `use_checkin_balance` (`'1'`), pre-check daya beli gabungan (server-side), pesan sukses komposisi via `rental_ok_activated_split`.
- `Marketplace` + `views/marketplace/index.php`: `data-trial` pada tombol sewa, baris checkbox **"Gunakan Saldo Absen"** + nominal tersedia, rincian split, indikator emerald/rose & tombol bayar mengikuti daya beli; checkbox disembunyikan untuk produk trial/harga 0/saldo 0.
- `Wallet` + `views/wallet/index.php`: kartu **Saldo Absen** non-interaktif (tidak dapat ditarik) + chip dompet pada riwayat ledger.
- `views/home/index.php`: baris saldo absen; **state TERKUNCI** (label + ikon kunci + tombol disabled + CTA `/marketplace`) saat syarat sewa belum terpenuhi; `paint()` memperluas state (precedence: "sudah klaim" > "terkunci"). **Header tidak disentuh** (D11).

### 3.6 Admin

- Toggle **"Wajib Punya Sewa Aktif untuk Klaim"** di kartu Absensi Harian (`Admin::settings()` POST/GET + `_settings_form_state()` + `views/admin/settings.php`, 100% Indonesia).
- 5 situs agregat diselaraskan: `get_users()` (subquery `wallet_type='main'`), `get_user_balance()` (+`get_user_checkin_balance()` baru), `get_user_xray()` (+`checkin_balance`), `get_treasury_stats()` (`total_balances` main + `total_checkin_balances` baru, dirender di dashboard), `get_global_analytics()` (tanpa filter + komentar alasan).
- `views/admin/user_detail.php`: baris **Saldo Absen** read-only (tanpa aksi admin — D9).

### 3.7 Rekonsiliasi & migrasi

- `scripts/reconcile_balances.php`: drift **per dompet** (main ↔ `users.balance`, checkin ↔ `users.checkin_balance`), repair per kolom cache, `--json` + `wallets`/`drifted_by_wallet`, fallback mode legacy pra-118, **loader kredensial diperbaiki** (`$active_group → 'default' → grup pertama`) — sebelumnya file ini memakai pola `$db['default']` yang membuat semua mode gagal sebelum koneksi.
- `scripts/migrate_118_checkin_refactor.php`: 7 fase (pre-flight → inspect → DDL → seed idempoten → normalize → guard → verify), `--dry-run`/`--apply`/`--verify`/`--help`, exit 0/1/2, tamper → exit 2.

### 3.8 i18n — 651/651

+12 key: `home_checkin_balance_label`, `home_checkin_cta_rent`, `home_checkin_err_no_rental`, `home_checkin_need_rental_hint`, `market_checkin_available`, `market_checkin_split_note`, `market_checkin_use_label`, `wallet_checkin_note`, `wallet_checkin_title`, `wallet_ledger_chip_checkin`, `rental_ok_activated_split`, `ledger_rent_checkin` (+1 pola `i18n_ledger_description()`: `^Sewa (.+) \(dari Saldo Absen\)$`). Tanpa nominal di kamus (L6); baris debit dompet utama (`Sewa {nama}`) tetap tidak dipetakan (non-goal plan/103).

### 3.9 Dokumen

`docs/1_PRD.md` **v5.3** (catatan versi + §B.7 dompet absen + §D split payment + §E penarikan main-only + §F gate absensi), `docs/2_ERD.md` **v5.3** (mermaid + kolom + `system_settings` + §7 CK1–CK8), `docs/3_ROADMAP.md` (blok **plan/118** + backfill seksi **plan/113/114/115** yang sebelumnya tidak ada), `AGENTS.md` (entri plan/118, daftar model/ledger, jumlah kamus 651, temuan framework `log_message('warning')`, catatan `reconcile_balances` sudah diperbaiki).

---

## 4. Verifikasi yang DIJALANKAN (perintah + hasil)

### V1 — lint (`php -l`)

```
php -l scripts/migrate_118_checkin_refactor.php scripts/reconcile_balances.php \
       application/config/checkin_rewards.php application/models/{Wallet,Checkin,Rental,Admin}_model.php \
       application/controllers/{Checkin,Rentals,Admin,Wallet,Marketplace}.php \
       application/views/{marketplace,wallet,home}/index.php application/views/admin/{settings,dashboard,user_detail}.php \
       application/helpers/i18n_helper.php application/language/{english,indonesian}/app_lang.php
```
**Hasil:** seluruh 21 berkas `No syntax errors detected` (PHP 8.3.6).

### V2 — gate i18n

```
php scripts/audit_i18n_parity.php    → EN keys 651 / ID keys 651, Paritas 1:1 OK,
                                       nilai identik 0, nominal di kamus 0, prosa ID di EN 0 → LULUS (exit 0)
php scripts/audit_i18n_hardcoded.php → File dipindai 92, Total temuan 0 → exit 0
```
Catatan: pemindaian awal menemukan **2 temuan R2-literal-prose** dari **komentar PHP** baru di `views/wallet/index.php` (frasa Indonesia di dalam blok komentar) → komentar ditulis ulang dalam bahasa Inggris (developer-facing) sehingga gate bersih.

### V3 — migrasi pada DB throwaway (fresh + upgrade + idempoten)

DB throwaway dibuat dari `database.sql` lalu `database_seed.sql` (13 users, 179 baris ledger, 30 settings; 49 statement sukses, 8 error duplikat legacy 1060/1061 ditoleransi — sesuai AGENTS.md).

```
# fresh install: kolom sudah ada dari database.sql
php scripts/migrate_118_checkin_refactor.php --verify            → EXIT 0
# skenario UPGRADE (kolom + key di-drop lebih dulu)
php scripts/migrate_118_checkin_refactor.php --dry-run           → EXIT 0 (rencana: 2 ALTER + 1 seed)
php scripts/migrate_118_checkin_refactor.php --apply  (#1)       → EXIT 0 setelah FIX (lihat di bawah)
php scripts/migrate_118_checkin_refactor.php --apply  (#2)       → EXIT 0, "sudah ada (dibiarkan)" + "0 baris tertulis" (idempoten)
```
**FIX yang ditemukan verifikasi ini:** verifikasi pasca-apply sempat gagal `DEFAULT harus 'main' (terdeteksi: '\'main\'')` → MariaDB mengembalikan `COLUMN_DEFAULT` ENUM sebagai literal ber-quote sedangkan MySQL mengembalikannya tanpa quote; guard kini menormalkan kutip (pola yang sama dengan `DEFAULT NULL` di plan/117). Setelah perbaikan: **--apply idempoten (no-op) dan --verify exit 0**.

Struktur kolom terverifikasi pada DB segar:
```
checkin_balance  decimal(15,2)  NOT NULL  DEFAULT 0.00
wallet_type      enum('main','checkin') NOT NULL DEFAULT main
checkin_require_active_rental = 0
```

### V4 — HTTP end-to-end (server lokal + DB throwaway)

Harness di `/tmp` (sess driver `files` + file sesi CI3 di-seed untuk uid tertentu — CAPTCHA login dilewati, sesuai pola "login/CAPTCHA via /tmp harness" di AGENTS.md). Seluruh respons nyata dari endpoint:

| # | Skenario | Hasil teramati |
|---|---|---|
| A | `require_active_rental='1'`, **uid 5 tanpa sewa aktif** | markup `/home`: `data-require-rental="1"`, `data-has-rental="0"`, CTA `href=".../marketplace"`, saldo absen `0`; `POST /checkin/claim` → **200** `{success:false, code:"no_active_rental", wallet_type:"checkin"}` + `status` segar |
| B | `require_active_rental='1'`, **uid 4 punya sewa aktif (berbayar)** | markup `data-has-rental="1"`; `POST /checkin/claim` → **200** `{success:true, reward:50, streak:1, wallet_type:"checkin", new_balance:1000000, new_checkin_balance:50}`; SQL: `users.balance` **tetap 1.000.000**, `checkin_balance 50`, `checkin_streak 1`, `checkin_last_date 2026-10-07`; ledger `CHK-4-20261007 \| credit \| checkin \| 50.00`; klik ulang → `already_claimed` |
| C | **Split payment** uid 4 beli produk Rp 1.000.000 dengan `use_checkin_balance=1` | **303 → /rentals**; ledger **dua baris**: `RENT-1-…-CHK \| debit \| checkin \| 50.00` + `RENT-1-…-MAIN \| debit \| main \| 999950.00`; `users.balance 50.00`, `checkin_balance 0.00`; kontrak `purchase_price = 1.000.000` (**harga penuh**, bukan 999.950) → CK6 terbukti |
| D | **CK3** (penarikan tidak menyentuh dompet checkin) | `Σ main = 50.00 == users.balance`; `Σ checkin = 0.00 == users.checkin_balance`; kredit absensi 50.00 **tidak** masuk penjumlahan `main` |
| E | Regresi `require_active_rental='0'`, uid 5 **tanpa** sewa | markup `data-require-rental="0"` + **0** marker disabled; klaim **sukses** (`code ok`, `wallet_type checkin`, reward 50); `users.balance` tetap 1.200.000 |
| F | **Produk trial gratis** + `use_checkin_balance=1` (D8) | **303 → /rentals**, **0 baris ledger baru**, kontrak `purchase_price 0.00`, `is_trial 1` |
| G | Saldo gabungan **tidak** cukup (uid 4: main 50 / checkin 0) | **303 → /marketplace** (flash insufficient), **0 baris ledger baru**, **0 kontrak baru**, saldo tidak berubah |
| H | Render view | `/wallet` menampilkan kartu **"Bonus Balance"** (EN default); `/marketplace` merender `modalCheckinOption` + label **"Use Bonus Balance"** (checkbox split aktif hanya untuk produk berbayar) |

Asersi konsistensi sesudah seluruh aktivitas:
```
sebaran ledger: main credit 161 / main debit 20 / checkin credit 2 / checkin debit 1
penulis liar (checkin di luar CHK-/RENT-): 0
drift global: main_drift = 0, checkin_drift = 0
```

### V5 — rekonsiliasi per dompet

```
php scripts/reconcile_balances.php --verify     → "Wallet scope: main (balance, filtered), checkin (checkin_balance, filtered)"
                                                 "Drifted accounts (cache <> ledger): 0" → CLEAN, EXIT 0
```

### V6 — grep-guard

```
insert('wallet_ledger'                 → hanya application/models/Wallet_model.php
UPDATE users SET balance|checkin_balance → hanya Wallet_model.php (kolom dari whitelist) + scripts/reconcile_balances.php (tool repair, by design)
FROM wallet_ledger (Admin_model.php)   → 3 situs: 2 difilter/berkomentar, 1 (get_global_analytics) sengaja tanpa filter + komentar
i18n_apply di Admin*/views/admin       → 0 pemanggilan (3 kemunculan = komentar dokumen invarian L1)
git diff --stat routes.php header.php autoload.php system/ → kosong (tidak tersentuh)
```

### Temuan tambahan (bukan bagian plan, dilaporkan)

1. **`log_message('warning', …)` adalah level TIDAK VALID di CI3** (`system/core/Log.php:106` hanya `ERROR/DEBUG/INFO/ALL`) → memicu PHP warning `Undefined array key "WARNING"` yang tercatat di `application/logs/`. Ditemukan saat smoke-test (log harness). Kode plan/118 memakai `debug`/`error`; **2 pemanggil lama** dibiarkan (di luar scope, terdokumentasi di AGENTS.md): `Wallet_model.php:858` (plan/74) & `Rental_model.php:185` (plan/114).
2. **`scripts/reconcile_balances.php` memakai loader `$db['default']`** yang rusak (kelas bug yang sama dengan daftar "known broken CLI") — diperbaiki di plan/118 karena verifikasi V5 membutuhkannya.

---

## 5. Deviasi dari plan

| # | Deviasi | Alasan |
|---|---|---|
| 1 | **12** key i18n baru (plan menyebut 11) → **651/651** | Tambahan `rental_ok_activated_split` untuk pesan sukses checkout yang menyebut komposisi pembayaran; plan §4.2 sendiri menyebut flash sukses komposisi sebagai opsional (nominal tetap argumen `sprintf`, L6). |
| 2 | Helper `log_message('debug')` (bukan `'warning'`) pada jalur trial + checkbox | Menghindari PHP warning framework (temuan §4); perilaku fungsional identik. |
| 3 | Guard `DEFAULT` ENUM menormalkan kutip | Kompatibilitas MySQL ↔ MariaDB (ditemukan verifikasi V3). |
| 4 | `scripts/reconcile_balances.php` juga memperbaiki loader kredensial | Prasyarat agar `--verify` dapat dijalankan sama sekali. |
| 5 | `db_webtable` lokal **tidak** dimigrasikan | AGENTS.md: data lokal tidak boleh diubah saat verifikasi → seluruh uji memakai DB throwaway (sudah di-drop). |

---

## 6. Non-goals (tidak dikerjakan, sesuai plan)

1. Tanpa backfill bonus historis `main → checkin`.
2. Tanpa transfer antar dompet & tanpa jalur admin untuk `checkin_balance`.
3. Tanpa perubahan header/Balance Pill; tanpa route/tabel/index baru; `system/`, `uploads/`, `backups/` tidak tersentuh.
4. Deposit/QRIS, rebate, gaji mingguan, promoter, dan mekanisme penarikan tidak diubah selain memastikan tetap dompet `main`.
5. Baris `wallet_ledger` lama tidak di-`UPDATE` (append-only, Z1).
6. Baris debit dompet utama (`Sewa {nama}`) tetap tidak dilokalisasi (non-goal plan/103).

## 7. Yang BELUM diuji end-to-end (dinyatakan jujur)

- **Cabang "saldo absen menutup seluruh harga"** (`main_used = 0` → hanya satu baris `…-CHK`): terbaca di kode (debit main di-gate `if ($main_used > 0)`) tetapi tidak dapat dieksekusi dengan data seed karena cap absensi harian (≤ Rp 10.000) jauh di bawah harga produk termurah berbayar.
- **Ambang `checkin_balance` drift** pada skenario tamper manual (UPDATE kolom cache langsung) tidak diuji; jalur repair per dompet diuji hanya pada kondisi bersih (`0 drift`).
- Uji dilakukan pada MariaDB 12.3.2 (bukan MySQL 8.4) — perbedaan `COLUMN_DEFAULT` ENUM sudah ditangani (§4 V3).
- Rate limit & CSRF diuji hanya sebagai bagian jalur normal (bukan stress/limit-exceeded).

## 8. Definition of Done

- [x] `php -l` bersih pada semua berkas yang diubah/dibuat.
- [x] Kedua gate i18n exit 0 (651/651, 0 temuan).
- [x] Migrasi idempoten pada DB throwaway (fresh + upgrade, re-run no-op, `--verify` exit 0).
- [x] Alur diuji over HTTP (klaim ok / gate `no_active_rental` / `already_claimed` / split 2 baris / trial tanpa debit / insufficient rollback).
- [x] `reconcile_balances --verify` CLEAN untuk **kedua** dompet.
- [x] Grep-guard bersih; `routes.php`/`header.php`/`autoload.php`/`system/` tidak tersentuh.
- [x] `docs/1_PRD.md` **v5.3**, `docs/2_ERD.md` **v5.3** (+CK1–CK8), `docs/3_ROADMAP.md` (plan/118 + backfill 113/114/115), `AGENTS.md` tersinkron.
- [x] Ringkasan ini memuat perintah + hasil; artefak scratch (`/tmp/*`, DB throwaway, log runtime) dibersihkan.
- [ ] **Menunggu owner:** eksekusi `scripts/migrate_118_checkin_refactor.php --apply` pada DB **live/dev** (urutan rilis: migrasi **sebelum** deploy kode) — di luar lingkup sesi ini karena DB live tidak boleh disentuh dari sandbox.
