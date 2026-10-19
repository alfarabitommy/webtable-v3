# PLAN 119 — BONUS PENCAPAIAN TIM (TEAM MILESTONE BONUS) — PENGGANTI GAJI MINGGUAN

- **Branch:** `feat/119-team-milestone-bonus` (roadmap rule 4: satu branch per fase)
- **Status:** PLAN — **DISETUJUI OWNER**; eksekusi **menunggu perintah lanjutan**
  (roadmap rule 7 — Milestone Lock; plan ini **belum** dieksekusi, nol kode diubah)
- **Basis keputusan owner:** `dec-4ac071f2603beab7`
  (Bonus L1 tetap independen · klaim **sekuensial** ketat · **backfill** grandfathering)
- **Tipe perubahan:** skema (+1 kolom `users`, +2 key `system_settings`) + mesin klaim
  (`User_model`) + controller (`Team`) + view member (`/team`) + admin (`/admin/settings`
  Card 7) + helper & fallback config baru + migrasi CLI + i18n (+26 / −28 key) +
  sinkronisasi `docs/`. **Tanpa tabel baru** (inventory kanonik tetap **14**), **tanpa index baru**,
  **tanpa entri `routes.php`**, **tanpa perubahan `system/`**,
  **tanpa perubahan `views/templates/header.php`** (invarian plan/100 utuh).

---

## 0. Ringkasan, Keputusan & Invarian

Saat ini imbalan agensi berjenjang dibayar **berulang tiap siklus 7 hari**
(`User_model::WAGE_TIERS` + `last_wage_claimed_at` + cooldown 7 hari). Plan/119
menggantinya dengan **Bonus Pencapaian Tim**:

1. **Tanpa siklus mingguan, tanpa cooldown.** Setiap tier dibayar **SEKALI seumur hidup**
   per user. Tidak ada jam/penjadwalan; klaim tetap **manual** (POST AJAX), konsisten
   dengan konvensi repo "tidak ada cron".
2. **Definisi downline aktif tidak berubah** — predikat kanonik plan/116 (K8):
   `ur.status = 'active'` **DAN** `ur.expired_at > now WIB` **DAN** `gp.is_trial = 0`.
   Nol predikat baru: otoritas tetap `User_model::count_all_active_downlines()`.
3. **Tier & nominal 100% dapat dikonfigurasi admin** (`/admin/settings`), disimpan di
   `system_settings` (JSON tuple, preseden `wd_fee_tiers`) dengan fallback per-key.
4. **Pembayaran ke Saldo Utama** (`users.balance`, `wallet_type = 'main'`) via
   `Wallet_model::credit()` — **bukan** dompet `checkin` (plan/118).
5. **Klaim sekuensial & permanen.** Tier aktif = **tier terendah yang belum diklaim**;
   tier di atasnya terkunci sampai tier sebelumnya diklaim. Klaim bersifat **permanen**:
   penurunan jumlah downline setelah klaim tidak pernah membatalkan/mengulang bayaran.
6. **Bonus Level 1 (Rp 80.000) tetap independen** — `claim_level1()` +
   `users.is_level_1_claimed` + kartu misi L1 **tidak disentuh** (keputusan owner).

### 0.1 Keputusan owner (dikonfirmasi, `dec-4ac071f2603beab7`)

| # | Keputusan owner |
|---|---|
| **O1** | **Bonus L1 tetap independen.** `claim_level1()`, `LEVEL1_BONUS` (Rp 80.000), flag `users.is_level_1_claimed`, dan kartu "Misi Level 1" di `/team` **tidak berubah**. Hanya **Gaji Mingguan (L2–6)** yang digantikan. |
| **O2** | **Urutan klaim sekuensial ketat** — tier boleh diklaim hanya bila **semua tier lebih rendah sudah diklaim** DAN targetnya tercapai. Ditegakkan di server (di dalam TX), bukan hanya di UI. |
| **O3** | **Grandfathering backfill** — user yang historisnya sudah menerima Gaji Mingguan ditandai **sudah mengklaim** tier yang setara dengan pencapaiannya (`WAGE-*`). Tanpa uang tambahan, tanpa tabel baru. |

### 0.2 Keputusan desain turunan (D1–D14)

| # | Keputusan | Alasan |
|---|---|---|
| **D1** | **State klaim** = kolom baru `users.team_milestone_claimed VARCHAR(255) NULL DEFAULT NULL` berisi **CSV target integer kanonik menaik** (contoh `'9,30'`; `NULL`/`''` = belum ada). Penulis tunggal: `User_model::claim_milestone()` via `UPDATE … WHERE id = ? AND (team_milestone_claimed <=> ?)` + guard `affected_rows() === 1` (pola M4, preseden `is_level_1_claimed`). | O3 (backfill grandfathering) memerlukan **penanda klaim tanpa uang**. Ledger tidak dapat menampung klaim tanpa kredit (`_post()` menolak amount ≤ 0 dan baris amount 0 = polusi ledger). CSV = preseden `wd_operational_days` ('1,2,3,4,5,6'); `users` tidak punya kolom JSON di repo ini. |
| **D2** | **Jejak audit + guard tingkat DB** = baris `wallet_ledger` `MSB-{user_id}-T{target}`, `wallet_type = 'main'`, description kanonik. UNIQUE `uk_wallet_ledger_user_tx_type (user_id, transaction_id, type)` = jaminan DB "satu kredit per user per tier". | Audit imutabel + lapis kedua anti double-claim (defense in depth), pola idempotensi `CHK-{uid}-{Ymd}` plan/112. |
| **D3** | **Konfigurasi = 2 key `system_settings`**: `team_milestone_enabled` (`'1'`/`'0'`) + `team_milestone_tiers` (JSON `[[target,amount],…]`, kanonik **menaik**, maks **20** baris — lihat D6). Fallback per-key `application/config/team_milestones.php`. | Preseden `wd_fee_tiers` (plan/110) & `checkin_*` (plan/112/118). Nol tabel baru. |
| **D4** | **Fail-closed**: `team_milestone_enabled = '0'` → kartu **tidak dirender sama sekali** DAN endpoint menolak (`code: 'disabled'`, HTTP 403). Konfigurasi rusak → fallback per-key + `log_message('error')`, tidak pernah fatal. | Parity `checkin_enabled` plan/112. |
| **D5** | **Seed default = mirror tier gaji lama**: `[[9,200000],[30,1000000],[70,2500000],[130,5000000],[190,9000000]]`. | Tidak mengarang angka baru pada data live; ambang & nominal yang sudah dipublikasikan dipertahankan (berubah dari *per minggu* menjadi *sekali*). Admin bebas mengubah (mis. `3,5,9,11`) di `/admin/settings`. |
| **D6** | **Batas administratif** (satu sumber di helper, dipakai validator admin + CLI `--verify`): maks **20** tier; `target` integer **1..100.000**; `amount` integer **1..100.000.000** (IDR); target **unik & menaik ketat**; kontiguitas **tidak** disyaratkan; proyeksi CSV state ≤ **200 karakter**. | M8 (integer IDR) + mencegah tier sampah/tak sengaja. Batas 20 tier **diturunkan dari lebar kolom** (§3.2): 20 tier × (6 digit target + 1 koma) = **139 karakter** ≤ 255, dengan margin aman untuk nilai lain. |
| **D7** | **Validator setelan hidup di MODEL** (`User_model::validate_milestone_settings()`), bukan di helper. | Gate `audit_i18n_hardcoded.php` **mengecualikan model dari R2** (`scripts/audit_i18n_hardcoded.php:295`), sehingga pesan validasi berbahasa Indonesia (invarian L1) tidak memicu temuan dan **tidak perlu pengecualian baru** di gate (berbeda dari `withdrawal_fee_helper.php`). |
| **D8** | Helper `application/helpers/team_milestone_helper.php` = **murni struktural, tanpa copy** (parse/encode CSV & JSON, pembentuk `transaction_id`) — nol string user-facing. | Gate hardcoded memindai `application/helpers/**`; helper tanpa prosa = aman tanpa pengecualian. |
| **D9** | **Tanpa cooldown, tanpa siklus, tanpa cron.** Satu endpoint: `POST /team/claim_milestone` (POST + AJAX-only). `Team::claim_wage()` **dihapus**. | Permintaan bisnis O2/D9; parity `Checkin::claim()` (plan/112). |
| **D10** | **Retensi historis**: kolom `users.last_wage_claimed_at` **dipertahankan** (tidak lagi ditulis; komentar DEPRECATED), key kamus `ledger_wage` + `notif_wage_title/_body` **dipertahankan**, dan pola `i18n_ledger_description()` untuk `^Gaji Mingguan Level (\d+)` **dipertahankan**. | Baris `wallet_ledger` `WAGE-*` dan baris `user_notifications` ber-`title_key = 'notif_wage'` **ada di DB live** → menghapus key/mapping = teks hilang/fatal saat render. Preseden AGENTS "never hard-delete". |
| **D11** | **`claim_level1()` tetap** sebagai satu-satunya jalur bonus one-time lain; helper/pemetaan pesan L1 di `Team::_claim_message()` **tidak diubah**. | Keputusan owner O1; blast radius minimal. |
| **D12** | **Denominasi saldo**: kredit ke **`Wallet_model::WALLET_MAIN`**. Tidak menyentuh `checkin_balance`, tidak menyentuh jalur penarikan. | Persyaratan bisnis #4; `credit()` default sudah `main` (plan/118 D12). |
| **D13** | **Fixture**: `scripts/seed_wage_test_account.php` → **rename** `scripts/seed_milestone_test_account.php` (isi: leader + N downline → fixture klaim milestone), dengan catatan penunjuk di dokumen historis (roadmap plan/116 + Notes AGENTS). | Fixture harus mendeskripsikan apa yang diuji; nama lama menyesatkan. |
| **D14** | **`users.level_id` tidak dipakai** untuk status milestone (tidak ada penulis di aplikasi; nilainya selalu 0). Status 100% **derived** dari konfigurasi + kolom klaim + penghitung downline. | Menghindari dua sumber kebenaran & skema "level" yang tak terpelihara. |

### 0.3 Invarian baru (didaftarkan di `docs/2_ERD.md` §7)

- **MK1** — Predikat "downline aktif" = plan/116 **K8** (`status='active'` **DAN**
  `expired_at > now WIB` **DAN** `gp.is_trial = 0`); **tidak ada predikat baru**.
  Otoritas penghitungan tetap `User_model::count_all_active_downlines()`.
- **MK2** — Satu klaim per `(user_id, target)` **seumur hidup**, ditegakkan **tiga lapis**:
  state CSV (`team_milestone_claimed`) + UNIQUE `uk_wallet_ledger_user_tx_type` pada
  `MSB-…` + recount kualifikasi **di dalam** TX terkunci.
- **MK3** — Kredit HANYA via `Wallet_model::credit()`, `wallet_type = 'main'`, nominal
  **dari konfigurasi server** (bukan dari input klien).
- **MK4** — Klien hanya mengirim `target` (integer). Target yang tidak ada di konfigurasi
  → `invalid_target` (nol mutasi, nol baris ledger).
- **MK5** — Urutan TX mengikat: `SELECT … FROM users … FOR UPDATE` →
  baca state klaim → resolve konfigurasi → gerbang tier/urutan/idempotensi →
  recount `count_all_active_downlines()` → `UPDATE` kondisional (`affected_rows() === 1`)
  → `credit()` → commit. Setiap kegagalan = rollback penuh.
- **MK6** — Klaim **permanen**: penurunan jumlah downline setelah klaim tidak
  membatalkan status (tanpa clawback) dan tidak dapat diulang.
- **MK7** — Klaim sekuensial: tier `k` hanya dapat diklaim bila seluruh tier dengan
  target lebih rendah sudah ada di state klaim; pelanggaran → `code: 'locked_previous'`.
- **MK8** — Tanpa tabel/index baru; `uk_wallet_ledger_user_tx_type` tidak diubah;
  inventory kanonik tetap **14** tabel; nol perubahan `system/`.

---

## 1. Fakta repositori yang diverifikasi (dasar desain)

| Titik | Fakta terverifikasi |
|---|---|
| `User_model.php:114` | `const LEVEL1_BONUS = 80000` — single source of truth bonus L1 (P5 plan/80). **Tidak diubah** (O1). |
| `User_model.php:119-125` | `const WAGE_TIERS = [6=>[…190…, 9.000.000], …, 2=>['threshold'=>9,'amount'=>200000]]` — **hardcoded** di model; kunci array = level, urut menurun. |
| `User_model.php:205-212` | `determine_wage_level($active_count)` = loop pertama yang `>= threshold` → `['level','amount','label']`; `label` = string `'Rp 9.000.000'` (nominal terformat **di model**). |
| `User_model.php:219-241` | `check_wage_cooldown()` = baca `users.last_wage_claimed_at`, hitung `diff->days`, klaimable bila `>= 7`; mengembalikan `next_claim_date` terformat `'d M Y'` (nomor bulan EN — sisa warisan, kini tak dipakai UI). |
| `User_model.php:248-283` | `get_claim_data()` = **dua blok**: L1 (`count_active_b_downlines`, `sum_sales_b_downlines`, `is_level_1_claimed`) + wage (`count_all_active_downlines` → `determine_wage_level` → `check_wage_cooldown` → `weekly_eligible`) + `balance` (saldo segar dari ledger, C4 plan/54). |
| `User_model.php:291-362` | `claim_level1()`: TX → `SELECT id, is_level_1_claimed … FOR UPDATE` → re-verify (`b_active>=3` && `sum_b>=330000`) → gate `is_level_1_claimed` → `UPDATE … WHERE is_level_1_claimed = 0` + `affected_rows()===1` → `credit('L1-{uid}-{YmdHis}')` → commit. **Pola M4 acuan plan/119.** |
| `User_model.php:382-514` | `claim_wage()`: TX → anchor `SELECT id, last_wage_claimed_at, is_banned … FOR UPDATE` → gerbang banned → gerbang 7 hari/siklus ISO (`cycle_not_ready`) → `count_all_active_downlines()` + `determine_wage_level()` (`not_qualified`) → `UPDATE users SET last_wage_claimed_at = ? … AND (last_wage_claimed_at IS NULL OR <= cutoff)` + `affected_rows()===1` → `credit('WAGE-{uid}-Y{week}')` → commit. |
| `User_model.php:525-536` | `_wage_result()` = normalizer payload (`success = code === 'claimed'`, key `amount/level/cycle/transaction_id/next_claim_date`). |
| `User_model.php:85-102` | `get_team_with_active_status()` = SQL badge aktif `/team`, sudah memakai predikat K8 (plan/116). **Tidak diubah.** |
| `User_model.php:181-198` | `count_all_active_downlines()` = recursive CTE + `status='active'` + `expired_at > ?` + `gp.is_trial = 0` → **otoritas tier milestone** (MK1). |
| `Team.php:16-98` | `index()`: `$claim_data = User_model::get_claim_data()` (`:61`) + inject `l1_bonus`/`l1_bonus_fmt` (`:83-84`) + rebate config; render `team/index`. |
| `Team.php:166-241` | `claim_wage()`: POST-only → AJAX-only → sesi (401) → rate limit `wage_claim:{uid}` 5/60 → `_wage_message()` (`:202`) → `error` ⇒ 500 → sukses ⇒ `new_balance` + `Notification_model::insert_keyed('notif_wage')` + `api_success` → else `api_error(200, code)`. |
| `Team.php:360-377` | `_wage_message()`: peta `already_claimed/not_qualified/user_unavailable/cycle_not_ready/error` → key kamus. **Tidak ada entry `'claimed'`** → fallback `rental_err_claim_failed` = **akar bug F18**. |
| `Team.php:197-198, 213, 223-234` | `$legacy = $result; unset($legacy['success']);` lalu `$legacy['message'] = $localized` (`:203`) — tetapi pada cabang sukses `$localized` di-`lang('team_js_wage_success')` (`:213`) **tanpa** menimpa `$legacy['message']`; `api_success()` menulis key legacy **di atas** envelope ⇒ `message` = prosa `rental_err_claim_failed`. Konsisten dengan catatan F18 di Notes AGENTS. |
| `views/team/index.php:9-30` | Blok display wage: `$w_ladder = [9=>2, 30=>3, 70=>4, 130=>5, 190=>6]` (**mirror hardcoded kedua** dari `WAGE_TIERS`, harus ikut hilang), `$w_next_thr`, `$w_pct`. |
| `views/team/index.php:82-143` | Kartu "GAJI MINGGUAN (LEVEL 2-6)": progress, badge level, tombol 3-state (`$w_eligible` / `$w_cool` / `team_req_min9`). |
| `views/team/index.php:505-519` | Blok modal bantuan "Gaji Mingguan — Levels 2-6": 5 baris **nominal hardcoded** (`Rp 200.000` … `Rp 9.000.000`) + label `team_help_wage_l2..l6` (memuat angka `≥ 9 aktif`). Wajib menjadi loop dinamis (nominal tier kini dari konfigurasi). |
| `views/team/index.php:532-551, 640-690` | Blok `const I18N = {…}` (13 key) + fungsi JS `claimWage()` (branch pada `code`). |
| `views/team/index.php:1-80, 152-500` | Kartu Misi L1 (`:1-80`), Share Center, panduan rebate, hub promotor, modal bantuan — **di luar lingkup** plan/119 (kecuali blok wage di dalam modal bantuan). |
| `Admin.php:375-625` | `settings()`: satu endpoint GET/POST; validasi `form_validation` (kontak) + `Wallet_model::validate_financial_settings` + `Rental_model::validate_rebate_settings` + `Checkin_model::validate_checkin_settings`; all-or-nothing via `flashdata('settings_form_state')` + `_settings_form_state()` (`:704-716`); audit `admin_update_settings` (`before`/`after`/`changed`) sebelum persist (`Admin_model::update_system_settings`, satu TX). |
| `Admin.php:608-614` | Injeksi GET: `$data['checkin_*']` dari `Checkin_model::get_config()` — **pola yang akan ditiru** untuk `team_milestone_*`. |
| `views/admin/settings.php:125, 132-581` | Satu `form_open('admin/settings')` memuat Card 1–6; Card 6 = "Absensi Harian (Daily Check-in)" di `:446-…` (**insersi Card 7 setelahnya**), repopulasi dari `$fs` (`:78-90`), error inline per kartu (`$error_flat` + filter prefix, `:52-60`). |
| `Admin_model.php:1318-1351` | `get_setting()` / `get_settings_map($keys)` (read) — dipakai GET & snapshot audit. |
| `Admin_model.php:1358-1369` | `update_system_settings($data, $audit)` = `INSERT … ON DUPLICATE KEY UPDATE` per key + `_write_audit()` dalam SATU TX (tanpa whitelist key → key baru otomatis tersimpan & teraudit). |
| `Checkin_model.php:57-78, 84-…` | Pola `get_config()`: fallback `require APPPATH.'config/…'` → `SELECT … WHERE key_name IN (…)` → `_resolve_config()` validasi per-key + `log_message('error')` saat nilai rusak (tidak pernah fatal). **Pola acuan `User_model::get_milestone_config()`.** |
| `Checkin_model.php:174-…` | Pola `validate_checkin_settings()`: `{ok, errors[], field_errors{}, values{}}` — **pola acuan `validate_milestone_settings()`**. |
| `Checkin_model.php:390-…` | Pola `claim()`: TX + anchor lock + `UPDATE` kondisional (`affected_rows()===1`) + `credit()` + `db_debug` dimatikan lokal + `$this->db->error()` dibaca **sebelum** rollback (translasi duplikat 1062/23000 → respons bisnis, bukan HTML). |
| `Wallet_model.php:811, 837-…` | `credit($uid,$amount,$tx_id,$description,$wallet_type = WALLET_MAIN)` → `_post()` = **satu-satunya** penulis `wallet_ledger` + mirror cache; amount ≤ 0 ditolak; insert gagal (termasuk duplikat) → `false`. |
| `wallet_ledger` (database.sql:234-256) | `transaction_id VARCHAR(50)` (muat `MSB-…`), `wallet_type ENUM('main','checkin') DEFAULT 'main'`, UNIQUE `uk_wallet_ledger_user_tx_type (user_id, transaction_id, type)`. |
| `i18n_helper.php:441-444` | Peta pola `^Gaji Mingguan Level (\d+)` → `ledger_wage` — **dipertahankan** (D10); perlu **+1 pola** `^Bonus Pencapaian Tim (\d+)` → `ledger_milestone`. |
| `Notification_model.php:96-125` | `insert_keyed($uid, $key, $params, $type)` — `type` bebas string (`info|success|warning|commission`); `params` disimpan JSON & dirender `vsprintf` di `identifier` idiom pembaca. **Key lama `notif_wage` tetap harus ada** (baris historis). |
| `config/autoload.php:117` | `$autoload['helper'] = array('url','file','form','security','language','i18n','maintenance','product_image','wa_group','ewallet','referral','withdrawal_amount','withdrawal_fee')` → **+ `'team_milestone'`**. |
| `scripts/audit_i18n_hardcoded.php:295` | `if ($isModel) { continue; }` — model dikecualikan dari R2 ⇒ pesan validasi Indonesia aman di `User_model` (D7). |
| `scripts/audit_i18n_parity.php:70-84` | P3 = hanya menolak literal `Rp`+digit; P1 = paritas himpunan key 1:1 (EN≡ID). |
| `database.sql:408-457` | Blok seed `system_settings` idempotent (`INSERT IGNORE`, “never overwrites a live value”) — tempat seed `team_milestone_*`. |
| `database_seed.sql:8-40, 308-…` | Daftar `-- [RECONCILE] ALTER TABLE …` (duplikat 1060 toleran) + blok seed `system_settings` kembar. |
| `docs/1_PRD.md:256-276`, `docs/2_ERD.md:263, 309`, `docs/3_ROADMAP.md:85-88, 297-304` | Titik dokumen yang menyebut Gaji Mingguan / `WAGE_TIERS` / `last_wage_claimed_at` → wajib disinkronkan (§11). |
| `plan/107_VIRAL_PROMOTIONAL_COPYWRITING_KIT.md:39, 183, 255` | Materi promosi memuat tabel gaji mingguan (`9 → Rp 200.000` … `190 → Rp 9.000.000/minggu`) → **menjadi keliru** setelah plan/119 (§11). |
| `scripts/seed_wage_test_account.php` | Fixture leader + N downline (idempoten, rentang telepon `0812999900xx`, `--downlines/--product`); kolom insert termasuk `last_wage_claimed_at`; blok verifikasi mencetak dua penghitung (berbayar vs semua) → adaptasi ke milestone (D13). |
| Grep baseline | Tidak ada `team_milestone` / `MSB-` di `application/`, `scripts/`, `docs/`, `database*.sql` → tidak ada preseden yang perlu dipertahankan. `users.level_id` **tidak punya penulis** di aplikasi (hanya seed & pembacaan) → tidak dipakai untuk state milestone (D14). |

---

## 2. Audit kode saat ini (ringkas & eksplisit)

### 2.1 Rantai Gaji Mingguan hari ini

1. **Konfigurasi** — `WAGE_TIERS` **hardcoded** di model (level → `threshold`/`amount`/`label`).
   Tidak ada jalur admin; mengubah nominal = mengubah kode.
2. **Evaluasi** — `get_claim_data()` (dipanggil `Team::index()`) menghitung
   `count_all_active_downlines()` → `determine_wage_level()` → `check_wage_cooldown()`.
3. **Klaim** — `POST /team/claim_wage` → `Team::claim_wage()` (lapisan HTTP/UX) →
   `User_model::claim_wage()` (seluruh gate + TX + kredit).
4. **Idempotensi** — dua lapis: stamp `users.last_wage_claimed_at` (gerbang 7 hari /
   siklus ISO) + UNIQUE ledger pada `WAGE-{uid}-Y{week}`.
5. **UI** — kartu "Gaji Mingguan (Level 2-6)" + tangga level **hardcoded kedua** di view
   (`$w_ladder`) + blok bantuan dengan nominal **hardcoded ketiga**.

**Implikasi:** ada **tiga** salinan kebijakan (konstanta model, label model, tangga view).
Plan/119 menyatukan semuanya ke **satu** sumber dinamis (konfigurasi) — view hanya
mem-`loop`, model hanya membaca.

### 2.2 Hubungan `claim_level1()` dengan sistem baru

| Aspek | Bonus Level 1 | Bonus Pencapaian Tim (baru) |
|---|---|---|
| Dasar kualifikasi | 3 downline **B** aktif **DAN** omset B ≥ Rp 330.000 | jumlah **seluruh** downline aktif (B+C+D+…) ≥ target tier |
| Sifat | one-time (Rp 80.000) | one-time **per tier** (nominal dari konfigurasi) |
| State idempotensi | `users.is_level_1_claimed` (TINYINT) | `users.team_milestone_claimed` (CSV) + UNIQUE ledger |
| Endpoint | `POST /team/claim_level1` | `POST /team/claim_milestone` |
| Kartu UI | "Misi Level 1" (`views/team/index.php:1-80`) | kartu baru (pengganti kartu wage) |
| Perubahan plan/119 | **NOL** (keputusan owner O1) | seluruh mesin |

**Kesimpulan:** keduanya **tetap independen**. Satu-satunya hal yang **dibagi** adalah
peta pesan JSON di controller — rencananya **satu fungsi pemetaan kode → key kamus per
endpoint** (`_claim_message()` untuk L1 tetap; `_milestone_message()` baru), sehingga
tidak ada jalur copy yang bisa bocor diam-diam (lihat §2.3).

### 2.3 Bug F18 — akar masalah & cara diperbaiki bersih

**Gejala (Notes AGENTS):** respons sukses `POST /team/claim_wage` berisi
`{success:true, code:"claimed", message:"System: failed to process the claim…"}` →
`showToast(d.message)` menampilkan teks **gagal** untuk klaim yang **berhasil**.

**Akar masalah (dua sebab, keduanya di `Team::claim_wage()`):**

1. `_wage_message()` (`Team.php:360-368`) tidak punya entry `'claimed'` → `$key` jatuh ke
   fallback `rental_err_claim_failed`.
2. `$legacy['message'] = $localized` diset **sebelum** cabang sukses (`:203`), sedangkan
   `$localized` di-`lang('team_js_wage_success')` **hanya sebagai argumen envelope** (`:213`);
   karena `api_success()` menulis key legacy **di atas** envelope, `legacy.message`
   yang salah menang.

**Perbaikan struktural di plan/119 (bukan tambal-sulam):**

- Peta pemetaan kode → key **selalu lengkap termasuk `'claimed'`**, dan
  `$legacy['message']` **di-assign ulang** setelah cabang sukses ditentukan (satu titik
  penulisan pesan, setelah `success` diketahui) — sehingga tidak mungkin ada dua nilai
  `message` yang bersaing.
- Kode `claim_wage()` beserta `_wage_message()` **dihapus** ⇒ sumber bug hilang, bukan
  dibiarkan menganggur.
- Ditambahkan langkah verifikasi eksplisit di matriks (§13.3): respons sukses **wajib**
  memuat `message` = kalimat sukses idiom aktif (`assert message != rental_err_*`).

---

## 3. Model Data & Pelacakan Klaim

Tiga lapis, masing-masing satu peran (tidak ada sumber kebenaran ganda):

| Peran | Media | Otoritatif untuk |
|---|---|---|
| **Kebijakan** (tier & nominal) | `system_settings.team_milestone_tiers` (JSON) + `team_milestone_enabled` | “tier apa saja & berapa bayarannya” |
| **State klaim** (idempotensi + backfill) | `users.team_milestone_claimed` (CSV target, NULL = belum ada) | “apakah (user, tier) sudah pernah diklaim” |
| **Jejak uang** (audit imutabel + guard DB) | `wallet_ledger` baris `MSB-{user_id}-T{target}` (`wallet_type='main'`) | “berapa & kapan dibayarkan” |

### 3.1 Mengapa bukan alternatif lain

| Alternatif | Ditolak karena |
|---|---|
| **Ledger-only** (existence `MSB-%` sebagai state) | Tidak dapat menjalankan **O3 (backfill grandfathering)**: menandai klaim tanpa uang memerlukan penanda di luar ledger; menulis baris ledger bernominal 0 ditolak `_post()` (`amount <= 0`) dan = polusi jalur uang. |
| **Tabel `user_milestone_claims`** | Menambah tabel ke-15 (inventory kanonik “14 tabel”) & join baru — tidak sebanding untuk satu himpunan kecil integer per user. |
| **Kolom JSON `users.team_milestone_claimed`** | Repo tidak punya kolom JSON di `users`; CSV = preseden langsung `wd_operational_days` (`'1,2,3,4,5,6'`) dan cukup untuk himpunan target integer. |
| **`users.level_id` sebagai level** | Tidak punya penulis di aplikasi (nilainya selalu 0) dan menyimpan level = menyimpan **turunan** yang bisa basi (D14). |
| **Flag per tier (kolom TINYINT per target)** | Jumlah tier dapat berubah dari admin ⇒ DDL tidak boleh bergantung pada konfigurasi runtime. |

### 3.2 `users.team_milestone_claimed` — kontrak

- `VARCHAR(255) NULL DEFAULT NULL`; `NULL` atau `''` = belum ada klaim.
- Nilai kanonik: **CSV target integer menaik tanpa spasi**, contoh `'9,30,70'`.
- **Anggaran panjang (mengikat — D6):** dengan `MAX_TIERS = 20` dan
  `MAX_TARGET = 100000` (6 digit) proyeksi terburuk = `20 × 6 + 19 = 139` karakter ≤ 255.
  ⚠️ **Batas wajib konsisten:** validator **menolak** konfigurasi yang proyeksi CSV-nya
  melebihi `max_claimed_csv = 200` karakter, dan CLI `--verify` memeriksa ulang nilai yang
  sudah tersimpan. Menaikkan `MAX_TIERS` di masa depan berarti **memperlebar kolom**
  (satu-satunya cara aman) — bukan sekadar menaikkan konstanta.
- Parser `team_milestone_claimed_parse()` **toleran-aman**: token non-integer/duplikat/
  di luar jangkauan → diabaikan (log `debug`), tidak pernah fatal. Nilai asing dari DB
  (tamper manual) tidak dapat membuka klaim karena **target juga harus ada di konfigurasi**
  (MK4) **dan** tercapai (MK2/MK5).

### 3.3 `wallet_ledger` — pola `transaction_id`

- `MSB-{user_id}-T{target}` (contoh `MSB-12-T9`), ≤ 50 karakter (batas kolom).
- `wallet_type = 'main'` (D12) — kredit ke Saldo Utama.
- `description` kanonik: `'Bonus Pencapaian Tim {target} Downline Aktif'`
  (dipetakan ke kamus `ledger_milestone` untuk idiom aktif, §10).
- Baris `WAGE-*` historis **tidak disentuh**: tidak ada rename, tidak ada UPDATE,
  tidak ada DELETE (D10). Insert-only, append-only.
- Index: `idx_user_id` sudah melayani pembacaan state (`LIKE 'MSB-%'`) — **nol index baru**.

### 3.4 Grandfathering (O3) — semantik backfill

Backfill **hanya menulis** `users.team_milestone_claimed`; **nol** baris `wallet_ledger`,
**nol** mutasi saldo, **nol** notifikasi:

1. Ambil seluruh user dengan ≥ 1 baris kredit `transaction_id LIKE 'WAGE-%'`.
2. Tentukan **pencapaian tertinggi** per user, dua sumber (presisi → proxy):
   a. **Presisi:** parse prosa deskripsi baris historis
      `^Gaji Mingguan Level \d+ — (\d+) downline aktif` → `reached = MAX(angka)`.
   b. **Proxy (bila prosa tidak cocok):** `max_amount = MAX(amount)` → `reached =`
      target terbesar di konfigurasi dengan `amount <= max_amount`.
   c. `reached = max(a, b)`.
3. `claimed = { target ∈ konfigurasi : target <= reached }`.
4. `UPDATE users SET team_milestone_claimed = '<csv>' WHERE id = ? AND team_milestone_claimed IS NULL`
   → **idempoten** (re-run = 0 baris; guard `IS NULL` mencegah menimpa klaim baru pasca-deploy).
5. `--dry-run` mencetak: baris `WAGE-%` dipindai, user unik, distribusi jumlah user per
   himpunan `claimed`, dan total nominal yang “terselamatkan” (informasi bisnis, bukan mutasi).

**Catatan operasional (wajib ditulis di ringkasan eksekusi):** fase backfill memakai
**konfigurasi tier saat migrasi dijalankan** (seed default = mirror tier lama, D5).
Karena itu `--apply` dijalankan **segera** setelah deploy, sebelum admin mengubah tier.
Tersedia flag `--skip-backfill` untuk instalasi yang memilih tanpa grandfathering.

---

## 4. Helper & Fallback Config

### 4.1 `application/config/team_milestones.php` (BARU — fallback per-key)

```php
return [
    'team_milestone_enabled' => 1,   // 1 = aktif; 0 = kartu disembunyikan + endpoint 403
    'team_milestone_tiers'   => [[9, 200000], [30, 1000000], [70, 2500000],
                                 [130, 5000000], [190, 9000000]],   // [target, nominal IDR]
];
```
Pola identik `application/config/checkin_rewards.php` (plan/112) & `rebate_commission.php`:
**default, bukan ambang validasi**; ambang administratif hidup di helper (§4.2).

### 4.2 `application/helpers/team_milestone_helper.php` (BARU — murni struktural, nol copy)

Autoloaded via `application/config/autoload.php:117` (`+ 'team_milestone'`). Semua fungsi
dibungkus `function_exists()`; satu-satunya berkas PHP yang memuat aturan bentuk tier.
**Tanpa** prosa user-facing (D8).

| Fungsi | Kontrak |
|---|---|
| `team_milestone_limits()` | `['max_tiers'=>20, 'max_target'=>100000, 'max_amount'=>100000000, 'max_claimed_csv'=>200]` — satu sumber ambang (dipakai validator model + CLI `--verify`). |
| `team_milestone_tiers_parse($json)` | JSON `[[target,amount],…]` → `[['target'=>int,'amount'=>int],…]` **kanonik menaik**; `null` bila tidak string/JSON rusak/bukan tuple 2 elemen/bukan numerik tidak bulat. Ketat (bukan best-effort) — pola `withdrawal_fee_tier_rows_from_json()`. |
| `team_milestone_tiers_valid(array $tiers)` | `true` bila: 1..`max_tiers` baris, target unik & menaik ketat dalam `1..max_target`, amount dalam `1..max_amount`, proyeksi CSV ≤ `max_claimed_csv`. Mengembalikan `['ok'=>bool,'reason'=>string]` (reason = token teknis `snake_case`, **bukan** copy). |
| `team_milestone_tiers_encode(array $tiers)` | `[[9,200000],…]` → JSON kanonik (untuk persist & audit `after`). |
| `team_milestone_claimed_parse($csv)` | CSV `'9,30'`/NULL → `[9,30]` unik menaik; token asing diabaikan (log `debug`). |
| `team_milestone_claimed_encode(array $targets)` | `[9,30]` → `'9,30'` kanonik (`''` bila kosong). |
| `team_milestone_claim_tx_id($user_id, $target)` | `'MSB-{uid}-T{target}'` — **satu sumber** untuk penulis (model) & pembaca (klaim historis di status). |
| `team_milestone_claim_tx_prefix()` | `'MSB-'` (untuk query `LIKE`). |

---

## 5. Model — `application/models/User_model.php`

### 5.1 DIHAPUS (mesin wage)

| Simbol | Catatan |
|---|---|
| `const WAGE_TIERS` (`:119-125`) | digantikan konfigurasi dinamis |
| `determine_wage_level()` (`:205-212`) | digantikan `get_milestone_status()` |
| `check_wage_cooldown()` (`:219-241`) | tidak ada lagi cooldown |
| `claim_wage()` (`:382-514`) | digantikan `claim_milestone()` |
| `_wage_result()` (`:525-536`) | digantikan `_milestone_result()` |

**Tetap:** `LEVEL1_BONUS`, `claim_level1()` (O1), `count_active_b_downlines()`,
`sum_sales_b_downlines()`, `count_all_active_downlines()` (MK1), `get_team_with_active_status()`.

### 5.2 DITAMBAH

#### a) `get_milestone_config(): array`
Pola `Checkin_model::get_config()`: cache per-request (static), fallback
`require APPPATH.'config/team_milestones.php'` → `SELECT key_name,key_value FROM system_settings
WHERE key_name IN ('team_milestone_enabled','team_milestone_tiers')` → `_resolve_milestone_config()`.

Kontrak kembalian:
```php
['enabled' => bool, 'tiers' => [['target'=>int,'amount'=>int],…] /* kanonik menaik */]
```
Aturan resolusi (fail-safe, tidak pernah fatal):
- `enabled`: hanya `'0'`/`'1'` diterima; nilai lain → fallback + `log_message('error')`.
- `tiers`: `team_milestone_tiers_parse()` → bila `null`/tidak valid → fallback config file
  + `log_message('error')`. Bila fallback pun tidak valid → `tiers = []` (kartu merender
  state "belum dikonfigurasi", endpoint menolak `invalid_target`).

#### b) `validate_milestone_settings(array $raw): array`
Kontrak sama `Checkin_model::validate_checkin_settings()`: `{ok, errors[], field_errors{}, values{}}`.
- `team_milestone_enabled`: semantik checkbox → `'1'`/`'0'` (tidak pernah error).
- `team_milestone_tiers`: input **array baris** `tm_target[]` / `tm_amount[]` (transport
  plan/110) → dipasangkan per indeks → dinormalisasi integer ketat
  (`^[0-9]+$`, M8) → divalidasi MK6/`team_milestone_tiers_valid()`.
  - Baris **kosong seluruhnya** (target & amount kosong) = diabaikan (memudahkan admin
    menghapus baris), bukan error.
  - Baris **separuh terisi** = error per baris (`field_errors['tm_row_<i>']`), mis.
    *“Baris 3: target dan nominal wajib diisi keduanya.”*
  - Error bentuk: *“Target harus bilangan bulat 1–100.000 (jumlah downline aktif).”* ·
    *“Nominal bonus harus bilangan bulat 1–100.000.000 (IDR).”* ·
    *“Target antar baris harus unik dan menaik (baris 4: 30 ≤ 9).”* ·
    *“Maksimal 20 tier.”* · *“Konfigurasi terlalu panjang — kurangi jumlah/digit target.”*
  - `values['team_milestone_tiers'] = team_milestone_tiers_encode($tiers)` (JSON kanonik).
- Pesan **100% Indonesia** (invarian L1) dan aman dari gate karena berada di model (D7).

#### c) `get_milestone_status(int $user_id): array`
Read-only, satu perhitungan, dipakai view `/team` **dan** payload respons klaim.
```php
[
  'enabled'          => bool,          // dari get_milestone_config()
  'total_active'     => int,           // count_all_active_downlines() — MARKAN K8
  'tiers'            => [              // selalu kanonik menaik, urutan tampilan
      ['target'=>int, 'amount'=>$int, 'state'=>'claimed'|'claimable'|'locked_count'|'locked_previous',
       'missing'=>int /* target - total_active, min 0 */],
  ],
  'next_target'      => int|null,      // target tier 'claimable' (bila ada) — untuk progress bar
  'claimable_target' => int|null,      // target yang boleh diklaim SEKARANG (sekuensial ⇒ maks 1)
  'claimed_targets'  => [int,…],       // dari team_milestone_claimed_parse()
  'all_claimed'      => bool,
  'claimed_total'    => int,           // Σ amount tier yang sudah diklaim (informasi, bukan saldo)
  'balance'          => int,           // saldo utama segar (Wallet_model::get_balance) — dipakai toast
]
```
Aturan state (MK7, O2) — **nama final saat eksekusi, lihat §19 A1:**
- `claimed` — target ada di `claimed_targets`.
- `claimable` — tier pertama yang **belum** diklaim, semua tier lebih rendah sudah diklaim,
  dan `total_active >= target`. **Maksimal satu** baris berstatus ini.
- `locked_count` — belum diklaim, semua tier lebih rendah sudah diklaim, tetapi
  `total_active < target` (badge + `missing`) → kamus `team_ms_need_more`.
- `locked_previous` — belum diklaim **dan** ada tier lebih rendah yang belum diklaim
  → kamus `team_ms_locked_prev`. Nama ini **identik** dengan `code` endpoint pada gerbang
  sekuensial (§5.2d langkah 6) sehingga UI dan API memakai satu kosakata.
  *(Rancangan awal menamai keadaan ini `locked_target`; diganti saat eksekusi — §19 A1.)*

#### d) `claim_milestone(int $user_id, int $target): array`
Zona waktu: defensif `Asia/Jakarta` (parity `claim_wage`), meski tidak ada komponen tanggal
pada jalur ini.

**Urutan TX yang mengikat (MK5):**
1. `trans_begin()`.
2. **Anchor lock:** `SELECT id, is_banned, team_milestone_claimed FROM users WHERE id = ? FOR UPDATE`.
   - tidak ada → rollback → `_milestone_result('user_unavailable', …)`.
   - `is_banned = 1` → rollback → `user_unavailable` (parity `claim_wage`).
3. **Resolve konfigurasi** (pasca-lock, snapshot stabil) → `get_milestone_config()`.
   - `enabled === false` → rollback → `disabled`.
4. **Gerbang target (MK4):** cari `$tier` dengan `target === $target`; tidak ada → rollback →
   `invalid_target`. (Nominal **selalu** diambil dari konfigurasi, bukan dari klien.)
5. **Idempotensi (MK2):** `in_array($target, $claimed, true)` → rollback → `already_claimed`.
6. **Sekuensial (MK7/O2):** setiap tier dengan target **lebih rendah** ada di `$claimed`?
   Tidak → rollback → `locked_previous`.
7. **Recount kualifikasi:** `$total = $this->count_all_active_downlines($user_id)`
   (predikat K8, di dalam TX). `$total < $target` → rollback → `not_qualified`
   (+ `missing` = target − total pada payload).
8. **Stamp kondisional (pola M4):**
   ```sql
   UPDATE users SET team_milestone_claimed = ?
    WHERE id = ? AND (team_milestone_claimed <=> ?)
   ```
   `$new_csv = team_milestone_claimed_encode(claimed + target)`, kondisi = nilai lama
   yang dibaca di langkah 2 (NULL-safe `<=>`). `affected_rows() !== 1` → rollback +
   `log_message('error')` → `already_claimed` (race tak terduga walau baris terkunci).
9. **Kredit (MK3):** `$tx_id = team_milestone_claim_tx_id($user_id, $target)`;
   `$this->Wallet_model->credit($user_id, (int) $tier['amount'], $tx_id,
   'Bonus Pencapaian Tim ' . $target . ' Downline Aktif')` (default `WALLET_MAIN`).
   - `false` → bedakan **duplikat** vs kegagalan nyata: `db_debug` **dimatikan lokal**
     (save/restore) & `$this->db->error()` dibaca **sebelum** rollback (pola `Checkin_model::claim()`,
     plan/112/118): kode `1062`/`23000` ⇒ `already_claimed` (guard DB MK2 bekerja) —
     selain itu `error`. Rollback selalu.
10. `trans_commit()` → `_milestone_result('claimed', …)` dengan payload
    `{amount, target, transaction_id, new_balance?, milestones?}` (view/controller yang
    melengkapi `new_balance` & `milestones` segar, parity `claim_wage`).
11. `catch (Throwable)` → rollback (bila perlu) + `log_message('error')` → `error`.

Kontrak kode hasil: `claimed` | `already_claimed` | `locked_previous` | `not_qualified` |
`invalid_target` | `disabled` | `user_unavailable` | `error`.
`_milestone_result($code, $message, array $extra = [])` = normalizer
(`success = ($code === 'claimed')`, key lengkap: `code/message/amount/target/missing/
transaction_id`), prosa Indonesia di model = **diagnostik** (presentasi lewat kamus, §6).

#### e) `get_claim_data(int $user_id)` — dirapikan
Kembalian **dikurangi** ke blok L1 saja (agar tidak ada lagi `weekly_eligible`,
`current_level`, `current_wage`, `current_wage_label`, `cooldown_active`,
`days_remaining`, `next_claim_date`):
```php
['level1_eligible','level1_claimed','active_b_count','sales_b','balance']
```
`total_active` **dipindah** ke `get_milestone_status()` (satu sumber, MK1). View & controller
diubah pada commit yang sama (§6, §7) sehingga tidak ada konsumen yatim.

---

## 6. Controller — `application/controllers/Team.php`

### 6.1 `index()` (`:16-98`)
- Ganti `$claim_data = get_claim_data()` → tetap, **plus**
  `$milestone = $this->User_model->get_milestone_status($user_id);`.
- Data view: tambah `'milestones' => $milestone`. `l1_bonus`/`l1_bonus_fmt` **tetap** (O1).
- **Tidak ada** key view wage baru (`w_level`/`w_label` dihitung di view dari `$milestones`).

### 6.2 `claim_wage()` (`:166-241`) + `_wage_message()` (`:360-377`) → **DIHAPUS**
- Tidak ada entri `routes.php` untuk `team/claim_wage` (URL metode langsung), jadi **tanpa
  perubahan route**: URL lama otomatis 404 (CI3) — perilaku yang diinginkan (fitur digantikan).
- Sumber bug F18 ikut terhapus (§2.3).

### 6.3 `claim_milestone()` (BARU — parity `claim_wage()`/`claim_level1()`)
```
POST-only  → bukan POST  → show_404()
AJAX-only  → bukan AJAX  → show_404()
Sesi       → kosong      → api_error(lang('common_session_expired'), 401, [], 'unauthenticated', legacy)
Rate limit → milestone_claim:{user_id}, 5 hit / 60 dtk (Rate_limit_model; pola claim_wage)
             → throttle tidak allowed → rate_limit_json_response() (HTTP 429 JSON)
Input      → $this->input->post('target', TRUE) divalidasi STRICT ^[1-9][0-9]*$ (M8)
             → gagal → api_error(lang('team_ms_js_invalid'), 200, [], 'invalid_target', legacy)
Model      → User_model::claim_milestone($user_id, (int) $target)
Pemetaan   → $msg = $this->_milestone_message($result)   ← peta LENGKAP (termasuk 'claimed' ⇒ F18)
             $status = ['error'=>500, 'disabled'=>403][$result['code']] ?? 200
Envelope   → $legacy = $result; unset($legacy['success']); $legacy['message'] = $msg
Sukses     → $result['new_balance'] = Wallet_model->get_balance($user_id)
             $result['milestones']   = User_model::get_milestone_status($user_id)  (payload segar)
             Notification_model::insert_keyed($user_id,'notif_milestone',
                 [number_format(amount,0,',','.'), (string) $target], 'commission')
             api_success($data, $msg, 200, $legacy)
Gagal      → $status === 200
             ? api_error($msg, 200, [], $result['code'], $legacy)   # already_claimed / locked_previous /
                                                                    # not_qualified / invalid_target / user_unavailable
             : api_error($msg, $status, [], $result['code'], $legacy)  # error ⇒ 500 · disabled ⇒ 403
             (satu jalur keluar; `$msg` FINAL sudah di-assign ke `$legacy` di atas — akar F18 tertutup)
```
**Fail-closed `disabled`:** `code === 'disabled'` → HTTP **403** (parity `Checkin::claim()` plan/112).

### 6.4 `_milestone_message(array $result): string` (BARU) — peta lengkap, satu titik tulis
| `code` | key kamus |
|---|---|
| `claimed` | `team_ms_js_success` |
| `already_claimed` | `team_ms_js_already` |
| `locked_previous` | `team_ms_js_locked` |
| `not_qualified` | `team_ms_js_not_qualified` |
| `invalid_target` | `team_ms_js_invalid` |
| `disabled` | `team_ms_js_disabled` |
| `user_unavailable` | `team_err_user_unavailable` (key lama dipakai ulang) |
| `error` | `rental_err_claim_failed` |

Kontrak anti-F18 yang **diuji** (§13.3): `$legacy['message']` di-`assign` **setelah** pesan
final diketahui; **tidak** ada fallback diam-diam untuk `claimed`.

### 6.5 `_claim_message()` (`:339-350`) — **tidak diubah** (jalur L1, O1)

---

## 7. Member UI — `application/views/team/index.php`

### 7.1 Kartu "BONUS PENCAPAIAN TIM" (menggantikan `:82-143`, memakai blok `:9-30` sebagai data)

Anatomi (mobile-first, `u-card-fin`, ikon Font Awesome, tanpa aset baru):

1. **Header** — ikon `fa-trophy` + judul `team_ms_title`; chip kanan
   `team_ms_once` ("Sekali seumur hidup"); sub-judul `team_ms_sub`.
2. **Ringkasan progres** — label `team_ms_progress_label` ("Total downline aktif (semua level)"),
   nilai `number_format($milestones['total_active'])`, pembanding target berikutnya
   (`team_ms_progress_to` = `"dari %d"`; bila semua tercapai → `team_ms_all_claimed`),
   progress bar lebar = `min(100, intdiv(total_active * 100, next_target))` (**integer**, tanpa float).
3. **Daftar tier** (`foreach ($milestones['tiers'])`) — satu baris per tier:
   - Kolom kiri: target (`team_ms_target_row` = `"%d downline aktif"`) + nominal
     (`'Rp ' . number_format($tier['amount'], 0, ',', '.')` — **dinamis, bukan di kamus**, L6).
   - Kolom kanan (badge status):
     - `claimed` → hijau + `fa-check-circle` + `team_ms_claimed` (teks "permanen",
       `team_ms_claimed_hint` tooltip).
     - `claimable` → tombol **Klaim** `id="btn-claim-ms"` `data-target="{target}"`,
       label `team_ms_claim_btn` = `"Klaim Bonus %s"` (sprintf nominal).
     - `locked_count` → abu + `fa-lock` + `team_ms_need_more` (`"%d downline lagi"`, `missing`).
     - `locked_previous` → abu + `fa-flag` + `team_ms_locked_prev`
       ("Selesaikan tier sebelumnya lebih dulu").
   - **Maksimal satu** tombol aktif (konsekuensi O2) — ditegakkan server via `claimable_target`.
4. **State degenerat:**
   - `enabled = false` → **blok kartu tidak dirender sama sekali** (fail-closed, parity widget
     absensi plan/112). Tidak ada `div` kosong, tidak ada tombol mati.
   - `enabled = true` tetapi `tiers` kosong → kartu dirender dengan pesan `team_ms_empty`
     (admin belum mengatur) dan **tanpa** tombol.
5. **Tanpa animasi baru** → kewajiban `prefers-reduced-motion` existing tetap terpenuhi
   (blok ini memakai transisi `active:scale` yang sudah ada di kartu lain).

### 7.2 Modal bantuan (`:505-519`) — loop dinamis
- Judul `team_ms_help_title`; paragraf `team_ms_help_body` (menegaskan definisi downline
  aktif = kontrak produk **berbayar**, konsisten `team_help_active_body`).
- Baris tier **di-loop** dari `$milestones['tiers']` dengan `team_ms_help_row` =
  `"%1\$d downline aktif → %2\$s"` (nominal via `number_format`).
- Catatan `team_ms_help_note`: klaim **berurutan dari tier terendah**, **sekali seumur hidup**,
  tanpa cooldown; klaim permanen walau jumlah downline turun.
- **Dihapus:** 5 baris nominal hardcoded (`Rp 200.000` … `Rp 9.000.000`) dan key
  `team_help_wage_*` (§10).

### 7.3 Blok `const I18N` (`:532-551`) & JS klaim (`:640-690`)
- Ganti key wage → key milestone + hapus key cooldown/mingguan:
  `ms_success`, `ms_already`, `ms_locked`, `ms_not_qualified`, `ms_invalid`, `ms_disabled`,
  `ms_claiming` (fallback `js_processing`), `ms_reload_hint` (opsional).
- `function claimMilestone(btn)`:
  1. Guard `btn.disabled`; set state loading (`fa-spinner` + `js_processing`).
  2. `FormData` + `fd.append('target', btn.dataset.target)` → `csrfFetch(site_url('team/claim_milestone'), {method:'POST', body: fd})`.
  3. `r.json()` → `code = d.code || (d.success ? 'claimed' : 'error')`.
  4. Sukses (`claimed`): toast `d.message || I18N.ms_success`; update `#balance-display`
     dari `d.new_balance` (parity `claim_level1`/`claim_wage`); **repaint** daftar tier dari
     `d.milestones.tiers` lewat helper kecil `paintMilestones(rows)` (satu-satunya tempat
     markup baris tier dibuat) → memunculkan status `claimed` + tier berikutnya tanpa reload.
  5. `already_claimed` / `locked_previous` / `not_qualified` / `invalid_target` / `disabled`:
     toast error + re-enable tombol (atau render state dari `d.milestones` bila ada).
  6. `.catch` → `I18N.network_error` + re-enable (parity pola lama).
- Nol `innerHTML` dari string server tanpa escape: nominal & target diformat di klien dari
  angka (bukan prosa server).

---

## 8. Panel Admin — `/admin/settings`

### 8.1 `application/controllers/Admin.php::settings()` (`:375-625`)

**POST (validasi → all-or-nothing → audit → persist):**
1. Kumpulkan transport array: `$this->_collect_milestone_rows()` (baru, pola
   `_collect_tier_rows()`): pasangkan `tm_target[]` ↔ `tm_amount[]` per indeks
   (`missing`/`extra` = error per baris).
2. `$ms_raw = ['team_milestone_enabled' => $this->input->post('team_milestone_enabled'),
   'team_milestone_tiers' => $rows];`
3. `$mv = $this->User_model->validate_milestone_settings($ms_raw);` → `$errors = array_merge($errors, $mv['errors'])`.
4. Pola lama tetap: bila `$errors` → `flashdata('error', 'Validasi gagal: …')` +
   `flashdata('settings_form_state', $this->_settings_form_state($tier_rows, $errors, …
   + $mv['field_errors']))` → `redirect('admin/settings')` (**nol key tersimpan**).
5. `$final = array_merge($contact, $v['values'], $rv['values'], $cv['values'], $mv['values']);`
   → snapshot `before` per key → `$changed` → `_audit_ctx(null,'admin_update_settings',
   ['keys'=>…,'before'=>…,'after'=>…,'auto_adjusted'=>$auto_notices])`
   → `Admin_model::update_system_settings()` (satu TX, audit atomik). **Tidak ada model baru**;
   key `team_milestone_*` otomatis muncul di `keys`/`before`/`after` (audit plan/110 D2).

**GET (render):**
- `$ms = $this->User_model->get_milestone_config();`
- `$data['team_milestone_enabled'] = $ms['enabled'] ? 1 : 0;`
- Baris editor: `$ms_rows =` state form (`tm_rows`) → config (`$ms['tiers']` dipetakan ke
  `[['target'=>…,'amount'=>…]]`, tanda `__EMPTY__` untuk baris baru) — pola `tier_rows` plan/110.
- `flashdata` repopulasi dibaca seperti blok finansial (`$form_state`/`$field_errors`).
- **Catatan:** `User_model` sudah di-load DI controller admin? → **belum**: tambahkan
  `$this->load->model('User_model');` di awal `settings()` (satu baris; `Admin` saat ini
  memuat `Admin_model`, `Wallet_model`, `Rental_model`, `Checkin_model`).
  **Invarian L1 tetap:** tidak ada `i18n_apply()`, semua label Indonesia.

### 8.2 `application/views/admin/settings.php` — **Card 7** (setelah Card 6, `:446-…`)

- Judul: **“Bonus Pencapaian Tim (Team Milestone Bonus)”** + ikon `fa-trophy`;
  letak: **full width di dalam `form_open('admin/settings')`** (CSRF & submit menyatu).
- Isi:
  1. Checkbox `team_milestone_enabled` (label **“Aktifkan Bonus Pencapaian Tim”**) +
     catatan: bila nonaktif → kartu member tidak dirender & endpoint menolak (fail-closed).
  2. **Editor baris tier**: tabel dengan kolom **Target (downline aktif)** `tm_target[]`
     (`type="number" min="1" max="100000" step="1"`) dan **Nominal (Rp)** `tm_amount[]`
     (`type="number" min="1" max="100000000" step="1"`), tombol **Tambah Baris** /
     **Hapus Baris** (JS kecil, tidak menambah dependensi CDN) — pola tombol
     “Rapikan Tier” plan/110.
  3. Kotak bantuan berbahasa Indonesia: definisi *downline aktif* (kontrak aktif produk
     **non-trial**, plan/116), aturan **sekali seumur hidup**, **klaim berurutan**,
     pembayaran ke **Saldo Utama**, dan peringatan bahwa **mengubah tier berlaku seketika**
     (tier yang sudah diklaim permanen; menghapus tier membuat nominalnya tidak dapat
     diklaim lagi).
  4. **Error inline** di kartu ini saja: `$milestone_errors = $error_flat(filter prefix 'team_milestone_' | 'tm_')`
     (pola `$checkin_errors` `:52-60`), plus nilai repopulasi `$state_val($fs, 'tm_rows', …)`.
- **Tanpa** key kamus (admin 100% Indonesia, L1).

---

## 9. Skema & Migrasi

### 9.1 `database.sql`

1. **`users`** — sisipkan **setelah** `last_wage_claimed_at` (`:32`):
   ```sql
   -- plan/119: STATE KLAIM Bonus Pencapaian Tim (CSV target integer menaik,
   -- contoh '9,30'; NULL/'' = belum ada). Satu-satunya penulis:
   -- User_model::claim_milestone() di dalam TX terkunci (UPDATE kondisional
   -- + guard affected_rows() === 1 — pola M4). Klaim bersifat PERMANEN
   -- (tanpa clawback). Baris pembayaran tetap di wallet_ledger
   -- (transaction_id 'MSB-{user_id}-T{target}', wallet_type 'main').
   `team_milestone_claimed` VARCHAR(255) NULL DEFAULT NULL,
   ```
   `last_wage_claimed_at` diberi anotasi **DEPRECATED (plan/119)** — dipertahankan sebagai
   retensi historis, tidak lagi ditulis (D10).
2. **Blok seed `system_settings`** (`:408-457`) — tambahkan:
   ```sql
   -- plan/119: Bonus Pencapaian Tim (pengganti Gaji Mingguan). `enabled` = gerbang
   -- fail-closed ('0' → kartu member tidak dirender + endpoint menolak).
   -- `tiers` = JSON [[target_downline_aktif, nominal_idr], …] kanonik MENAIK.
   -- Fallback per-key: application/config/team_milestones.php.
   ('team_milestone_enabled', '1'),
   ('team_milestone_tiers', '[[9,200000],[30,1000000],[70,2500000],[130,5000000],[190,9000000]]');
   ```
   (Tanda `;` penutup pindah dari `checkin_require_active_rental` ke baris terakhir baru.)
3. **Blok komentar “MIGRASI LIVE”** baru (pola blok plan/112 `:700-735` / plan/114 `:737-…`):
   referensi `ALTER TABLE` + seed `INSERT IGNORE`, perintah `--dry-run`/`--apply`/`--verify`,
   query verifikasi invarian, dan catatan tamper (exit 2).
   **⚠️ Aturan F17:** `AFTER` **hanya** di `ALTER TABLE … ADD COLUMN`, **tidak pernah** di
   `CREATE TABLE` (positional cukup dengan menempatkan baris pada urutan yang benar).

### 9.2 `database_seed.sql`

- Blok `[RECONCILE]` (`:8-40`): tambahkan
  `ALTER TABLE `users` ADD COLUMN `team_milestone_claimed` VARCHAR(255) NULL DEFAULT NULL;`
  (duplikat `1060` pada DB yang sudah termigrasi = **toleran**, sesuai catatan runner).
- Blok seed `system_settings` (`:308-…`): dua key yang sama (`INSERT IGNORE`).

### 9.3 `scripts/migrate_119_team_milestone.php` (BARU)

Kontrak standar repo: `--dry-run` (**default**) / `--apply` / `--verify` / `--skip-backfill` /
`--help`, idempoten, exit **0/1/2**, `SET time_zone = '+07:00'`, loader kredensial
**`$active_group → 'default' → grup pertama`** (pola `migrate_106/112/114/117/118` —
**bukan** `$db['default']` yang diketahui rusak), `function_exists()`-guarded untuk helper.

| Fase | Isi | Idempotensi |
|---|---|---|
| **0 PRE-FLIGHT** | Baca kredensial; deteksi versi MariaDB/MySQL; periksa keberadaan kolom & key via `information_schema`; pastikan tabel `users`/`system_settings` ada. Gagal → exit 1. | read-only |
| **1 DDL** | `ALTER TABLE users ADD COLUMN team_milestone_claimed VARCHAR(255) NULL DEFAULT NULL AFTER last_wage_claimed_at` — dijalankan **hanya** bila belum ada (MySQL 8 tidak punya `IF NOT EXISTS`; MariaDB pakai `IF NOT EXISTS`, kedua jalur lewat pemeriksaan `information_schema`). | guard `information_schema` ⇒ re-run = no-op |
| **2 SEED** | `INSERT IGNORE INTO system_settings (key_name,key_value) VALUES ('team_milestone_enabled','1'),('team_milestone_tiers','[[…]]')` — **tidak pernah** menimpa nilai yang sudah diubah admin; JSON dinormalisasi dulu lewat `team_milestone_tiers_encode(team_milestone_tiers_parse(…))`. | `INSERT IGNORE` |
| **3 BACKFILL (O3)** | Grandfathering §3.4: hitung `reached` per user dari baris `WAGE-%`, lalu `UPDATE … SET team_milestone_claimed = ? WHERE id = ? AND team_milestone_claimed IS NULL`. Dilewati bila `--skip-backfill`. | guard `IS NULL` ⇒ re-run = 0 baris |
| **4 VERIFY** | (a) kolom ada + tipe & nullability sesuai; (b) 2 key ada; (c) `enabled ∈ {'0','1'}`; (d) `tiers` parseable & lolos `team_milestone_tiers_valid()`; (e) seluruh nilai `team_milestone_claimed` parseable (token integer, unik, menaik) & proyeksi CSV ≤ batas; (f) nol tabel baru (inventory tetap 14). Tamper → **exit 2** + laporan. | read-only |
| **5 SUMMARY** | Cetak: DDL diterapkan/dilewati, key ditulis/no-op, distribusi backfill per tier, hasil verify. | — |

`--dry-run` mencetak rencana yang sama **tanpa** menulis (nol DDL/seed/backfill), termasuk
tabel 5 tier + jumlah user yang akan memperoleh tiap himpunan klaim.

---

## 10. i18n — Kamus EN/ID (`application/language/{english,indonesian}/app_lang.php`)

Kondisi awal: **651/651** key (kondisi plan/118). Aturan: paritas 1:1 (P1), tanpa literal
`Rp`+angka di kamus (P3), tanpa newline literal (P6). **Nol nominal tier di kamus** —
semua nominal dirender dinamis dari konfigurasi.

### 10.1 DITAMBAH (27 key × 2 idiom — termasuk `team_err_bonus_already`, §19 A2)

| Key | EN | ID |
|---|---|---|
| `team_ms_title` | Team Milestone Bonus | Bonus Pencapaian Tim |
| `team_ms_sub` | Reach active-downline milestones — the bonus is paid once per tier, for life. | Capai target downline aktif — bonus dibayar sekali per tier, seumur hidup. |
| `team_ms_once` | One-time, for life | Sekali seumur hidup |
| `team_ms_progress_label` | Total active downlines (all levels) | Total downline aktif (semua level) |
| `team_ms_progress_to` | of %d | dari %d |
| `team_ms_target_row` | %d active downlines | %d downline aktif |
| `team_ms_claim_btn` | Claim Bonus %s | Klaim Bonus %s |
| `team_ms_claimed` | Claimed | Sudah diklaim |
| `team_ms_claimed_hint` | Permanently claimed — cannot be claimed again. | Klaim permanen — tidak dapat diklaim ulang. |
| `team_ms_need_more` | %d more active downlines | Kurang %d downline aktif |
| `team_ms_locked_prev` | Finish the previous milestone first | Selesaikan tier sebelumnya lebih dulu |
| `team_ms_all_claimed` | All milestones claimed — congratulations! | Semua tier sudah diklaim — selamat! |
| `team_ms_empty` | The admin has not configured any milestone yet. | Admin belum mengatur tier bonus. |
| `team_ms_js_success` | Milestone bonus claimed successfully! | Bonus pencapaian tim berhasil diklaim! |
| `team_ms_js_already` | This milestone has already been claimed. | Tier bonus ini sudah pernah diklaim. |
| `team_ms_js_locked` | Claim the previous milestone first. | Klaim tier sebelumnya lebih dulu. |
| `team_ms_js_not_qualified` | Your active-downline count has not reached this milestone yet. | Jumlah downline aktif Anda belum mencapai tier ini. |
| `team_ms_js_invalid` | Invalid milestone. | Tier bonus tidak valid. |
| `team_ms_js_disabled` | The team milestone bonus is currently disabled. | Bonus pencapaian tim sedang dinonaktifkan. |
| `team_ms_help_title` | Team Milestone Bonus | Bonus Pencapaian Tim |
| `team_ms_help_body` | Every milestone pays **once for life** — no weekly cycle and no cooldown. Active downline = a downline holding an active contract from a paid product (trial contracts are not counted): | Setiap tier dibayar **sekali seumur hidup** — tanpa siklus mingguan dan tanpa cooldown. Downline aktif = downline pemegang kontrak aktif dari produk berbayar (kontrak trial tidak dihitung): |
| `team_ms_help_row` | %1$d active downlines → %2$s | %1$d downline aktif → %2$s |
| `team_ms_help_note` | Milestones are claimed in order, from the lowest. A claimed milestone is permanently locked, even if your active-downline count drops. | Tier diklaim berurutan dari yang terendah. Tier yang sudah diklaim terkunci permanen, walau jumlah downline aktif Anda turun. |
| `notif_milestone_title` | Team Milestone Bonus Credited | Bonus Pencapaian Tim Cair |
| `notif_milestone_body` | Congratulations! Your team milestone bonus of Rp %s for %s active downlines has been credited to your main balance. | Selamat! Bonus pencapaian tim sebesar Rp %s untuk %s downline aktif telah masuk ke saldo utama Anda. |
| `ledger_milestone` | Team milestone bonus — %s active downlines | Bonus pencapaian tim — %s downline aktif |
| `team_err_bonus_already` | This bonus has already been claimed. | Bonus ini sudah pernah diklaim sebelumnya. |

> Catatan P3: dua key di atas memuat pola `Rp %s` (placeholder, **bukan** digit) — **lolos**
> gate P3 yang hanya menolak `Rp` **diikuti digit** (`audit_i18n_parity.php:70-84`).
> Selaras dengan `notif_wage_body` yang sudah lama ada.

### 10.2 DIPERTAHANKAN (retensi historis — D10, **jangan dihapus**)

| Key | Alasan |
|---|---|
| `ledger_wage` | baris `wallet_ledger` historis `WAGE-*` dipetakan ke key ini (`i18n_helper.php:441-444`). |
| `notif_wage_title`, `notif_wage_body` | baris `user_notifications` live ber-`title_key = 'notif_wage'` (rendered `vsprintf`). |
| `team_err_user_unavailable` | dipakai bersama `claim_level1`, `_milestone_message`, `Promoter_model`. |
| `team_err_not_qualified` | dipakai `Team::_claim_message()` (jalur L1, O1). |

### 10.3 DIHAPUS (28 key khusus UI wage — terverifikasi **nol** konsumen lain & nol baris DB merujuk)

`team_weekly_wage` · `team_wage_sub` · `team_claim_wage_btn` · `team_cooldown_next` ·
`team_badge_level` · `team_no_level2` · `team_level_2_6` · `team_level6_reached` ·
`team_toward_level` · `team_req_min9` · `team_help_wage_title` · `team_help_wage_body` ·
`team_help_wage_l2` · `team_help_wage_l3` · `team_help_wage_l4` · `team_help_wage_l5` ·
`team_help_wage_l6` · `team_help_wage_note` · `team_js_claim_wage` · `team_js_wage_claimed` ·
`team_js_wage_success` · `team_js_weekly_already` · `team_js_claimed_this_week` ·
`team_js_cooldown_active` · `team_js_cooldown_msg` · `team_err_wage_cooldown` ·
`team_err_wage_not_qualified` · `user_wage_next_in` (**orphan** — nol pemakaian di seluruh aplikasi).

**Verifikasi daftar (wajib di E11):** `grep -o "^\$lang\['[a-z0-9_]*'\]" application/language/indonesian/app_lang.php
| grep -iE "wage|weekly|cooldown"` → **hanya** `ledger_wage`, `notif_wage_title`,
`notif_wage_body` yang tersisa (3 key retensi historis, §10.2).

**Delta bersih: 651 − 28 + 27 = 650/650** — angka **final dihitung dari berkas** saat
eksekusi (`grep -c '^\$lang\['` pada kedua idiom; AGENTS memperingatkan agar tidak percaya
angka ringkasan mana pun).

### 10.4 Helper i18n

`application/helpers/i18n_helper.php`: tambah **1 pola** di `$patterns`
(`i18n_ledger_description()`, setelah pola wage `:441-444`):
`'/^Bonus Pencapaian Tim (\d+) Downline Aktif$/u' => 'ledger_milestone'`.
Pola wage **dipertahankan** (D10).

---

## 11. Sinkronisasi Dokumen (`docs/`)

| Berkas | Perubahan |
|---|---|
| `docs/1_PRD.md` | (a) §G: ganti blok “Agency Rules & Rewards (ground truth `WAGE_TIERS`)” → tabel tier **dinamis** dengan ground truth `system_settings.team_milestone_tiers` + `application/config/team_milestones.php` (kode otoritatif) dan catatan “sekali seumur hidup, bukan per minggu”; (b) §G: ganti blok “Klaim Gaji Mingguan (Weekly Wage)” → **“Klaim Bonus Pencapaian Tim”** (POST `/team/claim_milestone`, tanpa cooldown/cron, sekuensial, dibuat/ditegakkan di dalam TX, ledger `MSB-{uid}-T{target}`, kredit Saldo Utama, fail-closed `disabled` 403, klaim permanen tanpa clawback); (c) §G: hapus klausa “cukup untuk membuka Gaji Mingguan Level 2” di paragraf Active Downline → ganti menjadi “membuka tier Bonus Pencapaian Tim”; (d) §H: tambah `notif_milestone_*` di daftar notifikasi `commission` + catatan bahwa `notif_wage_*` hanya untuk baris historis; (e) blok catatan versi di kepala dokumen (+ catatan sinkronisasi plan/119). |
| `docs/2_ERD.md` | (a) `users`: dokumen kolom `team_milestone_claimed` (kontrak CSV, penulis tunggal, permanen) + tandai `last_wage_claimed_at` **DEPRECATED (plan/119, retensi)**; (b) `wallet_ledger`: daftarkan pola `MSB-{user_id}-T{target}` bersama pola id deterministik lain; (c) §7: blok **“Invariant arsitektural tambahan (plan/119 — Team Milestone Bonus)”** berisi **MK1–MK8**; (d) kepala dokumen → **v5.4** + catatan sinkronisasi. |
| `docs/3_ROADMAP.md` | (a) catatan sinkronisasi baru di kepala (plan/119 menggantikan Gaji Mingguan; kode otoritatif; PRD/ERD v5.4); (b) bagian baru **“Bonus Pencapaian Tim (Team Milestone Bonus) — menggantikan Gaji Mingguan ✅ COMPLETED (plan/119)”** dengan 8 bullet (skema, helper/config, mesin klaim, controller, UI member, admin, migrasi+backfill, kamus) + penunjuk `plan/119_TEAM_MILESTONE_BONUS_SUMMARY.md`; (c) anotasi **SUPERSEDED (plan/119)** pada Phase 7B2 “Weekly Wage” (`:85-88`) dan pada bullet plan/116 yang menyebut *“otoritas level gaji mingguan”* — **tanpa** menghapus teks historis; (d) catatan penunjuk rename fixture (plan/116 bullet) → `scripts/seed_milestone_test_account.php`. |
| `docs/4_UI_UX_GUIDELINES.md` | Verifikasi nol rujukan wage (grep terverifikasi) → **tidak ada perubahan wajib**; bila muncul istilah kartu `/team` saat eksekusi, perbarui namanya menjadi “Bonus Pencapaian Tim”. |
| `plan/107_VIRAL_PROMOTIONAL_COPYWRITING_KIT.md` | **Materi promosi kini keliru** (`:39` tabel ground truth, `:183` baris nominal, `:255` klaim “Rp 9.000.000/minggu”): refresh angka → **“bonus sekali seumur hidup per tier”** dan **hapus** klaim periode mingguan; bila owner menghendaki kit tetap sebagai arsip, beri blok **“⚠️ HISTORIS — digantikan plan/119”** di kepala berkas. Aturan anti-scam kit (tanpa “dijamin untung”, wajib menyebut nominal sebagai *potensi*) tetap berlaku. |
| `AGENTS.md` (Notes) | (a) perbarui rujukan fixture `seed_wage_test_account.php` → `seed_milestone_test_account.php` (penunjuk nama); (b) hapus catatan **“Known open bug — weekly-wage success message (F18)”** → ganti dengan catatan **FIXED (plan/119, dihapusnya `claim_wage()`)**; (c) tambahkan catatan fitur plan/119 (2 key + 1 kolom + pola `MSB-`). |
| `plan/119_TEAM_MILESTONE_BONUS_SUMMARY.md` | **Dibuat saat eksekusi** (konvensi repo: pasangan PLAN + SUMMARY) — memuat bukti per langkah (perintah + hasil) termasuk output backfill. |

---

## 12. Directives Eksekusi (E1 … E16)

| # | Langkah | Berkas/objek | Gate |
|---|---|---|---|
| **E1** | Branch `feat/119-team-milestone-bonus`; catat baseline: `grep -c '^\$lang\['` (2 idiom), `git status` bersih untuk berkas yang akan disentuh. | — | baseline tercatat |
| **E2** | Skema kanonik: kolom `users.team_milestone_claimed` + 2 seed `system_settings` + blok “MIGRASI LIVE” + anotasi DEPRECATED `last_wage_claimed_at`. | `database.sql` | `php -l` (n/a SQL) · re-import bersih |
| **E3** | Cermin seed/ALTER di dataset seed. | `database_seed.sql` | import bersih (dup 1060 toleran) |
| **E4** | Fallback config baru. | `application/config/team_milestones.php` | `php -l` |
| **E5** | Helper struktural + autoload. | `application/helpers/team_milestone_helper.php`, `application/config/autoload.php:117` | `php -l` ×2 · gate hardcoded (helper **tanpa** copy) |
| **E6** | Model: hapus mesin wage (`WAGE_TIERS`/`determine_wage_level`/`check_wage_cooldown`/`claim_wage`/`_wage_result`), tambah `get_milestone_config()`/`_resolve_milestone_config()`/`validate_milestone_settings()`/`get_milestone_status()`/`claim_milestone()`/`_milestone_result()`, rapikan `get_claim_data()`. | `application/models/User_model.php` | `php -l` · grep: nol sisa `WAGE_TIERS`/`determine_wage_level`/`check_wage_cooldown`/`claim_wage` |
| **E7** | Controller: hapus `claim_wage()` + `_wage_message()`; tambah `claim_milestone()` + `_milestone_message()` (peta LENGKAP, F18); `index()` inject `milestones`; tambah `$this->load->model('User_model')` di `Admin::settings()`; perbarui komentar rujukan usang “pola `Team::claim_wage`” di `Checkin.php:7, 59` dan `Checkin_model.php:372` → “pola `Team::claim_milestone`”. | `application/controllers/Team.php`, `application/controllers/Admin.php`, `application/controllers/Checkin.php`, `application/models/Checkin_model.php` | `php -l` ×4 |
| **E8** | UI member: kartu milestone + modal bantuan dinamis + `I18N` + `claimMilestone()`/`paintMilestones()`; hapus blok wage & nominal hardcoded. | `application/views/team/index.php` | gate hardcoded 0 temuan |
| **E9** | Admin POST/GET: `_collect_milestone_rows()`, validasi `User_model::validate_milestone_settings()`, state repopulasi, injeksi GET. | `application/controllers/Admin.php` | `php -l` |
| **E10** | Card 7 admin (100% Indonesia, editor baris, error inline). | `application/views/admin/settings.php` | gate hardcoded (admin dikecualikan) · nol `lang()` baru |
| **E11** | Kamus EN/ID: +26 / −28 (paritas 1:1), tambah pola `ledger_milestone`. | `application/language/{english,indonesian}/app_lang.php`, `application/helpers/i18n_helper.php` | `audit_i18n_parity.php` exit 0 · `audit_i18n_hardcoded.php` 0 temuan |
| **E12** | Migrasi CLI (5 fase: pre-flight/DDL/seed/backfill/verify + summary), loader kredensial **benar**. | `scripts/migrate_119_team_milestone.php` | `php -l` · `--dry-run` · `--apply` · re-`--apply` no-op · `--verify` exit 0 · tamper → exit 2 |
| **E13** | Fixture: rename + retarget ke milestone (klaim per tier, sekuensial, eksklusi trial tetap diuji). | `scripts/seed_wage_test_account.php` → `scripts/seed_milestone_test_account.php` | `php -l` · jalankan `--downlines=9 --product=paid` / `--product=trial` pada DB buang |
| **E14** | Sinkronisasi dokumen (§11) termasuk penunjuk historis & kit promosi. | `docs/1_PRD.md`, `docs/2_ERD.md`, `docs/3_ROADMAP.md`, `docs/4_UI_UX_GUIDELINES.md`, `plan/107_*.md`, `AGENTS.md` | tinjauan manual: nol klaim “per minggu” yang masih aktif |
| **E15** | Matriks verifikasi penuh (§13) pada **DB buang** (bukan `db_webtable`) + bersihkan `application/logs/*.php` sebelum kedua gate i18n. | — | semua tabel §13 hijau |
| **E16** | Ringkasan + commit bahasa Indonesia; hapus scratch (`/tmp`) & artefak runtime; tinjau `git diff` akhir (hanya perubahan yang dimaksud). | `plan/119_TEAM_MILESTONE_BONUS_SUMMARY.md` | diff bersih · nol berkas `system/` berubah |

---

## 13. Matriks Verifikasi

Prinsip: **kode otoritatif**, bukti = perintah + hasil yang diamati. Semua uji HTTP pada
**DB buang** yang dibangun dari `database.sql` → `database_seed.sql` (bukan `db_webtable`),
dengan `APP_BASE_URL` + `DB_*` env override.

### 13.1 Statis & gate wajib
| # | Perintah | Harapan |
|---|---|---|
| V1 | `php -l` pada **setiap** berkas PHP baru/dimodifikasi (E4–E13) | `No syntax errors detected` |
| V2 | `php scripts/audit_i18n_parity.php` | **exit 0** · P1 paritas EN≡ID · P3 nol `Rp`+digit · P5 allowlist |
| V3 | `php scripts/audit_i18n_hardcoded.php` | **exit 0 · 0 temuan** (bersihkan `application/logs/*.php` lebih dulu) |
| V4 | `grep -rn "WAGE_TIERS\|determine_wage_level\|check_wage_cooldown\|claim_wage\|last_wage_claimed_at" application/` | hanya sisa **retensi/komentar** yang sah: anotasi DEPRECATED (`database.sql`, `MY_Controller.php:22`, `Wallet_model.php:53`) dan pola renderer historis (`i18n_helper.php:442`); **nol** pemakaian fungsional & **nol** rujukan “pola `Team::claim_wage`” yang belum diperbarui (E7) |
| V5 | `grep -rn "team_milestone\|MSB-" application/ database*.sql scripts/` | semua titik sesuai §5–§9 (nol duplikat kebijakan: hanya helper + model + config + view + migrasi) |

### 13.2 Migrasi
| # | Perintah | Harapan |
|---|---|---|
| V6 | `php scripts/migrate_119_team_milestone.php --dry-run` | rencana tercetak; **nol** DDL/seed/backfill; exit 0 |
| V7 | `php scripts/migrate_119_team_milestone.php --apply` | kolom + 2 key dibuat; backfill melaporkan distribusi per tier; exit 0 |
| V8 | `php scripts/migrate_119_team_milestone.php --apply` (ulang) | **no-op**: 0 DDL, 0 key tertimpa, 0 baris backfill (guard `IS NULL`); exit 0 |
| V9 | `php scripts/migrate_119_team_milestone.php --verify` | exit 0 (kolom/2 key/JSON valid/CSV valid/14 tabel) |
| V10 | Tamper uji: `UPDATE system_settings SET key_value='{"a":1}' WHERE key_name='team_milestone_tiers';` → `--verify` | **exit 2** + laporan temuan; pulihkan nilai |

### 13.3 Endpoint & jalur uang (`POST /team/claim_milestone`)
| # | Skenario | Harapan |
|---|---|---|
| V11 | GET `/team/claim_milestone` | **404** (POST-only) |
| V12 | POST tanpa header AJAX | **404** (AJAX-only) |
| V13 | POST tanpa sesi (AJAX) | **401** + `code: unauthenticated` (JSON, bukan HTML) |
| V14 | Sesi valid, `total_active = 0`, `target=9` | 200 `{success:false, code:'not_qualified'}` + `missing` = 9; **0** baris `wallet_ledger`, `team_milestone_claimed` tetap `NULL` |
| V15 | `total_active = 9` (fixture `--downlines=9 --product=paid`), `target=9` | 200 `{success:true, code:'claimed'}`; `message` = kalimat **sukses** idiom aktif (**assert bukan** `rental_err_*` → **F18 tervalidasi**); 1 baris `wallet_ledger` `MSB-{uid}-T9`, `wallet_type='main'`, amount `200000`, description `Bonus Pencapaian Tim 9 Downline Aktif`; `users.balance` naik +200.000; `team_milestone_claimed='9'`; notifikasi `notif_milestone` dengan params `["200.000","9"]` |
| V16 | Ulangi V15 (replay) | 200 `{success:false, code:'already_claimed'}`; **tetap** 1 baris ledger; saldo tidak berubah |
| V17 | `total_active = 9`, tier 9 **belum** diklaim, `target=30` | 200 `code:'locked_previous'` — gerbang **sekuensial (langkah 6)** dievaluasi **sebelum** recount (langkah 7); **0** baris ledger |
| V17b | Setelah V15 (tier 9 diklaim), `total_active = 9`, `target=30` | gerbang sekuensial lolos → recount gagal → 200 `code:'not_qualified'` + `missing=21`; **0** baris ledger |
| V18 | `total_active = 30` (fixture `--downlines=30`), klaim `target=30` **sebelum** tier 9 | 200 `code:'locked_previous'`; **0** baris ledger; CSV tetap `NULL` (**O2/MK7**) |
| V19 | Klaim berurutan: `9` → `30` | keduanya `claimed`; CSV akhir `'9,30'`; 2 baris ledger; saldo naik 1.200.000 |
| V20 | `target` aneh: `7` (tidak ada di konfigurasi), `0`, `-1`, `abc`, `9.5`, `1e3`, `target[]=9` | semua → 200 `code:'invalid_target'`; **0** baris ledger (M8/MK4) |
| V21 | `/admin/settings`: `team_milestone_enabled` dimatikan → `/team` + POST klaim | kartu **tidak ada** di DOM (grep HTML) · endpoint **403** `code:'disabled'` · **0** baris ledger (fail-closed) |
| V22 | User banned (`is_banned=1`) | 200 `code:'user_unavailable'`; 0 baris ledger |
| V23 | 6× POST dalam 60 detik | hit ke-6 → **429** JSON (`rate_limit_json_response`), bukan HTML |
| V24 | Kualifikasi eksklusi trial: fixture `--downlines=9 --product=trial` → POST `target=9` | `code:'not_qualified'` (predikat K8 plan/116 utuh — **regresi plan/116 diuji**) |
| V25 | **Permanen (MK6):** setelah V15, paksa `UPDATE user_rentals SET status='completed', expired_at = NOW() - INTERVAL 1 DAY WHERE user_id IN (downline)` → muat `/team` + replay klaim | card tetap menampilkan tier 9 **claimed**; endpoint `already_claimed`; **0** baris ledger baru |
| V26 | Konsistensi saldo: `users.balance` vs `SELECT SUM(amount) FROM wallet_ledger WHERE user_id=? AND wallet_type='main' AND type='credit'` | selisih **0** (mirror cache benar) · `scripts/reconcile_balances.php --verify` bersih |
| V27 | Ledger historis utuh: `SELECT COUNT(*) FROM wallet_ledger WHERE transaction_id LIKE 'WAGE-%'` sebelum vs sesudah plan/119 | **identik** (nol mutasi historis) · halaman `/wallet` merender deskripsi lama ber-idiom aktif (`ledger_wage`) |
| V28 | Notifikasi historis: baris `user_notifications` ber-`title_key='notif_wage'` (seed) dirender di dropdown/history | teks `notif_wage_*` masih **ada** (nol key hilang / nol "Undefined") |

### 13.4 Admin settings
| # | Skenario | Harapan |
|---|---|---|
| V29 | POST tier valid (mis. 4 baris `3/5/9/11` dengan nominal) | 2 key tersimpan; audit `admin_update_settings` memuat `keys=['team_milestone_tiers']` + `before`/`after`; `/team` langsung menampilkan 4 tier (kartu baru tanpa deploy ulang) |
| V30 | POST invalid: target duplikat · menurun · `0` · non-numerik · > 20 baris · nominal `0`/`101.000.000` · baris separuh terisi | **all-or-nothing**: flash `Validasi gagal: …`; **nol** key berubah (before ≡ after di DB); form ter-repopulasi dengan nilai ketikan admin (transfer array) + error inline di Card 7 |
| V31 | POST menghapus tier yang **sudah** diklaim user (mis. hapus target `9`) | Key tersimpan; `/team` tidak lagi menampilkan baris itu; state CSV user tetap memuat `9` (invalid di konfigurasi → **diabaikan** parser, tidak fatal); replay klaim `9` → `invalid_target`; **0** baris ledger baru |
| V32 | Toggle `team_milestone_enabled` dari `'1'`→`'0'`→`'1'` | kartu member hilang→muncul; audit mencatat kedua transisi |

### 13.5 Fixture & UI
| # | Skenario | Harapan |
|---|---|---|
| V33 | `php scripts/seed_milestone_test_account.php --downlines=9 --product=paid` lalu `--product=trial` | idempoten (re-run bersih, rentang telepon `0812999900xx`); blok verifikasi mencetak `count_all_active_downlines` berbayar 9 / trial 0 (bukti eksklusi) |
| V34 | `/team` (mobile 360 px) | kartu milestone: nol overflow horizontal, maksimal **satu** tombol klaim aktif, badge status sesuai perhitungan server, `lang` sesuai idiom aktif (`en` default, `id` setelah switch) |
| V35 | `/team` saat `tiers = []` (admin kosongkan) | kartu dirender dengan `team_ms_empty`, **tanpa** tombol, nol error PHP |
| V36 | Modal bantuan `/team` | baris tier = hasil loop konfigurasi (bukan hardcoded), nominal ter-`number_format`, tanpa nominal hardcoded tersisa di markup |
| V37 | Setelah klaim sukses (V15) via browser | toast pesan **sukses** (bukan gagal), Balance Pill di header bertambah (saldo utama), daftar tier **repaint** tanpa reload |

---

## 14. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| **Klaim ganda** (race / double-tap) | uang keluar dua kali | 3 lapis: CSV state via `UPDATE` kondisional `<=>` + guard `affected_rows()===1`; UNIQUE `uk_wallet_ledger_user_tx_type` pada `MSB-…`; recount di dalam TX terkunci (MK2/MK5). Duplikat `1062` ditranslasi ke `already_claimed` (nol 500/HTML). |
| **Kuantitas bayaran meledak** setelah admin mengubah tier (mis. 3/5/9/11 dengan nominal besar) | liabilitas tak terkendali | Batas administratif (MK6) + pemetaan `changed`/`before`/`after` di audit sehingga setiap perubahan nominal **terlacak**; toggle `team_milestone_enabled` sebagai kill switch **fail-closed**. |
| **Grandfathering salah hitung** | user dirugikan / dibayar dobel | Backfill **hanya** menambah state klaim (tidak pernah mengurangi, tidak pernah menulis uang); guard `IS NULL` ⇒ idempoten; `--dry-run` mencetak distribusi sebelum `--apply`; `--skip-backfill` sebagai jalan keluar. |
| **Konfigurasi rusak** (JSON tamper / migrate parsial) | fatal request / tier hilang | `get_milestone_config()` fail-safe per-key + fallback config file + `log_message('error')`; `--verify` exit 2 pada drift; nol jalur uang dipengaruhi (hanya klaim yang ditolak). |
| **Regresi plan/116 (trial dihitung aktif)** | tier terbuka tanpa modal | Predikat tidak disentuh (MK1); **V24 wajib** (fixture trial-only harus `not_qualified`). |
| **Data historis hilang** (hapus key kamus / hapus kolom) | deskripsi ledger & notifikasi lama rusak | D10: `ledger_wage`, `notif_wage_*`, pola regex wage, dan kolom `last_wage_claimed_at` **dipertahankan**; V27/V28 mengujinya. |
| **Bug F18 berulang** | copy sukses menampilkan "gagal" | `_milestone_message()` memuat `'claimed'`; `message` legacy di-assign **setelah** cabang sukses; V15 meng-assert teks sukses. `claim_wage()` (sumber bug) dihapus. |
| **CSV state melebihi 255 char** (konfigurasi ekstrem) | `UPDATE` terpotong / error DB | Batas `max_tiers`/`max_target` + validator menolak proyeksi CSV > 200 karakter (§3.2, MK6). |
| **Materi promosi basi** (`plan/107`) | klaim periode mingguan tersebar | §11 wajib: refresh/beri blok HISTORIS sebelum publikasi ulang. |
| **Gate i18n gagal karena log runtime** | gate merah palsu | AGENTS: bersihkan `application/logs/*.php` sebelum `audit_i18n_hardcoded.php` (E15). |

---

## 15. Non-goals (sengaja di luar lingkup)

1. **Bonus Level 1** (`claim_level1()`, `LEVEL1_BONUS`, `is_level_1_claimed`, kartu Misi L1) —
   tidak diubah (O1).
2. **Rebate 3-tier** (`Rental_model::_distribute_rebate()`), **Program Promotor**,
   **referral gating**, **ROI**, **absensi harian**, **dompet `checkin`** — nol perubahan.
3. **Clawback / pembatalan klaim** bila jumlah downline turun — ditolak by design (MK6).
4. **Halaman riwayat klaim milestone** (halaman/daftar khusus) — riwayat tersedia lewat
   `/wallet` (ledger) & `/notification`; tidak ada permukaan baru.
5. **Penghapusan kolom/ledger historis** `last_wage_claimed_at` / `WAGE-*` (retensi).
6. **Nominal/tier khusus per level agensi** (`level_id`) — status milestone 100% derived (D14).
7. **Perubahan `system/`**, `routes.php`, `views/templates/header.php`, skema tabel lain.
8. **Cron/scheduler** apa pun.

---

## 16. Definition of Done

1. Seluruh berkas PHP baru/dimodifikasi **lulus `php -l`** (bukti per berkas dilaporkan).
2. Kedua gate i18n **exit 0** (`audit_i18n_parity.php` paritas 1:1 & P1/P3/P5/P6;
   `audit_i18n_hardcoded.php` 0 temuan) — dijalankan **setelah** membersihkan log runtime.
3. **Nol dependensi baru / nol build step** — tidak ada yang perlu dipasang; verifikasi =
   `php -l`, dua gate i18n, dan matriks §13.
4. Migrasi `migrate_119_team_milestone.php`: `--dry-run` bersih, `--apply` sukses,
   **re-`--apply` = no-op**, `--verify` exit 0, uji tamper = exit 2.
5. Matriks §13 dijalankan pada **DB buang** dan dilaporkan per baris (perintah + hasil);
   **tidak ada** klaim "test suite dijalankan" (repo tidak punya test suite).
6. DDL ada di **`database.sql`** *dan* **`database_seed.sql`** *dan* migrasi idempoten.
7. Nol perubahan `system/`; nol hard-delete data uang; nol baris `wallet_ledger` historis
   berubah (`WAGE-*` utuh — V27).
8. `docs/` + `AGENTS.md` + `plan/107` tersinkron (§11); `plan/119_TEAM_MILESTONE_BONUS_SUMMARY.md`
   ada dan memuat bukti eksekusi.
9. `git diff` akhir hanya memuat perubahan yang dimaksud; nol scratch/artefak runtime.

---

## 17. Daftar Berkas yang Tersentuh

**Baru**
- `application/helpers/team_milestone_helper.php`
- `application/config/team_milestones.php`
- `scripts/migrate_119_team_milestone.php`
- `scripts/seed_milestone_test_account.php` (hasil **rename** dari `seed_wage_test_account.php`)
- `plan/119_TEAM_MILESTONE_BONUS_SUMMARY.md` (dibuat saat eksekusi)

**Dimodifikasi**
- `application/models/User_model.php`
- `application/controllers/Team.php`
- `application/controllers/Admin.php`
- `application/controllers/Checkin.php` (komentar rujukan usang saja)
- `application/models/Checkin_model.php` (komentar rujukan usang saja)
- `application/views/team/index.php`
- `application/views/admin/settings.php`
- `application/config/autoload.php` (1 helper)
- `application/language/english/app_lang.php`, `application/language/indonesian/app_lang.php`
- `application/helpers/i18n_helper.php` (1 pola ledger)
- `database.sql`, `database_seed.sql`
- `docs/1_PRD.md`, `docs/2_ERD.md`, `docs/3_ROADMAP.md`, `docs/4_UI_UX_GUIDELINES.md` (bila perlu)
- `plan/107_VIRAL_PROMOTIONAL_COPYWRITING_KIT.md`
- `AGENTS.md` (Notes: fixture, F18 FIXED, fitur plan/119)

**Tidak disentuh (ditegaskan)**
- `system/**` · `application/config/routes.php` · `views/templates/header.php` ·
  `application/models/Wallet_model.php` (permukaan `credit()` sudah cukup) ·
  `application/models/Rental_model.php` · `views/home/index.php` · `views/wallet/*` ·
  `views/marketplace/*` · `application/models/Checkin_model.php` & `Checkin.php`
  (**logika**; hanya komentar rujukan yang diperbarui di E7) ·
  `application/views/admin/templates/*`.

---

## 18. Aturan Pelaporan

1. Laporkan **perintah + hasil yang benar-benar dijalankan**; jangan mengklaim uji yang
   tidak dijalankan (repo tidak punya test suite — AGENTS).
2. Bila ada langkah yang tidak dapat dijalankan (tooling/DB tidak tersedia), tulis
   **status + alasan + perintah siap-jalan** di ringkasan; jangan menyembunyikan.
3. Setiap penyimpangan dari plan ini (mis. batas tier, nama key kamus, bentuk CSV) harus
   **dibuat keputusan eksplisit** dan dicatat di ringkasan + `docs/` sebelum commit.
4. Commit message dalam **bahasa Indonesia**, mengikuti pola repo:
   `feat(bonus): ganti gaji mingguan dengan bonus pencapaian tim terkonfigurasi (plan/119)`.
5. **Eksekusi hanya setelah perintah lanjutan owner** — approval atas plan ini **sudah**
   diberikan (Milestone Lock roadmap rule 7 terpenuhi); perintah “jalankan” tetap menjadi
   pemicu eksplisit. Sampai itu terjadi, repo harus tetap **nol-kode-berubah** untuk plan/119.

---

## 19. Addendum Eksekusi (penyimpangan yang diputuskan & dicatat)

Bagian ini adalah **catatan perubahan resmi** terhadap rancangan di atas — sesuai
aturan repo: setiap penyimpangan harus diputuskan eksplisit dan tercatat.
Status eksekusi: **SELESAI** (bukti lengkap di
`plan/119_TEAM_MILESTONE_BONUS_SUMMARY.md`).

| # | Rancangan | Realisasi | Alasan |
|---|---|---|---|
| **A1** | State tier non-`claimed`: `claimable` / `locked_previous` (butuh downline) / `locked_target` (tier bawah belum) | `claimable` / **`locked_count`** (butuh downline) / **`locked_previous`** (tier bawah belum) | Rancangan awal memakai `locked_previous` untuk makna yang **berlawanan** dengan `code` endpoint (`locked_previous` = "klaim tier sebelumnya dulu", §5.2d langkah 6). Satu kosakata untuk UI + API mencegah salah tafsir pada pemetaan JS. |
| **A2** | +26 key (termasuk `team_ms_none_yet`) | **+27 key** (tambahan `team_err_bonus_already`; `team_ms_none_yet` **dibatalkan** — tidak ada konsumen) | `Team::_claim_message()` (jalur Bonus L1) memetakan `already_claimed` → `team_js_weekly_already` yang **dipensiunkan** bersama mesin wage. Diganti key generik `team_err_bonus_already`; key milestone (`team_ms_js_already`) tetap khusus untuk jalur milestone. Delta final: **651 − 28 + 27 = 650/650**. |
| **A3** | Migrasi 5 fase | **6 fase** (pre-flight → inspect → DDL → seed → backfill → verify) + `--skip-backfill`, `--help` | Parity `migrate_118` (inspect terpisah memberi laporan kolom/key/`WAGE-`) + flag O3 yang tadinya tersirat. |
| **A4** | Fixture hanya di-rename & disesuaikan blok verifikasi | Fixture juga menulis `team_milestone_claimed = NULL` **bila kolomnya sudah ada** (`isset($userCols[...])`) | Fixture harus berjalan di DB pra-migrasi (kolom belum ada) **dan** pasca-migrasi. |
| **A5** | V13: sesi kosong → HTTP **401** `unauthenticated` | Teramati: **303 redirect ke `/login`** | Guard `MY_Controller` (`empty(user_id)` → `redirect('login')`) berjalan **sebelum** controller, juga untuk AJAX — parity seluruh endpoint member (`claim_level1`/`checkin/claim`). Cabang 401 di controller = **defense-in-depth** (tidak terjangkau HTTP). Bukti: bukan 200 + nol baris ledger + `Location: …/login`. |
| **A6** | V28: baris `user_notifications` live ber-`title_key='notif_wage'` | Seed repo memakai `title_key = NULL` (baris legacy) | Retensi diuji **dua bagian**: (a) `notif_wage_title/_body` masih ada di **kedua** kamus; (b) baris `title_key='notif_wage'` disintesis (setara data live) → `/notification` merender "Gaji Mingguan Cair" (ID) / "Weekly Wage Credited" (EN). Bukti ledger: `/wallet` user seed merender `WAGE-…` sebagai "Gaji Mingguan Level 2" (ID) / "Weekly wage - level 2" (EN). |
| **A7** | V35: `tiers = []` → pesan `team_ms_empty` | Teramati: konfigurasi dinamis kosong/rusak **fail-safe ke fallback repo** (5 tier), kartu tetap dirender | Itu memang perilaku yang dirancang (D4/§4: fallback per-key ≠ konfigurasi kosong). `team_ms_empty` tetap ada sebagai cabang defensif (hanya muncul bila fallback pun tidak valid). Bukti: `tiers='[]'` dan `tiers='{"a":1}'` → 5 baris tier dari fallback, tanpa error. |
| **A8** | V31 "tier dihapus" diuji pada konfigurasi default | Diuji mandiri: fixture 9 paid → klaim tier 9 → konfigurasi diganti `[[3,150000],[5,300000],[11,1500000]]` | Kasus sebelumnya bergantung pada urutan kasus lain; kini swasembada. |
| **A9** | Ambang `MAX_TIERS` dipilih 20 | **20** (final) | Anggaran CSV: `20 × 6 + 19 = 139` karakter ≤ 200 (`max_claimed_csv`) dan ≤ 255 (kolom). |
| **A10** | V37 (UX repaint) diuji manual di browser | Diuji **tanpa browser**: (a) seluruh blok `<script>` inline `/team` & `/admin/settings` lolos `node --check`; (b) `paintMilestones()` dieksekusi di Node dengan shim DOM + payload nyata → 4 baris, badge `claimed`, `Kurang 40 downline aktif`, **satu** tombol, `Rp 2.500.000`, bar 42% | Lingkungan eksekusi tidak punya browser; uji fungsional JS + parses tetap dijalankan. **Catatan:** verifikasi klik nyata (jaringan/CSRF/UX) belum dilakukan — rekomendasi QA manual singkat pada `/team`. |
| **A11** | V14/V17/V23/V24/V25/V29–V32 memakai "sesi login" | Member: fixture + **login nyata** (CAPTCHA dibaca dari berkas sesi CI3) pada matriks dasar; kasus lain memakai sesi CI3 yang dibentuk harness (`user_id`/`admin_id`) | Login otomatis tidak selalu praktis per kasus; sesi buatan memasuki jalur otorisasi yang sama (guard + `MY_Controller`). Login nyata tetap dibuktikan (V-warmup & fixture cases). |
