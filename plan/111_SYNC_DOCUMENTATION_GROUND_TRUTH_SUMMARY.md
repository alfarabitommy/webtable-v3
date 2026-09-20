# Plan 111 — SUMMARY (Sinkronisasi Dokumentasi dengan Ground Truth Kodebase)

> **Status:** **SELESAI** untuk seluruh langkah eksekusi E1–E8. Nol perubahan
> kode aplikasi/skema/migrasi/route/i18n — hanya 4 berkas `docs/`.
> **Blueprint:** `plan/111_SYNC_DOCUMENTATION_GROUND_TRUTH_PLAN.md` (disetujui).
> **Keputusan pemilik repositori (mengikat):**
> **13.1** = (a) log **semua** milestone plan/102–110; **13.2** = (a) bump
> **v5.0 → v5.1** (PRD/ERD/UI-UX); **13.3** = (a) dokumentasikan **4 provider**
> sesuai `database.sql` + nyatakan katalog dinamis; **13.4** = (a) dokumentasikan
> kontrak notifikasi **aktual**.
> **Tanggal:** sesi eksekusi plan/111. **Branch:** `docs/111-sync-ground-truth`.

---

## 1. Ringkasan Eksekusi

| Item | Hasil |
|---|---|
| Berkas `docs/` diubah | **4** (`1_PRD.md`, `2_ERD.md`, `3_ROADMAP.md`, `4_UI_UX_GUIDELINES.md`) |
| Berkas kode diubah | **0** (`application/**`, `system/**`, `*.sql`, `scripts/**` — nol diff) |
| Versi dokumen | `v5.0 → v5.1` pada PRD, ERD, UI/UX; ROADMAP tetap `v6.0` + catatan sinkronisasi |
| DDL / migrasi / route / kamus i18n | **nol** |
| `php -l` | **tidak berlaku** (nol berkas PHP tersentuh) — dinyatakan jujur |
| Gate i18n | **tidak berlaku** (dokumen bukan surface member) — dinyatakan jujur |
| Test suite | **tidak ada** di repo — tidak diklaim |

### Diff akhir (`git diff --stat`)

```
 docs/1_PRD.md              | 223 +++++++ 153 -------
 docs/2_ERD.md              | 115 ++++   23 -----
 docs/3_ROADMAP.md          |  73 +++    19 ---
 docs/4_UI_UX_GUIDELINES.md | 116 +++     8 ---
 4 files changed, 527 insertions(+), 203 deletions(-)
```

Panjang berkas: `1_PRD` 304→374 · `2_ERD` 463→555 · `3_ROADMAP` 229→283 ·
`4_UI_UX_GUIDELINES` 401→509.

---

## 2. Perubahan per Berkas (pemetaan pasal → aksi)

### 2.1 `docs/1_PRD.md` (v5.0 → v5.1)

| Bagian | Aksi | Inti |
|---|---|---|
| Header | REWRITE | `v5.1` + catatan sinkronisasi; tech stack `PHP 8.1 / MySQL 8.4` → `PHP 8.3.6 / MySQL-MariaDB` |
| §1 Overview | REWRITE | Hapus "double-entry ledger" & "gaji otomatis"; tambah single ledger `wallet_ledger`, klaim manual, e-wallet-only, catatan **tanpa cron** |
| §2 Currency | TAMBAH | Catatan **M8 integer IDR** (`^[1-9][0-9]*$`, `_post()` choke-point, `intdiv()`) |
| §3 Roles | REWRITE butir | User = e-wallet + `?ref=CODE` + klaim manual; Admin = **decline** deposit + katalog e-wallet; "System/Cron" → **"Lazy/Manual Worker (bukan cron)"** |
| §4.A Register | REWRITE + TAMBAH | Auto-fill `?ref=CODE` (normalize, cookie 30 hari, prioritas POST→URL→session→cookie) + koreksi klaim regex phone (lihat §4) |
| §4.B Wallet/Deposit | **REWRITE TOTAL** | Tier 1 → **staging QRIS manual plan/102**: B.0 kebijakan dinamis, B.1 `pending` (kode unik 100–999, `total_amount` beku, `reserved_code_key`), B.2 `waiting_approval` (abadi, D1), B.3 `success` (kredit pokok+kode, fee ditahan), B.4 `failed/rejected/expired`, B.5 jendela bayar (**tak ada gate hari/jam**), B.6 ledger |
| §4.C Checkout | PARTIAL | Endpoint `/rentals/create` → **`/rentals/checkout`** |
| §4.D Rental | REWRITE | `Ledger_model`/`rentals` → **`Wallet_model::debit()` + `user_rentals` + `wallet_ledger`** + rebate 3-tier di TX; tambah gambar produk (plan/104) + `max_per_user` (plan/83) |
| §4.E Ledger/Withdrawal | **REWRITE TOTAL** | "Pengikatan Kartu Bank" → **katalog e-wallet plan/106** (DANA/ShopeePay/OVO/GoPay, `^08[0-9]{8,11}$`, arsip bukan hapus); batas **dinamis**; tier half-open kontigu + endpoint turunan plan/110; **NET Display Standard plan/109** |
| §4.F Cron | REWRITE | "System Automation (Cron Jobs)" → **"Otomatisasi Lazy / Klaim Manual (TANPA Cron)"** (ROI klaim manual, expiry lazy, deposit lazy) |
| §4.G Affiliate | REWRITE | Wage tier `9/30/70/130/190` (bukan 18/40/90); L1 = **B-tier**; idempotensi `is_level_1_claimed`; klaim manual + cooldown 7 hari; tambah rebate 3-tier, referral gating, promoter omzet burn |
| §4.H Notification | REWRITE | 4 endpoint fiktif → kontrak aktual (`/notification`, `/notification/mark_all_read`, `/user/read_notifications`, server-rendered) + `title_key`/`params` |
| §5 UI/UX | PARTIAL | Cross-ref UI/UX v5.1; header 3 grup; standar NET; CSS-only selector |
| §6 Security | REWRITE butir | Masking **e-wallet phone**; +Maintenance Mode (plan/95); +API envelope (M9/P7) |
| §7.B/§7.C | PARTIAL | Alert center F2; antrean deposit (kode+status) & WD (**NET**); `decline_deposit` |
| §7.D.1/§7.D.2 | REWRITE | Rute `create_user`/`reset_password` (underscore); upline opsional; reset = admin mengetik sandi (bukan acak + plaintext flash) |
| §8 Notification Infra | REWRITE | Server-rendered badge; hapus "polling 60 detik" |

### 2.2 `docs/2_ERD.md` (v5.0 → v5.1)

| Bagian | Aksi |
|---|---|
| Header | `v5.1` + catatan sinkronisasi |
| §0 Mermaid | `gpu_products` +`image`; **`deposits` REWRITE** (9 kolom + enum 6 nilai); **entitas baru `ewallet_providers`**; `user_notifications` +`title_key`/`params`; `users.balance` → "cache non-otoritatif"; `13 tabel` → **`14 tabel`**; catatan relasi `bank_names` by-name (bukan FK, `%%` comment) |
| §2 `users` | `balance` = **cache non-otoritatif** (autoritatif = SUM `wallet_ledger`) |
| §3 `deposits` | **REWRITE TOTAL** — 12 kolom + index + lifecycle state machine |
| §4 `system_settings` | **+4 baris** (`is_maintenance_mode`, `qris_image`, `qris_merchant_name`, `qris_payment_instructions`, `deposit_expiry_minutes`, `deposit_min_amount`/`deposit_max_amount`) + deskripsi `wd_fee_tiers`/`wd_min_amount` plan/110 + blok fallback lengkap |
| §5 `user_notifications` | +`title_key`/`params` (plan/103); index `idx_user_read` |
| §7 | Blok invariant baru **plan/102–110** (D1/D2/D3/L1/A1/T1/N1/Z2) |

### 2.3 `docs/3_ROADMAP.md` (v6.0, versi dipertahankan)

| Bagian | Aksi |
|---|---|
| Header | Catatan sinkronisasi (log kini 102–110) |
| Strict Rule 6 | Catatan risiko kredensial DB ter-commit |
| Phase 1 (1A/1C/1D) | Anotasi: `transactions`/`rentals`/`otp_logs` deprecated; reCAPTCHA dipurge; regex phone tidak ada |
| Phase 4A / 7B2 / 8A1 / 8C2 / 7D1 / 7D3 / 7D4 | Anotasi ghost (`Ledger_model`, `transactions`, cron, endpoint notifikasi, polling) |
| Phase 5D / 8B1 | Anotasi bank→e-wallet, tier+NET |
| Phase 7E1 / 7E2 | Rute & perilaku admin dikoreksi |
| **Completed Phases (BARU)** | **7 section** plan/102, 103, 104, 107, 108, 109, 110 |
| Phase 11 | 11A diperjelas (gateway daring vs QRIS manual) |

### 2.4 `docs/4_UI_UX_GUIDELINES.md` (v5.0 → v5.1)

| Bagian | Aksi |
|---|---|
| Header | `v5.1` + catatan sinkronisasi |
| §5.B Input Forms | Koreksi klaim regex phone; satu-satunya pattern panjang = e-wallet `08…` |
| §5.C Cards | Varian kartu WD tertunda + catatan ledger tampil gross |
| **§5.G (BARU)** | Provider e-wallet card selector 2×2 (CSS `peer-checked:`, tanpa JS) |
| **§5.H (BARU)** | Withdrawal Amount Hierarchy — NET primer, gross/fee sub-teks |
| §8.B | Antrean deposit (kode+status) & WD (**NET**); escape data member |
| **§8.C (BARU)** | Financial Tier Editor admin (derive endpoints, `readonly`, "Rapikan Tier"/"Sesuaikan Batas Atas", inline error, repopulasi) |

---

## 3. Verifikasi yang BENAR-BENAR dijalankan (dengan hasil)

Dijalankan di sesi ini via `bash` (PHP 8.3.6; `bash` **tidak** diblokir).

| # | Kriteria | Metode | Hasil |
|---|---|---|---|
| **V1** | Tidak ada klaim bertentangan dengan kode | Grep silang angka/kolom/enum → `database.sql` + model | ✅ lulus (lihat V2–V7) |
| **V2** | Kolom `deposits` ERD ≡ DDL | Profil kolom vs `docs/2_ERD.md` | ✅ `unique_code`, `total_amount`, `reserved_code_key`, `expires_at`, `confirmed_at`, `processed_at`, `decline_reason` + enum 6 nilai semua ter-dokumentasi |
| **V3** | Key `system_settings` ERD ≡ seed | Loop 24 key seed vs ERD §4 | ✅ 0 key hilang, 0 key fiktif |
| **V4** | Wage tier PRD ≡ `WAGE_TIERS` | Diff 5 baris + syarat L1 | ✅ `9/30/70/130/190` (200rb/1jt/2,5jt/5jt/9jt) + `3 downline aktif B`/Rp 330.000 |
| **V5** | Milestone ROADMAP ≡ plan/102–110 | Grep `plan/102..110` | ✅ 7/7 ada |
| **V6** | Aturan tier & NET UI/UX ≡ helper | Baca `withdrawal_fee_helper.php` + `withdrawal_amount_helper.php` | ✅ konsisten (endpoint turunan, NET primer, ledger gross) |
| **V7** | Tidak ada referensi mati | Grep `Ledger_model`, `/notifications/`, `create-user`, `reset-password`, `Kartu Bank` | ✅ hanya konteks `~~strikethrough~~` / catatan "retired" / "ghost dihapus" |
| **V8** | Diff bersih | `git status --short` + `git diff --stat` | ✅ hanya 4 berkas `docs/` (+ blueprint plan/111) |
| V9 | Gate i18n | n/a | ⚪ tidak dijalankan — tidak ada perubahan member copy |

**Bukti tambahan:** balance fence markdown diperiksa (ERD = 1 blok mermaid
berpasangan; UI/UX = 44 fence → genap); `git status` mengonfirmasi **nol**
berkas `application/`/`system/`/`*.sql`/`scripts/` berubah.

> **Yang TIDAK bisa diuji:** repo tidak memiliki test suite, dan plan/111 tidak
> menyentuh kode — maka tidak ada `php -l`, migrasi, atau smoke HTTP yang
> relevan. Ini dinyatakan jujur, bukan diklaim lulus.

---

## 4. Temuan Tambahan (di luar daftar eksplisit blueprint — dikoreksi + diflag)

Semua dikoreksi **hanya di dokumen** (sesuai prinsip "kode otoritatif") dan
**wajib ditindaklanjuti pemilik** bila ingin mengubah perilaku kode:

1. **Regex phone `/^0[0-9]{9,13}$/` tidak ada di kode** (grep seluruh
   `application/` = nihil). Validasi aktual = normalisasi + `is_unique[users.phone]`.
   Klaim di PRD §4.A/§6, ROADMAP 1D/7E1, dan UI/UX §5.B dikoreksi.
   *(Bila panjang keras masih diinginkan → implementasi kode, plan terpisah.)*
2. **Rute admin ber-tanda strip tidak valid** (`translate_uri_dashes = FALSE`):
   `admin/create-user`/`admin/reset-password` → aktual `admin/create_user` /
   `admin/reset_password`.
3. **Reset password admin** = admin mengetik sandi baru (min 8, `PASSWORD_DEFAULT`,
   atomik + audit) — **bukan** generate acak + tampil plaintext.
4. **Idempotensi Bonus L1** memakai `users.is_level_1_claimed` — **bukan**
   `description LIKE '%Bonus Level 1%'`.
5. **`users.balance`** = cache non-otoritatif (di-`UPDATE` oleh `_post()`),
   sementara saldo otoritatif = SUM `wallet_ledger` — diklarifikasi di ERD §2.
6. **`user_notifications`** telah punya `title_key` + `params` (plan/103) —
   ditambahkan ke Mermaid & §5 ERD.
7. **Notifikasi server-rendered** (bukan polling 60 detik) — PRD §4.H/§8 &
   ROADMAP 7D3/7D4 dikoreksi.

---

## 5. Yang TIDAK diubah (sesuai non-goals blueprint §9)

* `docs/5_AUDIT_REPORT.md` — masih memuat referensi historis `Ledger_model`
  (di luar 4 dokumen inti; kandidat plan terpisah).
* Standar UI plan/95 (maintenance page), plan/105 (kartu WhatsApp),
  plan/100 (App Preferences), plan/99/101 (world map) — tidak diperluas.
* `AGENTS.md` tidak diubah (koreksi AGENTS.md = pekerjaan terpisah), meskipun
  catatan §Notes (plan/107 "untracked", regex phone, klaim "no committed
  secrets") kini diketahui tidak akurat.
* Provider **LinkAja** **tidak** ditambahkan (per 13.3 — katalog dinamis; seed
  tetap 4 provider sesuai `database.sql`).

---

## 6. Rollback

`git revert <commit>` — murni perubahan dokumentasi, tanpa DDL/migrasi/mutasi
data. Menghapus commit ini mengembalikan keempat `docs/` persis ke keadaan
sebelum plan/111.

---

## 7. Sisa Pekerjaan (follow-up, di luar scope plan/111)

1. **Keputusan kode (bila diinginkan):** implementasi regex panjang phone,
   rute REST-style notifikasi, atau cron — masing-masing plan terpisah.
2. **`docs/5_AUDIT_REPORT.md`** — sinkronkan referensi `Ledger_model`.
3. **`AGENTS.md`** — perbarui §Notes (plan/107 tracked; regex phone; status
   kredensial DB) pada plan terpisah.
4. **Uji browser manual** untuk layout §8.B/§8.C (hanya spesifikasi teks di
   dokumen; tidak ada render yang diuji di sini).
