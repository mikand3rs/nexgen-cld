#!/usr/bin/env bash
# BMOS 3.2 CRM 1.0 — functional + isolation tests.
# Part 1: HTTP tests as an authenticated tenant user (session cookie).
# Part 2: DB-level tenant-isolation tests as bmos_app (RLS enforced).
# Requires: ADMIN_EMAIL, ADMIN_PASSWORD (same as other test scripts).
# Cleanup: all test rows are tagged 'zz-crm-test' and removed at the end.
set -euo pipefail
cd "$(dirname "$0")/.."

BASE="${BMOS_BASE:-http://bmos.localhost}"
EMAIL="${ADMIN_EMAIL:?set ADMIN_EMAIL}"
PASS="${ADMIN_PASSWORD:?set ADMIN_PASSWORD}"
TAG="zz-crm-test"

set -a
[ -f .env ] && . ./.env
set +a
DB="${POSTGRES_DB:-bmos}"
SUPERUSER="${POSTGRES_USER:-bmos}"
APP_PW="$(cat secrets/app_password.txt)"
SUPER_PW="$(cat secrets/postgres_password.txt)"

pass=0
fail=0
check() { # check <name> <expected> <actual>
  if [ "$2" = "$3" ]; then
    echo "PASS: $1"; pass=$((pass + 1))
  else
    echo "FAIL: $1 (expected [$2], got [$3])"; fail=$((fail + 1))
  fi
}

app() { # app <sql> — run as bmos_app with a tenant context; last line is the result
  docker compose exec -T -e "PGPASSWORD=$APP_PW" postgres \
    psql -U bmos_app -d "$DB" -tA -v ON_ERROR_STOP=1 -c "$1" | tail -n 1
}
super() {
  docker compose exec -T -e "PGPASSWORD=$SUPER_PW" postgres \
    psql -U "$SUPERUSER" -d "$DB" -tA -v ON_ERROR_STOP=1 -c "$1"
}

echo "=== CRM 1.0: HTTP tests ==="
JAR=/tmp/bmos-crm-test-cookies.txt
rm -f "$JAR"
curl -s -c "$JAR" -X POST "$BASE/auth/login" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" > /dev/null
grep -q "PHPSESSID" "$JAR" || { echo "FAIL: login"; exit 1; }
echo "PASS: login"

# 1. Unauthenticated → 401
C1=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/crm/leads/list")
check "unauthenticated /crm/leads/list → 401" "401" "$C1"

# 2. Create lead
R2=$(curl -s -b "$JAR" -w "\n%{http_code}" -X POST "$BASE/crm/leads/create" \
  --data-urlencode "name=$TAG Lead One" \
  --data-urlencode "email=lead1@example.com" \
  --data-urlencode "company_name=$TAG Corp")
C2=$(echo "$R2" | tail -1)
check "create lead → 200" "200" "$C2"
echo "$R2" | grep -q "$TAG Lead One" && echo "PASS: lead appears in list" && pass=$((pass+1)) \
  || { echo "FAIL: lead appears in list"; fail=$((fail+1)); }

# 3. XSS is escaped in list output
curl -s -b "$JAR" -X POST "$BASE/crm/leads/create" \
  --data-urlencode "name=<script>alert('$TAG')</script>" > /dev/null
L3=$(curl -s -b "$JAR" "$BASE/crm/leads/list")
if echo "$L3" | grep -q "&lt;script&gt;"; then
  echo "PASS: XSS escaped (&lt;script&gt;)"; pass=$((pass+1))
else
  echo "FAIL: XSS not escaped"; fail=$((fail+1))
fi
if echo "$L3" | grep -q "<script>alert"; then
  echo "FAIL: raw <script> in output"; fail=$((fail+1))
else
  echo "PASS: no raw <script> in output"; pass=$((pass+1))
fi

# 4. SQL injection attempt is stored literally, table survives
curl -s -b "$JAR" -X POST "$BASE/crm/leads/create" \
  --data-urlencode "name='; DROP TABLE app.crm_leads; -- $TAG" > /dev/null
T4=$(super "SELECT COUNT(*) FROM app.crm_leads;" | tail -n 1)
[ "$T4" -ge 3 ] && echo "PASS: table survives injection (rows=$T4)" && pass=$((pass+1)) \
  || { echo "FAIL: table damaged"; fail=$((fail+1)); }

# 5. Lead detail page renders
LEAD_ID=$(super "SELECT id FROM app.crm_leads WHERE name='$TAG Lead One' LIMIT 1;" | tail -n 1)
C5=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$BASE/crm/leads/$LEAD_ID")
check "lead detail → 200" "200" "$C5"

# 6. Status update
C6=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X POST "$BASE/crm/leads/$LEAD_ID/status" \
  --data-urlencode "status=qualified")
check "status update → 200" "200" "$C6"

# 7. Convert lead (transactional) + idempotency
R7=$(curl -s -b "$JAR" -X POST "$BASE/crm/leads/$LEAD_ID/convert" -d "")
echo "$R7" | grep -q '"ok":true' && echo "PASS: convert → ok" && pass=$((pass+1)) \
  || { echo "FAIL: convert → $R7"; fail=$((fail+1)); }
ACCOUNT_ID=$(echo "$R7" | grep -o '"account_id":"[^"]*"' | cut -d'"' -f4)
CONTACT_ID=$(echo "$R7" | grep -o '"contact_id":"[^"]*"' | cut -d'"' -f4)
[ -n "$ACCOUNT_ID" ] && [ -n "$CONTACT_ID" ] \
  && { echo "PASS: convert returned account+contact ids"; pass=$((pass+1)); } \
  || { echo "FAIL: convert missing ids"; fail=$((fail+1)); }
# Second convert must fail (idempotent — no duplicates)
C7b=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X POST "$BASE/crm/leads/$LEAD_ID/convert" -d "")
check "double convert → 400" "400" "$C7b"
# Exactly one account/contact pair from this conversion
N7=$(super "SELECT COUNT(*) FROM app.crm_accounts WHERE name='$TAG Corp';" | tail -n 1)
check "no duplicate account on double convert" "1" "$N7"

# 8. Account detail shows the linked contact
A8=$(curl -s -b "$JAR" "$BASE/crm/accounts/$ACCOUNT_ID")
echo "$A8" | grep -q "$TAG Lead One" \
  && { echo "PASS: account detail shows converted contact"; pass=$((pass+1)); } \
  || { echo "FAIL: account detail missing contact"; fail=$((fail+1)); }

# 9. Cross-tenant link attempt: random UUID not in this tenant → 400
FAKE="00000000-0000-0000-0000-000000000000"
C9=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X POST "$BASE/crm/contacts/link" \
  --data-urlencode "contact_id=$FAKE" --data-urlencode "account_id=$ACCOUNT_ID")
check "link with foreign UUID → 400" "400" "$C9"

# 10. Notes refuse credential material
C10=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X POST "$BASE/crm/notes/create" \
  --data-urlencode "entity_type=lead" --data-urlencode "entity_id=$LEAD_ID" \
  --data-urlencode "body=password: hunter2")
check "note with password → 400" "400" "$C10"
# Legit note works
C10b=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X POST "$BASE/crm/notes/create" \
  --data-urlencode "entity_type=lead" --data-urlencode "entity_id=$LEAD_ID" \
  --data-urlencode "body=$TAG legitimate note")
check "legit note → 200" "200" "$C10b"

# 11. Activities: create overdue follow-up, verify overdue filter, complete it
curl -s -b "$JAR" -X POST "$BASE/crm/activities/create" \
  --data-urlencode "type=followup" --data-urlencode "subject=$TAG overdue call" \
  --data-urlencode "due_at=2020-01-01T09:00" > /dev/null
O11=$(curl -s -b "$JAR" "$BASE/crm/activities/list?overdue=1")
echo "$O11" | grep -q "$TAG overdue call" \
  && { echo "PASS: overdue filter finds it"; pass=$((pass+1)); } \
  || { echo "FAIL: overdue filter"; fail=$((fail+1)); }
ACT_ID=$(super "SELECT id FROM app.crm_activities WHERE subject='$TAG overdue call' LIMIT 1;" | tail -n 1)
curl -s -b "$JAR" -X POST "$BASE/crm/activities/$ACT_ID/complete" > /dev/null
O11b=$(curl -s -b "$JAR" "$BASE/crm/activities/list?overdue=1")
echo "$O11b" | grep -q "$TAG overdue call" \
  && { echo "FAIL: completed activity still overdue"; fail=$((fail+1)); } \
  || { echo "PASS: completed activity leaves overdue list"; pass=$((pass+1)); }

# 12. Search is tenant-scoped and finds the lead
S12=$(curl -s -b "$JAR" "$BASE/crm/search?q=$TAG%20Lead%20One")
echo "$S12" | grep -q "$TAG Lead One" \
  && { echo "PASS: search finds lead"; pass=$((pass+1)); } \
  || { echo "FAIL: search"; fail=$((fail+1)); }

# 13. CRM overview page renders
C13=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$BASE/crm")
check "GET /crm → 200" "200" "$C13"

echo ""
echo "=== CRM 1.0: DB isolation tests (as bmos_app) ==="
TENANT_A="11111111-1111-1111-1111-111111111111"
TENANT_B="22222222-2222-2222-2222-222222222222"
super "INSERT INTO app.tenants(id, name) VALUES ('$TENANT_A','Tenant A'), ('$TENANT_B','Tenant B') ON CONFLICT (id) DO NOTHING;" > /dev/null

CTX_A="SET app.current_tenant = '$TENANT_A';"
CTX_B="SET app.current_tenant = '$TENANT_B';"

# 14. RLS enabled + forced on every CRM table
for t in crm_leads crm_accounts crm_contacts crm_account_contacts crm_pipelines crm_stages crm_opportunities crm_activities crm_activity_links crm_notes crm_tags crm_record_tags audit_events; do
  R=$(app "$CTX_A SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE relname='$t';")
  check "RLS forced on app.$t" "t" "$R"
done

# 15. Tenant B cannot see tenant A's lead (record-ID substitution blocked by RLS)
LEAD_A=$(app "$CTX_A INSERT INTO app.crm_leads(tenant_id, name) VALUES ('$TENANT_A', '$TAG iso') RETURNING id;")
SEEN_B=$(app "$CTX_B SELECT COUNT(*) FROM app.crm_leads WHERE id='$LEAD_A';")
check "tenant B cannot SELECT tenant A lead" "0" "$SEEN_B"

# 16. Cross-tenant FK: tenant B cannot link its contact to tenant A's account
ACC_A=$(app "$CTX_A INSERT INTO app.crm_accounts(tenant_id, name) VALUES ('$TENANT_A', '$TAG iso acct') RETURNING id;")
CON_B=$(app "$CTX_B INSERT INTO app.crm_contacts(tenant_id, first_name) VALUES ('$TENANT_B', '$TAG') RETURNING id;")
FK16=$(app "$CTX_B INSERT INTO app.crm_account_contacts(tenant_id, account_id, contact_id) VALUES ('$TENANT_B', '$ACC_A', '$CON_B');" 2>&1 | tail -n 1 || true)
if echo "$FK16" | grep -qi "violates foreign key"; then
  echo "PASS: cross-tenant account link rejected by FK"; pass=$((pass+1))
else
  # RLS on the parent read may block instead — either rejection is correct
  CNT16=$(app "$CTX_B SELECT COUNT(*) FROM app.crm_account_contacts WHERE account_id='$ACC_A';")
  check "cross-tenant account link not created" "0" "$CNT16"
fi

# 17. Tenant B cannot write into tenant A's tables (WITH CHECK)
W17=$(app "$CTX_B INSERT INTO app.crm_leads(tenant_id, name) VALUES ('$TENANT_A', '$TAG sneak');" 2>&1 | tail -n 1 || true)
if echo "$W17" | grep -qi "violates row-level security\|new row violates"; then
  echo "PASS: cross-tenant INSERT rejected by RLS WITH CHECK"; pass=$((pass+1))
else
  echo "FAIL: cross-tenant INSERT not rejected ($W17)"; fail=$((fail+1))
fi

# 18. Notes/activity links cannot reference another tenant's records (PHP assertEntity is RLS-scoped;
#     DB-level: polymorphic links have no FK, so verify RLS hides the target)
N18=$(app "$CTX_B SELECT COUNT(*) FROM app.crm_notes WHERE entity_id='$LEAD_A';")
check "tenant B sees no notes on tenant A lead" "0" "$N18"

echo ""
echo "=== cleanup ==="
super "DELETE FROM app.crm_activities WHERE subject LIKE '$TAG%';" > /dev/null
super "DELETE FROM app.crm_notes WHERE body LIKE '$TAG%';" > /dev/null
super "DELETE FROM app.crm_account_contacts WHERE tenant_id IN ('$TENANT_A','$TENANT_B');" > /dev/null
super "DELETE FROM app.crm_leads WHERE name LIKE '%$TAG%';" > /dev/null
super "DELETE FROM app.crm_accounts WHERE name LIKE '%$TAG%';" > /dev/null
super "DELETE FROM app.crm_contacts WHERE first_name='$TAG';" > /dev/null
echo "test rows removed"

echo ""
echo "crm: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
