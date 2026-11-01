#!/usr/bin/env bash
# BMOS 3.2 Phase 7 — initialize Garage: layout, bucket, restricted app key.
#
# Creates:
#   - layout: single node, 10G capacity, version 1
#   - bucket: bmos-objects (or $S3_BUCKET)
#   - key: bmos-app-key with read+write on the bucket only
#
# The key ID and secret are written to secrets/garage_app_key_id.txt and
# secrets/garage_app_secret.txt (those files must exist, even empty, before
# `docker compose up -d`). After init, restart php to pick up the secrets:
#   docker compose restart php
#
# Run: bash scripts/garage-init.sh (from the project root, host side).
set -euo pipefail
cd "$(dirname "$0")/.."

BUCKET="${S3_BUCKET:-bmos-objects}"
KEY_NAME="bmos-app-key"

# Secret files must exist (even empty) for compose to start.
touch secrets/garage_app_key_id.txt secrets/garage_app_secret.txt
chmod 644 secrets/garage_app_key_id.txt secrets/garage_app_secret.txt 2>/dev/null || true

echo "--- waiting for Garage ---"
for i in $(seq 1 30); do
  if docker compose exec -T garage /garage -c /etc/garage.toml status >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

# Helper: run garage CLI in the container.
g() {
  docker compose exec -T garage /garage -c /etc/garage.toml "$@"
}

echo "--- cluster layout ---"
# Get node ID (first line of `garage status` after header).
NODE_ID="$(g status 2>/dev/null | awk '/^[0-9a-f]{16}/ {print $1; exit}')"
if [ -z "$NODE_ID" ]; then
  echo "ERROR: could not get Garage node ID" >&2
  g status 2>&1 | head -10
  exit 1
fi
echo "node: $NODE_ID"
# Assign layout if not already done (idempotent: check current version).
if g layout show 2>/dev/null | grep -q "version: 1"; then
  echo "layout v1 already applied"
else
  g layout assign -z dc1 -c 10G "$NODE_ID" >/dev/null
  g layout apply --version 1 >/dev/null
  echo "layout v1 applied"
fi

echo "--- bucket ${BUCKET} ---"
g bucket create "$BUCKET" >/dev/null 2>&1 || echo "(bucket exists)"

echo "--- app key ${KEY_NAME} ---"
# Create key if missing; capture ID and secret.
if g key list 2>/dev/null | grep -q "$KEY_NAME"; then
  echo "(key exists, reusing)"
  KEY_ID="$(g key list 2>/dev/null | awk -v n="$KEY_NAME" '$0 ~ n {print $1; exit}')"
  # Secret can't be retrieved; if the secret file is empty we must recreate.
  if [ ! -s secrets/garage_app_secret.txt ]; then
    echo "key exists but secret file empty — deleting and recreating"
    g key delete --yes "$KEY_ID" >/dev/null
    KEY_ID=""
  fi
fi
if [ -z "${KEY_ID:-}" ]; then
  OUT="$(g key create "$KEY_NAME")"
  KEY_ID="$(echo "$OUT" | awk '/Key ID/ {print $NF}')"
  SECRET="$(echo "$OUT" | awk '/Secret key/ {print $NF}')"
  if [ -z "$KEY_ID" ] || [ -z "$SECRET" ]; then
    echo "ERROR: could not parse key create output" >&2
    echo "$OUT"
    exit 1
  fi
  printf '%s' "$KEY_ID" > secrets/garage_app_key_id.txt
  printf '%s' "$SECRET" > secrets/garage_app_secret.txt
  chmod 644 secrets/garage_app_key_id.txt secrets/garage_app_secret.txt
  echo "key created: $KEY_ID"
fi

echo "--- granting bucket access ---"
g bucket allow "$BUCKET" --read --write --key "$KEY_ID" >/dev/null
echo "OK: bucket '${BUCKET}', key '${KEY_ID}' with read+write"
echo ""
echo "Now restart php to pick up the key:  docker compose restart php"
