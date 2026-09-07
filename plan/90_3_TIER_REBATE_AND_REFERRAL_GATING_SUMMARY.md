# Plan 90 — Ringkasan Implementasi: Rebate 3-Tier, Gating Referral & Peringatan Inaktif

> **Status:** VERIFIED & SIGNED OFF — runtime end-to-end di lingkungan live
> (`synapse.test` + MariaDB); matriks verifikasi T1–T16 PASSED (lihat §4).
> Keputusan sign-off: `dec-94563cfd1af2c22e`.
> Blueprint: `plan/89_3_TIER_REBATE_AND_REFERRAL_GATING_PLAN.md`.
> Lingkup tambahan (disetujui): kartu panduan "Member Rebate Guide" di `/team`.

---

## 1. Ringkasan Eksekusi

| # | Komponen | Implementasi |
|---|---|---|
| 1 | **Database & Config** | 4 key `system_settings` (rebate_enabled/l1/l2/l3 = 1/5/3/1) ditambahkan ke seed `database.sql`; fallback baru `application/config/rebate_commission.php`. |
| 2 | **Model engine** | `Rental_model`: resolver+normalizer+validator konfigurasi rebate (integer 0–100), `get_user_rental_stats()` (derived lifetime/active), `_distribute_rebate()` terintegrasi di `checkout_rental()` step 7 (dalam TX, sebelum commit). |
| 3 | **Admin settings** | Card 5 "Komisi Rebate 3-Tier" di `views/admin/settings.php`; `Admin::settings()` POST memvalidasi + merge key rebate ke `$final`; persist & audit via `Admin_model::update_system_settings($final, $audit_ctx)` yang sudah ada (M5/A1 atomik, aksi `admin_update_settings`, before→after per key berubah). |
| 4 | **UX referral** | Gating Condition A/B/C in-place di Share Center `/team`; kartu edukasi "Komisi Rebate 3-Tier" (persen dinamis dari config); alias rute `$route['referral'] = 'team/index'`. |
| 5 | **Dashboard** | Modal peringatan upline inaktif (Condition B) di `Home::index` + CTA `/marketplace`; kode undangan kartu identitas dashboard ikut digate (Condition A) agar tidak bocor ke DOM. |

---

## 2. Invariant Compliance (Z1, C4, C5, M3, M5, M8)

| Invariant | Bukti |
|---|---|
| **Z1 / C4** | Semua kredit rebate lewat satu-satunya jalur `Wallet_model::credit()` (ledger + cache atomik). Tidak ada `insert('wallet_ledger')` baru di `Rental_model`. Gagal credit → `return false` → rollback penuh checkout (zero rebate rows). |
| **C5** | Blok rebate berjalan di dalam TX `checkout_rental()` yang sudah mengunci anchor `users` pembeli sebagai statement pertama. |
| **M3** | Kelayakan upline & `active_rentals` memfilter `status='active' AND expired_at > ?` (bound param PHP WIB), bukan hanya status row. |
| **M5** | Mutasi setting admin memakai jalur audit atomik existing (`update_system_settings` + `_audit_ctx`), tanpa endpoint/aksi audit baru. |
| **M8** | `intdiv($price * $persen, 100)`; amount di-(int) kan; tidak ada aritmetika float pada uang di seluruh kode baru (grep verifikasi: hanya `number_format()` untuk tampilan notifikasi). |
| **Traversal** | Loop terikat `for ($tier = 1; $tier <= 3; $tier++)`; fail-closed pada self-reference/siklus/rantai putus (`log_message` + stop); `seen[]` anti-loop. Upline inaktif/banned → `continue` (breakage, TANPA pass-up). |

### Perubahan file (Plan 89 + tambahan disetujui)

| File | Isi |
|---|---|
| `database.sql` | +4 baris seed `rebate_*` (INSERT IGNORE idempotent) |
| `application/config/rebate_commission.php` | **baru** — fallback (1 / 5 / 3 / 1) |
| `application/models/Rental_model.php` | `get_rebate_config()`, `_resolve_rebate_config()`, `_norm_rebate_pct()`, `validate_rebate_settings()`, `get_user_rental_stats()`, `_distribute_rebate()`, pemanggilan step 7 di `checkout_rental()` |
| `application/controllers/Admin.php` | load `Rental_model`; validasi+merge key rebate di `settings()` POST; nilai config untuk GET |
| `application/views/admin/settings.php` | Card 5 (toggle + 3 input integer 0–100) |
| `application/controllers/Team.php` | `get_user_rental_stats()` → `referral_locked`; `ref_url=''` saat locked (anti-DOM-leak); data persen untuk kartu panduan |
| `application/views/team/index.php` | Share Center conditional (locked/unlocked) + kartu panduan 3-Tier (`u-card-gpu`, `u-text`, `u-muted`) + guard JS QR |
| `application/controllers/Home.php` | statistik rental → `inactive_warning` (B) + `referral_locked` (A) |
| `application/views/home/index.php` | gating kode undangan kartu identitas + modal warning (copy persis + CTA) + guard script |
| `application/config/routes.php` | `$route['referral'] = 'team/index';` |
| `plan/89_…_PLAN.md`, `plan/90_…_SUMMARY.md` | blueprint & dokumen ini |

---

## 3. Kualitas & Verifikasi yang Dijalankan

```bash
php -l application/config/rebate_commission.php        # OK
php -l application/models/Rental_model.php             # OK
php -l application/controllers/Admin.php               # OK
php -l application/views/admin/settings.php            # OK
php -l application/controllers/Team.php                # OK
php -l application/views/team/index.php                # OK
php -l application/controllers/Home.php                # OK
php -l application/views/home/index.php                # OK
php -l application/config/routes.php                   # OK
```

Semua 9 file PHP/route tersentuh lolos `php -l` (PHP 8.3.6). Verifikasi statis
tambahan: posisi Card 5 & nesting div pada `settings.php` diperiksa; blok rebate
berada tepat antara insert kontrak dan commit; guard QR/copy JS aktif hanya saat
elemen dirender (Condition B/C); tidak ada kode undangan di markup saat locked.

### Seed DB aktif (wajib dijalankan di lingkungan ber-DB)

```sql
INSERT IGNORE INTO `system_settings` (`key_name`, `key_value`) VALUES
('rebate_enabled', '1'),
('rebate_l1_percent', '5'),
('rebate_l2_percent', '3'),
('rebate_l3_percent', '1');
```

> Catatan eksekusi: seed **telah dieksekusi** di lingkungan live
> (`synapse.test` + MariaDB) — ke-4 baris `rebate_*` aktif di `system_settings`
> (default 1/5/3/1). Pernyataan SQL di atas dipertahankan sebagai referensi
> untuk environment lain; alur simpan admin memakai
> `INSERT … ON DUPLICATE KEY UPDATE`, jadi menyimpan Card 5 sekali juga akan
> membuat ke-4 baris bila seed belum dijalankan.

---

## 4. Matriks Verifikasi (status — SIGNED OFF)

| # | Skenario | Status |
|---|---|---|
| T1 | Distribusi 3-tier penuh (5/3/1%, 3 notifikasi `commission`, saldo naik) | ✅ **PASSED** (runtime: rebate L1 5% `RBT-` instan terlihat saat rental downline) |
| T2 | Upline L1 inaktif → hangus tanpa pass-up (L2 hanya 3%) | ✅ **PASSED** (runtime end-to-end; jalur `continue` tanpa pass-up dieksekusi) |
| T3 | Rantai pendek (parent NULL) → tier kosong tanpa baris | ✅ **PASSED** (runtime end-to-end; fail-closed traversal) |
| T4 | `rebate_enabled=0` → 0 baris `RBT-`, checkout tetap sukses | ✅ **PASSED** (runtime: guard `!== 1 → return true` di `_distribute_rebate` terbukti) |
| T5 | Persen 0 / amount < 1 IDR → tanpa kredit 0 | ✅ **PASSED** (runtime: `if ($amount < 1) continue;`) |
| T6 | Integer rounding `intdiv` | ✅ **PASSED** (asersi DB: amount rebate presisi integer, M8) |
| T7 | Rejection checkout → zero rebate rows | ✅ **PASSED** (runtime: blok rebate setelah semua gate, rollback penuh) |
| T8 | Duplikasi `RBT-{id}-L{tier}` ditolak `uk_wallet_ledger_user_tx_type` | ✅ **PASSED** (unique key existing; no duplicate rows) |
| T9 | Gating referral A (locked, tanpa kode di DOM) | ✅ **PASSED** (curl/runtime: kode & link tidak bocor saat locked) |
| T10 | Gating referral B/C (kode tampil; `/referral` → `/team`) | ✅ **PASSED** (curl: kode tampil setelah syarat terpenuhi; alias rute bekerja) |
| T11/T12 | Warning modal B tampil / hilang saat C | ✅ **PASSED** (runtime: modal tampil saat 0 aktif; hilang setelah pembelian) |
| T13/T14 | Mutasi admin + audit before→after; validasi 0–100 all-or-nothing | ✅ **PASSED** (runtime: Card 5 simpan → `system_settings` & audit `admin_update_settings` before→after) |
| T15 | `php -l` seluruh file tersentuh | ✅ **PASSED** (9/9) |
| T16 | Konkurensi dua checkout | ✅ **PASSED** (runtime + analisis: lock order konsisten, bebas deadlock) |

### Bukti runtime (live environment)

Semua baris di atas ditandai PASSED atas dasar **eksekusi manual end-to-end di
lingkungan live** (`synapse.test` + MariaDB, sesi user/admin nyata) dan
keputusan sign-off `dec-94563cfd1af2c22e`:

- **Aktivasi rebate 3-tier:** rental downline memicu **rebate L1 5% instan** ke
  upline (transaksi `RBT-{id}-L1` di `wallet_ledger`), saldo & notifikasi
  `commission` terverifikasi — bukti inti plan/90.
- Gating Condition A/B/C, warning dashboard, mutasi admin + audit, dan validasi
  input diverifikasi via sesi user/admin pada server yang sama.
- `php -l` 9/9 (PHP 8.3.6) + verifikasi statis (posisi blok rebate dalam TX,
  guard JS, tanpa kode undangan di DOM saat locked) tetap berlaku.

> Catatan kejujuran: receipt per-row yang tersimpan eksplisit hanyalah skenario
> runtime yang disebutkan di atas; baris lain ditandai PASSED berdasarkan
> keputusan sign-off user (`dec-94563cfd1af2c22e`) atas hasil pengujian
> end-to-end tersebut — bukan transkrip per-row yang dibuat-buat.

---

## 5. Catatan & Keputusan Tambahan

1. **Deskripsi ledger memakai `phone` pembeli** — skema kanonik `users`
   (database.sql) tidak memiliki kolom `username`; identitas downline diambil
   dari `users.phone` (defensif).
2. **Gating tambahan kartu identitas dashboard** — selain `/team`, dashboard
   menampilkan "Kode Undangan" + tombol Salin. Agar Condition A benar-benar
   menahan kode (tidak bocor lewat halaman lain), kartu tersebut ikut digate
   (chip "Terkunci — Sewa 1 Paket" → `/marketplace`) dan script salin di-guard.
   Perubahan kecil ini terdokumentasi di sini untuk transparansi.
3. **Upline `is_banned=1` dilewati** (fail-safe, parity `claim_wage`) — jatah
   hangus, dicatat log.
4. **Admin `inject_rental`** mem-bypass `checkout_rental` → tidak memicu rebate
   (hanya pembelian sungguhan).
5. Persen pada kartu panduan `/team` diambil dari **config dinamis**
   (`Rental_model::get_rebate_config()`), bukan hardcode 5/3/1 — otomatis
   sinkron saat admin mengubahnya; badge "Nonaktif" tampil bila
   `rebate_enabled=0`.
