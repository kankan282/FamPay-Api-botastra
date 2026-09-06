# Mobile se deploy kaise karein (sirf phone, PC nahi)

Teen tareeke hain. **Tareeka 1** sabse aasaan hai (koi app install nahi, sirf browser).

---

## Zaroori cheezein

- `fampay-gateway-v2.0-ROOT.zip` (jisme saari files bina folder ke root par hain)
- ZIP kholne wali app:
  - **Android:** ZArchiver ya "Files by Google" (Chrome se download → tap → Extract)
  - **iPhone:** Files app → ZIP par tap → apne aap folder ban jaata hai
- GitHub account (browser mein login)
- Render account (browser mein login)

> **Zaroori:** GitHub mobile **app** se file upload nahi hoti. Chrome/Safari browser use karein aur
> menu (⋮) se **"Desktop site"** ON kar lein.

---

## Tareeka 1 — GitHub website se (browser, desktop mode)

### Step 1: ZIP extract karein
`fampay-gateway-v2.0-ROOT.zip` extract karein. Andar dikhega:
`Dockerfile`, `config.php`, `qr.php`, `index.html`, … aur 4 folders: `api`, `assets`, `migrations`, `tests`.

### Step 2: Purani ZIP repo se hatayein
GitHub repo kholein → `fampay-gateway-v2.0.zip` par tap → ⋮ → **Delete file** → Commit changes.

### Step 3: Root files upload karein
Repo → **Add file → Upload files** → "choose your files" → extract kiye folder mein jaayein →
saari **files** select karein (folders nahi — Android mein ek file dabaye rakhein, phir baaki par tap) →
Upload → **Commit changes**.

Yeh files jani chahiye:
```
Dockerfile              docker-entrypoint.sh    apache-config.conf
render.yaml             composer.json           composer.lock
config.php              index.html              cpanel-admin-2025.php
qr.php                  verify.php              login.php
create-key.php          test-db.php             test-qr.php
test-imap.php           test-all.php            README.md (aur baaki .md files)
```
(Hidden files `.htaccess`, `.gitignore`, `.env.example` phone par nahi dikhengi — koi baat nahi,
inke bina bhi app poora chalta hai, kyunki wahi rules `apache-config.conf` mein bhi hain.)

### Step 4: `api` folder banayein aur uski files daalein
1. Repo → **Add file → Create new file**
2. File name box mein type karein: `api/README.md` — `/` type karte hi GitHub folder bana dega
3. Neeche kuch bhi likh dein (jaise `api folder`) → **Commit changes**
4. Ab repo mein `api` folder par tap → **Add file → Upload files** →
   extract kiye folder ke andar `api` folder se ye 3 files select karein:
   `helpers.php`, `qr-generator.php`, `test-ui.php` → Commit

### Step 5: `assets` folder (QR ke beech wali image)
Wahi tareeka:
1. **Create new file** → naam: `assets/README.md` → Commit
2. `assets` folder kholein → **Upload files** → daalein:
   `fampay-logo.png`, `fampay-logo-base64.txt`, `fampay-logo-fam-yellow.png` → Commit

### Step 6: `migrations` folder (optional)
1. **Create new file** → `migrations/README.md` → Commit
2. `migrations` folder → **Upload files** → `001_initial_schema.sql` → Commit

> Yeh folder chhoot bhi jaye to problem nahi — database ka schema `config.php` ke andar bhi
> embedded hai, app khud tables bana lega.

`tests` folder ki bilkul zaroorat nahi (sirf developer ke liye hai).

### Step 7: Check karein
Repo ke home page par **`Dockerfile`** seedha dikhna chahiye (kisi folder ke andar nahi).
Agar dikh raha hai — GitHub ka kaam khatam.

---

## Tareeka 2 — Termux app se (Android, sabse fast)

1. Play Store / F-Droid se **Termux** install karein
2. Terminal mein:

```bash
pkg update -y && pkg install git unzip -y

cd /sdcard/Download
unzip fampay-gateway-v2.0-ROOT.zip -d fampay
cd fampay

git init
git config user.email "you@example.com"
git config user.name "kankan282"
git add -A
git commit -m "FamPay Gateway v2.0"
git branch -M main
git remote add origin https://github.com/kankan282/FamPay-Api-botastra.git
git push -u origin main --force
```

Password maange to GitHub ka **Personal Access Token** dein:
GitHub → Settings → Developer settings → Personal access tokens → **Tokens (classic)** →
Generate new token → scope **repo** tick → token copy karke password ki jagah paste karein.

---

## Tareeka 3 — GitHub Codespaces (ZIP already repo mein hai to)

1. Browser (desktop mode) → repo → green **Code** button → **Codespaces** → *Create codespace on main*
2. Terminal khulne par:

```bash
unzip fampay-gateway-v2.0.zip
mv fampay-gateway/* fampay-gateway/.[!.]* . 2>/dev/null
rm -rf fampay-gateway fampay-gateway-v2.0.zip
git add -A && git commit -m "extract project to root" && git push
```

Free plan mein 60 ghante/month milte hain.

---

## Ab Render par deploy (phone browser se)

1. <https://render.com> → **Sign in with GitHub**
2. Dashboard → **New +** → **Blueprint**
3. Apna repo `FamPay-Api-botastra` select karein → **Connect**
4. Render `render.yaml` padh kar dikhayega: 1 web service + 1 free PostgreSQL → **Apply**
5. 5–10 minute build (Logs khule rehne dein). Status **Live** hone ka intezaar karein.

### Agar Blueprint na chale — manual (New + → Web Service)

| Field | Value |
| --- | --- |
| Language / Runtime | **Docker** |
| Branch | `main` |
| Root Directory | khaali |
| Dockerfile Path | `./Dockerfile` |
| Build Command | **khaali** |
| Start Command | **khaali** (zaroori ho to `/usr/local/bin/docker-entrypoint.sh apache2-foreground`) |
| Health Check Path | `/` |
| Instance Type | Free |

Phir **New + → PostgreSQL** (Name `fampay-db`, Database `fampay`, User `fampay_user`, Free) banayein,
uska **Internal Database URL** copy karke web service ke **Environment** mein daalein:

```
DATABASE_URL = postgres://...   (Render se copy)
DB_SSLMODE   = require
ADMIN_PASSWORD = kankan201028
APP_URL      = https://YOUR-APP.onrender.com
APP_SECRET   = koi_lamba_random_text
APP_TIMEZONE = Asia/Kolkata
```

---

## Deploy ke baad test (phone browser mein)

1. `https://YOUR-APP.onrender.com/test-db.php` → sab green (tables khud ban jaayenge)
2. `https://YOUR-APP.onrender.com/test-qr.php` → aapki image wala QR preview dikhega
3. `https://YOUR-APP.onrender.com/cpanel-admin-2025` → password `kankan201028`
4. Key banayein:
   `https://YOUR-APP.onrender.com/create-key.php?admin_password=kankan201028&key_name=Production`
5. QR banayein:
   `https://YOUR-APP.onrender.com/qr.php?upi=kankan1@fam&amount=100&api_key=YOUR_KEY`

---

## Common problems

| Problem | Solution |
| --- | --- |
| `failed to read dockerfile: open Dockerfile: no such file` | Repo mein ZIP padi hai ya files folder ke andar hain. Files root par laayein, ya Render Settings → **Root Directory** mein folder ka naam daalein. |
| Upload button hi nahi dikh raha | Browser menu → **Desktop site** ON karein. GitHub app se upload nahi hota. |
| Files select nahi ho rahi (multi-select) | Android: ek file par **long press** → phir baaki par tap. iPhone: Browse → Select → files choose karein. |
| Build "transferring dockerfile: 2B" | Asli `Dockerfile` upload nahi hua (2.4 KB hona chahiye). Dobara upload karein. |
| Site khulne mein 30 sec lag rahe | Render free plan 15 min baad sula deta hai — pehli request slow hoti hai. Normal hai. |
| `503` ya database error | `DATABASE_URL` galat ya `DB_SSLMODE=require` missing hai. |
