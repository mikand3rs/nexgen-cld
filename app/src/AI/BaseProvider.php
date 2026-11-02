<?php
// BMOS 3.2 — Phase 17: shared HTTP plumbing for AI providers.
// All providers speak OpenAI-compatible REST (Lemonade included).

abstract class BaseProvider implements AIProvider
{
    protected string $providerName;
    protected string $baseUrl;
    protected string $model;

    public function __construct(string $providerName, string $baseUrl, string $model)
    {
        $this->providerName = $providerName;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function endpoint(): string
    {
        return $this->baseUrl;
    }

    public function defaultModel(): string
    {
        return $this->model;
    }

    public function describe(): array
    {
        return [
            'provider' => $this->providerName,
            'model' => $this->model,
            'endpoint' => $this->baseUrl,
        ];
    }

    abstract public function models(): array;
    abstract public function chat(array $messages, array $opts = []): string;

    /** Extra headers per provider (e.g. Authorization). */
    protected function extraHeaders(): array
    {
        return [];
    }

    /** @return array{0:int,1:string} [http_code, body] */
    protected function httpGet(string $path): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->extraHeaders());
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            throw new RuntimeException("{$this->providerName}: HTTP error: {$err}");
        }
        return [$code, $body];
    }

    /** @return array{0:int,1:string} [http_code, body] */
    protected function httpPost(string $path, array $payload): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
            ['Content-Type: application/json'],
            $this->extraHeaders()
        ));
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            throw new RuntimeException("{$this->providerName}: HTTP error: {$err}");
        }
        return [$code, $body];
    }

    protected function decodeJson(string $body, string $what): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException("{$this->providerName} {$what}: invalid JSON");
        }
        return $data;
    }

    protected function extractContent(array $data, string $what): string
    {
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new RuntimeException("{$this->providerName} {$what}: no content in response");
        }
        return $content;
    }
}
