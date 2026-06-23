---
tags:
  - documentazione/testing
date: 2026-04-23
tipo: testing
status: finale
aliases: ["testing", "test"]
cssclasses: []
---

# Testing

## Strategia

| Suite | Tool | Scope | Target |
|-------|------|-------|--------|
| Unit | PHPUnit 11 | Classi singole, no HTTP, no DB | `tests/Unit/` |
| Integration | PHPUnit 11 | Controller + Service + DB | `tests/Integration/` |
| E2E | Playwright 1.59 Chromium | Browser full-stack | `tests/e2e/` |

## Comandi

```bash
# Unit + Integration (PHPUnit)
php vendor/bin/phpunit

# PHPUnit solo unit
php vendor/bin/phpunit tests/Unit/

# PHPUnit solo integration
php vendor/bin/phpunit tests/Integration/

# E2E (richiede pantedu.local attivo in XAMPP)
npm run e2e

# E2E con UI interattiva
npm run e2e:ui

# E2E headed (browser visibile)
npm run e2e:headed

# Installa Chromium per Playwright
npm run e2e:install

# E2E con URL custom
FM_E2E_BASE_URL=http://localhost npm run e2e
```

## Configurazione

**PHPUnit**: `phpunit.xml` — bootstrap da `tests/bootstrap.php`.
**Playwright**: `playwright.config.js` — `baseURL=http://pantedu.local`, no parallelismo (`fullyParallel: false`), output in `tests/e2e-results/`.

## Copertura per dominio

| Dominio | Unit | Integration | E2E | File test chiave |
|---------|:----:|:----------:|:---:|-----------------|
| core/Router | Alta | - | - | `tests/Unit/Core/RouterTest.php` |
| core/Auth | Alta | - | Media | `tests/Unit/Core/AuthTest.php`, `tests/Unit/AuthSessionRotationTest.php` |
| core/Csrf | Alta | - | Media | `tests/Unit/CsrfMiddlewareTest.php`, `tests/e2e/csrf_retry.spec.js` |
| core/Logger | Alta | - | - | `tests/Unit/Core/JsonLoggerTest.php` |
| core/Response | Alta | - | - | `tests/Unit/Core/ResponseTest.php` |
| core/Container | Alta | - | - | `tests/Unit/Core/ContainerTest.php` |
| auth/BlockList | Alta | - | - | `tests/Unit/BlockListTest.php` |
| auth/RateLimit | Alta | - | Media | `tests/Unit/RateLimiterTest.php`, `tests/e2e/rate_limit.spec.js` |
| auth/Registration | Alta | - | Alta | `tests/Unit/RegistrationServiceTest.php`, `tests/e2e/registration.spec.js` |
| auth/User | Alta | - | - | `tests/Unit/UserTest.php` |
| risdoc/TexBuilder | Alta | - | - | `tests/Unit/TexBuilderTest.php` |
| risdoc/templates E2E | - | - | Alta | `tests/e2e/risdoc_all_templates_coverage.spec.js`, `risdoc_tex_production.spec.js` |
| risdoc/operator journey | - | - | Alta | `tests/e2e/risdoc_operator_journey.spec.js` |
| esercizi/TeacherPrint | - | Alta | - | `tests/Integration/TeacherPrintControllerTest.php` |
| esercizi/ContentStudy | - | Alta | - | `tests/Integration/ContentStudyTopicIdsFilterTest.php` |
| verifiche/studio | - | - | Media | `tests/e2e/verifiche_studio_smoke.spec.js`, `studio_verifica_db.spec.js` |
| admin/analytics | - | Alta | Media | `tests/Integration/AnalyticsBeaconTest.php` |
| admin/print | - | Alta | - | `tests/Integration/AdminPrintControllerTest.php` |
| security | Alta | - | Alta | `tests/e2e/security.spec.js` |

## Test unitari principali

| File | Testa |
|------|-------|
| `tests/Unit/Core/AuthTest.php` | `Auth::attempt()`, `Auth::check()`, role checks |
| `tests/Unit/CsrfMiddlewareTest.php` | verifica, TTL, rotate |
| `tests/Unit/BlockListTest.php` | credential block, IP block |
| `tests/Unit/RateLimiterTest.php` | sliding window, lockout |
| `tests/Unit/TexBuilderTest.php` | `TexBuilder::build()`, placeholder sostituzione |
| `tests/Unit/RisdocSeedTest.php` | seed iniziale template risdoc DB |
| `tests/Unit/RisdocResolverTest.php` | `TemplateResolver` override vs source |
| `tests/Unit/SafePathTest.php` | `SafePath` path traversal prevention |
| `tests/Unit/ValidatorTest.php` | `Validator` input sanitization |
| `tests/Unit/Contract/ContractAggregateTest.php` | `ContractAggregate` CRUD items |
| `tests/Unit/Contract/ContractRepositoryTest.php` | CRUD optimistic locking |

## Test E2E chiave

| File | Scenario |
|------|---------|
| `tests/e2e/risdoc_tex_production.spec.js` | 7 template producono PDF (con pdflatex) |
| `tests/e2e/risdoc_operator_journey.spec.js` | Flusso completo docente: login → lista → edit → save → export |
| `tests/e2e/risdoc_all_templates_coverage.spec.js` | Tutti i template risdoc caricano senza errori |
| `tests/e2e/security.spec.js` | Auth protection, CSRF rejection |
| `tests/e2e/registration.spec.js` | Self-signup + approvazione admin |
| `tests/e2e/rate_limit.spec.js` | Rate limit su login e student-login |
| `tests/e2e/verifiche_studio_smoke.spec.js` | Smoke test studio verifica |

## Fixtures

`tests/Fixtures/data/` — JSON users/blocks per test (non collegati a DB reale).
`tests/e2e-results/tex-production/` — output pdflatex dai test E2E (PDF, ZIP, log).

## Lacune coverage

- Mappe: nessun test (dipendenza Google Apps Script)
- `DB_ENABLED=false` path: non coperto in E2E
- `S3CompatibleStorageProvider`: non coperto
- Frontend JS modules: nessun test unit JS (solo E2E)
