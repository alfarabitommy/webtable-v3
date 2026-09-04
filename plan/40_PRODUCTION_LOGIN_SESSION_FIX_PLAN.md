# Plan 40 — Produksi: Login Gagal & Session Drop (cookie_secure vs HTTP)

**Status:** DIAGNOSIS (plan mode — belum ada perubahan kode aplikasi)
**Tanggal:** 2026-09-02
**Lingkup:** `index.php`, `application/config/config.php`, `application/controllers/Auth.php`, `application/core/MY_Output.php`, deployment env.

---

## 1. Gejala

Setelah `ENVIRONMENT` dipaksa `'production'` di `index.php` (commit kerja, baris 83:
`define('ENVIRONMENT', 'production')` — *"Paksa ke mode production untuk pengujian"*):

1. User yang sedang login tiba-tiba dilempar ke `/login` (session drop).
2. Login ulang dengan kredensial **valid** tidak pernah berhasil — halaman login
   terus tampil ulang (loop), tanpa pesan error kredensial.

Bukti akses log FlyEnv Apache (`vhost/logs/1788067981989-access_log`), setelah flip
pukul 02:11:14:

```
02:11:27 POST /wallet/topup     -> 303 -> GET /login 200        (session hilang di tengah browsing)
02:11:55 POST /login            -> 303 -> GET /home 307 -> GET /login 200   (LOOP)
02:12:10 POST /login            -> 303 -> GET /home 307 -> GET /login 200   (LOOP)
02:13:11 POST /login            -> 303 -> GET /home 307 -> GET /login 200   (LOOP)
```

`POST /login` balas **303** (kredensial VALID, reCAPTCHA lolos, session di-set),
lalu `GET /home` balas **307** ke `/login` (session tidak pernah ter-restore).
Sebelum flip (01:34–02:05) semua `GET /home`/`/marketplace`/... balas **200** — normal.

---

## 2. ROOT CAUSE UTAMA: `cookie_secure=TRUE` di atas koneksi HTTP polos

### 2.1 Konfigurasi yang salah

`application/config/config.php:414`:

```php
$config['cookie_secure'] = (ENVIRONMENT === 'production') ? TRUE : FALSE;
```

`cookie_secure` diikat ke **konstanta environment**, bukan ke **transport aktual**.
Host berjalan di `http://synapse.test` (tanpa TLS, tanpa proxy) tetapi environment
dipaksa `production` → semua cookie ditandai `Secure`.

### 2.2 Mekanisme kegagalan (CI3)

- **Session cookie** (`ci_session`): `system/libraries/Session/Session.php:160-181`
  selalu menambahkan atribut `Secure` saat `cookie_secure=TRUE`, **tanpa mengecek
  koneksi**. Browser modern (Chrome ≥52, Firefox, Safari) **menolak menyimpan cookie
  `Secure` dari origin http://** → pada request berikutnya `ci_session` tidak
  terkirim → PHP membuka session kosong baru.
- **CSRF cookie** (`synapse_csrf_cookie`): `system/core/Security.php:268-306`
  `csrf_set_cookie()` bahkan lebih ketat — jika `cookie_secure=TRUE` dan
  `is_https()=FALSE`, cookie CSRF **tidak dikirim sama sekali** (`return FALSE`).
- **Regenerasi session** (`sess_time_to_update=300`, `sess_regenerate_destroy=TRUE`):
  saat session lama di-regenerate, ID baru dikirim via cookie `Secure` (ditolak
  browser) sementara session lama **dihancurkan** di server → cookie lama kini
  menunjuk file session yang sudah hilang → request berikutnya = session kosong.
  Ini menjelaskan kenapa user yang *sudah* login ikut drop dalam ≤5 menit.

### 2.3 Bukti forensik tambahan

- Session file orphan di `/tmp/ci_session*` (16 file, timestamp 02:11–02:13 — satu
  file kosong 34-byte per request yang gagal; file 178-byte berisi
  `user_id|s:1:"8";phone|s:12:"085798112233"...` = session login yang sukses di-set
  server-side namun cookie-nya tidak pernah bisa dikirim balik oleh browser).
- `application/logs/log-2026-09-02.php` berhenti ditulis pukul 02:05:36 (mtime)
  padahal request tetap berjalan 02:11–02:13 — konsisten: `log_threshold=1` di
  production, dan kegagalan ini **senyap** (redirect biasa, tanpa error).

---

## 3. FAKTOR PENDUKUNG / PENGHAMBAT LOGIN LAINNYA

### 3.1 `index.php` dipaksa `production` (pemicu)

`index.php:82-83` — override uji coba:

```php
// Paksa ke mode production untuk pengujian
define('ENVIRONMENT', 'production');
```

Menimpa `CI_ENV` apa pun (termasuk `SetEnv CI_ENV "development"` di `.htaccess`)
dan membuat seluruh app production di host dev http. Blok Plan 34 yang benar sudah
ada sebagai komentar (baris 67-73) — tinggal dipulihkan.

### 3.2 reCAPTCHA fail-closed memblokir login

`Auth::_verify_recaptcha()` (Auth.php:27-92) sengaja fail-closed. Dua kegagalan
tercatat di log (saat itu masih mode dev, tapi tetap berlaku di production):

- `01:16:24` & `01:16:44` — `reCAPTCHA curl gagal: error adding trust anchors from
  file: /usr/local/etc/openssl@1.1/cert.pem (errno 77)` — `_resolve_ca_bundle()`
  (Auth.php:99-117) memilih file CA yang **ada tapi rusak/kosong** (path Homebrew
  yang dikompilasi ke build PHP FlyEnv) sebelum mencoba `/etc/ssl/certs/
  ca-certificates.crt` yang valid; curl errno 77 = CURLE_SSL_CACERT_BADFILE →
  verifikasi ditolak → login tidak pernah sampai ke pengecekan kredensial.
- `01:27:24` — `reCAPTCHA secret belum dikonfigurasi (env RECAPTCHA_SECRET)` —
  env `RECAPTCHA_SECRET` tidak ada → semua login/register ditolak.
  (Catatan: pada 02:11–02:13 POST /login balas 303, artinya saat itu secret sudah
  ter-set di env web server FlyEnv php-fpm.)

### 3.3 Catatan hardening (bukan penyebab)

- `sess_save_path = sys_get_temp_dir()` (`/tmp`) — rawan tercampur session aplikasi
  lain di server bersama; idealnya direktori khusus yang writable.
- `.htaccess` berisi `SetEnv CI_ENV "development"` aktif — benar untuk dev lokal,
  tapi komentar di file menyatakan sebaiknya di vhost lokal, bukan file ter-deploy.

---

## 4. RENCANA PERBAIKAN (arsitektur)

### A. (PRIMER) `cookie_secure` mengikuti transport, bukan ENVIRONMENT

Di `application/config/config.php`, ganti baris 414 dengan deteksi skema runtime
yang **trusted-proxy aware** (jangan percaya `X-Forwarded-Proto` dari sumber tak
dikenal) + override eksplisit untuk deployment khusus:

```php
/*
| Plan 40: cookie Secure mengikuti TRANSPORT, bukan ENVIRONMENT.
| - HTTP polos (dev lokal)            -> cookie_secure = FALSE
| - HTTPS asli (Apache mod_ssl)       -> $_SERVER['HTTPS'] = on  -> TRUE
| - TLS di-terminate proxy (nginx/LB) -> X-Forwarded-Proto https
|   HANYA dipercaya bila REMOTE_ADDR ada di whitelist TRUSTED_PROXIES
|   (env, sama dengan $config['proxy_ips'] di bawah).
| - Override eksplisit deployment:    -> COOKIE_SECURE=true|false
*/
$_cookie_secure = NULL;
$_override = strtolower((string) getenv('COOKIE_SECURE'));
if ($_override === 'true') {
    $_cookie_secure = TRUE;
} elseif ($_override === 'false') {
    $_cookie_secure = FALSE;
} else {
    $_is_https  = (! empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
    $_xff_proto = isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        ? strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) : '';
    $_trusted   = array(); // isi dari getenv('TRUSTED_PROXIES') — lihat blok proxy_ips di bawah
    $_from_proxy = ! empty($_SERVER['REMOTE_ADDR'])
        && in_array($_SERVER['REMOTE_ADDR'], $_trusted, TRUE);
    $_cookie_secure = $_is_https || ($_from_proxy && $_xff_proto === 'https');
}
$config['cookie_secure'] = $_cookie_secure;
unset($_cookie_secure, $_override, $_is_https, $_xff_proto, $_trusted, $_from_proxy);
```

Catatan implementasi:
- `is_https()` (system/core/Common.php:339-366) **sudah tersedia** saat config.php
  di-parse (Common.php di-load sebelum Config — CodeIgniter.php:81 vs 220), tapi ia
  tidak melakukan gating `proxy_ips` → deteksi manual di atas lebih aman.
- Karena `$config['proxy_ips']` baru diisi di akhir file (baris 532-536), blok
  `cookie_secure` sebaiknya **dipindah ke bawah blok proxy_ips** (atau membaca
  `getenv('TRUSTED_PROXIES')` langsung) agar whitelist-nya konsisten.
- Perbaikan ini otomatis menyembuhkan **dua** cookie sekaligus: session `ci_session`
  (Session.php) dan CSRF `synapse_csrf_cookie` (Security.php) — keduanya membaca
  `cookie_secure`.
- `MY_Output::emit_security_headers()` sudah benar (HSTS hanya di production + HTTPS)
  — tidak perlu diubah.

### B. Pulihkan ENVIRONMENT berbasis env di `index.php`

Hapus baris 82-83 (`Paksa ke mode production untuk pengujian`) dan aktifkan kembali
blok Plan 34 (baris 67-73): baca `$_SERVER['CI_ENV']` / `getenv('CI_ENV')`, fallback
fail-closed `'production'`. Dev lokal kembali via `CI_ENV=development` (Apache
`SetEnv` di vhost lokal / `php -S`).

### C. reCAPTCHA: env wajib + resolver CA bundle di-hardening

1. **Deployment:** set `RECAPTCHA_SECRET` dan `RECAPTCHA_SITE_KEY` di env web server
   (php-fpm pool `env[]` / Apache `SetEnv` / nginx `fastcgi_param`) — tanpanya login
   sengaja ditolak (fail-closed).
2. **`_resolve_ca_bundle()` (Auth.php:99-117):**
   - validasi kandidat: `is_file() && is_readable() && filesize() > 0` — file CA
     kosong/rusak (errno 77) dilewati;
   - prioritas: sistem bundle `/etc/ssl/certs/ca-certificates.crt` di atas nilai
     `ini_get('openssl.cafile')` bila nilai ini tidak valid;
   - fallback: jika curl tetap errno 77 untuk kandidat terpilih, coba kandidat
     berikutnya (ubah `_verify_recaptcha()` untuk iterasi daftar kandidat yang
     sudah tervalidasi), bukan langsung gagal.

### D. Panduan HTTPS untuk deployment produksi sungguhan

- Produksi asli WAJIB TLS; di sana `cookie_secure` otomatis `TRUE` (aman).
- Apache + mod_ssl: `$_SERVER['HTTPS']='on'` otomatis → tanpa perubahan kode.
- nginx/LB TLS-termination: kirim `fastcgi_param HTTPS on;` (atau
  `X-Forwarded-Proto: https` + `REMOTE_ADDR` di whitelist `TRUSTED_PROXIES`).
- Staging/pengujian di HTTP polos: biarkan deteksi otomatis (FALSE) atau set
  `COOKIE_SECURE=false` eksplisit; jangan pernah memakai modus "production" di host
  HTTP tanpa salah satu di atas.

### E. Verifikasi (setelah implementasi)

1. `php -l index.php application/config/config.php application/controllers/Auth.php`
2. Dev (CI_ENV=development, http): alur curl dengan cookie jar —
   `GET /login` → pastikan `Set-Cookie` **tanpa** `Secure`; ambil CSRF token;
   `POST /login` (kredensial valid) → harap **303**; `GET /home` → harap **200**
   (bukan 307); `GET /profile` → 200.
3. Simulasi HTTPS: request dengan `HTTPS=on` (atau `X-Forwarded-Proto: https` dari
   IP trusted) → pastikan `Set-Cookie` kini **memiliki** `Secure`.
4. Akses log Apache: tidak ada lagi pola `POST /login 303 → GET /home 307 → GET /login`.
5. `/tmp/ci_session*` tidak bertambah per-request (bersihkan yang orphan dulu).

---

## 5. File yang akan diubah (menunggu persetujuan)

| File | Perubahan |
|---|---|
| `application/config/config.php` | Ganti baris 414 dgn deteksi transport + `COOKIE_SECURE` override; pindahkan ke bawah blok `proxy_ips` |
| `index.php` | Hapus override `'production'` (baris 82-83); aktifkan blok Plan 34 |
| `application/controllers/Auth.php` | Hardening `_resolve_ca_bundle()` + iterasi kandidat CA di `_verify_recaptcha()` |
| (opsional) `.htaccess` | Pindahkan `SetEnv CI_ENV "development"` ke vhost lokal saja |
| (opsional) `application/config/config.php` | `sess_save_path` ke direktori khusus (mis. `application/sessions`) |

---

## 6. Kriteria penerimaan

- [ ] Login di `http://synapse.test` (dev, `CI_ENV=development`) sukses end-to-end:
      session persist, tidak ada loop redirect.
- [ ] Di HTTPS sungguhan (produksi) cookie tetap `Secure` — tidak ada penurunan
      keamanan session/CSRF.
- [ ] `X-Forwarded-Proto` hanya dipercaya dari IP dalam `TRUSTED_PROXIES`.
- [ ] Log tidak lagi memuat `reCAPTCHA curl gagal ... errno 77` /
      `reCAPTCHA secret belum dikonfigurasi` pada login yang sah.
- [ ] Tidak ada akumulasi session file orphan per-request di `/tmp`.

## 7. Risiko

- Bila host produksi asli **tidak** punya TLS dan `COOKIE_SECURE` tidak di-set,
  bug akan kembali — fix ini mewajibkan HTTPS sungguhan (atau override eksplisit)
  di deployment produksi.
- Salah konfigurasi `TRUSTED_PROXIES` (whitelist terlalu longgar) berpotensi
  memalsukan `X-Forwarded-Proto` → pastikan hanya IP proxy sebenarnya.
- reCAPTCHA tetap fail-closed bila `RECAPTCHA_SECRET` belum di-deploy — itu
  perilaku yang disengaja (Plan 34), bukan bug.
