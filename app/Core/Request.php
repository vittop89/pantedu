<?php

namespace App\Core;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    public readonly array $query;
    public readonly array $post;
    public readonly array $server;
    public readonly array $headers;

    /** Corpo grezzo della richiesta; null = non ancora letto da php://input. */
    private ?string $rawBody;

    /**
     * @param string|null $rawBody Solo per i test: corpo gia' noto, cosi' non
     *                             si legge php://input (vuoto da CLI).
     */
    public function __construct(?string $rawBody = null)
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri           = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path    = '/' . trim(parse_url($uri, PHP_URL_PATH) ?? '/', '/');
        $this->query   = $_GET  ?? [];
        $this->post    = $_POST ?? [];
        $this->server  = $_SERVER;
        $this->headers = self::parseHeaders();
        $this->rawBody = $rawBody;
    }

    /**
     * Corpo grezzo (php://input), letto una volta sola.
     *
     * Revisione 2026-09, P4: 37 controller leggevano php://input per conto
     * loro, con otto copie di `readJsonBody()`. Da qui in poi il corpo lo
     * espone la Request: `json()` per le API JSON, `body()` per le rotte
     * che accettano sia JSON sia form.
     */
    public function rawBody(): string
    {
        if ($this->rawBody === null) {
            $this->rawBody = (string)@file_get_contents('php://input');
        }
        return $this->rawBody;
    }

    /**
     * Il tetto predefinito del corpo letto come JSON: quello di nginx
     * (`client_max_body_size 128M`, docker/nginx.conf) e di `post_max_size`.
     * Oltre, il corpo non arriverebbe comunque a PHP intero, e da qui si dice
     * 413 invece di vederlo vuoto. Le rotte che ne vogliono di meno lo passano
     * a `json()`, `body()` o `jsonObbligatorio()` (23/9/2026, A-52).
     */
    public const LIMITE_CORPO = 128 * 1024 * 1024;

    /**
     * Un corpo oltre `$limite` byte: 413, sempre. Si guarda prima
     * Content-Length, che basta a rifiutare senza leggere; poi la lunghezza
     * vera, per chi non la dichiara.
     *
     * @throws CorpoNonValido payload_too_large
     */
    private function entroIlLimite(int $limite): void
    {
        $dichiarata = (int)($this->server['CONTENT_LENGTH'] ?? 0);
        if ($dichiarata > $limite || \strlen($this->rawBody()) > $limite) {
            throw CorpoNonValido::troppoGrande();
        }
    }

    /** True se il client ha dichiarato Content-Type: application/json. */
    public function isJson(): bool
    {
        $ct = (string)($this->server['CONTENT_TYPE'] ?? $this->headers['content-type'] ?? '');
        return str_contains(strtolower($ct), 'application/json');
    }

    /**
     * Corpo decodificato da JSON; [] se vuoto, non JSON o non un oggetto/array
     * (stessa tolleranza del vecchio `json_decode(...) ?: []`). Oltre
     * `$limite` byte lancia CorpoNonValido con 413 (dal 23/9/2026: prima
     * nessun limite).
     *
     * @return array<mixed>
     * @throws CorpoNonValido payload_too_large
     */
    public function json(int $limite = self::LIMITE_CORPO): array
    {
        $this->entroIlLimite($limite);
        $raw = $this->rawBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Il corpo JSON che la rotta richiede: un oggetto o un array, entro
     * `$limite` byte. Al posto delle copie di `readJsonBody()` (A-52).
     *
     * Non tollera niente: corpo vuoto → `empty_payload` (400), JSON rotto o
     * uno scalare → `invalid_json` (400), troppo grande → `payload_too_large`
     * (413). La risposta la porta l'eccezione (CorpoNonValido).
     *
     * @return array<mixed>
     * @throws CorpoNonValido
     */
    public function jsonObbligatorio(int $limite = self::LIMITE_CORPO): array
    {
        $this->entroIlLimite($limite);
        return self::decodificaJson($this->rawBody(), $limite);
    }

    /**
     * La regola di `jsonObbligatorio()` su un testo che non è il corpo: un
     * campo di un form che porta JSON (il `selection` di /teacher/print).
     *
     * @return array<mixed>
     * @throws CorpoNonValido
     */
    public static function decodificaJson(string $testo, int $limite = self::LIMITE_CORPO): array
    {
        if (\strlen($testo) > $limite) {
            throw CorpoNonValido::troppoGrande();
        }
        if (trim($testo) === '') {
            throw CorpoNonValido::vuoto();
        }
        try {
            $decoded = json_decode($testo, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw CorpoNonValido::jsonRotto();
        }
        if (!is_array($decoded)) {
            throw CorpoNonValido::jsonRotto();
        }
        return $decoded;
    }

    /**
     * Corpo della richiesta come array, JSON o form: JSON se il Content-Type
     * lo dichiara; altrimenti i campi POST; altrimenti, se il corpo "sembra"
     * JSON (inizia con { o [), lo decodifica. [] se non c'e' nulla. Il limite
     * vale per il corpo intero, JSON o form.
     *
     * @return array<mixed>
     * @throws CorpoNonValido payload_too_large
     */
    public function body(int $limite = self::LIMITE_CORPO): array
    {
        $this->entroIlLimite($limite);
        if ($this->isJson()) {
            return $this->json($limite);
        }
        if ($this->post !== []) {
            return $this->post;
        }
        $raw = ltrim($this->rawBody());
        if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '[')) {
            return $this->json($limite);
        }
        return [];
    }

    private static function parseHeaders(): array
    {
        $h = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($k, 5)));
                $h[$name] = $v;
            }
        }
        return $h;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function wantsJson(): bool
    {
        $accept = $this->headers['accept'] ?? '';
        return str_contains($accept, 'application/json')
            || ($this->headers['x-requested-with'] ?? '') === 'XMLHttpRequest';
    }
}
