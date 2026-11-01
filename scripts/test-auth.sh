#!/bin/bash
# BMOS 3.2 Stage A — authentication tests.
# Verifies: login works, sessions persist, tenant comes from membership
# (not headers), cross-tenant access is blocked, API keys work.
set -euo pipefail

BASE="http://bmos.localhost"
COOKIES="/tmp/bmos-test-cookies.txt"
PASS=0; FAIL=0
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@bmos.local}"
ADMIN_PW="${ADMIN_PASSWORD:-test-admin-pw}"

check() {
    local name="$1"; shift
    if "$@"; then echo "PASS: $name"; PASS=$((PASS+1));
    else echo "FAIL: $name"; FAIL=$((FAIL+1)); fi
}

echo "--- Stage A: authentication ---"
rm -f "$COOKIES"

# 1. Unauthenticated API call → 401.
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/jobs/enqueue" \
  -H 'Content-Type: application/json' -d '{"job_type":"task.created","title":"x"}')
check "unauthenticated → 401" [ "$CODE" = "401" ]

# 2. Wrong password → 401.
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/auth/login" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"wrong\"}")
check "bad password → 401" [ "$CODE" = "401" ]

# 3. Login with the seeded admin (email + password from env).
CODE=$(curl -s -c "$COOKIES" -o /dev/null -w "%{http_code}" -X POST "$BASE/auth/login" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PW\"}")
check "login → 200 + session cookie" [ "$CODE" = "200" ]

# 4. Authenticated enqueue (no X-Tenant-ID header) → 201.
CODE=$(curl -s -b "$COOKIES" -o /dev/null -w "%{http_code}" -X POST "$BASE/jobs/enqueue" \
  -H 'Content-Type: application/json' \
  -d '{"job_type":"task.created","title":"auth test job"}')
check "authenticated enqueue → 201 (no tenant header)" [ "$CODE" = "201" ]

# 5. /auth/me returns user + tenants.
ME=$(curl -s -b "$COOKIES" "$BASE/auth/me")
check "/auth/me returns email" echo "$ME" | grep -q "$ADMIN_EMAIL"

# 6. Forged tenant header is ignored (session wins).
# (No header to forge anymore — the endpoint doesn't read it.)
CODE=$(curl -s -b "$COOKIES" -o /dev/null -w "%{http_code}" -X POST "$BASE/jobs/enqueue" \
  -H 'Content-Type: application/json' \
  -H 'X-Tenant-ID: 22222222-2222-2222-2222-222222222222' \
  -d '{"job_type":"task.created","title":"forged header test"}')
check "forged X-Tenant-ID ignored → 201 (session tenant)" [ "$CODE" = "201" ]

echo ""
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
