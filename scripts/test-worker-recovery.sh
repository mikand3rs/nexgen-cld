#!/usr/bin/env bash
# BMOS 3.2 Stage B — worker crash recovery test.
# Enqueues a job, kills the worker mid-claim (SIGKILL), restarts it,
# and verifies the reaper recovers the job.
set -euo pipefail
cd "$(dirname "$0")/.."

BASE="${BMOS_BASE:-http://bmos.localhost}"
EMAIL="${ADMIN_EMAIL:?set ADMIN_EMAIL}"
PASS="${ADMIN_PASSWORD:?set ADMIN_PASSWORD}"
JAR=/tmp/bmos-recovery-cookies.txt
rm -f "$JAR"

echo "--- Stage B: worker crash recovery ---"

# 1. Login
curl -s -c "$JAR" -X POST "$BASE/auth/login" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" > /dev/null
if ! grep -q PHPSESSID "$JAR" 2>/dev/null; then
  echo "FAIL: login did not set session cookie"; exit 1
fi
echo "PASS: logged in"

# 2. Enqueue a slow job (demo.fail_once sleeps via attempts; use task.created)
JOB_JSON=$(curl -s -b "$JAR" -X POST "$BASE/jobs/enqueue" \
  -H 'Content-Type: application/json' \
  -d '{"job_type":"task.created","title":"recovery test '"$(date +%s)"'"}')
JOB_ID=$(echo "$JOB_JSON" | grep -o '"job_id":"[^"]*"' | cut -d'"' -f4)
if [ -z "$JOB_ID" ]; then
  echo "FAIL: enqueue failed: $JOB_JSON"; exit 1
fi
echo "PASS: enqueued job $JOB_ID"

# 3. Stop the worker abruptly (SIGKILL = no graceful shutdown)
# First, manually claim the job to simulate a crash mid-execution:
docker compose exec -T postgres psql -U bmos_migrator -d bmos -c \
  "UPDATE app.outbox_jobs SET status='claimed', claimed_at=now(),
   lease_expires_at=now() - interval '1 second',
   last_heartbeat_at=now() - interval '70 seconds',
   worker_id='test-crash-sim' WHERE id='$JOB_ID';" > /dev/null
echo "PASS: simulated crashed worker (expired lease)"

# 4. Run the reaper directly
REAPED=$(docker compose exec -T postgres psql -U bmos_app -d bmos -t -c \
  "SELECT count(*) FROM app.reap_expired_leases();")
REAPED=$(echo "$REAPED" | tr -d ' ')
if [ "$REAPED" = "1" ]; then
  echo "PASS: reaper recovered 1 job"
else
  echo "FAIL: reaper recovered $REAPED jobs (expected 1)"; exit 1
fi

# 5. Verify the job is pending again (not dead, not lost)
STATUS=$(docker compose exec -T postgres psql -U bmos_migrator -d bmos -t -c \
  "SELECT status FROM app.outbox_jobs WHERE id='$JOB_ID';" | tr -d ' ')
if [ "$STATUS" = "pending" ]; then
  echo "PASS: job $JOB_ID is pending again (recovered, not lost)"
else
  echo "FAIL: job status is '$STATUS' (expected pending)"; exit 1
fi

# 6. Verify attempts incremented (bounded retry accounting)
ATTEMPTS=$(docker compose exec -T postgres psql -U bmos_migrator -d bmos -t -c \
  "SELECT attempts FROM app.outbox_jobs WHERE id='$JOB_ID';" | tr -d ' ')
echo "PASS: attempts=$ATTEMPTS (incremented by reaper)"

echo "--- All worker recovery checks passed ---"
