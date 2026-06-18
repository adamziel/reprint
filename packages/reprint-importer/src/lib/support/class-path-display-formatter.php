<?php

namespace Reprint\Importer\Support;

final class PathDisplayFormatter
{
    public static function short_path(string $path, int $max = 60): string
    {
        $rel = ltrim($path, "/");
        if (strlen($rel) > $max) {
            $rel = "..." . substr($rel, -($max - 3));
        }

        return $rel;
    }
}
