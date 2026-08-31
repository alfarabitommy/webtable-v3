# Phase 10A — Audit Logging Engine & Audit Viewer

**Project:** Synapse (webtable) · **Baseline:** `main` (HEAD `e98ccca`) · **Branch kerja:** `fase-10a-audit-logging`
**Mode:** APPROVED — blueprint dieksekusi oleh AI agent setelah persetujuan user.
**Referensi:** `docs/3_ROADMAP.md` (Phase 10A), `docs/1_PRD.md` §7.D (`admin_create_user`, `admin_reset_password`), `docs/2_ERD.md` §6 (`system_audit_logs`), `docs/5_AUDIT_REPORT.md` (finding #1: dead sidebar link `admin/audit`; prerequisites), `plan/6_PHASE_D_PLAN.md` (format & style guard).

---

## Ringkasan Perubahan

| # | Perubahan | File |
|---|-----------|------|
| 1 | **Model baru `Audit_model`** — helper `log_admin_action()` (plain INSERT, transaction-agnostic) + query methods viewer | `application/models/Audit_model.php` (new) |
| 2 | **Instrumentasi 13 method admin** — audit write di dalam envelope ACID yang sama dengan aksinya (no ghost records) | `application/controllers/Admin.php` (edit) |
| 3 | **Audit params di model-owned TX** — `inject_balance`, `inject_rental`, `update_settings` menulis audit di dalam `trans_start()` mereka | `application/models/Admin_model.php` (edit) |
| 4 | **Audit viewer** — `Admin::audit()` (fix 404 sidebar) + view dark Bloomberg Terminal | `application/controllers/Admin.php`, `application/views/admin/audit.php` (new) |
| 5 | **Blueprint ini** | `plan/9_PHASE_10A_PLAN.md` (new) |

**Tanpa perubahan:** `routes.php` (segment routing `admin/audit` → `Admin::audit()` sudah otomatis, sama seperti `admin/settings`), `database.sql` (tabel `system_audit_logs` sudah ada, lines 261–279), notification flow (tetap fire-and-forget setelah TX commit — perilaku eksisting, di luar scope).

---

## 1. Audit Logger Architecture (`Audit_model`)

### 1.1 Keputusan arsitektur: model khusus, bukan muatan `Admin_model`

`Admin_model` sudah 583 baris (user mgmt + treasury + analytics + CSV). `Notification_model` adalah preseden yang sudah ada untuk model fitur-tertentu. `Audit_model` baru → single responsibility, mudah di-test, dan bisa di-load dari controller maupun model lain tanpa cycle dependency.

### 1.2 Helper inti

```php
public function log_admin_action($admin_id, $user_id, $action, $details = null, $ip_address = '') {
    return $this->db->insert('system_audit_logs', [
        'admin_id'   => $admin_id ?: null,          // INT UNSIGNED NULL, FK admins.id ON DELETE SET NULL
        'user_id'    => $user_id ?: null,           // BIGINT UNSIGNED NULL, FK users.id ON DELETE SET NULL
        'action'     => $action,                    // VARCHAR(100)
        'details'    => is_array($details)
            ? json_encode($details, JSON_UNESCAPED_UNICODE)
            : $details,                             // TEXT NULL
        'ip_address' => $ip_address,                // VARCHAR(45)
    ]);
}
```

**Kenapa helper ini TIDAK memanggil `trans_begin()` sendiri:**

Dua gaya envelope TX hidup berdampingan di codebase:

- `trans_start()` / `trans_complete()` / `trans_status()` — dipakai `Admin` controller (approve_deposit, approve_withdrawal, decline_withdrawal) dan `Admin_model` (inject_balance, inject_rental, update_settings).
- `trans_begin()` / `trans_commit()` / `trans_rollback()` + try/catch — dipakai `Ledger_model::insert_transaction()`.

Jika `log_admin_action()` membuka TX sendiri, pemanggilan dari dalam envelope `trans_start()` akan membuat **nested transaction** yang di CI3 berperilaku tidak deterministik (depth counter + strict mode) dan berisiko commit parsial. Solusi bersih: **helper melakukan INSERT polos tanpa manajemen transaksi**; atomicity diwarisi dari envelope pemanggil. Kontrak: *semua* call site wajib berada di dalam TX yang sama dengan aksinya — dipenuhi oleh §2.

### 1.3 Query viewer

```php
public function get_audit_logs($action = '', $from = '', $to = '', $limit = 50, $offset = 0)
public function count_audit_logs($action = '', $from = '', $to = '')
public function get_action_options()   // DISTINCT action + COUNT, untuk dropdown filter
```

`get_audit_logs`: `SELECT a.*, adm.username AS admin_username, u.phone AS user_phone FROM system_audit_logs a LEFT JOIN admins adm ON adm.id = a.admin_id LEFT JOIN users u ON u.id = a.user_id` + filter opsional (`a.action = ?`; `DATE(a.created_at) BETWEEN ? AND ?`) + `ORDER BY a.created_at DESC, a.id DESC` + limit/offset. Semua bound params (konvensi AGENTS.md: SQL hanya di model, `$this->db->query("... ?", [$v])`).

### 1.4 Prasyarat schema

Tabel sudah ada di `database.sql` (Phase 10 baseline). Pre-flight:

```sql
SHOW TABLES LIKE 'system_audit_logs';
```

Jika tidak ada di DB live (CI3 tanpa migration tooling — pola sama dengan `must_change_password` di 7E3): apply DDL dari `database.sql` lines 264–279 secara manual.

---

## 2. Admin Operations Instrumentation

### 2.1 Kosakata action (konsisten dengan PRD & roadmap)

Lima nama sudah ditetapkan spesifikasi; sisanya mengikuti gaya yang sama (snake_case, prefiks `admin_`):

| Action string | Method | Dokumen sumber |
|---|---|---|
| `approve_deposit` | `Admin::approve_deposit()` | user brief |
| `approve_withdrawal` | `Admin::approve_withdrawal()` | user brief |
| `decline_withdrawal` | `Admin::decline_withdrawal()` | user brief |
| `admin_create_user` | `Admin::create_user()` | PRD §7.D.1 (details: `{ phone, created_by: admin_id }`) |
| `admin_reset_password` | `Admin::reset_password()` | PRD §7.D.2 (details: `{ user_id }`) |
| `admin_update_user` | `Admin::update_user()` | user mgmt profile/upline |
| `admin_toggle_ban` | `Admin::toggle_ban()` | user brief ("Status toggles") |
| `admin_inject_balance` | `Admin::inject_balance()` | UAT tool (financial!) |
| `admin_inject_rental` | `Admin::inject_rental()` | UAT tool |
| `admin_cancel_rental` | `Admin::cancel_rental()` | rental lifecycle |
| `admin_adjust_time` | `Admin::adjust_time()` | time travel (financial) |
| `admin_update_settings` | `Admin::settings()` POST | user brief ("settings adjustments") |
| `admin_toggle_registration` | `Admin::toggle_registration()` | user brief ("Status toggles") |

**Tidak di-audit (read-only):** `index`, `history`, `users`, `user_detail`, `chart_data`, `analytics`, `user_xray`, `export_csv`.

### 2.2 Envelope TX per method (bagaimana audit melekat secara atomik)

`$admin_id = (int) $this->session->userdata('admin_id')`; `$ip = $this->input->ip_address()` (CI3; sesuai `proxy_ips` config — peningkatan `X-Forwarded-For` ditunda ke 10D per audit report).

**Kelompok A — TX milik controller (approve_deposit / approve_withdrawal / decline_withdrawal):**
Insert audit **di antara** write aksi dan `trans_complete()`:
```php
$this->db->trans_start();
// ... aksi (status update + wallet_ledger) ...
$this->Audit_model->log_admin_action($admin_id, $deposit->user_id, 'approve_deposit',
    ['invoice_number' => $deposit->invoice_number, 'amount' => $deposit->amount], $ip);
$this->db->trans_complete();
```
Jika aksi gagal → `trans_status()` false → `trans_rollback()` (otomatis CI3) menghapus baris audit. Notification user tetap setelah commit (tidak berubah).

**Kelompok B — TX milik model (inject_balance / inject_rental / update_settings):**
`Admin_model::update_settings($data, $audit = null)`, `inject_balance(..., $audit = null)`, `inject_rental(..., $audit = null)` menerima param `$audit = ['admin_id'=>, 'user_id'=>, 'action'=>, 'details'=>]` dan memanggil `$this->Audit_model->log_admin_action(...)` di dalam `trans_start()`/`trans_complete()` yang sudah ada. Controller tinggal meneruskan konteks. (Menghindari double-TX: helper tetap plain INSERT.)

**Kelompok C — tanpa TX hari ini (create_user / reset_password / update_user / toggle_ban / cancel_rental / adjust_time / toggle_registration):**
Bungkus aksi + audit dalam envelope baru agar "failed operations do not leave ghost audit records":
```php
$this->db->trans_start();
$result = $this->Admin_model->create_user($data);   // aksi
$this->Audit_model->log_admin_action($admin_id, $result ?: null, 'admin_create_user',
    ['phone' => $phone, 'created_by' => $admin_id], $ip);
$this->db->trans_complete();
if (!$this->db->trans_status()) { /* flash error */ }
```
- `toggle_registration` (AJAX JSON): TX yang sama, lalu `set_output(json_encode(...))` seperti sekarang — status 200 hanya jika TX sukses; audit insert sebelum `set_setting`.
- `cancel_rental` / `adjust_time`: gunakan `user_id` dari row rental yang sudah di-fetch controller.
- `toggle_ban`: details `{ user_id, new_state: 'banned'|'unbanned' }` dari return `$new_state`.
- `reset_password`: `{ user_id }` persis PRD — **jangan pernah** menulis plaintext password baru ke audit.

**Guardian rule:** setiap call site `log_admin_action()` harus berada di dalam blok `trans_start()` … `trans_complete()` / `trans_begin()` … `trans_commit()|trans_rollback()` yang sama dengan aksinya. Direview saat eksekusi via grep `log_admin_action` + inspeksi konteks.

---

## 3. Audit Viewer (`/admin/audit`)

### 3.1 Controller — `Admin::audit()`

```php
public function audit()
{
    $this->load->model('Admin_model');
    $this->load->model('Audit_model');

    $action = trim($this->input->get('action', TRUE));   // whitelist via in_array terhadap get_action_options()
    $from   = $this->input->get('from', TRUE);           // YYYY-MM-DD
    $to     = $this->input->get('to', TRUE);             // YYYY-MM-DD
    $per_page = 50;
    $offset = max(0, intval($this->input->get('per_page', TRUE) ?? 0));

    $total = $this->Audit_model->count_audit_logs($action, $from, $to);
    $logs  = $this->Audit_model->get_audit_logs($action, $from, $to, $per_page, $offset);

    // CI pagination — pola sama dengan users(): base_url site_url('admin/audit') + query string per_page
    // (action/from/to dipertahankan di link pagination)
    ...
    $this->load->view('admin/templates/header', $data);
    $this->load->view('admin/templates/sidebar', $data);
    $this->load->view('admin/templates/topbar', $data);
    $this->load->view('admin/audit', $data);
    $this->load->view('admin/templates/footer');
}
```
Guard `admin_id` sudah ada di constructor `Admin` (baris 14–16) — halaman aman tanpa perubahan. Link sidebar `admin/audit` (sidebar.php:30) langsung hidup; **tidak perlu route baru**.

### 3.2 View — `application/views/admin/audit.php` (dark Bloomberg Terminal)

Mengikuti estetika `dashboard.php` (Phase 9A) — dark panel di atas body `bg-slate-50`:

- **Panel utama:** `bg-slate-950 rounded-xl border border-slate-800 p-5 shadow-lg`.
- **Terminal header:** `"AUDIT TRAIL // SYSTEM_LOG"` — `text-emerald-400 font-mono text-sm font-bold uppercase tracking-widest` + timestamp `date('Y-m-d H:i:s')`.
- **Filter bar:** `<select>` action (dari `get_action_options()`, opsi `-- SEMUA ACTION --` + tiap action dgn count), dua `<input type="date">` (from/to), tombol `FILTER` (submit GET ke `admin/audit`) dan `RESET` (link ke `admin/audit`). Styling `bg-slate-900 border-slate-700 text-slate-300 font-mono`.
- **Tabel** (tabular, `font-mono text-sm`, `w-full`, header `text-[10px] text-slate-400 uppercase tracking-wider`):
  | Kolom | Isi |
  |---|---|
  | `#ID` | `a.id`, `text-slate-500` |
  | `WAKTU` | `created_at` (`d M Y H:i:s`) |
  | `ADMIN` | `admin_username` (fallback `—` jika admin terhapus, karena FK SET NULL) |
  | `ACTION` | badge berwarna per jenis: `approve*` → emerald, `decline*` → red, `create/reset` → amber, `toggle*` → violet, lainnya slate; `font-mono text-xs px-2 py-0.5 rounded` |
  | `TARGET` | `user_phone` (fallback `—`); klik → link `admin/user_detail/{user_id}` bila user masih ada |
  | `IP` | `ip_address`, `text-slate-500` |
  | `DETAILS` | JSON `details` di-`htmlspecialchars` (anti-XSS), dipotong `max-w-xs truncate` + `title` tooltip penuh |
- **Row hover:** `hover:bg-slate-800/50`. **Striped:** `even:bg-slate-900/50`.
- **Empty state:** `∅ Tidak ada catatan audit.` (`text-slate-500`, `text-center`, `py-10`) — konsisten gaya dashboard.
- **Pagination:** links CI pagination di bawah tabel (pola `users.php`).

Semua output user/admin dari DB dilewatkan `htmlspecialchars()`/CI `set_value()` — detail JSON **tidak pernah** di-echo mentah (kontribusi ke 10D hardening).

---

## 4. Verification & Testing Protocol

### 4.1 Lint (Roadmap Rule #5 — semua file PHP baru/diubah)

```bash
php -l application/controllers/Admin.php
php -l application/models/Admin_model.php
php -l application/models/Audit_model.php
```
Wajib: `No syntax errors detected in ...` untuk ketiganya.

### 4.2 Pre-flight schema

```bash
mysql -u <user> -p db_webtable -e "SHOW TABLES LIKE 'system_audit_logs';"
```
→ tabel ada; jika tidak, apply DDL `database.sql` lines 264–279.

### 4.3 Curl smoke (Roadmap Rule #3)

Server: `php -S localhost:8080`; login admin via `/control-panel`, simpan cookie `/tmp/admin_cookies.txt`.

1. **Unauthenticated** `GET /admin/audit` → `302` ke `/control-panel` (constructor guard).
2. **Authenticated** `GET /admin/audit` → `200` (sidebar 404 resolved).
3. **Filter** `GET /admin/audit?action=approve_deposit` dan `?from=2024-01-01&to=2024-12-31` → `200`.
4. **AJAX toggle** `POST /admin/toggle_registration` → `200` + valid JSON `{success:true,...}`.

### 4.4 End-to-end: aksi admin → baris audit → tampil di viewer

Untuk tiap kelompok aksi (minimal satu wakil: approve_deposit, decline_withdrawal, create_user, reset_password, inject_balance, toggle_registration):

```bash
# jalankan aksi via curl (cookie admin), lalu:
mysql -u <user> -p db_webtable -e "
SELECT id, admin_id, user_id, action, details, ip_address FROM system_audit_logs
WHERE action = '<action>' ORDER BY id DESC LIMIT 1;"
curl -s -b /tmp/admin_cookies.txt "http://localhost:8080/admin/audit" | grep -c "<action>"
```
Kriteria lolos:
- 1 baris audit baru dengan `admin_id`, `user_id` benar, `details` JSON valid, `ip_address` terisi.
- Action string muncul di halaman viewer (grep count ≥ 1).

### 4.5 Anti-ghost / rollback test

- Ulangi `approve_deposit` pada deposit yang sudah `success` (guard `status !== 'pending'` → early return sebelum TX) → **tidak ada** baris audit baru (`COUNT` sebelum/sesudah sama).
- Simulasikan kegagalan di tengah TX (mis. drop index/kolom sementara, atau hardcode `UPDATE` salah tabel) → `trans_status()` false → audit row tidak persist (rollback membersihkan).

### 4.6 Hygiene review

```bash
grep -n "log_admin_action" application/ | grep -v "Audit_model.php"   # setiap call site di dalam TX
git diff --stat                                                   # hanya file yang didaftarkan di Ringkasan
```

---

## 5. Files Touched (Phase 10A)

| File | Action |
|------|--------|
| `plan/9_PHASE_10A_PLAN.md` | **new** — blueprint ini |
| `application/models/Audit_model.php` | **new** — `log_admin_action`, `get_audit_logs`, `count_audit_logs`, `get_action_options` |
| `application/controllers/Admin.php` | edit — `audit()` method baru + instrumentasi 13 method (§2.2) |
| `application/models/Admin_model.php` | edit — param `$audit` di `update_settings`, `inject_balance`, `inject_rental` |
| `application/views/admin/audit.php` | **new** — viewer dark Bloomberg Terminal (§3.2) |
