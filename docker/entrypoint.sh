#!/bin/sh
set -e

echo "Waiting for database..."
until php /app/bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; do
    sleep 2
done
echo "Database ready."

php /app/bin/console doctrine:migrations:migrate --no-interaction

exec supervisord -c /etc/supervisord.conf
