#!/bin/sh
# shellcheck disable=SC2016 # PHP code in single quotes
# Installs or updates Bagisto on every `docker compose up`; safe to repeat.
# Runs as root (volumes' owners); PHP runs as www-data:
# - public/ (the image's, static files for Caddy) copied to the `public`
#   volume when the image's build id changes.
# - Empty database: migrations, then the seeders of Bagisto's installer
#   (BAGISTO_LOCALE, BAGISTO_CURRENCY) and the admin user (scripts/install.php).
#   Bagisto's own `bagisto:install` is never run: it wipes the database.
#   A database with tables but no Bagisto admin is refused.
# - Installed: pending migrations (a new version).
# - scripts/configure.php: channel URL on every run, store settings once.
set -eu
cd /var/www/bagisto
# shellcheck source=scripts/mail-env.sh
. /usr/local/share/stack/scripts/mail-env.sh

php_www() { su-exec www-data php "$@"; }
artisan() { php_www artisan "$@"; }

echo "==> Public files for Caddy"
build="$(cat public/.build)"
if [ "$(cat /srv/public/.build 2>/dev/null || true)" != "${build}" ]; then
    echo "Copying the image's public/ (${build})"
    find /srv/public -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    tar -C public --exclude=./.build -cf - . | tar -C /srv/public -xf -
    # Build id last: an interrupted copy is repeated.
    cp public/.build /srv/public/.build
fi
# Laravel's storage tree (a new local directory is empty: overrides/local-dirs.yaml).
mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs
find storage ! -user www-data -exec chown www-data:www-data {} +

echo "==> Database"
for _ in $(seq 60); do
    php -r 'try { new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Exception $e) { exit(1); }' 2>/dev/null && break
    sleep 2
done
# installed: admins has rows; empty: no tables; other: anything else.
# Separate assignment: with `set -e`, a failing check stops setup here.
state="$(php -r '
    $db = new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT") . ";dbname=" . getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    if (!$tables) { echo "empty"; exit; }
    if (in_array("admins", $tables, true) && $db->query("SELECT COUNT(*) FROM admins")->fetchColumn() > 0) { echo "installed"; exit; }
    echo "other";')"

case "${state}" in
    empty)
        echo "==> Installing Bagisto ${BAGISTO_VERSION} (${APP_LOCALE}, ${APP_CURRENCY})"
        artisan migrate --force
        # Seeders and the admin user (its row marks the install: written last).
        php_www /usr/local/share/stack/scripts/install.php
        ;;
    installed)
        echo "==> Migrations"
        artisan migrate --force
        ;;
    *)
        echo "The database ${DB_DATABASE} has tables but no Bagisto admin user:" >&2
        echo "a failed first install or another application's data. Nothing was" >&2
        echo "changed. For a new install, empty it (docker compose down -v)." >&2
        exit 1
        ;;
esac

echo "==> Store settings"
php_www /usr/local/share/stack/scripts/configure.php
# Bagisto's installed marker (checked on every request; otherwise it
# redirects to its web installer).
[ -f storage/installed ] || php_www -r 'file_put_contents("storage/installed", "Bagisto is successfully installed.");'
# Caches of the previous version or settings; compiled views for every
# container (built here once: they share the storage volume).
artisan optimize:clear > /dev/null
artisan responsecache:clear > /dev/null
artisan view:cache > /dev/null

echo "==> Done: Bagisto ${BAGISTO_VERSION}"
echo "    Shop:  ${APP_URL}"
echo "    Admin: ${APP_URL}/${APP_ADMIN_URL} (${ADMIN_MAIL_ADDRESS})"
