#!/usr/bin/env bash
set -euo pipefail

# E2E Test Site Setup Script
# Creates test site directories, databases, and configurations under /srv/e2e-sites/

SITE_ROOT="/srv/e2e-sites"
PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PLUGIN_SRC="$PROJECT_ROOT/wordpress-plugin"
DB_HOST="127.0.0.1"
DB_USER="e2e_admin"
DB_PASS="e2e_password"

echo "=== E2E Test Setup ==="
echo "Project root: $PROJECT_ROOT"
echo "Plugin source: $PLUGIN_SRC"
echo "Site root: $SITE_ROOT"

# Download WordPress to use as template for test sites
WP_VERSION="6.7"
WP_TARBALL="/tmp/wordpress-${WP_VERSION}.tar.gz"
WP_TEMPLATE="/tmp/wordpress-template"

if [ ! -d "$WP_TEMPLATE" ]; then
    echo "Downloading WordPress ${WP_VERSION}..."
    curl -sL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o "$WP_TARBALL"
    mkdir -p "$WP_TEMPLATE"
    tar xzf "$WP_TARBALL" -C "$WP_TEMPLATE" --strip-components=1
    echo "WordPress template ready at $WP_TEMPLATE"
fi

# Ensure site root exists
sudo mkdir -p "$SITE_ROOT"
sudo chown nginx:nginx "$SITE_ROOT"
sudo chmod 755 "$SITE_ROOT"

# Helper: create a test site
create_site() {
    local name="$1"
    local db_name="e2e_${name//-/_}"
    local secret="test-secret-${name}"
    local site_dir="$SITE_ROOT/$name"

    echo "  Creating site: $name (db: $db_name)"

    # Copy WordPress template files into site directory
    sudo mkdir -p "$site_dir"
    sudo cp -a "$WP_TEMPLATE/." "$site_dir/"

    # Create additional directories
    sudo mkdir -p "$site_dir/wp-content/plugins/site-export"
    sudo mkdir -p "$site_dir/test-data"

    # Create wp-config.php (overrides WP default)
    sudo tee "$site_dir/wp-config.php" > /dev/null <<WPEOF
<?php
define('DB_HOST', '${DB_HOST}');
define('DB_NAME', '${db_name}');
define('DB_USER', '${DB_USER}');
define('DB_PASSWORD', '${DB_PASS}');
\$table_prefix = 'wp_';
WPEOF

    # Create secret.php for HMAC auth
    sudo tee "$site_dir/wp-content/plugins/site-export/secret.php" > /dev/null <<SEOF
<?php return '${secret}';
SEOF

    # Copy plugin source files (PHP-FPM has ProtectHome=yes so can't follow symlinks to /home)
    sudo mkdir -p "$site_dir/wp-content/plugins/site-export/generic"
    sudo cp "$PLUGIN_SRC/api.php" "$site_dir/wp-content/plugins/site-export/api.php"
    sudo cp "$PLUGIN_SRC/generic/"*.php "$site_dir/wp-content/plugins/site-export/generic/"

    # Create database
    mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" -e "DROP DATABASE IF EXISTS \`${db_name}\`; CREATE DATABASE \`${db_name}\`;" 2>/dev/null

    # Set ownership
    sudo chown -R nginx:nginx "$site_dir"
    sudo chmod -R 755 "$site_dir"
}

# Helper: create sample database tables
create_sample_db() {
    local db_name="$1"
    mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" "$db_name" <<'SQL'
CREATE TABLE IF NOT EXISTS wp_options (
    option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    option_name VARCHAR(191) NOT NULL DEFAULT '',
    option_value LONGTEXT NOT NULL,
    autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
    UNIQUE KEY option_name (option_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wp_options (option_name, option_value, autoload) VALUES
    ('siteurl', 'http://localhost', 'yes'),
    ('home', 'http://localhost', 'yes'),
    ('blogname', 'E2E Test Site', 'yes'),
    ('blogdescription', 'Just another test site', 'yes'),
    ('active_plugins', 'a:0:{}', 'yes');

CREATE TABLE IF NOT EXISTS wp_posts (
    ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_author BIGINT UNSIGNED NOT NULL DEFAULT 0,
    post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
    post_date_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
    post_content LONGTEXT NOT NULL,
    post_title TEXT NOT NULL,
    post_excerpt TEXT NOT NULL,
    post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
    post_name VARCHAR(200) NOT NULL DEFAULT '',
    post_type VARCHAR(20) NOT NULL DEFAULT 'post',
    KEY post_name (post_name(191)),
    KEY post_type_status (post_type, post_status, post_date, ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, post_type) VALUES
    (1, NOW(), UTC_TIMESTAMP(), 'Hello World content with <b>HTML</b> and special chars: &amp; "quotes" ''apostrophes''', 'Hello World', '', 'publish', 'hello-world', 'post'),
    (1, NOW(), UTC_TIMESTAMP(), 'Second post with unicode: \xC3\xA9\xC3\xA0\xC3\xBC \xE2\x9C\x93 \xF0\x9F\x98\x80', 'Unicode Post', '', 'publish', 'unicode-post', 'post'),
    (1, NOW(), UTC_TIMESTAMP(), CONCAT('Binary test: ', CHAR(0 USING binary), CHAR(1 USING binary)), 'Binary Post', '', 'draft', 'binary-post', 'post');

CREATE TABLE IF NOT EXISTS wp_usermeta (
    umeta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    meta_key VARCHAR(255) DEFAULT NULL,
    meta_value LONGTEXT,
    KEY user_id (user_id),
    KEY meta_key (meta_key(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES
    (1, 'nickname', 'admin'),
    (1, 'first_name', 'Test'),
    (1, 'last_name', 'User'),
    (1, 'wp_capabilities', 'a:1:{s:13:"administrator";b:1;}');
SQL
}

# Helper: create sample test files
create_sample_files() {
    local site_dir="$1"
    local data_dir="$site_dir/test-data"

    sudo mkdir -p "$data_dir/subdir/nested"
    echo "Hello World" | sudo tee "$data_dir/hello.txt" > /dev/null
    echo "Test file content" | sudo tee "$data_dir/subdir/test.txt" > /dev/null
    echo "Nested file" | sudo tee "$data_dir/subdir/nested/deep.txt" > /dev/null

    # Binary file
    sudo dd if=/dev/urandom of="$data_dir/binary.bin" bs=1024 count=10 2>/dev/null

    # Empty file
    sudo touch "$data_dir/empty.txt"

    # File with special characters in content
    printf 'Line 1\nLine 2\nLine with tab\there\nLine with null\x00byte\n' | sudo tee "$data_dir/special-content.txt" > /dev/null

    sudo chown -R nginx:nginx "$data_dir"
}

# ============================================================================
# Create all test sites
# ============================================================================

echo ""
echo "--- Creating test sites ---"

# 1. Basic site
create_site "basic"
create_sample_db "e2e_basic"
create_sample_files "$SITE_ROOT/basic"

# 2. Symlinks outside
create_site "symlinks-outside"
create_sample_db "e2e_symlinks_outside"
create_sample_files "$SITE_ROOT/symlinks-outside"
# Create an external directory and symlink to it
sudo mkdir -p /tmp/e2e-external-data
echo "External file" | sudo tee /tmp/e2e-external-data/external.txt > /dev/null
sudo chown -R nginx:nginx /tmp/e2e-external-data
sudo ln -sfn /tmp/e2e-external-data "$SITE_ROOT/symlinks-outside/test-data/external-link"

# 3. Custom wp-content
create_site "custom-wp-content"
create_sample_db "e2e_custom_wp_content"
# Also set up custom-content directory with plugin files
sudo mkdir -p "$SITE_ROOT/custom-wp-content/custom-content/plugins/site-export/generic"
sudo cp "$PLUGIN_SRC/api.php" "$SITE_ROOT/custom-wp-content/custom-content/plugins/site-export/api.php"
sudo cp "$PLUGIN_SRC/generic/"*.php "$SITE_ROOT/custom-wp-content/custom-content/plugins/site-export/generic/"
sudo cp "$SITE_ROOT/custom-wp-content/wp-content/plugins/site-export/secret.php" \
    "$SITE_ROOT/custom-wp-content/custom-content/plugins/site-export/secret.php"
create_sample_files "$SITE_ROOT/custom-wp-content"
sudo chown -R nginx:nginx "$SITE_ROOT/custom-wp-content"

# 4. Emoji paths
create_site "emoji-paths"
create_sample_db "e2e_emoji_paths"
sudo mkdir -p "$SITE_ROOT/emoji-paths/test-data"
# Files with emoji names
echo "emoji file" | sudo tee "$SITE_ROOT/emoji-paths/test-data/fire.txt" > /dev/null
echo "rocket content" | sudo tee "$SITE_ROOT/emoji-paths/test-data/rocket-file.txt" > /dev/null
# File with spaces and special chars
echo "spaces" | sudo tee "$SITE_ROOT/emoji-paths/test-data/file with spaces.txt" > /dev/null
# Directory with unusual name
sudo mkdir -p "$SITE_ROOT/emoji-paths/test-data/dir-with-dashes"
echo "dashed" | sudo tee "$SITE_ROOT/emoji-paths/test-data/dir-with-dashes/inner.txt" > /dev/null
# Unicode filenames (must use printf for proper UTF-8 encoding)
printf 'unicode content' | sudo tee "$(printf '%s/emoji-paths/test-data/caf\xc3\xa9.txt' "$SITE_ROOT")" > /dev/null
printf 'chinese' | sudo tee "$(printf '%s/emoji-paths/test-data/\xe4\xb8\xad\xe6\x96\x87.txt' "$SITE_ROOT")" > /dev/null
# Actual emoji characters in filename
printf 'emoji content' | sudo tee "$(printf '%s/emoji-paths/test-data/\xf0\x9f\x94\xa5\xf0\x9f\x9a\x80.txt' "$SITE_ROOT")" > /dev/null
# File with newlines in the name
printf 'newline content' | sudo tee "$(printf '%s/emoji-paths/test-data/file\nwith\nnewlines.txt' "$SITE_ROOT")" > /dev/null
# Invalid UTF-8 sequence filename
printf 'invalid utf8' | sudo tee "$(printf '%s/emoji-paths/test-data/invalid\xff\xfeutf8.txt' "$SITE_ROOT")" > /dev/null
sudo chown -R nginx:nginx "$SITE_ROOT/emoji-paths"

# 5. SHA1 verify
create_site "sha1-verify"
create_sample_db "e2e_sha1_verify"
# Create files with known content for SHA1 verification
sudo mkdir -p "$SITE_ROOT/sha1-verify/test-data/deep/nested/path"
for i in $(seq 1 20); do
    printf "File content number %d with some padding to make it non-trivial\n" "$i" | \
        sudo tee "$SITE_ROOT/sha1-verify/test-data/file-$i.txt" > /dev/null
done
sudo dd if=/dev/urandom of="$SITE_ROOT/sha1-verify/test-data/large-binary.bin" bs=1024 count=256 2>/dev/null
echo "Deep nested content" | sudo tee "$SITE_ROOT/sha1-verify/test-data/deep/nested/path/deep-file.txt" > /dev/null
sudo chown -R nginx:nginx "$SITE_ROOT/sha1-verify"

# 6. Circular symlinks
create_site "circular-symlinks"
create_sample_db "e2e_circular_symlinks"
create_sample_files "$SITE_ROOT/circular-symlinks"
# Create circular symlinks
sudo ln -sfn "$SITE_ROOT/circular-symlinks/test-data/link-b" "$SITE_ROOT/circular-symlinks/test-data/link-a"
sudo ln -sfn "$SITE_ROOT/circular-symlinks/test-data/link-a" "$SITE_ROOT/circular-symlinks/test-data/link-b"
# Self-referencing symlink
sudo ln -sfn "$SITE_ROOT/circular-symlinks/test-data/self-link" "$SITE_ROOT/circular-symlinks/test-data/self-link"
sudo chown -R nginx:nginx "$SITE_ROOT/circular-symlinks" 2>/dev/null || true

# 7. Chmod denied
create_site "chmod-denied"
create_sample_db "e2e_chmod_denied"
create_sample_files "$SITE_ROOT/chmod-denied"
# Create unreadable files (set permissions after all other setup)
echo "secret content" | sudo tee "$SITE_ROOT/chmod-denied/test-data/unreadable.txt" > /dev/null
sudo chmod 000 "$SITE_ROOT/chmod-denied/test-data/unreadable.txt"
sudo mkdir -p "$SITE_ROOT/chmod-denied/test-data/unreadable-dir"
echo "inside" | sudo tee "$SITE_ROOT/chmod-denied/test-data/unreadable-dir/inside.txt" > /dev/null
sudo chmod 000 "$SITE_ROOT/chmod-denied/test-data/unreadable-dir"

# 8. MySQL restricted
create_site "mysql-restricted"
# Create the restricted database and tables
mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" -e "DROP DATABASE IF EXISTS e2e_mysql_restricted; CREATE DATABASE e2e_mysql_restricted;" 2>/dev/null
mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" "e2e_mysql_restricted" <<'SQL'
CREATE TABLE wp_options (
    option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    option_name VARCHAR(191) NOT NULL,
    option_value LONGTEXT NOT NULL,
    autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
    UNIQUE KEY option_name (option_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'http://localhost');

CREATE TABLE wp_secret_table (
    id INT PRIMARY KEY,
    secret_data TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO wp_secret_table VALUES (1, 'top secret');
SQL
# Update wp-config to use restricted user
sudo tee "$SITE_ROOT/mysql-restricted/wp-config.php" > /dev/null <<'WPEOF'
<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'e2e_mysql_restricted');
define('DB_USER', 'e2e_restricted');
define('DB_PASSWORD', 'e2e_restricted_pw');
$table_prefix = 'wp_';
WPEOF
create_sample_files "$SITE_ROOT/mysql-restricted"
# Grant restricted user privileges
mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" -e "GRANT SELECT ON e2e_mysql_restricted.* TO 'e2e_restricted'@'localhost' IDENTIFIED BY 'e2e_restricted_pw'; FLUSH PRIVILEGES;" 2>/dev/null || true

# 9. File changes (test hooks site)
create_site "file-changes"
create_sample_db "e2e_file_changes"
create_sample_files "$SITE_ROOT/file-changes"
# The test-hooks.php will be written by the test itself

# 10. Dir deleted
create_site "dir-deleted"
create_sample_db "e2e_dir_deleted"
create_sample_files "$SITE_ROOT/dir-deleted"

# 11. Volatile file
create_site "volatile-file"
create_sample_db "e2e_volatile_file"
create_sample_files "$SITE_ROOT/volatile-file"

# 12. Large directory
create_site "large-directory"
create_sample_db "e2e_large_directory"
sudo mkdir -p "$SITE_ROOT/large-directory/test-data/many-files"
# Create 2000 small files
for i in $(seq 1 2000); do
    printf "content-%04d" "$i" | sudo tee "$SITE_ROOT/large-directory/test-data/many-files/file-$(printf '%04d' $i).txt" > /dev/null
done
sudo chown -R nginx:nginx "$SITE_ROOT/large-directory"

# 13. HMAC errors
create_site "hmac-errors"
create_sample_db "e2e_hmac_errors"
create_sample_files "$SITE_ROOT/hmac-errors"

# 14. HTTP errors (test hooks)
create_site "http-errors"
create_sample_db "e2e_http_errors"
create_sample_files "$SITE_ROOT/http-errors"

# 15. Request cutoff (test hooks)
create_site "request-cutoff"
create_sample_db "e2e_request_cutoff"
create_sample_files "$SITE_ROOT/request-cutoff"

# 16. Gzip corrupt (test hooks)
create_site "gzip-corrupt"
create_sample_db "e2e_gzip_corrupt"
create_sample_files "$SITE_ROOT/gzip-corrupt"

# 17. Buffered (same as basic but tested via port 8098)
create_site "buffered"
create_sample_db "e2e_buffered"
create_sample_files "$SITE_ROOT/buffered"

# 18. Error chunks
create_site "error-chunks"
create_sample_db "e2e_error_chunks"
create_sample_files "$SITE_ROOT/error-chunks"

# 19. Import failures
create_site "import-failures"
create_sample_db "e2e_import_failures"
create_sample_files "$SITE_ROOT/import-failures"

# 20. Redirect (uses basic site via port 8097)
# No separate site needed - Nginx redirects 8097 -> 8081

echo ""
echo "=== Setup complete ==="
echo "Created $(ls -d $SITE_ROOT/*/ 2>/dev/null | wc -l) test sites"
