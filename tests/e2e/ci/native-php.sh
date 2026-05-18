#!/usr/bin/env bash
# Wrapper used by benchmarks when the host PHP process should load the
# wp_native_apis extension before running Reprint.
set -euo pipefail

if [ -n "${WP_NATIVE_APIS_EXTENSION_SO:-}" ]; then
	exec php -d "extension=${WP_NATIVE_APIS_EXTENSION_SO}" "$@"
fi

exec php "$@"
