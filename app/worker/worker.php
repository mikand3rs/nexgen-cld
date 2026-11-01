<?php
// BMOS 3.2 — Phase 5: transactional outbox worker.
//
// The claim protocol (enforced by the RLS policies in 002-outbox.sql):
//   1. PEEK with NO tenant context: find one claimable job. The
//      outbox_discovery policy (SELECT ... WHERE status='pending') is the
//      only thing that lets this see rows across tenants.
//   2. SET LOCAL app.current_tenant to the job's tenant. From here on the
//      worker is RLS-bound exactly like a web request.
//   3. CLAIM with SELECT ... FOR UPDATE inside the tenant context. (A claim
//      without tenant context returns zero rows: UPDATE needs the tenant
//      policy's USING to pass. That is the enforcement, not a convention.)
//   4. PERFORM the work (tenant-scoped by RLS).
//   5. RECORD done/failed/dead + result, then COMMIT.
//
// Job handlers are the Phase 5 demo set. Real job types arrive in later
// phases; every handler must be idempotent — a retried job re-runs the
// handler, so "already done" must be a no-op success, never a duplicate
// side effect.

declare(strict_types=1);

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Qdrant.php';
require __DIR__ . '/../src/Embeddings.php';

$shutdown = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $handler = static function () use (&$shutdown): void {
        $shutdown = true;
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

function log_line(string $msg): void
{
    fwrite(STDOUT, gmdate('Y-m-d\TH:i:s\Z') . " worker: {$msg}\n");
}

/** Demo handlers. Each returns ['ok' => bool, 'result' => array, 'error' => ?string]. */
function handle_job(PDO $pdo, array $job): array
{
    $payload = json_decode($job['payload'] ?? '{}', true) ?: [];
    switch ($job['job_type']) {
        case 'task.created': {
            // Idempotent: marking an already-notified task is a no-op success.
            $taskId = $payload['task_id'] ?? null;
            if (!is_string($taskId) || $taskId === '') {
                return ['ok' => false, 'result' => [], 'error' => 'task.created: payload.task_id missing'];
            }
            $stmt = $pdo->prepare(
                "UPDATE app.tasks SET status = 'notified'
                 WHERE id = :id AND status = 'open'"
            );
            $stmt->execute([':id' => $taskId]);
            $touched = $stmt->rowCount();
            return ['ok' => true, 'result' => ['task_id' => $taskId, 'notified' => $touched > 0, 'already_notified' => $touched === 0], 'error' => null];
        }
        case 'demo.fail_once': {
            // Demo of retry + idempotency: fails on attempt 1, succeeds after.
            // attempts was already incremented at claim time.
            if ((int) $job['attempts'] < 2) {
                return ['ok' => false, 'result' => [], 'error' => 'demo.fail_once: simulated transient failure'];
            }
            return ['ok' => true, 'result' => ['recovered_on_attempt' => (int) $job['attempts']], 'error' => null];
        }
        case 'document.index': {
            // Phase 6: index a document's chunks into Qdrant. Idempotent —
            // points are upserted by chunk ID, so re-running is a no-op.
            // The worker's tenant context (set at claim time) scopes the
            // chunk SELECT via RLS, exactly like a web request.
            $docId = $payload['document_id'] ?? null;
            if (!is_string($docId) || $docId === '') {
                return ['ok' => false, 'result' => [], 'error' => 'document.index: payload.document_id missing'];
            }
            $tenantId = $job['tenant_id'];
            $collection = getenv('QDRANT_COLLECTION') ?: 'bmos_chunks';
            $dim = DemoEmbeddings::dimension();
            $stmt = $pdo->prepare(
                'SELECT id, document_id, tenant_id, content, source_version
                 FROM app.document_chunks WHERE document_id = :d ORDER BY chunk_index'
            );
            $stmt->execute([':d' => $docId]);
            $points = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $points[] = [
                    'id' => $row['id'],
                    'vector' => DemoEmbeddings::generate((string) $row['content'], $dim),
                    'payload' => [
                        'tenant_id' => $row['tenant_id'],
                        'document_id' => $row['document_id'],
                        'chunk_id' => $row['id'],
                        'source_version' => (int) $row['source_version'],
                        'content' => $row['content'],
                    ],
                ];
            }
            if ($points === []) {
                return ['ok' => true, 'result' => ['document_id' => $docId, 'indexed' => 0], 'error' => null];
            }
            (new Qdrant())->upsertPoints($collection, $points);
            return ['ok' => true, 'result' => ['document_id' => $docId, 'indexed' => count($points)], 'error' => null];
        }
        default:
            // Unknown job types can never succeed by retrying: dead-letter
            // immediately instead of burning max_attempts.
            return ['ok' => false, 'result' => [], 'error' => "unknown job_type '{$job['job_type']}'", 'dead' => true];
    }
}

function backoff_seconds(int $attempts): int
{
    // 2s, 8s, 18s, 32s, ... — short enough for Phase 5 demos, real
    // workloads tune this per job type in a later phase.
    return 2 * $attempts * $attempts;
}

$workerId = (gethostname() ?: 'worker') . ':' . getmypid();
$pdo = null;
$reaperCounter = 0;
log_line("starting (worker id {$workerId})");

while (!$shutdown) {
    try {
        if ($pdo === null) {
            $pdo = Db::connect();
            $role = $pdo->query('SELECT current_user')->fetchColumn();
            log_line("connected as {$role}");
        }

        // Reaper: recover jobs from dead workers (expired leases).
        // Runs every ~10 loop iterations, not every loop.
        if (++$reaperCounter >= 10) {
            $reaperCounter = 0;
            $reaped = $pdo->query("SELECT * FROM app.reap_expired_leases()")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reaped as $r) {
                $state = $r['reaped_dead'] ? 'dead-letter' : "pending (attempt {$r['reaped_attempts']})";
                log_line("reaper: recovered job {$r['reaped_id']} → {$state}");
            }
        }

        $pdo->beginTransaction();

        // Step 1 — peek with NO tenant context (discovery policy only).
        $peek = $pdo->query(
            "SELECT id, tenant_id, job_type FROM app.outbox_jobs
             WHERE status = 'pending' AND next_run_at <= now()
             ORDER BY created_at LIMIT 1"
        );
        $candidate = $peek->fetch(PDO::FETCH_ASSOC);
        if ($candidate === false) {
            $pdo->commit();
            // Sleep in 1s slices so SIGTERM is honored promptly.
            for ($i = 0; $i < 2 && !$shutdown; $i++) {
                sleep(1);
            }
            continue;
        }

        $jobId = $candidate['id'];
        $tenantId = $candidate['tenant_id'];

        // Step 2 — bind to the job's tenant. Everything after this is
        // RLS-scoped exactly like a web request.
        if (!preg_match('/^[0-9a-f-]{36}$/i', (string) $tenantId)) {
            $pdo->rollBack();
            log_line("skipping job {$jobId}: bad tenant_id");
            continue;
        }
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");

        // Step 3 — claim. FOR UPDATE serializes competing workers; the
        // status='pending' recheck means a lost race claims zero rows.
        // SKIP LOCKED keeps a second worker from blocking on the lock.
        $claim = $pdo->prepare(
            "SELECT id, tenant_id, job_type, payload, attempts, max_attempts
             FROM app.outbox_jobs
             WHERE id = :id AND status = 'pending'
             FOR UPDATE SKIP LOCKED"
        );
        $claim->execute([':id' => $jobId]);
        $job = $claim->fetch(PDO::FETCH_ASSOC);
        if ($job === false) {
            $pdo->rollBack(); // lost the race (or job changed state); move on
            continue;
        }

        $pdo->prepare(
            "UPDATE app.outbox_jobs
             SET status = 'claimed', claimed_by = :w, claimed_at = now(),
                 lease_expires_at = now() + make_interval(secs => 60),
                 last_heartbeat_at = now(), worker_id = :w,
                 attempts = attempts + 1
             WHERE id = :id"
        )->execute([':w' => $workerId, ':id' => $jobId]);

        // Step 4 — perform the work (tenant-scoped by RLS).
        // Heartbeat: renew the lease so long jobs aren't reaped.
        // (Current handlers are fast; this is the hook for slow ones.)
        $job['attempts'] = (int) $job['attempts'] + 1; // claim already incremented it
        $outcome = handle_job($pdo, $job);
        $pdo->prepare(
            "UPDATE app.outbox_jobs SET last_heartbeat_at = now(),
             lease_expires_at = now() + make_interval(secs => 60)
             WHERE id = :id"
        )->execute([':id' => $jobId]);

        // Step 5 — record the result.
        if ($outcome['ok']) {
            $pdo->prepare(
                "UPDATE app.outbox_jobs
                 SET status = 'done', completed_at = now(),
                     result = :r::jsonb, error = NULL
                 WHERE id = :id"
            )->execute([':r' => json_encode($outcome['result']), ':id' => $jobId]);
            log_line("job {$jobId} ({$job['job_type']}) done");
        } elseif (!empty($outcome['dead']) || (int) $job['attempts'] >= (int) $job['max_attempts']) {
            $pdo->prepare(
                "UPDATE app.outbox_jobs
                 SET status = 'dead', completed_at = now(),
                     error = :e
                 WHERE id = :id"
            )->execute([':e' => $outcome['error'], ':id' => $jobId]);
            log_line("job {$jobId} ({$job['job_type']}) dead: {$outcome['error']}");
        } else {
            $backoff = backoff_seconds((int) $job['attempts']);
            $pdo->prepare(
                "UPDATE app.outbox_jobs
                 SET status = 'pending',
                     next_run_at = now() + make_interval(secs => :b),
                     error = :e
                 WHERE id = :id"
            )->execute([':b' => $backoff, ':e' => $outcome['error'], ':id' => $jobId]);
            log_line("job {$jobId} ({$job['job_type']}) failed (attempt {$job['attempts']}): {$outcome['error']} — retry in {$backoff}s");
        }

        $pdo->commit();
    } catch (Throwable $e) {
        // Drop the handle so the next iteration reconnects: a broken
        // connection never heals itself.
        if ($pdo !== null) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // rollback on a dead connection throws; ignore it
            }
            $pdo = null;
        }
        log_line('loop error: ' . $e->getMessage());
        sleep(2);
    }
}

log_line('shutting down');
