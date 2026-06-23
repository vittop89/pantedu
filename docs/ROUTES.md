# Route inventory

> Generato da `routes/web.php` con `php tools/dev/gen_routes_md.php > docs/ROUTES.md`. **Non editare a mano** — rigenera dopo ogni modifica alle route.
>
> **Cosa mostra**: verbo, path letterale, handler `Controller::method`, middleware *route-local*, riga in `routes/web.php`.
> **Cosa NON mostra**: il middleware ereditato dai `group()` (es. `auth`, `role:teacher`, `log`) — è sul wrapper del gruppo, non sulla singola route. Per il middleware effettivo di una route apri `routes/web.php` alla riga indicata e risali al `group()` che la contiene.
> **Eccezione prefix**: le route `/copilot/*` (handler `CopilotController`) sono dentro un `group(['prefix'=>'/api'])` → il path reale è `/api/copilot/*`.

Totale: **501** route in 70 gruppi (per prefix di path). Flusso: route → controller in `app/Controllers/` → service in `app/Services/` (vedi `docs/SERVICES.md`).

## Indice gruppi

- [`/`](#) — 1
- [`/Elementi_Riservati.html`](#elementiriservatihtml) — 1
- [`/accessibility`](#accessibility) — 1
- [`/admin`](#admin) — 90
- [`/analytics`](#analytics) — 1
- [`/api/access`](#apiaccess) — 3
- [`/api/admin`](#apiadmin) — 50
- [`/api/institutes`](#apiinstitutes) — 2
- [`/api/latex-shortcuts`](#apilatexshortcuts) — 4
- [`/api/maps`](#apimaps) — 6
- [`/api/probe`](#apiprobe) — 1
- [`/api/risdoc`](#apirisdoc) — 33
- [`/api/scuole`](#apiscuole) — 1
- [`/api/sidebar`](#apisidebar) — 1
- [`/api/sidepage`](#apisidepage) — 1
- [`/api/sources`](#apisources) — 1
- [`/api/studio`](#apistudio) — 3
- [`/api/study`](#apistudy) — 6
- [`/api/teacher`](#apiteacher) — 125
- [`/api/tenant`](#apitenant) — 2
- [`/api/tex`](#apitex) — 1
- [`/api/verifica`](#apiverifica) — 20
- [`/api/vitals`](#apivitals) — 1
- [`/area-docente`](#areadocente) — 9
- [`/auth`](#auth) — 11
- [`/check`](#check) — 2
- [`/cookies_privacy-policy.html`](#cookiesprivacypolicyhtml) — 1
- [`/copilot`](#copilot) — 1
- [`/copilot.php`](#copilotphp) — 1
- [`/copilot_proxy.php`](#copilotproxyphp) — 1
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
- [`/health`](#health) — 1
- [`/lab`](#lab) — 1
- [`/legal`](#legal) — 4
- [`/log`](#log) — 5
- [`/login`](#login) — 2
- [`/logout`](#logout) — 1
- [`/mappe`](#mappe) — 1
- [`/me`](#me) — 15
- [`/metrics`](#metrics) — 1
- [`/modelli_tikz.json`](#modellitikzjson) — 1
- [`/modelli_tikz_elements.json`](#modellitikzelementsjson) — 1
- [`/modelli_tikz_traccia.json`](#modellitikztracciajson) — 1
- [`/modello_pag_listSidebar.php`](#modellopaglistsidebarphp) — 1
- [`/parent-consent`](#parentconsent) — 2
- [`/privacy`](#privacy) — 2
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
| ANY | `/Elementi_Riservati.html` | `AdminPartialController::show` | — | 528 |

## /accessibility

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/accessibility` | `TrustPagesController::accessibility` | — | 238 |

## /admin

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/admin` | `AdminController::dashboard` | — | 1168 |
| GET | `/admin/access-log` | `AdminController::accessLog` | — | 1223 |
| GET | `/admin/access-stats` | `AdminController::accessStats` | — | 1224 |
| GET | `/admin/analytics` | `AdminAnalyticsController::page` | — | 1177 |
| GET | `/admin/backup` | `AdminBackupController::index` | — | 186 |
| POST | `/admin/backup/b2-verified` | `AdminBackupController::b2Verified` | `csrf` | 189 |
| POST | `/admin/backup/cold-completed` | `AdminBackupController::coldCompleted` | `csrf` | 187 |
| GET | `/admin/crypto-status` | `AdminCryptoStatusController::index` | — | 180 |
| POST | `/admin/crypto-status/event` | `AdminCryptoStatusController::recordEvent` | `csrf` | 182 |
| GET | `/admin/crypto-status/export` | `AdminCryptoStatusController::export` | — | 181 |
| GET | `/admin/curriculum` | `(closure)` | — | 537 |
| GET | `/admin/dashboard` | `AdminController::dashboard` | — | 1169 |
| GET | `/admin/data-breach` | `AdminGdprController::dataBreachIndex` | — | 163 |
| GET | `/admin/data-breach/new` | `AdminGdprController::dataBreachNewForm` | — | 164 |
| POST | `/admin/data-breach/new` | `AdminGdprController::dataBreachCreate` | `csrf` | 165 |
| GET | `/admin/data-breach/{id}` | `AdminGdprController::dataBreachShow` | — | 167 |
| POST | `/admin/data-breach/{id}/action` | `AdminGdprController::dataBreachAction` | `csrf` | 168 |
| GET | `/admin/data-requests` | `AdminGdprController::dataRequestsIndex` | — | 158 |
| GET | `/admin/data-requests/{id}` | `AdminGdprController::dataRequestsShow` | — | 159 |
| POST | `/admin/data-requests/{id}/action` | `AdminGdprController::dataRequestsAction` | `csrf` | 160 |
| GET | `/admin/debug-log` | `AdminController::debugLog` | — | 1225 |
| GET | `/admin/gdpr` | `(closure)` | — | 142 |
| GET | `/admin/gdpr/authority-export` | `AdminGdprController::authorityExportPage` | — | 147 |
| POST | `/admin/gdpr/authority-export` | `AdminGdprController::authorityExportSubmit` | `csrf` | 148 |
| POST | `/admin/generate-hash` | `AdminController::generateHash` | — | 1235 |
| GET | `/admin/infrastructure` | `AdminInfrastructureController::page` | — | 1189 |
| GET | `/admin/institutes` | `AdminInstitutesController::index` | — | 127 |
| POST | `/admin/institutes/miur/update` | `AdminInstitutesController::miurUpdate` | `csrf` | 132 |
| GET | `/admin/institutes/new` | `AdminInstitutesController::newForm` | — | 128 |
| POST | `/admin/institutes/new` | `AdminInstitutesController::create` | `csrf` | 129 |
| GET | `/admin/logs` | `AdminLogsController::page` | — | 155 |
| GET | `/admin/logs/api/{table}` | `AdminLogsController::apiQuery` | — | 156 |
| GET | `/admin/migrate` | `AdminMigrateController::page` | — | 1185 |
| POST | `/admin/migrate/run` | `AdminMigrateController::run` | — | 1140 |
| GET | `/admin/migrate/status` | `AdminMigrateController::status` | — | 1186 |
| GET | `/admin/monitoring` | `AdminMonitoringController::index` | — | 193 |
| POST | `/admin/print` | `AdminPrintController::generate` | — | 1137 |
| POST | `/admin/print/batch` | `AdminPrintController::batch` | — | 1138 |
| GET | `/admin/registrations` | `RegistrationController::listPending` | — | 1226 |
| POST | `/admin/registrations/{id}/approve` | `RegistrationController::approve` | — | 1228 |
| POST | `/admin/registrations/{id}/reject` | `RegistrationController::reject` | — | 1229 |
| GET | `/admin/risdoc` | `RisdocAdminController::page` | — | 1299 |
| GET | `/admin/risdoc/pending/{id}/preview` | `RisdocAdminController::pendingPreviewPage` | — | 1310 |
| GET | `/admin/sidebar-config` | `AdminSidebarConfigController::page` | — | 117 |
| POST | `/admin/sidebar-config/delete` | `AdminSidebarConfigController::delete` | `csrf` | 120 |
| POST | `/admin/sidebar-config/reorder` | `AdminSidebarConfigController::reorder` | `csrf` | 122 |
| POST | `/admin/sidebar-config/save` | `AdminSidebarConfigController::save` | `csrf` | 118 |
| GET | `/admin/subprocessors` | `AdminGdprController::subprocessorsIndex` | — | 171 |
| GET | `/admin/subprocessors/new` | `AdminGdprController::subprocessorsNewForm` | — | 172 |
| POST | `/admin/subprocessors/save` | `AdminGdprController::subprocessorsSave` | `csrf` | 174 |
| POST | `/admin/subprocessors/{id}/delete` | `AdminGdprController::subprocessorsDelete` | `csrf` | 176 |
| GET | `/admin/subprocessors/{id}/edit` | `AdminGdprController::subprocessorsEditForm` | — | 173 |
| POST | `/admin/system/capability/assign` | `AdminSystemController::capabilityAssign` | `csrf` | 210 |
| POST | `/admin/system/capability/profile/delete` | `AdminSystemController::capabilityProfileDelete` | `csrf` | 208 |
| POST | `/admin/system/capability/profile/save` | `AdminSystemController::capabilityProfileSave` | `csrf` | 206 |
| GET | `/admin/system/deployment` | `AdminSystemController::deploymentPage` | — | 197 |
| POST | `/admin/system/deployment/switch` | `AdminSystemController::deploymentSwitch` | `csrf` | 198 |
| POST | `/admin/system/registration-classes/add` | `AdminSystemController::registrationClassAdd` | `csrf` | 201 |
| POST | `/admin/system/registration-classes/remove` | `AdminSystemController::registrationClassRemove` | `csrf` | 203 |
| GET | `/admin/takedown` | `AdminTakedownController::index` | — | 107 |
| GET | `/admin/takedown/{id}` | `AdminTakedownController::show` | — | 108 |
| POST | `/admin/takedown/{id}/action` | `AdminTakedownController::action` | `csrf` | 109 |
| GET | `/admin/templates` | `TemplatesAdminController::page` | — | 1295 |
| GET | `/admin/tools` | `AdminToolsController::page` | — | 1171 |
| GET | `/admin/tools/hash` | `AdminController::hashToolPage` | — | 1170 |
| GET | `/admin/tos-log` | `AdminTosLogController::index` | — | 113 |
| GET | `/admin/waf` | `WafAdminController::index` | — | 1378 |
| GET | `/admin/waf/anomalies` | `WafAdminController::anomaliesPage` | — | 1387 |
| POST | `/admin/waf/api/blacklist` | `WafAdminController::apiAddBlacklist` | — | 1402 |
| DELETE | `/admin/waf/api/blacklist/{id}` | `WafAdminController::apiDeleteBlacklist` | — | 1403 |
| POST | `/admin/waf/api/config` | `WafAdminController::apiUpdateConfig` | — | 1396 |
| GET | `/admin/waf/api/counters` | `WafAdminController::apiCounters` | — | 1392 |
| GET | `/admin/waf/api/logs` | `WafAdminController::apiLogs` | — | 1391 |
| POST | `/admin/waf/api/rules` | `WafAdminController::apiCreateRule` | — | 1398 |
| DELETE | `/admin/waf/api/rules/{id}` | `WafAdminController::apiDeleteRule` | — | 1400 |
| PUT | `/admin/waf/api/rules/{id}` | `WafAdminController::apiUpdateRule` | — | 1399 |
| POST | `/admin/waf/api/rules/{id}/toggle` | `WafAdminController::apiToggleRule` | — | 1401 |
| POST | `/admin/waf/api/threat-intel/sync` | `WafAdminController::apiThreatIntelSync` | — | 1397 |
| POST | `/admin/waf/api/whitelist` | `WafAdminController::apiAddWhitelist` | — | 1404 |
| DELETE | `/admin/waf/api/whitelist/{id}` | `WafAdminController::apiDeleteWhitelist` | — | 1405 |
| GET | `/admin/waf/blocks` | `WafAdminController::blocksPage` | — | 1383 |
| GET | `/admin/waf/config` | `WafAdminController::configPage` | — | 1380 |
| GET | `/admin/waf/credentials` | `WafAdminController::credentialsPage` | — | 1386 |
| GET | `/admin/waf/dashboard` | `WafAdminController::dashboard` | — | 1379 |
| GET | `/admin/waf/diag` | `WafAdminController::diagPage` | — | 1390 |
| GET | `/admin/waf/lists` | `WafAdminController::listsPage` | — | 1385 |
| GET | `/admin/waf/reports` | `WafAdminController::reportsPage` | — | 1388 |
| GET | `/admin/waf/rules` | `WafAdminController::rulesPage` | — | 1381 |
| GET | `/admin/waf/threat-intel` | `WafAdminController::threatIntelPage` | — | 1389 |
| GET | `/admin/whoami` | `AdminController::whoAmI` | — | 1232 |

## /analytics

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/analytics/nav` | `AnalyticsController::navBeacon` | — | 251 |

## /api/access

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/access/status` | `TeacherCredentialController::studentStatus` | — | 288 |
| POST | `/api/access/student-login` | `TeacherCredentialController::studentLogin` | `csrf rate` | 284 |
| POST | `/api/access/student-logout` | `TeacherCredentialController::studentLogout` | `csrf` | 286 |

## /api/admin

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/admin/analytics` | `AdminAnalyticsController::snapshot` | — | 1178 |
| GET | `/api/admin/analytics/cross-search` | `AdminAnalyticsController::crossSearch` | — | 1180 |
| GET | `/api/admin/analytics/teacher/{id}` | `AdminAnalyticsController::forTeacher` | — | 1179 |
| GET | `/api/admin/badge-style-presets` | `BadgeStyleController::adminList` | — | 474 |
| DELETE | `/api/admin/badge-style-presets/{name}` | `BadgeStyleController::adminDelete` | — | 480 |
| GET | `/api/admin/badge-style-presets/{name}` | `BadgeStyleController::adminGet` | — | 476 |
| PUT | `/api/admin/badge-style-presets/{name}` | `BadgeStyleController::adminPut` | — | 478 |
| GET | `/api/admin/gdpr/teacher-content-search` | `AdminGdprController::teacherContentSearch` | — | 151 |
| GET | `/api/admin/infrastructure.json` | `AdminInfrastructureController::snapshotJson` | — | 1191 |
| GET | `/api/admin/latex-shortcuts` | `LatexShortcutsController::adminList` | — | 371 |
| POST | `/api/admin/latex-shortcuts` | `LatexShortcutsController::adminSave` | — | 376 |
| GET | `/api/admin/notifications` | `AdminController::notifications` | — | 1233 |
| GET | `/api/admin/risdoc/drift` | `RisdocAdminController::driftList` | — | 1303 |
| GET | `/api/admin/risdoc/pending` | `RisdocAdminController::pendingList` | — | 1305 |
| POST | `/api/admin/risdoc/pending/{id}/approve` | `RisdocAdminController::pendingApprove` | — | 1320 |
| GET | `/api/admin/risdoc/pending/{id}/content` | `RisdocAdminController::pendingContent` | — | 1306 |
| POST | `/api/admin/risdoc/pending/{id}/reject` | `RisdocAdminController::pendingReject` | — | 1322 |
| GET | `/api/admin/risdoc/pending/{id}/schema` | `RisdocAdminController::pendingSchema` | — | 1308 |
| GET | `/api/admin/risdoc/teachers` | `RisdocAdminController::teachersList` | — | 1302 |
| GET | `/api/admin/risdoc/templates` | `RisdocAdminController::templatesList` | — | 1300 |
| POST | `/api/admin/risdoc/templates/create` | `RisdocAdminController::createTemplate` | — | 1333 |
| POST | `/api/admin/risdoc/templates/rename-group` | `RisdocAdminController::renameGroup` | — | 1330 |
| GET | `/api/admin/risdoc/templates/{id}` | `RisdocAdminController::templateDetail` | — | 1301 |
| POST | `/api/admin/risdoc/templates/{id}/collaborators` | `RisdocAdminController::collaboratorsEdit` | — | 1317 |
| POST | `/api/admin/risdoc/templates/{id}/meta` | `RisdocAdminController::updateMeta` | — | 1328 |
| POST | `/api/admin/risdoc/templates/{id}/visibility` | `RisdocAdminController::visibilityBulk` | — | 1314 |
| POST | `/api/admin/risdoc/templates/{id}/visibility-scope` | `RisdocAdminController::setVisibilityScope` | — | 1325 |
| GET | `/api/admin/security/anomalies` | `SecurityAdminController::anomalies` | — | 1200 |
| GET | `/api/admin/security/blocked-credentials` | `SecurityAdminController::listBlockedCredentials` | — | 1198 |
| GET | `/api/admin/security/blocked-ips` | `SecurityAdminController::listBlockedIps` | — | 1199 |
| GET | `/api/admin/security/config` | `SecurityAdminController::getConfig` | — | 1202 |
| POST | `/api/admin/security/config` | `SecurityAdminController::setConfig` | — | 1214 |
| POST | `/api/admin/security/credentials/block` | `SecurityAdminController::blockCredential` | — | 1210 |
| POST | `/api/admin/security/credentials/unblock` | `SecurityAdminController::unblockCredential` | — | 1211 |
| POST | `/api/admin/security/ips/block` | `SecurityAdminController::blockIp` | — | 1212 |
| POST | `/api/admin/security/ips/unblock` | `SecurityAdminController::unblockIp` | — | 1213 |
| GET | `/api/admin/security/live-blocks` | `SecurityAdminController::liveBlocks` | — | 1201 |
| GET | `/api/admin/users` | `UsersAdminController::index` | — | 1197 |
| POST | `/api/admin/users/{id}/active` | `UsersAdminController::setActive` | — | 1207 |
| POST | `/api/admin/users/{id}/delete` | `UsersAdminController::delete` | — | 1209 |
| POST | `/api/admin/users/{id}/role` | `UsersAdminController::setRole` | — | 1208 |
| GET | `/api/admin/verifica/files` | `VerificaFilesAdminController::listFiles` | — | 1359 |
| POST | `/api/admin/verifica/files/copy-from-default` | `VerificaFilesAdminController::copyFromDefault` | — | 1350 |
| POST | `/api/admin/verifica/files/delete` | `VerificaFilesAdminController::deleteFile` | — | 1348 |
| GET | `/api/admin/verifica/files/read` | `VerificaFilesAdminController::readFile` | — | 1362 |
| POST | `/api/admin/verifica/files/write` | `VerificaFilesAdminController::writeFile` | — | 1346 |
| GET | `/api/admin/verifica/preamble` | `VerificaPreambleAdminController::get` | — | 1354 |
| POST | `/api/admin/verifica/preamble` | `VerificaPreambleAdminController::save` | — | 1339 |
| POST | `/api/admin/verifica/preamble/reset` | `VerificaPreambleAdminController::reset` | — | 1341 |
| GET | `/api/admin/verifica/scopes` | `VerificaFilesAdminController::listScopes` | — | 1357 |

## /api/institutes

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/institutes` | `InstituteController::index` | — | 266 |
| POST | `/api/institutes` | `InstituteController::create` | — | 1206 |

## /api/latex-shortcuts

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/latex-shortcuts/effective` | `LatexShortcutsController::effective` | — | 370 |
| POST | `/api/latex-shortcuts/reset` | `LatexShortcutsController::reset` | — | 374 |
| POST | `/api/latex-shortcuts/reset-all` | `LatexShortcutsController::resetAll` | — | 375 |
| POST | `/api/latex-shortcuts/save` | `LatexShortcutsController::save` | — | 373 |

## /api/maps

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/api/maps` | `MapsController::create` | `rate:content 60` | 798 |
| GET | `/api/maps/dl` | `MapsController::download` | — | 38 |
| POST | `/api/maps/sync-all` | `MapsController::syncAll` | `rate:content 30` | 810 |
| GET | `/api/maps/{id}/signed-url` | `MapsController::signedUrl` | — | 820 |
| POST | `/api/maps/{id}/sync` | `MapsController::sync` | `rate:content 60` | 808 |
| POST | `/api/maps/{id}/update` | `MapsController::update` | `rate:content 60` | 804 |

## /api/probe

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/api/probe` | `CsrfProbeController::probe` | — | 518 |

## /api/risdoc

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/risdoc/compilations/{id}` | `CompilationController::show` | — | 1090 |
| POST | `/api/risdoc/compilations/{id}/delete` | `CompilationController::delete` | — | 1038 |
| GET | `/api/risdoc/curriculum-options` | `CurriculumOptionsController::options` | — | 1061 |
| POST | `/api/risdoc/curriculum-options` | `CurriculumOptionsController::save` | — | 1018 |
| POST | `/api/risdoc/curriculum-options/delete` | `CurriculumOptionsController::delete` | — | 1020 |
| GET | `/api/risdoc/exports/{file}` | `ExportController::serve` | — | 1080 |
| GET | `/api/risdoc/options-sources` | `TemplateController::optionsSources` | — | 1083 |
| GET | `/api/risdoc/shared/{file}` | `TemplateController::sharedAsset` | — | 1085 |
| GET | `/api/risdoc/teacher/instances` | `TemplateController::teacherAllInstances` | — | 1074 |
| GET | `/api/risdoc/templates` | `TemplateController::index` | — | 1051 |
| GET | `/api/risdoc/templates/{id}` | `TemplateController::show` | — | 1053 |
| POST | `/api/risdoc/templates/{id}/body-pt` | `TemplateController::saveBodyPt` | — | 1009 |
| GET | `/api/risdoc/templates/{id}/compilations` | `CompilationController::index` | — | 1088 |
| POST | `/api/risdoc/templates/{id}/compilations` | `CompilationController::save` | — | 1036 |
| POST | `/api/risdoc/templates/{id}/compile-pdf` | `TexFilesController::compilePdf` | — | 999 |
| GET | `/api/risdoc/templates/{id}/drift` | `TemplateController::driftStatus` | — | 1078 |
| POST | `/api/risdoc/templates/{id}/export` | `ExportController::export` | — | 991 |
| GET | `/api/risdoc/templates/{id}/file` | `TemplateController::file` | — | 1055 |
| GET | `/api/risdoc/templates/{id}/instances` | `TemplateController::instancesList` | — | 1071 |
| POST | `/api/risdoc/templates/{id}/instances` | `TemplateController::instancesCreate` | `rate:instances 60` | 1025 |
| POST | `/api/risdoc/templates/{id}/instances/{key}/delete` | `TemplateController::instancesDelete` | `rate:instances 60` | 1028 |
| POST | `/api/risdoc/templates/{id}/instances/{key}/rename` | `TemplateController::instancesRename` | `rate:instances 60` | 1031 |
| POST | `/api/risdoc/templates/{id}/institutional-override` | `TemplateController::institutionalOverrideSave` | — | 1012 |
| POST | `/api/risdoc/templates/{id}/institutional-override/del` | `TemplateController::institutionalOverrideDelete` | — | 1014 |
| GET | `/api/risdoc/templates/{id}/institutional-overrides` | `TemplateController::institutionalOverridesList` | — | 1068 |
| GET | `/api/risdoc/templates/{id}/json-files` | `TemplateController::jsonFiles` | — | 1076 |
| POST | `/api/risdoc/templates/{id}/override` | `TemplateController::overrideSave` | — | 987 |
| POST | `/api/risdoc/templates/{id}/override/del` | `TemplateController::overrideDelete` | — | 989 |
| GET | `/api/risdoc/templates/{id}/overrides` | `TemplateController::overridesList` | — | 1065 |
| GET | `/api/risdoc/templates/{id}/schema` | `TemplateController::schema` | — | 1057 |
| GET | `/api/risdoc/templates/{id}/tex` | `TemplateController::tex` | — | 1063 |
| POST | `/api/risdoc/templates/{id}/tex-files` | `TexFilesController::getFiles` | — | 995 |
| POST | `/api/risdoc/templates/{id}/tex-files/save` | `TexFilesController::saveFiles` | — | 997 |

## /api/scuole

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/scuole` | `SchoolsController::search` | — | 269 |

## /api/sidebar

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/sidebar/config` | `SidebarConfigController::config` | — | 431 |

## /api/sidepage

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/sidepage/topics` | `SidepageController::topics` | — | 278 |

## /api/sources

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/sources/common` | `StudySourcesController::sourcesCommonJson` | — | 511 |

## /api/studio

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/studio/exercise/{id}.json` | `ExerciseStudyController::exerciseJson` | — | 427 |
| GET | `/api/studio/exercises.json` | `ExerciseStudyController::exercisesJson` | — | 425 |
| GET | `/api/studio/topics.json` | `ExerciseStudyController::topicsJson` | — | 423 |

## /api/study

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/study/content.json` | `ContentStudyController::contentJson` | — | 436 |
| GET | `/api/study/content/{id}.json` | `ContentStudyController::contentSingleJson` | — | 438 |
| GET | `/api/study/header-page.json` | `StudyHeaderController::headerPageStudentJson` | — | 449 |
| GET | `/api/study/related-verifiche.html` | `ContentStudyController::relatedVerificaHtml` | — | 454 |
| GET | `/api/study/topics.json` | `ContentStudyController::topicsJson` | — | 434 |
| GET | `/api/study/verifica/list` | `VerificaController::listForStudent` | — | 443 |

## /api/teacher

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/teacher/badge-style` | `BadgeStyleController::teacherGet` | — | 467 |
| PUT | `/api/teacher/badge-style` | `BadgeStyleController::teacherPut` | — | 469 |
| GET | `/api/teacher/capabilities` | `TeacherContentController::capabilities` | — | 876 |
| GET | `/api/teacher/category-labels` | `TeacherCategoryLabelController::list` | — | 901 |
| POST | `/api/teacher/category-labels` | `TeacherCategoryLabelController::save` | — | 904 |
| GET | `/api/teacher/checked-origins.json` | `StudySourcesController::checkedOriginsJson` | — | 498 |
| PUT | `/api/teacher/checked-origins.json` | `StudySourcesController::checkedOriginsSave` | — | 500 |
| GET | `/api/teacher/content` | `TeacherContentController::index` | — | 893 |
| POST | `/api/teacher/content` | `TeacherContentController::store` | `rate:content 60` | 921 |
| GET | `/api/teacher/content/{id}` | `TeacherContentController::show` | — | 895 |
| POST | `/api/teacher/content/{id}/compile-pdf` | `ContentExportController::compilePdf` | `rate:compile 15` | 950 |
| GET | `/api/teacher/content/{id}/contract` | `ContentExportController::contract` | — | 882 |
| POST | `/api/teacher/content/{id}/delete` | `TeacherContentController::destroy` | `rate:content 60` | 927 |
| POST | `/api/teacher/content/{id}/export` | `ContentExportController::export` | — | 941 |
| GET | `/api/teacher/content/{id}/export-html` | `ContentExportController::exportHtml` | — | 898 |
| POST | `/api/teacher/content/{id}/group/add` | `GroupController::groupAdd` | — | 977 |
| POST | `/api/teacher/content/{id}/group/{groupRef}/delete` | `GroupController::groupDelete` | — | 983 |
| POST | `/api/teacher/content/{id}/group/{groupRef}/move` | `GroupController::groupMove` | — | 974 |
| POST | `/api/teacher/content/{id}/group/{groupRef}/patch` | `GroupController::groupPatch` | — | 980 |
| GET | `/api/teacher/content/{id}/provenance` | `ContentExportController::provenance` | — | 956 |
| POST | `/api/teacher/content/{id}/publish` | `ContentPublishController::publish` | — | 936 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/clone-to-eser` | `QuesitoController::quesitoCloneToEser` | — | 971 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/delete` | `QuesitoController::quesitoDelete` | — | 964 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/duplicate` | `QuesitoController::quesitoDuplicate` | — | 968 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/move` | `QuesitoController::quesitoMove` | — | 966 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/patch` | `QuesitoController::quesitoPatch` | — | 962 |
| POST | `/api/teacher/content/{id}/recategorize` | `TeacherContentController::recategorize` | `rate:content 60` | 933 |
| POST | `/api/teacher/content/{id}/share-pool` | `ContentPublishController::sharePool` | — | 954 |
| POST | `/api/teacher/content/{id}/tex-files` | `ContentExportController::texFiles` | — | 944 |
| POST | `/api/teacher/content/{id}/tex-files/save` | `ContentExportController::saveTexFiles` | — | 946 |
| POST | `/api/teacher/content/{id}/unpublish` | `ContentPublishController::unpublish` | — | 938 |
| POST | `/api/teacher/content/{id}/update` | `TeacherContentController::update` | `rate:content 60` | 924 |
| GET | `/api/teacher/credentials` | `TeacherCredentialController::index` | — | 837 |
| POST | `/api/teacher/credentials` | `TeacherCredentialController::create` | — | 844 |
| POST | `/api/teacher/credentials/{id}/delete` | `TeacherCredentialController::delete` | — | 846 |
| POST | `/api/teacher/credentials/{id}/toggle` | `TeacherCredentialController::toggle` | — | 848 |
| GET | `/api/teacher/curriculum` | `CurriculumController::index` | — | 869 |
| GET | `/api/teacher/curriculum/pivot` | `TeacherCurriculumPivotController::listMine` | — | 871 |
| POST | `/api/teacher/curriculum/pivot/toggle` | `TeacherCurriculumPivotController::toggle` | — | 918 |
| POST | `/api/teacher/curriculum/{id}/remove` | `CurriculumController::remove` | — | 915 |
| POST | `/api/teacher/curriculum/{id}/update` | `CurriculumController::update` | — | 913 |
| POST | `/api/teacher/curriculum/{kind}` | `CurriculumController::add` | — | 911 |
| GET | `/api/teacher/drawio/libraries` | `TeacherDrawioLibraryController::list` | — | 741 |
| POST | `/api/teacher/drawio/libraries/delete` | `TeacherDrawioLibraryController::delete` | — | 786 |
| GET | `/api/teacher/drawio/libraries/read/{name}` | `TeacherDrawioLibraryController::read` | — | 743 |
| POST | `/api/teacher/drawio/libraries/save-content` | `TeacherDrawioLibraryController::saveContent` | — | 790 |
| POST | `/api/teacher/drawio/libraries/upload` | `TeacherDrawioLibraryController::upload` | — | 784 |
| POST | `/api/teacher/github/configure` | `TeacherGitHubController::configure` | — | 775 |
| POST | `/api/teacher/github/disconnect` | `TeacherGitHubController::disconnect` | — | 776 |
| POST | `/api/teacher/github/push-file` | `TeacherGitHubController::pushFile` | — | 779 |
| GET | `/api/teacher/github/status` | `TeacherGitHubController::status` | — | 739 |
| POST | `/api/teacher/github/sync-all` | `TeacherGitHubController::syncAll` | — | 778 |
| POST | `/api/teacher/github/sync-test` | `TeacherGitHubController::syncTest` | — | 777 |
| GET | `/api/teacher/header-page.json` | `StudyHeaderController::headerPageJson` | — | 505 |
| PUT | `/api/teacher/header-page.json` | `StudyHeaderController::headerPageSave` | — | 507 |
| POST | `/api/teacher/import-bundle/apply` | `ImportBundleController::apply` | `rate:import 4` | 755 |
| POST | `/api/teacher/import-bundle/preview` | `ImportBundleController::preview` | `rate:import 4` | 752 |
| GET | `/api/teacher/institutes` | `InstituteController::listForTeacher` | — | 835 |
| POST | `/api/teacher/institutes/link` | `InstituteController::link` | — | 840 |
| POST | `/api/teacher/institutes/{id}/unlink` | `InstituteController::unlink` | — | 842 |
| GET | `/api/teacher/manifest/{type}` | `ContentExportController::manifest` | — | 879 |
| GET | `/api/teacher/my-classes` | `TeacherContentController::myClasses` | — | 890 |
| GET | `/api/teacher/origins.json` | `StudySourcesController::originsJson` | — | 494 |
| POST | `/api/teacher/pdf-import/provider-cache` | `PdfImportController::toggleCache` | `rate:pdf_import 30` | 653 |
| GET | `/api/teacher/pdf-import/provider-keys` | `PdfImportController::providerKeysStatus` | — | 570 |
| POST | `/api/teacher/pdf-import/provider-keys` | `PdfImportController::saveProviderKey` | `rate:pdf_import 30` | 644 |
| POST | `/api/teacher/pdf-import/provider-keys/clear` | `PdfImportController::clearProviderKey` | `rate:pdf_import 30` | 659 |
| GET | `/api/teacher/pdf-import/provider-models` | `PdfImportController::providerModels` | — | 572 |
| GET | `/api/teacher/pdf-import/provider-operations` | `PdfImportController::providerOperations` | — | 574 |
| POST | `/api/teacher/pdf-import/provider-operations` | `PdfImportController::saveProviderOperation` | `rate:pdf_import 30` | 647 |
| POST | `/api/teacher/pdf-import/provider-prompt` | `PdfImportController::saveProviderPrompt` | `rate:pdf_import 30` | 650 |
| POST | `/api/teacher/pdf-import/session` | `PdfImportController::createSession` | `rate:pdf_import_llm 12` | 617 |
| GET | `/api/teacher/pdf-import/session/{id}` | `PdfImportController::status` | — | 563 |
| POST | `/api/teacher/pdf-import/session/{id}/bulk` | `PdfImportController::bulkEdit` | `rate:pdf_import 30` | 623 |
| POST | `/api/teacher/pdf-import/session/{id}/cell` | `PdfImportController::editCell` | `rate:pdf_import 30` | 620 |
| POST | `/api/teacher/pdf-import/session/{id}/difficulty` | `PdfImportController::refineDifficulty` | `rate:pdf_import_llm 12` | 632 |
| POST | `/api/teacher/pdf-import/session/{id}/insert` | `PdfImportController::insert` | `rate:pdf_import 30` | 641 |
| GET | `/api/teacher/pdf-import/session/{id}/page/{n}` | `PdfImportController::pageImage` | — | 565 |
| GET | `/api/teacher/pdf-import/session/{id}/preview` | `PdfImportController::previewRow` | — | 567 |
| POST | `/api/teacher/pdf-import/session/{id}/solutions` | `PdfImportController::generateSolutions` | `rate:pdf_import_llm 12` | 626 |
| POST | `/api/teacher/pdf-import/session/{id}/stop` | `PdfImportController::stopSession` | `rate:pdf_import 30` | 635 |
| POST | `/api/teacher/pdf-import/session/{id}/topics` | `PdfImportController::generateTopics` | `rate:pdf_import_llm 12` | 629 |
| POST | `/api/teacher/pdf-import/session/{id}/translate` | `PdfImportController::translate` | `rate:pdf_import_llm 30` | 638 |
| GET | `/api/teacher/pdf-import/sessions` | `PdfImportController::listSessions` | — | 561 |
| POST | `/api/teacher/pdf-import/setting` | `PdfImportController::toggleSetting` | `rate:pdf_import 30` | 656 |
| GET | `/api/teacher/pool/materials` | `PoolController::materials` | — | 724 |
| GET | `/api/teacher/pool/my-shares` | `PoolController::myShares` | — | 727 |
| POST | `/api/teacher/pool/recover/{id}` | `PoolController::recover` | `rate:pool_recover 30` | 760 |
| POST | `/api/teacher/pool/unshare` | `PoolController::unshare` | — | 764 |
| GET | `/api/teacher/print-info` | `PrintInfoController::show` | — | 707 |
| POST | `/api/teacher/print-info` | `PrintInfoController::save` | — | 679 |
| POST | `/api/teacher/print-info/delete` | `PrintInfoController::delete` | — | 680 |
| GET | `/api/teacher/print-info/list` | `PrintInfoController::index` | — | 708 |
| POST | `/api/teacher/recovery-key/generate` | `TeacherRecoveryController::generate` | — | 747 |
| POST | `/api/teacher/recovery-key/revoke` | `TeacherRecoveryController::revoke` | — | 749 |
| GET | `/api/teacher/recovery-key/status` | `TeacherRecoveryController::status` | — | 701 |
| GET | `/api/teacher/risdoc/templates/files` | `TeacherTexCommonController::getFiles` | — | 1112 |
| POST | `/api/teacher/risdoc/templates/files/preview-pdf` | `TeacherTexCommonController::previewPdf` | — | 1005 |
| POST | `/api/teacher/risdoc/templates/files/save` | `TeacherTexCommonController::saveFiles` | — | 1002 |
| GET | `/api/teacher/share/colleagues` | `ShareGrantsController::listColleagues` | — | 734 |
| GET | `/api/teacher/share/grants/{source}/{id}` | `ShareGrantsController::listGrants` | — | 730 |
| POST | `/api/teacher/share/grants/{source}/{id}` | `ShareGrantsController::setGrants` | — | 767 |
| GET | `/api/teacher/share/groups` | `ShareGrantsController::listGroups` | — | 732 |
| POST | `/api/teacher/share/groups` | `ShareGrantsController::createGroup` | — | 769 |
| POST | `/api/teacher/share/groups/{id}/delete` | `ShareGrantsController::deleteGroup` | — | 773 |
| GET | `/api/teacher/share/groups/{id}/members` | `ShareGrantsController::listMembers` | — | 736 |
| POST | `/api/teacher/share/groups/{id}/members` | `ShareGrantsController::setMembers` | — | 771 |
| GET | `/api/teacher/sources.json` | `StudySourcesController::sourcesCommonJson` | — | 488 |
| PUT | `/api/teacher/sources.json` | `StudySourcesController::sourcesSave` | — | 490 |
| GET | `/api/teacher/sources.registry.json` | `StudySourcesController::sourcesRegistryJson` | — | 460 |
| PUT | `/api/teacher/sources.registry.json` | `StudySourcesController::sourcesRegistrySave` | — | 462 |
| GET | `/api/teacher/subjects` | `TeacherSubjectController::listMine` | — | 865 |
| POST | `/api/teacher/subjects` | `TeacherSubjectController::create` | — | 906 |
| POST | `/api/teacher/subjects/{id}/delete` | `TeacherSubjectController::unlink` | — | 908 |
| GET | `/api/teacher/sync-bundle/manifest` | `VerificaSyncController::manifestSigned` | — | 699 |
| GET | `/api/teacher/sync-local-bundle` | `VerificaSyncController::localBundle` | — | 697 |
| POST | `/api/teacher/sync/cleanup-orphans` | `TeacherSyncCleanupController::cleanupOrphans` | — | 781 |
| GET | `/api/teacher/templates.json` | `ContentTemplateController::templatesJson` | — | 549 |
| PUT | `/api/teacher/templates.json` | `ContentTemplateController::templatesSave` | — | 580 |
| GET | `/api/teacher/verifica/files` | `TeacherVerificaFilesController::listFiles` | — | 1111 |
| POST | `/api/teacher/verifica/files/copy-from-base` | `TeacherVerificaFilesController::copyFromBase` | — | 855 |
| POST | `/api/teacher/verifica/files/delete` | `TeacherVerificaFilesController::deleteFile` | — | 853 |
| POST | `/api/teacher/verifica/files/preview-pdf` | `TeacherVerificaFilesController::previewPdf` | — | 859 |
| GET | `/api/teacher/verifica/files/read` | `TeacherVerificaFilesController::readFile` | — | 1114 |
| POST | `/api/teacher/verifica/files/write` | `TeacherVerificaFilesController::writeFile` | — | 851 |

## /api/tenant

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/tenant/current` | `TenantController::current` | — | 246 |
| POST | `/api/tenant/switch` | `TenantController::switch` | `csrf` | 244 |

## /api/tex

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/api/tex/compile-adhoc-pdf` | `TexAdhocCompileController::compileTikzPdf` | — | 613 |

## /api/verifica

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/verifica/batch/{batchId}/files` | `VerificaBatchController::batchFiles` | — | 695 |
| GET | `/api/verifica/batch/{batchId}/zip` | `VerificaBatchController::batchZip` | — | 692 |
| GET | `/api/verifica/jobs/{jobId}` | `VerificaCompileController::getJob` | — | 597 |
| GET | `/api/verifica/list` | `VerificaController::listForTeacher` | — | 687 |
| POST | `/api/verifica/save-tex` | `VerificaController::saveTex` | — | 587 |
| POST | `/api/verifica/save-tex-batch` | `VerificaController::saveTexBatch` | — | 588 |
| POST | `/api/verifica/sync-all` | `VerificaSyncController::syncAll` | `rate:content 30` | 813 |
| POST | `/api/verifica/{id}/compile` | `VerificaCompileController::compilePdf` | — | 594 |
| POST | `/api/verifica/{id}/compile-async` | `VerificaCompileController::compileAsync` | — | 596 |
| POST | `/api/verifica/{id}/delete` | `VerificaController::delete` | — | 608 |
| POST | `/api/verifica/{id}/geogebra-attach` | `VerificaController::geogebraAttach` | — | 601 |
| GET | `/api/verifica/{id}/pdf` | `VerificaController::viewPdf` | — | 689 |
| POST | `/api/verifica/{id}/pdf` | `VerificaController::uploadPdf` | — | 589 |
| POST | `/api/verifica/{id}/share-pool` | `VerificaController::sharePool` | — | 610 |
| POST | `/api/verifica/{id}/synctex/edit` | `VerificaCompileController::synctexEdit` | — | 607 |
| GET | `/api/verifica/{id}/tex` | `VerificaController::downloadTex` | — | 688 |
| POST | `/api/verifica/{id}/tex` | `VerificaController::updateTex` | — | 599 |
| GET | `/api/verifica/{id}/tex-files` | `VerificaController::getTexFiles` | — | 603 |
| POST | `/api/verifica/{id}/tex-files` | `VerificaController::updateTexFiles` | — | 604 |
| GET | `/api/verifica/{id}/zip` | `VerificaController::zipExport` | — | 690 |

## /api/vitals

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/api/vitals` | `AnalyticsController::webVitals` | `rate:vitals 120` | 253 |

## /area-docente

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/area-docente` | `(closure)` | — | 1102 |
| GET | `/area-docente/categorie` | `TeacherProfileController::categoriePage` | — | 1109 |
| GET | `/area-docente/dashboard` | `TeacherController::dashboard` | — | 1103 |
| GET | `/area-docente/fonti` | `TeacherProfileController::fontiPage` | — | 1110 |
| GET | `/area-docente/pdf-import` | `PdfImportPageController::page` | — | 1105 |
| GET | `/area-docente/pdf-import/models` | `PdfImportPageController::modelsPage` | — | 1106 |
| GET | `/area-docente/profilo` | `TeacherProfileController::page` | — | 1107 |
| GET | `/area-docente/resources` | `TeacherController::resources` | — | 1104 |
| GET | `/area-docente/templates` | `TeacherProfileController::templatesPage` | — | 1108 |

## /auth

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/auth/cie/callback` | `CieController::callback` | — | 57 |
| GET | `/auth/cie/login` | `CieController::login` | — | 56 |
| GET | `/auth/cie/logout` | `CieController::logout` | — | 59 |
| GET | `/auth/cie/metadata` | `CieController::metadata` | — | 58 |
| GET | `/auth/csrf` | `AuthController::csrf` | — | 47 |
| GET | `/auth/grafana-gate` | `GrafanaGateController::gate` | — | 218 |
| GET | `/auth/spid/callback` | `SpidController::callback` | — | 53 |
| GET | `/auth/spid/login` | `SpidController::login` | — | 52 |
| GET | `/auth/spid/logout` | `SpidController::logout` | — | 55 |
| GET | `/auth/spid/metadata` | `SpidController::metadata` | — | 54 |
| GET | `/auth/user-info` | `AuthController::userInfo` | — | 46 |

## /check

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/check/file-protection` | `CheckController::fileProtection` | — | 1275 |
| ANY | `/check/password` | `CheckController::password` | — | 1274 |

## /cookies_privacy-policy.html

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/cookies_privacy-policy.html` | `(closure)` | — | 293 |

## /copilot

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/copilot/chat` | `CopilotController::chat` | — | 1422 |

## /copilot.php

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/copilot.php` | `CopilotController::chat` | — | 1423 |

## /copilot_proxy.php

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/copilot_proxy.php` | `CopilotController::chat` | — | 1424 |

## /curriculum

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/curriculum` | `CurriculumController::index` | — | 263 |

## /delete_temp.php

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/delete_temp.php` | `CronController::deleteTemp` | — | 1280 |

## /didattica

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/didattica/{path*}` | `(?)` | `legacy_gone` | 329 |

## /dpo-contact

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/dpo-contact` | `DpoContactController::show` | — | 95 |
| POST | `/dpo-contact` | `DpoContactController::submit` | `rate:dpo 3` | 96 |

## /drafts

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/drafts/{path*}` | `(?)` | `legacy_gone` | 1122 |

## /eser

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/eser/{path*}` | `(?)` | `legacy_gone` | 328 |

## /exercises

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/exercises` | `ExerciseController::searchPage` | — | 711 |
| GET | `/exercises/search.json` | `ExerciseController::searchJson` | — | 712 |

## /favicon.ico

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/favicon.ico` | `(closure)` | — | 18 |

## /files

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/files/clear-temp` | `FileController::clearTemp` | — | 1150 |
| POST | `/files/delete` | `FileController::deleteFile` | — | 1147 |
| POST | `/files/delete-folder` | `FileController::deleteFolder` | — | 1148 |
| GET | `/files/list` | `FileController::list` | — | 1152 |
| ANY | `/files/save-image` | `FileController::saveImage` | — | 1249 |
| POST | `/files/save-latex` | `FileController::saveLatex` | — | 1146 |
| ANY | `/files/save-pdf` | `FileController::savePdf` | — | 1250 |
| POST | `/files/save-tex` | `FileController::saveTex` | — | 1145 |

## /geogebra

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/geogebra/catalog` | `GeoGebraCatalogController::list` | — | 381 |
| POST | `/geogebra/catalog/delete` | `GeoGebraCatalogController::delete` | — | 385 |
| POST | `/geogebra/catalog/save` | `GeoGebraCatalogController::save` | — | 384 |
| GET | `/geogebra/catalog/{id}` | `GeoGebraCatalogController::get` | — | 382 |

## /health

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/health` | `HealthController::health` | — | 41 |

## /lab

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/lab/{path*}` | `(?)` | `legacy_gone` | 330 |

## /legal

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/legal/aup` | `TrustPagesController::aup` | — | 235 |
| GET | `/legal/dpa` | `TrustPagesController::dpa` | — | 237 |
| GET | `/legal/takedown-procedure` | `TrustPagesController::takedownProcedure` | — | 236 |
| GET | `/legal/tos` | `TrustPagesController::tos` | — | 234 |

## /log

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/log/admin/{path*}` | `LogServeController::show` | — | 1284 |
| ANY | `/log/auth/{path*}` | `LogServeController::show` | — | 1432 |
| ANY | `/log/logging/{path*}` | `LogServeController::show` | — | 1285 |
| ANY | `/log/logout/{path*}` | `LogServeController::show` | — | 1434 |
| ANY | `/log/security/{path*}` | `LogServeController::show` | — | 1286 |

## /login

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/login` | `AuthController::showLogin` | — | 42 |
| POST | `/login` | `AuthController::login` | `csrf rate:login 10` | 44 |

## /logout

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/logout` | `AuthController::logout` | — | 45 |

## /mappe

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/mappe/{path*}` | `(?)` | `legacy_gone` | 314 |

## /me

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/me/2fa` | `TotpController::page` | — | 69 |
| POST | `/me/2fa/disable` | `TotpController::disable` | `csrf` | 72 |
| POST | `/me/2fa/enable` | `TotpController::enable` | `csrf` | 71 |
| POST | `/me/2fa/setup` | `TotpController::setup` | `csrf` | 70 |
| POST | `/me/cancel-deletion` | `SelfServiceController::cancelDeletion` | `csrf` | 82 |
| GET | `/me/change-password` | `UserProfileController::showChangePassword` | — | 62 |
| POST | `/me/change-password` | `UserProfileController::changePassword` | `csrf` | 63 |
| GET | `/me/confirm-deletion` | `SelfServiceController::confirmDeletion` | — | 81 |
| GET | `/me/consents` | `SelfServiceController::consentsList` | — | 76 |
| POST | `/me/consents/grant` | `SelfServiceController::consentGrant` | `csrf` | 77 |
| POST | `/me/consents/revoke` | `SelfServiceController::consentRevoke` | `csrf` | 78 |
| GET | `/me/deletion-status` | `SelfServiceController::deletionStatus` | — | 83 |
| GET | `/me/export-data` | `SelfServiceController::exportData` | `rate:export 3` | 85 |
| POST | `/me/profile` | `SelfServiceController::profilePatch` | `csrf` | 86 |
| POST | `/me/request-deletion` | `SelfServiceController::requestDeletion` | `csrf rate:deletion 5` | 80 |

## /metrics

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/metrics` | `MetricsController::show` | — | 250 |

## /modelli_tikz.json

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/modelli_tikz.json` | `TikzDataController::show` | — | 1131 |

## /modelli_tikz_elements.json

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/modelli_tikz_elements.json` | `TikzDataController::show` | — | 1132 |

## /modelli_tikz_traccia.json

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/modelli_tikz_traccia.json` | `TikzDataController::show` | — | 1133 |

## /modello_pag_listSidebar.php

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/modello_pag_listSidebar.php` | `AdminPartialController::show` | — | 335 |

## /parent-consent

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/parent-consent/{token}` | `ParentConsentController::preview` | — | 90 |
| POST | `/parent-consent/{token}` | `ParentConsentController::confirm` | — | 91 |

## /privacy

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/privacy/informativa` | `TrustPagesController::informativa` | — | 230 |
| GET | `/privacy/your-data` | `TrustPagesController::yourData` | — | 229 |

## /register

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/register` | `RegistrationController::showForm` | — | 259 |
| POST | `/register` | `RegistrationController::submit` | `csrf` | 260 |

## /risdoc

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/risdoc/edit/{id}` | `TemplateEditorController::show` | — | 1045 |
| GET | `/risdoc/view/{id}` | `TemplateViewController::show` | — | 1043 |
| GET | `/risdoc/{category}/php/{filename}` | `TemplateViewController::showByLegacyPath` | — | 1049 |
| ANY | `/risdoc/{path*}` | `(?)` | `legacy_gone` | 1120 |
| GET | `/risdoc/{path*}` | `TemplateController::legacyPath` | — | 1094 |

## /security

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/security` | `TrustPagesController::security` | — | 228 |

## /segnalazione-contenuti

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/segnalazione-contenuti` | `PublicTakedownController::showForm` | — | 101 |
| POST | `/segnalazione-contenuti` | `PublicTakedownController::submit` | `rate:takedown 3` | 102 |

## /storage

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/storage/signed` | `StorageController::signed` | — | 273 |

## /strcomp_bes_altro

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/strcomp_bes_altro/{path*}` | `(?)` | `legacy_gone` | 1121 |

## /studio

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/studio/{indirizzo}/{classe}/{materia}` | `ExerciseStudyController::topicsPage` | — | 419 |
| GET | `/studio/{indirizzo}/{classe}/{materia}/{topic}` | `ExerciseStudyController::topicPage` | — | 421 |
| GET | `/studio/{type}/{ind}/{cls}/{subj}` | `ContentStudyController::topicsPage` | — | 411 |
| GET | `/studio/{type}/{ind}/{cls}/{subj}/{topic}` | `ContentStudyController::topicPage` | — | 413 |

## /teacher

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/teacher` | `(closure)` | — | 531 |
| GET | `/teacher/dashboard` | `(closure)` | — | 532 |
| GET | `/teacher/drive/callback` | `DriveController::callback` | — | 720 |
| GET | `/teacher/drive/connect` | `DriveController::connect` | — | 718 |
| GET | `/teacher/drive/connect-migration` | `DriveController::connectMigration` | — | 719 |
| POST | `/teacher/drive/disconnect` | `DriveController::disconnect` | — | 792 |
| GET | `/teacher/drive/status.json` | `DriveController::status` | — | 721 |
| GET | `/teacher/pdf-import` | `(closure)` | — | 557 |
| GET | `/teacher/pdf-import/models` | `(closure)` | — | 559 |
| POST | `/teacher/print` | `TeacherPrintController::generate` | — | 582 |
| GET | `/teacher/resources` | `(closure)` | — | 540 |
| GET | `/teacher/templates` | `(closure)` | — | 546 |

## /tex

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/tex/format` | `TexFormatController::format` | — | 355 |

## /tikz

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/tikz/admin-library` | `TeacherWorkspaceController::getAdminLibrary` | — | 393 |
| GET | `/tikz/content` | `TikzController::content` | — | 1164 |
| ANY | `/tikz/delete-element` | `TikzController::deleteElement` | — | 1265 |
| POST | `/tikz/delete-svg` | `TikzController::deleteSvg` | — | 1162 |
| ANY | `/tikz/edit-element` | `TikzController::editElement` | — | 1264 |
| GET | `/tikz/effective-templates` | `TeacherTemplateController::effective` | — | 361 |
| GET | `/tikz/ensure-json` | `TikzController::ensureJson` | — | 1165 |
| ANY | `/tikz/generate-json` | `TikzController::generateJson` | — | 1266 |
| GET | `/tikz/render` | `TikzRenderController::lookup` | — | 343 |
| POST | `/tikz/render` | `TikzRenderController::render` | — | 350 |
| ANY | `/tikz/save-new-element` | `TikzController::saveNewElement` | — | 1263 |
| POST | `/tikz/save-svg` | `TikzController::saveSvg` | — | 1161 |
| POST | `/tikz/teacher-templates/reset` | `TeacherTemplateController::reset` | — | 364 |
| POST | `/tikz/teacher-templates/save` | `TeacherTemplateController::save` | — | 363 |
| GET | `/tikz/workspace` | `TeacherWorkspaceController::getWorkspace` | — | 392 |
| POST | `/tikz/workspace/element/delete` | `TeacherWorkspaceController::deleteElement` | — | 396 |
| POST | `/tikz/workspace/element/save` | `TeacherWorkspaceController::saveElement` | — | 395 |
| POST | `/tikz/workspace/group/delete` | `TeacherWorkspaceController::deleteGroup` | — | 398 |
| POST | `/tikz/workspace/group/rename` | `TeacherWorkspaceController::renameGroup` | — | 397 |
| POST | `/tikz/workspace/group/reorder` | `TeacherWorkspaceController::reorderGroups` | — | 399 |
| POST | `/tikz/workspace/import` | `TeacherWorkspaceController::importFromAdmin` | — | 401 |
| POST | `/tikz/workspace/reset-all` | `TeacherWorkspaceController::resetAll` | — | 400 |

## /tikzjax.js

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/tikzjax.js` | `(closure)` | — | 301 |

## /tos-acceptance

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/tos-acceptance` | `TosAcceptanceController::show` | `auth` | 222 |
| POST | `/tos-acceptance` | `TosAcceptanceController::submit` | `auth csrf` | 224 |

## /verifiche

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/verifiche/print-info` | `VerificheController::managePrintInfo` | — | 683 |
| ANY | `/verifiche/scelte` | `VerificheController::saveLoadScelte` | — | 684 |
| ANY | `/verifiche/{path*}` | `(?)` | `legacy_gone` | 1128 |

## /version

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/version` | `HealthController::version` | — | 40 |

## /waf

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/waf/fingerprint` | `WafApiController::collect` | `rate:waf_fp 40` | 1371 |

