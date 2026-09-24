<?php

declare(strict_types=1);

/**
 * Phase 26 — OpenAPI 3.1 spec generator from routes/web.php.
 *
 * Strategia (corretta il 2026-09-23, DOC-2): non più una regex sul testo di
 * `routes/web.php`, che riconosceva solo `$router->` e `$r->` e saltava di
 * proposito i gruppi annidati (`$rr->`, `$rrr->`) e ogni handler che non
 * fosse letteralmente `[Controller::class, 'metodo']` (le chiusure, come
 * `$legacyGoneHandler`). Qui si carica il vero `App\Core\Router`: si crea
 * un'istanza e si include `routes/web.php`, esattamente come fa
 * `tests/Unit/Core/CoperturaCsrfTest.php` per la copertura CSRF. Il router è
 * un'unica istanza condivisa da tutti i gruppi, qualunque sia il nome che il
 * closure dà al parametro (`$r`, `$rr`, `$rrr`, ...): `routes()` restituisce
 * tutte le rotte dichiarate, con verbo, path assoluto (prefissi dei gruppi
 * già applicati) e handler vero.
 *
 * Una sola combinazione verbo+path risulta dichiarata due volte nel sito:
 * `GET /risdoc/{path*}` (una volta come rotta viva del docente, una volta
 * come `legacy_gone` dell'amministratore). Il Router vero fa vincere la
 * *prima* dichiarazione (`Router::match()` scorre l'elenco e si ferma alla
 * prima che combacia): qui si fa lo stesso con `??=`, così la spec descrive
 * il comportamento che il sito ha davvero, non l'ultima riga del file.
 *
 * Usage:
 *   php tools/api/generate_openapi.php > docs/api/openapi.yaml
 *
 * Re-run after route changes; the manual annotations on critical paths
 * are preserved by hand-merging from docs/api/openapi.overlay.yaml.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Core\Router;

$routesFile = __DIR__ . '/../../routes/web.php';

$router = new Router();
require $routesFile;

/** I soli verbi che la spec documenta come operazioni distinte. */
const VERBI_DOCUMENTATI = ['get', 'post', 'put', 'patch', 'delete'];

$routes = [];
foreach ($router->routes() as $route) {
    [$controller, $action] = handlerInfo($route->handler);

    $verbiRotta = array_map('strtolower', $route->methods);
    foreach (VERBI_DOCUMENTATI as $verb) {
        if (!in_array($verb, $verbiRotta, true)) {
            continue;
        }
        $routes[] = [
            'method'     => $verb,
            'path'       => $route->pattern,
            'controller' => $controller,
            'action'     => $action,
            'middleware' => $route->middleware,
        ];
    }
}

/**
 * @param mixed $handler come dichiarato in routes/web.php: `[Class::class,
 *   'metodo']`, oppure una chiusura (le rotte `legacy_gone`, i redirect, le
 *   pagine servite da un `fn()` inline).
 * @return array{0: string, 1: string} controller, action
 */
function handlerInfo(mixed $handler): array
{
    if (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1])) {
        return [ltrim($handler[0], '\\'), $handler[1]];
    }
    return ['(closure)', 'handler'];
}

// Group by path → operations per method. `??=`: la prima dichiarazione (quella
// che il Router vero farebbe vincere) resta, una successiva sullo stesso
// verbo+path non la sovrascrive silenziosamente.
$paths = [];
foreach ($routes as $r) {
    $oapiPath = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)[*?]?\}/', '{$1}', $r['path']);
    $paths[$oapiPath][$r['method']] ??= $r;
}

ksort($paths);

// Tag classification heuristic
$tagFor = static function (string $path, string $controller): string {
    if (str_starts_with($path, '/me/'))                                  return 'GDPR self-service (Art. 7/15-22)';
    if (str_starts_with($path, '/parent-consent'))                       return 'GDPR parent consent (Art. 8)';
    if (str_starts_with($path, '/dpo-contact'))                          return 'GDPR DPO contact (Art. 12-22, 37-39)';
    if (str_starts_with($path, '/api/teacher/content'))                  return 'Teacher Content';
    if (str_starts_with($path, '/api/risdoc'))                           return 'Risdoc Templates';
    if (str_starts_with($path, '/api/admin'))                            return 'Admin';
    if (str_starts_with($path, '/api/'))                                 return 'API Public';
    if (str_starts_with($path, '/login') || str_starts_with($path, '/auth')) return 'Auth';
    if (str_starts_with($path, '/register'))                             return 'Registration';
    if (str_starts_with($path, '/admin'))                                return 'Admin UI';
    if ($path === '/metrics')                                            return 'Observability';
    if (str_starts_with($path, '/security') || str_starts_with($path, '/privacy')) return 'Trust Pages';
    if (str_starts_with($path, '/studio'))                               return 'Studio';
    if (str_starts_with($path, '/risdoc'))                               return 'Risdoc';
    if (str_starts_with($path, '/storage'))                              return 'Storage';
    if (str_starts_with($path, '/eser') || str_starts_with($path, '/lab') || str_starts_with($path, '/map')) return 'Legacy Education';
    return 'Misc';
};

// Render YAML
$out = [];
$out[] = "openapi: 3.1.0";
$out[] = "info:";
$out[] = "  title: Pantedu API";
$out[] = "  version: 1.0.0";
$out[] = "  description: |";
$out[] = "    REST API per Pantedu, la piattaforma didattica.";
$out[] = "";
$out[] = "    **Generato automaticamente** da `tools/api/generate_openapi.php`, che";
$out[] = "    legge le rotte dal vero `App\\Core\\Router` (non da un'analisi del";
$out[] = "    testo): copre ogni rotta dichiarata in `routes/web.php`, a qualunque";
$out[] = "    livello di gruppo annidato. Le annotazioni request/response schema sui";
$out[] = "    endpoint critici restano manuali (overlay, sezione `paths` di";
$out[] = "    `docs/api/openapi.full.yaml`); il resto è scheletro (path, verbo,";
$out[] = "    handler, middleware) senza schema di dettaglio.";
$out[] = "";
$out[] = "    Auth: session cookie (PANTEDU_SID, httponly, samesite=Lax) o Bearer";
$out[] = "    token solo su `/metrics`.";
$out[] = "  contact:";
$out[] = "    name: DPO Pantedu";
$out[] = "    url: https://pantedu.eu/dpo-contact";
$out[] = "  license:";
$out[] = "    name: Proprietary";
$out[] = "servers:";
$out[] = "  - url: https://www.pantedu.eu";
$out[] = "    description: Production";
$out[] = "  - url: http://127.0.0.1:8765";
$out[] = "    description: Local dev (WSL, tools/dev/wsl/server.sh)";
$out[] = "";
$out[] = "tags:";
$tagSet = [];
foreach ($routes as $r) {
    $tagSet[$tagFor($r['path'], $r['controller'])] = true;
}
foreach (array_keys($tagSet) as $t) {
    $out[] = "  - name: " . yamlString($t);
}
$out[] = "";
$out[] = "components:";
$out[] = "  securitySchemes:";
$out[] = "    sessionAuth:";
$out[] = "      type: apiKey";
$out[] = "      in: cookie";
$out[] = "      name: PANTEDU_SID";
$out[] = "      description: Session cookie httponly + samesite=Lax + secure (prod).";
$out[] = "    bearerAuth:";
$out[] = "      type: http";
$out[] = "      scheme: bearer";
$out[] = "      description: Bearer token (solo su `/metrics`, env METRICS_BEARER_TOKEN).";
$out[] = "    csrfHeader:";
$out[] = "      type: apiKey";
$out[] = "      in: header";
$out[] = "      name: X-CSRF-Token";
$out[] = "      description: |";
$out[] = "        Token CSRF richiesto su POST/PUT/PATCH/DELETE. Recuperabile via";
$out[] = "        GET /auth/csrf. Alternativa: campo form `_csrf`.";
$out[] = "  schemas:";
$out[] = "    Error:";
$out[] = "      type: object";
$out[] = "      required: [error]";
$out[] = "      properties:";
$out[] = "        error: { type: string, example: invalid_credentials }";
$out[] = "        retry_after: { type: integer, description: Seconds before retry (rate limit) }";
$out[] = "    Success:";
$out[] = "      type: object";
$out[] = "      properties:";
$out[] = "        ok: { type: boolean, example: true }";
$out[] = "    User:";
$out[] = "      type: object";
$out[] = "      properties:";
$out[] = "        id: { type: integer }";
$out[] = "        username: { type: string }";
$out[] = "        role: { type: string, enum: [guest, student, teacher, institute_admin, administrator] }";
$out[] = "        email: { type: string, format: email }";
$out[] = "        first_name: { type: string }";
$out[] = "        last_name: { type: string }";
$out[] = "        active: { type: boolean }";
$out[] = "    Consent:";
$out[] = "      type: object";
$out[] = "      properties:";
$out[] = "        consent_type: { type: string, enum: [analytics, marketing, parent_8_minor] }";
$out[] = "        status: { type: string, enum: [granted, revoked] }";
$out[] = "        granted_at: { type: string, format: date-time }";
$out[] = "        revoked_at: { type: string, format: date-time, nullable: true }";
$out[] = "        text_version: { type: string }";
$out[] = "    DeletionRequest:";
$out[] = "      type: object";
$out[] = "      properties:";
$out[] = "        id: { type: integer }";
$out[] = "        status: { type: string, enum: [pending_confirm, cooling_off, executed, cancelled, expired] }";
$out[] = "        execute_after: { type: string, format: date-time, nullable: true }";
$out[] = "    TeacherContent:";
$out[] = "      type: object";
$out[] = "      properties:";
$out[] = "        id: { type: integer }";
$out[] = "        teacher_id: { type: integer }";
$out[] = "        content_type: { type: string, enum: [esercizio, verifica, lab, mappa, risdoc, bes] }";
$out[] = "        subject_code: { type: string, example: MAT }";
$out[] = "        indirizzo: { type: string, example: ITIA }";
$out[] = "        classe: { type: string, example: 3A }";
$out[] = "        topic: { type: string }";
$out[] = "        title: { type: string }";
$out[] = "        visibility: { type: string, enum: [draft, published, archived] }";
$out[] = "        metadata: { type: object, additionalProperties: true }";
$out[] = "        updated_at: { type: string, format: date-time }";
$out[] = "  responses:";
$out[] = "    Unauthorized:";
$out[] = "      description: Authentication required";
$out[] = "      content:";
$out[] = "        application/json:";
$out[] = "          schema: { \$ref: '#/components/schemas/Error' }";
$out[] = "    Forbidden:";
$out[] = "      description: CSRF token invalid or insufficient privileges";
$out[] = "      content:";
$out[] = "        application/json:";
$out[] = "          schema: { \$ref: '#/components/schemas/Error' }";
$out[] = "    NotFound:";
$out[] = "      description: Resource not found";
$out[] = "    RateLimited:";
$out[] = "      description: Rate limit exceeded";
$out[] = "      content:";
$out[] = "        application/json:";
$out[] = "          schema: { \$ref: '#/components/schemas/Error' }";
$out[] = "    ValidationError:";
$out[] = "      description: Invalid input (missing/malformed field)";
$out[] = "      content:";
$out[] = "        application/json:";
$out[] = "          schema: { \$ref: '#/components/schemas/Error' }";
$out[] = "";
$out[] = "security:";
$out[] = "  - sessionAuth: []";
$out[] = "";
$out[] = "paths:";

foreach ($paths as $path => $ops) {
    $out[] = "  " . yamlString($path) . ":";

    // Path parameters {id}, {token}, etc.
    if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $params)) {
        $out[] = "    parameters:";
        foreach ($params[1] as $p) {
            $type = inferParamType($p);
            $out[] = "      - name: $p";
            $out[] = "        in: path";
            $out[] = "        required: true";
            $out[] = "        schema: { type: $type }";
        }
    }

    foreach ($ops as $verb => $info) {
        $tag        = $tagFor($info['path'], $info['controller']);
        $isClosure  = $info['controller'] === '(closure)';
        $opId       = $isClosure
            ? 'closure' . preg_replace('/[^A-Za-z0-9]/', '', $verb . $path)
            : preg_replace('/[^A-Za-z0-9]/', '', strtolower($info['action']) . basename(str_replace('\\', '/', $info['controller'])));
        $summary    = $isClosure
            ? humanizePath($verb, $path)
            : humanize($info['action']) . ' (' . shortName($info['controller']) . ')';
        $authReq    = !str_starts_with($info['path'], '/login')
                   && !str_starts_with($info['path'], '/register')
                   && !str_starts_with($info['path'], '/parent-consent')
                   && !str_starts_with($info['path'], '/dpo-contact')
                   && $info['path'] !== '/curriculum'
                   && !str_starts_with($info['path'], '/security')
                   && !str_starts_with($info['path'], '/privacy/');
        $needsCsrf  = in_array('csrf', $info['middleware'], true);
        $rateMw     = array_filter($info['middleware'], fn($m) => str_starts_with($m, 'rate'));

        $out[] = "    $verb:";
        $out[] = "      tags: [" . yamlString($tag) . "]";
        $out[] = "      operationId: $opId";
        $out[] = "      summary: " . yamlString($summary);
        $out[] = "      x-controller: " . ($isClosure ? '(closure)' : shortName($info['controller']) . '::' . $info['action']);
        if ($info['middleware']) {
            $out[] = "      x-middleware: [" . implode(', ', array_map('yamlString', $info['middleware'])) . "]";
        }
        if (!$authReq) {
            $out[] = "      security: []  # Public endpoint";
        } elseif ($needsCsrf) {
            $out[] = "      security:";
            $out[] = "        - sessionAuth: []";
            $out[] = "          csrfHeader: []";
        }
        $out[] = "      responses:";
        $out[] = "        '200':";
        $out[] = "          description: Successful response";
        $out[] = "          content:";
        $out[] = "            application/json:";
        $out[] = "              schema: { \$ref: '#/components/schemas/Success' }";
        if ($authReq) {
            $out[] = "        '401': { \$ref: '#/components/responses/Unauthorized' }";
        }
        if ($needsCsrf) {
            $out[] = "        '403': { \$ref: '#/components/responses/Forbidden' }";
        }
        if ($rateMw) {
            $out[] = "        '429': { \$ref: '#/components/responses/RateLimited' }";
        }
        if ($verb === 'post' || $verb === 'put' || $verb === 'patch') {
            $out[] = "        '400': { \$ref: '#/components/responses/ValidationError' }";
        }
    }
}

$out[] = "";

echo implode("\n", $out);

function yamlString(string $s): string {
    if ($s === '' || preg_match('/[:#&*?,!|>%@`\\[\\]\\{\\}]/', $s) || strpos($s, ' ') !== false) {
        return "'" . str_replace("'", "''", $s) . "'";
    }
    return $s;
}

function inferParamType(string $name): string {
    if (preg_match('/^id$|_id$/', $name))      return 'integer';
    if ($name === 'token')                      return 'string';
    return 'string';
}

function humanize(string $action): string {
    return ucfirst(preg_replace('/(?<=[a-z])([A-Z])/', ' $1', $action));
}

function shortName(string $fqcn): string {
    $parts = explode('\\', $fqcn);
    return end($parts) ?: $fqcn;
}

/** Riassunto leggibile per le rotte servite da una chiusura (senza Controller::metodo). */
function humanizePath(string $verb, string $path): string {
    $parole = trim(str_replace(['/', '{', '}', '_', '-'], ' ', $path));
    $parole = trim(preg_replace('/\s+/', ' ', $parole));
    return strtoupper($verb) . ' ' . ($parole !== '' ? $parole : '/') . ' (closure)';
}
