#!/usr/bin/env bash
#
# OrthoTrack deploy (run on the VPS as the deploy user, from the app directory):
#   ./deploy/deploy.sh            # deploy the current branch
#   ./deploy/deploy.sh v0.1.0     # deploy a tag
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

REF="${1:-}"

echo "▶ Maintenance mode"
php artisan down --render="errors::503" --retry=15 || true
trap 'php artisan up' EXIT

echo "▶ Fetch code"
git fetch --tags --prune origin
if [[ -n "$REF" ]]; then
    git checkout --force "$REF"
else
    git pull --ff-only
fi

echo "▶ PHP dependencies"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

echo "▶ Database"
php artisan migrate --force
# Idempotent: adds new permissions / missing role templates / missing setting defaults.
php artisan db:seed --class=Database\\Seeders\\CurrencySeeder --force
php artisan db:seed --class=Database\\Seeders\\PermissionSeeder --force
php artisan db:seed --class=Database\\Seeders\\SettingsSeeder --force

echo "▶ Frontend"
npm ci --no-audit --no-fund
npm run build

echo "▶ Caches"
php artisan optimize:clear
php artisan optimize          # config, routes, events, views
php artisan filament:optimize # Filament components + Blade icons
php artisan storage:link 2>/dev/null || true

echo "▶ Restart workers"
php artisan horizon:terminate  # Supervisor starts a fresh Horizon with the new code
php artisan queue:restart

echo "✔ Deployed $(git rev-parse --short HEAD)"
