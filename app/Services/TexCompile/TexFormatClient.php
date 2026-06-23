<?php

declare(strict_types=1);

namespace App\Services\TexCompile;

/**
 * G22.S15 — Client per VPS endpoint /format-tex (latexindent.pl).
 * Stesso pattern HMAC di TexCompileClient/TikzRenderClient.
 */
final class TexFormatClient
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $secret,
        private readonly int $timeoutSeconds = 12,
        private readonly string $caBundle = '',
    ) {
        if ($this->endpoint === '' || $this->secret === '') {
            throw new \InvalidArgumentException('TexFormatClient: endpoint e secret obbligatori.');
        }
        if (str_ends_with($this->endpoint, '/')) {
            throw new \InvalidArgumentException('TexFormatClient: endpoint senza trailing slash.');
        }
    }

    /** @return array{ok:bool, formatted:?string, log:string, http_status:int, duration_ms:?int} */
    public function format(string $source, string $docId = 'format'): array
    {
        $payload = json_encode([
            'source_b64' => base64_encode($source),
            'doc_id'     => $docId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);

        $ch = curl_init($this->endpoint . '/format-tex');
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Timestamp: ' . $timestamp,
                'X-Signature: ' . $signature,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($this->caBundle !== '' && is_file($this->caBundle)) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $opts);

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $stat  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            return [
                'ok' => false, 'formatted' => null,
                'log' => "Errore di rete: [$errno] $err",
                'http_status' => 0, 'duration_ms' => null,
            ];
        }
        $decoded = json_decode((string)$body, true);
        if (!\is_array($decoded)) {
            return [
                'ok' => false, 'formatted' => null,
                'log' => "Risposta JSON malformata (HTTP $stat)",
                'http_status' => $stat, 'duration_ms' => null,
            ];
        }
        return [
            'ok'          => (bool)($decoded['ok'] ?? false),
            'formatted'   => $decoded['formatted'] ?? null,
            'log'         => (string)($decoded['log'] ?? ''),
            'http_status' => $stat,
            'duration_ms' => isset($decoded['duration_ms']) ? (int)$decoded['duration_ms'] : null,
        ];
    }
}
