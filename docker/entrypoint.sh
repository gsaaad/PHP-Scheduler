#!/bin/sh
set -e

# Migrations are opt-in via RUN_MIGRATIONS=1.
#
# Convenient for docker compose and single-container deployments. Leave it unset
# on Kubernetes/ECS and run `php scripts/migrate.php` as a separate init job or
# task instead -- with several replicas starting at once you want exactly one
# process applying schema changes, not N racing.
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    echo "[entrypoint] waiting for database ..."
    for i in $(seq 1 30); do
        if php -r '
            $c = require "/var/www/html/config/database.php";
            try { new PDO("pgsql:host={$c["host"]};port={$c["port"]};dbname={$c["dbname"]}", $c["user"], $c["password"]); exit(0); }
            catch (Throwable $e) { exit(1); }
        ' 2>/dev/null; then
            break
        fi
        sleep 2
    done

    echo "[entrypoint] applying migrations ..."
    php /var/www/html/scripts/migrate.php
fi

exec "$@"
