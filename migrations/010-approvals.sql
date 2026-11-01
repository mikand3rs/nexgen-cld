-- Migration 010: BMOS-owned human approvals (GOV-01).
--
-- Approvals are durable business records. An agent requests approval for a
-- consequential action; an authenticated human with tenant-scoped permission
-- approves or rejects. The agent can NEVER approve its own request.
--
-- Each approval binds: tenant, task, requesting identity, action type,
-- exact payload hash, expiry, and a single-use constraint.

CREATE TABLE IF NOT EXISTS app.approvals (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id   UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    task_id     TEXT NOT NULL,
    action      TEXT NOT NULL,
    -- SHA-256 of the canonical JSON payload the human approved.
    -- Execution must present the identical payload or the approval is void.
    payload_hash TEXT NOT NULL,
    payload     JSONB NOT NULL,
    requested_by TEXT NOT NULL,  -- agent identity (email or key name)
    requested_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- 'pending', 'approved', 'rejected', 'expired', 'consumed'
    status      TEXT NOT NULL DEFAULT 'pending'
                CHECK (status IN ('pending','approved','rejected','expired','consumed')),
    decided_by  TEXT,            -- human email who decided; NULL until decided
    decided_at  TIMESTAMPTZ,
    expires_at  TIMESTAMPTZ NOT NULL DEFAULT now() + interval '1 hour',
    -- Single-use: once consumed, the approval cannot authorize again.
    consumed_at TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- RLS: tenant isolation, forced.
ALTER TABLE app.approvals ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.approvals FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS approvals_isolation ON app.approvals;
CREATE POLICY approvals_isolation ON app.approvals
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));

-- Indexes.
CREATE INDEX IF NOT EXISTS approvals_tenant_idx ON app.approvals(tenant_id);
CREATE INDEX IF NOT EXISTS approvals_status_idx ON app.approvals(status)
    WHERE status = 'pending';
CREATE INDEX IF NOT EXISTS approvals_task_idx ON app.approvals(task_id);

-- Table owner: bmos_migrator (matches existing tables).
-- (Ownership is set by the migrate script's role.)
