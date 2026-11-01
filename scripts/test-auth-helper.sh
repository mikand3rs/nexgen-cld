#!/usr/bin/env bash
# BMOS 3.2 Stage B — test auth helper.
# Logs in and outputs a cookie jar path for authenticated test requests.
# Usage: JAR=$(bash scripts/test-auth-helper.sh) || exit 1
set -euo pipefail

BASE="${BMOS_BASE:-http://bmos.localhost}"
EMAIL="${ADMIN_EMAIL:?set ADMIN_EMAIL}"
PASS="${ADMIN_PASSWORD:?set ADMIN_PASSWORD}"
JAR="${TEST_JAR:-/tmp/bmos-test-cookies.txt}"

rm -f "$JAR"
curl -s -c "$JAR" -X POST "$BASE/auth/login" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" > /dev/null

if ! grep -q "PHPSESSID" "$JAR" 2>/dev/null; then
  echo "test-auth-helper: login failed" >&2
  exit 1
fi
echo "$JAR"
