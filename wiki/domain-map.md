---
tags:
  - documentazione/domain-map
date: 2026-04-23
tipo: domain-map
status: finale
aliases: ["domain map", "domini"]
cssclasses: []
---

# Domain Map

> [!abstract] Quick load
> Leggi questo file per orientarti. Poi vai al dominio specifico via [[map]].

## Domini identificati

| Dominio | Cartelle principali | Responsabilità | Dipende da |
|---------|--------------------|--------------|---------  |
| **core** | `app/Core/`, `app/Config/`, `app/Support/` | Router, Kernel, middleware pipeline, Auth, CSRF, Session, Config, Database, View, Logger | — |
| **auth** | `app/Controllers/AuthController.php`, `app/Controllers/RegistrationController.php`, `app/Core/Auth.php`, `app/Repositories/UserRepository.php`, `app/Domain/User.php`, `app/Domain/Role.php` | Login/logout, sessioni, registrazione self-service, roles, blocklist | core |
| **risdoc** | `app/Controllers/Risdoc/`, `app/Services/Risdoc/`, `js/components/risdoc/`, `schemas/risdoc/`, `storage/templates/risdoc/` | Documenti docente (risdoc = risorse docente): editor form, compilazioni, export TeX/PDF/ZIP/Overleaf | core, auth |
| **esercizi** | `app/Controllers/ExerciseController.php`, `app/Repositories/ExerciseRepository.php`, `app/Services/TexBuilder.php`, `js/modules/editor/`, `js/modules/print/` | Gestione esercizi LaTeX, editor inline, print export, TikZ elements | core, auth |
| **verifiche** | `app/Controllers/VerificheController.php`, `app/Controllers/VerificaBuilderController.php`, `app/Services/VerificheService.php` | Verifiche scolastiche, builder, BES/DSA export | core, auth, esercizi |
| **mappe** | `js/modules/integrations/google-apps.js`, `js/modules/integrations/google-apps-script.js` | Mappe concettuali via Google Drive/Apps Script | core, auth |
| **admin** | `app/Controllers/Admin*`, `app/Controllers/AdminController.php`, `app/Controllers/UsersAdminController.php`, `app/Services/AdminAnalyticsService.php`, `app/Services/InfrastructureMonitorService.php` | Dashboard admin, gestione utenti, analytics, monitoring, tools | core, auth |
| **frontend** | `js/modules/`, `js/components/`, `js/fm-router.js`, `js/fm-url-state.js`, `views/` | UI layer, modulare JS, Lit 3 Web Components, Vite build | tutti (consumer) |

## Accoppiamenti anomali

| Coppia | Descrizione | Rischio |
|--------|-------------|---------|
| `ExportController` + `processLegacyTex()` | 350+ righe in un metodo privato — logica TeX accoppiata al controller | Alto: difficile testare, evolvere |
| ~~`risdoc.js` (Plan A, 4931 LOC)~~ | IIFE monolitica legacy — **rimossa dal repo** (in git history), superata dai Web Components Plan B | Risolto |
| `TeacherContentRepository` + dual-write | Scrive su DB e JSON simultaneamente per transizione | Medio: sincronizzazione manuale |
| `LegacyController` | Serve asset statici via PHP anziché direttamente da Apache | Basso: overhead accettabile in Aruba shared |

## Test coverage per dominio

| Dominio | Unit | Integration | E2E | Note |
|---------|------|------------|-----|------|
| core | Alta | Media | - | Router, Auth, CSRF, Logger, Response testati |
| auth | Alta | - | Media | AuthTest, BlockListTest, CsrfMiddlewareTest; E2E login/registration |
| risdoc | Media | Media | Alta | TexBuilderTest, RisdocSeedTest; E2E: 7 template verificati |
| esercizi | Bassa | - | Media | TikzServiceTest; E2E sidepage/esercizio |
| verifiche | Bassa | - | Media | E2E studio_verifica_db |
| mappe | Assente | - | - | Nessun test: dipendenza Google Apps Script |
| admin | Bassa | Alta | Media | AdminPrintController, AdminAnalytics E2E |
| frontend | Assente | - | Alta | Solo Playwright smoke/interactions |

## Entrypoint per dominio

| Dominio | HTTP Entrypoint | File |
|---------|----------------|------|
| core | tutti | `public/index.php` → `app/Core/Kernel.php` |
| auth | `GET/POST /login`, `ANY /logout`, `GET /auth/user-info` | `app/Controllers/AuthController.php` |
| risdoc | `GET /risdoc/*`, `POST /api/risdoc/*` | `app/Controllers/Risdoc/TemplateController.php` |
| esercizi | `GET /eser/*`, `GET /api/exercise/*` | `app/Controllers/ExerciseController.php` |
| verifiche | `GET /verifiche/*` | `app/Controllers/VerificheController.php` |
| admin | `GET/POST /admin/*` | `app/Controllers/AdminController.php` |
| frontend | assets + Vite | `js/modules/bootstrap.js`, `public/build/` |

## Domini prioritari per deep wiki

1. **risdoc** — complessità più alta, pipeline TeX critica, attivamente sviluppato (Plan B)
2. **core** — base di tutto, fondamentale per debugging
3. **auth** — sicurezza critica
4. **esercizi** — dominio principale legacy, molte classi protette
5. **admin** — strumento operativo quotidiano
