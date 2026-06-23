<?php

declare(strict_types=1);

namespace App\Services\PdfImport\Provider;

/**
 * Phase PDF-Import — base condivisa per i client vision (curl hygiene).
 *
 * Igiene cURL ispirata a TexCompileClient: SSL verify peer+host, timeout di
 * connessione e totali, CA bundle opzionale (fix Windows/XAMPP cURL error 60).
 * Le chiavi API arrivano dal costruttore (lette da Config nel ProviderRouter,
 * MAI dal request body).
 */
abstract class AbstractVisionClient implements ProviderInterface
{
    protected int $maxTokens = 4096;

    public function __construct(
        protected readonly int $timeoutSeconds = 90,
        protected readonly string $caBundle = '',
    ) {
    }

    /**
     * POST JSON con igiene cURL. Ritorna [httpStatus, bodyString].
     *
     * @param list<string> $headers header HTTP completi ("Name: value")
     * @return array{0:int,1:string}
     */
    protected function postJson(string $url, array $headers, string $body): array
    {
        // Errori transitori VELOCI (connessione/SSL/risposta vuota/5xx/429): un
        // retry immediato recupera i blip del provider senza passare dal backoff
        // FSM. Il TIMEOUT (errno 28) NON si ri-tenta qui (resterebbe sotto
        // fastcgi_read_timeout) → lo gestisce il retry sul poll successivo.
        $fastErrnos = [7, 35, 52, 56]; // connect/SSL/empty-reply/recv — NON 28
        $retryHttp  = [429, 500, 502, 503, 504];
        $attempts   = 0;

        while (true) {
            $attempts++;
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
                CURLOPT_TIMEOUT        => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if ($this->caBundle !== '' && is_file($this->caBundle)) {
                $opts[CURLOPT_CAINFO] = $this->caBundle;
            }
            curl_setopt_array($ch, $opts);

            $resp   = curl_exec($ch);
            $errno  = curl_errno($ch);
            $err    = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            $netFail   = ($errno !== 0 || $resp === false);
            $retryable = ($netFail && in_array($errno, $fastErrnos, true))
                || (!$netFail && in_array($status, $retryHttp, true));

            if ($retryable && $attempts < 2) {
                usleep(600000); // 0.6s prima dell'unico retry
                continue;
            }
            if ($netFail) {
                throw new \RuntimeException("provider_network_error: [$errno] $err");
            }
            return [$status, (string)$resp];
        }
    }

    /** Decodifica JSON o lancia, includendo un estratto del body per debug. */
    protected function decode(int $status, string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("provider_bad_response (HTTP $status): "
                . substr($body, 0, 200));
        }
        if ($status < 200 || $status >= 300) {
            $msg = $decoded['error']['message'] ?? ($decoded['error'] ?? 'unknown');
            throw new \RuntimeException("provider_http_$status: "
                . (is_string($msg) ? substr($msg, 0, 300) : json_encode($msg)));
        }
        return $decoded;
    }
}
