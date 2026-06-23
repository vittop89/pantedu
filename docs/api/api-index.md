---
title: "Pantedu API — OpenAPI 3.1 spec"
date: 2026-04-28
last_reviewed: 2026-06-18
---

# Pantedu API documentation

OpenAPI 3.1 specification of all REST endpoints.

> ⚠️ **Spec potenzialmente stantia (revisione 2026-06-18)**: lo
> scheletro `openapi.yaml` è stato generato il **2026-04-28** e da allora
> sono state aggiunte nuove route (es. SPID/CIE `/auth/spid|cie/*`,
> PDF-import) non ancora riflesse. La copertura con annotazioni complete
> request/response resta bassa (vedi tabella "Stato copertura" sotto).
> **Da fare**: ri-eseguire `php tools/api/generate_openapi.php` per
> aggiornare lo scheletro, poi `merge_openapi.php`. Questa nota aggiorna
> solo lo stato; la spec NON è stata rigenerata in questa revisione.

## Files

| File | Generato? | Scopo |
|------|-----------|-------|
| `openapi.yaml` | ✅ Auto via `tools/api/generate_openapi.php` | Scheletro: 146 path / 187 operation, no request/response schema |
| `openapi.overlay.yaml` | ❌ Manuale | Annotazioni critiche (auth + GDPR + content + metrics — ~30 endpoint) |
| `openapi.full.yaml` | ✅ Merge via `tools/api/merge_openapi.php` | **Spec finale** — input per Swagger UI / Redoc / API codegen |

## Workflow

```bash
# 1. Genera scheletro (re-run quando cambiano routes/web.php)
php tools/api/generate_openapi.php > docs/api/openapi.yaml

# 2. Annota manualmente i nuovi endpoint critici in openapi.overlay.yaml

# 3. Merge → spec finale
php tools/api/merge_openapi.php > docs/api/openapi.full.yaml

# 4. Validate (richiede league/openapi-psr7-validator dev-dep)
php -r "
require 'vendor/autoload.php';
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
(new ValidatorBuilder())->fromYamlFile('docs/api/openapi.full.yaml')->getRoutedRequestValidator();
echo 'VALID';
"
```

## Composer shortcuts (proposti)

```json
{
  "scripts": {
    "openapi:generate": "php tools/api/generate_openapi.php > docs/api/openapi.yaml",
    "openapi:merge":    "php tools/api/merge_openapi.php > docs/api/openapi.full.yaml",
    "openapi:build":    ["@openapi:generate", "@openapi:merge"]
  }
}
```

## Visualizzare la spec

### Swagger UI (browser)

```bash
# Docker quick-start
docker run -p 8080:8080 -e SWAGGER_JSON=/spec/openapi.full.yaml \
    -v "$(pwd)/docs/api:/spec" swaggerapi/swagger-ui

# Apri http://localhost:8080
```

### Redoc (single-file HTML)

```bash
npx @redocly/cli build-docs docs/api/openapi.full.yaml \
    --output docs/api/redoc.html
```

### VS Code

Estensione "OpenAPI (Swagger) Editor" by 42Crunch — apre `openapi.full.yaml`
in preview live.

## API codegen

### TypeScript client (per future SPA)

```bash
npx openapi-typescript docs/api/openapi.full.yaml \
    --output js/types/api.d.ts
```

### Postman collection

```bash
npx openapi-to-postmanv2 -s docs/api/openapi.full.yaml \
    -o docs/api/pantedu.postman_collection.json
```

## Stato copertura

| Categoria | Endpoint totali | Annotati manualmente | Coverage % |
|-----------|----------------|---------------------|------------|
| Auth | 4 | 2 (login, csrf) | 50% |
| Registration | 2 | 1 (register POST) | 50% |
| GDPR self-service | 9 | 9 | 100% |
| GDPR parent_consent | 2 | 2 | 100% |
| GDPR DPO contact | 2 | 1 (POST submit) | 50% |
| Teacher Content | ~12 | 1 (GET index) | 8% |
| Risdoc | ~30 | 0 | 0% |
| Admin | ~40 | 0 | 0% |
| Trust pages | 3 | 0 (HTML only, no schema needed) | N/A |
| Observability | 1 | 1 (/metrics) | 100% |
| Studio / Legacy / Misc | ~80 | 0 | 0% |

**Totale**: 146 path, 187 operation, 19 schema. ~17 endpoint con annotazione
completa request/response. Resto = scheletro auto (path + auth + middleware
documentati, schema TBD).

## Roadmap espansione

| Priorità | Annotare | Effort |
|----------|----------|--------|
| P1 | Teacher Content CRUD (POST/PATCH/DELETE) | 2 ore |
| P1 | Risdoc Templates instances + override | 4 ore |
| P2 | Admin endpoint (registrations, users, security) | 4 ore |
| P2 | Storage signed URL | 30 min |
| P3 | Risdoc Editor (compilation, export TeX) | 2 ore |

## Riferimenti

- OpenAPI 3.1 spec: https://spec.openapis.org/oas/v3.1.0
- Swagger UI: https://swagger.io/tools/swagger-ui/
- Redoc: https://redocly.com/redoc/
- Validator usato: https://github.com/thephpleague/openapi-psr7-validator
