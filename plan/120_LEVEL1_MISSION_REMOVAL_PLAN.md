# PLAN 120 — PENGHAPUSAN FITUR MISI LEVEL 1 (LEVEL 1 MISSION REMOVAL)

- **Branch:** `feat/120-remove-level1-mission` (roadmap rule 4: satu branch per fase)
- **Status:** PLAN — **DISETUJUI OWNER**; eksekusi **menunggu perintah lanjutan**
  (roadmap rule 7 — Milestone Lock; plan ini **belum** dieksekusi, nol kode diubah)
- **Basis keputusan owner:** `dec-fc8726a949157eb0`
  (model scope **A** · endpoint **A** · i18n retensi **A** — lihat §0.1)
- **Tipe perubahan:** **penghapusan murni** — view member (`/team`) + controller
  (`Team`) + model (`User_model`) + kamus EN/ID (−17 key) + sinkronisasi `docs/`
  + anotasi komentar `database.sql`. **Tanpa DDL** (nol `DROP`, nol `ALTER`,
  nol migration script), **tanpa tabel/index baru**, **tanpa entri `routes.php`**,
  **tanpa perubahan `system/`**, **tanpa perubahan `views/templates/header.php`**
  (invarian plan/100 utuh), **tanpa mutasi `wallet_ledger`/`user_notifications`**.
- **Non-goal mutlak:** kolom `users.is_level_1_claimed` **TIDAK DIHAPUS**
  (retensi data historis — mandat owner, §8).

---

## 0. Ringkasan, Keputusan & Invarian

Fitur **Misi Level 1** (bonus one-time Rp 80.000; syarat 3 downline B aktif +
omset B ≥ Rp 330.000; flag idempotensi `users.is_level_1_claimed`) **dihentikan
sepenuhnya dari sisi aplikasi**: kartu misi di `/team`, tombol klaim, endpoint
JSON `POST /team/claim_level1`, method model yang mengkredit ledger, dan seluruh
copy-nya di kamus dwibahasa.

Fitur ini **sudah tidak lagi menjadi tulang punggung reward agensi**: sejak
**plan/119** reward berulang digantikan **Bonus Pencapaian Tim** yang memakai
mesin terpisah (`count_all_active_downlines()` + `team_milestone_claimed` +
`system_settings.team_milestone_*`). Plan/119 secara eksplisit **mempertahankan**
Bonus Level 1 sebagai jalur independen (keputusan O1). Plan/120 **mencabut
keputusan O1 tersebut**: tidak ada lagi bonus one-time berbasis 3 downline B.

### 0.1 Keputusan owner (`dec-fc8726a949157eb0`)

| # | Keputusan | Pilihan owner | Konsekuensi |
|---|---|---|---|
| Q1 | Scope model | **A** — hapus `get_claim_data()` + `claim_level1()` + `const LEVEL1_BONUS`; **PERTAHANKAN** `count_active_b_downlines()` + `sum_sales_b_downlines()` | Invariant plan/116 (4 titik sinkronisasi) tetap valid apa adanya; primitif hitung tetap tersedia sebagai mesin reusable, nol kontradiksi dokumen |
| Q2 | Endpoint `POST /team/claim_level1` | **A** — hapus method `Team::claim_level1()` seluruhnya → URL 404 | Paritas preseden plan/119 (`claim_wage()` dihapus, bukan di-stub); JS basi menerima 404 JSON lewat `MY_Exceptions::_wants_json()` |
| Q3 | Retensi i18n notifikasi | **A** — **PERTAHANKAN** `notif_bonus_l1_title`/`_body` | Baris historis `user_notifications.title_key='notif_bonus_l1'` tetap dwibahasa (paritas retensi `notif_wage_*` plan/119) |

### 0.2 Invarian plan/120 (didaftarkan saat eksekusi)

| Kode | Invarian |
|---|---|
| **L1R1** | `users.is_level_1_claimed` **DIPERTAHANKAN** sebagai retensi historis; **nol** pembaca/penulis baru di `application/` |
| **L1R2** | **Tidak ada** jalur kredit bonus Rp 80.000 lagi; satu-satunya penulis bonus = `User_model::claim_milestone()` (`MSB-*`, `wallet_type='main'`) |
| **L1R3** | `count_all_active_downlines()` / `get_team_with_active_status()` / `Rental_model::has_paid_rental()` / `_distribute_rebate()` **tidak disentuh satu baris pun** (plan/116 K8 & plan/89–92 utuh) |
| **L1R4** | Baris `wallet_ledger` `L1-*` dan baris `user_notifications` `notif_bonus_l1` **tidak dimutasi**; deskripsi ledger historis tetap diterjemahkan (`ledger_bonus_l1` + pola `i18n_helper`) |
| **L1R5** | Kamus EN ≡ ID **1:1** pasca-penghapusan: **633/633** key (dari 650/650) |
| **L1R6** | Nol DDL: `database.sql` hanya menerima **komentar** `-- DEPRECATED (plan/120)` di atas kolom; `database_seed.sql` & fixtures **tidak berubah** |

---

## 1. Fakta repositori yang terverifikasi

1. **Kamus saat ini 650/650 key** (bukan 651 — angka `AGENTS.md`/ringkasan plan
   sebelumnya sudah basi; agent rule: **hitung filenya**). Diukur via
   `grep -cE "^[[:space:]]*\$lang\["` → `650` di kedua idiom, dan
   `php -r 'include …; echo count($lang);'` → `EN=650`, `ID=650`.
2. **Titik sentuh fitur hanya di 5 berkas kode**: `views/team/index.php`,
   `controllers/Team.php`, `models/User_model.php`, dan 2 kamus bahasa.
3. **Panel admin NOL referensi**: `grep -rniE "level 1|level1|bonus l1"` atas
   `application/views/admin/`, `Admin.php`, `Admin_model.php` → kosong.
4. **`routes.php` NOL entri** untuk fitur ini: `team/claim_level1` bekerja
   sebagai **direct method URL**. Tidak ada rute yang perlu dihapus.
5. **`is_level_1_claimed` pembaca/penulis tunggal** di `application/`:
   `User_model::get_claim_data()` (`:569`) dan `User_model::claim_level1()`
   (`:597`, `:615`, `:621-622`). Setelah dua method itu dihapus → **nol**.
   Sisanya hanya `database.sql:31`, `database_seed.sql:22/84/101`,
   `scripts/seed_database.php:281`, `seed_milestone_test_account.php:227/244`,
   `seed_withdraw_test_account.php:139` — **semuanya tetap** karena kolom tetap.
6. **`count_active_b_downlines()` / `sum_sales_b_downlines()` hanya dipanggil**
   oleh `get_claim_data()` dan `claim_level1()` (`User_model:567-568`,
   `:607-608`). Keduanya **DIPERTAHANKAN** (keputusan Q1/A) sehingga setelah
   plan/120 keduanya menjadi primitif tanpa pemanggil — **sengaja**, agar
   dokumentasi plan/116 & `Rental_model::has_paid_rental()` docblock
   (`:1016-1022`) tetap benar.
7. **`count_all_active_downlines()` adalah otoritas tier milestone (MK1/K8)** —
   dipanggil `get_milestone_status()` (`:490`) dan `claim_milestone()` (`:769`).
   **Tidak disentuh.**
8. **Key kamus yang masih dipakai jalur lain** (jangan ikut terhapus):
   `team_js_claim_failed` (`views/team/index.php:770`),
   `team_js_network_error` (`:775`, `:831`),
   `team_err_user_unavailable` (`_milestone_message()` + `Promoter_model:676`),
   `team_how_bonus`, `team_help_title`, `team_help_active_*`.
9. **Retensi ledger**: `i18n_helper.php:438-440` memetakan pola
   `/^Bonus Level 1\b/u` → `ledger_bonus_l1`. Baris `wallet_ledger` historis
   ber-`transaction_id` `L1-{user_id}-{YmdHis}` WAJIB tetap terbaca
   (invarian L1R4).
10. **Preseden anotasi kolom**: `database.sql:32-37` sudah memakai pola
    `-- DEPRECATED (plan/119): … DIPERTAHANKAN sebagai retensi historis …`
    untuk `last_wage_claimed_at`. Plan/120 memakai pola identik.

---

## 2. Audit kode saat ini — rantai fitur

### 2.1 Alur hari ini

```
GET /team
  └─ Team::index()                                   controllers/Team.php:16-105
       ├─ $claim_data = User_model::get_claim_data()  :60-61  (models/User_model.php:563-581)
       │    ├─ count_active_b_downlines()             :567   → syarat "3 agen"
       │    └─ sum_sales_b_downlines()                :568   → syarat "omset 330.000"
       └─ 'l1_bonus' / 'l1_bonus_fmt' = LEVEL1_BONUS  :89-91
  └─ views/team/index.php
       ├─ :1-22   variabel kartu ($m_agent/$m_turnover/$m_claimed)
       ├─ :23-72  <section> KARTU MISI LEVEL 1 (progress + tombol 3-state)
       ├─ :499-508 blok "Level 1" di modal bantuan
       └─ :538-654 const L1_CLAIM_LABEL + I18N entries + claimLevel1()

POST /team/claim_level1 (AJAX)
  └─ Team::claim_level1()                            controllers/Team.php:116-159
       ├─ User_model::claim_level1()                 models/User_model.php:589-660
       │    ├─ SELECT … is_level_1_claimed FOR UPDATE  :596-599
       │    ├─ re-verify 3 downline + omset ≥ 330rb    :607-608
       │    ├─ UPDATE users SET is_level_1_claimed = 1  :621-622  (kondisional, M4)
       │    └─ Wallet_model::credit(80.000, 'L1-…')    :632-638
       └─ Notification_model::insert_keyed('notif_bonus_l1')  :143-148
```

### 2.2 Yang **tidak** boleh tersentuh (review gate)

| Simbol | Lokasi | Alasan |
|---|---|---|
| `count_all_active_downlines()` | `User_model:178-195` | MK1 — otoritas tier Bonus Pencapaian Tim (plan/119) & K8 plan/116 |
| `get_milestone_config()` / `get_milestone_status()` / `claim_milestone()` / `_milestone_result()` | `User_model:229-248`, `:484-554`, `:695-855`, `:867-885` | Mesin Bonus Pencapaian Tim |
| `get_team_with_active_status()` | `User_model:86-103` | Badge "Aktif" `/team` + K8 |
| `Rental_model::has_paid_rental()` & `_distribute_rebate()` | `Rental_model:1027+`, blok rebate | plan/114 D-A & rebate berjenjang plan/89–92 |
| `Team::claim_milestone()` / `_milestone_message()` | `Team.php:177-268`, `:392-409` | Endpoint + peta pesan F18 |
| `Promoter_model::localize_result()` | `Promoter_model:676` | Pemakai `team_err_user_unavailable` |
| `i18n_ledger_description()` + pola `Bonus Level 1` | `i18n_helper:423-470` | Retensi ledger historis (L1R4) |

---

## 3. Perubahan View — `application/views/team/index.php` (834 baris)

Enam blok dihapus, semuanya **self-contained** (tidak ada pembungkus `<div>`
yang dipakai bersama):

| Ref | Baris | Isi | Aksi |
|---|---|---|---|
| **V1** | **1**, **8** | komentar `<!-- ===== MISSION CARD: LEVEL 1 BONUS ===== -->` | hapus |
| **V2** | **3-7** | `$m_agent`, `$m_turnover`, `$m_claimed`, `$m_agent_pct`, `$m_turnover_pct` | hapus — **pertahankan** `<?php` (L2), blok milestone `$ms_*` (L9-21), dan `?>` (L22) |
| **V3** | **23-72** | `<section class="mx-4 mt-4 u-card-fin …">` kartu misi: header 🎯 + chip `Rp 80.000`, progress "Agen Aktif / 3", progress "Omset Tim / 330.000", tombol 3-state (`btn-claim-l1`, `claimLevel1()`, `team_req_not_met`) | hapus **satu section utuh** |
| **V4** | **499-508** | blok `<!-- Level 1 -->` di modal bantuan: `team_help_l1_title`, `sprintf(team_help_l1_body, $l1_bonus_fmt)`, `team_help_l1_li1`, `sprintf(team_help_l1_li2, 330000)`, `team_help_l1_note` | hapus **satu blok `<div>` utuh** (blok milestone sesudahnya tetap) |
| **V5** | **538-539** | `const L1_CLAIM_LABEL = json_encode(sprintf(lang('team_claim_l1_full'), $l1_bonus_fmt))` | hapus |
| **V6** | **542-543** | entri peta `I18N`: `claimed_bonus`, `claim_success` | hapus — **pertahankan** `claim_failed` (L544) & `network_error` (L545) |
| **V7** | **622-654** | komentar `// ═══ Claim AJAX ═══` + `function claimLevel1() { … csrfFetch('team/claim_level1') … }` | hapus — **pertahankan** blok plan/119 mulai L656 (`msTpl`, `msEsc`, `msIdr`, `paintMilestones`, `claimMilestone`) |

### 3.1 Catatan tata letak (Tailwind)

- Kartu misi adalah **section pertama** view; `templates/header.php` dan
  `templates/bottom_nav.php` dimuat terpisah oleh controller → **tidak ada
  kontainer yang rusak** saat section dihapus.
- Saat `team_milestone_enabled = '1'` kartu milestone (`#ms-card`, `mx-4 mt-4`)
  langsung menjadi elemen pertama → spasi atas **identik**.
- Saat `team_milestone_enabled = '0'` kartu milestone **tidak dirender**
  (`views/team/index.php:75`), sehingga elemen pertama menjadi tombol bantuan
  `mx-4 mt-3` (**L148**). **Aksi:** naikkan ke `mt-4` agar ritme vertikal tidak
  berubah, lalu verifikasi visual 360 px (matriks V-9).
- Nol `id`/`<style>` baru; nol animasi baru (tidak ada kewajiban
  `prefers-reduced-motion`).

---

## 4. Perubahan Controller — `application/controllers/Team.php` (410 baris)

| Ref | Baris | Isi | Aksi |
|---|---|---|---|
| **C1** | **60-61** | komentar `// Phase 9B: claim data (Misi Level 1 …)` + `$claim_data = $this->User_model->get_claim_data($user_id);` | hapus |
| **C2** | **86** | `'claim_data' => $claim_data,` (di array `$data`) | hapus |
| **C3** | **89-91** | komentar P5 + `'l1_bonus' => User_model::LEVEL1_BONUS`, `'l1_bonus_fmt' => number_format(...)` | hapus |
| **C4** | **107-159** | docblock + `public function claim_level1()` (AJAX-only, 401, `api_error`/`api_success`, `insert_keyed('notif_bonus_l1')`) | hapus **method utuh** |
| **C5** | **359-380** | docblock + `private function _claim_message(array $result, $fallback_key)` | hapus **method utuh** (pemanggil tunggal = C4) |

**Tidak disentuh:** `__construct()`, seluruh `index()` lainnya (gating referral
plan/89, promotor plan/91, `rebate_*`), `claim_milestone()` (incl. rate limit
`milestone_claim:`), `promoter_claim()`, `_format_wa_phone()`,
`_milestone_message()`.

Pasca-perubahan: `$data` untuk view `/team` memuat `claim_data`? **tidak lagi** —
view juga tidak lagi membacanya (V2/V3 dihapus bersamaan). Tidak ada
`undefined variable` (PHP 8.3 warning) karena setiap pemakaian `$l1_bonus_fmt`
(L30, L64, L502, L539) berada di dalam blok yang dihapus.

---

## 5. Perubahan Model — `application/models/User_model.php` (900 baris)

| Ref | Baris | Isi | Aksi |
|---|---|---|---|
| **M1** | **8** | komentar konstruktor `// C4 (plan/54): claim_level1/claim_milestone/get_claim_data/…` | **edit** teks: buang `claim_level1` & `get_claim_data`, sebut plan/120 |
| **M2** | **109-122** | docblock `Level 1 one-time bonus (PRD v5: Rp 80.000 …)` + `const LEVEL1_BONUS = 80000;` | hapus docblock + konstanta (blok `PHASE 9B` header `:105-107` disesuaikan menjadi **"Bonus Pencapaian Tim"** saja) |
| **M3** | **556-581** | docblock + `public function get_claim_data($user_id)` (mengembalikan `level1_eligible`, `level1_claimed`, `active_b_count`, `sales_b`, `balance`) | hapus **method utuh** |
| **M4** | **583-660** | docblock + `public function claim_level1($user_id)` (TX + `FOR UPDATE` + `is_level_1_claimed` + `Wallet_model::credit` `L1-…`) | hapus **method utuh** |

**DIPERTAHANKAN verbatim (keputusan Q1/A + L1R3):**

| Simbol | Baris | Catatan |
|---|---|---|
| `get_user_by_id`, `get_downlines`, `update_user` | `:35-84` | dipakai luas |
| `get_team_with_active_status()` | `:86-103` | K8 / badge `/team` |
| `count_active_b_downlines()` | `:133-145` | **primitif** (tanpa pemanggil pasca-plan/120, dokumentasi plan/116 tetap benar) |
| `sum_sales_b_downlines()` | `:153-164` | idem |
| `count_all_active_downlines()` | `:178-195` | **MK1** — otoritas tier milestone |
| blok milestone `get_milestone_config()` … `_milestone_result()` | `:197-885` | plan/119 |

Pasca-perubahan: **nol** kemunculan `is_level_1_claimed` di `application/`
(invarian L1R1) dan **nol** jalur kredit Rp 80.000 (invarian L1R2).

---

## 6. i18n — kamus EN/ID (`application/language/{english,indonesian}/app_lang.php`)

Baseline terukur: **650 key** per idiom. Penghapusan **17 key × 2 idiom** →
**633/633** (identik, invarian L1R5). Kedua berkas **wajib** diedit dalam satu
pass agar gate P1 (`himpunan key EN ≡ ID`) tidak pernah merah.

### 6.1 DIHAPUS (17 key — terverifikasi nol konsumen lain)

| Key | Satu-satunya perender |
|---|---|
| `team_mission_l1` | judul kartu (V3) |
| `team_once_lifetime` | subjudul kartu (V3) |
| `team_active_agents` | label progress agen (V3) |
| `team_team_turnover` | label progress omset (V3) |
| `team_done_claimed` | chip state "sudah diklaim" (V3) |
| `team_claim_bonus_btn` | label tombol klaim (V3) |
| `team_req_not_met` | label tombol disabled (V3) |
| `team_claim_l1_full` | `L1_CLAIM_LABEL` JS (V5) |
| `team_js_claimed_bonus` | `claimLevel1()` callback sukses (V6) |
| `team_js_claim_success` | `claimLevel1()` toast sukses (V6) |
| `team_help_l1_title` | modal bantuan (V4) |
| `team_help_l1_body` | modal bantuan (V4) |
| `team_help_l1_li1` | modal bantuan (V4) |
| `team_help_l1_li2` | modal bantuan (V4) |
| `team_help_l1_note` | modal bantuan (V4) |
| `team_err_bonus_already` | peta `_claim_message()` (C5) — **tanpa** konsumen lain |
| `team_err_not_qualified` | peta `_claim_message()` (C5) — **tanpa** konsumen lain |

### 6.2 DIPERTAHANKAN (jangan ikut terhapus)

| Key | Alasan |
|---|---|
| `team_js_claim_failed` | fallback JS milestone `views/team/index.php:770` |
| `team_js_network_error` | catch milestone (775) & promotor (831) |
| `team_err_user_unavailable` | `_milestone_message()` **dan** `Promoter_model:676` |
| `team_how_bonus`, `team_help_title`, `team_help_active_downline`, `team_help_active_body` | modal bantuan tetap hidup (kini fokus milestone + aturan downline aktif) |
| `notif_bonus_l1_title`, `notif_bonus_l1_body` | **retensi** (Q3/A): baris `user_notifications.title_key='notif_bonus_l1'` historis tetap dwibahasa |
| `ledger_bonus_l1` | **retensi** (L1R4): deskripsi ledger historis `L1-*` |

### 6.3 Gate

- `php scripts/audit_i18n_parity.php` → `LULUS` (P1 paritas, P3 tanpa nominal
  literal, P5 allowlist tidak berubah — tak satu pun key yang dihapus ada di
  `$identicalAllowlist`).
- `php scripts/audit_i18n_hardcoded.php` → `0 temuan` (penghapusan markah hanya
  mengurangi kandidat; **bersihkan `application/logs/*.php` lebih dulu** —
  gate ini memindai seluruh `application/**`).
- `scripts/migrate_103_notification_i18n.php` menyebut `'notif_bonus_l1'`
  (`:129`, `:321`) → **jangan diubah** (migrasi historis, idempoten, sudah
  diterapkan).

---

## 7. Retensi data historis (tanpa mutasi)

| Artefak | Perlakuan | Bukti aman |
|---|---|---|
| Kolom `users.is_level_1_claimed` | **tetap** (Q1 & mandat owner) | `database.sql` hanya menerima komentar deprecation |
| Baris `user_notifications` `title_key='notif_bonus_l1'` | **tetap**, tetap dirender | key kamus dipertahankan (§6.2); plus snapshot `title`/`message` tertulis oleh `Notification_model::insert_keyed()` (`:96-118`) sebagai lapis kedua |
| Baris `wallet_ledger` `L1-{uid}-{YmdHis}` | **tetap**, deskripsi tetap diterjemahkan | pola `i18n_ledger_description()` `:438-440` dipertahankan; nol `DELETE`/`UPDATE` |
| Tabel `rate_limits` | tidak relevan — `claim_level1` tidak pernah memakai rate limit | — |
| `scripts/seed_*` & `database_seed.sql` | **tidak berubah** | kolom tetap ada; daftar kolom INSERT tetap valid |

---

## 8. Skema — `database.sql` (komentar saja, NOL DDL)

Di atas `:31` (`is_level_1_claimed`), tambahkan blok komentar mengikuti pola
`last_wage_claimed_at` (`:32-37`):

```
-- DEPRECATED (plan/120): flag klaim "Misi Level 1" (bonus one-time Rp 80.000 —
-- 3 downline B aktif + omset ≥ Rp 330.000). FITUR DIHENTIKAN di lapis
-- aplikasi (view/controller/model dihapus); kolom DIPERTAHANKAN sebagai
-- retensi historis — nol pembaca/penulis baru. Baris `wallet_ledger`
-- historis ber-prefix `L1-` dan baris `user_notifications`
-- `title_key='notif_bonus_l1'` juga dibiarkan utuh (invariant L1R4).
`is_level_1_claimed` TINYINT(1) NOT NULL DEFAULT 0,
```

**Dilarang:** `DROP COLUMN`, `ALTER TABLE`, dan pembuatan
`scripts/migrate_120_*.php` (nol perubahan skema ⇒ nol migrasi; roadmap rule 6
hanya berlaku bila DDL berubah).

---

## 9. Sinkronisasi dokumentasi

| Berkas | Titik | Perubahan |
|---|---|---|
| `docs/1_PRD.md` | `:28`, `:46`, `:82`, `:152` | hapus klaim "**Bonus Level 1 (Rp 80.000) tetap independen**"; reward agensi = **Bonus Pencapaian Tim** saja |
| | `:265` | klausa "`count_active_b_downlines()` (syarat 3 downline bonus Level 1)" → tandai **legacy tanpa pemanggil** (method tetap ada) |
| | `:267` | butir "Bonus Level 1 (TETAP INDEPENDEN, O1)" → **DIHENTIKAN (plan/120)** |
| | `:270-279` | bagian "Zero-Trust Level 1 Bonus Claim (v5.0)" → tandai **REMOVED (plan/120)**; endpoint `POST /team/claim-level1` sudah 404 |
| | `:318` | contoh tipe notifikasi `notif_bonus` → anotasi "historis (plan/120)" |
| `docs/2_ERD.md` | `:77`, `:274` | `is_level_1_claimed` → `DEPRECATED (plan/120)` — kolom dipertahankan, nol pemakai |
| `docs/3_ROADMAP.md` | `:97`, `:135` | tandai butir **7B3** sebagai **SUPERSEDED oleh plan/120**; tambah entri fase plan/120 |
| `AGENTS.md` | Notes plan/116 & plan/119 | hapus "Bonus Level 1 tetap independen (O1)"; catat plan/120: fitur dihapus, kolom retensi, `count_active_b_downlines()`/`sum_sales_b_downlines()` jadi primitif tanpa pemanggil, kamus 650 → **633/633** |

---

## 10. Directives eksekusi (E1 … E12)

| # | Direktif |
|---|---|
| **E1** | Branch `feat/120-remove-level1-mission` dari kondisi worktree saat ini (memuat plan/117–119 yang belum di-commit). Jangan commit/ revert pekerjaan owner. |
| **E2** | View: hapus V1–V7 (§3) **dalam satu edit**; jangan sentuh `$ms_*` (L9-21), `#ms-card` (L76-144), blok bantuan milestone (L509-528), JS plan/119 (L656+). |
| **E3** | View: tombol bantuan (L148) `mt-3` → `mt-4`; verifikasi visual lebar 360 px dengan `team_milestone_enabled='0'` dan `'1'`. |
| **E4** | Controller: hapus C1–C5 (§4); pastikan `_milestone_message()` & `claim_milestone()` **byte-identik** sebelum/sesudah. |
| **E5** | Model: hapus M2–M4 (§5), edit M1; `count_active_b_downlines()` & `sum_sales_b_downlines()` **wajib tetap** (diff harus kosong untuk `:133-195`). |
| **E6** | i18n: hapus 17 key di **kedua** kamus (§6.1); jangan sentuh daftar §6.2. |
| **E7** | `database.sql`: tambah komentar deprecation (§8). **Nol DDL, nol migration script.** |
| **E8** | `docs/` + `AGENTS.md` sesuai §9; tulis `plan/120_LEVEL1_MISSION_REMOVAL_SUMMARY.md` berisi tabel perubahan + bukti verifikasi nyata. |
| **E9** | `php -l` pada **setiap** berkas PHP yang diubah/modifikasi. |
| **E10** | Jalankan kedua gate i18n; keduanya wajib `exit 0`. |
| **E11** | Uji HTTP hidup pada **DB buang** (`synapse120_tmp`, dibangun dari `database.sql` → `database_seed.sql`); **`db_webtable` tidak boleh disentuh**; drop DB setelah selesai. |
| **E12** | Review diff akhir: hanya perubahan yang dimaksud, nol artefak sementara di repo (skrip probe di `/tmp`, dihapus setelah dipakai). |

---

## 11. Matriks verifikasi

### 11.1 Statis & gate wajib

| # | Perintah | Ekspektasi |
|---|---|---|
| V-1 | `php -l application/views/team/index.php` + 4 berkas lain | `No syntax errors detected` (5/5) |
| V-2 | `grep -rn "claim_level1\|LEVEL1_BONUS\|get_claim_data\|m_claimed\|l1_bonus" application/` | **0 baris** |
| V-3 | `grep -rn "_claim_message" application/` | **0 baris** |
| V-4 | `grep -rn "is_level_1_claimed" application/` | **0 baris** (kolom hanya di `database*.sql` + `scripts/seed_*`) |
| V-5 | `grep -rn "300\.000\|330000" application/views/team/index.php` | **0 baris** |
| V-6 | `php scripts/audit_i18n_parity.php` | `LULUS`, exit 0 |
| V-7 | `php scripts/audit_i18n_hardcoded.php` | `0 temuan`, exit 0 |
| V-8 | `grep -cE "^[[:space:]]*\\\$lang\[" application/language/*/app_lang.php` | **633** dan **633** |

### 11.2 Retensi historis

| # | Perintah | Ekspektasi |
|---|---|---|
| V-9 | CLI `/tmp`: `i18n_ledger_description('Bonus Level 1 — 3 Agen Aktif + Omset ≥330rb')` untuk `site_lang=en` & `id` | keduanya mengembalikan teks terjemahan, bukan string mentah |
| V-10 | `grep -n "notif_bonus_l1_title\|ledger_bonus_l1" application/language/*/app_lang.php application/helpers/i18n_helper.php` | masih ada di **kedua** kamus + helper |

### 11.3 HTTP (DB buang `synapse120_tmp`)

| # | Skenario | Ekspektasi |
|---|---|---|
| V-11 | `GET /team` (member login) | **200**; body memuat `id="ms-card"`; **tidak** memuat `btn-claim-l1`, `team/claim_level1`, `Misi Level 1`, `330.000` |
| V-12 | `GET /team` saat `team_milestone_enabled='0'` | **200**; kartu misi & kartu milestone sama-sama absen; tata letak atas tetap rapi (bukti E3) |
| V-13 | `POST /team/claim_level1` (AJAX, CSRF valid) | **404 JSON** (`success:false`), bukan HTML |
| V-14 | `POST /team/claim_milestone` `target=<tier tercapai>` | **200** `{success:true, code:"claimed"}` + 1 baris `wallet_ledger` `MSB-{uid}-T{target}` (`wallet_type='main'`) + 1 baris `notif_milestone`; ulangi → `already_claimed` — **bukti Plan 119 utuh** |
| V-15 | `POST /team/claim_milestone` `target=<tier lebih tinggi, downline kurang>` | **200** `{success:false, code:"locked_previous"\|"not_qualified"}` dengan pesan ter-i18n |
| V-16 | `GET /wallet` (DB uji berisi baris ledger `L1-*` historis) | deskripsi baris tetap tampil terjemahan, bukan mentah |
| V-17 | `php scripts/reconcile_balances.php --verify` | drift **0** per dompet (`main` & `checkin`) |

### 11.4 Audit diff

| # | Perintah | Ekspektasi |
|---|---|---|
| V-18 | `git diff` pada `User_model.php`, `Rental_model.php`, `Promoter_model.php`, `Wallet_model.php`, `Team.php` | **nol** hunk di dalam `count_all_active_downlines()`, `get_team_with_active_status()`, `claim_milestone()`, `get_milestone_status()`, `_milestone_message()`, `has_paid_rental()`, `_distribute_rebate()` |
| V-19 | `git status --porcelain` | hanya berkas §12; nol artefak sementara |

---

## 12. Konfirmasi keamanan — fitur lain TIDAK terpengaruh

| Fitur | Mekanisme terpisah yang dipertahankan | Bukti |
|---|---|---|
| **Plan 119 — Bonus Pencapaian Tim** | `get_milestone_config()`, `get_milestone_status()`, `claim_milestone()` (`count_all_active_downlines()` MK1, state CSV `team_milestone_claimed`, UNIQUE ledger `MSB-*`), `_milestone_message()` (peta F18 lengkap), kartu `#ms-card`, JS `claimMilestone`/`paintMilestones` | Nol edit pada rentang tersebut; V-14/V-15 menguji jalur klaim sungguhan |
| **Plan 116 — Active Downline non-trial (K8)** | `count_all_active_downlines()`, `get_team_with_active_status()`, `Rental_model::has_paid_rental()`, `_distribute_rebate()`, `gp.is_trial = 0` | Nol edit (V-18). `count_active_b_downlines()` justru **dipertahankan** sehingga 4 titik sinkronisasi plan/116 tetap lengkap |
| **Plan 89–92 — rebate 3 tier & promotor** | `Rental_model::_distribute_rebate()`, `Promoter_model` (incl. `team_err_user_unavailable`) | Nol edit; key kamus pemakai tetap ada (§6.2) |
| **Plan 118 — dompet `checkin` + split payment** | `Wallet_model::WALLET_MAIN/WALLET_CHECKIN`, `lock_and_get_balances()`, `checkout_rental()` | Nol edit; `claim_level1` tidak pernah menyentuh dompet `checkin` |
| **Plan 102/106/117 — deposit, e-wallet, withdrawal lock** | jalur masing-masing di `Wallet_model`/`Admin_model` | Nol edit; nol overlap simbol |
| **Ledger immutability (Z1)** | satu-satunya penulis bonus = `claim_milestone()` | L1R2; nol `wallet_ledger` mutation di plan ini |
| **Admin panel (invarian L1)** | — | Nol berkas admin tersentuh; nol copy Indonesia/Inggris baru |
| **`system/`** | — | Nol berkas `system/` tersentuh (pinned `Pagination.php` & `mimes.php` webp aman) |

**Kesimpulan keamanan:** plan/120 adalah penghapusan **tertutup** — semua
simbol yang dihapus berpangkal hanya pada dirinya sendiri (`get_claim_data` ←
`Team::index`, `claim_level1` ← `Team::claim_level1` ← JS kartu). Tidak ada
fungsi bersama yang menjadi korban; satu-satunya jalur uang yang berubah adalah
**hilangnya** jalur kredit `L1-*` Rp 80.000, yang memang tujuan plan ini.

---

## 13. Risiko & mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Penghapusan view parsial menyisakan pemakaian `$l1_bonus_fmt` | PHP 8.3 `Undefined variable` → halaman `/team` rusak | V2–V5 dihapus bersamaan; gate V-2 memindai `l1_bonus` |
| Kamus EN/ID diedit asimetris | Gate P1 merah, halaman error | 17 key dihapus pada kedua berkas dalam satu pass; gate V-6/V-8 |
| Terhapusnya key yang masih dipakai milestone/promotor | Toast kosong / `lang()` mengembalikan key mentah | Daftar larangan §6.2 + grep V-2/V-10 |
| `count_*`/`sum_sales_b_downlines` ikut terhapus | Kontradiksi dokumentasi plan/116 & docblock `Rental_model` | Keputusan Q1/A: kedua method **wajib tetap**; V-18 memeriksa diff `:133-195` kosong |
| Pola `ledger_bonus_l1` ikut terhapus | Riwayat ledger `L1-*` tampil sebagai teks Indonesia mentah di idiom EN | V-9/V-10 |
| Notifikasi historis kehilangan bahasa | Baris lama membeku pada idiom saat dibuat | Q3/A: `notif_bonus_l1_*` dipertahankan |
| Sisa JS `claimLevel1` dirender untuk cache lama | Klik tombol → 404 | Preseden plan/119; `MY_Exceptions::_wants_json()` menormalkan 404 ke JSON; V-13 |
| Spasi atas `/team` berubah saat milestone nonaktif | Ritme UI | E3 (`mt-3` → `mt-4`) + V-12 |
| Uji menyentuh `db_webtable` | Data owner rusak | E11: DB buang `synapse120_tmp`, drop setelah selesai |

---

## 14. Non-goals (sengaja di luar lingkup)

1. **`DROP COLUMN is_level_1_claimed`** — dilarang mutlak (mandat owner).
2. Menghapus baris `wallet_ledger` `L1-*` atau `user_notifications`
   `notif_bonus_l1` historis (invarian Z1).
3. Menghapus `count_active_b_downlines()` / `sum_sales_b_downlines()`
   (keputusan Q1/A).
4. Menghapus `ledger_bonus_l1` / pola `i18n_ledger_description()`.
5. Menghapus `notif_bonus_l1_title`/`_body`.
6. Menyentuh level afiliasi (`users.level_id`), gating referral plan/89,
   program promotor plan/91, rebate plan/89–92.
7. Menambah feature flag/toggle baru untuk "menyalakan kembali" L1 —
   penghapusan bersifat final di lapis aplikasi (riwayat tetap di DB).
8. Perubahan `system/`, `views/templates/header.php`, `routes.php`,
   `application/config/*`.
9. Menulis migration script (nol DDL).

---

## 15. Definition of Done

- [ ] 5 berkas kode lulus `php -l` (V-1).
- [ ] Pemindaian simbol mati bersih (V-2…V-5).
- [ ] Kamus **633/633**; `audit_i18n_parity.php` & `audit_i18n_hardcoded.php`
      exit 0 (V-6…V-8).
- [ ] Retensi ledger & notifikasi terbukti hidup (V-9, V-10, V-16).
- [ ] `GET /team` 200 tanpa kartu misi; `POST /team/claim_level1` 404 JSON
      (V-11, V-13).
- [ ] `POST /team/claim_milestone` masih sukses (`claimed`) dan menulis satu
      baris `MSB-*` — **bukti plan/119 utuh** (V-14, V-15).
- [ ] `reconcile_balances.php --verify` drift 0 (V-17).
- [ ] `database.sql` hanya berubah komentar; `database_seed.sql` & `scripts/`
      tidak berubah (V-4 + `git status`).
- [ ] `docs/1_PRD.md`, `docs/2_ERD.md`, `docs/3_ROADMAP.md`, `AGENTS.md`
      tersinkron (§9).
- [ ] `plan/120_LEVEL1_MISSION_REMOVAL_SUMMARY.md` mencatat perubahan +
      **bukti perintah yang benar-benar dijalankan**.
- [ ] Diff akhir hanya memuat perubahan yang dimaksud; DB buang sudah di-drop;
      nol scratch file di repo.

---

## 16. Daftar berkas yang tersentuh

**Kode (5)**

1. `application/views/team/index.php` — V1–V7 + `mt-3`→`mt-4`
2. `application/controllers/Team.php` — C1–C5
3. `application/models/User_model.php` — M1–M4
4. `application/language/english/app_lang.php` — −17 key
5. `application/language/indonesian/app_lang.php` — −17 key

**Skema (1, komentar saja)**

6. `database.sql` — anotasi `-- DEPRECATED (plan/120)` di atas `:31`

**Dokumentasi (5)**

7. `docs/1_PRD.md`
8. `docs/2_ERD.md`
9. `docs/3_ROADMAP.md`
10. `AGENTS.md`
11. `plan/120_LEVEL1_MISSION_REMOVAL_SUMMARY.md` (dibuat saat eksekusi)

**Tidak tersentuh (eksplisit):** `database_seed.sql`, `scripts/seed_*.php`,
`scripts/migrate_103_notification_i18n.php`, `scripts/reconcile_balances.php`,
`application/config/routes.php`, `application/config/*`, `application/helpers/*`,
`application/models/{Rental,Promoter,Wallet,Admin,Notification,Checkin}_model.php`,
`application/controllers/{Admin,Wallet,Rentals,Home,Profile,Checkin}.php`,
`application/views/templates/*`, `application/views/admin/*`, `system/*`,
`uploads/*`, `backups/*`.

---

## 17. Aturan pelaporan

1. Laporkan **perintah yang benar-benar dijalankan** beserta hasil yang
   teramati — jangan mengklaim suite uji (repo **tidak punya** test suite;
   `composer.json` menunjuk `tests/` yang tidak ada).
2. Setiap penyimpangan dari plan ini wajib dicatat sebagai **Addendum** di
   `plan/120_LEVEL1_MISSION_REMOVAL_SUMMARY.md` (preseden §19 plan/119).
3. Bila salah satu gate i18n gagal, **jangan** melonggarkan gate — perbaiki
   kamus.
4. Commit message bahasa Indonesia, contoh:
   `refactor(team): hapus fitur Misi Level 1 (plan/120)`.
