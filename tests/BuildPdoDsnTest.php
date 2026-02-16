<?php

declare(strict_types=1);

require_once __DIR__ . '/../wordpress-plugin/generic/utils.php';

use PHPUnit\Framework\TestCase;

class BuildPdoDsnTest extends TestCase
{
    public function testPlainHostname(): void
    {
        $dsn = build_pdo_dsn('localhost', 'wp_db');
        $this->assertSame('mysql:host=localhost;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testHostnameWithPort(): void
    {
        $dsn = build_pdo_dsn('db.host.com:3307', 'wp_db');
        $this->assertSame('mysql:host=db.host.com;port=3307;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testHostnameWithSocket(): void
    {
        $dsn = build_pdo_dsn('localhost:/var/run/mysqld/mysqld.sock', 'wp_db');
        $this->assertSame('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testBareSocketPath(): void
    {
        $dsn = build_pdo_dsn('/var/run/mysqld/mysqld.sock', 'wp_db');
        $this->assertSame('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testIpAddressWithPort(): void
    {
        $dsn = build_pdo_dsn('127.0.0.1:3306', 'mydb');
        $this->assertSame('mysql:host=127.0.0.1;port=3306;dbname=mydb;charset=utf8mb4', $dsn);
    }

    public function testIpAddressOnly(): void
    {
        $dsn = build_pdo_dsn('127.0.0.1', 'mydb');
        $this->assertSame('mysql:host=127.0.0.1;dbname=mydb;charset=utf8mb4', $dsn);
    }

    public function testBareIpv6(): void
    {
        $dsn = build_pdo_dsn('::1', 'wp_db');
        $this->assertSame('mysql:host=::1;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testBracketedIpv6(): void
    {
        $dsn = build_pdo_dsn('[::1]', 'wp_db');
        $this->assertSame('mysql:host=::1;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testBracketedIpv6WithPort(): void
    {
        $dsn = build_pdo_dsn('[::1]:3306', 'wp_db');
        $this->assertSame('mysql:host=::1;port=3306;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testBracketedIpv6WithSocket(): void
    {
        $dsn = build_pdo_dsn('[::1]:/var/run/mysqld/mysqld.sock', 'wp_db');
        $this->assertSame('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testFullIpv6Address(): void
    {
        $dsn = build_pdo_dsn('2001:db8::1', 'wp_db');
        $this->assertSame('mysql:host=2001:db8::1;dbname=wp_db;charset=utf8mb4', $dsn);
    }
}
