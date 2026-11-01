-- BMOS 3.2 Phase 5 — migration 002: transactional outbox + demo business table.
-- Applied as bmos_migrator via scripts/migrate.sh (never as a superuser).
--
-- The pattern (guide section 6, Phase 5): an API transaction writes its
-- business record AND its outbox job row atomically. The PHP worker later
-- claims the job, sets app.current_tenant to the job's tenant, performs the
-- work under the same RLS rules as a web request, and records the result.
--
-- app.tasks is the demo business record. Real business tables in later
-- phases follow the same shape: tenant_id FK, forced RLS, tenant policy.

CREATE TABLE app.tasks (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id  uuid NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
  title      text NOT NULL,
  status     text NOT NULL DEFAULT 'open'
             CHECK (status IN ('open', 'notified', 'done')),
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE app.outbox_jobs (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id       uuid NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
  job_type        text NOT NULL,
  payload         jsonb NOT NULL DEFAULT '{}',
  -- Idempotency keys are scoped per tenant: two tenants may both use
  -- "order-123" without colliding, and a duplicate lookup can never
  -- return another tenant's job. (NULL keys never conflict: PostgreSQL
  -- treats NULLs as distinct in unique constraints.)
  idempotency_key text,
  status          text NOT NULL DEFAULT 'pending'
                  CHECK (status IN ('pending', 'claimed', 'done', 'failed', 'dead')),
  attempts        int NOT NULL DEFAULT 0 CHECK (attempts >= 0),
  max_attempts    int NOT NULL DEFAULT 5 CHECK (max_attempts >= 1),
  next_run_at     timestamptz NOT NULL DEFAULT now(),
  claimed_by      text,
  claimed_at      timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  completed_at    timestamptz,
  result          jsonb,
  error           text,
  UNIQUE (tenant_id, idempotency_key)
);

-- The worker's claim query filters on (status, next_run_at, created_at);
-- a partial index keeps the poll cheap as the table grows.
CREATE INDEX outbox_jobs_claim_idx ON app.outbox_jobs (status, next_run_at, created_at)
  WHERE status = 'pending';

-- Explicit grants (default privileges from init also cover future tables).
GRANT SELECT, INSERT, UPDATE, DELETE
  ON app.tasks, app.outbox_jobs TO bmos_app;

-- Tenant isolation on the demo business table: same shape as 001.
ALTER TABLE app.tasks ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.tasks FORCE ROW LEVEL SECURITY;
CREATE POLICY tasks_isolation ON app.tasks
  FOR ALL TO bmos_app
  USING (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
  WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid);

-- Outbox RLS: two policies, OR'd together for each command.
--
-- 1. outbox_discovery (SELECT only): the worker polls for claimable jobs
--    across ALL tenants with NO tenant context set. Without this, a
--    multi-tenant worker could never find work.
-- 2. outbox_tenant_isolation (ALL): every other operation — INSERT, UPDATE,
--    DELETE, and any SELECT of non-pending rows — is bound to the session's
--    app.current_tenant, exactly like a web request. The worker SETs
--    app.current_tenant to the job's tenant BEFORE claiming, so the claim,
--    the work, and the result recording are all tenant-scoped.
--
-- Consequence used by the worker: SELECT ... FOR UPDATE cannot claim with
-- no tenant context (UPDATE needs the tenant policy's USING to pass), so
-- the worker MUST set the tenant context before the claim. That is the
-- enforcement, not just a convention.
ALTER TABLE app.outbox_jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.outbox_jobs FORCE ROW LEVEL SECURITY;
CREATE POLICY outbox_discovery ON app.outbox_jobs
  FOR SELECT TO bmos_app
  USING (status = 'pending');
CREATE POLICY outbox_tenant_isolation ON app.outbox_jobs
  FOR ALL TO bmos_app
  USING (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
  WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid);
