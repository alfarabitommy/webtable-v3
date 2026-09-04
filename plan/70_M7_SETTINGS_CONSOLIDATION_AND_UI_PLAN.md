# plan/70 — M7 SETTINGS CONSOLIDATION & ADMIN SETTINGS UI OVERHAUL (PLAN)

Status: **PLAN — approved, awaiting implementation go** (no application code / schema changed in this round)
Round: M7 (finding `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` §3-M7, `plan/66_AUDIT_GAP_ANALYSIS_SUMMARY.md` §2 row M7)

---

## 1. PROBLEM STATEMENT

Two key-value settings stores with divergent column shapes exist and are both live:

| Store | Column | Owners today | Read/write sites |
|---|---|---|---|
| `site_settings` | `setting_value` | `wa_number`, `support_email` | `Admin_model::get_all_settings()` / `update_settings()` (`Admin_model.php:75-92`); `Admin::settings()` (`Admin.php:271-319`); `Help::index()` (`Help.php:8-13`); view `admin/settings.php` |
| `system_settings` | `key_value` | `is_registration_open`, `wd_*` (M1 financial), `deposit_fee_*` | `Admin_model::get_setting()`/`set_setting()` (`Admin_model.php:673-687`); `Admin.php:85,359,365,1037-1042`; `Auth.php:174`; `Wallet_model.php:42-56` (financial config); views `admin/financial_settings.php`, `wallet/*` |

Drift is structural: any new setting author must pick a table, and the admin surface is fragmented — contact/support settings live on `/admin/settings` ("Pengaturan"), while operational hours + fees live on a second page `/admin/financial-settings` ("Aturan Finansial"), each a narrow single-column `max-w-lg` stack. Both forms use separate POST endpoints and separate audit actions; `admin/settings.php` renders only contact fields, so an admin cannot see financial state in one place.

Secondary UI defect (to fix in the same round): the fee-tier editor in `admin/financial_settings.php:99-116` overflows its card horizontally — a fixed-width flex row (`w-16` label, two `flex-1` inputs, `w-20` percent, `w-7` delete) cannot fit inside a `max-w-lg` card (`≈416px` content), so inputs/spinners spill past the card boundary.

## 2. TARGET ARCHITECTURE

- **One store:** `system_settings (key_name, key_value)` is the sole settings table. `wa_number` and `support_email` migrate into it; `site_settings` is dropped (DDL) and removed from `database.sql` + seed tool.
- **One model API:** `Admin_model` keeps the existing key-value pair `get_setting()/set_setting()` (consumers unchanged) and gains one batched transactional writer; the legacy `site_settings` pair `get_all_settings()/update_settings()` is deleted.
- **One endpoint + one view:** `/admin/settings` (route `admin/settings`, method `Admin::settings()`) renders a single unified form and handles all submissions. `/admin/financial-settings` (method `Admin::financial_settings()`) becomes a backward-compat shim that redirects to `/admin/settings`; the sidebar's redundant "Aturan Finansial" item is removed.
- **One save action:** a single `<form data-guard-submit="1">` posts contact + financial fields together, all-or-nothing validation, one in-transaction persist with one M5-style audit row carrying per-key `before → after` snapshots.

### 2.1 Non-goals / kept out of scope
- `is_registration_open` + `toggle_registration` (dashboard circuit breaker) stay on the dashboard — same table, unchanged (`Admin_model::get_setting/set_setting` untouched).
- All `wallet_ledger` money logic, `withdrawal_fees.php` fallback contract, and public wallet views are read-only consumers of `system_settings` and are **not** modified.
- `wa_number` value normalization (currently stored as international `628xx`) is preserved as-is; the `required|numeric` rule is kept.

## 3. DATABASE MIGRATION

### 3.1 Key migration map
| `site_settings.key_name` | `system_settings.key_name` (unchanged name) |
|---|---|
| `wa_number` | `wa_number` |
| `support_email` | `support_email` |

### 3.2 Steps (order matters)
1. **Code first (reads/writes switched to `system_settings`)** — deploy the model/controller/Help/view changes in §4–§6. From this point nothing in `application/` reads or writes `site_settings`.
2. **Data copy (idempotent, in a one-off script `scripts/migrate_m7_settings.php` or manual SQL):**
   ```sql
   INSERT IGNORE INTO `system_settings` (`key_name`, `key_value`, `updated_at`)
   SELECT `key_name`, `setting_value`, `updated_at` FROM `site_settings`;
   ```
   `INSERT IGNORE` never overwrites a live value and never fails on duplicate key (same convention as the `system_settings` seed, `database.sql:249`).
3. **Sanity check before drop:** row counts match for the two keys; `grep -rn "site_settings" application/ scripts/` returns nothing.
4. **Drop DDL (live DB):** `DROP TABLE `site_settings`;`
5. **Schema/seed files:**
   - `database.sql`: delete the `site_settings` block (comment `:127`, `CREATE TABLE` `:129-136`, `INSERT` `:138-140`); extend the `system_settings` `INSERT IGNORE` seed (`:250`) with `('wa_number', '628000000000'), ('support_email', 'support@synapse.id')` (keep the comment "Seed is idempotent...").
   - `scripts/seed_database.php`: remove any `site_settings` inserts; add `wa_number`/`support_email` to the `system_settings` insert list.

**Rollback:** restore `site_settings` from its DDL + pre-drop `INSERT ... SELECT` snapshot (keep a backup dump of the old table before step 4).

## 4. MODEL LAYER (`application/models/Admin_model.php`)

### 4.1 Add (replaces the legacy pair)
```php
// ===== SETTINGS (single store: system_settings) =====

/**
 * Baca peta key => value dari system_settings (satu-satunya store).
 * @param array $keys  Kosong = semua baris (SELECT key_name, key_value).
 */
public function get_settings_map(array $keys = []) {
    $this->db->select('key_name, key_value');
    if ($keys) { $this->db->where_in('key_name', $keys); }
    $map = [];
    foreach ($this->db->get('system_settings')->result() as $row) {
        $map[$row->key_name] = $row->key_value;
    }
    return $map;
}

/**
 * Persist batch dalam SATU transaksi + SATU audit row (pola M5/A1).
 * Controller menyiapkan $audit dengan before/after per key SEBELUM memanggil.
 */
public function update_system_settings(array $data, $audit = null) {
    $this->db->trans_start();
    foreach ($data as $key => $value) {
        $this->db->query(
            'INSERT INTO system_settings (key_name, key_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), updated_at = CURRENT_TIMESTAMP',
            [$key, $value]
        );
    }
    $this->_write_audit($audit);
    $this->db->trans_complete();
    return $this->db->trans_status();
}
```
Notes:
- Reuses the existing upsert statement shape of `set_setting()` (`Admin_model.php:682-687`) and the in-TX `_write_audit()` helper (`:22-34`) — identical guarantees to today's `update_settings()`/`financial_settings` flows (rollback removes the audit row too).
- `get_setting()`/`set_setting()` remain as-is for single-key consumers (`Auth`, `Admin` toggle, `Wallet_model`).

### 4.2 Remove
- `get_all_settings()` (`:75-82`) — site_settings reader.
- `update_settings()` (`:84-92`) — site_settings writer.

### 4.3 No change
- `Wallet_model::get_financial_config()` / `validate_financial_settings()` / fee calculators — already read/validate `system_settings` only.

## 5. CONTROLLER & ROUTING

### 5.1 `Admin::settings()` — single authoritative endpoint (rewrite)
GET render path:
- `$this->load->model('Admin_model'); $this->load->model('Wallet_model');`
- Contact: `$contact = $this->Admin_model->get_settings_map(['wa_number','support_email']);`
- Financial (effective values, dynamic → config fallback): `$cfg = $this->Wallet_model->get_financial_config();` → `days`, `open_time`, `close_time`, `fixed_fee`, `tiers`, `min_amount`, `max_amount`, `deposit_fee_*` (same derivations as `financial_settings()` today, `Admin.php:387-401`).

POST handler (only when `$this->input->method() === 'post'`):
1. **Contact rules:** `wa_number required|numeric`, `support_email required|valid_email` (unchanged, `Admin.php:275-276`); collect via `validation_errors()` if invalid.
2. **Financial rules:** build `$raw` from the financial field names only and run `Wallet_model::validate_financial_settings($raw)` (unchanged contract, `Admin.php:335-348`); collect its `errors`.
3. **All-or-nothing:** if any error from (1) or (2) → single `set_flashdata('error', ...)` and `redirect('admin/settings')`; nothing persisted (today each page could persist its own half).
4. **M5/A1 before-snapshot:** union of contact keys + `array_keys($v['values'])`; `$before[$key] = $this->Admin_model->get_setting($key);` before persist.
5. **Persist + audit in one TX:** build `$audit_ctx = $this->_audit_ctx(null, 'admin_update_settings', ['section' => ..., 'keys' => [...], 'before' => $before, 'after' => $data])` where `$data` = contact values + validated financial values, `after` filtered to keys whose `before` differs (per modified key); call `$this->Admin_model->update_system_settings($data, $audit_ctx);` then flash `success`/`error` on `trans_status()` and `redirect('admin/settings')`.
6. View data as in GET + flash + `page_title = 'Pengaturan'`.

**POST-only gate with 404:** methods other than GET/POST on this endpoint → `show_404();` (GET renders the form, POST processes; PUT/DELETE/etc. are rejected with CI's 404). CSRF stays on via `form_open()` (auto hidden token).

### 5.2 `Admin::financial_settings()` — backward-compat shim
```php
public function financial_settings() {
    // M7 (plan/70): endpoint disatukan ke /admin/settings.
    redirect('admin/settings');
}
```
- Applies to both GET and POST — old bookmarks / stale form URLs land on the unified page. Trade-off (accepted): an in-flight POST to the old URL is not replayed (loses its payload); acceptable because the only in-app link was the sidebar item being deleted.
- Route `$route['admin/financial-settings'] = 'admin/financial_settings';` (`routes.php:33`) is **kept** so the shim is reachable. Sidebar no longer references it (see §6).

### 5.3 `Help::index()` (`Help.php:8-13`)
Replace `$settings = $this->Admin_model->get_all_settings();` with
`$wa = $this->Admin_model->get_setting('wa_number') ?: '628000000000'; $email = $this->Admin_model->get_setting('support_email') ?: 'support@synapse.id';`
(keeps current fallback semantics; `views/help/index.php` unchanged).

## 6. NAVIGATION CONSOLIDATION (`admin/templates/sidebar.php`)

- **Remove** the "Aturan Finansial" `<a>` block (`sidebar.php:48-52`, `segment(2) === 'financial-settings'`).
- **Keep** the single "Pengaturan" item (`:43-47`) pointing to `site_url('admin/settings')`, active when `segment(2) === 'settings'`.

## 7. UI/UX — UNIFIED 2-COLUMN SETTINGS VIEW (rewrite of `admin/settings.php`; delete `admin/financial_settings.php`)

### 7.1 Layout structure
- Page wrapper: `px-4 md:px-8 py-6`, single `<form id="settingsForm" data-guard-submit="1">` (see §9) wrapping the whole grid.
- Container: `max-w-7xl mx-auto` (replaces `max-w-lg`).
- Grid: `grid grid-cols-1 xl:grid-cols-2 gap-6 items-start` — **left column** `space-y-6`, **right column** `space-y-6`. On `<xl` the columns stack naturally (mobile-first preserved).
- Full-width page header above the grid ("Pengaturan" + one-line description covering both contact and financial scope), full-width flash blocks, and a **single submit bar below the grid** (right-aligned, `pt-6 border-t`).

### 7.2 Wireframe
```
┌─────────────────────── main px-4 md:px-8 py-6 ─────────────────────────┐
│ <form id="settingsForm" action="/admin/settings" method="post"          │
│       data-guard-submit="1">  …csrf hidden…                             │
│  ┌─────────────── max-w-7xl mx-auto ────────────────────────────────┐   │
│  │ [flash success/error]                                            │   │
│  │ Header: "Pengaturan" — Kelola info support & aturan finansial    │   │
│  │ grid grid-cols-1 xl:grid-cols-2 gap-6 items-start                │   │
│  │ ┌─ COL A (space-y-6) ──────────────┐ ┌─ COL B (space-y-6) ────┐ │   │
│  │ │ Card 1 — General & Support       │ │ Card 3 — Biaya WD+Tier │ │   │
│  │ │   📱 wa_number (WhatsApp CS)     │ │   fixed/min/max inputs │ │   │
│  │ │   ✉  support_email               │ │   tier rows (grid)     │ │   │
│  │ │ Card 2 — Jam Operasional & Hari  │ │   [Tambah baris tier]  │ │   │
│  │ │   ☑ hari aktif (Sen–Min)         │ │ Card 4 — Biaya Deposit │ │   │
│  │ │   🕐 jam buka / jam tutup        │ │   enabled + type+nilai │ │   │
│  │ └──────────────────────────────────┘ └─────────────────────────┘ │   │
│  │ Submit bar (border-t, right): [💾 Simpan Pengaturan] (+spinner)  │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│ </form>                                                                  │
└──────────────────────────────────────────────────────────────────────────┘
```

### 7.3 Card inventory (all `t-card p-6`, labels = existing `t-label` tokens, theme-aware)
| Card | Fields | Source today |
|---|---|---|
| 1. General & Support | `wa_number`, `support_email` (inputs as in `settings.php:29-62`) | `admin/settings.php` |
| 2. Jam Operasional & Hari Aktif | day checkboxes `wd_operational_days[]` (2-col grid inside card; card is now wide so 4+4 or 7-across acceptable), `wd_open_time`, `wd_close_time` | `financial_settings.php:38-69` |
| 3. Biaya Penarikan & Tier | `wd_fixed_fee`, `wd_min_amount`, `wd_max_amount` (3-col grid), tier editor rows + `#tierAdd` + hidden `wd_fee_tiers` + `#tierStatus` | `financial_settings.php:71-125` |
| 4. Biaya Deposit | `deposit_fee_enabled`, `deposit_fee_type`, `deposit_fee_value` + JS suffix/step sync | `financial_settings.php:127-161` |

### 7.4 Fee-tier row overflow — root cause & fix
**Root cause:** each row is `flex items-center gap-2` with fixed/label widths that cannot shrink — `w-16 shrink-0` label, two `flex-1` inputs (intrinsic `min-width` from `type=number` spinners), `w-20` percent input, `w-7` delete, plus inter-element text ("s/d", "%") — inside a `max-w-lg` card (`≈416px` content). Sum of fixed elements + minimum input widths exceeds the row width, so children overflow the card boundary (no wrapping possible in a nowrap flex row).

**Fix (implement in both the PHP render loop and the JS `addRow()` template — one shared structure, keep all classes/ids/behavior):**
1. Remove the fixed-width flex row. Each tier row becomes a **label-above-input responsive grid**:
```html
<!-- PHP loop & addRow() must produce identical markup -->
<div class="tier-row rounded-lg border border-slate-200 dark:border-slate-700 p-3
            grid grid-cols-2 sm:grid-cols-12 gap-2 sm:gap-3 items-end">
  <div class="col-span-1 sm:col-span-4 min-w-0">
    <label class="block text-[11px] text-[var(--t-muted)] mb-1">Min (IDR)</label>
    <input type="number" class="tier-min t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono"
           min="0" step="1" value="<?= (int) $tier[0] ?>">
  </div>
  <div class="col-span-1 sm:col-span-4 min-w-0">
    <label class="block text-[11px] text-[var(--t-muted)] mb-1">Maks (IDR)</label>
    <input type="number" class="tier-max t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono"
           min="0" step="1" value="<?= (int) $tier[1] ?>">
  </div>
  <div class="col-span-1 sm:col-span-3 min-w-0">
    <label class="block text-[11px] text-[var(--t-muted)] mb-1">Persen (%)</label>
    <input type="number" class="tier-pct t-input w-full min-w-0 px-2 py-2 rounded-lg text-xs font-mono"
           min="0" max="100" step="0.01"
           value="<?= htmlspecialchars(rtrim(rtrim(number_format($tier[2] / 100, 2, '.', ''), '0'), '.')) ?>">
  </div>
  <div class="col-span-2 sm:col-span-1 flex justify-end items-end">
    <button type="button" class="tier-del w-8 h-8 rounded-lg text-xs text-red-500
                                hover:bg-red-500/10 shrink-0" title="Hapus baris">&times;</button>
  </div>
</div>
```
2. Rules that guarantee containment: **no fixed element widths** (`w-16`/`w-20` removed), all inputs `w-full min-w-0`, column spans define the grid (grid items never overflow; on mobile 2-col wraps to 3 stacked blocks + delete), delete button becomes its own grid cell (`justify-end`) instead of a squeezed inline element.
3. Keep every JS contract intact: classes `.tier-row/.tier-min/.tier-max/.tier-pct/.tier-del`, ids `tierRows/wd_fee_tiers/tierStatus/tierAdd`, `serializeTiers()` logic, half-open `[min,max)` contiguity validation, and the pre-submit `serializeTiers()` gate.
4. Optional safety net: `overflow-x: clip` on the Card 3 container (never hides focus ring because rows now fit); verify at `375px` and `xl` widths that no horizontal scrollbar appears on the page.

## 8. AUDIT LOGGING & SECURITY PARITY

- **Audit (M5/A1 standard):** one audit row per save, written **inside** the persist transaction (`update_system_settings` TX so rollback removes it), via `_write_audit`/`Audit_model::log_admin_action(admin_id, null, 'admin_update_settings', {section, keys, before:{key:old}, after:{key:new}}, ip)` — `before`/`after` snapshots **per modified setting key** exactly like `Admin.php:356-374` today. (Two source pages → one action now; no change to `system_audit_logs` schema.)
- **POST-only gate:** submission path executes only on `method() === 'post'`; other methods → `show_404()` (`Admin::financial_settings` shim redirects for both GET/POST).
- **Validation all-or-nothing:** contact `form_validation` + `Wallet_model::validate_financial_settings` must both pass before any write; financial values pass through the existing strict normalizer (digit-only/regex/JSON decode) with raw POST (no XSS filter) — output always `htmlspecialchars`-escaped in the view.
- **No new SQL outside models**; all statements bound params.
- CSRF token preserved by `form_open()`; guard behavior preserved by `data-guard-submit` (§9).

## 9. FORM & GUARD INTEGRATION

- The unified view keeps a **single form** (`form_open('admin/settings', ['id'=>'settingsForm','data-guard-submit'=>'1'])`) — tabbed submission is NOT needed since two-column layout keeps both groups visible; a single submit saves contact + financial together.
- The guard attribute is honored by the existing M4 guard script (user shell: `views/templates/csrf_meta.php`; admin pages already use `data-guard-submit="1"` on dashboard action forms). **Implementation step:** confirm the guard/loader script is loaded by the admin shell (`admin/templates/header.php` / `topbar.php`); if absent, add the small inline loader to the settings view footer (below), not a new global.
- Spinner/disable on submit (expected M4 behavior — disabled button + `fa-spinner fa-spin` + label "Menyimpan…" while `#settingsForm` submits; page redirects on completion so no restore path needed):
```js
document.getElementById('settingsForm').addEventListener('submit', function () {
    var b = this.querySelector('button[type="submit"]');
    if (b) { b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan…'; }
});
```
- Financial-tier pre-submit `serializeTiers()` gate (submit listener `e.preventDefault()` when tiers invalid) must be **chained before** the guard submission, not replacing it — combine both listeners on `settingsForm`.

## 10. VERIFICATION CHECKLIST

1. `php -l` on every modified PHP file.
2. `grep -rn "site_settings\|get_all_settings\|update_settings(" application/ scripts/` → **no hits** after cleanup (only the migration script's comment may mention the legacy name).
3. Browser (admin): GET `/admin/settings` renders 2-column grid; all four cards visible; contact values pre-filled from `system_settings`.
4. Save contact-only, financial-only, and mixed changes → each persists, flash success, audit row shows per-key `before`/`after`.
5. `curl -I admin/financial-settings` → 302 to `/admin/settings`; sidebar shows exactly one "Pengaturan" item.
6. Fee tiers: at 375px and 1280px no horizontal overflow/truncation; add/delete row + invalid-tier client checks still work; hidden JSON matches server-side validation.
7. Live-DB migration dry-run on staging: `INSERT IGNORE ... SELECT` then `DROP TABLE site_settings` only after grep audit is clean; `database.sql` re-seed produces identical keys in `system_settings`.
8. Regression: register-gate (`is_registration_open`), wallet deposit-fee display, WD operational window, dashboard toggle, Help page `wa.me`/`mailto` links all still read correct values.

## 11. FILES TOUCHED (planned)

| File | Change |
|---|---|
| `application/models/Admin_model.php` | add `get_settings_map()`, `update_system_settings()`; delete `get_all_settings()`, `update_settings()` |
| `application/controllers/Admin.php` | rewrite `settings()` (GET render + unified POST); `financial_settings()` → redirect shim; keep `_audit_ctx`/helpers |
| `application/controllers/Help.php` | read contact keys via `get_setting()` (fallbacks kept) |
| `application/views/admin/settings.php` | full rewrite → unified 2-column form (cards §7.3, overflow fix §7.4, guard §9) |
| `application/views/admin/financial_settings.php` | **delete** |
| `application/views/admin/templates/sidebar.php` | remove "Aturan Finansial" item |
| `application/config/routes.php` | keep `admin/financial-settings` → shim (no other route change) |
| `database.sql` | drop `site_settings` block; extend `system_settings` seed with `wa_number`/`support_email` |
| `scripts/seed_database.php` | remove `site_settings` inserts; add contact keys to `system_settings` seed |
| `scripts/migrate_m7_settings.php` (new, one-off) | idempotent data copy (`INSERT IGNORE ... SELECT`) + drop helper/comments |

## 12. ROLLBACK

- App: revert controllers/models/views; keep legacy methods only if deploy order required it (preferred: single atomic deploy, so simply `git revert`).
- DB: restore `site_settings` snapshot taken before step 4 (`DROP`) — data copy is lossless (only two keys, same names), so reverse copy `INSERT IGNORE INTO site_settings SELECT ... FROM system_settings WHERE key_name IN ('wa_number','support_email')` is also sufficient if the table DDL still exists.

---
*End of plan/70. Read-only analysis; no application code or schema changed in this round.*
