# Restoring a backup

Backups are encrypted zip files on the `backups` disk (Backblaze B2 / S3-compatible), one folder per app name (`orthotrack/`). Each zip contains:

* `db-dumps/postgresql-<db>.sql` — full PostgreSQL dump
* `var/www/orthotrack/storage/app/…` — uploaded files (attachments, logo)

You need: bucket credentials, the **archive password** (`BACKUP_ARCHIVE_PASSWORD`, kept in the Coda password manager), and a server with the same app version (see `CHANGELOG.md` / git tag).

## 1. Pick and download the backup

```bash
php artisan backup:list                     # lists backups and their age per disk
# Download with the B2 CLI / rclone / aws-cli, e.g.:
aws s3 cp s3://$BACKUP_BUCKET/orthotrack/2026-10-25-02-00-00.zip . --endpoint-url $BACKUP_ENDPOINT
```

## 2. Unpack (AES-256)

```bash
7z x 2026-10-25-02-00-00.zip -o./restore     # prompts for the archive password
```

## 3. Restore the database

Put the app in maintenance mode first if the server is live (`php artisan down`).

```bash
sudo -u postgres psql -c "DROP DATABASE IF EXISTS orthotrack_restore;"
sudo -u postgres psql -c "CREATE DATABASE orthotrack_restore OWNER orthotrack ENCODING 'UTF8' TEMPLATE template0;"
sudo -u postgres psql orthotrack_restore < restore/db-dumps/postgresql-orthotrack.sql
```

Check it (row counts, latest activity), then swap names:

```bash
sudo -u postgres psql -c "ALTER DATABASE orthotrack RENAME TO orthotrack_old;"
sudo -u postgres psql -c "ALTER DATABASE orthotrack_restore RENAME TO orthotrack;"
```

> Ledgers and the activity log have append-only triggers. A dump/restore keeps them; you never need to disable them.

## 4. Restore files

```bash
rsync -a restore/var/www/orthotrack/storage/app/ /var/www/orthotrack/storage/app/
chown -R deploy:www-data /var/www/orthotrack/storage
```

## 5. Bring the app back

```bash
php artisan optimize:clear && php artisan optimize
php artisan horizon:terminate
php artisan up
```

Then verify: log in, open a branch and the activity log, and confirm the newest records match the backup time. Keep `orthotrack_old` for a few days, then drop it.

## Test the procedure

Restore the latest backup into a staging server **once a month** and note the date in the ops log. A backup that has never been restored is not a backup.
