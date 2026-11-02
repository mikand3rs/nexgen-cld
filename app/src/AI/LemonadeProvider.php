<?php
// BMOS 3.2 — Phase 17: Lemonade provider (moved from app/src/Lemonade.php).
// Lemonade runs on the Windows host (GPU), NOT in Docker. PHP reaches it
// via LEMONADE_URL. No API key, no auth headers — local trust boundary.
// Qwen3.5 requests need chat_template_kwargs.enable_thinking=false.

class LemonadeProvider extends BaseProvider
{
    public function __construct(string $baseUrl, string $model)
    {
        parent::__construct('lemonade', $baseUrl, $model);
    }

    public function models(): array
    {
        [$code, $body] = $this->httpGet('/v1/models');
        if ($body === '' || $code !== 200) {
            throw new RuntimeException("lemonade models failed: HTTP {$code}");
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
            // Qwen3.5 on Lemonade: disable thinking to get a clean response.
            'chat_template_kwargs' => ['enable_thinking' => false],
        ];
        [$code, $body] = $this->httpPost('/v1/chat/completions', $payload);
        if ($body === '' || $code !== 200) {
            throw new RuntimeException("lemonade chat failed: HTTP {$code}");
        }
        return $this->extractContent($this->decodeJson($body, 'chat'), 'chat');
    }
}
