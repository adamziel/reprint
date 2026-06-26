<?php

namespace Reprint\Importer\Session;

use RuntimeException;

final class ImportStateLock
{
    private string $lock_file;

    /** @var resource|null */
    private $handle = null;

    public function __construct(string $lock_file)
    {
        $this->lock_file = $lock_file;
    }

    public function acquire(): void
    {
        if (is_resource($this->handle)) {
            return;
        }

        $dir = dirname($this->lock_file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException("Failed to create state lock directory: {$dir}");
        }

        $handle = fopen($this->lock_file, "c+");
        if ($handle === false) {
            throw new RuntimeException("Failed to open import state lock: {$this->lock_file}");
        }

        $would_block = 0;
        if (!flock($handle, LOCK_EX | LOCK_NB, $would_block)) {
            $details = $this->read_details($handle);
            fclose($handle);

            $message = "Another importer process is already using this state directory.";
            if ($details !== null) {
                $message .= " Active lock: {$details}.";
            }
            $message .= " Stop that process or use a different --state-dir.";

            throw new RuntimeException($message);
        }

        $this->handle = $handle;
        $this->write_details($handle);
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        $handle = $this->handle;
        $this->handle = null;

        ftruncate($handle, 0);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * @param resource $handle
     */
    private function write_details($handle): void
    {
        $details = [
            "pid" => getmypid(),
            "started_at" => gmdate("c"),
            "lock_file" => $this->lock_file,
        ];

        $json = json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = "{}";
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $json);
        fflush($handle);
        if (function_exists("fsync")) {
            fsync($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private function read_details($handle): ?string
    {
        rewind($handle);
        $contents = stream_get_contents($handle);
        if (!is_string($contents) || trim($contents) === "") {
            return null;
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return trim($contents);
        }

        $parts = [];
        if (isset($data["pid"])) {
            $parts[] = "pid=" . (string) $data["pid"];
        }
        if (isset($data["started_at"]) && is_string($data["started_at"])) {
            $parts[] = "started_at=" . $data["started_at"];
        }

        return $parts === [] ? null : implode(", ", $parts);
    }
}
