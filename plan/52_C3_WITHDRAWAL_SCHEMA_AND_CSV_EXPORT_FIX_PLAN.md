# PLAN 52 — C3: Withdrawal Schema Drift & Admin CSV Export Fix

- **Status:** DRAFT — awaiting approval. No code/schema changes applied yet.
- **Audit source:** `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` §2-C3 (CRITICAL).
- **Scope:** `withdrawals` schema normalization, `Wallet_model::create_withdrawal()` ingestion, admin CSV export resilience (withdrawals + rentals), one-time legacy backfill.
- **Out of scope:** C4/C5/C6/C7, M1 window/max rules (only the fee tiers C3 needs are handled), `users.balance` source-of-truth debate.

---

## 1. Diagnosis Summary (evidence)

### 1.1 Schema divergence — three different truths

| Source | `gross/fee/net_amount` | `amount` | `wd_number` | `bank_name/account_number` |
|---|---|---|---|---|
| `database.sql:112-127` (canonical) | `DECIMAL(15,2) NOT NULL`, **no DEFAULT** | **column absent** | **column absent** | n/a (in `bank_accounts:99-100`) |
| `database_seed.sql:26-30` (reconcile ALTERs) | (unchanged, still no DEFAULT) | added `NOT NULL DEFAULT 0.00` | added `VARCHAR(50) NULL`, `UNIQUE uk_wd_number` | n/a |
| Live DB (dev, seed-applied) | NOT NULL, no DEFAULT unless custom-migrated | exists | exists | in `bank_accounts` |

- **Canonical `database.sql` never gained `amount`/`wd_number`** — they exist only via the seed's guarded ALTERs. A fresh install from `database.sql` alone has a `withdrawals` table the code cannot write to (unknown column `amount`).
- **Seed rows (`database_seed.sql:141-151`) populate all four financial columns** (`amount` = `gross_amount`, plus fee/net per PRD tier), so legacy/seed data looks "complete" — masking the fact that **new rows are never written that way**.
- Audit caveat: verify live DDL (`SHOW CREATE TABLE withdrawals`) — if a live migration added defaults, the severity is pure repo-file drift; either way the repo files and the code disagree.

### 1.2 Code vs schema — the broken insert

`application/models/Wallet_model.php:175-181` (`create_withdrawal()`) inserts only:

```php
'user_id' => ..., 'wd_number' => ..., 'amount' => $amount,
'bank_account_id' => ..., 'status' => 'pending',
```

On the canonical/seed schema (`gross_amount/fee_amount/net_amount NOT NULL`, no default) this is a **MySQL strict-mode NOT NULL violation** → `trans_status() === false` → withdrawal feature fails end-to-end. `gross/fee/net` are **never computed anywhere** in the app (M1 note: fee tiers missing from code; the only fee preview is cosmetic JS in `application/views/wallet/withdraw.php:119` using a flat 5%).

### 1.3 CSV export — two queries reference columns that don't exist

`application/models/Admin_model.php`:

- `get_all_withdrawals()` (`:597-603`): `select('... bank_name, account_number ...')` **from `withdrawals`** → those columns live in `bank_accounts` → SQL error → HTTP 500, no download.
- `get_active_rentals()` (`:588-595`): `select('... product_name ...')` **from `user_rentals`** → column lives in `gpu_products.name` (FK `user_rentals.product_id`) → SQL error → HTTP 500.
- `get_all_ledger()` (`:580-586`): valid.

`application/controllers/Admin.php:970-1026` (`export_csv()`): streaming via `fputcsv`, `Content-Type: text/csv`, BOM for Excel — the mechanism is sound. Weaknesses:
- header array and selected columns are hand-maintained; row values are dumped in array order without an explicit column map (order-sensitive).
- No user identity (`users.phone`), no `account_holder`, no `processed_at`, no `gross/fee/net` breakdown in the withdrawals export.
- **No legacy fallback**: a row whose `gross_amount` was stored as `0.00` would export `0` as the principal figure instead of falling back to `amount`.

### 1.4 Authoritative business math (docs/1_PRD.md §121-125 + seed comments + approved decisions)

- `gross_amount` — nominal penarikan yang diminta; **dana yang didebit dari saldo** (PRD: "Dana yang dikurangi dari saldo user adalah gross_amount").
- `fee_amount` — biaya penarikan = **pct(gross) + Rp 6.500**, pct dari tier half-open `[min, max)` (keputusan disetujui: batas masuk ke tier lebih tinggi/lebih diskon).
- `net_amount` = `gross_amount − fee_amount` — nominal bersih yang ditransfer ke rekening user.
- `amount` — kolom sinkronisasi backward-compat; **selalu = gross_amount** untuk row baru (seed legacy sudah mengikuti pola ini).
- Ledger: debit `amount`(= gross) saat request; refund penuh `amount` saat decline (`Admin.php:201-206`) — konsisten dengan "gross didebit dari saldo".

**Tier fee table (single source of truth):**

| Tier range (IDR, half-open) | pct |
|---|---|
| `[100.000, 500.000)` — PRD floor baris "20.000~500.000"; effective floor = min WD 100.000 | 10% |
| `[500.000, 1.000.000)` | 7,5% |
| `[1.000.000, 2.000.000)` | 6,5% |
| `[2.000.000, 5.000.000)` | 5% |
| `[5.000.000, 10.000.000)` | 4% |
| `[10.000.000, 50.000.000]` (max WD) | 3% |

Integer math (deterministic): `fee_base = floor(gross × bps / 10000)` with bps = 1000/750/650/500/400/300; `fee_amount = fee_base + 6500`; `net_amount = gross − fee_amount`.

**Consequence of the approved boundary rule:** exactly Rp 5.000.000 → 4% → fee **Rp 206.500**, net **Rp 4.793.500**. The current seed row 1 (`database_seed.sql:137,144`) and its comment (5jt → 5% → 256.500 / 4.743.500) become **inconsistent with the rule** and must be re-aligned (see §5.4) so seed data does not contradict fresh inserts.

---

## 2. Fix Strategy (architectural)

1. **One canonical schema** — `database.sql` becomes the single DDL truth (`amount`, `wd_number`, `uk_wd_number`, safe `DEFAULT`s) identical in shape to the seed-migrated live DB; guarded migration ALTERs bring live DBs to that shape.
2. **One fee authority** — tier table lives in a plain PHP config included by both the app and the standalone backfill script; `Wallet_model` computes `gross/fee/net/amount` atomically inside the existing C5 anchor-locked transaction.
3. **One backfill path** — idempotent standalone runner (mirrors `scripts/seed_database.php` pattern) recomputes `fee/net/gross` from `amount` for legacy rows where `gross_amount = 0`/`NULL` (or fee/net 0/NULL).
4. **One resilient export path** — model queries select from real columns via LEFT JOINs (`users`, `bank_accounts`, `gpu_products`); controller maps columns to headers explicitly (order-independent), formats money/timestamps, and applies legacy fallbacks (`NULLIF(gross_amount,0) → amount` at SQL level; PHP recompute of fee/net when stored value is 0/NULL).

---

## 3. Target Schema (canonical `database.sql`)

Rewrite the `withdrawals` DDL (`database.sql:112-127`) to:

```sql
CREATE TABLE IF NOT EXISTS `withdrawals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `bank_account_id` BIGINT UNSIGNED NOT NULL,
  `wd_number` VARCHAR(50) NULL DEFAULT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `gross_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `fee_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('pending','processing','success','failed') NOT NULL DEFAULT 'pending',
  `remark` VARCHAR(255) DEFAULT NULL,
  `processed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wd_number` (`wd_number`),
  CONSTRAINT `fk_withdrawals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_withdrawals_bank` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Notes:
- Defaults `0.00` are **safety nets only** — the model always writes real values (defense-in-depth for strict mode; legacy rows that slipped through no longer break reads).
- `amount` semantics: `amount == gross_amount` on every row the app creates; kept for backward compatibility with views/history/notifications/audit that read `wd->amount`.
- `wd_number` nullable + unique (seed rows and code always supply it; nullable preserves historical flexibility).

### 3.1 Guarded migration ALTERs (live DB)

```sql
-- [RECONCILE] (already in database_seed.sql:26-30 — keep)
ALTER TABLE `withdrawals` ADD COLUMN `wd_number` VARCHAR(50) NULL DEFAULT NULL;
ALTER TABLE `withdrawals` ADD COLUMN `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `withdrawals` ADD UNIQUE KEY `uk_wd_number` (`wd_number`);

-- [RECONCILE] NEW — safe defaults for strict-mode resilience
ALTER TABLE `withdrawals` ALTER COLUMN `gross_amount` SET DEFAULT 0.00;
ALTER TABLE `withdrawals` ALTER COLUMN `fee_amount`    SET DEFAULT 0.00;
ALTER TABLE `withdrawals` ALTER COLUMN `net_amount`    SET DEFAULT 0.00;
```

The runner must guard each against `information_schema` (column exists / default already set) exactly like `database_seed.sql`'s existing reconcile pattern; `ALTER COLUMN ... SET DEFAULT` is metadata-only, safe on existing rows.

---

## 4. Fee Authority (single source)

### 4.1 New file `application/config/withdrawal_fees.php`

Plain PHP returning an array (no CI dependency → reusable by the standalone backfill script):

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');
// PRD docs/1_PRD.md §121-125. Half-open [min, max) — boundary belongs to the
// higher (more discounted) tier. bps = basis points of the pct component.
// Fixed component Rp 6.500 per withdrawal. IDR only, integer math.
return [
    'fixed_fee' => 6500,
    'tiers'     => [
        // [min, max_exclusive], bps  (10% .. 3%)
        [100000,      500000,   1000],
        [500000,     1000000,    750],
        [1000000,    2000000,    650],
        [2000000,    5000000,    500],
        [5000000,   10000000,    400],
        [10000000,   50000001,   300], // max WD 50jt → exclusive cap 50.000.001
    ],
];
```

### 4.2 Fee calculator — `Wallet_model::calculate_withdrawal_fee(int $gross): array`

```php
/**
 * PRD fee tier (plan/52 §1.4). Integer IDR math.
 * @return array{fee:int, net:int, bps:int}
 *   fee = floor(gross*bps/10000) + fixed_fee; net = gross - fee.
 */
public function calculate_withdrawal_fee($gross) {
    $cfg = config_item('withdrawal_fees');           // loaded in __construct
    $bps = end($cfg['tiers'])[2];                    // default: highest tier
    foreach ($cfg['tiers'] as [$min, $max, $t_bps]) {
        if ($gross >= $min && $gross < $max) { $bps = $t_bps; break; }
    }
    $fee = (int) floor($gross * $bps / 10000) + (int) $cfg['fixed_fee'];
    return ['fee' => $fee, 'net' => (int) $gross - $fee, 'bps' => $bps];
}
```

Load config in `Wallet_model::__construct` (or CI `autoload`). Controllers never compute fees.

---

## 5. Code Changes (on approval)

### 5.1 `Wallet_model::create_withdrawal()` — ingest all financial columns atomically

Inside the existing `trans_begin()` block (`Wallet_model.php:143-201`), **after** the C5 guards (balance / pending / daily-limit) and **before** the insert:

```php
$fee = $this->calculate_withdrawal_fee((int) $amount);

$this->db->insert('withdrawals', [
    'user_id'          => $user_id,
    'wd_number'        => $wd_number,
    'amount'           => $amount,             // mirror == gross (backward-compat)
    'gross_amount'     => $amount,
    'fee_amount'       => $fee['fee'],
    'net_amount'       => $fee['net'],
    'bank_account_id'  => $bank_account_id,
    'status'           => 'pending',
]);
```

- Ledger debit stays `amount` (= gross) — semantics unchanged (`Wallet_model.php:184-190`).
- `amount` is the sanitized integer from the controller (`Wallet.php:178`); `floor` + integer casts guarantee no fractional IDR and no strict-mode surprises.
- **Balance sufficiency must compare against `gross` (the actual debit), not net** — currently `$fresh_balance < $amount` compares gross-to-gross, which remains correct because the debit is `gross == amount`. Document this invariant in the docblock so a future "debit net instead" change is caught.

### 5.2 `Admin_model` export queries — real columns via LEFT JOIN

```php
public function get_all_withdrawals() {
    return $this->db->select(
            'w.id, w.wd_number, w.user_id, u.phone,
             w.amount, w.gross_amount, w.fee_amount, w.net_amount,
             ba.bank_name, ba.account_number, ba.account_holder,
             w.status, w.remark, w.processed_at, w.created_at')
        ->from('withdrawals w')
        ->join('users u',          'u.id  = w.user_id',         'left')
        ->join('bank_accounts ba', 'ba.id = w.bank_account_id', 'left')
        ->order_by('w.created_at', 'DESC')
        ->get()->result_array();
}

public function get_active_rentals() {
    return $this->db->select(
            'ur.id, ur.user_id, u.phone, g.name AS product_name,
             ur.purchase_price, ur.daily_roi, ur.days_processed,
             ur.total_days, ur.status, ur.created_at')
        ->from('user_rentals ur')
        ->join('users u',        'u.id = ur.user_id',    'left')
        ->join('gpu_products g', 'g.id = ur.product_id', 'left')
        ->where('ur.status', 'active')
        ->order_by('ur.created_at', 'DESC')
        ->get()->result_array();
}
```

Legacy-resilient fallback at SQL level for the principal figure: `COALESCE(NULLIF(w.gross_amount, 0), w.amount) AS gross_eff` — see §5.3 for the chosen split (controller recomputes fee/net display values; principal fallback via the query alias).

### 5.3 `Admin::export_csv()` — resilient formatter

Refactor the export loop (`Admin.php:1017-1022`) into an explicit **column map** per type instead of dumping raw arrays:

- Keep streaming + headers + BOM as-is (mechanism is correct).
- Withdrawals ordered columns, e.g.: `ID, WD Number, User ID, Phone, Gross (Rp), Fee (Rp), Net (Rp), Bank Name, Account Number, Account Holder, Status, Processed At, Created At`.
- Map each row explicitly (order-independent, immune to future SELECT changes); never `fputcsv($fp, $row)` on a raw row.

```php
$gross = (float) ($row['gross_eff'] ?? $row['amount'] ?? 0);   // legacy fallback
$fee   = (float) ($row['fee_amount'] ?? 0);
$net   = (float) ($row['net_amount'] ?? 0);
if ($gross > 0 && (!$row['fee_amount'] || !$row['net_amount'])) {
    $calc = $this->Wallet_model->calculate_withdrawal_fee((int) $gross); // read-side recompute
    $fee = $calc['fee']; $net = $calc['net'];
}
```

- IDR figures exported as **raw integers** (`Rp` prefix and `number_format` are UI-only — CSV consumers need machine-readable values; document with a leading `#` comment row). Timestamps formatted `Y-m-d H:i`.
- No output before headers; `exit` after flush (already present); fix root causes so no notices/warnings appear.
- Load `Wallet_model` inside `export_csv` only for the withdrawals branch.

### 5.4 Seed re-alignment (`database_seed.sql`) — keep seed consistent with the rule

- Row 1 comment `:137` → `5jt -> 4% + 6.500 (fee 206.500, net 4.793.500)`.
- Row 1 values `:144` → `fee_amount 206500.00`, `net_amount 4793500.00` (amount/gross unchanged at 5000000.00).
- Ledger rows derived from withdrawals use `amount` — unaffected. `wallet_ledger`/audit/notification amounts read `amount` — unaffected (only stored fee/net change).

### 5.5 Optional cosmetic (recommended, low risk)

`application/views/wallet/withdraw.php:119-120` shows a flat 5% fee preview. Replace with a server-fed estimate (controller passes fee/net for the tier, or JS mirror of the tier table) so users see the PRD-accurate estimate. Mark display-only; authority stays in the model.

---

## 6. Backfill Migration (idempotent)

### 6.1 New standalone runner `scripts/backfill_withdrawal_fees.php`

Mirror `scripts/seed_database.php` conventions (`--verify` / `--apply` / `--check`, credentials parsed from `application/config/database.php`, exit codes 0/1/2/3). It must:

1. **Load the fee table from `application/config/withdrawal_fees.php`** (single source — no logic duplication).
2. Select legacy rows:
   ```sql
   SELECT id, amount, gross_amount, fee_amount, net_amount
   FROM withdrawals
   WHERE gross_amount IS NULL OR gross_amount = 0
      OR fee_amount    IS NULL OR fee_amount    = 0
      OR net_amount    IS NULL OR net_amount    = 0;
   ```
3. For each row: `gross = (amount > 0) ? amount : gross_amount`; compute fee/net via the config table (same integer math as §4.2); `UPDATE withdrawals SET gross_amount = ?, fee_amount = ?, net_amount = ? WHERE id = ?`.
4. Wrap all updates in one transaction; print a before/after diff table in `--verify`.
5. Rows with `amount = 0` AND gross = 0 (fully empty) are reported, not silently zeroed.

Re-running is a no-op (guarded WHERE). Note: seed row 1 (5jt, already 5% fee 256.500) is **not** a backfill candidate (gross ≠ 0) — it is corrected by §5.4 so it matches the approved rule.

### 6.2 Execution order (deployment checklist)

1. `php -l` all touched PHP files (roadmap rule).
2. Apply guarded ALTERs (or re-run seed runner with the new reconcile section) — adds `amount`/`wd_number`/defaults if missing.
3. `php scripts/backfill_withdrawal_fees.php --verify`, then `--apply`, then `--check`.
4. Deploy code changes (config + Wallet_model + Admin_model + Admin controller + optional view).
5. Run Test Cases 1 & 2 (§7).

---

## 7. Verification & Testing Protocol

Static:
- `php -l` on every modified/new PHP file: `application/config/withdrawal_fees.php`, `application/models/Wallet_model.php`, `application/models/Admin_model.php`, `application/controllers/Admin.php`, `scripts/backfill_withdrawal_fees.php`, optional `application/views/wallet/withdraw.php` (and `Wallet.php` if touched).
- Schema: `SHOW CREATE TABLE withdrawals;` on live DB vs §3 target (via a small PHP/mysqli check or mysql client) — confirm columns + defaults + `uk_wd_number`.

**Test Case 1 — new withdrawal persists all four figures (strict mode):**
1. Seed/ensure a user with `wallet_ledger` balance ≥ requested gross; bind bank; no pending WD.
2. POST `wallet/process_withdraw` with amount = **Rp 5.000.000** (boundary case) as the session user.
3. Assert: redirect 302 → `wallet`; new `withdrawals` row has `amount = 5000000.00`, `gross_amount = 5000000.00`, `fee_amount = 206500.00`, `net_amount = 4793500.00`, `status = 'pending'`, non-null `wd_number`; exactly one matching debit row in `wallet_ledger` (`amount = 5000000.00`).
4. Repeat for Rp 500.000 → fee 44.000 / net 456.000 and Rp 49.900.000 → 3%+6.500 (fee 1.503.500, net 48.396.500) to cover three tiers.
5. Assert `trans_status()` true (no strict-mode error), no SQL error in logs, and `has_pending_withdrawal()` blocks a second submit.

**Test Case 2 — admin CSV export integrity:**
1. Login as admin (`/control-panel`), GET `admin/export_csv/withdrawals`.
2. Assert: HTTP 200; `Content-Type: text/csv`; BOM present; header line contains WD Number / Phone / Gross / Fee / Net / Account Holder / Status / Processed At / Created At; **no PHP notice/warning** in output or log (queries hit real columns only).
3. Assert financial correctness for **both** row populations: a legacy row (gross 0.00/NULL pre-backfill, if any survives) exports gross = amount and fee/net recomputed; a newly created row (Test Case 1) exports its stored 206.500 / 4.793.500.
4. Same smoke for `export_csv/rentals` (Product Name from `gpu_products`) and `export_csv/ledger` (unchanged).
5. Row count == DB row count per type; column count per row == header count (explicit map guarantees).

Manual browser spot-check (dev): Wallet → request WD (boundary 5jt) → Admin → history/withdrawals renders; admin withdrawal detail shows gross/fee/net; CSV opens cleanly in Excel with UTF-8 BOM.

---

## 8. Risks / Notes

- **Seed row 1 values change** (fee/net) to obey the approved half-open rule — ledger-neutral (ledger/notifications/audit all read `amount`).
- Live-DB drift must be confirmed before execution (§1.1) — if the live table already has the columns/defaults, migration ALTERs are guarded no-ops.
- `has_reached_daily_wd_limit()` uses MySQL `CURDATE()` (M1, timezone mismatch) — **not** part of C3; do not touch here.
- Fee config keying: fees are PRD spec constants → config file is authoritative; if `site_settings`-driven fees are ever wanted, revisit under M1.
- Export IDR figures are raw integers by design (machine-readable); add a `#` note row documenting columns and that values are IDR without separators.

## 9. Files Touched (on approval)

| File | Change |
|---|---|
| `database.sql` | canonical `withdrawals` DDL: +`wd_number`, +`amount`, +`uk_wd_number`, +DEFAULT 0.00 on gross/fee/net |
| `database_seed.sql` | reconcile ALTERs for defaults; re-align row 1 (5jt → 4%) + comment |
| `application/config/withdrawal_fees.php` | **new** — tier table (single source) |
| `application/models/Wallet_model.php` | `calculate_withdrawal_fee()`; `create_withdrawal()` inserts gross/fee/net/amount |
| `application/models/Admin_model.php` | `get_all_withdrawals()` + `get_active_rentals()` LEFT JOIN fixes + phone/columns |
| `application/controllers/Admin.php` | `export_csv()` explicit column map + legacy fallback + fee recompute |
| `scripts/backfill_withdrawal_fees.php` | **new** — idempotent legacy backfill runner |
| `application/views/wallet/withdraw.php` | optional: PRD-accurate fee estimate preview (display-only) |

## 10. Approval Gate

Awaiting explicit approval before any code/schema modification. Approved decisions already captured: **dec-30928987d7ae1c74** — (1) half-open fee tiers `[min, max)`, boundary → higher tier (Rp 5.000.000 → 4% → fee Rp 206.500); (2) idempotent backfill migration + read-side fallback in export.
