<?php

namespace Reprint\Importer\Session;

use RuntimeException;

final class JsonStateStore
{
    public function load(string $file): ?array
    {
        if (!file_exists($file)) {
            return null;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            throw new RuntimeException("Corrupt JSON state file: {$file}");
        }

        return $data;
    }

    public function save(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException("Failed to create state directory: {$dir}");
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("Failed to encode state: " . json_last_error_msg());
        }

        $tmp_file = $file . ".tmp";
        if (file_put_contents($tmp_file, $json) === false) {
            throw new RuntimeException("Failed to write state file: {$tmp_file}");
        }
        if (!rename($tmp_file, $file)) {
            throw new RuntimeException("Failed to rename state file: {$tmp_file} -> {$file}");
        }
    }

    public function delete(string $file): void
    {
        if (file_exists($file) && !unlink($file)) {
            throw new RuntimeException("Failed to delete state file: {$file}");
        }
    }
}
