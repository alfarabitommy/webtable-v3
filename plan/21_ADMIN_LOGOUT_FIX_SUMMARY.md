# Admin Logout 404 Fix (SUMMARY)

**Project:** Synapse (webtable) · **Branch:** `fase-10b-rate-limiting` (kelanjutan Phase 10B)
**Status:** ✅ FIX SELESAI — **belum** di-commit, menunggu konfirmasi user.
**Laporan bug:** klik Logout di Admin Panel → navigasi ke `/admin/logout` → **404 Page Not Found**.

---

## 1. Root Cause

| Pemeriksaan | Hasil |
|---|---|
| `Admin_auth::logout()` | **TIDAK ADA** — `Admin_auth.php` hanya punya `login()` |
| `Admin::logout()` | **TIDAK ADA** — `Admin.php` tidak punya method logout sama sekali |
| Route `admin/logout` | **TIDAK ADA** di `application/config/routes.php` (hanya `control-panel` → `Admin_auth/login`) |
| Link sidebar | ✅ **Sudah benar** — `application/views/admin/templates/sidebar.php:58` → `site_url('admin/logout')` |

Link sudah menunjuk ke `/admin/logout`, tetapi tidak ada controller method maupun route yang memetakannya → CI3 melempar 404.

---

## 2. Fix

### 2.1 `application/controllers/Admin_auth.php` — method `logout()` baru

```php
/**
 * GET /admin/logout — destroy admin session, back to cloaked gateway.
 * Uses unset_userdata (bukan sess_destroy) agar flashdata success
 * tetap hidup hingga halaman control-panel dirender.
 */
public function logout() {
    $this->session->unset_userdata(['admin_id', 'admin_username']);
    $this->session->set_flashdata('success', 'Anda telah berhasil keluar.');
    redirect('control-panel');
}
```

- `unset_userdata()` dipilih alih-alih `sess_destroy()` (pola user `Auth::logout`) agar `flashdata('success')` yang di-set **setelah** unset tetap terbawa ke halaman `control-panel`.
- Hanya `admin_id` + `admin_username` yang dibersihkan — konsisten dengan `set_userdata` di `login()`; tidak menyentuh session user portal (dual-auth hard separation terjaga).

### 2.2 `application/config/routes.php` — route baru

```php
// Phase 7: Admin Portal (cloaked)
$route['control-panel'] = 'Admin_auth/login';
$route['admin/logout'] = 'admin_auth/logout';
```

### 2.3 `application/views/admin/login.php` — tampilkan flashdata success

View login admin sebelumnya hanya merender `flashdata('error')`, sehingga pesan sukses logout tidak akan terlihat. Ditambahkan banner emerald (mirror banner error merah):

```php
<?php if ($this->session->flashdata('success')): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-medium px-4 py-3 rounded-xl mb-4 flex items-center gap-2">
        <i class="fas fa-check-circle"></i>
        <?= $this->session->flashdata('success') ?>
    </div>
<?php endif; ?>
```

### 2.4 Link sidebar — tanpa perubahan

`application/views/admin/templates/sidebar.php:58` sudah `site_url('admin/logout')` — konsisten dengan route baru. Tidak ada edit yang dibutuhkan.

---

## 3. Verification

### 3.1 Lint (roadmap rule #5) — 3/3 lulus

```bash
$ php -l application/controllers/Admin_auth.php
No syntax errors detected in application/controllers/Admin_auth.php
$ php -l application/config/routes.php
No syntax errors detected in application/config/routes.php
$ php -l application/views/admin/login.php
No syntax errors detected in application/views/admin/login.php
```

### 3.2 Re-test manual di browser (wajib, environment dengan MySQL)

1. Login admin di `/control-panel` → dashboard `/admin`.
2. Klik **Logout** di sidebar → URL `admin/logout`.
3. **Ekspektasi:** redirect ke `/control-panel` (bukan 404) + banner hijau **"Anda telah berhasil keluar."**
4. Coba akses `/admin` langsung → redirect ke `/control-panel` (guard `admin_id` di constructor `Admin`).
5. `curl` alternatif: `curl -s -o /dev/null -w "%{http_code}\n" -b /tmp/admin_cookies.txt http://localhost:8080/index.php/admin/logout` → `302` ke `control-panel`.

---

## 4. Files Touched

| File | Perubahan |
|---|---|
| `application/controllers/Admin_auth.php` | edit — method `logout()` baru (§2.1) |
| `application/config/routes.php` | edit — `$route['admin/logout'] = 'admin_auth/logout';` (§2.2) |
| `application/views/admin/login.php` | edit — banner flashdata `success` (§2.3) |
| `plan/21_ADMIN_LOGOUT_FIX_SUMMARY.md` | **new** — dokumen ini |

**Tidak diubah:** `application/views/admin/templates/sidebar.php` (link sudah benar), `Admin.php` (guard admin_id di constructor sudah menangani akses pasca-logout).

---

*Menunggu konfirmasi user sebelum commit (commit terpisah dari Phase 10B atau digabung — keputusan user): `fix(admin): perbaiki 404 logout admin panel + tampilkan pesan sukses logout`.*
