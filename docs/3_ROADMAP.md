# Roadmap & Technical To-Do List v4.0 (Micro-Execution)
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

## Phase 6: Rentals & Asset Execution — ✅ [COMPLETED]
**Tujuan:** Memproses logika sewa aktual, memotong saldo dari `wallet_ledger`, dan menampilkan node aktif di halaman "Sewa Saya".

- [x] Buat `application/controllers/Rentals.php`.
    - Method `create($product_id)`:
        1. Validasi user session dan product existence.
        2. Hitung balance dari `wallet_ledger` (bukan `users.balance`).
        3. Validasi saldo >= harga produk.
        4. Dalam satu DB transaction (`trans_start` / `trans_complete`):
            a. Insert `debit` record ke `wallet_ledger` (description: "Rental: {product_name}").
            b. Insert record ke `user_rentals` (status: `active`, `started_at`, `expired_at`).
        5. Redirect ke "Sewa Saya" dengan flash success message.
    - Method `index()` (Sewa Saya page):
        1. Fetch all `user_rentals` WHERE `user_id` dan `status = 'active'`.
        2. Join dengan `gpu_products` untuk nama dan detail.
        3. Render progress bar berdasarkan `expired_at`.
        4. Hitung total pendapatan kumulatif per rental.
- [x] Buat `application/views/rentals/index.php`.
    - Card per rental: product name, status badge, progress bar, start/end dates, cumulative earnings.
    - Empty state: illustration + "Mulai Sewa Sekarang" CTA ke Marketplace.
- [x] Buat model `Rental_model.php` — methods `get_active_rentals()`, `claim_roi()`, `get_rental()`.
    - Join dengan `gpu_products` untuk detail produk.
    - Order by `created_at` DESC.
- [x] Implementasi Daily ROI Claim — Manual claim via `POST /rentals/claim/{id}`.
    - Validasi: cek `last_claimed_at` — jika sudah hari ini, tolak.
    - ACID transaction: update `last_claimed_at` + insert `credit` ke `wallet_ledger`.
    - User bisa klaim sekali per hari per rental.

---

## Phase 7: Admin Command Center & Fintech Guardrails — ✅ [COMPLETED]
**Tujuan:** Membangun panel admin terpisah (Bloomberg Terminal aesthetic), privilege separation, guardrails finansial, dan global layout architecture.

### 7A: Dual Authentication & Privilege Separation
- [x] Buat `application/controllers/Admin_auth.php` — Admin login handler.
    - Validasi `username` + `password_verify()` terhadap tabel `admins`.
    - Set session `admin_id` + `admin_username`.
    - Redirect ke `/admin` jika sukses, flash error jika gagal.
- [x] Buat route cloaked `$route['control-panel'] = 'Admin_auth/login'`.
    - URL `/control-panel` tidak dilink dari UI mana pun — hanya diketahui admin.
- [x] Buat `application/controllers/Admin.php` — Admin dashboard + queue operations.
    - Constructor: cek `admin_id` di session → redirect ke `/control-panel` jika absent.
    - Method `index()`: Ambil pending deposits + pending withdrawals, render ke `admin/dashboard`.
    - Method `approve_deposit($id)`: ACID transaction (update status + insert credit ke `wallet_ledger`).
    - Method `approve_withdrawal($id)`: ACID transaction (flip status ke `success`).
    - Method `decline_withdrawal($id)`: ACID transaction (flip status ke `failed` + auto-refund credit ke `wallet_ledger`).

### 7B: Command Center UI (Bloomberg Terminal Aesthetic)
- [x] Buat `application/views/admin/login.php` — Dark admin login form.
- [x] Buat `application/views/admin/dashboard.php` — Command Center dashboard.
    - Theme: `bg-slate-950`, `text-slate-300`, `font-mono text-sm`.
    - Header: `"SYNAPSE COMMAND CENTER // ROOT ACCESS"` — green terminal text (`text-green-400`).
    - Two-column grid: Pending Deposits (left) + Pending Withdrawals (right).
    - Each queue card: header with count badge, list items with amount (font-mono), phone, timestamp.
    - Actions: APPROVE (green) / DECLINE (slate) buttons with confirm dialogs.
    - Empty state: ∅ entity symbol + "No pending" message.
    - Footer: `"SYNAPSE ADMIN v1.0 — ROOT ACCESS"` + live timestamp.

### 7C: Advanced Fintech Guardrails
- [x] **Two-Tero Minimum Withdrawal Limit (Rp 100.000):** `Wallet::withdraw()` — reject `$amount < 100000` dengan flash message "Minimal penarikan adalah Rp 100.000".
- [x] **Single Pending Withdrawal Limit (Anti-Spam & Race-Condition Prevention):** `Wallet::withdraw()` — cek `$this->Wallet_model->has_pending_withdrawal($user_id)` sebelum proses. Jika ada pending WD, tolak. `Wallet_model::has_pending_withdrawal()` query `withdrawals` WHERE `status = 'pending'` LIMIT 1.
- [x] **Backend Auto-Rollback (Refund) for Declined Withdrawals:** `Admin::decline_withdrawal($wd_id)` — dalam satu `trans_start`/`trans_complete`:
    1. Update `withdrawals.status` → `failed`.
    2. Insert `credit` record ke `wallet_ledger` (amount: `$wd->amount`, description: "Pengembalian Dana: Penarikan Ditolak ({wd_number})").
    → Dana dikembalikan secara atomic tanpa double-spend.

### 7D: Global Layout Architecture (Balance Capsule)
- [x] Buat `application/core/MY_Controller.php` — Base controller untuk semua user-facing controllers.
    - Constructor: skip auth check untuk controller `auth`. Setelah auth ok, load `Wallet_model` dan inject `$global_balance` ke semua view via `$this->load->vars()`.
- [x] Update `application/views/templates/header.php` — Tambahkan Balance Capsule di Top Navbar.
    - `<a href="/wallet">` dengan ikon `fa-wallet` (text-indigo-500) + `Rp {global_balance}` (font-mono font-bold).
    - Sticky header `z-40`, capsule berada di sisi kanan.
- [x] Semua user-facing controller extends `MY_Controller` → otomatis mendapat balance injection.

---

## Phase 8: Payment Gateway Integration (JayaPay) — 📋 [PLANNED]
**Tujuan:** Menggantikan Development Simulator ("Simulasi Bayar") dengan integrasi payment gateway JayaPay yang sesungguhnya — auto-crediting deposits via webhook callback tanpa intervensi admin manual.

### 8A: Database & Config Setup
- [ ] Tambah kolom `payment_method` (VARCHAR 50, NULLABLE) di tabel `deposits` untuk mencatat metode pembayaran (contoh: `jaya_pay`, `manual`).
- [ ] Tambah kolom `gateway_response` (JSON/TEXT, NULLABLE) di tabel `deposits` untuk menyimpan raw callback payload (audit trail).
- [ ] Buat file `application/config/jayapay.php`:
    - `$config['jayapay_merchant_id']` — Merchant ID dari JayaPay dashboard.
    - `$config['jayapay_api_key']` — API Key untuk server-side requests.
    - `$config['jayapay_callback_secret']` — Secret key untuk verifikasi HMAC signature callback.
    - `$config['jayapay_sandbox']` — Boolean toggle sandbox vs production.
    - `$config['jayapay_api_base']` — Base URL API endpoint.

### 8B: JayaPay API Integration
- [ ] Buat `application/libraries/Jayapay.php` — Library wrapper untuk JayaPay REST API:
    - Method `create_invoice($invoice_number, $amount, $customer_phone)`:
        - POST ke JayaPay `/api/v1/invoice/create` dengan parameter: `merchant_id`, `invoice`, `amount` (IDR integer), `customer_phone`, `callback_url` (endpoint webhook), `expiry` (default 24 jam).
        - Return: `invoice_url` (redirect user) + `invoice_id` (JayaPay reference).
    - Method `verify_callback($payload, $signature)`:
        - Generate HMAC-SHA256 dari JSON payload menggunakan `jayapay_callback_secret`.
        - Compare dengan signature dari header callback.
    - Method `check_status($invoice_id)`:
        - GET ke JayaPay `/api/v1/invoice/{invoice_id}/status`.
        - Return: `paid`, `expired`, `failed`.

### 8C: Deposit Flow — Production
- [ ] Update `Wallet::topup()` — setelah insert ke `deposits` table, panggil `$this->jayapay->create_invoice()`:
    1. Insert deposit record (`status = 'pending'`, `payment_method = 'jaya_pay'`).
    2. Call JayaPay API → dapat `invoice_url`.
    3. Redirect user ke `invoice_url` (alihkan ke JayaPay payment page).
    4. Jika API call gagal, fallback: simpan deposit dengan `status = 'failed'`, tampilkan error.
- [ ] Update `application/views/wallet/index.php` — Ganti tombol "Simulasi Bayar" dengan:
    - Redirect ke JayaPay payment page (buka di tab baru atau inline iframe).
    - Tampilkan status "Menunggu Pembayaran" dengan timer expiry.
    - Polling status opsional (via JS `setInterval` ke endpoint status).

### 8D: Webhook/Callback Listener
- [ ] Buat `application/controllers/Payment_callback.php` — menerima POST dari JayaPay:
    - Method `jaya_pay()`:
        1. Baca raw request body (`$this->input->raw_input_stream`).
        2. Verifikasi HMAC signature dari header `X-Callback-Signature`.
        3. Jika signature invalid → HTTP 401, log warning.
        4. Cari deposit via `invoice_number` di tabel `deposits`.
        5. **Idempotency guard:** Jika `deposits.status` sudah `success`, return HTTP 200 OK (skip — mencegah double-credit).
        6. ACID transaction:
            a. Update `deposits.status` → `success`.
            b. Insert `credit` ke `wallet_ledger` (description: "Top Up via JayaPay ({invoice_number})").
            c. Simpan raw callback payload ke `deposits.gateway_response`.
        7. Return HTTP 200 OK ke JayaPay.
    - Method not allowed: reject GET request ke endpoint ini (HTTP 405).

### 8E: Admin Override
- [ ] Retain manual approve/decline buttons di Command Center untuk edge cases (callback miss, dispute).
- [ ] Admin dashboard tetap menampilkan pending deposits dari metode `manual` saja (jika JayaPay sudah auto-process).

---

## Phase 9: ROI Engine & Asset Automation — 📋 [PLANNED]
**Tujuan:** Menghilangkan manual claimROI dan menggantinya dengan otomatisasi latar belakang via Cron Job (server-side).

### 9A: Background Worker Setup
- [ ] Buat `application/controllers/cli/Automations.php` — CLI controller untuk tugas terjadwal.
    - Method `daily_roi()`:
        1. Query `user_rentals` WHERE `status = 'active'`.
        2. Untuk setiap rental:
            a. Check if `last_processed_at` < current_date.
            b. ACID Transaction:
                i. Insert `credit` ke `wallet_ledger` (amount: `daily_roi`, description: "Daily ROI Auto-Credit: {product_name}").
                ii. Increment `days_processed` counter.
                iii. Update `last_processed_at` → current_timestamp.
            c. Check if `days_processed == total_days`:
                i. Update status → `completed`.
                ii. Insert `credit` record (amount: `purchase_price` if `is_refundable` = 1, description: "Refund Asset Investment").
- [ ] Setup Server Cron Job (Linux `crontab`):
    - `0 0 * * * php /var/www/html/index.php automations daily_roi` (Run every midnight).
- [ ] Implement Error Handling & Logging:
    - Log setiap eksekusi ke `application/logs/roi_automation.log` (User ID, Rental ID, Amount, Status).

### 9B: ROI Dashboard Update
- [ ] Update `application/views/rentals/index.php`:
    - Ganti tombol "Klaim ROI" (Manual) menjadi status "Auto-Processed" indicator.
    - Tampilkan "Next Credit: Tomorrow 00:00".
    - Render "Total Earnings to Date" secara real-time dari `wallet_ledger`.
- [ ] Tambah "ROI History" tab: list harian kapan ROI masuk ke wallet.

### 9C: Affiliate System & Weekly Wage Automation
- [ ] Buat `application/controllers/Team.php`.
    - Method `index()`: Hitung total agen Level 1 (B) dan Level 2 (C). Render ke view `application/views/team/index.php` yang berisi QR Code dan URL *Referral*.
- [ ] **CRON JOB 2: Gaji Mingguan (Weekly Wage).**
    - Di controller `Automations.php` → Method `weekly_wage()`.
    - Loop semua user, hitung jumlah downline aktif B+C.
    - Cek kondisi Level 1-6. Jika memenuhi syarat, distribusikan komisi ke `wallet_ledger` (type: `credit`, description: "Weekly Wage Level N").

---

## Phase 10: Enterprise Auditing & Security — 📋 [PLANNED]
**Tujuan:** Menambahkan layer audit trail dan keamanan tingkat tinggi untuk mencegah manipulasi data finansial.

### 10A: System Audit Trail
- [ ] Buat tabel `system_audit_logs`:
    - `id` (BIGINT, PK, Auto Increment), `admin_id` (BIGINT, NULLABLE), `user_id` (BIGINT, NULLABLE), `action` (VARCHAR 100), `details` (TEXT), `ip_address` (VARCHAR 45), `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP).
    - Foreign Key: `admin_id` → `admins.id` (ON DELETE SET NULL), `user_id` → `users.id` (ON DELETE SET NULL).
- [ ] Implement Audit Logging di Admin Controller:
    - Setiap `approve_deposit()`: log "Approved deposit {invoice_number}".
    - Setiap `approve_withdrawal()`: log "Approved withdrawal {wd_number}".
    - Setiap `decline_withdrawal()`: log "Declined withdrawal {wd_number} — refund issued".
- [ ] Buat Admin Audit View: `application/views/admin/audit.php` — tabel logs yang dapat difilter berdasarkan action/user/date range.
- [ ] Buat route `$route['admin/audit'] = 'admin/audit'` dan method `Admin::audit()`.

### 10B: Security Hardening
- [ ] Implement Rate Limiting pada endpoint sensitif (`/login`, `/register`, `/wallet/withdraw`) menggunakan CI3 hook atau middleware + DB-based counter.
- [ ] Session Hijacking Protection: simpan `user_agent` + `ip_address` di session userdata; invalidate session jika user_agent berubah secara signifikan.
- [ ] SQL Injection Guard: audit semua model untuk memastikan 100% penggunaan query-binding (CI3 Active Record) — zero raw string interpolation di WHERE clauses.
- [ ] CSRF Token Refresh: pastikan setiap form POST menggunakan token baru setelah successful submission.
