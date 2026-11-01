-- BMOS 3.2 Stage B — worker leases for crash recovery.
-- A claimed job holds a lease; if the worker dies, the lease expires and
-- the reaper returns the job to pending (bounded retries, then dead-letter).

ALTER TABLE app.outbox_jobs
  ADD COLUMN IF NOT EXISTS claimed_at timestamptz,
  ADD COLUMN IF NOT EXISTS lease_expires_at timestamptz,
  ADD COLUMN IF NOT EXISTS last_heartbeat_at timestamptz,
  ADD COLUMN IF NOT EXISTS worker_id text;

-- Reaper index: find expired leases fast.
CREATE INDEX IF NOT EXISTS outbox_reaper_idx ON app.outbox_jobs (lease_expires_at)
  WHERE status = 'claimed';

-- Reaper function: recover jobs whose worker died (lease expired).
-- SECURITY DEFINER so it can UPDATE across tenants; the worker calls this,
-- never raw UPDATE on claimed rows. Bounded: increments attempts, and jobs
-- that exceed max_attempts go to dead-letter.
CREATE OR REPLACE FUNCTION app.reap_expired_leases()
RETURNS TABLE (reaped_id UUID, reaped_attempts INT, reaped_dead BOOLEAN)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = app
AS $$
DECLARE
  r RECORD;
BEGIN
  FOR r IN
    SELECT id, attempts, max_attempts
    FROM app.outbox_jobs
    WHERE status = 'claimed' AND lease_expires_at < now()
    FOR UPDATE SKIP LOCKED
  LOOP
    IF r.attempts >= r.max_attempts THEN
      UPDATE app.outbox_jobs
      SET status = 'dead', completed_at = now(),
          error = 'reaper: lease expired, max attempts exceeded'
      WHERE id = r.id;
      RETURN QUERY SELECT r.id, r.attempts, true;
    ELSE
      UPDATE app.outbox_jobs
      SET status = 'pending',
          attempts = r.attempts + 1,
          next_run_at = now() + make_interval(secs => 2 * (r.attempts + 1) * (r.attempts + 1)),
          error = 'reaper: lease expired, worker presumed dead',
          claimed_at = NULL, lease_expires_at = NULL,
          last_heartbeat_at = NULL, worker_id = NULL
      WHERE id = r.id;
      RETURN QUERY SELECT r.id, r.attempts + 1, false;
    END IF;
  END LOOP;
END;
$$;

ALTER FUNCTION app.reap_expired_leases() OWNER TO bmos_migrator;
REVOKE ALL ON FUNCTION app.reap_expired_leases() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app.reap_expired_leases() TO bmos_app;

-- Backoff is 2 * attempts^2 seconds; max 5 attempts then dead-letter.
-- These are enforced in the worker, not the DB.
