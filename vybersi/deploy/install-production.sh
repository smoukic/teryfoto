#!/bin/sh
set -eu
[ "$(id -u)" = 0 ] || { echo 'Spusťte instalační skript jako root.' >&2; exit 1; }

release=${1:?Zadejte absolutní cestu k archivu}
base=/var/www/vybersi.rulc.eu
available=/etc/nginx/sites-available/vybersi.rulc.eu
enabled=/etc/nginx/sites-enabled/vybersi.rulc.eu
pool=/etc/php/8.2/fpm/pool.d/vybersi.conf

for path in "$base" "$available" "$enabled" "$pool"; do
    if [ -e "$path" ] || [ -L "$path" ]; then
        echo "Existující umístění: $path" >&2
        exit 1
    fi
done

apt-get update
apt-get install -y postgresql php8.2-pgsql
systemctl start postgresql

if ! id vybersi >/dev/null 2>&1; then
    useradd --system --user-group --no-create-home --home-dir "$base" --shell /usr/sbin/nologin vybersi
fi
if [ "$(runuser -u postgres -- psql -XAtc "SELECT 1 FROM pg_roles WHERE rolname = 'vybersi'")" != 1 ]; then
    runuser -u postgres -- createuser vybersi
fi
if [ "$(runuser -u postgres -- psql -XAtc "SELECT 1 FROM pg_database WHERE datname = 'vybersi'")" != 1 ]; then
    runuser -u postgres -- createdb --owner=vybersi vybersi
fi

install -d -m 755 "$base"
tar --extract --gzip --file "$release" --directory "$base" --no-same-owner --no-same-permissions
cd "$base"
install -d -o vybersi -g vybersi -m 750 storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R vybersi:vybersi storage bootstrap/cache
chmod 750 storage bootstrap/cache
install -o vybersi -g vybersi -m 600 deploy/production.env.example .env

runuser -u vybersi -- php artisan key:generate --force --no-interaction
runuser -u vybersi -- php artisan migrate --force --no-interaction

install -o root -g root -m 644 deploy/production-fpm.conf "$pool"
if ! /usr/sbin/php-fpm8.2 -t; then
    rm -f -- "$pool"
    exit 1
fi
systemctl reload php8.2-fpm

install -o root -g root -m 644 deploy/production-nginx.conf "$available"
ln -s "$available" "$enabled"
if ! /usr/sbin/nginx -t; then
    rm -f -- "$enabled" "$available"
    exit 1
fi
systemctl reload nginx
curl --fail --silent --show-error --max-time 10 -H 'Host: vybersi.rulc.eu' http://127.0.0.1/up >/dev/null
echo "Vyber si odpovídá na Nginxu. Administrátor: sudo -u vybersi php $base/artisan make:filament-user"

