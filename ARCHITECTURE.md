# Architettura pantedu — mappa di orientamento

Punto d'ingresso tecnico per chi clona il repo. Versione narrativa estesa: [`wiki/architecture.md`](wiki/architecture.md); contesto rapido per persone e IA: [`wiki/_llm-primer.md`](wiki/_llm-primer.md). Decisioni: [`wiki/decisions/`](wiki/decisions/) (un ADR per file, elenco sempre giusto con `ls wiki/decisions/`). Indici generati: [`docs/ROUTES.md`](docs/ROUTES.md), [`docs/PHASES.md`](docs/PHASES.md); a mano: [`docs/SERVICES.md`](docs/SERVICES.md). Ultima revisione completa: [`docs/analysis/revisione-architetturale-2026-09-completa.md`](docs/analysis/revisione-architetturale-2026-09-completa.md) (23/9/2026; la precedente è del 4/9).

## Stack

| Layer | Tecnologia | File di riferimento |
|-------|-----------|---------------------|
| PHP runtime | PHP ^8.3 (8.4 in produzione), PSR-4 `App\` → `app/` | `composer.json` |
| HTTP | nginx + PHP-FPM nel container di produzione (`infra/nginx/`, ADR-048); in sviluppo il server integrato di PHP con `tools/ci/router-php-server.php`, che rifà gli alias di nginx (`docs/dev/sviluppo-in-wsl.md`) | `public/index.php` |
| DB | MariaDB (10.11 sviluppo/E2E, 11.8 produzione), PDO raw, viste sulle tabelle `*_data`, trigger append-only, tre utenti; migrazioni con sintassi MariaDB, non MySQL | `app/Core/Database.php`, `database/migrations/` (`ls database/migrations \| wc -l`) |
| Sessione | PHP nativa, a file sul volume dei dati o su database secondo `SESSION_DRIVER` (ADR-039), login in due passaggi | `app/Core/Session.php`, `app/Core/Auth.php` |
| Config | `.env` + `.env.local` → `app/Config/*.php` → `Config::get()`; override runtime in `storage/config/*.json` | `app/Core/Config.php`, `app/Config/` |
| View | PHP puro `View::render()` | `app/Core/View.php`, `views/` |
| Frontend | moduli ES vanilla + 21 Web Component Lit 3; Vite 8, entry multiple (`vite.config.js`) | `js/`, `vite.config.js`, `public/build/` |
| Storage | locale o S3-compatibile, blob cifrati a busta | `app/Support/Storage/` |
| TeX/PDF | microservizio Python separato via HMAC (nessun rendering "in-process": `TexCompileClient` rifiuta endpoint/secret vuoti) | `tools/tex-compile-vps/`, `app/Services/TexCompile/` |
| Sicurezza | WAF applicativo, cifratura per docente, audit append-only con catena di impronte, 2FA per ruolo | `app/Middleware/WafMiddleware.php`, `app/Services/Crypto/`, `app/Services/Audit/` |
| Test | PHPUnit 11, Vitest 4, Playwright 1.59 (numeri: [wiki/testing.md](wiki/testing.md), invecchiano a ogni PR) | `tests/` |
| Deploy | push su `main` → webhook → `tools/webhook/deploy-container.sh` sul VPS: immagine a container, scambio di nginx dopo la verifica (dall'8/9/2026, ADR-048; prima era `deploy.sh` sulla cartella servita) | `tools/webhook/`, `tools/systemd/` |

## Pattern: MVC custom (no framework)

```
Request → Kernel (X-Request-ID · WAF · gate ToS) → Router → middleware del gruppo → Controller → Service → Repository → Response (registro operazioni · security header)
```

- **Router** `app/Core/Router.php` + `Route.php` — pattern `{param}`, `{param?}`, `{param*}`; rotte in `routes/web.php` (elenco generato: `docs/ROUTES.md`).
- **Kernel** `app/Core/Kernel.php` — applica WAF, gate ToS/AUP, registro delle operazioni e security header a ogni richiesta; mappa gli alias dei middleware di gruppo (`auth`, `role:*`, `studio`, `csrf`, `rate:*`, `log`, `legacy_gone`, `sadmin_audit`, `audit_reason`, `super_admin_required`, `teacher_subjects`).
- **Middleware** `app/Middleware/` (contali con `find app/Middleware -name '*.php' | wc -l`).
- **Controller** `app/Controllers/` (nessuna classe base, `(Request, array $params)`; contali con `find app/Controllers -name '*.php' | wc -l`).
- **Service** `app/Services/` (sottodomini per dominio; contali con `find app/Services -name '*.php' | wc -l`).
- **Repository** `app/Repositories/<Dominio>/` (per dominio dal 2026-09-04, regola scritta per esteso in [`wiki/decisions/ADR-049-confini-degli-strati.md`](wiki/decisions/ADR-049-confini-degli-strati.md) dal 23/9); molte tabelle sono interrogate direttamente da controller e servizi (debito 17).
- **Domain** `app/Domain/` — `User`, `Role`, `ViewerContext`, `ContentVisibilityPolicy`.
- **Support** `app/Support/` — `DeploymentScenario` (ADR-032), `ClassAccessGrant`, `TwoFactorEnforcement`, `CurriculumLookup`, `SafePath`, `Validator`, `ViteManifest`.

## Mappa cartelle

| Cartella | Ruolo |
|----------|-------|
| `app/` | Backend PHP (Core, Middleware, Controllers, Services, Repositories, Domain, Support, Config, Jobs, Policies) |
| `routes/web.php` | **Tutte** le rotte → vedi `docs/ROUTES.md` (totale nella prima riga del file) |
| `views/` | Template PHP (layout, partials, auth, area docente, risdoc, admin, legal) |
| `js/` | Frontend: `modules/` (vanilla), `components/` (Lit), `entries/` (Vite); le librerie vengono da npm |
| `css/` | ITCSS + BEM `fm-*`, `@layer` (ADR-023); bundle generato al deploy |
| `database/` | `schema.sql` + `migrations/NNN_*.sql` |
| `schemas/` | contratto contenuti v1 + schemi risdoc |
| `storage/` | template TeX, dati runtime, chiavi, blob cifrati, override di configurazione |
| `tools/` | migrazioni, deploy (webhook, systemd), crypto, GDPR, audit, CI, pubblicazione, microservizio TeX; a radice, script una tantum di maggio 2026 senza riferimenti (42 al 23/9/2026, `find tools -maxdepth 1 -type f \| wc -l`; debito 28) |
| `docs/` | documentazione: install, API (OpenAPI), legale e privacy (registro versioni), ops, piani, analisi |
| `wiki/` | contesto tecnico per persone e IA: primer, mappa, domini, ADR, changelog mensile, registro del debito |
| `tests/` | `Unit/`, `Integration/` (PHPUnit), `js-unit/` (Vitest), `e2e/` (Playwright) |
| `public/` | HTTP root: `index.php`, `build/` (Vite), `vendor/` (Quill), `sw.js` |
| `infra/` | nginx |
| `.htaccess` (radice), `log/` | residuo del vecchio hosting condiviso hosting legacy (Apache), non usato né in sviluppo (WSL, server integrato di PHP) né in produzione (container, nginx): candidato all'igiene del repository (debito 28); `log/` tiene solo `errors/` (log locali, non versionati): gli entry point legacy `log/auth`, `log/logout` e `api/` sono stati tolti il 2026-09-05 (debito 22 risolto) |

## Come trovare X (runbook)

| Cerchi… | Vai a |
|---------|-------|
| Quale codice gestisce l'endpoint X | `docs/ROUTES.md` → controller in `app/Controllers/` → service |
| Quale service per la feature Y | `docs/SERVICES.md` |
| Cosa fa la "Phase NN" citata nei commenti | `docs/PHASES.md` + `wiki/changelog/` |
| Autenticazione, 2FA, credenziale di classe | `app/Core/Auth.php`, `app/Controllers/AuthController.php`, `app/Support/TwoFactorEnforcement.php`, `app/Support/ClassAccessGrant.php` |
| Scenario di esercizio | `app/Support/DeploymentScenario.php`, `wiki/decisions/ADR-032-deployment-scenarios.md` |
| Autorizzazione / ruoli | `app/Config/roles.php`, `app/Services/AclPolicy.php`, `app/Services/TeacherCapabilityPolicy.php`, `app/Domain/ContentVisibilityPolicy.php` |
| WAF | `app/Middleware/WafMiddleware.php`, `app/Services/Waf/` |
| Crypto | `app/Services/Crypto/`, ADR-006 |
| Registri di audit | `app/Services/Audit/`, migrazioni 098 e 100, `tools/audit/` |
| Validazione input | `app/Support/Validator.php` (+ `docs/VALIDATION.md`) |
| Schema DB / una colonna | `database/schema.sql`, `database/migrations/`, `wiki/database-schema.md` |
| Configurazione / env | `wiki/environment-variables.md`, `app/Config/<topic>.php` |
| Editor risdoc | `js/components/`, `app/Controllers/Risdoc/`, `app/Services/Risdoc/` |
| Documenti legali | `docs/legal/versions.json`, `tools/ci/check-legal-versions.mjs` |
| Debito tecnico | `wiki/technical-debt.md` |

## Decisioni & note critiche

- ADR in `wiki/decisions/`, uno per file (`ls wiki/decisions/` per l'elenco e l'ultimo numero: oggi arrivano ad ADR-049, confini fra strati).
- God object: l'elenco con le righe di oggi, sorvegliato da un cricchetto che fallisce se cresce, è `tools/ci/dimensioni.json` ([`wiki/technical-debt.md`](wiki/technical-debt.md), voce 18); `js/modules/risdoc/pt/pm-schema.js` è il file citato altrove come `js/modules/pm/pm-schema.js`, percorso mai esistito. Method-map in `docs/glossary/`.
- La doppia scrittura DB+JSON (`DB_DUAL_WRITE`) è stata tolta il 2026-09-05 in scrittura: i JSON restano come ripiego dei servizi quando il DB non è disponibile, ma in lettura `PrintInfoService` fa ancora prevalere un JSON rimasto indietro sul database ([`wiki/technical-debt.md`](wiki/technical-debt.md), voce 4).
- Lit, pdf.js, pako e MathJax vengono dal bundle Vite (`package.json`), non da CDN a runtime (revisione 2026-09, P3, ADR-033): restano da CDN solo GeoGebra (dichiarato) e il viewer diagrams.net delle mappe.
