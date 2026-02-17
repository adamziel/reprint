#!/usr/bin/env bash
set -euo pipefail

# Install Composer dependencies (vendor/ is gitignored).
# Needed for url-rewriting classes from wp-php-toolkit/data-liberation.
PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
if command -v composer &>/dev/null && [ -f "$PROJECT_ROOT/composer.json" ]; then
    composer install --no-dev --no-interaction --prefer-dist --working-dir="$PROJECT_ROOT"
fi

cd "$(dirname "$0")"
npm install --silent
