<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NexGen CLD — Database migration + seeds (CLI only)
|--------------------------------------------------------------------------
|
| Run inside the php container (or any machine with the DB reachable):
|
|   php app/db/migrate.php
|
| What it does, in order:
|   1. Applies app/db/schema.sql (idempotent: IF NOT EXISTS / DO blocks).
|   2. Tries CREATE EXTENSION vector. If pgvector is NOT in the
|      PostgreSQL image (plain postgres:17-alpine does not include
|      it), this step prints a warning and continues — retrieval
|      works on full-text search, and the embedding column is NOT
|      created. To enable vectors later, switch the postgres image
|      to pgvector/pgvector:pg17 (or an image with pgvector) and
|      re-run this script; it will add the column + HNSW index then.
|   3. Seeds the default tenant 'nexgen-cloud' if missing.
|   4. Seeds experts from app/db/seeds/experts.json, version 1,
|      only if that slug has no version yet. It never overwrites
|      an existing expert config — new versions are created by
|      editing the seed and running a separate versioning step
|      (see README), so a re-run cannot silently change prompts.
|
| This script never touches uploaded files, and never reads .env
| values into output. Run it, check the output, then start the worker.
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("migrate.php is CLI only.\n");
}

require_once __DIR__ . '/../lib/db.php';

$pdo = db();
$schemaPath = __DIR__ . '/schema.sql';
$seedsPath = __DIR__ . '/seeds/experts.json';

if (!is_file($schemaPath)) {
    fwrite(STDERR, "Missing schema.sql\n");
    exit(1);
}

echo "Applying schema...\n";
$pdo->exec(file_get_contents($schemaPath));
echo "Schema applied.\n";

// --- Optional pgvector -------------------------------------------------
$vectorAvailable = false;
try {
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');
    $vectorAvailable = true;
    echo "pgvector extension is available.\n";
} catch (Throwable $e) {
    echo "WARNING: pgvector is not available in this PostgreSQL image ("
        . $e->getMessage() . "). Continuing without vectors; "
        . "retrieval will use full-text search. See README for the "
        . "pgvector image switch.\n";
}

if ($vectorAvailable) {
    // Add the embedding column only when the type exists.
    $col = $pdo->query(
        "SELECT 1 FROM information_schema.columns
         WHERE table_name = 'doc_chunks' AND column_name = 'embedding'"
    )->fetch();
    if (!$col) {
        $pdo->exec('ALTER TABLE doc_chunks ADD COLUMN embedding vector(1536)');
        echo "Added doc_chunks.embedding vector(1536). NOTE: change the "
            . "dimension here to match your embedding model before "
            . "indexing with vectors; documents and questions must use "
            . "the same model and dimensions.\n";
    }
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_chunks_embedding
         ON doc_chunks USING hnsw (embedding vector_cosine_ops)'
    );
    echo "Vector index ready (hnsw, cosine).\n";
}

// --- Default tenant ----------------------------------------------------
$pdo->prepare(
    'INSERT INTO tenants (slug, name) VALUES (?, ?)
     ON CONFLICT (slug) DO NOTHING'
)->execute(['nexgen-cloud', 'NexGen Cloud']);
echo "Tenant 'nexgen-cloud' ready.\n";

// --- Expert seeds ------------------------------------------------------
if (!is_file($seedsPath)) {
    fwrite(STDERR, "Missing experts.json seed\n");
    exit(1);
}

$seeds = json_decode(file_get_contents($seedsPath), true);
if (!is_array($seeds)) {
    fwrite(STDERR, "Invalid experts.json\n");
    exit(1);
}

$check = $pdo->prepare('SELECT 1 FROM experts WHERE slug = ? LIMIT 1');
$insert = $pdo->prepare(
    'INSERT INTO experts
     (slug, version, name, description, system_prompt, model,
      temperature, top_k, retrieval_scope, enabled, is_router, is_synthesizer)
     VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, TRUE, ?, ?)'
);

foreach ($seeds as $expert) {
    $check->execute([$expert['slug']]);
    if ($check->fetch()) {
        echo "Expert '{$expert['slug']}' already exists — skipped (never overwrites).\n";
        continue;
    }
    $insert->execute([
        $expert['slug'],
        $expert['name'],
        $expert['description'] ?? '',
        $expert['system_prompt'],
        $expert['model'] ?? '',
        $expert['temperature'] ?? 0.20,
        $expert['top_k'] ?? 5,
        json_encode($expert['retrieval_scope'] ?? []),
        // PDO/pgsql sends a raw PHP false as '' (empty string),
        // which PostgreSQL rejects for a boolean column with
        // "invalid input syntax for type boolean: ''".
        // Cast to int: PostgreSQL accepts 0/1 for boolean.
        (int) ($expert['is_router'] ?? false),
        (int) ($expert['is_synthesizer'] ?? false),
    ]);
    echo "Seeded expert '{$expert['slug']}' v1.\n";
}

echo "Migration complete.\n";
