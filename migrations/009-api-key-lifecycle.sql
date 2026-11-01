-- BMOS 3.2 Stage B — API key lifecycle.
-- Adds expiration and revocation to api_keys.

ALTER TABLE app.api_keys
  ADD COLUMN IF NOT EXISTS expires_at timestamptz,
  ADD COLUMN IF NOT EXISTS revoked_at timestamptz,
  ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now();

-- Expired or revoked keys must never verify (enforced in Auth::verifyApiKey).
CREATE INDEX IF NOT EXISTS api_keys_tenant_idx ON app.api_keys (tenant_id);
