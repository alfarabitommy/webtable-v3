# 88 — SIMPLIFIED ADMIN-CONTROLLED PRODUCT GATING (plan/87 EXECUTION SUMMARY)

**Status:** EXECUTED — plan/87 fully implemented. Live-DB data migration applied,
canonical `database.sql` refreshed, models/controllers/views refactored, lint &
stale-reference gates passed. Browser/session-level QA matrix items remain open
(§4) pending manual verification on a running server.

**Scope recap:** decommission prerequisite-chain gating on `gpu_products`
(`unlock_prerequisite_id` now dormant, all rows NULL); product availability is
100% admin-controlled via the `is_active` toggle; per-user lifetime purchase
limits (`max_per_user`) stay fully active (marketplace badge + ACID checkout
GATE 2). Blueprint: `plan/87_SIMPLIFIED_ADMIN_CONTROLLED_GATING_PLAN.md`.

---

## 1. EXECUTION RESULTS

### 1.1 Live-DB migration (`db_webtable` — MariaDB 12.3.2 @ 127.0.0.1:3306) ✅

Data-only, idempotent, zero destructive DDL. Actual receipt:

```
connected: server=12.3.2-MariaDB
rows_chained_before=5 updated=5 rows_chained_after=0 total_products=13
fk_present=1
```

- `UPDATE gpu_products SET unlock_prerequisite_id = NULL WHERE unlock_prerequisite_id IS NOT NULL;`
  affected **5 rows** (the canonical chain 5→4, 6→5, 7→6, 8→7 + one admin-created
  product); **0 rows** remain chained.
- `fk_gpu_products_unlock_prereq` still present (`information_schema` check = 1),
  column + index retained — FK/zero-hard-delete policy untouched.
- Total products = 13 (canonical 1–8 + admin-created rows from earlier rounds);
  migration covered all non-NULL rows regardless of id.

### 1.2 Canonical schema/seed (`database.sql`) ✅

- DDL comment above `unlock_prerequisite_id` rewritten to
  `-- DEPRECATED (plan/87): ... Column/index/FK retained non-destructively (all rows NULL)...`
  Column definition, `INDEX idx_unlock_prerequisite`, and
  `CONSTRAINT fk_gpu_products_unlock_prereq` unchanged.
- Seed block comment updated (plan/87 rationale); rows 5–8 now `NULL` in
  `unlock_prerequisite_id` (ids 1–4 were already NULL); upsert arm writes
  `unlock_prerequisite_id = NULL` (idempotent — re-running can never re-chain).
- `max_per_user` values preserved exactly (1,2,3,5,5,5,0,0 for ids 1–8).

### 1.3 Application code ✅

| File | Change |
|---|---|
| `application/models/Product_model.php` | `get_catalog_for_user()`: self-join & `prerequisite_id`/`prerequisite_name`/`is_locked` removed; query is `SELECT p.* ... WHERE is_active = 1 ORDER BY id`; per-row `can_rent = !$p['is_quota_exhausted']`; quota telemetry keys retained; docblock updated. |
| `application/models/Rental_model.php` | `checkout_rental()`: GATE 0 snapshot query slimmed (no prereq join/columns); **GATE 1 deleted** (locked rejection + `prereq_count` subquery gone); GATE 2 kept as single `own_count` count with D1 predicate; return-code list drops `'locked'`; method docblock updated. |
| `application/models/Admin_model.php` | `get_products_admin()` & `get_product_row()`: `prerequisite_name` self-joins removed; **deleted** `get_all_products_lite()` and `prereq_would_cycle()`; `_sanitize_product_fields()` whitelist no longer accepts `unlock_prerequisite_id` (case branch removed) — dormant column unwritable from any path; docblocks/comments updated. |
| `application/controllers/Admin.php` | `products()` no longer loads `prereq_options`; `_validate_product_payload()` prereq validation block removed (M8 name/type/price/ROI/duration/quota/is_active rules retained); `_product_payload_from_row()` audit snapshot no longer includes `unlock_prerequisite_id` — BEFORE/AFTER audit JSON stays symmetric. |
| `application/views/marketplace/index.php` | Locked ribbon 🔒, `<div class="relative">` wrapper, locked dimming (`opacity-60 saturate-50`) and the "Terkunci — Sewa …" disabled CTA all removed. Cards now render exactly two states: **A** "Sewa Sekarang" (`can_rent`) / **B** disabled "Batas Maksimal Tercapai" (quota). Quota badge retained verbatim (`Batas Sewa: Maks. X (Tersisa: Y)` / `Batas Sewa: Tanpa Batas`). Modal JS untouched. |
| `application/views/admin/products/index.php` | "Paket Prasyarat" table column removed (8 columns; empty-state `colspan` 9→8); "N kontrak aktif" relocated into the Kuota Sewa cell under "N dipakai (lifetime)"; prereq `<select>` removed from the create/edit modal; `data-product` JSON no longer carries `unlock_prerequisite_id`; JS cleaned (`prereqSel`, option-restore loop, self-prereq disable loop, `f_prereq` assignment deleted); info-note copy updated. |
| `plan/87_SIMPLIFIED_ADMIN_CONTROLLED_GATING_PLAN.md` | **New** — approved blueprint. |

No changes required (verified): `Rentals.php`, `Marketplace.php`, `routes.php`,
`Wallet_model.php`, `docs/1_PRD.md`.

---

## 2. VERIFICATION

### 2.1 Lint (roadmap rule)

```
$ php -l application/models/Product_model.php            → No syntax errors detected
$ php -l application/models/Rental_model.php             → No syntax errors detected
$ php -l application/models/Admin_model.php              → No syntax errors detected
$ php -l application/controllers/Admin.php               → No syntax errors detected
$ php -l application/views/marketplace/index.php         → No syntax errors detected
$ php -l application/views/admin/products/index.php      → No syntax errors detected
```

### 2.2 Stale-reference grep (functional)

```
$ grep -rn 'is_locked\|prereq_would_cycle\|get_all_products_lite\|prereq_options\|prerequisite_name\|prereq_count\|prereqSel\|f_prereq' application/
  → exit=1 (ZERO hits)
```

Remaining `unlock_prerequisite_id` mentions in `application/` are **4
comment-only** plan/87 docblock annotations explaining the dormant column
(no SQL, no calls, no property access):

- `application/controllers/Admin.php:1280,1303`
- `application/models/Admin_model.php:548,688`

### 2.3 Invariants (plan/87 §7)

- **G1** — availability gate is `is_active` alone: render path filters
  `WHERE is_active = 1`; checkout GATE 0 (in-TX, post-lock current read)
  rejects missing/inactive rows with `product_unavailable`. Fail-closed.
- **G2** — quota engine retained: `max_per_user` computed with `(int)` casts,
  badge on every marketplace card, GATE 2 enforced inside the locked TX after
  the anchor `users` row lock (no double-checkout race).
- **G3** — dormant column isolation: no logic reads it, no audit/JSON
  serializes it, write whitelist excludes it; column/index/FK physically intact.
- **Z1 / M8 / M4 / M5 / D1** — no ledger-path change (rejections still roll back
  before any write; `wallet_ledger` sole ledger); zero floating-point arithmetic
  introduced (all comparisons `(int)`); admin mutators remain POST-only with
  CSRF and atomic M5 audit; quota predicate D1 (`active`/`completed`) unchanged.
- **No destructive DDL** executed; seed re-runnable/idempotent.

---

## 3. CHANGE INVENTORY (git)

plan/87 deltas in this working tree (relative to the state before this run):

```
 M application/controllers/Admin.php
 M application/models/Admin_model.php
 M application/models/Product_model.php
 M application/models/Rental_model.php
 M application/views/marketplace/index.php
 M database.sql
?? plan/87_SIMPLIFIED_ADMIN_CONTROLLED_GATING_PLAN.md
?? plan/88_SIMPLIFIED_ADMIN_CONTROLLED_GATING_SUMMARY.md   (this file)
```

Note: `application/views/admin/products/index.php` is new/untracked as part of
the still-uncommitted plan/85 admin-CRUD work already present in the tree
(alongside `plan/85`/`plan/86` docs and `routes.php`/`sidebar.php` mods); it was
edited in place for plan/87. No commit was requested; changes are left staged
for the user's review/commit.

Suggested commit message (Indonesian, repo style):
`plan/87: dekomisioning gating prasyarat — ketersediaan produk 100% via toggle admin (is_active)`

---

## 4. UNVERIFIED / RISKS / FOLLOW-UPS

Open verification-matrix rows (plan/87 §8 #6–#18) that require a running web
server + logged-in sessions or POST round-trips — **not executed in this run**:
marketplace render states (inactive hidden, A/B cards, badge), admin table &
modal render, admin create/update/toggle POST round-trips incl. M5 audit payload
content, and checkout TX emulation for `product_unavailable` / `quota_exceeded`
/ `insufficient` (zero-rows-persisted). Recommended manual QA per the plan/87
matrix; DB-backed gate checks can follow the plan/84 §2.2 TX+rollback pattern.

Risks/notes:
- Live DB has 13 products (5 beyond the canonical 8) — all chained ones were
  nulled; admin-created rows keep their other attributes untouched.
- No schema-level enforcement prevents future manual re-population of
  `unlock_prerequisite_id` via raw SQL; app layer is fully inert to it. Full
  `DROP COLUMN` remains an optional future release (plan/87 §3.6).
