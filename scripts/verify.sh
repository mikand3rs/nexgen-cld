#!/usr/bin/env bash
# BMOS 3.2 — Canonical verification (QA-01).
#
# Runs ALL test suites against the current commit and records evidence.
# Fails (non-zero exit) if any required suite fails.
# Skipped tests are reported separately; they do not pass silently.
#
# Usage: bash scripts/verify.sh
# Output: test evidence with commit SHA, timestamps, and per-suite results.

set -euo pipefail
cd "$(dirname "$0")/.."

COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "not-a-git-checkout")
DATE=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

echo "=============================================="
echo "BMOS 3.2 verification"
echo "commit: $COMMIT"
echo "date:   $DATE"
echo "=============================================="
echo ""

PASS=0
FAIL=0
SKIP=0
FAILED_SUITES=()
SKIPPED_SUITES=()

run_suite() {
  local name="$1"
  local script="$2"
  shift 2
  echo "--- $name ---"
  if [ ! -f "$script" ]; then
    echo "SKIP: $script not found"
    SKIP=$((SKIP+1))
    SKIPPED_SUITES+=("$name (missing)")
    return 0
  fi
  # Run and capture exit code properly (no pipefail masking).
  set +e
  bash "$script" "$@" > "/tmp/verify-$name.log" 2>&1
  local code=$?
  set -e
  if [ "$code" = "0" ]; then
    echo "PASS: $name"
    PASS=$((PASS+1))
  else
    echo "FAIL: $name (exit $code; see /tmp/verify-$name.log)"
    FAIL=$((FAIL+1))
    FAILED_SUITES+=("$name")
  fi
  # Show the last few lines for context.
  tail -3 "/tmp/verify-$name.log" | sed 's/^/  /'
  echo ""
}

# Credentials for auth-dependent suites.
export ADMIN_EMAIL="${ADMIN_EMAIL:-you@example.com}"
export ADMIN_PASSWORD="${ADMIN_PASSWORD:-yourpassword}"
export BMOS_BASE="${BMOS_BASE:-http://bmos.localhost}"

echo "Using BMOS_BASE=$BMOS_BASE"
echo ""

# Stage A: authentication
run_suite "auth" "scripts/test-auth.sh"

# Stage B: cross-tenant, worker recovery, hardening, TLS
run_suite "cross-tenant" "scripts/test-cross-tenant.sh"
run_suite "worker-recovery" "scripts/test-worker-recovery.sh"
run_suite "hardening" "scripts/test-hardening.sh"
run_suite "tls" "scripts/test-tls.sh"

# Regression: isolation, outbox, qdrant, garage, lemonade
run_suite "isolation" "scripts/test-isolation.sh"
run_suite "outbox" "scripts/test-outbox.sh"
run_suite "qdrant" "scripts/test-qdrant.sh"
run_suite "garage" "scripts/test-garage.sh"
run_suite "lemonade" "scripts/test-lemonade.sh"

# Remediation: worker crash (process-level)
run_suite "worker-crash" "scripts/test-worker-crash.sh"

echo "=============================================="
echo "Results: $PASS passed, $FAIL failed, $SKIP skipped"
echo "commit: $COMMIT"
if [ ${#FAILED_SUITES[@]} -gt 0 ]; then
  echo "failed: ${FAILED_SUITES[*]}"
fi
if [ ${#SKIPPED_SUITES[@]} -gt 0 ]; then
  echo "skipped: ${SKIPPED_SUITES[*]}"
fi
echo "=============================================="

if [ "$FAIL" -gt 0 ]; then
  echo "VERIFICATION FAILED"
  exit 1
fi
echo "VERIFICATION PASSED"
