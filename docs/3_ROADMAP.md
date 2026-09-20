# Roadmap & Technical To-Do List v6.0 (Restructured)
**Project Name:** Synapse
**Framework:** CodeIgniter 3 (MVC Architecture)
**Target:** AI Agent (Hermes)

> **Catatan sinkronisasi (plan/111).** Log milestone kini memuat rentang
> **plan/102–110** (sebelumnya berhenti di plan/106): QRIS manual berkode unik,
> i18n purification, gambar produk, copywriting viral, auto-fill referral,
> tampilan NET penarikan, dan perbaikan validasi tier admin. Nomor versi
> dokumen **tidak** diubah (struktur fase tetap). Beberapa catatan fase lama
> dianotasi agar tidak menyesatkan (lihat Fase 1 & Strict Rule 6).
> **plan/112** menambah blok **Daily Check-in — Bonus Absensi Harian** di log
> milestone (kode otoritatif; lihat juga PRD v5.2 §F).

---

## ⚠️ STRICT RULES FOR AI AGENT (HERMES)
1. **Sequential Execution:** Jangan kerjakan Fase N+1 sebelum Fase N selesai secara sempurna dan tervalidasi tanpa error.
2. **MVC Compliance:** Dilarang keras menulis query SQL langsung di dalam Controller atau View. Gunakan Model.
3. **Testing:** Setiap controller method baru harus diuji minimal melalui browser / `curl` / Thunder Client untuk memastikan HTTP 200 / 302 sesuai alur.
4. **Branch Strategy:** Gunakan branch terpisah untuk setiap Fase. Merge ke `main` hanya jika fase sudah selesai dan tervalidasi.
5. **Linting:** Jalankan `php -l` pada setiap file PHP baru atau yang dimodifikasi untuk memastikan tidak ada syntax error.
6. **No Hardcoded Credentials:** Jangan pernah menulis kode API key, token, atau password di dalam repository. Gunakan environment variables atau `.env`. *(**Catatan risiko terbuka plan/111:** `application/config/database.php` masih menyimpan fallback password plaintext untuk grup `dev`/`live` di git history — lihat §Notes AGENTS.md; direkomendasikan rotasi + strip fallback, jangan tambah kredensial baru.)*
7. **Milestone Lock:** Fase N+1 **TIDAK BOLEH** dimulai sampai Fase N selesai 100% dan dikonfirmasi oleh user (Tommy).

---

## Completed Phases

### Phase 1: Database & Basic Auth ✅ COMPLETED
- [x] **1A: Database Schema Creation** — Membuat database `webtable_db` dan semua tabel inti (`users`, `gpu_products`, `rentals`, `wallet_ledger`, `transactions`, `deposits`, `withdrawals`, `bank_accounts`, `otp_logs`, `admins`, `user_rentals`) sesuai ERD v5.0. ~~`transactions`~~ (didecommission M6), ~~`rentals`~~ (digantikan `user_rentals`, M10), ~~`otp_logs`~~ (tanpa flow OTP, M10) — **retensi historis saja, jangan dipakai di kode baru.**
- [x] **1B: Project Setup** — Instalasi CodeIgniter 3, konfigurasi database, `.env` untuk credentials, folder structure MVC.
- [x] **1C: Auth Controller (Register/Login/Logout)** — Registrasi dengan validasi (Kode Undangan, Nomor Telepon, Kata Sandi). Login dengan session handling. Logout. *(Bot protection awal ~~Google reCAPTCHA v2~~ — **dipurge total** dan digantikan native SVG CAPTCHA di M8/plan/72; tidak ada CAPTCHA eksternal lagi.)*
- [x] **1D: Phone Sanitization** — Backend ~~regex `/^0[0-9]{9,13}$/`~~ *(plan/111: regex panjang tersebut tidak ditemukan di kode; validasi aktual = normalisasi `62`→`0` + `is_unique[users.phone]`/`uk_phone`). Stripping `+62`, `0062`, symbols. Frontend `type="tel"` + `inputmode="numeric"`.*

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
- [x] **4A: Rental Transaction** — Post-checkout flow: balance deduction via ~~`Ledger_model`~~ (`Wallet_model::debit()` → `wallet_ledger`), ~~`rentals`~~ insert ke **`user_rentals`** dengan status `active`, ACID transaction wrapping. *(plan/111: `Ledger_model` tidak pernah ada di kode final; `transactions` didecommission M6 — tabel aktif = `wallet_ledger` + `user_rentals`.)*
- [x] **4B: Sewa Saya (My Rentals)** — User-facing rental list page. Status display (active/completed/cancelled). Progress tracking.
- [x] **4C: Manual ROI Claim** — `user_rentals.last_claimed_at` tracking. Manual claim button for daily ROI from rented products.

### Phase 5: Financial Ledger & Wallet ✅ COMPLETED
- [x] **5A: Wallet Ledger System** — `wallet_ledger` append-only table. `SUM(credit) - SUM(debit)` balance calculation.
- [x] **5B: Top-Up (Deposit) Flow** — Invoice generation (`INV-{YmdHis}-{user_id}`). `deposits` table with `pending` → `success` lifecycle.
- [x] **5C: Dev Simulator (Top-Up Approval)** — One-click "Simulasi Bayar" for testing. ACID-compliant deposit approval + ledger credit insert.
- [x] **5D: Withdrawal System** — ~~Bank account binding~~ *(plan/106: digantikan **binding e-wallet** via katalog `ewallet_providers`; `bank_accounts` = retensi struktural)*. Fee calculation (tiered: 3%–10% + Rp 6.500, **half-open `[min,max)`**; plan/110 = endpoint turunan dinamis). Min/max **dinamis** (`wd_min_amount`/`wd_max_amount`, default Rp 100K/Rp 50M). Single pending WD limit. Mon–Sat 07:00–19:00 WIB (gate pengajuan).
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
- [x] **7B2: Cron Job — Weekly Wage** — ~~Every Monday 01:00 WIB~~ *(plan/111: **tidak ada cron**; wage **diklaim manual** via `/team/claim_wage` dengan cooldown 7 hari — `check_wage_cooldown`.)* Evaluates user qualification, distributes wage via `wallet_ledger` credit. ~~`transactions` insert~~ *(tabel didecommission M6).*
- [x] **7B3: Level 1 Bonus (One-Time)** — Rp 80.000 one-time reward on first qualifying (`User_model::LEVEL1_BONUS`). `wallet_ledger` credit via jalur tunggal; idempotensi via `users.is_level_1_claimed`.

### Phase 7C: Team Page & Affiliate UI ✅ COMPLETED
- [x] **7C1: Team Page (Halaman Tim)** — Displays active agent count, total sales, current level, agency history.
- [x] **7C2: Downline Tree Visualization** — Hierarchical display of direct (L1) and indirect (L2) downlines. Active/inactive status indicators.
- [x] **7C3: Profile & Settings** — Avatar upload, display name, invite code display for sharing.

### Phase 7D: Notification System Foundation ✅ COMPLETED
- [x] **7D1: Database Table** — `user_notifications` table created (ERD v5.1 §5): `id`, `user_id` (FK → `users.id` CASCADE), `title`, `message`, `type` (ENUM: info/warning/success/commission), `is_read` (TINYINT DEFAULT 0), `created_at`, index `idx_user_read` (user_id, is_read). *(plan/103 menambah `title_key` + `params` untuk i18n keyed.)*
- [x] **7D2: Backend Model** — `Notification_model`: `create()`, `get_unread_count($user_id)`, `get_latest($user_id, $limit)`, `mark_read($id, $user_id)`, `mark_all_read($user_id)`.
- [x] **7D3: AJAX Endpoints** — *(plan/111: endpoint lama di bawah **tidak pernah ada** di kode — digantikan kontrak aktual: `GET /notification` (riwayat) + `POST /notification/mark_all_read` + `POST /user/read_notifications`; badge/lists **server-rendered** dari `$global_unread_count`.)* ~~`GET /notifications/unread-count` → JSON `{ count: N }`. `GET /notifications/list` → JSON array (last 20). `POST /notifications/mark-read/{id}`. `POST /notifications/mark-all-read`.~~
- [x] **7D4: Bell Icon + Red Badge** — Header bell icon in `header.php`. Badge `<span id="notif-badge">` hidden when count = 0, shows `N` (or `99+`) when > 0. *(plan/111: ~~60-second AJAX polling~~ — nilai badge **server-rendered** tiap page load, tanpa timer polling.)*
- [x] **7D5: Dropdown Popover** — `z-[60]` dropdown on bell click. Slate-800 background, w-80, max-h-96. Notification items with type-based icons (info→slate, warning→amber, success→emerald, commission→emerald+fa-coins). Unread items: `bg-slate-700/50` + left border `border-blue-400`. Empty state: "Tidak ada notifikasi."
- [x] **7D6: Vanilla JS Fetch Manager** — No jQuery. `fetch()` for all AJAX calls. Badge update on load + every 60s. Dropdown render on bell click. Per-item mark-read on click. "Tandai semua dibaca" bulk action.

### Phase 7E: Advanced User Management ✅ COMPLETED
- [x] **7E1: Create User (Referral Bypass)** — `POST /admin/create_user` from Command Center. Backend (`Admin::create_user()`): normalize phone (`62`→`0`) + unique check (`is_unique[users.phone]`), auto-generate `invite_code`, optional `upline_invite_code` (empty = root node), `password_hash(PASSWORD_DEFAULT)`, insert to `users` + audit `admin_create_user` atomically. *(plan/111: rute bertanda strip tak valid — `translate_uri_dashes = FALSE`; regex panjang `/^0[0-9]{9,13}$/` tidak ada di kode.)*
- [x] **7E2: Force Reset Password** — `POST /admin/reset_password/{user_id}`. Admin mengetik `new_password` (min 8), hash dengan `PASSWORD_DEFAULT`, update `users.password` **atomik** + audit `admin_reset_password` (plaintext **tidak** pernah dicatat/ditampilkan). *(plan/111: bukan generate-acak + plaintext-flash — lihat kode.)* `must_change_password` flag memaksa user ganti sandi saat login berikutnya via redirect ke `/auth/change-password`.
- [x] **7E3: `must_change_password` Column** — Added to `users` table: `TINYINT(1) DEFAULT 0`. `MY_Controller` checks flag → redirects to `/auth/change-password` if set. Cleared after successful password update.

### Phase 8A: Daily Revenue Distribution ✅ COMPLETED
- [x] **8A1: ~~Cron Job~~ — Daily ROI (KLAIM MANUAL)** — ~~Every day 00:01 WIB~~ *(plan/111: **tidak ada cron**.)* Kontrak `user_rentals` status `active` diklaim harian manual oleh user (`Rental_model::claim_roi`, gate T+1, idempotensi `ROI-{rental_id}-D{n}`) → `wallet_ledger` credit; `days_processed` naik; saat selesai → status `completed`. *(~~`transactions` insert~~ — didecommission M6.)*

### Phase 8B: Withdrawal Management UI ✅ COMPLETED
- [x] **8B1: User Withdrawal Page** — ~~Bank account management~~ *(plan/106: **e-wallet binding** — `/wallet/bind_bank` card selector 2×2 provider aktif)*. Withdrawal form (amount, **provider e-wallet** terikat). Fee preview (**3 baris Gross/Fee/Net — plan/109**). Submit with single-pending-WD guard.
- [x] **8B2: Admin Withdrawal Queue** — Command Center right column. APPROVE/DECLINE buttons. Decline triggers auto-rollback (Phase 5E).

### Phase 8C: Halaman Tim & Afiliasi ✅ COMPLETED
- [x] **8C1: Level 1 Mission Card** — Bloomberg Terminal dark aesthetic (`bg-slate-900 rounded-2xl p-5 border border-slate-800`). Two progress bars on `bg-slate-700` tracks with `bg-emerald-500` fill:
    * **Agent Bar:** `active_agents / 3` (target: 3 active L1+L2 downlines).
    * **Turnover Bar:** `total_sales / 330000` formatted as IDR (target: Rp 330.000).
    * Dynamic action button below bars:
        * Conditions not met → disabled `bg-slate-700 text-slate-400` "Klaim Bonus Level 1" with progress text (e.g., "1/3 Agen Aktif · Rp 150.000/330.000").
        * Conditions met → enabled `bg-emerald-500 hover:bg-emerald-600 text-white` "Klaim Bonus Rp 80.000".
- [x] **8C2: AJAX Claim API** — `POST /team/claim-level1`. Zero-Trust server-side validation (PRD v5.1 §4.G):
    * ACID transaction (`trans_start` / `trans_complete`).
    * Re-queries `User_model->get_downlines()` for active agents.
    * Re-sums `purchase_price` from `user_rentals` for turnover.
    * Checks `users.is_level_1_claimed` (idempotency flag — ~~description LIKE~~ lama).
    * `SELECT ... FOR UPDATE` on `users` row (race condition prevention).
    * On success: inserts `credit` (80000) to `wallet_ledger` via jalur tunggal `Wallet_model::credit()`. ~~+ `transactions` (type: `commission_bonus`)~~ *(tabel didecommission M6).* Returns JSON `{ success, message, data: { active_agents, total_sales, bonus_amount } }`.
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

### Daily Check-in — Bonus Absensi Harian (Member Widget + Admin Settings) ✅ COMPLETED (plan/112)
- [x] **Skema** — dua kolom `users` (`checkin_streak INT UNSIGNED NOT NULL DEFAULT 0`, `checkin_last_date DATE NULL DEFAULT NULL`) + 4 kunci `system_settings` (`checkin_enabled` · `checkin_base_reward` · `checkin_max_reward` · `checkin_streak_policy`); **tanpa tabel baru** (histori = `wallet_ledger` berprefix `CHK-`, tanpa kolom total).
- [x] **Migrasi CLI** — `scripts/migrate_112_daily_checkin.php` (`--dry-run` default / `--apply` / `--verify`, exit 0/1/2, pre-flight `information_schema`, DDL dijaga + `INSERT IGNORE`, tamper → exit 2, **tanpa backfill**); diterapkan ke DB lokal & re-run = no-op.
- [x] **Jalur uang** — `Checkin_model::claim()`: satu TX (anchor `users FOR UPDATE` → hitung hari → `UPDATE` kondisional + `affected_rows() === 1` → `Wallet_model::credit()` `CHK-{user_id}-{Ymd}` → commit); `db_debug` dimatikan lokal + `error()` dibaca SEBELUM rollback → 1062 ditranslasi `already_claimed` (jalur AJAX tidak pernah HTML). Fungsi murni `_streak_next()` / `_reward_for()`.
- [x] **Endpoint** — `POST /checkin/claim` (`Checkin`, POST+AJAX-only, rate limit `checkin_claim:{uid}` 5/60, envelope `api_*` + key legacy, `status` segar pada sukses & penolakan, `disabled` = HTTP 403). Rute eksplisit `checkin/claim`.
- [x] **Widget member** — `views/home/index.php` scoped `hm112-*` (Font Awesome, pratinjau 7 hari, progres cap via `intdiv` ceil, hitung mundur WIB dari epoch server, klaim via `csrfFetch` tanpa reload, `prefers-reduced-motion`); **tidak dirender sama sekali** saat `checkin_enabled='0'`.
- [x] **Admin** — kartu **Absensi Harian (Daily Check-in)** di `/admin/settings` (toggle + bonus hari pertama + batas harian + kebijakan streak; error inline `checkin_*` di kartunya sendiri, repopulasi via `settings_form_state`), all-or-nothing + audit `admin_update_settings` (key `checkin_*` otomatis tercatat).
- [x] **Kamus EN/ID** — +19 key (18 `home_checkin_*` + `ledger_checkin`) → **621** identik di kedua idiom; `audit_i18n_parity.php` exit 0, `audit_i18n_hardcoded.php` 0 temuan.
- [x] **Ringkasan** — `plan/112_DAILY_CHECKIN_FEATURE_SUMMARY.md`.

### Dynamic WhatsApp Group Link (Help & FAQ + Admin Settings) ✅ COMPLETED (plan/105)
- [x] **Key konfigurasi** — `system_settings.wa_group_link` (default `''`, **tanpa DDL**), seed kanonik `INSERT IGNORE` di `database.sql` + `database_seed.sql` (tidak pernah menimpa nilai live); baris hilang ≡ `''` ≡ kartu tersembunyi (fail-safe).
- [x] **Choke-point tunggal** — `application/helpers/wa_group_helper.php` (autoload `'wa_group'`): `wa_group_link_normalize()` (`''` = kosong sah / kanonik / `null` = invalid) + `wa_group_link_url()` (kanonik ATAU `''`, tidak pernah null). Allowlist ketat: host `chat.whatsapp.com` (https saja, tanpa userinfo/port), token `[A-Za-z0-9_-]{6,64}`, `www.` + `/invite/` + query/fragment/trailing-slash dinormalkan, input tanpa skema & `http://` → `https`, maks 512 char, ZWSP/BOM dibuang — dipakai **tiga** konsumen (admin POST, render member, CLI `--verify`).
- [x] **Migrasi CLI** — `scripts/migrate_105_wa_group_link.php` (`--dry-run` default / `--apply` / `--verify`; exit 0/1/2), idempoten (re-run = "sudah ada (dibiarkan)"), **tanpa fase ALTER/backfill** (key-value store), deteksi tamper → exit 2; sudah diterapkan ke DB live.
- [x] **Admin** — `/admin/settings` kartu **"Kontak & Bantuan"** (koreksi L1 dari header lama `General & Support`) + field **Link Grup WhatsApp (Komunitas)** di bawah `support_email`; validasi server-side all-or-nothing berbahasa Indonesia ("Link grup WhatsApp tidak valid…"), nilai kosong SAH (tanpa `required`); persist via `Admin_model::update_system_settings()` + audit generik `admin_update_settings` (before→after, hanya saat berubah).
- [x] **Member** — `Help::index()` meneruskan nilai terkanonikalisasi; kartu komunitas emerald/teal (gradient + orb glow, ikon `fa-users`, CTA full-width `target="_blank" rel="noopener noreferrer"`, href `html_escape()`) dirender **hanya bila** `$wa_group_link !== ''` → degradasi anggun tanpa tautan rusak.
- [x] **Kamus EN/ID** — +3 key (`help_wa_group_title`, `help_wa_group_desc`, `help_wa_group_btn`) → **594 key** identik di kedua idiom; `audit_i18n_parity.php` exit 0, `audit_i18n_hardcoded.php` 0 temuan.
- [x] **Ringkasan** — `plan/105_WHATSAPP_GROUP_LINK_SETTINGS_AND_HELP_INTEGRATION_SUMMARY.md`.

---

### Exclusive E-Wallet Withdrawal Gateway & Dynamic Provider Management ✅ COMPLETED (plan/106)
- [x] **Katalog provider dinamis** — tabel baru `ewallet_providers` (`id`, `code` UNIQUE `uk_ewallet_code`, `name`, `is_active`, `created_at`, `updated_at`) + seed kanonik `INSERT IGNORE` DANA / SHOPEEPAY / OVO / GOPAY. Tanpa hard delete (D7): provider dinonaktifkan, bukan dihapus.
- [x] **Migrasi CLI** — `scripts/migrate_106_ewallet_withdrawal.php` (7 fase: PRE-FLIGHT → INSPECT → DDL → SEED → BACKFILL nama bank legacy → ARSIP binding legacy → VERIFY; flag `--dry-run` default / `--apply` / `--verify` / `--keep-bindings` / `--default-provider`, exit 0/1/2). Idempoten (re-run = 0 dipetakan / 0 diarsipkan); **sudah diterapkan ke DB live** (17 baris dipetakan + 17 binding legacy diarsipkan, 0 orphan FK).
- [x] **Zero-breakage `bank_accounts`** — struktur tabel TIDAK diubah (FK `fk_withdrawals_bank` ON DELETE RESTRICT): `bank_name` = nama provider, `account_number` = nomor HP e-wallet `^08[0-9]{8,11}$`, `is_primary` = flag binding aktif (1 = terikat, 0 = arsip). Reset admin = **arsip**, bukan hapus → seluruh kartu riwayat penarikan tetap menampilkan provider + nomor asli.
- [x] **Choke-point nomor HP** — `application/helpers/ewallet_helper.php` (autoload `ewallet`): `ewallet_phone_normalize()` (62/0062 → 0), `ewallet_phone_is_valid()` / `ewallet_phone_validate()` (`^08[0-9]{8,11}$`), `ewallet_phone_mask()` — satu sumber aturan untuk controller member, 4 view, admin, dan verifikator CLI.
- [x] **Model katalog** — `Ewallet_model` (`get_active_providers`, `get_active_provider`, `get_provider_by_name`, `count_active_providers`, `get_providers_admin` + hitungan binding aktif/total, `create_provider`, `rename_provider`, `set_provider_active`, guard duplikat code & name).
- [x] **Admin provider CRUD** — `/admin/ewallet-providers` (+ `/create`, `/update/(:num)`, `/toggle_status/(:num)`, entri sidebar **E-Wallet**): tambah, rename (code immutable + cascade label binding + audit `rebound_bindings`), toggle aktif/nonaktif dengan guard **minimal satu provider aktif** dan peringatan jumlah akun terikat. Copy 100% Indonesia (L1), audit `admin_create/rename/toggle_ewallet_provider`.
- [x] **Reset / unbind admin** — kartu **Akun E-Wallet** di `/admin/user_detail/{id}` (provider + status, nomor ter-mask, nama pemilik) + aksi POST `admin/reset_ewallet/{id}` (form standalone, konfirmasi, peringatan bila ada penarikan pending); audit `admin_reset_ewallet` (nomor HP ter-mask — PII minimisation) + notifikasi dwibahasa `notif_ewallet_reset` di dalam TX yang sama.
- [x] **Binding member** — `/wallet/bind_bank` memakai **card selector 2×2** dari provider AKTIF (CSS `peer-checked:`, tanpa JS); input **Nomor HP E-Wallet** + **Nama Pemilik Akun**; validasi server: `provider_id` wajib ada & aktif (nama provider tidak pernah dipercaya dari klien), nomor wajib numerik/`08`/10–13 digit, nama ≤ 100 karakter; `Wallet_model::bind_user_ewallet()` memakai row-level lock `FOR UPDATE` (anti double-submit) dan tetap **immutable** bagi member.
- [x] **Gate penarikan** — `/wallet/withdraw` + `process_withdraw` menolak bila belum terikat (`wd_err_no_ewallet*`) atau providernya nonaktif (`wd_err_ewallet_inactive` → redirect `bind_bank` dengan notice); `Wallet_model::create_withdrawal()` memverifikasi ulang kepemilikan + status aktif binding **di dalam TX terkunci** (code `no_ewallet`) sebagai defense-in-depth.
- [x] **Terminologi e-wallet** — view wallet (`bank_bind`, `withdraw`, `index`), kartu pending WD (provider · nomor ter-mask), hub profil (`fa-wallet`), header CSV admin (`Provider E-Wallet` / `Nomor HP E-Wallet` / `Nama Pemilik Akun`), kolom riwayat admin (`E-Wallet`), FAQ bantuan.
- [x] **Kamus EN/ID** — 9 key di-rename (`bb_provider_label`, `bb_choose_provider`, `bb_phone_label`, `bb_phone_placeholder`, `bb_err_phone`, `profile_withdraw_ewallet`, `wd_ewallet_label`, `wd_err_no_ewallet`, `wd_err_no_ewallet_cta`), 14 nilai ditulis ulang, +8 key baru (`bb_phone_hint`, `bb_err_provider_invalid`, `bb_err_holder_too_long`, `bb_no_provider_available`, `bb_provider_inactive_notice`, `wd_err_ewallet_inactive`, `notif_ewallet_reset_title`, `notif_ewallet_reset_body`) → **602 key** identik di kedua idiom; token allowlist `'bank account'` dihapus dari `audit_i18n_parity.php`; kedua audit exit 0 dan `audit_i18n_hardcoded.php` 0 temuan.
- [x] **Ringkasan** — `plan/106_EWALLET_WITHDRAWAL_AND_PROVIDER_MANAGEMENT_SUMMARY.md`.

---

### Manual QRIS Deposit Gateway (Kode Unik 3 Digit) ✅ COMPLETED (plan/102)
- [x] **Skema `deposits`** — `unique_code SMALLINT UNSIGNED` (100–999, permanen sebagai jejak audit), `total_amount DECIMAL(15,2)` (nominal bayar **dibekukan** = pokok + [fee] + kode, **Option A** saat `deposit_fee_enabled='0'`), `reserved_code_key VARCHAR(24)` + `UNIQUE uk_reserved_code_key` (`"{pokok}-{kode}"` selama reservasi hidup, `NULL` setelah keluar), `expires_at`/`confirmed_at`/`processed_at`/`decline_reason`; ENUM status `('pending','waiting_approval','success','failed','rejected','expired')`; index `idx_status_expires`. Predikat identitas invoice = `INV-{YmdHis}-{user_id}-{6 hex CSPRNG}`.
- [x] **Model** — `Wallet_model`: `get_deposit_policy()`, `validate_deposit_settings()`, `create_deposit()` (satu TX, anchor `users FOR UPDATE`, alokasi kode CSPRNG via `_pick_unique_code()`, retry 3× pada duplicate key, **satu deposit hidup per user**), `confirm_deposit()` (`pending → waiting_approval`, tanpa unggah bukti), `expire_user_deposits()`/`expire_stale_deposits()` (lazy sweep, `waiting_approval` **tidak** disentuh — D1), `has_active_deposit()`/`get_active_deposits()`, `deposit_credit_amount()` (**kredit = pokok + kode**; fee deposit ditahan platform), `approve_deposit_simulator()`.
- [x] **Admin** — `Admin_model::approve_deposit()` (guard expiry hanya untuk `pending`), `decline_deposit()` baru, `get_deposit_queue()`, `count_history_deposits()`/`get_history_deposits()`; `get_alert_counts()['pending_deposits']` = `pending + waiting_approval`. Rute `wallet/pay/(:any)`, `wallet/confirm_payment/(:any)`, `admin/settings/qris`.
- [x] **View & konfigurasi** — `views/wallet/pay.php` (QR, merchant, nominal persis + kode ditonjolkan, breakdown, tombol salin, countdown server + satu auto-reload, 5 banner status, form "Saya Sudah Transfer"); kartu deposit hidup di `wallet/index.php`; upload QRIS allowlist + lifecycle-safe. Key `qris_image`/`qris_merchant_name`/`qris_payment_instructions`/`deposit_expiry_minutes`/`deposit_min_amount`/`deposit_max_amount`.
- [x] **Migrasi** — `scripts/migrate_102_qris_deposits.php` (`--dry-run` default / `--apply`, 10 klausa DDL + 2 backfill + 6 key setting, idempoten).
- [x] **Kamus EN/ID** — 332 → **378/378** key (paritas 1:1). **Ringkasan** — `plan/102_MANUAL_QRIS_UNIQUE_CODE_PAYMENT_SUMMARY.md`.

### i18n Purification — Wallet & Member Pages ✅ COMPLETED (plan/103)
- [x] **8 kelas kebocoran ditutup** di surface member → kamus **378 → 591/591** key (1:1 EN≡ID).
- [x] **Gate baru** — `scripts/audit_i18n_parity.php` (P1/P3/P3b/P5/P6) + `scripts/audit_i18n_hardcoded.php` (R1–R7 + allowlist), keduanya exit 0.
- [x] **Notifikasi keyed** — kolom `user_notifications.title_key` + `params` (JSON) + `i18n_notification_text()` (guard arity `vsprintf`); `Notification_model`, `Rental_model`, `User_model`, `Promoter_model::submit_claim()`, `ratelimit_helper`, dan beberapa controller dikonversi; pesan CI3 form-validation dilokalkan via `_set_fv_messages()` + `fv_*`.
- [x] **Migrasi** — `scripts/migrate_103_notification_i18n.php` (`--dry-run`/`--apply`, 26 baris, idempoten, **nol** mutasi `wallet_ledger`, **nol** audit — presentasi saja). **Ringkasan** — `plan/103_I18N_PURIFICATION_WALLET_AND_MEMBER_PAGES_SUMMARY.md`.

### GPU Product Real Image Support & Admin Upload ✅ COMPLETED (plan/104)
- [x] **Kolom** `gpu_products.image VARCHAR(255) NULL DEFAULT NULL` (basename di `uploads/products/`; `NULL`/berkas hilang → fallback).
- [x] **Choke-point** — `application/helpers/product_image_helper.php`: `product_image_filename()` (allowlist `jpg|jpeg|png|webp`, anti traversal/NUL/`://`), `product_image_path()`, `product_image_exists()` (memoized), `product_image_url()` (kontrak tunggal `null`).
- [x] **Marketplace** — `placehold.co` diganti kontainer `aspect-video … object-cover` + `loading="lazy"`; fallback banner gradien (tanpa teks, tanpa `<defs>`/`id`).
- [x] **Admin** — `form_open_multipart()`, kolom **Gambar** (colspan 8→9), unggah allowlist (`max_size=2048`, `encrypt_name`, `detect_mime`), hapus berkas hanya setelah persist sukses + guard `Admin_model::is_product_image_referenced()`; pesan error **selalu Indonesia** (L1). Prasyarat: entri `'webp'` di `application/config/mimes.php`.
- [x] **Migrasi** — `scripts/migrate_104_gpu_product_images.php` (`--dry-run`/`--apply`/`--verify`/`--keep-filenames`, **keyed by `name`**, idempoten, tak menimpa unggahan admin). **Ringkasan** — `plan/104_GPU_PRODUCT_IMAGES_AND_ADMIN_UPLOAD_SUMMARY.md`.

### Viral Promotional Copywriting Kit ✅ COMPLETED (plan/107 — NON-CODE)
- [x] **Deliverable** — `plan/107_VIRAL_PROMOTIONAL_COPYWRITING_KIT.md`: materi promosi bahasa Indonesia dalam 3 format (WhatsApp/Telegram, skrip affiliate/leader, sosial media) + tabel "ground truth" angka produk/wage/rebate/fee yang diverifikasi dari `database.sql` & model.
- [x] **Aturan anti-scam** — pakai *potensi/estimasi/simulasi/klaim harian*, hindari *dijamin untung/profit tetap/auto cuan/passive income otomatis*; wajib mengungkap biaya penarikan + jam operasional (Sen–Sabtu 07:00–19:00 WIB); jangan menjanjikan pencairan instan.
- [x] **Nol perubahan kode/skema/i18n** — dokumen konten saja. **Catatan:** PRD §G wage tier sudah dikoreksi ke angka kode (plan/111).

### Auto-fill Kode Referral via URL `/register?ref=CODE` ✅ COMPLETED (plan/108)
- [x] **Choke-point** — `application/helpers/referral_helper.php` (`referral_code_normalize()`/`referral_code_is_valid()`/`referral_code_resolve()`/`referral_capture_key()`/`referral_capture_ttl()` = **2592000 s / 30 hari**).
- [x] **Controller** — `Auth::_referral_prefill()`: menangkap `?ref=` (guard `is_array()`), persist session + cookie `referral_code`, resolve prioritas URL → stored; injeksi prefill via `$this->load->vars()`; cleanup (`unset_userdata` + `delete_cookie`) sebelum `redirect('login')`.
- [x] **View** — `register.php` memakai `set_value('invite_code', $invite_prefill ?? '')` (prioritas POST → URL ref → session → cookie; field tetap editable).
- [x] **Nol DDL, nol route baru, nol key kamus baru** (paritas tetap 602/602). **Ringkasan** — `plan/108_AUTOFILL_REFERRAL_SUMMARY.md` (verifikasi runtime sebagian pending QA manual).

### Tampilan Nominal NET Penarikan ✅ COMPLETED (plan/109)
- [x] **Standar** — NET sebagai nilai **primer**, gross/fee sebagai sub-teks, di **7 surface**: antrean admin, dialog konfirmasi approve, flash pasca-approve, riwayat admin (3 kolom Gross/Biaya/Net), kartu member, preview form (3 baris), notifikasi `notif_wd_approved` (arity 3).
- [x] **Choke-point** — `application/helpers/withdrawal_amount_helper.php` (`withdrawal_amount_parts()` / `withdrawal_amount_decorate()` → `gross_eff`/`fee_eff`/`net_eff`); `Admin_model::get_withdrawal_queue()` (SQL dipindah dari controller), `get_history_withdrawals()`.
- [x] **Ledger tidak disentuh** — debit tetap merekam **gross penuh**; hardening `html_escape()` pada data member di kartu antrean.
- [x] **Nol DDL/migrasi**; kamus 602/602 (4 nilai diubah, `notif_wd_approved_body` → 3 param). **Ringkasan** — `plan/109_WITHDRAWAL_NET_AMOUNT_DISPLAY_SUMMARY.md`.

### Perbaikan Validasi Tier & Minimal Penarikan (Admin) ✅ COMPLETED (plan/110)
- [x] **Choke-point** — `application/helpers/withdrawal_fee_helper.php`: `withdrawal_fee_tier_normalize()` (kontigu penuh + hard-error menyebut nomor baris), `withdrawal_fee_tier_rows_from_json()`, `withdrawal_fee_tier_json()`, `withdrawal_fee_tier_bps_to_pct()`.
- [x] **Derive, bukan assert** — endpoint tier turunan dinormalkan otomatis (baris 1 `min` ← `wd_min_amount`; baris terakhir `max` ← `max(…, wd_max_amount + 1)`) → `notices[]` + audit `auto_adjusted` (amandemen plan/56 §2.3, keputusan D2).
- [x] **Model** — `Wallet_model::validate_financial_settings()` bentuk kembalian aditif (`notices` + `field_errors`); `_norm_tiers`/`_resolve_financial_config`/`calculate_withdrawal_fee`/`_post` **identik** (nol perubahan jalur uang).
- [x] **View** — `admin/settings.php` Card 3 direnovasi (transport array `wd_tier_min[]/max[]/pct[]`, tombol "Rapikan Tier"/"Sesuaikan Batas Atas", `Min` baris 1 `readonly`, error inline per baris, repopulasi via `flashdata('settings_form_state')`).
- [x] **Nol DDL, nol route, nol key kamus**; gate i18n tetap exit 0. **Ringkasan** — `plan/110_FIX_WITHDRAWAL_TIER_VALIDATION_SUMMARY.md`.

---

## Upcoming Phases

### Phase 11: Production Payment Gateway (PLANNED)
- [ ] **11A: Payment Provider Integration** — Integrasi payment gateway daring (Midtrans, Xendit, atau sejenis) menggantikan verifikasi deposit **manual** QRIS (plan/102) + Dev Simulator.
- [ ] **11B: Webhook Handler** — Server-to-server payment notification processing. Signature verification. Idempotent transaction processing.
- [ ] **11C: Invoice PDF Generation** — Printable receipt for each deposit and withdrawal.

### Phase 12: Mobile Optimization & PWA (PLANNED)
- [ ] **12A: PWA Manifest** — Service worker, app manifest for "Add to Home Screen" on mobile.
- [ ] **12B: Push Notifications** — Web push API for real-time notification delivery (complement to AJAX polling).
- [ ] **12C: Performance Audit** — Lighthouse score optimization. Image compression. CDN for static assets.
