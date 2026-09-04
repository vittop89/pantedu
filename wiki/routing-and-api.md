---
tags:
  - documentazione/api
date: 2026-04-23
tipo: api
status: finale
aliases: ["routing", "api", "routes"]
cssclasses: []
---

# Routing & API

## Tabella rapida

| Method | Path | Controller::method | Auth |
|--------|------|-------------------|------|
| GET | `/` | `HomeController::index` | public |
| GET | `/login` | `AuthController::showLogin` | public |
| POST | `/login` | `AuthController::login` | public + csrf |
| ANY | `/logout` | `AuthController::logout` | public |
| GET | `/auth/user-info` | `AuthController::userInfo` | public |
| GET | `/auth/csrf` | `AuthController::csrf` | public |
| GET | `/register` | `RegistrationController::showForm` | public |
| POST | `/register` | `RegistrationController::submit` | public + csrf |
| GET | `/curriculum` | `CurriculumController::index` | public |
| GET | `/api/institutes` | `InstituteController::index` | public |
| GET | `/api/scuole` | `SchoolsController::search` | public |
| GET | `/storage/signed` | `StorageController::signed` | public (HMAC) |
| GET | `/api/sidepage/topics` | `SidepageController::topics` | public (ACL in controller) |
| POST | `/api/access/student-login` | `TeacherCredentialController::studentLogin` | public + csrf + rate |
| GET | `/me/change-password` | `UserProfileController::showChangePassword` | auth |
| POST | `/me/change-password` | `UserProfileController::changePassword` | auth + csrf |
| GET | `/teacher` | `TeacherController::dashboard` | auth + teacher+ |
| GET | `/exercises` | `ExerciseController::searchPage` | auth + teacher+ |
| GET | `/exercises/search.json` | `ExerciseController::searchJson` | auth + teacher+ |
| GET | `/teacher/drive/connect` | `DriveController::connect` | auth + teacher+ (G1.a) |
| GET | `/teacher/drive/connect-migration` | `DriveController::connectMigration` | auth + teacher+ (G6, scope drive.readonly UNA TANTUM) |
| GET | `/teacher/drive/callback` | `DriveController::callback` | auth + teacher+ (state nonce) |
| GET | `/teacher/drive/status.json` | `DriveController::status` | auth + teacher+ |
| POST | `/teacher/drive/disconnect` | `DriveController::disconnect` | auth + teacher+ + csrf + rate |
| POST | `/api/maps` | `MapsController::create` | auth + teacher+ + csrf + rate (G3.b) |
| GET | `/api/maps/{id}/signed-url` | `MapsController::signedUrl` | auth + teacher+ (G4) |
| POST | `/api/maps/{id}/update` | `MapsController::update` | auth + teacher+ + csrf + rate (G4, owner only) |
| GET | `/api/maps/dl` | `MapsController::download` | public (HMAC signed URL = auth) (G4) |
| POST | `/api/maps/{id}/sync` | `MapsController::sync` | auth + teacher+ + csrf + rate (G5, owner only) |
| POST | `/api/maps/sync-all` | `MapsController::syncAll` | auth + teacher+ + csrf + rate:30 (G5) |
| GET | `/api/risdoc/templates` | `Risdoc\TemplateController::index` | auth + teacher+ (`?with_body_pt=1` opt-in PT seed) |
| GET | `/api/risdoc/templates/{id}` | `Risdoc\TemplateController::show` | auth + teacher+ |
| GET | `/api/risdoc/templates/{id}/schema` | `Risdoc\TemplateController::schema` | auth + teacher+ (resolver 3-layer) |
| GET | `/risdoc/view/{id}` | `Risdoc\TemplateViewController::show` | auth + teacher+ (`?instance=KEY` istanza fork; `?admin_edit=1` admin schema editor) |
| GET | `/risdoc/edit/{id}` | `Risdoc\TemplateEditorController::show` | auth + teacher+ |
| POST | `/api/risdoc/templates/{id}/compilations` | `Risdoc\CompilationController::save` | auth + teacher+ + csrf + rate |
| POST | `/api/risdoc/templates/{id}/export` | `Risdoc\ExportController::export` | auth + teacher+ + csrf + rate |
| GET | `/api/risdoc/exports/{file}` | `Risdoc\ExportController::serve` | auth + teacher+ |
| **Phase 24.50** POST | `/api/risdoc/templates/{id}/body-pt` | `Risdoc\TemplateController::saveBodyPt` | super-admin + csrf |
| **Phase 24.55** POST | `/api/risdoc/templates/{id}/institutional-override[/del]` | `Risdoc\TemplateController::institutionalOverride*` | super-admin + csrf |
| **Phase 24.58** GET | `/api/risdoc/templates/{id}/instances` | `Risdoc\TemplateController::instancesList` | auth + teacher+ |
| **Phase 24.58** GET | `/api/risdoc/teacher/instances` | `Risdoc\TemplateController::teacherAllInstances` | auth + teacher+ (cross-template) |
| **Phase 24.58** POST | `/api/risdoc/templates/{id}/instances` | `Risdoc\TemplateController::instancesCreate` | auth + teacher+ + csrf |
| **Phase 24.58** POST | `/api/risdoc/templates/{id}/instances/{key}/delete` | `Risdoc\TemplateController::instancesDelete` | auth + teacher+ + csrf |
| **Phase 24.58** POST | `/api/risdoc/templates/{id}/instances/{key}/rename` | `Risdoc\TemplateController::instancesRename` | auth + teacher+ + csrf |
| GET | `/admin` | `AdminController::dashboard` | auth + admin |
| GET | `/admin/risdoc` | `Admin\RisdocAdminController::page` | auth + admin |
| POST | `/admin/print` | `AdminPrintController::generate` | auth + admin + csrf + rate |
| GET | `/api/admin/users` | `UsersAdminController::index` | auth + admin |
| POST | `/api/admin/users/{id}/role` | `UsersAdminController::setRole` | auth + admin + csrf + rate |
| GET | `/admin/infrastructure` | `AdminInfrastructureController::page` | auth + admin (super-admin check interno) |
| POST | `/files/save-tex` | `FileController::saveTex` | auth + admin + csrf + rate |
| POST | `/tikz/save-svg` | `TikzController::saveSvg` | auth + admin + csrf + rate |

## Route principali — dettaglio

### POST /login
**Controller**: `AuthController::login` — `app/Controllers/AuthController.php`
**Middleware**: `csrf`
**Input**: `{ username: string, password: string, indirizzo?: string, classe?: string }`
**Output**: redirect `/teacher` | `/` | JSON error
**Errori**: 401 invalid_credentials | 423 rate_limited | 403 unauthorized_section

### GET /auth/csrf
**Controller**: `AuthController::csrf`
**Input**: —
**Output**: `{ token: string }`
**Note**: Emette CSRF token via `Csrf::token()`. TTL configurabile `CSRF_TOKEN_LIFETIME`.

### GET /curriculum
**Controller**: `CurriculumController::index`
**Input**: —
**Output**: `{ indirizzi: [...], classi: [...], materie: [...] }`
**Note**: Fonte `storage/data/curriculum.json`. Usato da tutte le pagine per populare i select.

### POST /api/access/student-login
**Controller**: `TeacherCredentialController::studentLogin`
**Middleware**: `csrf`, `rate`
**Input**: `{ username: string, password: string }` (credenziali studente fornite dal docente)
**Output**: `{ ok: true, grant: { teacher_id, class } }` | errori
**Note**: Studente non ha account; il docente crea credenziali temporanee per accesso a contenuti.

### GET /api/risdoc/templates
**Controller**: `Risdoc\TemplateController::index`
**Middleware**: `auth`, `role:teacher`, `log`
**Input**: `?origin=string&category=string` (opzionali)
**Output**: `{ ok: true, templates: [ {id, code, origin, category, argomento, ...} ] }`
**Errori**: 401, 403

### POST /api/risdoc/templates/{id}/compilations
**Controller**: `Risdoc\CompilationController::save` — `app/Controllers/Risdoc/CompilationController.php`
**Middleware**: `auth`, `role:teacher`, `csrf`, `rate`
**Input**: `{ compilation_key: string, label: string, data: JSON-string, classe?: string, sezione?: string, indirizzo?: string, disciplina?: string }`
**Output**: `{ ok: true, id: int }`
**Errori**: 400 compilation_key_required | 400 invalid_json | 401 | 403 forbidden | 413 payload_too_large (>2MB)

### POST /api/risdoc/templates/{id}/export
**Controller**: `Risdoc\ExportController::export` — `app/Controllers/Risdoc/ExportController.php`
**Middleware**: `auth`, `role:teacher`, `csrf`, `rate`
**Input**: `{ form_state: JSON-string, mode: 'zip'|'overleaf' }`
**Output (zip)**: `{ ok: true, mode: 'zip', url: string, expires: timestamp }`
**Output (overleaf)**: `{ ok: true, mode: 'overleaf', url: string, overleaf_url: string }`
**Errori**: 400 tex_not_available | 403 forbidden | 404 template_not_found | 500 zip_open_failed
**Side effect**: scrive ZIP in `storage/risdoc-tmp/doc-{16hex}.zip`, pulizia auto dopo 1h.

### POST /api/teacher/content/{id}/quesito/{itemRef}/patch
**Controller**: `TeacherContentController::quesitoPatch`
**Middleware**: `auth`, `role:teacher`, `csrf`, `rate`
**Input**: corpo patch (testo, opzioni, metadati item); header opzionale `If-Match: "vN"` per optimistic locking
**Output**: `{ ok: true, version: int }`
**Errori**: 409 version_conflict | 404 item_not_found

### GET /files/list
**Controller**: `FileController::list`
**Middleware**: `auth`, `role:admin`
**Input**: `?path=string` (path relativo a root)
**Output**: `{ files: [...] }`
**Note**: SafePath validation previene path traversal.

### POST /admin/print
**Controller**: `AdminPrintController::generate`
**Middleware**: `auth`, `role:admin`, `csrf`, `rate`
**Input**: `{ tex: string, engine: 'pdflatex'|'xelatex' }`
**Output**: PDF binary o JSON errore
**Note**: Esecuzione sincrona pdflatex; timeout hardcoded nel service.

## Middleware — alias e implementazioni

| Alias | Classe | Funzione |
|-------|--------|---------|
| `auth` | `AuthMiddleware` | `Auth::check()` → 401/redirect login |
| `role` | `RoleMiddleware` | `Auth::hasRole($roles)` → 403 |
| `csrf` | `CsrfMiddleware` | `Csrf::verify($_POST['_csrf'])` → 403 |
| `rate` | `RateLimitMiddleware` | Sliding window su `RateLimitStore` (file PHP) |
| `log` | `AccessLogMiddleware` | Scrive su `storage/logs/access_log.json` |
| `legacy_gone` | `LegacyGoneMiddleware` | Emette 410 + redirect smart |
| `sadmin_audit` | `SuperAdminAuditMiddleware` | Audit trail per super-admin |

## Route legacy (LegacyController::serve)

`LegacyController` mappa `{path*}` → file system (static serve). Usato per:
- `/js/*`, `/css/*`, `/img/*` — asset statici
- `/functions.js`, `/script.js`, `/script_sel-mod.js` — JS legacy
- `/modelli_tikz.json`, `/modelli_tikz_elements.json` — config JSON
- `/risdoc/{path*}` — catch-all template legacy (superato da Plan B routes specifiche)

## Route Gone (legacy_gone middleware)

Route deprecate emettono 410 + redirect al moderno equivalente:
- `/mappe/*` → rimosso
- `/verifiche/*` → `/studio/verifica/...`
- `/risdoc/*` (collaborator group) → Plan B editor
