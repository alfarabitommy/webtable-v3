# Plan 109 — SUMMARY (Eksekusi Tampilan Nominal NET Penarikan)

> **Status:** SELESAI untuk seluruh langkah kode (P1–P9). Verifikasi CLI
> dijalankan **nyata** di sesi ini (bukti per perintah ada di §5). Verifikasi
> **runtime ber-browser/ber-sesi (V1–V7)** dinyatakan **"Pending Manual QA by
> User"** — bukan karena `bash` diblokir (bash BEKERJA; PHP 8.3.6, MySQL dev,
> dan `php -S` semuanya tersedia), melainkan karena **kredensial login
> admin/member tidak tersedia di lingkungan ini** dan **`db_webtable` lokal
> berisi 0 baris `withdrawals`** sehingga tidak ada baris yang bisa dirender.
>
> **Rencana acuan:** `plan/109_WITHDRAWAL_NET_AMOUNT_DISPLAY_PLAN.md` +
> keputusan owner `dec-ce71528c38328365`. Tidak ada DDL, tidak ada migrasi,
> tidak ada perubahan `wallet_ledger`, tidak ada perubahan pada `system/`.

---

## 1. Ringkasan hasil

Seluruh surface penarikan sekarang menampilkan **NET** (dana yang wajib
ditransfer admin) sebagai nilai primer, dengan gross/fee sebagai rincian:

| # | Surface | Sebelum | Sesudah |
|---|---|---|---|
| 1 | Antrean Pending Withdrawals (Command Center) | `$wd->amount` (gross) tanpa label | Label **"Wajib Transfer (Net)"** + `Rp {net}` + sub-teks `(Penarikan: Rp {gross} \| Biaya: Rp {fee})` |
| 2 | Dialog konfirmasi Approve | `confirm('Approve withdrawal WD-…?')` | `confirm('Pastikan Anda SUDAH mentransfer Rp {net} ke {provider} - {nomor} a/n {pemilik}. Setujui {wd_number}? (Penarikan Rp {gross} \| Biaya Rp {fee})')` |
| 3 | Flash pasca-approve (admin) | "… berhasil disetujui." | "… disetujui. Transfer NET Rp {net} ke akun e-wallet penarikan." |
| 4 | Riwayat penarikan (`/admin/history/withdrawal`) | 1 kolom "Nominal" (= gross) | 3 kolom **Gross / Biaya / Net (ditransfer)** |
| 5 | Kartu penarikan tertunda (member) | `Rp {gross}` saja | **Estimasi Dana Diterima (Net)** sebagai nilai primer + rincian Nominal Penarikan (Gross) & Biaya Admin (Fee) |
| 6 | Preview form penarikan (member) | baris Fee + Net | 3 baris: **Nominal Penarikan (Gross)** / **Biaya Admin (Fee)** / **Estimasi Dana Diterima (Net)** |
| 7 | Notifikasi `notif_wd_approved` | 1 param = **gross** | 3 param = **gross, fee, net** — body menyebut dana yang dikirim ke e-wallet |
| 8 | `wallet_ledger` | — | **TIDAK DISENTUH** (sesuai D2): debit tetap merekam gross penuh |

---

## 2. Berkas yang berubah

### 2.1 Berkas BARU (1)

| Berkas | Isi |
|---|---|
| `application/helpers/withdrawal_amount_helper.php` | Choke-point murni `withdrawal_amount_parts($row, ?callable)` → `{gross, fee, net, legacy}` dan `withdrawal_amount_decorate(array $rows, ?callable)` → menempelkan `gross_eff`/`fee_eff`/`net_eff`/`amount_legacy` pada baris object **maupun** array. Uang tetap `int` (L6: format hanya di view). |
| `plan/109_WITHDRAWAL_NET_AMOUNT_DISPLAY_SUMMARY.md` | Dokumen ini |

### 2.2 Berkas DIUBAH (11)

| Berkas | Perubahan |
|---|---|
| `application/config/autoload.php` | `+ 'withdrawal_amount'` pada `$autoload['helper']` + komentar preseden |
| `application/models/Wallet_model.php` | `get_pending_withdrawals()`: baris di-dekorasi via `withdrawal_amount_decorate($rows, [$this, 'calculate_withdrawal_fee'])` |
| `application/models/Admin_model.php` | `get_history_withdrawals()`: dekorasi; **BARU** `get_withdrawal_queue()` (SQL dipindahkan apa adanya dari controller) + helper privat `_withdrawal_fee_calculator()` |
| `application/controllers/Admin.php` | `index()`: SQL inline (7 baris) → `$this->Admin_model->get_withdrawal_queue()`; `approve_withdrawal()`: params notifikasi `[gross, fee, net]` + flash menyebut NET |
| `application/helpers/i18n_helper.php` | `i18n_notification_text()`: snapshot `$stored` + **guard arity** `vsprintf` berbasis seluruh conversion specifier |
| `application/views/admin/dashboard.php` | Kartu antrean: blok variabel `$wd_gross/$wd_fee/$wd_net/$wd_confirm`, nilai primer NET + label + sub-teks, `onsubmit` memakai `$wd_confirm_attr`, escape `html_escape()` untuk `bank_name`/`account_number`/`account_name`/`phone`/`wd_number` |
| `application/views/admin/history.php` | Header withdrawal → 3 kolom (Gross/Biaya/Net) + cabang `else` untuk deposit (blok nominal deposit plan/102 tidak berubah) |
| `application/views/wallet/index.php` | Kartu penarikan tertunda: NET primer + rincian gross/fee (blok `LEDGER HISTORY` tidak disentuh) |
| `application/views/wallet/withdraw.php` | Baris Gross baru (`#wd_gross`) + JS `grossEl` pada `refresh()` |
| `application/language/english/app_lang.php` | 3 nilai diubah + `notif_wd_approved_body` (arity 1 → 3) |
| `application/language/indonesian/app_lang.php` | idem (parity 1:1) |

### 2.3 DIVERIFIKASI SAJA — nol perubahan

`database.sql` (`withdrawals` sudah punya `gross_amount`/`fee_amount`/`net_amount`) ·
`Wallet_model::create_withdrawal()` · `Admin_model::get_all_withdrawals()` +
`Admin::export_csv('withdrawals')` (sudah Gross/Fee/Net) · seluruh jalur
`wallet_ledger` · `routes.php` · `scripts/backfill_withdrawal_fees.php` ·
`system/**` (termasuk fix `Pagination.php` & entri `webp` di `mimes.php`).

---

## 3. Pemetaan langkah P1–P9 → hasil

| Langkah | Status | Catatan |
|---|---|---|
| **P1** Helper choke-point | ✅ | 2 fungsi murni, `function_exists()`-guarded, CLI-safe |
| **P2** Registrasi autoload | ✅ | `autoload.php:108` |
| **P3** Dekorasi model | ✅ | `Admin_model::get_withdrawal_queue()` (BARU) + `_withdrawal_fee_calculator()`; `get_history_withdrawals()`; `Wallet_model::get_pending_withdrawals()` |
| **P4** Controller | ✅ | SQL inline dihapus; notifikasi `[gross, fee, net]`; flash NET |
| **P5** Dashboard admin | ✅ | NET primer + label "Wajib Transfer (Net)" + sub-teks; confirm diperkaya |
| **P6** Riwayat admin | ✅ | 3 kolom terpisah |
| **P7** Kartu member | ✅ | NET primer + rincian; ledger tidak disentuh |
| **P8** Preview form member | ✅ | 3 baris + JS gross |
| **P9** Kamus + guard notifikasi | ✅ | 4 nilai/idiom, parity 602/602, guard arity |
| **P10** Verifikasi | ⚠️ | CLI **selesai** (§5); matriks browser **pending QA manual** (§7) |

---

## 4. Tiga penyimpangan terkontrol dari dokumen plan (dengan alasan)

1. **Guard arity diperbaiki dari `substr_count($body, '%s')` → regex seluruh
   conversion specifier.** Draf plan hanya menghitung `%s`. Verifikasi
   menemukan ini **regresi nyata**: `notif_rebate_body` (positional `%1$d` +
   `%2$s`), `notif_roi_body` (`%s` + `%d`), dan `notif_rental_injected/expired`
   (`%d` saja) **tidak akan dirender lagi** (specifier mentah bocor ke member)
   atau kehilangan argumen. Implementasi final:
   `preg_match_all('/%(?:(\d+)\$)?[bcdeEfFgGosuxX]/', …)` → `expected =
   max(indeks positional tertinggi, jumlah specifier sequential)`, seluruh
   `params` diteruskan **tanpa** `array_slice`. Dibuktikan pada 9 body
   notifikasi nyata (§5.4) — nol specifier mentah.
2. **Dekorasi baris array diperbaiki.** Pada `foreach ($rows as $row)`, `$row`
   adalah **salinan**, sehingga penulisan `$row['gross_eff'] = …` hilang tanpa
   error (bug ditemukan oleh probe fixture §5.3). Final: iterasi
   `foreach ($rows as $i => $row)` + tulis balik `$rows[$i][…]`.
3. **Escape konfirmasi Approve diperkuat (temuan keamanan pada sink BARU).**
   `$wd_confirm` memuat **data dari member** (`account_holder` bebas teks ≤100
   char, `account_number`). Dua lapis escape: literal string JS
   (`str_replace(['\\', "'"], ['\\\\', "\\'"])`) lalu atribut HTML
   (`html_escape()` = `htmlspecialchars(ENT_QUOTES)` — `system/core/Common.php:762`).
   Sekaligus `html_escape()` diterapkan pada `bank_name`/`account_number`/
   `account_name`/`phone`/`wd_number` di kartu yang sama (sebelumnya dirender
   mentah — sink stored-XSS lama yang memapar admin). Dibuktikan dengan payload
   hostile (§5.5). **Tidak ada perubahan perilaku untuk data normal.**

Tidak ada perubahan lain di luar plan.

---

## 5. Verifikasi yang BENAR-BENAR dijalankan (dengan hasil)

### 5.1 Lint (V-L) — 12 berkas, semuanya bersih

```
php -l application/helpers/withdrawal_amount_helper.php   -> No syntax errors detected
php -l application/helpers/i18n_helper.php                -> No syntax errors detected
php -l application/config/autoload.php                    -> No syntax errors detected
php -l application/models/Admin_model.php                 -> No syntax errors detected
php -l application/models/Wallet_model.php                -> No syntax errors detected
php -l application/controllers/Admin.php                  -> No syntax errors detected
php -l application/views/admin/dashboard.php              -> No syntax errors detected
php -l application/views/admin/history.php                -> No syntax errors detected
php -l application/views/wallet/index.php                 -> No syntax errors detected
php -l application/views/wallet/withdraw.php              -> No syntax errors detected
php -l application/language/english/app_lang.php          -> No syntax errors detected
php -l application/language/indonesian/app_lang.php       -> No syntax errors detected
```

### 5.2 Gate i18n (V-G) — keduanya exit 0

```
php scripts/audit_i18n_parity.php
  EN keys : 602 | ID keys : 602 | Paritas : 1:1 OK
  Nilai identik (di luar allowlist): 0 | Nominal literal (P3): 0 | Newline (P6): 0 | Prosa ID di EN (P3b): 0
  [OK] Semua gate paritas & higienitas kamus LULUS.       -> exit 0

php scripts/audit_i18n_hardcoded.php
  File dipindai : 84 | Total temuan : 0
  [OK] 0 temuan — tidak ada string hardcoded di surface member.  -> exit 0
```

Paritas **602/602 dipertahankan** (nol key baru — D9): hanya **nilai** yang
berubah, dan `%s` pada `notif_wd_approved_body` EN & ID sama-sama **3**.
Gate paritas tidak memeriksa arity placeholder → dicek manual via grep (3 = 3).

### 5.3 Probe helper (V-P1) — `/tmp` scratch, dihapus setelah selesai

```
legacy        gross=1000000 fee=71500 net=928500 legacy=true      <- gross 0 -> fallback amount, fee/net direkalkulasi tier
modern        gross=1000000 fee=71500 net=928500 legacy=false
zero          gross=0 fee=0 net=0 legacy=true
inconsistent  gross=3000000 fee=156500 net=2843500 legacy=false   <- fee+net != gross -> rekalkulasi
tanpa kalkulator: gross=1000000 fee=0 net=1000000                 <- degradasi aman (net = gross - fee)
dekorasi object: 1000000 / 71500 / 928500  (legacy=true & false)
dekorasi array : 1000000 / 71500 / 928500
```

Angka tier cocok dengan PRD/seed: Rp 1.000.000 → fee 71.500 / net 928.500;
Rp 500.000 → fee 44.000 / net 456.000; Rp 5.000.000 → fee 206.500 / net 4.793.500.

### 5.4 Probe render data nyata (V-P2) — fixture di dalam 1 transaksi + **ROLLBACK**

Fixture 3 baris `withdrawals` (modern pending, legacy pending, success)
disisipkan memakai `user_id` + `bank_account_id` **nyata** dari `db_webtable`,
dibaca lewat query yang **dicerminkan dari model produksi**, didekorasi
**helper produksi**, lalu `ROLLBACK` (diverifikasi: `withdrawals` 0 → 0,
**DB TIDAK BERUBAH**):

```
A. KARTU DASHBOARD ADMIN
  T109-MODERN-PENDING  [Wajib Transfer (Net)] Rp 928.500  (Penarikan: Rp 1.000.000 | Biaya: Rp 71.500)
     confirm(): "...mentransfer Rp 928.500 ke DANA - 081200000001 a/n Leader VIP. Setujui T109-MODERN-PENDING?..."
  T109-LEGACY-PENDING  [Wajib Transfer (Net)] Rp 456.000  (Penarikan: Rp 500.000 | Biaya: Rp 44.000)  legacy=true
B. RIWAYAT ADMIN   T109-MODERN-SUCCESS  Gross Rp 5.000.000 | Biaya Rp 206.500 | Net Rp 4.793.500
C. KARTU MEMBER    [Estimasi Dana Diterima (Net)] Rp 928.500 / Nominal Penarikan (Gross): Rp 1.000.000 · Biaya Admin (Fee): Rp 71.500
D. NOTIFIKASI      ["1.000.000","71.500","928.500"]  dan legacy ["500.000","44.000","456.000"]
```

### 5.5 Probe guard notifikasi + escape konfirmasi (V-P3)

```
A. 9 body notifikasi nyata (notif_roi, notif_rebate, notif_rental_injected,
   notif_rental_expired, notif_balance_credit, notif_wd_approved [baru & legacy],
   notif_wd_declined, notif_deposit_approved) -> raw-specifier = tidak (semua render)
B. escape konfirmasi dengan 5 payload hostile (kutip ganda, kutip tunggal,
   backslash di akhir, tag HTML) -> attr_aman=ya, js_literal_aman=ya (semua)
```

### 5.6 Pre-flight DB read-only (V-P4)

```
status withdrawals: (kosong)  | baris legacy (gross/fee/net <= 0): 0
notifikasi keyed notif_wd_approved: 0
tabel: users 5 | withdrawals 0 | bank_accounts 2 | wallet_ledger 4 | gpu_products 13 | ewallet_providers 5
```

→ Jalur fallback legacy **tetap dipertahankan** sebagai jaring pengaman, tetapi
populasinya nol di dev DB (dan tidak dapat diuji dengan data live di sini).

### 5.7 Smoke boot aplikasi via HTTP (V0) — berhasil

```
DB_HOSTNAME=127.0.0.1 php -S 127.0.0.1:8099 index.php
  /login          -> HTTP 200 (34.840 B)
  /register       -> HTTP 200 (35.300 B)
  /lang/switch/en -> HTTP 307
  /control-panel  -> HTTP 200 (5.037 B)
  /help           -> HTTP 307
```

Ini membuktikan **entri autoload baru tidak mematikan aplikasi**. Tanpa
`DB_HOSTNAME=127.0.0.1`, `/login` = HTTP 500 — penyebabnya **pra-eksisting**:
CI3 memakai hostname `localhost` → socket Unix MySQL yang tidak ada di
lingkungan ini (`application/logs/`: `mysqli::real_connect(): (HY000/2002): No
such file or directory`). **Bukan** akibat perubahan plan/109 (tanpa perubahan
plan/109 pun hasilnya sama).

---

## 6. Status gate i18n (ringkas)

| Gate | Hasil |
|---|---|
| `php scripts/audit_i18n_parity.php` | **LULUS**, exit 0 — EN 602 / ID 602, 1:1, 0 nilai identik, 0 nominal literal |
| `php scripts/audit_i18n_hardcoded.php` | **0 temuan**, exit 0 |
| Arity `%s` `notif_wd_approved_body` | EN 3 = ID 3 (grep manual) |
| Key kamus ditambah/dihapus | **0 / 0** (hanya 4 nilai diubah per idiom) |

---

## 7. Runtime verification V1–V7 — **Pending Manual QA by User**

Verifikasi berikut **tidak dapat dieksekusi di lingkungan ini** dan
**dinyatakan Pending Manual QA by User**. Alasan yang jujur: `bash` **tidak**
diblokir (semua perintah §5 jalan), namun (a) **kredensial login admin & member
tidak tersedia**, dan (b) **`db_webtable` berisi 0 baris `withdrawals`** →
halaman `/admin` & `/wallet` hanya bisa dibuka oleh sesi terautentikasi dengan
data nyata. Jadi status ini **bukan karena pembatasan host**, melainkan karena
tidak adanya sesi + data.

| # | Uji | URL / aksi | Ekspektasi (belum diverifikasi) |
|---|---|---|---|
| **V1** | Antrean Pending Withdrawals | `GET /admin` | Kartu menampilkan "Wajib Transfer (Net)" + `Rp {net}` + `(Penarikan: Rp {gross} \| Biaya: Rp {fee})` |
| **V2** | Riwayat penarikan | `GET /admin/history/withdrawal` | Kolom terpisah Gross / Biaya / Net (ditransfer); tab deposit tetap 1 kolom "Nominal" |
| **V3** | Dialog + flash Approve | klik **Approve** pada WD pending | Dialog menyebut nominal NET + provider - nomor + a/n pemilik; setelah approve, flash menyebut NET; baris keluar dari antrean; badge alert center turun |
| **V4** | Export CSV | `GET /admin/export_csv/withdrawals` | Header tetap `Gross (IDR), Fee (IDR), Net (IDR)` (nol regresi) |
| **V5** | Kartu member | `GET /wallet` (member dengan WD pending) | "Estimasi Dana Diterima (Net)" sebagai nilai primer + rincian gross/fee; blok LEDGER tidak berubah |
| **V6** | Preview form penarikan | `GET /wallet/withdraw`, ketik `1000000` | Gross Rp 1.000.000 / Fee Rp 71.500 / Net Rp 928.500 (mengikuti tier `system_settings` aktif) |
| **V7** | Notifikasi member | buka lonceng/`/notifications` setelah approve | Body menyebut gross + biaya + **dana net**; baris lama tidak mengalami fatal |

**Skrip QA manual yang disarankan** (jalankan di lingkungan ber-data):
1. `SELECT COUNT(*) FROM withdrawals WHERE status='pending'` — pastikan ≥ 1.
2. Jalankan V1→V7 di atas; catat HTTP code + teks yang terlihat.
3. Cek `application/logs/` setelah V3 & V7 — ekspektasi **tanpa** `ArgumentCountError` / error baru.
4. (Opsional) `SELECT id, gross_amount, fee_amount, net_amount FROM withdrawals WHERE status='pending'` lalu bandingkan dengan angka yang dirender kartu.

---

## 8. Perilaku yang diketahui / batasan

1. **Baris notifikasi lama (`notif_wd_approved`, arity 1).** Guard tidak fatal:
   slot 1 (gross) tetap benar dan slot fee/net yang tidak tersimpan dirender
   sebagai em-dash — mis. `"Penarikan sebesar Rp 1.000.000 telah diproses.
   Biaya admin Rp —. Dana Rp — dikirim ke akun e-wallet Anda."` Ini perilaku
   yang disetujui di plan §9b (jaring pengaman anti-`ArgumentCountError`).
   Populasi baris tersebut diukur pada V-P4: **0**.
2. **Fallback legacy read-side** memakai kalkulator tier yang berlaku **saat
   ini** (`Wallet_model::calculate_withdrawal_fee()`), sumber yang sama dengan
   jalur export CSV. Untuk baris historis yang fee-nya berbeda akibat perubahan
   setting, angka fallback bisa berbeda dari fee historis — hanya aktif saat
   `fee/net ≤ 0`/inkonsisten dan tidak menulis apa pun ke DB.
3. **`history.php` merender `bank_name`/`account_number` mentah** (pra-eksisting,
   di luar diff plan/109). Risikonya rendah: `account_number` tervalidasi
   `^08[0-9]{8,11}$` dan `bank_name` berasal dari katalog admin. Kandidat
   hardening lanjutan (dicatat, tidak dikerjakan).
4. **`db_webtable` lokal kosong untuk `withdrawals`**, sehingga tidak ada
   evidence visual dari data nyata pengguna.

---

## 9. Rollback

`git revert <commit>` — tidak ada migrasi/DDL/backfill dan **tidak ada mutasi
data** (fixture probe memakai transaksi yang di-`ROLLBACK`; `withdrawals`
sebelum = sesudah = 0). Bila hanya kamus yang di-rollback, guard §5.5 tetap
aman untuk baris ber-`params` 3 elemen (vsprintf mengabaikan argumen berlebih).

---

## 10. Follow-up (di luar scope, seperti tercatat di plan §11)

1. Header CSV admin masih berbahasa Inggris (`Gross (IDR)` …) vs invariant L1.
2. Belum ada kartu riwayat penarikan untuk member (hanya WD `pending` + ledger).
3. `withdrawals.status = 'processing'` masih nilai mati di ENUM + seed.
4. Notifikasi decline menyebut gross (benar secara semantik: pengembalian penuh).
5. Hardening `html_escape()` untuk `bank_name`/`account_number` di
   `views/admin/history.php:81` (sisa sink pra-eksisting).
