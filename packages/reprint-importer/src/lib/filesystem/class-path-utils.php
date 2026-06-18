<?php

namespace Reprint\Importer\Filesystem;

final class PathUtils
{
    /**
     * Clean a path value from preflight data: trim, strip trailing slash.
     * Returns null if the value is not a non-empty string.
     */
    public static function clean_path_value($value): ?string
    {
        if (!is_string($value) || trim($value) === "") {
            return null;
        }
        return rtrim($value, "/");
    }

    /**
     * Compute a relative path from $from to $to.
     *
     * Both paths must be absolute. Returns a relative path such that
     * a symlink at $from/$name pointing to the result will resolve to $to.
     *
     * Example: relative_path('/a/b/c', '/a/d/e') => '../../d/e'
     */
    public static function relative_path(string $from, string $to): string
    {
        $from_parts = explode("/", trim($from, "/"));
        $to_parts = explode("/", trim($to, "/"));

        $common = 0;
        $max = min(count($from_parts), count($to_parts));
        while ($common < $max && $from_parts[$common] === $to_parts[$common]) {
            $common++;
        }

        $up = count($from_parts) - $common;
        $down = array_slice($to_parts, $common);

        $parts = array_merge(array_fill(0, $up, ".."), $down);
        return implode("/", $parts) ?: ".";
    }
}
