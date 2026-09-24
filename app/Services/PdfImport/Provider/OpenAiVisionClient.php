<?php

declare(strict_types=1);

namespace App\Services\PdfImport\Provider;

/**
 * Phase PDF-Import — client vision OpenAI via /v1/chat/completions.
 *
 * Chiave letta da config (ProviderRouter), mai dal request. L'immagine è
 * inviata come data-URI base64 (image_url).
 */
final class OpenAiVisionClient extends AbstractVisionClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint,
        private readonly string $model,
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
        return 'openai';
    }

    public function extract(string $imagePng, string $systemPrompt, string $userPrompt): array
    {
        $dataUri = 'data:image/png;base64,' . base64_encode($imagePng);
        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages'   => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $userPrompt],
                    // detail:high → il modello elabora l'immagine ad alta
                    // risoluzione (legge meglio segni piccoli: pallini difficoltà,
                    // colori badge). Ignorato dai modelli che non lo supportano.
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUri, 'detail' => 'high']],
                ]],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        [$status, $body] = $this->postJson($this->endpoint, [
            'Authorization: Bearer ' . $this->apiKey,
        ], $payload);

        $decoded = $this->decode($status, $body);

        $text = (string)($decoded['choices'][0]['message']['content'] ?? '');
        return [
            'text'       => $text,
            'model'      => (string)($decoded['model'] ?? $this->model),
            'tokens_in'  => (int)($decoded['usage']['prompt_tokens'] ?? 0),
            'tokens_out' => (int)($decoded['usage']['completion_tokens'] ?? 0),
        ];
    }

    public function extractMany(array $imagesPng, string $systemPrompt, string $userPrompt): array
    {
        $content = [['type' => 'text', 'text' => $userPrompt]];
        foreach ($imagesPng as $png) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:image/png;base64,' . base64_encode((string)$png), 'detail' => 'high'],
            ];
        }
        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages'   => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $content],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        [$status, $body] = $this->postJson($this->endpoint, ['Authorization: Bearer ' . $this->apiKey], $payload);
        $decoded = $this->decode($status, $body);
        return [
            'text'       => (string)($decoded['choices'][0]['message']['content'] ?? ''),
            'model'      => (string)($decoded['model'] ?? $this->model),
            'tokens_in'  => (int)($decoded['usage']['prompt_tokens'] ?? 0),
            'tokens_out' => (int)($decoded['usage']['completion_tokens'] ?? 0),
        ];
    }

    public function complete(string $systemPrompt, string $userPrompt): array
    {
        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages'   => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        [$status, $body] = $this->postJson($this->endpoint, [
            'Authorization: Bearer ' . $this->apiKey,
        ], $payload);
        $decoded = $this->decode($status, $body);

        return [
            'text'       => (string)($decoded['choices'][0]['message']['content'] ?? ''),
            'model'      => (string)($decoded['model'] ?? $this->model),
            'tokens_in'  => (int)($decoded['usage']['prompt_tokens'] ?? 0),
            'tokens_out' => (int)($decoded['usage']['completion_tokens'] ?? 0),
        ];
    }
}
