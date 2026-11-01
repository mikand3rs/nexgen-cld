-- BMOS 3.2 Stage A — authentication foundation.
-- Adds password_hash to users; sessions are PHP-native (cookie) with
-- server-side storage, so no sessions table is needed.

ALTER TABLE app.users ADD COLUMN IF NOT EXISTS password_hash TEXT;

-- Email must be unique for login identity.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'users_email_unique'
    ) THEN
        ALTER TABLE app.users ADD CONSTRAINT users_email_unique UNIQUE (email);
    END IF;
END $$;

-- Stage A: API keys for server-to-server (n8n, workers).
CREATE TABLE IF NOT EXISTS app.api_keys (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES app.tenants(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    key_hash TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at TIMESTAMPTZ,
    UNIQUE (tenant_id, name)
);
ALTER TABLE app.api_keys ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.api_keys FORCE ROW LEVEL SECURITY;
-- API keys are managed by the tenant's admins via the API, not directly.
-- (Policy added in a later migration when the management UI exists.)

-- Stage A: secure login lookup. SECURITY DEFINER runs as bmos_owner,
-- bypassing RLS for the single purpose of finding a user by email.
-- Returns (id, password_hash) or nothing. Does NOT reveal other columns.
CREATE OR REPLACE FUNCTION app.find_user_for_login(p_email TEXT)
RETURNS TABLE (user_id UUID, pw_hash TEXT)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = app
AS $$
BEGIN
    RETURN QUERY
    SELECT u.id, u.password_hash
    FROM app.users u
    WHERE u.email = p_email;
END;
$$;
-- Only the app role can call it.
REVOKE ALL ON FUNCTION app.find_user_for_login(TEXT) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app.find_user_for_login(TEXT) TO bmos_app;

-- Stage A: membership lookup. SECURITY DEFINER bypasses RLS so we can
-- list a user's tenants (needed before tenant context exists).
CREATE OR REPLACE FUNCTION app.get_user_memberships(p_user_id UUID)
RETURNS TABLE (tenant_id UUID, tenant_name TEXT, role TEXT)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = app
AS $$
BEGIN
    RETURN QUERY
    SELECT t.id, t.name, m.role
    FROM app.memberships m
    JOIN app.tenants t ON t.id = m.tenant_id
    WHERE m.user_id = p_user_id
    ORDER BY t.name;
END;
$$;
REVOKE ALL ON FUNCTION app.get_user_memberships(UUID) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app.get_user_memberships(UUID) TO bmos_app;
