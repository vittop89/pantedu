<?php

declare(strict_types=1);

namespace App\Services\TexCompile;

/**
 * Client per microservizio tex-compile-vps.
 *
 * Esempio uso (lato pantedu hosting legacy):
 *
 *   $client = new TexCompileClient(
 *       endpoint: getenv('TEX_COMPILE_ENDPOINT'),       // https://tex.tuosito.it
 *       secret:   getenv('TEX_COMPILE_SECRET'),
 *   );
 *   $result = $client->compile($texSource, docId: 'verifica_42');
 *   if ($result['ok']) {
 *       file_put_contents($pdfPath, $result['pdf']);
 *   } else {
 *       error_log('Compile fallito: ' . $result['log']);
 *   }
 *
 * Errori:
 *   - HTTP 401  → segreto/timestamp errati (check config)
 *   - HTTP 422  → .tex con errore di compilazione (vedi 'log')
 *   - HTTP 413  → sorgente > 5 MB
 *   - HTTP 5xx  → guasto VPS, fallback a flow legacy se disponibile
 */
final class TexCompileClient
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $secret,
        private readonly int    $timeoutSeconds = 35,
    ) {
        if ($this->endpoint === '' || $this->secret === '') {
            throw new \InvalidArgumentException('TexCompileClient: endpoint e secret obbligatori.');
        }
        if (str_ends_with($this->endpoint, '/')) {
            throw new \InvalidArgumentException('TexCompileClient: endpoint senza trailing slash.');
        }
    }

    /**
     * Compila .tex e restituisce array con esito.
     *
     * @return array{
     *     ok: bool,
     *     pdf: string|null,
     *     log: string,
     *     http_status: int,
     *     duration_ms: int|null,
     *     engine: string|null
     * }
     */
    public function compile(
        string $texSource,
        string $docId,
        string $engine = 'pdflatex',
        int    $passes = 2,
    ): array {
        $payload = json_encode([
            'tex_b64' => base64_encode($texSource),
            'doc_id'  => $docId,
            'engine'  => $engine,
            'passes'  => $passes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);

        $ch = curl_init($this->endpoint . '/compile');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Timestamp: ' . $timestamp,
                'X-Signature: ' . $signature,
                'Accept: application/pdf, application/json',
            ],
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER         => true,
        ]);

        $rawResponse = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($errno !== 0 || $rawResponse === false) {
            return [
                'ok'          => false,
                'pdf'         => null,
                'log'         => "Errore di rete: [$errno] $err",
                'http_status' => 0,
                'duration_ms' => null,
                'engine'      => null,
            ];
        }

        $headersBlob = substr((string) $rawResponse, 0, $headerSize);
        $body        = substr((string) $rawResponse, $headerSize);
        $headers     = self::parseHeaders($headersBlob);

        $duration = isset($headers['x-compile-duration-ms'])
            ? (int) $headers['x-compile-duration-ms']
            : null;
        $engineUsed = $headers['x-compile-engine'] ?? null;

        if ($status === 200) {
            return [
                'ok'          => true,
                'pdf'         => $body,
                'log'         => '',
                'http_status' => 200,
                'duration_ms' => $duration,
                'engine'      => $engineUsed,
            ];
        }

        // Errore: il body dovrebbe essere JSON con campo "log".
        $log = $body;
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['log'])) {
            $log = (string) $decoded['log'];
        }

        return [
            'ok'          => false,
            'pdf'         => null,
            'log'         => $log,
            'http_status' => $status,
            'duration_ms' => $duration,
            'engine'      => $engineUsed,
        ];
    }

    /**
     * Health check liveness — utile per dashboard/monitoring.
     */
    public function health(): bool
    {
        $ch = curl_init($this->endpoint . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status === 200 && is_string($body) && str_contains($body, '"ok"');
    }

    /**
     * @return array<string,string>
     */
    private static function parseHeaders(string $blob): array
    {
        $headers = [];
        foreach (preg_split("/\r?\n/", $blob) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }
}
