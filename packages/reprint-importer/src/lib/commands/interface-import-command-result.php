<?php

namespace Reprint\Importer\Command;

interface ImportCommandResult
{
    public function type(): string;

    /**
     * @return array<string, mixed>
     */
    public function to_array(): array;
}
