#!/usr/bin/env bash
# BMOS 3.2 Phase 7 — generate docker/garage.toml from the template.
# Reads the secrets and substitutes them. The generated file is gitignored
# and NOT included in the zip (it contains secrets).
#
# Run BEFORE `docker compose up -d`:
#   bash scripts/garage-config.sh
set -euo pipefail
cd "$(dirname "$0")/.."

for f in secrets/garage_rpc_secret.txt secrets/garage_admin_token.txt; do
  if [ ! -f "$f" ] || [ ! -s "$f" ]; then
    echo "ERROR: $f missing or empty. Create it first (see docs/secrets.md)." >&2
    exit 1
  fi
done

RPC_SECRET="$(cat secrets/garage_rpc_secret.txt)"
ADMIN_TOKEN="$(cat secrets/garage_admin_token.txt)"

sed -e "s|\${GARAGE_RPC_SECRET}|${RPC_SECRET}|g" \
    -e "s|\${GARAGE_ADMIN_TOKEN}|${ADMIN_TOKEN}|g" \
    docker/garage.toml.template > docker/garage.toml
chmod 600 docker/garage.toml
echo "wrote docker/garage.toml (gitignored, contains secrets — do not commit)"
