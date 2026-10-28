<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NexGen CLD — Document upload (V2.1)
|--------------------------------------------------------------------------
|
| GET  /upload.php  — server-rendered upload form
| POST /upload.php  — multipart upload handler
|
| Validation order (V2.1 rule): upload error, size, extension,
| actual type (finfo), tenant, collection. Never trust the supplied
| filename: the stored name is <document_id>.<ext>, in
| app/storage/uploads/<tenant_id>/<document_id>/ — outside the
| public web root, never web-accessible, never executed.
|
| Flow: validate -> insert document (pending) -> move file ->
| insert ingestion job (pending) -> worker (app/worker.php) picks
| it up. The web request never runs ingestion itself, and never
| calls exec() to launch a worker.
|
| No authentication in v2/v3 foundation: tenant is a form field
| defaulting to 'nexgen-cloud'. For a multi-tenant deployment,
| replace the tenant field with session auth before exposing this
| beyond a trusted small team. See README, "Security notes".
*/

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/ingestion.php';

function upload_error_page(string $message): void
{
    http_response_code(400);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Upload failed — NexGen CLD</title></head><body>';
    echo '<h1>Upload failed</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="/upload.php">Try again</a> · <a href="/documents.php">Documents</a></p>';
    echo '</body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tenantSlug = trim((string) ($_POST['tenant_slug'] ?? 'nexgen-cloud'));
    $collection = trim((string) ($_POST['collection'] ?? 'general'));
    $uploadedBy = trim((string) ($_POST['uploaded_by'] ?? ''));

    if ($tenantSlug === '') {
        $tenantSlug = 'nexgen-cloud';
    }
    if (!in_array($collection, CLD_ALLOWED_COLLECTIONS, true)) {
        upload_error_page('Unknown collection. Choose general, operations, website, support, or admin.');
    }
    if (!isset($_FILES['document'])) {
        upload_error_page('No file was uploaded.');
    }

    $file = $_FILES['document'];
    $error = ingestion_validate_upload($file);
    if ($error !== '') {
        upload_error_page($error);
    }

    try {
        $pdo = db();
        $tenantId = tenant_id_by_slug($pdo, $tenantSlug);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) ?: 'application/octet-stream';

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO documents (tenant_id, collection, original_name, stored_name, mime_type, size_bytes, status, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, \'pending\', ?) RETURNING id'
        );
        // Stored name needs the id, so insert a placeholder first, then update.
        $stmt->execute([$tenantId, $collection, $file['name'], 'pending', $mime, (int) $file['size'], $uploadedBy ?: null]);
        $documentId = (int) $stmt->fetchColumn();
        $storedName = ingestion_stored_name($documentId, $ext);
        $pdo->prepare('UPDATE documents SET stored_name = ? WHERE id = ?')
            ->execute([$storedName, $documentId]);

        $dir = storage_root() . '/' . $tenantId . '/' . $documentId;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create storage folder.');
        }
        $dest = $dir . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('Could not store the uploaded file.');
        }
        chmod($dest, 0640);

        $pdo->prepare(
            "INSERT INTO ingestion_jobs (tenant_id, document_id, status) VALUES (?, ?, 'pending')"
        )->execute([$tenantId, $documentId]);
        $pdo->commit();

        header('Location: /documents.php?tenant_slug=' . urlencode($tenantSlug) . '&uploaded=' . $documentId);
        exit;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('NexGen CLD upload error: ' . $e->getMessage());
        upload_error_page('Upload failed. Please try again, or check the server log.');
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upload a document — NexGen CLD</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 40px auto; padding: 0 20px; color: #17211b; }
        label { display: block; margin: 16px 0 4px; font-weight: 600; }
        input, select { width: 100%; padding: 10px; border: 1px solid #aebfb2; }
        button { margin-top: 20px; padding: 12px 24px; border: 0; background: #286044; color: #fff; font-weight: 600; cursor: pointer; }
        .note { color: #607066; font-size: 0.9rem; }
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
<h1>Upload a document</h1>
<p class="note">
    Accepted in v2: .txt, .md, and .pdf, up to 10 MB. Files are stored
    privately (outside the web root) and ingested by the background
    worker — they are not searchable until their status is "indexed".
    Scanned-image PDFs, Word documents, and spreadsheets are out of
    scope for v2 and will be rejected or fail visibly.
</p>
<form method="post" enctype="multipart/form-data" action="/upload.php">
    <label for="document">Document</label>
    <input type="file" id="document" name="document" accept=".txt,.md,.pdf" required>

    <label for="tenant_slug">Tenant</label>
    <input type="text" id="tenant_slug" name="tenant_slug" value="nexgen-cloud" required>

    <label for="collection">Collection (which experts can retrieve this)</label>
    <select id="collection" name="collection">
        <option value="general">general — all experts</option>
        <option value="operations">operations — General Operations, Support</option>
        <option value="website">website — Website expert</option>
        <option value="support">support — Support expert</option>
        <option value="admin">admin — Finance &amp; Admin expert</option>
    </select>

    <label for="uploaded_by">Uploaded by (optional, your name)</label>
    <input type="text" id="uploaded_by" name="uploaded_by">

    <button type="submit">Upload</button>
</form>
</body>
</html>
