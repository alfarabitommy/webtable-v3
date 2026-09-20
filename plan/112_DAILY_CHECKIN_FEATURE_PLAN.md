# PLAN 112 — FITUR ABSENSI HARIAN (DAILY CHECK-IN)

**Status:** DISETUJUI — menunggu eksekusi langkah 1..9
**Tanggal:** 2026-09-20
**Ruang lingkup:** member dashboard (widget) + konfigurasi admin (`/admin/settings`) + jalur uang (kredit `wallet_ledger`)
**Keputusan owner:** `dec-8cbc460b0e5c3d10`
**Non-goal tunggal dalam dokumen ini:** tidak ada perubahan di bawah `system/`.

---

## 0. Ringkasan eksekutif

Member menerima bonus harian dengan pengali berjenjang per hari beruntun
(`bonus = min(base × hari, cap)`), diklaim lewat satu tombol di dashboard.
Empat kunci `system_settings` mengendalikan fitur (on/off, base, cap, kebijakan
streak saat bolong). Setiap klaim = **satu baris kredit immutable** di
`wallet_ledger` lewat choke-point tunggal `Wallet_model::credit()`; tidak ada
`UPDATE users SET balance` di luar jalur itu (invarian C4/M8).

Fitur menyentuh: 2 kolom `users`, 4 kunci `system_settings`, 1 model baru,
1 controller baru, 1 config fallback baru, 1 skrip migrasi baru, 1 route,
1 widget view, 1 kartu setelan admin, 1 pola renderer ledger, dan 18 kunci kamus.

### 0.1 Keputusan yang dikonfirmasi owner

| Kode | Keputusan | Nilai |
|---|---|---|
| D-A | Cakupan akses | **Semua member aktif** (login, tidak banned). Tanpa gate sewa aktif / e-wallet terikat. |
| D-B | Semantik policy `continue` | **Lanjut ke N+1** — gap tidak mereset dan hari terlewat tidak dihitung: streak 5 → bolong → klaim berikutnya = hari ke-6. |
| D-C | Ikon | **Font Awesome** (`fas fa-*`) — standar repo, nol CDN/aset baru. Lucide ditolak. |

---

## 1. Fakta repositori yang diverifikasi (dasar desain)

| # | Fakta | Bukti |
|---|---|---|
| F1 | `users` belum punya kolom absensi; pola kolom "waktu klaim terakhir" sudah ada | `database.sql:11-34` (`last_wage_claimed_at DATETIME`) |
| F2 | Choke-point uang tunggal: `credit()`/`debit()` → `_post()`; `UPDATE users SET balance = balance ± ?` relatif; guard `affected_rows() === 1` | `application/models/Wallet_model.php:682-753` |
| F3 | Idempotensi tingkat DB tersedia: `uk_wallet_ledger_user_tx_type (user_id, transaction_id, type)` | `database.sql:199` |
| F4 | Konvensi ID ledger deterministik per-periode: `ROI-{rental}-D{n}`, `WAGE-{user}-Y{o}W{W}`, `RBT-{rental}-L{tier}` | `Rental_model.php:358,659`, `User_model.php:458` |
| F5 | Pola klaim model TX: `trans_begin` → `SELECT … FOR UPDATE` (anchor) → guard tanggal/banned → **UPDATE kondisional** + `affected_rows()` → `credit()` → commit | `User_model::claim_wage()` `User_model.php:362-494` |
| F6 | Pola endpoint AJAX member: POST-only + `is_ajax_request()` + sesi + `Rate_limit_model::check/hit` + `api_success()/api_error()` + key legacy di root | `Team::claim_wage()` `Team.php:166-241` |
| F7 | `api` + `ratelimit` **tidak** autoloaded → loader lokal di konstruktor controller | `autoload.php:117`, `Team.php:6-14` |
| F8 | `db_debug = (ENVIRONMENT !== 'production')`; query gagal **di dalam TX** → TX di-rollback lalu `show_error` (halaman error, bukan JSON) | `database.php:85`, `system/database/DB_driver.php:653-696` |
| F9 | Karena F8, jalur yang memanfaatkan duplikat-key 1062 wajib mematikan `db_debug` secara lokal (save/restore) | `Auth.php:229-238`, `Admin.php:1558-1573` |
| F10 | Deskripsi ledger kanonik diterjemahkan **saat dibaca** via pola regex di helper (bukan mutasi baris) | `i18n_helper.php:406-475` |
| F11 | Setelan admin = satu endpoint `/admin/settings` (GET render + POST), **all-or-nothing**, snapshot before→after, audit `admin_update_settings`, persist atomik `Admin_model::update_system_settings($final, $audit)` | `Admin.php:375-591`, `Admin_model.php:1227-1238` |
| F12 | Kontrak validator setelan: `{ok, errors[], notices[], field_errors[], values[]}` | `Wallet_model::validate_financial_settings()` `Wallet_model.php:258-384` |
| F13 | Repopulasi form saat validasi gagal via flashdata `settings_form_state` (bertahan satu request); view meng-escape | `Admin.php:530-537,657-682`; `views/admin/settings.php:31-74` |
| F14 | View setelan admin = **satu file** `application/views/admin/settings.php` (bukan folder); form utama `form_open('admin/settings')` mencakup Kartu 1–5; tombol submit di `:422-433` | `views/admin/settings.php:101,384-433` |
| F15 | Dashboard member: titik sisip widget berada di antara hero (`:168-195`) dan kartu identitas (`:197`) | `views/home/index.php:1,166-232` |
| F16 | Konvensi CSS view-local: prefix scoped (`hm99-*`), hanya warna/bg/border/shadow/animation; radius & spacing tetap utility Tailwind; tema via `html.dark .x`; blok `prefers-reduced-motion` | `views/home/index.php:3-166` |
| F17 | Kamus 602/602; gate `audit_i18n_parity.php` (P1/P3/P5/P6) + `audit_i18n_hardcoded.php`; uang/angka dilarang di kamus (P3/L6); nilai EN≡ID dilarang di luar allowlist (P5) | `scripts/audit_i18n_parity.php:63-172` |
| F18 | Kontrak skrip migrasi: `--dry-run` (default) / `--apply` / `--verify`, pre-flight `information_schema`, `INSERT IGNORE`, tamper-detection, exit 0/1/2 | `scripts/migrate_105_wa_group_link.php` |
| F19 | `wallet_ledger` = ledger append-only immutable; `type` hanya `ENUM('credit','debit')` | `database.sql:190-204` |
| F20 | Preseden fallback config terpisah untuk setelan domain | `application/config/rebate_commission.php`, `withdrawal_fees.php` |
| F21 | Pola notifikasi berkunci (bila kelak dipakai) | `Notification_model::insert_keyed($uid, 'notif_wage', [...], 'commission')` `Team.php:216-221` |

---

## 2. Keputusan desain (D1–D9)

| # | Keputusan | Alasan / trade-off |
|---|---|---|
| **D1** | Status absensi = **2 kolom di `users`** (`checkin_streak`, `checkin_last_date`), **tanpa tabel baru** | Satu baris per user = `SELECT … FOR UPDATE` yang sudah menjadi anchor serialisasi klaim (F5). Alternatif `user_checkins` ditolak: histori harian **sudah** ada di `wallet_ledger` (`CHK-%`) → tabel kedua = sumber kebenaran ganda. |
| **D2** | **Tanpa kolom total** (`checkin_total_claims`) | Lifetime diturunkan dari ledger: `SELECT COUNT(*) FROM wallet_ledger WHERE user_id = ? AND transaction_id LIKE 'CHK-%'` (terlayani `idx_user_id`). |
| **D3** | **Tanpa index baru** | Semua baca jalur uang = `WHERE id = ?` (PK). Hitung lifetime memakai prefix `user_id`. Index `(checkin_last_date)` hanya dibutuhkan laporan admin — di luar scope (§8). |
| **D4** | `checkin_last_date` bertipe **`DATE`** | Pembanding harian murni; jam tidak relevan. Selaras `last_wage_claimed_at DATETIME` yang sudah dipakai untuk guard periode. |
| **D5** | Default seed **`checkin_enabled = '1'`** (ON) | Requirement mendefinisikan fitur aktif dengan default base 50 / cap 10 000 (`rebate_enabled` juga di-seed `'1'`). Bisa dimatikan instan dari admin (widget hilang + endpoint fail-closed). **Flippable**: ubah seed ke `'0'` bila owner ingin fail-closed sejak awal. |
| **D6** | `transaction_id` = **`CHK-{user_id}-{Ymd}`** (mis. `CHK-12-20260920`) | Memenuhi F4 dan mengaktifkan jaminan UNIQUE F3 → **satu kredit per user per hari di tingkat DB**. Info "hari ke-N" hidup di `description` (audit), bukan di ID. |
| **D7** | Penanda kredit = **prefix `CHK-` + description kanonik**, **bukan** nilai ENUM `type` baru | Requirement menyebut `checkin_reward`. `type` adalah `ENUM('credit','debit')` pada tabel append-only immutable (F19): menambah nilai ENUM = `ALTER` pada tabel uang + menyentuh setiap pembaca jalur uang. Prefix + description memberi auditabilitas identik dengan **nol risiko skema**. **Deviasi sadar dari redaksi requirement**, dinyatakan terbuka — bukan substitusi senyap. |
| **D8** | `get_status($user_id)` membaca barisnya sendiri (1 PK lookup) | Model self-contained (dipakai `Home` dan endpoint klaim) tanpa bergantung pada bentuk `$user` pemanggil. Biaya 1 PK read, dapat diabaikan. |
| **D9** | Fallback setelan di `application/config/checkin_rewards.php` | Preseden F20; satu sumber default untuk model, view, dan CLI `--verify`. |

---

## 3. Perubahan skema

### 3.1 `database.sql` — `CREATE TABLE users` (blok `:11-34`)

```sql
  `last_wage_claimed_at` DATETIME NULL DEFAULT NULL,
  -- plan/112: absensi harian (daily check-in). `checkin_last_date` = tanggal WIB
  -- klaim terakhir (DATE, otoritas harian); `checkin_streak` = hari beruntun
  -- terakhir yang DIBAYAR (0 = belum pernah). Otoritas tanggal = PHP WIB, bukan
  -- MySQL NOW()/CURDATE() (invarian M2/M3). Histori klaim per hari ada di
  -- `wallet_ledger` dengan `transaction_id` = 'CHK-{user_id}-{Ymd}'.
  `checkin_streak`    INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_wage_claimed_at`,
  `checkin_last_date` DATE         NULL DEFAULT NULL AFTER `checkin_streak`,
```

### 3.2 `database.sql` — seed `system_settings` (blok `:359-393`)

```sql
-- plan/112: Absensi Harian (daily check-in). `checkin_enabled` = gerbang
-- fail-closed (0 = widget disembunyikan DAN endpoint klaim menolak).
-- `checkin_streak_policy`: 'reset' = bolong sehari → mulai lagi dari hari 1;
-- 'continue' = lanjut ke N+1 (gap tidak mereset).
('checkin_enabled', '1'),
('checkin_base_reward', '50'),
('checkin_max_reward', '10000'),
('checkin_streak_policy', 'reset');
```

### 3.3 `database.sql` — blok "MIGRASI LIVE" baru (pola `:485-560`)

Catatan one-time untuk DB yang sudah berjalan (referensi SQL yang dijalankan tool):

```sql
--   php scripts/migrate_112_daily_checkin.php --dry-run   # inspeksi, tanpa tulis
--   php scripts/migrate_112_daily_checkin.php --apply     # DDL + seed + verify
--   php scripts/migrate_112_daily_checkin.php --verify    # read-only; exit 2 bila drift
--
--   1) ALTER TABLE `users`
--        ADD COLUMN `checkin_streak`    INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_wage_claimed_at`,
--        ADD COLUMN `checkin_last_date` DATE         NULL DEFAULT NULL AFTER `checkin_streak`;
--
--   2) INSERT IGNORE INTO `system_settings` (`key_name`,`key_value`) VALUES
--        ('checkin_enabled','1'),('checkin_base_reward','50'),
--        ('checkin_max_reward','10000'),('checkin_streak_policy','reset');
--
--   3) Verifikasi (harapan: 2 kolom ada, 4 baris ada, 0 nilai rusak)
--        SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--          FROM information_schema.COLUMNS
--         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
--           AND COLUMN_NAME IN ('checkin_streak','checkin_last_date');
```

Catatan: `ADD COLUMN` tidak idempoten di MySQL 8 (tanpa `IF NOT EXISTS`); MariaDB
mendukung. Tool CLI memeriksa `information_schema` lebih dulu sehingga aman
dijalankan ulang.

### 3.4 `database_seed.sql` — SECTION `system_settings` (`:291-320`)

Tambahkan 4 baris `INSERT IGNORE` yang sama (dengan blok komentar `-- plan/112: …`)
agar instalasi bersih memperoleh setelan kanonik. **Tidak** ada `ALTER` baru di
section `reconcile_schema` — seeder melewatkan section itu; DDL hanya hidup di
`database.sql` + migrasi (preseden plan/106, lihat `database_seed.sql:86-106`).

### 3.5 `scripts/migrate_112_daily_checkin.php` (baru — kontrak F18)

| Fase | Isi |
|---|---|
| Pre-flight (exit 1) | tabel `users` & `system_settings` ada; kolom `key_name`/`key_value` ada; `key_value` = TEXT; `uk_key_name` UNIQUE |
| DDL | 2× `ADD COLUMN` **dijaga `information_schema.COLUMNS`**; verifikasi `DATA_TYPE`/`IS_NULLABLE`/`COLUMN_DEFAULT` sesudahnya |
| Seed | 4× `INSERT IGNORE` (idempotent; **tidak pernah** menimpa nilai yang sudah diubah admin) |
| Tamper detect | `checkin_enabled ∉ {'0','1'}` / `checkin_streak_policy ∉ {'reset','continue'}` / `checkin_base_reward` atau `checkin_max_reward` bukan integer positif / `base > max` → **exit 2** |
| `--dry-run` (default) | Cetak rencana (kolom mana yang akan ditambah, baris mana yang akan disisipkan) + status saat ini; **nol tulis**; exit 0 |
| `--apply` | DDL + seed, lalu verifikasi internal; exit 2 bila drift, 0 bila bersih. **Re-run = no-op (exit 0)** |
| `--verify` | Read-only: 2 kolom ada dengan tipe/default benar, 4 baris ada, nilai tidak rusak; exit 0/2 |

Pola CLI (identik `migrate_105_wa_group_link.php:36-95`): `define('BASEPATH','cli-migrate-112-runner')`,
`define('ENVIRONMENT','development')`, `date_default_timezone_set('Asia/Jakarta')`,
`mysqli` langsung memakai kredensial dari `application/config/database.php`,
`require_once application/config/checkin_rewards.php` untuk default pembanding,
`SET NAMES utf8mb4` + `SET time_zone = '+07:00'`.

---

## 4. Arsitektur setelan

### 4.1 Kunci `system_settings`

| Key | Nilai sah | Seed | Fallback (`config/checkin_rewards.php`) | Validasi admin | Pemakai |
|---|---|---|---|---|---|
| `checkin_enabled` | `'0'` \| `'1'` | `'1'` | `1` | toggle checkbox (absen `= '1'`) | gerbang UI member **dan** gerbang server klaim (fail-closed, E4) |
| `checkin_base_reward` | integer `^[1-9][0-9]*$`, `1 … 1 000 000` | `'50'` | `50` | input number, wajib | `reward = min(base × hari, max)` |
| `checkin_max_reward` | integer `^[1-9][0-9]*$`, `1 … 10 000 000` | `'10000'` | `10000` | input number, wajib | cap harian keras |
| `checkin_streak_policy` | `'reset'` \| `'continue'` | `'reset'` | `'reset'` | `<select>` | bonus saat gap (D-B) |

**Invarian koherensi:** `1 ≤ base ≤ max`. Bila baris dinamis melanggar (atau
rusak), `Checkin_model::get_config()` mengembalikan **pasangan base+max sekaligus**
ke fallback + `log_message('error', …)` — pola atomik
`wd_min_amount`/`wd_fee_tiers` (`Wallet_model.php:106-123`). Setelan rusak
**tidak pernah** membuat request fatal.

### 4.2 `application/config/checkin_rewards.php` (baru)

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// =====================================================================
// Plan 112 — ABSENSI HARIAN: FALLBACK CONFIG
// =====================================================================
// Sumber dinamis = `system_settings` (dioperasikan admin, di-seed di
// database.sql + database_seed.sql). File ini hanya FALLBACK per-key
// (pola M1/plan/56 & plan/89): dipakai bila baris hilang/tidak valid.
// Semua nilai = INTEGER IDR utuh (M8) — tanpa pecahan.
return [
    'checkin_enabled'       => 1,        // 1 = tampil & dapat diklaim, 0 = mati total (fail-closed)
    'checkin_base_reward'   => 50,       // bonus hari ke-1 (IDR)
    'checkin_max_reward'    => 10000,    // cap harian keras (IDR)
    'checkin_streak_policy' => 'reset',  // 'reset' | 'continue'
];
```

### 4.3 Integrasi controller & view admin

**`Admin::settings()` (`Admin.php:375-591`) — jalur POST:**

1. Raw map baru (pola `:433-444`):

```php
$checkin_raw = [
    'checkin_enabled'       => $this->input->post('checkin_enabled'),
    'checkin_base_reward'   => $this->input->post('checkin_base_reward'),
    'checkin_max_reward'    => $this->input->post('checkin_max_reward'),
    'checkin_streak_policy' => $this->input->post('checkin_streak_policy'),
];
$cv = $this->Checkin_model->validate_checkin_settings($checkin_raw);
if (!$cv['ok']) { $errors = array_merge($errors, $cv['errors']); }
```

2. Gabungkan ke `$final` (`:478`):
   `array_merge($contact, $v['values'], $rv['values'], $cv['values'])`
   → **seluruh mesin yang sudah ada bekerja tanpa perubahan** (F11): all-or-nothing,
   snapshot before→after, audit `admin_update_settings`, persist atomik.
   Audit otomatis memuat `checkin_*` pada `keys`/`before`/`after`.
3. Flash state (`:472`) tetap satu `settings_form_state` → tambahkan 4 kunci di
   `_settings_form_state()` (`:657-682`) sebagai `(string) $this->input->post(...)`
   agar admin tidak kehilangan ketikan saat validasi gagal.
4. GET: `$this->load->model('Checkin_model');` di `:377-379` +
   `$data['checkin'] = $this->Checkin_model->get_config();` di blok `$data` (`:545-577`).
5. Gate metode (`show_404()` untuk selain GET/POST, `:517-521`) tetap utuh.

**View — Kartu 7 di `application/views/admin/settings.php`**, disisipkan **di dalam**
`form_open('admin/settings')`, tepat sebelum blok submit (`:422`), full-width
(`class="t-card p-6 mt-6"`, pola Kartu 5 rebate `:384-420`) sehingga otomatis
mewarisi CSRF + guard `data-guard-submit`:

- Judul: `<i class="fas fa-calendar-check text-amber-500"></i> Absensi Harian (Daily Check-in)`
  — **100% Indonesia (invarian L1, tanpa key i18n)**.
- Toggle: `<input type="checkbox" id="checkin_enabled" name="checkin_enabled" value="1" class="rounded border-slate-300" <?= $checkin_enabled_state ? 'checked' : '' ?>>`.
- Grid 2 kolom: `checkin_base_reward` (number, `min="1" max="1000000" step="1"`,
  `class="t-input … font-mono"`), `checkin_max_reward` (number, `min="1" max="10000000" step="1"`).
- `<select id="checkin_streak_policy" name="checkin_streak_policy" class="t-input …">`:
  opsi `reset` ("Reset ke Hari 1 — hilang sehari, mulai dari awal") &
  `continue` ("Lanjutkan — streak dibekukan, lanjut dari hari terakhir").
- Blok error inline bila ada: pola `$qris_errors` (`:452-461`) dengan
  `$checkin_errors = $error_flat($field_errors['checkin_*'])` (agregasi di header view `:50`).
- Repopulasi via `$state_val($fs, 'checkin_*', …)` (F13) + `htmlspecialchars()`.

---

## 5. Logika backend

### 5.1 `application/models/Checkin_model.php` (baru, `extends CI_Model`)

| Method | Signature | Tanggung jawab |
|---|---|---|
| `get_config` | `(): array{enabled:bool, base:int, max:int, policy:string}` | Cache statis per-request; map `system_settings` di atas fallback config; validasi per-key + koherensi `base ≤ max` (fallback atomik + log). Pola `Wallet_model::get_financial_config()` (`:58-150`). |
| `validate_checkin_settings` | `(array $raw): array{ok:bool, errors:string[], notices:string[], field_errors:array<string,string[]>, values:array<string,string>}` | Kontrak **persis** F12, pesan Indonesia (L1). `enabled` → `'0'/'1'`; `base`/`max` → integer + batas + `base <= max` (pesan: "Bonus hari pertama tidak boleh melebihi batas harian."); `policy` → whitelist; key tak dikenal diabaikan. |
| `get_status` | `(int $user_id): array` | Read-only untuk widget: `SELECT checkin_streak, checkin_last_date FROM users WHERE id = ?`. Mengembalikan `enabled`, `claimed_today`, `streak`, `today_streak`, `today_reward`, `base_reward`, `max_reward`, `policy`, `last_date`, `server_date` (`Y-m-d` WIB), `next_window_ts` (epoch tengah malam WIB berikutnya), `preview` = 7 entri `['day'=>int,'reward'=>int,'is_today'=>bool]`. Saat `enabled = false` → `['enabled'=>false, …angka 0]` (view tidak merender apa pun). |
| `claim` | `(int $user_id): array{success:bool, code:string, message:string, reward:int, streak:int, total_amount:int, transaction_id:string, new_balance:int}` | Jalur uang — detail §5.2. |
| `_streak_next` (private, murni) | `(int $streak, ?string $last_date, string $today, string $policy): ?int` | Tanpa I/O, mudah ditelaah: `null`/kosong → 1; `last === yesterday` → `streak+1`; gap → `policy === 'reset' ? 1 : streak+1`; `last === today` → `null` (batal). |
| `_reward_for` (private, murni) | `(int $day, int $base, int $max): int` | `min($base * $day, $max)` — aritmetika integer (M8), tanpa float. |

### 5.2 State machine `Checkin_model::claim()` — urutan wajib

```
$cfg = get_config()
  └─ !enabled → {success:false, code:'disabled'}              (E4, tanpa TX)

$prev_debug = $this->db->db_debug; $this->db->db_debug = FALSE;   (F8/F9)
try {
  trans_begin()
   1) ANCHOR: SELECT id, checkin_streak, checkin_last_date, is_banned
              FROM users WHERE id = ? FOR UPDATE            (statement DB pertama — C5)
      • baris tidak ada / is_banned = 1 → rollback → {code:'user_unavailable'}
   2) $today = date('Y-m-d'); $yesterday = date('Y-m-d', strtotime('-1 day'))
      • $last === $today  → rollback → {code:'already_claimed'}   (E1)
      • $last >  $today   → rollback → {code:'already_claimed'}   (E2, fail-closed)
      • $new_streak = _streak_next(...); null → {code:'already_claimed'}
   3) $reward = _reward_for($new_streak, base, max); assert $reward >= 1
      • < 1 → rollback → {code:'error'}                           (E5)
   4) UPDATE users SET checkin_streak = ?, checkin_last_date = ?
       WHERE id = ? AND (checkin_last_date IS NULL OR checkin_last_date < ?)
      • affected_rows() !== 1 → rollback → {code:'already_claimed'}   (M4, E7)
   5) Wallet_model::credit($user_id, $reward,
                            'CHK-'.$user_id.'-'.date('Ymd'),
                            'Bonus Absensi Harian Hari ke-'.$new_streak)
      • false → rollback → {code:'error'}                         (F2/F3/E11)
  trans_complete()
  • trans_status() === false → {code:'error'}
  return {success:true, code:'ok', reward, streak:$new_streak,
          transaction_id, total_amount:$reward}
} finally { $this->db->db_debug = $prev_debug; }
```

**Catatan `db_debug` (load-bearing).** `credit()` menyisipkan baris ledger; bila
TX lain sudah menyisipkan `CHK-{user}-{Ymd}` lebih dulu, insert kena 1062. Tanpa
`db_debug = FALSE`, CI3 (non-production) **me-rollback TX lalu `show_error`** →
respons HTML pada jalur AJAX (melanggar M9/P7). Penanganan: simpan/restore
`db_debug` (preseden `Auth.php:229-238`, `Admin.php:1558-1573`), lalu translasi
`$this->db->error()['code'] === 1062` → `{code:'already_claimed'}`. Anchor
`FOR UPDATE` (langkah 1) sudah menutup hampir seluruh race per-user; langkah ini
adalah sabuk pengaman terakhir agar kegagalan tetap berwujud JSON.

**Deskripsi ledger kanonik:** `'Bonus Absensi Harian Hari ke-{N}'` — Bahasa
Indonesia, deterministik, **tanpa nominal** (nominal hidup di kolom `amount`;
kamus tetap bersih dari angka → aman P3/L6).

### 5.3 `application/controllers/Checkin.php` (baru, `extends MY_Controller`)

```php
public function __construct() {                 // pola Team.php:6-14 (F7)
    parent::__construct();                      // maintenance gate + WIB + i18n + guard login
    $this->load->model('Checkin_model');
    $this->load->model('Rate_limit_model');
    $this->load->model('Wallet_model');
    $this->load->helper('ratelimit');
    $this->load->helper('api');
}
```

`claim()` — persis pola `Team::claim_wage()` (F6):

1. bukan `POST` → `show_404()`.
2. bukan AJAX (`is_ajax_request()`) → `show_404()`.
3. sesi kosong → `api_error(lang('home_checkin_err_generic'), 401, [], 'unauthenticated', […])`.
4. Rate limit key `checkin_claim:{user_id}`: `check(5, 60)` →
   `rate_limit_json_response($throttle)`; lalu `hit($rl_key, 60, 5)`.
5. `$result = $this->Checkin_model->claim($user_id);`
6. `$legacy = $result; unset($legacy['success']);`
   `$localized = $this->_message($result); $legacy['message'] = $localized;`
7. `code === 'error'` → `api_error($localized, 500, [], 'error', $legacy)`.
8. Sukses → `new_balance = $this->Wallet_model->get_balance($user_id)` (bukan
   `users.balance` yang basi — C4) + `status = $this->Checkin_model->get_status($user_id)`
   → `api_success([...], lang('home_checkin_ok_claimed'), 200, $legacy)`.
9. Penolakan bisnis → `api_error($localized, 200, [], $result['code'], $legacy)`
   (`disabled` memakai **HTTP 403** — fail-closed eksplisit).

`_message($result)`: pemeta `code → lang()` (pola `Team::_wage_message`):
`ok` / `already_claimed` / `disabled` / `user_unavailable` / `error`.

**Route** (`application/config/routes.php` — wajib eksplisit untuk sub-path
multi-segmen, `translate_uri_dashes = FALSE`):

```php
$route['checkin/claim'] = 'checkin/claim';   // plan/112
```

### 5.4 `application/controllers/Home.php`

`$this->load->model('Checkin_model');` di konstruktor (`:8-10`), lalu di `index()`:

```php
'checkin' => $this->Checkin_model->get_status($user_id),   // plan/112
```

ditambahkan ke array `$data` (`:35-44`). **Tidak ada** perubahan `MY_Controller`
(tidak ada var global baru — widget hanya di dashboard, menghindari query
tambahan pada setiap request member).

---

## 6. UI/UX member — Widget Absensi Harian

**Lokasi:** `application/views/home/index.php`, disisipkan **di antara baris 195 dan
197** (setelah kartu hero, sebelum kartu identitas) — area lipatan atas, tanpa
menggeser struktur lain. Dibungkus `<?php if (!empty($checkin['enabled'])): ?> … <?php endif; ?>`
→ **hilang total saat fitur OFF** (requirement 3).

**Struktur (Tailwind + Font Awesome):**

```
.hm112-card  (u-card rounded-2xl p-5 shadow-sm + gradien indigo→cyan halus)
├─ Header: badge ikon fa-calendar-check (w-11 h-11 rounded-2xl bg-indigo-500/15 border)
│          + label  home_checkin_title  (10px uppercase tracking-wider)
│          + chip streak .hm112-fire  fa-fire  "home_checkin_streak_label: N" (+ home_checkin_day)
├─ Nominal: "Rp " . number_format($reward)  text-2xl font-extrabold  + home_checkin_today_reward
│           + bar progres tipis ke cap ($reward / max) + home_checkin_cap_label
├─ Strip pratinjau 7 hari (server-rendered dari $checkin['preview']): 7 pill .hm112-day
│           (hari + bonus; hari berikutnya di-ring; hari yang kena cap ditandai)
│           + label home_checkin_preview
├─ Baris waktu (font-mono kecil):
│           belum klaim → "home_checkin_window_left HH:MM:SS"
│           sudah klaim → "home_checkin_next_in HH:MM:SS"
├─ Tombol klaim: w-full, indigo solid, fa-hand-holding-usd → home_checkin_claim_btn
│           state sudah klaim: disabled + opacity-60 + fa-circle-check → home_checkin_claimed_btn
└─ Hint kebijakan: home_checkin_hint_reset | home_checkin_hint_continue
```

**Detail implementasi & invarian:**

- **CSS scoped `hm112-*`** ditambahkan ke blok `<style>` yang sudah ada (`:3-166`):
  hanya `color/background/border/box-shadow/animation`; radius & spacing tetap
  utility Tailwind (F16). Tema via `html.dark .hm112-*`; blok
  `prefers-reduced-motion: reduce` menonaktifkan pulse/sheen. **Tanpa**
  `<defs>`/id ganda (pelajaran plan/104).
- **Angka uang tidak pernah masuk kamus (L6/P3):** nominal dirender PHP
  `number_format($x, 0, ',', '.')` dengan prefix `Rp `. Update pasca-klaim di JS
  memakai `Intl.NumberFormat('id-ID', { style:'currency', currency:'IDR', maximumFractionDigits:0 })`.
- **Klaim:** `csrfFetch('<?= site_url('checkin/claim') ?>', { method:'POST', body: new FormData() })`
  (via `templates/csrf_meta.php`); branch pada `d.success` dan `d.code`; teks
  dinamis lewat `window.SYNAPSE_I18N`.
- **Sukses:** toast scoped `#hm112-toast` (`u-toast`, `z-[60]`), tombol →
  state "sudah diklaim", streak & pratinjau di-refresh dari `d.data.status`
  (tanpa reload halaman — widget tetap konsisten dengan sumber server).
- **A11y:** `aria-label` pada tombol, `aria-live="polite"` pada region status,
  target sentuh ≥ 44 px, `disabled` nyata (bukan hanya visual).
- **Mobile-first:** shell `max-w-[480px]`; strip 7 hari `grid grid-cols-7 gap-1`
  dengan label 11 px, tidak overflow pada 360 px.
- **Ikon:** hanya `fas fa-*` (D-C) — nol aset/CDN baru.

---

## 7. i18n

### 7.1 Helper — `application/helpers/i18n_helper.php`

Tambahkan satu pola pada `i18n_ledger_description()` (`:431-450`):

```php
// Bonus absensi harian: "Bonus Absensi Harian Hari ke-7"
'/^Bonus Absensi Harian Hari ke-(\d+)$/u' => 'ledger_checkin',
```

### 7.2 Kamus — 18 kunci baru (602 → ±620; angka final dilaporkan gate)

`application/language/{english,indonesian}/app_lang.php`. Semua nilai EN ≠ ID
(aman P5), tanpa `Rp`/angka (aman P3), tanpa newline/markup (aman P6).

| Key | EN | ID |
|---|---|---|
| `home_checkin_title` | Daily Check-in | Absensi Harian |
| `home_checkin_subtitle` | Log in and claim your bonus every day. | Masuk dan klaim bonus Anda setiap hari. |
| `home_checkin_streak_label` | Day Streak | Rentetan Hari |
| `home_checkin_day` | Day %d | Hari ke-%d |
| `home_checkin_today_reward` | Today's bonus | Bonus hari ini |
| `home_checkin_cap_label` | Daily cap | Batas harian |
| `home_checkin_preview` | Next 7 days | Tujuh hari ke depan |
| `home_checkin_claim_btn` | Claim Bonus | Klaim Bonus |
| `home_checkin_claimed_btn` | Claimed Today | Sudah Diklaim |
| `home_checkin_window_left` | Claim window closes in | Jendela klaim berakhir dalam |
| `home_checkin_next_in` | Next claim opens in | Klaim berikutnya dibuka dalam |
| `home_checkin_hint_reset` | Miss a day and your streak restarts from day 1. | Lewat satu hari, rentetan Anda diulang dari hari 1. |
| `home_checkin_hint_continue` | Miss a day and your streak stays where it is. | Lewat satu hari, rentetan Anda tetap tersimpan. |
| `home_checkin_ok_claimed` | Bonus claimed successfully. | Bonus berhasil diklaim. |
| `home_checkin_err_disabled` | Daily check-in is currently disabled. | Absensi harian sedang dinonaktifkan. |
| `home_checkin_err_already` | You have already claimed today's bonus. | Anda sudah mengklaim bonus hari ini. |
| `home_checkin_err_unavailable` | Your account is not eligible for a bonus right now. | Akun Anda belum memenuhi syarat bonus saat ini. |
| `home_checkin_err_generic` | Failed to claim the bonus. Please try again. | Gagal mengklaim bonus. Silakan coba lagi. |
| `ledger_checkin` | Daily Check-in Bonus — Day %d | Bonus Absensi Harian Hari ke-%d |

**Total = 18 kunci baru** (17 kunci member-facing + 1 kunci renderer ledger).

---

## 8. Non-goals (eksplisit di luar scope)

1. Tabel `user_checkins`, halaman riwayat absensi, atau kalender bulanan
   (ledger = histori; D1/D2).
2. Kartu laporan/monitor admin (jumlah klaim hari ini, total payout) — tidak
   menyentuh `Admin_model::get_alert_counts()` (tidak ada antrean baru).
3. Baris `user_notifications` per klaim harian (spam lonceng). *Follow-up
   opsional* bila diminta kelak: butuh key `notif_checkin` + 2 kunci kamus +
   `Notification_model::insert_keyed()` (F21).
4. Bonus tambahan (mingguan/bulanan/milestone) atau cap jumlah hari streak (E8).
5. Backfill streak untuk user lama — semua mulai `checkin_streak = 0`,
   `checkin_last_date = NULL`.
6. Perubahan skema `wallet_ledger` (termasuk nilai ENUM baru) — lihat D7.
7. Gate "wajib punya sewa aktif" / "wajib e-wallet terikat" (D-A: semua member).
8. Perubahan apa pun di bawah `system/`.

---

## 9. Edge case (matriks penanganan)

| # | Skenario | Perilaku |
|---|---|---|
| E1 | Klik ganda / dua tab di hari yang sama | Guard `last === today` → `already_claimed`; lapis 3: UPDATE kondisional; lapis 4: UNIQUE ledger (1062) |
| E2 | `checkin_last_date > today` (skew jam / data diubah manual / perubahan TZ) | Diperlakukan `already_claimed` (fail-closed); streak **tidak** dimajukan; nilai tidak diperbaiki senyap |
| E3 | Batas tengah malam WIB (23:59:59 → 00:00:01) | Dua hari berbeda = dua kredit (benar). Otoritas = `date()` PHP WIB; **tidak ada** `NOW()`/`CURDATE()` MySQL (M2/M3) |
| E4 | `checkin_enabled` dimatikan saat streak berjalan | Endpoint menolak (`disabled`, HTTP 403); widget hilang; **streak tidak direset** → aktif kembali, lanjut seperti semula |
| E5 | `base × streak` besar / `base` rusak | PHP int 64-bit; hasil dibatasi `max ≤ 10^7`; assertion `reward ≥ 1` sebelum `credit()` (`_post` menolak ≤ 0) |
| E6 | User banned | Gate ban `MY_Controller` (`:50-54`) mengalihkan sebelum body; **plus** re-cek `is_banned` di dalam TX (defense in depth) |
| E7 | Dua perangkat klaim serentak (pertama kali) | `FOR UPDATE` pada baris `users` menyerialkan; pemanggil kedua melihat `checkin_last_date` terbaru → `already_claimed` |
| E8 | Streak tumbuh tak terbatas | `INT UNSIGNED` memadai; **sengaja tanpa cap jumlah hari** — cap uang (`max`) adalah pengaman finansial; mereset streak user loyal adalah perilaku yang salah |
| E9 | Baris setelan hilang/rusak | Fallback per-key dari `config/checkin_rewards.php`; pelanggaran `base ≤ max` → fallback berpasangan + log; tidak pernah fatal |
| E10 | `checkin_streak_policy` bernilai asing | Fallback `'reset'` (kebijakan paling ketat) |
| E11 | Insert ledger duplikat saat `db_debug = TRUE` | `db_debug` dimatikan lokal (save/restore); 1062 → `already_claimed` JSON — bukan halaman error (F8/F9) |

---

## 10. Directives eksekusi (berurutan)

**Branch:** `feat/plan112-daily-checkin` (disiplin fase `docs/3_ROADMAP.md`).
Pesan commit Bahasa Indonesia, mis.
`feat(absensi): widget absensi harian + bonus berjenjang berbatas harian (plan/112)`.

| Langkah | Aksi | Berkas | Bukti yang dikumpulkan |
|---|---|---|---|
| **0** | ✅ **Dokumen ini** ditulis ke `plan/112_DAILY_CHECKIN_FEATURE_PLAN.md` | `plan/112_…PLAN.md` (baru) | berkas ada, **nol** perubahan kode |
| **1** | Skema + seed + migrasi | `database.sql`, `database_seed.sql`, `scripts/migrate_112_daily_checkin.php` (baru) | `php -l`; `--dry-run`; `--apply`; **re-run `--apply` = no-op exit 0**; `--verify` exit 0 |
| **2** | Default/fallback + model | `application/config/checkin_rewards.php` (baru), `application/models/Checkin_model.php` (baru) | `php -l`; tabel matematika streak (base 50 / cap 10 000: h1 50, h2 100, h3 150, … h200 10 000, h201+ 10 000) |
| **3** | Endpoint + route | `application/controllers/Checkin.php` (baru), `application/config/routes.php` | `php -l`; route terdaftar (bagian multi-segmen) |
| **4** | Injeksi dashboard + widget | `application/controllers/Home.php`, `application/views/home/index.php` | `php -l`; render HTTP 200; widget absen saat `checkin_enabled='0'` |
| **5** | i18n | `application/helpers/i18n_helper.php`, `application/language/{english,indonesian}/app_lang.php` | **kedua gate exit 0** (`audit_i18n_parity.php` → LULUS / ±620; `audit_i18n_hardcoded.php` → 0 temuan) |
| **6** | Integrasi admin | `application/controllers/Admin.php`, `application/views/admin/settings.php` | `php -l`; setelan tersimpan + muncul di audit `admin_update_settings`; validasi gagal → form ter-repopulasi & **tidak ada** satupun key yang tersimpan (all-or-nothing) |
| **7** | Matriks runtime (curl/browser) | — | tabel V1–V11 di bawah |
| **8** | Sinkronisasi dokumen & ringkasan | `docs/1_PRD.md`, `docs/3_ROADMAP.md`, `plan/112_DAILY_CHECKIN_FEATURE_SUMMARY.md` (baru) | invariant baru tercatat; ringkasan memuat matriks bukti |
| **9** | Review diff final | — | hanya perubahan yang diniatkan; tanpa skrip/probe tertinggal (scratch di `/tmp`, dibersihkan) |

### 10.1 Matriks verifikasi runtime (langkah 7)

| # | Aksi | Harapan |
|---|---|---|
| V1 | `GET /` (user seed yang belum pernah absen) | 200; widget tampil; "Bonus hari ini" = `Rp 50`; streak 0/1 |
| V2 | `POST /checkin/claim` **tanpa** token CSRF | 403 (CSRF), respons **JSON**, bukan HTML |
| V3 | `POST /checkin/claim` dengan `csrfFetch` | 200 `{success:true, data.reward:50, code:'ok'}` + `new_balance` naik 50 |
| V4 | `SELECT type, amount, transaction_id, description FROM wallet_ledger WHERE transaction_id LIKE 'CHK-%'` | 1 baris: `credit / 50 / CHK-{uid}-{Ymd} / Bonus Absensi Harian Hari ke-1` |
| V5 | `POST /checkin/claim` **ulang** di hari yang sama | 200 `{success:false, code:'already_claimed'}`; **nol** baris ledger baru |
| V6 | `GET /` lagi | tombol "Sudah Diklaim" + hitung mundur; streak = 1 |
| V7 | Parity saldo: `SUM(credit) − SUM(debit)` vs `users.balance` vs pil saldo header | ketiganya sama; `scripts/reconcile_balances.php` bersih untuk user uji |
| V8 | Admin: `checkin_base_reward = 20000`, `checkin_max_reward = 10000` → simpan | Flash validasi gagal; **nol** key berubah (before/after sama); form memuat nilai yang diketik (flash state) |
| V9 | Admin: `checkin_enabled` → OFF, simpan | Widget **hilang** dari `GET /`; `POST /checkin/claim` → 403 `{code:'disabled'}`; streak user tetap utuh |
| V10 | Admin: `checkin_enabled` → ON + policy `continue` | Widget kembali; hint kebijakan berubah; audit `admin_update_settings` memuat `checkin_enabled` & `checkin_streak_policy` di `keys` |
| V11 | UI pada 360 px (devtools) | Widget & strip 7 hari tanpa overflow; `prefers-reduced-motion: reduce` → animasi berhenti |

---

## 11. Risiko & mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Menggabungkan validator baru merusak jalur POST `/admin/settings` yang sudah ada | Setelan finansial/kontak/rebate gagal tersimpan | Validator terpisah (F12) + merge hanya ke `$final`; V8 + regresi: simpan setelan lama tanpa perubahan → sukses & audit hanya berisi key yang berubah |
| Duplikat ledger pada `db_debug = TRUE` | Respons HTML pada jalur AJAX; TX tertinggal | `db_debug` save/restore + translasi 1062 (§5.2, F8/F9); V5 |
| Batas tengah malam WIB | Klaim "hilang"/dobel di sekitar tengah malam | Semua perbandingan harian = PHP WIB `date('Y-m-d')`; nol `NOW()`/`CURDATE()`; E3 |
| Nilai setelan rusak (diedit langsung di DB) | Bonus salah / crash | Validasi per-key + koherensi `base ≤ max` + fallback atomik + log; `--verify` migrasi exit 2 (tamper) |
| Kebocoran i18n (angka/literal di kamus, EN≡ID) | Gate P3/P5 merah | Tabel kunci §7 disusun dengan EN ≠ ID dan tanpa nominal; kedua gate dieksekusi di langkah 5 |
| `checkin_enabled` default ON (D5) | Pengeluaran otomatis tanpa konfigurasi admin | Bonus terbatas (base 50, cap 10 000/hari/user), toggle instan, `--verify` menandai nilai rusak; **dapat dibalik ke `'0'`** sebelum langkah 1 dijalankan |

---

## 12. Daftar berkas yang akan tersentuh

| # | Berkas | Jenis | Langkah |
|---|---|---|---|
| 1 | `plan/112_DAILY_CHECKIN_FEATURE_PLAN.md` | baru (dokumen ini) | 0 |
| 2 | `plan/112_DAILY_CHECKIN_FEATURE_SUMMARY.md` | baru (pasca-eksekusi) | 8 |
| 3 | `database.sql` | ubah (DDL `users` + seed + catatan migrasi) | 1 |
| 4 | `database_seed.sql` | ubah (seed `system_settings`) | 1 |
| 5 | `scripts/migrate_112_daily_checkin.php` | baru | 1 |
| 6 | `application/config/checkin_rewards.php` | baru | 2 |
| 7 | `application/models/Checkin_model.php` | baru | 2 |
| 8 | `application/controllers/Checkin.php` | baru | 3 |
| 9 | `application/config/routes.php` | ubah (1 route) | 3 |
| 10 | `application/controllers/Home.php` | ubah (injeksi `$data['checkin']`) | 4 |
| 11 | `application/views/home/index.php` | ubah (widget + CSS `hm112-*` + JS klaim) | 4 |
| 12 | `application/helpers/i18n_helper.php` | ubah (1 pola renderer ledger) | 5 |
| 13 | `application/language/english/app_lang.php` | ubah (+18 kunci) | 5 |
| 14 | `application/language/indonesian/app_lang.php` | ubah (+18 kunci) | 5 |
| 15 | `application/controllers/Admin.php` | ubah (validasi + state + GET config) | 6 |
| 16 | `application/views/admin/settings.php` | ubah (Kartu 7) | 6 |
| 17 | `docs/1_PRD.md`, `docs/3_ROADMAP.md` | ubah (invariant baru) | 8 |

**Zona sensitif yang disentuh (AGENTS.md):** `application/models/Checkin_model.php`
(baru — jalur uang, wajib lewat `Wallet_model::credit()`),
`application/controllers/Admin.php`, `database.sql`, `database_seed.sql`,
`application/config/routes.php`. **Tidak** menyentuh `Wallet_model`,
`MY_Controller`, `Rental_model`, `Promoter_model`, `Admin_model`, atau apa pun
di bawah `system/`.
