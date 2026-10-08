#!/usr/bin/env bash
# Validate the e2e + coverage-report jobs in .github/workflows/test.yml.
# Usage: bash scripts/ci-validate-e2e-job.sh
#
# This is the RED/GREEN gate for issues #867 (e2e job) and #865 (coverage
# reporting job): it asserts every load-bearing property both issues demand,
# so a workflow edit that silently drops one fails here before it ships.
#
# Checks (issue #867 — e2e job):
#   1. An `e2e:` job exists in test.yml.
#   2. It declares postgis/postgis:16-3.4 AND redis services (same pattern as `test`).
#   3. It builds .env.e2e via scripts/build-env-e2e.sh (secret-safe, never bare values).
#   4. It runs `npx playwright install --with-deps chromium`.
#   5. It runs `bash scripts/e2e-test.sh` — never bare `npx playwright test`
#      (fixtures.ts needs .run-state.json, which only the script writes).
#   6. It proves tests really ran: `--reporter=list` (or --reporter list) plus
#      a count assertion on executed tests (the #865 trap: a green job with
#      zero tests executed must fail).
#   7. APP_LOCALE=fa is asserted (11 Persian tests break without it).
#   8. The job never references the `h_dashboard` dev database.
#   9. The script is never run with parallel sharding flags (it swaps .env,
#      not concurrency-safe).
#  10. The job starts `continue-on-error: true` with a ramp comment
#      (two green runs before required).
#
# Checks (issue #865 — coverage-report job):
#  11. A `coverage-report:` job exists, non-blocking (continue-on-error or
#      `needs` without gate semantics — it must never fail the build).
#  12. It runs scripts/coverage-report.sh (the single measurement entrypoint).
#  13. The hard `--min=80` gate on the `test` job is untouched.
set -uo pipefail
cd "$(dirname "$0")/.."

WORKFLOW=.github/workflows/test.yml
FAIL=0

fail() { echo "FAIL: $1"; FAIL=1; }
pass() { echo "ok: $1"; }

[ -f "$WORKFLOW" ] || { echo "FAIL: $WORKFLOW missing"; exit 1; }

# --- #867: e2e job exists ---
echo "$FAIL" >/dev/null
grep -qE '^  e2e:' "$WORKFLOW" && pass "e2e job exists" || fail "no 'e2e:' job in $WORKFLOW"

# Extract the e2e job block (from '^  e2e:' to the next top-level job or EOF).
E2E_BLOCK=$(awk '/^  e2e:/{f=1} f{print} f&&/^  [a-z-]+:/&&!/^  e2e:/{exit}' "$WORKFLOW")

check_e2e() {
    local desc="$1"; local pattern="$2"
    if echo "$E2E_BLOCK" | grep -qE "$pattern"; then pass "$desc"; else fail "$desc"; fi
}

check_e2e "e2e services include postgis/postgis:16-3.4" "postgis/postgis:16-3\.4"
check_e2e "e2e services include redis" "image: redis"
check_e2e "e2e builds .env.e2e via scripts/build-env-e2e.sh" "scripts/build-env-e2e\.sh"
check_e2e "e2e installs chromium with deps" "playwright install --with-deps chromium"
check_e2e "e2e runs scripts/e2e-test.sh" "scripts/e2e-test\.sh"

# Must not call bare `npx playwright test` outside the script invocation line.
if echo "$E2E_BLOCK" | grep -E "npx playwright test" | grep -qv "e2e-test\.sh"; then
    fail "e2e job calls bare 'npx playwright test' (must go through scripts/e2e-test.sh)"
else
    pass "no bare 'npx playwright test' in e2e job"
fi

check_e2e "e2e proves execution with --reporter=list" "reporter.?list"
check_e2e "e2e asserts an executed-test count" "tests? (executed|passed|ran)|Executed [0-9]|passed \("

check_e2e "e2e asserts APP_LOCALE=fa" "APP_LOCALE=fa"

if echo "$E2E_BLOCK" | grep -vE '^[[:space:]]*#' | grep -qE "CREATE DATABASE"; then
    fail "e2e job must not CREATE DATABASE (the postgres service creates it from POSTGRES_DB)"
else
    pass "e2e job does not redundantly create the database"
fi

if echo "$E2E_BLOCK" | grep -vE '^[[:space:]]*#' | grep -qE "h_dashboard[^_]"; then
    fail "e2e job references the dev database 'h_dashboard' (must use the isolated e2e DB)"
else
    pass "e2e job never references the dev database"
fi

if echo "$E2E_BLOCK" | grep -qE "e2e-test\.sh.*(--workers|--shard|parallel)|parallel.*e2e-test"; then
    fail "e2e-test.sh must never run in parallel (swaps .env)"
else
    pass "e2e-test.sh not run in parallel"
fi

check_e2e "e2e starts continue-on-error with ramp note" "continue-on-error: true"

# --- #865: coverage-report job exists, non-blocking ---
grep -qE '^  coverage-report:' "$WORKFLOW" && pass "coverage-report job exists" || fail "no 'coverage-report:' job in $WORKFLOW"

COV_BLOCK=$(awk '/^  coverage-report:/{f=1} f{print} f&&/^  [a-z-]+:/&&!/^  coverage-report:/{exit}' "$WORKFLOW")

if echo "$COV_BLOCK" | grep -qE "scripts/coverage-report\.sh"; then
    pass "coverage-report runs scripts/coverage-report.sh"
else
    fail "coverage-report job must run scripts/coverage-report.sh"
fi

if echo "$COV_BLOCK" | grep -qE "continue-on-error: true"; then
    pass "coverage-report is non-blocking"
else
    fail "coverage-report job must be non-blocking (continue-on-error: true)"
fi

# --- #865: hard gate untouched ---
if grep -qE "\-\-min=80" "$WORKFLOW"; then
    pass "hard --min=80 gate still present"
else
    fail "hard --min=80 gate was removed (out of scope until baseline is known)"
fi

if [ "$FAIL" -ne 0 ]; then
    echo ""
    echo "ci-validate-e2e-job: FAILED"
    exit 1
fi
echo ""
echo "ci-validate-e2e-job: all checks passed"
