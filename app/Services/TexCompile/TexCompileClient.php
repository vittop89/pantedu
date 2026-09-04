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
        private readonly int $timeoutSeconds = 35,
        private readonly string $caBundle = '',
    ) {
        if ($this->endpoint === '' || $this->secret === '') {
            throw new \InvalidArgumentException('TexCompileClient: endpoint e secret obbligatori.');
        }
        if (str_ends_with($this->endpoint, '/')) {
            throw new \InvalidArgumentException('TexCompileClient: endpoint senza trailing slash.');
        }
    }

    /**
     * G22.S15.bis Fase 5+ — Factory standard: legge config tex_compile.*
     * e costruisce il client con timeout default. Lancia eccezione se
     * endpoint/secret non configurati (callers usano tryDefault() per
     * gestione graceful).
     */
    public static function default(int $timeoutSeconds = 35): self
    {
        $endpoint = (string)\App\Core\Config::get('tex_compile.endpoint', '');
        $secret   = (string)\App\Core\Config::get('tex_compile.secret', '');
        $caBundle = (string)\App\Core\Config::get('tex_compile.ca_bundle', '');
        return new self(rtrim($endpoint, '/'), $secret, $timeoutSeconds, $caBundle);
    }

    /**
     * Variante non-throwing: ritorna null se config mancante.
     * Comodo per i controller che vogliono ritornare 503 graceful.
     */
    public static function tryDefault(int $timeoutSeconds = 35): ?self
    {
        $endpoint = (string)\App\Core\Config::get('tex_compile.endpoint', '');
        $secret   = (string)\App\Core\Config::get('tex_compile.secret', '');
        if ($endpoint === '' || $secret === '') {
            return null;
        }
        $caBundle = (string)\App\Core\Config::get('tex_compile.ca_bundle', '');
        return new self(rtrim($endpoint, '/'), $secret, $timeoutSeconds, $caBundle);
    }

    /**
     * Compila .tex e restituisce array con esito.
     *
     * Modalità default: risposta application/pdf binaria.
     *   Ritorna: ['ok', 'pdf', 'log', 'http_status', 'duration_ms', 'engine']
     *
     * Modalità $withArtifacts=true: risposta JSON con SyncTeX + log + warnings.
     *   Ritorna: ['ok', 'pdf', 'synctex_gz', 'aux', 'fls', 'log', 'warnings',
     *             'errors', 'http_status', 'duration_ms', 'engine']
     *
     * @return array<string,mixed>
     */
    public function compile(
        string $texSource,
        string $docId,
        string $engine = 'pdflatex',
        int $passes = 2,
        bool $withArtifacts = false,
    ): array {
        $payload = json_encode([
            'tex_b64' => base64_encode($texSource),
            'doc_id'  => $docId,
            'engine'  => $engine,
            'passes'  => $passes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);

        $url = $this->endpoint . '/compile' . ($withArtifacts ? '?with_artifacts=1' : '');
        $ch = curl_init($url);
        $opts = [
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
        ];
        // Windows/XAMPP fix: cURL non trova CA store di sistema → punta a bundle locale.
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

        // Modalità artifacts: response sempre JSON (anche su errore 422).
        if ($withArtifacts) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return [
                    'ok'          => false,
                    'pdf'         => null,
                    'log'         => "Risposta JSON malformata (HTTP $status)",
                    'http_status' => $status,
                    'duration_ms' => $duration,
                    'engine'      => $engineUsed,
                ];
            }
            $isOk = (bool)($decoded['ok'] ?? false);
            // G22.S16 — formatted_files (latexindent), single-file usa 'doc.tex'.
            $formattedFiles = [];
            $rawFmt = $decoded['formatted_files_b64'] ?? null;
            if (is_array($rawFmt)) {
                foreach ($rawFmt as $path => $b64) {
                    if (!is_string($path) || !is_string($b64)) {
                        continue;
                    }
                    $decodedContent = base64_decode($b64, true);
                    if ($decodedContent !== false) {
                        $formattedFiles[$path] = $decodedContent;
                    }
                }
            }
            return [
                'ok'           => $isOk,
                'pdf'          => $isOk && isset($decoded['pdf_b64'])
                    ? base64_decode((string)$decoded['pdf_b64'])
                    : null,
                'synctex_gz'   => isset($decoded['synctex_gz_b64'])
                    ? base64_decode((string)$decoded['synctex_gz_b64'])
                    : null,
                'aux'          => (string)($decoded['aux'] ?? ''),
                'fls'          => (string)($decoded['fls'] ?? ''),
                'log'          => (string)($decoded['log'] ?? ''),
                'warnings'     => is_array($decoded['warnings'] ?? null) ? $decoded['warnings'] : [],
                'errors'       => is_array($decoded['errors'] ?? null)   ? $decoded['errors']   : [],
                'http_status'  => $status,
                'duration_ms'  => $duration ?? (int)($decoded['duration_ms'] ?? 0),
                'engine'       => $engineUsed ?? (string)($decoded['engine'] ?? ''),
                'pdf_bytes'    => (int)($decoded['pdf_bytes'] ?? 0),
                'synctex_bytes' => (int)($decoded['synctex_bytes'] ?? 0),
                'formatted_files' => $formattedFiles,
            ];
        }

        // Modalità default: PDF binario su success, JSON su errore.
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
     * G22.S4.B.3 — Compila un bundle LaTeX multi-file.
     *
     * Invia il bundle al endpoint `/compile-bundle` del VPS che materializza
     * i file in tmpdir e compila `main_path` (con `\input{texCommon/...}` e
     * `\input{griglie/...}` risolti via filesystem invece che inline).
     *
     * @param list<array{path:string, content:string}> $files Bundle da compilare.
     *        `path` e' relativo al bundle root (es. "versioni/main_NOR.tex",
     *        "texCommon/verifica.sty"). `content` e' il plaintext (Service
     *        decifra dalla manifest tex_files prima di chiamare).
     * @param string $mainPath File principale da compilare (es. "versioni/main_NOR.tex").
     * @param string $docId Identificativo opaco per VPS logging.
     *
     * Stesso shape di risposta di `compile()`: PDF binario su success
     * default, JSON su errore. Con $withArtifacts=true ritorna JSON con
     * synctex_gz / aux / fls / log / warnings / errors.
     *
     * @return array<string,mixed>
     */
    public function compileBundle(
        array $files,
        string $mainPath,
        string $docId,
        string $engine = 'pdflatex',
        int $passes = 2,
        bool $withArtifacts = false,
    ): array {
        $payloadFiles = [];
        foreach ($files as $f) {
            if (!isset($f['path'], $f['content'])) {
                throw new \InvalidArgumentException('compileBundle: file deve avere {path, content}');
            }
            $payloadFiles[] = [
                'path'        => (string)$f['path'],
                'content_b64' => base64_encode((string)$f['content']),
            ];
        }
        if (!$payloadFiles) {
            throw new \InvalidArgumentException('compileBundle: bundle vuoto');
        }

        $payload = json_encode([
            'files'     => $payloadFiles,
            'main_path' => $mainPath,
            'doc_id'    => $docId,
            'engine'    => $engine,
            'passes'    => $passes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);

        $url = $this->endpoint . '/compile-bundle' . ($withArtifacts ? '?with_artifacts=1' : '');
        $ch = curl_init($url);
        $opts = [
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
        ];
        if ($this->caBundle !== '' && is_file($this->caBundle)) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $opts);

        $rawResponse = curl_exec($ch);
        $errno      = curl_errno($ch);
        $err        = curl_error($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
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
        $duration    = isset($headers['x-compile-duration-ms'])
            ? (int)$headers['x-compile-duration-ms'] : null;
        $engineUsed  = $headers['x-compile-engine'] ?? null;

        if ($withArtifacts) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return [
                    'ok'          => false,
                    'pdf'         => null,
                    'log'         => "Risposta JSON malformata (HTTP $status)",
                    'http_status' => $status,
                    'duration_ms' => $duration,
                    'engine'      => $engineUsed,
                ];
            }
            $isOk = (bool)($decoded['ok'] ?? false);
            // G22.S16 — formatted_files (latexindent output): decode base64.
            $formattedFiles = [];
            $rawFmt = $decoded['formatted_files_b64'] ?? null;
            if (is_array($rawFmt)) {
                foreach ($rawFmt as $path => $b64) {
                    if (!is_string($path) || !is_string($b64)) {
                        continue;
                    }
                    $decodedContent = base64_decode($b64, true);
                    if ($decodedContent !== false) {
                        $formattedFiles[$path] = $decodedContent;
                    }
                }
            }
            return [
                'ok'           => $isOk,
                'pdf'          => $isOk && isset($decoded['pdf_b64'])
                    ? base64_decode((string)$decoded['pdf_b64']) : null,
                'synctex_gz'   => isset($decoded['synctex_gz_b64'])
                    ? base64_decode((string)$decoded['synctex_gz_b64']) : null,
                'aux'          => (string)($decoded['aux'] ?? ''),
                'fls'          => (string)($decoded['fls'] ?? ''),
                'log'          => (string)($decoded['log'] ?? ''),
                'warnings'     => is_array($decoded['warnings'] ?? null) ? $decoded['warnings'] : [],
                'errors'       => is_array($decoded['errors'] ?? null)   ? $decoded['errors']   : [],
                'http_status'  => $status,
                'duration_ms'  => $duration ?? (int)($decoded['duration_ms'] ?? 0),
                'engine'       => $engineUsed ?? (string)($decoded['engine'] ?? ''),
                'pdf_bytes'    => (int)($decoded['pdf_bytes'] ?? 0),
                'synctex_bytes' => (int)($decoded['synctex_bytes'] ?? 0),
                'formatted_files' => $formattedFiles,
            ];
        }

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

        $log = $body;
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['log'])) {
            $log = (string)$decoded['log'];
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
     * G21.2 — Reverse SyncTeX via binario nativo `synctex` sul VPS.
     *
     * Proxy del POST /synctex/edit. Riceve synctex.gz blob + (page, x, y)
     * coordinate PDF, ritorna {file, line, column} dal binario synctex.
     *
     * @return array{ok:bool, file?:string, line?:int, column?:int, error?:string}
     */
    public function synctexEdit(string $synctexGz, int $page, float $x, float $y): array
    {
        $payload = json_encode([
            'synctex_gz_b64' => base64_encode($synctexGz),
            'page'           => $page,
            'x'              => $x,
            'y'              => $y,
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);

        $ch = curl_init($this->endpoint . '/synctex/edit');
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Timestamp: ' . $timestamp,
                'X-Signature: ' . $signature,
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($this->caBundle !== '' && is_file($this->caBundle)) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $stat = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $stat !== 200) {
            return ['ok' => false, 'error' => "synctex VPS HTTP {$stat}: " . substr((string)$body ?: $err, 0, 200)];
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'JSON malformato'];
        }
        return $decoded;
    }

    /**
     * Health check liveness — utile per dashboard/monitoring.
     */
    public function health(): bool
    {
        $ch = curl_init($this->endpoint . '/health');
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($this->caBundle !== '' && is_file($this->caBundle)) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $opts);
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
