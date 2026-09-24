---
tags:
  - documentazione/architettura
date: 2026-09-23
tipo: architettura
status: finale
aliases: ["entrypoints"]
cssclasses: []
---

# Entrypoints

## HTTP

| File | Trigger | Responsabilità | Moduli coinvolti |
|------|---------|----------------|-----------------|
| `public/index.php` | ogni richiesta (nginx `try_files` → FastCGI nel container; in sviluppo il server integrato di PHP con `tools/ci/router-php-server.php`, che rifà gli alias di nginx) | serve i file statici reali sotto `public/` a qualunque SAPI; carica `app/bootstrap.php`, `routes/web.php`, istanzia `Router` e `Kernel`, `handle()` → `send()`; header `Link` per Early Hints se dietro CDN | bootstrap, Router, Kernel |
| `app/bootstrap.php` | incluso da `public/index.php`, dai tool CLI e dai test di integrazione | autoload, `.env` + `.env.local`, `Config::load`, timezone, error reporting, `Session::start()` | Dotenv, Config, Session |
| `routes/web.php` | incluso da `public/index.php` | rotte in gruppi ([[routing-and-api]]; elenco generato: `docs/ROUTES.md`) | tutti i controller |
| `/delete_temp.php` | rotta di `CronController` (solo CLI o localhost) | svuota `temp/` e `verifiche/temp/` con `FileService`; dal 2026-09-05 nessun file PHP viene più eseguito con `require` (`log/auth`, `log/logout`, `api/files/delete_temp.php` rimossi); il vecchio `index.php` della radice non esiste più | — |

## CLI

| Comando | Responsabilità |
|---|---|
| `php tools/migrate.php [--status|--dry-run]` | migrazioni con l'utente DDL (`Database::migrationConnection`), advisory lock, prefissi doppi rifiutati |
| `php tools/dev/render_page.php <path>` | rende una pagina attraverso il router vero, senza browser (utile con il WAF che blocca i browser automatici) |
| `php tools/dev/gen_routes_md.php`, `gen_phases_md.php` | rigenerano `docs/ROUTES.md` e `docs/PHASES.md` |
| `php tools/dev/extract_controller.php`, `check_controller_complete.php` | estrazione meccanica di metodi da un controller e verifica di completezza (ADR-029) |
| `php tools/crypto/*.php` | chiave master (generazione; cambio con `rewrap_master.php`), backfill, rotazione KEK, audit report, benchmark |
| `php tools/gdpr/*.php` | cancellazioni in cooling-off, consensi scaduti, drill breach, cifratura compilazioni risdoc |
| `php tools/audit/export_audit_chain.php` | catena di impronte giornaliera dei registri append-only |
| `php tools/audit/impronta_ip.php <indirizzo>` | le due impronte di un indirizzo noto da cercare nei registri: con chiave (dal 2026-09-24) e SHA-256 (righe di prima) |
| `php tools/legal/sync_versions.php --apply` | allinea `legal_document_versions` a `docs/legal/versions.json` |
| `php tools/wiki/strip_code_links.php [--check]` | guard dei link al codice nella wiki |
| `node tools/ci/*.mjs` | budget bundle, versioni legali, niente CSS-in-JS, niente handler inline |
| `php tools/publish/sanitize-for-publication.php`, `tools/publish/release.sh` | snapshot pubblico sanitizzato |
| `php bin/worker.php` | worker della coda `jobs` (email) |
| `php bin/fm-risdoc-*.php`, `risdoc-pt-*.php` | seed e migrazione dei modelli risdoc |

Altri script alla radice di `tools/` (42 al 23/9/2026, contali con
`find tools -maxdepth 1 -type f | wc -l`) sono correzioni una tantum di
maggio 2026 senza riferimenti ([[technical-debt]] voce 28).

## Lavori pianificati (systemd, `tools/systemd/`)

Elenco completo, sempre giusto: `ls tools/systemd/*.timer`. Qui sotto tutte
le unità pianificate a quella data, per gruppo.

| Unit | Cosa fa |
|---|---|
| `pantedu-deploy.path` + `.service` | osserva il file-trigger scritto dal webhook e lancia `tools/webhook/deploy-container.sh` (fino all'8/9/2026 `deploy.sh`) |
| `pantedu-deploy-differito.timer` | rilascia ciò che la finestra oraria aveva differito |
| `pantedu-ensure-config.service` | rigenera `.env` e le cartelle di storage al boot |
| `pantedu-audit-chain.timer` | catena di impronte dei registri |
| `pantedu-audit-purge.timer` | purga mensile dei registri di audit oltre la retention |
| `pantedu-backup-encrypted.timer` | backup cifrato notturno con heartbeat verso il monitor (`/health/backup` lo espone) |
| `pantedu-backup-chiavi.timer` | salvataggio notturno delle chiavi di cifratura dei docenti |
| `pantedu-diagnostica.timer` | diagnostica degli invarianti, due volte al giorno (07:00 e 19:00) |
| `pantedu-gdpr-deletions.timer` | cancellazioni art. 17 dovute, ogni giorno alle 02:40 |
| `pantedu-gdpr-retention.timer` | ogni giorno alle 02:05, prima del salvataggio (fino al 24/9/2026 il primo del mese): account inattivi, domande di iscrizione in attesa oltre 30 giorni e copia rimasta in `users.json` (`ConservazioneDelleIscrizioni`), `privileged_access_log`, con l'utente di manutenzione |
| `pantedu-breach-drill.timer` | prova semestrale del piano violazioni (art. 33-34) |
| `pantedu-risdoc-scadenze.timer` | avviso e cancellazione delle bozze di compilazione risdoc scadute |
| `pantedu-waf-threat-intel@{asn,spamhaus,tor,x4b}.timer` | import delle liste threat-intel (la fonte CrowdSec è tolta dall'8/9/2026, voce 82 del debito) |
| `pantedu-waf-export-blocked.timer` | esporta i blocchi per fail2ban |
| `pantedu-tikz-prewarm.timer` | cache TikZ |
| `pantedu-rate-limit-cleanup.timer` | ogni giorno alle 03:15 toglie da `rate_limits` le righe più vecchie di un'ora (`tools/rate_limit_cleanup.php`): è la pulizia giornaliera che registro dei trattamenti e DPIA dichiarano (dal 23/9/2026) |
| `pantedu-compile-jobs.timer` | ogni cinque minuti riprende i lavori di compilazione rimasti in `retry` |
| `pantedu-drive-sync.timer` | sincronizzazione notturna delle mappe con Drive |
| `pantedu-pdf-import-purge.timer` | pulizia notturna delle sessioni di importazione PDF |
| `pantedu-versioni.timer` | rapporto mensile sulle versioni delle dipendenze indietro |

Il rilascio a container non installa queste unità: si installano a mano, e
dal 23/9/2026 il controllo `unita` della diagnostica dice quali sul server
mancano, sono diverse, spente o rimaste orfane (`docs/dev/ci-cd.md`, «Che
cosa il rilascio non installa»).

## Deploy

`tools/webhook/github.php` (HMAC, solo `push` su `refs/heads/main`, rate
limit) scrive un file-trigger; dall'8/9/2026 systemd lancia come root
`tools/webhook/deploy-container.sh` (prima era `deploy.sh`, sulla cartella
servita): `git reset --hard` del sorgente sull'host, immagine dal registro o
costruita sul posto, `composer install --no-dev` del `vendor/` dell'host se
`composer.json` o `composer.lock` sono cambiati, istantanea del database,
migrazioni, container nuovo sulla porta libera, scambio di nginx dopo la
verifica, codice del servizio TeX, `sync_versions.php --apply`. Ogni push su
`main` va in produzione. Le tappe e che cosa succede se una fallisce:
[`docs/dev/ci-cd.md`](../docs/dev/ci-cd.md#dal-commit-alla-produzione).

## Vite

Elenco completo, sempre giusto: `vite.config.js` (`input: {...}`). Qui sotto
tutte le entry a quella data, per gruppo — non una selezione. `auth.js`,
che nessuna vista caricava, è stato tolto il 23/9/2026.

| Gruppo | Entry | Uso |
|---|---|---|
| Nucleo | `js/modules/bootstrap.js`, `js/fm-router.js`, `js/modules/perf/sw-register.js` | app principale (caricata da `views/partials/head.php` via `ViteManifest::script()`), navigazione SPA leggera, registrazione del service worker (lazy da `bootstrap.js`) |
| Risdoc | `js/entries/risdoc-pt-editor.js`, `js/components/risdoc/index.js` (`risdoc-components`), `js/components/pt-document/fm-pt-document.js` (`pt-document`) | editor PT (Tiptap) e Web Component risdoc, lazy |
| Editor modali | `js/entries/verifica-preview-editor.js`, `tikz-editor-modal.js`, `tikz-template-filler.js`, `tikz-blocks-manager.js`, `tex-element-editor.js`, `geogebra-editor.js` | anteprima verifica (CodeMirror + pdf.js) e modali TikZ/LaTeX/GeoGebra, lazy |
| Login e registrazione | `js/entries/auth-register.js`, `auth-class-access.js` | registrazione, accesso con credenziale di classe (`auth.js` tolto il 23/9/2026: nessuna vista lo caricava) |
| Area docente | `js/entries/area-docente-templates.js`, `area-docente-profilo.js`, `area-docente-fonti.js`, `area-docente-sposta.js`, `area-docente-categorie.js`, `teacher-dashboard.js`, `exercises-search.js` | pagine dell'area docente, JS che era inline nelle viste (revisione P8, 2026-09-04/05) |
| Admin | `js/entries/admin.js`, `admin-sections.js`, `admin-waf-blocks.js`, `admin-logs.js`, `admin-templates.js`, `admin-analytics.js`, `admin-gdpr-authority-export.js`, `admin-system-deployment.js`, `admin-institutes.js`, `admin-waf-config.js`, `admin-waf-rules.js`, `admin-sidebar-config.js`, `admin-dashboard.js` | pagine di amministrazione, stesso motivo |
| PDF-Import | `js/entries/pdf-import.js`, `pdf-import-models.js` | estrazione esercizi da PDF via LLM |

Lit, pdf.js, pako e MathJax sono nel bundle da `package.json` (npm), non più
da CDN a runtime ([[technical-debt]] voce 16, chiusa): restano da CDN solo
GeoGebra (dichiarato) e il viewer diagrams.net delle mappe.

## Auth (rotte)

| Azione | Rotta | Controller |
|--------|-------|------------|
| Login | `GET/POST /login`, poi `GET/POST /login/2fa` | `AuthController::showLogin/login/show2fa/verify2fa` |
| Logout | `ANY /logout` | `AuthController::logout` |
| Accesso classe | `GET /accesso-classe`, `POST /api/access/student-login` | `AuthController::showClassAccess`, `TeacherCredentialController::studentLogin` |
| Recupero password | `GET/POST /password/forgot`, `/password/reset` | `PasswordResetController` |
| 2FA self-service | `/me/2fa`, `/me/2fa/setup|enable|setup-email|enable-email|disable` | `TotpController` |
| CSRF, user info | `GET /auth/csrf`, `GET /auth/user-info` | `AuthController::csrf/userInfo` |

## Risdoc (rotte)

| Azione | Rotta | Controller |
|--------|-------|------------|
| Lista modelli | `GET /api/risdoc/templates` | `Risdoc\TemplateController::index` |
| Vista / editor | `GET /risdoc/view/{id}`, `GET /risdoc/edit/{id}` | `TemplateViewController::show`, `TemplateEditorController::show` |
| Compilazioni | `GET/POST /api/risdoc/templates/{id}/compilations`, `GET/POST /api/risdoc/compilations/{id}[/delete]` | `CompilationController` |
| File TeX e compile | `POST /api/risdoc/templates/{id}/tex-files[/save]`, `POST .../compile-pdf` | `TexFilesController` |
| Export ZIP | `POST /api/risdoc/templates/{id}/export`, `GET /api/risdoc/exports/{file}` | `ExportController` |
| Override e istanze | `.../override[/del]`, `.../institutional-override[/del]`, `.../instances[/{key}/delete|rename]` | `TemplateController` |
