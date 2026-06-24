<?php

namespace Reprint\Exporter\Command;

use InvalidArgumentException;
use Reprint\Exporter\FileTreeProducer;
use Reprint\Exporter\ResourceBudget;
use function Reprint\Exporter\file_fetch_paths_should_gzip;
use function Reprint\Exporter\require_int_range;
use function Reprint\Exporter\resolve_directories;
use function Reprint\Exporter\stream_file_producer;

final class FileFetchCommand extends BudgetedExportCommand
{
    public function execute(array $config, ResourceBudget $budget): array
    {
        // Same rationale as FileIndexCommand: avoid stale path metadata across
        // requests in long-lived PHP processes.
        clearstatcache(true);
    
        $directories = resolve_directories($config);
    
        $list_path = $config["file_list_path"] ?? null;
        if ($list_path === null && isset($_FILES["file_list"])) {
            $tmp_name = $_FILES["file_list"]["tmp_name"] ?? "";
            if ($tmp_name === "" || !is_uploaded_file($tmp_name)) {
                throw new InvalidArgumentException(
                    "file_list upload missing or invalid",
                );
            }
            $list_path = $tmp_name;
        }
    
        if ($list_path === null) {
            throw new InvalidArgumentException(
                "file_list is required for file_fetch endpoint",
            );
        }
    
        $raw = file_get_contents($list_path);
        if ($raw === false) {
            throw new InvalidArgumentException("Failed to read file_list");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException(
                "file_list must be a JSON array of paths",
            );
        }
        $paths = [];
        foreach ($decoded as $path) {
            if (!is_string($path) || $path === "") {
                continue;
            }
            $paths[] = $path;
        }
    
        $chunk_size = $config["chunk_size"] ?? FileTreeProducer::DEFAULT_CHUNK_SIZE;
        $chunk_size = require_int_range(
            "chunk_size",
            (int) $chunk_size,
            16 * 1024,
            32 * 1024 * 1024,
        );
    
        $sync_options = [
            "chunk_size" => $chunk_size,
            "paths" => $paths,
        ];
        if (isset($config["cursor"])) {
            $sync_options["cursor"] = $config["cursor"];
        }
    
        $producer = new FileTreeProducer($directories, $sync_options);
        return stream_file_producer(
            $producer,
            $budget,
            $config,
            file_fetch_paths_should_gzip($paths),
        );
    }
}
