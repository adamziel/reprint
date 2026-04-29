#!/usr/bin/env bash
# Provisions the dockerized source WP: installs WP, activates the exporter plugin,
# sets the shared secret, and seeds some content. Idempotent.
set -euo pipefail

cd "$(dirname "$0")"

docker compose up -d

docker compose exec -T wp bash -c '
  curl -sS -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
  chmod +x /tmp/wp-cli.phar
  WP="/tmp/wp-cli.phar --allow-root --path=/var/www/html"
  until $WP db check 2>/dev/null; do sleep 2; done
  if ! $WP core is-installed 2>/dev/null; then
    $WP core install \
      --url=http://localhost:8080 \
      --title="Reprint Source" \
      --admin_user=admin --admin_password=admin --admin_email=a@b.c \
      --skip-email
  fi
  $WP plugin activate reprint-exporter-wp
  $WP option update site_export_secret "demo-secret-12345"
  $WP post generate --count=5 >/dev/null 2>&1 || true
  echo "Ready: http://localhost:8080  secret: demo-secret-12345"
'
