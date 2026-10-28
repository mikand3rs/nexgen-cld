<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NexGen CLD — Background worker (CLI only)
|--------------------------------------------------------------------------
|
| One process, two job types. It is NOT spawned per request and
| NOT launched with exec() from a web request. Start it with
| Compose (see the worker service) or manually:
|
|   php app/worker.php --once            # one pass, then exit
|   php app/worker.php --loop            # loop forever, 5s between passes
|   php app/worker.php --once --type=ingestion
|   php app/worker.php --once --type=expert
|
| Claiming rule (V2.2 / V3.2): one pending job at a time, claimed
| with FOR UPDATE SKIP LOCKED inside a transaction, so two workers
| cannot claim the same job. Failed jobs retry up to max_attempts
| with backoff (30s, 60s, 120s), then stay 'failed' and visible —
| never silent, never lost.
|
| Restart safety: a job left 'processing' by a killed worker is
| reclaimed after 10 minutes (stale_processing), so a restart
| picks up pending work instead of losing it or duplicating it.
| (Duplication guard: ingestion replaces chunks per document in
| one transaction; expert jobs are idempotent reads + one AI call
| whose result overwrites, not appends.)
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("worker.php is CLI only.\n");
}

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/ingestion.php';
require_once __DIR__ . '/lib/experts.php';

$type = 'all';
$loop = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--type=')) {
        $type = substr($arg, 7);
    }
    if ($arg === '--loop') {
        $loop = true;
    }
    if ($arg === '--once') {
        $loop = false;
    }
}
if (!in_array($type, ['all', 'ingestion', 'expert'], true)) {
    fwrite(STDERR, "Unknown --type. Use all, ingestion, or expert.\n");
    exit(1);
}

function worker_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

/** Reclaim jobs stuck in 'processing' for > 10 minutes. */
function reclaim_stale(PDO $pdo): void
{
    $pdo->prepare(
        "UPDATE ingestion_jobs SET status = 'pending', updated_at = now()
         WHERE status = 'processing' AND updated_at < now() - INTERVAL '10 minutes'"
    )->execute();
    $pdo->prepare(
        "UPDATE expert_jobs SET status = 'pending', updated_at = now()
         WHERE status = 'processing' AND updated_at < now() - INTERVAL '10 minutes'"
    )->execute();
}

/** Claim one pending ingestion job, or null. */
function claim_ingestion_job(PDO $pdo): ?array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query(
            "SELECT * FROM ingestion_jobs
             WHERE status = 'pending'
               AND (next_retry_at IS NULL OR next_retry_at <= now())
             ORDER BY created_at ASC LIMIT 1
             FOR UPDATE SKIP LOCKED"
        );
        $job = $stmt->fetch();
        if (!is_array($job)) {
            $pdo->commit();
            return null;
        }
        $pdo->prepare(
            "UPDATE ingestion_jobs SET status = 'processing', updated_at = now() WHERE id = ?"
        )->execute([$job['id']]);
        $pdo->commit();
        return $job;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Claim one pending expert job, or null. */
function claim_expert_job(PDO $pdo): ?array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query(
            "SELECT * FROM expert_jobs
             WHERE status = 'pending'
               AND (next_retry_at IS NULL OR next_retry_at <= now())
             ORDER BY created_at ASC LIMIT 1
             FOR UPDATE SKIP LOCKED"
        );
        $job = $stmt->fetch();
        if (!is_array($job)) {
            $pdo->commit();
            return null;
        }
        $pdo->prepare(
            "UPDATE expert_jobs SET status = 'processing', updated_at = now() WHERE id = ?"
        )->execute([$job['id']]);
        $pdo->commit();
        return $job;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function fail_job(PDO $pdo, string $table, int $jobId, string $error): void
{
    $stmt = $pdo->prepare("SELECT attempts, max_attempts FROM $table WHERE id = ?");
    $stmt->execute([$jobId]);
    $row = $stmt->fetch();
    $attempts = (int) ($row['attempts'] ?? 0) + 1;
    $max = (int) ($row['max_attempts'] ?? 3);
    if ($attempts >= $max) {
        $pdo->prepare(
            "UPDATE $table SET status = 'failed', attempts = ?, error = ?, updated_at = now() WHERE id = ?"
        )->execute([$attempts, $error, $jobId]);
    } else {
        $backoff = [30, 60, 120][$attempts - 1] ?? 120;
        $pdo->prepare(
            "UPDATE $table SET status = 'pending', attempts = ?, error = ?,
             next_retry_at = now() + INTERVAL '{$backoff} seconds', updated_at = now() WHERE id = ?"
        )->execute([$attempts, $error, $jobId]);
    }
}

function run_ingestion_pass(PDO $pdo): int
{
    $done = 0;
    while ($job = claim_ingestion_job($pdo)) {
        $tenantId = (int) $job['tenant_id'];
        $docId = (int) $job['document_id'];
        worker_log("Ingesting document $docId (job {$job['id']})");
        try {
            $ok = ingestion_process_document($pdo, $tenantId, $docId);
            if ($ok) {
                $pdo->prepare(
                    "UPDATE ingestion_jobs SET status = 'completed', updated_at = now() WHERE id = ?"
                )->execute([$job['id']]);
                $done++;
            } else {
                fail_job($pdo, 'ingestion_jobs', (int) $job['id'], 'Ingestion failed. See documents.error for details.');
            }
        } catch (Throwable $e) {
            error_log('NexGen CLD ingestion job error: ' . $e->getMessage());
            fail_job($pdo, 'ingestion_jobs', (int) $job['id'], 'Ingestion failed. See the server log.');
        }
    }
    return $done;
}

function run_expert_pass(PDO $pdo): int
{
    $done = 0;
    while ($job = claim_expert_job($pdo)) {
        $tenantId = (int) $job['tenant_id'];
        $slug = $job['expert_slug'];
        worker_log("Running expert $slug (job {$job['id']}, run {$job['run_id']})");
        try {
            $expert = expert_load($pdo, $tenantId, $slug);
            if ($expert === null) {
                fail_job($pdo, 'expert_jobs', (int) $job['id'], 'Expert is disabled or missing for this tenant.');
                continue;
            }
            $result = expert_run_once($pdo, $tenantId, $expert, $job['input_text']);
            $pdo->prepare(
                "UPDATE expert_jobs SET status = 'completed', result_text = ?, sources = ?, updated_at = now() WHERE id = ?"
            )->execute([$result['answer'], json_encode($result['sources']), $job['id']]);
            $done++;

            // If this was the last pending/processing job in its run,
            // finish the run (synthesizer if multi-expert).
            if ($job['run_id'] !== null) {
                $pending = $pdo->prepare(
                    "SELECT COUNT(*) FROM expert_jobs WHERE run_id = ? AND status IN ('pending','processing')"
                );
                $pending->execute([$job['run_id']]);
                if ((int) $pending->fetchColumn() === 0) {
                    orchestrator_finish_run($pdo, $tenantId, (int) $job['run_id']);
                    worker_log("Run {$job['run_id']} finished.");
                }
            }
        } catch (Throwable $e) {
            error_log('NexGen CLD expert job error: ' . $e->getMessage());
            fail_job($pdo, 'expert_jobs', (int) $job['id'], 'Expert failed. See the server log.');
            // A failed job may still be the last one in its run.
            if ($job['run_id'] !== null) {
                try {
                    $pending = $pdo->prepare(
                        "SELECT COUNT(*) FROM expert_jobs WHERE run_id = ? AND status IN ('pending','processing')"
                    );
                    $pending->execute([$job['run_id']]);
                    if ((int) $pending->fetchColumn() === 0) {
                        orchestrator_finish_run($pdo, $tenantId, (int) $job['run_id']);
                    }
                } catch (Throwable $e2) {
                    error_log('NexGen CLD run finish error: ' . $e2->getMessage());
                }
            }
        }
    }
    return $done;
}

function run_pass(PDO $pdo, string $type): void
{
    reclaim_stale($pdo);
    if ($type === 'all' || $type === 'ingestion') {
        $n = run_ingestion_pass($pdo);
        if ($n > 0) {
            worker_log("Ingestion pass: $n completed.");
        }
    }
    if ($type === 'all' || $type === 'expert') {
        $n = run_expert_pass($pdo);
        if ($n > 0) {
            worker_log("Expert pass: $n completed.");
        }
    }
}

$pdo = db();
worker_log("Worker started (type=$type, loop=" . ($loop ? 'yes' : 'no') . ").");
do {
    try {
        run_pass($pdo, $type);
    } catch (Throwable $e) {
        error_log('NexGen CLD worker pass error: ' . $e->getMessage());
        worker_log('Pass failed: ' . $e->getMessage());
    }
    if ($loop) {
        sleep(5);
    }
} while ($loop);
worker_log('Worker done.');
