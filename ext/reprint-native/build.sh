#!/usr/bin/env bash
# Build the reprint-native PHP extension.
#
# On standard Linux (Ubuntu/Debian): install libclang-dev and php-dev first:
#   sudo apt-get install -y libclang-dev php-dev
#   ./build.sh
#
# On NixOS the required headers live in the Nix store; this script locates them
# automatically using nix-store queries.
#
# Pass extra cargo arguments after --, e.g.: ./build.sh -- --release
set -euo pipefail

MODE="${1:---release}"   # --release or --debug
shift 2>/dev/null || true  # ignore "no arguments" error

cd "$(dirname "${BASH_SOURCE[0]}")"

PHP_CONFIG="${PHP_CONFIG:-$(which php-config 2>/dev/null || true)}"
if [ -z "$PHP_CONFIG" ]; then
  echo "ERROR: php-config not found. Install php-dev (Debian/Ubuntu) or set PHP_CONFIG." >&2
  exit 1
fi

export PHP_CONFIG

# ─── NixOS: auto-detect bindgen include paths ────────────────────────────────
if [ -d /nix/store ] && [ -z "${LIBCLANG_PATH:-}" ]; then
  echo "NixOS detected — locating libclang and glibc headers via nix-store..."

  # Find the already-built clang lib (avoids a rebuild if already present).
  CLANG_LIB=$(find /nix/store -maxdepth 1 -name "*clang*lib*" -type d 2>/dev/null \
    | grep -v source | head -1)
  if [ -z "$CLANG_LIB" ]; then
    CLANG_LIB=$(nix-build '<nixpkgs>' -A llvmPackages.libclang.lib --no-build-output 2>/dev/null)
  fi

  export LIBCLANG_PATH="${CLANG_LIB}/lib"

  # glibc and clang resource headers for bindgen.
  GLIBC_DEV=$(nix-build '<nixpkgs>' -A glibc.dev --no-build-output 2>/dev/null)
  CLANG_INC=$(find "${CLANG_LIB}/lib/clang" -name "stddef.h" 2>/dev/null \
    | head -1 | xargs dirname 2>/dev/null || true)

  if [ -n "$GLIBC_DEV" ] && [ -n "$CLANG_INC" ]; then
    export BINDGEN_EXTRA_CLANG_ARGS="-I${GLIBC_DEV}/include -I${CLANG_INC}"
  fi
fi

exec cargo build "$MODE" "$@"
