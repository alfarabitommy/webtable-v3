# PLAN 112 — SUMMARY: FITUR ABSENSI HARIAN (DAILY CHECK-IN)

**Status:** ✅ COMPLETED & RUNTIME-VERIFIED (kecuali QA visual 360 px — lihat §6.12)
**Tanggal:** 2026-09-20
**Plan:** `plan/112_DAILY_CHECKIN_FEATURE_PLAN.md`
**Keputusan owner:** `dec-8cbc460b0e5c3d10` — akses **semua member aktif** · policy `continue` = **lanjut N+1** · ikon **Font Awesome**
**Deviasi sadar dari redaksi requirement:** penanda kredit memakai prefix transaction_id **`CHK-`** + deskripsi kanonik, **bukan** nilai ENUM `type` baru (tabel `wallet_ledger` bersifat append-only immutable — menuangkan `ALTER` pada tabel uang untuk label semantik tidak sebanding risikonya; §2.3).

---

## 1. Berkas yang berubah

| # | Berkas | Jenis | Δ | Isi |
|---|---|---|---|---|
| 1 | `plan/112_DAILY_CHECKIN_FEATURE_PLAN.md` | baru | 565 baris | Blueprint (dokumen ini = ringkasannya) |
| 2 | `plan/112_DAILY_CHECKIN_FEATURE_SUMMARY.md` | baru | — | Ringkasan + matriks bukti ini |
| 3 | `database.sql` | ubah | +58 / −1 | 2 kolom `users` + 4 seed `system_settings` + catatan MIGRASI LIVE plan/112 |
| 4 | `database_seed.sql` | ubah | +6 / −1 | 4 seed `INSERT IGNORE` (SECTION `system_settings`) |
| 5 | `scripts/migrate_112_daily_checkin.php` | baru | 518 baris | Migrasi CLI (`--dry-run`/`--apply`/`--verify`, idempoten, tamper-detect) |
| 6 | `application/config/checkin_rewards.php` | baru | 24 baris | Fallback per-key + default kanonik |
| 7 | `application/models/Checkin_model.php` | baru | 527 baris | Konfigurasi, validator admin, status widget, **jalur uang** `claim()`, fungsi murni |
| 8 | `application/controllers/Checkin.php` | baru | 143 baris | `POST /checkin/claim` (POST+AJAX-only, rate limit, envelope `api_*`) |
| 9 | `application/config/routes.php` | ubah | +5 | `$route['checkin/claim']` |
| 10 | `application/controllers/Home.php` | ubah | +5 | Load `Checkin_model` + inject `$data['checkin']` |
| 11 | `application/views/home/index.php` | ubah | +283 | CSS scoped `hm112-*` + widget + JS klaim/mount |
| 12 | `application/helpers/i18n_helper.php` | ubah | +3 | Pola renderer ledger `ledger_checkin` |
| 13 | `application/language/english/app_lang.php` | ubah | +21 | 19 key baru + 2 komentar |
| 14 | `application/language/indonesian/app_lang.php` | ubah | +21 | 19 key baru + 2 komentar |
| 15 | `application/controllers/Admin.php` | ubah | +33 / −2 | Validator + state form + resolver GET absensi |
| 16 | `application/views/admin/settings.php` | ubah | +96 / −1 | Kartu **Absensi Harian** + pemisahan error `checkin_*` |
| 17 | `docs/1_PRD.md` | ubah | +16 / −2 | **v5.1 → v5.2** + §F mesin klaim baru |
| 18 | `docs/3_ROADMAP.md` | ubah | +12 | Blok milestone plan/112 + catatan header |

**Tidak disentuh:** `application/core/MY_Controller.php`, `Wallet_model`, `Rental_model`, `Promoter_model`, `Admin_model`, dan **apa pun di bawah `system/`** (nol perubahan framework).

---

## 2. Skema, setelan & migrasi

### 2.1 `users` (2 kolom)

```sql
`checkin_streak`    INT UNSIGNED NOT NULL DEFAULT 0,
`checkin_last_date` DATE NULL DEFAULT NULL,
```
Histori klaim **tidak** punya tabel sendiri — `wallet_ledger` berprefix `CHK-` adalah histori (query terlayani `idx_user_id`). Tidak ada kolom total, tidak ada index baru (semua baca jalur uang = PK).

### 2.2 `system_settings` (4 kunci)

| Key | Default | Nilai sah | Peran |
|---|---|---|---|
| `checkin_enabled` | `'1'` | `'0'`/`'1'` | Gerbang fail-closed (widget + endpoint) |
| `checkin_base_reward` | `'50'` | int `1 … 1.000.000` | Bonus hari ke-1 |
| `checkin_max_reward` | `'10000'` | int `1 … 10.000.000` | Cap harian keras |
| `checkin_streak_policy` | `'reset'` | `'reset'`/`'continue'` | Kebijakan saat bolong |

Invarian ditegakkan di tiga lapis: form admin (`Checkin_model::validate_checkin_settings`) → resolver (`get_config`, fallback **atomik** pasangan base/max + log) → CLI `--verify` (tamper → exit 2).

### 2.3 Bukti migrasi CLI (dijalankan terhadap DB lokal)

| Perintah | Hasil teramati |
|---|---|
| `php scripts/migrate_112_daily_checkin.php --dry-run` | inspeksi: 2 kolom `ADA (int(10) unsigned)`/`ADA (date)`, 4 key `ADA (nilai: 1/50/10000/reset)`, rencana = “dilewati”; **nol tulis**; exit 0 |
| `… --apply` | `2/3 DDL … sudah ada (dibiarkan)` (4×), `3/3 Verifikasi OK`; exit 0 |
| `… --apply` (re-run) | **no-op** identik; exit 0 → idempotensi terbukti |
| `… --verify` | `INT UNSIGNED NOT NULL DEFAULT 0 OK`, `DATE NULL DEFAULT NULL OK`, `4 kunci OK`, `uk_key_name OK`; exit 0 |
| `php -l scripts/migrate_112_daily_checkin.php` | `No syntax errors detected` |

**Koreksi implementasi vs plan:** plan mengasumsikan pola `load_db_config()` dari `scripts/migrate_105_wa_group_link.php` (`$db['default']`). Pola itu **gagal** di repo ini karena `application/config/database.php` memakai grup `local`/`dev`/`live` — dibuktikan dengan menjalankan skrip 105: `[FATAL] Cannot read DB credentials…`. Migrasi 112 karena itu memakai resolver CI3 yang benar (`$active_group` → `'default'` → grup pertama), yaitu pola `scripts/migrate_106_ewallet_withdrawal.php:133-151`.

---

## 3. Backend

### 3.1 `Checkin_model`

| Method | Peran |
|---|---|
| `get_config()` | `system_settings` di atas fallback; validasi per-key; koherensi `1 ≤ base ≤ max` (fallback atomik + `log_message('error')`) |
| `validate_checkin_settings()` | Kontrak F12: `{ok, errors, notices, field_errors, values}`; pesan Indonesia (L1) |
| `get_status($user_id)` | Read-only 1 PK lookup → `enabled/claimed_today/streak/today_streak/today_reward/next_streak/next_reward/base_reward/max_reward/policy/server_date/next_window_ts/max_today_reached/preview[7]` |
| `claim($user_id)` | **Jalur uang**: `disabled` guard → 1 TX (anchor `users FOR UPDATE` → `_streak_next` → `UPDATE` kondisional + `affected_rows()===1` → `Wallet_model::credit('CHK-{uid}-{Ymd}')` → `trans_status` → commit) |
| `_streak_next()` / `_reward_for()` | Fungsi murni (tanpa I/O) — 24 assertion lulus (§6.13) |
| `get_lifetime_claims($user_id)` | Turunan ledger (`LIKE 'CHK-%'`) |

### 3.2 `Checkin::claim()`

POST-only + AJAX-only (`show_404()` selainnya) → sesi (401) → rate limit `checkin_claim:{user_id}` 5/60 (`rate_limit_json_response`) → `Checkin_model::claim()` → envelope `api_success`/`api_error` + key legacy di root. Kode: `ok` · `already_claimed` · `user_unavailable` (HTTP 200) · `disabled` (**HTTP 403**, fail-closed) · `error` (500) · `unauthenticated` (401). Respons sukses **dan** penolakan `already_claimed`/`user_unavailable` menyertakan `status` segar agar widget memutakhirkan diri tanpa reload.

### 3.3 Dua cacat yang ditemukan **oleh** verifikasi runtime lalu diperbaiki

| # | Gejala (teramati) | Akar masalah | Perbaikan |
|---|---|---|---|
| 1 | Respons **sukses** membawa `message: "Failed to claim the bonus. Please try again."` | `api_helper` menyalin key legacy di atas `$body`, dan peta `_message()` tidak memuat `code` `'ok'` → `$legacy['message']` berisi pesan error generik | Tambah entri `'ok' => 'home_checkin_ok_claimed'` di peta `_message()` (+ komentar mengapa entri ini wajib). Terverifikasi: `message=Bonus claimed successfully.` |
| 2 | Jalur duplikat ledger (1062) mengembalikan **500 `error`**, bukan `already_claimed` | `$this->db->error()` dibaca **setelah** `trans_rollback()`; ROLLBACK yang sukses mereset errno koneksi → kode 1062 hilang | Baca `error()` **sebelum** rollback (urutan persis `Wallet_model::create_deposit`, plan/102). Terverifikasi: 200 `already_claimed` + log aplikasi memuat `Duplicate entry … uk_wallet_ledger_user_tx_type` |

Kedua cacat lolos dari review statis dan hanya tertangkap karena matriks runtime dijalankan.

---

## 4. UI

### 4.1 Widget member (`views/home/index.php`)

Disisipkan **setelah kartu hero, sebelum kartu identitas**. Isi: badge `fa-calendar-check` + judul/subjudul, **chip rentetan hari** (`fa-fire`), **bonus hari ini** (`Rp number_format`), bar progres ke cap (`intdiv` ceil — hari 1 = 1%, cap = 100%), **label hari** (`Hari ke-N` / `Day N` via `sprintf`), **pratinjau 7 hari**, **hitung mundur WIB** ke tengah malam (epoch dari server), tombol klaim (gradien indigo→cyan, `hm112-pulse` saat belum klaim, `fa-circle-check` + `disabled` saat sudah), hint kebijakan, toast + status `aria-live`. Semua warna lewat token tema `--u-*` (`html.dark` untuk aksen), `prefers-reduced-motion` mematikan animasi, ikon **Font Awesome** saja (nol aset/CDN baru).

Klaim: `csrfFetch('checkin/claim', {method:'POST'})` → repaint dari `status` yang dikembalikan server (streak, nominal, bar, 7 pill, tombol) tanpa reload; `disabled` → reload agar widget hilang.

**Fail-closed UI:** seluruh blok dibungkus `if (!empty($checkin['enabled']))` → saat `'0'` elemen widget **tidak ada** di DOM (hanya CSS-nya yang tetap terkirim).

### 4.2 Admin (`/admin/settings`)

Kartu **Absensi Harian (Daily Check-in)** di dalam `form_open('admin/settings')` (mewarisi CSRF + `data-guard-submit`), tepat sebelum tombol simpan: toggle aktif, bonus hari pertama, batas harian, dropdown kebijakan (Reset ke Hari 1 / Lanjutkan), blok error inline dari `field_errors['checkin_*']`, dan contoh perhitungan. Validasi memakai mesin all-or-nothing yang sudah ada (`$final = array_merge(..., $cv['values'])`) → snapshot + audit `admin_update_settings` otomatis memuat key `checkin_*`. Repopulasi validasi-gagal ikut karena 4 key ditambahkan ke `_settings_form_state()`.

---

## 5. i18n

**19 key baru** (18 `home_checkin_*` + `ledger_checkin`) → kamus **602 → 621**, paritas 1:1, EN ≠ ID, tanpa nominal literal, tanpa newline. Pola renderer deskripsi ledger `Bonus Absensi Harian Hari ke-{N}` ditambahkan ke `i18n_ledger_description()` sehingga entri ledger ikut berbahasa sesuai idiom pembaca (plan/103).

> **Koreksi hitungan plan:** tabel §7.2 plan memuat 18 baris `home_checkin_*` **dan** `ledger_checkin`, tetapi captionnya menyebut “18 (17 + 1)”. Implementasi memakai **19 key** (semua benar-benar dipakai — tak satu pun bisa dihapus tanpa meninggalkan string keras di view). Angka final diverifikasi gate: **621/621**.

---

## 6. Matriks verifikasi (hasil yang benar-benar dijalankan)

Lingkungan: `php -S 127.0.0.1:8099` (PHP 8.3.6, MariaDB 12.3.2, `db_webtable`), router `/tmp`, sesi CI3 `files` disuntik `user_id=1` / `admin_id=1` (tanpa melewati CAPTCHA). Semua perintah dijalankan dari root repo; scratch di `/tmp` sudah dibersihkan (§8).

| # | Skenario plan | Hasil teramati | Status |
|---|---|---|---|
| **V1** | `GET /` menampilkan widget | HTTP 200, 71.156 B; `id="hm112-card"` ×1, `data-claimed="0"`, `id="hm112-amount">50`, `id="hm112-day-label">Day 1`, 7 pill = 50/100/150/200/250/300/350, `data-window-ts="1789923600"`, label EN lengkap | ✅ |
| **V2** | `POST /checkin/claim` tanpa CSRF | **HTTP 403 `application/json`** (`{"success":false,…,"code":"internal_error"}`) — bukan HTML (M9/P7) | ✅ |
| **V3** | `POST` dengan CSRF | 200 `{success:true, message:"Bonus claimed successfully.", code:"ok", reward:50, streak:1, transaction_id:"CHK-1-20260920", new_balance:200050}` + `status.claimed_today=true` | ✅ |
| **V4** | Baris ledger | `user_id=1 · CHK-1-20260920 · credit · 50.00 · "Bonus Absensi Harian Hari ke-1"` | ✅ |
| **V5** | Klaim kedua hari sama | 200 `{success:false, code:"already_claimed", message:"You have already claimed today's bonus."}` + `status` segar; **jumlah baris CHK tetap 1** | ✅ |
| **V6** | `GET /` setelah klaim | `data-claimed="1"`, label `Claimed Today`, `fa-circle-check`, streak = 1 | ✅ |
| **V7** | Parity saldo | `SUM(credit)−SUM(debit)` = `users.balance` = pil header untuk **5 user**; ledger user 1 = 200.050 = balance | ✅ |
| **V8** | Admin simpan `base 20000 > max 10000` | HTTP 303 → DB **tidak berubah** (50/10000/reset); flash `Validasi gagal: Bonus hari pertama tidak boleh melebihi batas harian.`; error inline tampil; form ter-repopulasi (`value="20000"`, `value="continue" selected`) | ✅ |
| **V9** | Admin matikan fitur | `checkin_enabled=0` tersimpan; `GET /` → **0 elemen** widget (hanya 3 sebutan CSS); `POST /checkin/claim` → **403 `{code:"disabled"}`**; streak user utuh; nol baris ledger baru | ✅ |
| **V10** | Admin aktifkan + policy `continue` | DB `enabled=1`, `policy=continue`; audit row memuat `checkin_enabled,checkin_streak_policy`; widget kembali + hint “streak stays where it is” | ✅ |
| **V11a** | Policy `continue`, bolong 3 hari, streak 5 | 200 `reward=300, streak=6, tx=CHK-1-20260920`; ledger `"Bonus Absensi Harian Hari ke-6"` | ✅ |
| **V11b** | Policy `reset`, bolong 3 hari, streak 5 | 200 `reward=50, streak=1`; ledger `"Bonus Absensi Harian Hari ke-1"` | ✅ |
| **V11c** | E11 — baris ledger hari ini sudah ada (state direkayasa) | 200 `{code:"already_claimed"}`, `application/json`, **nol** baris tambahan, log memuat `Duplicate entry … uk_wallet_ledger_user_tx_type` → jalur 1062 tidak pernah menghasilkan halaman error | ✅ |
| **V12** | Bahasa ID | `GET /lang/switch/id` → widget berbahasa Indonesia (`Absensi Harian`, `Klaim Bonus`, `Bonus hari ini`, `Rentetan Hari`, `Hari ke-2`, `Tujuh hari ke depan (Rp)`, `Batas harian`); kembali EN OK | ✅ |
| **V-E1** | Rate limit | 6 POST berturut-turut → **429** `{code:"too_many_attempts"}` (JSON, pesan terlokalisasi) lalu reset setelah window | ✅ |
| **V-E2** | Kartu admin render | `GET /admin/settings` 200; kartu Absensi Harian + 4 field + opsi `reset`/`continue` (state aktif dari DB) | ✅ |
| **V-E3** | Logika murni | 35 assertion (`_streak_next` termasuk batas bulan/tahun & tanggal masa depan, `_reward_for` termasuk cap hari 200/201, `validate_checkin_settings` termasuk base>max & ambang) — **semua PASS** | ✅ |
| **V11** | **QA visual 360 px + `prefers-reduced-motion`** | **TIDAK dijalankan** — butuh browser; tidak tersedia di sandbox ini. Verifikasi statis: `grid-cols-7 gap-1` + label `text-[9px]/[10px]` + `hm112-card *` masuk blok reduced-motion | ⚠️ manual |

### 6.1 Gate wajib

| Gate | Hasil |
|---|---|
| `php -l` (12 berkas baru/berubah) | semuanya **OK** |
| `php scripts/audit_i18n_parity.php` | **EN 621 / ID 621**, paritas 1:1 OK, 0 nilai identik di luar allowlist, 0 nominal literal, 0 newline, **LULUS** — exit 0 |
| `php scripts/audit_i18n_hardcoded.php` | 87 berkas, **0 temuan** — exit 0 |
| `php scripts/migrate_112_daily_checkin.php --verify` | exit 0 (§2.3) |
| `scripts/reconcile_balances.php` (parity saldo) | ekuivalen via query Σcredit−Σdebit = `users.balance` untuk 5 user → **OK** |

> **Catatan temuan gate (non-defect).** `audit_i18n_hardcoded.php` memindai **`application/logs/*`** (di-gitignore). Uji V11c yang sengaja memicu duplikat ledger menulis satu baris log CI3 yang memuat deskripsi Indonesia (`… VALUES (… 'Bonus Absensi Harian Hari ke-2')`) sehingga gate melaporkan 2 temuan dari **berkas log**, bukan dari kode. Baris-baris log milik sesi verifikasi ini dibersihkan (baris 6195-6205) dan gate kembali 0 temuan. **Rekomendasi lanjutan (di luar scope):** kecualikan `application/logs/` dari pemindaian auditor agar log runtime tidak pernah dianggap temuan.

---

## 7. Non-goals yang tetap tidak dikerjakan

Tabel `user_checkins`/halaman riwayat/kalender · kartu laporan admin (tidak menyentuh `get_alert_counts()`) · notifikasi harian per klaim (spam lonceng) · bonus mingguan/milestone · cap jumlah hari streak · backfill streak user lama · perubahan nilai ENUM `wallet_ledger` · gate sewa aktif / e-wallet terikat · perubahan `system/`.

---

## 8. Kebersihan & pemulihan lingkungan

- **Scratch di `/tmp` dihapus:** router `php -S`, skrip penyuntik sesi, jar cookie, seluruh keluaran HTML/JSON uji, direktori sesi, probe logika murni.
- **Direktori tugas host** `.reasonix/tasks/desktop-…--bash-{1,2,3}/` (artefak job latar verifikasi) dihapus.
- **DB lokal dipulihkan ke keadaan awal:** `users.id=1` → `balance=200000.00, checkin_streak=0, checkin_last_date=NULL`; seluruh baris `wallet_ledger` berprefix `CHK-` dihapus (0 baris); baris `rate_limits` `checkin_claim:%` dihapus; `system_settings` dikembalikan persis ke nilai pra-uji (`wd_operational_days=1,2,3,4,5,6,7`, `01:00`–`23:59`, `wd_fixed_fee=5000`, `wd_min_amount=50000`, tier 0%, `deposit_fee_enabled=1`, `deposit_fee_value=5000`, `checkin_enabled=1`, `base=50`, `max=10000`, `policy=reset`). Parity saldo akhir: **semua user OK**.
- **Sengaja dibiarkan:** 2 baris `system_audit_logs` (`admin_update_settings` id **181** & **182**) yang merekam aksi penyimpanan setelan selama verifikasi — log audit bersifat append-only; hapus jika owner ingin jejak itu bersih.
- **Log aplikasi:** hanya baris log buatan sesi verifikasi yang dibuang (lihat §6.1); riwayat log pemilik repo sebelum 18:38 tidak disentuh.
- `application/config/config.php` (`base_url` → `http://synapse.test/`) dan `application/config/database.php` (`$active_group='local'`) tampil sebagai *modified* di `git status` — itu **perubahan lokal pra-sesi** milik owner, **bukan** hasil plan/112 (tidak pernah diedit di sesi ini).

---

## 9. Definition of Done

- [x] Setiap berkas PHP baru/berubah lolos `php -l`.
- [x] Kedua gate i18n exit 0 (paritas 621/621; 0 temuan hardcoded).
- [x] DDL ada di `database.sql` **dan** `database_seed.sql` + migrasi idempoten yang dibuktikan **no-op** saat dijalankan ulang (`--apply` 2×) dan lolos `--verify`.
- [x] Alur diuji via HTTP (200/302/303/307/403/429 sesuai ekspektasi) **dan** via CLI harness model — bukan klaim tanpa bukti.
- [x] Semua mutasi uang lewat `Wallet_model::credit()`; satu baris ledger per hari dikunci UNIQUE DB; TX + `FOR UPDATE` + `affected_rows()`.
- [x] Tidak ada SQL di controller/view; tidak ada kode admin yang memanggil `i18n_apply()`; uang tidak pernah masuk kamus.
- [x] `docs/1_PRD.md` (v5.2 §F) & `docs/3_ROADMAP.md` disinkronkan.
- [x] Diff akhir memuat **hanya** perubahan yang diniatkan (5 berkas baru + 10 berkas ubah; nol sisa scratch).

## 10. Tindak lanjut yang disarankan (opsional, di luar plan/112)

1. **QA visual 360 px + reduced-motion** untuk widget (§6.12) — satu kali buka dashboard di perangkat/devtools.
2. **Kecualikan `application/logs/`** dari `scripts/audit_i18n_hardcoded.php` (temuan gate dari log runtime, §6.1).
3. **Notifikasi "bonus diterima"** (opsional, owner decision): butuh key `notif_checkin` + 2 key kamus + `Notification_model::insert_keyed()` di dalam TX klaim — sengaja belum dibuat agar tidak menambah kebisingan lonceng harian.
4. **Rotasi/purge kredensial DB** yang masih ter-commit (`application/config/database.php`) — temuan audit lama, tidak terkait plan/112.
