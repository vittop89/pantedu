<?php

declare(strict_types=1);

namespace App\Services\TexCompile;

/**
 * G22.S15.bis Fase 4 — Client per VPS endpoint /svg-to-pdf (rsvg-convert).
 * Stesso pattern HMAC di TexCompileClient/TikzRenderClient/TexFormatClient.
 *
 * Usato nel pipeline pdflatex per pre-convertire blocchi SVG (GeoGebra)
 * in PDF vettoriali da \includegraphics nel master TeX.
 */
final class SvgToPdfClient
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $secret,
        private readonly int $timeoutSeconds = 15,
        private readonly string $caBundle = '',
    ) {
        if ($this->endpoint === '' || $this->secret === '') {
            throw new \InvalidArgumentException('SvgToPdfClient: endpoint e secret obbligatori.');
        }
        if (str_ends_with($this->endpoint, '/')) {
            throw new \InvalidArgumentException('SvgToPdfClient: endpoint senza trailing slash.');
        }
    }

    /**
     * @return array{ok:bool, pdf:?string, log:string, http_status:int, duration_ms:?int}
     *   pdf: contenuto PDF binario (raw bytes) se ok=true, null altrimenti.
     */
    public function convert(string $svgSource, string $docId = 'svg', int $dpi = 96): array
    {
        $payload = json_encode([
            'svg_b64' => base64_encode($svgSource),
            'doc_id'  => $docId,
            'dpi'     => $dpi,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);

        $ch = curl_init($this->endpoint . '/svg-to-pdf');
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
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
        ];
        if ($this->caBundle !== '' && is_file($this->caBundle)) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $stat  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            return [
                'ok' => false, 'pdf' => null,
                'log' => "Errore di rete: [$errno] $err",
                'http_status' => 0, 'duration_ms' => null,
            ];
        }
        $headers = substr((string)$response, 0, $headerSize);
        $body    = substr((string)$response, $headerSize);

        // Duration header (informativo)
        $duration = null;
        if (preg_match('/^X-Duration-Ms:\s*(\d+)/im', $headers, $m)) {
            $duration = (int)$m[1];
        }

        // Content-type discriminante: application/pdf = success, application/json = errore
        $contentType = '';
        if (preg_match('/^Content-Type:\s*([^\s;]+)/im', $headers, $m)) {
            $contentType = strtolower($m[1]);
        }

        if ($stat >= 200 && $stat < 300 && str_contains($contentType, 'pdf')) {
            // Sanity: deve iniziare con %PDF-
            if (substr($body, 0, 5) !== '%PDF-') {
                return [
                    'ok' => false, 'pdf' => null,
                    'log' => 'Risposta non e\' un PDF valido (manca header %PDF-)',
                    'http_status' => $stat, 'duration_ms' => $duration,
                ];
            }
            return [
                'ok' => true, 'pdf' => $body,
                'log' => '', 'http_status' => $stat, 'duration_ms' => $duration,
            ];
        }

        // Errore: prova a parse JSON
        $log = "HTTP $stat";
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $log = (string)($decoded['log'] ?? $decoded['detail'] ?? $decoded['error'] ?? $log);
            if (isset($decoded['duration_ms'])) {
                $duration = (int)$decoded['duration_ms'];
            }
        } else {
            $log .= ': ' . substr($body, 0, 400);
        }
        return [
            'ok' => false, 'pdf' => null,
            'log' => $log, 'http_status' => $stat, 'duration_ms' => $duration,
        ];
    }
}
