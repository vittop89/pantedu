# Audit Architetturale — Teacher/Admin Flows (Pantedu)

> Data: 2026-05-09 · Scope: esercizi, verifiche, mappe, risdoc · Read-only review

## 1. Centralizzazione (DRY)

### Punti di forza
- **Helper centralizzati creati recentemente (G22.S15.bis Fase 5+)**: `AuthHelpers::teacherUsernameOrThrow()` e `TeacherContextResolver` consolidano pattern duplicati di resolve (username → id, first institute). I controller moderni delegano a questi invece di replicare la logica.
- **VerificaSharedHelpersTrait**: estrae helper comuni (`teacherId()`, `readJsonBody()`, `statusFor()`) per evitare duplicazione fra VerificaController, VerificaBatchController, VerificaCompileController.
- **Response shape centralizzato**: i controller utilizzano `Response::json()` con shape uniforme `{ok, error, data}` almeno per i 4 flussi principali.

### Debiti / Problemi
- **[PROBLEM-1] TeacherContextResolver::userIdFromUsername() vs VerificaSharedHelpersTrait::teacherId()**: entrambi risolvono username → id via prepared statement identico, ma in due posti. La trait usa inline, il resolver è statico. `app/Controllers/VerificaSharedHelpersTrait.php:29-37` vs `app/Support/TeacherContextResolver.php:26-32`.
- **[PROBLEM-2] TeacherContentController (1316 righe), ContentStudyController (1179 righe), TemplateViewController (1108 righe)**: file giganti con logica mista (query, filtering, rendering). Multipli scenari (search+filter+ACL inline) invece di delegare a servizi.
- **[PROBLEM-3] Scope handling incohesivo per verifiche**: Admin usa `VerificaFilesAdminController::guardScope()` per validate `_default` vs institute. Teacher usa `TeacherVerificaFilesController::resolveInstituteCode()` con query separata. Non esiste un servizio condiviso `ScopeResolver`.
- **[PROBLEM-4] Repository vs Service mixing**: `TeacherContentRepository` contiene sia accesso DB che crittografia (`crypto()` lazily initialized), violando SRP. La responsabilità di decrypt dovrebbe stare in un CryptoRepository o Service separato.

### Raccomandazioni
1. Estrarre `userIdFromUsername()` a statico `TeacherContextResolver` e usarlo da VerificaSharedHelpersTrait.
2. Creare `ScopeResolver` per centralizzare logic di validazione scope (`_default`, institute code, `t_{id}`).
3. Split TeacherContentController in min 3 service: TeacherContentCreateService, TeacherContentSearchService, TeacherContentAclService.
4. Estrarre crypto decrypt in `CryptoRepository` (read-only) vs EncryptedBlobStore (write).

---

## 2. Sicurezza

### Punti di forza
- **Ownership check centralizzato per verifiche**: tutti gli accessi via `VerificaDocumentService::requireOwn()` che valida `teacher_id === auth_id`. Coerente su 10+ endpoint mutator (`app/Services/Verifica/VerificaDocumentService.php:1059-1068`).
- **CSRF middleware applicato su tutti POST/PUT**: rotte gated con `['middleware' => ['csrf', 'rate']]` (`routes/web.php:315-342`). Zero endpoint mutator esposto senza CSRF.
- **Mappa permission service granulare**: `MapPermissionService` implement 3 livelli (canEdit=owner only, canView=owner|public|shared|published, canCopy=explicit). Coerente (`app/Services/Maps/MapPermissionService.php:40-90`).
- **File upload validation**: MapsController ha ALLOWED_MIME whitelist (finfo magic bytes), dimensione cap (50MB), estensione fallback (`app/Controllers/MapsController.php:52-64, 465-490`).
- **Cross-teacher query filtering consistente**: tutti i `search()` repository includono `teacher_id = ?` filter.

### Debiti / Problemi
- **[PROBLEM-5] super_admin claim non re-fetch da session**: `AclPolicy::isSuperAdmin()` interroga il DB ogni volta, ma `Auth::user()` ritorna username/role da session senza live refresh. Se l'admin flag è togglato, l'utente deve logout/login.
- **[PROBLEM-6] Risdoc Permission::isSuperAdmin() + canManageAdmin() doppi**: due metodi quasi identici che chiamano `AclPolicy::isSuperAdmin()`. `app/Services/Risdoc/Permission.php:35-38` e 190+.
- **[PROBLEM-7] Path traversal risk in TemplateFileStore**: i path sono hardcoded (ALLOWED_PATHS), ma `TemplateFileStore::write($scope, $path, ...)` non valida che `$path` non contenga `..`. Se un futuro refactor permette input dinamico, sarà vulnerabile.
- **[PROBLEM-8] File upload MIME fallback weak**: se `finfo` non è disponibile, il fallback su estensione client (`resolveMime()`) si fida del client. Anche con whitelist, un file `.exe` rinominato `.pdf` potrebbe bypassare.
- **[PROBLEM-9] PDF upload size check non enforce magic bytes**: `VerificaController::uploadPdf()` valida size (30MB cap) e presence, ma non verifica magic bytes `%PDF`.

### Raccomandazioni
1. Re-fetch super_admin flag da DB con cache TTL breve (5 min) per riflettere toggle immediato.
2. Consolidate `isSuperAdmin()` e `canManageAdmin()` — una sola source of truth in AclPolicy.
3. Validare `$path` in TemplateFileStore con regex `^[a-z/_-]+\.tex$` (allowlist chars).
4. Fallback `finfo` → magic byte manuale per PDFs (`%PDF`), XML (`<?xml`), drawio (`<mxfile`).
5. Per PDF upload, richiedere magic bytes `%PDF` prima di accettare.

---

## 3. Coerenza architetturale

### Punti di forza
- **Route naming uniforme per i 4 flussi**: tutti usano `/api/{entity}/{id}/{action}` pattern. Retro-compat con legacy `/eser/`, `/mappe/` via `LegacyGoneMiddleware` 302 redirect.
- **Exception mapping coerente**: `VerificaSharedHelpersTrait::statusFor()` centralizza throw → HTTP status (verifica_forbidden → 403, verifica_not_found → 404).
- **DTO usage su verifica**: `Selection::fromArray()` per deserializzare JSON Selection payload.

### Debiti / Problemi
- **[PROBLEM-10] Response shape incoerente fra Admin vs Teacher**: Teacher usa `{ok, error, data}`. Admin a volte omette `ok` su errori (es. `RisdocAdminController::page()` ritorna `403` senza JSON body, ma `listFiles()` ritorna `{ok:false, error}`).
- **[PROBLEM-11] Model/Entity inconsistency**: Verifiche usano array associativo con `@phpstan-type Doc`. Mappe usano mix array + PDO row. Esercizi usano contract JSON. Risdoc usa raw array + JSON. Nessuno usa Entity class.
- **[PROBLEM-12] Naming controller divergente**: `VerificaController` vs `VerificaCompileController` vs `VerificaBatchController` vs `VerificaSyncController` vs `TeacherVerificaFilesController`. Mappe: solo `MapsController`. Risdoc: 5 controller sotto `/Risdoc/`.
- **[PROBLEM-13] Error handling leak**: Alcuni service throwano RuntimeException con dominio msg, ma `statusFor()` non copre tutti i casi. Default match → 400. Non esaustivo (`app/Controllers/VerificaSharedHelpersTrait.php:72-83`).

### Raccomandazioni
1. Standardizzare response shape: tutti gli Admin endpoint `{ok, data|error}` o helper centralizzato.
2. Creare Entity classes (VerificaDocument, MapContent, RisdocTemplate) per typecheck.
3. Naming: consolidare `{Entity}Controller` per flusso principale + `{Entity}{Action}Controller` solo se split necessario (>500 righe).
4. Exhaustive error mapping: ControllerErrorMapper service o enum.

---

## 4. Qualità del codice

### Punti di forza
- **Test coverage per servizi critici**: VerificaDocumentService, Crypto service testati.
- **Comment debt minimo**: Note di fase storiche ma informative. No TODO sparsi.
- **Database transaction safety**: Verifica save usa `PdoTransactionRunner`. Mappa create/update usano transaction explicit.

### Debiti / Problemi
- **[PROBLEM-14] File giganti senza test E2E**: TeacherContentController (1316 LOC) ha zero test. ContentStudyController (1179 LOC) ha zero test. Gatekeeper di 4 entità senza E2E.
- **[PROBLEM-15] Magic number spread**: Page size defaults (50, 500, 2500) hardcoded. MAX_BYTES per mappe (50MB) vs verifica TEX (4MB) vs PDF (30MB) — no constant enum.
- **[PROBLEM-16] Dead code risk post refactor**: `ExerciseController::searchJson()` legacy che nessuno chiama da UI moderna. Candidato rimozione.
- **[PROBLEM-17] Mixed paradigm Risdoc**: Permission ha 10+ static DB query method (one per permission type). MapPermissionService ha 3 metodi centrali. Risdoc Permission non cached.

### Raccomandazioni
1. E2E test per TeacherContentController e ContentStudyController.
2. Centralizzare Page/Buffer size constants in `App\Domain\Limits` enum.
3. Mark `ExerciseController::searchJson()` deprecated, removal Phase 26.
4. Risdoc Permission: add per-request memoization cache.

---

## 5. Teacher vs Admin Differenze

### Punti di forza
- **Admin privilege check centralizzato**: `Permission::canManageAdmin()` per risdoc, `AclPolicy::isSuperAdmin()` per metrics.
- **Teacher ACL delegation**: `/api/teacher/*` namespace isolato. Admin: `/api/admin/*` e `/admin/*`.
- **Audit differentiation**: Admin VerificaFilesAdminController → `institute_code` scope. Teacher → `t_{id}` scope. Logica coerente: admin touches defaults, teacher personalizza.

### Debiti / Problemi
- **[PROBLEM-18] RisdocAdminController + Risdoc/TemplateController overlap**: RisdocAdminController endpoints (`/admin/risdoc/*` e `/api/admin/risdoc/*`) gestiscono admin-level. Ma Risdoc/TemplateController gestisce `/api/teacher/risdoc/templates/*`. Nessun clear contract.
- **[PROBLEM-19] Admin TemplateFile vs Teacher VerificaFile access**: Admin `VerificaFilesAdminController` per `/api/admin/verifica/files`, Teacher `TeacherVerificaFilesController` per `/api/teacher/verifica/files`. Due controller paralleli sullo stesso storage (TemplateFileStore), no FileAccessService unificato.
- **[PROBLEM-20] Super-admin override non loggato**: RisdocAdminController::templateDetail() ritorna visibility list senza audit log. ADR-008 cita audit_reason ma non implementato.

### Raccomandazioni
1. Unify Risdoc{Admin}Controller + Risdoc/TemplateController sotto singolo TemplateController con role-based dispatch.
2. Creare `FileAccessService` che arbitra VerificaFilesAdminController + TeacherVerificaFilesController.
3. Implementare PrivilegedAccessLogger::log() per super-admin access su dati sensibili (ADR-008).

---

## TOP-10 Action Items (priorità)

| # | Livello | Titolo | File | Raccomandazione |
|---|---------|--------|------|-----------------|
| 1 | HIGH | Deduplicazione userIdFromUsername | VerificaSharedHelpersTrait.php:29-37 + TeacherContextResolver.php:26-32 | Solo path via TeacherContextResolver::userIdFromUsername() |
| 2 | HIGH | PDF magic byte validation | VerificaController.php (uploadPdf) | Check `%PDF` magic bytes prima di accettare |
| 3 | HIGH | Super-admin flag stale in session | AclPolicy.php:35-39 | Cache TTL (5min) su DB re-fetch |
| 4 | MED | TemplateFileStore path traversal risk | TeacherVerificaFilesController.php + VerificaFilesAdminController | Validare path con regex allowlist `^[a-z/_-]+\.tex$` |
| 5 | MED | File giganti TeacherContentController (1316 LOC) | TeacherContentController.php | Split in 3 service: Create + Search + Acl |
| 6 | MED | Zero E2E test per 4 entità | tests/ | Aggiungere E2E flow test |
| 7 | MED | Risdoc Permission DB hit per ogni check | Permission.php:71+ | Per-request memoization cache |
| 8 | LOW | Admin response shape inconsistent | RisdocAdminController.php vs VerificaFilesAdminController | Standardizzare `{ok, data|error}` |
| 9 | LOW | Dead code ExerciseController::searchJson() | ExerciseController.php | Mark deprecated, removal Phase 26 |
| 10 | LOW | Risdoc Admin/Teacher controller overlap | RisdocAdminController + Risdoc/TemplateController | Unify under single TemplateController + role dispatch |

---

## Conclusione

Il codebase mostra **architettura matura su assi critici** (crittografia envelope, ownership check, CSRF middleware). Vi sono **debiti accumulati da refactor incrementali** (G19–G22 span = 4 anni di fasi):

1. **Centralizzazione**: helper recenti (G22.S15.bis) stanno risolvendo la duplicazione; mancano Entity class.
2. **Sicurezza**: ownership check robusto; rischio su **validazione file upload** (PDF magic bytes, finfo fallback) e **super-admin staleness**.
3. **Coerenza**: route pattern uniforme, response shape e model representation eterogenei.
4. **Test**: coverage focus su service layer, assente su controller E2E.
5. **Teacher vs Admin**: separation coerente, overlap su Risdoc e TemplateFiles → unification.

**Azione più critica**: Deduplicate userIdFromUsername + PDF magic byte validation (PROBLEM-1, PROBLEM-9).
