<?php
/**
 * Runtime manifest — the intermediate representation between host analyzers
 * and runtime appliers.
 *
 * A host analyzer reads preflight data from the source site and produces a
 * RuntimeManifest describing what that site needs to run. A runtime applier
 * reads the manifest and writes the configuration files appropriate for the
 * target server.
 *
 * The manifest is pure data — no executable code, no file paths to scripts.
 * It captures:
 *
 * - php_ini:      INI directives the source site had (memory_limit, etc.)
 * - constants:    PHP constants to define before WordPress boots.
 *                 Values may contain {docroot} resolved at apply-time.
 * - server_vars:  $_SERVER entries to set before WordPress boots.
 *                 Values may contain {docroot}.
 * - routes:       Declarative request routes the target runtime must
 *                 implement. Each describes a URL path pattern, a handler
 *                 name, and an optional condition (e.g. "file_not_found").
 *                 The target runtime decides how to implement the handler.
 */
class RuntimeManifest
{
    /** @var string Source host identifier (e.g. "wpcloud", "siteground") */
    public string $source;

    /** @var array<string, string> PHP INI directives */
    public array $php_ini = [];

    /** @var array<string, string> PHP constants to define (values may use {docroot}) */
    public array $constants = [];

    /** @var array<string, string> $_SERVER entries (values may use {docroot}) */
    public array $server_vars = [];

    /**
     * @var array<int, array{handler: string, path_pattern: string, condition?: string, description: string}>
     * Declarative request routes. Each entry describes a URL path pattern,
     * the handler to invoke, and an optional condition under which it fires.
     *
     * The handler name maps 1:1 to an implementation file in
     * target-runtime/route-handlers/ (e.g. "wpcloud-thumbnail-generator"
     * maps to wpcloud-thumbnail-generator.php).
     *
     * Example:
     *   [
     *     'handler' => 'wpcloud-thumbnail-generator',
     *     'path_pattern' => '/wp-content/uploads/.*-\d+x\d+\.\w+$',
     *     'condition' => 'file_not_found',
     *     'description' => 'Generate missing WordPress thumbnails from originals'
     *   ]
     */
    public array $routes = [];

    /**
     * Whether the manifest includes DB_* constants that will collide
     * with definitions in wp-config.php.  When true, the generated
     * runtime.php installs a lightweight error handler that silences
     * the "Constant already defined" warnings that occur when
     * wp-config.php tries to redefine the same constants.
     */
    public bool $has_db_constants = false;

    public function __construct(string $source)
    {
        $this->source = $source;
    }

}
