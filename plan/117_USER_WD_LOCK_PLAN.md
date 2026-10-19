# PLAN 117 — KUNCI PENARIKAN PER-USER (PER-USER WITHDRAWAL LOCK)

- **Branch:** `feat/117-user-wd-lock` (roadmap rule 4: satu branch per fase)
- **Status:** PLAN — menunggu persetujuan owner sebelum eksekusi
- **Basis keputusan owner:** `dec-1ebc037f6f2688b7`
- **Tipe perubahan:** schema (2 kolom additive) + admin (AJAX mutator + UI) + member
  gate 3 lapis + i18n (6 key). **Tanpa tabel baru** (inventory kanonik tetap 14),
  **tanpa perubahan `wallet_ledger`**, **tanpa entri `routes.php`**, **tanpa
  perubahan `system/`**.

---

## 0. Ringkasan & Keputusan

Saat ini seluruh gerbang penarikan bersifat **global** (jam operasional
Mon–Sat 07:00–19:00 WIB, single-pending, daily limit, anti free-rider
`has_paid_rental()`, binding e-wallet aktif). Belum ada kemampuan admin untuk
**membekukan penarikan satu user tertentu** tanpa mem-ban akunnya.

Plan/117 menambah **Withdrawal Lock per-user**: user yang terkunci tetap bisa
login, menjelajah, menyewa, klaim ROI/absensi — hanya **pengajuan penarikan
baru** yang ditolak. Alasan lock bersifat **internal admin** dan **tidak pernah**
dikirim ke permukaan member.

### Keputusan (owner, `dec-1ebc037f6f2688b7`)

| # | Keputusan |
|---|---|
| **D1** | Kunci **hanya memblokir pengajuan BARU**. Penarikan `pending` yang sudah ada **dibiarkan** — admin tetap bisa approve/decline seperti biasa. **Tanpa clawback / auto-refund** (TIDAK ada penulisan `wallet_ledger` saat lock/unlock). |
| **D2** | Lock/unlock **tidak** menyentuh `withdrawals`, `wallet_ledger`, `bank_accounts`, atau `user_rentals`. Perubahan = **1 baris `users`** + 1 baris `system_audit_logs` (+ 1 notifikasi). |
| **D3** | Member **diberi notifikasi** dwibahasa generik: `notif_wd_locked`, `notif_wd_unlocked` (pola M5/N3 seperti `notif_unbanned` / `notif_ewallet_reset`). Teks notifikasi **tanpa** alasan admin. |
| **D4** | **Privasi alasan:** `wd_lock_reason` hanya boleh dibaca di `Admin*` dan `Wallet_model` (internal). Member hanya menerima boolean + pesan generik `wd_err_locked`. Diverifikasi dengan gate grep (E15). |
| **D5** | **UI prompt admin:** modal Tailwind bawaan repo (pola `#createUserModal`), **tanpa dependensi CDN baru** (preseden plan/97–98 & 100). Native `confirm()` dipakai untuk unlock (pola toggle existing). |
| **D6** | **AJAX** (`csrfFetch` + envelope `api_helper`) untuk mutator admin — preseden nyata: `admin/toggle_registration` & `admin/toggle-maintenance` (plan/95, `views/admin/dashboard.php:346,386`). |

### Invarian baru (didaftarkan di `docs/2_ERD.md`, E14)

- **W1 — Satu sumber kebenaran:** `users.is_wd_locked` adalah **satu-satunya**
  penanda; hanya berubah lewat toggle admin ber-audit `admin_toggle_wd_lock`
  (before→after + IP + alasan) di dalam TX.
- **W2 — Gate tiga lapis (cermin plan/114):** UI `/wallet` (tombol disabled) →
  gatekeeper GET `Wallet::withdraw()` → gatekeeper POST
  `Wallet::process_withdraw()` → **otoritas finansial** di dalam TX terkunci
  `Wallet_model::create_withdrawal()`. Lapisan atas hanya UX; keputusan uang di TX.
- **W3 — Privasi alasan:** `wd_lock_reason` **tidak pernah** dirender di
  permukaan member (tidak ada view/controller member yang membacanya).
- **W4 — Konsistensi data:** `wd_lock_reason IS NULL` **selalu** saat
  `is_wd_locked = 0` (dipaksa server saat unlock + fase NORMALIZE migrasi).
- **W5 — Tanpa clawback:** lock/unlock tidak mengubah `withdrawals` maupun
  `wallet_ledger` (D1/D2); `Z1/Z2` (ledger tunggal) tetap utuh.

---

## 1. Skema & Migrasi

### 1.1 DDL (`database.sql`) — kanonik

Tambah **2 kolom** pada `CREATE TABLE IF NOT EXISTS users` (`database.sql:11-41`),
disisipkan **setelah `checkin_last_date`** (`database.sql:32`) dan sebelum
`created_at`:

```sql
  -- plan/117: KUNCI PENARIKAN PER-USER (Withdrawal Lock) — mitigasi fraud.
  -- `is_wd_locked = 1` memblokir PENGAJUAN penarikan BARU pada 4 lapis:
  -- tombol /wallet, gatekeeper GET /wallet/withdraw, POST
  -- /wallet/process_withdraw, dan otoritas finansial di dalam TX terkunci
  -- `Wallet_model::create_withdrawal()`. BUKAN ban: user tetap bisa login,
  -- menyewa, klaim ROI & absensi (beda dari `is_banned`).
  -- TIDAK membatalkan penarikan `pending` yang sudah ada (keputusan D1) dan
  -- TIDAK menulis `wallet_ledger`/`withdrawals` (D2).
  -- `wd_lock_reason` = alasan INTERNAL admin (VARCHAR 255, NULL = terbuka);
  -- kolom ini TIDAK PERNAH dikirim ke permukaan member (invarian W3).
  -- Hanya diubah lewat toggle admin ber-audit `admin_toggle_wd_lock` (W1).
  `is_wd_locked` TINYINT(1) NOT NULL DEFAULT 0,
  `wd_lock_reason` VARCHAR(255) NULL DEFAULT NULL,
```

Plus blok komentar **`-- Plan 117 — MIGRASI LIVE`** di akhir `database.sql`
(setelah blok plan/114, ~`:736`), meniru pola plan/104/112/114: perintah
`--dry-run/--apply/--verify`, referensi SQL yang dijalankan tool, ekspektasi
verifikasi, dan catatan rollback manual (`ALTER TABLE users DROP COLUMN
is_wd_locked, DROP COLUMN wd_lock_reason;`).

⚠️ **TIDAK** boleh memakai klausa `AFTER` di dalam `CREATE TABLE` (F17,
plan/116) — `AFTER` hanya valid di `ALTER TABLE … ADD COLUMN`.

### 1.2 `database_seed.sql` — tidak berubah

Kolom bersifat **additive dengan DEFAULT/NULL** dan tidak ada baris seed user
yang terlibat: `INSERT INTO users` (`database_seed.sql:80`) memakai daftar kolom
eksplisit + `ON DUPLICATE KEY UPDATE`, sehingga kolom baru otomatis `0`/`NULL`.
Tidak ada kunci `system_settings` baru. **Nol perubahan.**

### 1.3 `scripts/migrate_117_user_wd_lock.php` (BARU)

Mengikuti kontrak baku (roadmap rule 6; pola `scripts/migrate_114_*.php`):

```
--dry-run (default) : inspeksi + rencana, tanpa menulis
--apply             : DDL + normalisasi + verifikasi
--verify            : read-only; exit 2 bila drift
--help | -h         : usage
Exit: 0 = bersih/no-op, 1 = pre-flight gagal, 2 = apply/verify gagal
```

**Loader DB-config WAJIB memakai pola plan/106/112/114** — semantik CI3
`$active_group` → `'default'` → grup pertama (`application/config/database.php`
hanya mendefinisikan `local`/`dev`/`live`). Loader `$db['default']` adalah bug
yang sudah terdokumentasi di §Notes AGENTS.md — **jangan** menyalinnya.

Fase:

1. **PRE-FLIGHT** — tabel `users` ada; kolom anchor `users.checkin_last_date`
   ada (dipakai klausa `AFTER`); koneksi mysqli TCP OK (`localhost → 127.0.0.1`).
2. **INSPECT** — `information_schema.COLUMNS` untuk `is_wd_locked` /
   `wd_lock_reason` (sudah ada / belum) + hitung:
   - `COUNT(*)` user, `COUNT(*) WHERE is_wd_locked = 1`,
   - **orphan reason**: `WHERE is_wd_locked = 0 AND wd_lock_reason IS NOT NULL`,
   - **locked tanpa alasan**: `WHERE is_wd_locked = 1 AND
     (wd_lock_reason IS NULL OR wd_lock_reason = '')` → `[WARN]`, bukan tamper.
3. **DDL** — 2 × `ADD COLUMN` dijaga `information_schema` (MySQL 8 tidak punya
   `ADD COLUMN IF NOT EXISTS`; MariaDB punya — tool tetap memeriksa lebih dulu
   agar idempoten di kedua engine):
   ```sql
   ALTER TABLE `users`
     ADD COLUMN `is_wd_locked`   TINYINT(1)   NOT NULL DEFAULT 0     AFTER `checkin_last_date`,
     ADD COLUMN `wd_lock_reason` VARCHAR(255) NULL     DEFAULT NULL  AFTER `is_wd_locked`;
   ```
   **TANPA backfill** — baris lama memakai DEFAULT 0.
4. **NORMALIZE** — `UPDATE users SET wd_lock_reason = NULL WHERE is_wd_locked = 0
   AND wd_lock_reason IS NOT NULL;` (menegakkan W4; melaporkan jumlah baris).
   Hanya di `--apply`; re-run = 0 baris.
5. **GUARD** — tipe/nullability/default kedua kolom via `information_schema`
   (`TINYINT`, `NO`, `0` / `VARCHAR(255)`, `YES`, `NULL`) + orphan reason == 0.
   Pelanggaran = tamper → **exit 2**.
6. **VERIFY** — ulangi 5 secara read-only.

Header docblock wajib memuat: **⚠️ URUTAN RILIS — jalankan migrasi ini SEBELUM
deploy kode plan/117** (query admin `get_users()` + gate Wallet membaca kolom
baru; deploy kode lebih dulu = admin user list error & gate melempar notice
kolom tidak ada).

**Discovery saat perencanaan (bukan bagian perubahan):** `database.sql` tidak
mendefinisikan `users.username` / `users.role`, sedangkan `database_seed.sql`
menambahkannya lewat blok legacy-ALTER (`database_seed.sql:14,16`). Divergensi
kanonik ini **pre-existing** dan **tidak disentuh** plan/117 — dicatat agar
`--verify` tidak salah menandainya sebagai drift.

---

## 2. Model — Choke-point

### 2.1 `application/models/Wallet_model.php`

**Tambahan 1 — pembaca state (publik, dipakai controller & view member):**

```php
/**
 * plan/117: state kunci penarikan per-user (W1).
 * Permukaan MEMBER hanya boleh memakai boolean `locked` — `reason` bersifat
 * internal admin (invarian W3).
 *
 * @return array{found:bool, locked:bool, reason:string|null}
 */
public function get_wd_lock_state($user_id)
{
    $row = $this->db->query(
        "SELECT is_wd_locked, wd_lock_reason FROM users WHERE id = ? LIMIT 1",
        [(int) $user_id]
    )->row();

    if (!$row) {
        return ['found' => false, 'locked' => false, 'reason' => null];
    }

    return [
        'found'  => true,
        'locked' => ((int) $row->is_wd_locked === 1),
        'reason' => ($row->wd_lock_reason !== null && $row->wd_lock_reason !== '')
            ? (string) $row->wd_lock_reason : null,
    ];
}

/** plan/117: sugar boolean untuk gatekeeper controller (baca NON-locking). */
public function is_withdrawal_locked($user_id)
{
    return $this->get_wd_lock_state($user_id)['locked'];
}
```

Diletakkan di dekat pembaca kebijakan withdrawal (mis. setelah
`has_reached_daily_wd_limit()` / sebelum `create_withdrawal()`).

**Tambahan 2 — GERBANG OTORITATIF di `create_withdrawal()`**
(`application/models/Wallet_model.php:1209-1341`):

Disisipkan **sebagai langkah paling awal setelah anchor lock**, yaitu tepat
setelah blok `if ($fresh_balance === false) { … }` (baris **1221**) dan sebelum
pemeriksaan binding e-wallet saat ini (baris 1229-1239):

```php
            // 1a. plan/117 — GERBANG KUNCI PENARIKAN PER-USER (OTORITAS, di
            //     dalam TX terkunci). Alasan: `SELECT … FROM users … FOR UPDATE`
            //     sudah dilakukan `lock_and_get_balance()`, sehingga read view
            //     TX ini dibuat SETELAH lock wait → nilai `is_wd_locked` yang
            //     dibaca di sini PASTI segar (admin yang mengunci bersamaan
            //     tidak bisa ditembus). Rollback di sini = 0 baris
            //     `withdrawals` + 0 baris `wallet_ledger` (D1/D2, imutabilitas Z1).
            if ($this->is_withdrawal_locked($user_id)) {
                $this->db->trans_rollback();
                return ['success' => false, 'code' => 'wd_locked',
                    'message' => 'Penarikan ditangguhkan oleh admin.',
                    'wd_number' => null];
            }
```

**Renumbering komentar** langkah yang bergeser (hanya label komentar, tanpa
perubahan logika): `1a` (binding e-wallet) → `1b`; `1b` (`has_paid_rental`) →
`1c`; `1c` (kebijakan operasional/tier) → `1d`. Langkah `2`–`5` tidak berubah.

**Docblock `@return`** (baris 1202-1208) ikut ditambah: `| 'wd_locked'`.

> Catatan urutan: lock sengaja diperiksa **sebelum** `no_ewallet` dan
> `no_paid_rental` — kebijakan admin adalah gerbang terkuat dan alasan
> penguncian tidak boleh tertutup oleh pesan teknis lain.

### 2.2 `application/models/Admin_model.php`

**`set_wd_lock($id, $locked, $reason)`** — diletakkan dekat `toggle_ban()`
(`Admin_model.php:398`) — **satu-satunya penulis** `is_wd_locked` /
`wd_lock_reason`:

```php
/**
 * plan/117: set state kunci penarikan user (W1).
 * - $locked = true  : is_wd_locked = 1, wd_lock_reason = $reason (wajib terisi)
 * - $locked = false : is_wd_locked = 0, wd_lock_reason = NULL (W4 — selalu)
 * Pemanggil (Admin::toggle_wd_lock) yang membungkus TX + audit.
 *
 * @return array{found:bool, before:int, after:int, affected:int}
 */
public function set_wd_lock($id, $locked, $reason = null)
{
    $id     = (int) $id;
    $locked = $locked ? 1 : 0;
    $reason = ($locked === 1) ? (string) $reason : null; // unlock SELALU null

    $user = $this->db->select('id, is_wd_locked')->where('id', $id)->get('users')->row();
    if (!$user) {
        return ['found' => false, 'before' => 0, 'after' => $locked, 'affected' => 0];
    }

    $this->db->where('id', $id)->update('users', [
        'is_wd_locked'   => $locked,
        'wd_lock_reason' => $reason,
    ]);

    return [
        'found'    => true,
        'before'   => (int) $user->is_wd_locked,
        'after'    => $locked,
        'affected' => (int) $this->db->affected_rows(), // 0 = no-op (state sama)
    ];
}
```

**`get_users()`** (`Admin_model.php:172-202`) — tambah 2 kolom ke SELECT agar
badge di tabel user list bisa dirender:
`u.is_wd_locked, u.wd_lock_reason`. ⚠️ **Jangan mengubah** `u.role` /
`u.phone` / subquery saldo — `u.role` hanya ada di DB live + blok legacy-ALTER
seed (bukan di `database.sql`); ketidaksesuaian itu **di luar scope**.

---

## 3. Admin Panel

### 3.1 `Admin::toggle_wd_lock($id)` — AJAX mutator (BARU)

Diletakkan setelah `toggle_promoter()` (`application/controllers/Admin.php:1157`).
Kontrak (preseden `toggle_maintenance()`, `Admin.php:1812-1855`):

```php
public function toggle_wd_lock($id)
{
    // M4: POST-only fail-closed — mutasi tidak boleh via GET (405 JSON).
    if ($this->input->method() !== 'post') {
        api_error('Method not allowed', 405, [], 'method_not_allowed',
            ['error' => 'Method not allowed']);
        return;
    }

    $this->load->model('Admin_model');
    $id = (int) $id;

    // Intent EKSPLISIT dari klien (bukan toggle implisit) → anti race pada
    // klik ganda: `lock=1` mengunci, `lock=0` membuka. Selain itu → 422.
    $lock = (string) $this->input->post('lock', TRUE);
    if ($lock !== '1' && $lock !== '0') {
        api_error('Permintaan tidak valid.', 422, ['lock' => 'invalid'], 'invalid_request',
            ['error' => 'Permintaan tidak valid.']);
        return;
    }
    $target = ($lock === '1');

    // Alasan WAJIB saat mengunci (admin harus memberi konteks).
    $reason = trim((string) $this->input->post('reason', TRUE));
    if ($target) {
        if ($reason === '') {
            api_error('Alasan penguncian wajib diisi.', 422, ['reason' => 'required'],
                'reason_required', ['error' => 'Alasan penguncian wajib diisi.']);
            return;
        }
        if (mb_strlen($reason) > 255) {
            api_error('Alasan maksimal 255 karakter.', 422, ['reason' => 'too_long'],
                'reason_too_long', ['error' => 'Alasan maksimal 255 karakter.']);
            return;
        }
    } else {
        $reason = null; // unlock selalu membersihkan alasan (W4)
    }

    $this->load->model('Audit_model');

    // M5: update state + audit ATOMIK dalam SATU TX.
    $this->db->trans_start();
    $res = $this->Admin_model->set_wd_lock($id, $target, $reason);
    $changed = ($res['found'] && $res['affected'] === 1);

    if ($changed) {
        $this->Audit_model->log_admin_action(
            (int) $this->session->userdata('admin_id'),
            $id,
            'admin_toggle_wd_lock',
            [
                'before' => $res['before'] === 1 ? 'locked' : 'unlocked',
                'after'  => $res['after'] === 1 ? 'locked' : 'unlocked',
                'reason' => $reason,              // internal, admin-only (W3)
            ],
            $this->input->ip_address()
        );
    }
    $this->db->trans_complete();

    if (!$res['found']) {
        api_error('User tidak ditemukan.', 404, [], 'user_not_found',
            ['error' => 'User tidak ditemukan.']);
        return;
    }

    if (!$changed) {
        // Idempoten: state sudah sama → 200 tanpa audit/notifikasi baru (A1).
        $message = $target ? 'Penarikan user sudah dalam keadaan terkunci.'
                           : 'Penarikan user sudah dalam keadaan terbuka.';
        api_success(
            ['user_id' => $id, 'is_wd_locked' => $target, 'changed' => false],
            $message, 200,
            ['is_wd_locked' => $target, 'changed' => false, 'message' => $message]
        );
        return;
    }

    // M5/N3: notifikasi member POST-COMMIT (generik, tanpa alasan — D3/W3).
    $this->load->model('Notification_model');
    $this->Notification_model->insert_keyed(
        $id,
        $target ? 'notif_wd_locked' : 'notif_wd_unlocked',
        [],
        'info'
    );

    $message = $target
        ? 'Penarikan user berhasil DIBLOKIR.'
        : 'Kunci penarikan user berhasil DIBUKA (alasan dihapus).';
    api_success(
        ['user_id' => $id, 'is_wd_locked' => $target, 'changed' => true],
        $message, 200,
        ['is_wd_locked' => $target, 'changed' => true, 'message' => $message]
    );
}
```

**Kontrak envelope (M9/P7):** `{success, message, data, code?, errors}` **plus**
key legacy root (`is_wd_locked`, `changed`, `message`, `error`) — nol bentuk
hand-rolled, nol respons HTML.

**Copy 100% Indonesia** (invarian L1: admin tidak pernah memuat kamus/i18n).

### 3.2 Route

**Tidak ada perubahan `application/config/routes.php`.** `admin/toggle_wd_lock/5`
adalah **URL metode langsung 2-segmen** yang dipetakan CI3 apa adanya — pola
identik `admin/toggle_ban/{id}` dan `admin/reset_ewallet/{id}` yang memang
didokumentasikan sebagai pengecualian yang sengaja (AGENTS §Route registration).
(Alternatif berhubung `translate_uri_dashes = FALSE`: memakai
`admin/toggle-wd-lock/{id}` **wajib** menambah `$route[...]` — **tidak dipilih**.)

### 3.3 Audit & notifikasi — rule check

- `admin_toggle_wd_lock` **muncul otomatis** di dropdown filter `/admin/audit`
  (`Audit_model::get_action_options()` menurunkan opsi dari DB) dan view
  mewarnainya lewat cabang `strpos($log->action,'toggle') !== false`
  (`views/admin/audit.php:112`) → **nol perubahan view audit**.
- Audit & notifikasi **hanya** saat transisi nyata (`affected === 1`) — no-op
  idempoten tidak menulis baris audit palsu (A1/A5-spirit).

### 3.4 `views/admin/templates/wd_lock_modal.php` (BARU)

Partial modal generik (satu modal melayani semua baris & kedua halaman), tampilan
mengikuti `#createUserModal` (`views/admin/users.php:134-192`) — `t-modal`,
`t-modal-backdrop`, dark/light token sudah tersedia, **nol dependensi baru**.

Struktur:
- `#wdLockModal.hidden.fixed.inset-0.z-[60]` + backdrop `onclick` menutup.
- judul `<i class="fas fa-lock text-rose-500"></i> Kunci Penarikan (Withdrawal Lock)`.
- teks target: `<span id="wdLockPhone">` (diisi JS; halaman admin sudah
  menampilkan phone penuh).
- `<form id="wdLockForm">` + `input type="hidden" id="wdLockUserId"`,
  `input id="wdLockReason" maxlength="255" required` + label
  *"Alasan penguncian (internal, tidak ditampilkan ke member)"*,
  catatan statis: *"Alasan tidak pernah ditampilkan ke member."*
- `<p id="wdLockError" class="hidden …t-flash-error…">` untuk pesan error server.
- Tombol: `Kunci Penarikan` (submit) & `Batal` (tutup).

JS (inline, di partial yang sama):

```js
window.openWdLockModal = function (userId, label) { /* isi field, reset error,
    reset reason, tampilkan modal, focus */ };
window.submitWdLock = function (e) { /* preventDefault; validasi reason non-kosong
    (pesan Indonesia, tanpa lang()); FormData{lock:'1', reason};
    csrfFetch('admin/toggle_wd_lock/'+uid, {method:'POST', body: fd})
      → r.json() → success ? location.reload()
                          : tampilkan data.message||data.error di #wdLockError
      → .catch(...) → #wdLockError 'Terjadi kesalahan jaringan.' */ };
window.unlockWdLock = function (userId) { /* confirm('Buka kunci penarikan user
    ini? Alasan akan dihapus.') → csrfFetch(..., FormData{lock:'0'}) → reload */ };
```

**Reload halaman setelah sukses** (bukan bedah DOM) — pilihan sadar: server
render adalah satu-satunya sumber kebenaran badge/alasan; menghindari state
badge basi di daftar 50 baris. Guard `data-guard-submit` di modal mencegah
double-POST.

### 3.5 `views/admin/users.php`

1. **Badge status** (sel Status, setelah badge PROMOTOR, baris ~89):
   ```php
   <?php if (!empty($u->is_wd_locked)): ?>
       <span class="t-badge" style="background:rgba(244,63,94,.12);color:#e11d48;border:1px solid rgba(244,63,94,.35);"
             title="<?= htmlspecialchars((string) ($u->wd_lock_reason ?? ''), ENT_QUOTES) ?>">
           <i class="fas fa-lock text-[10px]"></i> WD LOCK
       </span>
   <?php endif; ?>
   ```
   (Alasan tampil sebagai `title` **di panel admin saja**.)

2. **Tombol aksi** (sel Aksi, setelah `toggle_ban`, baris ~117) — **tanpa
   form**, murni `button` + `onclick`:
   ```php
   <?php if (!empty($u->is_wd_locked)): ?>
       <button type="button" onclick="unlockWdLock(<?= (int) $u->id ?>)"
               class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-medium hover:bg-emerald-700 transition-colors">
           <i class="fas fa-lock-open text-[10px]"></i> Buka WD Lock
       </button>
   <?php else: ?>
       <button type="button"
               onclick="openWdLockModal(<?= (int) $u->id ?>, '<?= htmlspecialchars($u->phone, ENT_QUOTES) ?>')"
               class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-rose-600 text-white text-xs font-medium hover:bg-rose-700 transition-colors">
           <i class="fas fa-lock text-[10px]"></i> Kunci WD
       </button>
   <?php endif; ?>
   ```

3. **Include partial** di akhir file (setelah `#createUserModal`):
   `<?php $this->load->view('admin/templates/wd_lock_modal'); ?>`
   (di-include juga dari `user_detail.php` — partial aman dimuat di halaman
   berbeda, tidak ada `id` ganda dalam satu halaman).

4. **Tidak menambah kolom tabel** → `colspan="9"` tetap
   (`views/admin/users.php:61`).

### 3.6 `views/admin/user_detail.php`

1. **Badge hero** (blok badge kanan, setelah badge PROMOTOR ~baris 48-52):
   badge `WD LOCK` (rose) bila `!empty($user->is_wd_locked)` —
   `$user` berasal dari `Admin_model::get_user_detail()` = `u.*` → kolom baru
   otomatis tersedia (nol perubahan controller detail).

2. **Kartu baru "Penarikan (Withdrawal Lock)"** — disisipkan sebelum kartu
   *Rentals Manipulation* (`views/admin/user_detail.php:320`), gaya `t-card p-6`:
   - status: badge `TERKUNCI` / `TERBUKA`;
   - bila terkunci: blok alasan (amber) — `htmlspecialchars((string) $user->wd_lock_reason)`
     + baris kecil *"Alasan bersifat internal dan tidak ditampilkan ke member."*;
   - **catatan kebijakan** (statis): *"Kunci ini hanya memblokir PENGAJUAN
     penarikan baru. Penarikan yang sudah pending tetap diproses admin."* (D1);
   - tombol `Kunci WD` / `Buka WD Lock` (fungsi JS yang sama).

3. **Include** `<?php $this->load->view('admin/templates/wd_lock_modal'); ?>`
   di akhir halaman.

> Halaman list maupun detail **tidak** mengirim flashdata — semua umpan balik
> berasal dari AJAX (JSON) dan di-render ulang saat reload. Tidak ada perubahan
> pada controller `Admin::users()` / `Admin::user_detail()`.

---

## 4. Permukaan Member

### 4.1 `application/controllers/Wallet.php`

**(a) Peta pesan** — tambah 1 entri ke `WD_ERR_KEYS` (`Wallet.php:28-46`):

```php
        // plan/117: kunci penarikan per-user (kebijakan admin, W2/W3).
        // Pesan SENGAJA generik — alasan admin tidak pernah disertakan.
        'wd_locked'      => 'wd_err_locked',
```

Kode lain tidak berubah; `_wd_message()` (`:93-112`) otomatis menangani
`wd_locked` (bukan anggota `AMOUNT_KEYS`, tanpa `sprintf`).

**(b) Gatekeeper 0 di `Wallet::withdraw()`** — disisipkan tepat setelah
`$user_id = $this->session->userdata('user_id');` (**baris 357**), sebelum
"Gatekeeper 1: pending withdrawal":

```php
        // Gatekeeper 0 (plan/117): KUNCI PENARIKAN PER-USER — gerbang admin
        // paling kuat, diperiksa paling awal. Redirect + flashdata (pola
        // gatekeeper 1–4); otoritas finansial tetap di TX
        // Wallet_model::create_withdrawal(). Pesan generik (W3).
        if ($this->Wallet_model->is_withdrawal_locked($user_id)) {
            $this->session->set_flashdata('error', lang('wd_err_locked'));
            redirect('wallet');
            return;
        }
```

**(c) Gatekeeper 0 di `Wallet::process_withdraw()`** — disisipkan tepat setelah
`$user_id = …` (**baris 442**), **sebelum** blok rate limit (`:444-456`) — user
terkunci ditolak segera tanpa menggerus kuota rate-limit penarikan:

```php
        // Gatekeeper 0 (plan/117): kunci penarikan per-user. Menutup jalur
        // POST langsung (curl/tamper) yang melewati UI dan /wallet/withdraw.
        if ($this->Wallet_model->is_withdrawal_locked($user_id)) {
            $this->session->set_flashdata('error', lang('wd_err_locked'));
            redirect('wallet');
            return;
        }
```

**(d) `Wallet::index()`** — tambah 1 key ke array `$data` (**dekat baris 136-141**):

```php
            // plan/117: state kunci penarikan (HANYA boolean — alasan admin
            // tidak pernah masuk permukaan member, invarian W3).
            'wd_locked'             => $this->Wallet_model->is_withdrawal_locked($user_id),
```

Tidak ada perubahan pada `MY_Controller` (kunci tidak memblokir login/aksi lain —
itu domain `is_banned`), tidak ada perubahan header/nav.

### 4.2 `views/wallet/index.php`

**(a) Cabang tombol baru — PALING ATAS** rantai kondisi (`:35-56`), sebelum
cabang `has_pending_wd`:

```php
<?php if (!empty($wd_locked)): ?>
<button disabled class="flex-1 bg-rose-600/70 text-white text-sm font-bold py-2.5 rounded-xl cursor-not-allowed opacity-70">
    <i class="fas fa-lock mr-1"></i> <?= lang('wallet_wd_locked_btn') ?>
</button>
<?php elseif (!empty($has_pending_wd)): ?>
<?php /* … sisa rantai kondisi existing (pending → active rental → paid rental →
        daily limit → tombol normal) TIDAK diubah, hanya tergeser satu cabang. */ ?>
```

**(b) Banner pesan** (persyaratan "disabled with a lock icon **and a message**")
— kartu info tepat setelah kartu saldo (`views/wallet/index.php:59`):

```php
<?php if (!empty($wd_locked)): ?>
<div class="bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/20 rounded-xl p-3 text-xs font-bold text-rose-700 dark:text-rose-300 flex items-start gap-2">
    <i class="fas fa-circle-info mt-0.5"></i>
    <span><?= lang('wd_err_locked') ?></span>
</div>
<?php endif; ?>
```

**(c)** Tombol tidak menautkan ke `wallet/withdraw` saat terkunci (elemen
`<button disabled>`, bukan `<a>`) → tidak ada jalur UI ke form.

### 4.3 `views/wallet/withdraw.php` — state defensif

Controller **selalu** redirect saat terkunci, sehingga view ini normalnya tidak
pernah dirender dalam keadaan locked. Tetap ditambahkan state defensif (murah,
memakai key yang sama): tombol submit (`:124-127`) diberi
`<?= !empty($wd_locked) ? 'disabled' : '' ?>` + banner notice `wd_err_locked`
di bawah blok `#wdClosedNotice` (`:47-52`), dan `wd_locked`
(`false` pada render normal) ditambahkan ke config JS (`L`/`WD_CONFIG`)
sehingga logika enable/disable klien tetap `disabled` bila state terpaksa true.

---

## 5. i18n (parity L1/L6)

Kamus saat ini **633/633 key** (`grep -c '^\$lang\['` — hitung file, jangan
percaya angka ringkasan lama). Tambahan **6 key** → **639/639**, posisi alfabetis:

`application/language/english/app_lang.php`:

| Key | Posisi | Nilai EN |
|---|---|---|
| `wallet_wd_locked_btn` | sebelum `wallet_wd_need_paid` (`:362`) | `Withdrawal Suspended` |
| `wd_err_locked` | setelah `wd_err_insufficient` (`:493`) | `Your withdrawal is suspended. Please contact customer service.` |
| `notif_wd_locked_title` | blok `notif_*` (setelah `notif_unbanned_body`, `:601`) | `Withdrawal Suspended` |
| `notif_wd_locked_body` | idem | `Withdrawal access for your account is temporarily suspended by admin. Please contact customer service.` |
| `notif_wd_unlocked_title` | idem | `Withdrawal Reactivated` |
| `notif_wd_unlocked_body` | idem | `Withdrawal access for your account has been reactivated. You can submit a withdrawal again.` |

`application/language/indonesian/app_lang.php` (key & posisi **identik 1:1**):

| Key | Nilai ID |
|---|---|
| `wallet_wd_locked_btn` | `Penarikan Ditangguhkan` |
| `wd_err_locked` | `Penarikan Anda ditangguhkan. Silakan hubungi layanan pelanggan.` |
| `notif_wd_locked_title` | `Penarikan Ditangguhkan` |
| `notif_wd_locked_body` | `Akses penarikan akun Anda ditangguhkan sementara oleh admin. Silakan hubungi layanan pelanggan.` |
| `notif_wd_unlocked_title` | `Penarikan Diaktifkan Kembali` |
| `notif_wd_unlocked_body` | `Akses penarikan akun Anda telah diaktifkan kembali. Anda dapat mengajukan penarikan lagi.` |

Aturan yang dipatuhi:
- **Nol string literal** di view member (semua via `lang()`);
- **Nol uang/angka** di kamus (L6) — `wd_err_locked` tidak memuat nominal;
- **Nol** key JS `window.SYNAPSE_I18N` baru (tidak ada teks dinamis JS baru di
  permukaan member);
- Copy admin (modal, flash, pesan JSON) **hardcoded Indonesia** (L1) — tidak
  pernah melewati `i18n_apply()`/kamus;
- Notifikasi memakai kontrak existing `title_key` + `params` → dirender idiom
  aktif saat dibaca (plan/103), **`params` kosong** karena tidak ada nominal.

Wajib lulus kedua gate (exit 0): `scripts/audit_i18n_parity.php` (**LULUS**),
`scripts/audit_i18n_hardcoded.php` (**0 temuan**) — bersihkan
`application/logs/*.php` lebih dulu (gotcha audit hardcoded).

---

## 6. Sinkronisasi Dokumen

- `docs/2_ERD.md` §2 Tabel `users` — tambah 2 butir setelah `checkin_last_date`
  (`:250-251`): `is_wd_locked` & `wd_lock_reason` + makna + invariant.
- `docs/2_ERD.md` §7 — subseksi baru
  **"### Invariant arsitektural tambahan (plan/117 — Per-User Withdrawal Lock)"**
  berisi **W1–W5** (§0 di dokumen ini).
- `docs/3_ROADMAP.md` — entri milestone
  **"### Kunci Penarikan Per-User (Withdrawal Lock) ✅ COMPLETED (plan/117)"**
  di bawah *Completed Phases* (setelah blok plan/114–116), mengikuti gaya
  ringkas milestone lain: DDL, gate `Wallet_model::create_withdrawal`,
  `Admin::toggle_wd_lock`, `users.php`/`user_detail.php`, 6 key i18n.
- `docs/1_PRD.md` — **hanya bila** owner ingin aturan bisnis "kunci penarikan
  per-user" masuk spec produk; default: **tidak diubah** di plan ini (dokumentasi
  teknis = ERD + ROADMAP + plan/117).

---

## 7. Eksekusi (E1 … E16)

| # | Aksi | File | Gerbang |
|---|---|---|---|
| **E1** | Simpan `plan/117_USER_WD_LOCK_PLAN.md` (dokumen ini) | `plan/` | — |
| **E2** | Tambah 2 kolom ke `CREATE TABLE users` (`database.sql:32`) + blok `-- Plan 117 — MIGRASI LIVE` di akhir file | `database.sql` | review DDL (tanpa `AFTER` di `CREATE TABLE`) |
| **E3** | Buat `scripts/migrate_117_user_wd_lock.php` (6 fase, loader `$active_group→default→first`, exit 0/1/2) | `scripts/` | `php -l` |
| **E4** | `Wallet_model`: `get_wd_lock_state()`, `is_withdrawal_locked()`, gate 1a di `create_withdrawal()`, renumber komentar, docblock `code` | `application/models/Wallet_model.php` | `php -l` |
| **E5** | `Admin_model`: `set_wd_lock()`; `get_users()` += `u.is_wd_locked, u.wd_lock_reason` | `application/models/Admin_model.php` | `php -l` |
| **E6** | `Wallet` controller: `WD_ERR_KEYS['wd_locked']`, Gatekeeper 0 di `withdraw()` + `process_withdraw()`, `wd_locked` di `index()` | `application/controllers/Wallet.php` | `php -l` |
| **E7** | `Admin::toggle_wd_lock($id)` (POST-only 405, validasi 422, TX atomik + audit, notifikasi post-commit, envelope) | `application/controllers/Admin.php` | `php -l`; pastikan tidak butuh route baru |
| **E8** | Buat partial `views/admin/templates/wd_lock_modal.php` (modal + `openWdLockModal`/`submitWdLock`/`unlockWdLock`) | `application/views/admin/templates/` | `php -l` |
| **E9** | `views/admin/users.php`: badge `WD LOCK` + tombol `Kunci WD`/`Buka WD Lock` + include partial; `colspan` tetap 9 | idem | — |
| **E10** | `views/admin/user_detail.php`: badge hero + kartu **Penarikan (Withdrawal Lock)** (status, alasan, catatan D1) + include partial | idem | — |
| **E11** | 6 key kamus EN/ID di posisi alfabetis | `application/language/{english,indonesian}/app_lang.php` | `php -l`; `audit_i18n_parity` **LULUS**; `audit_i18n_hardcoded` **0 temuan** |
| **E12** | `views/wallet/index.php`: cabang `$wd_locked` paling atas + banner `wd_err_locked` | idem | — |
| **E13** | `views/wallet/withdraw.php`: state defensif (tombol `disabled` + notice + flag JS) | idem | — |
| **E14** | Sinkron dokumen: `docs/2_ERD.md` (kolom + W1–W5), `docs/3_ROADMAP.md` (milestone) | `docs/` | konsistensi istilah |
| **E15** | **Verifikasi penuh** (matriks §8) di DB sekali-pakai | — | semua gate hijau |
| **E16** | Tulis `plan/117_USER_WD_LOCK_SUMMARY.md` (bukti & perintah yang dijalankan); opsional: entri Notes di `AGENTS.md`; bersihkan scratch `/tmp` + drop DB uji; review diff akhir | `plan/`, `AGENTS.md` (opsional) | diff hanya berisi perubahan yang dimaksud |

**Urutan rilis wajib:** E3 (`--apply` di DB aktif) **sebelum** deploy kode
E4–E13 (kolom baru dibaca `get_users()` dan gate Wallet).

---

## 8. Matriks Verifikasi

> Prinsip: **jangan** memakai `db_webtable` sebagai DB uji (data yang tidak boleh
> dimutasi). Bangun DB sekali-pakai dari `database.sql` lalu `database_seed.sql`
> (error 1060/1061 dari blok legacy-ALTER seed = **normal & ditoleransi**),
> jalankan app dengan override `DB_HOSTNAME/DB_DATABASE/DB_USERNAME/DB_PASSWORD/
> APP_BASE_URL`, lalu **drop** DB setelah selesai.

**V1 — Lint (roadmap rule 5):** `php -l` untuk **setiap** file PHP
baru/termodifikasi: `Wallet_model.php`, `Admin_model.php`, `Wallet.php`,
`Admin.php`, `migrate_117_*.php`, kedua `app_lang.php`, `wd_lock_modal.php`,
`users.php`, `user_detail.php`, `wallet/index.php`, `wallet/withdraw.php`.

**V2 — Gate i18n (keduanya exit 0):**

```
php scripts/audit_i18n_parity.php      # harapan: LULUS, 639 ≡ 639
php scripts/audit_i18n_hardcoded.php   # harapan: 0 temuan
```

Sanity: `grep -c '^\$lang\[' application/language/{english,indonesian}/app_lang.php`
→ `639` & `639`.

**V3 — Migrasi (idempotensi wajib dibuktikan):**

```
php scripts/migrate_117_user_wd_lock.php --dry-run   # rencana, 0 tulis
php scripts/migrate_117_user_wd_lock.php --apply     # 2 ADD COLUMN + normalize
php scripts/migrate_117_user_wd_lock.php --apply     # RE-RUN: no-op ("sudah ada")
php scripts/migrate_117_user_wd_lock.php --verify    # read-only, exit 0
```

Plus `SHOW COLUMNS FROM users LIKE 'is_wd_locked'|'wd_lock_reason'` →
`tinyint(1) NO 0` / `varchar(255) YES NULL`.

**V4 — Member terkunci (positif):**

1. Admin (via UI) mengunci user uji dengan alasan `Uji plan/117`.
2. `GET /wallet` → 200; tombol penarikan **disabled**, ikon `fa-lock`, label
   `Penarikan Ditangguhkan`; banner menampilkan `wd_err_locked`
   (**`grep` pada HTML: alasan admin `Uji plan/117` TIDAK muncul**).
3. `GET /wallet/withdraw` → **302 → `/wallet`** + flashdata `wd_err_locked`.
4. `POST /wallet/process_withdraw` (amount valid, token CSRF valid) → **302 →
   `/wallet`**; bukti: `SELECT COUNT(*) FROM withdrawals WHERE user_id = ?`
   **tidak berubah** dan **tidak ada baris debit baru** di `wallet_ledger`
   (`WHERE user_id = ? AND type = 'debit'`) — assert sebelum/sesudah.
5. Notifikasi: 1 baris `user_notifications` `title_key='notif_wd_locked'`; dibaca
   di `/notification` → teks idiom aktif (`site_lang`) benar.

**V5 — Unlock:**

6. Admin membuka kunci → `GET /wallet` kembali normal (`<a>` ke
   `wallet/withdraw`); `SELECT is_wd_locked, wd_lock_reason FROM users WHERE id = ?`
   → `0, NULL`; notifikasi `notif_wd_unlocked`.
7. `POST /wallet/process_withdraw` valid → **302 sukses**, baris `withdrawals`
   +1, debit ledger +1 (regresi nol pada jalur normal).

**V6 — Admin & envelope:**

8. `POST /admin/toggle_wd_lock/{id}` `lock=1&reason=…` → **200** JSON
   `{success:true, data.is_wd_locked:true}` + legacy root; baris
   `system_audit_logs` action `admin_toggle_wd_lock` dengan
   `details.before/after/reason`; muncul di filter dropdown `/admin/audit`.
9. `lock=1` **tanpa** `reason` → **422** `{success:false, code:'reason_required'}`,
   **tidak ada** perubahan state & **tidak ada** baris audit baru.
10. `lock=1` dengan `reason` 256+ karakter → **422** `reason_too_long`.
11. `lock=<kosong>`/`foo` → **422** `invalid_request`.
12. **GET** `/admin/toggle_wd_lock/{id}` → **405** JSON `method_not_allowed`.
13. **POST tanpa token CSRF** → **403** (CI3), bukan HTML/200.
14. Kirim ulang `lock=1` saat sudah terkunci → **200** `changed:false`,
    **tanpa** baris audit & **tanpa** notifikasi kedua (idempoten, A1).
15. User tidak ada (`/admin/toggle_wd_lock/999999`) → **404** `user_not_found`.
16. Halaman `/admin/users` & `/admin/user_detail/{id}` → **200**; badge & alasan
    tampil; dual-auth (`user_id` vs `admin_id`) tetap terpisah.

**V7 — Privasi (gate otomatis, invarian W3):**

```
grep -rn "wd_lock_reason" application/views application/controllers/Wallet.php
# harapan: 0 pembacaan kolom alasan di permukaan member — seluruh pembacaan
# ada di Admin*/Wallet_model (komentar boleh ada).
```

**V8 — Regresi batas (wajib):** `GET /wallet`, `/wallet/withdraw`,
`POST /wallet/process_withdraw` untuk user **tidak terkunci** harus berperilaku
**identik** dengan sebelum plan/117 (status 200/302 yang sama) — gate baru tidak
boleh memakan kuota rate-limit atau mengubah pesan lama.

**V9 — Bersih-bersih:** drop DB uji, hapus scratch `/tmp`, kosongkan
`application/logs/*`, `git status` bersih dari file tak dimaksud.

---

## 9. Risiko & Catatan

1. **Deploy order (TINGGI).** Kode plan/117 membaca kolom baru. Deploy kode
   sebelum migrasi → `get_users()` error (`Unknown column`) sehingga
   `/admin/users` rusak dan `is_withdrawal_locked()` melempar notice. Mitigasi:
   urutan rilis E3 → E4–E13, dan `--verify` sebagai gate rilis.
2. **Divergensi kanonik `users.role`/`users.username` (pre-existing).** Tidak ada
   di `database.sql`, hanya di blok legacy-ALTER `database_seed.sql:14,16`.
   `get_users()` memilih `u.role`; plan/117 **tidak** menyentuh ini — jangan
   "merapikan" di tengah perubahan agar diff tetap fokus.
3. **Multi-tab / race admin.** Intent eksplisit (`lock=1|0`) + TX + audit
   before→after membuat klik ganda tidak menghasilkan audit ganda; state akhir
   deterministik.
4. **Privasi.** Alasan tidak pernah dirender ke member (W3/V7) tetapi **tetap
   ada di `system_audit_logs.details`** (internal, memang tujuannya) dan di
   `title` badge panel admin. Copy admin menyatakan sifat internal tersebut.
5. **`affected_rows()` = 0 ≠ gagal.** MySQL melaporkan 0 bila nilai tidak
   berubah; karena itu no-op diperlakukan **sukses idempoten** (V6 #14), bukan
   error. `trans_status()` tetap jadi penentu kegagalan TX.
6. **Nol dampak finansial.** Tidak ada baris `wallet_ledger` baru, tidak ada
   refund, tidak ada pembatalan `withdrawals` (D1/D2) — diuji eksplisit di V4 #4.
7. **Tidak ada penulisan `bank_accounts`/`user_rentals`/`gpu_products`.**
   Choke-point lain tidak tersentuh.
8. **`templates/csrf_meta` wajib ada** di kedua halaman admin (sudah, lewat
   `views/admin/templates/header.php:23`) agar `csrfFetch()` bekerja — jangan
   menambah AJAX di halaman admin yang tidak memuat meta CSRF.

---

## 10. Definition of Done

- [ ] E1–E16 selesai; diff akhir hanya memuat perubahan yang dimaksud.
- [ ] `php -l` bersih untuk **semua** file PHP baru/termodifikasi (V1).
- [ ] `scripts/audit_i18n_parity.php` **LULUS** & `scripts/audit_i18n_hardcoded.php` **0 temuan**; kamus **639 ≡ 639** (V2).
- [ ] Migrasi terbukti **idempoten** (apply → re-apply no-op → verify exit 0) di DB sekali-pakai (V3).
- [ ] Matriks HTTP V4–V6 lulus (302/200/405/422/404 + envelope JSON, nol HTML pada jalur AJAX).
- [ ] Gate privasi V7 bersih; alasan admin tidak pernah muncul di HTML member.
- [ ] Regresi V8: jalur penarikan user normal identik dengan sebelum perubahan.
- [ ] `database.sql` kanonik + blok migrasi live diperbarui; `docs/2_ERD.md` (+ W1–W5) & `docs/3_ROADMAP.md` tersinkron.
- [ ] `plan/117_USER_WD_LOCK_SUMMARY.md` mencatat perubahan + bukti (perintah & hasil yang **benar-benar** dijalankan; tidak ada klaim test suite).
- [ ] Nol perubahan di `system/`, nol route baru, nol tabel baru (inventory tetap 14), nol `wallet_ledger` baru.
