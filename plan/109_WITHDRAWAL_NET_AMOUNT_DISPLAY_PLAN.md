# Plan 109 — Tampilan Nominal NET Penarikan (Admin & Member)

> **Status:** BLUEPRINT (dokumen arsitektur SAJA). Belum ada satu pun perubahan
> kode aplikasi, skema DB, controller, model, route, view, helper, atau kamus
> bahasa yang dilakukan oleh dokumen ini. **Deliverable round ini = file ini
> saja** (`plan/109_WITHDRAWAL_NET_AMOUNT_DISPLAY_PLAN.md`). Eksekusi
> implementasi **MENUNGGU instruksi lanjutan terpisah** dari pemilik repositori.
>
> **Ruang lingkup:** memperbaiki presentasi nominal pada seluruh surface
> penarikan yang memicu risiko salah-transfer admin dan keluhan member:
> antrean Pending Withdrawals di Command Center, dialog konfirmasi Approve,
> tabel riwayat penarikan admin, kartu penarikan tertunda member, preview fee
> di form penarikan member, dan notifikasi `notif_wd_approved`.
>
> **Zero-impact finansial:** **nol DDL**, **nol** perubahan `wallet_ledger`
> (ledger tetap mencatat debit **gross** penuh dan tidak disentuh), **nol**
> perubahan aritmetika fee/tier, **nol** route baru, **nol** perubahan status
> machine, **nol** perubahan `system/`.

---

## 1. Ringkasan & Success Criteria

### 1.1 Masalah yang diselesaikan

`withdrawals` menyimpan tiga kolom finansial yang berbeda artinya:

| Kolom | Arti | Dipotong dari saldo? | Ditransfer ke e-wallet member? |
|---|---|---|---|
| `gross_amount` (= `amount` mirror) | nominal yang diajukan member | **ya** (debit penuh) | tidak |
| `fee_amount` | biaya admin (tier PRD) | — | tidak (ditahan platform) |
| `net_amount` (= `gross_amount` − `fee_amount`) | **yang wajib ditransfer admin** | — | **ya** |

Namun seluruh surface yang dibaca manusia hari ini menonjolkan **GROSS**:

1. **Antrean Pending Withdrawals** (`views/admin/dashboard.php:280`) mencetak
   `$wd->amount` sebagai nilai primer, dan tombol Approve memakai
   `confirm('Approve withdrawal {wd_number}?')` — **tanpa nominal, tanpa tujuan
   transfer**. Admin yang salah baca mentransfer gross → **kerugian platform
   sebesar fee** (mis. Rp 206.500 pada penarikan Rp 5.000.000).
2. **Riwayat penarikan admin** (`/admin/history/withdrawal`) meruntuhkan
   gross/fee/net menjadi satu sel "Nominal" yang di-resolve ke gross
   (`views/admin/history.php:84-101`) → jejak audit tidak bisa membedakan
   "yang dipotong" vs "yang ditransfer".
3. **Kartu penarikan tertunda member** (`views/wallet/index.php:243`)
   menampilkan gross saja, sehingga member mengira akan menerima sejumlah itu.
4. **Notifikasi persetujuan** (`notif_wd_approved`) mengirim **gross**
   (`Admin.php:245-250`) — justru pesan inilah yang memicu panik member
   ("Rp 1.000.000 telah diproses" padahal masuk Rp 928.500) dan tiket dukungan.
5. **Preview form penarikan** (`views/wallet/withdraw.php:105-117`) sudah
   menghitung fee/net hidup, tetapi **tidak menampilkan baris gross** sehingga
   tidak ada label eksplisit gross/fee/net.

### 1.2 Akar masalah (kode, bukan data)

Semua data sudah benar sejak awal. `Wallet_model::create_withdrawal()`
(`:1231-1246`) mempersist `amount == gross_amount`, `fee_amount`, `net_amount`
di dalam TX terkunci. Jadi ini **murni cacat presentasi** — tidak ada migrasi,
tidak ada backfill wajib, tidak ada mutasi uang.

### 1.3 Solusi

Satu **choke-point murni** baru (`application/helpers/withdrawal_amount_helper.php`)
yang meresolusi gross/fee/net dari satu baris `withdrawals` (termasuk fallback
baris legacy `fee/net ≤ 0`), dipakai oleh **model** (dekorasi `gross_eff`,
`fee_eff`, `net_eff`) sehingga view tinggal memformat. Seluruh surface admin
memakai NET sebagai nilai primer + label tegas, seluruh surface member
menampilkan breakdown gross/fee/net secara eksplisit, dan notifikasi
persetujuan menyebut NET.

### 1.4 Success criteria

1. Antrean Pending Withdrawals menampilkan **`net_amount`** sebagai nilai
   primer berlabel **"Wajib Transfer (Net)"**, dengan sub-teks
   `(Penarikan: Rp {gross} | Biaya: Rp {fee})`.
2. Dialog Approve menyebut **nominal NET persis** + provider + nomor e-wallet +
   nama pemilik; flash pasca-approve juga menyebut NET.
3. Tabel riwayat penarikan admin memisahkan kolom **Gross / Biaya / Net**.
4. Kartu penarikan member menampilkan **dana diterima (net)** sebagai nilai
   primer + sub-teks nominal diminta (gross) & biaya admin (fee).
5. Form penarikan member menampilkan tiga baris eksplisit: **Nominal
   Penarikan (Gross)** / **Biaya Admin (Fee)** / **Estimasi Dana Diterima (Net)**.
6. `notif_wd_approved` menyebut **NET** (+ biaya admin) dan **tidak pernah
   fatal** untuk baris notifikasi lama (guard arity).
7. `wallet_ledger` **tidak tersentuh** — nol perubahan query, nol perubahan
   tampilan ledger.
8. `php -l` bersih untuk seluruh berkas yang diubah; `audit_i18n_parity.php`
   `LULUS` **602/602** (nol key baru); `audit_i18n_hardcoded.php` **0 temuan**.

---

## 2. Keputusan

### 2.1 Keputusan owner (`dec-ce71528c38328365`, mengikat)

| # | Keputusan |
|---|---|
| **D1** | Surface member yang diubah = **kartu penarikan tertunda** (`views/wallet/index.php`) + **preview form penarikan** (`views/wallet/withdraw.php`). Kartu = nilai net menonjol; sub-teks = gross + fee. Form = label eksplisit Gross/Fee/Net. |
| **D2** | **`wallet_ledger` DILARANG diubah** — entri maupun tampilan ledger tetap imutabel dan merekam **debit gross penuh** dari saldo member. Tidak ada LEFT JOIN, tidak ada sub-baris ledger. |
| **D3** | **Notifikasi `notif_wd_approved` WAJIB diperbaiki**: teruskan `net_amount` (dana yang benar-benar ditransfer) sebagai parameter, opsional menyebut biaya admin. Paritas kamus EN/ID tetap 1:1 dan `audit_i18n_parity.php` wajib exit 0. |
| **D4** | Dokumen ini ditulis dalam **Bahasa Indonesia** (house style `plan/`). |

### 2.2 Keputusan arsitek (terdokumentasi, tanpa perlu input owner)

- **D5 — Satu choke-point resolusi gross/fee/net.** Aturan "nominal mana yang
  ditampilkan" muncul di 4 surface; sesuai *helper-first rule* (AGENTS.md) ia
  hidup di satu berkas helper murni, bukan diduplikasi di view/controller.
- **D6 — Dekorasi di lapisan MODEL, bukan view.** Helper dipanggil dari
  `Wallet_model::get_pending_withdrawals()`, `Admin_model::get_history_withdrawals()`,
  dan `Admin_model::get_withdrawal_queue()` (**baru**), lalu menempelkan
  `gross_eff` / `fee_eff` / `net_eff` pada tiap baris. View hanya memformat
  (`number_format`) — tidak ada aritmetika uang di view.
- **D7 — `Admin_model::get_withdrawal_queue()` menyerap SQL inline di
  controller.** `Admin::index():97-103` hari ini menulis query langsung di
  controller — pelanggaran invariant "semua akses DB di model". Karena baris
  inilah yang sedang diubah, query dipindahkan ke model dengan pola yang sudah
  ada (`Admin_model::get_deposit_queue()`, plan/102).
  *Trade-off:* diff bertambah ±15 baris pada model + 1 baris pada controller,
  tetapi invariant AGENTS.md kembali utuh di jalur yang disentuh. **Bila owner
  ingin diff benar-benar hanya view, langkah ini bisa diveto** — dekorasi tetap
  bisa dilakukan di controller dengan bantuan helper yang sama (tanpa mengubah
  perilaku apa pun).
- **D8 — Konfirmasi memakai `confirm()` native** (pola preseden deposit:
  `views/admin/dashboard.php:174-176` + `:224`, dengan
  `str_replace("'", "\\'", …)` untuk eskapasi string JS), **bukan** modal
  kustom. Alasan teknis: inline `onsubmit` dieksekusi lebih dulu daripada guard
  double-submit `data-guard-submit` (lihat `views/templates/csrf_meta.php:60-62`
  — "Dipasang bubble-phase di document: inline onsubmit … berjalan lebih dulu;
  jika user batal, event berhenti sebelum guard menandai form"), sehingga
  membatalkan konfirmasi tidak menandai form sebagai sedang submit dan tidak
  memicu POST kedua dengan token CSRF basi.
- **D9 — Nol key kamus baru.** `wd_amount_label`, `wd_admin_fee`, `wd_received`
  masing-masing **hanya** dipakai di `views/wallet/withdraw.php` (`:84`, `:109`,
  `:114`) — terbukti dari grep — sehingga cukup **mengubah nilai** ketiganya
  (bukan menambah key). Paritas tetap **602/602**.
- **D10 — Admin tetap 100% Indonesia & hardcoded (L1)**; member melalui
  `lang()` (dual-idiom); uang **tidak pernah** diterjemahkan — `Rp` +
  `number_format($v, 0, ',', '.')` (L6); nomor e-wallet **tampil utuh di admin**
  (admin harus mentransfer ke nomor itu) dan tetap **ter-mask di member**
  (`ewallet_phone_mask()`).

### 2.3 Invariant yang wajib tetap utuh

`wallet_ledger` = satu-satunya ledger (tidak disentuh) · tidak ada SQL di
controller/view (D7 justru memulihkannya) · disiplin integer-IDR utuh
(helper mengembalikan `int`, bukan string/float) · admin tidak pernah memanggil
`i18n_apply()` (L1) · POST-only + CSRF pada setiap mutator (tidak ada mutator
baru di plan ini) · setiap mutasi tetap ter-audit seperti sekarang.

---

## 3. Fakta Codebase Terverifikasi (pra-edit)

| Fakta | Bukti |
|---|---|
| `withdrawals` sudah punya `amount`, `gross_amount`, `fee_amount`, `net_amount` (DECIMAL 15,2) → **tidak ada DDL** | `database.sql:144-166` (`:149-152`) |
| `create_withdrawal()` selalu mengisi ketiganya (`amount == gross`, `net = gross − fee`) di dalam TX terkunci | `application/models/Wallet_model.php:1231-1246` |
| Fee tier half-open dihitung `intdiv(gross * bps, 10000) + fixed_fee` | `Wallet_model.php:461-474` |
| Seed contoh membuktikan kontrak: 5jt → fee 206.500 / net 4.793.500; 1jt → fee 71.500 / net 928.500 | `database_seed.sql:177-193` |
| Antrean pending dashboard **SQL inline di controller** | `application/controllers/Admin.php:97-103`, dipakai di `:119` |
| Kartu antrean menampilkan `$wd->amount` sebagai nilai primer; confirm tanpa nominal | `application/views/admin/dashboard.php:279-282`, `:285` |
| Tujuan transfer dirender **utuh** (tidak di-mask) + `phone` member | `views/admin/dashboard.php:274-277` |
| Riwayat admin meruntuhkan nominal jadi satu sel "Nominal" (resolve ke gross utk withdrawal) | `views/admin/history.php:56-65`, `:84-101` |
| Sumber CSV riwayat penarikan **sudah** Gross/Fee/Net + read-side recompute legacy | `Admin_model.php:1398-1414`, `Admin.php:2479-2512` |
| `approve_withdrawal()` mengembalikan **baris penuh** (`SELECT *`) → `net_amount` tersedia untuk flash | `Admin_model.php:601-639` (`:602`, `:632`) |
| Notifikasi persetujuan meneruskan **gross** | `Admin.php:245-250` |
| Render notifikasi dilakukan **saat dibaca** dari kamus + `params` (JSON) → perubahan arity placeholder berdampak pada baris lama | `application/helpers/i18n_helper.php:274-328` (`:312-325`) |
| `insert_keyed()` menyimpan snapshot `message` + `params` (vsprintf di-suppress saat insert) | `Notification_model.php:96-118` (`:106`) |
| Kartu penarikan member menampilkan gross saja; ledger terpisah & tidak disentuh | `views/wallet/index.php:228-262` (`:242-246`), `:264-303` |
| Preview fee form penarikan: baris Fee + Net, **tanpa** baris Gross | `views/wallet/withdraw.php:105-117`, JS `:142-144`, `:244-257` |
| Query member pending: `w.*` + join `bank_accounts` | `Wallet_model.php:1273-1281` |
| `Admin_model` sudah lazim memuat `Wallet_model` di dalam method | `Admin_model.php:430`, `:664`, `:749` |
| Helper autoload = daftar eksplisit (preseden plan/104/105/106/108) | `application/config/autoload.php:108` |
| Key kamus target (nilai saat ini) | EN `app_lang.php:384` `'Admin Fee'`, `:386` `'Withdrawal Amount'`, `:393` `'Received'`, `:547` `'A withdrawal of Rp %s has been processed.'`; ID `:384` `'Biaya Admin'`, `:386` `'Nominal Penarikan'`, `:393` `'Diterima'`, `:547` `'Penarikan sebesar Rp %s telah diproses.'` |
| Guard double-submit + urutan inline `onsubmit` | `views/templates/csrf_meta.php:49-93` (`:60-62`, `:82-93`) |
| Kontrak fallback legacy yang **sudah** dipakai jalur CSV (preseden yang sama) | `scripts/backfill_withdrawal_fees.php` (header) + `Admin.php:2495-2501` |

---

## 4. Kontrak Choke-Point — `application/helpers/withdrawal_amount_helper.php` (BARU)

Berkas baru, autoloaded (`withdrawal_amount`), murni, aman di-`include` dari CLI
migrasi/probe (pola `product_image_helper` / `ewallet_helper`).

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Plan 109 — choke-point tunggal resolusi nominal penarikan (gross/fee/net).
 * Uang tetap INTEGER IDR (L6: pemformatan hanya di view).
 */

if ( ! function_exists('withdrawal_amount_parts'))
{
    /**
     * @param  array|object $row            Baris `withdrawals` (dengan kolom amount,
     *                                      gross_amount, fee_amount, net_amount)
     * @param  callable|null $fee_calculator  `fn(int $gross): array{fee:int,net:int}`
     *                                      — kirim `Wallet_model::calculate_withdrawal_fee`
     * @return array{gross:int,fee:int,net:int,legacy:bool}
     */
    function withdrawal_amount_parts($row, $fee_calculator = NULL) { /* lihat §6 P1 */ }
}

if ( ! function_exists('withdrawal_amount_decorate'))
{
    /** @return array Baris yang sama, ditambah gross_eff/fee_eff/net_eff/amount_legacy */
    function withdrawal_amount_decorate(array $rows, $fee_calculator = NULL) { /* lihat §6 P1 */ }
}
```

**Aturan resolusi (satu-satunya sumber):**

1. `gross = (gross_amount > 0) ? gross_amount : amount` → `legacy = (gross_amount ≤ 0)`.
2. Bila `gross ≤ 0` → kembalikan `{0,0,0,legacy}` (tidak ada yang bisa ditampilkan).
3. `fee = fee_amount`, `net = net_amount`; baris dianggap **inkonsisten** bila
   `fee ≤ 0` **atau** `net ≤ 0` **atau** `fee + net ≠ gross`.
4. Baris inkonsisten **dan** kalkulator tersedia → hitung ulang via tier PRD
   (`Wallet_model::calculate_withdrawal_fee()`, sumber yang sama dengan jalur
   CSV `Admin.php:2495-2501`).
5. `net ≤ 0` → `net = max(0, gross − fee)`.
6. Tidak ada pembulatan, tidak ada float: seluruh keluaran `(int)`.

**Preseden:** kontrak fallback ini sudah dipakai produksi untuk export CSV
(read-side recompute legacy) — plan ini hanya memusatkannya dan memakainya di
surface manusia.

---

## 5. Inventaris Berkas

### 5.1 Berkas BARU

| Berkas | Isi |
|---|---|
| `application/helpers/withdrawal_amount_helper.php` | Choke-point §4 (2 fungsi murni) |
| `plan/109_WITHDRAWAL_NET_AMOUNT_DISPLAY_PLAN.md` | Dokumen ini |

### 5.2 Berkas DIUBAH

| Berkas | Perubahan |
|---|---|
| `application/config/autoload.php` | `:108` — tambah `'withdrawal_amount'` + komentar preseden |
| `application/models/Admin_model.php` | **+** `get_withdrawal_queue()` (D7); dekorasi `get_history_withdrawals()` |
| `application/models/Wallet_model.php` | Dekorasi `get_pending_withdrawals()` |
| `application/controllers/Admin.php` | `:97-103` → `get_withdrawal_queue()`; `:245-250` params notifikasi = gross,fee,net; `:251` flash + NET |
| `application/helpers/i18n_helper.php` | Guard arity `vsprintf` (`:312-325`) untuk baris notifikasi lama |
| `application/views/admin/dashboard.php` | Kartu Pending Withdrawals: nilai primer = NET + label + sub-teks; confirm Approve diperkaya |
| `application/views/admin/history.php` | Tab withdrawal: 3 kolom Gross / Biaya / Net |
| `application/views/wallet/index.php` | Kartu penarikan tertunda: NET primer + sub-teks gross/fee (**ledger tidak disentuh**) |
| `application/views/wallet/withdraw.php` | Preview 3 baris: Gross / Fee / Net (+ JS `wd_gross`) |
| `application/language/english/app_lang.php` | 3 nilai + 1 body (arity 1 → 3) |
| `application/language/indonesian/app_lang.php` | 3 nilai + 1 body (arity 1 → 3) |

### 5.3 Berkas DIVERIFIKASI SAJA (target: **nol perubahan**)

`database.sql` (`:144-166` sudah lengkap) · `Admin_model::get_all_withdrawals()`
+ `Admin::export_csv('withdrawals')` (sudah Gross/Fee/Net) ·
`Wallet_model::create_withdrawal()` · `wallet_ledger` (seluruh jalur) ·
`views/wallet/index.php` blok LEDGER HISTORY (`:264-303`) · `routes.php` ·
`scripts/backfill_withdrawal_fees.php` · seluruh berkas di `system/`.

---

## 6. Langkah Eksekusi (P0–P10)

### P0 — Persist dokumen ini

Tulis `plan/109_WITHDRAWAL_NET_AMOUNT_DISPLAY_PLAN.md` (dokumen ini). Tidak ada
kode disentuh.

### P1 — Helper choke-point (BARU)

`application/helpers/withdrawal_amount_helper.php`:

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if ( ! function_exists('withdrawal_amount_parts'))
{
    function withdrawal_amount_parts($row, $fee_calculator = NULL)
    {
        $row = (array) $row;

        $gross_stored = isset($row['gross_amount']) ? (int) $row['gross_amount'] : 0;
        $amount       = isset($row['amount'])       ? (int) $row['amount']       : 0;
        $fee          = isset($row['fee_amount'])   ? (int) $row['fee_amount']   : 0;
        $net          = isset($row['net_amount'])   ? (int) $row['net_amount']   : 0;

        $legacy = ($gross_stored <= 0);
        $gross  = $legacy ? $amount : $gross_stored;

        if ($gross <= 0) {
            return ['gross' => 0, 'fee' => 0, 'net' => 0, 'legacy' => $legacy];
        }

        if (($fee <= 0 || $net <= 0 || ($fee + $net) !== $gross) && is_callable($fee_calculator)) {
            $calc = $fee_calculator($gross);
            if (is_array($calc) && isset($calc['fee'], $calc['net'])) {
                $fee = max(0, (int) $calc['fee']);
                $net = max(0, (int) $calc['net']);
            }
        }

        if ($net <= 0) {
            $net = max(0, $gross - $fee);
        }

        return ['gross' => $gross, 'fee' => $fee, 'net' => $net, 'legacy' => $legacy];
    }
}

if ( ! function_exists('withdrawal_amount_decorate'))
{
    function withdrawal_amount_decorate(array $rows, $fee_calculator = NULL)
    {
        foreach ($rows as $row) {
            $p = withdrawal_amount_parts($row, $fee_calculator);
            $row->gross_eff     = $p['gross'];
            $row->fee_eff       = $p['fee'];
            $row->net_eff       = $p['net'];
            $row->amount_legacy = $p['legacy'];
        }
        return $rows;
    }
}
```

### P2 — Registrasi autoload

`application/config/autoload.php:108` — tambahkan `'withdrawal_amount'` pada
`$autoload['helper']` + komentar preseden selaras plan/104–108 (fungsi murni,
netral untuk member & admin, aman di-include ulang dari CLI).

### P3 — Dekorasi di model

**3a. `Wallet_model::get_pending_withdrawals()`** (`:1273-1281`) — sebelum
`return`, dekorasi:

```php
$rows = $this->db->get()->result();
return withdrawal_amount_decorate($rows, [$this, 'calculate_withdrawal_fee']);
```

**3b. `Admin_model::get_history_withdrawals()`** (`:100-109`) — pola sama,
dengan `$this->load->model('Wallet_model');` di awal method (preseden
`Admin_model.php:430, 664, 749`) lalu
`return withdrawal_amount_decorate($rows, [$this->Wallet_model, 'calculate_withdrawal_fee']);`

**3c. `Admin_model::get_withdrawal_queue()` (BARU, D7)** — pindahkan query
`Admin::index():97-103` apa adanya ke model:

```php
public function get_withdrawal_queue() {
    $rows = $this->db->select('w.*, u.phone, ba.bank_name, ba.account_number, ba.account_holder AS account_name')
        ->from('withdrawals w')
        ->join('users u', 'u.id = w.user_id', 'left')
        ->join('bank_accounts ba', 'ba.id = w.bank_account_id', 'left')
        ->where('w.status', 'pending')
        ->order_by('w.created_at', 'ASC')
        ->get()->result();

    $this->load->model('Wallet_model');
    return withdrawal_amount_decorate($rows, [$this->Wallet_model, 'calculate_withdrawal_fee']);
}
```

### P4 — Controller

**4a.** `Admin::index()` — hapus SQL inline `:97-103`, ganti satu baris:

```php
$pending_withdrawals = $this->Admin_model->get_withdrawal_queue();
```

**4b.** `Admin::approve_withdrawal()` (`:245-250`) — params notifikasi menjadi
`[gross, fee, net]` (fallback `amount` bila `net_eff ≤ 0`), memakai helper yang
sama:

```php
$parts = withdrawal_amount_parts($wd, [$this->Wallet_model, 'calculate_withdrawal_fee']);
$this->Notification_model->insert_keyed(
    $wd->user_id,
    'notif_wd_approved',
    [
        number_format($parts['gross'], 0, ',', '.'),
        number_format($parts['fee'],   0, ',', '.'),
        number_format($parts['net'],   0, ',', '.'),
    ],
    'success'
);
```

**4c.** Flash pasca-approve (`:251`) menyebut NET + tujuan (defense-in-depth):

```php
$this->session->set_flashdata(
    'success',
    'Penarikan #' . $wd->wd_number . ' disetujui. Transfer NET Rp '
    . number_format($parts['net'], 0, ',', '.') . ' ke akun e-wallet penarikan.'
);
```

Tidak ada perubahan pada `decline_withdrawal` (dana dikembalikan penuh = gross,
sudah benar).

### P5 — Dashboard admin: kartu Pending Withdrawals

`views/admin/dashboard.php` — di dalam `foreach ($pending_withdrawals as $wd)`
(`:269`) tambahkan blok variabel view (pola preseden deposit `:166-177`):

```php
<?php
    // plan/109: nilai yang WAJIB ditransfer = NET (gross − biaya admin).
    // gross_eff/fee_eff/net_eff sudah di-dekorasi model (fallback legacy).
    $wd_gross = (int) $wd->gross_eff;
    $wd_fee   = (int) $wd->fee_eff;
    $wd_net   = (int) $wd->net_eff;
    $wd_dest  = $wd->bank_name . ' - ' . $wd->account_number . ' a/n ' . $wd->account_name;
    $wd_confirm = 'Pastikan Anda SUDAH mentransfer Rp ' . number_format($wd_net, 0, ',', '.')
        . ' ke ' . $wd_dest . '. Setujui ' . $wd->wd_number . '?'
        . ' (Penarikan Rp ' . number_format($wd_gross, 0, ',', '.')
        . ' | Biaya Rp ' . number_format($wd_fee, 0, ',', '.') . ')';
?>
```

**Sebelum** (`:279-282`):

```php
<div class="text-right">
    <div class="text-sm font-bold text-[var(--t-text)] font-mono">Rp <?= number_format($wd->amount, 0, ',', '.') ?></div>
    <div class="text-[11px] text-[var(--t-muted)] mt-0.5"><?= date('d M Y H:i', strtotime($wd->created_at)) ?></div>
</div>
```

**Sesudah** (Bloomberg-Terminal dark: token `var(--t-*)` + aksen amber yang sudah
dipakai antrean penarikan):

```php
<div class="text-right shrink-0">
    <div class="text-[10px] uppercase tracking-widest text-amber-600 dark:text-amber-400 font-extrabold">Wajib Transfer (Net)</div>
    <div class="text-base font-extrabold text-amber-600 dark:text-amber-300 font-mono">Rp <?= number_format($wd_net, 0, ',', '.') ?></div>
    <div class="text-[11px] text-[var(--t-muted)] mt-0.5 font-mono">
        (Penarikan: Rp <?= number_format($wd_gross, 0, ',', '.') ?> | Biaya: Rp <?= number_format($wd_fee, 0, ',', '.') ?>)
    </div>
    <div class="text-[11px] text-[var(--t-muted)] mt-0.5"><?= date('d M Y H:i', strtotime($wd->created_at)) ?></div>
</div>
```

Baris identitas/tujuan (`:271-278`) tetap: `wd_number`, `phone`, dan
`bank_name · account_number · account_name` (**utuh, tidak di-mask** — admin
harus mentransfer ke nomor itu).

**Tombol Approve** (`:285`) — konfirmasi diperkaya (D8):

```php
<?= form_open('admin/approve_withdrawal/' . $wd->id, [
        'class'              => 'flex-1',
        'data-guard-submit'  => '1',
        'onsubmit'           => "return confirm('" . str_replace("'", "\\'", $wd_confirm) . "')",
    ]) ?>
```

Tombol Decline (`:290`) tidak diubah.

### P6 — Riwayat penarikan admin: pisahkan Gross / Biaya / Net

`views/admin/history.php` — header (`:60-65`) menjadi kondisional per tab:

```php
<?php if ($type === 'withdrawal'): ?>
    <th class="text-left px-5 py-3 t-th">E-Wallet</th>
    <th class="text-right px-5 py-3 t-th">Gross</th>
    <th class="text-right px-5 py-3 t-th">Biaya</th>
    <th class="text-right px-5 py-3 t-th">Net (ditransfer)</th>
<?php else: ?>
    <th class="text-right px-5 py-3 t-th">Nominal</th>
<?php endif; ?>
```

Sel (`:84-101`) — cabang withdrawal memakai nilai terdekorasi, cabang deposit
tetap apa adanya:

```php
<?php if ($type === 'withdrawal'): ?>
    <td class="px-5 py-3.5 text-right font-mono t-text-2 whitespace-nowrap">Rp <?= number_format((int) $row->gross_eff, 0, ',', '.') ?></td>
    <td class="px-5 py-3.5 text-right font-mono text-rose-600 dark:text-rose-400 whitespace-nowrap">Rp <?= number_format((int) $row->fee_eff, 0, ',', '.') ?></td>
    <td class="px-5 py-3.5 text-right font-mono font-extrabold text-amber-600 dark:text-amber-300 whitespace-nowrap">Rp <?= number_format((int) $row->net_eff, 0, ',', '.') ?></td>
<?php else: ?>
    <?php /* blok nominal deposit plan/102 — TIDAK diubah */ ?>
<?php endif; ?>
```

Tidak ada `colspan`, empty state, atau paginasi yang perlu disesuaikan (dicek).

### P7 — Member: kartu penarikan tertunda

`views/wallet/index.php:242-246` — nilai primer menjadi NET; ledger (`:264-303`)
**tidak disentuh** (D2):

```php
<div class="text-right">
    <p class="text-[10px] uppercase tracking-widest u-muted font-bold"><?= lang('wd_received') ?></p>
    <p class="text-base font-extrabold u-text font-mono">Rp <?= number_format((int) $wd->net_eff, 0, ',', '.') ?></p>
    <p class="text-[10px] u-muted font-mono">
        <?= lang('wd_amount_label') ?>: Rp <?= number_format((int) $wd->gross_eff, 0, ',', '.') ?>
        · <?= lang('wd_admin_fee') ?>: Rp <?= number_format((int) $wd->fee_eff, 0, ',', '.') ?>
    </p>
    <p class="text-xs font-semibold text-orange-500 dark:text-orange-400"><?= lang('wallet_status_pending') ?></p>
</div>
```

Baris tujuan (`:238-240`, provider · nomor ter-mask · nama pemilik) dan tombol
simulasi dev (`:249-257`) tidak diubah. Seluruh label berasal dari `lang()` —
**nol** string literal baru (gate R1–R7 tetap bersih); `Rp` tetap literal
sebagai mata uang (dikecualikan L6).

### P8 — Member: preview form penarikan (3 baris eksplisit)

`views/wallet/withdraw.php:105-117` — tambah baris Gross di atas Fee, lalu Net
di baris terakhir (tetap di dalam kotak amber):

```php
<div class="flex items-center justify-between mb-1">
    <span class="text-[11px] text-amber-700 dark:text-amber-400 font-bold"><?= lang('wd_amount_label') ?></span>
    <span class="text-[11px] font-mono font-bold text-amber-700 dark:text-amber-400" id="wd_gross">Rp 0</span>
</div>
<div class="flex items-center justify-between border-t border-amber-200 dark:border-amber-500/20 pt-2 mb-1">
    <span class="text-[11px] text-amber-700 dark:text-amber-400 font-bold">
        <?= lang('wd_admin_fee') ?> <span id="wd_bps_label" class="opacity-70"><?= lang('wd_tier_label') ?></span>
    </span>
    <span class="text-[11px] font-mono font-bold text-amber-700 dark:text-amber-400" id="wd_fee">Rp 0</span>
</div>
<div class="flex items-center justify-between border-t border-amber-200 dark:border-amber-500/20 pt-2 mt-2">
    <span class="text-[11px] text-amber-800 dark:text-amber-300 font-extrabold"><?= lang('wd_received') ?></span>
    <span class="text-sm font-mono font-extrabold text-amber-800 dark:text-amber-300" id="wd_net">Rp 0</span>
</div>
```

JS (`:142-144` + `refresh()` `:244-257`) — **tanpa** perubahan aritmetika tier:

```js
var grossEl = document.getElementById('wd_gross');
// di cabang VALID:
grossEl.textContent = formatRupiah(amount);
// di cabang reset (valid === false):
grossEl.textContent = 'Rp 0';
```

### P9 — Kamus + guard notifikasi

**9a. Nilai kamus (EN & ID, key tidak berubah, D9)** — `english/app_lang.php`
dan `indonesian/app_lang.php`:

| Key | Baris | ID (sesudah) | EN (sesudah) |
|---|---|---|---|
| `wd_amount_label` | `:386` | `Nominal Penarikan (Gross)` | `Withdrawal Amount (Gross)` |
| `wd_admin_fee` | `:384` | `Biaya Admin (Fee)` | `Admin Fee (Fee)` |
| `wd_received` | `:393` | `Estimasi Dana Diterima (Net)` | `Estimated Net Received` |
| `notif_wd_approved_body` | `:547` | `Penarikan sebesar Rp %s telah diproses. Biaya admin Rp %s. Dana Rp %s dikirim ke akun e-wallet Anda.` | `A withdrawal of Rp %s has been processed. Admin fee Rp %s. Rp %s has been sent to your e-wallet account.` |

> Catatan kecil (perlu keputusan saat eksekusi): EN `Admin Fee (Fee)` sengaja
> disamakan pola dengan ID demi simetri tiga label; alternatifnya EN cukup
> `Admin Fee`. Nilai EN ≠ ID tetap terjaga (syarat gate paritas).

**9b. Guard arity `i18n_notification_text()`** (`i18n_helper.php:312-325`) —
wajib, karena arity `notif_wd_approved_body` berubah 1 → 3 sementara baris lama
menyimpan `params` 1 elemen; `vsprintf()` di PHP 8 melempar
`ArgumentCountError` (bukan `Exception`, dan **tidak** tertangkap `@`):

```php
$stored = isset($row['message']) ? (string) $row['message'] : '';   // di awal fungsi

if ($params)
{
    $expected = substr_count($body, '%s');

    if ($expected > 0)
    {
        // plan/109: baris lama bisa punya arity lebih pendek dari kamus terbaru.
        // Pad argumen kosong agar slot yang sudah benar (gross = slot 1) tetap
        // benar, dan bungkus try/catch agar tidak pernah fatal.
        while (count($params) < $expected) { $params[] = '—'; }

        try {
            $rendered = vsprintf($body, array_slice($params, 0, $expected));
            if (is_string($rendered)) { $body = $rendered; }
        } catch (Throwable $e) {
            if ($stored !== '') { $body = $stored; }
        }
    }
}
```

**9c. Verifikasi arity placeholder** — `grep -c '%s'` pada
`notif_wd_approved_body` EN dan ID harus sama-sama **3** (gate paritas tidak
memeriksa arity, jadi ini dicek manual).

### P10 — Verifikasi & catatan perubahan

Jalankan §7 secara keseluruhan, lalu tulis ringkasannya di
`plan/109_WITHDRAWAL_NET_AMOUNT_DISPLAY_SUMMARY.md` (berkas terpisah, dibuat
saat eksekusi — **tidak** dibuat oleh dokumen ini).

---

## 7. Matriks Verifikasi

### 7.1 Statis (wajib)

```bash
php -l application/helpers/withdrawal_amount_helper.php
php -l application/config/autoload.php
php -l application/models/Admin_model.php
php -l application/models/Wallet_model.php
php -l application/controllers/Admin.php
php -l application/helpers/i18n_helper.php
php -l application/views/admin/dashboard.php
php -l application/views/admin/history.php
php -l application/views/wallet/index.php
php -l application/views/wallet/withdraw.php
php -l application/language/english/app_lang.php
php -l application/language/indonesian/app_lang.php

php scripts/audit_i18n_parity.php      # ekspektasi: LULUS, EN 602 / ID 602
php scripts/audit_i18n_hardcoded.php   # ekspektasi: 0 temuan
grep -c "%s" application/language/english/app_lang.php    # baris notif_wd_approved_body → 3
grep -c "%s" application/language/indonesian/app_lang.php # baris notif_wd_approved_body → 3
```

### 7.2 Pre-flight DB (read-only, menentukan apakah jalur legacy tersentuh)

```sql
SELECT status, COUNT(*) FROM withdrawals GROUP BY status;
SELECT COUNT(*) FROM withdrawals WHERE gross_amount <= 0 OR fee_amount <= 0 OR net_amount <= 0;
SELECT COUNT(*) FROM user_notifications WHERE title_key = 'notif_wd_approved';
```

- Baris pertama → memastikan ada minimal satu `pending` untuk uji konfirmasi.
- Baris kedua → bila **0**, jalur fallback legacy hanya aktif secara teoretis
  (tetap dipertahankan sebagai jaring pengaman).
- Baris ketiga → bila **0**, guard §9b tidak pernah mengeksekusi jalur pad; bila
  > 0, catat hasil render-nya di SUMMARY.

### 7.3 Probe helper (scratch di `/tmp`, dibersihkan setelah selesai)

Skrip sekali-pakai yang `define('BASEPATH', 'cli-probe')` lalu `require` helper,
menjalankan dua kasus:

| Input | Ekspektasi |
|---|---|
| `{amount:1000000, gross_amount:0, fee_amount:0, net_amount:0}` (legacy) + kalkulator tier | `gross 1000000 / fee 71500 / net 928500`, `legacy true` |
| `{amount:1000000, gross_amount:1000000, fee_amount:71500, net_amount:928500}` | nilai apa adanya, `legacy false` |

### 7.4 HTTP

| URL | Metode | Ekspektasi |
|---|---|---|
| `/admin` | GET | 200; kartu Pending Withdrawals menampilkan "Wajib Transfer (Net)" + sub-teks gross/fee |
| `/admin/history/withdrawal` | GET | 200; kolom Gross / Biaya / Net terpisah |
| `/admin/approve_withdrawal/{id}` | POST (dari dialog) | Dialog menyebut NET + provider/nomor/nama; setelah approve → flash menyebut NET; baris keluar dari antrean |
| `/admin/export_csv/withdrawals` | GET | 200, header CSV tetap `Gross (IDR), Fee (IDR), Net (IDR)` (nol regresi) |
| `/wallet` (member dengan WD pending) | GET | 200; kartu menampilkan "Estimasi Dana Diterima (Net)" + sub-teks nominal & biaya; blok ledger identik seperti sebelumnya |
| `/wallet/withdraw` | GET | 200; kotak amber menampilkan 3 baris; mengetik `1000000` → Gross Rp 1.000.000 / Fee Rp 71.500 / Net Rp 928.500 |
| `/wallet/simulate_wd_approve/{wd_number}` (dev/UAT) | POST | nol regresi (tidak mengirim notifikasi apa pun) |

---

## 8. Non-Goal (eksplisit)

1. **Tidak** menyentuh `wallet_ledger`: tidak ada perubahan query, kolom, atau
   tampilan ledger — debit tetap merekam **gross penuh** (D2).
2. **Tidak** menambah daftar riwayat penarikan member (halaman/kartu baru) —
   hanya kartu penarikan tertunda yang diubah.
3. **Tidak** mengubah aritmetika fee, tier, `withdrawal_fees.php`, atau
   `system_settings` apa pun.
4. **Tidak** mengubah status machine (`pending → success|failed`), timestamp,
   atau alur refund `decline_withdrawal`.
5. **Tidak** ada DDL, migrasi, seed, atau backfill.
6. **Tidak** ada route baru, key kamus baru, atau perubahan `routes.php`.
7. **Tidak** mengubah export CSV (sudah Gross/Fee/Net).
8. **Tidak** menyentuh `system/` (termasuk tidak menyentuh fix `Pagination.php`
   & entri `webp` di `mimes.php`).
9. **Tidak** mengubah notifikasi decline (`notif_wd_declined`) — dana kembali
   penuh = gross, sudah benar.
10. **Tidak** menambah key i18n di panel admin (copy admin Indonesia, L1).

---

## 9. Risiko & Mitigasi

| # | Risiko | Mitigasi |
|---|---|---|
| R1 | Baris notifikasi lama (`params` 1 elemen) → `ArgumentCountError` di PHP 8 saat dibaca | Guard arity §9b: pad argumen + `try/catch (Throwable)` + fallback snapshot `message`; pre-flight §7.2 baris 3 mengukur populasi nyata |
| R2 | Baris legacy `fee/net = 0` → nilai fallback bisa **berbeda** dari biaya historis yang benar-benar dipotong (bila setting tier berubah) | Fallback hanya diaktifkan saat `fee/net ≤ 0`/inkonsisten, memakai sumber tier yang sama dengan jalur CSV yang sudah live (`Admin.php:2495-2501`); read-side, tidak menulis apa pun; populasi diukur di §7.2 |
| R3 | Perubahan **nilai** kamus `wd_amount_label`/`wd_admin_fee`/`wd_received` bocor ke surface lain | Ketiga key terbukti **hanya** dipakai di `views/wallet/withdraw.php` (`:84`, `:109`, `:114`) — grep pra-edit; gate paritas + hardcoded dijalankan ulang |
| R4 | `gross_eff/fee_eff/net_eff` dipakai view yang belum di-dekorasi → nilai NULL | Ketiga konsumen (queue dashboard, riwayat, pending member) **hanya** menerima baris dari model yang sudah di-dekorasi; tidak ada konsumen lain (grep `get_pending_withdrawals` → 1 call-site) |
| R5 | Pindah query ke `Admin_model` (D7) mengubah perilaku antrean | Query dipindahkan **apa adanya** (select/join/where/order identik) + dekorasi aditif; smoke test `/admin` §7.4 |
| R6 | Konfirmasi baru memblokir/mengganggu guard double-submit | Inline `onsubmit` dieksekusi lebih dulu daripada guard (`csrf_meta.php:60-62`); teks di-eskapasi `str_replace("'", "\\'", …)` (preseden deposit) |
| R7 | Nomor e-wallet ter-expose lebih luas di admin | Sudah utuh sebelum plan ini (antrean dashboard `:276`); admin memang wajib tahu tujuan transfer. Member tetap ter-mask (`ewallet_phone_mask`) — tanpa perubahan |
| R8 | Teks `Rp`/`number_format` di view dianggap leak i18n | Uang & angka memang tidak boleh diterjemahkan (L6); gate P3/R1–R7 tetap dijalankan untuk membuktikan nol temuan |

---

## 10. Rollback

1. `git revert <commit>` — seluruh perubahan adalah view/model/controller/kamus +
   satu berkas helper baru.
2. **Tidak ada** DDL, migrasi, backfill, atau mutasi baris `withdrawals` /
   `wallet_ledger` → tidak ada data yang perlu dipulihkan.
3. Notifikasi yang **sudah** terkirim dengan arity 3 tetap terbaca benar pada
   versi lama? Tidak — rollback versi kode mengembalikan arity kamus ke 1
   sementara `params` baris baru berisi 3 elemen; `vsprintf` dengan argumen
   berlebih aman (argumen ekstra diabaikan), sehingga baris baru tetap ter-render.
   Guard §9b tetap dipertahankan bila hanya kamus yang di-rollback.
4. Tidak ada `system_settings`/`uploads/`/`backups/` yang tersentuh → tidak ada
   langkah pembersihan.

---

## 11. Temuan Sampingan (di luar scope, untuk follow-up)

1. **Header CSV admin berbahasa Inggris** (`Gross (IDR)`, `Fee (IDR)`, …;
   `Admin.php:2483-2486`) — menyimpang dari invariant L1 (admin 100% Indonesia).
   Bukan bagian plan ini (fungsional & sudah benar); kandidat plan tersendiri.
2. **Riwayat penarikan member tidak ada** — member hanya melihat WD `pending`
   plus entri ledger; setelah `success`/`failed` tidak ada jejak terstruktur.
   Kandidat `plan/110` bila owner ingin kartu riwayat (butuh method model baru +
   key kamus baru).
3. **`withdrawals.status = 'processing'`** ada di ENUM dan seed, tetapi tidak
   pernah ditulis oleh alur saat ini (`pending → success|failed`) dan tidak
   disertakan filter riwayat (`['success','failed']`). Perlu keputusan
   produk: hapus dari ENUM atau implementasikan.
4. **Notifikasi decline** menyebut nominal gross (`Admin.php:290-295`) — benar
   secara semantik (dana dikembalikan penuh), namun bisa diberi keterangan
   "(dikembalikan penuh)" agar tidak dikira net.
