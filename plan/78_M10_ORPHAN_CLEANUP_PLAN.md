# 78 — M10 ORPHAN CLEANUP & DEAD CODE/SCHEMA AUDIT PLAN

**Task:** Address audit finding M10 (plan/37_FULL_SYSTEM_AUDIT_REPORT.md §M10; plan/66_AUDIT_GAP_ANALYSIS_SUMMARY.md §2-M10 "PARTIAL") — dead code, orphaned endpoints, leftover legacy artifacts, and redundant logic.
**Mode:** Blueprint only. **No file/endpoint/schema was modified while authoring this document.** All deletions/edits below are *planned* and gated on explicit approval + a fresh evidence re-run at execution time.
**Supersedes:** plan/76 §2 & plan/77 (M9 "orphan endpoint conformed but kept") — M10 is the round that resolves them.

**Approved decisions (user, `dec-b1c9e989ddc88978`):**
1. **Notification consolidation (Option 2):** canonical = `notification/mark_all_read`; repoint the 2 call sites in `templates/header.php`; **retain** `User::read_notifications` as a thin backward-compatible forwarding alias + route (deletion deferred).
2. **Secrets hygiene (Option 1):** remove the commented production-credential block from `application/config/database.php` now; document production DB credential **rotation** and optional **git-history scrub** as handoff follow-ups.

**Safety policy (unchanged, enforced):** NO destructive `DROP`/live-DB mutations. Unused schema elements are flagged for documentation/deprecation only. No file deletion executes before the plan is approved and the pre-action grep receipts are re-run.

---

## 1. SAFETY POLICY & EXECUTION GATE

- Every removal step below requires: (a) explicit user go-ahead, (b) re-running the quoted grep receipt in the same session and pasting its output into this document's appendix, (c) `php -l` on every touched PHP file.
- Live database schema is **not modified**. Legacy tables are annotated in `database.sql` only.
- The repo working tree currently carries **uncommitted local edits** (`application/config/config.php`, `application/config/database.php`). These are the user's in-progress hygiene change (see §2.1); M10 edits build on top of them and are committed separately (Indonesian commit message per convention).

---

## 2. FINDINGS & EVIDENCE (read-only, gathered at authoring time)

### 2.1 Legacy files & secrets hygiene

| Artifact | State | Evidence | Verdict |
|---|---|---|---|
| `application/config/database.php:98–121` | Commented **production-credential block**: `username cmlh9365_synapse`, plaintext password fallback `[REDACTED]`, database `cmlh9365_synapse` | Direct read (lines 98–121); task-named block ~103–107 confirmed. `git diff` shows the uncommitted working-tree change already de-activated the creds (active block is now env-only `root`/`root`/`db_webtable` dev fallbacks) and parked the prod block as a comment | **SECURITY ISSUE** — hardcoded prod credential inside the committed file (as a comment), violating Phase 10D "no committed secrets" (plan/28 §3). Additionally `git log -S <secret>` proves the password is **committed in git history** (54ae0f8, e900646, c84a87f) → treated as compromised |
| Root `captcha/` directory | Empty (`total 8`, no entries), mode `drwxrwxrwx`, **untracked** | `git ls-files captcha/` → no output | Safe to remove (no git impact) |
| Root `.htaccess copy` | **Tracked** leftover: stale minimal rewrite rules (no `system/`/`application/` deny block) | `git ls-files` lists `.htaccess copy`; `diff .htaccess ".htaccess copy"` shows the stale variant | Remove via `git rm` (tracked ⇒ repo cleanup) |
| `application/config/config.php` | Uncommitted 4-line diff (2+/2−); content not M10-related | `git diff --stat` | Leave as-is; review at execution time to confirm no secret added |
| `backups/` (5 `.sql` snapshots, Aug 31–Sep 2) | Gitignored operational dumps (`git status --ignored` → `!! backups/`) | `ls -la backups/` | **Out of scope** — not in repo, possibly contain live data; never delete without user request |
| `database_seed.sql` | Active migration/seed tooling | `scripts/seed_database.php:24` references `$ROOT.'/database_seed.sql'` | **Not an orphan — keep** |

### 2.2 Orphan & unreferenced controller methods

| Artifact | State | Evidence | Verdict |
|---|---|---|---|
| `Notification::mark_read_single($id)` (`Notification.php:50–58`) | Public, AJAX-style; reachable only via default CI routing (`/notification/mark_read_single/{id}`) — **no route entry** in `routes.php` | **Zero consumers**: repo-wide grep of `mark_read_single` over `application/`, `scripts/`, all `.php/.js/.html`, and `system/` returns only the controller definition + docblock | **ORPHAN → remove** (M9 conformed it, plan/76 §2 kept it; M10 now deletes it) |
| `Profile::_verify_current_password` (`Profile.php:131`) | Underscore-prefixed CI3 form-validation callback — not routable | Name-level controller sweep | Not dead by convention; verify callback registration in `Profile::change_password` at execution time |

**Full controller method inventory** (sweep at authoring time — `application/controllers/*.php`): `Admin_auth` (login/logout), `Admin` (26 methods), `Auth` (register/login/change_password/refresh_captcha/logout), `Help::index`, `Home::index`, `Marketplace::index`, `Notification` (index/mark_all_read/mark_read_single), `Profile` (index/update/avatar_delete/change_password/_verify_current_password), `Rentals` (index/checkout/claim), `Team` (index/claim_level1/claim_wage), `User` (read_notifications), `Wallet` (index/topup/simulate_payment/withdraw/process_withdraw/simulate_wd_approve/bind_bank).
**No additional zero-consumer public methods were identified beyond `mark_read_single`/`read_notifications`.** Notable checked-clean items:
- `Wallet::simulate_payment` / `Wallet::simulate_wd_approve` → **NOT orphan**: live consumers `views/wallet/index.php:152` & `:186` + routes `routes.php:13` & `:16` (dev/UAT UI, `data-guard-submit`, prod-inert gates per C1/C7 plan/38–43).
- All 4 helpers (`api`, `captcha`, `ratelimit` + autoloaded `url/file/form/security`) have active per-controller loaders (receipts: `Auth.php:13/16/18`, `Team.php:10/12`, `Admin_auth.php:14`, `Admin.php:18`, `Wallet.php:11`, `Notification.php:9`, `User.php:9`, `Rentals.php:13`; `autoload.php:92`).
- `Profile::avatar_delete`, `Auth::refresh_captcha`, `Admin::expire_expired_rentals`, `Admin::toggle_registration`, `Admin::user_xray` etc. all have view/route consumers per prior rounds — not re-litigated here.

### 2.3 Redundant & duplicate endpoints (notification)

| Endpoint | Body | Consumers (grep) |
|---|---|---|
| `POST notification/mark_all_read` (`Notification.php:37–45`) | unauth→401 JSON; `Notification_model->mark_read($user_id)`; `api_success(['unread_count'=>0],'',200,['unread_count'=>0])` | `views/notification/index.php:131` (history-page "Tandai Semua Sudah Dibaca" → reads `data.success` only) |
| `POST user/read_notifications` (`User.php:20–30`, route `routes.php:45`) | **identical body** (same envelope) | `templates/header.php:384` (dropdown auto-mark-read on open) + `:415` (`markAllRead()` button) — both read `data.success` only |

`Notification.php` and `User.php` are the only controllers loading the `api` helper besides `Auth/Team/Admin` — envelope parity is exact since M9 (plan/76–77). Both consumers are in-repo views updated atomically in the same change ⇒ repoint is non-breaking.

### 2.4 Database schema cross-reference (read-only)

**Canonical DDL (`database.sql`) table list (grep `CREATE TABLE`):** `users, gpu_products, rentals, bank_accounts, withdrawals, otp_logs, wallet_ledger, deposits, user_rentals, admins, user_notifications, system_settings, system_audit_logs, rate_limits`.

- **`transactions` — already absent** from canonical DDL (M6 decommission, plan/68–69). plan/66's ":76 still in schema" is **stale** (plan/66 dated Sep 4 02:11; `database.sql` mtime Sep 4 20:35). ✅ no action.
- **`site_settings` — already absent** (M7 consolidation into `system_settings`, plan/70–71). ✅ no action.
- **`rentals`** (`database.sql:54–71`, legacy: `gpu_product_id`, `daily_rate_snapshot`, `total_days/days_processed/ends_at/last_claimed_at`): **orphan** — word-boundary SQL-verb grep (`from|into|update|join … rentals` + `rentals([^_])`) over `application/` + `scripts/` → **zero hits**. Live table is `user_rentals` (plan/60).
- **`otp_logs`** (`database.sql:116–124`, `phone/otp_code/expires_at/is_used`): **orphan** — same grep for `otp_logs` → **zero hits**. No OTP flow exists (native CAPTCHA, no phone OTP).
- **`application/config/withdrawal_fees.php`**: **ACTIVE M1 fallback — NOT an orphan (audit false negative, corrected at execution time — see §7).** `Wallet_model::get_financial_config()` `require`s it per request (`Wallet_model.php:52`) and merges dynamic `system_settings` rows over it (`:54–59`, per-key validation `_resolve_financial_config`). The original "zero references" claim came from an over-aggressive `grep -v "config/withdrawal_fees.php"` filter that also dropped the two legitimately-referencing lines (`Wallet_model.php:33`, `:52`) because they contain the full path string. **File retained; no removal.**
- Live DB not queried (static audit; no live-schema drift assessment performed). Deprecation is doc-only regardless.

### 2.5 Stale documentation

- `AGENTS.md` seed note still lists `site_settings` (gone) and omits current canonical list → update during execution. AGENTS schema list does still name `rentals`/`otp_logs` (still present in DDL until annotated).

---

## 3. EVIDENCE MATRIX (itemized)

| Target Artifact / Code | Current State | Grep Evidence of Inactivity | Proposed Safe Action | Risk Level |
|---|---|---|---|---|
| `database.php:98–121` commented prod-cred block (`cmlh9365_synapse`, password redacted) | Commented secret in tracked file; same secret in git history (54ae0f8, e900646, c84a87f) | `git log -S <secret>` → 3 commits | **A1.** Delete comment block (env-only active config stays); hand off rotation + history scrub | **HIGH (security)** / low code risk |
| Root `captcha/` | Empty, untracked, mode 777 | `git ls-files captcha/` → ∅ | **A3.** `rm -rf captcha/` (non-git) | Low |
| Root `.htaccess copy` | Tracked stale rewrite variant | `git ls-files` lists it; `diff` vs `.htaccess` shows old rules | **A4.** `git rm ".htaccess copy"` | Low |
| `Notification::mark_read_single` | Public method, default-routing reachable, no route, no UI | repo-wide grep → only controller defn/docblock | **B3.** Delete method (re-grep first) | Low |
| `User::read_notifications` vs `Notification::mark_all_read` | Identical bodies/envelopes; 2 header consumers + 1 index consumer | `header.php:384/:415` → `user/read_notifications`; `notification/index.php:131` → `mark_all_read` | **B1–B2.** Canonical = `mark_all_read`; repoint header.php; keep thin alias + route | Low–Med (every-page template; smoke-test) |
| `rentals` table (`database.sql:54`) | Legacy DDL, nothing reads/writes | SQL-verb grep `application/ scripts/` → 0 hits | **C.** Deprecation comment in `database.sql` only — **no DROP** | None (doc-only) |
| `otp_logs` table (`database.sql:116`) | Legacy DDL, nothing reads/writes | same → 0 hits | **C.** Deprecation comment only — **no DROP** | None (doc-only) |
| `withdrawal_fees.php` config | **Active M1 fallback** (`Wallet_model.php:52` require; merged under `system_settings`) | corrected grep → `Wallet_model.php:33/:52` + UI hint `admin/settings.php:32` (no `load->config`, by design — plain `require`) | **None — retained.** Initial removal reverted at execution (audit false negative, §7) | None |
| `AGENTS.md` schema note | Stale (`site_settings` listed) | read | **C.** Sync with canonical DDL list | None |
| `transactions`, `site_settings` tables | Already removed from canonical DDL (M6/M7) | table-list grep | none | — |
| `backups/*.sql` | Gitignored operational snapshots | `git status --ignored` | none (out of scope) | — |
| `simulate_payment` / `simulate_wd_approve` | Dev/UAT simulators w/ consumers + gates | `wallet/index.php:152/:186`, `routes.php:13/:16` | none (checked clean) | — |
| Helpers (`api/captcha/ratelimit`) | All loaded per-controller | loader receipts §2.2 | none (checked clean) | — |

---

## 4. PLANNED REMEDIATION (NOT YET EXECUTED — pending further instruction)

### Phase A — Secrets & legacy-files hygiene
- **A1.** `application/config/database.php`: delete the commented production-credential block (lines 98–121) so the file ends at the active env-driven `$db['default']` array. `php -l application/config/database.php`. (Completes the in-progress local hygiene diff.)
- **A2.** Review the unrelated uncommitted `config.php` diff (4 lines) — confirm no secret; leave untouched otherwise.
- **A3.** Delete root `captcha/` (untracked, empty).
- **A4.** `git rm ".htaccess copy"` (tracked leftover).
- **A5.** Record rotation + optional history scrub in §6 handoffs.

### Phase B — Notification endpoint consolidation (non-breaking)
- **B1.** `templates/header.php:384` and `:415`: change target to `base_url('notification/mark_all_read')` (both consumers read only `data.success`; envelope identical since M9).
- **B2.** `User.php::read_notifications`: keep as a **thin backward-compatible alias** (retain method + `routes.php:45` entry; body already minimal — delegate to `Notification_model->mark_read()` + identical `api_success`; trim docblock to "legacy alias"). Deletion deferred per decision.
- **B3.** `Notification.php`: delete orphaned `mark_read_single($id)` after re-running the zero-consumer grep.

### Phase C — Schema & doc deprecation (NO DROP, no live-DB touch)
- **C1.** `database.sql`: annotate `rentals` (:54) and `otp_logs` (:116) with `-- DEPRECATED (M10): no code path reads/writes; retention-only, do not use in new code.`
- **C2.** ~~Remove `application/config/withdrawal_fees.php` + stale path hint~~ → **SUPERSEDED (see §7): no action.** File is the active M1 fallback (`Wallet_model.php:52`); `settings.php:32` hint left intact.
- **C3.** Sync `AGENTS.md` schema notes to the canonical DDL list.

### Phase D — Verification & close-out
- **D1.** `php -l` on every modified PHP file.
- **D2.** Re-run receipt greps: `mark_read_single` = 0 consumers; `user/read_notifications` JS callers = 0 (only route + alias remain); `withdrawal_fees` = **active fallback refs present (`Wallet_model.php:33/:52`) — file retained**; `rentals`/`otp_logs` SQL refs = 0.
- **D3.** Runtime smoke (if local env available): authenticated `POST notification/mark_all_read` and the retained alias return the M9 envelope; header dropdown + history page still clear the badge. If `synapse.test`/rewrite env is unavailable, record as limitation (static envelope-invariance check stands in).
- **D4.** Commit per convention (Indonesian message) on a phase branch; append execution receipts to this doc or a follow-up summary (`plan/79_..._SUMMARY.md` per M-series convention).

---

## 5. DECISION LOG

| # | Decision | Choice | Rationale |
|---|---|---|---|
| 1 | Duplicate notif endpoints | Canonical `notification/mark_all_read`; repoint header.php (2 sites); keep `User::read_notifications` thin alias + route | All consumers in-repo; alias preserves any out-of-band/cached JS; zero breakage; deletion deferred |
| 2 | database.php secret | Delete commented block now; **rotation + optional history scrub = handoff** | Credential historically active + in git history ⇒ compromised; rotation touches prod infra (external) |

## 6. DEFERRED HANDOFFS (not part of this round's code changes)

1. **Rotate production DB credential** (`cmlh9365_synapse`, password redacted here) — it was the active fallback in committed HEAD and exists in history (54ae0f8, e900646, c84a87f). Env var `DB_PASSWORD` on the host must be updated to the new value.
2. **Optional git-history scrub** (filter-repo) to purge the secret from all commits — destructive/irreversible, needs coordination before shared history is rewritten.
3. **Future decision:** drop deprecated `rentals` + `otp_logs` from live DB + canonical DDL after a sign-off period (policy forbids doing it now).
4. **Future cleanup candidate (deferred deletion):** `User::read_notifications` + `User.php` controller + `routes.php:45` once the alias window closes.

## 7. EXECUTION-TIME CORRECTIONS & NEW FINDINGS (added during implementation)

1. **`withdrawal_fees.php` audit false negative (corrected above).** Authoring-time grep `grep -rn "withdrawal_fees" application/ --include="*.php" | grep -v "config/withdrawal_fees.php"` dropped the two real references (`Wallet_model.php:33` comment, `:52` `$fallback = require APPPATH . 'config/withdrawal_fees.php';`) because `grep -v` matched the full path substring anywhere on the line. The file is the **active M1 fallback** merged per-key under `system_settings` by `Wallet_model::get_financial_config()` (`Wallet_model.php:47–61`). Execution initially ran `git rm application/config/withdrawal_fees.php` + edited `views/admin/settings.php:32`; both were **fully reverted** (`git checkout HEAD -- ...` + reverse edit; worktree/index clean for that file). Lesson: exclusion filters must anchor to the artifact path, not a bare substring, and "no `load->config`" is not "unreferenced" — a plain `require` is a valid loader.
2. **`Notification_model::mark_single_read($id, $user_id)` (`Notification_model.php:44`) is now unreferenced** after deletion of the `mark_read_single` endpoint (its only caller). Left in place (model-method removal not in the approved Phase B scope) — recorded as a deferred follow-up, not dead weight in the UI/controller layer.
3. **Uncommitted `application/config/config.php` diff reviewed** (Phase A4): it toggles `base_url` dev `http://synapse.test/` ↔ prod `https://synapse.cml-indonesia.com/` (env-overridable `APP_BASE_URL`). No credential; unrelated to M10 → **preserved untouched**.
4. Full execution receipts, final grep evidence, and lint results are recorded in `plan/79_M10_ORPHAN_CLEANUP_SUMMARY.md`.

---

*End of plan — plan/78_M10_ORPHAN_CLEANUP_PLAN.md. Authored read-only; execution receipts and the corrected withdrawal_fees disposition are in §7 above and plan/79.*
