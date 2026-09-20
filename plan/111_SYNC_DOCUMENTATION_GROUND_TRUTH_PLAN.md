# Plan 111 — Sinkronisasi Dokumentasi dengan Ground Truth Kodebase

> **Status:** BLUEPRINT (dokumen perencanaan SAJA). **Belum ada satu pun baris
> `docs/` yang diubah.** Deliverable ronde ini = file ini saja
> (`plan/111_SYNC_DOCUMENTATION_GROUND_TRUTH_PLAN.md`). Eksekusi penyuntingan
> `docs/` **MENUNGGU APPROVAL pemilik repositori** (lihat §13).
>
> **Prinsip mengikat (AGENTS.md):** *"When a doc and the code disagree, **code is
> authoritative**."* Seluruh isi dokumen di bawah **diambil dari working tree**,
> bukan dari `docs/` yang ada. `docs/` adalah **target yang diperbaiki**,
> kode adalah **sumber kebenaran**.
>
> **Ruang lingkup:** 4 berkas dokumentasi inti — `docs/1_PRD.md`,
> `docs/2_ERD.md`, `docs/3_ROADMAP.md`, `docs/4_UI_UX_GUIDELINES.md`.
> **Zero-impact pada kode:** nol perubahan `application/`, `system/`, skema DB,
> route, migrasi, kamus i18n, atau helper. Ini pekerjaan **dokumen saja**.
>
> **Rentang drift yang disinkronkan:** plan/102 → plan/110.

---

## 0. Ringkasan Eksekutif & Keputusan yang Diminta

Tiga dokumen (`1_PRD.md`, `3_ROADMAP.md`, `4_UI_UX_GUIDELINES.md`) tertinggal
jauh di belakang kode; `2_ERD.md` **sebagian** sudah disinkronkan (plan/104–106)
tetapi **masih menyimpan skema `deposits` pra-plan/102** dan **tidak**
mendaftar key `qris_*`/`deposit_*` di tabel key `system_settings`.

| Dokumen | Kondisi saat ini | Tingkat drift |
|---|---|---|
| `docs/1_PRD.md` | v5.0 — deposit manual lama, **"Pengikatan Kartu Bank"**, tidak ada `?ref=CODE`, tidak ada NET display, wage tier §G salah | **BERAT** |
| `docs/2_ERD.md` | v5.0 — §0 Mermaid & §3 `deposits` masih pra-plan/102; §4 key `system_settings` tidak memuat `qris_*`/`deposit_*`/`is_maintenance_mode` | **SEDANG** (parsial sudah sinkron) |
| `docs/3_ROADMAP.md` | v6.0 — berhenti di plan/106; **tidak ada** entri plan/102, 103, 104, 107, 108, 109, 110 | **SEDANG** |
| `docs/4_UI_UX_GUIDELINES.md` | v5.0 — tidak ada card selector 2×2 e-wallet (plan/106), hierarki NET (plan/109), editor tier dinamis (plan/110) | **SEDANG** |

**Yang diminta dari pemilik repositori di §13:** (a) approve rencana ini,
(b) putuskan 4 temuan divergensi di §8 (terutama **LinkAja** — lihat §8.1).

---

## 1. Metodologi (bagaimana "kebenaran" diekstraksi)

Setiap klaim di §2–§7 dapat dilacak ke salah satu bukti berikut (dibaca langsung
dari working tree pada HEAD `ed91bf9`, working tree bersih):

| Sumber kebenaran | Perannya |
|---|---|
| `database.sql` | DDL kanonik + seed `system_settings` & `ewallet_providers` |
| `application/models/Wallet_model.php` | Deposit (plan/102), withdrawal gate (plan/106), fee tier (plan/110) |
| `application/models/Ewallet_model.php` | Katalog provider e-wallet (plan/106) |
| `application/models/User_model.php` | `WAGE_TIERS`, `LEVEL1_BONUS`, syarat agen |
| `application/helpers/{ewallet,withdrawal_amount,withdrawal_fee,referral,wa_group,product_image}_helper.php` | Choke-point aturan |
| `application/controllers/{Auth,Wallet,Admin}.php` | Alur `?ref=CODE`, deposit, editor tier |
| `application/views/wallet/{pay,bank_bind,withdraw,index}.php`, `views/admin/{settings,dashboard}.php` | Ground truth UI/UX |
| `application/config/{withdrawal_fees,rebate_commission}.php` | Fallback fail-safe |
| `plan/102..110_*_{PLAN,SUMMARY}.md` | Catatan perubahan resmi |
| `application/config/routes.php`, `scripts/*` | Route & migrasi aktual |

**Aturan penulisan ulang:** bila `docs/` menyebut sesuatu yang tidak ada di kode
(chip: `Ledger_model`, tabel `rentals`, tabel `transactions`, cron, "Kartu
Bank") → **dihapus atau ditulis ulang** agar identik dengan kode. Bila kode
memiliki sesuatu yang tidak ada di `docs/` → **ditambahkan**. Bila kode dan
`docs/` sama-sama menyebut angka → **angka kode menang**.

---

## 2. GROUND TRUTH TERVERIFIKASI (yang akan dipakai menulis ulang dokumen)

### 2.1 Deposit — Gateway QRIS Manual (plan/102)

| Fakta | Bukti |
|---|---|
| `deposits` punya `unique_code SMALLINT UNSIGNED NULL` (100–999, **permanen** sebagai jejak audit) | `database.sql:216` |
| `total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00` = **pokok + [fee] + kode**, DIBEKUKAN saat create (tidak dihitung ulang) | `database.sql:220`, `Wallet_model.php:852-864` |
| `reserved_code_key VARCHAR(24) NULL` + UNIQUE `uk_reserved_code_key` (`"{pokok}-{kode}"` selama reservasi hidup, `NULL` saat keluar dari `pending`/`waiting_approval`) | `database.sql:226,237` |
| `expires_at`, `confirmed_at`, `processed_at`, `decline_reason` | `database.sql:227-230` |
| ENUM status: `pending`,`waiting_approval`,`success`,`failed`,`rejected`,`expired` | `database.sql:231` |
| Index `idx_status_expires (status, expires_at)` (lazy sweep) | `database.sql:243` |
| Invoice = `INV-{YmdHis}-{user_id}-{6 hex CSPRNG}` (**bukan** `INV-{YmdHis}-{user_id}`) | `Wallet_model.php:855-856` |
| Satu deposit hidup per user (guard di dalam TX terkunci; `pending_exists`) | `Wallet_model.php:834-842` |
| Kode unik dialokasikan CSPRNG dari rentang **100–999** (tanpa modulo bias), retry 3× saat duplicate key | `Wallet_model.php:810-904`, `:916-927` |
| Jendela bayar = `system_settings.deposit_expiry_minutes` (default **60**, clamp 5–1440), `expires_at = now + expiry` | `Wallet_model.php:399-452`, `:857` |
| **Kredit = pokok + kode** (fee deposit DITAHAN platform) = **Option A** saat `deposit_fee_enabled='0'`; baris legacy → `amount`; kredit tak pernah > `total_amount` | `Wallet_model.php:1156-1174` |
| Transisi `pending → waiting_approval` via member ("Saya Sudah Transfer", `confirmed_at`); **tanpa unggah bukti** | `Wallet_model.php:964-996` |
| `waiting_approval` **tidak pernah** auto-expire (keputusan D1) | `Wallet_model.php:1002-1003, 1022-1023` |
| `pending → expired` oleh sweep lazy per-user (`MY_Controller`) + global (`expire_stale_deposits`), melepas reservasi kode | `Wallet_model.php:1006-1056` |
| Kebijakan deposit: `deposit_min_amount` 10000 / `deposit_max_amount` 50000000 / `deposit_expiry_minutes` 60 | `database.sql:391-393` |
| **TIDAK ADA gate hari/jam operasional untuk deposit** — yang ada hanya jendela bayar (`expires_at`) + verifikasi manual admin "pada jam kerja" (teks instruksi) | `Wallet_model.php:788-905` (tidak ada `operational_status`) |
| QRIS gagal-tertutup: `qris_image = ''` → `create_deposit` ditolak (`deposit_err_qris_unconfigured`) | `Wallet.php:186-187` |

### 2.2 Withdrawal — E-Wallet Eksklusif (plan/106)

| Fakta | Bukti |
|---|---|
| Tabel `ewallet_providers` (`id`, `code` UNIQUE `uk_ewallet_code`, `name`, `is_active`) | `database.sql:100-110` |
| Seed kanonik `INSERT IGNORE`: **DANA, SHOPEEPAY (ShopeePay), OVO, GOPAY (GoPay)** — **tidak ada LinkAja** | `database.sql:113-117` |
| Provider **tidak pernah** hard-delete; dinonaktifkan (`is_active=0`); minimal satu wajib aktif | `Ewallet_model.php` (guard D6), `docs/2_ERD.md:303` |
| `bank_accounts` struktur **dibekukan**: `bank_name` = nama provider (harus ada di katalog), `account_number` = nomor HP e-wallet, `account_holder` = nama pemilik, `is_primary` = **flag binding aktif** (1 terikat / 0 arsip) | `database.sql:119-141` |
| Nomor HP e-wallet kanonik `^08[0-9]{8,11}$` (62/0062→0, numerik murni, 10–13 digit) | `ewallet_helper.php` |
| Reset admin = **ARSIP**, bukan DELETE (FK `fk_withdrawals_bank` RESTRICT) | `docs/2_ERD.md:290`, plan/106 |
| Binding member pakai **card selector 2×2** provider aktif (CSS `peer-checked:`, tanpa JS) + input Nomor HP + Nama Pemilik | `views/wallet/bank_bind.php:132-140` |
| Gate penarikan: `no_ewallet` (ownership + binding aktif) diverifikasi ulang **di dalam TX terkunci** | `Wallet_model.php:1220-1230` |
| Window: hari `wd_operational_days` (1–6 default) + `wd_open_time`/`wd_close_time` 07:00–19:00 WIB, gate **pengajuan** saja | `Wallet_model.php:557-583` |
| Min/Max dari `system_settings` (`wd_min_amount` 100000 / `wd_max_amount` 50000000) — **dinamis**, berkas config hanya fail-safe | `application/config/withdrawal_fees.php:14-20` |
| Satu WD pending per user + 1 WD/hari (`has_pending_withdrawal`, `has_reached_daily_wd_limit`) | `Wallet_model.php:1265-1273` |
| Debit ledger = **gross penuh**; `withdrawals` menyimpan `amount` (=gross mirror), `gross_amount`, `fee_amount`, `net_amount` | `Wallet_model.php:1279-1299` |

### 2.3 Fee Tier Penarikan & Validasi Admin (plan/110)

| Fakta | Bukti |
|---|---|
| Tier **half-open** `[min, max)` kontigu penuh; nominal batas masuk tier lebih tinggi (lebih murah) | `withdrawal_fee_helper.php` header, `Wallet_model.php:505-531` |
| Fee = `floor(gross × bps / 10000) + fixed_fee` (integer, `intdiv`); skala bps `10% = 1000` | `withdrawal_fees.php` |
| Default seed tier: `[[100000,500000,1000],[500000,1000000,750],[1000000,2000000,650],[2000000,5000000,500],[5000000,10000000,400],[10000000,50000001,300]]`, `fixed_fee` 6500 | `database.sql:368-369` |
| Contoh kanonik: 500.000 → fee 44.000; 1.000.000 → 71.500; 5.000.000 → 206.500 | `withdrawal_fees.php:31-34` |
| **Endpoint turunan dinormalkan otomatis** (bukan hard reject): `rows[0].min ← wd_min_amount`, `rows[n-1].max ← max(…, wd_max_amount + 1)` → `notices[]` + audit `auto_adjusted` (amandemen plan/56 §2.3) | `withdrawal_fee_helper.php`, `Wallet_model::validate_financial_settings()` |
| Gap/overlap/`max ≤ min`/persen luar 0–100/tier kosong = **hard error** yang menyebut nomor baris + kedua nilai | `withdrawal_fee_helper.php` |
| Admin boleh menurunkan minimal (mis. Rp 50.000) tanpa mengedit baris tier manual | plan/110 §1.4 S1 |
| Transport: array `wd_tier_min[]`/`wd_tier_max[]`/`wd_tier_pct[]` (JSON legacy tetap diterima) | plan/110 §5.3 |
| Repopulasi form setelah gagal simpan (`flashdata('settings_form_state')`) | plan/110 §7 |
| **`docs/1_PRD.md:124` ("Minimum Rp 100.000") = konflik dokumen** dengan kemampuan admin menurunkan floor | plan/110 §12, `withdrawal_fees.php:14-20` (catatan Q1) |

### 2.4 Tampilan Nominal NET (plan/109)

| Fakta | Bukti |
|---|---|
| Standar: **NET = nilai primer**, **gross/fee = sub-teks muted** | plan/109 D1 |
| Antrean admin: label `Wajib Transfer (Net)` + `Rp {net}` + sub-teks `(Penarikan: Rp {gross} \| Biaya: Rp {fee})` | `views/admin/dashboard.php:275-308` |
| Riwayat admin: 3 kolom **Gross / Biaya / Net (ditransfer)** | `views/admin/history.php`, plan/109 §1 |
| Kartu member: `Estimasi Dana Diterima (Net)` primer + rincian gross/fee | `views/wallet/index.php:247-253` |
| Preview form: 3 baris eksplisit — Gross (`#wd_gross`) / Fee (`#wd_fee`) / Net (`#wd_net`, `text-sm`, lebih besar) | `views/wallet/withdraw.php:105-121` |
| Notifikasi `notif_wd_approved` arity **3** (`gross, fee, net`) dengan guard arity `%` specifier | plan/109 §2.2, `i18n_helper.php` |
| **`wallet_ledger` TIDAK berubah** — debit tetap merekam gross penuh | plan/109 D2 |
| Helper choke-point: `withdrawal_amount_parts()` / `withdrawal_amount_decorate()` → `gross_eff`/`fee_eff`/`net_eff` | `withdrawal_amount_helper.php` |

### 2.5 Auto-fill Referral via URL (plan/108)

| Fakta | Bukti |
|---|---|
| `GET /register?ref=CODE` → sanitize → persist session + cookie → prefill field | `Auth::_referral_prefill()` `Auth.php:112-143` |
| Choke-point: `referral_code_normalize()` (strip non-`[0-9A-Za-z]`, `strtoupper`, `substr(…,0,6)`), `referral_code_is_valid()` (`/^[0-9A-Z]{6}$/`), `referral_code_resolve()` (URL menang atas stored), `referral_capture_key()` = `'referral_code'`, `referral_capture_ttl()` = **2592000 s (30 hari)** | `referral_helper.php` |
| Clamp 6 = **batas atas**, bukan syarat panjang (`?ref=ab` → `AB` → ditolak `auth_err_invite_invalid` saat submit) | plan/108 §5.2 |
| Prioritas nilai field: **POST → URL ref → session → cookie → `''`** | `register.php:413`, plan/108 §2.4 |
| Persist via `$this->load->vars(['invite_prefill' => …])` (memori view global, tersedia di semua cabang render) | `Auth.php:156` |
| Cleanup `unset_userdata` + `delete_cookie` sebelum `redirect('login')` | `Auth.php:240-250` |
| `Team.php` tetap `base_url('register?ref=' . $user->invite_code)`; Home & Profile **tidak** diubah | `Team.php:71-74`, plan/108 D2 |

### 2.6 Wage / Agency (koreksi §G PRD — plan/80 P5)

| Fakta | Bukti | Status vs `docs/1_PRD.md` §G |
|---|---|---|
| `WAGE_TIERS`: L2 `9 → Rp 200.000`; L3 `30 → Rp 1.000.000`; L4 `70 → Rp 2.500.000`; L5 `130 → Rp 5.000.000`; L6 `190 → Rp 9.000.000` | `User_model.php:110-116` | PRD salah untuk L3/L4/L5 (PRD: 18/40/90 & 500rb/1,5jt/4jt) |
| Bonus L1 = `LEVEL1_BONUS` **Rp 80.000 sekali**, syarat `b_active ≥ 3` **DAN** `b_sales ≥ 330000` (**B-tier saja**, bukan B+C) | `User_model.php:105, 239-242` | PRD menyebut "Agen B+C" → **salah** |
| Wage **diklaim manual** dengan cooldown 7 hari (`check_wage_cooldown`) — **bukan** cron | `User_model.php:197-227` | PRD §G menyebut "Cron Job setiap Senin 01:00" → **salah** |
| ROI harian juga **klaim manual** (`Rental_model::claim_roi`), expiry **lazy** tanpa cron | `plan/60`, `MY_Controller` | PRD §4.F "Cron Jobs" → **tidak ada cron di repo** |

### 2.7 Key `system_settings` (untuk ERD §4)

Sudah ter-seed (`database.sql:359-393`): `is_registration_open`, `is_maintenance_mode`,
`wd_operational_days`, `wd_open_time`, `wd_close_time`, `wd_fixed_fee`,
`wd_fee_tiers`, `wd_min_amount`, `wd_max_amount`, `deposit_fee_enabled`,
`deposit_fee_type`, `deposit_fee_value`, `wa_number`, `support_email`,
`wa_group_link`, `rebate_enabled`, `rebate_l1_percent`, `rebate_l2_percent`,
`rebate_l3_percent`, **`qris_image`**, **`qris_merchant_name`**,
**`qris_payment_instructions`**, **`deposit_expiry_minutes`**,
**`deposit_min_amount`**, **`deposit_max_amount`**.

---

## 3. RENCANA PERUBAHAN — `docs/1_PRD.md` (PALING BERAT)

**Versi:** `v5.0` → **`v5.1`** (lihat §13.2 — perlu approval). Header tech stack
`PHP 8.1` → **`PHP 8.3.6`**.

### 3.1 Ringkasan bedah per-section

| § PRD | Judul saat ini | Aksi | Inti perubahan |
|---|---|---|---|
| §1 (L9) | Product Overview | **REWRITE kalimat** | Hapus *"akuntansi double-entry ledger"* → **single authoritative ledger `wallet_ledger`**; hapus *"gaji mingguan otomatis"* → **klaim manual + cooldown 7 hari**; sebut e-wallet-only payout |
| §2 (L13-23) | Currency Standard | **KEEP** (koreksi kecil) | Tambah catatan M8: saldo & aritmetika uang = **integer IDR** (`(int)` choke-point, `intdiv()`), `DECIMAL(15,2)` hanya untuk kompatibilitas DB |
| §3 (L27-33) | Roles & Permissions | **REWRITE butir User & System** | User: **tanpa** "reference bank", hanya e-wallet; hapus *"System/Cron: pembagian hasil harian & gaji mingguan"* → **tidak ada cron** (lazy/klaim manual) |
| §4.A (L44-47) | Register | **REWRITE + TAMBAH** | Invoice/kode: tambah **auto-fill `?ref=CODE`** + persist session & **cookie 30 hari** + prioritas POST→URL→session→cookie → §3.3 |
| §4.B (L68-85) | Wallet Two-Tier + Deposit | **REWRITE TOTAL** | Ganti seluruh skema deposit lama dengan QRIS manual plan/102 → §3.2 |
| §4.C (L87-105) | Marketplace & One-Screen Checkout | **PARTIAL** | Perbarui endpoint `/rentals/create` sesuai kode & sebut `max_per_user` (plan/83); hapus referensi `Ledger_model` bila ada |
| §4.D (L107-117) | Rental (Sewa) | **REWRITE butir transaksi** | `Ledger_model->insert_transaction()` + tabel `rentals` → **`Wallet_model`/`Rental_model` + `user_rentals` + `wallet_ledger`**; tambah rebate 3-tier dipanggil di dalam TX checkout |
| §4.E (L119-139) | Financial Ledger & Withdrawal | **REWRITE TOTAL** | Ganti "Pengikatan Kartu Bank" dengan katalog e-wallet → §3.4; ganti tier fee statis dengan tier dinamis + NET display → §3.5 |
| §4.F (L141-146) | System Automation (Cron Jobs) | **REWRITE JUDUL & ISI** | Judul → *"Otomatisasi Lazy / Klaim Manual (Tanpa Cron)"*; ROI = klaim manual T+1; expiry lazy; sweep admin/CLI opsional |
| §4.G (L148-169) | Affiliate & Agency | **REWRITE angka & mekanisme** | Wage tier §G → sesuai `WAGE_TIERS`; hapus "B+C" → **B-tier**; hapus idempotensi `transactions` → `wallet_ledger` + `is_level_1_claimed`; ganti cron wage → klaim manual + cooldown 7 hari |
| §4.H (L171-208) | Notification System | **REWRITE endpoint & polling** | Ganti 4 endpoint fiktif (`/notifications/unread-count`, `/list`, `/mark-read/{id}`, `/mark-all-read`) dengan kontrak aktual (`/notification` + `POST /user/read_notifications` + `Notification_model`), interval polling aktual; tambah `title_key`+`params` (plan/103) → §3.7 |
| §5 (L212-218) | UI/UX & Frontend Guidelines | **PARTIAL** | Cross-ref ke `docs/4_UI_UX_GUIDELINES.md` yang diperbarui; tambah header 3 grup (plan/100) |
| §6 (L222-228) | Security & OpSec | **REWRITE butir masking** | *"Rekening bank disensor (1234*****789)"* → **nomor HP e-wallet ter-mask** via `ewallet_phone_mask()`; tambah maintenance gate (plan/95) + Arabic? tidak — tambah CSRF/toggle maintenance |
| §7.B (L250-251) | Dashboard queue | **PARTIAL** | Antrean deposit: tampilkan **invoice + nominal + kode unik + status**; antrean WD: tampilkan **NET** sebagai nilai primer + sub-teks gross/fee (plan/109) |
| §7.C (L255-258) | Queue Operations | **PARTIAL** | Tambah `Admin_model::decline_deposit()` (plan/102) ke daftar operasi; sebut ledger `wallet_ledger` |

### 3.2 §4.B — Penggantian lengkap "Wallet System (Two-Tier Architecture)"

Blok `#### Tier 1 — Pending Deposits` dan `#### Tier 2 — Immutable Ledger`
(L72-85) **diganti total** dengan sub-section berikut:

* **B.0 Kebijakan deposit (dinamis).** `deposit_min_amount` (10.000),
  `deposit_max_amount` (50.000.000), `deposit_expiry_minutes` (60, clamp 5–1440) —
  semua dari `system_settings` (fallback fail-safe `withdrawal_fees.php`).
* **B.1 `pending`** — membuat invoice: `INV-{YmdHis}-{user_id}-{6 hex}`;
  **kode unik 3 digit 100–999** dialokasikan CSPRNG (retry 3×); `total_amount`
  DIBEKUKAN = **pokok + [fee] + kode**; `reserved_code_key = "{pokok}-{kode}"`
  (UNIQUE — mencegah dua user membayar nominal + kode yang sama);
  `expires_at` = sekarang + `expiry_minutes`. **Satu deposit hidup per user.**
  Fail-closed bila `qris_image` kosong.
* **B.2 `waiting_approval`** — member menekan **"Saya Sudah Transfer"**
  (`POST wallet/confirm_payment/{invoice}`); **tanpa unggah bukti**;
  `confirmed_at` diisi; reservasi kode **tetap ditahan** dan **tidak pernah
  auto-expire** (keputusan D1).
* **B.3 `success`** — admin approve (antrean Command Center / simulator
  ENVIRONMENT-gated). Kredit = **pokok + kode**; **fee deposit ditahan platform**
  (`deposit_fee_enabled='0'` → kredit = `total_amount`, "Option A");
  `reserved_code_key` dilepas; satu baris `credit` `wallet_ledger`; notifikasi;
  audit.
* **B.4 Cabang terminal** — `failed` / `rejected` (admin decline +
  `decline_reason`) / `expired` (sweep lazy: `pending → expired`, kode dilepas &
  dapat dipakai ulang). **Catatan tegas:** `waiting_approval` **bukan** target
  expiry.
* **B.5 Jendela bayar & verifikasi.** Batas waktu = `expires_at` (server-based
  countdown + satu auto-reload di `views/wallet/pay.php`). **Tidak ada gate
  hari/jam untuk deposit** — verifikasi bersifat **manual oleh admin pada jam
  kerja** (teks instruksi), jadi jangan menulis jam operasional deposit sebagai
  gerbang keras.
* **B.6 Ledger.** `wallet_ledger` = **satu-satunya** ledger otoritatif
  (`SUM(credit) − SUM(debit)`); tabel `transactions` **decommissioned** (M6) —
  jangan pernah disebut sebagai aktif lagi.

### 3.3 §4.A — Auto-fill Referral Code via URL (plan/108)

**Tambahkan bullet baru** di bawah "Pendaftaran (Register)":

* `GET /register?ref=CODE` men-sanitasi (`referral_code_normalize()`:
  `[^0-9A-Za-z]` dibuang, uppercase, clamp 6 = batas atas), **mempersist** ke
  session + cookie `referral_code` (**TTL 30 hari / `2592000` s**), lalu
  mem-prefill field `invite_code`.
* Prioritas pengisian: **POST → URL ref → session → cookie → kosong**. Field
  tetap **editable** (`maxlength="6"`, tanpa `readonly`/`disabled`).
* Kode < 6 karakter (`?ref=ab`) sah ter-prefill sebagai `AB` tetapi **ditolak
  saat submit** oleh validasi kode undangan yang sudah ada.
* Cookie dihapus + session `unset` saat registrasi sukses.
* Tautan share resmi di halaman Team: `base_url('register?ref=' . invite_code)`.

### 3.4 §4.E — Ganti "Pengikatan Kartu Bank" dengan E-Wallet Catalog (plan/106)

Blok `**Pengikatan Bank:** User wajib menambahkan Kartu Bank (Nama Bank, Nomor
Rekening, Nama Pemegang)…` (L122) **diganti total**:

* **Tujuan penarikan = akun e-wallet saja.** User mengikat **satu** akun:
  **Provider** (dipilih dari katalog `ewallet_providers` **aktif**), **Nomor HP
  E-Wallet**, **Nama Pemilik Akun**.
* Katalog dinamis: seed kanonik **DANA, ShopeePay, OVO, GoPay**; admin dapat
  menambah/men-rename/menonaktifkan (`/admin/ewallet-providers`) — **tanpa hard
  delete**, minimal satu provider aktif.
* Validasi nomor: **`^08[0-9]{8,11}$`** (normalisasi `62`/`0062` → `0`; numerik
  murni; 10–13 digit) — choke-point `ewallet_helper.php`.
* Provider **nonaktif** → penarikan diblokir (`wd_err_ewallet_inactive`, arahkan
  ke binding ulang); binding lama **diarsipkan**, bukan dihapus.
* Reset/unbind oleh admin: kartu **Akun E-Wallet** di `/admin/user_detail/{id}`
  (nomor ter-mask, audit `admin_reset_ewallet`, notifikasi dwibahasa) — arsip,
  bukan DELETE (FK `fk_withdrawals_bank` RESTRICT).
* Gate penarikan memverifikasi ulang kepemilikan + status binding **di dalam
  transaksi terkunci** (defense-in-depth).
* Tampilan: nomor **di-mask** (mis. `0812*****01`) via `ewallet_phone_mask()`.

### 3.5 §4.E — Withdrawal: Net Display Standard (plan/109) + Tier Dinamis (plan/110)

Ganti blok `Batas Minimum & Maksimum` (L124) dan `Struktur Biaya Penarikan`
(L127-133):

* **Batas nominal DINAMIS** dari `system_settings` (`wd_min_amount`,
  `wd_max_amount`); **berkas `withdrawal_fees.php` = fail-safe saja**, bukan
  otoritas operasional. **Hapus klaim keras "Minimum Rp 100.000"** — admin dapat
  menurunkannya (mis. Rp 50.000) tanpa mengedit baris tier manual (plan/110 D1).
* **Tier half-open `[min, max)` kontigu penuh.** Nominal batas masuk tier lebih
  tinggi (lebih murah): 500.000 → 7,5% · 1.000.000 → 6,5% · 5.000.000 → 4% ·
  `fixed_fee` Rp 6.500. Fee = `floor(gross × bps / 10000) + fixed_fee` (integer).
* **Aturan tier kanonik (plan/110 §5.2):** ≥1 baris; `0 < min < max`;
  persen `0–100`; baris kontigu & menaik (gap/overlap = error keras yang
  menyebut nomor baris); **endpoint turunan dinormalkan otomatis** (baris 1 `min`
  ← `wd_min_amount`, baris terakhir `max` ← `max(…, wd_max_amount + 1)`) dan
  dilaporkan sebagai `notices` + audit `auto_adjusted`.
* **Net Amount Display Standard (plan/109):** seluruh surface menampilkan
  **NET sebagai nilai primer**, gross/fee sebagai sub-teks: antrean admin
  (`Wajib Transfer (Net)`), riwayat admin (3 kolom Gross/Biaya/Net), kartu
  member, preview form (3 baris), notifikasi approve (3 param). Debit ledger
  tetap **gross penuh** (ledger tidak pernah diubah).
* Gate frekuensi (1 WD/hari, satu WD pending) & window Sen–Sab **07:00–19:00 WIB**
  tetap (gate pengajuan). Auto-rollback decline tetap (refund `credit` +
  status `failed`, satu TX).
* Catatan: `withdrawals.status = 'processing'` adalah nilai ENUM **mati** (tidak
  pernah ditulis) — dokumentasikan sebagai cadangan, bukan state aktif.

### 3.6 §4.G — Koreksi Wage Tier (plan/80 P5) & mekanisme klaim

Tabel Agency Rules & Rewards (L151-157) **ditulis ulang** dari
`User_model::WAGE_TIERS` + `LEVEL1_BONUS`:

| Level | Syarat (downline aktif **B-tier**) | Reward |
|---|---|---|
| **1** | 3 downline aktif **B** **dan** omset B ≥ Rp 330.000 | **Rp 80.000 sekali** (klaim manual, idempotensi `is_level_1_claimed`) |
| **2** | 9 | Rp 200.000 / minggu |
| **3** | 30 | Rp 1.000.000 / minggu |
| **4** | 70 | Rp 2.500.000 / minggu |
| **5** | 130 | Rp 5.000.000 / minggu |
| **6** | 190 | Rp 9.000.000 / minggu |

* **Bukan** "B + C" — hitungan otoritatif adalah **downline aktif (B)** untuk
  syarat L1, dan **seluruh keturunan aktif** untuk penentuan level wage L2–L6.
* Wage **diklaim manual** (`/team/claim_wage`) dengan **cooldown 7 hari** —
  **bukan cron**. Bonus L1 diklaim manual (`/team/claim_level1`).
* Ledger: klaim menulis ke **`wallet_ledger`** (bukan `transactions`).
* Tambah **Rebate 3-tier** (plan/89): L1 5% / L2 3% / L3 1%, dinamis via
  `system_settings`, didistribusikan di dalam TX checkout (`RBT-{rental_id}-L{tier}`).

### 3.7 §4.H — Notification (temuan tambahan, lihat §8.4)

Endpoint di L199-203 **tidak ada di kode**. Kontrak aktual: halaman riwayat
`GET /notification` (`Notification::index`) + handler AJAX
`POST /user/read_notifications` (`User::read_notifications` →
`Notification_model::mark_read`) + badge global dari `MY_Controller`
(`global_unread_count`, `global_notifications`). Notifikasi kini
**keyed** (`title_key` + `params` JSON, plan/103) → teks diselesaikan via
`i18n_helper`.

---

## 4. RENCANA PERUBAHAN — `docs/2_ERD.md`

**Versi:** `v5.0` → **`v5.1`**. Header sinkronisasi diperbarui
(`plan/89–92` → `plan/89–110`).

### 4.1 §0 Diagram Mermaid

| Blok | Aksi |
|---|---|
| `deposits` (L109-117) | **REWRITE total** — tambah `unique_code`, `total_amount`, `reserved_code_key`, `expires_at`, `confirmed_at`, `processed_at`, `decline_reason`; ENUM status → `pending\|waiting_approval\|success\|failed\|rejected\|expired` |
| `gpu_products` (L35-48) | **TAMBAH kolom** `image VARCHAR255 "plan/104: basename, NULL = fallback"` |
| `system_settings` | Tidak ada perubahan entitas (key-value) — cukup komentar |
| **`ewallet_providers`** | **ENTITAS BARU** (`id`, `code` UK, `name`, `is_active`) |
| Header catatan (L12-14) | `13 tabel kanonik` → **`14 tabel kanonik`** |
| Relasi (L157-171) | Tambah komentar bahwa `bank_accounts.bank_name` ↔ `ewallet_providers.name` adalah relasi **logis by-name, bukan FK** |

Tidak ada FK baru di database (plan/106 sengaja menghindari perubahan struktur
`bank_accounts`); diagram harus tetap **jujur** soal itu.

### 4.2 §3 Financial — Tabel `deposits` (L250-263) — REWRITE TOTAL

Ganti seluruh spesifikasi kolom + lifecycle dengan:

* `id`, `user_id` (FK RESTRICT), `invoice_number` (UK, format
  `INV-{YmdHis}-{user_id}-{6 hex}`), `amount` (pokok).
* `unique_code SMALLINT UNSIGNED NULL` — kode 3 digit 100–999, **permanen**
  (jejak audit; tidak dibuang setelah deposit selesai).
* `total_amount DECIMAL(15,2) DEFAULT 0.00` — nominal bayar **dibekukan**
  (pokok + [fee] + kode); otoritatif untuk verifikasi admin **dan** nilai kredit.
* `reserved_code_key VARCHAR(24) NULL` + `UNIQUE uk_reserved_code_key` —
  `"{pokok}-{kode}"` selama reservasi hidup, `NULL` saat keluar dari
  `pending`/`waiting_approval`; semantik "banyak NULL" InnoDB = maksimal satu
  pemilik hidup per (pokok, kode).
* `expires_at`, `confirmed_at`, `processed_at`, `decline_reason`.
* `status ENUM('pending','waiting_approval','success','failed','rejected','expired')`.
* **Index:** `idx_user_status`, `idx_status_created`, `idx_status_expires`.
* **Lifecycle baru** (ganti L263): `pending` → (`waiting_approval` via konfirmasi
  member \| `expired` via sweep) → `success` (kredit **pokok + kode**; fee deposit
  ditahan platform) \| `rejected`/`failed` (decline admin + `decline_reason`).
  `waiting_approval` **tidak pernah** auto-expire.

### 4.3 §4 — Tabel `system_settings` (L332-356): tambah baris key

Tambahkan baris berikut ke tabel "Key ter-seed" (sudah ada di `database.sql:359-393`):

| Key | Default | Peran |
|---|---|---|
| `is_maintenance_mode` | `0` | **Plan 95:** maintenance mode member (default OFF bila baris hilang) |
| `qris_image` | `''` | **Plan 102:** basename gambar QRIS di `uploads/qris/`; `''` → deposit fail-closed |
| `qris_merchant_name` | `Synapse` | **Plan 102:** nama merchant |
| `qris_payment_instructions` | (teks) | **Plan 102:** instruksi pembayaran (dwibahasa, non-target i18n plan/103) |
| `deposit_expiry_minutes` | `60` | **Plan 102:** jendela bayar (clamp 5–1440) |
| `deposit_min_amount` | `10000` | **Plan 102:** batas bawah deposit |
| `deposit_max_amount` | `50000000` | **Plan 102:** batas atas deposit |

Dan perbarui deskripsi `wd_fee_tiers`/`wd_min_amount`/`wd_max_amount` (L346-347)
dengan catatan plan/110: tier **half-open kontigu**, **endpoint turunan
dinormalkan otomatis**, `withdrawal_fees.php` = fail-safe.

### 4.4 §7 — Invariant (tambahan opsional)

Tambah sub-blok *"Invariant plan/102–110"*: deposit code-uniqueness,
e-wallet-only payout, tier derived endpoints, NET display (ledger tidak diubah).

---

## 5. RENCANA PERUBAHAN — `docs/3_ROADMAP.md`

Versi `v6.0`; **tidak** bump versi utama (struktur tetap), tetapi sync header
opsional.

### 5.1 Sisipkan milestone baru **sebelum** `## Upcoming Phases` (L219)

Setelah entri plan/106 (L215), tambahkan 7 section `###` ber-format identik
dengan 6 section terakhir yang sudah ada (`- [x]` bullet + line refs + Ringkasan):

| # Section baru | Menutup | Sumber ringkasan |
|---|---|---|
| `### Manual QRIS Deposit Gateway (Unique 3-Digit Code)` | plan/102 | `plan/102_..._SUMMARY.md` |
| `### i18n Purification — Wallet & Member Pages` | plan/103 | `plan/103_..._SUMMARY.md` (591/591 key, 2 gate baru) |
| `### GPU Product Real Image Support & Admin Upload` | plan/104 | `plan/104_..._SUMMARY.md` |
| `### Viral Promotional Copywriting Kit` | plan/107 | `plan/107_VIRAL_PROMOTIONAL_COPYWRITING_KIT.md` (**non-code**) |
| `### Auto-fill Referral Code via URL (?ref=CODE)` | plan/108 | `plan/108_AUTOFILL_REFERRAL_SUMMARY.md` |
| `### Tampilan Nominal NET Penarikan` | plan/109 | `plan/109_..._SUMMARY.md` |
| `### Perbaikan Validasi Tier & Minimal Penarikan (Admin)` | plan/110 | `plan/110_..._SUMMARY.md` |

> **Catatan honest-reporting:** tugas menyebut plan/107–110, tetapi ground truth
> menunjukkan ROADMAP **juga** tidak memuat plan/102, 103, 104. Ketiganya
> dimasukkan agar log benar-benar tidak berlubang (dikonfirmasi ke pemilik di
> §13.1).

### 5.2 Perbaikan wajib lain di ROADMAP (temuan)

| Lokasi | Masalah | Aksi |
|---|---|---|
| Strict Rules L14 | *"No Hardcoded Credentials"* — dilanggar oleh fallback DB yang sudah ter-commit | Tambah catatan risiko terbuka (sudah ada di AGENTS.md) + rujuk ADR, **tanpa** menghapus riwayat historis |
| Fase 1 (L22) | Menyebut tabel `transactions` & `rentals` sebagai tabel inti | Tambahkan anotasi **strikethrough/deprecated** (jangan hapus record historis) |
| Fase 1 L24 | *"Google reCAPTCHA v2"* sebagai bot protection | Anotasi: **dipurge** (M8, plan/72) → native SVG CAPTCHA |
| Phase 11 (L221-224) | Menyebut "Replace Dev Simulator" saja | Perjelas: gateway QRIS manual plan/102 sudah ada; Phase 11 = otomatisasi gateway daring |

---

## 6. RENCANA PERUBAHAN — `docs/4_UI_UX_GUIDELINES.md`

Versi `v5.0` → **`v5.1`**.

### 6.1 Sisipkan standar baru

| § Baru | Judul | Isi (ground truth) |
|---|---|---|
| **§5.G** (setelah §5.F, L243) | **Provider E-Wallet Card Selector (2×2, CSS-only)** | Grid `grid-cols-2 gap-3`, per kartu `<label>` + `<input type="radio" name="provider_id">` tersembunyi (`sr-only`/`peer`) + styling `peer-checked:border-indigo-500 peer-checked:bg-indigo-50 dark:peer-checked:bg-indigo-500/10 peer-checked:ring-2 peer-checked:ring-indigo-500/20`; **tanpa JS**; state non-aktif → notice amber |
| **§5.H** | **Withdrawal Amount Hierarchy (plan/109)** | NET = nilai **primer** (`text-base`/`text-sm font-extrabold font-mono`), gross & fee = **sub-teks muted** (`text-[11px]`); aturan: *"dana yang benar-benar ditransfer adalah angka terbesar yang dibaca user"*; larangan menampilkan gross sebagai nilai tunggal |
| **§8.C** (setelah §8.B, L400) | **Admin Financial Tier Editor (plan/110)** | Grid tier dinamis; **`Min` baris 1 = `readonly`** (derivasi dari `wd_min_amount`); `Maks` baris terakhir auto-extend; tombol **"Rapikan Tier"** & **"Sesuaikan Batas Atas"**; status live `#tierStatus`; error **inline per baris**; **repopulasi** seluruh form setelah gagal simpan (`settings_form_state`) |

### 6.2 Perbaikan section yang ada

| § | Aksi |
|---|---|
| §5.C Cards | Tambah varian kartu **penarikan tertunda** (member) dengan hierarki NET (cross-ref §5.H) |
| §8.B Command Center — Queue Card (L363-391) | Perbarui contoh: antrean WD menampilkan **NET primer + label `Wajib Transfer (Net)`** + sub-teks gross/fee; antrean deposit menampilkan **kode unik & status** |
| §3/§4 (warna & tipografi) | Tidak berubah — berlaku apa adanya |
| §7 Animation | Tidak berubah (tidak ada animasi baru dari plan/107–110) |

> **Catatan:** `4_UI_UX_GUIDELINES.md` saat ini **belum** memuat standar
> maintenance page (plan/95) & kartu WhatsApp (plan/105). **Di luar scope**
> plan/111 kecuali pemilik meminta (lihat §9).

---

## 7. Apa yang **TIDAK** diubah (zero-diff yang disengaja)

* **Semua kode** `application/**` dan `system/**` — nol perubahan.
* `database.sql`, `database_seed.sql`, seluruh `scripts/*` — nol perubahan.
* `application/language/**` — nol perubahan (docs bukan i18n).
* `plan/1..110_*` — arsip historis, **tidak** disentuh (kecuali menambah
  `plan/111_..._SUMMARY.md` pada fase eksekusi, §12).
* `AGENTS.md` — **tidak** diubah oleh plan/111 (koreksi AGENTS.md = plan terpisah;
  lihat §8.1 & §8.5).

---

## 8. Divergensi & Temuan yang Perlu Keputusan Pemilik

### 8.1 ⚠️ LinkAja tidak ada di kode (perlu keputusan)

Tugas menyebut katalog e-wallet **"DANA, ShopeePay, OVO, GoPay, LinkAja"**.
Ground truth `database.sql:113-117` hanya men-seed **4** provider — **LinkAja
tidak ada**. Sesuai AGENTS.md (kode otoritatif) dan semangat tugas
("extract the truth from the current codebase"), rencana ini menulis
**4 provider** dan menyebut katalog bersifat **dinamis/administratif** sehingga
LinkAja dapat ditambahkan kapan pun lewat `/admin/ewallet-providers`.
**Bila pemilik ingin LinkAja di-seed**, itu **perubahan kode** (seed + migrasi),
bukan perubahan dokumen — di luar scope plan/111.

### 8.2 Kode vs PRD §4.F/§G soal cron → dokumen menang kode

Tidak ada cron di repo (ROI klaim manual, expiry lazy, wage klaim manual +
cooldown 7 hari). Rencana **menghapus** klaim cron dari PRD. Tidak ada keputusan
yang dibutuhkan kecuali pemilik ingin **menambahkan** cron (kerja kode baru).

### 8.3 PRD §122 vs plan/110 (min withdrawal)

`docs/1_PRD.md:124` menulis "Minimum Rp 100.000" keras. Sebaliknya plan/110
secara eksplisit menyatakan admin dapat menurunkan floor lewat
`/admin/settings` (§12 plan/110). Rencana menulis batas sebagai **dinamis +
default fail-safe**, dengan catatan bahwa `withdrawal_fees.php` **bukan**
otoritas operasional.

### 8.4 Endpoint notifikasi PRD §4.H tidak ada di kode (temuan tambahan)

4 endpoint fiktif. Rencana menuliskannya ulang ke kontrak aktual (`/notification`
+ `POST /user/read_notifications`). **Perlu konfirmasi** apakah pemilik ingin
sekaligus menambah endpoint REST-style tersebut (kerja kode) atau cukup
mendokumentasikan kontrak aktual (**rekomendasi: dokumentasikan kontrak aktual**).

### 8.5 Drift kecil antara AGENTS.md dan git

AGENTS.md Notes menyebut `plan/107...` **untracked**; kenyataannya
`git ls-files` menunjukkan **tracked** (dan working tree bersih). Catatan ini
agak stale. **Tidak** diubah di plan/111 (AGENTS.md di luar scope) — cukup
dicatat di sini agar tidak menyesatkan.

---

## 9. Non-Goals (tegas di luar scope)

1. **Tidak** mengubah/membuat kode, skema, migrasi, route, atau i18n.
2. **Tidak** menambah provider LinkAja (butuh seed + migrasi = kerja kode).
3. **Tidak** memperluas drift yang belum diminta: standar UI maintenance page
   (plan/95), kartu WhatsApp (plan/105), header declutter & App Preferences
   (plan/100), world map (plan/99/101) — **kecuali** pemilik memintanya.
4. **Tidak** menghapus record historis ROADMAP/PRD (hanya menganotasi
   deprecated) — sesuai semangat "soft delete".
5. **Tidak** menyentuh `docs/5_AUDIT_REPORT.md` (di luar 4 dokumen inti).

---

## 10. Verifikasi (dijalankan pada fase eksekusi, bukan sekarang)

Plan murni dokumen → verifikasi = **akurasi faktual**, bukan test suite.

| # | Cek | Metode | Kriteria lulus |
|---|---|---|---|
| V1 | Tidak ada klaim yang bertentangan dengan kode | Grep silang: setiap angka/kolom/enum di `docs/` dicocokkan ke `database.sql` + model | 0 mismatch |
| V2 | Kolom `deposits` di ERD ≡ DDL | Diff manual `docs/2_ERD.md` §0/§3 vs `database.sql:209-245` | identik (9 kolom baru + enum 6 nilai) |
| V3 | Key `system_settings` di ERD ≡ seed | Diff tabel ERD §4 vs `database.sql:359-393` | superset tepat (0 key hilang, 0 key fiktif) |
| V4 | Wage tier PRD ≡ `WAGE_TIERS` | Diff 5 baris | identik |
| V5 | Milestone ROADMAP ≡ `plan/102..110` | Cek 7 section ada + tautan ringkasan valid | 7/7 |
| V6 | Aturan tier & NET di UI/UX ≡ helper | Diff teks vs `withdrawal_fee_helper.php` + `withdrawal_amount_helper.php` | konsisten |
| V7 | Tidak ada referensi mati | Grep `Ledger_model`, `insert_transaction`, `bank_accounts` (sebagai "kartu bank"), `/notifications/unread-count`, `cron` di `docs/` | hanya di anotasi deprecated (jika ada) |
| V8 | Diff bersih | `git diff --stat` | hanya 4 berkas `docs/` (+ `plan/111_*_SUMMARY.md` bila dibuat) |
| V9 | i18n gate | Tidak dijalankan — **tidak ada** perubahan member copy (dokumen bukan surface member) | n/a (didokumentasikan) |

> **Tidak ada** `php -l`, migrasi, atau HTTP smoke yang relevan karena nol
> perubahan PHP. Ini harus dinyatakan jujur di ringkasan (jangan mengklaim test
> suite yang tidak ada).

---

## 11. Risiko & Mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R1 | Menulis "kebenaran" yang ternyata juga tidak akurat (dokumen baru usang) | Dokumen tetap menyesatkan | Setiap klaim wajib punya bukti file:line (§2); V1–V7 menahan |
| R2 | Menghapus detail lama yang masih relevan (mis. fee contoh) | Kehilangan info berguna | Contoh angka **dipertahankan** sebagai contoh, bukan sebagai aturan keras |
| R3 | Scope creep ke UI/UX plan/95–105 | Diff besar, review berat | §9 non-goals + konfirmasi §13 |
| R4 | Angka wage/rebate berubah lagi pasca-dokumen | Dokumen cepat usang | Tandai sumber otoritatif (`User_model::WAGE_TIERS`) + jadikan rujukan, bukan salinan tunggal |
| R5 | `docs/1_PRD.md` dipakai sebagai acuan copy pemasaran (plan/107) | Copy ikut salah | Sinkronkan PRD dengan `plan/107` ground truth (keduanya sepakat) |

---

## 12. Urutan Eksekusi (setelah approval)

1. **E1 — Branch baru** `docs/111-sync-ground-truth` (roadmap rule 4).
2. **E2 — `docs/2_ERD.md`** (paling mekanis & terverifikasi; jadi baseline).
3. **E3 — `docs/1_PRD.md`** (paling berat: §4.B, §4.E, §4.G, §4.H, §3, §6, §7.B).
4. **E4 — `docs/4_UI_UX_GUIDELINES.md`** (§5.G, §5.H, §8.C, perbaikan §8.B).
5. **E5 — `docs/3_ROADMAP.md`** (7 section milestone + anotasi fase historis).
6. **E6 — Jalankan V1–V8** (§10) dan catat bukti.
7. **E7 — Tulis `plan/111_SYNC_DOCUMENTATION_GROUND_TRUTH_SUMMARY.md`**
   (mencatat diff nyata + hasil verifikasi).
8. **E8 — Commit** (pesan bahasa Indonesia, konvensi repo), **tanpa** menyentuh
   kode. Tautkan ke §13 keputusan.

---

## 13. Keputusan yang Diminta Sebelum Eksekusi

| # | Pertanyaan | Pilihan (rekomendasi pertama) |
|---|---|---|
| 13.1 | **Cakupan milestone ROADMAP** — apakah sekadar plan/107–110, atau sekalian mengisi lubang plan/102–104 yang juga hilang? | **(a) Isi plan/102, 103, 104, 107, 108, 109, 110** (log tanpa lubang) · (b) hanya 107–110 |
| 13.2 | **Bump versi dokumen** (`v5.0` → `v5.1` pada PRD/ERD/UI-UX)? | **(a) Ya, bump v5.1** + changelog kecil di header · (b) pertahankan versi, tambahkan catatan sinkronisasi saja |
| 13.3 | **LinkAja** — tulis 4 provider (sesuai kode) atau minta seed LinkAja (kerja kode terpisah)? | **(a) Tulis 4 provider sesuai kode + nyatakan katalog dinamis** · (b) tambah seed LinkAja lewat plan terpisah dulu |
| 13.4 | **PRD §4.H Notification** — dokumenkan kontrak aktual, atau tambah endpoint REST-style yang tertulis? | **(a) Dokumentasikan kontrak aktual** (`/notification` + `POST /user/read_notifications`) · (b) jadikan roadmap kerja kode (tambah endpoint) |

---

## 14. Definition of Done (plan/111)

1. `docs/1_PRD.md`, `docs/2_ERD.md`, `docs/3_ROADMAP.md`,
   `docs/4_UI_UX_GUIDELINES.md` sinkron 100% dengan working tree (V1–V7 lulus).
2. Nol perubahan pada `application/**`, `system/**`, skema, route, migrasi, i18n.
3. `git diff --stat` hanya memperlihatkan 4 berkas `docs/`
   (+ `plan/111_*_SUMMARY.md`).
4. `plan/111_SYNC_DOCUMENTATION_GROUND_TRUTH_SUMMARY.md` merekam diff nyata +
   hasil verifikasi jujur (termasuk yang tidak bisa diuji).
5. Keputusan §13 tercatat pada commit message / ringkasan.
6. Tidak ada klaim "test suite lulus" — repo ini tidak punya test suite.
