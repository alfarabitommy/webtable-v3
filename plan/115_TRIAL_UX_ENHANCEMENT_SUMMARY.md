# PLAN 115 — SUMMARY: TRIAL PRODUCT UX ENHANCEMENTS (SORTING PRIORITY + WELCOME POPUP)

**Status:** ✅ COMPLETED & RUNTIME-VERIFIED (V1–V8 dieksekusi penuh; catatan lingkungan di §6.3)
**Tanggal:** 2026-09-23
**Plan:** `plan/115_TRIAL_UX_ENHANCEMENT_PLAN.md`
**Keputusan owner:** `dec-0d9ee9b255b03969` — non-trial **tetap `p.id ASC`** · nominal bonus **diturunkan dari baris produk trial di DB** · **O1 DISETUJUI** (flag `localStorage` di-scope per-user)
**Prasyarat yang dijalankan:** plan/114 `migrate_114_trial_product_wd_gate.php --verify` → exit 0 (kolom `is_trial` ada, tepat 1 baris trial)

---

## 1. Berkas yang berubah

| # | Berkas | Jenis | Δ | Isi |
|---|---|---|---|---|
| 1 | `plan/115_TRIAL_UX_ENHANCEMENT_PLAN.md` | baru | 441 baris | Blueprint (dokumen ini = ringkasannya) |
| 2 | `plan/115_TRIAL_UX_ENHANCEMENT_SUMMARY.md` | baru | — | Ringkasan + matriks bukti ini |
| 3 | `application/models/Product_model.php` | ubah | +37 / −2 | `ORDER BY p.is_trial DESC, p.id ASC` + docblock + method baru `get_active_trial_product()` |
| 4 | `application/controllers/Home.php` | ubah | +30 / −0 | Blok `$trial_promo` (gate `lifetime === 0`) + view var |
| 5 | `application/views/home/index.php` | ubah | +116 / −0 | Modal promo (baris 772–886) + IIFE JS gerbang klien |
| 6 | `application/language/indonesian/app_lang.php` | ubah | +9 / −0 | 6 kunci baru + 3 komentar |
| 7 | `application/language/english/app_lang.php` | ubah | +9 / −0 | 6 kunci baru + 3 komentar |

Total kontribusi plan/115: **+201 insertions, −2 deletions** pada 5 berkas aplikasi.

> **Catatan metodologi:** angka di atas adalah kontribusi **plan/115 itu sendiri**. Tiga berkas (`Home.php`, `Product_model.php`, `views/home/index.php`) belum tersentuh di working tree sebelumnya → `git diff` = plan/115 murni. Dua berkas kamus **sudah** dimodifikasi plan/114 (belum di-commit), sehingga `git diff --stat` menampilkan **+20 baris** masing-masing (= 11 baris plan/114 + 9 baris plan/115).

**Tidak disentuh (diverifikasi lewat `git status`):** `database.sql`, `database_seed.sql`, `scripts/**` (nol migrasi baru), `application/config/**`, `routes.php`, `views/marketplace/index.php`, `controllers/Marketplace.php`, `Rental_model`, `Wallet_model`, `Admin*`, dan **apa pun di bawah `system/`**.

---

## 2. Perubahan teknis yang dieksekusi

### 2.1 `Product_model` (E1/E2)

```php
// E1 — satu klausa ORDER BY (kunci kedua TIDAK diubah: non-trial tetap p.id ASC)
"SELECT p.*
   FROM gpu_products p
  WHERE p.is_active = 1
  ORDER BY p.is_trial DESC, p.id ASC"

// E2 — method read-only baru, fail-closed
public function get_active_trial_product() {
    $row = $this->db->query(
        "SELECT id, name, price, daily_rate, duration_days, max_per_user
           FROM gpu_products
          WHERE is_trial = 1
            AND is_active = 1
            AND price = 0
          ORDER BY id ASC
          LIMIT 1"
    )->row_array();

    return $row ?: null;
}
```

Docblock `get_catalog_for_user()` disinkronkan (`order is_trial DESC lalu id ASC`).

### 2.2 `Home` (E3)

Blok `$trial_promo` dijalankan **hanya** di dalam `if ($lifetime === 0)` → user yang sudah pernah menyewa tidak menambah query apa pun. Aritmetika integer murni (`$daily * $days`), tanpa float (M8).

```php
'trial_promo' => [
    'user_id' => (int) $user_id,   // O1 — scope gerbang klien per-user
    'days'    => $days,            // (int) duration_days, min 1
    'daily'   => $daily,           // (int) daily_rate
    'total'   => $daily * $days,   // 10000 × 3 = 30000
],
```

### 2.3 View (E4) — modal + JS

- Blok server-gated `<?php if (!empty($trial_promo)): ?>` disisipkan **sebelum** blok modal plan/89 (baris 772–886), di luar wrapper `p-4 space-y-6`.
- Kontainer `z-[60]` (guideline §2), default class **`hidden`**, `role="dialog"` + `aria-modal="true"` + `aria-labelledby="trialPromoTitle"`.
- Tombol tutup `w-11 h-11` + `aria-label` i18n; CTA `<a h-12>` ke `base_url('marketplace')`; semua ikon `aria-hidden="true"`; **nol** CSS/animasi baru.
- JS IIFE: baca flag → `return` bila ada, **sebelum** `classList.remove('hidden')`; `try/catch` pada `getItem`+`setItem`; `Esc`/backdrop/✕/"Nanti Saja" menutup + menandai; klik CTA menandai (tanpa `preventDefault`); focus trap 3 elemen; fokus awal ke CTA; **nol** global yang dipolusi.
- **O1 diimplementasikan:** `var FLAG = 'trial_popup_dismissed_' + <?= (int) $trial_promo['user_id'] ?>;` → sesuai permintaan owner, perangkat bersama tidak saling menekan promo.

### 2.4 Kamus (E5)

6 kunci baru, paritas **627 → 633/633**, diposisikan tepat setelah blok plan/114 `market_trial_*` di kedua idiom (baris `:157-162`).

| Key | ID | EN |
|---|---|---|
| `home_trial_modal_title` | `Gratis Sewa GPU Magang` | `Free GPU Internship Rental` |
| `home_trial_modal_subtitle` | `Coba mesin ROI Synapse tanpa modal awal, khusus untuk member yang belum pernah menyewa.` | `Try the Synapse ROI engine with zero upfront cost, exclusive to first-time renters.` |
| `home_trial_modal_potential` | `Potensi profit Rp %s (Rp %s/hari selama %d hari).` | `Potential profit of Rp %s (Rp %s/day for %d days).` |
| `home_trial_modal_rule` | `Catatan jujur: untuk menarik saldo, Anda nanti tetap harus menyewa minimal 1 produk berbayar (gerbang penarikan).` | `Full disclosure: to withdraw your balance you will still need to rent at least 1 paid product (withdrawal gate).` |
| `home_trial_modal_cta` | `Klaim Sekarang` | `Claim Now` |
| `home_trial_modal_close` | `Tutup promo` | `Close promo` |

Kunci reuse (nol duplikasi): `market_trial_badge`, `market_trial_free`, `home_later`. **Nol kunci tambahan untuk O1** — prefix per-user murni identifier JS (bukan copy), sehingga tidak masuk kamus.

---

## 3. Gate wajib (E6/E7) — hasil nyata

| Gate | Perintah | Hasil |
|---|---|---|
| Lint 5 berkas PHP berubah | `php -l <file>` | **semua** "No syntax errors detected" (`lint_fail=0`) |
| Paritas kamus | `php scripts/audit_i18n_parity.php` | `EN keys : 633` · `ID keys : 633` · Paritas 1:1 OK · nilai identik **0** · P3 **0** · P6 **0** · P3b **0** → `[OK] … LULUS`, **exit 0** |
| Higienitas string | `php scripts/audit_i18n_hardcoded.php` | 89 berkas dipindai, **0 temuan**, **exit 0** |
| Prasyarat plan/114 | `php scripts/migrate_114_trial_product_wd_gate.php --verify` | `[OK] Verifikasi plan/114 lulus.` **exit 0** |

> Info P6 (bukan kegagalan): 42 key lama memuat markup `<>` (mis. `en:team_help_l1_body`). **Tidak satu pun** berasal dari 6 kunci plan/115 — keenam nilai baru bersih dari `<`/`>`.

---

## 4. Verifikasi runtime V1–V8

Lingkungan: PHP 8.3.6 CLI + `php -S 127.0.0.1:8099` (env `APP_BASE_URL` lokal + `DB_*` → DB lokal `db_webtable`, MariaDB 12.3.2; **tanpa** mengubah `application/config/**`). Login HTTP nyata sebagai **fixture user id 6** (`lifetime_rentals = 0`), CAPTCHA native dijawab dari penyimpanan sesi (`/tmp/reasonix-session-tmp-*/ci_session*`, driver `files`) — nol perubahan kode aplikasi.

| # | Skenario | Hasil terukur | Status |
|---|---|---|---|
| E0 | Pre-flight DDL plan/114 | `kolom is_trial ADA` · `TEPAT 1 baris trial (id 17, price 0)` · exit 0 | ✅ |
| V1 | Urutan katalog (user lifetime 0, idiom ID) | kartu: **1** `GPU Magang (Trial)` → 2 RTX 3060 Starter (5) → 3 RTX 4060 Lite (6) → 4 RTX 4070 Basic (7) → 5 RTX 4080 Prime (8) → 6 RTX 4090 Pro (9) → 7 A100 Cloud Cluster (10) → 8 H100 Tensor Node (11) → 9 H200 Sovereign (12) — trial di puncak, sisanya **`id ASC` identik urutan lama** | ✅ |
| V2 | Trial tetap puncak saat kuota habis | setelah klaim trial: kartu tetap indeks 1, badge `Batas Sewa: Maks. 1 (Tersisa: 0)`, State B `disabled` + `fa-ban` · `Batas Maksimal Tercapai` | ✅ |
| V3 | Gerbang server modal = TAMPIL | `GET /home` (lifetime 0) → `id="trialPromoModal" class="fixed inset-0 z-[60] hidden"` ada; `role="dialog" aria-modal="true"` ada; copy EN: `Potential profit of Rp 30.000 (Rp 10.000/day for 3 days).` (server-computed `10000 × 3`) | ✅ |
| V4a | Gerbang server = ABSEN karena `lifetime > 0` | setelah kontrak trial dibuat (lifetime 1): `grep -c trialPromoModal` = **0** (dari 2 baris saat tampil); `inactiveWarnModal` = 0 (kontrak masih aktif → benar) | ✅ |
| V4b | Fail-closed: `is_active = 0` pada produk trial | `grep -c trialPromoModal` = **0** → promo hilang saat admin mematikan produk | ✅ |
| V4c | Fail-closed: tamper `price = 0.01` | `grep -c trialPromoModal` = **0** → promo tidak pernah menjanjikan "Gratis" palsu | ✅ |
| V4d | Pemulihan setelah V4b/V4c | nilai dipulihkan → `grep -c` kembali **2**; `migrate_114 --verify` → `[OK]` **exit 0** (invarian trial utuh) | ✅ |
| V5a | Invarian gerbang klien (HTML ter-render) | baris 1288 `localStorage.getItem(FLAG)` → 1289 `if (suppressed) return` → **1320** `modal.classList.remove('hidden')` → 1321 `cta.focus()` | ✅ |
| V5b | Perilaku gerbang klien (DOM nyata) | harness `/tmp/p115/v5b.mjs` — blok `<script>` ter-render dijalankan di **jsdom 30.1.1**: **13/13 assertion PASS**, exit 0 | ✅ |
| V6 | Dwibahasa | `GET /lang/switch/id` (307) → `/home`: `Potensi profit Rp 30.000 (Rp 10.000/hari selama 3 hari).` · `aria-label="Tutup promo"` · `Klaim Sekarang` · `Catatan jujur: … (gerbang penarikan).` · `Gratis Sewa GPU Magang` · chip `Uji Coba`. Default EN (`Potensi` **tidak** ada di mode `en`) → nol kebocoran silang | ✅ |
| V7a | Regresi: checkout trial | `POST /rentals/checkout` (`product_id=17`) → **HTTP 303 → /rentals**; kontrak `purchase_price=0.00`, `daily_roi=10000`, `expired_at` +3 hari, `source=purchase`; `wallet_ledger` **0 → 0**; saldo **0.00 → 0.00** | ✅ |
| V7b | Regresi: checkout BERBAYAR | fixture `P115-FIXTURE-CREDIT` Rp 200.000 → `POST /rentals/checkout` (`product_id=5`) → **HTTP 303 → /rentals**; debit `RENT-5-20260923220246` Rp 150.000; `ledger_sum == cache == 50.000`; kontrak `purchase_price=150000.00` | ✅ |
| V7c | Regresi: markup marketplace | `id="transactionModal"` = 1; urutan kartu tetap trial di puncak | ✅ |
| V8 | Gate CLI (final state) | `php -l` 5/5 bersih · parity `LULUS` exit 0 · hardcoded `0 temuan` exit 0 | ✅ |

### 4.1 Rincian V5b (13/13 PASS)

| # | Assertion | Hasil |
|---|---|---|
| A1 | Kunjungan pertama → modal TAMPIL | PASS (`hidden=false`) |
| A2 | Fokus awal di CTA | PASS (`activeElement=#trialPromoCta`) |
| A3a/A3b | Klik ✕ → tersembunyi + flag | PASS (`trial_popup_dismissed_6="1"`) |
| A4 | "Reload" dengan flag user ini → tetap `hidden` | PASS |
| A5 | **O1**: flag user lain (`_5`, `_99`) → modal user 6 **tetap tampil** | PASS |
| A6a/A6b | `Esc` → tersembunyi + flag | PASS |
| A7a/A7b | Klik backdrop → tersembunyi + flag | PASS |
| A8 | Tombol "Nanti Saja" → tersembunyi + flag | PASS |
| A9 | Klik CTA → flag tertulis (anti-spam) | PASS |
| A10 | Focus trap: `Tab` dari elemen terakhir → tombol tutup | PASS |

> **Batasan yang dinyatakan eksplisit:** harness V5b menjalankan skrip yang benar-benar ter-render di DOM jsdom, tetapi **persistensi `localStorage` antar navigasi browser nyata disimulasikan** (instance JSDOM punya storage sendiri; "reload" = instance baru + `beforeParse` menyemai flag). Uji browser/manual tetap disarankan sebagai konfirmasi akhir.

### 4.2 Integritas data pasca-uji

Seluruh artefak uji dihapus: fixture user 6 (username `p115_fixture`, `phone 081200001115`), kontrak trial & berbayar miliknya, dua baris `wallet_ledger` fixture, dan nilai `gpu_products.is_trial=1` yang dipulihkan (`is_active=1`, `price=0`). Kueri invarian yang sama dengan `scripts/reconcile_balances.php` dijalankan manual:

```
  user #1 cache=200050.00 ledger=200050.00 OK
  user #2 cache=50000.00  ledger=50000.00  OK
  user #3 cache=0.00      ledger=0.00      OK
  user #4 cache=50000.00  ledger=50000.00  OK
  user #5 cache=0.00      ledger=0.00      OK
  TOTAL DRIFT = 0
  fixture user6 sisa: 0 | ledger user6 sisa: 0 | rentals user6 sisa: 0
  PRODUK TRIAL: id=17 price=0.00 is_active=1 is_trial=1 | jumlah is_trial=1 → 1
  rental product_id=17 tersisa = 1 (milik user 3 — pra-ada, BUKAN fixture plan/115)
```

### 4.3 Catatan lingkungan

1. **`reconcile_balances.php --verify` tidak dijalankan** — skrip itu hanya menerima grup `$db['default']` sedangkan `database.php` memakai `local`/`dev`/`live` (temuan **pra-ada**, diidentifikasi plan/114 §6.3). Kueri invarian identik dijalankan manual (§4.2).
2. **`sys_get_temp_dir()` = `$TMPDIR`** (`/tmp/reasonix-session-tmp-*` di lingkungan ini), sehingga berkas sesi CI3 `sess_driver=files` berada di direktori tersebut — ini yang dibaca harness untuk menjawab CAPTCHA native. Tidak ada perubahan konfigurasi.
3. **`jsdom 30.1.1`** dipasang di `/tmp/p115` (bukan di repo) untuk V5b; `npm install` berhasil di lingkungan ini.
4. **Default bahasa = `en`** — pada V3 copy ter-render dalam EN (sesuai plan/94: `en` default untuk pengunjung baru); idiom ID diverifikasi terpisah pada V6.

---

## 5. Kebersihan & pemulihan lingkungan

- Semua skrip scratch (harness CAPTCHA, `v5b.mjs`, `node_modules`) berada di `/tmp/p115` — **nol** berkas scratch di repo.
- Direktori task sesi (`.reasonix/tasks/desktop-…--bash-1/`) yang terbentuk dari job server background **dihapus** → `git status` bersih dari artefak tak diinginkan.
- Server uji `php -S 127.0.0.1:8099` **dihentikan**; `application/config/**` tidak pernah diubah (tidak ada switch `$active_group`, tidak ada perubahan `base_url`).
- DB lokal dikembalikan ke keadaan pra-uji kecuali **deliverable** plan/114 (produk trial id 17) yang tetap utuh.

---

## 6. Definition of Done

| Kriteria | Status |
|---|---|
| Setiap berkas PHP termodifikasi lolos `php -l` | ✅ 5/5 |
| Kedua gate i18n exit 0 (`LULUS` / `0 temuan`) | ✅ |
| Paritas kamus 633/633, EN ≢ ID di luar allowlist, P3/P3b/P6 bersih | ✅ |
| Prasyarat plan/114 `--verify` exit 0 sebelum perubahan dirilis | ✅ (E0) |
| Flow diuji runtime (HTTP 200/303/307 sesuai harapan) + harness DOM | ✅ V1–V8 (V5b 13/13) |
| Diff akhir hanya berisi perubahan yang diniatkan | ✅ 5 berkas aplikasi (+201/−2 milik plan/115) + 2 berkas `plan/`; nol artefak |
| O1 (flag `localStorage` per-user) diimplementasikan | ✅ + bukti isolasi A5 |
| Ringkasan mencatat perubahan, bukti, dan catatan lingkungan apa adanya | ✅ (dokumen ini) |

---

## 7. Non-goals yang tetap tidak dikerjakan

1. Nol perubahan di bawah `system/`; nol DDL/seed/migrasi baru; nol route baru; nol kunci `system_settings` baru.
2. Nol perubahan panel admin (termasuk tidak ada toggle "tampilkan popup").
3. `views/marketplace/index.php`, `Marketplace.php`, `Rental_model`, `Wallet_model`, `Claim/Checkin/Promoter` model, dan modal plan/89 (`z-[70]`) + widget absensi plan/112 **tidak disentuh**.
4. Gerbang "umur akun" tidak ditambahkan — gate tetap `lifetime_rentals === 0` (konsisten O3 plan/114).
5. E11 (sinkronisasi `docs/4_UI_UX_GUIDELINES.md` & `docs/3_ROADMAP.md`) **ditunda** ke plan terpisah — mengikuti kebiasaan repo (plan/111, 113, 114-E19).

---

## 8. Tindak lanjut yang disarankan (opsional, di luar plan/115)

| # | Item | Catatan |
|---|---|---|
| **O2** | Kunci scroll `body` (`overflow-hidden`) selama modal terbuka | UX; menyimpang dari paritas modal existing — belum dikerjakan |
| **O3** | Baris info "batas 1× per akun" di dalam modal (butuh 1 kunci kamus tambahan) | Transparansi kuota trial — belum dikerjakan |
| **O4** | Deviasi pra-ada `#inactiveWarnModal` = `z-[70]` (di luar tabel guideline §2) | Modal plan/115 memakai `z-[60]` yang benar; harmonisasi modal lama = plan terpisah |
| **O5** | Perbaiki `load_db_config()` di `scripts/reconcile_balances.php` (only `$db['default']`) | Temuan pra-ada yang sama dengan plan/114 O5 |
