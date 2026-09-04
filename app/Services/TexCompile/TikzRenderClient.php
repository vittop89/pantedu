<?php

declare(strict_types=1);

namespace App\Services\TexCompile;

/**
 * G22.S15 — Client HMAC per microservizio tex-compile-vps endpoint
 * `/render-tikz` (TikZ → SVG).
 *
 * Stesso pattern di TexCompileClient ma:
 *   - URL: /render-tikz
 *   - Body: tikz_b64, libraries[], pgfplots_libraries[], extra_packages[],
 *           border, doc_id
 *   - Risposta success: image/svg+xml binary
 *   - Risposta errore:  application/json {ok:false, log, duration_ms}
 *
 * Esempio:
 *
 *   $client = new TikzRenderClient(
 *       endpoint: getenv('TEX_COMPILE_ENDPOINT'),
 *       secret:   getenv('TEX_COMPILE_SECRET'),
 *   );
 *   $r = $client->render(
 *       $tikzSource,
 *       libraries: ['arrows.meta','calc'],
 *       extraPackages: ['pgfplots'],
 *       docId: 'tikz_'.substr($hash, 0, 12),
 *   );
 *   if ($r['ok']) { file_put_contents($cachePath, $r['svg']); }
 */
final class TikzRenderClient
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $secret,
        private readonly int $timeoutSeconds = 25,
        private readonly string $caBundle = '',
    ) {
        if ($this->endpoint === '' || $this->secret === '') {
            throw new \InvalidArgumentException('TikzRenderClient: endpoint e secret obbligatori.');
        }
        if (str_ends_with($this->endpoint, '/')) {
            throw new \InvalidArgumentException('TikzRenderClient: endpoint senza trailing slash.');
        }
    }

    /**
     * @param list<string> $libraries           usetikzlibrary
     * @param list<string> $pgfplotsLibraries   usepgfplotslibrary
     * @param list<string> $extraPackages       usepackage extra (pgfplots, physics, ...)
     * @return array{ok:bool, svg:?string, log:string, http_status:int, duration_ms:?int}
     */
    public function render(
        string $tikzSource,
        array $libraries = [],
        array $pgfplotsLibraries = [],
        array $extraPackages = [],
        string $border = '2pt',
        string $docId = 'tikz',
    ): array {
        $payload = json_encode([
            'tikz_b64'           => base64_encode($tikzSource),
            'libraries'          => array_values($libraries),
            'pgfplots_libraries' => array_values($pgfplotsLibraries),
            'extra_packages'     => array_values($extraPackages),
            'border'             => $border,
            'doc_id'             => $docId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);

        $ch = curl_init($this->endpoint . '/render-tikz');
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Timestamp: ' . $timestamp,
                'X-Signature: ' . $signature,
                'Accept: image/svg+xml, application/json',
            ],
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER         => true,
        ];
        if ($this->caBundle !== '' && is_file($this->caBundle)) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $opts);

        $rawResponse = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($errno !== 0 || $rawResponse === false) {
            return [
                'ok'          => false,
                'svg'         => null,
                'log'         => "Errore di rete: [$errno] $err",
                'http_status' => 0,
                'duration_ms' => null,
            ];
        }

        $headersBlob = substr((string) $rawResponse, 0, $headerSize);
        $body        = substr((string) $rawResponse, $headerSize);
        $headers     = self::parseHeaders($headersBlob);
        $duration    = isset($headers['x-compile-duration-ms'])
            ? (int)$headers['x-compile-duration-ms']
            : null;

        if ($status === 200) {
            return [
                'ok'          => true,
                'svg'         => $body,
                'log'         => '',
                'http_status' => 200,
                'duration_ms' => $duration,
            ];
        }

        // Errore: il server ritorna JSON {ok:false, log, duration_ms}.
        $log = $body;
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['log'])) {
            $log = (string) $decoded['log'];
            if ($duration === null && isset($decoded['duration_ms'])) {
                $duration = (int)$decoded['duration_ms'];
            }
        }
        return [
            'ok'          => false,
            'svg'         => null,
            'log'         => $log,
            'http_status' => $status,
            'duration_ms' => $duration,
        ];
    }

    /** @return array<string,string> */
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
