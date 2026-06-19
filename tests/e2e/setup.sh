#!/usr/bin/env bash
set -euo pipefail

# Install Composer dependencies (vendor/ is gitignored).
# Needed for the importer package and the bundled plugin runtime.
PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
if command -v composer &>/dev/null && [ -f "$PROJECT_ROOT/composer.json" ]; then
    composer install --no-dev --no-interaction --prefer-dist --working-dir="$PROJECT_ROOT"
fi
if command -v composer &>/dev/null && [ -f "$PROJECT_ROOT/reprint-exporter-wp/composer.json" ]; then
    composer install --no-dev --no-interaction --prefer-dist --working-dir="$PROJECT_ROOT/reprint-exporter-wp"
fi

# JavaScript dependencies are installed by the dedicated workflow step after
# setup. Keep this script focused on PHP/runtime dependencies so npm failures
# are reported by that explicit install step instead of being hidden here.
