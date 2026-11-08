#!/usr/bin/env bash
#
# Update OrthoTrack on Hostinger shared hosting with a new package from make-release.php.
# Run over SSH from the app folder:
#   bash deploy/hostinger/update.sh ~/orthotrack-<commit>.zip
# Keeps .env, storage/ (uploads, logs, backups) and the SQLite database (outside this folder).
# PHP=/opt/alt/php83/usr/bin/php bash deploy/hostinger/update.sh …   if `php -v` is older than 8.3.
#
set -euo pipefail

ZIP="${1:?Give the package: bash deploy/hostinger/update.sh ~/orthotrack-<commit>.zip}"
PHP="${PHP:-php}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$APP_DIR"

[[ -f .env ]] || { echo "✖ No .env here — is this the app folder?"; exit 1; }

echo "▶ Maintenance mode"
$PHP artisan down --retry=15 || true
trap '$PHP artisan up' EXIT

echo "▶ Replace the code (files removed in the new version must go too)"
rm -rf app bootstrap/app.php bootstrap/providers.php config database/migrations database/seeders database/factories \
       lang resources routes vendor public/build public/js public/css public/fonts
unzip -oq "$ZIP" -x '.env' 'storage/*' -d "$APP_DIR"

echo "▶ Database"
$PHP artisan migrate --force
$PHP artisan db:seed --class='Database\Seeders\CurrencySeeder' --force
$PHP artisan db:seed --class='Database\Seeders\PermissionSeeder' --force
$PHP artisan db:seed --class='Database\Seeders\SettingsSeeder' --force

echo "▶ Caches"
$PHP artisan optimize:clear
$PHP artisan optimize
$PHP artisan filament:optimize

echo "▶ Restart the queue"
$PHP artisan queue:restart

echo "✔ Updated"
