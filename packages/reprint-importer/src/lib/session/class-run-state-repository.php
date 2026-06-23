<?php

namespace Reprint\Importer\Session;

use Reprint\Importer\Application\CommandRegistry;
use Reprint\Importer\Observability\AuditLogger;
use RuntimeException;

final class RunStateRepository
{
    private JsonStateStore $store;
    private ImportPaths $paths;
    private StatePathCodec $path_codec;
    private AuditLogger $audit;

    public function __construct(
        JsonStateStore $store,
        ImportPaths $paths,
        StatePathCodec $path_codec,
        AuditLogger $audit
    ) {
        $this->store = $store;
        $this->paths = $paths;
        $this->path_codec = $path_codec;
        $this->audit = $audit;
    }

    public function fresh(): ImportRunState
    {
        return ImportRunState::fresh();
    }

    public function load(): ImportRunState
    {
        try {
            $state = $this->store->load($this->paths->state_file());
        } catch (RuntimeException $e) {
            $this->audit->record($e->getMessage(), true);

            return $this->fresh();
        }

        if ($state === null) {
            return $this->fresh();
        }

        $this->migrate_preflight_checkpoint_from_state($state);

        $run_state = ImportRunState::from_array($state);
        if (is_string($run_state->command)) {
            $run_state->command = CommandRegistry::normalize_name($run_state->command);
        }

        return $run_state;
    }

    public function save(ImportRunState $state): void
    {
        $this->store->save($this->paths->state_file(), $state->to_array());
    }

    /**
     * @param array<string, mixed> $state
     */
    private function migrate_preflight_checkpoint_from_state(array $state): void
    {
        $legacy_keys = [
            "preflight",
            "remote_protocol_version",
            "remote_protocol_min_version",
            "version",
            "webhost",
        ];

        $has_legacy_preflight_state = false;
        foreach ($legacy_keys as $key) {
            if (array_key_exists($key, $state)) {
                $has_legacy_preflight_state = true;
                break;
            }
        }
        if (!$has_legacy_preflight_state) {
            return;
        }

        $existing = $this->store->load($this->paths->preflight_checkpoint_file()) ?? [];
        if ($existing !== []) {
            return;
        }

        $checkpoint = PreflightCheckpoint::from_persisted_array(
            $state,
            [$this->path_codec, "decode_preflight_data_paths"],
        );
        if (
            $checkpoint->entry === null &&
            $checkpoint->remote_protocol_version === null &&
            $checkpoint->remote_protocol_min_version === null &&
            $checkpoint->exporter_version === null &&
            $checkpoint->webhost === null
        ) {
            return;
        }

        $this->store->save(
            $this->paths->preflight_checkpoint_file(),
            $checkpoint->to_persisted_array([$this->path_codec, "encode_preflight_data_paths"]),
        );
    }
}
