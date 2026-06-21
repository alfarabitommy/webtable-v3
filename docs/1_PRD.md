# Product Requirements Document (PRD) v4.0
**Project Name:** Synapse (AI GPU Rental)
**Platform:** Web Application (100% Mobile-First / SPA-like Experience)
**Core Tech Stack:** CodeIgniter 3 (CI3), PHP 8.1, MySQL 8.4, Vanilla JavaScript, Tailwind CSS

---

## 1. Product Overview & Objective
Synapse adalah platform investasi dan penyewaan daya komputasi awan (GPUaaS). Pengguna dapat menyewa *node* GPU dengan durasi tertentu (Short-Term & Long-Term) untuk mendapatkan *Return of Investment* (ROI) berupa pendapatan harian. Sistem ini dilengkapi dengan akuntansi *double-entry ledger* yang ketat dan sistem afiliasi (*Agency*) multi-level berjenjang dengan *reward* pencapaian dan gaji mingguan otomatis.

---

## 2. Currency Standard (IDR Enforcement)

**All monetary values across the entire platform — including but not limited to product pricing, wallet balances, ledger entries, transaction records, rental fees, withdrawal amounts, commission bonuses, and UI display formatting — MUST strictly use Indonesian Rupiah (IDR).**

| Layer | Enforcement |
|-------|-------------|
| **Database** | All `DECIMAL` columns store raw IDR values (no decimals for sen; integer-level precision sufficient). |
| **Backend (PHP)** | All CI3 controller/model logic operates on IDR integers. No currency conversion. Fee calculations, balance checks, and ledger insertions are IDR-native. |
| **Frontend (JS)** | All monetary display uses `Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })` for consistent, locale-accurate formatting. Manual formatting via `number_format($val, 0, ',', '.')` with `Rp ` prefix is acceptable in PHP views. |

No foreign currencies (USD, USDT, BTC, etc.) are supported at any layer.

---

## 3. User Roles & Permissions

* **Guest (Unauthenticated):** Hanya dapat melihat halaman Login, Register, Lupa Sandi, dan Halaman Download/Landing Page.
* **User (Authenticated):** Memiliki dompet (*wallet*), dapat menyewa GPU, menarik dana, dan memiliki *referral link* untuk membangun tim/agensi.
* **Admin (System Operator):** Akses penuh ke **Command Center** (`/control-panel`) — gateway rahasia terpisah dari user flow. Memiliki hak approve/decline deposit & withdrawal, monitoring real-time queue, dan audit trail. Autentikasi terpisah menggunakan tabel `admins` (bukan `users`), session `admin_id`, dan middleware `Admin_auth` controller.
* **System/Cron:** Menjalankan tugas otomatis di latar belakang (pembagian hasil harian, perhitungan gaji mingguan agen).

---

## 4. Core Modules & Exact Business Logic

### A. Authentication & Onboarding
* **Dual Authentication Architecture (Hard Separation):**
    * Users authenticate via `users` table → session key `user_id` → managed by `Auth` controller → redirects to user-facing pages.
    * Admins authenticate via `admins` table → session key `admin_id` → managed by `Admin_auth` controller → redirects to Command Center (`/admin`).
    * **No cross-session sharing.** A user session cannot access admin routes and vice versa. The `Admin` controller constructor checks `admin_id` in session — absent users are redirected to `/control-panel` login.
    * **Cloaked Gateway:** The admin login URL is `/control-panel` (not `/admin/login`). The route is defined as `$route['control-panel'] = 'Admin_auth/login'`. No UI links point to this URL — it is known only to administrators.
* **Pendaftaran (Register):**
    * Kolom: Kode Undangan (Referral), Nomor Telepon (Unique, Numeric, Min: 9, Max: 15), Kata Sandi (Min: 8 karakter kombinasi huruf & angka).
    * **Constraint:** *Kode Undangan bersifat WAJIB*. Setiap user baru harus menjadi *downline* dari user lain.
    * Aksi Sistem: Saat pendaftaran berhasil, sistem men-generate "Kode Undangan" unik sepanjang 6 karakter alfanumerik untuk user baru tersebut.
* **Bot Protection — Google reCAPTCHA v2:**
    * The native CI3 GD-based captcha has been **replaced** by Google reCAPTCHA v2.
    * The registration form loads `https://www.google.com/recaptcha/api.js` and renders the `.g-recaptcha` widget with a valid site key.
    * Server-side validation: the `g-recaptcha-response` token is POSTed to Google's `siteverify` endpoint. Registration is rejected if the verification call returns `success: false`.
    * This eliminates OCR-susceptible image captchas while maintaining zero-friction UX for human users.
* **Login:** Menggunakan Nomor Telepon dan Kata Sandi. Session disimpan menggunakan sistem file CI3.
* **Profil:** User dapat mengunggah Avatar (format: JPG/PNG, max: 2MB) dan mengubah nama pengguna (maks 50 karakter).

### B. Wallet System (Two-Tier Architecture)

The wallet operates on a **two-tier model**: a temporary staging area for incoming deposits and an immutable ledger for confirmed funds.

#### Tier 1 — Pending Deposits (`deposits` table)
* User initiates a Top-Up by selecting an amount (preset grid: 100K–2.5M IDR, or custom input).
* System generates a unique invoice number (`INV-{YmdHis}-{user_id}`) and inserts a record into the `deposits` table with `status = 'pending'`.
* Pending deposits are displayed in a dedicated "Menunggu Pembayaran" section on the Wallet page.
* **No funds are available for spending until the deposit is approved.**

#### Tier 2 — Immutable Ledger (`wallet_ledger` table)
* Upon approval (currently via the **Development Simulator** — a one-click "Simulasi Bayar" button), the system executes an ACID-compliant transaction:
    1. Updates the deposit record's `status` from `pending` to `success`.
    2. Inserts a `credit` entry into `wallet_ledger` with the deposit amount.
* **Available Balance** is calculated dynamically: `SUM(credit) - SUM(debit)` from `wallet_ledger`. The balance is never stored as a static value on the user record for wallet operations.
* The `wallet_ledger` is **append-only** — records are never updated or deleted once written.

> **Note:** The Development Simulator exists solely for testing the deposit-to-ledger pipeline without a real payment gateway. It will be superseded by a production payment integration (see Roadmap Phase 7).

### C. Marketplace & One-Screen Checkout

The Marketplace page presents GPU product cards in a scrollable feed. Each card displays: product image, name, description, rental price (IDR), and daily ROI (IDR).

#### One-Screen Checkout (Bottom Sheet Modal)
When a user taps **"Sewa Sekarang"** on any product card, a **dynamic Vanilla JS Bottom Sheet Modal** slides up from the bottom of the screen — no page reload, no navigation.

The modal performs the following checks client-side:
1. Reads the user's `wallet_ledger` balance (passed from PHP as a JS variable at page load).
2. Compares `userBalance` against the product's `price`.
3. **If balance ≥ price:**
    * Displays product name, price, and current balance (all formatted as IDR via `Intl.NumberFormat`).
    * Renders a **"Konfirmasi & Bayar"** button that submits a POST form to `/rentals/create`.
    * Balance indicator turns **emerald** (`text-emerald-600`).
4. **If balance < price:**
    * Displays a **"Saldo Tidak Mencukupi — Isi Saldo"** link that redirects to `/wallet`.
    * Balance indicator turns **rose** (`text-rose-600`).

The modal overlay (`bg-black/60 backdrop-blur-sm`) closes on tap. The sheet animates via CSS `translate-y` transitions (300ms ease-out).

### D. Marketplace & Asset Rental (Sewa)
* **Katalog GPUaaS (Produk):**
    * Data produk harus dinamis dari database, menampilkan: Nama Produk, Tipe (Short/Long Term), Harga Sewa (Rp), Durasi (Hari), Pendapatan Harian (Rp), dan Estimasi Total Penghasilan.
    * Fallback: If the `gpu_products` table is empty, the controller renders a hardcoded set of four products for development/demo purposes.
* **Logika Transaksi Sewa:**
    * Upon form submission from the Bottom Sheet Modal, the `Rentals` controller validates the user's balance server-side.
    * Jika saldo < Harga Sewa: Redirect dengan error flash message.
    * Jika saldo >= Harga Sewa:
        1. Kurangi saldo user via `Ledger_model->insert_transaction()` (tipe: `rental_payment`, amount: negatif).
        2. Tambahkan data ke tabel `rentals` dengan status `active`, catat waktu `started_at` dan estimasi `ends_at`.
    * **Constraint:** Proses transaksi harus dibungkus dalam *Database Transaction* untuk menghindari saldo minus jika koneksi terputus.

### E. Financial Ledger & Wallet
* **Aturan Emas:** Saldo utama user dihitung dari kalkulasi mutasi di tabel `wallet_ledger` (Total Credit - Total Debit).
* **Penarikan Dana (Withdrawal):**
    * **Pengikatan Bank:** User wajib menambahkan Kartu Bank (Nama Bank, Nomor Rekening, Nama Pemegang) sebelum bisa melakukan penarikan.
    * **Jam Operasional:** Penarikan hanya dapat diajukan pada hari Senin - Sabtu pukul 07:00 - 19:00 WIB. Permintaan pada hari Minggu/di luar jam ditolak/ditunda.
    * **Batas Minimum & Maksimum:** Minimum Rp 100.000, Maksimum Rp 50.000.000 per penarikan.
    * **Limit Frekuensi:** Hanya diperbolehkan 1 kali penarikan per hari per user.
    * **Anti-Spam & Race-Condition Prevention (Single Pending WD Limit):** User TIDAK dapat mengajukan penarikan baru jika masih memiliki penarikan dengan status `pending`. Sistem mengecek `has_pending_withdrawal()` sebelum memproses — jika ada, permintaan ditolak dengan flash message. Ini mencegah spam dan race condition pengajuan ganda.
    * **Struktur Biaya Penarikan (Fee Calculation Rule):**
        * Rp 20.000 ~ Rp 500.000: Biaya 10% + Rp 6.500
        * Rp 500.000 ~ Rp 1.000.000: Biaya 7.5% + Rp 6.500
        * Rp 1.000.000 ~ Rp 2.000.000: Biaya 6.5% + Rp 6.500
        * Rp 2.000.000 ~ Rp 5.000.000: Biaya 5% + Rp 6.500
        * Rp 5.000.000 ~ Rp 10.000.000: Biaya 4% + Rp 6.500
        * Rp 10.000.000 ~ Rp 50.000.000: Biaya 3% + Rp 6.500
    * Dana yang dikurangi dari saldo user adalah `gross_amount` (Penarikan Kotor).
    * **Auto-Rollback on Decline:** Jika admin menolak penarikan (`decline_withdrawal`), sistem secara otomatis mengembalikan dana ke wallet user dengan insert `credit` record ke `wallet_ledger` (description: "Pengembalian Dana: Penarikan Ditolak ({wd_number})"). Proses refund dibungkus dalam satu DB transaction (`trans_start` / `trans_complete`) bersama status update ke `failed` — menjamin atomicity.
* **Balance Capsule (Global Header):**
    * Saldo user ditampilkan secara persisten di Top Navbar setiap halaman.
    * Powered by `MY_Controller.php` — constructor meng-inject `$global_balance` ke semua view via `$this->load->vars()`.
    * Komponen `header.php` merender balance capsule: tombol ke `/wallet` dengan ikon dompet, font monospace `Rp {balance}`.

### F. System Automation (Cron Jobs)
* **Distribusi Pendapatan Harian (Daily Revenue):**
    * Dieksekusi setiap hari pada jam 00:01 WIB.
    * Sistem mencari semua `rentals` dengan status `active` yang belum mencapai `total_days`.
    * Menambahkan saldo harian ke user (Insert ke tabel `transactions` dengan tipe `daily_revenue`).
    * Jika `days_processed` mencapai `total_days`, ubah status `rentals` menjadi `completed`.

### G. Affiliate & Agency System (MLM Structure)
* **Hierarki:** Sistem melacak keturunan langsung (Agen B) dan keturunan lapis kedua (Agen C). Kombinasi B+C disebut sebagai *Total Active Downline*.
* Definisi **Active Downline**: User *downline* yang minimal pernah melakukan 1 kali transaksi sewa produk GPU.
* **Agency Rules & Rewards:**
    * **Level 1:** Syarat (3 Agen B+C Aktif & Total Sales B+C > Rp 330.000). Reward: Rp 80.000 (Bonus pencapaian satu kali/One-time).
    * **Level 2:** Syarat (9 Agen B+C Aktif). Reward: Gaji Rp 200.000 / Minggu.
    * **Level 3:** Syarat (18 Agen B+C Aktif). Reward: Gaji Rp 500.000 / Minggu.
    * **Level 4:** Syarat (40 Agen B+C Aktif). Reward: Gaji Rp 1.500.000 / Minggu.
    * **Level 5:** Syarat (90 Agen B+C Aktif). Reward: Gaji Rp 4.000.000 / Minggu.
    * **Level 6:** Syarat (190 Agen B+C Aktif). Reward: Gaji Rp 9.000.000 / Minggu.
* **Otomatisasi Gaji:** Cron Job berjalan setiap hari Senin pukul 01:00 WIB untuk mengevaluasi kualifikasi setiap user dan mendistribusikan "Weekly Wage" ke dompet mereka secara otomatis.

---

## 5. UI/UX & Frontend Guidelines
* **Visual Murni:** 100% menggunakan Tailwind CSS via CDN atau Build. TIDAK BOLEH menggunakan Bootstrap.
* **Interaktivitas:** Menggunakan Vanilla JavaScript. Semua *pop-up* (seperti konfirmasi *withdraw*, *alert* sukses/gagal, *modal* ubah profil, *Bottom Sheet Checkout*) harus dirender tanpa *reload* halaman.
* **Navigasi:** *Bottom Navigation Bar* persisten di 5 halaman utama (Home, Sewa Saya, Bantuan, Marketplace, Profil).
* **Z-Index Layering:** Bottom Navigation sits at `z-50`. All Bottom Sheet Modals MUST be `z-[60]` to render above the nav bar.
* **Financial Display:** All IDR values formatted via `Intl.NumberFormat('id-ID')` in JavaScript or `number_format()` in PHP with `Rp ` prefix. No raw unformatted numbers in UI.
* **Form Visibility:** Secondary input forms (e.g., Top-Up amount selection) are hidden by default (`hidden` class) and toggled via a primary action button to conserve screen real estate.

---

## 6. Security & OpSec Requirements
* **CSRF Protection:** Wajib diaktifkan di konfigurasi CI3 (`$config['csrf_protection'] = TRUE;`).
* **Bot Protection:** Google reCAPTCHA v2 on registration. Server-side token verification against Google's API.
* **Rate Limiting / Mutex Lock:** Halaman eksekusi finansial (Tombol Beli Sewa, Tombol Tarik Dana) wajib memiliki *lock* atau *disable state* pada JavaScript dan divalidasi di PHP agar tidak terjadi *Double Spending* jika *user* melakukan klik dua kali dengan cepat.
* **Data Masking:** Rekening bank yang tampil di antarmuka harus disensor sebagian (misal: 1234*****789).
* **Infrastruktur:** Aplikasi akan di-deploy di balik proxy (seperti Cloudflare). Konfigurasi CI3 harus menangkap `HTTP_X_FORWARDED_FOR` untuk mencatat log IP asli user, bukan IP dari proxy.

---

## 7. Admin Command Center (Phase 7)

### A. Dual Authentication & Privilege Separation
| Layer | User Auth | Admin Auth |
|-------|-----------|------------|
| **Table** | `users` | `admins` |
| **Session Key** | `user_id` | `admin_id` |
| **Controller** | `Auth` | `Admin_auth` |
| **Login URL** | `/login` | `/control-panel` (cloaked) |
| **Middleware** | `MY_Controller` session check | `Admin` constructor session check |
| **Dashboard** | `/home` | `/admin` |

Routes are hard-separated. No user controller can serve admin views. The `/control-panel` URL is not linked from any UI — known only to operators.

### B. Command Center Dashboard (Bloomberg Terminal Aesthetic)
- Dark theme: `bg-slate-950`, `text-slate-300`, `font-mono text-sm`
- Header: `"SYNAPSE COMMAND CENTER // ROOT ACCESS"` in green terminal text
- Two-column grid layout:
  - **Left:** Pending Deposits queue — display invoice number, phone, amount (IDR), timestamp, APPROVE button
  - **Right:** Pending Withdrawals queue — display WD number, phone, bank details, amount (IDR), timestamp, APPROVE/DECLINE buttons
- Empty state: ∅ icon + "No pending deposits/withdrawals"
- System info footer: version + timestamp

### C. Queue Operations (ACID-Compliant)
- **Deposit Approval:** Transaction wraps deposit status update + `wallet_ledger` credit insert
- **Withdrawal Approval:** Transaction updates withdrawal status to `success` (funds already debited at request time)
- **Withdrawal Decline (Auto-Rollback):** Transaction updates status to `failed` + inserts `credit` refund record to `wallet_ledger` — funds restored atomically

### D. Global Layout Architecture
- `MY_Controller.php` extends `CI_Controller` and injects `$global_balance` into every view via `$this->load->vars()`
- Header (`templates/header.php`) renders persistent Balance Capsule: `<a href="/wallet">` with wallet icon + `Rp {balance}` in monospace
- Balance calculated on every page load from `wallet_ledger` (SUM credit - SUM debit) — always real-time
