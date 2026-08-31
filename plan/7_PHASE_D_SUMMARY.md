# Langkah 0 — Phase D: Session Guard Consistency (`Admin::export_csv()`)

**Project:** Synapse (webtable) · **Branch kerja:** `main` (menuju `fase-0-housekeeping`)
**Status:** ✅ SELESAI & TERVERIFIKASI (menunggu Phase E: final review + commit)

---

## Ringkasan Eksekusi

Phase D memperbaiki guard autentikasi legacy pada `Admin::export_csv()` agar selaras dengan konvensi dual-auth AGENTS.md (`admin_id` session + redirect ke `control-panel`). Hanya 2 baris guard yang diubah; seluruh logika CSV, HTTP headers, dan streaming **100% tidak tersentuh**.

### Perubahan

`application/controllers/Admin.php` — guard `export_csv()` (baris 752–753):

```diff
--- a/application/controllers/Admin.php
+++ b/application/controllers/Admin.php
@@ -747,12 +747,12 @@ class Admin extends CI_Controller {
     //  PHASE 9C: NATIVE CSV EXPORT
     // ===================================================================

     public function export_csv($type = '')
     {
-        if (!$this->session->userdata('admin_logged_in')) {
-            redirect('admin_auth');
+        if (!$this->session->userdata('admin_id')) {
+            redirect('control-panel');
         }

         $allowed = ['ledger', 'rentals', 'withdrawals'];
         if (!in_array($type, $allowed)) {
             show_404();
```

### Alasan (root cause)

- Kunci `admin_logged_in` **tidak pernah di-set** di seluruh codebase (satu-satunya match adalah baris guard lama itu sendiri); kunci sesi admin asli adalah `admin_id` (di-set di `Admin_auth::login()`, `Admin_auth.php:24`).
- `admin_auth` **bukan route terdefinisi** (`routes.php:24` hanya mendaftarkan `control-panel` → `Admin_auth/login`; `Admin_auth` tidak punya `index()` → fallback 404).
- Guard lama tidak konsisten dengan constructor guard (`Admin.php:14–16`) yang sudah memakai `admin_id`/`control-panel`. Karena constructor berjalan lebih dulu, guard lama adalah *dead code* — patch ini menjadikan method self-consistent (defense-in-depth).

### Bagian yang dijamin tidak berubah (baris 756–806)

Validasi tipe (`$allowed` + `show_404()`), load `Admin_model`, switch 3 tipe (DB calls `get_all_ledger()`/`get_active_rentals()`/`get_all_withdrawals()`), HTTP headers (`Content-Type: text/csv`, `Content-Disposition: attachment`, `Pragma`, `Expires`), streaming `php://output` dengan UTF-8 BOM, `fclose`, `exit`.

---

## Hasil Verifikasi

| Check | Command | Hasil |
|---|---|---|
| Syntax lint | `php -l application/controllers/Admin.php` | ✅ `No syntax errors detected` |
| Hygiene grep | `grep -rn "admin_logged_in" application/` | ✅ `CLEAN: 0 matches` |
| Scope diff | `git diff -U5 -- application/controllers/Admin.php` | ✅ single hunk, hanya 2 baris guard |
| Inspeksi file | `read_file` baris 748–808 | ✅ guard 752–753 ter-patch; logika CSV byte-identical |
| Code review | `skill:review` | ✅ verdict **warn — correct & well-scoped** |

**Belum terverifikasi (kendala lingkungan):** smoke test curl perilaku (unauthenticated → 302 ke `/control-panel`; authenticated → 200 CSV + BOM) tidak dapat dijalankan di sandbox ini — tidak ada MySQL client/server, dan `Admin::__construct()` melakukan koneksi DB (`db_webtable`) sebelum guard sehingga request HTTP akan 500 saat connect. Bukti statis: guard baru identik dengan constructor guard yang sudah terpakai di seluruh aplikasi.

---

## Status Step 0 (Langkah 0 — Housekeeping)

| Sub-phase | Deliverable | Status |
|---|---|---|
| A | `docs/3_ROADMAP.md` — Phase 9 ✅ COMPLETED + koreksi klaim audit 7E | ✅ selesai |
| B | `database.sql` — 7 tabel ditambahkan (idempotent, `CREATE TABLE IF NOT EXISTS`) | ✅ selesai |
| C | `Auth.php` + `application/config/recaptcha.php` (baru) — secret reCAPTCHA ke env, fail-closed | ✅ selesai |
| D | `Admin.php` — guard `export_csv()` selaras `admin_id` → `control-panel` | ✅ selesai |

**Siap untuk Phase E:** lint final semua file PHP yang tersentuh, smoke test di environment nyata, hygiene grep secret, commit Bahasa Indonesia di `fase-0-housekeeping`, merge ke `main` setelah konfirmasi user.

---

## Follow-up (di luar scope Step 0, tercatat saja)

1. **Rotate `RECAPTCHA_SECRET`** — secret reCAPTCHA lama masih ada di git history (commit `73f04b8`, ter-push ke `origin/main`); anggap ter-compromise dan ganti di environment (ditemukan `skill:review`).
2. **Nit `reasonix.toml:3`** — prefix site key publik (bukan secret) ter-encode hex; obfuscation saja, bukan proteksi — hapus atau hash.
3. **Nit `database.sql:154`** — komentar `wallet_ledger` "immutable append-only ledger" belum ditegakkan di kode; enforce di kode atau lunakkan komentar.

---

## Files Touched (Step 0)

| File | Aksi |
|---|---|
| `docs/3_ROADMAP.md` | edit (Phase A) |
| `database.sql` | edit — +7 tabel (Phase B) |
| `application/config/recaptcha.php` | **baru** (Phase C) |
| `application/controllers/Auth.php` | edit (Phase C) |
| `application/controllers/Admin.php` | edit — guard `export_csv()` (Phase D) |
| `plan/0…7` | blueprint & summary dokumen |
