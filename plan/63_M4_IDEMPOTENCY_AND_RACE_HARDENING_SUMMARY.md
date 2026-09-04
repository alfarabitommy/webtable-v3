# 63 — M4: IDEMPOTENCY, POST-GATES & DOUBLE-SUBMIT GUARDS — IMPLEMENTATION SUMMARY

**Status:** ✅ SELESAI — semua scope blueprint `plan/62_M4_IDEMPOTENCY_AND_RACE_HARDENING_PLAN.md` dieksekusi.
**Tanggal:** sesi implementasi M4. **Basis:** `main` + working tree plan 38–61.

---

## 1. RINGKASAN EKSEKUTIF

Finding M4 (audit `plan/37` §3) ditutup pada tiga lapis:

| Lapis | Masalah | Solusi |
|---|---|---|
| **H1 — Server POST-gates** | `Admin::approve_deposit/approve_withdrawal/decline_withdrawal` dapat dipicu via **GET** (CSRF CI3 hanya melindungi POST-family) → vektor CSRF-by-GET (S1) | Gate `if ($this->input->method() !== 'post') { show_404(); return; }` pada trio approve/decline + upgrade 9 mutator admin lain dari pola `redirect` ke `show_404()` (fail-closed, konsisten `Rentals::claim`) |
| **H2 — Idempotent deposit invoice** | `create_deposit()`: invoice `INV-YmdHis-userId` deterministik per detik + hasil insert tak dicek (P2) → duplicate-key / invoice ganda saat double-submit (S2) | Invoice kini `INV-YmdHis-userId-XXXXXX` (sufiks acak 6-hex), hasil insert diverifikasi, retry ≤3x hanya pada duplicate key (1062/23000), return `{success, invoice_number}`; `Wallet::topup()` memetakan hasil → flashdata |
| **H3 — Frontend guard** | Hampir semua form aksi tanpa guard double-click; dengan `csrf_regenerate=true`, POST kedua membawa token basi → layar error CSRF (S3–S5) | Helper Vanilla JS terpusat `guardFormSubmit()` + delegation `form[data-guard-submit]` di `templates/csrf_meta.php`; diterapkan ke 7 form |

**Catatan scope (N1):** `views/admin/withdrawals.php` memang tidak pernah ada — aksi admin terpusat di `admin/dashboard.php`; guard dipasang di sana (3 form).

---

## 2. PERUBAHAN FILE

### `application/controllers/Admin.php` (H1)
- **POST gate + `show_404()`** (komentar `M4 (plan/62 S1)`): `approve_deposit()`, `approve_withdrawal()`, `decline_withdrawal()`.
- **Upgrade `redirect(...)` → `show_404()`** pada gate POST yang sudah ada (komentar `M4 (plan/62 H1)`): `update_user()`, `toggle_ban()`, `inject_balance()`, `inject_rental()`, `cancel_rental()`, `expire_expired_rentals()`, `adjust_time()`, `create_user()`, `reset_password()`.
- Tidak diubah: `settings()` & `financial_settings()` (halaman GET-render + simpan di cabang POST — gate internal sudah benar); `toggle_registration()` (sudah 405 JSON untuk AJAX); endpoint read-only GET (`history`, `users`, `analytics`, `audit`, `chart_data`, `export_csv`, `user_xray`, `user_detail`).

### `application/models/Wallet_model.php` (H2)
- `create_deposit($user_id, $amount)` ditulis ulang:
  - `$invoice = 'INV-' . date('YmdHis') . '-' . (int)$user_id . '-' . strtoupper(bin2hex(random_bytes(3)))` → 6-hex suffix acak (tidak bisa ditebak/dibentrokkan per detik; panjang < VARCHAR(50)).
  - Insert diverifikasi; `$this->db->error()` — hanya code `1062`/`23000` (duplicate key `uk_invoice_number`) yang di-retry (sufiks baru, ≤3x); error lain di-log & berhenti.
  - Return kontrak baru: `['success' => bool, 'invoice_number' => string|null]`.

### `application/controllers/Wallet.php` (H2)
- `topup()`: memanggil hasil terstruktur `create_deposit()`; sukses → flash `Invoice {invoice_number} berhasil dibuat…`; gagal → flash error `Gagal membuat invoice…` (tidak lagi flash sukses buta).

### `application/views/templates/csrf_meta.php` (H3)
- Helper `window.guardFormSubmit(form)` + delegation `document.addEventListener('submit', …)` untuk `form[data-guard-submit]` (satu sumber, ter-load di header admin & user bersama `csrfFetch`):
  - Submit pertama **tidak** di-`preventDefault` → POST native + redirect + flashdata utuh (pola `claim-form`, plan/46).
  - Set `data-submitting="1"`, disable semua tombol submit, tombol pertama → `<i class="fas fa-spinner fa-spin mr-1"></i> Memproses...`.
  - Submit kedua+ → `preventDefault` + `stopPropagation`.
  - Dipasang bubble-phase → inline `onsubmit="return confirm(...)"` (dashboard admin) berjalan lebih dulu; jika user **batal**, event berhenti sebelum form ditandai.

### View (H3) — atribut `data-guard-submit="1"`
| File | Form |
|---|---|
| `application/views/admin/dashboard.php` | approve deposit, approve WD, decline WD (3 form; `confirm()` tetap dipertahankan) |
| `application/views/wallet/withdraw.php` | `#withdrawForm` (`form_open` string → array, `id` dipertahankan) |
| `application/views/wallet/index.php` | `#topupForm` + `simulate_payment/{inv}` + `simulate_wd_approve/{wd}` (2 form simulator dev-only) |
| `application/views/marketplace/index.php` | `#form-checkout` |

Tidak diubah skema DB — unique key backstop sudah ada (`uk_wd_number`, `uk_invoice_number`, `wallet_ledger(user_id, transaction_id, type)`).

---

## 3. VERIFIKASI

### 3.1 Lint — ✅ LULUS
```
php -l application/controllers/Admin.php          → No syntax errors detected
php -l application/controllers/Wallet.php         → No syntax errors detected
php -l application/models/Wallet_model.php        → No syntax errors detected
php -l application/views/templates/csrf_meta.php  → No syntax errors detected
php -l application/views/admin/dashboard.php      → No syntax errors detected
php -l application/views/wallet/withdraw.php      → No syntax errors detected
php -l application/views/wallet/index.php         → No syntax errors detected
php -l application/views/marketplace/index.php    → No syntax errors detected
```

### 3.2 Gate struktural — ✅ 12/12 mutator (skrip ad-hoc `/tmp/m4_gate_check.php`)
Untuk tiap metode (`approve_deposit`, `approve_withdrawal`, `decline_withdrawal`, `update_user`, `toggle_ban`, `inject_balance`, `inject_rental`, `cancel_rental`, `expire_expired_rentals`, `adjust_time`, `create_user`, `reset_password`): gate `method() !== 'post'` + `show_404()` adalah **statement pertama** sebelum efek samping apa pun → **`ALL-GATES-OK`**. Implikasi: `GET /admin/approve_withdrawal/{id}` (dengan sesi admin sah) → **HTTP 404**.

### 3.3 JS helper — ✅ `node --check` lulus (blok `<script>` csrf_meta.php terekstrak & tervalidasi).

### 3.4 Catatan keterbatasan (runtime end-to-end)
Uji runtime `curl` (`GET /admin/approve_withdrawal/{id}` → 404; dua POST paralel approve+decline → 1 sukses + 1 "sudah diproses"; topup detik-sama → tanpa exception DB) **tidak dapat dieksekusi di lingkungan ini**: tidak ada server MySQL yang berjalan (mysqli: `No such file or directory`), tidak ada biner `mysqld`/`mariadbd`/`docker`, dan `db_debug = (ENVIRONMENT !== 'production')` membuat boot CI gagal tanpa DB. Verifikasi runtime harus dijalankan di lingkungan dengan DB `db_webtable` tersedia:

```bash
# 1. Jalankan server + login admin (cloaked /control-panel) simpan cookie
php -S localhost:8080   # pretty-URL: pakai konfigurasi rewrite / host synapse.test
# 2. GET mutasi → 404
curl -i -b admin.cookies http://synapse.test/admin/approve_withdrawal/1
# harap: HTTP/1.1 404 (bukan 302/200; state tidak berubah)
# 3. Concurrency: dua POST paralel pada WD pending yang sama
curl -i -b admin.cookies -X POST -d "csrf=..." http://synapse.test/admin/approve_withdrawal/1 &
curl -i -b admin.cookies -X POST -d "csrf=..." http://synapse.test/admin/approve_withdrawal/1 &
# harap: satu flash 'berhasil disetujui', satu 'sudah diproses'; satu baris audit; tanpa kredit ganda
```

Otoritas anti-race server (CAS `WHERE status='pending'` + `affected_rows()===1`) sudah diverifikasi hadir di `Admin_model` (plan/54, tidak diubah oleh plan ini).

---

## 4. ASUMSI / KEPUTUSAN (dari blueprint, dipertahankan)
- **A1:** multi-pending deposit invoice dipertahankan — idempotensi dicapai lewat invoice unik + guard, bukan single-pending.
- **A2:** guard frontend = Vanilla JS via delegation `data-guard-submit` (bukan Alpine — tidak ada di stack).
- **A3:** notifikasi user pasca-commit (fire-and-forget) dipertahankan — di luar atom finansial.
- **Opsional ditunda:** rate limit `topup:{uid}` (parity `withdraw`) — tidak wajib untuk M4; bisa ditambah kemudian di `Wallet::topup`.

---

*End of summary — plan/63. Semua perubahan hanya pada controller/model/view; tanpa migrasi skema DB.*
