#!/usr/bin/env bash
#
# Point .env at the shared Railway database, but only once Railway actually
# answers, and bring its schema up to date afterwards.
#
# Railway's TCP proxy accepts a socket whether or not MySQL is alive behind it,
# and the dashboard's "Online" badge reports the container rather than mysqld,
# so a real connection is the only test worth running.
#
set -euo pipefail
cd "$(dirname "$0")/.."

PASS="$(sed -nE 's/^#?[[:space:]]*DB_PASSWORD=(.+)$/\1/p' .env | grep -v '^$' | tail -1 || true)"
HOST="$(sed -nE 's/^#?[[:space:]]*DB_HOST=(.+)$/\1/p' .env | grep -vE '^(127\.0\.0\.1|localhost)$' | tail -1 || true)"
HOST="${HOST:-66.33.22.240}"

if [ -z "$PASS" ]; then
    echo "No Railway password found in .env. Nothing changed." >&2
    exit 1
fi

echo "Testing the Railway database at ${HOST}..."
if ! RW_PASS="$PASS" RW_HOST="$HOST" php -r '
$t = microtime(true);
try {
    $pdo = new PDO(sprintf("mysql:host=%s;port=28994;dbname=railway", getenv("RW_HOST")), "root", getenv("RW_PASS"),
        [PDO::ATTR_TIMEOUT => 15, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->query("select 1");
    printf("  reachable in %.2fs\n", microtime(true) - $t);
    exit(0);
} catch (Throwable $e) {
    printf("  unreachable after %.2fs: %s\n", microtime(true) - $t, $e->getMessage());
    exit(1);
}'; then
    echo
    echo "Railway is not answering, so .env was left alone."
    echo "If the dashboard says the service is Online, the usual cause is this"
    echo "machine resolving shuttle.proxy.rlwy.net to its NAT64 IPv6 address."
    echo "Connect by IP, or turn IPv6 off:  sudo networksetup -setv6off Wi-Fi"
    exit 1
fi

cp .env "backups/env_$(date +%Y%m%d_%H%M%S).bak"
php scripts/db-target.php railway
php artisan config:clear >/dev/null 2>&1 || true

echo "Applying any migrations Railway is missing..."
php artisan migrate --force

php artisan optimize:clear >/dev/null 2>&1 || true
echo
echo "Done. The app is on the Railway database, schema up to date."
