<?php
// BMOS 3.2 — Phase 17: generic OpenAI-compatible provider.
// Covers Mistral, DeepSeek, OpenAI, Groq, Together, Fireworks, remote
// Ollama, and any custom endpoint via AI_PROVIDER=custom. Bearer auth
// when a key is configured; some endpoints (local Ollama) need none.

class OpenAICompatibleProvider extends BaseProvider
{
    private ?string $apiKey;

    public function __construct(string $providerName, string $baseUrl, ?string $apiKey, string $model)
    {
        parent::__construct($providerName, $baseUrl, $model);
        $this->apiKey = ($apiKey !== null && trim($apiKey) !== '') ? trim($apiKey) : null;
    }

    protected function extraHeaders(): array
    {
        if ($this->apiKey === null) {
            return [];
        }
        return ['Authorization: Bearer ' . $this->apiKey];
    }

    public function models(): array
    {
        [$code, $body] = $this->httpGet('/v1/models');
        if ($body === '' || $code !== 200) {
            throw new RuntimeException("{$this->providerName} models failed: HTTP {$code}");
        }
        return $this->decodeJson($body, 'models');
    }

    public function chat(array $messages, array $opts = []): string
    {
        $payload = [
            'model' => $opts['model'] ?? $this->model,
            'messages' => $messages,
            'max_tokens' => $opts['max_tokens'] ?? 512,
            'temperature' => $opts['temperature'] ?? 0.7,
        ];
        [$code, $body] = $this->httpPost('/v1/chat/completions', $payload);
        if ($body === '' || $code !== 200) {
            throw new RuntimeException("{$this->providerName} chat failed: HTTP {$code}");
        }
        return $this->extractContent($this->decodeJson($body, 'chat'), 'chat');
    }
}
