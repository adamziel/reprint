<?php

namespace Reprint\Importer\Session;

final class ImportStateSchema
{
    public static function default_state(): array
    {
        return [
            "command" => null,
            "status" => null,
            "cursor" => null,
            "stage" => null,
            "preflight" => null,
            "remote_protocol_version" => null,
            "remote_protocol_min_version" => null,
            "version" => null,
            "webhost" => null,
            "follow_symlinks" => true,
            "fs_root_nonempty_behavior" => "error",
            "filter" => "none",
            "max_allowed_packet" => null,
            "db_index" => [
                "file" => null,
                "tables" => 0,
                "rows_estimated" => 0,
                "bytes" => 0,
                "updated_at" => null,
            ],
            "diff" => [
                "remote_offset" => 0,
                "local_after" => null,
            ],
            "index" => [
                "cursor" => null,
            ],
            "fetch" => [
                "offset" => 0,
                "next_offset" => 0,
                "batch_file" => null,
                "cursor" => null,
            ],
            "fetch_skipped" => [
                "offset" => 0,
                "next_offset" => 0,
                "batch_file" => null,
                "cursor" => null,
            ],
            "current_file" => null,
            "current_file_bytes" => null,
            "sql_bytes" => null,
            "apply" => [
                "statements_executed" => 0,
                "bytes_read" => 0,
                "rewrite_url" => null,
                "target_engine" => null,
                "target_db" => null,
                "target_host" => null,
                "target_port" => null,
                "target_user" => null,
                "target_pass" => null,
                "target_sqlite_path" => null,
                "remote_paths_removed_from_local_site" => [],
            ],
            "sql_output" => null,
            "mysql_host" => null,
            "mysql_port" => null,
            "mysql_user" => null,
            "mysql_database" => null,
            "consecutive_timeouts" => 0,
            "tuning" => [
                "config" => [],
                "state" => [],
            ],
            "pull" => [
                "stage" => null,
                "files_filter" => null,
                "skipped_pending" => false,
            ],
        ];
    }

    public static function normalize(array $state): array
    {
        $defaults = self::default_state();
        $state = array_intersect_key($state, $defaults);
        $state = array_merge($defaults, $state);

        foreach (["diff", "index", "fetch", "fetch_skipped", "tuning", "db_index", "apply", "pull"] as $key) {
            $value = $state[$key] ?? [];
            if (!is_array($value)) {
                $value = [];
            }
            $value = array_intersect_key($value, $defaults[$key]);
            $state[$key] = array_merge($defaults[$key], $value);
        }

        return $state;
    }
}
