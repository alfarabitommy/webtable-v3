# plan/76 — M9 & P7: Unified API JSON Envelope & Error Response Audit

**Status:** APPROVED (write-only milestone — this file only; no application code changed yet)
**Round:** M9 + P7 (plan/37_FULL_SYSTEM_AUDIT_REPORT.md §M9/P7, plan/66_AUDIT_GAP_ANALYSIS_SUMMARY.md §2)
**Author discipline:** read-only audit (grep + view tracing); zero schema / route / view-JS changes planned. Every batch below is isolated, backward-compatible, and verified before the next.
**Execution gate:** code batches (A–E) start ONLY after a further explicit user prompt. This file documents the contract.

---

## 1. Context & references

- **M9 — Inconsistent API error envelope:** rate-limit helper `{success,error,message,retry_after}` + HTTP 429; Team claim endpoints `{success,message}` always HTTP 200; Notification endpoints `{success,error}`; no shared JSON error contract; some error paths leak HTML/flashdata.
- **P7 — No JSON 500/404 envelope:** 404/403/500 use stock CI3 error views. Headers are injected correctly via `MY_Exceptions`/`MY_Output`, but an AJAX request hitting an exception receives an HTML body → client `r.json()` rejects → generic "kesalahan jaringan" toast instead of a structured error.
- Primary evidence files (current line numbers):
  - `application/controllers/Auth.php:324-337` (`refresh_captcha`), `:111/:203` (AJAX rate-limit branches).
  - `application/controllers/Team.php:61-92` (`claim_level1`), `:99-151` (`claim_wage`).
  - `application/controllers/Notification.php:30-42` (`mark_all_read`), `:47-59` (`mark_read_single`).
  - `application/controllers/User.php:14-28` (`read_notifications`).
  - `application/controllers/Admin.php:1019-1054` (`toggle_registration`), `:1060-1067` (`chart_data`), `:1096-1113` (`user_xray`).
  - `application/helpers/ratelimit_helper.php` (`rate_limit_json_response`, whole file).
  - `application/core/MY_Exceptions.php` (whole file — HTML error delegation point).

## 2. Scope

### In scope
1. Every JSON-emitting controller method (matrix §3) — unify on one envelope.
2. A single choke-point JSON helper (§6) usable from every controller incl. `CI_Controller`-based `Auth`/`Admin`/`Admin_auth` (they do NOT extend `MY_Controller`).
3. P7: JSON error envelope for AJAX on CI3 error exits via `MY_Exceptions` (§8).
4. Backward-compatible dual-key migration so **no frontend JS file changes** are required (legacy keys preserved verbatim, §5).

### Out of scope (documented, NOT changed this round)
- Full-page HTML flows (register/login/logout, checkout, `Rentals::claim` form POST, topup, withdraw, process_withdraw HTML branch, bind_bank, profile, all admin HTML mutators, audit, CSV export). They emit flashdata + redirect, no JSON.
- M10 cleanup (orphan `Notification::mark_read_single` is **conformed but kept**, not deleted; duplicate `User::read_notifications` vs `Notification::mark_all_read` duplication untouched).
- M5/M6/M7/M8 items, CSRF config, routes, schema.
- Retiring legacy alias keys (tracked follow-up round after consumers are migrated — NOT this round).
- JSON 401 for *admin* AJAX endpoints when `admin_id` session expires mid-use (Admin constructor currently 302→`control-panel`; JS falls into `.catch`). Noted as optional hardening in §9.

## 3. Endpoint inventory & mapping matrix

Legend: shape shown is the CURRENT response; HTTP column is current behavior. Consumers traced via `csrfFetch`/`fetch` in views — no `$.ajax`/axios anywhere.

| # | Endpoint / method | HTTP now | Current JSON shape | Client consumer (view, line) | Keys frontend reads today | Migration batch |
|---|---|---|---|---|---|---|
| 1 | `Auth::refresh_captcha` — `GET /auth/refresh_captcha` (AJAX-only; non-AJAX → redirect `login`) | 200 | `{svg: string, expires_in: int}` — bare data, **no `success`** | `auth/login.php:168-192` & `auth/register.php:~170-195` (captcha-refresh button) | `svg`; **`res.ok` gate → must keep 200** (else `location.reload()` fallback) | C |
| 2 | `Team::claim_level1` — `POST /team/claim_level1` (AJAX-only → else `show_404`; session re-check inline) | 200 always | `{success, message}`; on success controller adds `new_balance` (fresh ledger balance) | `team/index.php:366-395` `claimLevel1()` | `success`, `new_balance`, `message` | B |
| 3 | `Team::claim_wage` — `POST /team/claim_wage` (POST+AJAX-only → else `show_404`; session re-check inline; rate limit 5/60s) | 500 only when `code==='error'`, else 200 | `{success, code, message[, level][, amount]}`; on success controller adds `new_balance` | `team/index.php:397-435` `claimWage()` | `success`, `code` (`claimed`/`already_claimed`/`cycle_not_ready`/else), `message`, `new_balance` | B |
| 4 | `Notification::mark_all_read` — `POST /notification/mark_all_read` (direct method URL) | 200 always | `{success:true, unread_count:0}` / `{success:false, error:'Unauthorized'}` | `notification/index.php:123-180` `markAllRead()` | `success` | A |
| 5 | `Notification::mark_read_single/{id}` — `POST` (direct method URL) | 200 always | same as #4 | **no consumer found in `application/` (orphan endpoint)** | — | A (conform, keep) |
| 6 | `User::read_notifications` — `POST /user/read_notifications` (routed) | 200 always | same as #4 | `templates/header.php:384` (`toggleNotifDropdown` auto-mark) and `:415` (`markAllRead`) | `success` | A |
| 7 | `Admin::toggle_registration` — `POST /admin/toggle_registration` (csrfFetch POST) | 405 (non-POST) else 200 | 405: `{success:false, error:'Method not allowed'}` — **JSON body WITHOUT `Content-Type: application/json`**; else `{success, is_open, message}` | `admin/dashboard.php:233-271` `toggleRegistration()` | `success`, `is_open`; on failure reads **`error` — but server sends `message` → alert "Unknown error"** (real bug) | D |
| 8 | `Admin::chart_data?days=N` — `GET` (plain fetch, no token needed) | 200 | `{labels: [], data: []}` — bare data from `Admin_model::get_revenue_chart_data()` (`Admin_model.php:736-756`), **no `success`** | `admin/templates/footer.php:104-114` (period dropdown chart re-fetch) | `labels`, `data` | D |
| 9 | `Admin::user_xray/{id}` — `GET` (plain fetch) | 200 / 404 | `{success:true, data:{user:{id,phone,username}, total_credit, total_withdrawals, active_rentals, total_invested, balance, downline_count, …}}` / `{success:false, error:'User not found'}` | `admin/analytics.php:271+` `openXray(userId)` (reads `json.data.*` fields) | `success`, `error`, `data.*` | D |
| 10 | Rate-limit choke — `rate_limit_json_response()` in `ratelimit_helper.php`, reached on AJAX only for: `Auth::register` (`:111`), `Auth::login` (`:203`), `Admin_auth::login` (`:32`), `Rentals::claim` (`:121`), `Team::claim_wage` (`:124`), `Wallet::process_withdraw` (`:190`) | 429 | `{success:false, error:'too_many_attempts', message, retry_after}` | Real JS consumer: `claimWage()` toast (`d.message`); other sites are HTML flows with no JS consumer | `message`, `retry_after`, `error` | 0 (re-route through helper §6) |

### Client transport facts (why migration is low-risk)
- All consumers use `fetch()` + `r.json()` (via `window.csrfFetch` wrapper for POST, `templates/csrf_meta.php:21-47`, or raw fetch with `X-Requested-With` for the GET/refresh endpoints).
- Consumers branch on **response keys**, never on `res.ok`/HTTP status — with exactly one exception: `refresh_captcha` checks `res.ok` (endpoint #1 must stay 200).
- `fetch` resolves on 4xx/5xx, so adding/keeping real status codes only matters if a consumer checks them — none do today. Therefore status changes are safe as long as the JSON body remains parseable and key-compatible.

## 4. Divergence inventory (M9 evidence)

1. **Three envelope dialects:** `{success,message}` (Team, toggle), `{success,error}` (Notification/User, xray-404, toggle-405), bare data with no success (`refresh_captcha` `{svg,expires_in}`, `chart_data` `{labels,data}`).
2. **No shared JSON helper:** 6+ divergent `echo json_encode()` / `$this->output->set_output(json_encode(...))` chains across 6 controllers + 1 helper; content-type set inconsistently (`set_content_type('application/json')`, raw `header('Content-Type: application/json')`, and — in `toggle_registration` 405 — **not set at all**).
3. **Unauthorized returns HTTP 200** in `Notification`/`User` (`{success:false,error:'Unauthorized'}` → should be 401). Unauthenticated user-AJAX in general is pre-empted by inline session checks precisely because `MY_Controller` would otherwise 302 to HTML `login` (breaking `r.json()`).
4. **Key mismatch bug:** `admin/dashboard.php` failure branch reads `data.error`, server failure payload carries `message` → alert shows "Unknown error".
5. **Message/error-key duality:** success carries `message`, some errors carry `error`, rate-limit carries **both** `error`+`message`.
6. **No JSON 500/404 envelope (P7):** an unhandled exception/DB error in any AJAX endpoint → CI3 stock HTML error page (`MY_Exceptions` delegates to parent) → `r.json()` rejects → generic network-error toast. M9 also flags HTML/flashdata leaking into error paths.
7. **No machine-actionable error codes** except `claim_wage` (`code`) and the rate limiter (`error:'too_many_attempts'` + `retry_after`).

## 5. Canonical envelope specification

### Success (HTTP 200/201)
```json
{ "success": true, "message": "…(Indonesian, human-readable, optional default)", "data": { } }
```
- `data` MUST be an object or array — omit (`null`) only when there is nothing to return; never a bare scalar at top level.

### Error (HTTP 400/401/403/404/409/422/429/500)
```json
{ "success": false, "message": "…", "errors": [], "code": "machine_code" }
```
- `errors`: array of field-level/validation strings (may be empty).
- `code`: optional stable machine code for programmatic branching (e.g. `too_many_attempts`, `unauthenticated`, `not_found`, `already_claimed`).
- Extra endpoint-specific keys allowed (e.g. `retry_after`) but MUST also be mirrored inside `data` where the envelope carries a payload.

### HTTP status mapping to apply per endpoint
| Condition | Status |
|---|---|
| Success | 200 / 201 |
| Validation / business rejection (not a bug) | 422 (400 only for malformed input) |
| Unauthenticated / expired session | 401 (was 200 — safe, no consumer checks status) |
| Not found (xray 404 exists already; 404-override JSON in §8) | 404 |
| Rate limited | 429 (unchanged) |
| Unhandled exception / server fault | 500 |

## 6. Backward-compatibility contract (dual-key transition)

**Migration rule:** *every top-level key a consumer reads today is emitted verbatim (legacy alias) alongside the canonical keys.* New keys are purely additive. Legacy aliases are retired in a later, separately-approved round after consumer code is migrated off them. Consumer JS files (`templates/header.php`, `team/index.php`, `notification/index.php`, `auth/login.php`, `auth/register.php`, `admin/dashboard.php`, `admin/analytics.php`, `admin/templates/footer.php`) are **not modified in this round**.

Per-endpoint alias plan:

| # | Endpoint | Canonical shape to emit | Legacy keys kept (top-level) |
|---|---|---|---|
| 1 | `refresh_captcha` | `{success:true, message:'', data:{svg, expires_in}}` — **HTTP stays 200** | `svg`, `expires_in` |
| 2 | `claim_level1` | success: `{success:true, message, data:{new_balance}}`; business-fail: `{success:false, message, data:null}` (200) | `new_balance` (success only), `message` |
| 3 | `claim_wage` | `{success, message, code, data:{level, amount, new_balance}}`; session → 401; `code==='error'` keeps 500 | `code`, `level`, `amount`, `new_balance`, `message` |
| 4/6 | `mark_all_read` / `read_notifications` | success `{success:true, message, data:{unread_count:0}}`; unauth `{success:false, message, data:null}` **HTTP 401** | `unread_count`, `error` (alias string on failures) |
| 5 | `mark_read_single` | success `{success:true, message:'', data:null}`; unauth 401 | `error` |
| 7 | `toggle_registration` | `{success, message, data:{is_open}}`; 405 gains JSON content-type + `{success:false, message, errors:[], code:'method_not_allowed'}` | `is_open`, **`error` alias added on failure paths so `dashboard.php` alert shows the real message** |
| 8 | `chart_data` | `{success:true, message:'', data:{labels, data}}` | `labels`, `data` (top-level) |
| 9 | `user_xray` | 200 `{success:true, message:'', data:{…xray}}`; 404 `{success:false, message:'User not found', data:null}` | `error` (alias on 404, value = message) |
| 10 | rate-limit | re-routed through choke helper (§6): `{success:false, message, errors:[], code:'too_many_attempts', data:{retry_after}}` + **HTTP 429** | `error:'too_many_attempts'`, `message`, `retry_after` (top-level) |

**Safe-status rule:** any status-code change is allowed because no consumer inspects HTTP status except endpoint #1's `res.ok` (which must see 200) — all others do `r.json()` then branch on keys. Verify per batch that no consumer code path inspects `res.ok`/`res.status` for that endpoint.

## 7. Single choke-point helper (design)

New file `application/helpers/api_helper.php` (loaded alongside `ratelimit_helper`, precedented by it), so it is callable from **any** controller (incl. `CI_Controller`-based `Auth`, `Admin`, `Admin_auth`) — a base-class method in `MY_Controller` would NOT reach those, hence a helper is the right single choke point.

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

function api_success($data = null, $message = '', $http = 200, array $legacy = [])
{
    // body = ['success'=>true,'message'=>$message,'data'=>$data] + $legacy merged at top level
    // set_status_header($http); header('Content-Type: application/json');
    // echo json_encode($body, JSON_UNESCAPED_UNICODE); exit;
}

function api_error($message, $http = 400, array $errors = [], $code = null, array $legacy = [])
{
    // body = ['success'=>false,'message'=>$message,'errors'=>$errors,'data'=>null]
    //        + 'code' if $code !== null + $legacy merged at top level
    // set_status_header($http); header('Content-Type: application/json');
    // echo json_encode($body, JSON_UNESCAPED_UNICODE); exit;
}
```

- JSON options: `JSON_UNESCAPED_UNICODE` (Indonesian text), plus `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` when payload may embed user/HTML-derived strings (parity with `Auth::refresh_captcha` today) — decide once in the helper and drop per-callsite flags.
- `$legacy` param keeps the dual-key story explicit at the callsite (no magic) and makes the later retirement round a pure diff of call sites.
- Content-Type always set via `header()` (equivalent to `$this->output->set_content_type(...)`), because several current sites use raw `echo` + `exit` and several use `$this->output`; the helper standardizes on `echo+exit` (consistent with `rate_limit_json_response`).
- `rate_limit_json_response()` in `ratelimit_helper.php` is refactored to delegate to `api_error(..., 429, [], 'too_many_attempts', ['error'=>'too_many_attempts','retry_after'=>…])` so its output shape/status is byte-identical to today.

## 8. P7 — JSON error envelope for AJAX (`MY_Exceptions`)

`MY_Exceptions` (already the single intercept for all early-exit error paths — 404, `show_error`, exceptions, PHP errors) gains an AJAX detector:

- Detect JSON expectation: `X-Requested-With: XMLHttpRequest` present **or** `Accept: application/json` (CI3 exposes via `$_SERVER`; keep it simple and header-based, no `$this->input` dependency on the exception path).
- When detected, emit canonical error JSON instead of the stock HTML view, preserving the real status code:
  - `show_404` → `api_error('Halaman tidak ditemukan.', 404, [], 'not_found')`.
  - `show_error`/`show_exception`/`show_php_error` → `api_error(…generic message, $status_code ?: 500, [], 'internal_error')` — **never leak PHP/DB details** in the JSON `message` (log them server-side as today).
- Keep `MY_Output::emit_security_headers()` emission for both branches (existing behavior must not regress).
- Non-AJAX paths delegate to parent unchanged (stock HTML error pages stay; branded pages are a separate P7 sub-item intentionally deferred).
- Bonus hardening (optional, same round if trivial): Admin constructor 302 on expired `admin_id` mid-AJAX → check `is_ajax_request()` and emit 401 JSON (`api_error`) instead of redirect, mirroring the user-side pattern.

## 9. Incremental execution batches & verification protocol

Order = lowest blast radius first; every batch lands independently and is fully verified before the next.

### Batch 0 — Contract + choke point (no behavior change)
1. This document (`plan/76`).
2. Add `application/helpers/api_helper.php` (additive; **no call sites converted yet**).
3. Refactor `rate_limit_json_response()` to delegate to `api_error(...)` (byte-identical output).
- Verify: `php -l application/helpers/api_helper.php application/helpers/ratelimit_helper.php`; curl an existing 429 trigger (5 rapid AJAX `claim_wage` POSTs) and diff body keys/status against pre-change contract.

### Batch A — Read-notification endpoints (#4, #5, #6)
- Convert `Notification::mark_all_read`, `Notification::mark_read_single`, `User::read_notifications` to helper; success `200 {success,message,data:{unread_count}}` + legacy `unread_count`; unauth → `401 {success:false,message:'Sesi habis. Silakan login ulang.',errors:[],code:'unauthenticated'}` + legacy `error`.
- Verify: `php -l` (3 controllers); curl unauth POST → 401 JSON; authed POST → 200 with `success` AND `unread_count` present; `php -S` + manual click check: header badge hidden on dropdown open, notification page "Tandai semua" button state machine intact; confirm no JS edits needed (grep: consumers read only `success`).

### Batch B — Team claim endpoints (#2, #3)
- Convert `Team::claim_level1` / `Team::claim_wage`; keep every `code` the JS branches on (`claimed`, `already_claimed`, `cycle_not_ready`, `not_qualified`, `unauthenticated`, `error`); legacy `new_balance` (success only), `level`, `amount`; session → 401 (new, safe); `claim_wage` keeps HTTP 500 for `code==='error'`.
- Verify: `php -l`; curl happy path (assert `success`,`new_balance`,`code:'claimed'`), double-claim (`already_claimed`), cooldown (`cycle_not_ready`), unauth (401); team page button/spinner/toast flows via browser smoke.

### Batch C — Captcha refresh (#1)
- Convert `Auth::refresh_captcha`; body gains `success/message/data` while top-level `svg`/`expires_in` remain; HTTP stays 200; non-AJAX still redirects to `login`.
- Verify: `php -l`; curl `GET` with `X-Requested-With: XMLHttpRequest` → 200 JSON containing `svg` (valid `<svg` string) AND `success:true`; curl without header → 302 to login; login/register refresh buttons still swap the SVG without page reload.

### Batch D — Admin endpoints (#7, #8, #9)
- `toggle_registration`: add JSON content-type to the 405 branch; failure payload gains `error` alias (fixes dashboard "Unknown error"); keep `success`/`is_open`/`message`.
- `chart_data`: wrap as `{success:true,message:'',data:{labels,data}}` + legacy top-level `labels`,`data`.
- `user_xray`: 200 wrapped `data` unchanged shape; 404 → `{success:false,message:'User not found',errors:[],code:'not_found'}` + legacy `error:'User not found'`.
- Verify: `php -l`; curl toggle POST → 200 `success/is_open/error-absent`; GET → 405 JSON with `application/json` content-type; chart `?days=30` → `labels`+`data`+`success`; xray existing id → `data.user.phone` etc.; xray 999999 → 404 JSON with `error`; dashboard breaker button + analytics x-ray modal smoke.

### Batch E — P7 JSON envelope (#MY_Exceptions)
- Implement §8 detector + JSON 404/500 emission.
- Verify: `php -l`; curl `POST /notification/mark_all_read` with header to a forced-error route → 500 JSON (status + `success:false`); curl any bogus URL with `X-Requested-With` → 404 JSON; without header → stock HTML 404 unchanged; security headers present in both.

### Cross-cutting regression (run after the last batch)
- `php -l` every touched PHP file.
- Grep-assert no consumer JS file changed and that each consumer-read key still exists in its endpoint body (checklist §10).
- Full manual flow (`php -S localhost:8080`): register → captcha refresh → login → topup → deposit approve → checkout → ROI claim → team L1/wage claim (success/fail/cool-down states) → notification mark-all → admin: breaker toggle, chart period switch, leaderboard x-ray → all buttons/modals/spinners show no uncaught JS exceptions (browser console clean on `r.json()` paths).

## 10. Consumer key-preservation checklist (grep-assert each batch)

| Consumer | Keys that must still exist post-batch |
|---|---|
| `auth/login.php` / `auth/register.php` (refresh_captcha) | `svg` (top-level); HTTP 200 |
| `team/index.php` `claimLevel1` | `success`, `message`, `new_balance` |
| `team/index.php` `claimWage` | `success`, `code`, `message`, `new_balance`, `level`, `amount` |
| `templates/header.php` (both call sites) | `success` |
| `notification/index.php` `markAllRead` | `success` |
| `admin/dashboard.php` `toggleRegistration` | `success`, `is_open`, `error` (failure) |
| `admin/analytics.php` `openXray` | `success`, `error`, `data.*` |
| `admin/templates/footer.php` chart | `labels`, `data` |

## 11. Risks & mitigations

- **Envelope shape regressions** → dual-key rule is enforced per batch; grep checklist §10 gates each merge.
- **`r.json()` on HTML** (P7) → Batch E closes the last HTML-on-AJAX path; until then batches A–D already route every in-scope body through the helper (always JSON content-type).
- **Rate-limit behavior drift** → Batch 0 delegates but must produce byte-identical 429 bodies (diff test).
- **Scope creep (M10/M7/M5)** → explicitly out of scope §2; only conformance of the orphan endpoint is touched.
- **Flashdata/HTML leaking into messages** → helper call sites pass plain string messages; `JSON_HEX_*` flags mitigate markup injection into JSON.

## 12. Planned file touch list (code batches, after this doc is approved for execution)

| File | Batch | Change |
|---|---|---|
| `plan/76_M9_P7_UNIFIED_API_RESPONSE_PLAN.md` | 0 | this document (done) |
| `application/helpers/api_helper.php` | 0 | NEW choke-point helper |
| `application/helpers/ratelimit_helper.php` | 0 | delegate 429 body to helper |
| `application/controllers/User.php` | A | envelope + 401 |
| `application/controllers/Notification.php` | A | envelope + 401 (both methods) |
| `application/controllers/Team.php` | B | envelope + legacy keys (both methods) |
| `application/controllers/Auth.php` | C | envelope + legacy keys (`refresh_captcha`) |
| `application/controllers/Admin.php` | D | envelope + content-type fix + aliases (3 methods) |
| `application/core/MY_Exceptions.php` | E | AJAX JSON 404/500 envelope |
| No view/JS files | — | intentionally none |

---

*End of plan/76 — read-only blueprint; no application code changed at the time of writing.*
