#!/bin/bash
# BMOS 3.2 Stage A — seed an admin user via postgres superuser (bypasses RLS).
# Usage: ADMIN_EMAIL=you@example.com ADMIN_PASSWORD=secret bash scripts/seed-admin.sh
set -euo pipefail

EMAIL="${ADMIN_EMAIL:-admin@bmos.local}"
PASSWORD="${ADMIN_PASSWORD:?Set ADMIN_PASSWORD env var}"
TENANT_ID="11111111-1111-1111-1111-111111111111"
USER_ID="aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa"

# Hash the password using the php container.
HASH=$(PW="$PASSWORD" docker compose exec -T -e PW php php -r 'echo password_hash(getenv("PW"), PASSWORD_DEFAULT);')
if [ -z "$HASH" ]; then
  echo "Failed to hash password" >&2
  exit 1
fi

# Insert user + membership as the bmos superuser (bypasses RLS).
# The fixture user aaaaaaaa-... may already exist — update it in place.
# Uses psql :'var' quoting (quoted heredoc) so bcrypt $ characters survive.
docker compose exec -T postgres psql -U bmos -d bmos -v ON_ERROR_STOP=1 \
  -v uid="$USER_ID" -v email="$EMAIL" -v hash="$HASH" <<'EOF'
INSERT INTO app.users (id, email, password_hash)
VALUES (:'uid', :'email', :'hash')
ON CONFLICT (id) DO UPDATE SET email = EXCLUDED.email, password_hash = EXCLUDED.password_hash;

INSERT INTO app.memberships (user_id, tenant_id, role)
VALUES (:'uid', '11111111-1111-1111-1111-111111111111', 'owner')
ON CONFLICT (user_id, tenant_id) DO UPDATE SET role = 'owner';
EOF

echo "Admin user ready: $EMAIL"
echo "Sign in at http://bmos.localhost/login"
