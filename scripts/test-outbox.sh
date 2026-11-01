#!/usr/bin/env bash
# BMOS 3.2 Phase 5 — the four outbox gates (guide section 6, Phase 5) plus an
# end-to-end worker run. EVERY assertion runs as bmos_app, the same
# non-superuser role the app and worker use. Fixture setup uses the superuser
# only to insert rows; that bypass is by design and labeled where it happens.
#
# The worker is STOPPED for the SQL gate tests (so it can't race them) and
# STARTED for the end-to-end test.
set -euo pipefail
set -o pipefail
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
  docker compose exec -T -e "PGPASSWORD=<redacted>" postgres \
    psql -U bmos_app -d "$DB" -tA -v ON_ERROR_STOP=1 -c "$1" | tail -n 1
}
super() {
  docker compose exec -T -e "PGPASSWORD=<redacted>" postgres \
    psql -U "$SUPERUSER" -d "$DB" -tA -v ON_ERROR_STOP=1 -c "$1"
}
jget() { # jget <json> <key> — tiny JSON extractor, no jq dependency; never fails
  python3 -c "import json,sys
try:
    print(json.load(sys.stdin).get('$2', ''))
except Exception:
    print('')" <<<"$1" 2>/dev/null
}

TENANT_A="11111111-1111-1111-1111-111111111111"
TENANT_B="22222222-2222-2222-2222-222222222222"

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
# Tenant B gets a finished job so the RLS tests have a non-pending row.
super "INSERT INTO app.outbox_jobs(id, tenant_id, job_type, status) VALUES
         ('bbbbbbbb-1111-1111-1111-111111111111', '$TENANT_B', 'task.created', 'done')
       ON CONFLICT (id) DO NOTHING;" > /dev/null
super "INSERT INTO app.tasks(id, tenant_id, title, status) VALUES
         ('bbbbbbbb-2222-2222-2222-222222222222', '$TENANT_B', 'B task', 'open')
       ON CONFLICT (id) DO NOTHING;" > /dev/null

echo "--- worker paused for the SQL gate tests ---"
docker compose stop worker > /dev/null 2>&1 || true

echo "--- test 1: a job is not visible before the creating transaction commits ---"
docker compose exec -T -e "PGPASSWORD=<redacted>" postgres \
  psql -U bmos_app -d "$DB" -v ON_ERROR_STOP=1 -c "
    BEGIN;
    SET LOCAL app.current_tenant = '$TENANT_A';
    INSERT INTO app.outbox_jobs (tenant_id, job_type, payload)
      VALUES ('$TENANT_A', 'test.t1', '{}');
    SELECT pg_sleep(8);
    COMMIT;" > /tmp/bmos-t1.log 2>&1 &
T1_PID=$!
sleep 3
before="$(app "SELECT count(*) FROM app.outbox_jobs WHERE job_type = 'test.t1';")"
wait "$T1_PID"
after="$(app "SELECT count(*) FROM app.outbox_jobs WHERE job_type = 'test.t1';")"
check "1a. uncommitted job is invisible" "0" "$before"
check "1b. committed job is visible" "1" "$after"
super "DELETE FROM app.outbox_jobs WHERE job_type = 'test.t1';" > /dev/null

echo "--- test 2: two workers cannot successfully execute the same claim ---"
T2_JOB="$(super "INSERT INTO app.outbox_jobs (tenant_id, job_type, payload) VALUES ('$TENANT_A', 'test.t2', '{}') RETURNING id;" | head -n 1)"
# Session A: claim the row and hold the lock for 8s (then roll back).
docker compose exec -T -e "PGPASSWORD=<redacted>" postgres \
  psql -U bmos_app -d "$DB" -v ON_ERROR_STOP=1 -c "
    BEGIN;
    SET LOCAL app.current_tenant = '$TENANT_A';
    SELECT id FROM app.outbox_jobs WHERE id = '$T2_JOB' AND status = 'pending' FOR UPDATE;
    SELECT pg_sleep(8);
    ROLLBACK;" > /tmp/bmos-t2a.log 2>&1 &
T2A_PID=$!
sleep 3
# Session B: a competing worker's claim must skip the locked row, not block.
# (FOR UPDATE can't sit next to count(*), so the lock lives in a subquery.)
got="$(app "SET app.current_tenant = '$TENANT_A';
            SELECT count(*) FROM (
              SELECT id FROM app.outbox_jobs
              WHERE id = '$T2_JOB' AND status = 'pending' FOR UPDATE SKIP LOCKED
            ) AS competing_claim;")"
wait "$T2A_PID"
check "2. competing claim skips the locked row (0 rows)" "0" "$got"
super "DELETE FROM app.outbox_jobs WHERE id = '$T2_JOB';" > /dev/null

echo "--- test 3: retried/duplicate enqueues are idempotent ---"
IKEY="test3-$(date +%s)"
enqueue() {
  curl -s -X POST http://bmos.localhost/jobs/enqueue \
    -H "X-Tenant-ID: $TENANT_A" -H 'Content-Type: application/json' \
    -d "{\"job_type\":\"task.created\",\"title\":\"t3-task\",\"idempotency_key\":\"$IKEY\"}"
}
R1="$(enqueue)"; R2="$(enqueue)"
J1="$(jget "$R1" job_id)"; J2="$(jget "$R2" job_id)"
D2="$(jget "$R2" deduplicated)"
check "3a. duplicate enqueue returns the same job id" "$J1" "$J2"
check "3b. duplicate enqueue is flagged deduplicated" "True" "$D2"
check "3c. exactly one job row for the key" "1" \
  "$(app "SET app.current_tenant = '$TENANT_A'; SELECT count(*) FROM app.outbox_jobs WHERE idempotency_key = '$IKEY';")"
check "3d. exactly one task row (no orphan from the duplicate)" "1" \
  "$(app "SET app.current_tenant = '$TENANT_A'; SELECT count(*) FROM app.tasks WHERE title = 't3-task';")"
# Same key under a DIFFERENT tenant must not collide (keys are per-tenant).
R3="$(curl -s -X POST http://bmos.localhost/jobs/enqueue \
  -H "X-Tenant-ID: $TENANT_B" -H 'Content-Type: application/json' \
  -d "{\"job_type\":\"task.created\",\"title\":\"t3-b-task\",\"idempotency_key\":\"$IKEY\"}")"
J3="$(jget "$R3" job_id)"; D3="$(jget "$R3" deduplicated)"
if [ "$J3" != "$J1" ] && [ "$D3" = "False" ]; then
  check "3e. same key under tenant B creates its own job" "separate" "separate"
else
  check "3e. same key under tenant B creates its own job" "separate" "collided"
fi

echo "--- test 4: worker-side operations obey the same RLS rules as web requests ---"
upd="$(app "SET app.current_tenant = '$TENANT_A';
            UPDATE app.outbox_jobs SET status = 'claimed'
            WHERE tenant_id = '$TENANT_B' AND status = 'pending';" | tr -d '[:space:]')"
check "4a. tenant A cannot claim tenant B's job (0 rows)" "UPDATE0" "$upd"
# Note: pending rows ARE visible cross-tenant by design (outbox_discovery —
# that is how the worker finds work), so this filters to non-pending.
check "4b. tenant A cannot see tenant B's finished job" "0" \
  "$(app "SET app.current_tenant = '$TENANT_A';
         SELECT count(*) FROM app.outbox_jobs WHERE tenant_id = '$TENANT_B' AND status <> 'pending';")"
check "4c. tenant A cannot read tenant B's tasks" "0" \
  "$(app "SET app.current_tenant = '$TENANT_A';
         SELECT count(*) FROM app.tasks WHERE tenant_id = '$TENANT_B';")"
if app "SET app.current_tenant = '$TENANT_A';
        INSERT INTO app.tasks (tenant_id, title) VALUES ('$TENANT_B', 'sneaky');" >/dev/null 2>&1; then
  check "4d. tenant A cannot insert a task for tenant B" "blocked" "allowed"
else
  check "4d. tenant A cannot insert a task for tenant B" "blocked" "blocked"
fi
# Design note, asserted by 4b/4c's shape: PENDING jobs are visible across
# tenants to bmos_app by design (outbox_discovery policy — that is how the
# worker finds work). No web endpoint exposes outbox rows in Phase 5, so
# the discovery exception never reaches a client.

echo "--- test 5: end-to-end — worker picks up and completes a job ---"
docker compose up -d worker > /dev/null
EKEY="test5-$(date +%s)"
RE="$(curl -s -X POST http://bmos.localhost/jobs/enqueue \
  -H "X-Tenant-ID: $TENANT_A" -H 'Content-Type: application/json' \
  -d "{\"job_type\":\"task.created\",\"title\":\"t5-task\",\"idempotency_key\":\"$EKEY\"}")"
EJ="$(jget "$RE" job_id)"
status=""; tries=0
while [ "$tries" -lt 30 ]; do
  status="$(app "SET app.current_tenant = '$TENANT_A';
                 SELECT status FROM app.outbox_jobs WHERE id = '$EJ';")"
  [ "$status" = "done" ] && break
  sleep 2; tries=$((tries + 1))
done
check "5a. worker completed the job" "done" "$status"
check "5b. task marked notified (handler ran)" "notified" \
  "$(app "SET app.current_tenant = '$TENANT_A';
         SELECT status FROM app.tasks WHERE title = 't5-task';")"
check "5c. result recorded on the job" "True" \
  "$(python3 -c "import json,sys
try:
    print(json.load(sys.stdin).get('notified', ''))
except Exception:
    print('MISSING')" \
    <<<"$(app "SET app.current_tenant = '$TENANT_A';
                SELECT result::text FROM app.outbox_jobs WHERE id = '$EJ';")" 2>/dev/null || echo MISSING)"

echo "--- test 6: retry — a failing job is retried and then succeeds ---"
FKEY="test6-$(date +%s)"
RF="$(curl -s -X POST http://bmos.localhost/jobs/enqueue \
  -H "X-Tenant-ID: $TENANT_A" -H 'Content-Type: application/json' \
  -d "{\"job_type\":\"demo.fail_once\",\"title\":\"t6-task\",\"idempotency_key\":\"$FKEY\"}")"
FJ="$(jget "$RF" job_id)"
status=""; tries=0
while [ "$tries" -lt 40 ]; do
  status="$(app "SET app.current_tenant = '$TENANT_A';
                 SELECT status FROM app.outbox_jobs WHERE id = '$FJ';")"
  [ "$status" = "done" ] && break
  sleep 2; tries=$((tries + 1))
done
check "6a. retried job eventually done" "done" "$status"
check "6b. it took exactly 2 attempts" "2" \
  "$(app "SET app.current_tenant = '$TENANT_A';
         SELECT attempts FROM app.outbox_jobs WHERE id = '$FJ';")"

echo "--- cleanup test rows (fixtures for tenant B stay) ---"
super "DELETE FROM app.outbox_jobs WHERE job_type LIKE 'test.%';" > /dev/null
super "DELETE FROM app.outbox_jobs WHERE idempotency_key LIKE 'test3-%' OR idempotency_key LIKE 'test5-%' OR idempotency_key LIKE 'test6-%';" > /dev/null
super "DELETE FROM app.tasks WHERE title IN ('t3-task', 't3-b-task', 't5-task', 't6-task');" > /dev/null

echo "--- result: $pass passed, $fail failed ---"
[ "$fail" -eq 0 ]
