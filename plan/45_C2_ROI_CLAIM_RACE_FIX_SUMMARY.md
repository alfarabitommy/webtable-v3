# 45 — C2 ROI CLAIM RACE FIX SUMMARY (Lost-Update & Double-Payout — Eliminated)

**Status:** ✅ COMPLETE — code applied, `php -l` clean, grep verification passed, claimable-math harness 12/12 PASS. Runtime curl/concurrency tests per plan/44 §6 pending live-DB smoke run (see §5).
**Blueprint:** plan/44_C2_ROI_CLAIM_RACE_FIX_PLAN.md (approved). **Audit:** plan/37 §2-C2.
**Scope:** `application/models/Rental_model.php`, `application/controllers/Rentals.php`, `application/views/rentals/index.php`. No database schema modified (unique-key migration intentionally deferred to a separate commit per plan/44 §7.3).

---

## 1. Vulnerability (recap)

`Rentals::claim()` read the rental with a plain `get_where` (no lock), computed `claimable_days`/`actual_claim_days` from that snapshot, then wrote back an **absolute stale value** (`days_processed = snapshot + n`) inside a TX. Two concurrent POSTs both snapshotted `days_processed=0` → both passed the day-diff guard → **two wallet_ledger credits** (double payout). Compounding issues fixed in the same pass:

- No `status='active'` guard and no `expired_at` guard — an expired rental (status never flipped, audit M3) stayed claimable.
- `transaction_id = 'ROI-'.time().'-'.$rental_id` — same-second non-unique; no way for the ledger to dedupe.
- SQL/TX inline in the controller (AGENTS.md violation); claimable-day math duplicated between `index()` and `claim()` (audit P8).

---

## 2. Changes applied

### 2.1 `application/models/Rental_model.php` — concurrency engine & single math source

- **`__construct()`**: timezone guard `Asia/Jakarta` (mirrors `Rate_limit_model`; protects CLI/cron paths).
- **`claimable_info($rental)`** (new, pure, no DB): **single source of truth** for T+1/H+1, 2-day accumulation cap, remaining days, and status flags. Returns `claimable_days, remaining_days, actual_claimable, is_claimed_today, is_expired, is_completed, reference_date, day_diff`. Consumed by both `Rentals::index()` (display) and `claim_roi()` (authoritative) — P8 twin eliminated.
- **`claim_roi($rental_id, $user_id)`** (rewritten; old unused 3-arg signature removed) — one atomic workflow:
  1. `$this->db->trans_begin()` (explicit TX, `Ledger_model` style).
  2. `SELECT * FROM user_rentals WHERE id = ? AND user_id = ? FOR UPDATE` — row lock scoped by ownership; concurrent claims **block**, then read committed state (InnoDB current read) → compute 0 → no credit.
  3. Lifecycle guards on the locked row: `status !== 'active'` → reject `not_active`; `expired_at <= now` (PHP Jakarta timestamp) **or** `days_processed >= total_days` → atomic idempotent flip `active→completed` (`WHERE ... status='active'`), `trans_commit()`, return `expired`/`completed` with **0 payout**.
  4. `actual_claimable < 1` → rollback, return `no_claimable` (distinct H+1 message when `created_at` is today).
  5. Guarded **relative** update `SET days_processed = days_processed + ?, last_claimed_at = ? WHERE id=? AND user_id=? AND status='active'` — an absolute stale write is structurally impossible; ledger credit gated strictly on `$this->db->affected_rows() === 1`.
  6. Ledger insert with **deterministic ID** `ROI-{rental_id}-D{new_days_processed}` (monotonic per claim sequence); insert failure → rollback.
  7. `trans_commit()`; returns structured `['code','message','amount','days']`. `catch (Throwable)` → rollback + `log_message('error', ...)`.
- **`checkout_rental($user_id, $product)`** (new): encapsulates the former inline checkout TX (debit `wallet_ledger` + create `user_rentals` with `expired_at`) so the controller holds **no DB access at all**. Behavior identical to the previous controller code.

### 2.2 `application/controllers/Rentals.php` — thin HTTP layer

- `index()`: inline claimable math replaced with `$this->Rental_model->claimable_info($r)` augmentation (adds `is_claimed_today`, `is_completed`, `is_expired` to each rental).
- `claim()`: all inline SQL, TX, and duplicate math stripped. Keeps POST-only guard, rental-id validation, rate limit (10B), then `$this->Rental_model->claim_roi($rental_id, $user_id)` and maps `code === 'claimed'` → success flash, otherwise error flash, then redirect.
- `checkout()`: DB/TX delegated to `Rental_model::checkout_rental()`; keeps product validation + UX balance check.
- **Zero `$this->db` invocations remain** (verified by grep).

### 2.3 `application/views/rentals/index.php` — defensive UX

- "Sudah Diklaim" / "Kontrak Habis" disabled states now consume the controller-supplied `is_claimed_today` / `is_completed` flags (same semantics as before; single source of truth, no inline date re-derivation).
- Cosmetic double-click prevention: delegated `submit` listener disables the claim button (after the submit is dispatched) for forms whose action contains `/rentals/claim/`. Explicitly **cosmetic** — the server-side `FOR UPDATE` row lock remains the sole authority against double payout. (View's pre-existing Phase 32 theme-tokenization changes were already in the working tree and are untouched.)

---

## 3. Syntax verification (php -l)

```bash
$ php -l application/models/Rental_model.php
No syntax errors detected in application/models/Rental_model.php
$ php -l application/controllers/Rentals.php
No syntax errors detected in application/controllers/Rentals.php
$ php -l application/views/rentals/index.php
No syntax errors detected in application/views/rentals/index.php
```

## 4. Grep / behavioral verification

```bash
$ grep -n '\$this->db' application/controllers/Rentals.php
# (no output) — CLEAN: controller has zero direct DB usage
$ grep -rn 'claim_roi(' application/ --include=*.php
# only: definition (Rental_model.php) + single controller call — no stale 3-arg callers
```

**Claimable-math harness (real `Rental_model`, stubbed CI base, run from /tmp, removed after):** 12/12 PASS across lifecycle states — T+1 gate (created today → 0), 2-day accumulation cap, claimed-yesterday → 1, claimed-today → 0 + flag, `days_processed == total_days` → completed, remaining=1 with 2 claimable → actual 1 (no over-payment), `expired_at` in the past → `is_expired`.

## 5. Test protocol status (runtime)

Plan/44 §6.1 static checks passed locally (this section). Plan/44 §6.2–6.3 runtime tests (Test 1 single claim, Test 2 5–10× parallel `xargs -P` burst → exactly one credit/one ledger row, Test 3 replay → 0 claimable, Test 4 expired/completed rejection + lazy flip) require a live MySQL `db_webtable`; this execution environment has no MySQL server/client available (`which mysql mariadb` → none), so they remain the **recommended next step** before merging, per the established plan/39 & plan/43 workflow.

## 6. Files touched (C2 scope only)

| File | Change |
|---|---|
| `application/models/Rental_model.php` | `__construct` tz guard; new `claimable_info()`; new `checkout_rental()`; `claim_roi()` rewritten as atomic FOR-UPDATE engine with lifecycle guards + deterministic ledger ID; `_claim_result()` helper |
| `application/controllers/Rentals.php` | `index()`/`claim()`/`checkout()` refactored — zero `$this->db`, zero inline SQL/TX |
| `application/views/rentals/index.php` | Consumes single-source status flags; cosmetic double-submit prevention |

Not modified (tracked separately in audit): schema unique key on `wallet_ledger` (plan/44 §4C, separate commit with pre-dedupe); C3/C4/C5/C6; M3 expiry/ROI cron.

## 7. Rollout notes

- Branch per `docs/3_ROADMAP.md` phase-branch convention; suggested commit message in Indonesian: `fix(c2): klaim ROI aman konkurensi — FOR UPDATE + guard status/expired + ID ledger deterministik + pindah SQL ke model`.
- Recommended next step: live-DB smoke run of plan/44 §6.3 Test 1–4, then the `wallet_ledger` unique-key migration commit (plan/44 §4C) after a duplicate-row audit.
