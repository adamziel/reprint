#!/bin/bash

# MySQL Dump Producer Test Runner
# Runs all tests with proper configuration and reporting

set -e

# Load and export environment variables from .env
set -a
source .env
set +a

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "================================"
echo "MySQL Dump Producer Test Suite"
echo "================================"
echo ""

# Check if PHPUnit is available
if ! command -v phpunit &> /dev/null; then
    echo -e "${RED}Error: PHPUnit not found${NC}"
    echo "Install it with: composer require --dev phpunit/phpunit"
    exit 1
fi

# Check if MySQL is available
if ! command -v mysql &> /dev/null; then
    echo -e "${RED}Error: MySQL client not found${NC}"
    echo "Install MySQL client to run these tests"
    exit 1
fi

# Test MySQL connection
DB_HOST=${DB_HOST:-localhost}
DB_USER=${DB_USER:-root}
DB_PASS=${DB_PASS:-}

echo "Testing MySQL connection..."
if [ -z "$DB_PASS" ]; then
    if mysql -h"$DB_HOST" -u"$DB_USER" -e "SELECT 1" &> /dev/null; then
        echo -e "${GREEN}✓ MySQL connection successful${NC}"
    else
        echo -e "${RED}✗ Cannot connect to MySQL${NC}"
        echo "Configure with: export DB_HOST=localhost DB_USER=root DB_PASS=secret"
        exit 1
    fi
else
    if mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "SELECT 1" &> /dev/null; then
        echo -e "${GREEN}✓ MySQL connection successful${NC}"
    else
        echo -e "${RED}✗ Cannot connect to MySQL${NC}"
        exit 1
    fi
fi

echo ""
echo "Running tests..."
echo ""

# Change to tests directory
cd "$(dirname "$0")"

# Run tests based on argument
case "${1:-all}" in
    "all")
        phpunit --testdox --colors
        ;;
    "basic")
        phpunit --testdox --colors BasicDumpTest.php
        ;;
    "pk")
        phpunit --testdox --colors PrimaryKeyVariationsTest.php
        ;;
    "fk")
        phpunit --testdox --colors ForeignKeysTest.php
        ;;
    "special")
        phpunit --testdox --colors SpecialCharactersTest.php
        ;;
    "encoding")
        phpunit --testdox --colors EncodingAndInvalidCharsTest.php
        ;;
    "binary")
        phpunit --testdox --colors BinaryAndUncommonTypesTest.php
        ;;
    "large")
        echo -e "${YELLOW}Running large dataset tests (may take 30-60 seconds)...${NC}"
        phpunit --testdox --colors LargeDatasetReentrancyTest.php
        ;;
    "fast")
        echo "Running fast tests only (excluding large dataset tests)..."
        phpunit --testdox --colors --exclude-group slow \
            BasicDumpTest.php \
            PrimaryKeyVariationsTest.php \
            ForeignKeysTest.php \
            SpecialCharactersTest.php \
            EncodingAndInvalidCharsTest.php \
            BinaryAndUncommonTypesTest.php
        ;;
    "coverage")
        if ! php -m | grep -q xdebug; then
            echo -e "${YELLOW}Warning: Xdebug not installed, coverage will be limited${NC}"
        fi
        phpunit --coverage-html coverage/ --coverage-text
        echo ""
        echo -e "${GREEN}Coverage report generated in coverage/index.html${NC}"
        ;;
    *)
        echo "Usage: $0 [all|basic|pk|fk|special|encoding|binary|large|fast|coverage]"
        echo ""
        echo "Options:"
        echo "  all       - Run all tests (default)"
        echo "  basic     - Run BasicDumpTest only"
        echo "  pk        - Run PrimaryKeyVariationsTest only"
        echo "  fk        - Run ForeignKeysTest only"
        echo "  special   - Run SpecialCharactersTest only"
        echo "  encoding  - Run EncodingAndInvalidCharsTest only"
        echo "  binary    - Run BinaryAndUncommonTypesTest only"
        echo "  large     - Run LargeDatasetReentrancyTest only (slow)"
        echo "  fast      - Run all tests except large dataset tests"
        echo "  coverage  - Generate code coverage report"
        exit 1
        ;;
esac

EXIT_CODE=$?

echo ""
if [ $EXIT_CODE -eq 0 ]; then
    echo -e "${GREEN}✓ All tests passed!${NC}"
else
    echo -e "${RED}✗ Some tests failed${NC}"
fi

exit $EXIT_CODE
