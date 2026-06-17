<?php

namespace Reprint\Importer\Pull\Command;

final class PullStageCommands
{
    private const COMMANDS = [
        'preflight' => PreflightStageCommand::class,
        'files-pull' => FilesPullStageCommand::class,
        'db-pull' => DbPullStageCommand::class,
        'db-apply' => DbApplyStageCommand::class,
        'flat-docroot' => FlatDocrootStageCommand::class,
        'apply-runtime' => ApplyRuntimeStageCommand::class,
        'start' => StartStageCommand::class,
    ];

    public static function get(string $stage): ?PullStageCommand
    {
        if (!isset(self::COMMANDS[$stage])) {
            return null;
        }

        $class_name = self::COMMANDS[$stage];
        return new $class_name();
    }

    public static function valid_names(): array
    {
        return array_keys(self::COMMANDS);
    }
}
