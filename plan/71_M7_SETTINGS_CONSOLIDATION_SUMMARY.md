# plan/71 — M7 SETTINGS CONSOLIDATION SUMMARY

Status: **DONE — implemented per plan/70** (M7: two settings stores + admin settings UI overhaul)
Round: M7 | Date: current | Branch-style commit message suggestion: `M7: konsolidasi store pengaturan + UI settings 2 kolom (plan/70-71)`

---

## 1. Ringkasan

M7 ditutup: `site_settings` (store legacy, kolom `setting_value`) didekomisioning penuh dan seluruh pengaturan kini hidup di satu store `system_settings` (kolom `key_value`). Endpoint admin disatukan ke `/admin/settings` (satu form kontak + finansial), `financial_settings` menjadi redirect shim, navigasi "Aturan Finansial" dihapus, dan halaman settings dirombak menjadi layout 2 kolom responsif dengan perbaikan overflow baris tier.

## 2. Perubahan Database (dieksekusi di db_webtable live)

1. **Migrasi data idempotent** — `scripts/migrate_m7_settings.php` (`--dry-run`/`--apply`, pola script repo lain):
   ```sql
   INSERT IGNORE INTO system_settings (key_name, key_value, updated_at)
   SELECT key_name, setting_value, updated_at FROM site_settings;
   ```
   Hasil `--apply`: `affected/inserted: 2` → verifikasi per key **OK** (`wa_number = 628000000000`, `support_email = support@synapse.id`) → **`DROP TABLE site_settings` berhasil**.
2. **Idempotensi terkonfirmasi**: `--dry-run` ulang = "site_settings sudah tidak ada — migration sudah diterapkan sebelumnya (no-op)", exit 0.
3. **`database.sql`**: blok `site_settings` (DDL + INSERT) dihapus; seed `system_settings` ditambah
   `('wa_number','628000000000'), ('support_email','support@synapse.id')` (INSERT IGNORE, komentar M7).
4. **`database_seed.sql`**: seed `system_settings` ditambah dua key kontak yang sama.
5. **`scripts/seed_database.php`**: `site_settings` dihapus dari daftar `$tables_expected` pre-flight.

## 3. Perubahan Model (`application/models/Admin_model.php`)

- **Ditambah** (di section SYSTEM SETTINGS):
  - `get_settings_map($keys = [])` — baca peta `key_name => key_value` dari `system_settings` (opsional filter `where_in`).
  - `update_system_settings($data, $audit = null)` — batch upsert (`ON DUPLICATE KEY UPDATE`) semua key dalam **satu transaksi** + satu audit row via `_write_audit()` (rollback ikut menghapus audit).
- **Dihapus**: `get_all_settings()` dan `update_settings()` (pembaca/penulis `site_settings`).
- `get_setting()`/`set_setting()` tetap (dipakai `Wallet_model`, `Auth`, toggle dashboard); `Wallet_model::get_financial_config()` tidak berubah (sudah baca `system_settings`).

## 4. Perubahan Controller & Navigasi

- **`Admin::settings()`** (endpoint otoritatif tunggal `/admin/settings`):
  - **GET** → render view terpadu: kontak via `get_settings_map(['wa_number','support_email'])`, finansial via `Wallet_model::get_financial_config()` (dynamic → fallback config).
  - **POST** → validasi **all-or-nothing**: kontak (`required|numeric`, `required|valid_email`) + `Wallet_model::validate_financial_settings($raw)`; error apa pun → satu flashdata, tidak ada yang disimpan. Persist semua key (kontak + finansial) via `update_system_settings()` dalam satu TX dengan audit M5/A1: action `admin_update_settings`, `details {keys, before, after}` **per key yang berubah**.
  - **POST-only gate**: selain GET/POST → `show_404()`.
- **`Admin::financial_settings()`** → redirect shim `redirect('admin/settings')` (backward compat; route `admin/financial-settings` dipertahankan).
- **`Help::index()`** → baca `wa_number`/`support_email` via `get_setting()` dengan fallback nilai lama.
- **`admin/templates/sidebar.php`** → item "Aturan Finansial" dihapus; satu item "Pengaturan" (active state mencakup segment lama `financial-settings` selama redirect).

## 5. Perubahan View / UI

- **`admin/settings.php` ditulis ulang** menjadi form tunggal (`id="settingsForm"`, `data-guard-submit="1"`):
  - Container `max-w-7xl mx-auto`; grid `grid grid-cols-1 xl:grid-cols-2 gap-6 items-start`.
  - **Kolom kiri**: Card General & Support (`wa_number`, `support_email`) + Card Jam Operasional & Hari Aktif (checkbox 2/4 kolom, jam buka/tutup).
  - **Kolom kanan**: Card Biaya Penarikan (fixed/min/max 3 kolom + tier dinamis) + Card Biaya Deposit (enabled/type/nilai + sinkronisasi suffix).
  - **Submit bar** tunggal di bawah grid.
- **Fix overflow tier** (PHP loop **dan** template JS `addRow()` identik): baris flex lebar-tetap (`w-16`/`flex-1`/`w-20`) diganti **grid responsif label-di-atas-input** `grid grid-cols-2 sm:grid-cols-12` (sel `min-w-0`, input `w-full min-w-0`, tombol hapus sel sendiri `justify-end`). Semua kontrak JS dipertahankan: `.tier-row/.tier-min/.tier-max/.tier-pct/.tier-del`, `wd_fee_tiers`, `tierStatus`, `serializeTiers()`, validasi kontiguitas `[min,max)`.
- **Guard M4**: `data-guard-submit="1"` → script global `templates/csrf_meta.php` (sudah dimuat `admin/templates/header.php`) men-disable tombol + spinner "Memproses..." + blokir double-submit. Submit listener tier memanggil `e.preventDefault(); e.stopPropagation();` saat tier invalid agar guard **tidak** menandai form sebagai submitting.
- **`admin/financial_settings.php` dihapus.**

## 6. Verifikasi

| Check | Hasil |
|---|---|
| `php -l` 7 file tersentuh (model, 2 controller, sidebar, settings view, 2 script) | ✅ tidak ada syntax error |
| `grep site_settings\|get_all_settings\|update_settings` di `application/ scripts/ database.sql database_seed.sql` | ✅ hanya sisa tak berfungsi: string action audit `admin_update_settings`, komentar, dan tool migrasi |
| Migrasi live `db_webtable` (dry-run → apply → verifikasi → DROP) | ✅ 2 key tersalin & terverifikasi, `site_settings` drop, re-run no-op |
| Smoke test `php -S` (tanpa session): `admin/settings` → guard redirect, `control-panel` 200, `help` → guard redirect, tanpa error PHP di log | ✅ |

**Belum diverifikasi (butuh UAT manual via browser, sesuai konvensi repo):** render grid 2 kolom + submit penuh dengan sesi admin, baris audit before→after, tidak ada horizontal scroll 375px/xl, dan 302 `/admin/financial-settings` saat login.

## 7. Files Touched

- `application/models/Admin_model.php`
- `application/controllers/Admin.php`
- `application/controllers/Help.php`
- `application/views/admin/settings.php` (rewrite)
- `application/views/admin/financial_settings.php` (dihapus)
- `application/views/admin/templates/sidebar.php`
- `database.sql`, `database_seed.sql`
- `scripts/seed_database.php`
- `scripts/migrate_m7_settings.php` (baru, one-off; aman dijalankan ulang = no-op)

## 8. Rollback

- Kode: `git revert` (deploy atomik).
- DB: data migrasi lossless (hanya 2 key, nama sama) — restore `site_settings` via `INSERT ... SELECT ... FROM system_settings WHERE key_name IN ('wa_number','support_email')` + DDL `site_settings` dari histori `database.sql`.

---
*End of plan/71_M7_SETTINGS_CONSOLIDATION_SUMMARY.md.*
