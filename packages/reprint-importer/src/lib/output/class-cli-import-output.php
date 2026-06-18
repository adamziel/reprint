<?php

namespace Reprint\Importer\Output;

use Reprint\Importer\TerminalProgress\TerminalProgress;

final class CliImportOutput implements ImportOutput
{
    /** @var resource */
    private $progress_fd;

    /** @var resource */
    private $error_fd;

    private bool $is_tty;

    private bool $verbose_mode;

    private float $last_event_output = 0.0;

    private float $event_throttle;

    private TerminalProgress $progress;

    /**
     * @param resource $progress_fd
     * @param resource $error_fd
     */
    public function __construct(
        $progress_fd,
        bool $is_tty,
        $error_fd,
        bool $verbose_mode = false,
        float $event_throttle = 1.0
    ) {
        $this->progress_fd = $progress_fd;
        $this->error_fd = $error_fd;
        $this->is_tty = $is_tty;
        $this->verbose_mode = $verbose_mode;
        $this->event_throttle = $event_throttle;
        $this->progress = new TerminalProgress($is_tty, $progress_fd, $verbose_mode);
    }

    public static function create_default(): self
    {
        $stdout = defined('STDOUT') ? STDOUT : fopen('php://output', 'wb');
        $stderr = defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');
        $is_tty = function_exists('posix_isatty') && @posix_isatty($stdout);

        return new self($stdout, $is_tty, $stderr);
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
        return $this->is_tty;
    }

    public function use_error_stream(): void
    {
        $this->progress_fd = $this->error_fd;
        $this->is_tty = function_exists('posix_isatty') && @posix_isatty($this->error_fd);
        $this->progress->set_progress_fd($this->progress_fd);
        $this->progress->set_is_tty($this->is_tty);
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
        return @fwrite($this->progress_fd, $message) !== false;
    }

    public function write_error(string $message): bool
    {
        return @fwrite($this->error_fd, $message) !== false;
    }

    public function emit_event(array $data, bool $force = false): bool
    {
        if ($this->is_tty && !$this->verbose_mode) {
            return true;
        }

        $now = microtime(true);
        $is_status_change =
            isset($data['status']) &&
            in_array($data['status'], ['starting', 'complete', 'error'], true);

        if (
            !$force &&
            !$is_status_change &&
            $now - $this->last_event_output < $this->event_throttle
        ) {
            return true;
        }

        $json = json_encode($data);
        if ($json === false) {
            $json = '{"error":"Failed to encode import output event"}';
        }

        if (@fwrite($this->progress_fd, $json . "\n") === false) {
            return false;
        }

        @flush();
        $this->last_event_output = $now;
        return true;
    }
}
