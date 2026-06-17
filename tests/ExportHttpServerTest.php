<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../packages/reprint-exporter/src/class-resource-budget.php';
require_once __DIR__ . '/../packages/reprint-exporter/src/commands/load.php';
require_once __DIR__ . '/../packages/reprint-exporter/src/class-http-server.php';

final class ExportHttpServerTest extends TestCase
{
    public function testParsesJsonBodyAndCastsKnownTypes(): void
    {
        $server = new \Reprint\Exporter\Site_Export_HTTP_Server();
        $config = $server->parse_http_config(
            ['endpoint' => 'file_index'],
            [],
            ['CONTENT_TYPE' => 'application/json; charset=utf-8'],
            json_encode([
                'paths' => ['a', 'b'],
                'max_execution_time' => '7',
                'memory_threshold' => '0.7',
                'create_table_query' => 'true',
            ]) ?: ''
        );

        $this->assertSame('file_index', $config['endpoint']);
        $this->assertSame(['a', 'b'], $config['paths']);
        $this->assertSame(7, $config['max_execution_time']);
        $this->assertSame(0.7, $config['memory_threshold']);
        $this->assertTrue($config['create_table_query']);
    }

    public function testNormalizeConfigAppliesDefaultDirectoryAndDecodesCursorHeader(): void
    {
        $server = new \Reprint\Exporter\Site_Export_HTTP_Server([
            'default_directory' => '/srv/site',
        ]);
        $cursor = base64_encode(json_encode(['offset' => 10]) ?: '');

        $config = $server->normalize_config(
            ['endpoint' => 'file_index'],
            ['HTTP_X_EXPORT_CURSOR' => $cursor]
        );

        $this->assertSame('/srv/site', $config['directory']);
        $this->assertSame('{"offset":10}', $config['cursor']);
    }

    public function testNormalizeConfigAppliesDefaultDirectoryEvenWhenListDirPresent(): void
    {
        $server = new \Reprint\Exporter\Site_Export_HTTP_Server([
            'default_directory' => '/srv/site',
        ]);

        $config = $server->normalize_config(
            ['endpoint' => 'file_index', 'list_dir' => '/srv/site/wp-content'],
            []
        );

        $this->assertSame('/srv/site', $config['directory']);
        $this->assertSame('/srv/site/wp-content', $config['list_dir']);
    }

    public function testNormalizeConfigRejectsInvalidCursor(): void
    {
        $server = new \Reprint\Exporter\Site_Export_HTTP_Server();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cursor must be base64-encoded');

        $server->normalize_config(
            ['endpoint' => 'file_index'],
            ['HTTP_X_EXPORT_CURSOR' => '!!!not-base64!!!']
        );
    }

    public function testDispatchRoutesPreflightCommandWithoutBudget(): void
    {
        require_once __DIR__ . '/../packages/reprint-exporter/src/export.php';

        $server = new \Reprint\Exporter\Site_Export_HTTP_Server([
            'handlers' => [
                'preflight' => new \Reprint\Exporter\Command\PreflightCommand(),
            ],
            'budget_factory' => static function (): \Reprint\Exporter\ResourceBudget {
                throw new RuntimeException('Preflight should not create a resource budget.');
            },
        ]);

        ob_start();
        try {
            $server->dispatch([
                'endpoint' => 'preflight',
                'directory' => sys_get_temp_dir(),
            ]);
        } finally {
            ob_end_clean();
        }

        $this->addToAssertionCount(1);
    }

    public function testDispatchRoutesStreamingEndpointsWithCreatedBudget(): void
    {
        $calls = [];
        $server = new \Reprint\Exporter\Site_Export_HTTP_Server([
            'handlers' => [
                'file_index' => function (array $config, $budget) use (&$calls): void {
                    $calls[] = [$config, $budget];
                },
            ],
            'budget_factory' => static function (array $config): array {
                return ['from' => $config['endpoint']];
            },
        ]);

        $server->dispatch(['endpoint' => 'file_index']);

        $this->assertCount(1, $calls);
        $this->assertSame(['endpoint' => 'file_index'], $calls[0][0]);
        $this->assertSame(['from' => 'file_index'], $calls[0][1]);
    }

    public function testDispatchCreatesBudgetForCommandHandlers(): void
    {
        $budget = new \Reprint\Exporter\ResourceBudget(0.0, 1, PHP_INT_MAX, 0.8);
        $command = new class extends \Reprint\Exporter\Command\BudgetedExportCommand {
            /** @var array<int, array{0: array<string, mixed>, 1: \Reprint\Exporter\ResourceBudget}> */
            public $calls = [];

            public function execute(array $config, \Reprint\Exporter\ResourceBudget $budget): array
            {
                $this->calls[] = [$config, $budget];
                return ['ok' => true];
            }
        };

        $server = new \Reprint\Exporter\Site_Export_HTTP_Server([
            'handlers' => [
                'file_index' => $command,
            ],
            'budget_factory' => static function (array $config) use ($budget): \Reprint\Exporter\ResourceBudget {
                return $config['endpoint'] === 'file_index'
                    ? $budget
                    : new \Reprint\Exporter\ResourceBudget(0.0, 1, 1, 0.8);
            },
        ]);

        $server->dispatch(['endpoint' => 'file_index']);

        $this->assertCount(1, $command->calls);
        $this->assertSame(['endpoint' => 'file_index'], $command->calls[0][0]);
        $this->assertSame($budget, $command->calls[0][1]);
    }

    public function testDispatchRejectsUnknownEndpoints(): void
    {
        $server = new \Reprint\Exporter\Site_Export_HTTP_Server([
            'handlers' => [
                'preflight' => static function (): void {},
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid endpoint: 'sql_chunk'. Valid endpoints: 'preflight'");

        $server->dispatch(['endpoint' => 'sql_chunk']);
    }

    public function testHandleRequestUsesParsedConfigAndDispatches(): void
    {
        $budget = new \Reprint\Exporter\ResourceBudget(0.0, 1, PHP_INT_MAX, 0.8);
        $command = new class extends \Reprint\Exporter\Command\BudgetedExportCommand {
            /** @var array<int, array{0: array<string, mixed>, 1: \Reprint\Exporter\ResourceBudget}> */
            public $calls = [];

            public function execute(array $config, \Reprint\Exporter\ResourceBudget $budget): array
            {
                $this->calls[] = [$config, $budget];
                return ['ok' => true];
            }
        };
        $server = new \Reprint\Exporter\Site_Export_HTTP_Server([
            'handlers' => [
                'file_index' => $command,
            ],
            'budget_factory' => static function () use ($budget): \Reprint\Exporter\ResourceBudget {
                return $budget;
            },
        ]);

        $server->handle_request([
            'get' => ['endpoint' => 'file_index'],
            'post' => [],
            'server' => ['REQUEST_METHOD' => 'GET'],
            'body' => '',
        ]);

        $this->assertSame([['endpoint' => 'file_index'], $budget], $command->calls[0]);
    }
}
