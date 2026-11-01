-- BMOS 3.2 Phase 7 — object metadata (authoritative).
-- Applied as bmos_migrator via scripts/migrate.sh (never as a superuser).
--
-- PostgreSQL is AUTHORITATIVE for object metadata. MinIO stores the binary
-- bytes only. Every row carries the bucket + object key so the bytes can be
-- located, verified (checksum), and re-fetched. Deleting the MinIO data
-- loses bytes; deleting these rows loses the business record.

CREATE TABLE app.objects (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id       uuid NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
  bucket          text NOT NULL,
  object_key      text NOT NULL,
  checksum        text,                       -- SHA-256 hex, set on complete
  size            bigint,                     -- bytes, set on complete
  media_type      text NOT NULL DEFAULT 'application/octet-stream',
  lifecycle_state text NOT NULL DEFAULT 'pending'
                  CHECK (lifecycle_state IN ('pending', 'uploaded', 'deleted')),
  created_by      uuid,                       -- app.users.id, nullable in demo
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now(),
  UNIQUE (bucket, object_key)
);

-- Explicit grants (default privileges from init also cover future tables).
GRANT SELECT, INSERT, UPDATE, DELETE ON app.objects TO bmos_app;

-- Tenant isolation: same shape as 001/002/003.
ALTER TABLE app.objects ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.objects FORCE ROW LEVEL SECURITY;
CREATE POLICY objects_isolation ON app.objects
  FOR ALL TO bmos_app
  USING (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
  WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid);
