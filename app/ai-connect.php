<?php

declare(strict_types=1);

/**
 * NexGen CLD / BMOS — AI Connector
 *
 * Connects the PHP application to Lemonade's
 * OpenAI-compatible API.
 *
 * Environment variables:
 *
 * LEMONADE_BASE_URL=http://172.25.160.1:13305/v1
 * LEMONADE_MODEL=Qwen3.5-2B-GGUF
 *
 * This file must remain outside the public web root.
 */

/**
 * Return the Lemonade API base URL.
 */
function ai_base(): string
{
    $url = getenv('LEMONADE_BASE_URL')
        ?: 'http://172.25.160.1:13305/v1';

    return rtrim($url, '/');
}

/**
 * Return the configured model identifier.
 */
function ai_model(): string
{
    return getenv('LEMONADE_MODEL')
        ?: 'Qwen3.5-2B-GGUF';
}

/**
 * Send a prompt to Lemonade and return the assistant's reply.
 *
 * @throws RuntimeException When the request fails.
 */
function ai_chat(
    string $prompt,
    string $system = '',
    float $temperature = 0.2
): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'The PHP cURL extension is not installed.'
        );
    }

    if (trim($prompt) === '') {
        throw new InvalidArgumentException(
            'The AI prompt cannot be empty.'
        );
    }

    $messages = [];

    if ($system !== '') {
        $messages[] = [
            'role' => 'system',
            'content' => $system,
        ];
    }

    $messages[] = [
        'role' => 'user',
        'content' => $prompt,
    ];

    $payload = [
        'model' => ai_model(),
        'messages' => $messages,
        'temperature' => max(0.0, min(2.0, $temperature)),
        'chat_template_kwargs' => [
            'enable_thinking' => false,
        ],
    ];

    $json = json_encode($payload);

    if ($json === false) {
        throw new RuntimeException(
            'Failed to encode the Lemonade request.'
        );
    }

    $ch = curl_init(
        ai_base() . '/chat/completions'
    );

    if ($ch === false) {
        throw new RuntimeException(
            'Failed to initialize the Lemonade connection.'
        );
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $out = curl_exec($ch);

    $status = curl_getinfo(
        $ch,
        CURLINFO_RESPONSE_CODE
    );

    $error = curl_error($ch);

    curl_close($ch);

    if ($out === false) {
        throw new RuntimeException(
            'Lemonade connection failed: ' . $error
        );
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException(
            'Lemonade returned HTTP '
            . $status
            . ': '
            . $out
        );
    }

    $data = json_decode($out, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            'Lemonade returned invalid JSON.'
        );
    }

    $reply = $data['choices'][0]['message']['content'] ?? null;

    if (!is_string($reply) || trim($reply) === '') {
        throw new RuntimeException(
            'Lemonade returned an empty or invalid reply.'
        );
    }

    return trim($reply);
}

/**
 * Retrieve the available Lemonade models.
 *
 * @throws RuntimeException When the request fails.
 */
function ai_models(): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'The PHP cURL extension is not installed.'
        );
    }

    $ch = curl_init(
        ai_base() . '/models'
    );

    if ($ch === false) {
        throw new RuntimeException(
            'Failed to initialize the Lemonade connection.'
        );
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]);

    $out = curl_exec($ch);

    $status = curl_getinfo(
        $ch,
        CURLINFO_RESPONSE_CODE
    );

    $error = curl_error($ch);

    curl_close($ch);

    if ($out === false) {
        throw new RuntimeException(
            'Lemonade model request failed: ' . $error
        );
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException(
            'Lemonade /models returned HTTP '
            . $status
            . ': '
            . $out
        );
    }

    $data = json_decode($out, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            'Lemonade returned invalid model JSON.'
        );
    }

    return $data;
}