<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Coverage for the mu-plugin fast-path install/uninstall flow.
 *
 * Two files are under test:
 *
 *   reprint-exporter-wp/mu-plugin/install.php
 *       Activation/deactivation logic. Tries symlink first, falls back to
 *       copy. Records outcome in REPRINT_MU_PLUGIN_STATUS_OPTION. Refuses
 *       to overwrite a foreign file at the install target.
 *
 *   reprint-exporter-wp/mu-plugin/0-reprint-exporter.php
 *       The mu-plugin loader itself. Intercepts API requests and exits;
 *       returns silently for non-API requests.
 *
 * Each test that needs WP-side functions (update_option, get_option,
 * delete_option, time, wp_mkdir_p) runs in a subprocess that:
 *   1. defines WP_CONTENT_DIR, WPMU_PLUGIN_DIR pointing at a temp dir
 *   2. populates a fake plugin tree at WP_PLUGIN_DIR
 *   3. stubs the WP option functions against a process-local store
 *   4. requires the file under test
 *   5. emits the resulting filesystem + option state as JSON on stdout
 *
 * Subprocess isolation matters because:
 *   - the loader file calls exit() on intercepted requests
 *   - the install function calls error_get_last() / native PHP errors
 *     that we want to inspect without contaminating the parent process
 *   - we test failure cases that mutate the FS in ways the parent
 *     shouldn't see (chmod 555 on a temp dir, etc.)
 */
final class MuPluginInstallTest extends TestCase
{
    private string $tempDir;
    private string $pluginDir;
    private string $muPluginsDir;

    /** Source loader file inside the simulated plugin directory. */
    private string $simulatedSourcePath;

    /** Real source loader (in this repo). */
    private string $realSourcePath;

    /** Copy of install.php inside the simulated plugin tree (required so
     *  __DIR__ in install.php resolves to the simulated path, which makes
     *  reprint_mu_plugin_source_path() point at our test fixture). */
    private string $installModulePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/reprint-mu-install-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->pluginDir    = $this->tempDir . '/plugins/reprint-exporter-wp';
        $this->muPluginsDir = $this->tempDir . '/mu-plugins';
        mkdir($this->pluginDir . '/mu-plugin', 0755, true);
        mkdir($this->muPluginsDir, 0755, true);

        // Copy install.php and the loader file into the simulated
        // plugin tree. install.php uses __DIR__-relative path resolution
        // to find the loader, so requiring it from the simulated tree
        // makes reprint_mu_plugin_source_path() point at the simulated
        // loader — not at the real file in this repo.
        $this->realSourcePath      = dirname(__DIR__) . '/reprint-exporter-wp/mu-plugin/0-reprint-exporter.php';
        $realInstallPath           = dirname(__DIR__) . '/reprint-exporter-wp/mu-plugin/install.php';
        $this->simulatedSourcePath = $this->pluginDir . '/mu-plugin/0-reprint-exporter.php';
        $this->installModulePath   = $this->pluginDir . '/mu-plugin/install.php';
        copy($this->realSourcePath, $this->simulatedSourcePath);
        copy($realInstallPath,     $this->installModulePath);
    }

    protected function tearDown(): void
    {
        // chmod everything back to writable in case a test left a
        // permission-denied state behind.
        @chmod($this->muPluginsDir, 0755);
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    // ==================================================================
    // install()
    // ==================================================================

    public function testInstallCreatesSymlinkWhenWritable(): void
    {
        $r = $this->runInstall();

        $target = $this->muPluginsDir . '/0-reprint-exporter.php';
        $this->assertTrue(is_link($target), 'expected a symlink at install target');
        $this->assertSame(realpath($this->simulatedSourcePath), realpath($target));

        $this->assertSame('active', $r['status']['kind']);
        $this->assertSame('symlink', $r['status']['install_method']);
        $this->assertSame($target, $r['status']['target_path']);
    }

    /**
     * PHP doesn't allow redeclaring built-in functions, and there is no
     * `-d disable_functions=symlink` equivalent for CLI scripts — so we
     * can't easily simulate "symlink() unavailable" in a subprocess.
     * Instead, exercise the copy path by reading the install function's
     * branch directly: confirm that copy() of the source to the target
     * produces a file recognized as ours. This guards the copy code
     * path without trying to mock built-ins.
     */
    public function testCopyPathProducesOurFile(): void
    {
        $target = $this->muPluginsDir . '/0-reprint-exporter.php';
        $this->assertTrue(copy($this->simulatedSourcePath, $target));
        $r = $this->runHelper('reprint_mu_plugin_file_is_ours', $target);
        $this->assertTrue($r['result'], 'a freshly copied loader file must be recognized as ours');
        $this->assertStringContainsString(
            '@reprint-mu-plugin-loader',
            file_get_contents($target)
        );
    }

    public function testInstallRecordsFailureWhenBothSymlinkAndCopyFail(): void
    {
        // Make the destination directory read-only — neither symlink()
        // nor copy() can write into it.
        chmod($this->muPluginsDir, 0555);
        $r = $this->runInstall();

        $this->assertSame('install-failed', $r['status']['kind']);
        $this->assertNull($r['status']['install_method']);
        $this->assertNotSame('', $r['status']['message']);
    }

    public function testInstallFailsWhenSourceMissing(): void
    {
        unlink($this->simulatedSourcePath);
        $r = $this->runInstall();

        $this->assertSame('install-failed', $r['status']['kind']);
        $this->assertStringContainsString('Source file is missing', $r['status']['message']);
    }

    public function testInstallCreatesMuPluginsDirIfMissing(): void
    {
        rmdir($this->muPluginsDir);
        $this->assertDirectoryDoesNotExist($this->muPluginsDir);

        $r = $this->runInstall();

        $this->assertDirectoryExists($this->muPluginsDir);
        $this->assertSame('active', $r['status']['kind']);
    }

    public function testInstallFailsWhenWpmuPluginDirUndefined(): void
    {
        $r = $this->runInstall(defineWpmuPluginDir: false, defineWpContentDir: false);

        $this->assertSame('install-failed', $r['status']['kind']);
        $this->assertStringContainsString('WPMU_PLUGIN_DIR is not defined', $r['status']['message']);
    }

    public function testInstallRefusesToOverwriteForeignFile(): void
    {
        $target = $this->muPluginsDir . '/0-reprint-exporter.php';
        file_put_contents($target, "<?php\n// Some other plugin's content — no marker.\n");

        $r = $this->runInstall();

        $this->assertSame('foreign', $r['status']['kind']);
        $this->assertStringContainsString(
            "// Some other plugin's content",
            file_get_contents($target),
            'foreign file must remain untouched'
        );
    }

    public function testInstallReplacesPreviouslyInstalledFile(): void
    {
        // Pre-existing file with our marker (e.g., earlier plugin version
        // installed as a copy). Should be cleanly replaced with a symlink.
        $target = $this->muPluginsDir . '/0-reprint-exporter.php';
        file_put_contents(
            $target,
            "<?php\n/* @reprint-mu-plugin-loader stale-copy */\necho 'old';\n"
        );
        $this->assertTrue(is_file($target));
        $this->assertFalse(is_link($target));

        $r = $this->runInstall();

        $this->assertTrue(is_link($target), 'pre-existing OUR file should be replaced with a symlink');
        $this->assertSame('active', $r['status']['kind']);
    }

    // ==================================================================
    // uninstall()
    // ==================================================================

    public function testUninstallRemovesOurSymlink(): void
    {
        symlink($this->simulatedSourcePath, $this->muPluginsDir . '/0-reprint-exporter.php');
        $r = $this->runUninstall();

        $this->assertFileDoesNotExist($this->muPluginsDir . '/0-reprint-exporter.php');
        $this->assertSame('missing', $r['status']['kind']);
    }

    public function testUninstallRemovesOurCopy(): void
    {
        copy($this->realSourcePath, $this->muPluginsDir . '/0-reprint-exporter.php');
        $r = $this->runUninstall();

        $this->assertFileDoesNotExist($this->muPluginsDir . '/0-reprint-exporter.php');
        $this->assertSame('missing', $r['status']['kind']);
    }

    public function testUninstallLeavesForeignFile(): void
    {
        $target = $this->muPluginsDir . '/0-reprint-exporter.php';
        $contents = "<?php\n// Custom mu-plugin — must not be deleted on deactivation.\n";
        file_put_contents($target, $contents);

        $r = $this->runUninstall();

        $this->assertFileExists($target);
        $this->assertSame($contents, file_get_contents($target));
        $this->assertSame('foreign', $r['status']['kind']);
    }

    public function testUninstallNoOpsWhenNoFileExists(): void
    {
        $r = $this->runUninstall();
        $this->assertSame('missing', $r['status']['kind']);
    }

    public function testUninstallRecordsFailureWhenFileNotRemovable(): void
    {
        symlink($this->simulatedSourcePath, $this->muPluginsDir . '/0-reprint-exporter.php');
        chmod($this->muPluginsDir, 0555);
        $r = $this->runUninstall();
        chmod($this->muPluginsDir, 0755);

        $this->assertSame('uninstall-failed', $r['status']['kind']);
    }

    // ==================================================================
    // get_status()
    // ==================================================================

    public function testStatusReportsActiveAfterInstall(): void
    {
        $this->runInstall();
        $r = $this->runStatus();

        $this->assertSame('active', $r['status']['kind']);
        $this->assertSame('symlink', $r['status']['install_method']);
    }

    public function testStatusReflectsForeignFilePlantedAfterInstall(): void
    {
        // Run install, then mutate the FS to simulate someone replacing
        // the file. get_status should reflect the live state, not the
        // stale option value.
        $this->runInstall();
        $target = $this->muPluginsDir . '/0-reprint-exporter.php';
        unlink($target);
        file_put_contents($target, "<?php\n// some other content\n");

        $r = $this->runStatus();
        $this->assertSame('foreign', $r['status']['kind']);
    }

    public function testStatusReturnsMissingFromCleanSlate(): void
    {
        $r = $this->runStatus();
        $this->assertSame('missing', $r['status']['kind']);
    }

    public function testStatusReturnsMissingAfterUninstall(): void
    {
        $this->runInstall();
        $this->runUninstall();
        $r = $this->runStatus();
        $this->assertSame('missing', $r['status']['kind']);
    }

    // ==================================================================
    // file_is_ours()
    // ==================================================================

    public function testFileIsOursDetectsCopyOfRealSource(): void
    {
        copy($this->realSourcePath, $this->muPluginsDir . '/some.php');
        $r = $this->runHelper('reprint_mu_plugin_file_is_ours', $this->muPluginsDir . '/some.php');
        $this->assertTrue($r['result']);
    }

    public function testFileIsOursDetectsSymlinkToOurSource(): void
    {
        symlink($this->simulatedSourcePath, $this->muPluginsDir . '/sym.php');
        $r = $this->runHelper('reprint_mu_plugin_file_is_ours', $this->muPluginsDir . '/sym.php');
        $this->assertTrue($r['result']);
    }

    public function testFileIsOursReturnsFalseForUnrelatedFile(): void
    {
        file_put_contents($this->muPluginsDir . '/other.php', "<?php\n// not ours\n");
        $r = $this->runHelper('reprint_mu_plugin_file_is_ours', $this->muPluginsDir . '/other.php');
        $this->assertFalse($r['result']);
    }

    public function testFileIsOursReturnsNullForMissingFile(): void
    {
        $r = $this->runHelper('reprint_mu_plugin_file_is_ours', $this->muPluginsDir . '/nope.php');
        $this->assertNull($r['result']);
    }

    public function testFileIsOursDetectsDanglingSymlinkByMarker(): void
    {
        // Symlink pointing somewhere else, but the somewhere-else file
        // still has our marker. Counts as ours.
        $alt = $this->tempDir . '/elsewhere.php';
        copy($this->realSourcePath, $alt);
        symlink($alt, $this->muPluginsDir . '/sym.php');

        $r = $this->runHelper('reprint_mu_plugin_file_is_ours', $this->muPluginsDir . '/sym.php');
        $this->assertTrue($r['result']);
    }

    // ==================================================================
    // 0-reprint-exporter.php loader — intercept/no-op behaviour
    // ==================================================================

    public function testLoaderInterceptsReprintApiRequest(): void
    {
        $r = $this->runLoader(['reprint-api' => '1'], stubLibPrints: 'STUB_HANDLED');
        $this->assertStringContainsString('STUB_HANDLED', $r['stdout']);
        $this->assertStringNotContainsString('RETURNED_NORMALLY', $r['stdout']);
    }

    public function testLoaderInterceptsLegacySiteExportApi(): void
    {
        $r = $this->runLoader(['site-export-api' => '1'], stubLibPrints: 'STUB_HANDLED');
        $this->assertStringContainsString('STUB_HANDLED', $r['stdout']);
    }

    public function testLoaderInterceptsEmptyParamValue(): void
    {
        // isset() is true for empty-string values; some HTTP clients drop the '=value'.
        $r = $this->runLoader(['reprint-api' => ''], stubLibPrints: 'STUB_HANDLED');
        $this->assertStringContainsString('STUB_HANDLED', $r['stdout']);
    }

    public function testLoaderReturnsNormallyForNonApiRequest(): void
    {
        $r = $this->runLoader(['other' => '1'], stubLibPrints: 'STUB_HANDLED');
        $this->assertStringContainsString('RETURNED_NORMALLY', $r['stdout']);
        $this->assertStringNotContainsString('STUB_HANDLED', $r['stdout']);
    }

    public function testLoaderDoesNotRequireLibOnNonApiPath(): void
    {
        // If the loader required lib.php for every request, it'd add
        // measurable overhead to every page view. Verify by dropping a
        // lib.php that throws on load — if it loads, the test fails.
        file_put_contents(
            $this->pluginDir . '/lib.php',
            "<?php\nfwrite(STDERR, 'LIB_LOADED'); throw new RuntimeException('should not load');\n"
        );
        $r = $this->runLoader(['other' => '1']);
        $this->assertStringContainsString('RETURNED_NORMALLY', $r['stdout']);
        $this->assertStringNotContainsString('LIB_LOADED', $r['stderr']);
    }

    public function testLoaderReturnsSilentlyWhenLibMissing(): void
    {
        // Plugin not installed — loader must not fatal, just no-op.
        $r = $this->runLoader(['reprint-api' => '1'], includeLib: false);
        $this->assertStringContainsString('RETURNED_NORMALLY', $r['stdout']);
        $this->assertSame('', $r['stderr'], 'no PHP errors expected');
    }

    public function testLoaderReturnsSilentlyWhenWpContentDirUndefined(): void
    {
        $r = $this->runLoader(['reprint-api' => '1'], defineWpContentDir: false);
        $this->assertStringContainsString('RETURNED_NORMALLY', $r['stdout']);
        $this->assertSame('', $r['stderr']);
    }

    // ==================================================================
    // subprocess harness
    // ==================================================================

    /**
     * @return array{status: array, stdout: string, stderr: string, exitCode: int}
     */
    private function runInstall(bool $defineWpContentDir = true, bool $defineWpmuPluginDir = true): array
    {
        return $this->runInstallHarness('install', $defineWpContentDir, $defineWpmuPluginDir);
    }

    private function runUninstall(): array
    {
        return $this->runInstallHarness('uninstall', true, true);
    }

    private function runStatus(): array
    {
        return $this->runInstallHarness('status', true, true);
    }

    /**
     * Run reprint_mu_plugin_file_is_ours() in a subprocess (so $argv
     * gives us a clean path with no PHPUnit context).
     */
    private function runHelper(string $helper, string $arg): array
    {
        $script = $this->tempDir . '/helper.php';
        file_put_contents(
            $script,
            "<?php\n"
            . "define('WP_PLUGIN_DIR', " . var_export($this->tempDir . '/plugins', true) . ");\n"
            . "require " . var_export($this->installModulePath, true) . ";\n"
            . "echo json_encode(['result' => $helper(" . var_export($arg, true) . ")]);\n"
        );
        $out = $this->runSubprocess($script);
        $decoded = json_decode($out['stdout'], true);
        $out['result'] = $decoded['result'] ?? null;
        return $out;
    }

    /**
     * Run the install module against the test fixture, calling
     * install/uninstall/status. Returns the resulting status array
     * (decoded from JSON the subprocess writes to stdout).
     */
    private function runInstallHarness(string $action, bool $defineWpContentDir, bool $defineWpmuPluginDir): array
    {
        $script = $this->tempDir . '/install-' . $action . '.php';

        // We need a tiny option-store stub so update_option /
        // get_option / delete_option work without WordPress booted.
        $stubs = <<<'PHP'
$REPRINT_OPTIONS = [];
function update_option($key, $value, $autoload = false) {
    global $REPRINT_OPTIONS;
    $REPRINT_OPTIONS[$key] = $value;
    return true;
}
function get_option($key, $default = false) {
    global $REPRINT_OPTIONS;
    return $REPRINT_OPTIONS[$key] ?? $default;
}
function delete_option($key) {
    global $REPRINT_OPTIONS;
    unset($REPRINT_OPTIONS[$key]);
    return true;
}
PHP;

        $defines = '';
        if ($defineWpContentDir) {
            $defines .= sprintf("define('WP_CONTENT_DIR', %s);\n", var_export($this->tempDir, true));
        }
        if ($defineWpmuPluginDir) {
            $defines .= sprintf("define('WPMU_PLUGIN_DIR', %s);\n", var_export($this->muPluginsDir, true));
        }

        // For action-style invocations, return the action's OWN status
        // array. get_status() reads live filesystem state and would mask
        // a uninstall-failed case where the file is still on disk
        // because unlink failed.
        $action_code = match ($action) {
            'install'   => "\$status = reprint_install_mu_plugin();\n",
            'uninstall' => "\$status = reprint_uninstall_mu_plugin();\n",
            'status'    => "\$status = reprint_get_mu_plugin_status();\n",
            default     => throw new InvalidArgumentException($action),
        };

        file_put_contents(
            $script,
            "<?php\n"
            . $defines
            . $stubs . "\n"
            . "require " . var_export($this->installModulePath, true) . ";\n"
            . $action_code
            . "echo json_encode(['status' => \$status, 'options' => \$REPRINT_OPTIONS]);\n"
        );

        $out = $this->runSubprocess($script);
        $decoded = json_decode($out['stdout'], true);
        if (!is_array($decoded) || !isset($decoded['status'])) {
            $this->fail("Subprocess output not JSON-shaped:\nstdout: {$out['stdout']}\nstderr: {$out['stderr']}");
        }
        $out['status'] = $decoded['status'];
        return $out;
    }

    /**
     * Run the mu-plugin loader file in a subprocess.
     *
     * @param array<string,string> $get
     */
    private function runLoader(array $get, bool $defineWpContentDir = true, ?string $stubLibPrints = null, bool $includeLib = true): array
    {
        if ($includeLib && $stubLibPrints !== null) {
            file_put_contents(
                $this->pluginDir . '/lib.php',
                "<?php\nfunction _site_export_handle_api_request() { echo " . var_export($stubLibPrints, true) . "; }\n"
            );
        } elseif ($includeLib && !file_exists($this->pluginDir . '/lib.php')) {
            // Default stub for tests that don't supply one but expect
            // intercept to *not* fire (so handler isn't called and the
            // contents don't matter).
            file_put_contents($this->pluginDir . '/lib.php', "<?php\n");
        } elseif (!$includeLib) {
            @unlink($this->pluginDir . '/lib.php');
        }

        $script = $this->tempDir . '/loader.php';
        $defines = $defineWpContentDir
            ? sprintf("define('WP_CONTENT_DIR', %s);\n", var_export($this->tempDir, true))
            : '';
        file_put_contents(
            $script,
            "<?php\n"
            . $defines
            . sprintf("\$_GET = %s;\n", var_export($get, true))
            . "require " . var_export($this->realSourcePath, true) . ";\n"
            . "echo \"RETURNED_NORMALLY\\n\";\n"
        );

        return $this->runSubprocess($script);
    }

    /**
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runSubprocess(string $scriptPath): array
    {
        $cmd = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($scriptPath));
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        // The subprocess just mutated paths we may have stat()'d in the
        // parent before launching it. PHP caches stat info per-process,
        // so without a flush the parent's subsequent is_link()/file_exists()
        // calls would return stale answers and tests would fail
        // mysteriously.
        clearstatcache(true);
        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => $exit];
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir) && !is_link($dir)) {
            return;
        }
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($dir);
    }
}
