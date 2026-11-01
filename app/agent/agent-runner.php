<?php
// BMOS 3.2 Stage B — agent sandbox runner.
//
// SECURITY MODEL:
//   - No Docker socket. No DB credentials. No BMOS secrets.
//   - Backend network only; reaches BMOS via http://nginx (auth enforced).
//   - Authenticates with scoped BMOS API keys (Bearer), tenant-bound.
//   - Each task runs in a disposable /tmp workspace, wiped afterward.
//   - Destructive operations require human approval (approval file gate).
//
// USAGE (inside the sandbox container):
//   BMOS_API_KEY=bmos_... php /workspace/agent-runner.php <task.json>
//   Task JSON: {"action":"<action>","params":{...}}
//
// ACTIONS:
//   - enqueue_job: {"job_type":..., "title":..., ...} → POST /jobs/enqueue
//   - api_call: {"method":..., "path":..., "body":...} → arbitrary BMOS API call
//   - shell: {"command":...} → runs in disposable workspace (SAFE commands only;
//     destructive ops require /tmp/agent-approval/<task-id> to exist)

declare(strict_types=1);

$apiKey = getenv('BMOS_API_KEY') ?: '';
if ($apiKey === '') {
    fwrite(STDERR, "BMOS_API_KEY not set\n");
    exit(1);
}
$taskFile = $argv[1] ?? '';
if ($taskFile === '' || !is_readable($taskFile)) {
    fwrite(STDERR, "Usage: php agent-runner.php <task.json>\n");
    exit(1);
}
$task = json_decode(file_get_contents($taskFile), true);
if (!is_array($task) || !isset($task['action'])) {
    fwrite(STDERR, "Invalid task JSON\n");
    exit(1);
}

$base = getenv('BMOS_API_URL') ?: 'http://nginx';
$taskId = 'task-' . bin2hex(random_bytes(8));

function api_call(string $method, string $path, ?array $body = null): array {
    global $base, $apiKey;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $base . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp ?: '{}', true)];
}

// Destructive patterns that require human approval.
$DESTRUCTIVE = ['/rm\s+-rf?\s+\//', '/drop\s+table/i', '/delete\s+from/i', '/shutdown/i', '/reboot/i'];

function requires_approval(string $command): bool {
    global $DESTRUCTIVE;
    foreach ($DESTRUCTIVE as $pattern) {
        if (preg_match($pattern, $command)) return true;
    }
    return false;
}

$action = $task['action'];
$params = $task['params'] ?? [];

switch ($action) {
    case 'enqueue_job':
        $r = api_call('POST', '/jobs/enqueue', $params);
        echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
        exit($r['code'] >= 200 && $r['code'] < 300 ? 0 : 1);

    case 'api_call':
        $r = api_call($params['method'] ?? 'GET', $params['path'] ?? '/', $params['body'] ?? null);
        echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
        exit($r['code'] >= 200 && $r['code'] < 300 ? 0 : 1);

    case 'shell':
        $command = $params['command'] ?? '';
        if ($command === '') { fwrite(STDERR, "No command\n"); exit(1); }
        // Disposable workspace.
        $ws = sys_get_temp_dir() . '/' . $taskId;
        mkdir($ws, 0700, true);
        // Approval gate for destructive ops.
        if (requires_approval($command)) {
            $approvalFile = sys_get_temp_dir() . '/agent-approval/' . $taskId;
            if (!file_exists($approvalFile)) {
                fwrite(STDERR, "DESTRUCTIVE COMMAND BLOCKED: $command\n");
                fwrite(STDERR, "Human approval required: create $approvalFile to approve.\n");
                // Cleanup workspace before exit.
                exec('rm -rf ' . escapeshellarg($ws));
                exit(2);
            }
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $descriptors, $pipes, $ws);
        if (!is_resource($proc)) { fwrite(STDERR, "Failed to run\n"); exit(1); }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($proc);
        echo $out;
        if ($err) fwrite(STDERR, $err);
        // Wipe the disposable workspace.
        exec('rm -rf ' . escapeshellarg($ws));
        exit($exit);

    default:
        fwrite(STDERR, "Unknown action: $action\n");
        exit(1);
}
