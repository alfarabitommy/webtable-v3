# 62 — M4: DOUBLE-SUBMIT RACES & ACTION IDEMPOTENCY — HARDENING BLUEPRINT

**Scope:** Finding M4 dari `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` §3 — admin approve/decline idempotency, member financial form submissions (double-submit), dan frontend debounce/double-click guard.
**Mode:** PLAN-ONLY (arsitektur & inspeksi). **Tidak ada perubahan** kode aplikasi / skema DB.
**Auditor:** Lead Financial Backend Architect / CI3 Specialist / System Auditor.
**Basis:** `main` + working tree plan 38–61 (fix C1–C7, M1–M3 — belum di-commit).

> **Temuan kunci:** Sebagian besar *server-side* M4 **sudah** diperbaiki oleh plan 38–61 (`Admin_model` memakai conditional status transition + `affected_rows()` gate; mesin WD/checkout/claim ACID terkunci). Yang **tersisa** dan menjadi isi blueprint ini: (1) endpoint admin approve/decline **tanpa POST-gate** → vektor CSRF via GET; (2) pembuatan invoice topup **tidak idempoten** (P2 audit); (3) guard double-click **frontend** hampir tidak ada (kecuali form klaim ROI). Catatan scope: `application/views/admin/withdrawals.php` / view deposit **tidak ada** di tree — permukaan aksi admin = `admin/dashboard.php` (3 form) + `admin/user_detail.php` (inject).

---

## 1. STATUS AUDIT PER KOMPONEN

### 1.1 Admin financial actions — `Admin_model.php` (SUDAH DIHARDEN, plan 38/44/48/54)
| Metode | Pola yang sudah ada | Verdict |
|---|---|---|
| `approve_deposit()` | pre-check di luar TX (fast-path) → `trans_begin` → `lock_and_get_balance()` (anchor `SELECT … FOR UPDATE` users, statement pertama) → **CAS** `UPDATE deposits SET status='success' WHERE id=? AND status='pending'` → `affected_rows()!==1` ⇒ rollback → `Wallet_model::credit()` (ledger+cache atomik) → audit dalam TX → commit | ✅ idempoten, tanpa orphan ledger |
| `approve_withdrawal()` | `trans_begin` → **CAS** `UPDATE withdrawals SET status='success', processed_at=? WHERE id=? AND status='pending'` → gate `affected_rows()===1` → audit → commit (tanpa mutasi uang — dana sudah didebit saat pengajuan) | ✅ idempoten |
| `decline_withdrawal()` | anchor lock → **CAS** `status='failed'` + gate → refund `credit()` (tx_id = `wd_number`) → audit → commit | ✅ idempoten, anti double-refund |

**Backstop skema (sudah ada di `database.sql`):** `uk_wd_number`, `uk_invoice_number`, unique `wallet_ledger(user_id, transaction_id, type)` — duplikat deterministik gagal di level DB. Refund `WD-x` type=credit vs debit asli type=debit **tidak** bentrok (komposit memuat `type`).

### 1.2 Member financial submissions — `Wallet.php` / `Rentals.php`
| Alur | Status |
|---|---|
| `Wallet::process_withdraw` → `Wallet_model::create_withdrawal` | ✅ ACID: rate limit `withdraw:{uid}` 5/900s → lock anchor → aturan op/min/max/overspend **di dalam TX terkunci** → re-check `has_pending_withdrawal` + daily-limit **setelah lock wait** → insert + debit helper → commit. Double-submit kedua di-serialisasi lock lalu ditolak (`pending_exists`). |
| `Rentals::checkout` → `checkout_rental` | ✅ ACID: lock anchor + reject overspend dalam TX + debit + insert kontrak, satu commit. |
| `Rentals::claim` → `claim_roi` | ✅ `SELECT … FOR UPDATE` baris rental + guard lifecycle (status/expired/days_processed) + `UPDATE days_processed = days_processed + ?` gate + kredit ID deterministik `ROI-{id}-D{seq}`; POST-only (non-POST → 404). |
| `Wallet::topup` → `Wallet_model::create_deposit` | ⚠️ **GAP (S2)** — lihat §2. |
| Simulator (`simulate_payment`, `simulate_wd_approve`) | ✅ production-gated (404), POST-only, ownership check, CAS + gate. Catatan: `approve_withdrawal_simulator` pakai `trans_start/trans_complete` — fungsional OK, gaya beda dari `trans_begin/try/catch` (opsional diselaraskan). |

### 1.3 Frontend (lihat detail §2 dan strategi §4)
- `rentals/index.php` form klaim: ✅ **satu-satunya** yang punya guard `data-submitting="1"` + spinner "Memproses…" (pola rujukan, plan/46).
- `admin/dashboard.php`: 3 form aksi (approve deposit, approve/decline WD) — hanya `confirm()`.
- `wallet/withdraw.php`: tombol di-disable hanya oleh validasi input; **tidak ada** once-guard saat submit.
- `wallet/index.php`: form `topupForm` + 2 form simulator — **tidak ada** guard submit.
- `rentals/index.php` checkout → `marketplace/index.php` (`form-checkout`): tanpa guard.
- Stack frontend = **Vanilla JS** + Tailwind CDN + Font Awesome (AGENTS.md). **Alpine.js tidak dipakai** — guard diusulkan Vanilla JS murni (bukan Alpine).

---

## 2. TITIK RAWAN YANG TERSISA (SCOPE BLUEPRINT INI)

| ID | Severity | Lokasi | Temuan | Dampak |
|---|---|---|---|---|
| **S1** | 🔴 HIGH | `Admin.php` `approve_deposit()` / `approve_withdrawal()` / `decline_withdrawal()` (dan saudara mutator-uang `expire_expired_rentals`, `toggle_registration`, `inject_balance` dkk.) | **Tidak ada POST-gate.** CSRF CI3 hanya melindungi metode POST-family → GET lolos CSRF. Endpoint tetap tercapai via `GET /admin/approve_withdrawal/{id}`. | State money bisa dipicu lintas-situs (CSRF-by-GET: `<img src="…/admin/approve_withdrawal/5">`) selagi sesi admin hidup — member bisa **menyetujui WD-nya sendiri** atau memaksa decline/refund WD member lain tanpa review admin. Duplikat sudah dicegah CAS (idempoten), tapi **invokasi tak sah** = bahaya nyata. Melanggar policy POST-only 10B. |
| **S2** | 🟠 MED | `Wallet.php::topup()` + `Wallet_model::create_deposit()` | Invoice `'INV-'.date('YmdHis').'-'.$user_id` — deterministik per detik; hasil `insert()` **tidak dicek** (P2 audit). Double-submit cepat: (a) detik sama → `uk_invoice_number` violation → exception/500, UI tetap flash "sukses"; (b) detik beda → **2 invoice pending** yang sah (duplikat pengajuan, bukan duplikat kredit — kredit tetap 1x per invoice saat admin approve). Tanpa rate limit & tanpa hasil terstruktur. | Duplicate invoice / error tak terkelola; pengalaman topup ganda; tidak idempoten di sisi request. |
| **S3** | 🟠 MED (UX) | `admin/dashboard.php` | Form approve/decline tanpa guard double-click. CI3 default `csrf_regenerate=true` → POST kedua yang sedang terbang membawa token lama → **gagal CSRF** (layar error), atau bila lolos → flash "sudah diproses" yang membingungkan. | Admin melihat error setelah aksi pertama sukses; risiko salah-klik ganda. |
| **S4** | 🟡 LOW (UX) | `wallet/withdraw.php` | `wd_submit` di-disable hanya saat nominal tidak valid; **tanpa** once-guard submit (`data-submitting`). Server aman (lock + gatekeeper), tapi klik ganda → gejala CSRF-regenerate yang sama seperti S3. | Flash error ganda/CSRF setelah submit pertama sukses. |
| **S5** | 🟡 LOW (UX) | `wallet/index.php` (`topupForm`, 2 form simulator), `marketplace/index.php` (`form-checkout`) | Tanpa guard submit. | Double-click → duplicate invoice (S2) / submit berulang. |
| **N1** | ℹ️ | Audit §3-M4 menyebut `views/admin/withdrawals.php` | File **tidak ada** di tree saat ini; aksi admin terpusat di `admin/dashboard.php` (pending list + 3 form). | Penyesuaian daftar file audit. |
| **N2** | ℹ️ | `Admin_model` ketiga metode | Pre-check `status !== 'pending'` di **luar** TX = fast-path saja; otoritas tetap CAS di dalam TX. Dua admin konkurren → satu menang, satu `affected_rows()===0` ⇒ rollback + pesan "sudah diproses". | Bukan bug; jangan diubah jadi gerbang tunggal. |
| **N3** | ℹ️ | `Wallet_model::approve_withdrawal_simulator` | Pakai `trans_start()` bukan `trans_begin/try/catch` eksplisit; conditional + gate sudah benar. | Opsional diselaraskan gaya (cosmetic). |

---

## 3. STRATEGI HARDENING STATE-TRANSITION (SERVER)

Pola yang **sudah benar → dipertahankan & dijadikan standar tertulis** (jangan diregresi):
1. **CAS sebagai gerbang tunggal**: `UPDATE … SET status = target WHERE id = ? AND status = 'pending'`; keberhasilan = `affected_rows() === 1` (bukan hasil baca pre-check). Menangani double-click, replay, dan dua admin konkurren secara idempoten.
2. **Anchor row lock `FOR UPDATE`** sebagai statement pertama TX pada semua jalur kredit/debit (`lock_and_get_balance`) — serialisasi per-user; read view dibuat setelah lock wait sehingga re-check gatekeeper melihat state committed.
3. **Ledger & cache dalam TX yang sama** (`credit()`/`debit()` = caller-TX participant) — tidak ada orphan ledger row: setiap gagal (insert ledger / update cache / CAS 0 baris) ⇒ rollback penuh termasuk audit.
4. **Unique key backstop**: `uk_wd_number`, `uk_invoice_number`, `uk_wallet_ledger_user_tx_type` — duplikat deterministik ditolak DB.

Pekerjaan server yang tersisa (blueprint ini):
- **H1 — POST-gate semua mutator admin.** Di `Admin::approve_deposit/approve_withdrawal/decline_withdrawal`: `if ($this->input->method() !== 'post') { show_404(); return; }` (pola `Rentals::claim` plan/46). Terapkan juga pada sibling mutator uang (`expire_expired_rentals`, `toggle_registration`, `inject_balance`, `inject_rental`, `cancel_rental`, `adjust_time`, `reset_password`, `toggle_ban`, `create_user`, `update_user`, `settings`, `financial_settings`) sebagai sweep POST-only; wajib untuk yang memutasi uang/status finansial. PRG (`redirect` setelah POST) sudah ada.
- **H2 — Idempotent deposit-invoice.** `create_deposit()`: buat `invoice_number` bebas-bentrok (`YmdHis` + sufiks acak pendek, tetap deterministik-readable), **periksa hasil insert** (duplicate key → retry 1x dengan sufiks baru atau return structured error), kembalikan `{success, invoice_number}`; `Wallet::topup` map hasil → flashdata sukses/gagal. Opsional rate limit `topup:{uid}` (parity `withdraw`). Keputusan desain: **pertahankan multi-pending-invoice** (UI & DB memang mendukung daftar invoice pending); idempotensi dicapai lewat invoice unik + guard, bukan larangan multi-invoice.
- **H3 — Konsistensi error/notifikasi.** Tidak mengubah arsitektur: notifikasi user tetap fire-and-forget setelah commit (di luar TX) — dokumentasikan sebagai keputusan.

## 4. STRATEGI FRONTEND DOUBLE-CLICK PREVENTION (VANILLA JS)

**Keputusan:** helper Vanilla JS murni (tanpa Alpine — tidak ada di stack, AGENTS.md). Satu sumber di `application/views/templates/csrf_meta.php` (sudah di-load header admin & user, tempat `csrfFetch` didefinisikan):
- `window.guardFormSubmit = function (form)`: flag `data-submitting="1"` pada submit pertama; `preventDefault` + blokir submit berikutnya; disable semua `button[type="submit"]` di form; ganti label → `<i class="fas fa-spinner fa-spin mr-1"></i> Memproses…` + class `opacity-60 cursor-not-allowed`. Submit pertama **tidak** di-`preventDefault` (POST native + redirect + flashdata utuh — pola `claim-form` plan/46).
- Auto-bind: `document.addEventListener('submit', …)` delegation untuk `form[data-guard-submit]` (tidak perlu edit tiap handler).
- **Catatan CSRF-regenerate:** guard ini juga menyelesaikan gejala "POST kedua gagal CSRF": klik kedua tidak pernah terkirim. (Bila ingin defense ekstra: `csrf_regenerate` bisa dipertimbangkan ulang — di luar scope; UI guard + server CAS sudah menutup.)

**Penerapan** (tambahkan atribut `data-guard-submit` + spinner):
1. `admin/dashboard.php` — 3 form: approve deposit, approve WD, decline WD (pertahankan `confirm()`, jalankan sebelum guard).
2. `wallet/withdraw.php` — `#withdrawForm` (gabung dengan validasi: tombol tetap di-disable saat nominal invalid; saat submit valid → guard).
3. `wallet/index.php` — `#topupForm` + 2 form simulator (dev-only).
4. `marketplace/index.php` — `#form-checkout`.
5. `rentals/index.php` — form klaim: migrasi ke helper bersama (pola sama, hapus duplikasi inline) ATAU biarkan — keputusan implementasi: satukan agar satu pola.

## 5. INVENTORI FILE (SCOPE PERUBAHAN SAAT EKSEKUSI)

- `application/controllers/Admin.php` (POST-gate H1)
- `application/models/Wallet_model.php` (`create_deposit` H2)
- `application/controllers/Wallet.php` (map hasil topup H2; opsional rate limit)
- `application/views/templates/csrf_meta.php` (helper `guardFormSubmit` + delegation)
- `application/views/admin/dashboard.php`, `application/views/wallet/withdraw.php`, `application/views/wallet/index.php`, `application/views/marketplace/index.php`, (`application/views/rentals/index.php` konsolidasi)
- Tanpa perubahan skema DB (unique key sudah ada).

## 6. VERIFIKASI (SAAT EKSEKUSI)

1. `php -l` semua file PHP yang disentuh.
2. **Concurrency (curl):** dua POST paralel `admin/approve_withdrawal/{id}` & `admin/decline_withdrawal/{id}` pada WD yang sama → tepat satu `success` flashdata + satu baris ledger/audit; hasil kedua = pesan "sudah diproses" (HTTP 302). Sama untuk approve deposit (satu kredit) dan `wallet/process_withdraw` (satu WD + satu debit; yang kedua `pending_exists`).
3. **CSRF/GET:** `GET /admin/approve_withdrawal/5` → 404 (setelah H1); POST tanpa token → 403 CSRF standar.
4. **Topup:** dua POST `wallet/topup` cepat (detik sama) → tidak ada exception DB; invoice unik; flashdata sesuai hasil.
5. **UI:** klik-ganda cepat pada tombol Approve/Tarik Dana/Top Up/Klaim → tombol langsung disabled + spinner "Memproses…", hanya satu request jaringan (devtools), tidak ada layar error CSRF.
6. Re-run checklist audit §3-M4 (plan/37 §6 P1-11) setelah implementasi.

## 7. KEPUTUSAN YANG DIMINTA / ASUMSI

- **A1 (desain, tidak blocking):** multi-pending deposit invoice dipertahankan (konsisten UI saat ini); idempotensi = invoice unik + guard, bukan single-pending.
- **A2:** Guard frontend = Vanilla JS via delegation `data-guard-submit` (bukan Alpine — tidak ada di stack).
- **A3:** Notifikasi pasca-commit (fire-and-forget) dipertahankan; di luar atom finansial.
