---
tags:
  - documentazione/domain-map
date: 2026-09-04
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
|---------|--------------------|--------------|-----------|
| **core** | `app/Core/`, `app/Middleware/`, `app/Config/`, `app/Support/` | Router, Kernel, pipeline, Auth, CSRF, Session, Config, Database, Migrator, View, scenari (`DeploymentScenario`), lookup curriculum | — |
| **auth** | `app/Controllers/AuthController.php`, `TotpController.php`, `PasswordResetController.php`, `RegistrationController.php`, `TeacherCredentialController.php`, `Auth/Spid*|Cie*`, `app/Services/Security/`, `app/Services/Registration*` | login in due passaggi, 2FA app/email, recupero password, registrazione per scenario, credenziale di classe, SPID/CIE (scaffold) | core |
| **contenuti ed esercizi** | `app/Controllers/{ContentStudy,ExerciseStudy,TeacherContent,Quesito,Group,ContentPublish,ContentExport,ContentTemplate,StudyHeader,StudySources,TeacherUncategorized,TeacherCategoryLabel}Controller.php`, `app/Services/Contract/`, `ContractRenderer.php`, `app/Domain/ContentVisibilityPolicy.php`, `app/Repositories/TeacherContentRepository.php`, `js/modules/editor/`, `js/modules/features/checkin-handlers.js` | contract JSON, studio, editor inline, pubblicazione e visibilità, categorie, export | core, auth, curriculum, crypto |
| **verifiche** | `app/Controllers/Verifica*Controller.php`, `TeacherVerificaFilesController.php`, `app/Services/Verifica/`, `app/Services/TexBuilder.php` + `TexBuilder/`, `app/Services/TexCompile/`, `js/modules/features/topbar-modern.js`, `js/entries/verifica-preview-editor.js` | documenti verifica cifrati, build TeX multi-file, compile sul microservizio, batch, sync Drive/locale | contenuti, crypto, tex |
| **risdoc** | `app/Controllers/Risdoc/`, `Admin/RisdocAdminController.php`, `app/Services/Risdoc/` (+ `Pt/`), `js/components/risdoc/`, `js/components/pt-document/`, `js/modules/risdoc/`, `schemas/risdoc/`, `storage/templates/risdoc/` | modelli documentali: schema, editor PT, compilazioni cifrate, override, valori per terna, formule, export TeX | core, auth, crypto, tex |
| **mappe** | `app/Controllers/MapsController.php`, `DriveController.php`, `app/Services/Maps/`, `app/Services/Drive/`, `js/modules/features/drawio-editor.js`, `drive-sync-buttons.js` | mappe drawio cifrate, signed URL, condivisioni, sync Drive | core, crypto |
| **curriculum e sezioni** | `app/Services/CurriculumService.php`, `MiurAdozioniImporter.php`, `MiurSchoolsService.php`, `TeacherSectionService.php`, `TeacherSubjectService.php`, `app/Support/CurriculumLookup.php`, `MiurCurriculumAlias.php`, `Admin/AdminSectionsController.php`, `Admin/AdminInstitutesController.php` | indirizzi/classi/materie dal dataset MIUR, vocabolario per scuola, incarichi dei docenti, classe degli studenti | core |
| **crypto** | `app/Services/Crypto/`, `app/Support/Storage/` | chiave master → KEK per docente, blob cifrati, chiavi di classe, recovery key, custodia, Shamir | core |
| **gdpr e legale** | `app/Services/Gdpr/` (+ `Export/`), `SelfServiceController.php`, `ParentConsentController.php`, `DpoContactController.php`, `Public/PublicTakedownController.php`, `TosAcceptanceController.php`, `Admin/AdminGdprController.php`, `docs/legal/versions.json` | consensi, cancellazione, export art. 15, minori, takedown, ToS/AUP versionati, registro breach, subprocessor | core, crypto |
| **audit** | `app/Services/Audit/`, `app/Core/PrivilegedAccessLogger.php`, `AccessLogger.php`, `tools/audit/` | registri append-only, hash IP/UA, catena di impronte | core |
| **waf** | `app/Middleware/WafMiddleware.php`, `app/Services/Waf/`, `Admin/WafAdminController.php`, `js/waf/fingerprint.js` | filtro applicativo globale, threat-intel, brute-force, pannello | core |
| **pdf-import** | `app/Controllers/Teacher/PdfImport*Controller.php`, `app/Services/PdfImport/`, `js/entries/pdf-import*.js` | estrazione esercizi da PDF via LLM (opt-in), marcatura AI Act | contenuti, crypto |
| **admin** | `app/Controllers/Admin/`, `AdminController.php`, `AdminToolsController.php`, `UsersAdminController.php`, `SecurityAdminController.php`, `views/admin/` | strumenti, utenti, istituti, sezioni, GDPR, WAF, backup, log, monitoraggio, scenari | tutti |
| **frontend** | `js/modules/`, `js/components/`, `js/entries/`, `js/fm-router.js`, `views/`, `css/` | UI, sidebar data-driven, modali lazy, componenti Lit, CSS a layer | tutti (consumer) |

Trasversali: `app/Services/TexCompile/` (client del microservizio TeX),
`app/Services/Tikz/`, `GeoGebra/`, `Shortcuts/`, `GitHub/`, `Sharing/`.

## Accoppiamenti anomali

| Coppia | Descrizione | Rischio |
|--------|-------------|---------|
| Controller ↔ SQL | 46 controller e oltre 40 servizi usano PDO direttamente; due viste eseguono query | Alto ([[technical-debt]] voce 17) |
| `ContentStudyController` ↔ rendering | il controller studio compone HTML in cinque metodi e ospita le regole di visibilità pubblica | Medio (voce 18) |
| Viste ↔ JavaScript inline | 41 viste con script inline che duplicano helper dei moduli | Medio (voce 19) |
| `TeacherContentRepository` ↔ crypto | il repository cifra e decifra da solo (`crypto()`) | Medio (audit 2026-05-09, PROBLEM-4) |
| Frontend ↔ CDN | Lit, pdf.js, pako, MathJax caricati a runtime da terzi | Alto (voce 16) |
| `google-apps*.js` ↔ path legacy | legge i pattern di path del filesystem legacy da `core/config.js` | Basso (voce 8) |

## Test coverage per dominio

| Dominio | Unit | Integration | E2E | Note |
|---------|------|------------|-----|------|
| core | alta | media | — | Router, Auth, Migrator, Response, Telemetry |
| auth | alta | — | media | 2FA (`TwoFactorPolicyTest`, `EmailSecondFactorTest`, `QrCodeTest`), reset password, registrazione; E2E login/registration |
| contenuti | media | media | alta (ma ferma a maggio) | Contract, ContentVisibilityPolicy, PublishScope, StudentSectionFilter |
| verifiche | media | media | alta (stale) | `VerificaDocumentService*`, `TexBuilderTest`, print controller |
| risdoc | media | — | alta (stale) | `Pt/*` (i nove casi `TEST_ROT` decisi il 2026-09-04), scrubber, storage policy |
| mappe | media | — | media | MapBlobStore, MapShareRepository |
| curriculum e sezioni | media | alta | — | CurriculumService, IndirizzoCodeDeriver, MiurAdozioniImporter, TeacherSectionService, InstituteRepository |
| crypto | alta | alta | — | TeacherCryptoService, Shamir, KEK rotation, full flow |
| gdpr | alta | media | media | export, consensi, ToS, takedown, parent consent |
| audit | alta | — | — | ActivityLogger, AuditChain |
| waf | bassa | — | media | solo `WafMiddlewareJsonResponseTest`; E2E su WAF admin |
| pdf-import | media | — | — | provenance, mapper, PII masker, prompt guard, SSRF |
| admin | bassa | media | media (stale) | AdminPrint, Analytics, LayoutModes |
| frontend | bassa | — | alta (stale) | 6 file Vitest (editor, sidebar cascade, audit reason) |

## Entrypoint per dominio

| Dominio | Entrypoint | File |
|---------|-----------|------|
| core | tutti | `public/index.php` → `app/Core/Kernel.php` |
| auth | `/login`, `/login/2fa`, `/logout`, `/password/*`, `/register`, `/accesso-classe`, `/me/2fa/*` | `AuthController`, `TotpController`, `PasswordResetController`, `RegistrationController` |
| contenuti | `/studio/{type}/...`, `/api/study/*`, `/api/teacher/content*` | `ContentStudyController`, `TeacherContentController` |
| verifiche | `/api/verifica/*`, `/api/teacher/verifica/files*` | `VerificaController`, `VerificaCompileController` |
| risdoc | `/risdoc/view|edit/{id}`, `/api/risdoc/*` | `Risdoc\TemplateController`, `TemplateViewController` |
| mappe | `/api/maps/*`, `/teacher/drive/*` | `MapsController`, `DriveController` |
| curriculum | `/curriculum`, `/api/teacher/curriculum*`, `/admin/sections`, `/admin/institutes/*` | `CurriculumController`, `AdminSectionsController`, `AdminInstitutesController` |
| gdpr | `/me/*`, `/parent-consent/{token}`, `/dpo-contact`, `/segnalazione-contenuti`, `/admin/gdpr/*` | `SelfServiceController`, `AdminGdprController` |
| admin | `/admin`, `/admin/dashboard`, `/admin/*` | `AdminToolsController`, `AdminController`, `app/Controllers/Admin/*` |
| waf | ogni richiesta; `/admin/waf/*`; `/waf/fingerprint` | `WafMiddleware`, `WafAdminController`, `WafApiController` |
| frontend | asset + Vite | `views/partials/head.php`, `js/modules/bootstrap.js`, `public/build/` |

## Domini prioritari per deep wiki

1. **contenuti ed esercizi** — il dominio più esteso e più accoppiato (studio, contract, visibilità)
2. **risdoc** — pipeline TeX critica, editor PT, test sospesi
3. **auth** — 2FA e credenziale di classe sono nuove (settembre 2026)
4. **curriculum e sezioni** — nato in settembre, nessuna pagina dedicata oltre a questa mappa
5. **admin** — strumento operativo quotidiano, viste con più script inline
