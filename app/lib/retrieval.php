<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NexGen CLD — Retrieval (library)
|--------------------------------------------------------------------------
|
| Retrieval rule (V2.3 / V3.3): tenant filter FIRST, then scope,
| then similarity. Never the other way around.
|
| Working retrieval today: PostgreSQL full-text search.
|   SELECT ... WHERE tenant_id = ? AND content_tsv @@ plainto_tsquery
|   ORDER BY ts_rank DESC, chunk_index ASC LIMIT top_k
| If pgvector is available AND a chunk has an embedding AND an
| embedding for the question is supplied, vector similarity is used
| as an additional ranking — it never replaces the tenant filter.
| Embedding the question is NOT implemented in v2/v3 by default:
| the embedding model is still an open decision (see README), and
| shipping a guessed embedding call would produce wrong vectors.
| When that model is chosen, implement embed_question() here, with
| the same model + dimensions as the chunks, and pass it in.
|
| Return shape: each row has document_id, original_name, collection,
| chunk_index, content, rank. Sources for answers are built from this.
*/

/**
 * Retrieve passages for a tenant + expert scope.
 *
 * @param string[] $collections Empty = all collections for this tenant.
 * @return array<int, array<string, mixed>>
 */
function retrieve_passages(
    PDO $pdo,
    int $tenantId,
    string $question,
    array $collections = [],
    int $topK = 5
): array {
    $topK = max(1, min(20, $topK));
    $question = trim($question);
    if ($question === '') {
        return [];
    }

    $sql = "SELECT c.document_id, d.original_name, d.collection,
                   c.chunk_index, c.content,
                   ts_rank(c.content_tsv, plainto_tsquery('english', ?)) AS rank
            FROM doc_chunks c
            JOIN documents d ON d.id = c.document_id AND d.tenant_id = ?
            WHERE c.tenant_id = ?
              AND d.status = 'indexed'
              AND c.content_tsv @@ plainto_tsquery('english', ?)";
    $params = [$question, $tenantId, $tenantId, $question];

    if ($collections !== []) {
        $placeholders = implode(',', array_fill(0, count($collections), '?'));
        $sql .= " AND d.collection IN ($placeholders)";
        $params = array_merge($params, $collections);
    }

    $sql .= ' ORDER BY rank DESC, c.chunk_index ASC LIMIT ' . $topK;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Build a grounded prompt: system prompt + retrieved passages with
 * document + chunk references + question. If there are no passages,
 * the prompt says so explicitly, so the model does not invent an
 * answer from general knowledge and present it as grounded.
 */
function grounded_prompt(string $systemPrompt, array $passages, string $question): string
{
    if ($passages === []) {
        return $systemPrompt . "\n\n"
            . "Retrieved documents: none. The documents do not contain "
            . "an answer to this question. Say so plainly, and do not "
            . "invent facts, prices, or dates.\n\n"
            . "Question: " . $question;
    }

    $parts = [];
    foreach ($passages as $i => $p) {
        $parts[] = '[' . ($i + 1) . '] ' . $p['original_name']
            . ' (chunk ' . $p['chunk_index'] . "):\n" . $p['content'];
    }

    return $systemPrompt . "\n\n"
        . "Retrieved passages (use only these to answer; cite them "
        . "as [1], [2], etc. in your answer):\n"
        . implode("\n\n", $parts) . "\n\n"
        . "Question: " . $question;
}

/**
 * Format passages as a JSON-safe sources list for API responses.
 */
function passages_to_sources(array $passages): array
{
    $sources = [];
    foreach ($passages as $i => $p) {
        $sources[] = [
            'ref' => $i + 1,
            'document_id' => (int) $p['document_id'],
            'document' => $p['original_name'],
            'collection' => $p['collection'],
            'chunk_index' => (int) $p['chunk_index'],
        ];
    }
    return $sources;
}
