# PLAN 121 — LUCKY SPIN WHEEL (REFERRAL REWARD SYSTEM) — SUMMARY EKSEKUSI

- **Branch:** `feat/121-lucky-spin-wheel`
- **Status:** **SELESAI DIEKSEKUSI (P0–P6)** — seluruh bukti di §6 dijalankan pada
  DB sekali-pakai (MariaDB 12.3.2) + `php -S 127.0.0.1:8080`, bukan pada `db_webtable`.
- **Basis keputusan owner:** `dec-016e333a2e77bd9e` (D1 data model · D2 penyimpanan
  konfigurasi · D3 segmen Rp 0) + `dec-84830f31a30fba50` (D4 tanpa backfill)
- **Tipe perubahan:** fitur baru — 1 kolom `users`, **2 tabel baru**, 2 key
  `system_settings`, 1 fallback config, 1 helper, 1 model, 1 controller member,
  1 halaman admin (+2 metode privat Admin), 2 view, 3 route, +36 key kamus,
  1 migration script, sinkronisasi `docs/` + `AGENTS.md`.
- **Nol perubahan:** `system/**`, `application/core/MY_Controller.php`,
  `application/models/Wallet_model.php`, `views/templates/header.php`,
  `views/templates/bottom_nav.php`, `scripts/reconcile_balances.php`,
  `Admin::settings()`; tanpa dependency Composer baru.

---

## 1. Ringkasan hasil

| Area | Hasil |
|---|---|
| Tiket | 1 tiket per downline L1 **seumur hidup**, hanya dari pembelian **non-trial pertama**; trial & `promoter_reward` tidak menghasilkan tiket; upline tidak wajib aktif (hanya `is_banned`/hilang dilewati) |
| Tiket ≠ uang | `users.spin_tickets` counter; tidak menyentuh `wallet_ledger`, tidak dapat ditarik/dibeli; otoritas = Σ`spin_ticket_grants` − Σ`spin_results`; drift check di migrasi `--verify` |
| Hadiah | Kredit **dompet `main`** via `Wallet_model::credit()`, `transaction_id = 'SPIN-{spin_results.id}'`, deskripsi `Hadiah Lucky Spin` → `ledger_spin` |
| Randomisasi | 100% server: `random_int()` (CSPRNG) + `spin_wheel_pick()` (fungsi murni, `$roll` di luar rentang → `NULL`); klien hanya menerima `slot_index` untuk animasi |
| Config | JSON `[[nominal,bobot],…]` di `system_settings` + fallback `application/config/spin_wheel.php`; ambang di `application/helpers/spin_wheel_helper.php` (satu sumber: validator admin + CLI) |
| Admin | `/admin/lucky-spin` (toggle, editor segmen, peluang + EV live, 20 putaran terakhir), persist atomik + audit `admin_update_spin_wheel` |
| i18n | **669/669** key (EN≡ID 1:1), +36 key; kedua gate audit **exit 0** |
| Migrasi | `scripts/migrate_121_lucky_spin.php` — 5 fase, `--dry-run`/`--apply`/`--verify`, idempotent (re-apply = no-op), **tanpa backfill** (D4) |

---

## 2. Inventaris file

**Baru (8)**

| Path | Isi |
|---|---|
| `application/models/Spin_model.php` | `get_config()`, `validate_settings()`, `get_summary()`, `award_ticket_for_first_paid_rental()` (caller-TX participant), `spin()` (TX uang). Penulis tunggal `users.spin_tickets` + 2 tabel event |
| `application/helpers/spin_wheel_helper.php` | `spin_wheel_limits/int_token/segments_parse/segments_valid/segments_encode/total_weight/pick/odds_bp/expected_value` — murni, `function_exists()`-guarded, **tanpa prosa** (gate R2) |
| `application/config/spin_wheel.php` | Fallback per-key (`spin_wheel_enabled`, `spin_wheel_segments`) |
| `application/controllers/Rewards.php` | `index()` (GET halaman) + `spin()` (POST+AJAX, rate limit, `code→lang`) |
| `application/views/rewards/index.php` | Roda `conic-gradient`, animasi transform-only, panel hasil, how-to 3 langkah, riwayat 10 putaran, state kosong/fail-closed |
| `application/views/admin/lucky_spin.php` | Toggle + editor segmen + peluang/EV live + 20 putaran terakhir (Indonesia) |
| `scripts/migrate_121_lucky_spin.php` | Migrasi idempoten + verifikasi (tipe kolom, tabel/index/FK, key parseable, drift) |
| `plan/121_LUCKY_SPIN_WHEEL_PLAN.md`, `plan/121_LUCKY_SPIN_WHEEL_SUMMARY.md` | Plan (disetujui) + ringkasan ini |

**Diubah (13)**

| Path | Perubahan |
|---|---|
| `database.sql` | `users.spin_tickets` + 2 `CREATE TABLE IF NOT EXISTS` (`spin_ticket_grants`, `spin_results`) + 2 `INSERT IGNORE` key + blok catatan migrasi live plan/121 di ekor file |
| `database_seed.sql` | 2 key seed (`spin_wheel_enabled`, `spin_wheel_segments`) |
| `application/config/autoload.php` | Helper `spin_wheel` |
| `application/config/routes.php` | `lucky-spin`, `lucky-spin/spin`, `admin/lucky-spin` |
| `application/models/Rental_model.php` | Hook **langkah 7b** + metode privat `_award_spin_ticket()` |
| `application/models/Admin_model.php` | `get_spin_overview()` (read-only) |
| `application/controllers/Admin.php` | `lucky_spin()` (GET+POST) + `_spin_rows_from_input()` + `_spin_rows_from_segments()` |
| `application/controllers/Home.php` | Muat `Spin_model` + inject `spin` summary |
| `application/views/home/index.php` | Kartu inline Lucky Spin + FAB (`bottom-20 z-40`, lapisan `inset-x-0 max-w-[480px]`) |
| `application/views/admin/templates/sidebar.php` | Entri `Lucky Spin` |
| `application/helpers/i18n_helper.php` | Pola `ledger_spin` di `i18n_ledger_description()` |
| `application/language/{english,indonesian}/app_lang.php` | +36 key masing-masing (669/669) |
| `docs/{1_PRD,2_ERD,3_ROADMAP,4_UI_UX_GUIDELINES}.md`, `AGENTS.md` | Sinkronisasi (lihat §5) |

---

## 3. Skema (ringkas)

```sql
-- users
`spin_tickets` INT UNSIGNED NOT NULL DEFAULT 0            -- counter tiket (bukan uang)

-- tabel baru 1: guard lifetime
CREATE TABLE `spin_ticket_grants` (
  id, upline_id, downline_id, rental_id NULL, created_at,
  UNIQUE `uk_spin_grant_downline` (downline_id),           -- SP2
  INDEX `idx_spin_grant_upline` (upline_id, created_at),
  FK upline/downline → users(id) RESTRICT, FK rental_id → user_rentals(id) SET NULL
);

-- tabel baru 2: jejak audit putaran
CREATE TABLE `spin_results` (
  id, user_id, slot_index, prize_amount, weight_snapshot, total_weight,
  transaction_id VARCHAR(50) NULL, created_at,
  UNIQUE `uk_spin_tx` (transaction_id),
  INDEX `idx_spin_user_created` (user_id, created_at),
  FK user_id → users(id) RESTRICT
);

-- system_settings (seed)
('spin_wheel_enabled', '1'),
('spin_wheel_segments', '[[5000,30],[10000,25],[25000,18],[50000,10],[100000,6],[150000,3],[250000,2],[500000,1]]');
```

Default seeder: 8 segmen, total bobot **95**, **expected value ≈ Rp 35.789/tiket**
(`Σ(nominal×bobot)/Σbobot` — dihitung server, tampil di panel admin).

---

## 4. Invarian (terdaftar di `docs/2_ERD.md` §7)

**SP1** satu jalur kredit (`Wallet_model::credit()` dompet `main`) · **SP2** 1 tiket
per downline seumur hidup (3 lapis: UNIQUE + pre-check terserialisasi + gate
"pembelian berbayar pertama") · **SP3** hanya non-trial · **SP4** zero-trust ·
**SP5** CSPRNG + fungsi murni · **SP6** tiket bukan uang (cache + drift check) ·
**SP7** fail-closed dua sisi · **SP8** integer IDR + tanpa angka di kamus ·
**SP9** pemisahan bahasa member/admin · **SP10** `prefers-reduced-motion` ·
**SP11** tanpa perubahan inti · **SP12** atomisitas grant (rollback penuh).

**Catatan implementasi SP3/SP2:** gate "pertama kali" memakai
`COUNT(user_rentals JOIN gpu_products WHERE is_trial = 0 AND source <> 'promoter_reward') = 1`
di dalam TX setelah kontrak dibuat. Konsekuensi yang **disengaja**: downline yang
sudah membeli sebelum rilis **tidak** menghasilkan tiket pada pembelian
berikutnya (menegakkan D4 tanpa backfill implisit).

---

## 5. i18n & dokumentasi

- Kamus: **633 → 669** key di kedua idiom (+36). Key baru: `spin_*` (28),
  `js_spin_network`, `ledger_spin`, `notif_spin_ticket_title/_body`,
  `notif_spin_win_title/_body`.
- `spin_ok_title` **dihapus** dari rencana awal karena nilainya identik EN≡ID
  ("Lucky Spin") dan melanggar gate **P5** — pesan sukses memakai
  `spin_ok_message`. Total key karena itu 669 (bukan 673 seperti estimasi plan).
- Pola ledger baru: deskripsi kanonik `Hadiah Lucky Spin` →
  `i18n_ledger_description()` → `ledger_spin`.
- Dokumen tersinkron: `docs/1_PRD.md` §4 (blok "Lucky Spin Wheel (plan/121)"),
  `docs/2_ERD.md` **v5.6** (kolom + 2 tabel + inventory **16 tabel** + SP1–SP12),
  `docs/3_ROADMAP.md` (entri **✅ COMPLETED (plan/121)**),
  `docs/4_UI_UX_GUIDELINES.md` §5.J (roda member) + §8.E (panel admin),
  `AGENTS.md` (bullet Notes plan/121, hitungan kamus 669, inventory 16 tabel,
  daftar fallback config).

---

## 6. Matriks verifikasi — bukti yang BENAR-BENAR dijalankan

Lingkungan: PHP 8.3.6, MariaDB 12.3.2, `php -S 127.0.0.1:8080`, DB sekali-pakai
`test_spin121` (dibangun dari `database.sql` + `database_seed.sql`), sesi login
nyata lewat `/login` (CAPTCHA dipecahkan dengan membaca file session CI3) dan
`/control-panel` (admin).

| # | Aksi | Hasil teramati |
|---|---|---|
| V1 | `php -l` pada **18** file PHP baru/diubah | `No syntax errors detected` ×18 |
| V2 | `php scripts/audit_i18n_parity.php` | `EN keys : 669` · `ID keys : 669` · `Paritas : 1:1 OK` · identik-di-luar-allowlist **0** · P3 **0** · P6 **0** · P3b **0** → `[OK] … LULUS` (**exit 0**) |
| V3 | `php scripts/audit_i18n_hardcoded.php` (log runtime dibersihkan dulu) | `File dipindai : 79` · `Total temuan : 0` → `[OK] 0 temuan` (**exit 0**) |
| V4a | `migrate_121 --dry-run` pada DB baru (skema sudah lengkap) | semua langkah `sudah ada — dilewati`, tanpa tulisan |
| V4b | `migrate_121 --apply` pada DB **pra-121** (`git show HEAD:database.sql`) | kolom `ditambahkan`, `spin_ticket_grants`/`spin_results` `dibuat`, 2 key `ditambahkan`, verifikasi OK (**exit 0**) |
| V4c | `migrate_121 --apply` **diulang** (idempotensi) | semuanya `sudah ada (dibiarkan)` (**exit 0**) |
| V4d | `migrate_121 --verify` pada DB uji **berisi data** | `kolom … OK` · 2 tabel + index/FK `OK` · 2 key `OK` · `drift counter tiket OK` (**exit 0**) |
| V5 | Probe helper `/tmp` (dihapus setelah uji) | 32 asersi lolos: batas `pick` (`roll=1`→0, `roll=total`→terakhir, `0`/`total+1`→`NULL`), parse/valid/encode round-trip + payload rusak (`[[0,10]]`, `[[1.5,2]]`, tuple 1/3 elemen, 13 segmen), `Σ odds_bp = 10000`, EV integer, **200k undian** CSPRNG: 41,15% / 34,05% / 24,80% vs target 41,09% / 34,25% / 24,66% |
| V6a | `GET /lucky-spin` tanpa sesi | `307 → /login` |
| V6b | `GET /lucky-spin` member (0 tiket) | `200`; roda dirender (`id="spin-wheel"`, `conic-gradient`), tombol `data-has-ticket="0"`, label `No Ticket Yet` + CTA `Invite a Downline` |
| V6c | `POST /lucky-spin/spin` (0 tiket) | `200 {"success":false,"code":"no_tickets",…}` + `summary` segar, **tanpa** baris ledger |
| V6f-1 | Downline (user 12) membeli **trial** (produk 9) | `303`; `spin_ticket_grants=0`, upline `spin_tickets=0` |
| V6f-2 | Downline membeli **berbayar pertama** (produk 1) | `303`; `grants=1` (`upline_id=7, downline_id=12, rental_id=18`), upline `spin_tickets=1`, notifikasi `notif_spin_ticket` masuk |
| V6f-3 | Downline membeli **berbayar kedua** | `303`; `grants` **tetap 1**, `spin_tickets` **tetap 1**, `drift_rows=0` |
| V6d | Upline `POST /lucky-spin/spin` (1 tiket) | `200 {"success":true,"code":"ok","slot_index":1,"prize":10000,"transaction_id":"SPIN-1","tickets_left":0,"new_balance":190000}`; ledger `SPIN-1` credit `main` 10.000 (saldo otoritatif 180.000 → **190.000**); `spin_tickets` 1→0; baris `spin_results` (`slot_index=1`, `weight_snapshot=25`, `total_weight=95`, `transaction_id` terisi); `drift_rows=0` |
| V6e | Double-submit paralel (1 tiket) | satu `success` (`SPIN-2`, prize 5.000) + satu `no_tickets`; `spins=2`, `spin_ledger_rows=2`, Σ=15.000, `spin_tickets=0` |
| V6g | `spin_wheel_enabled='0'` | halaman: roda **tidak** dirender, muncul `Lucky Spin is currently disabled.`; `POST` → `403 {"code":"disabled"}` |
| V6h | `spin_wheel_segments='[[0,10],[5000,20]]'` (nominal 0) | nilai dinamis ditolak → **fallback per-key** dipakai (roda tetap valid) + `log_message('error')` tercatat (3 baris log) — perilaku fail-safe plan/112/119 |
| V6h-2 | Segmen dinamis **dan** fallback sama-sama rusak | halaman: `The prize wheel is not configured yet.`; `POST` → `403 {"code":"not_configured"}` |
| V6i | Admin simpan segmen valid (3 baris) | `303`; tersimpan kanonik `[[20000,50],[50000,30],[100000,20]]`; audit `admin_update_spin_wheel` `{keys:[spin_wheel_segments], before:{…}, after:{…}}` |
| V6i-b | Resubmit identik | `303`; audit berikutnya `{keys:[],before:[],after:[]}` (no-op, bukan perubahan senyap) |
| V6j | Admin simpan **invalid** (nominal 0 + 1 segmen) | `303` + flashdata error; pesan Indonesia: `Baris 1: nominal hadiah harus angka bulat 1–100.000.000 (IDR).` / `Roda harus memiliki minimal 2 segmen hadiah.`; **DB tidak berubah**, **0** baris audit |
| V6k | 6× `POST` beruntun (rate limit 5/60) | `200 ×5` lalu **`429`** (`code: too_many_attempts`, `retry_after`) |
| V6l | `POST` tanpa token CSRF | **`403`** + envelope JSON (`MY_Exceptions` → bukan HTML) |
| V6m | `GET /home` (member) | kartu Lucky Spin + **FAB** dirender (`bottom-20 z-40`, 2 tautan `/lucky-spin`) |
| V6n | `GET /wallet` (member) | deskripsi ledger ter-i18n: `Lucky Spin prize` (EN) tampil 4× |
| V6o | `GET /lang/switch/id` → `GET /lucky-spin` | idiom ID aktif: `Roda Lucky Spin`, `Tiket Spin`, `Cara mendapat tiket`, `Riwayat Putaran` |
| V7 | Uji statis UI (360/480 px, reduced-motion) | CSS: `@media (prefers-reduced-motion: reduce) { .spin-wheel { transition: none } }`; FAB di lapisan `inset-x-0 bottom-20 z-40` + `max-w-[480px]` → tidak menutupi `bottom-nav` (`z-50`, 64 px); toast tetap `z-[60]`; animasi hanya `transform` |

**Higiene:** seluruh DB sekali-pakai (`test_spin121`, `test_spin121_old`,
`test_synapse_121`, `test_synapse_121_old`) di-drop setelah uji; seluruh harness
(`/tmp/syn_login.php`, `/tmp/syn_admin_login.php`, `/tmp/syn_req.php`,
`/tmp/syn_admin_post.php`, `/tmp/load_sql.php`, `/tmp/probe_spin_wheel.php`)
dihapus; server `php -S` dihentikan; `application/logs/*.php` dibersihkan.

---

## 7. Catatan, deviasi & tindak lanjut

1. **Gate "pembelian berbayar pertama" ditambahkan** saat eksekusi (tidak eksplisit
   di §4.1 plan): tanpa gate ini, downline yang sudah membeli **sebelum** rilis akan
   menerima tiket pada pembelian **berikutnya** — itu backfill implisit dan
   bertentangan dengan D4. Lihat §4 (catatan SP2/SP3).
2. **`not_configured` hanya terjadi bila dinamis + fallback sama-sama rusak.**
   Nilai dinamis yang rusak (mis. `[[0,10]]`) → **fallback per-key** + log
   (konsisten plan/112/119). Keduanya sudah diuji (V6h, V6h-2).
3. **Jumlah key 669** (bukan 673 pada plan): `spin_ok_title` dibatalkan karena
   melanggar gate P5 (nilai identik EN≡ID) dan beberapa key usulan tidak dipakai
   (mis. `spin_hero_title`, `spin_empty_title`) — kamus dijaga tanpa key mati.
4. **Expected value default ≈ Rp 35.789/tiket** (bukan ±15 rb seperti estimasi
   plan): perhitungan `Σ(nominal×bobot)/Σbobot` di atas seed 8 segmen. Panel admin
   menampilkan angka ini secara live agar owner dapat menyetel ekonomi roda.
5. **Follow-up opsional (P2, belum dikerjakan):** flag `--repair` drift di
   migrasi, fixture `scripts/seed_spin_test_account.php`, ekspor CSV putaran,
   cap spin harian, tier multi-tiket per downline.
6. **`scripts/expire_rentals.php` & `seed_database.php`** tetap membawa bug loader
   `$db['default']` (catatan AGENTS.md) — di luar scope plan/121; migrasi 121
   memakai pola yang sudah dibetulkan (`$active_group → default → grup pertama`).

---

## 8. Checklist rilis

1. **Backup DB** (`backups/`, gitignored).
2. `php scripts/migrate_121_lucky_spin.php --dry-run` → tinjau rencana.
3. `php scripts/migrate_121_lucky_spin.php --apply` → `--verify` harus exit 0.
4. Deploy kode plan/121 (urutan ini WAJIB: kode membaca kolom/tabel/key baru).
5. Set `spin_wheel_segments` & `spin_wheel_enabled` dari `/admin/lucky-spin`
   (default seed = contoh; tinjau **expected value** sebelum mengaktifkan).
6. Smoke test: login member → dashboard menampilkan kartu/FAB → `/lucky-spin`
   (0 tiket = tombol disabled) → admin toggle OFF → endpoint 403.
7. Pantau `application/logs/` untuk `Spin_model: … tidak valid` (indikasi setelan
   rusak) dan tabel `spin_results` untuk distribusi hadiah nyata.
