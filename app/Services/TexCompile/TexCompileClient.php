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
        $caBundle = \App\Support\BundleCa::percorso() ?? '';
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
        $caBundle = \App\Support\BundleCa::percorso() ?? '';
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
        // Il bundle delle CA, se c'è (App\Support\BundleCa); senza, quello di curl.
        if ($this->caBundle !== '' && is_file($this->caBundle)) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $opts);

        $rawResponse = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // Prima di curl_close(): dopo, l'handle non dice piu' niente.
        $connesso = TexIrraggiungibile::connesso($ch);
        curl_close($ch);

        if ($errno !== 0 || $rawResponse === false) {
            // L'errore di cURL (curl_error) porta l'indirizzo interno del servizio:
            // va nel registro, non al docente (TexIrraggiungibile).
            return [
                'ok'          => false,
                'pdf'         => null,
                'log'         => TexIrraggiungibile::segnala($errno, $this->endpoint, 'compile', $connesso),
                'http_status' => 0,
                'duration_ms' => null,
                'engine'      => null,
                // Connessione stabilita e tempo scaduto: il servizio sta ancora
                // compilando, non e' irraggiungibile. Chi chiama non deve
                // ritentare (VerificaCompileController).
                'tempo_scaduto' => TexIrraggiungibile::compilazioneTroppoLunga($errno, $connesso),
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
                'log'          => self::logFromDecoded($decoded),
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
        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $connesso   = TexIrraggiungibile::connesso($ch);
        curl_close($ch);

        if ($errno !== 0 || $rawResponse === false) {
            return [
                'ok'          => false,
                'pdf'         => null,
                'log'         => TexIrraggiungibile::segnala($errno, $this->endpoint, 'compile-bundle', $connesso),
                'http_status' => 0,
                'duration_ms' => null,
                'engine'      => null,
                'tempo_scaduto' => TexIrraggiungibile::compilazioneTroppoLunga($errno, $connesso),
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
                'log'          => self::logFromDecoded($decoded),
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

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $stat  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $connesso = TexIrraggiungibile::connesso($ch);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            return ['ok' => false, 'error' => TexIrraggiungibile::segnala($errno, $this->endpoint, 'synctex', $connesso)];
        }
        if ($stat !== 200) {
            return ['ok' => false, 'error' => "synctex VPS HTTP {$stat}: " . substr((string)$body, 0, 200)];
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'JSON malformato'];
        }
        return $decoded;
    }

    /**
     * `host:porta` del servizio, per la diagnostica e i registri interni. Mai
     * in una risposta pubblica (/health/tex non lo usa).
     */
    public function servizio(): string
    {
        return TexIrraggiungibile::servizio($this->endpoint);
    }

    /** Il servizio risponde? La sonda, ridotta a un sì o un no. */
    public function health(): bool
    {
        return $this->sonda()['ok'];
    }

    /**
     * Sonda il servizio: risponde, e se no perché (19/9/2026).
     *
     * La usano /health/tex, la diagnostica (`tex`) e, per la stessa domanda
     * fatta da fuori, il rilascio. Dall'8 settembre 2026 il container non
     * raggiungeva il TeX e nessun controllo lo guardava: `health()` c'era, ma
     * non la chiamava nessuno, e senza un tetto al tempo di connessione un
     * firewall che scarta i pacchetti l'avrebbe tenuta appesa.
     *
     * Non registra anomalie: è un'osservazione, e /health/tex è pubblico.
     *
     * `ok` vuole il 200 **e** il corpo di GET /health del servizio
     * (`{"status":"ok",…}`): un altro programma sulla stessa porta risponde, ma
     * non è il TeX.
     *
     * @return array{ok: bool, errno: int, http: int, ms: int, classe: string}
     *   `classe` è vuota se ok, altrimenti un nome da TexIrraggiungibile::classe()
     *   o «risposta inattesa».
     */
    public function sonda(int $connessioneSecondi = 3, int $totaleSecondi = 5): array
    {
        $inizio = microtime(true);
        $ch = curl_init($this->endpoint . '/health');
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connessioneSecondi,
            CURLOPT_TIMEOUT        => $totaleSecondi,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($this->caBundle !== '' && is_file($this->caBundle)) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $opts);
        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $ms = (int) round((microtime(true) - $inizio) * 1000);

        if ($errno !== 0 || $body === false) {
            return ['ok' => false, 'errno' => $errno, 'http' => 0, 'ms' => $ms, 'classe' => TexIrraggiungibile::classe($errno)];
        }
        $dati = json_decode((string)$body, true);
        $delServizio = \is_array($dati) && (($dati['status'] ?? null) === 'ok' || ($dati['ok'] ?? null) === true);
        $ok = $status === 200 && $delServizio;
        return ['ok' => $ok, 'errno' => 0, 'http' => $status, 'ms' => $ms, 'classe' => $ok ? '' : 'risposta inattesa'];
    }

    /**
     * @return array<string,string>
     */
    /**
     * Log dalla risposta JSON del servizio. Se il servizio ha rifiutato la
     * richiesta prima di compilare (validazione FastAPI: 422 con `detail`,
     * niente `log`), il motivo finisce comunque nel log invece di sparire
     * (2026-09-05: «tex_compile_failed» con log vuoto non diceva nulla).
     *
     * @param array<string, mixed> $decoded
     */
    private static function logFromDecoded(array $decoded): string
    {
        $log = (string)($decoded['log'] ?? '');
        if ($log === '' && isset($decoded['detail'])) {
            $log = 'Il servizio TeX ha rifiutato la richiesta: ' . self::detailToText($decoded['detail']);
        }
        return $log;
    }

    /**
     * Il `detail` di FastAPI è una stringa oppure, per gli errori di validazione
     * (pydantic), una lista di {loc, msg, input, …}: si tengono posizione e
     * messaggio, mai l'input (che ripete il bundle in base64).
     */
    private static function detailToText(mixed $detail): string
    {
        if (\is_string($detail)) {
            return $detail;
        }
        if (\is_array($detail)) {
            $lines = [];
            foreach ($detail as $err) {
                if (\is_array($err) && isset($err['msg'])) {
                    $loc = \is_array($err['loc'] ?? null) ? implode('.', array_map('strval', $err['loc'])) : '';
                    $lines[] = ($loc !== '' ? $loc . ': ' : '') . (string)$err['msg'];
                }
            }
            if ($lines !== []) {
                return implode('; ', $lines);
            }
        }
        return (string)json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

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
