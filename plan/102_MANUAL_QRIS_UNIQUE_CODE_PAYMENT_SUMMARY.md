# Plan 102 — SUMMARY: Manual QRIS Gateway dengan Kode Unik 3 Digit & Verifikasi Admin

> **Status:** SELESAI & TERVERIFIKASI (implementasi penuh plan/102).
> **Blueprint:** `plan/102_MANUAL_QRIS_UNIQUE_CODE_PAYMENT_PLAN.md` (859 baris).
> **Rentang:** skema DB → migrasi CLI → model → controller/route/core → view →
> kamus dwibahasa → verifikasi end-to-end (CLI harness + HTTP nyata).
> **Keputusan pemilik repositori yang mengikat:** D1 (expiry hanya `pending`),
> D2 (kode 100–999), D3 (fee M1 dipertahankan), D4 (approve dari `pending`
> maupun `waiting_approval`).

---

## 1. Ringkasan Eksekusi

| Fase | Hasil | Bukti |
|---|---|---|
| 1. Skema & seed | `deposits` +7 kolom, enum 6 nilai, `uk_reserved_code_key`, `idx_status_expires`; 6 key `system_settings` di **dua** file seed | `SHOW CREATE TABLE` live DB cocok dengan `database.sql` |
| 2. Migrasi CLI | `scripts/migrate_102_qris_deposits.php` (`--dry-run`/`--apply`), idempotent, DDL + backfill + seed + verifikasi | `--dry-run` OK; `--apply` exit 0; **re-run = no-op** (0 klausa DDL) |
| 3. Model | `Wallet_model` (policy, validator, create/confirm/expire, alokasi kode) & `Admin_model` (approve/decline/queue/alerts/history) | CLI harness **97/97 PASS** ×2 mode fee |
| 4. Controller/route/core | 3 route baru, 2 sweep lazy, 2 method admin baru, upload QRIS aman | Uji HTTP nyata (di bawah) |
| 5. View & upload | Halaman bayar baru + wallet index + dashboard/history/settings admin | Render uji 5 status + HTTP 200 |
| 6. i18n | **+46 key** simetris (332 → **378/378**) | `sort`+`diff` himpunan key identik |
| 7. Verifikasi | `php -l` 15/15 file bersih; 27 skenario HTTP; invarian DB = 0 | §4–§6 dokumen ini |

**Angka kunci:** 16 file diubah, 4 file baru, +1.433 / −107 baris; 0 syntax error;
0 pelanggaran invarian reservasi; 0 sisa data uji di DB.

---

## 2. Rincian Per Langkah (Step 2–7 Plan §11)

### 2.1 Skema basis data (step 2)

| Perubahan | Detail |
|---|---|
| `deposits.unique_code` | `SMALLINT UNSIGNED NULL` — kode 3 digit 100–999, disimpan permanen sebagai jejak audit |
| `deposits.total_amount` | `DECIMAL(15,2) NOT NULL DEFAULT 0.00` — nominal bayar **dibekukan** saat create (`pokok + [fee] + kode`) |
| `deposits.reserved_code_key` | `VARCHAR(24) NULL` + **UNIQUE** — `"{pokok}-{kode}"` selama reservasi hidup, `NULL` saat keluar dari `pending`/`waiting_approval` |
| `deposits.expires_at` / `confirmed_at` / `processed_at` / `decline_reason` | jendela bayar, cap konfirmasi, cap penyelesaian, alasan penolakan |
| `status` ENUM | `('pending','waiting_approval','success','failed','rejected','expired')` — `failed` dipertahankan untuk retensi |
| Index | `uk_reserved_code_key` (eksklusivitas + prefix scan `LIKE '150000-%'`), `idx_status_expires` (sweep) |
| `system_settings` | `qris_image`, `qris_merchant_name`, `qris_payment_instructions`, `deposit_expiry_minutes`(60), `deposit_min_amount`(10000), `deposit_max_amount`(50000000) — `INSERT IGNORE` di `database.sql` **dan** `database_seed.sql` |
| Fallback config | `application/config/withdrawal_fees.php` +3 key kebijakan deposit |

**Bukti migrasi live (dev DB `db_webtable`, MariaDB 12.3.2):**

```
[dry-run] → rencana 10 klausa DDL + 2 backfill + 6 key          (tanpa tulis)
[apply]   1/5 ALTER TABLE deposits → [OK] ALTER diterapkan (10 klausa)
          2/5 backfill total_amount  → affected: 29
          3/5 backfill expires_at    → affected: 0 (tidak ada pending menggantung)
          4/5 seed 6 key system_settings → ditambahkan (6/6)
          5/5 verifikasi → kolom 7/7, enum OK, index OK,
              invarian reservasi 0 inconsistent, 0 duplikat   → EXIT 0
[re-run --apply] → DDL dilewati (skema sudah sesuai), key "sudah ada (dibiarkan)" → EXIT 0
```

### 2.2 Model (step 3)

`application/models/Wallet_model.php` (+498/−…):

| Method | Peran |
|---|---|
| `get_deposit_policy()` | `{expiry_minutes, min_amount, max_amount}` — `system_settings` di atas fallback, nilai rusak → log + fallback (gaya M1) |
| `validate_deposit_settings()` | validator all-or-nothing untuk kartu QRIS admin |
| `create_deposit()` **ditulis ulang** | 1 TX per attempt: anchor lock `users` → baca kode terpakai → guard aktif tunggal → alokasi CSPRNG → INSERT (invoice + kode + total + reservasi + expiry); retry 3× pada duplicate key |
| `_pick_unique_code()` | private, murni — diff himpunan bebas 100..999 + `random_int` |
| `confirm_deposit()` | `pending → waiting_approval` + `confirmed_at`; reservasi **tetap** |
| `expire_user_deposits()` / `expire_stale_deposits()` | sweep lazy (per-user / global batch ≤500) → `expired` + lepas reservasi |
| `has_active_deposit()` / `get_active_deposits()` | guard & listing deposit hidup (rename dari `get_pending_deposits()`) |
| `deposit_credit_amount()` | **nilai kredit otoritatif (D3)**: `pokok + kode` (fee ditahan); legacy → `amount`; guard kredit ≤ `total_amount` |
| `approve_deposit_simulator()` | menerima kedua status hidup, kredit via `deposit_credit_amount()`, lepas reservasi (tetap dev-only) |

`application/models/Admin_model.php`:

| Method | Peran |
|---|---|
| `approve_deposit()` | guard status hidup + **guard expiry hanya untuk `pending`** (D1), lepas reservasi, `processed_at`, kredit `pokok+kode`, audit diperkaya |
| `decline_deposit()` **baru** | `rejected` + `decline_reason` + lepas reservasi, **tanpa** mutasi uang |
| `get_deposit_queue()` **baru** | antrean dashboard (SQL dipindah dari controller — menutup temuan G4), prioritas `waiting_approval` |
| `get_alert_counts()` | `pending_deposits` = `pending + waiting_approval` (key & envelope **tidak** berubah) |
| `count_history_deposits()` / `get_history_deposits()` | filter selaras `success, failed, rejected, expired` (parity paginasi) |

### 2.3 Controller, route, core (step 4)

| Berkas | Perubahan |
|---|---|
| `Wallet.php` | `index()` pakai `get_active_deposits()` + `total_amount` tersimpan (menutup G1); `topup()` + rate limit `deposit:{uid}` + fail-closed QRIS + redirect ke halaman bayar; **`pay()`** & **`confirm_payment()`** baru + cek kepemilikan `_owned_deposit()` (403 + log) |
| `Admin.php` | `__construct` sweep global sebelum alert counts; `index()` pakai `get_deposit_queue()`; `approve_deposit()` notifikasi = nilai kredit; **`decline_deposit()`** baru; `settings()` kirim data QRIS; **`qris_settings()`** baru (validasi → upload → persist → unlink lama) |
| `MY_Controller.php` | `expire_user_deposits()` tepat setelah sweep rental M3 (urutan statement terdokumentasi tetap) |
| `routes.php` | `wallet/pay/(:any)`, `wallet/confirm_payment/(:any)`, `admin/settings/qris` (route multi-segmen wajib) |

### 2.4 View, upload, keamanan (step 5)

| Berkas | Perubahan |
|---|---|
| `views/wallet/pay.php` **baru** (345 baris) | QR, merchant, nominal TEPAT + kode ditonjolkan (split digit hanya bila pokok kelipatan 1000 — mencegah angka menyesatkan), breakdown pokok+kode+[fee], tombol salin, countdown server-based + auto-reload sekali saat habis, banner 5 status, form "Saya Sudah Transfer", langkah bayar, tautan WA untuk QRIS kosong/rejected |
| `views/wallet/index.php` | kartu deposit hidup (chip status, chip kode, total tersimpan, tenggat, tombol **Bayar Sekarang**), notice fail-closed, edukasi kode + min/max + aturan satu-deposit-aktif |
| `views/admin/dashboard.php` | kolom **Verifikasi = total_amount**, chip `Siap diverifikasi`/`Belum konfirmasi`/`Konfirmasi terlambat`, Approve + Decline (alasan), hint runbook Inject Balance |
| `views/admin/history.php` | nominal = total transfer (+ pokok & kode kecil), chip `Rejected`/`Expired` (+ tooltip alasan) |
| `views/admin/settings.php` | kartu **terpisah** "Pembayaran QRIS Manual" (`form_open_multipart('admin/settings/qris')`) — form finansial lama tidak tersentuh |
| `uploads/qris/index.html` + `.gitignore` | placeholder direktori (ada di clone bersih) + isi runtime diabaikan git |

**Keamanan upload (teruji):** allowlist ekstensi `png|jpg|jpeg` + `detect_mime` (MIME sebenarnya) + `encrypt_name` (tanpa path traversal) + batas 2 MB; **SVG & skrip ditolak**; berkas lama dihapus **setelah** persist sukses; berkas baru dibuang bila persist gagal (tanpa orphan, tanpa jendela "gambar hilang").

### 2.5 Kamus dwibahasa (step 6)

**46 key** baru per idiom → **378/378**, himpunan key identik:

`wallet_pay_{back,code_hint,code_label,conf_late,confirm_btn,confirm_dialog,confirm_note,confirmed_at,expired_body,expired_cta,expired_title,expires_at,expires_in,help_note,help_wa_text,instructions_title,invoice_label,legacy_note,merchant_label,not_configured,now_btn,principal_label,qris_alt,reason_label,rejected_body,rejected_title,scan_title,status_expired,status_pending,status_rejected,status_success,status_waiting,step_confirm,step_scan,steps_title,step_transfer,success_body,success_title,title,total_label,waiting_body,waiting_title}` + `wallet_deposit_{code_note,min_note,max_note,single_active}`.

Uang/angka tidak diterjemahkan (L6); panel admin tetap 100% Indonesia tanpa key baru (L1).

---

## 3. Penyimpangan & Klarifikasi terhadap Blueprint

| # | Butir | Blueprint | Implementasi | Alasan |
|---|---|---|---|---|
| S1 | Guard expiry pada approve | §5.2: `expires_at > now` untuk kedua status | **Hanya `pending`** yang di-guard; `waiting_approval` selalu dapat di-approve | Blueprint §5.2 bertentangan dengan **D1** (dan §8.3 sendiri menyebut baris telat "tetap bisa di-approve"). Tanpa koreksi ini, member yang transfer di menit 59 kehilangan uangnya — inti yang ingin dicegah D1 |
| S2 | Nilai kredit | §6.2: kredit `total_amount` | **`pokok + kode`** (`deposit_credit_amount()`), guard ≤ `total_amount` | Mengikuti tabel keputusan **D3** (§4.4), yang menang atas ringkasan §6.2: saat fee ON, fee ditahan platform (zero-dilution M1); saat fee OFF hasilnya = `total_amount` = **Option A persis** |
| S3 | Jumlah key i18n | 39 key (indikatif) | **46 key** (daftar pasti di §2.5) | Kebutuhan nyata view (label invoice, back, confirmed_at, reason, WA text, scan title, confirm note, legacy note, conf_late, deposit_code_note) |
| S4 | Migrasi CLI | DDL + backfill | DDL + backfill + **seed 6 key** `INSERT IGNORE` | Agar fitur langsung dapat dikonfigurasi pada DB live tanpa harus menunggu simpan form admin (idempoten, tidak menimpa nilai live) |
| S5 | Robustness route | — | `pay($invoice='')` / `confirm_payment($invoice='')` | `/wallet/pay/` (segmen kosong) tadinya 500 (`ArgumentCountError`); sekarang 404 bersih (fail-closed, tanpa stack trace) |
| S6 | Refactor G1–G4 | terencana | dikerjakan: G1 (nominal tersimpan), G2 (alert count), G3 (filter history), G4 (SQL antrean ke model) + G5 (tombol Decline), G6 (min/max), G7 (placeholder uploads) | Konsisten dengan blueprint §2.3 |

---

## 4. Matriks Verifikasi — Hasil Nyata

### 4.1 CLI harness (model + service, MySQL nyata)

Harness sementara `Plan102_verify` (dibuat untuk verifikasi, **sudah dihapus** dari repo; `git status` bersih dari artefak uji):

```
MODE FEE ON (dev: flat Rp 5.000 — D3 fee ditahan)
 2. create+guard : total_amount = pokok + fee + kode; reserved_code_key "{pokok}-{kode}";
                   expires_at +60 mnt; 0 baris ledger; create ke-2 → pending_exists; below_min/above_max
 3. anti-tabrakan: kode berbeda lintas user (pokok sama); INSERT kode duplikat DITOLAK unique index;
                   banyak reserved_code_key NULL diizinkan (pelepasan tidak pernah bentrok)
 4. confirm      : pending→waiting_approval, confirmed_at, reservasi TETAP; konfirmasi ganda ditolak;
                   user lain tidak bisa mengonfirmasi; D1: lewat deadline TIDAK jadi expired
 5. expiry       : sweep per-user → expired, reservasi NULL, processed_at; konfirmasi ditolak;
                   approve baris expired DITOLAK; saldo tetap 0; create baru (pokok sama) berhasil
 6. approve      : success, reservasi NULL, kredit ledger = 150172 = pokok+kode (fee 5000 ditahan),
                   saldo +150172, TEPAT 1 baris ledger, audit memuat total_amount; replay ditolak
 7. decline      : rejected + alasan tersimpan + reservasi NULL + TANPA mutasi uang; replay ditolak; audit
 8. nilai kredit : fee ON → pokok+kode; fee OFF → total (Option A); legacy → amount; guard ≤ total
 9. query admin  : alert = pending+waiting; queue hanya status hidup & prioritas waiting; history parity; invarian 0
10. render view  : wallet/index + wallet/pay kelima status + legacy + QRIS kosong
CLEANUP          : user uji terhapus, 0 deposit orphan
  HASIL: PASS 97 / FAIL 0

MODE FEE OFF (deposit_fee_enabled='0' sementara — D3 jalur Option A)
  HASIL: PASS 97 / FAIL 0   (setting dikembalikan ke '1' setelah uji)
```

### 4.2 Verifikasi HTTP end-to-end (server dev + sesi & login nyata)

Login admin via `/control-panel`; login member nyata melalui form `/auth/login` (kode CAPTCHA SVG dibaca dari DOM render — bukan bypass).

| # | Skenario | Hasil |
|---|---|---|
| 1 | GET `/wallet` (member, sesi nyata) | **200** — saldo, notice QRIS belum dikonfigurasi, edukasi kode unik |
| 2 | POST `/wallet/topup` saat QRIS kosong (fail-closed) | **303 → /wallet**, **0 invoice dibuat** |
| 3 | Halaman kartu admin `/admin/settings` | **200** — kartu "Pembayaran QRIS Manual", 6 field, "Belum ada gambar QRIS" |
| 4 | POST `/admin/settings/qris` multipart + PNG asli | **303** — file `5e2ce1e1….png` tersimpan (encrypt_name), 6 key ter-update, audit `admin_update_qris_settings` |
| 5 | POST `/admin/settings/qris` data invalid (merchant kosong, min>max) | **303** + "Validasi QRIS gagal: …" — nilai lama **tidak berubah** |
| 6 | Upload SVG-as-.png & PHP-as-.png | **303** + "The filetype you are attempting to upload is not allowed" — **tidak ada file tertulis**, `qris_image` tidak berubah |
| 7 | GET `/admin/settings/qris` (POST-only) | **404** |
| 8 | POST `/wallet/topup` (QRIS terpasang) | **303 → /wallet/pay/INV-…-CEF3B9** — `kode=172`, `total=155172` (150000+5000+172), reservasi `150000-172`, sisa jendela 45 mnt (kebijakan baru) |
| 9 | GET `/wallet/pay/{inv}` | **200** — "Amount to Transfer (exact)", "Rp 155.172" (kode disorot), "Pay within", countdown, "I Have Transferred", merchant SYNAPSE QA |
| 10 | GET gambar QRIS | **200**, `image/png`, 26.254 byte |
| 11 | POST `/wallet/confirm_payment/{inv}` | **303** — `waiting_approval`, `confirmed_at` terisi, reservasi **tetap** |
| 12 | Konfirmasi ganda (replay) | **303** — status tetap `waiting_approval` (0 baris terpengaruh) |
| 13 | Konfirmasi oleh user lain (user V) | **403** + log `plan/102 ownership violation` |
| 14 | GET `/wallet/pay/{inv}` milik user lain | **403** |
| 15 | GET `/admin` (antrean) | **200** — invoice, chip `Siap diverifikasi`, **Verifikasi Rp 155.172**, kode, Decline |
| 16 | POST `/admin/approve_deposit/{id}` | **303 → /admin#pending-deposits** — status `success`, reservasi NULL, ledger `credit 150172`, saldo 150172, notifikasi "Rp 150.172 telah masuk" |
| 17 | Replay approve | **303** + "Deposit tidak valid atau sudah diproses" — tetap **1** baris ledger, saldo tidak berubah |
| 18 | POST `/admin/decline_deposit/{id}` + alasan | **303** — `rejected`, alasan tersimpan, reservasi NULL, **0** mutasi ledger, notifikasi `warning` beralasan |
| 19 | Expiry via HTTP (expires_at dipaksa lampau → GET `/wallet`) | **200** — sweep lazy: `expired`, reservasi NULL, `processed_at` terisi |
| 20 | Halaman bayar invoice kedaluwarsa | **200** — banner "Invoice expired" |
| 21 | Approve baris kedaluwarsa | **303** — status tetap `expired` + pesan penolakan |
| 22 | Create baru pokok sama setelah expiry | **303** — kode dilepas & dialokasikan ulang (`300000-803`) |
| 23 | Rate limit deposit (maks 5/900 dtk) | lockout aktif → "Terlalu banyak percobaan gagal. Silakan coba lagi dalam 15 menit.", tidak ada baris baru |
| 24 | GET `/admin/alerts/poll` | **200** — envelope utuh (`success/message/data` + key legacy root), `pending_deposits` = deposit hidup |
| 25 | Invoice legacy (`unique_code NULL`, total=amount) | **200** halaman bayar menampilkan catatan legacy; approve → kredit **150000** (= `amount`) |
| 26 | GET `/admin/history/deposit` | **200** — chip `Rejected`/`Expired`/`Success` + baris "pokok Rp …" |
| 27 | Maintenance mode ON (plan/95) | member `/wallet` & `/wallet/pay/{inv}` → **503**; `/admin` **200** (exempt); OFF → **200** |

### 4.3 Pemeriksaan statis & invarian

| # | Pemeriksaan | Hasil |
|---|---|---|
| S1 | `php -l` 15 file PHP baru/berubah | **15/15 bersih** |
| S2 | Paritas i18n | **378 / 378**, himpunan key identik |
| S3 | Semua `lang()` di views+controllers ada di kamus | **371 dipakai / 378 tersedia**, 0 key hilang |
| S4 | SQL langsung di method controller plan/102 | `Wallet::{index,topup,pay,confirm_payment}` = 0; `Admin::{approve_deposit,decline_deposit,settings,qris_settings}` = 0 (`Admin::index` 1 = query penarikan lama, tidak disentuh) |
| S5 | Tulis `wallet_ledger`/`UPDATE users SET balance` di luar `Wallet_model` | **0 (bersih)** |
| S6 | Invarian reservasi (`status hidup ⟺ reserved_code_key NOT NULL`) | **0 inconsistent**, 0 duplikat |
| S7 | Sinkronisasi `database.sql` ↔ live DB | 7/7 kolom ada, enum & 2 index baru sesuai |
| S8 | Seed 6 key di `database.sql` **dan** `database_seed.sql` | 6/6 di kedua berkas |
| S9 | Sisa data uji di DB (user/admin/deposit/audit/rate limit) | **0** — cleanup tuntas; `uploads/qris/` hanya berisi placeholder |
| S10 | Artefak uji di repo | harness `Plan102_verify.php` **dihapus**; `git status` hanya berisi perubahan yang dimaksud |

---

## 5. Diff Breakdown

| Berkas | Δ | Inti perubahan |
|---|---|---|
| `application/models/Wallet_model.php` | +498 | konstanta kode, policy+validator, create (alokasi+guard+TX), `_pick_unique_code`, `has_active_deposit`, `get_active_deposits`, `confirm_deposit`, 2 sweep, `deposit_credit_amount`, simulator |
| `application/controllers/Admin.php` | +205 | sweep di constructor, `get_deposit_queue()`, approve (kredit+notifikasi), `decline_deposit`, data QRIS di `settings()`, `qris_settings()` |
| `application/models/Admin_model.php` | +166 | approve (guard D1 + kredit + audit), `decline_deposit`, `get_deposit_queue`, alert counts, filter history |
| `application/controllers/Wallet.php` | +152 | `index` (nominal tersimpan), `topup` (rate limit + fail-closed + redirect), `pay`, `confirm_payment`, `_owned_deposit` |
| `application/views/wallet/pay.php` **(baru)** | +345 | halaman pembayaran QRIS manual + countdown + salin + 5 status |
| `application/views/admin/settings.php` | +102 | kartu + form multipart QRIS |
| `application/views/wallet/index.php` | +92 | kartu deposit hidup, hint kode/min/max/notice |
| `application/views/admin/dashboard.php` | +84 | antrean deposit (verifikasi, chip, Approve+Decline, runbook) |
| `database.sql` | +79 | DDL kanonik deposits, 6 key seed, catatan migrasi live-DB plan/102 |
| `application/language/{english,indonesian}/app_lang.php` | +46 / +46 | 46 key simetris |
| `application/config/routes.php` | +11 | 3 route (2 member, 1 admin) |
| `application/core/MY_Controller.php` | +10 | sweep deposit lazy (setelah sweep rental M3) |
| `application/views/admin/history.php` | +27 | nominal total + chip status baru |
| `database_seed.sql` | +9 | 6 key seed idempoten |
| `application/config/withdrawal_fees.php` | +8 | 3 key fallback kebijakan deposit |
| `.gitignore` | +5 | abaikan isi runtime `uploads/qris/*` |
| `scripts/migrate_102_qris_deposits.php` **(baru)** | +304 | migrasi CLI `--dry-run`/`--apply` + backfill + seed + verifikasi |
| `uploads/qris/index.html` **(baru)** | +2 | placeholder direktori (tracked) |
| `plan/102_…_PLAN.md` **(baru)** | +859 | blueprint yang disetujui |

---

## 6. Catatan Operasional (deploy & runbook)

1. **Urutan deploy:** (a) `php scripts/migrate_102_qris_deposits.php --dry-run` → (b) `--apply`
   → (c) deploy kode → (d) **segera unggah gambar QRIS** di `/admin/settings`.
   Selama `qris_image` kosong, pembuatan deposit **ditolak** (fail-closed by design) —
   member melihat notice "Pembayaran QRIS belum dikonfigurasi".
2. **Late payment:** deposit `expired` **tidak bisa** di-approve (kode sudah dilepas).
   Transfer yang telanjur masuk ditangani operator lewat **Inject Balance** di detail user (teraudit).
   Baris `waiting_approval` **tidak pernah** kedaluwarsa (D1) — aman bila admin verifikasi terlambat.
3. **Kredit:** = `pokok + kode`. Bila deposit fee M1 aktif, fee ditahan platform (zero-dilution);
   bila non-aktif (default produksi) kredit = total transfer penuh (Option A).
4. **Kebijakan deposit** (masa berlaku, min, max) dapat diubah admin kapan saja; nominal yang
   sudah terbit **tidak berubah** (`total_amount` dibekukan).
5. **Drift yang ditemukan (di luar scope, tidak dikerjakan):**
   `idx_status_created` dari plan/94 belum ada di DB dev lokal (migrasi plan/94 belum dijalankan di env ini) —
   tidak memengaruhi plan/102; `database_seed.sql` juga belum memuat 4 key rebate plan/89.

---

## 7. Sisa Pekerjaan (Step 8 Plan §11 — MENUNGGU PERSETUJUAN)

Sinkronisasi dokumen belum dikerjakan (di luar enumerasi task round ini). Rencana editnya:

| Berkas | Perubahan yang direncanakan |
|---|---|
| `docs/1_PRD.md` §B Tier 1 | ganti deskripsi "invoice + simulator" menjadi siklus deposit QRIS manual (kode unik 3 digit, konfirmasi member, verifikasi admin, expiry, Option A/D3) |
| `docs/2_ERD.md` | 7 kolom baru `deposits` + enum status 6 nilai + 2 index |
| `docs/3_ROADMAP.md` | entri fase plan/102 + status |
| `AGENTS.md` (bagian Notes) | bullet ringkas plan/102 (pola bullet plan/94–101) |

Perintah: balas "lanjutkan step 8" untuk mengeksekusi sinkronisasi dokumen di atas.
