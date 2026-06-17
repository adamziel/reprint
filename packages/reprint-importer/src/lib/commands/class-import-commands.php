<?php

namespace Reprint\Importer\Command;

final class ImportCommands
{
    private const ALIASES = [
        'files-sync' => 'files-pull',
        'db-sync' => 'db-pull',
        'flat-document-root' => 'flat-docroot',
        'flatten-docroot' => 'flat-docroot',
    ];

    private const COMMANDS = [
        'pull' => PullCommand::class,
        'files-pull' => FilesPullCommand::class,
        'files-index' => FilesIndexCommand::class,
        'files-stats' => FilesStatsCommand::class,
        'db-pull' => DbPullCommand::class,
        'db-index' => DbIndexCommand::class,
        'db-domains' => DbDomainsCommand::class,
        'db-apply' => DbApplyCommand::class,
        'preflight' => PreflightCommand::class,
        'preflight-assert' => PreflightAssertCommand::class,
        'flat-docroot' => FlatDocrootCommand::class,
        'apply-runtime' => ApplyRuntimeCommand::class,
    ];

    public static function normalize_name(?string $command): ?string
    {
        if ($command === null || $command === '') {
            return $command;
        }

        return self::ALIASES[$command] ?? $command;
    }

    public static function get(string $command): ?ImportCommand
    {
        if (!isset(self::COMMANDS[$command])) {
            return null;
        }

        $class_name = self::COMMANDS[$command];
        return new $class_name();
    }

    public static function valid_names(): array
    {
        return array_keys(self::COMMANDS);
    }

    public static function valid_names_message(): string
    {
        return implode(', ', self::valid_names());
    }
}
