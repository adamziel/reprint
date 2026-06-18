<?php

namespace Reprint\Importer\Session;

final class VolatileFileTracker
{
    private string $file;

    /** @var callable|null */
    private $audit;

    public function __construct(string $file, ?callable $audit = null)
    {
        $this->file = $file;
        $this->audit = $audit;
    }

    /**
     * @return array<string, int> Map of path => change count.
     */
    public function load(): array
    {
        if (!file_exists($this->file)) {
            return [];
        }

        $json = file_get_contents($this->file);
        if ($json === false) {
            return [];
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    public function save(array $files): void
    {
        if (empty($files)) {
            if (file_exists($this->file)) {
                @unlink($this->file);
            }
            return;
        }

        $json = json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        file_put_contents($this->file, $json . "\n");
    }

    public function record(string $path): int
    {
        $files = $this->load();
        $count = ($files[$path] ?? 0) + 1;
        $files[$path] = $count;
        $this->save($files);
        $this->audit("VOLATILE | path={$path} | count={$count}");

        return $count;
    }

    public function clear(string $path): bool
    {
        $files = $this->load();
        if (!isset($files[$path])) {
            return false;
        }

        unset($files[$path]);
        $this->save($files);
        $this->audit("VOLATILE CLEARED | path={$path}");

        return true;
    }

    private function audit(string $message): void
    {
        if ($this->audit !== null) {
            ($this->audit)($message);
        }
    }
}
