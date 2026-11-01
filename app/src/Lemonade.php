<?php
// BMOS 3.2 — Phase 8: Lemonade (Windows-hosted) client.
// Lemonade runs on the Windows host, NOT in Docker. PHP reaches it via
// LEMONADE_URL (default http://host.docker.internal:13305, Docker Desktop's
// host gateway). The gateway IP is a documented fallback only, never
// hardcoded here.
//
// Lemonade receives NO PostgreSQL credentials and NO BMOS secrets. It is
// a pure inference backend; PHP owns auth, tenant scoping, and persistence.
// Qwen3.5 requests need chat_template_kwargs.enable_thinking=false.

class Lemonade
{
    private string $baseUrl;
    private string $model;

    public function __construct()
    {
        $this->baseUrl = rtrim(getenv('LEMONADE_URL') ?: 'http://host.docker.internal:13305', '/');
        $this->model = getenv('LEMONADE_MODEL') ?: 'Qwen3.5-2B-GGUF';
    }

    /** GET /v1/models — returns model list or throws. */
    public function models(): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/v1/models');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === '' || $code !== 200) {
            throw new RuntimeException("Lemonade models failed: HTTP {$code} {$err}");
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Lemonade models: invalid JSON');
        }
        return $data;
    }

    /**
     * POST /v1/chat/completions.
     * $messages: [['role'=>'user','content'=>'...'], ...]
     * Returns the assistant's content string.
     */
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
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === '' || $code !== 200) {
            throw new RuntimeException("Lemonade chat failed: HTTP {$code} {$err}");
        }
        $data = json_decode($body, true);
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new RuntimeException('Lemonade chat: no content in response');
        }
        return $content;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }
}
