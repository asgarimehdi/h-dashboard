#!/usr/bin/env bash
# composer verify — local feedback that matches CI's test job before you push.
#
# 1. preflight  — fails fast (exit 2) if this machine cannot run the suite the
#                 way CI does, BEFORE anything destructive runs
# 1b. APP_KEY   — fails if a tracked env file commits a real APP_KEY (#953)
# 2. caches     — `composer test` already clears config/routes; view:clear is
#                 the one CI does that composer test does not, so add it here
# 3. pint       — `pint --test`, the exact command CI's lint job runs
# 3b. dead files— no test file may run zero tests (issue #935)
# 4. phpstan    — level 6 with the committed baseline, the exact CI command
# 5. pest       — the full suite, or the paths given as arguments
#
# This reproduces the CI *test job*, not its coverage gate: CI also runs
# `--parallel --coverage --min=80`, which needs pcov and a parallel-capable
# machine. Coverage is deliberately not gated locally.
#
# Usage:
#   composer verify                     # everything
#   composer verify tests/Feature/X.php  # only the tests you touched
set -uo pipefail

cd "$(dirname "$0")/.."

# 0 = fine, 2 = the environment is not fit to run the suite.
readonly EXIT_PRECONDITION_FAILED=2

# Xdebug in develop mode makes every date error a fatal; AGENTS.md records it.
export XDEBUG_MODE=off

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

# 3. Code style, exactly as CI's lint job runs it.
step "Pint (code style)"
if ! vendor/bin/pint --test; then
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

# 4. Static analysis, exactly as CI's phpstan job runs it.
step "PHPStan (static analysis)"
if ! vendor/bin/phpstan analyse --no-progress; then
    echo ""
    echo "✗ PHPStan. See the errors above."
    exit 1
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
echo "✅ verify passed — this matches CI's test job. Coverage is still CI-only."
