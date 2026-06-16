<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

/**
 * --only reuses the existing `value-or-next` option type (like --new-site-url),
 * taking a single comma-separated value. The parser lives inside the CLI
 * bootstrap guard (not require-able), so this exercises the real binary and
 * asserts `--only` is a recognized option in both `--only=VAL` and `--only VAL`
 * forms.
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

    public function testOnlyOptionIsRecognizedInBothForms(): void
    {
        $tail = array(
            '--state-dir=' . $this->tempDir . '/state',
            '--fs-root=' . $this->tempDir . '/fs',
            '--secret=x',
        );
        // Both forms fail later (unreachable host) but must not be rejected as
        // an unknown option.
        $equals = $this->runCli(array_merge(
            array('files-pull', 'http://fake.invalid/?site-export-api', '--only=:wp-content:,:wp-uploads:'),
            $tail
        ));
        $this->assertStringNotContainsString('Unknown option', $equals);

        $space = $this->runCli(array_merge(
            array('files-pull', 'http://fake.invalid/?site-export-api', '--only', ':wp-content:'),
            $tail
        ));
        $this->assertStringNotContainsString('Unknown option', $space);
    }
}
