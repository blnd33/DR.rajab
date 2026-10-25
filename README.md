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

### Quality checks

```bash
php artisan test          # Pest — feature tests need PostgreSQL (orthotrack_test)
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

---

## Production (Ubuntu 24.04 VPS — 2 vCPU / 4 GB to start)

1. **Packages:** Nginx, PHP-FPM 8.3 (`pgsql redis intl gd mbstring xml zip bcmath curl`), PostgreSQL 16+, Redis, Supervisor, Node 22, Chrome/Chromium (for Browsershot PDFs, Phase 1+), Certbot.
2. **Database:** create role + database with a strong password (`deploy/local/create-local-db.sql` shows the statements).
3. **Code:** clone to `/var/www/orthotrack` (owner: `deploy`; `storage/` and `bootstrap/cache/` writable by PHP-FPM).
4. **.env:** `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`, `ORTHOTRACK_REQUIRE_ADMIN_MFA=true`, `REDIS_CLIENT=phpredis`, DB/Redis/mail credentials, `BACKUP_*`, `VAPID_*` (`php artisan webpush:vapid`).
5. **Nginx + HTTPS:** `deploy/nginx/orthotrack.conf`, then `sudo certbot --nginx -d your.domain` (HTTPS is required for the camera, service worker and push).
6. **Queues:** `deploy/supervisor/horizon.conf` → `/etc/supervisor/conf.d/`, then `sudo supervisorctl reread && sudo supervisorctl update`.
7. **Scheduler:** `deploy/cron/scheduler` → `/etc/cron.d/orthotrack`.
8. **First deploy:** `php artisan migrate --force && php artisan db:seed --force` (in production this seeds only currencies, permissions/roles and settings — no demo data). Create the real Super Admin with `php artisan tinker` or from a temporary staging copy, then set up two-factor on first login.
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
