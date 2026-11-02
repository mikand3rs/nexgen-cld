# v4.0.0 — BMOS 3.2 + CRM 1.0

First tagged release of NexGen CLD v4: a self-hosted, multi-tenant Business
Operating System. PHP 8.4, PostgreSQL 17 with database-enforced tenant
isolation, server-rendered UI (no SPA framework), local GPU and cloud AI.

## What's in this release

**Core kernel (BMOS 3.2)**
- Traefik v3 → Nginx 1.28 → PHP 8.4-FPM → PostgreSQL 17, Docker Compose
- Forced Row-Level Security on all tenant tables; three-role least privilege
  (`bmos_owner` / `bmos_migrator` / `bmos_app`)
- Real auth: bcrypt sessions, 8h idle timeout, verified-membership tenants,
  hashed/expiring/revocable per-tenant API keys
- Transactional outbox worker (leases, heartbeats, reaper, dead-letter queue)
- Qdrant vector index (tenant-scoped by design), Garage S3 object storage
  (presigned URLs), n8n + optional Redis queue
- Agent sandbox: isolated container running an allowlisted runner (no shell
  action, no blacklist); human approvals with no self-approval
- Container hardening: read-only filesystems, dropped Linux capabilities,
  no-new-privileges
- Dark server-rendered UI: dashboard, tasks, AI chat, documents, files,
  workflows, tenants, system health

**CRM 1.0** — leads, accounts, contacts, notes, activities, search.
Lead conversion is transactional and preserves history; notes refuse
credential material.

**AI provider abstraction** — one `AI_PROVIDER` switch: local Lemonade GPU,
Ollama, Mistral, DeepSeek, OpenAI, Groq, or any OpenAI-compatible endpoint.
Keys load from env/secret files, never committed.

## Test evidence

| Suite | Result |
|---|---|
| RLS tenant isolation | 9/9 |
| Cross-tenant API checks | 6/6 |
| Auth lifecycle | 4/4 |
| Outbox / worker reliability | 17/17 + 6/6 recovery |
| Qdrant tenant filtering | 11/11 |
| Garage S3 | 12/12 |
| AI providers | 25/25 |
| CRM 1.0 (HTTP + DB isolation) | 37/37 |
| Container hardening | pass |
| Agent sandbox isolation | 9/9 |

Run everything with `bash scripts/verify.sh`.

## Honest roadmap (not in this release)

- Projects, Evidence/Approvals, Audit UI — planned (Stage D)
- Public docs portal — this release starts it
- Managed cloud tier, automated TLS, offsite backups, HA clustering,
  marketplace — commercial roadmap, not in the open-source kernel
- CSRF tokens, TLS-by-default, rate limiting, 2FA — not yet implemented

## Quickstart

```bash
cp .env.example .env        # fill in secrets (see docs/secrets.md)
docker compose up -d --build
bash scripts/seed-admin.sh  # create the first admin
```

Open http://bmos.localhost (add `127.0.0.1 bmos.localhost` to your hosts file).

Full guide: https://mikand3rs.github.io/nexgen-cld/
