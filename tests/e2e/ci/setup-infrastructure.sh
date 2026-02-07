#!/usr/bin/env bash
# CI infrastructure setup for E2E tests.
# Installs and configures MariaDB, PHP 8.2 FPM, and Nginx on an Ubuntu runner.
set -euo pipefail

SITE_ROOT="/srv/e2e-sites"
FPM_SOCKET="/run/php/e2e.sock"

# ---------- PHP 8.2 ----------
echo "=== Installing PHP 8.2 ==="
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update -qq
sudo apt-get install -y -qq \
    php8.2-cli php8.2-fpm \
    php8.2-mysql php8.2-mbstring php8.2-curl php8.2-xml php8.2-zip

# ---------- MariaDB ----------
echo "=== Installing MariaDB ==="
sudo apt-get install -y -qq mariadb-server
sudo systemctl start mariadb

echo "=== Creating MySQL users ==="
sudo mysql <<'SQL'
CREATE USER IF NOT EXISTS 'e2e_admin'@'127.0.0.1' IDENTIFIED BY 'e2e_password';
GRANT ALL PRIVILEGES ON *.* TO 'e2e_admin'@'127.0.0.1' WITH GRANT OPTION;
CREATE USER IF NOT EXISTS 'e2e_admin'@'localhost' IDENTIFIED BY 'e2e_password';
GRANT ALL PRIVILEGES ON *.* TO 'e2e_admin'@'localhost' WITH GRANT OPTION;
CREATE USER IF NOT EXISTS 'e2e_restricted'@'localhost' IDENTIFIED BY 'e2e_restricted_pw';
CREATE USER IF NOT EXISTS 'e2e_restricted'@'127.0.0.1' IDENTIFIED BY 'e2e_restricted_pw';
FLUSH PRIVILEGES;
SQL

# ---------- Nginx ----------
echo "=== Installing Nginx ==="
sudo apt-get install -y -qq nginx

# ---------- nginx user ----------
echo "=== Creating nginx user ==="
sudo groupadd -f nginx
sudo useradd -r -g nginx -s /usr/sbin/nologin nginx 2>/dev/null || true

# ---------- PHP-FPM pool ----------
echo "=== Configuring PHP-FPM ==="
sudo mkdir -p /run/php

sudo rm -f /etc/php/8.2/fpm/pool.d/www.conf

cat <<EOF | sudo tee /etc/php/8.2/fpm/pool.d/e2e.conf >/dev/null
[e2e]
user = nginx
group = nginx
listen = ${FPM_SOCKET}
listen.owner = nginx
listen.group = nginx
listen.mode = 0660

pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8

php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 120
php_admin_value[upload_max_filesize] = 50M
php_admin_value[post_max_size] = 50M
php_admin_value[error_reporting] = E_ALL
php_admin_value[display_errors] = Off
php_admin_value[log_errors] = On
php_admin_value[error_log] = /tmp/php-e2e-errors.log

env[SITE_EXPORT_TEST_MODE] = 1
EOF

# ---------- Nginx config ----------
echo "=== Configuring Nginx ==="
sudo mkdir -p "${SITE_ROOT}"
sudo chown nginx:nginx "${SITE_ROOT}"

cat <<'EOF' | sudo tee /etc/nginx/nginx.conf >/dev/null
user nginx;
worker_processes auto;
pid /run/nginx.pid;
error_log /var/log/nginx/error.log;

events {
    worker_connections 768;
}

http {
    sendfile on;
    tcp_nopush on;
    types_hash_max_size 2048;
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    access_log /var/log/nginx/access.log;
    client_max_body_size 50m;
    include /etc/nginx/conf.d/*.conf;
}
EOF

sudo rm -f /etc/nginx/sites-enabled/default
sudo rm -f /etc/nginx/conf.d/default.conf

# Standard sites — each gets the same fastcgi template on its own port.
declare -A SITES=(
    [basic]=8081
    [symlinks-outside]=8082
    [custom-wp-content]=8083
    [chmod-denied]=8084
    [mysql-restricted]=8085
    [circular-symlinks]=8086
    [file-changes]=8087
    [dir-deleted]=8088
    [volatile-file]=8089
    [emoji-paths]=8090
    [large-directory]=8091
    [hmac-errors]=8092
    [sha1-verify]=8093
    [http-errors]=8094
    [request-cutoff]=8095
    [gzip-corrupt]=8096
    [error-chunks]=8099
    [import-failures]=8100
)

for site in "${!SITES[@]}"; do
    port="${SITES[$site]}"
    cat <<VHOST | sudo tee "/etc/nginx/conf.d/e2e-${site}.conf" >/dev/null
server {
    listen 127.0.0.1:${port};
    root ${SITE_ROOT}/${site}/wp-content/plugins/site-export;

    location / {
        try_files \$uri \$uri/ /api.php?\$query_string;
    }

    location ~ \\.php\$ {
        fastcgi_pass unix:${FPM_SOCKET};
        fastcgi_index api.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param SITE_EXPORT_TEST_MODE "1";
        fastcgi_read_timeout 120s;
        fastcgi_send_timeout 120s;
    }
}
VHOST
done

# 301 redirect site (port 8097 → 8081)
cat <<'VHOST' | sudo tee /etc/nginx/conf.d/e2e-redirect-301.conf >/dev/null
server {
    listen 127.0.0.1:8097;
    location / {
        return 301 http://127.0.0.1:8081$request_uri;
    }
}
VHOST

# Buffered-response site (port 8098)
cat <<VHOST | sudo tee /etc/nginx/conf.d/e2e-buffered.conf >/dev/null
server {
    listen 127.0.0.1:8098;
    root ${SITE_ROOT}/buffered/wp-content/plugins/site-export;

    location / {
        try_files \$uri \$uri/ /api.php?\$query_string;
    }

    location ~ \\.php\$ {
        fastcgi_pass unix:${FPM_SOCKET};
        fastcgi_index api.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param SITE_EXPORT_TEST_MODE "1";
        fastcgi_read_timeout 120s;
        fastcgi_buffering on;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 8 128k;
    }
}
VHOST

# ---------- Start services ----------
echo "=== Starting services ==="
sudo systemctl restart php8.2-fpm
sudo systemctl restart nginx

# ---------- Verify ----------
echo "=== Verifying services ==="
sudo systemctl is-active --quiet php8.2-fpm && echo "php8.2-fpm: active" || { echo "php8.2-fpm: FAILED"; exit 1; }
sudo systemctl is-active --quiet nginx        && echo "nginx: active"      || { echo "nginx: FAILED"; exit 1; }
sudo systemctl is-active --quiet mariadb      && echo "mariadb: active"    || { echo "mariadb: FAILED"; exit 1; }

echo "=== Infrastructure setup complete ==="
