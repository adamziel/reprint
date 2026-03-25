<?php
/**
 * Registry for runtime appliers.
 */
class RuntimeAppliers
{
    /**
     * Instantiate the right applier for a runtime name.
     *
     * @param string $runtime "nginx-fpm" or "php-builtin".
     * @return RuntimeApplier
     */
    public static function for_runtime(string $runtime): RuntimeApplier
    {
        switch ($runtime) {
            case 'nginx-fpm':
                return new NginxFpmApplier();
            case 'php-builtin':
                return new PhpBuiltinApplier();
            default:
                throw new InvalidArgumentException(
                    "Unknown runtime: {$runtime}. Valid runtimes: nginx-fpm, php-builtin"
                );
        }
    }
}
