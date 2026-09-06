# FamPay Payment Gateway v2.0

UPI payment gateway in PHP that generates **FamPay-branded QR codes with the logo in the centre** and verifies incoming payments by scanning a Gmail inbox over IMAP. Built to be deployed on **Render.com** (Docker web service + free PostgreSQL).

Developer: **@lazzy_guy** ([Telegram](https://t.me/lazzy_guy))

---

## Features

- **Logo QR codes** – rendered locally with `chillerlan/php-qrcode` at error-correction level **H**, with the FamPay mark placed on a white badge in the exact centre (20 % of the QR width). Verified scannable with a real QR decoder.
- **Automatic fallbacks** – if Composer packages are missing the QR is produced through `api.qrserver.com`; if the logo cannot be loaded a plain (still valid) QR is returned and the reason is logged.
- **Order tracking** with a 15-minute expiry window and an automatic expiry sweep.
- **Gmail IMAP verification** – matches amount, credit direction, e-mail time and de-duplicates UTRs so one transfer can settle only one order.
- **Two-layer authentication** – 6-character master API key (app level) + 6-character Gmail key (inbox level).
- **Hidden admin panel** at `/cpanel-admin-2025` with stats, key management (create / pause / delete), Gmail connections, orders, activity log, purge and clear actions, CSRF protection and session auth.
- **Direct URL APIs** – every endpoint accepts GET, POST form data and JSON bodies.
- **Professional dark UI** – Inter typography, Lucide icons, no emojis, fully responsive.
- **Security** – prepared statements everywhere, strict input validation, encrypted Gmail app passwords (AES-256-CBC), rate limiting, CORS, security headers, protected config files.

---

## API endpoints

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET / POST | `/create-key.php?admin_password=...&key_name=MyApp` | Issue a master API key |
| GET / POST | `/qr.php?upi=kankan1@fam&amount=100&api_key=Ab3xKm` | Create an order + logo QR |
| GET | `/qr.php?order_id=FAM...&format=png` | Stream the stored QR image |
| GET / POST | `/login.php?gmail=you@gmail.com&app_password=xxxx&api_key=Ab3xKm` | Connect a Gmail inbox |
| GET / POST | `/verify.php?order_id=FAM...&api_key=Ab3xKm&gmail_key=Xt7pQw` | Check payment status |

Diagnostics: `/test-db.php`, `/test-qr.php`, `/test-imap.php`, `/test-all.php` (append `?format=json` for machine-readable output).

### Example response (`/qr.php`)

```json
{
  "success": true,
  "data": {
    "order_id": "FAM20260905A1B2C3D4",
    "upi_id": "kankan1@fam",
    "amount": 100,
    "status": "pending",
    "qr_code": {
      "image_url": "https://your-app.onrender.com/qr.php?order_id=FAM20260905A1B2C3D4&format=png",
      "base64": "data:image/png;base64,iVBORw0KG...",
      "upi_deeplink": "upi://pay?pa=kankan1%40fam&pn=FamPay&am=100.00&cu=INR&tn=Order%20FAM...&tr=FAM...",
      "has_fampay_logo": true,
      "engine": "chillerlan/php-qrcode",
      "size": "400x400",
      "error_correction": "H"
    },
    "expires_in_minutes": 15,
    "expires_at": "2026-09-05 12:15:00",
    "verify_url": "https://your-app.onrender.com/verify.php?order_id=FAM...&api_key=...&gmail_key=YOUR_GMAIL_KEY"
  },
  "timestamp": "2026-09-05 12:00:00"
}
```

### Error codes

| HTTP | Code | Meaning |
| --- | --- | --- |
| 400 | `MISSING_PARAMETER`, `MISSING_API_KEY` | A required parameter is absent |
| 401 | `UNAUTHORIZED`, `INVALID_API_KEY`, `INVALID_GMAIL_KEY`, `IMAP_LOGIN_FAILED` | Authentication failed |
| 403 | `API_KEY_DISABLED`, `GMAIL_KEY_DISABLED` | Key paused in the admin panel |
| 404 | `ORDER_NOT_FOUND` | Unknown order |
| 409 | `ORDER_FAILED`, `CREDENTIALS_INVALID` | Conflicting state |
| 410 | `ORDER_EXPIRED` | Past the 15-minute window |
| 422 | `INVALID_UPI`, `INVALID_AMOUNT`, `INVALID_EMAIL`, `INVALID_ORDER_ID`, `INVALID_APP_PASSWORD` | Validation failure |
| 429 | `RATE_LIMITED` | Too many requests (see `Retry-After`) |
| 500 | `INTERNAL_ERROR` | Unexpected error (details in the server log) |
| 502 | `QR_GENERATION_FAILED`, `IMAP_ERROR` | Downstream failure |
| 503 | `SERVICE_UNAVAILABLE`, `IMAP_UNAVAILABLE` | Database unreachable / IMAP extension missing |

---

## Requirements

- PHP **8.1+** with `pdo_pgsql`, `gd`, `imap`, `mbstring`, `openssl`, `curl`
- PostgreSQL 13+
- Composer (for `chillerlan/php-qrcode`)
- Apache with `mod_rewrite` and `mod_headers` (the bundled Dockerfile provides everything)

---

## Local setup

```bash
# 1. dependencies
composer install

# 2. configuration
cp .env.example .env      # then edit DB_* and ADMIN_PASSWORD

# 3. database (schema is also applied automatically on first request)
psql "$DATABASE_URL" -f migrations/001_initial_schema.sql

# 4. run
php -S 0.0.0.0:8080 -t . tests/router.php
```

Open <http://localhost:8080>, the admin panel at <http://localhost:8080/cpanel-admin-2025>, and the self tests at `/test-all.php`.

> The PHP built-in server is single threaded, so `/test-all.php?http=1` skips the loopback endpoint checks. Use `tests/integration-test.py` locally or run the checks on Apache/Render.

---

## Environment variables

| Variable | Default | Description |
| --- | --- | --- |
| `DATABASE_URL` | – | Full PostgreSQL URL (takes priority over `DB_*`) |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | `localhost` / `5432` / `fampay` / `fampay_user` / – | Individual DB settings |
| `DB_SSLMODE` | `prefer` | Use `require` on Render |
| `ADMIN_PASSWORD` | `kankan201028` | Admin panel + create-key password |
| `APP_URL` | auto-detected | Public base URL used in generated links |
| `APP_SECRET` | derived | Key used to encrypt stored Gmail app passwords |
| `APP_TIMEZONE` | `Asia/Kolkata` | Applied to PHP **and** the PostgreSQL session |
| `ORDER_EXPIRY_MINUTES` | `15` | Order lifetime |
| `QR_SIZE` / `QR_LOGO_SIZE` / `QR_LOGO_BG_SIZE` | `400` / `92` / `112` | QR geometry (pixels): 23 % logo on a 28 % badge |
| `QR_LOGO_SHAPE` | `rounded` | Badge shape behind the centre image (`rounded` or `circle`) |
| `QR_MERCHANT_NAME` | `FamPay` | Payee name inside the UPI deep link |
| `RATE_LIMIT_REQUESTS` / `RATE_LIMIT_WINDOW` | `60` / `60` | Default per-IP limit |
| `FAMPAY_LOGO_URL` | – | Optional override for the logo used in the overlay |

---

## Project structure

```
fampay-gateway/
├── Dockerfile                  Render deployment image (PHP 8.1 + Apache + imap/gd/pdo_pgsql)
├── docker-entrypoint.sh        Binds Apache to Render's $PORT
├── render.yaml                 Blueprint: web service + free PostgreSQL
├── apache-config.conf          Virtual host
├── composer.json / .lock       PHP dependencies
├── .htaccess                   Rewrites, file protection, security headers
├── .env.example                Environment template
├── config.php                  Configuration, DB connection, schema bootstrap
├── index.html                  API documentation home
├── cpanel-admin-2025.php       Hidden admin panel
├── qr.php / verify.php / login.php / create-key.php     API endpoints
├── test-db.php / test-qr.php / test-imap.php / test-all.php   Diagnostics
├── api/
│   ├── helpers.php             Validation, auth, logging, IMAP scanner
│   ├── qr-generator.php        QR rendering + FamPay logo overlay
│   └── test-ui.php             Renderer for the diagnostic pages
├── assets/
│   ├── fampay-logo.png            Image drawn in the QR centre
│   ├── fampay-logo-base64.txt     Base64 backup of the same asset
│   └── fampay-logo-fam-yellow.png Alternative FamPay yellow mark
├── migrations/001_initial_schema.sql
└── tests/
    ├── router.php              Router for the PHP built-in server
    └── integration-test.py     End-to-end test suite (49 checks)
```

---

## Gmail setup (required for verification)

1. Enable **2-Step Verification** on the Google account.
2. Create an **App Password** (Google Account → Security → App passwords).
3. Call `/login.php?gmail=you@gmail.com&app_password=THE16CHARS&api_key=YOUR_MASTER_KEY`.
4. Store the returned `gmail_key` – it is required by `/verify.php`.

The inbox must actually receive UPI/FamPay credit notifications; the scanner reads messages received after the order was created and matches the amount to the paisa.

---

## The image in the centre of the QR

| File | Purpose |
| --- | --- |
| `assets/fampay-logo.png` | The picture drawn in the middle of every QR (currently your custom image, 256 × 256, rounded corners) |
| `assets/fampay-logo-base64.txt` | Base64 backup used if the PNG cannot be read |
| `assets/fampay-logo-fam-yellow.png` | Alternative FamPay-yellow "fam" mark — copy it over `fampay-logo.png` to switch back |

To use another picture, just replace `assets/fampay-logo.png` with a square PNG (256 × 256 or larger) and redeploy — or point `FAMPAY_LOGO_URL` at a remote image. Tune `QR_LOGO_SIZE`, `QR_LOGO_BG_SIZE` and `QR_LOGO_SHAPE` if you want it bigger/smaller or on a circular badge; `&logo=0` on `/qr.php` returns a plain QR. Every size in this package was decode-tested, so the QR stays scannable.

---

## License

MIT – see `composer.json`. Built by **@lazzy_guy**.
