# Entity Relationship Diagram (ERD) & Database Schema v5.0
**Project Name:** Synapse
**Database Engine:** MySQL 8.4 (InnoDB)
**Character Set / Collation:** utf8mb4 / utf8mb4_unicode_ci

---

## 1. Important Rules for AI Agent (Hermes)
* **Storage Engine:** Semua tabel WAJIB menggunakan `InnoDB` untuk memastikan dukungan terhadap *ACID compliance* dan *Foreign Key constraints*.
* **Timestamps:** Setiap tabel wajib memiliki kolom `created_at` dan `updated_at` (Tipe: `TIMESTAMP`, Default: `CURRENT_TIMESTAMP` / `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`).
* **Soft Deletes:** Jangan gunakan penghapusan fisik pada data finansial dan riwayat sewa. Gunakan status (misal: `is_active = 0`) atau buat kolom `deleted_at`.
* **Foreign Key Constraints:** Data krusial seperti transaksi dan penarikan WAJIB menggunakan `ON DELETE RESTRICT` terhadap tabel `users` agar data tidak hilang jika user dihapus.
* **Database Transactions (ACID Compliance):** Semua mutasi finansial — termasuk tetapi tidak terbatas pada Insert ke `transactions`, Insert ke `wallet_ledger`, Update ke `users.balance`, Insert ke `rentals`, Update ke `deposits.status` — WAJIB dibungkus dalam blok `$this->db->trans_start()` dan `$this->db->trans_complete()` (atau `trans_begin()` / `trans_commit()` / `trans_rollback()`). Kegagalan pada salah satu langkah dalam transaksi HARUS memicu rollback penuh untuk menjaga integritas data.

---

## 2. Core Tables Specification

### Tabel: `users`
Menyimpan data autentikasi, profil pengguna, saldo utama, dan struktur *Adjacency List* untuk hierarki MLM/Keagenan.
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `phone` (VARCHAR 20, UNIQUE, NOT NULL) - Nomor telepon login. **Normasi backend: `+62` → `0`, strip simbol (`-`, `()`, `spasi`) sebelum insert. Format final: `0XXXXXXXXXX`.**
* `password` (VARCHAR 255, NOT NULL) - Hashed Bcrypt.
* `invite_code` (VARCHAR 10, UNIQUE, NOT NULL) - Kode referral unik milik user ini (digenerate sistem saat register).
* `parent_id` (BIGINT, Unsigned, NULLABLE) - ID dari user Upline. **[Foreign Key -> users.id, ON DELETE SET NULL]**
* `balance` (DECIMAL 15,2, NOT NULL, DEFAULT 0.00) - Saldo dompet yang bisa ditarik/digunakan.
* `avatar_url` (VARCHAR 255, NULLABLE) - Path/URL foto profil.
* `level_id` (INT, NOT NULL, DEFAULT 0) - Menyimpan level keagenan saat ini (0 = Member biasa, 1 = Level 1, dst).
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
* `updated_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)
**Index Optimization:** `INDEX (parent_id)`, `INDEX (invite_code)`

### Tabel: `gpu_products`
Katalog paket GPUaaS (Marketplace). Data ini bersifat master dan tidak sering berubah.
* `id` (INT, Primary Key, Auto Increment, Unsigned)
* `name` (VARCHAR 100, NOT NULL) - Contoh: "GPUaaS 1".
* `type` (ENUM('short_term', 'long_term'), NOT NULL)
* `price` (DECIMAL 15,2, NOT NULL) - Harga untuk mulai menyewa (IDR).
* `daily_rate` (DECIMAL 15,2, NOT NULL) - Fix ROI (pendapatan harian) (IDR).
* `duration_days` (INT, NOT NULL, Unsigned) - Lama kontrak (misal: 4, 365).
* `is_refundable` (TINYINT 1, NOT NULL, DEFAULT 0) - 1 Jika harga `price` dikembalikan di akhir periode, 0 jika tidak.
* `is_active` (TINYINT 1, NOT NULL, DEFAULT 1) - 1 Jika produk masih dijual di marketplace.
* `created_at` (TIMESTAMP)
* `updated_at` (TIMESTAMP)

### Tabel: `rentals`
Menyimpan riwayat pengguna yang telah menyewa produk (Sewa Saya/Situs Saya).
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE RESTRICT]**
* `gpu_product_id` (INT, Unsigned, NOT NULL) - **[Foreign Key -> gpu_products.id, ON DELETE RESTRICT]**
* `status` (ENUM('active', 'completed', 'cancelled'), NOT NULL, DEFAULT 'active')
* `total_days` (INT, NOT NULL, Unsigned) - Di-copy dari `gpu_products.duration_days` saat pembelian (mencegah bug jika produk master diubah).
* `days_processed` (INT, NOT NULL, Unsigned, DEFAULT 0) - Counter untuk cron job pembagian hasil harian.
* `daily_rate_snapshot` (DECIMAL 15,2, NOT NULL) - Di-copy dari `gpu_products.daily_rate` saat pembelian.
* `started_at` (TIMESTAMP, NOT NULL, DEFAULT CURRENT_TIMESTAMP) - Waktu mulai sewa.
* `ends_at` (TIMESTAMP, NULLABLE) - Estimasi waktu selesai (started_at + total_days).
* `created_at` (TIMESTAMP)
* `updated_at` (TIMESTAMP)
**Index Optimization:** `INDEX (user_id, status)` untuk load halaman Sewa Saya dengan cepat.

---

## 3. Financial & Ledger Tables

### Tabel: `deposits`
Tabel staging untuk deposit yang diajukan oleh user. Record bersifat temporary — status berubah dari `pending` ke `success`/`failed` setelah proses approval.

* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE RESTRICT]**
* `invoice_number` (VARCHAR 50, UNIQUE, NOT NULL) - Nomor invoice unik berformat `INV-{YmdHis}-{user_id}`.
* `amount` (DECIMAL 15,2, NOT NULL) - Nominal deposit dalam IDR.
* `status` (ENUM('pending', 'success', 'failed'), NOT NULL, DEFAULT 'pending') - Status pemrosesan deposit.
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
* `updated_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)

**Index Optimization:** `INDEX (user_id, status)` untuk query pending deposits per user.

> **Lifecycle:** User creates a deposit → status `pending` → displayed in "Menunggu Pembayaran" section → approved (simulator or gateway) → status `success` → credit entry minted into `wallet_ledger`.

### Tabel: `wallet_ledger`
Immutable, append-only ledger yang mencatat setiap pergerakan dana masuk (credit) dan keluar (debit). Saldo user dihitung secara dinamis dari tabel ini.

* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE RESTRICT]**
* `transaction_id` (VARCHAR 50, NOT NULL) - Referensi ke sumber transaksi (contoh: invoice number deposit, atau rental ID).
* `type` (ENUM('credit', 'debit'), NOT NULL) - `credit` = dana masuk, `debit` = dana keluar.
* `amount` (DECIMAL 15,2, NOT NULL) - Selalu positif. Arah ditentukan oleh kolom `type`.
* `description` (VARCHAR 255, NOT NULL) - Keterangan transaksi (contoh: "Top Up via INV-20260620123456-1").
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)

**Index Optimization:** `INDEX (user_id)`, `INDEX (type)`, `INDEX (created_at)`.

> **Balance Calculation:** `SELECT SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) - SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) AS balance FROM wallet_ledger WHERE user_id = ?`

> **ACID Rule:** Every write to `wallet_ledger` MUST be wrapped in `$this->db->trans_start()` / `$this->db->trans_complete()`. A failed ledger insert MUST rollback all preceding operations in the same transaction (deposit status update, user balance adjustment, etc.).

### Tabel: `transactions`
Double-Entry Ledger (Buku Besar). Tabel *append-only* (hanya boleh di-insert).
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE RESTRICT]**
* `type` (ENUM('deposit', 'withdrawal', 'rental_payment', 'daily_revenue', 'commission_bonus', 'refund'), NOT NULL)
* `amount` (DECIMAL 15,2, NOT NULL) - Bernilai positif (masuk) atau negatif (keluar). Tidak boleh 0.
* `balance_after` (DECIMAL 15,2, NOT NULL) - *Snapshot* total saldo `users.balance` SETELAH transaksi ini dieksekusi.
* `description` (VARCHAR 255, NOT NULL) - Keterangan manual (contoh: "Gaji Mingguan Level 2").
* `reference_type` (VARCHAR 50, NULLABLE) - Menyimpan nama tabel referensi (contoh: 'rentals', 'withdrawals').
* `reference_id` (BIGINT, Unsigned, NULLABLE) - ID dari tabel referensi tersebut.
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
**Index Optimization:** `INDEX (user_id)`, `INDEX (type)`, `INDEX (created_at)`.

### Tabel: `bank_accounts`
Data rekening yang di-bind oleh user untuk keperluan Withdrawal.
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE CASCADE]**
* `bank_name` (VARCHAR 100, NOT NULL)
* `account_number` (VARCHAR 50, NOT NULL)
* `account_holder` (VARCHAR 100, NOT NULL)
* `is_primary` (TINYINT 1, NOT NULL, DEFAULT 1)
* `created_at` (TIMESTAMP)
* `updated_at` (TIMESTAMP)

### Tabel: `withdrawals`
Menyimpan antrean dan riwayat penarikan dana ke rekening bank.
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE RESTRICT]**
* `bank_account_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> bank_accounts.id, ON DELETE RESTRICT]**
* `gross_amount` (DECIMAL 15,2, NOT NULL) - Total saldo user yang dipotong (Misal: 50,000).
* `fee_amount` (DECIMAL 15,2, NOT NULL) - Biaya layanan yang ditahan sistem (Misal: 11,500).
* `net_amount` (DECIMAL 15,2, NOT NULL) - Uang riil yang harus ditransfer admin ke user (Misal: 38,500).
* `status` (ENUM('pending', 'processing', 'success', 'failed'), NOT NULL, DEFAULT 'pending')
* `remark` (VARCHAR 255, NULLABLE) - Alasan jika penarikan di-reject/gagal.
* `processed_at` (TIMESTAMP, NULLABLE) - Waktu ketika admin mengklik "Approve/Transfer".
* `created_at` (TIMESTAMP)
* `updated_at` (TIMESTAMP)

---

## 4. System Logic Table (Optional but Recommended)

### Tabel: `otp_logs`
Menyimpan riwayat kode OTP untuk validasi pendaftaran dan keamanan.
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `phone` (VARCHAR 20, NOT NULL)
* `otp_code` (VARCHAR 6, NOT NULL)
* `expires_at` (TIMESTAMP, NOT NULL) - Biasanya +5 menit dari waktu dibuat.
* `is_used` (TINYINT 1, NOT NULL, DEFAULT 0)
* `created_at` (TIMESTAMP)

---

## 5. Notification & Engagement Table

### Tabel: `user_notifications`
Tabel notifikasi interaktif yang m驱动 oleh AJAX polling. Menyimpan notifikasi sistem, reward, dan informasi akun.

* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE CASCADE]** — Notifikasi dihapus otomatis jika user dihapus.
* `title` (VARCHAR 100, NOT NULL) - Judul singkat notifikasi (contoh: "Bonus Level 1 Terkirim!").
* `message` (TEXT, NOT NULL) - Isi detail notifikasi (contoh: "Selamat! Kamu telah mencapai Level 1 Agency...").
* `type` (ENUM('info', 'warning', 'success', 'commission'), NOT NULL) - Kategori notifikasi untuk styling badge warna:
    * `info` → slate badge
    * `warning` → amber badge
    * `success` → emerald badge
    * `commission` → emerald badge dengan ikon `fa-coins`
* `is_read` (TINYINT 1, NOT NULL, DEFAULT 0) - 0 = belum dibaca (tampil di Red Badge), 1 = sudah dibaca.
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)

**Index Optimization:** `INDEX (user_id, is_read)` — Composite index untuk query unread count yang sangat sering: `SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0`.

> **Lifecycle:** Backend insert notifikasi → AJAX poll dari `header.php` fetches unread count → render Red Badge → user taps bell → fetch notification list → mark `is_read = 1` per item atau bulk "Mark All Read".

---

## 6. Admin & Operational Tables (Phase 7)

### Tabel: `admins`
Tabel terpisah untuk autentikasi admin/operator. Hard-separated dari `users` — tidak ada foreign key ke `users.id`.
* `id` (INT, Primary Key, Auto Increment, Unsigned)
* `username` (VARCHAR 50, UNIQUE, NOT NULL) - Username login admin.
* `password` (VARCHAR 255, NOT NULL) - Hashed Bcrypt/Argon2id.
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
* `updated_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)

> **Security Note:** Tabel ini sepenuhnya terpisah dari `users`. Session admin menggunakan `admin_id` (bukan `user_id`). Tidak ada foreign key relasi ke tabel user.

### Tabel: `system_audit_logs` (Phase 10 — Planned)
Trail audit untuk setiap aksi admin terhadap data finansial.
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `admin_id` (INT, NULLABLE) - **[Foreign Key → admins.id, ON DELETE SET NULL]**
* `user_id` (BIGINT, NULLABLE) - **[Foreign Key → users.id, ON DELETE SET NULL]**
* `action` (VARCHAR 100, NOT NULL) - Contoh: "approved_deposit", "declined_withdrawal"
* `details` (TEXT, NULLABLE) - JSON atau human-readable deskripsi.
* `ip_address` (VARCHAR 45, NOT NULL) - IPv4/IPv6 alamat pelaku aksi.
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)

---

## 7. Schema Divergence Notes

### `user_rentals` vs `rentals` (Original ERD)
Tabel `rentals` dalam ERD v3.0 tidak digunakan dalam implementasi aktual. Sebagai gantinya, menggunakan **`user_rentals`** dengan kolom yang disederhanakan:

| Kolom ERD (`rentals`) | Kolom Aktual (`user_rentals`) |
|------------------------|-------------------------------|
| `gpu_product_id` | `product_id` |
| `total_days` | *(dihitung dari `expired_at`)* |
| `days_processed` | `days_processed` |
| `daily_rate_snapshot` | `daily_roi` |
| `started_at` | *(DEFAULT CURRENT_TIMESTAMP)* |
| `ends_at` | `expired_at` |
| *(baru)* | `last_claimed_at` (untuk manual ROI claim) |
| *(baru)* | `purchase_price` |

Tabel `user_rentals` juga berfungsi sebagai tabel `rentals` di dalam kode (`application/models/Rental_model.php`). Nama asli dari database.sql yang digunakan adalah `user_rentals`.
