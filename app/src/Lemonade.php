<?php
// BMOS 3.2 — Phase 17: backward-compat shim.
// The AI subsystem now lives in app/src/AI/ (provider abstraction).
// `new Lemonade()` still works: it builds the Lemonade provider from env.
// New code should use ProviderFactory::create() instead.
require_once __DIR__ . '/AI/AIProvider.php';
require_once __DIR__ . '/AI/BaseProvider.php';
require_once __DIR__ . '/AI/LemonadeProvider.php';
require_once __DIR__ . '/AI/OpenAICompatibleProvider.php';
require_once __DIR__ . '/AI/ProviderFactory.php';

class Lemonade extends LemonadeProvider
{
    public function __construct()
    {
        parent::__construct(
            getenv('LEMONADE_URL') ?: 'http://host.docker.internal:13305',
            getenv('LEMONADE_MODEL') ?: 'Qwen3.5-2B-GGUF'
        );
    }

    /** Backward compat: old code called $lem->baseUrl(). */
    public function baseUrl(): string
    {
        return $this->endpoint();
    }
}
