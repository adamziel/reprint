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

cd "$(dirname "$0")"
npm install --silent
