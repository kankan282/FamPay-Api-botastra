# TESTING REPORT — FamPay Payment Gateway v2.0

**Date:** 2026-09-05
**Tested by:** automated build + test run before packaging
**Test environment:** PHP 8.4.23 CLI/built-in server, PostgreSQL 17.10, GD 2.3.3, Composer 2.10, OpenCV 4.x (QR decoding)
**Target environment:** `php:8.1-apache` (Dockerfile) + Render free PostgreSQL

---

## 1. Summary

| Suite | Passed | Failed | Skipped |
| --- | --- | --- | --- |
| PHP syntax lint (14 files) | 14 | 0 | 0 |
| `/test-db.php` — database | 16 | 0 | 0 |
| `/test-qr.php` — QR + logo | 12 | 0 | 0 |
| `/test-imap.php` — IMAP + parser | 7 | 1\* | 1 |
| `/test-all.php` — full system | 24 | 0 | 3\* |
| `tests/integration-test.py` — end to end | 49 | 0 | 0 |
| **Total** | **122** | **1\*** | **4** |

\* The single failure is environmental, not a code defect: **PHP 8.4 removed `ext-imap` from core**, so the sandbox used for testing cannot load it. The deployment image (`php:8.1-apache`) compiles `imap` with Kerberos + SSL, and every IMAP code path is guarded by `imap_available()` so the app degrades gracefully with HTTP 503 `IMAP_UNAVAILABLE` instead of fataling. The skipped checks are the live Gmail login (needs real credentials) and the loopback endpoint calls (the single-threaded PHP dev server cannot answer its own request; they run on Apache/Render).

---

## 2. Bugs found and fixed

| # | Severity | Bug | Fix |
| --- | --- | --- | --- |
| 1 | **Critical** | Time-zone skew: PostgreSQL sessions run in UTC while PHP ran in `Asia/Kolkata`, so naive `CURRENT_TIMESTAMP` values were read 5 h 30 m in the past. Every order was reported **expired immediately** (`verify.php` returned 410 seconds after creation) and `expires_at` was wrong. | `db()` now executes `SET TIME ZONE '<APP_TIMEZONE>'` right after connecting. Regression test: *"order past 15 min → 410 EXPIRED"* + *"settled order returns SUCCESS"*. |
| 2 | High | E-mail parser leaked the following line into `payer_name` on HTML e-mails (`"Priya Nair Transacti"`). | Name patterns now run on the line-preserved text with the `m` modifier and additional stop words (`Transaction`, `Txn`, `Ref`, `UTR`, `:`, `-`). |
| 3 | High | 8 Lucide icon names used in the UI (`alert-circle`, `alert-triangle`, `alert-octagon`, `check-circle`, `check-circle-2`, `home`, `plus-circle`, `trash-2`) are legacy aliases that no longer exist in current Lucide — those icons would silently render as nothing. | Replaced with canonical names (`circle-alert`, `triangle-alert`, `octagon-alert`, `circle-check`, `circle-check-big`, `house`, `circle-plus`, `trash`). All **43** icon names were then validated against Lucide's official `tags.json` (1 807 icons) → 0 missing. |
| 4 | High | Render routes traffic to the port the container binds (`$PORT`, normally 10000), but `php:8.1-apache` hard-codes port 80 → deploy would hang on "health check failed". | Added `docker-entrypoint.sh`, which rewrites `ports.conf` and the vhost to `$PORT` before starting Apache; `HEALTHCHECK` uses `$PORT` too. |
| 5 | Medium | `Dockerfile` installed `mbstring` (already bundled in the official image → build warning/failure risk) and `pdo_mysql` (unused). | Extension list trimmed to `pdo pdo_pgsql imap gd zip opcache`, parallel build (`-j$(nproc)`), Composer install with a fallback, plus a production `php.ini` fragment. |
| 6 | Medium | Admin panel redirected to a raw `REQUEST_URI` (header-injection surface). | Path is now sanitised with a whitelist regex before being used in `Location:`. |
| 7 | Medium | `/test-all.php?http=1` reported 4 false failures when run under the single-threaded PHP dev server. | The suite probes the loopback first and marks the section **skipped** with an explanation. |
| 8 | Low | Test data expected `user@fam.com` to be a valid VPA — UPI PSP handles never contain a dot. | Test expectation corrected; the strict regex was kept. |
| 9 | Low | `.htaccess` did not block `vendor/`, `migrations/`, `tests/`, `*.md`, `*.sql`. | Added deny rules, `Options -Indexes`, security headers, and extension-less API routes. |
| 10 | Low | Gmail app passwords were about to be stored in clear text; users paste them with spaces. | Spaces stripped before use; passwords encrypted at rest with AES-256-CBC (`APP_SECRET`) and decrypted only for the IMAP call. |

---

## 3. Detailed results

### 3.1 Syntax / configuration
- `php -l` on all 14 PHP files → **no syntax errors**.
- `sh -n docker-entrypoint.sh` → OK.
- `render.yaml` parsed as valid YAML; `composer.json` valid JSON; `composer install` resolves `chillerlan/php-qrcode 5.0.5`.
- Emoji scan across every text file → **0 emojis** (icons only, as required).

### 3.2 Database (`/test-db.php`, 16/16)
- PDO pgsql driver present; connection to PostgreSQL 17.10 established.
- `db_ensure_schema()` applied `migrations/001_initial_schema.sql` idempotently.
- Tables verified: `orders`, `api_keys`, `master_keys`, `payment_logs`.
- Indexes verified: `idx_order_id`, `idx_status`, `idx_created_at`, `idx_api_key`, `idx_gmail`, `idx_master_key`, `idx_order_log`, `idx_action`.
- CRUD round trip: INSERT → SELECT → UPDATE (with `JSONB` write) → JSONB read-back → DELETE, all with prepared statements.
- Injection probe `x' OR '1'='1` through a prepared statement returned 0 rows (no bypass).
- Expiry sweep (`UPDATE ... interval '15 minutes'`) executed successfully.

### 3.3 QR + FamPay logo (`/test-qr.php`, 12/12)
- Engine: `chillerlan/php-qrcode` 5.0.5, **ECC level H**, 400 × 400 px output.
- Centre image loaded from `assets/fampay-logo.png` (256 × 256, alpha), scaled to 92 px on a 112 px white rounded badge, centred at (154, 154) — 23 % logo / 28 % badge of a 400 px QR.
- Rendered for amounts **1.00 / 100.00 / 10 000.00 / 100 000.00** — logo embedded in all four.
- Rendered for VPAs `kankan1@fam`, `merchant.store@ybl`, `test-user_9@okaxis`.
- **Scanability proof:** the finished PNG (with the centre image) was decoded with OpenCV's `QRCodeDetector` and returned exactly `upi://pay?pa=kankan1%40fam&pn=FamPay&am=499.00&cu=INR&tn=Order%20FAM...&tr=FAM...`.
- **Badge size sweep:** 10 payloads × 4 badge sizes (92/112, 88/108, 84/104, 80/100) decoded at full size and downscaled to 240 px. One payload failed in every configuration **including a control run with no logo at all**, confirming an OpenCV detector quirk rather than damage from the overlay. 92 px / 112 px was kept.
- **Apache verification:** the whole app was served by real Apache 2.4 + mod_php with `.htaccess` deleted, using the entrypoint's `$PORT` rewrite: `/` 200, `/cpanel-admin-2025` 200, `/qr` 401, `/create-key.php` 201, and `/config.php`, `/composer.json`, `/.env`, `/vendor/*`, `/migrations/*`, `/tests/*`, `/README.md` all 403.
- Fallback A — `vendor/` removed: QR produced through `api.qrserver.com` (ecc=H) **with the logo still overlaid**.
- Fallback B — `assets/` removed and remote sources unreachable: plain valid QR returned, `has_fampay_logo:false`, reason logged and surfaced as `logo_warning`.
- `logo=0` produces a plain QR on demand.

### 3.4 Gmail / IMAP (`/test-imap.php`, 7 passed, 1 environmental fail, 1 skipped)
- App-password encrypt/decrypt round trip (AES-256-CBC) OK; spaces stripped.
- Parser validated against four realistic samples:
  - FamPay credit alert → amount 100, UTR 412345678901, payer "John Doe", VPA `john@ybl`, credit ✔
  - Bank credit with thousands separator → amount 2 500.50, UTR 987654321012, payer "RAHUL SHARMA" ✔
  - HTML body with `&#8377;` entity → amount 750, ref `HDFC0012345678`, payer "Priya Nair" ✔
  - Debit alert → correctly **not** treated as a credit ✔
- Invalid credentials / missing extension both fail gracefully with a descriptive message (no fatal error, no leaked stack trace).
- Matching rules exercised in code review + unit level: amount equality to the paisa, mail newer than the order (5 min clock-skew grace), credit direction, and UTR uniqueness across orders.

### 3.5 API endpoints (`tests/integration-test.py`, 49/49)
`create-key.php`
- wrong password → 401 `UNAUTHORIZED`; missing password → 400 `MISSING_PARAMETER`.
- valid password → 201 with a 6-character alphanumeric key.
- Works via query string, POST form body and JSON body.

`qr.php`
- Valid request → 201, order ID matching `FAM + YYYYMMDD + 8`, `has_fampay_logo:true`, base64 data URI, UPI deep link, `expires_in_minutes:15`.
- `?order_id=...&format=png` streams the stored PNG (19 kB, `image/png`).
- Rejections: `INVALID_UPI` (422), `INVALID_AMOUNT` for 0 and 100001 (422), `MISSING_PARAMETER` (400), `MISSING_API_KEY` (401), `INVALID_API_KEY` (401).
- SQL-injection payloads in `upi` and `api_key` rejected by validation before reaching the database.

`login.php`
- Requires the master key, validates the address and the app-password length, and returns 503 `IMAP_UNAVAILABLE` / 401 `IMAP_LOGIN_FAILED` instead of crashing.

`verify.php`
- Unknown gmail key → 401, unknown order → 404, malformed order id → 422, missing order id → 400.
- Order older than 15 minutes → 410 `ORDER_EXPIRED` and the row is flipped to `expired`.
- Settled order → 200 `SUCCESS` with UTR / payer / dates, returned **without** re-scanning Gmail.

### 3.6 Admin panel (13 checks)
- Login page renders; wrong password rejected (with a 0.4 s delay); correct password starts a regenerated session.
- Dashboard renders 6 stat cards, key/Gmail/order tables and the activity log; CSRF token present.
- POST without a CSRF token → blocked ("Invalid CSRF token. Action blocked.").
- Create key, enable/disable toggle, delete key, purge expired orders, clear logs — all verified through the UI.
- XSS payload `<script>alert(1)</script>` submitted as a key name is escaped in the output.
- Logout destroys the session and returns to the sign-in screen.

### 3.7 Security
- All queries use prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`).
- `clean_text()` strips tags/control characters and HTML-escapes; strict regexes for UPI, amount, e-mail, key and order ID.
- Rate limiting verified live: repeated `create-key` calls returned `401 401 401 401 401 429 429 …` (limit 10/min), with a `Retry-After` header.
- Protected paths return **403**: `/config.php`, `/composer.json`, `/.env`, `/vendor/autoload.php`, `/migrations/001_initial_schema.sql`.
- Security headers set (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`); CORS enabled for the API endpoints; `display_errors=Off` with errors sent to the log.
- Admin URL is not linked from the home page and carries `noindex, nofollow`.

### 3.8 UI / UX
- Dark palette exactly as specified (`#06080f`, `#0c1018`, `#111827`, `#6366f1`, …), Inter + monospace stacks.
- 43 Lucide icon names validated against the official icon list; icons render through `unpkg.com/lucide@latest` with a `load`-event fallback.
- Responsive grids (`auto-fit`/`minmax`) with mobile breakpoints at 720 px and 640 px; tables scroll horizontally instead of overflowing.
- Collapsible endpoint cards, copy-to-clipboard buttons with a `document.execCommand` fallback for non-secure contexts.
- **No emojis anywhere** (verified by scanner).

### 3.9 Error handling
- Database offline → `RuntimeException` converted to 503 `SERVICE_UNAVAILABLE` (no stack trace to the client).
- QR engine unavailable → 502 `QR_GENERATION_FAILED`.
- IMAP failure → 502 `IMAP_ERROR` with the underlying reason.
- Unexpected exceptions → 500 `INTERNAL_ERROR`, details written to the error log only.

---

## 4. Known limitations

1. **Live Gmail verification could not be tested end to end** — that needs a real Google account with an App Password and an actual UPI credit e-mail. The connection, parsing and matching logic is unit-tested with realistic samples and fails safely.
2. `ext-imap` is unavailable on PHP 8.4 hosts (removed from core). Deploy the bundled Docker image (PHP 8.1) or install `imap` from PECL.
3. The centre image is whatever `assets/fampay-logo.png` contains (currently the custom picture supplied by the owner; the FamPay-yellow mark remains available as `assets/fampay-logo-fam-yellow.png`). The official FamPay logo URLs returned 404 at build time, so nothing is fetched at runtime unless `FAMPAY_LOGO_URL` is set.
4. Render's free tier sleeps after 15 minutes of inactivity (first request takes ~30 s) and free PostgreSQL instances expire after 30 days.
5. Rate limiting is per-instance (file based). With multiple instances, move it to the database or Redis.

---

## 5. Final checklist

- [x] All PHP files free of syntax errors
- [x] QR generates with the FamPay logo and still decodes
- [x] All API endpoints return well-formed JSON with correct HTTP codes
- [x] Admin panel login, CSRF, delete/toggle/purge/clear all work
- [x] Schema is PostgreSQL compatible and idempotent
- [x] Dockerfile and render.yaml validated (`$PORT` handled)
- [x] No emojis in the UI; all Lucide icon names valid
- [x] Responsive layout, security headers, CORS, session management
- [x] Environment variables used for all secrets
- [x] Logo assets, documentation and this report included
