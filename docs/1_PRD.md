# Product Requirements Document (PRD) v5.0
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
    * Kolom: Kode Undangan (Referral), Nomor Telepon (Unique, Numeric), Kata Sandi (Min: 8 karakter kombinasi huruf & angka).
    * **Constraint:** *Kode Undangan bersifat WAJIB*. Setiap user baru harus menjadi *downline* dari user lain.
    * Aksi Sistem: Saat pendaftaran berhasil, sistem men-generate "Kode Undangan" unik sepanjang 6 karakter alfanumerik untuk user baru tersebut.
* **Phone Sanitization Rule — "Flexible Frontend, Strict Backend" (v5.0):**
    * **Backend (Server-Side, MANDATORY):** Before any DB insert or lookup, the backend MUST normalize the phone number:
        1. Strip all non-digit characters: remove `+`, `-`, `()`, spaces, dots.
        2. Auto-convert leading `62` (Indonesian country code) → `0`.
        3. Auto-convert leading `0062` → `0`.
        4. Final normalized format: `0XXXXXXXXXX` (starts with `0`, 10–14 digits total).
        5. Backend regex validation: `/^0[0-9]{9,13}$/` — REJECT if mismatch after normalization.
    * **Frontend (Client-Side):** Use `type="tel"` and `inputmode="numeric"` for mobile keyboard optimization. Frontend applies the same stripping/conversion rules in real-time as the user types (on `input` event) so the preview shows normalized format. **No rigid `maxlength` or `minlength` HTML attributes** — the backend regex is the single source of truth for validation.
    * **Applies to:** Registration (`/auth/register`), Login (`/auth/login`), Profile phone update, and Admin user creation.
    * **DB Constraint:** The `users.phone` column remains `VARCHAR 20, UNIQUE, NOT NULL` — stores the normalized `0XXXXXXXXXX` format only.
* **Bot Protection — Native SVG CAPTCHA (M8 / plan/72):**
    * Google-hosted CAPTCHA telah **dipurge total** (plan/72): tidak ada lagi dependensi eksternal, whitelisting domain, maupun panggilan cURL verifikasi token ke pihak ketiga — menghilangkan kelas kegagalan transport (errno 77 / CA bundle) dari alur login & registrasi.
    * Login **dan** Register mewajibkan **Kode Keamanan**: 5 karakter alfanumerik dari alfabet 56 glyph yang **mengecualikan karakter ambigu** (`0, O, o, 1, I, l`).
    * Kode dirender sebagai **SVG inline transparan** (zero dependensi: tanpa GD/Imagick, tanpa file gambar) dengan rotasi per-karakter ±22°, jitter posisi, garis & titik noise, serta palet netral-tema **indigo/cyan/violet** agar tajam di kartu terang (`bg-white`) maupun gelap (`dark:bg-slate-800`).
    * Validasi server: pencocokan **case-insensitive** (`strtolower(trim($input)) === strtolower($stored)`), **single-use ketat** — challenge sesi `auth_captcha` langsung di-flush pada SETIAP evaluasi (anti replay), dan **TTL 180 detik (3 menit)**. Gagal/kedaluwarsa menampilkan: *"Kode keamanan salah atau sudah kedaluwarsa."*
    * Tombol refresh memuat challenge baru via endpoint JSON `auth/refresh_captcha` tanpa me-refresh halaman.
    * Rate limiting (10B) dan normalisasi nomor telepon (M5) tetap dijalankan pada urutan yang sama seperti sebelum migrasi.
* **Login:** Menggunakan Nomor Telepon dan Kata Sandi. Session disimpan menggunakan sistem file CI3. Phone undergoes the same backend sanitization before lookup.
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
* **Zero-Trust Level 1 Bonus Claim (v5.0):**
    * The Level 1 Bonus (Rp 80.000 one-time) is claimed manually via the Team page "Klaim Bonus" button. Frontend button sends `POST /team/claim-level1`.
    * **Server-Side Validation (Zero-Trust):** The backend CANNOT trust frontend-passed counts. It MUST re-validate ALL conditions from scratch inside a single ACID transaction (`trans_start` / `trans_complete`):
        1. **Re-query active downlines:** Call `User_model->get_downlines($user_id, 1)` and `get_downlines($user_id, 2)`. Filter for "active" (has ≥ 1 rental in `user_rentals`).
        2. **Re-sum total sales:** SUM `purchase_price` from `user_rentals` for all active Level 1 + Level 2 downlines.
        3. **Check qualification:** active_agents ≥ 3 AND total_sales ≥ 330000.
        4. **Check idempotency:** Query `wallet_ledger` for existing record with `description LIKE '%Bonus Level 1%'` for this `user_id`. If exists → reject with "Bonus sudah diklaim."
        5. **Check balance race:** `SELECT ... FOR UPDATE` on `users` row to prevent concurrent claim.
        6. **Credit + Log:** Insert `credit` record into `wallet_ledger` (amount: 80000, description: "Bonus Level 1 Agency"). Insert into `transactions` (type: `commission_bonus`).
    * **Response:** `{ success: true, message: "Bonus Level 1 berhasil diklaim!", data: { active_agents, total_sales, bonus_amount } }` — or error JSON on failure.
    * **Failure Modes:** Insufficient agents → "Agen aktif belum mencukupi." Insufficient sales → "Total sales belum mencapai Rp 330.000." Already claimed → "Bonus sudah diklaim." Any transaction error → full rollback.
* **Otomatisasi Gaji:** Cron Job berjalan setiap hari Senin pukul 01:00 WIB untuk mengevaluasi kualifikasi setiap user dan mendistribusikan "Weekly Wage" ke dompet mereka secara otomatis.

### H. Interactive Notification System (v5.0)

A DB-driven, AJAX-powered notification system that delivers real-time alerts for commissions, system messages, and account events — without requiring page reloads.

* **Database:** `user_notifications` table (see ERD v5.0 §5). Stores notification records per-user with `is_read` state.
* **Bell Icon (Global Header):**
    * Rendered in `header.php` inside the sticky top bar, positioned between Balance Capsule and profile icon.
    * Container: `<button id="notif-bell" class="relative p-2 rounded-full hover:bg-slate-100 transition">` with FontAwesome `fa-bell` icon (`text-slate-500`).
    * **Red Badge (Unread Count):** `<span id="notif-badge" class="absolute -top-0.5 -right-0.5 flex items-center justify-center w-5 h-5 text-[10px] font-bold text-white bg-red-500 rounded-full hidden"></span>` — hidden when count is 0, shown with count when > 0.
    * Badge text updates via AJAX: `GET /notifications/unread-count` → `{ count: N }`. Badge shows `N` if ≤ 99, otherwise `"99+"`.
* **Dropdown Popover:**
    * On bell click, toggle dropdown below the header bar.
    * Container: `<div id="notif-dropdown" class="hidden absolute right-0 top-full mt-2 w-80 max-h-96 bg-slate-800 rounded-xl shadow-2xl overflow-hidden z-[60]">`.
    * **Header:** "Notifikasi" title + "Tandai semua dibaca" action link (`text-xs text-blue-400`).
    * **Notification List:** Scrollable `max-h-80 overflow-y-auto`. Each item:
        * Unread: `bg-slate-700/50` with left border `border-l-2 border-blue-400`.
        * Read: `bg-transparent` with `border-l-2 border-transparent`.
        * Icon varies by `type`: info → `fa-info-circle text-slate-400`, warning → `fa-exclamation-triangle text-amber-400`, success → `fa-check-circle text-emerald-400`, commission → `fa-coins text-emerald-400`.
        * Title: `text-sm font-semibold text-white`.
        * Message: `text-xs text-slate-400` (truncated to 2 lines).
        * Timestamp: `text-[10px] text-slate-500`.
    * **Empty State:** `text-center py-8 text-slate-500 text-xs` → "Tidak ada notifikasi."
* **AJAX State Management:**
    * On page load + every 60 seconds: fetch `/notifications/unread-count` → update Red Badge.
    * On bell click: fetch `/notifications/list` (returns last 20 notifications) → render dropdown list.
    * On individual notification click: `POST /notifications/mark-read/{id}` → mark as read, remove unread styling, decrement badge.
    * On "Tandai semua dibaca": `POST /notifications/mark-all-read` → mark all as read, hide badge.
    * All AJAX calls use Vanilla JS `fetch()` — no jQuery, no page reload.
* **Backend Endpoints (CI3):**
    * `GET /notifications/unread-count` → JSON `{ count: N }`.
    * `GET /notifications/list` → JSON array of notifications (most recent 20).
    * `POST /notifications/mark-read/{id}` → mark single notification as read.
    * `POST /notifications/mark-all-read` → mark all user's notifications as read.
* **Notification Generation:**
    * Level 1 Bonus claimed → insert `type: 'commission'` notification.
    * Deposit approved → insert `type: 'success'` notification.
    * Withdrawal processed/declined → insert `type: 'success'` or `type: 'warning'` notification.
    * System-wide announcements → insert `type: 'info'` notification.

---

## 5. UI/UX & Frontend Guidelines
* **Visual Murni:** 100% menggunakan Tailwind CSS via CDN atau Build. TIDAK BOLEH menggunakan Bootstrap.
* **Interaktivitas:** Menggunakan Vanilla JavaScript. Semua *pop-up* (seperti konfirmasi *withdraw*, *alert* sukses/gagal, *modal* ubah profil, *Bottom Sheet Checkout*, *Notification Dropdown*) harus dirender tanpa *reload* halaman.
* **Navigasi:** *Bottom Navigation Bar* persisten di 5 halaman utama (Home, Sewa Saya, Bantuan, Marketplace, Profil).
* **Z-Index Layering:** Bottom Navigation sits at `z-50`. All Bottom Sheet Modals and Notification Dropdowns MUST be `z-[60]` to render above the nav bar.
* **Financial Display:** All IDR values formatted via `Intl.NumberFormat('id-ID')` in JavaScript or `number_format()` in PHP with `Rp ` prefix. No raw unformatted numbers in UI.
* **Form Visibility:** Secondary input forms (e.g., Top-Up amount selection) are hidden by default (`hidden` class) and toggled via a primary action button to conserve screen real estate.

---

## 6. Security & OpSec Requirements
* **CSRF Protection:** Wajib diaktifkan di konfigurasi CI3 (`$config['csrf_protection'] = TRUE;`).
* **Bot Protection:** Native SVG CAPTCHA on login & registration (M8/plan/72) — 5-char unambiguous alphabet (no `0/O/o/1/I/l`), inline transparent SVG (indigo/cyan/violet, rotation+jitter+noise), case-insensitive match, strict single-use session flush, 180s TTL, AJAX refresh endpoint `auth/refresh_captcha`. No external CAPTCHA service, no CAPTCHA keys.
* **Rate Limiting / Mutex Lock:** Halaman eksekusi finansial (Tombol Beli Sewa, Tombol Tarik Dana) wajib memiliki *lock* atau *disable state* pada JavaScript dan divalidasi di PHP agar tidak terjadi *Double Spending* jika *user* melakukan klik dua kali dengan cepat.
* **Phone Sanitization:** Backend regex `/^0[0-9]{9,13}$/` enforced on ALL phone inputs (register, login, profile update, admin user creation). Frontend is helper-only — never the source of truth.
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

### D. Advanced User Management (v5.0)

Admin has two privileged user-management operations accessible from the Command Center.

#### D.1 Create User (Referral Bypass)
* **Route:** `POST /admin/create-user` (from a form in the admin dashboard).
* **Purpose:** Admin can manually create a new user account — bypassing the normal registration flow (no invite code required).
* **Backend Logic (`Admin::create_user()`):**
    1. Validate input: `phone` (required, unique, passes sanitization regex `/^0[0-9]{9,13}$/`), `password` (required, min 8 chars).
    2. Apply phone sanitization: strip symbols, convert `62` → `0`.
    3. Check `users.phone` is_unique.
    4. Auto-generate `invite_code` (6-char alphanumeric, unique).
    5. Set `parent_id = NULL` (no upline — this user is a root node, not part of any agency hierarchy).
    6. Hash password with `password_hash($password, PASSWORD_BCRYPT)`.
    7. Insert into `users` table.
    8. Insert audit log entry: `action: 'admin_create_user'`, `details: { phone, created_by: admin_id }`.
* **Response:** Flash success with new user's phone + auto-generated invite code. Redirect to admin dashboard.
* **Constraint:** Created user appears as a standalone member (level_id 0, parent_id NULL) — they are NOT part of any agency tree unless they register via an invite link later.

#### D.2 Force Reset Password
* **Route:** `POST /admin/reset-password/{user_id}` (button in admin user list).
* **Purpose:** Admin can force-reset a user's password — useful when user is locked out or account is compromised.
* **Backend Logic (`Admin::reset_password($user_id)`):**
    1. Generate random 8-character password: `substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'), 0, 8)`.
    2. Hash with `password_hash($new_password, PASSWORD_BCRYPT)`.
    3. Update `users.password` WHERE `id = $user_id`.
    4. Insert audit log: `action: 'admin_reset_password'`, `details: { user_id }`.
* **Response:** Display the new plaintext password to admin in a one-time flash message (green terminal style). Admin must communicate it to the user via secure channel.
* **Constraint:** User is forced to change password on next login — implement via a `must_change_password` flag (add to `users` table: `TINYINT(1) DEFAULT 0`). When flag is `1`, redirect all requests to `/auth/change-password` until password is updated.

---

## 8. Notification System Infrastructure (v5.0)

This section cross-references the Interactive Notification System (§4.H) and provides the architectural overview.

* **DB Table:** `user_notifications` — see ERD v5.0 §5.
* **AJAX Polling Interval:** 60 seconds for unread count badge. On-demand fetch for full notification list (on bell click).
* **Security:** All notification endpoints require authenticated user session (`user_id`). Users can only read/mark their own notifications — `WHERE user_id = session.user_id`.
* **Performance:** Composite index `(user_id, is_read)` ensures fast unread count queries. Dropdown loads only the last 20 notifications — lazy-load for older entries if needed.
* **Notification Lifecycle:**
    1. Backend inserts notification record (via `Notification_model->create($user_id, $title, $message, $type)`).
    2. Frontend AJAX picks it up on next poll cycle (or on page refresh).
    3. User reads → mark as read → badge decrements.
    4. No TTL/expiry — notifications persist in DB until manually cleaned by admin or system cron.
