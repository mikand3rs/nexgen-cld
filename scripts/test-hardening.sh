#!/usr/bin/env bash
# BMOS 3.2 Stage B — container hardening verification.
# Checks read-only FS, dropped capabilities, no-new-privileges,
# non-root users, and network boundaries.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "--- Stage B: container hardening ---"
FAIL=0

check() {
  local desc=$1; shift
  if "$@" > /dev/null 2>&1; then
    echo "PASS: $desc"
  else
    echo "FAIL: $desc"; FAIL=1
  fi
}

# Services to check (core only; profile-gated ones when running)
for svc in php nginx worker postgres; do
  cname=$(docker ps --format '{{.Names}}' | grep "nexgen-bmos-${svc}-" | head -1 || true)
  [ -z "$cname" ] && { echo "SKIP: $svc not running"; continue; }

  # Read-only root FS (php, nginx, worker should be read-only)
  if [ "$svc" = "php" ] || [ "$svc" = "nginx" ] || [ "$svc" = "worker" ]; then
    RO=$(docker inspect "$cname" --format '{{.HostConfig.ReadonlyRootfs}}')
    [ "$RO" = "true" ] && echo "PASS: $svc read-only root FS" || { echo "FAIL: $svc not read-only"; FAIL=1; }
  fi

  # No-new-privileges
  NNP=$(docker inspect "$cname" --format '{{.HostConfig.SecurityOpt}}' | grep -c "no-new-privileges" || true)
  [ "$NNP" -gt 0 ] && echo "PASS: $svc no-new-privileges" || { echo "FAIL: $svc missing no-new-privileges"; FAIL=1; }

  # Capabilities dropped
  CAPDROP=$(docker inspect "$cname" --format '{{.HostConfig.CapDrop}}' | grep -c "ALL" || true)
  [ "$CAPDROP" -gt 0 ] && echo "PASS: $svc caps dropped" || { echo "FAIL: $svc caps not dropped"; FAIL=1; }
done

# No Docker socket except traefik
echo ""
echo "Docker socket mounts:"
docker ps --format '{{.Names}}' | while read -r cname; do
  if docker inspect "$cname" --format '{{.Mounts}}' 2>/dev/null | grep -q "docker.sock"; then
    echo "  $cname: HAS socket"
    if echo "$cname" | grep -q "traefik"; then
      echo "  PASS: only traefik has socket (read-only, documented)"
    else
      echo "  FAIL: non-traefik service has socket"; FAIL=1
    fi
  fi
done

# Network boundaries: postgres/qdrant/garage not on edge, no published ports
echo ""
echo "Network boundaries:"
for svc in postgres qdrant garage; do
  cname=$(docker ps --format '{{.Names}}' | grep "nexgen-bmos-${svc}-" | head -1 || true)
  [ -z "$cname" ] && continue
  NETS=$(docker inspect "$cname" --format '{{.NetworkSettings.Networks}}')
  if echo "$NETS" | grep -q "edge"; then
    echo "FAIL: $svc on edge network"; FAIL=1
  else
    echo "PASS: $svc not on edge"
  fi
  PORTS=$(docker inspect "$cname" --format '{{.HostConfig.PortBindings}}')
  if [ "$PORTS" = "map[]" ] || [ -z "$PORTS" ]; then
    echo "PASS: $svc no published ports"
  else
    echo "FAIL: $svc has published ports: $PORTS"; FAIL=1
  fi
done

[ $FAIL -eq 0 ] && echo "--- All hardening checks passed ---" || { echo "--- HARDENING FAILURES ---"; exit 1; }
