<?php

namespace Reprint\Importer\FileSync;

use RuntimeException;

final class DownloadList
{
    public static function count_lines(string $file, int $up_to_byte = -1): int
    {
        if (!is_file($file)) {
            return 0;
        }
        $handle = fopen($file, "r");
        if (!$handle) {
            return 0;
        }
        $count = 0;
        $chunk_size = 65536;
        $remaining = $up_to_byte >= 0 ? $up_to_byte : PHP_INT_MAX;
        while ($remaining > 0 && !feof($handle)) {
            $data = fread($handle, min($chunk_size, $remaining));
            if ($data === false || $data === '') {
                break;
            }
            $count += substr_count($data, "\n");
            $remaining -= strlen($data);
        }
        fclose($handle);
        return $count;
    }

    public static function read_path(string $line): ?string
    {
        $line = trim($line);
        if ($line === "") {
            return null;
        }

        $data = json_decode($line, true);
        if (is_string($data)) {
            return $data !== "" ? $data : null;
        }
        if (!is_array($data)) {
            return null;
        }

        $path_encoded = $data["path"] ?? "";
        if (!is_string($path_encoded) || $path_encoded === "") {
            return null;
        }
        $path = base64_decode($path_encoded, true);
        if ($path === false || $path === "") {
            return null;
        }

        return $path;
    }

    /**
     * @param resource $handle
     */
    public static function append_path($handle, string $path): void
    {
        $line = json_encode(
            ["path" => base64_encode($path)],
            JSON_UNESCAPED_SLASHES,
        );
        if ($line !== false) {
            fwrite($handle, $line . "\n");
        }
    }

    /**
     * Builds a JSON batch file listing the next set of paths to download.
     *
     * @return array{file: string, offset: int, next_offset: int, entries: int}|null
     */
    public static function prepare_batch(
        string $list_file,
        int $offset,
        int $max_request_bytes
    ): ?array {
        $limit = (int) max(256 * 1024, $max_request_bytes * 0.8);

        $handle = fopen($list_file, "r");
        if (!$handle) {
            throw new RuntimeException("Failed to open download list file");
        }

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $tmp = tempnam(sys_get_temp_dir(), "file-fetch-");
        if ($tmp === false) {
            fclose($handle);
            throw new RuntimeException("Failed to create fetch batch file");
        }
        $out = fopen($tmp, "w");
        if (!$out) {
            fclose($handle);
            @unlink($tmp);
            throw new RuntimeException("Failed to open fetch batch file");
        }

        $bytes = 1;
        $entries = 0;
        $first = true;
        fwrite($out, "[");
        while (true) {
            $line_start = ftell($handle);
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $path = self::read_path($line);
            if ($path === null) {
                continue;
            }
            $json_path = json_encode(
                $path,
                JSON_UNESCAPED_SLASHES,
            );
            if ($json_path === false) {
                continue;
            }
            $prefix = $first ? "" : ",";
            $chunk = $prefix . $json_path;
            $needed = $bytes + strlen($chunk) + 1;

            if (!$first && $needed > $limit) {
                fseek($handle, $line_start);
                break;
            }
            if ($first && $needed > $limit) {
                if (fwrite($out, $chunk) === false) {
                    throw new RuntimeException("Failed to write fetch batch file (disk full?)");
                }
                $bytes += strlen($chunk);
                $entries++;
                $first = false;
                break;
            }

            if (fwrite($out, $chunk) === false) {
                throw new RuntimeException("Failed to write fetch batch file (disk full?)");
            }
            $bytes += strlen($chunk);
            $entries++;
            $first = false;
        }
        fwrite($out, "]");
        $bytes++;

        $next_offset = ftell($handle);
        fclose($handle);
        fclose($out);

        if ($bytes <= 2) {
            @unlink($tmp);
            return null;
        }

        return [
            "file" => $tmp,
            "offset" => $offset,
            "next_offset" => $next_offset,
            "entries" => $entries,
        ];
    }
}
