# Entity Relationship Diagram (ERD) & Database Schema v2.0
**Project Name:** Synapse
**Database Engine:** MySQL 8.4 (InnoDB)
**Character Set / Collation:** utf8mb4 / utf8mb4_unicode_ci

## 1. Important Rules for AI Agent (Hermes)
* **Storage Engine:** Semua tabel WAJIB menggunakan `InnoDB` untuk memastikan dukungan terhadap *ACID compliance* dan *Foreign Key constraints*.
* **Timestamps:** Setiap tabel wajib memiliki kolom `created_at` dan `updated_at` (Tipe: `TIMESTAMP`, Default: `CURRENT_TIMESTAMP` / `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`).
* **Soft Deletes:** Jangan gunakan penghapusan fisik pada data finansial dan riwayat sewa. Gunakan status (misal: `is_active = 0`) atau buat kolom `deleted_at`.
* **Foreign Key Constraints:** * Data krusial seperti transaksi dan penarikan WAJIB menggunakan `ON DELETE RESTRICT` terhadap tabel `users` agar data tidak hilang jika user dihapus.
* **Database Transactions:** Semua mutasi (Insert ke `transactions`, Update ke `users.balance`, Insert ke `rentals`) WAJIB dibungkus dalam blok `DB->trans_begin()`, `DB->trans_commit()`, dan `DB->trans_rollback()`.

---

## 2. Core Tables Specification

### Tabel: `users`
Menyimpan data autentikasi, profil pengguna, saldo utama, dan struktur *Adjacency List* untuk hierarki MLM/Keagenan.
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `phone` (VARCHAR 20, UNIQUE, NOT NULL) - Nomor telepon login.
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
* `price` (DECIMAL 15,2, NOT NULL) - Harga untuk mulai menyewa.
* `daily_rate` (DECIMAL 15,2, NOT NULL) - Fix ROI (pendapatan harian).
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