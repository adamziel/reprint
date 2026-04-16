<?php
/**
 * Renders progress and lifecycle output to the terminal.
 *
 * Two categories of output:
 *
 * - Progress line: a single line that overwrites in place, e.g.
 *   "[5,091 files] /path/to/file.jpg". Use show_progress_line().
 *
 * - Lifecycle line: a regular line that announces a phase transition,
 *   e.g. "Starting db-pull". Use show_lifecycle_line(). Composite
 *   commands can suppress these by overriding show_lifecycle_line in
 *   a subclass when they want to provide their own framing.
 *
 * Both methods are no-ops when stdout is not a TTY (so machine
 * consumers reading JSONL don't get progress noise interleaved) or
 * when verbose_mode is on (so debug output isn't visually disturbed).
 */
class TerminalProgress
{
    /** @var bool Whether the progress stream is a TTY. */
    private bool $is_tty;

    /** @var resource Stream to write progress to (STDOUT or STDERR). */
    private $progress_fd;

    /** @var bool When true, all progress output is suppressed. */
    private bool $verbose_mode;

    /** @var int|null Cached terminal column count (lazy-loaded via tput). */
    private ?int $terminal_width_cache = null;

    public function __construct(bool $is_tty, $progress_fd, bool $verbose_mode = false)
    {
        $this->is_tty = $is_tty;
        $this->progress_fd = $progress_fd;
        $this->verbose_mode = $verbose_mode;
    }

    /**
     * Update the TTY flag. Used when the progress stream is reassigned
     * (e.g. STDOUT → STDERR for --sql-output=stdout mode).
     */
    public function set_is_tty(bool $is_tty): void
    {
        $this->is_tty = $is_tty;
    }

    /** Update the progress stream. */
    public function set_progress_fd($progress_fd): void
    {
        $this->progress_fd = $progress_fd;
    }

    /** Toggle verbose mode (suppresses progress output when true). */
    public function set_verbose_mode(bool $verbose_mode): void
    {
        $this->verbose_mode = $verbose_mode;
    }

    public function is_tty(): bool
    {
        return $this->is_tty;
    }

    public function is_verbose(): bool
    {
        return $this->verbose_mode;
    }

    /**
     * Show progress in a single refreshing line (TTY mode only).
     * Truncates long messages to fit terminal width.
     */
    public function show_progress_line(string $message): void
    {
        if (!$this->is_tty || $this->verbose_mode) {
            return;
        }
        $width = $this->get_terminal_width();
        if (strlen($message) > $width - 3) {
            $message = substr($message, 0, $width - 3) . "...";
        }
        fwrite($this->progress_fd, "\r\033[K" . $message);
    }

    /**
     * Print a lifecycle message that announces a phase transition.
     *
     * The message is written verbatim — callers include their own \n
     * if they want a newline. Composite commands like `pull` can
     * subclass and override this to suppress sub-command lifecycle
     * messages while keeping the show_progress_line output.
     */
    public function show_lifecycle_line(string $message): void
    {
        if (!$this->is_tty || $this->verbose_mode) {
            return;
        }
        fwrite($this->progress_fd, $message);
    }

    /**
     * Clear the current progress line (TTY mode only).
     */
    public function clear_progress_line(): void
    {
        if (!$this->is_tty || $this->verbose_mode) {
            return;
        }
        fwrite($this->progress_fd, "\r\033[K");
    }

    /**
     * Return the terminal width in columns. Tries `tput cols` once
     * and caches the result; falls back to 80.
     */
    public function get_terminal_width(): int
    {
        if ($this->terminal_width_cache !== null) {
            return $this->terminal_width_cache;
        }
        $width = 80;
        if (function_exists("exec")) {
            $tput_cols = @exec("tput cols 2>/dev/null");
            if ($tput_cols && is_numeric($tput_cols)) {
                $width = (int) $tput_cols;
            }
        }
        $this->terminal_width_cache = $width;
        return $width;
    }
}
