---
tags:
  - documentazione/architettura
  - dominio/admin
date: 2026-09-04
tipo: architettura
status: finale
aliases: ["admin", "amministrazione"]
cssclasses: []
---

# Dominio: admin

> [!abstract] Scopo
> Strumenti dell'amministratore e del super-admin: utenti e registrazioni, istituti e sezioni, GDPR (richieste, breach, subprocessor, export per l'autorità), WAF, crypto e backup, log, monitoraggio, scenari e toggle di sistema, modelli risdoc e template verifiche.

## Confini del dominio

- **In**: amministratore della piattaforma (`role=administrator`) o super-admin (`is_super_admin`, scope globale), sempre con motivazione sulle mutazioni. L'amministratore di istituto **non** è di questo dominio: ha un ruolo suo (`institute_admin`) e la zona `istituto`, solo nello scenario 3 ([[decisions/ADR-040-amministratore-di-istituto]])
- **Out**: pagine HTML, JSON, azioni tracciate in `privileged_access_log`

## Moduli interni

| Modulo | File | Responsabilità |
|--------|------|----------------|
| AdminToolsController | `app/Controllers/AdminToolsController.php` | `/admin`: la pagina degli strumenti (utenti, registrazioni, log, notifiche); landing dopo il login |
| AdminController | `app/Controllers/AdminController.php` | `/admin/dashboard` (riquadri), `/admin/tools` (rimando), hash tool, access/debug log (super-admin), `whoAmI`, notifiche |
| UsersAdminController, SecurityAdminController | `app/Controllers/` | `/api/admin/users*` (attiva, ruolo, elimina), blocchi credenziali/IP, anomalie, config |
| RegistrationController (parte admin) | `app/Controllers/RegistrationController.php` | `/admin/registrations`, approve/reject (super-admin) |
| Admin/AdminSystemController | `app/Controllers/Admin/AdminSystemController.php` | `/admin/system/deployment`: scenario (ADR-032), modalità dati studente, classi ammesse, toggle 2FA e ToS con motivazione, profili capability (ADR-028) |
| Admin/AdminInstitutesController, AdminSectionsController | `app/Controllers/Admin/` | istituti (creazione con codice MIUR, sospensione, storage compilazioni, sezioni dei docenti per istituto — ADR-041 —, aggiornamento anagrafiche e adozioni MIUR); sezioni: incarichi dei docenti (con «solo incaricati» decidono anche quali sezioni un docente può usare), classe degli studenti, materie |
| Admin/AdminGdprController, AdminTakedownController, AdminTosLogController | `app/Controllers/Admin/` | richieste GDPR, registro breach, subprocessor, export per l'autorità firmato; coda takedown; log accettazioni ToS |
| Admin/AdminCryptoStatusController, AdminBackupController, AdminMonitoringController, AdminLogsController | `app/Controllers/Admin/` | stato crypto e custodia con drill, backup (snapshot, off-site, cold), Grafana via `auth_request`, pannello log unificato |
| Admin/WafAdminController, WafApiController | `app/Controllers/Admin/WafAdminController.php`, `app/Controllers/WafApiController.php` | pannello WAF (config, regole, blocchi, anomalie, report, threat-intel, diagnostica); raccolta fingerprint |
| Admin/RisdocAdminController, TemplatesAdminController, VerificaFilesAdminController, VerificaPreambleAdminController | `app/Controllers/Admin/` | modelli risdoc (visibilità, collaboratori, review, meta, sorgenti JSON), `/admin/templates`, file template verifiche |
| Admin/AdminSidebarConfigController | `app/Controllers/Admin/AdminSidebarConfigController.php` | sezioni della sidebar (ADR-027) e chi pubblica in rete: il docente i cui contenuti pubblicati mostrano, senza login, le sezioni «Pubblica in rete» (`App\Services\Study\PublicContentPolicy`, migrazione 129). Senza scelta non c'è niente in rete: la barra dei visitatori mostra solo l'accesso, anche con sezioni marcate «Pubblica in rete» |
| AdminAnalyticsController, AdminInfrastructureController, AdminMigrateController, AdminPrintController, MetricsController, GrafanaGateController | `app/Controllers/` | analytics cross-istituto (super-admin), infrastruttura, migrazioni via web, stampa batch, `/metrics`, gate Grafana |
| AdminAnalyticsService, InfrastructureMonitorService, AnomalyDetectionService, AdminNotificationsService, LogRotator, LogTailer | `app/Services/` | aggregazioni, health, anomalie, contatori, log |
| InstituteMergeService, MiurSchoolsService, MiurAdozioniImporter, CurriculumService | `app/Services/` | istituti e curriculum |

## Viste admin (`views/admin/`)

`dashboard.php`, `tools.php`, `analytics.php`, `infrastructure.php`,
`institutes_index.php`, `institutes_new.php`, `institutes_adozioni.php`,
`sections.php`, `sidebar-config.php`, `system/deployment.php`, `templates.php`,
`_risdoc_admin_panel.php` (dentro `templates.php`; la vecchia `risdoc.php`, orfana, è tolta dal 23/9/2026), `waf/*.php`, `crypto_status.php`,
`backup.php`, `monitoring.php`, `logs_index.php`, `tos_log.php`,
`data_requests_*.php`, `data_breach_*.php`, `subprocessors_*.php`,
`gdpr_authority_export.php`, `takedown_*.php`, `Elementi_Riservati.html`
(template statico del builder). Molte contengono script inline
([[technical-debt]] voce 19).

## JS

`js/entries/admin.js` (entry Vite), `js/modules/features/admin-tools.js`,
`admin-risdoc.js`, `admin-tikz-templates.js`, `admin-verifica-templates.js`,
`admin-options-sources.js`, `admin-banner-badge.js`.

## Controllo accesso

- Gruppo `auth + role:admin + log`; le operazioni di governo stanno in un
  gruppo con `super_admin_required` e `sadmin_audit:admin_read,governance`
  (letture a registro).
- Le scritture hanno `csrf`, `rate` e, dove toccano dati altrui o decisioni
  di sistema, `audit_reason` (10-255 caratteri, `enforce`).
- L'amministratore di istituto non entra in questo gruppo: ha `role=institute_admin`
  con `admin_institute_id`, sta solo nella zona `istituto` (`/istituto`) e solo
  nello scenario 3. Le funzioni sul suo istituto si aprono lì, una alla volta
  ([[decisions/ADR-040-amministratore-di-istituto]], fase 2). Analytics
  cross-istituto e log restano super-admin.

## API chiave

| Method + Path | Funzione |
|--------------|---------|
| `GET /api/admin/users`, `POST /api/admin/users/{id}/{active|role|delete}` | utenti. L'elenco chiede un motivo, e al super-amministratore non mostra mai gli studenti. Il ruolo «student» si assegna solo dove esistono gli account studente (scenario 3), l'amministratore di istituto mai da qui (`App\Support\RuoliNelPannelloUtenti`). Il proprio account non si disattiva, non si elimina e non perde il ruolo: il server risponde 403, e la pagina non mostra quei comandi sulla propria riga |
| `POST /admin/registrations/{id}/{approve|reject}` | registrazioni (super-admin) |
| `POST /admin/sections/{assign|revoke|student|subjects}` | incarichi e classe (con `audit_reason` sullo studente) |
| `POST /admin/sidebar-config/pubblica-in-rete` | il docente che pubblica in rete (un docente attivo, uno solo: lo garantisce un indice unico), o nessuno, con `audit_reason`. Si sceglie un docente solo con il suo consenso |
| `POST /admin/sections/{materiali|scadenza}` | i materiali di un docente rimasti su una sezione senza incarico: portarli sull'anno (con `audit_reason`) e la data dell'avviso ai docenti (ADR-041, punto 5) |
| `POST /admin/institutes/new`, `.../{id}/active`, `.../{id}/compilation-storage`, `.../{id}/sezioni-docenti`, `.../miur/{update|adozioni}` | istituti |
| `POST /admin/system/deployment/switch`, `.../2fa-enforce`, `.../tos-enforce`, `.../registration-mode` | scenari e toggle |
| `GET /api/admin/analytics*` | analytics (super-admin) |
| `GET /api/admin/infrastructure.json`, `GET /metrics` | health e Prometheus |
| `/admin/waf/api/*` | WAF |
| `/api/admin/risdoc/*` | modelli risdoc |

## Link correlati

[[routing-and-api]] · [[security-notes]] · [[waf]] · [[domains/auth/auth-overview]] · [[domains/risdoc/risdoc-overview]] · [[decisions/ADR-028-institute-governance-teacher-capabilities]] · [[decisions/ADR-032-deployment-scenarios]]
