# Plan 89 — Mesin Rebate Komisi 3-Tier, Gating Kode Referral & Peringatan Upline Inaktif

> **Status:** BLUEPRINT (belum ada implementasi — menunggu instruksi lanjutan).
> **Cakupan:** (1) konfigurasi dinamis rebate via admin, (2) mesin distribusi
> komisi 3-tier di dalam `Rental_model::checkout_rental()`, (3) gating akses
> kode referral (Condition A/B/C), (4) peringatan upline inaktif di dashboard.
> **Keputusan pengguna (dec-326776f0a4f6130e):** gating referral diterapkan
> **in-place di Share Center halaman `/team`** (+ alias rute opsional
> `/referral`); penghitung lifetime/active rental **diturunkan via query**
> pada `user_rentals` — **nol perubahan skema** pada `users`.

---

## 1. Ringkasan & Tujuan

Ketika seorang member membeli paket GPU melalui `checkout_rental()`, platform
memberikan komisi otomatis kepada **hingga 3 level upline** (L1/L2/L3) dengan
persentase yang dikonfigurasi admin. Upline hanya menerima komisi bila ia
memegang **minimal satu kontrak sewa aktif** (`status='active'` DAN
`expired_at > now` WIB); bila inaktif, jatah tier-nya **hangus total
(breakage)** dan **tidak** di-pass-up ke level di atasnya.

Sebagai pasangan fitur:

- **Gating referral:** member yang **belum pernah menyewa** (lifetime
  rentals == 0) tidak melihat kode undangan/link/QR (Condition A — locked
  state). Setelah menyewa minimal 1 paket (lifetime ≥ 1), kode & link
  **permanen terlihat** (Condition B: lifetime ≥ 1 & aktif 0; Condition C:
  lifetime ≥ 1 & aktif ≥ 1).
- **Peringatan dashboard:** member dengan lifetime ≥ 1 namun **0 kontrak
  aktif** mendapat modal/alert menonjol di halaman beranda dengan CTA ke
  `/marketplace`, karena jaringan timnya tidak menghasilkan komisi apa pun
  selama ia inaktif.

Prinsip yang dipegang (konsisten dengan seluruh arsitektur Synapse):

| Kode | Prinsip | Sumber |
|---|---|---|
| Z1 | `wallet_ledger` immutable & satu-satunya sumber kebenaran finansial | AGENTS.md / plan/54 |
| C4 | Semua mutasi uang lewat `Wallet_model::credit()/debit()` (caller-TX participant), helper `_post()` sebagai satu-satunya gerbang tulis | plan/54 |
| C5 | Transaksi debit: `trans_begin()` → `lock_and_get_balance()` (anchor `users` FOR UPDATE) sebagai statement pertama | plan/48 |
| M3 | Kelayakan "aktif" SELALU memfilter `expired_at > ?` (bound param PHP WIB), tidak pernah hanya `status='active'` (expiry lazy per-request) | plan/60 |
| M5/A1 | Mutasi setting admin = audit atomik before→after per key (`system_audit_logs`) dalam TX yang sama | plan/64 |
| M7 | Seluruh pengaturan admin dalam SATU form/endpoint `/admin/settings` (`/admin/financial-settings` = redirect shim) | plan/70 |
| M8 | Uang IDR integer; aritmetika via `intdiv()`; `(int)` choke-point di `_post()` | plan/74 |
| M9/P7 | Endpoint AJAX lewat `api_success()/api_error()` | plan/76 |

---

## 2. Konfigurasi Dinamis (system_settings + UI Admin + Audit)

### 2.1 Key baru + default (seed idempotent)

Ditambahkan pada blok `INSERT IGNORE INTO system_settings` di `database.sql`
(baris seed saat ini: `is_registration_open` … `support_email`):

```sql
-- Plan 89: komisi rebate 3-tier (purchase rebate affiliate).
('rebate_enabled',     '1'),
('rebate_l1_percent',  '5'),
('rebate_l2_percent',  '3'),
('rebate_l3_percent',  '1'),
```

- `key_name` ≤ 50 karakter (`VARCHAR(50)`) ✓; `uk_key_name` membuat seed
  idempotent (INSERT IGNORE tidak menimpa nilai live).
- `rebate_enabled`: `'0'` (mati) / `'1'` (aktif). Default **1**.
- `rebate_l{1,2,3}_percent`: persen komisi, integer IDR-discipline **0–100**.
  Default 5 / 3 / 1.

### 2.2 Fallback + resolver bertipe (pola M1/plan/56)

File baru `application/config/rebate_commission.php` (fallback spek, dibaca
hanya bila baris dinamis hilang/invalid — persis pola `withdrawal_fees.php`):

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');
// Fallback PRD — nilai dinamis (system_settings) menang bila valid.
return [
    'rebate_enabled'    => 1,
    'rebate_l1_percent' => 5,
    'rebate_l2_percent' => 3,
    'rebate_l3_percent' => 1,
];
```

Resolver + normalizer diletakkan **di `Rental_model`** (pemilik domain mesin
rebate; analog: `Wallet_model` pemilik domain WD/deposit beserta
validatornya), dengan static per-request cache:

- `Rental_model::get_rebate_config()` → `{rebate_enabled:int, rebate_l1_percent:int, rebate_l2_percent:int, rebate_l3_percent:int}`
  - baca seluruh `system_settings` (`SELECT key_name, key_value`) → map;
  - merge fallback per-key: `enabled` hanya menerima `'0'`/`'1'`; tiap persen
    menerima string digit-only `^[1-9][0-9]*$|^0$` dalam rentang 0–100
    (normalizer `_norm_rebate_pct`, gaya `_norm_int` di Wallet_model);
  - nilai korup → `log_message('error')` + fallback key tsb (tidak pernah
    crash request);
  - cache `private static $_rebate_cfg` per-request.
- `Rental_model::validate_rebate_settings(array $raw)` (untuk form admin) →
  `{ok:bool, errors:string[], values:array<string,string>}` — aturan identik
  normalizer resolver + pesan error eksplisit per field, mis.:
  - "Persen komisi L1 harus angka bulat 0–100."
  - "Status rebate harus aktif atau nonaktif."

### 2.3 UI Admin (Card baru di form terpadu)

`/admin/financial-settings` **redirects** ke `/admin/settings` (M7, plan/70) —
maka kontrol rebate ditambahkan sebagai **Card 5 "Komisi Rebate 3-Tier
(Affiliate Purchase Rebate)"** di `application/views/admin/settings.php`,
dikirim lewat form `settingsForm` yang sama (satu submit = satu TX audit):

- checkbox `rebate_enabled` (value `1`, gaya `deposit_fee_enabled`);
- 3 input `type="number" name="rebate_l{1,2,3}_percent"` `min="0" max="100"
  step="1"` (integer), nilai awal dari `Rental_model::get_rebate_config()`;
- catatan UI: "Komisi dibayarkan otomatis ke upline L1–L3 yang memiliki sewa
  aktif saat downline membeli paket. Upline inaktif = jatah hangus
  (breakage)."

### 2.4 Alur simpan + audit (M5/A1 atomik — TANPA jalur baru)

`Admin::settings()` POST diperluas (bukan endpoint baru):

1. `$raw` ditambah 4 key rebate; panggil `Rental_model::validate_rebate_settings($raw)`;
2. error digabung dengan error kontak/finansial — **all-or-nothing** (satu
   error → tidak ada key yang disimpan);
3. `$final = array_merge($contact, $v['values'], $rv['values'])`;
4. snapshot `$before` per key → hitung `$changed` (hanya key berubah);
5. `$audit_ctx = $this->_audit_ctx(null, 'admin_update_settings', ['keys' => …, 'before' => …, 'after' => …])`;
6. `Admin_model::update_system_settings($final, $audit_ctx)` — persist semua
   key + baris `system_audit_logs` dalam **SATU transaksi** (pola yang sudah
   ada; audit aksi `admin_update_settings` kini mencakup key `rebate_*`
   sebelum→sesudah).

Tidak ada perubahan kontrak audit; hanya form + validator + key baru.
`Admin::financial_settings()` (redirect shim) tidak disentuh.

---

## 3. Mesin Distribusi Rebate 3-Tier di `checkout_rental()`

### 3.1 Urutan transaksi (sebelum → sesudah)

`checkout_rental()` saat ini (plan/83 + plan/87, GATE 0/2 dipertahankan):

```
1.  trans_begin()
2.  lock_and_get_balance($buyer)        // anchor users FOR UPDATE (C5)
3.  GATE 0: snapshot produk segar       // is_active=1; price/daily_rate/duration_days
4.  GATE 2: kuota lifetime max_per_user // status IN ('active','completed')
5.  Tolak overspend (fresh < price)     // rollback, code 'insufficient'
6.  Wallet_model::debit(buyer, price, 'RENT-…', 'Sewa …')
7.  INSERT user_rentals (active) → $rental_id
─── BARU (Plan 89) ─────────────────────────────────────────────
8.  BLOCK REBATE (lihat §3.2) — di dalam TX yang sama, SETELAH debit &
    insert kontrak, SEBELUM commit. Gagal → exception → catch luar →
    rollback SELURUH checkout (kontrak + debit + rebate batal atomik).
─── ────────────────────────────────────────────────────────────
9.  trans_commit()
```

Syarat penting: **blok rebate membutuhkan `$rental_id`** sehingga harus
berjalan setelah step 7. Seluruh jalur penolakan (produk unavailable, kuota
tercapai, saldo kurang, error internal) terjadi **sebelum** step 8, sehingga
**zero rebate rows** pada penolakan — diperkuat oleh rollback penuh pada
exception (Z1).

> Catatan: `Admin_model::inject_rental()` (tool admin) mem-bypass
> `checkout_rental` → **tidak** memicu rebate. Komisi hanya dari pembelian
> sungguhan lewat mesin checkout (dokumentasikan di docblock model).

### 3.2 Algoritma traversal (3 posisi, fail-closed)

Implementasi sebagai private helper `_distribute_rebate($buyer_id, $price_int,
$rental_id, $buyer_label)` yang dipanggil dari dalam TX:

```
1.  $cfg = get_rebate_config();
    if ((int)$cfg['rebate_enabled'] !== 1) return;        // engine mati → skip
    $pct = [1=>l1, 2=>l2, 3=>l3];
    $cur = parent_id pembeli  // SELECT parent_id FROM users WHERE id = $buyer_id
    $seen = [$buyer_id => true];                          // fail-closed: self-reference
2.  for tier = 1..3:                                       // TERIKAT 3 iterasi (loop runaway mustahil)
        if ($cur === null) break;
        $u = SELECT id, parent_id, phone, is_banned FROM users WHERE id = $cur
        if (!$u) { log error; break; }                     // rantai putus → sisa hangus
        if (isset($seen[$u->id])) { log error; break; }    // siklus/referensi ganda → fail-closed
        $seen[$u->id] = true;
        if ($u->id === $buyer_id) { log error; break; }    // self-reference → fail-closed
        $cur = $u->parent_id;                              // siapkan posisi berikutnya
3.      Kelayakan STRICT upline (M3):
        $eligible = SELECT 1 FROM user_rentals ur
                     WHERE ur.user_id = ? AND ur.status='active' AND ur.expired_at > ?
                     LIMIT 1                               // ? = date('Y-m-d H:i:s') PHP WIB
        if (!$eligible || (int)$u->is_banned === 1) continue;   // jatah hangus, TANPA pass-up
4.      $amount = intdiv($price_int * $pct[$tier], 100);   // M8: integer murni, TANPA float
        if ($amount < 1) continue;                         // _post() menolak amount <= 0
5.      Wallet_model::credit(
            (int)$u->id, $amount,
            'RBT-' . $rental_id . '-L' . $tier,            // deterministik
            'Komisi sewa GPU Level ' . $tier . ' dari ' . $buyer_label
        );                                                 // false → throw/rollback penuh
6.      Notification_model::insert((int)$u->id, 'Komisi Rebate Cair',
            'Anda menerima komisi Level ' . $tier . ' Rp ' . number_format($amount,0,',','.') ...
            , 'commission');                               // tipe 'commission' ada di ENUM
```

Semantik:

- **Bound traversal = tepat 3 posisi**; tidak ada loop tak terbatas; `parent_id`
  NULL menghentikan sisa tier (hangus ke platform).
- **Zero Pass-Up:** L2 menerima persis `l2_pct` dari harga paket — **tidak
  pernah** menerima tambahan jatah L1 yang hangus; breakage tinggal di
  platform.
- **Eligibility dinilai per posisi** terhadap kontrak upline itu sendiri.
- `$buyer_label` = identitas pembeli untuk deskripsi ledger (kanonik: `phone`;
  bila lingkungan live memiliki display name, boleh dipakai dengan fallback
  defensif `?? phone`).
- Kegagalan `credit()`/notifikasi → `Throwable` → ditangkap catch luar
  `checkout_rental()` → `trans_rollback()` → **tidak ada baris rebate parsial
  yang bertahan**; response `code:'error'` standar.
- Upline `is_banned = 1` dilewati (fail-safe, logged) — konsisten dengan
  penolakan klaim gaji pada user banned (`claim_wage`).

### 3.3 Skema ledger & idempotensi

| Aspek | Spesifikasi |
|---|---|
| Tabel | `wallet_ledger` (append-only, immutable) via `Wallet_model::credit()` |
| `type` | `credit` |
| `transaction_id` | `RBT-{rental_id}-L{tier}` — deterministik, ≤ 50 char, contoh `RBT-1042-L1` |
| Unique key | `uk_wallet_ledger_user_tx_type(user_id, transaction_id, type)` — backstop anti double-credit bila engine pernah dieksekusi ulang dengan rental_id sama (duplicate → `credit()` false → rollback) |
| `amount` | integer IDR positif (choke-point `(int)` + asersi di `_post()`, M8) |
| `description` | `Komisi sewa GPU Level {tier} dari {buyer_label}` |
| Cache | `users.balance = balance + ?` relatif, atomik, dalam TX yang sama (C4) |
| Notifikasi | `user_notifications` type `commission`, dibuat dalam TX yang sama (pola M5/N2 di `expire_user_rentals`) |

Karena `transaction_id` memuat `rental_id` (unik per pembelian), dua
checkout berbeda dari downline yang sama adalah dua peristiwa komisi yang sah
(masing-masing `RBT-…` berbeda) — bukan duplikasi.

### 3.4 Konkurensi & deadlock

- **Lock order konsisten per TX:** anchor `users` pembeli (statement pertama,
  C5) → baris `users` upline L1 → L2 → L3 (melalui UPDATE relatif di
  `_post()`), naik kedalaman. Dua TX yang berbagi upline mengunci node
  bersama dalam urutan yang sama (dangkal → dalam) → **deadlock-free**.
- Dua anak dari upline sama checkout bersamaan: masing-masing mengunci
  baris pembelinya, lalu serialisasi pada UPDATE relatif baris upline
  (X-lock); kredit ledger dihitung dari snapshot TX-nya masing-masing —
  aman karena tidak ada pembacaan-balik saldo upline.
- Kelayakan upline dibaca sebagai consistent read **setelah lock-wait
  anchor** (snapshot TX = setelah menunggu kunci pembeli). Window sisa
  (kontrak upline kedaluwarsa pada milidetik yang sama) simetris dengan gate
  aktif lain di sistem (`count_active_b_downlines`, dsb.) — tidak ada aksi
  tambahan; filter `expired_at > ?` menutup kasus sweep lazy yang belum
  sempat di-flip.

---

## 4. Referral Access Gating (Team Share Center)

### 4.1 Penghitung rental (derived — tanpa perubahan skema)

`Rental_model::get_user_rental_stats($user_id)` → `{lifetime_rentals:int,
active_rentals:int}` dalam SATU query (subquery agregat):

```sql
SELECT
  (SELECT COUNT(*) FROM user_rentals
    WHERE user_id = ? AND status IN ('active','completed')) AS lifetime_rentals,
  (SELECT COUNT(*) FROM user_rentals
    WHERE user_id = ? AND status = 'active' AND expired_at > ?) AS active_rentals
```

- **lifetime** = pernah menyewa (`active` + `completed`; `cancelled`
  dikecualikan — belum dipakai sistem, dan bila kelak dipakai untuk refund,
  "batal" ≠ "pernah menyewa").
- **active** = kontrak benar-benar aktif (filter defensif `expired_at > ?`
  WIB bound param — M3), konsisten dengan `has_active_rental()`.
- Dipakai oleh `Team::index()` (gating) dan `Home::index()` (warning) —
  single source of truth, zero migration.

### 4.2 State view referral

| State | Kondisi | UI Share Center `/team` |
|---|---|---|
| **A (locked)** | `lifetime_rentals == 0` | Kode undangan, link, tombol salin, dan QR **disembunyikan**. Diganti kartu locked state: ikon gembok + copy persis *"Sewa minimal 1 paket GPU untuk membuka kode undangan dan mulai menghasilkan komisi tim."* + CTA tombol "Lihat Paket GPU" → `/marketplace`. |
| **B** | `lifetime_rentals ≥ 1` && `active_rentals == 0` | Kode + link + QR **terlihat permanen**. |
| **C** | `lifetime_rentals ≥ 1` && `active_rentals ≥ 1` | Kode + link + QR **terlihat permanen**. |

### 4.3 Perubahan kode

- `Team::index()`: panggil `get_user_rental_stats()`; pass
  `referral_locked` (bool) + statistik ke view. Saat locked, **jangan bangun
  `$ref_url`** dan jangan ekspor `invite_code` mentah ke markup.
- `application/views/team/index.php`: bungkus section "Pusat Berbagi"
  (baris ±152–176) dalam `if (!$referral_locked)` … `else` (locked card);
  guard JS `copyRef()`/QR init agar hanya aktif saat unlocked (mencegah kode
  bocor ke DOM pada state A — info minimal).
- `routes.php`: alias ringan `$route['referral'] = 'team/index';` sehingga
  `/referral` mendarat mulus ke hub yang sama (sesuai keputusan pengguna;
  opsional, non-destruktif).
- Halaman `/team` tetap satu-satunya hub afiliasi — statistik tim,
  klaim L1/gaji mingguan, dan daftar anggota **tidak** berubah.

---

## 5. Peringatan Upline Inaktif (Dashboard Landing)

- **Target:** `lifetime_rentals > 0 && active_rentals == 0` (Condition B).
- `Home::index()` memanggil `get_user_rental_stats()`; kondisi terpenuhi →
  `$data['inactive_warning'] = true`.
- `application/views/home/index.php`: modal/alert menonjol (gaya modal
  vanilla JS yang sudah ada, mis. pola `openHelpModal`; shell mobile-first
  `max-w-[480px]`), dirender otomatis saat landing, dengan:
  - Heading + copy persis: *"⚠️ Perhatian: Kontrak Sewa Anda Tidak Aktif!
    Anda saat ini tidak memiliki sewa GPU yang aktif. Aktifkan kembali
    minimal satu paket sekarang agar komisi referral dari jaringan tim Anda
    tidak hangus."*
  - CTA utama: tombol "Aktifkan Sewa Sekarang" → link `/marketplace`
    (bukan AJAX; navigasi biasa).
- **Kesegaran state:** `MY_Controller` sudah menjalankan
  `expire_user_rentals()` setiap request terautentikasi sebelum controller —
  kontrak yang baru kedaluwarsa sudah di-flip sebelum `Home::index()`
  menghitung statistik; filter `expired_at > ?` menutup celah defensif.
- **Perilaku default:** modal muncul setiap landing dashboard selama kondisi
  berlaku (deterministik); dismiss hanya menutup render saat itu.
  Penyempurnaan opsional (dismiss per-sesi via `userdata`) dicatat sebagai
  enhancement — tidak masuk lingkup awal.
- Peringatan **tidak** digate oleh `rebate_enabled`: kondisi target murni
  berdasarkan status kontrak member (spesifikasi), copy-nya generik soal
  komisi referral.

---

## 6. Mitigasi Edge-Case (rangkuman)

| # | Kasus | Perilaku |
|---|---|---|
| 1 | Upline inaktif (0 kontrak aktif) | Tier hangus total; **tidak** di-pass-up; tidak ada baris ledger/notifikasi untuk tier tsb; tier lain tetap berjalan |
| 2 | Rantai upline pendek / `parent_id` NULL | Iterasi berhenti; sisa tier hangus ke platform |
| 3 | Self-reference / siklus `parent_id` | Fail-closed: log `error`, hentikan rantai (sisa hangus). Traversal terikat 3 iterasi → loop runaway mustahil |
| 4 | Rounding integer (M8) | `intdiv($price * $pct, 100)` (floor untuk operand non-negatif); hasil `0` (< 1 IDR) → dilewati (tidak pernah menulis amount 0/negatif — asersi `_post()`) |
| 5 | Rejection checkout (saldo/kuota/produk/error) | Blok rebate tak pernah tercapai + rollback penuh → **zero rebate rows** |
| 6 | Engine dieksekusi ulang (replay) | `uk_wallet_ledger_user_tx_type` memblokir double-credit `RBT-{rental_id}-L{tier}` → rollback |
| 7 | Dua anak checkout bersamaan (upline sama) | Serialisasi pada anchor masing-masing + UPDATE relatif baris upline; `transaction_id` berbeda per `rental_id` → dua komisi sah |
| 8 | Config korup / key hilang | `log_message('error')` + fallback per-key dari `config/rebate_commission.php`; engine tetap jalan dengan nilai aman |
| 9 | `rebate_enabled = 0` / persen tier 0 | Engine skip (0 kredit, 0 notifikasi) / tier tsb menghasilkan amount 0 → dilewati |
| 10 | Upline banned | Dilewati + log (fail-safe; parity `claim_wage`) |
| 11 | Kontrak upline expired tapi sweep belum jalan | Filter `expired_at > ?` (WIB bound) menolak sejak saat kedaluwarsa — tidak menunggu flip lazy (M3) |
| 12 | Admin `inject_rental` | Bypass `checkout_rental` → tidak memicu komisi (dokumentasikan) |
| 13 | Kode referral bocor ke DOM saat locked | `$ref_url` tidak dibangun; markup/JS Share Center hanya dirender saat unlocked |

---

## 7. Matriks Verifikasi (akan dieksekusi saat implementasi)

Persiapan data (jalur seeder manual/`mysql`): rantai U1(root) ← U2 ← U3 ← U4;
produk GPU aktif `price` integer (mis. 2.000.000, ROI harian terserah).
Setiap skenario diverifikasi dengan kombinasi `curl` (HTTP + HTML) dan
asersi SQL pada `wallet_ledger`/`user_notifications`/`users.balance`.

| # | Skenario | Langkah | Harapan |
|---|---|---|---|
| T1 | Distribusi 3-tier penuh | U1–U3 masing-masing memiliki 1 kontrak aktif; U4 checkout paket (config default 5/3/1) | Ledger: U3 +`RBT-{id}-L1` = 5% × price; U2 +`L2` = 3%; U1 +`L3` = 1%; `users.balance` naik sesuai; 3 notifikasi `commission`; jumlah = 9% price |
| T2 | Inactive forfeiture (L1 hangus, no pass-up) | U3 **tanpa** kontrak aktif; U2 aktif; U4 checkout | U3 0 baris; U2 hanya +`L2` = 3% (BUKAN 8%); breakage 5% ke platform; U1 +`L3` = 1% |
| T3 | Rantai pendek (parent NULL) | U2 checkout (uplines: U1 saja) | U1 +`L1` = 5%; L2/L3 tidak ada baris |
| T4 | Engine disabled | `rebate_enabled=0`; U4 checkout | 0 baris `RBT-`; saldo upline tidak berubah; checkout tetap sukses |
| T5 | Persen 0 / produk kecil | `rebate_l3_percent=0` (atau harga × 1% < 1 IDR) | Tidak ada kredit amount 0; tidak ada error TX |
| T6 | Integer rounding | price 55.000, l3=1% → 550; price 2.000.000 → L1 100.000 dst. | `intdiv` exact; tidak ada nilai pecahan/float di ledger |
| T7 | Rejection → zero rebate | U4 saldo kurang → checkout | `code:'insufficient'`; 0 baris ledger baru (debit & rebate) |
| T8 | Idempotensi/unique | Sisip manual `RBT-{id}-L1` duplikat | Ditolak `uk_wallet_ledger_user_tx_type` |
| T9 | Gating referral A | User lifetime 0 buka `/team` | HTML berisi copy locked + CTA marketplace; TIDAK berisi `invite_code`/`ref=` |
| T10 | Gating referral B/C | User tsb checkout 1 paket → buka `/team` lagi | Kode undangan + link + QR tampil; `/referral` redirect ke `/team` |
| T11 | Warning dashboard B | User lifetime ≥1, 0 aktif → `GET /` | Modal warning tampil dengan copy persis + CTA `/marketplace` |
| T12 | Warning hilang (C) | User tsb membeli 1 paket aktif → `GET /` | Modal tidak tampil |
| T13 | Admin settings mutation | POST `/admin/settings` ubah `rebate_l2_percent` 3→4 | `system_settings` ter-update; `system_audit_logs` aksi `admin_update_settings` memuat `keys:['rebate_l2_percent']` + before/after |
| T14 | Validasi admin | POST persen 150 / `abc` / enabled kosong | Error flash; tidak ada key tersimpan (all-or-nothing) |
| T15 | Smoke lint | seluruh file PHP tersentuh | `php -l` sukses 100% |
| T16 | Konkurensi (opsional paralel) | 2 checkout anak U2 bersamaan | Dua `rental_id`; 2 baris `RBT-…L1` untuk U2 dengan id berbeda; saldo = jumlah kedua komisi |

---

## 8. Daftar Perubahan File (lingkup implementasi berikutnya)

| File | Perubahan |
|---|---|
| `database.sql` | +4 baris seed `system_settings` (rebate_*) |
| `application/config/rebate_commission.php` | **baru** — fallback konfigurasi |
| `application/models/Rental_model.php` | `get_rebate_config()`, normalizer, `validate_rebate_settings()`, `get_user_rental_stats()`, blok rebate + `_distribute_rebate()` di `checkout_rental()` |
| `application/controllers/Admin.php` | `settings()` POST: raw + validasi + merge rebate |
| `application/views/admin/settings.php` | Card 5 "Komisi Rebate 3-Tier" |
| `application/controllers/Team.php` | `index()`: statistik rental + `referral_locked` |
| `application/views/team/index.php` | Gating Share Center (locked/unlocked) |
| `application/controllers/Home.php` | `index()`: statistik rental + `inactive_warning` |
| `application/views/home/index.php` | Modal peringatan upline inaktif |
| `application/config/routes.php` | `$route['referral'] = 'team/index';` |
| `plan/89_…_PLAN.md` | dokumen ini |

**Tidak menyentuh:** skema `users`, `Wallet_model` write-path, kontrak audit
`admin_update_settings`, endpoint `/admin/financial-settings` (redirect shim),
atau logika klaim L1/gaji mingguan (rebate bersifat aditif, otomatis, tanpa
klaim — tidak tumpang-tindih).

---

## 9. Konvensi & Referensi

- Bahasa UI/copy Indonesia; uang IDR integer; `php -l` wajib per file;
  commit message Bahasa Indonesia per fase.
- `docs/1_PRD.md` (v5.0) & `docs/3_ROADMAP.md` (v6.0) — dicek ulang saat
  implementasi bila ada benturan aturan agensi.
- Plan terkait: plan/48 (C5), plan/54 (C4), plan/56 (M1), plan/60 (M3),
  plan/64 (M5), plan/70 (M7), plan/74 (M8), plan/76 (M9/P7), plan/83 & plan/87
  (gating produk/quota di `checkout_rental`), plan/82 (audit closure).
