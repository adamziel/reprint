<?php

namespace Reprint\Importer\Output;

use Reprint\Importer\TerminalProgress\TerminalProgress;

final class BufferedImportOutput implements ImportOutput
{
    /** @var array<int, array> */
    private array $events = [];

    /** @var array<int, string> */
    private array $output = [];

    /** @var array<int, string> */
    private array $errors = [];

    private bool $verbose_mode = false;

    /** @var resource */
    private $sink;

    private TerminalProgress $progress;

    public function __construct()
    {
        $this->sink = fopen('php://temp', 'wb');
        $this->progress = new TerminalProgress(false, $this->sink, false);
    }

    public function progress(): TerminalProgress
    {
        return $this->progress;
    }

    public function set_verbose_mode(bool $verbose_mode): void
    {
        $this->verbose_mode = $verbose_mode;
        $this->progress->set_verbose_mode($verbose_mode);
    }

    public function is_verbose(): bool
    {
        return $this->verbose_mode;
    }

    public function is_tty(): bool
    {
        return false;
    }

    public function use_error_stream(): void
    {
    }

    public function show_progress_line(string $message, ?float $fraction = null): void
    {
        $this->progress->show_progress_line($message, $fraction);
    }

    public function show_lifecycle_line(string $message): void
    {
        $this->progress->show_lifecycle_line($message);
    }

    public function print_line(string $message): void
    {
        $this->progress->print_line($message);
    }

    public function clear_progress_line(): void
    {
        $this->progress->clear_progress_line();
    }

    public function tick_spinner(): void
    {
        $this->progress->tick_spinner();
    }

    public function set_active_label(?string $label): void
    {
        $this->progress->set_active_label($label);
    }

    public function is_quiet_lifecycle(): bool
    {
        return $this->progress->is_quiet_lifecycle();
    }

    public function write(string $message): bool
    {
        $this->output[] = $message;
        return true;
    }

    public function write_error(string $message): bool
    {
        $this->errors[] = $message;
        return true;
    }

    public function emit_event(array $data, bool $force = false): bool
    {
        $this->events[] = $data;
        return true;
    }

    public function events(): array
    {
        return $this->events;
    }

    public function output(): array
    {
        return $this->output;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
