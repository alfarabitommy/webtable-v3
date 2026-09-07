# 87 — SIMPLIFIED ADMIN-CONTROLLED PRODUCT GATING (BLUEPRINT)

**Status:** BLUEPRINT — planning artifact. No application code, model, controller,
view, or database table was modified to produce this document. Execution (code
edits + live-DB migration) starts only after explicit user approval.

**Context:** plan/83 built a two-axis gating engine on `gpu_products`
(`is_active` availability + `unlock_prerequisite_id` prerequisite chain +
`max_per_user` lifetime quota). This revision **decommissions the prerequisite
chain axis entirely**. Business requirement simplification:

1. **Decommission prerequisite gating** — renting package A no longer unlocks
   package B. Product availability becomes **100% manual** via the admin
   `is_active` toggle (1 = visible & purchasable, 0 = hidden & unpurchasable).
2. **Clean member catalog** — inactive products (`is_active = 0`) are completely
   hidden from the marketplace. They are **never** rendered as locked/dimmed
   cards (no "Terkunci" ribbon, no dimmed opacity, no disabled prereq CTA).
3. **Retain purchase limits (`max_per_user`)** — the per-user lifetime quota
   engine stays 100% active: visible as a badge on every marketplace card and
   enforced inside the checkout ACID transaction (authoritative gate).

Historical records plan/83–plan/86 and `docs/5_AUDIT_REPORT.md` remain untouched
as the audit trail. This document is the authoritative spec for the change set.

---

## 1. EXECUTIVE SUMMARY

| Concern | Today (plan/83/85) | After (plan/87) |
|---|---|---|
| Availability gate | `is_active = 1` **AND** prereq chain satisfied (auto-unlock) | `is_active = 1` only (admin toggle = single knob) |
| Locked state on marketplace | State B: amber "Terkunci" ribbon, `opacity-60 saturate-50` card, disabled "Terkunci — Sewa {prereq} dahulu" button | **Removed entirely** (state no longer exists) |
| Inactive products | Hidden from catalog query | Hidden from catalog query (unchanged) — never locked/dimmed |
| Purchase limits | `max_per_user` badge + TX gate | **Unchanged** — badge retained, TX gate retained |
| Checkout TX gates | GATE 0 (exists & `is_active=1`), GATE 1 (prereq owned ≥1x), GATE 2 (quota) | GATE 0 + GATE 2 only (GATE 1 deleted) |
| `unlock_prerequisite_id` | Self-referencing FK chain (seed rows 5–8), admin dropdown, cycle detection | **Dormant column**: all rows `NULL`, no app code reads/writes it, FK/index retained (zero destructive DDL) |
| Admin products table | 9 columns incl. "Paket Prasyarat" badge | 8 columns — column removed; live-usage line relocated |
| Admin create/edit modal | Prasyarat `<select>` + server cycle validation | Prasyarat field removed; validation block removed |

Net effect: the member catalog is a clean list of exactly the packages the admin
wants to sell, each with a live quota badge; checkout refuses anything that is
inactive (fail-closed) or over the user's lifetime quota, atomically.

---

## 2. BUSINESS RULES (AUTHORITATIVE SPEC)

### 2.1 Rule semantics (decisions locked)

- **R1 — Single availability knob.** `gpu_products.is_active` is the *only*
  product availability gate. `is_active = 0` ⇒ hidden from marketplace AND
  rejected by checkout GATE 0. No other condition can hide a product.
- **R2 — Prerequisite chain decommissioned.** `unlock_prerequisite_id` no longer
  participates in any business logic: no catalog computation, no checkout gate,
  no admin validation, no UI. Existing chain values are nulled once (data
  migration), and new writes can never set the column again (UI removed +
  model whitelist narrowed).
- **R3 — Quota engine retained (unchanged semantics).** `max_per_user`
  `0 = unlimited`; `N ≥ 1 = lifetime rental cap per user`. Qualifying rows keep
  predicate **D1** (plan/83): `user_rentals.status IN ('active','completed')`;
  `'cancelled'` rows consume **no** quota and never did. Enforced in the checkout
  TX (GATE 2) — the UI badge is display-only, never trusted.
- **R4 — Two marketplace card states only.** State A *Available*
  (`can_rent === true`) → enabled "Sewa Sekarang" CTA; State B *Quota Reached*
  (`is_quota_exhausted === true`) → disabled "Batas Maksimal Tercapai" button.
  Because `can_rent = !is_quota_exhausted`, exactly one state renders per card.
- **R5 — Quota badge on every card.** Exact strings in §2.2. Display logic
  unchanged from plan/83.
- **R6 — Admin lifecycle preserved.** Products are created, edited, and
  toggled active/inactive with the atomic M5 audit pattern
  (`trans_start`/`trans_complete` + `Audit_model::log_admin_action`). The
  toggle endpoint remains the only status mutator for existing rows.
- **R7 — Scope freeze.** Money math (M8), `wallet_ledger` immutability, session,
  CSRF, rate limiting, and rental expiry are untouched by this change.

### 2.2 UI string contract (Indonesian, exact)

Retained strings (must keep byte-for-byte):

| Context | String |
|---|---|
| Marketplace CTA (State A) | `Sewa Sekarang` |
| Marketplace CTA (State B, disabled) | `Batas Maksimal Tercapai` |
| Quota badge (limited) | `Batas Sewa: Maks. X (Tersisa: Y)` |
| Quota badge (unlimited) | `Batas Sewa: Tanpa Batas` |
| Checkout rejection (quota) | `Sistem: Batas maksimal sewa paket ini telah tercapai (Maks. N).` |
| Checkout rejection (inactive/missing) | `Sistem: Produk tidak ditemukan di database.` |

Removed strings (must no longer exist in application code):

| Context | String |
|---|---|
| Locked ribbon | `Terkunci` (badge) |
| Locked CTA | `Terkunci — Sewa {prerequisite_name} dahulu` |
| Admin modal | `Paket Prasyarat` label / `— (Tanpa Prasyarat)` option |
| Admin validation | `Paket tidak bisa menjadi prasyarat dirinya sendiri.` / `Prasyarat tidak ditemukan.` / `Prasyarat tidak valid — akan membentuk siklus terkunci.` |

### 2.3 Checkout return-code contract

`Rental_model::checkout_rental()` after refactor returns codes:
`'ok' | 'product_unavailable' | 'quota_exceeded' | 'insufficient' | 'error'`.
Code **`'locked'` is deleted** — no caller maps it (verified: `Rentals::checkout`
maps the generic `message` only), so no controller change is required.

---

## 3. DATABASE STRATEGY (`gpu_products`) — NON-DESTRUCTIVE

### 3.1 Dormant-column policy (decision)

Keep the physical column, its index, and its self-referencing FK **exactly as
is**. Rationale:

- Setting the child-side column to `NULL` never violates an FK — the constraint
  `fk_gpu_products_unlock_prereq ... ON DELETE RESTRICT ON UPDATE RESTRICT` only
  fires when a *referenced parent row* is deleted or its PK changes. Parent rows
  (ids 4–7) remain, and their referrers become `NULL`.
- Zero destructive DDL ⇒ zero risk to the live DB, `database.sql` fresh
  installs, and old-dump restores.
- The canonical schema keeps the column annotated `DEPRECATED (plan/87)`,
  mirroring the established `rentals` / `otp_logs` retention pattern (M10,
  plan/78): present for compatibility, dead to the application.

**App-layer invisibility:** the column is never *used* for logic. `SELECT p.*`
may still transport it (harmless), but no code branches on it and no audit
payload or `data-product` JSON serializes it. Write paths cannot set it: the
`Admin_model` whitelist (§4.4) no longer accepts the key, and the admin UI has
no input for it.

### 3.2 Canonical DDL (`database.sql`)

Replace the plan/83 comment above the column (current lines ~45–50) with:

```sql
  -- plan/83: gating & per-user purchase limits engine.
  -- 0 = unlimited; N >= 1 = lifetime rental cap per user.
  `max_per_user` INT UNSIGNED NOT NULL DEFAULT 0,
  -- DEPRECATED (plan/87): prerequisite-chain gating decommissioned.
  -- Product availability is 100% admin-controlled via `is_active`.
  -- Column/index/FK retained non-destructively (all rows NULL); no
  -- application code reads or writes this column anymore.
  `unlock_prerequisite_id` INT UNSIGNED NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
```

Column definition, `INDEX idx_unlock_prerequisite`, and
`CONSTRAINT fk_gpu_products_unlock_prereq` are **unchanged**.

### 3.3 Canonical seed (`database.sql`)

Update the seed block comment and rows so every `unlock_prerequisite_id` is
`NULL` (ids 5–8 previously chained 5→4, 6→5, 7→6, 8→7). `max_per_user` values
are **unchanged** (1,2,3,5,5,5,0,0) — the quota engine is retained.

```sql
-- plan/87: gating & limits engine simplified — `max_per_user` (0 = tanpa
-- batas) tetap aktif sebagai satu-satunya batas pembelian per-user;
-- `unlock_prerequisite_id` DICOMMISSIONED (rantai progresif 4→5→6→7→8
-- dihapus): ketersediaan produk 100% via toggle admin `is_active`.
INSERT INTO `gpu_products` (`id`, `name`, `type`, `price`, `daily_rate`, `duration_days`, `is_refundable`, `max_per_user`, `unlock_prerequisite_id`, `is_active`) VALUES
(1, 'RTX 3060 Starter', 'short_term', 150000.00, 7500.00, 25, 0, 1, NULL, 1),
(2, 'RTX 4060 Lite', 'short_term', 300000.00, 13500.00, 30, 0, 2, NULL, 1),
(3, 'RTX 4070 Basic', 'short_term', 600000.00, 28000.00, 30, 0, 3, NULL, 1),
(4, 'RTX 4080 Prime', 'short_term', 1200000.00, 57600.00, 35, 0, 5, NULL, 1),
(5, 'RTX 4090 Pro', 'long_term', 2500000.00, 125000.00, 40, 0, 5, NULL, 1),
(6, 'A100 Cloud Cluster', 'long_term', 4500000.00, 234000.00, 45, 0, 5, NULL, 1),
(7, 'H100 Tensor Node', 'long_term', 7000000.00, 378000.00, 50, 0, 0, NULL, 1),
(8, 'H200 Sovereign', 'long_term', 10000000.00, 560000.00, 60, 0, 0, NULL, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `type` = VALUES(`type`),
  `price` = VALUES(`price`),
  `daily_rate` = VALUES(`daily_rate`),
  `duration_days` = VALUES(`duration_days`),
  `is_refundable` = VALUES(`is_refundable`),
  `max_per_user` = VALUES(`max_per_user`),
  `unlock_prerequisite_id` = NULL,
  `is_active` = VALUES(`is_active`);
```

(`unlock_prerequisite_id = NULL` in the upsert is idempotent — re-running the
canonical seed can never resurrect a chain.)

### 3.4 Live-DB migration (idempotent, re-runnable)

Executed during the implementation round against live `db_webtable`
(precedent: plan/84 §1.1):

```sql
-- plan/87 live-DB migration (data-only; zero destructive DDL).
UPDATE `gpu_products` SET `unlock_prerequisite_id` = NULL
 WHERE `unlock_prerequisite_id` IS NOT NULL;

-- Verify: expect 0 rows.
SELECT COUNT(*) AS remaining_chained
  FROM `gpu_products`
 WHERE `unlock_prerequisite_id` IS NOT NULL;

-- Verify: expect all-NULL column, rows 1-8 intact.
SELECT id, name, is_active, max_per_user, unlock_prerequisite_id
  FROM `gpu_products` ORDER BY id;
```

### 3.5 FK / integrity rationale

- FK semantics unchanged; child-side `NULL` is always legal.
- `user_rentals.product_id` FK and `rentals.gpu_product_id` FK (legacy) are
  untouched — historical contracts stay intact (Zero Hard-Delete preserved).
- The one-time `UPDATE` touches no other table and no money data.

### 3.6 Future hard-drop (explicitly OUT OF SCOPE)

A later release *may* fully remove the column, but only after
`ALTER TABLE gpu_products DROP FOREIGN KEY fk_gpu_products_unlock_prereq`,
`DROP INDEX idx_unlock_prerequisite`, then `DROP COLUMN unlock_prerequisite_id`.
This is deliberately deferred: it is destructive DDL with zero business value
now, and old-dump compatibility argues for retention.

---

## 4. MODEL REFACTOR

### 4.1 `Product_model::get_catalog_for_user($user_id)` — simplified catalog

**File:** `application/models/Product_model.php` (method + docblock).

**Query A — self-join removed.** Before:

```php
$rows = $this->db->query(
    "SELECT p.*,
            pr.id   AS prerequisite_id,
            pr.name AS prerequisite_name
       FROM gpu_products p
       LEFT JOIN gpu_products pr ON pr.id = p.unlock_prerequisite_id
      WHERE p.is_active = 1
      ORDER BY p.id ASC"
)->result_array();
```

After (only active products; no join):

```php
// Query A — produk aktif SAJA (is_active = 1). Tidak ada self-join prasyarat
// (plan/87): ketersediaan 100% via toggle admin; produk non-aktif tidak
// pernah dirender dalam bentuk apa pun (bukan kartu terkunci/dim).
$rows = $this->db->query(
    "SELECT p.*
       FROM gpu_products p
      WHERE p.is_active = 1
      ORDER BY p.id ASC"
)->result_array();
```

**Query B — unchanged** (single grouped aggregate over qualifying rentals,
predicate D1). Per-row computation becomes:

```php
foreach ($rows as &$p) {
    $p['user_rentals_count'] = $counts[(int) $p['id']] ?? 0;

    // M8 (plan/74): seluruh aritmetika integer — max_per_user dari DB
    // (string) di-(int) kan sebelum perbandingan/selisih.
    $max                   = (int) $p['max_per_user'];
    $p['quota_max']        = $max;
    $p['is_unlimited']     = ($max === 0);
    $p['quota_remaining']  = $p['is_unlimited']
        ? null
        : max(0, $max - $p['user_rentals_count']);
    $p['is_quota_exhausted'] = (!$p['is_unlimited']
        && $p['user_rentals_count'] >= $max);

    // plan/87: tanpa prasyarat — ketersediaan murni fungsi kuota.
    $p['can_rent'] = !$p['is_quota_exhausted'];
}
unset($p);
```

**Deleted:** `prerequisite_id`, `prerequisite_name`, `is_locked` computation and
the `$prereq` casting block.

**Return contract (docblock updated):** product rows (active only, `id ASC`)
with `user_rentals_count (int)`, `quota_max (int)`, `is_unlimited (bool)`,
`quota_remaining (int|null)`, `is_quota_exhausted (bool)`, `can_rent (bool)`.
`can_rent` is now **strictly** `!is_quota_exhausted`.

### 4.2 `Rental_model::checkout_rental` — GATE 0 & GATE 2 retained, GATE 1 deleted

**File:** `application/models/Rental_model.php` (method body + docblocks).

**GATE 0 snapshot — self-join and prereq columns removed.** Before (lines
~95–103) selects `p.unlock_prerequisite_id` and joins `prerequisite_name`.
After:

```php
// 2. GATE 0 (plan/83, retained) — snapshot produk SEGAR (current read
//    SETELAH lock wait). Baris hilang / non-aktif → tolak: produk
//    non-aktif tidak boleh dibeli via POST tamper.
$product = $this->db->query(
    "SELECT p.id, p.name, p.price, p.daily_rate, p.duration_days,
            p.is_active, p.max_per_user
       FROM gpu_products p
      WHERE p.id = ?",
    [(int) $product['id']]
)->row_array();

if (!$product || (int) $product['is_active'] !== 1) {
    $this->db->trans_rollback();
    return ['success' => false, 'code' => 'product_unavailable', 'message' => 'Sistem: Produk tidak ditemukan di database.', 'rental_id' => null];
}
```

**GATE 1 deleted.** The paired `own_count`/`prereq_count` query collapses to a
single quota count; the whole locked-rejection block (which returned
`code => 'locked'`) is removed. After:

```php
// 3. GATE 2 (plan/83, retained) — kuota lifetime dalam TX terkunci.
//    Predikat D1: status IN ('active','completed'); 'cancelled' tidak
//    memakan kuota. (GATE 1 prasyarat DICOMMISSIONED — plan/87.)
$gates = $this->db->query(
    "SELECT COUNT(*) AS own_count
       FROM user_rentals ur
      WHERE ur.user_id = ? AND ur.product_id = ?
        AND ur.status IN ('active','completed')",
    [$user_id, (int) $product['id']]
)->row();

if (!$gates) {
    $this->db->trans_rollback();
    log_message('error', 'Rental_model::checkout_rental — gate count gagal (user=' . (int) $user_id . ', product=' . (int) $product['id'] . ')');
    return ['success' => false, 'code' => 'error', 'message' => 'Sistem: Gagal memproses sewa. Coba lagi.', 'rental_id' => null];
}

$max = (int) $product['max_per_user'];
if ($max > 0 && (int) $gates->own_count >= $max) {
    $this->db->trans_rollback();
    return ['success' => false, 'code' => 'quota_exceeded',
        'message' => 'Sistem: Batas maksimal sewa paket ini telah tercapai (Maks. ' . $max . ').',
        'rental_id' => null];
}
```

**Unchanged after GATE 2:** strict overspend rejection, `Wallet_model::debit()`
ledger ingestion, `user_rentals` insert, commit; catch → rollback. Rejection
paths still never touch `wallet_ledger` (immutability Z1).

**Docblock edits:** return-code list drops `'locked'`; the method-header step
list (§4 in current comment) drops the GATE 1 bullet and the `$prereq_id`
fallback explanation; `@param array $product` note stays (id + UX fast-fail
only). Naming: keep the labels "GATE 0" and "GATE 2" (not renumbered) so audit
references to plan/83/84 stay greppable.

### 4.3 `Admin_model` read helpers — join removed

**File:** `application/models/Admin_model.php`.

**`get_products_admin()`** — drop the `prerequisite_name` self-join; keep the
live-usage grouped aggregate. After:

```php
public function get_products_admin() {
    $rows = $this->db->query(
        "SELECT p.*,
                COALESCE(ag.active_cnt, 0) AS active_cnt,
                COALESCE(ag.total_cnt, 0)  AS total_cnt
           FROM gpu_products p
           LEFT JOIN (
                SELECT product_id,
                       SUM(status = 'active')                AS active_cnt,
                       SUM(status IN ('active','completed')) AS total_cnt
                  FROM user_rentals
                 GROUP BY product_id
           ) ag ON ag.product_id = p.id
          ORDER BY p.id ASC"
    )->result();
    // ... (int)-forcing loop unchanged ...
}
```

Docblock `@return` drops `prerequisite_name`.

**`get_product_row($id)`** — drop the join. After:

```php
public function get_product_row($id) {
    return $this->db->query(
        "SELECT p.*
           FROM gpu_products p
          WHERE p.id = ?",
        [(int) $id]
    )->row();
}
```

(`p.*` still carries the dormant column; no consumer branches on it — the audit
payload builder in the controller is edited to omit it, §5.)

### 4.4 `Admin_model` write sanitizer & dead-code removal

- **`_sanitize_product_fields()`:** remove `'unlock_prerequisite_id'` from the
  `$allowed` whitelist and delete its `case` branch. Effect: unknown keys are
  skipped (existing fail-closed anti-mass-assignment), so no code path can ever
  write the dormant column again. After:

```php
$allowed = ['name', 'type', 'price', 'daily_rate', 'duration_days',
            'is_refundable', 'max_per_user', 'is_active'];
```

- **Delete method `get_all_products_lite()`** (lines ~602–615) — sole consumer
  was the admin products view's prereq dropdown (`prereq_options`), which is
  removed.
- **Delete method `prereq_would_cycle()`** (lines ~633–662) — sole consumer was
  the controller validator's prereq block, which is removed. The analogous
  `has_ancestor()` upline check (team hierarchy) is unrelated and untouched.
- Update the plan/85 section header comment only if it mentions the chain;
  the D1 usage-predicate note (`active_cnt`/`total_cnt`) stays.

---

## 5. CONTROLLER REFACTOR (`Admin.php`)

**File:** `application/controllers/Admin.php`.

1. **`products()`** — remove the `prereq_options` data key:

```php
$data = [
    'page_title' => 'Manajemen Produk GPU',
    'products'   => $this->Admin_model->get_products_admin(),
];
```

2. **`_validate_product_payload($is_edit, $product_id = null)`** — delete the
   entire `// ── prasyarat (NULL / eksisting / != self / tanpa siklus) ──`
   block (lines ~1281–1298) and the `'unlock_prerequisite_id' => $prereq,`
   entry in `$fields`. The `$product_id` parameter is still needed for the
   name-uniqueness exclusion (`is_product_name_taken($name, $product_id)`) —
   signature stays. Validation that **remains**: name (required, ≤100, unique),
   type enum, M8 regex `^[1-9][0-9]*$` for `price`/`daily_rate`/`duration_days`,
   `^(0|[1-9][0-9]*)$` for `max_per_user`, `is_active` on create only.
   Docblock bullets referencing the prereq rules are removed.

3. **`_product_payload_from_row($row)`** — remove the
   `'unlock_prerequisite_id' => ...` key so symmetric audit BEFORE/AFTER JSON
   payloads never contain the dormant column.

4. **`Rentals.php`, `Marketplace.php`, `routes.php`: no change** — verified:
   `Rentals::checkout` maps `checkout_rental` results generically by `message`;
   nothing references code `'locked'`; the `Marketplace` controller already
   passes only `products` + `user_balance` to the view.

---

## 6. UI REFACTOR

### 6.1 Marketplace — card state machine (`application/views/marketplace/index.php`)

**State machine change (card-level, computed server-side in §4.1):**

| State | Condition | Render |
|---|---|---|
| A — Available | `can_rent === true` | Enabled `.btn-sewa` → "Sewa Sekarang" |
| B — Quota Reached | `is_quota_exhausted === true` | Disabled button → "Batas Maksimal Tercapai" |
| ~~Locked~~ | ~~prereq not owned~~ | **Deleted** — no ribbon, no dim, no prereq CTA |

Loop body before (lines ~60–121) renders: locked-ribbon `<span>` in a
`<div class="relative">` wrapper, ternary card opacity
(`$is_locked ? 'opacity-60 saturate-50' : ($is_exhausted ? 'opacity-75' : '')`),
and a 3-branch CTA (`can_rent` / `$is_locked` / else).

Loop body after:

```php
<?php foreach ($products as $product): ?>
<?php
    // plan/87 — dua state kartu: A available / B quota reached.
    // Prasyarat & state "locked" DICOMMISSIONED (gating 100% via admin
    // is_active); produk non-aktif tidak pernah sampai ke view ini
    // (Product_model: WHERE is_active = 1).
    $is_exhausted = !empty($product['is_quota_exhausted']);
?>
<div class="u-card-gpu rounded-2xl p-4 shadow-sm flex flex-col <?= $is_exhausted ? 'opacity-75' : '' ?>">
    <img src="https://placehold.co/400x150/f8fafc/94a3b8?text=<?= urlencode($product['name']) ?>" class="rounded-xl object-cover h-28 w-full mb-3" alt="<?= htmlspecialchars($product['name']) ?>">

    <h3 class="text-base font-bold u-text"><?= htmlspecialchars($product['name']) ?></h3>

    <!-- Quota badge (plan/83; dipertahankan plan/87): selalu tampil -->
    <span class="mt-1.5 inline-flex items-center gap-1.5 text-[10px] font-semibold px-2.5 py-1 rounded-full w-fit <?= $product['can_rent'] ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-slate-500/10 u-muted' ?>">
        <i class="fas fa-gauge-high text-[9px]"></i>
        <?php if (!empty($product['is_unlimited'])): ?>
            Batas Sewa: Tanpa Batas
        <?php else: ?>
            Batas Sewa: Maks. <?= (int) $product['quota_max'] ?> (Tersisa: <?= (int) $product['quota_remaining'] ?>)
        <?php endif; ?>
    </span>

    <div class="flex items-center gap-4 mt-3">
        <!-- Harga Sewa & ROI Harian blocks unchanged -->
    </div>

    <?php if ($product['can_rent']): ?>
        <!-- State A — Available -->
        <button class="btn-sewa w-full h-12 u-btn-cyber rounded-xl font-bold mt-3 transition-all active:scale-[0.98]"
                data-id="<?= (int) $product['id'] ?>"
                data-name="<?= htmlspecialchars($product['name']) ?>"
                data-price="<?= (int) $product['price'] ?>">
            Sewa Sekarang
        </button>
    <?php else: ?>
        <!-- State B — Quota Reached -->
        <button type="button" disabled
                class="w-full h-12 u-btn-ghost rounded-xl font-bold mt-3 cursor-not-allowed opacity-80 inline-flex items-center justify-center gap-2">
            <i class="fas fa-ban"></i> Batas Maksimal Tercapai
        </button>
    <?php endif; ?>
</div>
<?php endforeach; ?>
```

Concrete edits:
- Delete the card-level comment "plan/83 — tiga state kartu: A available / B
  locked / C quota reached." and the `$is_locked` computation.
- Delete the `<div class="relative">` wrapper + amber "Terkunci" ribbon `<span>`
  (flatten the `<img>` up one level; keep its classes).
- Card class: replace the locked/exhausted ternary with
  `<?= $is_exhausted ? 'opacity-75' : '' ?>` (subtle affordance for a
  quota-reached card only; no lock styling anywhere).
- Delete the `elseif ($is_locked):` CTA branch (State B/locked, with the
  `prerequisite_name` interpolation) — the CTA collapses to the two branches
  above.
- Quota badge block unchanged (strings per §2.2); empty-state block and the
  bottom-sheet modal + JS (`btn-sewa` binding, `data-guard-submit` form) are
  untouched — State B buttons are `disabled` and carry no `.btn-sewa` class,
  so they are never bound.

### 6.2 Admin products (`application/views/admin/products/index.php`)

**Table columns:** `ID | Nama & Type | Harga Sewa | ROI Harian | Durasi | Kuota
Sewa | Status | Aksi` (8 columns; "Paket Prasyarat" removed).

1. `<thead>` — delete the `<th>Paket Prasyarat</th>`.
2. Empty-state row `colspan="9"` → `colspan="8"`.
3. Row body — delete the prereq `<td>` block (the amber
   `unlock_prerequisite_id` badge). Preserve the per-row live-usage datum by
   relocating its "N kontrak aktif" line into the **Kuota Sewa** cell (which
   already shows "N dipakai (lifetime)"):

```php
<td class="px-4 py-3">
    <span class="text-xs font-semibold text-[var(--t-text)]">
        <?= $is_limited ? 'Maks. ' . (int) $p->max_per_user : 'Tanpa Batas' ?>
    </span>
    <span class="block text-[10px] t-muted"><?= (int) $p->total_cnt ?> dipakai (lifetime)</span>
    <span class="block text-[10px] t-muted"><?= (int) $p->active_cnt ?> kontrak aktif</span>
</td>
```

4. Info note under the header chips (currently mentions "rantai prasyarat
   terkunci via FK") — new copy:

```html
Paket tidak pernah dihapus permanen (riwayat sewa terkunci via FK).
Nonaktifkan saja via toggle — paket langsung hilang dari marketplace user
(plan/87: tanpa rantai prasyarat; ketersediaan 100% via toggle status).
```

5. **Edit-button `data-product` JSON** — delete the
   `'unlock_prerequisite_id' => ...` property.
6. **Create/Edit modal** — delete the "Paket Prasyarat" `<select>` field block
   (label + `#f_prereq` + `prereq_options` loop). Remaining modal fields:
   `name`, `type`, `duration_days`, `price`, `daily_rate`, `max_per_user`
   (`#f_quota`), `is_refundable` checkbox, `is_active` (create only; hidden on
   edit with the existing hint). Cosmetic: give `#f_active_wrap`
   `sm:col-span-2` (or accept grid auto-flow) since the prasyarat column that
   once balanced the grid is gone.
7. **Modal JS** — delete `prereqSel` declaration; in `resetForm()` delete the
   re-enable loop over `prereqSel.options`; in `openProductEdit()` delete the
   `f_prereq` value assignment and the disable-self loop. CSRF preservation
   logic (comment + `csrfInput` handling) stays untouched.

---

## 7. ZERO-REGRESSION SAFEGUARDS (INVARIANTS)

- **G1 — `is_active` is the only availability gate.** Enforced at both layers:
  catalog `WHERE is_active = 1` (render) and checkout GATE 0 inside the locked
  TX (purchase). Fail-closed: missing/inactive row → `product_unavailable`.
- **G2 — Quota is the only per-user purchase limit.** `max_per_user`
  (0 = unlimited) computed with `(int)` casts (M8), surfaced as the card badge,
  enforced by GATE 2 in the TX after the row-lock wait — no double-checkout race
  (same serialization argument as plan/83).
- **G3 — Dormant column isolation.** `unlock_prerequisite_id` is never read for
  logic, never serialized (audit/JSON), never writable (whitelist narrowed).
  Column/index/FK untouched physically.
- **Z1 — Ledger immutability.** All rejection paths (`product_unavailable`,
  `quota_exceeded`, `insufficient`, `error`) roll back before any write;
  `wallet_ledger` remains the sole authoritative ledger; balance
  = `SUM(credit) − SUM(debit)` unchanged.
- **M8 — Integer IDR discipline.** No new monetary math; all comparisons keep
  `(int)` casts; no floats introduced anywhere.
- **M4/M5 — Admin mutators.** POST-only fail-closed, CSRF via `form_open`,
  status changes through `toggle_product_status` with atomic audit
  (`admin_create_product` / `admin_update_product` / `admin_toggle_product_status`
  payloads shrink — no `unlock_prerequisite_id` key).
- **D1 — Quota predicate unchanged.** `user_rentals.status IN
  ('active','completed')` for both display telemetry and TX gates; `'cancelled'`
  rows still consume nothing.
- **No hard deletes, no destructive DDL** — Zero Hard-Delete and FK RESTRICT
  policy preserved; history (`user_rentals`, audit logs) untouched by the data
  migration.

---

## 8. VERIFICATION TEST MATRIX (implementation round)

| # | Check | Method | Expected |
|---|---|---|---|
| 1 | Lint every modified PHP file | `php -l` on the 6 edited PHP files | No syntax errors |
| 2 | No stale references in application code | `grep -rn 'prerequisite\|is_locked\|prereq_would_cycle\|get_all_products_lite\|prereq_options' application/` | No hits (allow plan/87 comments in view/model docblocks only) |
| 3 | Live-DB migration | Run §3.4 `UPDATE` + verification queries | `remaining_chained = 0`; ids 1–8 intact with expected `max_per_user`/`is_active` |
| 4 | FK intact | `SHOW CREATE TABLE gpu_products` (or `information_schema`) | `fk_gpu_products_unlock_prereq` + `idx_unlock_prerequisite` still present |
| 5 | Canonical seed re-runnable | Re-run `database.sql` seed block on a scratch schema | Idempotent upsert; no row re-chains |
| 6 | Marketplace hides inactive & never renders locked | Browser/curl on logged-in `/marketplace` after toggling one product off | Inactive product absent from HTML; zero "Terkunci"/`fa-lock` artifacts for any product |
| 7 | State A render | Product with quota left | `.btn-sewa` "Sewa Sekarang"; badge `Batas Sewa: Maks. N (Tersisa: M)` |
| 8 | State B render | Product with `is_quota_exhausted` (user count ≥ max) | Disabled "Batas Maksimal Tercapai"; badge `Tersisa: 0` |
| 9 | Badge unlimited | Product with `max_per_user = 0` | `Batas Sewa: Tanpa Batas` |
| 10 | Checkout GATE 0 tamper | POST `/rentals/checkout` with an inactive product id (TX emulation or live + rollback) | `code='product_unavailable'`, flash error, **zero** `wallet_ledger`/`user_rentals` rows persisted |
| 11 | Checkout GATE 2 | Attempt purchase at/over quota (emulate with TX + rollback, plan/84 §2.2 pattern) | `code='quota_exceeded'`; zero rows persisted |
| 12 | `'locked'` unreachable | grep + checkout of former chained product (e.g. id 5 without ever renting 4) | Succeeds if quota allows — chain no longer gates |
| 13 | Overspend still rejected | Low-balance checkout | `code='insufficient'`, rollback (regression C5) |
| 14 | Admin table | `/admin/products` | 8 columns; no "Paket Prasyarat"; "N kontrak aktif" still visible in Kuota cell; toggle buttons present |
| 15 | Admin modal | Open Create & Edit | No prasyarat select; CSRF token present; edit pre-fills remaining fields |
| 16 | Admin create | Create product via POST | Success; audit row `admin_create_product` payload has **no** `unlock_prerequisite_id` |
| 17 | Admin toggle audit (M5) | Toggle on/off | `system_audit_logs` row `admin_toggle_product_status` with symmetric `before/after {is_active}` |
| 18 | Checkout success path | Valid purchase with sufficient balance | `code='ok'`; debit + `user_rentals` inserted atomically (regression) |

Manual tooling note: DB-backed gate checks follow the established plan/84 §2.2
pattern (real TX + rollback emulation against live `db_webtable`), since the app
has no automated test suite.

---

## 9. FILES TOUCHED & CHANGE INVENTORY

| File | Action |
|---|---|
| `plan/87_SIMPLIFIED_ADMIN_CONTROLLED_GATING_PLAN.md` | **New** — this blueprint |
| `application/models/Product_model.php` | Edit — `get_catalog_for_user`: drop self-join & `is_locked`; `can_rent = !is_quota_exhausted` |
| `application/models/Rental_model.php` | Edit — `checkout_rental`: GATE 0 snapshot simplified; GATE 1 deleted; single-count GATE 2; docblocks |
| `application/models/Admin_model.php` | Edit — `get_products_admin`/`get_product_row` joins removed; delete `get_all_products_lite()` + `prereq_would_cycle()`; whitelist narrowed |
| `application/controllers/Admin.php` | Edit — `products()` data; validator prereq block; `_product_payload_from_row` key |
| `application/views/marketplace/index.php` | Edit — 2-state cards; locked ribbon/dim/CTA removed; quota badge retained |
| `application/views/admin/products/index.php` | Edit — column + modal select + JS cleanup; `colspan` 9→8; usage line relocated |
| `database.sql` | Edit — DEPRECATED column comment; seed all-NULL `unlock_prerequisite_id`; upsert NULL |
| Live `db_webtable` | Data migration only — §3.4 `UPDATE` (no DDL) |

Untouched (verified): `Rentals.php`, `Marketplace.php`, `routes.php`,
`Wallet_model.php`, `docs/1_PRD.md`, `docs/3_ROADMAP.md`, plan/83–86, and
`docs/5_AUDIT_REPORT.md` (historical record).

Suggested commit message (Indonesian, repo style):
`plan/87: dekomisioning gating prasyarat — ketersediaan produk 100% via toggle admin (is_active)`

---

## 10. FOLLOW-UPS / OUT OF SCOPE

- Optional future release: full `DROP COLUMN unlock_prerequisite_id` (requires
  dropping FK then index first, §3.6). Not part of this change.
- Optional pair summary doc (`plan/88_..._SUMMARY.md`) after implementation,
  per repo plan/summary convention.
- `docs/1_PRD.md` contains no prerequisite-chain language (verified by grep), so
  no spec-doc edit is required by this change.
