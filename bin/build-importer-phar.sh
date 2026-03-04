#!/usr/bin/env bash
#
# Builds importer.phar — a self-contained, lean archive of the import client
# with all its runtime dependencies baked in.
#
# Usage:
#   ./bin/build-importer-phar.sh            # outputs importer.phar in project root
#   ./bin/build-importer-phar.sh /tmp/out   # outputs /tmp/out/importer.phar
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
OUTPUT_DIR="${1:-$PROJECT_ROOT}"
PHAR_FILE="$OUTPUT_DIR/importer.phar"

# ── Preflight checks ──────────────────────────────────────────────

if ! command -v php >/dev/null 2>&1; then
    echo "Error: php not found in PATH" >&2
    exit 1
fi

# Verify the submodule is initialised
if [ ! -f "$PROJECT_ROOT/lib/sqlite-database-integration/wp-includes/parser/class-wp-parser.php" ]; then
    echo "Error: sqlite-database-integration submodule not initialised." >&2
    echo "Run: git submodule update --init" >&2
    exit 1
fi

# Verify composer deps are installed (--no-dev is fine)
if [ ! -f "$PROJECT_ROOT/vendor/autoload.php" ]; then
    echo "Error: vendor/ not found. Run: composer install --no-dev" >&2
    exit 1
fi

# ── Assemble a staging directory ──────────────────────────────────

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

echo "Staging files …"

# 1. Importer source
cp -r "$PROJECT_ROOT/importer" "$STAGE/importer"

# 2. Shared utils required by import.php
mkdir -p "$STAGE/wordpress-plugin/generic"
cp "$PROJECT_ROOT/wordpress-plugin/generic/utils.php" "$STAGE/wordpress-plugin/generic/utils.php"

# 3. Submodule: only the parser + MySQL files the importer actually loads
mkdir -p "$STAGE/lib/sqlite-database-integration/wp-includes/parser"
mkdir -p "$STAGE/lib/sqlite-database-integration/wp-includes/mysql"
cp "$PROJECT_ROOT"/lib/sqlite-database-integration/wp-includes/parser/*.php \
   "$STAGE/lib/sqlite-database-integration/wp-includes/parser/"
cp "$PROJECT_ROOT"/lib/sqlite-database-integration/wp-includes/mysql/*.php \
   "$STAGE/lib/sqlite-database-integration/wp-includes/mysql/"

# 4. Composer vendor — runtime deps only, minus bloat
cp -r "$PROJECT_ROOT/vendor" "$STAGE/vendor"
# Strip test suites — they dominate the vendor size
find "$STAGE/vendor" -type d \( -iname 'tests' -o -iname 'test' -o -iname 'Tests' -o -iname 'Test' \) -exec rm -rf {} + 2>/dev/null || true
# Strip docs, examples, and other non-runtime files
find "$STAGE/vendor" -type d \( -iname 'docs' -o -iname 'doc' -o -iname 'examples' -o -iname 'example' \) -exec rm -rf {} + 2>/dev/null || true
find "$STAGE/vendor" -type f \( \
    -iname '*.md' -o -iname 'LICENSE*' -o -iname 'CHANGELOG*' \
    -o -iname 'CONTRIBUTING*' -o -iname '.gitignore' -o -iname '.gitattributes' \
    -o -iname 'phpunit.xml*' -o -iname 'phpstan*' -o -iname '.editorconfig' \
    -o -iname 'Makefile' -o -iname 'composer.json' -o -iname 'composer.lock' \
    -o -iname '.php-cs-fixer*' \) -delete 2>/dev/null || true

# 5. Phar bootstrap stub
cat > "$STAGE/stub.php" <<'STUB'
#!/usr/bin/env php
<?php
// Signal to import.php's CLI guard that we are the entry point.
define('IMPORTER_PHAR_ENTRY', true);
Phar::mapPhar('importer.phar');
require 'phar://importer.phar/importer/import.php';
__HALT_COMPILER();
STUB

# ── Build the phar ────────────────────────────────────────────────

echo "Building $PHAR_FILE …"

rm -f "$PHAR_FILE"

php -d phar.readonly=0 -r '
$phar = new Phar($argv[1]);
$phar->startBuffering();
$phar->buildFromDirectory($argv[2]);
$phar->setStub(file_get_contents($argv[2] . "/stub.php"));
$phar->stopBuffering();
echo "Done. Files in archive: " . $phar->count() . PHP_EOL;
' "$PHAR_FILE" "$STAGE"

# Show final size
SIZE=$(du -h "$PHAR_FILE" | cut -f1)
echo "Output: $PHAR_FILE ($SIZE)"
