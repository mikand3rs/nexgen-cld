-- BMOS 3.2 Phase 2 — 01-roles-schema.sql
-- Runs ONCE at first volume init, as the superuser, before any app code connects.
-- Roles per the corrected guide (2.7):
--   bmos_owner     owns schemas and tables; NOLOGIN (no app login, ever)
--   bmos_migrator  applies approved migrations; LOGIN, least privilege
--   bmos_app       runtime role; LOGIN, NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS
-- Passwords are set by 02-passwords.sh from Compose secret files.

CREATE ROLE bmos_owner NOLOGIN;
CREATE ROLE bmos_migrator LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE bmos_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

-- The database from POSTGRES_DB is created owned by the superuser; hand
-- ownership to bmos_owner so no login role owns objects. Name-agnostic on
-- purpose: init runs this script connected to POSTGRES_DB, so a renamed or
-- unset POSTGRES_DB can never abort init halfway through (a hardcoded name
-- mismatch used to leave a half-initialized volume: roles without passwords,
-- no database).
DO $$
BEGIN
  EXECUTE format('ALTER DATABASE %I OWNER TO bmos_owner', current_database());
END
$$;

CREATE SCHEMA IF NOT EXISTS app AUTHORIZATION bmos_owner;

GRANT USAGE ON SCHEMA app TO bmos_migrator, bmos_app;
GRANT CREATE ON SCHEMA app TO bmos_migrator;

-- Every table a future migration creates (as bmos_migrator) automatically
-- grants runtime DML to bmos_app. RLS policies still decide WHICH rows.
ALTER DEFAULT PRIVILEGES FOR ROLE bmos_migrator IN SCHEMA app
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO bmos_app;
ALTER DEFAULT PRIVILEGES FOR ROLE bmos_migrator IN SCHEMA app
  GRANT USAGE, SELECT ON SEQUENCES TO bmos_app;

-- Migration bookkeeping. Internal tooling table: no RLS, migrator-only.
CREATE TABLE IF NOT EXISTS app.schema_migrations (
  version    text PRIMARY KEY,
  applied_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE app.schema_migrations OWNER TO bmos_owner;
GRANT SELECT, INSERT ON app.schema_migrations TO bmos_migrator;
