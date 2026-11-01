#!/usr/bin/env bash
# BMOS 3.2 Phase 2 — the six required isolation tests (guide Phase 4 gate).
# EVERY assertion runs as bmos_app, the same non-superuser role the app uses.
# (Superusers and BYPASSRLS roles bypass RLS, so testing as postgres would
# prove nothing.) Fixture setup uses the superuser only to insert rows;
# that bypass is by design and is labeled wherever it happens.
set -euo pipefail
set -o pipefail  # so a blocked INSERT inside app() still fails the if-test
cd "$(dirname "$0")/.."

set -a
[ -f .env ] && . ./.env
set +a
DB="${POSTGRES_DB:-bmos}"
SUPERUSER="${POSTGRES_USER:-bmos}"

APP_PW="$(cat secrets/app_password.txt)"
SUPER_PW="$(cat secrets/postgres_password.txt)"

app() {
  # tail -n 1: psql echoes "SET" for the context statement; the assertion
  # result is always the last line.
  docker compose exec -T -e "PGPASSWORD=$APP_PW" postgres \
    psql -U bmos_app -d "$DB" -tA -v ON_ERROR_STOP=1 -c "$1" | tail -n 1
}
super() {
  docker compose exec -T -e "PGPASSWORD=$SUPER_PW" postgres \
    psql -U "$SUPERUSER" -d "$DB" -tA -v ON_ERROR_STOP=1 -c "$1"
}

TENANT_A="11111111-1111-1111-1111-111111111111"
TENANT_B="22222222-2222-2222-2222-222222222222"
USER_A="aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa"
USER_X="aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"  # planted for the blocked-insert tests
USER_Y="ffffffff-1111-2222-3333-444444444444"  # planted for the blocked-insert tests

pass=0
fail=0
check() { # check <name> <expected> <actual>
  if [ "$2" = "$3" ]; then
    echo "PASS: $1"; pass=$((pass + 1))
  else
    echo "FAIL: $1 (expected [$2], got [$3])"; fail=$((fail + 1))
  fi
}

echo "--- fixtures (superuser: bypasses RLS by design; fixtures only, never assertions) ---"
super "INSERT INTO app.tenants(id, name) VALUES
         ('$TENANT_A', 'Tenant A'), ('$TENANT_B', 'Tenant B')
       ON CONFLICT (id) DO NOTHING;" > /dev/null
super "INSERT INTO app.users(id, email) VALUES
         ('$USER_A', 'a@example.test'),
         ('$USER_X', 'x@example.test'),
         ('$USER_Y', 'y@example.test')
       ON CONFLICT (id) DO NOTHING;" > /dev/null
super "INSERT INTO app.memberships(tenant_id, user_id, role) VALUES
         ('$TENANT_A', '$USER_A', 'owner'),
         ('$TENANT_B', '$USER_A', 'member')
       ON CONFLICT DO NOTHING;" > /dev/null

echo "--- test 6: assertions run as bmos_app (never as a superuser) ---"
check "6. test role is bmos_app" "bmos_app" "$(app "SELECT current_user;")"

echo "--- test 1: correct tenant can read its own row ---"
check "1. tenant A reads its own membership row" "1" \
  "$(app "SET app.current_tenant = '$TENANT_A'; SELECT count(*) FROM app.memberships;")"

echo "--- test 2: different tenant reads zero of another tenant's rows ---"
check "2. tenant B reads zero of tenant A's memberships" "0" \
  "$(app "SET app.current_tenant = '$TENANT_B'; SELECT count(*) FROM app.memberships WHERE tenant_id = '$TENANT_A';")"

echo "--- test 3: different tenant cannot insert another tenant's row ---"
if app "SET app.current_tenant = '$TENANT_B'; INSERT INTO app.memberships(tenant_id, user_id) VALUES ('$TENANT_A', '$USER_X');" >/dev/null 2>&1; then
  check "3. cross-tenant insert is blocked" "blocked" "allowed"
else
  check "3. cross-tenant insert is blocked" "blocked" "blocked"
fi

echo "--- test 4: different tenant cannot update or delete another tenant's row ---"
upd="$(app "SET app.current_tenant = '$TENANT_B'; UPDATE app.memberships SET role = 'admin' WHERE tenant_id = '$TENANT_A';" | tr -d '[:space:]')"
check "4a. cross-tenant update touches 0 rows" "UPDATE0" "$upd"
del="$(app "SET app.current_tenant = '$TENANT_B'; DELETE FROM app.memberships WHERE tenant_id = '$TENANT_A';" | tr -d '[:space:]')"
check "4b. cross-tenant delete touches 0 rows" "DELETE0" "$del"
role="$(app "SET app.current_tenant = '$TENANT_A'; SELECT role FROM app.memberships WHERE tenant_id = '$TENANT_A';")"
check "4c. tenant A's row is unchanged" "owner" "$role"

echo "--- test 5: missing tenant context fails closed ---"
check "5a. no context reads zero rows" "0" \
  "$(app "SELECT count(*) FROM app.memberships;")"
if app "INSERT INTO app.memberships(tenant_id, user_id) VALUES ('$TENANT_A', '$USER_Y');" >/dev/null 2>&1; then
  check "5b. no-context insert is blocked" "blocked" "allowed"
else
  check "5b. no-context insert is blocked" "blocked" "blocked"
fi

echo "--- result: $pass passed, $fail failed ---"
[ "$fail" -eq 0 ]
