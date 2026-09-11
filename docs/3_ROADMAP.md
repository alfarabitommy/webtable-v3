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

### Phase 10: System Hardening & Audit Trail ✅ COMPLETED
- [x] **10A: Audit Logging** — `system_audit_logs` live (ERD §6): deposit approval, withdrawal approval/decline, user creation, password reset, settings update; ditulis atomik dalam TX yang sama (M5/A1).
- [x] **10B: Rate Limiting** — `rate_limits` (ERD §4): brute-force lockout pada endpoint auth (login/register) + proteksi ban, timezone sync, perbaikan logout admin.
- [x] **10C: Session Security** — proteksi CSRF global + `csrfFetch()` handler, session hardening, ubah sandi profil.
- [x] **10D: Secret & Input Hygiene Sweep** — purge seluruh secret ter-commit (CAPTCHA external diganti native SVG M8, `seeder_admin` backdoor, `Test_core.php`); `base_url`/`encryption_key`/`log_threshold`/`TRUSTED_PROXIES`/DB credentials env-driven.

### Audit Series M1–M10 & P1–P7 ✅ COMPLETED (plan/82 — 24/24 findings CLOSED & VERIFIED)
- [x] **M-series (money correctness)** — M1 dynamic fees/rules, M2 wage tier audit, M3 lazy rental expiry (no cron), M4 idempotency & race hardening, M5 notifications & audit trail atomik, M6 `transactions` decommission → single `wallet_ledger`, M7 `site_settings` → `system_settings`, M8 integer IDR discipline, M9/P7 unified JSON envelope, M10 orphan cleanup & deprecation (`rentals`/`otp_logs` retention-only).
- [x] **P-series (polish)** — notification pagination, marketplace empty state, dynamic L1 wage. Matriks definitif & production handoff checklist di `plan/82`; gap ledger di `plan/66`; index resolusi di `plan/37`.

### Product Gating & Per-User Purchase Limits ✅ COMPLETED (plan/83–84)
- [x] **Gating & limits engine** — `gpu_products.max_per_user` (0 = tanpa batas, N ≥ 1 = kuota lifetime); 8 paket kanonik RTX 3060 s.d. H200 Sovereign di-seed dari DB (id 1–8 eksplisit, integer IDR).

### Admin GPU Product Management CRUD ✅ COMPLETED (plan/85–86)
- [x] **Admin CRUD `gpu_products`** — list (aktif + nonaktif), create, edit, quick-toggle `is_active`; validasi ketat, audit atomik M5, nol permukaan hard-delete.

### Simplified Admin-Controlled Gating ✅ COMPLETED (plan/87–88)
- [x] **Decommission rantai prasyarat** — `unlock_prerequisite_id` dorman (semua NULL, non-destruktif); ketersediaan produk 100% via toggle admin `is_active`; `max_per_user` tetap satu-satunya batas pembelian. *(Catatan: item QA browser/session plan/88 §4 sempat open saat penulisan summary tsb.)*

### 3-Tier Rebate Engine & Referral Gating ✅ COMPLETED — 100% Runtime Verified (plan/89–90)
- [x] **Rebate 3-tier saat checkout** — `_distribute_rebate()` di dalam TX `checkout_rental()`: upline L1/L2/L3 (traversal fail-closed, anti-loop) menerima kredit `RBT-{id}-L{tier}` via satu-satunya jalur `Wallet_model::credit()`, breakage prevention (upline inaktif/banned → hangus tanpa pass-up), notifikasi `commission` atomik.
- [x] **Dynamic admin rebate rates** — `system_settings` `rebate_enabled`/`rebate_l1_percent`/`rebate_l2_percent`/`rebate_l3_percent` (default 1/5/3/1) + fallback `application/config/rebate_commission.php`; Card 5 di `admin/settings` (validasi 0–100 all-or-nothing + audit before→after).
- [x] **Active-contract gating** — Condition A/B/C: kode/link/QR referral & kartu dashboard hanya tampil saat syarat kontrak aktif/riwayat terpenuhi (anti DOM-leak); `/referral` alias ke `/team`; modal warning upline inaktif.
- [x] **Bukti runtime (live `synapse.test` + MariaDB)** — rental downline memicu **rebate L1 5% instan** ke upline (transaksi `RBT-`); matriks T1–T16 PASSED — lihat `plan/90` (VERIFIED & SIGNED OFF, `dec-94563cfd1af2c22e`).

### Promoter Program via Omzet Burn ✅ COMPLETED — 100% Runtime Verified (plan/91–92)
- [x] **Referral gating bypass (K1/K6)** — `users.is_promoter` (admin-only, toggle ber-audit) mem-bypass Condition A; flag dibaca segar dalam TX → demosi langsung memblokir submit baru, klaim pending tetap diproses.
- [x] **Independent caps via `user_rentals.source` (K4)** — kanal paid (`source='purchase'`) vs reward (`source='promoter_reward'`) memakai kuota `max_per_user` terpisah; semua COUNT kuota marketplace/GATE 2 memfilter `source <> 'promoter_reward'`.
- [x] **Manual admin approval queue + zero-cost contracts (K7)** — `promoter_claims` (omzet L1 burn, guard rasio CAC K5, flip kondisional M4); approve menerbitkan kontrak reward `purchase_price = 0` + notifikasi + audit atomik; burn TIDAK pernah menyentuh `wallet_ledger` (Z1/C4).
- [x] **Bukti runtime (live `synapse.test` + MariaDB)** — bypass Condition A pada promotor 0-rental (`087700010001`); omzet calc + lock + telemetri queue; happy-path approval zero-cost; reject melepaskan omzet terkunci + **CSRF fix pada modal reject**; aktivasi rebate 3-tier dari kontrak reward; matriks T1–T16 PASSED — lihat `plan/92` (VERIFIED & SIGNED OFF, `dec-94563cfd1af2c22e`).

### Dual-Language Engine (Member) ✅ COMPLETED (plan/94 F1)
- [x] **Engine i18n member-only** — `application/helpers/i18n_helper.php`: `i18n_idioms()` (peta satu-sumber `en→english`/`id→indonesian`), `i18n_default_code()` = `en`, `i18n_resolve()` (session `site_lang` → cookie `site_lang` **30 hari** → `en`), `i18n_apply()` (memuat **tepat satu** idiom + inject var `site_lang_code`), `i18n_is_referer_same_host()` (anti open-redirect L8) — semua dibungkus `function_exists()`.
- [x] **Switcher route** — `GET /lang/switch/(:any)` → `Lang::switch()` (kode ∉ {`en`,`id`} → 404; persist session + cookie 30 hari; redirect referer same-host, fallback `base_url()`); `$route['lang/switch/(:any)'] = 'lang/switch/$1'`. Tersedia pra-login; partial `views/templates/lang_switcher.php` (SVG bendera inline) dipakai halaman auth.
- [x] **Kamus EN/ID** — `application/language/{english,indonesian}/app_lang.php`, **332 key dengan himpunan key identik** (paritas diuji ulang plan/100 V2: 332 = 332, `diff` kosong). Ruang lingkup: member (Auth, Home, Marketplace, Rentals, Team, Wallet, Profile + chrome `templates/*`) — **admin tetap 100% Indonesian** (L1); uang & angka tidak diterjemahkan (L6).
- [x] **Ringkasan** — `plan/94_DUAL_LANGUAGE_AND_ADMIN_ALERT_CENTER_SUMMARY.md`.

### Admin Real-Time Alert Center ✅ COMPLETED (plan/94 F2)
- [x] **Endpoint polling** — `GET /admin/alerts/poll` → `Admin::alerts_poll()` (`$route['admin/alerts/poll'] = 'admin/alerts_poll'`), sumber `Admin_model::get_alert_counts()` (3× `COUNT(*) WHERE status='pending'`: deposits/withdrawals/promoter_claims + `total_urgent`), respons `api_success(..., 200, $counts)` (envelope `data.*` + root legacy keys) + `Cache-Control: no-store`; read-only tanpa baris audit (A1).
- [x] **Klien `window.AdminAlerts`** — `views/admin/templates/footer.php`: **polling 25 dtk**, in-flight guard, pause `document.hidden` + poll langsung saat visible, sinkronisasi badge sidebar/bell dari SSR `global_admin_alerts`, **chime Web Audio 2 nada 880 → 1174.66 Hz** hanya saat `total_urgent` **naik**, mute persist `localStorage('admin_alerts_muted')`, reload saat sesi mati.
- [x] **Permukaan alert** — badge sidebar + bell topbar + dropdown 3 baris ringkas, deep-link `/admin#pending-deposits`, `/admin#pending-withdrawals` (`scroll-mt-24`), `/admin/promoter-claims`.
- [x] **Index komposit** — `INDEX idx_status_created (status, created_at)` kanonik di `CREATE TABLE` `withdrawals` (`database.sql:125`), `deposits` (`:184`), `promoter_claims` (`:262`) + catatan migrasi live one-time (`database.sql:378–390`: MySQL 8 `ALTER … ADD INDEX`; MariaDB `CREATE INDEX IF NOT EXISTS`) — melayani COUNT pending (leading `status`) dan listing `ORDER BY created_at ASC`.
- [x] **Ringkasan** — `plan/94_DUAL_LANGUAGE_AND_ADMIN_ALERT_CENTER_SUMMARY.md`.

### Maintenance Mode (Member Site) ✅ COMPLETED (plan/95–96)
- [x] **Saklar terpisah dari registrasi** — key `system_settings.is_maintenance_mode` (`'0'`=normal, `'1'`=locked; **default OFF bila baris hilang**, berbeda dari `is_registration_open`); seed kanonik `('is_maintenance_mode','0')` di `database.sql:285` **dan** `database_seed.sql:263`.
- [x] **Gate engine** — `application/helpers/maintenance_helper.php` (`maintenance_is_active()`, `maintenance_gate()`, `_maintenance_wants_json()`, autoloaded) dipanggil sebagai **statement pertama setelah `parent::__construct()`** di `MY_Controller`, `Auth`, dan `Lang` — sebelum pin WIB M2, `i18n_apply` plan/94, guard login, dan sweep rental M3.
- [x] **Bypass ketat** — admin routes/`control-panel` (constructor tidak memanggil gate) + session `admin_id` + `is_cli()`.
- [x] **Respons saat aktif** — HTML → `views/errors/html/maintenance.php` **HTTP 503** (standalone, dwibahasa ID/EN, `no-store`, tanpa shell member); JSON/AJAX → `api_error(..., 503, [], 'MAINTENANCE_MODE')` (parity deteksi `MY_Exceptions::_wants_json`).
- [x] **Toggle admin** — `POST /admin/toggle-maintenance` → `Admin::toggle_maintenance()` (POST-only M4, CSRF, TX atomik + audit `admin_toggle_maintenance` `{was_maintenance,is_maintenance}`), tombol dashboard berdampingan dengan "Toggle Pendaftaran" (subtle saat normal, ring merah/pulse saat aktif).
- [x] **Ringkasan** — `plan/96_MAINTENANCE_MODE_SUMMARY.md`. *(Verifikasi runtime penuh — matriks plan/95 §9 + migrasi live DB — masih tertunda per §4 summary; temuan drift seed `rebate_*` di luar scope.)*

### Auth UI Refinement — Wordmark & High-Tech Animated Background ✅ COMPLETED (plan/97–98)
- [x] **Wordmark vektor murni** — mark neural SVG 7-node + `<h1 class="u-auth-wordmark">` "SYNAPSE" gradient `background-clip:text`, `letter-spacing .3em`; seluruh aset gambar eksternal (`placehold.co`, `<img>`) dihapus → nol dependensi gambar di ketiga halaman auth.
- [x] **High-tech cyber circuit grid** — ambient background murni CSS/SVG menggantikan 6 orb blur plan/97: 5 plasma core (blur **statis** 35–50 px, animasi transform-only 7–11 s) + 1 inline SVG `viewBox="0 0 480 900"` (grid pattern 28 px, signal ring dashed, 2 GPU die + 24 pin, trace statis, node) + **3 bus data** (kiri cyan / kanan violet / bawah biru-teal) dengan **flowing data pulses** `stroke-dashoffset` linear **160 px/s seragam** (Δ kelipatan periode, T = 6/7/8 s, tail 40 px di belakang kepala).
- [x] **Theme-aware + reduced motion** — token `--u-*` berpasangan (`:root` light `#f8fafc` indigo halus / `html.dark` `#050811` cyan-violet vivid); `prefers-reduced-motion: reduce` freeze plasma/pulse/node/ring; kartu `auth-card` glass, form, CAPTCHA, dan toggle Sun/Moon (`templates/auth_theme_toggle.php`, `localStorage['user_theme']`) tidak tersentuh.
- [x] **Ringkasan** — `plan/97_AUTH_UI_REFINEMENT_LOGO_AND_ANIMATED_BG_SUMMARY.md` & `plan/98_HIGH_TECH_ANIMATED_BACKGROUND_SUMMARY.md`. *(QA visual browser V4–V16 plan/97 §7 / V6–V16 plan/98 §6 masih manual per summary.)*

### Member Dashboard Hero & World Node Map ✅ COMPLETED (plan/99 + plan/101)
- [x] **AI Neural Cluster hero card** (`application/views/home/index.php`) — kanvas obsidian `#0b1120` (dark) / pearl (light), 2 orb glow, neural dot-lattice + sheen, headline gradient-text, status dot pulsing, dan **3 micro-HUD pill** (PFLOPS aktif, Isolated, Direct Link). Placeholder `placehold.co` hero dihapus.
- [x] **World Node Map** — SVG `viewBox="0 0 1000 500"` (`width:100%; height:auto`, rasio intrinsik 2:1 tanpa letterbox) + header chip SLA + 5 label hub HTML overlay (FRA-1, US-EAST, TYO-1, SIN-1, JKT-2) + footer chip failover/latency.
- [x] **Kontur benua vektor tajam (plan/101)** — **6 `hm99-land`** hasil Q-midpoint smoothing (menggantikan 7 ellipse + `feGaussianBlur`) + **14 ring pulau `hm99-isle`** (Indonesia/ASEAN + Jepang, Filipina, Greenland, UK/IE, Islandia, Madagaskar); **nol filter SVG** (`<defs>`/`feGaussianBlur`/`filter=url()` dihapus).
- [x] **Topologi mesh** — **5 rute aktif `hm99-route`** + **5 pulsa `hm99-flow`** (`pathLength="1"`, `dasharray .04 .96`, satu komet per loop) + **8 backbone transit link `hm99-bb`** (dashed statis) + **5 edge node `hm99-edge`** (halo + core, breathing 4 s) + ping ring/core pada 5 hub.
- [x] **Label mata uang** — `'Total Value · USC'` → `'Total Value · USD'` pada kedua kamus (`home_stat_value`), plus 12 key baru `home_hub_*`/`home_hud_*`/`home_topology_*` (paritas EN/ID dijaga).
- [x] **Ringkasan** — `plan/99_HOME_HERO_AND_TOPOLOGY_MAP_SUMMARY.md` & `plan/101_WORLD_MAP_VECTOR_LANDMASS_AND_MESH_TOPOLOGY_SUMMARY.md`. *(QA visual runtime V6–V10 plan/99 / V9–V12 plan/101 masih manual per summary.)*

### Header Declutter & Profile "App Preferences" ✅ COMPLETED (plan/100)
- [x] **Header kembali 3 grup** — `views/templates/header.php` (464 → 430 baris) hanya **Brand (logo + wordmark) · Balance Pill · Notification Bell**; include `templates/lang_switcher.php` dan blok `#user-theme-toggle` dihapus dari header, bersama engine `toggleUserTheme()`/`syncThemeUI()` dan key `SYNAPSE_I18N` `js_theme_dark`/`js_theme_light`.
- [x] **Balance pill anti-tabrakan** — `min-w-0 max-w-[46vw]` + ikon `flex-shrink-0` + span `truncate` + `title="Rp {nominal penuh}"` (tanpa perubahan perilaku; di 360 px pill ≤ ~166 px, nominal penuh via tooltip).
- [x] **Kartu "App Preferences" di Profile** — `application/views/profile/index.php` (368 → 480 baris), disisipkan antara REFERRAL CENTER dan THE HUB: Row 1 **Bahasa** (segmented capsule EN/ID → anchor `lang/switch/en|id`, segmen aktif server-rendered dari `$site_lang_code`) dan Row 2 **Tema** (segmented `#pref-theme-seg` dua `button.pref-theme-opt`, `aria-pressed` via JS, `localStorage['user_theme']` + `CustomEvent('user-theme-change')`); baris hub `#btn-theme-hub` lama dihapus (hub jadi 1–6). Ini **lokasi kanonik** kontrol bahasa & tema member.
- [x] **Kamus** — +6 key (`profile_pref_title`, `profile_lang_label`, `profile_theme_label`, `profile_theme_dark`, `profile_theme_light`, `profile_theme_hint`), −3 key usang (`profile_theme`, `js_theme_dark`, `js_theme_light`) → **332 key** identik di kedua idiom.
- [x] **Ringkasan** — `plan/100_HEADER_DECLUTTER_AND_PROFILE_SETTINGS_SUMMARY.md`. *(QA live-browser V5–V11 tertunda karena sandbox tanpa MySQL; §2.1 memuat sanity lebar statis.)*

---

## Upcoming Phases

### Phase 11: Production Payment Gateway (PLANNED)
- [ ] **11A: Payment Provider Integration** — Replace Dev Simulator with real payment gateway (Midtrans, Xendit, or similar).
- [ ] **11B: Webhook Handler** — Server-to-server payment notification processing. Signature verification. Idempotent transaction processing.
- [ ] **11C: Invoice PDF Generation** — Printable receipt for each deposit and withdrawal.

### Phase 12: Mobile Optimization & PWA (PLANNED)
- [ ] **12A: PWA Manifest** — Service worker, app manifest for "Add to Home Screen" on mobile.
- [ ] **12B: Push Notifications** — Web push API for real-time notification delivery (complement to AJAX polling).
- [ ] **12C: Performance Audit** — Lighthouse score optimization. Image compression. CDN for static assets.
