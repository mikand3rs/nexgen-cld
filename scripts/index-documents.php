#!/usr/bin/env php
<?php
// BMOS 3.2 Phase 6 — index document chunks from PostgreSQL into Qdrant.
// PostgreSQL (app.document_chunks) is authoritative; Qdrant is the derived
// index. Re-running is safe: points are upserted by chunk ID.
//
// Runs as bmos_app (the runtime role), NOT as a superuser. The tenant comes
// from --tenant and is set as the RLS context, so the indexer only ever
// sees that tenant's chunks — exactly like a web request. Cross-tenant
// bulk indexing loops over tenants (see rebuild-qdrant.php).
//
// Usage: php scripts/index-documents.php --tenant <uuid>
// (Runs inside the php container: docker compose exec -T php php ...)

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Qdrant.php';
require_once __DIR__ . '/../src/Embeddings.php';

$opts = getopt('', ['tenant:']);
$tenant = $opts['tenant'] ?? null;
if (!$tenant || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenant)) {
    fwrite(STDERR, "Usage: index-documents.php --tenant <uuid>\n");
    exit(2);
}

$collection = getenv('QDRANT_COLLECTION') ?: 'bmos_chunks';
$dim = DemoEmbeddings::dimension();
$q = new Qdrant();
if (!$q->collectionExists($collection)) {
    fwrite(STDERR, "Collection '{$collection}' does not exist. Run scripts/qdrant-init.php first.\n");
    exit(1);
}

// bmos_app with the tenant's RLS context: the SELECT below can only return
// this tenant's chunks. The tenant_id is copied verbatim into each point's
// payload — that payload field is what the search-time tenant filter matches.
$pdo = Db::connect();
$pdo->exec("SET app.current_tenant = '{$tenant}'");

$stmt = $pdo->prepare(
    'SELECT id, document_id, tenant_id, content, source_version
     FROM app.document_chunks ORDER BY created_at'
);
$stmt->execute();

$points = [];
$n = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $points[] = [
        'id' => $row['id'],
        'vector' => DemoEmbeddings::generate((string) $row['content'], $dim),
        'payload' => [
            'tenant_id' => $row['tenant_id'],
            'document_id' => $row['document_id'],
            'chunk_id' => $row['id'],
            'source_version' => (int) $row['source_version'],
            // Content duplicated into the payload for demo convenience.
            // Authoritative text stays in PostgreSQL.
            'content' => $row['content'],
        ],
    ];
    $n++;
    // Batch upserts to keep request sizes sane.
    if (count($points) >= 100) {
        $q->upsertPoints($collection, $points);
        $points = [];
    }
}
$q->upsertPoints($collection, $points);

echo "Indexed {$n} chunks for tenant {$tenant}.\n";
