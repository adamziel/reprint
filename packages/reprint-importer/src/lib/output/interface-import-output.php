<?php

namespace Reprint\Importer\Output;

use Reprint\Importer\TerminalProgress\TerminalProgress;

interface ImportOutput
{
    public function progress(): TerminalProgress;

    public function set_verbose_mode(bool $verbose_mode): void;

    public function is_verbose(): bool;

    public function is_tty(): bool;

    public function use_error_stream(): void;

    public function show_progress_line(string $message, ?float $fraction = null): void;

    public function show_lifecycle_line(string $message): void;

    public function print_line(string $message): void;

    public function clear_progress_line(): void;

    public function tick_spinner(): void;

    public function set_active_label(?string $label): void;

    public function is_quiet_lifecycle(): bool;

    public function write(string $message): bool;

    public function write_error(string $message): bool;

    public function emit_event(array $data, bool $force = false): bool;
}
