<?php

declare(strict_types=1);

/**
 * Phase 26 — OpenAPI 3.1 spec generator from routes/web.php.
 *
 * Strategy: parse routes/web.php via regex (no Router runtime), extract
 * method/path/controller/middleware. Output YAML scaffold to be enriched
 * manually with request/response schemas for critical endpoints.
 *
 * Usage:
 *   php tools/api/generate_openapi.php > docs/api/openapi.yaml
 *
 * Re-run after route changes; the manual annotations on critical paths
 * are preserved by hand-merging from docs/api/openapi.yaml.manual.yaml.
 */

require __DIR__ . '/../../vendor/autoload.php';

$routesFile = __DIR__ . '/../../routes/web.php';
$content = file_get_contents($routesFile) ?: '';

// Match: $router-> OR $r-> (grouped)
//   ->method('/path', [Controller::class, 'method'])->middleware('a','b')...
//
// Note: group prefix extraction skipped intentionally — i route nei gruppi
// (`$router->group(['prefix' => '/x'], function ($r) { ... })`) sono per la
// maggior parte LegacyController che NON è API REST. L'OpenAPI copre i route
// applicativi di primo livello + grouped che già hanno path assoluto.
$pattern = '/\$(?:router|r)->(get|post|put|patch|delete|any)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\[\s*\\\\?([A-Za-z\\\\_0-9]+)::class\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]\s*\)((?:\s*->middleware\([^)]+\))?)/m';

preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

$routes = [];
foreach ($matches as $m) {
    $method     = strtolower($m[1]);
    $path       = $m[2];
    $controller = ltrim($m[3], '\\');
    $action     = $m[4];
    $mwBlock    = $m[5];

    // Extract middleware names
    $middleware = [];
    if (preg_match_all("/'([a-z_:,0-9]+)'/", $mwBlock, $mwMatches)) {
        $middleware = $mwMatches[1];
    }

    $methods = $method === 'any'
        ? ['get', 'post', 'put', 'patch', 'delete']
        : [$method];

    foreach ($methods as $verb) {
        $routes[] = [
            'method'     => $verb,
            'path'       => $path,
            'controller' => $controller,
            'action'     => $action,
            'middleware' => $middleware,
        ];
    }
}

// Group by path → operations per method
$paths = [];
foreach ($routes as $r) {
    $oapiPath = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '{$1}', $r['path']);
    $paths[$oapiPath][$r['method']] = $r;
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
    if (str_starts_with($path, '/api/copilot'))                          return 'Copilot AI';
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
$out[] = "    REST API per Pantedu — piattaforma educativa di didattica della fisica.";
$out[] = "";
$out[] = "    **Generato automaticamente** da `tools/api/generate_openapi.php`. Le";
$out[] = "    annotazioni request/response schema sui 30 endpoint critici sono manuali";
$out[] = "    (sezione `paths` di `docs/api/openapi.yaml`).";
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
$out[] = "  - url: http://pantedu.local";
$out[] = "    description: Local dev (XAMPP)";
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
$out[] = "        role: { type: string, enum: [guest, student, teacher, collaborator, administrator] }";
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

// Verbi HTTP standard supportati da OpenAPI
$openapiVerbs = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'];

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
        if (!in_array($verb, $openapiVerbs, true)) continue;
        $tag        = $tagFor($info['path'], $info['controller']);
        $opId       = sprintf('%s%s', strtolower($info['action']), basename(str_replace('\\', '/', $info['controller'])));
        $opId       = preg_replace('/[^A-Za-z0-9]/', '', $opId);
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
        $out[] = "      summary: " . yamlString(humanize($info['action']) . ' (' . shortName($info['controller']) . ')');
        $out[] = "      x-controller: " . shortName($info['controller']) . '::' . $info['action'];
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
