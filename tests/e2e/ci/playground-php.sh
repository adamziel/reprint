#!/usr/bin/env bash
# Wrapper that invokes PHP through WordPress Playground CLI's WASM runtime.
# Used by e2e tests when PHP_BINARY points here instead of the system `php`.
#
# Mounts the host filesystem paths that the importer needs:
# - The workspace root (for reprint.phar and project files)
# - /tmp (for temporary test output)
# - /srv (for e2e test site data)
#
# Uses JSPI (JavaScript Promise Integration) for proper async networking.
# Without JSPI, the Asyncify-based curl crashes on gzip decompression.
set -euo pipefail

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

# Resolve the @wp-playground/cli entry point.
# Use node directly with --experimental-wasm-jspi to enable JSPI networking.
# NODE_OPTIONS doesn't allow this flag, so we must pass it to node directly.
CLI_PATH=$(node -e "console.log(require.resolve('@wp-playground/cli/cli.js'))" 2>/dev/null || true)
if [ -z "$CLI_PATH" ]; then
    CLI_PATH=$(npx --yes which @wp-playground/cli 2>/dev/null || echo "")
    if [ -z "$CLI_PATH" ]; then
        # Fallback: just use npx
        exec npx @wp-playground/cli php "${UNIQUE_MOUNTS[@]}" -- "$@"
    fi
fi

exec node --experimental-wasm-jspi "$CLI_PATH" php "${UNIQUE_MOUNTS[@]}" -- "$@"
