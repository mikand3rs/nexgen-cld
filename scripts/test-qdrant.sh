#!/usr/bin/env bash
# BMOS 3.2 Phase 6 — Qdrant as a derived index: the guide's gates.
#
# Gate (guide, Phase 6):
#   - Every query includes a server-generated tenant filter.
#   - A forged client tenant ID is ignored.
#   - Deleting and rebuilding Qdrant does not destroy business records.
#   - Cross-tenant retrieval tests return no foreign points.
#
# Prerequisites: compose up -d (qdrant running), scripts/migrate.sh applied.
set -euo pipefail
set -o pipefail
cd "$(dirname "$0")/.."

# Stage B: session auth (replaces X-Tenant-ID demo header).
JAR=$(bash scripts/test-auth-helper.sh) || exit 1

set -a
[ -f .env ] && . ./.env
set +a
DB="${POSTGRES_DB:-bmos}"
COLLECTION="${QDRANT_COLLECTION:-bmos_chunks}"

pass=0
fail=0
check() { # check <name> <expected> <actual>
  if [ "$2" = "$3" ]; then
    echo "PASS: $1"; pass=$((pass + 1))
  else
    echo "FAIL: $1 (expected [$2], got [$3])"; fail=$((fail + 1))
  fi
}

TENANT_A="11111111-1111-1111-1111-111111111111"
TENANT_B="22222222-2222-2222-2222-222222222222"

jget() {
  python3 -c "import json,sys
try:
    d = json.load(sys.stdin)
    v = d
    for k in '$2'.split('.'):
        v = v.get(k, '') if isinstance(v, dict) else ''
    print(v if not isinstance(v, (dict, list)) else json.dumps(v))
except Exception:
    print('')" <<<"$1" 2>/dev/null
}

echo "--- setup: migrate, init collection ---"
bash scripts/migrate.sh > /dev/null
docker compose exec -T php php scripts/qdrant-init.php

echo "--- fixtures: documents for tenant A and B ---"
# Tenant fixtures (idempotent).
docker compose exec -T -e "PGPASSWORD=$(cat secrets/postgres_password.txt)" postgres \
  psql -U "${POSTGRES_USER:-bmos}" -d "$DB" -v ON_ERROR_STOP=1 -c \
  "INSERT INTO app.tenants(id, name) VALUES ('$TENANT_A','Tenant A'), ('$TENANT_B','Tenant B')
   ON CONFLICT (id) DO NOTHING;" > /dev/null

# Distinct content per tenant so cross-tenant leakage is detectable.
RA="$(curl -s -X POST http://bmos.localhost/documents -b "$JAR" -H 'Content-Type: application/json' \
  -d '{"title":"A doc","chunks":["alpha bravo charlie tenant A secret recipe","delta echo foxtrot tenant A internal notes"]}')"
RB="$(curl -s -X POST http://bmos.localhost/documents -b "$JAR" -H 'Content-Type: application/json' \
  -d '{"title":"B doc","chunks":["zulu yankee xray tenant B confidential plan","whiskey victor uniform tenant B private memo"]}')"
DA="$(jget "$RA" document_id)"; DBB="$(jget "$RB" document_id)"
check "setup: doc A created" "36" "${#DA}"
check "setup: doc B created" "36" "${#DBB}"

echo "--- index into Qdrant ---"
docker compose exec -T php php scripts/index-documents.php --tenant "$TENANT_A" > /dev/null
docker compose exec -T php php scripts/index-documents.php --tenant "$TENANT_B" > /dev/null
COUNT="$(docker compose exec -T php php -r "
require '/var/www/html/src/Qdrant.php';
echo (new Qdrant())->count('$COLLECTION');")"
check "index: 4 points (2 chunks x 2 tenants)" "4" "$COUNT"

echo "--- gate: tenant-filtered search ---"
# Search for A's distinctive content as tenant A.
SA="$(curl -s -X POST http://bmos.localhost/search -b "$JAR" -H 'Content-Type: application/json' \
  -d '{"query":"alpha bravo charlie secret recipe","limit":5}')"
# Every returned hit must belong to tenant A (server-generated filter).
LEAK_A="$(python3 -c "
import json,sys
d = json.load(sys.stdin)
hits = d.get('results', [])
bad = [h for h in hits if 'tenant B' in (h.get('content') or '')]
print(len(bad))" <<<"$SA")"
GOT_A="$(jget "$SA" results | python3 -c "import json,sys; print(len(json.load(sys.stdin)))")"
check "search A: returns results" "true" "$([ "$GOT_A" -gt 0 ] && echo true || echo false)"
check "search A: zero tenant-B content leaked" "0" "$LEAK_A"

echo "--- gate: forged client tenant ID is ignored ---"
# Attacker passes tenant B in the body; the server must still filter by A.
SF="$(curl -s -X POST http://bmos.localhost/search -b "$JAR" -H 'Content-Type: application/json' \
  -d "{\"query\":\"zulu yankee xray confidential plan\",\"tenant_id\":\"$TENANT_B\",\"limit\":5}")"
LEAK_F="$(python3 -c "
import json,sys
d = json.load(sys.stdin)
hits = d.get('results', [])
bad = [h for h in hits if 'tenant B' in (h.get('content') or '')]
print(len(bad))" <<<"$SF")"
# The echoed tenant_id must be A's (from the header), never B's.
ECHO_TENANT="$(jget "$SF" tenant_id)"
check "forged: server used header tenant, not body" "$TENANT_A" "$ECHO_TENANT"
check "forged: zero tenant-B content leaked" "0" "$LEAK_F"

echo "--- gate: cross-tenant retrieval returns no foreign points ---"
# Search B's exact content as A: B's points must be invisible.
SX="$(curl -s -X POST http://bmos.localhost/search -b "$JAR" -H 'Content-Type: application/json' \
  -d '{"query":"whiskey victor uniform private memo","limit":5}')"
LEAK_X="$(python3 -c "
import json,sys
d = json.load(sys.stdin)
hits = d.get('results', [])
bad = [h for h in hits if 'tenant B' in (h.get('content') or '')]
print(len(bad))" <<<"$SX")"
check "cross-tenant: B content invisible to A" "0" "$LEAK_X"

echo "--- gate: rebuildability — wipe Qdrant, rebuild, verify ---"
PG_BEFORE="$(docker compose exec -T -e "PGPASSWORD=$(cat secrets/postgres_password.txt)" postgres \
  psql -U "${POSTGRES_USER:-bmos}" -d "$DB" -tA -c "SELECT count(*) FROM app.document_chunks;")"
docker compose exec -T php php scripts/rebuild-qdrant.php --tenant "$TENANT_A" --tenant "$TENANT_B" > /dev/null
COUNT_AFTER="$(docker compose exec -T php php -r "
require '/var/www/html/src/Qdrant.php';
echo (new Qdrant())->count('$COLLECTION');")"
PG_AFTER="$(docker compose exec -T -e "PGPASSWORD=$(cat secrets/postgres_password.txt)" postgres \
  psql -U "${POSTGRES_USER:-bmos}" -d "$DB" -tA -c "SELECT count(*) FROM app.document_chunks;")"
check "rebuild: point count restored" "$COUNT" "$COUNT_AFTER"
check "rebuild: PostgreSQL chunks untouched" "$PG_BEFORE" "$PG_AFTER"
# Search still works after rebuild.
SR="$(curl -s -X POST http://bmos.localhost/search -b "$JAR" -H 'Content-Type: application/json' \
  -d '{"query":"alpha bravo charlie secret recipe","limit":5}')"
GOT_R="$(jget "$SR" results | python3 -c "import json,sys; print(len(json.load(sys.stdin)))")"
check "rebuild: search works after rebuild" "true" "$([ "$GOT_R" -gt 0 ] && echo true || echo false)"

echo "--- cleanup test documents (tenants stay) ---"
docker compose exec -T -e "PGPASSWORD=$(cat secrets/postgres_password.txt)" postgres \
  psql -U "${POSTGRES_USER:-bmos}" -d "$DB" -v ON_ERROR_STOP=1 -c \
  "DELETE FROM app.documents WHERE id IN ('$DA','$DBB');" > /dev/null
# Re-index to drop the deleted chunks' points from Qdrant (upsert-only index
# has no delete path in this demo; rebuild is the cleanup mechanism).
docker compose exec -T php php scripts/rebuild-qdrant.php --tenant "$TENANT_A" --tenant "$TENANT_B" > /dev/null

echo "--- result: $pass passed, $fail failed ---"
[ "$fail" -eq 0 ]
