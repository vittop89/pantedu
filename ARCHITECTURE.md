# Architettura pantedu — mappa di orientamento

Punto d'ingresso tecnico per chi clona il repo. Versione narrativa estesa: [`wiki/architecture.md`](wiki/architecture.md). Decisioni: [`wiki/decisions/`](wiki/decisions/) (ADR). Indici generati: [`docs/ROUTES.md`](docs/ROUTES.md), [`docs/SERVICES.md`](docs/SERVICES.md), [`docs/PHASES.md`](docs/PHASES.md).

## Stack

| Layer | Tecnologia | File di riferimento |
|-------|-----------|---------------------|
| PHP runtime | PHP ^8.3, PSR-4 autoload | `composer.json` |
| HTTP | Apache + mod_rewrite → `public/index.php` | `public/.htaccess` |
| DB | PDO raw, MySQL 5.7+/MariaDB utf8mb4 | `app/Core/Database.php`, `database/migrations/` |
| Session | PHP nativa (`DbSessionHandler` opz.) | `app/Core/Session.php` |
| Config | Dotenv → `Config::get()` | `app/Core/Config.php`, `app/Config/` |
| View | PHP puro `View::render()` | `app/Core/View.php`, `views/` |
| Frontend moderno | Lit 3 Web Components, Vite 8 | `js/components/`, `public/build/` |
| Frontend legacy | jQuery + moduli vanilla | `js/modules/`, `js/vendor/` |
| Storage | Local / S3-compatible | `app/Support/Storage/` |
| PDF | pdflatex server-side | `storage/templates/risdoc/texCommon/` |
| Test | PHPUnit 11 + Playwright 1.59 | `tests/Unit/`, `tests/e2e/` |

## Pattern: MVC custom (no framework)

```
Request → Router → Kernel::buildPipeline() → Middleware[] → Controller → Service → Repository → Response
```

- **Router** `app/Core/Router.php` — pattern `{param}`, `{param?}`, `{param*}`; match path + method.
- **Kernel** `app/Core/Kernel.php` — costruisce la pipeline middleware, orchestra il WAF, catch globale `Throwable`.
- **Middleware** `app/Middleware/` (13) — alias: `auth`, `csrf`, `role:<r>`, `rate:<k>,<n>`, `log`, `legacy_gone`, `sadmin_audit`, WAF, tenant.
- **Controller** `app/Controllers/` (40+) — nessuna classe base, istanziato dal Kernel con `(Request, array $params)`.
- **Service** `app/Services/` (50+ in 20 sottodomini) — business logic, no ORM.
- **Repository** `app/Repositories/` (15) — accesso dati, dual-write DB+JSON in transizione.
- **Domain** `app/Domain/` (7) — entità pure (User, Role, Institute, Curriculum).

## Mappa cartelle

| Cartella | Ruolo |
|----------|-------|
| `app/` | Backend PHP (Core, Controllers, Services, Repositories, Middleware, Domain, Config) |
| `routes/web.php` | **Tutte** le route (~430 endpoint) → vedi `docs/ROUTES.md` |
| `views/` | Template PHP (shell, partials, auth, risdoc, admin) |
| `js/` | Frontend: `components/` (Lit), `modules/` (legacy/feature), `entries/` (Vite) |
| `css/` | ITCSS + BEM `fm-*` (`tokens` → `modules/` → `layout.css` legacy). Vedi `css/modules/README.md` |
| `database/` | `schema.sql` + `migrations/NNN_*.sql` (sequenziali) |
| `app/Config/` | Config per topic (`app`, `auth`, `security`, `waf`, `session`…) |
| `tools/` | Script admin/build/audit (migrate, TikZ, backup, WAF, crypto) |
| `docs/` | Documentazione tecnica (api, security, privacy, plans, conventions) |
| `wiki/` | ADR + architecture + changelog mese-per-mese + `_llm-primer.md` |
| `storage/` | Runtime: templates, logs, backups, keys (KMS), objects, maps cifrate |
| `public/` | HTTP root: `index.php`, `build/` (Vite), assets |
| `infra/` | Deploy: `nginx/` |
| `tests/` | `Unit/` (PHPUnit), `e2e/` (Playwright) |

## Come trovare X (runbook)

| Cerchi… | Vai a |
|---------|-------|
| Quale codice gestisce l'endpoint X | `docs/ROUTES.md` → controller → `app/Controllers/` → service |
| Quale service per la feature Y | `docs/SERVICES.md` |
| Cosa fa la "Phase NN" citata nei commenti | `docs/PHASES.md` + `wiki/changelog/` |
| Autenticazione / sessione | `app/Core/Auth.php`, `app/Core/Session.php`, `app/Middleware/AuthMiddleware.php` |
| Autorizzazione / ruoli | `app/Services/AclPolicy.php`, `app/Services/Risdoc/Permission.php`, `app/Middleware/RoleMiddleware.php`, `app/Domain/Role.php` |
| WAF / sicurezza runtime | `app/Middleware/WafMiddleware.php`, `app/Services/Waf/` (+ `app/Services/Waf/README.md`) |
| Crypto (envelope encryption) | `app/Services/Crypto/` (+ README), ADR-006 |
| Validazione input | `app/Validation/Validator.php` (+ `docs/VALIDATION.md`) |
| Schema DB / una colonna | `database/schema.sql`, `database/migrations/` (grep per topic) |
| Configurazione / env | `app/Config/<topic>.php`, `.env`/`.env.local`, `Config::get('topic.key')` |
| Editor risdoc | `js/components/risdoc/`, `app/Controllers/Risdoc/`, `app/Services/Risdoc/` |

## Decisioni & note critiche

- ADR completi in `wiki/decisions/ADR-001..029`. Ultimi: ADR-027 (sidebar DB-driven), ADR-028 (governance istituto), ADR-029 (decomposizione God-controller).
- God-object noti (refactor in ADR-029): `TeacherContentController` (1886 LOC), `ContentStudyController` (1883 LOC), `routes/web.php` (1434 righe) — method-map in `docs/glossary/`.
- Dual-write DB+JSON disattivabile con `DB_DUAL_WRITE=false` dopo consolidamento.
- pdflatex non su shared hosting base → fallback ZIP export.
