# Plan 93 — Repository Audit & Documentation-Sync Findings (Pre-Sync Analysis)

> **Purpose:** stand-alone audit report produced BEFORE any documentation edit.
> Lets the owner (Tommy) analyze the drift between the codebase/schema and the
> docs before the synchronization in the plan below is executed.
> **Status:** AUDIT ONLY — no file was modified by this report (input data as of
> branch `main`, working tree, Sep 2026 session).
> **Decisions already given by user:** `dec-94563cfd1af2c22e` (full ERD parity +
> Mermaid; all T-rows in plan/90 & plan/92 PASSED; full roadmap reconciliation).

---

## 1. Ringkasan Eksekutif

Dua modul besar telah **selesai & runtime-verified** menurut pernyataan user
(eksekusi manual end-to-end di `synapse.test` + MariaDB):

1. **3-Tier Rebate Engine** — `plan/89` (blueprint) & `plan/90` (summary).
2. **Promoter Program (Omzet Burn)** — `plan/91` (blueprint) & `plan/92` (summary).

Audit `git status -s` + `git diff` + pembacaan `database.sql`, `docs/2_ERD.md`,
`docs/3_ROADMAP.md`, `plan/90`, `plan/92`, `AGENTS.md` menghasilkan temuan inti:

- ✅ **`database.sql` sudah sinkron penuh** dengan kedua modul (DDL otoritatif
  tidak perlu disentuh — hanya verifikasi).
- ❌ **`docs/2_ERD.md` tertinggal jauh** — bahkan belum memuat diagram sama sekali,
  belum ada `promoter_claims`, `system_settings`, `rate_limits`, spesifikasi
  `user_rentals` penuh, maupun kolom `is_promoter`/`source`.
- ❌ **`docs/3_ROADMAP.md` tertinggal** — Phase 10A–D masih `PLANNED`; rencana
  83–92 tidak tercantum di Completed.
- ❌ **`plan/90` & `plan/92` berstatus `IMPLEMENTED (menunggu DB/runtime)`** —
  bertentangan dengan status runtime-verified; matriks verifikasi penuh ⏳.
- ❌ **`AGENTS.md`** — inventory skema belum memuat `promoter_claims`; model
  `Promoter_model` & invariant K4/K7 belum tercatat.
- ⚠️ Temuan sekunder lain (lihat §6) yang sebaiknya dilaporkan walau di luar
  lingkup edit.

---

## 2. Audit Inventory — Apa yang Berubah di Working Tree

`git status -s` (branch `main`, semuanya BELUM di-commit):

**Modified (15):**

| File | Isi perubahan (per `git diff --stat`, ~+1746/−116) |
|---|---|
| `application/config/routes.php` | +16: route `referral`, `promoter/claim`, `admin/promoter-claims` (+approve/reject) |
| `application/controllers/Admin.php` | +468: settings rebate, toggle_promoter, promoter-claims queue approve/reject, dsb. |
| `application/controllers/Home.php` | +30/−: gating Condition A/B + kartu Program Promotor |
| `application/controllers/Team.php` | +125: `referral_locked`, hub omzet, endpoint AJAX `promoter_claim` |
| `application/models/Admin_model.php` | +280: toggle_promoter, get/count promoter claims, dsb. |
| `application/models/Product_model.php` | +44/−: query B kuota marketplace source-aware |
| `application/models/Rental_model.php` | +363: rebate engine `_distribute_rebate`, GATE 2 source-aware (`source <> 'promoter_reward'`) |
| 6 view files (`admin/settings.php`, `admin/templates/sidebar.php`, `admin/user_detail.php`, `admin/users.php`, `home/index.php`, `marketplace/index.php`, `team/index.php`) | UI Card 5 rebate, chip/aksi PROMOTOR, hub Program Promotor, gating, queue sidebar |
| `database.sql` | +58/−: lihat §3 |

**Untracked (14+):**

| File | Peran |
|---|---|
| `application/models/Promoter_model.php` | Engine omzet burn: submit/approve/reject TX + audit/notifikasi |
| `application/config/promoter_rewards.php` | Peta tier reward `product_id → omzet_cost` |
| `application/config/rebate_commission.php` | Fallback config rebate (1/5/3/1) |
| `application/views/admin/promoter_claims.php` | Queue admin + modal reject (+ CSRF hidden input) |
| `application/views/admin/products/` | View CRUD produk (plan/85–86) |
| `plan/85`–`plan/92` (8 file) | Plan+Summary product CRUD, gating sederhana, rebate, promoter |

**Catatan penting:** seluruh pekerjaan plan/85–92 berada di working tree dan
**belum pernah di-commit** (commit terakhir `feed598` = plan/83 gating & limits).
Direktori `.reasonix/tasks/…` (transkrip sesi) juga untracked — tidak dihitung
sebagai artefak repo.

---

## 3. `database.sql` — Sudah Sinkron ✅ (verifikasi saja)

`git diff database.sql` mengonfirmasi DDL kanonik sudah memuat SEMUA elemen yang
diminta checklist sync:

| Elemen | Definisi di `database.sql` |
|---|---|
| `users.is_promoter` | `TINYINT(1) NOT NULL DEFAULT 0` (komentar `-- plan/91: flag promotor (admin-only). Bypass gating referral Condition A.`), setelah `is_banned`, tanpa FK |
| `user_rentals.source` | `ENUM('purchase','promoter_reward') NOT NULL DEFAULT 'purchase'` (komentar plan/91 K4: baris reward TIDAK memakan kuota pembelian berbayar) |
| Tabel `promoter_claims` | Kolom penuh (`omzet_cost INT UNSIGNED`, `status ENUM('pending','approved','rejected') DEFAULT 'pending'`, `admin_notes`, dll); indeks `idx_user_status`, `idx_status_created`, `idx_product_id`; FK `user_id→users` RESTRICT, `product_id→gpu_products` RESTRICT, `admin_id→admins` SET NULL |
| `system_settings` seed | `rebate_enabled=1`, `rebate_l1_percent=5`, `rebate_l2_percent=3`, `rebate_l3_percent=1` (INSERT IGNORE; komentar plan/89) |
| Lain (di luar checklist) | `unlock_prerequisite_id` DICOMMISSIONED (plan/87); seed 8 paket `gpu_products` id 1–8 dgn `unlock_prerequisite_id = NULL` |

**Kesimpulan:** tidak ada edit `database.sql` yang diperlukan untuk sync.

---

## 4. Drift per Dokumen (Target Sync)

### 4.1 `docs/2_ERD.md` — STALE (butuh full parity + Mermaid)

- **Tidak ada diagram Mermaid** sama sekali saat ini (dokumen 203 baris, murni
  dictionary markdown; title "Entity Relationship Diagram (ERD) & Database
  Schema v5.0").
- `users` (bagian §2) TIDAK memuat: `is_banned`, `is_promoter`,
  `must_change_password`, `is_level_1_claimed`, `last_wage_claimed_at`.
- `gpu_products` (§2) TIDAK memuat `max_per_user`, `unlock_prerequisite_id`.
- **`user_rentals` tidak punya spesifikasi tabel sendiri** — hanya catatan
  divergence §7 terhadap `rentals` (yang malah didokumentasikan penuh sebagai
  tabel live, padahal DEPRECATED M10).
- **Tidak ada** bagian `promoter_claims`, `system_settings`, `rate_limits`.
- `withdrawals` (§3) tidak memuat `amount`, `wd_number`, `decline_reason`.
- `system_audit_logs` (§6) masih berlabel "(Phase 10 — Planned)" walau sudah
  live sejak Phase 10A.
- Catatan decommission `transactions` (M6) sudah ada ✅.

### 4.2 `docs/3_ROADMAP.md` — STALE (butuh reconciliation)

- "Completed Phases" berakhir di Phase 9 + M8 (plan/72–73).
- Phase 10A–D (Audit Logging / Rate Limiting / Session Security / Input
  Sanitization Audit) masih di "Upcoming Phases" berstatus `PLANNED` — padahal
  sudah diimplementasikan (commit `974b0b2`, `d40bced`, `1c0a529`, plan/18–29).
- Seri audit M1–M10, P1–P7, dan closure plan/82 (24 temuan 100% CLOSED) TIDAK
  tercermin (hanya M8 yang ada).
- Milestone plan/83–92 (gating & limits, admin product CRUD, simplified gating,
  rebate 3-tier, promoter program) TIDAK ada di Completed.

### 4.3 `plan/90_3_TIER_REBATE_AND_REFERRAL_GATING_SUMMARY.md` — STALE

- Status: `IMPLEMENTED (menunggu verifikasi runtime di lingkungan ber-DB)`.
- §3 "Seed DB aktif" dicatat belum dieksekusi (sandbox tanpa client DB).
- §4 Matriks T1–T16: hampir semua `⏳ butuh DB` / `⏳ curl` / `⏳ runtime`
  (hanya T5/T7/T8 design-check yang ✅).
- Tidak memuat receipt runtime yang sekarang tersedia (rebate 5% L1 `RBT-`
  instan saat rental downline).

### 4.4 `plan/92_PROMOTER_OMZET_BURN_PROGRAM_SUMMARY.md` — STALE

- Status: `IMPLEMENTED (kode selesai; verifikasi runtime menunggu lingkungan
  ber-DB — sandbox tanpa client mysql/mariadb & env DB_*)`.
- §1 baris 1 & §4 migrasi DB: `ALTER/CREATE … belum dieksekusi`.
- §5 Matriks T1–T16: hampir semua `⏳` (T15 `php -l` 15/15 ✅ saja).
- **CSRF fix tidak terdokumentasi** — plan/91 & plan/92 tidak menyebut CSRF
  sama sekali, padahal user melaporkan "CSRF token fix applied to the modal"
  (bukti di kode: `application/views/admin/promoter_claims.php` baris ~196
  hidden input `get_csrf_hash()`; AJAX member via `csrfFetch()`).

### 4.5 `AGENTS.md` — STALE (inventory + invariants)

- Inventory skema kanonik (12 tabel) belum memuat **`promoter_claims`**.
- Daftar Models belum memuat **`Promoter_model`**; config belum menyebut
  `rebate_commission.php` / `promoter_rewards.php`.
- Invariant baru **K4** (kuota independen per kanal via `user_rentals.source`)
  & **K7** (kontrak zero-cost) + flag `is_promoter` admin-only belum tercatat.

---

## 5. Bukti Runtime (receipts) — dari Pernyataan User, bukan dari Repo

Tidak ada receipt/transkrip runtime yang dapat dibaca di dalam repo
(`.reasonix/tasks/*` tidak memuat pola curl/receipt dan berizin terbatas).
Satu-satunya bukti adalah pernyataan user pada sesi ini (dikutip untuk analisis):

1. Environment live: **`synapse.test` + MariaDB** (bukan sandbox tanpa DB).
2. **Condition A bypass** terverifikasi pada promotor 0-rental
   (`087700010001`).
3. Perhitungan **omzet**, **pending omzet lock**, dan **telemetri queue admin**
   terverifikasi.
4. **Happy-path approval**: kontrak zero-cost diterbitkan
   (`purchase_price = 0`, `source = 'promoter_reward'`), **nol mutasi
   `wallet_ledger`**, audit logging berjalan.
5. **Aktivasi rebate 3-tier**: rental downline memicu **rebate L1 5% instan**
   ke promotor (transaksi `RBT-`).
6. **Rejection flow**: admin reject + note melepaskan omzet yang terkunci
   kembali ke available; **CSRF token fix diterapkan pada modal**.
7. Keputusan sign-off kedua matriks: `dec-94563cfd1af2c22e`.

---

## 6. Temuan Sekunder / Discrepancy lain (untuk dianalisis; di luar edit sync)

| # | Temuan | Dampak / Rekomendasi |
|---|---|---|
| D1 | **Working tree plan/85–92 + kode belum di-commit** (commit terakhir = plan/83) | Sync docs akan mengacu ke file untracked; disarankan commit setelah sync disetujui |
| D2 | `database_seed.sql` (tracked, plan/10) memuat blok `[RECONCILE] ALTER TABLE users ADD COLUMN username/role/…` yang **kontradiktif** dgn skema kanonik (`users` tidak punya `username`/`role`; plan/90 catatan 1 eksplisit "tidak memiliki kolom username") dan **tidak memuat** `is_promoter`/`promoter_claims` | Jangan disentuh di sync ini; kandidat pembersihan terpisah (seed runner `scripts/seed_database.php --apply` bisa menambah kolom legacy) |
| D3 | `plan/88` §4 menyatakan QA browser/session "remain open" untuk modul 87–88 | Roadmap akan mencantumkan 87–88 sebagai EXECUTED dgn catatan QA terbuka per plan/88 (bukan "Runtime Verified") |
| D4 | ERD lama mendokumentasikan `rentals`/`otp_logs` sebagai tabel aktif | Akan ditandai DEPRECATED (M10) agar paritas dgn database.sql |
| D5 | Roadmap memakai penomoran campuran (Phase 1–9, M8, dst.) | Entri baru mengikuti gaya existing + ref plan, tanpa menciptakan nomor fase baru |
| D6 | Tidak ada test suite otomatis; verifikasi = `php -l` (sudah 9/9 plan/90 & 15/15 plan/92) + runtime manual user | Receipt runtime dicatat sebagai bukti user, bukan klaim otomatis |
| D7 | `.reasonix/tasks/` untracked (artefak sesi) | Kandidat `.gitignore`, bukan untuk di-commit |

---

## 7. Rencana Sinkronisasi (menunggu persetujuan analisis)

1. **`docs/2_ERD.md`** — full parity dgn `database.sql`: tambah diagram Mermaid
   (`erDiagram`, seluruh 13 tabel + relasi/FK), perbarui semua dictionary tabel
   (kolom, tipe, default, FK, indeks), tandai deprecation, tambah invariant
   `is_promoter` (gating-bypass) & `source` (K4) & `promoter_claims` (K7).
2. **`docs/3_ROADMAP.md`** — Phase 10A–D → COMPLETED; entri Completed utk
   closure audit M1–M10/P1–P7 (plan/37/66/82) dan milestone plan/83–92 dgn
   summary kemampuan + receipts (89–90 & 91–92: 100% Runtime Verified).
3. **`plan/90`** — status → `VERIFIED & SIGNED OFF`; matriks T1–T16 → PASSED;
   seed §3 dicatat dieksekusi; blok bukti runtime.
4. **`plan/92`** — status → `VERIFIED & SIGNED OFF`; §4 migrasi dicatat
   dieksekusi (SQL idempotent dipertahankan sbg referensi); matriks T1–T16 →
   PASSED dgn pemetaan receipt; tambah catatan CSRF fix.
5. **`AGENTS.md`** — tambah `promoter_claims` (inventory), `Promoter_model` +
   config (architecture), invariant K4/K7 + `is_promoter` + closure plan/89–92.
6. **`database.sql`** — tanpa edit (verifikasi token-parity saja).
7. **Verifikasi akhir** — grep token-parity lintas dokumen; checklist file
   audited/updated; laporan akhir.

**Catatan kejujuran:** hanya row yang didukung receipt user yang diberi label
bukti runtime spesifik; row lain ditandai PASSED atas dasar keputusan sign-off
user (`dec-94563cfd1af2c22e`) dgn blok bukti dikutip — bukan transkrip per-row
yang dibuat-buat.

---

## 8. Checklist File

| File | Peran audit | Aksi sync |
|---|---|---|
| `git status -s` / `git diff` | Input audit | — |
| `database.sql` | ✅ sudah sinkron | verifikasi saja |
| `docs/2_ERD.md` | ❌ stale | edit (full parity + Mermaid) |
| `docs/3_ROADMAP.md` | ❌ stale | edit |
| `plan/90_…_SUMMARY.md` | ❌ stale | edit (sign-off) |
| `plan/92_…_SUMMARY.md` | ❌ stale | edit (sign-off + CSRF) |
| `AGENTS.md` | ❌ stale | edit (inventory + invariants) |
| `plan/93` (dokumen ini) | laporan temuan | referensi analisis |
