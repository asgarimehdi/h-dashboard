#!/usr/bin/env bash
# The four blocking JS checks from CI's `js-lint` and `js-typecheck` jobs (#1886).
# Single source of truth: `scripts/verify.sh` (--install) and `.githooks/pre-push`
# (--no-install) both call THIS file, so a CI change is made here once.
#
# Deliberately NOT reproduced: `npm audit --package-lock-only --audit-level=high`
# (test.yml:127). The advisory DB is live (docs/agents/testing.md §Dependency
# audits), so its verdict is not a function of the working tree, and it needs the
# registry — CI keeps it blocking, the local loop keeps it out.
set -uo pipefail

cd "$(dirname "$0")/.."

INSTALL=0
[ "${1:-}" = "--install" ] && INSTALL=1

command -v npm >/dev/null 2>&1 || {
    echo "✗ npm not found — install Node 24 (AGENTS.md → Tech Stack)."
    exit 1
}

# `npm ci` writes node_modules/.package-lock.json AFTER installing, so a healthy
# tree is not stale; any real change to package.json/package-lock.json moves their
# mtime forward, so the check errs toward installing, never toward skipping. The
# three binary probes catch a hand-mangled node_modules whose hidden lock survived.
needs_install() {
    [ ! -f node_modules/.package-lock.json ] \
        || [ package.json -nt node_modules/.package-lock.json ] \
        || [ package-lock.json -nt node_modules/.package-lock.json ] \
        || [ ! -x node_modules/.bin/eslint ] \
        || [ ! -x node_modules/.bin/prettier ] \
        || [ ! -x node_modules/.bin/tsc ]
}

if needs_install; then
    if [ "$INSTALL" -eq 1 ]; then
        echo "  node_modules missing or older than package.json/package-lock.json — npm ci"
        npm ci --prefer-offline --no-audit --no-fund || { echo "✗ npm ci failed."; exit 1; }
    else
        echo "⚠ JS dependencies missing or stale — skipping the JS gates."
        echo "  Run \`composer verify\` (or \`npm ci\`) and push again."
        exit 0
    fi
fi

run() {
    echo "  \$ $*"
    "$@" || { echo "✗ JS gate failed: $*"; exit 1; }
}

run npm run lint
run npm run format:check
run npx --no-install tsc -p tsconfig.js.json --noEmit

# Ratchet: every resources/js/*.js must opt in (test.yml:404 — identical command
# and identical scope: top-level files only; resources/js/types/*.d.ts is out).
offenders="$(grep -L '^// @ts-check' resources/js/*.js)"
if [ -n "$offenders" ]; then
    echo "✗ resources/js file without // @ts-check:"
    printf '%s\n' "$offenders"
    exit 1
fi

echo "✅ JS gates clean (lint, format:check, tsc -p tsconfig.js.json, @ts-check ratchet)."
