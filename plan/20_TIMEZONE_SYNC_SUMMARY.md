# Phase 10B — Timezone Sync Fix (SUMMARY)

**Project:** Synapse (webtable) · **Branch:** `fase-10b-rate-limiting` (kelanjutan Phase 10B)
**Status:** ✅ FIX SELESAI — **belum** di-commit, menunggu konfirmasi user.
**Laporan bug:** pesan lockout menampilkan **435 menit** (selisih 7 jam / 420 menit) alih-alih 15 menit.

---

## 1. Root Cause

`Rate_limit_model::hit()` menulis timestamp memakai **MySQL `NOW()`**, sedangkan `Rate_limit_model::check()` membacanya dengan **PHP `strtotime()`**. Keduanya memakai zona waktu yang berbeda:

- MySQL menulis **wall-clock Asia/Jakarta (UTC+7)** — `locked_until = DATE_ADD(NOW(), INTERVAL 900 SECOND)` menghasilkan string `'2026-08-31 21:49:47'` (WIB).
- PHP `date.timezone` default server = **UTC** — `strtotime('2026-08-31 21:49:47')` menafsirkan string sebagai **UTC** → epoch 7 jam **di masa depan**.
- `remaining_seconds = 26100` (= 7 jam + 15 menit = 435 menit) → `rate_limit_message()` menampilkan `"Silakan coba lagi dalam 435 menit."`

Reproduksi persis (simulasi pure-PHP, tanpa MySQL):

```
[BUG] locked_until dari MySQL (wall-clock WIB): 2026-08-31 21:49:47
[BUG] PHP tz = UTC → remaining = 26100 s
[BUG] message = Terlalu banyak percobaan gagal. Silakan coba lagi dalam 435 menit.
```

---

## 2. Fix

### 2.1 `index.php` — timezone aplikasi eksplisit

`date_default_timezone_set('Asia/Jakarta');` ditambahkan setelah blok ENVIRONMENT, sebelum bootstrap CI3 — PHP `date()`/`strtotime()` kini terkunci UTC+7 apa pun `date.timezone` server.

```php
/*
 *---------------------------------------------------------------
 * TIMEZONE
 *---------------------------------------------------------------
 * ... (WIB, UTC+7) ...
 */
	date_default_timezone_set('Asia/Jakarta');
```

### 2.2 `application/models/Rate_limit_model.php` — timestamp dari PHP, bukan MySQL NOW()

Semua timestamp di-generate PHP (`date('Y-m-d H:i:s')`, TZ Asia/Jakarta) dan dikirim sebagai **bound params** — MySQL `NOW()` dan `INTERVAL ... SECOND` di SQL **dihapus total**:

| Statement (sebelum) | Statement (sesudah) |
|---|---|
| `INSERT ... VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE attempts = IF(last_attempt_at < NOW() - INTERVAL ? SECOND, 1, attempts + 1), last_attempt_at = NOW()` | `INSERT ... VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE attempts = IF(last_attempt_at < ?, 1, attempts + 1), last_attempt_at = ?` — params `[$key, $now, $window_cut, $now]` (`$now = date('Y-m-d H:i:s')`, `$window_cut = date('Y-m-d H:i:s', time() - $lockout_seconds)`) |
| `UPDATE ... SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE ...` | `UPDATE ... SET locked_until = ? WHERE ...` — param `$locked_until = date('Y-m-d H:i:s', time() + $lockout_seconds)` |
| `DELETE ... WHERE last_attempt_at < NOW() - INTERVAL 30 MINUTE` | `DELETE ... WHERE last_attempt_at < ?` — param `$cutoff = date('Y-m-d H:i:s', time() - 1800)` |

**Bonus robustness:** constructor `Rate_limit_model` kini memaksa `Asia/Jakarta` jika belum ter-set — model tetap konsisten walau dipanggil dari CLI/cron (yang tidak melewati `index.php`).

**Konsistensi baca:** `check()` memakai `strtotime($row->locked_until) - time()` — kini string ditulis PHP (WIB) dan dibaca PHP (WIB) → selalu selisih murni 15 menit.

**Self-healing data lama:** baris `rate_limits` yang ditulis sebelum fix (string UTC dari MySQL) akan terbaca PHP sebagai waktu yang sudah lewat → `check()` menganggap lock kedaluwarsa → auto-`clear()` + fresh start. Tidak ada user yang "terkunci macet".

### 2.3 Mengapa tidak `SET time_zone = '+07:00'` di MySQL?

Opsi alternatif dari spesifikasi ("ensure MySQL session time zone is synchronized") sengaja **tidak** dipakai sebagai fix utama: mengubah session TZ MySQL berdampak pada seluruh query lain di koneksi yang sama, dan tetap menyisakan ketergantungan pada konfigurasi server. Pendekatan PHP-timestamps bersifat self-contained — sumber kebenaran tunggal (PHP) untuk tulis **dan** baca.

---

## 3. Verification

### 3.1 Lint (roadmap rule #5)

```bash
$ php -l index.php
No syntax errors detected in index.php
$ php -l application/models/Rate_limit_model.php
No syntax errors detected in application/models/Rate_limit_model.php
```

### 3.2 Simulasi pure-PHP (tanpa MySQL — server tidak tersedia di sandbox)

Logika persis `hit()`/`check()` + `rate_limit_message()`:

```
[BUG] locked_until dari MySQL (wall-clock WIB): 2026-08-31 21:49:47
[BUG] PHP tz = UTC → remaining = 26100 s
[BUG] message = Terlalu banyak percobaan gagal. Silakan coba lagi dalam 435 menit.
--------------------------------------------------------------
[FIX] PHP tz = Asia/Jakarta
[FIX] locked_until (PHP, WIB) : 2026-08-31 21:49:47
[FIX] remaining = 900 s → message = Terlalu banyak percobaan gagal. Silakan coba lagi dalam 15 menit.
--------------------------------------------------------------
PASS: remaining <= 15 menit, pesan menampilkan '15 menit'  (exit=0)
```

### 3.3 Re-test di environment dengan MySQL (wajib, verifikasi akhir)

Ulangi Skenario A dari `plan/19_PHASE_10B_SUMMARY.md` §6; tambahan assertion:

```bash
# setelah 5 gagal, periksa nilai locked_until benar-benar WIB (+7 dari UTC):
mysql -u root -p db_webtable -e \
  "SELECT rate_key, attempts, locked_until, NOW() AS mysql_now,
          UTC_TIMESTAMP() AS mysql_utc FROM rate_limits;"
# → locked_until - NOW() ≈ 900 detik (15 menit), bukan 7 jam 15 menit
# pesan lockout di halaman login: "dalam 15 menit" (atau menurun s.d. 1)
```

---

## 4. Files Touched

| File | Perubahan |
|---|---|
| `index.php` | edit — `date_default_timezone_set('Asia/Jakarta');` (blok TIMEZONE baru) |
| `application/models/Rate_limit_model.php` | edit — timestamp PHP-bound-params (hapus `NOW()`/`INTERVAL`), constructor paksa `Asia/Jakarta`, header comment TZ |
| `plan/20_TIMEZONE_SYNC_SUMMARY.md` | **new** — dokumen ini |

**Tidak diubah:** `database.sql` (tipe kolom `DATETIME` tidak perlu diubah — nilai yang disimpan kini konsisten WIB), helper, controller, view.

---

## 5. Catatan / Out of Scope

- **Audit timezone global aplikasi** (tabel lain yang memakai `NOW()`/`CURRENT_TIMESTAMP`, cron 8A/7B2, `adjust_time`) → terpisah dari bug ini; fix ini fokus pada konsistensi rate limiter + baseline TZ PHP. Bila ingin menyelaraskan seluruh layer MySQL (mis. `default-time-zone = '+07:00'` di `my.cnf`), itu keputusan infra terpisah yang bisa dipertimbangkan user.
- CLI/cron yang memanggil model tanpa `index.php` kini aman berkat constructor guard.

---

*Menunggu konfirmasi user sebelum commit (satu commit bersama Phase 10B): `feat(security): implementasi rate limiting & brute force protection 10B + fix timezone lockout`.*
