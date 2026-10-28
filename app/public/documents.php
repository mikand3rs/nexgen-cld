<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NexGen CLD — Documents list (V2.1 status view)
|--------------------------------------------------------------------------
|
| GET /documents.php?tenant_slug=nexgen-cloud
|
| Shows every document for one tenant, with its ingestion status
| and error (if any). This is the operator view for "is my upload
| indexed yet, and if not, why not?" — failures are visible here,
| not silent. No file content is served from this page, and no
| uploaded file is ever linked from the web root.
*/

require_once __DIR__ . '/../lib/db.php';

$tenantSlug = trim((string) ($_GET['tenant_slug'] ?? 'nexgen-cloud'));
$uploaded = (string) ($_GET['uploaded'] ?? '');

try {
    $pdo = db();
    $tenantId = tenant_id_by_slug($pdo, $tenantSlug);
    $stmt = $pdo->prepare(
        'SELECT d.*, j.status AS job_status, j.attempts, j.error AS job_error
         FROM documents d
         LEFT JOIN ingestion_jobs j ON j.document_id = d.id
         WHERE d.tenant_id = ?
         ORDER BY d.created_at DESC, d.id DESC'
    );
    $stmt->execute([$tenantId]);
    $documents = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('NexGen CLD documents error: ' . $e->getMessage());
    http_response_code(500);
    echo '<h1>Documents unavailable</h1><p>Check the server log.</p>';
    exit;
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Documents — NexGen CLD</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; color: #17211b; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { text-align: left; padding: 10px; border-bottom: 1px solid #d5e0d7; vertical-align: top; }
        .ok { color: #286044; font-weight: 600; }
        .bad { color: #8b2424; font-weight: 600; }
        nav a { margin-right: 16px; }
    </style>
</head>
<body>
<nav>
    <a href="/">Chat</a>
    <a href="/upload.php">Upload</a>
    <a href="/documents.php">Documents</a>
    <a href="/ask.php">Ask experts</a>
</nav>
<h1>Documents — <?= htmlspecialchars($tenantSlug, ENT_QUOTES, 'UTF-8') ?></h1>
<?php if ($uploaded !== ''): ?>
    <p><strong>Upload received.</strong> The worker will ingest it shortly; refresh this page to see its status change to "indexed".</p>
<?php endif; ?>
<?php if ($documents === []): ?>
    <p>No documents yet. <a href="/upload.php">Upload one</a>.</p>
<?php else: ?>
<table>
    <tr><th>Document</th><th>Collection</th><th>Size</th><th>Status</th><th>Error / note</th></tr>
    <?php foreach ($documents as $d): ?>
    <tr>
        <td><?= htmlspecialchars($d['original_name'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($d['collection'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= number_format((int) $d['size_bytes']) ?> bytes</td>
        <td class="<?= $d['status'] === 'indexed' ? 'ok' : ($d['status'] === 'failed' ? 'bad' : '') ?>">
            <?= htmlspecialchars($d['status'], ENT_QUOTES, 'UTF-8') ?>
        </td>
        <td><?= htmlspecialchars($d['error'] ?? $d['job_error'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
<p>Worker not running? Start it with <code>docker compose up worker</code> or <code>php app/worker.php --loop</code> in the php container.</p>
</body>
</html>
