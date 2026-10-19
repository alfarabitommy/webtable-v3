# Entity Relationship Diagram (ERD) & Database Schema v5.6
**Project Name:** Synapse
**Database Engine:** MySQL 8.4 (InnoDB)
**Character Set / Collation:** utf8mb4 / utf8mb4_unicode_ci
**Sinkronisasi skema:** penuh dengan `database.sql` — closure plan/89–92
(3-Tier Rebate Engine & Promoter Program, 100% runtime-verified, `dec-94563cfd1af2c22e`).

> **v5.6 — catatan penambahan (plan/121).** **LUCKY SPIN WHEEL** (reward
> referral): kolom `users.spin_tickets` INT UNSIGNED NOT NULL DEFAULT 0
> (COUNTER tiket — **bukan uang**: tidak pernah masuk `wallet_ledger`, tidak
> dapat ditarik/dibeli), **dua tabel baru** — `spin_ticket_grants`
> (`UNIQUE uk_spin_grant_downline (downline_id)` = guard struktural "1 tiket
> per downline seumur hidup") dan `spin_results` (jejak audit putaran +
> snapshot bobot `weight_snapshot`/`total_weight`), serta dua key
> `system_settings` (`spin_wheel_enabled` fail-closed + `spin_wheel_segments`
> JSON `[[nominal,bobot],…]`). **Inventory kanonik 14 → 16 tabel.** Hadiah
> dikreditkan ke dompet `main` via `Wallet_model::credit()` dengan
> `transaction_id = 'SPIN-{spin_results.id}'`. **Tanpa backfill** (keputusan
> owner D4). Invarian plan/121 didaftarkan di §7 (**SP1–SP12**).
>
> **v5.5 — catatan penghapusan (plan/120).** **Misi Level 1 DIHENTIKAN**
> (keputusan owner `dec-fc8726a949157eb0`): `Team::claim_level1()`/`_claim_message()`,
> `User_model::claim_level1()`/`get_claim_data()`/`LEVEL1_BONUS`, kartu misi `/team`,
> dan endpoint `POST /team/claim_level1` **dihapus**; **Bonus Pencapaian Tim
> (plan/119) menjadi SATU-SATUNYA reward pencapaian agensi**. Kolom
> `users.is_level_1_claimed` **DIPERTAHANKAN sebagai retensi historis** (nol
> pembaca/penulis — L1R1), demikian juga baris `wallet_ledger` `L1-*` dan
> notifikasi `notif_bonus_l1` (L1R4). **Nol DDL**: `database.sql` hanya menerima
> komentar `-- DEPRECATED (plan/120)`; `database_seed.sql` & `scripts/seed_*`
> tidak berubah; **14 tabel kanonik tetap**, tanpa tabel/index baru. Invarian
> plan/120 didaftarkan di §7 (L1R1–L1R6).
>
> **v5.4 — catatan sinkronisasi (plan/119).** Bonus Pencapaian Tim
> (**Team Milestone Bonus**) menggantikan Gaji Mingguan: kolom
> `users.team_milestone_claimed` VARCHAR(255) NULL DEFAULT NULL (state klaim
> CSV target kanonik menaik; penulis tunggal `User_model::claim_milestone()`),
> dua key `system_settings` (`team_milestone_enabled` fail-closed +
> `team_milestone_tiers` JSON `[[target,nominal],…]`), dan pola
> `transaction_id` `MSB-{user_id}-T{target}` (`wallet_type = 'main'`).
> `users.last_wage_claimed_at` menjadi **DEPRECATED (retensi)** — mesin wage
> dihentikan, kolom dibiarkan terisi apa adanya. **14 tabel kanonik tetap** —
> tanpa tabel baru, **tanpa index baru**, `uk_wallet_ledger_user_tx_type`
> tidak berubah. Invarian plan/119 didaftarkan di §7 (MK1–MK8).
>
> **v5.3 — catatan sinkronisasi (plan/118).** Memperkenalkan **dompet kedua +
> audit multi-dompet**: kolom `users.checkin_balance` DECIMAL(15,2) NOT NULL
> DEFAULT 0.00 ("Saldo Absen"; TIDAK dapat ditarik, hanya mensubsidi checkout —
> CK3) dan kolom `wallet_ledger.wallet_type` ENUM('main','checkin') NOT NULL
> DEFAULT 'main' (DEFAULT = backfill implisit seluruh baris lama, CK8), plus key
> `system_settings.checkin_require_active_rental` ('0' = OFF). **14 tabel kanonik
> tetap** — tanpa tabel baru, **tanpa index baru**, `uk_wallet_ledger_user_tx_type`
> tidak berubah. Invarian plan/118 didaftarkan di §7 (CK1–CK8).
>
> **v5.2 — catatan sinkronisasi (plan/112 + plan/113).** Menambahkan **Absensi
> Harian (daily check-in)**: 2 kolom `users` (`checkin_streak INT UNSIGNED NOT
> NULL DEFAULT 0`, `checkin_last_date DATE NULL DEFAULT NULL`), 4 kunci
> `system_settings` (`checkin_enabled`/`checkin_base_reward`/`checkin_max_reward`/
> `checkin_streak_policy`), dan **idempotensi ledger harian** —
> `wallet_ledger.transaction_id` = `CHK-{user_id}-{Ymd}`. **14 tabel kanonik
> tetap** (tanpa tabel baru, tanpa index baru, tanpa kolom total: histori klaim
> adalah derivasi `wallet_ledger` berprefix `CHK-%`). Invariant plan/112
> didaftarkan di §7.
>
> **v5.1 — catatan sinkronisasi (plan/111).** Dokumen ini disinkronkan dengan
> **kode sebagai sumber kebenaran** (AGENTS.md) untuk rentang plan/102–110.
> Perubahan utama: skema `deposits` (gateway QRIS manual, kode unik 3 digit —
> plan/102), kolom `gpu_products.image` (plan/104), tabel `ewallet_providers`
> (plan/106, 14 tabel kanonik), dan key `qris_*`/`deposit_*`/`is_maintenance_mode`
> di `system_settings`. Skema lama (deposit manual tanpa kode unik, tabel
> `transactions`/`rentals`) **tidak** dikembalikan.

---

## 0. ER Diagram (Mermaid)

Diagram di bawah mencakup **16 tabel kanonik** + relasi aktifnya (FK sesuai
`database.sql`). Tabel retention-only `rentals` & `otp_logs` (DEPRECATED M10)
dan `transactions` (decommissioned M6) **tidak** dirender — lihat §2, §4, §3.
Dua tabel plan/121 (`spin_ticket_grants`, `spin_results`) dirender di sini
sebagai **event ledger** Lucky Spin; `users.spin_tickets` hanyalah cache
counter-nya (§5, invarian SP6).

> **Catatan relasi:** `bank_accounts.bank_name` menyimpan **nama** provider
> (bukan FK) yang harus ada di `ewallet_providers.name`; relasi ini bersifat
> **logis by-name**, divalidasi di `Wallet::bind_bank`, gate penarikan, dan
> `migrate_106 --verify`. Plan/106 sengaja **tidak** membuat FK baru ke
> `ewallet_providers` demi menjaga struktur `bank_accounts` (zero-breakage
> `fk_withdrawals_bank`).

```mermaid
erDiagram
    users {
        BIGINT id PK
        VARCHAR20 phone UK "login, dinormalkan 0XXXXXXXXXX"
        VARCHAR255 password "bcrypt"
        VARCHAR10 invite_code UK
        BIGINT parent_id FK "upline, NULL utk root"
        DECIMAL15-2 balance "cache non-otoritatif; saldo asli = SUM wallet_ledger"
        DECIMAL15-2 checkin_balance "plan/118: cache dompet 'checkin' (Saldo Absen), TIDAK dapat ditarik"
        VARCHAR255 avatar_url
        INT level_id "0 = member biasa"
        TINYINT1 is_banned "0 = aktif"
        TINYINT1 is_promoter "plan/91, DEFAULT 0, admin-only"
        TINYINT1 must_change_password
        TINYINT1 is_level_1_claimed "DEPRECATED plan/120 — retensi historis"
        DATETIME last_wage_claimed_at
        INTU checkin_streak "plan/112: hari beruntun terakhir DIBAYAR (0 = belum pernah)"
        DATE checkin_last_date "plan/112: tanggal WIB klaim terakhir (otoritas harian)"
        INTU spin_tickets "plan/121: COUNTER tiket Lucky Spin (bukan uang); cache = Σgrant − Σspin"
        TINYINT1 is_wd_locked "plan/117: 1 = pengajuan penarikan BARU diblokir (admin-only)"
        VARCHAR255 wd_lock_reason "plan/117: alasan INTERNAL admin, NULL saat terbuka"
        TIMESTAMP created_at
        TIMESTAMP updated_at
    }
    gpu_products {
        INT id PK
        VARCHAR100 name
        VARCHAR255 image "plan/104: basename di uploads/products/, NULL = fallback"
        ENUM type "short_term | long_term"
        DECIMAL15-2 price "integer IDR"
        DECIMAL15-2 daily_rate
        INT duration_days
        TINYINT1 is_refundable
        INT max_per_user "0 = tanpa batas (plan/83)"
        TINYINT1 is_trial "plan/114: 1 = produk trial gratis (harga 0, max_per_user 1)"
        INT unlock_prerequisite_id "DORMANT plan/87, semua NULL"
        TINYINT1 is_active "satu-satunya gate ketersediaan"
        TIMESTAMP created_at
        TIMESTAMP updated_at
    }
    user_rentals {
        BIGINT id PK
        BIGINT user_id FK
        INT product_id FK
        ENUM source "purchase | promoter_reward (plan/91 K4)"
        DECIMAL15-2 purchase_price "0 utk kontrak reward (K7)"
        DECIMAL15-2 daily_roi
        INT total_days
        INT days_processed
        ENUM status "active | completed | cancelled"
        TIMESTAMP expired_at
        TIMESTAMP last_claimed_at
        TIMESTAMP created_at
    }
    promoter_claims {
        BIGINT id PK
        BIGINT user_id FK "promotor pemohon"
        INT product_id FK "produk reward"
        INT omzet_cost "omzet L1 dibakar, integer IDR"
        ENUM status "pending | approved | rejected"
        INT admin_id FK "approve/reject, NULL saat pending"
        VARCHAR255 admin_notes "wajib saat reject"
        TIMESTAMP created_at
        TIMESTAMP updated_at
    }
    bank_accounts {
        BIGINT id PK
        BIGINT user_id FK
        VARCHAR100 bank_name "plan/106: nama provider e-wallet"
        VARCHAR50 account_number "plan/106: nomor HP 08xxxxxxxxxx"
        VARCHAR100 account_holder
        TINYINT1 is_primary "plan/106: 1=terikat, 0=arsip"
        TIMESTAMP created_at
        TIMESTAMP updated_at
    }
    ewallet_providers {
        INT id PK
        VARCHAR50 code UK "plan/106: immutable, uppercase"
        VARCHAR100 name "label tampilan; disimpan di bank_accounts.bank_name"
        TINYINT1 is_active "1=tampil di selector, 0=disembunyikan"
        TIMESTAMP created_at
        TIMESTAMP updated_at
    }
    withdrawals {
        BIGINT id PK
        BIGINT user_id FK
        BIGINT bank_account_id FK
        VARCHAR50 wd_number UK "nullable"
        DECIMAL15-2 amount
        DECIMAL15-2 gross_amount
        DECIMAL15-2 fee_amount
        DECIMAL15-2 net_amount
        ENUM status "pending | processing | success | failed"
        VARCHAR255 remark
        VARCHAR255 decline_reason
        TIMESTAMP processed_at
        TIMESTAMP created_at
        TIMESTAMP updated_at
    }
    wallet_ledger {
        BIGINT id PK
        BIGINT user_id FK
        VARCHAR50 transaction_id
        ENUM type "credit | debit"
        ENUM wallet_type "plan/118: main (DEFAULT) | checkin"
        DECIMAL15-2 amount "integer IDR (M8)"
        VARCHAR255 description
        TIMESTAMP created_at
    }
    deposits {
        BIGINT id PK
        BIGINT user_id FK
        VARCHAR50 invoice_number UK "INV-{YmdHis}-{user_id}-{6 hex}"
        DECIMAL15-2 amount "pokok"
        SMALLINTU unique_code "plan/102: 3 digit 100-999, permanen"
        DECIMAL15-2 total_amount "plan/102: pokok + [fee] + kode, DIBEKUKAN"
        VARCHAR24 reserved_code_key UK "plan/102: {pokok}-{kode} saat reservasi hidup, NULL bila keluar"
        TIMESTAMP expires_at
        TIMESTAMP confirmed_at
        TIMESTAMP processed_at
        VARCHAR255 decline_reason
        ENUM status "pending | waiting_approval | success | failed | rejected | expired"
        TIMESTAMP created_at
        TIMESTAMP updated_at
    }
    admins {
        INT id PK
        VARCHAR50 username UK
        VARCHAR255 password
        TIMESTAMP created_at
        TIMESTAMP updated_at
    }
    user_notifications {
        BIGINT id PK
        BIGINT user_id FK
        VARCHAR100 title "retensi + fallback baris legacy"
        TEXT message "retensi + fallback baris legacy"
        VARCHAR64 title_key "plan/103: key kamus dasar (tanpa _title/_body)"
        JSON params "plan/103: argumen vsprintf untuk <key>_body"
        ENUM type "info | warning | success | commission"
        TINYINT1 is_read
        TIMESTAMP created_at
    }
    system_settings {
        INT id PK
        VARCHAR50 key_name UK
        TEXT key_value
        TIMESTAMP updated_at
    }
    system_audit_logs {
        BIGINT id PK
        INT admin_id FK "nullable"
        BIGINT user_id FK "nullable"
        VARCHAR100 action
        TEXT details "JSON/human-readable"
        VARCHAR45 ip_address
        TIMESTAMP created_at
    }
    rate_limits {
        BIGINT id PK
        VARCHAR191 rate_key UK
        INT attempts
        DATETIME last_attempt_at
        DATETIME locked_until "nullable"
    }
    spin_ticket_grants {
        BIGINT id PK
        BIGINT upline_id FK "penerima tiket saat grant"
        BIGINT downline_id FK "UK uk_spin_grant_downline = 1 tiket seumur hidup (SP2)"
        BIGINT rental_id FK "kontrak pemicu (audit), NULL bila hilang"
        TIMESTAMP created_at
    }
    spin_results {
        BIGINT id PK
        BIGINT user_id FK "pemutar roda"
        SMALLINTU slot_index "posisi segmen 0-based (animasi saja, SP4)"
        DECIMAL15-2 prize_amount "snapshot hadiah (integer IDR)"
        INTU weight_snapshot "bobot segmen saat spin (audit probabilitas)"
        INTU total_weight "sigma bobot saat spin"
        VARCHAR50 transaction_id UK "SPIN-{id} -> wallet_ledger (uk_spin_tx)"
        TIMESTAMP created_at
    }

    users ||--o{ users : "parent_id (upline)"
    users ||--o{ bank_accounts : "memiliki"
    users ||--o{ deposits : "membuat"
    users ||--o{ wallet_ledger : "pemilik saldo"
    users ||--o{ withdrawals : "menarik"
    users ||--o{ user_rentals : "menyewa"
    users ||--o{ user_notifications : "menerima"
    users ||--o{ promoter_claims : "memohon reward"
    users ||--o{ spin_ticket_grants : "upline menerima tiket"
    users ||--o{ spin_ticket_grants : "downline pemicu"
    users ||--o{ spin_results : "memutar roda"
    gpu_products ||--o{ user_rentals : "produk disewa"
    gpu_products ||--o{ promoter_claims : "produk reward"
    user_rentals ||--o{ spin_ticket_grants : "kontrak pemicu"
    bank_accounts ||--o{ withdrawals : "rekening tujuan"
    admins ||--o{ promoter_claims : "approve/reject"
    admins ||--o{ system_audit_logs : "pelaku aksi"
    users ||--o{ system_audit_logs : "subjek aksi"
    %% ewallet_providers TIDAK punya FK: bank_accounts.bank_name -> ewallet_providers.name (logis by-name, plan/106).
    %% spin_ticket_grants punya DUA FK ke users (upline_id + downline_id) dan satu ke user_rentals (rental_id).
```

---

## 1. Important Rules for AI Agent (Hermes)
* **Storage Engine:** Semua tabel WAJIB menggunakan `InnoDB` untuk memastikan dukungan terhadap *ACID compliance* dan *Foreign Key constraints*.
* **Timestamps:** Setiap tabel wajib memiliki kolom `created_at` dan `updated_at` (Tipe: `TIMESTAMP`, Default: `CURRENT_TIMESTAMP` / `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`).
* **Soft Deletes:** Jangan gunakan penghapusan fisik pada data finansial dan riwayat sewa. Gunakan status (misal: `is_active = 0`) atau buat kolom `deleted_at`.
* **Foreign Key Constraints:** Data krusial seperti transaksi dan penarikan WAJIB menggunakan `ON DELETE RESTRICT` terhadap tabel `users` agar data tidak hilang jika user dihapus.
* **Database Transactions (ACID Compliance):** Semua mutasi finansial — termasuk tetapi tidak terbatas pada Insert ke `wallet_ledger`, Update ke `users.balance`, Insert ke `user_rentals`, Update ke `deposits.status` — WAJIB dibungkus dalam blok `$this->db->trans_start()` dan `$this->db->trans_complete()` (atau `trans_begin()` / `trans_commit()` / `trans_rollback()`). Kegagalan pada salah satu langkah dalam transaksi HARUS memicu rollback penuh untuk menjaga integritas data.
* **Sumber Kebenaran Skema:** `database.sql` adalah DDL kanonik (fresh-install sinkron). Setiap perubahan skema WAJIB diduplikasi ke `database.sql` + dokumen ini + ALTER idempotent untuk DB aktif (pola plan/90 §3 & plan/92 §4).
* **Integer IDR (M8):** MySQL memakai `DECIMAL(15,2)` demi kompatibilitas non-breaking, tetapi saldo otoritatif & seluruh aritmetika uang di aplikasi adalah **integer** (`(int)` choke-point; `intdiv()` untuk fee/persen). Kolom `omzet_cost`, `max_per_user`, dll. yang memang integer didefinisikan `INT UNSIGNED`.

---

## 2. Core Tables Specification

### Tabel: `users`
Menyimpan data autentikasi, profil pengguna, saldo utama, dan struktur *Adjacency List* untuk hierarki MLM/Keagenan.
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `phone` (VARCHAR 20, UNIQUE, NOT NULL) - Nomor telepon login. **Normasi backend: `+62` → `0`, strip simbol (`-`, `()`, `spasi`) sebelum insert. Format final: `0XXXXXXXXXX`.**
* `password` (VARCHAR 255, NOT NULL) - Hashed Bcrypt.
* `invite_code` (VARCHAR 10, UNIQUE, NOT NULL) - Kode referral unik milik user ini (digenerate sistem saat register).
* `parent_id` (BIGINT, Unsigned, NULLABLE) - ID dari user Upline. **[Foreign Key -> users.id, ON DELETE SET NULL]**
* `balance` (DECIMAL 15,2, NOT NULL, DEFAULT 0.00) - **Cache non-otoritatif** saldo dompet UTAMA (di-`UPDATE` oleh `Wallet_model::_post()` sebagai mirror). **Saldo otoritatif = `SUM(credit) − SUM(debit)` atas baris `wallet_ledger` dengan `wallet_type = 'main'`** (`Wallet_model::get_balance()` → `(int)`); jangan pernah memakai `users.balance` sebagai sumber kebenaran untuk operasi dompet.
* `checkin_balance` (DECIMAL 15,2, NOT NULL, DEFAULT 0.00) - **plan/118:** cache non-otoritatif **dompet kedua** ("Saldo Absen"; `wallet_type = 'checkin'`). Kredit HANYA dari Bonus Absensi Harian; debit HANYA dari split payment checkout sewa. **TIDAK dapat ditarik** dan tidak dapat dipindahkan ke saldo utama (invarian CK3) — `Wallet_model::create_withdrawal()` hanya membaca dompet `main`. Saldo otoritatif = `SUM(credit − debit)` atas baris `wallet_type = 'checkin'` (`Wallet_model::get_checkin_balance()`). **Tidak ada jalur admin** untuk mengubahnya (D9): hanya absensi (kredit) dan checkout (debit) yang menulis, lewat `Wallet_model::_post()`. Tanpa backfill — seluruh user lama bernilai `0.00` (D3/CK8).
* `avatar_url` (VARCHAR 255, NULLABLE) - Path/URL foto profil.
* `level_id` (INT, NOT NULL, DEFAULT 0) - Menyimpan level keagenan saat ini (0 = Member biasa, 1 = Level 1, dst).
* `is_banned` (TINYINT 1, NOT NULL, DEFAULT 0) - 1 = akun dibanned (login/aksi diblokir, lockout).
* `is_promoter` (TINYINT 1, NOT NULL, DEFAULT 0) - **plan/91 (K1):** flag promotor, **hanya** diubah lewat toggle admin yang diaudit (`admin_toggle_promoter`). **Invariant gating-bypass:** `is_promoter = 1` mem-bypass gating referral **Condition A** ("wajib ≥ 1 rental seumur hidup") sehingga kode/link/QR referral tampil meski `lifetime == 0`; pembacaan flag SELALU segar di dalam TX (bukan session), jadi demosi langsung memblokir submit klaim baru (klaim pending tetap diproses — `dec-6d14b1039ad8cc30`).
* `must_change_password` (TINYINT 1, NOT NULL, DEFAULT 0) - 1 = paksa ganti sandi saat login berikutnya.
* `is_level_1_claimed` (TINYINT 1, NOT NULL, DEFAULT 0) - **DEPRECATED (plan/120):** flag idempotensi **Misi Level 1** (bonus one-time Rp 80.000 — 3 downline B aktif + omset ≥ Rp 330.000). **Fitur DIHENTIKAN** di lapis aplikasi: `Team::claim_level1()`/`_claim_message()`, `User_model::claim_level1()`/`get_claim_data()`/`LEVEL1_BONUS`, kartu `/team`, dan endpoint `POST /team/claim_level1` (kini 404) **dihapus**. Kolom **DIPERTAHANKAN sebagai retensi historis** — nol pembaca/penulis baru (invariant **L1R1**). **JANGAN** `DROP COLUMN`; baris `wallet_ledger` historis berprefix `L1-` dan notifikasi `title_key='notif_bonus_l1'` juga dibiarkan utuh (invariant **L1R4**, kunci kamus `ledger_bonus_l1` & `notif_bonus_l1_*` dipertahankan).
* `last_wage_claimed_at` (DATETIME, NULLABLE) - **DEPRECATED (plan/119):** stamp klaim **Gaji Mingguan** (siklus 7 hari). Mesin wage dihentikan dan digantikan Bonus Pencapaian Tim; kolom **dipertahankan sebagai retensi historis** (nol penulis baru, tidak ada keputusan bisnis yang membacanya). Baris `wallet_ledger` historis berprefix `WAGE-` juga dibiarkan utuh (Z1) — **jangan** menghidupkan kembali siklus mingguan tanpa pekerjaan pengembangan terpisah.
* `team_milestone_claimed` (VARCHAR 255, NULLABLE, DEFAULT NULL) - **plan/119:** STATE KLAIM **Bonus Pencapaian Tim** — CSV target integer kanonik **menaik tanpa spasi** (contoh `'9,30'`); `NULL`/`''` = belum ada klaim. Target diturunkan dari `system_settings.team_milestone_tiers`. **Penulis tunggal:** `User_model::claim_milestone()` di dalam satu TX terkunci (`users … FOR UPDATE` → `UPDATE` **kondisional** `(team_milestone_claimed <=> ?)` + guard `affected_rows() === 1`, pola M4) → klaim bersifat **permanen** (turunnya jumlah downline aktif tidak membatalkan/mengulang bayaran, MK6). Anggaran panjang: **maks 20 tier** × target ≤ 6 digit ⇒ ≤ 139 karakter (ambang di `application/helpers/team_milestone_helper.php`; menaikkan batas = **memperlebar kolom**). Nilai asing (hasil tamper) diabaikan parser dan tidak dapat membuka klaim (target juga harus ada di konfigurasi + tercapai). **Tanpa index tambahan** — seluruh baca state memakai PK `id` / `idx_user_id`.
* `checkin_streak` (INT UNSIGNED, NOT NULL, DEFAULT 0) - **plan/112:** hari beruntun terakhir yang **DIBAYAR** lewat Absensi Harian (0 = belum pernah klaim). Bukan jumlah hari dalam kalender, tetapi state pembayaran terakhir.
* `checkin_last_date` (DATE, NULLABLE) - **plan/112:** tanggal **WIB** klaim absensi terakhir. Pembanding harian murni (jam tidak relevan), selaras pola `last_wage_claimed_at`.
* `spin_tickets` (INT UNSIGNED, NOT NULL, DEFAULT 0) - **plan/121:** COUNTER **tiket Lucky Spin** (1 tiket = 1 putaran roda). **BUKAN uang** (invarian **SP6**): tidak pernah menulis `wallet_ledger`, tidak dapat ditarik, tidak dapat dibeli/dipindahtangankan, dan tidak masuk agregat treasury mana pun. **Penulis tunggal `Spin_model`:** `+1` di dalam TX `Rental_model::checkout_rental()` (langkah 7b — grant tiket saat downline L1 membeli produk **non-trial** untuk pertama kali) dan `−1` di dalam TX `Spin_model::spin()` (`UPDATE` **kondisional** `spin_tickets > 0` + guard `affected_rows() === 1`, pola M4). Nilai ini adalah **cache**: otoritas = `COUNT(spin_ticket_grants WHERE upline_id = id) − COUNT(spin_results WHERE user_id = id)`; pemeriksaan drift tersedia di `scripts/migrate_121_lucky_spin.php --verify` (exit 2 bila menyimpang). **Tanpa backfill** (D4) — seluruh baris lama mulai dari 0.
* `is_wd_locked` (TINYINT 1, NOT NULL, DEFAULT 0) - **plan/117 (W1):** kunci penarikan per-user. `1` = **pengajuan penarikan BARU** ditolak pada 4 lapis (tombol `/wallet`, gatekeeper GET `Wallet::withdraw()`, gatekeeper POST `Wallet::process_withdraw()`, dan **otoritas finansial** di dalam TX terkunci `Wallet_model::create_withdrawal()` → `code = 'wd_locked'` + rollback). **BUKAN ban:** user tetap bisa login, menyewa, klaim ROI & absensi (beda dari `is_banned`); **tidak** membatalkan penarikan `pending` yang sudah ada (keputusan D1) dan **tidak** menulis `wallet_ledger`/`withdrawals` (D2/W5). Hanya diubah lewat toggle admin ber-audit `admin_toggle_wd_lock` (`Admin::toggle_wd_lock()` + `Admin_model::set_wd_lock()` — satu-satunya penulis).
* `wd_lock_reason` (VARCHAR 255, NULLABLE) - **plan/117 (W3/W4):** alasan penguncian **internal admin**, wajib terisi saat mengunci (dipangkas 255 char) dan **selalu** di-`NULL`-kan saat membuka (invarian W4; dipaksa server + fase NORMALIZE migrasi). **TIDAK PERNAH** dirender di permukaan member — pesan yang diterima member selalu generik (`wd_err_locked`); alasan hanya muncul di panel admin (`/admin/users` tooltip + `/admin/user_detail`) dan di `system_audit_logs.details.reason`.
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
* `updated_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)
**Index Optimization:** `UNIQUE (phone)` = `uk_phone`, `UNIQUE (invite_code)` = `uk_invite_code`, `INDEX (parent_id)` = `idx_parent_id`, `INDEX (invite_code)` = `idx_invite_code`.
**Foreign Keys:** `fk_users_parent` → `users.id` ON DELETE SET NULL (self-referencing adjacency list).
**Invariant absensi harian (plan/112 + plan/118):** otoritas "hari" adalah **PHP WIB** (`date('Y-m-d')`), **bukan** `NOW()`/`CURDATE()` MySQL (M2/M3); tanggal di masa depan diperlakukan **"sudah klaim"** (fail-closed). **Tanpa index tambahan** — seluruh baca jalur uang memakai `WHERE id = ?` (Primary Key). Histori klaim **tidak** memiliki tabel sendiri: derivasi `SELECT … FROM wallet_ledger WHERE user_id = ? AND transaction_id LIKE 'CHK-%'` (terlayani `idx_user_id`), sehingga tidak ada sumber kebenaran ganda dan tidak ada kolom total. **plan/118:** kredit absensi masuk dompet `checkin` (`users.checkin_balance`), bukan saldo utama; gerbang opsional `checkin_require_active_rental` menuntut ≥ 1 kontrak aktif (`status='active' AND expired_at > now WIB` — predikat kanonik `Rental_model::has_active_rental()`, trial ikut) yang diuji **di dalam** TX setelah anchor lock (CK7).

### Tabel: `gpu_products`
Katalog paket GPUaaS (Marketplace). Data master; **tidak boleh** hard-delete (soft via `is_active`). 8 paket kanonik id 1–8 di-seed dari `database.sql` (Rp 150.000–Rp 10.000.000).
* `id` (INT, Primary Key, Auto Increment, Unsigned)
* `name` (VARCHAR 100, NOT NULL) - Contoh: "RTX 3060 Starter".
* `image` (VARCHAR 255, NULLABLE) - **plan/104:** nama berkas gambar produk (**BASENAME saja**, tanpa path) di `uploads/products/`. `NULL` **atau** berkas hilang di disk → marketplace merender fallback banner gelap (kontrak tunggal `product_image_url() === null`, helper `product_image_helper.php`). Berkas fisik bersifat runtime (`uploads/products/*` di-gitignore, placeholder `index.html` di-track) dan diunggah admin lewat `Admin::_handle_product_image_upload()` (allowlist `jpg|jpeg|png|webp`, maks 2048 KB, `detect_mime` + `encrypt_name`; SVG/executable ditolak). Backfill 8 paket kanonik dijalankan `scripts/migrate_104_gpu_product_images.php` — **keyed by `name`, bukan `id`** (id live bisa 5–12 sementara seed kanonik memakai 1–8).
* `type` (ENUM('short_term', 'long_term'), NOT NULL)
* `price` (DECIMAL 15,2, NOT NULL) - Harga untuk mulai menyewa (IDR).
* `daily_rate` (DECIMAL 15,2, NOT NULL) - Fix ROI (pendapatan harian) (IDR).
* `duration_days` (INT, NOT NULL, Unsigned) - Lama kontrak (misal: 25, 60).
* `is_refundable` (TINYINT 1, NOT NULL, DEFAULT 0) - 1 Jika harga `price` dikembalikan di akhir periode, 0 jika tidak.
* `max_per_user` (INT UNSIGNED, NOT NULL, DEFAULT 0) - **plan/83:** batas pembelian (kontrak) seumur hidup per user. `0` = tanpa batas; `N >= 1` = kuota lifetime. Satu-satunya gate pembelian per-user (GATE 2) — dihitung source-aware (lihat `user_rentals.source`, K4).
* `is_trial` (TINYINT 1, NOT NULL, DEFAULT 0) - **plan/114:** `1` = produk TRIAL gratis ("GPU Magang") — harga WAJIB `0`, `max_per_user` WAJIB `1`, checkout **tidak memotong saldo**, **tidak memicu rebate 3-tier**, dan **tidak menambah omzet** upline. Invarian: **tepat satu** baris `is_trial = 1` (dijaga admin product CRUD + `scripts/migrate_114_trial_product_wd_gate.php --verify`). Nilai `0` pada baris lama (tanpa backfill). **plan/116:** kolom ini adalah **identitas "sewa berbayar"** di seluruh sistem — `Rental_model::has_paid_rental()` (gerbang penarikan), `User_model::count_all_active_downlines()` / `count_active_b_downlines()` / `get_team_with_active_status()` (downline aktif & gaji mingguan), dan kelayakan upline rebate (`_distribute_rebate()`). Kontrak trial **tidak pernah** membuka salah satunya. **plan/116 F17:** klausa `AFTER` dihapus dari `CREATE TABLE` (hanya valid di `ALTER TABLE`) agar `database.sql` dapat di-import pada instalasi bersih.
* `unlock_prerequisite_id` (INT UNSIGNED, NULLABLE) - **DEPRECATED (plan/87):** rantai prasyarat progresif DICOMMISSIONED; kolom/index/FK dipertahankan non-destruktif (semua baris `NULL`), **tidak ada kode yang membaca/menulis**. Ketersediaan produk 100% via `is_active`.
* `is_active` (TINYINT 1, NOT NULL, DEFAULT 1) - 1 = dijual di marketplace (toggle admin; satu-satunya gate ketersediaan).
* `created_at` (TIMESTAMP)
* `updated_at` (TIMESTAMP)
**Index/FK:** `INDEX (unlock_prerequisite_id)` = `idx_unlock_prerequisite`; `fk_gpu_products_unlock_prereq` → `gpu_products.id` ON DELETE RESTRICT (dormant).

### Tabel: `user_rentals`  ← tabel LIVE (pengganti `rentals`)
Menyimpan kontrak sewa GPU aktif/kedaluwarsa + kontrak reward promotor.
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE RESTRICT]**
* `product_id` (INT, Unsigned, NOT NULL) - **[Foreign Key -> gpu_products.id, ON DELETE RESTRICT]** (nama kolom = `product_id`, bukan `gpu_product_id`).
* `source` (ENUM('purchase','promoter_reward'), NOT NULL, DEFAULT 'purchase') - **plan/91 (K4):** asal kontrak. `purchase` = checkout/inject berbayar (default); `promoter_reward` = reward zero-cost hasil approve klaim promotor. **Invariant K4 (kuota independen per kanal):** baris `promoter_reward` TIDAK memakan kuota pembelian berbayar — semua COUNT kuota (GATE 2 `checkout_rental` & kuota marketplace `Product_model`) memfilter `source <> 'promoter_reward'`; kuota kanal reward dihitung terpisah (`source = 'promoter_reward'`) di gate submit/approve `Promoter_model`.
* `purchase_price` (DECIMAL 15,2, NOT NULL) - Harga beli snapshot. **K7:** kontrak reward selalu `0` → tidak memicu `_distribute_rebate` dan tidak menambah omzet upline.
* `daily_roi` (DECIMAL 15,2, NOT NULL) - Snapshot `gpu_products.daily_rate` saat kontrak dibuat.
* `total_days` (INT UNSIGNED, NOT NULL, DEFAULT 0)
* `days_processed` (INT UNSIGNED, NOT NULL, DEFAULT 0) - Counter ROI harian yang sudah diklaim.
* `status` (ENUM('active', 'completed', 'cancelled'), NOT NULL, DEFAULT 'active')
* `expired_at` (TIMESTAMP, NULLABLE) - `started + total_days` (WIB); sweep lazy M3 memakai `expired_at > <PHP WIB>`.
* `last_claimed_at` (TIMESTAMP, NULLABLE) - Untuk manual ROI claim (T+1).
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
**Index Optimization:** `idx_user_status_expired` (user_id, status, expired_at) — lazy sweep per-user + kualifikasi downline; `idx_status_expired` (status, expired_at) — sweep global/CLI/admin; `idx_product_id` (product_id).
**Foreign Keys:** `fk_user_rentals_user` → `users.id` RESTRICT; `fk_user_rentals_product` → `gpu_products.id` RESTRICT.
**Invariant K7 (kontrak zero-cost, plan/91):** kontrak reward = kontrak normal (aktif, ROI tetap via jalur klaim `ROI-{rental_id}-D…`) yang otomatis memenuhi syarat "≥ 1 kontrak aktif" untuk menerima komisi rebate 3-tier — efek yang diinginkan. Kontrak reward TIDAK memicu distribusi rebate saat diterbitkan (harga 0).
**Invariant K8 (aktivitas berbayar = produk non-trial, plan/116):** setiap definisi "downline aktif"/"upline aktif" mensyaratkan kontrak `status = 'active'` **DAN** `expired_at > now WIB` **DAN** produknya `is_trial = 0` (join `gpu_products`, alias `gp`). Titiknya: `User_model::count_all_active_downlines()`, `count_active_b_downlines()`, `get_team_with_active_status()`, `Rental_model::_distribute_rebate()`. Tanpa klausa ini, satu trial gratis (Rp 0, 1× lifetime) cukup untuk membuka Gaji Mingguan Level 2 atau mengubah upline trial-only menjadi penerima rebate riil. `Rental_model::has_paid_rental()` (plan/114 D-A) adalah predikat kanoniknya; ia berbeda satu klausa karena menguji **riwayat** (tanpa filter `status`/`expired_at`), bukan aktivitas saat ini.

### Tabel: `rentals` — DEPRECATED (M10, plan/78)
Tabel legacy — **tidak ada jalur kode yang membaca/menulis**; tabel live = `user_rentals` (lihat §7 mapping). Retention-only untuk data historis; jangan dipakai di kode baru. Kolom: `id`, `user_id` (FK → users RESTRICT), `gpu_product_id` (FK → gpu_products RESTRICT), `status`, `total_days`, `days_processed`, `daily_rate_snapshot`, `started_at`, `ends_at`, `last_claimed_at`, `created_at`, `updated_at`.

---

## 3. Financial & Ledger Tables

### Tabel: `deposits`
Tabel staging **gateway deposit QRIS manual** (plan/102). Setiap deposit memiliki
**kode unik 3 digit (100–999)** sehingga admin dapat mencocokkan transfer masuk;
record tidak dihapus — status bergerak melalui state machine di bawah dan
`unique_code` disimpan **permanen** sebagai jejak audit.

* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE RESTRICT]**
* `invoice_number` (VARCHAR 50, UNIQUE, NOT NULL) - `INV-{YmdHis}-{user_id}-{6 hex CSPRNG}`.
* `amount` (DECIMAL 15,2, NOT NULL) - **Pokok** deposit (nominal yang diminta user, IDR).
* `unique_code` (SMALLINT UNSIGNED, NULLABLE) - **plan/102:** kode unik **3 digit (100–999)**, dialokasikan CSPRNG dari himpunan bebas (retry 3× saat tabrakan). Disimpan **permanen** (tidak dibuang setelah `success`) sebagai jejak audit.
* `total_amount` (DECIMAL 15,2, NOT NULL, DEFAULT 0.00) - **plan/102:** nominal bayar yang **DIBEKUKAN** saat create = `pokok + [fee] + kode`. Otoritatif untuk verifikasi admin **dan** nilai kredit (Option A). **Tidak pernah** dihitung ulang saat render.
* `reserved_code_key` (VARCHAR 24, NULLABLE, UNIQUE `uk_reserved_code_key`) - **plan/102:** `"{pokok}-{kode}"` **selama reservasi hidup**; `NULL` saat baris keluar dari `pending`/`waiting_approval`. Semantik "banyak NULL" InnoDB = jaminan tingkat DB: **maksimal satu pemilik hidup per (pokok, kode)**; kode bebas dipakai ulang setelah `expired`/`rejected`.
* `expires_at` (TIMESTAMP, NULLABLE) - **plan/102:** batas jendela bayar = `created_at + deposit_expiry_minutes` (default 60, clamp 5–1440).
* `confirmed_at` (TIMESTAMP, NULLABLE) - **plan/102:** stamp saat member menekan "Saya Sudah Transfer" (`pending → waiting_approval`).
* `processed_at` (TIMESTAMP, NULLABLE) - Stamp terminal (`success`/`failed`/`rejected`/`expired`).
* `decline_reason` (VARCHAR 255, NULLABLE) - **plan/102:** alasan terstruktur saat admin menolak (`rejected`).
* `status` (ENUM('pending','waiting_approval','success','failed','rejected','expired'), NOT NULL, DEFAULT 'pending') - State machine deposit.
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
* `updated_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)

**Index Optimization:** `idx_user_status` (user_id, status) untuk deposit hidup per user;
`idx_status_created` (status, created_at) untuk COUNT antrean pending + listing dashboard (plan/94 F2);
`idx_status_expires` (status, expires_at) untuk sweep expiry **lazy** (plan/102, pola sama `idx_status_created`).

> **Lifecycle (state machine, plan/102).** User membuat invoice → `pending`
> (`total_amount` + `unique_code` + `expires_at` dibekukan, reservasi kode ditahan)
> → pilih salah satu:
> * **`waiting_approval`** — member menekan **"Saya Sudah Transfer"** (`POST /wallet/confirm_payment/{invoice}`); **tanpa unggah bukti**; `confirmed_at` diisi; reservasi kode **tetap ditahan** dan **tidak pernah auto-expire** (keputusan D1).
> * **`expired`** — sweep **lazy** (`pending` → `expired`, `reserved_code_key` dilepas) saat user request berikutiya / CLI admin.
>
> Dari `pending` **atau** `waiting_approval` → **`success`** saat admin approve:
> kredit = **pokok + kode unik** (fee deposit, bila aktif, **ditahan platform**
> sehingga kredit tetap "pure principal"; saat `deposit_fee_enabled = '0'` kredit
> = `total_amount` persis — **Option A**). Satu baris `credit` ditulis ke
> `wallet_ledger`, `reserved_code_key` dilepas, notifikasi + audit ditulis
> **atomik dalam TX yang sama**. Cabang terminal lain: **`failed`** / **`rejected`**
> (decline admin + `decline_reason`).
>
> **Satu deposit hidup per user** (`status IN ('pending','waiting_approval')`) —
> dijaga di dalam TX terkunci `Wallet_model::create_deposit()`.
>
> **Kebijakan (dinamis, `system_settings`):** `deposit_min_amount` (10000),
> `deposit_max_amount` (50000000), `deposit_expiry_minutes` (60). Fallback
> fail-safe ada di `application/config/withdrawal_fees.php`. **Tidak ada gate
> hari/jam untuk deposit** — yang ada hanya jendela bayar (`expires_at`) dan
> verifikasi **manual oleh admin pada jam kerja**.

### Tabel: `wallet_ledger`
Immutable, append-only ledger yang mencatat setiap pergerakan dana masuk (credit) dan keluar (debit). Saldo user dihitung secara dinamis dari tabel ini. **`wallet_ledger` adalah SATU-SATUNYA ledger transaksi yang otoritatif** — tabel `transactions` (double-entry) legacy telah didecommission pada M6 (lihat `plan/68` & `plan/69`); jangan membuat ulang atau menulis ke tabel tersebut.

* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE RESTRICT]**
* `transaction_id` (VARCHAR 50, NOT NULL) - Referensi ke sumber transaksi (contoh: invoice number deposit, `RBT-{id}-L{tier}` rebate, `ROI-{rental_id}-D…`, **`CHK-{user_id}-{Ymd}` absensi harian — plan/112**, `MSB-{user_id}-T{target}` Bonus Pencapaian Tim — plan/119, `SPIN-{spin_results.id}` Lucky Spin — plan/121).
* `type` (ENUM('credit', 'debit'), NOT NULL) - `credit` = dana masuk, `debit` = dana keluar.
* `wallet_type` (ENUM('main','checkin'), NOT NULL, DEFAULT 'main') - **plan/118:** dompet tujuan/asal baris. `main` = Saldo Utama (dapat ditarik; juga dompet seluruh kredit agensi & hadiah Lucky Spin), `checkin` = Saldo Absen (tidak dapat ditarik — CK3). **DEFAULT `'main'` = backfill implisit seluruh baris historis** (CK8, tanpa script data); penulis tunggal tetap `Wallet_model::_post()` yang memilih kolom cache dari map whitelist internal (nilai asing ditolak, bukan di-default diam-diam).
* `amount` (DECIMAL 15,2, NOT NULL) - Selalu positif. Arah ditentukan oleh kolom `type`.
* `description` (VARCHAR 255, NOT NULL) - Keterangan transaksi (contoh: "Top Up via INV-20260620123456-1").
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)

**Index/Uniqueness:** `UNIQUE (user_id, transaction_id, type)` = `uk_wallet_ledger_user_tx_type` (idempotensi/anti-duplikasi); `INDEX (user_id)`, `INDEX (type)`, `INDEX (created_at)`.

> **Idempotensi harian absensi (plan/112):** `transaction_id` = **`CHK-{user_id}-{Ymd}`** (mis. `CHK-12-20260920`) dikombinasikan dengan `uk_wallet_ledger_user_tx_type` menjamin **maksimal satu kredit per user per hari** di tingkat DB. Duplikat `1062`/`23000` ditranslasi menjadi **`already_claimed`** (bukan error) — jalur AJAX tidak pernah mengembalikan HTML. Informasi "hari ke-N" hidup di `description`, **bukan** di ID: deskripsi kanonik `Bonus Absensi Harian Hari ke-{N}` (tanpa nominal → kamus tetap bersih dari angka, P3/L6), dirender dalam idiom aktif via pola `ledger_checkin` di `application/helpers/i18n_helper.php`.

> **Balance Calculation (per dompet, plan/118):** `SELECT SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) - SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) AS balance FROM wallet_ledger WHERE user_id = ? AND wallet_type = ?` — tanpa filter `wallet_type` hasilnya **bukan** saldo dompet mana pun (invarian CK2). `Wallet_model::lock_and_get_balances()` mengambil **kedua** dompet dalam satu agregat setelah anchor `users … FOR UPDATE` (CK4).

> **ACID Rule:** Every write to `wallet_ledger` MUST be wrapped in `$this->db->trans_start()` / `$this->db->trans_complete()`. A failed ledger insert MUST rollback all preceding operations in the same transaction (deposit status update, user balance adjustment, etc.).

> **Z1 / C4 (plan/89–92):** omzet-burn promotor (`promoter_claims`) dan penerbitan kontrak reward **tidak pernah** menyentuh `wallet_ledger` (burn = bookkeeping; reward `purchase_price = 0`). Semua kredit rebate lewat satu-satunya jalur `Wallet_model::credit()`.

> **C4 / M8 (plan/112):** kredit absensi harian juga ditulis **hanya** lewat `Wallet_model::credit()` (satu-satunya jalur uang: `credit()`/`debit()` → `_post()` dengan `(int)` choke-point + assertion positif). Jalur klaim tidak pernah menjalankan `UPDATE users SET balance` secara langsung.

### ~~Tabel: `transactions`~~ — DECOMMISSIONED (M6)
Tabel double-entry ledger lama. **TIDAK ADA LAGI** — telah dihapus pada M6 pragmatic path (audit: `plan/68_M6_TRANSACTIONS_TABLE_AUDIT_REPORT.md`; eksekusi: `plan/69_M6_DECOMMISSION_TRANSACTIONS_SUMMARY.md`). Aplikasi tidak pernah membaca/menulis tabel ini; seluruh pencatatan finansial memakai `wallet_ledger` (single ledger). Jangan membuat ulang tabel ini.

### Tabel: `bank_accounts` — BINDING E-WALLET (plan/106)
Data akun **e-wallet** yang di-bind user sebagai satu-satunya tujuan penarikan. Struktur tabel **sengaja dipertahankan** (zero-breakage: `fk_withdrawals_bank` ON DELETE RESTRICT, dan kartu riwayat penarikan membaca provider/nomor dari baris ini).
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE CASCADE]**
* `bank_name` (VARCHAR 100, NOT NULL) - **plan/106:** NAMA provider e-wallet; WAJIB ada di `ewallet_providers.name` (divalidasi di `Wallet::bind_bank`, gate penarikan, dan `migrate_106 --verify`).
* `account_number` (VARCHAR 50, NOT NULL) - **plan/106:** nomor HP e-wallet kanonik `^08[0-9]{8,11}$` (normalisasi + validasi satu sumber: `application/helpers/ewallet_helper.php`).
* `account_holder` (VARCHAR 100, NOT NULL) - Nama pemilik akun e-wallet.
* `is_primary` (TINYINT 1, NOT NULL, DEFAULT 1) - **plan/106:** FLAG BINDING AKTIF — `1` = terikat, `0` = arsip/unbound (hasil reset admin atau migrasi legacy). `Wallet_model::get_user_ewallet()` hanya membaca `is_primary = 1`; reset/unbind = ARSIP, **bukan** DELETE.
* `created_at` (TIMESTAMP)
* `updated_at` (TIMESTAMP)

### Tabel: `ewallet_providers` (plan/106)
Katalog provider e-wallet dinamis — satu-satunya sumber pilihan provider untuk member sekaligus otoritas status aktif/nonaktif.
* `id` (INT, Primary Key, Auto Increment, Unsigned)
* `code` (VARCHAR 50, UNIQUE `uk_ewallet_code`) - Identitas stabil (uppercase) untuk audit, backfill migrasi, dan CLI verify. **Immutable** setelah dibuat.
* `name` (VARCHAR 100, NOT NULL) - Label tampilan; disimpan apa adanya di `bank_accounts.bank_name`. Rename di-cascade ke binding tersimpan (`Wallet_model::reassign_provider_name`) di dalam TX yang sama + audit `admin_rename_ewallet_provider` (`rebound_bindings`).
* `is_active` (TINYINT 1, NOT NULL, DEFAULT 1) - `1` = muncul di selector member; `0` = disembunyikan (binding lama tetap tersimpan tetapi penarikan diblokir dengan `wd_err_ewallet_inactive`). Minimal satu provider wajib aktif (guard D6).
* `created_at` / `updated_at` (TIMESTAMP)
* Seed kanonik (`INSERT IGNORE`, tidak pernah menimpa perubahan admin): DANA, SHOPEEPAY (ShopeePay), OVO, GOPAY (GoPay). **Tanpa hard delete** — provider dinonaktifkan, bukan dihapus (D7).
* Pemilik tulis: `application/models/Ewallet_model.php` (member read-only via `get_active_providers()`; admin CRUD; gate penarikan memakai `get_provider_by_name()`).

### Tabel: `withdrawals`
Menyimpan antrean dan riwayat penarikan dana ke akun e-wallet.
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE RESTRICT]**
* `bank_account_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> bank_accounts.id, ON DELETE RESTRICT]**
* `wd_number` (VARCHAR 50, UNIQUE, NULLABLE) - Nomor unik withdrawal (diisi saat diproses).
* `amount` (DECIMAL 15,2, NOT NULL, DEFAULT 0.00)
* `gross_amount` (DECIMAL 15,2, NOT NULL) - Total saldo user yang dipotong (Misal: 50,000).
* `fee_amount` (DECIMAL 15,2, NOT NULL) - Biaya layanan yang ditahan sistem (Misal: 6,500).
* `net_amount` (DECIMAL 15,2, NOT NULL) - Uang riil yang harus ditransfer admin ke user (Misal: 43,500).
* `status` (ENUM('pending', 'processing', 'success', 'failed'), NOT NULL, DEFAULT 'pending')
* `remark` (VARCHAR 255, NULLABLE) - Alasan jika penarikan di-reject/gagal.
* `decline_reason` (VARCHAR 255, NULLABLE) - Alasan reject terstruktur.
* `processed_at` (TIMESTAMP, NULLABLE) - Waktu ketika admin mengklik "Approve/Transfer".
* `created_at` (TIMESTAMP)
* `updated_at` (TIMESTAMP)

> **Gerbang di dalam TX (plan/117 + plan/106):** sebelum baris dibuat,
> `Wallet_model::create_withdrawal()` memeriksa — setelah anchor `users …
> FOR UPDATE` — binding e-wallet aktif (`no_ewallet`), riwayat sewa berbayar
> (`no_paid_rental`), dan **`users.is_wd_locked`** (`wd_locked`, W2). Semua
> penolakan = rollback ⇒ **0 baris** di `withdrawals` **dan** `wallet_ledger`.
> Debit `wallet_ledger` tetap merekam **gross penuh** (N1/plan/109).

---

## 4. System, Settings & Security Tables

### Tabel: `otp_logs` — DEPRECATED (M10, plan/78)
Tabel legacy — **tidak ada flow OTP** (auth memakai native session CAPTCHA M8). Retention-only; jangan dipakai di kode baru. Kolom: `id`, `phone` (VARCHAR 20), `otp_code` (VARCHAR 6), `expires_at` (TIMESTAMP NOT NULL), `is_used` (TINYINT 1 DEFAULT 0), `created_at`. Tanpa FK.

### Tabel: `system_settings`
Key-value store konfigurasi runtime (circuit breaker + config finansial + rebate). Mutasi via jalur atomik `Admin_model::update_system_settings()` (audit `admin_update_settings`, before→after per key).
* `id` (INT UNSIGNED, Primary Key, Auto Increment)
* `key_name` (VARCHAR 50, NOT NULL, UNIQUE) - `uk_key_name`.
* `key_value` (TEXT, NOT NULL)
* `updated_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)

**Key ter-seed (idempotent `INSERT IGNORE` di `database.sql`):**

| Key | Default | Peran |
|---|---|---|
| `is_registration_open` | `1` | Circuit breaker registrasi (Phase 9A) |
| **`is_maintenance_mode`** | `0` | **Plan 95:** maintenance mode member site (`0`=normal, `1`=locked; **default OFF bila baris hilang**). Gate di `maintenance_helper.php` (`maintenance_gate()`), dieksekusi sebagai statement pertama di `MY_Controller`/`Auth`/`Lang`; admin/CLI exempt. Toggle: `POST /admin/toggle-maintenance` (audit `admin_toggle_maintenance`). |
| `wd_operational_days` / `wd_open_time` / `wd_close_time` | `1,2,3,4,5,6` / `07:00` / `19:00` | Window withdrawal (M1, plan/56) — gate **pengajuan** saja |
| `wd_fixed_fee` | `6500` | Biaya tetap withdrawal (M1) |
| `wd_fee_tiers` | JSON array 6 tier | Tier fee withdrawal, **half-open `[min, max)` kontigu penuh** (M1; amandemen plan/110: **endpoint turunan dinormalkan otomatis** — baris 1 `min` ← `wd_min_amount`, baris terakhir `max` ← `max(…, wd_max_amount + 1)`; gap/overlap tetap error keras). Choke-point input admin: `application/helpers/withdrawal_fee_helper.php`. |
| `wd_min_amount` / `wd_max_amount` | `100000` / `50000000` | Batas nominal WD (M1) — **dinamis**; admin boleh menurunkannya (mis. Rp 50.000) tanpa mengedit baris tier manual (plan/110 D1). Nilai di `application/config/withdrawal_fees.php` hanya **fail-safe**, bukan otoritas operasional. |
| `deposit_fee_enabled` / `deposit_fee_type` / `deposit_fee_value` | `0` / `flat` / `0` | Config fee deposit (M1) — fee ditahan platform, kredit deposit tetap pokok + kode |
| **`qris_image`** | `''` | **Plan 102:** **basename** gambar QRIS di `uploads/qris/`; `''` = belum dikonfigurasi → `create_deposit()` **fail-closed** (`deposit_err_qris_unconfigured`). |
| **`qris_merchant_name`** | `Synapse` | **Plan 102:** nama merchant yang ditampilkan di `views/wallet/pay.php`. |
| **`qris_payment_instructions`** | (teks instruksi) | **Plan 102:** instruksi pembayaran manual (dwibahasa **non-target** i18n plan/103 — konten admin-authored). |
| **`deposit_expiry_minutes`** | `60` | **Plan 102:** jendela bayar deposit (menit), clamp 5–1440 → `deposits.expires_at`. |
| **`deposit_min_amount` / `deposit_max_amount`** | `10000` / `50000000` | **Plan 102:** batas nominal deposit. Bundle harus koheren (`min < max`, `max ≤ 1e9`); pelanggaran → fallback fail-safe atomik. |
| `wa_number` / `support_email` | `628000000000` / `support@synapse.id` | Kontak/support (M7, plan/70) |
| **`wa_group_link`** | `''` | **Plan 105:** tautan undangan grup/komunitas WhatsApp resmi. Bentuk kanonik `https://chat.whatsapp.com/<token>`; `''` = belum dikonfigurasi → kartu Komunitas di `/help` **tidak dirender**. Validasi/kanonikalisasi satu sumber `application/helpers/wa_group_helper.php` (`wa_group_link_normalize()` / `wa_group_link_url()`); write-path `/admin/settings` (all-or-nothing + audit `admin_update_settings`). |
| **`rebate_enabled`** | `1` | **Plan 89:** master switch engine rebate 3-tier (0 = skip distribusi) |
| **`rebate_l1_percent`** | `5` | **Plan 89:** persen rebate L1 (integer 0–100) |
| **`rebate_l2_percent`** | `3` | **Plan 89:** persen rebate L2 |
| **`rebate_l3_percent`** | `1` | **Plan 89:** persen rebate L3 |
| **`checkin_enabled`** | `1` | **Plan 112:** gerbang **fail-closed** Absensi Harian — nilai sah `'0'`/`'1'`; `'0'` → widget dashboard member **tidak dirender** (DOM kosong) DAN endpoint `POST /checkin/claim` menolak dengan **HTTP 403** (`code: 'disabled'`). Nilai asing → fallback `1`. |
| **`checkin_base_reward`** | `50` | **Plan 112:** bonus hari ke-1 (IDR). Nilai sah integer `^[1-9][0-9]*$` dengan `1 … 1.000.000` (`Checkin_model::BASE_MAX`). |
| **`checkin_max_reward`** | `10000` | **Plan 112:** cap harian keras (IDR). Nilai sah integer `^[1-9][0-9]*$` dengan `1 … 10.000.000` (`Checkin_model::MAX_MAX`); bonus = `min(base × hari_streak, max)`. |
| **`checkin_streak_policy`** | `reset` | **Plan 112:** kebijakan saat bolong — whitelist `'reset'` (kembali ke hari 1) \| `'continue'` (**lanjut N+1**: gap dibekukan, hari terlewat tidak dihitung). Nilai asing → fallback `'reset'` (paling ketat). |
| **`checkin_require_active_rental`** | `0` | **Plan 118:** gerbang opsional klaim absensi — `'1'` = klaim menuntut **≥ 1 kontrak aktif** (`status='active'` + `expired_at > now WIB`, trial **ikut** dihitung; CK7), selain itu `code: 'no_active_rental'` (HTTP 200 + `status` segar). Widget merender state **terkunci** (D10). |
| **`team_milestone_enabled`** | `1` | **Plan 119:** gerbang **fail-closed** Bonus Pencapaian Tim — `'0'` → kartu `/team` **tidak dirender** DAN `POST /team/claim_milestone` menolak **HTTP 403** (`code: 'disabled'`). |
| **`team_milestone_tiers`** | `[[9,200000],[30,1000000],[70,2500000],[130,5000000],[190,9000000]]` | **Plan 119:** tier bonus agensi **dinamis** — JSON `[[target_downline_aktif, nominal_idr], …]`, kanonik **menaik**, maks 20 tier (ambang `team_milestone_helper.php`). Nominal tidak pernah berasal dari klien (MK4) dan tidak pernah masuk kamus (L6). |
| **`spin_wheel_enabled`** | `1` | **Plan 121:** gerbang **fail-closed** Lucky Spin — `'0'` → kartu/FAB/halaman roda **tidak dirender** DAN `POST /lucky-spin/spin` menolak **HTTP 403** (`code: 'disabled'`). |
| **`spin_wheel_segments`** | `[[5000,30],[10000,25],[25000,18],[50000,10],[100000,6],[150000,3],[250000,2],[500000,1]]` | **Plan 121:** segmen roda — JSON `[[nominal_idr, bobot], …]`; **urutan = urutan segmen** (indeks 0 = segmen pertama; 2–12 segmen; nominal ≥ 1; **tanpa segmen Rp 0**). Snapshot bobot disimpan per putaran (`spin_results.weight_snapshot`/`total_weight`) sehingga perubahan konfigurasi tidak merusak audit. |

> Fallback kode: `application/config/rebate_commission.php` (1/5/3/1) dipakai bila baris belum ada. Admin mengubah via Card 5 di `admin/settings` (validasi 0–100 all-or-nothing + audit atomik).
>
> **Fallback absensi harian (plan/112):** `application/config/checkin_rewards.php`
> — `checkin_enabled` `1`, `checkin_base_reward` `50`, `checkin_max_reward`
> `10000`, `checkin_streak_policy` `'reset'` — dipakai **per-key** bila baris
> hilang/rusak. Invarian **`1 ≤ base ≤ max`** ditegakkan
> `Checkin_model::_resolve_config()`: pelanggaran mengembalikan **pasangan
> base+max sekaligus secara ATOMIK** ke fallback + `log_message('error')`,
> sehingga setelan rusak **tidak pernah** membuat request fatal. Ambang
> administratif hidup **satu sumber** di `Checkin_model::BASE_MAX`/`MAX_MAX`
> (dipakai validator form admin **dan** CLI verifier); validator admin memakai
> kontrak aditif `{ok, errors, notices, field_errors, values}` (F12);
> `scripts/migrate_112_daily_checkin.php --verify` mendeteksi tamper →
> **exit 2**. Diatur admin di kartu **Absensi Harian (Card 6)** pada
> `/admin/settings` (all-or-nothing + audit `admin_update_settings`).
>
> **Fallback agensi & referral (plan/119 + plan/121):**
> `application/config/team_milestones.php` (`team_milestone_enabled` `1`,
> 5 tier 9/30/70/130/190) dan `application/config/spin_wheel.php`
> (`spin_wheel_enabled` `1`, 8 segmen total bobot 95). Keduanya **fallback
> per-key** (pola M1/plan/56, plan/89, plan/112): dipakai hanya bila baris
> dinamis hilang/rusak, dan **ambang validasi tidak tinggal di config** — ia
> hidup satu sumber di `application/helpers/team_milestone_helper.php`
> (`team_milestone_limits()`) dan `application/helpers/spin_wheel_helper.php`
> (`spin_wheel_limits()`), dipakai validator admin **dan** CLI `--verify`.
> Persist keduanya lewat jalur generik `Admin_model::update_system_settings()`
> (audit `admin_update_settings` / `admin_update_spin_wheel`).
>
> Fallback deposit/withdrawal: `application/config/withdrawal_fees.php` — berisi
> `operational_days`/`open_time`/`close_time`, `fixed_fee`, `min_amount`
> (100000), `max_amount` (50000000), `tiers` (6 baris), `deposit_expiry_minutes`
> (60), `deposit_min_amount` (10000), `deposit_max_amount` (50000000),
> `deposit_fee_*`. Dipakai **hanya** bila baris `system_settings` hilang/rusak
> (`Wallet_model::_resolve_financial_config()` + `get_deposit_policy()`).

### Tabel: `rate_limits`
Rate limiting & brute-force protection (Phase 10B). Satu baris per composite key (endpoint + identitas). Baris berumur pendek (GC ≤ 30 menit); **tanpa FK** (bukan data bisnis).
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `rate_key` (VARCHAR 191, NOT NULL, UNIQUE) - `uk_rate_key`.
* `attempts` (INT UNSIGNED, NOT NULL, DEFAULT 0)
* `last_attempt_at` (DATETIME, NOT NULL)
* `locked_until` (DATETIME, NULLABLE)
**Index:** `idx_last_attempt_at` (last_attempt_at).

---

## 5. Notification, Engagement & Referral-Reward Tables

### Tabel: `user_notifications`
Tabel notifikasi interaktif yang dipicu oleh AJAX polling. Menyimpan notifikasi sistem, reward, dan informasi akun.

* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE CASCADE]** — Notifikasi dihapus otomatis jika user dihapus.
* `title` (VARCHAR 100, NOT NULL) - Judul singkat notifikasi. **Plan/103:** dipertahankan sebagai **retensi + fallback baris legacy** (`title_key IS NULL`) — **tidak pernah dihapus**.
* `message` (TEXT, NOT NULL) - Isi detail notifikasi. **Plan/103:** idem — retensi + fallback snapshot untuk baris lama.
* `title_key` (VARCHAR 64, NULLABLE) - **plan/103 (W8):** kunci kamus dasar (tanpa sufiks `_title`/`_body`). Notifikasi keyed dirender dalam **idiom pembaca** via `i18n_notification_text()` — bukan prosa beku satu bahasa.
* `params` (JSON, NULLABLE) - **plan/103:** argumen `vsprintf` untuk `<title_key>_body` (larik JSON). Guard arity di `i18n_helper.php` melindungi baris ber-`params` 1 elemen dari `ArgumentCountError` (PHP 8) — fallback ke snapshot `message`.
* `type` (ENUM('info', 'warning', 'success', 'commission'), NOT NULL) - Kategori notifikasi untuk styling badge warna:
    * `info` → slate badge
    * `warning` → amber badge
    * `success` → emerald badge
    * `commission` → emerald badge dengan ikon `fa-coins`
* `is_read` (TINYINT 1, NOT NULL, DEFAULT 0) - 0 = belum dibaca (tampil di Red Badge), 1 = sudah dibaca.
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)

**Index Optimization:** `idx_user_read` (user_id, is_read) — Composite index untuk query unread count yang sangat sering: `SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0`.

> **Lifecycle:** Backend insert notifikasi → AJAX poll dari `header.php` fetches unread count → render Red Badge → user taps bell → fetch notification list → mark `is_read = 1` per item atau bulk "Mark All Read". Notifikasi approve/reject klaim promotor & toggle promotor ditulis **atomik dalam TX yang sama** dengan mutasinya (pola M5/N2, plan/91).


### Tabel: `spin_ticket_grants` — plan/121 (LUCKY SPIN WHEEL: grant tiket)
Satu baris = **satu tiket** yang diperoleh seorang upline karena downline L1-nya melakukan pembelian **non-trial pertama**. Diperlakukan sebagai *event ledger*: sumber kebenaran jumlah tiket yang diperoleh (bukan kolom counter di `users`).

* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `upline_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE RESTRICT]** — penerima tiket (parent pembeli **saat grant**).
* `downline_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE RESTRICT]** — pemicu: pembeli pertama kali. **UNIQUE `uk_spin_grant_downline` = guard struktural "maksimal 1 tiket per downline seumur hidup" (SP2)** — pembelian kedua dan seterusnya mustahil mencetak tiket baru walau lapis aplikasi dilewati.
* `rental_id` (BIGINT, Unsigned, NULLABLE) - **[Foreign Key -> user_rentals.id, ON DELETE SET NULL]** — kontrak pemicu (audit); `NULL` bila kontraknya hilang.
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)

**Index Optimization:** `uk_spin_grant_downline` (UNIQUE `downline_id`) + `idx_spin_grant_upline` (`upline_id`, `created_at`) untuk rekap per upline.
**Penulis tunggal:** `Spin_model::award_ticket_for_first_paid_rental()` — participant TX milik `Rental_model::checkout_rental()` (langkah 7b). Tidak ada jalur lain yang boleh INSERT ke tabel ini.

### Tabel: `spin_results` — plan/121 (LUCKY SPIN WHEEL: jejak audit putaran)
Satu baris = **satu putaran yang sudah dibayar** (tiket terpakai + hadiah dikreditkan).

* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT, Unsigned, NOT NULL) - **[Foreign Key -> users.id, ON DELETE RESTRICT]** — pemutar roda. Baris audit tidak pernah ikut terhapus.
* `slot_index` (SMALLINT Unsigned, NOT NULL) - Posisi segmen **0-based** (urutan konfigurasi). Dikirim balik ke klien **hanya** untuk animasi (zero-trust, SP4/SP5).
* `prize_amount` (DECIMAL 15,2, NOT NULL) - Snapshot nominal hadiah saat putaran (integer IDR).
* `weight_snapshot` (INT Unsigned, NOT NULL) + `total_weight` (INT Unsigned, NOT NULL) - **Snapshot probabilitas** saat putaran; hasil lama tetap dapat diaudit walau admin mengubah roda kemudian.
* `transaction_id` (VARCHAR 50, NULLABLE) - `'SPIN-{id}'` (di-set setelah insert); **UNIQUE `uk_spin_tx`** = backstop anti-duplikasi, sekaligus jejak ke `wallet_ledger`.
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)

**Index Optimization:** `idx_spin_user_created` (`user_id`, `created_at`) untuk histori 10 putaran terakhir halaman member + tabel 20 putaran terakhir panel admin.

---

## 6. Admin, Agency & Promoter Tables

### Tabel: `admins`
Tabel terpisah untuk autentikasi admin/operator. Hard-separated dari `users` — tidak ada foreign key ke `users.id`.
* `id` (INT UNSIGNED, Primary Key, Auto Increment)
* `username` (VARCHAR 50, UNIQUE, NOT NULL) - Username login admin.
* `password` (VARCHAR 255, NOT NULL) - Hashed Bcrypt/Argon2id.
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
* `updated_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)

> **Security Note:** Tabel ini sepenuhnya terpisah dari `users`. Session admin menggunakan `admin_id` (bukan `user_id`). Tidak ada foreign key relasi ke tabel user — kecuali sebagai pelaku aksi di `system_audit_logs` dan approver di `promoter_claims`.

### Tabel: `promoter_claims`
**Plan/91 (Omzet Burn):** antrean klaim reward promotor — promotor membakar omzet L1 (`omzet_cost`) untuk mendapat kontrak reward zero-cost (K7). **Invariant Z1/C4:** submit/approve/reject TIDAK pernah menyentuh `wallet_ledger`; burn = bookkeeping di tabel ini.
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `user_id` (BIGINT UNSIGNED, NOT NULL) - Promotor pemohon. **[Foreign Key -> users.id, ON DELETE RESTRICT]**
* `product_id` (INT UNSIGNED, NOT NULL) - Produk reward (harus ada di peta tier `promoter_rewards.php`). **[Foreign Key -> gpu_products.id, ON DELETE RESTRICT]**
* `omzet_cost` (INT UNSIGNED, NOT NULL) - Omzet L1 yang dibakar (integer IDR, M8; `^[1-9][0-9]*$`).
* `status` (ENUM('pending','approved','rejected'), NOT NULL, DEFAULT 'pending') - Flip kondisional `WHERE status='pending'` + gate `affected_rows()===1` (M4, anti double-action).
* `admin_id` (INT UNSIGNED, NULLABLE) - Admin yang approve/reject (NULL saat pending). **[Foreign Key -> admins.id, ON DELETE SET NULL]**
* `admin_notes` (VARCHAR 255, NULLABLE) - Catatan admin (WAJIB via UI saat reject; opsional saat approve).
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
* `updated_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)
**Index Optimization:** `idx_user_status` (user_id, status) — riwayat klaim per promotor; `idx_status_created` (status, created_at) — queue admin + pagination; `idx_product_id` (product_id).
**Foreign Keys:** `fk_promoter_claims_user` → `users.id` RESTRICT; `fk_promoter_claims_product` → `gpu_products.id` RESTRICT; `fk_promoter_claims_admin` → `admins.id` ON DELETE SET NULL.
**Invariant K5 (rasio CAC):** guard `8·cost ≤ 100·price ≤ 10·cost` (integer murni) dievaluasi saat submit & approve terhadap harga produk saat ini.
**Alur:** pending (submit promotor) → `approved` (kontrak zero-cost `user_rentals` `source='promoter_reward'` + notifikasi + audit `promoter_claim_approved`) | `rejected` (lock omzet lepas + `admin_notes` + notifikasi + audit `promoter_claim_rejected`). Tidak ada transisi pembatalan klaim; koreksi salah-approve via `cancel_rental` admin.

### Tabel: `system_audit_logs`
Trail audit untuk setiap aksi admin terhadap data finansial & konfigurasi.
* `id` (BIGINT, Primary Key, Auto Increment, Unsigned)
* `admin_id` (INT, NULLABLE) - **[Foreign Key → admins.id, ON DELETE SET NULL]**
* `user_id` (BIGINT, NULLABLE) - **[Foreign Key → users.id, ON DELETE SET NULL]**
* `action` (VARCHAR 100, NOT NULL) - Contoh: `approved_deposit`, `declined_withdrawal`, `admin_update_settings`, `admin_toggle_promoter`, `promoter_claim_approved`, `promoter_claim_rejected`.
* `details` (TEXT, NULLABLE) - JSON atau human-readable deskripsi (termasuk before→after per key).
* `ip_address` (VARCHAR 45, NOT NULL) - IPv4/IPv6 alamat pelaku aksi.
* `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
**Index Optimization:** `idx_admin_id`, `idx_user_id`, `idx_action`, `idx_created_at`.
> Mutasi finansial/konfigurasi/klaim ditulis **atomik dalam TX yang sama** dengan aksinya (M5/A1).

---

## 7. Schema Divergence & Architectural Invariants

### `user_rentals` vs `rentals` (Original ERD)
Tabel `rentals` dalam ERD v3.0 **tidak digunakan** dalam implementasi aktual dan telah ditandai **DEPRECATED (M10)**. Tabel live = **`user_rentals`**:

| Kolom ERD (`rentals`) | Kolom Aktual (`user_rentals`) |
|------------------------|-------------------------------|
| `gpu_product_id` | `product_id` |
| `total_days` | `total_days` (dihitung juga dari `expired_at`) |
| `days_processed` | `days_processed` |
| `daily_rate_snapshot` | `daily_roi` |
| `started_at` | *(DEFAULT CURRENT_TIMESTAMP)* |
| `ends_at` | `expired_at` |
| *(baru)* | `last_claimed_at` (untuk manual ROI claim) |
| *(baru)* | `purchase_price` |
| *(baru)* | `source` ENUM('purchase','promoter_reward') (plan/91 K4) |

Tabel `user_rentals` juga berfungsi sebagai tabel `rentals` di dalam kode (`application/models/Rental_model.php`).

### Inventory kanonik (16 tabel) vs retention-only
- **Kanonik (live):** `users`, `gpu_products`, `user_rentals`, `promoter_claims`, `deposits`, `withdrawals`, `bank_accounts`, `ewallet_providers` (plan/106), `wallet_ledger`, `user_notifications`, **`spin_ticket_grants` (plan/121)**, **`spin_results` (plan/121)**, `admins`, `system_settings`, `system_audit_logs`, `rate_limits`.
- **Retention-only (DEPRECATED M10):** `rentals` (live = `user_rentals`), `otp_logs` (tidak ada flow OTP).
- **Dihapus dari skema kanonik:** `site_settings` (M7 → `system_settings`), `transactions` double-entry (M6 → `wallet_ledger`).

### Invariant arsitektural yang diregistrasikan (plan/89–92)
- **K1 — `is_promoter` admin-only:** flag hanya berubah lewat toggle admin ber-audit (`admin_toggle_promoter` before→after + notifikasi info).
- **K4 — Kuota independen per kanal:** kanal paid (`source='purchase'`) dan kanal reward (`source='promoter_reward'`) punya kuota `max_per_user` terpisah; kontrak reward tidak memakan kuota pembelian berbayar.
- **K5 — Guard rasio CAC** 8–10% (integer) pada submit & approve klaim.
- **K6 — Demosi segar:** gate submit membaca `is_promoter` segar dalam TX; klaim pending tetap diproses.
- **K7 — Kontrak zero-cost:** `source='promoter_reward'`, `purchase_price = 0`, tanpa `_distribute_rebate`, tanpa dampak omzet upline; burn omzet TIDAK menyentuh `wallet_ledger` (Z1/C4).

### Invariant arsitektural tambahan (plan/102–110)
- **D1 (plan/102) — Eksklusivitas kode unik:** satu pemilik hidup per `(pokok, kode)` dijamin `UNIQUE uk_reserved_code_key`; kode dilepas (`NULL`) saat baris keluar dari `pending`/`waiting_approval` sehingga dapat dipakai ulang.
- **D2 (plan/102) — `waiting_approval` abadi:** status ini **tidak pernah** auto-expire; reservasi kode tetap ditahan setelah member menyatakan sudah transfer.
- **D3 (plan/102) — Kredit = pokok + kode:** nominal yang ditransfer member (minus fee deposit yang ditahan platform) yang dikreditkan; `total_amount` dibekukan saat create dan tidak dihitung ulang saat render.
- **L1 (plan/106) — Payout e-wallet saja:** tidak ada jalur bank; katalog provider dinamis (`ewallet_providers`), `bank_accounts.bank_name` harus ada di katalog, nomor kanonik `^08[0-9]{8,11}$`.
- **A1 (plan/106) — Arsip, bukan hapus:** reset/unbind binding = `is_primary = 0`; provider = `is_active = 0`; tidak pernah DELETE (FK RESTRICT / retensi riwayat).
- **T1 (plan/110) — Endpoint tier turunan:** `wd_fee_tiers` wajib kontigu penuh; dua endpoint dinormalkan otomatis (bukan ditolak) karena `calculate_withdrawal_fee()` memakai tarif tier terakhir sebagai fallback — celah tier = potensi kurang potong biaya secara senyap.
- **N1 (plan/109) — Ledger tidak diubah:** tampilan NET/gross/fee adalah **presentasi**; debit `wallet_ledger` tetap merekam **gross penuh**.
- **Z2 (plan/102–106) — `wallet_ledger` satu-satunya ledger:** deprecation tabel `transactions` (M6) dan `rentals` (M10) tetap berlaku; tidak ada kode baru yang menulis ke keduanya.

### Invariant arsitektural tambahan (plan/112 — Daily Check-in)
- **C1 (plan/112) — Idempotensi harian tingkat DB:** satu kredit per user per hari dijamin `transaction_id = CHK-{user_id}-{Ymd}` + `UNIQUE uk_wallet_ledger_user_tx_type (user_id, transaction_id, type)`; duplikat `1062`/`23000` ditranslasi `already_claimed` (bukan error) sehingga jalur AJAX tidak pernah HTML.
- **C2 (plan/112) — Fail-closed dua sisi:** `checkin_enabled = '0'` → widget tidak dirender di DOM **dan** endpoint klaim menolak HTTP 403; nilai setelan rusak/asing → fallback per-key (pasangan `base`+`max` dikembalikan **atomik**).
- **C3 (plan/112) — Otoritas tanggal = PHP WIB:** perhitungan hari memakai `date('Y-m-d')` (timezone di-pin `Asia/Jakarta`), **bukan** `NOW()`/`CURDATE()`; tanggal di masa depan → "sudah klaim" (fail-closed).
- **C4 (plan/112) — Tanpa tabel/index/kolom total baru:** state absensi = 2 kolom di `users` (`checkin_streak`, `checkin_last_date`); histori = derivasi `wallet_ledger` berprefix `CHK-%` (terlayani `idx_user_id`); inventory kanonik tetap **14 tabel** *(per plan/112; **16 sejak plan/121** — lihat §7 inventory & SP1–SP12)*.
- **C5 (plan/112) — Satu jalur uang:** seluruh kredit lewat `Wallet_model::credit()` di dalam satu TX terkunci (`SELECT … FROM users … FOR UPDATE` → `UPDATE users` **kondisional** + `affected_rows() === 1` → `credit()` → commit); tidak ada mutasi saldo di jalur lain.

### Invariant arsitektural tambahan (plan/117 — Per-User Withdrawal Lock)
- **W1 — Satu sumber kebenaran:** `users.is_wd_locked` adalah satu-satunya penanda kunci penarikan; nilainya hanya berubah lewat `Admin_model::set_wd_lock()` dari toggle admin ber-audit `admin_toggle_wd_lock` (`before`/`after` + `reason` + IP) di dalam satu TX (`trans_start`/`trans_complete`).
- **W2 — Gate berlapis (cermin plan/114):** UI `/wallet` (tombol `disabled` + ikon `fa-lock`) → gatekeeper **GET** `Wallet::withdraw()` (302 + flashdata) → gatekeeper **POST** `Wallet::process_withdraw()` (diperiksa **sebelum** rate limit) → **otoritas finansial** di dalam TX terkunci `Wallet_model::create_withdrawal()` (langkah `1a`, tepat setelah anchor `users FOR UPDATE` + saldo segar; `code = 'wd_locked'` → rollback ⇒ **0 baris** `withdrawals`, **0 baris** `wallet_ledger`). Lapisan atas hanya UX; keputusan uang tetap di TX.
- **W3 — Privasi alasan:** `wd_lock_reason` **tidak pernah** dikirim/dirender ke permukaan member — tidak ada view/controller member yang membacanya. Member hanya menerima boolean (`Wallet::index()` → `$wd_locked`) dan pesan kamus generik `wd_err_locked`; alasan hanya hidup di panel admin + `system_audit_logs.details.reason`. Dijaga gate grep pada matriks verifikasi plan/117.
- **W4 — Konsistensi data:** `wd_lock_reason` **selalu** `NULL` saat `is_wd_locked = 0`. Dipaksa dua sisi: server (`set_wd_lock()` mengabaikan `$reason` saat unlock) dan migrasi (`scripts/migrate_117_user_wd_lock.php` fase NORMALIZE + guard orphan reason = 0 → pelanggaran = exit 2).
- **W5 — Tanpa clawback (D1/D2):** lock/unlock tidak menyentuh `withdrawals`, `wallet_ledger`, `bank_accounts`, maupun `user_rentals`. Penarikan `pending` dibiarkan dan tetap diproses admin; imutabilitas ledger (Z1/Z2) utuh. Audit + notifikasi dwibahasa (D3) hanya ditulis saat **transisi nyata** (`affected_rows() === 1`) — no-op idempoten tetap HTTP 200 tanpa baris audit palsu (A1).

### Invariant arsitektural tambahan (plan/118 — Dompet Absen & Split Payment)
- **CK1 — Satu penulis uang (multi-dompet):** seluruh mutasi kedua dompet lewat satu-satunya choke-point `Wallet_model::_post()` (dipanggil `credit()`/`debit()`), kini ber-parameter `wallet_type`. Kolom cache `users.balance` / `users.checkin_balance` **hanya mirror** non-otoritatif dan di-`UPDATE` relatif di TX yang sama. Tidak ada `insert('wallet_ledger'` / `UPDATE users SET balance|checkin_balance` di luar `Wallet_model`.
- **CK2 — Saldo otoritatif per dompet:** `main` = `SUM(credit − debit) WHERE wallet_type = 'main'`; `checkin` = query sama dengan `'checkin'` (`Wallet_model::get_balance()`/`get_checkin_balance()`/`get_wallet_totals()`; `(int)`). Pembacaan **tanpa** filter dompet adalah bug (kredit absensi akan terbaca sebagai saldo utama) — karena itu seluruh agregat `Admin_model` diberi filter/komentar eksplisit, dan `scripts/reconcile_balances.php` mengukur drift **per dompet**.
- **CK3 — Saldo absen tidak dapat ditarik:** `Wallet_model::create_withdrawal()` memakai `lock_and_get_balance()` yang hanya melihat dompet `main`, sehingga `checkin_balance` **tidak pernah** masuk jalur penarikan. `checkin_balance` juga **bukan** jalur admin (D9): `Admin_model::inject_balance()` tetap dompet `main`; admin hanya dapat **membaca** (`Admin_model::get_user_checkin_balance()`).
- **CK4 — Satu kunci untuk dua dompet:** `Wallet_model::lock_and_get_balances()` melakukan anchor `SELECT … FROM users WHERE id = ? FOR UPDATE` (statement pertama) lalu **satu** agregat dua-dompet — satu baris `users` = satu kunci, sehingga tidak ada lock/urutan-lock baru dan kedua dompet terserialisasi bersama (anti-race C5 tetap utuh).
- **CK5 — Split = dua baris debit berkunci unik:** `Rental_model::checkout_rental()` menulis `wallet_type='checkin'` (`…-CHK`) lebih dulu lalu `wallet_type='main'` (`…-MAIN`), masing-masing lewat `debit()` dan hanya bila nilainya `> 0`; tanpa split ID legacy `RENT-{product_id}-{YmdHis}` tidak berubah. Dua `transaction_id` berbeda membuat `uk_wallet_ledger_user_tx_type` tetap aman; kegagalan salah satu → rollback penuh (0 baris ledger, 0 kontrak).
- **CK6 — Subsidi bukan diskon:** `user_rentals.purchase_price` **selalu** harga penuh; ROI harian, rebate 3-tier (`RBT-{rental_id}-L{tier}`), dan omzet upline dihitung dari harga penuh. `checkin_used + main_used === price` diasersikan (integer IDR, M8) sebelum debit.
- **CK7 — Syarat absensi = sewa AKTIF:** predicate `status = 'active' AND expired_at > now WIB` (bound param PHP WIB, bukan `NOW()`), trial **maupun** berbayar dihitung; diuji **di dalam** TX setelah anchor lock (read view segar). Bukan `has_paid_rental()` (predikat itu menguji **riwayat**).
- **CK8 — Tanpa backfill:** `wallet_type` DEFAULT `'main'` mengisi seluruh baris lama dan `checkin_balance` DEFAULT `0.00` mengisi seluruh user lama — baris ledger historis **tidak pernah** di-`UPDATE` (append-only Z1), dan bonus absensi pra-plan/118 tetap di saldo utama (keputusan D3).

### Invariant arsitektural tambahan (plan/119 — Bonus Pencapaian Tim)
- **MK1 — Predikat "downline aktif" tidak berubah:** otoritas penghitungan = `User_model::count_all_active_downlines()` dengan predikat kanonik **K8** (plan/116): `ur.status = 'active'` **DAN** `ur.expired_at > now WIB` (bound param PHP, bukan `NOW()`) **DAN** `gpu_products.is_trial = 0`. Plan/119 **tidak** memperkenalkan predikat baru; trial gratis tetap tidak pernah membuka tier (anti free-rider).
- **MK2 — Satu klaim per `(user_id, target)`, seumur hidup, tiga lapis:** (a) state `users.team_milestone_claimed` di-update **kondisional** NULL-safe `(team_milestone_claimed <=> ?)` + guard `affected_rows() === 1`; (b) `UNIQUE uk_wallet_ledger_user_tx_type` pada `MSB-{user_id}-T{target}` (duplikat `1062`/`23000` ditranslasi `already_claimed`, bukan error/HTML); (c) recount kualifikasi **di dalam** TX terkunci.
- **MK3 — Satu jalur uang, dompet utama:** kredit HANYA via `Wallet_model::credit(..., Wallet_model::WALLET_MAIN)`; nominal **selalu** diambil dari konfigurasi server (klien hanya mengirim `target`).
- **MK4 — Gerbang target server-side:** `target` yang tidak ada di `team_milestone_tiers` → `code: 'invalid_target'` (nol mutasi, nol baris ledger) — nilai tier tidak pernah berasal dari klien.
- **MK5 — Urutan TX mengikat:** `SELECT … FROM users WHERE id = ? FOR UPDATE` → baca state klaim → resolve konfigurasi (`enabled`, `tiers`) → gerbang target → idempotensi → **gerbang sekuensial** → recount → `UPDATE` kondisional state → `credit()` → commit. Setiap kegagalan = rollback penuh.
- **MK6 — Klaim permanen (tanpa clawback):** penurunan jumlah downline aktif setelah klaim tidak membatalkan status dan tidak dapat mengulang pembayaran; tier yang sudah diklaim tetap tercatat walau tier-nya kemudian dihapus dari konfigurasi.
- **MK7 — Klaim sekuensial:** tier hanya dapat diklaim bila **seluruh tier dengan target lebih rendah sudah diklaim** (`code: 'locked_previous'`); state `claimable` maksimal **satu** baris (tier belum-diklaim terendah).
- **MK8 — Tanpa tabel/index baru:** inventory kanonik tetap **14 tabel** *(per plan/119; **16 sejak plan/121**)*; `uk_wallet_ledger_user_tx_type` tidak berubah; nol perubahan di `system/`, `routes.php`, dan `views/templates/header.php`.

### Invariant arsitektural tambahan (plan/120 — Penghapusan Misi Level 1)
- **L1R1 — Retensi kolom:** `users.is_level_1_claimed` **DIPERTAHANKAN** (nol `DROP COLUMN`); **nol** pembaca/penulis di `application/` — satu-satunya pembaca historis (`User_model::get_claim_data()`/`claim_level1()`) sudah dihapus.
- **L1R2 — Satu jalur bonus:** satu-satunya penulis bonus agensi = `User_model::claim_milestone()` (`MSB-*`, dompet `main`). Jalur kredit `L1-*` Rp 80.000 **tidak ada lagi**.
- **L1R3 — Mesin bersama utuh:** `count_all_active_downlines()`, `get_team_with_active_status()`, `Rental_model::has_paid_rental()`, dan `_distribute_rebate()` **tidak disentuh satu baris pun** (K8/MK1 tetap berlaku). `count_active_b_downlines()` & `sum_sales_b_downlines()` **sengaja dipertahankan** (tanpa pemanggil) sebagai rujukan invariant plan/116.
- **L1R4 — Imutabilitas riwayat:** baris `wallet_ledger` `L1-*` dan `user_notifications` `title_key='notif_bonus_l1'` **tidak dimutasi**; `ledger_bonus_l1`, `notif_bonus_l1_title`/`_body`, dan pola `i18n_ledger_description()` dipertahankan agar riwayat tidak dirender sebagai string mentah.
- **L1R5 — Paritas kamus:** EN ≡ ID **1:1** pasca-penghapusan = **633/633** key (−17 key khusus Misi Level 1) *(sekarang **669/669** setelah plan/121)*.
- **L1R6 — Nol DDL:** `database.sql` hanya menerima komentar `-- DEPRECATED (plan/120)`; `database_seed.sql`, `scripts/seed_*`, dan seluruh fixtures **tidak berubah**; tanpa migration script.

### Invariant arsitektural tambahan (plan/121 — Lucky Spin Wheel)
- **SP1 — Satu jalur kredit:** hadiah HANYA lewat `Wallet_model::credit(..., Wallet_model::WALLET_MAIN)`; tidak ada `insert('wallet_ledger')` atau `UPDATE users SET balance` di luar `Wallet_model`.
- **SP2 — Satu tiket per downline, seumur hidup:** dijamin **tiga lapis** — (a) `UNIQUE uk_spin_grant_downline (downline_id)`, (b) pre-check grant di dalam TX yang sudah memegang `users … FOR UPDATE` atas baris pembeli (serialisasi anti-race), (c) gate "pembelian non-trial PERTAMA" (`COUNT` kontrak berbayar = 1).
- **SP3 — Hanya pembelian non-trial:** jalur trial gratis (`$free_trial`, plan/114) melewati hook sepenuhnya; kontrak `source='promoter_reward'` (zero-cost, K7) **tidak** dihitung sebagai pembelian.
- **SP4 — Zero-trust:** klien tidak pernah mengirim segmen/hadiah/nominal — hanya token CSRF. Bobot dibaca dari konfigurasi ter-commit **di dalam** TX putaran, dan `slot_index` pada respons hanya untuk animasi.
- **SP5 — Randomisasi server + fungsi murni:** `random_int()` (CSPRNG) menyuplai `$roll`; pemilihan segmen = `spin_wheel_pick()` (murni, `$roll` di luar rentang → `NULL`, tidak pernah diam-diam segmen 0). Helper `spin_wheel` adalah choke-point tunggal bentuk segmen/peluang/EV.
- **SP6 — Tiket bukan uang:** `users.spin_tickets` adalah counter (cache); otoritas = Σgrant − Σspin; tidak dapat ditarik/dibeli/dipindahtangankan; tidak masuk `scripts/reconcile_balances.php` maupun agregat treasury.
- **SP7 — Fail-closed dua sisi:** `spin_wheel_enabled = '0'` → kartu/FAB/halaman member tidak menampilkan roda **dan** `POST /lucky-spin/spin` menolak HTTP 403 (`code: 'disabled'`); konfigurasi segmen yang tidak dapat dipakai (dinamis **dan** fallback rusak) → `code: 'not_configured'` (403). Nilai dinamis rusak namun fallback valid → **fallback per-key** + log (pola plan/112/119), bukan error.
- **SP8 — Integer IDR & tanpa angka di kamus:** kolom `DECIMAL(15,2)` dipertahankan demi keseragaman skema tetapi seluruh aritmetika `(int)` (M8); peluang/EV dihitung basis-point integer; kamus bahasa tidak memuat nominal (L6).
- **SP9 — Pemisahan bahasa:** member dwibahasa EN≡ID 1:1 (669/669); panel admin `/admin/lucky-spin` 100% Indonesia (L1) dan tidak pernah memanggil `i18n_apply()`.
- **SP10 — Motion aman:** animasi roda transform-only + `prefers-reduced-motion: reduce` → tanpa transisi (hasil langsung tampil).
- **SP11 — Tanpa perubahan inti:** nol perubahan `system/`, nol dependency baru, `views/templates/header.php` dan `bottom_nav.php` tidak disentuh (plan/100 + Golden 5 tetap).
- **SP12 — Atomisitas grant:** kegagalan grant di dalam `checkout_rental()` → `Spin_model` mengembalikan `false` → pemanggil rollback seluruh TX (tidak ada grant parsial, tidak ada counter tanpa baris grant).
