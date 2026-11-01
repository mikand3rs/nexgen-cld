#!/usr/bin/env bash
# BMOS 3.2 Phase 9 — initialize n8n: database, user, secrets.
#
# Creates:
#   - secrets/n8n_db_password.txt (if missing)
#   - secrets/n8n_encryption_key.txt (if missing)
#   - Postgres database n8n_db and user n8n_user
#
# Run BEFORE `docker compose --profile n8n up -d`:
#   bash scripts/n8n-init.sh
set -euo pipefail
cd "$(dirname "$0")/.."

# Secrets (generate if missing).
if [ ! -f secrets/n8n_db_password.txt ]; then
  openssl rand -base64 24 | tr -d '\n' > secrets/n8n_db_password.txt
  echo "created secrets/n8n_db_password.txt"
fi
if [ ! -f secrets/n8n_encryption_key.txt ]; then
  openssl rand -base64 32 | tr -d '\n' > secrets/n8n_encryption_key.txt
  echo "created secrets/n8n_encryption_key.txt"
fi
chmod 644 secrets/n8n_db_password.txt secrets/n8n_encryption_key.txt 2>/dev/null || true

# Database and user (idempotent).
echo "--- creating n8n database and user ---"
N8N_PW="$(cat secrets/n8n_db_password.txt)"
docker compose exec -T -e PGPASSWORD="$(cat secrets/postgres_password.txt)" postgres \
  psql -U "${POSTGRES_USER:-bmos}" -d "${POSTGRES_DB:-bmos}" -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'n8n_user') THEN
    CREATE ROLE n8n_user LOGIN PASSWORD '${N8N_PW}';
  END IF;
END
\$\$;
SELECT 'CREATE DATABASE n8n_db OWNER n8n_user'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'n8n_db')\gexec
GRANT ALL PRIVILEGES ON DATABASE n8n_db TO n8n_user;
SQL
echo "OK: n8n_db + n8n_user ready"
echo ""
echo "Start n8n with:  docker compose --profile n8n up -d"
