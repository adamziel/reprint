#!/usr/bin/env bash
# Wrapper that invokes PHP through WordPress Playground CLI's WASM runtime.
# Used by e2e tests when PHP_BINARY points here instead of the system `php`.
#
# Mounts the host filesystem paths that the importer needs:
# - The workspace root (for reprint.phar and project files)
# - /tmp (for temporary test output)
# - /srv (for e2e test site data)
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

exec npx @wp-playground/cli php "${UNIQUE_MOUNTS[@]}" -- "$@"
