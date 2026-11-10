#!/usr/bin/env bash
#
# SUTURA — Hostinger Cloud VPS post-deploy script (Laravel API).
# Run this on the VPS after the repo is updated (git pull / rsync).
#
#   cd $SUTURA_HOME && ./deploy/deploy-hostinger.sh
#
# Env: SUTURA_HOME (repo root). Assumes PHP 8.3 + Composer + git + postgresql-client.
# First run also installs the scheduler cron and (optionally) a queue worker.
set -euo pipefail

SUTURA_HOME="${SUTURA_HOME:?set SUTURA_HOME to the repo root, e.g. /var/www/sutura-server}"
cd "$SUTURA_HOME"

[ -f .env ] || { echo "ERROR: .env not found (copy .env.production.example -> .env and fill it)."; exit 1; }
grep -q '^APP_ENV=production' .env || echo "WARN: APP_ENV is not 'production'."
[ -d node_modules ] && { echo "NOTE: node_modules present — this is a PHP deploy; Vercel builds the client."; }

echo "==> Dependencies"
composer install --no-dev --optimize-autoloader

echo "==> Cache config / routes / views"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Migrations"
# Run only when the schema changed. For a no-op redeploy, skip with SKIP_MIGRATE=1.
if [ "${SKIP_MIGRATE:-0}" = "1" ]; then
  echo "SKIP_MIGRATE=1 set — skipping migrations."
else
  php artisan migrate --force
fi

echo "==> Public storage link"
php artisan storage:link

echo "==> Scheduler cron (fires the 9 app:* commands incl. the daily R2 DB backup)"
CRON_LINE="* * * * * cd $SUTURA_HOME && php artisan schedule:run >> /var/log/sutura-scheduler.log 2>&1"
if grep -q "php artisan schedule:run" /etc/cron.d/sutura-scheduler 2>/dev/null || crontab -l 2>/dev/null | grep -q "php artisan schedule:run"; then
  echo "Scheduler cron already installed."
else
  echo "  Add (root):  echo '$CRON_LINE' | sudo tee /etc/cron.d/sutura-scheduler  (set the file owner to crontab-friendly: 0 0 .. crontab or use /etc/cron.d with a user field)"
  echo "  or:          (crontab -l 2>/dev/null; echo \"$CRON_LINE\") | crontab -"
fi

echo "==> Queue (optional; only if QUEUE_CONNECTION != sync)"
if grep -q '^QUEUE_CONNECTION=database' .env; then
  echo "  QUEUE_CONNECTION=database detected. Run a supervised worker, e.g.:"
  echo "    php artisan queue:work --tries=3 --timeout=120   (under a process manager)"
else
  echo "  QUEUE_CONNECTION=sync — notifications fire in-request; the cron scheduler is sufficient."
fi

echo "==> Done."
echo "  Verify: php artisan about ; tail /var/log/sutra-scheduler.log"
echo "  The daily app:backup-database runs via the cron above and uploads the dump to BACKUP_DISK (R2)."
