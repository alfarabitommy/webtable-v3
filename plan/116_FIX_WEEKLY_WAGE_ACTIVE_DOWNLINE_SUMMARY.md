# PLAN 116 — FIX GAJI MINGGUAN: DOWNLINE AKTIF WAJIB KONTRAK PRODUK NON-TRIAL — EXECUTION SUMMARY

> **Plan:** `plan/116_FIX_WEEKLY_WAGE_ACTIVE_DOWNLINE_PLAN.md` (disetujui, `dec-025fb0320bedcb1d`).
> **Scope dieksekusi:** E0–E10 (langkah plan §6) — predikat "downline aktif" diseragamkan di `User_model` (3 titik) + kelayakan upline rebate (`Rental_model`), fixture verifikasi diperbaiki, 4 nilai kamus disinkronkan, `docs/` disegarkan, **+ 1 perbaikan DDL darurat** (`dec-d15aaa2045b908d2`).
> **Status:** SELESAI & TERVERIFIKASI RUNTIME (55/55 assertion lolos, 0 gagal) — nol DDL skema, nol migrasi, nol route, nol perubahan admin/`system/`.
> **Non-goal yang dipegang:** nol perubahan `wallet_ledger` historis (tanpa clawback), nol perubahan struktur `claim_wage()`/`claim_level1()`, `get_user_rental_stats()` tidak disentuh (plan/114 D-B).

---

## 1. Keputusan owner yang diterapkan

| # | Keputusan | Decision ID |
|---|---|---|
| D1 | Unifikasi predikat di **ketiga** titik `User_model` (bukan hanya wage) | `dec-025fb0320bedcb1d` |
| D2 | Tanpa clawback; gaji terlanjur cair = kerugian bookkeeping terdokumentasi (nol mutasi data) | `dec-025fb0320bedcb1d` |
| D3 | Kelayakan upline rebate ikut diperbaiki (`Rental_model::_distribute_rebate()`) | `dec-025fb0320bedcb1d` |
| D4 | **Perbaikan `database.sql` (temuan blocker F17)** — klausa `AFTER \`max_per_user\`` di `CREATE TABLE gpu_products` dihapus (hanya valid di `ALTER TABLE`; berkas tidak dapat di-import pada instalasi bersih) | `dec-d15aaa2045b908d2` |

---

## 2. Perubahan yang dieksekusi (9 berkas + 1 berkas plan)

| # | Berkas | Perubahan |
|---|---|---|
| 1 | `application/models/User_model.php` | **3 predikat** diperbaiki: `count_all_active_downlines()` (`:171-195`, otoritas level gaji), `count_active_b_downlines()` (`:138-145`, gate bonus L1), `get_team_with_active_status()` (`:82-101`, kedua cabang UNION — badge + counter `/team`). Tiap titik: `JOIN gpu_products gp ON gp.id = ur.product_id` + `AND gp.is_trial = 0`; docblock menyebut plan/116 + predikat kanonik. `claim_wage()`/`claim_level1()`/`WAGE_TIERS`/`determine_wage_level()` **nol perubahan** |
| 2 | `application/models/Rental_model.php` | Kelayakan upline rebate (`_distribute_rebate()`, `:703-717`) kini mensyaratkan kontrak aktif dari produk **non-trial**; docblock aturan 3 (`:623-628`) disinkronkan; `has_paid_rental()` (`:917-931`) diberi catatan bahwa predikatnya menjadi **norma** bagi seluruh perhitungan downline/aktivitas berbayar |
| 3 | `scripts/seed_wage_test_account.php` | Pemilihan produk **eksplisit** via `is_trial` (`pick_product()`), flag baru `--downlines=N` (1..98), `--product=paid\|trial\|mixed` (default `paid` = perilaku lama), `--help`; pembersihan menyapu seluruh rentang telepon fixture; blok verifikasi memirror SQL model + mencetak **dua** penghitung (berbayar vs predikat lama) |
| 4 | `application/language/english/app_lang.php` | 4 nilai: `team_help_active_body`, `team_help_l1_li1`, `team_help_wage_body`, `team_wage_sub` |
| 5 | `application/language/indonesian/app_lang.php` | 4 nilai (padanan idiom ID) |
| 6 | `database.sql` | **F17:** klausa `AFTER` dihapus pada `CREATE TABLE gpu_products.is_trial` (klausa `AFTER` di catatan migrasi `:712` tetap — valid di `ALTER TABLE`) |
| 7 | `docs/1_PRD.md` | §G definisi **Active Downline** + klarifikasi syarat L1 (`:236`) + jalur gaji mingguan (fail-closed) + catatan rebate trial-only |
| 8 | `docs/2_ERD.md` | Kolom `gpu_products.is_trial` didokumentasikan (diagram `:71` + daftar kolom) + **Invariant K8** pada `user_rentals` |
| 9 | `docs/3_ROADMAP.md` | Entri **plan/116 COMPLETED** (7 butir, termasuk F17) |
| 10 | `plan/116_FIX_WEEKLY_WAGE_ACTIVE_DOWNLINE_PLAN.md` | Dokumen plan (E0) |

Diff akhir: `9 files changed, 221 insertions(+), 43 deletions(-)` + 1 berkas plan baru. `git status` bersih (hanya berkas di atas).

---

## 3. Gate statik — perintah & hasil yang benar-benar dijalankan

| # | Perintah | Hasil |
|---|---|---|
| G1 | `php scripts/migrate_114_trial_product_wd_gate.php --verify` (DB lokal `db_webtable`) | **exit 0** — `[OK] Verifikasi plan/114 lulus.`; `kolom gpu_products.is_trial TINYINT(1) NOT NULL DEFAULT 0 OK`; `baris is_trial = 1 TEPAT 1` (id aktif = 17, `price=0`, `max_per_user=1`, `daily_rate=10000`, `duration_days=3`) |
| G2 | `php -l` × 5 berkas PHP (`User_model`, `Rental_model`, `seed_wage_test_account`, kedua `app_lang`) | **No syntax errors detected** (5×) |
| G3 | `php scripts/audit_i18n_parity.php` | **exit 0** — `EN keys : 633` / `ID keys : 633` / `Paritas : 1:1 OK` / `Nilai identik: 0` / `Nominal literal (P3): 0` / `Newline (P6): 0` / `Prosa ID di EN (P3b): 0` / `[OK] Semua gate paritas & higienitas kamus LULUS.` |
| G4 | `php scripts/audit_i18n_hardcoded.php` | **exit 0** — `File dipindai : 90` / `Total temuan : 0` / `[OK] 0 temuan` |

Catatan G4: percobaan pertama gate ini sempat **FAIL 3 temuan** — seluruhnya di `application/logs/log-2026-09-25.php`, yaitu **file log runtime yang dibuat oleh trafik tes harness saya sendiri** (pesan SQL `Sewa RTX 4090 Node (Entry)` dari kegagalan checkout bentrok-transaction_id saat harness, §6.3). Auditor memindai `application/logs/*`, jadi artefak runtime lokal dapat memunculkan false positive. Berkas log (gitignored, dibuat 18:56 hari ini, seluruh isinya berasal dari sesi ini) dihapus → gate kembali `0 temuan` (90 berkas).

---

## 4. Bukti F17 — `database.sql` kini dapat di-import (instalasi bersih)

Perintah: `php /tmp/p116_build_scratch.php` (harness di `/tmp`, bukan di repo) — membangun DB sekali-pakai dari **`database.sql` + `database_seed.sql`** dengan splitter statement sadar-quote (klien `mysql` tidak tersedia di lingkungan ini).

| Asersi | Hasil |
|---|---|
| Sebelum perbaikan (dibuktikan saat insiden) | `ERROR 1064 ... near 'AFTER \`max_per_user\`, ...'` pada `CREATE TABLE gpu_products` → **seluruh tabel sesudahnya tidak pernah terbuat** |
| Sesudah perbaikan | `[ok] database.sql statements=22`, `[ok] database_seed.sql statements=35`; 8 error **ditoleransi** (1060 duplikat kolom / 1061 duplikat key — seed hanya memirror ALTER legacy yang sudah ada di DDL kanonik) |
| Tabel | `tables=16` (14 kanonik + 2 retensi legacy `rentals`/`otp_logs`) |
| Seed | `users 13`, `gpu_products 9`, `user_rentals 15`, `wallet_ledger 179`; baris trial: `{"id":"9","name":"GPU Magang (Trial)","price":"0.00","is_trial":"1","max_per_user":"1","duration_days":"3"}` |
| **Paritas skema vs DB aktif** | `tables live=16 fresh=16`, `only-live: -`, `only-fresh: -`; `gpu_products 14/14 IDENTICAL`, `users 19/19 IDENTICAL`, `user_rentals 12/12 IDENTICAL`, `wallet_ledger 7/7 IDENTICAL` |
| Urutan kolom (invarian posisi `is_trial`) | `id,name,image,type,price,daily_rate,duration_days,is_refundable,max_per_user,is_trial,unlock_prerequisite_id,is_active,created_at,updated_at` — **identik** dengan DB aktif (langsung setelah `max_per_user`) |

Kesimpulan: perbaikan F17 **tidak mengubah skema** (nol delta kolom/index), hanya membuat berkas DDL kanonik valid. Tidak ada migrasi baru yang dibutuhkan; DB aktif tidak disentuh.

---

## 5. Matriks verifikasi runtime V2–V8 (jalur HTTP nyata + fixture + DB)

**Metode.** DB sekali-pakai `db_webtable_p116` (dibangun dari DDL kanonik, §4) + aplikasi dijalankan `php -S 127.0.0.1:8099` dengan `DB_HOSTNAME/DB_DATABASE/DB_USERNAME/DB_PASSWORD` di-override ke DB sekali-pakai itu → **nol tulisan ke `db_webtable`** (dibuktikan di §8). Login memakai **jalur asli** (CAPTCHA SVG native dibaca dari session store CI3, bukan bypass), claim lewat `POST /team/claim_wage` (AJAX + CSRF), checkout lewat `POST /rentals/checkout`. Harness: `/tmp/p116_e2e.php` (di luar repo).

**Hasil ringkas: 55 PASS / 0 FAIL.**

| # | Skenario | Hasil teramati |
|---|---|---|
| **V8** | Fixture default (perilaku lama) | `Kontrak aktif dibuat: 9 (paid=9, trial=0)`; `count_all_active_downlines = 9 (expected 9)`; produk dipilih eksplisit: `Produk BERBAYAR id=1 price=1000000.00 is_trial=0`; harness exit 0; **re-run exit 0 & hitungan tetap 9** (idempoten) |
| **V4** | 9 downline **berbayar** | `/team` merender `id="btn-claim-wage"` (terbuka), label terkunci tidak dirender; `POST /team/claim_wage` → `{"success":true,"code":"claimed","amount":200000,"level":2,"cycle":"Y2026W39","transaction_id":"WAGE-216-Y2026W39","new_balance":200000}`; DB: **tepat 1** baris `wallet_ledger` `WAGE-*` (`credit`, 200000); `last_wage_claimed_at = 2026-09-25 18:59:05`; klaim kedua → `{"success":false,"code":"already_claimed"}` dan **tetap 1** baris (anti-replay utuh) |
| **V2** | 13 downline **trial** | Fixture: `count_all_active_downlines = 0 (expected 0)`, `predikat LAMA (tanpa is_trial) = 13  <-- inflasi trial yang kini dikecualikan`; `/team`: tombol claim **tidak dirender**, label `Minimum 9 Active Downlines` dirender; claim → `{"success":false,"code":"not_qualified"}`; DB: **0** baris `wallet_ledger`; `last_wage_claimed_at` tetap **NULL** |
| **V3** | **12 trial + 1 berbayar** (13 downline) | Fixture: `Kontrak aktif dibuat: 13 (paid=1, trial=12)`; `count = 1 (expected 1)`, `predikat LAMA = 13`; `/team` masih terkunci; claim → `not_qualified`; DB: 0 baris ledger |
| **V5** | Anti fan-out: 1 downline dengan trial **dan** kontrak berbayar (dua-duanya aktif) | Mirror predikat plan/116 = **1** (dihitung sekali), predikat lama = 9; kartu gaji `/team` menampilkan counter **1**; tetap terkunci |
| **V6** | Badge `/team` | `paid aktif -> Active`; `trial-only -> Inactive`; `paid kedaluwarsa -> Inactive` (badge kini konsisten dengan mesin gaji) |
| **V7a** | Rebate — upline **trial-only** | Checkout downline Rp 1.000.000 sukses (`Location: /rentals`), kontrak pembelian dibuat (1), tetapi upline: **0** baris `RBT-*`, **0** notifikasi `notif_rebate` (breakage, tanpa pass-up) |
| **V7b** | Rebate — upline **berbayar** | **1** baris `RBT-*` = `50000.00` (L1 5%) dengan pola `RBT-{rental_id}-L1`, **1** notifikasi `notif_rebate` |
| **V7c** | Rebate — upline kontrak `promoter_reward` **non-trial** | **+1** baris `RBT-*` → **tanpa regresi K7/plan-114 D-A** (kontrak reward tetap eligible) |

Jalur payout fail-closed terbukti pada kombinasi V2/V3/V4/V5: keputusan level dihitung ulang **di dalam TX terkunci** (`users FOR UPDATE` → `count_all_active_downlines()`), dan tanpa kualifikasi **tidak ada satu pun** baris ledger/stamp.

---

## 6. Deviasi, temuan, dan catatan jujur

### 6.1 Deviasi teknis kecil (disengaja, semantik identik)
- **Alias join `gp`, bukan `p`.** Plan §3 menulis `JOIN gpu_products p … p.is_trial = 0`. Pada `User_model::get_team_with_active_status()` cabang kedua `UNION ALL`, alias `p` **sudah dipakai** untuk `users p` (`INNER JOIN users p ON u.parent_id = p.id`) — memakai `p` berarti *shadowing* yang sah tapi menyesatkan. Seluruh 4 predikat baru memakai `gp` (konsisten, satu makna). Predikat & hasil identik; `Rental_model::has_paid_rental()` (tetangga langsung) tetap memakai `p`.

### 6.2 F17 — temuan blocker pra-eksisting yang diperbaiki atas persetujuan owner
`database.sql:73` memakai `AFTER \`max_per_user\`` **di dalam `CREATE TABLE`** (hanya valid di `ALTER TABLE … ADD COLUMN`) → `database.sql` tidak dapat di-import; pada instalasi bersih, tabel `gpu_products` dan **seluruh tabel sesudahnya** tidak pernah terbuat. Berasal dari plan/114 (perubahan DDL terakhir), bukan dari plan/116. Diperbaiki dengan menghapus klausa `AFTER` sambil mempertahankan posisi fisik baris kolom (terbukti: urutan kolom fresh ≡ live, §4).

### 6.3 Catatan harness (bukan cacat plan/116)
Saat harness melakukan dua pembelian oleh **user yang sama dalam detik yang sama**, `Rental_model` gagal dengan `Duplicate entry '<uid>-RENT-1-<YmdHis>-debit'` → checkout ditolak (rollback bersih, pesan generik). `transaction_id` debit memang berpresisi **detik** (`RENT-{n}-{YmdHis}`); ini perilaku pra-eksisting (bukan akibat plan/116) dan tidak pernah terjadi di produksi karena manusia tidak checkout dua kali dalam satu detik. Harness diberi jarak 1,3 s antar-checkout (dictat di header harness). Dirujukkan sebagai **O6** bila owner ingin memperkuat pola ID.

### 6.4 F18 — temuan baru, **di luar scope**, belum diperbaiki
Pada jalur **sukses** `POST /team/claim_wage`, key legacy `message` diisi oleh `Team::_wage_message()` yang **tidak punya pemetaan untuk `code = 'claimed'`** → jatuh ke fallback `rental_err_claim_failed`. Karena `api_success()` menimpa envelope dengan key legacy (`foreach ($legacy …) $body[$key] = $value`), respons sukses V4 tercatat:

```
{"success":true,"message":"System: failed to process the claim. Please try again.",
 "data":{"level":2,"amount":200000,...},"code":"claimed",...}
```

`views/team/index.php:657` menampilkan `d.message || I18N.wage_success` → **toast sukses berbahasa "gagal"**. Ini **pra-eksisting** (jalur kode tidak tersentuh plan/116; berasal dari pemetaan `_wage_message`, era plan/103) dan `plan/116` sengaja tidak mengubah `Team.php` (non-goal: nol perubahan controller). Fungsi tetap benar (`success:true`, kredit cair, anti-replay jalan) — hanya teks. Usulan perbaikan 1 baris: tambahkan `'claimed' => 'team_js_wage_success'` pada `$map` atau jangan menimpa `message` pada branch sukses.

### 6.5 Temuan sampingan lain (dokumentasi, tidak diperbaiki)
- **F11 (plan):** `/admin/analytics` melabeli `downline_count` sebagai *"active downlines"* padahal menghitung seluruh downline tanpa filter aktivitas; sekaligus bahasa Inggris di panel admin (L1) — **O4**.
- **F15 (plan):** ROADMAP belum memuat entri plan/113–115 — **O5**.

---

## 7. Dampak yang harus diketahui operasional

1. **Tanpa clawback (D2).** Gaji mingguan yang sudah terlanjur cair berdasarkan hitungan trial **tidak ditarik kembali**; `wallet_ledger` immutable. Dampak bersifat bookkeeping dan tidak dapat direkonstruksi tanpa audit manual (tidak ada skrip; lihat O1).
2. **Efek retroaktif yang disengaja.** Leader/promotor yang kelayakannya bertumpu pada downline trial-only akan **langsung kehilangan** kelayakan gaji (dan upline trial-only kehilangan rebate) sejak deploy. Ini inti anti free-rider; disosialisasikan lewat copy `/team` yang baru (§3 G3/G4 memastikan kamusnya rapi).
3. **Tanpa DDL/migrasi.** Deploy = unggah kode saja; kolom `is_trial` sudah ada di live sejak plan/114 (gate G1 exit 0). **Tidak ada** migrasi baru, tidak ada index baru (`idx_user_status_expired` + PK join tetap melayani).
4. **Kontrak `promoter_reward` (non-trial) tetap dihitung** sebagai aktivitas berbayar — konsekuensi yang diterima owner pada plan/114 D-A, kini eksplisit di PRD/ERD (K8).
5. **Gate i18n vs log runtime.** `audit_i18n_hardcoded.php` memindai `application/logs/*`; log lokal hasil aktivitas apa pun (termasuk QA manual) dapat memunculkan temuan palsu. Bersihkan log runtime sebelum menjalankan gate (§3).

---

## 8. Batas verifikasi (yang TIDAK diklaim)

1. **`db_webtable` (DB lokal/aktif) tidak menerima satu pun tulisan dari harness.** Bukti: setelah seluruh matriks, `SELECT COUNT(*) FROM users WHERE phone LIKE '08129999%'` = **0**; `users=6`, `user_rentals=4`, `wallet_ledger=9`, `user_notifications=2` (tetap seperti semula). Yang dijalankan terhadap `db_webtable` hanyalah **read-only** `migrate_114 --verify` (G1).
2. **QA visual 360 px (R3) tidak dijalankan dengan browser.** Risiko teks kartu bertambah panjang karena 4 nilai kamus; yang diverifikasi hanya tidak-adanya regresi fungsional (`/team` merender normal, badge & tombol benar). Verifikasi visual tetap manual.
3. **Panel admin & CSRF/JS sisi lain tidak diuji ulang** — plan/116 tidak menyentuh controller/view, jadi tidak ada jalur baru yang perlu diuji.
4. **Live DB tidak diuji** (kredensial produksi tidak dipakai); seluruh bukti berasal dari DB sekali-pakai hasil `database.sql` + `database_seed.sql` yang **identik secara skema** dengan DB aktif (§4).
5. Bukti tester tidak menyertakan eksekusi `--verify` plan/114 **terhadap live DB produksi** (tidak ada akses dari sandbox).

---

## 9. Definition of Done — checklist

| # | Kriteria (plan §12) | Status |
|---|---|---|
| 1 | `php -l` bersih untuk semua berkas PHP yang diubah (5 berkas) | ✅ G2 |
| 2 | `audit_i18n_parity.php` LULUS & `audit_i18n_hardcoded.php` 0 temuan (exit 0) | ✅ G3, G4 |
| 3 | Matriks V2, V3, V4, V5, V7, V8 dijalankan & dilaporkan apa adanya | ✅ §5 (55/55 pass) |
| 4 | Nol DDL: `database*.sql`/`scripts/migrate_*` tidak muncul di diff… | ⚠️ **pengecualian terotorisasi**: `database.sql` muncul, **tanpa delta skema** (perbaikan sintaks F17 atas `dec-d15aaa2045b908d2`, dibuktikan identik §4). `database_seed.sql` & `scripts/migrate_*` **tidak tersentuh** |
| 5 | Ringkasan memuat matriks bukti + dampak historis + rujukan keputusan owner | ✅ dokumen ini |
| 6 | `docs/` tersinkron (definisi Active Downline + kolom `is_trial`) | ✅ PRD §G, ERD (kolom + K8), ROADMAP entri plan/116 |
| 7 | Nol artefak scratch tertinggal di repo | ✅ §10 |

---

## 10. Kebersihan artefak & item lanjutan

**Dibersihkan (E9):** server `php -S` dihentikan (job `bash-1` killed); DB sekali-pakai `db_webtable_p116` **dan** `db_p116_probe` (DB diagnostik sementara) **di-drop**; `application/logs/log-2026-09-25.php` (dibuat trafik tes) dihapus; direktori job host `.reasonix/tasks/34a4630dbafe731a--bash-1/` dihapus; artefak harness (`/tmp/p116_*.php`, cookie jar, HTML, log) dihapus setelah ringkasan ini ditulis. `git status` akhir hanya memuat 9 berkas termodifikasi + 1 berkas plan baru.

**Item lanjutan (belum dikerjakan, menunggu keputusan owner):**

| # | Item | Catatan |
|---|---|---|
| O1 | CLI diagnostik read-only klaim gaji (dry-run) | ditolak pada D2; opsi bila owner ingin melihat dampak historis |
| O2 | Badge netral "Trial" di `/team` (butuh key kamus baru + view) | downline trial-only saat ini tampil "Nonaktif" |
| O3 | Sentralisasi predikat bila muncul titik pemakaian ke-6 (konstanta SQL) | kini 5 titik, sengaja inline (§D2 plan) |
| O4 | Perbaikan label `views/admin/analytics.php:66` + temuan bahasa Inggris panel admin (L1) | F11 |
| O5 | Backfill entri ROADMAP plan/113–115 | F15 |
| **O6** | **F18** — pesan sukses `claim_wage` memakai teks gagal (`Team::_wage_message()` fallback `rental_err_claim_failed` untuk `claimed`) | user-visible; 1 baris; di luar scope plan/116 (§6.4) |
| **O7** | `transaction_id` debit `RENT-{n}-{YmdHis}` bentrok bila user checkout 2× dalam 1 detik | pra-eksisting (§6.3) |

---

## 11. Rujukan bukti (dapat direproduksi)

```bash
# 0) gerbang prasyarat DDL (read-only)
php scripts/migrate_114_trial_product_wd_gate.php --verify                 # exit 0

# 1) gate statik
php -l application/models/User_model.php                                   # + 4 berkas lain
php scripts/audit_i18n_parity.php                                          # LULUS (633/633)
php scripts/audit_i18n_hardcoded.php                                       # 0 temuan (90 berkas)

# 2) DB sekali-pakai dari DDL kanonik (harness /tmp; tidak menyentuh db_webtable)
php /tmp/p116_build_scratch.php                                            # 22 + 35 statement, 16 tabel
php /tmp/p116_schema_parity.php                                            # 4 tabel IDENTICAL vs db_webtable

# 3) matriks runtime (fixture + HTTP nyata + DB assertions)
DB_HOSTNAME=127.0.0.1 DB_DATABASE=db_webtable_p116 ... php -S 127.0.0.1:8099 &
php /tmp/p116_e2e.php                                                      # 55 PASS / 0 FAIL

# 4) fixture manual (per skenario)
DB_DATABASE=db_webtable_p116 php scripts/seed_wage_test_account.php --downlines=13 --product=trial
DB_DATABASE=db_webtable_p116 php scripts/seed_wage_test_account.php --downlines=13 --product=mixed
DB_DATABASE=db_webtable_p116 php scripts/seed_wage_test_account.php        # default: 9 paid -> L2
```

*(Semua perintah dijalankan pada sesi ini. Harness `/tmp` beserta DB sekali-pakai sudah dihapus sesuai E9; skrip fixture di `scripts/` tetap tersedia sebagai harness permanen repo.)*
