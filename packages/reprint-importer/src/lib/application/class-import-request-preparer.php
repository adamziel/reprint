<?php

namespace Reprint\Importer\Application;

use InvalidArgumentException;
use RuntimeException;

final class ImportRequestPreparer
{
    private ImportContext $context;

    public function __construct(ImportContext $context)
    {
        $this->context = $context;
    }

    public function prepare(ImportRequest $request): void
    {
        $context = $this->context;
        $state = $context->state();

        $context->output()->set_verbose_mode($request->verbose());
        $context->set_follow_symlinks((bool) $request->value("follow_symlinks", true));
        $context->set_include_caches((bool) $request->value("include_caches", false));
        $context->set_extra_directory($request->value("extra_directory"));
        $context->set_pipeline(
            $request->value("pipeline_step"),
            $request->value("pipeline_steps"),
        );

        $this->apply_follow_symlinks_option($request, $state);
        $this->apply_fs_root_behavior_option($request, $state);
        $this->apply_filter_option($request, $state);
        $this->apply_max_allowed_packet_option($request, $state);
        $this->apply_sql_output_option($request, $state);
        $this->apply_mysql_connection_options($request, $state);

        $context->save_state($state);

        $this->apply_mysql_password_option($request);
        $context->validate_sql_output_options();
        $this->initialize_http_session($request);
    }

    private function apply_follow_symlinks_option(ImportRequest $request, $state): void
    {
        if ($request->has("follow_symlinks")) {
            $state->follow_symlinks = $this->context->follow_symlinks();
            return;
        }

        $this->context->set_follow_symlinks($state->follow_symlinks);
    }

    private function apply_fs_root_behavior_option(ImportRequest $request, $state): void
    {
        if ($request->has("fs_root_nonempty_behavior")) {
            $this->context->set_fs_root_nonempty_behavior(
                $request->value("fs_root_nonempty_behavior"),
            );
            $state->fs_root_nonempty_behavior = $this->context->fs_root_nonempty_behavior();
            return;
        }

        $this->context->set_fs_root_nonempty_behavior($state->fs_root_nonempty_behavior);
    }

    private function apply_filter_option(ImportRequest $request, $state): void
    {
        if (!$request->has("filter")) {
            $this->context->set_filter($state->filter);
            return;
        }

        $next = $request->value("filter");
        $prev = $state->filter;
        $status = $state->status;
        $is_mid_flight = $prev !== null && $prev !== $next && $status !== null && $status !== "complete";
        if ($is_mid_flight) {
            throw new RuntimeException(
                "Cannot change --filter from '{$prev}' to '{$next}' while a sync is in progress. " .
                    "Finish the current sync or use --abort to start over.",
            );
        }

        $this->context->set_filter($next);
        $state->filter = $next;
    }

    private function apply_max_allowed_packet_option(ImportRequest $request, $state): void
    {
        if ($request->has("max_allowed_packet")) {
            $this->context->set_max_allowed_packet((int) $request->value("max_allowed_packet"));
            $state->max_allowed_packet = $this->context->max_allowed_packet();
            return;
        }

        if ($state->max_allowed_packet !== null) {
            $this->context->set_max_allowed_packet($state->max_allowed_packet);
        }
    }

    private function apply_sql_output_option(ImportRequest $request, $state): void
    {
        if ($request->has("sql_output")) {
            $this->context->set_sql_output_mode($request->value("sql_output"));
            $state->sql_output = $this->context->sql_output_mode();
        } elseif ($state->sql_output !== null) {
            $this->context->set_sql_output_mode($state->sql_output);
        }

        if ($this->context->sql_output_mode() === "stdout") {
            $this->context->output()->use_error_stream();
        }
    }

    private function apply_mysql_connection_options(ImportRequest $request, $state): void
    {
        if ($request->has("mysql_host")) {
            $this->context->set_mysql_host($request->value("mysql_host"));
            $state->mysql_host = $this->context->mysql_host();
        } elseif ($state->mysql_host !== null) {
            $this->context->set_mysql_host($state->mysql_host);
        }

        if ($request->has("mysql_port")) {
            $this->context->set_mysql_port((int) $request->value("mysql_port"));
            $state->mysql_port = $this->context->mysql_port();
        } elseif ($state->mysql_port !== null) {
            $this->context->set_mysql_port($state->mysql_port);
        }

        if ($request->has("mysql_user")) {
            $this->context->set_mysql_user($request->value("mysql_user"));
            $state->mysql_user = $this->context->mysql_user();
        } elseif ($state->mysql_user !== null) {
            $this->context->set_mysql_user($state->mysql_user);
        }

        if ($request->has("mysql_database")) {
            $this->context->set_mysql_database($request->value("mysql_database"));
            $state->mysql_database = $this->context->mysql_database();
        } elseif ($state->mysql_database !== null) {
            $this->context->set_mysql_database($state->mysql_database);
        }
    }

    private function apply_mysql_password_option(ImportRequest $request): void
    {
        if ($request->has("mysql_password")) {
            $this->context->set_mysql_password($request->value("mysql_password"));
            return;
        }

        if (getenv("MYSQL_PASSWORD") !== false) {
            $this->context->set_mysql_password(getenv("MYSQL_PASSWORD"));
        }
    }

    private function initialize_http_session(ImportRequest $request): void
    {
        $session = $this->context->http_session();
        $state = $this->context->state();
        $options = $request->options();

        $config = array_merge(
            $state->tuning_config(),
            $options["tuning_config"] ?? [],
        );

        $session->configure_tuner($config, $state->tuning_state(), $this->context->max_allowed_packet());
        $state->set_tuning($session->tuning_config(), $session->tuning_state());

        $this->context->audit_log(
            "TUNER CONFIG | " . json_encode($state->tuning_config()),
            false,
        );

        $secret = $request->value("secret");
        if (empty($secret)) {
            $session->set_hmac_secret(null);
            return;
        }

        if (!class_exists(\Reprint\Exporter\Site_Export_HMAC_Client::class)) {
            throw new RuntimeException(
                "HMAC signing runtime not found. Run composer install, or rebuild the PHAR with the exporter package included.",
            );
        }

        $session->set_hmac_secret($secret);
    }
}
