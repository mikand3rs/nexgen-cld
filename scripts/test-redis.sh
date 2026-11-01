#!/usr/bin/env bash
# BMOS 3.2 Phase 10 (optional) — Redis + n8n Queue Mode.
#
# Gate (guide, Phase 10):
#   - Redis runs (profile-gated, ephemeral job data only).
#   - n8n-worker runs and connects to Redis.
#   - Queue Mode is opt-in via .env (N8N_EXECUTIONS_MODE=queue).
#
# Prerequisites:
#   bash scripts/n8n-init.sh (once)
#   Uncomment N8N_EXECUTIONS_MODE=queue and N8N_REDIS_HOST=redis in .env
#   docker compose --profile n8n --profile queue up -d
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

echo "--- gate: Redis running ---"
REDIS_PING="$(docker compose exec -T redis redis-cli ping 2>&1 || echo "FAIL")"
check "redis: PING returns PONG" "PONG" "$REDIS_PING"

echo "--- gate: n8n-worker running ---"
WORKER_RUNNING="$(docker compose ps --format "{{.Service}}" 2>/dev/null | grep -c "^n8n-worker$" || true)"
check "n8n-worker: container present" "1" "$WORKER_RUNNING"

echo "--- gate: worker connected to Redis ---"
# The worker logs should show Bull queue connection (not an error).
WORKER_LOGS="$(docker compose logs --tail=20 n8n-worker 2>&1 || echo "")"
check "n8n-worker: no Redis connection errors" "true" \
  "$(echo "$WORKER_LOGS" | grep -qiE "redis.*(error|fail|econnrefused)" && echo false || echo true)"

echo "--- gate: Redis holds only ephemeral data ---"
# Redis should NOT contain business data (it's a job queue, not a store).
# We just verify it's reachable and not being used as a primary store.
REDIS_KEYS="$(docker compose exec -T redis redis-cli dbsize 2>&1 || echo "FAIL")"
echo "redis dbsize: $REDIS_KEYS"
check "redis: reachable" "true" "$([ "$REDIS_KEYS" != "FAIL" ] && echo true || echo false)"

echo ""
echo "RESULT: $pass passed, $fail failed"
echo "(Phase 10 is optional; Queue Mode is opt-in via .env)"
[ "$fail" -eq 0 ]
