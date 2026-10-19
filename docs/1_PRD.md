# Product Requirements Document (PRD) v5.6
**Project Name:** Synapse (AI GPU Rental)
**Platform:** Web Application (100% Mobile-First / SPA-like Experience)
**Core Tech Stack:** CodeIgniter 3 (CI3), PHP 8.3.6, MySQL/MariaDB, Vanilla JavaScript, Tailwind CSS

> **v5.6 — catatan penambahan (plan/121).** Menambahkan **Lucky Spin Wheel** —
> insentif **referral** (bukan bonus pencapaian agensi): seorang upline menerima
> **tepat 1 tiket** saat downline L1-nya melakukan pembelian **non-trial
> pertama** (maksimal 1 tiket per downline **seumur hidup**, tanpa backfill).
> Hadiah berupa **uang tunai** yang dikreditkan ke **Saldo Utama**
> (`wallet_type = 'main'`, dapat ditarik) via `Wallet_model::credit()` dengan
> `transaction_id = 'SPIN-{spin_results.id}'`; pemilihan segmen **100%
> server-side** (`random_int()` CSPRNG + `spin_wheel_pick()`) sehingga klien
> tidak pernah menentukan hadiah. Skema: kolom `users.spin_tickets` (counter
> tiket — **bukan uang**) + **2 tabel baru** (`spin_ticket_grants`,
> `spin_results`) → **inventory kanonik 14 → 16 tabel**; key
> `system_settings.spin_wheel_enabled`/`spin_wheel_segments`; halaman member
> `GET /lucky-spin`, endpoint `POST /lucky-spin/spin`, dan panel admin
> `/admin/lucky-spin`. Invarian **SP1–SP12** (ERD §7). **Bonus Pencapaian Tim
> (plan/119) tetap satu-satunya reward pencapaian agensi** — Lucky Spin adalah
> kanal insentif referral terpisah dan tidak mengubah §G selain menambah
> sub-bagiannya.
>
> **v5.5 — catatan penghapusan (plan/120).** **Fitur "Misi Level 1"
> DIHENTIKAN** di lapis aplikasi: kartu misi `/team`, endpoint
> `POST /team/claim_level1`, `Team::claim_level1()`/`_claim_message()`,
> `User_model::claim_level1()`/`get_claim_data()`/`LEVEL1_BONUS`, dan 17 key
> kamusnya (`team_mission_l1`, `team_help_l1_*`, `team_js_claimed_bonus`, …)
> sudah **dihapus**. **Bonus Pencapaian Tim (plan/119) adalah SATU-SATUNYA
> reward pencapaian agensi.** Kolom `users.is_level_1_claimed` **DIPERTAHANKAN**
> sebagai retensi historis (nol pembaca/penulis — invariant L1R1), demikian juga
> baris `wallet_ledger` `L1-*` dan notifikasi `notif_bonus_l1` (kunci kamus
> `ledger_bonus_l1` & `notif_bonus_l1_*` tetap ada agar riwayat tidak tampil
> sebagai string mentah).
>
> **v5.4 — catatan sinkronisasi (plan/119).** **Gaji Mingguan (recurring,
> siklus 7 hari) DIGANTIKAN oleh Bonus Pencapaian Tim** (*Team Milestone
> Bonus*): tier & nominal kini **dinamis** (`system_settings.team_milestone_tiers`,
> diatur admin di `/admin/settings`), satu tier dibayar **SEKALI seumur hidup**
> per member, klaim **berurutan** dari tier terendah, tanpa cooldown/siklus,
> kredit ke **Saldo Utama**. **Bonus Level 1 (Rp 80.000) tetap independen** —
> tidak diubah *(**SUPERSEDED oleh plan/120** — lihat catatan v5.5 di atas:
> fitur Misi Level 1 dihentikan; kolom `is_level_1_claimed` hanya retensi)*.
> Referensi usang yang dipurge: `WAGE_TIERS`, `determine_wage_level()`,
> `check_wage_cooldown()`, `claim_wage()`, dan seluruh istilah "per minggu"
> pada reward agensi.
>
> **v5.3 — catatan sinkronisasi (plan/118).** Memperkenalkan **DOMpet ABSEN
> (`users.checkin_balance`) + audit multi-dompet (`wallet_ledger.wallet_type`)**:
> bonus Absensi Harian tidak lagi masuk saldo utama, melainkan dompet kedua yang
> **tidak dapat ditarik** dan hanya dapat **mensubsidi checkout sewa** (split
> payment: checkin dulu, sisanya saldo utama). Ditambah gerbang opsional
> `checkin_require_active_rental` (klaim absensi menuntut ≥ 1 kontrak aktif —
> trial maupun berbayar). Harga kontrak tetap penuh (`purchase_price`) sehingga
> ROI, rebate 3-tier, dan omzet upline tidak terpengaruh subsidi. Sumber
> kebenaran tetap **kode** (AGENTS.md).
>
> **v5.2 — catatan sinkronisasi (plan/112).** Menambahkan **mesin klaim manual
> baru: Bonus Absensi Harian** (daily check-in) — §F: bonus berjenjang
> `min(base × hari_streak, cap)` dengan kebijakan streak `reset`/`continue`,
> gerbang fail-closed `checkin_enabled`, dan kredit ledger ber-ID
> `CHK-{user_id}-{Ymd}`; dikonfigurasi di kartu **Absensi Harian** pada
> `/admin/settings`. Sumber kebenaran tetap **kode** (AGENTS.md).
>
> **v5.1 — catatan sinkronisasi (plan/111).** Dokumen ini disinkronkan ulang
> dengan **kode sebagai sumber kebenaran** (AGENTS.md) untuk rentang
> plan/102–110. Perubahan besar: alur deposit QRIS manual berkode unik
> (plan/102), binding penarikan **e-wallet** menggantikan kartu bank (plan/106),
> auto-fill kode referral via URL (plan/108), tampilan **NET** penarikan
> (plan/109), validasi tier dinamis (plan/110), dan koreksi wage tier §G
> (plan/80 P5). Referensi usang yang **dipurge**: "double-entry ledger",
> tabel `transactions`, `Ledger_model`, "Kartu Bank", dan seluruh cron job
> (ROI/expiry/wage bersifat klaim/lazy manual — tidak ada cron di repo).

---

## 1. Product Overview & Objective
Synapse adalah platform investasi dan penyewaan daya komputasi awan (GPUaaS). Pengguna dapat menyewa *node* GPU dengan durasi tertentu (Short-Term & Long-Term) untuk mendapatkan *Return of Investment* (ROI) berupa **pendapatan harian yang diklaim manual** (bukan auto-credit). Seluruh pencatatan uang memakai **satu ledger otoritatif: `wallet_ledger`** (append-only; tabel `transactions` double-entry telah **didecommission** pada M6). Sistem ini dilengkapi dengan sistem afiliasi (*Agency*) multi-level berjenjang dengan **satu** *reward* pencapaian: **Bonus Pencapaian Tim** (plan/119) — tier bonus **sekali seumur hidup per tier** (fitur **Bonus/Misi Level 1 dihentikan pada plan/120**) yang diklaim manual, berurutan, **tanpa cooldown/siklus mingguan** (tidak ada cron di repo) — ditambah **insentif referral Lucky Spin Wheel** (plan/121: 1 tiket per downline seumur hidup, hadiah tunai ke Saldo Utama). Pencairan dana bersifat **e-wallet only** (DANA, ShopeePay, OVO, GoPay — katalog dinamis yang dikelola admin).

> **Catatan operasional (tak ada cron).** Tidak ada pekerja otomatis di latar
> belakang: ROI harian = klaim manual (`Rental_model::claim_roi`, T+1), expiry
> sewa = lazy sweep per-request (`MY_Controller` → `Rental_model::expire_user_rentals()`)
> plus alat manual admin/CLI, expiry deposit = lazy sweep (`Wallet_model::expire_user_deposits()`),
> bonus pencapaian (**Bonus Pencapaian Tim** — satu-satunya reward pencapaian
> agensi) = klaim manual tanpa cooldown, dan **Lucky Spin** = aksi member
> on-demand (`POST /lucky-spin/spin`) yang menulis kredit tunai saat itu juga.

---

## 2. Currency Standard (IDR Enforcement)

**All monetary values across the entire platform — including but not limited to product pricing, wallet balances, ledger entries, transaction records, rental fees, withdrawal amounts, commission bonuses, and UI display formatting — MUST strictly use Indonesian Rupiah (IDR).**

| Layer | Enforcement |
|-------|-------------|
| **Database** | All `DECIMAL` columns store raw IDR values (no decimals for sen; integer-level precision sufficient). |
| **Backend (PHP)** | All CI3 controller/model logic operates on IDR integers. No currency conversion. Fee calculations, balance checks, and ledger insertions are IDR-native. |
| **Frontend (JS)** | All monetary display uses `Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })` for consistent, locale-accurate formatting. Manual formatting via `number_format($val, 0, ',', '.')` with `Rp ` prefix is acceptable in PHP views. |

No foreign currencies (USD, USDT, BTC, etc.) are supported at any layer.

> **Integer IDR discipline (M8, plan/74–75).** MySQL mempertahankan
> `DECIMAL(15,2)` demi kompatibilitas, **tetapi** saldo otoritatif dan seluruh
> aritmetika uang di aplikasi adalah **integer**: input moneter divalidasi
> server-side terhadap `^[1-9][0-9]*$` sebelum panggilan model, semua
> kredit/debit melewati satu choke-point `(int)` + assertion positif di
> `Wallet_model::_post()`, dan aritmetika fee memakai `intdiv()` (tanpa
> pembagian float). `get_balance()`/`lock_and_get_balance()` **selalu**
> mengembalikan `(int)`.

---

## 3. User Roles & Permissions

* **Guest (Unauthenticated):** Hanya dapat melihat halaman Login, Register, Lupa Sandi, dan Halaman Download/Landing Page.
* **User (Authenticated):** Memiliki dompet (*wallet*), dapat menyewa GPU, menarik dana **hanya ke akun e-wallet terikat**, dan memiliki *referral code*/link (`/register?ref=CODE`) untuk membangun tim/agensi. Pendapatan harian, ROI, dan bonus agensi (**Bonus Pencapaian Tim** — satu-satunya reward pencapaian sejak plan/120) **diklaim manual** oleh user. Sejak plan/121 user juga dapat memegang **tiket Lucky Spin** (`users.spin_tickets` — counter, **bukan uang**) yang diperoleh dari pembelian non-trial pertama downline L1-nya dan ditukar menjadi hadiah tunai di `/lucky-spin`.
* **Admin (System Operator):** Akses penuh ke **Command Center** (`/control-panel`) — gateway rahasia terpisah dari user flow. Memiliki hak approve/**decline** deposit QRIS & withdrawal, monitoring real-time queue, mengelola katalog e-wallet & produk, dan audit trail. Autentikasi terpisah menggunakan tabel `admins` (bukan `users`), session `admin_id`, dan middleware `Admin_auth` controller.
* **Lazy/Manual Worker (bukan cron):** Tidak ada pekerja latar otomatis. Sweep expiry sewa & deposit dijalankan **lazy per-request** oleh `MY_Controller` (setelah tz WIB + i18n + guard login) dan, bila perlu, dipicu manual oleh admin (`admin/expire_expired_rentals`) atau CLI (`scripts/expire_rentals.php`).

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
    * **Auto-fill Kode Undangan via URL (plan/108):** `GET /register?ref=CODE` men-sanitasi kode (`referral_code_normalize()` — buang karakter non-`[0-9A-Za-z]`, uppercase, clamp **6 = batas atas**), **mempersist**-nya ke session + cookie `referral_code` (**TTL 30 hari / 2592000 s**, samakan jendela cookie `site_lang`), lalu **mem-prefill** field `invite_code`. Prioritas nilai field: **POST → URL ref → session → cookie → kosong**; field tetap **editable** (`maxlength="6"`, tanpa `readonly`/`disabled`). Kode pendek (mis. `?ref=ab` → `AB`) sah ter-prefill tetapi **ditolak saat submit** oleh validasi kode undangan. Session + cookie dibersihkan setelah registrasi berhasil. Tautan share resmi (halaman Team) = `base_url('register?ref=' . invite_code)`.
* **Phone Sanitization Rule — "Flexible Frontend, Strict Backend" (v5.0):**
    * **Backend (Server-Side, MANDATORY):** Before any DB insert or lookup, the backend MUST normalize the phone number:
        1. Strip all non-digit characters: remove `+`, `-`, `()`, spaces, dots (`preg_replace('/\D/', '', …)`).
        2. Auto-convert leading `62` (Indonesian country code) → `0`.
        3. Auto-convert leading `0062` → `0` (ditangani oleh jalur e-wallet; `Auth::_normalize_phone` menangani `62`).
        4. Final normalized format: `0XXXXXXXXXX` (starts with `0`).
        5. Enforce keunikan via `is_unique[users.phone]` + `users.phone` `UNIQUE` (`uk_phone`).
    * **Frontend (Client-Side):** Use `type="tel"` and `inputmode="numeric"` for mobile keyboard optimization. Frontend applies the same stripping/conversion rules in real-time as the user types (on `input` event) so the preview shows normalized format. **No rigid `maxlength` or `minlength` HTML attributes.**
    * **⚠️ Koreksi plan/111 (temuan):** PRD v5.0 mengklaim backend menegakkan regex panjang keras `/^0[0-9]{9,13}$/`. **Regex tersebut tidak ada di kode** (grep seluruh `application/` = nihil) — validasi aktual = `required|is_unique[users.phone]` setelah normalisasi (`Auth::_normalize_phone`, `Admin::_normalize_phone`). Bila kebijakan panjang keras masih diinginkan, ia perlu **diimplementasikan di kode** (di luar scope plan/111). Aturan panjang kanonik yang **benar-benar** ditegakkan hanya milik **nomor HP e-wallet**: `^08[0-9]{8,11}$`.
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

The wallet operates on a **two-tier model**: a staging area for incoming deposits (`deposits`, gateway QRIS manual — plan/102) and an immutable ledger for confirmed funds (`wallet_ledger`, satu-satunya ledger otoritatif).

#### Tier 1 — Staging Deposits (`deposits` table) — Gateway QRIS Manual (plan/102)

Deposit tidak lagi memakai tombol simulator satu-klik sebagai alur utama; member membayar **QRIS statis merchant** secara **manual** dan deposit diverifikasi admin. Setiap invoice memuat **kode unik 3 digit** agar admin dapat mencocokkan mutasi masuk.

* **B.0 Kebijakan deposit (dinamis, `system_settings`).** `deposit_min_amount` (default Rp 10.000), `deposit_max_amount` (default Rp 50.000.000), `deposit_expiry_minutes` (default 60, clamp 5–1440). Fallback fail-safe ada di `application/config/withdrawal_fees.php`. Bila `qris_image` kosong, alur deposit **fail-closed** (`deposit_err_qris_unconfigured`).
* **B.1 `pending` — Buat invoice.** Sistem men-generate `invoice_number = INV-{YmdHis}-{user_id}-{6 hex CSPRNG}` dan mengalokasikan **kode unik 3 digit (100–999)** secara **CSPRNG** dari himpunan kode bebas untuk pokok tersebut (retry 3× bila terjadi tabrakan). Nilai `total_amount` **DIBEKUKAN** saat create = `pokok + [fee deposit] + kode` dan **tidak pernah dihitung ulang** saat render (menutup celah manipulasi). `reserved_code_key = "{pokok}-{kode}"` disimpan dengan `UNIQUE` (jaminan level DB: maksimal **satu pemilik hidup per (pokok, kode)**), dan `expires_at = sekarang + expiry_minutes`. **Satu deposit hidup per user** (`pending`/`waiting_approval`) — dijaga di dalam transaksi terkunci.
* **B.2 `waiting_approval` — Konfirmasi transfer member.** Member menekan **"Saya Sudah Transfer"** (`POST /wallet/confirm_payment/{invoice}`); **tidak ada unggah bukti**. `confirmed_at` diisi, transisi kondisional `WHERE status='pending'` + `affected_rows()===1`. Reservasi kode **tetap ditahan** dan status ini **tidak pernah auto-expire** (keputusan D1) — kode tidak akan direbut orang lain setelah member membayar.
* **B.3 `success` — Verifikasi admin.** Admin approve dari antrean Command Center (mode DEV/UAT memakai simulator `ENVIRONMENT`-gated). Di dalam satu transaksi ACID: status → `success`, `reserved_code_key` dilepas (`NULL`), dan **satu baris `credit`** ditulis ke `wallet_ledger`. **Nilai kredit = pokok + kode unik**; **fee deposit (bila aktif) DITAHAN platform** sehingga kredit tetap "pure principal" (saat `deposit_fee_enabled='0'` → kredit = `total_amount` persis — **Option A**). Notifikasi + audit ditulis atomik dalam TX yang sama.
* **B.4 Cabang terminal — `failed` / `rejected` / `expired`.** Admin dapat **decline** (`rejected`, dengan `decline_reason`) atau menandai `failed`. `pending` yang melewati `expires_at` ditutup oleh **lazy sweep** menjadi `expired` (`wallet_ledger` tidak disentuh; `reserved_code_key` dilepas sehingga kode dapat dipakai ulang). Baris **tidak dihapus** — `unique_code` tetap tersimpan sebagai jejak audit.
* **B.5 Jendela bayar & verifikasi.** Batas waktu = `expires_at` (countdown berbasis server + satu auto-reload di `views/wallet/pay.php`). **Tidak ada gate hari/jam untuk deposit** — verifikasi bersifat **manual oleh admin pada jam kerja** (libur/akhir pekan tetap dapat membayar QRIS, verifikasi menunggu admin).
* **B.6 Ledger.** `wallet_ledger` adalah **satu-satunya** ledger transaksi otoritatif (append-only; saldo = `SUM(credit) − SUM(debit)`). Tabel `transactions` double-entry **telah didecommission** (M6) dan **tidak boleh** dirujuk kembali.

> **Catatan dev/UAT:** Simulator satu-klik (`wallet/simulate_payment`, di-gate `ENVIRONMENT`) **tetap ada** untuk menguji pipeline deposit→ledger tanpa gateway daring. Ia menerima status `pending` **maupun** `waiting_approval`, mengkredit pokok + kode, dan **bukan** alur produksi. Penggantinya di masa depan = integrasi payment gateway (Roadmap Phase 11).

#### Tier 2 — Immutable Ledger (`wallet_ledger` table)
* Setiap mutasi uang (credit/debit) menulis **satu baris** ke `wallet_ledger` di dalam transaksi ACID dengan anchor lock `SELECT … FOR UPDATE` pada baris `users`.
* **Available Balance** dihitung dinamis **per dompet**: `SUM(credit) − SUM(debit) WHERE wallet_type = ?` via `Wallet_model::get_balance()` (mengembalikan `(int)`). Saldo tidak pernah disimpan sebagai nilai statis untuk operasi dompet.
* `wallet_ledger` bersifat **append-only** — baris tidak pernah di-update atau di-delete setelah ditulis.

#### B.7 Dompet Absen (`users.checkin_balance`) — plan/118

Platform memakai **dua dompet** pada satu akun, dibedakan oleh
`wallet_ledger.wallet_type ENUM('main','checkin')`:

| Dompet | `wallet_type` | Kolom cache | Sumber kredit | Dapat ditarik? |
|---|---|---|---|---|
| Saldo utama | `main` (DEFAULT) | `users.balance` | deposit QRIS, ROI, rebate L1–L3, **Bonus Pencapaian Tim (plan/119 — satu-satunya bonus agensi sejak plan/120)**, **hadiah Lucky Spin (plan/121)** | **Ya** (gate penarikan berlaku) |
| Saldo Absen | `checkin` | `users.checkin_balance` | **hanya** Bonus Absensi Harian (`CHK-{user_id}-{Ymd}`) | **Tidak** (invarian CK3) |

* **Saldo Absen tidak dapat ditarik dan tidak dapat dipindahkan** ke saldo utama. Satu-satunya penggunaan adalah **mensubsidi pembelian sewa GPU** (§D). Karena `Wallet_model::create_withdrawal()` hanya membaca dompet `main`, penarikan tidak dapat menyentuh `checkin_balance` tanpa kode tambahan.
* **Saldo otoritatif** tetap `wallet_ledger` (bukan kolom cache): `main` = `SUM(credit−debit) WHERE wallet_type='main'`, `checkin` = `SUM(credit−debit) WHERE wallet_type='checkin'` (invarian CK2). Kedua kolom cache di-`UPDATE` relatif oleh satu-satunya choke-point uang `Wallet_model::_post()` dalam TX yang sama.
* **Tanpa backfill (D3):** seluruh baris `wallet_ledger` pra-plan/118 memperoleh `wallet_type = 'main'` lewat DEFAULT, sehingga bonus absensi historis tetap berada di saldo utama. Setiap user memulai dengan `checkin_balance = 0.00`.
* **Bukan jalur admin:** `checkin_balance` **tidak** dapat di-inject/disesuaikan admin (D9) — hanya absensi (kredit) dan checkout (debit) yang menulis. Admin dapat **melihat**-nya di `/admin/user_detail` dan pada kartu treasury dashboard (`total_checkin_balances`).


### C. Marketplace & One-Screen Checkout

The Marketplace page presents GPU product cards in a scrollable feed. Each card displays: product image, name, description, rental price (IDR), and daily ROI (IDR).

#### One-Screen Checkout (Bottom Sheet Modal)
When a user taps **"Sewa Sekarang"** on any product card, a **dynamic Vanilla JS Bottom Sheet Modal** slides up from the bottom of the screen — no page reload, no navigation.

The modal performs the following checks client-side:
1. Reads the user's main-wallet balance (`wallet_type = 'main'`) and — plan/118 — the Bonus Balance (`checkin`) balance, both passed from PHP as JS variables at page load.
2. Compares `userBalance` (+ Bonus Balance when the split checkbox is ticked) against the product's `price`.
3. **If available ≥ price:**
    * Displays product name, price, and current balances (all formatted as IDR via `Intl.NumberFormat`).
    * Renders a **"Konfirmasi & Bayar"** button that submits a POST form to `/rentals/checkout`.
    * Balance indicator turns **emerald** (`text-emerald-600`).
4. **If available < price:**
    * Displays a **"Saldo Tidak Mencukupi — Isi Saldo"** link that redirects to `/wallet`.
    * Balance indicator turns **rose** (`text-rose-600`).
5. **Split payment (plan/118):** for **paid** products, when the user has a Bonus Balance > 0, the modal renders the checkbox **"Gunakan Saldo Absen"** (`name="use_checkin_balance"`, `value="1"`) plus the available amount. Ticking it recomputes the affordable total (`main + min(checkin, price)`), renders the split breakdown, and (un)toggles the pay button. Trial products (price 0) never show it (D8).

The modal overlay (`bg-black/60 backdrop-blur-sm`) closes on tap. The sheet animates via CSS `translate-y` transitions (300ms ease-out).

### D. Marketplace & Asset Rental (Sewa)
* **Katalog GPUaaS (Produk):**
    * Data produk dinamis dari tabel `gpu_products`, menampilkan: gambar produk, Nama Produk, Tipe (Short/Long Term), Harga Sewa (Rp), Durasi (Hari), Pendapatan Harian (Rp), dan Estimasi Total Penghasilan.
    * **Gambar produk (plan/104):** `gpu_products.image` menyimpan **basename** berkas di `uploads/products/`. Resolver tunggal `product_image_helper.php` (`product_image_url()`); `NULL`/berkas hilang → marketplace merender **fallback banner gradien** (tanpa gambar rusak). Unggah admin: allowlist `jpg|jpeg|png|webp`, maks 2048 KB, anti-orphan.
    * **Limit pembelian per user (plan/83):** `gpu_products.max_per_user` (`0` = tanpa batas) adalah satu-satunya gate kuota pembelian per-user. Produk nonaktif (`is_active = 0`) tidak muncul di marketplace.
* **Logika Transaksi Sewa (Checkout):**
    * Saat form dari Bottom Sheet Modal disubmit, `Rentals` controller memvalidasi saldo user server-side; bila daya beli (saldo utama + `checkin_balance` bila checkbox split aktif) `< harga`, redirect dengan flash error.
    * Bila daya beli cukup, satu transaksi ACID (`trans_begin`/`trans_commit` dengan anchor `SELECT … FOR UPDATE` pada `users`) menjalankan:
        1. **Split payment (plan/118):** `checkin_balance` dipakai **lebih dulu**, sisanya dari saldo utama — `checkin_used = min(checkin, harga)`, `main_used = harga − checkin_used` (integer IDR murni; asersi `checkin_used + main_used === harga`). Setiap komponen **> 0** ditulis sebagai **baris `wallet_ledger` terpisah** lewat satu-satunya choke-point uang `Wallet_model::debit()` dengan `transaction_id` berbeda (`…-CHK` dompet `checkin`, `…-MAIN` dompet `main`; tanpa split, ID legacy `RENT-{product_id}-{YmdHis}` dipakai apa adanya). Kegagalan salah satu → rollback penuh.
        2. **Insert** kontrak ke tabel **`user_rentals`** dengan `status = 'active'`, `source = 'purchase'`, `purchase_price`, `daily_roi` (snapshot `daily_rate`), `total_days`, dan `expired_at` (batas kedaluwarsa WIB). **`purchase_price` SELALU harga penuh** — subsidi hanya mengubah komposisi pembayaran, bukan nilai kontrak (invarian CK6).
        3. **Distribusi rebate 3-tier** (plan/89): upline aktif L1/L2/L3 menerima `credit` ber-`transaction_id` `RBT-{rental_id}-L{tier}` — semuanya lewat `Wallet_model::credit()` (jalur tunggal), di dalam TX yang sama (langkah 7 sebelum commit) dan tetap dihitung dari **harga penuh**.
        4. **Grant tiket Lucky Spin (langkah 7b, plan/121):** hanya pada pembelian **non-trial PERTAMA** seorang member, upline L1-nya menerima **1 tiket** (`Spin_model::award_ticket_for_first_paid_rental()` → baris `spin_ticket_grants`, `UNIQUE uk_spin_grant_downline` + `users.spin_tickets + 1`). Trial gratis & kontrak `promoter_reward` **tidak** menghasilkan tiket; kegagalan grant → rollback seluruh TX (SP12).
    * **Constraint:** seluruh mutasi dibungkus satu *Database Transaction* — kegagalan salah satu langkah membatalkan semuanya (tidak ada saldo minus / setengah kontrak). Model yang bertanggung jawab: `Rental_model` (checkout/claim/expiry/rebate) + `Wallet_model` (uang). **Tidak ada** SQL di controller/view.
    * **ROI harian = klaim manual** (`Rental_model::claim_roi`, gate `ROI-{rental_id}-D{n}`, T+1) — bukan auto-credit dan bukan cron.
    * **Produk trial gratis** (harga 0, `is_trial = 1`): jalur bebas tanpa debit sama sekali — checkbox saldo absen **diabaikan** (D8).

### E. Financial Ledger & Wallet
* **Aturan Emas:** Saldo user dihitung dari mutasi tabel **`wallet_ledger`** (Total Credit − Total Debit) **per dompet** (`wallet_type`) — **satu-satunya** ledger otoritatif. Tabel `transactions` double-entry telah **didecommission** (M6): jangan membuatnya kembali atau menulis ke sana.
* **Dua dompet, satu ledger (plan/118):** `main` (dapat ditarik, `users.balance`) dan `checkin` ("Saldo Absen", `users.checkin_balance`). **Penarikan dana HANYA membaca dompet `main`** — `Wallet_model::create_withdrawal()` memakai `lock_and_get_balance()` yang difilter `wallet_type = 'main'`, sehingga `checkin_balance` tidak pernah dapat dicairkan (invarian CK3).
* **Penarikan Dana (Withdrawal) — E-Wallet Only (plan/106):**
    * **Pengikatan E-Wallet (menggantikan Kartu Bank):** User **wajib** mengikat satu akun **e-wallet** sebelum dapat menarik dana. Menggantikan skema lama "Kartu Bank (Nama Bank / Nomor Rekening / Nama Pemegang)".
        * **Provider** dipilih dari **katalog dinamis** `ewallet_providers` yang dikelola admin (`/admin/ewallet-providers`). Seed kanonik: **DANA · ShopeePay (SHOPEEPAY) · OVO · GoPay (GOPAY)**; LinkAja **tidak** di-seed (katalog bersifat admin-manageable — provider baru ditambahkan admin kapan pun, tanpa perubahan kode).
        * **Nomor HP E-Wallet** wajib kanonik `^08[0-9]{8,11}$` (normalisasi `+62`/`0062` → `0`; numerik murni; **10–13 digit**). Choke-point tunggal `application/helpers/ewallet_helper.php`.
        * **Nama Pemilik Akun** (≤ 100 karakter).
        * Binding bersifat **immutable** bagi member (tidak bisa diubah sendiri tanpa reset admin) dan dijaga `FOR UPDATE` (anti double-submit). Tampilan nomor **di-mask** (mis. `0812*****01`) via `ewallet_phone_mask()`.
        * **Provider nonaktif** → penarikan diblokir (`wd_err_ewallet_inactive`, arahkan binding ulang). **Reset/unbind admin** = **ARSIP** (`is_primary = 0`), **bukan DELETE** (FK `fk_withdrawals_bank` `ON DELETE RESTRICT`) — audit `admin_reset_ewallet` (nomor ter-mask) + notifikasi dwibahasa.
    * **Jam Operasional:** Penarikan hanya dapat **diajukan** pada hari operasional (`wd_operational_days`, default Senin–Sabtu) pukul `wd_open_time`–`wd_close_time` (default **07:00–19:00 WIB**). Permintaan di luar hari/jam ditolak keras (gate **pengajuan**; gate ini dievaluasi ulang **di dalam** transaksi terkunci).
    * **Batas Minimum & Maksimum (DINAMIS):** `wd_min_amount` (default Rp 100.000) dan `wd_max_amount` (default Rp 50.000.000) dibaca dari `system_settings` dan **dapat diubah admin** di `/admin/settings`. **Hapus asumsi keras "Minimum Rp 100.000"**: admin boleh menurunkannya (mis. Rp 50.000) tanpa mengedit baris tier manual (plan/110). Berkas `application/config/withdrawal_fees.php` adalah **fail-safe**, bukan otoritas operasional.
    * **Limit Frekuensi:** Hanya **1 penarikan per hari per user** (`has_reached_daily_wd_limit`).
    * **Anti-Spam & Race-Condition Prevention (Single Pending WD Limit):** User **tidak dapat** mengajukan penarikan baru bila masih ada `pending` (`has_pending_withdrawal`). Kedua gate dievaluasi ulang **di dalam** TX terkunci (`SELECT … FOR UPDATE` pada `users`) sebagai otoritas, bukan sekadar pre-check controller.
    * **Struktur Biaya Penarikan (Fee Calculation Rule — tier half-open `[min, max)`):**
        * Tier bersifat **kontigu penuh** dan **half-open**: nominal tepat di batas masuk tier **lebih tinggi** (lebih murah).
        * Fee = `floor(gross × bps / 10000) + fixed_fee` (integer IDR; `intdiv()`, tanpa float). `fixed_fee` default Rp 6.500.
        * Tier default (seed; dinamis via `system_settings.wd_fee_tiers`):
            * Rp 100.000 – < Rp 500.000: **10%** + Rp 6.500
            * Rp 500.000 – < Rp 1.000.000: **7,5%** + Rp 6.500
            * Rp 1.000.000 – < Rp 2.000.000: **6,5%** + Rp 6.500
            * Rp 2.000.000 – < Rp 5.000.000: **5%** + Rp 6.500
            * Rp 5.000.000 – < Rp 10.000.000: **4%** + Rp 6.500
            * Rp 10.000.000 – ≤ Rp 50.000.000: **3%** + Rp 6.500
        * Contoh kanonik: Rp 500.000 → fee Rp 44.000; Rp 1.000.000 → fee Rp 71.500; Rp 5.000.000 → fee Rp 206.500.
        * **Aturan tier kanonik (plan/110):** minimal 1 baris; `0 < min < max`; persen `0–100`; baris **kontigu & menaik** (gap/overlap = error keras yang menyebut nomor baris + kedua nilai); **dua endpoint turunan dinormalkan otomatis** — baris 1 `min` ← `wd_min_amount`, baris terakhir `max` ← `max(…, wd_max_amount + 1)` — dilaporkan sebagai `notices` + audit `auto_adjusted`. Jaminan "`[wd_min_amount, wd_max_amount]` tercakup tepat satu tier" ini penting karena `calculate_withdrawal_fee()` memakai tarif tier terakhir sebagai fallback bila nominal tidak masuk tier mana pun.
    * **Net Amount Display Standard (plan/109):** Seluruh surface menampilkan **NET** (dana yang benar-benar ditransfer admin) sebagai **nilai primer**, dengan **Gross/Fee sebagai sub-teks**:
        * Antrean admin: label **"Wajib Transfer (Net)"** + `Rp {net}` + sub-teks `(Penarikan: Rp {gross} | Biaya: Rp {fee})`.
        * Riwayat admin: 3 kolom terpisah **Gross / Biaya / Net (ditransfer)**.
        * Kartu member: **"Estimasi Dana Diterima (Net)"** primer + rincian gross/fee.
        * Preview form penarikan: 3 baris eksplisit Gross / Fee / Net.
        * Notifikasi approve (arity 3): menyebut gross, biaya, dan **dana net**.
    * Dana yang dipotong dari saldo user adalah **`gross_amount`** (penarikan kotor). **`wallet_ledger` tidak diubah oleh plan/109** — debit tetap merekam **gross penuh**; tampilan NET/gross/fee murni **presentasi** (`withdrawal_amount_helper.php`).
    * **Auto-Rollback on Decline:** Jika admin menolak penarikan, sistem otomatis mengembalikan dana ke wallet user via satu baris `credit` (description "Pengembalian Dana: Penarikan Ditolak ({wd_number})"). Refund dibungkus satu DB transaction bersama update status → `failed` (atomicity).
    * **Gerbang tujuan di dalam TX:** `Wallet_model::create_withdrawal()` memverifikasi ulang kepemilikan + status binding aktif (`is_primary = 1`) **di dalam** transaksi terkunci; kegagalan → code `no_ewallet` (defense-in-depth terhadap reset admin di tengah proses).
* **Balance Capsule (Global Header):**
    * Saldo user ditampilkan persisten di header member (satu dari **tiga grup** header: Brand · Balance Pill · Notification Bell — plan/100).
    * Powered by `MY_Controller.php` — constructor meng-inject `$global_balance` ke semua view via `$this->load->vars()`.
    * Komponen `header.php` merender balance capsule: tombol ke `/wallet` dengan ikon dompet, font monospace `Rp {balance}`.

### F. Otomatisasi Lazy / Klaim Manual (TANPA Cron)

**Tidak ada cron job di repo ini.** Seluruh "otomatisasi" bersifat **lazy/event-driven** pada request member, atau **dipicu manual** oleh admin/CLI (batasan owner). Empat mesin utama:

* **Distribusi ROI Harian — KLAIM MANUAL:**
    * User mengklaim ROI per kontrak via `POST /rentals/claim/{id}` → `Rental_model::claim_roi()` (idempotensi `transaction_id = ROI-{rental_id}-D{n}`, gate T+1).
    * Credit ditulis ke **`wallet_ledger`** (bukan tabel `transactions` yang sudah didecommission). Jika `days_processed` mencapai `total_days`, kontrak ditutup menjadi `completed`.
* **Expiry Sewa — LAZY SWEEP:**
    * `MY_Controller` menjalankan `Rental_model::expire_user_rentals()` pada setiap request terautentikasi (setelah pin WIB M2 + i18n + guard login). Semua filter "kontrak aktif" memakai `expired_at > <PHP WIB bound>`, **bukan** `NOW()` MySQL.
    * Alat manual: admin `POST /admin/expire_expired_rentals` (dashboard "Tutup Sewa Kedaluwarsa") dan CLI `scripts/expire_rentals.php` (`--dry-run`/`--apply`).
* **Expiry Deposit — LAZY SWEEP:**
    * `MY_Controller` menjalankan `Wallet_model::expire_user_deposits()` (per-user, `pending` yang lewat `expires_at` → `expired`); alat global: `Wallet_model::expire_stale_deposits()` (entry admin/CLI).
    * **`waiting_approval` tidak pernah disentuh** oleh sweep (keputusan D1 plan/102).
* **Bonus Absensi Harian — KLAIM MANUAL (1× per hari WIB; plan/112 + plan/118):**
    * User mengklaim bonus harian via `POST /checkin/claim` → `Checkin_model::claim()` (POST + AJAX-only, rate limit `checkin_claim:{user_id}` 5/60 dtk). Idempotensi `transaction_id = CHK-{user_id}-{Ymd}`; UNIQUE `(user_id, transaction_id, type)` pada `wallet_ledger` menjamin **maksimal satu kredit per user per hari** di tingkat DB (duplikat 1062 ditranslasi menjadi `already_claimed`, bukan error).
    * **Bonus = `min(bonus_hari_pertama × hari_streak, batas_harian)`** — integer IDR (default base **Rp 50**, cap **Rp 10.000**/hari); seluruh aritmetika di server (`_reward_for()`, tanpa float).
    * **Kredit dompet (plan/118):** bonus masuk **dompet `checkin`** (`users.checkin_balance` = "Saldo Absen"), **BUKAN** saldo utama — `Wallet_model::credit(..., Wallet_model::WALLET_CHECKIN)`. Saldo absen **tidak dapat ditarik** dan hanya dapat mensubsidi checkout sewa (§B.7/§D). Bonus absensi yang diklaim **sebelum** plan/118 tetap berada di saldo utama (tanpa backfill, D3).
    * **Syarat sewa aktif (plan/118):** `checkin_require_active_rental` (default `'0'` = OFF). Bila `'1'`, klaim ditolak dengan `code: 'no_active_rental'` kecuali user memiliki **≥ 1 kontrak** `user_rentals` dengan `status = 'active'` **DAN** `expired_at > now WIB` — **trial maupun berbayar** (predikat kanonik `Rental_model::has_active_rental()`, diuji **di dalam** TX setelah anchor lock). Widget dashboard merender **state terkunci** (tombol disabled + hint + CTA ke `/marketplace`) alih-alih menyembunyikan kartu.
    * **State:** `users.checkin_streak` + `users.checkin_last_date`. Otoritas "hari" = **PHP WIB** (`date('Y-m-d')`), bukan `NOW()`/`CURDATE()` MySQL. Kebijakan saat bolong (`checkin_streak_policy`): `reset` (kembali hari ke-1) atau `continue` (lanjut N+1 — gap tidak mereset, hari terlewat tidak dihitung). Tanggal di masa depan diperlakukan "sudah klaim" (fail-closed).
    * **Konfigurasi `system_settings`:** `checkin_enabled` (**fail-closed**: `'0'` → widget member tidak dirender **dan** endpoint menolak HTTP 403) · `checkin_base_reward` · `checkin_max_reward` · `checkin_streak_policy` (invarian **`1 ≤ base ≤ max`**) · `checkin_require_active_rental` (`'0'`/`'1'`); fallback per-key `application/config/checkin_rewards.php`; diatur admin di `/admin/settings` (kartu **Absensi Harian**) — all-or-nothing + audit `admin_update_settings`.
    * **Ledger:** kredit ditulis lewat `Wallet_model::credit()` (satu-satunya jalur uang) dengan `wallet_type = 'checkin'` dan deskripsi kanonik `Bonus Absensi Harian Hari ke-{N}` (dirender dalam idiom aktif via key `ledger_checkin`). **Histori absensi tidak punya tabel sendiri** — cukup query `wallet_ledger` berprefix `CHK-` (tanpa kolom total tambahan); `get_lifetime_claims()` sengaja **tanpa** filter dompet agar mencakup baris historis `main` maupun baru `checkin`.
    * **UI member:** widget dashboard (`views/home/index.php`, scoped `hm112-*`, Font Awesome) menampilkan rentetan hari, bonus hari ini, progres ke cap, **saldo absen**, pratinjau 7 hari, hitung mundur ke tengah malam WIB, dan tombol klaim (POST AJAX ber-CSRF via `csrfFetch`, tanpa reload).
    * **Kontrak endpoint (POST + AJAX-only; plan/113/118):** `POST /checkin/claim` **menolak** (`show_404()`) bila bukan POST atau bukan AJAX (nol HTML di jalur JSON). Rate limit `checkin_claim:{user_id}` = **5 percobaan / 60 detik** → kelebihan → HTTP **429** (JSON terlokalisasi). Semua respons lewat `api_helper` (`api_success()`/`api_error()`) + key legacy di root; kode hasil dan HTTP-nya: `ok` (200) · `already_claimed` (200) · `user_unavailable` (200) · `no_active_rental` (200, plan/118) · `disabled` (**403**, fail-closed) · `error` (500) · `unauthenticated` (**401**). Respons **sukses maupun penolakan** menyertakan `status` segar (`Checkin_model::get_status()`) sehingga widget memutakhirkan diri tanpa reload halaman; respons sukses menambahkan `wallet_type: 'checkin'` + `new_checkin_balance` (dan tetap menyertakan `new_balance` = saldo utama).
    * **Urutan transaksi yang mengikat (plan/113 + plan/118):** satu TX — anchor `SELECT … FROM users WHERE id = ? FOR UPDATE` (statement DB pertama, C5) → hitung hari (`_streak_next()`; `last === today` atau tanggal masa depan → `already_claimed`) → **gate syarat sewa aktif** (`require_active_rental` && `!has_active_rental()` → `no_active_rental`) → `UPDATE users SET checkin_streak/checkin_last_date` **kondisional** (`checkin_last_date IS NULL OR checkin_last_date < ?`) dengan guard `affected_rows() === 1` (M4) → `Wallet_model::credit()` dompet `checkin` untuk `CHK-{user_id}-{Ymd}` → commit. `db_debug` dimatikan lokal (save/restore) dan `$this->db->error()` dibaca **sebelum** rollback, sehingga kegagalan duplikat `1062`/`23000` tetap berwujud JSON `already_claimed` — bukan halaman error (M9/P7).

> **Catatan migrasi dokumen:** bagian ini dahulu mendeskripsikan cron `00:01 WIB` untuk pendapatan harian dan cron Senin `01:00` untuk gaji. Keduanya **tidak ada** di kode — jangan dihidupkan kembali tanpa pekerjaan pengembangan terpisah.

### G. Affiliate & Agency System (MLM Structure)
* **Hierarki:** Sistem melacak keturunan langsung (Agen B, `parent_id`) dan seluruh keturunan di bawahnya (**recursive CTE** untuk penghitungan tier Bonus Pencapaian Tim). Definisi **Active Downline (plan/116):** user *downline* yang memiliki **minimal 1 kontrak aktif dari produk NON-trial** di `user_rentals` — `ur.status = 'active'` **DAN** `ur.expired_at > now WIB` (filter defensif M3) **DAN** `gpu_products.is_trial = 0`. Kontrak dari produk trial gratis (`is_trial = 1`, harga 0 — plan/114) **TIDAK PERNAH** dihitung sebagai downline aktif, agar 1 trial gratis (1× lifetime) tidak bisa membuka tier Bonus Pencapaian Tim tanpa modal. Predikat kanonik = `Rental_model::has_paid_rental()`; titik penerapan seragam: `User_model::count_all_active_downlines()` (otoritas tier Bonus Pencapaian Tim), `count_active_b_downlines()` (legacy — dulu syarat 3 downline bonus Level 1; **tanpa pemanggil sejak plan/120**, sengaja dipertahankan), `get_team_with_active_status()` (badge "Aktif" di `/team`), dan kelayakan upline rebate `Rental_model::_distribute_rebate()` (upline trial-only = breakage, tanpa pass-up).
* **Agency Rules & Rewards (ground truth: `system_settings.team_milestone_tiers` / fallback `application/config/team_milestones.php`; kode otoritatif — plan/119):**
    * ~~**Bonus Level 1 (TETAP INDEPENDEN, keputusan owner O1):** syarat 3 downline aktif B + total omset B ≥ Rp 330.000; reward Rp 80.000 sekali (flag `users.is_level_1_claimed`).~~ **DIHENTIKAN — plan/120.** Fitur Misi Level 1 dihapus dari aplikasi (`dec-fc8726a949157eb0`); **Bonus Pencapaian Tim di bawah adalah SATU-SATUNYA reward pencapaian agensi.** Kolom `users.is_level_1_claimed` DIPERTAHANKAN sebagai retensi historis (nol pembaca/penulis — L1R1).
    * **Bonus Pencapaian Tim (pengganti Gaji Mingguan):** **tier DINAMIS** (`[[target_downline_aktif, nominal_idr], …]`, kanonik menaik, maks 20 tier) — diatur admin di `/admin/settings` (kartu **Bonus Pencapaian Tim**; all-or-nothing + audit `admin_update_settings`). Satu tier dibayar **SEKALI seumur hidup** per member, **tanpa cooldown/siklus**, klaim **berurutan** dari tier terendah, kredit ke **Saldo Utama** (`wallet_type = 'main'`). **Seed default = mirror tier gaji lama** (9 → Rp 200.000 · 30 → Rp 1.000.000 · 70 → Rp 2.500.000 · 130 → Rp 5.000.000 · 190 → Rp 9.000.000) supaya ambang & nominal yang sudah dipublikasikan tidak berubah — hanya periodenya (per minggu → sekali). Nominal **tidak pernah** masuk kamus (L6); kartu `/team` & modal bantuan me-*loop* konfigurasi.
    * **Perbedaan konsekuensi:** tidak ada lagi "level 2–6"; tidak ada penjadwalan; tier yang sudah diklaim **permanen** (turunnya jumlah downline tidak membatalkan / mengulang bayaran, MK6).
* **~~Zero-Trust Level 1 Bonus Claim (v5.0)~~ — REMOVED (plan/120):**
    * Seluruh alur (tombol "Klaim Bonus" di `/team`, `POST /team/claim-level1`, validasi zero-trust `active_b ≥ 3` + `sales_b ≥ 330000`, idempotensi `users.is_level_1_claimed`, dan kredit `wallet_ledger` Rp 80.000 `L1-{user_id}-{YmdHis}`) **sudah dihapus dari kode**. Endpoint kini **404**.
    * **Retensi:** baris `wallet_ledger` historis ber-`transaction_id` `L1-*` dan notifikasi `title_key='notif_bonus_l1'` **tidak dimutasi**; deskripsi ledger tetap diterjemahkan lewat `ledger_bonus_l1` (`i18n_helper`) dan kamus `notif_bonus_l1_*` dipertahankan (invarian L1R4).
* **Klaim Bonus Pencapaian Tim (plan/119) — MANUAL, TANPA CRON, TANPA COOLDOWN:**
    * Member mengklaim **satu tier** via `POST /team/claim_milestone` (budget `target` saja — **tanpa** nilai nominal dari klien). Tier & nominal **selalu** dibaca dari konfigurasi server (`system_settings.team_milestone_tiers`); target di luar konfigurasi → `code: 'invalid_target'` (nol mutasi).
    * **Urutan TX yang mengikat (fail-closed, MK5):** satu TX — anchor `SELECT … FROM users WHERE id = ? FOR UPDATE` (statement DB pertama) → gerbang akun (banned → `user_unavailable`) → resolve konfigurasi (`enabled`; `'0'` → `disabled`) → gerbang target → **idempotensi** (`target` sudah ada di `users.team_milestone_claimed` → `already_claimed`) → **gerbang sekuensial** (semua tier lebih rendah wajib sudah diklaim → selain itu `locked_previous`; dievaluasi **sebelum** recount supaya hasil deterministik) → recount `count_all_active_downlines()` (predikat plan/116 K8, **di dalam** TX; kurang → `not_qualified` + `missing`) → `UPDATE users SET team_milestone_claimed` **kondisional NULL-safe** + guard `affected_rows() === 1` (M4) → `Wallet_model::credit()` dompet `main` dengan `transaction_id = MSB-{user_id}-T{target}` → commit. Semua kegagalan = rollback penuh (0 baris ledger).
    * **Idempotensi permanen:** satu klaim per `(user_id, target)` **seumur hidup** — dijaga tiga lapis (state CSV + `UNIQUE uk_wallet_ledger_user_tx_type` + recount terkunci). Klaim tidak dapat diulang walau jumlah downline turun dan naik lagi (MK2/MK6).
    * **Kontrak endpoint:** POST + AJAX-only (`show_404()` untuk GET/non-AJAX — nol HTML di jalur JSON); rate limit `milestone_claim:{user_id}` **5 percobaan / 60 detik** → kelebihan = HTTP **429**; sesi tanpa `user_id` di-redirect ke `/login` oleh guard `MY_Controller` (parity seluruh endpoint member). Semua respons lewat `api_helper` + key legacy di root; kode & HTTP: `claimed` (200) · `already_claimed` (200) · `locked_previous` (200) · `not_qualified` (200, + `missing`) · `invalid_target` (200) · `disabled` (**403**, fail-closed) · `user_unavailable` (200) · `error` (500). Respons sukses menyertakan `new_balance` (saldo utama segar) **dan** `data.milestones` (state tier segar) sehingga kartu `/team` dapat memutakhirkan diri **tanpa reload**; notifikasi `notif_milestone` dikirim ber-`type: 'commission'`.
    * **Kredit & deskripsi:** `wallet_ledger` baris `credit`, `wallet_type = 'main'`, deskripsi kanonik `Bonus Pencapaian Tim {target} Downline Aktif` (dirender idiom aktif via `ledger_milestone`); **tanpa nominal di deskripsi/kamus** (L6). Riwayat klaim karena itu **tidak** butuh tabel baru.
    * **Retensi historis:** baris `WAGE-*` (Gaji Mingguan lama) **tidak pernah** diubah/dihapus, dan key kamus `ledger_wage` / `notif_wage_title` / `notif_wage_body` **dipertahankan** agar riwayat tersebut tetap terbaca dalam idiom aktif. Kolom `users.last_wage_claimed_at` menjadi DEPRECATED (retensi).
* **Rebate 3-Tier (plan/89):** Setiap pembelian kontrak berbayar mendistribusikan rebate ke upline aktif L1/L2/L3 (default **L1 5% · L2 3% · L3 1%**, dinamis via `system_settings` `rebate_enabled`/`rebate_l1_percent`/`rebate_l2_percent`/`rebate_l3_percent`; fallback `application/config/rebate_commission.php`). Distribusi berjalan **di dalam TX checkout** (`_distribute_rebate`, langkah 7 sebelum commit) lewat jalur tunggal `Wallet_model::credit()` dengan `transaction_id = RBT-{rental_id}-L{tier}`. Upline nonaktif/banned = breakage (tanpa pass-up). **plan/116:** "aktif" di sini = kontrak aktif dari produk **non-trial** (`gpu_products.is_trial = 0`) — upline yang hanya memegang trial gratis = breakage, tanpa pass-up; kontrak reward `promoter_reward` non-trial **tetap** eligible (K7).
* **Referral Gating (plan/89–92):** Kode/link/QR referral disembunyikan di DOM sampai kriteria sewa terpenuhi (Condition A/B/C), **kecuali** user ber-flag promotor (`is_promoter = 1`, admin-only).
* **Program Promotor (Omzet Burn, plan/91–92):** Promotor dapat menukar omzet L1 menjadi **kontrak reward zero-cost** (`source='promoter_reward'`, `purchase_price = 0`) melalui klaim `promoter_claims` yang di-approve admin. Burn = **bookkeeping murni** — **tidak pernah** menyentuh `wallet_ledger`; kuota kanal reward terpisah dari kuota pembelian berbayar (K4); rasio CAC dijaga 8–10% (K5).

* **Lucky Spin Wheel (plan/121):** insentif referral berulang — **bukan** jalur uang baru.
  * **Perolehan tiket (idempoten):** seorang upline menerima **tepat 1 tiket** ketika downline **L1**-nya melakukan pembelian **produk non-trial untuk PERTAMA kali**. Maksimal **1 tiket per downline seumur hidup** — dijamin `UNIQUE uk_spin_grant_downline (downline_id)` **plus** gate "pembelian berbayar pertama" **plus** pre-check terserialisasi di dalam TX checkout. Pembelian kedua dan seterusnya, pembelian **trial gratis** (`gpu_products.is_trial = 1`), dan kontrak reward promotor (`source='promoter_reward'`) **tidak** menghasilkan tiket. Upline **tidak wajib aktif** (tiket = insentif engagement, bukan komisi) — hanya upline hilang/`is_banned` yang dilewati (no-op + log).
  * **Tanpa backfill (D4):** tiket mulai dihitung sejak rilis; pembelian yang terjadi sebelum plan/121 tidak menghasilkan tiket retroaktif.
  * **Roda & hadiah:** roda berisi 2–12 segmen uang tunai (nominal ≥ Rp 1 — **tidak ada segmen Rp 0**), masing-masing berbobot relatif. Hadiah dikreditkan ke **Saldo Utama** (`wallet_type = 'main'`, dapat ditarik) lewat `Wallet_model::credit()` dengan `transaction_id = 'SPIN-{spin_results.id}'`; deskripsi ledger `Hadiah Lucky Spin` dirender idiom aktif (`ledger_spin`).
  * **Zero-trust (SP4/SP5):** pemilihan segmen **seluruhnya di server** — `random_int()` (CSPRNG) + `spin_wheel_pick()` (fungsi murni berbobot). Klien hanya menerima `slot_index` untuk animasi; hadiah sudah ditentukan & dibayar sebelum respons dikirim, sehingga request yang dimanipulasi tidak dapat mengubah hasil.
  * **Kontrak endpoint:** `GET /lucky-spin` (halaman) + `POST /lucky-spin/spin` (POST + AJAX-only, `show_404()` untuk GET/non-AJAX), rate limit `spin_play:{user_id}` **5 / 60 detik** → **429**; respons lewat `api_helper` + key legacy: sukses `ok` (200, + `slot_index`/`prize`/`tickets_left`/`new_balance`/`summary`) · `no_tickets` (200) · `user_unavailable` (200) · `disabled` (**403**) · `not_configured` (**403**) · `error` (500).
  * **Tiket bukan uang (SP6):** `users.spin_tickets` adalah counter terpisah — tidak pernah menyentuh `wallet_ledger`, tidak dapat ditarik, dibeli, atau dipindahtangankan; otoritasnya = Σ`spin_ticket_grants` − Σ`spin_results`.
  * **Konfigurasi admin:** `/admin/lucky-spin` (toggle + editor segmen nominal/bobot + peluang & expected value per tiket + 20 putaran terakhir), persist atomik `Admin_model::update_system_settings()` dengan audit `admin_update_spin_wheel` (`keys`/`before`/`after`).

### H. Interactive Notification System (v5.0, disinkronkan plan/103 + plan/111)

A DB-driven, AJAX-powered notification system that delivers alerts for commissions, system messages, and account events — without requiring page reloads. **Notifikasi bersifat keyed** (`user_notifications.title_key` + `params`) dan dirender dalam **idiom pembaca** (EN/ID) oleh `i18n_notification_text()`; kolom `title`/`message` dipertahankan sebagai retensi + fallback baris legacy.

* **Database:** `user_notifications` table (see ERD v5.6 §5). Stores notification records per-user with `is_read` state + `title_key`/`params` (plan/103).
* **Bell Icon (Global Header):**
    * Rendered in `header.php` inside the sticky top bar — **satu dari tiga grup header** (Brand · Balance Pill · Notification Bell — plan/100).
    * Container: `<button id="notif-bell" class="relative p-2 rounded-full ...">` with FontAwesome `fa-bell`.
    * **Red Badge (Unread Count):** `<span id="notif-badge" class="... bg-red-500 rounded-full hidden">` — hidden when count is 0, shown with count when > 0. Nilai awal **server-rendered** dari `$global_unread_count` (di-inject `MY_Controller`), bukan diambil via endpoint polling terpisah. Badge menampilkan `N` bila ≤ 99, selain itu `"99+"`.
* **Dropdown Popover:**
    * On bell click, toggle dropdown below the header bar (`toggleNotifDropdown()`).
    * Container: `<div id="notif-dropdown" class="hidden absolute right-0 top-full mt-2 w-80 max-h-96 ...">`.
    * **Header:** judul "Notifikasi" + aksi "Tandai semua dibaca" (`#notif-mark-read-btn`).
    * **Notification List:** scrollable; item dirender server-side dari `$global_notifications`. Styling unread vs read, ikon per `type` (`info`/`warning`/`success`/`commission`), judul, pesan (truncate 2 baris), dan timestamp.
    * **Empty State:** "Tidak ada notifikasi."
* **AJAX State Management (kontrak aktual):**
    * **Auto mark-all-read on open:** membuka dropdown saat ada unread → `POST /notification/mark_all_read` (melalui `csrfFetch`), badge & tombol disembunyikan saat sukses.
    * **Manual "Tandai semua dibaca":** `markAllRead()` → `POST /notification/mark_all_read`.
    * **Mark-read individual:** `POST /user/read_notifications` (`User::read_notifications` → `Notification_model::mark_read`).
    * **Riwayat lengkap:** halaman `GET /notification` (paginated, `Notification::index`).
    * Show/Hide badge & list memakai **server-rendered state**; tidak ada polling timer untuk dropdown. Semua panggilan AJAX memakai Vanilla JS `fetch()`/`csrfFetch()` — **tanpa** jQuery, tanpa reload halaman. Setiap endpoint JSON memakai amplop `api_success()`/`api_error()` (M9/P7).
* **Backend Endpoints (CI3) — yang benar-benar ada:**
    * `GET /notification` → halaman riwayat notifikasi (paginated).
    * `POST /notification/mark_all_read` → tandai semua notifikasi user sebagai dibaca (JSON `api_success`/`api_error`).
    * `POST /user/read_notifications` → tandai notifikasi user sebagai dibaca (JSON envelope).
    * `$global_unread_count` + `$global_notifications` di-inject oleh `MY_Controller` pada setiap request terautentikasi (tanpa endpoint terpisah).
* **Notification Generation (keyed, plan/103):**
    * **Klaim Bonus Pencapaian Tim** / klaim promo → `type: 'commission'` (mis. `notif_milestone_*`/`promoter_claim_*`). `notif_bonus_l1_*` **hanya** untuk baris historis Misi Level 1 (plan/120) dan `notif_wage_*` **hanya** untuk baris historis Gaji Mingguan (plan/119) — key keduanya tetap ada, tidak lagi dipakai kode baru.
    * Deposit di-approve/decline → `type: 'success'`/`'warning'` (mis. `notif_deposit_approved`).
    * Withdrawal di-approve/decline → `type: 'success'`/`'warning'` (`notif_wd_approved` — arity 3: gross, fee, net; `notif_wd_declined`).
    * Reset e-wallet oleh admin → `notif_ewallet_reset` (dwibahasa).
    * **Lucky Spin (plan/121)** → `notif_spin_ticket_*` (`type: 'info'`, saat upline menerima tiket) dan `notif_spin_win_*` (`type: 'commission'`, saat hadiah dikreditkan ke Saldo Utama).
    * Pengumuman sistem → `type: 'info'`.

> **Ghost dihapus (plan/111):** empat endpoint fiktif pada PRD v5.0
> (`GET /notifications/unread-count`, `GET /notifications/list`,
> `POST /notifications/mark-read/{id}`, `POST /notifications/mark-all-read`)
> **tidak pernah ada** di kode dan telah diganti dengan kontrak di atas. Jangan
> menambahkannya kembali tanpa pekerjaan pengembangan terpisah.

---

## 5. UI/UX & Frontend Guidelines
* **Visual Murni:** 100% menggunakan Tailwind CSS via CDN atau Build. TIDAK BOLEH menggunakan Bootstrap.
* **Interaktivitas:** Menggunakan Vanilla JavaScript. Semua *pop-up* (konfirmasi *withdraw*, *alert* sukses/gagal, *modal* ubah profil, *Bottom Sheet Checkout*, *Notification Dropdown*) dirender tanpa *reload* halaman. E-wallet selector (plan/106) dan sebagian kontrol lain memakai **CSS murni** (`peer-checked:`) tanpa JS.
* **Navigasi:** *Bottom Navigation Bar* persisten di 5 halaman utama (Home, Sewa Saya, Bantuan, Marketplace, Profil). Header member memuat **tepat tiga grup** — Brand · Balance Pill · Notification Bell (plan/100); kontrol Bahasa & Tema berada di kartu "App Preferences" halaman Profil. Titik masuk **Lucky Spin** (plan/121) ada di dashboard (`/home`): kartu inline + **FAB** — keduanya bukan grup header dan bukan tab bottom-nav.
* **Z-Index Layering:** Bottom Navigation sits at `z-50`. All Bottom Sheet Modals and Notification Dropdowns MUST be `z-[60]` to render above the nav bar. **FAB dashboard (Lucky Spin, plan/121)** berada pada lapisan `inset-x-0 bottom-20 z-40` — di atas konten, **di bawah** bottom-nav `z-50` dan toast `z-[60]` (lihat `docs/4_UI_UX_GUIDELINES.md` §2 & §5.J).
* **Financial Display:** All IDR values formatted via `Intl.NumberFormat('id-ID')` in JavaScript or `number_format($val, 0, ',', '.')` in PHP with `Rp ` prefix. **Uang tidak pernah dilokalisasi i18n** (L6). **Standar NET (plan/109):** tampilkan **NET** sebagai nilai primer dengan gross/fee sebagai sub-teks (lihat `docs/4_UI_UX_GUIDELINES.md` §5.H).
* **Form Visibility:** Secondary input forms (Top-Up amount selection, custom amount input) are hidden by default (`hidden` class) and toggled via a primary action button to conserve screen real estate.
* **Detail lengkap** komponen, palet, tipografi, dan z-index ada di `docs/4_UI_UX_GUIDELINES.md` (v5.3) — dokumen ini hanya menetapkan prinsip.

---

## 6. Security & OpSec Requirements
* **CSRF Protection:** Wajib diaktifkan di konfigurasi CI3 (`$config['csrf_protection'] = TRUE;`).
* **Bot Protection:** Native SVG CAPTCHA on login & registration (M8/plan/72) — 5-char unambiguous alphabet (no `0/O/o/1/I/l`), inline transparent SVG (indigo/cyan/violet, rotation+jitter+noise), case-insensitive match, strict single-use session flush, 180s TTL, AJAX refresh endpoint `auth/refresh_captcha`. No external CAPTCHA service, no CAPTCHA keys.
* **Rate Limiting / Mutex Lock:** Halaman eksekusi finansial (Tombol Beli Sewa, Tombol Tarik Dana) wajib memiliki *lock* atau *disable state* pada JavaScript dan divalidasi di PHP agar tidak terjadi *Double Spending* jika *user* melakukan klik dua kali dengan cepat.
* **Phone Sanitization:** Backend **normalisasi** (strip non-digit, `62`→`0`, pastikan awalan `0`) + keunikan `is_unique[users.phone]`/`uk_phone` pada ALL phone inputs (register, login, profile update, admin user creation). Nomor HP **e-wallet** memakai aturan panjang kanonik terpisah `^08[0-9]{8,11}$` (`ewallet_helper.php`). Frontend is helper-only — never the source of truth. *(plan/111: regex panjang `/^0[0-9]{9,13}$/` yang diklaim lama tidak ada di kode — lihat §4.A.)*
* **Data Masking:** Nomor HP e-wallet yang tampil di antarmuka (member & admin) disensor sebagian via `ewallet_phone_mask()` (mis. `0812*****01`). Audit `admin_reset_ewallet` menyimpan nomor dalam bentuk **ter-mask** (PII minimisation).
* **Maintenance Mode (plan/95):** Gerbang `maintenance_gate()` (helper `maintenance_helper.php`) dieksekusi sebagai **statement pertama** di `MY_Controller`, `Auth`, dan `Lang`. Saat aktif: HTML → halaman `503` standalone dwibahasa; JSON/AJAX → `api_error(..., 503, [], 'MAINTENANCE_MODE')`. Bypass ketat: rute admin/`control-panel`, session `admin_id`, dan `is_cli()`. Toggle: `POST /admin/toggle-maintenance` (audit atomik).
* **API Response Envelope (M9/P7 — plan/76–77):** Setiap endpoint AJAX/JSON merespons melalui `api_helper.php` (`api_success()`/`api_error()`); error/404 AJAX dinormalisasi menjadi JSON bersih oleh `MY_Exceptions.php` (tidak pernah HTML).
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
- Real-time alert center (plan/94 F2): `GET /admin/alerts/poll` (polling 25 detik, `Cache-Control: no-store`, **tanpa** audit row) mengembalikan `Admin_model::get_alert_counts()` (`pending_deposits` = `pending + waiting_approval`, `pending_withdrawals`, `pending_promoter_claims`, `total_urgent`).
- Two-column grid layout:
  - **Left:** Pending Deposits queue — display invoice number, phone (masked), **kode unik + status**, amount (IDR), timestamp, APPROVE/**DECLINE** button
  - **Right:** Pending Withdrawals queue — display WD number, phone (masked), **provider e-wallet + nomor HP e-wallet ter-mask**, **NET sebagai nilai primer** (`Wajib Transfer (Net)`) + sub-teks gross/fee, timestamp, APPROVE/DECLINE buttons
- Empty state: ∅ icon + "No pending deposits/withdrawals"
- System info footer: version + timestamp

### C. Queue Operations (ACID-Compliant)
- **Deposit Approval:** Transaction wraps deposit status update (`pending`/`waiting_approval` → `success`), release `reserved_code_key`, dan `wallet_ledger` credit insert (kredit = pokok + kode; fee deposit ditahan platform). **Deposit Decline** (`Admin_model::decline_deposit()`, plan/102) menyetel `rejected` + `decline_reason`.
- **Withdrawal Approval:** Transaction updates withdrawal status to `success` (funds already debited at request time — gross penuh di `wallet_ledger`).
- **Withdrawal Decline (Auto-Rollback):** Transaction updates status to `failed` + inserts `credit` refund record to `wallet_ledger` — funds restored atomically.
- **Semua mutasi** menulis baris `system_audit_logs` atomik dalam TX yang sama (M5/A1); flip status selalu **kondisional** (`WHERE status=…` + `affected_rows()===1`, M4).

### D. Advanced User Management (v5.0)

Admin has two privileged user-management operations accessible from the Command Center.

#### D.1 Create User
* **Route:** `POST /admin/create_user` (form di `views/admin/users.php`).
* **Purpose:** Admin dapat membuat akun user secara manual — melewati alur registrasi publik.
* **Backend Logic (`Admin::create_user()` — POST-only, plan/62 M4):**
    1. **Normalisasi lebih dulu** (M5/plan/67): `_normalize_phone()` (strip non-digit, `62`→`0`) lalu tulis kembali ke `$_POST['phone']`.
    2. Validate input: `phone` (`required|trim|is_unique[users.phone]` + pesan Indonesia), `password` (`required|min_length[8]`).
    3. Auto-generate `invite_code` (6 karakter alfanumerik, unik, via `Admin_model::generate_invite_code()`).
    4. **Upline opsional:** field `upline_invite_code`. Kosong → `parent_id = NULL` (root node); terisi → diresolusi (`Admin_model::resolve_upline()`), tidak ditemukan → error flash.
    5. Hash password dengan `password_hash($password, PASSWORD_DEFAULT)`.
    6. Insert ke `users` **atomik** dengan audit `admin_create_user`; jalur duplikat `uk_phone` (errno 1062) ditangani defensif (tanpa baris audit untuk no-op).
* **Response:** Flash sukses/gagal + redirect ke `admin/users`.
* **Constraint:** Tanpa `upline_invite_code`, user dibuat sebagai **root node** (bukan bagian pohon agensi) sampai ia memperoleh downline.

#### D.2 Force Reset Password
* **Route:** `POST /admin/reset_password/{user_id}` (form di `views/admin/user_detail.php`).
* **Purpose:** Admin memaksa ganti sandi user (locked out / akun terkompromi).
* **Backend Logic (`Admin::reset_password($user_id)` — POST-only, plan/62 M4):**
    1. Validasi user ada (`Admin_model::user_exists()`); tidak ada → flash error + redirect.
    2. Validate input: `new_password` (`required|min_length[8]`) — admin **mengetik sandi baru** (kode **tidak** men-generate sandi acak dan **tidak** menampilkan plaintext).
    3. Hash dengan `password_hash($new_password, PASSWORD_DEFAULT)`.
    4. **Atomik:** `Admin_model::force_reset_password()` + audit `admin_reset_password` (`{ user_id }`) dalam satu TX; plaintext **tidak pernah** dicatat.
* **Response:** Flash "Kata sandi berhasil di-reset." + redirect.
* **Constraint:** User dipaksa ganti sandi saat login berikutnya via kolom `users.must_change_password` (`TINYINT(1) DEFAULT 0`, sudah ada). Ketika flag `1`, `MY_Controller` mengalihkan seluruh request ke `/auth/change-password` hingga sandi diperbarui.

### E. Lucky Spin Configuration (plan/121)

Halaman **`GET|POST /admin/lucky-spin`** (`Admin::lucky_spin()` + `views/admin/lucky_spin.php`, entri sidebar **Lucky Spin**) — 100% Bahasa Indonesia (L1), satu form ber-CSRF.

* **Strip ringkasan (read-only):** tiket beredar (Σ`users.spin_tickets`), tiket diperoleh, jumlah putaran, dan total hadiah dibayarkan (`Admin_model::get_spin_overview()`).
* **Konfigurasi roda:** toggle `spin_wheel_enabled` (**fail-closed**) + editor baris segmen `spin_amount[]`/`spin_weight[]` (2–12 segmen, tiap nominal ≥ Rp 1 — **tidak ada segmen Rp 0**), dengan **peluang per baris** dan **expected value per tiket** dihitung ulang **live** di klien (aritmetika integer).
* **Validasi & audit:** all-or-nothing (pesan Indonesia per baris) lewat `Spin_model::validate_settings()` + ambang `spin_wheel_helper.php`; persist atomik `Admin_model::update_system_settings()` → audit `admin_update_spin_wheel` (`keys`/`before`/`after`).
* **Tabel 20 putaran terakhir** (member, segmen, hadiah, waktu WIB) sebagai bukti audit visual — riwayat **immutable** (snapshot bobot per putaran).

---

## 8. Notification System Infrastructure (v5.0)

This section cross-references the Interactive Notification System (§4.H) and provides the architectural overview.

* **DB Table:** `user_notifications` — see ERD v5.6 §5 (`title_key` + `params` untuk i18n keyed, plan/103).
* **State Injection:** `MY_Controller` meng-inject `$global_unread_count` + `$global_notifications` pada **setiap** request terautentikasi (server-rendered). Tidak ada polling timer untuk badge/dropdown; interaksi AJAX hanya untuk **mark-read** (`POST /notification/mark_all_read`, `POST /user/read_notifications`).
* **Security:** All notification endpoints require authenticated user session (`user_id`). Users can only read/mark their own notifications — `WHERE user_id = session.user_id`.
* **Performance:** Composite index `idx_user_read` (user_id, is_read) mempercepat unread count. Riwayat lengkap dipaginasi di `GET /notification` (dropdown menampilkan subset terbaru).
* **Notification Lifecycle:**
    1. Backend insert notifikasi (via `Notification_model`, umumnya ber-`title_key` + `params`) — seringkali **atomik dalam TX** mutasi terkait (M5).
    2. Nilai unread dihitung saat render halaman berikutnya (atau refresh); badge tersinkron.
    3. User membuka dropdown / klik "Tandai semua dibaca" → mark-read → badge hilang.
    4. Tidak ada TTL/expiry — notifikasi persist di DB (kolom legacy `title`/`message` dipertahankan sebagai retensi).

> **Ghost dihapus (plan/111):** klaim lama "AJAX polling interval 60 detik" dan
> endpoint `/notifications/*` tidak ada di kode; badge bersifat server-rendered.

