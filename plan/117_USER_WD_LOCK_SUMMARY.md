# PLAN 117 — SUMMARY: KUNCI PENARIKAN PER-USER (PER-USER WITHDRAWAL LOCK)

- **Plan:** `plan/117_USER_WD_LOCK_PLAN.md`
- **Keputusan owner:** `dec-1ebc037f6f2688b7` (D1 pending dibiarkan · D3 notifikasi
  dwibahasa · D5 modal Tailwind bawaan repo — tanpa dependensi CDN baru)
- **Status:** SELESAI — seluruh E1–E16 dijalankan; verifikasi runtime **LULUS**
- **Perubahan:** 13 file termodifikasi + 3 file baru (kode/DDL/dokumen),
  **nol** perubahan `system/`, **nol** route baru, **nol** tabel baru
  (inventory kanonik tetap 14), **nol** baris ledger baru.

---

## 1. Ringkasan Perubahan

Admin kini dapat **memblokir pengajuan penarikan satu user** (mitigasi fraud)
tanpa mem-ban akunnya: user tetap bisa login, menyewa, klaim ROI & absensi —
hanya **pengajuan penarikan BARU** yang ditolak. Penarikan `pending` yang sudah
ada dibiarkan dan tetap diproses admin (D1) — tidak ada clawback/refund.

| # | File | Perubahan |
|---|---|---|
| 1 | `database.sql` | 2 kolom di `CREATE TABLE users` (`is_wd_locked`, `wd_lock_reason`) + blok `-- Plan 117 — MIGRASI LIVE` (referensi SQL, verifikasi, rollback manual) |
| 2 | `scripts/migrate_117_user_wd_lock.php` **(baru)** | 6 fase (pre-flight → inspect → DDL → normalize → guard → verify), idempoten via `information_schema`, `--dry-run`/`--apply`/`--verify`, exit 0/1/2, loader kredensial `$active_group → default → grup pertama` |
| 3 | `application/models/Wallet_model.php` | `get_wd_lock_state()` + `is_withdrawal_locked()`; **gate OTORITATIF** langkah `1a` di dalam TX `create_withdrawal()` (tepat setelah anchor `users FOR UPDATE` + saldo segar); renumber komentar `1a→1b/c/d`; docblock `code` += `wd_locked` |
| 4 | `application/models/Admin_model.php` | `set_wd_lock()` (**satu-satunya penulis**); `get_users()` += `u.is_wd_locked, u.wd_lock_reason` |
| 5 | `application/controllers/Wallet.php` | `WD_ERR_KEYS['wd_locked'] => 'wd_err_locked'`; **Gatekeeper 0** di `withdraw()` (baris ~357) & `process_withdraw()` (sebelum rate limit); `wd_locked` di data `index()` + data `withdraw()` (state defensif) |
| 6 | `application/controllers/Admin.php` | `Admin::toggle_wd_lock($id)` — AJAX POST-only (405), intent eksplisit `lock=1|0`, alasan wajib ≤255 (422 `reason_required`/`reason_too_long`), 404 `user_not_found`, TX atomik + audit `admin_toggle_wd_lock`, notifikasi post-commit, envelope `api_helper` + legacy root |
| 7 | `application/views/admin/templates/wd_lock_modal.php` **(baru)** | Modal Tailwind (pola `#createUserModal`) + `openWdLockModal()` / `submitWdLock()` / `unlockWdLock()` via `csrfFetch` → reload saat sukses |
| 8 | `application/views/admin/users.php` | Badge `WD LOCK` (alasan sebagai `title`) + tombol `Kunci WD` / `Buka WD Lock` + include modal; `colspan="9"` tetap |
| 9 | `application/views/admin/user_detail.php` | Badge hero `WD LOCK` + kartu **Penarikan (Withdrawal Lock)** (status, alasan, catatan D1) + include modal |
| 10 | `application/language/english/app_lang.php` | +6 key |
| 11 | `application/language/indonesian/app_lang.php` | +6 key (1:1) |
| 12 | `application/views/wallet/index.php` | Cabang `$wd_locked` **paling awal** (tombol `disabled` + `fa-lock` + label `wallet_wd_locked_btn`) + banner notice `wd_err_locked` |
| 13 | `application/views/wallet/withdraw.php` | State defensif: notice `#wdLockedNotice`, atribut `disabled`, `WD_CONFIG.locked`, early-return + backstop submit di JS |
| 14 | `docs/2_ERD.md` | Kolom `users` (2 butir) + subseksi invarian **W1–W5** |
| 15 | `docs/3_ROADMAP.md` | Milestone *Kunci Penarikan Per-User (Withdrawal Lock)* |
| 16 | `plan/117_USER_WD_LOCK_PLAN.md` **(baru)** | Blueprint yang disetujui |

**Kamus:** 633 → **639/639** key (`grep -c '^\$lang\['` pada kedua file).
Key baru: `wallet_wd_locked_btn`, `wd_err_locked`, `notif_wd_locked_title`,
`notif_wd_locked_body`, `notif_wd_unlocked_title`, `notif_wd_unlocked_body`.
Copy admin (modal, flash, pesan JSON) 100% Indonesia dan **tidak** melewati
kamus/`i18n_apply()` (L1). Tidak ada nominal di kamus (L6).

---

## 2. DDL & Migrasi

```sql
ALTER TABLE `users`
  ADD COLUMN `is_wd_locked`   TINYINT(1)   NOT NULL DEFAULT 0     AFTER `checkin_last_date`,
  ADD COLUMN `wd_lock_reason` VARCHAR(255) NULL     DEFAULT NULL  AFTER `is_wd_locked`;
```

- Additive & **tanpa backfill** (baris lama otomatis `0`/`NULL`).
- `database_seed.sql` **tidak berubah** (tidak ada baris seed yang terlibat).
- `AFTER` **hanya** di `ALTER TABLE … ADD COLUMN` (bukan di `CREATE TABLE`, F17).
- ⚠️ **Urutan rilis:** migrasi **sebelum** deploy kode (query `get_users()`,
  gate Wallet, dan `toggle_wd_lock` membaca kolom baru).

---

## 3. Verifikasi yang DIJALANKAN (bukti nyata)

DB uji sekali-pakai `synapse_117_verify` dibangun dari file kanonik
(`database.sql` → `database_seed.sql`); `db_webtable` **tidak disentuh**.
Server: MariaDB 12.3.2, PHP 8.3.6.

### V0 — Bangun DB sekali-pakai dari file kanonik
```
php /tmp/p117/build_db.php
→ Statement: 57 | toleran-dilewati: 8 | fatal: 0
  users: 13 | gpu_products: 9 | ewallet_providers: 4 | admins: 1 | system_settings: 29
```
8 statement toleran = blok legacy-ALTER di `database_seed.sql` (kolom/index sudah
ada di `database.sql`) — perilaku yang memang didokumentasikan.

### V1 — Lint (roadmap rule 5)
`php -l` pada 12 file PHP baru/termodifikasi → **semua "No syntax errors detected"**
(`Wallet_model`, `Admin_model`, `Wallet`, `Admin`, `wd_lock_modal`,
`users.php`, `user_detail.php`, `wallet/index`, `wallet/withdraw`, kedua
`app_lang.php`, `migrate_117`).

### V2 — Gate i18n (keduanya exit 0)
```
php scripts/audit_i18n_parity.php    → [OK] LULUS — EN 639 ≡ ID 639, 0 nilai identik,
                                       0 nominal (P3), 0 newline (P6), 0 prosa ID di EN (P3b)
php scripts/audit_i18n_hardcoded.php → [OK] 0 temuan (90 file dipindai)
```

### V3 — Migrasi (idempoten, diuji di DB sekali-pakai)
Dijalankan pada DB yang **kolom 117-nya sudah di-drop** untuk mensimulasikan DB
live pra-117 (jalur `ADD COLUMN` benar-benar dieksekusi):

| Perintah | Hasil | Exit |
|---|---|---|
| `--dry-run` | kolom `BELUM ADA`; rencana 2 × `ADD COLUMN` + normalize | 0 |
| `--apply` | kedua kolom **ditambahkan**, normalize 0 baris, verifikasi lulus | 0 |
| `--apply` (re-run) | "sudah ada (dibiarkan)" / 0 baris → **no-op** | 0 |
| `--verify` | `TINYINT(1) NOT NULL DEFAULT 0` + `VARCHAR(255) NULL DEFAULT NULL` + W4 (0 orphan) OK | 0 |

### V4–V8 — Matriks HTTP (`bash /tmp/p117/http_matrix.sh`) → **PASS=59 FAIL=0**
2 user fixture benar-benar eligible (saldo Rp 1.000.000 via `wallet_ledger`,
binding e-wallet aktif, kontrak produk NON-trial aktif); user 4 = kontrol.
Login nyata melalui CAPTCHA native (challenge dibaca dari file session CI3),
token CSRF dari halaman, idiom di-set `id` (+ cek silang idiom `en`).

- **Baseline (tidak terkunci, user 4):** `GET /wallet` → tombol penarikan aktif,
  tanpa notice; `GET /wallet/withdraw` → **200** (form); `POST
  /wallet/process_withdraw` → 303 → `/wallet`, **`withdrawals` +1**, **debit
  ledger +1**.
- **Admin lock (`POST /admin/toggle_wd_lock/3` `lock=1&reason=…`)** → 200
  `{success:true, data.is_wd_locked:true}` + legacy root `is_wd_locked:true`;
  DB `is_wd_locked=1` + reason tersimpan; audit `admin_toggle_wd_lock`
  `{"before":"unlocked","after":"locked","reason":"Uji plan/117 …"}`; notifikasi
  `notif_wd_locked` +1.
- **Idempoten:** lock ulang → `changed:false`, **tanpa** audit/notifikasi baru.
- **Member terkunci:** `GET /wallet` → 200, tombol `disabled` label
  *Penarikan Ditangguhkan* (ID) / *Withdrawal Suspended* (EN), notice
  `wd_err_locked` tampil, **tanpa** tautan `wallet/withdraw`, dan **alasan admin
  tidak ada di HTML** (W3) di kedua idiom; `GET /wallet/withdraw` → 307 →
  `/wallet` (gatekeeper, bukan form); `POST /wallet/process_withdraw` → 303 →
  `/wallet` dengan **0 baris `withdrawals` baru** dan **0 baris debit baru**.
- **Unlock:** `lock=0` → `is_wd_locked=false`, DB `0 | NULL` (W4), notifikasi
  `notif_wd_unlocked`; notice hilang; `GET /wallet/withdraw` → **200** lagi;
  `POST /wallet/process_withdraw` → **berhasil** (`withdrawals` +1, debit +1,
  flash `wd_ok_submitted`).
- **Negatif admin:** GET → **405**; POST tanpa CSRF → **403**; tanpa reason →
  **422** `reason_required` (state tidak berubah); reason 300 char → **422**
  `reason_too_long`; `lock=yes` → **422** `invalid_request`; user tidak ada →
  **404** `user_found=false`.
- **Halaman admin:** `/admin/users`, `/admin/user_detail/3`, `/admin/audit` →
  200; badge/tombol/kartu WD Lock ter-render; `admin_toggle_wd_lock` muncul di
  filter audit (dropdown diturunkan dari DB — nol perubahan view audit).
- **Gate privasi statis:** `grep -rn -- "->wd_lock_reason"` pada view/controller
  permukaan member → **0**; pembaca sah hanya
  `views/admin/users.php`, `views/admin/user_detail.php`, `Wallet_model.php`.

### V8b — RE-TEST pasca-review (setelah perbaikan urutan `trans_status()`)
Review diff akhir menemukan bahwa cabang kegagalan TX masih tergantung pada
`&& $changed`, sehingga kegagalan TX pada no-op bisa jatuh ke respons 200
idempoten. Urutan diperbaiki (periksa `trans_status()` **sebelum** cabang
idempoten) dan **seluruh jalur admin diuji ulang** di DB sekali-pakai baru
(`synapse_117b`, dibangun ulang dari file kanonik + `migrate_117 --apply` pada
state pra-117 → jalur `ADD COLUMN` kembali dieksekusi, `--verify` exit 0):

```
bash /tmp/p117b/admin_retest.sh → RINGKASAN RE-TEST ADMIN: PASS=19 FAIL=0
```
Mencakup: login admin, GET 405, POST tanpa CSRF 403, tanpa reason 422,
reason 300 char 422, `lock=zz` 422, user tidak ada 404, lock 200
(`is_wd_locked=true`, audit +1, `notif_wd_locked`), **lock ulang →
`changed:false` tetap 200 (bukan 500 — cabang idempoten tidak rusak)**, audit
tidak bertambah, unlock 200 (`0 | NULL` W4, `notif_wd_unlocked`),
`/admin/users` & `/admin/user_detail/5` tetap 200.

### V9 — UJI RACE deterministik: gerbang OTORITATIF di dalam TX
Gatekeeper controller tidak cukup sebagai bukti; gerbang di dalam TX diuji dengan
skenario race nyata (`bash /tmp/p117/race_test.sh`, proses locker memegang
`SELECT … FOR UPDATE` pada baris `users`):

```
state awal: is_wd_locked = 0 (terbuka)
[1] locker memegang row lock users (3,0 s)
[2] POST /wallet/process_withdraw dikirim saat controller melihat user TERBUKA
    respons: 303 → /wallet | time_total = 2.2218 s   ← TX MENUNGGU row lock
[locker] is_wd_locked = 1 di-commit setelah 3.01 s; lock dilepas
[3] withdrawals: 0 → 0   debit ledger: 0 → 0
    is_wd_locked sekarang: 1
    flash member: penolakan generik (wd_err_locked) TAMPIL
[OK]
```
Waktu 2,22 s membuktikan request melewati **seluruh** gatekeeper controller (user
masih terbuka saat itu) lalu **memblokir di dalam TX**; setelah lock wait selesai,
TX membaca `is_wd_locked` yang **segar** (read view dibuat setelah lock wait) →
langkah `1a` menolak + `ROLLBACK` ⇒ **0 baris `withdrawals`, 0 baris
`wallet_ledger`**. Ini bukti langsung invarian **W2** + imutabilitas ledger.

---

## 4. Temuan Teknis Selama Eksekusi (perbaikan yang sudah masuk)

1. **MariaDB melaporkan `DEFAULT NULL` sebagai STRING `'NULL'`** di
   `information_schema.COLUMNS` (MySQL mengembalikan NULL PHP). Guard `--verify`
   awalnya menandai ini sebagai tamper → **diperbaiki** di
   `migrate_117_user_wd_lock.php` (menerima `NULL` maupun `'NULL'`) agar tidak
   menghasilkan exit 2 palsu di MariaDB.
2. **`mbstring` tidak tersedia di runtime ini** (`function_exists('mb_strlen') === false`),
   padahal `Admin_model::decline_withdrawal()` memakai `mb_substr()`. Validasi
   panjang alasan diberi fallback eksplisit
   (`mb_strlen` bila ada, `strlen` bila tidak) sehingga jalur 422 `reason_too_long`
   tetap berfungsi di lingkungan mana pun.
3. **Kode redirect CI3 bukan selalu 302**: POST `redirect()` → **303**, guard
   login member → **307**, admin login → **303**. Matriks verifikasi menerima
   302/303/307 dan mengasersi **tujuan** `Location` (bukan hanya kodenya).
4. **Judul notifikasi `notif_wd_locked` ("Penarikan Ditangguhkan") wajar muncul di
   bell/`/notification`** — asersi "notice hilang setelah unlock" karena itu
   mengukur pesan notice `wd_err_locked`, bukan keberadaan judul notifikasi (D3).
5. **Flashdata tertunda**: harness yang tidak mengikuti redirect menyisakan
   `wd_err_locked` untuk request berikutnya — asersi pasca-unlock mendahului
   dengan satu GET `/wallet` sebagai drain. Perilaku aplikasi sendiri benar.
6. **Review diff akhir (perbaikan yang sudah masuk)**: cabang kegagalan TX di
   `Admin::toggle_wd_lock()` semula masih `&& $changed`, sehingga kegagalan TX
   pada no-op bisa dilaporkan sebagai 200 idempoten. Diubah menjadi pemeriksaan
   `trans_status()` **sebelum** cabang idempoten (no-op sah tetap `true`), lalu
   jalur admin diuji ulang → **19/19 PASS** (lihat V8b).

---

## 5. Risiko / Catatan Lanjutan

1. **Urutan rilis wajib**: `migrate_117 --apply` di DB aktif **sebelum** deploy
   kode plan/117 (deploy lebih dulu → `/admin/users` error `Unknown column`).
2. **Divergensi `users.role`/`username` (pre-existing)** — hanya ada di DB live +
   blok legacy-ALTER `database_seed.sql`, tidak di `database.sql`. `get_users()`
   tetap memilih `u.role` dan **tidak** disentuh plan/117.
3. **`affected_rows() = 0` ≠ gagal** di MySQL; no-op diperlakukan sukses
   idempoten (`changed:false`, tanpa audit/notifikasi) — `trans_status()` tetap
   penentu kegagalan TX.
4. **Alasan tetap tercatat** di `system_audit_logs.details.reason` dan pada
   tooltip badge panel admin (memang tujuannya); copy admin menyatakan sifat
   internal tersebut. Rotasi/retensi audit log mengikuti kebijakan yang berlaku.

---

## 6. Definition of Done

- [x] E1–E16 selesai; diff akhir hanya berisi perubahan yang dimaksud
      (16 file; `AGENTS.md` **tidak** disentuh — perubahannya sudah ada sebelum sesi ini).
- [x] `php -l` bersih untuk seluruh file PHP baru/termodifikasi.
- [x] `audit_i18n_parity.php` **LULUS** & `audit_i18n_hardcoded.php` **0 temuan**;
      kamus **639 ≡ 639**.
- [x] Migrasi idempoten terbukti (dry-run → apply → re-apply no-op → verify, exit 0).
- [x] Matriks HTTP 59/59 (302/303/307/200/405/422/404 + envelope JSON, nol HTML pada jalur AJAX).
- [x] Gerbang TX diuji dengan race deterministik (0 baris `withdrawals`, 0 baris `wallet_ledger`).
- [x] Gate privasi W3 bersih; alasan admin tidak pernah muncul di HTML member (ID maupun EN).
- [x] Regresi: jalur penarikan user normal identik & tetap berhasil (V8 baseline + pasca-unlock).
- [x] `database.sql` kanonik + catatan migrasi live; `docs/2_ERD.md` (W1–W5) & `docs/3_ROADMAP.md` tersinkron.
- [x] Nol perubahan `system/`, nol route baru, nol tabel baru, nol `wallet_ledger` baru dari lock/unlock.
