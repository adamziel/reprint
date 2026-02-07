#!/usr/bin/env bash
set -euo pipefail

# E2E Test Teardown Script
# Cleans up test sites, databases, and temporary files

SITE_ROOT="/srv/e2e-sites"
DB_HOST="127.0.0.1"
DB_USER="e2e_admin"
DB_PASS="e2e_password"

echo "=== E2E Test Teardown ==="

# Fix permissions before removal (chmod 000 files)
sudo chmod -R u+rwX "$SITE_ROOT" 2>/dev/null || true

# Drop all e2e databases
for db in $(mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" -N -e "SHOW DATABASES LIKE 'e2e_%';" 2>/dev/null); do
    echo "  Dropping database: $db"
    mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" -e "DROP DATABASE IF EXISTS \`${db}\`;" 2>/dev/null
done

# Remove site directories
if [ -d "$SITE_ROOT" ]; then
    echo "  Removing site directories"
    sudo rm -rf "$SITE_ROOT"/*
fi

# Clean up external test data
sudo rm -rf /tmp/e2e-external-data 2>/dev/null || true

# Clean up import temp directories
rm -rf /tmp/e2e-import-* 2>/dev/null || true

# Clean up test hook state files
rm -f /tmp/e2e-hook-state-* 2>/dev/null || true

echo "=== Teardown complete ==="
