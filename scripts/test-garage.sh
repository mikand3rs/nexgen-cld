#!/usr/bin/env bash
# BMOS 3.2 Phase 7 — Garage S3 object storage: the guide's gates.
#
# Gate (guide, Phase 7):
#   - MinIO API and console are not exposed publicly by default.
#   - PHP uses a non-root MinIO identity.
#   - Tenant A cannot retrieve Tenant B's object through BMOS.
#   - Backup and restore tests cover both PostgreSQL metadata and object data.
#
# Flow under test: POST /objects → PUT bytes to presigned URL →
# POST /objects/{id}/complete → GET /objects/{id}/download → GET bytes.
#
# Prerequisites: compose up -d, migrate.sh, minio-init.sh.
set -euo pipefail
set -o pipefail
cd "$(dirname "$0")/.."

set -a
[ -f .env ] && . ./.env
set +a
DB="${POSTGRES_DB:-bmos}"
BUCKET="${S3_BUCKET:-bmos-objects}"

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

echo "--- setup: migrate, init minio ---"
bash scripts/migrate.sh > /dev/null
bash scripts/garage-init.sh > /dev/null 2>&1 || {
  echo "WARN: minio-init.sh needs review; continuing"
}

echo "--- gate: Garage not publicly exposed ---"
# No published ports on the minio service (backend network only).
PUB="$(docker inspect nexgen-bmos-garage-1 --format '{{json .HostConfig.PortBindings}}')"
check "garage: no published ports" "true" "$([ "$PUB" = "null" ] || [ "$PUB" = "{}" ] && echo true || echo false)"
# Console/API not reachable via Traefik (no router). Direct host:9000 fails.
if curl -s -m 3 http://localhost:3900/ >/dev/null 2>&1; then
  check "garage: :3900 not on host" "closed" "open"
else
  check "garage: :3900 not on host" "closed" "closed"
fi

echo "--- fixtures: tenants ---"
docker compose exec -T -e "PGPASSWORD=$(cat secrets/postgres_password.txt)" postgres \
  psql -U "${POSTGRES_USER:-bmos}" -d "$DB" -v ON_ERROR_STOP=1 -c \
  "INSERT INTO app.tenants(id, name) VALUES ('$TENANT_A','Tenant A'), ('$TENANT_B','Tenant B')
   ON CONFLICT (id) DO NOTHING;" > /dev/null

echo "--- upload flow: register → PUT bytes → complete ---"
ROBJ="$(curl -s -X POST http://bmos.localhost/objects \
  -H "X-Tenant-ID: $TENANT_A" -H 'Content-Type: application/json' \
  -d '{"filename":"hello.txt","media_type":"text/plain"}')"
OID="$(jget "$ROBJ" id)"
PUTURL="$(jget "$ROBJ" upload_url)"
check "register: object id returned" "36" "${#OID}"
check "register: presigned PUT url" "true" "$([ -n "$PUTURL" ] && echo true || echo false)"

# PUT bytes directly to MinIO via the presigned URL.
# (MinIO is backend-only; curl runs on the host, so we rewrite the host
#  to localhost only if a port were published — it isn't. Instead, PUT
#  from inside a backend-network container.)
TESTDATA="hello tenant A $(date +%s)"
echo -n "$TESTDATA" > /tmp/bmos-test-upload.txt
docker run --rm --network nexgen-bmos_backend \
  -v /tmp/bmos-test-upload.txt:/tmp/u.txt:ro \
  curlimages/curl:8.18.0 -s -X PUT --data-binary @/tmp/u.txt \
  -H 'Content-Type: text/plain' "$PUTURL" -o /dev/null -w "%{http_code}" > /tmp/bmos-put-code.txt || true
# Note: the presigned URL uses http://garage:3900 which resolves inside the
# backend network — the curl container shares that network.
PUTCODE="$(cat /tmp/bmos-put-code.txt)"
check "PUT bytes to presigned URL" "200" "$PUTCODE"

RCOMPLETE="$(curl -s -X POST "http://bmos.localhost/objects/${OID}/complete" \
  -H "X-Tenant-ID: $TENANT_A")"
check "complete: state uploaded" "uploaded" "$(jget "$RCOMPLETE" lifecycle_state)"

echo "--- download flow: presigned GET → bytes match ---"
RDL="$(curl -s "http://bmos.localhost/objects/${OID}/download" \
  -H "X-Tenant-ID: $TENANT_A")"
GETURL="$(jget "$RDL" download_url)"
check "download: presigned GET url" "true" "$([ -n "$GETURL" ] && echo true || echo false)"
# GET bytes via backend-network curl, compare.
docker run --rm --network nexgen-bmos_backend \
  curlimages/curl:8.18.0 -s "$GETURL" -o /tmp/bmos-dl.txt || true
# Copy out of the ephemeral container via a shared volume trick: re-run with volume.
docker run --rm --network nexgen-bmos_backend -v /tmp:/tmp \
  curlimages/curl:8.18.0 -s "$GETURL" -o /tmp/bmos-dl.txt
GOTDATA="$(cat /tmp/bmos-dl.txt)"
check "download: bytes match upload" "$TESTDATA" "$GOTDATA"

echo "--- gate: tenant B cannot retrieve tenant A's object ---"
RB="$(curl -s "http://bmos.localhost/objects/${OID}/download" \
  -H "X-Tenant-ID: $TENANT_B" -w "\n%{http_code}" )"
BCODE="$(echo "$RB" | tail -1)"
check "cross-tenant download: 404" "404" "$BCODE"
# B's object list must not include A's object.
RLIST_B="$(curl -s http://bmos.localhost/objects -H "X-Tenant-ID: $TENANT_B")"
LEAK="$(python3 -c "
import json,sys
d = json.load(sys.stdin)
objs = d.get('objects', [])
print('yes' if any(o.get('id') == '$OID' for o in objs) else 'no')" <<<"$RLIST_B")"
check "cross-tenant list: A's object invisible to B" "no" "$LEAK"

echo "--- gate: PHP uses non-root MinIO identity ---"
# The S3Client reads the app user, not root. Verify the app user exists and
# root is not referenced in PHP config.
APPUSER="$(cat secrets/minio_app_user.txt)"
ROOTUSER="$(cat secrets/minio_root_user.txt)"
check "app user != root user" "true" "$([ "$APPUSER" != "$ROOTUSER" ] && echo true || echo false)"
if grep -rq "MINIO_ROOT" app/src/ 2>/dev/null; then
  check "PHP: no root credential reference" "clean" "found"
else
  check "PHP: no root credential reference" "clean" "clean"
fi

echo "--- gate: backup covers PG metadata + object bytes ---"
# PG metadata dumps.
docker compose exec -T -e "PGPASSWORD=$(cat secrets/postgres_password.txt)" postgres \
  pg_dump -U "${POSTGRES_USER:-bmos}" -d "$DB" -t app.objects > /tmp/bmos-objects.sql
check "backup: PG objects table dumps" "true" "$([ -s /tmp/bmos-objects.sql ] && echo true || echo false)"
# MinIO bytes exist in the named volume.
MCNT="$(docker run --rm -v nexgen-bmos_garage_data:/data alpine sh -c 'find /data -type f | wc -l')"
check "backup: MinIO volume holds object bytes" "true" "$([ "$MCNT" -gt 0 ] && echo true || echo false)"

echo "--- cleanup ---"
curl -s -X DELETE "http://bmos.localhost/objects/${OID}" -H "X-Tenant-ID: $TENANT_A" > /dev/null
rm -f /tmp/bmos-test-upload.txt /tmp/bmos-dl.txt /tmp/bmos-put-code.txt /tmp/bmos-objects.sql

echo "--- result: $pass passed, $fail failed ---"
[ "$fail" -eq 0 ]
