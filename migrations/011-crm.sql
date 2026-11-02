-- Migration 011: BMOS CRM v1 — native customer & sales module.
--
-- CRM is a first-class BMOS domain, not a bolted-on product. It reuses
-- tenants, users, forced RLS, and the transactional outbox. No new
-- containers, no new databases.
--
-- Scope: CRM 1.0 (leads, accounts, contacts, notes, activities, search).
-- Opportunity/pipeline/stage tables are created now so 1.1 builds on the
-- same schema; their UI lands in CRM 1.1.
--
-- Also creates app.audit_events (no audit table existed yet) — CRM writes
-- an audit event for important changes (lead conversion, status changes).
--
-- Rules enforced here:
--   1. Every business table has tenant_id + forced RLS (standard policy).
--   2. Cross-tenant references are blocked by composite (tenant_id, id)
--      foreign keys wherever the relationship is table-to-table.
--   3. Polymorphic links (entity_type + entity_id) are enforced in PHP;
--      document the allowed entity types in docs/crm.md.

-- ---------------------------------------------------------------- audit
CREATE TABLE IF NOT EXISTS app.audit_events (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id   UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    actor       TEXT NOT NULL,              -- user email or 'system'
    action      TEXT NOT NULL,              -- e.g. 'crm.lead.converted'
    entity_type TEXT NOT NULL,              -- e.g. 'lead', 'account'
    entity_id   UUID,                       -- NULL for tenant-level events
    details     JSONB NOT NULL DEFAULT '{}',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
ALTER TABLE app.audit_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.audit_events FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS audit_events_isolation ON app.audit_events;
CREATE POLICY audit_events_isolation ON app.audit_events
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));
CREATE INDEX IF NOT EXISTS audit_events_tenant_idx ON app.audit_events(tenant_id);
CREATE INDEX IF NOT EXISTS audit_events_entity_idx ON app.audit_events(entity_type, entity_id);

-- ---------------------------------------------------------------- leads
CREATE TABLE IF NOT EXISTS app.crm_leads (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id     UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    name          TEXT NOT NULL,
    email         TEXT,
    phone         TEXT,
    company_name  TEXT,
    source        TEXT,                       -- website, referral, cold, ...
    status        TEXT NOT NULL DEFAULT 'new'
                  CHECK (status IN ('new','contacted','qualified','disqualified','converted')),
    assigned_to   UUID,                       -- app.users(id), nullable
    notes         TEXT,
    converted_at  TIMESTAMPTZ,
    converted_account_id UUID,
    converted_contact_id UUID,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
ALTER TABLE app.crm_leads ADD CONSTRAINT crm_leads_tenant_id_unique UNIQUE (tenant_id, id);
ALTER TABLE app.crm_leads ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.crm_leads FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS crm_leads_isolation ON app.crm_leads;
CREATE POLICY crm_leads_isolation ON app.crm_leads
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));
CREATE INDEX IF NOT EXISTS crm_leads_tenant_idx ON app.crm_leads(tenant_id);
CREATE INDEX IF NOT EXISTS crm_leads_status_idx ON app.crm_leads(tenant_id, status);
CREATE INDEX IF NOT EXISTS crm_leads_search_idx ON app.crm_leads USING gin
    (to_tsvector('english', coalesce(name,'') || ' ' || coalesce(email,'') || ' ' || coalesce(company_name,'')));

-- ---------------------------------------------------------------- accounts (companies)
CREATE TABLE IF NOT EXISTS app.crm_accounts (
    id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id  UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    name       TEXT NOT NULL,
    website    TEXT,
    phone      TEXT,
    email      TEXT,
    address    TEXT,
    industry   TEXT,
    status     TEXT NOT NULL DEFAULT 'prospect'
               CHECK (status IN ('prospect','customer','former')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
ALTER TABLE app.crm_accounts ADD CONSTRAINT crm_accounts_tenant_id_unique UNIQUE (tenant_id, id);
ALTER TABLE app.crm_accounts ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.crm_accounts FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS crm_accounts_isolation ON app.crm_accounts;
CREATE POLICY crm_accounts_isolation ON app.crm_accounts
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));
CREATE INDEX IF NOT EXISTS crm_accounts_tenant_idx ON app.crm_accounts(tenant_id);
CREATE INDEX IF NOT EXISTS crm_accounts_name_idx ON app.crm_accounts(tenant_id, name);
CREATE INDEX IF NOT EXISTS crm_accounts_search_idx ON app.crm_accounts USING gin
    (to_tsvector('english', coalesce(name,'') || ' ' || coalesce(email,'') || ' ' || coalesce(industry,'')));

-- ---------------------------------------------------------------- contacts (people)
CREATE TABLE IF NOT EXISTS app.crm_contacts (
    id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id  UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    first_name TEXT NOT NULL,
    last_name  TEXT,
    email      TEXT,
    phone      TEXT,
    title      TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
ALTER TABLE app.crm_contacts ADD CONSTRAINT crm_contacts_tenant_id_unique UNIQUE (tenant_id, id);
ALTER TABLE app.crm_contacts ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.crm_contacts FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS crm_contacts_isolation ON app.crm_contacts;
CREATE POLICY crm_contacts_isolation ON app.crm_contacts
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));
CREATE INDEX IF NOT EXISTS crm_contacts_tenant_idx ON app.crm_contacts(tenant_id);
CREATE INDEX IF NOT EXISTS crm_contacts_search_idx ON app.crm_contacts USING gin
    (to_tsvector('english', coalesce(first_name,'') || ' ' || coalesce(last_name,'') || ' ' || coalesce(email,'')));

-- ------------------------------------------------- account <-> contact links
CREATE TABLE IF NOT EXISTS app.crm_account_contacts (
    tenant_id  UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    account_id UUID NOT NULL,
    contact_id UUID NOT NULL,
    role       TEXT,
    is_primary BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (tenant_id, account_id, contact_id),
    FOREIGN KEY (tenant_id, account_id) REFERENCES app.crm_accounts(tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id, contact_id) REFERENCES app.crm_contacts(tenant_id, id) ON DELETE CASCADE
);
ALTER TABLE app.crm_account_contacts ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.crm_account_contacts FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS crm_account_contacts_isolation ON app.crm_account_contacts;
CREATE POLICY crm_account_contacts_isolation ON app.crm_account_contacts
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));

-- ------------------------------------------------- pipelines & stages (1.1)
CREATE TABLE IF NOT EXISTS app.crm_pipelines (
    id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id  UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    name       TEXT NOT NULL,
    is_default BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);
ALTER TABLE app.crm_pipelines ADD CONSTRAINT crm_pipelines_tenant_id_unique UNIQUE (tenant_id, id);
ALTER TABLE app.crm_pipelines ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.crm_pipelines FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS crm_pipelines_isolation ON app.crm_pipelines;
CREATE POLICY crm_pipelines_isolation ON app.crm_pipelines
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));

CREATE TABLE IF NOT EXISTS app.crm_stages (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id   UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    pipeline_id UUID NOT NULL,
    name        TEXT NOT NULL,
    position    INT NOT NULL DEFAULT 0,
    probability INT NOT NULL DEFAULT 0 CHECK (probability BETWEEN 0 AND 100),
    is_won      BOOLEAN NOT NULL DEFAULT false,
    is_lost     BOOLEAN NOT NULL DEFAULT false,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (tenant_id, pipeline_id) REFERENCES app.crm_pipelines(tenant_id, id) ON DELETE CASCADE,
    UNIQUE (tenant_id, pipeline_id, name)
);
ALTER TABLE app.crm_stages ADD CONSTRAINT crm_stages_tenant_id_unique UNIQUE (tenant_id, id);
ALTER TABLE app.crm_stages ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.crm_stages FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS crm_stages_isolation ON app.crm_stages;
CREATE POLICY crm_stages_isolation ON app.crm_stages
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));
CREATE INDEX IF NOT EXISTS crm_stages_pipeline_idx ON app.crm_stages(tenant_id, pipeline_id, position);

-- ------------------------------------------------- opportunities (1.1)
CREATE TABLE IF NOT EXISTS app.crm_opportunities (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id     UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    account_id    UUID,
    contact_id    UUID,
    pipeline_id   UUID,
    stage_id      UUID,
    name          TEXT NOT NULL,
    value_cents   BIGINT NOT NULL DEFAULT 0 CHECK (value_cents >= 0),
    expected_close DATE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    FOREIGN KEY (tenant_id, account_id) REFERENCES app.crm_accounts(tenant_id, id) ON DELETE SET NULL,
    FOREIGN KEY (tenant_id, contact_id) REFERENCES app.crm_contacts(tenant_id, id) ON DELETE SET NULL,
    FOREIGN KEY (tenant_id, pipeline_id) REFERENCES app.crm_pipelines(tenant_id, id) ON DELETE SET NULL,
    FOREIGN KEY (tenant_id, stage_id) REFERENCES app.crm_stages(tenant_id, id) ON DELETE SET NULL
);
ALTER TABLE app.crm_opportunities ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.crm_opportunities FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS crm_opportunities_isolation ON app.crm_opportunities;
CREATE POLICY crm_opportunities_isolation ON app.crm_opportunities
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));
CREATE INDEX IF NOT EXISTS crm_opportunities_tenant_idx ON app.crm_opportunities(tenant_id);
CREATE INDEX IF NOT EXISTS crm_opportunities_stage_idx ON app.crm_opportunities(tenant_id, stage_id);

-- ---------------------------------------------------------------- activities
CREATE TABLE IF NOT EXISTS app.crm_activities (
    id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id    UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    type         TEXT NOT NULL
                 CHECK (type IN ('call','meeting','email','note','followup')),
    subject      TEXT NOT NULL,
    body         TEXT,
    due_at       TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    created_by   TEXT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
ALTER TABLE app.crm_activities ADD CONSTRAINT crm_activities_tenant_id_unique UNIQUE (tenant_id, id);
ALTER TABLE app.crm_activities ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.crm_activities FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS crm_activities_isolation ON app.crm_activities;
CREATE POLICY crm_activities_isolation ON app.crm_activities
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));
CREATE INDEX IF NOT EXISTS crm_activities_tenant_idx ON app.crm_activities(tenant_id);
CREATE INDEX IF NOT EXISTS crm_activities_due_idx ON app.crm_activities(tenant_id, due_at)
    WHERE completed_at IS NULL;

-- Polymorphic links: activity -> lead/account/contact/opportunity.
-- entity_type is validated in PHP (lead|account|contact|opportunity).
CREATE TABLE IF NOT EXISTS app.crm_activity_links (
    tenant_id   UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    activity_id UUID NOT NULL,
    entity_type TEXT NOT NULL CHECK (entity_type IN ('lead','account','contact','opportunity')),
    entity_id   UUID NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (tenant_id, activity_id, entity_type, entity_id),
    FOREIGN KEY (tenant_id, activity_id) REFERENCES app.crm_activities(tenant_id, id) ON DELETE CASCADE
);
ALTER TABLE app.crm_activity_links ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.crm_activity_links FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS crm_activity_links_isolation ON app.crm_activity_links;
CREATE POLICY crm_activity_links_isolation ON app.crm_activity_links
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));

-- ---------------------------------------------------------------- notes
-- entity_type validated in PHP (lead|account|contact|opportunity).
CREATE TABLE IF NOT EXISTS app.crm_notes (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id   UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    entity_type TEXT NOT NULL CHECK (entity_type IN ('lead','account','contact','opportunity')),
    entity_id   UUID NOT NULL,
    body        TEXT NOT NULL,
    created_by  TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
ALTER TABLE app.crm_notes ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.crm_notes FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS crm_notes_isolation ON app.crm_notes;
CREATE POLICY crm_notes_isolation ON app.crm_notes
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));
CREATE INDEX IF NOT EXISTS crm_notes_entity_idx ON app.crm_notes(tenant_id, entity_type, entity_id);

-- ---------------------------------------------------------------- tags
CREATE TABLE IF NOT EXISTS app.crm_tags (
    id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id  UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    name       TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);
ALTER TABLE app.crm_tags ADD CONSTRAINT crm_tags_tenant_id_unique UNIQUE (tenant_id, id);
ALTER TABLE app.crm_tags ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.crm_tags FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS crm_tags_isolation ON app.crm_tags;
CREATE POLICY crm_tags_isolation ON app.crm_tags
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));

CREATE TABLE IF NOT EXISTS app.crm_record_tags (
    tenant_id   UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    tag_id      UUID NOT NULL,
    entity_type TEXT NOT NULL CHECK (entity_type IN ('lead','account','contact','opportunity')),
    entity_id   UUID NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (tenant_id, tag_id, entity_type, entity_id),
    FOREIGN KEY (tenant_id, tag_id) REFERENCES app.crm_tags(tenant_id, id) ON DELETE CASCADE
);
ALTER TABLE app.crm_record_tags ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.crm_record_tags FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS crm_record_tags_isolation ON app.crm_record_tags;
CREATE POLICY crm_record_tags_isolation ON app.crm_record_tags
    USING (tenant_id::text = current_setting('app.current_tenant', true))
    WITH CHECK (tenant_id::text = current_setting('app.current_tenant', true));

-- Table owner: bmos_migrator (matches existing tables; set by migrate role).
