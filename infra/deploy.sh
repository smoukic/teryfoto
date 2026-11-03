#!/bin/sh
set -eu
domain="${1:?Usage: deploy.sh domain}"
case "$domain" in test.teryfoto.com|teryfoto.com) ;; *) exit 2 ;; esac
base="/var/www/$domain"
sudo -n mkdir -p "$base/public_html" "$base/logs"
sudo -n chown rulcj:www-data "$base/public_html"
tar -xf /tmp/teryfoto-source.tar -C "$base/public_html"
sudo -n mkdir -p "$base/public_html/storage"
sudo -n chown -R rulcj:www-data "$base/public_html"
sudo -n chmod 750 "$base/public_html/storage"
sudo -n chmod g+w "$base/public_html/storage"
sed "s/DOMAIN/$domain/g" "$base/public_html/infra/nginx.conf.template" > /tmp/teryfoto-nginx.conf
if [ -e "/etc/nginx/sites-available/$domain" ]; then
    echo 'Existing vhost found; refusing to overwrite' >&2
    exit 1
fi
sudo -n install -m 644 /tmp/teryfoto-nginx.conf "/etc/nginx/sites-available/$domain"
sudo -n ln -s "/etc/nginx/sites-available/$domain" "/etc/nginx/sites-enabled/$domain"
if ! sudo -n nginx -t; then
    sudo -n unlink "/etc/nginx/sites-enabled/$domain"
    exit 1
fi
sudo -n systemctl reload nginx
curl -fsS -o /dev/null -w 'HTTP %{http_code}\n' -H "Host: $domain" http://127.0.0.1/
