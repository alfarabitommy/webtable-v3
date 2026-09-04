# PLAN 56 — M1: Withdrawal Operational Rules & Dynamic Financial Configuration

- **Status:** DRAFT — blueprint approved for file persistence; **implementation NOT started** (awaiting explicit execution prompt).
- **Audit source:** `plan/37_FULL_SYSTEM_AUDIT_REPORT.md` §3-M1 (MEDIUM / functional).
- **Spec source:** `docs/1_PRD.md` §121–125 (withdrawal window / min-max / fee tiers), decision `dec-30928987d7ae1c74` (half-open tier boundary, plan/52 §1.4).
- **Scope:** dynamic financial settings in `system_settings` (fallback `application/config/withdrawal_fees.php`), Asia/Jakarta (WIB) timezone harmonization of the daily-limit check, backend operational gating (active days + time window), min/max enforcement, admin financial-rules UI, user-facing fee preview overhaul (withdraw + deposit).
- **Out of scope:** M7 settings-store consolidation (`site_settings` vs `system_settings`), M4 double-submit races, M6 double-entry ledger, gating admin *approval* of withdrawals by the operational window (gating applies to **submission** only, per PRD "ditunda/ditolak saat pengajuan"), persisted deposit-fee accounting (fee is metadata/display-only this pass — see §4.4).

---

## 1. Diagnosis Summary (evidence)

### 1.1 What M1 reports
- `docs/1_PRD.md` §121: window **Mon–Sat 07:00–19:00 WIB**; §122: min **Rp 100.000**, max **Rp 50.000.000** per withdrawal; §123–125 tiered fee = `pct(gross) + fixed Rp 6.500`.
- Implemented today: min check only (`Wallet::process_withdraw()` hardcodes `100000`). **Missing:** max Rp 50jt enforcement, any active-day / time-window gating. Tier fee computation landed in plan/52 (C3) via `Wallet_model::calculate_withdrawal_fee()` — but **no dynamic configuration** and **no window/day logic anywhere**.
- `has_reached_daily_wd_limit()` (`Wallet_model.php:383-391`) uses MySQL `CURDATE()` — same timezone-mismatch class fixed for rate limits in Phase 10B (MySQL server often UTC vs PHP Asia/Jakarta). Boundary at 00:00–07:00 WIB is wrong because DB-stored `created_at` (server-tz default `CURRENT_TIMESTAMP`) can lag WIB by 7h.

### 1.2 Current configuration surface (evidence)
- `application/config/withdrawal_fees.php` — the *only* fee source: `fixed_fee => 6500`, half-open tiers `[min, max, bps]` covering `[100000, 50000001)`, bps scale (10% = 1000). Its own docblock claims fee "is a spec constant (PRD), not a site_settings key" — this plan supersedes that stance (admin-operable per task).
- `Wallet_model::__construct()` `require`s that file into `$this->_wd_fees`; `calculate_withdrawal_fee()` reads it. Config file must remain as **fallback** when dynamic rows are absent/invalid.
- Two settings stores exist (finding M7): `site_settings` (`setting_value`, contact info, `Admin_model::get_all_settings()/update_settings()`) and `system_settings` (`key_value`, `Admin_model::get_setting()/set_setting()`, currently only `is_registration_open`). **Financial keys go into `system_settings`** (upsert-safe `set_setting`, `INSERT ... ON DUPLICATE KEY UPDATE` at `Admin_model.php:651-656`), matching the task. Canonical seeds: `database.sql` `INSERT IGNORE`; live idempotent migration pattern lives in `database_seed.sql` (guarded ALTER/INSERT — plan/52 convention).

### 1.3 Timezone facts
- `index.php:125-131` already sets `date_default_timezone_set('Asia/Jakarta')` (Phase 10B) — PHP is WIB everywhere.
- `withdrawals.created_at` / `deposits.created_at` are `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` (`database.sql`) — interpreted/stored in the **MySQL session** timezone, which the audit flags as typically UTC on the server. Reads (ledger timestamps, exports) and `CURDATE()` therefore skew vs PHP WIB.
- Fix = **one `SET time_zone = '+07:00'` per DB connection** (single shared CI connection, so one statement per request covers all later queries) **+** PHP-generated boundary dates bound as parameters (no reliance on `CURDATE()`), per the Phase 10B project pattern.

### 1.4 Frontend gap (evidence)
- `application/views/wallet/withdraw.php` — obsolete flat **5%** JS preview (`Math.floor(amount * 0.05)`); PRD tiers go up to 10% + fixed fee; net-payout preview is wrong for every amount.
- No `application/views/wallet/deposit.php` exists (task text names it): the deposit flow lives in `application/views/wallet/index.php` — inline top-up panel (quick amounts + custom) → `Wallet::topup()` creates `deposits` row → pending list with dev-only "Simulasi Pembayaran". No fee breakdown shown anywhere before invoice creation.
- `Wallet::withdraw()` GET + `Wallet::process_withdraw()` POST (`application/controllers/Wallet.php`) hold 4 gatekeepers (pending WD, active rental, daily limit, bank bound) but **no window/day gate and no min/max gate at POST besides min**.
- Authoritative financial decision must stay in the model inside the locked TX (C5 pattern, plan/48): controller checks are UX-only.

### 1.5 Deposit fee — billing contract
Task: user pays `amount + deposit_fee`; wallet ledger credit stays **pure principal** (zero dilution). Current approval path (`Wallet_model::approve_deposit_simulator()`) already credits exactly `deposits.amount` → keeping `deposits.amount = principal` satisfies zero dilution with **no change** to the ledger path. Fee is deterministic from `amount` → computable at display time (no schema change required for this pass).

---

## 2. Dynamic Financial Configuration Design

### 2.1 Keys in `system_settings` (all seeded, all with file fallback)

| Key | Type / example | Semantics | Seed default (PRD §121–125) |
|---|---|---|---|
| `wd_operational_days` | CSV ints 1–7, 1 = Monday | Active submission days | `1,2,3,4,5,6` (Mon–Sat) |
| `wd_open_time` | `HH:MM` | Window open (WIB) | `07:00` |
| `wd_close_time` | `HH:MM` | Window close (WIB, inclusive end: `now <= close`) | `19:00` |
| `wd_fixed_fee` | int IDR ≥ 0 | Bank disbursement fee | `6500` |
| `wd_fee_tiers` | JSON `[[min,max,bps],…]` half-open | Tier table (bps scale: 10% = 1000) | mirrors `withdrawal_fees.php` tiers |
| `wd_min_amount` | int IDR | Min per withdrawal (PRD §122) | `100000` |
| `wd_max_amount` | int IDR | Max per withdrawal (PRD §122) | `50000000` |
| `deposit_fee_enabled` | `0`/`1` | Deposit fee toggle | `0` |
| `deposit_fee_type` | `flat` \| `percent` | Deposit fee mode | `flat` |
| `deposit_fee_value` | int (flat IDR) or decimal % | Amount | `0` |

- `wd_min_amount`/`wd_max_amount` are added beyond the task's explicit key list because M1 names the missing max Rp 50jt as part of the finding; they live in the same config object for a single admin surface.
- Time values are **WIB wall-clock strings**; comparisons always run through the PHP `Asia/Jakarta` clock (`index.php` default). No `CURRENT_TIME()` in SQL.
- Percent deposit fee is interpreted in **percentage points** (e.g. `0.70` → `0.70%`); `fee = floor(amount * value / 100)`, flat → `fee = value`. Rounding = `floor` (integer IDR), same discipline as withdrawal fees.
- WD tier fee formula unchanged (plan/52 §1.4): `fee = floor(gross * bps / 10000) + fixed_fee`; half-open `[min, max)`; boundary amount belongs to the higher tier; `net = gross − fee`.

### 2.2 Fallback chain (task requirement)
1. `Wallet_model::get_financial_config()` reads the `system_settings` rows once per request (static cache in the model).
2. Missing **or invalid** row → value from `application/config/withdrawal_fees.php` extended with `operational_days`/`open_time`/`close_time`/`min_amount`/`max_amount`/`deposit_*` PRD defaults (file becomes the full fallback object, keeping one obvious place to read spec defaults).
3. `calculate_withdrawal_fee()` and all gating read the merged config — dynamic wins, file is never mutated by the app.

### 2.3 Tier JSON validation rules (shared model + admin POST)
- Decodes to array of `[min:int≥0, max:int>min, bps:int 0..10000]`, numeric-integer coercion after sanitize (`preg_replace('/[^0-9]/','')` per component).
- Tiers must be contiguous half-open covering at least `[wd_min_amount, wd_max_amount)` and sorted ascending by `min`; first `min` must equal `wd_min_amount`; last `max` must be `> wd_max_amount`; no gaps/overlaps.
- Any violation → reject the whole `wd_fee_tiers` update with an explicit error (never persist partial/invalid JSON).

---

## 3. Operational Enforcement & Timezone Harmonization (backend)

- **One-time DB session tz:** in `Wallet_model::__construct()` and `Admin_model::__construct()` run `$this->db->query("SET time_zone = '+07:00'")` (idempotent per request; single shared CI connection ⇒ all subsequent `CURRENT_TIMESTAMP`, reads and comparisons are WIB-consistent). These two models own every time-sensitive financial query today; a future cron touching time logic must do the same (noted in code comment).
- **Daily limit (00:00:00–23:59:59 WIB):** rewrite `has_reached_daily_wd_limit($user_id)` to bind the PHP boundary — `"DATE(created_at) = ?"` with `date('Y-m-d')` — instead of `CURDATE()`; count statuses `pending|success|processing` (a declined WD does **not** consume the day's quota — stated assumption). Called inside `create_withdrawal()` TX (already) and from controller/views for UX.
- **Window/day gating (authoritative, inside the locked TX):** new `Wallet_model::withdrawal_operational_status($now = null)` → `{open: bool, code: 'open'|'closed_day'|'closed_time', day: int, days: int[], open_at, close_at}`; day = `(int)date('N', $ts)` (1 = Monday), open iff `in_array(day, days)` **and** `time >= open` **and** `time <= close`. `create_withdrawal()` rejects with new codes `'closed_day'` / `'closed_time'` before insert; controller mirrors the check for fast UX (same gate as the GET form).
- **Min/max enforcement:** controller pre-check (UX) + model check inside the TX (`below_min` / `above_max` codes). Max uses `wd_max_amount` inclusive, top tier cap already `> max`.
- **Explicit timestamp discipline:** deposit/withdrawal inserts that must be compared later may set `created_at` from PHP (`date('Y-m-d H:i:s')`) so boundary logic never depends on MySQL defaults — only where the session-tz statement alone is deemed insufficient during implementation; session-tz is the primary fix.
- Audit trail: keep `code` values machine-readable; flashdata messages stay in Indonesian (`Hari ini bukan hari operasional penarikan.` / `Penarikan hanya dapat diajukan pukul 07:00–19:00 WIB.` etc.).

---

## 4. Admin Configuration Interface & Frontend

### 4.1 Admin surface
- New method `Admin::financial_settings()` (`GET` render + `POST` save; same `admin_id` guard pattern as `Admin::settings()`), pretty URL via `application/config/routes.php` (`'admin/financial-settings'`), sidebar link added to `admin/templates/sidebar`.
- New view `application/views/admin/financial_settings.php` with **three sections** (single POST):
  1. **Jam Operasional:** 7 day checkboxes (Senin–Minggu) + two `type="time"` inputs (`wd_open_time`, `wd_close_time`) — time inputs emit `HH:MM`.
  2. **Biaya Penarikan:** `wd_fixed_fee` number input; dynamic tier editor — repeatable rows (min / max / %) + add/remove, serialized to `wd_fee_tiers` JSON on submit; client-side preview of JSON with structural checks mirroring §2.3.
  3. **Biaya Deposit:** `deposit_fee_enabled` toggle, `deposit_fee_type` select (`flat`/`percent`), `deposit_fee_value` input (step any for percent, integer for flat).
- **Sanitization & validation (strict, server-side, before any `set_setting`):** whitelist + `trim()` all keys; days → `array_map('intval')`, filter `1..7`, unique, at least 1; times → regex `^\d{2}:\d{2}$` + `open < close` (PRD window is 07:00–19:00 so require `open < close`); `wd_fixed_fee` → int `0..100000`; `wd_fee_tiers` → JSON validation §2.3; `wd_min_amount`/`wd_max_amount` → ints with `min ≥ 100000`, `max > min`; deposit keys → toggle in `{0,1}`, type in `{flat,percent}`, value ≥ 0 with percent cap (e.g. `≤ 5`) and flat cap (e.g. `≤ 100000`).
- Persist via `Admin_model::set_setting()` per key inside one TX + `Audit_model::log_admin_action(..., 'admin_update_financial_settings', ['keys' => [...], 'changed' => [...]])` (mirror `toggle_registration()`, `Admin.php`).
- POST is **redirect-after-POST** to `admin/financial-settings` with flashdata (existing admin pattern).

### 4.2 `application/views/wallet/withdraw.php` overhaul
- Delete the flat-5% JS block; server injects one `window.WD_CONFIG = <?= json_encode($wd_config) ?>` (days, open/close, fixed_fee, tiers, min/max, `is_open`) from `Wallet::withdraw()`.
- Real-time preview on input: pick tier (half-open, boundary → higher tier — same loop as PHP), `fee = floor(amount*bps/10000) + fixed_fee`, net = amount − fee; min/max inline hints + client validation.
- If `!is_open` (outside day/window): disable submit button, replace fee box with informative notice (message varies: closed day vs closed time) — server remains the authority; the notice is UX protection.
- Time/day check computed client-side with `Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Jakarta', ... })` parts (not raw device tz) so WIB is honored regardless of the user's device timezone; re-evaluate on `visibilitychange`/interval (optional light touch).

### 4.3 Deposit view (`wallet/index.php` top-up panel — no `wallet/deposit.php` exists)
- Inject `deposit_fee_enabled/type/value`; under the selected amount show a breakdown line **before** "Lanjutkan Pembayaran": `Pokok Rp X + Biaya Rp Y = Total Dibayar Rp Z` when enabled (else no line). `Wallet::topup()` keeps storing **principal** as `deposits.amount` → approval credits principal only (zero dilution, unchanged ledger path).
- Pending-invoice card may show "Total dibayar" = amount + fee (recomputed deterministically) so the user knows the payable; fee persists only as display/metadata this pass (§1.5).

### 4.4 Deposit-fee accounting note (decision recorded)
Wallet ledger credit = principal only. The fee itself is **not** booked into `wallet_ledger` (single-entry store has only `credit`/`debit` types; booking revenue would need a type/schema extension and ties into open finding M6). Collected fee is visible as display metadata; persisting a `deposits.fee_amount` snapshot for admin revenue reporting is a cheap, safe follow-up and can be included in this plan only if approved — default: **not included** (keeps schema diff to seeds only).

---

## 5. Verification & Testing Protocol

1. **Static:** `php -l` on every touched file (roadmap rule).
2. **Config merge check:** seed DB rows absent → file fallback identical output; rows present → dynamic values win; corrupt `wd_fee_tiers` JSON → fallback used + error logged, never fatal.
3. **Test Case 1 — window/day gating:** set `wd_operational_days = 1,2,3,4,5` + `wd_open_time/wd_close_time`; assert POST `wallet/process_withdraw` on Saturday returns rejection (`closed_day`, flashdata) and GET `wallet/withdraw` shows the notice; assert inside window on Monday passes through to existing gates; mutate settings → behavior changes immediately (no cache beyond request).
4. **Test Case 2 — daily limit 00:00 WIB boundary:** with DB session UTC (simulate `SET time_zone='+00:00'` on a scratch connection) insert a withdrawal at `2026-06-09 00:30 WIB` (i.e. `2026-06-08 17:30 UTC`), then run `has_reached_daily_wd_limit` at `00:31 WIB` → must return `true` (PHP WIB date `2026-06-09`), and at `2026-06-09 23:59:59` still `true`, at `2026-06-10 00:00:01` `false` — proving the WIB calendar boundary, not UTC's.
5. **Test Case 3 — admin config mutation:** via `admin/financial-settings` change tier JSON + window; immediately (a) `withdraw.php` preview + POST math use the new tier for a boundary amount (e.g. Rp 5.000.000), (b) rejection window shifts to the new hours — assert in model + browser (HTTP 200/302 + flashdata).
6. Manual browser pass on mobile-first UI (form disabled state outside window; fee preview matches PHP `calculate_withdrawal_fee` for Rp 500.000 / 5.000.000 samples from plan/52 §1.4).

---

## 6. Implementation Phases (approval gate — do not execute until prompted)

1. **Financial config service & seeds (fallback-first)**
   - Extend `application/config/withdrawal_fees.php` into the full fallback object (`operational_days`, `open_time`, `close_time`, `fixed_fee`, `tiers`, `min_amount`, `max_amount`, `deposit_fee_*` PRD defaults) without changing tier semantics.
   - Add `Wallet_model::get_financial_config()` (per-request static-cache read of `system_settings` + typed parse + §2.3 validation + fallback merge) and route `calculate_withdrawal_fee()` through it.
   - Seed all 10 keys in `database.sql` (`INSERT IGNORE`) + idempotent guarded INSERTs in `database_seed.sql` (project migration convention).
2. **Backend operational enforcement & timezone harmonization**
   - `SET time_zone = '+07:00'` in `Wallet_model`/`Admin_model` constructors.
   - Rewrite `has_reached_daily_wd_limit()` with PHP-bound `date('Y-m-d')`; add `withdrawal_operational_status()` + min/max helpers.
   - Gate `create_withdrawal()` inside the TX (`closed_day`/`closed_time`/`below_min`/`above_max`) and mirror pre-checks in `Wallet::withdraw()`/`process_withdraw()`.
3. **Admin financial configuration UI**
   - `Admin::financial_settings()` GET/POST with strict validation (§4.1), `routes.php` entry, sidebar link, view `admin/financial_settings.php`, audit log + flashdata.
4. **Frontend synchronicity**
   - Overhaul `wallet/withdraw.php` (dynamic tier/fixed/net preview, WIB-aware disable + notice).
   - Deposit fee breakdown in `wallet/index.php` top-up panel before invoice creation (principal-only billing preserved).
5. **Verification & docs**
   - `php -l` sweep; run Test Cases 1–3 (§5) on dev (`php -S localhost:8080`); update this file's status to APPROVED/SUMMARY + note any PRD/doc deltas.
