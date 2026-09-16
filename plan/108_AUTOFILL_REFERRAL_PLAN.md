# Plan 108 — Auto-fill Kode Undangan (Referral Code) via URL `/register?ref=CODE`

> **Status:** BLUEPRINT (dokumen arsitektur SAJA). Belum ada satu pun perubahan
> kode aplikasi, skema DB, controller, model, route, view, helper, atau kamus
> bahasa yang dilakukan oleh dokumen ini. **Deliverable round ini = file ini
> saja** (`plan/108_AUTOFILL_REFERRAL_PLAN.md`). Eksekusi implementasi
> **MENUNGGU instruksi lanjutan terpisah** dari pemilik repositori.
>
> **Keputusan pemilik repositori (sudah dikonfirmasi, mengikat desain) —
> `dec-37ae9226e2838c01`:**
>
> | # | Keputusan |
> |---|---|
> | **D1** | **Clamp 6 karakter** untuk sanitizer **dan** input frontend (`maxlength="6"`). Kolom `users.invite_code` tetap `VARCHAR(10)` sebagai headroom storage — **tidak ada DDL**. Semua kode undangan Synapse adalah tepat 6 karakter `[0-9A-Z]`. |
> | **D2** | **Scope link share: hanya `/team`.** Home dan Profile **tidak disentuh** — kartu/tombol copy di sana tetap menyalin kode mentah (bukan URL). `Team.php:73` tetap memakai `base_url(...)` dan tetap di balik gate `$referral_locked`. Cukup **diverifikasi** bahwa format query-parameternya kanonik dan cocok dengan handler register. |
>
> **Ruang lingkup:** menangkap `?ref=CODE` pada `GET /register`, menyanitasi,
> menyimpan sementara (session + cookie pendek), mengisi otomatis field "Kode
> Undangan", mempertahankannya saat language switch / navigasi / validasi
> gagal, dan membersihkannya setelah registrasi sukses.
>
> **Zero-impact:** nol DDL, nol route baru, nol key kamus baru, nol perubahan
> `system/`, nol perubahan Home/Profile.

---

## 1. Ringkasan & Success Criteria

### 1.1 Masalah yang diselesaikan

Link share afiliasi yang dibagikan member sudah berbentuk
`…/register?ref=ABC123` (dibangun di `Team.php:73`), tetapi **sisi register
tidak pernah membaca parameter `ref`**. Grep menyeluruh pada `application/**`
untuk `input->get('ref')`, `_GET['ref']`, `location.search`, dan
`URLSearchParams` **tidak menemukan satu pun** pemakaian. Konsekuensinya:

1. **`?ref=` adalah jalur mati.** Calon member yang mengklik link afiliasi
   mendarat di form register kosong dan harus mengetik 6 karakter kode secara
   manual — friksi terbesar di puncak funnel akuisisi.
2. **Tidak ada atribusi yang tahan gangguan.** Bahkan bila kode diketik, satu
   language switch atau reload menghapus niat tersebut dari layar.
3. Form hanya mengisi ulang dari `set_value('invite_code')`, yaitu murni
   repopulasi POST (`register.php:413`).

### 1.2 Solusi

Satu choke-point helper baru (`referral_helper.php`) + hook tipis di `Auth`
dan satu ekspresi di view register:

`GET /register?ref=abc123` → sanitasi (`ABC123`) → persist (session + cookie
24 jam) → prefill field → bertahan melewati language switch & validasi gagal →
dibersihkan tepat setelah registrasi sukses.

### 1.3 Success Criteria

| # | Kriteria | Bukti verifikasi |
|---|---|---|
| S1 | `GET /register?ref=abc123` merender `value="ABC123"` pada `#invite_code` | V4a §7 |
| S2 | Sanitasi deterministik: strip non-alnum, `strtoupper`, clamp **6** | V3 §7 (probe CLI) |
| S3 | Prioritas nilai field: `set_value()` → `ref` URL → session → cookie → `''` | V4c/V4f/V4g §7 |
| S4 | Nilai `ref` bertahan setelah **language switch** EN↔ID dan setelah reload tanpa query string | V4d/V4e §7 |
| S5 | Field **tetap editable** — tidak ada atribut `readonly`/`disabled`; `maxlength="6"` dipertahankan | V1b §7 (grep statis) |
| S6 | Setelah registrasi sukses: session `referral_code` dihapus **dan** cookie `referral_code` di-expire, tepat sebelum `redirect('login')` | V6 §7 |
| S7 | Format URL share `/team` kanonik & cocok dengan handler register (`base_url('register?ref='.code)` ≡ `site_url('register?ref='.code)`) | V5 §7 |
| S8 | Nol regresi pada alur register pra-eksisting (rate limit, captcha, `auth_err_invite_invalid`, race `uk_phone`) | V4f/V4h/V6 §7 |
| S9 | Nol key kamus baru → paritas tetap **602/602**; kedua gate i18n exit 0 | V2 §7 |
| S10 | `php -l` bersih pada semua berkas yang diubah/dibuat | V1 §7 |

---

## 2. Fakta Codebase Terverifikasi (pra-edit)

Semua baris diverifikasi langsung pada repo sesi ini (PHP 8.3.6; audit i18n
`602/602` exit 0; `audit_i18n_hardcoded` 0 temuan).

| Fakta | Bukti |
|---|---|
| Kolom `users.invite_code` = **`VARCHAR(10) NOT NULL`** + `UNIQUE KEY uk_invite_code` + `INDEX idx_invite_code` | `database.sql:15`, `:30`, `:32` |
| **Kode yang benar-benar digenerate = 6 karakter**, charset `0123456789A…Z`, loop unik via `get_where` | `application/models/User_model.php:526-538`; dipanggil `:20` di dalam `create_user()` |
| Generator **terduplikasi** kedua (byte-for-byte sama) untuk jalur admin | `application/models/Admin_model.php:1072-1081`; dipanggil `Admin.php:1352`; batas admin `max_length[10]` di `Admin.php:755` |
| Salinan generator di seed script (dev-only) | `scripts/seed_wage_test_account.php:69`, `scripts/seed_withdraw_test_account.php:65` |
| **Tidak ada helper bersama** untuk kode undangan/referral | grep `invite\|referral\|ref_` pada `application/helpers/**` → hanya parsing token WhatsApp di `wa_group_helper.php` |
| Input register: `value="<?= set_value('invite_code') ?>"`, **`maxlength="6"`**, tanpa `readonly`/`disabled`, class `uppercase`; form = `form_open('register', …)` (POST tanpa query string) | `application/views/auth/register.php:409`, `:413-417` |
| Severity: `set_value()` CI3 mengembalikan nilai POST bila field **ada** di POST (termasuk `''`), dan `$default` hanya bila field **absen** → ini persis hierarki prioritas yang diminta | `system/helpers/form_helper.php` (`set_value` → `form_prep`/`html_escape`) |
| `Auth extends CI_Controller`; constructor urut: `maintenance_gate()` → pin WIB → `i18n_apply()` → `load` helper/model | `application/controllers/Auth.php:4`, `:6-31` |
| `Auth::register()` rentang penuh; guard login `:114-116`; cabang "pendaftaran ditutup" `:118-125`; rate limit `:132-145`; captcha `:150-155`; normalisasi+kode `:157-187`; `required` untuk `invite_code` `:168`; lookup induk & `auth_err_invite_invalid` `:170-181`; sukses → `redirect('login')` `:200-202`; render final `:211-212` | `application/controllers/Auth.php:113-213` |
| `_render_auth_view()` dipanggil dari **banyak** cabang → setiap var view wajib sudah ada di `$data` sebelum render | `application/controllers/Auth.php:107-110` |
| `Team.php` adalah **satu-satunya** pembangun `register?ref=`: `'ref_url' => $referral_locked ? '' : base_url('register?ref=' . $user->invite_code)` | `application/controllers/Team.php:71-74` (baris 73) |
| `/team` menampilkan `<code id="ref-url">` + tombol `copyRef()` (clipboard) dan QR berisi `ref_url` | `application/views/team/index.php:185-191`, `:553`, `:562-589` |
| Home & Profile **hanya menampilkan/menyalin KODE mentah**, bukan URL (dan pada Home disembunyikan saat `referral_locked`) | `views/home/index.php:207-223`, `:259-313`; `views/profile/index.php:52-68`, ~`:305-330` |
| Language switch: `GET /lang/switch/(en\|id)` → set session + cookie 30 hari → `redirect()` ke **Referer same-host** (fallback `base_url()`). Referer umumnya memuat query string, tetapi **tidak dijamin** (Referrer-Policy) → persistensi wajib disimpan eksplisit | `application/controllers/Lang.php:36-61` (`set_cookie` `:53-57`, redirect `:59-60`) |
| Pola persistensi kanonik repo: **session dulu → cookie sebagai fallback lintas-restart** | `i18n_resolve()` (`application/helpers/i18n_helper.php`) |
| `index_page = ''` → `base_url('register?ref=X')` dan `site_url('register?ref=X')` **identik** | `application/config/config.php:40`, `:27` |
| Session: `sess_driver='files'`, `sess_save_path=sys_get_temp_dir()`, cookie `ci_session`, regenerasi `TRUE` — relevan untuk harness verifikasi V6 | `application/config/config.php:388-395` |
| CSRF aktif (`csrf_protection=TRUE`, `csrf_regenerate=FALSE`) — tidak terpengaruh (alur ini GET) | `application/config/config.php:464-469` |
| Helper autoload saat ini: `url, file, form, security, language, i18n, maintenance, product_image, wa_group, ewallet` — **`cookie` TIDAK di-autoload** | `application/config/autoload.php` (`$autoload['helper']`) |
| Preseden choke-point helper murni + guard `function_exists()` (aman di-`include` dari CLI): `wa_group_helper.php` (plan/105), `ewallet_helper.php` (plan/106), `product_image_helper.php` (plan/104) | ketiga berkas di `application/helpers/` |
| Route `register` sudah ada; query string **tidak** dirutekan CI3 → nol perubahan `routes.php` | `application/config/routes.php:5` |
| Working tree saat ini: `$active_group = 'local'`, fallback `base_url` = `http://synapse.test/`; HEAD = `live` / `https://synapserent.com/` | `application/config/database.php:73`; `application/config/config.php:27` |

---

## 3. Arsitektur & Alur

### 3.1 Hierarki prioritas nilai field (WAJIB, sesuai permintaan)

```
#invite_code value =
  a. set_value('invite_code')          ← POST (termasuk string kosong) — TERTINGGI
  b. ref pada URL saat ini  ?ref=…     ← setelah sanitasi 6 char
  c. session('referral_code')          ← tersimpan dari kunjungan sebelumnya
  d. cookie('referral_code')           ← fallback lintas-restart browser (24 jam)
  e. ''                                ← registrasi organik
```

**Implementasi:** `set_value('invite_code', $invite_prefill)` — CI3 sudah
memberikan semantik yang tepat, sehingga a > (b|c|d|e) tanpa percabangan
tambahan, sementara `$invite_prefill` menyelesaikan b > c > d > e.

Konsekuensi yang disengaja: pada POST yang gagal validasi, **editan user
selalu menang** atas `ref`/nilai tersimpan (inilah tujuan prioritas a).

### 3.2 Siklus hidup nilai

```
GET /register?ref=abc123
  └─ _referral_prefill()
       ├─ normalize('abc123') = 'ABC123'
       ├─ persist: session['referral_code']='ABC123'  +  cookie 'referral_code' (24 jam)
       └─ resolve(url, stored) = 'ABC123'  ──► $data['invite_prefill']
                                              └─► view: value="ABC123"

GET /lang/switch/id  →  redirect back  →  GET /register   (tanpa ?ref)
  └─ _referral_prefill(): url kosong → session 'ABC123'          ──► tetap terisi

POST /register (gagal captcha / kode invalid)
  └─ set_value('invite_code') = nilai yang di-POST                ──► editan user menang

POST /register (SUKSES, $user_id truthy)
  └─ unset session['referral_code']  +  delete_cookie('referral_code')  ──► redirect('login')
```

### 3.3 Penempatan pemanggilan (presisi)

`_referral_prefill()` dipanggil sebagai **statement pertama `register()` setelah
guard login** (yaitu setelah `Auth.php:114-116`, sebelum cabang "pendaftaran
ditutup" `:118-125`). Alasannya: setiap cabang render — pendaftaran ditutup,
rate limit, captcha, kode invalid, `uk_phone` bentrok, dan render final —
memakai `$data` yang berbeda-beda, jadi var harus sudah diisi sebelum cabang
pertama. View tetap memakai `?? ''` sebagai jaring pengaman (pola `??` yang
sama dengan `MY_Controller`).

---

## 4. Inventaris Berkas

### 4.1 Berkas BARU

| Berkas | Isi |
|---|---|
| `application/helpers/referral_helper.php` | **Choke-point tunggal** normalisasi/validasi/resolusi kode undangan. 5 fungsi murni, `function_exists()`-guarded, `defined('BASEPATH') OR exit(...)`, nol efek samping. |
| `plan/108_AUTOFILL_REFERRAL_PLAN.md` | Dokumen ini (deliverable round ini). |
| `plan/108_AUTOFILL_REFERRAL_SUMMARY.md` | **Belum dibuat** — ditulis di fase verifikasi (setelah implementasi), memuat matriks bukti aktual. |

### 4.2 Berkas DIUBAH

| Berkas | Perubahan | Anchor |
|---|---|---|
| `application/config/autoload.php` | Tambah `'referral'` ke `$autoload['helper']` + blok komentar pola plan/104–106 | `$autoload['helper']` |
| `application/controllers/Auth.php` | (1) method privat baru `_referral_prefill()` di sebelah `_normalize_phone()`; (2) pemanggilan + injeksi `$data['invite_prefill']` di awal `register()`; (3) pembersihan session+cookie di cabang sukses | `:34-43` (sisip), `:116/118` (sisip), `:200-202` |
| `application/views/auth/register.php` | Satu ekspresi: `set_value('invite_code')` → `set_value('invite_code', $invite_prefill ?? '')` | `:413` |

### 4.3 Berkas DIVERIFIKASI SAJA (target: **nol perubahan**)

| Berkas | Yang diverifikasi |
|---|---|
| `application/controllers/Team.php` | `:73` menghasilkan `base_url('register?ref=' . $user->invite_code)`; nama parameter = `ref`; precedence `$referral_locked` utuh |
| `application/config/routes.php` | `$route['register'] = 'auth/register'` sudah ada; query string tidak butuh route |
| `application/views/{home,profile}/index.php` | **TIDAK DISENTUH** (keputusan D2) |

---

## 5. Kontrak API Helper (draft, `application/helpers/referral_helper.php`)

Seluruhnya **murni** (tanpa DB, tanpa session, tanpa output) dan dibungkus
`if ( ! function_exists('…'))` agar aman di-`include` ulang dari script CLI
migrasi/verifikator — pola persis `wa_group_helper.php`.

| Fungsi | Signature | Perilaku |
|---|---|---|
| `referral_code_normalize` | `(mixed $raw): string` | `is_array`/non-scalar → `''`. `trim` → buang `[^0-9A-Za-z]` → `strtoupper` → `substr(..., 0, 6)`. Hasil kosong → `''`. **Selalu** cocok `/^[0-9A-Z]{6}$/` atau `''`. |
| `referral_code_is_valid` | `(mixed $code): bool` | `is_string` && `preg_match('/^[0-9A-Z]{6}$/', $code) === 1`. |
| `referral_code_resolve` | `(string $url_ref, string $stored): string` | Anggota pertama yang tidak kosong: `$url_ref` lalu `$stored`. **Tidak** melakukan normalisasi (pemanggil sudah menormalisasi). |
| `referral_capture_key` | `(): string` | `'referral_code'` — satu sumber nama key session + cookie (anti magic-string ganda di controller & cleanup). |
| `referral_capture_ttl` | `(): int` | `86400` (**24 jam**, "short-lived cookie" — D3 di bawah). |

**Catatan keamanan:** karena `referral_code_normalize()` hanya mungkin
menghasilkan `[0-9A-Z]` (atau `''`), tidak ada karakter HTML yang bisa lolos ke
markup; `set_value()` tetap `html_escape()` sebagai pertahanan berlapis. Nilai
yang disimpan adalah **kode share publik** (sudah tampil di URL & dibagikan
member) → nol kebutuhan kerahasiaan; **jangan pernah** menyimpan hal lain di
key/cookie ini.

---

## 6. Langkah Eksekusi (P1–P6)

### P1 — Helper choke-point
1. Buat `application/helpers/referral_helper.php` berisi 5 fungsi §5.
2. Daftarkan `'referral'` di `$autoload['helper']` (`application/config/autoload.php`) + komentar pola plan/104–106 (fungsi murni → netral untuk member, admin, dan CLI).
3. `php -l application/helpers/referral_helper.php` && `php -l application/config/autoload.php`.

### P2 — Capture, persist, resolve (`Auth`)
4. Tambah `private function _referral_prefill(): string`:
   - baca `$raw = $this->input->get('ref', TRUE)`; **guard `is_array($raw)` → anggap kosong** (mencegah `?ref[]=x` menjadi fatal);
   - `$url_ref = referral_code_normalize($raw)`;
   - bila `$url_ref !== ''`: `$this->session->set_userdata(referral_capture_key(), $url_ref)` **dan** `$this->input->set_cookie(['name' => referral_capture_key(), 'value' => $url_ref, 'expire' => referral_capture_ttl()])`; nilai tersimpan **ditimpa** hanya oleh `ref` URL yang baru;
   - `$stored = referral_code_normalize($this->session->userdata(referral_capture_key()))`; bila kosong → `referral_code_normalize($this->input->cookie(referral_capture_key(), TRUE))`;
   - `return referral_code_resolve($url_ref, $stored);`
5. Sisipkan di `register()` sebagai statement pertama setelah guard login: `$data['invite_prefill'] = $this->_referral_prefill();` sehingga semua cabang render ikut membawanya.

### P3 — Prefill field (view)
6. `application/views/auth/register.php:413` → `value="<?= set_value('invite_code', $invite_prefill ?? '') ?>"`.
7. **Pertahankan** `maxlength="6"` (`:415`) dan sifat editable (tanpa `readonly`/`disabled`). Tidak ada key kamus baru, tidak ada perubahan copy → nol perubahan i18n.

### P4 — Cleanup pasca-registrasi sukses
8. Di dalam cabang `if ($user_id) {` (`Auth.php:200-202`), **sebelum** `redirect('login')`:
   ```php
   $this->session->unset_userdata(referral_capture_key());
   $this->load->helper('cookie');       // 'cookie' TIDAK di-autoload — loader, bukan autoload baru
   delete_cookie(referral_capture_key());
   ```
   Keduanya dieksekusi sebelum output apa pun (aman terhadap "headers already sent").
9. Jalur gagal (captcha salah, kode invalid, `uk_phone` bentrok, rate limit) **tidak** menghapus nilai tersimpan — user masih bisa memperbaiki tanpa kehilangan atribusi.

### P5 — Verifikasi & standardisasi link `/team` (target: nol perubahan kode)
10. Konfirmasi `Team.php:73` menghasilkan `base_url('register?ref=' . $user->invite_code)` dan bahwa `base_url('register?ref=X') === site_url('register?ref=X')` karena `index_page=''` (`config.php:40`). Gate `$referral_locked` (`:71-74`) tetap utuh — kode member terkunci tidak boleh bocor ke markup.
11. Konfirmasi nama parameter query = **`ref`**, identik dengan yang dibaca `_referral_prefill()`.
12. **Hanya bila** salah satu cek gagal → ubah minimal (mis. ke `site_url()` atau `rawurlencode()`), lalu ulangi cek. Bila lulus, tidak ada diff pada `Team.php`.

### P6 — Verifikasi menyeluruh & catatan perubahan
13. Jalankan matriks §7 (V1–V6) dan **catat output aktual** (bukan klaim).
14. Tulis `plan/108_AUTOFILL_REFERRAL_SUMMARY.md` (status, berkas, bukti per-V, sisa pekerjaan).
15. Perbarui `AGENTS.md`: tambah `referral_helper` ke inventaris helper + entri Notes plan/108 (mengikuti definisi selesai di AGENTS.md).
16. Pastikan **tidak ada** berkas scratch tertinggal (probe wajib di `/tmp`, dihapus setelah dipakai).

---

## 7. Matriks Verifikasi

> Tidak ada test suite di repo ini (`tests/` tidak ada; script `test:coverage`
> di `composer.json` menunjuk direktori yang tidak eksis — **jangan** diklaim
> berjalan). Semua bukti = CLI gate + `php -l` + HTTP `curl` + probe `/tmp`.

| # | Uji | Perintah / metode | Hasil yang diharapkan |
|---|---|---|---|
| V1 | Lint | `php -l application/helpers/referral_helper.php` `php -l application/config/autoload.php` `php -l application/controllers/Auth.php` `php -l application/views/auth/register.php` | 4× `No syntax errors detected` |
| V1b | Field tetap editable | grep statis `register.php` untuk `readonly`/`disabled` pada `#invite_code` + cek `maxlength="6"` | 0 temuan `readonly/disabled`, `maxlength="6"` utuh |
| V2 | Gate i18n (register.php = member surface) | `php scripts/audit_i18n_parity.php` `php scripts/audit_i18n_hardcoded.php` | keduanya exit 0; paritas **602/602** (nol key baru) |
| V3 | Probe sanitizer (`/tmp`, dihapus) | include helper; uji: `'abc123'→'ABC123'`, `'ab-c1 23!'→'ABC123'`, `'12345678901'→'123456'`, `'!@#'→''`, `''→''`, `['x']→''`, `null→''`, `'<script>alert(1)</script>'→` hasil `/^[0-9A-Z]{6}$/` | setiap hasil non-kosong cocok `/^[0-9A-Z]{6}$/` **dan** tanpa `< > " '` |
| V4a | Prefill dasar | `curl -c jar 'http://<origin>/register?ref=abc123'` | HTTP 200; `id="invite_code"` memuat `value="ABC123"` |
| V4b | Cookie terpasang | `curl -b jar -c jar` lalu inspeksi cookie jar | `referral_code=ABC123`, kedaluwarsa ~24 jam |
| V4c | Prefill tanpa query (session/cookie) | `curl -b jar 'http://<origin>/register'` | `value="ABC123"` (V4a→V4c urutan) |
| V4d | Tahan language switch | `curl -b jar '/lang/switch/id'` → `curl -b jar '/register'` → lalu `'/lang/switch/en'` → `'/register'` | `value="ABC123"` di **kedua** idiom; `<html lang="id">` saat mode ID |
| V4e | Input `?ref=` mendominasi nilai tersimpan | jar berisi `ABC123`; `curl -b jar '/register?ref=zzz999'` | `value="ZZZ999"` |
| V4f | Selamat dari validasi gagal | `curl -b jar -X POST '/register'` dengan captcha salah (field `invite_code=ABC123`) | HTTP 200 re-render; `value="ABC123"`; pesan `auth_err_captcha` |
| V4g | Prioritas a — user menang | POST dengan `invite_code=` (sengaja dikosongkan) + captcha salah | `value=""` (bukan diisi ulang dari session) |
| V4h | Kode invalid ditangani | POST dengan `invite_code=ZZZ999` + captcha benar | re-render dengan `auth_err_invite_invalid`; **tidak** crash; nilai tersimpan tetap ada |
| V5 | Kontrak link `/team` | statis: `base_url('register?ref=X')` ≡ `site_url('register?ref=X')`; E2E: `curl '/register?ref=<invite_code nyata dari DB>'` | format & nama parameter `ref` cocok ⇒ handler mengonsumsi persis format yang diproduksi `/team` |
| V6 | Cleanup pasca-sukses | (a) ambil `auth_captcha` dari berkas session di `sys_get_temp_dir()` (`sess_driver='files'`); (b) `SELECT invite_code FROM users LIMIT 1` (**read-only**); (c) POST register dengan phone sekali-pakai + captcha benar | HTTP **302 → `/login`**; respons memuat `Set-Cookie` kedaluwarsa untuk `referral_code`; session tidak lagi memuat `referral_code` |

**Catatan penting untuk V6:** ini **satu-satunya** langkah yang menyentuh DB
lokal (membuat 1 baris user sekali-pakai; SELECT-nya read-only). Bila MySQL
lokal tidak tersedia, langkah ini **ditandai belum terverifikasi** dalam
`plan/108_AUTOFILL_REFERRAL_SUMMARY.md` — bukan diklaim lulus. Fallback bukti
yang sah: lint + review statis bahwa kedua statement cleanup berada sebelum
`redirect('login')`.

**Origin harness:** working tree memakai fallback `base_url =
http://synapse.test/` (`config.php:27`) dan `$active_group = 'local'`
(`database.php:73`). Jalankan `php -S` dan pakai origin yang benar-benar
tercetak, atau set `APP_BASE_URL` — jangan mengarang host.

---

## 8. Non-Goal (eksplisit)

1. **Home & Profile tidak disentuh** — kartu/tombol copy di sana tetap menyalin kode mentah (D2).
2. **Tidak ada DDL.** `users.invite_code` tetap `VARCHAR(10)`.
3. **Tidak ada key kamus baru** dan tidak ada perubahan copy → nol perubahan i18n (paritas tetap 602/602).
4. **Tidak ada route baru** (`routes.php` tidak disentuh).
5. **Tidak ada perubahan `system/`.**
6. **Tidak ada validasi keberadaan kode di DB saat GET** — dipilih agar alur GET bebas DB. Kode berformat benar tapi tidak eksis tetap ter-prefill dan ditolak saat submit oleh pesan `auth_err_invite_invalid` yang sudah ada.
7. **Tidak ada de-duplikasi generator** `User_model::_generate_invite_code()` / `Admin_model::generate_invite_code()` (temuan sampingan, di luar scope — lihat §11).
8. **Tidak ada** penangkapan `?ref=` di `/login`, `/home`, atau landing page lain.
9. **Tidak ada** pembersihan `referral_code` saat logout (hanya saat registrasi sukses) — cookie tetap "short-lived" 24 jam.
10. **Tidak ada** perubahan pada alur POST `invite_code` itu sendiri (validasi, lookup induk, `parent_id`).

---

## 9. Risiko & Mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R1 | `?ref[]=` (array) membuat `input->get()` mengembalikan array → error/notice | 500 pada rute publik | Guard `is_array()` di controller **dan** di `referral_code_normalize()` (non-scalar → `''`); dibuktikan V3 |
| R2 | `base_url()` vs `site_url()` berbeda format | Link share rusak | Terverifikasi identik karena `index_page=''`; V5 + langkah perbaikan minimal bila gagal |
| R3 | Nilai `ref` dari URL disuntikkan ke markup | XSS | Sanitasi allowlist `[0-9A-Z]` + clamp 6 + `set_value()` yang `html_escape()`; dibuktikan V3 |
| R4 | Cookie diset setelah output → "headers already sent" | Cookie gagal / warning | `set_cookie`/`delete_cookie` dipanggil sebelum render view dan sebelum `redirect()` |
| R5 | Var `$invite_prefill` tidak ada pada salah satu cabang render | Undefined variable di view | Var diisi sebelum cabang pertama + view memakai `?? ''` |
| R6 | Nilai tersimpan "membeku" dan salah mengatribusikan registrasi berikutnya | Atribusi salah | Hanya `ref` URL yang menimpa nilai; dibersihkan saat sukses; TTL cookie 24 jam (satu konstanta di helper) |
| R7 | Atribusi bocor lewat cookie ke pengguna bersama perangkat | Atribusi salah | Cookie publik non-rahasia + umur pendek + dibersihkan saat sukses; dicatat sebagai trade-off yang diterima |
| R8 | Uji sukses (V6) sulit karena CAPTCHA terikat session | Bukti lemah | Ekstraksi `auth_captcha` dari berkas session; bila DB tak tersedia → tandai **belum terverifikasi** |
| R9 | Menambah helper autoload memperbesar bootstrap tiap request | Overhead mikro | Fungsi murni tanpa efek samping, file kecil; konsisten dengan plan/104–106 |

---

## 10. Rollback

Perubahan terisolasi pada 1 berkas baru + 3 berkas yang diubah, tanpa
DDL/route/kamus. Rollback = buang `application/helpers/referral_helper.php`,
kembalikan ekspresi `set_value('invite_code')` di `register.php:413`, hapus
`_referral_prefill()` + 2 blok pemanggilannya di `Auth.php`, dan hapus
`'referral'` dari `$autoload['helper']`. Nol migrasi data, nol pembersihan DB
(di luar 1 baris user uji V6 yang bersifat sekali-pakai).

---

## 11. Temuan Sampingan (di luar scope, untuk follow-up)

1. **Generator kode undangan terduplikasi** di `User_model::_generate_invite_code()` (`:526-538`) dan `Admin_model::generate_invite_code()` (`:1072-1081`) — dua implementasi byte-for-byte sama. Kandidat konsolidasi ke `referral_helper.php` (atau konsumsi `referral_code_is_valid()` untuk guard) pada plan terpisah, karena menyentuh jalur registrasi admin + member.
2. **Batas panjang kode tidak konsisten di tiga tempat**: `VARCHAR(10)` (DB), `max_length[10]` (`Admin.php:755`), `maxlength="6"` + generator 6 karakter (`register.php:415`, `User_model.php:531`). Keputusan D1 menetapkan **6** sebagai kontrak aplikasi; kolom DB sengaja dibiarkan `VARCHAR(10)`.
3. **Home & Profile hanya menyalin kode mentah**, sehingga member harus merangkai URL sendiri saat membagikan. Keputusan D2 menahannya pada round ini; konsistensi "copy link" di ketiga permukaan member layak dipertimbangkan sebagai plan lanjutan.
