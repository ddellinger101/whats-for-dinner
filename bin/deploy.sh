#!/usr/bin/env bash
#
# Deploy What's For Dinner on the Cloudways server.
#
#   ssh chef 'bash /home/master/applications/zxnzbudyru/public_html/bin/deploy.sh'
#
# Deliberately fails loudly: a half-applied deploy is worse than a stopped one.
set -euo pipefail

APP_DIR="/home/master/applications/zxnzbudyru/public_html"
cd "$APP_DIR"

# Server Node is too old for Vite; nvm supplies a current one. The stale prefix
# in ~/.npmrc means --delete-prefix is required.
export NVM_DIR="$HOME/.nvm"
# shellcheck disable=SC1091
. "$NVM_DIR/nvm.sh"
nvm use --delete-prefix v22.23.2 >/dev/null

echo "==> Fetching"
git fetch --quiet origin
git reset --hard origin/main

echo "==> PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --quiet

echo "==> Frontend build"
npm ci --silent --no-audit --no-fund
npm run build --silent

echo "==> Migrations"
php artisan migrate --force

echo "==> Storage permissions"
# The web server runs as a different user from this shell, so anything the CLI
# creates under storage is unwritable to it by default — which silently broke
# photo uploads. setgid on the directories makes new subdirectories inherit the
# group instead of repeating the problem the next time one is created.
#
# Restricted to what this user owns. Photos the web server wrote belong to it,
# not to us, so chmod on those can only ever fail — and under `set -e` that
# aborted the deploy before the caches were rebuilt, leaving the server running
# new code against an old route cache. They are already writable by their owner,
# so skipping them costs nothing.
me="$(id -un)"
find storage -type d -user "$me" -exec chmod 2775 {} +
find storage bootstrap/cache -user "$me" -exec chmod g+w {} +

echo "==> Rebuilding caches"
php artisan optimize:clear >/dev/null
php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null

echo "==> Deployed $(git log --oneline -1)"
echo "    Varnish caches aggressively; purge from the Cloudways panel if a"
echo "    change does not appear. Append ?cb=\$RANDOM to test the real app."
