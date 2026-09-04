<?php

declare(strict_types=1);

namespace App\Services\PdfImport\Provider;

/**
 * Phase PDF-Import — client vision Ollama locale via {base}/api/chat.
 *
 * base_url già validato da SsrfGuard nel ProviderRouter (allowlist host). Nessuna
 * chiave: inferenza locale. L'immagine è inviata come base64 nel campo `images`.
 */
final class OllamaVisionClient extends AbstractVisionClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        int $timeoutSeconds = 90,
        string $caBundle = '',
    ) {
        parent::__construct($timeoutSeconds, $caBundle);
    }

    public function name(): string
    {
        return 'ollama';
    }

    public function extract(string $imagePng, string $systemPrompt, string $userPrompt): array
    {
        $payload = json_encode([
            'model'    => $this->model,
            'stream'   => false,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                [
                    'role'    => 'user',
                    'content' => $userPrompt,
                    'images'  => [base64_encode($imagePng)],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        [$status, $body] = $this->postJson($this->baseUrl . '/api/chat', [], $payload);
        $decoded = $this->decode($status, $body);

        $text = (string)($decoded['message']['content'] ?? '');
        return [
            'text'       => $text,
            'model'      => (string)($decoded['model'] ?? $this->model),
            'tokens_in'  => (int)($decoded['prompt_eval_count'] ?? 0),
            'tokens_out' => (int)($decoded['eval_count'] ?? 0),
        ];
    }

    public function extractMany(array $imagesPng, string $systemPrompt, string $userPrompt): array
    {
        // Ollama locale: multi-immagine non supportato in questo flusso.
        throw new \RuntimeException('multi_image_unsupported');
    }

    public function complete(string $systemPrompt, string $userPrompt): array
    {
        $payload = json_encode([
            'model'    => $this->model,
            'stream'   => false,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        [$status, $body] = $this->postJson($this->baseUrl . '/api/chat', [], $payload);
        $decoded = $this->decode($status, $body);

        return [
            'text'       => (string)($decoded['message']['content'] ?? ''),
            'model'      => (string)($decoded['model'] ?? $this->model),
            'tokens_in'  => (int)($decoded['prompt_eval_count'] ?? 0),
            'tokens_out' => (int)($decoded['eval_count'] ?? 0),
        ];
    }
}
