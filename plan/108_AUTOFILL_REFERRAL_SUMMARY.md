# Plan 108 — SUMMARY: Auto-fill Kode Undangan (Referral Code) via URL `/register?ref=CODE`

> **Status:** KODE SELESAI DITULIS — **VERIFIKASI RUNTIME TERBLOKIR** (lihat §4).
> **Blueprint:** `plan/108_AUTOFILL_REFERRAL_PLAN.md` (disetujui pemilik repositori).
> **Keputusan mengikat:** `dec-37ae9226e2838c01` — **D1** clamp 6 karakter
> (sanitizer + `maxlength="6"`; `VARCHAR(10)` tetap), **D2** hanya `/team`
> yang disentuh; Home & Profile tidak diubah.
> **Tanggal:** sesi implementasi plan/108.

---

## 1. Ringkasan Eksekusi

| Item | Hasil |
|---|---|
| Berkas baru | 1 (`application/helpers/referral_helper.php`) |
| Berkas diubah | 3 (`application/config/autoload.php`, `application/controllers/Auth.php`, `application/views/auth/register.php`) |
| Berkas **tidak** diubah (target nol-diff) | `application/controllers/Team.php`, `application/config/routes.php`, `views/home/index.php`, `views/profile/index.php` |
| DDL / migrasi | **nol** (`users.invite_code` tetap `VARCHAR(10)`) |
| Key kamus | **nol** (paritas tetap 602/602) |
| Perubahan `system/` | **nol** |

---

## 2. Perubahan per Berkas

### 2.1 BARU — `application/helpers/referral_helper.php` (138 baris)

Choke-point tunggal, murni (nol DB/session/output), `function_exists()`-guarded,
pola `wa_group_helper.php` (plan/105) / `ewallet_helper.php` (plan/106):

| Fungsi | Perilaku |
|---|---|
| `referral_code_normalize($raw)` | non-scalar → `''`; buang `[^0-9A-Za-z]`; `strtoupper`; `substr(0, 6)`. **Clamp = batas atas, bukan syarat panjang** — input pendek diteruskan apa adanya. |
| `referral_code_is_valid($code)` | cek ketat `/^[0-9A-Z]{6}$/`. |
| `referral_code_resolve($url_ref, $stored)` | prioritas `url_ref` → `stored`, keduanya sudah ternormalisasi. |
| `referral_capture_key()` | `'referral_code'` — satu sumber nama key session + cookie. |
| `referral_capture_ttl()` | **`2592000` (30 hari)** — Refinement 1. |

Satu konstanta guarded ditambahkan di level file:
`defined('REFERRAL_CODE_LENGTH') OR define('REFERRAL_CODE_LENGTH', 6);`
(satu sumber panjang 6 dipakai `normalize()` dan `is_valid()`; guarded agar
aman pada `include` berulang dari CLI).

### 2.2 `application/config/autoload.php`

- `'referral'` ditambahkan ke `$autoload['helper']` (kini 11 helper) + blok komentar pola plan/104–106.

### 2.3 `application/controllers/Auth.php`

| Lokasi | Perubahan |
|---|---|
| `:112-143` (baru, sebelum `// ─── REGISTER ───`) | `private function _referral_prefill(): string` — guard `is_array($raw)` untuk `?ref[]=x`; persist ke session + cookie saat `ref` URL ada; resolve session → cookie. |
| `:151-156` (`register()`, tepat setelah guard login) | **Refinement 2:** `$this->load->vars(['invite_prefill' => $this->_referral_prefill()]);` — injeksi ke memori view GLOBAL CI3, bukan `$data` lokal, sehingga tersedia di **semua** cabang render (pendaftaran ditutup, rate limit, captcha, kode invalid, `uk_phone` bentrok, render final). |
| `:240-250` (cabang `if ($user_id)`) | Cleanup sebelum `redirect('login')`: `unset_userdata(referral_capture_key())` → `$this->load->helper('cookie')` → `delete_cookie(referral_capture_key())` → flashdata → redirect. `'cookie'` TIDAK di-autoload (loader lokal, bukan autoload baru). |

### 2.4 `application/views/auth/register.php:413`

```diff
- value="<?= set_value('invite_code') ?>"
+ value="<?= set_value('invite_code', $invite_prefill ?? '') ?>"
```

`maxlength="6"` (`:415`) dan sifat editable (tanpa `readonly`/`disabled`) **dipertahankan**.
Hierarki prioritas a→e dari plan §3.1 terpenuhi tanpa percabangan tambahan karena
semantik `set_value()` CI3 (nilai POST menang bila field ada; `$default` hanya bila absen).

---

## 3. Refinement yang Diterapkan

| # | Refinement | Bukti penerapan |
|---|---|---|
| 1 | `referral_capture_ttl()` → **`2592000`** (30 hari), menyamai cookie `site_lang` | `application/helpers/referral_helper.php:136` (`return 2592000; // 30 hari`) + docblock `:125-130` |
| 2 | Injeksi prefill via `$this->load->vars([...])` (memori view global), bukan `$data['invite_prefill']` | `application/controllers/Auth.php:156` + komentar alasan `:151-155` |

---

## 4. Status Verifikasi — JUJUR & PENTING

**Semua perintah CLI/HTTP di bawah TIDAK DAPAT DIJALANKAN pada sesi ini.**
Host memblokir setiap tool yang mampu menjalankan perintah (`bash` maupun
`use_capability` → `tool:bash`) dengan respons tetap:

```
[evidence required] bash cannot declare which files it changes while a
read-evidence requirement is outstanding
(/home/tommy/dev/webtable/application/controllers/Auth.php,
 /home/tommy/dev/webtable/application/views/auth/register.php);
use the exact file tool for those paths
```

Blokir ini muncul walau kedua berkas sudah dibaca ulang **secara penuh**
(`read_file` `intent=full`) setelah edit terakhir. Karena itu **tidak ada**
klaim `php -l` bersih, gate i18n exit 0, atau HTTP 200/302 di dokumen ini.

### 4.1 Verifikasi STATIS yang benar-benar dilakukan

Seluruh berkas yang diubah dibaca ulang penuh pasca-edit dan dikonfirmasi:

| # | Cek statis | Hasil | Sumber |
|---|---|---|---|
| S-1 | 5 fungsi helper ada, murni, `function_exists()`-guarded, `defined('BASEPATH') OR exit()` | OK | `read_file` full `referral_helper.php` (138 baris) |
| S-2 | TTL helper = `2592000` (Refinement 1) | OK | `referral_helper.php:136` |
| S-3 | `_referral_prefill()` ada di `Auth`, tepat setelah guard login `register()` memanggil `load->vars` | OK | `Auth.php:112-156` |
| S-4 | Cleanup `unset_userdata` + `delete_cookie` berada **sebelum** `redirect('login')` | OK | `Auth.php:240-250` |
| S-5 | View memakai `set_value('invite_code', $invite_prefill ?? '')`; `maxlength="6"` utuh; **nol** `readonly`/`disabled` | OK | `register.php:413-415` |
| S-6 | `'referral'` masuk `$autoload['helper']` | OK | `autoload.php` (edit terverifikasi) |
| S-7 | `Team.php:73` **tidak berubah** (`base_url('register?ref=' . $user->invite_code)`, gate `$referral_locked` utuh); parameter `ref` cocok dengan `input->get('ref')` di `Auth` | OK | `Team.php:71-74`, `Auth.php:120` |
| S-8 | Home & Profile **tidak disentuh** (D2) | OK | nol diff pada kedua view |

### 4.2 Verifikasi: **Skipped / Pending Manual QA by User due to host bash restrictions.**

**Tidak ada satu pun uji runtime (V1–V6) yang dijalankan, dan tidak ada yang
diklaim lulus.** Seluruh matriks verifikasi diserahkan ke QA manual pemilik
repositori. Perintah siap tempel ada di §4.2.1, probe sanitizer lengkap di §4.3.

#### 4.2.1 Perintah QA manual (belum dijalankan)

```bash
# V1 — lint (WAJIB sebelum "done" per AGENTS.md)
php -l application/helpers/referral_helper.php
php -l application/config/autoload.php
php -l application/controllers/Auth.php
php -l application/views/auth/register.php

# V2 — gate i18n (register.php = member surface; harapan tetap 602/602)
php scripts/audit_i18n_parity.php
php scripts/audit_i18n_hardcoded.php

# V3 — probe sanitizer: lihat §4.3 (script lengkap siap tempel)

# V5 — kontrak link /team (harapan: nol diff, format cocok)
sed -n '71,74p' application/controllers/Team.php

# V4/V6 — matriks HTTP (lihat plan §7): jalankan `php -S`, curl dengan cookie jar
```

### 4.3 Probe sanitizer (V3) — siap tempel ke `/tmp/probe108.php`

```php
<?php
define('BASEPATH', '/tmp/');
require '/home/tommy/dev/webtable/application/helpers/referral_helper.php';
$fails = 0;
function t($label, $got, $want) {
    global $fails;
    $ok = ($got === $want);
    if ( ! $ok) { $fails++; }
    printf("  [%s] %-38s got=%-12s want=%s\n", $ok ? 'PASS' : 'FAIL', $label,
        var_export($got, true), var_export($want, true));
}
echo "== normalize ==\n";
t("'abc123'",               referral_code_normalize('abc123'), 'ABC123');
t("'ab-c1 23!'",            referral_code_normalize('ab-c1 23!'), 'ABC123');
t("'12345678901' (clamp6)", referral_code_normalize('12345678901'), '123456');
t("'!@#'",                  referral_code_normalize('!@#'), '');
t("''",                     referral_code_normalize(''), '');
t("null",                   referral_code_normalize(null), '');
t("['x'] (array)",          referral_code_normalize(['x']), '');
t("'ab' (short kept)",      referral_code_normalize('ab'), 'AB');
echo "== XSS shape guarantee ==\n";
foreach (['<script>alert(1)</script>', '"><img src=x onerror=1>', "'--", 'a<b>c"d\'e'] as $p) {
    $out = referral_code_normalize($p);
    t('payload: '.substr($p, 0, 20),
      ((bool) preg_match('/^[0-9A-Z]{0,6}$/', $out) && strpbrk($out, '<>&"\'') === false), true);
}
echo "== is_valid (strict 6) ==\n";
t("'ABC123'", referral_code_is_valid('ABC123'), true);
t("'AB'",     referral_code_is_valid('AB'), false);
t("'ABC12'",  referral_code_is_valid('ABC12'), false);
t("'abc123'", referral_code_is_valid('abc123'), false);
t("null",     referral_code_is_valid(null), false);
echo "== resolve (url > stored) ==\n";
t("('ZZZ999','ABC123')", referral_code_resolve('ZZZ999', 'ABC123'), 'ZZZ999');
t("('','ABC123')",       referral_code_resolve('', 'ABC123'), 'ABC123');
t("('','')",             referral_code_resolve('', ''), '');
t("(null,null)",         referral_code_resolve(null, null), '');
echo "== capture contracts (refinement 1) ==\n";
t('key', referral_capture_key(), 'referral_code');
t('ttl = 30 days', referral_capture_ttl(), 2592000);
printf("\nRESULT: %s (%d fail)\n", $fails === 0 ? 'ALL PASS' : 'FAILURES', $fails);
exit($fails === 0 ? 0 : 1);
```

**Ekspektasi:** `ALL PASS (0 fail)` → exit 0.

---

## 5. Penyimpangan dari Blueprint (disetujui secara implisit, dicatat eksplisit)

1. **Konstanta `REFERRAL_CODE_LENGTH` (item ke-6, di luar 5 fungsi blueprint).**
   Ditambahkan karena blueprint memakai angka `6` di **dua** tempat
   (`normalize` clamp + `is_valid` regex). Guarded `define()` agar aman pada
   include berulang — **bukan** fungsi publik baru, jadi kontrak 5 fungsi tetap.
2. **Koreksi docblock `referral_code_normalize()` di tengah implementasi.**
   Draft pertama menjamin hasil "selalu `''` atau `/^[0-9A-Z]{6}$/`" — **salah**:
   clamp adalah batas atas, sehingga `?ref=ab` menghasilkan `AB` (2 karakter).
   Docblock diperbaiki menjadi jaminan bentuk `/^[0-9A-Z]{0,6}$/` + catatan
   eksplisit bahwa sanitasi **tidak** menolak. Jaminan keamanan (nol karakter
   HTML yang bisa lolos) tetap utuh dan tidak berubah.
   Blueprint §5 memuat klaim yang sama-sama longgar dan sebaiknya disinkronkan
   bila plan/108 direvisi.

Perilaku yang disengaja (sesuai requirement, bukan bug):
`?ref=ab` → di-prefill `AB` → ditolak saat submit oleh `auth_err_invite_invalid`
yang sudah ada (plan §8 non-goal #6: tanpa lookup DB saat GET).

---

## 6. Sisa Pekerjaan

1. **Jalankan §4.2 + §4.3** (lint, dua gate i18n, probe, matriks HTTP V4/V6) dan
   ganti §4 dokumen ini dengan output aktual.
2. **V6** (cleanup pasca-registrasi sukses) adalah satu-satunya langkah yang
   menyentuh DB lokal: ekstrak `auth_captcha` dari berkas session
   (`sess_driver='files'`, `sess_save_path=sys_get_temp_dir()`), ambil
   `SELECT invite_code FROM users LIMIT 1` (read-only), lalu POST register
   dengan nomor sekali-pakai. Bila DB lokal tidak tersedia → tandai
   **belum terverifikasi**, jangan diklaim lulus.
3. Perbarui `AGENTS.md` (inventaris helper + entri Notes plan/108).
4. Follow-up di luar scope (plan §11): de-duplikasi generator kode undangan
   (`User_model` vs `Admin_model`), dan konsistensi "copy link" di Home/Profile.
