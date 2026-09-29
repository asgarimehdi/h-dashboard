#!/usr/bin/env bash
# Activate the versioned hooks in .githooks/ by pointing core.hooksPath at them.
#
# The previous mechanism was a symlink created by composer's post-install-cmd,
# which only appears on a FRESH `composer install`. On an already-provisioned
# machine the light pre-commit layer was therefore silently absent — the hook
# nobody had was still nobody's hook.
#
# Idempotent and never fatal: `composer install` runs this on CI, in containers
# and in worktrees where there is no repository to configure, and a missing
# repository must not fail the install.
#
# Usage: composer hooks:install
set -uo pipefail

readonly HOOKS_DIR=".githooks"

if [ ! -d "${HOOKS_DIR}" ]; then
    echo "⚠️  ${HOOKS_DIR}/ not found — skipping hook installation." >&2
    exit 0
fi

# A hook without its executable bit never fires under core.hooksPath, and the
# bit does not survive every copy or checkout.
chmod +x "${HOOKS_DIR}"/pre-commit "${HOOKS_DIR}"/pre-push 2>/dev/null || true

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "⚠️  Not a git repository — skipping hook installation." >&2
    exit 0
fi

if ! git config core.hooksPath "${HOOKS_DIR}"; then
    echo "⚠️  Could not set core.hooksPath — run 'git config core.hooksPath ${HOOKS_DIR}' manually." >&2
    exit 0
fi

echo "✅ Git hooks active (core.hooksPath = ${HOOKS_DIR})"
