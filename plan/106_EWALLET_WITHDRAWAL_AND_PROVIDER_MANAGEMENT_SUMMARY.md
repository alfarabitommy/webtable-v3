# Plan 106 — Summary: Exclusive E-Wallet Withdrawal Gateway & Dynamic Provider Management

**Status:** ✅ COMPLETED — implemented, migrated on the live local DB, and runtime-verified.
**Plan:** `plan/106_EWALLET_WITHDRAWAL_AND_PROVIDER_MANAGEMENT_PLAN.md`
**Date:** 2026-09-13 (WIB) · **Branch:** `main` · **DB:** `db_webtable` (MariaDB 12.3.2)

---

## 1. What shipped

Penarikan dana kini **eksklusif e-wallet** (DANA, ShopeePay, OVO, GoPay) dengan **katalog provider yang dikelola admin secara dinamis**, binding berbasis nomor HP e-wallet, aksi **reset/unbind** untuk admin, dan perombakan terminologi dwibahasa yang tetap lolos seluruh gate kamus.

| Area | Hasil |
|---|---|
| Skema | Tabel baru `ewallet_providers` (`code` UNIQUE) + seed 4 provider; `bank_accounts` **strukturnya tidak diubah** (zero-breakage) |
| Migrasi | `scripts/migrate_106_ewallet_withdrawal.php` — 7 fase, idempoten, **sudah dijalankan** (`--apply` ×2 + `--verify` exit 0) |
| Data live | 17 baris nama bank dipetakan ke provider; **17 binding legacy diarsipkan** (`is_primary=0`); 0 baris dihapus; 0 orphan FK |
| Admin | `/admin/ewallet-providers` (tambah/rename/toggle) + kartu **Akun E-Wallet** & aksi **reset** di `/admin/user_detail/{id}` — 100% Indonesia (L1) |
| Member | `/wallet/bind_bank` card selector 2×2 dari provider aktif + validasi nomor HP; gate penarikan menolak binding kosong/provider nonaktif |
| Kamus | 9 rename + 14 nilai ditulis ulang + 8 key baru → **602/602** identik; `audit_i18n_parity.php` & `audit_i18n_hardcoded.php` exit 0 |

### Keputusan yang dieksekusi (dari plan §1.1)
- **D1** — binding legacy: nama dipetakan ke provider **dan** diarsipkan → member wajib re-bind (17/17 baris; 0 nomor yang sudah valid).
- **D2** — reset/unbind = **arsip via `is_primary=0`** (`is_primary` kini flag binding aktif; `get_user_ewallet()` memfilternya). Tanpa DDL, aman terhadap `fk_withdrawals_bank` (RESTRICT), riwayat penarikan tetap utuh.
- **D3** — selector member = **card/radio 2×2** murni CSS `peer-checked:`, tanpa JS.

Arsitek: **D4** `bank_name` menyimpan nama provider + cascade rename · **D5** provider nonaktif memblokir penarikan · **D6** minimal satu provider aktif · **D7** tanpa hard delete provider · **D8** nama provider = data, bukan entri kamus · **D9** teks historis admin tidak di-retro-edit.

---

## 2. File yang berubah

**Baru (5)**

| File | Baris | Isi |
|---|---|---|
| `application/helpers/ewallet_helper.php` | 158 | Choke-point aturan nomor HP: `ewallet_phone_normalize()` (62/0062→0, awalan 0), `ewallet_phone_is_valid()`, `ewallet_phone_validate()`, `ewallet_phone_mask()`, `ewallet_phone_pattern()`, `ewallet_default_code()` — semua `function_exists()`-guarded (aman CLI) |
| `application/models/Ewallet_model.php` | 244 | Katalog: `get_active_providers`, `get_active_provider`, `get_provider_by_name`, `get_provider`, `count_active_providers`, `get_providers_admin` (＋ hitungan binding aktif/total), `code_exists`, `name_exists`, `create_provider`, `rename_provider`, `set_provider_active` |
| `application/views/admin/ewallet_providers.php` | 274 | Tabel provider + modal tambah/rename (vanilla JS, `form_open()` server-rendered → token CSRF tidak pernah ditulis ulang JS) |
| `scripts/migrate_106_ewallet_withdrawal.php` | 619 | 7 fase (PRE-FLIGHT → INSPECT → DDL → SEED → BACKFILL → ARSIP → VERIFY); `--dry-run`/`--apply`/`--verify`/`--keep-bindings`/`--default-provider`; exit 0/1/2 |
| `plan/106_EWALLET_WITHDRAWAL_AND_PROVIDER_MANAGEMENT_PLAN.md` | 416 | Blueprint yang disetujui |

**Diubah (23)** — `database.sql` (DDL `ewallet_providers` + seed + blok migrasi live + komentar semantik `bank_accounts`) · `database_seed.sql` (section `ewallet_providers` + `bank_accounts` e-wallet) · `scripts/seed_database.php` (count provider + 4 assertion e-wallet) · `scripts/audit_i18n_parity.php` (hapus token allowlist mati `'bank account'`) · `application/config/autoload.php` (`+ 'ewallet'`) · `application/config/routes.php` (4 route admin) · `application/models/Wallet_model.php` (`get_user_ewallet`, `bind_user_ewallet`, `unbind_user_ewallet`, `reassign_provider_name`, hardening `create_withdrawal` → code `no_ewallet`) · `application/controllers/Wallet.php` (bind_bank ditulis ulang, `_resolve_active_provider()`, gate WD GET+POST, `WD_ERR_KEYS['no_ewallet']`, `Ewallet_model` di constructor) · `application/controllers/Admin.php` (4 method CRUD provider + `reset_ewallet` + data `user_detail` + header CSV) · 7 view (`wallet/bank_bind`, `wallet/withdraw`, `wallet/index`, `profile/index`, `admin/user_detail`, `admin/templates/sidebar`, `admin/history`) · 2 kamus · 3 dokumen (`docs/2_ERD.md`, `docs/3_ROADMAP.md`, `AGENTS.md`).

**Tidak disentuh:** DDL `bank_accounts`/`withdrawals`, `wallet_ledger`, aritmetika uang, `system/` (termasuk fix Pagination PHP 8.3), `views/wallet/pay.php`, `Admin_model.php` (semua join berbasis id tetap bekerja).

---

## 3. Migrasi: hasil eksekusi nyata

```
php scripts/migrate_106_ewallet_withdrawal.php --dry-run   → exit 0 (tanpa tulis)
php scripts/migrate_106_ewallet_withdrawal.php --apply     → exit 0
php scripts/migrate_106_ewallet_withdrawal.php --apply     → exit 0 (re-run idempoten: 0 dipetakan / 0 diarsipkan)
php scripts/migrate_106_ewallet_withdrawal.php --verify    → exit 0
```

| Fase | Hasil live |
|---|---|
| DDL | `ewallet_providers` dibuat (id 1–4) |
| Seed | DANA / SHOPEEPAY / OVO / GOPAY (`INSERT IGNORE`) |
| Backfill | 17 baris: BCA/Bank BCA/Bank Central Asia (BCA) → **DANA** (7), Mandiri → **OVO** (3), BRI → **GoPay** (3), BNI → **DANA** (2), CIMB → **ShopeePay** (2) |
| Arsip | 17 binding legacy `is_primary=1 → 0` (ID `1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,17,21`) — rollback: `UPDATE bank_accounts SET is_primary=1 WHERE id IN (…)` |
| Verify | provider kanonik 4/4 aktif · drift nama 0 · nomor HP valid 0 invalid · orphan FK 0 · **total baris `bank_accounts` tetap 17 saat migrasi** (tidak ada yang dihapus) |

> Setelah pengujian end-to-end, tabel berisi 19 baris: 17 baris legacy (diarsipkan) **+ 2 baris QA** hasil uji bind user `wd_test` (id 58) — keduanya juga diarsipkan, jadi binding aktif akhir = **0**.

Verifikasi seed kanonik pada DB scratch (`database.sql` → section `ewallet_providers` + `bank_accounts`): **4 provider, 13 binding, 13 aktif, 0 drift, 0 nomor invalid**.

---

## 4. Matriks verifikasi (semua dijalankan)

| # | Uji | Hasil |
|---|---|---|
| V0 | `php -l` seluruh file baru/terubah | ✅ tanpa error |
| V1 | `audit_i18n_parity.php` | ✅ exit 0 · **EN 602 / ID 602** · 1:1 OK · 0 identik di luar allowlist · P3 0 · P6 newline 0 · P3b 0 |
| V2 | `audit_i18n_hardcoded.php` | ✅ exit 0 · 0 temuan (78 file dipindai) |
| V3–V5 | migrasi `--dry-run` → `--apply` ×2 → `--verify` | ✅ exit 0; re-run no-op |
| V6 | `withdrawals LEFT JOIN bank_accounts` orphan | ✅ 0 (9 baris tereferensi tetap resolve; riwayat tampil normal) |
| V7 | `GET /admin/ewallet-providers` | ✅ 200, 4 provider, copy Indonesia |
| V8 | create / duplicate / rename cascade / toggle | ✅ create `TESTX` → flash sukses; code duplikat → "sudah dipakai"; rename `DANA → "DANA E-Wallet"` → **10 binding ikut diperbarui** + audit `rebound_bindings:10`; toggle off → flash + peringatan "1 akun member terikat akan diblokir" |
| V9 | POST-only guard | ✅ `GET /admin/ewallet-providers/create` → 404; `GET /admin/reset_ewallet/58` → 404 |
| V9b | guard D6 provider terakhir | ✅ dengan hanya GoPay aktif, toggle-off → **ditolak** ("Minimal satu provider e-wallet harus aktif…"), status tetap aktif |
| V10 | validasi binding member | ✅ 9 digit / 14 digit / non-08 → pesan `bb_err_phone`; `provider_id=999` → `bb_err_provider_invalid`; `6281288880001` → tersimpan kanonik `081288880001`; field kosong → `bb_err_required_fields` |
| V11 | immutability | ✅ POST kedua saat terikat → "Your e-wallet account is already linked…", tetap **tepat 1 binding aktif**, nol baris baru |
| V12 | gate provider nonaktif | ✅ `/wallet/withdraw` → 307 ke `bind_bank` + flash "provider is currently inactive…" dan kartu notice di halaman bind |
| V13 | reset/unbind admin | ✅ `is_primary 1→0`, audit `admin_reset_ewallet` dengan `account_number_masked: 0812*****001`, notifikasi `notif_ewallet_reset` (title_key), user detail → "User belum mengikat akun e-wallet"; POST kedua → "tidak memiliki akun e-wallet terikat" |
| V14 | penarikan end-to-end | ✅ withdrawal `WD-20260913214021-58` (Rp 100.000, `bank_account_id` = binding e-wallet) dibuat; kartu pending member menampilkan `DANA · 0812*****001`; dashboard admin `DANA · 081288880001 · Budi Test`; kolom history `E-Wallet`; CSV header `Provider E-Wallet / Nomor HP E-Wallet / Nama Pemilik Akun`; **decline → refund** (pasangan debit+credit Rp 100.000 di `wallet_ledger`) |
| V15 | jalur seed | ✅ diverifikasi pada DB scratch (lihat §3) |
| V16 | L1 admin | ✅ seluruh copy/flash/dialog admin baru berbahasa Indonesia, tanpa pemanggilan `lang(` |
| V17 | dwibahasa member | ✅ idiom `id` → "Provider E-Wallet / Nomor HP E-Wallet / Nama Pemilik Akun / Hanya angka, diawali 08, 10-13 digit." |

### Catatan state setelah verifikasi
- `system_settings.wd_operational_days` **dikembalikan** ke `1,2,3,4,5,6` (sempat dilonggarkan ke `…,7` untuk menguji POST penarikan di hari Minggu).
- Password QA user **`wd_test` (id 58) dikembalikan** ke hash semula setelah pengujian login.
- Binding QA hasil uji diarsipkan (`is_primary=0`) sehingga DB kembali ke keadaan pasca-migrasi (**0 binding aktif**).
- Baris audit dari aksi uji (create/rename/toggle provider uji, reset) dibiarkan utuh sebagai catatan jujur; provider uji `TESTX` (tanpa binding) dihapus manual — tidak pernah dipakai member.
- Backup pra-migrasi: `backups/pre_plan106_bank_accounts_withdrawals.sql`.

---

## 5. Kontrak arsitektur yang mengikat ke depan

1. **Satu penulis per tabel** — `Ewallet_model` (katalog provider) vs `Wallet_model` (binding `bank_accounts`).
2. **Nomor HP e-wallet** — `^08[0-9]{8,11}$` (numerik, awalan `08`, 10–13 digit), normalisasi `62/0062 → 0`, validasi & masking **hanya** lewat `ewallet_helper.php` (dipakai controller, view, admin, dan CLI).
3. **Nama provider tidak pernah dipercaya dari klien** — klien mengirim `provider_id`; server me-resolve ke katalog aktif.
4. **Reset = arsip** — `is_primary=0`; DELETE dilarang (FK RESTRICT + integritas riwayat).
5. **Provider tanpa hard delete** — salah buat ⇒ nonaktifkan; minimal satu provider harus aktif.
6. **Rename provider = cascade** ke `bank_accounts.bank_name` di dalam TX + audit `rebound_bindings`.
7. **`create_withdrawal()`** memverifikasi ulang ownership + status aktif binding di dalam TX terkunci (code `no_ewallet`).
8. Admin tetap 100% Indonesia (L1); penambahan katalog provider tidak pernah menambah entri kamus (D8).

---

## 6. Caveat operator (pra-eksisting, di luar plan 106)

`application/config/database.php` di working tree lokal memakai grup `$active_group='local'` dengan `$db['local']`/`$db['live']` (bukan `$db['default']`). Akibatnya **script lama yang membaca `$db['default']`** — `scripts/seed_database.php`, `migrate_102`, `migrate_104`, `migrate_105` — gagal dengan `[FATAL] Cannot read DB credentials from application/config/database.php`. `migrate_106` **tidak** terpengaruh: `load_db_config()`-nya me-resolve `$active_group` lebih dulu (fallback `default` → grup pertama). Jalur seed plan 106 diverifikasi langsung terhadap DB scratch sebagai gantinya. Perbaikan 3 baris pada `load_db_config()` script lama direkomendasikan sebagai follow-up terpisah (di luar scope plan ini).

---

## 7. Follow-up opsional (tidak dikerjakan)

- Notifikasi ke member saat provider yang dipakai dinonaktifkan (saat ini hanya flash admin + catatan di halaman bind).
- Kode unik/e-wallet provider per-negara bila katalog diperluas.
- Rekompresi aset gambar produk (plan/104) — tidak terkait.
