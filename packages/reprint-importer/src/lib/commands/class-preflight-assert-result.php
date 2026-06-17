<?php

namespace Reprint\Importer\Command;

final class PreflightAssertResult implements ImportCommandResult
{
    /** @var array<int, array<string, mixed>> */
    private $checks;

    /** @var bool */
    private $all_pass;

    /**
     * @param array<int, array<string, mixed>> $checks
     */
    public function __construct(array $checks, bool $all_pass)
    {
        $this->checks = $checks;
        $this->all_pass = $all_pass;
    }

    public function type(): string
    {
        return 'preflight-assert';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    public function all_pass(): bool
    {
        return $this->all_pass;
    }

    public function to_array(): array
    {
        return [
            'type' => $this->type(),
            'checks' => $this->checks,
            'all_pass' => $this->all_pass,
        ];
    }
}
