# 80 — P3/P4/P5 MINOR POLISH PLAN (plan/66 follow-up)

**Task:** Close three open Minor/Polish findings from `plan/66_AUDIT_GAP_ANALYSIS_SUMMARY.md` §2:
**P3** (notifications capped 100, no pagination), **P4** (marketplace empty state missing), **P5**
(`Team::claim_level1` hardcodes "Rp 80.000").
**Mode:** Approved blueprint — awaiting explicit go-ahead before any application code is touched.
This document is the contract for the implementation round (branch e.g. `polish/P3-P5`).

---

## 0. ARCHITECTURAL OVERVIEW

| Finding | Root cause | Fix strategy | Main files |
|---|---|---|---|
| P3 | History page fetches 100 rows (`Notification.php:17` → `Notification_model::get_by_user($user_id, 100)`), view renders one unbounded flat list | CI3 pagination library (in-repo precedent `Admin::users`, `Admin.php:402–445`): model `COUNT` + page query, per-request Tailwind config, page nav in view. Header bell is **already** capped at 5 + has "Lihat semua notifikasi" link — verified, no change needed there | `application/models/Notification_model.php`, `application/controllers/Notification.php`, `application/views/notification/index.php`, `application/config/routes.php` |
| P4 | Marketplace has no empty-state branch; additionally `Product_model::get_all_active_products()` silently falls back to 4 hardcoded mock products whenever DB has 0 active rows → empty state would never render | **Decision (ask dec-ca16ebd00401bc7f): DB canonical** — seed 4 real `gpu_products` rows in `database.sql`, delete the mock array + listing fallback, add theme-adaptive empty-state card + inline SVG in the view | `database.sql`, `application/models/Product_model.php`, `application/views/marketplace/index.php` |
| P5 | L1 bonus credited from inline literal `80000` (`User_model.php:308`) and echoed as hardcoded strings in model message (`:320`) + notification (`Team.php:95`) | Single source of truth: `const User_model::LEVEL1_BONUS = 80000;` (mirrors `WAGE_TIERS` const pattern, PRD v5 fixes one-time Rp 80.000); format via `number_format(..., 0, ',', '.')`; sweep remaining view literals through one controller-injected variable | `application/models/User_model.php`, `application/controllers/Team.php`, `application/views/team/index.php` |

Conventions honored: SQL only in models w/ bound params; money = integer IDR (M8 discipline);
`defined('BASEPATH')` guard; UI language Indonesian; `Rp` + `number_format($v, 0, ',', '.')`
server-side; theme-adaptive Tailwind (`dark:` variants); no new secrets; no schema column changes.

---

## 1. P3 — NOTIFICATION PAGINATION & HEADER BELL

### 1.1 Verified current state (facts, not assumptions)
- Header dropdown is **already optimized**: `MY_Controller.php:63` calls
  `Notification_model->get_latest($user_id, 5)` (5 rows), rendered in `templates/header.php`
  (`#notif-list`, `max-h-96 overflow-y-auto`) with an existing footer link
  "Lihat semua notifikasi" → `base_url('notification')` (`header.php:355–360`).
  The plan/66 citation (`Notification.php:15 limit 100`) points at the **history page**, which is
  the real open gap. → Header bell: **no code change; regression-verify only.**
- History page today: `Notification::index()` fetches all 100 rows; view
  `notification/index.php` renders them with date grouping + `markAllRead()` JS
  (per-page DOM updates only — stays valid per page).
- No `application/config/pagination.php`; precedent is inline `$this->load->library('pagination')`
  + per-call config (`Admin::users`, `Admin.php:412–430`, Tailwind tag strings,
  `page_query_string=TRUE`, segment `per_page`). Admin's tag classes are light-only → user-side
  needs `dark:` variants.

### 1.2 Changes
1. **`Notification_model`**
   - Add `count_by_user($user_id)` → `(int)` `COUNT` on `user_notifications` (query builder, SQL in model).
   - Change `get_by_user($user_id, $limit = 100, $offset = 0)` → add `$offset` (default 0);
     `->limit($limit, $offset)`; keep `order_by('created_at','DESC')`. No cap-100 semantic remains.
   - `get_latest()` untouched (dropdown contract).
2. **`Notification::index()`**
   - `$per_page = 15;` `$offset = max(0, (int) $this->input->get('per_page', TRUE));`
   - `$total = $this->Notification_model->count_by_user($user_id);`
   - `$notifs = $this->Notification_model->get_by_user($user_id, $per_page, $offset);`
   - Pagination config (parity `Admin::users`, theme-adaptive):
     `base_url = site_url('notification')`, `total_rows`, `per_page`, `page_query_string=TRUE`,
     `query_string_segment='per_page'`, and Tailwind tag strings with both themes, e.g.
     num/prev/next: `px-3 py-1.5 text-sm rounded-lg border border-slate-200 dark:border-slate-700
     text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors`,
     current: `... bg-indigo-600 text-white font-medium`; full tag nav
     `flex items-center justify-center gap-1 mt-6`.
   - Pass `notifications`, `total`, `pagination` (string) to view.
3. **`views/notification/index.php`**
   - Render `<?= $pagination ?>` block after `#notif-container` only when `$total > $per_page`
     (keep date grouping per page — recomputed per page is acceptable; note in code comment).
   - Keep existing empty state, type maps, and `markAllRead()` untouched.
4. **`routes.php`** — register `$route['notification'] = 'notification/index';` (parity with
   `team`/`profile`/`help`; URL unchanged, future-proofs pretty-URL rule).

### 1.3 Zero-regression safeguards (P3)
- `php -l` on the 2 PHP files touched.
- curl: `GET /notification` → 200 & ≤15 items; `GET /notification?per_page=15` (page 2) → next 15;
  bogus `per_page=abc`/negative → offset clamps to 0 (no SQL error).
- POST `notification/mark_all_read` still `{success:true}` on any page.
- Header dropdown still shows 5 + footer link (no diff in `MY_Controller`/`header.php`).

---

## 2. P4 — MARKETPLACE GPU EMPTY STATE

### 2.1 Decision (approved)
DB canonical: add standard seed rows for `gpu_products` to `database.sql`, drop the hardcoded
mock array + listing fallback from `Product_model`, add a genuine empty-state component in the
view. Reachable/testable by setting all rows `is_active = 0` or having 0 rows.

### 2.2 Changes
1. **`database.sql`** — after the `gpu_products` DDL (lines 37–49; columns are `id, name, type,
   price, daily_rate, duration_days, is_refundable, is_active` — **no `description` column**, the
   view already tolerates that via `$product['description'] ?? ''`), append seed INSERTs matching
   today's mock catalog so UI parity is preserved:
   - `('NVIDIA RTX 4090 Node', 'short_term', 1500000, 45000, 30, 0, 1)`
   - `('AMD MI300X Cluster', 'long_term', 3500000, 120000, 90, 0, 1)`
   - `('Cloud VPS Enterprise', 'short_term', 750000, 22000, 30, 0, 1)`
   - `('AI Inference Pod', 'long_term', 2000000, 65000, 60, 0, 1)`
   Explicit ids 1–4 keep any pre-existing `user_rentals.product_id` references valid on fresh seeds.
2. **`Product_model`** — remove `private $mock_products`; `get_all_active_products()` returns
   `$this->db->get_where('gpu_products', ['is_active' => 1])->result_array()` directly (drop fallback).
   - **Guard step:** `get_product()` also has a mock fallback. Before removing it, verify
     `Rentals::checkout` null-product handling (expect a graceful "produk tidak ditemukan" branch);
     if safe → delete the fallback there too for consistency (no ghost-rent of a deleted product).
     If checkout does NOT null-guard → keep `get_product` DB-first + null return and add the missing
     guard in `Rentals` as part of this phase. Do NOT leave a mock path that can outlive seeds.
3. **`views/marketplace/index.php`** — wrap the product loop:
   - `<?php if (empty($products)): ?>` → empty-state component:
     - Card: `bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl
       p-8 text-center` (explicit light/dark per brief; fits `max-w-[480px]` shell).
     - Inline SVG illustration (~`w-24 h-24 mx-auto mb-4`): stylized GPU server rack / maintenance
       motif — indigo/cyan strokes, `fill="none"`, `stroke-current` classes honoring theme
       (`text-slate-300 dark:text-slate-600`); no external image host (current cards use
       `placehold.co` — do not follow that pattern here).
     - Copy: heading **"Belum Ada Paket Tersedia"**, body
       **"Saat ini seluruh unit komputasi sedang penuh atau dalam pemeliharaan. Silakan cek kembali secara berkala."**
     - Optional subtle re-check hint row (icon + "Muat Ulang" linking back to marketplace).
   - `<?php else: ?>` → existing `foreach` unchanged. Close `endif`.
4. **`Marketplace::index`** — unchanged (view-only branch on `empty($products)`).

### 2.3 Zero-regression safeguards (P4)
- `php -l` on `Product_model.php` (+ `Rentals.php` if the guard is added); SQL sanity: run the seed
  INSERTs on a scratch DB copy (or apply to dev DB after approval).
- curl `GET /marketplace`: with seeds active → exactly the same 4 cards/names/prices as today.
- Toggle all `is_active = 0` (or truncate) → 200 + empty-state card visible, no PHP warnings.
- Confirm no remaining `mock_products` reference: `grep -rn "mock_products\|mock_product" application/`.

---

## 3. P5 — DYNAMIC AMOUNT FOR LEVEL 1 CLAIM NOTIFICATION

### 3.1 Single source of truth
PRD v5 fixes the L1 one-time bonus at Rp 80.000; wage tiers already live as class constants
(`User_model::WAGE_TIERS`). Mirror that pattern (int IDR — M8 discipline).

### 3.2 Changes
1. **`User_model`** — next to `WAGE_TIERS` add `const LEVEL1_BONUS = 80000;` and use it in
   `claim_level1()`:
   - `credit(..., self::LEVEL1_BONUS, ...)` (replaces literal `80000` at `:308`);
   - success return gains `'amount' => (int) self::LEVEL1_BONUS` (parity with `claim_wage`, which
     already returns `amount`);
   - success message: `'Bonus Level 1 Rp ' . number_format(self::LEVEL1_BONUS, 0, ',', '.') .
     ' berhasil diklaim!'` (replaces `:320` literal); docblock `:258` updated.
   - Numeric amount untouched (80000 == LEVEL1_BONUS) → zero ledger/balance impact.
2. **`Team::claim_level1`** — replace the literal notification at `:95` with the dynamic form,
   mirroring the existing `claim_wage` notification style (`Team.php:167–174`):
   `'Selamat! Bonus Level 1 sebesar Rp ' . number_format((int) $result['amount'], 0, ',', '.') .
   ' telah masuk ke saldo.'` — the model result now supplies `amount`, so tier/amount changes
   propagate automatically. Title unchanged.
3. **View sweep (consistency)** — `Team::index` passes `l1_bonus` (int) + `l1_bonus_fmt`
   (`number_format(User_model::LEVEL1_BONUS, 0, ',', '.')`) and replaces the 5 hardcoded
   "Rp 80.000" literals in `views/team/index.php` (`:38, :72, :281` and the JS `:386, :392`
   label builders — inject the formatted value via `json_encode` into the inline script).
   Display-only, zero logic risk; leaves the feature fully consistent.
4. **No changes** to `claim_wage` paths or ledger logic.

### 3.3 Zero-regression safeguards (P5)
- `php -l` on `User_model.php`, `Team.php` (+ view lint).
- `grep -rn "80\.000\|80000" application/` → only `User_model` const definition remains
  (expect zero other hits under `application/`).
- curl smoke of a qualified L1 claim (dev fixture) → HTTP 200 `{success:true}` with
  `message` containing formatted `80.000`; `wallet_ledger` credit row amount unchanged (80000).
- Notification row `type='commission'` shows the dynamic amount string.

---

## 4. ZERO-REGRESSION SAFEGUARDS (CONSOLIDATED)

1. **Lint:** `php -l` every modified PHP file (model/controller/view scripts).
2. **Grep audits:** no `mock_products` remains; no `80.000`/`80000` outside the const; no
   `get_by_user(..., 100)` cap; routes registered.
3. **curl smoke** (dev server): `/notification` (+page 2 + bad page param), `/marketplace`
   (active → 4 cards; all inactive → empty state), `/team`; AJAX `mark_all_read` POST per page.
4. **Money invariance:** every touched path credits the exact same integer (80000) via
   `wallet_ledger`; only display strings change.
5. **DB:** fresh `database.sql` import yields 4 active products; existing dev DBs keep working
   (seeds are INSERTs, no column changes).
6. **Behavior parity:** header dropdown untouched (5 + link); notification empty state preserved;
   marketplace card names/prices identical pre/post; team page copy identical after sweep.
7. Commit style: Indonesian messages, per finding (P3 → P5), on branch `polish/P3-P5`.

---

## 5. IMPLEMENTATION ORDER (layered)

1. **P3 — Notification pagination**
   - model: `count_by_user()` + `get_by_user($limit, $offset)`
   - controller: `Notification::index` page query + CI pagination config (theme-adaptive Tailwind)
   - view: pagination nav (render when `total > per_page`)
   - routes: register `$route['notification']`
   - verify: lint + curl (page 1/2/bad param + mark_all_read)
2. **P4 — Marketplace empty state**
   - `database.sql`: 4 seed INSERTs for `gpu_products`
   - `Product_model`: remove mock array + listing fallback; null-guard check on `get_product`/`Rentals`
   - view: theme-adaptive empty-state card + inline SVG + Indonesian copy
   - verify: lint + grep `mock_products` + curl active/all-inactive
3. **P5 — Dynamic L1 amount**
   - `User_model`: `const LEVEL1_BONUS`, credit/message/`amount` return
   - `Team::claim_level1`: dynamic notification via `$result['amount']`
   - `Team::index` + `views/team/index.php`: inject `l1_bonus(_fmt)`, sweep literals
   - verify: lint + grep + curl claim smoke + `wallet_ledger` amount parity

*End of plan — plan/80_P3_P4_P5_MINOR_POLISH_PLAN.md. Blueprint only; no application code, views, or schema were modified.*
