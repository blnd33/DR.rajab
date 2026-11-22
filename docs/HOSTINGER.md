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

First make `php` mean **PHP 8.4** on the command line (the default there is 8.3, and the SQLite settings need 8.4 — see *What this server is like*). Once per account; then log out and in again:

```bash
echo "alias php=/opt/alt/php84/usr/bin/php" >> ~/.bashrc
```

```bash
php -v | head -1            # must say PHP 8.4
cd ~/domains/coda-agency.net
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

*Create a New Cron Job* → **Custom**, command:

```
/opt/alt/php84/usr/bin/php /home/u527134066/domains/coda-agency.net/drrajab-app/artisan schedule:run
```

There is no *once per minute* in *Common Settings*: set **Minute, Hour, Day, Month and Weekday** each to *Every … (\*)*, then **Save**. The list must show it with the time `* * * * *`.

* The full PHP 8.4 path is needed here — the cron does not read the `php` alias.
* The list holds the cron jobs of every site on the account (e.g. one for hargav.com). Leave the others alone.
* *View Output* on the row shows the last run: `Running … queue:work …` or `No scheduled commands are ready to run` are both fine.

This runs the daily alerts, exchange-rate fetch, nightly stock value, backups — and the job queue (Excel imports, notifications). Without it, an uploaded import waits for ever.

### What this server is like (found on the first install, 2026-11-08)

* Web PHP 8.4. Command-line `php` is **8.3** (`/usr/bin/php`); PHP 8.4 is at **`/opt/alt/php84/usr/bin/php`** with every needed extension. Use 8.4 for every command and for the cron: Laravel applies `DB_TRANSACTION_MODE=IMMEDIATE` only on PHP 8.4, and without it a queue job that meets a page writing at the same moment fails at once with *"database is locked"* (found 2026-11-10: the Lima import stopped on its first row while its own page was refreshing).
* `DB_JOURNAL_MODE=WAL` in `.env` (added 2026-11-10) lets pages read while the queue writes.
* Command-line PHP has `exec`, `shell_exec`, `system`, `popen`, `symlink` and `link` switched off. `proc_open` works, so the scheduler and the queue run. Use the shell (`ln -s`) for links.
* Password prompts (`orthotrack:create-admin`) show what you type — the tool that hides it is blocked. Clear the screen afterwards (`clear`).
* Every Super Admin must set up two-factor (authenticator app) on first sign-in; until then the panel stays on the Profile page.

## 5. Check

Open **https://drrajab.coda-agency.net/admin**: the orange *Test site* strip shows, the doctor signs in and sets up two-factor. Then he follows [TEST-SITE.md → First steps for the doctor](TEST-SITE.md#3-first-steps-for-the-doctor). The phone app is **https://drrajab.coda-agency.net/app**.

If a page shows an error: `tail -n 50 ~/domains/coda-agency.net/drrajab-app/storage/logs/laravel-*.log`.

### Excel imports

An import runs in two queued steps, each picked up by the cron within a minute:

1. **Upload** → *Checking every row…* → **Checked** (preview: new / changed / errors per row).
2. Press **Import N rows** → *Importing…* → **Imported**.

Nothing is saved until step 2. The doctor's Lima / Enovis workbook is imported with **Stock → Excel Imports → New → Supplier catalog (Lima / Enovis workbook)** — not *Products → Excel → Import from Excel* (that one expects OrthoTrack's own product template). The 2026-11-10 import of *SUMMARY ENOVIS Lima Items … 02.11.2026.xlsx*: 4,240 new (1,829 implants, 2,287 instruments, 125 sets), 1 error row (a code listed twice with different names).

If an import stays on *Checking…* or *Importing…* for more than a few minutes, the cron is not running (§4). Run the waiting jobs by hand — imports use their own `imports` queue, so name every queue:

```bash
php artisan queue:work --queue=high,default,imports,pdf --stop-when-empty --timeout=0
```

Where an import stands (last three):

```bash
php artisan tinker --execute="foreach (App\Models\DataImport::latest('id')->take(3)->get() as \$i) echo \$i->id.' '.\$i->status->value.' processed='.\$i->processed_rows.' new='.\$i->new_count.' errors='.\$i->error_count.' '.\$i->failure_message.PHP_EOL;"
```

An import that **failed while writing** (e.g. *database is locked*) can be started again — rows already written are skipped. Replace `5` with its number, then run the worker above:

```bash
php artisan tinker --execute="App\Models\DataImport::find(5)->forceFill(['status' => 'confirmed', 'failure_message' => null, 'finished_at' => null])->save(); App\Jobs\ApplyImport::dispatch(5); echo 'queued'.PHP_EOL;"
```

## 6. Update with new code

On the PC: commit, `php deploy/hostinger/make-release.php`, upload the new zip next to the old one. Then over SSH:

```bash
cd ~/domains/coda-agency.net/drrajab-app
bash deploy/hostinger/update.sh ~/domains/coda-agency.net/orthotrack-<commit>.zip
```

It keeps `.env`, the database and every uploaded photo and file, and uses PHP 8.4 by itself (it prints which PHP it runs with, and warns if it is older than 8.4).

## 7. Empty it again

```bash
cd ~/domains/coda-agency.net/drrajab-app
php artisan orthotrack:reset-test-site      # asks you to type drrajab.coda-agency.net
php artisan orthotrack:create-admin rajab --name="Dr. Rajab"
```

### Only the Items list

To empty **Stock → Items list** but keep stations, logins, customers, suppliers, patients, surgeries, kits, cash boxes and money:

```bash
cd ~/domains/coda-agency.net/drrajab-app
php artisan orthotrack:clear-items --dry-run   # shows what would go, removes nothing
php artisan orthotrack:clear-items             # shows it again, asks you to type drrajab.coda-agency.net
```

What goes with the items: all stock (serials, lots, stock movements, stock-value history) and every document made of item lines — transfers (a transfer carrying kits or instruments stays, without its items), stock requests, write-offs, returns and their credit notes, stock counts, purchases and sales invoices with their supplier/customer balance lines, the item lines of kit checkouts and surgeries, and the files attached to those. Money in the cash boxes and payments stay as they are. It refuses on any site without `ORTHOTRACK_TEST_SITE=true`; for the other company, put `ORTHOTRACK_COMPANY=al_fadhaa` in front of the command.
