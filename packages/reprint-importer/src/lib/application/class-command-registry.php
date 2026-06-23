<?php

namespace Reprint\Importer\Application;

use InvalidArgumentException;
use Reprint\Importer\Application\UseCase\DbApplyHandler;
use Reprint\Importer\Application\UseCase\DbDomainsHandler;
use Reprint\Importer\Application\UseCase\DbIndexHandler;
use Reprint\Importer\Application\UseCase\DbPullHandler;
use Reprint\Importer\Application\UseCase\FilesIndexHandler;
use Reprint\Importer\Application\UseCase\FilesPullHandler;
use Reprint\Importer\Application\UseCase\FilesStatsHandler;
use Reprint\Importer\Application\UseCase\FlatDocrootHandler;
use Reprint\Importer\Application\UseCase\PreflightAssertHandler;
use Reprint\Importer\Application\UseCase\PreflightHandler;
use Reprint\Importer\Application\UseCase\PullHandler;
use Reprint\Importer\Application\UseCase\RuntimeApplyHandler;

final class CommandRegistry
{
    private const ALIASES = [
        "files-sync" => "files-pull",
        "db-sync" => "db-pull",
        "flat-document-root" => "flat-docroot",
        "flatten-docroot" => "flat-docroot",
    ];

    /** @var array<string, ImportCommandHandler> */
    private array $handlers;

    /**
     * @param array<string, ImportCommandHandler> $handlers
     */
    public function __construct(array $handlers)
    {
        $this->handlers = $handlers;
    }

    public static function create_default(): self
    {
        return new self([
            "pull" => new PullHandler(),
            "files-pull" => new FilesPullHandler(),
            "files-index" => new FilesIndexHandler(),
            "files-stats" => new FilesStatsHandler(),
            "db-pull" => new DbPullHandler(),
            "db-index" => new DbIndexHandler(),
            "db-domains" => new DbDomainsHandler(),
            "db-apply" => new DbApplyHandler(),
            "preflight" => new PreflightHandler(),
            "preflight-assert" => new PreflightAssertHandler(),
            "flat-docroot" => new FlatDocrootHandler(),
            "apply-runtime" => new RuntimeApplyHandler(),
        ]);
    }

    public static function normalize_name(?string $command): ?string
    {
        if ($command === null || $command === "") {
            return $command;
        }

        return self::ALIASES[$command] ?? $command;
    }

    public function get(string $command): ImportCommandHandler
    {
        if (!isset($this->handlers[$command])) {
            throw new InvalidArgumentException(
                "Invalid command: {$command}. Valid commands: " . $this->valid_names_message(),
            );
        }

        return $this->handlers[$command];
    }

    public function valid_names(): array
    {
        return array_keys($this->handlers);
    }

    public function valid_names_message(): string
    {
        return implode(", ", $this->valid_names());
    }
}
