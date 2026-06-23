# DB Legacy Tables (G22.S15.bis Fase 5+)

Documentazione delle tabelle DB legacy ancora presenti dopo il primo
refactor parziale. Audit eseguito 2026-05-09.

## Tabelle DROP-able dopo cleanup migration 037

| Tabella | Status |
|---|---|
| `template_cache` | ✅ DROPPED — già zero refs |
| `teacher_recovery_audit` | ✅ DROPPED — feature scaffold mai usata |

## Tabelle LEGACY ancora ATTIVE (non droppate)

### `exercises` (57 rows)

**Stato**: tabella ImmutABLE legacy, ancora referenziata da:
- `app/Controllers/ExerciseController` (search legacy)
- `app/Repositories/ExerciseRepository`
- `app/Services/Verifica/VerificaDocumentService` (eserciziSnapshot)

**Cosa contiene**: esercizi pre-Phase 18 importati dal CMS legacy.
Ogni nuovo esercizio docente va in `teacher_content` (content_type='esercizio').

**Per droppare** (work ~6-8h):
1. Audit: quali endpoint leggono `exercises`?
2. Check se i 57 esercizi sono ancora visualizzati nel sito (rendering frontend)
3. Migration data: `INSERT INTO teacher_content SELECT ... FROM exercises`
4. Update repository: read fallback teacher_content, niente exercises
5. Drop `exercises`

### `verifica_documents` (21 rows VPS, 8 locale)

**Stato**: tabella legacy Phase G8, dual-write disabilitato su VPS
(`DB_DUAL_WRITE=0`). Nuove verifiche vanno SOLO in `teacher_content`.

**Cosa contiene**: verifiche storiche pre-Phase 18 + relativi blob path.

**Referenze attive**:
- `VerificaDocumentService` (read fallback)
- `VerificaController::list/show`
- Storage objects `verifiche_enc/` ancora pointed via questo

**Per droppare**:
1. Verificare che tutti gli endpoint leggono da `teacher_content`
2. Migrate residual data se necessario
3. Drop tabella + cleanup `verifiche_enc/` orfani

### `teacher_exercises` (0 rows)

**Stato**: tabella vuota MA codice live in:
- `TeacherController::addExercise` (linea 147)
- `VerificaBuilderController` (M11 build flow)

**Perché vuota** ma codice vivo: gli endpoint M11 sono deprecated, frontend
moderno usa `/api/teacher/content` invece. Nessun nuovo INSERT da mesi.

**Per droppare**: rimuovere prima endpoint M11 (`/api/verifiche/build`,
`/api/verifiche/{id}/*`) + JS `verifica-builder.js`. Plus rotte dichiarative
in `routes/web.php`. Solo dopo drop tabella.

### `teacher_verifiche` (0 rows)

Stessa storia di `teacher_exercises`: vuota MA referenced da M11 controllers.

## Cosa è stato già fatto (Phase 18+)

- Schema unificato: `teacher_content` con `content_type ENUM('mappa','esercizio','verifica','risdoc','bes','lab')`
- Storage object via `StorageProvider`/`StorageFactory` astratto
- Contract pattern: `ContractRepository::load($contentId)` → ContractAggregate
- Sync services (Maps + Verifiche) con interface comune

## Cosa serve per finire la migration completa

Lavoro stimato **12-16h**, distribuito su **2-3 release**:

**Release N (preparazione)**:
- Audit completo flow M11 (verifica-builder.js, exercise-wizard.js)
- Documentare quali endpoint legacy sono ancora chiamati dal frontend
- Add deprecation warnings nei controller M11 (error_log "deprecated path")

**Release N+1 (migration)**:
- Migration data: copy residual `verifica_documents` → `teacher_content`
- Migration data: copy `exercises` → `teacher_content` (con ID mapping)
- Update repositories: read da teacher_content con fallback
- Frontend: rimuovi chiamate M11

**Release N+2 (drop)**:
- Verifica zero traffico endpoint M11 in produzione (log analysis 1 settimana)
- Drop tabelle legacy via migration
- Cleanup codice deprecated

## Backup pre-cleanup

- locale: `storage/_backups/db_pre_unification_20260509_*.sql` (4.4MB)
- VPS:    `/root/db_backups/db_pre_unification_20260509_*.sql` (3.9MB)

## Comandi audit utili

```sql
-- Conta righe per tipo
SELECT 'exercises' tbl, COUNT(*) FROM exercises
UNION ALL
SELECT 'verifica_documents', COUNT(*) FROM verifica_documents
UNION ALL
SELECT 'teacher_content', COUNT(*) FROM teacher_content
UNION ALL
SELECT 'teacher_content esercizio', COUNT(*) FROM teacher_content WHERE content_type='esercizio'
UNION ALL
SELECT 'teacher_content verifica', COUNT(*) FROM teacher_content WHERE content_type='verifica';
```

```bash
# Trova reference a una tabella legacy
grep -rn "verifica_documents" app/ --include="*.php" | grep -v "// "
```
