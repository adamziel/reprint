<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

/**
 * Verify that progress line output never exceeds terminal width.
 *
 * Line wrapping in a terminal causes \r\033[K to only clear the last
 * physical line, leaving ghost text above. This test ensures the
 * truncation logic correctly limits all output to the terminal width.
 */
class ProgressLineWidthTest extends TestCase
{
    /**
     * Create a testable ImportClient subclass that exposes the
     * truncation method and captures progress output.
     */
    private function createTestClient(int $terminal_width = 80): object
    {
        $tempDir = sys_get_temp_dir() . '/progress-width-test-' . uniqid();
        mkdir($tempDir . '/state', 0755, true);
        mkdir($tempDir . '/fs', 0755, true);

        // Use an anonymous class to access protected/private methods
        $client = new class('http://example.com', $tempDir . '/state', $tempDir . '/fs', $terminal_width) extends \ImportClient {
            private int $forced_width;
            public string $captured_output = '';

            public function __construct(string $url, string $state, string $fs, int $width)
            {
                parent::__construct($url, $state, $fs);
                $this->forced_width = $width;
            }

            // Override terminal width to a controlled value
            protected function get_terminal_width_override(): ?int
            {
                return $this->forced_width;
            }

            // Expose truncate_for_terminal for direct testing
            public function testTruncate(string $msg): string
            {
                return $this->truncate_for_terminal($msg);
            }

            // Expose display_width for direct testing
            public function testDisplayWidth(string $msg): int
            {
                return $this->display_width($msg);
            }
        };

        return $client;
    }

    private function displayWidth(string $s): int
    {
        $stripped = preg_replace('/\033\[[0-9;]*m/', '', $s);
        return function_exists('mb_strwidth')
            ? mb_strwidth($stripped, 'UTF-8')
            : strlen($stripped);
    }

    public function testPlainTextTruncation(): void
    {
        $client = $this->createTestClient(40);
        $long = str_repeat('a', 60);
        $result = $client->testTruncate($long);
        $this->assertLessThanOrEqual(40, $this->displayWidth($result));
        $this->assertStringEndsWith('...', $result);
    }

    public function testShortTextUnchanged(): void
    {
        $client = $this->createTestClient(80);
        $short = 'Hello world';
        $result = $client->testTruncate($short);
        $this->assertEquals($short, $result);
    }

    public function testAnsiCodesPreservedInTruncation(): void
    {
        $client = $this->createTestClient(30);
        // "  \033[36m⠋\033[0m Hello world more text here blah"
        $msg = "  \033[36m⠋\033[0m Hello world more text here blah blah blah";
        $result = $client->testTruncate($msg);
        $this->assertLessThanOrEqual(30, $this->displayWidth($result));
        // Should contain at least some ANSI codes (not stripped)
        $this->assertStringContainsString("\033[", $result);
    }

    public function testProgressBarTruncation(): void
    {
        $client = $this->createTestClient(60);
        // Simulate a progress bar with Unicode bar chars + ANSI codes
        $bar = str_repeat("━", 10) . str_repeat("░", 10);
        $msg = "  \033[36m{$bar}\033[0m  Downloading — 1,234 / 43,378 files \033[2m28%\033[0m";
        $result = $client->testTruncate($msg);
        $this->assertLessThanOrEqual(60, $this->displayWidth($result),
            "Progress bar line exceeds terminal width. Display width: " .
            $this->displayWidth($result) . ", max: 60. Output: " . $result);
    }

    public function testUnicodeSpinnerWidth(): void
    {
        $client = $this->createTestClient(80);
        // Each Braille char is 1 display column
        $this->assertEquals(1, $client->testDisplayWidth("⠋"));
        $this->assertEquals(1, $client->testDisplayWidth("━"));
        $this->assertEquals(1, $client->testDisplayWidth("░"));
    }

    public function testAnsiCodesHaveZeroWidth(): void
    {
        $client = $this->createTestClient(80);
        $this->assertEquals(0, $client->testDisplayWidth("\033[36m"));
        $this->assertEquals(0, $client->testDisplayWidth("\033[0m"));
        $this->assertEquals(5, $client->testDisplayWidth("\033[36mhello\033[0m"));
    }

    public function testNarrowTerminal(): void
    {
        $client = $this->createTestClient(20);
        $msg = "  \033[36m⠋\033[0m Scanning remote files — 376,000 scanned";
        $result = $client->testTruncate($msg);
        $width = $this->displayWidth($result);
        $this->assertLessThanOrEqual(20, $width,
            "Output exceeds 20-col terminal. Width: {$width}. Output: {$result}");
    }

    public function testExactWidthNotTruncated(): void
    {
        $client = $this->createTestClient(40);
        $msg = str_repeat('x', 40);
        $result = $client->testTruncate($msg);
        $this->assertEquals($msg, $result);
    }

    public function testUtf8NotSplitMidCharacter(): void
    {
        $client = $this->createTestClient(10);
        // String of 3-byte UTF-8 chars that would be split if using substr
        $msg = str_repeat("━", 15); // 15 display cols, 45 bytes
        $result = $client->testTruncate($msg);
        // Verify the result is valid UTF-8
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'),
            "Truncated string is not valid UTF-8");
        $this->assertLessThanOrEqual(10, $this->displayWidth($result));
    }
}
