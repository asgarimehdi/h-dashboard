#!/usr/bin/env bash
# composer verify — local feedback that matches CI's test job before you push.
#
# 1. preflight  — fails fast (exit 2) if this machine cannot run the suite the
#                 way CI does, BEFORE anything destructive runs
# 2. caches     — `composer test` already clears config+routes; view:clear is
#                 the one CI does that composer test does not, so add it here
# 3. pint       — `pint --test`, the exact command CI's lint job runs
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
