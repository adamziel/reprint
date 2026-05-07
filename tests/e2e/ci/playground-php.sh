#!/usr/bin/env bash
# Wrapper that invokes PHP through WordPress Playground's WASM runtime.
# Used by e2e tests when PHP_BINARY points here instead of the system `php`.
#
# By default this delegates to @wp-playground/cli php so the CI still covers
# the public CLI path. When WP_MYSQL_PARSER_EXTENSION_MANIFEST is set, or when
# PLAYGROUND_PHP_USE_WASM_RUNNER=1 is set, it uses the npm-installed
# @php-wasm/node runner because the CLI does not expose arbitrary external PHP
# extensions.
set -euo pipefail

if [ -n "${WP_MYSQL_PARSER_EXTENSION_MANIFEST:-}" ] || [ "${PLAYGROUND_PHP_USE_WASM_RUNNER:-}" = "1" ]; then
    SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
    NODE_CMD=(node)
    if ! node --experimental-wasm-jspi --input-type=module -e "import { jspi } from 'wasm-feature-detect'; process.exit(await jspi() ? 0 : 1);" >/dev/null 2>&1; then
        NODE_CMD=(npx --yes node@24)
    fi
    exec "${NODE_CMD[@]}" --experimental-wasm-jspi "${SCRIPT_DIR}/php-wasm-runner.mjs" "$@"
fi

MOUNT_ARGS=()

# Auto-mount any paths referenced in the arguments
for arg in "$@"; do
    case "$arg" in
        /*)
            # Extract the directory from the path
            dir=$(dirname "$arg")
            # Mount the top-level directory to avoid too many mounts
            top=$(echo "$dir" | cut -d/ -f1-3)
            if [ -d "$top" ] || [ -f "$arg" ]; then
                MOUNT_ARGS+=("--mount=${top}:${top}")
            fi
            ;;
    esac
done

# Always mount common paths
[ -d /tmp ] && MOUNT_ARGS+=("--mount=/tmp:/tmp")
[ -d /srv ] && MOUNT_ARGS+=("--mount=/srv:/srv")

# Deduplicate mount args
UNIQUE_MOUNTS=($(printf '%s\n' "${MOUNT_ARGS[@]}" | sort -u))

exec npx @wp-playground/cli php "${UNIQUE_MOUNTS[@]}" -- "$@"
