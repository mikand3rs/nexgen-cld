-- BMOS 3.2 Stage A — fix reserved keyword.
-- app.current_user is a reserved PostgreSQL keyword and cannot be used
-- as a custom GUC name. Rename to app.current_user_id.

DROP POLICY IF EXISTS users_isolation ON app.users;

CREATE POLICY users_isolation ON app.users
  USING (id = NULLIF(current_setting('app.current_user_id', true), '')::uuid)
  WITH CHECK (id = NULLIF(current_setting('app.current_user_id', true), '')::uuid);
