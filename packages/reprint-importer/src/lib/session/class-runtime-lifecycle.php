<?php

namespace Reprint\Importer\Session;

use RuntimeException;

final class RuntimeLifecycle
{
    private string $state_dir;
    private string $fs_root;
    /** @var callable */
    private $shutdown_handler;
    private bool $prepared = false;
    private bool $signal_handlers_installed = false;
    /** @var array<int, mixed> */
    private array $previous_signal_handlers = [];
    private ?bool $previous_async_signals = null;

    public function __construct(
        string $state_dir,
        string $fs_root,
        callable $shutdown_handler
    ) {
        $this->state_dir = $state_dir;
        $this->fs_root = $fs_root;
        $this->shutdown_handler = $shutdown_handler;
    }

    public function prepare(): void
    {
        if ($this->prepared) {
            return;
        }

        $this->ensure_directories();
        $this->install_signal_handlers();
        $this->prepared = true;
    }

    public function cleanup(): void
    {
        if (!$this->prepared) {
            return;
        }

        $this->restore_signal_handlers();
        $this->prepared = false;
    }

    private function ensure_directories(): void
    {
        if (!is_dir($this->state_dir) && !mkdir($this->state_dir, 0755, true)) {
            throw new RuntimeException("Failed to create directory: {$this->state_dir}");
        }
        if (!is_dir($this->fs_root) && !mkdir($this->fs_root, 0755, true)) {
            throw new RuntimeException("Failed to create directory: {$this->fs_root}");
        }
    }

    private function install_signal_handlers(): void
    {
        if (!function_exists("pcntl_signal")) {
            return;
        }

        $signals = [SIGINT, SIGTERM];
        if (function_exists("pcntl_signal_get_handler")) {
            foreach ($signals as $signal) {
                $this->previous_signal_handlers[$signal] = pcntl_signal_get_handler($signal);
            }
        }

        if (function_exists("pcntl_async_signals")) {
            $this->previous_async_signals = pcntl_async_signals();
            pcntl_async_signals(true);
        }

        pcntl_signal(SIGINT, $this->shutdown_handler);
        pcntl_signal(SIGTERM, $this->shutdown_handler);
        $this->signal_handlers_installed = true;
    }

    private function restore_signal_handlers(): void
    {
        if (!$this->signal_handlers_installed || !function_exists("pcntl_signal")) {
            return;
        }

        foreach ([SIGINT, SIGTERM] as $signal) {
            if (array_key_exists($signal, $this->previous_signal_handlers)) {
                pcntl_signal($signal, $this->previous_signal_handlers[$signal]);
            } else {
                pcntl_signal($signal, SIG_DFL);
            }
        }

        if ($this->previous_async_signals !== null && function_exists("pcntl_async_signals")) {
            pcntl_async_signals($this->previous_async_signals);
        }

        $this->previous_signal_handlers = [];
        $this->previous_async_signals = null;
        $this->signal_handlers_installed = false;
    }
}
