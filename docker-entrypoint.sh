#!/bin/bash
set -e

mkdir -p /var/www/html/data/teamcal /var/www/html/public/uploads/icons

# Ensure mounted volumes are writable by Apache (www-data)
chown -R www-data:www-data /var/www/html/data /var/www/html/public/uploads || true
chmod -R ug+rwX /var/www/html/data /var/www/html/public/uploads || true

exec "$@"
