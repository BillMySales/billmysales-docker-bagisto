#!/bin/sh
# Entrypoint of the Bagisto containers (php, worker, scheduler, console):
# mail settings from SMTP_*, Laravel's config, route and event caches (in
# bootstrap/cache, inside this container) built from this container's
# environment, then the command. Compiled views live in the shared storage
# volume: setup builds them once (containers starting together raced).
set -eu
cd /var/www/bagisto
# shellcheck source=scripts/mail-env.sh
. /usr/local/share/stack/scripts/mail-env.sh

as_www() {
    if [ "$(id -u)" = 0 ]; then su-exec www-data "$@"; else "$@"; fi
}
as_www php artisan config:cache > /dev/null
as_www php artisan route:cache > /dev/null
as_www php artisan event:cache > /dev/null

exec "$@"
