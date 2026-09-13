# Plan 105 — Dynamic WhatsApp Group Link: System Settings & Integrasi Halaman Bantuan (Help & FAQ)

**Status:** BLUEPRINT / PLAN ONLY — belum ada baris kode, view, model, seed, helper, atau skema DB yang diubah.
**Deliverable plan ini:** dokumen arsitektur `plan/105_WHATSAPP_GROUP_LINK_SETTINGS_AND_HELP_INTEGRATION_PLAN.md`.
**Scope eksekusi:** dokumen ini **adalah** deliverable. P1–P7 di §12 adalah roadmap implementasi yang dieksekusi **setelah blueprint disetujui** (terpisah dari plan ini, menunggu perintah lanjutan).

**Objective:** menambahkan tautan undangan grup/komunitas WhatsApp resmi ke halaman Help & FAQ member (`/help`), yang dikelola secara dinamis dari Admin System Settings (`/admin/settings`) — dengan validasi server-side, audit trail, degradasi anggun (kartu disembunyikan total bila belum dikonfigurasi), dan paritas kamus EN/ID 1:1 (plan/103).

---

## 0. Konteks & Masalah

Halaman Bantuan member saat ini hanya menyediakan **dua kanal kontak langsung**: chat WhatsApp personal (`wa.me/<wa_number>`) dan email support — keduanya dibaca dari `system_settings` (`wa_number`, `support_email`; M7 plan/70). Lihat `application/views/help/index.php:11-33` dan `application/controllers/Help.php:10-14`.

Belum ada jalur untuk mengumumkan **komunitas/grup WhatsApp resmi** (broadcast update jaringan, pengumuman node GPU, bantuan antar-member). Kebutuhan bisnis: admin harus bisa memasang/mengganti/mengosongkan tautan grup kapan saja tanpa deploy, dan bila tautan belum ada **tidak boleh** muncul kartu kosong atau tautan rusak di sisi member.

Plan ini merancang: (a) key `system_settings.wa_group_link` (default `''`) tanpa perubahan DDL, (b) satu choke-point validasi/kanonikalisasi tautan, (c) field admin di kartu kontak dengan validasi Indonesia + audit atomik, (d) kartu komunitas emerald di `/help` dengan degradasi anggun, (e) 3 key kamus dwibahasa paralel.

---

## 1. Ringkasan & Success Criteria

| # | Kriteria | Bukti verifikasi |
|---|---|---|
| S1 | Key `wa_group_link` ada di DB live **dan** di `database.sql` + `database_seed.sql`, **tanpa DDL baru** | `SELECT key_name,key_value …` + grep kedua seed + `SHOW CREATE TABLE system_settings` identik baseline |
| S2 | Migrasi CLI idempoten & aman: `--dry-run` / `--apply` / `--verify`, re-run = no-op, tidak pernah menimpa nilai live | exit code `0`; `--apply` dijalankan dua kali |
| S3 | Admin dapat **set / ubah / kosongkan** tautan; input tidak valid ditolak **all-or-nothing** (tidak ada setting lain yang tersimpan) dengan pesan Indonesia | N1–N4 §13.3 |
| S4 | Audit `admin_update_settings` memuat `wa_group_link` di `keys`/`before`/`after` **hanya saat nilai benar-benar berubah** | `SELECT details FROM system_audit_logs …` (N7) |
| S5 | `/help` merender kartu komunitas **jika dan hanya jika** tautan kanonik tersimpan; selain itu **nol markup** (tanpa `href=""`, tanpa kartu separuh) | view-source kosong vs terisi (N1/N2/N3) |
| S6 | CTA membuka tab baru: `target="_blank" rel="noopener noreferrer"`; href di-escape `html_escape()` | view-source |
| S7 | 3 key kamus baru per idiom (**591 → 594**), himpunan key identik; `audit_i18n_parity.php` exit 0 & `audit_i18n_hardcoded.php` 0 temuan | kedua audit script |
| S8 | Admin 100% Indonesia (L1); seluruh copy member lewat `lang()` (plan/103); tanpa nominal/uang di kamus (L6/P3) | audit + review manual |
| S9 | Nol regresi pada form setting lain (finansial, rebate, QRIS) dan tombol kontak `/help` yang sudah ada | R1–R4 §13.4 |

**Non-goal:** menampilkan kartu komunitas di halaman member lain, QR/preview grup, preview kartu di panel admin, tautan multi-bahasa/multi-grup, unggah ikon grup, perubahan apa pun pada alur chat langsung `wa_number`, dan perubahan skema database.

---

## 2. Fakta Codebase Terverifikasi (pra-edit)

Semua baris di bawah diverifikasi langsung pada repo/DB sesi ini (PHP 8.3.6, MariaDB 12.3.2, DB `db_webtable`).

| Fakta | Bukti |
|---|---|
| `system_settings` = satu-satunya key-value store; `key_name` VARCHAR(50) UNIQUE (`uk_key_name`), `key_value` TEXT | `database.sql:317-324`; `SHOW CREATE TABLE system_settings` (live) |
| Live DB: **24 baris** `system_settings`, **tidak ada** baris `wa_group_link` (baseline bersih) | query mysqli via `application/config/database.php` |
| Seed kontak: `('wa_number','628000000000')`, `('support_email','support@synapse.id')` | `database.sql:343-344`, `database_seed.sql:282-283` |
| API baca/tulis kanonik: `Admin_model::get_setting()` (`null` bila baris hilang), `get_settings_map()`, `update_system_settings($data,$audit)` = 1 TX (upsert semua key + 1 baris audit) | `application/models/Admin_model.php:1144-1195` |
| Audit generik before→after untuk **semua** key yang di-POST: `admin_update_settings` `{keys,before,after}`; detail di-JSON-encode | `application/controllers/Admin.php:429-447`; `Audit_model::log_admin_action()` |
| Form admin: header Card 1 literal `General & Support` (Inggris → drift L1 pra-eksisting); input `wa_number` lalu `support_email` berakhir di baris 81 | `application/views/admin/settings.php:43-83` |
| Validasi POST admin: kontak via CI3 `form_validation` (`required|numeric`, `required|valid_email`); finansial & rebate via **validator di model** (`Wallet_model::validate_financial_settings`, `Rental_model::validate_rebate_settings`) | `application/controllers/Admin.php:375-418` |
| Endpoint tunggal `POST /admin/settings` = all-or-nothing (`if (!empty($errors)) redirect`) | `application/controllers/Admin.php:420-456` |
| `/help` → `help/index`; controller membaca 2 key dengan fallback `?:` | `application/config/routes.php:50`; `application/controllers/Help.php:10-14` |
| View `/help` = header + kartu kontak (11–33) + accordion FAQ 6 item + footer note; tanpa `<style>`/`<script>` tambahan selain accordion vanilla | `application/views/help/index.php` |
| **Baseline audit:** paritas kamus **591/591 OK (exit 0)**; hardcoded-string audit **0 temuan (exit 0)** | `php scripts/audit_i18n_parity.php --quiet`, `php scripts/audit_i18n_hardcoded.php --quiet` |
| Kedua kamus **simetris posisional** (urutan key EN ≡ ID, `diff` kosong); `help_contact_email` di baris **576** pada keduanya | `diff <(grep -o "^\$lang\['[^']*'\]" english/app_lang.php) <(…indonesian…)` = kosong |
| Gate audit yang mengikat markup: paritas P1/P3/P5/P6 + scanner R1/R2/R4 (scope: `views/**`, `controllers/**`, `models/**`, `helpers/**`; **exclude** `views/admin/**`, `Admin.php`, `Admin_auth.php`, `Admin_model.php`) | `scripts/audit_i18n_parity.php`, `scripts/audit_i18n_hardcoded.php:38-58` |
| CI3 `set_value()` **tidak** menyimpan old input antar-redirect → setelah validasi gagal, field menampilkan nilai tersimpan terakhir (perilaku eksisting `wa_number`/`support_email`) | `system/libraries/Form_validation.php:926-933`; tidak ada `old_input` di repo |
| Autoload helper: `array('url','file','form','security','language','i18n','maintenance','product_image')` | `application/config/autoload.php:98` |
| Preseden "satu choke-point helper" + guard `function_exists()`: `product_image_helper.php` (plan/104) | `application/helpers/product_image_helper.php:1-33` |
| Gaya CLI migrasi rumah: `--dry-run` (default) / `--apply` / `--verify`, mysqli dari config app, pin WIB, exit `0/1/2`, `INSERT IGNORE` tidak pernah menimpa nilai live | `scripts/migrate_102_qris_deposits.php`, `scripts/migrate_104_gpu_product_images.php` |
| Font Awesome **6.5.1** (CDN) + Tailwind CDN `darkMode:'class'`; token tema `u-card`/`u-text`/`u-text-2`/`t-input`/`t-card`/`t-label` | `application/views/templates/header.php:23-27,99-112`; `application/views/admin/settings.php:63` |
| **Vhost tidak menyala di environment ini**: `curl http://synapse.test/help` → `000` | curl (runtime matrix §13.3 harus dijalankan di host ber-vhost, atau `php -S` + front controller) |

---

## 3. Design Decisions (mengikat untuk eksekusi)

- **D1 — Storage.** Satu baris `system_settings` (`key_name='wa_group_link'`, `key_value` TEXT, default `''`), **tanpa DDL**. Baris hilang ≡ `''` ≡ kartu tersembunyi (fail-safe; pola sama dengan `is_maintenance_mode` plan/95 "default aman bila baris hilang").
- **D2 — Satu choke-point validasi/kanonikalisasi.** Helper baru `application/helpers/wa_group_helper.php` (di-autoload) mengekspos **tepat dua** fungsi:
  - `wa_group_link_normalize($raw)` → string kanonik `https://chat.whatsapp.com/<token>` | `''` (kosong, SAH) | `null` (tidak valid).
  - `wa_group_link_url($raw)` → string kanonik | `''` (tidak pernah `null`).
  Tiga konsumen memakai sumber yang sama: **admin POST** (validasi + simpan kanonik), **Help controller** (re-validasi saat render — DB bisa diedit manual via phpMyAdmin), **CLI migrasi `--verify`** (deteksi tamper). Duplikasi allowlist host di dua tempat = bug drift yang harus dihindari.
- **D3 — Kosong adalah nilai sah kelas satu** ("belum dikonfigurasi"), bukan error validasi → field admin **tidak** `required`, dan mengosongkannya secara sengaja menyembunyikan kartu.
- **D4 — Allowlist ketat.** Host **tepat** `chat.whatsapp.com` (prefix `www.` dinormalkan; host case-insensitive), skema **`https`** saja (input tanpa skema / `http://` dinaikkan ke `https`), **tanpa** userinfo (`@`) / port / host lain, token `[A-Za-z0-9_-]{6,64}`, bentuk legacy `/invite/<token>` dinormalkan ke `/<token>`, query/fragment/trailing slash dibuang, panjang raw ≤ 512, karakter format tak terlihat (ZWSP/ZWNJ/ZWJ/BOM) dibuang, whitespace di-trim.
- **D5 — Tanpa method model baru.** Persist memakai `Admin_model::update_system_settings()`, baca memakai `get_setting()` / `get_settings_map()`, audit memakai diff before→after generik yang sudah ada (`Admin.php:430-441` otomatis menyertakan `wa_group_link`). "Model handler" = API settings generik eksisting (tidak berubah) → permukaan regresi minimum.
- **D6 — Penempatan kartu.** Tepat **setelah** kartu kontak (baris 33) dan **sebelum** header FAQ (baris 35). CTA support eksisting tetap above-the-fold; "prominent" dicapai lewat styling emerald/gradient/glow, bukan dengan menggeser markup lama (penyisipan zero-risk).
- **D7 — Degradasi anggun dua lapis.** (1) Kanonikalisasi saat render (mematikan nilai tamper/invalid) **dan** (2) satu guard `if ($wa_group_link !== '')` membungkus **seluruh** kartu (tidak ada markup separuh/`href` kosong).
- **D8 — i18n.** Tepat **3 key** baru, disisipkan pada **posisi baris yang sama** di kedua idiom (setelah `help_contact_email`, baris 576) untuk menjaga simetri posisional; nol copy member literal di view/controller; nol i18n untuk admin (L1).
- **D9 — Perbaikan L1 insidental.** Header Card 1 `General & Support` → **`Kontak & Bantuan`** (header Inggris di permukaan yang wajib 100% Indonesia; juga sejalan dengan penamaan kartu pada brief). Dokumen historis `plan/70-71` bersifat arsip dan **tidak** ditulis ulang.
- **D10 — Tanpa route baru** (`/help` sudah ada; `/admin/settings` adalah pemetaan default), **tanpa perubahan envelope API** (tidak ada jalur AJAX), **tanpa DDL**.

### 3.1 Peta Requirement → Mekanisme

| Requirement | Mekanisme | Kode baru |
|---|---|---|
| Key + seed default `''` | baris `system_settings` | 2 baris seed (`database.sql`, `database_seed.sql`) + 1 tool CLI |
| Validasi tautan (server-side, Indonesia) | `wa_group_link_normalize()` + string error di `Admin::settings()` | helper baru + ±10 baris controller |
| Model handler | `get_settings_map()` / `update_system_settings()` | **tidak ada** (dipakai ulang) |
| Audit trail saat update | diff before→after `admin_update_settings` | **tidak ada** (dipakai ulang) |
| Pass ke view + render kartu | `Help::index()` → `wa_group_link` | 1 baris controller + ±25 baris view |
| Degradasi anggun | `wa_group_link_url()` + guard `!== ''` | bagian dari dua item di atas |
| Paritas dwibahasa | 3 key × 2 idiom | 6 baris kamus |

---

## 4. Arsitektur & Alur Data

```
[Admin  POST /admin/settings]
  input->post('wa_group_link', TRUE)          # XSS filter CI3
        │
        ├─ wa_group_link_normalize() ── null ─► $errors[] (pesan Indonesia)
        │                                      └─► redirect + flash error
        │                                          (TIDAK ada setting tersimpan — all-or-nothing)
        ├───────────────────────────── ''   ─► $final['wa_group_link'] = ''
        └───────────────────────────── url  ─► $final['wa_group_link'] = kanonik
        │
        └─► Admin_model::update_system_settings($final, $audit)
              1 TX: upsert semua key + 1 baris audit (before→after per key berubah)
              └─► system_settings.wa_group_link

[Member GET /help]
  Admin_model::get_setting('wa_group_link')   # null bila baris hilang
        │  (string)
        └─► wa_group_link_url()  ─► '' | 'https://chat.whatsapp.com/<token>'
                │
                └─► views/help/index.php
                        if ($wa_group_link !== '')  → render kartu Komunitas (href = html_escape)
                        else                        → nol markup (kartu tidak ada di DOM)
```

**Invariant alur:** tidak ada satu pun jalur di mana nilai mentah dari DB mencapai atribut `href` tanpa melewati helper; tidak ada jalur di mana nilai kosong menghasilkan elemen setengah jadi.

---

## 5. Database & Migrasi

### 5.1 Skema — tanpa DDL

Tidak ada `ALTER TABLE`. Hanya satu **baris** data pada tabel eksisting:

| Properti | Nilai |
|---|---|
| Tabel | `system_settings` |
| `key_name` | `wa_group_link` (13 karakter; `VARCHAR(50)` — aman) |
| `key_value` | `''` (TEXT NOT NULL; default kanonik = belum dikonfigurasi) |
| Unik | dijaga `uk_key_name` eksisting |
| Semantik | baris hilang ≡ `''` ≡ kartu komunitas tersembunyi |

Konsekuensi desain: `--apply` migrasi = **satu `INSERT IGNORE`** (operasi paling aman yang mungkin), tanpa fase ALTER/backfill sama sekali. Ini disengaja dan **wajib didokumentasikan di dalam script** supaya pembaca berikutnya tidak mengira ada langkah yang hilang.

### 5.2 Patch `database.sql` (setelah baris 344, di dalam blok `INSERT IGNORE` grup kontak)

```sql
-- M7 (plan/70): contact/support keys migrated from decommissioned `site_settings`.
('wa_number', '628000000000'),
('support_email', 'support@synapse.id'),
-- plan/105: tautan grup/komunitas WhatsApp resmi. '' = tidak dikonfigurasi →
-- kartu Komunitas disembunyikan di halaman Bantuan member (fail-safe).
('wa_group_link', ''),
```

### 5.3 Patch `database_seed.sql` (setelah baris 283, blok yang sama)

Tiga baris identik dengan komentar identik (kedua blok seed memang sengaja dicerminkan 1:1).

Kedua blok memakai `INSERT IGNORE` → re-run seed **tidak pernah** menimpa nilai live.

### 5.4 `scripts/migrate_105_wa_group_link.php` (baru)

Mengikuti gaya rumah `migrate_102_qris_deposits.php` / `migrate_104_gpu_product_images.php`.

**Header/konstanta:** docblock tujuan + flag + safety + exit code; `define('BASEPATH','cli-migrate-105-runner')`, `define('ENVIRONMENT','development')`, `error_reporting(E_ALL)`, `date_default_timezone_set('Asia/Jakarta')` (otoritas WIB M2), `mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT)`; kredensial via `load_db_config($ROOT.'/application/config/database.php')`; `localhost → 127.0.0.1` (TCP); `set_charset('utf8mb4')`; `SET time_zone='+07:00'`.

**Include helper:** `require_once $ROOT.'/application/helpers/wa_group_helper.php';` **setelah** `BASEPATH` didefinisikan → `--verify` memakai aturan kanonik yang persis sama dengan aplikasi (nol duplikasi regex, nol drift). Helper adalah fungsi murni tanpa dependensi CI, jadi aman di-include dari CLI.

**Fase:**

1. **Pre-flight** (exit `1` bila gagal): koneksi DB berhasil; tabel `system_settings` ada; unique index `uk_key_name` ada (via `information_schema.STATISTICS`, `NON_UNIQUE=0`); kolom `key_value` bertipe `text`.
2. **Inspeksi**: baris `wa_group_link` ada/tidak; cetak `key_value` (dipotong ±80 karakter) bila ada. Cetak juga jumlah baris `system_settings` sebagai konteks.
3. **Rencana / `--dry-run` (default)**: cetak rencana `INSERT IGNORE INTO system_settings (key_name,key_value) VALUES ('wa_group_link','')` + catatan eksplisit "tidak ada DDL/ALTER/backfill"; **tidak menulis apa pun**; exit `0`.
4. **`--apply`**: prepared statement `INSERT IGNORE system_settings (key_name, key_value) VALUES (?, ?)`; cetak `ditambahkan` vs `sudah ada (dibiarkan)`; lalu langsung menjalankan verifikasi. Tidak ada flag `--force` dan tidak pernah menimpa nilai live.
5. **`--verify` (read-only)**: baris ada; `wa_group_link_normalize($stored) !== null` (tamper → **exit 2**); **peringatan saja** (bukan gagal) bila `canonical !== stored` (saran normalisasi); `uk_key_name` masih unik; `key_value` tetap TEXT.
6. **`--help|-h`**: usage; exit `0`.

**Exit codes:** `0` = bersih/no-op, `1` = pre-flight gagal, `2` = apply/verify gagal.

**Usage:**
```
php scripts/migrate_105_wa_group_link.php [--dry-run|--apply|--verify] [--help]
```

### 5.5 Raw SQL siap-tempel (phpMyAdmin)

```sql
-- 1) Seed idempoten, TIDAK menimpa nilai live (aman dijalankan berulang):
INSERT INTO `system_settings` (`key_name`, `key_value`)
VALUES ('wa_group_link', '')
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;

-- 2) Verifikasi:
SELECT `key_name`, `key_value` FROM `system_settings` WHERE `key_name` = 'wa_group_link';

-- 3) Set manual (opsional; bentuk kanonik):
UPDATE `system_settings`
   SET `key_value` = 'https://chat.whatsapp.com/XXXXXXXXXXXXXXXXXXXXXX'
 WHERE `key_name` = 'wa_group_link';

-- 4) Kembali ke default fail-safe (opsional):
-- DELETE FROM `system_settings` WHERE `key_name` = 'wa_group_link';
```

> Catatan operator: jalur kanonik pengelolaan nilai adalah UI admin (`/admin/settings`) supaya kanonikalisasi + validasi + audit tetap berjalan. SQL manual hanya untuk keadaan darurat; nilai non-kanonik yang masih **valid** tetap akan tampil (helper menormalkan saat render) tetapi tidak akan tercatat di audit.

---

## 6. Helper Kanonik — `application/helpers/wa_group_helper.php` (baru)

Guard `defined('BASEPATH') OR exit('No direct script access allowed');`; semua fungsi dibungkus `if ( ! function_exists(...) )` (pola `api_helper` plan/76, `i18n_helper` plan/94, `product_image_helper` plan/104); fungsi murni (tanpa dependensi CI) agar bisa di-include CLI. Didaftarkan dengan menambahkan `'wa_group'` ke `$autoload['helper']` di `application/config/autoload.php:98`.

```php
if ( ! function_exists('wa_group_link_normalize'))
{
    /**
     * Kanonikalisasi + validasi tautan undangan grup WhatsApp (plan/105).
     *
     * @param  mixed $raw Nilai mentah (POST/DB/CLI).
     * @return string|null '' = kosong (tidak dikonfigurasi, SAH);
     *                     string kanonik = valid;
     *                     null = TIDAK valid.
     */
    function wa_group_link_normalize($raw)
    {
        $raw = is_string($raw) ? $raw : '';
        $raw = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $raw);
        $raw = trim($raw);
        if ($raw === '')        { return ''; }        // kosong = belum dikonfigurasi
        if (strlen($raw) > 512) { return null; }

        if (stripos($raw, 'chat.whatsapp.com/') === 0)        { $raw = 'https://' . $raw; }
        if (stripos($raw, 'http://chat.whatsapp.com/') === 0) { $raw = 'https://' . substr($raw, 7); }

        $p = parse_url($raw);
        if ( ! is_array($p) || empty($p['scheme']) || empty($p['host'])) { return null; }
        if (strtolower($p['scheme']) !== 'https') { return null; }
        if (isset($p['user']) || isset($p['pass']) || isset($p['port'])) { return null; }

        $host = strtolower($p['host']);
        if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }
        if ($host !== 'chat.whatsapp.com') { return null; }

        $path = isset($p['path']) ? trim($p['path'], '/') : '';
        $path = preg_replace('#^invite/#i', '', $path);
        if ( ! preg_match('/^[A-Za-z0-9_-]{6,64}$/', $path)) { return null; }

        return 'https://chat.whatsapp.com/' . $path;
    }
}

if ( ! function_exists('wa_group_link_url'))
{
    /**
     * Choke-point tampil: kanonik ATAU '' — tidak pernah null/nilai tak aman.
     */
    function wa_group_link_url($raw)
    {
        $c = wa_group_link_normalize($raw);
        return is_string($c) ? $c : '';
    }
}
```

Catatan higienitas audit: komentar berbahasa Indonesia di helper **aman** — scanner melewati baris komentar dan helper tidak pernah mengembalikan prosa user-facing (pesan error Indonesia tinggal di controller admin, yang di-exclude scanner).

### 6.1 Matriks kontrak normalizer (vektor uji eksak)

| Input | Output | Alasan |
|---|---|---|
| `null`, `''`, `'   '` | `''` | kosong = belum dikonfigurasi (SAH) |
| `'chat.whatsapp.com/AbCdEf123456'` | `https://chat.whatsapp.com/AbCdEf123456` | tanpa skema → https |
| `'http://chat.whatsapp.com/AbCdEf123456'` | `https://chat.whatsapp.com/AbCdEf123456` | upgrade ke https |
| `'HTTPS://CHAT.WHATSAPP.COM/AbCdEf123456?fbclid=x#f'` | `https://chat.whatsapp.com/AbCdEf123456` | host case-insensitive; query/fragment dibuang |
| `'https://www.chat.whatsapp.com/AbCdEf123456/'` | `https://chat.whatsapp.com/AbCdEf123456` | `www.` + trailing slash dinormalkan |
| `'https://chat.whatsapp.com/invite/AbCdEf123456'` | `https://chat.whatsapp.com/AbCdEf123456` | bentuk legacy `/invite/` |
| `'https://chat.whatsapp.com/AbCd-12_34'` | `https://chat.whatsapp.com/AbCd-12_34` | charset token `[A-Za-z0-9_-]` |
| `'chat.whatsapp.com/AbCdEf123456'` + ZWSP | kanonik | karakter format tak terlihat dibuang |
| `'https://chat.whatsapp.com/'` (tanpa token) | `null` | path kosong |
| `'https://chat.whatsapp.com/abc'` (5 char) | `null` | token < 6 |
| `'https://wa.me/628123456789'` | `null` | host bukan grup (`wa.me` = chat langsung) |
| `'https://evil.com/chat.whatsapp.com/ABC123'` | `null` | host tidak masuk allowlist |
| `'https://chat.whatsapp.com@evil.com/ABC123'` | `null` | userinfo phishing → host `evil.com` |
| `'javascript:alert(1)'`, `'data:text/html,x'` | `null` | skema bukan https / tanpa host |
| `'https://chat.whatsapp.com:8443/ABC123'` | `null` | port dilarang |
| > 512 karakter, atau token > 64 karakter | `null` | batas panjang |

---

## 7. Integrasi Admin (`/admin/settings`)

### 7.1 `application/controllers/Admin.php` — `settings()` cabang **POST** (± baris 375–386)

Blok `form_validation` eksisting **tidak disentuh** (agar perilaku `wa_number`/`support_email` identik). Sisipkan tepat setelahnya, sebelum `$contact` dibangun:

```php
            // plan/105: tautan grup WhatsApp — OPSIONAL ('' = tidak ditampilkan).
            // Di luar form_validation karena nilai kosong SAH; normalizer kanonik
            // ada di helper wa_group_helper (satu sumber bersama CLI migrasi).
            $wa_group_raw  = (string) $this->input->post('wa_group_link', TRUE);
            $wa_group_link = wa_group_link_normalize($wa_group_raw);
            if ($wa_group_link === null) {
                $errors[] = 'Link grup WhatsApp tidak valid. Gunakan tautan undangan resmi '
                          . '(contoh: https://chat.whatsapp.com/XXXXXXXXXXXXXXXXXXXXXX) '
                          . 'atau kosongkan bila belum ada.';
                $wa_group_link = '';
            }

            $contact = [
                'wa_number'     => $this->input->post('wa_number', TRUE),
                'support_email' => $this->input->post('support_email', TRUE),
                'wa_group_link' => $wa_group_link,
            ];
```

Karena `$final = array_merge($contact, $v['values'], $rv['values'])` (baris 427) dan guard all-or-nothing ada di baris 421–425, tautan tidak valid membatalkan penyimpanan **seluruh** setting. Pesan error tetap Indonesia (L1) dan tampil lewat flash eksisting: `'Validasi gagal: ' . implode(' ', $errors)`.

### 7.2 `Admin.php` — `settings()` cabang **GET** (baris 465–494)

```php
        $contact = $this->Admin_model->get_settings_map(['wa_number', 'support_email', 'wa_group_link']);
        // …
        $data = [
            // …
            'support_email'       => $contact['support_email'] ?? '',
            'wa_group_link'       => (string) ($contact['wa_group_link'] ?? ''),
            // …
        ];
```

### 7.3 `application/views/admin/settings.php`

**(a) Ganti header Card 1** (baris 46) menjadi `Kontak & Bantuan` (ikon `fa-headset` dipertahankan; komentar baris 43 diperbarui).

**(b) Sisipkan field di bawah `support_email`** (setelah baris 81, di dalam `space-y-4`):

```php
                    <div>
                        <label for="wa_group_link" class="t-label text-sm mb-1.5">
                            <i class="fas fa-users text-emerald-500 mr-1"></i>
                            Link Grup WhatsApp (Komunitas)
                        </label>
                        <input type="text"
                               id="wa_group_link"
                               name="wa_group_link"
                               value="<?= set_value('wa_group_link', $wa_group_link) ?>"
                               inputmode="url"
                               autocomplete="off"
                               spellcheck="false"
                               maxlength="512"
                               placeholder="https://chat.whatsapp.com/XXXXXXXXXXXXXXXXXXXXXX"
                               class="t-input w-full px-3 py-2.5 rounded-lg text-sm font-mono
                                      focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <p class="text-xs text-[var(--t-muted)] mt-1">
                            Tautan undangan grup/komunitas WhatsApp resmi. Kosongkan bila belum ada —
                            kartu Komunitas di halaman Bantuan member otomatis disembunyikan.
                        </p>
                    </div>
```

Alasan teknis atribut:
- `type="text"` (bukan `url`) agar tempelan tanpa skema diterima server-side, tidak diblokir validasi browser yang lebih ketat daripada normalizer.
- **tanpa `required`** (D3) — kosong = nilai sah.
- `maxlength="512"` mencerminkan batas server.
- `inputmode="url"` + `autocomplete="off"` + `spellcheck="false"` → UX paste tautan bersih tanpa koreksi ejaan.
- Field ikut `form_open('admin/settings')` eksisting → token CSRF sudah otomatis.

Perilaku yang diterima (dan didokumentasikan): setelah validasi gagal + redirect, field menampilkan **nilai tersimpan terakhir**, bukan input yang diketik (CI3 tidak menyimpan old input antar-redirect — `Form_validation.php:926-933`). Ini identik dengan perilaku `wa_number`/`support_email` saat ini; alasan kegagalan tetap terlihat lewat flash error.

### 7.4 Audit trail (tanpa kode baru)

`$before` dan `$changed` dihitung atas `array_keys($final)`, sehingga `wa_group_link` otomatis masuk payload audit `admin_update_settings`:

```json
{"keys":["wa_group_link"],"before":{"wa_group_link":""},"after":{"wa_group_link":"https://chat.whatsapp.com/AbCdEf123456"}}
```

- Ditulis di **TX yang sama** dengan upsert → rollback ikut membatalkan baris audit (M5/A1).
- Re-submit nilai identik → **tidak ada** baris audit (semantik "hanya key yang berubah").
- Tautan ini konten publik (bukan secret) → aman disimpan di `details`.

---

## 8. Integrasi Member (`/help`)

### 8.1 `application/controllers/Help.php`

```php
        $data = [
            'page_title'   => lang('help_page_title'),
            'wa_number'    => $this->Admin_model->get_setting('wa_number') ?: '628000000000',
            'support_email'=> $this->Admin_model->get_setting('support_email') ?: 'support@synapse.id',
            // plan/105: choke-point tampil — '' bila belum dikonfigurasi, tidak valid,
            // atau nilainya ditamper manual di DB (kartu komunitas tidak dirender).
            'wa_group_link'=> wa_group_link_url((string) $this->Admin_model->get_setting('wa_group_link')),
        ];
```

`(string)` wajib: `get_setting()` mengembalikan `null` saat baris hilang (PHP 8.x deprecation bila diteruskan mentah).

### 8.2 `application/views/help/index.php` — kartu baru antara baris 33 dan 35

```php
    <?php if ($wa_group_link !== ''): ?>
    <!-- plan/105: kartu Komunitas WhatsApp — seluruh blok disembunyikan bila tautan kosong -->
    <div class="relative overflow-hidden rounded-2xl p-5 shadow-sm border border-emerald-400/40
                bg-gradient-to-br from-emerald-500/10 via-emerald-400/5 to-teal-500/10
                dark:from-emerald-500/15 dark:via-slate-900 dark:to-teal-500/10">
        <span class="pointer-events-none absolute -right-6 -top-6 w-24 h-24 rounded-full bg-emerald-400/20 blur-2xl"></span>
        <div class="relative flex items-start gap-3">
            <span class="w-11 h-11 shrink-0 rounded-xl bg-emerald-500 text-white flex items-center justify-center shadow-lg shadow-emerald-500/30">
                <i class="fas fa-users text-lg"></i>
            </span>
            <div class="min-w-0">
                <h3 class="text-sm font-bold u-text"><?= lang('help_wa_group_title') ?></h3>
                <p class="text-xs u-text-2 mt-1 leading-relaxed"><?= lang('help_wa_group_desc') ?></p>
            </div>
        </div>
        <a href="<?= html_escape($wa_group_link) ?>"
           target="_blank"
           rel="noopener noreferrer"
           class="relative mt-4 flex items-center justify-center gap-2 w-full bg-emerald-500 hover:bg-emerald-600
                  active:bg-emerald-700 text-white text-sm font-bold py-3 px-4 rounded-xl transition-all
                  duration-200 active:scale-95 shadow-sm">
            <i class="fab fa-whatsapp text-lg"></i>
            <span><?= lang('help_wa_group_btn') ?></span>
        </a>
    </div>
    <?php endif; ?>
```

Alasan desain:
- **Aksen emerald/tech:** gradient emerald→teal + orb blur lembut; **tanpa animasi** (tidak ada kewajiban `prefers-reduced-motion`, tanpa biaya performa) dan **tanpa** `<style>`/`id`/aset baru.
- **Ikon komunitas:** `fas fa-users` (Font Awesome 6.5.1) + `fab fa-whatsapp` pada CTA.
- **Tema:** teks via token `u-text`/`u-text-2`, kartu via varian `dark:` (Tailwind `darkMode:'class'`); glow dan border tetap terbaca di tema terang maupun gelap.
- **Aksesibilitas:** CTA full-width berlabel teks dari kamus (tidak bergantung pada `aria-label` atau ikon saja).
- **Higienitas audit i18n:** setiap node teks memakai `lang()` pada baris yang sama (R2 bersih), komentar HTML berada di baris sendiri diawali `<!--` (di-skip scanner), tidak ada literal JS (R4 tidak berlaku).
- **Nol regresi:** baris 1–33 (header + kartu kontak) dan 35–176 (FAQ + skrip accordion) tidak disentuh; penyisipan murni aditif.

### 8.3 Logika conditional rendering (eksplisit)

| Kondisi `$wa_group_link` | DOM |
|---|---|
| `''` (baris hilang / kosong / invalid / > 512 / skema berbahaya) | **tidak ada** kartu, tidak ada `href`, tidak ada teks komunitas |
| `'https://chat.whatsapp.com/<token>'` | kartu lengkap + CTA `target="_blank" rel="noopener noreferrer"` |

Tidak ada cabang "kartu dengan tautan kosong", tidak ada placeholder "segera hadir" — degradasi anggun berarti **absen total**, bukan tampilan rusak.

---

## 9. Skema Kamus EN/ID (Strict Bilingual Parity — plan/103)

Disisipkan setelah `$lang['help_contact_email']` (**baris 576 pada kedua file**) agar urutan key tetap simetris posisional.

| Key | `application/language/english/app_lang.php` | `application/language/indonesian/app_lang.php` |
|---|---|---|
| `help_wa_group_title` | `Join the Synapse WhatsApp Community` | `Gabung Komunitas WhatsApp Synapse` |
| `help_wa_group_desc` | `Connect with the Synapse team and fellow members for network updates, GPU node announcements, and direct assistance.` | `Terhubung dengan tim Synapse dan member lain untuk update jaringan, pengumuman node GPU, dan bantuan langsung.` |
| `help_wa_group_btn` | `Join the Community` | `Gabung Komunitas` |

### 9.1 Analisis gate paritas (`scripts/audit_i18n_parity.php`)

| Gate | Status rancangan | Catatan |
|---|---|---|
| **P1** himpunan key EN ≡ ID | LOLOS | 591 + 3 = **594** di kedua file |
| **P3** tanpa `Rp`+digit di kamus | LOLOS | ketiga nilai tanpa nominal |
| **P5** nilai identik EN≡ID hanya untuk allowlist | LOLOS | ketiga nilai berbeda antar-idiom (tidak perlu menambah allowlist) |
| **P6** tanpa newline literal; higienitas atribut | LOLOS | teks polos, tanpa `<`/`>` dan tanpa apostrof (nol risiko escaping) |
| **P3b** prosa Indonesia di idiom EN | LOLOS | nilai EN bebas leksikon Indonesia |

`scripts/audit_i18n_hardcoded.php` juga tetap **0 temuan**: tidak ada prosa literal baru pada surface member (view/controller/helper), dan `views/admin/**` + `Admin.php`/`Admin_model.php` memang di luar cakupan (L1 — admin 100% Indonesia tanpa key i18n).

---

## 10. Invariant & Kepatuhan

- **L1 (admin 100% Indonesia):** label field, teks bantuan, dan pesan validasi baru berbahasa Indonesia; header Card 1 dikoreksi; tidak ada pemanggilan `i18n_apply()` pada jalur admin.
- **L2 / plan-103 (kemurnian member):** seluruh copy kartu lewat `lang()`; nol kalimat literal baru di `views/help/index.php` dan `Help.php`; kedua audit tetap exit 0.
- **L6 (netralitas uang/angka):** tidak ada nominal, tidak ada lokalalisasi angka (tidak relevan).
- **M9/P7 (envelope API):** tidak ada endpoint JSON/AJAX yang disentuh → envelope tidak berubah.
- **M4/POST-only + CSRF:** `/admin/settings` tetap POST-only dengan guard 404, field baru menumpang form ber-CSRF eksisting.
- **M5/A1 (audit atomik):** persist + audit dalam satu TX, hanya key yang berubah dicatat.
- **M7:** nol jalur tulis ke tabel selain `system_settings`.
- **L8 / anti open-redirect:** hanya host `chat.whatsapp.com` yang boleh dirender sebagai tautan keluar; tidak ada redirect server-side yang diperkenalkan.
- **Routes & framework:** `application/config/routes.php` tidak berubah; `system/` tidak disentuh.
- **Choke-point tunggal:** satu-satunya tempat aturan tautan hidup adalah `wa_group_helper.php`.

---

## 11. Edge Cases & Failure Modes

| # | Kasus | Perilaku yang dirancang |
|---|---|---|
| E1 | Baris key hilang (DB baru / setelah `DELETE`) | `get_setting()` → `null` → `''` → kartu tersembunyi; form admin tampil kosong |
| E2 | Admin mengosongkan lalu menyimpan | Sah; kartu hilang; audit mencatat before→`''` |
| E3 | Nilai ditamper (`javascript:…`, host lain, `data:` URI) | `wa_group_link_url()` → `''` → kartu absen total, tanpa PHP notice, tanpa href berbahaya |
| E4 | Nilai > 512 karakter | Ditolak admin; saat render → `''` → tersembunyi |
| E5 | Tempel tanpa skema (`chat.whatsapp.com/ABC…`) | Dikanonikalisasi ke `https://…` lalu disimpan |
| E6 | `http://`, `www.`, `/invite/`, `?query`, trailing slash | Dikanonikalisasi (D4); audit mencatat bentuk kanonik |
| E7 | Bentuk phishing `https://chat.whatsapp.com@evil.com/x` | `null` → ditolak admin; tersembunyi bila disuntik manual |
| E8 | Karakter tak terlihat (ZWSP/BOM) ikut ter-copy | Dibuang sebelum validasi |
| E9 | Tautan tidak valid dikirim bersama perubahan setting lain | **All-or-nothing**: tidak ada satu pun setting tersimpan; flash error Indonesia; field kembali ke nilai tersimpan |
| E10 | Nilai identik disimpan ulang | Tidak ada baris audit baru |
| E11 | Method selain GET/POST ke `/admin/settings` | Gate 404 eksisting tidak berubah |
| E12 | Member belum login mengakses `/help` | Guard `MY_Controller` eksisting (redirect `login`) tidak berubah |
| E13 | Helper lupa didaftarkan di autoload (risiko regresi) | Fatal `undefined function` di `/help` → dicegah oleh urutan P1 + diuji runtime N1 |
| E14 | Dua admin menyimpan bersamaan | Last-write-wins per key, tiap simpan di TX sendiri, tiap diff tercatat terpisah (perilaku eksisting) |
| E15 | Tautan menghadap grup yang penuh/dicabut (link tetap valid secara format) | Tidak terdeteksi sistem (di luar scope) — admin perlu memperbarui manual; kartu tetap tampil sesuai data |
| E16 | Nilai valid tapi non-kanonik dimasukkan via SQL manual | Tetap tampil kanonik (helper menormalkan saat render); `--verify` memberi **peringatan** (bukan gagal) |

---

## 12. Urutan Implementasi (Step-by-Step)

| Fase | Pekerjaan | File |
|---|---|---|
| **P0** | Dokumen blueprint ini (**deliverable**) | `plan/105_…_PLAN.md` (baru) |
| **P1** | Helper kanonik + pendaftaran autoload | `application/helpers/wa_group_helper.php` (baru), `application/config/autoload.php` |
| **P2** | DB: patch dua seed + tool migrasi CLI, lalu `--dry-run` → `--apply` → `--verify` | `database.sql`, `database_seed.sql`, `scripts/migrate_105_wa_group_link.php` (baru) |
| **P3** | Admin: validasi + persist + GET var + field UI + judul kartu | `application/controllers/Admin.php`, `application/views/admin/settings.php` |
| **P4** | Member: pass var + kartu komunitas | `application/controllers/Help.php`, `application/views/help/index.php` |
| **P5** | Kamus dwibahasa (+3 key × 2 idiom) | `application/language/english/app_lang.php`, `application/language/indonesian/app_lang.php` |
| **P6** | Gate statis: `php -l`, kedua audit, vektor uji normalizer, cek CLI/DB | — |
| **P7** | Acceptance runtime (§13.3) + sinkronisasi dokumen (`docs/2_ERD.md`, `docs/3_ROADMAP.md`, `AGENTS.md`) + `plan/105_…_SUMMARY.md` | dokumentasi |

**Urutan wajib:** P1 sebelum P3/P4 (callee harus ada); P2 sebelum acceptance admin (baris key harus ada, walau help/controller tetap aman bila belum ada karena D1/E1); P5 sebelum uji tampilan (copy dari kamus).

### 12.1 Inventaris file

| Aksi | File |
|---|---|
| **BARU** | `application/helpers/wa_group_helper.php` |
| **BARU** | `scripts/migrate_105_wa_group_link.php` |
| **BARU** | `plan/105_WHATSAPP_GROUP_LINK_SETTINGS_AND_HELP_INTEGRATION_PLAN.md` (deliverable P0) |
| **UBAH** | `application/config/autoload.php` (+`'wa_group'`) |
| **UBAH** | `application/controllers/Admin.php` (POST ±10 baris, GET 2 baris) |
| **UBAH** | `application/views/admin/settings.php` (field baru + judul kartu) |
| **UBAH** | `application/controllers/Help.php` (1 baris) |
| **UBAH** | `application/views/help/index.php` (±25 baris, aditif) |
| **UBAH** | `application/language/english/app_lang.php` (+3) |
| **UBAH** | `application/language/indonesian/app_lang.php` (+3) |
| **UBAH** | `database.sql`, `database_seed.sql` (+3 baris masing-masing) |
| **DOK** | `docs/2_ERD.md`, `docs/3_ROADMAP.md`, `AGENTS.md` (+ opsional `plan/105_…_SUMMARY.md`) |
| **TIDAK SENTUH** | `application/config/routes.php`, `application/models/Admin_model.php`, seluruh `system/`, view member lain |

---

## 13. Matriks Verifikasi

### 13.1 Statis

```bash
php -l application/helpers/wa_group_helper.php
php -l scripts/migrate_105_wa_group_link.php
php -l application/controllers/Admin.php
php -l application/controllers/Help.php
php -l application/views/admin/settings.php
php -l application/views/help/index.php
php -l application/language/english/app_lang.php
php -l application/language/indonesian/app_lang.php

php scripts/audit_i18n_parity.php      # EN 594 / ID 594, exit 0
php scripts/audit_i18n_hardcoded.php   # 0 temuan, exit 0

grep -n "help_wa_group_" application/language/*/app_lang.php   # 3 + 3
grep -n "wa_group" application/config/autoload.php application/controllers/Admin.php application/controllers/Help.php
grep -c "wa_group_link" database.sql database_seed.sql         # masing-masing ≥ 1
```

### 13.2 Vektor uji normalizer (tanpa bootstrap aplikasi)

```bash
php -r 'define("BASEPATH","cli"); require "application/helpers/wa_group_helper.php";
$cases = [
  [null,""], ["",""], ["   ",""],
  ["chat.whatsapp.com/AbCdEf123456","https://chat.whatsapp.com/AbCdEf123456"],
  ["http://chat.whatsapp.com/AbCdEf123456","https://chat.whatsapp.com/AbCdEf123456"],
  ["HTTPS://CHAT.WHATSAPP.COM/AbCdEf123456?fbclid=x#f","https://chat.whatsapp.com/AbCdEf123456"],
  ["https://www.chat.whatsapp.com/AbCdEf123456/","https://chat.whatsapp.com/AbCdEf123456"],
  ["https://chat.whatsapp.com/invite/AbCdEf123456","https://chat.whatsapp.com/AbCdEf123456"],
  ["https://chat.whatsapp.com/AbCd-12_34","https://chat.whatsapp.com/AbCd-12_34"],
  ["https://chat.whatsapp.com/",null], ["https://chat.whatsapp.com/abc",null],
  ["https://wa.me/628123456789",null], ["https://evil.com/chat.whatsapp.com/ABC123",null],
  ["https://chat.whatsapp.com@evil.com/ABC123",null],
  ["javascript:alert(1)",null], ["data:text/html,x",null],
  ["https://chat.whatsapp.com:8443/ABC123",null],
  ["https://chat.whatsapp.com/" . str_repeat("A",65),null],
];
$fail = 0;
foreach ($cases as [$in,$want]) { $got = wa_group_link_normalize($in);
  if ($got !== $want) { $fail++; printf("FAIL in=%s got=%s want=%s\n", var_export($in,true), var_export($got,true), var_export($want,true)); } }
echo $fail ? "VECTORS FAIL: $fail\n" : "VECTORS OK\n";'
```

Setiap ketidakcocokan = defect yang harus diperbaiki **sebelum** UI di-wire (P6 gate).

### 13.3 CLI & Database

```bash
php scripts/migrate_105_wa_group_link.php --dry-run   # exit 0; "BELUM ADA"; nol tulisan
php scripts/migrate_105_wa_group_link.php --apply     # exit 0; "ditambahkan"
php scripts/migrate_105_wa_group_link.php --apply     # exit 0; "sudah ada (dibiarkan)" → idempoten
php scripts/migrate_105_wa_group_link.php --verify    # exit 0
php scripts/migrate_105_wa_group_link.php --help      # usage; exit 0

# negatif – deteksi tamper (uji, lalu pulihkan):
#   UPDATE system_settings SET key_value='https://evil.com/x' WHERE key_name='wa_group_link';
#   php scripts/migrate_105_wa_group_link.php --verify   → exit 2
#   UPDATE system_settings SET key_value='' WHERE key_name='wa_group_link';

SELECT key_name, key_value FROM system_settings WHERE key_name = 'wa_group_link';
SHOW CREATE TABLE system_settings;   # identik baseline (tanpa DDL baru)
```

### 13.4 Runtime (browser) — butuh vhost aktif

> Environment sesi ini **tidak** menyajikan `synapse.test` (`curl` → `000`). Jalankan di host ber-vhost, atau `php -S 127.0.0.1:8080` dengan front controller CI3. Akun seed: member `081234567890` / `password`; admin `admin` / `password`.

| # | Skenario | Ekspektasi |
|---|---|---|
| N1 | Login member; `/help` saat nilai kosong | view-source **tanpa** `chat.whatsapp.com` dan **tanpa** teks komunitas; kartu kontak + FAQ utuh |
| N2 | Login admin; isi `chat.whatsapp.com/AbCdEf123456` (tanpa skema) → Simpan | flash sukses; DB berisi `https://chat.whatsapp.com/AbCdEf123456`; `/help` menampilkan kartu emerald; view-source memuat `target="_blank" rel="noopener noreferrer"` + href kanonik |
| N3 | Admin kosongkan field → Simpan | kartu hilang seketika dari `/help` |
| N4 | Admin isi `https://evil.com/abc` → Simpan | flash error Indonesia ("Link grup WhatsApp tidak valid…"); **tidak ada** setting yang berubah (cek satu field lain, mis. `wa_max_amount`); kartu tetap tersembunyi |
| N5 | Tamper DB: `key_value='javascript:alert(1)'` | `/help` tanpa kartu; tanpa PHP warning di `application/logs/`; tidak ada substring `javascript:` di HTML |
| N6 | Ganti bahasa EN ↔ ID | EN: `Join the Synapse WhatsApp Community` / `Join the Community`; ID: `Gabung Komunitas WhatsApp Synapse` / `Gabung Komunitas` |
| N7 | `SELECT action, details FROM system_audit_logs WHERE action='admin_update_settings' ORDER BY id DESC LIMIT 1` | `wa_group_link` muncul di `keys`/`before`/`after`; simpan ulang nilai sama → tidak ada baris baru |
| N8 | Tema terang/gelap, lebar 360 px & 480 px | CTA full-width, teks tidak terpotong, orb glow tidak menutupi teks, tanpa scroll horizontal |

### 13.5 Regresi

| # | Cek | Ekspektasi |
|---|---|---|
| R1 | Tombol kontak `/help` | `wa.me/<wa_number>?text=…` dan `mailto:…?subject=…` tidak berubah |
| R2 | Form finansial + rebate + QRIS di `/admin/settings` | Tetap tersimpan normal (verifikasi DB + baris audit) |
| R3 | Kedua audit i18n | exit 0; jumlah key 594/594 |
| R4 | `/admin/settings` GET/POST/404 gate | Tidak berubah; tidak ada route baru yang diperlukan |

---

## 14. Risiko, Asumsi & Follow-up

### 14.1 Asumsi

- **A1:** Undangan grup/komunitas WhatsApp selalu di bawah host `chat.whatsapp.com` (permukaan `wa.me` adalah chat langsung dan sudah dilayani `wa_number`). Bila kelak muncul bentuk lain, pelebaran allowlist adalah perubahan **satu tempat** di helper.
- **A2:** Hanya ada **satu** tautan komunitas (bukan per-bahasa, bukan multi-grup, bukan per-halaman).
- **A3:** Tautan ini konten publik non-secret → aman dicatat di `details` audit.
- **A4:** Nilai default kanonik adalah `''` (belum dikonfigurasi) — tidak ada tautan placeholder/wajib saat instalasi baru.

### 14.2 Risiko & mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Validasi terlalu ketat menolak varian tautan sah | Admin tidak bisa menyimpan | Charset token liberal (`[A-Za-z0-9_-]{6,64}`), toleransi `/invite/`, query/fragment, trailing slash; pesan error menyebut bentuk yang diharapkan; vektor uji §6.1 |
| Helper lupa didaftarkan di autoload | Fatal `undefined function` di `/help` | Fase P1 eksplisit + uji runtime N1 |
| Nilai ditamper manual di DB | Tautan berbahaya tampil | Re-validasi saat render (D2/D7) → tersembunyi; `--verify` mendeteksi (exit 2) |
| Old input tidak persist antar-redirect | Admin harus menempel ulang setelah error | Perilaku eksisting (identik `wa_number`/`support_email`); flash error menjelaskan sebab — didokumentasikan, bukan regresi |
| Drift dokumen (hitungan kamus 332 vs 591) | Kebingungan pembaca | Sinkronisasi doc di P7: `AGENTS.md` (bullet plan/94 → 591 terukur) + entri plan/105 (594); baris historis ROADMAP plan/94 tidak ditulis ulang |
| Header Card 1 diganti nama | Referensi dokumen lama `plan/70-71` menyebut "General & Support" | Dokumen plan bersifat arsip (tidak diubah); perubahan dicatat di plan/105 + `AGENTS.md` |

### 14.3 Follow-up (di luar scope plan ini)

- Menampilkan kartu komunitas di halaman member lain (dashboard/profile/wallet).
- QR code grup + pratinjau kartu di panel admin.
- Deteksi grup penuh/dicabut via WhatsApp API (butuh integrasi eksternal).
- Tautan komunitas berbeda per idiom bahasa (butuh 2 key + kebijakan konten).
- Uji otomatis normalizer sebagai test suite permanen (saat ini masih vektor `php -r` manual).

---

## 15. Definisi Selesai (Definition of Done)

1. `plan/105_…_PLAN.md` (dokumen ini) ditinjau & disetujui.
2. P1–P5 dieksekusi; `php -l` bersih untuk semua file tersentuh.
3. `audit_i18n_parity.php` (594/594) & `audit_i18n_hardcoded.php` (0 temuan) exit 0.
4. Migrasi CLI lulus `--dry-run` → `--apply` → `--apply` (no-op) → `--verify` (exit 0), dan deteksi tamper (exit 2) terbukti.
5. Matriks runtime N1–N8 + regresi R1–R4 lulus di host ber-vhost.
6. Audit `admin_update_settings` memuat `wa_group_link` hanya saat nilai berubah.
7. `docs/2_ERD.md`, `docs/3_ROADMAP.md`, `AGENTS.md` sinkron + `plan/105_…_SUMMARY.md` ditulis.
