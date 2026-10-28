# NexGen CLD

A practical digital-services and business-operations platform for
small businesses. PHP-native, server-rendered. PHP owns business
logic, authorization, validation, orchestration, and persistence;
workers own long-running execution.

- **V1 (already in this repo):** chat at `/`, PostgreSQL health at
  `/health`, Lemonade (Qwen3.5-2B-GGUF) via `app/ai-connect.php`.
- **V2 (this change):** document ingestion — secure uploads,
  background ingestion worker, tenant-scoped retrieval chat.
- **V3 (this change):** mixture of experts — expert registry,
  rules-based router, orchestration jobs, per-expert retrieval,
  synthesizer, seeded with a small-team catalogue for running
  NexGen Cloud.

## Quick start (in your local clone)

```bash
# 1. Copy the new files from the v2v3 zip into your local clone,
#    review the diff, then:
docker compose up --build -d

# 2. Migrate + seed (inside the php container)
docker compose exec php php /var/www/html/db/migrate.php

# 3. Start the worker (Compose already runs it as the worker service;
#    this is the manual equivalent)
docker compose exec php php /var/www/html/worker.php --loop

# 4. Open the stack
#    http://127.0.0.1:8080/            chat (v1, unchanged)
#    http://127.0.0.1:8080/upload.php  upload documents
#    http://127.0.0.1:8080/documents.php  document status
#    http://127.0.0.1:8080/ask.php     ask the experts
```

Run the unit tests (no DB, no AI needed):

```bash
php app/tests/run-tests.php
# inside the container: docker compose exec php php /var/www/html/tests/run-tests.php
```

## What was added, file by file

| File | What it does |
|---|---|
| `app/db/schema.sql` | tenants, documents, doc_chunks, ingestion_jobs, experts, tenant_expert_overrides, expert_runs, expert_jobs, plus RLS policies |
| `app/db/migrate.php` | CLI: applies schema, tries pgvector (optional, see below), seeds tenant + experts |
| `app/db/seeds/experts.json` | Expert catalogue templates, version 1 (source of truth for fresh deploys) |
| `app/lib/db.php` | PDO + tenant resolution + private storage root |
| `app/lib/ingestion.php` | Upload validation, PDF/text extraction, chunking (~1200 chars, ~200 overlap), ingestion |
| `app/lib/retrieval.php` | Tenant-first FTS retrieval, grounded prompt, sources |
| `app/lib/experts.php` | Registry loading, rules router, orchestrator, synthesizer |
| `app/worker.php` | CLI worker: ingestion + expert jobs, `FOR UPDATE SKIP LOCKED`, retries, stale reclaim |
| `app/public/upload.php` | Upload form + handler (GET form, POST multipart) |
| `app/public/documents.php` | Per-tenant document + ingestion status view |
| `app/public/ask.php` | Ask endpoint (JSON + form): router, single-expert sync, multi-expert poll |
| `app/tests/run-tests.php` | Pure-function tests: chunking, routing, upload validation, seeds |
| `compose.yaml` | Added `worker` service |
| `docker/php.Dockerfile` | Added curl, mbstring, fileinfo, poppler-utils (pdftotext) |

V1 routes (`/`, `/chat`, `/health`) are unchanged, except for a
navigation paragraph on the home page linking to the new pages.

## Document ingestion (V2)

- **Accepted:** `.txt`, `.md`, `.pdf`, up to 10 MB.
- **Rejected with a clear reason:** wrong extension, mismatched
  actual type (finfo), empty file, oversized file.
- **Out of scope for v2:** scanned-image OCR, `.docx`, spreadsheets.
  Those are rejected or fail visibly, not silently stored.
- **Storage:** `app/storage/uploads/<tenant_id>/<document_id>/<id>.<ext>`,
  outside the public web root, never web-accessible, never executed.
  Uploaded files are gitignored (`app/storage/`).
- **Flow:** upload → document `pending` → ingestion job `pending` →
  worker extracts → chunks → `indexed` (or `failed` with a visible
  reason). The web request never runs ingestion and never calls
  `exec()` to launch a worker. PDF extraction prefers `pdftotext`;
  if it is missing, a pure-PHP FlateDecode fallback is tried, and
  if that yields nothing, the job fails visibly.
- **Collections:** upload picks a collection (`general`, `operations`,
  `website`, `support`, `admin`). Experts retrieve only from their
  declared collections (empty = all). This is how a website question
  does not pull finance documents, and vice versa.

## Mixture of experts (V3) — seeded catalogue

Seeded in `app/db/seeds/experts.json`, for a small team running
NexGen Cloud. Every prompt says: answer from retrieved documents
first, and never invent prices, dates, or commitments.

| Slug | Role |
|---|---|
| `general-operations` | Day-to-day running: SOPs, checklists, scheduling |
| `website` | Building/upgrading/integrating small business websites |
| `client-onboarding` | Intake, scopes, proposals, client emails, handover |
| `support` | Triage + fix-it for Docker, PHP, PostgreSQL, nginx, deployments |
| `finance-admin` | Invoicing, pricing, subscriptions, admin paperwork |
| `synthesizer` | Internal: combines expert answers, keeps sources, surfaces disagreements |
| `router` | Internal: rules-based routing record, fallback = `general-operations` |

**Router:** rules-based keyword groups, deterministic and testable
(see `app/tests/run-tests.php`). At most 2 experts per run.
No match → `general-operations`. Never a random expert.

**Ask flow:**
1. `POST /ask.php` with `tenant_slug`, `question`, optional
   `expert_slug` (forces one expert, for testing/operator use).
2. Router picks experts → orchestrator writes a run + jobs.
3. Single expert (default sync): runs inline, returns answer + sources.
4. Multi-expert: jobs run in the worker (sequentially, safe on the
   laptop), synthesizer runs last, caller polls
   `GET /ask.php?run_id=…&tenant_slug=…` until `completed`,
   `partial` (some experts failed, labelled in the answer), or `failed`.
5. Every answer lists its sources as `[n] document (chunk n)`.

**Per-expert retrieval:** every query filters `tenant_id` first,
then the expert's collections, then full-text rank. Top-k comes
from the expert config (default 5).

## Vector store / embedding decision — recorded, not locked in

Retrieval in this change works on **PostgreSQL full-text search**
(`content_tsv`, `ts_rank`). That is deliberate, because the
vector-store decision from the V2/V3 plan was still open:

- `migrate.php` tries `CREATE EXTENSION vector`. Plain
  `postgres:17-alpine` does **not** include pgvector, so migration
  prints a warning and continues without vectors — it does not fail.
- To enable vectors later: change the postgres image in
  `compose.yaml` to `pgvector/pgvector:pg17`, re-run `migrate.php`
  (it adds `doc_chunks.embedding vector(1536)` + an HNSW index),
  then implement `embed_question()` in `app/lib/retrieval.php`
  with the **same model and dimensions** as the chunks.
- Do **not** add Qdrant without a measured trigger from the
  pgvector-vs-Qdrant research: filtered recall < 0.95 after tuning,
  retrieval p95 > 200 ms, ~1–2 M vectors, or a second embedding
  model becoming core. If that trigger fires, keep PostgreSQL as
  truth and migrate vectors — do not replace this schema.
- Embedding model is **not chosen** in this change. Chat model
  (Qwen3.5-2B-GGUF via Lemonade) and embedding model are different
  jobs, and shipping a guessed embedding call would produce wrong
  vectors. Record the model + dimensions in the migration output
  when you choose it.

## Tenant isolation

- Every document, chunk, job, run, and override row carries
  `tenant_id`, and every query in `app/lib/*.php` filters it.
- RLS policies in `schema.sql` are defense-in-depth for a future
  non-owner app role. PostgreSQL RLS does **not** apply to the
  table owner (the `nexgen` user the container uses), so the
  tenant filter in code is the real enforcement today — do not
  remove it from any query.
- Manual gate (must pass before trusting this with real client
  documents): Tenant A uploads a document; Tenant B asks a question
  that document would answer. Tenant B must get no answer from
  Tenant A's document, at the database level and at the chat level.

## Security notes — read before exposing this beyond a trusted team

- **No authentication in this foundation.** Upload, documents,
  and ask take a `tenant_slug` form/JSON field. That is fine for
  a trusted small team on localhost, and **not** fine for a public
  deployment. Add session auth and replace the tenant field with
  the authenticated tenant before exposing it.
- **`.env.example` was fixed in this change.** The previous version
  in the repo contained a 36-character password value, not a
  placeholder. Treat that value as exposed: rotate
  `POSTGRES_PASSWORD` if it was ever used for a real database.
  The new example uses `change-me-to-a-new-password`.
- **Never commit `.env`, uploaded documents, or the storage folder.**
  They are gitignored. Check `git status` before every commit.
- **Never paste secrets into chat or terminal output.** A secret
  that appears in chat/terminal is burned — generate a fresh one.

## Manual gates (not covered by unit tests — run these on the stack)

1. Tenant isolation (above).
2. Upload tests: oversized, wrong extension, mismatched type, empty,
   and a PDF that extracts correctly.
3. Retrieval tests: a question with a known answer in the documents,
   a question with no answer (must say so, not invent), and a
   partial-answer question.
4. Worker tests: stop/restart the worker (pending jobs picked up,
   not lost or duplicated), failed extraction, document deleted
   mid-ingestion.
5. Routing tests: the evaluation set in `app/tests/run-tests.php`,
   plus low-confidence and out-of-scope questions.
6. Failure tests: one expert fails while others succeed (answer
   must be labelled `partial`, not silently wrong).

## Out of scope (unless you add them)

- Public multi-tenant hosted deployment (production is a separate
  design from the local WSL laptop stack).
- OCR, `.docx`, spreadsheets.
- A second orchestration layer alongside PHP (the 2026-10-19
  LiteLLM / semantic-router stack is a separate experiment, not
  run alongside this one in production without a stated reason).
- Any feature that stores a tenant's documents, vectors, or chat
  history without a tenant on every row.
