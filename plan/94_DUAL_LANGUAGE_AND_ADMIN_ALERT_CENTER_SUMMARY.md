# Plan 94 — Summary Eksekusi: Dual-Language Engine (Member) & Admin Real-Time Alert Center

> **Status:** IMPLEMENTED & VERIFIED (M1–M7, 100% per roadmap §6 Plan 94).
> Dokumen ini merangkum **apa yang telah dibuat/diubah** oleh eksekusi
> plan/94_DUAL_LANGUAGE_AND_ADMIN_ALERT_CENTER_PLAN.md — untuk review &
> analisis. Detail desain & invariant: baca dokumen PLAN.
> **Keputusan pengguna (dec-c3f38a96e356cc7a):** tanpa halaman queue admin
> baru; Deposit/Penarikan & bell deep-link → dashboard Command Center
> (`/admin#pending-deposits`, `/admin#pending-withdrawals`); Klaim Promoter →
> `/admin/promoter-claims`.

---

## 1. Ringkasan Eksekusi

| Fitur | Scope | Status |
|---|---|---|
| **F1 Dual-Language** | Member-facing ONLY: Auth, Dashboard/Home, Marketplace, Rentals, Team, Wallet, Profile + chrome bersama (`templates/*`). Admin panel **tetap 100% Indonesian** | Selesai |
| **F2 Alert Center** | Admin: badge sidebar, bell dropdown, polling 25 dtk, audio chime | Selesai |
| Dictionaries | `english` + `indonesian` — **317 key, parity simetris eksak** | Selesai |
| Skema DB | 2 index baru (`deposits`, `withdrawals`) — **tanpa perubahan tabel/kolom** | Selesai (DDL + migrasi live one-time) |

---

## 2. File Baru (5 + 2 direktori dictionary)

| File | Isi |
|---|---|
| `application/helpers/i18n_helper.php` | `i18n_idioms()` (peta `en→english`,`id→indonesian` satu-sumber), `i18n_default_code()`, `i18n_resolve()` (session → cookie → `en`), `i18n_idiom()`, `i18n_apply()` (muat TEPAT SATU idiom + inject var `site_lang_code`), `i18n_is_referer_same_host()` (anti open-redirect L8). Semua dibungkus `function_exists()` |
| `application/controllers/Lang.php` | `Lang extends CI_Controller` — `switch($code)`: validasi `{en,id}` (lain → 404), `session->set_userdata('site_lang')`, cookie `site_lang` 30 hari, redirect referer same-host / fallback `base_url()` |
| `application/views/templates/lang_switcher.php` | Partial segmented control EN|ID — **SVG bendera inline** (tanpa aset jaringan/emoji), tombol aktif ring indigo |
| `application/language/english/app_lang.php` | Kamus EN (idiom default/kanonik) — 317 key |
| `application/language/indonesian/app_lang.php` | Kamus ID (sekunder) — 317 key, himpunan key identik |
| `plan/94_DUAL_LANGUAGE_AND_ADMIN_ALERT_CENTER_PLAN.md` | Blueprint (disetujui sebelumnya) |

## 3. File Diubah (30)

**F1 — bootstrap & kamus:**
- `application/config/autoload.php` — helper `+ 'language'` (core `lang()`) & `+ 'i18n'`.
- `application/config/routes.php` — `+ lang/switch/(:any)`, `+ admin/alerts/poll`.
- `application/core/MY_Controller.php` — `i18n_apply()` setelah pin WIB (semua controller member login).
- `application/controllers/Auth.php` — `i18n_apply()` di constructor (pra-login & forced change-password).

**F1 — ekstraksi teks per scope (view → `lang()`) + `page_title` controller → `lang()`:**
- `views/templates/header.php` (switcher + `<html lang>` dinamis + dropdown notifikasi + waktu relatif `sprintf` + `window.SYNAPSE_I18N`), `views/templates/bottom_nav.php` (label nav).
- `views/auth/{login,register,change_password}.php` (switcher di area branding, `<html lang>`, label/placeholder/hint captcha, dll).
- `views/home/index.php`, `views/marketplace/index.php`, `views/rentals/index.php`, `views/team/index.php`, `views/profile/{index,change_password}.php`, `views/wallet/{index,withdraw,bank_bind}.php`.
- `controllers/{Home,Marketplace,Rentals,Team,Wallet,Profile}.php` — 9 nilai `page_title` → `lang()`.

**F2 — Alert Center:**
- `database.sql` — `idx_status_created(status, created_at)` pada `deposits` & `withdrawals` (CREATE TABLE + catatan migrasi live one-time: MySQL 8 `ALTER … ADD INDEX`; MariaDB `CREATE INDEX IF NOT EXISTS`).
- `application/models/Admin_model.php` — `get_alert_counts()` (3 × `COUNT(*) WHERE status='pending'` → index-scan + `total_urgent`).
- `application/controllers/Admin.php` — constructor: SSR `global_admin_alerts` (A4); method `alerts_poll()`: `api_success($counts,'ok',200,$counts)` (envelope `data.*` + root legacy keys) + `Cache-Control: no-store`.
- `views/admin/templates/sidebar.php` — item **Deposit** & **Penarikan** (deep-link anchor dashboard) + badge merah pada Deposit/Penarikan/Klaim Promoter (`admin-badge-*`).
- `views/admin/templates/topbar.php` — bell + badge total (`admin-bell-badge`) + dropdown 3 baris ringkas dengan count & deep-link (`admin-bell-*-count`) + tombol mute chime.
- `views/admin/templates/footer.php` — poller `window.AdminAlerts`: interval 25 dtk, in-flight guard, pause `document.hidden` + langsung poll saat visible, sinkronisasi badge/bell dari SSR & poll, **chime Web Audio 2 nada (880→1174 Hz)** hanya saat `total_urgent` NAIK, mute `localStorage('admin_alerts_muted')`, reload saat sesi mati (≥2 kegagalan beruntun).
- `views/admin/dashboard.php` — `id="pending-deposits"` & `id="pending-withdrawals"` + `scroll-mt-24` (target anchor).

---

## 4. Perilaku yang Diimplementasikan (verifikasi cepat)

### F1
- Resolusi bahasa: `session('site_lang')` → cookie `site_lang` (30 hari) → **`en` (default visitor baru)**.
- `GET /lang/switch/en|id` — tersedia pra-login; kode lain → 404; referer eksternal → `base_url()`.
- Teks JS dinamis lewat `SYNAPSE_I18N` (header) / dict `L`/`STR`/`I18N` per halaman (di-render PHP) — invariant L5.
- Uang & angka: TIDAK diterjemahkan — `Rp` + `number_format(id-ID)` tetap (L6).
- Batas scope (L7/§3.6.3 PLAN): riwayat `user_notifications`, nama produk, body `help/` & `notification/`, serta pesan flash/validation dari controller TIDAK diterjemahkan pada gelombang ini.

### F2
- Respons poll (contoh): `{success:true, message:"ok", data:{pending_deposits:3, pending_withdrawals:1, pending_promoter_claims:5, total_urgent:9}, pending_deposits:3, …}`.
- Deep-link: sidebar & bell → `/admin#pending-deposits`, `/admin#pending-withdrawals`, `/admin/promoter-claims`.
- Chime hanya saat kenaikan (bukan tiap poll); mute bertahan lintas reload.

---

## 5. Hasil Verifikasi (M7)

| Check | Hasil |
|---|---|
| `php -l` — 35 file PHP baru/ubah | **No syntax errors** (2 parse error di `views/wallet/*` — typo `':'` vs `'=>'` pada dict JS — ditemukan & diperbaiki selama sweep) |
| Paritas key EN vs ID (V6) | **True — 317 = 317**; `used(317) = en(317) = id(317)`; missing **[]**, orphans **[]** (termasuk 6 key dinamis `team_promo_reason_*`) |
| Scope diff | `git status` = hanya himpunan file Plan-94 (30 modified + 5 new + plan/94 doc) |
| Render HTTP penuh | **Belum dijalankan** (sandbox tanpa MySQL) — lihat checklist §6 |

---

## 6. Checklist Lanjutan (runtime — lingkungan dev/prod)

1. **Migrasi index live (sekali):** jalankan per catatan di bawah `database.sql`
   (MySQL 8: `ALTER TABLE deposits ADD INDEX idx_status_created (status, created_at);` dan sama untuk `withdrawals`; MariaDB: `CREATE INDEX IF NOT EXISTS …`).
2. Smoke `curl`: member page default = EN (`<html lang="en">`), `lang/switch/id` → 302 + cookie, kode invalid → 404, admin tetap ID.
3. `EXPLAIN SELECT COUNT(*)…WHERE status='pending'` per tabel → via `idx_status_created` (bukan full scan).
4. Uji badge/bell/chime di browser admin: insert baris pending → naik ≤25 dtk; chime 1× per kenaikan; mute persist setelah reload; tab hidden tidak memanggil endpoint.
5. `system_audit_logs` tidak boleh bertambah oleh polling (A1 read-only).

---

## 7. Catatan & Trade-off (untuk analisis)

- **M3 fokus view-layer** sesuai instruksi: pesan controller (flash/validation/notifikasi DB) tetap Indonesian pada gelombang ini — lihat §3.6.3 PLAN untuk opsi ekstensi (lang_key pada notifikasi, Help/Notification page, label produk).
- **`page_title`** member kini via `lang()` di controller — `<title>`/header turut berpindah bahasa; body `help/` & `notification/` sengaja di luar scope.
- **Index** `(status, created_at)` dipilih ganda-layanan: COUNT pending (leading status) **dan** listing Command Center `ORDER BY created_at ASC` yang sudah ada.
- Polling **25 dtk** + index-scan: overhead sub-ms per request; tanpa WebSocket/SSE (sesuai keputusan arsitektur).
- Bell topbar adalah satu-satunya permukaan alert di layar mobile (sidebar tersembunyi < lg) — disengaja.
