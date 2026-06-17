<?php

namespace Reprint\Exporter\Command;

final class ExportCommands
{
    private const COMMANDS = [
        'file_index' => FileIndexCommand::class,
        'file_fetch' => FileFetchCommand::class,
        'sql_chunk' => SqlChunkCommand::class,
        'db_index' => DbIndexCommand::class,
        'preflight' => PreflightCommand::class,
    ];

    public static function all(): array
    {
        $commands = [];
        foreach (self::COMMANDS as $endpoint => $class_name) {
            $commands[$endpoint] = new $class_name();
        }
        return $commands;
    }
}
