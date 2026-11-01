<?php
// BMOS 3.2 — Phase 6: minimal Qdrant HTTP client.
// Qdrant is a DERIVED index only. PostgreSQL (app.documents,
// app.document_chunks) is authoritative. Every point payload carries
// tenant_id, document_id, chunk_id, and source_version so the collection
// can be rebuilt from PostgreSQL at any time.
//
// TENANT RULE: every search MUST include a server-generated tenant filter.
// The caller passes the tenant ID from the authenticated session (never
// from client input). There is no search method without a tenant filter.

final class Qdrant
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? getenv('QDRANT_URL') ?: 'http://qdrant:6333', '/');
    }

    /** Low-level request. Throws RuntimeException on transport or API error. */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException("Qdrant request failed: {$err}");
        }
        $decoded = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded) && isset($decoded['status']['error'])
                ? (string) $decoded['status']['error']
                : "HTTP {$code}";
            throw new RuntimeException("Qdrant API error [{$code}]: {$msg}");
        }
        return is_array($decoded) ? $decoded : [];
    }

    public function collectionExists(string $name): bool
    {
        try {
            $this->request('GET', '/collections/' . urlencode($name));
            return true;
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), '[404]')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Create the collection. $vectorSize MUST match the embedding model
     * output — it comes from QDRANT_VECTOR_SIZE, never hardcoded, per the
     * guide ("do not hardcode 1536 until the selected embedding model is
     * confirmed").
     */
    public function createCollection(string $name, int $vectorSize): void
    {
        $this->request('PUT', '/collections/' . urlencode($name), [
            'vectors' => ['size' => $vectorSize, 'distance' => 'Cosine'],
        ]);
    }

    public function deleteCollection(string $name): void
    {
        try {
            $this->request('DELETE', '/collections/' . urlencode($name));
        } catch (RuntimeException $e) {
            // Deleting a missing collection is fine (idempotent rebuilds).
            if (!str_contains($e->getMessage(), '[404]')) {
                throw $e;
            }
        }
    }

    /** Payload index on tenant_id: makes the mandatory tenant filter fast. */
    public function createTenantIndex(string $collection): void
    {
        $this->request('PUT', '/collections/' . urlencode($collection) . '/index', [
            'field_name' => 'tenant_id',
            'field_schema' => 'keyword',
        ]);
    }

    /**
     * Upsert points. Each point: ['id' => uuid, 'vector' => [...],
     * 'payload' => ['tenant_id' => ..., 'document_id' => ...,
     * 'chunk_id' => ..., 'source_version' => N, ...]].
     */
    public function upsertPoints(string $collection, array $points): void
    {
        if ($points === []) {
            return;
        }
        $this->request('PUT', '/collections/' . urlencode($collection) . '/points', [
            'points' => $points,
        ]);
    }

    /**
     * Tenant-filtered vector search. The tenant filter is SERVER-GENERATED:
     * $tenantId must come from the authenticated session, never from client
     * input. There is intentionally no unfiltered search method.
     *
     * @return list<array{id: string, score: float, payload: array}>
     */
    public function search(string $collection, array $vector, string $tenantId, int $limit = 5): array
    {
        $res = $this->request('POST', '/collections/' . urlencode($collection) . '/points/search', [
            'vector' => $vector,
            'limit' => $limit,
            'with_payload' => true,
            'filter' => [
                'must' => [
                    ['key' => 'tenant_id', 'match' => ['value' => $tenantId]],
                ],
            ],
        ]);
        $out = [];
        foreach ($res['result'] ?? [] as $hit) {
            $out[] = [
                'id' => (string) ($hit['id'] ?? ''),
                'score' => (float) ($hit['score'] ?? 0),
                'payload' => is_array($hit['payload'] ?? null) ? $hit['payload'] : [],
            ];
        }
        return $out;
    }

    /** Point count (for rebuild verification). */
    public function count(string $collection): int
    {
        $res = $this->request('POST', '/collections/' . urlencode($collection) . '/points/count', [
            'exact' => true,
        ]);
        return (int) ($res['result']['count'] ?? 0);
    }
}
