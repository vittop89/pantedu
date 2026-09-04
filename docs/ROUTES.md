# Route inventory

> Generato da `routes/web.php` con `php tools/dev/gen_routes_md.php > docs/ROUTES.md`. **Non editare a mano** — rigenera dopo ogni modifica alle route.
>
> **Cosa mostra**: verbo, path letterale, handler `Controller::method`, middleware *route-local*, riga in `routes/web.php`.
> **Cosa NON mostra**: il middleware ereditato dai `group()` (es. `auth`, `role:teacher`, `log`) — è sul wrapper del gruppo, non sulla singola route. Per il middleware effettivo di una route apri `routes/web.php` alla riga indicata e risali al `group()` che la contiene.

Totale: **532** route in 71 gruppi (per prefix di path). Flusso: route → controller in `app/Controllers/` → service in `app/Services/` (vedi `docs/SERVICES.md`).

## Indice gruppi

- [`/`](#) — 1
- [`/Elementi_Riservati.html`](#elementiriservatihtml) — 1
- [`/accessibility`](#accessibility) — 1
- [`/accesso-classe`](#accessoclasse) — 2
- [`/admin`](#admin) — 103
- [`/analytics`](#analytics) — 1
- [`/api/access`](#apiaccess) — 3
- [`/api/admin`](#apiadmin) — 53
- [`/api/institutes`](#apiinstitutes) — 2
- [`/api/latex-shortcuts`](#apilatexshortcuts) — 4
- [`/api/maps`](#apimaps) — 6
- [`/api/probe`](#apiprobe) — 1
- [`/api/public`](#apipublic) — 2
- [`/api/risdoc`](#apirisdoc) — 33
- [`/api/scuole`](#apiscuole) — 1
- [`/api/sidebar`](#apisidebar) — 1
- [`/api/sidepage`](#apisidepage) — 1
- [`/api/sources`](#apisources) — 1
- [`/api/studio`](#apistudio) — 3
- [`/api/study`](#apistudy) — 6
- [`/api/teacher`](#apiteacher) — 121
- [`/api/tenant`](#apitenant) — 2
- [`/api/tex`](#apitex) — 1
- [`/api/verifica`](#apiverifica) — 20
- [`/api/vitals`](#apivitals) — 1
- [`/area-docente`](#areadocente) — 13
- [`/auth`](#auth) — 11
- [`/check`](#check) — 2
- [`/cookies_privacy-policy.html`](#cookiesprivacypolicyhtml) — 1
- [`/curriculum`](#curriculum) — 1
- [`/delete_temp.php`](#deletetempphp) — 1
- [`/didattica`](#didattica) — 1
- [`/dpo-contact`](#dpocontact) — 2
- [`/drafts`](#drafts) — 1
- [`/eser`](#eser) — 1
- [`/exercises`](#exercises) — 2
- [`/favicon.ico`](#faviconico) — 1
- [`/files`](#files) — 8
- [`/geogebra`](#geogebra) — 4
- [`/health`](#health) — 2
- [`/lab`](#lab) — 1
- [`/legal`](#legal) — 6
- [`/log`](#log) — 5
- [`/login`](#login) — 4
- [`/logout`](#logout) — 1
- [`/mappe`](#mappe) — 1
- [`/me`](#me) — 18
- [`/metrics`](#metrics) — 1
- [`/modelli_tikz.json`](#modellitikzjson) — 1
- [`/modelli_tikz_elements.json`](#modellitikzelementsjson) — 1
- [`/modelli_tikz_traccia.json`](#modellitikztracciajson) — 1
- [`/modello_pag_listSidebar.php`](#modellopaglistsidebarphp) — 1
- [`/parent-consent`](#parentconsent) — 2
- [`/password`](#password) — 4
- [`/privacy`](#privacy) — 2
- [`/public`](#public) — 2
- [`/register`](#register) — 2
- [`/risdoc`](#risdoc) — 5
- [`/security`](#security) — 1
- [`/segnalazione-contenuti`](#segnalazionecontenuti) — 2
- [`/storage`](#storage) — 1
- [`/strcomp_bes_altro`](#strcompbesaltro) — 1
- [`/studio`](#studio) — 4
- [`/teacher`](#teacher) — 12
- [`/tex`](#tex) — 1
- [`/tikz`](#tikz) — 22
- [`/tikzjax.js`](#tikzjaxjs) — 1
- [`/tos-acceptance`](#tosacceptance) — 2
- [`/verifiche`](#verifiche) — 3
- [`/version`](#version) — 1
- [`/waf`](#waf) — 1

## /

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/` | `HomeController::index` | — | 32 |

## /Elementi_Riservati.html

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/Elementi_Riservati.html` | `AdminPartialController::show` | — | 626 |

## /accessibility

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/accessibility` | `TrustPagesController::accessibility` | — | 329 |

## /accesso-classe

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/accesso-classe` | `AuthController::showClassAccess` | — | 46 |
| POST | `/accesso-classe/esci` | `AuthController::classAccessLogout` | `csrf` | 47 |

## /admin

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/admin` | `AdminToolsController::page` | — | 1273 |
| GET | `/admin/access-log` | `AdminController::accessLog` | — | 1328 |
| GET | `/admin/access-stats` | `AdminController::accessStats` | — | 1329 |
| GET | `/admin/analytics` | `AdminAnalyticsController::page` | — | 1282 |
| GET | `/admin/backup` | `AdminBackupController::index` | — | 247 |
| POST | `/admin/backup/b2-verified` | `AdminBackupController::b2Verified` | `csrf` | 250 |
| POST | `/admin/backup/cold-completed` | `AdminBackupController::coldCompleted` | `csrf` | 248 |
| GET | `/admin/crypto-status` | `AdminCryptoStatusController::index` | — | 241 |
| POST | `/admin/crypto-status/event` | `AdminCryptoStatusController::recordEvent` | `csrf` | 243 |
| GET | `/admin/crypto-status/export` | `AdminCryptoStatusController::export` | — | 242 |
| GET | `/admin/curriculum` | `(closure)` | — | 635 |
| GET | `/admin/dashboard` | `AdminController::dashboard` | — | 1274 |
| GET | `/admin/data-breach` | `AdminGdprController::dataBreachIndex` | — | 224 |
| GET | `/admin/data-breach/new` | `AdminGdprController::dataBreachNewForm` | — | 225 |
| POST | `/admin/data-breach/new` | `AdminGdprController::dataBreachCreate` | `csrf` | 226 |
| GET | `/admin/data-breach/{id}` | `AdminGdprController::dataBreachShow` | — | 228 |
| POST | `/admin/data-breach/{id}/action` | `AdminGdprController::dataBreachAction` | `csrf` | 229 |
| GET | `/admin/data-requests` | `AdminGdprController::dataRequestsIndex` | — | 219 |
| GET | `/admin/data-requests/{id}` | `AdminGdprController::dataRequestsShow` | — | 220 |
| POST | `/admin/data-requests/{id}/action` | `AdminGdprController::dataRequestsAction` | `csrf` | 221 |
| GET | `/admin/debug-log` | `AdminController::debugLog` | — | 1330 |
| GET | `/admin/gdpr` | `(closure)` | — | 203 |
| GET | `/admin/gdpr/authority-export` | `AdminGdprController::authorityExportPage` | — | 208 |
| POST | `/admin/gdpr/authority-export` | `AdminGdprController::authorityExportSubmit` | `csrf` | 209 |
| POST | `/admin/generate-hash` | `AdminController::generateHash` | — | 1340 |
| GET | `/admin/infrastructure` | `AdminInfrastructureController::page` | — | 1294 |
| GET | `/admin/institutes` | `AdminInstitutesController::index` | — | 171 |
| GET | `/admin/institutes/adozioni` | `AdminInstitutesController::adozioniPreview` | — | 192 |
| POST | `/admin/institutes/adozioni/apply` | `AdminInstitutesController::adozioniApply` | `csrf` | 193 |
| POST | `/admin/institutes/miur/adozioni` | `AdminInstitutesController::adozioniUpload` | `csrf` | 190 |
| POST | `/admin/institutes/miur/update` | `AdminInstitutesController::miurUpdate` | `csrf` | 185 |
| GET | `/admin/institutes/new` | `AdminInstitutesController::newForm` | — | 172 |
| POST | `/admin/institutes/new` | `AdminInstitutesController::create` | `csrf` | 173 |
| POST | `/admin/institutes/{id}/active` | `AdminInstitutesController::toggleActive` | `csrf` | 177 |
| POST | `/admin/institutes/{id}/compilation-storage` | `AdminInstitutesController::toggleCompilationStorage` | `csrf` | 182 |
| GET | `/admin/logs` | `AdminLogsController::page` | — | 216 |
| GET | `/admin/logs/api/{table}` | `AdminLogsController::apiQuery` | — | 217 |
| GET | `/admin/migrate` | `AdminMigrateController::page` | — | 1290 |
| POST | `/admin/migrate/run` | `AdminMigrateController::run` | — | 1241 |
| GET | `/admin/migrate/status` | `AdminMigrateController::status` | — | 1291 |
| GET | `/admin/monitoring` | `AdminMonitoringController::index` | — | 254 |
| POST | `/admin/print` | `AdminPrintController::generate` | — | 1238 |
| POST | `/admin/print/batch` | `AdminPrintController::batch` | — | 1239 |
| GET | `/admin/registrations` | `RegistrationController::listPending` | — | 1331 |
| POST | `/admin/registrations/{id}/approve` | `RegistrationController::approve` | — | 1333 |
| POST | `/admin/registrations/{id}/reject` | `RegistrationController::reject` | — | 1334 |
| GET | `/admin/risdoc` | `RisdocAdminController::page` | — | 1404 |
| GET | `/admin/risdoc/pending/{id}/preview` | `RisdocAdminController::pendingPreviewPage` | — | 1415 |
| GET | `/admin/sections` | `AdminSectionsController::index` | — | 157 |
| POST | `/admin/sections/assign` | `AdminSectionsController::assign` | `csrf` | 158 |
| POST | `/admin/sections/revoke` | `AdminSectionsController::revoke` | `csrf` | 160 |
| POST | `/admin/sections/student` | `AdminSectionsController::student` | `csrf audit_reason` | 162 |
| POST | `/admin/sections/subjects` | `AdminSectionsController::subjects` | `csrf` | 166 |
| GET | `/admin/sidebar-config` | `AdminSidebarConfigController::page` | — | 144 |
| POST | `/admin/sidebar-config/delete` | `AdminSidebarConfigController::delete` | `csrf` | 147 |
| POST | `/admin/sidebar-config/reorder` | `AdminSidebarConfigController::reorder` | `csrf` | 149 |
| POST | `/admin/sidebar-config/save` | `AdminSidebarConfigController::save` | `csrf` | 145 |
| GET | `/admin/subprocessors` | `AdminGdprController::subprocessorsIndex` | — | 232 |
| GET | `/admin/subprocessors/new` | `AdminGdprController::subprocessorsNewForm` | — | 233 |
| POST | `/admin/subprocessors/save` | `AdminGdprController::subprocessorsSave` | `csrf` | 235 |
| POST | `/admin/subprocessors/{id}/delete` | `AdminGdprController::subprocessorsDelete` | `csrf` | 237 |
| GET | `/admin/subprocessors/{id}/edit` | `AdminGdprController::subprocessorsEditForm` | — | 234 |
| POST | `/admin/system/2fa-enforce` | `AdminSystemController::twoFactorEnforceSet` | `csrf audit_reason` | 272 |
| POST | `/admin/system/capability/assign` | `AdminSystemController::capabilityAssign` | `csrf` | 281 |
| POST | `/admin/system/capability/profile/delete` | `AdminSystemController::capabilityProfileDelete` | `csrf` | 279 |
| POST | `/admin/system/capability/profile/save` | `AdminSystemController::capabilityProfileSave` | `csrf` | 277 |
| GET | `/admin/system/deployment` | `AdminSystemController::deploymentPage` | — | 258 |
| POST | `/admin/system/deployment/switch` | `AdminSystemController::deploymentSwitch` | `csrf` | 259 |
| POST | `/admin/system/registration-classes/add` | `AdminSystemController::registrationClassAdd` | `csrf` | 262 |
| POST | `/admin/system/registration-classes/remove` | `AdminSystemController::registrationClassRemove` | `csrf` | 264 |
| POST | `/admin/system/registration-mode` | `AdminSystemController::registrationModeSet` | `csrf` | 267 |
| POST | `/admin/system/tos-enforce` | `AdminSystemController::tosEnforceSet` | `csrf audit_reason` | 274 |
| GET | `/admin/takedown` | `AdminTakedownController::index` | — | 134 |
| GET | `/admin/takedown/{id}` | `AdminTakedownController::show` | — | 135 |
| POST | `/admin/takedown/{id}/action` | `AdminTakedownController::action` | `csrf` | 136 |
| GET | `/admin/templates` | `TemplatesAdminController::page` | — | 1400 |
| GET | `/admin/tools` | `AdminController::index` | — | 1276 |
| GET | `/admin/tools/hash` | `AdminController::hashToolPage` | — | 1275 |
| GET | `/admin/tos-log` | `AdminTosLogController::index` | — | 140 |
| GET | `/admin/waf` | `WafAdminController::index` | — | 1489 |
| GET | `/admin/waf/anomalies` | `WafAdminController::anomaliesPage` | — | 1498 |
| POST | `/admin/waf/api/blacklist` | `WafAdminController::apiAddBlacklist` | — | 1513 |
| DELETE | `/admin/waf/api/blacklist/{id}` | `WafAdminController::apiDeleteBlacklist` | — | 1514 |
| POST | `/admin/waf/api/config` | `WafAdminController::apiUpdateConfig` | — | 1507 |
| GET | `/admin/waf/api/counters` | `WafAdminController::apiCounters` | — | 1503 |
| GET | `/admin/waf/api/logs` | `WafAdminController::apiLogs` | — | 1502 |
| POST | `/admin/waf/api/rules` | `WafAdminController::apiCreateRule` | — | 1509 |
| DELETE | `/admin/waf/api/rules/{id}` | `WafAdminController::apiDeleteRule` | — | 1511 |
| PUT | `/admin/waf/api/rules/{id}` | `WafAdminController::apiUpdateRule` | — | 1510 |
| POST | `/admin/waf/api/rules/{id}/toggle` | `WafAdminController::apiToggleRule` | — | 1512 |
| POST | `/admin/waf/api/threat-intel/sync` | `WafAdminController::apiThreatIntelSync` | — | 1508 |
| POST | `/admin/waf/api/whitelist` | `WafAdminController::apiAddWhitelist` | — | 1515 |
| DELETE | `/admin/waf/api/whitelist/{id}` | `WafAdminController::apiDeleteWhitelist` | — | 1516 |
| GET | `/admin/waf/blocks` | `WafAdminController::blocksPage` | — | 1494 |
| GET | `/admin/waf/config` | `WafAdminController::configPage` | — | 1491 |
| GET | `/admin/waf/credentials` | `WafAdminController::credentialsPage` | — | 1497 |
| GET | `/admin/waf/dashboard` | `WafAdminController::dashboard` | — | 1490 |
| GET | `/admin/waf/diag` | `WafAdminController::diagPage` | — | 1501 |
| GET | `/admin/waf/lists` | `WafAdminController::listsPage` | — | 1496 |
| GET | `/admin/waf/reports` | `WafAdminController::reportsPage` | — | 1499 |
| GET | `/admin/waf/rules` | `WafAdminController::rulesPage` | — | 1492 |
| GET | `/admin/waf/threat-intel` | `WafAdminController::threatIntelPage` | — | 1500 |
| GET | `/admin/whoami` | `AdminController::whoAmI` | — | 1337 |

## /analytics

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/analytics/nav` | `AnalyticsController::navBeacon` | — | 342 |

## /api/access

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/access/status` | `TeacherCredentialController::studentStatus` | — | 380 |
| POST | `/api/access/student-login` | `TeacherCredentialController::studentLogin` | `csrf rate` | 376 |
| POST | `/api/access/student-logout` | `TeacherCredentialController::studentLogout` | `csrf` | 378 |

## /api/admin

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/admin/analytics` | `AdminAnalyticsController::snapshot` | — | 1283 |
| GET | `/api/admin/analytics/cross-search` | `AdminAnalyticsController::crossSearch` | — | 1285 |
| GET | `/api/admin/analytics/teacher/{id}` | `AdminAnalyticsController::forTeacher` | — | 1284 |
| GET | `/api/admin/badge-style-presets` | `BadgeStyleController::adminList` | — | 566 |
| DELETE | `/api/admin/badge-style-presets/{name}` | `BadgeStyleController::adminDelete` | — | 572 |
| GET | `/api/admin/badge-style-presets/{name}` | `BadgeStyleController::adminGet` | — | 568 |
| PUT | `/api/admin/badge-style-presets/{name}` | `BadgeStyleController::adminPut` | — | 570 |
| GET | `/api/admin/gdpr/teacher-content-search` | `AdminGdprController::teacherContentSearch` | — | 212 |
| GET | `/api/admin/infrastructure.json` | `AdminInfrastructureController::snapshotJson` | — | 1296 |
| GET | `/api/admin/latex-shortcuts` | `LatexShortcutsController::adminList` | — | 463 |
| POST | `/api/admin/latex-shortcuts` | `LatexShortcutsController::adminSave` | — | 468 |
| GET | `/api/admin/notifications` | `AdminController::notifications` | — | 1338 |
| GET | `/api/admin/risdoc/drift` | `RisdocAdminController::driftList` | — | 1408 |
| GET | `/api/admin/risdoc/options-source` | `RisdocAdminController::optionsSourceRead` | — | 1418 |
| POST | `/api/admin/risdoc/options-source` | `RisdocAdminController::optionsSourceSave` | — | 1444 |
| GET | `/api/admin/risdoc/options-sources` | `RisdocAdminController::optionsSourcesList` | — | 1417 |
| GET | `/api/admin/risdoc/pending` | `RisdocAdminController::pendingList` | — | 1410 |
| POST | `/api/admin/risdoc/pending/{id}/approve` | `RisdocAdminController::pendingApprove` | — | 1428 |
| GET | `/api/admin/risdoc/pending/{id}/content` | `RisdocAdminController::pendingContent` | — | 1411 |
| POST | `/api/admin/risdoc/pending/{id}/reject` | `RisdocAdminController::pendingReject` | — | 1430 |
| GET | `/api/admin/risdoc/pending/{id}/schema` | `RisdocAdminController::pendingSchema` | — | 1413 |
| GET | `/api/admin/risdoc/teachers` | `RisdocAdminController::teachersList` | — | 1407 |
| GET | `/api/admin/risdoc/templates` | `RisdocAdminController::templatesList` | — | 1405 |
| POST | `/api/admin/risdoc/templates/create` | `RisdocAdminController::createTemplate` | — | 1441 |
| POST | `/api/admin/risdoc/templates/rename-group` | `RisdocAdminController::renameGroup` | — | 1438 |
| GET | `/api/admin/risdoc/templates/{id}` | `RisdocAdminController::templateDetail` | — | 1406 |
| POST | `/api/admin/risdoc/templates/{id}/collaborators` | `RisdocAdminController::collaboratorsEdit` | — | 1425 |
| POST | `/api/admin/risdoc/templates/{id}/meta` | `RisdocAdminController::updateMeta` | — | 1436 |
| POST | `/api/admin/risdoc/templates/{id}/visibility` | `RisdocAdminController::visibilityBulk` | — | 1422 |
| POST | `/api/admin/risdoc/templates/{id}/visibility-scope` | `RisdocAdminController::setVisibilityScope` | — | 1433 |
| GET | `/api/admin/security/anomalies` | `SecurityAdminController::anomalies` | — | 1305 |
| GET | `/api/admin/security/blocked-credentials` | `SecurityAdminController::listBlockedCredentials` | — | 1303 |
| GET | `/api/admin/security/blocked-ips` | `SecurityAdminController::listBlockedIps` | — | 1304 |
| GET | `/api/admin/security/config` | `SecurityAdminController::getConfig` | — | 1307 |
| POST | `/api/admin/security/config` | `SecurityAdminController::setConfig` | — | 1319 |
| POST | `/api/admin/security/credentials/block` | `SecurityAdminController::blockCredential` | — | 1315 |
| POST | `/api/admin/security/credentials/unblock` | `SecurityAdminController::unblockCredential` | — | 1316 |
| POST | `/api/admin/security/ips/block` | `SecurityAdminController::blockIp` | — | 1317 |
| POST | `/api/admin/security/ips/unblock` | `SecurityAdminController::unblockIp` | — | 1318 |
| GET | `/api/admin/security/live-blocks` | `SecurityAdminController::liveBlocks` | — | 1306 |
| GET | `/api/admin/users` | `UsersAdminController::index` | — | 1302 |
| POST | `/api/admin/users/{id}/active` | `UsersAdminController::setActive` | — | 1312 |
| POST | `/api/admin/users/{id}/delete` | `UsersAdminController::delete` | — | 1314 |
| POST | `/api/admin/users/{id}/role` | `UsersAdminController::setRole` | — | 1313 |
| GET | `/api/admin/verifica/files` | `VerificaFilesAdminController::listFiles` | — | 1470 |
| POST | `/api/admin/verifica/files/copy-from-default` | `VerificaFilesAdminController::copyFromDefault` | — | 1461 |
| POST | `/api/admin/verifica/files/delete` | `VerificaFilesAdminController::deleteFile` | — | 1459 |
| GET | `/api/admin/verifica/files/read` | `VerificaFilesAdminController::readFile` | — | 1473 |
| POST | `/api/admin/verifica/files/write` | `VerificaFilesAdminController::writeFile` | — | 1457 |
| GET | `/api/admin/verifica/preamble` | `VerificaPreambleAdminController::get` | — | 1465 |
| POST | `/api/admin/verifica/preamble` | `VerificaPreambleAdminController::save` | — | 1450 |
| POST | `/api/admin/verifica/preamble/reset` | `VerificaPreambleAdminController::reset` | — | 1452 |
| GET | `/api/admin/verifica/scopes` | `VerificaFilesAdminController::listScopes` | — | 1468 |

## /api/institutes

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/institutes` | `InstituteController::index` | — | 358 |
| POST | `/api/institutes` | `InstituteController::create` | — | 1311 |

## /api/latex-shortcuts

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/latex-shortcuts/effective` | `LatexShortcutsController::effective` | — | 462 |
| POST | `/api/latex-shortcuts/reset` | `LatexShortcutsController::reset` | — | 466 |
| POST | `/api/latex-shortcuts/reset-all` | `LatexShortcutsController::resetAll` | — | 467 |
| POST | `/api/latex-shortcuts/save` | `LatexShortcutsController::save` | — | 465 |

## /api/maps

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/api/maps` | `MapsController::create` | `rate:content 60` | 896 |
| GET | `/api/maps/dl` | `MapsController::download` | — | 38 |
| POST | `/api/maps/sync-all` | `MapsController::syncAll` | `rate:content 30` | 908 |
| GET | `/api/maps/{id}/signed-url` | `MapsController::signedUrl` | — | 918 |
| POST | `/api/maps/{id}/sync` | `MapsController::sync` | `rate:content 60` | 906 |
| POST | `/api/maps/{id}/update` | `MapsController::update` | `rate:content 60` | 902 |

## /api/probe

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/api/probe` | `CsrfProbeController::probe` | — | 610 |

## /api/public

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/public/study/content.json` | `ContentStudyController::publicContentJson` | `rate:pub_study 120` | 317 |
| GET | `/api/public/study/topics.json` | `ContentStudyController::publicTopicsJson` | `rate:pub_study 120` | 315 |

## /api/risdoc

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/risdoc/compilations/{id}` | `CompilationController::show` | — | 1186 |
| POST | `/api/risdoc/compilations/{id}/delete` | `CompilationController::delete` | — | 1134 |
| GET | `/api/risdoc/curriculum-options` | `CurriculumOptionsController::options` | — | 1157 |
| POST | `/api/risdoc/curriculum-options` | `CurriculumOptionsController::save` | — | 1114 |
| POST | `/api/risdoc/curriculum-options/delete` | `CurriculumOptionsController::delete` | — | 1116 |
| GET | `/api/risdoc/exports/{file}` | `ExportController::serve` | — | 1176 |
| GET | `/api/risdoc/options-sources` | `TemplateController::optionsSources` | — | 1179 |
| GET | `/api/risdoc/shared/{file}` | `TemplateController::sharedAsset` | — | 1181 |
| GET | `/api/risdoc/teacher/instances` | `TemplateController::teacherAllInstances` | — | 1170 |
| GET | `/api/risdoc/templates` | `TemplateController::index` | — | 1147 |
| GET | `/api/risdoc/templates/{id}` | `TemplateController::show` | — | 1149 |
| POST | `/api/risdoc/templates/{id}/body-pt` | `TemplateController::saveBodyPt` | — | 1105 |
| GET | `/api/risdoc/templates/{id}/compilations` | `CompilationController::index` | — | 1184 |
| POST | `/api/risdoc/templates/{id}/compilations` | `CompilationController::save` | — | 1132 |
| POST | `/api/risdoc/templates/{id}/compile-pdf` | `TexFilesController::compilePdf` | — | 1095 |
| GET | `/api/risdoc/templates/{id}/drift` | `TemplateController::driftStatus` | — | 1174 |
| POST | `/api/risdoc/templates/{id}/export` | `ExportController::export` | — | 1087 |
| GET | `/api/risdoc/templates/{id}/file` | `TemplateController::file` | — | 1151 |
| GET | `/api/risdoc/templates/{id}/instances` | `TemplateController::instancesList` | — | 1167 |
| POST | `/api/risdoc/templates/{id}/instances` | `TemplateController::instancesCreate` | `rate:instances 60` | 1121 |
| POST | `/api/risdoc/templates/{id}/instances/{key}/delete` | `TemplateController::instancesDelete` | `rate:instances 60` | 1124 |
| POST | `/api/risdoc/templates/{id}/instances/{key}/rename` | `TemplateController::instancesRename` | `rate:instances 60` | 1127 |
| POST | `/api/risdoc/templates/{id}/institutional-override` | `TemplateController::institutionalOverrideSave` | — | 1108 |
| POST | `/api/risdoc/templates/{id}/institutional-override/del` | `TemplateController::institutionalOverrideDelete` | — | 1110 |
| GET | `/api/risdoc/templates/{id}/institutional-overrides` | `TemplateController::institutionalOverridesList` | — | 1164 |
| GET | `/api/risdoc/templates/{id}/json-files` | `TemplateController::jsonFiles` | — | 1172 |
| POST | `/api/risdoc/templates/{id}/override` | `TemplateController::overrideSave` | — | 1083 |
| POST | `/api/risdoc/templates/{id}/override/del` | `TemplateController::overrideDelete` | — | 1085 |
| GET | `/api/risdoc/templates/{id}/overrides` | `TemplateController::overridesList` | — | 1161 |
| GET | `/api/risdoc/templates/{id}/schema` | `TemplateController::schema` | — | 1153 |
| GET | `/api/risdoc/templates/{id}/tex` | `TemplateController::tex` | — | 1159 |
| POST | `/api/risdoc/templates/{id}/tex-files` | `TexFilesController::getFiles` | — | 1091 |
| POST | `/api/risdoc/templates/{id}/tex-files/save` | `TexFilesController::saveFiles` | — | 1093 |

## /api/scuole

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/scuole` | `SchoolsController::search` | — | 361 |

## /api/sidebar

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/sidebar/config` | `SidebarConfigController::config` | — | 523 |

## /api/sidepage

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/sidepage/topics` | `SidepageController::topics` | — | 370 |

## /api/sources

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/sources/common` | `StudySourcesController::sourcesCommonJson` | — | 603 |

## /api/studio

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/studio/exercise/{id}.json` | `ExerciseStudyController::exerciseJson` | — | 519 |
| GET | `/api/studio/exercises.json` | `ExerciseStudyController::exercisesJson` | — | 517 |
| GET | `/api/studio/topics.json` | `ExerciseStudyController::topicsJson` | — | 515 |

## /api/study

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/study/content.json` | `ContentStudyController::contentJson` | — | 528 |
| GET | `/api/study/content/{id}.json` | `ContentStudyController::contentSingleJson` | — | 530 |
| GET | `/api/study/header-page.json` | `StudyHeaderController::headerPageStudentJson` | — | 541 |
| GET | `/api/study/related-verifiche.html` | `ContentStudyController::relatedVerificaHtml` | — | 546 |
| GET | `/api/study/topics.json` | `ContentStudyController::topicsJson` | — | 526 |
| GET | `/api/study/verifica/list` | `VerificaController::listForStudent` | — | 535 |

## /api/teacher

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/teacher/badge-style` | `BadgeStyleController::teacherGet` | — | 559 |
| PUT | `/api/teacher/badge-style` | `BadgeStyleController::teacherPut` | — | 561 |
| GET | `/api/teacher/capabilities` | `TeacherContentController::capabilities` | — | 976 |
| GET | `/api/teacher/category-labels` | `TeacherCategoryLabelController::list` | — | 1001 |
| POST | `/api/teacher/category-labels` | `TeacherCategoryLabelController::save` | — | 1004 |
| GET | `/api/teacher/checked-origins.json` | `StudySourcesController::checkedOriginsJson` | — | 590 |
| PUT | `/api/teacher/checked-origins.json` | `StudySourcesController::checkedOriginsSave` | — | 592 |
| GET | `/api/teacher/content` | `TeacherContentController::index` | — | 993 |
| POST | `/api/teacher/content` | `TeacherContentController::store` | `rate:content 60` | 1017 |
| GET | `/api/teacher/content/{id}` | `TeacherContentController::show` | — | 995 |
| POST | `/api/teacher/content/{id}/compile-pdf` | `ContentExportController::compilePdf` | `rate:compile 15` | 1046 |
| GET | `/api/teacher/content/{id}/contract` | `ContentExportController::contract` | — | 982 |
| POST | `/api/teacher/content/{id}/delete` | `TeacherContentController::destroy` | `rate:content 60` | 1023 |
| POST | `/api/teacher/content/{id}/export` | `ContentExportController::export` | — | 1037 |
| GET | `/api/teacher/content/{id}/export-html` | `ContentExportController::exportHtml` | — | 998 |
| POST | `/api/teacher/content/{id}/group/add` | `GroupController::groupAdd` | — | 1073 |
| POST | `/api/teacher/content/{id}/group/{groupRef}/delete` | `GroupController::groupDelete` | — | 1079 |
| POST | `/api/teacher/content/{id}/group/{groupRef}/move` | `GroupController::groupMove` | — | 1070 |
| POST | `/api/teacher/content/{id}/group/{groupRef}/patch` | `GroupController::groupPatch` | — | 1076 |
| GET | `/api/teacher/content/{id}/provenance` | `ContentExportController::provenance` | — | 1052 |
| POST | `/api/teacher/content/{id}/publish` | `ContentPublishController::publish` | — | 1032 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/clone-to-eser` | `QuesitoController::quesitoCloneToEser` | — | 1067 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/delete` | `QuesitoController::quesitoDelete` | — | 1060 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/duplicate` | `QuesitoController::quesitoDuplicate` | — | 1064 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/move` | `QuesitoController::quesitoMove` | — | 1062 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/patch` | `QuesitoController::quesitoPatch` | — | 1058 |
| POST | `/api/teacher/content/{id}/recategorize` | `TeacherContentController::recategorize` | `rate:content 60` | 1029 |
| POST | `/api/teacher/content/{id}/share-pool` | `ContentPublishController::sharePool` | — | 1050 |
| POST | `/api/teacher/content/{id}/tex-files` | `ContentExportController::texFiles` | — | 1040 |
| POST | `/api/teacher/content/{id}/tex-files/save` | `ContentExportController::saveTexFiles` | — | 1042 |
| POST | `/api/teacher/content/{id}/unpublish` | `ContentPublishController::unpublish` | — | 1034 |
| POST | `/api/teacher/content/{id}/update` | `TeacherContentController::update` | `rate:content 60` | 1020 |
| GET | `/api/teacher/credentials` | `TeacherCredentialController::index` | — | 935 |
| POST | `/api/teacher/credentials` | `TeacherCredentialController::create` | — | 942 |
| POST | `/api/teacher/credentials/{id}/delete` | `TeacherCredentialController::delete` | — | 944 |
| POST | `/api/teacher/credentials/{id}/toggle` | `TeacherCredentialController::toggle` | — | 946 |
| GET | `/api/teacher/curriculum` | `CurriculumController::index` | — | 969 |
| GET | `/api/teacher/curriculum/pivot` | `TeacherCurriculumPivotController::listMine` | — | 971 |
| POST | `/api/teacher/curriculum/pivot/toggle` | `TeacherCurriculumPivotController::toggle` | — | 1014 |
| POST | `/api/teacher/curriculum/{id}/remove` | `CurriculumController::remove` | — | 1011 |
| POST | `/api/teacher/curriculum/{id}/update` | `CurriculumController::update` | — | 1009 |
| POST | `/api/teacher/curriculum/{kind}` | `CurriculumController::add` | — | 1007 |
| GET | `/api/teacher/drawio/libraries` | `TeacherDrawioLibraryController::list` | — | 839 |
| POST | `/api/teacher/drawio/libraries/delete` | `TeacherDrawioLibraryController::delete` | — | 884 |
| GET | `/api/teacher/drawio/libraries/read/{name}` | `TeacherDrawioLibraryController::read` | — | 841 |
| POST | `/api/teacher/drawio/libraries/save-content` | `TeacherDrawioLibraryController::saveContent` | — | 888 |
| POST | `/api/teacher/drawio/libraries/upload` | `TeacherDrawioLibraryController::upload` | — | 882 |
| POST | `/api/teacher/github/configure` | `TeacherGitHubController::configure` | — | 873 |
| POST | `/api/teacher/github/disconnect` | `TeacherGitHubController::disconnect` | — | 874 |
| POST | `/api/teacher/github/push-file` | `TeacherGitHubController::pushFile` | — | 877 |
| GET | `/api/teacher/github/status` | `TeacherGitHubController::status` | — | 837 |
| POST | `/api/teacher/github/sync-all` | `TeacherGitHubController::syncAll` | — | 876 |
| POST | `/api/teacher/github/sync-test` | `TeacherGitHubController::syncTest` | — | 875 |
| GET | `/api/teacher/header-page.json` | `StudyHeaderController::headerPageJson` | — | 597 |
| PUT | `/api/teacher/header-page.json` | `StudyHeaderController::headerPageSave` | — | 599 |
| POST | `/api/teacher/import-bundle/apply` | `ImportBundleController::apply` | `rate:import 4` | 853 |
| POST | `/api/teacher/import-bundle/preview` | `ImportBundleController::preview` | `rate:import 4` | 850 |
| GET | `/api/teacher/institutes` | `InstituteController::listForTeacher` | — | 933 |
| POST | `/api/teacher/institutes/link` | `InstituteController::link` | — | 938 |
| POST | `/api/teacher/institutes/{id}/unlink` | `InstituteController::unlink` | — | 940 |
| GET | `/api/teacher/manifest/{type}` | `ContentExportController::manifest` | — | 979 |
| GET | `/api/teacher/my-classes` | `TeacherContentController::myClasses` | — | 990 |
| GET | `/api/teacher/origins.json` | `StudySourcesController::originsJson` | — | 586 |
| POST | `/api/teacher/pdf-import/provider-cache` | `PdfImportController::toggleCache` | `rate:pdf_import 30` | 751 |
| GET | `/api/teacher/pdf-import/provider-keys` | `PdfImportController::providerKeysStatus` | — | 668 |
| POST | `/api/teacher/pdf-import/provider-keys` | `PdfImportController::saveProviderKey` | `rate:pdf_import 30` | 742 |
| POST | `/api/teacher/pdf-import/provider-keys/clear` | `PdfImportController::clearProviderKey` | `rate:pdf_import 30` | 757 |
| GET | `/api/teacher/pdf-import/provider-operations` | `PdfImportController::providerOperations` | — | 672 |
| POST | `/api/teacher/pdf-import/provider-operations` | `PdfImportController::saveProviderOperation` | `rate:pdf_import 30` | 745 |
| POST | `/api/teacher/pdf-import/provider-prompt` | `PdfImportController::saveProviderPrompt` | `rate:pdf_import 30` | 748 |
| POST | `/api/teacher/pdf-import/session` | `PdfImportController::createSession` | `rate:pdf_import_llm 12` | 715 |
| GET | `/api/teacher/pdf-import/session/{id}` | `PdfImportController::status` | — | 661 |
| POST | `/api/teacher/pdf-import/session/{id}/bulk` | `PdfImportController::bulkEdit` | `rate:pdf_import 30` | 721 |
| POST | `/api/teacher/pdf-import/session/{id}/cell` | `PdfImportController::editCell` | `rate:pdf_import 30` | 718 |
| POST | `/api/teacher/pdf-import/session/{id}/difficulty` | `PdfImportController::refineDifficulty` | `rate:pdf_import_llm 12` | 730 |
| POST | `/api/teacher/pdf-import/session/{id}/insert` | `PdfImportController::insert` | `rate:pdf_import 30` | 739 |
| GET | `/api/teacher/pdf-import/session/{id}/page/{n}` | `PdfImportController::pageImage` | — | 663 |
| GET | `/api/teacher/pdf-import/session/{id}/preview` | `PdfImportController::previewRow` | — | 665 |
| POST | `/api/teacher/pdf-import/session/{id}/solutions` | `PdfImportController::generateSolutions` | `rate:pdf_import_llm 12` | 724 |
| POST | `/api/teacher/pdf-import/session/{id}/stop` | `PdfImportController::stopSession` | `rate:pdf_import 30` | 733 |
| POST | `/api/teacher/pdf-import/session/{id}/topics` | `PdfImportController::generateTopics` | `rate:pdf_import_llm 12` | 727 |
| POST | `/api/teacher/pdf-import/session/{id}/translate` | `PdfImportController::translate` | `rate:pdf_import_llm 30` | 736 |
| GET | `/api/teacher/pdf-import/sessions` | `PdfImportController::listSessions` | — | 659 |
| POST | `/api/teacher/pdf-import/setting` | `PdfImportController::toggleSetting` | `rate:pdf_import 30` | 754 |
| GET | `/api/teacher/pool/materials` | `PoolController::materials` | — | 822 |
| GET | `/api/teacher/pool/my-shares` | `PoolController::myShares` | — | 825 |
| POST | `/api/teacher/pool/recover/{id}` | `PoolController::recover` | `rate:pool_recover 30` | 858 |
| POST | `/api/teacher/pool/unshare` | `PoolController::unshare` | — | 862 |
| GET | `/api/teacher/print-info` | `PrintInfoController::show` | — | 805 |
| POST | `/api/teacher/print-info` | `PrintInfoController::save` | — | 777 |
| POST | `/api/teacher/print-info/delete` | `PrintInfoController::delete` | — | 778 |
| GET | `/api/teacher/print-info/list` | `PrintInfoController::index` | — | 806 |
| POST | `/api/teacher/recovery-key/generate` | `TeacherRecoveryController::generate` | — | 845 |
| POST | `/api/teacher/recovery-key/revoke` | `TeacherRecoveryController::revoke` | — | 847 |
| GET | `/api/teacher/recovery-key/status` | `TeacherRecoveryController::status` | — | 799 |
| GET | `/api/teacher/risdoc/templates/files` | `TeacherTexCommonController::getFiles` | — | 1213 |
| POST | `/api/teacher/risdoc/templates/files/preview-pdf` | `TeacherTexCommonController::previewPdf` | — | 1101 |
| POST | `/api/teacher/risdoc/templates/files/save` | `TeacherTexCommonController::saveFiles` | — | 1098 |
| GET | `/api/teacher/share/colleagues` | `ShareGrantsController::listColleagues` | — | 832 |
| GET | `/api/teacher/share/grants/{source}/{id}` | `ShareGrantsController::listGrants` | — | 828 |
| POST | `/api/teacher/share/grants/{source}/{id}` | `ShareGrantsController::setGrants` | — | 865 |
| GET | `/api/teacher/share/groups` | `ShareGrantsController::listGroups` | — | 830 |
| POST | `/api/teacher/share/groups` | `ShareGrantsController::createGroup` | — | 867 |
| POST | `/api/teacher/share/groups/{id}/delete` | `ShareGrantsController::deleteGroup` | — | 871 |
| GET | `/api/teacher/share/groups/{id}/members` | `ShareGrantsController::listMembers` | — | 834 |
| POST | `/api/teacher/share/groups/{id}/members` | `ShareGrantsController::setMembers` | — | 869 |
| GET | `/api/teacher/sources.json` | `StudySourcesController::sourcesCommonJson` | — | 580 |
| PUT | `/api/teacher/sources.json` | `StudySourcesController::sourcesSave` | — | 582 |
| GET | `/api/teacher/sources.registry.json` | `StudySourcesController::sourcesRegistryJson` | — | 552 |
| PUT | `/api/teacher/sources.registry.json` | `StudySourcesController::sourcesRegistrySave` | — | 554 |
| GET | `/api/teacher/sync-bundle/manifest` | `VerificaSyncController::manifestSigned` | — | 797 |
| GET | `/api/teacher/sync-local-bundle` | `VerificaSyncController::localBundle` | — | 795 |
| POST | `/api/teacher/sync/cleanup-orphans` | `TeacherSyncCleanupController::cleanupOrphans` | — | 879 |
| GET | `/api/teacher/templates.json` | `ContentTemplateController::templatesJson` | — | 647 |
| PUT | `/api/teacher/templates.json` | `ContentTemplateController::templatesSave` | — | 678 |
| GET | `/api/teacher/verifica/files` | `TeacherVerificaFilesController::listFiles` | — | 1212 |
| POST | `/api/teacher/verifica/files/copy-from-base` | `TeacherVerificaFilesController::copyFromBase` | — | 953 |
| POST | `/api/teacher/verifica/files/delete` | `TeacherVerificaFilesController::deleteFile` | — | 951 |
| POST | `/api/teacher/verifica/files/preview-pdf` | `TeacherVerificaFilesController::previewPdf` | — | 957 |
| GET | `/api/teacher/verifica/files/read` | `TeacherVerificaFilesController::readFile` | — | 1215 |
| POST | `/api/teacher/verifica/files/write` | `TeacherVerificaFilesController::writeFile` | — | 949 |

## /api/tenant

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/tenant/current` | `TenantController::current` | — | 337 |
| POST | `/api/tenant/switch` | `TenantController::switch` | `csrf` | 335 |

## /api/tex

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/api/tex/compile-adhoc-pdf` | `TexAdhocCompileController::compileTikzPdf` | — | 711 |

## /api/verifica

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/verifica/batch/{batchId}/files` | `VerificaBatchController::batchFiles` | — | 793 |
| GET | `/api/verifica/batch/{batchId}/zip` | `VerificaBatchController::batchZip` | — | 790 |
| GET | `/api/verifica/jobs/{jobId}` | `VerificaCompileController::getJob` | — | 695 |
| GET | `/api/verifica/list` | `VerificaController::listForTeacher` | — | 785 |
| POST | `/api/verifica/save-tex` | `VerificaController::saveTex` | — | 685 |
| POST | `/api/verifica/save-tex-batch` | `VerificaController::saveTexBatch` | — | 686 |
| POST | `/api/verifica/sync-all` | `VerificaSyncController::syncAll` | `rate:content 30` | 911 |
| POST | `/api/verifica/{id}/compile` | `VerificaCompileController::compilePdf` | — | 692 |
| POST | `/api/verifica/{id}/compile-async` | `VerificaCompileController::compileAsync` | — | 694 |
| POST | `/api/verifica/{id}/delete` | `VerificaController::delete` | — | 706 |
| POST | `/api/verifica/{id}/geogebra-attach` | `VerificaController::geogebraAttach` | — | 699 |
| GET | `/api/verifica/{id}/pdf` | `VerificaController::viewPdf` | — | 787 |
| POST | `/api/verifica/{id}/pdf` | `VerificaController::uploadPdf` | — | 687 |
| POST | `/api/verifica/{id}/share-pool` | `VerificaController::sharePool` | — | 708 |
| POST | `/api/verifica/{id}/synctex/edit` | `VerificaCompileController::synctexEdit` | — | 705 |
| GET | `/api/verifica/{id}/tex` | `VerificaController::downloadTex` | — | 786 |
| POST | `/api/verifica/{id}/tex` | `VerificaController::updateTex` | — | 697 |
| GET | `/api/verifica/{id}/tex-files` | `VerificaController::getTexFiles` | — | 701 |
| POST | `/api/verifica/{id}/tex-files` | `VerificaController::updateTexFiles` | — | 702 |
| GET | `/api/verifica/{id}/zip` | `VerificaController::zipExport` | — | 788 |

## /api/vitals

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/api/vitals` | `AnalyticsController::webVitals` | `rate:vitals 120` | 344 |

## /area-docente

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/area-docente` | `(closure)` | — | 1198 |
| GET | `/area-docente/categorie` | `TeacherProfileController::categoriePage` | — | 1205 |
| GET | `/area-docente/da-categorizzare` | `TeacherUncategorizedController::index` | — | 1208 |
| POST | `/area-docente/da-categorizzare` | `TeacherUncategorizedController::save` | `csrf` | 1209 |
| GET | `/area-docente/dashboard` | `TeacherController::dashboard` | — | 1199 |
| GET | `/area-docente/fonti` | `TeacherProfileController::fontiPage` | — | 1211 |
| GET | `/area-docente/materie` | `TeacherSubjectsController::form` | — | 618 |
| POST | `/area-docente/materie` | `TeacherSubjectsController::save` | `csrf` | 619 |
| GET | `/area-docente/pdf-import` | `PdfImportPageController::page` | — | 1201 |
| GET | `/area-docente/pdf-import/models` | `PdfImportPageController::modelsPage` | — | 1202 |
| GET | `/area-docente/profilo` | `TeacherProfileController::page` | — | 1203 |
| GET | `/area-docente/resources` | `TeacherController::resources` | — | 1200 |
| GET | `/area-docente/templates` | `TeacherProfileController::templatesPage` | — | 1204 |

## /auth

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/auth/cie/callback` | `CieController::callback` | — | 75 |
| GET | `/auth/cie/login` | `CieController::login` | — | 74 |
| GET | `/auth/cie/logout` | `CieController::logout` | — | 77 |
| GET | `/auth/cie/metadata` | `CieController::metadata` | — | 76 |
| GET | `/auth/csrf` | `AuthController::csrf` | — | 65 |
| GET | `/auth/grafana-gate` | `GrafanaGateController::gate` | — | 289 |
| GET | `/auth/spid/callback` | `SpidController::callback` | — | 71 |
| GET | `/auth/spid/login` | `SpidController::login` | — | 70 |
| GET | `/auth/spid/logout` | `SpidController::logout` | — | 73 |
| GET | `/auth/spid/metadata` | `SpidController::metadata` | — | 72 |
| GET | `/auth/user-info` | `AuthController::userInfo` | — | 64 |

## /check

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/check/file-protection` | `CheckController::fileProtection` | — | 1380 |
| ANY | `/check/password` | `CheckController::password` | — | 1379 |

## /cookies_privacy-policy.html

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/cookies_privacy-policy.html` | `(closure)` | — | 385 |

## /curriculum

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/curriculum` | `CurriculumController::index` | `rate:curriculum 180` | 354 |

## /delete_temp.php

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/delete_temp.php` | `CronController::deleteTemp` | — | 1385 |

## /didattica

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/didattica/{path*}` | `(?)` | `legacy_gone` | 421 |

## /dpo-contact

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/dpo-contact` | `DpoContactController::show` | — | 117 |
| POST | `/dpo-contact` | `DpoContactController::submit` | `csrf rate:dpo 3` | 118 |

## /drafts

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/drafts/{path*}` | `(?)` | `legacy_gone` | 1223 |

## /eser

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/eser/{path*}` | `(?)` | `legacy_gone` | 420 |

## /exercises

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/exercises` | `ExerciseController::searchPage` | — | 809 |
| GET | `/exercises/search.json` | `ExerciseController::searchJson` | — | 810 |

## /favicon.ico

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/favicon.ico` | `(closure)` | — | 18 |

## /files

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/files/clear-temp` | `FileController::clearTemp` | — | 1251 |
| POST | `/files/delete` | `FileController::deleteFile` | — | 1248 |
| POST | `/files/delete-folder` | `FileController::deleteFolder` | — | 1249 |
| GET | `/files/list` | `FileController::list` | — | 1253 |
| POST | `/files/save-image` | `FileController::saveImage` | — | 1354 |
| POST | `/files/save-latex` | `FileController::saveLatex` | — | 1247 |
| POST | `/files/save-pdf` | `FileController::savePdf` | — | 1355 |
| POST | `/files/save-tex` | `FileController::saveTex` | — | 1246 |

## /geogebra

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/geogebra/catalog` | `GeoGebraCatalogController::list` | — | 473 |
| POST | `/geogebra/catalog/delete` | `GeoGebraCatalogController::delete` | — | 477 |
| POST | `/geogebra/catalog/save` | `GeoGebraCatalogController::save` | — | 476 |
| GET | `/geogebra/catalog/{id}` | `GeoGebraCatalogController::get` | — | 474 |

## /health

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/health` | `HealthController::health` | — | 41 |
| GET | `/health/backup` | `HealthController::backupFreshness` | — | 42 |

## /lab

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/lab/{path*}` | `(?)` | `legacy_gone` | 422 |

## /legal

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/legal/ai-act` | `TrustPagesController::aiAct` | — | 327 |
| GET | `/legal/ai-literacy` | `TrustPagesController::aiLiteracy` | — | 328 |
| GET | `/legal/aup` | `TrustPagesController::aup` | — | 323 |
| GET | `/legal/dpa` | `TrustPagesController::dpa` | — | 325 |
| GET | `/legal/takedown-procedure` | `TrustPagesController::takedownProcedure` | — | 324 |
| GET | `/legal/tos` | `TrustPagesController::tos` | — | 322 |

## /log

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/log/admin/{path*}` | `LogServeController::show` | — | 1389 |
| ANY | `/log/auth/{path*}` | `LogServeController::show` | — | 1542 |
| ANY | `/log/logging/{path*}` | `LogServeController::show` | — | 1390 |
| ANY | `/log/logout/{path*}` | `LogServeController::show` | — | 1544 |
| ANY | `/log/security/{path*}` | `LogServeController::show` | — | 1391 |

## /login

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/login` | `AuthController::showLogin` | — | 43 |
| POST | `/login` | `AuthController::login` | `csrf rate:login 10` | 49 |
| GET | `/login/2fa` | `AuthController::show2fa` | — | 53 |
| POST | `/login/2fa` | `AuthController::verify2fa` | `csrf rate:login 10` | 54 |

## /logout

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/logout` | `AuthController::logout` | — | 55 |

## /mappe

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/mappe/{path*}` | `(?)` | `legacy_gone` | 406 |

## /me

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/me/2fa` | `TotpController::page` | — | 87 |
| POST | `/me/2fa/disable` | `TotpController::disable` | `csrf` | 92 |
| POST | `/me/2fa/enable` | `TotpController::enable` | `csrf` | 89 |
| POST | `/me/2fa/enable-email` | `TotpController::enableEmail` | `csrf` | 91 |
| POST | `/me/2fa/setup` | `TotpController::setup` | `csrf` | 88 |
| POST | `/me/2fa/setup-email` | `TotpController::setupEmail` | `csrf` | 90 |
| POST | `/me/cancel-deletion` | `SelfServiceController::cancelDeletion` | `csrf` | 104 |
| GET | `/me/change-password` | `UserProfileController::showChangePassword` | — | 80 |
| POST | `/me/change-password` | `UserProfileController::changePassword` | `csrf` | 81 |
| GET | `/me/confirm-deletion` | `SelfServiceController::confirmDeletion` | — | 103 |
| GET | `/me/consents` | `SelfServiceController::consentsList` | — | 96 |
| POST | `/me/consents/grant` | `SelfServiceController::consentGrant` | `csrf` | 99 |
| POST | `/me/consents/revoke` | `SelfServiceController::consentRevoke` | `csrf` | 100 |
| GET | `/me/custody-events` | `SelfServiceController::custodyEvents` | — | 98 |
| GET | `/me/deletion-status` | `SelfServiceController::deletionStatus` | — | 105 |
| GET | `/me/export-data` | `SelfServiceController::exportData` | `rate:export 3` | 107 |
| POST | `/me/profile` | `SelfServiceController::profilePatch` | `csrf` | 108 |
| POST | `/me/request-deletion` | `SelfServiceController::requestDeletion` | `csrf rate:deletion 5` | 102 |

## /metrics

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/metrics` | `MetricsController::show` | — | 341 |

## /modelli_tikz.json

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/modelli_tikz.json` | `TikzDataController::show` | — | 1232 |

## /modelli_tikz_elements.json

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/modelli_tikz_elements.json` | `TikzDataController::show` | — | 1233 |

## /modelli_tikz_traccia.json

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/modelli_tikz_traccia.json` | `TikzDataController::show` | — | 1234 |

## /modello_pag_listSidebar.php

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/modello_pag_listSidebar.php` | `AdminPartialController::show` | — | 427 |

## /parent-consent

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/parent-consent/{token}` | `ParentConsentController::preview` | — | 112 |
| POST | `/parent-consent/{token}` | `ParentConsentController::confirm` | — | 113 |

## /password

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/password/forgot` | `PasswordResetController::showForgot` | — | 60 |
| POST | `/password/forgot` | `PasswordResetController::submitForgot` | `csrf rate:login 5` | 61 |
| GET | `/password/reset` | `PasswordResetController::showReset` | — | 62 |
| POST | `/password/reset` | `PasswordResetController::submitReset` | `csrf rate:login 10` | 63 |

## /privacy

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/privacy/informativa` | `TrustPagesController::informativa` | — | 301 |
| GET | `/privacy/your-data` | `TrustPagesController::yourData` | — | 300 |

## /public

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/public/sidebar/{key}` | `PublicSidebarController::section` | `rate:pub_sidebar 120` | 305 |
| GET | `/public/studio/{id}` | `ContentStudyController::publicView` | `rate:pub_view 120` | 309 |

## /register

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/register` | `RegistrationController::showForm` | — | 350 |
| POST | `/register` | `RegistrationController::submit` | `csrf` | 351 |

## /risdoc

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/risdoc/edit/{id}` | `TemplateEditorController::show` | — | 1141 |
| GET | `/risdoc/view/{id}` | `TemplateViewController::show` | — | 1139 |
| GET | `/risdoc/{category}/php/{filename}` | `TemplateViewController::showByLegacyPath` | — | 1145 |
| ANY | `/risdoc/{path*}` | `(?)` | `legacy_gone` | 1221 |
| GET | `/risdoc/{path*}` | `TemplateController::legacyPath` | — | 1190 |

## /security

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/security` | `TrustPagesController::security` | — | 299 |

## /segnalazione-contenuti

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/segnalazione-contenuti` | `PublicTakedownController::showForm` | — | 123 |
| POST | `/segnalazione-contenuti` | `PublicTakedownController::submit` | `rate:takedown 3` | 124 |

## /storage

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/storage/signed` | `StorageController::signed` | — | 365 |

## /strcomp_bes_altro

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/strcomp_bes_altro/{path*}` | `(?)` | `legacy_gone` | 1222 |

## /studio

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/studio/{indirizzo}/{classe}/{materia}` | `ExerciseStudyController::topicsPage` | — | 511 |
| GET | `/studio/{indirizzo}/{classe}/{materia}/{topic}` | `ExerciseStudyController::topicPage` | — | 513 |
| GET | `/studio/{type}/{ind}/{cls}/{subj}` | `ContentStudyController::topicsPage` | — | 503 |
| GET | `/studio/{type}/{ind}/{cls}/{subj}/{topic}` | `ContentStudyController::topicPage` | — | 505 |

## /teacher

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/teacher` | `(closure)` | — | 629 |
| GET | `/teacher/dashboard` | `(closure)` | — | 630 |
| GET | `/teacher/drive/callback` | `DriveController::callback` | — | 818 |
| GET | `/teacher/drive/connect` | `DriveController::connect` | — | 816 |
| GET | `/teacher/drive/connect-migration` | `DriveController::connectMigration` | — | 817 |
| POST | `/teacher/drive/disconnect` | `DriveController::disconnect` | — | 890 |
| GET | `/teacher/drive/status.json` | `DriveController::status` | — | 819 |
| GET | `/teacher/pdf-import` | `(closure)` | — | 655 |
| GET | `/teacher/pdf-import/models` | `(closure)` | — | 657 |
| POST | `/teacher/print` | `TeacherPrintController::generate` | — | 680 |
| GET | `/teacher/resources` | `(closure)` | — | 638 |
| GET | `/teacher/templates` | `(closure)` | — | 644 |

## /tex

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/tex/format` | `TexFormatController::format` | — | 447 |

## /tikz

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/tikz/admin-library` | `TeacherWorkspaceController::getAdminLibrary` | — | 485 |
| GET | `/tikz/content` | `TikzController::content` | — | 1265 |
| POST | `/tikz/delete-element` | `TikzController::deleteElement` | — | 1370 |
| POST | `/tikz/delete-svg` | `TikzController::deleteSvg` | — | 1263 |
| POST | `/tikz/edit-element` | `TikzController::editElement` | — | 1369 |
| GET | `/tikz/effective-templates` | `TeacherTemplateController::effective` | — | 453 |
| GET | `/tikz/ensure-json` | `TikzController::ensureJson` | — | 1266 |
| ANY | `/tikz/generate-json` | `TikzController::generateJson` | — | 1371 |
| GET | `/tikz/render` | `TikzRenderController::lookup` | — | 435 |
| POST | `/tikz/render` | `TikzRenderController::render` | — | 442 |
| POST | `/tikz/save-new-element` | `TikzController::saveNewElement` | — | 1368 |
| POST | `/tikz/save-svg` | `TikzController::saveSvg` | — | 1262 |
| POST | `/tikz/teacher-templates/reset` | `TeacherTemplateController::reset` | — | 456 |
| POST | `/tikz/teacher-templates/save` | `TeacherTemplateController::save` | — | 455 |
| GET | `/tikz/workspace` | `TeacherWorkspaceController::getWorkspace` | — | 484 |
| POST | `/tikz/workspace/element/delete` | `TeacherWorkspaceController::deleteElement` | — | 488 |
| POST | `/tikz/workspace/element/save` | `TeacherWorkspaceController::saveElement` | — | 487 |
| POST | `/tikz/workspace/group/delete` | `TeacherWorkspaceController::deleteGroup` | — | 490 |
| POST | `/tikz/workspace/group/rename` | `TeacherWorkspaceController::renameGroup` | — | 489 |
| POST | `/tikz/workspace/group/reorder` | `TeacherWorkspaceController::reorderGroups` | — | 491 |
| POST | `/tikz/workspace/import` | `TeacherWorkspaceController::importFromAdmin` | — | 493 |
| POST | `/tikz/workspace/reset-all` | `TeacherWorkspaceController::resetAll` | — | 492 |

## /tikzjax.js

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/tikzjax.js` | `(closure)` | — | 393 |

## /tos-acceptance

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/tos-acceptance` | `TosAcceptanceController::show` | `auth` | 293 |
| POST | `/tos-acceptance` | `TosAcceptanceController::submit` | `auth csrf` | 295 |

## /verifiche

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/verifiche/print-info` | `VerificheController::managePrintInfo` | — | 781 |
| POST | `/verifiche/scelte` | `VerificheController::saveLoadScelte` | — | 782 |
| ANY | `/verifiche/{path*}` | `(?)` | `legacy_gone` | 1229 |

## /version

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/version` | `HealthController::version` | — | 40 |

## /waf

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/waf/fingerprint` | `WafApiController::collect` | `rate:waf_fp 40` | 1482 |

