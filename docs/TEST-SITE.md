# Test site for the doctor

A second copy of OrthoTrack on a subdomain of the same server (for example `test.example.com`) so the doctor can try everything before go-live. It is **separate from the real site in every way**: its own folder, database, Redis keys, queue worker, scheduler, login cookie, push keys and backup folder.

* It **starts with zero data** — no branches, users, items, patients or money. Only what every site needs is created: currencies, roles and permissions, settings, expiry levels, label templates, expense categories.
* An orange **"Test site"** strip is shown at the top of every page (admin panel and phone app), in all three languages.
* It runs in production mode, so it behaves exactly like the real site (HTTPS, two-factor for admins, password checks).
* Search engines are told not to list it.
* It can be emptied again at any time with one command; that command refuses to run on any site that is not a test site.

Files: [`deploy/test-site/`](../deploy/test-site/).

> **On Hostinger web hosting** (drrajab.coda-agency.net — no root, PostgreSQL or Redis there) follow **[HOSTINGER.md](HOSTINGER.md)** instead of section 1 below. Sections 3 and 4 apply to both.

## 1. Set up (server — once)

Replace `test.example.com` with the real subdomain everywhere.

1. **DNS:** add an `A` record `test` → the server's IP address (and `AAAA` if the server has IPv6). Wait until `ping test.example.com` answers from the server.
2. **Database:** edit the password in `deploy/test-site/create-db.sql`, then
   ```bash
   sudo -u postgres psql -f deploy/test-site/create-db.sql
   ```
3. **Code** (as the `deploy` user):
   ```bash
   cd /var/www
   git clone https://github.com/blnd33/DR.rajab.git orthotrack-test
   cd orthotrack-test
   composer install --no-dev --prefer-dist --optimize-autoloader
   npm ci && npm run build
   ```
   `storage/` and `bootstrap/cache/` must be writable by PHP-FPM, as for the real site.
4. **.env:** `cp .env.example .env`, then set the lines from [`deploy/test-site/env.test-site`](../deploy/test-site/env.test-site) (keep mail, backup bucket and PDF settings as on the real site). Then
   ```bash
   php artisan key:generate
   php artisan webpush:vapid
   ```
5. **Database tables and base data** (no demo data — this is a test site):
   ```bash
   php artisan migrate --force
   php artisan db:seed --force
   php artisan storage:link
   php artisan optimize && php artisan filament:optimize
   ```
6. **First login** for the doctor (asks for the password twice — at least 10 characters, upper and lower case and a number):
   ```bash
   php artisan orthotrack:create-admin rajab --name="Dr. Rajab" --locale=en
   ```
   `--locale` can be `en`, `ar` or `ckb`. Make one more for the Coda Agency team if needed.
7. **Web server + HTTPS** (HTTPS is required for the camera, scanning, photos and the phone app):
   ```bash
   sudo cp deploy/test-site/nginx-orthotrack-test.conf /etc/nginx/sites-available/orthotrack-test
   # edit test.example.com in the file, then
   sudo ln -s /etc/nginx/sites-available/orthotrack-test /etc/nginx/sites-enabled/
   sudo certbot --nginx -d test.example.com
   sudo nginx -t && sudo systemctl reload nginx
   ```
8. **Queue worker:**
   ```bash
   sudo cp deploy/test-site/horizon-test.conf /etc/supervisor/conf.d/orthotrack-test-horizon.conf
   sudo supervisorctl reread && sudo supervisorctl update
   ```
9. **Scheduler:**
   ```bash
   sudo cp deploy/test-site/scheduler-test /etc/cron.d/orthotrack-test
   ```
10. Open `https://test.example.com/admin`: the orange *Test site* strip shows and the doctor can sign in (two-factor is set up on the first login).

## 2. Update the test site with new code

Same script as the real site, run in the test folder:

```bash
cd /var/www/orthotrack-test && ./deploy/deploy.sh
```

It keeps the doctor's test data (it only adds new tables, permissions and settings). Usually update the test site first, let the doctor check, then the real site.

## 3. First steps for the doctor

Everything starts empty, in this order:

1. **System → Settings** — company name, address, logo (used on printed documents).
2. **Administration → Branches** — Duhok, Erbil, Baghdad … (code, name, address).
3. **Administration → Users** — staff, cashiers, accountant, with their role and branches.
4. **Finance → Exchange rates** — USD → IQD and EUR → USD (money screens wait for both).
5. **Stock → Excel imports → Supplier catalog** — his Lima / Enovis workbook (items and kit types).
6. **Finance → Cash boxes & banks** — at least one per branch and currency.
7. Then try the daily work: receive stock (scan the boxes), transfers **with the two photos**, sales **per item or one price split by %**, surgeries, kits, expenses, the daily closing, reports and **Graphs** (Pie / Bars / Line).

Please use **made-up patient names** on the test site.

## 4. Empty it again

When the doctor wants to start over (for example after trying an import):

```bash
cd /var/www/orthotrack-test
php artisan orthotrack:reset-test-site
php artisan orthotrack:create-admin rajab --name="Dr. Rajab"
```

The reset asks you to type the site name (`test.example.com`) and then erases all data and uploaded files (photos, invoices, imports) and puts the base data back. It refuses on any site without `ORTHOTRACK_TEST_SITE=true` — the real site cannot be emptied with it.

## 5. Going live

Nothing moves from the test site to the real site: the real site also starts empty (`db:seed` in production adds base data only) and gets its own `orthotrack:create-admin`. When testing is finished the test site can stay for training, or be switched off:

```bash
sudo rm /etc/nginx/sites-enabled/orthotrack-test && sudo systemctl reload nginx
sudo rm /etc/supervisor/conf.d/orthotrack-test-horizon.conf /etc/cron.d/orthotrack-test && sudo supervisorctl update
```
