#!/usr/bin/env php
<?php
// BMOS 3.2 Phase 6 — create the Qdrant collection (idempotent).
// Collection name and vector size come from the environment, never hardcoded:
//   QDRANT_COLLECTION (default: bmos_chunks)
//   QDRANT_VECTOR_SIZE (default: 384) — MUST match the embedding model output.
//   QDRANT_URL (default: http://qdrant:6333)
//
// Usage: php scripts/qdrant-init.php
// (Runs inside the php container: docker compose exec -T php php scripts/qdrant-init.php)

require_once __DIR__ . '/../src/Qdrant.php';
require_once __DIR__ . '/../src/Embeddings.php';

$collection = getenv('QDRANT_COLLECTION') ?: 'bmos_chunks';
$dim = DemoEmbeddings::dimension();

$q = new Qdrant();
if ($q->collectionExists($collection)) {
    echo "EXISTS {$collection} (dim check skipped — recreate to change dimension)\n";
    exit(0);
}

$q->createCollection($collection, $dim);
$q->createTenantIndex($collection);
echo "CREATED {$collection} (vector size {$dim}, Cosine, tenant_id keyword index)\n";
