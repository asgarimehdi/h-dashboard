#!/usr/bin/env bash
# Build .env.e2e from .env.e2e.example, copying secret VALUES straight from .env
# without ever printing them (the terminal masks output, so a read-and-rewrite
# approach would write literal "***" into the file).
set -euo pipefail
cd "$(dirname "$0")/.."

DEV=.env
E2E=.env.e2e
EXAMPLE=.env.e2e.example

[ -f "$DEV" ] || { echo "missing $DEV"; exit 1; }
[ -f "$EXAMPLE" ] || { echo "missing $EXAMPLE"; exit 1; }

# value_of KEY FILE  -> prints the raw value, nothing else
value_of() {
  grep -E "^$1=" "$2" | head -1 | cut -d= -f2-
}

cp "$EXAMPLE" "$E2E"

for key in APP_KEY DB_PASSWORD REDIS_PASSWORD; do
  val="$(value_of "$key" "$DEV")"
  if [ -n "$val" ] && [ "$val" != "***" ]; then
    # BSD/GNU sed both fine here: escape the delimiter and any '&'
    escaped=$(printf '%s' "$val" | sed -e 's/[\/&]/\\&/g')
    sed -i.bak "s|^${key}=.*|${key}=${escaped}|" "$E2E"
    rm -f "$E2E.bak"
  else
    echo "WARNING: no usable $key in $DEV"
  fi
done

# DB_USERNAME, if present in dev
dbuser="$(value_of DB_USERNAME "$DEV")"
if [ -n "$dbuser" ] && [ "$dbuser" != "***" ]; then
  escaped=$(printf '%s' "$dbuser" | sed -e 's/[\/&]/\\&/g')
  sed -i.bak "s|^DB_USERNAME=.*|DB_USERNAME=${escaped}|" "$E2E"
  rm -f "$E2E.bak"
fi

# Report only lengths / non-secret settings — never the values.
echo "--- .env.e2e written ---"
for key in APP_KEY DB_PASSWORD REDIS_PASSWORD; do
  v="$(value_of "$key" "$E2E")"
  echo "$key length: ${#v}"
done
echo "DB_DATABASE: $(value_of DB_DATABASE "$E2E")"
echo "APP_LOCALE:  $(value_of APP_LOCALE "$E2E")"
echo "TEST_N_CODE: $(value_of TEST_N_CODE "$E2E")"
