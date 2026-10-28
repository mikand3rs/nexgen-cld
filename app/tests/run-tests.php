<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NexGen CLD — Unit tests (no database, no Lemonade)
|--------------------------------------------------------------------------
|
| Run inside the php container, or any PHP CLI:
|
|   php app/tests/run-tests.php
|
| These tests cover only pure functions: chunking, routing, upload
| validation, and expert seed shape. Database / Lemonade tests are
| NOT here — they need a running stack, and are listed in the README
| as manual gates (tenant isolation, retrieval, worker restart).
| A test that needs a DB or AI and cannot run here must not be
| faked to pass; it belongs in the manual gate list, not this file.
*/

require_once __DIR__ . '/../lib/ingestion.php';
require_once __DIR__ . '/../lib/experts.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "PASS: $name\n";
    } else {
        $failed++;
        echo "FAIL: $name\n";
    }
}

// --- Chunking ---------------------------------------------------------
check('chunk_text: empty returns []', chunk_text('') === []);
check('chunk_text: short text is one chunk', chunk_text('Hello world') === ['Hello world']);
$long = str_repeat("Paragraph one.\n\n", 200) . str_repeat('x', 2000);
$chunks = chunk_text($long);
check('chunk_text: long text splits into >1 chunk', count($chunks) > 1);
check('chunk_text: no chunk is empty', !in_array('', $chunks, true));
check('chunk_text: first chunk contains start', str_contains($chunks[0], 'Paragraph one.'));

// --- Routing -----------------------------------------------------------
check('router: website question -> website', route_question('Can you build me a website with a contact form?')[0] === 'website');
check('router: docker error -> support', route_question('Docker container failed with an error')[0] === 'support');
check('router: invoice -> finance-admin', route_question('How do I send an invoice?')[0] === 'finance-admin');
check('router: unknown -> general-operations fallback', route_question('Tell me about the weather')[0] === 'general-operations');
check('router: multi-expert capped at 2', count(route_question('My website docker deploy failed and I need an invoice')) <= 2);

// --- Upload validation -------------------------------------------------
check('upload: oversized rejected', ingestion_validate_upload([
    'error' => UPLOAD_ERR_OK, 'size' => 11 * 1024 * 1024, 'name' => 'a.txt', 'tmp_name' => '/tmp/x',
]) !== '');
check('upload: .docx rejected', ingestion_validate_upload([
    'error' => UPLOAD_ERR_OK, 'size' => 100, 'name' => 'a.docx', 'tmp_name' => '/tmp/x',
]) !== '');
check('upload: empty rejected', ingestion_validate_upload([
    'error' => UPLOAD_ERR_OK, 'size' => 0, 'name' => 'a.txt', 'tmp_name' => '/tmp/x',
]) !== '');

// --- Expert seeds --------------------------------------------------------
$seeds = json_decode(file_get_contents(__DIR__ . '/../db/seeds/experts.json'), true);
check('seeds: valid JSON array', is_array($seeds) && $seeds !== []);
$slugs = array_column($seeds, 'slug');
check('seeds: general-operations present', in_array('general-operations', $slugs, true));
check('seeds: website present', in_array('website', $slugs, true));
check('seeds: support present', in_array('support', $slugs, true));
check('seeds: finance-admin present', in_array('finance-admin', $slugs, true));
check('seeds: client-onboarding present', in_array('client-onboarding', $slugs, true));
check('seeds: synthesizer present', in_array('synthesizer', $slugs, true));
check('seeds: router present', in_array('router', $slugs, true));
$allHavePrompt = true;
foreach ($seeds as $s) {
    if (empty($s['system_prompt'])) {
        $allHavePrompt = false;
    }
}
check('seeds: every expert has a system prompt', $allHavePrompt);
check('seeds: no expert prompt invents prices', !str_contains(
    json_encode($seeds),
    '$150'
));

echo "\n$passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
