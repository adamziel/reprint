<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Reprint\Exporter\WpdbDriverPDO;

require_once __DIR__ . '/../packages/reprint-exporter/src/class-wpdb-driver-pdo.php';

final class WpdbDriverPDOTest extends TestCase
{
    public function testConstructorDoesNotSuppressWpdbErrors(): void
    {
        $wpdb = new WpdbDriverPDOTestDouble();

        new WpdbDriverPDO($wpdb);

        $this->assertFalse($wpdb->errors_suppressed);
        $this->assertFalse($wpdb->errors_hidden);
    }

    public function testSuppressErrorsIsExplicit(): void
    {
        $wpdb = new WpdbDriverPDOTestDouble();
        $adapter = new WpdbDriverPDO($wpdb);

        $adapter->suppress_errors();

        $this->assertTrue($wpdb->errors_suppressed);
        $this->assertTrue($wpdb->errors_hidden);
    }
}

final class WpdbDriverPDOTestDouble
{
    public bool $errors_suppressed = false;
    public bool $errors_hidden = false;
    public string $last_error = '';

    public function suppress_errors(bool $suppress = true): void
    {
        $this->errors_suppressed = $suppress;
    }

    public function hide_errors(): void
    {
        $this->errors_hidden = true;
    }

    public function _real_escape(string $value): string
    {
        return addslashes($value);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_results(string $sql, string $output): array
    {
        return [];
    }
}
