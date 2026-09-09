# Plan 95 — Maintenance Mode Toggle (Member Site Lockdown, HTTP 503)

> **Status:** BLUEPRINT (dokumen arsitektur SAJA — Plan Mode). Belum ada
> perubahan kode aplikasi, skema DB, controller, model, atau view yang
> dilakukan oleh dokumen ini. Deliverable round ini = file ini saja
> (`plan/95_MAINTENANCE_MODE_PLAN.md`); eksekusi implementasi MENUNGGU
> instruksi lanjutan terpisah dari pemilik repositori.
>
> **Keputusan pemilik repositori:** tulis hanya file `.md` plan ini dulu;
> jangan eksekusi kode apa pun sampai ada prompt lanjutan (dokumen akan
> dianalisis terlebih dahulu).
>
> **Ruang lingkup:** Maintenance Mode **member-facing** yang berdiri sendiri
> dan terpisah penuh dari circuit breaker registrasi (`is_registration_open`).
> Admin (`/admin/*`, `/control-panel/*`) **tidak pernah** terkena kuncian.

---

## 1. Ringkasan & Tujuan

Saklar Maintenance Mode memberi admin satu kendali darurat: **mengunci seluruh
situs member (login, dashboard, marketplace, rentals, wallet, team, dsb.)**
dengan respons HTTP **503 Service Unavailable** dan halaman/JSON yang bersih,
sementara panel admin tetap 100% berfungsi agar mode bisa dimatikan kapan pun.

| Aspek | Nilai |
|---|---|
| Trigger | Admin dashboard Command Center (`views/admin/dashboard.php`) — tombol toggle |
| Cakupan kuncian | Semua trafik member & guest (HTML) + semua endpoint AJAX/API member/guest (JSON) |
| Pengecualian (bypass) | Admin routes, `control-panel`, session `admin_id`, dan proses CLI — **wajib lolos** |
| Respons web | Halaman maintenance standalone, responsif, dwibahasa, HTTP **503** |
| Respons API/AJAX | Envelope JSON standar `api_error(..., 503, ..., 'MAINTENANCE_MODE')` |
| Penyimpanan | Key `is_maintenance_mode` di `system_settings` (`'0'` normal, `'1'` aktif) |
| Basis data | **NOL perubahan skema** (key-value `system_settings` sudah ada) |
| Mutasi uang/saldo | **NOL** — fitur ini tidak menyentuh `wallet_ledger` atau data user |
| Bahasa | Pesan JSON persis sesuai spek (EN); halaman HTML dwibahasa statis |

---

## 2. Fakta Codebase yang Menjadi Dasar Desain

> Nomor baris mengacu ke HEAD saat dokumen ditulis; nomor dapat bergeser saat
> edit — verifikasi ulang sebelum patch.

### 2.1 Permukaan enforcement (siapa yang harus di-gate)

| Controller | Base class | Audience | Kena gate? | Titik sisip |
|---|---|---|---|---|
| `Home`, `Rentals`, `Wallet`, `Marketplace`, `Team`, `Profile`, `Help`, `Notification`, `User` | `MY_Controller` | member login | ✅ | constructor `MY_Controller` |
| `Auth` (`login`/`register`/`change_password`/`logout`/`refresh_captcha`) | `CI_Controller` | guest & member (pra-login/forced-pw) | ✅ | constructor `Auth` |
| `Lang` (`switch`) | `CI_Controller` | guest & member | ✅ | constructor `Lang` |
| `Admin` (`/admin/*`) | `CI_Controller` | admin | ❌ exempt | — |
| `Admin_auth` (`/control-panel`) | `CI_Controller` | admin | ❌ exempt | — |
| Script CLI (`scripts/expire_rentals.php`, seeder, dsb.) | — | internal | ❌ exempt | `is_cli()` |

Kesimpulan: **3 titik bootstrap** (`MY_Controller`, `Auth`, `Lang`) mencakup
SEMUA controller non-admin yang ada saat ini — tidak perlu hook.

### 2.2 Pola existing yang ditiru (jangan reinvent)

| Pola | Lokasi (HEAD) | Dipakai untuk |
|---|---|---|
| Circuit breaker registrasi: `Admin::toggle_registration()` | `application/controllers/Admin.php:1261-1300` | blueprint method `toggle_maintenance()` |
| Inject state ke dashboard | `Admin.php:116` (`is_registration_open` di `$data`) | `is_maintenance_mode` di `$data` |
| Read/write setting | `Admin_model::get_setting()` `:963-970`, `set_setting()` (upsert) `:972-977` | baca/tulis `is_maintenance_mode` — **tanpa SQL baru** |
| Audit atomik M5 | `Audit_model::log_admin_action()` `:33-43` (transaksi milik caller) | `admin_toggle_maintenance` |
| Envelope JSON M9/P7 | `application/helpers/api_helper.php` (`api_error` `:86-118`, `api_success` `:58-84`) | respons toggle + respons maintenance API |
| Deteksi JSON request | `MY_Exceptions::_wants_json()` `:95-112` (X-Requested-With **atau** `Accept: application/json`) | dipakai ulang semantiknya di helper gate |
| Security headers pada early-exit | `MY_Output::emit_security_headers()` `:35-46` (dipanggil `MY_Exceptions` `:82-87`) | halaman/JSON maintenance sebelum `exit` |
| `csrfFetch()` AJAX + token | `application/views/templates/csrf_meta.php:21-24` (set `X-Requested-With: XMLHttpRequest`) | JS dashboard `toggleMaintenance()` |
| Pin WIB statement DB pertama (M2) | `MY_Controller.php:9-17`, `Auth.php:9-11` | gate diletakkan **sebelum** pin ini |
| Lazy rental sweep M3 + guard redirect | `MY_Controller.php:25-60` | gate harus **sebelum** blok ini (terminate dini) |
| Autoload helper | `application/config/autoload.php:95` (`url,file,form,security,language,i18n`) | tambah `maintenance` |
| Hooks CI3 | `config.php:106` `$config['enable_hooks'] = FALSE` | **tidak dipakai** (lihat §3 G-2) |
| Autoload library | `autoload.php:61` (`database`, `session`, `form_validation`) | session+db tersedia tepat setelah `parent::__construct()` |

### 2.3 Storage & skema

- `system_settings` = key-value: `key_name VARCHAR(50) UNIQUE`, `key_value TEXT`
  (`database.sql:270-283`). Tidak ada perubahan DDL — cukup tambah baris seed.
- Canonical seed `database.sql:281-302` (blok `INSERT IGNORE`, idempotent,
  tidak menimpa nilai live); baris `('is_registration_open','1'),` di `:283`.
- `database_seed.sql:258-275` memuat blok `system_settings` sendiri.
  ⚠️ **Drift yang sudah ada (di luar scope):** `database_seed.sql` TIDAK memuat
  key `rebate_*` (plan/89) yang ada di `database.sql`. Dokumen ini tetap
  menyinkronkan KEDUA file untuk key baru kita (`is_maintenance_mode`).

### 2.4 Invariant repo yang dipegang

- **Dual-auth hard separation:** `user_id` vs `admin_id`; pengecualian admin
  dideteksi lewat `admin_id`, bukan role user.
- SQL hanya di model: gate memakai `Admin_model::get_setting()` — helper TIDAK
  menulis query mentah.
- `is_maintenance_mode` adalah fitur-flag terpisah; `is_registration_open`
  tidak diubah perilakunya sama sekali (regresi dicek di §9).
- Seluruh mutator admin: POST-only (M4) + CSRF + audit atomik (M5) — pola
  `toggle_registration()` diikuti persis.

---

## 3. Keputusan Arsitektur & Invariant

| Kode | Keputusan / Invariant | Sumber |
|---|---|---|
| S-1 | Key tunggal `is_maintenance_mode` (`'0'`=normal, `'1'`=maintenance aktif); simpan sebagai `TEXT` seperti key lain | Spek |
| S-2 | **Default saat baris hilang = `'0'` (site tetap live).** Beda dari `is_registration_open` (default tertutup). Hilangnya baris karena migrasi belum jalan TIDAK boleh mengunci seluruh situs; admin tetap bisa toggle (upsert) kapan pun | Arsitektur |
| S-3 | Baca/tulis hanya lewat `Admin_model::get_setting()`/`set_setting()` (upsert idempotent) — nol SQL baru, nol perubahan model | AGENTS.md (SQL di model) |
| G-1 | Enforcement = **constructor chokepoint** di 3 kelas (`MY_Controller`, `Auth`, `Lang`), dipanggil **sebagai statement pertama setelah `parent::__construct()`** | Spek ("MY_Controller and Auth") |
| G-2 | **BUKAN CI Hooks.** `enable_hooks=FALSE`; `pre_controller` jalan sebelum instance/Loader/session ada (tak bisa baca session); `post_controller_constructor` jalan SETELAH constructor → sweep M3 & baca saldo sudah terjadi, melanggar "terminate sebelum DB/sweep/session" | CI3 lifecycle + invariant M2/M3 |
| G-3 | Bypass ketat: (a) route admin/`control-panel` — konstruktor `Admin`/`Admin_auth` TIDAK memanggil gate (exempt by construction); (b) session `admin_id` ada → gate `return` (admin tak pernah terkunci, termasuk saat membuka URL member); (c) `is_cli()` → `return` (script/cron/seeder jalan normal) | Spek (strict bypass) |
| G-4 | Terminate semantics: saat aktif & non-admin, gate **exit** sebelum: pin WIB M2, `i18n_apply`, guard redirect login, sweep rental M3, baca user/balance/notifikasi. Hanya 1 query yang boleh terjadi: SELECT setting. Session member **tidak** dihancurkan (logout pun ter-kunci; sesi utuh saat mode mati) | Spek ("no DB queries, no rental sweeps, no session processing") |
| G-5 | HTML: **render langsung** halaman maintenance di URL yang diminta dengan HTTP 503 — **tanpa 302 redirect** (URL terjaga, tak ada cache redirect) | Arsitektur |
| G-6 | JSON/AJAX: deteksi parity `MY_Exceptions::_wants_json()` (X-Requested-With `xmlhttprequest` ATAU `Accept: application/json`) → `api_error('System is currently under maintenance. Please check back later.', 503, [], 'MAINTENANCE_MODE')` | Spek (kalimat persis) |
| E-1 | Halaman maintenance = view standalone di `application/views/errors/html/maintenance.php`: inline CSS, dwibahasa statis, `noindex`, **tanpa** shell `templates/header`/`bottom_nav`, **tanpa** baca `$global_*` (gate exit sebelum var global di-inject) | Spek (bilingual/clean/responsive) |
| A-1 | Toggle = `Admin::toggle_maintenance()`, URL **`POST /admin/toggle-maintenance`** (route eksplisit karena `translate_uri_dashes=FALSE`), POST-only + CSRF (`csrfFetch`) + guard `admin_id` constructor | Spek (M4) |
| A-2 | Audit atomik dalam TX yang sama dengan write setting: action `admin_toggle_maintenance`, `details {was_maintenance, is_maintenance}`, `user_id null`, `ip_address` | Spek (M5) |
| A-3 | Visual dashboard: tombol berdampingan dengan "Toggle Pendaftaran"; state normal = subtle/inaktif (site live), state aktif = **warning/danger** (ring merah/pulse) | Spek |
| Z-1 | Fitur ini TIDAK memutasi `wallet_ledger`/saldo/data user; murni gate + satu setting | AGENTS.md |

---

## 4. Settings & Storage (S)

### 4.1 Semantik key

| `key_value` | Arti | Efek |
|---|---|---|
| `'0'` | Normal (default) | Semua request member/guest berjalan seperti biasa |
| `'1'` | Maintenance aktif | Non-admin → 503 (HTML) / JSON envelope `MAINTENANCE_MODE` |

### 4.2 Sinkronisasi canonical (3 tempat)

**a) `database.sql`** — sisip setelah baris `('is_registration_open', '1'),`
(`:283`, sebelum komentar M1 `:284`):

```sql
('is_registration_open', '1'),
-- plan/95: Maintenance Mode member site (0=normal, 1=locked down; admin/CLI exempt).
('is_maintenance_mode', '0'),
```

**b) `database_seed.sql`** — sisip setelah `('is_registration_open', '1'),`
(`:261`). Perhatikan baris terakhir blok saat ini berakhiran `;`
(`('support_email', 'support@synapse.id');` di `:275`) → ubah `;`-nya jadi `,`
dan tambahkan baris baru:

```sql
('is_registration_open', '1'),
-- plan/95: Maintenance Mode member site (0=normal, 1=locked down; admin/CLI exempt).
('is_maintenance_mode', '0'),
```

**c) Live DB** (dieksekusi manual saat implementasi; idempotent, aman diulang):

```sql
INSERT IGNORE INTO system_settings (key_name, key_value) VALUES ('is_maintenance_mode', '0');
SELECT key_name, key_value FROM system_settings WHERE key_name = 'is_maintenance_mode';
```

Tidak ada `ALTER TABLE` (kolom `key_name`/`key_value` sudah ada), tidak ada
perubahan ERD (`system_settings` sudah di kanonik), dan `INSERT IGNORE` tidak
pernah menimpa nilai live — pola seed lama dipertahankan.

### 4.3 Jalur baca/tulis runtime

- **Baca (gate):** `Admin_model::get_setting('is_maintenance_mode')` — satu
  SELECT ber-index (`uk_key_name`). Hasil `=== '1'` → aktif; selain itu
  (termasuk `null`) → normal (S-2). Di-cache per-request (`static`) agar tidak
  dobel-baca bila gate dipanggil lebih dari sekali.
- **Tulis (admin toggle):** `Admin_model::set_setting('is_maintenance_mode',
  $new)` — upsert `ON DUPLICATE KEY UPDATE` (bekerja walau baris belum ada).

---

## 5. Global Request Gating & Intercept Lifecycle (G)

### 5.1 Arsitektur intercept

```
Request masuk (browser / fetch)
   │
   ▼
index.php → routing CI3 → CSRF preflight (POST body) ── tanpa token → 403 (sebelum gate)
   │
   ▼
Controller::__construct()
   │  parent::__construct()          ← autoload session + database (autoload.php:61)
   │  maintenance_gate();            ← PLAN 95: statement pertama (G-1/G-4)
   │     │ is_cli()? ───────────────► return (bypass, G-3c)
   │     │ session admin_id ada? ───► return (bypass, G-3b)
   │     │ is_maintenance_mode === '1'? ── tidak ──► return (normal, lanjut lifecycle)
   │     │ YA ↓
   │     ├─ JSON request? ──► api_error(..., 503, [], 'MAINTENANCE_MODE') + exit   (G-6)
   │     └─ HTML ──────────► set_status_header(503) + view maintenance + exit       (G-5/E-1)
   ▼
(aktif? ya & non-admin → TIDAK PERNAH sampai di sini)
pin WIB M2 → i18n_apply → guard redirect login → sweep rental M3 → baca saldo/notifikasi → method
```

Urutan CSRF: karena CI3 memvalidasi token POST di fase Input (sebelum
constructor), POST tanpa token tetap 403 terlebih dahulu; gate menangani semua
POST yang lolos CSRF. Ini disengaja dan tidak bermasalah (admin & member sama
kebagian; admin tidak pernah sampai ke gate).

### 5.2 Helper baru `application/helpers/maintenance_helper.php`

Satu-satunya logika gate, dipakai dari 3 constructor. Semua fungsi dibungkus
`function_exists()` (pola api_helper/i18n_helper) dan tidak menulis SQL mentah
(S-3). Blueprint implementasi:

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// ===================================================================
//  MAINTENANCE MODE GATE — plan/95
//
//  Dipanggil sebagai statement PERTAMA setelah parent::__construct()
//  di MY_Controller, Auth, dan Lang (G-1). Admin/Admin_auth TIDAK
//  pernah memanggil helper ini (exempt by construction, G-3a).
//  CLI di-skip (G-3c). Deteksi JSON parity MY_Exceptions::_wants_json
//  (G-6). Default saat key hilang = '0' (site live, S-2).
// ===================================================================

if ( ! function_exists('maintenance_is_active'))
{
    /**
     * Apakah maintenance mode aktif? Satu SELECT ber-index, di-cache
     * per-request. Key hilang/null -> FALSE (S-2).
     *
     * @return bool
     */
    function maintenance_is_active()
    {
        static $active = null;
        if ($active !== null)
        {
            return $active;
        }
        $ci =& get_instance();
        $ci->load->model('Admin_model');
        $active = ($ci->Admin_model->get_setting('is_maintenance_mode') === '1');
        return $active;
    }
}

if ( ! function_exists('_maintenance_wants_json'))
{
    /**
     * Deteksi JSON request — parity MY_Exceptions::_wants_json():
     * X-Requested-With: XMLHttpRequest (csrfFetch/fetch aplikasi) atau
     * Accept: application/json.
     *
     * @return bool
     */
    function _maintenance_wants_json()
    {
        if (is_cli())
        {
            return FALSE;
        }
        $xrw = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            ? strtolower(trim((string) $_SERVER['HTTP_X_REQUESTED_WITH']))
            : '';
        if ($xrw === 'xmlhttprequest')
        {
            return TRUE;
        }
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
        return (stripos($accept, 'application/json') !== FALSE);
    }
}

if ( ! function_exists('maintenance_gate'))
{
    /**
     * Gate utama. Panggil PALING AWAL di constructor member/guest.
     * Bypass: CLI, session admin_id, mode non-aktif. Aktif & non-admin:
     * JSON -> api_error(503, MAINTENANCE_MODE); HTML -> 503 + view.
     *
     * @return void
     */
    function maintenance_gate()
    {
        // G-3c: CLI (scripts/cron/seeder) tidak pernah dikunci.
        if (is_cli())
        {
            return;
        }

        $ci =& get_instance();

        // G-3b: admin session fully exempt (admin tidak pernah lock out;
        // termasuk saat membuka URL member dari browser yang sama).
        if ( ! empty($ci->session->userdata('admin_id')))
        {
            return;
        }

        // S-2: key hilang/null -> normal.
        if ( ! maintenance_is_active())
        {
            return;
        }

        // Parity MY_Exceptions: security headers sebelum early-exit.
        if (class_exists('MY_Output'))
        {
            MY_Output::emit_security_headers();
        }

        if (_maintenance_wants_json())
        {
            // G-6: envelope standar (kalimat persis spek).
            if ( ! function_exists('api_error')
                && file_exists(APPPATH . 'helpers/api_helper.php'))
            {
                require_once APPPATH . 'helpers/api_helper.php';
            }
            if (function_exists('api_error'))
            {
                api_error('System is currently under maintenance. Please check back later.', 503, [], 'MAINTENANCE_MODE');
            }

            // Fallback defensif: tetap JSON + status, tanpa leak apa pun.
            set_status_header(503);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'System is currently under maintenance. Please check back later.',
                'errors'  => [],
                'data'    => null,
                'code'    => 'MAINTENANCE_MODE',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // HTML: 503 + no-store + view standalone (G-5/E-1). Tanpa 302.
        set_status_header(503);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        $ci->load->view('errors/html/maintenance');
        exit;
    }
}
```

> Catatan implementasi: `set_status_header()` global (bukan `$ci->output->…`)
> dipakai persis seperti `api_helper::_api_send()` — langsung memanggil
> `http_response_code()` sehingga status 503 benar-benar terkirim walau kita
> `exit` sebelum `Output::_display()` (pola sudah terbukti di seluruh endpoint
> M9/P7 dan `MY_Exceptions`).

**Autoload:** tambah `'maintenance'` ke `$autoload['helper']`
(`application/config/autoload.php:95`) → tersedia otomatis di 3 constructor
dan controller masa depan.

### 5.3 Titik sisip per constructor

**a) `application/core/MY_Controller.php`** (baris ~6-8): panggil tepat setelah
`parent::__construct()` dan SEBELUM pin WIB M2 (`:17`), `i18n_apply` (`:23`),
guard redirect (`:27`), dan sweep M3 (`:53-60`).

```php
public function __construct() {
    parent::__construct();

    // plan/95 (G-1/G-4): maintenance gate PALING AWAL — sebelum pin WIB M2,
    // i18n_apply, guard redirect, sweep rental M3, dan baca saldo apa pun.
    // Exempt: CLI + session admin_id (G-3). Exit 503 untuk non-admin.
    maintenance_gate();

    // M2 (...komentar existing...): statement DB pertama SETELAH gate.
    $this->db->query("SET time_zone = '+07:00'");
```

> Penyesuaian komentar M2 (`:9-16`): tambahkan klausa "statement DB pertama
> (selain SELECT maintenance-gate)" agar komentar tetap jujur — SELECT gate
> membaca kolom `TEXT` saja (tidak sensitif timezone), lalu pin WIB tetap
> berjalan sebelum pembacaan `TIMESTAMP`/bisnis apa pun.

**b) `application/controllers/Auth.php`** (baris ~6-8): sama — setelah
`parent::__construct()`, sebelum `SET time_zone` (`:11`) dan seluruh
`load->model/helper` (`:19-24`) (semua kebutuhan gate dipenuhi sendiri oleh
helper via `get_instance()`).

**c) `application/controllers/Lang.php`** (baris ~21-24): setelah
`parent::__construct()` — menutup celah satu-satunya controller publik lain;
saat maintenance, `GET /lang/switch/(:any)` ikut 503 (tidak ada bypass).

### 5.4 Bypass — ringkasan ketat

| # | Siapa | Mengapa | Mekanisme |
|---|---|---|---|
| 1 | `/admin/*`, `/control-panel/*` | admin harus bisa matikan mode kapan pun | `Admin`/`Admin_auth` tidak memanggil gate (G-3a) |
| 2 | Session `admin_id` (browser admin) | admin tak terkunci di mana pun | cek `session->userdata('admin_id')` di gate (G-3b) |
| 3 | CLI (`scripts/*`, cron, seeder) | operasional internal tidak boleh berhenti | `is_cli()` di gate (G-3c) |
| 4 | Aset statis (`uploads/`, file public) | dilayani web server, bukan CI | otomatis |
| 5 | `404` (URL tak ter-routing) | tak bisa dicegat sebelum routing tanpa hook | tetap 404 CI standar (diterima, non-bisnis) |

### 5.5 Matriks perilaku saat `is_maintenance_mode = '1'`

| # | Request | Hasil |
|---|---|---|
| 1 | Member login, buka URL member mana pun (`/home`, `/wallet`, …) | HTML 503 halaman maintenance (sesi tetap utuh) |
| 2 | Guest buka `/login`, `/register`, `/auth/change-password` | HTML 503 (login/register non-aktif) |
| 3 | POST member/guest (`auth/login`, `auth/register`, `auth/logout`, `auth/change_password`) | HTML 503 (AJAX-nya: JSON 503) — logout pun ter-kunci; sesi tidak dihancurkan |
| 4 | `GET /auth/refresh_captcha` (AJAX, X-Requested-With) | JSON 503 `code: MAINTENANCE_MODE` (bukan 200 SVG) |
| 5 | AJAX member (`user/read_notifications`, `notification/*`, klaim team/promoter) | JSON 503 envelope standar |
| 6 | `GET /lang/switch/(en\|id)` | HTML 503 (via gate `Lang`) |
| 7 | `/admin/*` & `/control-panel` (GET/POST) | **Normal** — dashboard, approve/decline, toggle semua jalan |
| 8 | Browser dengan session `admin_id` membuka URL member | **Normal** (bypass G-3b) |
| 9 | `php scripts/expire_rentals.php --dry-run/--apply` | **Normal** (bypass G-3c) |
| 10 | URL tak dikenal | 404 CI standar (tidak berubah) |
| 11 | Setelah toggle OFF | Request berikutnya normal; sesi member yang tadi ter-kunci tetap valid |

### 5.6 Semantik HTTP 503

- **Status line:** `503 Service Unavailable` via `set_status_header(503)`
  global (parity `_api_send`).
- **Cache:** `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
  — mencegah proxy/browser menyajikan 503 basi SETELAH mode dimatikan.
- **SEO:** `<meta name="robots" content="noindex">` di halaman; 503 memang
  sinyal transient untuk crawler.
- **`Retry-After`:** TIDAK dikirim (ETA tidak diketahui; opsional 3600 bila
  diinginkan — dicatat sebagai keputusan kosmetik, bukan invariant).
- **Tanpa redirect:** halaman dirender langsung di URL yang diminta — URL
  dalam (deep link) tetap terjaga, tak ada 302 yang bisa di-cache, tombol
  back/refresh aman.
- **Security headers:** `MY_Output::emit_security_headers()` sebelum output
  (parity jalur error early-exit `MY_Exceptions`).
- **Body:** hanya view maintenance (HTML) / envelope JSON — tidak ada
  partial render shell member.

---

## 6. Admin Dashboard Controls & Mutation Security (A)

### 6.1 Data dashboard — `application/controllers/Admin.php` `dashboard()` (`:109-117`)

Tambah satu key di `$data` (di samping `is_registration_open` `:116`):

```php
'is_registration_open' => ($this->Admin_model->get_setting('is_registration_open') === '1'),
// plan/95: state maintenance mode untuk tombol toggle dashboard.
'is_maintenance_mode'  => ($this->Admin_model->get_setting('is_maintenance_mode') === '1'),
```

### 6.2 UI dashboard — `application/views/admin/dashboard.php`

Tombol "Toggle Pendaftaran" saat ini adalah satu-satunya anak sisi kanan header
Treasury Health (`:24-34`, `id="circuit-breaker-btn"`). Ubah sisi kanan menjadi
wrapper `flex items-center gap-2` berisi **dua tombol** (registrasi eksisting +
maintenance baru). Blueprint markup:

```php
<div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-2">
        <i class="fas fa-shield-halved ..."></i>
        <h2 class="...">Treasury Health</h2>
    </div>
    <div class="flex items-center gap-2">
        <button id="circuit-breaker-btn" onclick="toggleRegistration()" class="px-4 py-1.5 rounded-lg text-xs font-bold transition-all duration-200 <?= $is_registration_open ? 'bg-red-600 hover:bg-red-700 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white' ?>">
            <i class="fas fa-power-off mr-1"></i>
            <span id="cb-label"><?= $is_registration_open ? 'TUTUP PENDAFTARAN' : 'BUKA PENDAFTARAN' ?></span>
        </button>
        <!-- plan/95: Maintenance Mode toggle — state danger saat AKTIF -->
        <button id="maintenance-toggle-btn" onclick="toggleMaintenance()"
                title="<?= $is_maintenance_mode ? 'Situs member terkunci (503) — klik untuk mematikan' : 'Kunci situs member (maintenance mode)' ?>"
                class="px-4 py-1.5 rounded-lg text-xs font-bold border transition-all duration-200 <?= $is_maintenance_mode
                    ? 'bg-red-600 hover:bg-red-700 text-white border-red-400 ring-2 ring-red-500/60 animate-pulse'
                    : 'border-slate-500/60 text-slate-300 hover:bg-slate-700/50 hover:text-white' ?>">
            <i class="fas fa-triangle-exclamation mr-1"></i>
            <span id="mm-label"><?= $is_maintenance_mode ? 'MAINTENANCE AKTIF — MATIKAN' : 'MAINTENANCE NONAKTIF' ?></span>
        </button>
    </div>
</div>
```

**State visual (A-3):**

| State | Tombol | Label | Kesan |
|---|---|---|---|
| Normal (`'0'`) | subtle: outline abu tipis, teks slate, hover redup | `MAINTENANCE NONAKTIF` | situs member live; aksi = aktifkan |
| Aktif (`'1'`) | danger: `bg-red-600`, `ring-2 ring-red-500/60`, `animate-pulse`, border merah | `MAINTENANCE AKTIF — MATIKAN` | situs member terkunci; aksi = matikan |

**JS** — tambah fungsi di blok `<script>` dashboard yang sama (`:233-272`),
cermin persis `toggleRegistration()` (csrfFetch + state swap + alert memakai
`data.error`):

```js
function toggleMaintenance() {
    const btn = document.getElementById('maintenance-toggle-btn');
    const label = document.getElementById('mm-label');
    const originalText = label.textContent;
    const originalClass = btn.className;

    btn.disabled = true;
    label.textContent = 'Memproses...';
    btn.classList.add('opacity-75', 'cursor-not-allowed');

    csrfFetch('<?= base_url('admin/toggle-maintenance') ?>', { method: 'POST' })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const isMM = data.is_maintenance_mode;   // key legacy root (parity is_open)
            label.textContent = isMM ? 'MAINTENANCE AKTIF — MATIKAN' : 'MAINTENANCE NONAKTIF';
            // swap class: danger ring/pulse vs subtle outline
            if (isMM) {
                btn.classList.remove('border-slate-500/60', 'text-slate-300');
                btn.classList.add('bg-red-600', 'hover:bg-red-700', 'text-white', 'border-red-400', 'ring-2', 'ring-red-500/60', 'animate-pulse');
            } else {
                btn.classList.remove('bg-red-600', 'hover:bg-red-700', 'text-white', 'border-red-400', 'ring-2', 'ring-red-500/60', 'animate-pulse');
                btn.classList.add('border-slate-500/60', 'text-slate-300');
            }
        } else {
            label.textContent = originalText;
            alert('Gagal: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(() => { label.textContent = originalText; alert('Terjadi kesalahan jaringan.'); })
    .finally(() => { btn.disabled = false; });
}
```

> JS membaca `data.is_maintenance_mode` dari **root legacy** (kontrak M9/P7),
> sama seperti `toggleRegistration()` membaca `data.is_open`.

### 6.3 Route — `application/config/routes.php` (append, gaya `:52-58`)

```php
// plan/95: maintenance mode toggle (POST-only; dash di URL butuh route
// eksplisit karena translate_uri_dashes=FALSE).
$route['admin/toggle-maintenance'] = 'admin/toggle_maintenance';
```

### 6.4 Controller — `Admin::toggle_maintenance()` (cermin `toggle_registration()` `:1261-1300`)

Letakkan setelah method `toggle_registration()` (blok "CIRCUIT BREAKER TOGGLE"
→ tambah blok "PLAN 95: MAINTENANCE MODE TOGGLE").

```php
// ===================================================================
//  PLAN 95: MAINTENANCE MODE TOGGLE
// ===================================================================

public function toggle_maintenance() {
    // M4 (plan/62 S1): POST-only fail-closed — mutasi tidak boleh via GET.
    if ($this->input->method() !== 'post') {
        // M9/P7: 405 JSON + content-type + legacy `error` alias.
        api_error('Method not allowed', 405, [], 'method_not_allowed', ['error' => 'Method not allowed']);
    }

    $this->load->model('Admin_model');

    $current = $this->Admin_model->get_setting('is_maintenance_mode');
    $new_value = ($current === '1') ? '0' : '1';

    // M5: write setting + audit atomik dalam SATU TX (rollback menghapus
    // keduanya). CSRF otomatis (csrfFetch kirim token); guard admin_id
    // sudah di constructor Admin.
    $this->db->trans_start();
    $this->Admin_model->set_setting('is_maintenance_mode', $new_value);
    $this->load->model('Audit_model');
    $this->Audit_model->log_admin_action(
        (int) $this->session->userdata('admin_id'),
        null,
        'admin_toggle_maintenance',
        ['was_maintenance' => ($current === '1'), 'is_maintenance' => ($new_value === '1')],
        $this->input->ip_address()
    );
    $this->db->trans_complete();

    $success = $this->db->trans_status();
    $is_maintenance = ($new_value === '1');

    if ($success) {
        $message = $is_maintenance
            ? 'Mode maintenance AKTIF — situs member terkunci (HTTP 503).'
            : 'Mode maintenance NONAKTIF — situs member normal.';
        // Envelope + legacy root {is_maintenance_mode, message} (dibaca dashboard).
        api_success(['is_maintenance_mode' => $is_maintenance], $message, 200,
            ['is_maintenance_mode' => $is_maintenance, 'message' => $message]);
    }

    // Gagal transaksi: HTTP 500 + legacy + alias `error` (bug "Unknown error" tertutup).
    $message = 'Gagal mengubah mode maintenance.';
    api_error($message, 500, [], 'toggle_failed',
        ['is_maintenance_mode' => $is_maintenance, 'message' => $message, 'error' => $message]);
}
```

### 6.5 Keamanan mutasi (ringkas)

- **POST-only:** non-POST → `api_error(405, 'method_not_allowed')` — pola M4.
- **CSRF:** `csrf_protection=TRUE`; `csrfFetch()` menambahkan token otomatis;
  POST tanpa token → 403 CI (tidak pernah sampai ke method).
- **Otorisasi:** guard `admin_id` di constructor `Admin` (`:20-22`) — member
  tidak bisa memanggil.
- **Audit atomik:** satu `trans_start/trans_complete` membungkus
  `set_setting()` + `log_admin_action()`; viewer audit
  (`Admin::audit`, `Audit_model::get_action_options`) otomatis menampilkan
  action `admin_toggle_maintenance` tanpa perubahan lain.
- **Tanpa penguncian diri:** admin menyalakan mode dari dashboard → respons
  tetap 200 (session `admin_id` bypass G-3b), dashboard tetap terbuka untuk
  mematikan mode.

---

## 7. Error View & API Fail-Closed (E)

### 7.1 Halaman maintenance — `application/views/errors/html/maintenance.php` (BARU)

View standalone **tanpa** `templates/header`/`bottom_nav` dan **tanpa**
`$global_*` (E-1 — gate exit sebelum var global di-inject). Spesifikasi:

- `<!DOCTYPE html>` lengkap; `<meta name="viewport">` mobile-first;
  `<meta name="robots" content="noindex">`; `<title>Maintenance</title>`.
- **Inline CSS** minimal (bukan Tailwind CDN): sistem font stack, latar
  gradient gelap, kartu tengah terpusat, ikon SVG inline (gear/alert) —
  halaman tetap rapi walau CDN/aset eksternal tidak terjangkau.
- **Dwibahasa statis** (spek mengizinkan "clean bilingual message"): headline
  Indonesian + paragraf English, mis.:

  > **Sistem sedang dalam pemeliharaan** / *We're currently under maintenance*
  > Silakan kembali lagi nanti. Sesi Anda tetap aman. / *Please check back
  > later. Your session remains safe.*

- Tidak ada tautan logout/login, tidak ada form, tidak ada JS dependency.
- Status 503 + header dikirim oleh `maintenance_gate()` **sebelum** view
  di-load (`set_status_header(503)` + `Cache-Control` + security headers).

Blueprint kerangka minimal (final styling bebas saat implementasi):

```html
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Maintenance</title>
<style>/* inline minimal: body flex center, card, ikon SVG */</style>
</head>
<body>
  <!-- ikon SVG inline -->
  <h1>Sistem sedang dalam pemeliharaan</h1>
  <p>Situs sedang dikunci sementara untuk perawatan. Silakan kembali lagi nanti — sesi Anda tetap aman.</p>
  <p class="en">We&rsquo;re currently under maintenance. Please check back later — your session remains safe.</p>
</body>
</html>
```

### 7.2 Respons JSON (fail-closed)

Setiap request JSON/AJAX non-admin saat mode aktif menerima (G-6), status **503**:

```json
{
  "success": false,
  "message": "System is currently under maintenance. Please check back later.",
  "errors": [],
  "data": null,
  "code": "MAINTENANCE_MODE"
}
```

- Dikirim lewat choke-point `api_error()` (helper di-`require_once` jika
  belum ter-load — pola `MY_Exceptions::_emit_json_error()`); fallback
  defensif tetap JSON + status tanpa leak (lihat §5.2).
- `Content-Type: application/json` + `emit_security_headers()` sebelum kirim.
- Konsumen `fetch().json()` tidak pernah menerima HTML (invariant M9/P7
  dipertahankan pada jalur maintenance).

### 7.3 Catatan fail-closed vs kondisi di luar kendali

- Key hilang → **default `'0'`** (S-2): situs tetap hidup; admin bisa toggle
  (upsert). Bukan kondisi "terkunci karena salah konfigurasi".
- DB down total → request gagal di lapisan koneksi (bukan gate): tetap
  menghasilkan error 500/JSON `internal_error` via `MY_Exceptions`, bukan
  halaman maintenance. Diterima: maintenance mode mengasumsikan DB sehat
  (setting harus terbaca).

---

## 8. Step-by-Step Implementation Plan

> Urutan aman: **storage → helper → view → gate → admin → verifikasi → docs.**
> Lint (`php -l`) setiap file PHP baru/ubah (aturan roadmap).
> Eksekusi SEMUA langkah ini menunggu prompt lanjutan pemilik repositori.

| # | Langkah | File | Verifikasi cepat |
|---|---|---|---|
| 1 | Sisip seed `('is_maintenance_mode','0')` setelah `is_registration_open` | `database.sql` (`:283`) | `grep -n "is_maintenance_mode" database.sql` |
| 2 | Sisip seed yang sama (sesuaikan koma baris terakhir blok) | `database_seed.sql` (`:261`, `:275`) | `grep -n "is_maintenance_mode" database_seed.sql` |
| 3 | Jalankan SQL live idempotent (§4.2c) + SELECT sanity | live DB (manual, via `mysql`/client) | SELECT mengembalikan `'0'` |
| 4 | Buat `application/helpers/maintenance_helper.php` (§5.2) | baru | `php -l` |
| 5 | Daftarkan helper `'maintenance'` di autoload | `application/config/autoload.php:95` | `php -l` |
| 6 | Buat view maintenance standalone | `application/views/errors/html/maintenance.php` | buka via browser saat aktif |
| 7 | Panggil `maintenance_gate()` di constructor + sesuaikan komentar M2 | `application/core/MY_Controller.php` | `php -l`; request member normal masih 200 |
| 8 | Panggil `maintenance_gate()` di constructor | `application/controllers/Auth.php` | `php -l`; `/login` normal masih 200 |
| 9 | Panggil `maintenance_gate()` di constructor | `application/controllers/Lang.php` | `php -l`; `lang/switch` normal masih jalan |
| 10 | Tambah route `admin/toggle-maintenance` | `application/config/routes.php` | `php -l` |
| 11 | Tambah `is_maintenance_mode` ke `$data` dashboard | `application/controllers/Admin.php` (`:116` area) | `php -l` |
| 12 | Tambah method `toggle_maintenance()` | `application/controllers/Admin.php` (setelah `:1300`) | `php -l` |
| 13 | Tambah tombol kedua (wrapper flex) + JS `toggleMaintenance()` | `application/views/admin/dashboard.php` (`:29-33`, `:233-272`) | render dashboard; klik toggle |
| 14 | Jalankan matriks verifikasi §9 (mode ON lalu OFF) | — | lihat §9 |
| 15 | Sinkronisasi docs: catatan AGENTS.md + komentar DDL | `AGENTS.md`, `database.sql:270` | lihat §10 |
| 16 | Commit (pesan Indonesian, gaya repo) | — | lihat §10 |

---

## 9. Verification Matrix

> Setup: mode OFF dulu (`key_value='0'`), lalu ON (`'1'`) untuk baris ber-label
> ON; session admin via browser (login `/control-panel`) — login member
> ber-CAPTCHA native sehingga uji member penuh via browser, uji guest/AJAX/
> CSRF/HTTP-code via `curl`. Base URL dev: `http://synapse.test/`.

| # | Skenario | State | Ekspektasi | Perintah / cek |
|---|---|---|---|---|
| 1 | Seed canonical sinkron | — | key ada di KEDUA file SQL, nilai `'0'` | `grep -n "is_maintenance_mode" database.sql database_seed.sql` |
| 2 | Live DB berisi key | — | 1 baris `'0'` | `SELECT key_name,key_value FROM system_settings WHERE key_name='is_maintenance_mode';` |
| 3 | Lint semua file PHP diubah | — | tanpa error sintaks | `php -l` tiap file langkah 4,5,7,8,9,10,11,12 |
| 4 | Request member normal (regresi) | OFF | 200, aplikasi jalan normal | browser login member → `/home` 200 |
| 5 | Guest `/login` | ON | HTTP 503 + body halaman maintenance | `curl -s -o /dev/null -w '%{http_code}' http://synapse.test/login` |
| 6 | Member URL dalam | ON | 503 (bukan redirect 302) | `curl -s -o /dev/null -w '%{http_code}' -b <member-cookie> http://synapse.test/home` |
| 7 | AJAX member/guest | ON | JSON `{success:false, code:'MAINTENANCE_MODE'}`, status 503, `Content-Type: application/json` | `curl -s -i -H 'X-Requested-With: XMLHttpRequest' http://synapse.test/auth/refresh_captcha` |
| 8 | AJAX tanpa header XRW tapi `Accept: application/json` | ON | JSON 503 (parity `_wants_json`) | `curl -s -i -H 'Accept: application/json' http://synapse.test/login` |
| 9 | `Lang::switch` | ON | 503 | `curl -s -o /dev/null -w '%{http_code}' http://synapse.test/lang/switch/en` |
| 10 | Admin dashboard | ON | 200; tombol maintenance state merah/aktif | browser admin → `/admin` |
| 11 | Admin approve/action | ON | normal (exempt) | browser admin → approve WD/deposit tetap jalan |
| 12 | `/control-panel` login admin | ON | login sukses → redirect `/admin` | browser/curl admin login |
| 13 | Session admin buka URL member | ON | 200 (bypass G-3b) | browser admin → `/home` member |
| 14 | Toggle ON | OFF→ON | 200 `{success:true, is_maintenance_mode:true}`; tombol berubah merah | klik tombol / `POST /admin/toggle-maintenance` dgn token CSRF |
| 15 | Audit row toggle ON | — | action `admin_toggle_maintenance`, details `{"was_maintenance":false,"is_maintenance":true}`, ip benar | `SELECT action,details,ip_address FROM system_audit_logs WHERE action='admin_toggle_maintenance' ORDER BY id DESC LIMIT 2;` |
| 16 | Non-POST ke endpoint toggle | — | 405 JSON `code:'method_not_allowed'` | `curl -s -i http://synapse.test/admin/toggle-maintenance` |
| 17 | POST tanpa token CSRF | — | 403 (sebelum gate) | `curl -s -o /dev/null -w '%{http_code}' -X POST http://synapse.test/admin/toggle-maintenance` |
| 18 | Toggle OFF | ON→OFF | 200 `{success:true, is_maintenance_mode:false}`; audit `was_maintenance:true, is_maintenance:false` | klik tombol + query audit (§15) |
| 19 | Situs pulih | OFF | `/login` 200; member login normal | `curl`/browser |
| 20 | Sesi member utuh setelah mode mati | ON→OFF | member masih login (tanpa login ulang) | browser member — refresh pasca-OFF |
| 21 | Regresi `toggle_registration` | — | 200 + audit `admin_toggle_registration` normal | klik "Toggle Pendaftaran" |
| 22 | CLI bypass | ON | script jalan normal | `php scripts/expire_rentals.php --dry-run` |
| 23 | Audit viewer menampilkan action baru | ON | dropdown/filter memuat `admin_toggle_maintenance` | `/admin/audit?action=admin_toggle_maintenance` |
| 24 | Header keamanan + no-store di halaman 503 | ON | `Cache-Control: no-store…`, security headers ada | `curl -s -i http://synapse.test/login` |
| 25 | 404 tak berubah | ON | 404 standar (bukan 503) | `curl -s -o /dev/null -w '%{http_code}' http://synapse.test/tidak-ada` |

---

## 10. Docs Sync & Follow-ups

1. **AGENTS.md** — tambah bullet quick-add pada bagian Notes (konvensi repo),
   ringkas: key `is_maintenance_mode`; gate di constructor `MY_Controller`/
   `Auth`/`Lang` via `maintenance_helper.php` (statement pertama setelah
   `parent::__construct()`, sebelum pin WIB/sweep M3); bypass admin (route +
   `admin_id`) & CLI; HTML 503 → `views/errors/html/maintenance.php`;
   JSON 503 `code MAINTENANCE_MODE`; toggle `POST /admin/toggle-maintenance`
   + audit `admin_toggle_maintenance`; default `'0'` saat key hilang.
2. **`database.sql:270`** — komentar DDL `system_settings` boleh diperluas:
   `(key-value; circuit breaker Phase 9A + maintenance mode plan/95)`.
3. **Dokumen lain:** tidak ada perubahan ERD (schema tidak berubah); ROADMAP
   tidak menyentuh fitur ini (opsional: catat di roadmap bila ada gelombang).
4. **Summary doc** (konvensi PLAN→SUMMARY): setelah implementasi + verifikasi,
   tulis `plan/96_MAINTENANCE_MODE_SUMMARY.md` dengan hasil runtime (parity
   `91→92`, `94→94-SUMMARY`).
5. **Commit message (gaya repo, Indonesian):** mis.
   `plan/95: maintenance mode toggle member site (503 gate + admin switch)`.

### Out of scope (dicatat agar tidak jadi scope creep)

- Hooks CI3 global / perubahan `enable_hooks`.
- Halaman maintenance dengan pesan/kontak dinamis dari DB (opsional
  evolusi: render `wa_number`/`support_email` — butuh baca setting ekstra).
- Penjadwalan otomatis (buka/tutup maintenance per jam).
- Banner countdown/ETA atau `Retry-After` dinamis.
- Perbaikan drift seed `rebate_*` (`database_seed.sql`) — terpisah.

---

## 11. Asumsi yang Dipegang

| # | Asumsi | Dampak bila salah |
|---|---|---|
| 1 | Bahasa dokumen internal mengikuti gaya plan repo (Indonesian + istilah teknis EN) | hanya kosmetik |
| 2 | Pesan JSON maintenance persis teks EN dari spek | kosmetik; mudah diganti bila ingin ID |
| 3 | Default saat key hilang = `'0'` (site live) — beda dari `is_registration_open` | keamanan vs availability; sudah dipilih sengaja (S-2) |
| 4 | Deteksi JSON = parity `MY_Exceptions::_wants_json()` (bukan hanya `is_ajax_request`) | konsumen JSON tanpa header XRW tetap dapat envelope |
| 5 | View di `errors/html/maintenance.php` (bukan root `views/maintenance.php`) — mengelompokkan halaman error; keduanya valid | hanya lokasi file |
| 6 | Endpoint memakai dash `admin/toggle-maintenance` + route eksplisit (spek); method controller tetap `toggle_maintenance()` (konvensi PHP) | URL alternatif underscore juga jalan (tanpa route) |
| 7 | Session member TIDAK dihancurkan saat maintenance (logout ter-kunci) | member tetap login pasca-mode; sesuai G-4 |
| 8 | Admin dengan session `admin_id` melihat situs member normal selama maintenance (bypass penuh) | persis spek "admin sessions fully exempt" |
| 9 | Nomor baris yang dikutip valid di HEAD saat dokumen ditulis; verifikasi ulang saat patch | edit bisa menggeser nomor |

---

## 12. Checklist Spek → Bagian Dokumen

| Spek / Deliverable | Terpenuhi di |
|---|---|
| Key `is_maintenance_mode` di `system_settings` (0/1) | §4.1, §4.2 |
| Sinkronisasi canonical `database.sql` + migrasi live | §4.2 |
| Enforcement early bootstrap `MY_Controller` + `Auth` (+`Lang`) | §3 G-1, §5.2–5.3 |
| Bypass ketat admin routes/`control-panel`/session admin | §3 G-3, §5.4 |
| Halaman maintenance 503 (HTML) + pesan dwibahasa | §5.5, §5.6, §7.1 |
| API/AJAX → `api_error(..., 503, 'MAINTENANCE_MODE')` | §3 G-6, §7.2 |
| Terminate segera (tanpa query/sweep/session member) | §3 G-4, §5.1, §5.3 |
| Tombol toggle dashboard di samping "Toggle Pendaftaran" | §6.2 |
| Visual state normal subtle vs aktif danger (ring merah) | §3 A-3, §6.2 |
| `POST /admin/toggle-maintenance`, POST-only + CSRF | §3 A-1, §6.3–6.5 |
| Audit atomik `admin_toggle_maintenance` before/after | §3 A-2, §6.4 |
| Step-by-step implementation plan | §8 |
| Verification matrix lengkap | §9 |
| File `plan/95_MAINTENANCE_MODE_PLAN.md` (deliverable round ini) | file ini |
