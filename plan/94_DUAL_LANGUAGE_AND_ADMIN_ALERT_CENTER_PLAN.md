# Plan 94 — Dual-Language Engine (Member) & Admin Real-Time Alert Center

> **Status:** BLUEPRINT (dokumen arsitektur SAJA — Plan Mode). Belum ada
> perubahan kode aplikasi, skema DB, controller, model, atau view yang
> dilakukan oleh dokumen ini; eksekusi menunggu instruksi lanjutan terpisah.
>
> **Keputusan pengguna (dec-c3f38a96e356cc7a) — arsitektur queue admin:**
> 1. **Tanpa halaman queue baru** — alur kerja Command Center dashboard
>    (`admin/`) dipertahankan apa adanya (hindari scope creep).
> 2. **Klaim Promoter:** sidebar & bell deep-link mengarah ke halaman
>    dedicated-nya: `/admin/promoter-claims`.
> 3. **Pending Deposits & Withdrawals:** sidebar items baru **Deposit** &
>    **Penarikan** serta bell deep-link mengarah ke dashboard Command Center
>    dengan anchor ke container tabel pending masing-masing
>    (`/admin#pending-deposits`, `/admin#pending-withdrawals`). View dashboard
>    wajib diberi `id` container agar klik melompat/scroll ke tabel tsb.
> 4. Badge counter merah live ditempel pada nav item Deposit / Penarikan /
>    Klaim Promoter di sidebar.

---

## 1. Ringkasan & Tujuan

Dua fitur independen yang dirilis dalam satu gelombang:

**F1 — Dual-Language Engine (member-facing ONLY).** Area member
(Auth, Dashboard, Marketplace, Rentals, Team, Wallet, Profile) dapat
ditampilkan dalam **English (default)** atau **Indonesian (sekunder)**.
Admin panel **tetap 100% Indonesian** — tidak pernah tersentuh sistem ini.

**F2 — Admin Operational Alert Center (real-time).** Admin mendapat
visibilitas real-time atas 3 antrean bergerak cepat: **pending deposits**,
**pending withdrawals**, **pending promoter claims** — lewat badge sidebar,
dropdown bell di topbar, dan audio chime saat `total_urgent` naik.

| Aspek | F1 (Bahasa) | F2 (Alert Center) |
|---|---|---|
| Audience | Member (login & pra-login) | Admin saja |
| Bahasa UI | EN (default) + ID | Tetap Indonesian |
| Mekanisme | Dictionaries `app_lang.php` + helper CI `lang()` | Polling GET ringan 25 dtk + DOM badge/bell |
| Persistensi | Session `site_lang` + cookie 30 hari | `localStorage` (mute chime) |
| Basis data | **NOL perubahan skema** | 2 index baru (lihat §4.3) |
| Invariant uang | IDR murni; format `Rp` & angka TIDAK berubah oleh bahasa | — |

---

## 2. Keputusan Arsitektur & Invariant yang Dipegang

| Kode | Keputusan / Invariant | Sumber |
|---|---|---|
| L1 | Admin (Admin/Admin_auth + seluruh view `admin/*`) **tidak pernah** memuat `app_lang` — tetap hardcoded Indonesian | Spek |
| L2 | Default = `english` untuk visitor baru / first-time user; precedence: `session('site_lang')` → cookie `site_lang` → `english` | Spek |
| L3 | Nilai tersimpan = kode pendek `en`/`id`; pemetaan idiom CI: `en→english`, `id→indonesian`. `config['language']` (english) dibiarkan untuk internal CI | Arsitektur |
| L4 | Tepat SATU idiom dimuat per request member — CI `Lang::load` meng-merge array, idiom kedua akan menimpa baris pertama (tidak boleh dobel-load) | CI3 |
| L5 | Seluruh teks statis view member lewat `lang('key')`; teks dinamis/JS lewat kamus kecil `window.SYNAPSE_I18N` yang di-inject PHP (bukan string mentah dalam JS) | Spek "tanpa merusak binding" |
| L6 | **Uang & angka tidak pernah diterjemahkan**: `Rp` + `number_format(…, 0, ',', '.')` / `Intl id-ID` dipakai apa adanya di kedua bahasa; bahasa hanya mengganti kata | AGENTS.md (IDR-only) |
| L7 | Konten DB (nama produk `gpu_products.name`, riwayat `user_notifications.title/message`) **di luar scope terjemahan** — ditulis saat event (lihat §3.6.3) | Keputusan scope |
| L8 | Switcher `GET /lang/switch/(:any)` hanya menerima `en`/`id`; redirect balik **hanya** ke referer same-host (anti open-redirect), fallback `base_url()` | Keamanan |
| A1 | Endpoint poll **read-only** (`COUNT`), GET, admin-only, tanpa audit, tanpa CSRF-token (bukan POST) | M4/P7 |
| A2 | Seluruh JSON lewat `api_success()`/`api_error()` (envelope `{success,message,data,…}`) + key legacy di root untuk konsumen lama — kontrak plan/76 | M9/P7 |
| A3 | Counts memakai index ber-leading-`status` → scan index-saja; dashboard `ORDER BY created_at` juga terlayani index `(status, created_at)` | §4.3 |
| A4 | SSR awal (badge/bell terisi sebelum poll pertama) via `$this->load->vars('global_admin_alerts')` di constructor `Admin` — pola sama `global_balance` MY_Controller | Pola ada |
| A5 | Satu request in-flight; poll berhenti saat tab hidden (`document.hidden`) & langsung poll saat visible kembali; error jaringan = retry senyap | Robustness |
| A6 | Chime = Web Audio API (sintesis 2 nada) — **nol aset file**; butuh user gesture pertama (autoplay policy); mute persist `localStorage('admin_alerts_muted')` | Spek |
| A7 | Anchor dashboard: `id="pending-deposits"` & `id="pending-withdrawals"` pada container tabel di `admin/dashboard.php` (deep-link target) | dec-c3f38a96e356cc7a |
| Z1 | Tidak ada mutasi `wallet_ledger`/uang di kedua fitur; F2 murni pembacaan status | AGENTS.md |

---

## 3. F1 — Dual-Language Engine (Member-Facing Only)

### 3.1 Arsitektur & Alur Data

```
Request member (browser)
   │
   ▼
[Auth] atau [MY_Controller + turunannya]  ── Auth & Lang adalah CI_Controller
   │  constructor: i18n_apply()
   │    1. resolve: session('site_lang') → cookie('site_lang') → 'en'
   │    2. $this->lang->load('app_lang', idiom)   ← hanya idiom terpilih (L4)
   │    3. load->vars: site_lang_code ('en'|'id') → <html lang> + switcher UI
   │
   ▼
View member  →  <?= lang('key'); ?>        (language helper, autoload)
                 window.SYNAPSE_I18N = {...} (hanya key ber-prefix js_)
   │
   ▼
[GET /lang/switch/en | /lang/switch/id]  (Lang controller, pra-login boleh)
     1. validasi kode ∈ {en,id}      → selain itu 404
     2. session->set_userdata('site_lang', code)
     3. cookie site_lang = code, expire 30 hari (httponly, secure mengikuti transport)
     4. redirect HTTP_REFERER same-host (L8), fallback base_url()
```

* Admin & `Admin_auth` tidak berada di jalur ini (L1) — tidak ada `i18n_apply`.
* Guest hanya menjangkau `auth/*`, `home` (redirect login), dan `lang/switch`
  — jadi titik bootstrap cukup **2 kelas**: `MY_Controller` (9 controller
  member login) + `Auth` (login/register/change_password). Tidak perlu hook.

### 3.2 File Baru/Ubah — F1

| File | Aksi | Isi |
|---|---|---|
| `application/language/english/app_lang.php` | baru | `$lang['key'] = '…'` EN (default) |
| `application/language/indonesian/app_lang.php` | baru | `$lang['key'] = '…'` ID (sumber: string Indonesian yang SUDAH ada di view) |
| `application/helpers/i18n_helper.php` | baru | `i18n_resolve()`, `i18n_apply()`, `i18n_code()`, `i18n_idiom()`, `i18n_is_referer_same_host($url)` — semua dibungkus `function_exists()` (pola api_helper) |
| `application/controllers/Lang.php` | baru | `Lang extends CI_Controller`, method `switch($code)` (lihat §3.4) |
| `application/config/routes.php` | ubah | `$route['lang/switch/(:any)'] = 'lang/switch/$1';` |
| `application/config/autoload.php` | ubah | tambah `'language'` ke `$autoload['helper']` (helper core `lang()`) |
| `application/core/MY_Controller.php` | ubah | panggil `i18n_apply()` di constructor (sebelum guard redirect; sesudah pin WIB) + inject vars |
| `application/controllers/Auth.php` | ubah | panggil `i18n_apply()` di constructor (Auth = CI_Controller) |
| `application/views/templates/header.php` | ubah | `<html lang=…>` dinamis, include switcher, teks → `lang()` |
| `application/views/templates/bottom_nav.php` | ubah | label nav → `lang()` |
| `application/views/auth/{login,register,change_password}.php` | ubah | `<html lang>`, include switcher, teks → `lang()` |
| view member per scope (§3.6.2) | ubah | teks statis → `lang()` bertahap (roadmap M3) |

### 3.3 Spesifikasi Helper (`i18n_helper.php`)

```php
// Resolve kode bahasa: session → cookie → 'en'. Selalu mengembalikan 'en'|'id'.
function i18n_resolve() { … }

// Apply ke request berjalan: load('app_lang', idiom) SEKALI + set config/vars.
// Return kode ('en'|'id').
function i18n_apply() {
    $ci =& get_instance();
    $code = i18n_resolve();
    $ci->lang->load('app_lang', ($code === 'id') ? 'indonesian' : 'english');
    $ci->load->vars(['site_lang_code' => $code]);
    return $code;
}

// Validasi referer: host harus sama dengan HTTP_HOST (anti open-redirect L8).
function i18n_is_referer_same_host($referer) { … }
```

- Pemetaan idiom **tidak boleh** di-encode dua kali — taruh konstanta di helper
  (`I18N_IDIOMS = ['en'=>'english','id'=>'indonesian']`) agar Auth, MY_Controller,
  dan Lang controller satu sumber.
- `i18n_apply()` dipanggil **setelah** `$this->session` tersedia (session
  autoload) dan **sebelum** logika bisnis apa pun yang me-render teks.

### 3.4 Controller `Lang` & Route

```php
class Lang extends CI_Controller {
    public function switch($code) {
        if (!in_array($code, ['en','id'], true)) { show_404(); return; }
        $this->session->set_userdata('site_lang', $code);
        $this->input->set_cookie([ /* name:'site_lang', value:$code,
            expire: 86400*30, httponly:TRUE, secure: otomatis transport */ ]);
        $referer = $this->input->server('HTTP_REFERER', TRUE);
        redirect(i18n_is_referer_same_host($referer) ? $referer : base_url());
    }
}
// routes.php
$route['lang/switch/(:any)'] = 'lang/switch/$1';
```

- **GET-only**, tanpa CSRF (CSRF CI3 hanya melindungi POST). Tanpa DB, tanpa
  rate limit, tanpa audit — aksi non-finansial, idempotent.
- Referer kosong/eksternal → `base_url()`. Setelah switch, redirect memicu
  request baru → constructor member/auth memuat idiom baru.
- Cookie 30 hari menjamin persistensi lintas restart browser (session 7200s
  tetap sumber utama selama sesi hidup).

### 3.5 UI Switcher (partial + html lang)

Partial baru `application/views/templates/lang_switcher.php` — segmented
control **tanpa emoji bendera** (render platform tidak konsisten); dua tombol
lingkaran 16 px berisi **SVG inline sederhana** (publik-domain, tanpa aset
jaringan):
- 🇮🇩 → SVG dua pita (merah-putih) — trivial.
- 🇬🇧 → Union Jack sederhana (3 palang) — path ringkas inline.
- Aktif = ring indigo; teks label `EN`/`ID` di samping flag kecil; `title`
  tooltip dari `lang('lang_switch_to')`.

Penempatan:
1. `templates/header.php` — di kanan atas, sebelum tombol theme (member
   logged-in).
2. `auth/login.php`, `auth/register.php` (dan `auth/change_password.php`
   sesuai layout aktual saat implementasi) — dekat brand/atas form.

`<html lang>`:
- `templates/header.php` (member) & tiap view auth: ganti literal `lang="id"`
  → `lang="<?= htmlspecialchars($site_lang_code ?? 'en') ?>"`.
- `admin/templates/header.php`: **tetap** `lang="id"` (L1).

### 3.6 Dictionary: Skema Key & Breakdown Scope Terjemahan

#### 3.6.1 Skema key

- Format file: `application/language/{english,indonesian}/app_lang.php`,
  array `$lang['key'] = 'teks';` — flat, `snake_case`, **prefix scope**:
  `{scope}_{kata}`. Tanpa titik, tanpa nesting (CI3 polos).
- Idiom **English = kanonik penulisan baru**; **Indonesian = pindahkan string
  hardcoded yang SUDAH ada** di view (kualitas tinggi, nol regresi makna).
- Parameterisasi: gunakan `sprintf` placeholder `%s`/`%d` di dalam teks dan
  `sprintf(lang('wallet_confirm_deposit'), $nominal)` di view — pemformatan
  uang tetap di PHP (L6). Contoh: `$lang['wallet_topup_confirm'] = 'Top up %s ke saldo Anda?';`
- Key JS murni ber-prefix `js_` dan di-inject sekali di member header:

```php
<script>window.SYNAPSE_I18N = <?= json_encode([
    'js_menit_lalu'   => lang('js_menit_lalu'),   // "%d menit lalu"
    'js_jam_lalu'     => lang('js_jam_lalu'),
    'js_hari_lalu'    => lang('js_hari_lalu'),
    // … hanya key yang dipakai JS header/bottom-nav
]) ?>;</script>
```

#### 3.6.2 Prefix & breakdown scope (estimasi key; final = hasil ekstraksi per view)

| Prefix | Scope (view) | Est. key | Contoh key |
|---|---|---|---|
| `common_` | lintas halaman (tombol, status, kosong) | ~25 | `common_save`, `common_cancel`, `common_confirm`, `common_empty`, `common_processing`, `common_back` |
| `nav_` | `templates/header.php`, `bottom_nav.php` | ~20 | `nav_home`, `nav_marketplace`, `nav_rentals`, `nav_team`, `nav_wallet`, `nav_profile`, `nav_notifications`, `nav_logout` |
| `notif_` | dropdown notifikasi header (statis) | ~8 | `notif_title`, `notif_mark_all_read`, `notif_empty`, `notif_see_all` |
| `lang_` | `lang_switcher.php` | ~3 | `lang_switch_to`, `lang_english`, `lang_indonesian` |
| `auth_` | `views/auth/*` (login/register/change_password) | ~35 | `auth_login_title`, `auth_phone`, `auth_password`, `auth_captcha_placeholder`, `auth_captcha_error`, `auth_register_btn`, `auth_no_account` |
| `home_` | `views/home/*` (Dashboard member) | ~30 | `home_balance`, `home_active_rentals`, `home_total_roi`, `home_cta_rent` |
| `market_` | `views/marketplace/*` | ~45 | `market_title`, `market_daily_roi`, `market_duration_days`, `market_buy_now`, `market_quota_reached` |
| `rental_` | `views/rentals/*` | ~55 | `rental_active`, `rental_completed`, `rental_claim_roi`, `rental_expires_at`, `rental_checkout_confirm` |
| `team_` | `views/team/*` | ~45 | `team_upline`, `team_downline`, `team_invite_code`, `team_copy`, `team_referral_gated`, `team_promoter_claim` |
| `wallet_` | `views/wallet/*` | ~65 | `wallet_balance`, `wallet_topup`, `wallet_withdraw`, `wallet_bind_bank`, `wallet_wd_window`, `wallet_history`, `wallet_confirm_*` |
| `profile_` | `views/profile/*` | ~30 | `profile_title`, `profile_avatar`, `profile_change_password`, `profile_language` |
| `js_` | string dinamis JS (header/bottom-nav/dsb.) | ~6 | `js_menit_lalu`, `js_jam_lalu`, `js_hari_lalu`, `js_theme_dark`, `js_theme_light` |
| **Total** | 7 scope member + chrome bersama | **~365** | — |

> Scope terjemahan **teks statis view** persis area yang disebut spek: Auth,
> Dashboard, Marketplace, Rentals, Team, Wallet, Profile — plus chrome bersama
> (`templates/*`) yang dipakai halaman-halaman tsb. `views/help/*` &
> `views/notification/*` di luar scope awal (catatan ekstensi).

#### 3.6.3 Batas terjemahan (trade-off terdokumentasi)

1. **`user_notifications.title/message`** (riwayat DB): tetap Indonesian —
   ditulis saat event oleh kode server; tidak ada kamus saat row dibuat.
2. **Flash messages**: pesan yang dipilih (auth/wallet/rental) boleh
   diterjemahkan dengan me-resolve `sprintf(lang(...))` **saat `set_flashdata`**
   (transien 1 request — tidak perlu re-terjemah).
3. **Nama produk** (`gpu_products.name`), nomor invoice, dll.: data, bukan
   label — tidak diterjemahkan.
4. **Admin**: tidak tersentuh (L1).
Ekstensi masa depan (di luar gelombang ini): kolom bahasa/`lang_key` pada
notifikasi, terjemahan Help/Notification page, label produk.

---

## 4. F2 — Admin Operational Alert Center

### 4.1 Alur Data

```
Browser admin (setiap halaman admin)
   │  SSR (A4): Admin::__construct → Admin_model::get_alert_counts()
   │           → load->vars('global_admin_alerts') → badge/bell terisi awal
   ▼
JS poller (footer admin) — tiap 25 dtk, skip saat document.hidden (A5)
   │  GET /admin/alerts/poll   (route → Admin::alerts_poll)
   ▼
Admin guard (admin_id session) → Admin_model::get_alert_counts()
   3 × SELECT COUNT(*) … WHERE status='pending'   (index (status,created_at))
   ▼
api_success($counts, …) → {success,message,data:{…}, pending_deposits:…, …}
   ▼
JS: update badge Deposit / Penarikan / Klaim Promoter + badge bell (total)
     total_urgent naik & !muted → chime Web Audio (A6)
```

### 4.2 Endpoint Poll

| Atribut | Nilai |
|---|---|
| URL | `GET /admin/alerts/poll` (route `$route['admin/alerts/poll'] = 'admin/alerts_poll';` — pattern method URL, perlu route eksplisit karena segment tambahan) |
| Guard | Constructor `Admin` (session `admin_id`, redirect `control-panel`) |
| Authz | Admin session saja — data sensitif operasional, tidak pernah publik |
| Method | `Admin::alerts_poll()` — load `Admin_model`, `get_alert_counts()`, `api_success(...)`; tambah `Cache-Control: no-store` |
| Respon | `api_success($counts, 'ok')` dengan `$legacy = $counts` sehingga BOTH `data.*` (kanonik M9) dan root `{pending_deposits, pending_withdrawals, pending_promoter_claims, total_urgent}` (kontrak spek + konsumen lama) tersedia |
| Frekuensi | 25 dtk (rentang spek 20–30), jitter kecil opsional; skip tab hidden |
| Logging | Tanpa audit, tanpa rate limit (admin authed, read-only, ~3 query index) |
| Sesi mati | Redirect 302 → login HTML → `fetch().json()` gagal → JS hentikan poll & `location.reload()` (kembali ke layar login) |

### 4.3 Efisiensi Query & Migrasi Index

Kondisi saat ini (dari `database.sql`):

| Tabel | Status | Index status terkini | COUNT pending hari ini |
|---|---|---|---|
| `deposits` | ENUM(pending,success,failed) | `idx_user_status(user_id,status)` — leading `user_id`, TIDAK melayani filter status saja | Full scan |
| `withdrawals` | ENUM(pending,processing,success,failed) | **tidak ada index status** (PK + `uk_wd_number`) | Full scan |
| `promoter_claims` | ENUM(pending,approved,rejected) | `idx_status_created(status,created_at)` ✅ | Index-saja |

Migrasi (F2 = 2 index baru; DDL kanonik `database.sql` + DB live **wajib
sinkron** — aturan AGENTS.md):

```sql
-- Jalankan SEKALI di DB live (MySQL 8 tidak punya CREATE INDEX IF NOT EXISTS;
-- dokumentasikan di plan sebagai one-time migration — database.sql memakai
-- CREATE TABLE IF NOT EXISTS sehingga idempotent untuk instalasi baru).
ALTER TABLE `deposits`
  ADD INDEX `idx_status_created` (`status`, `created_at`);
ALTER TABLE `withdrawals`
  ADD INDEX `idx_status_created` (`status`, `created_at`);
```

- `(status, created_at)` melayani **dua** kebutuhan: `COUNT(*) WHERE status=`
  (leading status → covering index-scan, sub-ms) dan listing queue dashboard
  `ORDER BY created_at ASC` (existing `Admin::index()`).
- `promoter_claims` sudah memadai; `count_promoter_claims('pending')` existing
  dipakai ulang.

`Admin_model::get_alert_counts()` (SQL di model — aturan baku):

```php
public function get_alert_counts() {
    $counts = [
        'pending_deposits'   => (int) $this->db->where('status','pending')->count_all_results('deposits'),
        'pending_withdrawals'=> (int) $this->db->where('status','pending')->count_all_results('withdrawals'),
        'pending_promoter_claims' => (int) $this->db->where('status','pending')->count_all_results('promoter_claims'),
    ];
    $counts['total_urgent'] = $counts['pending_deposits'] + $counts['pending_withdrawals'] + $counts['pending_promoter_claims'];
    return $counts;
}
```

### 4.4 UI Komponen Admin

**a) Sidebar (`admin/templates/sidebar.php`)** — badge merah dinamis:

```
Dashboard          → /admin                (existing)
Deposit  [3]       → /admin#pending-deposits      ← item BARU + badge
Penarikan [1]      → /admin#pending-withdrawals   ← item BARU + badge
… existing …
Klaim Promoter [5] → /admin/promoter-claims       ← badge ditambah
```

- Item Deposit/Penarikan = anchor link ke dashboard + hash; `id` container
  target ditambahkan di `admin/dashboard.php`: `id="pending-deposits"` pada
  div t-card "Pending Deposits" (kini baris 136) dan `id="pending-withdrawals"`
  pada t-card "Pending Withdrawals" (kini baris 178).
- Badge: `<span id="admin-badge-deposit" class="… hidden">` gaya rose
  (`bg-rose-500 text-white … rounded-full min-w-[18px]`), sembunyikan saat 0.
  Kontainer nav diberi `relative`.
- State active: Dashboard tetap `t-nav-active` (pola segmen existing); item
  Deposit/Penarikan ikut di-highlight saat `location.hash` cocok (JS kecil
  menambah kelas pada load — estetika, opsional).

**b) Topbar bell (`admin/templates/topbar.php`)** — meniru pola dropdown
notifikasi member (`templates/header.php`):
- Ikon `fa-bell` + badge total `admin-bell-badge` (rose, `99+` cap), kanan
  sebelum toggle theme.
- Dropdown `w-80` `t-card`: header "Antrean Menunggu", 3 baris ringkas
  (ikon + label + count `t-badge-danger` + chevron) masing-masing deep-link:
  - Deposit → `/admin#pending-deposits`
  - Penarikan → `/admin#pending-withdrawals`
  - Klaim Promoter → `/admin/promoter-claims`
- Footer dropdown: tombol **mute/unmute chime** (`fa-volume-high` ↔
  `fa-volume-xmark`), state `localStorage('admin_alerts_muted')`.

**c) Poll JS + chime (`admin/templates/footer.php`)** — modul tunggal
`window.AdminAlerts` (dekat blok theme toggle yang sudah ada):

```js
const POLL_MS = 25000;                 // rentang spek 20–30 dtk
let lastTotal = <?= (int)($global_admin_alerts['total_urgent'] ?? 0) ?>;
let inFlight = false, muted = localStorage.getItem('admin_alerts_muted') === '1';

async function pollAlerts() {
  if (inFlight || document.hidden) return;        // A5
  inFlight = true;
  try {
    const r = await fetch(siteUrl('admin/alerts/poll'), { headers: { 'Accept': 'application/json' } });
    if (!r.ok) throw new Error('http');
    const j = await r.json();
    applyAlerts(j.data);                            // fallback j.pending_* (root legacy)
    if (j.data.total_urgent > lastTotal && !muted && !document.hidden) chime(); // A6
    lastTotal = j.data.total_urgent;
  } catch (e) { /* retry senyap; jika 302 login → stop + reload */ }
  finally { inFlight = false; }
}
function applyAlerts(c) { /* set text/hide-show badge deposit/withdrawal/promoter + bell total */ }
function chime() { /* Web Audio: 2 nada 880→1174 Hz ~180 ms, volume rendah */ }
setInterval(pollAlerts, POLL_MS);
document.addEventListener('visibilitychange', () => { if (!document.hidden) pollAlerts(); });
// AudioContext dibuat/resume pada user gesture pertama (autoplay policy).
```

- **Chime tanpa aset**: sintesis 2 nada via `AudioContext` (oscillator +
  gain envelope) — nol file, nol CDN; hanya berbunyi saat `total_urgent`
  **naik vs state sebelumnya** (bukan tiap poll), sekali per kenaikan.
- Mute toggle di bell dropdown + persist `localStorage`; saat mute, badge
  tetap update, hanya suara dimatikan.
- SSR awal (A4): `Admin::__construct` memanggil `get_alert_counts()` dan
  `load->vars(['global_admin_alerts' => $counts])` → badge/bell tidak pernah
  mulai dari 0 kosong sebelum poll pertama. (Cost: 3 COUNT index-saja per
  pageview admin — dapat diterima; dashboard sudah jauh lebih berat.)

---

## 5. Spesifikasi Controller / Helper / Route (Ringkas)

**Baru:**
- `application/controllers/Lang.php` → `switch($code)` (§3.4)
- `application/helpers/i18n_helper.php` (§3.3)
- `application/language/english/app_lang.php`, `…/indonesian/app_lang.php`

**Ubah:**
- `routes.php`: `+ lang/switch/(:any)`, `+ admin/alerts/poll`
- `autoload.php`: helper `+ 'language'`
- `MY_Controller.php` & `Auth.php`: `+ i18n_apply()`
- `Admin.php`: constructor `+ global_admin_alerts`; `+ alerts_poll()`
- `Admin_model.php`: `+ get_alert_counts()`
- View member per scope (§3.6.2), `templates/header.php`, `bottom_nav.php`,
  view auth, partial `lang_switcher.php`
- `admin/templates/sidebar.php` (2 item + 3 badge), `topbar.php` (bell),
  `footer.php` (poller + chime), `admin/dashboard.php` (2 anchor `id`)
- `database.sql`: `+ idx_status_created` deposits & withdrawals

**Konvensi wajib:** `php -l` tiap file PHP baru/ubah; SQL hanya di model;
semua JSON lewat `api_helper`; mutator admin tetap POST-only (tak ada mutator
baru di fitur ini); commit message Indonesian; cabang per-phase roadmap.

---

## 6. Roadmap Implementasi (Step-by-Step)

1. **M1 — Kerangka engine bahasa**
   - Buat kedua `app_lang.php` (seed: `common_`, `nav_`, `notif_`, `lang_`,
     `js_`), `i18n_helper.php`, bootstrap `i18n_apply()` di `MY_Controller` &
     `Auth`, autoload `language`, `<html lang>` dinamis + `SYNAPSE_I18N`.
   - Verifikasi: seluruh halaman member & auth HTTP 200 (EN default);
     `php -l` semua file.

2. **M2 — Switcher & persistensi**
   - `Lang.php`, route, partial `lang_switcher.php`, integrasi header member +
     auth views, cookie 30 hari, referer same-host.
   - Verifikasi: switch → 302 ke referer; cookie `site_lang` tersimpan
     (expire 30 hari); refresh/restart browser bertahan; kode invalid → 404;
     referer eksternal → `base_url()`.

3. **M3 — Ekstraksi terjemahan per scope (batch view)**
   - Urutan: auth → templates → wallet → rentals → marketplace → home →
     team → profile. Tiap batch: string hardcoded → `lang()` + isi kedua
     dictionary; `js_*` untuk string JS.
   - Verifikasi per batch: render EN & ID (switch via cookie), tidak ada key
     mentah bocor; diff key kedua idiom = simetris (script pembanding);
     `php -l`.

4. **M4 — Fondasi Alert Center (server)**
   - Migrasi index (live + `database.sql`), `Admin_model::get_alert_counts()`,
     `Admin::alerts_poll()` + route, SSR `global_admin_alerts` di constructor.
   - Verifikasi: `curl` poll → JSON envelope + root keys; `EXPLAIN` ketiga
     COUNT memakai `idx_status_created` (type ref/index, tanpa full scan);
     tanpa session admin → redirect.

5. **M5 — UI Alert Center (SSR)**
   - Sidebar: item Deposit/Penarikan + badge (3), anchor `id` di dashboard,
     bell + dropdown + badge di topbar, mute toggle UI.
   - Verifikasi: DOM id `pending-deposits`/`pending-withdrawals` ada; badge
     SSR = counts nyata DB; deep-link scroll ke tabel.

6. **M6 — Polling JS + chime**
   - Poller 25 dtk di footer admin, `document.hidden` skip, in-flight guard,
     chime Web Audio, mute `localStorage`, stop-and-reload saat sesi mati.
   - Verifikasi: insert baris `pending` via SQL → poll berikutnya badge naik
     & chime sekali; mute persist setelah reload; tab hidden tidak memanggil
     endpoint (log/network); tanpa kenaikan → tanpa chime.

7. **M7 — Poles & Verifikasi penuh (matrix §7)**
   - Smoke `curl` seluruh route F1/F2; audit diffs; sync catatan AGENTS.md/
     ROADMAP bila perlu; commit final Indonesian.

---

## 7. Verification Matrix

| # | Fitur | Check | Metode | Expected |
|---|---|---|---|---|
| V1 | F1 | Semua file PHP baru/ubah lolos lint | `php -l <file>` | `No syntax errors` |
| V2 | F1 | Default visitor = EN | `curl` login tanpa cookie, inspeksi teks | Teks EN, `<html lang="en">` |
| V3 | F1 | Switch en→id persist session+cookie | `curl -c` `lang/switch/id` lalu fetch member page | 302 → referer; teks ID; `<html lang="id">`; cookie `site_lang=id` Max-Age 30 hari |
| V4 | F1 | Kode invalid | `GET lang/switch/fr` | 404 |
| V5 | F1 | Anti open-redirect | `lang/switch/en` dgn referer `http://evil.test/x` | Redirect ke `base_url()`, bukan evil |
| V6 | F1 | Paritas key dua idiom | script diff `array_keys` EN vs ID | Himpunan key identik (0 selisih) |
| V7 | F1 | Tidak ada key mentah bocor | render tiap halaman scope EN & ID, scan output | Tidak ada `$lang['…']`/key belum terdefinisi tampil |
| V8 | F1 | Uang & angka konsisten lintas bahasa | render halaman wallet EN vs ID | Format `Rp 1.000.000` identik |
| V9 | F1 | Admin tetap Indonesian | render 3 halaman admin setelah user pilih `id`/`en` | Teks admin tetap ID; admin tidak memuat app_lang (grep) |
| V10 | F2 | Endpoint poll | `curl -b <admin-session> /admin/alerts/poll` | JSON `{success:true, data:{…}}` + root keys; HTTP 200 |
| V11 | F2 | Efisiensi query | `EXPLAIN SELECT COUNT(*)…WHERE status='pending'` per tabel | `type=index`/`ref` via `idx_status_created`, `rows` kecil (bukan ALL) |
| V12 | F2 | Akses admin-only | poll tanpa session admin | Redirect `control-panel` (302) |
| V13 | F2 | Anchor deep-link | buka `/admin#pending-deposits`, `/admin#pending-withdrawals` | Scroll ke container tabel; `id` ada di DOM |
| V14 | F2 | Badge/bell live | insert pending baru via SQL sambil dashboard terbuka | Poll ≤25 dtk → badge + bell naik |
| V15 | F2 | Chime hanya saat naik & sekali | kenaikan 0→1 lalu 1→1 (tambah lalu settle) | Chime 1× (naik), 0× (turun/tetap); mute on → 0× |
| V16 | F2 | Mute persist | toggle mute, reload halaman | State mute tetap (localStorage) |
| V17 | F2 | Tab hidden hemat | buka tab lain ≥30 dtk, cek network | Tidak ada panggilan poll saat hidden; langsung poll saat visible |
| V18 | F2 | Sesuai pola API | grep endpoint baru | Hanya `api_success()`/`api_error()` — tanpa JSON hand-rolled |
| V19 | DDL | Index sinkron | `SHOW INDEX FROM deposits/withdrawals` vs `database.sql` | `idx_status_created(status,created_at)` ada di keduanya |
| V20 | Umum | Smoke routes | `curl` F1 & F2 routes (login/member/admin) | HTTP 200/302 sesuai alur; `system_audit_logs` tak bertambah oleh poll |

---

## 8. Non-Goals (eksplisit)

- Tidak ada terjemahan admin; tidak ada terjemahan konten DB (notifikasi/
  produk/flash historis) pada gelombang ini (L7, §3.6.3).
- Tidak ada WebSocket/SSE/push — polling 25 dtk per spek; endpoint COUNT
  sub-ms menjadikan polling aman.
- Tidak ada halaman queue baru admin (dec-c3f38a96e356cc7a).
- Tidak ada perubahan tabel/kolom — hanya 2 index tambahan (F2).
- Tidak ada aset audio/flag baru di repo — SVG inline & Web Audio sintesis.
