<?php

namespace Reprint\Importer\Application\Result;

interface ImportCommandResult
{
    public function type(): string;

    /**
     * @return array<string, mixed>
     */
    public function to_array(): array;
}
