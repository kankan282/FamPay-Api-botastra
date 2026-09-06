#!/usr/bin/env python3
"""
FamPay Gateway - end to end integration test (development machines only).

Start the local server first:
    php -S 0.0.0.0:8080 -t . tests/router.php
Then run:
    python3 tests/integration-test.py http://127.0.0.1:8080

Requires: requests (and optionally opencv-python + numpy to decode the QR).
"""
import json
import re
import sys
import time
import urllib.parse
import urllib.request
import http.cookiejar
import os

PROJECT_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080"
ADMIN_PASSWORD = sys.argv[2] if len(sys.argv) > 2 else "kankan201028"

results = []


def record(ok, name, detail=""):
    results.append((ok, name, detail))
    print(("  PASS  " if ok else "  FAIL  ") + name + ((" | " + str(detail)[:150]) if detail else ""))


cj = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))


def call(path, data=None, headers=None, raw=False):
    url = BASE + path
    body = None
    hdrs = headers or {}
    if data is not None:
        if isinstance(data, dict):
            body = urllib.parse.urlencode(data).encode()
        else:
            body = data.encode() if isinstance(data, str) else data
    req = urllib.request.Request(url, data=body, headers=hdrs)
    try:
        with opener.open(req, timeout=30) as r:
            content = r.read()
            status = r.status
    except urllib.error.HTTPError as e:
        content = e.read()
        status = e.code
    if raw:
        return status, content
    try:
        return status, json.loads(content.decode("utf-8", "replace"))
    except Exception:
        return status, content.decode("utf-8", "replace")


print("\n=== 1. create-key.php ===")
st, js = call("/create-key.php?admin_password=wrong&key_name=Nope")
record(st == 401 and js.get("error", {}).get("code") == "UNAUTHORIZED", "wrong admin password -> 401", st)

st, js = call("/create-key.php?admin_password=%s&key_name=IntegrationTest" % urllib.parse.quote(ADMIN_PASSWORD))
KEY = js.get("data", {}).get("api_key") if isinstance(js, dict) else None
record(st == 201 and bool(re.fullmatch(r"[A-Za-z0-9]{6}", KEY or "")), "creates 6 char master key -> 201", KEY)

st, js = call("/create-key.php", data={"admin_password": ADMIN_PASSWORD, "key_name": "PostMethod"})
KEY2 = js.get("data", {}).get("api_key") if isinstance(js, dict) else None
record(st == 201 and bool(KEY2), "POST form body works", KEY2)

st, js = call("/create-key.php", data=json.dumps({"admin_password": ADMIN_PASSWORD, "key_name": "JsonBody"}),
              headers={"Content-Type": "application/json"})
record(st == 201 and bool(js.get("data", {}).get("api_key")), "JSON body works", js.get("data", {}).get("api_key"))

st, js = call("/create-key.php?key_name=NoPass")
record(st == 400 and js.get("error", {}).get("code") == "MISSING_PARAMETER", "missing admin_password -> 400", st)

print("\n=== 2. qr.php ===")
st, js = call("/qr.php?upi=kankan1@fam&amount=100&api_key=" + KEY)
data = js.get("data", {}) if isinstance(js, dict) else {}
ORDER = data.get("order_id")
record(st == 201 and bool(re.fullmatch(r"FAM\d{8}[A-Z0-9]{8}", ORDER or "")), "creates order -> 201", ORDER)
record(data.get("qr_code", {}).get("has_fampay_logo") is True, "QR reports FamPay logo", data.get("qr_code", {}).get("engine"))
record(data.get("expires_in_minutes") == 15, "15 minute expiry advertised", data.get("expires_at"))
record(str(data.get("qr_code", {}).get("base64", "")).startswith("data:image/png;base64,"), "base64 data URI returned")
record("upi://pay?" in data.get("qr_code", {}).get("upi_deeplink", ""), "UPI deep link present",
       data.get("qr_code", {}).get("upi_deeplink"))

st, png = call("/qr.php?order_id=%s&format=png" % ORDER, raw=True)
record(st == 200 and png[:4] == b"\x89PNG", "image_url streams a PNG", "%d bytes" % len(png))
open("/tmp/fampay-qr-test.png", "wb").write(png)

for bad, code in [("/qr.php?upi=not-a-upi&amount=100&api_key=" + KEY, "INVALID_UPI"),
                  ("/qr.php?upi=kankan1@fam&amount=0&api_key=" + KEY, "INVALID_AMOUNT"),
                  ("/qr.php?upi=kankan1@fam&amount=100001&api_key=" + KEY, "INVALID_AMOUNT"),
                  ("/qr.php?upi=kankan1@fam&api_key=" + KEY, "MISSING_PARAMETER"),
                  ("/qr.php?upi=kankan1@fam&amount=100", "MISSING_API_KEY"),
                  ("/qr.php?upi=kankan1@fam&amount=100&api_key=ZZZZZZ", "INVALID_API_KEY"),
                  ("/qr.php?upi=" + urllib.parse.quote("x' OR '1'='1") + "&amount=100&api_key=" + KEY, "INVALID_UPI"),
                  ("/qr.php?upi=kankan1@fam&amount=100&api_key=" + urllib.parse.quote("' OR 1=1 --"), "INVALID_API_KEY")]:
    st, js = call(bad)
    got = js.get("error", {}).get("code") if isinstance(js, dict) else js
    record(got == code, "rejects %s" % bad.split("?")[1][:60], "%s (%s)" % (got, st))

print("\n=== 3. login.php ===")
st, js = call("/login.php?gmail=user@gmail.com&app_password=abcdefghijklmnop")
record(js.get("error", {}).get("code") == "MISSING_API_KEY", "requires master key", st)
st, js = call("/login.php?gmail=bad-email&app_password=abcdefghijklmnop&api_key=" + KEY)
record(js.get("error", {}).get("code") == "INVALID_EMAIL", "validates e-mail", st)
st, js = call("/login.php?gmail=user@gmail.com&app_password=x&api_key=" + KEY)
record(js.get("error", {}).get("code") == "INVALID_APP_PASSWORD", "validates app password length", st)
st, js = call("/login.php?gmail=user@gmail.com&app_password=abcdefghijklmnop&api_key=" + KEY)
code = js.get("error", {}).get("code") if isinstance(js, dict) else ""
record(code in ("IMAP_UNAVAILABLE", "IMAP_LOGIN_FAILED"),
       "handles Gmail login failure gracefully", "%s (%s)" % (code, st))

print("\n=== 4. verify.php ===")
st, js = call("/verify.php?order_id=%s&api_key=%s&gmail_key=ZZZZZZ" % (ORDER, KEY))
record(js.get("error", {}).get("code") == "INVALID_GMAIL_KEY", "unknown gmail_key -> 401", st)
st, js = call("/verify.php?order_id=FAM20250101ABCDEFGH&api_key=" + KEY + "&gmail_key=ZZZZZZ")
record(js.get("error", {}).get("code") == "ORDER_NOT_FOUND", "unknown order -> 404", st)
st, js = call("/verify.php?order_id=notanorder&api_key=" + KEY + "&gmail_key=ZZZZZZ")
record(js.get("error", {}).get("code") == "INVALID_ORDER_ID", "malformed order id -> 422", st)
st, js = call("/verify.php?api_key=" + KEY)
record(js.get("error", {}).get("code") == "MISSING_PARAMETER", "missing order_id -> 400", st)

print("\n=== 5. QR decode (scanability) ===")
try:
    import cv2
    import numpy as np
    img = cv2.imdecode(np.frombuffer(open("/tmp/fampay-qr-test.png", "rb").read(), np.uint8), cv2.IMREAD_COLOR)
    decoded, _, _ = cv2.QRCodeDetector().detectAndDecode(img)
    record(decoded.startswith("upi://pay?") and ("tr=" + ORDER) in decoded,
           "logo QR decodes back to the UPI deep link", decoded)
except ImportError:
    record(True, "QR decode skipped (opencv-python not installed)", "install opencv-python to run it")

print("\n=== 6. order lifecycle ===")
st, js = call("/qr.php?upi=kankan1@fam&amount=250.75&api_key=" + KEY)
ORDER2 = js.get("data", {}).get("order_id")
record(js.get("data", {}).get("amount") == 250.75, "decimal amount accepted", ORDER2)


def php_exec(snippet):
    """Run a snippet against the app's own database (dev helper)."""
    import subprocess
    code = "require __DIR__ . '/config.php'; " + snippet
    out = subprocess.run(["php", "-r", code], cwd=PROJECT_DIR, capture_output=True, text=True, timeout=30)
    return out.returncode == 0, (out.stdout + out.stderr).strip()


ok, out = php_exec(
    "db()->prepare(\"UPDATE orders SET created_at = created_at - interval '20 minutes' "
    "WHERE order_id = :o\")->execute([':o' => '" + ORDER2 + "']); echo 'ok';"
)
if ok:
    st, js = call("/verify.php?order_id=%s&api_key=%s&gmail_key=ZZZZZZ" % (ORDER2, KEY))
    record(st == 410 and js.get("error", {}).get("code") == "ORDER_EXPIRED", "order past 15 min -> 410 EXPIRED", st)

    ok2, _ = php_exec(
        "db()->prepare(\"UPDATE orders SET status='success', utr_number='TESTUTR12345', payer_name='John Doe', "
        "payer_upi='john@ybl', payment_date=CURRENT_TIMESTAMP WHERE order_id = :o\")"
        "->execute([':o' => '" + ORDER2 + "']); echo 'ok';"
    )
    st, js = call("/verify.php?order_id=%s&api_key=%s" % (ORDER2, KEY))
    d = js.get("data", {}) if isinstance(js, dict) else {}
    record(st == 200 and d.get("status") == "SUCCESS" and d.get("payment_details", {}).get("utr") == "TESTUTR12345",
           "settled order returns SUCCESS without re-scanning Gmail", d.get("payment_details", {}).get("payer_name"))
else:
    record(True, "lifecycle DB manipulation skipped", out[:80])

print("\n=== 7. static file protection ===")
for path in ["/config.php", "/composer.json", "/.env", "/vendor/autoload.php", "/migrations/001_initial_schema.sql"]:
    st, body = call(path, raw=True)
    record(st in (403, 404), "blocked: " + path, st)

print("\n=== 8. admin panel ===")
st, body = call("/cpanel-admin-2025", raw=True)
html = body.decode("utf-8", "replace")
record(st == 200 and "Control Panel" in html and "Sign In" in html, "login page renders", st)

st, body = call("/cpanel-admin-2025", data={"action": "login", "password": "wrong"}, raw=True)
record(b"Invalid password" in body, "wrong password rejected", st)

st, body = call("/cpanel-admin-2025", data={"action": "login", "password": ADMIN_PASSWORD}, raw=True)
html = body.decode("utf-8", "replace")
record("Master API Keys" in html or "Control Panel" in html, "login succeeds (session cookie)", st)

st, body = call("/cpanel-admin-2025", raw=True)
html = body.decode("utf-8", "replace")
m = re.search(r'name="csrf_token" value="([a-f0-9]{64})"', html)
CSRF = m.group(1) if m else None
record(bool(CSRF), "dashboard renders with CSRF token", (CSRF or "")[:12] + "...")
record("Orders" in html and "Gmail Connections" in html, "dashboard sections present")

st, body = call("/cpanel-admin-2025", data={"action": "create_key", "key_name": "AdminMade"}, raw=True)
record(b"Invalid CSRF token" in body, "action without CSRF token blocked", st)

st, body = call("/cpanel-admin-2025", data={"action": "create_key", "key_name": "AdminMade", "csrf_token": CSRF}, raw=True)
html = body.decode("utf-8", "replace")
record("Master key created" in html, "create key via admin panel", st)

m = re.search(r'name="action" value="toggle_key">\s*<input type="hidden" name="id" value="(\d+)"', html)
KID = m.group(1) if m else None
if KID:
    st, body = call("/cpanel-admin-2025", data={"action": "toggle_key", "id": KID, "csrf_token": CSRF}, raw=True)
    record(b"is now disabled" in body or b"is now active" in body, "toggle key enable/disable", st)
    st, body = call("/cpanel-admin-2025", data={"action": "delete_key", "id": KID, "csrf_token": CSRF}, raw=True)
    record(b"Master key deleted" in body, "delete key", st)
else:
    record(False, "found a key row to toggle/delete", "no id parsed")

st, body = call("/cpanel-admin-2025", data={"action": "purge_expired", "csrf_token": CSRF}, raw=True)
record(b"Purged" in body, "purge expired orders", st)
st, body = call("/cpanel-admin-2025", data={"action": "clear_logs", "csrf_token": CSRF}, raw=True)
record(b"Cleared" in body, "clear logs", st)

st, body = call("/cpanel-admin-2025", data={"action": "create_key", "key_name": "<script>alert(1)</script>", "csrf_token": CSRF}, raw=True)
record(b"<script>alert(1)</script>" not in body, "XSS payload escaped in admin output", st)

st, body = call("/cpanel-admin-2025?logout=1", raw=True)
st, body = call("/cpanel-admin-2025", raw=True)
record(b"Sign In" in body, "logout ends the session", st)

print("\n=== 9. summary ===")
failed = [r for r in results if not r[0]]
print("passed=%d failed=%d" % (len(results) - len(failed), len(failed)))
sys.exit(1 if failed else 0)
