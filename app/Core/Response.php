<?php

namespace App\Core;

final class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function file(string $absolutePath, int $status = 200): self
    {
        $r = new self('', $status);
        $r->headers['X-Serve-File'] = $absolutePath;
        return $r;
    }

    /**
     * Phase 17 — ETag-based conditional response. Imposta il header `ETag` +
     * `Cache-Control`. Se il client invia `If-None-Match` combaciante,
     * ritorna 304 Not Modified con body vuoto (risparmia banda + render).
     *
     * Uso:
     *   return Response::json($data)->withETag($row['updated_at'] . ':' . $row['version']);
     *
     * `$token` è hashato → l'header valore reale è `"<hash>"` (quoted).
     * `$maxAge` in secondi per Cache-Control (default 0 = revalidate ogni volta).
     */
    public function withETag(string $token, int $maxAge = 0): self
    {
        $etag = '"' . substr(hash('sha256', $token), 0, 27) . '"';
        $this->headers['ETag'] = $etag;
        $this->headers['Cache-Control'] = sprintf('private, max-age=%d, must-revalidate', $maxAge);
        $clientTag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($clientTag && trim($clientTag) === $etag) {
            $this->status = 304;
            $this->body = '';
        }
        return $this;
    }

    /** Helper: no-cache per risposte volatili/sensibili. */
    public function withNoCache(): self
    {
        $this->headers['Cache-Control'] = 'no-store, no-cache, must-revalidate';
        $this->headers['Pragma'] = 'no-cache';
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            if ($k === 'X-Serve-File') {
                continue;
            }
            header("$k: $v");
        }

        if (!empty($this->headers['X-Serve-File']) && is_file($this->headers['X-Serve-File'])) {
            $this->serveFile($this->headers['X-Serve-File']);
            return;
        }
        echo $this->body;
    }

    private function serveFile(string $path): void
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'php') {
            chdir(dirname($path));
            require $path;
            return;
        }
        $mime = match ($ext) {
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'json' => 'application/json',
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',
            'jpg','jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
            'pdf'  => 'application/pdf',
            'zip'  => 'application/zip',
            'html' => 'text/html; charset=UTF-8',
            default=> 'application/octet-stream',
        };
        header("Content-Type: $mime");
        readfile($path);
    }
}
