#!/bin/sh
# Prepares the data directory on first boot, then hands over to Apache.
set -e

# Some builders hand back an image with more than one Apache multi-processing
# module enabled, and Apache refuses to start with "More than one MPM loaded".
# The image asserts a single MPM at build time; this repeats the check at boot
# so a platform that rebuilds or patches the base layer cannot break the start.
if [ "$(id -u)" = "0" ] && [ "$(ls /etc/apache2/mods-enabled/ 2>/dev/null | grep -c '^mpm_.*\.load$')" -gt 1 ]; then
    echo "zfeeder: more than one Apache MPM enabled, keeping mpm_prefork"
    for mod in event worker; do
        rm -f "/etc/apache2/mods-enabled/mpm_${mod}.load" "/etc/apache2/mods-enabled/mpm_${mod}.conf"
    done
    if [ ! -e /etc/apache2/mods-enabled/mpm_prefork.load ]; then
        ln -sf ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
        ln -sf ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf
    fi
fi

# Most hosting platforms (Railway, Cloud Run, Heroku, Fly) tell the container
# which port to listen on through PORT, and health-check that port. Apache
# defaults to 80, so the two have to be reconciled here rather than baked in.
LISTEN_PORT="${PORT:-80}"
if [ "$(id -u)" = "0" ]; then
    printf 'Listen %s\n' "$LISTEN_PORT" > /etc/apache2/ports.conf
    sed -ri "s!<VirtualHost \*:[0-9]+>!<VirtualHost *:${LISTEN_PORT}>!" /etc/apache2/sites-available/000-default.conf
    printf 'ServerName localhost\n' > /etc/apache2/conf-available/zf-servername.conf
    a2enconf zf-servername >/dev/null 2>&1 || true
    echo "zfeeder: listening on port ${LISTEN_PORT}"
fi

DATA="${ZF_DATA_DIR:-/var/www/data}"

# A volume arrives owned by root on most platforms, so ownership is set here,
# once, before anything tries to write. Running as root for this and then
# letting Apache drop to www-data is the reason the image does not set USER.
if [ "$(id -u)" = "0" ]; then
    mkdir -p "$DATA/categories" "$DATA/cache"
    chown -R www-data:www-data "$DATA"
    chmod 0750 "$DATA"
else
    mkdir -p "$DATA/categories" "$DATA/cache"
fi

# Run a zfeeder command as the web user, so anything it writes is owned
# correctly whether or not the container started as root.
as_web_user() {
    if [ "$(id -u)" = "0" ]; then
        su -s /bin/sh -c "php /var/www/html/bin/zfeeder $*" www-data
    else
        php /var/www/html/bin/zfeeder "$@"
    fi
}

# The SQLite schema has to exist before anything is written into it, and
# --ensure-schema exits cleanly when the flat backend is in use.
as_web_user migrate --ensure-schema || true

# Seed once, so a fresh volume starts with something to show rather than an
# empty screen. Seeding goes through the CLI rather than copying files, because
# copying OPML into the data directory only works for the flat backend - a
# SQLite installation would start with no categories and error on every page.
if [ ! -f "$DATA/config.json" ] && [ -f /var/www/html/data-dist/config.json.dist ]; then
    cp /var/www/html/data-dist/config.json.dist "$DATA/config.json"
fi

# `seed` leaves existing categories alone, so running it on every boot costs a
# few milliseconds and means a volume that was created by an older version, or
# that had its categories emptied, repairs itself. A marker file would not:
# it would record that seeding happened even when it did not take effect.
as_web_user seed || true
[ "$(id -u)" = "0" ] && chown -R www-data:www-data "$DATA"

exec "$@"
