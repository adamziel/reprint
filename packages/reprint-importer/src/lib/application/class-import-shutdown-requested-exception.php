<?php

namespace Reprint\Importer\Application;

use RuntimeException;

final class ImportShutdownRequestedException extends RuntimeException
{
    private int $signal;
    private bool $forced;

    public function __construct(string $message, int $signal, bool $forced = false)
    {
        $this->signal = $signal;
        $this->forced = $forced;

        parent::__construct($message);
    }

    public function signal(): int
    {
        return $this->signal;
    }

    public function forced(): bool
    {
        return $this->forced;
    }

    public function exit_code(): int
    {
        return 128 + $this->signal;
    }
}
