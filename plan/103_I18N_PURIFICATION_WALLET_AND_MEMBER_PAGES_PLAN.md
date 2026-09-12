# Plan 103 — i18n Purification: Wallet, QRIS Payment & Member Views (Strict Bilingual Purity)

> **Status:** BLUEPRINT — implementation **NOT** started. This task only produces this document (`plan/103_I18N_PURIFICATION_WALLET_AND_MEMBER_PAGES_PLAN.md`); **no** view, controller, model, dictionary, config, route, or schema file is created, modified, or deleted by it.
> **Bahasa dokumen:** English (mengikuti brief). Disiplin & format mengikuti plan/97–102 (fakta terverifikasi → arsitektur → dataset key → timing → langkah → matriks verifikasi → lampiran).
> **Lingkup eksekusi nanti:** `application/controllers/{Wallet,Rentals,Profile,Auth}.php`, `application/models/{Wallet_model,Rental_model,User_model,Notification_model}.php`, `application/views/wallet/{pay,index,withdraw,bank_bind}.php`, `application/views/{home,profile,rentals,notification,help}/index.php`, `application/views/team/index.php` (copy only), `application/views/templates/header.php`, `application/helpers/{i18n_helper,ratelimit_helper}.php`, `application/language/{english,indonesian}/app_lang.php`, **+1 skema & migrasi baru** untuk notifikasi (plan/103 N1 — lihat §6.4).
> **Bukan lingkup:** panel admin (invariant **L1** — admin tetap 100% Indonesian, tidak pernah memanggil `i18n_apply()`), `docs/*`, sistem pembayaran, logika finansial, dan **semua nominal/angka** (invariant **L6**).

---

## 1. Ringkasan & Objective

Plan 102 shipped the manual QRIS flow with a bilingual dictionary (332 → 378 keys). The dictionary itself is **parity-clean** — but the *delivery* of strings is not. Three independent leak channels remain:

| # | Leak channel | Mechanic | Why Plan 102 missed it |
|---|---|---|---|
| **C1** | **Controller/model message strings** | Model returns `['code' => 'pending_exists', 'message' => 'Anda masih memiliki deposit aktif…']`; controller does `set_flashdata('error', $result['message'])`. The **code is already structured** but unused as an i18n key. | Plan 102 introduced the `code` field for control-flow only; nobody mapped `code → lang()`. |
| **C2** | **Hardcoded literals in views** | e.g. `<p …>Pending</p>` (`wallet/index.php:237`), `title="Salin"` (`home/index.php:219`), `'Tersalin!'` JS fallback (`home:273`, `team:569,586`, `profile:332`). | Plan 102 only audited the *new* pay.php + the deposit card. |
| **C3** | **Un-localized date/timer rendering** | `date('d M Y H:i', …)` produces English month abbrevs (`Feb`) in **both** idioms; countdown renders raw `00:00:00` with no spoken unit; `User_model:413` bakes `d M Y` + the phrase "hari lagi" into a message. | CI3 `date_lang.php` is not loaded by `i18n_apply()`, so no locale month/day source exists. |

**Objective:** achieve **100% strict language purity** on all member-facing surfaces touched by the deposit/QRIS/withdrawal flow and its adjacent member pages:

- `en` mode → every user-visible word (labels, badges, helper notes, validation errors, flashdata, status chips, countdown, JS dialogs, toasts, notification list, help center) is **pure English**.
- `id` mode → every one of those is **pure Indonesian**.
- **Zero** mixed-language render in any state (pending / waiting_approval / success / rejected / expired / legacy-invoice / QRIS-unconfigured).
- Dictionary key sets stay **exactly 1:1** (§5.1 verified: 378 ≡ 378, `array_diff` empty both ways).
- **Money is never translated** (L6): `Rp` + `number_format($v, 0, ',', '.')` in both idioms, in every new code path.

### 1.1 Workstreams

| # | Workstream | Now | Target |
|---|---|---|---|
| **W1** | Controller flashdata (Wallet) | 15 literal Indonesian `set_flashdata(...)` + 6 pass-through of model prose | Every message resolved via `lang()`; **0** literal prose in `Wallet.php` |
| **W2** | Model result `message` | 15 `Wallet_model` + 6 `Rental_model` + 1 `User_model` literal phrases | `code` is authoritative; controller maps `code → lang()`; model prose demoted to `log_message()` diagnostics only |
| **W3** | `wallet/pay.php` | Fully keyed **except**: countdown units, un-localized dates, JS fallback prose | 100% keyed + locale-aware dates + accessible/bilingual countdown |
| **W4** | `wallet/index.php` | Fully keyed **except** `Pending` badge (L237), un-localized dates | 100% keyed, dedup keys with pay.php |
| **W5** | `qris_payment_instructions` section | Admin free-text block rendered **alone**; the 3 canonical steps (`Scan / Transfer exact / Confirm`) live in a *separate* card keyed `wallet_pay_step_*` | Canonical steps **always** rendered from `lang()` inside the QRIS card (admin note becomes an *additive* labeled block, never a replacement) → section can never become unilingual |
| **W6** | Secondary member views | `notification/index.php` **0 keys**, `help/index.php` **0 keys**; `home`/`profile`/`team` JS fallbacks; `rentals` dates | Full keying of notification + help; JS fallbacks → `window.SYNAPSE_I18N`; dates → `i18n_date()` |
| **W7** | Date/time localization | `date('d M Y …')` ×8 member sites (+1 model) | `i18n_date()`/`i18n_datetime()` helper + 19 date-lexicon keys; `WIB` kept as a literal zone tag in both idioms |
| **W8** | Persistent notifications | 12 `Notification_model->insert()` sites store **pre-rendered Indonesian** text | Key + params stored (new columns), rendered at read time in the *reader's* idiom; idempotent backfill for existing rows |
| **W9** | Guardrails | No automated i18n check exists | 2 new verification scripts: (a) key-parity + value-collision, (b) hardcoded-string scanner with allowlist |

---

## 2. Fakta Codebase (terverifikasi pra-edit)

Semua angka di bawah **diukur** pada working tree saat plan ini ditulis (`grep`/`php -r` langsung, bukan asumsi).

### 2.1 Dictionary — parity **SUDAH** bersih

```
php -r '…include both app_lang.php…'
EN keys: 378   ID keys: 378
key set diff (EN→ID): []      key set diff (ID→EN): []
```

| Temuan | Detail |
|---|---|
| **Parity 1:1** | ✅ 378 ≡ 378, himpunan key identik. Invariant plan/94 F1 V6 **utuh**. |
| **25 key bernilai identik** di kedua idiom | Sah (nama diri / satuan / label netral): `common_level, common_online, home_hub_fra, home_hub_jkt, home_hub_tyo, home_hud_link, home_online, home_page_title, home_stat_value, lang_english, lang_indonesian, market_page_title, nav_market, rental_system_monitor, team_badge_level, team_l2_name, team_l3_name, team_level_2_6, team_promo_badge, wallet_page_title, wallet_pay_invoice_label, wallet_pay_merchant_label, wallet_topup_btn, wd_an_label, wd_tier_label`. Perlu **allowlist eksplisit** di guardrail W9(b) agar tidak jadi false-positive. |
| **`wallet_amount_prompt` mengandung newline literal** | Nilai EN = `"Enter amount (digits only):\ne.g. 750000"`, ID = `"Masukkan nominal (angka saja):\nContoh: 750000"`. `prompt()` memang butuh `\n`, tapi sumbernya adalah **baris terbelah** di file (`english/app_lang.php:311–312`). Fungsional (lint bersih) namun rapuh → normalisasi ke `"\n"` escape (W3, §7 step 3). |
| **1 key orphan** | `wallet_principal_in` — 0 referensi di seluruh `application/` (kecuali `views/admin/` yang dikecualikan). Kandidat hapus dari **kedua** idiom (plan/103 §7 step 3, "−1 key"). |
| **0 key hilang** | Setiap `lang('…')` & `lang->line('…')` di `application/` (di luar `views/admin/`) punya padanan di kamus. Tidak ada `lang()` yang mengembalikan key mentah. |
| **6 key tampak "unused"** | `team_promo_reason_*` — **bukan** orphan: diakses dinamis via map di `views/team/index.php:264–269`. Scanner W9(b) wajib punya allowlist pola `team_promo_reason_*`. |

### 2.2 Leak channel C1 — string literal di controller

| File | L | String | Kelas |
|---|---|---|---|
| `controllers/Wallet.php` | 76 | `'Nominal tidak valid.'` | flashdata |
| | 86 | `'Pembayaran QRIS belum dikonfigurasi admin. Silakan hubungi admin.'` | flashdata |
| | 94–96 | `'Invoice … dibuat. Transfer TEPAT Rp … (termasuk kode unik …).'` (3 konkatenasi) | flashdata + interpolasi uang (L6) |
| | 102 | `'Gagal membuat invoice. Silakan coba lagi.'` (fallback) | flashdata |
| | 167 | `'Konfirmasi diterima. Deposit Anda sedang menunggu verifikasi admin.'` | flashdata |
| | 193 | `show_error('Akses ditolak: invoice milik pengguna lain.', 403)` | error page |
| | 219 | `'Invoice tidak ditemukan.'` | flashdata |
| | 225 | `show_error('Akses ditolak: invoice milik pengguna lain.', 403)` | error page |
| | 232 | `'Pembayaran berhasil disimulasikan! Dana sudah masuk.'` | flashdata (dev-only) |
| | 234 | `'Gagal memproses simulasi: invoice sudah diproses atau tidak valid.'` | flashdata (dev-only) |
| | 247 | `'Anda masih memiliki penarikan yang sedang diproses.'` | flashdata |
| | 254 | `'Anda harus memiliki minimal 1 produk sewa aktif untuk melakukan penarikan.'` | flashdata |
| | 261 | `'Batas penarikan harian tercapai. Anda sudah melakukan penarikan hari ini.'` | flashdata |
| | 269 | `'Anda belum mengikat rekening bank. Silakan ikat rekening terlebih dahulu.'` | flashdata |
| | 325 | `'Anda masih memiliki penarikan yang sedang diproses.'` (duplikat 247) | flashdata |
| | 331 | `'Anda harus memiliki minimal 1 produk sewa aktif …'` (duplikat 254) | flashdata |
| | 337 | `'Batas penarikan harian tercapai.'` | flashdata |
| | 345 | `'Anda belum mengikat rekening bank.'` | flashdata |
| | 356 | `'Nominal penarikan tidak valid.'` | flashdata |
| | 369 | `'Hari ini bukan hari operasional penarikan.'` | flashdata |
| | 370 | `'Penarikan hanya dapat diajukan pada pukul {open}–{close} WIB.'` | flashdata |
| | 385 | `'Minimal penarikan adalah Rp ' . number_format(…)` | flashdata + uang |
| | 391 | `'Maksimal penarikan adalah Rp ' . number_format(…)` | flashdata + uang |
| | 397 | `'Saldo tidak mencukupi untuk penarikan'` | flashdata |
| | 406 | `'Permintaan penarikan berhasil diajukan'` | flashdata |
| | 436 | `'Penarikan tidak ditemukan.'` | flashdata (dev-only) |
| | 442 | `show_error('Akses ditolak: penarikan milik pengguna lain.', 403)` | error page |
| | 449 | `'Simulasi: Penarikan berhasil disetujui.'` | flashdata (dev-only) |
| | 451 | `'Gagal memproses simulasi: penarikan sudah diproses atau tidak valid.'` | flashdata (dev-only) |
| | 466 | `'Rekening sudah terikat dan tidak dapat diubah.'` | flashdata |
| | 477 | `'Semua field wajib diisi.'` | flashdata |
| | 483 | `'Nomor rekening harus numeric minimal 8 digit.'` | flashdata |
| | 497 | `'Rekening berhasil diikat.'` | flashdata |
| | 499 | `'Gagal menyimpan data rekening.'` | flashdata |
| `controllers/Rentals.php` | 56 | `'Sistem: ID Produk tidak terbaca dari form.'` | flashdata |
| | 63 | `'Sistem: Produk tidak ditemukan di database.'` | flashdata |
| | 73 | `'Sistem: Saldo USC/IDR Anda tidak mencukupi.'` | flashdata |
| | 90 | `'Sewa berhasil diaktifkan! Infrastruktur sedang online.'` | flashdata |
| | 108 | `'Sistem: ID Sewa tidak valid.'` | flashdata |
| | 137–143 | `Notification_model->insert(…, 'ROI Harian Cair', 'ROI sebesar Rp … telah masuk ke saldo (kontrak #…).', 'commission')` | **notifikasi tersimpan** (W8) |
| `controllers/Profile.php` | 30 | `'Nama 1-50 karakter'` | flashdata |
| | 57 | `$this->upload->display_errors('', '')` | **string CI3 Inggris** (`form_validation`/`upload` lang) → lihat §6.6 |
| | 63 | `'Profil diperbarui'` | flashdata |
| | 65 | `'Gagal memperbarui'` | flashdata |
| | 80 | `'Foto dihapus'` | flashdata |
| | 116 | `'Kata sandi berhasil diperbarui.'` | flashdata |
| `controllers/Auth.php` | 188 | `'Pendaftaran berhasil! Silakan login.'` | flashdata |
| | 302 | `'Akun Anda telah dinonaktifkan. Silakan hubungi admin.'` | flashdata |
| | 321 | `'Kata sandi berhasil diperbarui.'` | flashdata |
| `helpers/ratelimit_helper.php` | 28 | `'Terlalu banyak percobaan gagal. Silakan coba lagi dalam N menit.'` | flashdata **dan** API 429 body — satu choke-point, dipakai Auth/Admin_auth/Rentals/Wallet |

### 2.3 Leak channel C1b — prose di model (di-*pass-through* controller)

`Wallet_model::create_deposit()` / `confirm_deposit()` / `create_withdrawal()` mengembalikan `['success','code','message']`. `message` = prosa Indonesia; **`code` sudah tersedia** dan itulah yang dipakai controller untuk kontrol alur (`$form_codes`).

| File:L | `code` | `message` (ID) |
|---|---|---|
| `Wallet_model.php:757` | `invalid_amount` | `Nominal deposit tidak valid.` |
| `:760` | `below_min` | `Minimal deposit adalah Rp {min}` |
| `:763` | `above_max` | `Maksimal deposit adalah Rp {max}` |
| `:773` | `error` | `Gagal membuat invoice. Silakan coba lagi.` |
| `:797` | `pending_exists` | `Anda masih memiliki deposit aktif. Selesaikan atau tunggu kedaluwarsa terlebih dahulu.` |
| `:805` | `code_exhausted` | `Kuota kode unik untuk nominal ini sedang penuh. Coba nominal lain atau hubungi admin.` |
| `:837` | `error` | (idem) |
| `:855` | `error` | (idem) |
| `:860` | `code_conflict` | `Sistem sedang sibuk mengalokasikan kode unik. Silakan coba lagi.` |
| `:924` | `not_found` | `Invoice tidak ditemukan.` |
| `:930` | `expired` | `Invoice sudah kedaluwarsa. Silakan buat deposit baru.` |
| `:933` | `not_pending` | `Deposit ini sudah dikonfirmasi atau tidak lagi menunggu pembayaran.` |
| `:948` | `not_pending` | (idem) |
| `:1167,1241,1251` | `error` | `Gagal memproses penarikan.` |
| `:1179–1182` | `closed_day`/`closed_time` | `Hari ini bukan hari operasional penarikan.` / `Penarikan hanya dapat diajukan pada pukul {open}–{close} WIB.` |
| `:1187` | `below_min` | `Minimal penarikan adalah Rp {min}.` |
| `:1192` | `above_max` | `Maksimal penarikan adalah Rp {max}.` |
| `:1199` | `insufficient` | `Saldo tidak mencukupi untuk penarikan` |
| `:1205` | `pending_exists` | `Anda masih memiliki penarikan yang sedang diproses.` |
| `:1210` | `daily_limit` | `Batas penarikan harian tercapai.` |
| `Rental_model.php:99,137,167,193,203` | `error` | `Sistem: Gagal memotong saldo atau membuat kontrak sewa.` |
| `:116` | `product_unavailable` | `Sistem: Produk tidak ditemukan di database.` |
| `:137` | `error` | `Sistem: Gagal memproses sewa. Coba lagi.` |
| `:145` | `max_per_user` | `Sistem: Batas maksimal sewa paket ini telah tercapai (Maks. {n}).` |
| `:153` | `insufficient` | `Sistem: Saldo USC/IDR Anda tidak mencukupi.` |
| `:381` | `error` | `Sistem: Gagal memproses klaim. Coba lagi.` |
| `User_model.php:407` | `already_claimed` | `Gaji mingguan sudah diklaim untuk minggu ini.` |
| `User_model.php:413–417` | `cycle_not_ready` | `` "Cooldown aktif. Gaji berikutnya: {$days_remaining} hari lagi ({$next_date})." `` — **3 pelanggaran sekaligus**: prosa ID, satuan "hari" hardcoded, `$next_date` via `date('d M Y')` (abbrev bulan Inggris). |

### 2.4 Leak channel C3 — tanggal & timer

| File:L | Ekspresi | Masalah |
|---|---|---|
| `views/wallet/index.php:194` | `date('d M Y H:i', $exp_ts) . ' WIB'` | `M` = English month abbrev di kedua idiom |
| `views/wallet/index.php:282` | `date('d M Y, H:i', …)` | idem |
| `views/wallet/pay.php:37` | `date('d M Y H:i', …) . ' WIB'` | idem |
| `views/wallet/pay.php:91` | `date('d M Y H:i', …) . ' WIB'` | idem |
| `views/rentals/index.php:156` | `date('d M Y', …)` | idem |
| `views/rentals/index.php:178` | `sprintf(lang('rental_expires_on'), date('d M Y', $expired))` | idem (kalimat sudah ter-key, tanggalnya belum) |
| `views/team/index.php:354` | `date('d M Y H:i', …)` | idem |
| `models/User_model.php:413` | `date('d M Y', $next_ts)` + `"hari lagi"` | idem + satuan hardcoded |
| `views/wallet/pay.php:194–197, 293–314` | `#countdown` → `pad(h)+':'+pad(m)+':'+pad(s)` | Angka mentah tanpa satuan; `aria-live="polite"` tapi yang di-announce `00:12:03` (tidak terbaca sebagai "12 menit"). Tidak ada unit noun sama sekali (`detik`/`menit`/`jam`/`expired` tidak pernah dirender) |
| `views/wallet/index.php:193` | `lang('wallet_pay_conf_late')` = `'Konfirmasi terlambat ·'` | Key ada; **trailing `·`** di dalam nilai kamus (anti-pola) |

### 2.5 Leak channel C2 — literal di view (member)

| File | L | Literal | Catatan |
|---|---|---|---|
| `views/wallet/index.php` | 237 | `<p …>Pending</p>` | **Hardcoded English** di tengah mode `id`; duplikat makna `wallet_pay_status_pending` yang sudah dipakai di L188–189 |
| | 401 (komentar) | `Salin Nominal` | Komentar — tetap diselaraskan saat sentuh file |
| `views/home/index.php` | 219 | `title="Salin"` | Atribut aksesibilitas, tidak ter-key |
| | 273 | `\|\| 'Tersalin!'` | JS fallback prosa ID (dipakai bila `SYNAPSE_I18N` tak termuat) |
| | 307 | `\|\| 'Gagal menyalin kode.'` | idem |
| `views/profile/index.php` | 332 | `\|\| 'Tersalin!'` | idem |
| | 338 | `\|\| 'Gagal menyalin'` | idem |
| | 98 / 108 | `EN` / `ID` | **Sah** — kode bahasa, netral (bukan leak) |
| `views/team/index.php` | 569 | `\|\| 'Tersalin!'` | idem |
| | 586 | `\|\| 'Tersalin!'` | idem |
| | 691 | `// ═══ Plan 91 — Klaim Reward Promotor ═══` | Komentar |
| `views/notification/index.php` | 29 | `Belum ada notifikasi` | Literal ID |
| | 30 | `Notifikasi akan muncul di sini` | Literal ID |
| | 39–43 | `Info / Berhasil / Peringatan / Gagal / Bonus` | Peta label tipe — 5 literal ID |
| | 48 | `?? 'Info'` | fallback |
| | 55 / 57 | `'Hari Ini'` / `'Kemarin'` | Label grup tanggal |
| | 60 | `$dt->format('d M Y')` | Abbrev bulan Inggris (C3) |
| `views/help/index.php` | **0 key** | Seluruh halaman (193 baris) | ~11 blok `<li>`/`<p>` prosa ID + `WhatsApp`/`Email` (netral). Satu-satunya view member yang **belum tersentuh plan/94 sama sekali**. |
| `views/templates/header.php` | 363–367 | `SYNAPSE_I18N` = `{js_processing, js_copied, js_copy_failed}` | Kontrak global ada, tapi **tidak** memuat `js_copied_short`/`js_copy_failed_code` → halaman yang butuh jatuh ke fallback literal |

### 2.6 Leak channel C2b — QRIS instructions (target eksplisit brief)

Kondisi **sekarang** (`views/wallet/pay.php`):

| Region | L | Sumber string |
|---|---|---|
| QRIS card | 205–234 | `wallet_pay_scan_title`, `wallet_pay_qris_alt`, `wallet_pay_merchant_label`, `wallet_pay_not_configured`, `wallet_pay_help_note` |
| **Admin note block** | 228–233 | `lang('wallet_pay_instructions_title')` sebagai **judul**, isi = `nl2br(html_escape($qris_notes))` — **free text dari `system_settings.qris_payment_instructions`**, satu bahasa saja, tanpa penanda bahasa |
| **Steps card (terpisah)** | 236–253 | `wallet_pay_step_scan` / `_transfer` / `_confirm` — **sudah** ter-key |

**Diagnosis:** brief menyebut "admin-configured custom notes memaksa seluruh section menjadi unilingual". Secara struktural penyebabnya adalah **pemisahan kartu**: begitu admin mengisi `qris_payment_instructions` (lazimnya Indonesia, karena panel admin 100% ID — L1), member mode `en` melihat **dua blok berbeda bahasa berdampingan**: catatan admin (ID) + kartu "How to pay" (EN). Solusinya bukan menghapus catatan admin, melainkan **menjadikan langkah kanonik sebagai tulang punggung kartu QRIS** dan catatan admin sebagai blok **aditif berlabel** (W5, §6.5).

### 2.7 Notifikasi tersimpan (W8) — inventaris penuh

12 call-site menulis prosa **Indonesia** ke `user_notifications.title/message`, permanen di DB:

| Sumber | Jumlah | Contoh |
|---|---|---|
| `controllers/Admin.php` | 8 (L165, 213, 247, 295, 861, 908, 950, 986) | approve/decline deposit & WD, toggle promoter, expire rentals, dll. |
| `controllers/Rentals.php` | 1 (L139) | `'ROI Harian Cair'` + `'ROI sebesar Rp … (kontrak #…).'` |
| `controllers/Team.php` | 2 (L133, 209) | klaim bonus/gaji |
| `models/Promoter_model.php` | 2 (L495, 607) | klaim reward |
| `models/Rental_model.php` | 3 (L669, 738, 791) | rebate L1/L2/L3, ROI |

`user_notifications` **tidak punya** kolom bahasa/key (`database.sql:255–266`: `title VARCHAR(100)`, `message TEXT`, `type ENUM`, `is_read`, `created_at`). Karena `views/notification/index.php` + dropdown bell (`templates/header.php`) merender teks mentah dari DB, notifikasi Indonesia **muncul apa adanya** di mode `en` → **leak permanen yang tidak bisa diperbaiki di layer view**. Ini satu-satunya bagian plan yang butuh perubahan skema.

### 2.8 Sudah bersih (jangan diutak-atik)

| Surface | Status |
|---|---|
| `views/wallet/withdraw.php` | ✅ Semua label + JS string (`closed_day`, `closed_time`, `amount_invalid_*`, `tier_label`) ter-key; `WD_CONFIG` dari server; `Intl.DateTimeFormat('en-GB')` hanya untuk **aritmetika** WIB (bukan tampilan) |
| `views/wallet/bank_bind.php` | ✅ Semua label ter-key (`bb_*`, `wd_verified`) |
| `views/wallet/pay.php` kartu status | ✅ 5 status ter-key; `$pill` map lengkap |
| `views/wallet/index.php` kartu deposit | ✅ Chip status, kode, breakdown, CTA ter-key |
| `views/home/index.php` hero + map | ✅ Plan 99/101 sudah full-key |
| `views/profile/index.php` App Preferences | ✅ Plan 100 sudah full-key |
| Sepenuhnya di luar scope | `views/admin/**` (L1), `system/**`, `docs/**`, `database.sql` (kecuali migrasi notifikasi §6.4) |

---

## 3. Design Decisions (mengikat untuk eksekusi)

| # | Keputusan | Rasional |
|---|---|---|
| **D1** | **Controller adalah satu-satunya titik resolusi bahasa.** Model tetap mengembalikan `code`, dan `message` diturunkan menjadi diagnostik (`log_message`) — bukan dihapus. | Menjaga invariant "semua akses DB & aturan bisnis di model" tanpa memaksa model `use lang()` (model adalah layer domain, bukan presentasi). Mengubah `message` menjadi key akan mencampur domain dengan i18n. |
| **D2** | **`code` → key map dideklarasikan eksplisit per controller** sebagai `private const` PHP array (pola `User_model::LEVEL1_BONUS`/`WAGE_TITLES`), bukan `lang('deposit_' . $code)` hasil konkatenasi. | Konkatenasi key membuat key tidak grep-able & tidak terverifikasi oleh scanner W9(b). Konstanta eksplisit = inventaris statis. |
| **D3** | **Fallback aman berlapis:** jika `code` tidak punya key → pakai `lang('common_error_generic')`; **tidak pernah** menampilkan `$result['message']` ke UI. | Kode baru di masa depan tidak bisa diam-diam membocorkan prosa Indonesia ke UI. |
| **D4** | **Tanggal dilokalkan oleh helper baru**, bukan `date_lang.php` CI3. | `i18n_apply()` hanya memuat `app_lang.php`; menambah `date_lang.php` menuntut perubahan engine plan/94. Lebih murah & lebih terkontrol: 19 key leksikon di `app_lang.php`. |
| **D5** | **`WIB` tidak diterjemahkan** di kedua idiom. | Zona waktu adalah label teknis (seperti `Rp`), bukan kata. |
| **D6** | **Angka & uang tetap diformat PHP/JS** (`number_format` / `Intl.NumberFormat('id-ID')`), dititipkan ke key sebagai argumen `sprintf`/`%s`. | Invariant **L6** — kamus tidak pernah berisi angka. |
| **D7** | **`en` adalah sumber kebenaran copy.** Setiap key baru ditulis English dulu, lalu Indonesian. | Invariant plan/94 (EN = default idiom untuk visitor baru). |
| **D8** | **Notifikasi persisten dirender saat dibaca**, bukan saat ditulis. | Satu-satunya cara agar notifikasi lama ikut berubah bahasa saat member menekan tombol switch. |
| **D9** | **`qris_payment_instructions` tetap 1 kolom free-text** (tanpa perubahan skema admin); langkah kanonik dirender dari kamus sehingga section tidak pernah unilingual. Pemisahan per-bahasa (2 kolom) dicatat sebagai **follow-up opsional** plan/104, bukan bagian plan ini. | Menghormati L1 (panel admin 100% ID) & meminimalkan blast radius. |
| **D10** | **JS string via `window.SYNAPSE_I18N`** (kontrak plan/94 F1/plan/100) diperluas, bukan diganti. Fallback literal di JS **dihapus** (bukan diterjemahkan) karena setelah §7 tidak ada kondisi di mana objek itu absen (selalu di-inject `header.php`). | Menghapus fallback = menghapus permukaan leak. Sengaja **bukan** fallback English: fallback apa pun akan salah di satu idiom. |

---

## 4. Arsitektur

### 4.1 Alur resolusi string (setelah plan ini)

```
                        ┌───────────────────────────────────────┐
  request member ──────▶│ MY_Controller::__construct()          │
                        │  maintenance → WIB pin → i18n_apply() │  ← tepat 1 idiom dimuat (L4)
                        └───────────────┬───────────────────────┘
                                        │ $site_lang_code ∈ {en,id}
        ┌───────────────────────────────┼────────────────────────────────┐
        ▼                               ▼                                ▼
┌──────────────────┐         ┌────────────────────┐          ┌─────────────────────┐
│ CONTROLLER       │         │ VIEW (PHP)         │          │ VIEW (JS)           │
│ flashdata via    │         │ lang('key')        │          │ window.SYNAPSE_I18N │
│ lang($key)       │         │ i18n_date()/       │          │ + page-level        │
│ code→key const   │         │   i18n_datetime()  │          │   JSON payload      │
└──────────────────┘         └────────────────────┘          └─────────────────────┘
        │                               │                                │
        └───────────┬───────────────────┴────────────────┬───────────────┘
                    ▼                                    ▼
        ┌───────────────────────┐            ┌──────────────────────────┐
        │ language/english/     │            │ MODEL (tanpa i18n)        │
        │   app_lang.php (N key)│            │  return ['code'=>…,       │
        │ language/indonesian/  │            │          'message'=>…]    │
        │   app_lang.php (N key)│            │  message → log diagnostik │
        └───────────────────────┘            └──────────────────────────┘
```

### 4.2 Escaping di dalam atribut HTML (temuan wajib tangani)

`views/wallet/pay.php:257–259` menyuntik kalimat kamus ke atribut `onsubmit`:

```php
'onsubmit' => "return confirm('" . str_replace("'", "\\'", lang('wallet_pay_confirm_dialog')) . "')"
```

`str_replace` hanya meng-escape apostrof **JS**, tidak `"` / `<` / `&`. Key `wallet_pay_confirm_dialog` ID mengandung apostrof? — tidak; tapi **key baru** (§5) akan mengandung tanda baca. Aturan mengikat untuk semua key yang masuk atribut:

1. Nilai kamus **dilarang** mengandung `'`, `"`, `\`, `<`, `>`.
2. Dialog konfirmasi **dipindah ke `data-confirm`** + handler JS terpusat (`data-guard-submit` sudah ada di form) — pola yang sama dipakai `form_open` lain di repo. Ini menghapus seluruh kelas bug injeksi sekaligus membuat string dialog ikut tersedia untuk komponen JS lain.

### 4.3 Arsitektur timer (countdown)

**Kontrak server → klien (sudah ada, dipertahankan):**

```html
<div id="countdown"
     data-expires-ts="<?= (int) $expires_ts ?>"   <!-- epoch detik, otoritatif -->
     data-now-ts="<?= (int) $now_ts ?>"           <!-- epoch saat render -->
     data-label-h="<?= lang('time_unit_hours') ?>"   <!-- "hours" / "jam" -->
     data-label-m="<?= lang('time_unit_minutes') ?>"
     data-label-s="<?= lang('time_unit_seconds') ?>"
     data-expired="<?= lang('wallet_pay_expired_title') ?>"
     aria-live="polite">--:--:--</div>
```

**Aturan render (JS `pay.php`, `refreshCountdown`):**

| Aspek | Perilaku |
|---|---|
| Sumber waktu | `remaining = data-expires-ts − data-now-ts`, di-decrement per tick → **bebas clock-skew** (klien tidak pernah membaca jam lokalnya) |
| Visual | `HH:MM:SS` monospace (tidak berubah — L6: angka tidak diterjemahkan) |
| Satuan | Jam/menit/detik **tidak** disisipkan ke dalam digit; satuan hidup di **(a)** `aria-label` yang di-`setAttribute` setiap menit (bukan setiap detik, agar screen-reader tidak spam), dan **(b)** baris `expires_at` absolut di bawahnya |
| `aria-live` | `polite` dipertahankan; teks yang di-announce = `aria-label` ber-satuan, bukan digit mentah |
| Ambang "segera habis" | `remaining <= 300` → tambah kelas `is-urgent` (warna amber/rose). **Teks tetap sama** (tanpa kata "expired" palsu) |
| Habis | `el.textContent = '00:00:00'`; CTA di-`disabled`; `aria-label` = `data-expired`; banner status server-side muncul setelah **satu** `location.reload()` (mekanisme plan/102 dipertahankan) |
| Fallback tanpa JS | Blok `#countdown` menyertakan `<noscript>`-safe teks absolut (`wallet_pay_expires_at` + tanggal WIB) yang sudah ada di L198 |

**Kontrak angka relatif ("3 hari lagi")** untuk `User_model:413` dan sejenisnya: dipindah ke key `%d`-ber-placeholder (`user_wage_next_in`), satuan dari `time_unit_days` bila perlu dipisah — **tanpa** menyusun kata di JS/PHP.

---

## 5. Skema Kamus

### 5.1 Aturan parity (dipertahankan + diperketat)

| Aturan | Enforcement |
|---|---|
| **P1** | Himpunan key `english/app_lang.php` ≡ `indonesian/app_lang.php` — `array_diff()` dua arah **harus** kosong |
| **P2** | Setiap key baru ditambahkan di **kedua** file dalam commit yang sama (dilarang "EN dulu, ID nanti") |
| **P3** | Kamus **tidak pernah** memuat angka/`Rp` (L6) — hanya kata + placeholder |
| **P4** | Nomor urut baris kedua file **tidak** wajib sama (urutan alfabetis dipertahankan agar diff rapi) |
| **P5** | Nilai identik EN≡ID hanya untuk kategori: (a) nama diri/merek, (b) satuan teknis (`Rp`, `WIB`), (c) kode bahasa (`EN`, `ID`, `L1`…), (d) label netral lintas-bahasa (`Online`, `Invoice`, `Top Up`, `Level`). Selai itu **wajib** berbeda → dicek W9(a) dengan allowlist. |
| **P6** | Nilai kamus dilarang mengandung newline **literal** (gunakan escape `"\n"`), dan dilarang mengandung `'` `"` `\` `<` `>` bila dipakai di atribut HTML |

### 5.2 Key baru — Wallet & QRIS (EN ↔ ID)

| Key | English (`en`) | Indonesian (`id`) |
|---|---|---|
| `wallet_pay_qris_notes_label` | `Merchant Notes` | `Catatan Merchant` |
| `wallet_pay_code_badge` | `Unique code` | `Kode unik` |
| `wallet_copy_aria` | `Copy transfer amount` | `Salin nominal transfer` |
| `wallet_copy_failed` | `Copy failed` | `Gagal menyalin` |
| `wallet_status_pending` | `Pending` | `Menunggu` |
| `wallet_status_waiting` | `Waiting approval` | `Menunggu persetujuan` |
| `wallet_status_success` | `Completed` | `Selesai` |
| `wallet_status_rejected` | `Rejected` | `Ditolak` |
| `wallet_status_expired` | `Expired` | `Kedaluwarsa` |
| `wallet_pay_now` | `Pay Now` | `Bayar Sekarang` |
| `wallet_pay_expires_soon` | `Expiring soon` | `Segera berakhir` |

> `wallet_status_*` adalah **label generik status** (dipakai badge daftar transaksi & chip deposit). Kartu status `wallet_pay.php` tetap memakai varian panjang `wallet_pay_status_*` (pesan kalimat) yang sudah ada — dua registri yang sengaja dipisah (chip pendek vs judul banner).

### 5.3 Key baru — pesan hasil operasi (code → key)

Satu key per `code`, dinormalisasi lintas idiom. Kolom ID adalah teks yang **sudah** hidup di model/controller (dipertahankan verbatim → zero regresi copy ID) — jadi transisi ID bersifat *no-op visual*.

#### 5.3.1 Deposit (QRIS)

| Key | English (`en`) | Indonesian (`id`) | Memetakan `code` |
|---|---|---|---|
| `deposit_err_invalid_amount` | `Invalid deposit amount.` | `Nominal deposit tidak valid.` | `invalid_amount` |
| `deposit_err_below_min` | `Minimum deposit is Rp %s` | `Minimal deposit adalah Rp %s` | `below_min` |
| `deposit_err_above_max` | `Maximum deposit is Rp %s` | `Maksimal deposit adalah Rp %s` | `above_max` |
| `deposit_err_pending_exists` | `You already have an active deposit. Complete it or wait until it expires.` | `Anda masih memiliki deposit aktif. Selesaikan atau tunggu kedaluwarsa terlebih dahulu.` | `pending_exists` |
| `deposit_err_code_exhausted` | `The unique-code quota for this amount is full. Try another amount or contact admin.` | `Kuota kode unik untuk nominal ini sedang penuh. Coba nominal lain atau hubungi admin.` | `code_exhausted` |
| `deposit_err_code_conflict` | `The system is busy allocating a unique code. Please try again.` | `Sistem sedang sibuk mengalokasikan kode unik. Silakan coba lagi.` | `code_conflict` |
| `deposit_err_create_failed` | `Failed to create the invoice. Please try again.` | `Gagal membuat invoice. Silakan coba lagi.` | `error` |
| `deposit_err_not_found` | `Invoice not found.` | `Invoice tidak ditemukan.` | `not_found` |
| `deposit_err_expired` | `This invoice has expired. Please create a new deposit.` | `Invoice sudah kedaluwarsa. Silakan buat deposit baru.` | `expired` |
| `deposit_err_not_pending` | `This deposit has already been confirmed or is no longer awaiting payment.` | `Deposit ini sudah dikonfirmasi atau tidak lagi menunggu pembayaran.` | `not_pending` |
| `deposit_err_qris_unconfigured` | `QRIS payment has not been configured by the admin. Please contact admin.` | `Pembayaran QRIS belum dikonfigurasi admin. Silakan hubungi admin.` | *(controller-only)* |
| `deposit_ok_created` | `Invoice %1$s created. Transfer EXACTLY Rp %2$s (including unique code %3$d).` | `Invoice %1$s dibuat. Transfer TEPAT Rp %2$s (termasuk kode unik %3$d).` | *(controller success)* |
| `deposit_ok_confirmed` | `Confirmation received. Your deposit is awaiting admin verification.` | `Konfirmasi diterima. Deposit Anda sedang menunggu verifikasi admin.` | *(controller success)* |
| `deposit_ok_simulated` | `Payment simulated successfully. Funds have been credited.` | `Pembayaran berhasil disimulasikan! Dana sudah masuk.` | *(dev-only)* |
| `deposit_err_simulate_failed` | `Simulation failed: the invoice is already processed or invalid.` | `Gagal memproses simulasi: invoice sudah diproses atau tidak valid.` | *(dev-only)* |

#### 5.3.2 Penarikan (WD)

| Key | English (`en`) | Indonesian (`id`) | `code` |
|---|---|---|---|
| `wd_err_invalid_amount` | `Invalid withdrawal amount.` | `Nominal penarikan tidak valid.` | *(controller)* |
| `wd_err_pending_exists` | `You still have a withdrawal being processed.` | `Anda masih memiliki penarikan yang sedang diproses.` | `pending_exists` |
| `wd_err_no_active_rental` | `You need at least 1 active rental product to make a withdrawal.` | `Anda harus memiliki minimal 1 produk sewa aktif untuk melakukan penarikan.` | *(controller)* |
| `wd_err_daily_limit` | `Daily withdrawal limit reached.` | `Batas penarikan harian tercapai.` | `daily_limit` |
| `wd_err_daily_limit_done` | `Daily withdrawal limit reached. You already made a withdrawal today.` | `Batas penarikan harian tercapai. Anda sudah melakukan penarikan hari ini.` | *(controller)* |
| `wd_err_no_bank` | `You have not linked a bank account yet.` | `Anda belum mengikat rekening bank.` | *(controller)* |
| `wd_err_no_bank_cta` | `You have not linked a bank account. Please link one first.` | `Anda belum mengikat rekening bank. Silakan ikat rekening terlebih dahulu.` | *(controller)* |
| `wd_err_closed_day` | `Today is not an operational withdrawal day.` | `Hari ini bukan hari operasional penarikan.` | `closed_day` |
| `wd_err_closed_time` | `Withdrawals can only be submitted between %s–%s WIB.` | `Penarikan hanya dapat diajukan pada pukul %s–%s WIB.` | `closed_time` |
| `wd_err_below_min` | `Minimum withdrawal is Rp %s` | `Minimal penarikan adalah Rp %s` | `below_min` |
| `wd_err_above_max` | `Maximum withdrawal is Rp %s` | `Maksimal penarikan adalah Rp %s` | `above_max` |
| `wd_err_insufficient` | `Insufficient balance for withdrawal` | `Saldo tidak mencukupi untuk penarikan` | `insufficient` |
| `wd_err_process_failed` | `Failed to process the withdrawal.` | `Gagal memproses penarikan.` | `error` |
| `wd_err_not_found` | `Withdrawal not found.` | `Penarikan tidak ditemukan.` | *(dev-only)* |
| `wd_ok_submitted` | `Withdrawal request submitted successfully` | `Permintaan penarikan berhasil diajukan` | *(controller success)* |
| `wd_ok_simulated` | `Simulation: withdrawal approved successfully.` | `Simulasi: Penarikan berhasil disetujui.` | *(dev-only)* |
| `wd_err_simulate_failed` | `Simulation failed: the withdrawal is already processed or invalid.` | `Gagal memproses simulasi: penarikan sudah diproses atau tidak valid.` | *(dev-only)* |

> Catatan: `wd_closed_day` / `wd_closed_time` **sudah ada** dan dipakai `withdraw.php`. Key `wd_err_*` di atas khusus untuk **flashdata controller** (registri pesan), dengan token `%s` (bukan `{open}`) agar konsisten `sprintf`. Tidak ada key yang dihapus.

#### 5.3.3 Bank binding

| Key | English (`en`) | Indonesian (`id`) |
|---|---|---|
| `bb_err_already_bound` | `Your bank account is already linked and cannot be changed.` | `Rekening sudah terikat dan tidak dapat diubah.` |
| `bb_err_required_fields` | `All fields are required.` | `Semua field wajib diisi.` |
| `bb_err_account_number` | `Account number must be numeric and at least 8 digits.` | `Nomor rekening harus numerik minimal 8 digit.` |
| `bb_ok_bound` | `Bank account linked successfully.` | `Rekening berhasil diikat.` |
| `bb_err_save_failed` | `Failed to save the bank account.` | `Gagal menyimpan data rekening.` |

> Perbaikan sekaligus: ID `'Nomor rekening harus numeric minimal 8 digit.'` → **`numerik`** (typo kata serapan). Ini satu-satunya perubahan copy ID yang **disengaja**.

#### 5.3.4 Rentals, Auth, Profile, rate limit

| Key | English (`en`) | Indonesian (`id`) |
|---|---|---|
| `rental_err_product_id` | `System: product ID could not be read from the form.` | `Sistem: ID Produk tidak terbaca dari form.` |
| `rental_err_product_missing` | `System: product was not found in the database.` | `Sistem: Produk tidak ditemukan di database.` |
| `rental_err_insufficient` | `System: your IDR balance is insufficient.` | `Sistem: Saldo IDR Anda tidak mencukupi.` |
| `rental_err_rental_id` | `System: invalid rental ID.` | `Sistem: ID Sewa tidak valid.` |
| `rental_ok_activated` | `Rental activated successfully! Infrastructure is online.` | `Sewa berhasil diaktifkan! Infrastruktur sedang online.` |
| `rental_err_max_per_user` | `System: the maximum rental limit for this package has been reached (Max. %d).` | `Sistem: Batas maksimal sewa paket ini telah tercapai (Maks. %d).` |
| `rental_err_checkout_failed` | `System: failed to process the rental. Please try again.` | `Sistem: Gagal memproses sewa. Coba lagi.` |
| `rental_err_claim_failed` | `System: failed to process the claim. Please try again.` | `Sistem: Gagal memproses klaim. Coba lagi.` |
| `auth_ok_registered` | `Registration successful! Please log in.` | `Pendaftaran berhasil! Silakan login.` |
| `auth_err_account_inactive` | `Your account has been deactivated. Please contact admin.` | `Akun Anda telah dinonaktifkan. Silakan hubungi admin.` |
| `auth_ok_password_updated` | `Password updated successfully.` | `Kata sandi berhasil diperbarui.` |
| `profile_err_name_length` | `Name must be 1–50 characters.` | `Nama 1-50 karakter.` |
| `profile_ok_updated` | `Profile updated.` | `Profil diperbarui.` |
| `profile_err_update_failed` | `Failed to update.` | `Gagal memperbarui.` |
| `profile_ok_photo_deleted` | `Photo deleted.` | `Foto dihapus.` |
| `ratelimit_too_many` | `Too many failed attempts. Please try again in %d minutes.` | `Terlalu banyak percobaan gagal. Silakan coba lagi dalam %d menit.` |

> **Catatan `rental_err_insufficient`:** copy ID memakai `USC/IDR` — istilah USC sudah tidak ada di sistem (IDR-only, L6). Copy EN **dan** ID dinormalisasi menjadi `IDR` saja. Perubahan copy ID yang disengaja #2.

#### 5.3.5 Notifikasi (W8) — key template

| Key | English (`en`) | Indonesian (`id`) |
|---|---|---|
| `notif_roi_title` | `Daily ROI Credited` | `ROI Harian Cair` |
| `notif_roi_body` | `ROI of Rp %s has been credited to your balance (contract #%d).` | `ROI sebesar Rp %s telah masuk ke saldo (kontrak #%d).` |
| `notif_deposit_approved_title` | `Deposit Approved` | `Deposit Disetujui` |
| `notif_deposit_approved_body` | `Your deposit of Rp %s has been credited to your balance.` | `Deposit Anda sebesar Rp %s telah dikreditkan ke saldo.` |
| `notif_deposit_declined_title` | `Deposit Rejected` | `Deposit Ditolak` |
| `notif_deposit_declined_body` | `Your deposit of Rp %s was rejected. Reason: %s` | `Deposit Anda sebesar Rp %s ditolak. Alasan: %s` |
| `notif_wd_approved_title` | `Withdrawal Approved` | `Penarikan Disetujui` |
| `notif_wd_approved_body` | `Your withdrawal of Rp %s has been processed.` | `Penarikan Anda sebesar Rp %s telah diproses.` |
| `notif_wd_declined_title` | `Withdrawal Rejected` | `Penarikan Ditolak` |
| `notif_wd_declined_body` | `Your withdrawal of Rp %s was rejected and the funds returned to your balance.` | `Penarikan Anda sebesar Rp %s ditolak dan dana dikembalikan ke saldo.` |
| `notif_rebate_title` | `Rebate Bonus` | `Bonus Rebate` |
| `notif_rebate_body` | `Level %1$d rebate of Rp %2$s from downline activity has been credited.` | `Rebate Level %1$d sebesar Rp %2$s dari aktivitas downline telah masuk.` |
| `notif_promoter_claimed_title` | `Reward Claim Submitted` | `Klaim Reward Diajukan` |
| `notif_promoter_claimed_body` | `Your reward claim for %s is being reviewed by admin.` | `Klaim reward Anda untuk %s sedang ditinjau admin.` |
| `notif_promoter_approved_title` | `Reward Approved` | `Reward Disetujui` |
| `notif_promoter_approved_body` | `Your reward claim for %s has been approved. The contract is now active.` | `Klaim reward Anda untuk %s telah disetujui. Kontrak kini aktif.` |
| `notif_promoter_rejected_title` | `Reward Rejected` | `Reward Ditolak` |
| `notif_promoter_rejected_body` | `Your reward claim for %s was rejected.` | `Klaim reward Anda untuk %s ditolak.` |
| `notif_team_bonus_title` | `Bonus Credited` | `Bonus Cair` |
| `notif_team_bonus_body` | `Bonus of Rp %s has been credited to your balance.` | `Bonus sebesar Rp %s telah masuk ke saldo.` |
| `notif_team_wage_title` | `Weekly Wage Credited` | `Gaji Mingguan Cair` |
| `notif_team_wage_body` | `Weekly wage of Rp %s has been credited to your balance.` | `Gaji mingguan sebesar Rp %s telah masuk ke saldo.` |
| `notif_promoter_toggled_on_title` | `Promoter Status Granted` | `Status Promotor Diberikan` |
| `notif_promoter_toggled_on_body` | `You are now a promoter. Your invite code is public and reward claims are enabled.` | `Anda kini promotor. Kode undangan terbuka permanen dan klaim reward aktif.` |
| `notif_promoter_toggled_off_title` | `Promoter Status Revoked` | `Status Promotor Dicabut` |
| `notif_promoter_toggled_off_body` | `Your promoter status has been revoked. Pending claims are still processed.` | `Status promotor Anda dicabut. Klaim yang masih pending tetap diproses.` |
| `notif_rental_expired_title` | `Rental Contract Closed` | `Kontrak Sewa Ditutup` |
| `notif_rental_expired_body` | `Rental contract #%d has expired and was closed.` | `Kontrak sewa #%d telah kedaluwarsa dan ditutup.` |

> Daftar di atas adalah **target skema**; §7 step 8 menetapkan inventaris final per call-site (12 site) dengan pembacaan aktual `controllers/Admin.php` + `models/*.php` sebelum menulis key, sehingga tidak ada key spekulatif.

### 5.4 Key baru — view sekunder

| Key | English (`en`) | Indonesian (`id`) |
|---|---|---|
| `notif_empty_title` | `No notifications yet` | `Belum ada notifikasi` |
| `notif_empty_body` | `Notifications will appear here` | `Notifikasi akan muncul di sini` |
| `notif_type_info` | `Info` | `Info` |
| `notif_type_success` | `Success` | `Berhasil` |
| `notif_type_warning` | `Warning` | `Peringatan` |
| `notif_type_error` | `Failed` | `Gagal` |
| `notif_type_commission` | `Bonus` | `Bonus` |
| `notif_today` | `Today` | `Hari Ini` |
| `notif_yesterday` | `Yesterday` | `Kemarin` |
| `common_copy_title` (reuse `wallet_copy_aria`) | — | — |
| `common_copier_label` | `Copy` | `Salin` |
| `js_copy_code_failed` | `Failed to copy the code.` | `Gagal menyalin kode.` |

**Help center (`help/index.php`, 0 key → N key).** Sekitar **34 key** baru dengan prefix `help_*`, satu key per paragraf/`<li>` (~18) + per judul kartu (~16): `help_contact_title`, `help_contact_wa`, `help_contact_email`, `help_rent_title`, `help_rent_step1..4`, `help_wd_title`, `help_wd_step1..5`, `help_ref_title`, `help_ref_body`, `help_topup_title`, `help_topup_step1..5`, `help_wd_req_title`, `help_wd_req1..5`. Inventaris final diambil per baris pada step 9 (§7) — **nol key spekulatif**.

### 5.5 Key baru — leksikon tanggal (W7)

| Key | English (`en`) | Indonesian (`id`) |
|---|---|---|
| `dt_mon` … `dt_sun` (7) | `Mon Tue Wed Thu Fri Sat Sun` | `Sen Sel Rab Kam Jum Sab Min` |
| `dt_jan` … `dt_dec` (12) | `Jan Feb Mar … Dec` | `Jan Feb Mar Apr Mei Jun Jul Agu Sep Okt Nov Des` |
| `dt_am` / `dt_pm` (2) | `AM` / `PM` | *(tidak dipakai, 24-jam)* |
| `time_unit_seconds` | `seconds` | `detik` |
| `time_unit_minutes` | `minutes` | `menit` |
| `time_unit_hours` | `hours` | `jam` |
| `time_unit_days` | `days` | `hari` |
| `user_wage_next_in` | `%d days remaining (next: %s)` | `%d hari lagi (%s)` |

Total: **7 + 12 + 2 + 4 + 1 = 26 key** leksikon/tanggal.

### 5.6 Rekapitulasi delta kamus

| Blok | Key baru |
|---|---|
| Wallet & QRIS (§5.2) | 11 |
| Deposit codes (§5.3.1) | 15 |
| WD codes (§5.3.2) | 17 |
| Bank binding (§5.3.3) | 5 |
| Rentals/Auth/Profile/ratelimit (§5.3.4) | 16 |
| Notifikasi (§5.3.5) | ~28 |
| View sekunder (§5.4) | ~12 + ~34 (help) |
| Tanggal & satuan (§5.5) | 26 |
| **Key dihapus** | `-1` (`wallet_principal_in`) |
| **Total (perkiraan, dikunci pada step 9)** | **378 → ~530** |

> Semua key baru **wajib** ditambahkan simetris (P2) dan diverifikasi W9(a).

---

## 6. Desain Detail per Area

### 6.1 `Wallet.php` — peta `code → key`

Pola yang diterapkan (contoh untuk deposit):

```php
private const DEPOSIT_ERR_KEYS = [
    'invalid_amount'  => 'deposit_err_invalid_amount',
    'below_min'       => 'deposit_err_below_min',
    'above_max'       => 'deposit_err_above_max',
    'pending_exists'  => 'deposit_err_pending_exists',
    'code_exhausted'  => 'deposit_err_code_exhausted',
    'code_conflict'   => 'deposit_err_code_conflict',
    'not_found'       => 'deposit_err_not_found',
    'expired'         => 'deposit_err_expired',
    'not_pending'     => 'deposit_err_not_pending',
    'error'           => 'deposit_err_create_failed',
];

private function _deposit_error_message(array $result)
{
    $key = self::DEPOSIT_ERR_KEYS[$result['code'] ?? 'error'] ?? 'deposit_err_create_failed';

    if ($key === 'deposit_err_below_min' || $key === 'deposit_err_above_max') {
        $policy = $this->Wallet_model->get_deposit_policy();
        $amount = ($key === 'deposit_err_below_min') ? $policy['min_amount'] : $policy['max_amount'];
        return sprintf(lang($key), number_format((int) $amount, 0, ',', '.'));   // L6: uang via sprintf
    }

    return lang($key);
}
```

Aturan mengikat:

1. **Tidak ada** `set_flashdata('error'|'success', '<literal>')` di `Wallet.php` setelah plan ini.
2. `$result['message']` **tidak pernah** masuk flashdata → diganti `log_message('error', 'wallet: ' . $result['code'] . ' — ' . $result['message'])` (diagnostik tetap utuh).
3. `show_error('Akses ditolak: …', 403)` → ganti dengan `show_error(lang('common_err_forbidden_owner'), 403)` (key baru `common_err_forbidden_owner`: EN `Access denied: this record belongs to another user.` / ID `Akses ditolak: data ini milik pengguna lain.`).
4. Pesan sukses beruang: `sprintf(lang('deposit_ok_created'), $invoice, number_format($total,0,',','.'), $code)` — angka **lewat argumen** (P3).
5. Duplikasi 247↔325, 254↔331, 261↔337, 269↔345 dikonsolidasi menjadi **satu** key masing-masing (varian "hari ini" dipertahankan hanya untuk jalur GET, varian pendek untuk POST — atau disatukan; keputusan step 4 §7, pilih **disatukan** agar konsisten: satu key, satu teks).

### 6.2 `Rentals.php` + `Profile.php` + `Auth.php`

Sama polanya: `private const *_ERR_KEYS` + `lang()`. Tambahan khusus:

- **`Profile.php:57`** — `$this->upload->display_errors('', '')` mengembalikan string dari `system/language/english/upload_lang.php` (**Inggris**) → muncul di mode `id` sebagai leak. Solusi: `lang('profile_err_upload_failed')` (EN `Failed to upload the photo.` / ID `Gagal mengunggah foto.`) + `log_message('error', $this->upload->display_errors('', ''))`. **Tidak** membuat `upload_lang.php` Indonesia (blast radius terlalu besar; pesan generik lebih baik UX-nya).
- **`Auth.php:188/302/321`** — 3 flashdata; `Auth` sudah memanggil `i18n_apply()` di constructor (plan/94) sehingga `lang()` tersedia pra-login. Verifikasi urutan statement saat eksekusi.

### 6.3 `ratelimit_helper.php` — satu choke-point, dua konsumen

`rate_limit_message()` dipanggil di **flashdata web** (Wallet, Rentals, Auth, Admin_auth) **dan** disematkan ke body JSON 429 (`rate_limit_json_response` → `api_error`).

```php
function rate_limit_message($remaining_seconds) {
    $minutes = max(1, (int) ceil($remaining_seconds / 60));
    return sprintf(lang('ratelimit_too_many'), $minutes);
}
```

⚠️ **Invariant L1:** `rate_limit_message()` dipakai juga oleh `Admin_auth` (admin = 100% Indonesian, tidak memanggil `i18n_apply()`). Karena `i18n_apply()` **tidak** dijalankan di sana, idiom aktif = `$config['language'] = 'english'` → admin akan melihat pesan **Inggris**. **Itu regresi L1.**

**Solusi wajib (dipilih):** helper menerima idiom eksplisit, dengan default tetap aman:

```php
function rate_limit_message($remaining_seconds, $idiom = NULL) {
    $minutes = max(1, (int) ceil($remaining_seconds / 60));
    $key     = 'ratelimit_too_many';
    $line    = ($idiom !== NULL)
        ? _rate_limit_line($key, $idiom)   // load app_lang pada idiom yang diminta
        : lang($key);                      // idiom request (member)
    return sprintf($line, $minutes);
}
```

- **Call-site member** (`Wallet`, `Rentals`, `Auth`): `rate_limit_message($s)` → idiom request (`en`/`id`).
- **Call-site admin** (`Admin_auth`, `Admin`): `rate_limit_message($s, 'id')` → **selalu Indonesia** (L1).
- Dari 5 file pemanggil, 1 (`Admin_auth`) diubah; `Admin.php` tidak memanggil helper ini (verifikasi saat eksekusi).

### 6.4 W8 — notifikasi persisten

**Skema (migrasi baru `scripts/migrate_103_notification_i18n.php`, idempotent, `--dry-run`/`--apply`, pola persis `migrate_102_qris_deposits.php`):**

```sql
ALTER TABLE `user_notifications`
  ADD COLUMN `title_key`  VARCHAR(64)  NULL AFTER `message`,
  ADD COLUMN `params`     JSON         NULL AFTER `title_key`;
```

- `database.sql` disinkronkan (`CREATE TABLE user_notifications` + catatan migrasi live one-time di bagian bawah file, pola plan/94 F2 index komposit).
- **Backfill** (`--apply`): untuk setiap baris lama, cocokkan (`title`,`message`) terhadap daftar pola `LIKE`/regex yang **sudah ada** di 12 call-site → isi `title_key` + `params` (JSON). Baris yang tidak cocok → `title_key = NULL` (**tetap dirender apa adanya** → nol kehilangan data, nol mutasi teks lama).
- **Kolom `title`/`message` TIDAK dihapus** (retensi + fallback + audit). Jalur tulis baru tetap mengisi keduanya (teks ID saat ini) **dan** `title_key`/`params`.

**`Notification_model`:**

```php
public function insert_keyed($user_id, $title_key, $params = [], $type = 'info') {
    return $this->db->insert($this->table, [
        'user_id'   => $user_id,
        'title_key' => $title_key,
        'params'    => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : NULL,
        'title'     => lang($title_key),                       // teks ID/EN saat tulis (retensi)
        'message'   => _notif_render_body($title_key, $params),// idem
        'type'      => $type,
    ]);
}
```

**Renderer (helper `i18n_helper.php`, pola `function_exists()`):**

```php
if ( ! function_exists('i18n_notification_text'))
{
    /** @return array{title:string,message:string} dalam idiom request aktif. */
    function i18n_notification_text(array $row)
    {
        if (empty($row['title_key'])) {
            return ['title' => $row['title'], 'message' => $row['message']];  // legacy — apa adanya
        }
        $params = json_decode((string) ($row['params'] ?? ''), TRUE) ?: [];
        return [
            'title'   => lang($row['title_key'] . '_title'),
            'message' => vsprintf(lang($row['title_key'] . '_body'), $params),
        ];
    }
}
```

Dipakai di **2 tempat**: `views/notification/index.php` (§6.5) dan dropdown bell `views/templates/header.php` (`$global_notifications` di-inject `MY_Controller`).

**Catatan penting:** `lang()` untuk baris legacy mengembalikan `NULL` bila idiom belum dimuat — aman karena `i18n_apply()` selalu jalan di `MY_Controller`. Untuk konteks non-member (tidak ada), renderer jatuh ke kolom DB.

### 6.5 `wallet/pay.php` + QRIS section (W5)

**Struktur kartu QRIS setelah plan ini:**

```
┌─ Card: wallet_pay_scan_title ─────────────────────────────┐
│  [ QR image ]  (atau blok wallet_pay_not_configured)      │
│  wallet_pay_merchant_label → nama merchant                │
│  ─────────────────────────────────────────────────────    │
│  wallet_pay_instructions_title      ← judul kanonik (key) │
│  1. wallet_pay_step_scan            ← SELALU dari kamus   │
│  2. wallet_pay_step_transfer        ← SELALU dari kamus   │
│  3. wallet_pay_step_confirm         ← SELALU dari kamus   │
│  ─────────────────────────────────────────────────────    │
│  [kondisional] wallet_pay_qris_notes_label  ← key BARU    │
│  $qris_notes (free-text admin, apa adanya, nl2br)         │
└───────────────────────────────────────────────────────────┘
```

Keputusan:
1. <ol> 3 langkah **dipindah ke dalam** kartu QRIS (dari kartu terpisah `wallet_pay_steps_title`), memakai key yang **sudah ada** → struktur kanonik selalu hadir, dalam bahasa aktif, **tanpa** bergantung pada isi `qris_payment_instructions`.
2. Kartu terpisah "Cara bayar" (`wallet_pay_steps_title`, L236–253) **dihapus**; key `wallet_pay_steps_title` dipertahankan sebagai judul blok langkah (tidak dihapus → tidak ada key orphan baru).
3. Catatan admin dirender **setelah** langkah kanonik, dengan **label key baru** `wallet_pay_qris_notes_label` (EN `Merchant Notes` / ID `Catatan Merchant`). Karena berlabel, pembaca tahu blok itu adalah teks bebas merchant — konvensi yang sama dipakai label `decline_reason` (`wallet_pay_reason_label`) dan `admin_notes` (`team_promo_reason_text`).
4. `nl2br(html_escape($qris_notes))` dipertahankan (escape + line break), **tanpa** menerjemahkan isinya.
5. Jika `$qris_notes` kosong → blok tidak dirender (tidak ada label menggantung).
6. **Follow-up opsional (bukan plan ini):** dua kolom `qris_payment_instructions_en` / `_id` di `system_settings` + pemilih bahasa di form admin → dicatat di §11.

**Sisa perbaikan `pay.php`:**
- L37 & L91 → `i18n_datetime($ts)` + `' WIB'` (D5).
- L194–198 + L280–315 → timer per §4.3 (`data-label-*`, `aria-label` ber-satuan, `is-urgent`).
- L257–259 → dialog konfirmasi pindah ke `data-confirm` (§4.2).
- L272–277 → `PAY_L` diperluas: `{expired_title, expired_body, copied, copy_failed, unit_h, unit_m, unit_s}`; **tanpa fallback literal**.
- L144 penekanan kode unik: tetap (angka, L6).

### 6.6 `wallet/index.php` (W4)

- **L237** `Pending` → `lang($is_waiting ? 'wallet_status_waiting' : 'wallet_status_pending')`. **Ini leak paling terlihat** (badge English di mode ID). Konsistenkan juga L188–189 agar memakai key registri chip yang sama.
- L194 & L282 → `i18n_datetime()`.
- L193 → hapus `·` dari nilai kamus `wallet_pay_conf_late`; pindahkan pemisah ke markup (`<span>…</span> ·`). P5/P6.
- L263 `sprintf(lang('wallet_count_tx'), count($ledger))` — sudah benar (angka via argumen).
- Dedup: `wallet_pay_principal_label`, `wallet_pay_code_label`, `wallet_service_fee`, `wallet_copy_amount*` dipakai di **dua** view → **tetap** di kamus bersama (jangan fork key per-halaman).
- L207–215 & L241–249: blok dev-only (`ENVIRONMENT !== 'production'`) tetap; pesannya kini dari key (`deposit_ok_simulated`, `wallet_simulate_pay`, dst.).

### 6.7 View sekunder

| View | Aksi |
|---|---|
| `notification/index.php` | `$type_labels` → `lang('notif_type_*')`; `$date_label` → `lang('notif_today'/'notif_yesterday')` / `i18n_date($dt)`; empty state → `notif_empty_title/body`; baris notifikasi → `i18n_notification_text($n)` (§6.4) |
| `help/index.php` | Ekstraksi penuh ~34 key `help_*` (§5.4). Struktur HTML/tailwind tidak berubah |
| `home/index.php` | L219 `title="Salin"` → `lang('common_copier_label')`; L273/307 hapus `\|\| '…'` fallback → `(window.SYNAPSE_I18N||{})['js_copied']` (nilai dijamin ada karena `header.php` selalu inject) |
| `profile/index.php` | L332/338 idem; L98/108 `EN`/`ID` **dipertahankan** (kode bahasa, sah) |
| `team/index.php` | L569/586 idem; L354 → `i18n_datetime()`; L691 komentar diselaraskan |
| `rentals/index.php` | L156 & L178 → `i18n_date()`; `'Node #'` pada L152 dipertahankan (label teknis + ID numerik, netral) |
| `templates/header.php` | `SYNAPSE_I18N` diperluas: `+js_copied_short, +js_copy_failed, +js_copy_code_failed, +js_processing` (sudah ada); dropdown bell memakai `i18n_notification_text()` |

---

## 7. Urutan Implementasi (step-by-step)

Setiap step = 1 commit di branch fase, `php -l` pada setiap file tersentuh (**roadmap rule**), dan diakhiri smoke test HTTP.

| # | Step | File | Deliverable | Gate |
|---|---|---|---|---|
| **0** | **Baseline audit** | — | Jalankan `scripts/audit_i18n_hardcoded.php` (§8.2) → simpan output sebagai baseline (harapan: ~236 temuan) | Output terarsip |
| **1** | **Helper tanggal** | `helpers/i18n_helper.php` | `i18n_date($ts,$fmt)`, `i18n_datetime($ts)`, `i18n_day_lexicon()`, `_i18n_date_subst()` — semua `function_exists()`-wrapped, docblock Indonesia, TZ dari `date_default_timezone_get()` (sudah WIB via M2) | `php -l` + unit smoke `php -r` untuk 2 idiom |
| **2** | **Key tanggal & satuan** | kedua `app_lang.php` | 26 key §5.5 di **kedua** idiom | W9(a) hijau |
| **3** | **Normalisasi kamus** | kedua `app_lang.php` | (a) `wallet_amount_prompt` → escape `"\n"` satu baris; (b) hapus `wallet_principal_in` (2 file); (c) hapus `·` dari `wallet_pay_conf_late` + 2 key status baru | W9(a) hijau (378 → 377 + 26 = 403) |
| **4** | **Bank binding + WD messages** | `Wallet.php` (bind_bank, withdraw, process_withdraw, simulate_wd_approve) + key §5.3.2/§5.3.3 | 0 literal di 4 method | W9(b) turun; smoke ID+EN |
| **5** | **Deposit/QRIS messages** | `Wallet.php` (topup, pay, confirm_payment, simulate_payment, `_owned_deposit`) + key §5.3.1 | 0 literal; `code→key` const lengkap | W9(b); smoke 5 status |
| **6** | **Rentals/Auth/Profile messages** | `Rentals.php`, `Auth.php`, `Profile.php` (+ `upload->display_errors` → `log_message`) + key §5.3.4 | 0 literal flashdata | W9(b); smoke register/login/checkout/claim/profile |
| **7** | **Rate-limit idiom param** | `helpers/ratelimit_helper.php` + call-site `Admin_auth.php`, `Admin.php` | Admin 429 tetap **Indonesia** (L1) | Smoke: 6× login gagal di `/control-panel` → pesan ID; 6× di `/login` mode EN → pesan EN, mode ID → ID |
| **8** | **Notifikasi persisten** | `database.sql`, `scripts/migrate_103_notification_i18n.php` (baru), `Notification_model.php`, 12 call-site, `views/notification/index.php`, `views/templates/header.php`, key §5.3.5 | Migrasi `--dry-run` → `--apply` → **re-run = no-op**; backfill terverifikasi (`SELECT COUNT(*) WHERE title_key IS NULL` = baris tak dikenal saja) | Invariant DB 0; render EN+ID |
| **9** | **View sekunder** | `notification/index.php`, `help/index.php`, `home/index.php`, `profile/index.php`, `team/index.php`, `rentals/index.php`, `templates/header.php` + key §5.4 | 0 literal; ~46 key baru | W9(b) hijau |
| **10** | **wallet/pay.php** | `views/wallet/pay.php` | Timer ber-satuan + a11y, `i18n_datetime`, `data-confirm`, langkah kanonik di kartu QRIS + label catatan admin, `PAY_L` tanpa fallback | Smoke 5 status × 2 idiom |
| **11** | **wallet/index.php** | `views/wallet/index.php` | Badge status ter-key, tanggal ter-lokalisasi, dedup chip | Smoke 2 idiom |
| **12** | **Guardrail scripts** | `scripts/audit_i18n_parity.php`, `scripts/audit_i18n_hardcoded.php` (baru, §8) | 2 skrip siap CI/CLI | Keduanya exit 0 |
| **13** | **Verifikasi end-to-end** | — | Matriks §9 dijalankan penuh | Semua baris PASS |
| **14** | **Summary doc** | `plan/103_..._SUMMARY.md` | Ringkasan bukti (format plan/102 §1) | — |

**Urutan ini disengaja:** step 1–3 menyiapkan fondasi (helper + kamus) sebelum menyentuh perilaku; step 4–7 menguras controller/helper (leak C1, dampak terbesar & paling mudah diverifikasi); step 8 skema (paling berisiko → dikerjakan setelah pola `code→key` sudah terbukti); step 9–11 view (leak C2/C3); step 12–13 penutup.

---

## 8. Guardrails (otomatisasi anti-regresi)

### 8.1 `scripts/audit_i18n_parity.php` (baru)

1. `include` kedua `app_lang.php` (dengan `define('BASEPATH', …)`).
2. **Gate P1:** `array_diff(array_keys($en), array_keys($id))` **dan** sebaliknya → harus `[]`.
3. **Gate P5:** daftar key bernilai identik → bandingkan terhadap **allowlist** (25 key §2.1 + key baru yang sah) → key di luar allowlist = **FAIL**.
4. **Gate P6:** nilai mengandung `\n` literal / `'` `"` `\` `<` `>` → **FAIL** (kecuali key di allowlist-atribut).
5. **Gate lint:** `php -l` kedua file.
6. Exit code `0`/`1` + ringkasan tabel.

### 8.2 `scripts/audit_i18n_hardcoded.php` (baru)

Scanner statis pada `application/views/**` (kecuali `views/admin/**`) + `application/controllers/**` (kecuali `Admin*.php`) + `application/helpers/**`:

| Rule | Deteksi | Allowlist |
|---|---|---|
| **R1** | `set_flashdata('error'\|'success', '<literal>')` — argumen bukan `lang(`/`$`/`sprintf(lang(` | — |
| **R2** | Teks node HTML `>…<` mengandung ≥1 kata dari **lexicon ID** (`Salin, Tersalin, Anda, Silakan, Nominal, Kedaluwarsa, Penarikan, Rekening, Sewa, Biaya, Undangan, Klaim, Peringatan, Berhasil, Gagal, Belum, Hari, Menit, Detik, Jam, …`) | komentar (`//`, `<!--`), atribut |
| **R3** | `date('d M Y…'` di luar `helpers/i18n_helper.php` | — |
| **R4** | `\|\| '<literal>'` di dalam blok `<script>` | `class=`, `id=`, selector |
| **R5** | `lang('<key>')` / `lang->line('<key>')` yang **tidak** ada di kamus | `team_promo_reason_*` (dinamis), key dibangun via variabel |
| **R6** | `Notification_model->insert(` **tanpa** `_key`/keyed varian | — |

Output: tabel `file:line | rule | snippet`, exit `1` bila ada temuan. **Target akhir: 0 temuan.**

### 8.3 Guardrail manual (wajib dibaca reviewer)

1. `Rp` dan angka **tidak** pernah masuk nilai kamus (P3) — grep nilai kamus untuk `Rp |[0-9]`.
2. `views/admin/**` **tidak tersentuh** (L1) — `git diff --stat` tidak boleh memuat path itu.
3. `i18n_apply()` tidak pernah dipanggil dari `Admin`/`Admin_auth` (L1).
4. Urutan statement `MY_Controller` tetap: maintenance → WIB → `i18n_apply` → guard login → sweep rental → sweep deposit.

---

## 9. Matriks Verifikasi

### 9.1 Parity & statis

| # | Uji | Metode | Ekspektasi |
|---|---|---|---|
| V1 | Lint kamus | `php -l application/language/{english,indonesian}/app_lang.php` | `No syntax errors` |
| V2 | Lint semua file tersentuh | `php -l <file>` per file | 0 error |
| V3 | **Parity 1:1** | `php scripts/audit_i18n_parity.php` | exit 0; `diff` dua arah kosong |
| V4 | **Hardcoded = 0** | `php scripts/audit_i18n_hardcoded.php` | exit 0; **0 temuan** (baseline step 0 ≈ 236) |
| V5 | Nilai kolisi | gate P5 | 0 di luar allowlist |
| V6 | Admin tak tersentuh (L1) | `git diff --stat \| grep views/admin` | kosong |
| V7 | Uang tak diterjemahkan (L6) | grep nilai kamus `Rp ` / `[0-9]{3}` | 0 hit |
| V8 | Orphan key | irisan key kamus vs `lang()` statis | hanya allowlist dinamis |

### 9.2 Runtime — halaman (HTTP 200 + isi idiom)

Mode uji: `curl -b "site_lang=<idiom>"` (session + cookie 30 hari, plan/94).

| # | Halaman | Kondisi | `en` — harus ABSEN | `id` — harus ABSEN |
|---|---|---|---|---|
| V9 | `/wallet` | saldo 0, tanpa sewa | kata ID: `Menunggu`, `Saldo`, `Penarikan`, `Rekening` | kata EN: `Pending`, `Waiting`, `Wallet`, `Withdraw`, `Top Up` (kecuali brand) |
| V10 | `/wallet` | deposit pending | idem | idem |
| V11 | `/wallet` | deposit waiting_approval | idem | idem |
| V12 | `/wallet` | WD pending | idem | idem |
| V13 | `/wallet/pay/{inv}` | `pending` | `Kedaluwarsa`, `Bayar`, `Kode unik` | `Expired`, `Pay Now`, `Unique code` |
| V14 | `/wallet/pay/{inv}` | `waiting_approval` | `Menunggu verifikasi` | `Waiting approval` |
| V15 | `/wallet/pay/{inv}` | `success` | `Berhasil` | `Completed`, `credited` |
| V16 | `/wallet/pay/{inv}` | `rejected` + reason | `Ditolak`, `Alasan` | `Rejected`, `Reason` |
| V17 | `/wallet/pay/{inv}` | `expired` | `Kedaluwarsa` | `Expired` |
| V18 | `/wallet/pay/{inv}` | **legacy** (`unique_code IS NULL`) | `invoice lama` | `legacy invoice` |
| V19 | `/wallet/pay/{inv}` | QRIS **kosong** | `belum dikonfigurasi` | `not been configured` |
| V20 | `/wallet/pay/{inv}` | `qris_notes` **terisi ID** | blok berlabel `Merchant Notes` **hadir** + 3 langkah EN **hadir** | blok `Catatan Merchant` + 3 langkah ID |
| V21 | `/wallet/withdraw` | di luar jam | `Hari ini bukan` | `not an operational` |
| V22 | `/wallet/withdraw` | fee preview diketik | `Biaya`, `Diterima` | `Fee`, `Received` |
| V23 | `/wallet/bind_bank` | belum terikat | `Rekening` | `Bank account` |
| V24 | `/wallet/bind_bank` | sudah terikat | `Rekening sudah` | `already linked` |
| V25 | `/home` | promoter ON + locked OFF | `Salin` (title) | `Copy` |
| V26 | `/rentals` | ada kontrak | `Sewa`, `Hari` | `Rental`, `Days` |
| V27 | `/profile` | App Preferences | `Bahasa` | `Language` |
| V28 | `/notifications` | kosong / 5 tipe / hari ini+kemarin | `Belum`, `Hari Ini`, `Kemarin` | `No notifications`, `Today`, `Yesterday` |
| V29 | `/help` | seluruh kartu | prosa ID apa pun | prosa EN apa pun |
| V30 | Semua di atas | **tanggal** | tidak ada `Jan..Dec`/`Mon..Sun` Inggris **di mode `id`**; mode `en` tetap Inggris | tidak ada `Jan/Mei/Agu/Okt/Des` atau `Sen/Sel/Rab` |

### 9.3 Runtime — interaksi (browser)

| # | Uji | Ekspektasi |
|---|---|---|
| V31 | Klik "Copy Amount" (`/wallet` & `/wallet/pay`) | Toast/label = `Copied`/`Tersalin` **sesuai idiom**; tidak ada fallback literal |
| V32 | Batal copy (clipboard ditolak) | Pesan gagal sesuai idiom (`Copy failed` / `Gagal menyalin`) |
| V33 | Countdown berjalan | Digit berjalan; `aria-label` = `12 minutes 3 seconds` / `12 menit 3 detik`; berubah tiap **menit**, bukan tiap detik |
| V34 | Countdown ≤ 5 menit | Kelas `is-urgent` aktif; **teks tidak berubah** (no fake "expired") |
| V35 | Countdown habis | `00:00:00` + CTA disabled + `reload()` **sekali** → banner `wallet_pay_expired_*` sesuai idiom |
| V36 | Dialog konfirmasi transfer | `confirm()` teks sesuai idiom; apostrof/kutip di kamus tidak merusak HTML (V36b: injeksi `'` ke nilai kamus sementara → tetap render aman) |
| V37 | Switch `en → id` di `/wallet/pay` | Reload → **seluruh** halaman berubah idiom, **termasuk notifikasi lama** di dropdown bell & `/notifications` |
| V38 | Switch `id → en` | idem |
| V39 | Nominal `Rp 150.234` (split kode) | Angka **identik** di kedua idiom; kode unik tetap amber |
| V40 | Badge status WD | `Pending`/`Menunggu` — **bukan** `Pending` di mode ID |

### 9.4 DB & invariant

| # | Uji | Ekspektasi |
|---|---|---|
| V41 | `migrate_103 --dry-run` | Rencana DDL + backfill tercetak, tanpa tulis |
| V42 | `migrate_103 --apply` | exit 0; 2 kolom ada; backfill terisi |
| V43 | **re-run `--apply`** | no-op (DDL dilewati, 0 baris berubah) — parity pola plan/102 |
| V44 | `SELECT COUNT(*) FROM user_notifications WHERE title_key IS NULL` | hanya baris tak dikenal (terdokumentasi), 0 untuk baris yang dipetakan |
| V45 | Saldo & ledger | `SUM(credit)−SUM(debit)` **tidak berubah** sebelum/sesudah plan (0 mutasi uang) |
| V46 | `reserved_code_key` invariant | 0 inkonsisten (sweep plan/102 tidak tersentuh) |
| V47 | Audit log | tidak ada baris audit baru dari plan ini (murni presentasi) |

### 9.5 Negatif (harus tetap berfungsi)

| # | Uji | Ekspektasi |
|---|---|---|
| V48 | `ENVIRONMENT=production` | `simulate_payment`/`simulate_wd_approve` → 404 (tidak berubah) |
| V49 | Akses invoice milik user lain | HTTP 403 + pesan idiom aktif (bukan prosa ID saat mode EN) |
| V50 | `POST /wallet/confirm_payment` via GET | 404 (M4 tidak berubah) |
| V51 | Rate limit deposit (6×/15 mnt) | 429 JSON (AJAX) / flashdata sesuai idiom; `retry_after` utuh |
| V52 | Admin 429 (`/control-panel`) | pesan **Indonesia** (L1 terjaga) |
| V53 | Maintenance mode ON | 503 bilingual (plan/95 tidak tersentuh) |

---

## 10. Estimasi & Risiko

| Area | Perkiraan | Catatan |
|---|---|---|
| File diubah | **~22** | +3 file baru (2 skrip audit, 1 migrasi) |
| Key kamus | **+~150 / −1** | 378 → ~530 |
| Baris | **+~900 / −~250** | Mayoritas view (help center & notifikasi = ekstraksi besar) |
| Risiko | **Tinggi:** migrasi notifikasi (schema) | Mitigasi: idempotent + `--dry-run` + kolom lama dipertahankan + backfill "tidak cocok → NULL" |
| Risiko | **Sedang:** `rate_limit_message()` dipakai admin (L1) | Mitigasi: parameter idiom eksplisit + V52 sebagai gate |
| Risiko | **Sedang:** `help/index.php` 193 baris tanpa key sama sekali | Mitigasi: step 9 commit terpisah, diff besar tapi mekanis |
| Risiko | **Rendah:** helper tanggal salah format | Mitigasi: `php -r` smoke 2 idiom di step 1 |
| Risiko | **Rendah:** key baru menambah nilai kolisi P5 | Mitigasi: W9(a) gate |
| Di luar scope (dicatat) | Pemisahan `qris_payment_instructions` per bahasa (§11) | Perlu kolom admin baru — plan/104 |

---

## 11. Catatan Follow-up (bukan bagian plan ini)

1. **`qris_payment_instructions` dua kolom** (`_en` / `_id`): memungkinkan catatan admin sendiri bilingual. Menuntut perubahan form admin `views/admin/settings.php` (tetap ID) + `Admin::qris_settings()`. Tidak diperlukan untuk mencapai **strict purity** karena §6.5 sudah menjamin blok kanonik selalu dalam bahasa aktif.
2. **Notifikasi email/WA** (bila ada di masa depan) harus memakai resolver bahasa penerima, bukan idiom request.
3. **`views/errors/html/maintenance.php`** sudah dwibahasa (plan/95); `error_php.php`/`error_exception.php` masih prosa ID — itu halaman **developer**, bukan member-facing, sengaja dikecualikan.
4. **`views/marketplace/index.php`** belum diaudit dalam plan ini (di luar brief) — kandidat plan/104 sweep penuh; scanner W9(b) akan melaporkannya sebagai temuan terbuka bila ada.

---

## 12. Definition of Done

Plan ini dinyatakan selesai bila **semua** terpenuhi:

- [ ] `scripts/audit_i18n_parity.php` → exit 0 (parity 1:1, 0 kolisi tak sah, 0 pelanggaran P6)
- [ ] `scripts/audit_i18n_hardcoded.php` → exit 0 (**0 temuan** di seluruh scope member)
- [ ] `php -l` bersih pada **setiap** file diubah/dibuat
- [ ] Matriks §9.2 (V9–V30) **PASS** untuk **kedua** idiom di **setiap** state (pending/waiting/success/rejected/expired/legacy/unconfigured)
- [ ] Matriks §9.3 (V31–V40) PASS di browser (360 px & 480 px)
- [ ] Matriks §9.4 (V41–V47) PASS: migrasi idempotent, 0 mutasi uang, 0 invariant DB dilanggar
- [ ] Matriks §9.5 (V48–V53) PASS: tidak ada regresi keamanan/rate-limit/maintenance
- [ ] `git diff --stat` **tidak** memuat `application/views/admin/**` (L1)
- [ ] Tidak ada nilai kamus yang memuat `Rp` atau angka (L6)
- [ ] `plan/103_I18N_PURIFICATION_WALLET_AND_MEMBER_PAGES_SUMMARY.md` terbit dengan bukti (tabel per fase, pola plan/102 §1)

---

## 13. Lampiran A — Risalah Audit (ringkas)

| Kanal | Jumlah temuan | Verifikasi |
|---|---|---|
| **C1** literal di controller (member) | 34 di `Wallet.php`, 6 di `Rentals.php`, 6 di `Profile.php`, 3 di `Auth.php`, 1 di `ratelimit_helper.php` = **50** | `grep -n "set_flashdata\|show_error(" controllers/*.php` + pembacaan manual |
| **C1b** prosa di model (pass-through) | `Wallet_model` 21 cabang, `Rental_model` 7, `User_model` 2 = **30** | `grep -n "'message' =>" models/*.php` |
| **C2** literal di view | `notification` 9, `help` ~49 baris prosa, `home` 3, `profile` 2, `team` 3, `wallet/index` 1 = **~67** | `grep -rnoP "(Salin\|Tersalin\|Silakan\|…)"` + pembacaan per view |
| **C2b** QRIS instructions | 1 (free-text admin tanpa label bahasa) + pemisahan kartu | `views/wallet/pay.php:228–253` |
| **C3** tanggal/timer | 9 situs `date('d M Y')` + 1 countdown tanpa satuan | `grep -rn "date('d M Y" views/ models/` |
| **W8** notifikasi tersimpan | 12 call-site, skema tanpa kolom bahasa | `grep -rn "Notification_model->insert"` |
| **Total temuan** | **~236** | Baseline step 0 akan mengukuhkan angka ini secara otomatis |

## 14. Lampiran B — Perintah Verifikasi (copy-paste)

```bash
# lint
php -l application/language/english/app_lang.php
php -l application/language/indonesian/app_lang.php

# parity + kolisi + P6
php scripts/audit_i18n_parity.php

# hardcoded sweep (target 0)
php scripts/audit_i18n_hardcoded.php

# migrasi notifikasi
php scripts/migrate_103_notification_i18n.php --dry-run
php scripts/migrate_103_notification_i18n.php --apply
php scripts/migrate_103_notification_i18n.php --apply   # re-run → no-op

# smoke HTTP dwibahasa (contoh)
curl -s -b "site_lang=id" http://synapse.test/wallet | grep -c "Pending"   # → 0
curl -s -b "site_lang=en" http://synapse.test/wallet | grep -c "Menunggu"  # → 0
curl -s -b "site_lang=en" http://synapse.test/wallet/pay/INV-XXX | grep -c "Kedaluwarsa"  # → 0

# L1: admin tidak tersentuh
git diff --stat | grep 'views/admin' || echo "L1 OK"
```
