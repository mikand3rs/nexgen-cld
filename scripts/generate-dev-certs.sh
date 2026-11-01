#!/usr/bin/env bash
# BMOS 3.2 Stage B — generate local dev TLS certificates.
# Creates a self-signed CA + cert for bmos.localhost.
# For production, replace with Let's Encrypt (Traefik ACME) or real certs.
set -euo pipefail
cd "$(dirname "$0")/.."

CERT_DIR="docker/traefik/certs"
mkdir -p "$CERT_DIR"

if [ -f "$CERT_DIR/bmos.localhost.crt" ]; then
  echo "Certs already exist in $CERT_DIR — delete them to regenerate."
  exit 0
fi

echo "Generating CA..."
openssl genrsa -out "$CERT_DIR/ca.key" 2048 2>/dev/null
openssl req -x509 -new -nodes -key "$CERT_DIR/ca.key" -sha256 -days 825 \
  -out "$CERT_DIR/ca.crt" -subj "/CN=BMOS Local Dev CA" 2>/dev/null

echo "Generating cert for bmos.localhost..."
openssl genrsa -out "$CERT_DIR/bmos.localhost.key" 2048 2>/dev/null
openssl req -new -key "$CERT_DIR/bmos.localhost.key" \
  -out "$CERT_DIR/bmos.localhost.csr" -subj "/CN=bmos.localhost" 2>/dev/null
cat > "$CERT_DIR/bmos.localhost.ext" <<'EOF'
authorityKeyIdentifier=keyid,issuer
basicConstraints=CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names
[alt_names]
DNS.1 = bmos.localhost
DNS.2 = localhost
IP.1 = 127.0.0.1
EOF
openssl x509 -req -in "$CERT_DIR/bmos.localhost.csr" \
  -CA "$CERT_DIR/ca.crt" -CAkey "$CERT_DIR/ca.key" -CAcreateserial \
  -out "$CERT_DIR/bmos.localhost.crt" -days 825 -sha256 \
  -extfile "$CERT_DIR/bmos.localhost.ext" 2>/dev/null
rm "$CERT_DIR/bmos.localhost.csr" "$CERT_DIR/bmos.localhost.ext"

chmod 600 "$CERT_DIR"/*.key
chmod 644 "$CERT_DIR"/*.crt

echo ""
echo "Done. Certs in $CERT_DIR/"
echo "To trust the CA on Windows: import $CERT_DIR/ca.crt into"
echo "  Trusted Root Certification Authorities (certmgr.msc)."
echo "To trust on WSL/Ubuntu:"
echo "  sudo cp $CERT_DIR/ca.crt /usr/local/share/ca-certificates/bmos-ca.crt"
echo "  sudo update-ca-certificates"
