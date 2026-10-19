# SUMMARY PLAN 120 — PENGHAPUSAN FITUR MISI LEVEL 1

- **Branch (disarankan):** `feat/120-remove-level1-mission`
- **Status:** **SELESAI** — seluruh direktif E1–E12 dijalankan; lint, kedua gate i18n,
  dan uji HTTP hidup pada **DB buang** tervalidasi
- **Rencana:** `plan/120_LEVEL1_MISSION_REMOVAL_PLAN.md`
- **Keputusan owner:** `dec-fc8726a949157eb0` (model scope **A** · endpoint **A** · retensi i18n **A**)
- **Catatan:** `db_webtable` (DB lokal milik owner) **TIDAK DISENTUH** — seluruh uji memakai
  DB buang `synapse120_tmp` (MariaDB 12.3.2, `127.0.0.1`), sudah **di-drop** setelah selesai

---

## 1. Ringkasan perubahan

Fitur **Misi Level 1** (bonus one-time Rp 80.000; 3 downline B aktif + omset B ≥ Rp 330.000)
**dihentikan di lapis aplikasi**. Sejak plan/120 **Bonus Pencapaian Tim (plan/119) adalah
SATU-SATUNYA reward pencapaian agensi**. **Nol DDL** (tidak ada `DROP COLUMN`, tidak ada
`ALTER TABLE`, tidak ada migration script) — kolom `users.is_level_1_claimed` dipertahankan
sebagai retensi historis.

| Lapis | Realisasi |
|---|---|
| **View** (`views/team/index.php` 834 → 729 baris) | Kartu misi `<section>` (50 baris) + blok bantuan L1 (10 baris) + `L1_CLAIM_LABEL` + 2 entri `I18N` + `claimLevel1()` (34 baris) **dihapus**; tombol bantuan `mt-3` → `mt-4` |
| **Controller** (`Team.php` 410 → 327 baris) | `claim_level1()` (54 baris) + `_claim_message()` (23 baris) **dihapus**; injeksi `claim_data`/`l1_bonus`/`l1_bonus_fmt` dipurg; 3 komentar rujukan `claim_level1` dirapikan |
| **Model** (`User_model.php` 900 → 786 baris) | `const LEVEL1_BONUS` + docblock, `get_claim_data()`, `claim_level1()` **dihapus** (106 baris); header seksi PHASE 9B ditulis ulang sebagai catatan deprecation |
| **i18n** | **−17 key** di **kedua** idiom → **633/633** (paritas 1:1) |
| **Skema** | `database.sql` **hanya komentar** `-- DEPRECATED (plan/120)` di atas `is_level_1_claimed`; `database_seed.sql` & `scripts/seed_*` **tidak berubah** |
| **Dokumentasi** | PRD **v5.5**, ERD **v5.5** (+ invarian L1R1–L1R6 di §7), ROADMAP (7B3 SUPERSEDED / 8C1 REMOVED / seksi plan/120), `AGENTS.md` (kunci kamus 633/633 + catatan plan/120) |

---

## 2. Berkas yang diubah (6 kode/skema + 4 dokumen)

| # | Berkas | Perubahan |
|---|---|---|
| 1 | `application/views/team/index.php` | −96 baris (V1–V7), `mt-3`→`mt-4` |
| 2 | `application/controllers/Team.php` | −83 baris (C1–C5) + 3 komentar |
| 3 | `application/models/User_model.php` | −114 baris (M1–M4) |
| 4 | `application/language/english/app_lang.php` | −19 baris (17 key + 2 baris komentar usang) |
| 5 | `application/language/indonesian/app_lang.php` | −19 baris (17 key + 2 baris komentar usang) |
| 6 | `database.sql` | +8 baris **komentar** (nol DDL) |
| 7 | `docs/1_PRD.md` | v5.5: §G (L1 → REMOVED), glosarium, tabel dompet, aktor, tipe notifikasi, catatan rilis |
| 8 | `docs/2_ERD.md` | v5.5: annotasi Mermaid + §2 (kolom DEPRECATED) + §7 invarian **L1R1–L1R6** |
| 9 | `docs/3_ROADMAP.md` | 7B3 **SUPERSEDED**, 8C1 **REMOVED**, catatan plan/116, seksi plan/120 |
| 10 | `AGENTS.md` | kunci kamus **633/633**, PRD/ERD v5.5, catatan plan/120, koreksi catatan plan/116/119 |

**Tidak tersentuh (diverifikasi via mtime + `git diff`):** `Rental_model.php` (07 Okt),
`Promoter_model.php` (12 Sep), `Wallet_model.php` (07 Okt), `Admin_model.php` (07 Okt),
`i18n_helper.php` (07 Okt), `routes.php` (20 Sep), `database_seed.sql`, `scripts/seed_*`,
`system/`, `views/templates/*`, `views/admin/*`.

---

## 3. i18n — 17 key dihapus (650 → 633/633)

`team_mission_l1`, `team_once_lifetime`, `team_active_agents`, `team_team_turnover`,
`team_done_claimed`, `team_claim_bonus_btn`, `team_req_not_met`, `team_claim_l1_full`,
`team_js_claimed_bonus`, `team_js_claim_success`, `team_help_l1_title`, `team_help_l1_body`,
`team_help_l1_li1`, `team_help_l1_li2`, `team_help_l1_note`, `team_err_bonus_already`,
`team_err_not_qualified`.

**Dipertahankan (retensi):** `ledger_bonus_l1` + pola `i18n_ledger_description()`,
`notif_bonus_l1_title`/`_body`, `team_js_claim_failed`, `team_js_network_error`,
`team_err_user_unavailable` (dipakai milestone & promotor).

---

## 4. Bukti verifikasi (perintah + hasil teramati)

### 4.1 Statis & gate

| # | Perintah | Hasil |
|---|---|---|
| V-1 | `php -l` pada **semua** 109 berkas PHP `application/**` (kecuali `logs/`) | **exit 0** — `No syntax errors detected` untuk kelima berkas yang diubah |
| V-2 | `grep -rnE "claim_level1\|LEVEL1_BONUS\|get_claim_data\|is_level_1_claimed\|l1_bonus\|m_claimed" application/ \| grep -vE ":[0-9]+: *//"` | **0 baris** (nol simbol mati; satu-satunya sisa adalah komentar dokumentasi plan/120 di `User_model`) |
| V-3 | `grep -rn "_claim_message" application/` | **0 baris** |
| V-4 | `grep -rn "is_level_1_claimed" application/` | **0 baris** (kolom hanya hidup di `database*.sql` + `scripts/seed_*`) |
| V-5 | `grep -n "330000\|330\.000" application/views/team/index.php` | **0 baris** |
| V-6 | `php scripts/audit_i18n_parity.php` | **exit 0** — `EN 633 / ID 633`, `Paritas 1:1 OK`, `Nilai identik 0`, `P3 0`, `P6 0`, `P3b 0` → **LULUS** |
| V-7 | `php scripts/audit_i18n_hardcoded.php` | **exit 0** — 76 berkas dipindai, **0 temuan** |
| V-8 | `grep -cE "^[[:space:]]*\$lang\[" application/language/*/app_lang.php` | **633** dan **633** |

### 4.2 HTTP hidup (DB buang `synapse120_tmp`, dibangun dari `database.sql` → `database_seed.sql`)

Fixture: leader `id=1`; downline `id=2` dengan 1 kontrak aktif produk NON-trial;
`team_milestone_tiers='[[1,1000]]'`, `team_milestone_enabled='1'`.
Sesi member dibuat dengan menyuntik session file CI3 pada `sys_get_temp_dir()`.

| # | Skenario | Hasil teramati |
|---|---|---|
| V-11 | `GET /team` (member) | **200**, 50.956 byte; `id="ms-card"` **ada**; `btn-claim-l1` **0**; `team/claim_level1` **0**; teks "Misi Level 1" **0**; `330.000` **0**; `claimMilestone` JS **ada**; `Undefined variable`/`A PHP Error` **0** |
| V-12 | `GET /team`, `team_milestone_enabled='0'` | **200**; kartu misi **0**, `#ms-card` **0**, tombol bantuan terender dengan `mx-4 mt-4` (ritme atas tetap) |
| V-13 | `POST /team/claim_level1` (AJAX + CSRF) | **404** `application/json` → `{"success":false,"message":"Halaman tidak ditemukan.","code":"not_found"}` |
| V-14 | `POST /team/claim_milestone target=1` | **200** `{"success":true,"code":"claimed","data":{"amount":1000,"new_balance":1000,"transaction_id":"MSB-1-T1",…}}`; DB: 1 baris `wallet_ledger` `MSB-1-T1` `credit 1000.00` **`wallet_type=main`**, 1 baris `user_notifications` `title_key=notif_milestone` `type=commission`, `users.balance=1000.00`, `team_milestone_claimed='1'` |
| V-14b | `POST` ulang `target=1` | **200** `{"success":false,"code":"already_claimed"}` (idempotensi MK2 utuh) |
| V-15 | `POST target=999` (di luar konfigurasi) | **200** `{"success":false,"code":"invalid_target"}` (gerbang MK4 utuh) |
| V-16 | `GET /wallet` dengan baris ledger **historis** `L1-1-20260901120000` | **200**; deskripsi terender **`Level 1 bonus - 3 active agents + omzet >=330k`** (idiom EN) — **0** kemunculan teks Indonesia mentah; deskripsi `MSB-*` juga terterjemah (`Team milestone bonus - 1 active downlines`) ⇒ retensi `ledger_bonus_l1` terbukti |
| V-17 | `php scripts/reconcile_balances.php --verify` (DB buang) | **exit 0** — `Scanned users: 2`, `main` + `checkin`, **`Drifted accounts: 0`**, `Sum |delta|: Rp 0` → ledger immutability utuh |

### 4.3 Isolasi logika (L1R3) — bukti tidak ada yang rusak

- Empat predikat kanonik `gp.is_trial = 0` (plan/116 K8) **masih utuh**:
  `get_team_with_active_status()` ×2, `count_active_b_downlines()`, `count_all_active_downlines()`.
- `count_active_b_downlines()` (`User_model:124`) dan `sum_sales_b_downlines()` (`:144`)
  **tetap ada** (keputusan Q1/A) — kini primitif tanpa pemanggil, sesuai rencana.
- `count_all_active_downlines()` (`:169`), `get_team_with_active_status()` (`:85`),
  `claim_milestone()` (`:580`), `_milestone_result()` (`:752`) **tidak berubah** —
  dibuktikan perilakunya oleh V-14/V-14b/V-15 (jalur klaim milestone berfungsi penuh).
- Mtime `Rental_model`, `Promoter_model`, `Wallet_model`, `Admin_model`, `i18n_helper`,
  `routes.php` = **07 Okt / 12 Sep / 20 Sep** (jauh sebelum sesi ini) ⇒ **nol edit**.

---

## 5. Penyimpangan / addendum

1. **Koreksi hitungan kamus:** baseline terukur adalah **650/650** (bukan 651 seperti
   tertulis di `AGENTS.md`/ringkasan plan/118). Sesuai agent rule, angka diambil dari
   **hitungan berkas**; `AGENTS.md` kini mencatat rantai `… 639 → 650 → **633 after plan/120**`
   plus instruksi untuk selalu menghitung ulang.
2. **Versi dokumen dinaikkan** PRD `v5.3 → v5.5` dan ERD `v5.4 → v5.5` (PRD sebelumnya
   tertinggal satu versi dari catatan plan/118 yang sudah ada di badannya).
3. **Komentar rujukan `claim_level1` di `Team.php`** (3 tempat: pola rate limit, paritas
   notifikasi, pola pengajuan promotor) dirapikan agar tidak menunjuk method yang sudah
   dihapus — nol perubahan perilaku.
4. **`.reasonix/tasks/<job>/`** hasil background job verifikasi **dihapus** (artefak tooling,
   bukan konten repo); `db_webtable` dan seluruh DB buas sudah bersih.
5. **Harness uji tidak masuk repo:** skrip build DB + probe HTTP hidup di `/tmp`,
   dihapus setelah dipakai (AGENTS.md never-rule 8).

---

## 6. Definition of Done — checklist

- [x] Semua berkas PHP yang diubah lulus `php -l`; seluruh 109 berkas `application/**` lint-clean
- [x] Nol simbol mati (`claim_level1`/`LEVEL1_BONUS`/`get_claim_data`/`is_level_1_claimed`/`l1_bonus`)
- [x] Kamus **633/633**; kedua gate i18n **exit 0**
- [x] Retensi historis terbukti hidup (ledger `L1-*` + `notif_bonus_l1_*` dipertahankan)
- [x] `POST /team/claim_level1` → **404 JSON**; `GET /team` bersih dari kartu misi
- [x] **Plan 119 regresi lulus:** `claim_milestone` → `claimed` + `MSB-1-T1` + `already_claimed`
- [x] `reconcile_balances.php --verify` → drift 0
- [x] Nol DDL; `database_seed.sql` & `scripts/seed_*` tidak berubah
- [x] `docs/` + `AGENTS.md` tersinkron
- [x] DB buang di-drop, harness `/tmp` dihapus, nol scratch di repo
