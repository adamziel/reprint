<?php

namespace Reprint\Importer\Index;

use RuntimeException;
use function Reprint\Exporter\assert_valid_path;

final class IndexLineParser
{
    /**
     * Parse one JSON index line into an array.
     */
    public static function parse(string $line): ?array
    {
        $line = trim($line);
        if ($line === "") {
            return null;
        }
        $data = json_decode($line, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid index line format");
        }
        $path_encoded = $data["path"] ?? "";
        if (!is_string($path_encoded) || $path_encoded === "") {
            throw new RuntimeException("Invalid index path");
        }
        $path = base64_decode($path_encoded, true);
        if ($path === "" || $path === false) {
            throw new RuntimeException("Invalid index path (base64 decode failed)");
        }
        assert_valid_path($path, "index path");
        return [
            "path" => $path,
            "ctime" => (int) ($data["ctime"] ?? 0),
            "size" => (int) ($data["size"] ?? 0),
            "type" => (string) ($data["type"] ?? "file"),
        ];
    }
}
