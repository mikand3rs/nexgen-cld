# NexGen CLD v4 (BMOS 3.2) — Multi-tenant Business OS

**PHP 8.4 + PostgreSQL 17 + Traefik + Nginx**, with Qdrant (vector search),
Garage (S3-compatible objects), Lemonade (local GPU AI), n8n (workflows),
Redis (optional queue), and a locked-down agent sandbox.

**Architecture:** PHP-native, server-rendered. PHP owns business logic,
auth, and persistence; PostgreSQL is authoritative for all business state
with **forced row-level security** (tenant isolation is database-enforced,
not code-filtered). Qdrant and Garage are rebuildable derived stores.
The web UI is Tailwind CSS + HTMX + Alpine.js (Preline-inspired).

## Stack

| Service | Image | Role |
|---------|-------|------|
| Traefik | `traefik:v3` | Edge router (:80) |
| Nginx | `nginx:1.28-alpine` | PHP-FPM reverse proxy |
| PHP | `php:8.4-fpm-alpine` | API + web UI (server-rendered) |
| Worker | (same PHP image) | Outbox job runner |
| Postgres | `postgres:17-alpine` | Authoritative store, forced RLS |
| Qdrant | `qdrant/qdrant:v1.13.0` | Vector index (rebuildable) |
| Garage | `dxflrs/garage:v2.4.1` | S3-compatible objects |
| n8n | `n8nio/n8n:1.113.0` | Workflows (profile-gated) |
| Redis | `redis:7-alpine` | n8n queue (profile-gated, optional) |

Lemonade Server runs on the **Windows host** (GPU), not in Docker.
PHP reaches it via `LEMONADE_URL` (default `host.docker.internal:13305`).

## Quickstart

```bash
# 1. Secrets (never commit these — see docs/secrets.md)
mkdir -p secrets && chmod 700 secrets
openssl rand -base64 24 | tr -d '\n' > secrets/postgres_password.txt
openssl rand -base64 24 | tr -d '\n' > secrets/app_password.txt
openssl rand -base64 24 | tr -d '\n' > secrets/migrator_password.txt
openssl rand -hex 32 | tr -d '\n' > secrets/garage_rpc_secret.txt
openssl rand -base64 24 | tr -d '\n' > secrets/garage_admin_token.txt
touch secrets/garage_app_key_id.txt secrets/garage_app_secret.txt
chmod 644 secrets/*.txt
cp .env.example .env

# 2. Start core stack
bash scripts/garage-config.sh
docker compose up -d
bash scripts/migrate.sh          # applies 001–004
bash scripts/garage-init.sh      # Garage bucket + restricted key
docker compose restart php nginx

# 3. Open the dashboard
# http://bmos.localhost/dashboard
```

Optional profiles:
```bash
bash scripts/n8n-init.sh
docker compose --profile n8n up -d              # n8n (regular mode)
docker compose --profile n8n --profile queue up -d  # + Redis queue mode
docker compose --profile agents up -d           # agent sandbox
```

## Test suites

```bash
bash scripts/test-isolation.sh  # 9 passed — RLS tenant isolation
bash scripts/test-outbox.sh     # 17 passed — transactional outbox
bash scripts/test-qdrant.sh     # 11 passed — vector search, tenant-filtered
bash scripts/test-garage.sh     # 12 passed — S3 objects, presigned URLs
bash scripts/test-lemonade.sh   # 6 passed — local AI chat
bash scripts/test-n8n.sh        # 4 passed — workflow integration
bash scripts/test-redis.sh      # queue mode (optional)
bash scripts/test-agents.sh     # 7 passed — sandbox isolation
```

## Web UI

Server-rendered PHP + Tailwind (CDN) + HTMX + Alpine.js, Preline-inspired.
Sidebar IA: Command Center / Operations / AI Workforce / Automation / Administration.

- `/dashboard` — stats, recent tasks, system status
- `/tasks` — task management (HTMX)
- `/ai-ui` — AI chat (Lemonade)
- `/documents` — document search
- `/objects-ui` — file upload/download (presigned URLs)
- `/workflows` — n8n integration dashboard
- `/tenants`, `/health-ui` — administration

Demo auth: `X-Tenant-ID` header (UUID). **Not real authentication** — replace
before any multi-user deployment.

---

## Phase build notes (historical)

## The outbox pattern (Phase 5)

`POST /jobs/enqueue` (demo auth stand-in: `X-Tenant-ID` header — NOT real
authentication) writes a business record (`app.tasks`) **and** the outbox job
(`app.outbox_jobs`) in **one** transaction: either both commit or neither does.
A duplicate `idempotency_key` returns the original job with
`"deduplicated": true` and writes nothing new.

```bash
curl -s -X POST http://bmos.localhost/jobs/enqueue \
  -H "X-Tenant-ID: 11111111-1111-1111-1111-111111111111" \
  -H 'Content-Type: application/json' \
  -d '{"job_type":"task.created","title":"first task","idempotency_key":"demo-1"}'
# {"job_id":"...","task_id":"...","status":"pending","deduplicated":false}
```

The worker (`docker compose logs -f worker`) then:
1. **Peeks** for a pending job with no tenant context (the only cross-tenant
   read bmos_app gets — that is how a multi-tenant worker finds work).
2. **Sets** `app.current_tenant` to the job's tenant — from here it is
   RLS-bound exactly like a web request.
3. **Claims** with `SELECT ... FOR UPDATE SKIP LOCKED` (a lost race claims
   zero rows; a claim without tenant context is impossible by RLS design).
4. **Works** (demo handlers: `task.created`, `demo.fail_once`) and
5. **Records** `done` / `pending`-with-backoff / `dead`, then commits.

RLS note: `outbox_discovery` lets bmos_app SELECT pending jobs across tenants
(worker discovery); every other operation on the table is tenant-scoped via
`outbox_tenant_isolation`. No web endpoint exposes outbox rows in Phase 5, so
the discovery exception never reaches a client. Real auth replaces the
`X-Tenant-ID` header in a later phase.

Shutdown note: the worker handles SIGTERM/SIGINT gracefully (finishes the
current job, then exits). A SIGKILL/crash mid-job leaves the row in
`claimed` — there is no automatic reaper in Phase 5; recover with
`UPDATE app.outbox_jobs SET status='pending' WHERE status='claimed'` (as a
superuser) or wait for the reaper in a later phase. Handlers must stay
idempotent for exactly this reason.

## Notes

- **Two networks:** `edge` (Traefik, Nginx) and `backend` (internal — Nginx, PHP, worker, PostgreSQL).
  Nothing on `backend` can reach the host or internet. When Lemonade arrives (Phase 8),
  PHP gets attached to an egress-capable network.
- **Postgres is not published** and the app talks to it as `postgres:5432` over Docker DNS.
  For a temporary GUI look, use the loopback example in `compose.override.yaml`.
- **Secrets:** file-based Compose secrets under `secrets/`. Local Compose bind-mounts
  them at `/run/secrets/<name>` — that is NOT encrypted or RAM-only storage, so the
  files stay chmod 644 (php-fpm workers run as www-data and must read them;
  the 700 directory is what blocks other host users) and out of Git. Real secret manager before production.
- **Roles:** `bmos_owner` owns schemas/tables and can never log in; `bmos_migrator`
  applies `migrations/*.sql`; `bmos_app` is the runtime role (`NOBYPASSRLS`) and the
  only role the app, the worker, and the tests use. RLS is **forced**, so it applies even to
  table owners; the tenant context comes from `SET app.current_tenant = '<uuid>'`
  per transaction (`SET LOCAL` inside `BEGIN`/`COMMIT`).
- **Liveness vs readiness:** `/health` never touches the database; `/ready` connects
  as `bmos_app` and returns 503 while the database is unreachable.

## Version matrix

| Component  | Image / version used | Notes                    |
|------------|----------------------|--------------------------|
| Traefik    | `traefik:v3`         | Observed 3.7.17, 2026-11-01 |
| Nginx      | `nginx:1.28-alpine`  |                          |
| PHP        | `php:8.4-fpm-alpine` | `pdo_pgsql`, `pcntl` compiled in |
| PostgreSQL | `postgres:17-alpine` |                          |
| Qdrant     | `qdrant/qdrant:v1.13.0` | Derived vector index only |
| Garage     | `dxflrs/garage:v2.4.1` | S3-compatible object storage, backend-only |

Update one component at a time and re-run the gates.

## Phase 6 — Qdrant as a derived index

PostgreSQL (`app.documents`, `app.document_chunks`) is the authoritative
store. Qdrant holds vectors only; every point payload carries `tenant_id`,
`document_id`, `chunk_id`, and `source_version`, so the collection can be
wiped and rebuilt from PostgreSQL at any time.

- **Embeddings are demo placeholders** (`app/src/Embeddings.php`,
  deterministic hash-based, no semantic meaning). The vector dimension comes
  from `QDRANT_VECTOR_SIZE` (default 384) — never hardcoded — and must match
  the real model once one is selected. Swap in a real model by replacing
  `DemoEmbeddings::generate()`.
- **Tenant filter is server-generated.** `POST /search` takes the tenant from
  the `X-Tenant-ID` header; a `tenant_id` in the request body is ignored.
  There is no unfiltered search path.
- **Flow:** `POST /documents` → PostgreSQL → `scripts/index-documents.php`
  (or the `document.index` outbox job) → Qdrant. `POST /search` → embed query
  → Qdrant with tenant filter.

```bash
docker compose up -d
bash scripts/migrate.sh                                    # APPLY 003-documents
docker compose exec -T php php scripts/qdrant-init.php    # create collection
bash scripts/test-qdrant.sh                               # 13 checks
```

Rebuild demo: `docker compose exec -T php php scripts/rebuild-qdrant.php`

## What's next

**Hard gate (met 2026-11-01):** all isolation tests pass (9/9 as `bmos_app`),
so tenant isolation is database-enforced.

**Phase 6 gate (met 2026-11-01):** 11/11 Qdrant checks — server-generated
tenant filter on every query, forged client tenant IDs ignored, cross-tenant
retrieval returns no foreign points, and wipe-and-rebuild restores the index
without touching PostgreSQL.

## Phase 7 — Garage S3 object storage

PostgreSQL (`app.objects`) is authoritative for metadata; Garage holds bytes
only. Clients never talk to Garage directly — they use short-lived presigned
URLs minted by PHP after BMOS authorization:

1. `POST /objects` → PG record (pending) + presigned PUT URL
2. Client PUTs bytes directly to Garage
3. `POST /objects/{id}/complete` → HEAD verification → state=uploaded
4. `GET /objects/{id}/download` → tenant-checked presigned GET URL
5. Client GETs bytes directly from Garage

- **Not publicly exposed:** Garage's S3 API (:3900) is on
  the backend network only — no published ports, no Traefik router.
- **Non-root identity:** PHP's S3 client uses a restricted Garage key
  (read+write on the bucket only, from secrets); the admin token is setup-only.
- **Secrets:** create `secrets/garage_rpc_secret.txt` and
  `secrets/garage_admin_token.txt` (see `docs/secrets.md`); the app key
  files are created by the init script.

```bash
docker compose up -d
bash scripts/migrate.sh                                    # APPLY 004-objects
bash scripts/garage-config.sh                              # generate docker/garage.toml
bash scripts/garage-init.sh                                # layout + bucket + app key
bash scripts/test-garage.sh                                # 12 checks
```

## Phase 8 — Windows-hosted Lemonade

Lemonade Server stays on the Windows host (GPU) — it is NOT a container.
PHP reaches it at `LEMONADE_URL` (default `http://host.docker.internal:13305`,
Docker Desktop's host gateway). Lemonade receives no PostgreSQL credentials
and no BMOS secrets; it is pure inference. Qwen3.5 requests set
`chat_template_kwargs.enable_thinking=false`.

- `GET /ai/health` — Lemonade reachable? lists models.
- `POST /ai/chat` — tenant-scoped chat (demo `X-Tenant-ID` header, same
  stand-in as other endpoints; the tenant id never leaves PHP).

```bash
bash scripts/test-lemonade.sh   # needs Lemonade running on the Windows host
```

## Phase 9 — n8n workflow automation (profile-gated)

n8n runs in regular mode (single instance), started only with
`docker compose --profile n8n up -d`. It calls BMOS APIs via the PHP
endpoints — never bypasses BMOS authorization, never gets direct DB
credentials to the app database. n8n uses its own `n8n_db` Postgres
database with a dedicated `n8n_user`.

```bash
bash scripts/n8n-init.sh                  # DB + user + secrets
docker compose --profile n8n up -d        # start n8n
bash scripts/test-n8n.sh                  # gates
```

## Phase 10 (optional) — Redis + n8n Queue Mode

Redis holds ephemeral Bull job data only (no business state). n8n Queue
Mode is opt-in: uncomment `N8N_EXECUTIONS_MODE=queue` and
`N8N_REDIS_HOST=redis` in `.env`, then start with both profiles.

```bash
docker compose --profile n8n --profile queue up -d
bash scripts/test-redis.sh
```

## Phase 11 — Paperclip / coding-agent sandbox (LAST)

Sandboxed environment for AI coding agents. Profile-gated, locked down:

- **NO** Docker socket (cannot control Docker/host).
- **NO** production DB credentials (no direct database access).
- **NO** BMOS secrets mounted.
- Backend network only — calls BMOS APIs via `http://nginx` (auth enforced).
- Narrow adapter: the agent uses the API, not shell access to prod.

```bash
docker compose --profile agents up -d
bash scripts/test-agents.sh
```

All BMOS 3.2 phases complete.

## Phase 12 — Dashboard UI (Preline-inspired)

Server-rendered PHP + Tailwind (CDN) + HTMX + Alpine.js. Preline Admin
Dashboard is the presentation inspiration; the sidebar IA follows the
board guidelines (Command Center / Operations / AI Workforce / Automation /
Administration).

- `GET /` → redirect to `/dashboard`
- `GET /dashboard` → shell + stats cards, recent tasks, system status
  (HTMX partials: `/dashboard/stats`, `/dashboard/recent-tasks`,
  `/dashboard/system`)
- Tenant UUID input in the header; HTMX sends it as `X-Tenant-ID`.

Next: Tasks UI, Documents UI, Files UI, AI Chat UI.
