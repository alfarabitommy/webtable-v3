# Plan 106 — Exclusive E-Wallet Withdrawal Gateway & Dynamic Provider Management

**Status:** PLAN ONLY — belum ada satu baris kode/skema/kamus yang diubah.
**Deliverable:** dokumen ini (`plan/106_EWALLET_WITHDRAWAL_AND_PROVIDER_MANAGEMENT_PLAN.md`).
**Scope eksekusi:** ditahan sampai ada instruksi lanjutan dari owner.

---

## 1. Goal & success criteria

Transisi tujuan penarikan dari rekening bank konvensional menjadi **khusus e-wallet** (lineup awal: DANA, ShopeePay, OVO, GoPay), dengan **provider dikelola admin secara dinamis**, binding akun berbasis nomor HP, kemampuan admin **reset/unbind**, serta perombakan terminologi dwibahasa yang tetap lolos gate paritas kamus.

Sukses = seluruh poin berikut terverifikasi (§12):

1. Tabel `ewallet_providers` ada, ter-seed idempoten, dan terkelola dari `/admin/ewallet-providers` (lihat / tambah / rename / toggle). Admin UI **100% Indonesia** tanpa key i18n (invariant **L1**).
2. Member hanya bisa mengikat **provider aktif** + nomor HP yang lolos `^08[0-9]{8,11}$`; binding tetap **immutable** bagi member; admin bisa mereset.
3. Binding bank lama dimigrasikan dengan aman (nama → provider, binding diarsipkan) tanpa memutus FK: `fk_withdrawals_bank` tidak disentuh, seluruh kartu riwayat penarikan tetap ter-render.
4. Alur penarikan (`/wallet/withdraw`, proses, riwayat wallet, dashboard/history/CSV admin) memakai terminologi e-wallet.
5. `php scripts/audit_i18n_parity.php` → exit 0 dengan **602/602** key; `php scripts/audit_i18n_hardcoded.php` → exit 0 (0 temuan).

### 1.1 Keputusan owner (sudah dikonfirmasi)

- **D1 — Binding legacy:** nama bank lama dipetakan ke provider **dan seluruh binding legacy diarsipkan** (`is_primary=0`) → member wajib re-bind. Dampak live terukur: **17/17 baris diarsipkan, 0 baris yang nomornya sudah valid** (user 1–13, 19, 40, 58, 141).
- **D2 — Mekanisme unbind:** **arsip via `is_primary=0`**; `is_primary` menjadi *flag binding aktif* (1 = terikat, 0 = arsip). Tanpa DDL, aman terhadap FK, riwayat terjaga. (`is_primary` saat ini hanya ditulis sekali saat insert dan **tidak pernah dibaca** di manapun — hasil grep — sehingga pemakaian ulang kolom ini bebas biaya.)
- **D3 — UI selector member:** **card/radio selector 2×2**, murni CSS `peer-checked:`, tanpa JS.

### 1.2 Keputusan arsitek (terdokumentasi, tanpa perlu input owner)

- **D4 — `bank_name` menyimpan *name* provider** (bukan code): semua join/view eksisting tetap merender `bank_name`, dan pemeriksaan drift CLI tetap terbaca manusia. **Rename di-cascade** ke `bank_accounts` di dalam TX rename (`UPDATE ... WHERE bank_name = <old>`) sehingga rename tidak pernah diam-diam memblokir member.
- **D5 — Provider nonaktif memblokir penarikan:** bila provider member dinonaktifkan, `/wallet/withdraw` dan `process_withdraw` menolak dengan `wd_err_ewallet_inactive` + redirect ke `bind_bank` (State B + notice). Immutability binding tetap utuh — hanya reset admin (atau reaktivasi) yang membuka.
- **D6 — Minimal satu provider aktif:** menonaktifkan provider aktif **terakhir** ditolak (`Minimal satu provider e-wallet harus aktif.`) — mencegah jalan buntu di mana tidak ada member yang bisa mengikat.
- **D7 — Tanpa hard delete provider** (pola plan/85 products): provider yang salah dibuat dinonaktifkan, tidak dihapus — string `bank_name` di baris historis harus selalu bisa di-resolve.
- **D8 — Nama provider = data brand, bukan entri kamus**, dan tidak pernah diterjemahkan; sumbernya `ewallet_providers.name` (selalu `htmlspecialchars()` saat render).
- **D9 — Tanpa retro-edit teks tersimpan:** `withdrawals.remark`/`decline_reason` berisi "Rekening" (3 baris live) adalah catatan historis admin; `decline_reason` penarikan hanya tampil di admin (surface member hanya merender decline reason *deposit*).
- **D10 — Non-goal:** tanpa logo/aset provider, tanpa aturan fee per-provider, tanpa uniqueness nomor HP lintas user, tanpa self-service unbind untuk member, tanpa kolom baru di `bank_accounts`.

### 1.3 Invariant yang wajib tetap utuh (AGENTS.md / plan 103)

`wallet_ledger` tetap satu-satunya ledger; aritmetika uang tidak berubah; seluruh akses DB di model (tanpa SQL di controller/view); disiplin integer-IDR tidak disentuh; admin tidak pernah memanggil `i18n_apply()` (**L1**); uang/angka tidak diterjemahkan (**L6**); setiap mutator POST bersifat POST-only + CSRF via `form_open`; setiap mutasi ter-audit.

---

## 2. Kondisi saat ini (bukti terverifikasi)

| Fakta | Bukti |
|---|---|
| `bank_accounts` = `id, user_id, bank_name, account_number, account_holder, is_primary, created_at, updated_at`; FK `fk_bank_user` CASCADE, `fk_withdrawals_bank` **RESTRICT** | `database.sql:96–134` |
| Hanya 3 titik tulis/baca: `Wallet_model::get_user_bank()` (`:1316`), `insert_bank()` (`:1320`), 3 call-site di `Wallet.php:367,443,562,597` | grep |
| Data live: 17 baris bank, **semua** nama bank legacy, `is_primary=1`, 0 nomor valid; 9 baris direferensikan 12 withdrawal; 1 withdrawal `processing` | SELECT live DB |
| View bind hari ini: `<select>` 13 bank **hardcoded** (dropdown statis, bukan input bebas) | `views/wallet/bank_bind.php:97–112` |
| Surface terminologi bank (member): `bank_bind.php`, `withdraw.php:9,64,67,71`, `wallet/index.php:238`, `profile/index.php:155–162`; (admin): `history.php:61,81`, `dashboard.php:276`, header CSV `Admin.php:2183–2185`; key kamus `help_q_wd_*` | grep |
| Baseline paritas kamus: **594/594**, semua gate lulus; hardcoded scan: **0 temuan** | `php scripts/audit_i18n_parity.php`, `audit_i18n_hardcoded.php` |
| MariaDB **12.3.2** (mendukung `IF NOT EXISTS` pada DDL/kolom) | `SELECT VERSION()` |
| Seeder mengeksekusi semua statement di SECTION non-`reconcile_schema` apa adanya; `reconcile_schema` **dilewati** (diterapkan dari daftar ALTER terjaga `reconcile_plan()`) | `scripts/seed_database.php:172–189, 274–295` |
| Tailwind Play CDN (JIT) → `peer` / `peer-checked:` / `sr-only` tersedia; `set_radio()` ada (form helper autoload) | `views/templates/header.php:23`, `system/helpers/form_helper.php:840` |

---

## 3. Database: `ewallet_providers` (tabel baru) + paritas skema

### 3.1 DDL kanonik (masuk `database.sql`, sebelum blok `bank_accounts`)

```sql
-- -----------------------------------------------------
-- Table `ewallet_providers` — Plan 106 (katalog provider e-wallet dinamis)
-- Satu-satunya sumber kebenaran pilihan provider untuk member (/wallet/bind_bank)
-- dan otoritas status aktif/nonaktif. `code` = identitas stabil (dipakai audit,
-- backfill migrasi & verify CLI); `name` = label tampilan (disimpan apa adanya
-- di bank_accounts.bank_name — D4). Tidak ada hard delete (D7).
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ewallet_providers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ewallet_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed idempotent (pola `system_settings`): INSERT IGNORE tidak pernah
-- menimpa rename/status yang sudah diubah admin.
INSERT IGNORE INTO `ewallet_providers` (`code`, `name`, `is_active`) VALUES
('DANA',      'DANA',      1),
('SHOPEEPAY', 'ShopeePay', 1),
('OVO',       'OVO',       1),
('GOPAY',     'GoPay',     1);
```

DDL `bank_accounts` **tidak disentuh** (constraint zero-breakage). Hanya ditambah komentar di atasnya yang mencatat bahwa `bank_name` kini menyimpan nama provider e-wallet dan `account_number` menyimpan nomor HP e-wallet kanonik (`08…`).

### 3.2 Raw SQL untuk phpMyAdmin (cermin CLI, urutan wajib)

```sql
-- 1) DDL (idempoten di MariaDB; MySQL 8: hapus IF NOT EXISTS → error 1050 aman diabaikan)
CREATE TABLE IF NOT EXISTS `ewallet_providers` ( /* DDL di §3.1 */ );

-- 2) Seed
INSERT IGNORE INTO `ewallet_providers` (`code`,`name`,`is_active`) VALUES
 ('DANA','DANA',1),('SHOPEEPAY','ShopeePay',1),('OVO','OVO',1),('GOPAY','GoPay',1);

-- 3) Backfill nama bank → provider (case-insensitive: kolom utf8mb4_unicode_ci)
UPDATE `bank_accounts` SET `bank_name` = 'DANA'      WHERE LOWER(`bank_name`) IN ('bca','bank bca','bank central asia (bca)','bni','bank negara indonesia (bni)');
UPDATE `bank_accounts` SET `bank_name` = 'OVO'       WHERE LOWER(`bank_name`) IN ('mandiri','bank mandiri');
UPDATE `bank_accounts` SET `bank_name` = 'GoPay'     WHERE LOWER(`bank_name`) IN ('bri','bank rakyat indonesia (bri)');
UPDATE `bank_accounts` SET `bank_name` = 'ShopeePay' WHERE LOWER(`bank_name`) IN ('cimb','bank cimb niaga');
-- sisa nama tak dikenal → provider default (DANA)
UPDATE `bank_accounts` SET `bank_name` = 'DANA'
 WHERE `bank_name` NOT IN (SELECT `name` FROM `ewallet_providers`);

-- 4) Arsipkan binding lama (nomor bank ≠ nomor HP e-wallet) → member wajib re-bind
UPDATE `bank_accounts` SET `is_primary` = 0
 WHERE `is_primary` = 1 AND `account_number` NOT REGEXP '^08[0-9]{8,11}$';
```

### 3.3 Query verifikasi (juga diimplementasikan di CLI `--verify`)

```sql
-- V1 4 provider kanonik ada & aktif
SELECT COUNT(*) FROM `ewallet_providers` WHERE `is_active`=1 AND `code` IN ('DANA','SHOPEEPAY','OVO','GOPAY');        -- harapan 4
-- V2 tanpa drift: setiap binding AKTIF menunjuk nama provider yang ada
SELECT COUNT(*) FROM `bank_accounts` b LEFT JOIN `ewallet_providers` p ON p.`name`=b.`bank_name`
 WHERE b.`is_primary`=1 AND p.`id` IS NULL;                                                                          -- harapan 0
-- V3 setiap binding AKTIF memakai nomor HP e-wallet valid
SELECT COUNT(*) FROM `bank_accounts` WHERE `is_primary`=1 AND `account_number` NOT REGEXP '^08[0-9]{8,11}$';         -- harapan 0
-- V4 integritas FK terjaga (tidak ada baris dihapus) — HARUS 0
SELECT COUNT(*) FROM `withdrawals` w LEFT JOIN `bank_accounts` b ON b.`id`=w.`bank_account_id`
 WHERE w.`bank_account_id` IS NOT NULL AND b.`id` IS NULL;                                                           -- harapan 0
-- V5 maksimum satu binding aktif per user (post-apply; WARN bila >0 setelah member re-bind)
SELECT COUNT(*) FROM (SELECT `user_id` FROM `bank_accounts` WHERE `is_primary`=1 GROUP BY `user_id` HAVING COUNT(*)>1) t;
```

### 3.4 `database_seed.sql` + `scripts/seed_database.php`

- **Section baru** `-- ============ SECTION: ewallet_providers ============` berisi `CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE` (4 baris). Wajib di section **biasa**, **bukan** `reconcile_schema`, karena seeder melewati section itu (diterapkan dari daftar `reconcile_plan()` yang hanya memahami `ALTER … ADD COLUMN/KEY`). Karena itu marker `[RECONCILE]` **tidak** dipakai di plan 106.
- **Tulis ulang** `SECTION: bank_accounts`: tetap 13 baris/id, tetapi `bank_name` ∈ {DANA, ShopeePay, OVO, GoPay} dan `account_number` = nomor HP user seed dari `scripts/seed_database.php:USER_PHONES` (semua lolos `^08[0-9]{8,11}$`), `is_primary=1` → DB seed lokal tetap bisa bind/withdraw end-to-end.
- **`scripts/seed_database.php`**: tambah `'ewallet_providers' => 4` di blok hitung pasca-apply (`run_validation()`), plus dua assertion baru: setiap baris `bank_accounts` aktif lolos regex nomor HP e-wallet, dan setiap `bank_name` terpetakan ke nama provider yang ada.
- Catatan migrasi live ditambahkan ke `database.sql` mengikuti gaya eksisting (blok `-- Plan 106 — MIGRASI LIVE (one-time …)` berisi perintah CLI + raw SQL §3.2), meniru blok plan/102 dan plan/104.

---

## 4. `scripts/migrate_106_ewallet_withdrawal.php` (CLI baru)

Mengikuti kerangka `scripts/migrate_105_wa_group_link.php` (raw `mysqli`, kredensial dibaca dari `application/config/database.php`, `date_default_timezone_set('Asia/Jakarta')`, `mysqli_report(STRICT)`, define `BASEPATH`/`ENVIRONMENT`) dan gaya fase plan/104.

**Flag:** `--dry-run` (default; tidak menulis apa pun) · `--apply` · `--verify` (read-only) · `--keep-bindings` (melewati fase 6 — escape hatch operator atas keputusan D1) · `--default-provider=CODE` (default `DANA`) · `--help`.
**Exit code:** `0` bersih/no-op · `1` pre-flight gagal · `2` apply/verify gagal (identik 104/105).

**Fase**

1. **PRE-FLIGHT** — DB reachable; `bank_accounts`, `withdrawals`, `users` ada; `bank_accounts` punya 6 kolom yang diharapkan; cetak versi server.
2. **INSPECT (semua mode)** — apakah `ewallet_providers` ada (kolom/index)? berapa provider, code apa saja, mana yang nonaktif?; klasifikasi nama `bank_accounts` (legacy vs sudah provider); hitung baris yang direferensikan per `bank_account_id` dan baris berstatus `pending|processing` (peringatan in-flight); hitung binding aktif yang nomornya tidak valid (= baris yang akan diarsipkan); cetak rencana mapping + ekspektasi jumlah arsip. **Dry-run berhenti di sini (exit 0).**
3. **DDL** — `CREATE TABLE IF NOT EXISTS ewallet_providers …` (dilewati bila sudah ada dengan bentuk yang sesuai; bila ada tapi bentuknya menyimpang → exit 1 dengan laporan diff).
4. **SEED** — `INSERT IGNORE` 4 code kanonik (tidak pernah menimpa baris eksisting: provider yang sudah di-rename/dinonaktifkan admin tetap bertahan pada re-run).
5. **BACKFILL nama** — 5 `UPDATE` mapping §3.2 langkah 3 (idempoten: baris yang sudah membawa nama provider dikecualikan oleh predikat `NOT IN (SELECT name …)`); cetak jumlah per nama dan nama apa pun yang jatuh ke default.
6. **ARSIP binding legacy** — `UPDATE … SET is_primary=0 WHERE is_primary=1 AND account_number NOT REGEXP '^08[0-9]{8,11}$'` (idempoten: re-run mengenai 0 baris). Dilewati dengan `--keep-bindings`. Tidak pernah menghapus baris.
7. **VERIFY** — V1–V5 (§3.3) plus: bentuk tabel sesuai DDL; 4 baris kanonik ada; nama provider tersimpan ⊂ nama live (deteksi drift → exit 2); jumlah baris terarsip ≥ jumlah legacy pra-migrasi (bukti tidak ada yang dihapus); jumlah orphan FK = 0. Mode `--verify` hanya menjalankan fase 2 + 7.

Setiap fase mencetak `[ok]/[skip]/[warn]/[FAIL]`; diakhiri tabel ringkasan (before → after per concern).

**Rollback (didokumentasikan di header script + plan):** langkah arsip reversibel dengan `UPDATE bank_accounts SET is_primary=1 WHERE id IN (…)` (script mencetak daftar ID yang diarsipkan) — catatan: member yang sudah re-bind akan memegang dua baris aktif; `get_user_ewallet()` deterministik (`ORDER BY id DESC LIMIT 1`) dan V5 menandai kasus ini sebagai WARN.

---

## 5. Domain layer

### 5.1 `application/models/Ewallet_model.php` (baru)

Satu-satunya sumber kebenaran katalog provider (mengikuti pola `Promoter_model` — model domain yang dipakai bersama alur member + admin; menghindari risiko divergensi duplikasi gaya `Product_model`/`Admin_model`). Semua method memakai bound param / query builder; tidak ada SQL di luar model.

| Method | Kegunaan |
|---|---|
| `get_active_providers()` | `is_active=1 ORDER BY name ASC` → card selector member |
| `get_providers_admin()` | semua baris + hitungan `active_bindings` / `total_bindings` (LEFT JOIN `bank_accounts` on `bank_name = name`, dipisah `is_primary`) → kepadatan tabel admin & konteks keputusan deaktivasi |
| `get_provider($id)` | baris per id (state apa pun) |
| `get_active_provider($id)` | `id` **dan** `is_active=1` → choke-point validasi POST member |
| `get_provider_by_name($name)` | resolusi drift / gate penarikan |
| `count_active_providers()` | guard D6 |
| `code_exists($code, $exclude_id=null)` / `name_exists($name, $exclude_id=null)` | pesan duplikat yang ramah (collation sudah CI) |
| `create_provider(array $fields)` | mengembalikan `insert_id|false` |
| `rename_provider($id, $name)` | hanya nama (code immutable — §6.2) |
| `set_provider_active($id, $state)` | toggle |

### 5.2 `application/helpers/ewallet_helper.php` (baru, autoload)

Fungsi murni, dijaga `function_exists()` (aman dipakai dari konteks CLI/`BASEPATH`), menjadi satu-satunya sumber aturan nomor HP sehingga controller, hint view, dan verifikasi CLI tidak bisa berbeda:

- `ewallet_phone_normalize($raw)` — buang non-digit, `62`/`0062` → `0`, pastikan awalan `0` (semantik sama dengan `Auth::_normalize_phone()`, backend tetap sumber kebenaran).
- `ewallet_phone_is_valid($digits)` — `preg_match('/^08[0-9]{8,11}$/', $digits) === 1` (**numerik, diawali 08, panjang 10–13 digit**).
- `ewallet_phone_validate($raw)` — normalisasi + validasi → string kanonik `08…`, atau `null` bila invalid.
- `ewallet_phone_mask($value)` — algoritma masking eksisting diekstrak apa adanya (`len>7 → 4 awal + '*'×(len−7) + 3 akhir`, selain itu mentah) agar kartu terikat, kartu withdraw, dan riwayat wallet memasking identik.
- `ewallet_default_code()` = `'DANA'` (hanya dipakai backfill CLI; didefinisikan sekali di sini).

`application/config/autoload.php`: tambahkan `'ewallet'` pada `$autoload['helper']`.

### 5.3 `application/models/Wallet_model.php` (pemilik tulis `bank_accounts` — aturan single writer)

- **Rename** `get_user_bank($user_id)` → `get_user_ewallet($user_id)`: `where('user_id', $user_id)->where('is_primary', 1)->order_by('id','DESC')->limit(1)` → `row()`. Ketiga call-site diperbarui (semantik D2).
- **Baru** `bind_user_ewallet(int $user_id, string $provider_name, string $phone, string $holder): array` — TX: `SELECT … FROM bank_accounts WHERE user_id=? AND is_primary=1 FOR UPDATE` → bila ada: rollback + `['ok'=>false,'code'=>'already_bound']`; bila tidak: insert via `insert_bank(['user_id','bank_name'=>$provider_name,'account_number'=>$phone,'account_holder'=>$holder,'is_primary'=>1])` → `['ok'=>true,'code'=>'ok']`. Menutup race double-submit yang saat ini hanya dijaga di controller. `insert_bank()` dipertahankan (kini internal).
- **Baru** `unbind_user_ewallet(int $user_id): int` — arsipkan semua baris aktif (`is_primary=1 → 0`), mengembalikan `affected_rows()` (0 = tidak ada binding).
- **Baru** `reassign_provider_name(string $old_name, string $new_name): int` — `UPDATE bank_accounts SET bank_name=? WHERE bank_name=?` (cascade rename D4), mengembalikan affected rows.
- **Hardening** `create_withdrawal()`: tepat setelah anchor `users FOR UPDATE`, pastikan tujuan milik user dan masih aktif:
  `SELECT id FROM bank_accounts WHERE id=? AND user_id=? AND is_primary=1 FOR UPDATE` → bila tidak ada: `trans_rollback()` + `['success'=>false,'code'=>'no_ewallet', …]`. Code baru `'no_ewallet'` dipetakan di controller (§8).

---

## 6. Admin — manajemen provider

### 6.1 Route (`application/config/routes.php`, pretty URL ber-tanda-hubung meniru plan/85 products)

```php
// plan/106: manajemen provider e-wallet (CRUD tanpa hard delete — D7).
$route['admin/ewallet-providers'] = 'admin/ewallet_providers';
$route['admin/ewallet-providers/create'] = 'admin/create_ewallet_provider';
$route['admin/ewallet-providers/update/(:num)'] = 'admin/update_ewallet_provider/$1';
$route['admin/ewallet-providers/toggle_status/(:num)'] = 'admin/toggle_ewallet_provider/$1';
```

Endpoint reset user **tidak perlu route** (`admin/reset_ewallet/{id}` resolve lewat default routing, persis seperti `admin/toggle_ban/{id}` yang sudah ada).

### 6.2 Method `Admin` (4 baru) — semua POST-only (`show_404()` saat GET), semua di dalam `trans_start()/trans_complete()` + `Audit_model::log_admin_action()`, semua pesan **Indonesia**

| Method | Aturan / perilaku |
|---|---|
| `ewallet_providers()` (GET) | `$data = ['page_title'=>'Provider E-Wallet', 'providers'=>$this->Ewallet_model->get_providers_admin()]` → view `admin/ewallet_providers` |
| `create_ewallet_provider()` (POST) | `code` = `strtoupper(trim(post))` wajib cocok `^[A-Z0-9_]{2,50}$`; `name` = trimmed, 1–100 char, `name_exists()` false; `is_active` ∈ {0,1} (default 1). Code duplikat → pesan `code_exists()`. Audit `admin_create_ewallet_provider` `{before:null, after:{id,code,name,is_active}}` |
| `update_ewallet_provider($id)` (POST) | **rename saja** (`code` immutable — identitas stabil untuk CLI/audit); `name` wajib & unik; TX = `rename_provider()` + `Wallet_model::reassign_provider_name($old,$new)`; audit `admin_rename_ewallet_provider` `{id, code, before:{name}, after:{name}, rebound_bindings:<int>}` |
| `toggle_ewallet_provider($id)` (POST) | baca baris (flash setara 404 bila tidak ada) → state baru; **guard D6**: tolak menonaktifkan bila `count_active_providers() <= 1`; audit `admin_toggle_ewallet_provider` `{id, code, name, before:{is_active}, after:{is_active}, active_bindings:<int>}` |

Keempatnya redirect ke `admin/ewallet-providers` dengan flash; toggle menambah baris peringatan Indonesia saat `active_bindings > 0` ("… N akun member terikat akan diblokir dari penarikan sampai provider diaktifkan kembali.").

### 6.3 `application/views/admin/ewallet_providers.php` (baru, meniru `admin/products/index.php`)

100% Indonesia (L1). Header `t-card` + banner info ("Provider nonaktif tidak muncul di pilihan member; ikatan lama tetap tersimpan."); tabel `ID · Kode · Nama Provider · Akun Terikat · Status · Aksi` (pill status Aktif/Nonaktif, `active_bindings`/`total_bindings` dari model); tombol "Tambah Provider" + satu modal yang dipakai ulang, digerakkan vanilla JS via atribut `data-*`, dengan **`form_open()` di-bake server-side** (token CSRF tidak pernah ditulis ulang JS — pelajaran plan/85): field Kode (`maxlength=50`, `pattern="[A-Za-z0-9_]{2,50}"`, auto-uppercase saat input), Nama (`maxlength=100`), Status; mode rename mengunci field Kode dan menampilkan hint "Mengubah nama akan memperbarui label akun member yang sudah terikat."; tombol baris: "Ubah Nama" dan "Aktifkan/Nonaktifkan" (`return confirm(…)`).

### 6.4 Sidebar admin

`application/views/admin/templates/sidebar.php`: entri baru setelah "Produk GPU" → `site_url('admin/ewallet-providers')`, label `E-Wallet`, ikon `fas fa-wallet`, active state `in_array($this->uri->segment(2), ['ewallet-providers','ewallet_providers'])` (bentuk dash tetap didukung untuk URL fallback).

---

## 7. Admin — "Reset / Unbind Akun E-Wallet"

**Controller** `Admin::reset_ewallet($user_id)` — POST-only (`show_404()` saat GET); id `(int)`; user wajib ada; binding aktif wajib ada (bila tidak: flash `User tidak memiliki akun e-wallet terikat.`). TX:

1. `$before = $this->Wallet_model->get_user_ewallet($id)` (snapshot untuk audit);
2. `$n = $this->Wallet_model->unbind_user_ewallet($id)` — wajib `≥1`, jika tidak rollback;
3. `Audit_model::log_admin_action($admin_id, $id, 'admin_reset_ewallet', ['before'=>['bank_name','account_number_masked','account_holder'], 'after'=>['is_primary'=>0], 'affected'=>$n], ip)` — nomor HP **ter-mask** (minimisasi PII);
4. `Notification_model::insert_keyed($id, 'notif_ewallet_reset', [], 'info')` — terlihat member, dwibahasa lewat key (§9).

Flash sukses `Akun e-wallet user berhasil direset. User dapat mengikat ulang.`; redirect `admin/user_detail/{id}`. Penarikan in-flight **tidak** diblokir (barisnya memegang `bank_account_id` sendiri sehingga payout tidak terpengaruh) — sebagai gantinya dialog konfirmasi memberi peringatan ketika `Wallet_model::has_pending_withdrawal($id)` true.

**View** `application/views/admin/user_detail.php`: section `t-card` baru "E-Wallet" (ikon `fa-wallet`), disisipkan setelah Section 2 (Wallet Controls), copy Indonesia:

- terikat → nama provider + pill status (Aktif/Nonaktif dari baris provider) · `Nomor HP E-Wallet: 0812***890` (masking `ewallet_phone_mask()`) · `Nama Pemilik Akun` · `Direkatkan pada {tanggal}`; ditambah baris amber bila provider nonaktif;
- belum terikat → "User belum mengikat akun e-wallet." + hint bahwa binding wajib sebelum penarikan;
- form reset = `form_open('admin/reset_ewallet/'.$user->id)` standalone dengan `onsubmit` confirm ("Reset ikatan akun e-wallet user ini? User akan bisa mengikat ulang." + peringatan WD pending bila relevan); tombol tampil disabled/absen saat belum terikat. Blok form standalone (tidak boleh bersarang di `<form>` lain).
- `Admin::user_detail()` menambah tiga data key: `ewallet` (`Wallet_model::get_user_ewallet`), `ewallet_provider` (`Ewallet_model::get_provider_by_name`, nullable), `has_pending_withdrawal` (bool).

---

## 8. Alur binding member `/wallet/bind_bank`

**Controller `Wallet`** (`__construct` tambahan `Ewallet_model`):

- `bind_bank()` GET: `$existing = Wallet_model::get_user_ewallet($uid)`, `$providers = Ewallet_model::get_active_providers()`; saat terikat, resolusi `$provider` juga untuk mengekspos notice provider nonaktif; kirim `providers`, `existing_ewallet`, `provider`.
- `bind_bank()` POST (urutan & gaya kegagalan tetap: flash + redirect, tanpa library `form_validation` di `Wallet`):

| # | Pemeriksaan | Key kegagalan |
|---|---|---|
| 1 | sudah terikat (`get_user_ewallet`) | `bb_err_already_bound` |
| 2 | `provider_id` cocok `^[1-9][0-9]*$` dan `get_active_provider()` mengembalikan baris | `bb_err_provider_invalid` |
| 3 | `account_holder` dan `ewallet_phone` tidak kosong | `bb_err_required_fields` |
| 4 | `ewallet_phone_validate(post)` non-null (numerik / `08` / 10–13 digit; bentuk kanonik disimpan) | `bb_err_phone` |
| 5 | `mb_strlen(account_holder) ≤ 100` (baru; kolom `VARCHAR(100)`) | `bb_err_holder_too_long` |
| 6 | `Wallet_model::bind_user_ewallet($uid, $provider->name, $phone, $holder)` → `code==='already_bound'` dipetakan ke `bb_err_already_bound`, kegagalan lain ke `bb_err_save_failed`, sukses ke `bb_ok_bound` | — |

Nama field POST menjadi `provider_id`, `ewallet_phone`, `account_holder` (nama kolom legacy `bank_name`/`account_number` hanya tinggal di DB — didokumentasikan di komentar kode).

**View** `application/views/wallet/bank_bind.php` (dibangun ulang):

- **State A (belum terikat)** — `u-card` dengan header `bb_bind_title` / `bb_bind_sub`; `<fieldset>` legend `bb_provider_label` + hint `bb_choose_provider` + **grid 2×2 kartu radio** (`<input type="radio" name="provider_id" class="peer sr-only" required <?= set_radio(...) ?>>` diikuti kartu `peer-checked:border-indigo-500 peer-checked:bg-indigo-50 dark:peer-checked:bg-indigo-500/10` berisi `<i class="fas fa-wallet">` + `htmlspecialchars($p->name)`; `focus-visible:` ring untuk a11y); lalu input nomor HP (`name="ewallet_phone"`, `inputmode="numeric"`, `maxlength="13"`, `pattern="08[0-9]{8,11}"`, hint `bb_phone_hint`, placeholder `bb_phone_placeholder`, `set_value`), lalu input pemilik (`name="account_holder"`, `maxlength="100"`, `capitalize`), amber `bb_notice`, submit `bb_bind_btn`; **empty state fail-closed**: bila `$providers` kosong → kartu info `bb_no_provider_available`, input + submit tidak dirender;
- **State B (terikat)** — nama provider (escaped) + `bb_linked_label` + pill `wd_verified`, nomor HP ter-mask `ewallet_phone_mask()`, `bb_account_holder_label` + nama pemilik, `bb_bound_status`; kartu rose `bb_security_title`/`bb_security_body` (menyebut jalur reset CS/admin); kartu amber tambahan `bb_provider_inactive_notice` saat provider terikat nonaktif. Ikon diperbarui `fa-university` → `fa-wallet`.

**Gate penarikan** (`Wallet::withdraw()` GET dan `process_withdraw()` POST) — Gatekeeper 4 menjadi: tidak ada binding aktif → `wd_err_no_ewallet_cta` (GET) / `wd_err_no_ewallet` (POST) + redirect `wallet/bind_bank`; binding ada tetapi `get_provider_by_name()` `null` atau `is_active=0` → `wd_err_ewallet_inactive` + redirect `wallet/bind_bank` (State B menjelaskan sebabnya). `WD_ERR_KEYS` bertambah `'no_ewallet' => 'wd_err_no_ewallet'` untuk hardening `create_withdrawal` di level model (§5.3).

---

## 9. Perombakan i18n (EN & ID) — tabel audit, rename, key baru

### 9.1 Key di-rename (9) — nilai ditulis ulang di kedua idiom (jumlah key tidak berubah)

| Key lama | Key baru | EN | ID |
|---|---|---|---|
| `bb_bank_name_label` | `bb_provider_label` | E-Wallet Provider | Provider E-Wallet |
| `bb_choose_bank` | `bb_choose_provider` | Select E-Wallet Provider | Pilih Provider E-Wallet |
| `bb_account_number_label` | `bb_phone_label` | E-Wallet Phone Number | Nomor HP E-Wallet |
| `bb_account_number_placeholder` | `bb_phone_placeholder` | e.g. 081234567890 | Contoh: 081234567890 |
| `bb_err_account_number` | `bb_err_phone` | The e-wallet phone number must be numeric, start with 08, and be 10-13 digits long. | Nomor HP e-wallet harus berupa angka, diawali 08, dan terdiri dari 10-13 digit. |
| `profile_withdraw_bank` | `profile_withdraw_ewallet` | Withdraw & E-Wallet Account | Tarik Dana & Akun E-Wallet |
| `wd_rekening_label` | `wd_ewallet_label` | E-Wallet Withdrawal Account | Akun Penarikan E-Wallet |
| `wd_err_no_bank` | `wd_err_no_ewallet` | You have not linked an e-wallet account yet. | Anda belum mengikat akun e-wallet. |
| `wd_err_no_bank_cta` | `wd_err_no_ewallet_cta` | You have not linked an e-wallet account. Please link one first. | Anda belum mengikat akun e-wallet. Silakan ikat akun e-wallet terlebih dahulu. |

### 9.2 Nilai ditulis ulang, nama key dipertahankan (14)

`bb_bind_title`, `bind_page_title` (EN *Link E-Wallet Account* / ID *Ikat Akun E-Wallet*); `bb_linked_label` (*Linked E-Wallet* / *E-Wallet Terikat*); `bb_holder_placeholder` (*As per e-wallet account name* / *Sesuai nama pemilik akun e-wallet*); `bb_bind_btn` (*Link E-Wallet* / *Ikat E-Wallet*); `bb_ok_bound` (*E-wallet account linked successfully.* / *Akun e-wallet berhasil diikat.*); `bb_err_already_bound` (+ Contact Customer Service to reset it. / + Hubungi Customer Service untuk mereset.); `bb_err_save_failed`; `bb_notice` (+ kalimat reset di kedua idiom, tetap memakai `<strong>`); `bb_security_title` (*E-Wallet Data Locked* / *Data Akun E-Wallet Dikunci*); `bb_security_body` (wording reset via CS); `help_q_wd_body` (bullet → *Choose the e-wallet account you have already linked.* / *Pilih akun e-wallet yang sudah terikat.*); `help_q_wd_fail_req1` (*E-wallet account is required - open Profile then E-Wallet Account to link your e-wallet phone number.* / *Akun E-Wallet wajib diikat terlebih dahulu - Buka menu Profil lalu Akun E-Wallet untuk mengikat nomor HP e-wallet Anda.*).

Tidak berubah (`bb_bound_status`, `bb_holder_label`, `bb_bind_sub`, `wd_an_label`, `wd_verified`) — sudah netral terminologi dan sudah memuat wording "Nama Pemilik Akun" yang diminta.

### 9.3 Key baru (8) → **594 + 8 = 602 key per idiom**

| Key | EN | ID |
|---|---|---|
| `bb_phone_hint` | Digits only, starts with 08, 10-13 digits. | Hanya angka, diawali 08, 10-13 digit. |
| `bb_err_provider_invalid` | Please select a valid e-wallet provider. | Silakan pilih provider e-wallet yang valid. |
| `bb_err_holder_too_long` | The account holder name is too long (maximum 100 characters). | Nama pemilik akun terlalu panjang (maksimal 100 karakter). |
| `bb_no_provider_available` | No e-wallet provider is available right now. Please try again later. | Belum ada provider e-wallet yang tersedia saat ini. Silakan coba lagi nanti. |
| `bb_provider_inactive_notice` | This e-wallet provider is temporarily inactive, so withdrawals are blocked. Contact Customer Service to reset your linked account. | Provider e-wallet ini sedang tidak aktif sehingga penarikan diblokir. Hubungi Customer Service untuk mereset akun terikat Anda. |
| `wd_err_ewallet_inactive` | Your e-wallet provider is currently inactive. Please contact Customer Service to reset your linked account. | Provider e-wallet Anda sedang tidak aktif. Silakan hubungi Customer Service untuk mereset akun terikat Anda. |
| `notif_ewallet_reset_title` | E-Wallet Account Reset | Akun E-Wallet Direset |
| `notif_ewallet_reset_body` | Admin has reset your linked e-wallet account. Please link your e-wallet again from the wallet menu. | Admin telah mereset akun e-wallet Anda yang terikat. Silakan ikat ulang akun e-wallet Anda dari menu wallet. |

### 9.4 Diaudit tetapi sengaja **tidak** diubah (didokumentasikan agar audit terbukti sistematis)

- `wallet_pay_step_scan`, `wallet_pay_waiting_body` — wording instrumen **deposit** QRIS ("bank/e-wallet app", "mutasi bank/QRIS"), bukan terminologi akun penarikan.
- Nama provider DANA/ShopeePay/OVO/GoPay — data brand dari DB, bukan entri kamus (D8).
- Teks tersimpan `withdrawals.remark`/`decline_reason` — riwayat admin yang immutable (D9).

### 9.5 Higienitas gate

`scripts/audit_i18n_parity.php`: hapus token `'bank account'` yang kini mati dari `$identicalAllowlist` (memperketat, tidak pernah melemahkan gate; tidak ada pasangan nilai EN/ID yang masuk allowlist). Ekspektasi pasca-perubahan: `EN keys = ID keys = 602`, `Paritas 1:1 OK`, `Nilai identik = 0`, `P3 = 0`, `P6 newline = 0`, `P3b = 0`; daftar info-warning P6 `<>` boleh bertambah (informatif, sama seperti `bb_notice` hari ini).

### 9.6 L1 (admin 100% Indonesia, tanpa key i18n)

Surface admin baru/terubah (view `ewallet_providers`, section `user_detail`, pesan flash, dialog konfirmasi, label sidebar, nama aksi audit dalam snake_case Inggris sesuai konvensi) memakai literal Indonesia. Ikut diperbarui: `admin/history.php` `<th>Bank</th>` → `<th>E-Wallet</th>`; header CSV `Admin::export_csv('withdrawals')` `'Bank Name','Account Number','Account Holder'` → `'Provider E-Wallet','Nomor HP E-Wallet','Nama Pemilik Akun'`. `admin/dashboard.php:276` tidak perlu diubah (merender nama provider tersimpan).

---

## 10. Inventaris file lengkap

**Baru:** `plan/106_EWALLET_WITHDRAWAL_AND_PROVIDER_MANAGEMENT_PLAN.md` · `application/models/Ewallet_model.php` · `application/helpers/ewallet_helper.php` · `application/views/admin/ewallet_providers.php` · `scripts/migrate_106_ewallet_withdrawal.php`.

**Diubah:** `database.sql` · `database_seed.sql` · `scripts/seed_database.php` · `application/config/autoload.php` · `application/config/routes.php` · `application/models/Wallet_model.php` · `application/controllers/Wallet.php` · `application/controllers/Admin.php` · `application/views/wallet/bank_bind.php` · `application/views/wallet/withdraw.php` · `application/views/wallet/index.php` · `application/views/profile/index.php` · `application/views/admin/user_detail.php` · `application/views/admin/templates/sidebar.php` · `application/views/admin/history.php` · `application/language/english/app_lang.php` · `application/language/indonesian/app_lang.php` · `scripts/audit_i18n_parity.php` · `docs/2_ERD.md` (tabel baru + semantik `bank_accounts`) · `docs/3_ROADMAP.md` (entri plan 106) · `AGENTS.md` (catatan plan/106).

**Eksplisit tidak disentuh:** DDL `bank_accounts`, DDL `withdrawals`, `wallet_ledger`, aritmetika uang, `system/` (termasuk fix Pagination PHP 8.3), `templates/header.php`, `help/index.php` (nama key tidak berubah → hanya nilai), `Admin_model.php` (semua join berbasis id `LEFT JOIN` ke `bank_accounts.id` tetap bekerja), `views/wallet/pay.php`.

---

## 11. Urutan implementasi (setiap langkah diakhiri `php -l` untuk tiap file PHP yang tersentuh)

1. Tulis plan ini ke `plan/106_EWALLET_WITHDRAWAL_AND_PROVIDER_MANAGEMENT_PLAN.md`. *(selesai — dokumen ini)*
2. Skema: `database.sql` (DDL + seed + blok komentar migrasi live), `database_seed.sql` (section baru + baris `bank_accounts` e-wallet), `scripts/seed_database.php` (hitungan + 2 assertion baru).
3. `scripts/migrate_106_ewallet_withdrawal.php` (7 fase + flag + exit code).
4. `application/helpers/ewallet_helper.php` + entri `autoload.php`.
5. `application/models/Ewallet_model.php`.
6. `application/models/Wallet_model.php` (rename `get_user_ewallet`, `bind_user_ewallet`, `unbind_user_ewallet`, `reassign_provider_name`, hardening `create_withdrawal`).
7. Jalankan migrasi di DB lokal: `--dry-run` → `--apply` → `--apply` (idempoten) → `--verify` (exit 0); pastikan 17 baris terarsip dan V1–V5 sesuai.
8. Admin: route + 4 method CRUD provider + `reset_ewallet` + data key `user_detail` + view `ewallet_providers.php` + sidebar + label history/CSV.
9. Section E-Wallet di `admin/user_detail.php` (terikat/belum/reset).
10. Member: rewrite `Wallet::bind_bank` + gate penarikan + `WD_ERR_KEYS['no_ewallet']`.
11. View: `bank_bind.php` (card selector + State A/B), `withdraw.php` (label + helper mask), `wallet/index.php` (kartu pending), `profile/index.php` (key hasil rename + ikon `fa-wallet`).
12. Kamus: rename + tulis ulang nilai + 8 key baru di kedua file (EN & ID dalam satu pass edit); higienitas allowlist `audit_i18n_parity.php`.
13. Jalankan kedua audit + verifikasi alur manual (§12).
14. Sinkronisasi dokumen: `docs/2_ERD.md`, `docs/3_ROADMAP.md`, `AGENTS.md`.

---

## 12. Matriks verifikasi

| # | Pemeriksaan | Perintah / aksi | Ekspektasi |
|---|---|---|---|
| V0 | Sintaks | `php -l` tiap file PHP baru/terubah | tanpa error |
| V1 | Paritas kamus | `php scripts/audit_i18n_parity.php` | exit 0 · `EN 602 / ID 602` · `1:1 OK` · 0 nilai identik di luar allowlist · P3 0 · P6 newline 0 · P3b 0 |
| V2 | Tanpa copy hardcoded | `php scripts/audit_i18n_hardcoded.php` | exit 0 · 0 temuan |
| V3 | Migrasi dry-run | `php scripts/migrate_106_ewallet_withdrawal.php --dry-run` | exit 0 · tanpa tulis (jumlah baris tidak berubah) |
| V4 | Apply + idempotensi | `--apply`, lalu `--apply` lagi | exit 0 · run ke-2: 0 dipetakan / 0 diarsipkan |
| V5 | Verify | `--verify` | exit 0 · assertion SQL V1–V5 sesuai |
| V6 | Integritas FK/riwayat | `SELECT COUNT(*) FROM withdrawals w LEFT JOIN bank_accounts b ON b.id=w.bank_account_id WHERE b.id IS NULL` | 0 (9 baris tereferensi tetap resolve) |
| V7 | Halaman admin provider | GET `/admin/ewallet-providers` | 200, 4 baris, copy hanya Indonesia |
| V8 | Tambah / rename / toggle | POST create (code `SHOPEEPAY` duplikat → error Indonesia; code baru → baris) · rename cascade (jumlah `UPDATE bank_accounts` = binding aktif provider tsb) · toggle off → hilang dari selector member · menonaktifkan provider aktif **terakhir** → ditolak | masing-masing dengan flash + baris `system_audit_logs` (`admin_create/rename/toggle_ewallet_provider`) |
| V9 | Guard POST-only | GET `/admin/ewallet-providers/create`, GET `/admin/reset_ewallet/{id}` | 404 |
| V10 | Validasi binding | POST `/wallet/bind_bank` dengan: field kosong · `provider_id=999` · id provider nonaktif · nomor `081234567` (9 digit) · `08123456789012` (14 digit) · `1234567890` (bukan 08) · `8123456789` (dinormalisasi → valid) · `081234567890` (valid) | lima kasus pertama → flash kamus yang tepat, tanpa baris DB; dua terakhir → sukses + baris `bank_name='DANA'`/`account_number='081234567890'`/`is_primary=1` |
| V11 | Immutability | POST kedua saat sudah terikat | `bb_err_already_bound`, tetap tepat 1 baris aktif |
| V12 | Gate provider nonaktif | nonaktifkan provider yang terikat → GET/POST `/wallet/withdraw` | flash `wd_err_ewallet_inactive` + redirect `wallet/bind_bank` (State B + notice nonaktif); reaktivasi → kartu 200 menampilkan provider + nomor ter-mask |
| V13 | Alur reset | POST `/admin/reset_ewallet/{id}` untuk user terikat | flash sukses + `is_primary=0` + audit `admin_reset_ewallet` + notifikasi `notif_ewallet_reset_*`; user detail menampilkan "belum mengikat"; member bisa mengikat ulang; POST kedua → "tidak memiliki akun e-wallet terikat" |
| V14 | Penarikan end-to-end | user terikat + rental aktif, jendela WIB terbuka, `/wallet/withdraw` → submit | baris withdrawal dibuat; kartu pending + dashboard/history admin merender provider + nomor HP; header CSV memakai label Indonesia baru |
| V15 | Jalur seed | `php scripts/seed_database.php --verify` lalu `--apply --force` di DB scratch | 4 provider, 13 binding e-wallet, seluruh assertion baru lulus |
| V16 | L1 admin | grep string view/flash admin baru | hanya Indonesia, tanpa pemanggilan `lang(` di surface admin |

---

## 13. Edge case & mode kegagalan

| Kasus | Penanganan |
|---|---|
| Semua provider nonaktif / nol provider | Halaman bind member merender `bb_no_provider_available`, form + submit dihilangkan (fail-closed); penarikan tetap diblokir oleh D5; D6 mencegah kondisi ini tercapai lewat UI. |
| Provider dinonaktifkan saat ada member terikat | Binding dipertahankan, penarikan diblokir dengan `wd_err_ewallet_inactive`; tabel admin menampilkan `active_bindings` dan flash toggle memberi peringatan; remediasi = reaktivasi atau reset per member. |
| Provider di-rename | Cascade memperbarui baris terikat di TX yang sama; audit mencatat `rebound_bindings`; CLI `--verify` mendeteksi drift di luar jalur aplikasi (exit 2). |
| Baris binding direferensikan withdrawal | Tidak pernah dihapus (hanya arsip) → FK `fk_withdrawals_bank` RESTRICT tidak pernah terpicu, kartu riwayat tetap render (V6). |
| Withdrawal in-flight saat migrasi | Baris `bank_account_id`-nya diarsipkan tetapi tidak dihapus → admin tetap bisa approve/pay; member boleh re-bind provider baru secara bersamaan (tidak berbahaya, terdokumentasi). |
| Double-submit / bind bersamaan | `bind_user_ewallet()` mengunci baris aktif `FOR UPDATE` → tepat satu binding aktif; V5 memperingatkan bila data historis melanggar. |
| Nomor diketik non-digit / berawalan 62 | Dinormalisasi `62…`→`0…`; yang tersimpan bentuk kanonik `08…`; apa pun yang gagal aturan 10–13 digit `08` ditolak `bb_err_phone`. |
| Reset admin saat ada WD pending | Diizinkan (tidak ada kopling teknis); dialog konfirmasi memperingatkan dana tetap dikirim ke e-wallet lama. |
| Nomor legacy kebetulan cocok `^08\d{8,11}$` | Baris tetap terikat dengan nama provider hasil mapping — disengaja (nomor sudah plausibel sebagai e-wallet, tanpa friksi paksa). |
| XSS lewat nama provider | Setiap nama provider dirender via `htmlspecialchars()`; nama buatan admin juga divalidasi panjang/format. |
| PII di audit | Audit reset hanya menyimpan nomor HP **ter-mask**. |

---

## 14. Asumsi, risiko, rollback

- **Asumsi:** DB lokal/live adalah MariaDB 12.3 (terverifikasi); `bank_accounts` mempertahankan kolom legacy (tidak perlu kolom baru — D2); logika keamanan dana penarikan tidak berubah (hanya data tujuan); provider tetap konteks Indonesia/IDR (tanpa katalog per negara).
- **Risiko:** (a) 17 member wajib re-bind pasca-migrasi — memang disengaja, dimitigasi key notifikasi member + UX halaman bind; (b) cascade rename menyentuh label baris historis — hanya tampilan, tanpa semantik finansial; (c) admin menonaktifkan provider yang sedang dipakai memblokir penarikan member tersebut — terlihat dari `active_bindings` + flash peringatan + verify CLI.
- **Rollback:** perubahan kode/dokumen cukup `git revert`; efek data migrasi reversibel sesuai §4 (kembalikan `is_primary=1` dari daftar ID terarsip yang dicetak) dengan catatan binding ganda terdokumentasi; tidak ada yang pernah di-hard-delete sehingga tidak ada data yang tak bisa dipulihkan. Backup `mysqldump` (atau mekanisme `backups/pre_seed_*.sql` milik seeder) direkomendasikan sebelum `--apply` di produksi.
