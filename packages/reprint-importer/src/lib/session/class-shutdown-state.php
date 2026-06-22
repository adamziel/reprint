<?php

namespace Reprint\Importer\Session;

final class ShutdownState
{
    private bool $requested = false;

    public function request(): void
    {
        $this->requested = true;
    }

    public function reset(): void
    {
        $this->requested = false;
    }

    public function requested(): bool
    {
        return $this->requested;
    }
}
