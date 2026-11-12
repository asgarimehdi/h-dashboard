#!/usr/bin/env bash
# composer verify — local feedback that matches CI's test job before you push.
#
# 1. preflight  — fails fast (exit 2) if this machine cannot run the suite the
#                 way CI does, BEFORE anything destructive runs
# 1b. APP_KEY   — fails if a tracked env file commits a real APP_KEY (#953)
# 2. caches     — `composer test` already clears config/routes; view:clear is
#                 the one CI does that composer test does not, so add it here
# 3. pint       — `pint --test`, the exact command CI's lint job runs
#                 (--fast narrows it to the paths given on the command line)
# 3b. dead files— no test file may run zero tests (issue #935)
# 3c. lookup seed— no test file may hand-roll lookup seeding (issue #1044)
# 4. phpstan    — level 6 with the committed baseline, the exact CI command
#                 (--fast SKIPS this; the banner says so)
# 5. pest       — the full suite, or the paths given as arguments
#
# This reproduces the CI *test job*, not its coverage gate: CI also runs
# `--parallel --coverage --min=80`, which needs pcov and a parallel-capable
# machine. Coverage is deliberately not gated locally.
#
# Usage:
#   composer verify                      # everything (full gates)
#   composer verify tests/Feature/X.php  # full gates; only the TEST step is narrowed
#   composer verify:fast tests/Feature/X.php
#                                        # LOCAL TDD LOOP: Pint on the named paths
#                                        # only, PHPStan SKIPPED — NOT CI-equivalent
set -uo pipefail

cd "$(dirname "$0")/.."

# 0 = fine, 2 = the run cannot proceed as asked (bad environment or bad usage).
readonly EXIT_PRECONDITION_FAILED=2

# Xdebug in develop mode makes every date error a fatal; AGENTS.md records it.
export XDEBUG_MODE=off

# ── Fast path (issue #1416) ─────────────────────────────────────────────────
# `--fast` is LOCAL ONLY: Pint is narrowed to the paths named after it and the
# full-project PHPStan gate is skipped. It is never the default and CI never
# runs this script. Parsed as the FIRST argument and removed, so the remaining
# paths still reach `composer test -- "$@"` unchanged.
FAST=0
if [ "${1:-}" = "--fast" ]; then
    FAST=1
    shift
fi

if [ "$FAST" -eq 1 ] && [ "$#" -eq 0 ]; then
    echo "✗ --fast needs at least one path — a narrowed run with nothing named is a full run in disguise." >&2
    echo "  Try: composer verify:fast tests/Feature/TodoLivewireTest.php" >&2
    exit "$EXIT_PRECONDITION_FAILED"
fi

if [ "$FAST" -eq 1 ]; then
    echo ""
    echo "⚡ FAST MODE — LOCAL ONLY, NOT CI-EQUIVALENT"
    echo "   Pint:    ${*}"
    echo "   PHPStan: SKIPPED"
    echo "   Run \`composer verify\` (no --fast) before pushing."
fi

# Capture the shell environment NOW, before the first php process boots Laravel
# and putenv's every .env value into the process environment. Without this the
# preflight cannot tell a developer's export from the app's own .env, and would
# report "wrong database" on a machine that is configured perfectly.
export VERIFY_SHELL_ENV="${VERIFY_SHELL_ENV:-$(env -0 | php -r 'echo json_encode(array_filter($_SERVER, "is_string", ARRAY_FILTER_USE_KEY));')}"

step() {
    echo ""
    echo "── $1"
}

# 1. Preflight. Runs first and alone: a wrong or missing database would
#    otherwise surface as failures scattered through otherwise green tests,
#    and `migrate:fresh` against a mis-resolved database destroys real data.
step "Environment preflight"
php artisan verify:preflight
preflight_status=$?

if [ "$preflight_status" -ne 0 ]; then
    echo ""
    echo "✗ Preflight failed (exit ${preflight_status}). Nothing was run, nothing was touched."
    echo "  Fix the environment above, then run composer verify again."
    exit "$preflight_status"
fi

# 1b. Committed APP_KEY guard (issue #953). Runs before every expensive gate
#     below, because a leaked master secret is the one finding that must not
#     wait five minutes for Pint and PHPStan to finish first. Cheap, and it
#     needs no database — but it reads the git index, so it is a repository
#     check, not an environment one.
step "Committed APP_KEY"
if ! php scripts/check-app-key-leak.php; then
    echo ""
    echo "✗ Committed APP_KEY. See the list above."
    exit 1
fi

# 2. Caches. `composer test` clears config and routes on its own; a stale
#    bootstrap/cache/routes-v7.php makes every Livewire ->set()/->call() silently
#    no-op, which reads as ~75 unrelated failures.
step "Clearing caches"
php artisan view:clear

# 3. Code style. The default is CI's lint job exactly: `pint --test` over the
#    whole repo. --fast narrows it to the paths given on the command line and
#    nothing else (a directory argument still recurses — Pint's own behaviour).
step "Pint (code style)"
pint_paths=()
if [ "$FAST" -eq 1 ]; then
    pint_paths=("$@")
fi
if ! vendor/bin/pint --test ${pint_paths[@]+"${pint_paths[@]}"}; then
    echo ""
    echo "✗ Code style. Fix with: composer pint"
    exit 1
fi

# 3b. Dead test files (issue #935). `TicketWorkflowTest.php` ran ZERO tests for
# a year — its methods lacked the `test` prefix, PHPUnit collected none of them,
# and every gate stayed green. Cheap to check, so it runs here rather than only
# in CI.
step "Dead test files"
if ! php scripts/find-dead-test-files.php; then
    echo ""
    echo "✗ Dead test files. See the list above."
    exit 1
fi

# 3c. Hand-rolled lookup seeding (issue #1044). 52 Feature tests used to seed
# the tahsils/estekhdams/semats/radifs lookup tables by hand, skipping the
# Postgres sequence resync — the first factory insert then collided with id=1.
# They now call InteractsWithTestSetup::seedLookupTables(); this ratchet keeps
# the migration at zero. Cheap, and it needs no database.
step "Hand-rolled lookup seeding"
if ! php scripts/find-hand-rolled-lookup-seeding.php; then
    echo ""
    echo "✗ Hand-rolled lookup seeding. Use InteractsWithTestSetup::seedLookupTables()."
    exit 1
fi

# 4. Static analysis, exactly as CI's phpstan job runs it — on the default
#    path only. --fast skips it by design; that is why the banner exists.
if [ "$FAST" -eq 1 ]; then
    step "PHPStan (static analysis) — SKIPPED (--fast, local only)"
else
    step "PHPStan (static analysis)"
    if ! vendor/bin/phpstan analyse --no-progress; then
        echo ""
        echo "✗ PHPStan. See the errors above."
        exit 1
    fi
fi

# 5. Tests. `composer test` rather than a bare pest call: it clears config and
#    routes, which step 2 depends on and which CI does explicitly.
if [ "$#" -gt 0 ]; then
    step "Tests (${*})"
else
    step "Tests (full suite)"
fi

if ! composer test -- "$@"; then
    echo ""
    echo "✗ Tests. See the failures above."
    exit 1
fi

echo ""
if [ "$FAST" -eq 1 ]; then
    echo "⚡ verify:fast passed — PHPStan was SKIPPED and Pint only saw: $*"
    echo "   NOT CI-equivalent. Run \`composer verify\` before pushing."
else
    echo "✅ verify passed — this matches CI's test job. Coverage is still CI-only."
fi
