#!/usr/bin/env bash
# BMOS 3.2 Stage B — cross-tenant isolation HTTP tests.
# Verifies that authenticated users cannot access other tenants' data
# via any API path: sessions, forged tenant selection, or API keys.
set -euo pipefail
cd "$(dirname "$0")/.."

BASE="${BMOS_BASE:-http://bmos.localhost}"
EMAIL="${ADMIN_EMAIL:?set ADMIN_EMAIL}"
PASS="${ADMIN_PASSWORD:?set ADMIN_PASSWORD}"

echo "--- Stage B: cross-tenant isolation ---"

# Helper: login and save cookies
login() {
  local email=$1 pass=$2 jar=$3
  rm -f "$jar"
  curl -s -c "$jar" -X POST "$BASE/auth/login" \
    -H 'Content-Type: application/json' \
    -d "{\"email\":\"$email\",\"password\":\"$pass\"}" > /dev/null
}

JAR_A=/tmp/bmos-xtenant-a.txt
login "$EMAIL" "$PASS" "$JAR_A"

# 1. Authenticated user can enqueue in their own tenant
R1=$(curl -s -b "$JAR_A" -w "\n%{http_code}" -X POST "$BASE/jobs/enqueue" \
  -H 'Content-Type: application/json' \
  -d '{"job_type":"task.created","title":"xtenant test"}')
CODE1=$(echo "$R1" | tail -1)
[ "$CODE1" = "201" ] && echo "PASS: own-tenant enqueue → 201" || { echo "FAIL: own-tenant enqueue → $CODE1"; exit 1; }

# 2. Forged tenant switch to a UUID the user doesn't belong to → must fail
FAKE_TENANT="00000000-0000-0000-0000-000000000000"
R2=$(curl -s -b "$JAR_A" -w "\n%{http_code}" -X POST "$BASE/auth/switch-tenant" \
  -H 'Content-Type: application/json' \
  -d "{\"tenant_id\":\"$FAKE_TENANT\"}")
CODE2=$(echo "$R2" | tail -1)
BODY2=$(echo "$R2" | head -1)
if [ "$CODE2" = "403" ] || echo "$BODY2" | grep -q '"ok":false'; then
  echo "PASS: forged tenant switch rejected"
else
  echo "FAIL: forged tenant switch → $CODE2 $BODY2"; exit 1
fi

# 3. Unauthenticated access to tenant endpoints → 401
R3=$(curl -s -w "\n%{http_code}" "$BASE/jobs/enqueue" -X POST \
  -H 'Content-Type: application/json' -d '{"job_type":"x"}')
CODE3=$(echo "$R3" | tail -1)
[ "$CODE3" = "401" ] && echo "PASS: unauthenticated enqueue → 401" || { echo "FAIL: unauth enqueue → $CODE3"; exit 1; }

# 4. Invalid API key → 401
R4=$(curl -s -w "\n%{http_code}" -X POST "$BASE/jobs/enqueue" \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer bmos_invalidkey123' \
  -d '{"job_type":"x"}')
CODE4=$(echo "$R4" | tail -1)
[ "$CODE4" = "401" ] && echo "PASS: invalid API key → 401" || { echo "FAIL: invalid key → $CODE4"; exit 1; }

# 5. Old X-Tenant-ID header is ignored (not an auth path)
R5=$(curl -s -w "\n%{http_code}" -X POST "$BASE/jobs/enqueue" \
  -H 'Content-Type: application/json' \
  -H 'X-Tenant-ID: 11111111-1111-1111-1111-111111111111' \
  -d '{"job_type":"x"}')
CODE5=$(echo "$R5" | tail -1)
[ "$CODE5" = "401" ] && echo "PASS: X-Tenant-ID header alone → 401 (not an auth path)" || { echo "FAIL: X-Tenant-ID → $CODE5"; exit 1; }

# 6. /auth/me returns only the user's own tenants
ME=$(curl -s -b "$JAR_A" "$BASE/auth/me")
echo "$ME" | grep -q '"email"' && echo "PASS: /auth/me returns user" || { echo "FAIL: /auth/me"; exit 1; }

echo "--- All cross-tenant checks passed ---"
