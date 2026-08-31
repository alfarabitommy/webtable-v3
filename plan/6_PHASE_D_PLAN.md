# Langkah 0 — Phase D: Session Guard Consistency (`Admin::export_csv()`)

**Project:** Synapse (webtable) · **Baseline:** `main` (branch kerja: `fase-0-housekeeping`)
**Mode:** PLAN — blueprint di bawah belum dieksekusi. Tunggu persetujuan user sebelum menyentuh `application/controllers/Admin.php`.
**Referensi:** `plan/0_HOUSEKEEPING_PLAN.md` §Phase D (D1–D2); AGENTS.md §Conventions (dual-auth hard separation); Phase C summary (`plan/5_PHASE_C_SUMMARY.md`).
**Constraint:** Tidak ada perubahan logika aplikasi selain satu guard di `export_csv()`. Tidak ada fitur baru.

---

## Ringkasan Perubahan

| # | Perubahan | Severity | File |
|---|-----------|----------|------|
| D1 | Ganti guard `admin_logged_in` → `admin_id` dan redirect `admin_auth` → `control-panel` (defense-in-depth, selaras konvensi dual-auth) | Low | `application/controllers/Admin.php` |

Hanya 2 baris yang berubah (guard `if` + target redirect). Seluruh logika streaming CSV, HTTP headers, dan pemanggilan database **100% tidak disentuh**.

---

## 1. Target Identification & Root Cause

### 1.1 Lokasi persis `export_csv()` di `application/controllers/Admin.php`

| Elemen | Baris | Isi |
|---|---|---|
| Signature method | **750** | `public function export_csv($type = '')` |
| Guard legacy | **752** | `if (!$this->session->userdata('admin_logged_in')) {` |
| Redirect legacy | **753** | `redirect('admin_auth');` |
| Tutup guard | **754** | `}` |
| Logika CSV | **756–806** | validasi tipe, load model, switch data, header(), streaming `php://output`, `exit` |

### 1.2 Mengapa guard legacy melanggar konvensi dual-auth

AGENTS.md §Conventions: *"Dual-auth hard separation: `user_id` vs `admin_id` sessions — never shared; admin routes redirect to `control-panel` when `admin_id` missing."*

Fakta terverifikasi di codebase:

1. **Kunci `admin_logged_in` TIDAK PERNAH di-set** — `grep -rn "admin_logged_in" application/` hanya menghasilkan 1 match: baris 752 itu sendiri (guard yang salah). Kunci sesi admin yang asli adalah **`admin_id`**, di-set di `Admin_auth::login()` (`application/controllers/Admin_auth.php:24`) dan dibaca ulang di constructor guard serta `Admin_auth::__construct()` (baris 12).
2. **`admin_auth` bukan route yang terdefinisi** — `application/config/routes.php:24` hanya mendaftarkan `$route['control-panel'] = 'Admin_auth/login'`. `redirect('admin_auth')` menghasilkan URL `/admin_auth` → CI3 memanggil `Admin_auth::index()` yang **tidak ada** (Admin_auth hanya punya `__construct()` dan `login()`) → fallback 404. Jadi guard legacy, jika pernah tereksekusi, mengarahkan admin ke halaman 404, bukan ke login.
3. **Inkonsisten dengan guard kanonik** — constructor `Admin` (baris 14–16) sudah memakai pola yang benar:
   ```php
   if (!$this->session->userdata('admin_id')) {
       redirect('control-panel');
   }
   ```
   Guard di `export_csv()` memakai pola lama yang berbeda (`admin_logged_in` + `admin_auth`) — melanggar "single source of truth" konvensi dual-auth.
4. **Dead code yang menyesatkan** — karena constructor guard (baris 14–16) berjalan lebih dulu untuk SEMUA method (termasuk `export_csv()`), guard legacy saat ini tidak pernah tercapai. Ia "tidak berbahaya" hanya karena bersandar pada guard lain; jika constructor guard dihapus/dilemahkan di masa depan, `export_csv()` akan terbuka tanpa proteksi yang benar. Menjaga agar method self-consistent = **defense-in-depth**, alasan utama patch ini.

**Root cause:** `export_csv()` ditulis dengan pola guard lama (kemungkinan salinan dari era sebelum `Admin_auth` + route `control-panel` diperkenalkan), tidak ikut dimigrasikan ke konvensi `admin_id`/`control-panel`.

---

## 2. Session Guard Alignment Blueprint

### 2.1 Diff eksak (satu-satunya perubahan di `Admin.php`)

```diff
--- a/application/controllers/Admin.php
+++ b/application/controllers/Admin.php
@@ -749,7 +749,7 @@ class Admin extends CI_Controller {
     // ===================================================================

     public function export_csv($type = '')
     {
-        if (!$this->session->userdata('admin_logged_in')) {
-            redirect('admin_auth');
+        if (!$this->session->userdata('admin_id')) {
+            redirect('control-panel');
         }

         $allowed = ['ledger', 'rentals', 'withdrawals'];
```

```php
// AFTER — selaras dengan constructor guard (baris 14–16) dan Admin_auth session key
if (!$this->session->userdata('admin_id')) {
    redirect('control-panel');
}
```

Catatan implementasi:
- Hanya baris 752–753 yang diganti; baris 754 (`}`) dan sisanya tidak berubah.
- Tidak ada import/use baru, tidak ada perubahan constructor, tidak ada perubahan route.

### 2.2 Bagian yang DIJAMIN 100% tidak tersentuh (baris 756–806)

| Blok | Baris | Isi |
|---|---|---|
| Validasi tipe | 756–759 | `$allowed = ['ledger', 'rentals', 'withdrawals']` + `show_404()` |
| Load model & tanggal | 761–762 | `Admin_model` load, `$date = date('Y-m-d')` |
| Switch 3 tipe (DB calls) | 764–784 | `get_all_ledger()`, `get_active_rentals()`, `get_all_withdrawals()`; masing-masing menetapkan `$filename` & `$headers` |
| HTTP headers | 786–790 | `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment`, `Pragma: no-cache`, `Expires: 0` |
| Streaming | 792–805 | `fopen('php://output','w')`, BOM UTF-8 `\xEF\xBB\xBF`, `fputcsv($fp, $headers)`, loop `fputcsv`, `fclose`, `exit` |

Tidak ada satu baris pun dari blok di atas yang diubah; `git diff -U5 application/controllers/Admin.php` harus menunjukkan hanya hunk guard (±2 baris).

---

## 3. Verification & Testing Protocol

### 3.1 Syntax lint (Roadmap Rule — semua file PHP baru/diubah)

```bash
php -l application/controllers/Admin.php
```

Wajib output: `No syntax errors detected in application/controllers/Admin.php`.

### 3.2 Test case perilaku (curl smoke — Roadmap Rule #3)

Setup server dev lokal (sesuai AGENTS.md / pola Phase C):

```bash
php -S localhost:8080
```

**Test 1 — Unauthenticated request → 302 ke `/control-panel`**

```bash
curl -s -o /dev/null -w "%{http_code} -> %{redirect_url}\n" http://localhost:8080/admin/export_csv/ledger
```

Kriteria lolos:
- Status `302`.
- `redirect_url` (header `Location`) berakhir di `/control-panel` (mis. `http://localhost:8080/control-panel`).

Catatan: redirect ini dieksekusi oleh **constructor guard** (baris 14–16) yang berjalan sebelum body method — ini memang perilaku yang diharapkan; patch menjadikan guard di dalam method konsisten dengan perilaku tersebut (defense-in-depth, verifikasi via review diff §3.3).

**Test 2 — Authenticated admin → 200 dengan CSV streaming**

Langkah:
1. Login admin, simpan cookie sesi:
   ```bash
   curl -s -c /tmp/admin_cookies.txt -o /dev/null \
     -X POST http://localhost:8080/control-panel \
     -d "username=<admin_username>&password=<admin_password>"
   ```
2. Minta CSV dengan cookie tersebut:
   ```bash
   curl -s -b /tmp/admin_cookies.txt -D - -o /tmp/ledger.csv \
     http://localhost:8080/admin/export_csv/ledger
   ```

Kriteria lolos:
- Status `200`.
- Header: `Content-Type: text/csv; charset=utf-8` dan `Content-Disposition: attachment; filename="wallet_ledger_<tanggal>.csv"`.
- Body diawali BOM UTF-8 dan berisi baris header:
  ```bash
  head -c 3 /tmp/ledger.csv | xxd   # →  ef bb bf
  head -1 /tmp/ledger.csv           # →  ID,User ID,Amount,Type,Description,Created At
  ```

**Test 3 (sekunder, opsional) — tipe tidak valid → 404**

```bash
curl -s -b /tmp/admin_cookies.txt -o /dev/null -w "%{http_code}\n" \
  http://localhost:8080/admin/export_csv/bogus
```
→ `404` (logika `show_404()` di baris 757–759 — konfirmasi blok validasi tetap utuh).

**Test 4 (sekunder, opsional) — tipe `rentals` & `withdrawals`**

Ulangi Test 2 dengan `rentals` dan `withdrawals` → 200 + CSV (memastikan ketiga cabang `switch` tidak terganggu oleh patch).

### 3.3 Review diff (verifikasi "hanya guard yang berubah")

```bash
git diff -U5 -- application/controllers/Admin.php
```

Kriteria lolos: hunk tunggal yang hanya mengubah 2 baris guard (`admin_logged_in` → `admin_id`, `admin_auth` → `control-panel`); **tidak ada** perubahan lain di file.

### 3.4 Hygiene grep

```bash
grep -rn "admin_logged_in" application/ || echo "CLEAN: 0 matches"
```
→ wajib `CLEAN: 0 matches` setelah patch (satu-satunya referensi legacy dihapus).

---

## 4. Files Touched (Phase D saja)

| File | Action |
|------|--------|
| `plan/6_PHASE_D_PLAN.md` | **new** — blueprint ini |
| `application/controllers/Admin.php` | edit — guard `export_csv()` (baris 752–753): `admin_id` → `control-panel` |

**STRICT RULE:** `application/controllers/Admin.php` TIDAK diubah sampai user menyetujui eksekusi Phase D.
