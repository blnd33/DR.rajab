# OrthoTrack

Multi-branch orthopedic inventory & surgery system (working name — client brand TBD).
Built by **Coda Agency** (Duhok, Iraq) — *Build What's Next*.

* **Admin panel** (`/admin`, Filament 5) — Super Admin, Accountant: back-office CRUD, settings, reports.
* **Operations app** (`/app`, Livewire 4, installable PWA) — doctors, cashiers, branch staff: phone-first, scan-driven.
* One login (`/login`) for both; users land on the panel for their role.
* Languages: English, Arabic, Sorani Kurdish (full RTL). Arabic-Indic digits for display; data always stored with Western digits.

Docs: [Architecture](docs/ARCHITECTURE.md) · [Database schema](docs/DATABASE.md) · [Design system](docs/design/DESIGN-SYSTEM.md) · [Backup restore](docs/RESTORE.md) · [Changelog](CHANGELOG.md)

---

## Stack

PHP 8.3+ · Laravel 13 · PostgreSQL 16+ · Redis · Horizon · Filament 5 · Livewire 4 · Alpine.js · Tailwind CSS 4 · Vite · Workbox · ZXing · Pest.

---

## Local development

### Quick preview (no database server)

To look at the app before PostgreSQL is set up, the local `.env` currently points at a SQLite file:

```bash
DB_CONNECTION=sqlite
DB_DATABASE=database/preview.sqlite
```

```bash
php artisan migrate:fresh --seed && php artisan serve
```

The preview keeps the append-only triggers but skips Postgres-only CHECK constraints and search indexes. **It is for looking around only — production, staging and the test suite use PostgreSQL.** To switch: replace those two lines with the `pgsql` block (kept, commented, in `.env`), run `deploy/local/create-local-db.sql`, then `php artisan migrate:fresh --seed`.

### Option A — WSL2 + Ubuntu 24.04 (recommended, same as production)

```bash
# Windows (admin PowerShell), then reboot:
wsl --install -d Ubuntu-24.04

# Inside Ubuntu:
sudo apt update && sudo apt install -y php8.3-{cli,fpm,pgsql,redis,intl,gd,mbstring,xml,zip,bcmath,curl} \
    postgresql redis-server unzip git
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
sudo -u postgres psql -f deploy/local/create-local-db.sql
```

### Option B — Windows with the native PostgreSQL install

PostgreSQL 18 is installed on the dev machine. Create the role and databases once (psql asks for the `postgres` password):

```bash
"/c/Program Files/PostgreSQL/18/bin/psql.exe" -U postgres -h 127.0.0.1 -f deploy/local/create-local-db.sql
```

Without Redis on Windows, set `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database` in `.env`. Horizon does not run on Windows (no `pcntl`); use `php artisan queue:work`.

### Install & run

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed
npm install && npm run build      # or: npm run dev
php artisan serve
```

Open http://localhost:8000/login.

Demo users (local/staging only — the seeder refuses to run in production). Password: `DEMO_USER_PASSWORD` in `.env`.

| Username | Role | Language | Branches | Lands on |
|---|---|---|---|---|
| `superadmin` | Super Admin | English | all | `/admin` |
| `accountant` | Accountant | Arabic | all | `/admin` |
| `doctor` | Doctor | Sorani | DHK, EBL | `/app` |
| `cashier` | Cashier | Arabic | EBL | `/app` |
| `staff` | Branch Staff | Sorani | DHK | `/app` |
| `staff.bgw` | Branch Staff | English | BGW | `/app` |

> Camera scanning needs HTTPS (or `localhost`). To test on a phone over the LAN, use a tunnel with HTTPS (e.g. `cloudflared tunnel --url http://localhost:8000`).

### Try the inventory (Phase 1)

With the demo data (`php artisan migrate:fresh --seed`):

* **Admin → Stock → Receive stock** — click the scan field and scan (or type) a GS1 code, e.g. `(01)08712345000011(17)290630(10)LTEST1(21)SN-NEW-001`: product, lot, expiry and serial fill in. Plain SKUs, extra barcodes (`KM-3530`) and serials work too.
* **Serial units / Lots** — expiry chips, filters by expiry level, *Correct details* (with reason) and the unit's history. **Recall search** — try lot `L26001A`.
* **Excel imports** — download a template, fill it, upload: every row is checked and shown with old → new before anything changes.
* **System → Expiry levels** — change colors / day ranges; gaps and overlaps are refused.
* **Phone app** (`staff`, Sorani) — Stock, Scan, Receive and Recall.

### Try movement (Phase 2)

* **Dashboard → Waiting for action** — the demo has one of everything: an urgent stock request, a write-off, a count to review and open issues.
* **Operations → Issues** — open the transfer that arrived with a missing unit and *Settle* it; the issue closes by itself.
* **Operations → Stock requests** — approve the BGW request: one draft transfer per sending branch appears.
* **Operations → Stock counts** — the EBL count waits for decisions (*Decide* per line, then *Close count*).
* **Phone app** (`staff`) → **Tasks** — send the draft transfer, scan stock into the running count (works offline; scans sync when the connection is back), report a write-off, record a customer return.
* **Branches → Move all stock to another branch** — before closing a branch.
### Try clinical & kits (Phase 3)

* **Clinical → Kits** — `KIT-DHK-0002` is out at Rizgary and overdue: open its checkout, *Return the kit*, leave the cup unticked → it becomes unaccounted and an issue for the Super Admin (*Settle*).
* **Clinical → Surgeries** — two completed surgeries (one from the kit) and a draft that still needs its hospital.
* **Clinical → Patients** — only the patients you may see; every opening is logged.
* **Phone app** (`doctor`) → **Tasks → New surgery** — scan implants, add a new patient, *Save draft* or *Complete*; the draft is kept on the phone if the connection drops (see *My surgeries → Saved on this phone*).
* **Phone app** (`staff`) → **Tasks → Kits** — prepare / refill by scanning, check out, return by scanning.
### Try reports and documents (Phase 5)

* **Reports** menu — nine reports; change the period, branch, *Show amounts in* (USD / IQD / EUR) and *Group by*; *Export to Excel*, *Print / PDF*.
* **Purchases → a purchase → Deliveries → Print / PDF** (goods received note); the same button on sales, transfers, surgeries, kit checkouts, suppliers (statement) and daily closings.
* **Dashboard** — sales and surgeries per month, stock value trend (run `php artisan reports:snapshot` for today's point), most used implants.
* **System → System health** — backups, daily jobs, disk, exchange rates; problems also show on the dashboard.

### Try money (Phase 4)

* **Finance → Exchange rates** — USD → IQD 1,460 and EUR → USD 1.08 are set; set a new rate or fetch the ECB one (a suggestion to confirm).
* **Finance → Approvals** — a 7,040 USD BoneTech payment and a 1,400 USD Erbil expense wait (log in as `accountant` or `superadmin`; nobody approves their own request).
* **Finance → Purchases** — the NovaJoint invoice (EUR, discount spread, shipping + customs, partly received and paid): *Receive items* for the back-ordered femoral component, *Pay* the rest; the BoneTech one is overdue.
* **Catalog → Suppliers → NovaJoint** — what we owe per currency and the statement.
* **Finance → Daily closings** — Erbil closed five dollars short (*Mark reviewed*).
* **Phone app** (`cashier`, Erbil) → **Tasks → Cash** — receive the 300 USD sent from Duhok, *New sale* by scanning, *Record an expense*, *Close the day*.
* Commissions and credit sales are switched off; turn them on in **Settings** to see their screens.

Excel imports run on the queue. The local preview `.env` uses `QUEUE_CONNECTION=sync` (runs at once); on a server keep `redis`/`database` with Horizon or `php artisan queue:work --queue=high,default,imports,pdf`.

Labels print from the browser: the print sheet's page size equals the label size, so pick the thermal printer and "actual size" (no scaling, no margins) in the print dialog.

Daily stock alerts (expiry levels, low stock) run at 06:00 via the scheduler; run them by hand with `php artisan stock:alerts`. Money jobs (ECB rate suggestion, recurring expenses, overdue supplier invoices) run at 07:00: `php artisan money:daily`. The nightly stock value snapshot runs at 23:50: `php artisan reports:snapshot`. Every daily job records a heartbeat for the System health page.

On Windows, stop the preview server before `composer require` / `composer dump-autoload` — the running server keeps the autoload files open and Composer silently fails to rewrite them.

### Try the morning summary and Quick answers (Phase 6)

* **Insight → Morning summary** — yesterday's numbers and what needs attention; pick any earlier morning, *Send it to me now*. Each person sets the time, branch, sections and Fridays in their profile (phone: *Me*); defaults in **Settings → Morning summary**. Sent by `php artisan summary:send` (scheduled every 15 minutes).
* **Insight → Quick answers** — tap a question (*Which kits are overdue?*, *What expires in the next 30 days?*, *Sales by hospital* for a period…) or type / scan a code: serial `NJ-FEM4-90001`, kit `KIT-DHK-0001`, transfer `TRF-BGW-2026-00001`, a SKU or a lot. A few words also work, in English, Arabic or Kurdish (*overdue kits*, *مبيعات*, *خەرجی*). No AI: it answers the listed questions only.
* **Phone app** → **Home → Ask** — the same, with the camera.

### Quality checks

```bash
php artisan test          # Pest — feature tests need PostgreSQL (orthotrack_test)
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

---

## Production (Ubuntu 24.04 VPS — 2 vCPU / 4 GB to start)

> A separate **test site** for the doctor (subdomain, empty database, *Test site* banner) is set up the same way — see [docs/TEST-SITE.md](docs/TEST-SITE.md).

1. **Packages:** Nginx, PHP-FPM 8.3 (`pgsql redis intl gd mbstring xml zip bcmath curl`), PostgreSQL 16+, Redis, Supervisor, Node 22, Chrome/Chromium (for PDF downloads — `npm ci` installs puppeteer without its own Chromium; set `PDF_CHROME_PATH` if Chrome is not in a standard place), Certbot.
2. **Database:** create role + database with a strong password (`deploy/local/create-local-db.sql` shows the statements).
3. **Code:** clone to `/var/www/orthotrack` (owner: `deploy`; `storage/` and `bootstrap/cache/` writable by PHP-FPM).
4. **.env:** `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`, `ORTHOTRACK_REQUIRE_ADMIN_MFA=true`, `REDIS_CLIENT=phpredis`, DB/Redis/mail credentials, `BACKUP_*`, `VAPID_*` (`php artisan webpush:vapid`).
5. **Nginx + HTTPS:** `deploy/nginx/orthotrack.conf`, then `sudo certbot --nginx -d your.domain` (HTTPS is required for the camera, service worker and push).
6. **Queues:** `deploy/supervisor/horizon.conf` → `/etc/supervisor/conf.d/`, then `sudo supervisorctl reread && sudo supervisorctl update`.
7. **Scheduler:** `deploy/cron/scheduler` → `/etc/cron.d/orthotrack`.
8. **First deploy:** `php artisan migrate --force && php artisan db:seed --force` (in production this seeds only base data — no demo data). Create the real Super Admin with `php artisan orthotrack:create-admin <username> --name="…"`, then set up two-factor on first login.
9. **Every deploy:** `./deploy/deploy.sh` (maintenance mode → git pull → composer --no-dev → migrate → idempotent seeders → npm build → optimize → restart Horizon).

### Backups

`spatie/laravel-backup` runs every night at 02:00 (Asia/Baghdad): PostgreSQL dump + uploaded files, an AES-256 encrypted zip (`BACKUP_ARCHIVE_PASSWORD`), stored off-server on the `backups` S3-compatible disk (Backblaze B2: `BACKUP_BUCKET`, `BACKUP_ENDPOINT`, keys). Retention: every backup for 7 days, then one per day up to 30 days, then one per month for 12 months. Failures and unhealthy backups (older than 1 day) email `ORTHOTRACK_OPS_ADMIN_EMAIL`.

Restore procedure: [docs/RESTORE.md](docs/RESTORE.md). **Keep the archive password outside the server** (password manager): without it a backup cannot be opened.

---

## Project rules (short version — details in docs/ARCHITECTURE.md)

* Business logic lives in `app/Services/*`. Filament resources and Livewire components only call services.
* Stock changes only through `StockMovementService`; money only through `MoneyLedgerService` (from Phase 1/4). Ledgers and the activity log are append-only (database triggers).
* Every permission check includes the branch scope (`BranchScopeResolver`).
* Never hardcode left/right: use logical utilities (`ms-`, `pe-`, `start-`, `end-`).
* Codes (serial, lot, SKU, barcode, phone, document numbers) are always Western digits, LTR, monospace (`<x-code>`, `CodeColumn`).
* Every UI string goes through `__()` and must exist in `lang/en.json`, `lang/ar.json`, `lang/ckb.json` (a test enforces it).

*Powered by Coda Agency*
