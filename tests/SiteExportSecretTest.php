<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$site_export_test_plugin_dir = sys_get_temp_dir() . '/site-export-secret-test-' . getmypid() . '/';
if (!defined('SITE_EXPORT_PLUGIN_DIR')) {
    define('SITE_EXPORT_PLUGIN_DIR', $site_export_test_plugin_dir);
}
if (!defined('SITE_EXPORT_SECRET_FILE')) {
    define('SITE_EXPORT_SECRET_FILE', SITE_EXPORT_PLUGIN_DIR . 'secret.php');
}

$GLOBALS['site_export_test_options'] = [];
$GLOBALS['site_export_registered_settings'] = [];

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string {
        return SITE_EXPORT_PLUGIN_DIR;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, $default = false) {
        return array_key_exists($name, $GLOBALS['site_export_test_options'])
            ? $GLOBALS['site_export_test_options'][$name]
            : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null): bool {
        $GLOBALS['site_export_test_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('register_setting')) {
    function register_setting(string $group, string $name, array $args = []): void {
        $GLOBALS['site_export_registered_settings'][$name] = [
            'group' => $group,
            'args' => $args,
        ];
    }
}

if (!function_exists('add_action')) {
    function add_action(...$args): void {}
}

if (!function_exists('add_filter')) {
    function add_filter(...$args): void {}
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string {
        return basename($file);
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook(...$args): void {}
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool {
        return false;
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool {
        return true;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(...$args): void {}
}

if (!function_exists('get_transient')) {
    function get_transient(...$args): bool {
        return false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(...$args): void {}
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(...$args): void {}
}

require_once __DIR__ . '/../wordpress-plugin/lib.php';
require_once __DIR__ . '/../wordpress-plugin/wordpress/site-export.php';

final class SiteExportSecretTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(SITE_EXPORT_PLUGIN_DIR)) {
            mkdir(SITE_EXPORT_PLUGIN_DIR, 0755, true);
        }

        $GLOBALS['site_export_test_options'] = [];
        $GLOBALS['site_export_registered_settings'] = [];

        if (file_exists(SITE_EXPORT_SECRET_FILE)) {
            unlink(SITE_EXPORT_SECRET_FILE);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists(SITE_EXPORT_SECRET_FILE)) {
            unlink(SITE_EXPORT_SECRET_FILE);
        }

        if (is_dir(SITE_EXPORT_PLUGIN_DIR)) {
            rmdir(SITE_EXPORT_PLUGIN_DIR);
        }

        parent::tearDown();
    }

    public function testSharedSecretFallsBackToOptionWhenSecretFileMissing(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'option-secret';

        $this->assertSame('option-secret', _site_export_get_shared_secret());
    }

    public function testSecretFileOverridesSiteOptionWhenPresent(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'option-secret';
        file_put_contents(SITE_EXPORT_SECRET_FILE, "<?php return 'file-secret';\n");

        $this->assertSame('file-secret', _site_export_get_shared_secret());
    }

    public function testUpdatingSharedSecretOnlyTouchesTheSiteOption(): void
    {
        $this->assertTrue(_site_export_update_shared_secret('new-secret'));
        $this->assertSame('new-secret', $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION]);
        $this->assertFileDoesNotExist(SITE_EXPORT_SECRET_FILE);
    }

    public function testPluginRegistersSecretOptionForCoreSettingsRestEndpoint(): void
    {
        Site_Export_Plugin::get_instance()->register_settings();

        $setting = $GLOBALS['site_export_registered_settings'][SITE_EXPORT_SECRET_OPTION] ?? null;
        $this->assertNotNull($setting);
        $this->assertSame('general', $setting['group']);
        $this->assertTrue($setting['args']['show_in_rest']);
        $this->assertSame('string', $setting['args']['type']);
        $this->assertSame('', $setting['args']['default']);
    }
}
