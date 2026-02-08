#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

echo "=== E2E Test Runner ==="
echo "Working directory: $SCRIPT_DIR"
echo ""

# Run tests (sites self-provision via ensureSite in before() hooks)
echo "--- Running test suite ---"
echo ""

FAILED=0
if node --test tests/ 2>&1; then
    echo ""
    echo "=== All tests passed ==="
else
    FAILED=1
    echo ""
    echo "=== Some tests failed ==="
fi

# Report
echo ""
echo "--- Test run complete ---"

exit $FAILED
