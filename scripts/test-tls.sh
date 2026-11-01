#!/usr/bin/env bash
# BMOS 3.2 — TLS verification (TLS-01).
#
# TLS is OPT-IN (see docs/tls.md). By default BMOS runs HTTP-only.
# This script verifies whichever mode is active:
#   - If port 443 is open: verifies HTTPS, cert, redirect, TLS version.
#   - If port 443 is closed: verifies HTTP works and reports TLS as opt-in.
#
# It FAILS if the configuration is inconsistent (e.g. 443 published but
# no cert, or redirect configured but 443 closed).

set -euo pipefail

BASE_HOST="${BMOS_HOST:-bmos.localhost}"
echo "--- TLS-01: transport verification ---"
FAIL=0

# Check if 443 is published.
if docker compose ps traefik --format '{{.Publishers}}' 2>/dev/null | grep -q "443"; then
  TLS_ENABLED=1
  echo "port 443 is published; verifying HTTPS mode"
else
  TLS_ENABLED=0
  echo "port 443 is NOT published; verifying HTTP-only mode (TLS opt-in)"
fi

if [ "$TLS_ENABLED" = "1" ]; then
  # 1. HTTPS works
  CODE=$(curl -sk -o /dev/null -w "%{http_code}" "https://$BASE_HOST/health" 2>/dev/null || echo "000")
  if [ "$CODE" = "200" ]; then echo "PASS: HTTPS /health → 200"; else echo "FAIL: HTTPS /health → $CODE"; FAIL=1; fi

  # 2. HTTP redirects to HTTPS
  CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://$BASE_HOST/health" 2>/dev/null || echo "000")
  if [ "$CODE" = "301" ] || [ "$CODE" = "308" ]; then
    echo "PASS: HTTP → HTTPS redirect ($CODE)"
  else
    echo "FAIL: HTTP did not redirect (got $CODE)"; FAIL=1
  fi

  # 3. Certificate is valid for the hostname
  CERT_CN=$(echo | openssl s_client -connect "$BASE_HOST:443" -servername "$BASE_HOST" 2>/dev/null \
    | openssl x509 -noout -subject 2>/dev/null | grep -o "CN=[^,]*" || echo "none")
  echo "cert: $CERT_CN"
  if echo "$CERT_CN" | grep -q "$BASE_HOST"; then
    echo "PASS: cert matches hostname"
  else
    echo "FAIL: cert does not match $BASE_HOST"; FAIL=1
  fi

  # 4. TLS 1.1 is rejected (1.2+ required)
  if echo | openssl s_client -connect "$BASE_HOST:443" -tls1_1 2>&1 | grep -q "Protocol"; then
    echo "WARN: TLS 1.1 accepted (should be 1.2+)"
  else
    echo "PASS: TLS 1.1 rejected"
  fi
else
  # HTTP-only mode: verify HTTP works and no HTTPS is expected.
  CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://$BASE_HOST/health" 2>/dev/null || echo "000")
  if [ "$CODE" = "200" ]; then
    echo "PASS: HTTP /health → 200"
  else
    echo "FAIL: HTTP /health → $CODE"; FAIL=1
  fi

  # Verify no redirect loop (HTTP should NOT redirect when TLS is off).
  CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://$BASE_HOST/health" 2>/dev/null || echo "000")
  if [ "$CODE" = "301" ] || [ "$CODE" = "308" ]; then
    echo "FAIL: HTTP redirects but 443 is closed (inconsistent config)"; FAIL=1
  else
    echo "PASS: no phantom redirect (HTTP-only is consistent)"
  fi

  echo "INFO: TLS is opt-in. See docs/tls.md to enable HTTPS."
fi

# 5. Backend services are not published (both modes).
for svc in postgres qdrant garage; do
  PUB=$(docker compose ps "$svc" --format '{{.Publishers}}' 2>/dev/null || echo "")
  # Only fail on actual host mappings (0.0.0.0:PORT). EXPOSEd ports show
  # as "6333/tcp -> :0" which is not a published port.
  if echo "$PUB" | grep -Eq "0\.0\.0\.0:[0-9]+->|:::[0-9]+->"; then
    echo "FAIL: $svc publishes ports: $PUB"; FAIL=1
  else
    echo "PASS: $svc has no published ports"
  fi
done

if [ "$FAIL" = "0" ]; then
  echo "--- All TLS-01 checks passed ---"
else
  echo "--- TLS-01 FAILED ---"
  exit 1
fi
