# Roadmap & Technical To-Do List v6.0 (Restructured)
**Project Name:** Synapse
**Framework:** CodeIgniter 3 (MVC Architecture)
**Target:** AI Agent (Hermes)

---

## ⚠️ STRICT RULES FOR AI AGENT (HERMES)
1. **Sequential Execution:** Jangan kerjakan Fase N+1 sebelum Fase N selesai secara sempurna dan tervalidasi tanpa error.
2. **MVC Compliance:** Dilarang keras menulis query SQL langsung di dalam Controller atau View. Gunakan Model.
3. **Testing:** Setiap controller method baru harus diuji minimal melalui browser / `curl` / Thunder Client untuk memastikan HTTP 200 / 302 sesuai alur.
4. **Branch Strategy:** Gunakan branch terpisah untuk setiap Fase. Merge ke `main` hanya jika fase sudah selesai dan tervalidasi.
5. **Linting:** Jalankan `php -l` pada setiap file PHP baru atau yang dimodifikasi untuk memastikan tidak ada syntax error.
6. **No Hardcoded Credentials:** Jangan pernah menulis kode API key, token, atau password di dalam repository. Gunakan environment variables atau `.env`.
7. **Milestone Lock:** Fase N+1 **TIDAK BOLEH** dimulai sampai Fase N selesai 100% dan dikonfirmasi oleh user (Tommy).

---

## Completed Phases

### Phase 1: Database & Basic Auth ✅ COMPLETED
- [x] **1A: Database Schema Creation** — Membuat database `webtable_db` dan semua tabel inti (`users`, `gpu_products`, `rentals`, `wallet_ledger`, `transactions`, `deposits`, `withdrawals`, `bank_accounts`, `otp_logs`, `admins`, `user_rentals`) sesuai ERD v5.0.
- [x] **1B: Project Setup** — Instalasi CodeIgniter 3, konfigurasi database, `.env` untuk credentials, folder structure MVC.
- [x] **1C: Auth Controller (Register/Login/Logout)** — Registrasi dengan validasi (Kode Undangan, Nomor Telepon, Kata Sandi). Login dengan session handling. Logout. Bot protection via Google reCAPTCHA v2 (replacing native GD captcha).
- [x] **1D: Phone Sanitization** — Backend regex `/^0[0-9]{9,13}$/`. Stripping `+62`, `0062`, symbols. Frontend `type="tel"` + `inputmode="numeric"`, no rigid maxlength/minlength — backend is source of truth.

### Phase 2: Auth Context & Navigation ✅ COMPLETED
- [x] **2A: MY_Controller Base Class** — `is_logged_in()`, `is_admin()`, session check, redirect guards.
- [x] **2B: User Session Context** — `user_id` in session, `user_data` injection to all views via `$this->load->vars()`.
- [x] **2C: Navigation Layout** — Bottom Navigation Bar (5 tabs: Home, Sewa Saya, Bantuan, Marketplace, Profil). Top header bar with Balance Capsule + Notification Bell.
- [x] **2D: Balance Capsule (Global Header)** — Persistent wallet balance in sticky header via `MY_Controller` → `$global_balance` injection. Font monospace `Rp {balance}`.

### Phase 3: GPU Products & Marketplace ✅ COMPLETED
- [x] **3A: Product CRUD (Admin)** — CRUD operations for `gpu_products`. Admin can create, read, update, delete products.
- [x] **3B: Marketplace Page (User)** — Product card feed with dynamic data from DB. Scrollable, mobile-first.
- [x] **3C: One-Screen Checkout (Bottom Sheet Modal)** — Dynamic Vanilla JS bottom sheet. Balance check (sufficient → confirm button, insufficient → top-up redirect). `z-[60]` layering above bottom nav.

### Phase 4: Rental System ✅ COMPLETED
- [x] **4A: Rental Transaction** — Post-checkout flow: balance deduction via `Ledger_model`, `rentals` insert with status `active`, ACID transaction wrapping.
- [x] **4B: Sewa Saya (My Rentals)** — User-facing rental list page. Status display (active/completed/cancelled). Progress tracking.
- [x] **4C: Manual ROI Claim** — `user_rentals.last_claimed_at` tracking. Manual claim button for daily ROI from rented products.

### Phase 5: Financial Ledger & Wallet ✅ COMPLETED
- [x] **5A: Wallet Ledger System** — `wallet_ledger` append-only table. `SUM(credit) - SUM(debit)` balance calculation.
- [x] **5B: Top-Up (Deposit) Flow** — Invoice generation (`INV-{YmdHis}-{user_id}`). `deposits` table with `pending` → `success` lifecycle.
- [x] **5C: Dev Simulator (Top-Up Approval)** — One-click "Simulasi Bayar" for testing. ACID-compliant deposit approval + ledger credit insert.
- [x] **5D: Withdrawal System** — Bank account binding. Fee calculation (tiered: 3%–10% + Rp 6.500). Min Rp 100K, max Rp 50M. Single pending WD limit. Mon–Sat 07:00–19:00 only.
- [x] **5E: Auto-Rollback on Withdrawal Decline** — Admin decline → automatic `credit` refund to `wallet_ledger` inside same ACID transaction.

### Phase 6: Admin Command Center ✅ COMPLETED
- [x] **6A: Dual Auth Architecture** — Hard separation: `users` table + `user_id` session vs `admins` table + `admin_id` session. No cross-access.
- [x] **6B: Cloaked Gateway** — Admin login at `/control-panel` (not `/admin/login`). No UI links — known only to operators.
- [x] **6C: Admin Dashboard (Bloomberg Terminal Aesthetic)** — Dark theme `bg-slate-950`, terminal header, two-column grid: pending deposits (left) + pending withdrawals (right). IDR formatted amounts.
- [x] **6D: Queue Operations** — Deposit approval (ACID: status update + ledger credit). Withdrawal approval (ACID: status update). Withdrawal decline with auto-rollback (ACID: status update + ledger refund).

### Phase 7A: Affiliate System Foundation ✅ COMPLETED
- [x] **7A1: Adjacency List Model** — `parent_id` foreign key on `users` table. Up/downline relationship tracking.
- [x] **7A2: Invite Code System** — Auto-generated 6-char alphanumeric `invite_code` per user. Registration requires valid invite code.
- [x] **7A3: Active Downline Detection** — User is "active" if they have ≥ 1 record in `user_rentals`. Level 1 + Level 2 downline counting.
- [x] **7A4: Total Sales Calculation** — SUM `purchase_price` from `user_rentals` for all active Level 1 + Level 2 downlines.

### Phase 7B: Agency Levels & Weekly Wage ✅ COMPLETED
- [x] **7B1: Level Thresholds** — Level 1–6 defined in `agency_levels` model. Active agents + total sales criteria per level.
- [x] **7B2: Cron Job — Weekly Wage** — Every Monday 01:00 WIB. Evaluates user qualification, distributes wage via `wallet_ledger` credit + `transactions` insert (type: `commission_bonus`).
- [x] **7B3: Level 1 Bonus (One-Time)** — Rp 80.000 one-time reward on first qualifying. `wallet_ledger` description: "Bonus Level 1 Agency".

### Phase 7C: Team Page & Affiliate UI ✅ COMPLETED
- [x] **7C1: Team Page (Halaman Tim)** — Displays active agent count, total sales, current level, agency history.
- [x] **7C2: Downline Tree Visualization** — Hierarchical display of direct (L1) and indirect (L2) downlines. Active/inactive status indicators.
- [x] **7C3: Profile & Settings** — Avatar upload, display name, invite code display for sharing.

### Phase 7D: Notification System Foundation ✅ COMPLETED
- [x] **7D1: Database Table** — `user_notifications` table created (ERD v5.0 §5): `id`, `user_id` (FK → `users.id` CASCADE), `title`, `message`, `type` (ENUM: info/warning/success/commission), `is_read` (TINYINT DEFAULT 0), `created_at`. Composite index `(user_id, is_read)` for fast unread count.
- [x] **7D2: Backend Model** — `Notification_model`: `create()`, `get_unread_count($user_id)`, `get_latest($user_id, $limit)`, `mark_read($id, $user_id)`, `mark_all_read($user_id)`.
- [x] **7D3: AJAX Endpoints** — `GET /notifications/unread-count` → JSON `{ count: N }`. `GET /notifications/list` → JSON array (last 20). `POST /notifications/mark-read/{id}`. `POST /notifications/mark-all-read`.
- [x] **7D4: Bell Icon + Red Badge** — Header bell icon in `header.php`. Badge `<span id="notif-badge">` hidden when count = 0, shows `N` (or `99+`) when > 0. 60-second AJAX polling interval.
- [x] **7D5: Dropdown Popover** — `z-[60]` dropdown on bell click. Slate-800 background, w-80, max-h-96. Notification items with type-based icons (info→slate, warning→amber, success→emerald, commission→emerald+fa-coins). Unread items: `bg-slate-700/50` + left border `border-blue-400`. Empty state: "Tidak ada notifikasi."
- [x] **7D6: Vanilla JS Fetch Manager** — No jQuery. `fetch()` for all AJAX calls. Badge update on load + every 60s. Dropdown render on bell click. Per-item mark-read on click. "Tandai semua dibaca" bulk action.

### Phase 7E: Advanced User Management ✅ COMPLETED
- [x] **7E1: Create User (Referral Bypass)** — `POST /admin/create-user` from Command Center. Backend (`Admin::create_user()`): validate phone (sanitization regex `/^0[0-9]{9,13}$/`), unique check, auto-generate `invite_code`, set `parent_id = NULL` (root node, no agency tree), `password_hash(PASSWORD_BCRYPT)`, insert to `users`. Flash success with phone + invite code. *(Audit logging menyusul di Phase 10A.)*
- [x] **7E2: Force Reset Password** — `POST /admin/reset-password/{user_id}`. Generate random 8-char password (mixed alphanumeric), hash with bcrypt, update `users.password`. One-time plaintext display to admin in flash message. *(Audit logging menyusul di Phase 10A.)* `must_change_password` flag forces user to change on next login via redirect to `/auth/change-password`.
- [x] **7E3: `must_change_password` Column** — Added to `users` table: `TINYINT(1) DEFAULT 0`. `MY_Controller` checks flag → redirects to `/auth/change-password` if set. Cleared after successful password update.

### Phase 8A: Daily Revenue Distribution ✅ COMPLETED
- [x] **8A1: Cron Job — Daily ROI** — Every day 00:01 WIB. Finds all `rentals` status `active`, not yet completed. Adds daily revenue via `wallet_ledger` credit + `transactions` insert (type: `daily_revenue`). Increments `days_processed`. On completion → status `completed`.

### Phase 8B: Withdrawal Management UI ✅ COMPLETED
- [x] **8B1: User Withdrawal Page** — Bank account management. Withdrawal form (amount, bank selection). Fee preview. Submit with single-pending-WD guard.
- [x] **8B2: Admin Withdrawal Queue** — Command Center right column. APPROVE/DECLINE buttons. Decline triggers auto-rollback (Phase 5E).

### Phase 8C: Halaman Tim & Afiliasi ✅ COMPLETED
- [x] **8C1: Level 1 Mission Card** — Bloomberg Terminal dark aesthetic (`bg-slate-900 rounded-2xl p-5 border border-slate-800`). Two progress bars on `bg-slate-700` tracks with `bg-emerald-500` fill:
    * **Agent Bar:** `active_agents / 3` (target: 3 active L1+L2 downlines).
    * **Turnover Bar:** `total_sales / 330000` formatted as IDR (target: Rp 330.000).
    * Dynamic action button below bars:
        * Conditions not met → disabled `bg-slate-700 text-slate-400` "Klaim Bonus Level 1" with progress text (e.g., "1/3 Agen Aktif · Rp 150.000/330.000").
        * Conditions met → enabled `bg-emerald-500 hover:bg-emerald-600 text-white` "Klaim Bonus Rp 80.000".
- [x] **8C2: AJAX Claim API** — `POST /team/claim-level1`. Zero-Trust server-side validation (PRD v5.0 §4.G):
    * ACID transaction (`trans_start` / `trans_complete`).
    * Re-queries `User_model->get_downlines()` for active agents.
    * Re-sums `purchase_price` from `user_rentals` for turnover.
    * Checks `wallet_ledger` for existing "Bonus Level 1" description (idempotency guard).
    * `SELECT ... FOR UPDATE` on `users` row (race condition prevention).
    * On success: inserts `credit` (80000) to `wallet_ledger` + `transactions` (type: `commission_bonus`). Returns JSON `{ success, message, data: { active_agents, total_sales, bonus_amount } }`.
    * On failure: full rollback. Returns error JSON with specific message ("Agen aktif belum mencukupi" / "Total sales belum mencapai Rp 330.000" / "Bonus sudah diklaim").
- [x] **8C3: Team Page Integration** — Mission Card rendered prominently on `/team` page. Real-time data from `User_model`. AJAX claim with optimistic UI update + fallback reload.

### Phase 9: Advanced Analytics & Reporting ✅ COMPLETED
- [x] **9A: Treasury & Chart.js** — Admin Command Center "Treasury Health" panel: total cash-in (SUM `user_rentals.purchase_price`), total user balances (SUM credit − SUM debit on `wallet_ledger`), pending ROI obligation dari rental aktif, circuit breaker `is_registration_open`; Chart.js 4.4.1 revenue chart dengan AJAX refresh via `admin/chart_data`.
- [x] **9B: Analytics & VIP Leaderboard** — Halaman `admin/analytics`: global metrics (`get_global_analytics()`), per-user financial X-ray (`get_user_xray()`), "TOP AFFILIATES — VIP LEADERBOARD" via recursive CTE (`get_leaderboard()`).
- [x] **9C: CSV Export Streaming** — `Admin::export_csv()` native streaming ke `php://output`, UTF-8 BOM untuk Excel, 3 tipe: `ledger` / `rentals` / `withdrawals`.

### M8: Native SVG CAPTCHA & External CAPTCHA Purge ✅ COMPLETED (plan/72–73)
- [x] **M8A: CAPTCHA Engine** — `captcha_helper.php` (56-glyph unambiguous alphabet; transparent inline SVG with per-char ±22° rotation + jitter, noise lines/dots, indigo/cyan/violet palette); session lifecycle `auth_captcha` (strict single-use flush, TTL 180s).
- [x] **M8B: Controller Integration** — Login/Register gates replaced with `_verify_captcha()` ("Kode keamanan salah atau sudah kedaluwarsa."); M5 phone-normalization + rate-limit ordering preserved; fresh challenge per render; AJAX `auth/refresh_captcha` JSON endpoint.
- [x] **M8C: Views** — `api.js` & `.g-recaptcha` removed; light/dark Tailwind "Kode Keamanan" component + vanilla refresh JS on `login`/`register`.

---

## Upcoming Phases

### Phase 10: System Hardening & Audit Trail (PLANNED)
- [ ] **10A: Audit Logging** — `system_audit_logs` table (ERD v5.0 §6). Every admin action logged: deposit approval, withdrawal approval/decline, user creation, password reset.
- [ ] **10B: Rate Limiting** — IP-based rate limiting on auth endpoints (login, register, OTP). Prevent brute-force attacks.
- [ ] **10C: Session Security** — Session timeout (30 min idle). Concurrent session limiting. CSRF token rotation.
- [ ] **10D: Input Sanitization Audit** — Full review of all user inputs against XSS, SQL injection, and CSRF vectors.

### Phase 11: Production Payment Gateway (PLANNED)
- [ ] **11A: Payment Provider Integration** — Replace Dev Simulator with real payment gateway (Midtrans, Xendit, or similar).
- [ ] **11B: Webhook Handler** — Server-to-server payment notification processing. Signature verification. Idempotent transaction processing.
- [ ] **11C: Invoice PDF Generation** — Printable receipt for each deposit and withdrawal.

### Phase 12: Mobile Optimization & PWA (PLANNED)
- [ ] **12A: PWA Manifest** — Service worker, app manifest for "Add to Home Screen" on mobile.
- [ ] **12B: Push Notifications** — Web push API for real-time notification delivery (complement to AJAX polling).
- [ ] **12C: Performance Audit** — Lighthouse score optimization. Image compression. CDN for static assets.
