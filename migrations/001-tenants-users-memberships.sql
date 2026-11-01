-- BMOS 3.2 Phase 2 — migration 001: tenants, users, memberships + forced RLS.
-- Applied as bmos_migrator via scripts/migrate.sh (never as a superuser).
-- gen_random_uuid() is built into PostgreSQL 13+; no extension needed.

CREATE TABLE app.tenants (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name       text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE app.users (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  email      text NOT NULL UNIQUE,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE app.memberships (
  tenant_id  uuid NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
  user_id    uuid NOT NULL REFERENCES app.users(id) ON DELETE CASCADE,
  role       text NOT NULL DEFAULT 'member'
             CHECK (role IN ('owner', 'admin', 'member')),
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (tenant_id, user_id)
);

-- Explicit grants (default privileges from init also cover future tables).
GRANT SELECT, INSERT, UPDATE, DELETE
  ON app.tenants, app.users, app.memberships TO bmos_app;

-- Tenant isolation: a session only sees rows for the tenant in
-- app.current_tenant. Missing/empty context matches nothing (fails closed).
-- FORCE makes RLS apply even to table owners; superusers and BYPASSRLS
-- roles still bypass it, which is why tests must run as bmos_app.

ALTER TABLE app.tenants ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.tenants FORCE ROW LEVEL SECURITY;
CREATE POLICY tenants_isolation ON app.tenants
  FOR ALL TO bmos_app
  USING (id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
  WITH CHECK (id = NULLIF(current_setting('app.current_tenant', true), '')::uuid);

ALTER TABLE app.memberships ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.memberships FORCE ROW LEVEL SECURITY;
CREATE POLICY memberships_isolation ON app.memberships
  FOR ALL TO bmos_app
  USING (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
  WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid);

-- Users are personal rows, not tenant rows: a session only sees its own
-- user row via app.current_user. (Login wiring comes in a later phase.)
ALTER TABLE app.users ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.users FORCE ROW LEVEL SECURITY;
CREATE POLICY users_own_row ON app.users
  FOR ALL TO bmos_app
  USING (id = NULLIF(current_setting('app.current_user', true), '')::uuid)
  WITH CHECK (id = NULLIF(current_setting('app.current_user', true), '')::uuid);
