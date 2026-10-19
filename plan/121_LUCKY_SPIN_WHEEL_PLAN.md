# PLAN 121 — LUCKY SPIN WHEEL (REFERRAL REWARD SYSTEM)

- **Branch (diusulkan):** `feat/121-lucky-spin-wheel` (roadmap rule 4: satu branch per fase)
- **Status:** PLAN — **DISETUJUI OWNER**; eksekusi **menunggu perintah lanjutan**
  (roadmap rule 7 — Milestone Lock; plan ini **belum** dieksekusi, nol kode diubah)
- **Basis keputusan owner:** `dec-016e333a2e77bd9e` (D1 data model · D2 penyimpanan
  konfigurasi · D3 segmen Rp 0) + `dec-84830f31a30fba50` (D4 grandfathering)
- **Tipe perubahan:** **fitur baru** — 1 kolom `users`, **2 tabel baru**, 2 kunci
  `system_settings`, 1 fallback config, 1 helper choke-point, 1 model, 1 controller
  member, 1 controller method admin, 2 view, 3 route, ~40 key kamus EN/ID, 1 migration
  script, sinkronisasi `docs/` + `AGENTS.md`.
- **Non-goal mutlak:** **tanpa perubahan `system/`**, **tanpa perubahan
  `views/templates/header.php`** (invarian plan/100: header = tepat 3 grup),
  **tanpa perubahan `views/templates/bottom_nav.php`** (Golden 5), **tanpa perubahan
  `application/core/MY_Controller.php`** (urutan statement load-bearing), **tanpa
  menyentuh `Wallet_model.php`** (hanya pemanggil `credit()`), **tanpa backfill**
  (D4), **tanpa dependency Composer baru** (Tailwind Play CDN + Font Awesome saja).

---

## 0. Ringkasan, Keputusan & Asumsi

Membangun **Lucky Spin Wheel**: insentif berulang untuk mengajak **downline L1**
melakukan pembelian pertama produk **non-trial**. Setiap downline memberi **tepat
1 (satu) tiket spin** seumur hidup; tiket dipakai pada roda hadiah uang tunai yang
seluruh probabilistikanya dihitung **di server** (zero-trust), dan hadiah dikredit
ke **dompet utama** (`wallet_type = 'main'`, dapat ditarik melalui seluruh gerbang
plan/117/118 yang sudah ada).

Karakter fitur: **tiket BUKAN uang** (tidak pernah masuk `wallet_ledger`, tidak dapat
ditarik, tidak dapat dibeli), sedangkan **hadiah ADALAH uang** (satu baris
`wallet_ledger` kredit deterministik per spin).

### 0.1 Keputusan owner (mengikat saat eksekusi)

| # | Keputusan | Pilihan owner | Konsekuensi |
|---|---|---|---|
| **D1** | Data model tiket + idempotensi + histori | **Counter + 2 tabel audit**: `users.spin_tickets` (cache/saldo tiket) + `spin_ticket_grants` (UNIQUE `downline_id`) + `spin_results` | Guard lifetime di tingkat DB (bukan hanya lapis aplikasi); histori spin + snapshot bobot tersedia untuk audit/sengketa; mengikuti pola `wallet_ledger` + kolom cache yang sudah jadi konvensi repo |
| **D2** | Penyimpanan konfigurasi roda | **JSON di `system_settings`** (`spin_wheel_enabled`, `spin_wheel_segments`) + helper codec murni + editor baris | Preseden plan/119 (`team_milestone_tiers`); tanpa tabel baru; satu form, satu `update_system_settings()`, audit otomatis |
| **D3** | Segmen "zonk" (Rp 0) | **TIDAK boleh** — setiap segmen ≥ Rp 1 | Jalur uang tanpa cabang khusus: setiap spin yang berhasil menulis **tepat satu** kredit `SPIN-*`; `Wallet_model::_post()` menolak amount ≤ 0 sehingga cabang 0 justru berbahaya |
| **D4** | Grandfathering pembelian pertama pra-rilis | **Tanpa backfill** — grant mulai saat rilis | Migration hanya menanam konfigurasi; nol pergerakan data; tidak ada "banjir tiket" saat launch |

### 0.2 Asumsi yang dinyatakan (owner dapat menimpa, tidak memblokir)

| # | Asumsi | Alasan |
|---|---|---|
| **A1** | Upline **tidak wajib aktif** untuk menerima tiket (berbeda dari rebate L1–L3 yang menuntut kontrak aktif non-trial) | Tiket = insentif engagement, bukan komisi. Hanya upline **banned** / `parent_id` NULL yang dilewati (no-op + log) |
| **A2** | Tiket **tidak kedaluwarsa**, tidak dapat dibeli/dipindahtangankan | Konsisten dengan cap "1 per downline seumur hidup"; model tanpa state waktu |
| **A3** | Tidak ada cap spin harian/mingguan selain "butuh tiket" | Kelangkaan tiket itu sendiri adalah cap (tiket ≤ Σdownline, SP2) |
| **A4** | URL kanonik member **`/lucky-spin`**; `/rewards` **tidak** dibuat | Satu URL, tanpa duplikasi konten; rute eksplisit tetap wajib |
| **A5** | Titik masuk = **FAB melayang di atas `bottom-nav`** + kartu inline di dashboard | Invarian plan/100 (header tepat 3 grup) tidak boleh dilanggar |
| **A6** | Bottom nav tetap **Golden 5** | `views/templates/bottom_nav.php` adalah layout 5 slot tetap |
| **A7** | Konfigurasi admin di halaman **khusus** `/admin/lucky-spin` | `views/admin/settings.php` sudah 1.158 baris dengan 5 blok konfigurasi; halaman khusus mengikuti preseden `admin/ewallet_providers.php` |

---

## 1. Bukti kondisi saat ini (anchor terverifikasi, bukan asumsi)

| Kontrak | Lokasi | Yang dipakai plan ini |
|---|---|---|
| TX checkout + urutan langkah | `application/models/Rental_model.php:123–369` | `lock_and_get_balances()` anchor (`:131`), snapshot produk GATE 0 termasuk `is_trial` (`:148–197`), insert kontrak (`:320–329`), **hook rebate langkah 7 (`:331–349`)**, commit (`:351`) |
| Preseden "caller-TX participant" | `Rental_model::_distribute_rebate()` `:738–850` | Pola hook bonus privat: load `Notification_model`, return `bool`, gagal ⇒ pemanggil rollback |
| Resolver konfigurasi dinamis | `Checkin_model::get_config()` `:57–147`, `validate_checkin_settings()` `:174–238` | Nilai dinamis di atas fallback per-key; nilai rusak ⇒ fallback key tersebut + log, tidak pernah fatal |
| Preseden codec JSON | `application/helpers/team_milestone_helper.php` (limits / int token / parse / valid / encode; **tanpa prosa user-facing** — gate i18n R2) | Bentuk `spin_wheel_helper.php` |
| Choke-point uang | `Wallet_model::credit()` `:811`, `_post()` `:837–899` (`WALLET_MAIN = 'main'` `:25`) | Hadiah wajib lewat sini; amount ≤ 0 ditolak ⇒ D3 |
| Endpoint member POST+AJAX | `application/controllers/Checkin.php` (POST-only, `is_ajax_request`, rate limit, peta `code→lang` termasuk `'ok'`, `api_success/api_error`) | Bentuk `Rewards::spin()` |
| Editor baris JSON admin | `views/admin/settings.php:562–736` (`tm_target[]`/`tm_amount[]` + `tmAddRow()`) | Bentuk editor segmen |
| Persist setelan + audit | `Admin_model::update_system_settings($data, $audit)` `:1358–1369` | Tulis multi-key atomik + baris audit `admin_*` dalam satu TX |
| Notifikasi dwibahasa | `Notification_model::insert_keyed($user_id, $key, $params, $type)` `:96` | Notifikasi tanpa copy hardcoded (plan/103) |
| i18n deskripsi ledger | `i18n_helper.php:406–465` (`i18n_ledger_description()`) | Pola baru `ledger_spin` |
| Skema kanonik | `database.sql:11–86` (`users`), `:259–278` (`wallet_ledger`), `:433+` (seed `system_settings`), blok catatan migrasi plan/1xx di ekor file (`:946–1013`) | Penempatan DDL + seed + catatan migrasi |
| Baseline i18n | `english/app_lang.php` = `indonesian/app_lang.php` = **633 key** (`grep -cE '^\s*\$lang\['`) | Paritas wajib 1:1 |
| Konvensi rute | `application/config/routes.php:97–100` | Rute multi-segmen wajib eksplisit (`translate_uri_dashes = FALSE`) |
| Sidebar admin | `views/admin/templates/sidebar.php:51–98` | Entri nav baru |

Pencarian `spin|wheel|lucky` di seluruh `application/` = **nol hasil** (tidak ada kode/setelan
yang bertabrakan). Fitur ini greenfield.

---

## 2. Aturan domain & invarian (SP1–SP12 — didaftarkan di `docs/2_ERD.md` §7)

| Kode | Invarian |
|---|---|
| **SP1** | Uang hadiah berpindah **hanya** lewat `Wallet_model::credit(..., WALLET_MAIN)` — dilarang `insert('wallet_ledger')` langsung atau `UPDATE users SET balance` di luar model dompet |
| **SP2** | **Tepat satu** tiket per downline seumur hidup: dijamin `spin_ticket_grants.uk_spin_grant_downline (downline_id)` **dan** pre-check terserialisasi oleh anchor `users … FOR UPDATE` milik `checkout_rental()` |
| **SP3** | Grant tiket hanya dipicu kontrak **non-trial** (`gpu_products.is_trial = 0`); jalur trial gratis (`$free_trial`) melewati hook sepenuhnya |
| **SP4** | **Zero-trust**: bobot dibaca dari konfigurasi ter-commit **di dalam** TX spin; klien **tidak** mengirim segmen/hadiah/nominal apa pun (hanya token CSRF) |
| **SP5** | Randomisasi = `random_int()` (CSPRNG). Pemilihan berbobot adalah **fungsi murni** `spin_wheel_pick($segments, $roll)` yang dapat diuji tanpa DB |
| **SP6** | Tiket **bukan uang**: tidak pernah masuk `wallet_ledger`, tidak dapat ditarik; `users.spin_tickets` adalah counter, bukan saldo; `scripts/reconcile_balances.php` tidak terlibat |
| **SP7** | Fail-closed: `spin_wheel_enabled = '0'` ⇒ UI disembunyikan **dan** `POST /lucky-spin/spin` menolak HTTP 403 `code:'disabled'`; segmen tak parseable ⇒ `not_configured` (403), tidak pernah hadiah "diam-diam" |
| **SP8** | Semua uang integer IDR (M8): kolom tetap `DECIMAL(15,2)` demi keseragaman skema, tetapi di-`(int)` sebelum aritmetika; **tanpa angka/uang di kamus bahasa** (L6) |
| **SP9** | Copy admin 100% Indonesia (L1); copy member dwibahasa EN≡ID 1:1; string JS dinamis via `window.SYNAPSE_I18N` |
| **SP10** | Animasi menghormati `prefers-reduced-motion: reduce` (roda langsung menampilkan hasil); animasi transform-only (gaya house plan/97–101) |
| **SP11** | Tanpa perubahan `system/`, tanpa dependency baru, hanya Tailwind Play CDN + Font Awesome |
| **SP12** | Kegagalan grant tiket di dalam `checkout_rental()` ⇒ log + **rollback penuh** (kebijakan identik langkah 7 rebate: tidak ada grant parsial, tidak ada baris tanpa counter, tidak ada counter tanpa baris) |

---

## 3. Skema database

### 3.1 `users` — satu kolom baru

```sql
-- plan/121: TIKET LUCKY SPIN (cache/keseimbangan tiket, BUKAN uang).
-- Otoritatif = COUNT(spin_ticket_grants WHERE upline_id = uid)
--             − COUNT(spin_results   WHERE user_id  = uid)
-- Penulis tunggal: Spin_model (grant di dalam TX checkout; konsumsi di TX spin).
-- Tidak pernah masuk wallet_ledger, tidak dapat ditarik (SP6).
`spin_tickets` INT UNSIGNED NOT NULL DEFAULT 0,
```

Tanpa backfill (D4) — seluruh baris lama otomatis 0.

### 3.2 Tabel baru `spin_ticket_grants` — ledger idempotensi lifetime

```sql
CREATE TABLE IF NOT EXISTS `spin_ticket_grants` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `upline_id`   BIGINT UNSIGNED NOT NULL,          -- penerima tiket (parent saat itu)
  `downline_id` BIGINT UNSIGNED NOT NULL,          -- pemicu: pembeli pertama kali
  `rental_id`   BIGINT UNSIGNED NULL DEFAULT NULL, -- kontrak pemicu (audit; NULL bila kontrak hilang)
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spin_grant_downline` (`downline_id`),           -- SP2: 1 tiket / downline / seumur hidup
  INDEX `idx_spin_grant_upline` (`upline_id`, `created_at`),
  CONSTRAINT `fk_spin_grant_upline`   FOREIGN KEY (`upline_id`)   REFERENCES `users` (`id`)        ON DELETE RESTRICT,
  CONSTRAINT `fk_spin_grant_downline` FOREIGN KEY (`downline_id`) REFERENCES `users` (`id`)        ON DELETE RESTRICT,
  CONSTRAINT `fk_spin_grant_rental`   FOREIGN KEY (`rental_id`)   REFERENCES `user_rentals` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`UNIQUE (downline_id)` adalah implementasi **struktural** dari "pertama kali": pembelian
kedua/ketiga downline yang sama mustahil mencetak tiket baru, bahkan bila lapis aplikasi
dilewati.

### 3.3 Tabel baru `spin_results` — jejak audit spin

```sql
CREATE TABLE IF NOT EXISTS `spin_results` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         BIGINT UNSIGNED NOT NULL,
  `slot_index`      SMALLINT UNSIGNED NOT NULL,      -- posisi segmen (0-based, urutan konfigurasi)
  `prize_amount`    DECIMAL(15,2) NOT NULL,          -- snapshot nominal (integer IDR saat spin)
  `weight_snapshot` INT UNSIGNED NOT NULL,           -- bobot segmen saat spin (audit probabilitas)
  `total_weight`    INT UNSIGNED NOT NULL,           -- Σ bobot saat spin
  `transaction_id`  VARCHAR(50) NULL DEFAULT NULL,   -- 'SPIN-{id}' — jejak ke wallet_ledger
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spin_tx` (`transaction_id`),
  INDEX `idx_spin_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_spin_result_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`transaction_id = 'SPIN-{id}'` di-set **setelah** insert (di dalam TX yang sama): auto-increment
id adalah satu-satunya id deterministik bebas tabrakan (skema `SPIN-{uid}-{YmdHis}` bisa
menabrak `uk_wallet_ledger_user_tx_type` bila dua spin terjadi pada detik yang sama).

Penempatan di `database.sql`: **setelah `user_rentals`** (kebutuhan FK), yaitu di section 5
berdampingan `user_notifications`. Inventaris `docs/2_ERD.md` menjadi **14 → 16 tabel**.

### 3.4 Konfigurasi — kunci `system_settings` (D2)

```sql
('spin_wheel_enabled', '1'),
-- JSON array tuple [[nominal_idr, bobot], …] — URUTAN = urutan segmen tampil.
('spin_wheel_segments', '[[5000,30],[10000,25],[25000,18],[50000,10],[100000,6],[150000,3],[250000,2],[500000,1]]'),
```

- Di-seed `INSERT IGNORE` di **kedua** file: `database.sql` **dan** `database_seed.sql`
  (nilai live tidak pernah ditimpa).
- Fallback `application/config/spin_wheel.php` (per-key, pola `checkin_rewards.php` /
  `team_milestones.php`).
- Ambang administratif di helper (satu sumber: validator admin + CLI `--verify`):
  `min_segments = 2`, `max_segments = 12`, `max_amount = 100000000`, `max_weight = 1000000`,
  `max_total_weight = 10000000`.
- Seed di atas adalah **default ilustratif** (expected value ≈ Rp 15,1 rb/tiket pada total
  bobot 95). Halaman admin menampilkan **peluang per segmen** dan **expected value per
  tiket** agar owner dapat menyetel ekonomi sebelum rilis.

### 3.5 Helper — `application/helpers/spin_wheel_helper.php` (autoload `spin_wheel`)

Murni, `function_exists()`-guarded, **tanpa prosa user-facing** (gate i18n R2; pesan
validasi berbahasa Indonesia hidup di `Spin_model` yang dikecualikan gate — pemisahan
sama dengan plan/119):

| Fungsi | Kontrak |
|---|---|
| `spin_wheel_limits()` | `['min_segments','max_segments','max_amount','max_weight','max_total_weight']` |
| `spin_wheel_int_token($raw)` | `^[0-9]+$` ketat → `int` \| `NULL` |
| `spin_wheel_segments_parse($raw)` | string JSON **atau** array tuple → `[[amount,weight],…]` \| `NULL` (ketat; tanpa pengurutan/clamp) |
| `spin_wheel_segments_valid($s)` | `bool` — batas jumlah, `amount ≥ 1` (D3), `weight ≥ 1`, cap per-item & total |
| `spin_wheel_segments_encode($s)` | JSON kanonik `[[a,w],…]` (round-trip idempoten) |
| `spin_wheel_total_weight($s)` | `int` |
| `spin_wheel_pick($s, $roll)` | **murni**: `$roll ∈ [1, total]` → indeks segmen (cumulative walk). Di luar rentang ⇒ `NULL` (tidak pernah diam-diam segmen 0) |
| `spin_wheel_odds_bp($s)` | basis-point integer per segmen (tanpa float; display saja) |

`Spin_model` memanggil `spin_wheel_pick($segments, random_int(1, spin_wheel_total_weight($segments)))`
— **satu-satunya** titik randomisasi (SP4/SP5).

---

## 4. Arsitektur backend

### 4.1 Hook perolehan tiket — di dalam TX checkout yang sudah ada

**File:** `application/models/Rental_model.php`, di dalam `checkout_rental()` antara
langkah 7 (rebate `:349`) dan `trans_commit()` (`:351`):

```php
// langkah 7b (plan/121)
if (!$free_trial && !$this->_award_spin_ticket($user_id, $rental_id)) { /* rollback */ }
```

`_award_spin_ticket($buyer_id, $rental_id): bool` — **caller-TX participant**, mendelegasikan
seluruh state tiket ke `Spin_model::award_ticket_for_first_paid_rental($buyer_id, $rental_id)`
sehingga `Rental_model` tidak memuat SQL tiket (preseden: sudah `load->model('Notification_model')`).

Urutan di dalam `Spin_model::award_ticket_for_first_paid_rental()`:

1. `SELECT parent_id FROM users WHERE id = ?` → `NULL`/self-reference ⇒ **no-op `true`**.
2. `SELECT id, is_banned FROM users WHERE id = upline` → hilang/`is_banned = 1` ⇒ log + **no-op `true`**
   (pembelian tidak pernah digagalkan oleh upline yang rusak).
3. `SELECT id FROM spin_ticket_grants WHERE downline_id = ?` → ada ⇒ **no-op `true`**
   (pembelian kedua dan seterusnya — jalur normal).
4. `INSERT spin_ticket_grants (upline_id, downline_id, rental_id)` — baris pembeli sudah
   terkunci `FOR UPDATE` oleh `lock_and_get_balances()` (`:131`), sehingga dua checkout
   konkuren milik pembeli yang sama terserialisasi di sini; `UNIQUE` menjadi backstop.
5. `UPDATE users SET spin_tickets = spin_tickets + 1 WHERE id = ?` + guard
   `affected_rows() === 1` (M4) — selain itu log + `false`.
6. `Notification_model::insert_keyed($upline_id, 'notif_spin_ticket', ['count' => 1], 'info')`
   (TX yang sama — pola M5).
7. `return true`.

Kegagalan di langkah 4–6 ⇒ `false` ⇒ pemanggil rollback seluruh checkout (SP12, kebijakan
identik langkah 7 rebate).

**Kenapa tidak ada lock tambahan:** TX sudah memegang `users(pembeli) FOR UPDATE` lebih dulu
lalu menyentuh baris `users` upline (jalur rebate melakukan hal yang sama) — kelas dan urutan
lock tidak berubah, sehingga tidak ada siklus deadlock baru.

### 4.2 Alur spin — `Spin_model::spin($user_id)`

Satu transaksi ACID, urutan mengikat:

```
BEGIN
1. SELECT id, spin_tickets FROM users WHERE id = ? FOR UPDATE      ← anchor pertama (serialisasi double-submit)
2. cfg = get_config()          (baca segar; disabled → rollback, code 'disabled')
3. segmen tidak valid/kosong   → rollback, code 'not_configured'
4. tickets < 1                 → rollback, code 'no_tickets'
5. UPDATE users SET spin_tickets = spin_tickets - 1
      WHERE id = ? AND spin_tickets > 0                            ← affected_rows() === 1 (M4)
6. roll  = random_int(1, spin_wheel_total_weight(segments))         ← CSPRNG
   slot  = spin_wheel_pick(segments, roll)                          ← murni, di sini tidak mungkin NULL
   prize = segments[slot].amount                                    ← int ≥ 1 (D3)
7. INSERT spin_results (user_id, slot_index, prize_amount, weight_snapshot, total_weight)
   spin_id = insert_id()
8. Wallet_model::credit($user_id, $prize, 'SPIN-'.$spin_id,
                        'Hadiah Lucky Spin', Wallet_model::WALLET_MAIN)   ← false ⇒ rollback
9. UPDATE spin_results SET transaction_id = 'SPIN-'.$spin_id WHERE id = $spin_id
10. Notification_model::insert_keyed($user_id, 'notif_spin_win', ['amount' => $prize], 'bonus')
COMMIT
```

Kontrak hasil (mengikuti `Checkin_model::claim()`):
`['success'=>bool, 'code'=>'ok'|'disabled'|'not_configured'|'no_tickets'|'user_unavailable'|'error',
'slot_index'=>int, 'prize'=>int, 'transaction_id'=>string, 'tickets_left'=>int,
'new_balance'=>int, 'summary'=>array]`

Tidak ada cabang hadiah 0 (D3) — `_post()` tidak pernah dipanggil dengan 0.
`user_unavailable` menutup baris hilang/banned yang terbaca di langkah 1.

### 4.3 Permukaan publik `Spin_model` (`application/models/Spin_model.php`)

| Method | Tujuan |
|---|---|
| `get_config()` | Memoized per-request; `system_settings` di atas `config/spin_wheel.php`, validasi per-key, `enabled`/`segments`/`total_weight`/`odds` |
| `validate_settings(array $raw)` | Normalizer POST admin → `['ok','errors','field_errors','values','notices']`; pesan Indonesia (L1); menegakkan bentuk SP7 |
| `get_summary($user_id)` | Read-only: `enabled`, `tickets`, `earned` (Σ grant), `used` (Σ result), `can_spin`, `segments` (amount + odds untuk render), `history` (10 spin terakhir), flag `segments_invalid` |
| `award_ticket_for_first_paid_rental($buyer_id, $rental_id)` | Caller-TX participant §4.1 |
| `spin($user_id)` | §4.2 |

Seluruh SQL tinggal di sini (roadmap rule 2); controller/view tidak memuat SQL.

### 4.4 Controller member — `application/controllers/Rewards.php` (extends `MY_Controller`)

| Method | Perilaku |
|---|---|
| `index()` | `GET /lucky-spin` — inject `Spin_model::get_summary()`, render `templates/header` + `rewards/index` + `templates/bottom_nav` |
| `spin()` | `POST /lucky-spin/spin` — POST-only + `is_ajax_request` + sesi + `Rate_limit_model` (`spin_play:{uid}`, 5 hit / 60 detik) + `api_success()/api_error()` dengan peta `code→lang` (**termasuk `'ok'`** — pelajaran F18: key legacy `message` di root menimpa envelope), plus `summary` segar pada sukses dan pada `no_tickets` |

`MY_Controller` **tidak disentuh** (tanpa view-var global baru ⇒ nol risiko pada urutan
statement yang load-bearing).

### 4.5 Admin — `Admin::lucky_spin()` + reader `Admin_model`

- `GET /admin/lucky-spin` → render `views/admin/lucky_spin.php` (Indonesia).
- `POST /admin/lucky-spin` → `Spin_model::validate_settings()` → sukses ⇒
  `Admin_model::update_system_settings($values, $audit)` dengan aksi **`admin_update_spin_wheel`**
  dan `details = {keys, before, after}` (hanya key yang berubah, perilaku plan/105) →
  flash + redirect.
- `Admin_model::get_spin_overview()` — statistik read-only: tiket beredar
  (`SUM(users.spin_tickets)`), total grant, total spin, total hadiah dibayar
  (`SUM(prize_amount)`), 20 spin terakhir di-join `users.phone`. Tanpa baris audit (read-only ⇒ A1).

---

## 5. UI / UX

### 5.1 Halaman member `application/views/rewards/index.php` (baru, ~450 baris)

Mobile-first di dalam shell `max-w-[480px]`, memakai token tema member (`u-*` / `t-*`):

1. **Blok header** — judul halaman + pil tiket (`spin_tickets_label` + angka besar + `spin_ticket_unit`).
2. **Roda** — CSS/JS murni, **tanpa filter SVG**, tanpa aset eksternal:
   - `conic-gradient` dibangun dari daftar segmen hasil render server (warna bersiklus via 12
     kelas predefined; inline hanya custom property numerik `--i` / `--n`).
   - Label (nominal hadiah) dirender **server-side** dengan `number_format` IDR
     (L6: angka tidak pernah masuk kamus).
   - Pointer di jam 12; wrapper `transform: rotate(deg)` dengan
     `transition: transform 4.2s cubic-bezier(.16,1,.3,1)`.
   - `@media (prefers-reduced-motion: reduce)` ⇒ `transition: none` (SP10).
3. **Tombol spin** — disabled bila `tickets < 1` | `!enabled` | in-flight; label
   `spin_cta_spin` / `spin_cta_spinning` / `spin_cta_no_ticket`.
4. **Panel hasil** (`aria-live="polite"`) — `spin_win_title` + `Rp {prize}` + saldo utama baru
   + `spin_win_desc`.
5. **"Cara mendapat tiket" 3 langkah** (ajak → downline membeli paket non-trial pertama →
   1 tiket) + `spin_rules_body`.
6. **Riwayat** — 10 spin terakhir dari `spin_results` (tanggal/hadiah), plus empty state.
7. **Empty state** saat `tickets = 0`: `spin_empty_title/desc` + CTA ke `/team` (ajak) — roda
   tetap dirender (visual utuh) tetapi non-interaktif.

**Kontrak animasi (zero-trust):** klien mengirim `POST` hanya dengan CSRF → server membalas
`{success:true, slot_index, prize, tickets_left, new_balance}` → JS menghitung
`finalAngle = 360 * 5 + (360 - (slot_index * segAngle + segAngle / 2))` lalu menyetel
`rotate(finalAngle)deg`; panel hasil muncul **setelah** `transitionend` (atau seketika pada
reduced-motion). Klien **tidak pernah** menentukan hadiah; respons yang dimanipulasi hanya
mengubah posisi berhenti pointer, sementara kredit dompet sudah ter-commit di server.

### 5.2 Titik masuk dashboard

- **FAB melayang** di `views/home/index.php`: `fixed bottom-20 right-4 z-40` (di atas
  `bottom-nav` `z-50`? — lihat catatan di bawah), lingkaran gradient `fa-star`/`fa-dharmachakra`,
  badge jumlah tiket, `href=site_url('lucky-spin')`, dirender hanya bila `spin.enabled`.
  Label via `lang('spin_fab_label')` + `aria-label`.
- **Kartu inline** di bawah kartu absensi plan/112: `spin_dashboard_card_title/desc/cta` +
  jumlah tiket + hint "1 tiket per downline".
- **Tanpa perubahan header** (plan/100), **tanpa perubahan bottom nav** (A6).

> Catatan z-index untuk fase eksekusi: `bottom-nav` memakai `z-50` dan toast `z-[60]`. FAB
> harus berada **di atas** kanvas konten namun **tidak menutupi** tombol nav — verifikasi di
> 360 px (matriks V7). Jika `z-40` membuat FAB tertutup nav, naikkan ke `z-[55]` dengan offset
> `bottom-20` (di atas tinggi nav 64 px).

### 5.3 Halaman admin `application/views/admin/lucky_spin.php` (baru, ~280 baris, Indonesia)

- Toggle aktif (`spin_wheel_enabled`, semantik fail-closed dijelaskan inline).
- Editor baris `spin_amount[]` / `spin_weight[]` dengan tambah/hapus (preseden `tmAddRow()`,
  `views/admin/settings.php:709–735`), peluang **%** dan **expected value per tiket** dihitung
  live — integer saja, `number_format` IDR.
- Daftar error validasi + error per baris (repopulasi baris tidak valid — pola transport plan/110).
- Strip ringkasan read-only (tiket beredar / grant / spin / hadiah dibayar) + tabel 20 spin terakhir.
- Entri sidebar gaya blok `E-Wallet`: `/admin/lucky-spin`.

---

## 6. Rencana i18n (EN ≡ ID, 1:1)

Baseline **633 / 633** (terverifikasi). Tambahan ≈ **40 key** → ekspektasi **673 / 673**
(angka final diverifikasi `grep -cE '^\s*\$lang\['`, tidak dipercaya dari memori — AGENTS.md).

| Grup | Key |
|---|---|
| Halaman/hero | `spin_page_title`, `spin_hero_title`, `spin_hero_subtitle`, `spin_tickets_label`, `spin_ticket_unit`, `spin_fab_label` |
| Interaksi roda | `spin_cta_spin`, `spin_cta_spinning`, `spin_cta_no_ticket`, `spin_win_title`, `spin_win_desc`, `spin_close` |
| Aturan/cara | `spin_how_title`, `spin_how_step1`, `spin_how_step2`, `spin_how_step3`, `spin_rules_title`, `spin_rules_body` |
| Empty/riwayat | `spin_empty_title`, `spin_empty_desc`, `spin_empty_cta`, `spin_history_title`, `spin_history_empty`, `spin_history_prize`, `spin_odds_hint` |
| Kartu dashboard | `spin_dashboard_card_title`, `spin_dashboard_card_desc`, `spin_dashboard_card_cta`, `spin_dashboard_no_ticket_hint` |
| Error/status | `spin_err_no_ticket`, `spin_err_disabled`, `spin_err_not_configured`, `spin_err_unavailable`, `spin_err_generic`, `spin_ok_title` |
| JS (`window.SYNAPSE_I18N`) | `js_spin_spinning`, `js_spin_error`, `js_spin_network` |
| Ledger + notifikasi | `ledger_spin`, `notif_spin_ticket_title`, `notif_spin_ticket_body`, `notif_spin_win_title`, `notif_spin_win_body` |

Aturan: tanpa angka/uang di kamus (L6); halaman admin + pesan validator `Spin_model` tetap
Indonesia dan **tidak** di-key (L1); `i18n_helper.php` mendapat pola `ledger_spin` di
`i18n_ledger_description()`.

---

## 7. Inventaris file

**Baru**

| Path | Tujuan |
|---|---|
| `application/models/Spin_model.php` | Resolver konfigurasi, validator admin, summary, grant tiket, TX spin (penulis tunggal `spin_tickets`, `spin_ticket_grants`, `spin_results`) |
| `application/helpers/spin_wheel_helper.php` | Codec murni + pemilihan berbobot + ambang (autoload `spin_wheel`) |
| `application/config/spin_wheel.php` | Fallback default per-key |
| `application/controllers/Rewards.php` | `index()` halaman + `spin()` endpoint POST/AJAX |
| `application/views/rewards/index.php` | Halaman roda member |
| `application/views/admin/lucky_spin.php` | Halaman konfigurasi admin |
| `scripts/migrate_121_lucky_spin.php` | CLI idempoten (`--dry-run` default / `--apply` / `--verify` / `--help`; exit 0/1/2) |
| `plan/121_LUCKY_SPIN_WHEEL_PLAN.md`, `plan/121_LUCKY_SPIN_WHEEL_SUMMARY.md` | Plan ini + ringkasan pasca-implementasi |

**Diubah**

| Path | Perubahan |
|---|---|
| `database.sql` | `users.spin_tickets`; 2 `CREATE TABLE IF NOT EXISTS`; 2 `INSERT IGNORE` kunci setelan; blok catatan migrasi plan/121 di ekor file (`ALTER`/`CREATE INDEX` untuk DB live) |
| `database_seed.sql` | 2 kunci setelan (`INSERT IGNORE`) |
| `application/models/Rental_model.php` | Hook langkah 7b + `_award_spin_ticket()` (privat, caller-TX participant) |
| `application/models/Admin_model.php` | `get_spin_overview()` (read-only) |
| `application/controllers/Admin.php` | `lucky_spin()` (GET render + POST simpan + audit) |
| `application/controllers/Home.php` | Inject summary `spin` (tergerbang; panggilan dilewati total bila fitur OFF) |
| `application/views/home/index.php` | FAB + kartu inline |
| `application/views/admin/templates/sidebar.php` | Entri nav `Lucky Spin` |
| `application/config/routes.php` | `lucky-spin`, `lucky-spin/spin`, `admin/lucky-spin` |
| `application/config/autoload.php` | Helper `spin_wheel` |
| `application/helpers/i18n_helper.php` | Pola deskripsi `ledger_spin` |
| `application/language/{english,indonesian}/app_lang.php` | ~40 key masing-masing (1:1) |
| `docs/1_PRD.md`, `docs/2_ERD.md`, `docs/3_ROADMAP.md`, `docs/4_UI_UX_GUIDELINES.md`, `AGENTS.md` | §10 |

**Tidak disentuh:** `system/**`, `MY_Controller.php`, `Wallet_model.php` (hanya pemanggil),
`views/templates/header.php`, `views/templates/bottom_nav.php`, `reconcile_balances.php`,
`Admin::settings()`.

---

## 8. Migrasi & rollout — `scripts/migrate_121_lucky_spin.php`

Fase (identik pola `scripts/migrate_112_daily_checkin.php`, termasuk loader konfigurasi DB
**yang sudah dibetulkan** `$active_group → default → grup pertama`):

1. **PRE-FLIGHT** — tabel `users`, `user_rentals`, `system_settings` ada; tipe `key_value`;
   `uk_key_name` ada; include helper berhasil (`function_exists` guard).
2. **INSPECT** — kolom/tabel/kunci sudah ada? cetak rencana.
3. **DDL** — `ADD COLUMN users.spin_tickets` dijaga `information_schema`;
   `CREATE TABLE IF NOT EXISTS` × 2. **Tanpa backfill** (D4).
4. **SEED** — `INSERT IGNORE` 2 kunci setelan (tidak pernah menimpa nilai live).
5. **VERIFY (read-only)** — tipe/nullability/default kolom; kedua tabel + nama index persis
   (`uk_spin_grant_downline`, `uk_spin_tx`, `idx_spin_grant_upline`, `idx_spin_user_created`);
   FK; kunci ada & parseable (`spin_wheel_segments` tidak valid ⇒ tamper ⇒ **exit 2**);
   **pemeriksaan drift tiket**: `users.spin_tickets <> (Σgrant − Σresult)` per user ⇒
   **exit 2** + laporan.

Exit code `0` bersih/no-op, `1` pre-flight gagal, `2` apply/verify gagal. `--apply` diulang
wajib no-op.

---

## 9. Matriks verifikasi (bukti DoD resmi)

| # | Perintah / aksi | Ekspektasi |
|---|---|---|
| V1 | `php -l` pada setiap file PHP baru/diubah | tanpa syntax error |
| V2 | `php scripts/audit_i18n_parity.php` | `LULUS` (EN≡ID 1:1, tanpa nilai identik di luar allowlist) |
| V3 | `php scripts/audit_i18n_hardcoded.php` (bersihkan `application/logs/*.php` lebih dulu) | `0 temuan` |
| V4 | `migrate_121 --dry-run` → `--apply` → `--apply` (ulang) → `--verify` | rencana → diterapkan → **no-op** → exit 0 |
| V5 | Probe helper di `/tmp` (dibersihkan setelahnya): batas `spin_wheel_pick()` (`roll=1`→0, `roll=total`→terakhir, `roll=0`/`total+1`→`NULL`); 200k undian empiris vs bobot (±2% toleransi); round-trip parse/valid/encode termasuk payload rusak | seluruh asersi lolos |
| V6 | DB sekali-pakai dari `database.sql` + `database_seed.sql`, app di `127.0.0.1:8080` dengan override `DB_*` + `APP_BASE_URL` | — |
| V6a | `GET /lucky-spin` anonim | `302 → /login` |
| V6b | `GET /lucky-spin` member dengan 0 tiket | `200`, roda terender, tombol disabled, copy `no_tickets` |
| V6c | `POST /lucky-spin/spin` dengan 0 tiket | `200 {success:false, code:'no_tickets'}` (tanpa baris ledger) |
| V6d | Beri 1 tiket (via alur V6f) → `POST …/spin` | `200 {success:true, code:'ok', slot_index, prize}`, tepat **1** baris `wallet_ledger` `SPIN-{id}` `wallet_type='main'`, `users.balance +prize`, `users.spin_tickets −1`, 1 baris `spin_results` + `transaction_id` terisi |
| V6e | Double-submit (dua POST paralel, 1 tiket) | tepat satu sukses + satu `no_tickets`; saldo naik sekali |
| V6f | Upline + downline baru: downline beli **trial** → lalu **berbayar** → lalu **berbayar lagi** | trial: 0 grant; berbayar ke-1: tepat 1 grant, `spin_tickets` upline = 1, baris `notif_spin_ticket`, upline **tanpa** sewa aktif tetap dikredit (A1); berbayar ke-2: tetap 1 grant, counter tidak berubah |
| V6g | `spin_wheel_enabled='0'` → `GET /lucky-spin` + `POST …/spin` | UI disembunyikan / `403 {code:'disabled'}` |
| V6h | `spin_wheel_segments` dirusak (`'[[0,10]]'`, `'not-json'`) | halaman merender state fail-closed, POST → `403 {code:'not_configured'}`, error ter-log, **tanpa** hadiah |
| V6i | Admin: simpan segmen valid | `302` + baris `system_audit_logs` `admin_update_spin_wheel` dengan `keys/before/after`; submit ulang identik ⇒ `keys` kosong |
| V6j | Admin: `weight = 0`, `< 2` segmen, `amount = 0`, 13 segmen | error field berbahasa Indonesia, **tanpa** penulisan |
| V6k | 6 `POST …/spin` beruntun cepat | `429` dari `rate_limit_json_response()` |
| V6l | POST tanpa token CSRF | `403` (framework), tanpa perubahan state |
| V7 | Uji UI manual 360 px / 480 px + `prefers-reduced-motion: reduce` | pointer berhenti pada `slot_index`; reduced-motion menampilkan hasil seketika; tanpa scroll horizontal; FAB tidak menutupi bottom-nav |

Higiene repo: DB sekali-pakai di-drop; seluruh probe di `/tmp`; `git status` hanya memuat
file yang dijanjikan §7.

---

## 10. Pembaruan dokumentasi

| Dokumen | Pembaruan |
|---|---|
| `docs/1_PRD.md` | Sub-bab baru §4 **"I. Lucky Spin Wheel (Referral Reward)"** — aturan tiket (1/downline seumur hidup, non-trial), semantik roda, kredit hadiah ke saldo utama (dapat ditarik di bawah gerbang plan/117/118), konfigurasi admin; §7 Admin Command Center mendapat halaman Lucky Spin; §2 catatan bahwa hadiah adalah integer IDR |
| `docs/2_ERD.md` | `users.spin_tickets`; `spin_ticket_grants`; `spin_results`; inventaris **14 → 16 tabel**; tambahan Mermaid ER; blok §7 baru **"Invariant arsitektural tambahan (plan/121 — Lucky Spin Wheel)"** = SP1–SP12 |
| `docs/3_ROADMAP.md` | Entri selesai `### Lucky Spin Wheel (Referral Reward System) ✅ COMPLETED (plan/121)` beserta invarian + penunjuk bukti |
| `docs/4_UI_UX_GUIDELINES.md` | Spesifikasi komponen roda: pendekatan conic-gradient, animasi transform-only, `prefers-reduced-motion`, shell 480 px, penempatan FAB relatif `bottom-nav` dan toast |
| `AGENTS.md` | Bullet **plan/121** baru (skema, choke-point, invarian, jumlah key i18n) + pembaruan hitungan inventaris tabel |
| `plan/121_LUCKY_SPIN_WHEEL_SUMMARY.md` | Ditulis pasca-implementasi dengan hasil V1–V7 yang sebenarnya dijalankan |

---

## 11. Risiko, mitigasi, di luar cakupan

| Risiko | Mitigasi |
|---|---|
| Deadlock antara checkout (lock pembeli → update upline) dan TX spin | TX spin hanya mengunci barisnya sendiri; checkout sudah meng-update baris upline pada jalur rebate dengan kelas/urutan lock yang sama — tidak ada siklus baru |
| Inflasi tiket / payout tak terkendali | Pasokan tiket terbatas secara struktural pada Σdownline (SP2); halaman admin menampilkan **expected value per tiket** sehingga bobot dapat disetel pra-rilis |
| Hadiah langsung dapat ditarik | Memang disengaja (spesifikasi §2); gerbang existing berlaku tanpa perubahan (jam Mon–Sab 07:00–19:00 WIB, tier fee, `is_wd_locked`, `has_paid_rental`) |
| Drift cache (`users.spin_tickets` vs event) | Definisi otoritatif yang dapat diturunkan (SP6) + pemeriksaan drift `migrate_121 --verify` (exit 2). Tindak lanjut opsional: flag `--repair` |
| Konfigurasi di `system_settings` dirusak | Parse ketat + fail-closed `not_configured`, tanpa hadiah diam-diam, error ter-log |
| Bug kelas F18 (respons sukses membawa pesan error) | Peta `code→lang` memuat `'ok'` **dan** satu titik penetapan pesan setelah hasil diketahui (pola Checkin/plan-119) |
| Kebingungan member "kenapa tidak dapat tiket?" | Kartu dashboard + how-to di `/lucky-spin` menyatakan eksplisit "non-trial, pembelian pertama, 1 per downline"; undangan yang hanya menghasilkan trial tidak memberi tiket (konsisten dengan sikap anti-free-rider plan/116/118) |

**Di luar cakupan:** membeli tiket dengan saldo, kedaluwarsa tiket, leaderboard/bonus streak,
cap hadiah per user atau cap spin harian, aset gambar/branding hadiah, ekspor CSV hasil spin,
backfill retroaktif (D4), perubahan apa pun di `system/`.

**Sengaja ditunda (P2, hanya bila owner meminta):** flag `--repair` drift di migration;
fixture `scripts/seed_spin_test_account.php` (upline + N downline, cermin
`seed_milestone_test_account.php`); ekspor CSV admin; tier multi-tiket per downline.

---

## 12. Fase eksekusi (setiap fase hijau sebelum lanjut)

| Fase | Cakupan | Kriteria keluar |
|---|---|---|
| **P0 — Skema & konfigurasi** | `database.sql`, `database_seed.sql`, `config/spin_wheel.php`, `helpers/spin_wheel_helper.php`, `autoload.php`, `scripts/migrate_121_lucky_spin.php` | V1 (helper+script), V4, V5 hijau; `--apply` diulang no-op |
| **P1 — Model** | `Spin_model` (konfigurasi, validator, summary, grant, TX spin), hook langkah 7b `Rental_model` | V1; V6f + V6c/V6d/V6e terverifikasi lewat HTTP |
| **P2 — Permukaan member** | controller `Rewards`, `views/rewards/index.php`, route, injeksi `Home` + FAB/kartu | V1, V6a–V6e, V6g–V6h, V7 |
| **P3 — Permukaan admin** | `Admin::lucky_spin()`, `views/admin/lucky_spin.php`, `Admin_model::get_spin_overview()`, sidebar | V1, V6i–V6j, V6k–V6l |
| **P4 — i18n** | ~40 key × EN/ID, pola `ledger_spin` di `i18n_helper`, key notifikasi | V2 + V3 keduanya exit 0 |
| **P5 — Dokumen & ringkasan** | `docs/1..4`, `AGENTS.md`, `plan/121_..._SUMMARY.md` | Dokumen konsisten dengan kode; ringkasan memuat bukti V1–V7 aktual |
| **P6 — Sapuan akhir** | review `git status`/diff, gate i18n diulang setelah bersihkan log, DB sekali-pakai di-drop, probe `/tmp` dihapus | Diff hanya memuat file §7 |

---

**Status akhir dokumen:** PLAN DISETUJUI (`dec-016e333a2e77bd9e`, `dec-84830f31a30fba50`).
Eksekusi P0–P6 menunggu perintah lanjutan owner (roadmap rule 7 — Milestone Lock).
