<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NexGen CLD — Experts: registry, router, orchestrator, synthesizer
|--------------------------------------------------------------------------
|
| V3 design, as planned:
|   * Expert configs live in the DB (experts table, versioned).
|     Seeds (app/db/seeds/experts.json) recreate v1 on a fresh deploy.
|   * Tenant overrides can enable/disable an expert or replace its
|     system prompt for that tenant only.
|   * Router is rules-based first (deterministic, testable, no extra
|     LLM call on a 2B laptop model). Fallback is always
|     general-operations — never a random expert, never silent.
|   * Orchestrator (PHP, control plane) writes job rows; it does
|     not spawn processes. The worker (app/worker.php) executes them.
|   * Synthesizer combines expert outputs, keeps sources attached,
|     and surfaces disagreements instead of hiding them.
|
| Model rule: an expert's model is '' in the seeds, which means
| "use the configured Lemonade model" (ai_model()). If a future
| expert needs a different model, set it in the registry — and
| remember the V3.2 queueing rule: experts on different models
| run serially, because switching models costs time and memory
| on shared-RAM hardware. This code runs experts sequentially
| in run order, which is safe on the laptop by default.
*/

/**
 * Load the latest enabled version of an expert for a tenant,
 * applying a tenant override if one exists. Returns null if the
 * expert is disabled for this tenant or does not exist.
 */
function expert_load(PDO $pdo, int $tenantId, string $slug): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM experts WHERE slug = ? AND enabled = TRUE
         ORDER BY version DESC LIMIT 1'
    );
    $stmt->execute([$slug]);
    $expert = $stmt->fetch();
    if (!is_array($expert)) {
        return null;
    }

    $override = $pdo->prepare(
        'SELECT * FROM tenant_expert_overrides WHERE tenant_id = ? AND expert_slug = ?'
    );
    $override->execute([$tenantId, $slug]);
    $row = $override->fetch();
    if (is_array($row)) {
        if (!$row['enabled']) {
            return null;
        }
        if (!empty($row['system_prompt_override'])) {
            $expert['system_prompt'] = $row['system_prompt_override'];
        }
    }

    $scope = json_decode($expert['retrieval_scope'] ?? '[]', true);
    $expert['retrieval_scope'] = is_array($scope) ? $scope : [];
    return $expert;
}

/**
 * Rules-based router. Pure function (testable without a DB).
 * Input: question. Output: ordered expert slugs, highest priority first.
 * Rules are keyword groups per expert; a question can match several
 * experts (multi-expert path), in priority order. No match, or a
 * low-confidence match only, falls back to general-operations.
 *
 * @return string[]
 */
function route_question(string $question): array
{
    // strtolower, not mb_strtolower: keywords are ASCII, and this
    // keeps routing working without the mbstring extension.
    $q = strtolower($question);
    $rules = [
        'website' => ['website', 'site', 'web page', 'landing page', 'wordpress', 'html', 'css', 'tailwind', 'htmx', 'contact form', 'banner'],
        'support' => ['docker', 'error', 'bug', 'crash', 'postgres', 'database', 'nginx', 'php', 'server', 'deploy', 'not working', 'failed', 'troubleshoot', 'fix'],
        'finance-admin' => ['invoice', 'price', 'pricing', 'payment', 'bill', 'subscription', 'cost', 'quote', 'tax', 'admin'],
        'client-onboarding' => ['onboard', 'new client', 'intake', 'proposal', 'scope', 'client', 'email', 'handover', 'kickoff'],
        'general-operations' => ['sop', 'process', 'checklist', 'schedule', 'operations', 'run the business', 'daily'],
    ];

    $matched = [];
    foreach ($rules as $slug => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($q, $kw)) {
                $matched[] = $slug;
                break;
            }
        }
    }

    // Deduplicate, keep priority order (array order above).
    $matched = array_values(array_unique($matched));

    // Multi-expert cap: at most 2 experts per run, to keep the
    // laptop usable and the answer readable. The first match is
    // primary; the second is supporting, if any.
    $matched = array_slice($matched, 0, 2);

    if ($matched === []) {
        return ['general-operations'];
    }
    return $matched;
}

/**
 * Run one expert synchronously: retrieve (tenant + scope), build a
 * grounded prompt, call Lemonade via ai_chat(). Returns
 * ['answer' => string, 'sources' => array]. Throws on AI failure —
 * the caller (orchestrator / worker) records it as a failed job.
 */
function expert_run_once(PDO $pdo, int $tenantId, array $expert, string $question): array
{
    require_once __DIR__ . '/retrieval.php';
    require_once dirname(__DIR__) . '/ai-connect.php';

    $passages = retrieve_passages(
        $pdo,
        $tenantId,
        $question,
        $expert['retrieval_scope'],
        (int) ($expert['top_k'] ?? 5)
    );

    $prompt = grounded_prompt($expert['system_prompt'], $passages, $question);
    $temperature = isset($expert['temperature']) ? (float) $expert['temperature'] : 0.2;

    // ai_chat() in ai-connect.php takes (prompt, system, temperature).
    // The expert system prompt is already inside $prompt, so the
    // second argument is empty here to avoid duplicating it.
    $answer = ai_chat($prompt, '', $temperature);

    return [
        'answer' => $answer,
        'sources' => passages_to_sources($passages),
    ];
}

/**
 * Create an expert run + one job per expert. Returns the run id.
 * This is the orchestrator's control-plane step: it writes rows,
 * it does not call the AI itself.
 */
function orchestrator_create_run(PDO $pdo, int $tenantId, string $question, array $expertSlugs): int
{
    $pdo->beginTransaction();
    try {
        db_set_tenant($pdo, $tenantId);
        $run = $pdo->prepare(
            "INSERT INTO expert_runs (tenant_id, question, router_experts, status)
             VALUES (?, ?, ?, 'pending') RETURNING id"
        );
        $run->execute([$tenantId, $question, json_encode($expertSlugs)]);
        $runId = (int) $run->fetchColumn();

        $job = $pdo->prepare(
            "INSERT INTO expert_jobs (tenant_id, run_id, expert_slug, expert_version, input_text, status)
             VALUES (?, ?, ?, ?, ?, 'pending')"
        );
        foreach ($expertSlugs as $slug) {
            $expert = expert_load($pdo, $tenantId, $slug);
            if ($expert === null) {
                continue;
            }
            $job->execute([$tenantId, $runId, $slug, (int) $expert['version'], $question]);
        }

        $pdo->prepare("UPDATE expert_runs SET status = 'running', updated_at = now() WHERE id = ?")
            ->execute([$runId]);
        $pdo->commit();
        return $runId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Finish a run after its jobs are done: if there is one completed
 * job, its answer is the run answer. If several, the synthesizer
 * combines them (one more Lemonade call, with sources attached).
 * If all jobs failed, the run is failed. If some failed, the run
 * is partial, and the answer says which expert failed.
 * Returns the run row (fresh from the DB).
 */
function orchestrator_finish_run(PDO $pdo, int $tenantId, int $runId): array
{
    $runStmt = $pdo->prepare('SELECT * FROM expert_runs WHERE id = ? AND tenant_id = ?');
    $runStmt->execute([$runId, $tenantId]);
    $run = $runStmt->fetch();
    if (!is_array($run)) {
        throw new RuntimeException('Run not found.');
    }

    $jobsStmt = $pdo->prepare(
        'SELECT * FROM expert_jobs WHERE run_id = ? AND tenant_id = ? ORDER BY id ASC'
    );
    $jobsStmt->execute([$runId, $tenantId]);
    $jobs = $jobsStmt->fetchAll();

    $completed = array_filter($jobs, fn ($j) => $j['status'] === 'completed' && !empty($j['result_text']));
    $failed = array_filter($jobs, fn ($j) => $j['status'] === 'failed');

    if ($completed === []) {
        $pdo->prepare("UPDATE expert_runs SET status = 'failed', updated_at = now() WHERE id = ?")
            ->execute([$runId]);
        $runStmt->execute([$runId, $tenantId]);
        return $runStmt->fetch();
    }

    $allSources = [];
    foreach ($completed as $job) {
        $src = json_decode($job['sources'] ?? '[]', true);
        if (is_array($src)) {
            $allSources = array_merge($allSources, $src);
        }
    }

    if (count($completed) === 1) {
        $only = array_values($completed)[0];
        $answer = $only['result_text'];
        $status = $failed !== [] ? 'partial' : 'completed';
        if ($failed !== []) {
            $failedNames = implode(', ', array_map(fn ($j) => $j['expert_slug'], $failed));
            $answer .= "\n\nNote: the following expert(s) failed and are not included: " . $failedNames . '.';
        }
    } else {
        $synth = expert_load($pdo, $tenantId, 'synthesizer');
        $combined = [];
        foreach ($completed as $job) {
            $combined[] = 'Expert ' . $job['expert_slug'] . ":\n" . $job['result_text'];
        }
        $failedNote = '';
        if ($failed !== []) {
            $failedNames = implode(', ', array_map(fn ($j) => $j['expert_slug'], $failed));
            $failedNote = "\n\nNote: the following expert(s) failed and are not included: " . $failedNames . '.';
        }
        if ($synth !== null) {
            require_once dirname(__DIR__) . '/ai-connect.php';
            $synthPrompt = $synth['system_prompt'] . "\n\n" . implode("\n\n", $combined);
            try {
                $answer = ai_chat($synthPrompt, '', (float) ($synth['temperature'] ?? 0.2)) . $failedNote;
                $status = $failed !== [] ? 'partial' : 'completed';
            } catch (Throwable $e) {
                error_log('NexGen CLD synthesizer failed: ' . $e->getMessage());
                // Fallback: concatenated expert answers, labelled, not silent.
                $answer = implode("\n\n---\n\n", $combined) . $failedNote;
                $status = 'partial';
            }
        } else {
            $answer = implode("\n\n---\n\n", $combined) . $failedNote;
            $status = $failed !== [] ? 'partial' : 'completed';
        }
    }

    $pdo->prepare(
        'UPDATE expert_runs SET status = ?, answer = ?, sources = ?, updated_at = now() WHERE id = ?'
    )->execute([$status, $answer, json_encode($allSources), $runId]);

    $runStmt->execute([$runId, $tenantId]);
    return $runStmt->fetch();
}
