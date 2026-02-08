#!/usr/bin/env bash
# Sites are now self-provisioned by each test via lib/site-setup.js ensureSite().
# This script is kept as a no-op for backward compatibility with CI workflows
# that lack the 'workflow' token scope to update .github/workflows/e2e.yml.
# TODO: Remove this file and the "Setup test sites" step from e2e.yml.
echo "=== Site setup is now handled by tests (ensureSite) — nothing to do ==="
