---
title: "Pantedu API — OpenAPI 3.1 spec"
date: 2026-04-28
last_reviewed: 2026-09-23
---

# Pantedu API documentation

OpenAPI 3.1 specification of the Pantedu REST endpoints: lo scheletro
elenca **ogni** rotta dichiarata in `routes/web.php` (letta dal vero
`App\Core\Router`, non da un'analisi del testo), ma solo un sottoinsieme ha
uno schema request/response completo — vedi «Stato copertura» sotto prima di
assumere che un endpoint sia documentato per intero.

> **Stato (2026-09-23, DOC-2)**: il generatore (`tools/api/generate_openapi.php`)
> riconosceva solo `$router->` e `$r->`, testualmente: saltava le rotte nei
> gruppi annidati (`$rr->`, `$rrr->`) e quelle con un handler-chiusura (es. le
> rotte `legacy_gone`). Lo scheletro copriva 112 dei 253 path `/api/` reali, e
> si dichiarava comunque completo. Corretto leggendo le rotte dal vero
> `App\Core\Router` (istanzia il router e include `routes/web.php`, come
> `tests/Unit/Core/CoperturaCsrfTest.php`): copre ogni gruppo, a qualunque
> profondità. Rigenerato lo stesso giorno: **508 path, 603 operation** (253
> sotto `/api/`), `php tools/api/validate_openapi.php` verde. La copertura con
> annotazioni complete request/response resta quella dell'overlay manuale (16
> path, 17 operation): vedi la tabella «Stato copertura» qui sotto, ricalcolata
> sulla spec di oggi. Dopo ogni modifica alle rotte:
> `composer openapi:build && composer openapi:validate` (dal 2026-09-23 anche
> un passo di CI rigenera e confronta, vedi `docs/dev/ci-cd.md`).

## Files

| File | Generato? | Scopo |
|------|-----------|-------|
| `openapi.yaml` | ✅ Auto via `tools/api/generate_openapi.php` | Scheletro: 508 path / 603 operation, no request/response schema |
| `openapi.overlay.yaml` | ❌ Manuale | Annotazioni critiche (auth + GDPR + content + metrics — 16 path / 17 operation) |
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

## Composer shortcuts

Già in `composer.json` (non più «proposti»): `composer openapi:generate`,
`composer openapi:merge`, `composer openapi:build` (le prime due in sequenza)
e `composer openapi:validate`.

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

Ricalcolata il 2026-09-23 confrontando ogni operazione dello scheletro
generato con `openapi.overlay.yaml` (script ad-hoc, non versionato: conta le
operazioni per tag e quelle con lo stesso path+verbo nell'overlay). Non è un
numero scritto a mano: rifarla dopo un cambio di rotte con lo stesso confronto,
non aggiornare questa tabella a occhio.

| Categoria (tag) | Operation totali | Annotate manualmente | Coverage % |
|-----------------|------------------|-----------------------|------------|
| Auth | 15 | 2 | 13% |
| Registration | 2 | 1 | 50% |
| GDPR self-service (Art. 7/15-22) | 22 | 9 | 41% |
| GDPR parent consent (Art. 8) | 2 | 2 | 100% |
| GDPR DPO contact (Art. 12-22, 37-39) | 2 | 1 | 50% |
| Teacher Content | 28 | 1 | 4% |
| Risdoc Templates | 33 | 0 | 0% |
| Risdoc | 8 | 0 | 0% |
| Admin | 56 | 0 | 0% |
| Admin UI | 113 | 0 | 0% |
| API Public | 167 | 0 | 0% |
| Legacy Education | 11 | 0 | 0% |
| Storage | 1 | 0 | 0% |
| Studio | 4 | 0 | 0% |
| Trust Pages | 3 | 0 (HTML, nessuno schema richiesto) | N/A |
| Observability | 1 | 1 | 100% |
| Misc | 135 | 0 | 0% |

**Totale**: 508 path, 603 operation, 19 schema. 17 operazioni con annotazione
completa request/response (16 path). Resto = scheletro auto (path + auth +
middleware documentati, schema TBD). «API Public», «Admin UI» e «Misc» sono le
tre categorie più grandi e le meno annotate: la maggior parte delle 253 rotte
`/api/` ricade nella prima.

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
