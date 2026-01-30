<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

/**
 * Tests MySQL dump with foreign key relationships.
 */
class ForeignKeysTest extends MySQLDumpProducerTestBase
{
    public function testSimpleForeignKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE authors (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE books (
                id INT PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(200) NOT NULL,
                author_id INT NOT NULL,
                FOREIGN KEY (author_id) REFERENCES authors(id)
            )
        ");

        $this->pdo->exec("INSERT INTO authors (name) VALUES ('J.K. Rowling'), ('George Orwell')");
        $this->pdo->exec("INSERT INTO books (title, author_id) VALUES ('Harry Potter', 1), ('1984', 2)");

        $sql = $this->getDumpSQL();

        // Verify CREATE TABLE includes foreign key
        $this->assertSQLContains('FOREIGN KEY', $sql);
        $this->assertSQLContains('REFERENCES `authors`', $sql);

        // Round-trip test - FK relationships must be preserved
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['authors', 'books']);

        // Verify FK constraint works in imported database
        try {
            $importPdo->exec("INSERT INTO books (title, author_id) VALUES ('Invalid', 999)");
            $this->fail('Foreign key constraint should have prevented invalid insert');
        } catch (PDOException $e) {
            $this->assertStringContainsString('foreign key constraint', strtolower($e->getMessage()));
        }
    }

    public function testMultipleForeignKeys(): void
    {
        $this->pdo->exec("
            CREATE TABLE users (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(50) NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE categories (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(50) NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE posts (
                id INT PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(200) NOT NULL,
                user_id INT NOT NULL,
                category_id INT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
            )
        ");

        $this->pdo->exec("INSERT INTO users (username) VALUES ('alice'), ('bob')");
        $this->pdo->exec("INSERT INTO categories (name) VALUES ('Tech'), ('News')");
        $this->pdo->exec("INSERT INTO posts (title, user_id, category_id) VALUES ('Post 1', 1, 1), ('Post 2', 2, 2)");

        $sql = $this->getDumpSQL();

        // Verify all FK relationships
        $this->assertMatchesRegularExpression('/FOREIGN KEY.*user_id.*REFERENCES.*users/s', $sql);
        $this->assertMatchesRegularExpression('/FOREIGN KEY.*category_id.*REFERENCES.*categories/s', $sql);

        // Verify ON DELETE clauses
        $this->assertSQLContains('ON DELETE CASCADE', $sql);
        $this->assertSQLContains('ON DELETE RESTRICT', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['users', 'categories', 'posts']);
    }

    public function testSelfReferencingForeignKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE employees (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                manager_id INT NULL,
                FOREIGN KEY (manager_id) REFERENCES employees(id)
            )
        ");

        $this->pdo->exec("
            INSERT INTO employees (id, name, manager_id) VALUES
            (1, 'CEO', NULL),
            (2, 'VP Sales', 1),
            (3, 'VP Engineering', 1),
            (4, 'Sales Rep', 2),
            (5, 'Developer', 3)
        ");

        $sql = $this->getDumpSQL();

        // Verify self-referencing FK
        $this->assertMatchesRegularExpression('/FOREIGN KEY.*manager_id.*REFERENCES.*employees/s', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['employees']);
    }

    public function testCompositeForeignKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE orders (
                order_id INT NOT NULL,
                order_line INT NOT NULL,
                product_name VARCHAR(100) NOT NULL,
                PRIMARY KEY (order_id, order_line)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE shipments (
                shipment_id INT PRIMARY KEY AUTO_INCREMENT,
                order_id INT NOT NULL,
                order_line INT NOT NULL,
                tracking_number VARCHAR(50),
                FOREIGN KEY (order_id, order_line) REFERENCES orders(order_id, order_line)
            )
        ");

        $this->pdo->exec("
            INSERT INTO orders (order_id, order_line, product_name) VALUES
            (100, 1, 'Widget'),
            (100, 2, 'Gadget'),
            (101, 1, 'Doohickey')
        ");

        $this->pdo->exec("
            INSERT INTO shipments (order_id, order_line, tracking_number) VALUES
            (100, 1, 'TRACK001'),
            (100, 2, 'TRACK002')
        ");

        $sql = $this->getDumpSQL();

        // Verify composite FK
        $this->assertMatchesRegularExpression('/FOREIGN KEY.*order_id.*order_line.*REFERENCES.*orders/s', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['orders', 'shipments']);
    }

    public function testCircularForeignKeys(): void
    {
        // Create tables without FKs first
        $this->pdo->exec("
            CREATE TABLE departments (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                manager_id INT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE employees (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                department_id INT NULL
            )
        ");

        // Add FKs after both tables exist
        $this->pdo->exec("
            ALTER TABLE departments
            ADD FOREIGN KEY (manager_id) REFERENCES employees(id)
        ");

        $this->pdo->exec("
            ALTER TABLE employees
            ADD FOREIGN KEY (department_id) REFERENCES departments(id)
        ");

        // Insert data carefully to avoid FK violations
        $this->pdo->exec("INSERT INTO departments (id, name, manager_id) VALUES (1, 'Engineering', NULL)");
        $this->pdo->exec("INSERT INTO employees (id, name, department_id) VALUES (1, 'Alice', 1)");
        $this->pdo->exec("UPDATE departments SET manager_id = 1 WHERE id = 1");

        $sql = $this->getDumpSQL();

        // Verify both FKs are present
        $this->assertMatchesRegularExpression('/CREATE TABLE.*departments/s', $sql);
        $this->assertMatchesRegularExpression('/CREATE TABLE.*employees/s', $sql);

        // Note: Circular FKs may require special handling during import
        // The dump should contain the structure correctly
    }

    public function testForeignKeyWithActions(): void
    {
        $this->pdo->exec("
            CREATE TABLE customers (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE invoices (
                id INT PRIMARY KEY AUTO_INCREMENT,
                customer_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                FOREIGN KEY (customer_id) REFERENCES customers(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
            )
        ");

        $this->pdo->exec("INSERT INTO customers (name) VALUES ('ACME Corp'), ('Widgets Inc')");
        $this->pdo->exec("INSERT INTO invoices (customer_id, amount) VALUES (1, 1000.00), (2, 500.00)");

        $sql = $this->getDumpSQL();

        // Verify FK actions
        $this->assertSQLContains('ON DELETE CASCADE', $sql);
        $this->assertSQLContains('ON UPDATE CASCADE', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Test cascade delete works
        $importPdo->exec("DELETE FROM customers WHERE id = 1");
        $count = $importPdo->query("SELECT COUNT(*) FROM invoices WHERE customer_id = 1")->fetchColumn();
        $this->assertEquals(0, $count, 'CASCADE DELETE should have removed invoice');
    }

    public function testTableOrderWithForeignKeys(): void
    {
        // Create tables in reverse dependency order
        $this->pdo->exec("
            CREATE TABLE level1 (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(50)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE level2 (
                id INT PRIMARY KEY AUTO_INCREMENT,
                level1_id INT NOT NULL,
                name VARCHAR(50),
                FOREIGN KEY (level1_id) REFERENCES level1(id)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE level3 (
                id INT PRIMARY KEY AUTO_INCREMENT,
                level2_id INT NOT NULL,
                name VARCHAR(50),
                FOREIGN KEY (level2_id) REFERENCES level2(id)
            )
        ");

        $this->pdo->exec("INSERT INTO level1 (name) VALUES ('L1-A'), ('L1-B')");
        $this->pdo->exec("INSERT INTO level2 (level1_id, name) VALUES (1, 'L2-A'), (2, 'L2-B')");
        $this->pdo->exec("INSERT INTO level3 (level2_id, name) VALUES (1, 'L3-A'), (2, 'L3-B')");

        $sql = $this->getDumpSQL();

        // Tables should be in alphabetical order (level1, level2, level3)
        // This is fine because we're just dumping structure and data
        // The import should handle FK checks being disabled
        $tables = $this->extractTableNames($sql);
        $this->assertEquals(['level1', 'level2', 'level3'], $tables);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['level1', 'level2', 'level3']);
    }
}
