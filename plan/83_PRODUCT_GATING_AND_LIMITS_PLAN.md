# 83 — PRODUCT GATING & PER-USER PURCHASE LIMITS ENGINE (BLUEPRINT)

**Scope (Phase 1 — blueprint only):** Progressive unlock (gating) and per-user lifetime
purchase limits for the 8 commercial GPU packages (`gpu_products`).
**Mode:** DOCUMENTATION-ONLY round. This file is the approved architectural blueprint.
**Execution hold (user instruction):** NO database migration, model/controller/view edit,
or runtime query is executed from this round — implementation starts only after explicit
further instruction from the user.
**Target files for the future implementation round (not touched here):**
`database.sql`, `application/models/Product_model.php`,
`application/models/Rental_model.php`, `application/controllers/Marketplace.php`,
`application/views/marketplace/index.php`, plus doc sync (`docs/1_PRD.md` §C/D,
`docs/2_ERD.md`, `AGENTS.md` note).

---

## 1. EXECUTIVE SUMMARY

The marketplace currently lists all 8 packages with an unconditional "Sewa Sekarang" CTA.
This blueprint adds two orthogonal per-product controls, enforced **server-side inside the
existing ACID checkout transaction** (zero tolerance for URL/POST tampering) and mirrored
in the marketplace UI:

1. **`unlock_prerequisite_id`** — a package is *locked* until the user has rented its
   prerequisite package at least once (`user_rentals` row with
   `status IN ('active','completed')`).
2. **`max_per_user`** — a *lifetime* cap on the number of times a user may rent a package
   (`0` = unlimited; `N ≥ 1` ⇒ at most N qualified rental rows ever).

Product lineup semantics (seed): tiers 1–4 are immediately available with quotas 1/2/3/5;
tier 5 unlocks after renting tier 4; tier 6 after tier 5; tiers 7–8 (H100/H200) are
**unlimited (`0`)** but sit at the top of a strict unlock chain 4 → 5 → 6 → 7 → 8, so they
only ever appear after the lower chain has been rented once each.

The design reuses the platform's established invariants: `wallet_ledger` remains the sole
authoritative ledger, all money writes stay inside locked ACID transactions behind
`Wallet_model::credit()/debit()` (M8 integer-IDR choke point), and the rental
lifecycle/expiry/claim engine (`claim_roi`, lazy sweep, `claimable_info`) is **untouched** —
the new gates are pure *reads* on `user_rentals`/`gpu_products` inserted **before** any
ledger write, so a rejected request appends zero rows to any table.

---

## 2. BUSINESS RULES (AUTHORITATIVE SPEC)

| id | Package (type) | Price | max_per_user | unlock_prerequisite_id |
|----|----------------|-------|--------------|------------------------|
| 1 | RTX 3060 Starter (short_term) | 150.000 | 1 | NULL |
| 2 | RTX 4060 Lite (short_term) | 300.000 | 2 | NULL |
| 3 | RTX 4070 Basic (short_term) | 600.000 | 3 | NULL |
| 4 | RTX 4080 Prime (short_term) | 1.200.000 | 5 | NULL |
| 5 | RTX 4090 Pro (long_term) | 2.500.000 | 5 | 4 |
| 6 | A100 Cloud Cluster (long_term) | 4.500.000 | 5 | 5 |
| 7 | H100 Tensor Node (long_term) | 7.000.000 | 0 (unlimited) | 6 |
| 8 | H200 Sovereign (long_term) | 10.000.000 | 0 (unlimited) | 7 |

### 2.1 Rule semantics (decisions locked for implementation)

- **D1 — "Ever rented" predicate (shared, single source of truth):**
  `user_rentals.status IN ('active','completed')`. This is the ONLY definition of "rented
  at least once" — used by both the prerequisite gate and the quota counter.
  * `cancelled` rows (admin soft-cancel without refund, `Admin_model::cancel_rental`) do
    **not** satisfy a prerequisite and do **not** consume quota. Rationale: the spec defines
    prerequisite fulfilment as *active or completed*; a cancelled contract is an admin
    intervention with no refund, so it must not punish/credit the user's progression.
  * `expired` is not a stored status — the lazy sweep (M3/plan 60) and `claim_roi` flip
    `active → completed`, so expired/completed contracts remain "rented once" (lifetime
    semantics: completed contracts count toward quota and unlock downstream tiers forever).
- **D2 — Lifetime counts.** Quota is not "concurrent actives": every qualified historical
  row counts. Renting id 1 (max 1) exactly once ever is the lifetime ceiling; after its
  contract completes, the user **cannot** re-rent id 1.
- **D3 — Unlock is permanent once satisfied.** Renting the prerequisite once unlocks the
  dependent tier for that user forever (rows are never deleted; soft-cancelled rows exist
  but are not qualifying — see D1).
- **D4 — Gates are authoritative server-side only.** The catalog telemetry
  (`get_catalog_for_user`) is *display* sugar; the checkout TX re-evaluates both gates on a
  fresh DB snapshot after acquiring the per-user anchor lock. UI state can never be trusted.
- **D5 — Admin support bypass is out of scope.** `Admin_model::inject_rental`
  (admin-session-guarded support tool) does **not** pass user gates; it stays as-is and is
  documented as the operator bypass. Only the user self-service path
  (`Rentals::checkout` → `Rental_model::checkout_rental`) is gated.
- **D6 — Inactive products are not purchasable.** The TX gate block re-reads the product
  row and rejects `is_active = 0` (currently a tampered POST with a deactivated
  `product_id` would succeed if balance sufficed — closed as part of zero-tolerance).
  Marketplace only lists active products, so no UI regression.

### 2.2 UI string contract (Indonesian, exact)

| Context | String |
|---|---|
| Quota badge (limited) | `Batas Sewa: Maks. {max} (Tersisa: {remaining})` |
| Quota badge (unlimited) | `Batas Sewa: Tanpa Batas` |
| Locked button | `Terkunci — Sewa {prerequisite_name} dahulu` |
| Quota-reached button | `Batas Maksimal Tercapai` |
| Flash: prerequisite unmet | `Sistem: Paket ini masih terkunci. Sewa {prerequisite_name} dahulu untuk membukanya.` |
| Flash: quota exceeded | `Sistem: Batas maksimal sewa paket ini telah tercapai (Maks. {max}).` |
| Flash: inactive product | `Sistem: Produk tidak ditemukan di database.` (reuse existing string) |

---

## 3. DATABASE SCHEMA ENHANCEMENT (`gpu_products`)

### 3.1 Canonical DDL (`database.sql` — fresh installs)

Two columns added between `is_refundable` and `is_active`, plus self-referencing FK + index:

```sql
CREATE TABLE IF NOT EXISTS `gpu_products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('short_term', 'long_term') NOT NULL,
  `price` DECIMAL(15,2) NOT NULL,
  `daily_rate` DECIMAL(15,2) NOT NULL,
  `duration_days` INT UNSIGNED NOT NULL,
  `is_refundable` TINYINT(1) NOT NULL DEFAULT 0,
  `max_per_user` INT UNSIGNED NOT NULL DEFAULT 0,            -- NEW: 0 = unlimited
  `unlock_prerequisite_id` INT UNSIGNED NULL DEFAULT NULL,   -- NEW: FK → gpu_products.id
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_unlock_prerequisite` (`unlock_prerequisite_id`),  -- NEW (FK support)
  CONSTRAINT `fk_gpu_products_unlock_prereq` FOREIGN KEY (`unlock_prerequisite_id`)
    REFERENCES `gpu_products` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT  -- NEW
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

FK choice: `RESTRICT` (both directions) — products are soft-disabled via `is_active`, never
hard-deleted while referenced; RESTRICT makes a dangling/self-gating configuration fail
loudly instead of silently dropping the gate. (MySQL default `NO ACTION` ≡ RESTRICT; spelled
out for clarity.)

### 3.2 Canonical seed (`database.sql` — sync comment + full upsert)

Replace the current 8-row `INSERT … ON DUPLICATE KEY UPDATE` (plan/82 block) with the
extended statement. Column list and values gain `max_per_user` and
`unlock_prerequisite_id`; the `ON DUPLICATE KEY UPDATE` arm **must** refresh both new
columns so re-running `database.sql` re-applies the canonical gating policy to existing
rows (same refresh pattern as today's lineup update):

```sql
-- Seed `gpu_products` (plan/83): 8 paket komersial final + gating & limits engine.
INSERT INTO `gpu_products`
  (`id`, `name`, `type`, `price`, `daily_rate`, `duration_days`, `is_refundable`,
   `max_per_user`, `unlock_prerequisite_id`, `is_active`) VALUES
(1, 'RTX 3060 Starter',  'short_term', 150000.00,  7500.00, 25, 0, 1, NULL, 1),
(2, 'RTX 4060 Lite',     'short_term', 300000.00, 13500.00, 30, 0, 2, NULL, 1),
(3, 'RTX 4070 Basic',    'short_term', 600000.00, 28000.00, 30, 0, 3, NULL, 1),
(4, 'RTX 4080 Prime',    'short_term', 1200000.00, 57600.00, 35, 0, 5, NULL, 1),
(5, 'RTX 4090 Pro',      'long_term', 2500000.00, 125000.00, 40, 0, 5, 4,    1),
(6, 'A100 Cloud Cluster','long_term', 4500000.00, 234000.00, 45, 0, 5, 5,    1),
(7, 'H100 Tensor Node',  'long_term', 7000000.00, 378000.00, 50, 0, 0, 6,    1),
(8, 'H200 Sovereign',    'long_term', 10000000.00, 560000.00, 60, 0, 0, 7,    1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `type` = VALUES(`type`),
  `price` = VALUES(`price`),
  `daily_rate` = VALUES(`daily_rate`),
  `duration_days` = VALUES(`duration_days`),
  `is_refundable` = VALUES(`is_refundable`),
  `max_per_user` = VALUES(`max_per_user`),
  `unlock_prerequisite_id` = VALUES(`unlock_prerequisite_id`),
  `is_active` = VALUES(`is_active`);
```

(Column order in the row tuples above is illustrative; the implementation keeps the
existing 8-row layout and the `(id 1–8 eksplisit …)` comment block, extended with the two
new fields.)

### 3.3 Live-DB migration (idempotent, re-runnable)

The project has no migration framework — the live/dev DB is migrated by DBA/manual SQL.
Both `ALTER`s are guarded via `information_schema` so re-running is a no-op; the FK/index
are created together with the column on a fresh add:

```sql
-- ── 1) max_per_user ──────────────────────────────────────────────
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gpu_products'
               AND COLUMN_NAME = 'max_per_user');
SET @sql := IF(@has = 0,
  'ALTER TABLE `gpu_products`
     ADD COLUMN `max_per_user` INT UNSIGNED NOT NULL DEFAULT 0
     AFTER `is_refundable`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2) unlock_prerequisite_id + index + FK (fresh add only) ─────
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gpu_products'
               AND COLUMN_NAME = 'unlock_prerequisite_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `gpu_products`
     ADD COLUMN `unlock_prerequisite_id` INT UNSIGNED NULL DEFAULT NULL
       AFTER `max_per_user`,
     ADD INDEX `idx_unlock_prerequisite` (`unlock_prerequisite_id`),
     ADD CONSTRAINT `fk_gpu_products_unlock_prereq`
       FOREIGN KEY (`unlock_prerequisite_id`)
       REFERENCES `gpu_products` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3) Canonical policy values (idempotent) ─────────────────────
-- Re-run the §3.2 seed upsert (preferred — single canonical source),
-- or equivalently: UPDATE gpu_products SET max_per_user = ..., unlock_prerequisite_id = ...
-- WHERE id IN (1..8);  — values per §2 table.
```

Post-migration sanity: `SHOW CREATE TABLE gpu_products` (2 new columns, `idx_unlock_prerequisite`,
`fk_gpu_products_unlock_prereq`), then `SELECT id, name, max_per_user, unlock_prerequisite_id
FROM gpu_products ORDER BY id` must equal the §2 table.

**Backfill:** none needed. Quota/unlock are computed live from existing `user_rentals`
history — a user who already rented id 4 before the rollout is immediately eligible for
id 5, and their historical purchases already count against `max_per_user`. Only the
`gpu_products` policy columns are written.

### 3.4 Performance notes

- Count queries filter `user_id + status IN (…) + product_id`. Existing
  `idx_user_status_expired(user_id, status, expired_at)` serves the `user_id+status` prefix;
  per-user rental history is small (purchases only, lifetime-capped per product), so the
  residual `product_id` filter is negligible.
- The catalog aggregate (`§4.1` Query B) is one `GROUP BY product_id` over one user's
  history — bounded by the same argument.
- Optional (not required): `ADD INDEX idx_user_product_status (user_id, product_id, status)`
  if per-user history ever grows large. Skipped by default to avoid write-path overhead on a
  hot insert table.

---

## 4. BACKEND & MODEL ENHANCEMENTS

### 4.1 `Product_model::get_catalog_for_user($user_id)` — catalog telemetry (display only)

New public method; returns **assoc arrays** (same shape as today's
`get_all_active_products()` so the view keeps `$product['price']`-style access) with extra
computed keys. `get_all_active_products()`/`get_product()` remain untouched (the latter is
still the controller's fast pre-fetch for checkout).

**Query A — active products + prerequisite name** (self `LEFT JOIN`; a prerequisite that is
currently `is_active = 0` still renders its name, and the user's past rentals still satisfy
it — D1/D3):

```sql
SELECT p.*,
       pr.id   AS prerequisite_id,
       pr.name AS prerequisite_name
  FROM gpu_products p
  LEFT JOIN gpu_products pr ON pr.id = p.unlock_prerequisite_id
 WHERE p.is_active = 1
 ORDER BY p.id ASC;               -- deterministic; equals current render order (price ↑)
```

**Query B — one aggregate for the whole qualified history of the user:**

```sql
SELECT product_id, COUNT(*) AS cnt
  FROM user_rentals
 WHERE user_id = ? AND status IN ('active', 'completed')   -- D1 predicate
 GROUP BY product_id;
```

**PHP composition** (sketch — bound params, `(int)` casts, no float):

```php
public function get_catalog_for_user($user_id) {
    $user_id = (int) $user_id;

    $rows = $this->db->query(
        "SELECT p.*,
                pr.id   AS prerequisite_id,
                pr.name AS prerequisite_name
           FROM gpu_products p
           LEFT JOIN gpu_products pr ON pr.id = p.unlock_prerequisite_id
          WHERE p.is_active = 1
          ORDER BY p.id ASC"
    )->result_array();

    $counts = [];
    foreach ($this->db->query(
        "SELECT product_id, COUNT(*) AS cnt
           FROM user_rentals
          WHERE user_id = ? AND status IN ('active','completed')
          GROUP BY product_id",
        [$user_id]
    )->result() as $row) {
        $counts[(int) $row->product_id] = (int) $row->cnt;
    }

    foreach ($rows as &$p) {
        $p['user_rentals_count'] = $counts[(int) $p['id']] ?? 0;

        $max                 = (int) $p['max_per_user'];
        $p['quota_max']      = $max;
        $p['is_unlimited']   = ($max === 0);
        $p['quota_remaining'] = $p['is_unlimited']
            ? null
            : max(0, $max - $p['user_rentals_count']);
        $p['is_quota_exhausted'] = (!$p['is_unlimited']
            && $p['user_rentals_count'] >= $max);

        $prereq = ($p['prerequisite_id'] === null) ? null : (int) $p['prerequisite_id'];
        $p['is_locked'] = ($prereq !== null && !isset($counts[$prereq]));

        $p['can_rent'] = !$p['is_locked'] && !$p['is_quota_exhausted'];
    }
    unset($p);

    return $rows;
}
```

**Returned row contract (per product):**

| key | type | meaning |
|---|---|---|
| `id, name, type, price, daily_rate, duration_days, is_active, max_per_user, unlock_prerequisite_id` | as today (DB) | `p.*` passthrough |
| `prerequisite_id`, `prerequisite_name` | int \| null, string \| null | joined prerequisite (NULL when unlocked-from-start) |
| `user_rentals_count` | int | qualified lifetime rentals of this product |
| `quota_max` | int | `max_per_user` (0 = unlimited) |
| `is_unlimited` | bool | `max_per_user === 0` |
| `quota_remaining` | int \| null | `max(0, quota_max − count)`; `null` when unlimited |
| `is_locked` | bool | prerequisite set and not yet rented once |
| `is_quota_exhausted` | bool | limited and count ≥ quota_max |
| `can_rent` | bool | `!is_locked && !is_quota_exhausted` |

Empty catalog (no active products) still yields `[]` → the marketplace empty state (P4)
renders exactly as today.

### 4.2 `Rental_model::checkout_rental` — strict server-side gates (authoritative)

Current TX skeleton (unchanged parts elided): `trans_begin()` → `lock_and_get_balance()`
(first statement — per-user anchor lock `SELECT … FOR UPDATE` on `users`, serializes every
concurrent debit/checkout/withdrawal of that user) → insufficient check → `debit()`
(ledger `RENT-…`) → `user_rentals` insert → `commit`.

**New ordering inside the TX (this order is part of the contract):**

```
trans_begin()
  ├─ 1. lock_and_get_balance($user_id)            [existing — statement 1, anchor lock]
  ├─ 2. GATE 0 — fresh product snapshot (current read, AFTER lock wait):
  │      SELECT id, name, price, daily_rate, duration_days, is_active,
  │             max_per_user, unlock_prerequisite_id
  │        FROM gpu_products WHERE id = ?          (bound param)
  │      → row missing OR is_active = 0  ⇒ rollback, code 'product_unavailable',
  │        message "Sistem: Produk tidak ditemukan di database."
  ├─ 3. GATE 1 — prerequisite (only when unlock_prerequisite_id IS NOT NULL):
  │      SELECT (SELECT COUNT(*) FROM user_rentals ur
  │                WHERE ur.user_id = ? AND ur.product_id = ?           -- prereq id
  │                  AND ur.status IN ('active','completed')) AS c
  │      → c < 1 ⇒ rollback, code 'locked',
  │        message "Sistem: Paket ini masih terkunci. Sewa {prerequisite_name}
  │                 dahulu untuk membukanya."
  ├─ 4. GATE 2 — quota (only when max_per_user > 0):
  │      SELECT COUNT(*) FROM user_rentals
  │       WHERE user_id = ? AND product_id = ?
  │         AND status IN ('active','completed')
  │      → count >= max_per_user ⇒ rollback, code 'quota_exceeded',
  │        message "Sistem: Batas maksimal sewa paket ini telah tercapai (Maks. {max})."
  ├─ 5. insufficient check (fresh_balance < (int) snapshot price)  [existing, now on snapshot]
  ├─ 6. debit() + user_rentals insert (snapshot price/roi/duration) [existing]
  └─ commit
catch (Throwable) ⇒ rollback + generic 'error'       [existing]
```

Concurrency proof (why this is race-free):
- The anchor lock is taken **first** (statement 1), so all concurrent money-mutating TXs of
  the same user serialize. Gates 3–4 execute **after** the lock wait, on a fresh current
  read view ⇒ two parallel checkouts of the same product can never both observe
  `count < max` and both insert past the ceiling (the second TX reads the first's committed
  row). Same mechanism already protects overspend (C5/plan 48) and the single-pending-WD
  gate.
- Gates 0–4 run **before** any ledger write ⇒ a rejected request appends **zero**
  `wallet_ledger` rows and zero `user_rentals` rows (ledger immutability & no-partial-state
  preserved).
- Fresh snapshot (Gate 0) closes the pre-TX TOCTOU where the controller's earlier
  `get_product()` fetch could be stale (price/`is_active`/gating policy changed between
  fetch and TX). Debit and insert then use the snapshot, so snapshot consistency is total.

**Result contract extension** (docblock updated; shape unchanged):

```php
/**
 * @return array{success:bool, code:string, message:string, rental_id:int|null}
 *   code: 'ok' | 'product_unavailable' | 'locked' | 'quota_exceeded'
 *         | 'insufficient' | 'error'
 */
```

### 4.3 Controller change surface

- `application/controllers/Rentals.php` — **no change**. It already maps any
  `!$result['success']` to `flashdata('error')` = `$result['message']`, so the new friendly
  Indonesian messages flow through automatically; only the balance pre-check (pure UX) and
  the pre-fetch (`get_product`) remain, unchanged.
- `application/controllers/Marketplace.php` — one-line swap in `index()`:
  `get_all_active_products()` → `get_catalog_for_user($user_id)`; `$user_balance` fetch and
  the view variable name `$products` stay identical.

### 4.4 Out of scope (documented, untouched)

- `Admin_model::inject_rental` (operator bypass; D5), `Admin_model::cancel_rental`,
  `Admin_model::get_active_products` (admin dropdown; `SELECT *` on `gpu_products` returns
  the new columns harmlessly), admin dashboard aggregates joining `gpu_products`
  (`Admin_model.php:172,534,541,904`) — all read-only w.r.t. the new columns.
- `User_model.php:148` active-downline join — unchanged semantics.

---

## 5. USER INTERFACE POLISH (`views/marketplace/index.php`)

Theme system recap: `html.dark` class + CSS design tokens (`u-card-gpu`, `u-text`,
`u-btn-cyber`, `u-btn-ghost`, `u-flash-error`, …) with Tailwind `dark:` utilities —
components must stay adaptive in both themes. Empty state, flash alerts, and the
bottom-sheet modal scaffolding are preserved.

### 5.1 State machine for each card (computed server-side, §4.1)

| State | trigger | card look | CTA |
|---|---|---|---|
| **A — Available** | `can_rent === true` | full opacity, quota badge (emerald accent) | enabled `.btn-sewa` (`u-btn-cyber`) "Sewa Sekarang" → opens modal |
| **B — Locked 🔒** | `is_locked === true` | `opacity-60 saturate-50`, lock badge with prerequisite name | disabled button "Terkunci — Sewa {prerequisite_name} dahulu" |
| **C — Quota reached** | `is_quota_exhausted === true` | normal/`opacity-75`, muted badge | disabled button "Batas Maksimal Tercapai" |

Quota badge on **every** card (spec): limited ⇒
`Batas Sewa: Maks. {quota_max} (Tersisa: {quota_remaining})`; unlimited ⇒
`Batas Sewa: Tanpa Batas`.

### 5.2 Card markup sketch (representative; final Tailwind in implementation round)

```html
<?php foreach ($products as $product): ?>
<div class="u-card-gpu rounded-2xl p-4 shadow-sm flex flex-col
            <?= $product['is_locked'] ? 'opacity-60 saturate-50' : '' ?>">

    <div class="relative">
        <img src="https://placehold.co/400x150/f8fafc/94a3b8?text=<?= urlencode($product['name']) ?>"
             class="rounded-xl object-cover h-28 w-full mb-3" alt="<?= htmlspecialchars($product['name']) ?>">
        <?php if ($product['is_locked']): ?>
        <!-- Locked ribbon badge (top-left) -->
        <span class="absolute top-2 left-2 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg
                     bg-amber-500/90 text-white text-[10px] font-bold shadow">
            <i class="fas fa-lock text-[9px]"></i> Terkunci
        </span>
        <?php endif; ?>
    </div>

    <h3 class="text-base font-bold u-text"><?= htmlspecialchars($product['name']) ?></h3>

    <!-- Quota badge (always visible) -->
    <span class="mt-1 inline-flex items-center gap-1.5 text-[10px] font-semibold px-2.5 py-1 rounded-full w-fit
                 <?= $product['can_rent']
                     ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                     : 'bg-slate-500/10 u-muted' ?>">
        <i class="fas fa-gauge-high text-[9px]"></i>
        <?php if ($product['is_unlimited']): ?>
            Batas Sewa: Tanpa Batas
        <?php else: ?>
            Batas Sewa: Maks. <?= (int) $product['quota_max'] ?>
            (Tersisa: <?= (int) $product['quota_remaining'] ?>)
        <?php endif; ?>
    </span>

    <!-- Price / daily ROI (unchanged layout) -->

    <?php if ($product['can_rent']): ?>
        <!-- State A — only this button is .btn-sewa with data-* (JS binding contract) -->
        <button class="btn-sewa w-full h-12 u-btn-cyber rounded-xl font-bold mt-3 transition-all active:scale-[0.98]"
                data-id="<?= (int) $product['id'] ?>"
                data-name="<?= htmlspecialchars($product['name']) ?>"
                data-price="<?= (int) $product['price'] ?>">
            Sewa Sekarang
        </button>
    <?php elseif ($product['is_locked']): ?>
        <!-- State B -->
        <button type="button" disabled
                class="w-full h-12 u-btn-ghost rounded-xl font-bold mt-3 cursor-not-allowed opacity-90
                       inline-flex items-center justify-center gap-2">
            <i class="fas fa-lock text-amber-500 dark:text-amber-400"></i>
            Terkunci — Sewa <?= htmlspecialchars($product['prerequisite_name']) ?> dahulu
        </button>
    <?php else: ?>
        <!-- State C -->
        <button type="button" disabled
                class="w-full h-12 u-btn-ghost rounded-xl font-bold mt-3 cursor-not-allowed opacity-80
                       inline-flex items-center justify-center gap-2">
            <i class="fas fa-ban"></i> Batas Maksimal Tercapai
        </button>
    <?php endif; ?>
</div>
<?php endforeach; ?>
```

### 5.3 JS/modal contract (no change to the script, one guarantee)

The modal script binds `document.querySelectorAll('.btn-sewa')` and opens only when the
button carries `data-id/name/price`. State B/C buttons are plain `type="button" disabled`
elements **without** `.btn-sewa`/`data-*`, so:
- locked / quota-exhausted cards can never open the checkout sheet from the UI, and
- the existing `#form-checkout` POST (`rentals/checkout`, CSRF via `form_open`,
  `data-guard-submit` double-submit guard) is untouched.

Server gates (§4.2) remain the real authority even if the modal/POST is forged.

---

## 6. ZERO-REGRESSION SAFEGUARDS (INVARIANTS)

| # | Invariant | How this design preserves it |
|---|---|---|
| Z1 | `wallet_ledger` sole authority, append-only, deterministic `transaction_id`s | New code writes no ledger rows; gates 0–4 are read-only and run before the existing `debit()`. Rejected requests → `rollback()` with zero appended rows. |
| Z2 | Integer-IDR arithmetic (M8): `(int)` choke point, no float money | All new arithmetic is integer `COUNT`/`max`/`remaining` on `user_rentals`/`gpu_products` (`(int)` casts); snapshot `price`/`daily_rate` flow into the existing integer casts unchanged. |
| Z3 | Anchor-lock-first serialization (C5/plan 48) | Gate statements are inserted strictly AFTER `lock_and_get_balance()`; order documented in §4.2. No new lock or transaction layer. |
| Z4 | Rental lifecycle engine untouched (C2/claim_roi, M3 lazy sweep, `claimable_info`, H+1) | No change to `claim_roi`, sweep, status transitions, or eligibility SQL; gates only *read* `user_rentals` history. Completed/expired contracts keep counting (D1 lifetime). |
| Z5 | Marketplace empty-state (P4) & checkout flash UX | `[]` catalog still renders the empty state; `Rentals::checkout` mapping unchanged (§4.3). |
| Z6 | Admin console flows | Admin product dropdown/cancel/inject/aggregates unaffected (§4.4); new columns have defaults (`0`/`NULL`) so any legacy explicit-column insert still works. |
| Z7 | Canonical seed idempotency (plan/82) | `ON DUPLICATE KEY UPDATE` extended to refresh the new columns; re-running `database.sql` is safe. |
| Z8 | Framework/business doc conventions | No routes change; `php -l` on every modified PHP file (roadmap rule); bound params only, SQL confined to models; new strings centralized (single message constants in the model, exact text per §2.2). |
| Z9 | No new credentials/keys | Schema/model/view changes only — nothing env/secret related. |

---

## 7. VERIFICATION MATRIX (implementation round — manual / curl / DB)

Prep: apply §3.3 migration + §3.2 seed on the dev DB; register a fresh user; fund via the
dev deposit simulator (never in production).

| # | Scenario | Expected |
|---|---|---|
| V1 | `GET /marketplace` as fresh user (HTTP 200) | Cards 1–4 State A (badges `Maks. 1/2/3/5`, `Tersisa` = max); cards 5–8 State B with ribbon 🔒 and buttons naming prerequisite (5→"RTX 4080 Prime", 6→"RTX 4090 Pro", 7→"A100 Cloud Cluster", 8→"H100 Tensor Node"); badges `Maks. 5`/`Tanpa Batas` correct. |
| V2 | `POST rentals/checkout` `product_id=5` (locked, sufficient balance) | HTTP 302 → marketplace, flash contains "masih terkunci"; DB: no new `user_rentals` row, no new `wallet_ledger` row, `users.balance` unchanged. |
| V3 | Rent `product_id=1` (max 1) once | Success flash → redirect `rentals`; card 1 now State C (`Tersisa: 0`). Repeat POST `product_id=1` → 302 + "Batas maksimal"; balance/ledger untouched (still exactly one debit row). |
| V4 | Rent `product_id=4` once | Card 5 unlocks → State A, badge `Maks. 5 (Tersisa: 5)`, modal opens. |
| V5 | Tier independence | Products 2–3 (prereq NULL) remain available regardless of 4/5; products 7–8 stay locked until 6 resp. 7 rented once. |
| V6 | Regression: normal purchase + ROI claim | Rent an unlocked package (e.g. 2) → contract `active`, `expired_at = now + duration`; next-day `POST rentals/claim/{id}` still pays H+1 ROI via existing `claim_roi` (unchanged). |
| V7 | Tamper: `product_id` of an `is_active=0` product | 302 + "Produk tidak ditemukan di database."; zero ledger/rental rows (D6). |
| V8 | Concurrency (optional): two parallel checkouts of id 1 with balance for exactly one | Anchor lock serializes; exactly one succeeds, other gets flash error; exactly one `user_rentals` + one ledger debit. |
| V9 | Admin smoke | Product dropdown lists 8 rows (new columns ignored); soft-cancel/inject-rental still work; re-running `database.sql` seed is a no-op diff on the 8 gating rows. |
| V10 | Schema audit | `SHOW CREATE TABLE gpu_products` + §3.3 SELECT matches §2 table; `wallet_ledger` DDL untouched (`git diff --stat` shows only the 5 listed target files + this doc). |

Lint gate: `php -l` on every modified PHP file before any commit.

---

## 8. OPEN ITEMS / FOLLOW-UPS (deferred, not in this blueprint's execution)

- **Doc sync (small, recommend with implementation):** `docs/1_PRD.md` §C/D (add gating
  rules), `docs/2_ERD.md` `gpu_products` attribute list, `AGENTS.md` quick-note, and this
  plan's execution summary — keep the authoritative business spec in lockstep.
- **Admin UI for the two new fields** (create/edit product) is intentionally out of scope
  for the user-flow engine; add in a later round if product management needs it.
- Optional index `(user_id, product_id, status)` if per-user history grows (§3.4).
