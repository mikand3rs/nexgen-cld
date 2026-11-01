#!/usr/bin/env php
<?php
// BMOS 3.2 Phase 6 — wipe Qdrant and rebuild it from PostgreSQL.
// This is the rebuildability gate: deleting Qdrant must not destroy business
// records, and the index must come back identical (same point IDs, same
// payloads) because PostgreSQL is authoritative.
//
// Tenants are passed explicitly (the re-indexer runs as bmos_app with each
// tenant's RLS context — there is no superuser read of all tenants).
//
// Usage: php scripts/rebuild-qdrant.php --tenant <uuid> [--tenant <uuid> ...]
// (Runs inside the php container.)

require_once __DIR__ . '/../src/Qdrant.php';
require_once __DIR__ . '/../src/Embeddings.php';

$opts = getopt('', ['tenant:']);
$tenants = $opts['tenant'] ?? [];
if (!is_array($tenants)) {
    $tenants = [$tenants];
}
$tenants = array_values(array_filter($tenants, static fn($t) =>
    is_string($t) && preg_match('/^[0-9a-f-]{36}$/i', $t)));
if ($tenants === []) {
    fwrite(STDERR, "Usage: rebuild-qdrant.php --tenant <uuid> [--tenant <uuid> ...]\n");
    exit(2);
}

$collection = getenv('QDRANT_COLLECTION') ?: 'bmos_chunks';
$dim = DemoEmbeddings::dimension();
$q = new Qdrant();

echo "Deleting collection '{$collection}'...\n";
$q->deleteCollection($collection);

echo "Recreating collection (vector size {$dim})...\n";
$q->createCollection($collection, $dim);
$q->createTenantIndex($collection);

foreach ($tenants as $tenant) {
    echo "Re-indexing tenant {$tenant}...\n";
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/index-documents.php')
        . ' --tenant ' . escapeshellarg($tenant);
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Re-index failed for tenant {$tenant} (exit {$code})\n");
        exit(1);
    }
}

$n = $q->count($collection);
echo "Rebuild complete. Collection '{$collection}' holds {$n} points.\n";
