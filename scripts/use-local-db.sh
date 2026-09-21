#!/usr/bin/env bash
#
# Point .env at the local MySQL, for when the shared Railway database is away
# and the app still has to run.
#
set -euo pipefail
cd "$(dirname "$0")/.."

cp .env "backups/env_$(date +%Y%m%d_%H%M%S).bak"
php scripts/db-target.php local
php artisan config:clear >/dev/null 2>&1 || true
