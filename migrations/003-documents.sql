-- BMOS 3.2 Phase 6 — documents and chunks.
-- Applied as bmos_migrator via scripts/migrate.sh (never as a superuser).
--
-- PostgreSQL is the AUTHORITATIVE store for document and chunk metadata.
-- Qdrant holds a derived vector index only: every point's payload carries
-- tenant_id, document_id, chunk_id, and source_version, and the whole
-- Qdrant collection can be wiped and rebuilt from these tables at any time
-- (scripts/rebuild-qdrant.php). Deleting Qdrant never destroys business records.

CREATE TABLE app.documents (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id  uuid NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
  title      text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE app.document_chunks (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  document_id    uuid NOT NULL REFERENCES app.documents(id) ON DELETE CASCADE,
  tenant_id      uuid NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
  chunk_index    int NOT NULL,
  content        text NOT NULL,
  source_version int NOT NULL DEFAULT 1,
  created_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (document_id, chunk_index)
);

-- Explicit grants (default privileges from init also cover future tables).
GRANT SELECT, INSERT, UPDATE, DELETE
  ON app.documents, app.document_chunks TO bmos_app;

-- Tenant isolation: same shape as 001/002. A session only sees rows for
-- the tenant in app.current_tenant; missing/empty context matches nothing.
ALTER TABLE app.documents ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.documents FORCE ROW LEVEL SECURITY;
CREATE POLICY documents_isolation ON app.documents
  FOR ALL TO bmos_app
  USING (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
  WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid);

ALTER TABLE app.document_chunks ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.document_chunks FORCE ROW LEVEL SECURITY;
CREATE POLICY chunks_isolation ON app.document_chunks
  FOR ALL TO bmos_app
  USING (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
  WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid);
