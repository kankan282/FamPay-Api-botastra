# Local test tools (development only)

These files are not required in production; Apache never serves the `tests/`
directory (`.htaccess` and `apache-config.conf` both deny it).

## router.php

Emulates the `.htaccess` rewrites for PHP's built-in web server:

```bash
composer install
cp .env.example .env    # point DB_* at a local PostgreSQL
php -S 0.0.0.0:8080 -t . tests/router.php
```

## integration-test.py

End-to-end suite (49 checks) covering the four APIs, order lifecycle,
file protection and the admin panel (login, CSRF, create/toggle/delete,
purge, clear logs, XSS escaping). Optionally decodes the generated QR with
OpenCV to prove it is still scannable with the logo overlay.

```bash
python3 tests/integration-test.py http://127.0.0.1:8080 kankan201028
```

Only the Python standard library is required; install `opencv-python` and
`numpy` to enable the QR decode check.

## Browser diagnostics

`/test-db.php`, `/test-qr.php`, `/test-imap.php`, `/test-all.php`
(append `?format=json` for machine-readable output).
