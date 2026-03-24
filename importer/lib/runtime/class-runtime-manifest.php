<?php
/**
 * Runtime manifest — the intermediate representation between host analyzers
 * and runtime appliers.
 *
 * A host analyzer (e.g. WpcloudHostAnalyzer) reads preflight data from the
 * source site and produces a RuntimeManifest describing what that site needs
 * to run. A runtime applier (e.g. NginxFpmApplier) reads the manifest and
 * writes the configuration files appropriate for the target server.
 *
 * The manifest is pure data — no executable code. It captures:
 *
 * - php_ini:     INI directives the source site had (memory_limit, etc.)
 * - constants:   PHP constants to define before WordPress boots
 *                (WP_CONTENT_DIR, etc.). Values may contain {docroot} as
 *                a placeholder resolved at apply-time.
 * - server_vars: $_SERVER entries to set before WordPress boots.
 *                Values may contain {docroot}.
 * - request_interceptors: PHP scripts that must run before WordPress on
 *                every HTTP request. Each entry names a script file
 *                (shipped alongside the manifest) and declares whether
 *                it may exit() early.
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
     * @var array<int, array{name: string, phase: string, may_exit: bool, script: string}>
     * Scripts to run before WordPress boots on every HTTP request.
     * "script" is a path relative to the manifest's directory.
     */
    public array $request_interceptors = [];

    public function __construct(string $source)
    {
        $this->source = $source;
    }

    /**
     * Serialize to a JSON-friendly array.
     */
    public function to_array(): array
    {
        return [
            'source' => $this->source,
            'php_ini' => $this->php_ini,
            'constants' => $this->constants,
            'server_vars' => $this->server_vars,
            'request_interceptors' => $this->request_interceptors,
        ];
    }

    /**
     * Reconstitute from a JSON-decoded array.
     */
    public static function from_array(array $data): self
    {
        $manifest = new self($data['source'] ?? 'unknown');
        $manifest->php_ini = $data['php_ini'] ?? [];
        $manifest->constants = $data['constants'] ?? [];
        $manifest->server_vars = $data['server_vars'] ?? [];
        $manifest->request_interceptors = $data['request_interceptors'] ?? [];
        return $manifest;
    }

    /**
     * Save the manifest as JSON to a file.
     */
    public function save(string $path): void
    {
        $json = json_encode($this->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("Failed to encode runtime manifest as JSON");
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $json . "\n");
    }

    /**
     * Load a manifest from a JSON file.
     */
    public static function load(string $path): self
    {
        if (!file_exists($path)) {
            throw new RuntimeException("Runtime manifest not found: {$path}");
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            throw new RuntimeException("Failed to decode runtime manifest: {$path}");
        }
        return self::from_array($data);
    }
}
