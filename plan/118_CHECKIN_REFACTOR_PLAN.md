# PLAN 118 — DOMPET ABSEN (CHECK-IN BALANCE) & SYARAT SEWA AKTIF

- **Branch:** `feat/118-checkin-refactor` (roadmap rule 4: satu branch per fase)
- **Status:** PLAN — disetujui owner; eksekusi menunggu perintah lanjutan
- **Basis keputusan owner:** `dec-945aaa1f5547c192`
- **Tipe perubahan:** skema (+1 kolom `users`, +1 kolom `wallet_ledger`, +1 key
  `system_settings`) + mesin absensi + mesin checkout (split payment) + admin
  (1 toggle + 2 metrik) + i18n (11 key). **Tanpa tabel baru** (inventory kanonik
  tetap 14), **tanpa entri `routes.php`**, **tanpa perubahan `system/`**,
  **tanpa perubahan `views/templates/header.php`**.

---

## 0. Ringkasan, Keputusan & Invarian

Absensi harian (plan/112) saat ini mengkredit **saldo utama** — bonus bisa
langsung ditarik begitu user punya riwayat sewa berbayar (plan/114). Plan/118
memisahkan ekonomi bonus:

1. **Syarat sewa aktif** (`checkin_require_active_rental`, default OFF) —
   absensi hanya dapat diklaim bila user memiliki **≥ 1 kontrak** dengan
   `status = 'active'` **dan** `expired_at > now WIB` (trial **maupun**
   berbayar).
2. **Dompet kedua** `users.checkin_balance` — bonus absensi masuk ke sini,
   **tidak dapat ditarik**, hanya dapat **mensubsidi checkout** sewa.
3. **Split payment** di checkout: `checkin_balance` dipakai lebih dulu, sisa
   dari `balance` utama — dua baris debit ledger terpisah, di dalam **satu** TX
   terkunci.
4. **Audit dompet** lewat `wallet_ledger.wallet_type` ENUM('main','checkin').

### 0.1 Keputusan owner (dikonfirmasi, `dec-945aaa1f5547c192`)

| # | Keputusan owner |
|---|---|
| **D9** | `checkin_balance` **system-only**: hanya bertambah dari absensi dan berkurang dari checkout. **Tidak ada** jalur admin inject/adjust (`Admin_model::inject_balance` tetap `main`). |
| **D10** | Widget absensi saat syarat tidak terpenuhi → **kartu tetap dirender dalam state TERKUNCI** (tombol `disabled` + hint + CTA ke `/marketplace`); endpoint tetap menolak (`code: 'no_active_rental'`). |
| **D11** | **Header Balance Pill tetap saldo utama saja** — invarian plan/100 ("exactly 3 groups") utuh, `views/templates/header.php` tidak disentuh. Saldo absen ditampilkan di `/wallet` + di dalam widget absensi. |

### 0.2 Keputusan desain turunan (D1–D8, D12–D14)

| # | Keputusan | Alasan |
|---|---|---|
| **D1** | Dua dompet: `main` (dapat ditarik) & `checkin` (subsidi, **tidak** dapat ditarik). Penarikan tetap aman **tanpa perubahan kode**: `Wallet_model::create_withdrawal()` memakai `lock_and_get_balance()` yang (setelah plan/118) hanya melihat `main`. | Nol risiko di jalur uang paling sensitif. |
| **D2** | Predikat syarat absensi = `Rental_model::has_active_rental()` yang **sudah ada** (`status='active' AND expired_at > ?` WIB) — trial ikut, sesuai permintaan owner. | Reuse choke-point; tidak ada predikat baru yang perlu disinkronkan. |
| **D3** | **Tanpa backfill**: seluruh baris `wallet_ledger` lama memperoleh `wallet_type = 'main'` lewat DEFAULT. Bonus absensi historis tetap berada di saldo utama. | Permintaan eksplisit owner. |
| **D4** | `wallet_ledger.wallet_type ENUM('main','checkin') NOT NULL DEFAULT 'main'`; `uk_wallet_ledger_user_tx_type` **tidak diubah**; **tanpa index baru**. | DEFAULT memberi backfill gratis; uk tetap menjamin idempotensi harian; baris per user sudah dibatasi `idx_user_id`. |
| **D5** | Urutan split: **checkin dulu**, sisa dari main. Keduanya lewat `_post()` yang sama, masing-masing satu baris ledger. | Permintaan owner; dua baris = jejak audit jelas. |
| **D6** | ID ledger **saat split**: `RENT-{product_id}-{YmdHis}-CHK` + `-MAIN`. **Tanpa split**: ID legacy `RENT-{product_id}-{YmdHis}` byte-identik dengan kondisi hari ini. | **Koreksi teknis:** `rental_id` belum ada saat debit (debit mendahului insert kontrak), sehingga pola `RENT-{rental_id}-MAIN` tidak implementable. ID legacy dipertahankan agar histori tidak bergeser. |
| **D7** | `user_rentals.purchase_price` = **harga penuh**. Subsidi **tidak** mengurangi harga. | ROI harian, rebate L1–L3, dan omzet upline tetap dihitung dari harga penuh — tidak ada efek samping ekonomi. Subsidi = alokasi pembayaran, bukan diskon. |
| **D8** | Trial gratis (Rp 0) mengabaikan checkbox (`log_message('warning')` bila tetap dikirim). | Tidak ada nilai yang dapat disubsidi. |
| **D12** | Seluruh kredit non-checkout (ROI, deposit, rebate, gaji mingguan, bonus L1, penarikan) tetap `main` — parameter `$wallet_type` **opsional** dengan default `'main'`. | Kompatibilitas 100% untuk seluruh pemanggil existing (±20 titik). |
| **D13** | Metrik admin: `total_balances` treasury = **main** (liabilitas yang dapat ditarik, menjaga `is_critical` & tren historis) + metrik informasi baru `total_checkin_balances`. | Saldo absen tetap terlihat tanpa mengubah arti KPI lama. |
| **D14** | Deskripsi kredit absensi tetap `Bonus Absensi Harian Hari ke-{N}` (kamus `ledger_checkin` sudah ada). | Nol key baru pada jalur absensi. |

### 0.3 Invarian baru (didaftarkan di `docs/2_ERD.md` §7 — E14)

- **CK1** — Penulis tunggal uang tetap `Wallet_model::_post()` (kini
  ber-parameter `wallet_type`); `users.balance` & `users.checkin_balance`
  **hanya mirror** non-otoritatif.
- **CK2** — Saldo otoritatif per dompet = `SUM(credit) − SUM(debit)
  WHERE wallet_type = ?`.
- **CK3** — `wallet_type = 'checkin'` **tidak pernah** masuk jalur penarikan
  (`create_withdrawal` → `main`).
- **CK4** — **Satu** anchor lock `SELECT … FROM users … FOR UPDATE`
  menserialisasi **kedua** dompet (satu baris `users` = satu kunci).
- **CK5** — Split payment = 2 baris debit dengan `transaction_id` berbeda,
  dijamin `uk_wallet_ledger_user_tx_type (user_id, transaction_id, type)`.
- **CK6** — Subsidi **tidak pernah** mengurangi `purchase_price`/ROI/rebate/
  omzet upline (D7).
- **CK7** — Syarat absensi: `status='active' AND expired_at > now WIB` — trial
  **ikut** (D2).
- **CK8** — Tanpa backfill: seluruh baris pra-plan/118 = `'main'` (D3).

---

## 1. Fakta repositori yang diverifikasi (dasar desain)

| Titik | Fakta terverifikasi |
|---|---|
| `application/models/Wallet_model.php:708` | `_post($type, $user_id, $amount, $transaction_id, $description)` = **satu-satunya** penulis `wallet_ledger` + cache `users.balance` (`:728-756`). Grep `insert('wallet_ledger'` → hanya di berkas ini. |
| `Wallet_model.php:598` / `:632` | `get_balance()` = `Σcredit − Σdebit` **tanpa** filter dompet; `lock_and_get_balance()` = anchor `users FOR UPDATE` **lalu** satu agregat (read view dibuat setelah lock wait — C5/plan48 §3.1). |
| `Wallet_model.php:1218` | `create_withdrawal()` memakai `lock_and_get_balance()` → begitu helper difilter `main`, saldo absen **otomatis** tidak dapat ditarik (D1). |
| `Wallet_model.php:1064-1067` | `get_ledger_history()` = `$this->db->get('wallet_ledger')` (seluruh kolom) → kolom `wallet_type` langsung tersedia untuk chip UI, tanpa perubahan model. |
| `Checkin_model.php:222-268` | `claim()`: TX → anchor `users FOR UPDATE` → cek `is_banned` → `_streak_next()` → nominal → `UPDATE users SET checkin_streak/checkin_last_date` **kondisional** (`affected_rows() === 1`) → `Wallet_model::credit()` `CHK-{uid}-{Ymd}` → commit. `db_debug` dimatikan lokal; `$this->db->error()` dibaca **sebelum** rollback. |
| `Checkin_model.php:505-520` | `get_lifetime_claims()` = `COUNT(*) WHERE transaction_id LIKE 'CHK-%'` — **tanpa** filter dompet, sehingga tetap benar untuk histori `main` (D3). |
| `Checkin_model.php:28-31, 84-92, 546-556` | `BASE_MAX`/`MAX_MAX`, `_resolve_config()` (pola validasi per-key + `log_message`), `_reward_for()` (integer murni). |
| `Rental_model.php:104-273` | `checkout_rental($user_id, $product)`: ① `lock_and_get_balance` → ② GATE 0 snapshot produk → ②b klasifikasi `$free_trial` → ③ GATE 2 kuota → ④ tolak overspend → ⑤ `debit()` → ⑥ insert kontrak → ⑦ `_distribute_rebate($user_id, $price, $rental_id)`. |
| `Rental_model.php:217` | ID debit existing `'RENT-'.(int) $product['id'].'-'.date('YmdHis')` — **ditulis, tidak pernah diparse** (grep `RENT-` → 1 hit, hanya penulis). Hanya prefix `CHK-%` yang di-scan kode. |
| `Rental_model.php:398-407` | `has_active_rental()` = `status='active' AND expired_at > ?` (bound param PHP WIB) — predikat kanonik syarat absensi (D2/CK7). |
| `database.sql:227-241` | `wallet_ledger` + `uk_wallet_ledger_user_tx_type (user_id, transaction_id, type)` + `idx_user_id(type)`; `users.balance` di `:17`; blok seed `checkin_*` di `:431-439`; seed kembar `database_seed.sql:337-340`. |
| `Admin_model.php:185-190` | Subquery agregat ledger untuk kolom `balance` di daftar user (tanpa filter dompet). |
| `Admin_model.php:296-308` | `get_user_balance()` = dua `select_sum` atas `wallet_ledger` (tanpa filter dompet). |
| `Admin_model.php:1237-1243` | `get_treasury_stats()`: `total_balances` = agregat seluruh ledger (dipakai `is_critical`). |
| `Admin_model.php:1446-1458` | `get_user_xray()`: dua `select_sum` (tanpa filter dompet). |
| `Admin_model.php:1377-1379` | `get_global_analytics().total_commissions` = `SUM(amount) WHERE type='debit'` (semantik "total arus keluar debit"). |
| `Admin_model.php:830` | `inject_balance()` memakai `lock_and_get_balance` → tetap `main` (D9). |
| `Admin_model.php:1308-1319` | `update_system_settings()` tanpa whitelist key → key baru otomatis tersimpan + teraudit (`admin_update_settings`). |
| `scripts/reconcile_balances.php:124-140, 229-232` | Membandingkan `users.balance` dengan agregat ledger **tanpa** filter dompet → **setiap kredit absensi baru akan terdeteksi palsu sebagai drift** pasca-plan/118. Wajib diperbaiki (E11). |
| `views/home/index.php:258-478` | Widget absensi: guard `!empty($checkin['enabled'])`, `window.SYNAPSE_I18N.hm112_*`, `paint(status)`, handler `POST checkin/claim`, tombol `#hm112-claim` + `#hm112-btn-label`. |
| `views/marketplace/index.php:135-140, 167-186, 190-264` | Tombol `.btn-sewa` (`data-id/name/price`), modal bottom-sheet dengan `userBalance`/`IDR.format`, gating `userBalance >= data.price`, `form_open('rentals/checkout')` + hidden `product_id`, `STR` = `json_encode(lang(...))`. |
| `views/wallet/index.php:17-66, 297-336` | TOP CARD saldo utama + tombol penarikan; LEDGER HISTORY memakai `i18n_ledger_description($row->description)`. |
| `views/admin/settings.php:442-499` | Card 6 "Absensi Harian" (100% Indonesia, L1) di dalam form finansial; `:78-86` blok state/repopulasi `checkin_*`. |
| `controllers/Admin.php:469-478, 606-610, 704-708` | Validasi `checkin_*`, injeksi config ke view GET, dan daftar repopulasi `_settings_form_state()`. |
| `controllers/Checkin.php:31-40, 88-140` | Kontrak respons (`ok`/`already_claimed`/`user_unavailable`/`disabled`/`error`/`unauthenticated`), `_message()` peta kode → kamus, `new_balance` = saldo utama. |
| `controllers/Rentals.php:64-96` | Pre-check UX `get_balance() < price` → flash `rental_err_insufficient`; otoritas di `checkout_rental()`. |
| `application/helpers/i18n_helper.php:406-476` | `i18n_ledger_description()` = peta pola regex → key kamus (perlu +1 pola baru). |
| `application/language/{english,indonesian}/app_lang.php` | **639/639** key (kondisi setelah plan/117). |
| `docs/3_ROADMAP.md` | Memiliki seksi untuk plan/112/116/117; **plan/114 & plan/115 tidak memiliki seksi**; plan/113 hanya disebut di blok intro `:12-16`. |
| `docs/1_PRD.md:1-21`, `docs/2_ERD.md:1-24` | Konvensi catatan versi di kepala dokumen (kini v5.2). |
| `scripts/migrate_117_user_wd_lock.php` | Kontrak migrasi: `--dry-run` (default) / `--apply` / `--verify` / `--help`, loader kredensial `$active_group → 'default' → grup pertama`, idempotensi via `information_schema`, exit 0/1/2, `SET time_zone = '+07:00'`. |
| `application/logs/log-*.php` | Terdapat artefak runtime → **wajib** dibersihkan sebelum `audit_i18n_hardcoded.php` (temuan palsu). |
| Grep baseline | Tidak ada kemunculan `wallet_type` / `checkin_balance` di `application/`, `scripts/`, `docs/`, `database.sql`, `database_seed.sql` → tidak ada preseden yang perlu dipertahankan. |

---

## 2. Skema & Migrasi

### 2.1 `users` — kolom `checkin_balance` (`database.sql`)

Disisipkan di dalam `CREATE TABLE users` **tepat setelah** `balance` (`:17`)
sehingga urutan kolom live ≡ fresh install:

```sql
  `balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  -- plan/118: DOMPET KEDUA — "Saldo Absen" (checkin). Kredit HANYA dari
  -- Bonus Absensi Harian; debit HANYA dari split payment checkout sewa.
  -- TIDAK dapat ditarik: `Wallet_model::create_withdrawal()` hanya melihat
  -- dompet `main` (invarian CK3). Mirror non-otoritatif — sumber kebenaran =
  -- Σ(credit − debit) wallet_ledger dengan `wallet_type = 'checkin'` (CK2).
  -- Tanpa backfill: nilai awal seluruh baris lama = 0.00 (D3/CK8).
  `checkin_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
```

### 2.2 `wallet_ledger` — kolom `wallet_type` (`database.sql`)

```sql
  `type` ENUM('credit', 'debit') NOT NULL,
  -- plan/118: jenis dompet yang dikredit/didebit (audit multi-dompet).
  -- DEFAULT 'main' = backfill implisit seluruh baris pra-plan/118 (D3/CK8).
  -- `uk_wallet_ledger_user_tx_type (user_id, transaction_id, type)` TIDAK
  -- berubah → idempotensi harian absensi ('CHK-{uid}-{Ymd}') tetap terjaga.
  `wallet_type` ENUM('main', 'checkin') NOT NULL DEFAULT 'main',
```

**Tanpa index baru** (dokumentasikan di ERD): seluruh agregat dompet memakai
predikat `user_id = ?` yang sudah dilayani `idx_user_id`; `wallet_type` hanya
mempersempit hasil dalam rentang baris satu user. Menambah index
`(user_id, wallet_type)` hanya membebani tabel append-heavy tanpa pengurangan
baris terbaca yang berarti.

### 2.3 `system_settings` — `checkin_require_active_rental`

| Berkas | Perubahan |
|---|---|
| `database.sql:431-439` | +baris seed `('checkin_require_active_rental', '0')` dengan komentar: default **OFF**; bila `'1'`, klaim absensi menuntut ≥ 1 kontrak aktif (`status='active' AND expired_at > now WIB`, trial maupun berbayar). |
| `database_seed.sql:337-340` | +baris seed identik (runtime/seed parity). |
| `application/config/checkin_rewards.php` | +`'checkin_require_active_rental' => 0,` (fallback per-key; baris hilang/rusak ≡ `0` — fitur baru bersifat **opt-in**). |

### 2.4 Blok "MIGRASI LIVE" baru di `database.sql` (pola `:795-814`)

Catatan operator berisi:

```sql
--   ALTER TABLE `users`
--        ADD COLUMN `checkin_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `balance`;
--   ALTER TABLE `wallet_ledger`
--        ADD COLUMN `wallet_type` ENUM('main','checkin') NOT NULL DEFAULT 'main' AFTER `type`;
--   INSERT INTO `system_settings` (`key_name`,`key_value`)
--        VALUES ('checkin_require_active_rental','0')
--        ON DUPLICATE KEY UPDATE `key_name` = `key_name`;
--
-- Rollback manual (hanya bila benar-benar perlu):
--   ALTER TABLE `wallet_ledger` DROP COLUMN `wallet_type`;
--   ALTER TABLE `users` DROP COLUMN `checkin_balance`;
--
-- ⚠️ URUTAN RILIS: jalankan migrasi ini SEBELUM deploy kode plan/118 —
--    `Wallet_model::_post()` (kolom cache), `Checkin_model` (dompet checkin),
--    `Rental_model::checkout_rental()` (split debit), dan seluruh agregat
--    `Admin_model` membaca kolom baru. Kolom yang belum ada = error query.
```

### 2.5 `scripts/migrate_118_checkin_refactor.php` (BARU)

Loader kredensial **pola tetap** `$active_group → 'default' → grup pertama`
(`scripts/migrate_106/112/114/117_*.php`) — **bukan** pola `$db['default']` yang
tercatat rusak di AGENTS.md. `mysqli_report(STRICT)`, `SET time_zone = '+07:00'`,
`BASEPATH` dummy, `date_default_timezone_set('Asia/Jakarta')`.

| Fase | Isi |
|---|---|
| 1 PRE-FLIGHT | tabel `users`/`wallet_ledger`/`system_settings` ada; kolom anchor `users.balance` & `wallet_ledger.type` ada (dipakai klausa `AFTER`); koneksi TCP siap. |
| 2 INSPECT | keberadaan 2 kolom + 1 key; jumlah baris `users`; jumlah baris ledger per `wallet_type` (pra-migrasi: kolom belum ada → lapor rencana saja). |
| 3 DDL | 2 × `ADD COLUMN` dijaga `information_schema` (idempoten; MySQL 8 tidak punya `ADD COLUMN IF NOT EXISTS`). |
| 4 SEED | `INSERT INTO system_settings … ON DUPLICATE KEY UPDATE key_name = key_name` — nilai yang sudah ada **tidak ditimpa** (tamper-safe). |
| 5 NORMALIZE | `UPDATE wallet_ledger SET wallet_type = 'main' WHERE wallet_type IS NULL` (defensif; harapan 0 baris). **Tanpa** backfill `users.checkin_balance` (tetap `0.00`, D3). |
| 6 GUARD | tipe/nullability/default kedua kolom + tipe ENUM tepat `enum('main','checkin')` + key `checkin_require_active_rental` ada; pelanggaran = tamper → **exit 2**. |
| 7 VERIFY | ulangi fase 6 secara read-only. |

Kontrak CLI: `--dry-run` (default) / `--apply` / `--verify` / `--help|-h`;
exit **0** bersih/no-op, **1** pre-flight gagal, **2** apply/verify gagal.
**Tidak pernah** menulis `wallet_ledger`, `users.balance`, `withdrawals`,
`user_rentals`, atau `bank_accounts`.

---

## 3. Model — Choke-point

### 3.1 `application/models/Wallet_model.php`

```php
const WALLET_MAIN    = 'main';
const WALLET_CHECKIN = 'checkin';
/** Whitelist dompet → kolom cache di `users` (satu sumber pemetaan). */
private static $_wallet_columns = [
    'main'    => 'balance',
    'checkin' => 'checkin_balance',
];
```

1. **`_post($type, $user_id, $amount, $transaction_id, $description, $wallet_type = self::WALLET_MAIN)`**
   - Validasi `$wallet_type` terhadap whitelist `$_wallet_columns`: nilai asing →
     `log_message('error')` + **`return false`** (fail-closed — **tidak**
     diam-diam dialihkan ke `main`, membuat kesalahan pemetaan terlihat).
   - Insert ledger menyertakan `'wallet_type' => $wallet_type`.
   - Update cache: `$col = self::$_wallet_columns[$wallet_type];`
     `"UPDATE users SET {$col} = {$col} {$sign} ? WHERE id = ?"` — interpolasi
     nama kolom **hanya** dari map hardcoded (bukan input pengguna), pola aman
     yang sama dengan `$sign` hari ini. `affected_rows() === 1` tetap wajib.
   - Guard amount `(int) > 0` tidak berubah (menolak 0/negatif/pecahan — dasar
     panggilan di-gate `if (… > 0)` pada split payment).
2. `credit(...)` / `debit(...)` → **+1 parameter opsional** `$wallet_type`
   (default `main`), diteruskan ke `_post()`. (D12)
3. `get_balance($user_id, $wallet_type = self::WALLET_MAIN)` → `… WHERE user_id = ?
   AND wallet_type = ?` (tetap `(int)`, tetap `COALESCE(…, 0)`).
4. `get_checkin_balance($user_id)` (**baru**) → sugar `get_balance($id, self::WALLET_CHECKIN)`.
5. `lock_and_get_balances($user_id)` (**baru — implementasi tunggal**) →
   anchor `SELECT id FROM users WHERE id = ? FOR UPDATE` sebagai **statement
   pertama**, lalu **satu** agregat dua-dompet:
   ```sql
   SELECT
     COALESCE(SUM(CASE WHEN wallet_type = 'main'    AND type = 'credit' THEN amount ELSE 0 END), 0)
   - COALESCE(SUM(CASE WHEN wallet_type = 'main'    AND type = 'debit'  THEN amount ELSE 0 END), 0) AS main_balance,
     COALESCE(SUM(CASE WHEN wallet_type = 'checkin' AND type = 'credit' THEN amount ELSE 0 END), 0)
   - COALESCE(SUM(CASE WHEN wallet_type = 'checkin' AND type = 'debit'  THEN amount ELSE 0 END), 0) AS checkin_balance
     FROM wallet_ledger WHERE user_id = ?
   ```
   Return `['main' => int, 'checkin' => int]` atau `false` bila baris `users`
   tidak ada. Invarian C5 dipertahankan utuh (lock wait → consistent read
   pertama) dan **satu** kunci kini menserialisasi kedua dompet (CK4).
6. `lock_and_get_balance($user_id, $wallet_type = self::WALLET_MAIN)` →
   **wrapper tipis** atas `lock_and_get_balances()`: mengembalikan nilai tipe yang
   diminta, atau `false`. **Nol duplikasi logika**; seluruh pemanggil existing
   (`create_withdrawal`, `approve_deposit`, `inject_balance`,
   `checkout_rental`) tidak berubah perilaku — dan otomatis menjadi
   **main-only** (mewujudkan CK3).
7. `get_wallet_totals($user_id)` (**baru**, read-only) → `['main' => int,
   'checkin' => int]` dalam **satu** query (tanpa lock) untuk halaman yang butuh
   keduanya (marketplace, wallet, home).
8. Docblock seksi C4 (`:655-674`) diperbarui: tambahkan dimensi `wallet_type`,
   aturan CK2/CK4, dan penegasan grep-guard "tidak ada `insert('wallet_ledger'`
   / `UPDATE users SET balance|checkin_balance` di luar berkas ini".

### 3.2 `application/models/Checkin_model.php`

1. `get_config()` → +`'require_active_rental' => bool`. Resolusi mengikuti pola
   `checkin_enabled`: `'0'`/`'1'` sah; nilai asing → `log_message('error')` +
   fallback config (tidak pernah fatal). Cache per-request `self::$_cfg` tetap.
2. `validate_checkin_settings()` → +`'checkin_require_active_rental' =>
   (isset($raw[...]) && !empty($raw[...])) ? '1' : '0'` (semantik checkbox;
   **tidak ada** error baru — semua nilai sah). Pesan tetap 100% Indonesia (L1).
3. `get_status($user_id)` → tambahan read-only:
   - `require_active_rental` (bool, dari config),
   - `has_active_rental` (bool) — `$this->load->model('Rental_model')` lazily
     (pola `Checkin_model::claim()` memuat `Wallet_model`), memanggil
     `Rental_model::has_active_rental($user_id)`,
   - `checkin_balance` (int) — `Wallet_model::get_balance($user_id,
     Wallet_model::WALLET_CHECKIN)`,
   - `can_claim` = `enabled && !claimed_today && (!require_active_rental ||
     has_active_rental)`.
   - **Precedence:** `claimed_today` **selalu menang** atas
     `has_active_rental` (reload pada hari yang sama menampilkan "sudah klaim",
     bukan "terkunci").
   - Biaya: 1 query tambahan per render dashboard (PK lookup + 1 agregat
     terindeks) — dapat diterima, dicatat di ringkasan.
4. `claim($user_id)` — urutan di dalam TX (hanya **dua** titik berubah):
   - ① anchor `SELECT … FROM users … FOR UPDATE` (tetap) → cek `is_banned`
     (tetap) → `$fail('user_unavailable', …)`.
   - ② `_streak_next()` → bila `null` → `$fail('already_claimed', …)`
     (**tetap sebelum gate baru**, agar klik ganda tidak mengubah pesan).
   - ③ **BARU — gate syarat sewa** (`require_active_rental` pada config):
     ```php
     if ($cfg['require_active_rental']
         && !$this->Rental_model->has_active_rental($user_id)) {
         $this->db->trans_rollback();
         return $fail('no_active_rental',
             'Anda perlu minimal 1 sewa aktif untuk mengklaim bonus ini.');
     }
     ```
     Diletakkan **di dalam** TX setelah lock wait (read view segar → admin/trial
     expiry yang terjadi bersamaan tidak dapat ditembus).
   - ④ nominal `_reward_for()` (tetap, `< 1` → `$fail('error', …)`).
   - ⑤ `UPDATE users SET checkin_streak = ?, checkin_last_date = ? WHERE id = ?
     AND (checkin_last_date IS NULL OR checkin_last_date < ?)` + guard
     `affected_rows() === 1` (M4, tetap).
   - ⑥ **kredit dompet checkin**: `$this->Wallet_model->credit($user_id,
     $reward, $tx_id, $description, Wallet_model::WALLET_CHECKIN)`.
     `transaction_id` **tidak berubah** (`CHK-{uid}-{Ymd}`) → `uk (user_id,
     transaction_id, type)` tetap menjamin maksimum satu kredit per user per
     hari; jalur baca `$this->db->error()` **sebelum** rollback dan translasi
     duplikat `1062`/`23000` → `already_claimed` dipertahankan apa adanya.
   - ⑦ Commit → hasil + `'wallet_type' => Wallet_model::WALLET_CHECKIN` dan
     `'new_checkin_balance' => get_checkin_balance($uid)` (kontrak **tambahan**,
     bukan pengganti).
5. Kontrak return `claim()` tetap `{success, code, message, reward, streak,
   transaction_id}` (+2 key baru); `code` baru: `'no_active_rental'`.
6. `get_lifetime_claims()` **tidak berubah** (prefix `CHK-%` mencakup baris
   historis `wallet_type='main'` maupun baru `'checkin'`).

### 3.3 `application/models/Rental_model.php::checkout_rental($user_id, $product, $use_checkin = false)`

Nomor langkah existing dipertahankan agar audit lama tetap menjadi rujukan.

1. **① Kunci + saldo (berubah)**
   ```php
   $balances = $this->Wallet_model->lock_and_get_balances($user_id);
   if ($balances === false) { trans_rollback(); return code 'error'; }
   $fresh_balance     = $balances['main'];
   $checkin_available = $balances['checkin'];
   ```
2. ② GATE 0 (snapshot produk, `p.is_trial`) — **tidak berubah**.
3. ②b klasifikasi `$free_trial` — **tidak berubah**; bila
   `$free_trial && $use_checkin` → `log_message('warning')` + `$use_checkin = false` (D8).
4. ③ GATE 2 kuota (`IN ('active','completed') AND source <> 'promoter_reward'`,
   `max_per_user`) — **tidak berubah**.
5. **④ Matematika + tolak overspend STRICT (berubah)**
   ```php
   $checkin_used = 0;
   $main_used    = $price;

   if (!$free_trial && $use_checkin) {
       $checkin_used = min($checkin_available, $price);   // integer murni
       $main_used    = $price - $checkin_used;
   }

   if (!$free_trial && ($checkin_used + $main_used) !== $price) { rollback; code 'error'; }
   if (!$free_trial && $main_used > $fresh_balance)             { rollback; code 'insufficient'; }
   ```
   Seluruh aritmetika integer IDR (M8) — tanpa float, tanpa pembagian;
   `$price`, `$checkin_available`, `$fresh_balance` semuanya `(int)`.
6. **⑤ Debit (berubah)** — checkin **dulu**, lalu main; masing-masing di-gate
   `> 0` karena `_post()` menolak amount ≤ 0:
   ```php
   $base = 'RENT-' . (int) $product['id'] . '-' . date('YmdHis');   // ID legacy (D6)

   if ($checkin_used > 0) {
       $ok = $this->Wallet_model->debit(
           $user_id, $checkin_used, $base . '-CHK',
           'Sewa ' . $product['name'] . ' (dari Saldo Absen)', Wallet_model::WALLET_CHECKIN);
       if (!$ok) { trans_rollback(); return code 'error'; }
   }
   if ($main_used > 0) {
       $ok = $this->Wallet_model->debit(
           $user_id, $main_used,
           ($checkin_used > 0 ? $base . '-MAIN' : $base),      // tanpa split ≡ ID legacy
           'Sewa ' . $product['name'], Wallet_model::WALLET_MAIN);
       if (!$ok) { trans_rollback(); return code 'error'; }
   }
   ```
   Kegagalan salah satu → rollback penuh (**nol** baris ledger, **nol** kontrak).
7. ⑥ Insert kontrak — `purchase_price = $price` **penuh** (D7/CK6), `daily_roi`,
   `total_days`, `status='active'`, `expired_at` — **tidak berubah**.
8. ⑦ `_distribute_rebate($user_id, $price, $rental_id)` — **tetap harga penuh**
   (rebate & omzet upline tidak terpengaruh subsidi).
9. Return +`'checkin_used' => (int)`, `'main_used' => (int)`; kontrak lama
   `{success, code, message, rental_id}` dan `max` (plan/114) utuh.
10. Docblock `checkout_rental()` (:56-100) +penjelasan langkah split & D6/D7.

#### 3.3.1 Matriks matematika split (bukti kebenaran)

| Kasus | harga | checkin | main | hasil | baris ledger |
|---|---|---|---|---|---|
| Toggle OFF (regresi) | 1.000.000 | 300.000 | 800.000 | `checkin_used=0`, `main_used=1.000.000` | `RENT-{pid}-{ts}` (legacy, byte-identik) |
| Split normal | 1.000.000 | 300.000 | 800.000 | `300.000` + `700.000` | `…-CHK`, `…-MAIN` |
| Checkin menutup seluruh harga | 500.000 | 500.000 | 0 | `500.000` + `0` | **hanya** `…-CHK` |
| Checkin melebihi harga | 500.000 | 900.000 | 0 | `500.000` + `0` (tanpa sisa/refund) | hanya `…-CHK` |
| Saldo gabungan kurang | 1.000.000 | 100.000 | 200.000 | rollback `insufficient` | **nol** |
| Trial gratis | 0 | apa pun | 0 | jalur bebas, checkbox diabaikan (D8) | **nol** |

### 3.4 `application/models/Admin_model.php` — 5 situs agregat (wajib, D13)

| Lokasi | Perubahan |
|---|---|
| `get_users()` `:185-190` | subquery → `FROM wallet_ledger WHERE wallet_type = 'main' GROUP BY user_id` → kolom `balance` di daftar user tetap berarti **saldo utama**. |
| `get_user_balance()` `:296-308` | `->where('wallet_type','main')` pada kedua `select_sum`; **+** metode baru `get_user_checkin_balance($id)`. |
| `get_user_xray()` `:1446-1458` | kedua `select_sum` difilter `main`; nilai return +`'checkin_balance'`. |
| `get_treasury_stats()` `:1237-1243` | `total_balances` difilter `main` (liabilitas dapat ditarik; `is_critical` tetap konservatif) **+** agregat baru `total_checkin_balances` (informasi sirkulasi bonus). |
| `get_global_analytics()` `:1377-1379` | **tidak** difilter — semantik aslinya "total arus keluar (debit)"; debit absensi juga arus keluar. Diberi komentar eksplisit agar tidak dianggap kelalaian. |

Grep-guard E16: setiap `FROM wallet_ledger` di `Admin_model` harus punya
keputusan filter yang terdokumentasi (komentar).

---

## 4. UI/UX & Admin

### 4.1 `views/marketplace/index.php` — checkbox split di modal checkout

- Tombol `.btn-sewa` (`:135-140`) + atribut `data-trial="<?= (int) ($product['is_trial'] ?? 0) ?>"`
  (dipakai JS untuk menyembunyikan baris checkbox pada produk trial).
- Di dalam `<form id="form-checkout">` (`:183-186`), **sebelum** `#modalActionBtn`,
  sisipkan baris checkbox (awalnya `hidden`, di-toggle JS — memakai markup
  Tailwind existing, tanpa JS baru selain handler):
  ```html
  <label id="modal-checkin-row" for="modal_use_checkin"
         class="hidden items-center gap-2 mb-3 u-card-inset rounded-xl p-3 cursor-pointer select-none">
      <input type="checkbox" id="modal_use_checkin" name="use_checkin_balance" value="1"
             class="rounded border-slate-300">
      <span class="text-xs font-semibold u-text"><?= lang('market_checkin_use_label') ?></span>
      <span id="modal-checkin-amount" class="ml-auto text-xs font-bold text-cyan-600 dark:text-cyan-400">
          <?php /* L6: nominal = ARGUMEN, bukan isi kamus. */ ?>
          <?= sprintf(lang('market_checkin_available'), 'Rp ' . number_format((int) $checkin_balance, 0, ',', '.')) ?>
      </span>
  </label>
  <p id="modal-split-note" class="hidden text-[11px] u-muted mb-3"></p>
  ```
- Header modal (`:167-181`): +baris "Saldo Absen" dengan `id="modalCheckinBalance"`.
- JS:
  - `var checkinBalance = <?= (int) $checkin_balance ?>;`
  - `openModal(data)`: tampilkan `#modal-checkin-row` bila `data.price > 0 &&
    data.trial === 0 && checkinBalance > 0`; reset checkbox ke unchecked.
  - handler `change` pada `#modal_use_checkin` →
    `available = userBalance + (checked ? Math.min(checkinBalance, data.price) : 0)`;
    klasifikasi emerald/rose pada `#modalCheckinBalance` **dan** `#modalActionBtn`
    mengikuti `available >= data.price`; baris rincian split dirender dari
    `STR.splitNote` (`market_checkin_split_note`) via `IDR.format`.
  - `closeModal()` me-reset checkbox + menyembunyikan baris/rincian.
  - Teks JS memakai pola `json_encode(lang(...))` yang sudah ada di berkas ini
    (`STR.confirmPay`, `STR.insufficient`).

### 4.2 `controllers/Rentals.php::checkout()`

- Baca flag: `$use_checkin = ($this->input->post('use_checkin_balance') === '1');`
- Pre-check UX (`:70-77`) diperluas:
  ```php
  $available = $this->Wallet_model->get_balance($user_id)
             + ($use_checkin ? $this->Wallet_model->get_checkin_balance($user_id) : 0);
  if ($available < (int) $product['price']) { flash 'rental_err_insufficient'; redirect('marketplace'); }
  ```
- Panggil `$this->Rental_model->checkout_rental($user_id, $product, $use_checkin);`
- Flash sukses opsional menyebut komposisi pembayaran dari
  `$result['checkin_used']`/`$result['main_used']` (dibangun dengan `sprintf` +
  argumen; L6).
- `_checkout_message()` tidak berubah (kode hasil tidak bertambah).

### 4.3 `views/wallet/index.php` + `controllers/Wallet.php`

- Kartu baru **"Saldo Absen"** disisipkan setelah TOP CARD (`:66`): judul
  `wallet_checkin_title`, ikon `fas fa-calendar-check`, nominal
  `Rp number_format($checkin_balance, 0, ',', '.')`, catatan
  `wallet_checkin_note` ("tidak dapat ditarik"). **Non-interaktif** — tanpa
  tombol tarik/topup.
- LEDGER HISTORY (`:315-333`): chip kecil `wallet_ledger_chip_checkin` bila
  `$row->wallet_type === 'checkin'` (kolom sudah tersedia tanpa perubahan model).
- `Wallet::index()` (`:134-158`): +`'checkin_balance' => $this->Wallet_model->get_checkin_balance($user_id)`.
- Halaman `wallet/withdraw` **tidak** menampilkan saldo absen (bukan sumber dana
  penarikan — CK3).

### 4.4 `views/home/index.php` — state terkunci + saldo absen

- Di dalam kartu absensi (`:283-350`): tambahkan baris **Saldo Absen**
  (label `home_checkin_balance_label` + nominal server-formatted dari
  `$checkin['checkin_balance']`), dan atribut pada `#hm112-card`:
  `data-require-rental="<?= !empty($checkin['require_active_rental']) ? '1' : '0' ?>"`
  + `data-has-rental="<?= !empty($checkin['has_active_rental']) ? '1' : '0' ?>"`.
- **State terkunci (D10)** — bila `require_active_rental` aktif dan
  `has_active_rental` false:
  - `#hm112-claim` `disabled` + ikon `fa-lock` + label dari
    `home_checkin_need_rental_hint`,
  - CTA `<a href="<?= base_url('marketplace') ?>">` berlabel
    `home_checkin_cta_rent`,
  - tanpa kelas `hm112-pulse`.
- JS `paint(status)` (`:400-430`) diperluas:
  `var locked = !!status.require_active_rental && !status.has_active_rental;`
  → `btn.disabled = claimed || locked;` kelas pulse hanya bila `!claimed &&
  !locked`; label mengikuti state; nominal saldo absen diperbarui.
  Handler klik: `d.code === 'no_active_rental'` → `showToast` + `paint(d.status)`
  (memanfaatkan kontrak `status` segar pada respons penolakan, plan/112).
- `window.SYNAPSE_I18N` (`:273-280`): +`hm112_need_rental`, `hm112_locked_btn`.
- **Tidak menyentuh** `templates/header.php` (invarian plan/100, D11).

### 4.5 Panel Admin

| Berkas | Perubahan |
|---|---|
| `views/admin/settings.php:471-477` | Baris checkbox kedua di bawah "Aktifkan Absensi Harian": `id/name="checkin_require_active_rental" value="1"` + label **"Wajib Punya Sewa Aktif untuk Klaim"** + teks bantuan "Kontrak harus berstatus aktif dan belum kedaluwarsa (trial maupun berbayar)." — 100% Indonesia (L1), tanpa key i18n. |
| `views/admin/settings.php:78-86` | `$checkin_require_state` mengikuti pola `$checkin_enabled_state` (termasuk `array_key_exists('checkin_require_active_rental', $fs)` untuk repopulasi). |
| `controllers/Admin.php:469-478` | +`'checkin_require_active_rental' => $this->input->post('checkin_require_active_rental')` ke `$checkin_raw`. |
| `controllers/Admin.php:704-708` | `_settings_form_state()` +baris repopulasi `checkin_require_active_rental` (`(string) $this->input->post(...)`). |
| `controllers/Admin.php:606-610` (GET) | +`$data['checkin_require_active_rental'] = $checkin_cfg['require_active_rental'] ? 1 : 0;` |
| `views/admin/dashboard.php` | Kartu treasury: baris **"Saldo Absen (sirkulasi)"** dari `total_checkin_balances` (D13). |
| `views/admin/user_detail.php` | Baris **Saldo Absen** (read-only) di kartu akun — tanpa aksi admin (D9). |
| Audit | Tanpa aksi baru: key baru otomatis tercatat di `admin_update_settings` (`keys`/`before`/`after`). |

### 4.6 `scripts/reconcile_balances.php` (WAJIB — mencegah drift palsu)

- Query drift (`:119-140`): subquery agregat ledger +`WHERE wallet_type = 'main'`
  (dibandingkan dengan `u.balance`).
- **+** kelas drift kedua: `u.checkin_balance <> Σ(wallet_type='checkin')`.
- Repair (`:229-232`): `… WHERE user_id = ? AND wallet_type = 'main'` untuk
  `balance`; **+** statement paralel untuk `checkin_balance`.
- Ringkasan/`--json`/exit code: dua kelas drift dilaporkan terpisah agar output
  tetap terbaca; `--verify` CLEAN hanya bila **keduanya** nol.

### 4.7 i18n — 11 key baru (639 → **650/650**)

| # | Key | EN | ID |
|---|---|---|---|
| 1 | `home_checkin_need_rental_hint` | Claim your daily bonus by keeping at least one active rental (trial or paid). | Klaim bonus harian dengan memiliki minimal 1 sewa aktif (trial atau berbayar). |
| 2 | `home_checkin_err_no_rental` | You need at least one active rental to claim this bonus. | Anda perlu minimal 1 sewa aktif untuk mengklaim bonus ini. |
| 3 | `home_checkin_cta_rent` | View GPU Plans | Lihat Paket GPU |
| 4 | `home_checkin_balance_label` | Bonus Balance | Saldo Absen |
| 5 | `market_checkin_use_label` | Use Bonus Balance | Gunakan Saldo Absen |
| 6 | `market_checkin_available` | Available: %s | Tersedia: %s |
| 7 | `market_checkin_split_note` | %s from Bonus Balance · %s from main balance | %s dari Saldo Absen · %s dari saldo utama |
| 8 | `wallet_checkin_title` | Bonus Balance | Saldo Absen |
| 9 | `wallet_checkin_note` | Usable only to rent GPU products. Cannot be withdrawn. | Hanya dapat dipakai untuk menyewa produk GPU. Tidak dapat ditarik. |
| 10 | `wallet_ledger_chip_checkin` | Bonus | Absen |
| 11 | `ledger_rent_checkin` | Rent %s (from Bonus Balance) | Sewa %s (dari Saldo Absen) |

- **Tanpa nominal/angka di kamus** (L6): setiap `%s` selalu diisi argumen
  `sprintf`/`json_encode` dari PHP atau JS.
- `application/helpers/i18n_helper.php:450-452` → +1 pola regex sebelum entri
  terakhir: `'/^Sewa (.+) \(dari Saldo Absen\)$/u' => 'ledger_rent_checkin'`.
- Baris debit `main` (`Sewa {nama produk}`) **tetap** tidak dilokalisasi —
  non-goal plan/103 yang disengaja, dipertahankan (hindari pemetaan pola
  ambigu `^Sewa …`).

---

## 5. Sinkronisasi Dokumen (`docs/`)

| Dokumen | Perubahan |
|---|---|
| `docs/1_PRD.md` | Naikkan judul menjadi **v5.3** + blok catatan "v5.3 (plan/118)" di kepala dokumen. **§B** sub-seksi baru **B.7 "Dompet Absen (`checkin_balance`) — plan/118"** (tidak dapat ditarik; hanya mensubsidi checkout). **§D** langkah checkout: langkah 1 diperluas menjadi split `checkin → main` (urutan, dua ID ledger, `purchase_price` tetap penuh). **§E** penegasan eksplisit: penarikan **hanya** dompet `main`. **§F** aturan absensi: +gate `checkin_require_active_rental` + kredit masuk `checkin_balance`. |
| `docs/2_ERD.md` | Naikkan judul menjadi **v5.3** + blok catatan plan/118. Mermaid: `DECIMAL15-2 checkin_balance` pada `users`, `ENUM wallet_type` pada `wallet_ledger`. §2 `users` + §3 `wallet_ledger`: dokumentasi kolom + rumus saldo **per dompet** + catatan "tanpa index baru, `uk` tidak berubah". §4 tabel `system_settings`: +baris key `checkin_require_active_rental`. **§7**: blok invariant baru **CK1–CK8**. |
| `docs/3_ROADMAP.md` | Seksi milestone baru **"Dompet Absen & Syarat Sewa Aktif ✅ COMPLETED (plan/118)"** + **backfill seksi yang hilang**: **plan/114 (Trial Product & WD Gate)** dan **plan/115 (Trial UX)** — keduanya belum punya seksi; +seksi ringkas **plan/113 (Doc Sync)** agar rantai 112→118 lengkap. |
| `AGENTS.md` | Entri Notes plan/118 + pembaruan deskripsi `Checkin_model`/`Wallet_model` (dua dompet, `wallet_type`) + daftar model/helper. |

---

## 6. Directives Eksekusi (E1 … E16)

| # | Langkah | Keluaran |
|---|---|---|
| **E1** | Tulis `plan/118_CHECKIN_REFACTOR_PLAN.md` (dokumen ini). | Dokumen plan |
| **E2** | Skema: `database.sql` (§2.1, §2.2, §2.3, §2.4) + `database_seed.sql` (§2.3). | DDL kanonik + seed |
| **E3** | Tulis `scripts/migrate_118_checkin_refactor.php` (§2.5) → jalankan `--dry-run` (inspeksi, tanpa tulis). | Migrasi idempoten |
| **E4** | `application/config/checkin_rewards.php` → +`checkin_require_active_rental = 0` (fallback per-key). | Fallback config |
| **E5** | `application/models/Wallet_model.php` (§3.1 lengkap): konstanta, `_post`, `credit`/`debit`, `get_balance`, `get_checkin_balance`, `get_wallet_totals`, `lock_and_get_balances`, wrapper `lock_and_get_balance`, docblock C4. | Choke-point dua dompet |
| **E6** | `application/models/Checkin_model.php` (§3.2): config, validator admin, `get_status`, gate syarat sewa, kredit dompet checkin, kontrak hasil. | Mesin absensi |
| **E7** | `application/models/Rental_model.php::checkout_rental()` (§3.3) + docblock; verifikasi matriks §3.3.1 sebagai asersi integer. | Split payment |
| **E8** | `application/controllers/Checkin.php`: peta `no_active_rental` → `home_checkin_err_no_rental`; payload sukses +`new_checkin_balance`, `wallet_type`. | Kontrak JSON |
| **E9** | `application/controllers/Rentals.php`: flag POST, pre-check gabungan, pesan sukses komposisi (§4.2). | Jalur checkout |
| **E10** | Admin: `controllers/Admin.php` (§4.5) + `views/admin/settings.php` (toggle). | Toggle admin |
| **E11** | `models/Admin_model.php` 5 situs agregat (§3.4) + `scripts/reconcile_balances.php` (§4.6) + `views/admin/dashboard.php` & `views/admin/user_detail.php`. | Metrik & rekonsiliasi |
| **E12** | Views member: `marketplace/index.php`, `wallet/index.php`, `home/index.php` (§4.1/§4.3/§4.4) + `controllers/Marketplace.php` & `controllers/Wallet.php`. | UX dua dompet |
| **E13** | i18n: 11 key EN/ID (§4.7) + pola `i18n_helper`; jalankan **kedua** gate audit. | 650/650 |
| **E14** | `docs/1_PRD.md`, `docs/2_ERD.md`, `docs/3_ROADMAP.md` (§5). | Doc sync |
| **E15** | `AGENTS.md` + tulis `plan/118_CHECKIN_REFACTOR_SUMMARY.md` (bukti verifikasi). | Change record |
| **E16** | Verifikasi menyeluruh (§7) + bersihkan artefak (`application/logs/*`, `/tmp/*`) + tinjau `git diff` final. | Bukti & DoD |

---

## 7. Matriks Verifikasi

```bash
# V1 — lint setiap berkas PHP yang baru/diubah (roadmap rule 5)
php -l application/models/Wallet_model.php
php -l application/models/Checkin_model.php
php -l application/models/Rental_model.php
php -l application/models/Admin_model.php
php -l application/controllers/Checkin.php
php -l application/controllers/Rentals.php
php -l application/controllers/Admin.php
php -l application/controllers/Wallet.php
php -l application/controllers/Marketplace.php
php -l application/views/marketplace/index.php
php -l application/views/wallet/index.php
php -l application/views/home/index.php
php -l application/views/admin/settings.php
php -l application/views/admin/dashboard.php
php -l application/views/admin/user_detail.php
php -l application/helpers/i18n_helper.php
php -l application/config/checkin_rewards.php
php -l scripts/migrate_118_checkin_refactor.php
php -l scripts/reconcile_balances.php

# V2 — gate i18n (keduanya WAJIB exit 0); bersihkan log runtime lebih dulu
rm -f application/logs/log-*.php
php scripts/audit_i18n_parity.php      # harapan: LULUS, 650/650
php scripts/audit_i18n_hardcoded.php   # harapan: 0 temuan

# V3 — migrasi pada DB throwaway (database.sql → database_seed.sql lebih dulu)
DB_HOSTNAME=127.0.0.1 DB_DATABASE=db_webtable_118 DB_USERNAME=root DB_PASSWORD=root \
  php scripts/migrate_118_checkin_refactor.php --dry-run
DB_HOSTNAME=127.0.0.1 DB_DATABASE=db_webtable_118 DB_USERNAME=root DB_PASSWORD=root \
  php scripts/migrate_118_checkin_refactor.php --apply
DB_HOSTNAME=127.0.0.1 DB_DATABASE=db_webtable_118 DB_USERNAME=root DB_PASSWORD=root \
  php scripts/migrate_118_checkin_refactor.php --apply     # re-run = no-op (idempoten)
DB_HOSTNAME=127.0.0.1 DB_DATABASE=db_webtable_118 DB_USERNAME=root DB_PASSWORD=root \
  php scripts/migrate_118_checkin_refactor.php --verify    # exit 0

# V4 — HTTP (server lokal + env override, DB throwaway; BUKAN db_webtable)
#   login → /home   : widget absensi tampil
#   require=1 & tanpa sewa aktif → kartu TERKUNCI (tombol disabled + CTA /marketplace)
#   POST /checkin/claim (AJAX) tanpa sewa → 200 {success:false, code:'no_active_rental', status:{…}}
#   checkout produk trial → POST /checkin/claim → 200 {success:true, data.wallet_type:'checkin'}
#   ulangi klaim hari yang sama → code:'already_claimed'
#   POST /rentals/checkout use_checkin_balance=1 → 302 /rentals; 2 baris debit (…-CHK, …-MAIN)
#   POST /rentals/checkout saldo gabungan kurang → flash rental_err_insufficient, 0 baris ledger
#   GET /wallet  : kartu "Saldo Absen" tampil; histori menampilkan chip "Absen"

# V5 — asersi SQL (integritas dua dompet)
#   users.checkin_balance == Σ(wallet_type='checkin')    → 0 baris drift
#   users.balance          == Σ(wallet_type='main')      → 0 baris drift
#   COUNT(*) wallet_type='checkin' AND transaction_id NOT LIKE 'CHK-%'
#            AND transaction_id NOT LIKE 'RENT-%'         → 0 (tidak ada penulis liar)
php scripts/reconcile_balances.php --verify --json        # harapan: CLEAN (kedua kelas)

# V6 — grep-guard
#   insert('wallet_ledger' / UPDATE users SET (balance|checkin_balance)  → hanya Wallet_model.php
#   FROM wallet_ledger di Admin_model.php                                → tiap situs punya filter/komentar
#   i18n_apply() di Admin*/Admin_auth                                    → 0
#   perubahan pada views/templates/header.php                            → 0 (invarian plan/100, D11)
git diff --stat   # hanya berkas pada §11
```

---

## 8. Risiko & Mitigasi

| # | Risiko | Mitigasi |
|---|---|---|
| R1 | Injeksi nama kolom cache di `_post()` | Peta whitelist hardcoded `wallet_type → kolom`; **tidak ada** string dari input; nilai asing → `false` + log. |
| R2 | Agregat admin/member terlewat → angka salah & drift palsu | Daftar **eksplisit 5 situs** (§3.4) + `reconcile_balances` diperbaiki (§4.6) + grep-guard V6. |
| R3 | Split menulis baris ledger sebagian | Kedua debit di dalam **satu** TX; `false` pada salah satu → `trans_rollback()` (nol baris). Panggilan di-gate `if (… > 0)` karena `_post()` menolak amount ≤ 0. |
| R4 | Bonus absensi menjadi "gratis ditarik" | `checkin` tidak pernah masuk `lock_and_get_balance()` yang dipakai penarikan (CK3) + V5 memastikan saldo utama tidak terkontaminasi. |
| R5 | P3/L6: angka masuk kamus | Seluruh nominal sebagai argumen `sprintf`/`json_encode`; gate parity V2 menegakkan. |
| R6 | Subsidi menaikkan ROI/rebate | `purchase_price` tetap harga penuh (D7/CK6) → rebate L1–L3 dan omzet upline dihitung dari nilai penuh. |
| R7 | **Residual (disadari):** user ber-trial-saja dapat menabung `checkin_balance` hingga cap harian selama masa trial lalu memakainya untuk menyubsidi pembelian pertama | Sesuai permintaan owner (trial ikut memenuhi syarat, D2). Bila owner ingin lebih ketat: ubah satu klausa menjadi `Rental_model::has_paid_rental()` — dicatat sebagai opsi eksplisit di PRD/ERD. |
| R8 | Regresi jalur uang lama | `$wallet_type` **opsional** default `'main'` (D12) → seluruh pemanggil `credit()`/`debit()`/`get_balance()`/`lock_and_get_balance()` berperilaku identik; V4/V5 memverifikasi. |
| R9 | Drift kolom mirror `checkin_balance` | Mirror ditulis di TX yang sama dengan ledger; `reconcile_balances` kini memeriksa **kedua** dompet (V5). |
| R10 | Migrasi dijalankan setelah deploy | Blok "MIGRASI LIVE" (§2.4) + header skrip menegaskan urutan rilis; kegagalan = error query yang jelas (bukan korupsi senyap). |

---

## 9. Non-goals

1. Tidak ada backfill bonus historis `main → checkin` (D3).
2. Tidak ada transfer antar dompet (checkin → main) dan tidak ada jalur admin
   adjust untuk `checkin_balance` (D9).
3. Tidak mengubah header/Balance Pill (D11), tidak mengubah `routes.php`
   (tidak ada URL baru), tidak menambah tabel.
4. Tidak mengubah `system/`, `uploads/`, `backups/`.
5. Tidak menyentuh alur deposit/QRIS, rebate 3-tier, gaji mingguan, program
   promotor, dan mekanisme penarikan (selain memastikan tetap `main`).
6. Baris `wallet_ledger` lama tidak dimigrasi/ditulis ulang (append-only, Z1).
7. Lokalisasi deskripsi debit `main` legacy (`Sewa {nama}`) tetap non-goal
   (konsisten dengan plan/103).

---

## 10. Definition of Done

- Setiap berkas PHP yang diubah `php -l` bersih (V1).
- Kedua gate i18n **exit 0** dengan **650/650** key (V2).
- Migrasi idempoten: `--apply` dua kali = no-op, `--verify` exit 0 (V3).
- Alur diuji over HTTP pada **DB throwaway** — bukan `db_webtable` (V4) —
  mencakup kedua state widget absensi, klaim sukses/gagal, dan split di checkout.
- `scripts/reconcile_balances.php --verify` CLEAN untuk **kedua** dompet (V5);
  grep-guard V6 bersih.
- `docs/1_PRD.md` (v5.3), `docs/2_ERD.md` (v5.3 + CK1–CK8),
  `docs/3_ROADMAP.md` (plan/118 + backfill 113/114/115), dan `AGENTS.md`
  tersinkron.
- `plan/118_CHECKIN_REFACTOR_SUMMARY.md` memuat bukti (perintah + hasil) dan
  diff final hanya berisi perubahan yang dimaksud.

---

## 11. Daftar Berkas yang Tersentuh

**Baru (3):** `plan/118_CHECKIN_REFACTOR_PLAN.md`,
`plan/118_CHECKIN_REFACTOR_SUMMARY.md`,
`scripts/migrate_118_checkin_refactor.php`.

**Diubah (23):** `database.sql`, `database_seed.sql`,
`scripts/reconcile_balances.php`, `application/config/checkin_rewards.php`,
`application/models/Wallet_model.php`, `application/models/Checkin_model.php`,
`application/models/Rental_model.php`, `application/models/Admin_model.php`,
`application/controllers/Checkin.php`, `application/controllers/Rentals.php`,
`application/controllers/Admin.php`, `application/controllers/Wallet.php`,
`application/controllers/Marketplace.php`,
`application/views/marketplace/index.php`, `application/views/wallet/index.php`,
`application/views/home/index.php`, `application/views/admin/settings.php`,
`application/views/admin/dashboard.php`, `application/views/admin/user_detail.php`,
`application/helpers/i18n_helper.php`,
`application/language/english/app_lang.php`,
`application/language/indonesian/app_lang.php`, `docs/1_PRD.md`,
`docs/2_ERD.md`, `docs/3_ROADMAP.md`, `AGENTS.md`.

**Tidak disentuh:** `application/config/routes.php`,
`application/config/autoload.php`, `application/views/templates/*`,
`system/**`.

---

## 12. Aturan Pelaporan

- Setiap langkah eksekusi (E1–E16) melaporkan **perintah yang dijalankan +
  hasil yang diamati** (AGENTS.md: "Report the verification you actually ran").
- Tidak ada klaim "test suite lulus" — repo ini tidak memiliki test suite.
- Bila ada deviasi dari plan ini saat eksekusi, catat di
  `plan/118_CHECKIN_REFACTOR_SUMMARY.md` beserta alasannya.
