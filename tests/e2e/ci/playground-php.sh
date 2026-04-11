#!/usr/bin/env bash
# Wrapper that invokes PHP through WordPress Playground CLI's WASM runtime.
# Used by e2e tests when PHP_BINARY points here instead of the system `php`.
set -euo pipefail
exec npx @wp-playground/cli php -- "$@"
