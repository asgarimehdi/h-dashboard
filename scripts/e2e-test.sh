#!/bin/bash
# Run E2E tests with full isolation.
# Usage: bash scripts/e2e-test.sh [optional playwright args]
#
# Flow: setup → start server → run tests → stop server → teardown
#
# Teardown runs from a trap, not from the last line. `set -e` aborts the script
# on the first failing step, so restoring .env inline meant a failure anywhere
# in setup left .env swapped to the e2e database and the serve process alive —
# the next `composer verify` then preflighted the wrong database and every
# later command used the wrong credentials. Every exit path must restore both.
set -euo pipefail

cd "$(dirname "$0")/.."

SERVER_PID=""

teardown() {
    exit_code=$?

    # Stop the server first: killing it must not be able to abort teardown,
    # or the .env restore below would be skipped.
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
        echo "[e2e] Server stopped"
    fi

    # Restore .env last-but-one, before removing the backup it is copied from.
    if [ -f .env.dev.bak ]; then
        cp .env.dev.bak .env
        rm -f .env.dev.bak
        echo "[e2e] .env restored"
    fi

    rm -f tests/e2e/.run-state.json

    exit "$exit_code"
}

# EXIT (every exit) plus INT/TERM, so Ctrl-C does not strand the server.
trap teardown EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# 1. Swap .env
cp .env .env.dev.bak
cp .env.e2e .env
set -a; source .env.e2e; set +a
echo "[e2e] .env swapped to e2e config"

# 2. Clear caches
php artisan config:clear
php artisan route:clear

# 3. Fresh database + seed
php artisan migrate:fresh --seed --force

# 4. Create password-mutation user
RUN_ID=$(date +%s%N | cut -b1-13)
PWD_NCODE="9${RUN_ID: -9}"
UNIT_NAME="E2E-${RUN_ID}"
php tests/e2e/create-pwd-user.php "$PWD_NCODE" "$TEST_PASSWORD" "$UNIT_NAME"

# 5. Write run state
echo "{\"runId\":\"$RUN_ID\",\"pwdNCode\":\"$PWD_NCODE\"}" > tests/e2e/.run-state.json
echo "[e2e] Run state: runId=$RUN_ID pwdNCode=$PWD_NCODE"

# 6. Start server
php artisan serve --port=8001 &
SERVER_PID=$!
echo "[e2e] Server started (PID=$SERVER_PID)"

# 7. Wait for server
for i in $(seq 1 20); do
  if curl -s -o /dev/null http://localhost:8001/login 2>/dev/null; then
    echo "[e2e] Server ready"
    break
  fi
  sleep 1
done

# 8. Run playwright (skip global-setup since we already did it). The exit code
#    is captured without tripping `set -e`, then re-raised through the trap so
#    teardown runs exactly once.
if BASE_URL=http://localhost:8001 npx playwright test "$@" --config=playwright.config.ts; then
    exit 0
else
    exit $?
fi
