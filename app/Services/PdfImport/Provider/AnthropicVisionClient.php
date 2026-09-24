<?php

declare(strict_types=1);

namespace App\Services\PdfImport\Provider;

/**
 * Phase PDF-Import — client vision Anthropic (Claude) via /v1/messages.
 *
 * Chiave letta da config (ProviderRouter), mai dal request. L'immagine è
 * inviata come source base64. La risposta attesa è testo (JSON nel testo).
 */
final class AnthropicVisionClient extends AbstractVisionClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint,
        private readonly string $model,
        private readonly string $apiVersion = '2023-06-01',
        int $timeoutSeconds = 90,
        string $caBundle = '',
    ) {
        parent::__construct($timeoutSeconds, $caBundle);
        if ($this->apiKey === '') {
            throw new \RuntimeException('provider_unavailable');
        }
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function extract(string $imagePng, string $systemPrompt, string $userPrompt): array
    {
        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'system'     => $systemPrompt,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'   => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => 'image/png',
                            'data'       => base64_encode($imagePng),
                        ],
                    ],
                    ['type' => 'text', 'text' => $userPrompt],
                ],
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        [$status, $body] = $this->postJson($this->endpoint, [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . $this->apiVersion,
        ], $payload);

        $decoded = $this->decode($status, $body);

        $text = '';
        foreach ((array)($decoded['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string)($block['text'] ?? '');
            }
        }
        return [
            'text'       => $text,
            'model'      => (string)($decoded['model'] ?? $this->model),
            'tokens_in'  => (int)($decoded['usage']['input_tokens'] ?? 0),
            'tokens_out' => (int)($decoded['usage']['output_tokens'] ?? 0),
        ];
    }

    public function extractMany(array $imagesPng, string $systemPrompt, string $userPrompt): array
    {
        $content = [['type' => 'text', 'text' => $userPrompt]];
        foreach ($imagesPng as $png) {
            $content[] = [
                'type'   => 'image',
                'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode((string)$png)],
            ];
        }
        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'system'     => $systemPrompt,
            'messages'   => [['role' => 'user', 'content' => $content]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        [$status, $body] = $this->postJson($this->endpoint, [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . $this->apiVersion,
        ], $payload);
        $decoded = $this->decode($status, $body);
        $text = '';
        foreach ((array)($decoded['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string)($block['text'] ?? '');
            }
        }
        return [
            'text'       => $text,
            'model'      => (string)($decoded['model'] ?? $this->model),
            'tokens_in'  => (int)($decoded['usage']['input_tokens'] ?? 0),
            'tokens_out' => (int)($decoded['usage']['output_tokens'] ?? 0),
        ];
    }

    public function complete(string $systemPrompt, string $userPrompt): array
    {
        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'system'     => $systemPrompt,
            'messages'   => [[
                'role'    => 'user',
                'content' => [['type' => 'text', 'text' => $userPrompt]],
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        [$status, $body] = $this->postJson($this->endpoint, [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . $this->apiVersion,
        ], $payload);
        $decoded = $this->decode($status, $body);

        $text = '';
        foreach ((array)($decoded['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string)($block['text'] ?? '');
            }
        }
        return [
            'text'       => $text,
            'model'      => (string)($decoded['model'] ?? $this->model),
            'tokens_in'  => (int)($decoded['usage']['input_tokens'] ?? 0),
            'tokens_out' => (int)($decoded['usage']['output_tokens'] ?? 0),
        ];
    }
}
