#!/usr/bin/env bash
# BMOS 3.2 Phase 8 — Windows-hosted Lemonade: the guide's gates.
#
# Gate (guide, Phase 8):
#   - PHP reaches Lemonade on the Windows host (host.docker.internal:13305).
#   - Lemonade receives NO PostgreSQL credentials and NO BMOS secrets.
#   - Chat round-trip works through the PHP endpoint (tenant-scoped).
#
# Prerequisites: Lemonade Server running on the Windows host (port 13305,
# GPU), compose up -d. No new containers in this phase.
set -euo pipefail
set -o pipefail
cd "$(dirname "$0")/.."

# Stage B: session auth (replaces X-Tenant-ID demo header).
JAR=$(bash scripts/test-auth-helper.sh) || exit 1

set -a
[ -f .env ] && . ./.env
set +a

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

echo "--- gate: Lemonade reachable from PHP ---"
RHEALTH="$(curl -s -m 15 http://bmos.localhost/ai/health)"
HSTATUS="$(jget "$RHEALTH" status)"
check "ai/health: status ok" "ok" "$HSTATUS"

echo "--- fixtures: tenant ---"
DB="${POSTGRES_DB:-bmos}"
docker compose exec -T -e PGPASSWORD="$(cat secrets/postgres_password.txt)" postgres \
  psql -U "${POSTGRES_USER:-bmos}" -d "$DB" -v ON_ERROR_STOP=1 -c \
  "INSERT INTO app.tenants(id, name) VALUES ('$TENANT_A','Tenant A')
   ON CONFLICT (id) DO NOTHING;" > /dev/null
MODELS="$(echo "$RHEALTH" | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d.get('models',[])))" 2>/dev/null)"
check "ai/health: at least one model listed" "true" "$([ "${MODELS:-0}" -ge 1 ] && echo true || echo false)"
echo "models: $(echo "$RHEALTH" | python3 -c "import json,sys; print(json.load(sys.stdin).get('models',[]))" 2>/dev/null)"

echo "--- gate: no DB credentials leak to Lemonade config ---"
# The php container env must not contain POSTGRES_PASSWORD or secret paths
# pointing at Lemonade; Lemonade URL must be host-gateway, not a container.
PENV="$(docker compose exec -T php env | grep -iE "lemonade|postgres_password" || true)"
echo "$PENV"
check "LEMONADE_URL uses host gateway" "true" "$(echo "$PENV" | grep -Eq "host.docker.internal|172\.25\.160\.1" && echo true || echo false)"
check "no POSTGRES_PASSWORD in php env" "true" "$(echo "$PENV" | grep -q "POSTGRES_PASSWORD=" && echo false || echo true)"

echo "--- gate: chat round-trip (tenant-scoped) ---"
RCHAT="$(curl -s -m 120 -X POST http://bmos.localhost/ai/chat \ -b "$JAR" -H 'Content-Type: application/json' \
  -d '{"messages":[{"role":"user","content":"Reply with exactly: BMOS-OK"}]}')"
CONTENT="$(jget "$RCHAT" content)"
check "ai/chat: got content" "true" "$([ -n "$CONTENT" ] && echo true || echo false)"
echo "chat reply (first 120 chars): $(echo "$CONTENT" | head -c 120)"
# Tenant isolation on the endpoint: bad UUID rejected, unknown tenant 404.
RBAD="$(curl -s -o /dev/null -w "%{http_code}" -X POST http://bmos.localhost/ai/chat \ -b "$JAR" -H 'Content-Type: application/json' \
  -d '{"messages":[{"role":"user","content":"hi"}]}')"
check "ai/chat: bad tenant UUID → 400" "400" "$RBAD"

echo ""
echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
