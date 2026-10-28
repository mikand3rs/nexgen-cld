<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NexGen CLD — Ask the experts (V3)
|--------------------------------------------------------------------------
|
| POST /ask.php  (JSON) — create a run, route, and answer.
|   Body: { "tenant_slug": "nexgen-cloud", "question": "...",
|           "expert_slug": "website" (optional, forces one expert),
|           "sync": true (optional, default true for single-expert) }
|
| GET /ask.php?run_id=123&tenant_slug=nexgen-cloud — run status.
|
| Behaviour:
|   * Router (rules-based) picks up to 2 experts, or the forced expert.
|   * Single expert + sync (default): the expert runs inline and the
|     answer is returned immediately, with sources.
|   * Multi-expert, or sync=false: jobs are written, the worker
|     runs them, and the caller polls GET /ask.php?run_id=... until
|     the run is completed / partial / failed. The synthesizer runs
|     last (see orchestrator_finish_run).
|   * Tenant filter is applied in every retrieval query. A question
|     from another tenant can never retrieve this tenant's documents.
|
| GET without run_id shows a simple form that posts JSON via fetch,
| so a human can try it without curl.
*/

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/experts.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ask_error(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

try {
    $pdo = db();
} catch (Throwable $e) {
    error_log('NexGen CLD ask DB error: ' . $e->getMessage());
    ask_error(503, 'The database is unavailable right now.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $runId = (int) ($_GET['run_id'] ?? 0);
    $tenantSlug = trim((string) ($_GET['tenant_slug'] ?? 'nexgen-cloud'));
    if ($runId <= 0) {
        // Not a JSON API call — show the form page instead.
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8"><title>Ask experts — NexGen CLD</title>
        <style>body{font-family:system-ui,sans-serif;max-width:760px;margin:40px auto;padding:0 20px}textarea{width:100%;min-height:90px;padding:10px}button{margin-top:12px;padding:12px 24px;border:0;background:#286044;color:#fff;font-weight:600;cursor:pointer}pre{white-space:pre-wrap;background:#e8f0e9;padding:16px}nav a{margin-right:16px}</style>
        </head><body>
        <nav><a href="/">Chat</a> <a href="/upload.php">Upload</a> <a href="/documents.php">Documents</a> <a href="/ask.php">Ask experts</a></nav>
        <h1>Ask the experts</h1>
        <p>Ask a question. The router picks the right expert(s) from your uploaded documents, or you can force one expert.</p>
        <form id="ask-form">
            <label>Question<br><textarea id="question" required maxlength="12000"></textarea></label><br>
            <label>Expert (optional, blank = router decides)<br>
            <select id="expert"><option value="">Router decides</option>
            <option value="general-operations">General Business Operations</option>
            <option value="website">Website &amp; Client Sites</option>
            <option value="client-onboarding">Client Onboarding &amp; Communication</option>
            <option value="support">Support &amp; Troubleshooting</option>
            <option value="finance-admin">Finance &amp; Admin</option>
            </select></label><br>
            <button type="submit">Ask</button>
        </form>
        <pre id="result" hidden></pre>
        <script>
        document.getElementById('ask-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const out = document.getElementById('result');
            out.hidden = false; out.textContent = 'Thinking…';
            const body = { tenant_slug: 'nexgen-cloud', question: document.getElementById('question').value.trim() };
            const expert = document.getElementById('expert').value;
            if (expert) body.expert_slug = expert;
            let res = await fetch('/ask.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
            let data = await res.json();
            if (data.status === 'pending' || data.status === 'running') {
                // Multi-expert: poll until the run finishes.
                for (let i = 0; i < 60; i++) {
                    await new Promise(r => setTimeout(r, 3000));
                    res = await fetch('/ask.php?run_id=' + data.run_id + '&tenant_slug=nexgen-cloud');
                    data = await res.json();
                    if (data.status === 'completed' || data.status === 'partial' || data.status === 'failed') break;
                    out.textContent = 'Working… (' + data.status + ')';
                }
            }
            out.textContent = data.answer || data.error || JSON.stringify(data, null, 2);
            if (data.sources) out.textContent += '\n\nSources:\n' + data.sources.map(s => '[' + s.ref + '] ' + s.document + ' (chunk ' + s.chunk_index + ')').join('\n');
        });
        </script>
        </body></html>
        <?php
        exit;
    }
    try {
        $tenantId = tenant_id_by_slug($pdo, $tenantSlug);
        $stmt = $pdo->prepare('SELECT * FROM expert_runs WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$runId, $tenantId]);
        $run = $stmt->fetch();
        if (!is_array($run)) {
            ask_error(404, 'Run not found.');
        }
        echo json_encode([
            'run_id' => (int) $run['id'],
            'status' => $run['status'],
            'experts' => json_decode($run['router_experts'] ?? '[]', true),
            'answer' => $run['answer'],
            'sources' => json_decode($run['sources'] ?? '[]', true),
        ]);
        exit;
    } catch (Throwable $e) {
        error_log('NexGen CLD ask GET error: ' . $e->getMessage());
        ask_error(500, 'Could not load that run.');
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    ask_error(405, 'Method not allowed.');
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    ask_error(400, 'Invalid JSON request.');
}
$question = trim((string) ($input['question'] ?? ''));
$tenantSlug = trim((string) ($input['tenant_slug'] ?? 'nexgen-cloud'));
$forcedExpert = trim((string) ($input['expert_slug'] ?? ''));
$sync = $input['sync'] ?? true;

if ($question === '') {
    ask_error(400, 'Question is required.');
}
if (strlen($question) > 12000) {
    ask_error(413, 'Question is too long.');
}

try {
    $tenantId = tenant_id_by_slug($pdo, $tenantSlug);
    $expertSlugs = $forcedExpert !== ''
        ? [$forcedExpert]
        : route_question($question);

    // Filter out experts disabled for this tenant, before creating jobs.
    $expertSlugs = array_values(array_filter(
        $expertSlugs,
        fn ($slug) => expert_load($pdo, $tenantId, $slug) !== null
    ));
    if ($expertSlugs === []) {
        $expertSlugs = ['general-operations'];
    }

    $runId = orchestrator_create_run($pdo, $tenantId, $question, $expertSlugs);

    // Single expert + sync: run inline, finish the run, return the answer.
    if ($sync && count($expertSlugs) === 1) {
        $jobStmt = $pdo->prepare(
            "SELECT * FROM expert_jobs WHERE run_id = ? AND tenant_id = ? AND status = 'pending' ORDER BY id ASC LIMIT 1"
        );
        $jobStmt->execute([$runId, $tenantId]);
        $job = $jobStmt->fetch();
        if (is_array($job)) {
            $pdo->prepare("UPDATE expert_jobs SET status = 'processing', updated_at = now() WHERE id = ?")
                ->execute([$job['id']]);
            try {
                $expert = expert_load($pdo, $tenantId, $job['expert_slug']);
                if ($expert === null) {
                    throw new RuntimeException('Expert is disabled for this tenant.');
                }
                $result = expert_run_once($pdo, $tenantId, $expert, $question);
                $pdo->prepare(
                    "UPDATE expert_jobs SET status = 'completed', result_text = ?, sources = ?, updated_at = now() WHERE id = ?"
                )->execute([$result['answer'], json_encode($result['sources']), $job['id']]);
            } catch (Throwable $e) {
                error_log('NexGen CLD ask inline expert error: ' . $e->getMessage());
                $pdo->prepare(
                    "UPDATE expert_jobs SET status = 'failed', error = ?, updated_at = now() WHERE id = ?"
                )->execute(['Expert failed. See the server log.', $job['id']]);
            }
            $run = orchestrator_finish_run($pdo, $tenantId, $runId);
            echo json_encode([
                'run_id' => $runId,
                'status' => $run['status'],
                'experts' => $expertSlugs,
                'answer' => $run['answer'],
                'sources' => json_decode($run['sources'] ?? '[]', true),
            ]);
            exit;
        }
    }

    // Multi-expert or async: worker runs the jobs; caller polls GET.
    echo json_encode([
        'run_id' => $runId,
        'status' => 'pending',
        'experts' => $expertSlugs,
        'answer' => null,
        'sources' => [],
        'poll' => '/ask.php?run_id=' . $runId . '&tenant_slug=' . urlencode($tenantSlug),
    ]);
} catch (Throwable $e) {
    error_log('NexGen CLD ask error: ' . $e->getMessage());
    ask_error(502, 'The experts are unavailable right now.');
}
