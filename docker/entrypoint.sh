#!/bin/sh
set -e

echo "Waiting for database..."
until php /app/bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; do
    sleep 2
done
echo "Database ready."

php /app/bin/console doctrine:migrations:migrate --no-interaction

# Entrypoint runs as root, so any cache files created above (and any
# future root-owned writes inside /app/var) would block php-fpm, which
# runs as www-data. Normalise ownership before supervisord spawns workers.
chown -R www-data:www-data /app/var/cache /app/var/log /app/var/traces

exec supervisord -c /etc/supervisord.conf
