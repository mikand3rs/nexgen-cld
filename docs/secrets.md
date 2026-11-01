# Secrets

File-based secrets for `docker compose`. Create each file with the secret
content and **no trailing newline issues** (use `printf` or a text editor).

Directory must be `chmod 700`, files `chmod 644` (see main README for why
644: the php-fpm workers run as a non-root user and must read `app_password`;
the 700 directory is what keeps other host users out).

## Required files

| File | Used by | Notes |
|------|---------|-------|
| `postgres_password.txt` | postgres (superuser) | Generate fresh; never reuse a value that appeared in chat/logs |
| `app_password.txt` | php, worker (bmos_app role) | Generate fresh |
| `migrator_password.txt` | migrations (bmos_migrator role) | Generate fresh |
| `garage_rpc_secret.txt` | garage (cluster RPC) | 64 hex chars: `openssl rand -hex 32` |
| `garage_admin_token.txt` | garage (admin API) | Generate fresh: `openssl rand -base64 24` |
| `garage_app_key_id.txt` | php (S3 client) | Created by `scripts/garage-init.sh`; touch empty before first `up -d` |
| `garage_app_secret.txt` | php (S3 client) | Created by `scripts/garage-init.sh`; touch empty before first `up -d` |
| `n8n_db_password.txt` | n8n (its own DB) | Created by `scripts/n8n-init.sh` |
| `n8n_encryption_key.txt` | n8n (credential encryption) | Created by `scripts/n8n-init.sh` |

Generate the Garage cluster secrets before first start:
```bash
openssl rand -hex 32 | tr -d '\n' > secrets/garage_rpc_secret.txt
openssl rand -base64 24 | tr -d '\n' > secrets/garage_admin_token.txt
touch secrets/garage_app_key_id.txt secrets/garage_app_secret.txt
chmod 700 secrets && chmod 644 secrets/*.txt
```

Then generate the Garage config (embeds the two cluster secrets into
`docker/garage.toml`, which is gitignored and not shipped in the zip):

    bash scripts/garage-config.sh

Then `docker compose up -d`, `bash scripts/migrate.sh`,
`bash scripts/garage-init.sh` (creates the restricted S3 key and fills in
the app key files), `docker compose restart php`, and
`bash scripts/test-garage.sh`.

**Never commit these files.** They are gitignored. If a secret value ever
appears in chat, terminal output, or logs, it is burned — generate a fresh
one.
