# Plan 102 — Manual QRIS Gateway dengan Kode Unik 3 Digit & Verifikasi Admin

> **Status:** BLUEPRINT (dokumen arsitektur SAJA). Belum ada satu pun perubahan
> kode aplikasi, skema DB, controller, model, route, view, atau kamus bahasa
> yang dilakukan oleh dokumen ini. **Deliverable round ini = file ini saja**
> (`plan/102_MANUAL_QRIS_UNIQUE_CODE_PAYMENT_PLAN.md`); eksekusi implementasi
> MENUNGGU instruksi lanjutan terpisah dari pemilik repositori (dokumen akan
> dianalisis terlebih dahulu).
>
> **Keputusan pemilik repositori (sudah dikonfirmasi, mengikat desain):**
>
> | # | Keputusan |
> |---|---|
> | **D1** | **Auto-expiry 60 menit HANYA untuk status `pending`.** Begitu masuk `waiting_approval`, baris **tidak pernah** kedaluwarsa otomatis — reservasi kode ditahan sampai admin approve/reject. Mencegah member yang transfer di menit 59 kehilangan invoice-nya. |
> | **D2** | **Rentang kode unik 100–999** (900 kode, tanpa leading zero → tidak ada ambiguitas "045 vs 45"). |
> | **D3** | **Deposit fee M1 dipertahankan:** fee ON → `total_amount = pokok + fee + kode`, kredit = `pokok + kode` (fee ditahan platform, zero-dilution plan/56). Fee OFF (`deposit_fee_enabled='0'`, default produksi) → `total_amount = pokok + kode` **dan** kredit = `total_amount` = **Option A persis**. |
> | **D4** | **Admin boleh approve dari `pending` MAUPUN `waiting_approval`** (row legacy tanpa kode + kasus "member lupa klik" tetap bisa diselesaikan). Dashboard menandai row `pending` sebagai *belum konfirmasi*. |
>
> **Ruang lingkup:** Gateway deposit **manual** (tanpa payment gateway/webhook):
> member memesan nominal pokok → sistem mengalokasikan **kode unik 3 digit**
> sehingga nominal transfer menjadi unik dan dapat diatribusikan
> (`Rp150.000 + 234 = Rp150.234`) → member transfer via QRIS statis di luar
> sistem → member menekan **"Saya Sudah Transfer"** → admin memverifikasi mutasi
> bank/QRIS secara manual → approve (kredit) / reject (release reservasi).

---

## 1. Ringkasan & Tujuan

### 1.1 Masalah yang diselesaikan

Kondisi saat ini (HEAD): deposit adalah **invoice tanpa arti nominal** —
`deposits.amount` apa adanya, status hanya `pending|success|failed`, dan
satu-satunya jalur pelunasan adalah **simulator dev** (`Wallet::simulate_payment`).
Konsekuensinya di produksi:

1. Tidak ada cara memverifikasi mutasi bank secara deterministik: dua member
   yang sama-sama transfer Rp150.000 tidak bisa dibedakan satu sama lain.
2. Tidak ada antrean kerja untuk admin (hanya daftar `pending` lalu tombol
   Approve), sehingga tidak ada state "member sudah bayar, menunggu verifikasi".
3. Tidak ada batas waktu → invoice pending menumpuk selamanya dan menahan
   nomor invoice tanpa konsekuensi.
4. Tidak ada kanal konfigurasi QRIS (gambar/merchant/instruksi) di admin.

### 1.2 Solusi

| Kapabilitas | Implementasi |
|---|---|
| Nominal unik | `deposits.unique_code` (100–999) + `total_amount` (dibekukan saat create) |
| Anti-tabrakan | **Unique index DB** pada kolom reservasi nullable `reserved_code_key` (`"{pokok}-{kode}"`, `NULL` saat tidak aktif) |
| Antrean admin | Status baru `waiting_approval` + panel deposit di Command Center + alert center existing (poll 25 dtk) |
| Batas waktu | `expires_at` +125 sweep lazy (pola M3, tanpa cron) + countdown client |
| Settlement | Approve atomik: kredit `total_amount` → `wallet_ledger` + cache + audit + notifikasi |
| Konfigurasi | Tab/kartu "Pembayaran QRIS Manual" di `/admin/settings` (gambar QRIS, merchant, instruksi, kebijakan deposit) |

### 1.3 Kriteria sukses (definition of done)

1. Setiap deposit baru membawa `unique_code`, `total_amount`, `expires_at`,
   dan **reservasi kode yang ditegakkan DB** — dua deposit aktif dengan pokok
   yang sama mustahil berbagi kode.
2. Siklus `pending → waiting_approval → success | rejected | expired`
   ditegakkan lewat **UPDATE kondisional atomik**; tidak ada double credit,
   tidak ada state flip ganda, tidak ada kredit untuk baris kedaluwarsa.
3. Approve mengkredit `total_amount` (**Option A**) tepat satu baris
   `wallet_ledger` immutable via `Wallet_model::credit()`, plus audit + notifikasi.
4. Guard deposit aktif tunggal, jendela bayar 60 menit dengan countdown client,
   dan pelepasan kode saat expired/rejected — semuanya otoritatif di server
   (bound param WIB PHP, **tidak pernah** MySQL `NOW()`).
5. Admin dapat mengonfigurasi gambar QRIS / nama merchant / instruksi /
   kebijakan deposit di System Settings (panel admin tetap **100% Indonesia**,
   invarian L1).
6. Halaman pembayaran member (`/wallet/pay/{invoice}`, dwibahasa EN/ID,
   mobile-first `max-w-[480px]`) menampilkan QR, merchant, nominal **tepat**
   dengan kode 3 digit yang ditonjolkan + tombol Copy, countdown hidup,
   tombol "Saya Sudah Transfer", dan indikator status 5 keadaan.

---

## 2. Fakta Codebase yang Menjadi Dasar Desain

> Nomor baris mengacu ke HEAD saat dokumen ditulis; nomor dapat bergeser saat
> edit — verifikasi ulang sebelum patch.

### 2.1 Kondisi `deposits` saat ini (`database.sql:169–186`)

```sql
CREATE TABLE IF NOT EXISTS `deposits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `invoice_number` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `status` ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invoice_number` (`invoice_number`),
  INDEX `idx_user_status` (`user_id`, `status`),
  INDEX `idx_status_created` (`status`, `created_at`),
  CONSTRAINT `fk_deposits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
)
```

Catatan penting: `failed` **tidak pernah** ditulis oleh kode mana pun saat ini
(dipertahankan hanya untuk retensi + listing history `status IN ('success','failed')`).

### 2.2 Pola existing yang ditiru (jangan reinvent)

| Kebutuhan plan ini | Pola existing yang dipakai ulang | Lokasi acuan |
|---|---|---|
| Invoice idempoten + retry duplicate key | Loop 3× `strtoupper(bin2hex(random_bytes(3)))` + retry kode 1062/23000 | `Wallet_model::create_deposit()` (`:605`) |
| Kunci serialisasi mutasi uang | `lock_and_get_balance()` = `SELECT ... FOR UPDATE` anchor `users` sebagai statement pertama di TX | `Wallet_model` (`:463`), audit C5 plan/48 |
| Satu-satunya jalur tulis uang | `credit()`/`debit()` → `_post()` choke point (integer positif, ledger + cache relatif) | `Wallet_model` (`:519`,`:539`) |
| Transisi status anti-replay | UPDATE kondisional + gerbang `affected_rows() === 1` | `Admin_model::approve_deposit()` (`:390`) |
| Penolakan dengan alasan | `decline_withdrawal()`: `mb_substr(trim($r),0,255)`, persist `decline_reason`, audit + notifikasi | `Admin_model` (`:509`), `Admin::decline_withdrawal()` (`:198`) |
| Expiry tanpa cron | Sweep lazy per-request ber-index, autocommit, idempotent | `Rental_model::expire_user_rentals()` dipanggil `MY_Controller` (`:66–67`), M3 plan/60 |
| Waktu otoritatif | PHP WIB (`Asia/Jakarta` di `index.php`) + `SET time_zone='+07:00'`; bound param, tidak `NOW()` | `Wallet_model::__construct()` (`:26`), `MY_Controller` (`:24`), M2 plan/58 |
| Validasi integer IDR | Regex `^[1-9][0-9]*$` sebelum masuk model; `intdiv()` untuk fee | `Wallet::topup()` (`:54`), `Wallet::process_withdraw()` (`:229`), M8 plan/74 |
| Config dinamis + fallback | `system_settings` menang per-key, `application/config/withdrawal_fees.php` sebagai fallback, nilai rusak → log + fallback | `Wallet_model::get_financial_config()` (`:47`) |
| Upload file | CI3 `upload` library: `encrypt_name`, `detect_mime`, `allowed_types`, unlink lama setelah sukses | `Profile::update()` (`:37–59`) |
| Baca setting di controller member | `Admin_model::get_setting('wa_number')` | `Help::index()` (`:12`) |
| Envelope JSON | `api_success($data, 'ok', 200, $legacy)` + `Cache-Control: no-store` | `Admin::alerts_poll()` (`:42`), helper `api_helper.php`, M9/P7 plan/76 |
| Validator setting admin | `validate_financial_settings()` / `validate_rebate_settings()` → `{ok, values, errors}` + all-or-nothing | `Wallet_model` (`:235`), `Admin::settings()` (`:304`) |
| Rate limit | `Rate_limit_model::check($key,$max,$lockout)` + `hit()` + helper `rate_limit_json_response()` | `Wallet::process_withdraw()` (`:186–195`) |
| Audit dalam TX | `_write_audit()` dipanggil di dalam transaksi yang sama | `Admin_model` (`:22`) |

### 2.3 Titik gesek (temuan) yang wajib ditangani plan ini

| # | Temuan | Dampak bila dibiarkan | Penanganan |
|---|---|---|---|
| **G1** | `Wallet::index()` (`:37–40`) **menghitung ulang** `deposit_fee`/`total_payable` saat render | Nominal yang dilihat member bisa **berbeda** dari nominal yang diverifikasi admin begitu setting fee berubah di tengah siklus invoice (sumber sengketa) | Ganti ke `total_amount` yang **dibekukan** di baris deposit |
| **G2** | `Admin_model::get_alert_counts()` (`:65–76`) menghitung `status='pending'` saja | Setelah member konfirmasi (`waiting_approval`), badge alert **turun** padahal deposit masih menunggu aksi admin → antrean "hilang" dari radar | Hitung `status IN ('pending','waiting_approval')` (nama key & envelope tetap) |
| **G3** | `count_history_deposits()`/`get_history_deposits()` filter `IN ('success','failed')` | Deposit `rejected`/`expired` tidak pernah muncul di halaman history admin dan `total` paginasi ≠ jumlah baris | Perluas filter kedua method **bersamaan** |
| **G4** | `Admin::index()` (`:83–88`) menaruh SQL query deposit di controller | Melanggar invariant "semua akses DB di model" (AGENTS.md) | Pindahkan ke `Admin_model::get_deposit_queue()` |
| **G5** | Dashboard hanya punya tombol Approve untuk deposit (tidak ada Reject) | Admin tidak bisa menolak deposit yang salah nominal/tidak ditemukan | Tambah `Admin::decline_deposit()` + `Admin_model::decline_deposit()` |
| **G6** | `Wallet::topup()` menerima nominal apa pun (`>0`) | Nominal absurd (Rp1) memperbesar ruang kode & membuat verifikasi manual tak masuk akal | `deposit_min_amount`/`deposit_max_amount` (key baru) |
| **G7** | `uploads/avatars/` tidak punya placeholder sehingga direktori tidak ada di clone bersih | Upload gagal senyap pada instalasi baru | `uploads/qris/index.html` (tracked) + `.gitignore` untuk isi runtime |
| **G8** | `database_seed.sql` tidak memuat key rebate plan/89 yang ada di `database.sql` | Drift seeder (bukan bagian plan ini) | Dicatat §13.3 sebagai drift opsional, **tidak** dikerjakan tanpa persetujuan |

---

## 3. Skema Basis Data

### 3.1 `deposits` — kolom baru, enum diperluas, index baru

```sql
-- DDL kanonik (fresh install) di database.sql; ALTER satu kali untuk live DB.
ALTER TABLE `deposits`
  MODIFY `status` ENUM('pending','waiting_approval','success','failed','rejected','expired')
         NOT NULL DEFAULT 'pending',
  ADD COLUMN `unique_code`       SMALLINT UNSIGNED  NULL DEFAULT NULL AFTER `amount`,
  ADD COLUMN `total_amount`      DECIMAL(15,2)      NOT NULL DEFAULT 0.00 AFTER `unique_code`,
  ADD COLUMN `reserved_code_key` VARCHAR(24)        NULL DEFAULT NULL AFTER `total_amount`,
  ADD COLUMN `expires_at`        TIMESTAMP          NULL DEFAULT NULL AFTER `reserved_code_key`,
  ADD COLUMN `confirmed_at`      TIMESTAMP          NULL DEFAULT NULL AFTER `expires_at`,
  ADD COLUMN `processed_at`      TIMESTAMP          NULL DEFAULT NULL AFTER `confirmed_at`,
  ADD COLUMN `decline_reason`    VARCHAR(255)       NULL DEFAULT NULL AFTER `processed_at`,
  ADD UNIQUE KEY `uk_reserved_code_key` (`reserved_code_key`),
  ADD INDEX      `idx_status_expires`   (`status`, `expires_at`);
```

| Kolom | Tipe | Semantik |
|---|---|---|
| `unique_code` | `SMALLINT UNSIGNED NULL` | Kode 3 digit `100–999`. Disimpan permanen untuk audit/history (tidak dibuang setelah selesai). |
| `total_amount` | `DECIMAL(15,2) NOT NULL DEFAULT 0.00` | **Nominal bayar yang dibekukan** saat create = `pokok + [fee] + kode`. Otoritatif untuk verifikasi admin **dan** untuk nilai kredit. Tidak pernah dihitung ulang saat render (menutup **G1**). `DECIMAL(15,2)` dipertahankan untuk kompatibilitas non-breaking (M8); PHP selalu `(int)`. |
| `reserved_code_key` | `VARCHAR(24) NULL` + **UNIQUE** | `"{pokok_int}-{kode}"` (mis. `150000-234`) **selama reservasi hidup**; `NULL` saat baris keluar dari `pending`/`waiting_approval`. Lihat §3.2. |
| `expires_at` | `TIMESTAMP NULL` | `created_at + deposit_expiry_minutes` (default 60) dalam WIB. |
| `confirmed_at` | `TIMESTAMP NULL` | Diisi saat `pending → waiting_approval`. |
| `processed_at` | `TIMESTAMP NULL` | Diisi saat `success` / `rejected` / `expired` (parity `withdrawals.processed_at`). |
| `decline_reason` | `VARCHAR(255) NULL` | Alasan penolakan opsional (parity `withdrawals.decline_reason`). |
| `status` | ENUM diperluas | Tambah `waiting_approval`, `rejected`, `expired`. `failed` **dipertahankan** untuk retensi baris lama. |

**Index & rasionalnya**

| Index | Status | Melayani |
|---|---|---|
| `idx_user_status (user_id, status)` | existing | guard deposit aktif tunggal (`status IN ('pending','waiting_approval')`) |
| `uk_reserved_code_key (reserved_code_key)` | **baru** | eksklusivitas keras + scan tabrakan via prefix `LIKE '150000-%'` (range pada unique index — **tidak** perlu index tambahan) |
| `idx_status_expires (status, expires_at)` | **baru** | sweep expiry lazy (leading `status`, konvensi sama dengan `idx_status_created` plan/94) |
| `idx_status_created (status, created_at)` | existing | listing antrean admin + COUNT alert center |

### 3.2 Rasional `reserved_code_key` (dan alternatif yang **ditolak**)

Kebutuhan: *"kode unik tidak boleh bertabrakan dengan deposit aktif (pending/
waiting_approval) lain dengan pokok yang sama, dan kode harus **bebas dipakai
ulang** setelah expired/rejected."*

MySQL 8 **tidak punya partial index**, tetapi InnoDB mengizinkan **banyak `NULL`**
pada unique index. Maka satu kolom nullable yang berisi kunci komposit
(`"{pokok}-{kode}"` bila aktif, `NULL` bila tidak) memberi **jaminan di level
DB**: paling banyak satu baris hidup per pasangan (pokok, kode), sementara baris
tertutup berapa pun jumlahnya tetap aman.

| Alternatif | Alasan ditolak |
|---|---|
| `UNIQUE (amount, unique_code)` | Kode terbakar permanen — tidak bisa dipakai ulang setelah expiry (melanggar spek) |
| `UNIQUE (amount, unique_code, status)` | **Landmine**: dua baris historis `expired` dengan pokok+kode sama akan bentrok saat *transisi status*, bukan saat insert (error muncul di jalur yang salah, sulit direproduksi) |
| Tabel reservasi terpisah | Mesin tambahan (join, TX, backfill) tanpa manfaat dibanding satu kolom |
| Hanya cek `SELECT` sebelum insert | TOCTOU: dua request paralel bisa memilih kode yang sama (tidak ada jaminan) |

### 3.3 `system_settings` — key baru + default

Key-value store tunggal (`system_settings`, M7 plan/70). Semua key di-seed
**idempoten** (`INSERT IGNORE`) di **dua** file: `database.sql` (§seed) dan
`database_seed.sql`.

| Key | Default | Validasi server (otoritatif) |
|---|---|---|
| `qris_image` | `''` | nama file saja: `/^[a-z0-9]{32}\.(png\|jpe?g)$/i` (output `encrypt_name` CI3) |
| `qris_merchant_name` | `'Synapse'` | trim, 1–100 karakter, di-escape saat render |
| `qris_payment_instructions` | teks instruksi multi-baris (ID) | trim, ≤ 2000 karakter, render `nl2br(html_escape())` |
| `deposit_expiry_minutes` | `'60'` | integer 5–1440 |
| `deposit_min_amount` | `'10000'` | integer ≥ 1 (menutup **G6**) |
| `deposit_max_amount` | `'50000000'` | integer > min dan ≤ 1e9 (parity `wd_max_amount`) |

- Tiga key **kebijakan** (`deposit_expiry_minutes`, `deposit_min_amount`,
  `deposit_max_amount`) mendapat **fallback** di
  `application/config/withdrawal_fees.php` (file itu sudah memiliki key deposit
  fee), dengan semantik M1: nilai dinamis rusak → `log_message` + fallback per key.
- Key `qris_*` adalah **konten tampilan** → tanpa fallback file; gambar kosong =
  **fail-closed** (§7.1): member tidak boleh diminta transfer ke tujuan tak dikenal.

### 3.4 Migrasi live DB, backfill, dan sinkronisasi file seed

| Langkah | Detail |
|---|---|
| Kanonik | `database.sql`: blok `CREATE TABLE deposits` diperbarui (kolom + enum + index) dan blok seed `INSERT IGNORE` ditambah 6 key baru |
| Seed | `database_seed.sql`: 6 key yang sama ditambahkan idempoten |
| Catatan migrasi | Blok "Live DB migration notes" di bagian bawah `database.sql` (pola plan/94, `:378–394`) ditambah ALTER satu kali plan/102 + peringatan `ADD COLUMN` tidak idempoten (MySQL 8 tidak punya `IF NOT EXISTS`; MariaDB punya) |
| Tool migrasi | **`scripts/migrate_102_qris_deposits.php`** — CLI, `--dry-run` (default) / `--apply`, meniru `scripts/migrate_m7_settings.php`: baca kredensial dari `application/config/database.php`, cetak rencana DDL + backfill, `--apply` menjalankan DDL lalu backfill berurutan, verifikasi via `information_schema` sebelum menyatakan sukses. Exit code 0/1/2 |
| Backfill 1 | `UPDATE deposits SET total_amount = amount WHERE total_amount = 0;` → parity kredit untuk baris lama (dulu memang dikredit sebesar `amount`) |
| Backfill 2 | `UPDATE deposits SET expires_at = <WIB now + 60 menit> WHERE status='pending' AND expires_at IS NULL;` → memberi jendela baru; deposit pending produksi **tidak** langsung tersapu |
| Verifikasi | `SHOW CREATE TABLE deposits` (kolom/enum/index sesuai) + query invarian §3.5 mengembalikan `0` |

### 3.5 Query invarian (untuk operator/QA)

```sql
-- Reservasi hidup ⟺ status aktif. WAJIB selalu 0.
SELECT COUNT(*) AS inconsistent
  FROM deposits
 WHERE (status IN ('pending','waiting_approval')) <> (reserved_code_key IS NOT NULL);

-- Tidak boleh ada dua reservasi hidup dengan kunci sama (dijamin unique index,
-- query ini untuk membuktikan index benar-benar terpasang).
SELECT reserved_code_key, COUNT(*) c
  FROM deposits WHERE reserved_code_key IS NOT NULL
 GROUP BY reserved_code_key HAVING c > 1;
```

---

## 4. Algoritma Alokasi Kode Unik & Konkurensi

### 4.1 Algoritma (`Wallet_model::create_deposit()`, ditulis ulang; signature tetap)

```
INPUT  : $user_id, $amount (int, sudah divalidasi ^[1-9][0-9]*$ di controller)
CONFIG : policy = get_deposit_policy()   // expiry_minutes, min_amount, max_amount

for attempt = 1..3:                        // SETIAP attempt = transaksi sendiri
  trans_begin()
   1. lock_and_get_balance($user_id) === false
        -> rollback, code 'error'        // anchor lock + validasi baris users
   2. used = SELECT unique_code FROM deposits
                WHERE reserved_code_key LIKE "{amount}-%"
      // range scan pada uk_reserved_code_key (hanya reservasi HIDUP yang terlihat)
   3. COUNT(deposits WHERE user_id = ? AND status IN ('pending','waiting_approval')) > 0
        -> rollback, code 'pending_exists'                 // GUARD DEPOSIT AKTIF TUNGGAL
   4. free = [100..999] \ used ;  free kosong
        -> rollback, code 'code_exhausted'
   5. code = free[ random_int(0, count(free) - 1) ]        // CSPRNG, tanpa modulo bias
   6. fee   = calculate_deposit_fee($amount)
      total = $amount + $fee + $code
      invoice = 'INV-{YmdHis}-{user_id}-{6 hex}'            // format M4 existing
      INSERT deposits(
        user_id, invoice_number, amount,
        unique_code       = code,
        total_amount      = total,
        reserved_code_key = "{amount}-{code}",
        expires_at        = <WIB now + expiry_minutes>,
        status            = 'pending')
   7. duplicate key 1062/23000 -> rollback, continue loop   // kode direbut TX lain
      error DB lain            -> rollback + log, code 'error'
  trans_commit()
  return {success:true, invoice_number, unique_code:code, total_amount:total, expires_at}
return {success:false, code:'code_conflict'|'error'}
```

### 4.2 Mengapa race-safe

| Skenario balapan | Mekanisme penjaga |
|---|---|
| **Dua user berbeda, pokok sama, bersamaan** | `users FOR UPDATE` hanya menyerialkan per-user, jadi penjaga lintas-user adalah **`uk_reserved_code_key`**: insert kedua kalah dengan duplicate key 1062 → rollback → ulangi attempt dengan kandidat yang dibaca ulang. (Pola retry yang sama dengan `create_deposit` M4 plan/62 — bukan mesin baru.) |
| **Double submit user yang sama** | Terserialisasi oleh anchor lock `users`. `COUNT` di langkah 3 adalah **consistent read pertama setelah lock wait**, sehingga melihat insert yang sudah di-commit TX pertama (alasan identik dengan `lock_and_get_balance`, plan/48 §3.1) → `pending_exists`. |
| **TOCTOU (baca dulu, tulis kemudian)** | SELECT di langkah 2 hanya optimasi "sekali jadi"; **kebenaran** bertumpu pada unique index, bukan pada SELECT. |
| **Pelepasan kode** | `reserved_code_key = NULL` ditulis dalam **UPDATE kondisional yang sama** dengan transisi terminal (`expired`/`rejected`/`success`) → status dan reservasi tidak mungkin drift. |
| **Deadlock** | Semua jalur mengambil lock baris `users` lebih dulu, lalu hanya menyentuh baris `deposits` miliknya sendiri — tidak ada inversi urutan lock, tidak ada kelas deadlock baru. |
| **Pembacaan waktu** | Semua guard waktu memakai bound param WIB PHP (`date('Y-m-d H:i:s')`), **tidak pernah** `NOW()` MySQL (invarian M2/M3). |

### 4.3 Ruang kode, kehabisan kode, dan batas nominal

- **Ruang kode:** 900 kode per pokok (100–999).
- **Exhaustion:** terjadi hanya bila >900 user berbeda masing-masing menahan
  deposit hidup dengan **pokok persis sama** (guard aktif-tunggal bersifat
  per-user, bukan global). Perilaku: `code_exhausted` → pesan "Kuota kode unik
  untuk nominal ini penuh; silakan pakai nominal lain atau hubungi admin."
  **Fail-closed, terlihat, dan bisa dipulihkan** (admin reject baris basi →
  kode bebas).
- **Batas nominal** (`deposit_min_amount`/`deposit_max_amount`) menjaga ruang
  kode tetap rapat dan mempersempit nominal aneh (kode `below_min`/`above_max`).
- **Nominal murni integer** (M8) → prefix `reserved_code_key` eksak, tanpa
  ambiguitas pecahan.

### 4.4 Kebijakan kredit (D3 — Option A) dan interaksi deposit fee

| Kondisi | `total_amount` (yang diverifikasi & ditransfer) | Nilai kredit ke dompet | Catatan |
|---|---|---|---|
| `deposit_fee_enabled = '0'` (**default produksi**) | `pokok + kode` (mis. 150234) | **`total_amount`** (150234) | = **Option A persis**: seluruh nominal transfer dikreditkan |
| `deposit_fee_enabled = '1'` (flat/percent) | `pokok + fee + kode` (mis. 155234) | `pokok + kode` (150234) | Fee **ditahan platform** — zero-dilution, semantik M1 plan/56 tidak berubah |

Ledger: `transaction_id = invoice_number`, `type = 'credit'`, `amount = (int) $credit_amount`,
`description = 'Top Up via ' . invoice_number`. Unique key
`uk_wallet_ledger_user_tx_type (user_id, transaction_id, type)` membuat kredit
**natural-idempoten** (replay tidak bisa menulis baris kedua).

---

## 5. State Machine & Guards

### 5.1 Diagram transisi

```
                     confirm_payment (member, POST + CSRF)
       ┌────────────────────────────────────────────────────────┐
       │  guard: status='pending' AND expires_at > now(WIB)     │
       ▼                                                        │
  ┌─────────┐                                          ┌──────────────────┐
  │ pending │                                          │ waiting_approval │
  └────┬────┘                                          └───┬──────────┬───┘
       │ sweep lazy: expires_at <= now (HANYA pending, D1)  │          │
       │                                                   │          │
       │            admin decline (POST + alasan)          │          │
       │   ┌───────────────────────────────────────────────┘          │
       │   │      guard: status IN (pending, waiting_approval)        │
       ▼   ▼                                                          │
  ┌─────────┐ ┌──────────┐        admin approve (POST)                │
  │ expired │ │ rejected │  ◄───────────────────────────────────┐     │
  └─────────┘ └──────────┘                                      │     │
   kode bebas  kode bebas              guard: status IN (pending, waiting_approval)
   tanpa uang  tanpa uang                     AND (expires_at IS NULL OR expires_at > now)
                                                               │
                                                               ▼
                                                          ┌─────────┐
                                                          │ success │  kredit total_amount
                                                          └─────────┘  kode bebas + notifikasi

TERMINAL : success | rejected | expired
RESERVASI HIDUP : pending | waiting_approval
```

### 5.2 Tabel transisi (aktor · guard · efek · audit)

| Transisi | Aktor | Guard (semua server-side, bound param WIB) | Efek samping | Audit |
|---|---|---|---|---|
| create → `pending` | member | integer `^[1-9][0-9]*$`, min/max, tidak ada deposit hidup, QRIS terkonfigurasi, rate limit | INSERT invoice + reservasi kode + `total_amount` + `expires_at` | — (aksi member) |
| `pending` → `waiting_approval` | member (`confirm_deposit`) | kepemilikan + `status='pending'` + `expires_at > now`; `affected_rows()===1` | `confirmed_at = now`; reservasi **tetap** | — |
| `pending` → `expired` | sweep lazy | `user_id` + `status='pending'` + `expires_at <= now` (satu UPDATE ber-index, autocommit) | `processed_at`, `reserved_code_key = NULL` | — |
| `pending`/`waiting_approval` → `success` | admin | status IN keduanya; `expires_at` masih depan (atau `NULL` untuk legacy) → **baris expired tidak pernah bisa di-approve** | `processed_at`, `reserved_code_key = NULL`, **kredit `total_amount`**, `users.balance ±` dalam TX yang sama, notifikasi pasca-commit | `approve_deposit` (before/after) |
| `pending`/`waiting_approval` → `rejected` | admin | status IN keduanya; alasan opsional ≤255 | `decline_reason`, `processed_at`, `reserved_code_key = NULL`, **tanpa** mutasi uang | `decline_deposit` (+ alasannya) |
| `waiting_approval` lewat jendela | — | **tanpa transisi otomatis (D1)**; dashboard menampilkan chip "konfirmasi terlambat" agar operator memprioritaskan | — | — |

### 5.3 Guards & rate limit

| Guard | Implementasi |
|---|---|
| Deposit aktif tunggal | Otoritatif di `create_deposit()` (langkah 3) + mirror UX di `Wallet::index()`/`topup()` via `has_active_deposit()`; pesan: "Selesaikan deposit aktif Anda terlebih dahulu." |
| QRIS fail-closed | `Wallet::topup()` menolak bila `qris_image` kosong (`qris_unavailable`) — member tidak boleh diarahkan transfer ke tujuan tak dikenal. Simulator dev tetap satu-satunya jalur uji tanpa QRIS |
| Rate limit | `deposit:{user_id}` = maks 5 / 900 dtk (create); `deposit_confirm:{user_id}` = maks 10 / 900 dtk (confirm). Throttle → flashdata (+ `rate_limit_json_response()` saat AJAX) |
| Kepemilikan invoice | `Wallet::pay()` & `Wallet::confirm_payment()` memverifikasi invoice milik user sesi; pelanggaran → `log_message('error', ...)` + HTTP **403** (pola C1 plan/38 / C7 plan/42) |
| Maintenance mode | Otomatis berlaku (kedua method di bawah `MY_Controller` → `maintenance_gate()` sebagai statement pertama): HTML 503 / JSON `MAINTENANCE_MODE` |
| CSRF | Semua POST memakai `form_open()`/`form_open_multipart()` (CI3 `csrf_protection = TRUE`, `csrf_token_name = synapse_csrf_token`) |
| POST-only | `confirm_payment`, `decline_deposit`, `qris_settings` menolak non-POST dengan `show_404()` (M4 plan/62 S1) |

### 5.4 Expiry lazy (tanpa cron — preseden M3 plan/60)

| Titik sisip | Panggilan | Alasan |
|---|---|---|
| `MY_Controller::__construct()` **tepat setelah** sweep rental (`:66–67`) | `expire_user_deposits($user_id)` | Satu UPDATE ber-index per request terautentikasi, idempotent, autocommit, sub-milidetik. Urutan statement terdokumentasi tetap utuh: maintenance → pin WIB → `i18n_apply` → guard login → sweep rental → **sweep deposit** |
| `Admin::__construct()` **sebelum** `get_alert_counts()` | `expire_stale_deposits(500)` | Badge alert tidak boleh menghitung baris yang sudah basi |
| `scripts/expire_deposits.php --dry-run\|--apply` | manual/CLI | Escape hatch operator (parity `scripts/expire_rentals.php`) |

Expiry **tidak** membuat notifikasi: sweep berjalan di tiap request → notifikasi
akan spam dan membuat sweep tidak lagi murni idempoten. Status kedaluwarsa
disampaikan lewat UI (kartu wallet + halaman bayar). Dicatat sebagai asumsi §13.1.

### 5.5 Runbook late payment (fail-safe, tanpa bypass skema)

Member yang sudah transfer tetapi invoicenya sudah `expired` **tidak bisa**
di-approve lewat antrean normal (guard `expires_at`). Jalur operator yang sudah
ada dan teraudit: `/admin/users/{id}` → **Inject Balance** dengan deskripsi
memuat nomor invoice. Hint berbahasa Indonesia ditampilkan di kartu deposit
dashboard agar operator tidak mencari jalur lain.

---

## 6. Arsitektur Model

### 6.1 `application/models/Wallet_model.php` (jalur uang member)

| Method | Perubahan |
|---|---|
| `get_deposit_policy()` | **baru** — `{expiry_minutes, min_amount, max_amount}`; static cache per-request; `system_settings` di atas fallback `withdrawal_fees.php`; validasi per-key, nilai rusak → log + fallback (gaya M1) |
| `validate_deposit_settings(array $raw)` | **baru** — `{ok, values, errors}` untuk form QRIS admin (mirror `validate_financial_settings`/`validate_rebate_settings`) |
| `create_deposit($user_id, $amount)` | **ditulis ulang** (§4.1) — signature sama; return diperluas aditif: `{success, code, message, invoice_number, unique_code, total_amount, expires_at}` |
| `has_active_deposit($user_id)` | **baru** — `status IN ('pending','waiting_approval')` |
| `get_pending_deposits($user_id)` → `get_active_deposits($user_id)` | **rename + perluas filter** (hanya 1 call site: `Wallet::index` `:23`); order `created_at DESC` |
| `confirm_deposit($invoice_number, $user_id)` | **baru** — `{success, code, message}`; `code ∈ {ok, not_found, forbidden, not_pending, expired}`; UPDATE kondisional + `confirmed_at`; reservasi **tetap** |
| `expire_user_deposits($user_id)` | **baru** — int affected rows (sweep per-user) |
| `expire_stale_deposits($limit = 500)` | **baru** — int affected rows (sweep global, admin/CLI) |
| `approve_deposit_simulator($invoice_number, $user_id)` | **diperbarui** — menerima `pending` **atau** `waiting_approval`, kredit `total_amount`, bersihkan `reserved_code_key`, set `processed_at`; tetap di-gate production oleh controller |
| `_pick_unique_code(int $amount, array $used): ?int` | **baru, private, murni** — diff himpunan bebas + `random_int` (tanpa I/O, mudah ditelaah/diuji) |
| `get_financial_config()`, `calculate_deposit_fee()`, `calculate_withdrawal_fee()`, `credit()`, `debit()`, `_post()`, `lock_and_get_balance()`, `get_balance()`, `create_withdrawal()`, `get_withdrawal_*`, `insert_bank()` | **tidak disentuh** — jalur penarikan tidak boleh regresi |

Disiplin uang: `(int)` di setiap batas, `intdiv()` untuk aritmetika fee, kredit
hanya lewat choke point `_post()` (amount > 0 assertion), dan **tidak ada**
`insert('wallet_ledger')`/`UPDATE users SET balance` di luar file ini.

### 6.2 `application/models/Admin_model.php`

| Method | Perubahan |
|---|---|
| `approve_deposit($deposit_id, $audit)` | **diperbarui** — guard status diperluas ke `IN ('pending','waiting_approval')`; UPDATE kondisional juga mensyaratkan `(expires_at IS NULL OR expires_at > ?)`; set `processed_at` + bersihkan `reserved_code_key`; kredit `(int) total_amount` dengan fallback defensif ke `amount` bila `total_amount <= 0` (baris belum termigrasi); `details` audit ditambah `base_amount`, `unique_code`, `total_amount`. **Dipertahankan:** `lock_and_get_balance()` sebagai operasi pertama dalam `trans_begin()`, gerbang `affected_rows() === 1`, rollback pada `Throwable` |
| `decline_deposit($deposit_id, $audit, $reason = null)` | **baru** — cermin `decline_withdrawal` **tanpa refund**: UPDATE kondisional → `rejected`, `decline_reason` (`mb_substr(trim($r),0,255)` atau NULL), `processed_at`, `reserved_code_key = NULL`; audit `decline_deposit` |
| `get_deposit_queue()` | **baru** — antrean dashboard dipindah dari controller ke model (menutup **G4**): `status IN ('pending','waiting_approval')`, join `users.phone`, `ORDER BY FIELD(d.status,'waiting_approval','pending'), d.created_at ASC` (baris yang uangnya sudah diklaim naik ke atas), kolom termasuk `unique_code`, `total_amount`, `expires_at`, `confirmed_at` |
| `get_alert_counts()` | **semantik diperbarui, bentuk tetap** — `pending_deposits = COUNT WHERE status IN ('pending','waiting_approval')` sehingga badge = isi panel (menutup **G2**); nama key + envelope `api_success($counts,'ok',200,$counts)` **tidak berubah** |
| `get_history_deposits()` & `count_history_deposits()` | **diperbarui bersamaan** (**G3**) — filter `status IN ('success','failed','rejected','expired')`; total paginasi wajib sama dengan himpunan baris |
| `get_setting()`, `get_settings_map()`, `update_system_settings()`, `_write_audit()`, `inject_balance()` | **tidak disentuh** — dipakai ulang oleh form QRIS (TX atomik + audit before/after) dan oleh runbook late payment |

### 6.3 Aturan uang & ledger (tidak berubah, hanya ditegaskan)

1. `wallet_ledger` = satu-satunya ledger otoritatif; saldo = `SUM(credit) − SUM(debit)`.
2. Setiap kredit deposit terjadi di dalam TX yang sama dengan flip status dan audit.
3. Anchor lock `users FOR UPDATE` wajib statement pertama jalur kredit.
4. `users.balance` = cache relatif (`±`) di dalam TX, bukan nilai absolut dari baca basi.
5. Notification & flashdata = pasca-commit (fire-and-forget), bukan bagian TX.

---

## 7. Arsitektur Controller, Upload & Routes

### 7.1 `application/controllers/Wallet.php`

| Method | Perubahan |
|---|---|
| `index()` (`:14`) | pakai `get_active_deposits()`; meneruskan **`total_amount`/`unique_code` dari baris** (menutup **G1** — hapus penghitungan ulang fee di loop `:37–40`); tambah `deposit_policy`, `qris_configured`, `now_ts` |
| `topup()` (`:47`, POST) | rate limit `deposit:{user_id}` → validasi integer ketat (regex M8) → `create_deposit()` → **redirect ke `wallet/pay/{invoice}`** saat sukses; kode gagal dipetakan ke flashdata spesifik (`pending_exists`, `below_min`, `above_max`, `qris_unavailable`, `code_exhausted`/`code_conflict`) |
| `pay($invoice_number)` (**baru**, GET) | cek kepemilikan (403 + log) → ambil deposit + setting `qris_*` (preseden `Help::index`) → render `templates/header` + `wallet/pay` + `templates/bottom_nav` |
| `confirm_payment($invoice_number)` (**baru**, POST) | rate limit → cek kepemilikan → `confirm_deposit()` → flashdata → redirect balik ke `wallet/pay/{invoice}` |
| `simulate_payment()`, `simulate_wd_approve()`, `withdraw()`, `process_withdraw()`, `bind_bank()` | **tidak disentuh** |

### 7.2 `application/controllers/Admin.php`

| Method | Perubahan |
|---|---|
| `index()` (`:80`) | antrean deposit via `Admin_model::get_deposit_queue()` (SQL inline `:83–88` dihapus) |
| `approve_deposit($id)` (`:128`) | POST-only (existing); body notifikasi memakai `total_amount`; flashdata memuat invoice + kode; dialog konfirmasi membedakan row `pending` vs `waiting_approval` |
| `decline_deposit($id)` (**baru**) | POST-only (selain itu `show_404()`); `reason = trim($this->input->post('reason', TRUE))`; audit `decline_deposit`; notifikasi `type='warning'` (**bukan** `'error'` — bukan anggota ENUM, catatan bug M5); redirect `admin#pending-deposits` |
| `settings()` (`:304`) | jalur POST **tidak berubah**; GET tambahan meneruskan `qris_image`, `qris_merchant_name`, `qris_payment_instructions`, `deposit_expiry_minutes`, `deposit_min_amount`, `deposit_max_amount` |
| `qris_settings()` (**baru**) | **POST:** validasi kebijakan via `Wallet_model::validate_deposit_settings` → all-or-nothing (ada error → flashdata + redirect, **tanpa** upload, **tanpa** persist) → upload gambar opsional → susun `$final` → snapshot before/after per key → `Admin_model::update_system_settings($final, $this->_audit_ctx(null,'admin_update_qris_settings', [...]))`. **GET** → `redirect('admin/settings')` |
| `alerts_poll()` (`:42`) | **tidak disentuh** (kontrak terjaga: `api_success($counts,'ok',200,$counts)` + header `Cache-Control: no-store`) |

### 7.3 Upload gambar QRIS (di controller, library `upload` CI3 — preseden `Profile::update()`)

```php
$config = [
    'upload_path'   => './uploads/qris/',
    'allowed_types' => 'png|jpg|jpeg',   // SVG SENGAJA tidak diizinkan (vektor XSS)
    'max_size'      => 2048,             // KB
    'encrypt_name'  => TRUE,             // nama acak → tidak ada path traversal
    'remove_spaces' => TRUE,
    'detect_mime'   => TRUE,             // verifikasi MIME sebenarnya, bukan ekstensi
];
```

Aturan operasional upload:
1. Nama file lama diambil **sebelum** overwrite (untuk snapshot audit `before`).
2. `@unlink()` file lama hanya **setelah** upload baru sukses **dan** key
   `qris_image` berhasil dipersist — tidak ada jendela "gambar hilang".
3. Semua setting lain divalidasi **sebelum** file diproses (all-or-nothing).
4. Gambar QRIS memang **publik** (diakses member) → tidak ada `.htaccess deny`;
   hanya isi direktori runtime yang di-`.gitignore` (G7).

### 7.4 Routes (`application/config/routes.php`) — hanya entri eksplisit

```php
// Plan 102: halaman pembayaran manual QRIS + konfirmasi transfer.
$route['wallet/pay/(:any)']             = 'wallet/pay/$1';
$route['wallet/confirm_payment/(:any)'] = 'wallet/confirm_payment/$1';

// Plan 102: simpan konfigurasi QRIS. Multi-segmen TANPA route eksplisit akan
// dipetakan CI3 sebagai Admin::settings('qris') — route ini WAJIB.
$route['admin/settings/qris']           = 'admin/qris_settings';
```

`POST admin/decline_deposit/{id}` **tidak butuh** route: pemetaan default CI3
menangani nama method ber-underscore persis seperti `admin/approve_withdrawal/{id}`
yang sudah berjalan hari ini.

### 7.5 Sentuhan core class (minimal & terdokumentasi di tempat)

| File | Perubahan |
|---|---|
| `application/core/MY_Controller.php` | Satu statement tambahan (§5.4), diletakkan setelah sweep rental M3 sehingga urutan tetap terdokumentasi |
| `application/controllers/Admin.php::__construct()` | Sweep `expire_stale_deposits()` sebelum injeksi `global_admin_alerts` |

---

## 8. View & Wireframe

### 8.1 **BARU** `application/views/wallet/pay.php` (member, EN/ID, mobile-first)

```
┌──────────────────────────────────────────┐  ← shell max-w-[480px]
│  ← Kembali              INVOICE          │     (templates/header + bottom_nav
│  INV-20250911…-a91f3c    [ PENDING ]     │      di-render oleh controller)
├──────────────────────────────────────────┤
│             ┌────────────────┐           │
│             │   ▓▓ QRIS ▓▓   │           │  <img> w-full max-w-[280px]
│             └────────────────┘           │  aspect-square, object-contain
│           Merchant: SYNAPSE              │  (kosong ⇒ kartu notice "QRIS belum
│   #qris_payment_instructions             │   dikonfigurasi" + tautan WA,
│                                          │   pakai key `wa_number` yang sama
│                                          │   dengan halaman Help)
├──────────────────────────────────────────┤
│  JUMLAH TRANSFER (TEPAT)                 │
│  Rp 150|234                              │  ← 3 digit terakhir ditonjolkan
│  Pokok Rp 150.000  +  Kode Rp 234        │     (amber/indigo, tracking-widest)
│  [ ⧉ Salin Jumlah ]                      │  clipboard + fallback execCommand
├──────────────────────────────────────────┤
│  ⏱ Bayar dalam  00:57:12                 │  countdown aria-live="polite"
│  Sebelum 11 Sep 2025 21:14 WIB           │  (jam WIB dari server)
├──────────────────────────────────────────┤
│  Cara bayar:                             │
│   1 Scan QR   2 Transfer nominal TEPAT   │
│   3 Tekan tombol di bawah                │
├──────────────────────────────────────────┤
│  [ ✔ Saya Sudah Transfer ]               │  POST wallet/confirm_payment/{inv}
└──────────────────────────────────────────┘     (form_open + CSRF + confirm())
```

Varian status (shell sama, isi berbeda):

| Status | Isi badan halaman |
|---|---|
| `pending` | QR + nominal + countdown + tombol konfirmasi |
| `waiting_approval` | Banner amber "Menunggu verifikasi admin (±30 menit kerja)"; QR tetap tampil (bila perlu kirim ulang); countdown diganti "Dikonfirmasi pada …"; **tanpa** CTA |
| `success` | Banner hijau "Saldo Rp150.234 sudah masuk" + tautan riwayat transaksi |
| `rejected` | Banner rose + `decline_reason` (bila ada) + ajakan hubungi admin |
| `expired` | Banner abu "Invoice kedaluwarsa, kode unik dilepas" + tombol utama "Buat Deposit Baru" → `/wallet` |
| legacy (`unique_code NULL`) | Catatan kecil "Invoice lama tanpa kode unik — transfer sesuai nominal pokok" |

Detail JS countdown (vanilla, blok `<script>` di view):
1. Server mengirim `expires_ts` dan `server_now_ts` (integer) → JS menghitung
   `remaining = expires_ts - server_now_ts` sehingga **tidak terpengaruh jam
   client yang meleset**, lalu menurunkan nilainya per detik.
2. Saat `remaining <= 0`: tombol konfirmasi disembunyikan/di-disable, banner
   kedaluwarsa dirender, flag `reloadOnce` dipasang, lalu `location.reload()`
   **sekali** agar state server menjadi otoritatif (termasuk efek sweep).
3. Tombol salin memakai pola `.btn-copy-nominal` + `navigator.clipboard` dengan
   fallback `execCommand` (diambil dari `wallet/index.php`), string dari
   `window.SYNAPSE_I18N` (`js_copied`, `js_copy_failed`) → **tidak perlu**
   mengubah `templates/header.php`.

### 8.2 `application/views/wallet/index.php` (edit)

- Form top-up: hint min/max dari policy; tombol nominal cepat di luar rentang
  dinonaktifkan dengan petunjuk; kalimat edukasi **kode unik 3 digit** sebelum
  invoice dibuat.
- Kartu deposit aktif: chip status (**Pending Pembayaran** vs **Menunggu
  Verifikasi**), chip kode (`#234`), **Total Transfer = `total_amount`
  tersimpan**, timestamp kedaluwarsa, dan tombol utama **"Bayar Sekarang"** →
  `/wallet/pay/{invoice}`; tombol simulator dev tetap (`ENVIRONMENT !== 'production'`).
- Breakdown fee (JS) tetap untuk pratinjau pra-invoice, dengan catatan bahwa
  nominal **dibekukan** setelah invoice terbit.

### 8.3 `application/views/admin/dashboard.php` (edit — Indonesia, anchor `#pending-deposits`)

```
┌ Pending Deposits                                [ 3 ] ┐  badge = pending + waiting_approval
│ INV-…-a91f3c                 user 0812…               │
│ Pokok Rp 150.000 + kode 234                           │
│ VERIFIKASI: Rp 150.234   ← mono, bold, angka primer   │
│ [Siap diverifikasi] dibuat 12 mnt · konfirmasi 10 mnt │
│ [ ✓ Approve ]      [ alasan… ] [ ✕ Decline ]          │
└───────────────────────────────────────────────────────┘
```

- Chip `Siap diverifikasi` (`waiting_approval`, emerald) vs `Belum konfirmasi`
  (`pending`, slate/amber) — menegakkan **D4** secara visual.
- Chip amber `Konfirmasi terlambat` bila `waiting_approval` dan
  `expires_at < now` (tetap bisa di-approve, **D1**) — petunjuk prioritas ops.
- Dialog `confirm()` berbeda per status; untuk `pending`:
  "Member belum mengonfirmasi transfer. Tetap setujui?".
- Blok Decline mengikuti layout penarikan (input alasan opsional + tombol ghost).
- Hint: "Deposit kedaluwarsa / transfer terlambat → gunakan Inject Balance di
  detail user (teraudit)."

### 8.4 `application/views/admin/history.php` (edit)

Baris deposit menampilkan `total_amount` (fallback `amount`) berlabel **Total
Transfer** (penarikan tetap `amount`), plus chip status untuk `success` /
`rejected` / `expired` / `failed` dan fallback netral untuk status tak dikenal.

### 8.5 `application/views/admin/settings.php` (edit — kartu baru, Indonesia)

```
┌ Pembayaran QRIS Manual ──────────────────────────────┐
│ [ pratinjau 96px ]  Unggah Gambar QRIS (PNG/JPG ≤2MB)│
│ Nama Merchant : [ Synapse                 ]          │
│ Instruksi     : [ textarea multi-baris    ]          │
│ Kedaluwarsa   : [ 60 ] menit                         │
│ Min Deposit   : [ 10000 ]    Max: [ 50000000 ]       │
│ [ Simpan Pengaturan QRIS ]                           │
└──────────────────────────────────────────────────────┘  <form enctype="multipart/form-data">
```

`form_open_multipart('admin/settings/qris')` + `data-guard-submit="1"` (guard
double-submit M4). Form ini **terpisah** dari form kontak/finansial/rebate yang
sudah ada → jalur POST lama tetap utuh byte-for-byte (risiko regresi minimum).
Panel admin **100% Indonesia** (invarian L1 — tidak ada key i18n baru di sini).

### 8.6 Direktori upload

| Item | Perubahan |
|---|---|
| `uploads/qris/index.html` | **baru, tracked** — placeholder 1 baris agar direktori ada di clone bersih (library upload CI3 tidak membuat direktori) — menutup **G7** |
| `.gitignore` | tambah `uploads/qris/*` dan `!uploads/qris/index.html` (konten runtime tidak boleh ikut ter-commit, berbeda dengan avatar dev yang saat ini ter-track) |

---

## 9. Kamus i18n (dwibahasa member)

### 9.1 Aturan

- Baseline terverifikasi: **332 key** di `application/language/english/app_lang.php`
  dan **332** di `application/language/indonesian/app_lang.php`, himpunan key
  **identik** (`sort` + `diff`).
- Plan ini menambah **39 key** di **kedua** file → **371 / 371**.
- Uang & angka **tidak** diterjemahkan (L6): `Rp ` + `number_format($v,0,',','.')`.
- Panel admin tidak menambah key sama sekali (L1).

### 9.2 Daftar 39 key baru (wajib ada di kedua idiom)

`wallet_pay_title`, `wallet_pay_amount_label`, `wallet_pay_total_label`,
`wallet_pay_principal_label`, `wallet_pay_code_label`, `wallet_pay_code_hint`,
`wallet_pay_copy_hint`, `wallet_pay_merchant_label`, `wallet_pay_qris_alt`,
`wallet_pay_instructions_title`, `wallet_pay_not_configured`,
`wallet_pay_help_note`, `wallet_pay_steps_title`, `wallet_pay_step_scan`,
`wallet_pay_step_transfer`, `wallet_pay_step_confirm`, `wallet_pay_expires_in`,
`wallet_pay_expires_at`, `wallet_pay_expired_title`, `wallet_pay_expired_body`,
`wallet_pay_expired_cta`, `wallet_pay_confirm_btn`, `wallet_pay_confirm_dialog`,
`wallet_pay_waiting_title`, `wallet_pay_waiting_body`, `wallet_pay_success_title`,
`wallet_pay_success_body`, `wallet_pay_rejected_title`, `wallet_pay_rejected_body`,
`wallet_pay_status_pending`, `wallet_pay_status_waiting`, `wallet_pay_status_success`,
`wallet_pay_status_rejected`, `wallet_pay_status_expired`, `wallet_pay_now_btn`,
`wallet_pay_unconfirmed_badge`, `wallet_deposit_min_note`, `wallet_deposit_max_note`,
`wallet_deposit_single_active`.

### 9.3 Key lama yang dipakai ulang (tidak ada entri baru)

`wallet_copy_amount`, `wallet_copy_amount_title`, `wallet_principal_in`,
`wallet_service_fee`, `wallet_total_bill`, `wallet_page_title`,
`js_copied`, `js_copy_failed`.

### 9.4 Perintah verifikasi paritas (wajib lulus)

```bash
for f in english indonesian; do
  echo -n "$f: "; grep -c '^\$lang\[' application/language/$f/app_lang.php
done   # harus 371 dan 371

diff <(grep -o "^\$lang\['[a-z0-9_]*'\]" application/language/english/app_lang.php | sort) \
     <(grep -o "^\$lang\['[a-z0-9_]*'\]" application/language/indonesian/app_lang.php | sort) \
  && echo "KEY SETS IDENTICAL"
```

---

## 10. Perubahan Kontrak Publik (ringkasan)

| Permukaan | Perubahan | Kompatibilitas |
|---|---|---|
| Route | `+GET wallet/pay/(:any)`, `+POST wallet/confirm_payment/(:any)`, `+POST admin/settings/qris` | aditif |
| Skema `deposits` | `+unique_code`, `total_amount`, `reserved_code_key` (UNIQUE), `expires_at`, `confirmed_at`, `processed_at`, `decline_reason`; enum `+waiting_approval`,`rejected`,`expired`; `+idx_status_expires` | aditif; baris lama di-backfill; `failed` dipertahankan |
| `system_settings` | `+6` key (seed idempoten) | aditif |
| `Wallet_model::create_deposit()` | signature sama, return diperluas | aditif (call site hanya membaca `success`/`invoice_number`) |
| `Wallet_model::get_pending_deposits()` → `get_active_deposits()` | rename + filter diperluas | 1 call site diperbarui |
| `Wallet_model::approve_deposit_simulator()` | menerima `waiting_approval`, kredit `total_amount` | dev-only |
| `Admin_model::approve_deposit()` | guard diperluas + guard expiry + kredit `total_amount` | bentuk return sama |
| `Admin_model::get_alert_counts()` | `pending_deposits` kini menghitung `pending + waiting_approval` | nama key + envelope tidak berubah |
| Filter history admin | mencakup `rejected`, `expired` | aditif |
| `window.AdminAlerts` / endpoint poll | tidak berubah | — |

---

## 11. Urutan Implementasi Step-by-Step

| Step | Pekerjaan | File |
|---|---|---|
| **1** | Tulis dokumen plan ini (deliverable round ini) | `plan/102_MANUAL_QRIS_UNIQUE_CODE_PAYMENT_PLAN.md` |
| **2** | Skema: DDL kanonik (`CREATE TABLE deposits` + seed 6 key) di dua file seed; blok catatan migrasi live-DB; CLI migrasi; uji `--dry-run` → `--apply`; verifikasi `SHOW CREATE TABLE` + query invarian §3.5 | `database.sql`, `database_seed.sql`, `scripts/migrate_102_qris_deposits.php` |
| **3** | Model: policy + validator, `create_deposit()` baru + `_pick_unique_code()`, `confirm_deposit()`, `expire_*`, `has_active_deposit()`, `get_active_deposits()`, simulator; `Admin_model` approve/decline/queue/history/alerts | `application/models/Wallet_model.php`, `application/models/Admin_model.php` |
| **4** | Controller + route + core: member `index`/`topup`/`pay`/`confirm_payment`; admin `index`/`approve_deposit`/`decline_deposit`/`settings`/`qris_settings`; hook sweep | `application/controllers/Wallet.php`, `application/controllers/Admin.php`, `application/core/MY_Controller.php`, `application/config/routes.php` |
| **5** | View: halaman bayar baru, index wallet, dashboard admin, history admin, kartu settings; direktori upload + `.gitignore` | `application/views/wallet/pay.php`, `application/views/wallet/index.php`, `application/views/admin/{dashboard,history,settings}.php`, `uploads/qris/index.html`, `.gitignore` |
| **6** | i18n: 39 key × 2 idiom + cek paritas | `application/language/{english,indonesian}/app_lang.php` |
| **7** | Jalankan matriks verifikasi §12 menyeluruh; `php -l` setiap file PHP baru/berubah | — |
| **8** | Sinkronisasi dokumen + closure: PRD §B (siklus deposit), ERD, ROADMAP (fase baru), catatan `AGENTS.md`, dan summary plan | `docs/1_PRD.md`, `docs/2_ERD.md`, `docs/3_ROADMAP.md`, `AGENTS.md`, `plan/102_MANUAL_QRIS_UNIQUE_CODE_PAYMENT_SUMMARY.md` |

Konvensi wajib tiap step: `php -l` untuk setiap file PHP yang dibuat/diubah;
kerja per-fase di branch (docs/3_ROADMAP.md); pesan commit bahasa Indonesia;
tidak ada kredensial/secret baru; `defined('BASEPATH') OR exit(...)` di setiap
file PHP baru; tidak ada SQL di controller/view.

---

## 12. Matriks Verifikasi

### 12.1 Statis

| # | Pemeriksaan | Ekspektasi |
|---|---|---|
| S1 | `php -l` pada semua file PHP baru/berubah | tanpa syntax error |
| S2 | Paritas + jumlah key i18n (§9.4) | `diff` kosong; 371 vs 371 |
| S3 | Invarian repo | tidak ada `$this->db` di controller/view (grep); tidak ada tulis `wallet_ledger` di luar `Wallet_model`; header `BASEPATH` di setiap file baru |
| S4 | Migrasi `--dry-run` | mencetak DDL + backfill, tidak menulis apa pun; exit 0 |

### 12.2 Fungsional (browser/curl; HTTP 200/302 kecuali disebutkan)

| # | Skenario | Ekspektasi |
|---|---|---|
| F1 | Create Rp150.000 | baris `pending`, `unique_code ∈ 100..999`, `total_amount = 150234`, `expires_at = created+60m`, `reserved_code_key='150000-{kode}'`; **tanpa** baris ledger; kartu wallet menampilkan kode + total |
| F2 | Create kedua saat masih ada deposit hidup | ditolak "masih ada deposit aktif"; `COUNT(*)` tetap 1 |
| F3 | Dua user, pokok sama | kode berbeda; kedua reservasi unik |
| F4 | Countdown mencapai 0 → auto reload | status `expired` setelah sweep, `reserved_code_key IS NULL`, approve ditolak; create baru untuk pokok sama berhasil (kode bebas kembali) |
| F5 | Konfirmasi transfer | `waiting_approval`, `confirmed_at` terisi, reservasi **tetap**; badge alert admin naik pada poll berikutnya (≤25 dtk); QR + nominal tetap tampil |
| F6 | Double-click konfirmasi / replay POST | 0 baris terpengaruh → error flash; hanya satu transisi; `confirmed_at` tidak berubah |
| F7 | Admin approve (dari `waiting_approval`) | deposit `success`, `reserved_code_key NULL`, `processed_at` terisi, ledger +1 kredit **150234**, `users.balance +150234`, notifikasi `success`, audit `approve_deposit` memuat `total_amount`+`unique_code` |
| F8 | Replay approve | ditolak; tidak ada baris ledger kedua (unique key + gerbang `affected_rows`) |
| F9 | Approve baris `expired` | ditolak ("Deposit tidak valid atau sudah diproses") |
| F10 | Admin decline + alasan | `rejected`, alasan tersimpan, notifikasi (`warning`), **tanpa** mutasi ledger, reservasi bebas → pasangan pokok+kode bisa dipakai lagi |
| F11 | `waiting_approval` lewat 60 menit (set `expires_at` ke masa lalu via SQL, reload) | tetap `waiting_approval` (**D1**) dan tetap bisa di-approve; dashboard menampilkan chip konfirmasi terlambat |
| F12 | Fee deposit ON (`'1'`, flat 5000, pokok 150000) | `total_amount = 155234`, kredit ledger = 150234 (fee ditahan), nominal verifikasi admin = 155234 |
| F13 | Upload PNG lalu ganti PNG baru | file baru di `uploads/qris/`, file lama terhapus, `qris_image` diperbarui, audit `admin_update_qris_settings` before/after; halaman member menampilkan gambar baru |
| F14 | Upload `evil.php` dan `evil.svg` yang di-rename `.png` | ditolak oleh ekstensi + true-MIME (`detect_mime`); tidak ada file tertulis; setting tidak dipersist |
| F15 | `qris_image` kosong | `POST /wallet/topup` ditolak (`qris_unavailable`); halaman bayar menampilkan notice "belum dikonfigurasi" |
| F16 | Kepemilikan: user B membuka invoice A (GET pay, POST confirm) | HTTP 403 + `log_message` error; tanpa perubahan state |
| F17 | Baris legacy (pra-migrasi, `unique_code NULL`) | halaman bayar menampilkan catatan legacy; konfirmasi berjalan; approve mengkredit `amount` (fallback); history tampil normal |
| F18 | Maintenance mode ON | halaman bayar/konfirmasi member → HTTP 503; `/control-panel` tetap bisa diakses |
| F19 | Rate limit: create ke-6 dalam 900 dtk | ter-throttle (parity flash/429 via helper `rate_limit_*`) |
| F20 | Simulator (development saja) | tersedia dari `pending` dan `waiting_approval`; mengkredit `total_amount`; tidak ada di `production` |
| F21 | Invarian §3.5 dijalankan setelah seluruh skenario | kedua query mengembalikan hasil bersih (0 baris / 0 inconsistent) |

### 12.3 Regresi & operasional

| # | Pemeriksaan | Ekspektasi |
|---|---|---|
| R1 | Alur penarikan (create/approve/decline) | tidak berubah; seluruh gate lama berperilaku identik |
| R2 | Envelope `/admin/alerts/poll` | key + root legacy key sama; `pending_deposits` kini termasuk `waiting_approval` |
| R3 | Paginasi history admin | `count_history_deposits() === jumlah baris` di bawah filter baru |
| R4 | `scripts/reconcile_balances.php` setelah siklus penuh | bersih (ledger ↔ cache sinkron) |
| R5 | Render `wallet/index`, `home`, `profile` (2 idiom, dark/light, 360–480 px) | tidak ada layout pecah; string dwibahasa termuat |
| R6 | Halaman admin (dashboard/history/settings) | tetap 100% Indonesia; tidak ada key i18n baru dipakai |

---

## 13. Asumsi, Risiko & Non-Goals

### 13.1 Asumsi

1. `waiting_approval` tidak pernah kedaluwarsa otomatis (**D1**); reservasi
   ditahan sampai admin bertindak.
2. `total_amount` dibekukan saat create dan menjadi satu-satunya sumber
   kebenaran untuk verifikasi maupun kredit (menutup **G1**).
3. Expiry tidak mengirim notifikasi (idempotensi sweep + anti-spam); state UI
   adalah pemberitahuannya.
4. Kasus "terlambat/expired tapi sudah transfer" ditangani lewat tool admin
   `inject_balance` yang sudah ada dan teraudit — bukan jalur skema baru.
5. Gambar QRIS memang publik (`uploads/qris/`), hanya PNG/JPG.
6. Row `waiting_approval` muncul di panel admin hanya setelah member
   mengonfirmasi; approve tetap boleh dari `pending` (**D4**).
7. Tidak ada endpoint JSON/AJAX baru — halaman bayar memakai POST + redirect;
   polling alert center existing dipakai apa adanya.

### 13.2 Risiko & mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Member transfer di menit 59, admin verifikasi di menit 61 | Uang masuk tapi invoice `expired` | **D1**: `waiting_approval` tidak auto-expire; jendela 60 menit hanya membatasi fase bayar |
| Member klik "Saya Sudah Transfer" tanpa benar-benar transfer | Kode tertahan, antrean admin terisi | Admin bisa **decline** (reservasi bebas) + notifikasi beralasan; guard aktif-tunggal membuat penyalahgunaan merugikan pelakunya sendiri |
| Admin salah approve nominal (mis. transfer tanpa kode) | Kredit lebih/kurang | Nominal verifikasi = `total_amount` yang ditampilkan besar & mono; `decline_reason` untuk jejak; `inject_balance` teraudit untuk koreksi |
| Fee deposit diubah setelah invoice terbit | Nominal berbeda antara tampilan & verifikasi | `total_amount` tersimpan; render tidak pernah menghitung ulang (**G1**) |
| Kehabisan 900 kode untuk satu pokok | Create gagal untuk nominal itu | Fail-closed dengan pesan jelas; admin reject baris basi → kode bebas; member disarankan nominal lain |
| Upload QRIS berisi muatan berbahaya | XSS / RCE | Allowlist ekstensi + `detect_mime` true-MIME + `encrypt_name`; **SVG/script ditolak**; batas 2 MB |
| Jam client meleset | Countdown menyesatkan | Countdown dari selisih `expires_ts − server_now_ts`; otoritas tetap server (`expires_at > bound param WIB`) |
| Sweep lazy gagal di satu request | Baris basi tertahan | UPDATE autocommit tanpa TX, error hanya di-log (tidak mematikan request); sweep global juga berjalan di entry admin + tersedia CLI |
| Drift antara status dan reservasi | Kode bocor / bentrok | Dijaga unique index + transisi tunggal; query invarian §3.5 untuk audit berkala |

### 13.3 Non-goals (eksplisit di luar lingkup)

- Tidak ada integrasi payment gateway/webhook, tidak ada upload bukti transfer,
  tidak ada tombol batal dari sisi member, tidak ada analitik frekuensi deposit.
- Panel admin tetap 100% Indonesia (tidak ada key i18n baru untuk admin).
- **Drift yang ditemukan tapi TIDAK dikerjakan tanpa persetujuan**:
  `database_seed.sql` tidak memuat key rebate plan/89
  (`rebate_enabled`, `rebate_l1_percent`, `rebate_l2_percent`, `rebate_l3_percent`)
  yang di-seed oleh `database.sql` — perbaikan 4 baris, tersedia atas permintaan.

---

## 14. Inventaris File yang Akan Disentuh

| File | Jenis | Ringkasan |
|---|---|---|
| `plan/102_MANUAL_QRIS_UNIQUE_CODE_PAYMENT_PLAN.md` | baru | Dokumen ini (round sekarang) |
| `database.sql` | edit | DDL kanonik `deposits` + seed 6 key + catatan migrasi live-DB |
| `database_seed.sql` | edit | Seed 6 key baru (idempoten) |
| `scripts/migrate_102_qris_deposits.php` | baru | Migrasi CLI `--dry-run`/`--apply` + backfill + verifikasi |
| `scripts/expire_deposits.php` | baru (opsional) | Sweep manual/CLI, parity `expire_rentals.php` |
| `application/models/Wallet_model.php` | edit | Policy, validator, create/confirm/expire/guard, helper alokasi |
| `application/models/Admin_model.php` | edit | Approve/decline/queue/history/alerts |
| `application/controllers/Wallet.php` | edit | `index`, `topup`, `pay`, `confirm_payment` |
| `application/controllers/Admin.php` | edit | `__construct`, `index`, `approve_deposit`, `decline_deposit`, `settings`, `qris_settings` |
| `application/core/MY_Controller.php` | edit | 1 statement sweep deposit |
| `application/config/routes.php` | edit | 3 route baru |
| `application/config/withdrawal_fees.php` | edit | 3 key fallback kebijakan deposit |
| `application/views/wallet/pay.php` | baru | Halaman pembayaran QRIS member |
| `application/views/wallet/index.php` | edit | Kartu deposit aktif + hint kode/min-max |
| `application/views/admin/dashboard.php` | edit | Antrean deposit (verifikasi, chip, decline) |
| `application/views/admin/history.php` | edit | Nominal total + status baru |
| `application/views/admin/settings.php` | edit | Kartu "Pembayaran QRIS Manual" |
| `application/language/english/app_lang.php` | edit | +39 key |
| `application/language/indonesian/app_lang.php` | edit | +39 key (paritas) |
| `uploads/qris/index.html` | baru | Placeholder direktori upload |
| `.gitignore` | edit | Abaikan isi runtime `uploads/qris/*` |
| `docs/1_PRD.md`, `docs/2_ERD.md`, `docs/3_ROADMAP.md`, `AGENTS.md` | edit | Sinkronisasi dokumen (step 8) |
| `plan/102_MANUAL_QRIS_UNIQUE_CODE_PAYMENT_SUMMARY.md` | baru | Ringkasan penutup (step 8) |

---

## 15. Checklist Go/No-Go Sebelum Eksekusi

- [ ] **D1–D4** disetujui dan tercatat (sudah, lihat banner atas).
- [ ] Nominal kredit `total_amount` (Option A) disetujui sebagai perilaku produksi.
- [ ] Rentang kode **100–999** disetujui (tanpa leading zero).
- [ ] Ambang `deposit_min_amount`/`deposit_max_amount` (10.000 / 50.000.000) disetujui.
- [ ] Kebijakan "expired/rejected → tidak bisa approve, pakai Inject Balance" disetujui.
- [ ] Form QRIS sebagai **form terpisah** di `/admin/settings` disetujui (vs menyatu dengan form finansial).
- [ ] Refactor kecil yang disertakan disetujui: `get_active_deposits()` rename (**G1**), `get_deposit_queue()` pindah ke model (**G4**), `get_alert_counts()` semantik baru (**G2**), filter history (**G3**).
- [ ] Jendela pemeliharaan DB untuk `ALTER TABLE deposits` (backfill `total_amount` + `expires_at`) disetujui.
- [ ] Urutan deploy disetujui: migrasi → deploy kode → **segera unggah gambar QRIS** (selama kosong, pembuatan deposit fail-closed).
