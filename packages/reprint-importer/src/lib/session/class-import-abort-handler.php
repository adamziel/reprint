<?php

namespace Reprint\Importer\Session;

use Reprint\Importer\Index\IndexStore;

final class ImportAbortHandler
{
    private ImportPaths $paths;
    private IndexStore $index_store;

    /** @var callable */
    private $audit;

    public function __construct(
        ImportPaths $paths,
        IndexStore $index_store,
        callable $audit
    ) {
        $this->paths = $paths;
        $this->index_store = $index_store;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function abort(
        array $state,
        string $command,
        string $sql_output_mode
    ): array {
        switch ($command) {
            case "files-pull":
                $this->audit(
                    "RESTART | Clearing files-pull progress (keeping local index and files)",
                    true,
                );
                $state = $this->reset_state($state);

                $this->index_store->recover();
                $this->index_store->delete_updates_file();
                $this->index_store->clear_updates_state();

                $this->delete_file($this->paths->remote_index_file());
                $this->delete_file($this->paths->download_list_file());
                $this->delete_file($this->paths->skipped_download_list_file());
                $this->delete_file($this->paths->volatile_files_file());
                $this->delete_file(
                    $this->paths->files_pull_checkpoint_file(),
                    " | abort files-pull",
                );

                return $state;

            case "files-index":
                $this->audit("RESTART | Clearing files-index state", true);
                $state["command"] = "files-index";
                $state["status"] = null;
                $this->delete_file(
                    $this->paths->files_pull_checkpoint_file(),
                    " | abort files-index",
                );
                $this->delete_file($this->paths->remote_index_file());
                return $state;

            case "db-pull":
                $this->audit("RESTART | Clearing db-pull state", true);
                $state = $this->reset_state($state);

                $this->delete_file($this->paths->db_pull_checkpoint_file(), " | abort db-pull");
                if ($sql_output_mode === "file") {
                    $this->delete_file($this->paths->sql_file(), " | abort db-pull");
                }
                $this->delete_file($this->paths->table_stats_file(), " | abort db-pull");
                $this->delete_file($this->paths->domains_file(), " | abort db-pull");
                return $state;

            case "db-index":
                $this->audit("RESTART | Clearing db-index state", true);
                $state = $this->reset_state($state);
                $this->delete_file($this->paths->db_pull_checkpoint_file(), " | abort db-index");
                $this->delete_file($this->paths->table_stats_file(), " | abort db-index");
                return $state;

            case "db-apply":
                $this->audit("RESTART | Clearing db-apply state", true);
                $this->delete_file($this->paths->db_apply_checkpoint_file(), " | abort db-apply");
                return $this->reset_state($state);
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function reset_state(array $state): array
    {
        $next = ImportStateSchema::default_state();
        $next["preflight"] = $state["preflight"] ?? null;
        $next["version"] = $state["version"] ?? null;
        $next["webhost"] = $state["webhost"] ?? null;
        $next["follow_symlinks"] = $state["follow_symlinks"] ?? false;
        $next["fs_root_nonempty_behavior"] = $state["fs_root_nonempty_behavior"] ?? "error";
        $next["max_allowed_packet"] = $state["max_allowed_packet"] ?? null;

        return $next;
    }

    private function delete_file(string $path, string $reason = ""): void
    {
        if (!file_exists($path)) {
            return;
        }

        @unlink($path);
        $this->audit("FILE DELETE | {$path}{$reason}");
    }

    private function audit(string $message, bool $to_console = true): void
    {
        ($this->audit)($message, $to_console);
    }
}
