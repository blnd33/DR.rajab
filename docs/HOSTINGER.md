# Test site on Hostinger — drrajab.coda-agency.net

The doctor's test site runs on the Hostinger web hosting of **coda-agency.net** (account `u527134066`), subdomain **drrajab.coda-agency.net**. It starts with **zero data** and shows an orange *Test site* strip on every page. See [TEST-SITE.md](TEST-SITE.md) for what a test site is; this page is the Hostinger way to set it up.

Shared hosting is not the server the real site is planned for (a VPS with PostgreSQL, Redis and Supervisor — README → *Production*). For the test site it is set up like this:

| Real site (VPS) | Test site on Hostinger |
|---|---|
| PostgreSQL | **SQLite** — one database file, outside the website folder (the automated tests run on SQLite too) |
| Redis | sessions, cache and the job queue in the database |
| Horizon + Supervisor | the one-minute **cron** also runs the job queue (Excel imports, notifications) |
| PDF download (Chrome) | off — the print page's *Save as PDF* works |
| Off-site backups | a daily copy kept on the Hostinger account |

**Folders** (everything under `/home/u527134066/domains/coda-agency.net/`):

```
drrajab-app/                 the application (not reachable from the web)
drrajab-data/orthotrack.sqlite   the database (not reachable from the web)
public_html/drrajab  →  drrajab-app/public    (link: only the public folder is on the web)
```

## 1. In hPanel (once)

1. **PHP version** — *Websites → coda-agency.net → Advanced → PHP Configuration*: **PHP 8.3** or newer, with the extensions `pdo_sqlite`, `sqlite3`, `intl`, `gd`, `bcmath`, `zip`, `fileinfo`, `mbstring`, `curl`, `openssl` switched on.
   ⚠ The PHP version is shared by coda-agency.net and its other subdomains (nihad, os, harekar). If it is already 8.3 or newer, change nothing; if not, check those sites work with 8.3 before switching.
2. **SSH** — *Advanced → SSH Access*: enable it; note the IP, port and username (`u527134066`).
3. **SSL** — *Security → SSL*: drrajab.coda-agency.net must show an active certificate (Hostinger adds one to new subdomains within minutes). HTTPS is required for the camera, scanning and the phone app.

## 2. Make the upload package (on the PC)

```bash
php deploy/hostinger/make-release.php
```

→ `dist/orthotrack-<commit>.zip` (code + packages + built screens, about 40 MB). The last commit is packaged, so commit first.

**Upload** it to `/home/u527134066/domains/coda-agency.net/` with *Files → File Manager* (or `scp -P <port> dist/orthotrack-<commit>.zip u527134066@<ip>:domains/coda-agency.net/`).

## 3. Install (SSH, once)

Connect (`ssh -p <port> u527134066@<ip>`), then — replace `<commit>` with the zip's name:

```bash
cd ~/domains/coda-agency.net
php -v                      # must say 8.3 or newer; otherwise use /opt/alt/php83/usr/bin/php instead of php below
mkdir -p drrajab-app drrajab-data
unzip -q orthotrack-<commit>.zip -d drrajab-app
cd drrajab-app
cp deploy/hostinger/env.hostinger .env
php artisan key:generate --force
php artisan webpush:vapid --force
touch ../drrajab-data/orthotrack.sqlite
php artisan migrate --force
php artisan db:seed --force          # base data only — no demo data on a test site
ln -s ../storage/app/public public/storage   # logo, item and kit photos (php artisan storage:link fails here: exec/symlink are off)
php artisan optimize && php artisan filament:optimize
php artisan orthotrack:create-admin rajab --name="Dr. Rajab"   # asks for his password twice
```

Point the subdomain at the app's public folder (hPanel created `public_html/drrajab` with a placeholder page — look inside first, it should hold nothing of value):

```bash
cd ~/domains/coda-agency.net/public_html
ls -la drrajab
rm -rf drrajab
ln -s ../drrajab-app/public drrajab
```

## 4. Cron (hPanel → Advanced → Cron Jobs)

One job, **every minute** (`* * * * *`), command:

```
/usr/bin/php /home/u527134066/domains/coda-agency.net/drrajab-app/artisan schedule:run
```

Use the PHP path that answered 8.3+ in step 3 (`which php` shows it). This runs the daily alerts, exchange-rate fetch, nightly stock value, backups — and the job queue (Excel imports, notifications).

### What this server is like (found on the first install, 2026-11-08)

* Web PHP 8.4, command-line PHP 8.3 (`/usr/bin/php`) — both fine; every needed extension is on.
* Command-line PHP has `exec`, `shell_exec`, `system`, `popen`, `symlink` and `link` switched off. `proc_open` works, so the scheduler and the queue run. Use the shell (`ln -s`) for links.
* Password prompts (`orthotrack:create-admin`) show what you type — the tool that hides it is blocked. Clear the screen afterwards (`clear`).
* Every Super Admin must set up two-factor (authenticator app) on first sign-in; until then the panel stays on the Profile page.

## 5. Check

Open **https://drrajab.coda-agency.net/admin**: the orange *Test site* strip shows, the doctor signs in and sets up two-factor. Then he follows [TEST-SITE.md → First steps for the doctor](TEST-SITE.md#3-first-steps-for-the-doctor). The phone app is **https://drrajab.coda-agency.net/app**.

If a page shows an error: `tail -n 50 ~/domains/coda-agency.net/drrajab-app/storage/logs/laravel-*.log`.

## 6. Update with new code

On the PC: commit, `php deploy/hostinger/make-release.php`, upload the new zip next to the old one. Then over SSH:

```bash
cd ~/domains/coda-agency.net/drrajab-app
bash deploy/hostinger/update.sh ~/domains/coda-agency.net/orthotrack-<commit>.zip
```

It keeps `.env`, the database and every uploaded photo and file.

## 7. Empty it again

```bash
cd ~/domains/coda-agency.net/drrajab-app
php artisan orthotrack:reset-test-site      # asks you to type drrajab.coda-agency.net
php artisan orthotrack:create-admin rajab --name="Dr. Rajab"
```
