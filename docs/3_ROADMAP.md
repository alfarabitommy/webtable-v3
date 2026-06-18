# Roadmap & Technical To-Do List v2.0 (Micro-Execution)
**Project Name:** Synapse
**Framework:** CodeIgniter 3 (MVC Architecture)
**Target:** AI Agent (Hermes)

## ⚠️ STRICT RULES FOR AI AGENT (HERMES)
1. **Sequential Execution:** Jangan kerjakan Fase 2 sebelum Fase 1 selesai secara sempurna dan tervalidasi tanpa error.
2. **MVC Compliance:** Dilarang keras menulis query SQL langsung di dalam Controller atau View. Gunakan Model.
3. **Security First:** Setiap form WAJIB menggunakan perlindungan CSRF (`form_open()`). Setiap input WAJIB divalidasi menggunakan CI3 Form Validation sebelum menyentuh database.
4. **Transaction Wrapper:** Setiap manipulasi saldo (`users.balance`) WAJIB dibungkus dengan `$this->db->trans_begin()`, `$this->db->trans_commit()`, dan `$this->db->trans_rollback()`.
5. **Checklist Update:** Ubah `[ ]` menjadi `[x]` setelah tugas selesai dan di-commit.

---

## Phase 1: Database Initialization & Core Models
**Tujuan:** Membangun struktur data dasar sesuai ERD dan mengamankan fungsi *query*.
- [ ] Buat file `database.sql` di root directory. File ini harus berisi DDL (Data Definition Language) yang persis sama dengan `2_ERD.md`, lengkap dengan relasi *Foreign Key* dan *Index*.
- [ ] Buat file `application/models/User_model.php`.
    - Buat method `create_user($data)`: Termasuk auto-generate `invite_code` (6 karakter alfanumerik) sebelum insert.
    - Buat method `get_user_by_phone($phone)`: Untuk keperluan login.
    - Buat method `get_downlines($user_id, $level)`: Untuk melacak agen B (level 1) dan C (level 2).
- [ ] Buat file `application/models/Ledger_model.php`.
    - Buat method `insert_transaction($user_id, $type, $amount, $description, $ref_type, $ref_id)`.
    - Method ini HARUS secara otomatis mengkalkulasi `$balance_after` dan meng-update kolom `balance` di tabel `users`.
- [ ] Buat file `application/models/Product_model.php`.
    - Buat method `get_all_active_products()`.
- [ ] Buat file `application/models/Rental_model.php`.
    - Buat method `create_rental($data)` dan `get_user_rentals($user_id)`.

## Phase 2: Authentication, Security & Onboarding
**Tujuan:** Mengunci sistem agar hanya *user* terdaftar yang bisa masuk.
- [ ] Setup file `application/config/routes.php`.
    - `$route['login'] = 'auth/login';`
    - `$route['register'] = 'auth/register';`
    - `$route['logout'] = 'auth/logout';`
- [ ] Buat `application/controllers/Auth.php`.
    - **Method `register()`:** Validasi `phone` (is_unique[users.phone]), `password` (min_length[8]), dan `invite_code` (wajib diisi, cek apakah ada di database untuk di-set sebagai `parent_id`). Enkripsi sandi dengan `password_hash()`.
    - **Method `login()`:** Validasi input, verifikasi dengan `password_verify()`. Jika sukses, set session: `user_id`, `phone`, `level_id`.
    - **Method `logout()`:** Lakukan `sess_destroy()` dan redirect ke `/login`.
- [ ] Buat UI Views menggunakan Tailwind CSS.
    - `application/views/auth/login.php`
    - `application/views/auth/register.php` (Pastikan input *Kode Undangan* terlihat menonjol).
- [ ] Buat Middleware/Hook untuk mengecek session. Jika `!$this->session->userdata('user_id')`, redirect otomatis ke halaman login untuk semua rute kecuali `/login` dan `/register`.

## Phase 3: Financial Engine (Ledger, Deposit, Withdrawal)
**Tujuan:** Membangun *double-entry system* yang akurat dan modul perbankan.
- [ ] Buat `application/controllers/Wallet.php`.
- [ ] **Modul Rekening Bank:**
    - Tambah views: `application/views/wallet/bank_bind.php`.
    - Method `bind_bank()`: Validasi form nama bank, nomor rekening, nama pemilik. Insert ke tabel `bank_accounts`.
- [ ] **Modul Penarikan (Withdrawal):**
    - Tambah views: `application/views/wallet/withdraw.php`.
    - Method `process_withdraw()`:
        1. Validasi Jam (07:00 - 19:00).
        2. Cek Saldo `balance` >= `gross_amount`.
        3. Cek minimum penarikan Rp 30.000.
        4. Hitung `fee_amount` menggunakan fungsi bertingkat (if-else berjenjang sesuai PRD).
        5. Eksekusi `Ledger_model->insert_transaction` (tipe: withdrawal, amount: negatif).
        6. Insert ke tabel `withdrawals` (status: pending).
- [ ] **Halaman Riwayat:**
    - Tambah views: `application/views/wallet/history.php` dengan fitur filter (Deposit, Penarikan, Pendapatan).

## Phase 4: Marketplace & Asset Rental System
**Tujuan:** Mengaktifkan pembelian dan pelacakan aset oleh *user*.
- [ ] Buat `application/controllers/Marketplace.php`.
- [ ] Method `index()`: Ambil data dari `Product_model->get_all_active_products()` dan lempar ke view `application/views/marketplace/index.php`.
- [ ] Method `rent_product($product_id)`:
    - Lock database / cek saldo.
    - Jika sukses, potong saldo (`Ledger_model`), insert ke `rentals`.
- [ ] Buat `application/controllers/My_sites.php`.
    - Menampilkan view `application/views/rentals/my_sites.php` (Menampilkan total aset, hari yang berjalan, dan pendapatan yang sudah masuk).

## Phase 5: Affiliate System & Background Automation (Cron)
**Tujuan:** Membangun *logic* multi-level dan otomatisasi pembagian komisi/gaji.
- [ ] Buat `application/controllers/Team.php`.
    - Method `index()`: Hitung total agen Level 1 (B) dan Level 2 (C). Render ke view `application/views/team/index.php` yang berisi QR Code dan URL *Referral*.
- [ ] **CRON JOB 1: Pendapatan Harian (Daily Revenue).**
    - Buat `application/controllers/cli/Automations.php` -> Method `daily_roi()`.
    - Cari semua `rentals` status 'active'.
    - Loop: Tambah `days_processed + 1`. Insert transaksi `daily_revenue`. Jika `days_processed == total_days`, ubah status ke 'completed'.
- [ ] **CRON JOB 2: Gaji Mingguan (Weekly Wage).**
    - Di controller `Automations.php` -> Method `weekly_wage()`.
    - Loop semua user, hitung jumlah downline aktif B+C.
    - Cek kondisi Level 1-6. Jika memenuhi syarat, distribusikan komisi ke `Ledger_model` (tipe: commission_bonus).

## Phase 6: Core Setup UI & Component State
**Tujuan:** Menerapkan desain *Mobile-First* yang konsisten di semua halaman.
- [ ] Buat `application/views/templates/header.php` (Load Tailwind CDN/Build, Meta Tags untuk mobile).
- [ ] Buat `application/views/templates/bottom_nav.php` (Menu persisten: Beranda, Sewa Saya, Bantuan, Marketplace, Profil).
- [ ] Terapkan layout PWA-style (max-width: 480px, margin auto, background abu-abu/cerah) agar di layar desktop sekalipun terlihat seperti tampilan *mobile app*.