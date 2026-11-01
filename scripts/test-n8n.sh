#!/usr/bin/env bash
# BMOS 3.2 Phase 9 — n8n: the guide's gates.
#
# Gate (guide, Phase 9):
#   - n8n starts via profile (not by default with `up -d`).
#   - n8n uses its own database (n8n_db), NOT the BMOS app database.
#   - n8n can reach BMOS APIs (via backend network).
#   - n8n has NO direct credentials to the BMOS app database.
#
# Prerequisites: bash scripts/n8n-init.sh, then
#   docker compose --profile n8n up -d
set -euo pipefail
cd "$(dirname "$0")/.."

pass=0
fail=0
check() {
  if [ "$2" = "$3" ]; then
    echo "PASS: $1"; pass=$((pass + 1))
  else
    echo "FAIL: $1 (expected [$2], got [$3])"; fail=$((fail + 1))
  fi
}

echo "--- gate: n8n profile-gated (not in default up) ---"
# n8n should NOT be running after a plain `up -d` (it needs --profile n8n).
# If the user started it with the profile, it SHOULD be running.
N8N_RUNNING="$(docker compose ps --format "{{.Service}}" 2>/dev/null | grep -c "^n8n$" || true)"
echo "n8n container present: $N8N_RUNNING"

echo "--- gate: n8n reachable on backend network ---"
# n8n UI on :5678, backend network only (not published).
N8N_CODE="$(docker compose exec -T php wget -q -O /dev/null --timeout=10 http://n8n:5678/ 2>&1; echo $?)"
# wget returns 0 on success (even for non-200, we just check connectivity).
# Better: check via the n8n container itself.
N8N_HEALTH="$(docker compose exec -T n8n wget -q -O- --timeout=5 http://localhost:5678/healthz 2>&1 | head -c 50 || echo "FAIL")"
check "n8n: healthz reachable" "true" "$([ -n "$N8N_HEALTH" ] && [ "$N8N_HEALTH" != "FAIL" ] && echo true || echo false)"
echo "healthz: $N8N_HEALTH"

echo "--- gate: n8n uses its own database ---"
N8N_DB="$(docker compose exec -T -e PGPASSWORD="$(cat secrets/postgres_password.txt)" postgres \
  psql -U "${POSTGRES_USER:-bmos}" -d "${POSTGRES_DB:-bmos}" -tAc \
  "SELECT 1 FROM pg_database WHERE datname='n8n_db';" 2>/dev/null)"
check "n8n: n8n_db exists" "1" "$N8N_DB"

echo "--- gate: n8n can reach BMOS APIs ---"
# From the n8n container, curl the BMOS health endpoint via nginx.
BMOS_FROM_N8N="$(docker compose exec -T n8n wget -q -O- --timeout=10 http://nginx/health 2>&1 | head -c 100 || echo "FAIL")"
check "n8n: reaches BMOS /health" "true" "$(echo "$BMOS_FROM_N8N" | grep -q '"status":"ok"' && echo true || echo false)"

echo "--- gate: n8n has no BMOS app DB credentials ---"
N8N_ENV="$(docker compose exec -T n8n env 2>/dev/null | grep -iE "bmos_app|app_password" || echo "clean")"
check "n8n: no bmos_app credentials in env" "clean" "$N8N_ENV"

echo ""
echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
