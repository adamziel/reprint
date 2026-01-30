<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

/**
 * Tests basic MySQL dump functionality with simple tables.
 */
class BasicDumpTest extends MySQLDumpProducerTestBase
{
    public function testEmptyDatabase(): void
    {
        // No tables created
        $producer = $this->createProducer();
        $fragments = $this->collectAllFragments($producer);

        // Should produce header and footer even for empty database
        $this->assertCount(2, $fragments, 'Empty database should produce header and footer');
        $this->assertStringContainsString('SET @OLD_UNIQUE_CHECKS', $fragments[0]);
        $this->assertStringContainsString('COMMIT', $fragments[1]);
        $this->assertTrue($producer->is_finished());
    }

    public function testSingleEmptyTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE users (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL
            )
        ");

        $sql = $this->getDumpSQL();

        $this->assertSQLContains('CREATE TABLE `users`', $sql);
        $this->assertSQLContains('Dumping data for table `users`', $sql);
        $this->assertSQLNotContains('INSERT INTO', $sql, 'Empty table should have no INSERTs');
    }

    public function testSingleTableWithData(): void
    {
        $this->pdo->exec("
            CREATE TABLE products (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                price DECIMAL(10,2) NOT NULL
            )
        ");

        $this->pdo->exec("
            INSERT INTO products (name, price) VALUES
            ('Widget', 19.99),
            ('Gadget', 29.99),
            ('Doohickey', 9.99)
        ");

        $sql = $this->getDumpSQL();

        $this->assertSQLContains('CREATE TABLE `products`', $sql);
        $this->assertSQLContains('INSERT INTO `products`', $sql);
        $this->assertSQLContains('Widget', $sql);
        $this->assertSQLContains('19.99', $sql);
        $this->assertSQLContains('Gadget', $sql);
    }

    public function testMultipleTables(): void
    {
        // Create multiple tables
        $this->pdo->exec("
            CREATE TABLE categories (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE products (
                id INT PRIMARY KEY AUTO_INCREMENT,
                category_id INT NOT NULL,
                name VARCHAR(100) NOT NULL,
                price DECIMAL(10,2) NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE orders (
                id INT PRIMARY KEY AUTO_INCREMENT,
                product_id INT NOT NULL,
                quantity INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Insert data
        $this->pdo->exec("INSERT INTO categories (name) VALUES ('Electronics'), ('Books')");
        $this->pdo->exec("INSERT INTO products (category_id, name, price) VALUES (1, 'Laptop', 999.99), (2, 'Novel', 14.99)");
        $this->pdo->exec("INSERT INTO orders (product_id, quantity) VALUES (1, 2), (2, 1)");

        $sql = $this->getDumpSQL();
        $tables = $this->extractTableNames($sql);

        // Verify all tables are present and in alphabetical order
        $this->assertEquals(['categories', 'orders', 'products'], $tables);

        // Verify data is present
        $this->assertSQLContains('Electronics', $sql);
        $this->assertSQLContains('Laptop', $sql);
        $this->assertSQLContains('999.99', $sql);
    }

    public function testRoundTripIntegrity(): void
    {
        // Create schema
        $this->pdo->exec("
            CREATE TABLE employees (
                id INT PRIMARY KEY AUTO_INCREMENT,
                first_name VARCHAR(50) NOT NULL,
                last_name VARCHAR(50) NOT NULL,
                salary DECIMAL(10,2),
                hire_date DATE,
                is_active BOOLEAN DEFAULT TRUE
            )
        ");

        // Insert test data
        $this->pdo->exec("
            INSERT INTO employees (first_name, last_name, salary, hire_date, is_active) VALUES
            ('John', 'Doe', 75000.50, '2020-01-15', TRUE),
            ('Jane', 'Smith', 82000.00, '2019-06-01', TRUE),
            ('Bob', 'Johnson', 65000.75, '2021-03-10', FALSE)
        ");

        // Export
        $sql = $this->getDumpSQL();

        // Import to new database
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Verify data integrity
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['employees']);
    }

    public function testBatchSizeRespected(): void
    {
        $this->pdo->exec("
            CREATE TABLE items (
                id INT PRIMARY KEY AUTO_INCREMENT,
                value INT NOT NULL
            )
        ");

        // Insert 500 rows
        $values = [];
        for ($i = 1; $i <= 500; $i++) {
            $values[] = "({$i})";
        }
        $this->pdo->exec("INSERT INTO items (value) VALUES " . implode(',', $values));

        // Export with batch size of 100
        $sql = $this->getDumpSQL(['batch_size' => 100]);

        // Count semicolons in INSERT statements (one per batch)
        // Each batch ends with ); so we should have 5 batches
        $batchCount = substr_count($sql, ');');
        $this->assertEquals(5, $batchCount, 'Should have 5 batches (500 rows / 100 per batch)');

        // Verify round-trip - all 500 rows should be imported
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $count = $importPdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
        $this->assertEquals(500, $count, 'All 500 rows should be imported');

        // Verify data integrity
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['items']);
    }

    public function testVariousDataTypes(): void
    {
        $this->pdo->exec("
            CREATE TABLE data_types (
                id INT PRIMARY KEY AUTO_INCREMENT,
                tiny_int TINYINT,
                small_int SMALLINT,
                medium_int MEDIUMINT,
                big_int BIGINT,
                a_float FLOAT,
                a_double DOUBLE,
                a_decimal DECIMAL(10,2),
                a_char CHAR(10),
                a_varchar VARCHAR(100),
                a_text TEXT,
                a_date DATE,
                a_time TIME,
                a_datetime DATETIME,
                a_timestamp TIMESTAMP,
                a_year YEAR
            )
        ");

        $this->pdo->exec("
            INSERT INTO data_types (
                tiny_int, small_int, medium_int, big_int,
                a_float, a_double, a_decimal,
                a_char, a_varchar, a_text,
                a_date, a_time, a_datetime, a_timestamp, a_year
            ) VALUES (
                127, 32767, 8388607, 9223372036854775807,
                3.14, 2.718281828, 12345.67,
                'CHAR', 'VARCHAR text', 'Long text content',
                '2024-01-15', '14:30:00', '2024-01-15 14:30:00', '2024-01-15 14:30:00', 2024
            )
        ");

        $sql = $this->getDumpSQL();

        // Verify numeric types are not quoted (allow optional spaces after commas)
        $this->assertMatchesRegularExpression('/\(1,\s*127,\s*32767,\s*8388607,\s*9223372036854775807/', $sql);

        // Verify decimal precision is preserved
        $this->assertSQLContains('12345.67', $sql);

        // Verify strings are quoted
        $this->assertSQLContains("'CHAR'", $sql);
        $this->assertSQLContains("'VARCHAR text'", $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['data_types']);
    }

    public function testNullValues(): void
    {
        $this->pdo->exec("
            CREATE TABLE nullables (
                id INT PRIMARY KEY AUTO_INCREMENT,
                nullable_int INT NULL,
                nullable_varchar VARCHAR(100) NULL,
                nullable_date DATE NULL
            )
        ");

        $this->pdo->exec("
            INSERT INTO nullables (nullable_int, nullable_varchar, nullable_date) VALUES
            (NULL, NULL, NULL),
            (42, 'text', '2024-01-15'),
            (NULL, 'only text', NULL)
        ");

        $sql = $this->getDumpSQL();

        // Verify NULL is not quoted
        $this->assertMatchesRegularExpression('/\(1,NULL,NULL,NULL\)/', $sql);
        $this->assertMatchesRegularExpression('/\(2,42,\'text\',\'2024-01-15\'\)/', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['nullables']);
    }

    public function testCreateTableDisabled(): void
    {
        $this->pdo->exec("
            CREATE TABLE simple (
                id INT PRIMARY KEY,
                name VARCHAR(50)
            )
        ");

        $this->pdo->exec("INSERT INTO simple VALUES (1, 'test')");

        $sql = $this->getDumpSQL(['create_table_query' => false]);

        $this->assertSQLNotContains('CREATE TABLE', $sql);
        $this->assertSQLContains('INSERT INTO', $sql);
        $this->assertSQLContains('Dumping data for table', $sql);
    }
}
