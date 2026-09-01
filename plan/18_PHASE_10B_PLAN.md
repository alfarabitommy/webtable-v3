# Phase 10B — Rate Limiting & Brute Force Protection

**Project:** Synapse (webtable) · **Baseline:** `main` (HEAD `974b0b2`) · **Branch kerja:** `fase-10b-rate-limiting`
**Mode:** PLANNING — blueprint menunggu persetujuan user. **Belum ada kode/skema yang diubah.**
**Referensi:** `docs/3_ROADMAP.md` (Phase 10B), `plan/9_PHASE_10A_PLAN.md` (format & gaya, preseden model fitur + pre-flight schema), `docs/1_PRD.md` (business state machines), AGENTS.md (SQL hanya di model, `php -l` tiap file, branch per fase).

---

## Ringkasan Perubahan

| # | Perubahan | File |
|---|-----------|------|
| 1 | **DDL tabel `rate_limits`** — lean, indexed, UNIQUE `rate_key`, kolom GC | `database.sql` (edit, append section Phase 10B) |
| 2 | **Model baru `Rate_limit_model`** — `check()` / `hit()` / `clear()` + GC probabilistik | `application/models/Rate_limit_model.php` (new) |
| 3 | **Helper baru `ratelimit_helper.php`** — pesan Indonesia seragam + response JSON HTTP 429 | `application/helpers/ratelimit_helper.php` (new) |
| 4 | **Instrumentasi `Auth::login()`** — key `login:{phone}:{ip}`; `hit()` pada kredensial gagal, `clear()` pada sukses | `application/controllers/Auth.php` (edit) |
| 5 | **Instrumentasi `Auth::register()`** — key `register:{ip}`; burst limiter (semua submission dihitung) | `application/controllers/Auth.php` (edit) |
| 6 | **Instrumentasi `Admin_auth::login()`** — key `admin_login:{username}:{ip}`; `hit()`/`clear()` | `application/controllers/Admin_auth.php` (edit) |
| 7 | **Instrumentasi `Rentals::claim()`** — key `claim_roi:{user_id}`; anti-spam klaim | `application/controllers/Rentals.php` (edit) |
| 8 | **Instrumentasi `Wallet::process_withdraw()`** — key `withdraw:{user_id}`; rate limit pengajuan WD | `application/controllers/Wallet.php` (edit) |
| 9 | **Blueprint ini** | `plan/18_PHASE_10B_PLAN.md` (new) |

**Tanpa perubahan:** `routes.php` (keempat endpoint sudah ter-route: `login`, `register`, `control-panel`, `rentals/claim/(:num)`; `wallet/process_withdraw` ter-route otomatis via segment), `autoload.php` (model & helper di-`load` eksplisit per controller, mengikuti konvensi).

---

## 1. Storage Schema — tabel `rate_limits`

### 1.1 DDL (append ke `database.sql`, section "Phase 10B baseline")

```sql
-- -----------------------------------------------------
-- Table `rate_limits` — Phase 10B (rate limiting & brute force)
-- Satu baris per composite key (endpoint + identitas). Baris pendek
-- umurnya (GC ≤ 30 menit); tidak perlu FK (bukan data bisnis).
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rate_key` VARCHAR(191) NOT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_attempt_at` DATETIME NOT NULL,
  `locked_until` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rate_key` (`rate_key`),
  INDEX `idx_last_attempt_at` (`last_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.2 Keputusan desain

| Keputusan | Alasan |
|---|---|
| `rate_key VARCHAR(191)` plaintext, **bukan hash** | (a) Spesifikasi persis; (b) 191 = batas index UNIQUE InnoDB utf8mb4 (767B/4); (c) key komposit terpanjang `admin_login:{username ≤ 50}:{ip ≤ 45}` ≈ 110 char — aman; (d) **debuggable** — admin bisa `SELECT * FROM rate_limits WHERE rate_key LIKE 'login:081234567890:%'` saat investigasi serangan; (e) konsisten dengan preseden repo (`system_audit_logs.ip_address` juga plaintext). Opsi hash sha256 dicatat sebagai varian hardening 10D (deferred). |
| Tanpa kolom `scope` terpisah | Prefix di `rate_key` (`login:`, `register:`, `claim_roi:`, …) sudah memisahkan scope — tabel tetap lean per spesifikasi. |
| `INDEX idx_last_attempt_at` | GC `DELETE ... WHERE last_attempt_at < NOW() - INTERVAL 30 MINUTE` memakai index ini (scan kecil). Satu-satunya index tambahan di luar UNIQUE. |
| Tanpa FK | Baris bersifat sementara (≤ 30 menit). `users` bisa dihapus tanpa isu referensial; FK justru menambah biaya tulis pada jalur hot. |
| `DATETIME` (bukan `TIMESTAMP`) | Kompatibel dengan gaya kolom `expires_at`/`processed_at` yang sudah ada; tidak ada kebutuhan timezone auto-convert. |

### 1.3 Garbage collection (GC)

- **Retensi:** baris yang `last_attempt_at < NOW() - INTERVAL 30 MENIT` (2× window default 15 menit) tidak mungkin sedang terkunci (lock 15 menit selalu ≤ 30 menit) dan counter-nya sudah basi → aman dihapus.
- **Trigger in-app:** `_maybe_gc()` dipanggil di awal `check()` dan `hit()` dengan probabilitas `mt_rand(1, 100) === 1` (~1% per request) — biaya hampir nol, tidak perlu request berdedikasi.
- **Cron opsional** (gaya cron roadmap 8A): per jam
  `DELETE FROM rate_limits WHERE last_attempt_at < NOW() - INTERVAL 30 MINUTE;`
- GC **tidak pernah** menghapus baris yang sedang terkunci: `last_attempt_at` selalu diperbarui oleh `hit()`, jadi baris terkunci tidak pernah idle ≥ 30 menit.

---

## 2. Engine Architecture — `Rate_limit_model`

### 2.1 API (signature mengikuti spesifikasi)

```php
/**
 * @param string $key  Composite key, lihat §2.2
 * @param int    $max_attempts   Ambang percobaan sebelum lock (default 5)
 * @param int    $lockout_seconds Durasi lock (default 900 = 15 menit)
 * @return array{allowed: bool, remaining_seconds: int, attempts: int}
 */
public function check($key, $max_attempts = 5, $lockout_seconds = 900);

/** Increment counter + set locked_until saat ambang tercapai.
 *  Trailing param $max_attempts = null → default 5 (extension kecil dari
 *  signature spesifikasi agar ambang tetap konfigurabel per call site). */
public function hit($key, $lockout_seconds = 900, $max_attempts = null);

/** Reset key setelah autentikasi/aksi sukses. Implementasi: DELETE baris
 *  (setara "reset ke 0", tapi membuat tabel tetap lean + tak ada baris basi). */
public function clear($key);
```

Semantik:

- `check()` **read-only** (plus auto-reset lock kedaluwarsa, lihat bawah): tidak mengubah `attempts`.
- `allowed = false` **hanya** saat `locked_until > NOW()` (sedang dalam masa lockout). Counter yang belum mencapai ambang tidak memblokir — sesuai spesifikasi: 5 gagal → ke-6 diblokir.
- `remaining_seconds` = `locked_until − NOW()` (dibulatkan ke atas di lapisan pesan); `0` saat allowed.
- Lock yang sudah kedaluwarsa: `check()` mereset baris (`clear()`) lalu mengembalikan `allowed = true, attempts = 0` — memberi pengguna "5 percobaan segar" setelah timer habis (skenario test: *timer expiry → successful retry*).

### 2.2 Komposisi key

| Endpoint | Format key | Contoh |
|---|---|---|
| User login (`Auth::login`) | `login:{phone_normalized}:{ip}` | `login:081234567890:127.0.0.1` |
| Admin login (`Admin_auth::login`) | `admin_login:{username_trim}:{ip}` | `admin_login:root:127.0.0.1` |
| Registrasi (`Auth::register`) | `register:{ip}` | `register:127.0.0.1` |
| Klaim ROI (`Rentals::claim`) | `claim_roi:{user_id}` | `claim_roi:42` |
| Pengajuan WD (`Wallet::process_withdraw`) | `withdraw:{user_id}` | `withdraw:42` |

- IP via `$this->input->ip_address()` (CI3; peningkatan `X-Forwarded-For`/`proxy_ips` ditunda ke 10D — sama seperti catatan 10A).
- Prefix scope menjamin tidak ada tabrakan antar endpoint.
- Financial actions memakai `user_id` (bukan IP): user sah di belakang NAT berbagi IP, sedangkan serangan ROI-spam/withdraw-spam adalah per-akun.

### 2.3 Algoritma inti — atomic upsert (race-safe)

Semua tulis lewat **satu statement atomik InnoDB** — tanpa read-modify-write, aman terhadap konkurensi (dua request gagal paralel tidak saling menimpa counter):

```sql
INSERT INTO rate_limits (rate_key, attempts, last_attempt_at)
VALUES (?, 1, NOW())
ON DUPLICATE KEY UPDATE
  attempts = IF(last_attempt_at < NOW() - INTERVAL ? SECOND, 1, attempts + 1),
  last_attempt_at = NOW()
```

- **Window decay (semantik "5 gagal dalam 15 menit"):** jika percobaan terakhir ≥ window (default 900 s) yang lalu, counter di-reset ke 1 — jendela berlabuh pada `last_attempt_at` (fixed-window tersederhana yang memenuhi skema lean; sliding-window presisi via tabel log per-attempt dicatat sebagai opsi masa depan, §6).
- Placeholder `INTERVAL ? SECOND` didukung statement prepared MySQL/mysqli. (Fallback jika driver menolak: konversi `(int)` + interpolasi literal — tidak pernah parameter raw.)

Setelah increment, set lock **sekali saja** saat ambang tercapai (idempotent — tidak memperpanjang lock pada percobaan berikutnya):

```sql
UPDATE rate_limits
SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
WHERE rate_key = ? AND attempts >= ? AND locked_until IS NULL;
```

### 2.4 Pseudocode model

```php
public function check($key, $max_attempts = 5, $lockout_seconds = 900) {
    $this->_maybe_gc();
    $row = $this->db->get_where('rate_limits', ['rate_key' => $key])->row();
    if (!$row) return ['allowed' => true, 'remaining_seconds' => 0, 'attempts' => 0];

    if ($row->locked_until && strtotime($row->locked_until) > time()) {
        return ['allowed' => false,
                'remaining_seconds' => strtotime($row->locked_until) - time(),
                'attempts' => (int) $row->attempts];
    }
    if ($row->locked_until) {                 // lock kedaluwarsa → reset, fresh start
        $this->clear($key);
        return ['allowed' => true, 'remaining_seconds' => 0, 'attempts' => 0];
    }
    return ['allowed' => true, 'remaining_seconds' => 0, 'attempts' => (int) $row->attempts];
}

public function hit($key, $lockout_seconds = 900, $max_attempts = null) {
    $max_attempts = $max_attempts ?: 5;
    $this->_maybe_gc();
    // upsert atomik §2.3 (bound params)
    // set lock §2.3 (bound params)
    return $this->check($key, $max_attempts, $lockout_seconds);
}

public function clear($key) {
    $this->db->where('rate_key', $key)->delete('rate_limits');
}

private function _maybe_gc() {
    if (mt_rand(1, 100) === 1) {
        $this->db->query("DELETE FROM rate_limits WHERE last_attempt_at < NOW() - INTERVAL 30 MINUTE");
    }
}
```

Konvensi AGENTS.md: seluruh SQL di model, `$this->db->query("... ?", [$v])` dengan bound params.

### 2.5 Alternatif yang dipertimbangkan (dan ditolak)

| Alternatif | Alasan tolak |
|---|---|
| Redis/Memcached | Tidak ada di stack; menambah dependency runtime (larangan Composer runtime deps). |
| Session-based throttling | Attacker bisa reset session — tidak mengunci apa pun. |
| CI Hooks global | Terlalu kasar; logika per-key (composite, window, clear-on-success) butuh kontrol per endpoint. |
| Tabel log per-attempt (sliding window presisi) | Over-engineering untuk threshold 5/15 menit; skema lean spesifikasi cukup. |

---

## 3. Controller Instrumentation

Pola umum di tiap endpoint (helper `ratelimit` di-load di constructor/`__construct` atau on-demand):

```php
$this->load->helper('ratelimit');
$this->load->model('Rate_limit_model');

$throttle = $this->Rate_limit_model->check($key, 5, 900);
if (!$throttle['allowed']) {
    if ($this->input->is_ajax_request()) {
        rate_limit_json_response($throttle);   // HTTP 429 + JSON, exit
    }
    // Web standard: pesan + render/redirect (§3.6)
}
```

### 3.1 `Auth::login()` — key `login:{phone}:{ip}`

Insertion point: **awal blok `if ($this->input->post())`** — SEBELUM `_verify_recaptcha()` (pengguna terkunci tidak membebani API reCAPTCHA Google).

1. Normalisasi phone di awal blok (pindahkan pemanggilan `_normalize_phone()` dari dalam blok `form_validation->run()` ke atas, agar key bisa dibentuk; nilai `$_POST['phone']` tetap di-set seperti sekarang untuk flow selanjutnya).
2. `$key = 'login:' . $phone . ':' . $this->input->ip_address();`
3. `check($key, 5, 900)` → jika blocked: `$data['errors'][] = rate_limit_message($throttle['remaining_seconds']);` lalu render `auth/login` + `return;` (pola inline error yang sudah ada di view).
4. **Sukses kredensial** (`if ($user && password_verify(...))`): `clear($key)` — letakkan di baris pertama cabang sukses (sebelum cek ban), sehingga kredensial benar = counter dibersihkan.
5. **Gagal kredensial** (cabang `else`): `hit($key, 900, 5)`.

`check()` dilakukan sebelum form_validation → tidak ada query DB credential untuk user yang sedang diblokir (fail-fast).

### 3.2 `Auth::register()` — key `register:{ip}` — burst limiter

Insertion point: **awal blok `if ($this->input->post())`**, setelah guard circuit breaker (Phase 9A) di method.

1. `$key = 'register:' . $this->input->ip_address();`
2. `check($key, 5, 900)` → jika blocked: `$data['errors'][] = rate_limit_message(...)` + render `auth/register` + `return;`.
3. `hit($key, 900, 5)` — **setiap submission dihitung** (sukses maupun gagal): inilah inti pencegahan *automated account creation bursts* (5 akun percobaan/15 menit per IP, apa pun hasil validasinya). **Tanpa `clear()`** — clearing hanya untuk key login per spesifikasi.
4. Alur validasi/reCAPTCHA/create user berjalan seperti biasa setelahnya.

*Catatan desain (deviasi sadar dari pola "hit hanya saat gagal"):* brute-force limiter klasik tidak cukup untuk registrasi — bot yang berhasil membuat akun tidak pernah kena hit. Untuk endpoint ini threshold mengukur *volume submission*, bukan *kegagalan*.

### 3.3 `Admin_auth::login()` — key `admin_login:{username}:{ip}`

Insertion point: **awal blok `if ($this->input->post())`**.

1. `$username = trim($this->input->post('username', TRUE));` (sudah ada; pastikan di-trim sebelum masuk key).
2. `$key = 'admin_login:' . $username . ':' . $this->input->ip_address();`
3. `check($key, 5, 900)` → jika blocked: `set_flashdata('error', 'SYSTEM HALTED: ' . rate_limit_message(...));` + `redirect('control-panel');` (pola flash+redirect yang sudah dipakai Admin_auth).
4. **Sukses** (`password_verify` true): `clear($key)` sebelum `set_userdata`/redirect.
5. **Gagal** (`else`): `hit($key, 900, 5)`.

### 3.4 `Rentals::claim()` — key `claim_roi:{user_id}`

Insertion point: **setelah `$user_id = $this->session->userdata('user_id');`** (baris 116), sebelum fetch rental / business logic.

1. `$key = 'claim_roi:' . $user_id;`
2. `check($key, 5, 900)` → jika blocked: `set_flashdata('error', rate_limit_message(...));` + `redirect('rentals');`.
3. `hit($key, 900, 5)` — setiap percobaan klaim dihitung (anti-spam: business guard T+1 sudah membatasi ROI harian, layer ini membatasi frekuensi request).
4. **Bonus hardening (direkomendasikan, satu baris):** tambahkan `if (!$this->input->post()) { redirect('rentals'); }` — saat ini `claim()` mutasi DB dan bisa dieksekusi via GET; tutup celah ini bersama instrumentasi (kontribusi ke 10D).

### 3.5 `Wallet::process_withdraw()` — key `withdraw:{user_id}`

Insertion point: **setelah `$user_id = $this->session->userdata('user_id');`** (baris 105), sebelum Gatekeeper 1.

1. `$key = 'withdraw:' . $user_id;`
2. `check($key, 5, 900)` → jika blocked: `set_flashdata('error', rate_limit_message(...));` + `redirect('wallet/withdraw');`.
3. `hit($key, 900, 5)` — setiap submission dihitung. Gatekeeper existing (single-pending-WD, daily limit) tetap berjalan; layer ini menambah pagu frekuensi pengajuan.

### 3.6 Graceful UX

**Helper baru `application/helpers/ratelimit_helper.php`** (copy pesan terpusat — satu bahasa, satu format):

```php
function rate_limit_message($remaining_seconds) {
    $minutes = max(1, (int) ceil($remaining_seconds / 60));
    return 'Terlalu banyak percobaan gagal. Silakan coba lagi dalam ' . $minutes . ' menit.';
}

function rate_limit_json_response($throttle) {
    set_status_header(429);            // global CI3, tanpa objek $this
    header('Content-Type: application/json');
    echo json_encode([
        'success'     => false,
        'error'       => 'too_many_attempts',
        'message'     => rate_limit_message($throttle['remaining_seconds']),
        'retry_after' => (int) $throttle['remaining_seconds'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
```

- **Standard Web:** inline `$data['errors'][]` (login/register — view sudah render loop `$errors`) atau `set_flashdata('error', ...)` + redirect (admin, claim, withdraw). Contoh: `"Terlalu banyak percobaan gagal. Silakan coba lagi dalam 12 menit."`
- **AJAX/JSON:** deteksi `$this->input->is_ajax_request()` → `rate_limit_json_response()` → **HTTP 429** + `{ success:false, error:"too_many_attempts", message, retry_after }`. Keempat endpoint saat ini non-AJAX; cabang JSON didefinisikan sekarang agar kontrak siap (dan bisa diuji via header `X-Requested-With`).

---

## 4. Verification & Testing Protocol

### 4.1 Lint (Roadmap Rule #5)

```bash
php -l application/models/Rate_limit_model.php
php -l application/helpers/ratelimit_helper.php
php -l application/controllers/Auth.php
php -l application/controllers/Admin_auth.php
php -l application/controllers/Rentals.php
php -l application/controllers/Wallet.php
```
Wajib: `No syntax errors detected in ...` untuk keenam file.

### 4.2 Pre-flight schema (CI3 tanpa migration — pola sama dengan 10A)

```bash
mysql -u <user> -p db_webtable -e "SHOW TABLES LIKE 'rate_limits';"
```
→ jika tidak ada, apply DDL §1.1 secara manual.

### 4.3 Setup test

- Server: `php -S localhost:8080` dari project root (pakai `http://localhost:8080/index.php/...` bila pretty-URL tidak aktif di dev server).
- Seed data: 1 user test (via `Test_core` atau insert langsung `users` dengan phone `081234567890` / password known) + 1 admin (`seeder_admin` di `Auth` atau seed `admins`).
- Pembersihan key antar skenario: `DELETE FROM rate_limits;` (atau `clear()`).

### 4.4 Skenario A — User login brute force (end-to-end)

```bash
# 1) 5× kredensial salah (phone sama, IP sama)
for i in 1 2 3 4 5; do
  curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/index.php/login \
       -d "phone=081234567890&password=wrong${i}"
done
# → 200 (render ulang view error) × 5

# 2) Verifikasi state DB
mysql -u <user> -p db_webtable -e \
  "SELECT rate_key, attempts, last_attempt_at, locked_until FROM rate_limits WHERE rate_key LIKE 'login:081234567890:%';"
# → attempts = 5, locked_until NOT NULL (NOW() + 15 menit)

# 3) Percobaan ke-6 dengan password BENAR → tetap diblokir
curl -s -X POST http://localhost:8080/index.php/login \
     -d "phone=081234567890&password=<password_benar>" | grep -c "Terlalu banyak percobaan"
# → 1 (pesan lockout tampil; kredensial tidak pernah dievaluasi)

# 4) Simulasi expiry timer, lalu retry sukses
mysql -u <user> -p db_webtable -e \
  "UPDATE rate_limits SET locked_until = NOW() - INTERVAL 1 MINUTE WHERE rate_key LIKE 'login:081234567890:%';"
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/index.php/login \
     -d "phone=081234567890&password=<password_benar>" -L
# → 302 (redirect home, login sukses)
mysql -u <user> -p db_webtable -e "SELECT COUNT(*) FROM rate_limits;"
# → key login terhapus oleh clear() (tidak ada baris sisa)
```

### 4.5 Skenario B — Admin login

Ulangi pola A pada `/index.php/control-panel` (`username`/`password` salah ×5 → ke-6 dengan password benar tetap 302 + flash `SYSTEM HALTED: ... menit`; expiry → login sukses). Verifikasi key `admin_login:...` di DB.

### 4.6 Skenario C — Register burst

```bash
for i in 1 2 3 4 5; do
  curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/index.php/register \
       -d "phone=08123456789${i}&password=password123&invite_code=XXXXXX"
done
# → 200 × 5 (validasi/gagal apapun tetap dihitung)
curl -s -X POST http://localhost:8080/index.php/register \
     -d "phone=081234567890&password=password123&invite_code=XXXXXX" | grep -c "Terlalu banyak percobaan"
# → 1 (percobaan ke-6 diblokir; DB: attempts = 5, key register:{ip})
```

### 4.7 Skenario D — AJAX/JSON 429

```bash
# Saat key login sedang terkunci:
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/index.php/login \
     -H "X-Requested-With: XMLHttpRequest" -d "phone=081234567890&password=x"
# → 429
curl -s -X POST http://localhost:8080/index.php/login \
     -H "X-Requested-With: XMLHttpRequest" -d "phone=081234567890&password=x"
# → {"success":false,"error":"too_many_attempts","message":"...","retry_after":<detik>}
```

### 4.8 Skenario E — GC deterministik

Bootstrap CI3 one-off di `/tmp` (di luar repo, dibersihkan setelah test — pola CI3 CLI):

```php
<?php // /tmp/rl_gc_test.php — jalankan: php /tmp/rl_gc_test.php
define('ENVIRONMENT', 'development');
define('BASEPATH', '/home/tommy/dev/webtable/system/');
define('APPPATH',  '/home/tommy/dev/webtable/application/');
define('VIEWPATH', APPPATH . 'views/');
require BASEPATH . 'core/CodeIgniter.php';   // menyalakan framework + DB
// setelah CI boot: get_instance()->Rate_limit_model->gc() (public)
```

1. `INSERT INTO rate_limits (rate_key, attempts, last_attempt_at) VALUES ('login:stale:1.1.1.1', 3, NOW() - INTERVAL 1 HOUR);`
2. Jalankan bootstrap → `Rate_limit_model` diinisialisasi, panggil `gc()`.
3. `SELECT COUNT(*) ... WHERE rate_key = 'login:stale:1.1.1.1';` → 0.

*(`_maybe_gc()` dibuat public `gc()` agar deterministik diuji; pemanggilan probabilistik tetap private.)*

### 4.9 Hygiene review

```bash
grep -rn "Rate_limit_model->\(check\|hit\|clear\)" application/controllers/   # 5 call site + konteks benar
git diff --stat                                                               # hanya file di Ringkasan
git status --short                                                            # tidak ada artefak test di repo
```

---

## 5. Files Touched (Phase 10B)

| File | Action |
|------|--------|
| `plan/18_PHASE_10B_PLAN.md` | **new** — blueprint ini |
| `database.sql` | edit — DDL `rate_limits` (§1.1) |
| `application/models/Rate_limit_model.php` | **new** — `check` / `hit` / `clear` / `gc` (§2) |
| `application/helpers/ratelimit_helper.php` | **new** — `rate_limit_message` / `rate_limit_json_response` (§3.6) |
| `application/controllers/Auth.php` | edit — `login()` + `register()` (§3.1–3.2) |
| `application/controllers/Admin_auth.php` | edit — `login()` (§3.3) |
| `application/controllers/Rentals.php` | edit — `claim()` + POST-guard (§3.4) |
| `application/controllers/Wallet.php` | edit — `process_withdraw()` (§3.5) |

---

## 6. Out of Scope / Ditunda

- **`proxy_ips` / `X-Forwarded-For`** → Phase 10D (catatan sama dengan 10A; IP akurat di belakang reverse-proxy butuh config review menyeluruh).
- **Sliding-window presisi** (per-attempt log) → hanya jika threshold kustom kompleks dibutuhkan; semantik fixed-window berlabuh `last_attempt_at` memenuhi spesifikasi.
- **Hash `rate_key`** (PII at-rest) → varian hardening 10D; plaintext dipilih untuk debuggability + preseden repo.
- **OTP rate limiting** — roadmap menyebut OTP, tapi belum ada endpoint OTP di kode; saat dibangun, cukup `check('otp:{phone}:{ip}', ...)` — model sudah siap pakai.
- **CSRF rotation & session timeout** → Phase 10C.

---

*Blueprint menunggu persetujuan user (Tommy). Setelah approval: buat branch `fase-10b-rate-limiting`, eksekusi §1–§3, verifikasi §4, commit berbahasa Indonesia.*
