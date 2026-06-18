<?php

namespace Reprint\Importer\Index;

use RuntimeException;

final class IndexStore
{
    private string $index_file;
    private ?string $updates_file;

    /** @var resource|null */
    private $updates_handle = null;

    private int $updates_count = 0;
    private ?string $last_update_path = null;
    private ?bool $last_update_delete = null;
    private ?int $last_update_ctime = null;
    private ?int $last_update_size = null;
    private ?string $last_update_type = null;

    /** @var callable|null */
    private $audit_logger;

    public function __construct(
        string $index_file,
        ?string $updates_file = null,
        ?callable $audit_logger = null
    ) {
        $this->index_file = $index_file;
        $this->updates_file = $updates_file;
        $this->audit_logger = $audit_logger;
    }

    public function __destruct()
    {
        if ($this->updates_handle) {
            fclose($this->updates_handle);
            $this->updates_handle = null;
        }
    }

    public function count(): int
    {
        if (!is_file($this->index_file)) {
            return 0;
        }
        $handle = fopen($this->index_file, "r");
        if (!$handle) {
            return 0;
        }
        $count = 0;
        while (fgets($handle) !== false) {
            $count++;
        }
        fclose($handle);
        return $count;
    }

    public function updates_file(): ?string
    {
        return $this->updates_file;
    }

    public function upsert(
        string $path,
        int $ctime,
        int $size,
        string $type
    ): void {
        if (!$this->updates_handle) {
            $this->begin_updates();
        }
        if (
            $this->last_update_path === $path &&
            $this->last_update_delete === false &&
            $this->last_update_ctime === $ctime &&
            $this->last_update_size === $size &&
            $this->last_update_type === $type
        ) {
            return;
        }
        $line = json_encode(
            [
                "op" => "F",
                "path" => base64_encode($path),
                "ctime" => $ctime,
                "size" => $size,
                "type" => $type,
            ],
            JSON_UNESCAPED_SLASHES,
        );
        if ($line !== false) {
            $bytes = fwrite($this->updates_handle, $line . "\n");
            if ($bytes === false) {
                throw new RuntimeException("Failed to write to index updates file (disk full?)");
            }
        }
        $this->updates_count++;
        $this->last_update_path = $path;
        $this->last_update_delete = false;
        $this->last_update_ctime = $ctime;
        $this->last_update_size = $size;
        $this->last_update_type = $type;
    }

    public function delete(string $path): void
    {
        if (!$this->updates_handle) {
            $this->begin_updates();
        }
        if (
            $this->last_update_path === $path &&
            $this->last_update_delete === true
        ) {
            return;
        }
        $line = json_encode(
            [
                "op" => "D",
                "path" => base64_encode($path),
            ],
            JSON_UNESCAPED_SLASHES,
        );
        if ($line !== false) {
            $bytes = fwrite($this->updates_handle, $line . "\n");
            if ($bytes === false) {
                throw new RuntimeException("Failed to write to index updates file (disk full?)");
            }
        }
        $this->updates_count++;
        $this->last_update_path = $path;
        $this->last_update_delete = true;
        $this->last_update_ctime = null;
        $this->last_update_size = null;
        $this->last_update_type = null;
    }

    public function recover(): void
    {
        if ($this->updates_file && file_exists($this->updates_file)) {
            $this->finalize_updates();
        }
    }

    public function delete_updates_file(): void
    {
        if ($this->updates_file && file_exists($this->updates_file)) {
            @unlink($this->updates_file);
            $this->audit("FILE DELETE | {$this->updates_file}");
        }
    }

    public function clear_updates_state(): void
    {
        if ($this->updates_handle) {
            fclose($this->updates_handle);
        }
        $this->updates_file = null;
        $this->updates_handle = null;
        $this->updates_count = 0;
        $this->reset_last_update();
    }

    public function begin_updates(): void
    {
        if ($this->updates_handle) {
            return;
        }

        $is_new = false;
        if ($this->updates_file === null) {
            $tmp = tempnam(sys_get_temp_dir(), "index-updates-");
            if ($tmp === false) {
                throw new RuntimeException("Failed to create temp index updates file");
            }
            $this->updates_file = $tmp;
            $is_new = true;
        } elseif (!file_exists($this->updates_file)) {
            $dir = dirname($this->updates_file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $is_new = true;
        }
        $this->updates_handle = fopen($this->updates_file, "a");
        if (!$this->updates_handle) {
            throw new RuntimeException(
                "Failed to open index updates file: {$this->updates_file}",
            );
        }
        if ($is_new) {
            $this->audit(
                "FILE CREATE | {$this->updates_file} | index updates buffer",
            );
        }
        $this->updates_count = 0;
        $this->reset_last_update();
    }

    public function finalize_updates(): void
    {
        if ($this->updates_handle) {
            fclose($this->updates_handle);
            $this->updates_handle = null;
        }
        $this->reset_last_update();

        $has_updates =
            $this->updates_count > 0 ||
            ($this->updates_file &&
                file_exists($this->updates_file) &&
                filesize($this->updates_file) > 0);

        if (!$has_updates) {
            if ($this->updates_file && file_exists($this->updates_file)) {
                @unlink($this->updates_file);
                $this->audit(
                    "FILE DELETE | {$this->updates_file} | no updates to merge",
                );
            }
            $this->updates_count = 0;
            return;
        }

        $updates_path = $this->updates_file;
        $new_index = $this->index_file . ".new";

        $this->audit("INDEX MERGE START | merging updates into {$this->index_file}");

        $old_handle = file_exists($this->index_file)
            ? fopen($this->index_file, "r")
            : null;
        $upd_handle = fopen($updates_path, "r");
        $new_handle = fopen($new_index, "w");

        if (!$upd_handle || !$new_handle) {
            throw new RuntimeException("Failed to merge index updates");
        }

        $old = $this->read_index_line($old_handle);
        $carry = null;
        $upd = $this->read_update_line($upd_handle, $carry);
        $last_written_path = null;

        while ($old !== null || $upd !== null) {
            if ($upd === null) {
                if ($last_written_path !== $old["path"]) {
                    self::write_index_entry($new_handle, $old);
                    $last_written_path = $old["path"];
                }
                $old = $this->read_index_line($old_handle);
                continue;
            }

            if ($old === null) {
                if (!$upd["delete"] && $last_written_path !== $upd["path"]) {
                    self::write_index_entry($new_handle, $upd);
                    $last_written_path = $upd["path"];
                }
                $upd = $this->read_update_line($upd_handle, $carry);
                continue;
            }

            $cmp = strcmp($old["path"], $upd["path"]);
            if ($cmp === 0) {
                if (!$upd["delete"] && $last_written_path !== $upd["path"]) {
                    self::write_index_entry($new_handle, $upd);
                    $last_written_path = $upd["path"];
                }
                $old = $this->read_index_line($old_handle);
                $upd = $this->read_update_line($upd_handle, $carry);
            } elseif ($cmp < 0) {
                if ($last_written_path !== $old["path"]) {
                    self::write_index_entry($new_handle, $old);
                    $last_written_path = $old["path"];
                }
                $old = $this->read_index_line($old_handle);
            } else {
                if (!$upd["delete"] && $last_written_path !== $upd["path"]) {
                    self::write_index_entry($new_handle, $upd);
                    $last_written_path = $upd["path"];
                }
                $upd = $this->read_update_line($upd_handle, $carry);
            }
        }

        if ($old_handle) {
            fclose($old_handle);
        }
        fclose($upd_handle);
        fclose($new_handle);

        if (!rename($new_index, $this->index_file)) {
            throw new RuntimeException("Failed to replace index file");
        }
        $this->audit("INDEX MERGE COMPLETE | {$this->index_file} updated");

        @unlink($updates_path);
        $this->audit("FILE DELETE | {$updates_path} | updates merged");
        $this->updates_count = 0;
    }

    /**
     * @param resource|null $handle
     */
    public function read_index_line($handle): ?array
    {
        if (!$handle) {
            return null;
        }
        while (($line = fgets($handle)) !== false) {
            $parsed = IndexLineParser::parse($line);
            if ($parsed !== null) {
                return $parsed;
            }
        }
        return null;
    }

    /**
     * @param resource|null $handle
     */
    private function read_update_line_raw($handle): ?array
    {
        if (!$handle) {
            return null;
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }
            $data = json_decode($line, true);
            if (!is_array($data)) {
                throw new RuntimeException("Invalid index update line format");
            }
            $op = $data["op"] ?? null;
            $path_encoded = $data["path"] ?? null;
            if (!is_string($path_encoded) || $path_encoded === "") {
                throw new RuntimeException("Invalid index update path");
            }
            $path = base64_decode($path_encoded);
            if ($path === false || $path === "") {
                throw new RuntimeException("Invalid index update path (base64 decode failed)");
            }
            if ($op === "D") {
                return [
                    "path" => $path,
                    "delete" => true,
                    "ctime" => 0,
                    "size" => 0,
                    "type" => null,
                ];
            }
            if ($op === "F") {
                return [
                    "path" => $path,
                    "delete" => false,
                    "ctime" => (int) ($data["ctime"] ?? 0),
                    "size" => (int) ($data["size"] ?? 0),
                    "type" => (string) ($data["type"] ?? "file"),
                ];
            }
        }
        return null;
    }

    /**
     * @param resource|null $handle
     */
    private function read_update_line($handle, ?array &$carry = null): ?array
    {
        if (!$handle) {
            return null;
        }
        $current = $carry ?? $this->read_update_line_raw($handle);
        $carry = null;
        if ($current === null) {
            return null;
        }

        while (true) {
            $next = $this->read_update_line_raw($handle);
            if ($next === null) {
                return $current;
            }
            if ($next["path"] !== $current["path"]) {
                $carry = $next;
                return $current;
            }
            $current = $next;
        }
    }

    /**
     * @param resource $handle
     */
    private static function write_index_entry($handle, array $entry): void
    {
        $line = json_encode(
            [
                "path" => base64_encode($entry["path"]),
                "ctime" => (int) $entry["ctime"],
                "size" => (int) $entry["size"],
                "type" => (string) $entry["type"],
            ],
            JSON_UNESCAPED_SLASHES,
        );
        if ($line !== false) {
            fwrite($handle, $line . "\n");
        }
    }

    private function reset_last_update(): void
    {
        $this->last_update_path = null;
        $this->last_update_delete = null;
        $this->last_update_ctime = null;
        $this->last_update_size = null;
        $this->last_update_type = null;
    }

    private function audit(string $message): void
    {
        if ($this->audit_logger !== null) {
            ($this->audit_logger)($message);
        }
    }
}
