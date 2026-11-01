#!/usr/bin/env bash
# BMOS 3.2 Phase 2 — 02-passwords.sh
# Runs once at first volume init (alphabetically after 01-roles-schema.sql).
# Sets the LOGIN role passwords from Compose secret files mounted at
# /run/secrets/*. The :'var' psql quoting keeps special characters safe.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  -v app_pw="$(cat /run/secrets/app_password)" \
  -v migrator_pw="$(cat /run/secrets/migrator_password)" <<'EOSQL'
ALTER ROLE bmos_app WITH PASSWORD :'app_pw';
ALTER ROLE bmos_migrator WITH PASSWORD :'migrator_pw';
EOSQL
