# How to put this project on GitHub so Render can build it

Render clones your repository and looks for **`Dockerfile` in the root**.
If the repo contains only `fampay-gateway-v2.0.zip`, the build fails with:

```
error: failed to solve: failed to read dockerfile:
open Dockerfile: no such file or directory
```

## Correct repository layout

```
YOUR_REPO/
├── Dockerfile              <-- must be here (root)
├── docker-entrypoint.sh
├── render.yaml
├── apache-config.conf
├── composer.json
├── composer.lock
├── config.php
├── index.html
├── cpanel-admin-2025.php
├── qr.php  verify.php  login.php  create-key.php
├── test-db.php  test-qr.php  test-imap.php  test-all.php
├── api/        assets/      migrations/     tests/
└── README.md  DEPLOYMENT.md  TESTING_REPORT.md
```

## Fastest fix (Git CLI)

```bash
unzip fampay-gateway-v2.0.zip
cd fampay-gateway

git init
git add -A
git commit -m "FamPay Gateway v2.0"
git branch -M main
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPO.git
git push -u origin main --force
```

Delete the old `fampay-gateway-v2.0.zip` file from the repository afterwards
(GitHub → click the file → trash icon → commit).

## Website upload (no Git installed)

1. Extract **`fampay-gateway-v2.0-ROOT.zip`** – its files have no wrapper folder.
2. GitHub repo → **Add file → Upload files**.
3. Drag in every file **and** the folders `api`, `assets`, `migrations`, `tests`.
4. Commit, then delete the old ZIP from the repo.

Hidden files (`.htaccess`, `.gitignore`, `.env.example`) are usually skipped by
the browser upload. The app still works: `apache-config.conf` carries the same
rewrite rules, file protections and security headers.

## Already uploaded as a sub-folder?

Leave it and tell Render where to look:

* Web service → **Settings → Build & Deploy → Root Directory** → `fampay-gateway`
* or in `render.yaml`, add `rootDir: fampay-gateway` under the service.

## After a correct push

Render → your service → **Manual Deploy → Deploy latest commit**.
The log should show `transferring dockerfile: 2.4kB` (not `2B`) and then the
PHP extension build.
