<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

/**
 * --only reuses the existing `value-or-next` option type (like --new-site-url),
 * but is repeatable because commas are valid path bytes. The parser lives
 * inside the CLI bootstrap guard (not require-able), so this exercises the real
 * binary and asserts `--only` is recognized in both `--only=VAL` and
 * `--only VAL` forms.
 */
class OnlyCliParseTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/only-cli-' . uniqid();
        mkdir($this->tempDir . '/state', 0755, true);
        mkdir($this->tempDir . '/fs', 0755, true);
    }

    protected function tearDown(): void
    {
        // The real CLI run may drop state files (.import-state.json, audit log)
        // into the state dir, so a plain rmdir wouldn't clear it — recurse.
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            (is_dir($path) && !is_link($path)) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function runCli(array $args): string
    {
        $entry = __DIR__ . '/../../importer/import.php';
        $cmd = 'php ' . escapeshellarg($entry);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        return shell_exec($cmd . ' 2>&1') ?? '';
    }

    private function writePreflightState(): void
    {
        file_put_contents($this->tempDir . '/state/.import-state.json', json_encode(array(
            'preflight' => array(
                'data' => array(
                    'ok' => true,
                    'database' => array(
                        'wp' => array(
                            'paths_urls' => array(
                                'content_dir' => '/var/www/html/wp-content',
                                'uploads' => array('basedir' => '/var/www/html/wp-content/uploads'),
                            ),
                        ),
                    ),
                ),
                'http_code' => 200,
            ),
        ), JSON_PRETTY_PRINT));
    }

    public function testOnlyOptionIsRecognizedAsRepeatableInBothForms(): void
    {
        $tail = array(
            '--state-dir=' . $this->tempDir . '/state',
            '--fs-root=' . $this->tempDir . '/fs',
            '--secret=x',
        );
        // Both forms fail later (unreachable host) but must not be rejected as
        // an unknown option.
        $equals = $this->runCli(array_merge(
            array('files-pull', 'http://fake.invalid/?site-export-api', '--only=:wp-content:', '--only=:wp-uploads:/2025'),
            $tail
        ));
        $this->assertStringNotContainsString('Unknown option', $equals);

        $space = $this->runCli(array_merge(
            array('files-pull', 'http://fake.invalid/?site-export-api', '--only', ':wp-content:', '--only', ':wp-uploads:/2025'),
            $tail
        ));
        $this->assertStringNotContainsString('Unknown option', $space);
    }

    public function testOnlyOptionKeepsCommaInsideSourcePath(): void
    {
        // --abort runs after --only resolution, avoiding a network request while
        // still proving the CLI did not split the SOURCE at the comma.
        $this->writePreflightState();

        $output = $this->runCli(array(
            'files-pull',
            'http://fake.invalid/?site-export-api',
            '--only',
            ':wp-content:/plugins,custom',
            '--abort',
            '--state-dir=' . $this->tempDir . '/state',
            '--fs-root=' . $this->tempDir . '/fs',
        ));

        $this->assertStringContainsString('"status":"aborted"', $output);
        $this->assertStringNotContainsString('path "custom"', $output);
    }
}
