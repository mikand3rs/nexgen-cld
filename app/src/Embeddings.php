<?php
// BMOS 3.2 — Phase 6: embedding generator.
//
// DEMO IMPLEMENTATION — NOT FOR PRODUCTION. This produces deterministic,
// hash-based pseudo-vectors so the Qdrant plumbing (index, tenant-filtered
// search, rebuild) can be built and tested before an embedding model is
// selected. It has NO semantic meaning: similar texts do NOT get similar
// vectors.
//
// SWAP-IN CONTRACT: replace DemoEmbeddings::generate() with a real model
// call (e.g. Lemonade/Ollama embeddings endpoint). The contract is:
//   generate(string $text, int $dim): list<float>  — deterministic,
//   length $dim, L2-normalized. The collection's vector size
//   (QDRANT_VECTOR_SIZE) must equal the real model's output dimension.

final class DemoEmbeddings
{
    /**
     * Deterministic pseudo-embedding: SHA-256 in counter mode, bytes mapped
     * to [-1, 1], L2-normalized. Same text + same dim => same vector, always.
     *
     * @return list<float>
     */
    public static function generate(string $text, int $dim): array
    {
        $vec = [];
        $counter = 0;
        while (count($vec) < $dim) {
            $hash = hash('sha256', $text . ':' . $counter, true);
            /** @var array<int, int> $bytes */
            $bytes = unpack('C*', $hash);
            foreach ($bytes as $b) {
                if (count($vec) >= $dim) {
                    break;
                }
                $vec[] = ($b / 127.5) - 1.0;
            }
            $counter++;
        }
        $norm = sqrt(array_sum(array_map(static fn(float $x): float => $x * $x, $vec)));
        if ($norm > 0) {
            $vec = array_map(static fn(float $x): float => $x / $norm, $vec);
        }
        return $vec;
    }

    /** Vector dimension for this deployment. Never hardcoded at call sites. */
    public static function dimension(): int
    {
        return (int) (getenv('QDRANT_VECTOR_SIZE') ?: 384);
    }
}
