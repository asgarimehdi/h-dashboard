#!/usr/bin/env bash
# Build .env.testing for the CI test jobs (`test`, `mutation`, `test-php86`).
set -euo pipefail
cd "$(dirname "$0")/.."

cp .env.example .env.testing
# Match the CI service containers (postgres: postgres/secret/h_dashboard_test, redis: no password).
# CACHE_STORE must be non-shared: Laravel 13 reads CACHE_STORE (not the legacy
# CACHE_DRIVER in .env.example); leaving it on redis shares spatie's permission
# cache between parallel test processes and cross-pollutes their separate DBs.
sed -i 's/^DB_DATABASE=.*/DB_DATABASE=h_dashboard_test/; s/^DB_USERNAME=.*/DB_USERNAME=postgres/; s/^DB_PASSWORD=.*/DB_PASSWORD=secret/; s/^REDIS_PASSWORD=.*/REDIS_PASSWORD=/; s/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/' .env.testing
grep -q '^CACHE_STORE=' .env.testing || echo 'CACHE_STORE=array' >> .env.testing
