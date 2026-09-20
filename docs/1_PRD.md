# Product Requirements Document (PRD) v5.1
**Project Name:** Synapse (AI GPU Rental)
**Platform:** Web Application (100% Mobile-First / SPA-like Experience)
**Core Tech Stack:** CodeIgniter 3 (CI3), PHP 8.3.6, MySQL/MariaDB, Vanilla JavaScript, Tailwind CSS

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
Synapse adalah platform investasi dan penyewaan daya komputasi awan (GPUaaS). Pengguna dapat menyewa *node* GPU dengan durasi tertentu (Short-Term & Long-Term) untuk mendapatkan *Return of Investment* (ROI) berupa **pendapatan harian yang diklaim manual** (bukan auto-credit). Seluruh pencatatan uang memakai **satu ledger otoritatif: `wallet_ledger`** (append-only; tabel `transactions` double-entry telah **didecommission** pada M6). Sistem ini dilengkapi dengan sistem afiliasi (*Agency*) multi-level berjenjang dengan *reward* pencapaian (klaim manual) dan **gaji mingguan yang diklaim manual dengan cooldown 7 hari** (tidak ada cron di repo). Pencairan dana bersifat **e-wallet only** (DANA, ShopeePay, OVO, GoPay — katalog dinamis yang dikelola admin).

> **Catatan operasional (tak ada cron).** Tidak ada pekerja otomatis di latar
> belakang: ROI harian = klaim manual (`Rental_model::claim_roi`, T+1), expiry
> sewa = lazy sweep per-request (`MY_Controller` → `Rental_model::expire_user_rentals()`)
> plus alat manual admin/CLI, expiry deposit = lazy sweep (`Wallet_model::expire_user_deposits()`),
> dan wage = klaim manual dengan cooldown.

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
* **User (Authenticated):** Memiliki dompet (*wallet*), dapat menyewa GPU, menarik dana **hanya ke akun e-wallet terikat**, dan memiliki *referral code*/link (`/register?ref=CODE`) untuk membangun tim/agensi. Pendapatan harian dan gaji mingguan **diklaim manual** oleh user.
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
* **Available Balance** dihitung dinamis: `SUM(credit) − SUM(debit)` via `Wallet_model::get_balance()` (mengembalikan `(int)`). Saldo tidak pernah disimpan sebagai nilai statis untuk operasi dompet.
* `wallet_ledger` bersifat **append-only** — baris tidak pernah di-update atau di-delete setelah ditulis.


### C. Marketplace & One-Screen Checkout

The Marketplace page presents GPU product cards in a scrollable feed. Each card displays: product image, name, description, rental price (IDR), and daily ROI (IDR).

#### One-Screen Checkout (Bottom Sheet Modal)
When a user taps **"Sewa Sekarang"** on any product card, a **dynamic Vanilla JS Bottom Sheet Modal** slides up from the bottom of the screen — no page reload, no navigation.

The modal performs the following checks client-side:
1. Reads the user's `wallet_ledger` balance (passed from PHP as a JS variable at page load).
2. Compares `userBalance` against the product's `price`.
3. **If balance ≥ price:**
    * Displays product name, price, and current balance (all formatted as IDR via `Intl.NumberFormat`).
    * Renders a **"Konfirmasi & Bayar"** button that submits a POST form to `/rentals/checkout`.
    * Balance indicator turns **emerald** (`text-emerald-600`).
4. **If balance < price:**
    * Displays a **"Saldo Tidak Mencukupi — Isi Saldo"** link that redirects to `/wallet`.
    * Balance indicator turns **rose** (`text-rose-600`).

The modal overlay (`bg-black/60 backdrop-blur-sm`) closes on tap. The sheet animates via CSS `translate-y` transitions (300ms ease-out).

### D. Marketplace & Asset Rental (Sewa)
* **Katalog GPUaaS (Produk):**
    * Data produk dinamis dari tabel `gpu_products`, menampilkan: gambar produk, Nama Produk, Tipe (Short/Long Term), Harga Sewa (Rp), Durasi (Hari), Pendapatan Harian (Rp), dan Estimasi Total Penghasilan.
    * **Gambar produk (plan/104):** `gpu_products.image` menyimpan **basename** berkas di `uploads/products/`. Resolver tunggal `product_image_helper.php` (`product_image_url()`); `NULL`/berkas hilang → marketplace merender **fallback banner gradien** (tanpa gambar rusak). Unggah admin: allowlist `jpg|jpeg|png|webp`, maks 2048 KB, anti-orphan.
    * **Limit pembelian per user (plan/83):** `gpu_products.max_per_user` (`0` = tanpa batas) adalah satu-satunya gate kuota pembelian per-user. Produk nonaktif (`is_active = 0`) tidak muncul di marketplace.
* **Logika Transaksi Sewa (Checkout):**
    * Saat form dari Bottom Sheet Modal disubmit, `Rentals` controller memvalidasi saldo user server-side; bila `saldo < harga`, redirect dengan flash error.
    * Bila `saldo >= harga`, satu transaksi ACID (`trans_begin`/`trans_commit` dengan anchor `SELECT … FOR UPDATE` pada `users`) menjalankan:
        1. **Debit** saldo via satu-satunya choke-point uang `Wallet_model::debit()` → menulis baris `debit` ke **`wallet_ledger`** (bukan tabel `transactions` lama).
        2. **Insert** kontrak ke tabel **`user_rentals`** dengan `status = 'active'`, `source = 'purchase'`, `purchase_price`, `daily_roi` (snapshot `daily_rate`), `total_days`, dan `expired_at` (batas kedaluwarsa WIB).
        3. **Distribusi rebate 3-tier** (plan/89): upline aktif L1/L2/L3 menerima `credit` ber-`transaction_id` `RBT-{rental_id}-L{tier}` — semuanya lewat `Wallet_model::credit()` (jalur tunggal), di dalam TX yang sama (langkah 7 sebelum commit).
    * **Constraint:** seluruh mutasi dibungkus satu *Database Transaction* — kegagalan salah satu langkah membatalkan semuanya (tidak ada saldo minus / setengah kontrak). Model yang bertanggung jawab: `Rental_model` (checkout/claim/expiry/rebate) + `Wallet_model` (uang). **Tidak ada** SQL di controller/view.
    * **ROI harian = klaim manual** (`Rental_model::claim_roi`, gate `ROI-{rental_id}-D{n}`, T+1) — bukan auto-credit dan bukan cron.

### E. Financial Ledger & Wallet
* **Aturan Emas:** Saldo utama user dihitung dari mutasi tabel **`wallet_ledger`** (Total Credit − Total Debit) — **satu-satunya** ledger otoritatif. Tabel `transactions` double-entry telah **didecommission** (M6): jangan membuatnya kembali atau menulis ke sana.
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

**Tidak ada cron job di repo ini.** Seluruh "otomatisasi" bersifat **lazy/event-driven** pada request member, atau **dipicu manual** oleh admin/CLI (batasan owner). Tiga mesin utama:

* **Distribusi ROI Harian — KLAIM MANUAL:**
    * User mengklaim ROI per kontrak via `POST /rentals/claim/{id}` → `Rental_model::claim_roi()` (idempotensi `transaction_id = ROI-{rental_id}-D{n}`, gate T+1).
    * Credit ditulis ke **`wallet_ledger`** (bukan tabel `transactions` yang sudah didecommission). Jika `days_processed` mencapai `total_days`, kontrak ditutup menjadi `completed`.
* **Expiry Sewa — LAZY SWEEP:**
    * `MY_Controller` menjalankan `Rental_model::expire_user_rentals()` pada setiap request terautentikasi (setelah pin WIB M2 + i18n + guard login). Semua filter "kontrak aktif" memakai `expired_at > <PHP WIB bound>`, **bukan** `NOW()` MySQL.
    * Alat manual: admin `POST /admin/expire_expired_rentals` (dashboard "Tutup Sewa Kedaluwarsa") dan CLI `scripts/expire_rentals.php` (`--dry-run`/`--apply`).
* **Expiry Deposit — LAZY SWEEP:**
    * `MY_Controller` menjalankan `Wallet_model::expire_user_deposits()` (per-user, `pending` yang lewat `expires_at` → `expired`); alat global: `Wallet_model::expire_stale_deposits()` (entry admin/CLI).
    * **`waiting_approval` tidak pernah disentuh** oleh sweep (keputusan D1 plan/102).

> **Catatan migrasi dokumen:** bagian ini dahulu mendeskripsikan cron `00:01 WIB` untuk pendapatan harian dan cron Senin `01:00` untuk gaji. Keduanya **tidak ada** di kode — jangan dihidupkan kembali tanpa pekerjaan pengembangan terpisah.

### G. Affiliate & Agency System (MLM Structure)
* **Hierarki:** Sistem melacak keturunan langsung (Agen B, `parent_id`) dan seluruh keturunan di bawahnya (**recursive CTE** untuk penghitungan level wage). Definisi **Active Downline:** user *downline* yang minimal memiliki 1 kontrak `active` di `user_rentals` (dengan filter defensif `expired_at > now WIB`).
* **Agency Rules & Rewards (ground truth `User_model::WAGE_TIERS` + `LEVEL1_BONUS`; kode otoritatif — plan/80 P5):**
    * **Level 1:** Syarat **3 downline aktif B** (`parent_id` langsung) **DAN** total omset B ≥ **Rp 330.000**. Reward **Rp 80.000 sekali** (one-time). **Bukan B+C** — hitungan otoritatif hanya keturunan langsung.
    * **Level 2:** 9 downline aktif → Gaji **Rp 200.000 / minggu**
    * **Level 3:** 30 downline aktif → Gaji **Rp 1.000.000 / minggu**
    * **Level 4:** 70 downline aktif → Gaji **Rp 2.500.000 / minggu**
    * **Level 5:** 130 downline aktif → Gaji **Rp 5.000.000 / minggu**
    * **Level 6:** 190 downline aktif → Gaji **Rp 9.000.000 / minggu**
* **Zero-Trust Level 1 Bonus Claim (v5.0):**
    * The Level 1 Bonus (Rp 80.000 one-time) is claimed manually via the Team page "Klaim Bonus" button. Frontend button sends `POST /team/claim-level1`.
    * **Server-Side Validation (Zero-Trust):** The backend CANNOT trust frontend-passed counts. It MUST re-validate ALL conditions from scratch inside a single ACID transaction (`trans_begin` / `trans_commit`):
        1. **Re-query active downlines:** hitung downline **B** aktif langsung dan jumlah omset B (SUM `gpu_products.price` atas kontrak aktif downline B).
        2. **Check qualification:** `active_b ≥ 3` AND `sales_b ≥ 330000`.
        3. **Check idempotency:** flag `users.is_level_1_claimed` (bukan LIKE description).
        4. **Check balance race:** `SELECT ... FOR UPDATE` on `users` row to prevent concurrent claim.
        5. **Credit + Log:** Insert `credit` record into **`wallet_ledger`** (amount: 80000, `LEVEL1_BONUS`, jalur tunggal `Wallet_model::credit()`), tandai `is_level_1_claimed = 1`, notifikasi + audit atomik. **Tidak ada** insert ke `transactions` (tabel didecommission).
    * **Response:** amplop `api_success()` `{ success, message, data: { active_agents, total_sales, bonus_amount } }` — atau `api_error()` saat gagal.
    * **Failure Modes:** agen aktif belum mencukupi / total sales belum mencapai Rp 330.000 / bonus sudah diklaim / error transaksi → rollback penuh (pesan ter-i18n EN/ID via `localize_result()`).
* **Klaim Gaji Mingguan (Weekly Wage) — MANUAL, TANPA CRON:**
    * Wage **diklaim manual** oleh user via `/team/claim_wage` (bukan cron Senin `01:00`). Sistem menentukan level dari **jumlah seluruh downline aktif** (`determine_wage_level()`), lalu gate **cooldown 7 hari** (`check_wage_cooldown()` memakai `users.last_wage_claimed_at`).
    * Credit ditulis ke **`wallet_ledger`** dengan `FOR UPDATE` + cek race; stamp `last_wage_claimed_at` di-update atomik.
* **Rebate 3-Tier (plan/89):** Setiap pembelian kontrak berbayar mendistribusikan rebate ke upline aktif L1/L2/L3 (default **L1 5% · L2 3% · L3 1%**, dinamis via `system_settings` `rebate_enabled`/`rebate_l1_percent`/`rebate_l2_percent`/`rebate_l3_percent`; fallback `application/config/rebate_commission.php`). Distribusi berjalan **di dalam TX checkout** (`_distribute_rebate`, langkah 7 sebelum commit) lewat jalur tunggal `Wallet_model::credit()` dengan `transaction_id = RBT-{rental_id}-L{tier}`. Upline nonaktif/banned = breakage (tanpa pass-up).
* **Referral Gating (plan/89–92):** Kode/link/QR referral disembunyikan di DOM sampai kriteria sewa terpenuhi (Condition A/B/C), **kecuali** user ber-flag promotor (`is_promoter = 1`, admin-only).
* **Program Promotor (Omzet Burn, plan/91–92):** Promotor dapat menukar omzet L1 menjadi **kontrak reward zero-cost** (`source='promoter_reward'`, `purchase_price = 0`) melalui klaim `promoter_claims` yang di-approve admin. Burn = **bookkeeping murni** — **tidak pernah** menyentuh `wallet_ledger`; kuota kanal reward terpisah dari kuota pembelian berbayar (K4); rasio CAC dijaga 8–10% (K5).

### H. Interactive Notification System (v5.0, disinkronkan plan/103 + plan/111)

A DB-driven, AJAX-powered notification system that delivers alerts for commissions, system messages, and account events — without requiring page reloads. **Notifikasi bersifat keyed** (`user_notifications.title_key` + `params`) dan dirender dalam **idiom pembaca** (EN/ID) oleh `i18n_notification_text()`; kolom `title`/`message` dipertahankan sebagai retensi + fallback baris legacy.

* **Database:** `user_notifications` table (see ERD v5.1 §5). Stores notification records per-user with `is_read` state + `title_key`/`params` (plan/103).
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
    * Level 1 Bonus diklaim / klaim promo → `type: 'commission'` (mis. `notif_bonus`/`notif_wage_*`/`promoter_claim_*`).
    * Deposit di-approve/decline → `type: 'success'`/`'warning'` (mis. `notif_deposit_approved`).
    * Withdrawal di-approve/decline → `type: 'success'`/`'warning'` (`notif_wd_approved` — arity 3: gross, fee, net; `notif_wd_declined`).
    * Reset e-wallet oleh admin → `notif_ewallet_reset` (dwibahasa).
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
* **Navigasi:** *Bottom Navigation Bar* persisten di 5 halaman utama (Home, Sewa Saya, Bantuan, Marketplace, Profil). Header member memuat **tepat tiga grup** — Brand · Balance Pill · Notification Bell (plan/100); kontrol Bahasa & Tema berada di kartu "App Preferences" halaman Profil.
* **Z-Index Layering:** Bottom Navigation sits at `z-50`. All Bottom Sheet Modals and Notification Dropdowns MUST be `z-[60]` to render above the nav bar.
* **Financial Display:** All IDR values formatted via `Intl.NumberFormat('id-ID')` in JavaScript or `number_format($val, 0, ',', '.')` in PHP with `Rp ` prefix. **Uang tidak pernah dilokalisasi i18n** (L6). **Standar NET (plan/109):** tampilkan **NET** sebagai nilai primer dengan gross/fee sebagai sub-teks (lihat `docs/4_UI_UX_GUIDELINES.md` §5.H).
* **Form Visibility:** Secondary input forms (Top-Up amount selection, custom amount input) are hidden by default (`hidden` class) and toggled via a primary action button to conserve screen real estate.
* **Detail lengkap** komponen, palet, tipografi, dan z-index ada di `docs/4_UI_UX_GUIDELINES.md` (v5.1) — dokumen ini hanya menetapkan prinsip.

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

---

## 8. Notification System Infrastructure (v5.0)

This section cross-references the Interactive Notification System (§4.H) and provides the architectural overview.

* **DB Table:** `user_notifications` — see ERD v5.1 §5 (`title_key` + `params` untuk i18n keyed, plan/103).
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

