<?php
// BMOS 3.2 — Phase 5: three endpoint groups.
//   GET  /health        liveness only. No database dependency by design: a DB
//                       outage must not falsely report that the PHP process
//                       itself is dead.
//   GET  /ready         readiness. Checks PostgreSQL as the bmos_app runtime
//                       role. 200 = serving traffic, 503 = not ready yet.
//   POST /jobs/enqueue  demo of the transactional-outbox pattern: one
//                       transaction writes the business record (app.tasks)
//                       AND the outbox job row atomically. The PHP worker
//                       delivers the job later.
//
// DEMO AUTH STAND-IN: /jobs/enqueue takes the tenant from the X-Tenant-ID
// header. That header is NOT authentication — it is a placeholder until a
// later phase wires real login. Do not treat it as a security boundary.
header('Content-Type: application/json');

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Qdrant.php';
require_once __DIR__ . '/../src/Embeddings.php';
require_once __DIR__ . '/../src/S3Client.php';
require_once __DIR__ . '/../src/Lemonade.php';

$version = '3.2-dev';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/jobs/enqueue' && $method === 'POST') {
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
        http_response_code(400);
        echo json_encode(['error' => 'X-Tenant-ID header must be a UUID (demo auth stand-in, not real auth)']);
        return;
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    $jobType = $body['job_type'] ?? 'task.created';
    if (!in_array($jobType, ['task.created', 'demo.fail_once', 'document.index'], true)) {
        http_response_code(400);
        echo json_encode(['error' => "unsupported job_type '{$jobType}' (Phase 5+6 demo set: task.created, demo.fail_once, document.index)"]);
        return;
    }
    $title = is_string($body['title'] ?? null) && $body['title'] !== '' ? $body['title'] : 'demo task';
    $idempotencyKey = $body['idempotency_key'] ?? null;
    if ($idempotencyKey !== null && !is_string($idempotencyKey)) {
        http_response_code(400);
        echo json_encode(['error' => 'idempotency_key must be a string']);
        return;
    }

    try {
        $pdo = Db::connect();
        $pdo->beginTransaction();
        // Tenant context FIRST: every write below is RLS-scoped to it.
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");

        // The tenant must exist (RLS on app.tenants returns the row only
        // under its own context, so this doubles as an existence check).
        $exists = $pdo->query("SELECT 1 FROM app.tenants WHERE id = '{$tenantId}'")->fetchColumn();
        if (!$exists) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'tenant not found']);
            return;
        }

        // Business record first — the outbox payload references it.
        $stmt = $pdo->prepare(
            "INSERT INTO app.tasks (tenant_id, title) VALUES (:t, :title) RETURNING id"
        );
        $stmt->execute([':t' => $tenantId, ':title' => $title]);
        $taskId = $stmt->fetchColumn();

        // Outbox job in the SAME transaction: either both commit or neither
        // does. A duplicate (tenant_id, idempotency_key) inserts nothing (the
        // ROLLBACK below also undoes the task row, so no orphan is left behind).
        $stmt = $pdo->prepare(
            "INSERT INTO app.outbox_jobs (tenant_id, job_type, payload, idempotency_key)
             VALUES (:t, :jt, :p::jsonb, :k)
             ON CONFLICT (tenant_id, idempotency_key) DO NOTHING
             RETURNING id"
        );
        $stmt->execute([
            ':t' => $tenantId,
            ':jt' => $jobType,
            ':p' => json_encode(['task_id' => $taskId]),
            ':k' => $idempotencyKey,
        ]);
        $jobId = $stmt->fetchColumn();

        if ($jobId === false) {
            // Duplicate key: look up the original job while the tenant
            // context is still set (tenant-scoped, so it can only ever be
            // this tenant's own job), then abandon this attempt.
            $jobId = $pdo->query(
                'SELECT id FROM app.outbox_jobs WHERE tenant_id = ' . $pdo->quote($tenantId) .
                ' AND idempotency_key = ' . $pdo->quote((string) $idempotencyKey)
            )->fetchColumn();
            $pdo->rollBack();
            echo json_encode([
                'job_id' => $jobId,
                'task_id' => null,
                'status' => 'pending',
                'deduplicated' => true,
            ]);
            return;
        }

        $pdo->commit();
        http_response_code(201);
        echo json_encode([
            'job_id' => $jobId,
            'task_id' => $taskId,
            'status' => 'pending',
            'deduplicated' => false,
        ]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'enqueue failed']);
    }
    return;
}

if ($path === '/documents' && $method === 'POST') {
    // Create a document with chunks in PostgreSQL (authoritative).
    // Indexing into Qdrant happens via scripts/index-documents.php or the
    // document.index outbox job — Qdrant is the derived index, never the
    // write path for business records.
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
        http_response_code(400);
        echo json_encode(['error' => 'X-Tenant-ID header must be a UUID (demo auth stand-in, not real auth)']);
        return;
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    $title = is_string($body['title'] ?? null) && $body['title'] !== '' ? $body['title'] : 'untitled';
    $chunks = $body['chunks'] ?? [];
    if (!is_array($chunks) || $chunks === []) {
        http_response_code(400);
        echo json_encode(['error' => 'chunks must be a non-empty array of strings']);
        return;
    }
    foreach ($chunks as $c) {
        if (!is_string($c) || $c === '') {
            http_response_code(400);
            echo json_encode(['error' => 'every chunk must be a non-empty string']);
            return;
        }
    }

    try {
        $pdo = Db::connect();
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");

        $exists = $pdo->query("SELECT 1 FROM app.tenants WHERE id = '{$tenantId}'")->fetchColumn();
        if (!$exists) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'tenant not found']);
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO app.documents (tenant_id, title) VALUES (:t, :title) RETURNING id');
        $stmt->execute([':t' => $tenantId, ':title' => $title]);
        $docId = $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO app.document_chunks (document_id, tenant_id, chunk_index, content)
             VALUES (:d, :t, :i, :c) RETURNING id'
        );
        $chunkIds = [];
        foreach (array_values($chunks) as $i => $c) {
            $stmt->execute([':d' => $docId, ':t' => $tenantId, ':i' => $i, ':c' => $c]);
            $chunkIds[] = $stmt->fetchColumn();
        }
        $pdo->commit();
        http_response_code(201);
        echo json_encode([
            'document_id' => $docId,
            'chunk_ids' => $chunkIds,
            'chunks' => count($chunkIds),
        ]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'document create failed']);
    }
    return;
}

if ($path === '/search' && $method === 'POST') {
    // Tenant-filtered vector search. The tenant filter is SERVER-GENERATED
    // from the X-Tenant-ID header. A tenant_id in the request body is
    // IGNORED (forged client tenant IDs must not change the filter).
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
        http_response_code(400);
        echo json_encode(['error' => 'X-Tenant-ID header must be a UUID (demo auth stand-in, not real auth)']);
        return;
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    $query = is_string($body['query'] ?? null) ? trim($body['query']) : '';
    if ($query === '') {
        http_response_code(400);
        echo json_encode(['error' => 'query must be a non-empty string']);
        return;
    }
    $limit = (int) ($body['limit'] ?? 5);
    $limit = max(1, min(20, $limit));

    try {
        $collection = getenv('QDRANT_COLLECTION') ?: 'bmos_chunks';
        $q = new Qdrant();
        $vector = DemoEmbeddings::generate($query, DemoEmbeddings::dimension());
        $hits = $q->search($collection, $vector, $tenantId, $limit);
        echo json_encode([
            'query' => $query,
            'tenant_id' => $tenantId,
            'results' => array_map(static fn(array $h): array => [
                'chunk_id' => $h['payload']['chunk_id'] ?? $h['id'],
                'document_id' => $h['payload']['document_id'] ?? null,
                'score' => $h['score'],
                'content' => $h['payload']['content'] ?? null,
            ], $hits),
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'search failed']);
    }
    return;
}

// Phase 7: object storage endpoints. Flow:
//   POST /objects              → register metadata (pending), get PUT URL
//   PUT <presigned url>         → client uploads bytes directly to MinIO
//   POST /objects/{id}/complete → verify via HEAD, mark uploaded
//   GET  /objects/{id}/download → tenant-checked, get GET URL
//   GET  /objects               → list this tenant's objects
//   DELETE /objects/{id}        → delete bytes + mark deleted
// PHP never proxies bytes; MinIO creds are the non-root app identity.

if ($path === '/objects' && $method === 'POST') {
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
        http_response_code(400);
        echo json_encode(['error' => 'X-Tenant-ID header must be a UUID (demo auth stand-in, not real auth)']);
        return;
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    $mediaType = is_string($body['media_type'] ?? null) && $body['media_type'] !== ''
        ? $body['media_type'] : 'application/octet-stream';
    $filename = is_string($body['filename'] ?? null) && $body['filename'] !== ''
        ? preg_replace('/[^a-zA-Z0-9._-]/', '_', $body['filename']) : 'upload.bin';

    try {
        $pdo = Db::connect();
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
        $exists = $pdo->query("SELECT 1 FROM app.tenants WHERE id = '{$tenantId}'")->fetchColumn();
        if (!$exists) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'tenant not found']);
            return;
        }
        $bucket = getenv('S3_BUCKET') ?: 'bmos-objects';
        // Object key: tenant-scoped prefix + uuid + safe filename. The key is
        // NOT the database ID (guide: object keys versus database IDs).
        $objectId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%08x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffffffff));
        $objectKey = "t/{$tenantId}/{$objectId}-{$filename}";
        $stmt = $pdo->prepare(
            'INSERT INTO app.objects (tenant_id, bucket, object_key, media_type, lifecycle_state)
             VALUES (:t, :b, :k, :m, \'pending\') RETURNING id'
        );
        $stmt->execute([':t' => $tenantId, ':b' => $bucket, ':k' => $objectKey, ':m' => $mediaType]);
        $id = $stmt->fetchColumn();
        $pdo->commit();

        $s3 = new S3Client();
        $uploadUrl = $s3->presignPut($bucket, $objectKey, 900);
        http_response_code(201);
        echo json_encode([
            'id' => $id,
            'bucket' => $bucket,
            'object_key' => $objectKey,
            'upload_url' => $uploadUrl,
            'expires_in' => 900,
        ]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'object register failed']);
    }
    return;
}

if (preg_match('#^/objects/([0-9a-f-]{36})/complete$#i', $path, $m) && $method === 'POST') {
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    $objectId = $m[1];
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
        http_response_code(400);
        echo json_encode(['error' => 'X-Tenant-ID header must be a UUID']);
        return;
    }
    try {
        $pdo = Db::connect();
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
        // RLS: can only see own tenant's object. FOR UPDATE locks the row.
        $stmt = $pdo->prepare(
            "SELECT id, bucket, object_key, lifecycle_state FROM app.objects
             WHERE id = :id FOR UPDATE"
        );
        $stmt->execute([':id' => $objectId]);
        $obj = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$obj) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'object not found']);
            return;
        }
        // Verify the bytes actually landed via HEAD (presigned URL).
        $s3 = new S3Client();
        $head = $s3->headObjectViaPresigned($obj['bucket'], $obj['object_key']);
        if ($head === null) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'bytes not found in storage; upload first']);
            return;
        }
        $stmt = $pdo->prepare(
            "UPDATE app.objects SET lifecycle_state = 'uploaded', size = :s,
             updated_at = now() WHERE id = :id"
        );
        $stmt->execute([':s' => $head['size'], ':id' => $objectId]);
        $pdo->commit();
        echo json_encode(['id' => $objectId, 'lifecycle_state' => 'uploaded', 'size' => $head['size']]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'complete failed']);
    }
    return;
}

if (preg_match('#^/objects/([0-9a-f-]{36})/download$#i', $path, $m) && $method === 'GET') {
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    $objectId = $m[1];
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
        http_response_code(400);
        echo json_encode(['error' => 'X-Tenant-ID header must be a UUID']);
        return;
    }
    try {
        $pdo = Db::connect();
        $pdo->exec("SET app.current_tenant = '{$tenantId}'");
        // RLS: tenant A cannot see tenant B's rows → 404, not 403 (no oracle).
        $stmt = $pdo->prepare(
            "SELECT id, bucket, object_key, media_type, size, lifecycle_state
             FROM app.objects WHERE id = :id"
        );
        $stmt->execute([':id' => $objectId]);
        $obj = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$obj || $obj['lifecycle_state'] !== 'uploaded') {
            http_response_code(404);
            echo json_encode(['error' => 'object not found']);
            return;
        }
        $s3 = new S3Client();
        $url = $s3->presignGet($obj['bucket'], $obj['object_key'], 900);
        echo json_encode([
            'id' => $obj['id'],
            'media_type' => $obj['media_type'],
            'size' => (int) $obj['size'],
            'download_url' => $url,
            'expires_in' => 900,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'download failed']);
    }
    return;
}

if ($path === '/objects' && $method === 'GET') {
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
        http_response_code(400);
        echo json_encode(['error' => 'X-Tenant-ID header must be a UUID']);
        return;
    }
    try {
        $pdo = Db::connect();
        $pdo->exec("SET app.current_tenant = '{$tenantId}'");
        $rows = $pdo->query(
            "SELECT id, bucket, object_key, media_type, size, lifecycle_state, created_at
             FROM app.objects WHERE lifecycle_state <> 'deleted' ORDER BY created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['objects' => $rows]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'list failed']);
    }
    return;
}

if (preg_match('#^/objects/([0-9a-f-]{36})$#i', $path, $m) && $method === 'DELETE') {
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    $objectId = $m[1];
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
        http_response_code(400);
        echo json_encode(['error' => 'X-Tenant-ID header must be a UUID']);
        return;
    }
    try {
        $pdo = Db::connect();
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
        $stmt = $pdo->prepare(
            "SELECT id, bucket, object_key FROM app.objects WHERE id = :id FOR UPDATE"
        );
        $stmt->execute([':id' => $objectId]);
        $obj = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$obj) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'object not found']);
            return;
        }
        (new S3Client())->deleteObject($obj['bucket'], $obj['object_key']);
        $pdo->prepare("UPDATE app.objects SET lifecycle_state = 'deleted', updated_at = now() WHERE id = :id")
            ->execute([':id' => $objectId]);
        $pdo->commit();
        echo json_encode(['id' => $objectId, 'lifecycle_state' => 'deleted']);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'delete failed']);
    }
    return;
}

if ($path === '/ready') {
    try {
        $pdo = Db::connect();
        $role = $pdo->query('SELECT current_user')->fetchColumn();
        $one = $pdo->query('SELECT 1')->fetchColumn();
        if ($one != 1) {
            throw new RuntimeException('db sanity query failed');
        }
        echo json_encode([
            'status' => 'ok',
            'service' => 'bmos',
            'version' => $version,
            'checks' => ['database' => 'ok', 'db_role' => $role],
        ]);
    } catch (Throwable $e) {
        http_response_code(503);
        echo json_encode([
            'status' => 'degraded',
            'service' => 'bmos',
            'version' => $version,
            'checks' => ['database' => 'unreachable'],
        ]);
    }
    return;
}

// Phase 8: Lemonade (Windows-hosted) endpoints.
// Lemonade is inference-only: no DB credentials, no tenant data leaves PHP
// except the prompt itself. Tenant scoping stays in PHP (demo: X-Tenant-ID
// header validated as UUID, same stand-in as other endpoints).

// GET /ai/health → is Lemonade reachable? lists models (no auth needed).
if ($path === '/ai/health' && $method === 'GET') {
    try {
        $lem = new Lemonade();
        $models = $lem->models();
        $ids = [];
        foreach (($models['data'] ?? []) as $m) {
            if (isset($m['id'])) {
                $ids[] = $m['id'];
            }
        }
        echo json_encode([
            'status' => 'ok',
            'lemonade_url' => $lem->baseUrl(),
            'models' => $ids,
        ]);
    } catch (Throwable $e) {
        http_response_code(503);
        echo json_encode(['status' => 'degraded', 'error' => 'lemonade unreachable']);
    }
    return;
}

// POST /ai/chat → {messages:[{role,content},...]} → {content}
// Tenant header is validated (demo auth stand-in); the tenant id is NOT
// sent to Lemonade — it only scopes which PHP caller may use the endpoint.
if ($path === '/ai/chat' && $method === 'POST') {
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
        http_response_code(400);
        echo json_encode(['error' => 'X-Tenant-ID header must be a UUID (demo auth stand-in, not real auth)']);
        return;
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
    // Minimal shape validation: non-empty, each has role+content strings.
    $valid = count($messages) > 0 && count($messages) <= 20;
    foreach ($messages as $m) {
        if (!is_array($m) || !is_string($m['role'] ?? null) || !is_string($m['content'] ?? null)) {
            $valid = false;
            break;
        }
    }
    if (!$valid) {
        http_response_code(400);
        echo json_encode(['error' => 'body.messages must be 1-20 {role, content} objects']);
        return;
    }
    try {
        // Optional: verify the tenant exists (RLS-scoped, like other endpoints).
        $pdo = Db::connect();
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
        $exists = $pdo->query("SELECT 1 FROM app.tenants WHERE id = '{$tenantId}'")->fetchColumn();
        $pdo->commit();
        if (!$exists) {
            http_response_code(404);
            echo json_encode(['error' => 'tenant not found']);
            return;
        }
        $lem = new Lemonade();
        $content = $lem->chat($messages);
        echo json_encode(['content' => $content, 'model' => getenv('LEMONADE_MODEL') ?: 'Qwen3.5-2B-GGUF']);
    } catch (Throwable $e) {
        http_response_code(503);
        echo json_encode(['error' => 'ai chat failed']);
    }
    return;
}

// Phase 12: Dashboard UI (Preline-inspired shell).
// GET / → redirect to /dashboard.
if ($path === '/' && $method === 'GET') {
    header('Location: /dashboard', true, 302);
    return;
}

// GET /dashboard → full page shell.
if ($path === '/dashboard' && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $title = 'Dashboard';
    $active = 'dashboard';
    ob_start();
    require __DIR__ . '/../views/dashboard.php';
    $content = ob_get_clean();
    require __DIR__ . '/../views/layout.php';
    return;
}

// GET /dashboard/stats → HTMX partial: stat cards.
if ($path === '/dashboard/stats' && $method === 'GET') {
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    $stats = ['tenants' => 0, 'tasks' => 0, 'documents' => 0, 'objects' => 0];
    if (preg_match('/^[0-9a-f-]{36}$/i', $tenantId)) {
        try {
            $pdo = Db::connect();
            $pdo->beginTransaction();
            $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
            $stats['tenants'] = (int) $pdo->query('SELECT COUNT(*) FROM app.tenants')->fetchColumn();
            $stats['tasks'] = (int) $pdo->query("SELECT COUNT(*) FROM app.tasks")->fetchColumn();
            $stats['documents'] = (int) $pdo->query('SELECT COUNT(*) FROM app.documents')->fetchColumn();
            $stats['objects'] = (int) $pdo->query("SELECT COUNT(*) FROM app.objects WHERE lifecycle_state <> 'deleted'")->fetchColumn();
            $pdo->commit();
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        }
    }
    $cards = [
        ['Tenants', $stats['tenants'], 'bg-blue-50 text-blue-700'],
        ['Open tasks', $stats['tasks'], 'bg-amber-50 text-amber-700'],
        ['Documents', $stats['documents'], 'bg-emerald-50 text-emerald-700'],
        ['Files', $stats['objects'], 'bg-violet-50 text-violet-700'],
    ];
    foreach ($cards as [$label, $value, $cls]) {
        echo '<div class="bg-white rounded-xl border border-gray-200 p-5">';
        echo '<div class="text-sm text-gray-500 mb-1">' . htmlspecialchars($label) . '</div>';
        echo '<div class="text-3xl font-bold ' . $cls . ' rounded-lg px-3 py-1 inline-block">' . $value . '</div>';
        echo '</div>';
    }
    return;
}

// GET /dashboard/recent-tasks → HTMX partial.
if ($path === '/dashboard/recent-tasks' && $method === 'GET') {
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    if (!preg_match('/^[0-9a-f-]{36}$/i', $tenantId)) {
        echo '<div class="text-sm text-gray-500">Set a tenant UUID above.</div>';
        return;
    }
    try {
        $pdo = Db::connect();
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
        $rows = $pdo->query(
            "SELECT id, title, status FROM app.tasks ORDER BY id DESC LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);
        $pdo->commit();
        if (!$rows) {
            echo '<div class="text-sm text-gray-500">No tasks yet.</div>';
            return;
        }
        echo '<ul class="space-y-2">';
        foreach ($rows as $r) {
            $badge = $r['status'] === 'done'
                ? 'bg-emerald-100 text-emerald-800'
                : 'bg-amber-100 text-amber-800';
            echo '<li class="flex items-center gap-2 text-sm">';
            echo '<span class="px-2 py-0.5 rounded text-xs ' . $badge . '">' . htmlspecialchars($r['status']) . '</span>';
            echo '<span class="truncate">' . htmlspecialchars($r['title']) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        echo '<div class="text-sm text-red-600">Could not load tasks.</div>';
    }
    return;
}

// GET /dashboard/system → HTMX partial: service status.
if ($path === '/dashboard/system' && $method === 'GET') {
    $services = [];
    // PostgreSQL.
    try { Db::connect()->query('SELECT 1'); $services['PostgreSQL'] = true; }
    catch (Throwable $e) { $services['PostgreSQL'] = false; }
    // Qdrant.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://qdrant:6333/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    $services['Qdrant'] = ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
    curl_close($ch);
    // Garage.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://garage:3900/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $services['Garage'] = ($code === 403 || $code === 400 || $code === 200);
    // Lemonade.
    try { (new Lemonade())->models(); $services['Lemonade'] = true; }
    catch (Throwable $e) { $services['Lemonade'] = false; }

    echo '<ul class="space-y-2 text-sm">';
    foreach ($services as $name => $ok) {
        $dot = $ok ? 'bg-emerald-500' : 'bg-red-500';
        echo '<li class="flex items-center gap-2">';
        echo '<span class="w-2.5 h-2.5 rounded-full ' . $dot . '"></span>';
        echo htmlspecialchars($name) . ' <span class="text-gray-500">· ' . ($ok ? 'ok' : 'down') . '</span>';
        echo '</li>';
    }
    echo '</ul>';
    return;
}

// Phase 12b: Tasks UI.
// GET /tasks → full page.
if ($path === '/tasks' && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $title = 'Tasks';
    $active = 'tasks';
    ob_start();
    require __DIR__ . '/../views/tasks.php';
    $content = ob_get_clean();
    require __DIR__ . '/../views/layout.php';
    return;
}

// GET /tasks/list → HTMX partial (tenant-scoped).
if ($path === '/tasks/list' && $method === 'GET') {
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    if (!preg_match('/^[0-9a-f-]{36}$/i', $tenantId)) {
        echo '<div class="text-sm text-gray-500">Set a tenant UUID above.</div>';
        return;
    }
    try {
        $pdo = Db::connect();
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
        $rows = $pdo->query(
            "SELECT id, title, status FROM app.tasks ORDER BY id DESC LIMIT 50"
        )->fetchAll(PDO::FETCH_ASSOC);
        $pdo->commit();
        if (!$rows) {
            echo '<div class="text-sm text-gray-500">No tasks yet. Add one above.</div>';
            return;
        }
        echo '<ul class="space-y-2" id="task-items">';
        foreach ($rows as $r) {
            $done = $r['status'] === 'done';
            $badge = $done ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800';
            echo '<li class="flex items-center gap-3 text-sm border-b border-gray-100 pb-2" data-status="' . $r['status'] . '">';
            echo '<button hx-post="/tasks/' . htmlspecialchars($r['id']) . '/toggle" hx-target="#task-list" hx-swap="innerHTML"'
                . ' class="w-5 h-5 rounded border ' . ($done ? 'bg-emerald-500 border-emerald-500 text-white' : 'border-gray-300') . ' flex items-center justify-center text-xs">'
                . ($done ? '✓' : '') . '</button>';
            echo '<span class="' . ($done ? 'line-through text-gray-400' : '') . ' flex-1">' . htmlspecialchars($r['title']) . '</span>';
            echo '<span class="px-2 py-0.5 rounded text-xs ' . $badge . '">' . htmlspecialchars($r['status']) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        echo '<div class="text-sm text-red-600">Could not load tasks.</div>';
    }
    return;
}

// POST /tasks/create → create task, return refreshed list partial.
if ($path === '/tasks/create' && $method === 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    $title = trim($_POST['title'] ?? '');
    if (!preg_match('/^[0-9a-f-]{36}$/i', $tenantId) || $title === '') {
        http_response_code(400);
        echo '<div class="text-sm text-red-600">Title and tenant required.</div>';
        return;
    }
    try {
        $pdo = Db::connect();
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
        $stmt = $pdo->prepare("INSERT INTO app.tasks (tenant_id, title, status) VALUES (:t, :title, 'open')");
        $stmt->execute([':t' => $tenantId, ':title' => mb_substr($title, 0, 200)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        http_response_code(500);
        echo '<div class="text-sm text-red-600">Could not create task.</div>';
        return;
    }
    // Return the refreshed list by re-dispatching internally.
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $path = '/tasks/list';
    // Fall through to the list handler via a simple include trick:
    // (we just re-run the list logic inline)
    try {
        $pdo = Db::connect();
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
        $rows = $pdo->query("SELECT id, title, status FROM app.tasks ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $pdo->commit();
        echo '<ul class="space-y-2" id="task-items">';
        foreach ($rows as $r) {
            $done = $r['status'] === 'done';
            $badge = $done ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800';
            echo '<li class="flex items-center gap-3 text-sm border-b border-gray-100 pb-2">';
            echo '<button hx-post="/tasks/' . htmlspecialchars($r['id']) . '/toggle" hx-target="#task-list" hx-swap="innerHTML"'
                . ' class="w-5 h-5 rounded border ' . ($done ? 'bg-emerald-500 border-emerald-500 text-white' : 'border-gray-300') . ' flex items-center justify-center text-xs">'
                . ($done ? '✓' : '') . '</button>';
            echo '<span class="' . ($done ? 'line-through text-gray-400' : '') . ' flex-1">' . htmlspecialchars($r['title']) . '</span>';
            echo '<span class="px-2 py-0.5 rounded text-xs ' . $badge . '">' . htmlspecialchars($r['status']) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        echo '<div class="text-sm text-red-600">Could not load tasks.</div>';
    }
    return;
}

// POST /tasks/{id}/toggle → flip open/done, return refreshed list.
if (preg_match('#^/tasks/([0-9a-f-]{36})/toggle$#i', $path, $m) && $method === 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    $taskId = $m[1];
    if (!preg_match('/^[0-9a-f-]{36}$/i', $tenantId)) {
        http_response_code(400);
        echo '<div class="text-sm text-red-600">Tenant required.</div>';
        return;
    }
    try {
        $pdo = Db::connect();
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
        $stmt = $pdo->prepare("SELECT status FROM app.tasks WHERE id = :id");
        $stmt->execute([':id' => $taskId]);
        $cur = $stmt->fetchColumn();
        if ($cur === false) {
            $pdo->rollBack();
            http_response_code(404);
            echo '<div class="text-sm text-red-600">Task not found.</div>';
            return;
        }
        $next = ($cur === 'done') ? 'open' : 'done';
        $stmt = $pdo->prepare("UPDATE app.tasks SET status = :s WHERE id = :id");
        $stmt->execute([':s' => $next, ':id' => $taskId]);
        $rows = $pdo->query("SELECT id, title, status FROM app.tasks ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $pdo->commit();
        echo '<ul class="space-y-2" id="task-items">';
        foreach ($rows as $r) {
            $done = $r['status'] === 'done';
            $badge = $done ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800';
            echo '<li class="flex items-center gap-3 text-sm border-b border-gray-100 pb-2">';
            echo '<button hx-post="/tasks/' . htmlspecialchars($r['id']) . '/toggle" hx-target="#task-list" hx-swap="innerHTML"'
                . ' class="w-5 h-5 rounded border ' . ($done ? 'bg-emerald-500 border-emerald-500 text-white' : 'border-gray-300') . ' flex items-center justify-center text-xs">'
                . ($done ? '✓' : '') . '</button>';
            echo '<span class="' . ($done ? 'line-through text-gray-400' : '') . ' flex-1">' . htmlspecialchars($r['title']) . '</span>';
            echo '<span class="px-2 py-0.5 rounded text-xs ' . $badge . '">' . htmlspecialchars($r['status']) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        http_response_code(500);
        echo '<div class="text-sm text-red-600">Could not update task.</div>';
    }
    return;
}

// Phase 12c: AI Chat UI.
if ($path === '/ai-ui' && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $title = 'AI Chat';
    $active = 'ai';
    ob_start();
    require __DIR__ . '/../views/ai-chat.php';
    $content = ob_get_clean();
    require __DIR__ . '/../views/layout.php';
    return;
}

// Phase 12d: Documents UI.
if ($path === '/documents' && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $title = 'Documents';
    $active = 'documents';
    ob_start();
    require __DIR__ . '/../views/documents.php';
    $content = ob_get_clean();
    require __DIR__ . '/../views/layout.php';
    return;
}
if ($path === '/documents/list' && $method === 'GET') {
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    if (!preg_match('/^[0-9a-f-]{36}$/i', $tenantId)) {
        echo '<div class="text-sm text-gray-500">Set a tenant UUID above.</div>';
        return;
    }
    try {
        $pdo = Db::connect();
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
        $rows = $pdo->query(
            "SELECT id, title, source_version FROM app.documents ORDER BY id DESC LIMIT 30"
        )->fetchAll(PDO::FETCH_ASSOC);
        $pdo->commit();
        if (!$rows) { echo '<div class="text-sm text-gray-500">No documents yet.</div>'; return; }
        echo '<ul class="space-y-2 text-sm">';
        foreach ($rows as $r) {
            echo '<li class="border-b border-gray-100 pb-2">';
            echo '<div class="font-medium">' . htmlspecialchars($r['title']) . '</div>';
            echo '<div class="text-xs text-gray-500 font-mono">' . htmlspecialchars(substr($r['id'], 0, 8)) . '… · v' . htmlspecialchars($r['source_version'] ?? '1') . '</div>';
            echo '</li>';
        }
        echo '</ul>';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        echo '<div class="text-sm text-red-600">Could not load documents.</div>';
    }
    return;
}
if ($path === '/documents/search' && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    $q = trim($_GET['q'] ?? '');
    if (!preg_match('/^[0-9a-f-]{36}$/i', $tenantId) || $q === '') {
        echo '<div class="text-sm text-gray-500">Enter a query and set a tenant.</div>';
        return;
    }
    // Proxy to the existing /search API internally (same tenant scoping).
    // For the UI we do a simple ILIKE on chunks; vector search stays in /search.
    try {
        $pdo = Db::connect();
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
        $stmt = $pdo->prepare(
            "SELECT d.title, c.content FROM app.document_chunks c
             JOIN app.documents d ON d.id = c.document_id
             WHERE c.content ILIKE :q LIMIT 10"
        );
        $stmt->execute([':q' => '%' . $q . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pdo->commit();
        if (!$rows) { echo '<div class="text-sm text-gray-500">No matches.</div>'; return; }
        echo '<ul class="space-y-2 text-sm">';
        foreach ($rows as $r) {
            echo '<li class="border-b border-gray-100 pb-2">';
            echo '<div class="font-medium">' . htmlspecialchars($r['title']) . '</div>';
            echo '<div class="text-gray-600">' . htmlspecialchars(mb_substr($r['content'], 0, 160)) . '…</div>';
            echo '</li>';
        }
        echo '</ul>';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        echo '<div class="text-sm text-red-600">Search failed.</div>';
    }
    return;
}

// Phase 12e: Files UI.
if ($path === '/objects-ui' && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $title = 'Files';
    $active = 'objects';
    ob_start();
    require __DIR__ . '/../views/files.php';
    $content = ob_get_clean();
    require __DIR__ . '/../views/layout.php';
    return;
}
if ($path === '/objects-ui/list' && $method === 'GET') {
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    if (!preg_match('/^[0-9a-f-]{36}$/i', $tenantId)) {
        echo '<div class="text-sm text-gray-500">Set a tenant UUID above.</div>';
        return;
    }
    try {
        $pdo = Db::connect();
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
        $rows = $pdo->query(
            "SELECT id, object_key, size, lifecycle_state, created_at FROM app.objects
             WHERE lifecycle_state <> 'deleted' ORDER BY created_at DESC LIMIT 30"
        )->fetchAll(PDO::FETCH_ASSOC);
        $pdo->commit();
        if (!$rows) { echo '<div class="text-sm text-gray-500">No files yet.</div>'; return; }
        echo '<ul class="space-y-2 text-sm">';
        foreach ($rows as $r) {
            $name = basename($r['object_key']);
            $size = $r['size'] ? number_format((int) $r['size']) . ' B' : '—';
            $badge = $r['lifecycle_state'] === 'uploaded'
                ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800';
            echo '<li class="flex items-center gap-3 border-b border-gray-100 pb-2">';
            echo '<span class="flex-1 truncate font-medium">' . htmlspecialchars($name) . '</span>';
            echo '<span class="text-gray-500 text-xs">' . $size . '</span>';
            echo '<span class="px-2 py-0.5 rounded text-xs ' . $badge . '">' . htmlspecialchars($r['lifecycle_state']) . '</span>';
            echo '<button class="text-blue-600 text-xs hover:underline" '
                . 'onclick="downloadFile(\'' . htmlspecialchars($r['id']) . '\')">Download</button>';
            echo '</li>';
        }
        echo '</ul>';
        echo '<script>
        function downloadFile(id) {
          const tenant = document.getElementById("tenant-input").value;
          fetch("/objects/" + id + "/download", { headers: { "X-Tenant-ID": tenant } })
            .then(r => r.json()).then(d => { if (d.download_url) window.open(d.download_url, "_blank"); });
        }
        </script>';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        echo '<div class="text-sm text-red-600">Could not load files.</div>';
    }
    return;
}

// Phase 12f: Workflows UI (n8n integration dashboard).
if ($path === '/workflows' && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $title = 'Workflows';
    $active = 'workflows';
    ob_start();
    require __DIR__ . '/../views/workflows.php';
    $content = ob_get_clean();
    require __DIR__ . '/../views/layout.php';
    return;
}
if ($path === '/workflows/status' && $method === 'GET') {
    $out = [];
    // n8n healthz via backend network (php → n8n:5678). No docker socket needed.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://n8n:5678/healthz');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);
    // Connection refused = not running; 200 = healthy.
    $running = ($err === 0);
    $healthy = ($code === 200);
    $out[] = ['n8n container', $running];
    $out[] = ['n8n API (healthz)', $healthy];
    // Queue mode?
    $queue = getenv('N8N_EXECUTIONS_MODE') === 'queue';
    echo '<ul class="space-y-2 text-sm">';
    foreach ($out as [$name, $ok]) {
        $dot = $ok ? 'bg-emerald-500' : 'bg-red-500';
        echo '<li class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full ' . $dot . '"></span>'
            . htmlspecialchars($name) . ' <span class="text-gray-500">· ' . ($ok ? 'ok' : 'down') . '</span></li>';
    }
    echo '<li class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-gray-300"></span>'
        . 'Queue mode <span class="text-gray-500">· ' . ($queue ? 'enabled' : 'regular mode') . '</span></li>';
    if (!$running) {
        echo '<li class="text-xs text-gray-500 mt-2">Start n8n: <span class="font-mono">docker compose --profile n8n up -d</span></li>';
    }
    echo '</ul>';
    return;
}
if ($path === '/workflows/test-job' && $method === 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    $tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
    if (!preg_match('/^[0-9a-f-]{36}$/i', $tenantId)) {
        echo '<span class="text-red-600">Set a tenant UUID in the header input.</span>';
        return;
    }
    // Enqueue via the BMOS API (same call n8n would make).
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://nginx/jobs/enqueue');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'job_type' => 'task.created',
        'title' => 'n8n test job ' . date('H:i:s'),
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "X-Tenant-ID: {$tenantId}",
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 201 || $code === 200) {
        $d = json_decode($body, true);
        echo '<span class="text-emerald-700">✓ Job enqueued: <span class="font-mono">' . htmlspecialchars($d['id'] ?? '?') . '</span>'
            . (isset($d['deduplicated']) && $d['deduplicated'] ? ' (deduplicated)' : '') . '</span>';
    } else {
        echo '<span class="text-red-600">Failed (HTTP ' . $code . '). Is n8n running? This test uses the BMOS API directly.</span>';
    }
    return;
}

// Phase 12g: Tenants UI + System Health UI.
if ($path === '/tenants' && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $title = 'Tenants';
    $active = 'tenants';
    ob_start();
    require __DIR__ . '/../views/tenants.php';
    $content = ob_get_clean();
    require __DIR__ . '/../views/layout.php';
    return;
}
if ($path === '/tenants/list' && $method === 'GET') {
    try {
        $pdo = Db::connect();
        // No tenant scoping for the list itself (admin view); RLS still
        // applies per the app role, so this shows what bmos_app can see.
        $rows = $pdo->query("SELECT id, name FROM app.tenants ORDER BY name LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { echo '<div class="text-sm text-gray-500">No tenants.</div>'; return; }
        echo '<ul class="space-y-2 text-sm">';
        foreach ($rows as $r) {
            echo '<li class="flex items-center gap-3 border-b border-gray-100 pb-2">';
            echo '<span class="font-medium">' . htmlspecialchars($r['name']) . '</span>';
            echo '<span class="font-mono text-xs text-gray-500">' . htmlspecialchars($r['id']) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    } catch (Throwable $e) {
        echo '<div class="text-sm text-red-600">Could not load tenants.</div>';
    }
    return;
}
if ($path === '/health-ui' && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $title = 'System Health';
    $active = 'health';
    ob_start();
    require __DIR__ . '/../views/health.php';
    $content = ob_get_clean();
    require __DIR__ . '/../views/layout.php';
    return;
}
if ($path === '/health-ui/services' && $method === 'GET') {
    $checks = [];
    // PostgreSQL via /ready logic.
    try { Db::connect()->query('SELECT 1'); $checks['PostgreSQL'] = true; }
    catch (Throwable $e) { $checks['PostgreSQL'] = false; }
    // Qdrant: GET / returns 200 with version.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://qdrant:6333/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    $checks['Qdrant'] = ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
    curl_close($ch);
    // Garage: S3 API responds (403 on / is fine — it means it's up).
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://garage:3900/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $checks['Garage'] = ($code === 403 || $code === 400 || $code === 200);
    // Lemonade.
    try { (new Lemonade())->models(); $checks['Lemonade'] = true; }
    catch (Throwable $e) { $checks['Lemonade'] = false; }
    // n8n (if running): healthz.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://n8n:5678/healthz');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $body = (string) curl_exec($ch);
    $checks['n8n'] = ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
    curl_close($ch);

    echo '<ul class="space-y-2 text-sm">';
    foreach ($checks as $name => $ok) {
        $dot = $ok ? 'bg-emerald-500' : 'bg-red-500';
        echo '<li class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full ' . $dot . '"></span>'
            . htmlspecialchars($name) . ' <span class="text-gray-500">· ' . ($ok ? 'ok' : 'down') . '</span></li>';
    }
    echo '</ul>';
    return;
}
if ($path === '/health-ui/database' && $method === 'GET') {
    try {
        $pdo = Db::connect();
        $tables = ['tenants', 'users', 'memberships', 'tasks', 'outbox_jobs', 'documents', 'document_chunks', 'objects'];
        echo '<ul class="space-y-1 text-sm font-mono">';
        foreach ($tables as $t) {
            $n = (int) $pdo->query("SELECT COUNT(*) FROM app.{$t}")->fetchColumn();
            echo '<li class="flex justify-between"><span>app.' . $t . '</span><span class="text-gray-600">' . $n . ' rows</span></li>';
        }
        echo '</ul>';
    } catch (Throwable $e) {
        echo '<div class="text-sm text-red-600">DB unreachable.</div>';
    }
    return;
}

// /health and any other path: liveness only, no DB dependency.
echo json_encode([
    'status' => 'ok',
    'service' => 'bmos',
    'version' => $version,
    'request_id' => bin2hex(random_bytes(8)),
]);
