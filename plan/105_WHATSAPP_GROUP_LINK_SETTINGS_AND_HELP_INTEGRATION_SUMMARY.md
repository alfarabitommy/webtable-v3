# Plan 105 — SUMMARY: Dynamic WhatsApp Group Link (Help & FAQ + Admin Settings)

**Status:** ✅ **COMPLETED & RUNTIME VERIFIED** — P1–P7 dieksekusi penuh.
**Plan:** `plan/105_WHATSAPP_GROUP_LINK_SETTINGS_AND_HELP_INTEGRATION_PLAN.md` (blueprint, disetujui).
**Tanggal eksekusi:** 2026-09-13 (WIB).
**Lingkungan:** PHP 8.3.6, MariaDB 12.3.2 (`db_webtable`), CodeIgniter 3, `php -S` + `DB_HOSTNAME=127.0.0.1` (vhost `synapse.test` tidak menyala di sandbox ini).

---

## 1. Hasil Ringkas (kriteria plan §1)

| # | Kriteria | Status | Bukti |
|---|---|---|---|
| S1 | Key `wa_group_link` di DB live + kedua seed, **tanpa DDL** | ✅ | `SELECT` OK; `database.sql` + `database_seed.sql` patched; `SHOW CREATE TABLE` tidak berubah |
| S2 | Migrasi CLI idempoten (`--dry-run`/`--apply`/`--verify`), tidak menimpa nilai live | ✅ | `--apply` ×2 → `ditambahkan` lalu `sudah ada (dibiarkan)`, exit 0; `--verify` exit 0 |
| S3 | Admin set/ubah/kosongkan; invalid ditolak all-or-nothing dengan pesan Indonesia | ✅ | §5 (flow 1–5) — invalid → flash error Indonesia, DB tidak berubah, setting lain tidak tersimpan |
| S4 | Audit `admin_update_settings` memuat `wa_group_link` hanya saat berubah | ✅ | #147/#149/#150 (ada); #148 (nilai identik) → `{"keys":[],"before":[],"after":[]}` |
| S5 | `/help` merender kartu **hanya bila** tautan kanonik; selain itu nol markup | ✅ | §6 matriks A–E (HTTP nyata) |
| S6 | CTA `target="_blank" rel="noopener noreferrer"` + href `html_escape()` | ✅ | grep view-source: `href="https://chat.whatsapp.com/AbCdEf123456"`, `rel="noopener noreferrer"` = 1 |
| S7 | 3 key kamus × 2 idiom, paritas 1:1 | ✅ | `audit_i18n_parity.php` → **594/594 OK**, exit 0 |
| S8 | Admin 100% Indonesia (L1); copy member via `lang()`; audit i18n bersih | ✅ | `audit_i18n_hardcoded.php` → **0 temuan**, exit 0 |
| S9 | Nol regresi form setting lain & tombol kontak `/help` | ✅ | §6 (`contact`/`faq` utuh), R2/R4, baseline DB 0 drift |

---

## 2. Inventaris Perubahan

| Aksi | File |
|---|---|
| **BARU** | `application/helpers/wa_group_helper.php` — choke-point tunggal validasi/kanonikalisasi |
| **BARU** | `scripts/migrate_105_wa_group_link.php` — CLI migrasi + verifikasi + deteksi tamper |
| **BARU** | `plan/105_WHATSAPP_GROUP_LINK_SETTINGS_AND_HELP_INTEGRATION_PLAN.md` (blueprint P0) |
| **BARU** | `plan/105_WHATSAPP_GROUP_LINK_SETTINGS_AND_HELP_INTEGRATION_SUMMARY.md` (dokumen ini) |
| UBAH | `application/config/autoload.php` (+`'wa_group'`) |
| UBAH | `application/controllers/Admin.php` (POST validasi + GET var) |
| UBAH | `application/views/admin/settings.php` (header "Kontak & Bantuan" + field + catatan bantuan) |
| UBAH | `application/controllers/Help.php` (pass `wa_group_link` terkanonikalisasi) |
| UBAH | `application/views/help/index.php` (kartu komunitas emerald, kondisional) |
| UBAH | `application/language/english/app_lang.php` (+3 key) |
| UBAH | `application/language/indonesian/app_lang.php` (+3 key) |
| UBAH | `database.sql`, `database_seed.sql` (+3 baris seed masing-masing) |
| DOK | `docs/2_ERD.md` (baris key), `docs/3_ROADMAP.md` (entri COMPLETED), `AGENTS.md` (bullet plan/105 + koreksi hitungan kamus usang) |
| **TIDAK DISENTUH** | `application/config/routes.php`, `application/models/Admin_model.php`, seluruh `system/`, view member lain |

Diff kode: **+137 / −37** baris (14 file berubah + 4 file baru). Dua file (`application/config/config.php`, `application/config/database.php`) sudah **kotor sebelum sesi ini** (perubahan env-driven pra-eksisting) dan tidak disentuh oleh plan/105.

---

## 3. Gate Statis (P6)

```
php -l  (9 file: helper, CLI, autoload, Admin.php, Help.php, 2 view, 2 kamus)  → No syntax errors (semua)
php scripts/audit_i18n_parity.php     → EN 594 / ID 594 · Paritas 1:1 OK · P3 0 · P5 0 · P6 0 · P3b 0 · exit 0
php scripts/audit_i18n_hardcoded.php  → 75 file dipindai · 0 temuan · exit 0
```

---

## 4. Bukti CLI & DB (P2)

| Perintah | Hasil |
|---|---|
| `--dry-run` (sebelum apply) | `key wa_group_link BELUM ADA`; rencana `INSERT IGNORE … ('wa_group_link','')`; catatan eksplisit "tidak ada DDL/ALTER/backfill"; exit 0, nol tulisan |
| `--apply` (1×) | `ditambahkan`; verifikasi: baris OK, nilai kanonik OK, `uk_key_name` OK; exit 0 |
| `--apply` (2×) | `sudah ada (dibiarkan)` (idempoten); exit 0 |
| `--verify` | baris OK · nilai kanonik OK · unique index OK · `key_value` TEXT; exit 0 |
| **Negatif tamper** `UPDATE key_value='https://evil.com/x'` lalu `--verify` | **exit 2** dengan pesan `nilai wa_group_link TIDAK valid menurut wa_group_link_normalize() (tamper?)` |
| Restore `key_value=''` lalu `--verify` | exit 0 |
| `SHOW CREATE TABLE system_settings` | identik baseline (tanpa DDL baru) — total 25 baris (24 baseline + `wa_group_link`) |

---

## 5. Bukti Admin (P3) — sesi admin nyata (`/control-panel` → `/admin/settings`)

Login admin asli (CSRF `synapse_csrf_token` + session file), form settings diposting utuh (semua field lain disertakan) supaya gate all-or-nothing benar-benar teruji.

| # | Aksi | Flash | DB `wa_group_link` | Audit |
|---|---|---|---|---|
| 1 | Set `chat.whatsapp.com/AbCdEf123456` (tanpa skema) | ✅ "Pengaturan berhasil disimpan dan langsung berlaku." | `'' → https://chat.whatsapp.com/AbCdEf123456` | #147 baru (`keys:[…]`, before/after) |
| 2 | Set `https://evil.com/abc` | ❌ "Validasi gagal: **Link grup WhatsApp tidak valid.** Gunakan tautan undangan resmi (contoh: https://chat.whatsapp.com/XXXX…)" | tetap kanonik (tidak berubah) | tidak ada baris baru |
| 3 | Set ulang nilai **identik** | ✅ sukses | tidak berubah | #148 `{"keys":[],"before":[],"after":[]}` (semantik "hanya saat berubah") |
| 4 | Set `https://chat.whatsapp.com/Zz99TtRr4455` | ✅ sukses | berubah ke kanonik baru | #149 before→after |
| 5 | Set `https://chat.whatsapp.com/abc` (token < 6) | ❌ pesan invalid yang sama | tidak berubah | tidak ada baris baru |
| 6 | Kosongkan (`''`) | ✅ sukses | `→ ''` | #150 before→after |
| 7 | Halaman GET `/admin/settings` | header **Kontak & Bantuan** = 1 · `id="wa_group_link"` = 1 · label = 1 · catatan bantuan = 1 · `value=""` | — | — |
| 8 | `wa_number` setelah semua uji | `628000000000` (tidak berubah) | — | — |

---

## 6. Bukti Member (P4) — `/help` over HTTP nyata, sesi member valid

Sesi member dibentuk lewat session file CI3 (id 32 char `[0-9a-v]`), `user_id=1`; halaman dirender penuh lewat `php -S` (bukan hanya unit test view).

| Skenario | HTTP | Kartu (`fa-users`+`chat.whatsapp.com`) | href | `target`/`rel` | Copy | Kontak lama | FAQ |
|---|---|---|---|---|---|---|---|
| A. `wa_group_link = ''` | 200 | **absen** (0) | — | — | — | utuh | 8 item |
| B. terisi (idiom default EN) | 200 | **ada** | `href="https://chat.whatsapp.com/AbCdEf123456"` | `_blank` + `noopener noreferrer` | EN: "Join the Synapse WhatsApp Community" / ">Join the Community<" | utuh | 8 item |
| C. terisi + `lang/switch/id` | 200 | **ada** | sama (kanonik) | sama | ID: "Gabung Komunitas WhatsApp Synapse" / ">Gabung Komunitas<" | utuh | 8 item |
| D. tamper `javascript:alert(1)` | 200 | **absen** | — | — | — | utuh | 8 item |
| E. tamper host asing `https://evil.com/chat.whatsapp.com/ABC123` | 200 | **absen** | — | — | — | utuh | 8 item |

Tambahan: **0** kemunculan `href="javascript:` di HTML; **0** PHP fatal/warning pada seluruh run; tombol kontak eksisting (`wa.me/628000000000` + `mailto:`) tetap utuh di semua skenario; accordion FAQ tetap 8 `faq-item`.

Render view juga diuji terpisah dengan kamus asli (harness) untuk 4 kombinasi {EN, ID} × {kosong, terisi}: **0 key kamus hilang** (`<<MISSING:…>>` = 0).

---

## 7. Vektor Uji Normalizer (20 kasus — semua PASS)

```
php -r 'define("BASEPATH","cli"); require "application/helpers/wa_group_helper.php"; …'
cases=20 VECTORS OK
url() invalid tamper => []      url() null => []      url() valid => [https://chat.whatsapp.com/AbCdEf123456]
```

Tercakup: `null`/`''`/spasi → `''`; tanpa skema → `https://`; `http://` → upgrade; host uppercase + query/fragment dibuang; `www.` + trailing slash; `/invite/` legacy; charset `-`/`_`; ZWSP; tanpa token; token 5 char; `wa.me`; host asing; userinfo phishing (`chat.whatsapp.com@evil.com`); `javascript:`; `data:`; port; token 65 char; raw > 512.

---

## 8. Catatan Database Live

- **Nilai akhir `wa_group_link` = `''`** (baseline bersih; kartu tersembunyi). Total key `system_settings` = **25**.
- **Baseline 24 key lain diverifikasi 0 drift** setelah seluruh pengujian (dibandingkan snapshot pra-eksekusi), termasuk `wa_number`, `wd_*`, `deposit_*`, `qris_*`, `rebate_*`.
- Baris audit **#147–#152** adalah jejak pengujian plan/105 (append-only, sengaja **tidak dihapus**): #147–#150 = set/invalid/identik/clear `wa_group_link`; #151–#152 = koreksi dua kolateral uji.
- **Kolateral uji & pemulihannya (transparansi):** harness Python yang saya pakai untuk memposting form secara otomatis (a) meng-collapse input bernama berulang `wd_operational_days[]` menjadi satu nilai → `1,2,3,4,5,6` sempat menjadi `6`, dan (b) melewatkan checkbox → `deposit_fee_enabled` & `rebate_enabled` sempat menjadi `0`. Ketiganya **dipulihkan lewat jalur admin asli** (POST form dengan semua field benar) sehingga tercatat audit #151/#152, dan diverifikasi kembali **0 drift**. Tidak ada artefak uji yang tersisa pada nilai setting.

---

## 9. Deviasi, Catatan Lingkungan & Temuan

1. **`type="text"` (bukan `url`) pada input admin** — sesuai blueprint D4: normalizer server menerima tempelan tanpa skema, sehingga validasi browser yang lebih ketat tidak boleh memblokir submit. `inputmode="url"` + `maxlength="512"` dipertahankan.
2. **Field tanpa `required`** — kosong adalah nilai sah kelas satu (D3).
3. **`php -S` memerlukan `DB_HOSTNAME=127.0.0.1`** — CI3 memakai `localhost` yang memetakan ke unix socket mysqli yang tidak ada di sandbox; tanpa override **semua** route 500 (termasuk `/login`, pra-eksisting, bukan regresi plan/105). Vhost `synapse.test` tidak menyala di environment ini.
4. **Verifikasi member memakai session file CI3** (id 32 char `[0-9a-v]`), bukan login CAPTCHA, karena CAPTCHA sengaja session-bound & single-use. Akun member seed (`081234567890`) di DB live **bukan** berpassword seed, jadi login kredensial tidak dipakai.
5. **QA visual manual masih tersisa** (di luar jangkauan otomatis): tampilan kartu di tema terang/gelap pada 360 px & 480 px, dan klik CTA yang benar-benar membuka aplikasi WhatsApp. Struktur markup, kontras token tema, dan atribut `target`/`rel` sudah terverifikasi.

---

## 10. Follow-up (di luar scope, tidak dieksekusi)

- Menampilkan kartu komunitas di halaman member lain (dashboard/profile/wallet).
- QR grup + pratinjau kartu di panel admin; deteksi grup penuh/dicabut via WhatsApp API.
- Tautan komunitas berbeda per idiom bahasa; jadikan vektor normalizer sebagai test suite permanen.
- Menyelaraskan entri ROADMAP plan/102–104 yang belum tercatat (hanya plan/105 yang ditambahkan pada plan ini — riwayat plan lain tidak ditulis ulang).
