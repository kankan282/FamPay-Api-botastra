# Render.com settings — copy/paste values

Render has **no native PHP runtime**, so this project ships as a Docker image.
Pick **Docker** as the language/runtime; the Dockerfile installs PHP 8.1, Apache,
`imap`, `gd`, `pdo_pgsql` and runs Composer for you.

## A. Blueprint (easiest — reads `render.yaml`)

New + → **Blueprint** → select your repo → **Apply**.
Web service + free PostgreSQL are created and linked automatically. Nothing else to type.

## B. Manual web service — exact field values

| Field | Value |
| --- | --- |
| **Language / Runtime** | `Docker` |
| **Repository** | your GitHub repo |
| **Branch** | `main` |
| **Region** | `Singapore` (closest to India) |
| **Root Directory** | *leave empty* — or `fampay-gateway` if the files sit in that sub-folder |
| **Dockerfile Path** | `./Dockerfile` |
| **Docker Build Context Directory** | `.` |
| **Build Command** | *leave empty* (Docker build does everything) |
| **Start Command** | *leave empty* — the image already runs `docker-entrypoint.sh apache2-foreground`.<br>If the field is mandatory, enter: `/usr/local/bin/docker-entrypoint.sh apache2-foreground` |
| **Health Check Path** | `/` |
| **Instance Type** | `Free` |
| **Auto-Deploy** | `Yes` |

### Environment variables (Environment tab)

| Key | Value |
| --- | --- |
| `DATABASE_URL` | *Internal Database URL* of your Render PostgreSQL |
| `DB_SSLMODE` | `require` |
| `ADMIN_PASSWORD` | `kankan201028` (change it!) |
| `APP_URL` | `https://YOUR-APP.onrender.com` |
| `APP_SECRET` | any long random string |
| `APP_TIMEZONE` | `Asia/Kolkata` |
| `ORDER_EXPIRY_MINUTES` | `15` |

> Instead of `DATABASE_URL` you may set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` individually.

### Database (New + → PostgreSQL)

| Field | Value |
| --- | --- |
| Name | `fampay-db` |
| Database | `fampay` |
| User | `fampay_user` |
| Region | same as the web service |
| Plan | `Free` |

The schema creates itself on the first request — just open `/test-db.php` once.

---

## C. Local commands

```bash
# dependencies
composer install

# configuration
cp .env.example .env          # edit DB_* + ADMIN_PASSWORD

# database (optional — the app also self-creates the schema)
psql "postgres://user:pass@localhost:5432/fampay" -f migrations/001_initial_schema.sql

# run the app
php -S 0.0.0.0:8080 -t . tests/router.php
# -> http://localhost:8080            docs
# -> http://localhost:8080/cpanel-admin-2025   admin panel
# -> http://localhost:8080/test-all.php        self test
```

### Run it with Docker locally (same image Render builds)

```bash
docker build -t fampay-gateway .

docker run --rm -p 8080:8080 \
  -e PORT=8080 \
  -e DATABASE_URL="postgres://fampay_user:pass@host.docker.internal:5432/fampay" \
  -e ADMIN_PASSWORD=kankan201028 \
  -e APP_URL=http://localhost:8080 \
  fampay-gateway
```

### Git push

```bash
git init
git add -A
git commit -m "FamPay Gateway v2.0"
git branch -M main
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPO.git
git push -u origin main --force
```

---

## D. First run checklist

```bash
# 1. diagnostics
open https://YOUR-APP.onrender.com/test-db.php
open https://YOUR-APP.onrender.com/test-qr.php     # shows the QR with your centre image
open https://YOUR-APP.onrender.com/test-imap.php

# 2. master key
curl "https://YOUR-APP.onrender.com/create-key.php?admin_password=kankan201028&key_name=Production"

# 3. Gmail connection (16-char App Password)
curl "https://YOUR-APP.onrender.com/login.php?gmail=you@gmail.com&app_password=xxxxxxxxxxxxxxxx&api_key=YOUR_KEY"

# 4. QR
curl "https://YOUR-APP.onrender.com/qr.php?upi=kankan1@fam&amount=100&api_key=YOUR_KEY"

# 5. verify
curl "https://YOUR-APP.onrender.com/verify.php?order_id=FAM...&api_key=YOUR_KEY&gmail_key=YOUR_GMAIL_KEY"
```

---

## E. Changing the image in the centre of the QR

| What | Where |
| --- | --- |
| Current centre image | `assets/fampay-logo.png` (your uploaded picture, 256 × 256, rounded corners) |
| Backup copy used if the PNG is missing | `assets/fampay-logo-base64.txt` |
| Alternative FamPay yellow mark | `assets/fampay-logo-fam-yellow.png` — copy it over `fampay-logo.png` to switch back |
| Size of the image | `QR_LOGO_SIZE` (default `92` px of 400 = 23 %) |
| Size of the white badge behind it | `QR_LOGO_BG_SIZE` (default `112` px = 28 %) |
| Badge shape | `QR_LOGO_SHAPE` = `rounded` (default) or `circle` |
| Load a logo from a URL instead | `FAMPAY_LOGO_URL=https://.../image.png` |
| Turn the logo off for one request | add `&logo=0` to `/qr.php` |

To use a different picture: replace `assets/fampay-logo.png` with a square PNG
(256 × 256 or larger, transparent corners optional) and commit. Nothing else to change.
