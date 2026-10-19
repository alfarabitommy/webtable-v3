# SUMMARY PLAN 119 — BONUS PENCAPAIAN TIM (TEAM MILESTONE BONUS)

- **Branch:** `feat/119-team-milestone-bonus` (dibuat dari `main`; **belum** di-commit)
- **Status:** **SELESAI** — seluruh direktif E1–E16 dijalankan; kode, migrasi, kamus,
  dokumen, dan matriks verifikasi tervalidasi pada **DB buang**
- **Rencana:** `plan/119_TEAM_MILESTONE_BONUS_PLAN.md` (+ §19 Addendum Eksekusi)
- **Keputusan owner:** `dec-4ac071f2603beab7` (L1 independen · klaim **sekuensial** · **backfill** grandfathering)
- **Catatan penting:** `db_webtable` (DB lokal milik owner) **TIDAK PERNAH disentuh** —
  seluruh uji memakai DB buang `synapse119_tmp` (MariaDB 12.3.2) di `127.0.0.1`.

> **Catatan worktree:** branch ini dibangun di atas worktree yang **sudah** memuat
> pekerjaan plan/117–118 milik owner yang belum di-commit (Admin_model, Wallet_model,
> Rental_model, Checkin_model, beberapa view, `docs/`, `database.sql`, dst.). Diff akhir
> memuat keduanya; daftar berkas **plan/119** ada di §4 di bawah.

---

## 1. Ringkasan perubahan

Mesin **Gaji Mingguan** (recurring, siklus 7 hari, `WAGE_TIERS` hardcoded) **digantikan**
**Bonus Pencapaian Tim**: tier & nominal **dinamis** (admin), dibayar **sekali seumur hidup
per tier**, klaim **berurutan dari tier terendah**, **tanpa cooldown/siklus**, kredit ke
**Saldo Utama**. Bonus Level 1 (Rp 80.000) **tidak diubah** (keputusan owner O1).

| Lapis | Realisasi |
|---|---|
| Skema | `users.team_milestone_claimed` VARCHAR(255) NULL DEFAULT NULL + key `team_milestone_enabled` / `team_milestone_tiers`. `users.last_wage_claimed_at` → **DEPRECATED (retensi)**. **Nol tabel/index baru** (inventory tetap 14). |
| Konfigurasi | `system_settings` (seed = mirror tier gaji lama 9/30/70/130/190) + fallback `application/config/team_milestones.php` |
| Choke-point | `application/helpers/team_milestone_helper.php` (murni struktural, nol prosa → gate i18n tetap bersih); autoload `'team_milestone'` |
| Mesin klaim | `User_model::get_milestone_config()` / `validate_milestone_settings()` / `get_milestone_status()` / `claim_milestone()`; `WAGE_TIERS`/`determine_wage_level()`/`check_wage_cooldown()`/`claim_wage()`/`_wage_result()` **dihapus** |
| Endpoint | `POST /team/claim_milestone` (POST+AJAX-only, rate limit 5/60 → 429, envelope `api_*` + key legacy); `Team::claim_wage()`/`_wage_message()` **dihapus** ⇒ **F18 tertutup di akarnya** |
| Admin | **Card 7** "Bonus Pencapaian Tim" di `/admin/settings` (toggle fail-closed + editor baris tier + error inline + repopulasi) + audit `admin_update_settings` |
| Member | Kartu "Bonus Pencapaian Tim" di `/team` (loop tier, maks **satu** tombol klaim, repaint tanpa reload), modal bantuan dinamis, `team_ms_*` kamus |
| Migrasi | `scripts/migrate_119_team_milestone.php` (6 fase + `--skip-backfill`, idempoten, tamper → exit 2) |
| Fixture | `seed_wage_test_account.php` → `seed_milestone_test_account.php` (diadaptasi) |
| Kamus | **650/650** (+27 / −28) + pola `ledger_milestone`; `ledger_wage`/`notif_wage_*` + kolom `last_wage_claimed_at` + baris `WAGE-*` **dipertahankan** |
| Dokumen | `docs/1_PRD.md` (v5.4), `docs/2_ERD.md` (v5.4 + MK1–MK8), `docs/3_ROADMAP.md` (seksi plan/119 + Fase 7B2 SUPERSEDED), `plan/107` (angka mingguan → sekali per tier), `AGENTS.md` (Notes + F18 FIXED + fixture) |

**Tidak disentuh:** `system/**`, `application/config/routes.php`,
`application/views/templates/header.php`, `Wallet_model.php` (permukaan `credit()` sudah
cukup), logika `Checkin_model`/`Checkin.php` (hanya komentar rujukan yang diperbarui).

---

## 2. Directives (E1–E16) — status

| # | Langkah | Status | Bukti |
|---|---|---|---|
| E1 | Branch + baseline | ✅ | `git checkout -b feat/119-team-milestone-bonus`; baseline kamus `651/651` |
| E2 | `database.sql` | ✅ | 1 kolom + 2 seed + blok "MIGRASI LIVE" (import bersih pada DB buang) |
| E3 | `database_seed.sql` | ✅ | `[RECONCILE]` ALTER + 2 seed (duplikat 1060 toleran) |
| E4 | Config fallback | ✅ | `application/config/team_milestones.php` |
| E5 | Helper + autoload | ✅ | `team_milestone_helper.php` + `autoload.php:117` |
| E6 | `User_model` | ✅ | Mesin wage dihapus, mesin milestone ditambah (lint bersih) |
| E7 | `Team.php` + komentar usang | ✅ | `claim_milestone()`/`_milestone_message()`; `claim_wage()` hilang; `Checkin.php`/`Checkin_model.php` komentar disinkronkan |
| E8 | View member | ✅ | Kartu + modal + I18N + `claimMilestone()`/`paintMilestones()` |
| E9 | `Admin.php` settings | ✅ | `_collect_milestone_rows()`, validasi, audit, repopulasi, injeksi GET |
| E10 | Card 7 admin | ✅ | Toggle + editor baris + error inline (100% Indonesia) |
| E11 | i18n | ✅ | 650/650 + pola `ledger_milestone` |
| E12 | Migrasi CLI | ✅ | 6 fase; dry-run/apply/re-apply/verify/tamper terbukti |
| E13 | Fixture | ✅ | `git mv` + adaptasi (eksklusi trial tetap diuji) |
| E14 | Sinkronisasi dokumen | ✅ | PRD/ERD/ROADMAP/107/AGENTS + §19 plan |
| E15 | Matriks verifikasi | ✅ | V1–V37 pada DB buang (rincian §3) |
| E16 | Ringkasan + pembersihan | ✅ | Berkas ini + cleanup (§6) |

---

## 3. Verifikasi yang BENAR-BENAR dijalankan

### 3.1 Statis (V1–V5)

```
$ for f in <15 berkas PHP plan/119>; do php -l $f; done
No syntax errors detected            (15/15 berkas, PHP 8.3.6)

$ rm -f application/logs/log-*.php && php scripts/audit_i18n_parity.php --quiet
EN keys : 650 · ID keys : 650 · Paritas : 1:1 OK
Nilai identik (di luar allowlist): 0 · Nominal literal (P3): 0
Newline literal (P6): 0 · Prosa Indonesia di idiom EN (P3b): 0
[OK] Semua gate paritas & higienitas kamus LULUS.            (exit 0)

$ php scripts/audit_i18n_hardcoded.php
File dipindai : 74 · Total temuan : 0
[OK] 0 temuan — tidak ada string hardcoded di surface member. (exit 0)

$ grep -rn "WAGE_TIERS|determine_wage_level|check_wage_cooldown|claim_wage|last_wage_claimed_at" application/
→ hanya retensi/komentar: views/team/index.php:11 (komentar), MY_Controller.php:22,
  User_model.php:117-118 & 665 (komentar), Wallet_model.php:53 (komentar).
  Nol pemakaian fungsional; nol rujukan "pola Team::claim_wage" yang tertinggal.

$ grep -n "MSB-\|team_milestone" application/ database.sql scripts/
→ titik tunggal sesuai rencana (helper + model + config + view + admin + migrasi).
```

### 3.2 Migrasi (V6–V10) — DB buang berbentuk **pra-plan/119**

Setup: `synapse119_tmp` dibangun dari `database.sql` + `database_seed.sql`, lalu
kolom + 2 key **dihapus** (simulasi DB lama) dan disuntik histori:
user 991 (prosa parseable "9 lalu 30 downline aktif"), user 992 (prosa **tidak**
parseable, nominal 2.500.000 → jalur proxy), user 993 (tanpa histori), user 1 (seed,
baris `WAGE-...` deskripsi lama + nominal 100.000).

```
$ php scripts/migrate_119_team_milestone.php --dry-run          # V6 → exit 0
  kolom BELUM ADA · 2 key BELUM ADA · baris WAGE-%: 4 (pemilik unik: 3)
  [>] AKAN: ALTER TABLE users ADD COLUMN team_milestone_claimed VARCHAR(255) NULL DEFAULT NULL AFTER last_wage_claimed_at
  Backfill: 2 user ditandai → distribusi [9,30] → 1 user · [9,30,70] → 1 user
  [DRY-RUN] rencana siap — jalankan --apply (exit 0)

$ php scripts/migrate_119_team_milestone.php --apply            # V7 → exit 0
  [+] kolom team_milestone_claimed ditambahkan
  [+] team_milestone_enabled di-seed · [+] team_milestone_tiers di-seed
  [+] 2 baris team_milestone_claimed diisi (guard IS NULL)
  VERIFY: varchar(255) nullable=YES · enabled='1' · tiers=5 tier · 2/16 user terklaim
  [OK] seluruh invarian plan/119 terpenuhi (exit 0)

$ php scripts/migrate_119_team_milestone.php --apply            # V8 re-run
  [=] kolom sudah ada (dibiarkan) — varchar(255)
  [=] team_milestone_enabled sudah ada (dibiarkan): 1
  [=] 0 baris team_milestone_claimed diisi (no-op)              (exit 0)

$ php scripts/migrate_119_team_milestone.php --verify           # V9 → exit 0
$ UPDATE system_settings SET key_value='{"a":1}' …; --verify    # V10 → exit 2
  [TAMPER] nilai `team_milestone_tiers` tidak dapat diparse sebagai JSON
```

**Hasil backfill aktual (grandfathering O3):** user 991 → CSV `9,30` (presisi prosa);
user 992 → `9,30,70` (proxy nominal 2.500.000 → tier dengan nominal ≤ 2,5 jt);
user 993 & user 1 → tanpa penandaan (pencapaian di bawah tier terendah).

### 3.3 Endpoint & jalur uang (V11–V28) — via HTTP nyata

Harness: server `php -S 127.0.0.1:8099 index.php` (env `DB_DATABASE=synapse119_tmp`,
`TMPDIR=/tmp`) + dua skrip pengemudi di `/tmp` (`http119.php` login nyata termasuk CAPTCHA
yang dibaca dari berkas sesi CI3; `matrix119.php` sesi CI3 + asersi DB langsung).
Total **≥ 90 asersi**, **0 gagal**:

| V | Asersi yang diamati |
|---|---|
| V11 | `GET /team/claim_milestone` → **404** |
| V12 | `POST` tanpa header AJAX → **404** |
| V13 | sesi kosong → **303 `Location: …/login`** (guard `MY_Controller`) + nol baris ledger — *lihat §5 A5* |
| V14 | 5 downline berbayar → `not_qualified` + `missing=4`, HTTP 200, 0 ledger, CSV tetap `NULL`, kartu menampilkan "Kurang 4 downline aktif", nol tombol klaim |
| V15 | 9 downline → `claimed`, amount `200000`, `transaction_id=MSB-<uid>-T9`, **`message` = kalimat SUKSES** (F18), `data.milestones` ada, `new_balance` == mirror ledger, baris ledger `wallet_type=main` + deskripsi `Bonus Pencapaian Tim 9 Downline Aktif`, CSV `9`, notifikasi `notif_milestone` = 1 |
| V16 | replay tier 9 → `already_claimed`, **0** baris ledger baru |
| V17 | target 30 sebelum tier 9 (30 downline) → `locked_previous`, 0 ledger, CSV `NULL`; kartu: **tepat satu** tombol aktif |
| V17b | tier 30 setelah tier 9 diklaim (9 downline) → `not_qualified` + `missing=21` |
| V18/V19 | `locked_previous` → klaim 9 → klaim 30 → CSV `9,30`, 2 baris ledger, saldo +Rp 1.200.000, nol tombol aktif |
| V20 | `target` ∈ {`7`,`0`,`-1`,`abc`,`9.5`,`1e3`,`[]`} → `invalid_target` (7/7) |
| V21 | `enabled='0'` → kartu **tidak** dirender (kartu L1 tetap) + klaim **HTTP 403** `disabled` + 0 ledger + CSV `NULL`; `enabled='1'` → kartu kembali |
| V22 | user banned → redirect lockout (302/303), 0 ledger, CSV `NULL` |
| V23 | 6× klaim: hit 1–5 bisnis (200) → **hit 6 = HTTP 429** `too_many_attempts` |
| V24 | fixture 9 **trial** → `count_all_active_downlines = 0` (predikat lama = 9) → `not_qualified` (anti free-rider plan/116 utuh) |
| V25 | setelah semua kontrak kedaluwarsa: kartu tetap "Sudah diklaim" + replay → `already_claimed` + 0 baris baru (permanen, MK6) |
| V26 | `users.balance` == Σ ledger dompet `main`; `scripts/reconcile_balances.php --verify` → **0 drift** (exit 0) |
| V27 | jumlah baris `WAGE-%` **identik** sebelum/sesudah seluruh matriks (4); `/wallet` merender baris historis ber-idiom ("Gaji Mingguan Level 2" / "Weekly wage - level 2"); deskripsi MSB kanonik |
| V28 | `notif_wage_title/_body` masih ada di **kedua** kamus; baris `title_key='notif_wage'` (disintesis = setara data live) dirender "Gaji Mingguan Cair" (ID) / "Weekly Wage Credited" (EN) |
| V33 | fixture `--downlines=5/9/30` × `--product=paid/trial`: exit 0, idempoten, dua penghitung (berbayar vs lama) tercetak |

### 3.4 Admin settings (V29–V32, V35, V36)

```
V29 POST valid (tm_target 3/5/9/11)  → 303; key = '[[3,150000],[5,300000],[9,800000],[11,1500000]]';
    audit admin_update_settings memuat 'team_milestone_tiers' + before/after.
V30 POST invalid (duplikat/menurun/0/non-numerik/separuh/21 baris/nominal > batas/target > batas)
    → key TIDAK berubah (all-or-nothing) ×7 kasus; redirect + flash 'Validasi gagal'; form
      ter-repopulasi dengan nilai ketikan admin (value="9", value="0").
V31 tier yang sudah diklaim dihapus dari konfigurasi → tidak dirender lagi; replay → invalid_target;
    0 ledger; state CSV user tetap memuat '9'.
V32 toggle OFF → key '0' + audit + kartu member hilang; toggle ON → key '1' + audit + kartu kembali.
V35 tiers dinamik '[]' & '{"a":1}' → FAIL-SAFE ke fallback repo (5 tier, bukan fatal, tanpa error PHP).
V36 modal bantuan = loop dinamis (5 tier → 5 baris; konfigurasi 2 tier → 2 baris; nol nominal hardcoded).
```

### 3.5 Front-end (V37 — tanpa browser, lihat §5 A10)

```
$ node --check <setiap blok <script> inline dari /team & /admin/settings>
  team: 5/5 blok OK · admin_settings: 7/7 blok OK
$ node /tmp/repaint_test.js          # paintMilestones() dijalankan di Node (shim DOM + payload nyata)
  [PASS] total diupdate · bar 42% (30/70) · 4 baris · badge claimed
  [PASS] "Kurang 40 downline aktif" · tombol klaim hanya 1 · "Klaim Rp 1.000.000"
  [PASS] "Selesaikan tier sebelumnya lebih dulu" · nominal id-ID "Rp 2.500.000"
```

---

## 4. Berkas yang disentuh (plan/119)

**Baru**

| Berkas | Isi |
|---|---|
| `application/helpers/team_milestone_helper.php` | choke-point murni (parse/encode tier & CSV state, ambang, `transaction_id`) |
| `application/config/team_milestones.php` | fallback per-key (default = mirror tier lama) |
| `scripts/migrate_119_team_milestone.php` | migrasi 6 fase (DDL + seed + grandfathering + verify) |
| `scripts/seed_milestone_test_account.php` | `git mv` dari `seed_wage_test_account.php` + adaptasi |

**Dimodifikasi** — `application/models/User_model.php` ·
`application/controllers/Team.php` · `application/controllers/Admin.php` ·
`application/controllers/Checkin.php` (komentar) · `application/models/Checkin_model.php`
(komentar) · `application/views/team/index.php` · `application/views/admin/settings.php` ·
`application/config/autoload.php` · `application/helpers/i18n_helper.php` ·
`application/language/{english,indonesian}/app_lang.php` · `database.sql` ·
`database_seed.sql` · `docs/1_PRD.md` · `docs/2_ERD.md` · `docs/3_ROADMAP.md` ·
`plan/107_VIRAL_PROMOTIONAL_COPYWRITING_KIT.md` · `AGENTS.md` ·
`plan/119_TEAM_MILESTONE_BONUS_PLAN.md` (§19).

**Ditegaskan tidak disentuh:** `system/**` · `application/config/routes.php` ·
`views/templates/header.php` · `views/templates/bottom_nav.php` ·
`application/models/Wallet_model.php`.

---

## 5. Penyimpangan & catatan (ringkas; rincian di plan §19)

| # | Hal |
|---|---|
| **A1** | Nama state tier final: `claimed` / `claimable` / **`locked_count`** (butuh downline) / **`locked_previous`** (tier bawah belum) — `locked_previous` disatukan dengan `code` endpoint agar UI & API satu kosakata. |
| **A2** | Kamus **+27 / −28 = 650/650** (tambah `team_err_bonus_already` untuk jalur Bonus L1 yang tadinya memakai key "weekly"; `team_ms_none_yet` dibatalkan karena tak ber-konsumen). |
| **A5** | **V13:** sesi kosong di-redirect ke `/login` oleh guard `MY_Controller` **sebelum** controller (parity seluruh endpoint member) — cabang 401 di controller adalah defense-in-depth, tidak terjangkau HTTP. Perilaku ini **bukan** regresi. |
| **A6** | **V28:** seed repo memakai `title_key = NULL`, jadi retensi `notif_wage_*` diuji via key kamus + baris sintesis (setara data live). |
| **A7** | **V35:** konfigurasi dinamis kosong/rusak **fail-safe** ke fallback repo (bukannya kartu kosong) — itu memang desainnya; `team_ms_empty` tetap ada sebagai cabang defensif. |
| **A10** | **V37 belum diverifikasi dengan klik browser nyata** (lingkungan tanpa browser): yang dijalankan adalah parse JS (`node --check`) + uji fungsional `paintMilestones()` di Node. **Rekomendasi QA manual singkat**: buka `/team`, klaim satu tier, pastikan toast sukses + Balance Pill naik + daftar tier repaint tanpa reload. |
| **A11** | Sebagian kasus memakai **sesi CI3 yang dibentuk harness** (`user_id`/`admin_id`) alih-alih login penuh; login nyata (termasuk CAPTCHA) tetap dibuktikan pada matriks dasar + tiap fixture. |
| — | **Belum diverifikasi di jalur nyata:** `--apply` pada DB produksi (menunggu keputusan rilis owner), dan integrasi pembayaran apa pun (di luar lingkup). |

**Urutan rilis yang wajib dipatuhi** (bila disetujui owner): jalankan
`php scripts/migrate_119_team_milestone.php --apply` **sebelum** deploy kode plan/119
(backfill memakai konfigurasi tier **saat itu**), lalu bersihkan `application/logs/*.php`.

---

## 6. Pembersihan

| Artefak | Tindakan |
|---|---|
| DB buang `synapse119_tmp` | **DROP** (bukti uji sudah direkam di berkas ini) |
| Server uji `php -S 127.0.0.1:8099` | dihentikan (job background dimatikan) |
| Skrip harness (`/tmp/build_db.php`, `prep_legacy.php`, `http119.php`, `matrix119.php`, `jscheck.php`, `repaint_test.js`, `dbg*.php`, blok blok JS) | dihapus dari `/tmp` |
| Berkas sesi `ci_session*` & cookie jar di `/tmp` | dihapus |
| `application/logs/log-*.php` (artefak runtime uji) | dibersihkan |
| Scratch di dalam repo | **nol** (tidak pernah ada; semua scratch di `/tmp`) |
