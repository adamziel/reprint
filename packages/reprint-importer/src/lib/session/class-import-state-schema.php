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
            "sql_output" => null,
            "mysql_host" => null,
            "mysql_port" => null,
            "mysql_user" => null,
            "mysql_database" => null,
            "tuning" => [
                "config" => [],
                "state" => [],
            ],
        ];
    }

    public static function normalize(array $state): array
    {
        $defaults = self::default_state();
        $state = array_intersect_key($state, $defaults);
        $state = array_merge($defaults, $state);

        foreach (["tuning"] as $key) {
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
