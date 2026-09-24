# Route inventory

> Generato da `routes/web.php` con `php tools/dev/gen_routes_md.php > docs/ROUTES.md`. **Non editare a mano** — rigenera dopo ogni modifica alle route.
>
> **Cosa mostra**: verbo, path letterale, handler `Controller::method`, middleware *route-local*, riga in `routes/web.php`.
> **Cosa NON mostra**: il middleware ereditato dai `group()` (es. `auth`, `role:teacher`, `log`) — è sul wrapper del gruppo, non sulla singola route. Per il middleware effettivo di una route apri `routes/web.php` alla riga indicata e risali al `group()` che la contiene.

Totale: **561** route in 68 gruppi (per prefix di path). Flusso: route → controller in `app/Controllers/` → service in `app/Services/` (vedi `docs/SERVICES.md`).

## Indice gruppi

- [`/`](#) — 1
- [`/Elementi_Riservati.html`](#elementiriservatihtml) — 1
- [`/accessibility`](#accessibility) — 1
- [`/accesso-classe`](#accessoclasse) — 4
- [`/admin`](#admin) — 113
- [`/analytics`](#analytics) — 1
- [`/api/access`](#apiaccess) — 3
- [`/api/admin`](#apiadmin) — 56
- [`/api/csp-report`](#apicspreport) — 1
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
- [`/api/study`](#apistudy) — 7
- [`/api/teacher`](#apiteacher) — 134
- [`/api/tenant`](#apitenant) — 2
- [`/api/tex`](#apitex) — 1
- [`/api/verifica`](#apiverifica) — 25
- [`/area-docente`](#areadocente) — 15
- [`/auth`](#auth) — 11
- [`/check`](#check) — 1
- [`/cookies_privacy-policy.html`](#cookiesprivacypolicyhtml) — 1
- [`/curriculum`](#curriculum) — 1
- [`/delete_temp.php`](#deletetempphp) — 1
- [`/didattica`](#didattica) — 1
- [`/dpo-contact`](#dpocontact) — 2
- [`/drafts`](#drafts) — 1
- [`/eser`](#eser) — 1
- [`/exercises`](#exercises) — 2
- [`/favicon.ico`](#faviconico) — 1
- [`/files`](#files) — 6
- [`/geogebra`](#geogebra) — 4
- [`/health`](#health) — 3
- [`/istituto`](#istituto) — 1
- [`/lab`](#lab) — 1
- [`/legal`](#legal) — 6
- [`/login`](#login) — 4
- [`/logout`](#logout) — 1
- [`/mappe`](#mappe) — 1
- [`/me`](#me) — 23
- [`/metrics`](#metrics) — 1
- [`/modelli_tikz_elements.json`](#modellitikzelementsjson) — 1
- [`/modelli_tikz_traccia.json`](#modellitikztracciajson) — 1
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
- [`/tikz`](#tikz) — 19
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
| ANY | `/Elementi_Riservati.html` | `AdminPartialController::show` | — | 703 |

## /accessibility

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/accessibility` | `TrustPagesController::accessibility` | — | 387 |

## /accesso-classe

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/accesso-classe` | `AuthController::showClassAccess` | — | 49 |
| POST | `/accesso-classe/esci` | `AuthController::classAccessLogout` | `csrf` | 50 |
| GET | `/accesso-classe/pacchetto.svg` | `TeacherCredentialController::bundleSvg` | — | 442 |
| GET | `/accesso-classe/qr/{token}` | `TeacherCredentialController::qrLogin` | `rate:qr 20` | 440 |

## /admin

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/admin` | `AdminToolsController::page` | — | 1427 |
| GET | `/admin/access-log` | `AdminController::accessLog` | — | 1499 |
| GET | `/admin/access-stats` | `AdminController::accessStats` | — | 1500 |
| GET | `/admin/analytics` | `AdminAnalyticsController::page` | — | 1436 |
| GET | `/admin/backup` | `AdminBackupController::index` | — | 303 |
| POST | `/admin/backup/b2-verified` | `AdminBackupController::b2Verified` | `csrf audit_reason:backup_b2_verified backup` | 306 |
| POST | `/admin/backup/cold-completed` | `AdminBackupController::coldCompleted` | `csrf audit_reason:backup_cold_completed backup` | 304 |
| GET | `/admin/crypto-status` | `AdminCryptoStatusController::index` | — | 297 |
| POST | `/admin/crypto-status/event` | `AdminCryptoStatusController::recordEvent` | `csrf audit_reason:crypto_event_record crypto_custody` | 299 |
| GET | `/admin/crypto-status/export` | `AdminCryptoStatusController::export` | — | 298 |
| GET | `/admin/curriculum` | `(closure)` | — | 712 |
| GET | `/admin/dashboard` | `AdminController::dashboard` | — | 1428 |
| GET | `/admin/data-breach` | `AdminGdprController::dataBreachIndex` | — | 278 |
| GET | `/admin/data-breach/new` | `AdminGdprController::dataBreachNewForm` | — | 281 |
| POST | `/admin/data-breach/new` | `AdminGdprController::dataBreachCreate` | `csrf audit_reason:data_breach_create data_breach` | 282 |
| GET | `/admin/data-breach/piano` | `AdminGdprController::dataBreachPiano` | — | 280 |
| GET | `/admin/data-breach/{id}` | `AdminGdprController::dataBreachShow` | — | 284 |
| POST | `/admin/data-breach/{id}/action` | `AdminGdprController::dataBreachAction` | `csrf audit_reason:data_breach_action data_breach` | 285 |
| GET | `/admin/data-requests` | `AdminGdprController::dataRequestsIndex` | — | 273 |
| GET | `/admin/data-requests/{id}` | `AdminGdprController::dataRequestsShow` | — | 274 |
| POST | `/admin/data-requests/{id}/action` | `AdminGdprController::dataRequestsAction` | `csrf audit_reason:data_request_action gdpr_request` | 275 |
| GET | `/admin/debug-log` | `AdminController::debugLog` | — | 1501 |
| GET | `/admin/gdpr` | `(closure)` | — | 257 |
| GET | `/admin/gdpr/authority-export` | `AdminGdprController::authorityExportPage` | — | 262 |
| POST | `/admin/gdpr/authority-export` | `AdminGdprController::authorityExportSubmit` | `csrf audit_reason:authority_export gdpr_export` | 263 |
| POST | `/admin/generate-hash` | `AdminController::generateHash` | — | 1513 |
| GET | `/admin/infrastructure` | `AdminInfrastructureController::page` | — | 1448 |
| GET | `/admin/institutes` | `AdminInstitutesController::index` | — | 212 |
| GET | `/admin/institutes/adozioni` | `AdminInstitutesController::adozioniPreview` | — | 236 |
| POST | `/admin/institutes/adozioni/apply` | `AdminInstitutesController::adozioniApply` | `csrf audit_reason:miur_adoptions_apply adoptions` | 237 |
| POST | `/admin/institutes/miur/adozioni` | `AdminInstitutesController::adozioniUpload` | `csrf audit_reason:miur_adoptions_upload adoptions` | 234 |
| POST | `/admin/institutes/miur/update` | `AdminInstitutesController::miurUpdate` | `csrf audit_reason:miur_schools_update miur_registry` | 229 |
| GET | `/admin/institutes/new` | `AdminInstitutesController::newForm` | — | 213 |
| POST | `/admin/institutes/new` | `AdminInstitutesController::create` | `csrf audit_reason:institute_create institute` | 214 |
| POST | `/admin/institutes/{id}/active` | `AdminInstitutesController::toggleActive` | `csrf audit_reason:institute_active_toggle institute` | 218 |
| GET | `/admin/institutes/{id}/catalogo` | `AdminCatalogoController::index` | — | 244 |
| POST | `/admin/institutes/{id}/catalogo` | `AdminCatalogoController::aggiungi` | `csrf audit_reason` | 245 |
| POST | `/admin/institutes/{id}/catalogo/{entry}` | `AdminCatalogoController::modifica` | `csrf audit_reason` | 247 |
| POST | `/admin/institutes/{id}/compilation-storage` | `AdminInstitutesController::toggleCompilationStorage` | `csrf audit_reason:institute_compilation_storage institute` | 223 |
| POST | `/admin/institutes/{id}/sezioni-docenti` | `AdminInstitutesController::impostaSezioniDocenti` | `csrf audit_reason:institute_teacher_sections_policy institute` | 226 |
| GET | `/admin/logs` | `AdminLogsController::page` | — | 270 |
| GET | `/admin/logs/api/{table}` | `AdminLogsController::apiQuery` | — | 271 |
| GET | `/admin/migrate` | `AdminMigrateController::page` | — | 1444 |
| POST | `/admin/migrate/run` | `AdminMigrateController::run` | `audit_reason:db_migrate database` | 1381 |
| GET | `/admin/migrate/status` | `AdminMigrateController::status` | — | 1445 |
| GET | `/admin/monitoring` | `AdminMonitoringController::index` | — | 310 |
| POST | `/admin/print` | `AdminPrintController::generate` | — | 1378 |
| POST | `/admin/print/batch` | `AdminPrintController::batch` | — | 1379 |
| GET | `/admin/registrations` | `RegistrationController::listPending` | — | 1502 |
| POST | `/admin/registrations/{id}/approve` | `RegistrationController::approve` | `audit_reason:registration_approve registration_request` | 1504 |
| POST | `/admin/registrations/{id}/reject` | `RegistrationController::reject` | `audit_reason:registration_reject registration_request` | 1506 |
| GET | `/admin/risdoc` | `RisdocAdminController::page` | — | 1583 |
| GET | `/admin/risdoc/pending/{id}/preview` | `RisdocAdminController::pendingPreviewPage` | — | 1594 |
| GET | `/admin/sections` | `AdminSectionsController::index` | — | 189 |
| GET | `/admin/sections/anteprima-revoca` | `AdminSectionsController::anteprimaRevoca` | — | 195 |
| POST | `/admin/sections/assign` | `AdminSectionsController::assign` | `csrf audit_reason:teacher_sections_assign teacher_sections` | 190 |
| POST | `/admin/sections/materiali` | `AdminSectionsController::materiali` | `csrf audit_reason` | 201 |
| POST | `/admin/sections/revoke` | `AdminSectionsController::revoke` | `csrf audit_reason:teacher_sections_revoke teacher_sections` | 192 |
| POST | `/admin/sections/scadenza` | `AdminSectionsController::scadenza` | `csrf audit_reason:leftover_materials_deadline teacher_sections` | 203 |
| POST | `/admin/sections/student` | `AdminSectionsController::student` | `csrf audit_reason` | 196 |
| POST | `/admin/sections/subjects` | `AdminSectionsController::subjects` | `csrf audit_reason:teacher_subjects_set teacher_subjects` | 207 |
| GET | `/admin/sidebar-config` | `AdminSidebarConfigController::page` | — | 172 |
| POST | `/admin/sidebar-config/delete` | `AdminSidebarConfigController::delete` | `csrf audit_reason:sidebar_section_delete sidebar_config` | 175 |
| POST | `/admin/sidebar-config/pubblica-in-rete` | `AdminSidebarConfigController::pubblicaInRete` | `csrf audit_reason` | 181 |
| POST | `/admin/sidebar-config/reorder` | `AdminSidebarConfigController::reorder` | `csrf audit_reason:sidebar_sections_reorder sidebar_config` | 177 |
| POST | `/admin/sidebar-config/save` | `AdminSidebarConfigController::save` | `csrf audit_reason:sidebar_section_save sidebar_config` | 173 |
| GET | `/admin/subprocessors` | `AdminGdprController::subprocessorsIndex` | — | 288 |
| GET | `/admin/subprocessors/new` | `AdminGdprController::subprocessorsNewForm` | — | 289 |
| POST | `/admin/subprocessors/save` | `AdminGdprController::subprocessorsSave` | `csrf audit_reason:subprocessor_save subprocessor` | 291 |
| POST | `/admin/subprocessors/{id}/delete` | `AdminGdprController::subprocessorsDelete` | `csrf audit_reason:subprocessor_delete subprocessor` | 293 |
| GET | `/admin/subprocessors/{id}/edit` | `AdminGdprController::subprocessorsEditForm` | — | 290 |
| POST | `/admin/system/2fa-enforce` | `AdminSystemController::twoFactorEnforceSet` | `csrf audit_reason` | 328 |
| POST | `/admin/system/capability/assign` | `AdminSystemController::capabilityAssign` | `csrf audit_reason:capability_assign capability` | 337 |
| POST | `/admin/system/capability/profile/delete` | `AdminSystemController::capabilityProfileDelete` | `csrf audit_reason:capability_profile_delete capability` | 335 |
| POST | `/admin/system/capability/profile/save` | `AdminSystemController::capabilityProfileSave` | `csrf audit_reason:capability_profile_save capability` | 333 |
| GET | `/admin/system/deployment` | `AdminSystemController::deploymentPage` | — | 314 |
| POST | `/admin/system/deployment/switch` | `AdminSystemController::deploymentSwitch` | `csrf audit_reason:deployment_switch deployment` | 315 |
| POST | `/admin/system/registration-classes/add` | `AdminSystemController::registrationClassAdd` | `csrf audit_reason:registration_class_add registration` | 318 |
| POST | `/admin/system/registration-classes/remove` | `AdminSystemController::registrationClassRemove` | `csrf audit_reason:registration_class_remove registration` | 320 |
| POST | `/admin/system/registration-mode` | `AdminSystemController::registrationModeSet` | `csrf audit_reason:registration_mode_set registration` | 323 |
| POST | `/admin/system/tos-enforce` | `AdminSystemController::tosEnforceSet` | `csrf audit_reason` | 330 |
| GET | `/admin/takedown` | `AdminTakedownController::index` | — | 162 |
| GET | `/admin/takedown/{id}` | `AdminTakedownController::show` | — | 163 |
| POST | `/admin/takedown/{id}/action` | `AdminTakedownController::action` | `csrf audit_reason:takedown_decision takedown_request` | 164 |
| GET | `/admin/templates` | `TemplatesAdminController::page` | — | 1579 |
| GET | `/admin/tools` | `AdminController::index` | — | 1430 |
| GET | `/admin/tools/hash` | `AdminController::hashToolPage` | — | 1429 |
| GET | `/admin/tos-log` | `AdminTosLogController::index` | — | 168 |
| GET | `/admin/waf` | `WafAdminController::index` | — | 1696 |
| GET | `/admin/waf/anomalies` | `WafAdminController::anomaliesPage` | — | 1705 |
| POST | `/admin/waf/api/blacklist` | `WafAdminController::apiAddBlacklist` | — | 1721 |
| DELETE | `/admin/waf/api/blacklist/{id}` | `WafAdminController::apiDeleteBlacklist` | — | 1722 |
| POST | `/admin/waf/api/config` | `WafAdminController::apiUpdateConfig` | — | 1714 |
| GET | `/admin/waf/api/counters` | `WafAdminController::apiCounters` | — | 1710 |
| GET | `/admin/waf/api/cti` | `WafAdminController::apiCti` | — | 1716 |
| GET | `/admin/waf/api/logs` | `WafAdminController::apiLogs` | — | 1709 |
| POST | `/admin/waf/api/rules` | `WafAdminController::apiCreateRule` | — | 1717 |
| DELETE | `/admin/waf/api/rules/{id}` | `WafAdminController::apiDeleteRule` | — | 1719 |
| PUT | `/admin/waf/api/rules/{id}` | `WafAdminController::apiUpdateRule` | — | 1718 |
| POST | `/admin/waf/api/rules/{id}/toggle` | `WafAdminController::apiToggleRule` | — | 1720 |
| POST | `/admin/waf/api/threat-intel/sync` | `WafAdminController::apiThreatIntelSync` | — | 1715 |
| POST | `/admin/waf/api/whitelist` | `WafAdminController::apiAddWhitelist` | — | 1723 |
| DELETE | `/admin/waf/api/whitelist/{id}` | `WafAdminController::apiDeleteWhitelist` | — | 1724 |
| GET | `/admin/waf/blocks` | `WafAdminController::blocksPage` | — | 1701 |
| GET | `/admin/waf/config` | `WafAdminController::configPage` | — | 1698 |
| GET | `/admin/waf/credentials` | `WafAdminController::credentialsPage` | — | 1704 |
| GET | `/admin/waf/dashboard` | `WafAdminController::dashboard` | — | 1697 |
| GET | `/admin/waf/diag` | `WafAdminController::diagPage` | — | 1708 |
| GET | `/admin/waf/lists` | `WafAdminController::listsPage` | — | 1703 |
| GET | `/admin/waf/reports` | `WafAdminController::reportsPage` | — | 1706 |
| GET | `/admin/waf/rules` | `WafAdminController::rulesPage` | — | 1699 |
| GET | `/admin/waf/threat-intel` | `WafAdminController::threatIntelPage` | — | 1707 |
| GET | `/admin/whoami` | `AdminController::whoAmI` | — | 1510 |

## /analytics

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/analytics/nav` | `AnalyticsController::navBeacon` | — | 400 |

## /api/access

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/access/status` | `TeacherCredentialController::studentStatus` | — | 445 |
| POST | `/api/access/student-login` | `TeacherCredentialController::studentLogin` | `csrf rate` | 435 |
| POST | `/api/access/student-logout` | `TeacherCredentialController::studentLogout` | `csrf` | 443 |

## /api/admin

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/admin/adozioni` | `AdminAdozioniController::index` | — | 1465 |
| POST | `/api/admin/adozioni` | `AdminAdozioniController::create` | `audit_reason:adoption_create adoptions` | 1469 |
| POST | `/api/admin/adozioni/{id}/delete` | `AdminAdozioniController::delete` | `audit_reason:adoption_delete adoptions` | 1471 |
| GET | `/api/admin/analytics` | `AdminAnalyticsController::snapshot` | — | 1437 |
| GET | `/api/admin/analytics/cross-search` | `AdminAnalyticsController::crossSearch` | — | 1439 |
| GET | `/api/admin/analytics/teacher/{id}` | `AdminAnalyticsController::forTeacher` | — | 1438 |
| GET | `/api/admin/badge-style-presets` | `BadgeStyleController::adminList` | — | 1674 |
| DELETE | `/api/admin/badge-style-presets/{name}` | `BadgeStyleController::adminDelete` | `csrf audit_reason:badge_style_preset_delete badge_style_presets` | 1680 |
| GET | `/api/admin/badge-style-presets/{name}` | `BadgeStyleController::adminGet` | — | 1676 |
| PUT | `/api/admin/badge-style-presets/{name}` | `BadgeStyleController::adminPut` | `csrf audit_reason:badge_style_preset_save badge_style_presets` | 1678 |
| GET | `/api/admin/gdpr/teacher-content-search` | `AdminGdprController::teacherContentSearch` | — | 266 |
| GET | `/api/admin/infrastructure.json` | `AdminInfrastructureController::snapshotJson` | — | 1450 |
| GET | `/api/admin/latex-shortcuts` | `LatexShortcutsController::adminList` | — | 1666 |
| POST | `/api/admin/latex-shortcuts` | `LatexShortcutsController::adminSave` | `audit_reason:latex_shortcuts_save latex_shortcuts` | 1668 |
| GET | `/api/admin/notifications` | `AdminController::notifications` | — | 1511 |
| GET | `/api/admin/risdoc/drift` | `RisdocAdminController::driftList` | — | 1587 |
| GET | `/api/admin/risdoc/options-source` | `RisdocAdminController::optionsSourceRead` | — | 1597 |
| POST | `/api/admin/risdoc/options-source` | `RisdocAdminController::optionsSourceSave` | — | 1623 |
| GET | `/api/admin/risdoc/options-sources` | `RisdocAdminController::optionsSourcesList` | — | 1596 |
| GET | `/api/admin/risdoc/pending` | `RisdocAdminController::pendingList` | — | 1589 |
| POST | `/api/admin/risdoc/pending/{id}/approve` | `RisdocAdminController::pendingApprove` | — | 1607 |
| GET | `/api/admin/risdoc/pending/{id}/content` | `RisdocAdminController::pendingContent` | — | 1590 |
| POST | `/api/admin/risdoc/pending/{id}/reject` | `RisdocAdminController::pendingReject` | — | 1609 |
| GET | `/api/admin/risdoc/pending/{id}/schema` | `RisdocAdminController::pendingSchema` | — | 1592 |
| GET | `/api/admin/risdoc/teachers` | `RisdocAdminController::teachersList` | — | 1586 |
| GET | `/api/admin/risdoc/templates` | `RisdocAdminController::templatesList` | — | 1584 |
| POST | `/api/admin/risdoc/templates/create` | `RisdocAdminController::createTemplate` | — | 1620 |
| POST | `/api/admin/risdoc/templates/rename-group` | `RisdocAdminController::renameGroup` | — | 1617 |
| GET | `/api/admin/risdoc/templates/{id}` | `RisdocAdminController::templateDetail` | — | 1585 |
| POST | `/api/admin/risdoc/templates/{id}/collaborators` | `RisdocAdminController::collaboratorsEdit` | — | 1604 |
| POST | `/api/admin/risdoc/templates/{id}/meta` | `RisdocAdminController::updateMeta` | — | 1615 |
| POST | `/api/admin/risdoc/templates/{id}/visibility` | `RisdocAdminController::visibilityBulk` | — | 1601 |
| POST | `/api/admin/risdoc/templates/{id}/visibility-scope` | `RisdocAdminController::setVisibilityScope` | — | 1612 |
| GET | `/api/admin/security/anomalies` | `SecurityAdminController::anomalies` | — | 1459 |
| GET | `/api/admin/security/blocked-credentials` | `SecurityAdminController::listBlockedCredentials` | — | 1457 |
| GET | `/api/admin/security/blocked-ips` | `SecurityAdminController::listBlockedIps` | — | 1458 |
| GET | `/api/admin/security/config` | `SecurityAdminController::getConfig` | — | 1461 |
| POST | `/api/admin/security/config` | `SecurityAdminController::setConfig` | `audit_reason:security_alerts_config security_config` | 1489 |
| POST | `/api/admin/security/credentials/block` | `SecurityAdminController::blockCredential` | `audit_reason:credential_block security_blocks` | 1481 |
| POST | `/api/admin/security/credentials/unblock` | `SecurityAdminController::unblockCredential` | `audit_reason:credential_unblock security_blocks` | 1483 |
| POST | `/api/admin/security/ips/block` | `SecurityAdminController::blockIp` | `audit_reason:ip_block security_blocks` | 1485 |
| POST | `/api/admin/security/ips/unblock` | `SecurityAdminController::unblockIp` | `audit_reason:ip_unblock security_blocks` | 1487 |
| GET | `/api/admin/security/live-blocks` | `SecurityAdminController::liveBlocks` | — | 1460 |
| GET | `/api/admin/users` | `UsersAdminController::index` | — | 1456 |
| POST | `/api/admin/users/{id}/active` | `UsersAdminController::setActive` | `audit_reason:user_active_set user` | 1475 |
| POST | `/api/admin/users/{id}/delete` | `UsersAdminController::delete` | `audit_reason:user_delete user` | 1479 |
| POST | `/api/admin/users/{id}/role` | `UsersAdminController::setRole` | `audit_reason:user_role_set user` | 1477 |
| GET | `/api/admin/verifica/files` | `VerificaFilesAdminController::listFiles` | — | 1649 |
| POST | `/api/admin/verifica/files/copy-from-default` | `VerificaFilesAdminController::copyFromDefault` | — | 1640 |
| POST | `/api/admin/verifica/files/delete` | `VerificaFilesAdminController::deleteFile` | — | 1638 |
| GET | `/api/admin/verifica/files/read` | `VerificaFilesAdminController::readFile` | — | 1652 |
| POST | `/api/admin/verifica/files/write` | `VerificaFilesAdminController::writeFile` | — | 1636 |
| GET | `/api/admin/verifica/preamble` | `VerificaPreambleAdminController::get` | — | 1644 |
| POST | `/api/admin/verifica/preamble` | `VerificaPreambleAdminController::save` | — | 1629 |
| POST | `/api/admin/verifica/preamble/reset` | `VerificaPreambleAdminController::reset` | — | 1631 |
| GET | `/api/admin/verifica/scopes` | `VerificaFilesAdminController::listScopes` | — | 1647 |

## /api/csp-report

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/api/csp-report` | `CspReportController::collect` | `rate:csp 60` | 403 |

## /api/institutes

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/institutes` | `InstituteController::index` | — | 417 |
| POST | `/api/institutes` | `InstituteController::create` | `audit_reason:institute_create institute` | 1473 |

## /api/latex-shortcuts

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/latex-shortcuts/effective` | `LatexShortcutsController::effective` | — | 582 |
| POST | `/api/latex-shortcuts/reset` | `LatexShortcutsController::reset` | — | 585 |
| POST | `/api/latex-shortcuts/reset-all` | `LatexShortcutsController::resetAll` | — | 586 |
| POST | `/api/latex-shortcuts/save` | `LatexShortcutsController::save` | — | 584 |

## /api/maps

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/api/maps` | `MapsController::create` | `rate:content 60` | 987 |
| GET | `/api/maps/dl` | `MapsController::download` | — | 38 |
| POST | `/api/maps/sync-all` | `MapsController::syncAll` | `rate:content 30` | 999 |
| GET | `/api/maps/{id}/signed-url` | `MapsController::signedUrl` | — | 1009 |
| POST | `/api/maps/{id}/sync` | `MapsController::sync` | `rate:content 60` | 997 |
| POST | `/api/maps/{id}/update` | `MapsController::update` | `rate:content 60` | 993 |

## /api/probe

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/api/probe` | `CsrfProbeController::probe` | — | 670 |

## /api/public

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/public/study/content.json` | `PublicStudyController::publicContentJson` | `rate:pub_study 120` | 375 |
| GET | `/api/public/study/topics.json` | `PublicStudyController::publicTopicsJson` | `rate:pub_study 120` | 373 |

## /api/risdoc

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/risdoc/compilations/{id}` | `CompilationController::show` | — | 1316 |
| POST | `/api/risdoc/compilations/{id}/delete` | `CompilationController::delete` | — | 1258 |
| POST | `/api/risdoc/compilations/{id}/scaricata` | `CompilationController::scaricata` | `rate:scaricata 60` | 1265 |
| GET | `/api/risdoc/curriculum-options` | `CurriculumOptionsController::options` | — | 1289 |
| POST | `/api/risdoc/curriculum-options` | `CurriculumOptionsController::save` | — | 1238 |
| POST | `/api/risdoc/curriculum-options/delete` | `CurriculumOptionsController::delete` | — | 1240 |
| GET | `/api/risdoc/options-sources` | `TemplateController::optionsSources` | — | 1309 |
| GET | `/api/risdoc/shared/{file}` | `TemplateController::sharedAsset` | — | 1311 |
| GET | `/api/risdoc/teacher/instances` | `TemplateController::teacherAllInstances` | — | 1302 |
| GET | `/api/risdoc/templates` | `TemplateController::index` | — | 1279 |
| GET | `/api/risdoc/templates/{id}` | `TemplateController::show` | — | 1281 |
| POST | `/api/risdoc/templates/{id}/body-pt` | `TemplateController::saveBodyPt` | — | 1229 |
| GET | `/api/risdoc/templates/{id}/compilations` | `CompilationController::index` | — | 1314 |
| POST | `/api/risdoc/templates/{id}/compilations` | `CompilationController::save` | — | 1256 |
| POST | `/api/risdoc/templates/{id}/compile-pdf` | `TexFilesController::compilePdf` | — | 1219 |
| GET | `/api/risdoc/templates/{id}/drift` | `TemplateController::driftStatus` | — | 1306 |
| POST | `/api/risdoc/templates/{id}/export` | `ExportController::export` | — | 1211 |
| GET | `/api/risdoc/templates/{id}/file` | `TemplateController::file` | — | 1283 |
| GET | `/api/risdoc/templates/{id}/instances` | `TemplateController::instancesList` | — | 1299 |
| POST | `/api/risdoc/templates/{id}/instances` | `TemplateController::instancesCreate` | `rate:instances 60` | 1245 |
| POST | `/api/risdoc/templates/{id}/instances/{key}/delete` | `TemplateController::instancesDelete` | `rate:instances 60` | 1248 |
| POST | `/api/risdoc/templates/{id}/instances/{key}/rename` | `TemplateController::instancesRename` | `rate:instances 60` | 1251 |
| POST | `/api/risdoc/templates/{id}/institutional-override` | `TemplateController::institutionalOverrideSave` | — | 1232 |
| POST | `/api/risdoc/templates/{id}/institutional-override/del` | `TemplateController::institutionalOverrideDelete` | — | 1234 |
| GET | `/api/risdoc/templates/{id}/institutional-overrides` | `TemplateController::institutionalOverridesList` | — | 1296 |
| GET | `/api/risdoc/templates/{id}/json-files` | `TemplateController::jsonFiles` | — | 1304 |
| POST | `/api/risdoc/templates/{id}/override` | `TemplateController::overrideSave` | — | 1207 |
| POST | `/api/risdoc/templates/{id}/override/del` | `TemplateController::overrideDelete` | — | 1209 |
| GET | `/api/risdoc/templates/{id}/overrides` | `TemplateController::overridesList` | — | 1293 |
| GET | `/api/risdoc/templates/{id}/schema` | `TemplateController::schema` | — | 1285 |
| GET | `/api/risdoc/templates/{id}/tex` | `TemplateController::tex` | — | 1291 |
| POST | `/api/risdoc/templates/{id}/tex-files` | `TexFilesController::getFiles` | — | 1215 |
| POST | `/api/risdoc/templates/{id}/tex-files/save` | `TexFilesController::saveFiles` | — | 1217 |

## /api/scuole

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/scuole` | `SchoolsController::search` | — | 420 |

## /api/sidebar

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/sidebar/config` | `SidebarConfigController::config` | — | 503 |

## /api/sidepage

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/sidepage/topics` | `SidepageController::topics` | — | 429 |

## /api/sources

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/sources/common` | `StudySourcesController::sourcesCommonJson` | — | 663 |

## /api/studio

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/studio/exercise/{id}.json` | `ExerciseStudyController::exerciseJson` | — | 625 |
| GET | `/api/studio/exercises.json` | `ExerciseStudyController::exercisesJson` | — | 623 |
| GET | `/api/studio/topics.json` | `ExerciseStudyController::topicsJson` | — | 621 |

## /api/study

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/study/content.json` | `ContentStudyController::contentJson` | — | 507 |
| GET | `/api/study/content/{id}.json` | `ContentStudyController::contentSingleJson` | — | 509 |
| GET | `/api/study/header-page.json` | `StudyHeaderController::headerPageStudentJson` | — | 520 |
| GET | `/api/study/materie.json` | `ContentStudyController::materieJson` | — | 513 |
| GET | `/api/study/related-verifiche.html` | `ContentStudyController::relatedVerificaHtml` | — | 525 |
| GET | `/api/study/topics.json` | `ContentStudyController::topicsJson` | — | 505 |
| GET | `/api/study/verifica/list` | `VerificaController::listForStudent` | — | 516 |

## /api/teacher

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/teacher/adozioni` | `TeacherAdozioniController::index` | — | 1030 |
| GET | `/api/teacher/badge-style` | `BadgeStyleController::teacherGet` | — | 636 |
| PUT | `/api/teacher/badge-style` | `BadgeStyleController::teacherPut` | `csrf` | 684 |
| GET | `/api/teacher/capabilities` | `TeacherContentController::capabilities` | — | 1087 |
| GET | `/api/teacher/category-labels` | `TeacherCategoryLabelController::list` | — | 1115 |
| POST | `/api/teacher/category-labels` | `TeacherCategoryLabelController::save` | — | 1118 |
| GET | `/api/teacher/checked-origins.json` | `StudySourcesController::checkedOriginsJson` | — | 654 |
| PUT | `/api/teacher/checked-origins.json` | `StudySourcesController::checkedOriginsSave` | `csrf` | 688 |
| GET | `/api/teacher/content` | `TeacherContentController::index` | — | 1101 |
| POST | `/api/teacher/content` | `TeacherContentController::store` | `rate:content 60` | 1131 |
| GET | `/api/teacher/content/{id}` | `TeacherContentController::show` | — | 1103 |
| POST | `/api/teacher/content/{id}/compile-pdf` | `ContentExportController::compilePdf` | `rate:compile 15` | 1160 |
| GET | `/api/teacher/content/{id}/contract` | `ContentExportController::contract` | — | 1093 |
| POST | `/api/teacher/content/{id}/delete` | `TeacherContentController::destroy` | `rate:content 60` | 1137 |
| POST | `/api/teacher/content/{id}/duplica` | `TeacherPublicationsController::duplica` | `rate:content 60` | 1170 |
| POST | `/api/teacher/content/{id}/export` | `ContentExportController::export` | — | 1151 |
| GET | `/api/teacher/content/{id}/export-html` | `ContentExportController::exportHtml` | — | 1112 |
| POST | `/api/teacher/content/{id}/group/add` | `GroupController::groupAdd` | — | 1197 |
| POST | `/api/teacher/content/{id}/group/{groupRef}/delete` | `GroupController::groupDelete` | — | 1203 |
| POST | `/api/teacher/content/{id}/group/{groupRef}/move` | `GroupController::groupMove` | — | 1194 |
| POST | `/api/teacher/content/{id}/group/{groupRef}/patch` | `GroupController::groupPatch` | — | 1200 |
| GET | `/api/teacher/content/{id}/provenance` | `ContentExportController::provenance` | — | 1176 |
| GET | `/api/teacher/content/{id}/pubblicazioni` | `TeacherPublicationsController::elenco` | — | 1107 |
| POST | `/api/teacher/content/{id}/pubblicazioni` | `TeacherPublicationsController::aggiungi` | — | 1164 |
| POST | `/api/teacher/content/{id}/publish` | `ContentPublishController::publish` | — | 1146 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/clone-to-eser` | `QuesitoController::quesitoCloneToEser` | — | 1191 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/delete` | `QuesitoController::quesitoDelete` | — | 1184 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/duplicate` | `QuesitoController::quesitoDuplicate` | — | 1188 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/move` | `QuesitoController::quesitoMove` | — | 1186 |
| POST | `/api/teacher/content/{id}/quesito/{itemRef}/patch` | `QuesitoController::quesitoPatch` | — | 1182 |
| POST | `/api/teacher/content/{id}/recategorize` | `TeacherContentController::recategorize` | `rate:content 60` | 1143 |
| POST | `/api/teacher/content/{id}/share-pool` | `ContentPublishController::sharePool` | — | 1174 |
| POST | `/api/teacher/content/{id}/tex-files` | `ContentExportController::texFiles` | — | 1154 |
| POST | `/api/teacher/content/{id}/tex-files/save` | `ContentExportController::saveTexFiles` | — | 1156 |
| POST | `/api/teacher/content/{id}/unpublish` | `ContentPublishController::unpublish` | — | 1148 |
| POST | `/api/teacher/content/{id}/update` | `TeacherContentController::update` | `rate:content 60` | 1134 |
| GET | `/api/teacher/credentials` | `TeacherCredentialController::index` | — | 1026 |
| POST | `/api/teacher/credentials` | `TeacherCredentialController::create` | — | 1043 |
| GET | `/api/teacher/credentials/anteprima-etichetta` | `TeacherCredentialController::labelPreview` | — | 1036 |
| POST | `/api/teacher/credentials/{id}/delete` | `TeacherCredentialController::delete` | — | 1045 |
| POST | `/api/teacher/credentials/{id}/etichetta` | `TeacherCredentialController::relabel` | — | 1057 |
| POST | `/api/teacher/credentials/{id}/expiry` | `TeacherCredentialController::expiry` | — | 1052 |
| POST | `/api/teacher/credentials/{id}/password` | `TeacherCredentialController::rotate` | — | 1050 |
| GET | `/api/teacher/credentials/{id}/qr.svg` | `TeacherCredentialController::qrSvg` | — | 1033 |
| POST | `/api/teacher/credentials/{id}/qr/regenerate` | `TeacherCredentialController::qrRegenerate` | — | 1054 |
| POST | `/api/teacher/credentials/{id}/toggle` | `TeacherCredentialController::toggle` | — | 1047 |
| GET | `/api/teacher/curriculum` | `CurriculumController::index` | — | 1080 |
| GET | `/api/teacher/curriculum/pivot` | `TeacherCurriculumPivotController::listMine` | — | 1082 |
| POST | `/api/teacher/curriculum/pivot/toggle` | `TeacherCurriculumPivotController::toggle` | — | 1128 |
| POST | `/api/teacher/curriculum/{id}/remove` | `CurriculumController::remove` | — | 1125 |
| POST | `/api/teacher/curriculum/{id}/update` | `CurriculumController::update` | — | 1123 |
| POST | `/api/teacher/curriculum/{kind}` | `CurriculumController::add` | — | 1121 |
| GET | `/api/teacher/drawio/libraries` | `TeacherDrawioLibraryController::list` | — | 926 |
| POST | `/api/teacher/drawio/libraries/delete` | `TeacherDrawioLibraryController::delete` | — | 975 |
| GET | `/api/teacher/drawio/libraries/read/{name}` | `TeacherDrawioLibraryController::read` | — | 928 |
| POST | `/api/teacher/drawio/libraries/save-content` | `TeacherDrawioLibraryController::saveContent` | — | 979 |
| POST | `/api/teacher/drawio/libraries/upload` | `TeacherDrawioLibraryController::upload` | — | 973 |
| POST | `/api/teacher/github/configure` | `TeacherGitHubController::configure` | — | 964 |
| POST | `/api/teacher/github/disconnect` | `TeacherGitHubController::disconnect` | — | 965 |
| POST | `/api/teacher/github/push-file` | `TeacherGitHubController::pushFile` | — | 968 |
| GET | `/api/teacher/github/status` | `TeacherGitHubController::status` | — | 924 |
| POST | `/api/teacher/github/sync-all` | `TeacherGitHubController::syncAll` | — | 967 |
| POST | `/api/teacher/github/sync-test` | `TeacherGitHubController::syncTest` | — | 966 |
| GET | `/api/teacher/header-page.json` | `StudyHeaderController::headerPageJson` | — | 659 |
| PUT | `/api/teacher/header-page.json` | `StudyHeaderController::headerPageSave` | `csrf` | 690 |
| POST | `/api/teacher/import-bundle/apply` | `ImportBundleController::apply` | `rate:import 4` | 940 |
| POST | `/api/teacher/import-bundle/preview` | `ImportBundleController::preview` | `rate:import 4` | 937 |
| GET | `/api/teacher/institutes` | `InstituteController::listForTeacher` | — | 1024 |
| POST | `/api/teacher/institutes/link` | `InstituteController::link` | — | 1039 |
| POST | `/api/teacher/institutes/{id}/unlink` | `InstituteController::unlink` | — | 1041 |
| GET | `/api/teacher/manifest/{type}` | `ContentExportController::manifest` | — | 1090 |
| GET | `/api/teacher/origins.json` | `StudySourcesController::originsJson` | — | 650 |
| POST | `/api/teacher/pdf-import/provider-cache` | `PdfImportController::toggleCache` | `rate:pdf_import config:pdf_import.rate.pdf_import` | 836 |
| GET | `/api/teacher/pdf-import/provider-keys` | `PdfImportController::providerKeysStatus` | — | 745 |
| POST | `/api/teacher/pdf-import/provider-keys` | `PdfImportController::saveProviderKey` | `rate:pdf_import config:pdf_import.rate.pdf_import` | 827 |
| POST | `/api/teacher/pdf-import/provider-keys/clear` | `PdfImportController::clearProviderKey` | `rate:pdf_import config:pdf_import.rate.pdf_import` | 842 |
| GET | `/api/teacher/pdf-import/provider-operations` | `PdfImportController::providerOperations` | — | 749 |
| POST | `/api/teacher/pdf-import/provider-operations` | `PdfImportController::saveProviderOperation` | `rate:pdf_import config:pdf_import.rate.pdf_import` | 830 |
| POST | `/api/teacher/pdf-import/provider-prompt` | `PdfImportController::saveProviderPrompt` | `rate:pdf_import config:pdf_import.rate.pdf_import` | 833 |
| POST | `/api/teacher/pdf-import/session` | `PdfImportController::createSession` | `rate:pdf_import_llm config:pdf_import.rate.pdf_import_llm` | 798 |
| GET | `/api/teacher/pdf-import/session/{id}` | `PdfImportController::status` | — | 738 |
| POST | `/api/teacher/pdf-import/session/{id}/bulk` | `PdfImportController::bulkEdit` | `rate:pdf_import config:pdf_import.rate.pdf_import` | 804 |
| POST | `/api/teacher/pdf-import/session/{id}/cell` | `PdfImportController::editCell` | `rate:pdf_import config:pdf_import.rate.pdf_import` | 801 |
| POST | `/api/teacher/pdf-import/session/{id}/difficulty` | `PdfImportController::refineDifficulty` | `rate:pdf_import_llm config:pdf_import.rate.pdf_import_llm` | 813 |
| POST | `/api/teacher/pdf-import/session/{id}/insert` | `PdfImportController::insert` | `rate:pdf_import config:pdf_import.rate.pdf_import` | 824 |
| GET | `/api/teacher/pdf-import/session/{id}/page/{n}` | `PdfImportController::pageImage` | — | 740 |
| GET | `/api/teacher/pdf-import/session/{id}/preview` | `PdfImportController::previewRow` | — | 742 |
| POST | `/api/teacher/pdf-import/session/{id}/solutions` | `PdfImportController::generateSolutions` | `rate:pdf_import_llm config:pdf_import.rate.pdf_import_llm` | 807 |
| POST | `/api/teacher/pdf-import/session/{id}/stop` | `PdfImportController::stopSession` | `rate:pdf_import config:pdf_import.rate.pdf_import` | 816 |
| POST | `/api/teacher/pdf-import/session/{id}/topics` | `PdfImportController::generateTopics` | `rate:pdf_import_llm config:pdf_import.rate.pdf_import_llm` | 810 |
| POST | `/api/teacher/pdf-import/session/{id}/translate` | `PdfImportController::translate` | `rate:pdf_import_llm 30` | 819 |
| GET | `/api/teacher/pdf-import/sessions` | `PdfImportController::listSessions` | — | 736 |
| POST | `/api/teacher/pdf-import/setting` | `PdfImportController::toggleSetting` | `rate:pdf_import config:pdf_import.rate.pdf_import` | 839 |
| GET | `/api/teacher/pool/materials` | `PoolController::materials` | — | 909 |
| GET | `/api/teacher/pool/my-shares` | `PoolController::myShares` | — | 912 |
| POST | `/api/teacher/pool/recover-verifica/{id}` | `PoolController::recoverVerifica` | `rate:pool_recover 30` | 949 |
| POST | `/api/teacher/pool/recover/{id}` | `PoolController::recover` | `rate:pool_recover 30` | 945 |
| POST | `/api/teacher/pool/unshare` | `PoolController::unshare` | — | 953 |
| GET | `/api/teacher/print-info` | `PrintInfoController::show` | — | 892 |
| POST | `/api/teacher/print-info` | `PrintInfoController::save` | — | 862 |
| POST | `/api/teacher/print-info/delete` | `PrintInfoController::delete` | — | 863 |
| GET | `/api/teacher/print-info/list` | `PrintInfoController::index` | — | 893 |
| GET | `/api/teacher/pubblicazioni/luoghi` | `TeacherPublicationsController::luoghi` | — | 1109 |
| POST | `/api/teacher/pubblicazioni/{id}/stato` | `TeacherPublicationsController::stato` | — | 1166 |
| POST | `/api/teacher/pubblicazioni/{id}/togli` | `TeacherPublicationsController::togli` | — | 1168 |
| POST | `/api/teacher/recovery-key/generate` | `TeacherRecoveryController::generate` | — | 932 |
| POST | `/api/teacher/recovery-key/revoke` | `TeacherRecoveryController::revoke` | — | 934 |
| GET | `/api/teacher/recovery-key/status` | `TeacherRecoveryController::status` | — | 886 |
| GET | `/api/teacher/risdoc/templates/files` | `TeacherTexCommonController::getFiles` | — | 1348 |
| POST | `/api/teacher/risdoc/templates/files/preview-pdf` | `TeacherTexCommonController::previewPdf` | — | 1225 |
| POST | `/api/teacher/risdoc/templates/files/save` | `TeacherTexCommonController::saveFiles` | — | 1222 |
| GET | `/api/teacher/share/colleagues` | `ShareGrantsController::listColleagues` | — | 919 |
| GET | `/api/teacher/share/grants/{source}/{id}` | `ShareGrantsController::listGrants` | — | 915 |
| POST | `/api/teacher/share/grants/{source}/{id}` | `ShareGrantsController::setGrants` | — | 956 |
| GET | `/api/teacher/share/groups` | `ShareGrantsController::listGroups` | — | 917 |
| POST | `/api/teacher/share/groups` | `ShareGrantsController::createGroup` | — | 958 |
| POST | `/api/teacher/share/groups/{id}/delete` | `ShareGrantsController::deleteGroup` | — | 962 |
| GET | `/api/teacher/share/groups/{id}/members` | `ShareGrantsController::listMembers` | — | 921 |
| POST | `/api/teacher/share/groups/{id}/members` | `ShareGrantsController::setMembers` | — | 960 |
| GET | `/api/teacher/sources.json` | `StudySourcesController::sourcesCommonJson` | — | 646 |
| PUT | `/api/teacher/sources.json` | `StudySourcesController::sourcesSave` | `csrf` | 686 |
| GET | `/api/teacher/sources.registry.json` | `StudySourcesController::sourcesRegistryJson` | — | 631 |
| PUT | `/api/teacher/sources.registry.json` | `StudySourcesController::sourcesRegistrySave` | `csrf` | 682 |
| GET | `/api/teacher/sync-bundle/manifest` | `VerificaSyncController::manifestSigned` | — | 884 |
| GET | `/api/teacher/sync-local-bundle` | `VerificaSyncController::localBundle` | — | 882 |
| POST | `/api/teacher/sync/cleanup-orphans` | `TeacherSyncCleanupController::cleanupOrphans` | — | 970 |
| GET | `/api/teacher/templates.json` | `ContentTemplateController::templatesJson` | — | 724 |
| PUT | `/api/teacher/templates.json` | `ContentTemplateController::templatesSave` | — | 755 |
| GET | `/api/teacher/verifica/files` | `TeacherVerificaFilesController::listFiles` | — | 1347 |
| POST | `/api/teacher/verifica/files/copy-from-base` | `TeacherVerificaFilesController::copyFromBase` | — | 1064 |
| POST | `/api/teacher/verifica/files/delete` | `TeacherVerificaFilesController::deleteFile` | — | 1062 |
| POST | `/api/teacher/verifica/files/preview-pdf` | `TeacherVerificaFilesController::previewPdf` | — | 1068 |
| GET | `/api/teacher/verifica/files/read` | `TeacherVerificaFilesController::readFile` | — | 1350 |
| POST | `/api/teacher/verifica/files/write` | `TeacherVerificaFilesController::writeFile` | — | 1060 |

## /api/tenant

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/tenant/current` | `TenantController::current` | — | 395 |
| POST | `/api/tenant/switch` | `TenantController::switch` | `csrf` | 393 |

## /api/tex

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/api/tex/compile-adhoc-pdf` | `TexAdhocCompileController::compileTikzPdf` | — | 794 |

## /api/verifica

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/api/verifica/batch/{batchId}/files` | `VerificaBatchController::batchFiles` | — | 880 |
| GET | `/api/verifica/batch/{batchId}/zip` | `VerificaBatchController::batchZip` | — | 877 |
| GET | `/api/verifica/jobs/{jobId}` | `VerificaCompileController::getJob` | — | 772 |
| GET | `/api/verifica/list` | `VerificaController::listForTeacher` | — | 870 |
| POST | `/api/verifica/save-tex` | `VerificaController::saveTex` | — | 762 |
| POST | `/api/verifica/save-tex-batch` | `VerificaController::saveTexBatch` | — | 763 |
| POST | `/api/verifica/sync-all` | `VerificaSyncController::syncAll` | `rate:content 30` | 1002 |
| POST | `/api/verifica/{id}/compile` | `VerificaCompileController::compilePdf` | — | 769 |
| POST | `/api/verifica/{id}/compile-async` | `VerificaCompileController::compileAsync` | — | 771 |
| POST | `/api/verifica/{id}/delete` | `VerificaController::delete` | — | 783 |
| POST | `/api/verifica/{id}/duplica` | `TeacherPublicationsController::duplicaVerifica` | `rate:content 60` | 790 |
| POST | `/api/verifica/{id}/geogebra-attach` | `VerificaController::geogebraAttach` | — | 776 |
| GET | `/api/verifica/{id}/pdf` | `VerificaController::viewPdf` | — | 872 |
| POST | `/api/verifica/{id}/pdf` | `VerificaController::uploadPdf` | — | 764 |
| GET | `/api/verifica/{id}/pubblicazioni` | `TeacherPublicationsController::elencoVerifica` | — | 875 |
| POST | `/api/verifica/{id}/pubblicazioni` | `TeacherPublicationsController::aggiungiVerifica` | — | 787 |
| POST | `/api/verifica/{id}/pubblicazioni/{pub}/stato` | `TeacherPublicationsController::statoVerifica` | — | 788 |
| POST | `/api/verifica/{id}/pubblicazioni/{pub}/togli` | `TeacherPublicationsController::togliVerifica` | — | 789 |
| POST | `/api/verifica/{id}/share-pool` | `VerificaController::sharePool` | — | 785 |
| POST | `/api/verifica/{id}/synctex/edit` | `VerificaCompileController::synctexEdit` | — | 782 |
| GET | `/api/verifica/{id}/tex` | `VerificaController::downloadTex` | — | 871 |
| POST | `/api/verifica/{id}/tex` | `VerificaController::updateTex` | — | 774 |
| GET | `/api/verifica/{id}/tex-files` | `VerificaController::getTexFiles` | — | 778 |
| POST | `/api/verifica/{id}/tex-files` | `VerificaController::updateTexFiles` | — | 779 |
| GET | `/api/verifica/{id}/zip` | `VerificaController::zipExport` | — | 873 |

## /area-docente

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/area-docente` | `(closure)` | — | 1328 |
| GET | `/area-docente/categorie` | `TeacherProfileController::categoriePage` | — | 1335 |
| GET | `/area-docente/da-categorizzare` | `TeacherUncategorizedController::index` | — | 1338 |
| POST | `/area-docente/da-categorizzare` | `TeacherUncategorizedController::save` | `csrf` | 1339 |
| GET | `/area-docente/dashboard` | `TeacherController::dashboard` | — | 1329 |
| GET | `/area-docente/fonti` | `TeacherProfileController::fontiPage` | — | 1346 |
| GET | `/area-docente/materie` | `TeacherSubjectsController::form` | — | 695 |
| POST | `/area-docente/materie` | `TeacherSubjectsController::save` | `csrf` | 696 |
| GET | `/area-docente/pdf-import` | `PdfImportPageController::page` | — | 1331 |
| GET | `/area-docente/pdf-import/models` | `PdfImportPageController::modelsPage` | — | 1332 |
| GET | `/area-docente/profilo` | `TeacherProfileController::page` | — | 1333 |
| GET | `/area-docente/resources` | `TeacherController::resources` | — | 1330 |
| GET | `/area-docente/sposta-di-classe` | `TeacherMoveController::index` | — | 1343 |
| POST | `/area-docente/sposta-di-classe` | `TeacherMoveController::save` | `csrf` | 1344 |
| GET | `/area-docente/templates` | `TeacherProfileController::templatesPage` | — | 1334 |

## /auth

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/auth/cie/callback` | `CieController::callback` | — | 78 |
| GET | `/auth/cie/login` | `CieController::login` | — | 77 |
| GET | `/auth/cie/logout` | `CieController::logout` | — | 80 |
| GET | `/auth/cie/metadata` | `CieController::metadata` | — | 79 |
| GET | `/auth/csrf` | `AuthController::csrf` | — | 68 |
| GET | `/auth/grafana-gate` | `GrafanaGateController::gate` | — | 345 |
| GET | `/auth/spid/callback` | `SpidController::callback` | — | 74 |
| GET | `/auth/spid/login` | `SpidController::login` | — | 73 |
| GET | `/auth/spid/logout` | `SpidController::logout` | — | 76 |
| GET | `/auth/spid/metadata` | `SpidController::metadata` | — | 75 |
| GET | `/auth/user-info` | `AuthController::userInfo` | — | 67 |

## /check

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/check/password` | `CheckController::password` | — | 1560 |

## /cookies_privacy-policy.html

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/cookies_privacy-policy.html` | `(closure)` | — | 450 |

## /curriculum

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/curriculum` | `CurriculumController::index` | `rate:curriculum 180` | 413 |

## /delete_temp.php

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/delete_temp.php` | `CronController::deleteTemp` | — | 1566 |

## /didattica

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/didattica/{path*}` | `(?)` | `legacy_gone` | 559 |

## /dpo-contact

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/dpo-contact` | `DpoContactController::show` | — | 142 |
| POST | `/dpo-contact` | `DpoContactController::submit` | `csrf rate:dpo 3 3600` | 143 |

## /drafts

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/drafts/{path*}` | `(?)` | `legacy_gone` | 1370 |

## /eser

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/eser/{path*}` | `(?)` | `legacy_gone` | 558 |

## /exercises

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/exercises` | `ExerciseController::searchPage` | — | 896 |
| GET | `/exercises/search.json` | `ExerciseController::searchJson` | — | 897 |

## /favicon.ico

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/favicon.ico` | `(closure)` | — | 18 |

## /files

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/files/clear-temp` | `FileController::clearTemp` | — | 1405 |
| POST | `/files/delete` | `FileController::deleteFile` | `audit_reason:webroot_file_delete webroot_files` | 1393 |
| POST | `/files/delete-folder` | `FileController::deleteFolder` | `audit_reason:webroot_folder_delete webroot_files` | 1395 |
| GET | `/files/list` | `FileController::list` | — | 1407 |
| POST | `/files/save-latex` | `FileController::saveLatex` | — | 1392 |
| POST | `/files/save-pdf` | `FileController::savePdf` | `audit_reason:verifica_pdf_save webroot_files` | 1533 |

## /geogebra

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/geogebra/catalog` | `GeoGebraCatalogController::list` | — | 591 |
| POST | `/geogebra/catalog/delete` | `GeoGebraCatalogController::delete` | — | 595 |
| POST | `/geogebra/catalog/save` | `GeoGebraCatalogController::save` | — | 594 |
| GET | `/geogebra/catalog/{id}` | `GeoGebraCatalogController::get` | — | 592 |

## /health

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/health` | `HealthController::health` | — | 41 |
| GET | `/health/backup` | `HealthController::backupFreshness` | — | 42 |
| GET | `/health/tex` | `HealthController::tex` | — | 45 |

## /istituto

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/istituto` | `IstitutoController::page` | — | 1359 |

## /lab

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/lab/{path*}` | `(?)` | `legacy_gone` | 560 |

## /legal

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/legal/ai-act` | `TrustPagesController::aiAct` | — | 385 |
| GET | `/legal/ai-literacy` | `TrustPagesController::aiLiteracy` | — | 386 |
| GET | `/legal/aup` | `TrustPagesController::aup` | — | 381 |
| GET | `/legal/dpa` | `TrustPagesController::dpa` | — | 383 |
| GET | `/legal/takedown-procedure` | `TrustPagesController::takedownProcedure` | — | 382 |
| GET | `/legal/tos` | `TrustPagesController::tos` | — | 380 |

## /login

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/login` | `AuthController::showLogin` | — | 46 |
| POST | `/login` | `AuthController::login` | `csrf rate:login 10` | 52 |
| GET | `/login/2fa` | `AuthController::show2fa` | — | 56 |
| POST | `/login/2fa` | `AuthController::verify2fa` | `csrf rate:login 10` | 57 |

## /logout

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/logout` | `AuthController::logout` | — | 58 |

## /mappe

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/mappe/{path*}` | `(?)` | `legacy_gone` | 471 |

## /me

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/me/2fa` | `TotpController::page` | — | 111 |
| POST | `/me/2fa/disable` | `TotpController::disable` | `auth csrf` | 117 |
| POST | `/me/2fa/enable` | `TotpController::enable` | `csrf` | 113 |
| POST | `/me/2fa/enable-email` | `TotpController::enableEmail` | `csrf` | 115 |
| POST | `/me/2fa/setup` | `TotpController::setup` | `csrf` | 112 |
| POST | `/me/2fa/setup-email` | `TotpController::setupEmail` | `csrf` | 114 |
| GET | `/me/account` | `AccountController::page` | `auth` | 98 |
| POST | `/me/account/email` | `AccountController::email` | `auth csrf rate:login 10` | 99 |
| GET | `/me/account/email/conferma` | `AccountController::confermaPagina` | — | 100 |
| POST | `/me/account/email/conferma` | `AccountController::conferma` | `csrf rate:login 10` | 101 |
| POST | `/me/cancel-deletion` | `SelfServiceController::cancelDeletion` | `auth csrf` | 129 |
| GET | `/me/change-password` | `UserProfileController::showChangePassword` | — | 104 |
| POST | `/me/change-password` | `UserProfileController::changePassword` | `csrf` | 105 |
| GET | `/me/confirm-deletion` | `SelfServiceController::confirmDeletion` | — | 127 |
| POST | `/me/confirm-deletion` | `SelfServiceController::confirmDeletionSubmit` | `csrf rate:deletion 5` | 128 |
| GET | `/me/consents` | `SelfServiceController::consentsList` | `auth` | 120 |
| POST | `/me/consents/grant` | `SelfServiceController::consentGrant` | `auth csrf` | 123 |
| POST | `/me/consents/revoke` | `SelfServiceController::consentRevoke` | `auth csrf` | 124 |
| GET | `/me/custody-events` | `SelfServiceController::custodyEvents` | `auth` | 122 |
| GET | `/me/deletion-status` | `SelfServiceController::deletionStatus` | `auth` | 130 |
| GET | `/me/export-data` | `SelfServiceController::exportData` | `auth rate:export 3` | 132 |
| POST | `/me/profile` | `SelfServiceController::profilePatch` | `auth csrf` | 133 |
| POST | `/me/request-deletion` | `SelfServiceController::requestDeletion` | `auth csrf rate:deletion 5` | 126 |

## /metrics

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/metrics` | `MetricsController::show` | — | 399 |

## /modelli_tikz_elements.json

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/modelli_tikz_elements.json` | `TikzDataController::show` | — | 1373 |

## /modelli_tikz_traccia.json

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/modelli_tikz_traccia.json` | `TikzDataController::show` | — | 1374 |

## /parent-consent

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/parent-consent/{token}` | `ParentConsentController::preview` | — | 137 |
| POST | `/parent-consent/{token}` | `ParentConsentController::confirm` | — | 138 |

## /password

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/password/forgot` | `PasswordResetController::showForgot` | — | 63 |
| POST | `/password/forgot` | `PasswordResetController::submitForgot` | `csrf rate:login 5` | 64 |
| GET | `/password/reset` | `PasswordResetController::showReset` | — | 65 |
| POST | `/password/reset` | `PasswordResetController::submitReset` | `csrf rate:login 10` | 66 |

## /privacy

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/privacy/informativa` | `TrustPagesController::informativa` | — | 357 |
| GET | `/privacy/your-data` | `TrustPagesController::yourData` | — | 356 |

## /public

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/public/sidebar/{key}` | `PublicSidebarController::section` | `rate:pub_sidebar 120` | 361 |
| GET | `/public/studio/{id}` | `PublicStudyController::publicView` | `rate:pub_view 120` | 367 |

## /register

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/register` | `RegistrationController::showForm` | — | 409 |
| POST | `/register` | `RegistrationController::submit` | `csrf` | 410 |

## /risdoc

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/risdoc/edit/{id}` | `TemplateEditorController::show` | — | 1273 |
| GET | `/risdoc/view/{id}` | `TemplateViewController::show` | — | 1271 |
| GET | `/risdoc/{category}/php/{filename}` | `TemplateViewController::showByLegacyPath` | — | 1277 |
| ANY | `/risdoc/{path*}` | `(?)` | `legacy_gone` | 1368 |
| GET | `/risdoc/{path*}` | `TemplateController::legacyPath` | — | 1320 |

## /security

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/security` | `TrustPagesController::security` | — | 355 |

## /segnalazione-contenuti

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/segnalazione-contenuti` | `PublicTakedownController::showForm` | — | 148 |
| POST | `/segnalazione-contenuti` | `PublicTakedownController::submit` | `rate:takedown 3 3600` | 149 |

## /storage

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/storage/signed` | `StorageController::signed` | — | 424 |

## /strcomp_bes_altro

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| ANY | `/strcomp_bes_altro/{path*}` | `(?)` | `legacy_gone` | 1369 |

## /studio

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/studio/{indirizzo}/{classe}/{materia}` | `ExerciseStudyController::topicsPage` | — | 617 |
| GET | `/studio/{indirizzo}/{classe}/{materia}/{topic}` | `ExerciseStudyController::topicPage` | — | 619 |
| GET | `/studio/{type}/{ind}/{cls}/{subj}` | `ContentStudyController::topicsPage` | — | 497 |
| GET | `/studio/{type}/{ind}/{cls}/{subj}/{topic}` | `ContentStudyController::topicPage` | — | 499 |

## /teacher

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/teacher` | `(closure)` | — | 706 |
| GET | `/teacher/dashboard` | `(closure)` | — | 707 |
| GET | `/teacher/drive/callback` | `DriveController::callback` | — | 905 |
| GET | `/teacher/drive/connect` | `DriveController::connect` | — | 903 |
| GET | `/teacher/drive/connect-migration` | `DriveController::connectMigration` | — | 904 |
| POST | `/teacher/drive/disconnect` | `DriveController::disconnect` | — | 981 |
| GET | `/teacher/drive/status.json` | `DriveController::status` | — | 906 |
| GET | `/teacher/pdf-import` | `(closure)` | — | 732 |
| GET | `/teacher/pdf-import/models` | `(closure)` | — | 734 |
| POST | `/teacher/print` | `TeacherPrintController::generate` | — | 757 |
| GET | `/teacher/resources` | `(closure)` | — | 715 |
| GET | `/teacher/templates` | `(closure)` | — | 721 |

## /tex

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/tex/format` | `TexFormatController::format` | — | 565 |

## /tikz

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/tikz/admin-library` | `TeacherWorkspaceController::getAdminLibrary` | — | 603 |
| POST | `/tikz/delete-element` | `TikzController::deleteElement` | `audit_reason:tikz_template_delete tikz_templates` | 1551 |
| POST | `/tikz/delete-svg` | `TikzController::deleteSvg` | `audit_reason:tikz_svg_delete tikz_svg` | 1418 |
| POST | `/tikz/edit-element` | `TikzController::editElement` | `audit_reason:tikz_template_edit tikz_templates` | 1549 |
| GET | `/tikz/effective-templates` | `TeacherTemplateController::effective` | — | 571 |
| GET | `/tikz/render` | `TikzRenderController::lookup` | — | 527 |
| POST | `/tikz/render` | `TikzRenderController::render` | — | 548 |
| POST | `/tikz/save-new-element` | `TikzController::saveNewElement` | `audit_reason:tikz_template_create tikz_templates` | 1547 |
| POST | `/tikz/save-svg` | `TikzController::saveSvg` | `audit_reason:tikz_svg_save tikz_svg` | 1416 |
| POST | `/tikz/teacher-templates/reset` | `TeacherTemplateController::reset` | — | 574 |
| POST | `/tikz/teacher-templates/save` | `TeacherTemplateController::save` | — | 573 |
| GET | `/tikz/workspace` | `TeacherWorkspaceController::getWorkspace` | — | 602 |
| POST | `/tikz/workspace/element/delete` | `TeacherWorkspaceController::deleteElement` | — | 606 |
| POST | `/tikz/workspace/element/save` | `TeacherWorkspaceController::saveElement` | — | 605 |
| POST | `/tikz/workspace/group/delete` | `TeacherWorkspaceController::deleteGroup` | — | 608 |
| POST | `/tikz/workspace/group/rename` | `TeacherWorkspaceController::renameGroup` | — | 607 |
| POST | `/tikz/workspace/group/reorder` | `TeacherWorkspaceController::reorderGroups` | — | 609 |
| POST | `/tikz/workspace/import` | `TeacherWorkspaceController::importFromAdmin` | — | 611 |
| POST | `/tikz/workspace/reset-all` | `TeacherWorkspaceController::resetAll` | — | 610 |

## /tos-acceptance

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/tos-acceptance` | `TosAcceptanceController::show` | `auth` | 349 |
| POST | `/tos-acceptance` | `TosAcceptanceController::submit` | `auth csrf` | 351 |

## /verifiche

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/verifiche/print-info` | `VerificheController::managePrintInfo` | — | 866 |
| POST | `/verifiche/scelte` | `VerificheController::saveLoadScelte` | — | 867 |
| ANY | `/verifiche/{path*}` | `(?)` | `legacy_gone` | 1365 |

## /version

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| GET | `/version` | `HealthController::version` | — | 40 |

## /waf

| Metodo | Path | Handler | Mw (route-local) | L# |
|--------|------|---------|------------------|----|
| POST | `/waf/fingerprint` | `WafApiController::collect` | `rate:waf_fp 40` | 1689 |

