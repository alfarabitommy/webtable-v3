# Plan 113 — Sinkronisasi Dokumentasi Fitur Absensi Harian (Plan 112)

> **Status:** DISETUJUI — menunggu eksekusi E1..E8. Ronde ini **hanya** menulis berkas
> ini (`plan/113_SYNC_CHECKIN_DOCS_PLAN.md`); **belum ada** berkas `docs/` yang diubah.
>
> **Prinsip mengikat (AGENTS.md):** *"When a doc and the code disagree, **code is
> authoritative**."* Seluruh isi §2 diambil dari working tree (HEAD `851db4d`,
> working tree bersih), **bukan** dari `docs/`. `docs/` adalah **target yang
> diperbaiki**, kode adalah **sumber kebenaran**.
>
> **Ruang lingkup:** 4 berkas dokumentasi inti — `docs/1_PRD.md`,
> `docs/2_ERD.md`, `docs/3_ROADMAP.md`, `docs/4_UI_UX_GUIDELINES.md`.
> **Zero-impact pada kode:** nol perubahan `application/`, `system/`, skema DB,
> seed, route, migrasi, kamus i18n, atau helper. Ini pekerjaan **dokumen saja**.
>
> **Rentang drift yang disinkronkan:** plan/112 (Daily Check-in) + koreksi
> rujukan versi lintas-dokumen.
>
> **Keputusan owner:** `dec-c38d618a49de30aa` (D-A/D-B/D-C — lihat §0.1).

---

## 0. Ringkasan Eksekutif & Keputusan Pemilik

| Dokumen | Versi | Kondisi saat ini | Verdict |
|---|---|---|---|
| `docs/1_PRD.md` | **v5.2** | §4.F (baris 212–218) **sudah** memuat mesin check-in lengkap (ini yang di-update plan/112 sendiri) | **HAMPIR SINKRON** — poles delta kecil (§5) |
| `docs/2_ERD.md` | **v5.1** | Nol sebutan check-in: `users` tanpa 2 kolom, `system_settings` tanpa 4 kunci, `wallet_ledger` tanpa konvensi `CHK-`, §7 tanpa invariant | **DRIFT — target v5.2** (§3) |
| `docs/3_ROADMAP.md` | **v6.0** | Blok plan/112 **ADA** (baris 201–209) tetapi **tersisip salah urutan** di antara plan/100 & plan/105 | **SINKRON tapi perlu relokasi + poles** (§6) |
| `docs/4_UI_UX_GUIDELINES.md` | **v5.1** | Nol sebutan widget dashboard absensi & kartu setelan admin | **DRIFT — target v5.2** (§4) |

**Temuan tambahan (in-scope, hasil keputusan §0.1 D-C):** 4 rujukan versi
lintas-dokumen masih usang → `docs/1_PRD.md:252`, `docs/1_PRD.md:376`
(`ERD v5.1 §5`), `docs/1_PRD.md:296` (`4_UI_UX_GUIDELINES.md (v5.1)`),
`docs/3_ROADMAP.md:82` (`ERD v5.1 §5`).

### 0.1 Keputusan yang diminta (SUDAH dijawab — `dec-c38d618a49de30aa`)

| Kode | Pertanyaan | Keputusan owner |
|---|---|---|
| **D-A** | Blok milestone plan/112 tersisip tidak kronologis | **Pindahkan ke akhir log milestone** (setelah plan/110, sebelum `## Upcoming Phases`) |
| **D-B** | Redaksi permintaan menyebut "Card 7"; kode menandai **Card 6** | **Tulis "Card 6 / kartu ke-6 dari 6" sesuai kode** |
| **D-C** | Scope polish tambahan | **Hanya** perbarui rujukan versi lintas-dokumen. Entri milestone plan/111 & temuan non-blocking **tidak** dikerjakan (§8) |

### 0.2 Yang akan dicapai

1. `docs/2_ERD.md` → **v5.2**: 2 kolom `users`, 4 kunci `system_settings` + ambang
   validasi + fallback, konvensi `transaction_id` `CHK-`, invariant idempotensi
   harian di §7.
2. `docs/4_UI_UX_GUIDELINES.md` → **v5.2**: §5.**I** (widget member `.hm112-card`)
   + §8.**D** (kartu admin absensi, **Card 6**) + 2 penajaman kecil (§2 z-index,
   §7 animasi).
3. `docs/1_PRD.md` → tetap **v5.2**: poles §4.F (kontrak endpoint & HTTP code
   eksplisit) + 3 rujukan versi.
4. `docs/3_ROADMAP.md` → tetap **v6.0**: relokasi blok plan/112 + poles + catatan
   header + 1 rujukan versi.

---

## 1. Metodologi & Sumber Bukti

| Sumber kebenaran | Perannya di plan ini |
|---|---|
| `database.sql` (`:11-41` DDL `users`, `:401-409` seed, `:642-665` catatan MIGRASI LIVE) | Skema + seed kanonik |
| `database_seed.sql:322-325` | Seed instalasi bersih |
| `scripts/migrate_112_daily_checkin.php` | Kontrak migrasi (nyata, sudah dijalankan) |
| `application/models/Checkin_model.php` | Setelan, validator, status widget, **jalur uang** `claim()` |
| `application/controllers/Checkin.php`, `Home.php`, `Admin.php` | Endpoint & integrasi |
| `application/config/checkin_rewards.php`, `application/config/routes.php:99-100` | Fallback fail-safe & route |
| `application/views/home/index.php`, `application/views/admin/settings.php` | Ground truth UI/UX |
| `application/helpers/i18n_helper.php:450-452`, `application/language/{english,indonesian}/app_lang.php` | Renderer ledger & kamus (**621/621** key) |
| `plan/112_*_{PLAN,SUMMARY}.md`, `plan/111_SYNC_DOCUMENTATION_GROUND_TRUTH_PLAN.md` | Catatan perubahan resmi + pola/precedent plan/113 |

**Aturan penulisan ulang:** kode tanpa dokumen → **ditambahkan**; dokumen tanpa
kode → **dihapus/ditulis ulang**; angka berbeda → **angka kode menang**.

> **Catatan metodologis (jujur):** tool `codebase-memory-mcp/search_code` diblokir
> host di plan mode, sehingga **seluruh** ground truth §2 diambil dari pembacaan
> langsung berkas — setiap klaim disertai `file:line`.

---

## 2. GROUND TRUTH TERVERIFIKASI (dipakai menulis ulang dokumen)

### 2.1 Skema `users` — 2 kolom (tanpa tabel baru, tanpa index baru)

| Fakta | Bukti |
|---|---|
| `checkin_streak INT UNSIGNED NOT NULL DEFAULT 0` (`AFTER last_wage_claimed_at`) | `database.sql:31` |
| `checkin_last_date DATE NULL DEFAULT NULL` (`AFTER checkin_streak`) | `database.sql:32` |
| **Tidak ada tabel `user_checkins`**, tidak ada kolom total, **tidak ada index baru** (semua baca jalur uang = PK) | `database.sql:11-41`; `Checkin_model.php:17-24` |
| Histori harian = derivasi `wallet_ledger` prefix `CHK-%` (terlayani `idx_user_id`) | `Checkin_model.php:22-23, 518-522` |
| Migrasi CLI: `--dry-run`/`--apply`/`--verify`, exit 0/1/2, DDL dijaga `information_schema`, 4× `INSERT IGNORE`, tamper → exit 2, **tanpa backfill** | `scripts/migrate_112_daily_checkin.php`; `database.sql:642-665` |

### 2.2 `system_settings` — 4 kunci baru

| Key | Seed | Nilai sah (kode) | Peran | Bukti |
|---|---|---|---|---|
| `checkin_enabled` | `'1'` | persis `'0'` \| `'1'` | Gerbang **fail-closed** (widget + endpoint) | `database.sql:406`; `Checkin_model.php:86-93` |
| `checkin_base_reward` | `'50'` | integer `^[1-9][0-9]*$`, `1 … BASE_MAX = 1.000.000` | Bonus hari ke-1 | `Checkin_model.php:29, 163-173` |
| `checkin_max_reward` | `'10000'` | integer `^[1-9][0-9]*$`, `1 … MAX_MAX = 10.000.000` | Cap harian keras | `Checkin_model.php:30, 176-186` |
| `checkin_streak_policy` | `'reset'` | whitelist `'reset'` \| `'continue'` | Kebijakan saat bolong | `Checkin_model.php:117-124, 196-204` |

| Invarian / fallback | Fakta | Bukti |
|---|---|---|
| Koherensi | **`1 ≤ base ≤ max`**; pelanggaran → pasangan base+max dikembalikan **ATOMIK** ke fallback + `log_message('error')` ("tidak pernah fatal") | `Checkin_model.php:103-114` |
| Pesan validator (Indonesia, L1) | "Bonus hari pertama harus angka bulat 1–1000000 (IDR)." · "Batas harian harus angka bulat 1–10000000 (IDR)." · "Bonus hari pertama tidak boleh melebihi batas harian." · "Kebijakan streak harus dipilih…" | `Checkin_model.php:168, 181, 190, 199` |
| Fallback config | `application/config/checkin_rewards.php` → `1 / 50 / 10000 / 'reset'` (per-key) | `checkin_rewards.php:19-24` |
| Migrasi tamper | CLI `--verify` deteksi nilai rusak → **exit 2** | `plan/112_SUMMARY.md §2.3`; `database.sql:665` |

### 2.3 Ledger — idempotensi harian (`wallet_ledger`)

| Fakta | Bukti |
|---|---|
| `transaction_id` = **`CHK-{user_id}-{Ymd}`** (mis. `CHK-12-20260920`); "hari ke-N" hidup di `description`, **bukan** di ID | `Checkin_model.php:410`; `database.sql:26-30` |
| Jaminan tingkat DB: `UNIQUE (user_id, transaction_id, type)` = `uk_wallet_ledger_user_tx_type` → **maksimal satu kredit per user per hari** | `database.sql:199` (index); `Checkin_model.php:20-21` |
| Deskripsi kanonik `Bonus Absensi Harian Hari ke-{N}` (tanpa nominal → kamus bersih P3/L6) | `Checkin_model.php:411` |
| Renderer ledger dwibahasa: pola regex `/^Bonus Absensi Harian Hari ke-(\d+)$/u` → key `ledger_checkin` | `i18n_helper.php:450-452`; `app_lang.php` EN `'Daily check-in bonus - day %s'` / ID `'Bonus Absensi Harian Hari ke-%s'` |
| Prefix `CHK-` ditulis **kredit tunggal** via `Wallet_model::credit()` (satu-satunya jalur uang, C4/M8) | `Checkin_model.php:413` |
| `get_lifetime_claims()` (derivasi `LIKE 'CHK-%'`) **belum punya konsumen UI** | `Checkin_model.php:518-522` (nol pemanggil di `application/`) |

### 2.4 Mesin klaim & kontrak endpoint

| Fakta | Bukti |
|---|---|
| `Checkin_model::claim()` = **satu TX**: anchor `SELECT … FROM users WHERE id = ? FOR UPDATE` → hitung hari (`_streak_next`) → `UPDATE` **kondisional** (`checkin_last_date < ?`) + `affected_rows() === 1` → `Wallet_model::credit()` → commit | `Checkin_model.php:362-438` |
| `db_debug` dimatikan lokal (save/restore) dan `$this->db->error()` dibaca **SEBELUM** rollback → 1062/23000 ditranslasi `already_claimed` (**jalur AJAX tidak pernah HTML**) | `Checkin_model.php:354-359, 414-430` |
| Kode hasil: `ok` · `already_claimed` · `user_unavailable` · `disabled` · `error` | `Checkin_model.php:332-341, 347, 373, 384, 406, 424, 429` |
| Fungsi murni `_streak_next()` / `_reward_for()` (`min(base × hari, max)`, integer; `reset` → 1, `continue` → N+1, tanggal masa depan → "sudah klaim") | `Checkin_model.php:380-381, 388, 471-516` |
| Otoritas "hari" = **PHP WIB** `date('Y-m-d')`; badge 7 hari & `next_window_ts` = epoch tengah malam WIB berikutnya (dari server) | `Checkin_model.php:378, 247, 300-307` |
| `Checkin::claim()`: POST-only + AJAX-only (`show_404()` selain itu), sesi (401), rate limit `checkin_claim:{uid}` **5/60** (`rate_limit_json_response`), envelope `api_*` + key legacy | `Checkin.php:39-118` |
| HTTP: `disabled` → **403**; `unauthenticated` → **401**; `error` → 500; bisnis lain → 200. Sukses **dan** penolakan membawa `status` segar (widget memutakhirkan diri tanpa reload) | `Checkin.php:54, 76, 88, 113`; `plan/112_SUMMARY.md §3.2` |
| Route eksplisit `$route['checkin/claim'] = 'checkin/claim'` | `routes.php:99-100` |
| `Home` menyuntik `$data['checkin'] = Checkin_model::get_status($user_id)` di dashboard saja — **`MY_Controller` tidak diubah** (nol query tambahan per request) | `Home.php` (+5); `plan/112_SUMMARY.md §1` |

### 2.5 Widget member — `.hm112-card` (`views/home/index.php`)

| Fakta | Bukti |
|---|---|
| **Lokasi:** disisipkan **setelah kartu hero plan/99** (berakhir baris 256) dan **sebelum kartu identitas** (mulai baris 480) | `home/index.php:229-256` → `:258` → `:480` |
| **Fail-closed:** seluruh blok dibungkus `if (!empty($checkin['enabled']))` → saat `'0'` elemen **tidak ada** di DOM (hanya CSS terkirim) | `home/index.php:258-471` |
| Urutan hierarki: header (badge `fa-calendar-check` + judul/subjudul) → **chip rentetan `fa-fire`** (`#hm112-streak-days`) → **bonus hari ini** (`#hm112-amount`, `text-2xl font-extrabold`, prefix `Rp `) + label hari (`#hm112-day-label`, `sprintf(home_checkin_day)`) → **bar progres ke cap** (`#hm112-bar`, `u-progress-track`, `intdiv` ceil: hari 1 = 1%, cap = 100%) + label `Batas harian` → **pratinjau 7 hari** (`grid grid-cols-7 gap-1`, `.hm112-day` / hari berikutnya `.hm112-day-next`, label 9–10 px) → **timer** `#hm112-timer` (`font-mono`, `HH:MM:SS` ke tengah malam WIB dari `data-window-ts`) → tombol klaim → hint kebijakan → region status `aria-live` | `home/index.php:290-349` |
| **Tombol:** `#hm112-claim` full-width `h-12`, gradien indigo→cyan `.hm112-btn`; **belum klaim** → `hm112-pulse` (`box-shadow` 2.4 s) + `fa-hand-holding-usd`; **sudah klaim** → `disabled` nyata + `fa-circle-check` + label `Claimed Today`, tanpa pulse | `home/index.php:209-226, 340-346` |
| **A11y:** `aria-label` pada tombol, `aria-live="polite"` pada `#hm112-status`, target ≥ 44 px (`h-12`) | `home/index.php:342, 349` |
| **Toast** `#hm112-toast` (`u-toast`, `z-[60]`, `pointer-events-none`, auto-hide 2.6 s) | `home/index.php:352, 379-386` |
| Klaim: `csrfFetch('checkin/claim', {method:'POST'})` → `paint(d.data.status)` (streak, nominal, bar, 7 pill, tombol) **tanpa reload**; `disabled` → reload agar widget hilang | `home/index.php:354-471` |
| **Tema & animasi:** token `--u-*` + `html.dark .hm112-*`; hanya `color/bg/border/shadow/animation` (radius/spacing tetap Tailwind; **tanpa** `<defs>`/`id` ganda); `prefers-reduced-motion` → `.hm112-card *` dianimasikan-off | `home/index.php:161-166, 168-226` |
| Ikon **Font Awesome** saja (nol aset/CDN baru); string dinamis via `window.SYNAPSE_I18N`; uang dirender PHP `number_format(…,0,',','.')` + JS `Intl.NumberFormat('id-ID')` (L6) | `home/index.php:270-281, 312, 318, 329, 361` |

### 2.6 Kartu admin — **Card 6** (`views/admin/settings.php`)

| Fakta | Bukti |
|---|---|
| Nomor kartu **6 dari 6**: 1 Kontak & Bantuan · 2 Operational Hours · 3 Biaya Penarikan · 4 Deposit Fee · 5 Komisi Rebate · **6 Absensi Harian** | `settings.php:128, 193, 236, 365, 403, 442` |
| Berada **di dalam** `form_open('admin/settings')` → mewarisi CSRF + `data-guard-submit`; tepat sebelum blok submit | `settings.php:442-515` |
| Layout: judul `fa-calendar-check text-amber-500` + paragraf penjelas → **toggle** `#checkin_enabled` → **grid** `sm:grid-cols-3` (bonus hari pertama `number min="1" max="1000000"`, batas harian `number min="1" max="10000000"`, dropdown `#checkin_streak_policy` opsi `reset`/`continue`) → paragraf contoh perhitungan | `settings.php:447-514` |
| Error **inline di kartunya sendiri**: `$checkin_errors = $error_flat(array_filter($field_errors, key berawalan checkin_))` (kartu finansial tidak ikut tercemar) | `settings.php:52-63, 460-469` |
| Repopulasi: `settings_form_state` + `$state_val($fs, 'checkin_*', …)` + `htmlspecialchars()` | `settings.php:80-86`; `Admin.php:705-708` |
| Copy **100% Indonesia** (L1, tanpa key i18n); all-or-nothing + audit `admin_update_settings` (key `checkin_*` otomatis) | `Admin.php:467-475, 606-610` |

### 2.7 i18n & hal yang **tidak** berubah

| Fakta | Bukti |
|---|---|
| Kamus **621/621** key identik (18 `home_checkin_*` + `ledger_checkin`) | `grep -c '^$lang\['` = 621 di kedua idiom |
| **Tidak** ada tabel baru · **tidak** ada index baru · **tidak** ada notifikasi harian · **tidak** ada cron · **tidak** ada perubahan `MY_Controller` · **tidak** ada perubahan nilai ENUM `wallet_ledger` | `plan/112_SUMMARY.md §7`; `database.sql:190-204` |

---

## 3. Rencana Perubahan — `docs/2_ERD.md` (v5.1 → **v5.2**)

### 3.1 Header (baris 1–16)

| Aksi | Detail |
|---|---|
| **REWRITE judul** | `… & Database Schema v5.1` → **`v5.2`** (baris 1) |
| **TAMBAH blok catatan** | Paragraf baru ala plan/111 (baris 8–16): *"**v5.2 — catatan sinkronisasi (plan/112).** … 2 kolom `users` (`checkin_streak`, `checkin_last_date`), 4 kunci `system_settings` `checkin_*`, idempotensi ledger harian `CHK-{user_id}-{Ymd}`; **14 tabel kanonik tetap** (tanpa tabel baru)."* |

### 3.2 §0 Diagram Mermaid (baris 18–205)

| Blok | Aksi |
|---|---|
| `users` (baris 33–49) | **TAMBAH 2 baris** tepat setelah `DATETIME last_wage_claimed_at`: `INTU checkin_streak "plan/112: hari beruntun terakhir DIBAYAR (0 = belum pernah)"` dan `DATE checkin_last_date "plan/112: tanggal WIB klaim terakhir (otoritas harian)"` — pakai konvensi tipe yang sudah ada (`SMALLINTU`, `TINYINT1`, `DECIMAL15-2`) |
| `system_settings` (baris 167–172) | **TIDAK** berubah entitas (key-value) — cukup ditangani di §4 tabel key |
| Relasi (baris 190–204) | **TIDAK** berubah: tidak ada FK/relasi baru (users ↔ wallet_ledger sudah ada) |
| Catatan header diagram | Tegaskan **14 tabel kanonik tetap** (baris 20–22) — jangan disentuh |

### 3.3 §2 Tabel `users` (baris 222–240) — **TAMBAH 2 bullet kolom**

Disisipkan setelah bullet `last_wage_claimed_at` (baris 236), sebelum `created_at`:

* `checkin_streak` (INT UNSIGNED, NOT NULL, DEFAULT 0) — **plan/112:** hari
  beruntun terakhir yang **DIBAYAR** (0 = belum pernah).
* `checkin_last_date` (DATE, NULLABLE) — **plan/112:** tanggal WIB klaim terakhir;
  pembanding harian murni (jam tidak relevan).

* **Tambah baris `Invariant` di blok penutup:** otoritas "hari" = **PHP WIB**,
  bukan `NOW()`/`CURDATE()`; tanggal masa depan → fail-closed ("sudah klaim");
  **tanpa index tambahan** (baca jalur uang selalu `WHERE id = ?` = PK); histori
  klaim **tidak** punya tabel sendiri → derivasi `wallet_ledger` prefix `CHK-%`.

### 3.4 §3 Tabel `wallet_ledger` (baris 332–349) — **PERLUAS konvensi & tambah invarian**

| Aksi | Detail |
|---|---|
| **REWRITE bullet** `transaction_id` (baris 337) | Tambahkan ke daftar contoh: ``… `RBT-{rental_id}-L{tier}` rebate, `ROI-{rental_id}-D…`, **`CHK-{user_id}-{Ymd}` absensi harian (plan/112)**`` |
| **TAMBAH catatan idempotensi harian** di bawah blok uniqueness (baris 343) | *"**Plan 112 — idempotensi harian:** `CHK-{user_id}-{Ymd}` + `uk_wallet_ledger_user_tx_type` = **maksimal satu kredit per user per hari** di tingkat DB; duplikat 1062/23000 ditranslasi `already_claimed` (bukan error). Deskripsi kanonik `Bonus Absensi Harian Hari ke-{N}` (tanpa nominal), dirender dalam idiom aktif via key `ledger_checkin`."* |
| **TAMBAH di blok ACID/Z1** | Satu kalimat: kredit absensi juga lewat **satu-satunya** jalur `Wallet_model::credit()` (C4/M8). |

### 3.5 §4 Tabel `system_settings` (baris 399–436) — **TAMBAH 4 baris key + 1 paragraf fallback**

Tambahkan ke tabel "Key ter-seed" (setelah baris 427, sebelum blok fallback):

| Key | Default | Nilai sah (validator/`get_config`) | Peran |
|---|---|---|---|
| **`checkin_enabled`** | `1` | `'0'` \| `'1'` | **Plan 112:** gerbang **fail-closed** — `'0'` → widget member **tidak dirender** DAN endpoint klaim menolak (**HTTP 403**) |
| **`checkin_base_reward`** | `50` | integer `^[1-9][0-9]*$`, `1 … 1.000.000` (`BASE_MAX`) | Bonus hari ke-1 (IDR) |
| **`checkin_max_reward`** | `10000` | integer `^[1-9][0-9]*$`, `1 … 10.000.000` (`MAX_MAX`) | Cap harian keras |
| **`checkin_streak_policy`** | `reset` | `'reset'` \| `'continue'` | Kebijakan saat bolong (`reset` → hari 1; `continue` → N+1, gap dibekukan) |

Dan paragraf fallback baru setelah blok `rebate_commission.php` (baris 429):

> **Fallback absensi harian:** `application/config/checkin_rewards.php`
> (1 / 50 / 10000 / `'reset'`) — dipakai per-key bila baris hilang/rusak; invarian
> **`1 ≤ base ≤ max`** ditegakkan `Checkin_model::_resolve_config()` dengan
> **fallback ATOMIK pasangan base+max** + `log_message('error')` (setelan rusak
> **tidak pernah** fatal). Ambang administratif hidup **satu sumber** di
> `Checkin_model::BASE_MAX`/`MAX_MAX`; validator admin memakai kontrak aditif
> `{ok, errors, notices, field_errors, values}`;
> `scripts/migrate_112_daily_checkin.php --verify` mendeteksi tamper → **exit 2**.

### 3.6 §7 Invariant (baris 516–555) — **TAMBAH sub-blok baru**

Setelah sub-blok `(plan/102–110)` (berakhir baris 555), tambahkan:

**### Invariant arsitektural tambahan (plan/112 — Daily Check-in)**

* **C1 — Idempotensi harian tingkat DB:** satu kredit per user per hari dijamin
  `CHK-{user_id}-{Ymd}` + `uk_wallet_ledger_user_tx_type`; duplikat 1062 →
  `already_claimed`.
* **C2 — Fail-closed dua sisi:** `checkin_enabled='0'` → widget tidak dirender
  (DOM kosong) **dan** endpoint menolak 403; nilai rusak → fallback.
* **C3 — Otoritas tanggal = PHP WIB:** `date('Y-m-d')`, bukan `NOW()`/`CURDATE()`;
  tanggal masa depan diperlakukan "sudah klaim".
* **C4 — Tanpa tabel/index/kolom total baru:** state = 2 kolom `users`; histori =
  derivasi ledger (`LIKE 'CHK-%'`, terlayani `idx_user_id`).
* **C5 — Satu jalur uang:** seluruh kredit lewat `Wallet_model::credit()` di dalam
  satu TX (`users FOR UPDATE` → `UPDATE` kondisional `affected_rows()===1` →
  kredit); nol mutasi saldo di jalur lain.

### 3.7 Yang **tidak** diubah di ERD

§1 (Rules for AI Agent), §5 (`user_notifications`), §6 (`admins`,
`promoter_claims`, `system_audit_logs`), inventory 14 tabel, tabel
`deposits`/`bank_accounts`/`ewallet_providers`/`withdrawals`/`rate_limits`,
sub-blok invariant plan/89–92 & plan/102–110.

---

## 4. Rencana Perubahan — `docs/4_UI_UX_GUIDELINES.md` (v5.1 → **v5.2**)

### 4.1 Header (baris 1–13)

| Aksi | Detail |
|---|---|
| **REWRITE judul** | `… Component Guidelines v5.1` → **`v5.2`** (baris 1) |
| **TAMBAH catatan** | *"**v5.2 — catatan sinkronisasi (plan/112).** … §5.I widget absensi harian dashboard member (scoped `hm112-*`, Font Awesome, nol aset baru) dan §8.D kartu setelan **Absensi Harian** (Card 6) di `/admin/settings`."* |

### 4.2 **§5.I BARU — "Member Dashboard Check-in Widget (`.hm112-card`) — plan/112"**

Disisipkan **setelah §5.H** (berakhir baris 305), **sebelum** `---`/`## 6`.
Isi (semuanya dari `views/home/index.php`):

1. **Lokasi (STRICT):** `views/home/index.php`, **di antara kartu hero plan/99 dan
   kartu identitas**. Urutan dashboard tidak boleh diubah.
2. **Fail-closed:** dibungkus `if (!empty($checkin['enabled']))` → saat
   `checkin_enabled='0'` **tidak ada elemen** di DOM (hanya CSS yang tetap
   terkirim).
3. **Hierarki visual (atas → bawah):** badge `fa-calendar-check` + judul/subjudul
   (10–11 px) → **chip rentetan hari** (`fa-fire`, `.hm112-chip`, amber) →
   **nominal hari ini** `Rp {number_format}` `text-2xl font-extrabold` + label
   `Hari ke-N` → **bar progres ke cap harian** (`.u-progress-track`, `#hm112-bar`,
   persen = `intdiv` ceil: hari 1 = 1%, cap = 100%) + label
   `Batas harian · Rp {max}` → **strip pratinjau 7 hari**
   (`grid grid-cols-7 gap-1`, `.hm112-day`, hari berikutnya di-ring
   `.hm112-day-next`; angka 9–10 px, aman di 360 px) → **hitung mundur WIB**
   (`#hm112-timer`, `font-mono`, epoch dari server `data-window-ts`) → **tombol
   klaim** → hint kebijakan → region status `aria-live`.
4. **Label timer dua-keadaan:** belum klaim → `home_checkin_window_left`; sudah
   klaim → `home_checkin_next_in`.
5. **State tombol (STRICT):** belum klaim → gradien indigo→cyan `.hm112-btn` +
   `hm112-pulse` (`box-shadow` 0→12 px, 2.4 s) + `fa-hand-holding-usd`; sudah
   klaim → **`disabled` nyata** + `fa-circle-check` + `Claimed Today`, pulse
   hilang, tampilan `--u-surface-3`/muted.
6. **Toast:** `#hm112-toast` (`u-toast`, `z-[60]`, `pointer-events-none`,
   auto-hide 2.6 s) — cross-ref §2.
7. **Klaim tanpa reload:** `csrfFetch('checkin/claim', {method:'POST'})` (via
   `templates/csrf_meta.php`) → **repaint dari `d.data.status`** (streak, nominal,
   bar, 7 pill, tombol); `code='disabled'` → reload agar widget hilang.
8. **A11y:** `aria-label` tombol, `aria-live="polite"`, target sentuh `h-12`
   (≥ 44 px), `disabled` fungsional bukan sekadar visual.
9. **Tema & animasi:** hanya token `--u-*` + `html.dark .hm112-*`; scoped CSS
   hanya `color/background/border/box-shadow/animation` (radius & spacing tetap
   utility Tailwind — pola §5.F/plan/99); **tanpa** `<defs>`/`id` duplikat;
   `prefers-reduced-motion` mematikan animasi (`.hm112-card *`).
10. **Angka uang tidak pernah masuk kamus** (L6/P3): PHP
    `number_format($v,0,',','.')` + prefix `Rp `; update JS via
    `Intl.NumberFormat('id-ID', {maximumFractionDigits:0})`. Ikon **Font Awesome
    only** — nol aset/CDN baru.
11. **Anti-pattern:** (a) merender widget saat fitur OFF; (b) menghitung ulang
    tanggal/nominal di klien (server yang menentukan); (c) memakai
    `placehold.co`/ikon gambar; (d) animasi tanpa jalur `prefers-reduced-motion`.

### 4.3 **§8.D BARU — "Admin Check-in Settings (`/admin/settings`, Kartu 6) — plan/112"**

Disisipkan **setelah §8.C** (berakhir baris 508). Isi (dari
`views/admin/settings.php:442-515` + `Admin.php:467-475, 606-610, 705-708`):

1. **Posisi kartu:** **kartu ke-6 dari 6** (STRICT, sesuai komentar `Card 6` di
   kode). Berada **di dalam** `form_open('admin/settings')` → mewarisi **CSRF** +
   `data-guard-submit`, dan **tepat sebelum** blok tombol simpan.
2. **Copy 100% Indonesia (L1)** — tanpa key i18n.
3. **Layout input:** judul `fa-calendar-check` + paragraf penjelas → **toggle**
   `#checkin_enabled` (label kiri, checkbox `value="1"`) → **grid
   `sm:grid-cols-3`**: `#checkin_base_reward` (`number`,
   `min=1 max=1000000 step=1 required`, `font-mono`), `#checkin_max_reward`
   (`number`, `min=1 max=10000000 step=1 required`), `#checkin_streak_policy`
   (`<select>`: `Reset ke Hari 1` / `Lanjutkan (streak dibekukan)`) → paragraf
   contoh perhitungan (`50 & 10.000 → 50/100/150 … cap 10.000`).
4. **Error inline blocking:** bila `field_errors` berawalan `checkin_*` ada → blok
   merah `Periksa kembali:` **di dalam kartu ini sendiri** (kartu finansial lain
   tidak tercemar). Validasi **all-or-nothing**: satu error → seluruh form tidak
   dipersist, DB tidak berubah.
5. **Repopulasi:** nilai yang sudah diketik dikembalikan setelah simpan gagal via
   `flashdata('settings_form_state')` + `$state_val()` + `htmlspecialchars()`
   (toggle: `array_key_exists` → `!empty`).
6. **Persist & audit:** `Admin_model::update_system_settings()` dalam satu TX →
   audit `admin_update_settings` dengan `keys/before/after` yang **otomatis**
   memuat `checkin_*` (hanya saat berubah).
7. **Anti-pattern:** (a) menaruh pesan error absensi di kartu finansial (atau
   sebaliknya); (b) melewatkan repopulasi → admin kehilangan ketikan;
   (c) menampilkan angka dengan pecahan/format non-IDR.

### 4.4 Penajaman kecil di section yang sudah ada

| § | Aksi |
|---|---|
| **§2 Z-Index Layering** (baris 24–38) | Tambahkan anggota layer pada tabel: **`z-[60]`** → *"Bottom Sheet / Dropdown / **Toast notifikasi member (`.u-toast`, mis. `#hm112-toast` — plan/112)**"* — agar tidak ada "layer liar" baru di luar standar. |
| **§7 Animation & Transition** (baris 384–400) | Tambahkan 1 butir: *"**Check-in claim pulse:** `box-shadow` `hm112-pulse` 2.4 s `ease-out` `infinite`, **hanya** saat tombol klaim aktif; dimatikan saat `disabled` dan oleh `prefers-reduced-motion`."* |
| §1, §3, §4, §5.A–5.H, §6, §8.A–8.C | **Tidak** diubah. |

---

## 5. Rencana Perubahan — `docs/1_PRD.md` (tetap **v5.2**)

§4.F **sudah** memuat mesin check-in (baris 212–218) — hasil plan/112 sendiri.
Yang tersisa adalah **delta penajaman** + koreksi rujukan.

### 5.1 §4.F — "Bonus Absensi Harian" (baris 212–218)

**Verifikasi yang sudah lulus (jangan ditulis ulang, cukup dikonfirmasi di
ringkasan):** `POST /checkin/claim` ✔ · rate limit `checkin_claim:{user_id}`
**5/60 dtk** ✔ · idempotensi `CHK-{user_id}-{Ymd}` + UNIQUE
`(user_id, transaction_id, type)` ✔ · 1062 → `already_claimed` (bukan error) ✔ ·
`enabled='0'` → HTTP **403** + widget tidak dirender ✔ · policy `continue` =
**lanjut N+1 (gap tidak mereset, hari terlewat tidak dihitung)** ✔ · invarian
`1 ≤ base ≤ max` + fallback `checkin_rewards.php` ✔ · deskripsi ledger + key
`ledger_checkin` ✔.

**Tambahan usulan (2 sub-bullet, delta minimal):**

* **Kontrak respons endpoint (eksplisit):** kode `ok` · `already_claimed` ·
  `user_unavailable` (**HTTP 200**, penolakan bisnis) · `disabled` (**HTTP 403**)
  · `error` (**500**) · `unauthenticated` (**401**); POST-only **dan** AJAX-only
  (`show_404()` selain itu); respons **sukses maupun penolakan** menyertakan
  `status` segar (widget memutakhirkan diri tanpa reload); envelope lewat
  `api_helper` (`api_success()`/`api_error()`) — tidak pernah HTML.
* **Urutan TX yang mengikat:** `users FOR UPDATE` (anchor) → `UPDATE`
  **kondisional** (`checkin_last_date < hari ini`) + `affected_rows() === 1` →
  `Wallet_model::credit()`; `db_debug` dimatikan lokal & `$this->db->error()`
  dibaca **sebelum** rollback (pelajaran jalur 1062 → JSON).

### 5.2 Koreksi rujukan versi (in-scope, D-C)

| Lokasi | Sekarang | Menjadi |
|---|---|---|
| `docs/1_PRD.md:296` (§5) | `docs/4_UI_UX_GUIDELINES.md (v5.1)` | `docs/4_UI_UX_GUIDELINES.md (v5.2)` |
| `docs/1_PRD.md:252` (§4.H) | `see ERD v5.1 §5` | `see ERD v5.2 §5` |
| `docs/1_PRD.md:376` (§8) | `see ERD v5.1 §5` | `see ERD v5.2 §5` |

### 5.3 Yang **tidak** diubah

Judul/versi (tetap v5.2), catatan header baris 6–11, §1–§3, §4.A–§4.E, §4.G,
§4.H (selain rujukan versi), §5 (selain rujukan), §6, §7.A–§7.D, §8 (selain
rujukan).

---

## 6. Rencana Perubahan — `docs/3_ROADMAP.md` (tetap **v6.0**)

### 6.1 Relokasi blok milestone plan/112 (keputusan **D-A**)

| Aksi | Detail |
|---|---|
| **CUT** | Blok `### Daily Check-in — Bonus Absensi Harian (Member Widget + Admin Settings) ✅ COMPLETED (plan/112)` + 8 bullet (baris **201–209**) dari posisinya saat ini (antara plan/100 dan plan/105) |
| **PASTE** | Blok yang sama, **byte-identik**, ke **akhir log milestone**: setelah blok plan/110 (berakhir baris 281), sebelum `---` (baris 283) / `## Upcoming Phases` (baris 285) |
| Hasil | Urutan log berakhir `… plan/108 → plan/109 → plan/110 → plan/112`, konsisten dengan kebiasaan append plan/111 |

### 6.2 Poles isi blok (2 penajaman)

* Bullet **Admin**: tambahkan penanda nomor kartu — *"… kartu **Absensi Harian
  (Daily Check-in)** — **Card 6 dari 6** di `/admin/settings` …"* (konsisten D-B).
* Bullet **Kamus**: tambahkan penyebab key ke-19 — *"…+19 key (18
  `home_checkin_*` + `ledger_checkin`, pola renderer deskripsi ledger di
  `i18n_helper.php`) → 621 …"*.

### 6.3 Header dokumen (baris 6–13)

Perbarui catatan sinkronisasi: pertahankan paragraf plan/111 & kalimat plan/112
(baris 12–13), lalu tambahkan **satu kalimat**: *"**plan/113** menyinkronkan
`docs/2_ERD.md` **v5.2** & `docs/4_UI_UX_GUIDELINES.md` **v5.2** dengan ground
truth plan/112 (nomor versi ROADMAP tetap v6.0)."* — sekaligus merujuk
`plan/113_SYNC_CHECKIN_DOCS_SUMMARY.md`.

### 6.4 Koreksi rujukan versi (in-scope, D-C)

| Lokasi | Sekarang | Menjadi |
|---|---|---|
| `docs/3_ROADMAP.md:82` (7D1) | `(ERD v5.1 §5)` | `(ERD v5.2 §5)` |

**Dibiarkan apa adanya (historis, punya konteks waktu):** baris 31
(`sesuai ERD v5.0`), baris 108 (`PRD v5.1 §4.G`), baris 34/48/73/84/85/90/91/95
(anotasi plan/111), baris 4–5 & Strict Rules.

### 6.5 Yang **tidak** diubah

Struktur fase (Phase 1–12), semua blok milestone lain, Strict Rules,
`## Upcoming Phases` (Phase 11/12 tetap PLANNED), nomor versi v6.0.

---

## 7. Yang **TIDAK** Diubah (zero-diff yang disengaja)

* Seluruh `application/**` dan `system/**` — nol perubahan.
* `database.sql`, `database_seed.sql`, seluruh `scripts/*` — nol perubahan.
* `application/language/**` — nol perubahan (dokumen bukan surface member; gate
  i18n tidak relevan).
* `plan/1..112_*` — arsip historis, **tidak** disentuh (hanya menambah
  `plan/113_*`).
* `AGENTS.md`, `docs/5_AUDIT_REPORT.md` — di luar 4 dokumen inti.
* `reasonix.toml`, `.reasonix/`, `.kilo/` — tooling, bukan dokumentasi.

---

## 8. Temuan di Luar Scope (dicatat, **tidak** dikerjakan — keputusan **D-C**)

1. `Checkin_model::get_lifetime_claims()` (baris 518–522) **belum punya
   konsumen** di `application/` — derivasi ledger yang siap pakai tetapi belum
   dirender di UI mana pun. Kandidat follow-up (halaman riwayat/kalender absensi
   sengaja **non-goal** plan/112 §7).
2. `scripts/audit_i18n_hardcoded.php` memindai `application/logs/*` (gitignored)
   → log runtime dapat dilaporkan sebagai "temuan". Rekomendasi lanjutan plan/112
   §6.1: kecualikan direktori log dari pemindaian.
3. Blok milestone **plan/111** tidak ada di log ROADMAP (hanya catatan header
   baris 6–11) — **tidak** diisi oleh plan/113.
4. `docs/2_ERD.md:519` & `docs/3_ROADMAP.md:31` masih merujuk "ERD v3.0/v5.0" —
   rujukan **historis** yang benar apa adanya, **tidak** diubah.

---

## 9. Verifikasi (dijalankan pada fase eksekusi, bukan sekarang)

| # | Cek | Metode | Kriteria lulus |
|---|---|---|---|
| **V1** | Tidak ada klaim yang bertentangan dengan kode | Grep silang: setiap kolom/key/angka/enum baru di `docs/` dicocokkan ke `database.sql` + `Checkin_model.php` | 0 mismatch |
| **V2** | 2 kolom `users` di ERD ≡ DDL | Diff manual `docs/2_ERD.md` §0 + §2 vs `database.sql:31-32` | identik (`INT UNSIGNED DEFAULT 0` / `DATE NULL DEFAULT NULL`) |
| **V3** | 4 key `system_settings` di ERD ≡ seed | Diff tabel §4 vs `database.sql:406-409` + `database_seed.sql:322-325` | superset tepat (0 key hilang, 0 key fiktif) |
| **V4** | Ambang validasi di ERD ≡ kode | Diff vs `Checkin_model::BASE_MAX`/`MAX_MAX` + pesan validator baris 168/181/190/199 | identik (1.000.000 / 10.000.000 / `1 ≤ base ≤ max`) |
| **V5** | Invariant §7 ≡ `claim()` | Diff format `CHK-{user_id}-{Ymd}` + `uk_wallet_ledger_user_tx_type` vs `Checkin_model.php:20-21, 410` & `database.sql:199` | identik |
| **V6** | §5.I & §8.D ≡ view | Diff class/id/hierarki vs `views/home/index.php:161-471` & `views/admin/settings.php:52-86, 442-515` | identik (termasuk **Card 6**, `hm112-pulse` vs `disabled`, `grid-cols-7`, `z-[60]`) |
| **V7** | Tidak ada referensi mati | Grep di `docs/`: `Card 7`, `user_checkins`, `checkin_total`, `checkin_reward` (nilai ENUM), `cron` check-in | 0 temuan |
| **V8** | Rujukan versi konsisten | Grep `ERD v5.1`, `GUIDELINES.md (v5.1)`, `Schema v5.1` di `docs/` | 0 tersisa (kecuali konteks historis yang dinyatakan) |
| **V9** | Diff bersih | `git diff --stat` | hanya 4 berkas `docs/` (+ `plan/113_*`) |

> **Jujur soal batas:** nol perubahan PHP → `php -l`, migrasi, dan HTTP smoke
> **tidak relevan** dan **tidak** diklaim dijalankan. Gate i18n juga **tidak**
> dijalankan (lihat §7). Ini harus dinyatakan apa adanya di ringkasan.

---

## 10. Risiko & Mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R1 | Menulis "kebenaran" yang juga keliru (dokumen baru ikut usang) | Dokumen tetap menyesatkan | Setiap klaim punya bukti `file:line` (§2); V1–V6 menahan |
| R2 | Relokasi blok ROADMAP menghapus/mengubah teks | Kehilangan bukti historis | CUT/PASTE **byte-identik**; verifikasi `git diff` hanya memperlihatkan perpindahan blok pada V9 |
| R3 | Scope creep ke UI plan/95–105 (maintenance page, kartu WhatsApp) yang juga belum terdokumentasi | Diff membesar, review berat | §7 + §8 non-goals; hanya 4 poin + rujukan versi (D-C) |
| R4 | Sempat salah menomori kartu admin ("Card 7") | Dokumen bertentangan dengan kode | Keputusan **D-B**: tulis **Card 6** sesuai `settings.php:442` |
| R5 | Angka/fitur berubah lagi pasca-dokumen | Dokumen cepat usang | Selalu rujuk sumber otoritatif (`Checkin_model::BASE_MAX`/`MAX_MAX`, `database.sql`) bukan hanya salinan angka |

---

## 11. Urutan Eksekusi (setelah approval)

1. **E0 —** tulis `plan/113_SYNC_CHECKIN_DOCS_PLAN.md` (isi = dokumen ini,
   apa adanya). ← **selesai pada ronde ini**
2. **E1 —** branch baru `docs/113-sync-checkin-ground-truth` (roadmap rule 4).
3. **E2 —** `docs/2_ERD.md` → **v5.2** (§3). Paling mekanis & terverifikasi →
   jadi baseline.
4. **E3 —** `docs/4_UI_UX_GUIDELINES.md` → **v5.2** (§4).
5. **E4 —** `docs/1_PRD.md` poles §4.F + 3 koreksi rujukan (§5).
6. **E5 —** `docs/3_ROADMAP.md` relokasi blok plan/112 + poles + header +
   1 koreksi rujukan (§6).
7. **E6 —** jalankan **V1–V9** (§9) dan catat bukti per perintah.
8. **E7 —** tulis `plan/113_SYNC_CHECKIN_DOCS_SUMMARY.md` (diff nyata + hasil
   verifikasi + yang sengaja tidak dikerjakan).
9. **E8 —** commit (pesan bahasa Indonesia, konvensi repo), tautkan keputusan
   `dec-c38d618a49de30aa`.

> **Batas ronde ini:** hanya **E0**. E1–E8 **menunggu instruksi lanjutan** dari
> pemilik repositori.

---

## 12. Definition of Done (plan/113)

1. `docs/2_ERD.md` **v5.2** memuat: 2 kolom `users` (§0 + §2), 4 kunci
   `checkin_*` + ambang validasi + fallback `checkin_rewards.php` (§4), konvensi
   `CHK-{user_id}-{Ymd}` di `wallet_ledger` (§3), invariant C1–C5 (§7).
2. `docs/4_UI_UX_GUIDELINES.md` **v5.2** memuat §5.**I** (widget `.hm112-card`,
   lokasi antara hero & kartu identitas, hierarki, state tombol, scoped CSS +
   Font Awesome, fail-closed) dan §8.**D** (kartu **Card 6**, layout input,
   dropdown `reset`/`continue`, error inline blocking, repopulasi), plus §2 & §7
   disesuaikan.
3. `docs/1_PRD.md` §4.F menjelaskan **eksplisit** kontrak AJAX
   (`POST /checkin/claim`, rate limit 5/60, HTTP code per `code`, envelope
   `api_*`, anti-double-claim via 1062→`already_claimed`) dan semantik `continue`
   = **N+1 (gap dibekukan, hari terlewat tidak dihitung)**.
4. `docs/3_ROADMAP.md` mencatat plan/112 **lengkap dan berurutan** (blok di akhir
   log milestone) + catatan header plan/113.
5. Rujukan versi lintas-dokumen konsisten (`ERD v5.2`, `UI/UX v5.2`).
6. Nol perubahan pada `application/**`, `system/**`, skema, seed, route,
   migrasi, i18n.
7. V1–V9 lulus dengan bukti perintah + keluaran apa adanya; **tidak** ada klaim
   test suite (repo ini tidak punya test suite).
8. `git diff --stat` akhir hanya memperlihatkan 4 berkas `docs/` + 2 berkas
   `plan/113_*` — tanpa sisa scratch.
