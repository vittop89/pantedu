<?php

declare(strict_types=1);

namespace App\Services\PdfImport\Provider;

/**
 * Phase PDF-Import — guard SSRF per il base-URL configurabile di Ollama.
 *
 * L'unico endpoint LLM con host non costante è Ollama (locale/LAN). Senza
 * controllo, un base-URL malevolo permetterebbe SSRF verso metadata endpoint
 * cloud (169.254.169.254), servizi interni, ecc. (cfr. LLM-PY-001 / OWASP A10).
 *
 * Policy: lo schema deve essere http/https, niente userinfo, e l'HOST deve
 * comparire ESPLICITAMENTE nell'allowlist di config (`pdf_import.ollama_allowed_hosts`).
 * Anthropic/OpenAI usano endpoint costanti e non passano da qui.
 */
final class SsrfGuard
{
    /**
     * Valida e normalizza un base-URL Ollama. Ritorna l'URL senza trailing slash.
     *
     * @param list<string> $allowedHosts host ammessi (es. ['127.0.0.1','localhost'])
     * @throws \RuntimeException 'ollama_base_url_invalid' / 'ollama_host_not_allowed'
     */
    public static function assertOllamaBaseUrl(string $baseUrl, array $allowedHosts): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            throw new \RuntimeException('ollama_base_url_invalid');
        }
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('ollama_base_url_invalid');
        }
        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('ollama_base_url_invalid');
        }
        // Niente credenziali in URL (utente:pass@host) → vettore di confusione.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('ollama_base_url_invalid');
        }
        $host = strtolower((string)$parts['host']);
        // Allowlist esatta per host. Nessun match parziale.
        $allowed = array_map('strtolower', $allowedHosts);
        if (!in_array($host, $allowed, true)) {
            throw new \RuntimeException('ollama_host_not_allowed');
        }
        // Ricostruisce un URL pulito (scheme://host[:port]) senza path/query.
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }
}
