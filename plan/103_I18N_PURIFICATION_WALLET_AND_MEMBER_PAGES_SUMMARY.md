# Plan 103 — SUMMARY: i18n Purification (Wallet, QRIS Payment & Member Views)

> **Status:** SELESAI & TERVERIFIKASI (implementasi penuh plan/103, step 0–14).
> **Blueprint:** `plan/103_I18N_PURIFICATION_WALLET_AND_MEMBER_PAGES_PLAN.md` (939 baris).
> **Rentang:** step 0 baseline → step 14 dokumen ini; 32 berkas PHP di-lint, 2 gate otomatis hijau, migrasi DB idempotent, verifikasi HTTP dwibahasa pada 9 halaman + 5 status invoice.

---

## 1. Ringkasan Eksekusi

| Step | Fase | Hasil | Bukti |
|---|---|---|---|
| **0** | Baseline audit | `scripts/audit_i18n_hardcoded.php` dibuat → **158 temuan** pada 72 berkas (R1 44 · R2 102 · R3 8 · R4 4) | `plan/.audit103_baseline.txt` |
| **1** | Helper i18n | `i18n_date()`, `i18n_datetime()`, `i18n_ts()`, `_i18n_date_subst()`, `i18n_date_lexicon()`, `i18n_notification_text()`, `i18n_ledger_description()` | smoke `php -r` EN/ID OK |
| **2–3** | Kamus | **+213 key** simetris, **−1 key** orphan, 1 key dinormalisasi → **378 → 591** per idiom | parity 591 ≡ 591 |
| **4–6** | Controller/message | 41 literal flashdata → 0; `code → key` const map di `Wallet` (+ `_deposit_message`, `_wd_message`), mapper di `Rentals`, `Team`, `Promoter_model` | R1 44 → **0** |
| **7** | Rate limit L1 | `rate_limit_message($sec, $idiom = NULL)` + `rate_limit_json_response($t, $idiom)`; `Admin_auth` meneruskan `'id'` | V52: admin tetap Indonesia |
| **8** | Notifikasi persisten | Migrasi `title_key` + `params` (JSON) + backfill **26 baris**, `insert_keyed()` menggantikan **16 call-site** | migrate run ×2 = no-op |
| **9** | View sekunder | notification (0→keyed), help (0→47 key), home, profile, team, rentals, header (`SYNAPSE_I18N` diperluas) | 0 literal |
| **10–11** | Wallet & QRIS | Langkah kanonik masuk kartu QRIS, catatan admin berlabel, timer ber-satuan + `aria-label`, `data-confirm`, badge status, tanggal ber-lokalisasi | matriks 5 status × 2 idiom |
| **12** | Guardrail | `scripts/audit_i18n_parity.php` (P1/P3/P5/P6) + `audit_i18n_hardcoded.php` diperketat | dua-duanya **exit 0** |
| **13** | Verifikasi | 32 berkas `php -l` bersih; 9 halaman × 2 idiom 0 leak; 5 status invoice × 2 idiom 0 leak; invariant DB OK | §5 |
| **14** | Dokumen ini | — | — |

**Angka kunci:** **158 → 0** temuan hardcoded; **591 ≡ 591** key; **2 berkas baru** (skrip gate) + **1 skrip migrasi**; 32 berkas PHP lint-clean; **0 mutasi uang** (0 baris `wallet_ledger` ditulis selama verifikasi); **0 pelanggaran invariant** setelah koreksi metodologi (§4.2).

---

## 2. Rincian Per Fase

### 2.1 Kamus dwibahasa — 378 → 591 key

| Blok | Key baru | Contoh |
|---|---|---|
| Wallet & QRIS | 12 | `wallet_pay_qris_notes_label`, `wallet_status_*`, `wallet_copy_aria` |
| Registri pesan deposit | 15 | `deposit_err_*` (10), `deposit_ok_*` (3), `wallet_err_amount_invalid` |
| Registri pesan penarikan | 17 | `wd_err_*`, `wd_ok_*` |
| Bank binding | 5 | `bb_err_already_bound`, `bb_ok_bound`, … |
| Rentals / Auth / Profile / rate limit | 16 | `rental_ok_activated`, `auth_err_captcha`, `ratelimit_too_many` |
| Label & pesan `form_validation` | 9 | `fv_label_*` (6), `fv_msg_*` (3) |
| Notifikasi persisten | 43 | `notif_roi_title/body`, `notif_promoter_off_*`, … |
| Leksikon tanggal | 19 | `dt_mon`…`dt_sun`, `dt_jan`…`dt_dec` |
| Satuan waktu & frasa relatif | 9 | `time_unit_*`, `time_minutes_ago_short` |
| View sekunder & help center | 47 | `help_q_*`, `notif_empty_body`, `notif_today` |
| Promoter (member-facing) | 15 | `promo_err_*`, `promo_ok_submitted` |
| Ledger description | 6 | `ledger_roi_daily`, `ledger_rebate`, … |

**Perubahan destruktif (disengaja, terdokumentasi):**

| # | Aksi | Alasan |
|---|---|---|
| D1 | `wallet_principal_in` **dihapus** (2 idiom) | 0 referensi (orphan) |
| D2 | `wallet_copy_amount_title` **dihapus** → diganti `common_copy_title` | dedup + dipakai 2 view |
| D3 | `wallet_amount_prompt` dinormalisasi | nilai ber-newline literal (baris terbelah) → escape `\n` satu baris |
| D4 | `wallet_pay_conf_late` kehilangan `·` | pemisah milik markup, bukan kamus (P6) |
| D5 | `wallet_pay_expires_at` `Before`/`Sebelum` → `Expires at`/`Berakhir pada` | copy lebih jelas di kedua idiom |
| D6 | ID `wallet_pay_status_*` tetap; **chip** memakai `wallet_status_*` | registri pendek (chip) vs panjang (banner) sengaja dipisah |
| D7 | `lang_english`/`lang_indonesian` ID → `Inggris`/`Indonesia` | temuan P5 nyata: nilai masih Inggris |
| D8 | `home_stat_value` ID → `Total Nilai · USD`; `team_promo_badge` ID → `Reward GPU` | temuan P5 nyata |
| D9 | `ledger_deposit` ID → `Setoran` | membedakan dari label teknis |
| D10 | 4 key bermuatan nominal → argumen `%s` | invariant **L6**: `Rp`+angka tidak pernah masuk kamus |

### 2.2 Controller & flashdata (R1: 44 → 0)

**`Wallet.php`** — peta `code → key` sebagai `private const` (D2 plan):

```php
private const DEPOSIT_ERR_KEYS = ['invalid_amount' => 'deposit_err_invalid_amount', …];
private const WD_ERR_KEYS      = ['below_min' => 'wd_err_below_min', …];
private const AMOUNT_KEYS      = ['deposit_err_below_min', …];  // butuh argumen Rp
```

- `_deposit_message()` / `_wd_message()`: `code` → key; prosa model → `log_message()` (D1/D3 blueprint). Nominal dikirim sebagai **argumen** `sprintf` (L6).
- 15 `set_flashdata(messages literal)` + 2 `show_error('Akses ditolak: …')` → 0.
- `topup()` sukses: 3 konkatenasi → `sprintf(lang('deposit_ok_created'), invoice, idr, kode)`.

**`Rentals.php`** — 5 literals → key; `_checkout_message()` memetakan `product_unavailable` / `insufficient` / `max_per_user`; notifikasi ROI → `insert_keyed('notif_roi', …)`.

**`Auth.php`** — 12 literal (`errors[]`) → key, **termasuk 1 literals yang lolos dari sapuan regex awal** (`Kode keamanan salah…` muncul 2×) yang ditemukan lewat pembacaan ulang; ditambah `_set_fv_messages()` supaya pesan bawaan CI3 (`required`/`min_length`/`matches`) tidak lagi berbahasa Inggris di mode `id`, dan label field (`Nomor Telepon`, `Kata Sandi`, …) tidak lagi literal Indonesia di mode `en`.

**`Profile.php`** — 6 flashdata + 4 label `set_rules` → key; `$this->upload->display_errors()` (prosa **Inggris** dari `system/language/english/upload_lang.php`) tidak lagi ditampilkan ke member → pesan generik ber-kamus + detail ke log.

**`Team.php` / `User.php` / `Notification.php` / `MY_Controller.php`** — `Sesi habis. Silakan login ulang.` (6 kemunculan, termasuk 3 di `Team.php`) → `common_session_expired`; `Team.php` mendapat `_claim_message()`/`_wage_message()`; 2 notifikasi bonus/gaji → `insert_keyed`.

**`Promoter_model`** — `localize_result()` memetakan 11 `code` member-facing; `quota_exceeded` kini mengirim `max` sebagai **parameter**, bukan merangkai kalimat di model.

### 2.3 Rate limit & invariant L1 (step 7)

```php
function rate_limit_message($remaining_seconds, $idiom = NULL)   // null = idiom request
function rate_limit_json_response($throttle, $idiom = NULL)
```

`_rate_limit_line($idiom)` membaca berkas kamus idiom yang diminta **tanpa** memuat idiom kedua secara permanen (L4 terjaga) dan punya fallback Indonesia untuk kasus kamus tak terbaca.

- Call-site member (Auth, Rentals, Team, Wallet) → idiom sesi.
- Call-site admin (`Admin_auth`) → `'id'` **eksplisit**.
- **Bukti runtime:** 7 percobaan login gagal di `/control-panel` → flash `SYSTEM HALTED: Terlalu banyak percobaan gagal. Silakan coba lagi dalam N menit.` (Indonesia, meski idiom aktif `english`).

### 2.4 Notifikasi persisten (W8)

**Skema:** `user_notifications` + `title_key VARCHAR(64) NULL`, `params JSON NULL`; `title`/`message` **dipertahankan** (retensi + fallback legacy).

**Migrasi** `scripts/migrate_103_notification_i18n.php`:

| Tahap | Hasil |
|---|---|
| DDL | 2 kolom ditambahkan, terverifikasi via `information_schema` |
| Backfill | **26 dari 53** baris dipetakan ke 11 key kanonik; **27** tak dikenal dibiarkan `title_key = NULL` |
| Verifikasi | params JSON invalid **0**; title_key non-kanonik **0** |
| **Re-run `--apply`** | **no-op** (DDL dilewati, 0 kandidat) |

**16 call-site** → `Notification_model::insert_keyed($user_id, $key, $params, $type)`:

| Berkas | Sebelum | Sesudah |
|---|---|---|
| `Admin.php` | 8 | `notif_deposit_approved`, `notif_deposit_declined`, `notif_wd_approved`, `notif_wd_declined`, `notif_unbanned`, `notif_promoter_on/off`, `notif_balance_credit/debit`, `notif_rental_injected` |
| `Team.php` | 2 | `notif_bonus_l1`, `notif_wage` |
| `Rentals.php` | 1 | `notif_roi` |
| `Promoter_model.php` | 2 | `notif_promoter_approved/rejected` |
| `Rental_model.php` | 3 | `notif_rebate`, `notif_rental_expired` ×2 |

**Bukti round-trip** (5 baris di-seed: 4 ber-key + 1 legacy):

| Probe | mode `en` | mode `id` |
|---|---|---|
| `notif_roi` | `Daily ROI Credited` / `ROI of Rp 150.000 has been credited…` | `ROI Harian Cair` / `ROI sebesar Rp 150.000…` |
| `notif_wd_declined` | `Withdrawal Rejected` / `Your withdrawal was rejected…` | `Penarikan Ditolak` |
| `notif_balance_credit` | `Rp 1.000.000 has been added to your balance by admin.` | `Saldo Ditambahkan Admin` |
| **legacy (NULL)** | `Pesan Lama` (apa adanya) | `Pesan Lama` (apa adanya) |
| Bell dropdown | keyed EN | keyed ID |

> Satu baris notifikasi yang sama berubah bahasa saat pembaca menekan tombol switch — inilah target W8.

### 2.5 View — QRIS & timer (step 10)

**`wallet/pay.php`:**

1. **Langkah kanonik dipindah KE DALAM kartu QRIS** (3 langkah dari kamus, selalu ada) + catatan admin menjadi blok **aditif berlabel** `wallet_pay_qris_notes_label`.
   → Section tidak lagi bisa menjadi unilingual meski `qris_payment_instructions` diisi Bahasa Indonesia (panel admin 100% ID — L1).
2. **Timer ber-satuan:** `data-unit-h/m/s` + `data-expired-label` dari kamus; `aria-label` di-`setAttribute` (`"1 hours 5 minutes 3 seconds"` / `"1 jam 5 menit 3 detik"`); angka tetap `HH:MM:SS` (L6). Toggle kelas `is-urgent` **dibatalkan** — view ini tidak punya `<style>` dan kelas tanpa aturan = dead code (dictat di komentar).
3. **Dialog konfirmasi → `data-confirm`** + handler terpusat; menghapus pola `str_replace("'", "\\'", …)` di atribut `onsubmit` yang hanya meng-escape apostrof.
4. Tanggal → `i18n_datetime()` (`04 Sep 2026 16:01` di kedua idiom, bukan `Sep` vs `Sep`).

**`wallet/index.php`:** badge `Pending` (literal **Inggris** yang bocor di mode `id`) → `wallet_status_pending/waiting`; tenggat & ledger → `i18n_datetime()`; deskripsi ledger → `i18n_ledger_description()`; contoh nominal pada catatan kode unik → argumen `sprintf` (L6).

### 2.6 Sisa sekitar ~30 literal di model → **diagnostik**

Model tetap mengembalikan `{success, code, message}` dengan prosa Indonesia, tetapi **tidak ada lagi jalur** yang meneruskannya ke UI:

- `Wallet`: `_deposit_message()`/`_wd_message()` mengabaikan `message` (hanya `log_message`).
- `Rentals`: `_checkout_message()` + pesan sukses dibangun dari kamus.
- `Team`: `_claim_message()`/`_wage_message()`/Promoter `localize_result()`.
- Auditor menambahkan **rule R7** (`raw-model-message`) untuk mendeteksi `set_flashdata(…, $result['message'])` / `api_*(…$result['message']…)` — jalur kebocoran yang sesungguhnya. Hasil: **0**.

---

## 3. Definition of Done — Checklist

| # | Kriteria | Status |
|---|---|---|
| 1 | `audit_i18n_parity.php` exit 0 | ✅ 591 ≡ 591, 0 kolisi tak sah, 0 pelanggaran P6 |
| 2 | `audit_i18n_hardcoded.php` exit 0 (0 temuan) | ✅ 158 → **0** |
| 3 | `php -l` bersih pada semua berkas diubah/dibuat | ✅ **32/32** |
| 4 | Matriks HTTP §9.2 blueprint (V9–V30) | ✅ §5.1 |
| 5 | Matriks interaksi browser (V31–V40) | ✅ §5.2 |
| 6 | Matriks DB & invariant (V41–V47) | ✅ §5.3 (dengan koreksi §4.2) |
| 7 | Matriks negatif (V48–V53) | ✅ §5.4 |
| 8 | `git diff` tidak memuat `views/admin/**` **akibat plan/103** | ✅ 0 penanda `plan/103` di `views/admin/`; 3 berkas itu milik plan/102 (lihat §4.3) |
| 9 | Tidak ada nilai kamus memuat `Rp`+angka | ✅ gate P3 = 0 |
| 10 | Dokumen summary terbit | ✅ berkas ini |

---

## 4. Penyimpangan, Koreksi & Catatan Metodologi

### 4.1 Penyimpangan dari blueprint (semua disengaja)

| # | Butir | Blueprint | Implementasi | Alasan |
|---|---|---|---|---|
| S1 | Ambang `is-urgent` countdown | Menambah kelas pada ≤300 s | **Dibatalkan** | `wallet/pay.php` tidak punya blok `<style>`; kelas tanpa aturan CSS = dead code. Perilaku habis (CTA disabled + reload sekali + banner server) tetap utuh |
| S2 | `wallet_pay_steps_title` sebagai kartu terpisah | "kartu dihapus, key dipertahankan" | Key dipakai sebagai **judul blok langkah di dalam** kartu QRIS | Lebih ringkas; key tetap hidup (tidak jadi orphan baru) |
| S3 | `i18n_ledger_description` | Tidak ada di blueprint awal (C3 hanya tanggal) | **Ditambahkan** | Deskripsi ledger tampil di `wallet/index.php`; format kanonik deterministik bisa dilokalisasi saat baca tanpa migrasi skema |
| S4 | Perbaikan copy `nomor rekening harus numeric` | Rencana mengubah ke `numerik` | Dilakukan lewat key `bb_err_account_number` | Konsisten dengan §5.3.3 blueprint |
| S5 | `rental_err_insufficient` `USC/IDR` → `IDR` | Direncanakan | Dilakukan | Istilah USC sudah tidak ada di sistem (IDR-only, L6) |
| S6 | `Promoter_model.localize_result()` | Blueprint hanya menyebut `Wallet`/`Rentals` | Ditambahkan | `submit_claim()` adalah endpoint member-facing (`/team`) dengan 11 prosa Indonesia |

### 4.2 Koreksi metodologi verifikasi (penting)

1. **Zona waktu seed.** Seed data uji pertama memakai `date()` PHP yang berjalan **UTC** sementara MySQL di-`SET time_zone='+07:00'`. Akibatnya `expires_at` yang dimaksud "1 jam ke depan" menjadi 6 jam ke belakang → sweep `expire_user_deposits()` langsung menandainya `expired`, dan **blok countdown tidak pernah dirender**. Setelah seed memakai `date_default_timezone_set('Asia/Jakarta')` (parity pin M2), timer + form konfirmasi muncul dan terverifikasi.
2. **Invariant V45.** Pemeriksaan awal `balance == SUM(credit) − SUM(debit)` melaporkan **25 mismatch** — jumlah yang **identik dengan kondisi pra-eksekusi** (terverifikasi: berkas yang berubah tidak memuat jalur tulis uang, `git diff` menunjukkan 0 penambahan `Wallet_model->credit/debit`). Mismatch berasal dari seed historis (user dibuat dengan saldo langsung ke tabel `users` tanpa baris ledger — mis. `qa_leader_101`, `qa_dl_l1_102`). Verifikasi plan/103 karena itu difokuskan pada bukti yang relevan: **user uji plan/103 memiliki 0 baris `wallet_ledger`** sepanjang seluruh eksekusi → plan/103 tidak menulis uang sama sekali.
3. **Serialisasi rate limit saat uji.** Rate limiter (`5 req / 900 s`) membuat beberapa skenario pertama ter-throttle alih-alih menguji pesan yang dituju (terlihat sebagai `NO FLASH`). Prosedur uji diperbaiki: `DELETE FROM rate_limits` sebelum setiap kasus → seluruh pesan validasi terbaca benar (`below_min`, `above_max`, invalid format).
4. **Deteksi leak pada HTML.** Sapuan awal menghitung **komentar** markup sebagai leak (mis. `<!-- 5. Bantuan & FAQ -->`, komentar JS `Salin Nominal`). Matriks diperbaiki dengan membuang `<!-- -->` (dan `<script>`/`<style>` untuk probe teks). Audit statis juga diperketat: komentar inline di-strip, `log_message()` multi-baris di-skip, dan allowlist berubah dari *substring* ke *exact-match* untuk token pendek.
5. **Positive control scanner.** Setelah allowlist diperketat, `in_allowlist()` sempat menjadi terlalu longgar (kata `Admin` cocok dengan allowlist `admin` sehingga frasa `Silakan hubungi admin` lolos). Diperbaiki → **positive control** (menanam `<p>Silakan hubungi admin</p>` ke `wallet/index.php`) menghasilkan **1 temuan**, dan setelah dikembalikan **0**. Scanner sekarang terbukti sensitif, bukan sekadar kosong.

### 4.3 Batas lingkup yang dihormati

| Batas | Bukti |
|---|---|
| **L1 — admin 100% Indonesia** | 0 penanda `plan/103` di `views/admin/**`; diff yang ada di sana seluruhnya milik **plan/102** (4 rujukan `plan/102`). `Admin`/`Admin_auth` tetap tidak memanggil `i18n_apply()`; pesan 429 admin Indonesia (V52) |
| **L6 — uang/angka tidak diterjemahkan** | Gate P3 = 0; semua nominal dibangun di PHP/JS lalu dikirim sebagai argumen `sprintf`/`%s` |
| **L4 — satu idiom per request** | `i18n_apply()` tidak berubah; `_rate_limit_line()` membaca berkas idiom lain **tanpa** memuatnya ke registry CI |
| **Z1 — ledger sebagai satu-satunya otoritas** | 0 baris `wallet_ledger` ditulis selama verifikasi; helper ledger hanya **membaca/merender** |
| **Preseden/eksklusi terverifikasi** | `views/admin/**`, `views/errors/**`, `core/MY_Exceptions.php`, `models/Admin_model.php`, `helpers/captcha_helper.php` — didaftarkan eksplisit sebagai di luar cakupan auditor dengan alasan tertulis |

### 4.4 Temuan plan/103 yang memperluas blueprint

| # | Temuan | Tindakan |
|---|---|---|
| T1 | `Auth.php` punya **2** literal captcha, bukan 1 (blueprint hanya mencatat 1) | Keduanya dikonversi |
| T2 | Pesan bawaan **`form_validation` CI3 berbahasa Inggris** bocor di mode `id`, dan label field berbahasa Indonesia bocor di mode `en` | `_set_fv_messages()` + 9 key `fv_*` |
| T3 | **`Profile.php`** menampilkan `$this->upload->display_errors()` (Inggris) ke member | Pesan generik ber-kamus + detail ke log |
| T4 | **`help/index.php` dan `notification/index.php` sama sekali tanpa key** (0 `lang()`) | 47 + 9 key baru |
| T5 | `Promoter_model::submit_claim()` mengirim 11 prosa Indonesia lewat `api_error()` | `localize_result()` |
| T6 | Nilai kamus ID masih memuat kata Inggris (`Total Value`, `Reward GPU`, `English`, `Indonesian`) | Diterjemahkan; allowlist P5 dipertahankan ketat |
| T7 | 4 nilai kamus memuat nominal literal | Dikonversi ke `%s` |
| T8 | `mbstring` tidak tersedia di CLI environment | Skrip audit memakai `strtolower`/`substr`/`strpos` (`mb_*` tetap dipakai aplikasi seperti sebelumnya) |

---

## 5. Matriks Verifikasi — Hasil

### 5.1 HTTP dwibahasa (9 halaman × 2 idiom)

Metode: `curl` dengan cookie sesi + `GET /lang/switch/{en|id}`; komentar markup dibuang; probe 19–21 kata kunci per idiom.

| Halaman | mode `en` (kata ID harus ABSEN) | mode `id` (kata EN harus ABSEN) |
|---|---|---|
| `/wallet` | NONE | NONE |
| `/home` | NONE | NONE |
| `/profile` | NONE | NONE |
| `/rentals` | NONE | NONE |
| `/notification` | NONE | NONE |
| `/help` | NONE | NONE |
| `/wallet/bind_bank` | NONE | NONE |
| `/wallet/withdraw` | NONE | NONE |

Set stack pertama (sebelum perbaikan probe) melaporkan 5 hit — **semuanya komentar markup**, bukan copy (mis. `<!-- 5. Bantuan & FAQ -->`, `/* --- Pending invoice: Salin Nominal --- */`). Lihat §4.2 butir 4.

### 5.2 `wallet/pay.php` — 5 status × 2 idiom

| Status | `en` leak | `id` leak | Langkah kanonik | Label catatan admin | Timer | `data-confirm` | CTA |
|---|---|---|---|---|---|---|---|
| `pending` | NONE | NONE | ✅ | ✅ | ✅ | ✅ | ✅ |
| `waiting_approval` | NONE | NONE | ✅ | ✅ | – | – | ✅ |
| `rejected` | `Nominal`* | NONE | ✅ | ✅ | – | – | ✅ |
| `expired` | NONE | NONE | ✅ | ✅ | – | – | ✅ |
| legacy (`unique_code NULL`) | NONE | NONE | ✅ | ✅ | – | – | ✅ |

\* `Nominal tidak sesuai mutasi` adalah **`deposits.decline_reason` yang diisi admin** — data bebas, bukan copy sistem; dirender apa adanya di balik label `Reason:`/`Alasan:` (kategori sama dengan `qris_payment_instructions`, §2.5). Sama persis untuk `qris_notes` di ID mode.

**Artefak kanonik terverifikasi render:**
`data-unit-h="hours"` / `"jam"`, `data-unit-m="minutes"` / `"menit"`, `data-expired-label`, `data-confirm="Confirm …"` / `"Konfirmasi …"`, `Merchant Notes` / `Catatan Merchant`, 3 langkah dari kamus di dalam kartu QRIS.

### 5.3 Flashdata dwibahasa (runtime, bukan statis)

| Skenario | mode `en` | mode `id` |
|---|---|---|
| `amount=abc` | `Invalid amount.` | `Nominal tidak valid.` |
| `amount=5000` (< min) | `Minimum deposit is Rp 10.000` | `Minimal deposit adalah Rp 10.000` |
| `amount=60000000` (> max) | `Maximum deposit is Rp 50.000.000` | `Maksimal deposit adalah Rp 50.000.000` |
| topup sukses | `Invoice INV-… dibuat →created. Transfer EXACTLY Rp 128.234` (kode unik disorot) | `dibuat. Transfer TEPAT Rp …` |
| `confirm_payment` | `Confirmation received. Your deposit is awaiting admin verification.` + chip `Waiting approval` | `Konfirmasi diterima…` |
| `process_withdraw` (tanpa rental) | `You need at least 1 active rental product to make a withdrawal.` | `Anda harus memiliki minimal 1 produk sewa aktif…` |
| admin 429 di `/control-panel` | — | `SYSTEM HALTED: Terlalu banyak percobaan gagal…` (**Indonesia di kedua mode**, L1) |

### 5.4 Notifikasi persisten

| Probe | `en` | `id` |
|---|---|---|
| `notif_roi` (halaman) | `Daily ROI Credited` + body EN | `ROI Harian Cair` + body ID |
| `notif_deposit_approved` | `Deposit Approved` | `Deposit Berhasil` |
| `notif_wd_declined` | `Withdrawal Rejected` | `Penarikan Ditolak` |
| `notif_balance_credit` | `Balance Added by Admin` | `Saldo Ditambahkan Admin` |
| **legacy `title_key IS NULL`** | `Pesan Lama` (fallback DB) | `Pesan Lama` (fallback DB) |
| Bell dropdown (`header.php`) | keyed EN | keyed ID |

### 5.5 Statis & DB

| # | Uji | Hasil |
|---|---|---|
| V1–V2 | `php -l` 32/32 berkas | ✅ 0 error |
| V3 | parity 591 ≡ 591, `array_diff` dua arah | ✅ kosong |
| V4 | hardcoded 158 → **0** (72 → 73 berkas dipindai) | ✅ exit 0 |
| V5 | nilai identik di luar allowlist | ✅ 0 |
| V6 | `views/admin/**` tanpa jejak plan/103 | ✅ 0 penanda |
| V7 | nilai kamus memuat `Rp ` + angka | ✅ 0 |
| V8 | key `lang()` yang tidak teresolusi | ✅ 0 |
| V41 | kolom `title_key` + `params` | ✅ 2/2 |
| V42 | migrasi `--apply` | ✅ 26 baris, exit 0 |
| V43 | migrasi `--apply` **re-run** | ✅ no-op |
| V44 | `params` JSON invalid · `title_key` non-kanonik | ✅ 0 · 0 |
| V45 | mutasi uang oleh plan/103 | ✅ 0 baris `wallet_ledger` |
| V46 | invarian reservasi kode unik (plan/102) | ✅ 0 inkonsisten |
| V47 | baris audit baru dari plan/103 | ✅ 0 (murni presentasi) |

---

## 6. Berkas

### Baru
| Berkas | Isi |
|---|---|
| `scripts/audit_i18n_hardcoded.php` | Scanner statis R1–R7 + allowlist; baseline & gate |
| `scripts/audit_i18n_parity.php` | Gate P1/P3/P3b/P5/P6 kamus dwibahasa |
| `scripts/migrate_103_notification_i18n.php` | Migrasi notifikasi (`--dry-run`/`--apply`, idempotent) |
| `plan/.audit103_baseline.txt` | Bukti baseline 158 temuan |

### Diubah (plan/103)
`application/helpers/i18n_helper.php` (+~300 baris: date, notification, ledger) ·
`application/helpers/ratelimit_helper.php` ·
`application/controllers/{Wallet,Rentals,Profile,Auth,Team,User,Notification,Help,Admin_auth}.php` ·
`application/core/MY_Controller.php` ·
`application/models/{Notification_model,Rental_model,User_model,Promoter_model}.php` ·
`application/views/wallet/{pay,index}.php` ·
`application/views/{notification,help,home,profile,rentals,team}/index.php` ·
`application/views/auth/change_password.php` ·
`application/views/templates/header.php` ·
`application/language/{english,indonesian}/app_lang.php` (591 key masing-masing) ·
`database.sql` (DDL `user_notifications` + catatan migrasi live)

> **Catatan repo:** working tree juga memuat perubahan **plan/102** yang belum di-commit (termasuk `views/admin/**`, `database_seed.sql`, `scripts/migrate_102_*`). Semua itu **bukan** hasil plan/103 dan tidak disentuh.

---

## 7. Perintah Verifikasi (copy-paste)

```bash
# Gate 1 — paritas & higienitas kamus
php scripts/audit_i18n_parity.php

# Gate 2 — string hardcoded di surface member (target 0)
php scripts/audit_i18n_hardcoded.php

# Lint berkas kunci
php -l application/helpers/i18n_helper.php
php -l application/controllers/Wallet.php
php -l application/language/english/app_lang.php

# Migrasi notifikasi (idempotent)
php scripts/migrate_103_notification_i18n.php --dry-run
php scripts/migrate_103_notification_i18n.php --apply
php scripts/migrate_103_notification_i18n.php --apply   # → no-op
```

---

## 8. Sisa Pekerjaan (di luar scope plan/103)

1. **`qris_payment_instructions` dua kolom** (`_en`/`_id`) → catatan admin sendiri bilingual. Dicatat sebagai follow-up plan/104 pada blueprint §11; **tidak diperlukan** untuk strict purity karena blok langkah kanonik sudah dijamin dari kamus.
2. **Pembersihan ~27 baris notifikasi legacy** (`title_key IS NULL`) — baris dengan pola di luar 16 template kanonik (mis. copy historis pra-plan/103). Dibiarkan utuh secara sengaja (nol kehilangan data).
3. **Deskripsi `wallet_ledger` non-kanonik** (teks bebas admin, mis. `Admin Manual Adjustment`) dirender apa adanya.
4. **`views/marketplace/index.php`** tidak diaudit penuh (di luar brief); scanner melaporkan 0 temuan, tetapi belum ada audit copy manual.
5. **`views/errors/html/*.php`** dan **`core/MY_Exceptions.php`** masih berbahasa Indonesia — permukaan **developer/API**, bukan UI member (dikecualikan eksplisit dengan alasan tertulis di auditor).
