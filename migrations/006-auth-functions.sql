-- BMOS 3.2 Stage A — auth helper functions (SECURITY DEFINER).
-- These were added to 005-auth.sql after it was already applied,
-- so they ship here as 006.

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
REVOKE ALL ON FUNCTION app.find_user_for_login(TEXT) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app.find_user_for_login(TEXT) TO bmos_app;
-- SECURITY DEFINER runs as the function owner; must be bmos_owner
-- (who owns the tables and bypasses RLS), not bmos_migrator.
ALTER FUNCTION app.find_user_for_login(TEXT) OWNER TO bmos_owner;

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
ALTER FUNCTION app.get_user_memberships(UUID) OWNER TO bmos_owner;
