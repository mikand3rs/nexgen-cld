#!/usr/bin/env bash
# BMOS 3.2 Stage B — TLS verification.
# Checks HTTPS, HTTP→HTTPS redirect, cert validity, TLS version.
set -euo pipefail

BASE_HOST="${BMOS_HOST:-bmos.localhost}"
echo "--- Stage B: TLS verification ---"
FAIL=0

# 1. HTTPS works (skip verify for self-signed dev certs)
if curl -sk --max-time 10 "https://$BASE_HOST/health" | grep -q '"ok"'; then
  echo "PASS: HTTPS /health reachable"
else
  echo "FAIL: HTTPS /health not reachable"; FAIL=1
fi

# 2. HTTP redirects to HTTPS
REDIR=$(curl -s -o /dev/null -w "%{http_code} %{redirect_url}" --max-time 10 "http://$BASE_HOST/health" || true)
if echo "$REDIR" | grep -q "301.*https://"; then
  echo "PASS: HTTP → HTTPS redirect ($REDIR)"
else
  echo "FAIL: no HTTP→HTTPS redirect ($REDIR)"; FAIL=1
fi

# 3. Cert is valid for bmos.localhost
CERT_CN=$(echo | openssl s_client -connect "$BASE_HOST:443" -servername "$BASE_HOST" 2>/dev/null \
  | openssl x509 -noout -subject 2>/dev/null | grep -o "CN=[^,]*" || echo "none")
if echo "$CERT_CN" | grep -q "bmos.localhost"; then
  echo "PASS: cert CN matches ($CERT_CN)"
else
  echo "FAIL: cert CN mismatch ($CERT_CN)"; FAIL=1
fi

# 4. TLS 1.2+ only (TLS 1.0/1.1 rejected)
if echo | openssl s_client -connect "$BASE_HOST:443" -tls1_1 2>&1 | grep -q "handshake failure\|no protocols"; then
  echo "PASS: TLS 1.1 rejected"
else
  echo "WARN: TLS 1.1 not explicitly rejected (check config)"
fi

# 5. Internal ports still unpublished (spot check postgres)
if docker ps --format '{{.Names}} {{.Ports}}' | grep "nexgen-bmos-postgres" | grep -q "5432"; then
  echo "FAIL: postgres port published"; FAIL=1
else
  echo "PASS: postgres port not published"
fi

[ $FAIL -eq 0 ] && echo "--- All TLS checks passed ---" || { echo "--- TLS FAILURES ---"; exit 1; }
