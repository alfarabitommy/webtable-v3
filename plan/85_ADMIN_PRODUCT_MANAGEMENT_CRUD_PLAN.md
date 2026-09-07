# 85 — ADMIN GPU PRODUCT MANAGEMENT CRUD (BLUEPRINT)

**Scope (Phase 2 — blueprint only):** Admin Panel UI + backend controller/model for managing
GPU rental packages (`gpu_products`) — list all packages (active + inactive), create, edit,
and quick-toggle `is_active`, entirely from the admin UI (no manual DB intervention).
**Mode:** DOCUMENTATION-ONLY round. This file is the approved architectural blueprint.
**Execution hold (user instruction):** NO source code, route, model, controller, view, or
database change is executed from this round — implementation starts only after explicit
further approval from the user.
**Target files for the future implementation round (not touched here):**
`application/config/routes.php`, `application/controllers/Admin.php`,
`application/models/Admin_model.php`, `application/views/admin/products/index.php` (new),
`application/views/admin/templates/sidebar.php`.
**Schema:** NO DDL change (`gpu_products` already carries `max_per_user` /
`unlock_prerequisite_id` / `idx_unlock_prerequisite` / `fk_gpu_products_unlock_prereq` from
plan/83; `system_audit_logs` already records `admin_id`, `user_id` (NULLable), `action`,
`details` (TEXT JSON), `ip_address`).

---

## 1. EXECUTIVE SUMMARY

The admin panel today has no product management surface — packages are only editable by
hand-writing SQL. This phase adds a full CRUD (minus hard-delete) surface under
`/admin/products`, following the repo's hardened admin conventions:

- **Every mutator is POST-only, fail-closed** (`$this->input->method() !== 'post'` →
  `show_404()`), CSRF-protected by the global `csrf_protection = TRUE` + `form_open()`
  tokens (M4/plan 62 H1 pattern).
- **Every mutation is atomic with its audit entry** — controller-level
  `trans_start()`/`trans_complete()` wrapping the `Admin_model` write +
  `Audit_model::log_admin_action()` with a JSON `{before, after}` snapshot (M5 pattern,
  identical to `Admin::update_user` / `Admin::toggle_ban`).
- **Zero hard-delete policy** — no delete route/method/button exists or is added; both
  FKs (`fk_user_rentals_product`, `fk_gpu_products_unlock_prereq`) are `ON DELETE RESTRICT`
  and packages are only ever soft-disabled via `is_active = 0` (marketplace/gating queries
  already filter `is_active = 1`).
- **Validation follows plan/83 + M8 discipline**: integer-IDR regex inputs, prerequisite
  existence/self/cycle prevention (mirrors the `has_ancestor` upline-cycle walk), purchase
  limit ≥ 0, duration ≥ 1.
- UI uses the admin `t-*` design-token system (dark/light adaptive), mirrors
  `views/admin/users.php` (table + modal + inline confirm toggle forms).

---

## 2. ROUTE & CONTROLLER ARCHITECTURE

### 2.1 `routes.php` additions (REQUIRED, not cosmetic)

CI3 maps `/admin/products/create` to controller `admin`, method `products` with arg
`create` — the pretty mutation URLs therefore need explicit routes (precedent:
`$route['admin/financial-settings']`). Add near the existing admin block:

```php
// plan/85: Admin GPU product management (CRUD, no hard delete)
$route['admin/products']                    = 'admin/products';
$route['admin/products/create']             = 'admin/create_product';
$route['admin/products/update/(:num)']      = 'admin/update_product/$1';
$route['admin/products/toggle_status/(:num)'] = 'admin/toggle_product_status/$1';
```

| URL | Method | Controller |
|---|---|---|
| GET `/admin/products` | `Admin::products()` | render catalog table |
| POST `/admin/products/create` | `Admin::create_product()` | validate + insert + audit |
| POST `/admin/products/update/{id}` | `Admin::update_product($id)` | validate + update + audit |
| POST `/admin/products/toggle_status/{id}` | `Admin::toggle_product_status($id)` | flip `is_active` + audit |

`Admin.php` extends `CI_Controller`; its constructor already loads DB/session/api helper and
redirects to `control-panel` when `admin_id` is missing — every new method inherits the
strict admin-session guard. No CSRF whitelist entry needed: all forms use `form_open()`.

### 2.2 Controller workflows (all three mutators)

Shared skeleton (mirrors `toggle_ban` / `update_user`):

```
1. POST-only guard        → method() !== 'post' ? show_404() + return
2. Load Admin_model (+ Audit_model only when mutating)
3. (update/toggle) fetch BEFORE snapshot via Admin_model::get_product_row($id)
       → missing row → flashdata error 'Paket tidak ditemukan.' + redirect admin/products
4. Validate inputs (pure reads; §4) — any failure → flashdata error + redirect admin/products
5. trans_start()
   Admin_model::<write>($id, $fields)          // guarded UPDATE / INSERT
   Audit_model::log_admin_action(admin_id, NULL, '<action>', details, ip_address)
6. trans_complete()
   trans_status() ? flashdata success : flashdata error
7. redirect('admin/products')
```

**`Admin::products()`** (GET render):
- `$products = $this->Admin_model->get_products_admin();` (all rows incl. inactive, w/
  prereq name + usage counters — §3.1)
- `$prereq_options = $this->Admin_model->get_all_products_lite();` (id/name for the
  create/edit prerequisite dropdowns)
- Loads `admin/templates/header` + `sidebar` + `topbar` + `admin/products/index` +
  `footer`, `page_title = 'Manajemen Produk GPU'`.

**`Admin::create_product()`** (POST): no BEFORE snapshot → audit `details.before = null`;
after insert the model returns the new `id` which is embedded in `details.after` + echoed in
the success flash. `is_active` accepted from the create form (default `1`).

**`Admin::update_product($id)`** (POST): edits business fields only — `is_active` is NOT an
editable field here (owned by the toggle endpoint, §2.3); before/after payloads are
symmetric over the editable columns (§5.2).

**`Admin::toggle_product_status($id)`** (POST): computes `new = 1 − (int) is_active`,
persists, audits `{before:{is_active}, after:{is_active}}`, flashes
`'Paket "X" berhasil diaktifkan.'` / `'Paket "X" berhasil dinonaktifkan.'`.

---

## 3. MODEL ARCHITECTURE (`Admin_model.php`)

All SQL stays in the model (repo rule). Methods return **objects** (`->result()` style,
consistent with `get_active_products()`), with counters and policy fields **strict `(int)`
casts** before return (M8).

### 3.1 `get_products_admin()` — catalog rows + prereq name + live usage

```php
/**
 * Semua paket (aktif & nonaktif) + nama prasyarat + pemakaian live:
 *   active_cnt  = kontrak user_rentals status 'active'      (sedang berjalan)
 *   total_cnt   = status IN ('active','completed')           (kuota lifetime terpakai)
 * Semua counter & max_per_user di-(int) kan sebelum return (M8).
 * @return array  objects: gpu_products.*, prerequisite_name|null,
 *                active_cnt:int, total_cnt:int
 */
public function get_products_admin() {
    $rows = $this->db->query(
        "SELECT p.*,
                pr.name AS prerequisite_name,
                COALESCE(ag.active_cnt, 0) AS active_cnt,
                COALESCE(ag.total_cnt, 0)  AS total_cnt
           FROM gpu_products p
           LEFT JOIN gpu_products pr ON pr.id = p.unlock_prerequisite_id
           LEFT JOIN (
                SELECT product_id,
                       SUM(status = 'active')                AS active_cnt,
                       SUM(status IN ('active','completed')) AS total_cnt
                  FROM user_rentals
                 GROUP BY product_id
           ) ag ON ag.product_id = p.id
          ORDER BY p.id ASC"
    )->result();

    foreach ($rows as $r) {
        $r->active_cnt = (int) $r->active_cnt;
        $r->total_cnt  = (int) $r->total_cnt;
        $r->max_per_user = (int) $r->max_per_user;
        $r->duration_days = (int) $r->duration_days;
    }
    return $rows;
}
```

Notes: counters use integer boolean `SUM` on a `user_rentals` aggregate — count math only,
no float; single grouped subquery keeps it one round-trip; the existing
`idx_user_status_expired`/`idx_product_id` serve the join.

### 3.2 Read helpers

| Signature | Purpose |
|---|---|
| `get_product_row($id) : ?object` | Single product by id (for BEFORE snapshot + existence). Returns `null` when absent. |
| `get_all_products_lite() : array` | `id, name, is_active` ordered by `id` — prerequisite `<select>` options (create uses all; edit view filters out the row being edited). |
| `is_product_name_taken($name, $ignore_id = null) : bool` | Case-insensitive name uniqueness (guards plan/83's name-matched seeding & duplicate lineup rows). |
| `prereq_would_cycle($product_id, $new_prereq_id) : bool` | Cycle/self detection — §3.4. |

### 3.3 Write helpers (called inside the controller TX)

| Signature | Behavior |
|---|---|
| `create_product(array $data) : int\|false` | `INSERT` explicit column list; returns `insert_id` or `false`. New row starts `is_active` per form (default 1). |
| `update_product($id, array $fields) : bool` | Guarded `UPDATE … WHERE id = ?`; `false` only on SQL error or missing row (existence pre-checked in controller anyway). |
| `set_product_active($id, int $is_active) : bool` | Guarded `UPDATE is_active = ?` (0/1), `affected_rows() === 1` semantics relaxed to row-exists (toggle to same value is a no-op success). |

Every method re-casts `(int)` on id/amount-like fields and binds all params — defense in
depth on top of controller regex validation.

### 3.4 Prerequisite cycle-prevention algorithm

Rejects **self-reference** and **circular chains** (A→B while B→A, or any loop). Bounded
walk with a depth cap = total row count (fail-closed on runaway — returns `true`):

```php
public function prereq_would_cycle($product_id, $new_prereq_id) {
    $product_id = (int) $product_id;
    $cursor     = (int) $new_prereq_id;
    $depth_cap  = (int) $this->db->count_all('gpu_products'); // >= 1 row always

    for ($i = 0; $i <= $depth_cap; $i++) {
        if ($cursor === $product_id) {
            return true; // tercapai diri sendiri → siklus (termasuk self-prereq)
        }
        $row = $this->db->select('unlock_prerequisite_id')
            ->where('id', $cursor)->get('gpu_products')->row();
        if (!$row || $row->unlock_prerequisite_id === null) {
            return false; // ujung rantai sah (NULL = tanpa prasyarat)
        }
        $cursor = (int) $row->unlock_prerequisite_id;
    }
    return true; // depth terlampaui → fail-closed: anggap siklus
}
```

Invariant guaranteed for the canonical lineup: chain 4→5→6→7→8 remains acyclic; setting
id 8's prereq to 4 again, or id 5's prereq to 7, is rejected.

---

## 4. VALIDATION RULES (controller; model re-checks by casting)

All raw posts captured with `$this->input->post(key, TRUE)`. Failure → one aggregated
Indonesian flash (`implode('<br>', $errors)`) + redirect back to `admin/products`
(no partial writes; validation happens before `trans_start`).

| Field | Rule (regex / set) | Error (Indonesian, representative) |
|---|---|---|
| `name` | `trim`, required, ≤ 100 chars, unique (excl. self on edit) | `Nama paket wajib diisi (maks. 100 karakter) dan tidak boleh duplikat.` |
| `type` | `in_array(['short_term','long_term'])` | `Tipe paket tidak valid.` |
| `price` | M8 `^[1-9][0-9]*$` (positive int, no float/exp) | `Harga sewa harus bilangan bulat positif (IDR).` |
| `daily_rate` | M8 `^[1-9][0-9]*$` | `ROI harian harus bilangan bulat positif (IDR).` |
| `duration_days` | `^[1-9][0-9]*$` (≥ 1) | `Durasi kontrak minimal 1 hari.` |
| `max_per_user` | `^(0|[1-9][0-9]*)$` (0 = unlimited) | `Batas sewa per user harus ≥ 0 (0 = tanpa batas).` |
| `is_refundable` | checkbox → `0`/`1` | — |
| `is_active` | create-only select → `0`/`1` (default `1`) | — |
| `unlock_prerequisite_id` | `''` → `NULL`; else numeric id that **exists**, `!= id`, and `!prereq_would_cycle(id, cand)` | `Prasyarat tidak ditemukan.` / `Paket tidak bisa menjadi prasyarat dirinya sendiri.` / `Prasyarat tidak valid — akan membentuk siklus terkunci.` |

No DELETE field, endpoint, or button exists anywhere in this feature (Zero Hard-Delete
Policy). `price`/`daily_rate` are persisted into the existing `DECIMAL(15,2)` columns as
integer strings; the integer-IDR invariant (M8) is untouched because every consumer already
`(int)`-casts these values (plan/74 §2) and the new code never introduces float arithmetic.

---

## 5. TRANSACTION ORDER & AUDIT (M5)

### 5.1 Mutator TX shape

```
trans_start()
  ├─ Admin_model write (INSERT/UPDATE/SET is_active)      — bound params, (int) casts
  └─ Audit_model::log_admin_action(
         (int) admin_id from session,
         NULL,                                  // user_id — aksi produk, tanpa user
         'admin_create_product' | 'admin_update_product' | 'admin_toggle_product_status',
         ['product_id' => id, 'before' => …, 'after' => …],
         $this->input->ip_address())
trans_complete()
if (!trans_status()) → flashdata error 'Gagal menyimpan perubahan paket.' 
```

`Audit_model::log_admin_action()` JSON-encodes `details` with `JSON_UNESCAPED_UNICODE`
(existing behavior). Because both rows commit/rollback together, a failed write can never
leave an orphan audit entry (and vice-versa) — M5 compliance. `user_id` stays `NULL`
(column is nullable); the audit log page's existing `get_action_options()` picks up the new
actions automatically (distinct action list — no extra change needed).

### 5.2 JSON snapshot payloads (field order symmetric; all values int/string/null)

- **create:** `{ "product_id": N, "before": null, "after": { "name", "type", "price",
  "daily_rate", "duration_days", "is_refundable", "max_per_user",
  "unlock_prerequisite_id", "is_active" } }`
- **update:** same shape with `"before"` = row snapshot read in step 3 (§2.2) and `"after"` =
  validated form values (+ unchanged `is_active` mirrored from before for symmetry).
- **toggle:** `{ "product_id": N, "name": "…", "before": { "is_active": x },
  "after": { "is_active": 1−x } }`

---

## 6. ADMIN UI/UX (`application/views/admin/products/index.php`)

New view folder per spec (`admin/products/`), rendered with the standard admin chrome
(`templates/header|sidebar|topbar|footer`). Styling uses the existing admin `t-*` design
tokens (`t-card`, `t-badge t-badge-success|danger`, `t-th`, `t-row-hover`, `t-flash-*`,
`t-input`, `t-btn-ghost`, `text-[var(--t-text)]`, `bg-[var(--t-surface-2)]`,
`border-[var(--t-border)]`, …) so dark/light adaptivity comes for free — same language as
`views/admin/users.php`.

### 6.1 Page structure

- **Flash blocks** (`t-flash-success` / `t-flash-error`) at top — identical to `users.php`.
- **Header row**: package count summary + `+ Tambah Paket GPU` button (opens create modal).
- **Responsive table** (`.t-card` + `overflow-x-auto`, `<table class="w-full text-sm">`):

| Column | Content |
|---|---|
| ID | `font-mono t-text-2` |
| Nama & Type | name + `t-badge` chip `short_term`/`long_term` (indigo / slate) |
| Harga Sewa | `Rp {number_format}` (`font-mono`, emerald) |
| ROI Harian | `Rp {number_format}` (`font-mono`) |
| Durasi | `{duration_days} hari` |
| Purchase Quota | `Maks. {max_per_user}` or `Tanpa Batas`; secondary line `{total_cnt} dipakai` |
| Prerequisite | name badge or `—`; secondary `{active_cnt} kontrak aktif` |
| Status | `t-badge-success` AKTIF / `t-badge-danger` NONAKTIF (+ `fa-check-circle`/`fa-ban`) |
| Aksi | **Edit** (opens edit modal) + **toggle** inline `form_open` POST w/ `onsubmit confirm` (mirror `toggle_ban` in `users.php`) |

- **Empty state**: when `$products` is empty → centered muted row (`t-card`, icon
  `fa-microchip`, `Belum ada paket GPU. Tambahkan paket pertama.`) with the create CTA.

### 6.2 Create / Edit modal (vanilla JS, `users.php` `createUserModal` precedent)

One page with a **single shared modal** driven by JS:

- Fields: `name` (text), `type` (select short/long), `price` (number), `daily_rate`
  (number), `duration_days` (number), `max_per_user` (number, hint `0 = tanpa batas`),
  `is_refundable` (checkbox), `unlock_prerequisite_id` (select — options built server-side
  from `get_all_products_lite()`; on **edit** the current product's own option is omitted
  to prevent self-reference at the source; on **create** an extra `— (Tanpa Prasyarat)`
  option maps to empty → `NULL`), `is_active` (select, **create only**).
- Actions: create modal posts `admin/products/create`; edit modal posts
  `admin/products/update/{id}` (hidden `id` field). Both `form_open()` → CSRF token auto.
- Edit button carries `data-product="<?= htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"`; JS fills fields on open and swaps the modal `form action` + submit label (`Simpan Perubahan` vs `Buat Paket`).
- Numeric inputs use `inputmode="numeric"`; server regex remains the source of truth (M8).

### 6.3 Navigation integration (`admin/templates/sidebar.php`)

Insert below the "User Management" link:

```php
<a href="<?= site_url('admin/products') ?>"
   class="t-nav-link <?= $this->uri->segment(2) === 'products' ? 't-nav-active' : '' ?>">
    <i class="fas fa-microchip w-5 text-center text-xs"></i>
    <span>Produk GPU</span>
</a>
```

---

## 7. INTEGRITY, CONCURRENCY & DATA-SAFETY SAFEGUARDS

| # | Safeguard | Mechanism |
|---|---|---|
| S1 | Zero hard-delete | No delete route/method/button; FKs `fk_user_rentals_product` & `fk_gpu_products_unlock_prereq` are `ON DELETE RESTRICT` — a DELETE would fail at the DB regardless. Soft-disable only via toggle (`is_active = 0`), which instantly hides the package from the user marketplace & gating catalog (both filter `is_active = 1`). |
| S2 | FK safety on prereq | Prerequisite must resolve to an existing `gpu_products.id` (pre-check + FK backstop `fk_gpu_products_unlock_prereq` RESTRICT). Updating the chain is only possible through `update_product`, so it can never dangle. |
| S3 | Self & cycle prevention | `prereq_would_cycle()` bounded walk (§3.4) + edit dropdown omits self + controller rejects `candidate == id`. |
| S4 | Integer-IDR (M8) | `price`/`daily_rate` regex `^[1-9][0-9]*$`; `(int)` casts at controller boundary AND model write; no float anywhere; existing `DECIMAL(15,2)` storage unchanged (consumers `(int)`-cast). |
| S5 | Race safety on toggle/update | Single-row guarded `UPDATE` inside TX; concurrent admin toggles serialize on row lock; audit row in same TX ⇒ no state/audit divergence. No dependency on a pre-read value being written back (toggle writes computed target, not stale snapshot). |
| S6 | Usage counters freshness | Counters are computed per request from `user_rentals` (single grouped aggregate) — no denormalized counter to drift; no change to the wallet/ledger or rental lifecycle. |
| S7 | CSRF + auth | Global `csrf_protection = TRUE`; all mutators POST-only + `admin_id` session guard in constructor; GET on a mutator → `show_404()`. |
| S8 | No ledger/money-move impact | This feature writes only `gpu_products` + `system_audit_logs`. `wallet_ledger`, `users.balance`, `user_rentals` untouched ⇒ invariants Z1–Z4 (plan/83 §6) unaffected. |
| S9 | Canonical seed interplay (documented) | `database.sql` upsert refreshes ids 1–8 (incl. `is_active = 1`) on re-run — a toggle OFF on a canonical package persists until someone re-runs `database.sql` (fresh-install script; not part of live ops). Noted in UI-agnostic way (no code change). |

---

## 8. VERIFICATION TEST MATRIX (implementation round)

Prep: admin session via `/control-panel` login; dev DB already migrated (plan/83). CSRF
token read from rendered forms; curl must send cookie + token for POSTs.

| # | Scenario | Expected |
|---|---|---|
| V1 | GET `/admin/products` (admin session) | HTTP 200; table lists all 12 live rows (8 active commercial + 4 inactive legacy) or DB state; prereq column shows chain names (RTX 4090 Pro → RTX 4080 Prime, …); inactive rows visible with NONAKTIF badge. |
| V2 | GET `/admin/products` (no session) | 302 → `/control-panel`. |
| V3 | Create valid package (`POST admin/products/create`) | 302 → `admin/products` + success flash; row appears w/ AKTIF; audit row `admin_create_product` exists with `details.before = null` + full `after` incl. `product_id`. |
| V4 | Create: price `100000.50`, `1e5`, `0`, `-5`, empty | All rejected; flash lists Indonesian error(s); no `gpu_products` row; no audit row. |
| V5 | Create: `daily_rate` float / `duration_days = 0` / `max_per_user = -1` | Rejected per §4 regex; zero writes. |
| V6 | Update: rename + change price/daily_rate/quota/prereq (valid) | 302 success; row updated; audit `admin_update_product` with symmetric `before`/`after`. |
| V7 | Update: self-prereq (`unlock_prerequisite_id == id`) | Rejected (`tidak bisa menjadi prasyarat dirinya sendiri`); nothing written. |
| V8 | Update: circular A↔B (set B's prereq → A while A's prereq → B) | Rejected (`akan membentuk siklus`); chain unchanged; audit absent. |
| V9 | Update: nonexistent prereq id (e.g. 99999) | Rejected (`Prasyarat tidak ditemukan`); FK never hit; no row. |
| V10 | Update: duplicate name (another row already has it, incl. case variant) | Rejected (unique). |
| V11 | Toggle ON→OFF then OFF→ON (id of an active package) | Each 302 + flash; `is_active` flips; audit `admin_toggle_product_status` records `before/after is_active`; second toggle back restores. |
| V12 | User side cross-check after toggle OFF | `GET /marketplace` no longer shows that package (is_active filter) — plan/83 catalog & gate states consistent. |
| V13 | GET (not POST) on `create`/`update/5`/`toggle_status/5` | `show_404()` (HTTP 404), no state change. |
| V14 | POST without CSRF token | CI3 CSRF rejection (HTTP error), no state change. |
| V15 | Hard-delete attempt (`DELETE … WHERE id` on a package with rentals / with dependent prereq) | FK `RESTRICT` error; row intact (sanity: no app path exposes delete). |
| V16 | Concurrent double-toggle smoke (two parallel POSTs) | Final state consistent (0/1) and exactly matching audit entries (row-lock serialization). |
| V17 | Audit trail review | `GET /admin/audit` filter shows the three new actions with JSON details & `ip_address`; `user_id` NULL. |
| V18 | Lint | `php -l` clean on `Admin.php`, `Admin_model.php`, `views/admin/products/index.php` (and any touched file). |
| V19 | Regression | Dashboard, users, financial-settings, audit pages still render; user marketplace & checkout gates (plan/83) behavior unchanged. |

---

## 9. FILES TOUCHED (future implementation round) & FOLLOW-UPS

| File | Change |
|---|---|
| `application/config/routes.php` | 4 new `$route` entries (§2.1) |
| `application/controllers/Admin.php` | `products()`, `create_product()`, `update_product()`, `toggle_product_status()` + shared validation helper `_validate_product_payload($is_edit)` (returns `{ok, errors, fields}`) |
| `application/models/Admin_model.php` | §3 methods (read + write + cycle/unique helpers) |
| `application/views/admin/products/index.php` | NEW — table + create/edit modal + empty state + flashes |
| `application/views/admin/templates/sidebar.php` | "Produk GPU" nav link |

Follow-ups (out of scope, noted): live-DB ↔ canonical layout divergence remains per
plan/84 §4 (admin CRUD operates on whatever rows exist, incl. the legacy inactive ids 1–4 —
expected); optional `plan/86` execution-summary doc after the implementation round;
no `database.sql` change required this phase.
