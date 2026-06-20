# Roadmap & Technical To-Do List v3.0 (Micro-Execution)
**Project Name:** Synapse
**Framework:** CodeIgniter 3 (MVC Architecture)
**Target:** AI Agent (Hermes)

---

## ⚠️ STRICT RULES FOR AI AGENT (HERMES)
1. **Sequential Execution:** Jangan kerjakan Fase N+1 sebelum Fase N selesai secara sempurna dan tervalidasi tanpa error.
2. **MVC Compliance:** Dilarang keras menulis query SQL langsung di dalam Controller atau View. Gunakan Model.
3. **Security First:** Setiap form WAJIB menggunakan perlindungan CSRF (`form_open()`). Setiap input WAJIB divalidasi menggunakan CI3 Form Validation sebelum menyentuh database.
4. **Transaction Wrapper:** Setiap manipulasi saldo dan mutasi finansial WAJIB dibungkus dengan `$this->db->trans_start()` dan `$this->db->trans_complete()` (atau `trans_begin()` / `trans_commit()` / `trans_rollback()`).
5. **Checklist Update:** Ubah `[ ]` menjadi `[x]` setelah tugas selesai dan di-commit.

---

## Phase 1: Database Initialization & Core Models — ✅ [COMPLETED]
**Tujuan:** Membangun struktur data dasar sesuai ERD dan mengamankan fungsi *query*.
- [x] Buat file `database.sql` di root directory. File ini harus berisi DDL (Data Definition Language) yang persis sama dengan `2_ERD.md`, lengkap dengan relasi *Foreign Key* dan *Index*.
- [x] Buat file `application/models/User_model.php`.
    - Buat method `create_user($data)`: Termasuk auto-generate `invite_code` (6 karakter alfanumerik) sebelum insert.
    - Buat method `get_user_by_phone($phone)`: Untuk keperluan login.
    - Buat method `get_downlines($user_id, $level)`: Untuk melacak agen B (level 1) dan C (level 2).
- [x] Buat file `application/models/Ledger_model.php`.
    - Buat method `insert_transaction($user_id, $type, $amount, $description, $ref_type, $ref_id)`.
    - Method ini secara otomatis mengkalkulasi `$balance_after` dan meng-update kolom `balance` di tabel `users`.
    - Menggunakan `SELECT ... FOR UPDATE` untuk mencegah race condition.
- [x] Buat file `application/models/Product_model.php`.
    - Buat method `get_all_active_products()`.
- [x] Buat file `application/models/Rental_model.php`.
    - Buat method `create_rental($data)` dan `get_user_rentals($user_id)`.
- [x] Buat file `application/models/Wallet_model.php`.
    - Methods: `get_balance()`, `create_deposit()`, `get_pending_deposits()`, `get_ledger_history()`, `approve_deposit_simulator()`.
    - Balance dihitung dari `wallet_ledger` (SUM credit - SUM debit), bukan statis.
    - Deposit approval dibungkus `$this->db->trans_start()` / `$this->db->trans_complete()`.

## Phase 2: Authentication, Security & Onboarding — ✅ [COMPLETED]
**Tujuan:** Mengunci sistem agar hanya *user* terdaftar yang bisa masuk.
- [x] Setup file `application/config/routes.php`.
    - `$route['login'] = 'auth/login';`
    - `$route['register'] = 'auth/register';`
    - `$route['logout'] = 'auth/logout';`
- [x] Buat `application/controllers/Auth.php`.
    - **Method `register()`:** Validasi `phone` (is_unique[users.phone]), `password` (min_length[8]), dan `invite_code` (wajib diisi, cek apakah ada di database untuk di-set sebagai `parent_id`). Enkripsi sandi dengan `password_hash()`. Verifikasi Google reCAPTCHA v2 token server-side.
    - **Method `login()`:** Validasi input, verifikasi dengan `password_verify()`. Jika sukses, set session: `user_id`, `phone`, `level_id`.
    - **Method `logout()`:** Lakukan `sess_destroy()` dan redirect ke `/login`.
- [x] Buat UI Views menggunakan Tailwind CSS.
    - `application/views/auth/login.php`
    - `application/views/auth/register.php` — Menggunakan Google reCAPTCHA v2 widget (bukan GD captcha).
- [x] Buat Middleware/Hook untuk mengecek session. Jika `!$this->session->userdata('user_id')`, redirect otomatis ke halaman login untuk semua rute kecuali `/login` dan `/register`.

## Phase 3: Core UI Shell & Dashboard — ✅ [COMPLETED]
**Tujuan:** Menerapkan desain *Mobile-First* yang konsisten di semua halaman.
- [x] Buat `application/views/templates/header.php` (Load Tailwind CDN, Meta Tags untuk mobile, Font Awesome icons).
- [x] Buat `application/views/templates/bottom_nav.php` (Menu persisten: Home, Sewa Saya, Bantuan, Marketplace, Profil). Active state highlighting berdasarkan URI segment.
- [x] Buat `application/views/home/index.php` (Dashboard with balance card).
- [x] Terapkan layout PWA-style (max-width: 480px, margin auto) agar di layar desktop sekalipun terlihat seperti tampilan *mobile app*.
- [x] Implementasi "Clean Dashboard" rule: secondary forms (Top-Up inputs) di-hidden default, toggle via primary button.
- [x] Implementasi balance card dengan "Bloomberg Terminal" aesthetic: `bg-slate-900`, `text-white`, monospace font untuk angka.

## Phase 4: Marketplace & UX — ✅ [COMPLETED]
**Tujuan:** Mengaktifkan pembelian dan pelacakan aset oleh *user* dengan checkout frictionless.
- [x] Buat `application/controllers/Marketplace.php`.
- [x] Method `index()`: Ambil data dari `Product_model->get_all_active_products()` dengan fallback dummy data. Kirim `user_balance` ke view.
- [x] Buat `application/views/marketplace/index.php`.
- [x] Implementasi "One-Screen Checkout" — dynamic Vanilla JS Bottom Sheet Modal:
    - Slide-up animation (`translate-y-full` → `translate-y-0`, 300ms ease-out).
    - Client-side balance check: toggle "Konfirmasi & Bayar" (emerald) vs "Isi Saldo" (rose).
    - IDR formatting via `Intl.NumberFormat('id-ID')`.
    - Modal overlay: `bg-black/60 backdrop-blur-sm`.
    - z-index: `z-[60]` (above Bottom Navigation `z-50`).
- [x] Buat `application/controllers/Wallet.php` — index(), topup(), simulate_payment().
- [x] Buat `application/views/wallet/index.php`.
    - Balance card: `bg-slate-900` terminal aesthetic.
    - Top-Up form hidden by default, toggled via "Top Up" button.
    - Quick-amount grid (100K–2.5M IDR) + custom input.
    - Pending deposits section with "Simulasi Bayar" action.
    - Ledger history with credit/debit visual indicators.

## Phase 5: Wallet & Ledger System — ✅ [COMPLETED]
**Tujuan:** Membangun *double-entry system* yang akurat, two-tier deposit pipeline, dan modul perbankan.
- [x] Buat file `application/models/Wallet_model.php` dengan two-tier architecture:
    - **Tier 1 (Pending):** `create_deposit()` inserts into `deposits` table with `status = 'pending'`.
    - **Tier 2 (Ledger):** `approve_deposit_simulator()` wraps in `$this->db->trans_start()`:
        1. Updates `deposits.status` → `success`.
        2. Inserts `credit` entry into `wallet_ledger`.
- [x] Balance calculation via `wallet_ledger`: `SUM(credit) - SUM(debit)`.
- [x] Pending deposits display on Wallet page with invoice number and "Simulasi Bayar" button.
- [x] Ledger history view with credit/debit visual indicators and date formatting.
- [x] **Modul Rekening Bank:**
    - Tambah views: `application/views/wallet/bank_bind.php`.
    - Method `bind_bank()`: Validasi form nama bank, nomor rekening, nama pemilik. Insert ke tabel `bank_accounts`.
- [x] **Modul Penarikan (Withdrawal):**
    - Tambah views: `application/views/wallet/withdraw.php`.
    - Method `process_withdraw()`:
        1. Validasi Jam (07:00 - 19:00).
        2. Cek Saldo `balance` >= `gross_amount`.
        3. Cek minimum penarikan Rp 30.000.
        4. Hitung `fee_amount` menggunakan fungsi bertingkat (if-else berjenjang sesuai PRD).
        5. Eksekusi `Ledger_model->insert_transaction` (tipe: withdrawal, amount: negatif).
        6. Insert ke tabel `withdrawals` (status: pending).
- [x] **Halaman Riwayat:**
    - Tambah views: `application/views/wallet/history.php` dengan fitur filter (Deposit, Penarikan, Pendapatan).

---

## Phase 6: Rentals & Asset Execution — 🔄 [IN PROGRESS]
**Tujuan:** Memproses logika sewa aktual, memotong saldo dari `wallet_ledger`, dan menampilkan node aktif di halaman "Sewa Saya".

- [ ] Buat `application/controllers/Rentals.php`.
    - Method `create($product_id)`:
        1. Validasi user session dan product existence.
        2. Hitung balance dari `wallet_ledger` (bukan `users.balance`).
        3. Validasi saldo >= harga produk.
        4. Dalam satu DB transaction (`trans_start` / `trans_complete`):
            a. Insert `debit` record ke `wallet_ledger` (description: "Rental: {product_name}").
            b. Insert record ke `rentals` (status: `active`, `started_at`, `ends_at`).
        5. Redirect ke "Sewa Saya" dengan flash success message.
    - Method `index()` (Sewa Saya page):
        1. Fetch all `rentals` WHERE `user_id` dan `status IN ('active', 'completed')`.
        2. Join dengan `gpu_products` untuk nama dan detail.
        3. Render progress bar: `days_processed` / `total_days`.
        4. Hitung total pendapatan kumulatif per rental.
- [ ] Buat `application/views/rentals/my_sites.php`.
    - Card per rental: product name, status badge, progress bar, start/end dates, cumulative earnings.
    - Empty state: illustration + "Mulai Sewa Sekarang" CTA ke Marketplace.
- [ ] Buat model `Rental_model.php` — update method `get_user_rentals()`:
    - Join dengan `gpu_products` untuk detail produk.
    - Order by `status` (active first) then `created_at` DESC.
- [ ] Implementasi daily ROI cron job (opsional — bisa deferred ke Phase 8):
    - Controller `application/controllers/cli/Automations.php` → method `daily_roi()`.
    - Loop semua `rentals` aktif → increment `days_processed` → insert `credit` ke `wallet_ledger`.
    - Jika `days_processed == total_days` → ubah status ke `completed`.

---

## Phase 7: Production Payment Gateway — 📋 [PLANNED]
**Tujuan:** Menggantikan Development Simulator (deposit approval manual) dengan integrasi payment gateway production-grade.

- [ ] Evaluasi provider: **Midtrans** vs **Xendit** (keputusan berdasarkan fee structure, settlement speed, dan API maturity).
- [ ] Buat `application/models/PaymentGateway_model.php`.
    - Method `create_charge($invoice_number, $amount)` → generate Snap URL / invoice link.
    - Method `handle_callback($payload)` → verifikasi signature, update `deposits.status`, mint `wallet_ledger` credit.
- [ ] Implementasi webhook/callback endpoint:
    - `application/controllers/Payment_callback.php` — handles POST from gateway.
    - Verifikasi signature HMAC untuk mencegah spoofing.
    - Idempotency check: skip jika `deposits.status` sudah `success`.
- [ ] Ganti "Simulasi Bayar" button di Wallet page dengan redirect ke payment gateway Snap/Invoice page.
- [ ] Implementasi payment status polling (fallback untuk webhook failure).
- [ ] Buat admin panel section untuk manual approval (opsional, untuk edge cases).

---

## Phase 8: Affiliate System & Background Automation — 📋 [PLANNED]
**Tujuan:** Membangun *logic* multi-level dan otomatisasi pembagian komisi/gaji.

- [ ] Buat `application/controllers/Team.php`.
    - Method `index()`: Hitung total agen Level 1 (B) dan Level 2 (C). Render ke view `application/views/team/index.php` yang berisi QR Code dan URL *Referral*.
- [ ] **CRON JOB 1: Pendapatan Harian (Daily Revenue).**
    - Buat `application/controllers/cli/Automations.php` → Method `daily_roi()`.
    - Cari semua `rentals` status 'active'.
    - Loop: Insert `credit` ke `wallet_ledger` (daily_rate). Increment `days_processed`. Jika `days_processed == total_days`, ubah status ke 'completed'.
- [ ] **CRON JOB 2: Gaji Mingguan (Weekly Wage).**
    - Di controller `Automations.php` → Method `weekly_wage()`.
    - Loop semua user, hitung jumlah downline aktif B+C.
    - Cek kondisi Level 1-6. Jika memenuhi syarat, distribusikan komisi ke `wallet_ledger` (type: `credit`, description: "Weekly Wage Level N").
