-- NexGen CLD — V2 (RAG) + V3 (Mixture of Experts) schema
-- PostgreSQL 17. Applied by app/db/migrate.php, in this order.
-- Rules carried from the V2/V3 plan:
--   * tenant_id is mandatory on every row that holds a tenant's data.
--   * Every retrieval / job query filters tenant_id FIRST, then scope.
--   * PHP is the control plane; workers claim jobs, they are never
--     spawned per request with exec() from a web request.
--   * No uploaded file is stored under the web root, and no uploaded
--     content is ever executed.
--
-- Vector / embedding decision (recorded, not locked in):
--   Retrieval in v2/v3 works TODAY on PostgreSQL full-text search
--   (content_tsv). The embedding column is added ONLY if the
--   pgvector extension is available (see migrate.php). If it is not,
--   migration succeeds without it, embedding_model/dimensions stay
--   NULL, and retrieval falls back to FTS. Do NOT add Qdrant here
--   without a measured trigger from the pgvector-vs-Qdrant research
--   (filtered recall < 0.95, retrieval p95 > 200ms, ~1-2M vectors,
--   or a second embedding model becoming core). When that trigger
--   fires, keep PostgreSQL as truth and migrate vectors, do not
--   replace this schema.

CREATE TABLE IF NOT EXISTS tenants (
    id SERIAL PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Documents: one row per uploaded file. Status flow:
-- pending -> processing -> indexed | failed | partially_indexed
-- deleted documents keep their row (status='deleted') so chunk
-- cleanup is auditable; chunks are removed in the same step.
CREATE TABLE IF NOT EXISTS documents (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    collection TEXT NOT NULL DEFAULT 'general',
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    size_bytes BIGINT NOT NULL CHECK (size_bytes >= 0),
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','processing','indexed','failed','partially_indexed','deleted')),
    error TEXT,
    uploaded_by TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_documents_tenant_status ON documents (tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_documents_tenant_collection ON documents (tenant_id, collection);

-- Chunks: one row per overlapping text chunk. content_tsv is the
-- working retrieval index today. embedding is added by migrate.php
-- only when pgvector is available; it is NOT in this file on purpose,
-- so this schema applies cleanly on plain postgres:17-alpine.
CREATE TABLE IF NOT EXISTS doc_chunks (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    chunk_index INTEGER NOT NULL CHECK (chunk_index >= 0),
    content TEXT NOT NULL,
    content_tsv TSVECTOR,
    embedding_model TEXT,
    embedding_dimensions INTEGER,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (document_id, chunk_index)
);
CREATE INDEX IF NOT EXISTS idx_chunks_tenant_doc ON doc_chunks (tenant_id, document_id);
CREATE INDEX IF NOT EXISTS idx_chunks_tsv ON doc_chunks USING GIN (content_tsv);

-- Ingestion jobs: one per document. Worker claims with
-- FOR UPDATE SKIP LOCKED. Attempts + next_retry_at make failures
-- visible and retryable, never silent.
CREATE TABLE IF NOT EXISTS ingestion_jobs (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','processing','completed','failed')),
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    error TEXT,
    next_retry_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_ingestion_pending ON ingestion_jobs (status, next_retry_at) WHERE status = 'pending';

-- Experts: registry. Versioned: every config change inserts a new
-- row with version+1 for the same slug; jobs record expert_version.
-- The seed file (app/db/seeds/experts.json) is the source of truth
-- for a fresh deploy; the database is the runtime source of truth.
CREATE TABLE IF NOT EXISTS experts (
    id SERIAL PRIMARY KEY,
    slug TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    system_prompt TEXT NOT NULL,
    model TEXT NOT NULL DEFAULT '',
    temperature NUMERIC(3,2) NOT NULL DEFAULT 0.20,
    top_k INTEGER NOT NULL DEFAULT 5,
    retrieval_scope TEXT NOT NULL DEFAULT '[]',
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    is_router BOOLEAN NOT NULL DEFAULT FALSE,
    is_synthesizer BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (slug, version)
);
CREATE INDEX IF NOT EXISTS idx_experts_slug_enabled ON experts (slug, enabled);

-- Per-tenant expert overrides: enable/disable, and optionally
-- replace the system prompt for that tenant only. Never changes
-- the global seed for other tenants.
CREATE TABLE IF NOT EXISTS tenant_expert_overrides (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    expert_slug TEXT NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    system_prompt_override TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, expert_slug)
);

-- Expert runs: one per /ask request. Expert jobs: one per expert
-- in that run. A run is complete when all its jobs are complete or
-- failed; the synthesizer job (if any) runs last and writes the
-- run's final answer.
CREATE TABLE IF NOT EXISTS expert_runs (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    question TEXT NOT NULL,
    router_experts TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','running','completed','failed','partial')),
    answer TEXT,
    sources TEXT NOT NULL DEFAULT '[]',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS expert_jobs (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    run_id INTEGER REFERENCES expert_runs(id) ON DELETE CASCADE,
    expert_slug TEXT NOT NULL,
    expert_version INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','processing','completed','failed')),
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    input_text TEXT NOT NULL DEFAULT '',
    result_text TEXT,
    sources TEXT NOT NULL DEFAULT '[]',
    error TEXT,
    next_retry_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_expert_jobs_pending ON expert_jobs (status, next_retry_at) WHERE status = 'pending';
CREATE INDEX IF NOT EXISTS idx_expert_jobs_run ON expert_jobs (run_id, status);

-- Row-level security, defense-in-depth.
-- NOTE: PostgreSQL RLS does NOT apply to the table owner. The PHP
-- container connects as the owner (nexgen), so the real enforcement
-- in this codebase is the tenant_id = ? filter in EVERY query in
-- app/lib/*.php. RLS below protects a future non-owner app role:
-- set app.current_tenant per transaction (see app/lib/db.php) and
-- connect as that role, and a tenant can never read another tenant.
ALTER TABLE documents ENABLE ROW LEVEL SECURITY;
ALTER TABLE doc_chunks ENABLE ROW LEVEL SECURITY;
ALTER TABLE ingestion_jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE expert_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE expert_jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE tenant_expert_overrides ENABLE ROW LEVEL SECURITY;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE policyname = 'tenant_isolation_documents') THEN
        CREATE POLICY tenant_isolation_documents ON documents
            USING (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::integer);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE policyname = 'tenant_isolation_chunks') THEN
        CREATE POLICY tenant_isolation_chunks ON doc_chunks
            USING (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::integer);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE policyname = 'tenant_isolation_ingestion') THEN
        CREATE POLICY tenant_isolation_ingestion ON ingestion_jobs
            USING (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::integer);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE policyname = 'tenant_isolation_runs') THEN
        CREATE POLICY tenant_isolation_runs ON expert_runs
            USING (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::integer);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE policyname = 'tenant_isolation_expert_jobs') THEN
        CREATE POLICY tenant_isolation_expert_jobs ON expert_jobs
            USING (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::integer);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE policyname = 'tenant_isolation_overrides') THEN
        CREATE POLICY tenant_isolation_overrides ON tenant_expert_overrides
            USING (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::integer);
    END IF;
END $$;
