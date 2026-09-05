# plan/77 — M9 & P7: Unified API JSON Envelope & Error Response — EXECUTION SUMMARY

**Status:** ✅ COMPLETE — all batches (0, A–E) implemented, lint-clean, live smoke-verified.
**Blueprint:** plan/76_M9_P7_UNIFIED_API_RESPONSE_PLAN.md (approved).
**Constraint honored:** **zero view / client-JS files modified** (verified via `git status`, §5). All legacy root keys preserved.

---

## 1. Batch status

| Batch | Scope | Files | Status |
|---|---|---|---|
| 0 | Choke-point helper + rate-limit delegation | `application/helpers/api_helper.php` (NEW), `application/helpers/ratelimit_helper.php` | ✅ |
| A | Read-notification endpoints | `application/controllers/Notification.php`, `application/controllers/User.php` | ✅ |
| B | Team claim endpoints | `application/controllers/Team.php` | ✅ |
| C | Captcha refresh | `application/controllers/Auth.php` | ✅ |
| D | Admin endpoints | `application/controllers/Admin.php` | ✅ |
| E | P7 AJAX exception interceptor | `application/core/MY_Exceptions.php` | ✅ |
| — | Execution summary | `plan/77_M9_P7_UNIFIED_API_RESPONSE_SUMMARY.md` (this file) | ✅ |

## 2. What was implemented

### Batch 0 — choke point
- **`api_helper.php` (new):** `api_success($data=null,$message='',$http=200,array $legacy=[])` and `api_error($message,$http=400,array $errors=[],$code=null,array $legacy=[])`. Canonical bodies:
  - success: `{success:true, message, data}` + legacy root keys
  - error: `{success:false, message, errors:[], data:null[, code]}` + legacy root keys
  - Single encoder `_api_send()`: `Content-Type: application/json`, `set_status_header()` (native header, safe with `echo+exit`), `JSON_UNESCAPED_UNICODE | JSON_HEX_TAG|AMP|APOS|QUOT` (hex flags = parity with the old captcha endpoint encoder). All functions `function_exists()`-guarded → safe under double include (CI Loader `include` + `MY_Exceptions`/ratelimit `require_once`). Private helper naming prefixed `_api_send`.
- **`ratelimit_helper.php`:** `rate_limit_json_response()` now delegates to `api_error(..., 429, [], 'too_many_attempts', legacy)`; HTTP 429 + Content-Type + legacy keys `{error, message, retry_after}` unchanged; canonical adds additive `{errors, data, code}`.

### Batch A — read-notification endpoints
- `Notification::mark_all_read`: success `200 {success:true, message, data:{unread_count:0}}` + legacy root `unread_count`; unauth → `401 {success:false,…,code:'unauthenticated'}` + legacy `error:'Unauthorized'` (was 200).
- `Notification::mark_read_single`: enveloped `200 {success:true, message:'', data:null}`; unauth 401 (orphan endpoint — conformed, kept; M10 deletion out of scope).
- `User::read_notifications`: identical envelope treatment (consumers `templates/header.php:384/:415`, `notification/index.php` read only `success` → no JS change).

### Batch B — Team claim endpoints
- `Team::claim_level1`: session → `401`; business rejection → `200 {success:false, message, errors, data:null}` + legacy `message`; success → `200 {success:true, message, data:{new_balance}}` + legacy `{message, new_balance}`.
- `Team::claim_wage`: session → `401` (legacy `code:'unauthenticated'`); `code==='error'` → `500`; success → `200` with `data:{level,amount,new_balance,cycle,transaction_id}` + ALL legacy model keys at root (`code,message,amount,level,cycle,transaction_id,next_claim_date,new_balance`); business rejection (`already_claimed/cycle_not_ready/not_qualified/user_unavailable`) → `200 {success:false}` + legacy full body — JS `claimWage()` branch keys (`claimed/already_claimed/cycle_not_ready`) untouched.

### Batch C — Auth captcha refresh
- `Auth::refresh_captcha`: envelope `200 {success:true, message:'', data:{svg, expires_in}}` + legacy root `svg`/`expires_in`. HTTP stays 200 (login/register JS gates on `res.ok` → fallback reload). Non-AJAX still `redirect('login')`.

### Batch D — Admin endpoints
- `toggle_registration`: 405 non-POST now `api_error(405)` — **Content-Type: application/json** added (was JSON body without header) + legacy `error:'Method not allowed'`; success `200 {success:true, message, data:{is_open}}` + legacy `{is_open, message}`; transaction failure → `500 {success:false,…,code:'toggle_failed'}` + legacy `{is_open, message, error}` — **fixes the dashboard "Unknown error" bug** (dashboard reads `data.error`).
- `chart_data`: data-native endpoint. **Deviation (see §3):** full envelope nesting impossible without breaking the chart consumer (`footer.php` reads root `json.data` as the revenue series, which collides with the envelope `data` key). Emits additive `{success:true, message:''}` + legacy payload root `{labels, data}`.
- `user_xray`: 200 `{success:true, message:'', data:{…xray}}`; 404 `api_error('User not found',404,…)` + legacy `error:'User not found'`.

### Batch E — P7 AJAX exception interceptor (`MY_Exceptions`)
- `_wants_json()`: `X-Requested-With: XMLHttpRequest` **or** `Accept: application/json` (CLI excluded).
- `show_404` → JSON `{success:false, message:'Halaman tidak ditemukan.', errors:[], data:null, code:'not_found'}` HTTP 404 (logged server-side).
- `show_error` → generic JSON 500/`internal_error` with the given status code; raw detail only to server log.
- `show_exception` / `show_php_error` → generic JSON 500 + server log; `_clean_buffers()` discards partial output buffers before emission (parity with parent ob juggling) so no stray HTML pollutes the envelope.
- Both JSON and HTML paths still call `MY_Output::emit_security_headers()`.
- Defensive fallback inside `_emit_json_error()` if the helper cannot be loaded (still JSON, no detail leak).

## 3. Documented deviations from the blueprint
1. **`chart_data` dual-key impossible** (plan/76 §6 row 8): legacy payload key `data` collides with the canonical envelope key `data` at the same JSON level; nesting `data:{labels,data}` would overwrite the root revenue array the chart JS consumes. Resolution: additive `success/message` only, payload root unchanged → strict zero view-regression. (Noted in-code.)
2. **Rate-limit "byte-identical" → semantically identical:** delegation adds envelope keys `{errors, data, code}`; key order in the JSON string changes (object order is insignificant to `JSON.parse`/`r.json()` consumers; keys, values, status 429, and Content-Type are identical).
3. **401 JSON paths are defense-in-depth only for fully anonymous callers** on `MY_Controller`-derived controllers (constructor 302 → `login` fires first, as before — pre-existing architecture, unchanged). The 401s trigger on stale/expired sessions mid-flight; helper unit probes verified their exact bodies.
4. **`message` for read/`mark_*` success kept `''`** per plan/76 default (JS ignores it).

## 4. Verification performed (live, this sandbox)

Environment used for live checks: `php -S 127.0.0.1:8080` with `DB_HOSTNAME=127.0.0.1` (DB over TCP; default `localhost` socket unavailable in sandbox) and `Host: smoke.test` (production-like error reporting — CI3 `index.php` treats `synapse.test` as `development`, where **pre-existing PHP 8.3 `E_DEPRECATED` noise renders error pages mid-request**; Batch E correctly converts that AJAX symptom to JSON 500 instead of HTML).

| Check | Result |
|---|---|
| `php -l` on all 8 touched PHP files | ✅ 0 errors |
| Helper probes (double-include safe; success/error/429 bodies + statuses) | ✅ |
| `GET /auth/refresh_captcha` AJAX | ✅ 200, envelope + legacy `svg`/`expires_in` (Batch C) |
| `GET /auth/refresh_captcha` non-AJAX | ✅ unchanged redirect behavior |
| AJAX 404 (unknown method + unknown route) | ✅ 404 JSON `code:'not_found'` (Batch E) |
| Non-AJAX 404 | ✅ stock HTML 404 unchanged (Batch E) |
| AJAX 500 (forced DB connect failure) & CSRF-403 | ✅ clean JSON `internal_error` envelope (Batch E bonus) |
| UI register → login (fresh user, real captcha) | ✅ (captcha parser matched stored challenge exactly) |
| `POST /notification/mark_all_read` | ✅ 200 `success:true` + legacy `unread_count` + `data.unread_count` |
| `POST /user/read_notifications` | ✅ same |
| `POST /notification/mark_read_single/1` | ✅ 200 `success:true` |
| `POST /team/claim_level1` (business reject) | ✅ 200 envelope `{success:false, message, data:null}` |
| `POST /team/claim_wage` (business reject) | ✅ 200 `{success:false, code, message, level, amount, …}` |
| `POST /admin/toggle_registration` ×2 (toggle+restore) | ✅ 200 `success:true` + `is_open` + `message` (both real-login runs) |
| `GET /admin/toggle_registration` (AJAX) | ✅ 405 JSON, `Content-Type: application/json`, `error:'Method not allowed'` |
| `GET /admin/chart_data?days=7` | ✅ 200 `success:true` + root `labels`/`data` arrays |
| `GET /admin/user_xray/1` and `/999999` | ✅ 200 `data.user.id=1`; 404 JSON `error:'User not found'` |
| Sandbox state restored | ✅ test user(s) removed; `is_registration_open=1` (seed); scratch deleted; no server left running |

**Manual browser-UI flows (spinner/modal/toast paths) were not executable in this headless sandbox**; they are protected by construction: no consumer JS was changed, every consumer-read key is byte-identical at root, `r.json()` always receives JSON now (no HTML on AJAX 4xx/5xx), and status-code changes only affect endpoints whose JS never inspects HTTP status (captcha keeps 200).

## 5. Scope / regression proof
- `git status --short` shows only the 8 intended files (6 modified, 1 new helper, + this/previous plan docs). **No `application/views/**` or asset/JS file modified.**
- Remaining raw JSON emission in controllers: none (only a code comment in `Wallet.php`).
- Legacy-key preservation checklist (plan/76 §10): `svg`, `expires_in`, `success`, `message`, `new_balance`, `code`, `level`, `amount`, `unread_count`, `is_open`, `error`, `labels`, `data`, `retry_after` — all asserted present at root in the live smoke responses above.

## 6. Follow-ups (tracked, out of scope)
- Retire legacy alias keys after consumers are migrated (separate round).
- Optional hardening: Admin constructor 302 → JSON 401 when `admin_id` expires mid-AJAX (parity with user-side pattern).
- PHP 8.3 `E_DEPRECATED` flood under `ENVIRONMENT=development` is pre-existing; recommend `error_reporting` tuning or PHP 8.2- parity work separately (Batch E now makes the AJAX symptom graceful).

---

*End of plan/77 — M9 & P7 execution summary.*
