# PLAN 116 — FIX GAJI MINGGUAN: DOWNLINE AKTIF WAJIB KONTRAK PRODUK NON-TRIAL

**Status:** DISETUJUI — siap eksekusi langkah E0..E11 (belum ada kode yang ditulis pada fase perencanaan ini)
**Tanggal:** 2026-09-25
**Ruang lingkup:** 2 model (`User_model`, `Rental_model`) + 1 fixture CLI (`scripts/seed_wage_test_account.php`) + 4 nilai kamus EN/ID + sinkronisasi `docs/`
**Prasyarat:** plan/114 (produk trial `GPU Magang` + gerbang penarikan) sudah rilis & terverifikasi; kolom `gpu_products.is_trial` sudah ada di DB aktif
**Keputusan owner:** `dec-025fb0320bedcb1d` — (a) **unifikasi ketiga titik** `User_model`; (b) **tanpa clawback**, tanpa mutasi data historis; (c) **kelayakan upline rebate ikut diperbaiki** di plan ini
**Non-goal yang mengikat:** nol DDL, nol migrasi, nol route baru, nol perubahan `system/**`, nol perubahan panel admin, nol mutasi `wallet_ledger` historis

---

## 0. Ringkasan eksekutif

Empat cacat predikat, satu akar masalah: **"downline aktif" didefinisikan sebagai "punya sewa apa pun yang aktif", tanpa memperhitungkan bahwa sejak plan/114 ada produk trial berharga Rp 0 yang bisa diklaim siapa saja (1× lifetime)**.

1. **Satu definisi otoritatif diterapkan seragam** di `User_model`: kontrak `status='active'` **dan** `expired_at > now WIB` **dan** `gpu_products.is_trial = 0`. Titik yang diperbaiki: `count_all_active_downlines()` (otoritas gaji mingguan), `count_active_b_downlines()` (bonus Level 1), `get_team_with_active_status()` (badge + counter di `/team`).
2. **Gerbang bayar tidak disentuh.** `claim_wage()` tetap: anchor `users FOR UPDATE` → hitung ulang di dalam TX → `determine_wage_level()` → stamp kondisional `affected_rows() === 1` → `Wallet_model::credit()`. Karena penghitung itu **satu-satunya** sumber level, memperbaiki predikatnya otomatis menutup celah payout secara fail-closed — nol kode transaksi baru, nol perubahan urutan statement yang load-bearing.
3. **Kebenaran pembayaran rebate ikut ditutup.** `Rental_model::_distribute_rebate()` menganggap upline "aktif" cukup dengan kontrak apa pun — termasuk trial gratis — sehingga upline trial-only menerima komisi riil dari pembelian downline. Kelayakan kini juga mensyaratkan kontrak dari produk non-trial; kontrak reward `promoter_reward` (non-trial) **tetap** eligible (preseden plan/114 D-A & K7 plan/91–92).
4. **Tanpa clawback & tanpa DDL.** Deploy = kode saja. Gaji yang terlanjur cair dicatat sebagai dampak bookkeeping yang diketahui dan tidak dapat ditarik kembali (ledger immutable).

Dampak kode: **4 predikat SQL** di 2 model, **1 fixture** diselamatkan (sekaligus diperluas flag opsional untuk matriks bukti), **4 nilai kamus** (0 key baru, paritas tetap 633/633), **3 dokumen** disinkronkan. Nol berkas pada `database*.sql`, `scripts/migrate_*`, `routes.php`, `application/controllers/**`, `application/views/**`, `application/helpers/**`, dan admin panel.

---

## 1. Fakta repositori yang diverifikasi (dasar desain)

| # | Fakta | Bukti |
|---|---|---|
| F1 | **Defect utama.** `count_all_active_downlines()` = recursive CTE atas `users.parent_id` `JOIN user_rentals ur` `WHERE ur.status = 'active' AND ur.expired_at > ?` — **tanpa** join `gpu_products` → kontrak trial ikut terhitung sebagai downline aktif | `User_model.php:163-178` |
| F2 | Penghitung itu dipakai **dua kali**: tampilan (`get_claim_data()`) dan otoritas bayar (`claim_wage()` memanggilnya **setelah** anchor lock, di dalam TX) | `User_model.php:238`, `User_model.php:430-436` |
| F3 | `count_active_b_downlines()` punya defect identik (gate bonus L1 `>= 3`), dipakai di display **dan** di dalam TX `claim_level1()` | `User_model.php:125-135`, `:232`, `:289-290` |
| F4 | `get_team_with_active_status()` — subquery `is_active` menghitung **kontrak apa pun** → badge "Aktif" dan counter `active_bc`/`l1_active`/`l2_active` di `/team` menghitung pemegang trial | `User_model.php:82-93`; `Team.php:41-58`; `views/team/index.php:446-478` |
| F5 | Bonus L1 **tidak** eksploitatif lewat trial: omzet memakai `JOIN gpu_products` + `SUM(gp.price)` (trial = 0) sehingga tetap butuh omset riil Rp 330.000 | `User_model.php:143-154` |
| F6 | Omzet promotor **tidak** terinflasi: `total_l1 = SUM(ur.purchase_price)` (trial menyumbang 0 — plan/114 F8). `l1_count` = hitungan **mentah** downline, bukan metrik aktivitas | `Promoter_model.php:115-155` |
| F7 | **Defect kedua (kelas sama).** Kelayakan upline rebate = `status = 'active' AND expired_at > ?` tanpa filter trial → upline yang hanya memegang trial menerima rebate riil; docblock aturan 3 masih berbunyi "≥ 1 kontrak aktif" | `Rental_model.php:703-715`, `:623-625` |
| F8 | **Predikat kanonik yang sudah ada & sudah diterima owner**: `Rental_model::has_paid_rental()` = `JOIN gpu_products p ON p.id = ur.product_id … AND p.is_trial = 0` (gerbang penarikan, plan/114 D-A). Plan/116 memakai literal yang sama | `Rental_model.php:915-926` |
| F9 | `get_user_rental_stats()` menghitung trial di `lifetime_rentals` → membuka gating referral Condition A. **Disengaja** (plan/114 D-B/D2, keputusan owner) → **jangan diubah** | `Rental_model.php:596-611`; `Team.php:25-27` |
| F10 | **Tidak ada engine "tier/rank agen"** berbasis jumlah downline: `users.level_id` hanya ditulis saat registrasi, disalin ke session, dan ditampilkan di profil — nol derivasi, nol auto-upgrade | `Auth.php:327`; `views/profile/index.php:47`; grep `level_id` = 3 hasil |
| F11 | `/admin` leaderboard `downline_count` = `COUNT(DISTINCT downline_id)` **tanpa** filter aktivitas, sementara label UI berbunyi *"Ranked by total active downlines (L1 + L2)"* → mislabel, bukan gate uang | `Admin_model.php:1374`; `views/admin/analytics.php:66` |
| F12 | `scripts/seed_wage_test_account.php` memilih produk **termurah yang aktif** (`ORDER BY price ASC LIMIT 1`) → sejak plan/114 baris itu adalah **produk trial** (id 9, harga 0); blok verifikasinya juga memirror SQL lama | `scripts/seed_wage_test_account.php:119-123`, `:187-198` |
| F13 | Kamus hari ini **633/633 key** (pasca plan/115), bukan 602 seperti catatan AGENTS.md yang basi | `grep -c '^$lang\['` = 633 di kedua idiom |
| F14 | Salinan yang mendokumentasikan definisi lama dan **wajib** disesuaikan: `team_help_active_body`, `team_help_l1_li1`, `team_help_wage_body`, `team_wage_sub` | EN `app_lang.php:254`/`:257`/`:262`/`:342`; ID idem |
| F15 | Definisi "Active Downline" di PRD juga tidak mengecualikan trial, dan `is_trial` **tidak pernah** didokumentasikan di `docs/` (gap sinkronisasi plan/114–115) | `docs/1_PRD.md:225`; grep `is_trial`/`trial` di `docs/` = 0 hasil |
| F16 | Disiplin WIB terjaga: bound param di-generate PHP (`date('Y-m-d H:i:s')`), `expired_at` bertipe `TIMESTAMP NULL` (NULL tidak pernah lolos `> ?` → aman), MySQL `NOW()` tidak dipakai | `User_model.php:126`, `:164`; `database.sql:281` |

### 1.1 Alur exploit & penilaian severity (jujur)

1. Leader klaim produk trial (gratis, `price = 0`, `is_trial = 1`, `max_per_user = 1`) → tanpa debit ledger.
2. Karena plan/114 D-B **sengaja** membiarkan trial membuka Condition A, kode/link/QR undangan langsung terbuka di `/team`.
3. 9 akun baru mendaftar dengan kode itu dan masing-masing mengklaim trial (gratis) → `count_all_active_downlines(leader)` = **9** → klaim gaji **Level 2 Rp 200.000/minggu** tanpa modal.
4. Omzet upline tetap 0 (F6) dan bonus L1 tetap terkunci (F5) — jadi **satu-satunya** kebocoran finansial adalah gaji mingguan (plus kelayakan rebate pada F7).

Batas realistis: kontrak trial hanya 3 hari, jadi armada downline harus di-refresh sekitar mingguan (9 akun baru per pekan untuk L2; secara teori sampai 190 akun untuk L6). Ekstraksi kas keluar tetap terhalang gerbang penarikan plan/114 (wajib 1 kontrak produk berbayar), **tetapi** kredit gaji gratis dapat langsung dipakai membeli paket di dalam platform → inflasi saldo nyata, plus distorsi statistik tim/leaderboard. Larangan "uang keluar tanpa bayar" tidak dilanggar; **kebenaran perhitungan reward** yang dilanggar.

---

## 2. Keputusan desain (D1–D11)

| # | Keputusan | Alasan / trade-off |
|---|---|---|
| **D1** | Unifikasi predikat di **ketiga** titik `User_model` (bukan hanya wage) | Satu definisi otoritatif; badge `/team` tidak lagi berkata "Aktif" untuk downline yang tidak dihitung mesin gaji. Keputusan owner. |
| **D2** | Predikat kanonik = `JOIN gpu_products p ON p.id = ur.product_id` **dan** `p.is_trial = 0`; **tetap** `ur.status = 'active'` + `ur.expired_at > ?` (bound PHP WIB) | Literal sesuai permintaan + preseden `has_paid_rental()` (plan/114 D-A). `p.is_active` **tidak** dipakai: produk yang dinonaktifkan admin tidak boleh mencabut kontrak yang sudah sah. |
| **D3** | Predikat **inline** di tiap query + komentar rujukan kanonik (bukan helper/konstanta SQL baru) | Trade-off eksplisit: fragment `'p.is_trial = 0'` tetap butuh `JOIN` yang harus ditulis per query, sehingga abstraksi hanya menyembunyikan satu klausa; `Rental_model::has_paid_rental()` tetap titik acuan norma (memenuhi semangat helper-first: satu aturan, satu rujukan). Bila muncul titik ke-6 → §10 O3. |
| **D4** | `claim_wage()`/`claim_level1()` **tidak diubah strukturnya** | Penghitung yang salah adalah satu-satunya cacat; TX/anchor/stamp kondisional sudah fail-closed (F2). Diff nol di jalur uang = nol risiko regresi urutan statement (M2/C5/C6). |
| **D5** | Kelayakan upline rebate ikut diperbaiki dengan predikat sama | Kelas bug identik (aktivitas gratis → uang riil). Keputusan owner. Kontrak `promoter_reward` non-trial **tetap** eligible (K6/K7 plan/91–92, plan/114 D-A) — yang ditutup hanya "trial-only upline". |
| **D6** | `get_user_rental_stats()` **tidak** diubah | Trial sengaja membuka gating referral (plan/114 D-B). Bukan bug; menutupnya berarti membalik keputusan owner lain. |
| **D7** | `count_active_b_downlines()` tetap diperbaiki walau L1 tidak eksploitatif (F5) | Konsistensi definisi; mencegah progress bar/mission card L1 menghitung downline trial, dan menjaga satu makna kata "aktif" di seluruh `/team`. |
| **D8** | Kamus: **4 nilai diubah** di kedua idiom, **0 key baru** (paritas tetap 633/633); nol nominal/angka baru (L6/P3); admin (L1) tidak disentuh | Definisi lama ("cukup punya sewa aktif") akan berbohong ke member setelah fix. Angka tier yang sudah ada dibiarkan apa adanya (gate P3 saat ini LULUS dengan nilai itu). |
| **D9** | Fixture dibetulkan + diberi flag opsional `--downlines=N`, `--product=trial\|paid\|mixed`, `--help`; **default tanpa argumen = perilaku lama** (9 downline berbayar → L2) | Tanpa `is_trial = 0` harness memilih produk trial (F12) → bukti verifikasi palsu (hijau padahal salah). Flag opsional dipakai matriks V2–V5; kontrak default tidak berubah sehingga regresi harness tetap terjaga. |
| **D10** | Sinkronisasi dokumen: PRD §G (definisi Active Downline), ERD (kolom `is_trial` yang belum terdokumentasi), ROADMAP (entri plan/116) | Konvensi repo: invarian berubah → `docs/` disegarkan (AGENTS.md). |
| **D11** | Tanpa DDL/migrasi/route; urutan rilis = **kode saja**, dengan gerbang E0 `migrate_114 --verify` exit 0 | Kolom `is_trial` sudah ada di live sejak plan/114; kode baru tanpa kolom = 500 di seluruh jalur `/team`, jadi E0 adalah gate keras (R1). |

---

## 3. Perubahan model (persis, 4 titik)

### 3.1 `application/models/User_model.php` — `count_all_active_downlines()` (`:163-178`) — OTORITAS GAJI

```sql
WITH RECURSIVE tree AS (
    SELECT id FROM users WHERE parent_id = ?
    UNION ALL
    SELECT u.id FROM users u
    INNER JOIN tree t ON u.parent_id = t.id
)
SELECT COUNT(DISTINCT t.id) AS cnt
FROM tree t
JOIN user_rentals ur ON ur.user_id = t.id
JOIN gpu_products p   ON p.id = ur.product_id   -- plan/116 (D2): identitas "sewa berbayar"
WHERE ur.status = 'active'
  AND ur.expired_at > ?                         -- M3: bound param WIB, bukan MySQL NOW()
  AND p.is_trial = 0                            -- plan/116 (D2): kontrak TRIAL tidak pernah dihitung
```

- Docblock diperbarui: menyebut pengecualian trial + rujukan norma `Rental_model::has_paid_rental()` (plan/114 D-A).
- `COUNT(DISTINCT t.id)` dipertahankan → join tidak mungkin menggandakan hitungan (anti fan-out).
- Bind param tetap `[$user_id, $now]`.

### 3.2 `application/models/User_model.php` — `count_active_b_downlines()` (`:125-135`) — GATE BONUS L1

```sql
SELECT COUNT(DISTINCT u.id) AS cnt
FROM users u
JOIN user_rentals ur ON ur.user_id = u.id
JOIN gpu_products p   ON p.id = ur.product_id
WHERE u.parent_id = ?
  AND ur.status = 'active'
  AND ur.expired_at > ?
  AND p.is_trial = 0
```

- Bind param tetap `[$user_id, $now]`. Docblock disinkronkan.

### 3.3 `application/models/User_model.php` — `get_team_with_active_status()` (`:82-93`) — BADGE `/team`

Subquery `is_active` diganti di **kedua** cabang `UNION ALL`:

```sql
(SELECT COUNT(*) FROM user_rentals ur
   JOIN gpu_products p ON p.id = ur.product_id
  WHERE ur.user_id = u.id
    AND ur.status = 'active'
    AND ur.expired_at > ?
    AND p.is_trial = 0) AS is_active
```

- Urutan bind param **tidak berubah**: `[$now, $user_id, $now, $user_id, $user_id]`.
- `COUNT(*)` → join ke PK tidak mem-fan-out; `Team.php:44` sudah men-cast `(int) > 0` menjadi bool.

### 3.4 `application/models/Rental_model.php` — `_distribute_rebate()` kelayakan upline (`:703-715`)

```sql
SELECT 1 FROM user_rentals ur
  JOIN gpu_products p ON p.id = ur.product_id
 WHERE ur.user_id = ? AND ur.status = 'active' AND ur.expired_at > ?
   AND p.is_trial = 0
 LIMIT 1
```

- Docblock aturan 3 (`:623-625`) disinkronkan menjadi: *"upline wajib punya ≥ 1 kontrak aktif dari produk NON-trial"*.
- Sisanya **tidak berubah**: traversal terikat 3 posisi, fail-closed self-reference/siklus, breakage tanpa pass-up, `intdiv(price * pct, 100)`, `amount < 1` dilewati, kredit `Wallet_model::credit()` dengan `RBT-{rental_id}-L{tier}`, notifikasi di TX yang sama.

### 3.5 Ringkasan predikat sesudah plan/116 (5 titik, satu makna)

| Titik | Peran | Status plan/116 |
|---|---|---|
| `Rental_model::has_paid_rental()` `:915-926` | gerbang penarikan (plan/114) | **sudah benar** — menjadi norma literal |
| `User_model::count_all_active_downlines()` `:163-178` | otoritas level gaji mingguan | diperbaiki |
| `User_model::count_active_b_downlines()` `:125-135` | gate bonus Level 1 | diperbaiki |
| `User_model::get_team_with_active_status()` `:82-93` | badge/status `/team` | diperbaiki |
| `Rental_model::_distribute_rebate()` `:703-715` | kelayakan upline rebate | diperbaiki |

---

## 4. Fixture & harness verifikasi — `scripts/seed_wage_test_account.php`

| Perubahan | Detail |
|---|---|
| Pemilihan produk (`:119-123`) | Tambah `AND is_trial = 0` pada kedua query (primer `is_active = 1 AND is_trial = 0 ORDER BY price ASC`, fallback `is_trial = 0 ORDER BY id ASC`) — **wajib**: tanpa ini fixture menghasilkan 0 downline aktif pasca-fix dan mencetak hasil menyesatkan |
| Blok verifikasi (`:187-198`) | SQL mirror disamakan dengan model (tambah `JOIN gpu_products p … AND p.is_trial = 0`) + ekspektasi dihitung dinamis (bukan hardcode "expected 9" bila `--downlines=N`) |
| Flag baru (opsional, aditif) | `--downlines=N` (default 9), `--product=trial\|paid\|mixed` (default `paid`), `--help` |
| Kontrak tetap | idempoten (bersih-bersih rentang telepon `0812999900xx` + baris turunannya), `SET time_zone = '+07:00'`, exit 0/1, nol berkas baru, nol tulis ke `application/` |

Alasan masuk scope: harness ini adalah satu-satunya jalur bukti untuk gaji mingguan di repo dan **diam-diam rusak sejak plan/114** (F12). Memperbaikinya bukan scope creep, melainkan bagian dari DoD (V8).

---

## 5. Kamus EN/ID — 4 nilai (0 key baru, paritas 633/633)

| Key | Nilai baru (ringkas) |
|---|---|
| `team_help_active_body` | ID: *"Downline dihitung **aktif** hanya bila memiliki **kontrak aktif dari produk berbayar (bukan trial)**, belum kedaluwarsa. Kontrak trial **tidak** dihitung; user yang tidak menyewa **tidak dihitung**."* — EN padanan idiomnya |
| `team_help_l1_li1` | ID: *"Minimal **3 downline langsung (B)** aktif **dari produk berbayar (bukan trial)**"* — EN padanan |
| `team_help_wage_body` | ID: *"Klaim gaji mingguan berdasarkan jumlah downline aktif **pemegang kontrak produk berbayar (bukan trial)**, semua level:"* — EN padanan |
| `team_wage_sub` | ID: *"Klaim 1x per 7 hari — level ditentukan dinamis dari downline aktif **pemegang kontrak produk berbayar (bukan trial)**"* — EN padanan |

Aturan yang dipegang: nol nominal/angka baru di kamus (L6/P3 — menyebut "trial"/"produk berbayar" **tanpa** `Rp 0`); nilai EN ≠ ID (gate paritas); nol literal baru di view (`audit_i18n_hardcoded.php` tetap 0 temuan); nol kunci baru sehingga jumlah key tetap 633/633; panel admin tidak disentuh (L1).

Catatan: `team_help_wage_l2`…`l6` dan `team_active_downline` **tidak** diubah (sudah netral/berisi angka tier yang saat ini lolos gate P3).

---

## 6. Eksekusi (E0 → E11)

| # | Langkah | Target | Gate / ekspektasi |
|---|---|---|---|
| **E0** | **Tulis plan ini** ke `plan/116_FIX_WEEKLY_WAGE_ACTIVE_DOWNLINE_PLAN.md`; jalankan gate prasyarat DDL | `plan/116_..._PLAN.md`; `php scripts/migrate_114_trial_product_wd_gate.php --verify` | Berkas plan ada; `--verify` **exit 0** (kolom `is_trial` ada, tepat 1 baris trial). **Gate keras** — E1–E4 tidak dirilis sebelum ini hijau (R1) |
| **E1** | 3 predikat `User_model` (F1/F3/F4) + docblock | `application/models/User_model.php` | Diff = 3 blok SQL + komentar + docblock; **nol** perubahan `claim_wage()`/`claim_level1()`/`WAGE_TIERS`/`determine_wage_level()` |
| **E2** | Kelayakan upline rebate (F7) + docblock `:623-625` | `application/models/Rental_model.php` | Diff = 1 query + 2 komentar |
| **E3** | Fixture: filter `is_trial = 0`, SQL mirror disamakan, flag opsional | `scripts/seed_wage_test_account.php` | Default tanpa argumen tetap 9 downline berbayar → L2 |
| **E4** | 4 nilai kamus × 2 idiom | `application/language/{english,indonesian}/app_lang.php` | 633/633 key, EN ≠ ID, nol angka baru |
| **E5** | Sinkronisasi dokumen (D10) | `docs/1_PRD.md` §G (`:225`, klarifikasi `:227`/`:236`); `docs/2_ERD.md` (kolom `gpu_products.is_trial` — area `:267` + blok entitas `:71`); `docs/3_ROADMAP.md` entri plan/116 | Definisi "Active Downline" menyebut pengecualian trial; `is_trial` terdokumentasi |
| **E6** | Lint + gate i18n | `php -l` 4 berkas; `php scripts/audit_i18n_parity.php`; `php scripts/audit_i18n_hardcoded.php` | Ketiganya exit 0 (`LULUS` / `0 temuan`) |
| **E7** | Matriks runtime V2–V8 | — | Lihat §7; hasil dilaporkan apa adanya |
| **E8** | Tulis `plan/116_FIX_WEEKLY_WAGE_ACTIVE_DOWNLINE_SUMMARY.md` | `plan/116_..._SUMMARY.md` | Memuat perintah + hasil yang **benar-benar** dijalankan, dampak historis (R2/R8), dan keputusan owner |
| **E9** | Bersihkan scratch | `/tmp` probe + fixture di-cleanup | `git status` hanya memuat berkas §9 |
| **E10** | Commit (pesan Indonesia) | — | mis. `fix(gaji): hitung downline aktif hanya dari kontrak produk non-trial (plan/116)` |
| **E11** | Opsional: backfill entri ROADMAP plan/113–115 (F15) | `docs/3_ROADMAP.md` | Hanya bila tidak memperluas scope kode |

---

## 7. Matriks verifikasi runtime (bukti yang akan dilampirkan)

| # | Skenario | Data | Ekspektasi |
|---|---|---|---|
| V1 | Statik | `php -l` 4 berkas + 2 gate i18n | Semua exit 0 |
| V2 | **13 trial** | leader + 13 downline, semuanya hanya memegang trial aktif | `count_all_active_downlines` = **0**; kartu gaji `/team` **terkunci** (bukan "cooldown"); `POST /team/claim_wage` → `{success:false, code:'not_qualified'}`; **0** baris `wallet_ledger` baru; `users.last_wage_claimed_at` tetap NULL |
| V3 | **12 trial + 1 berbayar** | 13 downline campuran | count = **1** → **terkunci** (harus 9); nol kredit, stamp tidak berubah |
| V4 | **9 berbayar** | 9 downline dengan kontrak produk non-trial aktif | count = **9** → **terbuka** (L2, Rp 200.000); tepat 1 baris kredit `WAGE-{uid}-Y{o}W{w}`; `last_wage_claimed_at` terisi; klaim kedua di siklus ISO yang sama → `already_claimed`, nol baris baru |
| V5 | Anti double-count (fan-out join) | 1 downline dengan 1 trial **dan** 1 kontrak berbayar, keduanya aktif | dihitung **sekali** (`COUNT(DISTINCT`)) |
| V6 | Badge `/team` | downline trial-only vs berbayar-aktif vs berbayar-kedaluwarsa | "Nonaktif" / "Aktif" / "Nonaktif"; `active_bc` = `l1_active` + `l2_active`; layout kartu tetap rapi @360 px |
| V7 | **Rebate (D5)** | (a) upline trial-only + downline beli paket Rp 1.000.000; (b) ulangan dengan upline berbayar; (c) ulangan dengan upline pemegang kontrak `promoter_reward` non-trial | (a) **0** baris `RBT-*` untuk upline itu (breakage, tanpa pass-up) + 0 notifikasi rebate; (b) kredit rebate muncul sesuai persen aktif; (c) **tetap** menerima (tanpa regresi K7/plan-114 D-A) |
| V8 | Regresi harness | `php scripts/seed_wage_test_account.php` (tanpa argumen) | 9 downline berbayar → L2; re-run idempoten (no-op) |
| V9 | Mode terbatas (bila HTTP/MySQL tidak tersedia) | fixture `--dry-run` + diff SQL model vs mirror | Dilaporkan sebagai bukti **lebih lemah**, tidak diklaim sebagai e2e (preseden plan/108) |
| V10 | Diff akhir | `git status` + `git diff` | Hanya berkas §9 yang tersentuh |

**Metode.** Jalur emas = seed fixture → jalur HTTP nyata. Login terlindungi CAPTCHA SVG native (plan/114), sehingga harness sementara di `/tmp` boleh membaca berkas session (`sess_driver = files`, `sess_save_path = sys_get_temp_dir()`) untuk mengambil `auth_captcha.code`, lalu `POST /auth/login` + CSRF → `POST /team/claim_wage`, dan memeriksa DB. Bila langkah itu tidak dapat dijalankan di lingkungan eksekusi, **wajib dilaporkan apa adanya** (bukti yang tidak diperoleh tidak boleh diklaim).

---

## 8. Risiko & mitigasi

| # | Risiko | Mitigasi |
|---|---|---|
| R1 | Kode dirilis sebelum kolom `is_trial` ada → 500 di seluruh jalur `/team` | **E0 gate keras**: `migrate_114_trial_product_wd_gate.php --verify` exit 0 lebih dulu (live sudah punya kolom sejak plan/114) |
| R2 | Efek retroaktif: leader/promotor yang saat ini "qualified" mendadak kehilangan kelayakan bila downline-nya hanya trial | **Intended** (anti-abuse). Tanpa clawback (D4). Wajib dicatat di ringkasan §Dampak + disosialisasikan lewat copy `/team` yang baru (E4) |
| R3 | Copy `/team` yang lebih panjang merusak layout kartu kecil @360 px | Teks dibatasi ±2 baris di kartu; verifikasi visual manual pada V6 bila lingkungan memungkinkan |
| R4 | Fixture memilih produk trial → verifikasi palsu (hijau padahal salah) | D9/F12: filter `is_trial = 0` + SQL mirror disamakan; V8 menjaga harness |
| R5 | Produk yang di-flag trial setelah punya pembeli berbayar mencabut kredensialnya | Sudah dicegah plan/114 D9 (`product_has_paid_rentals` guard). Predikat membaca flag **saat ini** — identik dengan `has_paid_rental()`, konsisten; dicatat sebagai ketergantungan eksplisit |
| R6 | Performa: join tambahan di CTE gaji | Join PK `gpu_products.id` atas katalog puluhan baris, difilter setelah join; leftmost prefix `idx_user_status_expired` tetap melayani. **Nol index baru** (preseden plan/114 D10) |
| R7 | Lock-ordering baru di dalam TX gaji | Query baru **non-locking** (tanpa `FOR UPDATE`) → tidak menambah urutan kunci; anchor tetap `users` sebagai statement pertama (C5) |
| R8 | Gaji terlanjur cair tetap dibayar | Diterima owner (D4). Dampak bookkeeping dicatat eksplisit di ringkasan; nominal tidak dapat direkonstruksi tanpa audit manual (tanpa skrip, §10 O1) |
| R9 | Rebate berubah perilaku untuk upline yang selama ini "aktif" hanya karena trial | Intended (D5); cakupan sempit (upline trial-only), kontrak `promoter_reward` & pembelian berbayar tidak terpengaruh — dibuktikan V7(b)(c) |

---

## 9. Berkas yang akan tersentuh

| Berkas | Perubahan |
|---|---|
| `plan/116_FIX_WEEKLY_WAGE_ACTIVE_DOWNLINE_PLAN.md` | **baru** (E0, dokumen ini) |
| `plan/116_FIX_WEEKLY_WAGE_ACTIVE_DOWNLINE_SUMMARY.md` | **baru** saat E8 |
| `application/models/User_model.php` | 3 predikat + docblock |
| `application/models/Rental_model.php` | 1 predikat (`_distribute_rebate`) + docblock |
| `scripts/seed_wage_test_account.php` | filter `is_trial = 0`, SQL mirror, flag opsional |
| `application/language/english/app_lang.php` | 4 nilai |
| `application/language/indonesian/app_lang.php` | 4 nilai |
| `docs/1_PRD.md` | §G definisi "Active Downline" (+ klarifikasi `:227`/`:236`) |
| `docs/2_ERD.md` | dokumentasi kolom `gpu_products.is_trial` + catatan predikat downline |
| `docs/3_ROADMAP.md` | entri plan/116 (opsional: backfill 113–115) |

**Nol perubahan:** `database.sql`, `database_seed.sql`, `scripts/migrate_*`, `application/config/routes.php`, seluruh `application/controllers/**`, `application/views/**`, `application/helpers/**`, `application/models/{Wallet,Promoter,Admin,Product,Checkin,Notification,Audit,Rate_limit,Ewallet}_model.php`, panel admin, `system/**`.

---

## 10. Item opsional (di luar plan ini sampai owner memutuskan)

- **O1** — CLI diagnostik **read-only** (`scripts/audit_wage_active_downlines.php`, default dry-run, nol tulis) untuk mendaftar klaim gaji vs hitungan ulang. *Tidak diambil*: owner memilih tanpa skrip (D4).
- **O2** — Badge netral "Trial" di `/team` agar downline trial-only tidak hanya terbaca "Nonaktif" (butuh key kamus baru + perubahan view; nol dampak UX pada plan/116).
- **O3** — Sentralisasi predikat bila muncul titik pemakaian ke-6 (konstanta SQL di model atau helper choke-point).
- **O4** — Perbaikan label `views/admin/analytics.php:66` yang menyebut "active downlines" padahal `downline_count` = seluruh downline (F11) — sekaligus temuan bahasa Inggris di panel admin (L1).
- **O5** — Backfill entri ROADMAP plan/113–115 yang belum tercatat (F15).

---

## 11. Non-goals (eksplisit di luar scope)

1. **Tidak** mengubah `get_user_rental_stats()` → trial tetap membuka gating referral (plan/114 D-B, keputusan owner lain).
2. **Tidak** mengubah mesin `claim_wage()`: anchor, stamp kondisional, cooldown 7 hari, `WAGE_TIERS` (9/30/70/130/190), `determine_wage_level()`, `transaction_id` `WAGE-{uid}-Y{cycle}`.
3. **Tidak** mengubah `claim_level1()` selain lewat predikat penghitungnya (syarat `≥ 3` + omset `≥ Rp 330.000` tetap).
4. **Tidak** menyentuh `Promoter_model` (omzet = `SUM(purchase_price)`; trial sudah menyumbang 0), `Checkin_model`, `Wallet_model`, `Admin_model`.
5. **Tidak** ada clawback, migrasi data, atau mutasi `wallet_ledger` historis.
6. **Tidak** menambah DDL/index/migrasi/route/kunci kamus/admin UI.
7. **Tidak** mengubah definisi kuota kanal (`max_per_user`, K4) atau predikat D1 `status IN ('active','completed')` milik mesin omzet.

---

## 12. Definition of Done

1. `php -l` bersih untuk 4 berkas PHP yang diubah.
2. `php scripts/audit_i18n_parity.php` → `LULUS` dan `php scripts/audit_i18n_hardcoded.php` → `0 temuan` (keduanya exit 0).
3. Matriks §7 **V2, V3, V4, V5, V7, V8** dijalankan dan hasilnya dilaporkan apa adanya (perintah + observasi); langkah yang tidak dapat dijalankan dinyatakan eksplisit sebagai tidak dijalankan.
4. Nol DDL: `database.sql`/`database_seed.sql`/`scripts/migrate_*` tidak muncul di diff; `git status` hanya memuat berkas §9.
5. `plan/116_FIX_WEEKLY_WAGE_ACTIVE_DOWNLINE_SUMMARY.md` memuat matriks bukti, dampak gaji historis (R2/R8), dan rujukan keputusan owner `dec-025fb0320bedcb1d`.
6. Dokumen `docs/` tersinkron untuk invarian yang berubah (definisi Active Downline + kolom `is_trial`).
7. Commit dengan pesan bahasa Indonesia; nol berkas scratch tertinggal di repo.
