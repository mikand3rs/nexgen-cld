<?php
// BMOS 3.2 — Phase 17: builds the configured AI provider from env.
// AI_PROVIDER selects the backend (default: lemonade, backward compatible).
// API keys come from env OR from a Docker secret file via <NAME>_FILE —
// never from committed files. describeSafe() is safe for logs/health.

class ProviderFactory
{
    public static function create(): AIProvider
    {
        $name = strtolower(trim((string) (getenv('AI_PROVIDER') ?: 'lemonade')));
        switch ($name) {
            case 'lemonade':
                return new LemonadeProvider(
                    self::env('LEMONADE_URL', 'http://host.docker.internal:13305'),
                    self::env('LEMONADE_MODEL', 'Qwen3.5-2B-GGUF')
                );
            case 'mistral':
                return new OpenAICompatibleProvider(
                    'mistral',
                    'https://api.mistral.ai/v1',
                    self::requiredSecret('MISTRAL_API_KEY'),
                    self::env('MISTRAL_MODEL', 'mistral-large-latest')
                );
            case 'deepseek':
                return new OpenAICompatibleProvider(
                    'deepseek',
                    'https://api.deepseek.com/v1',
                    self::requiredSecret('DEEPSEEK_API_KEY'),
                    self::env('DEEPSEEK_MODEL', 'deepseek-flash')
                );
            case 'openai':
                return new OpenAICompatibleProvider(
                    'openai',
                    'https://api.openai.com/v1',
                    self::requiredSecret('OPENAI_API_KEY'),
                    self::env('OPENAI_MODEL', 'gpt-6-luna')
                );
            case 'groq':
                return new OpenAICompatibleProvider(
                    'groq',
                    'https://api.groq.com/openai/v1',
                    self::requiredSecret('GROQ_API_KEY'),
                    self::env('GROQ_MODEL', 'llama-3.3-70b-versatile')
                );
            case 'ollama':
                // Remote Ollama (local network, Cloud Run GPU, etc.). Key optional.
                return new OpenAICompatibleProvider(
                    'ollama',
                    self::env('OLLAMA_URL', 'http://host.docker.internal:11434'),
                    self::optionalSecret('OLLAMA_API_KEY'),
                    self::requiredEnv('OLLAMA_MODEL', 'set OLLAMA_MODEL to the model to use')
                );
            case 'custom':
                // Any OpenAI-compatible endpoint.
                return new OpenAICompatibleProvider(
                    'custom',
                    self::requiredEnv('AI_API_URL', 'set AI_API_URL to the endpoint base URL'),
                    self::optionalSecret('AI_API_KEY'),
                    self::requiredEnv('AI_MODEL', 'set AI_MODEL to the model name')
                );
            default:
                throw new RuntimeException(
                    "Unknown AI_PROVIDER '{$name}'. " .
                    'Valid: lemonade, mistral, deepseek, openai, groq, ollama, custom.'
                );
        }
    }

    /**
     * Safe summary for /ai/health and logs. Never includes secrets.
     * Returns a misconfigured marker instead of throwing.
     */
    public static function describeSafe(): array
    {
        try {
            return self::create()->describe();
        } catch (Throwable $e) {
            return [
                'provider' => (string) (getenv('AI_PROVIDER') ?: 'lemonade'),
                'error' => 'misconfigured',
            ];
        }
    }

    private static function env(string $name, string $default): string
    {
        $v = getenv($name);
        return ($v === false || $v === '') ? $default : $v;
    }

    private static function requiredEnv(string $name, string $hint): string
    {
        $v = self::env($name, '');
        if ($v === '') {
            throw new RuntimeException("AI provider config: {$hint} ({$name})");
        }
        return $v;
    }

    /**
     * API key from env, or from a Docker secret file via <NAME>_FILE
     * (e.g. MISTRAL_API_KEY_FILE=/run/secrets/mistral_api_key).
     */
    private static function optionalSecret(string $name): ?string
    {
        $v = getenv($name);
        if ($v !== false && trim($v) !== '') {
            return trim($v);
        }
        $f = getenv($name . '_FILE');
        if ($f !== false && $f !== '' && is_readable($f)) {
            $c = trim((string) file_get_contents($f));
            return $c !== '' ? $c : null;
        }
        return null;
    }

    private static function requiredSecret(string $name): string
    {
        $v = self::optionalSecret($name);
        if ($v === null) {
            throw new RuntimeException(
                "AI provider needs an API key: set {$name} (env) " .
                "or {$name}_FILE (Docker secret file)."
            );
        }
        return $v;
    }
}
