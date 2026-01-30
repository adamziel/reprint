# MySQL Dump Producer Test Suite

Comprehensive PHPUnit test suite for MySQLDumpProducer covering all aspects of MySQL data export functionality.

## Test Coverage

### 1. BasicDumpTest.php
Tests fundamental export functionality:
- ✓ Empty database handling
- ✓ Single table with/without data
- ✓ Multiple tables
- ✓ Round-trip integrity (export → import → verify)
- ✓ Batch size configuration
- ✓ Various data types (INT, VARCHAR, DECIMAL, DATE, etc.)
- ✓ NULL value handling
- ✓ CREATE TABLE statement generation

**Tests**: 10 test methods covering basic scenarios

### 2. PrimaryKeyVariationsTest.php
Tests cursor-based pagination with different primary key structures:
- ✓ Simple primary key (single column)
- ✓ Composite primary key (2 columns)
- ✓ Three-column composite primary key
- ✓ Tables with no primary key (offset-based)
- ✓ String primary keys
- ✓ UUID primary keys
- ✓ Mixed PK types in single export
- ✓ Large datasets with pagination

**Tests**: 8 test methods covering PK variations

### 3. ForeignKeysTest.php
Tests foreign key relationships and constraints:
- ✓ Simple foreign keys
- ✓ Multiple foreign keys per table
- ✓ Self-referencing foreign keys
- ✓ Composite foreign keys
- ✓ Circular foreign keys
- ✓ ON DELETE/ON UPDATE actions
- ✓ Table ordering with dependencies

**Tests**: 7 test methods covering FK scenarios

### 4. SpecialCharactersTest.php
Tests proper escaping of special characters:
- ✓ Single quotes (')
- ✓ Double quotes (")
- ✓ Backslashes (\)
- ✓ Newlines (\n, \r\n)
- ✓ Tabs (\t)
- ✓ NULL bytes (\x00)
- ✓ Mixed special characters
- ✓ Empty strings vs NULL
- ✓ String literal "NULL"
- ✓ SQL injection strings (safely escaped)
- ✓ Control characters

**Tests**: 11 test methods covering escaping

### 5. EncodingAndInvalidCharsTest.php
Tests Unicode and encoding handling:
- ✓ UTF-8 multibyte characters (Chinese, Greek, Arabic, Russian, Japanese)
- ✓ Emoji (including skin tones, flags, ZWJ sequences)
- ✓ Right-to-left text
- ✓ Combining characters
- ✓ Surrogate pairs (characters outside BMP)
- ✓ Zero-width characters
- ✓ Homoglyphs
- ✓ Invalid UTF-8 sequences (in VARBINARY)
- ✓ Mixed character encodings
- ✓ Long Unicode strings
- ✓ BOM (Byte Order Mark)
- ✓ Unicode whitespace variations

**Tests**: 12 test methods covering encoding

### 6. BinaryAndUncommonTypesTest.php
Tests binary data and less common MySQL types:
- ✓ BLOB types (TINYBLOB, BLOB, MEDIUMBLOB, LONGBLOB)
- ✓ BINARY and VARBINARY
- ✓ Empty binary fields
- ✓ ENUM type
- ✓ SET type
- ✓ JSON type
- ✓ BIT type
- ✓ Geometry types (POINT, LINESTRING, POLYGON)
- ✓ FULLTEXT indexes
- ✓ YEAR type
- ✓ Spatial types
- ✓ All numeric types
- ✓ Large BLOBs (1MB+)

**Tests**: 13 test methods covering data types

### 7. LargeDatasetReentrancyTest.php
Tests large-scale exports and cursor resumption:
- ✓ **200,000 rows** with resumption every 200 rows (1000+ iterations)
- ✓ Composite primary key with 10,000 rows
- ✓ No primary key with 5,000 rows
- ✓ Multiple tables with reentrancy
- ✓ Cursor state across batch boundaries
- ✓ Resumption mid-batch
- ✓ Small batch size (10 rows) with many iterations
- ✓ Cursor serialization/deserialization
- ✓ Single row export
- ✓ Exact batch size rows

**Tests**: 10 test methods covering reentrancy and large datasets

## Total Test Coverage

- **71 test methods** across 7 test files
- **200,000+ rows** exported in large dataset tests
- **1,000+ cursor resumptions** tested
- All MySQL data types covered
- All edge cases handled

## Requirements

- PHP 7.4 or higher
- PHPUnit 10.x
- MySQL 5.7 or higher
- PDO MySQL extension
- At least 512MB PHP memory for large dataset tests

## Installation

```bash
# Install PHPUnit (if not already installed)
composer require --dev phpunit/phpunit ^10.0

# Or download PHPUnit PHAR
wget https://phar.phpunit.de/phpunit-10.phar
chmod +x phpunit-10.phar
mv phpunit-10.phar /usr/local/bin/phpunit
```

## Configuration

Edit `phpunit.xml` to configure database connection:

```xml
<php>
    <env name="DB_HOST" value="localhost"/>
    <env name="DB_USER" value="root"/>
    <env name="DB_PASS" value=""/>
    <env name="DB_NAME" value="test_mysql_dump"/>
</php>
```

Or set environment variables:

```bash
export DB_HOST=localhost
export DB_USER=root
export DB_PASS=secret
export DB_NAME=test_mysql_dump
```

## Running Tests

### Run all tests
```bash
cd tests
phpunit
```

### Run specific test file
```bash
phpunit BasicDumpTest.php
phpunit LargeDatasetReentrancyTest.php
```

### Run specific test method
```bash
phpunit --filter testRoundTripIntegrity BasicDumpTest.php
phpunit --filter testLargeDatasetWithReentrancy LargeDatasetReentrancyTest.php
```

### Run with verbose output
```bash
phpunit --testdox --colors
```

### Run with coverage (if Xdebug installed)
```bash
phpunit --coverage-html coverage/
```

## Test Database

- Tests automatically create/drop the test database
- Database name: `test_mysql_dump` (configurable)
- Import tests create `test_mysql_dump_import`
- All databases are cleaned up after tests

## Performance

Approximate test execution times:

| Test File | Tests | Rows | Time |
|-----------|-------|------|------|
| BasicDumpTest | 10 | ~2,000 | 2-5s |
| PrimaryKeyVariationsTest | 8 | ~3,500 | 3-6s |
| ForeignKeysTest | 7 | ~50 | 2-4s |
| SpecialCharactersTest | 11 | ~100 | 2-4s |
| EncodingAndInvalidCharsTest | 12 | ~150 | 3-5s |
| BinaryAndUncommonTypesTest | 13 | ~50 | 5-10s |
| LargeDatasetReentrancyTest | 10 | **200,000+** | **30-60s** |

**Total**: ~50-100 seconds for complete test suite

## CI/CD Integration

### GitHub Actions Example

```yaml
name: MySQL Dump Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ALLOW_EMPTY_PASSWORD: yes
        ports:
          - 3306:3306
        options: --health-cmd="mysqladmin ping" --health-interval=10s

    steps:
      - uses: actions/checkout@v2
      - uses: php-actions/composer@v6
      - name: Run tests
        run: |
          cd tests
          phpunit --testdox
        env:
          DB_HOST: 127.0.0.1
          DB_USER: root
          DB_PASS: ''
          DB_NAME: test_mysql_dump
```

## Interpreting Results

### Success Output
```
PHPUnit 10.5.0 by Sebastian Bergmann

MySQL Dump Producer Test Suite
==============================
PHP Version: 8.2.0
PDO MySQL: Available

BasicDumpTest
 ✔ Empty database
 ✔ Single empty table
 ✔ Single table with data
 ✔ Multiple tables
 ✔ Round trip integrity
 ...

Time: 00:45.123, Memory: 128.00 MB

OK (71 tests, 250 assertions)
```

### Failure Analysis

If tests fail:

1. **Connection errors**: Check MySQL is running and credentials are correct
2. **Assertion failures**: Look for data mismatch details in output
3. **Timeout errors**: Increase `max_execution_time` in php.ini for large tests
4. **Memory errors**: Increase `memory_limit` in php.ini

## Debugging

### Enable verbose assertions
```bash
phpunit --testdox --verbose
```

### Run single test with debug output
```bash
phpunit --filter testLargeDatasetWithReentrancy --debug
```

### Check MySQL version compatibility
```bash
mysql --version
```

Minimum MySQL 5.7 required (for JSON type support)

## Known Limitations

1. **Geometry types**: Some geometry tests may behave differently on MySQL 5.7 vs 8.0
2. **Character sets**: Tests assume utf8mb4 support
3. **Memory**: Large dataset tests require at least 512MB PHP memory
4. **Execution time**: Large dataset tests may take 30-60 seconds

## Contributing

When adding new tests:

1. Extend `MySQLDumpProducerTestBase`
2. Use descriptive test names (`testFeatureDescription`)
3. Always include round-trip verification
4. Clean up test data in `tearDown()`
5. Document edge cases in comments

## Test Principles

Each test follows this pattern:

```php
public function testFeature(): void
{
    // 1. Setup: Create tables and insert test data
    $this->pdo->exec("CREATE TABLE ...");
    $this->pdo->exec("INSERT INTO ...");

    // 2. Export: Generate SQL dump
    $sql = $this->getDumpSQL();

    // 3. Assert: Verify SQL contains expected content
    $this->assertSQLContains('expected', $sql);

    // 4. Round-trip: Import to new database
    $importPdo = $this->executeDumpInNewDatabase($sql);

    // 5. Verify: Compare original and imported data
    $this->assertDatabasesEqual($this->pdo, $importPdo, ['table']);
}
```

This ensures:
- ✓ SQL is generated correctly
- ✓ SQL is valid and can be executed
- ✓ Data integrity is preserved
- ✓ No data loss or corruption
