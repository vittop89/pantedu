# ADR-029 — Decomposizione dei God-controller contenuti

- **Stato:** COMPLETATO (write-side) — 7 controller + 1 service estratti e **in produzione**. `TeacherContentController` 1886→484 LOC (**-74%**), `ContentStudyController` 1883→1382 LOC (-27%, core contenuti coeso lasciato lì per scelta).
- **Data:** 2026-06-11
- **Correlati:** Opzione A collasso `content_type` (data-model già fatto, mig 078/079 in prod, `docs/plans/option-a-content-type-collapse.md`), ADR-028 (capabilities docente), `docs/ROUTES.md`, `docs/glossary/`.

## Contesto

Due controller dei contenuti sono God-object e abbassano la navigabilità (audit/onboarding lenti):

- `app/Controllers/TeacherContentController.php` — **1886 LOC**, 30 metodi pubblici (write/manage lato docente).
- `app/Controllers/ContentStudyController.php` — **1883 LOC**, 16 metodi pubblici (read-side studio).

**Non** sono duplicati tra loro (concern opposti: write vs read), ma ciascuno mescola responsabilità eterogenee (CRUD, publish, export/compile TeX, editing quesiti/gruppi, template; e lato studio: pagine, header, fonti, verifiche). Il collasso del modello dati (`content_type` 8→4 + `content_format`) è **già stato fatto** (Opzione A, mig 078/079) — questo ADR riguarda i **controller**, non lo schema.

## Decisione

1. **Findability subito** (fatto): pubblicare la **method-map** di entrambi i controller in `docs/glossary/` (metodo → riga → cosa fa → modulo target). Rende i file navigabili senza toccare il codice.
2. **Decomposizione incrementale** (backlog): estrarre per seam coesi, una classe per area, riusando `TeacherContentRepository` e i Service esistenti. Seam proposti:

   `TeacherContentController` →
   - `TeacherContentController` (CRUD core: index/store/show/update/destroy/recategorize/capabilities/myClasses)
   - `ContentPublishController` (publish/unpublish/sharePool)
   - `ContentExportController` (export/texFiles/compilePdf/saveTexFiles/exportHtml/provenance/contract/manifest)
   - `QuesitoController` (quesito*)
   - `GroupController` (group*)
   - `ContentTemplateController` (templates*, default*ForType)

   `ContentStudyController` →
   - `StudyContentController` (topics/content JSON+pagine)
   - `StudyHeaderController` (headerPage*)
   - `StudySourcesController` (sources*/origins*/checkedOrigins*)
   - `StudyVerificaController` (relatedVerificaHtml)

3. **Vincoli dello split** (quando si farà): le route in `routes/web.php` puntano a `Classe::metodo` → ogni estrazione richiede l'aggiornamento puntuale della route corrispondente. **Mai split alla cieca**: ogni modulo estratto va verificato con i test E2E (`tests/e2e/`) prima del merge. Procedere un'area alla volta (un modulo = un commit verificabile), non big-bang.

## Conseguenze

- **+** Audit security più rapido (rendering/permission localizzati per area), onboarding più semplice, diff più piccoli.
- **−** Più file/classi; le route vanno ritoccate a ogni estrazione.
- **Rischio** mitigato dall'incrementalità + E2E: nessuna regressione se ogni area è estratta e testata separatamente.

## Stato implementazione

- **Method-map** (`docs/glossary/TeacherContentController.md`, `ContentStudyController.md`) — pubblicate.
- **`ContentExportController`** — ESTRATTO e **in produzione** (main). 8 metodi (export/texFiles/compilePdf/saveTexFiles/exportHtml/provenance/contract/manifest) + helper export-only `buildTexBundle`/`escTexShort` spostati; helper condivisi (`teacherId`/`dbReady`/`findOwnedRow`/`contentVisibilityPolicy`/`viewerContext`/`firstInstituteId`) duplicati nel nuovo controller; 8 route ripuntate. `TeacherContentController` 1886→1346 LOC, nuovo controller 629 LOC. Estrazione meccanica via Reflection (no retyping). **Verifiche superate**: `php -l` (3 file), Reflection, integration `TeacherTemplatesTest` 10/10, suite completa 784 test (15 failures **pre-esistenti**, nessuna nell'area estratta), e **completeness-check runtime** (ogni `$this->metodo()` risolve nella classe → ha scovato `firstInstituteId` mancante, transitivo da `viewerContext`, poi corretto). Smoke-test HTTP in prod consigliato post-deploy. **Procedura riusabile per i moduli successivi** (sotto).
- **`ContentPublishController`** (modulo 2) — in produzione. publish/unpublish/sharePool/setVisibility; 3 route ripuntate. Estratto col tool `tools/dev/extract_controller.php` (chiusura transitiva auto). 118 LOC.
- **`QuesitoController`** (modulo 3) — in produzione. quesitoPatch/Delete/Move/Duplicate/CloneToEser (+ quesitoOp/readQuesitoPatchBody); 5 route ripuntate. 255 LOC. Verifiche estese a `composer cs` (0) + `composer stan` (baseline ri-grandfathered) oltre a lint/Reflection/completeness/suite.
- **`StudyHeaderController`** (read-side) — in produzione. headerPage* (+loadHeaderPage/defaultHeaderPage) da `ContentStudyController`.
- **`StudySourcesController`** (read-side) — in produzione. sources*/origins*/checkedOrigins* da `ContentStudyController`. `ContentStudyController` 1917→1382 LOC; il core contenuti (topics*/content*/relatedVerifica) resta lì, coeso (ulteriore split sarebbe artificiale).

- **`App\Services\Risdoc\TemplateDefaults`** (service) — in produzione. Logica template-default (`itemsForType`/`introForType`/`titleForType`/`normalizeType`/`readRaw`/`loadTeacherTemplate`/`hardcodedItems`/`seedDefault`) estratta da `TeacherContentController`. Statico/stateless (unica dipendenza `TeacherContextResolver::firstInstituteId`). `TeacherTemplatesTest` riscritto per testare il service direttamente (10/10, niente più Reflection). **Sblocca l'estrazione di Group/Template** rompendo l'accoppiamento (vedi sotto).
- **`GroupController`** (modulo 4) — in produzione. group* da `TeacherContentController`; ora pulito perché `groupAdd` usa `TemplateDefaults::` invece di helper privati condivisi.
- **`ContentTemplateController`** (modulo 5) — in produzione. templatesJson/templatesSave da `TeacherContentController`.

### Risolto: accoppiamento group↔template (era il blocco di design)

Primo tentativo (estrazione meccanica diretta) **scartato**: i metodi template-default sono **condivisi** tra `groupAdd` e il template-CRUD, e **testati come unità** da `TeacherTemplatesTest` (via Reflection su `TeacherContentController`). L'estrazione li trascinava nel primo controller estratto, scatterando logica testata e rompendo il test (`ReflectionException: hardcodedDefaultItems() does not exist`).

**Soluzione applicata**: estratto prima un **`TemplateDefaults` service** (statico, testato direttamente), poi `groupAdd`/`templatesJson` ripuntati al service → l'accoppiamento è sparito e `GroupController`/`ContentTemplateController` sono stati estratti puliti (la chiusura transitiva del tool non trascina più nulla). Lezione confermata: split meccanico + service-extraction quando la logica è condivisa e testata.

- **Flusso riusabile** (collaudato sui 5 moduli): `extract_controller.php` (chiusura transitiva auto) → ripunta route → `gen_routes_md` → lint + Reflection + `check_controller_complete` **su nuovo E sorgente** + `composer cs` + `composer stan`(+`stan:baseline`) + suite → branch → FF-merge. **Lezione**: il completeness-check copre i `$this->` interni ma NON i chiamanti esterni (test via Reflection) — verificare sempre la suite prima del merge.

## Procedura riusabile per estrazione (collaudata sul modulo 1)

1. **Mappa accoppiamento**: per i metodi da spostare, `grep '$this->'` → elenco helper privati chiamati; per ciascuno, verifica se è usato SOLO dall'area (→ *move*) o anche altrove (→ *copy* nel nuovo controller).
2. **Estrazione meccanica via Reflection** (no retyping): `ReflectionMethod::getStartLine()/getEndLine()` per slice esatti (incl. docblock contiguo). Build nuovo controller = header+costruttore + metodi move + helper copy; riscrivi l'originale rimuovendo SOLO i metodi move.
3. **Ripunta le route** in `routes/web.php` (`Vecchio::class, 'm'` → `Nuovo::class, 'm'`), rigenera `docs/ROUTES.md`.
4. **Verifiche obbligatorie**: `php -l` (3 file) · Reflection (metodi nel posto giusto) · **completeness-check runtime** (ogni `$this->metodo()` del nuovo controller risolve a un metodo definito lì — cattura le dipendenze transitive mancanti, che lint/reflection NON vedono) · suite PHPUnit (confronto failures pre/post) · smoke-test HTTP post-deploy.
5. **Un modulo = un branch/commit**; FF-merge in main solo a verifiche verdi.
