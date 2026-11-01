<?php
// BMOS 3.2 — Agent Runner v2 (remediation: AGENT-01, AGENT-02, GOV-01).
//
// SECURITY MODEL:
//   - No Docker socket. No DB credentials. No host mounts.
//   - Backend network only; reaches BMOS via http://nginx (auth enforced).
//   - Authenticates with a scoped BMOS API key (Bearer, tenant-bound),
//     injected via Docker secret file (BMOS_API_KEY_FILE), never baked in.
//   - NEVER passes untrusted input to a shell. There is no `shell` action.
//     Allowed operations are a fixed allowlist with structured arguments.
//   - Consequential actions require a BMOS-owned human approval record
//     (GOV-01). The runner checks BMOS; a local file is never approval.
//   - Fails closed: missing/invalid credential, missing task scope, or
//     failed workspace setup → non-zero exit, no partial execution.
//
// CONTAINER CONTRACT:
//   - Command: ["php", "/opt/agent/agent-runner.php", "--daemon"]
//     or single-task: ["php", "/opt/agent/agent-runner.php", "/path/to/task.json"]
//   - Env: BMOS_API_URL (default http://nginx)
//   - Secret: BMOS_API_KEY_FILE (default /run/secrets/agent_api_key)
//   - Mount: ./app/agent:/opt/agent:ro
//
// TASK JSON:
//   {"task_id": "...", "tenant_id": "...", "action": "<allowlisted>",
//    "params": {...}, "approval_id": "... (if required)"}
//
// ALLOWLISTED ACTIONS:
//   - task.fetch: {"task_id"} → GET /agent/tasks/{id} (scoped to runner identity)
//   - task.complete: {"task_id", "result"} → POST /agent/tasks/{id}/complete
//   - job.enqueue: {"job_type", "title", ...} → POST /jobs/enqueue
//   - api.call: {"method", "path", "body"} → BMOS API (tenant-scoped by auth)
//   - file.write: {"path", "content"} → write inside workspace only
//     (path traversal blocked; absolute paths blocked; symlinks not followed)
//   - file.read: {"path"} → read inside workspace only
//   - http.fetch: {"url", "method"} → allowlisted hosts only (see below)

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Startup validation (fail closed).
// ---------------------------------------------------------------------------

$keyFile = getenv('BMOS_API_KEY_FILE') ?: '/run/secrets/agent_api_key';
if (!is_readable($keyFile)) {
    fwrite(STDERR, "FATAL: API key file not readable: $keyFile\n");
    fwrite(STDERR, "Provide a scoped BMOS API key via Docker secret.\n");
    exit(10);
}
$apiKey = trim(file_get_contents($keyFile));
if ($apiKey === '' || !str_starts_with($apiKey, 'bmos_')) {
    fwrite(STDERR, "FATAL: API key file is empty or malformed.\n");
    exit(10);
}
// Never log the key. We only confirm its presence.
$base = getenv('BMOS_API_URL') ?: 'http://nginx';

// Validate we can reach BMOS and the key is valid before doing anything.
function api_call(string $method, string $path, ?array $body = null): array {
    global $base, $apiKey;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $base . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return ['code' => 0, 'body' => ['error' => "curl: $err"]];
    }
    return ['code' => $code, 'body' => json_decode($resp ?: '{}', true)];
}

// Startup self-test: the key must authenticate.
$me = api_call('GET', '/auth/me');
if ($me['code'] !== 200) {
    fwrite(STDERR, "FATAL: API key rejected by BMOS (HTTP {$me['code']}).\n");
    fwrite(STDERR, "Generate a scoped key via Auth::createApiKey() and mount it as a secret.\n");
    exit(11);
}
$identity = $me['body']['email'] ?? 'agent';
fwrite(STDERR, "agent-runner: authenticated as $identity\n");

// ---------------------------------------------------------------------------
// Workspace: disposable, per-task, with limits.
// ---------------------------------------------------------------------------

define('WS_BASE', sys_get_temp_dir() . '/agent-workspaces');
define('WS_MAX_BYTES', 100 * 1024 * 1024); // 100MB per workspace

function make_workspace(string $taskId): string {
    // Task ID is sanitized to [a-zA-Z0-9_-] to prevent path injection.
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId);
    if ($safe === '' || $safe !== $taskId) {
        fwrite(STDERR, "FATAL: invalid task_id\n");
        exit(12);
    }
    $ws = WS_BASE . '/' . $safe . '-' . bin2hex(random_bytes(4));
    if (!mkdir($ws, 0700, true)) {
        fwrite(STDERR, "FATAL: cannot create workspace\n");
        exit(12);
    }
    return $ws;
}

function wipe_workspace(string $ws): void {
    // Only wipe inside our workspace base. Never rm -rf an arbitrary path.
    $real = realpath($ws);
    $base = realpath(WS_BASE);
    if ($real === false || $base === false || !str_starts_with($real, $base . '/')) {
        fwrite(STDERR, "FATAL: workspace path escape detected\n");
        exit(13);
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($real);
}

/** Resolve a workspace-relative path. Blocks traversal, absolute paths, symlinks. */
function ws_path(string $ws, string $rel): string {
    if (str_starts_with($rel, '/') || str_contains($rel, '..')) {
        fwrite(STDERR, "BLOCKED: path traversal attempt\n");
        exit(14);
    }
    $full = $ws . '/' . $rel;
    // If the path exists and is a symlink, refuse.
    if (file_exists($full) && is_link($full)) {
        fwrite(STDERR, "BLOCKED: symlink access denied\n");
        exit(14);
    }
    return $full;
}

// ---------------------------------------------------------------------------
// Approval check (GOV-01): consequential actions need a BMOS approval record.
// ---------------------------------------------------------------------------

function check_approval(string $taskId, string $action, array $params): void {
    // Actions that always require human approval.
    $needsApproval = ['job.enqueue', 'api.call'];
    if (!in_array($action, $needsApproval, true)) {
        return;
    }
    $approvalId = $params['approval_id'] ?? '';
    if ($approvalId === '') {
        fwrite(STDERR, "BLOCKED: action '$action' requires a BMOS approval_id.\n");
        exit(20);
    }
    $r = api_call('GET', '/approvals/' . urlencode($approvalId));
    if ($r['code'] !== 200) {
        fwrite(STDERR, "BLOCKED: approval not found or not accessible.\n");
        exit(20);
    }
    $a = $r['body'];
    // Validate: approved, not expired, matches this task and action.
    if (($a['status'] ?? '') !== 'approved') {
        fwrite(STDERR, "BLOCKED: approval status is '{$a['status']}', not approved.\n");
        exit(20);
    }
    if (strtotime($a['expires_at'] ?? 'now') < time()) {
        fwrite(STDERR, "BLOCKED: approval expired.\n");
        exit(20);
    }
    if (($a['task_id'] ?? '') !== $taskId) {
        fwrite(STDERR, "BLOCKED: approval is for a different task.\n");
        exit(20);
    }
    if (($a['action'] ?? '') !== $action) {
        fwrite(STDERR, "BLOCKED: approval is for a different action.\n");
        exit(20);
    }
    // Payload integrity: the approved payload hash must match.
    $payloadHash = hash('sha256', json_encode($params));
    if (!hash_equals($a['payload_hash'] ?? '', $payloadHash)) {
        fwrite(STDERR, "BLOCKED: task payload does not match approved payload.\n");
        exit(20);
    }
    fwrite(STDERR, "approval $approvalId validated for $action\n");
}

// ---------------------------------------------------------------------------
// Allowlisted HTTP fetch hosts.
// ---------------------------------------------------------------------------

$FETCH_ALLOWLIST = [
    // Only hosts the agent is permitted to fetch from.
    // Add entries here deliberately; never allow arbitrary URLs.
];

// ---------------------------------------------------------------------------
// Task execution.
// ---------------------------------------------------------------------------

function run_task(array $task): int {
    $taskId = $task['task_id'] ?? '';
    $action = $task['action'] ?? '';
    $params = $task['params'] ?? [];

    if ($taskId === '' || $action === '') {
        fwrite(STDERR, "Invalid task: missing task_id or action\n");
        return 1;
    }

    $ws = make_workspace($taskId);
    fwrite(STDERR, "task $taskId: workspace $ws\n");

    try {
        // Approval gate for consequential actions.
        check_approval($taskId, $action, $params);

        switch ($action) {
            case 'task.fetch':
                $r = api_call('GET', '/agent/tasks/' . urlencode($params['task_id'] ?? $taskId));
                echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
                return ($r['code'] >= 200 && $r['code'] < 300) ? 0 : 1;

            case 'task.complete':
                $r = api_call('POST', '/agent/tasks/' . urlencode($taskId) . '/complete', [
                    'result' => $params['result'] ?? null,
                ]);
                echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
                return ($r['code'] >= 200 && $r['code'] < 300) ? 0 : 1;

            case 'job.enqueue':
                // Approval already checked above.
                $r = api_call('POST', '/jobs/enqueue', $params);
                echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
                return ($r['code'] >= 200 && $r['code'] < 300) ? 0 : 1;

            case 'api.call':
                // Approval already checked above.
                $method = strtoupper($params['method'] ?? 'GET');
                $path = $params['path'] ?? '/';
                if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    fwrite(STDERR, "BLOCKED: invalid method\n");
                    return 1;
                }
                if (!str_starts_with($path, '/') || str_contains($path, '..')) {
                    fwrite(STDERR, "BLOCKED: invalid path\n");
                    return 1;
                }
                $r = api_call($method, $path, $params['body'] ?? null);
                echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
                return ($r['code'] >= 200 && $r['code'] < 300) ? 0 : 1;

            case 'file.write':
                $p = ws_path($ws, $params['path'] ?? '');
                $content = $params['content'] ?? '';
                if (strlen($content) > WS_MAX_BYTES) {
                    fwrite(STDERR, "BLOCKED: content exceeds workspace limit\n");
                    return 1;
                }
                $dir = dirname($p);
                if (!is_dir($dir)) mkdir($dir, 0700, true);
                file_put_contents($p, $content);
                echo json_encode(['ok' => true, 'path' => $params['path']]) . "\n";
                return 0;

            case 'file.read':
                $p = ws_path($ws, $params['path'] ?? '');
                if (!is_file($p)) {
                    fwrite(STDERR, "Not found\n");
                    return 1;
                }
                echo file_get_contents($p);
                return 0;

            case 'http.fetch':
                global $FETCH_ALLOWLIST;
                $url = $params['url'] ?? '';
                $host = parse_url($url, PHP_URL_HOST);
                if (!$host || !in_array($host, $FETCH_ALLOWLIST, true)) {
                    fwrite(STDERR, "BLOCKED: host not in fetch allowlist\n");
                    return 1;
                }
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                $out = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                echo json_encode(['code' => $code, 'body' => substr($out ?: '', 0, 102400)]) . "\n";
                return 0;

            default:
                fwrite(STDERR, "BLOCKED: unknown action '$action'\n");
                return 1;
        }
    } finally {
        wipe_workspace($ws);
        fwrite(STDERR, "task $taskId: workspace wiped\n");
    }
}

// ---------------------------------------------------------------------------
// Entry point.
// ---------------------------------------------------------------------------

$mode = $argv[1] ?? '';
if ($mode === '--daemon') {
    fwrite(STDERR, "agent-runner: daemon mode, polling for tasks\n");
    while (true) {
        $r = api_call('GET', '/agent/tasks/next');
        if ($r['code'] === 200 && isset($r['body']['task_id'])) {
            $code = run_task($r['body']);
            api_call('POST', '/agent/tasks/' . urlencode($r['body']['task_id']) . '/complete', [
                'exit_code' => $code,
            ]);
        } elseif ($r['code'] === 404) {
            sleep(10);
        } else {
            fwrite(STDERR, "poll error: HTTP {$r['code']}\n");
            sleep(30);
        }
    }
} elseif ($mode !== '') {
    $taskFile = $mode;
    if (!is_readable($taskFile)) {
        fwrite(STDERR, "Usage: php agent-runner.php [--daemon|<task.json>]\n");
        exit(1);
    }
    $task = json_decode(file_get_contents($taskFile), true);
    if (!is_array($task)) {
        fwrite(STDERR, "Invalid task JSON\n");
        exit(1);
    }
    exit(run_task($task));
} else {
    fwrite(STDERR, "Usage: php agent-runner.php [--daemon|<task.json>]\n");
    exit(1);
}
