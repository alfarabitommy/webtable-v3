# Plan 96 — Maintenance Mode Toggle: Execution Summary (plan/95)

> **Status:** IMPLEMENTED (kode + seed selesai; `php -l` 100% hijau).
> Verifikasi runtime penuh (browser/curl + live DB) BELUM dijalankan di
> sandbox ini — tidak ada MySQL/DB live yang dapat dijangkau. Langkah yang
> tersisa & SQL migrasi live tercantum di §4.

---

## 1. Ringkasan

Plan 95 (blueprint `plan/95_MAINTENANCE_MODE_PLAN.md`) dieksekusi sesuai
Section 8 (langkah 1–15; langkah 16 commit **tidak** dilakukan — menunggu
instruksi pemilik repositori). Fitur: saklar **Maintenance Mode member site**
terpisah penuh dari `is_registration_open` — key `is_maintenance_mode` di
`system_settings`; gate global di constructor `MY_Controller`/`Auth`/`Lang`;
admin routes + session `admin_id` + CLI **exempt**; non-admin saat aktif
menerima HTTP 503 (HTML dwibahasa) atau JSON envelope `MAINTENANCE_MODE`;
toggle admin `POST /admin/toggle-maintenance` dengan audit atomik
`admin_toggle_maintenance`.

## 2. Perubahan File

| # | File | Aksi | Isi |
|---|---|---|---|
| 1 | `database.sql` | ubah | seed `('is_maintenance_mode','0')` + komentar DDL `system_settings` |
| 2 | `database_seed.sql` | ubah | seed `('is_maintenance_mode','0')` |
| 3 | `application/helpers/maintenance_helper.php` | **baru** | `maintenance_is_active()`, `_maintenance_wants_json()`, `maintenance_gate()` (envelope JSON 503 `MAINTENANCE_MODE` / view HTML 503; bypass CLI + `admin_id`; `function_exists()` wrap) |
| 4 | `application/config/autoload.php` | ubah | `'maintenance'` ditambahkan ke `$autoload['helper']` |
| 5 | `application/views/errors/html/maintenance.php` | **baru** | halaman 503 standalone, inline CSS, dwibahasa ID/EN, `noindex`, tanpa shell member |
| 6 | `application/core/MY_Controller.php` | ubah | `maintenance_gate()` = statement pertama setelah `parent::__construct()` (sebelum pin WIB M2 / guard / sweep M3); komentar M2 disesuaikan |
| 7 | `application/controllers/Auth.php` | ubah | `maintenance_gate()` di constructor (sebelum pin WIB M2) |
| 8 | `application/controllers/Lang.php` | ubah | `maintenance_gate()` di constructor |
| 9 | `application/config/routes.php` | ubah | `$route['admin/toggle-maintenance'] = 'admin/toggle_maintenance';` |
| 10 | `application/controllers/Admin.php` | ubah | var dashboard `is_maintenance_mode` + method `toggle_maintenance()` (POST-only 405 JSON, CSRF, TX atomik + audit) |
| 11 | `application/views/admin/dashboard.php` | ubah | wrapper 2 tombol (registrasi + maintenance) dgn state subtle/danger + JS `toggleMaintenance()` |
| 12 | `AGENTS.md` | ubah | bullet quick-add plan/95 di Notes |
| 13 | `plan/96_MAINTENANCE_MODE_SUMMARY.md` | **baru** | file ini |

## 3. Hasil Verifikasi (statis)

| Cek | Hasil |
|---|---|
| `php -l` 9 file PHP (baru+ubah: helper, autoload, view 503, `MY_Controller`, `Auth`, `Lang`, `routes`, `Admin`, `dashboard`) | ✅ semua "No syntax errors detected" |
| Panggilan `maintenance_gate();` di 3 constructor (MY_Controller:13, Auth:12, Lang:27) | ✅ |
| Autoload helper | ✅ `autoload.php:95` memuat `'maintenance'` |
| Seed canonical | ✅ `database.sql:285` & `database_seed.sql:263` `('is_maintenance_mode','0')` |
| Route | ✅ `routes.php:76` |
| Controller admin | ✅ data dashboard `:118`, `toggle_maintenance()` `:1308`, audit `admin_toggle_maintenance` `:1329` |
| Dashboard | ✅ tombol `#maintenance-toggle-btn` `:36`, label `#mm-label` `:42`, JS `toggleMaintenance()` `:285` |
| Urutan gate di MY_Controller | ✅ gate sebelum `SET time_zone`, `i18n_apply`, guard, sweep |

## 4. Belum Diverifikasi / Tindakan Tersisa (butuh live DB & browser)

1. **Migrasi live DB** (idempotent — jalankan sekali):
   ```sql
   INSERT IGNORE INTO system_settings (key_name, key_value) VALUES ('is_maintenance_mode', '0');
   SELECT key_name, key_value FROM system_settings WHERE key_name = 'is_maintenance_mode';
   ```
2. Jalankan matriks verifikasi `plan/95 §9` (25 kasus): HTML 503 guest/member,
   JSON 503 AJAX (`X-Requested-With` & `Accept: application/json`), bypass
   admin/CLI, toggle ON/OFF via dashboard, baris audit
   `admin_toggle_maintenance` (`was_maintenance`/`is_maintenance`), CSRF 403,
   405 non-POST, regresi `toggle_registration`, sesi member utuh pasca-mode.
3. Browser check visual: state tombol subtle vs ring merah/pulse.
4. Commit (pesan Indonesian) — sengaja **belum** dibuat (langkah 16).

## 5. Catatan & Deviasi Minor

- **Nuansa urutan statement DB:** gate memuat `Admin_model`, yang konstruktornya
  menjalankan `SET time_zone = '+07:00'` sebelum SELECT `get_setting()` — jadi
  statement pertama pada request yang di-gate adalah SET WIB (bukan SELECT).
  Tidak ada pembacaan `TIMESTAMP`/bisnis sebelum pin; komentar M2 sudah
  disesuaikan. Tidak ada dampak fungsional.
- **Konteks kerja:** perubahan ini menumpuk di atas working-tree plan/94
  (dual-language, belum di-commit) yang sudah ada sebelumnya — file plan/94
  tidak disentuh.
- **Drift seed lama** (`database_seed.sql` tanpa key `rebate_*` plan/89) di luar
  scope; hanya key plan/95 yang disinkronkan di kedua file.
- **Live DB tidak terjangkau** di sandbox (tidak ada MySQL/`mysql` client
  terhubung) — migrasi §4.1 dan seluruh uji runtime §4.2 adalah prasyarat
  sign-off operasional penuh.
