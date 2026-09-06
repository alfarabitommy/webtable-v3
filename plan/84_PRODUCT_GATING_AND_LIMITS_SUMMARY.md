# 84 — PRODUCT GATING & PURCHASE LIMITS ENGINE (plan/83 EXECUTION SUMMARY)

**Task:** Full Phase 1 implementation of the approved blueprint `plan/83` — progressive
unlock (gating) + per-user lifetime purchase limits for the 8 commercial GPU packages.
**Mode:** EXECUTION round on top of `plan/83`; blueprint unchanged.
**Decision applied:** user decision `dec-32b98a3e676c190a` — gating policy values applied
**in place** to the active lineup of the live DB (commercial rows at `id 5–12`), prerequisite
chain linked dynamically by package name; legacy rows `id 1–4` untouched to preserve
historical rental integrity; `database.sql` keeps the canonical `id 1–8` layout for fresh
production installs.

---

## 1. EXECUTION RESULTS

### 1.1 Database migration (live `db_webtable` — MariaDB 12.3.2 @ 127.0.0.1:3306) ✅

Idempotent `information_schema`-guarded migration executed via PHP `mysqli` (no mysql CLI
in sandbox). Result:

| Change | Result |
|---|---|
| `ADD COLUMN max_per_user INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_refundable` | ✅ OK |
| `ADD COLUMN unlock_prerequisite_id INT UNSIGNED NULL DEFAULT NULL AFTER max_per_user` | ✅ OK |
| `ADD INDEX idx_unlock_prerequisite (unlock_prerequisite_id)` | ✅ OK |
| `ADD CONSTRAINT fk_gpu_products_unlock_prereq FOREIGN KEY (unlock_prerequisite_id) REFERENCES gpu_products(id) ON DELETE RESTRICT ON UPDATE RESTRICT` | ✅ OK |

Verified via `SHOW COLUMNS` / `SHOW INDEX` / `information_schema.REFERENTIAL_CONSTRAINTS`:
columns present (`max_per_user` NOT NULL default `0`; `unlock_prerequisite_id` NULLable,
`MUL`), FK `fk_gpu_products_unlock_prereq` RESTRICT/RESTRICT → `gpu_products(id)`.

### 1.2 Gating policy values (live DB — in place, decision dec-32b98a3e676c190a) ✅

`UPDATE … JOIN` by package name (8 rows, `affected=8`), dynamic prerequisite chain over the
actual live ids. Verified state of the active lineup:

| live id | name | max_per_user | unlock_prerequisite_id |
|---|---|---|---|
| 5 | RTX 3060 Starter | 1 | NULL |
| 6 | RTX 4060 Lite | 2 | NULL |
| 7 | RTX 4070 Basic | 3 | NULL |
| 8 | RTX 4080 Prime | 5 | NULL |
| 9 | RTX 4090 Pro | 5 | 8 |
| 10 | A100 Cloud Cluster | 5 | 9 |
| 11 | H100 Tensor Node | 0 | 10 |
| 12 | H200 Sovereign | 0 | 11 |

Legacy rows `id 1–4` (`is_active = 0`) untouched — new columns remain at defaults
(`0` / `NULL`); all 39 historical `user_rentals` rows intact (count re-verified = 39).

### 1.3 Canonical schema/seed (`database.sql`) ✅

- `CREATE TABLE gpu_products` extended: `max_per_user` + `unlock_prerequisite_id` columns
  (between `is_refundable` and `is_active`), `idx_unlock_prerequisite`, self-referencing FK
  `fk_gpu_products_unlock_prereq` (RESTRICT/RESTRICT).
- Canonical 8-tier seed (plan/82 + plan/83) rewritten: column list/values gain the two
  fields and the `ON DUPLICATE KEY UPDATE` arm refreshes them, so every re-run re-applies
  the canonical gating policy: ids 1–4 quota `1/2/3/5` prereq NULL; id 5 `(5, 4)`; id 6
  `(5, 5)`; id 7 `(0, 6)`; id 8 `(0, 7)`.

### 1.4 Application code ✅

| File | Change |
|---|---|
| `application/models/Product_model.php` | New `get_catalog_for_user($user_id)` — Query A (active products `LEFT JOIN` self for `prerequisite_id`/`prerequisite_name`, `ORDER BY id`), Query B (one `GROUP BY product_id` aggregate over the user's `status IN ('active','completed')` rentals), PHP composition adding `user_rentals_count`, `quota_max`, `is_unlimited`, `quota_remaining`, `is_locked`, `is_quota_exhausted`, `can_rent`. Display-only; docblock states the authority lives in the checkout TX. |
| `application/models/Rental_model.php` | `checkout_rental`: **GATE 0** fresh product snapshot (current read after anchor-lock wait; missing row or `is_active = 0` → `product_unavailable`), **GATE 1** prerequisite ≥1 qualified rental (`locked`), **GATE 2** quota `< max_per_user` (`quota_exceeded`) — all inside the ACID TX after `lock_and_get_balance()` and **before** any ledger write; debit/insert now consume the fresh snapshot. Exact Indonesian flash messages per plan/83 §2.2. Docblock return codes extended. |
| `application/controllers/Marketplace.php` | `index()` now calls `Product_model::get_catalog_for_user($user_id)`; `$user_balance` + view var name unchanged. |
| `application/views/marketplace/index.php` | Card rework: quota badge on every card (`Batas Sewa: Maks. X (Tersisa: Y)` / `Batas Sewa: Tanpa Batas`, emerald when purchasable, muted otherwise); State A `.btn-sewa` "Sewa Sekarang" (only buyable cards carry `data-id/name/price` → modal JS binding contract preserved); State B locked (`opacity-60 saturate-50`, amber 🔒 ribbon badge, disabled ghost button `Terkunci — Sewa {prasyarat} dahulu`); State C disabled `Batas Maksimal Tercapai`. Full dark/light adaptivity via existing `u-*` design tokens + `dark:` utilities. Empty state & bottom-sheet modal untouched. |

`Rentals.php` controller required **no change** (generic `!success → flashdata(error)`
already surfaces the new messages).

---

## 2. VERIFICATION

### 2.1 Lint (roadmap rule)

```
php -l application/models/Product_model.php    → No syntax errors detected
php -l application/models/Rental_model.php     → No syntax errors detected
php -l application/controllers/Marketplace.php → No syntax errors detected
php -l application/views/marketplace/index.php → No syntax errors detected
```

### 2.2 Gate semantics (live DB emulation, TX + rollback — zero rows persisted)

Replicating the exact catalog + gate SQL added to the models, against real users (who hold
rentals only on legacy products 1–4):

```
baseline  p5(3060) own=0     => PASS gate2 (available)
baseline  p9(4090Pro) prereq8=0 => LOCKED (gate1 blocks)
simulated p5 rented once     => own=1 → QUOTA EXHAUSTED (gate2 blocks re-rent)
simulated p8 rented once     => p9 prereq8=1 → UNLOCKED (gate1 passes)
rolled back — no rows persisted
```

All four sampled users render the expected catalog: ids 5–8 `AVAIL`, ids 9–12 `LOCKED`
with correct prerequisite names. `user_rentals` total re-verified at 39 after rollback.

### 2.3 Invariants (plan/83 §6 Z1–Z9)

| # | Invariant | Status |
|---|---|---|
| Z1 | `wallet_ledger` sole authority / immutable / deterministic tx ids | ✅ Gates are read-only and run before `debit()`; rejections append zero rows (verified by rollback test pattern & code order). |
| Z2 | Integer-IDR arithmetic, no float money | ✅ All new math is `COUNT`/`(int)` casts/`max(0, …)` on integer columns; snapshot price/roi flow into existing `(int)` choke points; no float introduced (`git diff` review). |
| Z3 | Anchor-lock-first serialization (C5) | ✅ Gates inserted strictly after `lock_and_get_balance()`; no new lock/TX layer. |
| Z4 | Rental lifecycle engine untouched (claim_roi / lazy sweep / claimable_info / H+1) | ✅ No changes to those methods; gates only read rental history. |
| Z5 | Marketplace empty-state & checkout flash UX | ✅ `[]` catalog path unchanged; `Rentals::checkout` untouched. |
| Z6 | Admin console flows | ✅ `Admin_model::inject_rental`/`cancel_rental`/dropdown untouched; new columns defaulted so legacy inserts work. |
| Z7 | Canonical seed idempotency | ✅ Upsert arm refreshes new columns; re-runnable. |
| Z8 | Conventions | ✅ SQL confined to models w/ bound params; no routes change; lint clean; messages centralized per plan/83 §2.2. |
| Z9 | No new credentials | ✅ No env/secret surface touched. |

---

## 3. CHANGE INVENTORY (git)

```
 M application/controllers/Marketplace.php
 M application/models/Product_model.php
 M application/models/Rental_model.php
 M application/views/marketplace/index.php
 M database.sql
?? plan/83_PRODUCT_GATING_AND_LIMITS_PLAN.md   (prior blueprint round)
(plan/84 — this document)
```
Diff stat: 5 files, +238/−42. No other files touched; no commits made (pending user
review / branch naming per roadmap rule 4).

---

## 4. UNVERIFIED / RISKS / FOLLOW-UPS

- **Runtime HTTP smoke (V1–V9 of plan/83 §7) not executed:** this sandbox has no Apache
  vhost / `synapse.test` resolution / web session harness, so end-to-end browser flows
  (`GET /marketplace` render of the three states, CSRF checkout POSTs) were **not** run —
  verification was done at SQL + lint + code-review level. Run the matrix against the dev
  server before merge.
- **Live-DB ↔ canonical layout divergence (pre-existing):** this `db_webtable` hosts the
  commercial lineup at `id 5–12` (legacy 1–4 inactive), while `database.sql` canonicalizes
  `id 1–8`. By user decision, values were applied in place; any future full re-seed of this
  DB with `database.sql` will repurpose ids 1–4 (plan/82 semantics) and must be treated as
  a deliberate migration.
- **Recommended doc sync (not executed in this round):** `docs/1_PRD.md` §C/D, `docs/2_ERD.md`
  `gpu_products` attributes, `AGENTS.md` quick-note for the gating engine.
- Optional perf index `(user_id, product_id, status)` only if per-user history grows
  (plan/83 §3.4) — not added.
