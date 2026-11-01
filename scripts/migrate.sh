#!/usr/bin/env bash
# BMOS 3.2 Phase 2 — apply pending migrations as bmos_migrator.
# Idempotent: app.schema_migrations records what already ran.
# Passwords are read from secrets/ and never printed.
set -euo pipefail
cd "$(dirname "$0")/.."

set -a
[ -f .env ] && . ./.env
set +a
DB="${POSTGRES_DB:-bmos}"

PW="$(cat secrets/migrator_password.txt)"
PSQL=(docker compose exec -T -e "PGPASSWORD=$PW" postgres
      psql -U bmos_migrator -d "$DB" -v ON_ERROR_STOP=1 -q)

applied="$("${PSQL[@]}" -tA -c "SELECT version FROM app.schema_migrations;")"

for f in migrations/*.sql; do
  version="$(basename "$f" .sql)"
  if grep -qx "$version" <<<"$applied"; then
    echo "SKIP  $version (already applied)"
    continue
  fi
  echo "APPLY $version"
  "${PSQL[@]}" -f - < "$f"
  "${PSQL[@]}" -c "INSERT INTO app.schema_migrations(version) VALUES ('$version');"
done
echo "migrations up to date"
