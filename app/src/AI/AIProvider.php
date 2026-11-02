<?php
// BMOS 3.2 — Phase 17: AI provider abstraction.
// One interface for every inference backend (local Lemonade, Mistral,
// DeepSeek, OpenAI, Groq, remote Ollama, any OpenAI-compatible endpoint).
// PHP owns auth, tenant scoping, and persistence; the provider is pure
// inference. No provider ever receives DB credentials or BMOS secrets —
// only the prompt text, same as Lemonade in Phase 8.

interface AIProvider
{
    /** Short id: 'lemonade', 'mistral', 'deepseek', 'openai', 'groq', 'ollama', 'custom'. */
    public function name(): string;

    /** Base URL of the inference endpoint (no credentials in it, ever). */
    public function endpoint(): string;

    /** Default model id used when the caller doesn't override. */
    public function defaultModel(): string;

    /** GET <base>/v1/models — decoded response array, or throws. */
    public function models(): array;

    /**
     * POST <base>/v1/chat/completions.
     * $messages: [['role'=>'user','content'=>'...'], ...]
     * $opts: ['model'=>..., 'max_tokens'=>..., 'temperature'=>...]
     * Returns the assistant's content string, or throws.
     */
    public function chat(array $messages, array $opts = []): string;

    /**
     * Safe summary for /ai/health and logs. MUST NOT contain API keys
     * or any secret material.
     */
    public function describe(): array;
}
