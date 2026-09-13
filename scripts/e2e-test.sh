#!/bin/bash
# Run E2E tests with full isolation.
# Usage: bash scripts/e2e-test.sh [optional playwright args]
#
# Flow: setup → start server → run tests → stop server → teardown
set -e

cd "$(dirname "$0")/.."

# 1. Swap .env
cp .env .env.dev.bak 2>/dev/null || true
cp .env.e2e .env
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

# 8. Run playwright (skip global-setup since we already did it)
BASE_URL=http://localhost:8001 npx playwright test "$@" --config=playwright.config.ts
TEST_EXIT=$?

# 9. Stop server
kill $SERVER_PID 2>/dev/null || true
echo "[e2e] Server stopped"

# 10. Teardown: restore .env, remove state
cp .env.dev.bak .env
rm -f .env.dev.bak tests/e2e/.run-state.json
echo "[e2e] .env restored, cleanup done"

exit $TEST_EXIT
